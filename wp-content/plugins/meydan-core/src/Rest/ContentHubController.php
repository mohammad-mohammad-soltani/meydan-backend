<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\AudioProducers;
use Meydan\Core\Support\NarrativeMediaFlags;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

/**
 * The two tabs of the content hub that group many items at once: «آوا» (audio)
 * and «یادداشت» (notes). Each answers with one cached payload, so opening a
 * tab costs one request instead of one per shelf.
 */
final class ContentHubController extends BaseController
{
    private const CACHE_SECONDS = 60;
    private const NOTE_TYPES = ['note'];

    public function audio(WP_REST_Request $request)
    {
        $q = mb_substr(trim((string) $request->get_param('q')), 0, 80);
        // Only the default shelves are stored: caching every search text would let anyone fill the options table.
        $data = $q === '' ? get_transient('meydan_hub_audio') : false;
        if (!is_array($data)) {
            $data = $q === '' ? $this->audioShelves() : $this->audioSearch($q);
            if ($q === '') set_transient('meydan_hub_audio', $data, self::CACHE_SECONDS);
        }
        return Response::cache(Response::ok($data), 'public, max-age=60, stale-while-revalidate=300');
    }

    public function notes(WP_REST_Request $request)
    {
        $q = mb_substr(trim((string) $request->get_param('q')), 0, 80);
        $category = sanitize_title((string) $request->get_param('category'));
        $plain = $q === '' && $category === '';
        $data = $plain ? get_transient('meydan_hub_notes') : false;
        if (!is_array($data)) {
            $data = $this->noteShelves($q, $category);
            if ($plain) set_transient('meydan_hub_notes', $data, self::CACHE_SECONDS);
        }
        return Response::cache(Response::ok($data), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** A full, paged list of the people («faces») or entities («squares») that publish audio. */
    public function producerList(WP_REST_Request $request)
    {
        $kind = (string) $request->get_param('kind') === 'squares' ? 'squares' : 'faces';
        $offset = max(0, (int) $request->get_param('offset'));
        $limit = 30;
        $key = 'meydan_hub_producers_' . $kind . '_' . $offset;
        // Only real page starts are stored, so arbitrary offsets cannot fill the options table.
        $cacheable = $offset % $limit === 0 && $offset <= 600;
        $rows = $cacheable ? get_transient($key) : false;
        if (!is_array($rows)) {
            $rows = $this->producers($kind, $limit + 1, $offset);
            if ($cacheable) set_transient($key, $rows, self::CACHE_SECONDS);
        }
        $more = count($rows) > $limit;
        return Response::cache(Response::ok(array_slice($rows, 0, $limit), ['next_offset' => $more ? $offset + $limit : null]), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** @return array<string,mixed> */
    private function audioShelves(): array
    {
        // «ویژه‌ها» are exactly the content marked as a music video (نماهنگ).
        $featured = $this->items(['meta_query' => [['key' => 'meydan_content_type', 'value' => 'music_video']]], 6);
        $latest = $this->latestAudio(12);
        return [
            'featured' => $featured,
            'series' => $this->series(),
            'faces' => $this->producers('faces', 12),
            'squares' => $this->producers('squares', 12),
            'latest' => $latest,
        ];
    }

    /** @return array<string,mixed> */
    private function audioSearch(string $q): array
    {
        return [
            'featured' => [],
            'series' => [],
            'faces' => [],
            'squares' => [],
            'latest' => $this->latestAudio(20, $q),
            'query' => $q,
        ];
    }

    /**
     * The newest audio from anywhere: published audio content plus every audio file
     * attached to a narrative by any account, whether or not it was ever promoted to content.
     *
     * @return array<int,array<string,mixed>>
     */
    private function latestAudio(int $limit, string $q = ''): array
    {
        $content = $this->items($q === '' ? ['meta_query' => [$this->audioMeta()]] : ['s' => $q, 'meta_query' => [$this->audioMeta()]], $limit);
        return $this->mergeAudio($content, [], $q, $limit);
    }

    /**
     * Every audio file one actor published: audio content produced by them plus audio in their posts.
     * A plain offset keeps paging simple; both sources are read newest-first.
     */
    public function producer(WP_REST_Request $request)
    {
        $type = sanitize_key((string) $request->get_param('type'));
        $id = (int) $request->get_param('id');
        $offset = max(0, (int) $request->get_param('offset'));
        $limit = 20;
        if ($type === '' || $id <= 0) return Response::error('validation_failed', 'بازیگر نامعتبر است.', 422);
        $need = $offset + $limit + 1;
        $content = $this->items(['meta_query' => [
            $this->audioMeta(),
            ['key' => 'meydan_producer_actor_type', 'value' => $type],
            ['key' => 'meydan_producer_actor_id', 'value' => (string) $id],
        ]], $need);
        $authored = [
            ['key' => 'meydan_author_actor_type', 'value' => $type],
            ['key' => 'meydan_author_actor_id', 'value' => (string) $id],
        ];
        $all = $this->mergeAudio($content, $authored, '', $need);
        $more = count($all) > $offset + $limit;
        return Response::cache(Response::ok(array_slice($all, $offset, $limit), ['next_offset' => $more ? $offset + $limit : null]), 'public, max-age=30, stale-while-revalidate=120');
    }

    /**
     * Adds the audio files attached to posts to already-loaded audio content and returns
     * the newest `$limit` of both. Narratives carry a `meydan_has_audio` flag, so the post
     * side is one indexed read; nothing is scanned or fully serialized.
     *
     * @param array<int,array<string,mixed>> $content
     * @param array<int,array<string,mixed>> $extraMeta further meta conditions on the post
     * @return array<int,array<string,mixed>>
     */
    private function mergeAudio(array $content, array $extraMeta, string $q, int $limit): array
    {
        // A narrative already promoted to content is listed once, as content.
        $promoted = [];
        foreach ($content as $item) {
            $source = (int) get_post_meta((int) $item['id'], 'meydan_source_narrative_id', true);
            if ($source > 0) $promoted[$source] = true;
        }
        $args = [
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => $limit + count($promoted),
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'meta_query' => array_merge([['key' => NarrativeMediaFlags::AUDIO, 'value' => '1']], $extraMeta),
        ];
        if ($q !== '') $args['s'] = $q;
        $query = new WP_Query($args);
        $ids = wp_list_pluck($query->posts, 'ID');
        if ($ids) update_meta_cache('post', $ids);
        $fromPosts = [];
        foreach ($query->posts as $post) {
            if (count($fromPosts) >= $limit) break;
            $id = (int) $post->ID;
            if (isset($promoted[$id]) || !UserAccess::visibleNarrative($id)) continue;
            foreach ((array) get_post_meta($id, 'meydan_attachments', true) as $raw) {
                if (!is_array($raw)) continue;
                $mediaId = (int) ($raw['media_id'] ?? $raw['id'] ?? 0);
                if ($mediaId <= 0 || !str_starts_with((string) get_post_mime_type($mediaId), 'audio/')) continue;
                $attachment = Serializer::attachment($raw);
                $text = trim(wp_strip_all_tags((string) $post->post_content));
                $title = (string) ($attachment['label'] ?? '') ?: ($text !== '' ? mb_substr(preg_split('/\\R/u', $text)[0], 0, 80) : 'صوت');
                $duration = (float) ($attachment['duration'] ?? 0);
                $fromPosts[] = [
                    'id' => -$id,
                    'title' => $title,
                    'excerpt' => '',
                    'body' => '',
                    'format' => 'audio',
                    'href' => '/posts/' . $id,
                    'attachments' => [$attachment],
                    'primary_attachment_id' => $mediaId,
                    'producer' => Actor::fromNarrative($id),
                    'published_at' => gmdate('c', (int) strtotime($post->post_date_gmt . ' UTC')),
                    'media_duration' => $duration > 0 ? sprintf('%d:%02d', intdiv((int) $duration, 60), (int) $duration % 60) : '',
                ];
                break;
            }
        }
        $all = array_merge($content, $fromPosts);
        usort($all, static fn(array $a, array $b): int => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
        return array_slice($all, 0, $limit);
    }

    /** @return array<string,mixed> */
    private function noteShelves(string $q, string $category): array
    {
        $base = ['meta_query' => [['key' => 'meydan_content_type', 'value' => self::NOTE_TYPES, 'compare' => 'IN']]];
        if ($q !== '') $base['s'] = $q;
        $list = $base;
        if ($category !== '') {
            $list['tax_query'] = [['taxonomy' => 'meydan_content_category', 'field' => 'slug', 'terms' => $category]];
        }
        $featuredArgs = $base;
        $featuredArgs['meta_query'][] = ['key' => 'meydan_featured', 'value' => '1'];
        $terms = get_terms(['taxonomy' => 'meydan_content_category', 'hide_empty' => true]);
        $categories = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (in_array($term->slug, ['talks', 'audio', 'schedule', 'featured'], true)) continue;
                $categories[] = ['slug' => $term->slug, 'name' => $term->name, 'count' => (int) $term->count];
            }
        }
        return [
            'categories' => $categories,
            'featured' => $q === '' && $category === '' ? $this->items($featuredArgs, 5) : [],
            'latest' => $this->items($list, 30),
        ];
    }

    /** @return array<string,mixed> */
    private function audioMeta(): array
    {
        return ['key' => 'meydan_format', 'value' => 'audio'];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    private function items(array $args, int $limit): array
    {
        $query = new WP_Query($args + [
            'post_type' => 'meydan_content',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        $ids = wp_list_pluck($query->posts, 'ID');
        if ($ids) update_meta_cache('post', $ids);
        $out = [];
        foreach ($query->posts as $post) {
            $item = Serializer::content($post);
            if ($item) $out[] = $this->light($item, (int) $post->ID);
        }
        return $out;
    }

    /**
     * Lists need the card fields only; the long body stays on the detail read.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function light(array $item, int $id): array
    {
        $body = (string) ($item['body'] ?? '');
        $item['body'] = '';
        $item['series'] = (string) get_post_meta($id, 'meydan_series', true) ?: null;
        $item['media_duration'] = (string) get_post_meta($id, 'meydan_media_duration', true);
        $item['primary_attachment_id'] = (int) get_post_meta($id, 'meydan_primary_attachment_id', true);
        $item['reading_minutes'] = $body === '' ? null : max(1, (int) ceil(count(preg_split('/\s+/u', trim(wp_strip_all_tags($body)), -1, PREG_SPLIT_NO_EMPTY) ?: []) / 180));
        return $item;
    }

    /** @return array<int,array<string,mixed>> */
    private function series(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT s.meta_value AS title, COUNT(*) AS sessions, MAX(p.ID) AS last_id
             FROM {$wpdb->postmeta} s
             INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_type = 'meydan_content' AND p.post_status = 'publish'
             INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = 'meydan_format' AND f.meta_value = 'audio'
             WHERE s.meta_key = 'meydan_series' AND s.meta_value <> ''
             GROUP BY s.meta_value
             ORDER BY MAX(p.post_date_gmt) DESC
             LIMIT 10"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $last = Serializer::content((int) $row->last_id);
            $out[] = [
                'title' => (string) $row->title,
                'sessions' => (int) $row->sessions,
                'cover_url' => $last['media_cover_url'] ?? null,
                'producer' => $last['producer'] ?? null,
                'last_id' => (int) $row->last_id,
            ];
        }
        return $out;
    }

    /**
     * Who published audio, busiest first: one indexed read of the maintained table.
     * «faces» are speaker or official accounts; «squares» are squares (میدان).
     *
     * @return array<int,array<string,mixed>>
     */
    private function producers(string $kind, int $limit, int $offset = 0): array
    {
        return AudioProducers::top($kind === 'faces' ? 'face' : 'square', $limit, $offset);
    }
}
