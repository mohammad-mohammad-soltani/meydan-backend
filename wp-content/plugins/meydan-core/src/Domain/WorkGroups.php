<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

/**
 * Work groups ("کارها"): one chat conversation of type 'work' per
 * `meydan_initiative`. Membership mirrors initiative membership; the
 * initiative author is the owner (مدیر) and may promote members to admin.
 *
 * Work conversations are invisible to the regular chat API — ChatRepository
 * excludes type='work' — and are only reachable through /works routes.
 */
final class WorkGroups
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    private const BACKFILL_OPTION = 'meydan_work_groups_backfill_v1';
    private const BACKFILL_RANGE = 5000;

    public static function register(): void
    {
        add_action('save_post_meydan_initiative', [self::class, 'onInitiativeSaved'], 20, 2);
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meydan_chat_' . $name;
    }

    public static function onInitiativeSaved(int $postId, \WP_Post $post): void
    {
        if ($post->post_status !== 'publish' || wp_is_post_revision($postId)) {
            return;
        }
        self::ensureForInitiative($postId);
    }

    /** Conversation id for an initiative, 0 if there is none yet. Served from the postmeta cache. */
    public static function conversationIdForInitiative(int $initiativeId): int
    {
        return (int) get_post_meta($initiativeId, 'meydan_work_id', true);
    }

    /** Idempotent: creates the work conversation and its owner membership once. */
    public static function ensureForInitiative(int $initiativeId): int
    {
        $existing = self::conversationIdForInitiative($initiativeId);
        if ($existing > 0) {
            return $existing;
        }

        $post = get_post($initiativeId);
        if (!$post || $post->post_type !== 'meydan_initiative' || $post->post_status !== 'publish') {
            return 0;
        }

        global $wpdb;
        $conversations = self::table('conversations');
        $now = current_time('mysql', true);
        $ownerId = max(1, (int) $post->post_author);

        $wpdb->insert($conversations, [
            'type' => 'work',
            'title' => mb_substr(wp_strip_all_tags((string) $post->post_title), 0, 190),
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
            'initiative_id' => $initiativeId,
        ]);
        $conversationId = (int) $wpdb->insert_id;
        if ($conversationId <= 0) {
            // Lost a race against the unique initiative_id key.
            $conversationId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$conversations} WHERE initiative_id=%d LIMIT 1",
                $initiativeId
            ));
            if ($conversationId <= 0) {
                return 0;
            }
        }

        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table('participants') . ' (conversation_id,user_id,joined_at,role) VALUES (%d,%d,%s,%s)',
            $conversationId,
            $ownerId,
            $now,
            self::ROLE_OWNER
        ));
        update_post_meta($initiativeId, 'meydan_work_id', $conversationId);

        return $conversationId;
    }

    /** The viewer's role in the work, or null when they are not a member. */
    public static function role(int $conversationId, int $userId): ?string
    {
        if ($conversationId <= 0 || $userId <= 0) {
            return null;
        }
        global $wpdb;
        $role = $wpdb->get_var($wpdb->prepare(
            'SELECT p.role FROM ' . self::table('participants') . ' p INNER JOIN ' . self::table('conversations')
            . " c ON c.id=p.conversation_id WHERE p.conversation_id=%d AND p.user_id=%d AND p.archived_at IS NULL AND c.type='work' LIMIT 1",
            $conversationId,
            $userId
        ));
        return $role === null ? null : (string) $role;
    }

    public static function isSiteAdmin(?int $userId = null): bool
    {
        $user = $userId === null ? wp_get_current_user() : get_userdata($userId);
        return $user instanceof \WP_User && in_array('administrator', (array) $user->roles, true);
    }

    public static function isManagerRole(?string $role): bool
    {
        return $role === self::ROLE_OWNER || $role === self::ROLE_ADMIN;
    }

    /** Owner, appointed admin, or a site administrator. */
    public static function canManage(?string $role, int $userId): bool
    {
        return self::isManagerRole($role) || self::isSiteAdmin($userId);
    }

    /** Editing group info and appointing admins: owner or site administrator. */
    public static function canAdminister(?string $role, int $userId): bool
    {
        return $role === self::ROLE_OWNER || self::isSiteAdmin($userId);
    }

    /** @return int[] owner + admin user ids */
    public static function managerIds(int $conversationId): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . self::table('participants') . " WHERE conversation_id=%d AND role IN ('owner','admin') AND archived_at IS NULL",
            $conversationId
        )) ?: []);
    }

    /**
     * Keep the group in step with initiative membership.
     *
     * @return bool true when membership actually changed
     */
    public static function syncMember(int $initiativeId, int $userId, bool $joined): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $conversationId = self::ensureForInitiative($initiativeId);
        if ($conversationId <= 0) {
            return false;
        }

        global $wpdb;
        $participants = self::table('participants');

        if ($joined) {
            // 1 = inserted, 2 = re-activated, 0 = already an active member.
            $affected = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$participants} (conversation_id,user_id,joined_at,role) VALUES (%d,%d,%s,%s) ON DUPLICATE KEY UPDATE archived_at=NULL",
                $conversationId,
                $userId,
                current_time('mysql', true),
                self::ROLE_MEMBER
            ));
            if ((int) $affected <= 0) {
                return false;
            }
            WorkMessages::system($conversationId, $userId, 'joined');
            return true;
        }

        // The owner stays in their own group even if they leave the initiative.
        $removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$participants} WHERE conversation_id=%d AND user_id=%d AND role<>'owner'",
            $conversationId,
            $userId
        ));
        if ((int) $removed <= 0) {
            return false;
        }
        WorkMessages::system($conversationId, $userId, 'left');
        return true;
    }

    /**
     * One-time backfill: a group per existing initiative with its active user
     * members. Set-based SQL in initiative-id ranges, so cost is a handful of
     * statements per 5000 initiatives rather than a query per member.
     */
    public static function backfill(): void
    {
        if ((bool) get_option(self::BACKFILL_OPTION, false)) {
            return;
        }

        global $wpdb;
        $posts = $wpdb->posts;
        $conversations = self::table('conversations');
        $participants = self::table('participants');
        $members = $wpdb->prefix . 'meydan_initiative_members';

        $bounds = $wpdb->get_row(
            "SELECT MIN(ID) AS lo, MAX(ID) AS hi FROM {$posts} WHERE post_type='meydan_initiative' AND post_status='publish'",
            ARRAY_A
        );
        $lo = (int) ($bounds['lo'] ?? 0);
        $hi = (int) ($bounds['hi'] ?? 0);

        for ($from = $lo; $lo > 0 && $from <= $hi; $from += self::BACKFILL_RANGE) {
            $to = $from + self::BACKFILL_RANGE - 1;

            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$conversations} (type,title,created_by,created_at,updated_at,initiative_id)
                 SELECT 'work', LEFT(p.post_title,190), GREATEST(p.post_author,1), UTC_TIMESTAMP(), UTC_TIMESTAMP(), p.ID
                 FROM {$posts} p WHERE p.post_type='meydan_initiative' AND p.post_status='publish' AND p.ID BETWEEN %d AND %d",
                $from,
                $to
            ));

            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$participants} (conversation_id,user_id,joined_at,role)
                 SELECT c.id, c.created_by, UTC_TIMESTAMP(), 'owner'
                 FROM {$conversations} c WHERE c.type='work' AND c.initiative_id BETWEEN %d AND %d",
                $from,
                $to
            ));

            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$participants} (conversation_id,user_id,joined_at,role)
                 SELECT c.id, m.user_id, m.joined_at, 'member'
                 FROM {$members} m INNER JOIN {$conversations} c ON c.initiative_id=m.initiative_id AND c.type='work'
                 WHERE m.status='active' AND m.user_id IS NOT NULL AND m.initiative_id BETWEEN %d AND %d",
                $from,
                $to
            ));

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value)
                 SELECT c.initiative_id, 'meydan_work_id', c.id FROM {$conversations} c
                 WHERE c.type='work' AND c.initiative_id BETWEEN %d AND %d
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=c.initiative_id AND pm.meta_key='meydan_work_id')",
                $from,
                $to
            ));
        }

        update_option(self::BACKFILL_OPTION, 1, false);
    }
}
