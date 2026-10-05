<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

use Meydan\Core\Support\NarrativeMediaFlags;
use Meydan\Core\Support\Viewer;

/**
 * Builds a snapshot containing video narratives only.
 *
 * Fast path: one shared, cached pool of the newest videos (an index range scan on
 * `narrative_features(has_video, post_date_gmt)`), then a per-viewer weighted shuffle, so every
 * load of the video feed is a different order and videos this viewer has already been served
 * recently come last. The snapshot is still stored in the timeline session, so scrolling through
 * one load stays stable. The postmeta/batch scan below is kept as the fallback until the media
 * flags have been backfilled.
 */
final class VideoTimeline
{
    private const POOL_GROUP = 'meydan_pools';
    private const POOL_KEY = 'video_pool';
    private const POOL_SIZE = 2000;
    private const POOL_TTL = 60;
    private const SYNC_OPTION = 'meydan_video_features_synced';
    /** A video the viewer was served within this window goes to the back of the line. */
    private const SEEN_WINDOW_HOURS = 24;

    /** @return list<int> */
    public function ids(int $limit, ?Viewer $viewer = null, int $batchSize = 100): array
    {
        $limit = max(1, $limit);
        if (NarrativeMediaFlags::ready() && $this->featuresSynced()) {
            return $this->shuffled($this->pool(), $limit, $viewer);
        }
        return $this->legacyIds($limit, $batchSize);
    }

    /**
     * One-time mirror of the existing `meydan_has_video` flags into the indexed feature column, plus
     * feature rows for flagged videos that have none yet. Resumable: if the time budget runs out the
     * legacy path serves this request and the next one continues.
     */
    private function featuresSynced(): bool
    {
        if (get_option(self::SYNC_OPTION) === '1') return true;
        global $wpdb;
        $deadline = microtime(true) + 15;
        $features = $wpdb->prefix . 'meydan_narrative_features';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$features} f
             INNER JOIN {$wpdb->postmeta} v ON v.post_id = f.narrative_id AND v.meta_key = %s AND v.meta_value = '1'
             SET f.has_video = 1",
            NarrativeMediaFlags::VIDEO
        ));
        while (microtime(true) < $deadline) {
            $missing = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = '1'
                 LEFT JOIN {$features} f ON f.narrative_id = p.ID
                 WHERE p.post_type = 'meydan_narrative' AND p.post_status = 'publish' AND f.narrative_id IS NULL
                 LIMIT 200",
                NarrativeMediaFlags::VIDEO
            )) ?: [];
            if ($missing === []) {
                update_option(self::SYNC_OPTION, '1', false);
                return true;
            }
            NarrativeFeatureStore::upsertBatch(array_map('intval', $missing));
        }

        return false;
    }

    /** @return list<array{id:int,actor:string,age_h:float,score:float}> newest videos, shared by every viewer */
    private function pool(): array
    {
        $hit = wp_cache_get(self::POOL_KEY, self::POOL_GROUP);
        if (is_array($hit)) return $hit;
        // One rebuild per TTL: concurrent requests wait briefly for it instead of all querying.
        if (!wp_cache_add(self::POOL_KEY . '_lock', 1, self::POOL_GROUP, 10)) {
            for ($i = 0; $i < 10; $i++) {
                usleep(50000);
                $hit = wp_cache_get(self::POOL_KEY, self::POOL_GROUP);
                if (is_array($hit)) return $hit;
            }
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.narrative_id, f.actor_type, f.actor_id, f.post_date_gmt, f.views, f.likes, f.reposts, f.comments, f.shares
             FROM {$wpdb->prefix}meydan_narrative_features f
             INNER JOIN {$wpdb->posts} p ON p.ID = f.narrative_id AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
             WHERE f.has_video = 1
             ORDER BY f.post_date_gmt DESC
             LIMIT %d",
            self::POOL_SIZE
        ), ARRAY_A) ?: [];
        $now = time();
        $pool = [];
        foreach ($rows as $r) {
            $engagement = (int) $r['likes'] + 2 * (int) $r['reposts'] + 2.5 * (int) $r['comments'] + 1.5 * (int) $r['shares'];
            $pool[] = [
                'id' => (int) $r['narrative_id'],
                'actor' => $r['actor_type'] . ':' . $r['actor_id'],
                'age_h' => max(0.0, ($now - strtotime($r['post_date_gmt'] . ' UTC')) / 3600),
                // Engagement counts, with diminishing returns so one viral video cannot own the feed.
                'score' => 1.0 + log(1 + $engagement) + 0.3 * log(1 + (int) $r['views']),
            ];
        }
        wp_cache_set(self::POOL_KEY, $pool, self::POOL_GROUP, self::POOL_TTL);
        wp_cache_delete(self::POOL_KEY . '_lock', self::POOL_GROUP);
        return $pool;
    }

    /**
     * Weighted random order without replacement (Efraimidis–Spirakis): a video's chance of ranking
     * early grows with its freshness and engagement, but every load draws new random keys.
     * Videos this viewer saw in the last day come after all unseen ones; neighbours from the same
     * actor are spread apart.
     *
     * @param list<array{id:int,actor:string,age_h:float,score:float}> $pool
     * @return list<int>
     */
    private function shuffled(array $pool, int $limit, ?Viewer $viewer): array
    {
        if ($pool === []) return [];
        $seen = $viewer ? $this->recentlyServed($viewer) : [];

        $unseen = [];
        $old = [];
        foreach ($pool as $v) {
            $recency = 1 / (1 + $v['age_h'] / 72); // a three-day scale
            $weight = (0.25 + $recency) * $v['score'];
            $u = random_int(1, 1000000) / 1000000;
            $v['key'] = -log($u) / $weight; // smaller key = earlier
            if (isset($seen[$v['id']])) $old[] = $v; else $unseen[] = $v;
        }
        $order = static function (array $list): array {
            usort($list, static fn(array $a, array $b): int => $a['key'] <=> $b['key']);
            return $list;
        };
        $ordered = $this->spreadActors(array_merge($order($unseen), $order($old)));

        return array_slice(array_column($ordered, 'id'), 0, $limit);
    }

    /** @return array<int,true> */
    private function recentlyServed(Viewer $viewer): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT narrative_id FROM {$wpdb->prefix}meydan_served_history
             WHERE viewer_type = %s AND viewer_id = %s AND source = 'for_you:video'
               AND served_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
            $viewer->type,
            (string) $viewer->id,
            self::SEEN_WINDOW_HOURS
        )) ?: [];

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Keeps the weighted order but avoids the same actor twice in a row where another actor is available.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private function spreadActors(array $list): array
    {
        $out = [];
        $count = count($list);
        for ($i = 0; $i < $count; $i++) {
            if ($out !== [] && $list[$i]['actor'] === $out[array_key_last($out)]['actor']) {
                for ($j = $i + 1; $j < min($count, $i + 8); $j++) {
                    if ($list[$j]['actor'] !== $list[$i]['actor']) {
                        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
                        break;
                    }
                }
            }
            $out[] = $list[$i];
        }

        return $out;
    }

    /** @return list<int> */
    private function legacyIds(int $limit, int $batchSize = 100): array
    {
        $limit = max(1, $limit);
        $batchSize = min(250, max(1, $batchSize));
        $ids = [];
        $cursorDate = null;
        $cursorId = 0;
        global $wpdb;

        // Once every narrative carries its `meydan_has_video` flag the feed is one indexed read.
        if (NarrativeMediaFlags::ready()) {
            return array_values(array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = '1'
                 WHERE p.post_type='meydan_narrative' AND p.post_status='publish'
                 ORDER BY p.post_date_gmt DESC, p.ID DESC
                 LIMIT %d",
                NarrativeMediaFlags::VIDEO,
                $limit,
            )) ?: []));
        }

        while (count($ids) < $limit) {
            $cursorSql = '';
            $args = [];
            if ($cursorDate !== null) {
                $cursorSql = ' AND (post_date_gmt < %s OR (post_date_gmt = %s AND ID < %d))';
                $args = [$cursorDate, $cursorDate, $cursorId];
            }
            $args[] = $batchSize;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_date_gmt
                 FROM {$wpdb->posts}
                 WHERE post_type='meydan_narrative'
                   AND post_status='publish'{$cursorSql}
                 ORDER BY post_date_gmt DESC, ID DESC
                 LIMIT %d",
                ...$args,
            ), ARRAY_A) ?: [];
            $batch = array_values(array_map(static fn(array $row): int => (int) $row['ID'], $rows));
            if ($batch === []) break;

            update_meta_cache('post', $batch);
            $attachmentsByNarrative = [];
            $mediaIds = [];
            foreach ($batch as $id) {
                $attachments = get_post_meta($id, 'meydan_attachments', true);
                $attachmentsByNarrative[$id] = is_array($attachments) ? $attachments : [];
                foreach ($attachmentsByNarrative[$id] as $attachment) {
                    if (!is_array($attachment)) continue;
                    $mediaId = (int) ($attachment['media_id'] ?? $attachment['id'] ?? 0);
                    if ($mediaId > 0) $mediaIds[$mediaId] = true;
                }
            }

            $videoIds = [];
            if ($mediaIds !== []) {
                $placeholders = implode(',', array_fill(0, count($mediaIds), '%d'));
                $videoIds = array_fill_keys(array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type='attachment'
                       AND post_mime_type LIKE 'video/%%'
                       AND ID IN ({$placeholders})",
                    ...array_keys($mediaIds),
                )) ?: []), true);
            }

            foreach ($batch as $id) {
                if ($id > 0 && $this->hasVideo($attachmentsByNarrative[$id], $videoIds)) {
                    $ids[] = $id;
                    if (count($ids) >= $limit) break;
                }
            }

            if (count($batch) < $batchSize) break;
            $last = $rows[array_key_last($rows)];
            $cursorDate = (string) $last['post_date_gmt'];
            $cursorId = (int) $last['ID'];
        }

        return array_values(array_unique($ids));
    }

    /** @param array<int,mixed> $attachments @param array<int,true> $videoIds */
    private function hasVideo(array $attachments, array $videoIds): bool
    {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $mediaId = (int) ($attachment['media_id'] ?? $attachment['id'] ?? 0);
            if (isset($videoIds[$mediaId])) return true;
        }

        return false;
    }
}
