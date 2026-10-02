<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\EventLogger;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

final class ExploreController extends BaseController
{
    public function search(WP_REST_Request $request)
    {
        $query = $this->normalizeSearch((string) $request->get_param('q'));

        if ($query === '') {
            return Response::error(
                'validation_failed',
                'عبارت جست‌وجو الزامی است.',
                422,
                ['q' => 'required']
            );
        }

        if (!$this->searchRate()) {
            return Response::error(
                'rate_limited',
                'تعداد جست‌وجوها بیش از حد مجاز است.',
                429
            );
        }

        $types = array_values(array_filter(array_map(
            'sanitize_key',
            explode(
                ',',
                (string) ($request->get_param('types') ?: 'narrative,content,square,media,collective,organization,creator,user,topic')
            )
        )));

        $sections = [
            'narratives' => [],
            'squares' => [],
            'media' => [],
            'collectives' => [],
            'organizations' => [],
            'users' => [],
            'content' => [],
            'creators' => [],
            'topics' => [],
        ];

        if (in_array('narrative', $types, true)) {
            $sections['narratives'] = $this->posts(
                'meydan_narrative',
                $query,
                [Serializer::class, 'narrative']
            );
        }

        if (in_array('content', $types, true)) {
            $sections['content'] = $this->posts(
                'meydan_content',
                $query,
                [Serializer::class, 'content']
            );
        }

        // One section per entity kind; each searches only its own post type.
        foreach (['square' => 'squares', 'media' => 'media', 'collective' => 'collectives', 'organization' => 'organizations'] as $kind => $section) {
            if (in_array($kind, $types, true)) {
                $sections[$section] = $this->searchEntities($kind, $query);
            }
        }

        if (in_array('creator', $types, true)) {
            $sections['creators'] = $this->posts(
                'meydan_creator',
                $query,
                [Serializer::class, 'creator']
            );
        }

        if (in_array('user', $types, true)) {
            $sections['users'] = $this->searchUsers($query);
        }

        if (in_array('topic', $types, true)) {
            $terms = get_terms([
                'taxonomy' => 'meydan_topic',
                'search' => $query,
                'hide_empty' => false,
                'number' => 10,
            ]);

            if (!is_wp_error($terms)) {
                $sections['topics'] = array_map(
                    static fn($term) => [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ],
                    $terms
                );
            }
        }

        EventLogger::log('search', 'search', null, [
            'q' => $query,
            'types' => $types,
        ]);

        return Response::ok(['sections' => $sections]);
    }

    public function trends(WP_REST_Request $request)
    {
        $window = (string) ($request->get_param('window') ?: '24h');
        $hours = in_array($window, ['1h', '6h', '24h'], true)
            ? (int) $window
            : 24;

        global $wpdb;

        $weights = (array) get_option('meydan_trends', []);
        $likeWeight = (float) ($weights['likes'] ?? 1);
        $repostWeight = (float) ($weights['reposts'] ?? 2);
        $commentWeight = (float) ($weights['comments'] ?? 2.5);
        $shareWeight = (float) ($weights['shares'] ?? 1.5);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID,
                    (%f * COALESCE(s.likes, 0)
                    + %f * COALESCE(s.reposts, 0)
                    + %f * COALESCE(s.comments, 0)
                    + %f * COALESCE(s.shares, 0)
                    + (24 / GREATEST(1, TIMESTAMPDIFF(HOUR, p.post_date_gmt, UTC_TIMESTAMP())))) AS trend_score
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->prefix}meydan_narrative_stats s
                    ON s.narrative_id = p.ID
                WHERE p.post_type = 'meydan_narrative'
                    AND p.post_status = 'publish'
                    AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
                ORDER BY trend_score DESC
                LIMIT 20",
                $likeWeight,
                $repostWeight,
                $commentWeight,
                $shareWeight,
                $hours
            ),
            ARRAY_A
        );

        $items = [];

        foreach ($rows ?: [] as $row) {
            $narrative = Serializer::narrative((int) $row['ID']);
            if (!$narrative) {
                continue;
            }

            $narrative['trend_score'] = round((float) $row['trend_score'], 4);
            $items[] = $narrative;
        }

        return Response::ok([
            'window' => $window,
            'items' => $items,
        ]);
    }

    public function suggestions()
    {
        $viewer = $this->viewer();

        $result = [
            'nearby_squares' => [],
            'creators' => [],
            'topics' => [],
            'content' => [],
            'recommended_actors' => [],
        ];

        $squareQuery = new WP_Query([
            'post_type' => 'meydan_square',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $allSquares = array_values(array_filter(array_map(
            [Serializer::class, 'square'],
            $squareQuery->posts
        )));

        $nearbySquares = $allSquares;

        if ($viewer->cityId) {
            $cityMatches = array_values(array_filter(
                $allSquares,
                fn($square) => ($square['location']['city_id'] ?? 0) === $viewer->cityId
            ));

            if ($cityMatches) {
                $nearbySquares = $cityMatches;
            }
        }

        $result['nearby_squares'] = array_slice($nearbySquares, 0, 10);

        $creatorQuery = new WP_Query([
            'post_type' => 'meydan_creator',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'meta_key' => 'meydan_verified',
            'meta_value' => '1',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $result['creators'] = array_values(array_filter(array_map(
            [Serializer::class, 'creator'],
            $creatorQuery->posts
        )));

        $contentQuery = new WP_Query([
            'post_type' => 'meydan_content',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'meta_key' => 'meydan_featured',
            'meta_value' => '1',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $result['content'] = array_values(array_filter(array_map(
            [Serializer::class, 'content'],
            $contentQuery->posts
        )));

        $terms = get_terms([
            'taxonomy' => 'meydan_topic',
            'hide_empty' => false,
            'number' => 10,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (!is_wp_error($terms)) {
            $result['topics'] = array_map(
                static fn($term) => [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                ],
                $terms
            );
        }

        $actors = array_map(
            static fn($square) => Actor::forEntity((int) $square['id']),
            array_slice($result['nearby_squares'], 0, 5)
        );
        foreach (['media', 'collective', 'organization'] as $kind) {
            $latest = new WP_Query([
                'post_type' => EntityKinds::postType($kind),
                'post_status' => 'publish',
                'posts_per_page' => 2,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
            ]);
            foreach ($latest->posts as $post) {
                if (UserAccess::visibleEntity((int) $post->ID)) $actors[] = Actor::forEntity((int) $post->ID);
            }
        }
        $result['recommended_actors'] = array_slice($actors, 0, 8);

        return Response::ok($result);
    }

    private function posts(string $type, string $query, callable $serializer): array
    {
        $wpQuery = new WP_Query([
            'post_type' => $type,
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => 10,
        ]);

        return array_values(array_filter(array_map(
            $serializer,
            $wpQuery->posts
        )));
    }

    /** Entities of one kind by name or @handle. Only published entities are ever returned. */
    private function searchEntities(string $kind, string $query): array
    {
        $results = $this->posts(EntityKinds::postType($kind), $query, [Serializer::class, 'entity']);
        $seen = [];
        foreach ($results as $entity) {
            $seen[(int) $entity['id']] = true;
        }
        foreach ($this->userIdsByHandle($query) as $userId) {
            if (count($results) >= 10) break;
            $entityId = Actor::entityId($userId);
            if ($entityId <= 0 || isset($seen[$entityId]) || EntityKinds::kindOf($entityId) !== $kind || get_post_status($entityId) !== 'publish') {
                continue;
            }
            $entity = Serializer::entity($entityId);
            if (!$entity) continue;
            $results[] = $entity;
            $seen[$entityId] = true;
        }
        return array_slice($results, 0, 10);
    }

    /** @return int[] users whose @handle contains the query */
    private function userIdsByHandle(string $query): array
    {
        $handle = Handles::normalize($query);
        if (strlen($handle) < 2 || !preg_match('/^[a-z0-9_]+$/', $handle)) return [];
        return array_map('intval', get_users([
            'meta_key' => Handles::META,
            'meta_value' => $handle,
            'meta_compare' => 'LIKE',
            'fields' => 'ID',
            'number' => 20,
        ]));
    }

    private function searchUsers(string $query): array
    {
        $users = array_merge(
            get_users([
                'search' => '*' . $query . '*',
                'search_columns' => ['display_name'],
                'number' => 20,
            ]),
            get_users([
                'meta_key' => 'meydan_full_name',
                'meta_value' => $query,
                'meta_compare' => 'LIKE',
                'number' => 20,
            ])
        );

        foreach ($this->userIdsByHandle($query) as $handleUserId) {
            $users[] = get_userdata($handleUserId);
        }

        $results = [];
        $seen = [];

        foreach ($users as $user) {
            if (count($results) >= 10) {
                break;
            }

            if (!$user) {
                continue;
            }
            $userId = (int) $user->ID;
            if (isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            if (UserAccess::disabled($userId) || Actor::isEntityAccount($userId)) {
                continue;
            }

            $results[] = Actor::forUser($userId);
        }

        return $results;
    }

    private function normalizeSearch(string $value): string
    {
        $value = trim(sanitize_text_field($value));
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], $value);
        return preg_replace('/\s+/u', ' ', $value) ?: '';
    }

    private function searchRate(): bool
    {
        $viewer = $this->viewer();
        $key = $viewer->isAuthenticated()
            ? 'u' . $viewer->userId
            : 'g' . $viewer->id;

        $rate = \Meydan\Core\Support\RateLimiter::hit(
            'search',
            $key,
            60,
            MINUTE_IN_SECONDS
        );

        return $rate['allowed'];
    }
}
