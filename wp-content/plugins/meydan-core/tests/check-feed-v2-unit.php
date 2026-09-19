<?php

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

require_once __DIR__ . '/../src/Feed/Candidate.php';
require_once __DIR__ . '/../src/Feed/FeedSettings.php';
require_once __DIR__ . '/../src/Feed/FeedScorer.php';
require_once __DIR__ . '/../src/Feed/FeedDiversity.php';
require_once __DIR__ . '/../src/Feed/FeedRanker.php';
require_once __DIR__ . '/../src/Feed/CandidateGenerator.php';

$candidate = new Meydan\Core\Feed\Candidate(42, 'following');
$candidate->addSource('same_city');
$candidate->addSource('following');

assertSame(42, $candidate->narrativeId);
assertSame(['following', 'same_city'], $candidate->sourceNames());

$defaults = Meydan\Core\Feed\FeedSettings::defaults();
assertSame(150, $defaults['source_quotas']['following']);
assertSame(75, $defaults['source_quotas']['speaker']);
assertSame(40.0, $defaults['max_speaker_ratio_top_20']);

$settings = Meydan\Core\Feed\FeedSettings::validate([
    'max_post_age_hours' => 72,
    'freshness_buckets' => [[
        'min_hours' => 0,
        'max_hours' => 72,
        'multiplier' => 1.45,
    ]],
]);
assertSame(1.45, $settings['freshness_buckets'][0]['multiplier']);

foreach ([
    ['max_total_boost' => INF],
    ['freshness_buckets' => [[
        'min_hours' => 6,
        'max_hours' => 6,
        'multiplier' => 1,
    ]]],
] as $invalid) {
    $result = Meydan\Core\Feed\FeedSettings::validate($invalid);
    if (!$result instanceof WP_Error) {
        throw new RuntimeException('Expected invalid feed settings to return WP_Error.');
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) throw new RuntimeException($message !== '' ? $message : 'Expected true.');
}

function feedFixture(array $override = []): array
{
    return array_replace([
        'narrative_id' => 1,
        'actor_type' => 'user',
        'actor_id' => 5,
        'actor_roles' => [],
        'stats' => ['likes' => 5, 'views' => 5, 'comments' => 5, 'shares' => 0, 'reposts' => 0],
        'age_hours' => 1.0,
        'viewer_city_id' => 1,
        'viewer_province_id' => 1,
        'post_city_id' => 3,
        'post_province_id' => 3,
        'editorial' => false,
        'good_deed' => false,
        'following' => false,
    ], $override);
}

$feedSettings = Meydan\Core\Feed\FeedSettings::defaults();
$scorer = new Meydan\Core\Feed\FeedScorer();
$normal = $scorer->score(feedFixture(), $feedSettings);
$official = $scorer->score(feedFixture(['actor_roles' => ['meydan_official']]), $feedSettings);
assertTrue($official['score'] > $normal['score']);
assertSame(2.25, $scorer->score(feedFixture(['actor_roles' => ['meydan_official', 'meydan_speaker']]), $feedSettings)['role_multiplier']);
assertSame(1.5, $scorer->score(feedFixture(['post_city_id' => 1, 'post_province_id' => 1]), $feedSettings)['location_multiplier']);
assertSame(1.25, $scorer->score(feedFixture(['post_city_id' => 2, 'post_province_id' => 1]), $feedSettings)['location_multiplier']);
assertSame(1.6, $scorer->score(feedFixture(['following' => true]), $feedSettings)['following_multiplier']);
assertSame(6.0, $scorer->score(feedFixture(['actor_roles' => ['meydan_official'], 'post_city_id' => 1, 'post_province_id' => 1, 'editorial' => true, 'good_deed' => true, 'following' => true]), $feedSettings)['total_boost']);
assertTrue($scorer->score(feedFixture(['stats' => ['likes'=>0,'views'=>0,'comments'=>1,'shares'=>0,'reposts'=>0]]), $feedSettings)['base_score'] > $scorer->score(feedFixture(['stats' => ['likes'=>1,'views'=>0,'comments'=>0,'shares'=>0,'reposts'=>0]]), $feedSettings)['base_score']);
assertSame(1.8, $scorer->score(feedFixture(['age_hours' => 0]), $feedSettings)['freshness_multiplier']);
assertSame(1.45, $scorer->score(feedFixture(['age_hours' => 6]), $feedSettings)['freshness_multiplier']);
assertSame(1.15, $scorer->score(feedFixture(['age_hours' => 12]), $feedSettings)['freshness_multiplier']);
assertSame(0.75, $scorer->score(feedFixture(['age_hours' => 24]), $feedSettings)['freshness_multiplier']);
assertSame(0.45, $scorer->score(feedFixture(['age_hours' => 48]), $feedSettings)['freshness_multiplier']);
assertSame(0.45, $scorer->score(feedFixture(['age_hours' => 72]), $feedSettings)['freshness_multiplier']);
assertTrue($scorer->score(feedFixture(['age_hours' => 72.0001]), $feedSettings)['excluded']);
$freshScore = $scorer->score(feedFixture(['age_hours' => 1]), $feedSettings)['score'];
$oldScore = $scorer->score(feedFixture(['age_hours' => 48]), $feedSettings)['score'];
assertTrue($freshScore > $oldScore, 'A fresh post with matching engagement must outrank an older post.');
$zeroScore = $scorer->score(feedFixture(['stats' => ['likes'=>0,'views'=>0,'comments'=>0,'shares'=>0,'reposts'=>0]]), $feedSettings)['score'];
assertTrue(is_finite($zeroScore) && $zeroScore === 0.0);

$diversity = new Meydan\Core\Feed\FeedDiversity();
$diversitySettings = Meydan\Core\Feed\FeedSettings::defaults();
$diversityItems = [];
for ($i = 0; $i < 80; $i++) {
    $isSpeaker = $i % 4 === 0;
    $diversityItems[] = [
        'narrative_id' => 1000 + $i,
        'actor_type' => $isSpeaker ? 'user' : 'square',
        'actor_id' => $isSpeaker ? 200 + ($i % 8) : 1 + ($i % 10),
        'actor_roles' => $isSpeaker ? ['meydan_speaker'] : [],
        'score' => 100 - $i,
        'age_hours' => (float) $i,
    ];
}

$roleItems = [];
for ($i = 0; $i < 100; $i++) {
    $roleItems[] = [
        'narrative_id' => 5000 + $i,
        'actor_type' => 'user',
        'actor_id' => 100 + ($i % 50),
        'actor_roles' => ['meydan_speaker'],
        'source_names' => ['speaker'],
        'score' => 100 - $i,
        'age_hours' => 1.0,
    ];
}
for ($i = 0; $i < 100; $i++) {
    $roleItems[] = [
        'narrative_id' => 6000 + $i,
        'actor_type' => 'user',
        'actor_id' => 300 + $i,
        'actor_roles' => [],
        'source_names' => ['general'],
        'score' => 50 - ($i / 10),
        'age_hours' => 1.0,
    ];
}
$roleMixed = $diversity->rerank($roleItems, $diversitySettings);
$firstTwenty = array_slice($roleMixed, 0, 20);
$speakerCount = count(array_filter($firstTwenty, static fn(array $item): bool => in_array('meydan_speaker', (array) ($item['actor_roles'] ?? []), true)));
assertTrue($speakerCount > 0, 'Speaker posts must not be removed completely.');
assertTrue($speakerCount <= 8, 'Speaker role ratio must be capped at 40% of top 20.');

$quotaGenerator = new Meydan\Core\Feed\CandidateGenerator();
$quotaSelection = $quotaGenerator->selectBySourceBudgets([
    'speaker' => range(1, 100),
    'general' => range(1001, 1100),
], ['speaker' => 3, 'general' => 7], 10);
assertSame(10, count($quotaSelection));
assertTrue(count(array_intersect($quotaSelection, range(1, 100))) <= 3, 'Source quota must cap speaker candidates.');
assertTrue(count(array_intersect($quotaSelection, range(1001, 1100))) >= 7, 'Source quota must preserve normal candidates.');

$missingSourceSelection = $quotaGenerator->selectBySourceBudgets([
    'speaker' => [],
    'recent' => range(2001, 2010),
    'general' => range(3001, 3010),
], ['speaker' => 75, 'recent' => 10, 'general' => 10], 10);
assertSame(10, count($missingSourceSelection), 'Missing sources must not starve available sources.');
assertSame($missingSourceSelection, $quotaGenerator->selectBySourceBudgets([
    'speaker' => [], 'recent' => range(2001, 2010), 'general' => range(3001, 3010),
], ['speaker' => 75, 'recent' => 10, 'general' => 10], 10), 'Quota ordering must be deterministic.');

$onlySpeakerItems = [];
for ($i = 0; $i < 20; $i++) {
    $onlySpeakerItems[] = [
        'narrative_id' => 7000 + $i,
        'actor_type' => 'user',
        'actor_id' => 700 + $i,
        'actor_roles' => ['meydan_speaker'],
        'source_names' => ['speaker'],
        'score' => 100 - $i,
        'age_hours' => 1.0,
    ];
}
$onlySpeakerOutput = $diversity->rerank($onlySpeakerItems, $diversitySettings);
assertSame(20, count($onlySpeakerOutput), 'Role caps must not empty an otherwise valid role-only feed.');

$officialItems = [];
for ($i = 0; $i < 20; $i++) {
    $officialItems[] = [
        'narrative_id' => 8000 + $i,
        'actor_type' => 'user',
        'actor_id' => 800 + $i,
        'actor_roles' => ['meydan_official'],
        'source_names' => ['recent'],
        'score' => 100 - $i,
        'age_hours' => 1.0,
    ];
}
$officialOutput = $diversity->rerank($officialItems, $diversitySettings);
assertSame(20, count($officialOutput), 'Official-only feeds must remain usable.');
$mixed = $diversity->rerank($diversityItems, $diversitySettings);
assertSame(count($diversityItems), count($mixed));
for ($offset = 0; $offset < count($mixed); $offset += 20) {
    $chunk = array_slice($mixed, $offset, 20);
    $actors = [];
    $speakerBlocks = [];
    foreach ($chunk as $index => $item) {
        $actor = (string) $item['actor_type'] . ':' . (int) $item['actor_id'];
        $actors[$actor] = ($actors[$actor] ?? 0) + 1;
        if (in_array('meydan_speaker', (array) ($item['actor_roles'] ?? []), true)) {
            $speakerBlocks[intdiv($index, 10)] = ($speakerBlocks[intdiv($index, 10)] ?? 0) + 1;
        }
        if ($index > 0) {
            $previous = $chunk[$index - 1];
            $previousActor = (string) $previous['actor_type'] . ':' . (int) $previous['actor_id'];
            if ($actor === $previousActor) throw new RuntimeException('Diversity should separate repeated actors.');
        }
    }
    foreach ($actors as $actor => $count) {
        if (str_starts_with($actor, 'square:') && $count > 3) throw new RuntimeException('Square cap exceeded in a chunk.');
    }
    for ($block = 0; $block < 2; $block++) {
        if (($speakerBlocks[$block] ?? 0) < 1 || ($speakerBlocks[$block] ?? 0) > 3) throw new RuntimeException('Speaker quota violated in a ten-item block.');
    }
}

$ranker = new Meydan\Core\Feed\FeedRanker();
$seedItems = [];
for ($i = 0; $i < 12; $i++) $seedItems[] = ['narrative_id' => 3000 + $i, 'score' => 10.0, 'age_hours' => 1.0];
$seedA = $ranker->rank($seedItems, 'refresh-a');
$seedARepeat = $ranker->rank($seedItems, 'refresh-a');
$seedB = $ranker->rank($seedItems, 'refresh-b');
assertSame(array_column($seedA, 'narrative_id'), array_column($seedARepeat, 'narrative_id'));
if (array_column($seedA, 'narrative_id') === array_column($seedB, 'narrative_id')) throw new RuntimeException('Different refresh seeds should change equal-score ordering.');
$closeItems = [
    ['narrative_id' => 9101, 'score' => 100.0, 'age_hours' => 1.0],
    ['narrative_id' => 9102, 'score' => 99.0, 'age_hours' => 1.0],
    ['narrative_id' => 9103, 'score' => 98.0, 'age_hours' => 1.0],
];
$closeA = $ranker->rank($closeItems, 'refresh-close-a');
$closeB = $ranker->rank($closeItems, 'refresh-close-b');
if (array_column($closeA, 'narrative_id') === array_column($closeB, 'narrative_id')) throw new RuntimeException('Refresh should vary close-score ordering.');

echo "Feed V2 unit checks passed.\n";
