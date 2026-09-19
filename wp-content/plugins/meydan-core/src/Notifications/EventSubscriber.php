<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use Meydan\Core\Support\Actor;

final class EventSubscriber
{
    public static function narrativeInteraction(string $type, int $narrativeId, int $actorUserId): void
    {
        $recipient = (int) get_post_field('post_author', $narrativeId);
        if ($recipient <= 0 || $recipient === $actorUserId) return;
        $actor = Actor::forUser($actorUserId);
        (new NotificationService())->fromTemplate(
            $recipient,
            $type,
            (string) $actor['type'],
            (int) substr((string) $actor['id'], strpos((string) $actor['id'], '_') + 1),
            'narrative',
            $narrativeId,
            '/posts/' . $narrativeId,
            $type . ':narrative:' . $narrativeId,
            true
        );
    }

    public static function follow(string $targetType, int $targetId, int $actorUserId): void
    {
        $recipient = Actor::ownerUserId($targetType, $targetId);
        if ($recipient <= 0 || $recipient === $actorUserId) return;
        $actor = Actor::forUser($actorUserId);
        $actorKind = (string) ($actor['type'] ?? 'user') === 'square' ? 'square' : 'user';
        $actorRawId = (string) ($actor['id'] ?? 'usr_' . $actorUserId);
        $actorNumericId = (int) substr($actorRawId, (int) strrpos($actorRawId, '_') + 1);
        $profileId = $actorNumericId > 0 ? $actorNumericId : $actorUserId;
        $deepLink = $actorKind === 'square' ? '/square/' . $profileId : '/' . $profileId;
        (new NotificationService())->fromTemplate($recipient, 'follow', $actorKind, $actorUserId, 'actor', $targetId, $deepLink, 'follow:actor:' . $targetType . ':' . $targetId, true);
    }

    public static function comment(int $commentId): void
    {
        $comment = get_comment($commentId);
        if (!$comment) return;
        $authorUser = (int) $comment->user_id;
        $narrativeId = (int) $comment->comment_post_ID;
        $service = new NotificationService();

        if ((int) $comment->comment_parent > 0) {
            $parent = get_comment((int) $comment->comment_parent);
            if ($parent && (int) $parent->user_id > 0 && (int) $parent->user_id !== $authorUser) {
                $service->fromTemplate((int) $parent->user_id, 'comment_reply', 'user', $authorUser, 'comment', $commentId, '/posts/' . $narrativeId . '#comment-' . $commentId, null, false);
            }
        }
        $narrativeOwner = (int) get_post_field('post_author', $narrativeId);
        if ($narrativeOwner > 0 && $narrativeOwner !== $authorUser) {
            $service->fromTemplate($narrativeOwner, 'comment', 'user', $authorUser, 'comment', $commentId, '/posts/' . $narrativeId . '#comment-' . $commentId, null, false);
        }
    }
}
