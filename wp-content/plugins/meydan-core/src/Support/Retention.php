<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

/**
 * Periodic sweep of append-only / expiring tables that otherwise grow without bound
 * (sessions, OTP challenges, idempotency replies, events, served history, audit log,
 * notifications, dead push endpoints).
 *
 * Runs every ten minutes from WP-Cron. Each statement deletes in LIMITed chunks inside a
 * small time budget so it never holds long locks or starves a request; whatever is left is
 * picked up by the next run. Windows are overridable through the `meydan_retention` option
 * (days per key) so they stay manageable without a deploy.
 */
final class Retention
{
    private const HOOK = 'meydan_retention_sweep';
    private const INTERVAL = 600;
    private const CHUNK = 5000;
    private const BUDGET_SECONDS = 20;

    /** Default retention in days. */
    private const DEFAULTS = [
        'sessions' => 7,        // grace after a session was revoked / its refresh token expired
        'auth_challenges' => 1,
        'events' => 90,
        'served_history' => 7,  // the timeline only reads the last 24h
        'audit_log' => 365,
        'notifications' => 180,
        'push_tokens' => 120,
    ];

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule'], 46);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::INTERVAL, self::HOOK);
        }
    }

    public static function run(): void
    {
        try {
            self::sweep();
        } catch (\Throwable $error) {
            error_log('Meydan retention: ' . $error->getMessage());
        } finally {
            self::schedule();
        }
    }

    /** @return array<string,int> rows deleted per job */
    public static function sweep(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'meydan_';
        $days = self::days();
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $cutoff = static fn(int $d): string => gmdate('Y-m-d H:i:s', time() - $d * DAY_IN_SECONDS);

        $jobs = [
            'sessions_revoked' => ["{$p}sessions", 'revoked_at < %s', $cutoff($days['sessions'])],
            'sessions_expired' => ["{$p}sessions", 'refresh_expires_at < %s', $cutoff($days['sessions'])],
            'auth_challenges' => ["{$p}auth_challenges", 'expires_at < %s', $cutoff($days['auth_challenges'])],
            'idempotency' => ["{$p}idempotency", 'expires_at < %s', gmdate('Y-m-d H:i:s')],
            'served_history' => ["{$p}served_history", 'served_at < %s', $cutoff($days['served_history'])],
            'events' => ["{$p}events", 'created_at < %s', $cutoff($days['events'])],
            'audit_log' => ["{$p}audit_log", 'created_at < %s', $cutoff($days['audit_log'])],
            'notifications' => ["{$p}notifications", 'created_at < %s', $cutoff($days['notifications'])],
            'web_push' => ["{$p}push_subscriptions", 'updated_at < %s', $cutoff($days['push_tokens'])],
            'native_push' => ["{$p}native_push_tokens", 'updated_at < %s', $cutoff($days['push_tokens'])],
        ];

        $deleted = [];
        foreach ($jobs as $name => [$table, $where, $value]) {
            $deleted[$name] = 0;
            while (microtime(true) < $deadline) {
                $n = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$where} LIMIT " . self::CHUNK, $value));
                $deleted[$name] += $n;
                if ($n < self::CHUNK) {
                    break;
                }
            }
        }

        return $deleted;
    }

    /** @return array<string,int> */
    private static function days(): array
    {
        $configured = (array) get_option('meydan_retention', []);
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = max(1, (int) ($configured[$key] ?? $default));
        }

        return $out;
    }
}
