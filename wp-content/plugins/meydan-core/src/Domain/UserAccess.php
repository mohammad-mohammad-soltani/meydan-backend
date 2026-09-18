<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Auth\SessionService;
use WP_Error;

final class UserAccess
{
    private static bool $adminContext = false;

    public static function adminContext(): bool
    {
        self::$adminContext = true;
        return true;
    }

    public static function resetContext(): void
    {
        self::$adminContext = false;
    }

    public static function disabled(int $userId): bool
    {
        return $userId > 0 && get_user_meta($userId, 'meydan_disabled', true) === '1';
    }

    public static function visibleUser(int $userId): bool
    {
        return $userId > 0 && get_userdata($userId) && (self::$adminContext || !self::disabled($userId));
    }

    public static function visibleSquare(int $squareId): bool
    {
        if ($squareId <= 0 || get_post_type($squareId) !== 'meydan_square') return false;
        $owner = (int) get_post_meta($squareId, 'meydan_owner_user_id', true)
            ?: (int) get_post_field('post_author', $squareId);
        return self::visibleUser($owner) && (self::$adminContext || get_post_meta($squareId, 'meydan_disabled_by_owner', true) !== '1');
    }

    public static function visibleNarrative(int $id): bool
    {
        if (self::$adminContext) return true;
        $type = (string) get_post_meta($id, 'meydan_author_actor_type', true);
        $actor = (int) get_post_meta($id, 'meydan_author_actor_id', true);
        if ($type === 'square') return self::visibleSquare($actor);
        return self::visibleUser($actor ?: (int) get_post_field('post_author', $id));
    }

    public static function setDisabled(int $userId, bool $disabled): true|WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) return new WP_Error('not_found', 'کاربر پیدا نشد.', ['status' => 404]);
        if ($disabled && $userId === get_current_user_id()) {
            return new WP_Error('validation_failed', 'حساب فعلی را نمی‌توان غیرفعال کرد.', ['status' => 422]);
        }
        if ($disabled && in_array('administrator', (array) $user->roles, true) && self::activeAdminCount() <= 1) {
            return new WP_Error('validation_failed', 'آخرین مدیرکل را نمی‌توان غیرفعال کرد.', ['status' => 422]);
        }
        update_user_meta($userId, 'meydan_disabled', $disabled ? '1' : '0');
        if ($disabled) (new SessionService())->revokeAll($userId);
        return true;
    }

    public static function activeAdminCount(): int
    {
        $ids = get_users(['role' => 'administrator', 'fields' => 'ID']);
        return count(array_filter($ids, static fn($id) => !self::disabled((int) $id)));
    }

    public static function allowWordPressLogin($user)
    {
        return $user instanceof \WP_User && self::disabled((int) $user->ID)
            ? new WP_Error('account_disabled', 'این حساب غیرفعال است.') : $user;
    }

    public static function currentUser($userId)
    {
        return $userId && self::disabled((int) $userId) ? 0 : $userId;
    }
}
