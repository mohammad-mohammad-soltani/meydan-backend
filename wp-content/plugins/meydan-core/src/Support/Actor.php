<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Actor
{
    public static function forUser(int $userId): array
    {
        $type = (string) get_user_meta($userId, 'meydan_account_type', true);
        if ($type === 'square') {
            $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
            if ($squareId > 0) {
                return self::forSquare($squareId);
            }
        }

        $user = get_userdata($userId);
        return [
            'id' => 'usr_' . $userId,
            'type' => 'user',
            'display_name' => (string) get_user_meta($userId, 'meydan_full_name', true) ?: ($user?->display_name ?: 'کاربر میدان'),
            'avatar_url' => self::avatarUrl((int) get_user_meta($userId, 'meydan_avatar_media_id', true)),
            'verified' => self::isVerifiedUser($userId),
        ];
    }

    public static function forSquare(int $squareId): array
    {
        return [
            'id' => 'sq_' . $squareId,
            'type' => 'square',
            'display_name' => self::squareDisplayName($squareId),
            'avatar_url' => self::squareAvatarUrl($squareId),
            'verified' => (bool) get_post_meta($squareId, 'meydan_verified', true),
        ];
    }

    public static function fromNarrative(int $narrativeId): array
    {
        $type = (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true);
        $id = (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true);
        return $type === 'square' ? self::forSquare($id) : self::forUser($id ?: (int) get_post_field('post_author', $narrativeId));
    }

    public static function ownerUserId(string $type, int $actorId): int
    {
        if ($type === 'user') {
            return $actorId;
        }
        if ($type === 'square') {
            return self::squareOwnerUserId($actorId);
        }
        return 0;
    }

    public static function parse(string $type, int $id): ?array
    {
        if ($type === 'user' && get_userdata($id)) {
            return self::forUser($id);
        }
        if ($type === 'square' && get_post_type($id) === 'meydan_square') {
            return self::forSquare($id);
        }
        return null;
    }

    public static function isVerifiedUser(int $userId): bool
    {
        $user = get_userdata($userId);
        return (bool) ($user && (in_array('administrator', (array) $user->roles, true) || in_array('meydan_square', (array) $user->roles, true)));
    }

    public static function squareOwnerUserId(int $squareId): int
    {
        $ownerId = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
        if ($ownerId > 0 && get_userdata($ownerId)) return $ownerId;
        $post = get_post($squareId);
        if (!$post || !get_userdata((int) $post->post_author)) return 0;
        $ownerId = (int) $post->post_author;
        update_post_meta($squareId, 'meydan_owner_user_id', $ownerId);
        return $ownerId;
    }

    public static function squareDisplayName(int $squareId): string
    {
        $ownerId = self::squareOwnerUserId($squareId);
        $owner = $ownerId ? get_userdata($ownerId) : null;
        return (string) get_user_meta($ownerId, 'meydan_full_name', true) ?: ($owner?->display_name ?: (get_the_title($squareId) ?: 'میدان'));
    }

    public static function squareAvatarUrl(int $squareId): string
    {
        $ownerAvatar = self::avatarUrl((int) get_user_meta(self::squareOwnerUserId($squareId), 'meydan_avatar_media_id', true));
        return $ownerAvatar ?: self::avatarUrl((int) get_post_meta($squareId, 'meydan_avatar_media_id', true));
    }

    public static function coverUrl(int $userId): string
    {
        return self::avatarUrl((int) get_user_meta($userId, 'meydan_cover_media_id', true));
    }

    public static function squareCoverUrl(int $squareId): string
    {
        return self::coverUrl(self::squareOwnerUserId($squareId));
    }

    public static function avatarUrl(int $mediaId): string
    {
        if ($mediaId <= 0 || !wp_attachment_is_image($mediaId)) {
            return '';
        }
        return self::mediaUrl($mediaId);
    }

    public static function mediaUrl(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        return (string) wp_get_attachment_url($mediaId);
    }
}
