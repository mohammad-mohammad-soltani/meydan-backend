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

    /** @return array{ids:list<int>,items:list<array<string,mixed>>,algorithm_version:string} */
    public function forYou(Viewer $viewer, int $limit, bool $explain = false): array
    {
        $settings = FeedSettings::get();
        $context = new FeedContext($viewer, $settings, []);
        $candidates = $this->generator->generate($context);
        $hydrated = $this->hydrator->hydrate($candidates, $context);
        $eligible = $this->filter->preRank($hydrated, $context);
        $scored = $this->scorer->scoreAll($eligible, $settings);
        $ranked = $this->ranker->rank($scored);
        $selected = array_slice($this->diversity->rerank($ranked, $settings), 0, max(1, $limit));
        foreach ($selected as $index => &$item) $item['rank'] = $index + 1;
        unset($item);
        return ['ids' => array_values(array_map(static fn(array $item): int => (int) $item['narrative_id'], $selected)), 'items' => $explain ? $selected : [], 'algorithm_version' => 'feed-v2'];
    }
}
