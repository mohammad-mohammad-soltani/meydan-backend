<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Viewer;

/**
 * Keeps one ranked timeline stable while the viewer scrolls through it.
 *
 * A session stores only narrative ids. Cursors are signed and point to a
 * position inside that immutable snapshot, so later pages cannot be affected
 * by ranking changes, served-history writes, or exploration randomness.
 */
final class TimelineSession
{
    private const VERSION = 1;
    private const TTL = 7200; // Two hours of idle time; refreshed while the viewer keeps scrolling.
    private const MAX_AGE = 21600; // Hard cap: a snapshot is never kept alive longer than six hours.
    private const CACHE_GROUP = 'meydan_timeline_sessions';
    private const TRANSIENT_PREFIX = 'meydan_timeline_session_';

    /**
     * @param array<int,int|string> $ids
     * @return array{ids:array<int,int>,next_cursor:?string}
     */
    public static function start(
        Viewer $viewer,
        string $mode,
        string $filter,
        array $ids,
        int $limit,
    ): array {
        $snapshot = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));

        $sessionId = Crypto::randomToken(18, 'tls_');
        $session = [
            'viewer' => self::viewerKey($viewer),
            'mode' => $mode,
            'filter' => $filter,
            'ids' => pack('V*', ...$snapshot), // 4 bytes per id instead of a PHP array (≈10x smaller in the cache)
            'created_at' => time(),
            'touched_at' => time(),
        ];

        self::store($sessionId, $session);

        return self::page($sessionId, $session, 0, $limit);
    }

    /**
     * @return array{ids:array<int,int>,next_cursor:?string}|null
     */
    public static function resume(
        Viewer $viewer,
        string $mode,
        string $filter,
        string $cursor,
        int $limit,
    ): ?array {
        $decoded = self::decodeCursor($cursor);
        if ($decoded === null) {
            return null;
        }

        if ($decoded['mode'] !== $mode || $decoded['filter'] !== $filter) {
            return null;
        }

        $session = self::load($decoded['session_id']);
        if (!is_array($session)) {
            return null;
        }

        if (
            ($session['viewer'] ?? null) !== self::viewerKey($viewer)
            || ($session['mode'] ?? null) !== $mode
            || ($session['filter'] ?? null) !== $filter
            || !isset($session['ids'])
            || (!is_array($session['ids']) && !is_string($session['ids']))
            || time() - (int) ($session['created_at'] ?? 0) > self::MAX_AGE
        ) {
            return null;
        }

        // Sliding expiration: an actively scrolling viewer keeps the session, but the blob is only
        // rewritten once half of the idle TTL has passed instead of on every page.
        if (time() - (int) ($session['touched_at'] ?? 0) > self::TTL / 2) {
            $session['touched_at'] = time();
            self::store($decoded['session_id'], $session);
        }

        return self::page(
            $decoded['session_id'],
            $session,
            $decoded['position'],
            $limit,
        );
    }

    /**
     * @param array{ids:array<int,int>} $session
     * @return array{ids:array<int,int>,next_cursor:?string}
     */
    private static function page(string $sessionId, array $session, int $position, int $limit): array
    {
        $ids = self::decodeIds($session['ids']);
        $position = max(0, $position);
        $limit = max(1, $limit);
        $pageIds = array_slice($ids, $position, $limit);
        $nextPosition = $position + count($pageIds);

        return [
            'ids' => $pageIds,
            'next_cursor' => $nextPosition < count($ids)
                ? self::encodeCursor(
                    $sessionId,
                    $nextPosition,
                    (string) ($session['mode'] ?? 'for_you'),
                    (string) ($session['filter'] ?? 'all'),
                )
                : null,
        ];
    }

    /** @return array<int,int> */
    private static function decodeIds(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('intval', $raw)); // sessions written before ids were packed
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $ids = unpack('V*', $raw);

        return $ids === false ? [] : array_values($ids);
    }

    private static function viewerKey(Viewer $viewer): string
    {
        // Guest SSR and browser requests can have different guest-cookie ids.
        // The cursor itself is unguessable and signed, so binding guest sessions
        // to the guest class keeps SSR -> client pagination continuous while
        // authenticated timelines remain strictly account-bound.
        return $viewer->isAuthenticated() ? 'user:' . $viewer->id : 'guest';
    }

    /** @param array<string,mixed> $session */
    private static function store(string $sessionId, array $session): void
    {
        $cacheKey = self::cacheKey($sessionId);
        wp_cache_set($cacheKey, $session, self::CACHE_GROUP, self::TTL);
        // With a persistent object cache (Redis) the group entry above already survives requests;
        // a second transient copy would only double the memory per session.
        if (!wp_using_ext_object_cache()) {
            set_transient(self::TRANSIENT_PREFIX . $cacheKey, $session, self::TTL);
        }
    }

    /** @return array<string,mixed>|false */
    private static function load(string $sessionId): array|false
    {
        $cacheKey = self::cacheKey($sessionId);
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        if (wp_using_ext_object_cache()) {
            return false;
        }

        $stored = get_transient(self::TRANSIENT_PREFIX . $cacheKey);
        if (!is_array($stored)) {
            return false;
        }

        wp_cache_set($cacheKey, $stored, self::CACHE_GROUP, self::TTL);
        return $stored;
    }

    private static function cacheKey(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private static function encodeCursor(
        string $sessionId,
        int $position,
        string $mode,
        string $filter,
    ): string {
        $body = Cursor::encode([
            'v' => self::VERSION,
            's' => $sessionId,
            'p' => $position,
            'm' => $mode,
            'f' => $filter,
        ]);
        $signature = Crypto::hash('timeline_cursor|' . $body);

        return $body . '.' . $signature;
    }

    /**
     * @return array{session_id:string,position:int,mode:string,filter:string}|null
     */
    private static function decodeCursor(string $cursor): ?array
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = Crypto::hash('timeline_cursor|' . $body);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = Cursor::decode($body);
        if (
            (int) ($payload['v'] ?? 0) !== self::VERSION
            || !is_string($payload['s'] ?? null)
            || !is_numeric($payload['p'] ?? null)
            || !is_string($payload['m'] ?? null)
            || !is_string($payload['f'] ?? null)
        ) {
            return null;
        }

        $position = (int) $payload['p'];
        if ($position < 0) {
            return null;
        }

        return [
            'session_id' => $payload['s'],
            'position' => $position,
            'mode' => $payload['m'],
            'filter' => $payload['f'],
        ];
    }
}
