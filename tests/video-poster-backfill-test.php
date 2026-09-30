<?php

declare(strict_types=1);

use Meydan\Core\Storage\MediaPipeline;
use Meydan\Core\Storage\StorageInterface;
use Meydan\Core\Storage\VideoPosterBackfill;

require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/StorageInterface.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/MediaPipeline.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Uploads/VideoProcessor.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/VideoDerivatives.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/VideoPosterBackfill.php';

$options = [];
$scheduled = [];
$actions = [];
$postMeta = [
    1 => ['_meydan_storage_driver' => 's3', '_meydan_storage_key' => 'clip-1.mp4', 'meydan_poster_url' => 'existing'],
    2 => ['_meydan_storage_driver' => 's3', '_meydan_storage_key' => 'clip-2.mp4'],
    3 => ['_meydan_storage_driver' => 's3', '_meydan_storage_key' => 'clip-3.mp4'],
];

function get_option(string $key): mixed { global $options; return $options[$key] ?? false; }
function update_option(string $key, mixed $value, bool $autoload = false): void { global $options; $options[$key] = $value; }
function get_post_meta(int $id, string $key, bool $single = true): mixed { global $postMeta; return $postMeta[$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): void { global $postMeta; $postMeta[$id][$key] = $value; }
function delete_post_meta(int $id, string $key): void { global $postMeta; unset($postMeta[$id][$key]); }
function add_action(string $hook, array $callback, int $priority = 10): void { global $actions; $actions[$hook] = $callback; }
function wp_next_scheduled(string $hook): int|false { global $scheduled; return $scheduled[$hook] ?? false; }
function wp_schedule_single_event(int $when, string $hook): void { global $scheduled; $scheduled[$hook] = $when; }

final class BackfillWpdb
{
    public string $posts = 'wp_posts';
    public function prepare(string $query, mixed ...$args): array { return [$query, $args]; }
    public function get_var(array $query): int { return 3; }
    public function get_col(array $query): array
    {
        $cursor = $query[1][3];
        $maxId = $query[1][4];
        return array_values(array_filter([1, 2, 3], static fn(int $id): bool => $id > $cursor && $id <= $maxId));
    }
}

final class ExistingPosterStorage implements StorageInterface
{
    public int $checked = 0;
    public bool $fail = false;
    public function put(string $localPath, string $key, string $mimeType): array { throw new RuntimeException('No new object should be uploaded'); }
    public function delete(string $key): void { throw new RuntimeException('No object should be deleted'); }
    public function exists(string $key): bool { $this->checked++; if ($this->fail) throw new RuntimeException('temporary storage error'); return true; }
    public function url(string $key): string { return 'https://media.example.test/' . $key; }
    public function head(string $key): array { throw new RuntimeException('No HEAD needed for an existing poster'); }
}

$wpdb = new BackfillWpdb();
$storage = new ExistingPosterStorage();
$pipeline = new MediaPipeline($storage, 1000000, ['image/jpeg']);

$previousStorage = getenv('MEDIA_STORAGE');
putenv('MEDIA_STORAGE=s3');
VideoPosterBackfill::register();
if (!isset($actions['init'], $actions['meydan_video_poster_backfill'])) throw new RuntimeException('Cron hooks were not registered');
VideoPosterBackfill::schedule();
$firstSchedule = $scheduled['meydan_video_poster_backfill'] ?? null;
VideoPosterBackfill::schedule();
if ($firstSchedule === null || $scheduled['meydan_video_poster_backfill'] !== $firstSchedule) {
    throw new RuntimeException('The first run was not scheduled exactly once');
}

VideoPosterBackfill::processNext($pipeline);
if (($options['meydan_video_poster_backfill_v1']['cursor'] ?? null) !== 2 || $storage->checked !== 1) {
    throw new RuntimeException('First batch did not skip existing metadata or stop after one eligible video');
}
if (($postMeta[2]['meydan_poster_url'] ?? '') !== 'https://media.example.test/' . \Meydan\Core\Storage\VideoDerivatives::posterKey('clip-2.mp4')) {
    throw new RuntimeException('Existing S3 poster was not linked without re-upload');
}

VideoPosterBackfill::processNext($pipeline);
if (($options['meydan_video_poster_backfill_v1']['cursor'] ?? null) !== 3 || $storage->checked !== 2) {
    throw new RuntimeException('Second batch did not advance by one eligible video');
}
VideoPosterBackfill::processNext($pipeline);
if ($options['meydan_video_poster_backfill_v1'] !== 'done') throw new RuntimeException('Backfill did not stop after its snapshot');
VideoPosterBackfill::processNext($pipeline);
if ($storage->checked !== 2) throw new RuntimeException('Completed backfill did more work');
unset($scheduled['meydan_video_poster_backfill']);
VideoPosterBackfill::schedule();
if ($scheduled) throw new RuntimeException('Completed backfill was scheduled again');

// Failed work is retried at most three times, then the automatic pass stops.
$options = [];
unset($postMeta[2]['meydan_poster_url'], $postMeta[2]['meydan_poster_backfill_attempts']);
$storage->fail = true;
for ($run = 0; $run < 10 && ($options['meydan_video_poster_backfill_v1'] ?? null) !== 'done'; $run++) {
    VideoPosterBackfill::processNext($pipeline);
}
if (($postMeta[2]['meydan_poster_backfill_attempts'] ?? null) !== 3
    || ($options['meydan_video_poster_backfill_v1'] ?? null) !== 'done') {
    throw new RuntimeException('Failed poster did not stop after bounded retries');
}
if ($previousStorage === false) putenv('MEDIA_STORAGE'); else putenv('MEDIA_STORAGE=' . $previousStorage);

echo "bounded video poster backfill ok\n";
