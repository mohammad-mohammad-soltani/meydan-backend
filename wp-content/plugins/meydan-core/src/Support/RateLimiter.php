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
        $state = get_transient($key);
        $now = time();
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

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return Crypto::hash($ip);
    }
}
