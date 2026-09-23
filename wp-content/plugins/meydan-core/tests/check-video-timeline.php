<?php

declare(strict_types=1);

$videoTimelinePosts = [105, 104, 103, 102, 101];
$videoTimelineAttachments = [
    105 => [['media_id' => 5005]],
    104 => [['media_id' => 5004]],
    103 => [['media_id' => 5003]],
    102 => [['media_id' => 5002], ['media_id' => 5001]],
    101 => [],
];
$videoTimelineMimeTypes = [
    5005 => 'image/jpeg',
    5004 => 'video/mp4',
    5003 => 'image/webp',
    5002 => 'video/webm',
    5001 => 'video/mp4',
];
$videoTimelineQueries = [];
$videoTimelineMetaBatches = [];
$videoTimelineCache = [];
$videoTimelineTransients = [];

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

final class VideoTimelineWpdb
{
    public string $posts = 'wp_posts';

    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    public function get_results(array $prepared, string $format): array
    {
        global $videoTimelinePosts, $videoTimelineQueries;
        $videoTimelineQueries[] = $prepared;
        $batchSize = (int) end($prepared['args']);
        $start = 0;
        if (count($prepared['args']) > 1) {
            $cursorId = (int) $prepared['args'][2];
            $position = array_search($cursorId, $videoTimelinePosts, true);
            $start = $position === false ? count($videoTimelinePosts) : $position + 1;
        }
        return array_map(
            static fn(int $id): array => ['ID' => $id, 'post_date_gmt' => sprintf('2026-01-%02d 00:00:00', $id - 90)],
            array_slice($videoTimelinePosts, $start, $batchSize),
        );
    }

    public function get_col(array $prepared): array
    {
        global $videoTimelineMimeTypes;
        return array_values(array_filter(
            array_map('intval', $prepared['args']),
            static fn(int $id): bool => str_starts_with($videoTimelineMimeTypes[$id] ?? '', 'video/'),
        ));
    }
}

$wpdb = new VideoTimelineWpdb();

function get_post_meta(int $postId, string $key, bool $single): mixed
{
    global $videoTimelineAttachments;
    return $videoTimelineAttachments[$postId] ?? [];
}

function get_post_mime_type(int $mediaId): string|false
{
    global $videoTimelineMimeTypes;
    return $videoTimelineMimeTypes[$mediaId] ?? false;
}

function update_meta_cache(string $type, array $ids): array
{
    global $videoTimelineMetaBatches;
    $videoTimelineMetaBatches[] = [$type, $ids];
    return [];
}

function wp_salt(string $scheme): string { return 'test-salt-' . $scheme; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function wp_cache_set(string $key, mixed $value, string $group, int $ttl): bool
{
    global $videoTimelineCache;
    $videoTimelineCache[$group . ':' . $key] = $value;
    return true;
}
function wp_cache_get(string $key, string $group): mixed
{
    global $videoTimelineCache;
    return $videoTimelineCache[$group . ':' . $key] ?? false;
}
function set_transient(string $key, mixed $value, int $ttl): bool
{
    global $videoTimelineTransients;
    $videoTimelineTransients[$key] = $value;
    return true;
}
function get_transient(string $key): mixed
{
    global $videoTimelineTransients;
    return $videoTimelineTransients[$key] ?? false;
}

function assertVideoTimelineSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . sprintf(
            ' Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

require_once __DIR__ . '/../src/Timeline/VideoTimeline.php';
require_once __DIR__ . '/../src/Support/Viewer.php';
require_once __DIR__ . '/../src/Support/Crypto.php';
require_once __DIR__ . '/../src/Support/Cursor.php';
require_once __DIR__ . '/../src/Timeline/TimelineSession.php';

$ids = (new Meydan\Core\Timeline\VideoTimeline())->ids(3, 2);
assertVideoTimelineSame([104, 102], $ids, 'Only narratives with real video MIME types should be selected once.');
assertVideoTimelineSame(3, count($videoTimelineQueries), 'The collector should scan narratives in bounded batches.');
assertVideoTimelineSame(1, count($videoTimelineQueries[0]['args']), 'The first batch should not use an offset or cursor.');
assertVideoTimelineSame(4, count($videoTimelineQueries[1]['args']), 'Later batches should use the previous date and ID as a keyset cursor.');
if (str_contains($videoTimelineQueries[1]['query'], 'OFFSET')) {
    throw new RuntimeException('Video scanning must not use increasingly expensive SQL offsets.');
}
if (str_contains($videoTimelineQueries[0]['query'], 'max_post_age_hours') || str_contains($videoTimelineQueries[0]['query'], 'DATE_SUB')) {
    throw new RuntimeException('The video timeline must not apply the Feed V2 age window.');
}
assertVideoTimelineSame([
    ['post', [105, 104]],
    ['post', [103, 102]],
    ['post', [101]],
], $videoTimelineMetaBatches, 'Narrative metadata should be primed once per batch.');

$viewer = new Meydan\Core\Support\Viewer('user', '7', 7, null, null);
$first = Meydan\Core\Timeline\TimelineSession::start($viewer, 'for_you', 'video', [104, 102, 99], 2);
assertVideoTimelineSame([104, 102], $first['ids'], 'The first video page should respect its page size.');
if (!is_string($first['next_cursor']) || $first['next_cursor'] === '') {
    throw new RuntimeException('A video snapshot larger than one page must return a next cursor.');
}
$second = Meydan\Core\Timeline\TimelineSession::resume($viewer, 'for_you', 'video', $first['next_cursor'], 2);
assertVideoTimelineSame([99], $second['ids'] ?? null, 'The next cursor should resume without duplicates.');
if (!is_array($second) || !array_key_exists('next_cursor', $second)) {
    throw new RuntimeException('The resumed video page must include the cursor contract.');
}
assertVideoTimelineSame(null, $second['next_cursor'], 'The final video page should exhaust the cursor.');

echo "Video timeline checks passed.\n";
