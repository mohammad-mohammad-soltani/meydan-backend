<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

final class FeedDiversity
{
    /** @param array<int,array<string,mixed>> $items @param array<string,mixed> $settings @return array<int,array<string,mixed>> */
    public function rerank(array $items, array $settings): array
    {
        $topN = max(1, (int) $settings['diversity_top_n']);
        $cap = max(1, (int) $settings['max_same_author_in_top_n']);
        $out = []; $counts = []; $deferred = [];
        foreach ($items as $item) {
            $author = (string) ($item['actor_type'] ?? 'user') . ':' . (int) ($item['actor_id'] ?? 0);
            if (count($out) < $topN && ($counts[$author] ?? 0) >= $cap) { $deferred[] = $item; continue; }
            $out[] = $item; $counts[$author] = ($counts[$author] ?? 0) + 1;
        }
        return [...$out, ...$deferred];
    }
}
