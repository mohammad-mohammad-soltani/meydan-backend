<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use Meydan\Core\Integrations\Bale\EventSubscriber as BaleEvents;
use Meydan\Core\Support\Stats;
use WP_Error;

final class ImportService
{
    public function __construct(private readonly BindingService $bindings = new BindingService())
    {
    }

    /** @param array<int,array{source_key:string,source_hash:string}> $items */
    public function known(array $items): array
    {
        global $wpdb;
        $keys = [];
        $requestedHashes = [];
        foreach (array_slice($items, 0, 500) as $item) {
            $key = trim((string) ($item['source_key'] ?? ''));
            $hash = self::cleanHash((string) ($item['source_hash'] ?? ''));
            if ($key !== '' && $hash !== '') {
                $keys[] = $key;
                $requestedHashes[$key] = $hash;
            }
        }
        if (!$keys) {
            return [];
        }
        $table = $wpdb->prefix . 'meydan_eitaa_imports';
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT source_key,source_hash,narrative_id FROM {$table} WHERE source_key IN ({$placeholders})",
            ...$keys
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['source_key'];
            $storedHash = (string) $row['source_hash'];
            $out[$key] = [
                'source_hash' => $storedHash,
                'narrative_id' => (int) $row['narrative_id'],
                'unchanged' => isset($requestedHashes[$key]) && hash_equals($storedHash, $requestedHashes[$key]),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|WP_Error */
    public function upsert(array $payload): array|WP_Error
    {
        global $wpdb;
        $squareId = (int) ($payload['square_id'] ?? 0);
        $userId = $this->bindings->ownerForSquare($squareId);
        if ($userId <= 0 || $this->bindings->channelForSquare($squareId) === '') {
            return $this->fail(new WP_Error('eitaa_square_invalid', 'میدان یا اتصال ایتا معتبر نیست.', ['status' => 422]), $squareId);
        }

        $sourceKey = trim((string) ($payload['source_key'] ?? ''));
        $sourceHash = self::cleanHash((string) ($payload['source_hash'] ?? ''));
        $channelId = trim((string) ($payload['channel_id'] ?? ''));
        $publishedAt = (int) ($payload['published_at'] ?? 0);
        if ($sourceKey === '' || strlen($sourceKey) > 255 || $sourceHash === '' || $channelId === '' || $publishedAt <= 0) {
            return $this->fail(new WP_Error('eitaa_import_invalid', 'اطلاعات منبع ایتا کامل نیست.', ['status' => 422]), $squareId);
        }

        $attachments = $this->attachments((array) ($payload['attachments'] ?? []), $userId);
        if (is_wp_error($attachments)) {
            return $this->fail($attachments, $squareId);
        }

        $table = $wpdb->prefix . 'meydan_eitaa_imports';
        $lockName = 'meydan_eitaa_' . substr(hash('sha256', $sourceKey), 0, 48);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lockName));
        if ($locked !== 1) {
            return new WP_Error('eitaa_import_busy', 'این پست هم‌اکنون در حال همگام‌سازی است.', ['status' => 409]);
        }

        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_key=%s LIMIT 1",
                $sourceKey
            ), ARRAY_A);
            if ($row && hash_equals((string) $row['source_hash'], $sourceHash)
                && get_post_type((int) $row['narrative_id']) === 'meydan_narrative') {
                return ['status' => 'skipped', 'narrative_id' => (int) $row['narrative_id']];
            }

            $wpdb->query('START TRANSACTION');
            $postId = $row ? (int) $row['narrative_id'] : 0;
            $post = [
                'post_type' => 'meydan_narrative',
                'post_status' => 'publish',
                'post_author' => $userId,
                'post_content' => wp_kses_post((string) ($payload['body_html'] ?? $payload['body'] ?? '')),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', $publishedAt),
                'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $publishedAt)),
            ];
            if ($postId > 0 && get_post_type($postId) === 'meydan_narrative') {
                $post['ID'] = $postId;
                $result = wp_update_post($post, true);
            } else {
                $result = wp_insert_post($post, true);
            }
            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                return $result;
            }
            $postId = (int) $result;

            update_post_meta($postId, 'meydan_author_actor_type', 'square');
            update_post_meta($postId, 'meydan_author_actor_id', $squareId);
            update_post_meta($postId, 'meydan_attachments', $attachments);
            update_post_meta($postId, 'meydan_import_source', 'eitaa');
            update_post_meta($postId, 'meydan_import_source_key', $sourceKey);
            update_post_meta($postId, 'meydan_import_source_hash', $sourceHash);
            update_post_meta($postId, 'meydan_eitaa_channel_id', $channelId);
            update_post_meta($postId, 'meydan_eitaa_message_ids', array_values(array_map('intval', (array) ($payload['message_ids'] ?? []))));
            update_post_meta($postId, 'meydan_eitaa_grouped_id', (string) ($payload['grouped_id'] ?? ''));
            update_post_meta($postId, 'meydan_eitaa_published_at', $publishedAt);
            update_post_meta($postId, 'meydan_eitaa_source_payload', (array) ($payload['source_payload'] ?? []));
            if (!empty($payload['unmapped_media_type'])) {
                update_post_meta($postId, 'meydan_eitaa_unmapped_media_type', sanitize_key((string) $payload['unmapped_media_type']));
            } else {
                delete_post_meta($postId, 'meydan_eitaa_unmapped_media_type');
            }
            if (!empty($payload['poll']) && is_array($payload['poll'])) {
                update_post_meta($postId, 'meydan_poll', $payload['poll']);
            }
            Stats::incrementNarrative($postId, 'views', 0);

            $now = current_time('mysql', true);
            $messageIds = wp_json_encode(array_values(array_map('intval', (array) ($payload['message_ids'] ?? []))));
            $published = gmdate('Y-m-d H:i:s', $publishedAt);
            if ($row) {
                $ok = $wpdb->update($table, [
                    'source_hash' => $sourceHash,
                    'channel_id' => $channelId,
                    'square_id' => $squareId,
                    'user_id' => $userId,
                    'narrative_id' => $postId,
                    'grouped_id' => isset($payload['grouped_id']) ? (string) $payload['grouped_id'] : null,
                    'message_ids' => $messageIds ?: '[]',
                    'published_at' => $published,
                    'updated_at' => $now,
                ], ['id' => (int) $row['id']]);
            } else {
                $ok = $wpdb->insert($table, [
                    'source_key' => $sourceKey,
                    'source_hash' => $sourceHash,
                    'channel_id' => $channelId,
                    'square_id' => $squareId,
                    'user_id' => $userId,
                    'narrative_id' => $postId,
                    'grouped_id' => isset($payload['grouped_id']) ? (string) $payload['grouped_id'] : null,
                    'message_ids' => $messageIds ?: '[]',
                    'published_at' => $published,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('eitaa_import_mapping_failed', 'ثبت شناسه منبع ایتا ناموفق بود.', ['status' => 500]);
            }
            $wpdb->query('COMMIT');
            return ['status' => $row ? 'updated' : 'imported', 'narrative_id' => $postId];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @return array<int,array<string,mixed>>|WP_Error */
    private function attachments(array $items, int $userId): array|WP_Error
    {
        $out = [];
        $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaId = (int) ($item['media_id'] ?? 0);
            if ($mediaId <= 0 || isset($seen[$mediaId]) || get_post_type($mediaId) !== 'attachment') {
                return new WP_Error('eitaa_media_invalid', 'فایل آپلودشده معتبر نیست.', ['status' => 422]);
            }
            if ((int) get_post_field('post_author', $mediaId) !== $userId) {
                return new WP_Error('eitaa_media_owner', 'مالک فایل با میدان مقصد یکسان نیست.', ['status' => 403]);
            }
            $seen[$mediaId] = true;
            $out[] = [
                'media_id' => $mediaId,
                'order' => (int) ($item['order'] ?? ($index + 1)),
                'caption' => isset($item['caption']) ? sanitize_text_field((string) $item['caption']) : null,
                'label' => isset($item['label']) ? sanitize_text_field((string) $item['label']) : null,
                'source' => isset($item['source']) && is_array($item['source']) ? $item['source'] : [],
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $out;
    }

    /**
     * Reports an import failure to Bale (requirement 1) and returns it
     * unchanged, so the sync service still receives its error.
     */
    private function fail(WP_Error $error, int $squareId): WP_Error
    {
        BaleEvents::reportEitaaFailure('ورود محتوا از ایتا', $error, [
            'square_id' => $squareId,
            'http_status' => $error->get_error_data()['status'] ?? null,
        ]);
        return $error;
    }

    private static function cleanHash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if (str_starts_with($hash, 'sha256:')) {
            $hash = substr($hash, 7);
        }
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }
}
