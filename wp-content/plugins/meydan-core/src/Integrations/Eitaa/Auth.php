<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use WP_Error;
use WP_REST_Request;

final class Auth
{
    private const MAX_SKEW = 300;
    private const NONCE_TTL = 600;

    public static function verify(WP_REST_Request $request): true|WP_Error
    {
        $secret = self::secret();
        if ($secret === '') {
            return new WP_Error('eitaa_integration_unconfigured', 'کلید همگام‌سازی ایتا تنظیم نشده است.', ['status' => 503]);
        }

        $timestampHeader = trim((string) $request->get_header('X-Meydan-Eitaa-Timestamp'));
        $nonce = trim((string) $request->get_header('X-Meydan-Eitaa-Nonce'));
        $signature = strtolower(trim((string) $request->get_header('X-Meydan-Eitaa-Signature')));
        if ($timestampHeader === '' || !ctype_digit($timestampHeader) || $nonce === '' || $signature === '') {
            return new WP_Error('eitaa_auth_missing', 'امضای سرویس ایتا ناقص است.', ['status' => 401]);
        }

        $timestamp = (int) $timestampHeader;
        if (abs(time() - $timestamp) > self::MAX_SKEW) {
            return new WP_Error('eitaa_auth_stale', 'درخواست ایتا منقضی شده است.', ['status' => 401]);
        }
        if (strlen($nonce) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $nonce)) {
            return new WP_Error('eitaa_auth_nonce', 'Nonce نامعتبر است.', ['status' => 401]);
        }

        $nonceKey = 'meydan_eitaa_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonceKey) !== false) {
            return new WP_Error('eitaa_auth_replay', 'درخواست تکراری رد شد.', ['status' => 409]);
        }

        $body = (string) $request->get_body();
        $canonical = strtoupper($request->get_method()) . "\n"
            . self::pathWithQuery($request) . "\n"
            . $timestampHeader . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('eitaa_auth_invalid', 'امضای سرویس ایتا معتبر نیست.', ['status' => 401]);
        }

        set_transient($nonceKey, 1, self::NONCE_TTL);
        return true;
    }

    public static function secret(): string
    {
        if (defined('MEYDAN_EITAA_SYNC_SECRET')) {
            $constant = trim((string) constant('MEYDAN_EITAA_SYNC_SECRET'));
            if ($constant !== '') {
                return $constant;
            }
        }
        return trim((string) getenv('MEYDAN_EITAA_SYNC_SECRET'));
    }

    private static function pathWithQuery(WP_REST_Request $request): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri !== '') {
            $path = parse_url($uri, PHP_URL_PATH) ?: $request->get_route();
            $query = parse_url($uri, PHP_URL_QUERY);
            return $query !== null && $query !== '' ? $path . '?' . $query : $path;
        }
        return $request->get_route();
    }
}
