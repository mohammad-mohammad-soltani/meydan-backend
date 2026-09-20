<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

final class FeedRanker
{
    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    public function rank(array $items, ?string $refreshSeed = null): array
    {
        usort($items, static function (array $left, array $right): int {
            $score = ((float) ($right['_rank_key'] ?? $right['score'] ?? 0.0)) <=> ((float) ($left['_rank_key'] ?? $left['score'] ?? 0.0));
            if ($score !== 0) return $score;
            $age = ((float) ($left['age_hours'] ?? 0.0)) <=> ((float) ($right['age_hours'] ?? 0.0));
            return $age !== 0 ? $age : ((int) ($right['narrative_id'] ?? 0)) <=> ((int) ($left['narrative_id'] ?? 0));
        });
        if ($refreshSeed !== null) {
            foreach ($items as &$item) {
                $score = (float) ($item['score'] ?? 0.0);
                $hash = hash('sha256', $refreshSeed . ':' . (int) ($item['narrative_id'] ?? 0));
                $fraction = hexdec(substr($hash, 0, 8)) / 4294967295;
                // Seeded Gumbel jitter: weighted sampling without replacement.
                // Log compression lets fresh, less popular posts compete while
                // preserving a preference for higher quality scores.
                $uniform = max(0.000000001, min(0.999999999, $fraction));
                $item['_rank_key'] = log1p(max(0.0, $score)) - 0.85 * log(-log($uniform));
            }
            unset($item);
            usort($items, static function (array $left, array $right): int {
                $score = ((float) ($right['_rank_key'] ?? 0.0)) <=> ((float) ($left['_rank_key'] ?? 0.0));
                if ($score !== 0) return $score;
                $age = ((float) ($left['age_hours'] ?? 0.0)) <=> ((float) ($right['age_hours'] ?? 0.0));
                return $age !== 0 ? $age : ((int) ($right['narrative_id'] ?? 0)) <=> ((int) ($left['narrative_id'] ?? 0));
            });
            foreach ($items as &$item) unset($item['_rank_key']);
            unset($item);
        }
        return $items;
    }
}
