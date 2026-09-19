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

$candidate = new Meydan\Core\Feed\Candidate(42, 'following');
$candidate->addSource('same_city');
$candidate->addSource('following');

assertSame(42, $candidate->narrativeId);
assertSame(['following', 'same_city'], $candidate->sourceNames());

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
assertSame(1.45, $scorer->score(feedFixture(['age_hours' => 0]), $feedSettings)['freshness_multiplier']);
assertSame(1.35, $scorer->score(feedFixture(['age_hours' => 6]), $feedSettings)['freshness_multiplier']);
assertSame(1.2, $scorer->score(feedFixture(['age_hours' => 12]), $feedSettings)['freshness_multiplier']);
assertSame(0.85, $scorer->score(feedFixture(['age_hours' => 24]), $feedSettings)['freshness_multiplier']);
assertSame(0.6, $scorer->score(feedFixture(['age_hours' => 48]), $feedSettings)['freshness_multiplier']);
assertSame(0.6, $scorer->score(feedFixture(['age_hours' => 72]), $feedSettings)['freshness_multiplier']);
assertTrue($scorer->score(feedFixture(['age_hours' => 72.0001]), $feedSettings)['excluded']);
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

echo "Feed V2 unit checks passed.\n";
