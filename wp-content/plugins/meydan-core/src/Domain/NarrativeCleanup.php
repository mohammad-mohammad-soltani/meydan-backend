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
    private const ORPHAN_CLEANUP_OPTION = 'meydan_orphan_narratives_cleaned_v3';

    public static function register(): void
    {
        add_action('delete_user', [self::class, 'deleteUserNarratives'], 10, 1);
        add_action('before_delete_post', [self::class, 'deleteSquareNarratives'], 10, 2);
        add_action('before_delete_post', [self::class, 'deleteImportMapping'], 20, 2);
        add_action('before_delete_post', [self::class, 'deleteNarrativeRelations'], 30, 2);
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
     * One-time cleanup for every orphaned narrative, regardless of source.
     *
     * Native/manual narratives are orphaned when their user/square actor no
     * longer exists. Eitaa/Bale narratives follow the same actor rule and are
     * also orphaned when their import mapping no longer exists.
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

            $source = (string) get_post_meta($id, 'meydan_import_source', true);
            $sourceKey = trim((string) get_post_meta($id, 'meydan_import_source_key', true));
            $actorType = (string) get_post_meta($id, 'meydan_author_actor_type', true);
            $actorId = (int) get_post_meta($id, 'meydan_author_actor_id', true);

            $actorOrphaned = false;
            if ($actorType === 'square') {
                $square = $actorId > 0 ? get_post($actorId) : null;
                $actorOrphaned = !$square
                    || $square->post_type !== 'meydan_square'
                    || in_array($square->post_status, ['trash', 'auto-draft'], true);
            } elseif ($actorType === 'user') {
                $ownerId = $actorId > 0 ? $actorId : (int) $post->post_author;
                $actorOrphaned = $ownerId <= 0 || !get_userdata($ownerId);
            } else {
                $authorId = (int) $post->post_author;
                $actorOrphaned = $authorId <= 0 || !get_userdata($authorId);
            }

            $mappingOrphaned = in_array($source, ['eitaa', 'bale'], true)
                && !self::hasImportMapping($source, $sourceKey, $id);

            if (($actorOrphaned || $mappingOrphaned) && wp_delete_post($id, true)) {
                $deleted++;
            }
        }

        update_option(self::ORPHAN_CLEANUP_OPTION, [
            'deleted' => $deleted,
            'ran_at' => current_time('mysql', true),
        ], false);
    }

    private static function hasImportMapping(string $source, string $sourceKey, int $narrativeId): bool
    {
        if ($sourceKey === '' || !in_array($source, ['eitaa', 'bale'], true)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_' . $source . '_imports';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE source_key=%s AND narrative_id=%d LIMIT 1",
            $sourceKey,
            $narrativeId
        ));
    }

    /**
     * Removes stale integration rows when an imported narrative is permanently
     * deleted, preventing known() from returning a dead narrative id.
     */
    public static function deleteImportMapping(int $postId, WP_Post $post): void
    {
        if ($postId <= 0 || $post->post_type !== 'meydan_narrative') {
            return;
        }

        global $wpdb;
        foreach (['eitaa', 'bale'] as $source) {
            $table = $wpdb->prefix . 'meydan_' . $source . '_imports';
            $wpdb->delete($table, ['narrative_id' => $postId], ['%d']);
        }
    }

    /**
     * Removes custom-table data that WordPress cannot cascade when a narrative
     * is hard-deleted.
     */
    public static function deleteNarrativeRelations(int $postId, WP_Post $post): void
    {
        if ($postId <= 0 || $post->post_type !== 'meydan_narrative') {
            return;
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'meydan_narrative_stats', ['narrative_id' => $postId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'meydan_served_history', ['narrative_id' => $postId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'meydan_media_reflections', ['narrative_id' => $postId], ['%d']);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions
             WHERE object_type='narrative' AND object_id=%d",
            $postId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_notifications
             WHERE (entity_type='narrative' AND entity_id=%d)
                OR (parent_entity_type='narrative' AND parent_entity_id=%d)",
            $postId,
            $postId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_events
             WHERE entity_type='narrative' AND entity_id=%d",
            $postId
        ));
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
