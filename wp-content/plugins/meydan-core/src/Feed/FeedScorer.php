<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

/** Pure Feed V2 score calculator. It deliberately has no WordPress dependency. */
final class FeedScorer
{
    /** @param array<string,mixed> $item @param array<string,mixed> $settings @return array<string,mixed> */
    public function score(array $item, array $settings): array
    {
        $age = max(0.0, (float) ($item['age_hours'] ?? 0.0));
        $freshness = $this->freshnessMultiplier($age, (array) ($settings['freshness_buckets'] ?? []));
        if ($freshness === null) {
            return [...$item, 'excluded' => true, 'freshness_multiplier' => 0.0, 'base_score' => 0.0, 'score' => 0.0];
        }

        $stats = (array) ($item['stats'] ?? []);
        $likes = max(0, (int) ($stats['likes'] ?? 0));
        $views = max(0, (int) ($stats['views'] ?? 0));
        $comments = max(0, (int) ($stats['comments'] ?? 0));
        $shares = max(0, (int) ($stats['shares'] ?? 0));
        $reposts = max(0, (int) ($stats['reposts'] ?? 0));

        $engagement = log1p($likes) * (float) $settings['like_weight']
            + log1p($views) * (float) $settings['view_weight']
            + log1p($comments) * (float) $settings['comment_weight']
            + log1p($shares + $reposts) * (float) $settings['share_weight'];
        // A cold-start prior lets unseen posts compete before they collect engagement.
        $base = min(2.0 + $engagement, (float) $settings['max_engagement_score']);

        $role = $this->roleMultiplier((string) ($item['actor_type'] ?? 'user'), (array) ($item['actor_roles'] ?? []), $settings);
        $location = $this->locationMultiplier($item, $settings);
        $editorial = !empty($item['editorial']) ? (float) $settings['editorial_multiplier'] : 1.0;
        $goodDeed = !empty($item['good_deed']) ? (float) $settings['good_deed_multiplier'] : 1.0;
        $following = !empty($item['following']) ? (float) $settings['following_multiplier'] : 1.0;
        $totalBoost = min($role * $location * $editorial * $goodDeed * $following, (float) $settings['max_total_boost']);

        return [...$item,
            'excluded' => false,
            'base_score' => $base,
            'score' => $base * $freshness * $totalBoost,
            'freshness_multiplier' => $freshness,
            'role_multiplier' => $role,
            'location_multiplier' => $location,
            'editorial_multiplier' => $editorial,
            'good_deed_multiplier' => $goodDeed,
            'following_multiplier' => $following,
            'total_boost' => $totalBoost,
        ];
    }

    /** @param array<int,array<string,mixed>> $items @param array<string,mixed> $settings @return array<int,array<string,mixed>> */
    public function scoreAll(array $items, array $settings): array
    {
        return array_values(array_filter(array_map(fn(array $item): array => $this->score($item, $settings), $items), static fn(array $item): bool => empty($item['excluded'])));
    }

    /** @param array<int,array<string,mixed>> $buckets */
    private function freshnessMultiplier(float $age, array $buckets): ?float
    {
        foreach ($buckets as $bucket) {
            $min = (float) ($bucket['min_hours'] ?? 0);
            $max = (float) ($bucket['max_hours'] ?? 0);
            if ($age >= $min && $age < $max) return (float) $bucket['multiplier'];
        }
        $last = end($buckets);
        if (is_array($last) && $age === (float) ($last['max_hours'] ?? -1)) return (float) $last['multiplier'];
        return null;
    }

    /** @param list<string> $roles @param array<string,mixed> $settings */
    private function roleMultiplier(string $actorType, array $roles, array $settings): float
    {
        $result = $actorType === 'square' ? (float) $settings['square_role_multiplier'] : 1.0;
        if (in_array('meydan_speaker', $roles, true)) $result = max($result, (float) $settings['speaker_role_multiplier']);
        if (in_array('meydan_official', $roles, true)) $result = max($result, (float) $settings['official_role_multiplier']);
        return $result;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $settings */
    private function locationMultiplier(array $item, array $settings): float
    {
        $viewerCity = (int) ($item['viewer_city_id'] ?? 0);
        $viewerProvince = (int) ($item['viewer_province_id'] ?? 0);
        if ($viewerCity > 0 && $viewerCity === (int) ($item['post_city_id'] ?? 0)) return (float) $settings['same_city_multiplier'];
        if ($viewerProvince > 0 && $viewerProvince === (int) ($item['post_province_id'] ?? 0)) return (float) $settings['same_province_multiplier'];
        return 1.0;
    }
}
