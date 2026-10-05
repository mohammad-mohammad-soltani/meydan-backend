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
use Meydan\Core\Timeline\VideoTimeline;
use Meydan\Core\Feed\FeedService;
use Meydan\Core\Feed\FeedSettings;
use WP_Query;
use WP_REST_Request;

final class TimelineController extends BaseController
{
    /** @var array<int,array<string,mixed>> */
    private array $feedV2Debug = [];
    /** @var array<string,mixed> */
    private array $feedV2DebugSummary = [];
    public function timeline(WP_REST_Request $request)
    {
        $viewer = $this->viewer();
        $mode = sanitize_key((string) ($request->get_param('mode') ?: 'for_you'));
        $filter = sanitize_key((string) ($request->get_param('filter') ?: 'all'));
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        $cursor = trim((string) $request->get_param('cursor'));
        // Debug data is intentionally gated; ordinary public timeline responses stay unchanged.
        $debugFeed = (string) $request->get_param('debug_feed') === '1' && $this->isAdministrator();

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

            return $this->respondPage($viewer, $page, $mode, $filter, $debugFeed ? [] : null);
        }

        $snapshotSize = $this->snapshotSize($limit);
        $ids = $this->buildSnapshot($viewer, $mode, $filter, $snapshotSize, $debugFeed);
        $page = TimelineSession::start($viewer, $mode, $filter, $ids, $limit);

        return $this->respondPage($viewer, $page, $mode, $filter, $debugFeed ? $this->debugForIds($page['ids']) : null);
    }

    private function snapshotSize(int $limit): int
    {
        $config = (array) get_option('meydan_timeline', []);
        $configured = (int) ($config['session_size'] ?? 600);

        return min(1000, max($limit, $configured));
    }

    /** @return array<int,int> */
    private function buildSnapshot(Viewer $viewer, string $mode, string $filter, int $limit, bool $debug = false): array
    {
        if ($mode === 'for_you' && $filter === 'video') {
            return (new VideoTimeline())->ids($limit);
        }

        // Cursor requests return before this method, so V2 runs only for a newly created snapshot.
        if ($mode === 'for_you' && $filter === 'all' && FeedSettings::enabled()) {
            // Keep refresh history out of preview/scoring and out of cursor reads.
            $refreshKey = 'meydan_feed_first_' . hash('sha256', $viewer->type . ':' . $viewer->id);
            $previousFirstId = (int) get_transient($refreshKey);
            $result = (new FeedService())->forYou($viewer, $limit, $debug, $previousFirstId);
            if ($result['ids'] !== []) set_transient($refreshKey, $result['ids'][0], HOUR_IN_SECONDS);
            foreach ($result['items'] as $item) $this->feedV2Debug[(int) $item['narrative_id']] = $item;
            $this->feedV2DebugSummary = (array) ($result['debug_summary'] ?? []);
            return $result['ids'];
        }
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
            // matches() reads meta and attachment mime types per candidate; load them for the whole pool at once.
            $this->primeCaches(array_map('intval', array_column($ranked, 'id')));
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
    private function respondPage(Viewer $viewer, array $page, string $mode, string $filter, ?array $debug = null)
    {
        $ids = $page['ids'];
        $source = $filter === 'all' ? $mode : $mode . ':' . $filter;
        // View counts and served history are bookkeeping, not part of the answer: write them after the
        // response has been sent (the client never waits for these INSERT/UPDATE statements).
        add_action('shutdown', function () use ($viewer, $ids, $source): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            Stats::incrementViewsBulk($ids);
            $this->record($viewer, $ids, $source);
        }, 20);

        $this->primeCaches($ids);
        Stats::primeNarratives($ids);
        Serializer::primeMediaReflections($ids);
        Serializer::primeNarrativeStates($ids);
        $data = array_values(array_filter(array_map([Serializer::class, 'narrative'], $ids)));

        $meta = [
            'next_cursor' => $page['next_cursor'],
            'count' => count($data),
        ];
        if ($debug !== null) $meta['debug_feed'] = $debug;
        if ($debug !== null) $meta['debug_feed'] = ['items' => $meta['debug_feed'], 'summary' => $this->feedV2DebugSummary];
        return Response::cache(
            Response::ok($data, $meta),
            'private, no-store',
        );
    }

    /**
     * Warms the post, meta, term, user and attachment caches for a set of narratives in a handful of
     * queries, so the per-item code that follows (serialization, filter chips) reads from memory.
     *
     * @param int[] $ids
     */
    private function primeCaches(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return;
        }
        _prime_post_caches($ids, true, true);

        $authors = [];
        $entities = [];
        $media = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post) {
                $authors[] = (int) $post->post_author;
            }
            if ((string) get_post_meta($id, 'meydan_author_actor_type', true) === 'square') {
                $entities[] = (int) get_post_meta($id, 'meydan_author_actor_id', true);
            }
            foreach ((array) get_post_meta($id, 'meydan_attachments', true) as $attachment) {
                if (is_array($attachment) && !empty($attachment['media_id'])) {
                    $media[] = (int) $attachment['media_id'];
                }
            }
        }
        $authors = array_values(array_unique(array_filter($authors)));
        if ($authors) {
            cache_users($authors);
            update_meta_cache('user', $authors);
        }
        $related = array_values(array_unique(array_filter(array_merge($entities, $media))));
        if ($related) {
            _prime_post_caches($related, false, true);
        }
    }

    private function isAdministrator(): bool
    {
        $user = wp_get_current_user();
        return $user instanceof \WP_User && in_array('administrator', (array) $user->roles, true);
    }

    /** @param array<int,int> $ids @return list<array<string,mixed>> */
    private function debugForIds(array $ids): array
    {
        return array_values(array_filter(array_map(fn(int $id): ?array => $this->feedV2Debug[$id] ?? null, $ids)));
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
            // «روایت» chip: plain narratives, i.e. not attached to a کار.
            'narratives' => (int) get_post_meta($id, 'meydan_initiative_id', true) <= 0,
            'visual' => $hasVisual,
            'audio' => $hasAudio,
            'media' => $hasVisual || $hasAudio,
            'video' => (bool) array_filter(
                $attachments,
                static fn ($attachment): bool => str_starts_with(
                    (string) get_post_mime_type((int) ($attachment['media_id'] ?? 0)),
                    'video/',
                ),
            ),
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

        // One multi-row INSERT per page instead of one statement per narrative.
        $now = current_time('mysql', true);
        $rows = [];
        $args = [];
        foreach ($ids as $id) {
            $rows[] = '(%s, %s, %d, %s, %s)';
            array_push($args, $viewer->type, $viewer->id, (int) $id, $now, $source);
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (viewer_type, viewer_id, narrative_id, served_at, source) VALUES " . implode(',', $rows),
            ...$args
        ));
    }
}
