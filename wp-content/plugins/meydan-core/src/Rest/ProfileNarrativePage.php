<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

/**
 * A shared cursor contract for own, user, and square profile narratives.
 *
 * Pass `$repostUserId` (the account's user id) to also list what the account
 * reposted, interleaved by time; those items carry `reposted_at`.
 */
final class ProfileNarrativePage
{
    public static function list(array $metaQuery, WP_REST_Request $request, string $actorKey, int $repostUserId = 0, int $metaActorId = 0)
    {
        if ($repostUserId > 0 && $metaActorId > 0) {
            return self::withReposts('user', $metaActorId, $repostUserId, $request, $actorKey);
        }
        return self::query(['meta_query' => $metaQuery], $request, $actorKey);
    }

    public static function listByAuthor(int $authorId, WP_REST_Request $request, string $actorKey, bool $withReposts = false)
    {
        if ($withReposts) return self::withReposts('author', $authorId, $authorId, $request, $actorKey);
        return self::query(['author' => $authorId], $request, $actorKey);
    }

    /** @return array{limit:int,offset:int}|\WP_REST_Response|\WP_Error */
    private static function paging(WP_REST_Request $request, string $actorKey)
    {
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        $rawCursor = trim((string) $request->get_param('cursor'));
        $cursor = Cursor::decode($rawCursor);
        if ($rawCursor !== '' && (($cursor['actor'] ?? null) !== $actorKey || !isset($cursor['offset']) || !is_int($cursor['offset']) || $cursor['offset'] < 0)) {
            return Response::error('invalid_cursor', 'صفحهٔ روایت‌ها معتبر نیست.', 400);
        }
        return ['limit' => $limit, 'offset' => $rawCursor === '' ? 0 : $cursor['offset']];
    }

    private static function query(array $filter, WP_REST_Request $request, string $actorKey)
    {
        $paging = self::paging($request, $actorKey);
        if (!isset($paging['limit'])) return $paging;
        ['limit' => $limit, 'offset' => $offset] = $paging;
        $query = new WP_Query($filter + [
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => $limit + 1,
            'no_found_rows' => true,
            'offset' => $offset,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]);
        $hasMore = count($query->posts) > $limit;
        $posts = array_slice($query->posts, 0, $limit);
        return self::respond($posts, [], $hasMore, $actorKey, $offset, $limit);
    }

    /**
     * Own posts and reposts in one time-ordered page. One UNION keeps the
     * offset cursor stable; a post the account both wrote and reposted shows
     * only as its own post.
     */
    private static function withReposts(string $mode, int $actorId, int $userId, WP_REST_Request $request, string $actorKey)
    {
        global $wpdb;
        $paging = self::paging($request, $actorKey);
        if (!isset($paging['limit'])) return $paging;
        ['limit' => $limit, 'offset' => $offset] = $paging;

        if ($mode === 'author') {
            $own = $wpdb->prepare(
                "SELECT p.ID AS id, p.post_date_gmt AS ts, 0 AS rp FROM {$wpdb->posts} p WHERE p.post_author = %d AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'",
                $actorId
            );
            $notOwn = $wpdb->prepare('p.post_author <> %d', $actorId);
        } else {
            $own = $wpdb->prepare(
                "SELECT p.ID AS id, p.post_date_gmt AS ts, 0 AS rp FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'meydan_author_actor_type' AND t.meta_value = 'user'
                 INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = 'meydan_author_actor_id' AND a.meta_value = %s
                 WHERE p.post_type = 'meydan_narrative' AND p.post_status = 'publish'",
                (string) $actorId
            );
            $notOwn = $wpdb->prepare('p.post_author <> %d', $userId);
        }
        $reposts = $wpdb->prepare(
            "SELECT p.ID AS id, i.created_at AS ts, 1 AS rp FROM {$wpdb->prefix}meydan_interactions i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.object_id
             WHERE i.user_id = %d AND i.object_type = 'narrative' AND i.action = 'repost'
               AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish' AND {$notOwn}",
            $userId
        );
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ts, rp FROM (({$own}) UNION ALL ({$reposts})) u ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d",
            $limit + 1,
            $offset
        ), ARRAY_A) ?: [];

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $posts = [];
        $repostedAt = [];
        foreach ($rows as $row) {
            $post = get_post((int) $row['id']);
            if (!$post) continue;
            $posts[] = $post;
            if ((int) $row['rp'] === 1) $repostedAt[(int) $row['id']] = gmdate(DATE_ATOM, strtotime($row['ts'] . ' UTC'));
        }
        return self::respond($posts, $repostedAt, $hasMore, $actorKey, $offset, $limit);
    }

    /** @param array<int,string> $repostedAt */
    private static function respond(array $posts, array $repostedAt, bool $hasMore, string $actorKey, int $offset, int $limit)
    {
        Serializer::primeMediaReflections(array_map(static fn($post): int => (int) $post->ID, $posts));
        $data = [];
        foreach ($posts as $post) {
            $item = Serializer::narrative($post);
            if (!$item) continue;
            if (isset($repostedAt[(int) $post->ID])) $item['reposted_at'] = $repostedAt[(int) $post->ID];
            $data[] = $item;
        }
        return Response::ok($data, [
            'next_cursor' => $hasMore ? Cursor::encode(['actor' => $actorKey, 'offset' => $offset + $limit]) : null,
        ]);
    }
}
