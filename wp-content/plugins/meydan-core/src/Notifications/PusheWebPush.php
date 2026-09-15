<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

final class PusheWebPush
{
    private const ENDPOINT = 'https://api.pushe.co/v2/messaging/web-rapid/';
    private const MAX_RECIPIENTS = 500;

    public static function isConfigured(): bool
    {
        return self::appId() !== '' && self::token() !== '';
    }

    public static function appId(): string
    {
        return self::secret('MEYDAN_PUSHE_APP_ID');
    }

    public static function customId(int $userId): string
    {
        return 'meydan-user-' . max(0, $userId);
    }

    public static function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $deepLink = null,
        ?string $iconUrl = null,
    ): void {
        if ($userId <= 0) {
            return;
        }
        self::sendToUsers([$userId], $title, $body, $deepLink, $iconUrl);
    }

    /** @param int[] $userIds */
    public static function sendToUsers(
        array $userIds,
        string $title,
        string $body,
        ?string $deepLink = null,
        ?string $iconUrl = null,
    ): void {
        if (!self::isConfigured()) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return;
        }

        $data = [
            'title' => sanitize_text_field($title),
            'content' => sanitize_textarea_field($body),
        ];

        if ($iconUrl && str_starts_with($iconUrl, 'https://')) {
            $data['icon'] = esc_url_raw($iconUrl);
        }

        $actionUrl = self::actionUrl($deepLink);
        if ($actionUrl !== null) {
            $data['action'] = [
                'action_type' => 'U',
                'url' => $actionUrl,
            ];
        }

        foreach (array_chunk($ids, self::MAX_RECIPIENTS) as $chunk) {
            $payload = [
                'app_id' => self::appId(),
                'custom_id' => array_map([self::class, 'customId'], $chunk),
                'data' => $data,
            ];

            // Push must not add latency to likes/comments or large broadcasts.
            // Pushe acknowledges the request independently, so fire-and-forget
            // is appropriate here; the in-app notification remains the source of truth.
            wp_remote_post(self::ENDPOINT, [
                'blocking' => false,
                'timeout' => 1,
                'redirection' => 0,
                'headers' => [
                    'Authorization' => 'Token ' . self::token(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data_format' => 'body',
            ]);
        }
    }

    private static function actionUrl(?string $deepLink): ?string
    {
        $deepLink = trim((string) $deepLink);
        if ($deepLink === '') {
            return null;
        }
        if (str_starts_with($deepLink, 'https://')) {
            return esc_url_raw($deepLink);
        }
        if (!str_starts_with($deepLink, '/')) {
            return null;
        }

        $frontend = rtrim(self::secret('MEYDAN_FRONTEND_URL'), '/');
        if ($frontend === '' || !str_starts_with($frontend, 'https://')) {
            return null;
        }
        return esc_url_raw($frontend . $deepLink);
    }

    private static function token(): string
    {
        return self::secret('MEYDAN_PUSHE_TOKEN');
    }

    private static function secret(string $name): string
    {
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) || is_numeric($value)) {
                return trim((string) $value);
            }
        }
        $value = getenv($name);
        return $value === false ? '' : trim((string) $value);
    }
}
