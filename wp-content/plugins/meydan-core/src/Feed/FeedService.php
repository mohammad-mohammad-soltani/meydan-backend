<?php

declare(strict_types=1);

namespace Meydan\Core\Feed;

use Meydan\Core\Support\Viewer;

/** Request-path orchestration only; persistence and response side effects stay in TimelineController. */
final class FeedService
{
    public function __construct(
        private readonly CandidateGenerator $generator = new CandidateGenerator(),
        private readonly CandidateHydrator $hydrator = new CandidateHydrator(),
        private readonly FeedFilter $filter = new FeedFilter(),
        private readonly FeedScorer $scorer = new FeedScorer(),
        private readonly FeedRanker $ranker = new FeedRanker(),
        private readonly FeedDiversity $diversity = new FeedDiversity(),
    ) {}

    /** @return array{ids:list<int>,items:list<array<string,mixed>>,algorithm_version:string,debug_summary?:array<string,mixed>} */
    public function forYou(Viewer $viewer, int $limit, bool $explain = false): array
    {
        $settings = FeedSettings::get();
        $context = new FeedContext($viewer, $settings, []);
        $candidates = $this->generator->generate($context, $limit);
        $hydrated = $this->hydrator->hydrate($candidates, $context);
        $eligible = $this->filter->preRank($hydrated, $context);
        $scored = $this->scorer->scoreAll($eligible, $settings);
        $refreshSeed = bin2hex(random_bytes(16));
        $ranked = $this->ranker->rank($scored, $refreshSeed);
        $selected = array_slice($this->diversity->rerank($ranked, $settings), 0, max(1, $limit));
        foreach ($selected as $index => &$item) $item['rank'] = $index + 1;
        unset($item);
        $result = ['ids' => array_values(array_map(static fn(array $item): int => (int) $item['narrative_id'], $selected)), 'items' => $explain ? $selected : [], 'algorithm_version' => 'feed-v2'];
        if ($explain) $result['debug_summary'] = $this->debugSummary($candidates, $selected);
        return $result;
    }

    /** @param array<int,Candidate> $candidates @param list<array<string,mixed>> $selected @return array<string,mixed> */
    private function debugSummary(array $candidates, array $selected): array
    {
        $candidateBySource = [];
        foreach ($candidates as $candidate) foreach ($candidate->sourceNames() as $source) $candidateBySource[$source] = ($candidateBySource[$source] ?? 0) + 1;
        $selectedBySource = [];
        $selectedByRole = [];
        foreach ($selected as $item) {
            foreach ((array) ($item['source_names'] ?? []) as $source) $selectedBySource[$source] = ($selectedBySource[$source] ?? 0) + 1;
            foreach ((array) ($item['actor_roles'] ?? []) as $role) $selectedByRole[$role] = ($selectedByRole[$role] ?? 0) + 1;
        }
        $total = count($selected);
        $speaker = (int) ($selectedByRole['meydan_speaker'] ?? 0);
        $official = (int) ($selectedByRole['meydan_official'] ?? 0);
        return [
            'candidate_count_by_source' => $candidateBySource,
            'selected_count_by_source' => $selectedBySource,
            'selected_count_by_role' => $selectedByRole,
            'speaker_ratio' => $total > 0 ? $speaker / $total : 0.0,
            'official_ratio' => $total > 0 ? $official / $total : 0.0,
        ];
    }
}
