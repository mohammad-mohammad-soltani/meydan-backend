<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Comment;
use WP_Post;

final class Serializer
{
    public static function narrative(int|WP_Post $post, ?Viewer $viewer = null): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_narrative' || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            return null;
        }
        $viewer ??= Viewer::current();
        $id = (int) $post->ID;
        $initiativeId = (int) get_post_meta($id, 'meydan_initiative_id', true);
        $reflections = self::mediaReflections($id);
        $attachments = get_post_meta($id, 'meydan_attachments', true);

        $attachments = is_array($attachments)
            ? array_values(array_filter($attachments, 'is_array'))
            : [];

        usort(
            $attachments,
            static fn(array $a, array $b): int =>
                ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0))
        );
        return [
            'id' => $id,
            'author' => Actor::fromNarrative($id),
            'body' => $post->post_content,
            'status' => $post->post_status,
            'published_at' => self::date($post->post_date_gmt),
            'edited_at' => $post->post_modified_gmt !== $post->post_date_gmt ? self::date($post->post_modified_gmt) : null,
            'attachments' => array_values(array_map([self::class, 'attachment'], $attachments)),
            'tags' => wp_get_post_terms($id, 'meydan_narrative_tag', ['fields' => 'names']),
            'initiative' => $initiativeId ? self::initiative($initiativeId) : null,
            'poll' => get_post_meta($id, 'meydan_poll', true) ?: null,
            'is_echo' => (bool) get_post_meta($id, 'meydan_is_echo', true),
            'media_reflections' => $reflections,
            'location' => [
                'province_id' => (int) get_post_meta($id, 'meydan_province_id', true) ?: null,
                'city_id' => (int) get_post_meta($id, 'meydan_city_id', true) ?: null,
            ],
            'stats' => Stats::narrative($id),
            'viewer_state' => $viewer->isAuthenticated() ? [
                'liked' => self::interactionExists($viewer->userId, 'narrative', $id, 'like'),
                'reposted' => self::interactionExists($viewer->userId, 'narrative', $id, 'repost'),
            ] : null,
        ];
    }

    public static function attachment(array $item): array
    {
        $mediaId = (int) ($item['media_id'] ?? $item['id'] ?? 0);
        $url = $mediaId ? (string) wp_get_attachment_url($mediaId) : (string) ($item['url'] ?? '');
        $path = $mediaId ? get_attached_file($mediaId) : '';
        $mime = $mediaId ? (string) get_post_mime_type($mediaId) : (string) ($item['mime_type'] ?? '');
        $metadata = $mediaId ? wp_get_attachment_metadata($mediaId) : [];
        return [
            'id' => $mediaId,
            'type' => self::mediaType($mime, $url),
            'mime_type' => $mime,
            'filename' => $path ? wp_basename($path) : wp_basename((string) parse_url($url, PHP_URL_PATH)),
            'url' => $url,
            'size' => ($path && is_file($path)) ? (int) filesize($path) : (int) ($item['size'] ?? 0),
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : null,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : null,
            'duration' => isset($item['duration']) ? (float) $item['duration'] : null,
            'caption' => isset($item['caption']) ? (string) $item['caption'] : null,
            'label' => isset($item['label']) ? (string) $item['label'] : null,
            'order' => (int) ($item['order'] ?? 0),
        ];
    }

    public static function content(int|WP_Post $post, ?Viewer $viewer = null): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_content' || $post->post_status !== 'publish') {
            return null;
        }
        $id = (int) $post->ID;
        $attachments = array_values(array_filter(
            (array) get_post_meta($id, 'meydan_attachments', true),
            'is_array'
        ));
        usort($attachments, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        $categories = wp_get_post_terms($id, 'meydan_content_category');
        return [
            'id' => $id,
            'title' => get_the_title($post),
            'excerpt' => $post->post_excerpt,
            'body' => $post->post_content,
            'format' => (string) get_post_meta($id, 'meydan_format', true) ?: 'mixed',
            'category' => $categories && !is_wp_error($categories) ? ['id' => $categories[0]->term_id, 'name' => $categories[0]->name, 'slug' => $categories[0]->slug] : null,
            'attachments' => array_values(array_map([self::class, 'attachment'], $attachments)),
            'creators' => self::contentCreators($id),
            'tags' => wp_get_post_terms($id, 'meydan_content_tag', ['fields' => 'names']),
            'usage_note' => (string) get_post_meta($id, 'meydan_usage_note', true),
            'featured' => (bool) get_post_meta($id, 'meydan_featured', true),
            'published_at' => self::date($post->post_date_gmt),
            'stats' => Stats::content($id),
            'viewer_state' => ($viewer ?? Viewer::current())->isAuthenticated() ? [
                'bookmarked' => self::interactionExists(($viewer ?? Viewer::current())->userId, 'content', $id, 'bookmark'),
            ] : null,
        ];
    }

    public static function creator(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_creator' || $post->post_status !== 'publish') {
            return null;
        }
        $id = (int) $post->ID;
        $types = wp_get_post_terms($id, 'meydan_creator_type', ['fields' => 'slugs']);
        return [
            'id' => $id,
            'name' => get_the_title($post),
            'types' => is_wp_error($types) ? [] : array_values($types),
            'role' => (string) get_post_meta($id, 'meydan_role', true),
            'bio' => $post->post_content,
            'avatar_url' => Actor::avatarUrl((int) get_post_meta($id, 'meydan_avatar_media_id', true)),
            'verified' => (bool) get_post_meta($id, 'meydan_verified', true),
            'cities' => array_values(array_map('intval', (array) get_post_meta($id, 'meydan_cities', true))),
            'social_links' => (array) get_post_meta($id, 'meydan_social_links', true),
        ];
    }

    public static function square(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_square' || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            return null;
        }
        $id = (int) $post->ID;
        global $wpdb;
        $geo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id = %d", $id), ARRAY_A);
        return [
            'id' => $id,
            'name' => Actor::squareDisplayName($id),
            'description' => (string) get_user_meta(Actor::squareOwnerUserId($id), 'meydan_about', true) ?: $post->post_content,
            'avatar_url' => Actor::squareAvatarUrl($id),
            'cover_url' => Actor::squareCoverUrl($id),
            'verified' => true,
            'approval_status' => (string) get_post_meta($id, 'meydan_approval_status', true) ?: 'pending_verification',
            'location' => $geo ? [
                'province_id' => (int) $geo['province_id'],
                'city_id' => (int) $geo['city_id'],
                'address' => (string) $geo['address'],
                'latitude' => (float) $geo['latitude'],
                'longitude' => (float) $geo['longitude'],
            ] : null,
            'schedule' => self::squareSchedule($id),
            'stats' => [
                'narratives' => (int) (new \WP_Query(['post_type' => 'meydan_narrative', 'post_status' => 'publish', 'meta_query' => [['key' => 'meydan_author_actor_type', 'value' => 'square'], ['key' => 'meydan_author_actor_id', 'value' => $id]], 'fields' => 'ids', 'posts_per_page' => 1]))->found_posts,
            ],
        ];
    }

    public static function initiative(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_initiative' || $post->post_status !== 'publish') {
            return null;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id = %d AND status = 'active'", $post->ID));
        $viewer = Viewer::current();
        $joined = false;
        if ($viewer->isAuthenticated()) {
            $joined = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND user_id=%d AND status='active' LIMIT 1", $post->ID, $viewer->userId));
        }
        return [
            'id' => (int) $post->ID,
            'title' => get_the_title($post),
            'description' => $post->post_content,
            'cta_label' => (string) get_post_meta($post->ID, 'meydan_cta_label', true),
            'starts_at' => self::isoMeta((string) get_post_meta($post->ID, 'meydan_starts_at', true)),
            'ends_at' => self::isoMeta((string) get_post_meta($post->ID, 'meydan_ends_at', true)),
            'status' => (string) get_post_meta($post->ID, 'meydan_status', true) ?: 'active',
            'allow_guest_join' => (bool) get_post_meta($post->ID, 'meydan_allow_guest_join', true),
            'participant_count' => $count,
            'viewer_state' => $viewer->isAuthenticated() ? ['joined' => $joined] : null,
        ];
    }

    public static function campaign(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_campaign' || $post->post_status !== 'publish') {
            return null;
        }
        return [
            'id' => (int) $post->ID,
            'title' => get_the_title($post),
            'description' => $post->post_content,
            'starts_at' => self::isoMeta((string) get_post_meta($post->ID, 'meydan_starts_at', true)),
            'ends_at' => self::isoMeta((string) get_post_meta($post->ID, 'meydan_ends_at', true)),
            'current' => (bool) get_post_meta($post->ID, 'meydan_current', true),
            'labels' => (array) get_post_meta($post->ID, 'meydan_labels', true),
            'linked_content' => array_values(array_map('intval', (array) get_post_meta($post->ID, 'meydan_linked_content', true))),
            'schedule' => (array) get_post_meta($post->ID, 'meydan_schedule', true),
            'order' => (int) get_post_meta($post->ID, 'meydan_order', true),
        ];
    }

    public static function comment(int|WP_Comment $comment): ?array
    {
        $comment = $comment instanceof WP_Comment ? $comment : get_comment($comment);
        if (!$comment || $comment->comment_type !== 'meydan_comment') {
            return null;
        }
        $authorId = (int) $comment->user_id;
        return [
            'id' => (int) $comment->comment_ID,
            'narrative_id' => (int) $comment->comment_post_ID,
            'author' => $authorId ? Actor::forUser($authorId) : null,
            'body' => $comment->comment_content,
            'parent_id' => (int) $comment->comment_parent ?: null,
            'reply_count' => (int) get_comments(['parent' => $comment->comment_ID, 'type' => 'meydan_comment', 'status' => 'approve', 'count' => true]),
            'created_at' => self::date($comment->comment_date_gmt),
            'edited_at' => ($edited = get_comment_meta($comment->comment_ID, 'meydan_edited_at', true)) ? self::isoMeta((string) $edited) : null,
        ];
    }

    public static function notification(array|object $row): array
    {
        $r = (array) $row;
        return [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'actor' => (!empty($r['actor_type']) && !empty($r['actor_id'])) ? Actor::parse((string) $r['actor_type'], (int) $r['actor_id']) : null,
            'entity_type' => $r['entity_type'] ?: null,
            'entity_id' => isset($r['entity_id']) ? (int) $r['entity_id'] : null,
            'parent_entity_type' => $r['parent_entity_type'] ?: null,
            'parent_entity_id' => isset($r['parent_entity_id']) ? (int) $r['parent_entity_id'] : null,
            'title' => (string) $r['title'],
            'body' => (string) $r['body'],
            'deep_link' => $r['deep_link'] ?: null,
            'payload' => !empty($r['payload_json']) ? json_decode((string) $r['payload_json'], true) : null,
            'created_at' => self::isoMeta((string) $r['created_at']),
            'read_at' => !empty($r['read_at']) ? self::isoMeta((string) $r['read_at']) : null,
            'archived_at' => !empty($r['archived_at']) ? self::isoMeta((string) $r['archived_at']) : null,
        ];
    }

    public static function mediaReflections(int $narrativeId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE narrative_id = %d AND status = 'published' ORDER BY position ASC, published_at DESC, id DESC",
            $narrativeId
        ), ARRAY_A);
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'narrative_id' => (int) $r['narrative_id'],
            'outlet' => (string) $r['outlet'],
            'title' => (string) $r['title'],
            'summary' => (string) ($r['summary'] ?? ''),
            'url' => (string) $r['url'],
            'logo_url' => Actor::mediaUrl((int) ($r['logo_media_id'] ?? 0)),
            'published_at' => !empty($r['published_at']) ? self::isoMeta((string) $r['published_at']) : null,
            'status' => (string) $r['status'],
            'position' => (int) $r['position'],
        ], $rows ?: []);
    }

    public static function squareSchedule(int $squareId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE square_id = %d AND status = 'published' ORDER BY starts_at ASC, position ASC",
            $squareId
        ), ARRAY_A);
        return array_map([self::class, 'scheduleRow'], $rows ?: []);
    }

    public static function scheduleRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'square_id' => (int) $r['square_id'],
            'title' => (string) $r['title'],
            'description' => (string) ($r['description'] ?? ''),
            'starts_at' => self::isoMeta((string) $r['starts_at']),
            'ends_at' => !empty($r['ends_at']) ? self::isoMeta((string) $r['ends_at']) : null,
            'location_label' => $r['location_label'] ?: null,
            'status' => (string) $r['status'],
            'position' => (int) $r['position'],
        ];
    }

    private static function contentCreators(int $contentId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT creator_id, position, role_label FROM {$wpdb->prefix}meydan_content_creators WHERE content_id = %d ORDER BY position ASC, creator_id ASC",
            $contentId
        ), ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $creator = self::creator((int) $row['creator_id']);
            if ($creator) {
                $creator['position'] = (int) $row['position'];
                $creator['role_label'] = $row['role_label'] ?: null;
                $out[] = $creator;
            }
        }
        return $out;
    }

    private static function interactionExists(?int $userId, string $objectType, int $objectId, string $action): bool
    {
        if (!$userId) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}meydan_interactions WHERE user_id = %d AND object_type = %s AND object_id = %d AND action = %s LIMIT 1",
            $userId,
            $objectType,
            $objectId,
            $action
        ));
    }

    private static function mediaType(string $mime, string $url): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_contains($mime, 'pdf') || str_contains($mime, 'word') || str_contains($mime, 'presentation')) return 'document';
        if (preg_match('#^https?://#i', $url) && $mime === '') return 'link';
        return 'file';
    }

    private static function date(string $gmt): ?string
    {
        if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
            return null;
        }
        return gmdate(DATE_ATOM, strtotime($gmt . ' UTC'));
    }

    private static function isoMeta(string $date): ?string
    {
        if ($date === '') return null;
        $ts = strtotime($date);
        return $ts ? gmdate(DATE_ATOM, $ts) : null;
    }
}
