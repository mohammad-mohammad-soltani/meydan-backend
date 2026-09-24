<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

/** A shared cursor contract for own, user, and square profile narratives. */
final class ProfileNarrativePage
{
    public static function list(array $metaQuery, WP_REST_Request $request, string $actorKey)
    {
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        $rawCursor = trim((string) $request->get_param('cursor'));
        $cursor = Cursor::decode($rawCursor);
        if ($rawCursor !== '' && (($cursor['actor'] ?? null) !== $actorKey || !isset($cursor['offset']) || !is_int($cursor['offset']) || $cursor['offset'] < 0)) {
            return Response::error('invalid_cursor', 'صفحهٔ روایت‌ها معتبر نیست.', 400);
        }
        $offset = $rawCursor === '' ? 0 : $cursor['offset'];
        $query = new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => $limit + 1,
            'no_found_rows' => true,
            'offset' => $offset,
            'meta_query' => $metaQuery,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]);
        $hasMore = count($query->posts) > $limit;
        $posts = array_slice($query->posts, 0, $limit);
        $data = array_values(array_filter(array_map([Serializer::class, 'narrative'], $posts)));
        return Response::ok($data, [
            'next_cursor' => $hasMore ? Cursor::encode(['actor' => $actorKey, 'offset' => $offset + $limit]) : null,
        ]);
    }
}
