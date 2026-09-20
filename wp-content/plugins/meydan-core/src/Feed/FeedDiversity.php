<?php

declare(strict_types=1);
namespace Meydan\Core\Feed;

/** Greedy constrained selection; history spans the whole cursor snapshot. */
final class FeedDiversity
{
    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    public function rerank(array $items, array $settings, int $previousFirstId = 0): array
    {
        $remaining = array_values($items);
        $output = $lastSeen = $counts = $seenIds = [];
        $window = max(5, (int) ($settings['diversity_top_n'] ?? 20));
        while ($remaining !== []) {
            $position = count($output);
            $recent = array_slice($output, -($window - 1));
            $mediaCounts = $roleCounts = $actorCounts = [];
            foreach ($recent as $item) {
                $media = $item['media_type'] ?? 'text';
                $mediaCounts[$media] = ($mediaCounts[$media] ?? 0) + 1;
                $actor = $this->actorKey($item);
                $actorCounts[$actor] = ($actorCounts[$actor] ?? 0) + 1;
                foreach ((array) ($item['actor_roles'] ?? []) as $role) $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
            }
            $previous = $output[$position - 1] ?? null;
            $best = null;
            $bestPriority = null;
            foreach ($remaining as $index => $item) {
                if (isset($seenIds[$item['narrative_id']])) continue;
                if ($position === 0 && count($remaining) > 1 && (int) $item['narrative_id'] === $previousFirstId) continue;
                $speaker = $this->isSpeaker($item);
                if ($previous !== null && $speaker && $this->isSpeaker($previous)) continue;
                $actor = $this->actorKey($item);
                // Never fill a sparse feed with A/B/A repetitions.
                if (isset($lastSeen[$actor]) && $position - $lastSeen[$actor] < 5) continue;
                if (($actorCounts[$actor] ?? 0) >= max(1, (int) ($settings['max_same_author_in_top_n'] ?? 3))) continue;
                $roleOverflow = 0;
                foreach (['meydan_speaker'=>'max_speaker_ratio_top_20','meydan_official'=>'max_official_ratio_top_20'] as $role=>$key) {
                    if (in_array($role, (array) ($item['actor_roles'] ?? []), true) && ($roleCounts[$role] ?? 0) >= (int) floor($window * (float) ($settings[$key] ?? 40) / 100)) $roleOverflow++;
                }
                $media = $item['media_type'] ?? 'text';
                $sameMedia = $previous !== null && ($previous['media_type'] ?? 'text') === $media;
                // New actors (including speakers) precede repeated squares. Format
                // deficits and role caps are preferences when supply is sparse.
                $priority = [
                    $counts[$actor] ?? 0,
                    $roleOverflow,
                    ($mediaCounts[$media] ?? 0) + ($sameMedia ? 2 : 0),
                    $index,
                ];
                if ($bestPriority === null || $priority < $bestPriority) { $best = $index; $bestPriority = $priority; }
            }
            // Hard spacing wins over padding the snapshot with repetitive posts.
            if ($best === null) break;
            $item = $remaining[$best];
            unset($remaining[$best]);
            $actor = $this->actorKey($item);
            $lastSeen[$actor] = $position;
            $counts[$actor] = ($counts[$actor] ?? 0) + 1;
            $seenIds[$item['narrative_id']] = true;
            $output[] = $item;
        }
        return $output;
    }

    private function isSpeaker(array $item): bool
    {
        return ($item['actor_type'] ?? 'user') === 'user' && in_array('meydan_speaker', (array) ($item['actor_roles'] ?? []), true);
    }

    private function actorKey(array $item): string
    {
        return (string) ($item['actor_type'] ?? 'user') . ':' . (int) ($item['actor_id'] ?? 0);
    }
}
