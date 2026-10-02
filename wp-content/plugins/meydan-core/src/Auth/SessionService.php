<?php

declare(strict_types=1);

namespace Meydan\Core\Auth;

use Meydan\Core\Support\Crypto;
use Meydan\Core\Domain\UserAccess;
use WP_Error;

final class SessionService
{
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = YEAR_IN_SECONDS;

    public function issue(int $userId, ?string $deviceName = null, bool $persistentDevice = false): array|WP_Error
    {
        if (!get_userdata($userId) || UserAccess::disabled($userId)) {
            return new WP_Error('user_not_found', 'حساب کاربری پیدا نشد.', ['status' => 404]);
        }
        $access = Crypto::randomToken(32, 'acc_');
        $refresh = Crypto::randomToken(48, 'ref_');
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_sessions';
        $inserted = $wpdb->insert($table, [
            'user_id' => $userId,
            'access_token_hash' => Crypto::hash($access),
            'refresh_token_hash' => Crypto::hash($refresh),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL),
            'refresh_expires_at' => $persistentDevice ? null : gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'persistent_device' => $persistentDevice ? 1 : 0,
            'device_name' => $deviceName ? sanitize_text_field($deviceName) : null,
            'last_used_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
        ]);
        if (!$inserted) {
            return new WP_Error('internal_error', 'ایجاد نشست ناموفق بود.', ['status' => 500]);
        }
        self::setRefreshCookie($refresh, $persistentDevice);
        return ['access_token' => $access, 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh];
    }

    public function refresh(?string $refreshToken = null): array|WP_Error
    {
        $refreshToken = $refreshToken ?: (isset($_COOKIE['meydan_refresh']) ? sanitize_text_field(wp_unslash($_COOKIE['meydan_refresh'])) : '');
        if ($refreshToken === '') {
            return new WP_Error('unauthenticated', 'نشست معتبر نیست.', ['status' => 401]);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_sessions';
        $hash = Crypto::hash($refreshToken);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE refresh_token_hash = %s AND revoked_at IS NULL AND (persistent_device = 1 OR refresh_expires_at >= UTC_TIMESTAMP()) LIMIT 1",
            $hash
        ));
        if (!$row || UserAccess::disabled((int) $row->user_id)) {
            self::clearRefreshCookie();
            return new WP_Error('unauthenticated', 'نشست معتبر نیست.', ['status' => 401]);
        }

        $newAccess = Crypto::randomToken(32, 'acc_');
        $updates = [
            'access_token_hash' => Crypto::hash($newAccess),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL),
            'last_used_at' => current_time('mysql', true),
        ];
        $persistentDevice = (int) ($row->persistent_device ?? 0) === 1;
        if (!$persistentDevice) {
            $updates['refresh_expires_at'] = gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL);
        }
        $wpdb->update($table, $updates, ['id' => (int) $row->id]);
        // Keep the refresh credential stable. Concurrent API requests can both
        // renew an expired access token; rotating this value let a late response
        // overwrite the browser with a token the database had already replaced.
        self::setRefreshCookie($refreshToken, $persistentDevice);
        return ['access_token' => $newAccess, 'expires_in' => self::ACCESS_TTL];
    }

    public function logoutCurrent(): void
    {
        $token = self::bearerToken();
        if ($token !== '') {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'meydan_sessions', ['revoked_at' => current_time('mysql', true)], ['access_token_hash' => Crypto::hash($token)]);
        }
        self::clearRefreshCookie();
    }

    public function logoutAll(int $userId): void
    {
        $this->revokeAll($userId);
        self::clearRefreshCookie();
    }

    /** Admin account changes must not clear the administrator's own cookie. */
    public function revokeAll(int $userId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_sessions';
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET revoked_at = UTC_TIMESTAMP() WHERE user_id = %d AND revoked_at IS NULL", $userId));
    }

    public static function authenticateBearer(mixed $userId): mixed
    {
        if ($userId) {
            return $userId;
        }
        $token = self::bearerToken();
        if ($token === '') {
            return $userId;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_sessions';
        $hash = Crypto::hash($token);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, last_used_at FROM {$table} WHERE access_token_hash = %s AND revoked_at IS NULL AND access_expires_at >= UTC_TIMESTAMP() LIMIT 1",
            $hash
        ));
        if (!$row || UserAccess::disabled((int) $row->user_id)) {
            return $userId;
        }
        // Touching the row on every request turns each API call into a write; once per 5 minutes is enough for "last seen".
        $lastUsed = $row->last_used_at ? strtotime((string) $row->last_used_at . ' UTC') : 0;
        if (!$lastUsed || time() - $lastUsed >= 300) {
            $wpdb->update($table, ['last_used_at' => current_time('mysql', true)], ['id' => (int) $row->id]);
        }
        return (int) $row->user_id;
    }

    public static function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function setRefreshCookie(string $token, bool $persistentDevice = false): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie('meydan_refresh', $token, [
            'expires' => time() + ($persistentDevice ? 20 * YEAR_IN_SECONDS : self::REFRESH_TTL),
            'path' => '/wp-json/meydan/v1/auth',
            'secure' => is_ssl() || wp_get_environment_type() === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['meydan_refresh'] = $token;
    }

    public static function clearRefreshCookie(): void
    {
        if (!headers_sent()) {
            setcookie('meydan_refresh', '', [
                'expires' => time() - HOUR_IN_SECONDS,
                'path' => '/wp-json/meydan/v1/auth',
                'secure' => is_ssl() || wp_get_environment_type() === 'production',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE['meydan_refresh']);
    }
}
