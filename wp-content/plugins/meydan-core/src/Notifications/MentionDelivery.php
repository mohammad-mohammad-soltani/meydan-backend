<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Mentions;

/**
 * Notifies the people `@mentioned` in a narrative or a comment. Each post
 * remembers whom it already notified, so an edit only reaches newly added
 * mentions. Narratives are delivered from a background job once they are
 * published (which also covers scheduled posts), never inside the request.
 */
final class MentionDelivery
{
    public const HOOK = 'meydan_narrative_mention_notify';
    private const NOTIFIED = 'meydan_mentions_notified';

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action(self::HOOK, [self::class, 'deliverNarrative'], 10, 1);
    }

    public static function onTransition(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($post->post_type !== 'meydan_narrative' || $newStatus !== 'publish' || $oldStatus === 'publish') return;
        if (!wp_next_scheduled(self::HOOK, [(int) $post->ID])) {
            wp_schedule_single_event(time() + 5, self::HOOK, [(int) $post->ID]);
        }
    }

    public static function deliverNarrative(int $narrativeId): void
    {
        $post = get_post($narrativeId);
        if (!$post || $post->post_type !== 'meydan_narrative' || $post->post_status !== 'publish') return;
        $type = (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true);
        $actorId = (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true);
        if ($type === '' || $actorId <= 0) return;
        $authorUser = (int) $post->post_author;
        $fresh = self::fresh('post', $narrativeId, (string) $post->post_content, $authorUser);
        $service = new NotificationService();
        foreach ($fresh as $userId) {
            $service->fromTemplate($userId, 'mention', $type, $actorId, 'narrative', $narrativeId, '/posts/' . $narrativeId, 'mention:narrative:' . $narrativeId, false);
        }
    }

    /** Call after a comment is created or edited. */
    public static function deliverComment(int $commentId): void
    {
        $comment = get_comment($commentId);
        if (!$comment || $comment->comment_type !== 'meydan_comment') return;
        $authorUser = (int) $comment->user_id;
        if ($authorUser <= 0) return;
        $narrativeId = (int) $comment->comment_post_ID;
        $type = Actor::actorType($authorUser);
        $actorId = $type === 'user' ? $authorUser : Actor::entityId($authorUser);
        if ($actorId <= 0) return;
        $fresh = self::fresh('comment', $commentId, (string) $comment->comment_content, $authorUser);
        $service = new NotificationService();
        foreach ($fresh as $userId) {
            $service->fromTemplate($userId, 'comment_mention', $type, $actorId, 'comment', $commentId, '/posts/' . $narrativeId . '#comment-' . $commentId, 'mention:comment:' . $commentId, false);
        }
    }

    /**
     * Mentioned user ids not yet notified; records them as notified.
     *
     * @return int[]
     */
    private static function fresh(string $kind, int $id, string $body, int $authorUser): array
    {
        $get = $kind === 'post' ? 'get_post_meta' : 'get_comment_meta';
        $set = $kind === 'post' ? 'update_post_meta' : 'update_comment_meta';
        $seen = array_map('intval', (array) $get($id, self::NOTIFIED, true));
        $mentioned = array_values(array_diff(Mentions::userIds($body), [$authorUser]));
        $fresh = array_values(array_diff($mentioned, $seen));
        if ($fresh) {
            $set($id, self::NOTIFIED, array_values(array_unique(array_merge($seen, $fresh))));
        }
        return $fresh;
    }
}
