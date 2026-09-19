<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Integrations\Channels\Channels;
use WP_Error;

/**
 * Irreversibly removes a square and all data that belongs to it.
 *
 * Admin square deletion is intentionally a hard delete. It must never use
 * WordPress trash because sync bindings and custom tables can otherwise keep
 * the deleted square alive indirectly.
 */
final class SquareDeletionService
{
    /** @return array{square_id:int,owner_user_id:int,owner_deleted:bool}|WP_Error */
    public static function deletePermanently(int $squareId, bool $deleteOwner = true): array|WP_Error
    {
        $post = get_post($squareId);
        if (!$post || $post->post_type !== 'meydan_square') {
            return new WP_Error('not_found', 'میدان پیدا نشد.', ['status' => 404]);
        }

        $ownerId = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
        $owner = $ownerId > 0 ? get_userdata($ownerId) : false;
        $ownerLinked = $ownerId > 0
            && (int) get_user_meta($ownerId, 'meydan_square_id', true) === $squareId;

        // Disable every external source before removing anything else so no
        // later sync request can rediscover this square through owner metadata.
        if ($ownerLinked) {
            Channels::store($ownerId, 'eitaa', '');
            Channels::store($ownerId, 'bale', '');
            delete_user_meta($ownerId, 'meydan_square_id');
        }

        self::deleteSquareRelations($squareId);

        // This fires NarrativeCleanup::deleteSquareNarratives(), so every
        // narrative whose actor is this square is permanently deleted first.
        if (!wp_delete_post($squareId, true)) {
            return new WP_Error('internal_error', 'حذف کامل میدان انجام نشد.', ['status' => 500]);
        }

        $ownerDeleted = false;
        if ($deleteOwner && $ownerLinked && $owner) {
            $roles = (array) $owner->roles;
            $dedicatedSquareAccount =
                !in_array('administrator', $roles, true)
                && (
                    in_array('meydan_square', $roles, true)
                    || (string) get_user_meta($ownerId, 'meydan_account_type', true) === 'square'
                );

            if ($dedicatedSquareAccount) {
                self::deleteOwnerRelations($ownerId);

                // delete_user fires NarrativeCleanup::deleteUserNarratives(),
                // covering legacy/user-actor narratives owned by this account.
                require_once ABSPATH . 'wp-admin/includes/user.php';
                $ownerDeleted = (bool) wp_delete_user($ownerId);
                if (!$ownerDeleted) {
                    return new WP_Error('internal_error', 'میدان حذف شد اما حذف کامل حساب مالک انجام نشد.', ['status' => 500]);
                }
            }
        }

        return [
            'square_id' => $squareId,
            'owner_user_id' => $ownerId,
            'owner_deleted' => $ownerDeleted,
        ];
    }

    private static function deleteSquareRelations(int $squareId): void
    {
        global $wpdb;

        // Integration state. Imports are also removed when their narratives
        // disappear; deleting by square_id here additionally clears stale rows.
        foreach (['eitaa', 'bale'] as $source) {
            $wpdb->delete($wpdb->prefix . 'meydan_' . $source . '_imports', ['square_id' => $squareId], ['%d']);
            $wpdb->delete($wpdb->prefix . 'meydan_' . $source . '_checkpoints', ['square_id' => $squareId], ['%d']);
        }

        $wpdb->delete($wpdb->prefix . 'meydan_square_geo', ['square_id' => $squareId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'meydan_square_schedule', ['square_id' => $squareId], ['%d']);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions
             WHERE object_type='square' AND object_id=%d",
            $squareId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_actor_affinity
             WHERE target_actor_type='square' AND target_actor_id=%d",
            $squareId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_notifications
             WHERE (actor_type='square' AND actor_id=%d)
                OR (entity_type='square' AND entity_id=%d)
                OR (parent_entity_type='square' AND parent_entity_id=%d)",
            $squareId,
            $squareId,
            $squareId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_events
             WHERE entity_type='square' AND entity_id=%d",
            $squareId
        ));
    }

    private static function deleteOwnerRelations(int $userId): void
    {
        global $wpdb;

        foreach (['eitaa', 'bale'] as $source) {
            $wpdb->delete($wpdb->prefix . 'meydan_' . $source . '_imports', ['user_id' => $userId], ['%d']);
        }

        foreach (['sessions', 'uploads', 'initiative_members'] as $suffix) {
            $wpdb->delete($wpdb->prefix . 'meydan_' . $suffix, ['user_id' => $userId], ['%d']);
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d",
            $userId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_actor_affinity
             WHERE viewer_user_id=%d
                OR (target_actor_type='user' AND target_actor_id=%d)",
            $userId,
            $userId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_notifications
             WHERE recipient_user_id=%d
                OR (actor_type='user' AND actor_id=%d)
                OR (entity_type='user' AND entity_id=%d)
                OR (parent_entity_type='user' AND parent_entity_id=%d)",
            $userId,
            $userId,
            $userId,
            $userId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_speaker_requests
             WHERE requester_user_id=%d OR inviter_user_id=%d OR speaker_user_id=%d",
            $userId,
            $userId,
            $userId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_served_history
             WHERE viewer_type='user' AND viewer_id=%s",
            (string) $userId
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}meydan_events
             WHERE (viewer_type='user' AND viewer_id=%s)
                OR (entity_type='user' AND entity_id=%d)",
            (string) $userId,
            $userId
        ));
    }
}
