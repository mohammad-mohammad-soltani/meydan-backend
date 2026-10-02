<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Bale;

final class BindingService
{
    /** @return array<int,array{user_id:int,square_id:int,target_type:string,channel:string,last_success_at:?string}> */
    public function list(): array
    {
        global $wpdb;
        $items = [];
        $users = get_users([
            'meta_key' => 'meydan_bale_channel',
            'fields' => ['ID'],
            'number' => -1,
        ]);

        foreach ($users as $user) {
            $userId = (int) $user->ID;
            $channel = trim((string) get_user_meta($userId, 'meydan_bale_channel', true));
            if ($channel === '') {
                continue;
            }
            $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
            $isSquare = self::activeSquare($squareId);
            $user = get_userdata($userId);
            $isSquareAccount = \Meydan\Core\Support\Actor::isEntityAccount($userId);

            // A deleted/trashed square account must never silently fall back
            // to a plain user target, otherwise its channel keeps importing.
            if ($isSquareAccount && !$isSquare) {
                continue;
            }
            $isUserTarget = $user && (in_array('meydan_speaker', (array) $user->roles, true) || in_array('meydan_official', (array) $user->roles, true));
            if (!$isSquare && !$isUserTarget) {
                continue;
            }

            $last = $isSquare
                ? $wpdb->get_var($wpdb->prepare("SELECT last_success_at FROM {$wpdb->prefix}meydan_bale_checkpoints WHERE square_id=%d", $squareId))
                : get_user_meta($userId, 'meydan_bale_last_success_at', true);
            $items[] = [
                'user_id' => $userId,
                'square_id' => $isSquare ? $squareId : 0,
                // `square` means an entity target of any kind; `kind` says which.
                'target_type' => $isSquare ? 'square' : 'user',
                'kind' => $isSquare ? \Meydan\Core\Domain\EntityKinds::kindOf($squareId) : 'user',
                'channel' => $channel,
                'last_success_at' => $last ? gmdate('c', strtotime((string) $last . ' UTC')) : null,
            ];
        }

        return $items;
    }

    public function user(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }

        $isSquareAccount = \Meydan\Core\Support\Actor::isEntityAccount($userId);
        if ($isSquareAccount) {
            return false;
        }
        $roles = (array) $user->roles;
        if (!in_array('meydan_speaker', $roles, true) && !in_array('meydan_official', $roles, true)) {
            return false;
        }

        return self::channelForUser($userId) !== '';
    }

    public static function channelForUser(int $userId): string
    {
        return trim((string) get_user_meta($userId, 'meydan_bale_channel', true));
    }

    public function ownerForSquare(int $squareId): int
    {
        if (!self::activeSquare($squareId)) {
            return 0;
        }
        $owner = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
        if ($owner > 0) {
            $user = get_userdata($owner);
            if ($user && (int) get_user_meta($owner, 'meydan_square_id', true) === $squareId) {
                return $owner;
            }
        }
        $ids = get_users([
            'meta_key' => 'meydan_square_id',
            'meta_value' => $squareId,
            'fields' => 'ids',
            'number' => 1,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    private static function activeSquare(int $squareId): bool
    {
        if ($squareId <= 0 || !\Meydan\Core\Domain\EntityKinds::isEntity($squareId)) {
            return false;
        }

        return !in_array((string) get_post_status($squareId), ['trash', 'auto-draft'], true);
    }

    public function channelForSquare(int $squareId): string
    {
        $owner = $this->ownerForSquare($squareId);
        return $owner > 0 ? trim((string) get_user_meta($owner, 'meydan_bale_channel', true)) : '';
    }

    public function checkpoint(int $squareId, int $timestamp): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_bale_checkpoints';
        $time = gmdate('Y-m-d H:i:s', $timestamp);
        $now = current_time('mysql', true);
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (square_id,last_success_at,updated_at) VALUES (%d,%s,%s)
             ON DUPLICATE KEY UPDATE last_success_at=VALUES(last_success_at),updated_at=VALUES(updated_at)",
            $squareId,
            $time,
            $now
        );
        return $wpdb->query($sql) !== false;
    }

    public function checkpointUser(int $userId, int $timestamp): bool
    {
        return $this->user($userId) && update_user_meta($userId, 'meydan_bale_last_success_at', gmdate('c', $timestamp)) !== false;
    }
}
