<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Throwable;

/**
 * One-time, paced backfill of `meydan_narrative_tag` for narratives that
 * predate hashtag extraction (composed before the feature shipped, or
 * imported from Eitaa/Bale, which never ran it at all). Mirrors
 * `Storage\VideoPosterBackfill`'s pattern: a single-event cron chain that
 * reschedules itself after each small batch, so the whole catch-up never
 * shows up as one big spike — just quiet background work, a batch a minute,
 * until every existing narrative has been looked at once.
 */
final class HashtagBackfill
{
    private const HOOK = 'meydan_hashtag_backfill';
    private const STATE = 'meydan_hashtag_backfill_v1';
    private const LOCK = 'meydan_hashtag_backfill_lock';
    private const SCAN_SIZE = 50;
    private const INTERVAL = 60;

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule'], 40);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (get_option(self::STATE) === 'done' || wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_single_event(time() + self::INTERVAL, self::HOOK);
    }

    public static function run(): void
    {
        // add_option() is atomic in WordPress, so two overlapping cron ticks
        // (e.g. a slow batch plus a retriggered pseudo-cron) can't double-run.
        // A crashed worker's lock is recovered after 10 minutes — far longer
        // than one batch should ever take.
        $lockedAt = get_option(self::LOCK);
        if ($lockedAt !== false && (int) $lockedAt < time() - 600) {
            delete_option(self::LOCK);
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            self::schedule();
            return;
        }

        try {
            self::processNext();
        } catch (Throwable $error) {
            error_log('Meydan hashtag backfill: ' . $error->getMessage());
        } finally {
            delete_option(self::LOCK);
            self::schedule();
        }
    }

    public static function processNext(): void
    {
        global $wpdb;

        $state = get_option(self::STATE);
        if ($state === 'done') {
            return;
        }
        if (!is_array($state)) {
            $maxId = (int) $wpdb->get_var(
                "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'meydan_narrative' AND post_status = 'publish'"
            );
            $state = ['cursor' => 0, 'max_id' => $maxId];
            update_option(self::STATE, $state, false);
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'meydan_narrative' AND post_status = 'publish'
               AND ID > %d AND ID <= %d
             ORDER BY ID ASC
             LIMIT %d",
            (int) $state['cursor'],
            (int) $state['max_id'],
            self::SCAN_SIZE
        ));

        if (!$ids) {
            update_option(self::STATE, 'done', false);
            return;
        }

        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                self::reconcile($id);
            } catch (Throwable $error) {
                error_log('Meydan hashtag backfill for narrative ' . $id . ': ' . $error->getMessage());
            }
            $state['cursor'] = $id;
        }

        update_option(self::STATE, $state, false);
    }

    /**
     * Re-derives one narrative's tags from its body and writes them back only
     * when the result actually differs from what is already stored — most
     * narratives composed after the hashtag feature shipped already match, so
     * the common case here is a read-only no-op, not a write.
     */
    private static function reconcile(int $id): void
    {
        $post = get_post($id);
        if (!$post) {
            return;
        }

        $existing = wp_get_post_terms($id, 'meydan_narrative_tag', ['fields' => 'names']);
        if (is_wp_error($existing)) {
            $existing = [];
        }

        $wanted = Hashtags::merge($existing, (string) $post->post_content);

        $same = count($wanted) === count($existing) && !array_diff($wanted, $existing) && !array_diff($existing, $wanted);
        if ($same) {
            return;
        }

        wp_set_post_terms($id, $wanted, 'meydan_narrative_tag');
    }
}
