<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

/**
 * Two cheap, indexable flags on every narrative so the video feed and the audio
 * shelf never have to read every attachment of every post:
 *
 *   meydan_has_video = '1'  when any attachment is a video
 *   meydan_has_audio = '1'  when any attachment is audio
 *
 * A flag exists only while it is true. They follow `meydan_attachments`
 * automatically; the first request after deploy flags the posts written before
 * they existed.
 */
final class NarrativeMediaFlags
{
    public const VIDEO = 'meydan_has_video';
    public const AUDIO = 'meydan_has_audio';
    private const DONE_OPTION = 'meydan_media_flags_done';
    private const LOCK_OPTION = 'meydan_media_flags_lock';
    private const BACKFILL_BATCH = 500;
    /** Time one request may spend flagging old posts before handing over to the next. */
    private const BACKFILL_SECONDS = 20;

    public static function register(): void
    {
        foreach (['added_post_meta', 'updated_post_meta'] as $hook) {
            add_action($hook, [self::class, 'onMeta'], 10, 4);
        }
        add_action('deleted_post_meta', [self::class, 'onDeleted'], 10, 4);
        add_action('init', [self::class, 'continueBackfill'], 40);
    }

    /** @param mixed $metaId @param mixed $value */
    public static function onMeta($metaId, int $postId, string $key, $value): void
    {
        if ($key !== 'meydan_attachments' || get_post_type($postId) !== 'meydan_narrative') return;
        self::sync($postId, is_array($value) ? $value : null);
    }

    /** @param mixed $metaIds @param mixed $value */
    public static function onDeleted($metaIds, int $postId, string $key, $value): void
    {
        if ($key !== 'meydan_attachments' || get_post_type($postId) !== 'meydan_narrative') return;
        self::sync($postId, []);
    }

    /**
     * @param array<int,mixed>|null $attachments null reads the stored meta
     * @param int[] $ignoreMedia files to treat as already gone (a deletion in progress)
     */
    public static function sync(int $postId, ?array $attachments = null, array $ignoreMedia = []): void
    {
        $attachments ??= (array) get_post_meta($postId, 'meydan_attachments', true);
        $ids = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $mediaId = (int) ($attachment['media_id'] ?? $attachment['id'] ?? 0);
            if ($mediaId > 0 && !in_array($mediaId, $ignoreMedia, true)) $ids[$mediaId] = true;
        }
        $video = false;
        $audio = false;
        if ($ids) {
            global $wpdb;
            $marks = implode(',', array_fill(0, count($ids), '%d'));
            $mimes = $wpdb->get_col($wpdb->prepare(
                "SELECT post_mime_type FROM {$wpdb->posts} WHERE post_type='attachment' AND ID IN ({$marks})",
                ...array_keys($ids)
            )) ?: [];
            foreach ($mimes as $mime) {
                if (str_starts_with((string) $mime, 'video/')) $video = true;
                elseif (str_starts_with((string) $mime, 'audio/')) $audio = true;
            }
        }
        self::set($postId, self::VIDEO, $video);
        self::set($postId, self::AUDIO, $audio);
    }

    private static function set(int $postId, string $key, bool $on): void
    {
        $before = get_post_meta($postId, $key, true) === '1';
        if ($on) update_post_meta($postId, $key, '1');
        else delete_post_meta($postId, $key);
        // While the first backfill runs the table is built from scratch afterwards, so only live changes count.
        if ($key === self::AUDIO && $before !== $on && self::ready() && get_post_status($postId) === 'publish') {
            AudioProducers::narrativeAudio($postId, $on ? 1 : -1);
        }
    }

    /** True once every older narrative has been flagged. */
    public static function ready(): bool
    {
        return get_option(self::DONE_OPTION) === '1';
    }

    /** Flags one batch of narratives that have attachments; call until it returns 0. */
    public static function backfill(int $limit = self::BACKFILL_BATCH): int
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = 'meydan_attachments'
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = 'meydan_media_flags_checked'
             WHERE p.post_type = 'meydan_narrative' AND d.meta_id IS NULL
             ORDER BY p.ID DESC LIMIT %d",
            $limit
        )) ?: [];
        foreach ($ids as $id) {
            self::sync((int) $id);
            update_post_meta((int) $id, 'meydan_media_flags_checked', '1');
        }
        return count($ids);
    }

    /**
     * First request after deploy: flags every existing narrative in one go. One
     * request does the work (an atomic lock keeps parallel requests out); if a very
     * large site runs out of its time budget, the next request carries on.
     */
    public static function continueBackfill(): void
    {
        if (self::ready()) return;
        // add_option is atomic: only the request that creates the row proceeds.
        if (!add_option(self::LOCK_OPTION, (string) time(), '', false)) {
            if ((int) get_option(self::LOCK_OPTION) > time() - 120) return; // another request is on it
            update_option(self::LOCK_OPTION, (string) time(), false); // a crashed run left a stale lock
        }
        $deadline = microtime(true) + self::BACKFILL_SECONDS;
        $finished = false;
        try {
            if (function_exists('ignore_user_abort')) ignore_user_abort(true);
            while (microtime(true) < $deadline) {
                if (self::backfill() === 0) {
                    $finished = true;
                    break;
                }
            }
        } finally {
            if ($finished) update_option(self::DONE_OPTION, '1', false);
            delete_option(self::LOCK_OPTION);
        }
    }
}
