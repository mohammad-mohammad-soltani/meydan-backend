<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class BindingService
{
    /** @return array<int,array{user_id:int,square_id:int,target_type:string,channel:string,last_success_at:?string}> */
    public function list(): array
    {
        global $wpdb;
        $checkpointTable = $wpdb->prefix . 'meydan_eitaa_checkpoints';
        $items = [];
        $users = get_users([
            'meta_key' => 'meydan_eitaa_channel',
            'fields' => ['ID'],
            'number' => -1,
        ]);

        foreach ($users as $row) {
            $userId = (int) $row->ID;
            $channel = trim((string) get_user_meta($userId, 'meydan_eitaa_channel', true));
            if ($channel === '') continue;

            $user = get_userdata($userId);
            if (!$user) continue;
            $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
            $isSquare = self::activeSquare($squareId);
            $isSquareAccount = (string) get_user_meta($userId, 'meydan_account_type', true) === 'square'
                || in_array('meydan_square', (array) $user->roles, true);

            if ($isSquareAccount && !$isSquare) continue;

            $roles = (array) $user->roles;
            $isUserTarget = in_array('meydan_speaker', $roles, true) || in_array('meydan_official', $roles, true);
            if (!$isSquare && !$isUserTarget) continue;

            $last = $isSquare
                ? $wpdb->get_var($wpdb->prepare("SELECT last_success_at FROM {$checkpointTable} WHERE square_id=%d", $squareId))
                : get_user_meta($userId, 'meydan_eitaa_last_success_at', true);

            $items[] = [
                'user_id' => $userId,
                'square_id' => $isSquare ? $squareId : 0,
                'target_type' => $isSquare ? 'square' : 'user',
                'channel' => $channel,
                'last_success_at' => $last ? gmdate('c', strtotime((string) $last . ' UTC')) : null,
            ];
        }

        return $items;
    }

    public function user(int $userId): bool
    {
        $user = $userId > 0 ? get_userdata($userId) : false;
        if (!$user) return false;

        $roles = (array) $user->roles;
        if (in_array('meydan_square', $roles, true)) return false;
        if (!in_array('meydan_speaker', $roles, true) && !in_array('meydan_official', $roles, true)) return false;

        return self::channelForUser($userId) !== '';
    }

    public static function channelForUser(int $userId): string
    {
        return trim((string) get_user_meta($userId, 'meydan_eitaa_channel', true));
    }

    public function ownerForSquare(int $squareId): int
    {
        if (!self::activeSquare($squareId)) return 0;

        $owner = (int) get_post_meta($squareId, 'meydan_owner_user_id', true);
        if ($owner > 0) {
            $user = get_userdata($owner);
            if ($user && in_array('meydan_square', (array) $user->roles, true)
                && (int) get_user_meta($owner, 'meydan_square_id', true) === $squareId) {
                return $owner;
            }
        }

        $ids = get_users([
            'role' => 'meydan_square',
            'meta_key' => 'meydan_square_id',
            'meta_value' => $squareId,
            'fields' => 'ids',
            'number' => 1,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    private static function activeSquare(int $squareId): bool
    {
        if ($squareId <= 0 || get_post_type($squareId) !== 'meydan_square') return false;
        return !in_array((string) get_post_status($squareId), ['trash', 'auto-draft'], true);
    }

    public function channelForSquare(int $squareId): string
    {
        $owner = $this->ownerForSquare($squareId);
        return $owner > 0 ? self::channelForUser($owner) : '';
    }

    public function checkpoint(int $squareId, int $timestamp): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_eitaa_checkpoints';
        $time = gmdate('Y-m-d H:i:s', $timestamp);
        $now = current_time('mysql', true);
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (square_id,last_success_at,updated_at) VALUES (%d,%s,%s)
             ON DUPLICATE KEY UPDATE last_success_at=VALUES(last_success_at),updated_at=VALUES(updated_at)",
            $squareId, $time, $now
        );
        return $wpdb->query($sql) !== false;
    }

    public function checkpointUser(int $userId, int $timestamp): bool
    {
        return $this->user($userId)
            && update_user_meta($userId, 'meydan_eitaa_last_success_at', gmdate('c', $timestamp)) !== false;
    }
}
