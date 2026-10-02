<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Profile parts of the reference design that the API did not have yet: the
 * pinned post, the «پسندها» and «برجسته‌ها» tabs, and «دنبال‌شده توسط».
 *
 * Every query here is keyed by an indexed column: `post_author`, the
 * interactions table's `user_action` / `object_lookup` keys, or the stats
 * table's primary key.
 */
final class ProfileExtras
{
    public const PIN_META = 'meydan_pinned_narrative_id';

    /**
     * Follower and following counts of an actor; both use the interactions
     * table's indexes (object_lookup and user_action).
     *
     * Also carries `joined_at` (account registration) for «عضویت از …».
     *
     * @return array{followers:int,following:int,joined_at:?string}
     */
    public static function social(string $objectType, int $objectId, int $ownerUserId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_interactions';
        $owner = $ownerUserId > 0 ? get_userdata($ownerUserId) : false;
        return [
            'joined_at' => $owner && $owner->user_registered ? gmdate(DATE_ATOM, strtotime($owner->user_registered . ' UTC')) : null,
            'followers' => $objectId > 0 ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE object_type=%s AND object_id=%d AND action='follow'", $objectType, $objectId)) : 0,
            'following' => $ownerUserId > 0 ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND action='follow'", $ownerUserId)) : 0,
        ];
    }

    /** The account's pinned narrative id, or 0 when none (or it is gone). */
    public static function pinnedId(int $ownerUserId): int
    {
        $id = (int) get_user_meta($ownerUserId, self::PIN_META, true);
        if ($id <= 0) return 0;
        $post = get_post($id);
        return $post && $post->post_type === 'meydan_narrative' && $post->post_status === 'publish' && (int) $post->post_author === $ownerUserId ? $id : 0;
    }

    /** Pins one of the viewer's own published narratives; `0` unpins. */
    public static function setPinned(int $ownerUserId, int $narrativeId): true|WP_Error
    {
        if ($narrativeId <= 0) {
            delete_user_meta($ownerUserId, self::PIN_META);
            return true;
        }
        $post = get_post($narrativeId);
        if (!$post || $post->post_type !== 'meydan_narrative' || $post->post_status !== 'publish' || (int) $post->post_author !== $ownerUserId) {
            return new WP_Error('validation_failed', 'فقط روایت‌های خودتان را می‌توانید سنجاق کنید.', ['status' => 422, 'fields' => ['narrative_id' => 'invalid']]);
        }
        update_user_meta($ownerUserId, self::PIN_META, $narrativeId);
        return true;
    }

    /**
     * Adds the pinned narrative to the first page of a profile list (`meta.pinned`),
     * so the profile shows it without another request.
     */
    public static function withPinned(WP_REST_Response $response, int $ownerUserId, WP_REST_Request $request): WP_REST_Response
    {
        if ($response->get_status() >= 400 || trim((string) $request->get_param('cursor')) !== '') return $response;
        $pinnedId = self::pinnedId($ownerUserId);
        $body = $response->get_data();
        $body['meta']['pinned'] = $pinnedId > 0 ? Serializer::narrative($pinnedId) : null;
        $response->set_data($body);
        return $response;
    }

    /** Narratives the account liked, newest like first, with an offset cursor. */
    public static function liked(int $ownerUserId, WP_REST_Request $request, string $actorKey): WP_REST_Response
    {
        global $wpdb;
        [$limit, $offset] = self::paging($request, $actorKey);
        if ($limit === 0) return Response::error('invalid_cursor', 'صفحه معتبر نیست.', 400);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT i.object_id FROM {$wpdb->prefix}meydan_interactions i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.object_id AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
             WHERE i.user_id = %d AND i.object_type = 'narrative' AND i.action = 'like'
             ORDER BY i.id DESC LIMIT %d OFFSET %d",
            $ownerUserId,
            $limit + 1,
            $offset
        )) ?: [];
        return self::page(array_map('intval', $ids), $limit, $offset, $actorKey);
    }

    /** The account's most engaging narratives (likes, reposts, quotes, comments). */
    public static function highlights(int $ownerUserId): WP_REST_Response
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}meydan_narrative_stats s ON s.narrative_id = p.ID
             WHERE p.post_author = %d AND p.post_type = 'meydan_narrative' AND p.post_status = 'publish'
               AND (s.likes + s.reposts + s.comments) > 0
             ORDER BY (s.likes + 2 * s.reposts + s.comments) DESC, p.post_date_gmt DESC LIMIT 20",
            $ownerUserId
        )) ?: [];
        return self::page(array_map('intval', $ids), 20, 0, '');
    }

    /**
     * Accounts the viewer follows that also follow this actor: the first
     * three for the avatar row, plus how many in total.
     *
     * @return array{count:int,actors:array<int,array<string,mixed>>}
     */
    public static function followedBy(int $viewerId, string $type, int $id): array
    {
        global $wpdb;
        if ($viewerId <= 0) return ['count' => 0, 'actors' => []];
        $table = $wpdb->prefix . 'meydan_interactions';
        // Followers of the target (object_lookup) whose user the viewer follows (uniq_interaction).
        $from = "FROM {$table} f INNER JOIN {$table} v ON v.user_id = %d AND v.object_type = 'user' AND v.object_id = f.user_id AND v.action = 'follow'
                 WHERE f.object_type = %s AND f.object_id = %d AND f.action = 'follow' AND f.user_id <> %d";
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", $viewerId, $type, $id, $viewerId));
        if ($count === 0) return ['count' => 0, 'actors' => []];
        $userIds = $wpdb->get_col($wpdb->prepare("SELECT f.user_id {$from} ORDER BY f.id DESC LIMIT 3", $viewerId, $type, $id, $viewerId)) ?: [];
        $actors = array_values(array_filter(array_map(static fn($uid) => Actor::forUser((int) $uid), $userIds)));
        return ['count' => $count, 'actors' => $actors];
    }

    /** @return array{0:int,1:int} limit and offset; limit 0 means a bad cursor */
    private static function paging(WP_REST_Request $request, string $actorKey): array
    {
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));
        $raw = trim((string) $request->get_param('cursor'));
        if ($raw === '') return [$limit, 0];
        $cursor = Cursor::decode($raw);
        if (($cursor['actor'] ?? null) !== $actorKey || !isset($cursor['offset']) || !is_int($cursor['offset']) || $cursor['offset'] < 0) return [0, 0];
        return [$limit, $cursor['offset']];
    }

    /** @param int[] $ids */
    private static function page(array $ids, int $limit, int $offset, string $actorKey): WP_REST_Response
    {
        $hasMore = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        if ($ids) {
            _prime_post_caches($ids, false, true);
            Serializer::primeMediaReflections($ids);
        }
        $data = array_values(array_filter(array_map([Serializer::class, 'narrative'], $ids)));
        return Response::ok($data, [
            'next_cursor' => $hasMore && $actorKey !== '' ? Cursor::encode(['actor' => $actorKey, 'offset' => $offset + $limit]) : null,
        ]);
    }
}
