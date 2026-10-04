<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\EntityKinds;

/**
 * Who published audio, and how much — kept as one small table so the «آوا» shelves
 * for people and squares are a single indexed read, with no joins over postmeta.
 *
 * One row per actor:
 *   kind           face (speaker or official account) | square | user | other
 *   post_audios    published posts by this actor that carry audio (`meydan_has_audio`)
 *   content_audios published audio content produced by this actor
 *   total          post_audios + content_audios, the sort key
 *
 * Counters move by +1 / -1 on the events that change them, so a write costs one
 * upsert. A daily rebuild (and the very first run) repairs any drift.
 */
final class AudioProducers
{
    private const VERSION = '1';
    private const DB_OPTION = 'meydan_audio_producers_db';
    private const REV_OPTION = 'meydan_audio_producers_rev';
    private const LOCK_OPTION = 'meydan_audio_producers_lock';
    private const CRON = 'meydan_audio_producers_rebuild';
    private const CHUNK = 1000;

    /** @var array<int,string> content posts waiting to be recounted at shutdown => producer counted before */
    private static array $contentQueue = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meydan_audio_producers';
    }

    public static function register(): void
    {
        add_action('init', [self::class, 'maybeInstall'], 5);
        add_action('init', [self::class, 'maybeBuild'], 45);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('before_delete_post', [self::class, 'onDelete'], 10, 1);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'onPostMeta'], 10, 4);
        }
        // The account type derives from the user's role, so role changes decide who counts as a «face».
        foreach (['set_user_role', 'add_user_role', 'remove_user_role'] as $hook) {
            add_action($hook, [self::class, 'onUserRole'], 10, 1);
        }
        add_action('delete_user', [self::class, 'onUserDeleted'], 10, 1);
        // wp_delete_attachment() announces itself with its own hook, not before_delete_post.
        add_action('delete_attachment', [self::class, 'onAttachmentDeleted'], 10, 1);
        add_action(self::CRON, [self::class, 'rebuild']);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 3600, 'daily', self::CRON);
    }

    public static function maybeInstall(): void
    {
        if ((string) get_option(self::DB_OPTION, '') === self::VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            actor_type VARCHAR(20) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            post_audios INT UNSIGNED NOT NULL DEFAULT 0,
            content_audios INT UNSIGNED NOT NULL DEFAULT 0,
            total INT UNSIGNED NOT NULL DEFAULT 0,
            last_at DATETIME NULL,
            rev VARCHAR(20) NOT NULL DEFAULT '',
            PRIMARY KEY  (actor_type, actor_id),
            KEY kind_total (kind, total, last_at)
        ) {$wpdb->get_charset_collate()};");
        update_option(self::DB_OPTION, self::VERSION, false);
    }

    /** True once the table has been filled from existing data. */
    public static function ready(): bool
    {
        return (string) get_option(self::REV_OPTION, '') !== '';
    }

    // ---- reading ---------------------------------------------------------------------------

    /**
     * The busiest publishers of one kind. A single indexed range read.
     *
     * @return array<int,array<string,mixed>> rows of ['actor' => payload, 'audios' => int]
     */
    public static function top(string $kind, int $limit, int $offset = 0): array
    {
        global $wpdb;
        $out = [];
        $skipped = 0;
        $cursor = 0;
        // Accounts that were deleted, hidden or banned are skipped, so read on until the page is full
        // (a few rounds at most: stale rows are rare and the nightly rebuild removes them).
        for ($round = 0; $round < 4 && count($out) < $limit; $round++) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT actor_type, actor_id, total FROM ' . self::table() . ' WHERE kind = %s AND total > 0 ORDER BY total DESC, last_at DESC, actor_id DESC LIMIT %d OFFSET %d',
                $kind,
                $limit + 5,
                $cursor
            )) ?: [];
            foreach ($rows as $row) {
                $cursor++;
                $actor = Actor::parse((string) $row->actor_type, (int) $row->actor_id);
                if (!$actor) continue;
                if ($skipped < $offset) { $skipped++; continue; }
                $out[] = ['actor' => $actor, 'audios' => (int) $row->total];
                if (count($out) >= $limit) break 2;
            }
            if (count($rows) < $limit + 5) break;
        }
        return $out;
    }

    // ---- events ----------------------------------------------------------------------------

    /** A narrative gained (+1) or lost (-1) a published audio file. */
    public static function narrativeAudio(int $narrativeId, int $delta): void
    {
        $type = (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true);
        $id = (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true);
        if ($type === '' || $id <= 0 || $delta === 0) return;
        self::bump($type, $id, $delta);
    }

    public static function onTransition(string $new, string $old, $post): void
    {
        if (!$post instanceof \WP_Post || $new === $old) return;
        if ($post->post_type === 'meydan_narrative') {
            if (get_post_meta($post->ID, NarrativeMediaFlags::AUDIO, true) !== '1') return;
            if ($new === 'publish') self::narrativeAudio((int) $post->ID, 1);
            elseif ($old === 'publish') self::narrativeAudio((int) $post->ID, -1);
        } elseif ($post->post_type === 'meydan_content') {
            self::queueContent((int) $post->ID);
        }
    }

    public static function onDelete(int $postId): void
    {
        $post = get_post($postId);
        if (!$post) return;
        if ($post->post_type === 'meydan_narrative') {
            if ($post->post_status === 'publish' && get_post_meta($postId, NarrativeMediaFlags::AUDIO, true) === '1') {
                self::narrativeAudio($postId, -1);
            }
        } elseif ($post->post_type === 'meydan_content') {
            // Counted again at shutdown, when this row is gone; the producer is remembered now.
            self::queueContent($postId);
        } elseif (($kind = EntityKinds::kindForPostType($post->post_type)) !== null) {
            self::forget($kind, $postId);
        }
    }

    public static function onAttachmentDeleted(int $mediaId): void
    {
        self::attachmentGone($mediaId, (string) get_post_mime_type($mediaId));
    }

    /** A deleted account takes its counters with it. */
    public static function onUserDeleted(int $userId): void
    {
        self::forget('user', $userId);
    }

    private static function forget(string $type, int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['actor_type' => $type, 'actor_id' => $id]);
        delete_transient('meydan_hub_audio');
    }

    /**
     * An audio or video file was deleted: every post that carried it is re-flagged, so a
     * missing file never leaves a flag (and a count) behind. Rare event, and only for media files.
     */
    private static function attachmentGone(int $mediaId, string $mime): void
    {
        if (!str_starts_with($mime, 'audio/') && !str_starts_with($mime, 'video/')) return;
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'meydan_narrative'
             WHERE m.meta_key = 'meydan_attachments' AND m.meta_value LIKE %s",
            '%' . $wpdb->esc_like('"media_id";i:' . $mediaId . ';') . '%'
        )) ?: []);
        // The row still exists while this hook runs; flags are read from the other attachments only.
        foreach ($ids as $id) NarrativeMediaFlags::sync($id, null, [$mediaId]);
    }

    /** @param mixed $metaId @param mixed $value */
    public static function onPostMeta($metaId, int $postId, string $key, $value): void
    {
        if (!in_array($key, ['meydan_format', 'meydan_producer_actor_type', 'meydan_producer_actor_id'], true)) return;
        if (get_post_type($postId) === 'meydan_content') self::queueContent($postId);
    }

    public static function onUserRole(int $userId): void
    {
        // Roles are written before the cached account type, so resolve from the roles directly.
        global $wpdb;
        $wpdb->update(self::table(), ['kind' => self::kindFor('user', $userId)], ['actor_type' => 'user', 'actor_id' => $userId]);
    }

    private static function queueContent(int $postId): void
    {
        if (!self::$contentQueue) add_action('shutdown', [self::class, 'flushContent']);
        // Read the producer counted so far now: a deleted post has no meta left by shutdown.
        self::$contentQueue[$postId] ??= (string) get_post_meta($postId, 'meydan_audio_counted_for', true);
    }

    /** Recounts the producers touched by content edits, once per request. */
    public static function flushContent(): void
    {
        $queue = self::$contentQueue;
        self::$contentQueue = [];
        $touched = [];
        foreach ($queue as $postId => $previous) {
            if ($previous !== '') $touched[$previous] = true;
            $current = '';
            $post = get_post($postId);
            $type = (string) get_post_meta($postId, 'meydan_producer_actor_type', true);
            $id = (int) get_post_meta($postId, 'meydan_producer_actor_id', true);
            $isAudio = get_post_meta($postId, 'meydan_format', true) === 'audio' && $post && $post->post_status === 'publish';
            if ($isAudio && $type !== '' && $id > 0) {
                $current = $type . ':' . $id;
                $touched[$current] = true;
            }
            if ($post) update_post_meta($postId, 'meydan_audio_counted_for', $current);
        }
        foreach (array_keys($touched) as $key) {
            [$type, $id] = explode(':', $key);
            self::setContentCount($type, (int) $id, self::contentCount($type, (int) $id));
        }
    }

    // ---- writes ----------------------------------------------------------------------------

    public static function kindFor(string $type, int $id): string
    {
        if ($type === 'square') return 'square';
        if ($type === 'user') return in_array(Actor::accountType($id), ['speaker', 'official'], true) ? 'face' : 'user';
        return 'other';
    }

    private static function bump(string $type, int $id, int $delta): void
    {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        // One atomic upsert; counters never go below zero. MySQL evaluates the assignments in order,
        // so `total` already sees the new `post_audios`.
        delete_transient('meydan_hub_audio');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (actor_type, actor_id, kind, post_audios, content_audios, total, last_at)
             VALUES (%s, %d, %s, %d, 0, %d, %s)
             ON DUPLICATE KEY UPDATE
               post_audios = GREATEST(0, CAST(post_audios AS SIGNED) + %d),
               total = post_audios + content_audios,
               last_at = IF(%d > 0, VALUES(last_at), last_at)",
            $type, $id, self::kindFor($type, $id), max(0, $delta), max(0, $delta), $now, $delta, $delta
        ));
    }

    private static function setContentCount(string $type, int $id, int $count): void
    {
        global $wpdb;
        $table = self::table();
        delete_transient('meydan_hub_audio');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (actor_type, actor_id, kind, post_audios, content_audios, total, last_at)
             VALUES (%s, %d, %s, 0, %d, %d, %s)
             ON DUPLICATE KEY UPDATE content_audios = VALUES(content_audios), total = post_audios + VALUES(content_audios)",
            $type, $id, self::kindFor($type, $id), $count, $count, gmdate('Y-m-d H:i:s')
        ));
    }

    /** Published audio content of one producer. Content is a small, curated set, so this stays cheap. */
    private static function contentCount(string $type, int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} i
             INNER JOIN {$wpdb->postmeta} t ON t.post_id = i.post_id AND t.meta_key = 'meydan_producer_actor_type' AND t.meta_value = %s
             INNER JOIN {$wpdb->postmeta} f ON f.post_id = i.post_id AND f.meta_key = 'meydan_format' AND f.meta_value = 'audio'
             INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id AND p.post_type = 'meydan_content' AND p.post_status = 'publish'
             WHERE i.meta_key = 'meydan_producer_actor_id' AND i.meta_value = %s",
            $type,
            (string) $id
        ));
    }

    // ---- building and repair ---------------------------------------------------------------

    public static function maybeBuild(): void
    {
        if (self::ready() || !NarrativeMediaFlags::ready()) return;
        self::rebuild();
    }

    /**
     * Recounts everything from the flags, in id-ordered chunks (no joins over postmeta):
     * the first run after deploy, and a daily repair.
     */
    public static function rebuild(): void
    {
        if (!NarrativeMediaFlags::ready()) return;
        global $wpdb;
        if (!add_option(self::LOCK_OPTION, (string) time(), '', false)) {
            if ((int) get_option(self::LOCK_OPTION) > time() - 600) return;
            update_option(self::LOCK_OPTION, (string) time(), false);
        }
        try {
            if (function_exists('ignore_user_abort')) ignore_user_abort(true);
            $posts = [];
            $content = [];

            // Narratives: walk the flagged ids in order.
            $cursor = 0;
            do {
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT h.post_id FROM {$wpdb->postmeta} h
                     INNER JOIN {$wpdb->posts} p ON p.ID = h.post_id AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
                     WHERE h.meta_key = %s AND h.meta_value = '1' AND h.post_id > %d
                     ORDER BY h.post_id ASC LIMIT %d",
                    NarrativeMediaFlags::AUDIO, $cursor, self::CHUNK
                )) ?: []);
                if ($ids) update_meta_cache('post', $ids);
                foreach ($ids as $id) {
                    $type = (string) get_post_meta($id, 'meydan_author_actor_type', true);
                    $actor = (int) get_post_meta($id, 'meydan_author_actor_id', true);
                    if ($type !== '' && $actor > 0) $posts[$type . ':' . $actor] = ($posts[$type . ':' . $actor] ?? 0) + 1;
                    $cursor = $id;
                }
            } while (count($ids) === self::CHUNK);

            // Audio content, same way.
            $cursor = 0;
            do {
                $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT f.post_id FROM {$wpdb->postmeta} f
                     INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id AND p.post_type = 'meydan_content' AND p.post_status = 'publish'
                     WHERE f.meta_key = 'meydan_format' AND f.meta_value = 'audio' AND f.post_id > %d
                     ORDER BY f.post_id ASC LIMIT %d",
                    $cursor, self::CHUNK
                )) ?: []);
                if ($ids) update_meta_cache('post', $ids);
                foreach ($ids as $id) {
                    $type = (string) get_post_meta($id, 'meydan_producer_actor_type', true);
                    $actor = (int) get_post_meta($id, 'meydan_producer_actor_id', true);
                    $key = $type !== '' && $actor > 0 ? $type . ':' . $actor : '';
                    if ($key !== '') $content[$key] = ($content[$key] ?? 0) + 1;
                    update_post_meta($id, 'meydan_audio_counted_for', $key);
                    $cursor = $id;
                }
            } while (count($ids) === self::CHUNK);

            $rev = (string) time();
            $now = gmdate('Y-m-d H:i:s');
            $table = self::table();
            foreach (array_chunk(array_unique(array_merge(array_keys($posts), array_keys($content))), 200) as $keys) {
                $values = [];
                foreach ($keys as $key) {
                    [$type, $id] = explode(':', $key);
                    $p = $posts[$key] ?? 0;
                    $c = $content[$key] ?? 0;
                    $values[] = $wpdb->prepare('(%s, %d, %s, %d, %d, %d, %s, %s)', $type, (int) $id, self::kindFor($type, (int) $id), $p, $c, $p + $c, $now, $rev);
                }
                $wpdb->query("INSERT INTO {$table} (actor_type, actor_id, kind, post_audios, content_audios, total, last_at, rev) VALUES " . implode(',', $values)
                    . ' ON DUPLICATE KEY UPDATE kind = VALUES(kind), post_audios = VALUES(post_audios), content_audios = VALUES(content_audios), total = VALUES(total), rev = VALUES(rev)');
            }
            // Anyone not seen this time has no audio any more.
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE rev <> %s", $rev));
            update_option(self::REV_OPTION, $rev, false);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
