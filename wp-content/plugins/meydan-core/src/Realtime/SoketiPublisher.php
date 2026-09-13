<?php

declare(strict_types=1);

namespace Meydan\Core\Realtime;

use Meydan\Core\Support\SoketiRealtime;

/**
 * Backwards-compatible facade. All realtime traffic is delegated to the
 * single SoketiRealtime transport used by chat and other Meydan features.
 */
final class SoketiPublisher
{
    public static function publish(string $channel, string $event, array $payload = []): void
    {
        SoketiRealtime::publish([$channel], $event, $payload);
    }

    public static function user(string $userId, string $event, array $payload = []): void
    {
        $id = (int) $userId;
        if ($id <= 0) {
            return;
        }

        SoketiRealtime::publishToUser($id, $event, $payload);
    }

    public static function feed(string $event, array $payload = []): void
    {
        SoketiRealtime::publish(['public-feed'], $event, $payload);
    }
}
