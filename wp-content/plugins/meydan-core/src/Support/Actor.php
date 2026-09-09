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
            'avatar_url' => self::avatar((int) get_user_meta($userId, 'meydan_avatar_media_id', true), $userId),
            'verified' => (bool) get_user_meta($userId, 'meydan_verified', true),
        ];
    }

    public static function forSquare(int $squareId): array
    {
        $post = get_post($squareId);
        return [
            'id' => 'sq_' . $squareId,
            'type' => 'square',
            'display_name' => $post ? get_the_title($post) : 'میدان',
            'avatar_url' => self::mediaUrl((int) get_post_meta($squareId, 'meydan_avatar_media_id', true)),
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
            return (int) get_post_meta($actorId, 'meydan_owner_user_id', true);
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

    private static function avatar(int $mediaId, int $userId): string
    {
        $url = self::mediaUrl($mediaId);
        return $url ?: (string) get_avatar_url($userId, ['size' => 256]);
    }

    public static function mediaUrl(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        return (string) wp_get_attachment_url($mediaId);
    }
}
