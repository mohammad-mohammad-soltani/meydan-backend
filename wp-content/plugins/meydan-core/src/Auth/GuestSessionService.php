<?php

declare(strict_types=1);

namespace Meydan\Core\Auth;

use Meydan\Core\Support\Crypto;

final class GuestSessionService
{
    private static ?string $id = null;

    public static function ensureGuestCookie(): void
    {
        self::id();
    }

    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        $raw = isset($_COOKIE['meydan_guest']) ? sanitize_text_field(wp_unslash($_COOKIE['meydan_guest'])) : '';
        if (preg_match('/^[a-f0-9-]{36}$/i', $raw)) {
            return self::$id = strtolower($raw);
        }

        $uuid = wp_generate_uuid4();
        self::$id = $uuid;
        if (!headers_sent()) {
            setcookie('meydan_guest', $uuid, [
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['meydan_guest'] = $uuid;
        }
        return $uuid;
    }
}
