<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use WP_Error;

/**
 * Entity kinds that share the `meydan_square` post type: square (میدان),
 * collective (مجموعه), media (رسانه) and organization (سازمان).
 *
 * Legacy squares carry no kind meta and therefore read as `square`. Every
 * non-square kind keeps the legacy `meydan_square` WordPress role as a
 * secondary role so all existing square-actor checks keep working; the primary
 * role (roles[0]) is the kind-specific one.
 */
final class EntityKinds
{
    public const SQUARE = 'square';
    public const KINDS = ['square', 'collective', 'media', 'organization'];
    public const ROLES = [
        'collective' => 'meydan_collective',
        'media' => 'meydan_media',
        'organization' => 'meydan_organization',
    ];
    public const ROLE_LABELS = [
        'meydan_collective' => 'مجموعه',
        'meydan_media' => 'رسانه',
        'meydan_organization' => 'سازمان',
    ];

    public static function valid(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function kindOf(int $squareId): string
    {
        $kind = (string) get_post_meta($squareId, 'meydan_entity_kind', true);
        return self::valid($kind) ? $kind : self::SQUARE;
    }

    /** Primary WordPress role for a kind (square keeps the legacy role). */
    public static function roleFor(string $kind): string
    {
        return self::ROLES[$kind] ?? 'meydan_square';
    }

    /** Adds the legacy square role after the kind role so legacy checks still match. */
    public static function applyRoles(\WP_User $user, string $kind): void
    {
        if ($kind !== self::SQUARE) $user->add_role('meydan_square');
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
        return $id > 0 && get_post_type($id) === 'meydan_square' ? $id : 0;
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
