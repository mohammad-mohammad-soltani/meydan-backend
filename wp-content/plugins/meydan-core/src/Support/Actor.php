<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Actor
{
    /** Request-scoped cache of userId => linked creator post id (0 = not a speaker). */
    private static array $speakerCreatorCache = [];

    /** Drops the memoised speaker link for a user after it changes. */
    public static function forgetSpeakerLink(int $userId): void
    {
        unset(self::$speakerCreatorCache[$userId]);
    }

    /**
     * Canonical account type for a user: `square` or `user`.
     *
     * The WordPress role is authoritative — administrators may change it
     * directly — so a stale `meydan_account_type` meta is repaired on read.
     */
    public static function accountType(int $userId): string
    {
        $stored = (string) get_user_meta($userId, 'meydan_account_type', true);
        $user = get_userdata($userId);
        $type = $user && in_array('meydan_square', (array) $user->roles, true) ? 'square' : 'user';
        if ($stored !== $type) {
            update_user_meta($userId, 'meydan_account_type', $type);
        }
        return $type;
    }

    public static function isSquare(int $userId): bool
    {
        return self::accountType($userId) === 'square';
    }

    /** Square post owned by a user, or 0 when unset/not a square post. */
    public static function squareId(int $userId): int
    {
        $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
        return $squareId > 0 && get_post_type($squareId) === 'meydan_square' ? $squareId : 0;
    }

    /** Registered address of a square, used as the invitation venue. '' when unset. */
    public static function squareAddress(int $squareId): string
    {
        if ($squareId <= 0) {
            return '';
        }
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT address FROM {$wpdb->prefix}meydan_square_geo WHERE square_id = %d",
            $squareId
        ));
    }

    /**
     * Resolves the speaker profile linked to a user, or 0.
     *
     * Reads the current meta key, falling back to the pre-1.1.0 creator-era key
     * and validating against `meydan_speaker`; the legacy key is resolved only
     * when the migration has not run yet, so a stale link cannot revive a
     * creator post as a speaker. Cached per request.
     */
    public static function speakerCreatorId(int $userId): int
    {
        if (!array_key_exists($userId, self::$speakerCreatorCache)) {
            $speakerId = (int) get_user_meta($userId, 'meydan_user_speaker_id', true);
            if ($speakerId <= 0 || get_post_type($speakerId) !== 'meydan_speaker') {
                $speakerId = 0;
            }
            self::$speakerCreatorCache[$userId] = $speakerId;
        }
        return self::$speakerCreatorCache[$userId];
    }

    /** A user is a speaker once a speaker profile is linked to their account. */
    public static function isSpeaker(int $userId): bool
    {
        return self::speakerCreatorId($userId) > 0;
    }

    /** Red speaker badge: speaker accounts verified on their linked profile. */
    public static function isVerifiedSpeaker(int $userId): bool
    {
        $speakerId = self::speakerCreatorId($userId);
        return $speakerId > 0 && (bool) get_post_meta($speakerId, 'meydan_verified', true);
    }

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
        $speakerCreatorId = self::speakerCreatorId($userId);
        return [
            'id' => 'usr_' . $userId,
            'type' => 'user',
            'display_name' => (string) get_user_meta($userId, 'meydan_full_name', true) ?: ($user?->display_name ?: 'کاربر میدان'),
            'avatar_url' => self::avatarUrl((int) get_user_meta($userId, 'meydan_avatar_media_id', true)),
            'verified' => self::isVerifiedUser($userId),
            'is_speaker' => $speakerCreatorId > 0,
            'verified_speaker' => self::isVerifiedSpeaker($userId),
            'speaker_creator_id' => $speakerCreatorId ?: null,
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
