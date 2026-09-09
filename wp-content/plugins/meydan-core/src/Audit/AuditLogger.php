<?php

declare(strict_types=1);

namespace Meydan\Core\Audit;

use Meydan\Core\Support\Crypto;

final class AuditLogger
{
    public static function log(string $action, string $entityType, string|int|null $entityId = null, mixed $before = null, mixed $after = null, ?int $adminId = null): void
    {
        $adminId ??= get_current_user_id();
        global $wpdb;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $wpdb->insert($wpdb->prefix . 'meydan_audit_log', [
            'admin_id' => max(0, $adminId),
            'action' => sanitize_key($action),
            'entity_type' => sanitize_key($entityType),
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'before_json' => $before !== null ? wp_json_encode($before) : null,
            'after_json' => $after !== null ? wp_json_encode($after) : null,
            'ip_hash' => $ip !== '' ? Crypto::hash($ip) : null,
            'created_at' => current_time('mysql', true),
        ]);
    }
}
