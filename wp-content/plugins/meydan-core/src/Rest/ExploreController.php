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

        return Response::ok([
            'window' => $window,
            'items' => $this->trendItems($hours, 20),
        ]);
    }

    /**
     * The hottest narratives of the last `$hours` hours, each with `trend_score`
     * and `growth_pct` (engagement now against the same span before it).
     *
     * @return array<int,array<string,mixed>>
     */
    private function trendItems(int $hours, int $limit): array
    {
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
                LIMIT %d",
                $likeWeight,
                $repostWeight,
                $commentWeight,
                $shareWeight,
                $hours,
                $limit
            ),
            ARRAY_A
        ) ?: [];

        $ids = array_map(static fn(array $row): int => (int) $row['ID'], $rows);
        $growth = $this->growth($ids, $hours);
        if ($ids) {
            _prime_post_caches($ids, false, true);
            Serializer::primeNarrativeStates($ids);
        }

        $items = [];
        foreach ($rows as $row) {
            $narrative = Serializer::narrative((int) $row['ID']);
            if (!$narrative) {
                continue;
            }

            $narrative['trend_score'] = round((float) $row['trend_score'], 4);
            $narrative['growth_pct'] = $growth[(int) $row['ID']] ?? null;
            $items[] = $narrative;
        }

        return $items;
    }

    /**
     * Likes and reposts in the last `$hours` against the `$hours` before, as a
     * percentage, for a page of narratives in one query. Null when there is
     * nothing earlier to compare with.
     *
     * @param int[] $ids
     * @return array<int,int|null>
     */
    private function growth(array $ids, int $hours): array
    {
        if (!$ids) return [];
        global $wpdb;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id,
                SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)) AS now_count,
                SUM(created_at <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)) AS before_count
             FROM {$wpdb->prefix}meydan_interactions
             WHERE object_type = 'narrative' AND action IN ('like','repost')
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
               AND object_id IN ($marks)
             GROUP BY object_id",
            $hours,
            $hours,
            $hours * 2,
            ...$ids
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $before = (int) $row['before_count'];
            $out[(int) $row['object_id']] = $before > 0 ? (int) round((((int) $row['now_count']) - $before) / $before * 100) : null;
        }
        return $out;
    }

    /**
     * Everything the explore landing shows, in one cached read: hot tags, entity
     * suggestions, hot narratives, active entities and the most followed people.
     */
    public function home()
    {
        $cached = get_transient('meydan_explore_home');
        if (is_array($cached)) {
            return Response::cache(Response::ok($cached), 'public, max-age=60, stale-while-revalidate=300');
        }
        // One payload serves every visitor, so nothing viewer-specific may be baked into it.
        $hot = array_map(static function (array $item): array {
            $item['viewer_state'] = null;
            return $item;
        }, $this->trendItems(24, 6));
        $data = [
            'tags' => $this->hotTags(),
            'hot' => $hot,
            'active' => $this->activeEntities(),
            'people' => $this->topFollowed(['user'], 6),
            'entities' => $this->topFollowed(['square', 'media', 'collective', 'organization'], 8),
        ];
        set_transient('meydan_explore_home', $data, 60);
        return Response::cache(Response::ok($data), 'public, max-age=60, stale-while-revalidate=300');
    }

    /** @return array<int,array{tag:string,count:int,hot:bool}> */
    private function hotTags(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT t.name, COUNT(DISTINCT r.object_id) AS uses
             FROM {$wpdb->term_relationships} r
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'meydan_narrative_tag'
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = r.object_id AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
                AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             GROUP BY t.term_id, t.name
             ORDER BY uses DESC, t.name ASC
             LIMIT 8",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = ['tag' => (string) $row['name'], 'count' => (int) $row['uses'], 'hot' => $index < 3];
        }
        return $out;
    }

    /**
     * Entities that published in the last week, busiest first. `live` marks the
     * ones that posted within the last day.
     *
     * @return array<int,array<string,mixed>>
     */
    private function activeEntities(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT t.meta_value AS type, i.meta_value AS actor_id, COUNT(*) AS posts,
                    SUM(p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) AS today
             FROM {$wpdb->postmeta} t
             INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
                AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = 'meydan_author_actor_id'
             WHERE t.meta_key = 'meydan_author_actor_type' AND t.meta_value IN ('square','media','collective','organization')
             GROUP BY t.meta_value, i.meta_value
             ORDER BY posts DESC
             LIMIT 6",
            ARRAY_A
        ) ?: [];
        $members = $this->followerCounts($rows, 'type', 'actor_id');
        $out = [];
        foreach ($rows as $row) {
            $actor = Actor::parse((string) $row['type'], (int) $row['actor_id']);
            if (!$actor) continue;
            $out[] = [
                'actor' => $actor,
                'members' => $members[$row['type'] . ':' . (int) $row['actor_id']] ?? 0,
                'posts' => (int) $row['posts'],
                'live' => (int) $row['today'] > 0,
            ];
        }
        return $out;
    }

    /**
     * The most followed accounts of some actor types, with their follower count
     * and a one-line description.
     *
     * @param string[] $types
     * @return array<int,array<string,mixed>>
     */
    private function topFollowed(array $types, int $limit): array
    {
        global $wpdb;
        $marks = implode(',', array_fill(0, count($types), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_type AS type, object_id AS actor_id, COUNT(*) AS followers
             FROM {$wpdb->prefix}meydan_interactions
             WHERE action = 'follow' AND object_type IN ($marks)
             GROUP BY object_type, object_id
             ORDER BY followers DESC
             LIMIT %d",
            ...[...$types, $limit * 3]
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) break;
            $actor = Actor::parse((string) $row['type'], (int) $row['actor_id']);
            if (!$actor) continue;
            $ownerId = Actor::ownerUserId((string) $row['type'], (int) $row['actor_id']);
            $description = (string) get_user_meta($ownerId, 'meydan_headline', true);
            if ($description === '' && EntityKinds::isEntityActorType((string) $row['type'])) {
                $description = (string) ($actor['location_address'] ?? '');
            }
            $out[] = ['actor' => $actor, 'followers' => (int) $row['followers'], 'description' => $description];
        }
        return $out;
    }

    /**
     * Follower counts for a list of rows, keyed "type:id", in one query.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function followerCounts(array $rows, string $typeKey, string $idKey): array
    {
        if (!$rows) return [];
        global $wpdb;
        $pairs = [];
        foreach ($rows as $row) $pairs[] = $wpdb->prepare('(object_type = %s AND object_id = %d)', (string) $row[$typeKey], (int) $row[$idKey]);
        $found = $wpdb->get_results(
            "SELECT object_type, object_id, COUNT(*) AS followers FROM {$wpdb->prefix}meydan_interactions
             WHERE action = 'follow' AND (" . implode(' OR ', $pairs) . ') GROUP BY object_type, object_id',
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($found as $row) $out[$row['object_type'] . ':' . (int) $row['object_id']] = (int) $row['followers'];
        return $out;
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

        // Memorial accounts are people too: they surface beside users, by name or @handle.
        foreach ($this->searchEntities('memorial', $query) as $memorial) {
            if (count($results) >= 10) break;
            $results[] = Actor::forEntity((int) $memorial['id']);
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
