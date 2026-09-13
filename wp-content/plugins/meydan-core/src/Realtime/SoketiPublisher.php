<?php

declare(strict_types=1);

namespace Meydan\Core\Realtime;

final class SoketiPublisher
{
    public static function publish(string $channel, string $event, array $payload = []): void
    {
        $appUrl = 'https://naghshman.ir/socket/app/naghsh-b5a7e6394b1c637cfee7eb2d7762a156';

        if ($appUrl === '') {
            return;
        }

        wp_remote_post($appUrl, [
            'timeout' => 2,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'channel' => $channel,
                'name' => $event,
                'data' => wp_json_encode($payload),
            ]),
        ]);
    }

    public static function user(string $userId, string $event, array $payload = []): void
    {
        self::publish('private-user-' . $userId, $event, $payload);
    }

    public static function feed(string $event, array $payload = []): void
    {
        self::publish('public-feed', $event, $payload);
    }
}
