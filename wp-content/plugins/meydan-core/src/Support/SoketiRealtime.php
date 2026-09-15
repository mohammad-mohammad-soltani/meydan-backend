<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Error;

/**
 * Single Pusher-compatible realtime transport for Meydan.
 *
 * Browser clients connect through:
 *   wss://socket.naghshman.ir/app/{APP_KEY}
 *
 * Server-side event publishing uses the same public Soketi endpoint through:
 *   https://socket.naghshman.ir/apps/{APP_ID}/events
 *
 * Soketi is exposed directly on the socket.naghshman.ir subdomain, so the
 * canonical Pusher paths (/app/... and /apps/...) are used without a prefix.
 */
final class SoketiRealtime
{
    private const APP_ID = 'naghsh-e4bb4dbbaa0ac5a621376177435d9390';
    private const APP_KEY = 'naghsh-b5a7e6394b1c637cfee7eb2d7762a156';
    private const APP_SECRET = 'naghsh-34e3ad5bfe824c98885f91ebc1cec44e';

    private const HOST = 'socket.naghshman.ir';
    private const PORT = 443;
    private const SCHEME = 'https';
    private const SOCKET_PATH = '';

    public static function publicConfig(): array
    {
        $socketUrl = sprintf(
            'wss://%s%s/app/%s',
            self::HOST,
            self::SOCKET_PATH,
            rawurlencode(self::APP_KEY)
        );

        return [
            'app_key' => self::APP_KEY,
            'host' => self::HOST,
            'port' => self::PORT,
            'scheme' => self::SCHEME,
            'path' => self::SOCKET_PATH,
            'ws_path' => self::SOCKET_PATH,
            'socket_url' => $socketUrl,
            'ws_url' => $socketUrl,
        ];
    }

    public static function authorize(string $socketId, string $channelName, int $userId): array|WP_Error
    {
        if (!preg_match('/^\d+\.\d+$/', $socketId)) {
            return new WP_Error('invalid_socket_id', 'شناسه اتصال معتبر نیست.', ['status' => 422]);
        }
        if ($userId <= 0) {
            return new WP_Error('realtime_unauthenticated', 'برای اتصال زنده باید وارد شوید.', ['status' => 401]);
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

        $signature = hash_hmac('sha256', $stringToSign, self::APP_SECRET);
        $result = ['auth' => self::APP_KEY . ':' . $signature];

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
        $userIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE conversation_id=%d AND archived_at IS NULL",
                $conversationId
            )) ?: []
        ))));

        if (!$userIds) return;

        self::publish(
            array_map(static fn(int $id): string => 'private-user-' . $id, $userIds),
            $event,
            $payload
        );
    }

    public static function publish(array $channels, string $event, array $payload): void
    {
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));
        $event = trim($event);
        if (!$channels || $event === '') return;

        $body = (string) wp_json_encode([
            'name' => $event,
            'channels' => $channels,
            'data' => (string) wp_json_encode($payload),
        ]);

        if ($body === '') {
            error_log('[meydan-soketi] failed to encode event payload');
            return;
        }

        $canonicalPath = '/apps/' . rawurlencode(self::APP_ID) . '/events';
        $params = [
            'auth_key' => self::APP_KEY,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($params);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n{$canonicalPath}\n{$query}", self::APP_SECRET);
        $url = sprintf(
            '%s://%s%s?%s&auth_signature=%s',
            self::SCHEME,
            self::HOST,
            $canonicalPath,
            $query,
            rawurlencode($signature)
        );

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            error_log('[meydan-soketi] publish failed: ' . $response->get_error_message());
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $responseBody = trim((string) wp_remote_retrieve_body($response));
            error_log(sprintf(
                '[meydan-soketi] publish returned HTTP %d%s',
                $status,
                $responseBody !== '' ? ': ' . substr($responseBody, 0, 500) : ''
            ));
        }
    }

    public static function configured(): bool
    {
        return self::APP_ID !== ''
            && self::APP_KEY !== ''
            && self::APP_SECRET !== ''
            && self::HOST !== '';
    }
}
