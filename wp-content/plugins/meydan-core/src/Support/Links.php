<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\EntityKinds;

/**
 * The one place that builds links to public profiles.
 *
 * Every profile (user, speaker, official, square, media, collective,
 * organization) lives at `/{handle}`. Never concatenate `/square/…` or
 * `/users/…` by hand.
 */
final class Links
{
    /** `/handle` for an actor payload from Actor::forUser()/forEntity(). */
    public static function profile(array $actor): string
    {
        $handle = (string) ($actor['handle'] ?? '');
        if ($handle !== '') return '/' . $handle;
        $type = (string) ($actor['type'] ?? 'user');
        $raw = (string) ($actor['id'] ?? '');
        $id = (int) substr($raw, (int) strrpos($raw, '_') + 1);
        return self::byId($type, $id);
    }

    public static function forUser(int $userId): string
    {
        $handle = Handles::ofUser($userId);
        return $handle !== '' ? '/' . $handle : self::byId('user', $userId);
    }

    public static function forEntity(int $entityId): string
    {
        $owner = Actor::squareOwnerUserId($entityId);
        $handle = $owner > 0 ? Handles::ofUser($owner) : '';
        return $handle !== '' ? '/' . $handle : self::byId(EntityKinds::kindOf($entityId), $entityId);
    }

    /** Legacy id-based address; the app redirects it to `/{handle}`. Only a fallback for handle-less accounts. */
    private static function byId(string $type, int $id): string
    {
        return '/users/' . ($type === '' ? 'user' : $type) . '/' . $id;
    }
}
