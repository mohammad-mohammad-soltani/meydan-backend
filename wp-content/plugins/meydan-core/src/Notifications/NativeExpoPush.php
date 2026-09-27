<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use WP_Error;

/** Delivers native Android/iOS notifications through Expo's push gateway. */
final class NativeExpoPush
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const TOKEN = '/^(?:ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]{1,512}\]$/';

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match(self::TOKEN, $token);
    }

    public static function subscribe(int $userId, string $token, string $platform): true|WP_Error
    {
        $token = trim($token);
        $platform = sanitize_key($platform);
        if ($userId <= 0) {
            return new WP_Error('native_push_unauthenticated', 'فعال‌سازی اعلان نیاز به ورود دارد.', ['status' => 401]);
        }
        if (!self::isValidToken($token) || !in_array($platform, ['android', 'ios'], true)) {
            return new WP_Error('native_push_invalid_token', 'توکن اعلان Native معتبر نیست.', ['status' => 422]);
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $table = self::table();
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id,token_hash,token,platform,created_at,updated_at,failure_count)\n"
            . "VALUES (%d,%s,%s,%s,%s,%s,0)\n"
            . "ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),token=VALUES(token),platform=VALUES(platform),updated_at=VALUES(updated_at),failure_count=0",
            $userId,
            hash('sha256', $token),
            $token,
            $platform,
            $now,
            $now,
        ));
        if ($result === false) {
            return new WP_Error('native_push_subscribe_failed', 'ذخیره توکن اعلان انجام نشد.', ['status' => 500]);
        }
        return true;
    }

    public static function unsubscribe(int $userId, string $token): true|WP_Error
    {
        if ($userId <= 0 || !self::isValidToken(trim($token))) {
            return new WP_Error('native_push_invalid_token', 'توکن اعلان Native معتبر نیست.', ['status' => 422]);
        }
        global $wpdb;
        $wpdb->delete(self::table(), [
            'user_id' => $userId,
            'token_hash' => hash('sha256', trim($token)),
        ], ['%d', '%s']);
        return true;
    }

    /** @param array<string,mixed> $data */
    public static function payload(string $token, string $title, string $body, ?string $deepLink, array $data = []): array
    {
        $safeData = [];
        foreach ($data as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && (is_scalar($value) || $value === null)) {
                $safeData[$key] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }
        $safeData['url'] = self::deepLink($deepLink);
        return [
            'to' => $token,
            'title' => mb_substr(sanitize_text_field($title), 0, 160),
            'body' => mb_substr(sanitize_textarea_field($body), 0, 700),
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
            'data' => $safeData,
        ];
    }

    /** @param int[] $userIds @param array<string,mixed> $data */
    public static function sendToUsers(array $userIds, string $title, string $body, ?string $deepLink = null, array $data = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) return;
        global $wpdb;
        foreach (array_chunk($ids, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,token FROM " . self::table() . " WHERE user_id IN ({$placeholders}) ORDER BY id ASC",
                ...$chunk,
            ), ARRAY_A) ?: [];
            foreach (array_chunk($rows, 100) as $tokens) {
                $messages = array_map(
                    static fn(array $row): array => self::payload((string) $row['token'], $title, $body, $deepLink, $data),
                    $tokens,
                );
                self::deliver($tokens, $messages);
            }
        }
    }

    /** @param array<int,array{id:mixed,token:mixed}> $tokens @param array<int,array<string,mixed>> $messages */
    private static function deliver(array $tokens, array $messages): void
    {
        if (!$messages) return;
        $response = wp_safe_remote_post(self::ENDPOINT, [
            'timeout' => 8,
            'headers' => ['content-type' => 'application/json', 'accept' => 'application/json'],
            'body' => wp_json_encode($messages),
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
            foreach ($tokens as $row) self::markFailure((int) ($row['id'] ?? 0));
            return;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $tickets = is_array($body['data'] ?? null) ? $body['data'] : [];
        foreach ($tokens as $index => $row) {
            $ticket = is_array($tickets[$index] ?? null) ? $tickets[$index] : [];
            if (($ticket['status'] ?? '') === 'ok') {
                self::markSuccess((int) ($row['id'] ?? 0));
            } elseif (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered') {
                self::delete((int) ($row['id'] ?? 0));
            } else {
                self::markFailure((int) ($row['id'] ?? 0));
            }
        }
    }

    private static function deepLink(?string $value): string
    {
        $value = trim((string) $value);
        return str_starts_with($value, '/') && !str_starts_with($value, '//') ? $value : '/home';
    }

    private static function table(): string { global $wpdb; return $wpdb->prefix . 'meydan_native_push_tokens'; }
    private static function markSuccess(int $id): void { global $wpdb; if ($id > 0) $wpdb->update(self::table(), ['last_success_at' => current_time('mysql', true), 'failure_count' => 0], ['id' => $id]); }
    private static function markFailure(int $id): void { global $wpdb; if ($id > 0) $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET failure_count=failure_count+1 WHERE id=%d', $id)); }
    private static function delete(int $id): void { global $wpdb; if ($id > 0) $wpdb->delete(self::table(), ['id' => $id], ['%d']); }
}
