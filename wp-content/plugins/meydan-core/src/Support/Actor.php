<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\UserAccess;
final class Actor
{
    /**
     * Canonical account type for a user: `square`, `speaker`, `official` or `user`.
     *
     * The WordPress role is authoritative — administrators may change it
     * directly — so a stale `meydan_account_type` meta is repaired on read.
     */
    public static function accountType(int $userId): string
    {
        $stored = (string) get_user_meta($userId, 'meydan_account_type', true);
        $user = get_userdata($userId);
        $roles = $user ? (array) $user->roles : [];
        if (in_array('meydan_square', $roles, true)) {
            $type = 'square';
        } elseif (in_array('meydan_speaker', $roles, true)) {
            $type = 'speaker';
        } elseif (in_array('meydan_official', $roles, true)) {
            $type = 'official';
        } else {
            $type = 'user';
        }
        if ($stored !== $type) {
            update_user_meta($userId, 'meydan_account_type', $type);
        }
        return $type;
    }

    /**
     * Author/interaction actor type for a user: always `square` or `user`.
     *
     * A speaker is a *user* actor even though its account type is `speaker`:
     * narratives, follows and affinity are keyed on `user|square`, so letting
     * `speaker` leak into those tables would orphan its content.
     */
    public static function actorType(int $userId): string
    {
        return self::accountType($userId) === 'square' ? 'square' : 'user';
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
     * A speaker is a user account holding the `meydan_speaker` role.
     *
     * There is no speaker post any more: the role is the single source of
     * truth, so a user promoted in wp-admin is immediately invitable.
     */
    public static function isSpeaker(int $userId): bool
    {
        return self::accountType($userId) === 'speaker';
    }

    /** Red speaker badge: a speaker account verified on its own profile. */
    public static function isVerifiedSpeaker(int $userId): bool
    {
        return self::isSpeaker($userId) && (bool) get_user_meta($userId, 'meydan_verified', true);
    }

    /** Official accounts always carry the grey official badge. */
    public static function isOfficial(int $userId): bool
    {
        return self::accountType($userId) === 'official';
    }

    public static function forUser(int $userId): array
    {
        $type = self::accountType($userId);
        if ($type === 'square') {
            $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
            if ($squareId > 0) {
                return self::forSquare($squareId);
            }
        }

        $user = get_userdata($userId);
        $isSpeaker = $type === 'speaker';
        $isOfficial = $type === 'official';
        return [
            'id' => 'usr_' . $userId,
            'type' => 'user',
            'account_type' => $type,
            'display_name' => (string) get_user_meta($userId, 'meydan_full_name', true) ?: ($user?->display_name ?: 'کاربر میدان'),
            'avatar_url' => self::avatarUrl((int) get_user_meta($userId, 'meydan_avatar_media_id', true)),
            'verified' => self::isVerifiedUser($userId),
            'is_speaker' => $isSpeaker,
            'verified_speaker' => self::isVerifiedSpeaker($userId),
            'is_official' => $isOfficial,
            'verified_official' => $isOfficial,
            // Both keys carry the user id now that a speaker *is* the account;
            // `speaker_creator_id` is kept for client compatibility.
            'speaker_user_id' => $isSpeaker ? $userId : null,
            'speaker_creator_id' => $isSpeaker ? $userId : null,
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
        if ($type === 'user' && UserAccess::visibleUser($id)) {
            return self::forUser($id);
        }
        if ($type === 'square' && UserAccess::visibleSquare($id)) {
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
        // A square is its own actor. Its public/admin name must come from the
        // square post title and must never fall back to the owner's name.
        $title = trim((string) get_the_title($squareId));
        return $title !== '' ? $title : 'میدان';
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
