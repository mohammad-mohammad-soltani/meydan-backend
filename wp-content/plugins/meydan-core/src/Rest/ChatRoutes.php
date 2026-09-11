<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

final class ChatRoutes
{
    private const NS = 'meydan/v1';

    public static function register(): void
    {
        $chat = new ChatController();
        self::route('/chat/conversations', 'GET', [$chat, 'conversations']);
        self::route('/chat/conversations', 'POST', [$chat, 'createConversation']);
        self::route('/chat/conversations/(?P<id>\d+)', 'GET', [$chat, 'conversation']);
        self::route('/chat/conversations/(?P<id>\d+)/messages', 'GET', [$chat, 'messages']);
        self::route('/chat/conversations/(?P<id>\d+)/messages', 'POST', [$chat, 'send']);
        self::route('/chat/conversations/(?P<id>\d+)/search', 'GET', [$chat, 'search']);
        self::route('/chat/conversations/(?P<id>\d+)/mute', 'PUT', [$chat, 'mute']);
        self::route('/chat/conversations/(?P<id>\d+)/read', 'PUT', [$chat, 'read']);
        self::route('/chat/messages/(?P<id>\d+)', 'PATCH', [$chat, 'edit']);
        self::route('/chat/messages/(?P<id>\d+)', 'DELETE', [$chat, 'delete']);
        self::route('/chat/messages/(?P<id>\d+)/reaction', 'PUT', [$chat, 'react']);
        self::route('/chat/messages/(?P<id>\d+)/reaction', 'DELETE', [$chat, 'unreact']);
        self::route('/chat/socket-ticket', 'POST', [$chat, 'socketTicket']);
    }

    private static function route(string $route, string $method, callable $callback): void
    {
        register_rest_route(self::NS, $route, [
            'methods' => $method,
            'callback' => $callback,
            'permission_callback' => static fn(): bool|\WP_Error => is_user_logged_in()
                ? true
                : new \WP_Error('unauthenticated', 'برای استفاده از گفتگو باید وارد شوید.', ['status' => 401]),
        ]);
    }
}
