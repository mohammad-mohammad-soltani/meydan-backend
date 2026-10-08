<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Storage\AttachmentStorage;

use Meydan\Core\Domain\CreatorService;
use Meydan\Core\Domain\MemorialService;
use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Integrations\Channels\Channels;
use Meydan\Core\Uploads\VideoProcessor;
use WP_Comment;
use WP_Post;

final class Serializer
{
    public static function narrative(int|WP_Post $post, ?Viewer $viewer = null): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_narrative' || in_array($post->post_status, ['trash', 'auto-draft'], true) || !UserAccess::visibleNarrative((int) $post->ID)) {
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
            'editorial' => (bool) get_post_meta($id, 'meydan_editorial', true),
            'is_content' => self::linkedContentId($id) !== null,
            'content_id' => self::linkedContentId($id),
            'media_reflections' => $reflections,
            'location' => [
                'province_id' => (int) get_post_meta($id, 'meydan_province_id', true) ?: null,
                'city_id' => (int) get_post_meta($id, 'meydan_city_id', true) ?: null,
            ],
            'quoted_narrative_id' => Quotes::quotedId($id) ?: null,
            'quoted_narrative' => self::quotedNarrative($id),
            'stats' => Stats::narrative($id),
            'viewer_state' => $viewer->isAuthenticated() ? self::narrativeViewerState((int) $viewer->userId, $id) + [
                'can_delete' => (int) $post->post_author === $viewer->userId || current_user_can('moderate_meydan_narratives') || current_user_can('manage_options'),
            ] : null,
        ];
    }

    /**
     * The narrative a quote embeds, in a compact one-level shape. A quoted
     * narrative that was deleted or is hidden from viewers comes back as an
     * `unavailable` stub so the client can render a placeholder in its place.
     */
    private static function quotedNarrative(int $quoteId): ?array
    {
        $quotedId = Quotes::quotedId($quoteId);
        if ($quotedId <= 0) {
            return null;
        }
        $post = get_post($quotedId);
        if (
            !$post
            || $post->post_type !== 'meydan_narrative'
            || $post->post_status !== 'publish'
            || !UserAccess::visibleNarrative($quotedId)
        ) {
            return ['id' => $quotedId, 'unavailable' => true];
        }
        $attachments = get_post_meta($quotedId, 'meydan_attachments', true);
        $attachments = is_array($attachments) ? array_values(array_filter($attachments, 'is_array')) : [];
        usort($attachments, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        return [
            'id' => $quotedId,
            'unavailable' => false,
            'author' => Actor::fromNarrative($quotedId),
            'body' => $post->post_content,
            'published_at' => self::date($post->post_date_gmt),
            'attachments' => array_values(array_map([self::class, 'attachment'], $attachments)),
        ];
    }

    /**
     * Everything about an attachment that comes from the media file itself (URL, mime, dimensions,
     * poster, size) is the same for every narrative that shows it, so it is built once and cached.
     * Item-specific fields (caption, label, order, a client-supplied duration) are added per call.
     */
    public static function attachment(array $item): array
    {
        $mediaId = (int) ($item['media_id'] ?? $item['id'] ?? 0);
        $itemDuration = isset($item['duration']) ? (float) $item['duration'] : null;
        $cacheKey = $mediaId > 0 ? $mediaId . ':' . ($itemDuration ?? '') : '';
        $media = $cacheKey !== '' ? wp_cache_get($cacheKey, 'meydan_media') : false;
        if (!is_array($media)) {
            $media = self::mediaFacts($item, $mediaId, $itemDuration);
            if ($cacheKey !== '') {
                // Short TTL: video posters can appear after the first read (poster backfill).
                wp_cache_set($cacheKey, $media, 'meydan_media', 600);
            }
        }

        return [
            'id' => $mediaId,
            'type' => $media['type'],
            'mime_type' => $media['mime'],
            'filename' => $media['filename'],
            'url' => $media['url'],
            'poster_url' => $media['poster'],
            'thumbnail_url' => $media['poster'],
            'size' => $media['size'],
            'width' => $media['width'],
            'height' => $media['height'],
            'duration' => $media['duration'],
            'caption' => isset($item['caption']) ? (string) $item['caption'] : null,
            'label' => isset($item['label']) ? (string) $item['label'] : null,
            'order' => (int) ($item['order'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private static function mediaFacts(array $item, int $mediaId, ?float $itemDuration): array
    {
        $url = $mediaId ? (string) wp_get_attachment_url($mediaId) : (string) ($item['url'] ?? '');
        $path = $mediaId ? AttachmentStorage::localPath($mediaId) : '';
        $mime = $mediaId ? (string) get_post_mime_type($mediaId) : (string) ($item['mime_type'] ?? '');
        $metadata = $mediaId ? (array) wp_get_attachment_metadata($mediaId) : [];
        $type = self::mediaType($mime, $url);

        $width = isset($metadata['width']) ? (int) $metadata['width'] : null;
        $height = isset($metadata['height']) ? (int) $metadata['height'] : null;
        $duration = $itemDuration;
        $poster = null;

        // Videos also expose a still frame and their real length so a card never
        // has to touch the video file to render.
        if ($type === 'video') {
            $video = VideoProcessor::describe($mediaId, $url, $path, $metadata, $item);
            $poster = $video['poster_url'];
            $duration = $video['duration'];
            $width = $video['width'];
            $height = $video['height'];
        }

        return [
            'type' => $type,
            'mime' => $mime,
            'filename' => $path ? wp_basename($path) : wp_basename((string) parse_url($url, PHP_URL_PATH)),
            'url' => $url,
            'poster' => $poster,
            'size' => ($path && is_file($path)) ? (int) filesize($path) : (int) ($metadata['filesize'] ?? $item['size'] ?? 0),
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
        ];
    }

    public static function content(int|WP_Post $post, ?Viewer $viewer = null): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_content' || $post->post_status !== 'publish') {
            return null;
        }
        $id = (int) $post->ID;
        $sourceNarrative = (int) get_post_meta($id, 'meydan_source_narrative_id', true);
        if ($sourceNarrative > 0 && !UserAccess::visibleNarrative($sourceNarrative)) return null;
        $producerType = (string) get_post_meta($id, 'meydan_producer_actor_type', true);
        $producerId = (int) get_post_meta($id, 'meydan_producer_actor_id', true);
        if (($producerType === 'user' && !UserAccess::visibleUser($producerId)) || (EntityKinds::isEntityActorType($producerType) && !UserAccess::visibleEntity($producerId))) return null;
        $attachments = array_values(array_filter(
            (array) get_post_meta($id, 'meydan_attachments', true),
            'is_array'
        ));
        usort($attachments, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        $isUser = (bool) get_post_meta($id, 'meydan_is_user', true);
        $userId = (int) get_post_meta($id, 'meydan_user_id', true);
        $creatorId = (int) get_post_meta($id, 'meydan_creator_id', true);
        $coverId = (int) get_post_meta($id, 'meydan_media_cover', true);
        $attachedMedia = array_values(array_map(static function (array $item): array {
            $attachment = self::attachment($item);
            return [
                'media_id' => (int) $attachment['id'],
                'media_title' => (string) ($item['media_title'] ?? $item['label'] ?? ''),
                'media_subtitle' => (string) ($item['media_subtitle'] ?? $item['caption'] ?? ''),
                'media_mime_type' => (string) $attachment['mime_type'],
                'media_size' => (int) $attachment['size'],
            ];
        }, $attachments));
        $categories = wp_get_post_terms($id, 'meydan_content_category');
        return [
            'id' => $id,
            'title' => get_the_title($post),
            'excerpt' => $post->post_excerpt,
            'body' => $post->post_content,
            'is_user' => $isUser,
            'user_id' => $isUser && $userId > 0 ? $userId : null,
            'creator_id' => !$isUser && $creatorId > 0 ? $creatorId : null,
            'content_type' => (string) get_post_meta($id, 'meydan_content_type', true) ?: null,
            'view_counts' => Stats::content($id)['views'],
            'attached_media' => $attachedMedia,
            'media_cover' => $coverId > 0 ? $coverId : null,
            'media_cover_url' => $coverId > 0 ? (wp_get_attachment_url($coverId) ?: null) : null,
            'time' => self::isoMeta((string) get_post_meta($id, 'meydan_time', true)),
            'created_at' => self::date($post->post_date_gmt),
            'source_narrative_id' => $sourceNarrative > 0 ? $sourceNarrative : null,
            'format' => (string) get_post_meta($id, 'meydan_format', true) ?: 'mixed',
            'category' => $categories && !is_wp_error($categories) ? ['id' => $categories[0]->term_id, 'name' => $categories[0]->name, 'slug' => $categories[0]->slug] : null,
            'attachments' => array_values(array_map([self::class, 'attachment'], $attachments)),
            'creators' => self::contentCreators($id),
            'producer' => self::contentProducer($id),
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
            'cities' => array_values(array_filter(array_map('intval', (array) get_post_meta($id, 'meydan_cities', true)))),
            'social_links' => array_values(array_filter((array) get_post_meta($id, 'meydan_social_links', true), 'is_array')),
        ];
    }

    /**
     * A speaker profile, backed by the user account itself.
     *
     * A speaker holds the `meydan_speaker` role and its profile lives in user
     * meta; there is no speaker post. Field names mirror `creator()` so
     * existing clients see the same shape.
     */
    public static function speaker(int $userId): ?array
    {
        if ($userId <= 0 || !Actor::isSpeaker($userId) || !UserAccess::visibleUser($userId)) {
            return null;
        }
        $user = get_userdata($userId);
        return [
            'id' => $userId,
            'name' => (string) get_user_meta($userId, 'meydan_full_name', true) ?: ($user?->display_name ?: 'سخنران'),
            'role' => (string) get_user_meta($userId, 'meydan_role', true),
            'bio' => (string) get_user_meta($userId, 'meydan_about', true),
            'avatar_url' => Actor::avatarUrl((int) get_user_meta($userId, 'meydan_avatar_media_id', true)),
            'verified' => (bool) get_user_meta($userId, 'meydan_verified', true),
            'cities' => array_values(array_filter(array_map('intval', (array) get_user_meta($userId, 'meydan_cities', true)))),
            'social_links' => array_values(array_filter((array) get_user_meta($userId, 'meydan_social_links', true), 'is_array')),
            'categories' => SpeakerService::categoriesOf($userId),
            'eitaa_channel' => Channels::value($userId, 'eitaa'),
            'bale_channel' => Channels::value($userId, 'bale'),
            // A speaker *is* the account, so this is the same user id.
            'user_id' => $userId,
        ];
    }

    public static function mediaOutlet(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== 'meydan_media_outlet' || $post->post_status !== 'publish') {
            return null;
        }
        $id = (int) $post->ID;
        return [
            'id' => $id,
            'name' => get_the_title($post),
            'avatar_url' => Actor::avatarUrl((int) get_post_meta($id, 'meydan_avatar_media_id', true)),
            'website' => (string) get_post_meta($id, 'meydan_website', true),
            'bale' => (string) get_post_meta($id, 'meydan_bale', true),
            'eitaa' => (string) get_post_meta($id, 'meydan_eitaa', true),
        ];
    }

    /** A square only; other entity kinds are not squares. */
    public static function square(int|WP_Post $post, bool $includeNarrativeCount = true): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== EntityKinds::postType(EntityKinds::SQUARE)) return null;
        return self::entity($post, $includeNarrativeCount);
    }

    /** Public profile payload of an entity of any kind. Only squares carry location and schedule. */
    public static function entity(int|WP_Post $post, bool $includeNarrativeCount = true): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        $kind = $post ? EntityKinds::kindForPostType((string) $post->post_type) : null;
        if (!$post || $kind === null || in_array($post->post_status, ['trash', 'auto-draft'], true) || !UserAccess::visibleEntity((int) $post->ID)) {
            return null;
        }
        $id = (int) $post->ID;
        global $wpdb;
        $hasLocation = EntityKinds::hasLocation($kind);
        $geo = $hasLocation ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id = %d", $id), ARRAY_A) : null;
        return [
            'id' => $id,
            'kind' => $kind,
            'handle' => ((($ownerForHandle = Actor::squareOwnerUserId($id)) > 0) ? Handles::ofUser($ownerForHandle) : '') ?: (string) get_post_meta($id, 'meydan_handle', true),
            'name' => Actor::squareDisplayName($id),
            'description' => (string) get_user_meta(Actor::squareOwnerUserId($id), 'meydan_about', true) ?: $post->post_content,
            'avatar_url' => Actor::squareAvatarUrl($id),
            'cover_url' => Actor::squareCoverUrl($id),
            // Squares are always ticked; every other kind is ticked once the admin approved it.
            'verified' => $kind === EntityKinds::SQUARE ? true : (bool) get_post_meta($id, 'meydan_verified', true),
            'approval_status' => (string) get_post_meta($id, 'meydan_approval_status', true) ?: 'pending_verification',
            'eitaa_channel' => Channels::value(Actor::squareOwnerUserId($id), 'eitaa'),
            'bale_channel' => Channels::value(Actor::squareOwnerUserId($id), 'bale'),
            'location' => $geo ? [
                'province_id' => (int) $geo['province_id'],
                'city_id' => (int) $geo['city_id'],
                'address' => (string) $geo['address'],
                'latitude' => (float) $geo['latitude'],
                'longitude' => (float) $geo['longitude'],
            ] : null,
            'schedule' => $hasLocation ? self::squareSchedule($id) : [],
            'stats' => [
                'narratives' => $includeNarrativeCount ? self::squareNarrativeCount($id) : null,
            ],
        ];
    }

    /**
     * Full admin-panel profile of a یادبود (memorial) account: the generic
     * entity fields plus its three memorial-specific objects (biography,
     * timeline, frames gallery). Biography is the full `post_content`, not
     * the truncated/owner-overridable `description` that `entity()` returns.
     */
    public static function memorial(int|WP_Post $post): ?array
    {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!$post || $post->post_type !== EntityKinds::postType(EntityKinds::MEMORIAL)) return null;
        $data = self::entity($post, false);
        if (!$data) return null;
        $id = (int) $post->ID;
        $data['biography'] = (string) $post->post_content;
        $data['birth_date'] = (string) get_post_meta($id, 'meydan_birth_date', true);
        $data['death_date'] = (string) get_post_meta($id, 'meydan_death_date', true);
        $data['timeline'] = MemorialService::timeline($id);
        $data['frames'] = array_map(static function (array $frame): array {
            $frame['url'] = Actor::avatarUrl((int) $frame['media_id']);
            return $frame;
        }, MemorialService::frames($id));
        unset($data['description'], $data['schedule'], $data['eitaa_channel'], $data['bale_channel']);
        return $data;
    }

    private static function squareNarrativeCount(int $squareId): int
    {
        $key = 'meydan_square_narratives_' . $squareId;
        $cached = get_transient($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $ownerId = Actor::squareOwnerUserId($squareId);
        $count = $ownerId > 0 ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'meydan_narrative' AND post_status = 'publish'",
            $ownerId
        )) : 0;
        set_transient($key, $count, MINUTE_IN_SECONDS);
        return $count;
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
            $joined = (int) $post->post_author === $viewer->userId
                || (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND user_id=%d AND status='active' LIMIT 1", $post->ID, $viewer->userId));
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
            'work_id' => ($workId = (int) get_post_meta($post->ID, 'meydan_work_id', true)) > 0 ? (string) $workId : null,
            'work_closed' => (bool) get_post_meta($post->ID, 'meydan_work_closed', true),
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
        if (($authorId && !UserAccess::visibleUser($authorId)) || !UserAccess::visibleNarrative((int) $comment->comment_post_ID)) return null;
        return [
            'id' => (int) $comment->comment_ID,
            'narrative_id' => (int) $comment->comment_post_ID,
            'author' => $authorId ? Actor::forUser($authorId) : null,
            'body' => $comment->comment_content,
            'parent_id' => (int) $comment->comment_parent ?: null,
            'reply_count' => (int) get_comments(['parent' => $comment->comment_ID, 'type' => 'meydan_comment', 'status' => 'approve', 'count' => true]),
            'likes' => self::commentLikeState((int) $comment->comment_ID)['likes'],
            'viewer_state' => (Viewer::current()->userId ?? 0) > 0 ? ['liked' => self::commentLikeState((int) $comment->comment_ID)['liked']] : null,
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

    /** @var array<int,array<int,array<string,mixed>>> request-local rows, filled in bulk by primeMediaReflections(). */
    private static array $reflectionRows = [];

    /** One query for a whole page of narratives instead of one per narrative. */
    public static function primeMediaReflections(array $narrativeIds): void
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $narrativeIds), static fn(int $id): bool => $id > 0 && !isset(self::$reflectionRows[$id]))));
        if (!$ids) return;
        foreach ($ids as $id) self::$reflectionRows[$id] = [];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE narrative_id IN ($placeholders) AND status = 'published' ORDER BY position ASC, published_at DESC, id DESC",
            ...$ids
        ), ARRAY_A);
        foreach ($rows ?: [] as $row) self::$reflectionRows[(int) $row['narrative_id']][] = $row;
    }

    public static function mediaReflections(int $narrativeId): array
    {
        global $wpdb;
        if (isset(self::$reflectionRows[$narrativeId])) {
            $rows = self::$reflectionRows[$narrativeId];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE narrative_id = %d AND status = 'published' ORDER BY position ASC, published_at DESC, id DESC",
                $narrativeId
            ), ARRAY_A);
        }
        return array_map(static function (array $r): array {
            $outletId = (int) ($r['outlet_id'] ?? 0);
            return [
                'id' => (int) $r['id'],
                'narrative_id' => (int) $r['narrative_id'],
                'outlet' => (string) $r['outlet'],
                'outlet_id' => $outletId,
                'outlet_detail' => $outletId ? self::mediaOutlet($outletId) : null,
                'title' => (string) $r['title'],
                'summary' => (string) ($r['summary'] ?? ''),
                'url' => (string) $r['url'],
                'logo_url' => Actor::mediaUrl((int) ($r['logo_media_id'] ?? 0)),
                'published_at' => !empty($r['published_at']) ? self::isoMeta((string) $r['published_at']) : null,
                'status' => (string) $r['status'],
                'position' => (int) $r['position'],
                'source' => (string) ($r['source'] ?? 'manual'),
                'source_narrative_id' => (int) ($r['source_narrative_id'] ?? 0) ?: null,
            ];
        }, $rows ?: []);
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

    private static function contentProducer(int $contentId): ?array
    {
        $type=(string)get_post_meta($contentId,'meydan_producer_actor_type',true);
        $id=(int)get_post_meta($contentId,'meydan_producer_actor_id',true);
        if($type&&$id>0){$actor=Actor::parse($type,$id);if($actor)return $actor;}
        // Directly-created content historically stored its producer in the
        // content_creators relation. Keep producer populated for clients that
        // render the singular producer field, while preserving creators[].
        global $wpdb;
        $creatorId=(int)$wpdb->get_var($wpdb->prepare("SELECT creator_id FROM {$wpdb->prefix}meydan_content_creators WHERE content_id=%d ORDER BY position ASC,creator_id ASC LIMIT 1",$contentId));
        $creator=$creatorId>0?self::creator($creatorId):null;
        if(!$creator)return null;
        $creator['type']='creator';
        $creator['display_name']=(string)($creator['name']??'');
        return $creator;
    }

    private static function linkedContentId(int $narrativeId): ?int
    {
        $id=(int)get_post_meta($narrativeId,'meydan_content_id',true);
        return $id>0&&get_post_type($id)==='meydan_content'&&get_post_status($id)==='publish'?$id:null;
    }

    /** @var array<int,array<int,array{liked:bool,reposted:bool,bookmarked:bool}>> request-local, [userId][narrativeId]. */
    private static array $narrativeStates = [];

    /**
     * Like / repost / bookmark of a whole page of narratives in ONE query.
     * Without it every narrative paid its own lookups for each of the three.
     *
     * @param int[] $narrativeIds
     */
    public static function primeNarrativeStates(array $narrativeIds): void
    {
        $userId = (int) (Viewer::current()->userId ?? 0);
        if ($userId <= 0) return;
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $narrativeIds), static fn(int $id): bool => $id > 0 && !isset(self::$narrativeStates[$userId][$id]))));
        if (!$ids) return;
        foreach ($ids as $id) self::$narrativeStates[$userId][$id] = ['liked' => false, 'reposted' => false, 'bookmarked' => false];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, action FROM {$wpdb->prefix}meydan_interactions
             WHERE user_id = %d AND object_type = 'narrative' AND action IN ('like','repost','bookmark') AND object_id IN ($placeholders)",
            $userId,
            ...$ids
        ), ARRAY_A) ?: [];
        $key = ['like' => 'liked', 'repost' => 'reposted', 'bookmark' => 'bookmarked'];
        foreach ($rows as $row) self::$narrativeStates[$userId][(int) $row['object_id']][$key[$row['action']]] = true;
    }

    /** @var array<int,int> */
    private static array $commentLikeCounts = [];
    /** @var array<int,array<int,bool>> viewer id => comment id => liked */
    private static array $commentLiked = [];

    /**
     * Like totals (and the viewer's own likes) for a page of comments in two
     * queries, so a comment list never costs one query per row.
     *
     * @param int[] $commentIds
     */
    public static function primeCommentLikes(array $commentIds): void
    {
        $userId = (int) (Viewer::current()->userId ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn(int $id): bool => $id > 0 && !isset(self::$commentLikeCounts[$id]))));
        if (!$ids) return;
        global $wpdb;
        foreach ($ids as $id) self::$commentLikeCounts[$id] = 0;
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $table = $wpdb->prefix . 'meydan_interactions';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, COUNT(*) AS likes FROM {$table} WHERE object_type = 'comment' AND action = 'like' AND object_id IN ($marks) GROUP BY object_id",
            ...$ids
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) self::$commentLikeCounts[(int) $row['object_id']] = (int) $row['likes'];
        if ($userId > 0) {
            foreach ($ids as $id) self::$commentLiked[$userId][$id] = false;
            $mine = $wpdb->get_col($wpdb->prepare(
                "SELECT object_id FROM {$table} WHERE user_id = %d AND object_type = 'comment' AND action = 'like' AND object_id IN ($marks)",
                $userId,
                ...$ids
            )) ?: [];
            foreach ($mine as $id) self::$commentLiked[$userId][(int) $id] = true;
        }
    }

    /** A like was just written: the next read of this comment is fresh. */
    public static function forgetCommentLikes(int $commentId): void
    {
        unset(self::$commentLikeCounts[$commentId]);
        $userId = (int) (Viewer::current()->userId ?? 0);
        unset(self::$commentLiked[$userId][$commentId]);
    }

    /** @return array{likes:int,liked:bool} */
    private static function commentLikeState(int $commentId): array
    {
        $userId = (int) (Viewer::current()->userId ?? 0);
        if (!isset(self::$commentLikeCounts[$commentId]) || ($userId > 0 && !isset(self::$commentLiked[$userId][$commentId]))) {
            unset(self::$commentLikeCounts[$commentId]);
            self::primeCommentLikes([$commentId]);
        }
        return ['likes' => self::$commentLikeCounts[$commentId] ?? 0, 'liked' => $userId > 0 && (self::$commentLiked[$userId][$commentId] ?? false)];
    }

    /** @return array{liked:bool,reposted:bool,bookmarked:bool} */
    private static function narrativeViewerState(int $userId, int $narrativeId): array
    {
        if (!isset(self::$narrativeStates[$userId][$narrativeId])) self::primeNarrativeStates([$narrativeId]);
        return self::$narrativeStates[$userId][$narrativeId] ?? ['liked' => false, 'reposted' => false, 'bookmarked' => false];
    }

    /** A like/repost/bookmark was just written: drop the cached state so the next read is fresh. */
    public static function forgetNarrativeState(int $userId, int $narrativeId): void
    {
        unset(self::$narrativeStates[$userId][$narrativeId]);
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
