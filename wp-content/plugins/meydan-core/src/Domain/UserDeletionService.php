<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Storage\AttachmentStorage;

use Meydan\Core\Integrations\Channels\Channels;
use Meydan\Core\Support\Stats;
use Meydan\Core\Uploads\ChunkedUploadService;
use WP_Error;

/**
 * Hard-deletes a disabled account and the Meydan data owned by that identity.
 *
 * Only explicitly user-owned/domain data is destroyed. Administrative posts
 * that merely happen to have this WordPress user as post_author are reassigned
 * to the administrator performing the deletion so a staff-account cleanup
 * cannot accidentally remove site-wide managed content.
 */
final class UserDeletionService
{
    /**
     * @return array{
     *   user_id:int,
     *   squares:int,
     *   narratives:int,
     *   contents:int,
     *   initiatives:int,
     *   comments:int,
     *   attachments:int,
     *   conversations:int
     * }|WP_Error
     */
    public static function deletePermanently(int $userId, int $reassignUserId): array|WP_Error
    {
        $user = $userId > 0 ? get_userdata($userId) : false;
        if (!$user) {
            return new WP_Error('not_found', 'کاربر پیدا نشد.', ['status' => 404]);
        }
        if (!UserAccess::disabled($userId)) {
            return new WP_Error(
                'user_must_be_disabled',
                'برای حذف دائمی، ابتدا حساب را غیرفعال کنید.',
                ['status' => 409],
            );
        }
        if ($userId === get_current_user_id()) {
            return new WP_Error(
                'validation_failed',
                'حساب فعلی را نمی‌توان حذف دائمی کرد.',
                ['status' => 422],
            );
        }

        $reassign = $reassignUserId > 0 ? get_userdata($reassignUserId) : false;
        if (!$reassign || $reassignUserId === $userId) {
            return new WP_Error(
                'validation_failed',
                'مدیر مقصد برای نگه‌داری محتوای مدیریتی معتبر نیست.',
                ['status' => 422],
            );
        }

        $phoneHash = (string) get_user_meta($userId, 'meydan_phone_hash', true);
        $squareIds = self::ownedSquareIds($userId);
        $narrativeIds = self::ids(array_merge(
            self::userNarrativeIds($userId),
            self::squareNarrativeIds($squareIds),
        ));
        $contentIds = self::derivedContentIds($userId, $squareIds, $narrativeIds);
        $initiativeIds = self::derivedInitiativeIds($narrativeIds);

        // Stop channel imports before anything disappears. A sync worker that
        // races this request will fail its binding check instead of recreating
        // content for an account being removed.
        Channels::store($userId, 'eitaa', '');
        Channels::store($userId, 'bale', '');

        foreach ($squareIds as $squareId) {
            $deleted = SquareDeletionService::deletePermanently($squareId, false);
            if (is_wp_error($deleted)) {
                return $deleted;
            }
        }

        foreach ($contentIds as $contentId) {
            self::deleteContent($contentId);
        }
        foreach ($initiativeIds as $initiativeId) {
            self::deleteInitiative($initiativeId);
        }

        // This uses the same canonical cleanup hooks as ordinary actor removal,
        // including import mappings, stats, reflections, notifications/events.
        NarrativeCleanup::deleteUserNarratives($userId);

        $comments = self::deleteComments($userId);
        $conversations = self::deleteChatRelations($userId);
        $attachments = self::deleteAttachments($userId);

        self::deleteUserRelations($userId, $phoneHash);

        // Remove both the DB rows and any unfinished chunk directories.
        (new ChunkedUploadService())->purgeForUser($userId);

        require_once ABSPATH . 'wp-admin/includes/user.php';

        // Remaining posts are administrative/site records rather than the
        // personal actor data removed above. Keep them by reassigning authorship
        // to the administrator executing the deletion.
        if (!wp_delete_user($userId, $reassignUserId)) {
            return new WP_Error(
                'internal_error',
                'حذف دائمی حساب کاربر انجام نشد.',
                ['status' => 500],
            );
        }

        return [
            'user_id' => $userId,
            'squares' => count($squareIds),
            'narratives' => count($narrativeIds),
            'contents' => count($contentIds),
            'initiatives' => count($initiativeIds),
            'comments' => $comments,
            'attachments' => $attachments,
            'conversations' => $conversations,
        ];
    }

    /** @return list<int> */
    private static function ownedSquareIds(int $userId): array
    {
        $ids = [];

        $linked = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($linked > 0 && get_post_type($linked) === 'meydan_square') {
            $ids[] = $linked;
        }

        $byAuthor = get_posts([
            'post_type' => 'meydan_square',
            'post_status' => 'any',
            'author' => $userId,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $ids = array_merge($ids, array_map('intval', $byAuthor ?: []));

        $byOwnerMeta = get_posts([
            'post_type' => 'meydan_square',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => 'meydan_owner_user_id',
            'meta_value' => $userId,
        ]);
        $ids = array_merge($ids, array_map('intval', $byOwnerMeta ?: []));

        return self::ids($ids);
    }

    /** @return list<int> */
    private static function userNarrativeIds(int $userId): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} actor_type
               ON actor_type.post_id=p.ID
              AND actor_type.meta_key='meydan_author_actor_type'
             LEFT JOIN {$wpdb->postmeta} actor_id
               ON actor_id.post_id=p.ID
              AND actor_id.meta_key='meydan_author_actor_id'
             WHERE p.post_type='meydan_narrative'
               AND (
                    (actor_type.meta_value='user' AND CAST(actor_id.meta_value AS UNSIGNED)=%d)
                    OR (actor_type.meta_id IS NULL AND p.post_author=%d)
               )",
            $userId,
            $userId,
        ));

        return self::ids($ids ?: []);
    }

    /** @param list<int> $squareIds @return list<int> */
    private static function squareNarrativeIds(array $squareIds): array
    {
        if (!$squareIds) {
            return [];
        }

        return self::ids(get_posts([
            'post_type' => 'meydan_narrative',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => 'meydan_author_actor_type', 'value' => 'square'],
                [
                    'key' => 'meydan_author_actor_id',
                    'value' => $squareIds,
                    'compare' => 'IN',
                    'type' => 'NUMERIC',
                ],
            ],
        ]) ?: []);
    }

    /** @param list<int> $squareIds @param list<int> $narrativeIds @return list<int> */
    private static function derivedContentIds(int $userId, array $squareIds, array $narrativeIds): array
    {
        $meta = [
            'relation' => 'OR',
            [
                'relation' => 'AND',
                ['key' => 'meydan_producer_actor_type', 'value' => 'user'],
                ['key' => 'meydan_producer_actor_id', 'value' => $userId, 'type' => 'NUMERIC'],
            ],
        ];
        if ($squareIds) {
            $meta[] = [
                'relation' => 'AND',
                ['key' => 'meydan_producer_actor_type', 'value' => 'square'],
                [
                    'key' => 'meydan_producer_actor_id',
                    'value' => $squareIds,
                    'compare' => 'IN',
                    'type' => 'NUMERIC',
                ],
            ];
        }
        if ($narrativeIds) {
            $meta[] = [
                'key' => 'meydan_source_narrative_id',
                'value' => $narrativeIds,
                'compare' => 'IN',
                'type' => 'NUMERIC',
            ];
        }

        return self::ids(get_posts([
            'post_type' => 'meydan_content',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => $meta,
        ]) ?: []);
    }

    /** @param list<int> $narrativeIds @return list<int> */
    private static function derivedInitiativeIds(array $narrativeIds): array
    {
        if (!$narrativeIds) {
            return [];
        }

        return self::ids(get_posts([
            'post_type' => 'meydan_initiative',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => 'meydan_source_narrative_id',
                'value' => $narrativeIds,
                'compare' => 'IN',
                'type' => 'NUMERIC',
            ]],
        ]) ?: []);
    }

    private static function deleteContent(int $contentId): void
    {
        if ($contentId <= 0 || get_post_type($contentId) !== 'meydan_content') {
            return;
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'meydan_content_stats', ['content_id' => $contentId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'meydan_content_creators', ['content_id' => $contentId], ['%d']);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions
             WHERE object_type='content' AND object_id=%d",
            $contentId,
        ));
        self::deleteEntityRelations('content', $contentId);

        wp_delete_post($contentId, true);
    }

    private static function deleteInitiative(int $initiativeId): void
    {
        if ($initiativeId <= 0 || get_post_type($initiativeId) !== 'meydan_initiative') {
            return;
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'meydan_initiative_members',
            ['initiative_id' => $initiativeId],
            ['%d'],
        );
        $wpdb->delete(
            $wpdb->prefix . 'meydan_speaker_requests',
            ['initiative_id' => $initiativeId],
            ['%d'],
        );
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions
             WHERE object_type='initiative' AND object_id=%d",
            $initiativeId,
        ));
        self::deleteEntityRelations('initiative', $initiativeId);

        $linkedNarratives = get_posts([
            'post_type' => 'meydan_narrative',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => 'meydan_initiative_id',
            'meta_value' => $initiativeId,
        ]);
        foreach ($linkedNarratives ?: [] as $narrativeId) {
            update_post_meta((int) $narrativeId, 'meydan_initiative_id', 0);
        }

        wp_delete_post($initiativeId, true);
    }

    private static function deleteEntityRelations(string $type, int $id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_notifications
             WHERE (entity_type=%s AND entity_id=%d)
                OR (parent_entity_type=%s AND parent_entity_id=%d)",
            $type,
            $id,
            $type,
            $id,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_events
             WHERE entity_type=%s AND entity_id=%d",
            $type,
            $id,
        ));
    }

    private static function deleteComments(int $userId): int
    {
        $comments = get_comments([
            'user_id' => $userId,
            'status' => 'all',
            'number' => 0,
            'orderby' => 'comment_ID',
            'order' => 'ASC',
        ]);

        $deleted = 0;
        foreach ($comments ?: [] as $comment) {
            $commentId = (int) $comment->comment_ID;
            $postId = (int) $comment->comment_post_ID;

            if (
                $comment->comment_type === 'meydan_comment'
                && (string) $comment->comment_approved === '1'
                && $postId > 0
            ) {
                Stats::incrementNarrative($postId, 'comments', -1);
            }

            self::deleteEntityRelations('comment', $commentId);
            if (wp_delete_comment($commentId, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private static function deleteAttachments(int $userId): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'author' => $userId,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $deleted = 0;
        foreach (self::ids($ids ?: []) as $attachmentId) {
            $poster = (string) get_post_meta($attachmentId, 'meydan_poster_path', true);
            if (!AttachmentStorage::isRemote($attachmentId) && $poster !== '' && is_file($poster)) {
                @unlink($poster);
            }
            if (wp_delete_attachment($attachmentId, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private static function deleteUserRelations(int $userId, string $phoneHash): void
    {
        global $wpdb;

        // Tables whose row belongs wholly to one user.
        foreach (['sessions', 'initiative_members', 'push_subscriptions'] as $suffix) {
            $wpdb->delete(
                $wpdb->prefix . 'meydan_' . $suffix,
                ['user_id' => $userId],
                ['%d'],
            );
        }

        foreach (['eitaa', 'bale'] as $source) {
            $wpdb->delete(
                $wpdb->prefix . 'meydan_' . $source . '_imports',
                ['user_id' => $userId],
                ['%d'],
            );
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions
             WHERE user_id=%d OR (object_type='user' AND object_id=%d)",
            $userId,
            $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_actor_affinity
             WHERE viewer_user_id=%d
                OR (target_actor_type='user' AND target_actor_id=%d)",
            $userId,
            $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_notifications
             WHERE recipient_user_id=%d
                OR (actor_type='user' AND actor_id=%d)
                OR (entity_type='user' AND entity_id=%d)
                OR (parent_entity_type='user' AND parent_entity_id=%d)",
            $userId,
            $userId,
            $userId,
            $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_speaker_requests
             WHERE creator_id=%d
                OR requester_user_id=%d
                OR inviter_user_id=%d
                OR speaker_user_id=%d",
            $userId,
            $userId,
            $userId,
            $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}meydan_speaker_requests
             SET assigned_manager=NULL
             WHERE assigned_manager=%d",
            $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_served_history
             WHERE viewer_type='user' AND viewer_id=%s",
            (string) $userId,
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_events
             WHERE (viewer_type='user' AND viewer_id=%s)
                OR (entity_type='user' AND entity_id=%d)",
            (string) $userId,
            $userId,
        ));
        $wpdb->delete(
            $wpdb->prefix . 'meydan_idempotency',
            ['owner_key' => 'user:' . $userId],
            ['%s'],
        );

        if ($phoneHash !== '') {
            $wpdb->delete(
                $wpdb->prefix . 'meydan_auth_challenges',
                ['phone_hash' => $phoneHash],
                ['%s'],
            );
        }

        // Clear migration-era speaker references as well. Leaving these stale
        // would not revive the user, but a later forced migration would still
        // carry dead post => user links.
        $speakerMap = (array) get_option('meydan_speaker_user_map', []);
        $filteredSpeakerMap = array_filter(
            $speakerMap,
            static fn(mixed $mappedUserId): bool => (int) $mappedUserId !== $userId,
        );
        if (count($filteredSpeakerMap) !== count($speakerMap)) {
            update_option('meydan_speaker_user_map', $filteredSpeakerMap, false);
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key IN ('meydan_speaker_user_id','meydan_creator_user_id')
               AND CAST(meta_value AS UNSIGNED)=%d",
            $userId,
        ));

        // Audit history is intentionally retained, but it must no longer point
        // at a user row that is about to disappear.
        $wpdb->update(
            $wpdb->prefix . 'meydan_audit_log',
            ['admin_id' => 0],
            ['admin_id' => $userId],
            ['%d'],
            ['%d'],
        );
    }

    /** Returns number of conversations fully removed. */
    private static function deleteChatRelations(int $userId): int
    {
        global $wpdb;

        $conversations = $wpdb->prefix . 'meydan_chat_conversations';
        $participants = $wpdb->prefix . 'meydan_chat_participants';
        $messages = $wpdb->prefix . 'meydan_chat_messages';
        $reactions = $wpdb->prefix . 'meydan_chat_reactions';

        $conversationIds = self::ids($wpdb->get_col($wpdb->prepare(
            "SELECT conversation_id FROM {$participants} WHERE user_id=%d
             UNION
             SELECT conversation_id FROM {$messages} WHERE sender_user_id=%d
             UNION
             SELECT id FROM {$conversations} WHERE created_by=%d",
            $userId,
            $userId,
            $userId,
        )) ?: []);

        // User reactions can exist on messages in conversations they did not
        // create, so remove them independently of participant cleanup.
        $wpdb->delete($reactions, ['user_id' => $userId], ['%d']);

        $removedConversations = 0;
        foreach ($conversationIds as $conversationId) {
            $type = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT type FROM {$conversations} WHERE id=%d",
                $conversationId,
            ));

            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$participants}
                 WHERE conversation_id=%d AND user_id<>%d",
                $conversationId,
                $userId,
            ));

            if ($type === 'direct' || $remaining <= 0) {
                self::deleteWholeConversation($conversationId);
                $removedConversations++;
                continue;
            }

            // Future group chats should survive account deletion. Remove only
            // this user's messages and repair reply/forward pointers.
            $messageIds = self::ids($wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$messages}
                 WHERE conversation_id=%d AND sender_user_id=%d",
                $conversationId,
                $userId,
            )) ?: []);

            if ($messageIds) {
                $in = implode(',', $messageIds);
                $wpdb->query(
                    "DELETE FROM {$reactions} WHERE message_id IN ({$in})"
                );
                $wpdb->query(
                    "UPDATE {$messages}
                     SET reply_to_id=NULL
                     WHERE conversation_id=" . (int) $conversationId . "
                       AND reply_to_id IN ({$in})"
                );
                $wpdb->query(
                    "UPDATE {$messages}
                     SET forwarded_from_message_id=NULL
                     WHERE conversation_id=" . (int) $conversationId . "
                       AND forwarded_from_message_id IN ({$in})"
                );
                $wpdb->query(
                    "DELETE FROM {$messages} WHERE id IN ({$in})"
                );
            }

            $wpdb->delete(
                $participants,
                ['conversation_id' => $conversationId, 'user_id' => $userId],
                ['%d', '%d'],
            );

            $last = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(id) FROM {$messages} WHERE conversation_id=%d",
                $conversationId,
            ));
            $createdBy = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT created_by FROM {$conversations} WHERE id=%d",
                $conversationId,
            ));
            $update = ['last_message_id' => $last > 0 ? $last : null];
            $format = ['%d'];
            if ($createdBy === $userId) {
                $replacement = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT MIN(user_id) FROM {$participants} WHERE conversation_id=%d",
                    $conversationId,
                ));
                $update['created_by'] = max(0, $replacement);
                $format[] = '%d';
            }
            // wpdb accepts null in the data array and writes SQL NULL.
            $wpdb->update(
                $conversations,
                $update,
                ['id' => $conversationId],
                $format,
                ['%d'],
            );
        }

        return $removedConversations;
    }

    private static function deleteWholeConversation(int $conversationId): void
    {
        global $wpdb;

        $messages = $wpdb->prefix . 'meydan_chat_messages';
        $reactions = $wpdb->prefix . 'meydan_chat_reactions';

        $wpdb->query($wpdb->prepare(
            "DELETE r FROM {$reactions} r
             INNER JOIN {$messages} m ON m.id=r.message_id
             WHERE m.conversation_id=%d",
            $conversationId,
        ));
        $wpdb->delete(
            $messages,
            ['conversation_id' => $conversationId],
            ['%d'],
        );
        $wpdb->delete(
            $wpdb->prefix . 'meydan_chat_participants',
            ['conversation_id' => $conversationId],
            ['%d'],
        );
        $wpdb->delete(
            $wpdb->prefix . 'meydan_chat_conversations',
            ['id' => $conversationId],
            ['%d'],
        );
    }

    /** @param array<int,int|string> $values @return list<int> */
    private static function ids(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
