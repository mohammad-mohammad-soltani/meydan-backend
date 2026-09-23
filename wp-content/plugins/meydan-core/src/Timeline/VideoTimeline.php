<?php

declare(strict_types=1);

namespace Meydan\Core\Timeline;

/** Builds a stable, all-ages snapshot containing video narratives only. */
final class VideoTimeline
{
    /** @return list<int> */
    public function ids(int $limit, int $batchSize = 100): array
    {
        $limit = max(1, $limit);
        $batchSize = min(250, max(1, $batchSize));
        $ids = [];
        $cursorDate = null;
        $cursorId = 0;
        global $wpdb;

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
