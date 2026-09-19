<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

final class FeedRanker
{
    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    public function rank(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            $score = ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0));
            if ($score !== 0) return $score;
            $age = ((float) ($left['age_hours'] ?? 0.0)) <=> ((float) ($right['age_hours'] ?? 0.0));
            return $age !== 0 ? $age : ((int) ($right['narrative_id'] ?? 0)) <=> ((int) ($left['narrative_id'] ?? 0));
        });
        return $items;
    }
}
