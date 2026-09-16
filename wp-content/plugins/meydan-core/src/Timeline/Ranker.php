<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

final class Ranker
{
    public function rank(array $items): array
    {
        $weights = (array) get_option('meydan_ranking', []);

        // New refreshes get a fresh ordering, while TimelineSession keeps the
        // generated order stable for cursor pagination.
        $refreshJitter = min(1.5, max(0.25, (float) ($weights['refresh_jitter'] ?? 0.8)));
        $refreshReplayPenalty = max(0.0, (float) ($weights['refresh_replay_penalty'] ?? 8.0));

        foreach ($items as &$item) {
            $recentlyServed = (float) ($item['recently_served'] ?? 0.0);
            $score =
                ((float) ($weights['affinity'] ?? 3.0)) * (float) ($item['affinity'] ?? 0.0) +
                ((float) ($weights['recency'] ?? 2.1)) * (float) ($item['recency'] ?? 0.0) +
                ((float) ($weights['engagement_quality'] ?? 1.5)) * (float) ($item['engagement_quality'] ?? 0.0) +
                ((float) ($weights['locality'] ?? 0.9)) * (float) ($item['locality'] ?? 0.0) +
                ((float) ($weights['media_affinity'] ?? 0.6)) * (float) ($item['media_affinity'] ?? 0.0) +
                ((float) ($weights['initiative_boost'] ?? 0.6)) * (float) ($item['initiative_boost'] ?? 0.0) +
                ((float) ($weights['exploration_boost'] ?? 0.4)) * (float) ($item['exploration_boost'] ?? 0.0) -
                ((float) ($weights['recently_served_penalty'] ?? 2.0)) * $recentlyServed;

            // Strong refresh penalty: content already shown should not dominate
            // the next fresh session.
            $score -= $refreshReplayPenalty * $recentlyServed;

            // Larger controlled randomness only affects new snapshots.
            $score += ((mt_rand(0, 1000000) / 1000000) * 2 - 1) * $refreshJitter;

            $item['score'] = $score;
        }
        unset($item);

        usort(
            $items,
            static fn (array $a, array $b): int =>
                ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0)),
        );

        return $items;
    }
}
