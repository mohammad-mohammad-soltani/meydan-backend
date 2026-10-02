<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

/** REST surface of «کارها» (work groups). Every route requires a signed-in user. */
final class WorkRoutes
{
    private const NS = 'meydan/v1';

    public static function register(): void
    {
        $w = new WorkController();
        $id = '(?P<id>\d+)';

        self::route('/works', 'GET', [$w, 'list']);
        self::route('/works/summary', 'GET', [$w, 'summary']);
        self::route("/works/{$id}", 'GET', [$w, 'get']);
        self::route("/works/{$id}", 'PATCH', [$w, 'update']);
        self::route("/works/{$id}", 'DELETE', [$w, 'destroy']);
        self::route("/works/{$id}/members", 'GET', [$w, 'members']);
        self::route("/works/{$id}/members/(?P<user_id>\d+)/label", 'PUT', [$w, 'setLabel']);
        self::route("/works/{$id}/members/(?P<user_id>\d+)/role", 'PUT', [$w, 'setRole']);
        self::route("/works/{$id}/join", 'PUT', [$w, 'join']);
        self::route("/works/{$id}/join", 'DELETE', [$w, 'leave']);
        self::route("/works/{$id}/read", 'PUT', [$w, 'read']);
        self::route("/works/{$id}/messages", 'GET', [$w, 'messages']);
        self::route("/works/{$id}/messages", 'POST', [$w, 'send']);

        self::route("/works/messages/{$id}", 'GET', [$w, 'message']);
        self::route("/works/messages/{$id}", 'PATCH', [$w, 'edit']);
        self::route("/works/messages/{$id}", 'DELETE', [$w, 'delete']);
        self::route("/works/messages/{$id}/reaction", 'PUT', [$w, 'react']);
        self::route("/works/messages/{$id}/reaction", 'DELETE', [$w, 'unreact']);

        self::route("/works/tasks/{$id}/claim", 'POST', [$w, 'claim']);
        self::route("/works/tasks/{$id}/claim", 'DELETE', [$w, 'unclaim']);
        self::route("/works/tasks/{$id}/status", 'PUT', [$w, 'taskStatus']);
        self::route("/works/tasks/{$id}/people", 'PUT', [$w, 'taskPeople']);
        self::route("/works/tasks/{$id}/nudge", 'POST', [$w, 'nudge']);
        self::route("/works/tasks/{$id}/items", 'POST', [$w, 'addItem']);
        self::route("/works/task-items/{$id}", 'PATCH', [$w, 'updateItem']);
        self::route("/works/task-items/{$id}", 'DELETE', [$w, 'deleteItem']);

        self::route("/works/meetings/{$id}/rsvp", 'PUT', [$w, 'rsvp']);
        self::route("/works/announcements/{$id}/seen", 'PUT', [$w, 'seen']);
        self::route("/works/announcements/{$id}/seen", 'GET', [$w, 'seenList']);
        self::route("/works/announcements/{$id}/remind", 'POST', [$w, 'remind']);
        self::route("/works/polls/{$id}/vote", 'PUT', [$w, 'vote']);
    }

    private static function route(string $route, string $method, callable $callback): void
    {
        register_rest_route(self::NS, $route, [
            'methods' => $method,
            'callback' => $callback,
            'permission_callback' => static fn(): bool|\WP_Error => is_user_logged_in()
                ? true
                : new \WP_Error('unauthenticated', 'برای استفاده از کارها باید وارد شوید.', ['status' => 401]),
        ]);
    }
}
