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
