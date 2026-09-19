<?php

declare(strict_types=1);

namespace Meydan\Core\Uploads;

use Meydan\Core\Storage\AttachmentStorage;

/**
 * MP4 "faststart" remux plus poster/duration extraction for uploaded videos.
 *
 * A file whose `moov` atom sits at the end forces the browser to download the
 * tail before it can show a frame (and makes iOS/Safari give up). Every video
 * that enters the site is therefore remuxed with `-c copy -movflags +faststart`
 * (no re-encode, no quality loss) and gets a small still frame plus its real
 * duration, width and height.
 *
 * The file-level helpers (`isFaststart`, `remuxFaststart`, `makePoster`,
 * `probe`, `processFile`) avoid WordPress calls so they can run in a plain PHP
 * harness; the `*Attachment`/`describe` helpers add the WordPress glue.
 */
final class VideoProcessor
{
    /** A `moov` atom that starts after this many bytes is treated as "at EOF". */
    public const MOOV_WINDOW = 65536;

    /** Long edge of the generated poster, per the media contract. */
    public const POSTER_MAX_EDGE = 720;

    /** Timestamp (seconds) the poster frame is taken from. */
    public const POSTER_TIMESTAMP = 1.0;

    /** JPEG quality for the poster (ffmpeg qscale; lower is better/larger). */
    public const POSTER_QUALITY = 12;

    /** Containers that carry a `moov` atom and accept `+faststart`. */
    private const REMUX_EXTENSIONS = ['mp4', 'm4v', 'mov'];

    /** Extensions we treat as video for poster/duration purposes. */
    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm', 'ogv', 'mkv', 'avi'];

    /** @var array{ffmpeg:?string,ffprobe:?string}|null */
    private static ?array $binaries = null;

    /**
     * Absolute paths of the ffmpeg/ffprobe binaries, or null when not installed.
     *
     * @return array{ffmpeg:?string,ffprobe:?string}
     */
    public static function binaries(): array
    {
        if (self::$binaries !== null) {
            return self::$binaries;
        }

        return self::$binaries = [
            'ffmpeg' => self::locate('ffmpeg'),
            'ffprobe' => self::locate('ffprobe'),
        ];
    }

    /** Whether the remux/poster pipeline can run on this host. */
    public static function available(): bool
    {
        return self::binaries()['ffmpeg'] !== null;
    }

    /** Whether the path/extension is a video we should process. */
    public static function isVideoPath(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true);
    }

    /** Whether ffmpeg may rewrite this container as faststart. */
    public static function canRemux(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::REMUX_EXTENSIONS, true);
    }

    /** Sibling poster path for a video file (`video-12.mp4` → `video-12-poster.jpg`). */
    public static function posterPath(string $file): string
    {
        return dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '-poster.jpg';
    }

    /**
     * Walk the top-level MP4 atom table and report whether `moov` starts inside
     * the first $window bytes.
     */
    public static function isFaststart(string $file, int $window = self::MOOV_WINDOW): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }

        $offset = 0;
        $faststart = false;
        $fileSize = (int) @filesize($file);

        while ($offset < $window) {
            if (fseek($handle, $offset) !== 0) {
                break;
            }
            $header = (string) fread($handle, 8);
            if (strlen($header) < 8) {
                break;
            }

            $size = (int) (unpack('N', substr($header, 0, 4))[1] ?? 0);
            $type = substr($header, 4, 4);

            if ($size === 1) {
                $large = (string) fread($handle, 8);
                if (strlen($large) < 8) {
                    break;
                }
                $parts = unpack('N2', $large);
                $size = ((int) $parts[1] << 32) | (int) $parts[2];
            } elseif ($size === 0) {
                $size = max(8, $fileSize - $offset);
            }

            if ($type === 'moov') {
                $faststart = true;
                break;
            }
            if ($size < 8) {
                break; // malformed box; stop rather than loop forever
            }

            $offset += $size;
        }

        fclose($handle);

        return $faststart;
    }

    /**
     * Remux a video so `moov` is written before `mdat`, atomically replacing the
     * original file (same filename, so existing URLs keep working).
     *
     * @return array{ok:bool,error:?string}
     */
    public static function remuxFaststart(string $file): array
    {
        $ffmpeg = self::binaries()['ffmpeg'];
        if ($ffmpeg === null) {
            return ['ok' => false, 'error' => 'ffmpeg is not installed'];
        }

        $tmp = $file . '.faststart.mp4';
        @unlink($tmp);

        [$code, , $stderr] = self::run([
            $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $file,
            '-c', 'copy',
            '-movflags', '+faststart',
            $tmp,
        ], 600);

        if ($code !== 0 || !is_file($tmp) || (int) filesize($tmp) < 1024) {
            @unlink($tmp);
            return ['ok' => false, 'error' => trim($stderr) !== '' ? trim($stderr) : 'ffmpeg exited with code ' . $code];
        }

        $perms = @fileperms($file);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'could not replace ' . $file];
        }
        if ($perms !== false) {
            @chmod($file, $perms & 0777);
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Probe duration and display dimensions (rotation-aware) with ffprobe.
     *
     * @return array{duration:?float,width:?int,height:?int,error:?string}
     */
    public static function probe(string $file): array
    {
        $empty = ['duration' => null, 'width' => null, 'height' => null, 'error' => null];
        $ffprobe = self::binaries()['ffprobe'];
        if ($ffprobe === null) {
            $empty['error'] = 'ffprobe is not installed';
            return $empty;
        }

        [$code, $stdout, $stderr] = self::run([
            $ffprobe, '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', $file,
        ], 60);

        if ($code !== 0) {
            $empty['error'] = trim($stderr) !== '' ? trim($stderr) : 'ffprobe exited with code ' . $code;
            return $empty;
        }

        $info = json_decode($stdout, true);
        if (!is_array($info)) {
            $empty['error'] = 'ffprobe returned invalid JSON';
            return $empty;
        }

        $video = null;
        foreach ((array) ($info['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
                $video = $stream;
                break;
            }
        }

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        // A rotated phone video carries a display matrix; the browser rotates it
        // for us, so report the orientation the viewer actually sees.
        if (is_array($video)) {
            $rotation = self::rotation($video);
            if (in_array(abs($rotation) % 360, [90, 270], true) && $width !== null && $height !== null) {
                [$width, $height] = [$height, $width];
            }
        }

        $duration = $info['format']['duration'] ?? ($video['duration'] ?? null);
        $duration = is_numeric($duration) ? round((float) $duration, 3) : null;
        if ($duration !== null && $duration <= 0.0) {
            $duration = null;
        }

        return ['duration' => $duration, 'width' => $width, 'height' => $height, 'error' => null];
    }

    /**
     * Extract a JPEG still around second 1, scaled to at most $maxEdge long edge.
     * Falls back to the first frame when the clip is shorter than the timestamp.
     */
    public static function makePoster(string $file, string $poster, float $timestamp = self::POSTER_TIMESTAMP, int $maxEdge = self::POSTER_MAX_EDGE): bool
    {
        $ffmpeg = self::binaries()['ffmpeg'];
        if ($ffmpeg === null) {
            return false;
        }

        // Quotes are part of ffmpeg's filter syntax (the comma inside min() must
        // not be read as a filter separator).
        $filter = "scale='min(" . $maxEdge . ",iw)':-2";
        foreach ([$timestamp, 0.0] as $seek) {
            @unlink($poster);
            [$code] = self::run([
                $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string) $seek,
                '-i', $file,
                '-frames:v', '1',
                '-vf', $filter,
                '-q:v', (string) self::POSTER_QUALITY,
                $poster,
            ], 120);

            if ($code === 0 && is_file($poster) && (int) filesize($poster) > 0) {
                return true;
            }
        }

        @unlink($poster);

        return false;
    }

    /**
     * File-level pipeline: probe, remux when needed, generate a poster.
     *
     * @param array{remux?:bool,poster?:bool} $options
     * @return array{
     *     file:string,
     *     video:bool,
     *     faststart:?bool,
     *     remuxed:bool,
     *     poster_path:string,
     *     poster_exists:bool,
     *     poster_generated:bool,
     *     duration:?float,
     *     width:?int,
     *     height:?int,
     *     errors:list<string>
     * }
     */
    public static function processFile(string $file, array $options = []): array
    {
        $remux = $options['remux'] ?? true;
        $poster = $options['poster'] ?? true;

        $result = [
            'file' => $file,
            'video' => self::isVideoPath($file),
            'faststart' => null,
            'remuxed' => false,
            'poster_path' => '',
            'poster_exists' => false,
            'poster_generated' => false,
            'duration' => null,
            'width' => null,
            'height' => null,
            'errors' => [],
        ];

        if (!$result['video'] || !is_file($file)) {
            return $result;
        }

        if (self::available()) {
            $probe = self::probe($file);
            $result['duration'] = $probe['duration'];
            $result['width'] = $probe['width'];
            $result['height'] = $probe['height'];
            if ($probe['error'] !== null) {
                $result['errors'][] = 'probe: ' . $probe['error'];
            }
        } else {
            $result['errors'][] = 'ffmpeg is not installed; skipped';
        }

        if (self::canRemux($file)) {
            $result['faststart'] = self::isFaststart($file);
            if ($remux && $result['faststart'] === false && self::available()) {
                $remuxResult = self::remuxFaststart($file);
                if ($remuxResult['ok']) {
                    $result['remuxed'] = true;
                    $result['faststart'] = true;
                } else {
                    $result['errors'][] = 'remux: ' . (string) $remuxResult['error'];
                }
            }
        }

        $posterPath = self::posterPath($file);
        $result['poster_path'] = $posterPath;
        $result['poster_exists'] = is_file($posterPath) && (int) filesize($posterPath) > 0;
        if ($poster && !$result['poster_exists'] && self::available()) {
            if (self::makePoster($file, $posterPath)) {
                $result['poster_exists'] = true;
                $result['poster_generated'] = true;
            } else {
                $result['errors'][] = 'poster: ffmpeg could not extract a frame';
            }
        }

        return $result;
    }

    /**
     * Run the pipeline for a WordPress attachment and persist the results.
     *
     * Never throws: a video that cannot be improved is still a valid upload.
     *
     * @return array{poster_url:?string,thumbnail_url:?string,duration:?float,width:?int,height:?int}
     */
    public static function processAttachment(int $attachmentId): array
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) {
            return self::payload(null, null, null, null);
        }

        try {
            // A stream copy is quick, but give large uploads room on slow disks.
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $mime = (string) get_post_mime_type($attachmentId);
            $path = AttachmentStorage::localPath($attachmentId);
            $isVideo = str_starts_with($mime, 'video/') || ($path !== '' && self::isVideoPath($path));
            if (!$isVideo || $path === '' || !is_file($path)) {
                return self::payload(null, null, null, null);
            }

            $result = self::processFile($path);

            foreach ($result['errors'] as $error) {
                error_log('Meydan video processing for attachment ' . $attachmentId . ': ' . $error);
            }

            return self::storeAttachmentMeta($attachmentId, $result);
        } catch (\Throwable $e) {
            error_log('Meydan video processing failed for attachment ' . $attachmentId . ': ' . $e->getMessage());
            return self::payload(null, null, null, null);
        }
    }

    /**
     * Persist a `processFile()` result on the attachment and return the API payload.
     *
     * @param array<string,mixed> $result
     * @return array{poster_url:?string,thumbnail_url:?string,duration:?float,width:?int,height:?int}
     */
    public static function storeAttachmentMeta(int $attachmentId, array $result): array
    {
        if ($result['duration'] !== null) {
            update_post_meta($attachmentId, 'meydan_video_duration', (float) $result['duration']);
        }
        if ($result['width'] !== null) {
            update_post_meta($attachmentId, 'meydan_video_width', (int) $result['width']);
        }
        if ($result['height'] !== null) {
            update_post_meta($attachmentId, 'meydan_video_height', (int) $result['height']);
        }
        if ($result['faststart'] !== null) {
            update_post_meta($attachmentId, 'meydan_video_faststart', $result['faststart'] ? 1 : 0);
        }

        $posterUrl = null;
        $posterPath = (string) ($result['poster_path'] ?? '');
        if ($posterPath !== '' && is_file($posterPath)) {
            $posterUrl = self::urlForPath($posterPath);
            if ($posterUrl !== '') {
                update_post_meta($attachmentId, 'meydan_poster_path', $posterPath);
                update_post_meta($attachmentId, 'meydan_poster_url', $posterUrl);
            }
        }

        // Keep WordPress' own `length` (seconds) in sync so other consumers see it.
        if ($result['duration'] !== null) {
            $metadata = wp_get_attachment_metadata($attachmentId);
            if (is_array($metadata)) {
                $metadata['length'] = (int) round((float) $result['duration']);
                wp_update_attachment_metadata($attachmentId, $metadata);
            }
        }

        return self::payload(
            $posterUrl !== '' ? $posterUrl : null,
            $result['duration'] !== null ? (float) $result['duration'] : null,
            $result['width'] !== null ? (int) $result['width'] : null,
            $result['height'] !== null ? (int) $result['height'] : null,
        );
    }

    /**
     * Describe a video attachment for the API: poster/duration/dimensions.
     *
     * Works for real attachments (meta + sibling poster file) and for plain URLs
     * that live under the uploads directory.
     *
     * @param array<string,mixed> $metadata wp_get_attachment_metadata()
     * @param array<string,mixed> $item     raw attachment item from post meta
     * @return array{poster_url:?string,duration:?float,width:?int,height:?int}
     */
    public static function describe(int $mediaId, string $url, string $path, array $metadata = [], array $item = []): array
    {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
        if ($path === '' && $url !== '' && is_array($uploads) && !empty($uploads['baseurl'])) {
            $baseUrl = (string) $uploads['baseurl'];
            if (str_starts_with($url, $baseUrl)) {
                $path = (string) $uploads['basedir'] . substr($url, strlen($baseUrl));
            }
        }

        $posterUrl = null;
        $posterPath = '';
        if ($mediaId > 0) {
            $stored = get_post_meta($mediaId, 'meydan_poster_url', true);
            $posterUrl = is_string($stored) && $stored !== '' ? $stored : null;
            $storedPath = get_post_meta($mediaId, 'meydan_poster_path', true);
            $posterPath = is_string($storedPath) ? $storedPath : '';
        }
        if ($posterPath === '' && $path !== '' && self::isVideoPath($path)) {
            $posterPath = self::posterPath($path);
        }
        if ($posterUrl === null && $posterPath !== '' && is_file($posterPath)) {
            $derived = self::urlForPath($posterPath);
            $posterUrl = $derived !== '' ? $derived : null;
        }

        $duration = null;
        if ($mediaId > 0) {
            $stored = get_post_meta($mediaId, 'meydan_video_duration', true);
            if (is_numeric($stored) && (float) $stored > 0) {
                $duration = round((float) $stored, 3);
            }
        }
        if ($duration === null && isset($metadata['length']) && (float) $metadata['length'] > 0) {
            $duration = (float) $metadata['length'];
        }
        if ($duration === null && isset($item['duration']) && (float) $item['duration'] > 0) {
            $duration = (float) $item['duration'];
        }

        $width = self::metaInt($mediaId, 'meydan_video_width');
        $height = self::metaInt($mediaId, 'meydan_video_height');
        if ($width === null && isset($metadata['width'])) {
            $width = (int) $metadata['width'] ?: null;
        }
        if ($height === null && isset($metadata['height'])) {
            $height = (int) $metadata['height'] ?: null;
        }

        return self::payload($posterUrl, $duration, $width, $height);
    }

    /**
     * Map an absolute uploads path back to its public URL.
     */
    public static function urlForPath(string $path): string
    {
        if (!function_exists('wp_get_upload_dir')) {
            return '';
        }
        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        if ($basedir === '' || $baseurl === '') {
            return '';
        }

        $basedir = trailingslashit($basedir);
        if (!str_starts_with($path, $basedir)) {
            return '';
        }

        return trailingslashit($baseurl) . ltrim(substr($path, strlen($basedir)), '/');
    }

    /**
     * @return array{poster_url:?string,thumbnail_url:?string,duration:?float,width:?int,height:?int}
     */
    private static function payload(?string $posterUrl, ?float $duration, ?int $width, ?int $height): array
    {
        return [
            'poster_url' => $posterUrl,
            'thumbnail_url' => $posterUrl,
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
        ];
    }

    private static function metaInt(int $mediaId, string $key): ?int
    {
        if ($mediaId <= 0) {
            return null;
        }
        $value = get_post_meta($mediaId, $key, true);
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string,mixed> $stream */
    private static function rotation(array $stream): int
    {
        foreach ((array) ($stream['side_data_list'] ?? []) as $side) {
            if (is_array($side) && array_key_exists('rotation', $side)) {
                return (int) $side['rotation'];
            }
        }
        if (isset($stream['tags']['rotate'])) {
            return (int) $stream['tags']['rotate'];
        }

        return 0;
    }

    /**
     * Resolve a binary once: constant/filter override, common paths, then PATH.
     */
    private static function locate(string $binary): ?string
    {
        $override = '';
        $constant = strtoupper('meydan_' . $binary . '_binary');
        if (defined($constant)) {
            $override = (string) constant($constant);
        }
        if (function_exists('apply_filters')) {
            $override = (string) apply_filters('meydan_' . $binary . '_binary', $override);
        }
        if ($override !== '' && @is_executable($override)) {
            return $override;
        }

        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/', '/snap/bin/', '/opt/local/bin/'] as $dir) {
            if (@is_executable($dir . $binary)) {
                return $dir . $binary;
            }
        }

        if (function_exists('shell_exec')) {
            $found = trim((string) @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
            if ($found !== '' && @is_executable($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Run a command without a shell and capture its output.
     *
     * @param list<string> $command
     * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
     */
    private static function run(array $command, int $timeout = 120): array
    {
        if (!function_exists('proc_open')) {
            return [127, '', 'proc_open is disabled'];
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [127, '', 'could not start ' . (string) ($command[0] ?? 'process')];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $timeout;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                $stderr .= "\ncommand timed out after {$timeout}s";
                $exitCode = 124;
                break;
            }
            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }
}
