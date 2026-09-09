<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;

final class Aggregator
{
    public static function body(string $type, string $actorType, int $actorId, int $count, string $fallback): string
    {
        $actor = Actor::parse($actorType, $actorId);
        $name = (string) ($actor['display_name'] ?? 'یک کاربر');
        if ($count <= 1) {
            return $fallback;
        }
        $others = $count - 1;
        return match ($type) {
            'like' => sprintf('%s و %d نفر دیگر روایت شما را پسندیدند.', $name, $others),
            'repost' => sprintf('%s و %d نفر دیگر روایت شما را بازنشر کردند.', $name, $others),
            'follow' => sprintf('%s و %d نفر دیگر شما را دنبال کردند.', $name, $others),
            default => $fallback,
        };
    }
}
