<?php

/**
 * Functional check for the video pipeline: a non-faststart fixture must be
 * remuxed with `moov` inside the first 64 KB and get a poster + probed size.
 *
 * Runs against the file-level helpers only (no WordPress bootstrap), so it can
 * execute on the host or inside the WordPress image:
 *
 *   MEYDAN_VIDEO_FIXTURE=/tmp/in.mp4 php tests/video-pipeline-check.php
 *   php tests/video-pipeline-check.php /tmp/in.mp4
 */

declare(strict_types=1);

$candidates = array_filter([
    (string) getenv('MEYDAN_PLUGIN_DIR'),
    '/var/www/html/wp-content/plugins/meydan-core',
    __DIR__ . '/../wp-content/plugins/meydan-core',
]);
foreach ($candidates as $dir) {
    $processor = rtrim($dir, '/') . '/src/Uploads/VideoProcessor.php';
    if (is_file($processor)) {
        require_once $processor;
        break;
    }
}

$class = \Meydan\Core\Uploads\VideoProcessor::class;
$fixture = (string) (getenv('MEYDAN_VIDEO_FIXTURE') ?: ($argv[1] ?? ''));

function fail(string $message): never
{
    fwrite(STDERR, 'video pipeline check failed: ' . $message . "\n");
    exit(1);
}

if (!class_exists($class)) {
    fail('VideoProcessor could not be loaded');
}
if (!$class::available()) {
    fail('ffmpeg is not available');
}
if ($fixture === '' || !is_file($fixture)) {
    fail('fixture not found (set MEYDAN_VIDEO_FIXTURE)');
}

if ($class::isFaststart($fixture)) {
    fail('fixture already has moov up front; regenerate it with ffmpeg without +faststart');
}

$result = $class::processFile($fixture);

if (!$result['remuxed'] || !$class::isFaststart($fixture)) {
    fail('file was not remuxed as faststart');
}

$head = (string) file_get_contents($fixture, false, null, 0, 65536);
if (substr_count($head, 'moov') < 1) {
    fail('moov is not inside the first 64 KB');
}
if (!$result['poster_exists'] || !is_file($result['poster_path'])) {
    fail('poster frame was not generated');
}
if (is_file($result['poster_path']) && filesize($result['poster_path']) > 400 * 1024) {
    fail('poster is unexpectedly large');
}
if (!$result['duration'] || !$result['width'] || !$result['height']) {
    fail('duration/width/height were not probed');
}

printf(
    "video pipeline ok: remuxed=%s moov_in_64k=%d duration=%s %dx%d poster=%dB\n",
    $result['remuxed'] ? 'yes' : 'no',
    substr_count($head, 'moov'),
    (string) $result['duration'],
    (int) $result['width'],
    (int) $result['height'],
    (int) filesize($result['poster_path'])
);
