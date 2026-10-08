<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use WP_Error;

/**
 * Registry of public entity kinds: square (میدان), collective (مجموعه),
 * media (رسانه) and organization (سازمان).
 *
 * Every kind is its own entity: its own post type, actor type, WordPress role
 * and id prefix. Only squares have a location and a schedule. The actor type
 * of an entity equals its kind, so `['user', ...EntityKinds::KINDS]` is the one
 * list of actor types; never hard-code it elsewhere.
 */
final class EntityKinds
{
    public const SQUARE = 'square';
    public const MEMORIAL = 'memorial';
    public const KINDS = ['square', 'collective', 'media', 'organization', 'memorial'];
    public const ROLES = [
        'collective' => 'meydan_collective',
        'media' => 'meydan_media',
        'organization' => 'meydan_organization',
        'memorial' => 'meydan_memorial',
    ];
    public const POST_TYPES = [
        'square' => 'meydan_square',
        'media' => 'meydan_media_acct',
        'collective' => 'meydan_collective',
        'organization' => 'meydan_organization',
        'memorial' => 'meydan_memorial',
    ];
    public const PREFIXES = ['square' => 'sq_', 'media' => 'md_', 'collective' => 'cl_', 'organization' => 'og_', 'memorial' => 'mm_'];
    public const LABELS = ['square' => 'میدان', 'media' => 'رسانه', 'collective' => 'مجموعه', 'organization' => 'سازمان', 'memorial' => 'یادبود'];
    public const ROLE_LABELS = [
        'meydan_collective' => 'مجموعه',
        'meydan_media' => 'رسانه',
        'meydan_organization' => 'سازمان',
        'meydan_memorial' => 'یادبود',
    ];

    public static function valid(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** Kind of an entity post, by its post type; `square` when the post is not an entity. */
    public static function kindOf(int $postId): string
    {
        return self::kindForPostType((string) get_post_type($postId)) ?? self::SQUARE;
    }

    public static function postType(string $kind): string
    {
        return self::POST_TYPES[$kind] ?? self::POST_TYPES[self::SQUARE];
    }

    /** @return string[] every entity post type */
    public static function postTypes(): array
    {
        return array_values(self::POST_TYPES);
    }

    public static function kindForPostType(string $postType): ?string
    {
        $kind = array_search($postType, self::POST_TYPES, true);
        return $kind === false ? null : (string) $kind;
    }

    /** True when the post is an entity of any kind. */
    public static function isEntity(int $postId): bool
    {
        return $postId > 0 && self::kindForPostType((string) get_post_type($postId)) !== null;
    }

    /** Actor types: `user` plus every entity kind. */
    public static function actorTypes(): array
    {
        return array_merge(['user'], self::KINDS);
    }

    public static function isEntityActorType(string $type): bool
    {
        return in_array($type, self::KINDS, true);
    }

    public static function prefix(string $kind): string
    {
        return self::PREFIXES[$kind] ?? 'sq_';
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? self::LABELS[self::SQUARE];
    }

    /** Only squares have a physical location and a night schedule. */
    public static function hasLocation(string $kind): bool
    {
        return $kind === self::SQUARE;
    }

    /** `md_12` → ['media', 12]; null when the prefix is unknown. */
    public static function parsePrefixed(string $id): ?array
    {
        foreach (self::PREFIXES as $kind => $prefix) {
            if (str_starts_with($id, $prefix) && ctype_digit(substr($id, strlen($prefix)))) {
                return [$kind, (int) substr($id, strlen($prefix))];
            }
        }
        return null;
    }

    /** Primary WordPress role for a kind (square keeps the legacy role). */
    public static function roleFor(string $kind): string
    {
        return self::ROLES[$kind] ?? 'meydan_square';
    }

    /** Gives the account exactly the kind's role: no secondary `meydan_square` role. */
    public static function applyRoles(\WP_User $user, string $kind): void
    {
        $user->set_role(self::roleFor($kind));
    }

    /**
     * Creates the republishing outlet for a media entity and links both ways.
     * The outlet stays a draft until the entity is approved, so it never shows
     * up in the public outlet list early.
     */
    public static function createLinkedOutlet(int $squareId, string $name, int $avatarMediaId = 0): int|WP_Error
    {
        $outletId = MediaOutletService::save(['name' => $name, 'avatar_media_id' => $avatarMediaId]);
        if (is_wp_error($outletId)) return $outletId;
        wp_update_post(['ID' => (int) $outletId, 'post_status' => 'draft']);
        self::link($squareId, (int) $outletId);
        update_post_meta((int) $outletId, 'meydan_outlet_auto', '1');
        return (int) $outletId;
    }

    public static function linkedOutlet(int $squareId): int
    {
        $id = (int) get_post_meta($squareId, 'meydan_linked_outlet_id', true);
        return $id > 0 && get_post_type($id) === 'meydan_media_outlet' ? $id : 0;
    }

    public static function linkedEntity(int $outletId): int
    {
        $id = (int) get_post_meta($outletId, 'meydan_linked_entity_id', true);
        return self::isEntity($id) ? $id : 0;
    }

    /** One entity ↔ one outlet. Any previous link on either side is dropped first. */
    public static function link(int $squareId, int $outletId): void
    {
        self::unlink($squareId);
        if ($previous = self::linkedEntity($outletId)) self::unlink($previous);
        update_post_meta($squareId, 'meydan_linked_outlet_id', $outletId);
        update_post_meta($outletId, 'meydan_linked_entity_id', $squareId);
    }

    public static function unlink(int $squareId): void
    {
        $outletId = (int) get_post_meta($squareId, 'meydan_linked_outlet_id', true);
        delete_post_meta($squareId, 'meydan_linked_outlet_id');
        if ($outletId > 0 && (int) get_post_meta($outletId, 'meydan_linked_entity_id', true) === $squareId) {
            delete_post_meta($outletId, 'meydan_linked_entity_id');
        }
    }

    /** Approval publishes the linked outlet; other states take it back to draft. */
    public static function syncOutletStatus(int $squareId, string $approval): void
    {
        $outletId = self::linkedOutlet($squareId);
        // Only outlets created by registration follow the approval; manually linked ones keep their own state.
        if ($outletId <= 0 || get_post_meta($outletId, 'meydan_outlet_auto', true) !== '1') return;
        $target = $approval === 'approved' ? 'publish' : 'draft';
        if (get_post_status($outletId) !== $target) {
            wp_update_post(['ID' => $outletId, 'post_status' => $target]);
        }
    }

    public static function audit(string $action, int $squareId, mixed $before, mixed $after): void
    {
        AuditLogger::log($action, 'square', $squareId, $before, $after);
    }
}
