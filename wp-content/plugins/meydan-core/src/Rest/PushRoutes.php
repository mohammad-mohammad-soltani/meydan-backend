<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

final class PushRoutes
{
    private const NS = 'meydan/v1';

    public static function register(): void
    {
        $controller = new PushController();
        $permission = static fn(): bool|\WP_Error => is_user_logged_in()
            ? true
            : new \WP_Error('unauthenticated', 'برای انجام این عملیات باید وارد شوید.', ['status' => 401]);

        register_rest_route(self::NS, '/push/config', [
            'methods' => 'GET',
            'callback' => [$controller, 'config'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NS, '/push/subscriptions', [
            'methods' => 'POST',
            'callback' => [$controller, 'subscribe'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NS, '/push/native-tokens', [
            'methods' => 'POST',
            'callback' => [$controller, 'subscribeNative'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NS, '/push/native-tokens', [
            'methods' => 'DELETE',
            'callback' => [$controller, 'unsubscribeNative'],
            'permission_callback' => $permission,
        ]);
        register_rest_route(self::NS, '/push/subscriptions', [
            'methods' => 'DELETE',
            'callback' => [$controller, 'unsubscribe'],
            'permission_callback' => $permission,
        ]);
    }
}
