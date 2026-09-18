<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\UserAccess;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ApiMiddleware
{
    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 10, 3);
        add_filter('rest_post_dispatch', [self::class, 'postDispatch'], 10, 3);
    }

    public static function preDispatch(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if ($result !== null || !str_starts_with($request->get_route(), '/meydan/v1/')) {
            return $result;
        }
        UserAccess::resetContext();
        if ($request->get_method() === 'POST') {
            return Idempotency::lookup($request) ?? $result;
        }
        return $result;
    }

    public static function postDispatch(WP_HTTP_Response $response, WP_REST_Server $server, WP_REST_Request $request): WP_HTTP_Response
    {
        if (!str_starts_with($request->get_route(), '/meydan/v1/')) {
            return $response;
        }
        if (!$response instanceof WP_REST_Response) {
            $response = rest_ensure_response($response);
        }
        $data = $response->get_data();
        if ($response->get_status() >= 400 && is_array($data) && !isset($data['error']) && isset($data['code'], $data['message'])) {
            $fields = is_array($data['data']['fields'] ?? null) ? $data['data']['fields'] : [];
            $response->set_data(['error' => ['code' => (string) $data['code'], 'message' => (string) $data['message'], 'fields' => (object) $fields]]);
        }
        $response->header('X-Request-Id', Response::requestId());
        $response->header('X-Content-Type-Options', 'nosniff');
        // Account moderation must take effect without a previously cached public
        // profile, square, search result or timeline reappearing for another minute.
        if (preg_match('#^/meydan/v1/(?:users|actors|speakers|squares|narratives|comments|content|timeline|explore)(?:/|$)#', $request->get_route())) {
            $response->header('Cache-Control', 'no-store');
        }
        if ($request->get_method() === 'POST') {
            Idempotency::store($request, $response);
        }
        return $response;
    }
}
