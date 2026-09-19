<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class BindingService
{
    /** @return array<int,array{user_id:int,square_id:int,channel:string,last_success_at:?string}> */
    public function list(): array
    {
        global $wpdb;
        $checkpointTable = $wpdb->prefix . 'meydan_eitaa_checkpoints';
        $items = [];
        $users = get_users([
            'role' => 'meydan_square',
            'fields' => ['ID'],
            'number' => -1,
        ]);

        foreach ($users as $user) {
            $userId = (int) $user->ID;
            $channel = trim((string) get_user_meta($userId, 'meydan_eitaa_channel', true));
            if ($channel === '') {
                continue;
            }
            $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
            if (!self::activeSquare($squareId)) {
                continue;
            }
            $last = $wpdb->get_var($wpdb->prepare(
                "SELECT last_success_at FROM {$checkpointTable} WHERE square_id=%d",
                $squareId
            ));
            $items[] = [
                'user_id' => $userId,
                'square_id' => $squareId,
                'channel' => $channel,
                'last_success_at' => $last ? gmdate('c', strtotime((string) $last . ' UTC')) : null,
            ];
        }

        return $items;
    }

    public function ownerForSquare(int $squareId): int
    {
        if (!self::activeSquare($squareId)) {
            return 0;
        }
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
        if ($squareId <= 0 || get_post_type($squareId) !== 'meydan_square') {
            return false;
        }

        return !in_array((string) get_post_status($squareId), ['trash', 'auto-draft'], true);
    }

    public function channelForSquare(int $squareId): string
    {
        $owner = $this->ownerForSquare($squareId);
        return $owner > 0 ? trim((string) get_user_meta($owner, 'meydan_eitaa_channel', true)) : '';
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
            $squareId,
            $time,
            $now
        );
        return $wpdb->query($sql) !== false;
    }
}
