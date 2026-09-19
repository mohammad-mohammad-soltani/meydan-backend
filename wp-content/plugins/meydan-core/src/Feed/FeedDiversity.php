<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

final class FeedDiversity
{
    /** @param array<int,array<string,mixed>> $items @param array<string,mixed> $settings @return array<int,array<string,mixed>> */
    public function rerank(array $items, array $settings): array
    {
        $chunkSize = max(1, (int) ($settings['diversity_top_n'] ?? 20));
        $actorCap = max(1, (int) ($settings['max_same_author_in_top_n'] ?? 3));
        $remaining = array_values($items);
        $output = [];
        $previousActor = null;
        while ($remaining !== []) {
            [$chunk, $remaining, $previousActor] = $this->takeChunk($remaining, $chunkSize, $actorCap, $previousActor);
            if ($chunk === []) break;
            $output = [...$output, ...$chunk];
        }
        return $output;
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:?string} */
    private function takeChunk(array $remaining, int $chunkSize, int $actorCap, ?string $previousActor): array
    {
        $chunk = [];
        $counts = [];
        $speakerCounts = [0, 0];
        for ($position = 0; $position < $chunkSize && $remaining !== []; $position++) {
            $block = intdiv($position, 10);
            $speakerCount = $speakerCounts[$block] ?? 0;
            $mustSpeaker = $position % 10 === 0 && $this->hasEligibleSpeaker($remaining, $counts, $actorCap);
            $index = $this->findIndex($remaining, $counts, $speakerCount, $actorCap, $previousActor, $mustSpeaker, false);
            if ($index === null) $index = $this->findIndex($remaining, $counts, $speakerCount, $actorCap, $previousActor, $mustSpeaker, true);
            if ($index === null && $mustSpeaker) $index = $this->findIndex($remaining, $counts, $speakerCount, $actorCap, $previousActor, false, true);
            if ($index === null) break;
            $item = $remaining[$index];
            array_splice($remaining, $index, 1);
            $actor = $this->actorKey($item);
            $counts[$actor] = ($counts[$actor] ?? 0) + 1;
            if ($this->isSpeaker($item)) $speakerCounts[$block]++;
            $chunk[] = $item;
            $previousActor = $actor;
        }
        return [$chunk, $remaining, $previousActor];
    }

    private function findIndex(array $items, array $counts, int $speakerCount, int $actorCap, ?string $previousActor, bool $speakerOnly, bool $allowAdjacent): ?int
    {
        foreach ($items as $index => $item) {
            if ($speakerOnly && !$this->isSpeaker($item)) continue;
            if (!$speakerOnly && $this->isSpeaker($item) && $speakerCount >= 3) continue;
            $actor = $this->actorKey($item);
            if (($counts[$actor] ?? 0) >= $actorCap) continue;
            if (!$allowAdjacent && $previousActor !== null && $actor === $previousActor) continue;
            return $index;
        }
        return null;
    }

    private function hasEligibleSpeaker(array $items, array $counts, int $actorCap): bool
    {
        foreach ($items as $item) if ($this->isSpeaker($item) && (($counts[$this->actorKey($item)] ?? 0) < $actorCap)) return true;
        return false;
    }

    private function isSpeaker(array $item): bool
    {
        return in_array('meydan_speaker', (array) ($item['actor_roles'] ?? []), true);
    }

    private function actorKey(array $item): string
    {
        return (string) ($item['actor_type'] ?? 'user') . ':' . (int) ($item['actor_id'] ?? 0);
    }
}
