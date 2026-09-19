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

echo "Feed V2 unit checks passed.\n";
