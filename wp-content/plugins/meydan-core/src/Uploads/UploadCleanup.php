<?php

declare(strict_types=1);

namespace Meydan\Core\Uploads;

/** Hourly sweep of abandoned upload sessions (one cheap indexed-by-status query per run). */
final class UploadCleanup
{
    private const HOOK = 'meydan_upload_cleanup';

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule'], 45);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::HOOK);
        }
    }

    public static function run(): void
    {
        try {
            (new ChunkedUploadService())->purgeExpired();
        } catch (\Throwable $error) {
            error_log('Meydan upload cleanup: ' . $error->getMessage());
        } finally {
            self::schedule();
        }
    }
}
