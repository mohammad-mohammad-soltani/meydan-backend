<?php

declare(strict_types=1);

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
    }
}

require_once __DIR__ . '/../src/Support/Handles.php';
require_once __DIR__ . '/../src/Support/Mentions.php';

use Meydan\Core\Support\Mentions;

assertSame(['reza_s'], Mentions::extract('سلام @reza_s چطوری'));
assertSame(['reza_s', 'ali'], Mentions::extract("@Reza_S و (@ali) و @REZA_S"), 'lower-cased, de-duplicated, in first-seen order');
assertSame([], Mentions::extract('mail me at a@example.com'), 'an email address is not a mention');
assertSame([], Mentions::extract('@ab too short'));
assertSame(['abc'], Mentions::extract('<p>@abc</p>'), 'a mention may open an HTML paragraph');
assertSame(['abc'], Mentions::extract('،@abc'));
assertSame([], Mentions::extract('@abc@def'), 'glued handles are not mentions');
assertSame(10, count(Mentions::extract(implode(' ', array_map(static fn(int $i): string => '@user' . $i, range(1, 15))))), 'capped at 10');

echo "mentions ok\n";
