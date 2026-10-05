<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

use Throwable;

/**
 * Keeps NarrativeFeatureStore's cache warm by continuously cycling through
 * published narratives in small batches, so Timeline\FeatureHydrator almost
 * always hits a pure cache read and only rarely falls back to computing a
 * brand-new narrative inline.
 *
 * Structural fields (actor, city/province, media) essentially never change
 * after publish; this pass exists mainly to pick up stat drift beyond what
 * NarrativeFeatureStore::bumpStat() nudges in real time, and to pick up
 * media-reflection publishes, which happen on a separate narrative.
 *
 * Mirrors Storage\VideoPosterBackfill: a self-rescheduling single event with
 * an atomic option-based lock, rather than a persistent wp_schedule_event
 * tick that could pile up if a run overran its interval.
 */
final class NarrativeFeatureRefreshCron
{
    private const HOOK = 'meydan_narrative_feature_refresh';
    private const CURSOR_OPTION = 'meydan_narrative_feature_refresh_cursor';
    private const LOCK = 'meydan_narrative_feature_refresh_lock';
    private const BATCH_SIZE = 200;
    private const INTERVAL = 30;
    /** After a full pass over every narrative, rest this long before starting the next pass. */
    private const IDLE_INTERVAL = 600;

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule'], 40);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(int $delay = self::INTERVAL): void
    {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }
        wp_schedule_single_event(time() + $delay, self::HOOK);
    }

    public static function run(): void
    {
        // add_option is atomic in WordPress. A stale lock recovers after a
        // crashed worker; otherwise it's held for one batch only.
        $lockedAt = get_option(self::LOCK);
        if ($lockedAt !== false && (int) $lockedAt < time() - 300) {
            delete_option(self::LOCK);
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            self::schedule();
            return;
        }

        $wrapped = false;
        try {
            $wrapped = self::processNextBatch();
        } catch (Throwable $error) {
            error_log('Meydan narrative feature refresh: ' . $error->getMessage());
        } finally {
            delete_option(self::LOCK);
            self::schedule($wrapped ? self::IDLE_INTERVAL : self::INTERVAL);
        }
    }

    /** @return bool true when this call reached the end of the table (a full pass is complete) */
    public static function processNextBatch(): bool
    {
        global $wpdb;
        $cursor = (int) get_option(self::CURSOR_OPTION, 0);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='meydan_narrative' AND post_status='publish' AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            $cursor,
            self::BATCH_SIZE
        ));

        if (!$ids) {
            // Wrapped the whole table — restart from the top so stat drift and
            // reflection publishes keep getting picked up continuously.
            update_option(self::CURSOR_OPTION, 0, false);
            return true;
        }

        NarrativeFeatureStore::upsertBatch(array_map('intval', $ids));
        update_option(self::CURSOR_OPTION, (int) end($ids), false);
        return false;
    }
}
