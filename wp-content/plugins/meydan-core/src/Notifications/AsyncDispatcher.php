<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\SoketiRealtime;

/**
 * Moves realtime publish + push delivery off the request thread.
 *
 * NotificationService::create() and ChatController's message/typing/read
 * handlers used to call SoketiRealtime and the push providers synchronously
 * — each doing a blocking wp_remote_post (5-8s timeout, one per device for
 * push) inline in the response path. A slow or unreachable realtime/push
 * backend could hold a "like" or a chat message open for tens of seconds and
 * tie up a PHP-FPM worker the whole time.
 *
 * wp_schedule_single_event(time(), ...) makes WordPress fire its normal
 * non-blocking wp-cron.php loopback request near the end of *this* request
 * (see WP's spawn_cron()); the actual HTTP calls to Soketi/WebPush/Expo then
 * run inside that separate loopback request, so the caller's response is
 * never gated on them. No new infrastructure — same primitive already used
 * by Storage\VideoPosterBackfill and NativeExpoPush's receipt check.
 */
final class AsyncDispatcher
{
    private const REALTIME_USER_HOOK = 'meydan_async_realtime_user';
    private const REALTIME_CONVERSATION_HOOK = 'meydan_async_realtime_conversation';
    private const REALTIME_USERS_HOOK = 'meydan_async_realtime_users';
    private const PUSH_HOOK = 'meydan_async_push_dispatch';
    private const WEB_PUSH_ONLY_HOOK = 'meydan_async_web_push_only';

    public static function register(): void
    {
        add_action(self::REALTIME_USER_HOOK, [self::class, 'runRealtimeUser'], 10, 3);
        add_action(self::REALTIME_CONVERSATION_HOOK, [self::class, 'runRealtimeConversation'], 10, 3);
        add_action(self::REALTIME_USERS_HOOK, [self::class, 'runRealtimeUsers'], 10, 3);
        add_action(self::PUSH_HOOK, [self::class, 'runPush'], 10, 1);
        add_action(self::WEB_PUSH_ONLY_HOOK, [self::class, 'runWebPushOnly'], 10, 1);
    }

    /** @param array<string,mixed> $payload */
    public static function queueRealtimeToUser(int $userId, string $event, array $payload): void
    {
        if ($userId <= 0) {
            return;
        }
        wp_schedule_single_event(time(), self::REALTIME_USER_HOOK, [$userId, $event, $payload]);
    }

    /**
     * One queued job per 1000 users (instead of one per user); the job itself
     * publishes in batches of 100 channels per Pusher call.
     *
     * @param int[] $userIds
     * @param array<string,mixed> $payload
     */
    public static function queueRealtimeToUsers(array $userIds, string $event, array $payload): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        foreach (array_chunk($userIds, 1000) as $chunk) {
            wp_schedule_single_event(time(), self::REALTIME_USERS_HOOK, [$chunk, $event, $payload]);
        }
    }

    /** @param array<string,mixed> $payload */
    public static function queueRealtimeToConversation(int $conversationId, string $event, array $payload): void
    {
        if ($conversationId <= 0) {
            return;
        }
        wp_schedule_single_event(time(), self::REALTIME_CONVERSATION_HOOK, [$conversationId, $event, $payload]);
    }

    /**
     * @param int[] $userIds
     * @param array<string,mixed> $data
     */
    public static function queuePush(array $userIds, string $title, string $body, ?string $deepLink, ?string $iconUrl, array $data): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$userIds) {
            return;
        }
        wp_schedule_single_event(time(), self::PUSH_HOOK, [[
            'user_ids' => $userIds,
            'title' => $title,
            'body' => $body,
            'deep_link' => $deepLink,
            'icon_url' => $iconUrl,
            'data' => $data,
        ]]);
    }

    /**
     * Chat's own message-received push has always been WebPush-only (never
     * Expo, unlike NotificationService's push), so it gets a dedicated job
     * type rather than reusing queuePush() and silently changing that.
     *
     * @param int[] $userIds
     * @param array<string,mixed> $data
     */
    public static function queueWebPushOnly(array $userIds, string $title, string $body, ?string $deepLink, ?string $iconUrl, array $data): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$userIds) {
            return;
        }
        wp_schedule_single_event(time(), self::WEB_PUSH_ONLY_HOOK, [[
            'user_ids' => $userIds,
            'title' => $title,
            'body' => $body,
            'deep_link' => $deepLink,
            'icon_url' => $iconUrl,
            'data' => $data,
        ]]);
    }

    /** @param array{user_ids:int[],title:string,body:string,deep_link:?string,icon_url:?string,data:array<string,mixed>} $job */
    public static function runWebPushOnly(array $job): void
    {
        $userIds = array_map('intval', (array) ($job['user_ids'] ?? []));
        if (!$userIds) {
            return;
        }
        NativeWebPush::sendToUsers(
            $userIds,
            (string) ($job['title'] ?? ''),
            (string) ($job['body'] ?? ''),
            $job['deep_link'] ?? null,
            $job['icon_url'] ?? null,
            (array) ($job['data'] ?? []),
        );
    }

    /** @param array<string,mixed> $payload */
    public static function runRealtimeUser(int $userId, string $event, array $payload): void
    {
        SoketiRealtime::publishToUser($userId, $event, $payload);
    }

    /**
     * @param int[] $userIds
     * @param array<string,mixed> $payload
     */
    public static function runRealtimeUsers(array $userIds, string $event, array $payload): void
    {
        SoketiRealtime::publishToUsers($userIds, $event, $payload);
    }

    /** @param array<string,mixed> $payload */
    public static function runRealtimeConversation(int $conversationId, string $event, array $payload): void
    {
        SoketiRealtime::publishToConversation($conversationId, $event, $payload);
    }

    /** @param array{user_ids:int[],title:string,body:string,deep_link:?string,icon_url:?string,data:array<string,mixed>} $job */
    public static function runPush(array $job): void
    {
        $userIds = array_map('intval', (array) ($job['user_ids'] ?? []));
        if (!$userIds) {
            return;
        }
        $title = (string) ($job['title'] ?? '');
        $body = (string) ($job['body'] ?? '');
        $deepLink = $job['deep_link'] ?? null;
        $iconUrl = $job['icon_url'] ?? null;
        $data = (array) ($job['data'] ?? []);

        NativeWebPush::sendToUsers($userIds, $title, $body, $deepLink, $iconUrl, $data);
        NativeExpoPush::sendToUsers($userIds, $title, $body, $deepLink, $data);
    }
}
