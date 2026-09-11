<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class ChatSocketTicket
{
    public static function issue(int $userId, int $ttl = 60): array
    {
        $now = time();
        $payload = [
            'uid' => $userId,
            'iat' => $now,
            'exp' => $now + max(15, min(300, $ttl)),
            'jti' => bin2hex(random_bytes(12)),
        ];
        $encoded = self::base64UrlEncode((string) wp_json_encode($payload));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encoded, self::secret(), true));
        return [
            'ticket' => $encoded . '.' . $signature,
            'user_id' => $userId,
            'expires_at' => gmdate('c', $payload['exp']),
            'socket_url' => rtrim((string) (getenv('MEYDAN_CHAT_SOCKET_URL') ?: ''), '/'),
        ];
    }

    private static function secret(): string
    {
        $secret = (string) (getenv('MEYDAN_CHAT_SOCKET_SECRET') ?: '');
        if ($secret === '') {
            throw new \RuntimeException('MEYDAN_CHAT_SOCKET_SECRET is not configured.');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
