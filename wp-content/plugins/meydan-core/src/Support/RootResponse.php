<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class RootResponse
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'respond'], 0);
    }

    public static function respond(): void
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true) || !is_front_page()) {
            return;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));

        if ($method !== 'HEAD') {
            echo wp_json_encode(['message' => 'Enter the endpoint.'], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
