<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class EventLogger
{
    public static function log(string $eventType, string $entityType, ?int $entityId = null, array $metadata = [], ?Viewer $viewer = null): void
    {
        $viewer ??= Viewer::current();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'meydan_events', [
            'viewer_type' => $viewer->type,
            'viewer_id' => $viewer->id,
            'event_type' => sanitize_key($eventType),
            'entity_type' => sanitize_key($entityType),
            'entity_id' => $entityId,
            'metadata_json' => $metadata ? wp_json_encode($metadata) : null,
            'created_at' => current_time('mysql', true),
        ]);
    }
}
