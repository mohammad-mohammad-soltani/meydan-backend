<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use InvalidArgumentException;
use Meydan\Core\Uploads\VideoProcessor;

/** Persist a small video still beside its S3 original. */
final class VideoDerivatives
{
    public static function posterKey(string $videoKey): string
    {
        $extension = pathinfo($videoKey, PATHINFO_EXTENSION);
        if ($extension === '' || !VideoProcessor::isVideoPath($videoKey)) {
            throw new InvalidArgumentException('A video storage key is required.');
        }

        $directory = trim(dirname($videoKey), '/.');
        $stem = pathinfo($videoKey, PATHINFO_FILENAME);
        $suffix = substr(hash('sha256', $videoKey), 0, 12);
        return ($directory !== '' ? $directory . '/' : '') . $stem . '-poster-' . $suffix . '.jpg';
    }

    /** @return array{key:string,url:string} */
    public static function uploadPoster(MediaPipeline $pipeline, string $posterPath, string $videoKey): array
    {
        $stored = $pipeline->upload($posterPath, self::posterKey($videoKey), 'image/jpeg');
        return ['key' => $stored['key'], 'url' => $pipeline->storage()->url($stored['key'])];
    }

    /**
     * Build a still for an existing object using its trusted public storage URL.
     * ffmpeg reads only the ranges needed for the frame; no persistent copy of
     * the source video is written to the WordPress host.
     *
     * @return array{key:string,url:string,duration:?float,width:?int,height:?int}|null
     */
    public static function backfill(MediaPipeline $pipeline, string $source, string $videoKey): ?array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'meydan-poster-');
        if ($temporary === false) return null;
        @unlink($temporary);
        $poster = $temporary . '.jpg';

        try {
            if (!VideoProcessor::makePoster($source, $poster)) return null;
            $stored = self::uploadPoster($pipeline, $poster, $videoKey);
            $probe = VideoProcessor::probe($source);
            return $stored + [
                'duration' => $probe['duration'],
                'width' => $probe['width'],
                'height' => $probe['height'],
            ];
        } finally {
            if (is_file($poster)) @unlink($poster);
        }
    }
}
