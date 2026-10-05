<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

/**
 * Pre-scored, viewer-independent ranking features for narratives.
 *
 * FeatureHydrator used to recompute everything from scratch per candidate on
 * every timeline request — get_post, several get_post_meta calls, Stats's 4
 * queries, a media-reflection count — around 10 queries per candidate, up to
 * ~12,000 queries for a single "for you" refresh. Every one of those values is
 * the same for every viewer, so it belongs in a cache table computed once
 * (continuously, by NarrativeFeatureRefreshCron) and read back with a single
 * `WHERE narrative_id IN (...)` regardless of how large the candidate pool is.
 * Only genuinely viewer-specific signals (affinity, recently-served) stay out
 * of this table and get batched separately by the caller.
 */
final class NarrativeFeatureStore
{
    private const TABLE = 'meydan_narrative_features';

    /**
     * @param int[] $narrativeIds
     * @return array<int,array<string,mixed>> keyed by narrative_id
     */
    public static function get(array $narrativeIds): array
    {
        $ids = self::sanitizeIds($narrativeIds);
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE narrative_id IN ({$marks})", ...$ids),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['narrative_id']] = $row;
        }
        return $out;
    }

    /**
     * Returns one feature row per requested id, computing (and persisting) any
     * that aren't cached yet — e.g. a narrative published seconds ago that the
     * refresh cron hasn't reached. This is the only path that can still run a
     * per-request compute, and it is itself fully batched: one pass over the
     * missing ids, never one query per id.
     *
     * @param int[] $narrativeIds
     * @return array<int,array<string,mixed>> keyed by narrative_id
     */
    public static function ensureFresh(array $narrativeIds): array
    {
        $ids = self::sanitizeIds($narrativeIds);
        if (!$ids) {
            return [];
        }
        $cached = self::get($ids);
        $missing = array_values(array_diff($ids, array_keys($cached)));
        if ($missing) {
            self::upsertBatch($missing);
            $cached += self::get($missing);
        }
        return $cached;
    }

    /**
     * Batch-computes and upserts feature rows for the given narrative ids.
     * Mirrors Feed\CandidateHydrator's batching style: a handful of
     * `IN (...)` queries total, never one query per narrative.
     *
     * @param int[] $narrativeIds
     */
    public static function upsertBatch(array $narrativeIds): int
    {
        $ids = self::sanitizeIds($narrativeIds);
        if (!$ids) {
            return 0;
        }
        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_date_gmt FROM {$wpdb->posts}
             WHERE ID IN ({$marks}) AND post_type='meydan_narrative' AND post_status='publish'",
            ...$ids
        ), ARRAY_A) ?: [];
        if (!$posts) {
            return 0;
        }
        $liveIds = array_map(static fn(array $p): int => (int) $p['ID'], $posts);
        $liveMarks = implode(',', array_fill(0, count($liveIds), '%d'));

        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ({$liveMarks}) AND meta_key IN (
                'meydan_author_actor_type','meydan_author_actor_id',
                'meydan_city_id','meydan_province_id',
                'meydan_attachments','meydan_initiative_id','meydan_has_video'
             )",
            ...$liveIds
        ), ARRAY_A) ?: [];
        $byMeta = [];
        foreach ($meta as $row) {
            $byMeta[(int) $row['post_id']][$row['meta_key']] = $row['meta_value'];
        }

        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT narrative_id, views, likes, comments, reposts, shares
             FROM {$wpdb->prefix}meydan_narrative_stats WHERE narrative_id IN ({$liveMarks})",
            ...$liveIds
        ), ARRAY_A) ?: [];
        $byStats = [];
        foreach ($stats as $row) {
            $byStats[(int) $row['narrative_id']] = $row;
        }
        // narrative_stats can momentarily lag the source-of-truth
        // interactions/comments tables (see Stats::narrative()'s comment on
        // stale aggregates). That distinction matters for a publicly displayed
        // count; for a ranking signal, being off by however long until the
        // next cron pass or bumpStat() call is fine.

        $reflected = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT narrative_id FROM {$wpdb->prefix}meydan_media_reflections
             WHERE status='published' AND narrative_id IN ({$liveMarks})",
            ...$liveIds
        )) ?: [];
        $reflectedIds = array_fill_keys(array_map('intval', $reflected), true);

        $now = current_time('mysql', true);
        $table = $wpdb->prefix . self::TABLE;
        foreach ($posts as $post) {
            $id = (int) $post['ID'];
            $m = $byMeta[$id] ?? [];
            $s = $byStats[$id] ?? [];
            $attachments = maybe_unserialize($m['meydan_attachments'] ?? []);
            $hasMedia = is_array($attachments) && $attachments !== [];

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (narrative_id, actor_type, actor_id, city_id, province_id, has_media,
                     initiative_boost, media_reflection_boost, has_video, post_date_gmt,
                     views, likes, reposts, comments, shares, computed_at)
                 VALUES (%d,%s,%d,%d,%d,%d,%d,%d,%d,%s,%d,%d,%d,%d,%d,%s)
                 ON DUPLICATE KEY UPDATE
                    actor_type=VALUES(actor_type), actor_id=VALUES(actor_id),
                    city_id=VALUES(city_id), province_id=VALUES(province_id),
                    has_media=VALUES(has_media), initiative_boost=VALUES(initiative_boost),
                    media_reflection_boost=VALUES(media_reflection_boost), has_video=VALUES(has_video),
                    post_date_gmt=VALUES(post_date_gmt),
                    views=VALUES(views), likes=VALUES(likes), reposts=VALUES(reposts),
                    comments=VALUES(comments), shares=VALUES(shares), computed_at=VALUES(computed_at)",
                $id,
                (string) ($m['meydan_author_actor_type'] ?? 'user'),
                (int) ($m['meydan_author_actor_id'] ?? 0),
                (int) ($m['meydan_city_id'] ?? 0),
                (int) ($m['meydan_province_id'] ?? 0),
                $hasMedia ? 1 : 0,
                (int) ($m['meydan_initiative_id'] ?? 0) > 0 ? 1 : 0,
                isset($reflectedIds[$id]) ? 1 : 0,
                ($m['meydan_has_video'] ?? '') === '1' ? 1 : 0,
                (string) $post['post_date_gmt'],
                (int) ($s['views'] ?? 0),
                (int) ($s['likes'] ?? 0),
                (int) ($s['reposts'] ?? 0),
                (int) ($s['comments'] ?? 0),
                (int) ($s['shares'] ?? 0),
                $now,
            ));
        }

        return count($posts);
    }

    /**
     * Cheap incremental nudge so a fresh like/comment/repost/share/view shows
     * up in ranking before the next cron pass reaches this narrative. Only
     * touches an existing row — a narrative not yet cached picks up its stats
     * from ensureFresh()/the cron instead of being half-created here.
     */
    public static function bumpStat(int $narrativeId, string $field, int $delta): void
    {
        if ($narrativeId <= 0 || $delta === 0) {
            return;
        }
        if (!in_array($field, ['views', 'likes', 'reposts', 'comments', 'shares'], true)) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET {$field} = GREATEST(0, {$field} + %d) WHERE narrative_id = %d",
            $delta,
            $narrativeId
        ));
    }

    /** @param int[] $ids @return int[] */
    private static function sanitizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
    }
}
