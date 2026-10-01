<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Timeline\NarrativeFeatureStore;

final class Stats
{
    public static function narrative(int $id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_narrative_stats';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE narrative_id = %d", $id), ARRAY_A);
        // Likes, reposts and comments are source-of-truth relations. Older
        // imported fixtures may contain stale aggregate values, so never let
        // those values disagree with the data users can actually see.
        $interactions = $wpdb->prefix . 'meydan_interactions';
        $likes = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$interactions} WHERE object_type='narrative' AND object_id=%d AND action='like'",
            $id
        ));
        $reposts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$interactions} WHERE object_type='narrative' AND object_id=%d AND action='repost'",
            $id
        ));
        $comments = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type='meydan_comment' AND comment_approved='1'",
            $id
        ));
        return [
            'views' => (int) ($row['views'] ?? 0),
            'likes' => $likes,
            'comments' => $comments,
            'reposts' => $reposts,
            'quotes' => (int) ($row['quotes'] ?? 0),
            'shares' => (int) ($row['shares'] ?? 0),
        ];
    }

    public static function content(int $id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_content_stats';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE content_id = %d", $id), ARRAY_A);
        return [
            'views' => (int) ($row['views'] ?? 0),
            'downloads' => (int) ($row['downloads'] ?? 0),
            'shares' => (int) ($row['shares'] ?? 0),
            'bookmarks' => (int) ($row['bookmarks'] ?? 0),
        ];
    }

    public static function incrementNarrative(int $id, string $field, int $delta = 1): void
    {
        if (!in_array($field, ['views', 'likes', 'comments', 'reposts', 'quotes', 'shares'], true) || $id <= 0) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_narrative_stats';
        $delta = max(-1_000_000, min(1_000_000, $delta));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (narrative_id, {$field}, updated_at) VALUES (%d, %d, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE {$field} = GREATEST(0, {$field} + VALUES({$field})), updated_at = UTC_TIMESTAMP()",
            $id,
            $delta
        ));
        // Keeps Timeline ranking's pre-scored cache from drifting between
        // NarrativeFeatureRefreshCron passes — a fresh like/comment/share
        // shows up in ranking immediately instead of within the next ~25min cycle.
        // A quote is ranked like a repost; the feature cache has no separate column.
        NarrativeFeatureStore::bumpStat($id, $field === 'quotes' ? 'reposts' : $field, $delta);
    }

    public static function incrementContent(int $id, string $field, int $delta = 1): void
    {
        if (!in_array($field, ['views', 'downloads', 'shares', 'bookmarks'], true) || $id <= 0) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_content_stats';
        $delta = max(-1_000_000, min(1_000_000, $delta));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (content_id, {$field}, updated_at) VALUES (%d, %d, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE {$field} = GREATEST(0, {$field} + VALUES({$field})), updated_at = UTC_TIMESTAMP()",
            $id,
            $delta
        ));
    }

    /** @param int[] $ids */
    public static function incrementViewsBulk(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if (!$ids) {
            return;
        }
        $ids = array_slice($ids, 0, 100);
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_narrative_stats';
        $values = [];
        $args = [];
        foreach ($ids as $id) {
            $values[] = '(%d, 1, UTC_TIMESTAMP())';
            $args[] = $id;
        }
        $sql = "INSERT INTO {$table} (narrative_id, views, updated_at) VALUES " . implode(',', $values) .
            ' ON DUPLICATE KEY UPDATE views = views + 1, updated_at = UTC_TIMESTAMP()';
        $wpdb->query($wpdb->prepare($sql, ...$args));

        // Same reasoning as incrementNarrative()'s bumpStat call, batched: one
        // UPDATE for the whole served page instead of one per narrative.
        $featuresTable = $wpdb->prefix . 'meydan_narrative_features';
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$featuresTable} SET views = views + 1 WHERE narrative_id IN ({$marks})",
            ...$ids
        ));
    }

    public static function correct(string $type, int $id, array $values): bool
    {
        global $wpdb;
        if ($type === 'narrative') {
            $allowed = ['views', 'likes', 'comments', 'reposts', 'quotes', 'shares'];
            $table = $wpdb->prefix . 'meydan_narrative_stats';
            $pk = 'narrative_id';
        } elseif ($type === 'content') {
            $allowed = ['views', 'downloads', 'shares', 'bookmarks'];
            $table = $wpdb->prefix . 'meydan_content_stats';
            $pk = 'content_id';
        } else {
            return false;
        }
        $data = [$pk => $id, 'updated_at' => current_time('mysql', true)];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $values)) {
                $data[$field] = max(0, (int) $values[$field]);
            }
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$pk} = %d", $id));
        if ($exists) {
            unset($data[$pk]);
            return $wpdb->update($table, $data, [$pk => $id]) !== false;
        }
        return $wpdb->insert($table, $data) !== false;
    }
}
