<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Support\Handles;

/**
 * One-time split of the entity kinds that used to live in `meydan_square`.
 *
 * Media, collective and organization accounts were squares with a
 * `meydan_entity_kind` meta. Each now has its own post type, actor type and
 * role. Post ids do not change, so every reference by id stays valid; only the
 * type strings that sit next to those ids are rewritten.
 *
 * Idempotent: a finished run is recorded in an option and a second run finds
 * nothing left to move. `run(true)` only counts.
 */
final class EntityMigration
{
    public const OPTION = 'meydan_entity_split_v1';

    /** @return array<string,int> what was (or, in a dry run, would be) changed */
    public static function run(bool $dryRun = false): array
    {
        global $wpdb;
        $report = ['entities' => 0, 'narratives' => 0, 'contents' => 0, 'follows' => 0, 'affinity' => 0, 'notifications' => 0, 'events' => 0, 'links' => 0, 'handles' => 0];

        $rows = $wpdb->get_results(
            "SELECT p.ID AS id, k.meta_value AS kind FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = 'meydan_entity_kind'
             WHERE p.post_type = 'meydan_square' AND k.meta_value IN ('media','collective','organization')",
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $kind = (string) $row['kind'];
            $report['entities']++;
            if ($dryRun) continue;
            self::moveEntity($id, $kind, $report);
        }

        $report['handles'] = self::fixReservedHandles($dryRun);

        if (!$dryRun) update_option(self::OPTION, 1, false);
        return $report;
    }

    /** @param array<string,int> $report */
    private static function moveEntity(int $id, string $kind, array &$report): void
    {
        global $wpdb;
        $ownerId = (int) get_post_meta($id, 'meydan_owner_user_id', true) ?: (int) get_post_field('post_author', $id);
        $oldHandle = $ownerId > 0 ? Handles::ofUser($ownerId) : '';

        $wpdb->update($wpdb->posts, ['post_type' => EntityKinds::postType($kind)], ['ID' => $id], ['%s'], ['%d']);
        delete_post_meta($id, 'meydan_entity_kind');
        clean_post_cache($id);

        if ($ownerId > 0 && ($user = get_userdata($ownerId))) {
            $user->set_role(EntityKinds::roleFor($kind));
            update_user_meta($ownerId, 'meydan_account_type', $kind);
            update_user_meta($ownerId, 'meydan_square_id', $id);
        }

        // Narratives and content authored as this square.
        foreach (['meydan_author_actor' => 'narratives', 'meydan_producer_actor' => 'contents'] as $prefix => $key) {
            $report[$key] += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} t INNER JOIN {$wpdb->postmeta} i ON i.post_id = t.post_id AND i.meta_key = %s AND i.meta_value = %s
                 SET t.meta_value = %s WHERE t.meta_key = %s AND t.meta_value = 'square'",
                $prefix . '_id', (string) $id, $kind, $prefix . '_type'
            ));
        }

        $p = $wpdb->prefix . 'meydan_';
        $report['follows'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}interactions SET object_type=%s WHERE object_type='square' AND object_id=%d", $kind, $id));
        $report['affinity'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}actor_affinity SET target_actor_type=%s WHERE target_actor_type='square' AND target_actor_id=%d", $kind, $id));
        $report['notifications'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}notifications SET entity_type=%s WHERE entity_type='square' AND entity_id=%d", $kind, $id));
        $report['notifications'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}notifications SET parent_entity_type=%s WHERE parent_entity_type='square' AND parent_entity_id=%d", $kind, $id));
        if ($ownerId > 0) {
            // Follow notifications keep the follower's *user* id as the actor id.
            $report['notifications'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}notifications SET actor_type=%s WHERE actor_type='square' AND actor_id=%d", $kind, $ownerId));
        }
        $report['events'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}events SET entity_type=%s WHERE entity_type='square' AND entity_id=%d", $kind, $id));

        // Entities other than squares have no location or schedule.
        $wpdb->delete($p . 'square_geo', ['square_id' => $id], ['%d']);
        $wpdb->delete($p . 'square_schedule', ['square_id' => $id], ['%d']);

        // Stored links: `/square/{id}` becomes `/{handle}`.
        $new = $oldHandle !== '' ? '/' . $oldHandle : '/users/' . $kind . '/' . $id;
        $old = '/square/' . $id;
        $report['links'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}notifications SET deep_link=%s WHERE deep_link=%s", $new, $old));
        $report['links'] += (int) $wpdb->query($wpdb->prepare("UPDATE {$p}media_reflections SET url=%s WHERE url=%s", $new, $old));
    }

    /** Handles that now collide with a top-level route get a numeric suffix. */
    private static function fixReservedHandles(bool $dryRun): int
    {
        global $wpdb;
        $marks = implode(',', array_fill(0, count(Handles::RESERVED), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN ($marks)",
            Handles::META,
            ...Handles::RESERVED
        ), ARRAY_A) ?: [];
        if ($dryRun) return count($rows);

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            // `admin`-style handles are held by staff on purpose; leave administrators alone.
            $user = get_userdata($userId);
            if (!$user || in_array('administrator', (array) $user->roles, true)) continue;
            for ($i = 2; $i < 1000; $i++) {
                $candidate = $row['meta_value'] . '_' . $i;
                if (!is_wp_error(Handles::validate($candidate, $userId))) {
                    Handles::store($userId, $candidate);
                    break;
                }
            }
        }
        return count($rows);
    }
}
