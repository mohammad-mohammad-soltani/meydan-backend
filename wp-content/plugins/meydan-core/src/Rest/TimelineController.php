<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Stats;
use Meydan\Core\Support\Viewer;
use Meydan\Core\Timeline\CandidateGenerator;
use Meydan\Core\Timeline\FeatureHydrator;
use Meydan\Core\Timeline\Mixer;
use Meydan\Core\Timeline\Ranker;
use Meydan\Core\Timeline\TimelineSession;
use WP_Query;
use WP_REST_Request;

final class TimelineController extends BaseController
{
    public function timeline(WP_REST_Request $request)
    {
        $viewer = $this->viewer();
        $mode = sanitize_key((string) ($request->get_param('mode') ?: 'for_you'));
        $filter = sanitize_key((string) ($request->get_param('filter') ?: 'all'));
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        $cursor = trim((string) $request->get_param('cursor'));

        if (!in_array($mode, ['for_you', 'following'], true)) {
            $mode = 'for_you';
        }

        if ($mode === 'following' && !$viewer->isAuthenticated()) {
            return Response::error(
                'unauthenticated',
                'Timeline دنبال‌شوندگان نیاز به ورود دارد.',
                401,
            );
        }

        if ($cursor !== '') {
            $page = TimelineSession::resume($viewer, $mode, $filter, $cursor, $limit);
            if ($page === null) {
                return Response::error(
                    'timeline_cursor_expired',
                    'نشست این Timeline منقضی یا نامعتبر شده است. Timeline را تازه‌سازی کنید.',
                    410,
                );
            }

            return $this->respondPage($viewer, $page, $mode, $filter);
        }

        $snapshotSize = $this->snapshotSize($limit);
        $ids = $this->buildSnapshot($viewer, $mode, $filter, $snapshotSize);
        $page = TimelineSession::start($viewer, $mode, $filter, $ids, $limit);

        return $this->respondPage($viewer, $page, $mode, $filter);
    }

    private function snapshotSize(int $limit): int
    {
        $config = (array) get_option('meydan_timeline', []);
        $configured = (int) ($config['session_size'] ?? 600);

        return min(1000, max($limit, $configured));
    }

    /** @return array<int,int> */
    private function buildSnapshot(Viewer $viewer, string $mode, string $filter, int $limit): array
    {
        $generator = new CandidateGenerator();

        if ($mode === 'following') {
            $items = $generator->followingChronological((int) $viewer->userId, $limit);
            return array_values(array_map('intval', array_column($items, 'id')));
        }

        if (in_array($filter, ['initiatives', 'reflected'], true)) {
            return $this->filteredIds($filter, $limit);
        }

        $features = (new FeatureHydrator())->hydrate($viewer, $generator->generate($viewer));
        $ranked = (new Ranker())->rank($features);

        if ($filter !== 'all') {
            $ranked = array_values(array_filter(
                $ranked,
                fn (array $item): bool => $this->matches((int) $item['id'], $filter),
            ));
        }

        $mixed = $this->mixSnapshot($ranked, $limit);
        $ids = array_values(array_map('intval', array_column($mixed, 'id')));

        // Ranking pools intentionally favor relevant/recent content. Once those
        // pools are exhausted, append chronological narratives so a long scroll
        // can keep moving without re-ranking or repeating the current session.
        if ($filter === 'all' && count($ids) < $limit) {
            $ids = $this->appendRecentFallback($ids, $limit);
        }

        return $ids;
    }

    /**
     * Mixer actor caps are useful inside a viewport-sized window, but applying
     * one cap across a 600-item snapshot would prematurely exhaust the feed.
     * Build the snapshot in deterministic windows and remove selected ids after
     * every pass so diversity resets naturally farther down the timeline.
     *
     * @param array<int,array<string,mixed>> $ranked
     * @return array<int,array<string,mixed>>
     */
    private function mixSnapshot(array $ranked, int $limit): array
    {
        $remaining = array_values($ranked);
        $selected = [];
        $mixer = new Mixer();
        $guard = 0;

        while ($remaining !== [] && count($selected) < $limit && $guard < 100) {
            $guard++;
            $batchLimit = min(100, $limit - count($selected));
            $batch = $mixer->mixAndDiversify($remaining, $batchLimit);
            if ($batch === []) {
                break;
            }

            $picked = [];
            foreach ($batch as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0 || isset($picked[$id])) {
                    continue;
                }
                $picked[$id] = true;
                $selected[] = $item;
                if (count($selected) >= $limit) {
                    break;
                }
            }

            if ($picked === []) {
                break;
            }

            $remaining = array_values(array_filter(
                $remaining,
                static fn (array $item): bool => !isset($picked[(int) ($item['id'] ?? 0)]),
            ));
        }

        return array_slice($selected, 0, $limit);
    }

    /** @return array<int,int> */
    private function appendRecentFallback(array $ids, int $limit): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $remaining = $limit - count($ids);
        if ($remaining <= 0) {
            return array_slice($ids, 0, $limit);
        }

        $query = new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => $remaining,
            'fields' => 'ids',
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'post__not_in' => $ids,
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, $limit);
    }

    /** @return array<int,int> */
    private function filteredIds(string $filter, int $limit): array
    {
        if ($filter === 'initiatives') {
            $query = new WP_Query([
                'post_type' => 'meydan_narrative',
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'fields' => 'ids',
                'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
                'no_found_rows' => true,
                'meta_query' => [[
                    'key' => 'meydan_initiative_id',
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ]],
            ]);

            return array_values(array_map('intval', $query->posts));
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}meydan_media_reflections mr
                ON mr.narrative_id = p.ID AND mr.status = 'published'
             WHERE p.post_type = 'meydan_narrative'
               AND p.post_status = 'publish'
             ORDER BY p.post_date_gmt DESC, p.ID DESC
             LIMIT %d",
            $limit,
        ));

        return array_values(array_map('intval', $ids ?: []));
    }

    /**
     * @param array{ids:array<int,int>,next_cursor:?string} $page
     */
    private function respondPage(Viewer $viewer, array $page, string $mode, string $filter)
    {
        $ids = $page['ids'];
        Stats::incrementViewsBulk($ids);
        $source = $filter === 'all' ? $mode : $mode . ':' . $filter;
        $this->record($viewer, $ids, $source);

        $data = array_values(array_filter(array_map([Serializer::class, 'narrative'], $ids)));

        return Response::cache(
            Response::ok($data, [
                'next_cursor' => $page['next_cursor'],
                'count' => count($data),
            ]),
            'private, no-store',
        );
    }

    private function matches(int $id, string $filter): bool
    {
        $attachments = (array) get_post_meta($id, 'meydan_attachments', true);
        $hasVisual = (bool) array_filter(
            $attachments,
            static fn ($attachment): bool => str_starts_with(
                (string) get_post_mime_type((int) ($attachment['media_id'] ?? 0)),
                'image/',
            ) || str_starts_with(
                (string) get_post_mime_type((int) ($attachment['media_id'] ?? 0)),
                'video/',
            ),
        );
        $hasAudio = (bool) array_filter(
            $attachments,
            static fn ($attachment): bool => str_starts_with(
                (string) get_post_mime_type((int) ($attachment['media_id'] ?? 0)),
                'audio/',
            ),
        );

        return match ($filter) {
            'echo' => (bool) get_post_meta($id, 'meydan_is_echo', true),
            'reflected' => Serializer::mediaReflections($id) !== [],
            'initiatives' => (int) get_post_meta($id, 'meydan_initiative_id', true) > 0,
            'visual' => $hasVisual,
            'audio' => $hasAudio,
            'media' => $hasVisual || $hasAudio,
            'ideas' => !$hasVisual
                && !$hasAudio
                && !((bool) get_post_meta($id, 'meydan_is_echo', true)),
            default => true,
        };
    }

    private function record(Viewer $viewer, array $ids, string $source): void
    {
        if ($ids === []) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_served_history';

        foreach ($ids as $id) {
            $wpdb->insert($table, [
                'viewer_type' => $viewer->type,
                'viewer_id' => $viewer->id,
                'narrative_id' => (int) $id,
                'served_at' => current_time('mysql', true),
                'source' => $source,
            ]);
        }
    }
}
