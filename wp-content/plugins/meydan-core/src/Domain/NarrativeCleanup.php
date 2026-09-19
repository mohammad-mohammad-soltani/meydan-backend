<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use WP_Post;

/**
 * Keeps narrative ownership consistent when actors are permanently removed.
 *
 * Speakers are user-backed accounts, so user cleanup covers both ordinary
 * users and speakers. Squares use their square post id as the narrative actor.
 */
final class NarrativeCleanup
{
    private const ORPHAN_CLEANUP_OPTION = 'meydan_orphan_narratives_cleaned_v1';

    public static function register(): void
    {
        add_action('delete_user', [self::class, 'deleteUserNarratives'], 10, 1);
        add_action('before_delete_post', [self::class, 'deleteSquareNarratives'], 10, 2);
        add_action('init', [self::class, 'maybeDeleteOrphanNarratives'], 35);
    }

    /** Permanently deletes narratives owned by a user or speaker account. */
    public static function deleteUserNarratives(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} actor_type
               ON actor_type.post_id = p.ID
              AND actor_type.meta_key = 'meydan_author_actor_type'
             LEFT JOIN {$wpdb->postmeta} actor_id
               ON actor_id.post_id = p.ID
              AND actor_id.meta_key = 'meydan_author_actor_id'
             WHERE p.post_type = 'meydan_narrative'
               AND (
                    (actor_type.meta_value = 'user' AND CAST(actor_id.meta_value AS UNSIGNED) = %d)
                    OR (actor_type.meta_id IS NULL AND p.post_author = %d)
               )",
            $userId,
            $userId
        ));

        self::forceDelete($ids ?: []);
    }

    /** Permanently deletes narratives owned by a square before that square is deleted. */
    public static function deleteSquareNarratives(int $postId, WP_Post $post): void
    {
        if ($postId <= 0 || $post->post_type !== 'meydan_square') {
            return;
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} actor_type
               ON actor_type.post_id = p.ID
              AND actor_type.meta_key = 'meydan_author_actor_type'
              AND actor_type.meta_value = 'square'
             INNER JOIN {$wpdb->postmeta} actor_id
               ON actor_id.post_id = p.ID
              AND actor_id.meta_key = 'meydan_author_actor_id'
             WHERE p.post_type = 'meydan_narrative'
               AND CAST(actor_id.meta_value AS UNSIGNED) = %d",
            $postId
        ));

        self::forceDelete($ids ?: []);
    }

    /**
     * One-time cleanup for narratives whose owning actor no longer exists.
     *
     * For legacy user narratives without actor metadata, post_author is used as
     * the fallback. A trashed square still exists and is therefore not treated
     * as orphaned until it is permanently deleted.
     */
    public static function maybeDeleteOrphanNarratives(): void
    {
        if (get_option(self::ORPHAN_CLEANUP_OPTION, null) !== null) {
            return;
        }

        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'meydan_narrative'"
        );

        $deleted = 0;
        foreach ($ids ?: [] as $rawId) {
            $id = (int) $rawId;
            $post = get_post($id);
            if (!$post) {
                continue;
            }

            $actorType = (string) get_post_meta($id, 'meydan_author_actor_type', true);
            $actorId = (int) get_post_meta($id, 'meydan_author_actor_id', true);
            $orphaned = false;

            if ($actorType === 'square') {
                $orphaned = $actorId <= 0 || get_post_type($actorId) !== 'meydan_square';
            } elseif ($actorType === 'user') {
                $ownerId = $actorId > 0 ? $actorId : (int) $post->post_author;
                $orphaned = $ownerId <= 0 || !get_userdata($ownerId);
            } else {
                $authorId = (int) $post->post_author;
                $orphaned = $authorId <= 0 || !get_userdata($authorId);
            }

            if ($orphaned && wp_delete_post($id, true)) {
                $deleted++;
            }
        }

        update_option(self::ORPHAN_CLEANUP_OPTION, [
            'deleted' => $deleted,
            'ran_at' => current_time('mysql', true),
        ], false);
    }

    /** @param array<int,int|string> $ids */
    private static function forceDelete(array $ids): void
    {
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0 && get_post_type($id) === 'meydan_narrative') {
                wp_delete_post($id, true);
            }
        }
    }
}
