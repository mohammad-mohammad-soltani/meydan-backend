<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Domain\EntityKinds;
use Meydan\Core\Domain\UserAccess;
final class Actor
{
    /**
     * Canonical account type for a user: an entity kind (`square`, `media`,
     * `collective`, `organization`), `speaker`, `official` or `user`.
     *
     * The WordPress role is authoritative — administrators may change it
     * directly — so a stale `meydan_account_type` meta is repaired on read.
     */
    public static function accountType(int $userId): string
    {
        $stored = (string) get_user_meta($userId, 'meydan_account_type', true);
        $user = get_userdata($userId);
        $roles = $user ? (array) $user->roles : [];
        $kind = null;
        foreach (EntityKinds::ROLES as $roleKind => $role) {
            if (in_array($role, $roles, true)) $kind = $roleKind;
        }
        if ($kind !== null) {
            $type = $kind;
        } elseif (in_array('meydan_square', $roles, true)) {
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
     * Author/interaction actor type for a user: `user` or an entity kind.
     *
     * A speaker is a *user* actor even though its account type is `speaker`:
     * narratives, follows and affinity are keyed on the actor type, so letting
     * `speaker` leak into those tables would orphan its content.
     */
    public static function actorType(int $userId): string
    {
        $type = self::accountType($userId);
        return EntityKinds::isEntityActorType($type) ? $type : 'user';
    }

    /** True for an account that owns an entity of any kind (square, media, collective, organization). */
    public static function isEntityAccount(int $userId): bool
    {
        return EntityKinds::isEntityActorType(self::accountType($userId));
    }

    /**
     * Entity post owned by a user, of any kind, or 0. The id lives in the
     * `meydan_square_id` user meta for every kind (a historical name).
     */
    public static function entityId(int $userId): int
    {
        $id = (int) get_user_meta($userId, 'meydan_square_id', true);
        return EntityKinds::isEntity($id) ? $id : 0;
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

    /** @var array<int,string> request-local cache, filled in bulk by primeSquareAddresses(). */
    private static array $addressCache = [];

    /** One query for many squares, so listing N squares never costs N address lookups. */
    public static function primeSquareAddresses(array $squareIds): void
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $squareIds), static fn(int $id): bool => $id > 0 && !isset(self::$addressCache[$id]))));
        if (!$ids) {
            return;
        }
        foreach ($ids as $id) {
            self::$addressCache[$id] = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT square_id, address FROM {$wpdb->prefix}meydan_square_geo WHERE square_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')',
            ...$ids
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            self::$addressCache[(int) $row['square_id']] = (string) $row['address'];
        }
    }

    /** Registered address of a square, used as the invitation venue. '' when unset. */
    public static function squareAddress(int $squareId): string
    {
        if ($squareId <= 0) {
            return '';
        }
        if (isset(self::$addressCache[$squareId])) {
            return self::$addressCache[$squareId];
        }
        global $wpdb;
        return self::$addressCache[$squareId] = (string) $wpdb->get_var($wpdb->prepare(
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
        if (EntityKinds::isEntityActorType($type)) {
            $entityId = self::entityId($userId);
            if ($entityId > 0) {
                return self::forEntity($entityId);
            }
        }

        $user = get_userdata($userId);
        $isSpeaker = $type === 'speaker';
        $isOfficial = $type === 'official';
        return [
            'id' => 'usr_' . $userId,
            'type' => 'user',
            'account_type' => $type,
            'handle' => Handles::ofUser($userId),
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

    /** Public actor payload of an entity of any kind; `type` and `kind` both carry the kind. */
    public static function forEntity(int $entityId): array
    {
        $kind = EntityKinds::kindOf($entityId);
        $handle = (($o = self::squareOwnerUserId($entityId)) > 0 ? Handles::ofUser($o) : '') ?: (string) get_post_meta($entityId, 'meydan_handle', true);
        $actor = [
            'id' => EntityKinds::prefix($kind) . $entityId,
            'type' => $kind,
            'kind' => $kind,
            'display_name' => self::squareDisplayName($entityId),
            'avatar_url' => self::squareAvatarUrl($entityId),
            'handle' => $handle,
            'verified' => (bool) get_post_meta($entityId, 'meydan_verified', true),
        ];
        if (EntityKinds::hasLocation($kind)) $actor['location_address'] = self::squareAddress($entityId);
        return $actor;
    }

    public static function forSquare(int $squareId): array
    {
        return self::forEntity($squareId);
    }

    public static function fromNarrative(int $narrativeId): array
    {
        $type = (string) get_post_meta($narrativeId, 'meydan_author_actor_type', true);
        $id = (int) get_post_meta($narrativeId, 'meydan_author_actor_id', true);
        return EntityKinds::isEntityActorType($type) ? self::forEntity($id) : self::forUser($id ?: (int) get_post_field('post_author', $narrativeId));
    }

    public static function ownerUserId(string $type, int $actorId): int
    {
        if ($type === 'user') {
            return $actorId;
        }
        if (EntityKinds::isEntityActorType($type)) {
            return self::squareOwnerUserId($actorId);
        }
        return 0;
    }

    public static function parse(string $type, int $id): ?array
    {
        if ($type === 'user' && UserAccess::visibleUser($id)) {
            return self::forUser($id);
        }
        if (EntityKinds::isEntityActorType($type) && EntityKinds::kindOf($id) === $type && UserAccess::visibleEntity($id)) {
            return self::forEntity($id);
        }
        return null;
    }

    public static function isVerifiedUser(int $userId): bool
    {
        $user = get_userdata($userId);
        return (bool) ($user && (in_array('administrator', (array) $user->roles, true) || self::isEntityAccount($userId)));
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
        return $title !== '' ? $title : EntityKinds::label(EntityKinds::kindOf($squareId));
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
