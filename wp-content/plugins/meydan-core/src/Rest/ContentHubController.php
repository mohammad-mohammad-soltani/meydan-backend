<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Actor;
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
        if ($q !== '' && !$this->searchAllowed()) return Response::error('rate_limited', 'تعداد جست‌وجوها بیش از حد مجاز است.', 429);
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
        if ($q !== '' && !$this->searchAllowed()) return Response::error('rate_limited', 'تعداد جست‌وجوها بیش از حد مجاز است.', 429);
        $category = sanitize_title((string) $request->get_param('category'));
        $plain = $q === '' && $category === '';
        $data = $plain ? get_transient('meydan_hub_notes') : false;
        if (!is_array($data)) {
            $data = $this->noteShelves($q, $category);
            if ($plain) set_transient('meydan_hub_notes', $data, self::CACHE_SECONDS);
        }
        return Response::cache(Response::ok($data), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** Live search runs a LIKE scan; the same budget as /explore/search keeps it from being hammered. */
    private function searchAllowed(): bool
    {
        $viewer = \Meydan\Core\Support\Viewer::current();
        $key = $viewer->userId ? 'u' . $viewer->userId : 'g' . $viewer->id;
        return \Meydan\Core\Support\RateLimiter::hit('search', $key, 60, MINUTE_IN_SECONDS)['allowed'];
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
            $types = $kind === 'faces' ? ['user', 'speaker', 'official'] : ['square', 'media', 'collective', 'organization'];
            $rows = $this->producers($types, $limit + 1, $offset);
            if ($cacheable) set_transient($key, $rows, self::CACHE_SECONDS);
        }
        $more = count($rows) > $limit;
        return Response::cache(Response::ok(array_slice($rows, 0, $limit), ['next_offset' => $more ? $offset + $limit : null]), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** @return array<string,mixed> */
    private function audioShelves(): array
    {
        $featured = $this->items(['meta_query' => [$this->audioMeta(), ['key' => 'meydan_featured', 'value' => '1']]], 6);
        $latest = $this->items(['meta_query' => [$this->audioMeta()]], 12);
        return [
            'featured' => $featured,
            'series' => $this->series(),
            'faces' => $this->producers(['user', 'speaker', 'official'], 12),
            'squares' => $this->producers(['square', 'media', 'collective', 'organization'], 12),
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
            'latest' => $this->items(['s' => $q, 'meta_query' => [$this->audioMeta()]], 20),
            'query' => $q,
        ];
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
        if ($ids) {
            update_meta_cache('post', $ids);
            \Meydan\Core\Support\Stats::primeContents($ids);
        }
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
     * Producers of audio content with how many items each has, busiest first.
     *
     * @param string[] $types
     * @return array<int,array<string,mixed>>
     */
    private function producers(array $types, int $limit, int $offset = 0): array
    {
        global $wpdb;
        $marks = implode(',', array_fill(0, count($types), '%s'));
        $sql = "SELECT t.meta_value AS type, i.meta_value AS actor_id, COUNT(*) AS audios
                FROM {$wpdb->postmeta} t
                INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id AND p.post_type = 'meydan_content' AND p.post_status = 'publish'
                INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = 'meydan_producer_actor_id'
                INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = 'meydan_format' AND f.meta_value = 'audio'
                WHERE t.meta_key = 'meydan_producer_actor_type' AND t.meta_value IN ({$marks})
                GROUP BY t.meta_value, i.meta_value
                ORDER BY audios DESC
                LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...[...$types, $limit, $offset])) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $actor = Actor::parse((string) $row->type, (int) $row->actor_id);
            if ($actor) $out[] = ['actor' => $actor, 'audios' => (int) $row->audios];
        }
        return $out;
    }
}
