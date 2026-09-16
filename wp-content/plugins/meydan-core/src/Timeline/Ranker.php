<?php

declare(strict_types=1);
namespace Meydan\Core\Timeline;

final class Ranker
{
    public function rank(array $items): array
    {
        $weights = (array) get_option('meydan_ranking', []);

        // A timeline session is immutable after it is created, so adding a small
        // amount of randomness here only changes *new* refreshes. Cursor pages
        // keep reading the frozen TimelineSession snapshot and never reshuffle.
        $refreshJitter = min(0.75, max(0.0, (float) ($weights['refresh_jitter'] ?? 0.24)));
        $refreshReplayPenalty = max(0.0, (float) ($weights['refresh_replay_penalty'] ?? 2.75));

        foreach ($items as &$item) {
            $recentlyServed = (float) ($item['recently_served'] ?? 0.0);
            $baseScore =
                ((float) ($weights['affinity'] ?? 3.0)) * (float) ($item['affinity'] ?? 0.0) +
                ((float) ($weights['recency'] ?? 2.1)) * (float) ($item['recency'] ?? 0.0) +
                ((float) ($weights['engagement_quality'] ?? 1.5)) * (float) ($item['engagement_quality'] ?? 0.0) +
                ((float) ($weights['locality'] ?? 0.9)) * (float) ($item['locality'] ?? 0.0) +
                ((float) ($weights['media_affinity'] ?? 0.6)) * (float) ($item['media_affinity'] ?? 0.0) +
                ((float) ($weights['media_reflection_boost'] ?? 0.5)) * (float) ($item['media_reflection_boost'] ?? 0.0) +
                ((float) ($weights['initiative_boost'] ?? 0.6)) * (float) ($item['initiative_boost'] ?? 0.0) +
                ((float) ($weights['exploration_boost'] ?? 0.4)) * (float) ($item['exploration_boost'] ?? 0.0) -
                ((float) ($weights['recently_served_penalty'] ?? 2.0)) * $recentlyServed;

            // Previously served narratives should not dominate the top again on
            // the next refresh. This extra refresh-only penalty is deliberately
            // stronger than ordinary ranking decay, while still allowing an
            // exceptional/relevant post to reappear farther down the feed.
            $baseScore -= $refreshReplayPenalty * $recentlyServed;

            // Nearby scores are intentionally allowed to trade places between
            // fresh sessions. The jitter is far smaller than the major ranking
            // weights, so relevance is preserved while refreshes stop looking
            // like a byte-for-byte replay of the previous timeline.
            $unit = mt_rand(0, 1000000) / 1000000;
            $jitter = (($unit * 2.0) - 1.0) * $refreshJitter;

            $item['score'] = $baseScore + $jitter;
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
