<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_REST_Request;
use WP_REST_Response;

final class Idempotency
{
    public static function lookup(WP_REST_Request $request): ?WP_REST_Response
    {
        $key = trim((string) $request->get_header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 190) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_idempotency';
        $owner = self::ownerKey();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT response_json, status_code FROM {$table} WHERE owner_key = %s AND route = %s AND idem_key = %s AND expires_at >= UTC_TIMESTAMP() LIMIT 1",
            $owner,
            $request->get_route(),
            $key
        ));
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row->response_json, true);
        return new WP_REST_Response(is_array($data) ? $data : [], (int) $row->status_code);
    }

    public static function store(WP_REST_Request $request, WP_REST_Response $response): void
    {
        $key = trim((string) $request->get_header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 190 || $response->get_status() >= 500) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_idempotency';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (owner_key, route, idem_key, response_json, status_code, expires_at, created_at)
             VALUES (%s, %s, %s, %s, %d, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), status_code = VALUES(status_code), expires_at = VALUES(expires_at)",
            self::ownerKey(),
            $request->get_route(),
            $key,
            wp_json_encode($response->get_data()),
            $response->get_status()
        ));
    }

    private static function ownerKey(): string
    {
        $uid = get_current_user_id();
        return $uid > 0 ? 'user:' . $uid : 'guest:' . \Meydan\Core\Auth\GuestSessionService::id();
    }
}
