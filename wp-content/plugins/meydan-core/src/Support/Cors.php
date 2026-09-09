<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Cors
{
    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', [self::class, 'headers']);
        }, 15);
    }

    public static function headers(bool $served): bool
    {
        $origin = get_http_origin();
        if (!$origin) {
            return $served;
        }
        $settings = (array) get_option('meydan_api_settings', []);
        $allowed = array_values(array_filter(array_map('strval', (array) ($settings['allowed_origins'] ?? []))));
        if (wp_get_environment_type() === 'local') {
            $allowed = array_unique(array_merge($allowed, ['http://localhost:3000', 'http://localhost:5173', 'http://127.0.0.1:3000']));
        }
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-WP-Nonce');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Vary: Origin', false);
        }
        return $served;
    }
}
