<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Error;

final class SoketiRealtime
{
    private const DEFAULT_HOST = 'naghshman.ir';
    private const DEFAULT_PORT = 6001;
    private const DEFAULT_SCHEME = 'http';

    public static function publicConfig(): array
    {
        return [
            'app_key' => self::appKey(),
            'host' => (string) (getenv('MEYDAN_SOKETI_PUBLIC_HOST') ?: self::host()),
            'port' => (int) (getenv('MEYDAN_SOKETI_PUBLIC_PORT') ?: self::port()),
            'scheme' => strtolower((string) (getenv('MEYDAN_SOKETI_PUBLIC_SCHEME') ?: self::scheme())),
        ];
    }

    public static function authorize(string $socketId, string $channelName, int $userId): array|WP_Error
    {
        if (!preg_match('/^\d+\.\d+$/', $socketId)) {
            return new WP_Error('invalid_socket_id', 'شناسه اتصال معتبر نیست.', ['status' => 422]);
        }
        if (!self::configured()) {
            return new WP_Error('realtime_unavailable', 'تنظیمات Soketi کامل نیست.', ['status' => 503]);
        }

        $channelData = null;
        if (preg_match('/^private-user-(\d+)$/', $channelName, $match)) {
            if ((int) $match[1] !== $userId) {
                return new WP_Error('realtime_forbidden', 'دسترسی به کانال مجاز نیست.', ['status' => 403]);
            }
        } elseif ($channelName === 'presence-meydan') {
            $actor = Actor::forUser($userId);
            $channelData = (string) wp_json_encode([
                'user_id' => (string) $userId,
                'user_info' => [
                    'name' => (string) ($actor['display_name'] ?? 'کاربر'),
                    'avatar_url' => !empty($actor['avatar_url']) ? (string) $actor['avatar_url'] : null,
                ],
            ]);
        } else {
            return new WP_Error('realtime_forbidden', 'دسترسی به کانال مجاز نیست.', ['status' => 403]);
        }

        $stringToSign = $socketId . ':' . $channelName;
        if ($channelData !== null) {
            $stringToSign .= ':' . $channelData;
        }
        $signature = hash_hmac('sha256', $stringToSign, self::appSecret());
        $result = ['auth' => self::appKey() . ':' . $signature];
        if ($channelData !== null) {
            $result['channel_data'] = $channelData;
        }
        return $result;
    }

    public static function publishToUser(int $userId, string $event, array $payload): void
    {
        if ($userId <= 0) return;
        self::publish(['private-user-' . $userId], $event, $payload);
    }

    public static function publishToConversation(int $conversationId, string $event, array $payload): void
    {
        if ($conversationId <= 0) return;
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_chat_participants';
        $userIds = array_values(array_unique(array_filter(array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE conversation_id=%d AND archived_at IS NULL",
            $conversationId
        )) ?: []))));
        if (!$userIds) return;
        self::publish(array_map(static fn(int $id): string => 'private-user-' . $id, $userIds), $event, $payload);
    }

    public static function publish(array $channels, string $event, array $payload): void
    {
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));
        if (!$channels || $event === '' || !self::configured()) return;

        $body = (string) wp_json_encode([
            'name' => $event,
            'channels' => $channels,
            'data' => (string) wp_json_encode($payload),
        ]);
        $path = '/apps/' . rawurlencode(self::appId()) . '/events';
        $params = [
            'auth_key' => self::appKey(),
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", self::appSecret());
        $url = sprintf('%s://%s:%d%s?%s&auth_signature=%s', self::scheme(), self::host(), self::port(), $path, $query, $signature);

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => 2,
            'redirection' => 0,
        ]);
        if (is_wp_error($response)) {
            error_log('[meydan-soketi] publish failed: ' . $response->get_error_message());
            return;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log('[meydan-soketi] publish returned HTTP ' . $status);
        }
    }

    public static function configured(): bool
    {
        return self::appId() !== '' && self::appKey() !== '' && self::appSecret() !== '' && self::host() !== '';
    }

    private static function appId(): string
    {
        return trim((string) (getenv('MEYDAN_SOKETI_APP_ID') ?: ''));
    }

    private static function appKey(): string
    {
        return trim((string) (getenv('MEYDAN_SOKETI_APP_KEY') ?: ''));
    }

    private static function appSecret(): string
    {
        return trim((string) (getenv('MEYDAN_SOKETI_APP_SECRET') ?: ''));
    }

    private static function host(): string
    {
        return trim((string) (getenv('MEYDAN_SOKETI_HOST') ?: self::DEFAULT_HOST));
    }

    private static function port(): int
    {
        return max(1, (int) (getenv('MEYDAN_SOKETI_PORT') ?: self::DEFAULT_PORT));
    }

    private static function scheme(): string
    {
        $scheme = strtolower(trim((string) (getenv('MEYDAN_SOKETI_SCHEME') ?: self::DEFAULT_SCHEME)));
        return $scheme === 'https' ? 'https' : 'http';
    }
}
