<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use Meydan\Core\Uploads\VideoProcessor;
use Throwable;

/** Gradually fill missing S3 stills without copying or rewriting video originals. */
final class VideoPosterBackfill
{
    private const HOOK = 'meydan_video_poster_backfill';
    private const STATE = 'meydan_video_poster_backfill_v1';
    private const LOCK = 'meydan_video_poster_backfill_lock';
    private const SCAN_SIZE = 20;
    private const INTERVAL = 60;
    private const MAX_ATTEMPTS = 3;

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule'], 40);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (strtolower(trim((string) getenv('MEDIA_STORAGE'))) !== 's3'
            || get_option(self::STATE) === 'done'
            || !VideoProcessor::available()
            || wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_single_event(time() + self::INTERVAL, self::HOOK);
    }

    public static function run(): void
    {
        if (strtolower(trim((string) getenv('MEDIA_STORAGE'))) !== 's3' || !VideoProcessor::available()) return;

        // add_option is atomic in WordPress. A stale lock can be recovered after
        // a crashed worker; the lock is otherwise held for just one video.
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
            error_log('Meydan video poster backfill: ' . $error->getMessage());
        } finally {
            delete_option(self::LOCK);
            self::schedule();
        }
    }

    public static function processNext(?MediaPipeline $pipeline = null): void
    {
        global $wpdb;

        $state = get_option(self::STATE);
        if ($state === 'done') return;
        if (!is_array($state)) {
            $maxId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_mime_type LIKE %s",
                'attachment', 'inherit', 'video/%'
            ));
            $state = ['cursor' => 0, 'max_id' => $maxId, 'failed' => 0];
            update_option(self::STATE, $state, false);
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_mime_type LIKE %s AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
            'attachment', 'inherit', 'video/%', (int) $state['cursor'], (int) $state['max_id'], self::SCAN_SIZE
        ));

        if (!$ids) {
            if ((int) $state['failed'] > 0) {
                $state['cursor'] = 0;
                $state['failed'] = 0;
                update_option(self::STATE, $state, false);
            } else {
                update_option(self::STATE, 'done', false);
            }
            return;
        }

        foreach ($ids as $id) {
            $id = (int) $id;
            if ((string) get_post_meta($id, '_meydan_storage_driver', true) === 's3'
                && (string) get_post_meta($id, 'meydan_poster_url', true) === ''
                && (int) get_post_meta($id, 'meydan_poster_backfill_attempts', true) < self::MAX_ATTEMPTS) {
                $key = (string) get_post_meta($id, '_meydan_storage_key', true);
                if ($key !== '' && VideoProcessor::isVideoPath($key)) {
                    // Only initialize S3 after finding a missing poster.
                    $pipeline ??= WordPressMediaHooks::pipeline();
                    try {
                        self::processAttachment($id, $key, $pipeline);
                    } catch (Throwable $error) {
                        $attempts = (int) get_post_meta($id, 'meydan_poster_backfill_attempts', true) + 1;
                        update_post_meta($id, 'meydan_poster_backfill_attempts', $attempts);
                        if ($attempts < self::MAX_ATTEMPTS) $state['failed']++;
                        error_log('Meydan video poster backfill for attachment ' . $id . ': ' . $error->getMessage());
                    }
                    $state['cursor'] = $id;
                    update_option(self::STATE, $state, false);
                    return; // At most one remote video per cron run.
                }
            }
            $state['cursor'] = $id;
        }
        update_option(self::STATE, $state, false);
    }

    public static function processAttachment(int $id, string $key, MediaPipeline $pipeline): void
    {
        $storage = $pipeline->storage();
        $posterKey = VideoDerivatives::posterKey($key);
        if ($storage->exists($posterKey)) {
            $poster = ['key' => $posterKey, 'url' => $storage->url($posterKey)];
        } else {
            $poster = VideoDerivatives::backfill($pipeline, $storage->url($key), $key);
            if ($poster === null) throw new \RuntimeException('ffmpeg could not extract a frame');
        }

        $derivatives = (array) get_post_meta($id, '_meydan_storage_derivative_keys', true);
        $derivatives['poster'] = $poster['key'];
        update_post_meta($id, '_meydan_storage_derivative_keys', $derivatives);
        update_post_meta($id, 'meydan_poster_url', $poster['url']);
        delete_post_meta($id, 'meydan_poster_backfill_attempts');
    }
}
