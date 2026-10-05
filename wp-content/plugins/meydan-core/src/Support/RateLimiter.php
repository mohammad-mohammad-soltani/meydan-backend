<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class RateLimiter
{
    /**
     * @return array{allowed:bool,retry_after:int}
     */
    public static function hit(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $key = 'meydan_rl_' . substr(Crypto::hash($bucket . '|' . $identity), 0, 40);
        $now = time();
        if (wp_using_ext_object_cache()) {
            return self::hitCached($key, $limit, max(1, $windowSeconds), $now);
        }
        $state = get_transient($key);
        if (!is_array($state) || ($state['reset'] ?? 0) <= $now) {
            $state = ['count' => 0, 'reset' => $now + $windowSeconds];
        }
        $state['count']++;
        $ttl = max(1, (int) $state['reset'] - $now);
        set_transient($key, $state, $ttl);
        return [
            'allowed' => (int) $state['count'] <= $limit,
            'retry_after' => $ttl,
        ];
    }

    /**
     * Atomic counter for a persistent object cache: `incr` cannot lose concurrent hits the way the
     * read-modify-write transient can, and it never touches wp_options. Both keys expire with the window.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    private static function hitCached(string $key, int $limit, int $window, int $now): array
    {
        $group = 'meydan_rl';
        $count = wp_cache_incr($key, 1, $group);
        if ($count === false) {
            if (wp_cache_add($key, 1, $group, $window)) {
                wp_cache_set($key . '_r', $now + $window, $group, $window);
                $count = 1;
            } else {
                $count = wp_cache_incr($key, 1, $group);
            }
        }
        $reset = (int) wp_cache_get($key . '_r', $group);
        $retry = $reset > $now ? $reset - $now : $window;

        return ['allowed' => (int) $count <= $limit, 'retry_after' => max(1, $retry)];
    }

    public static function ip(): string
    {
        return Crypto::hash(self::clientIp());
    }

    /**
     * Real client address behind reverse proxies. `X-Forwarded-For` is honoured only
     * when the TCP peer is itself a trusted proxy (private/loopback ranges, plus any
     * `MEYDAN_TRUSTED_PROXIES` entries, comma-separated IPs); the chain is walked from
     * the right so a client-supplied prefix can never choose its own address.
     */
    public static function clientIp(): string
    {
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($peer === '' || !filter_var($peer, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        $extra = array_filter(array_map('trim', explode(',', (string) (getenv('MEYDAN_TRUSTED_PROXIES') ?: ''))));
        $trusted = static fn(string $ip): bool => in_array($ip, $extra, true)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (!$trusted($peer)) {
            return $peer;
        }
        $chain = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                break;
            }
            if (!$trusted($candidate)) {
                return $candidate;
            }
        }
        return $peer;
    }
}
