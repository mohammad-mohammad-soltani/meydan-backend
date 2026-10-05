<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class EventLogger
{
    /** @var array<int,array<string,mixed>> rows waiting for the end of the request */
    private static array $buffer = [];
    private static bool $flushHooked = false;

    /** Hard stop so a runaway loop cannot build an unbounded INSERT. */
    private const MAX_BUFFER = 200;

    public static function log(string $eventType, string $entityType, ?int $entityId = null, array $metadata = [], ?Viewer $viewer = null): void
    {
        $viewer ??= Viewer::current();
        self::$buffer[] = [
            'viewer_type' => $viewer->type,
            'viewer_id' => $viewer->id,
            'event_type' => sanitize_key($eventType),
            'entity_type' => sanitize_key($entityType),
            'entity_id' => $entityId,
            'metadata_json' => $metadata ? wp_json_encode($metadata) : null,
            'created_at' => current_time('mysql', true),
        ];

        if (count(self::$buffer) >= self::MAX_BUFFER) {
            self::flush();
            return;
        }
        if (!self::$flushHooked) {
            self::$flushHooked = true;
            // Writes happen once per request, after the response is built, in a single multi-row INSERT.
            add_action('shutdown', [self::class, 'flush'], 5);
        }
    }

    public static function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }
        $rows = self::$buffer;
        self::$buffer = [];

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_events';
        $marks = [];
        $args = [];
        foreach ($rows as $row) {
            // Nullable columns get a literal NULL instead of a placeholder.
            $marks[] = '(%s, %s, %s, %s, ' . ($row['entity_id'] === null ? 'NULL' : '%d') . ', '
                . ($row['metadata_json'] === null ? 'NULL' : '%s') . ', %s)';
            array_push($args, $row['viewer_type'], $row['viewer_id'], $row['event_type'], $row['entity_type']);
            if ($row['entity_id'] !== null) {
                $args[] = (int) $row['entity_id'];
            }
            if ($row['metadata_json'] !== null) {
                $args[] = $row['metadata_json'];
            }
            $args[] = $row['created_at'];
        }
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (viewer_type, viewer_id, event_type, entity_type, entity_id, metadata_json, created_at) VALUES " . implode(',', $marks),
            ...$args
        );
        $wpdb->query($sql);
    }
}
