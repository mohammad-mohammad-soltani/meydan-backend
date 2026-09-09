<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Stats
{
    public static function narrative(int $id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_narrative_stats';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE narrative_id = %d", $id), ARRAY_A);
        return [
            'views' => (int) ($row['views'] ?? 0),
            'likes' => (int) ($row['likes'] ?? 0),
            'comments' => (int) ($row['comments'] ?? 0),
            'reposts' => (int) ($row['reposts'] ?? 0),
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
        if (!in_array($field, ['views', 'likes', 'comments', 'reposts', 'shares'], true) || $id <= 0) {
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
    }

    public static function correct(string $type, int $id, array $values): bool
    {
        global $wpdb;
        if ($type === 'narrative') {
            $allowed = ['views', 'likes', 'comments', 'reposts', 'shares'];
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
