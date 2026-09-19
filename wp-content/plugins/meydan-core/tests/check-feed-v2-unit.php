<?php

declare(strict_types=1);

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

$candidate = new Meydan\Core\Feed\Candidate(42, 'following');
$candidate->addSource('same_city');
$candidate->addSource('following');

assertSame(42, $candidate->narrativeId);
assertSame(['following', 'same_city'], $candidate->sourceNames());

echo "Feed V2 unit checks passed.\n";
