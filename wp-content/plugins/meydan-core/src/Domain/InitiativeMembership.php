<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\EventLogger;
use Meydan\Core\Support\Serializer;

/**
 * Single path for a signed-in user joining or leaving a work (initiative).
 * Used by both the feed's join button and the «کارها» room, so the
 * initiative membership row and the work-group membership never diverge.
 */
final class InitiativeMembership
{
    /** @return array{joined:bool,participant_count:int,changed:bool} */
    public static function setForUser(int $initiativeId, int $userId, bool $on): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_initiative_members';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE initiative_id=%d AND user_id=%d LIMIT 1",
            $initiativeId,
            $userId
        ));
        $changed = false;

        if ($on) {
            $wasActive = $status === 'active';
            if ($status === null) {
                $wpdb->insert($table, [
                    'initiative_id' => $initiativeId,
                    'member_type' => 'user',
                    'user_id' => $userId,
                    'guest_id' => null,
                    'joined_at' => current_time('mysql', true),
                    'status' => 'active',
                ]);
            } elseif (!$wasActive) {
                $wpdb->update($table, ['status' => 'active', 'joined_at' => current_time('mysql', true)], ['initiative_id' => $initiativeId, 'user_id' => $userId]);
            }
            EventLogger::log('initiative_join', 'initiative', $initiativeId);

            // Always reconcile the group (idempotent) — covers members that predate it.
            WorkGroups::syncMember($initiativeId, $userId, true);

            if (!$wasActive) {
                $changed = true;
                self::notifyJoined($initiativeId, $userId);
            }
        } elseif ($status === 'active') {
            $wpdb->update($table, ['status' => 'left'], ['initiative_id' => $initiativeId, 'user_id' => $userId]);
            WorkGroups::syncMember($initiativeId, $userId, false);
            $changed = true;
        }

        $initiative = Serializer::initiative($initiativeId);
        return ['joined' => $on, 'participant_count' => (int) ($initiative['participant_count'] ?? 0), 'changed' => $changed];
    }

    private static function notifyJoined(int $initiativeId, int $userId): void
    {
        $owner = (int) get_post_field('post_author', $initiativeId);
        $workId = WorkGroups::conversationIdForInitiative($initiativeId);
        $title = (string) (get_the_title($initiativeId) ?: 'کار');
        $actor = Actor::forUser($userId);
        $actorId = (int) ($actor['numeric_id'] ?? 0);
        if ($actorId <= 0) {
            $raw = (string) ($actor['id'] ?? ('user_' . $userId));
            $pos = strrpos($raw, '_');
            $actorId = $pos === false ? $userId : (int) substr($raw, $pos + 1);
        }
        $actorType = (string) ($actor['type'] ?? 'user');
        $name = (string) ($actor['display_name'] ?? 'یک کاربر');

        $srcNarrative = (int) get_post_meta($initiativeId, 'meydan_source_narrative_id', true);
        $link = $workId > 0 ? '/works/' . $workId : ($srcNarrative > 0 ? '/posts/' . $srcNarrative : '/home');

        if ($owner > 0 && $owner !== $userId) {
            (new NotificationService())->create(
                $owner,
                'initiative_join',
                $actorType,
                $actorId,
                'initiative',
                $initiativeId,
                'عضو جدید در کار',
                $name . ' به «' . $title . '» پیوست.',
                $link,
                'initiative_join:' . $initiativeId,
                ['initiative_title' => $title],
            );
        }

        // Appointed admins hear about it too — one bulk insert, not a loop.
        if ($workId > 0) {
            $conv = WorkQueries::conversation($workId);
            if ($conv) {
                $admins = array_diff(WorkGroups::managerIds($workId), [$userId, $owner]);
                WorkMessages::notify($conv, $userId, 'work_member_joined', $admins, 0, '', [], 'work:joined:' . $workId . ':' . $userId);
            }
        }
    }
}
