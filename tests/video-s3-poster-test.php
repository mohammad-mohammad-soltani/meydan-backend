<?php

declare(strict_types=1);

use Meydan\Core\Storage\LocalStorage;
use Meydan\Core\Storage\MediaPipeline;
use Meydan\Core\Storage\VideoDerivatives;
use Meydan\Core\Uploads\VideoProcessor;

require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/StorageInterface.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/LocalStorage.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/MediaPipeline.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Uploads/VideoProcessor.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/VideoDerivatives.php';

$root = sys_get_temp_dir() . '/meydan-video-poster-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$video = $root . '/clip.mp4';
$storage = new LocalStorage($root . '/objects', 'https://media.example.test', 'production');
$pipeline = new MediaPipeline($storage, 104857600, ['video/mp4', 'image/jpeg']);

try {
    $command = 'ffmpeg -y -hide_banner -loglevel error -f lavfi -i testsrc2=size=640x360:rate=10 -t 2 -c:v mpeg4 ' . escapeshellarg($video);
    exec($command, $output, $code);
    if ($code !== 0) throw new RuntimeException('fixture generation failed');

    $processed = VideoProcessor::processFile($video);
    if (!$processed['poster_exists']) throw new RuntimeException('poster generation failed');

    $stored = VideoDerivatives::uploadPoster($pipeline, $processed['poster_path'], 'production/eitaa/channel/clip.mp4');
    $expectedPosterKey = VideoDerivatives::posterKey('production/eitaa/channel/clip.mp4');
    if ($stored['key'] !== $expectedPosterKey) throw new RuntimeException('poster key is incorrect');
    if ($stored['url'] !== 'https://media.example.test/' . $expectedPosterKey) throw new RuntimeException('poster URL is incorrect');
    $poster = $root . '/objects/' . $expectedPosterKey;
    if (!is_file($poster) || filesize($poster) <= 0 || filesize($poster) > 400000) throw new RuntimeException('small poster was not uploaded');
    if ($storage->head($stored['key'])['content_type'] !== 'image/jpeg') throw new RuntimeException('poster content type is incorrect');

    $backfill = VideoDerivatives::backfill($pipeline, $video, 'production/eitaa/channel/old.mp4');
    if ($backfill === null || $backfill['key'] !== VideoDerivatives::posterKey('production/eitaa/channel/old.mp4')) throw new RuntimeException('old video poster backfill failed');
    if (!is_file($root . '/objects/' . $backfill['key'])) throw new RuntimeException('backfill did not persist the still');
    if (!$backfill['duration'] || !$backfill['width'] || !$backfill['height']) throw new RuntimeException('backfill did not probe video metadata');

    echo "S3 video poster derivative ok\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($root);
    }
}
