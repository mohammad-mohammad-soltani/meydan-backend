<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Affinity
{
    private const WEIGHTS = [
        'follow' => 8.0,
        'comment' => 5.0,
        'repost' => 4.0,
        'share' => 3.0,
        'like' => 2.0,
        'detail_open' => 1.0,
    ];

    public static function bump(int $viewerUserId, string $actorType, int $actorId, string $action): void
    {
        $weight = self::WEIGHTS[$action] ?? 0.0;
        if ($viewerUserId <= 0 || $actorId <= 0 || $weight <= 0) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_actor_affinity';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (viewer_user_id, target_actor_type, target_actor_id, score, updated_at)
             VALUES (%d, %s, %d, %f, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = UTC_TIMESTAMP()",
            $viewerUserId,
            $actorType,
            $actorId,
            $weight
        ));
    }

    public static function normalized(int $viewerUserId, string $actorType, int $actorId): float
    {
        if ($viewerUserId <= 0) {
            return 0.0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_actor_affinity';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT score, updated_at FROM {$table} WHERE viewer_user_id = %d AND target_actor_type = %s AND target_actor_id = %d",
            $viewerUserId,
            $actorType,
            $actorId
        ));
        if (!$row) {
            return 0.0;
        }
        $config = (array) get_option('meydan_ranking', []);
        $halfLife = max(1.0, (float) ($config['affinity_half_life_days'] ?? 30.0));
        $ageDays = max(0.0, (time() - strtotime((string) $row->updated_at . ' UTC')) / DAY_IN_SECONDS);
        $decayed = (float) $row->score * pow(0.5, $ageDays / $halfLife);
        return 1.0 - exp(-$decayed / 10.0);
    }
}
