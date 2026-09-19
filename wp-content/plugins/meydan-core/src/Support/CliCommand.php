<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Storage\AttachmentStorage;
use Meydan\Core\Storage\StorageFactory;
use Meydan\Core\Storage\WordPressMediaHooks;

use Meydan\Core\Database\Migrations;
use Meydan\Core\Domain\Registrations;

final class CliCommand
{
    /** Run/repair database migrations. */
    public function migrate(): void
    {
        Migrations::run();
        Registrations::registerRolesAndCapabilities();
        \WP_CLI::success('Meydan migrations completed: ' . Migrations::VERSION);
    }

    /** Upload legacy attachment originals only; resized derivatives are intentionally skipped. */
    public function media_migrate_originals(array $args, array $assocArgs): void
    {
        if (strtolower(trim((string) getenv('MEDIA_STORAGE'))) !== 's3') {
            \WP_CLI::error('MEDIA_STORAGE must be s3.');
        }

        $dryRun = isset($assocArgs['dry-run']);
        $limit = max(0, (int) ($assocArgs['limit'] ?? 0));
        $after = max(0, (int) ($assocArgs['after'] ?? 0));
        $storage = StorageFactory::create();
        $pipeline = WordPressMediaHooks::pipeline();
        $page = 1;
        $processed = $uploaded = $skipped = $failed = 0;

        do {
            $attachments = get_posts([
                'post_type' => 'attachment', 'post_status' => 'inherit',
                'posts_per_page' => 100, 'paged' => $page++,
                'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids',
            ]);
            foreach ($attachments as $attachmentId) {
                $attachmentId = (int) $attachmentId;
                if ($attachmentId <= $after) continue;
                if ($limit > 0 && $processed >= $limit) break 2;
                $processed++;
                if ((string) get_post_meta($attachmentId, '_meydan_storage_driver', true) === 's3') {
                    $skipped++;
                    continue;
                }
                $path = self::legacyOriginalPath($attachmentId);
                if ($path === '' || !is_file($path)) {
                    $skipped++;
                    continue;
                }
                $attached = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
                $filename = basename($path);
                $relativeDir = trim(str_replace('\\', '/', dirname($attached)), '/.');
                $directory = preg_match('#^\d{4}/\d{2}$#', $relativeDir) ? 'uploads/' . $relativeDir : 'uploads/' . gmdate('Y/m');
                $key = self::legacyUniqueKey($storage, $directory, $filename);
                $mime = (string) get_post_mime_type($attachmentId);
                if ($dryRun) {
                    \WP_CLI::log('would upload original ' . $attachmentId . ' -> ' . $key);
                    continue;
                }
                try {
                    $result = $pipeline->upload($path, $key, $mime);
                    update_post_meta($attachmentId, '_meydan_storage_driver', 's3');
                    update_post_meta($attachmentId, '_meydan_storage_key', $result['key']);
                    update_post_meta($attachmentId, '_meydan_storage_derivative_keys', []);
                    update_post_meta($attachmentId, '_meydan_storage_size', $result['size']);
                    update_post_meta($attachmentId, '_meydan_storage_checksum', $result['checksum']);
                    update_post_meta($attachmentId, '_meydan_storage_etag', $result['etag']);
                    update_post_meta($attachmentId, '_wp_attached_file', $result['key']);
                    $uploaded++;
                    \WP_CLI::log('uploaded original ' . $attachmentId . ' -> ' . $result['key']);
                } catch (\Throwable $error) {
                    $failed++;
                    \WP_CLI::warning('attachment ' . $attachmentId . ': ' . $error->getMessage());
                }
            }
        } while ($attachments);

        \WP_CLI::success(sprintf('%d scanned, %d originals uploaded, %d skipped, %d failed%s.', $processed, $uploaded, $skipped, $failed, $dryRun ? ' (dry-run)' : ''));
    }

    private static function legacyUniqueKey(\Meydan\Core\Storage\StorageInterface $storage, string $directory, string $filename): string
    {
        $filename = sanitize_file_name($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = $extension !== '' ? substr($filename, 0, -strlen($extension) - 1) : $filename;
        for ($index = 0; $index < 10000; $index++) {
            $suffix = $index === 0 ? '' : '-' . $index;
            $candidate = trim($directory, '/') . '/' . $stem . $suffix . ($extension !== '' ? '.' . $extension : '');
            if (!$storage->exists($candidate)) return $candidate;
        }
        throw new \RuntimeException('Unable to allocate a unique legacy media key.');
    }

    private static function legacyOriginalPath(int $attachmentId): string
    {
        $attachedPath = AttachmentStorage::localPath($attachmentId);
        if ($attachedPath === '' || !is_file($attachedPath)) return '';

        $metadata = (array) wp_get_attachment_metadata($attachmentId);
        $originalImage = (string) ($metadata['original_image'] ?? '');
        if ($originalImage !== '') {
            $candidate = dirname($attachedPath) . '/' . basename($originalImage);
            if (is_file($candidate)) return $candidate;
        }

        $name = basename($attachedPath);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
        if (str_ends_with($stem, '-scaled')) {
            $candidate = dirname($attachedPath) . '/' . substr($stem, 0, -7) . ($extension !== '' ? '.' . $extension : '');
            if (is_file($candidate)) return $candidate;
        }

        return $attachedPath;
    }

    /** Import the frontend fixtures into real WordPress entities. Use --force to rebuild seeded records. */
    public function seed(array $args, array $assocArgs): void
    {
        $result = SeedData::run(isset($assocArgs['force']));
        SeedRepairs::run();
        if (!empty($result['skipped'])) {
            \WP_CLI::success('Meydan seed already applied; taxonomy/data repairs verified: ' . $result['version']);
            return;
        }
        foreach ($result as $key => $value) {
            if ($key === 'skipped') continue;
            \WP_CLI::log($key . ': ' . (is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE)));
        }
        \WP_CLI::success('Meydan fixture import completed.');
    }

    /**
     * Remux uploaded videos so their metadata sits at the front and generate posters.
     *
     * Existing uploads predate the faststart pass, so their `moov` atom is still at
     * EOF. This scans `/wp-content/uploads/**`, remuxes only the files whose `moov`
     * is not inside the first 64 KB (temp file + atomic rename) and writes a still
     * frame plus duration/dimensions for every video attachment.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change without modifying any file.
     *
     * [--posters-only]
     * : Generate missing poster frames without remuxing.
     *
     * [--limit=<number>]
     * : Stop after this many video files.
     *
     * [--path=<path>]
     * : Scan this directory instead of the whole uploads directory.
     *
     * ## EXAMPLES
     *
     *     wp meydan video-faststart --dry-run
     *     wp meydan video-faststart
     *
     * @subcommand video-faststart
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function video_faststart(array $args, array $assocArgs): void
    {
        $processor = \Meydan\Core\Uploads\VideoProcessor::class;
        if (!$processor::available()) {
            \WP_CLI::error('ffmpeg is not installed on this host. Rebuild the WordPress image (docker/wordpress/Dockerfile) or install ffmpeg, then run this again.');
        }

        $uploads = wp_upload_dir();
        $basedir = (string) $uploads['basedir'];
        $root = isset($assocArgs['path']) && (string) $assocArgs['path'] !== ''
            ? (string) $assocArgs['path']
            : $basedir;
        if (!is_dir($root)) {
            \WP_CLI::error('Uploads directory not found: ' . $root);
        }

        $dryRun = isset($assocArgs['dry-run']);
        $postersOnly = isset($assocArgs['posters-only']);
        $limit = max(0, (int) ($assocArgs['limit'] ?? 0));
        $index = self::attachmentIndex();

        \WP_CLI::log(sprintf(
            '%s uploads under %s (ffmpeg: %s)',
            $dryRun ? 'Scanning' : 'Processing',
            $root,
            (string) ($processor::binaries()['ffmpeg'] ?? 'missing')
        ));

        $scanned = 0;
        $remuxed = 0;
        $already = 0;
        $posters = 0;
        $errors = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!$processor::isVideoPath($path)) {
                continue;
            }
            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $scanned++;

            $result = $processor::processFile($path, [
                'remux' => !$dryRun && !$postersOnly,
                'poster' => !$dryRun,
            ]);

            $attachmentId = $index[$path] ?? 0;
            if (!$dryRun && $attachmentId > 0) {
                $processor::storeAttachmentMeta($attachmentId, $result);
            }

            if ($result['remuxed']) {
                $remuxed++;
                \WP_CLI::log('remuxed      ' . $path);
            } elseif ($dryRun && $result['faststart'] === false) {
                $remuxed++;
                \WP_CLI::log('needs remux  ' . $path);
            } elseif ($result['faststart'] === true) {
                $already++;
            }

            if ($result['poster_generated']) {
                $posters++;
                \WP_CLI::log('poster       ' . $result['poster_path']);
            } elseif ($dryRun && !$result['poster_exists']) {
                \WP_CLI::log('needs poster ' . $path);
            }

            if ($result['errors']) {
                $errors++;
                \WP_CLI::warning($path . ': ' . implode('; ', $result['errors']));
            }
        }

        \WP_CLI::success(sprintf(
            '%d video file(s): %d %s, %d already had moov in the first 64 KB, %d poster(s) generated, %d error(s).',
            $scanned,
            $remuxed,
            $dryRun ? 'would be remuxed' : 'remuxed',
            $already,
            $posters,
            $errors
        ));
    }

    /**
     * Map every attachment's absolute file path to its post id.
     *
     * @return array<string,int>
     */
    private static function attachmentIndex(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_wp_attached_file'
        ));

        $index = [];
        foreach ((array) $rows as $row) {
            $absolute = AttachmentStorage::localPath((int) $row->post_id);
            if ($absolute === '') continue;
            $index[wp_normalize_path($absolute)] = (int) $row->post_id;
        }

        return $index;
    }

    /** Print backend status. */
    public function status(): void
    {
        global $wpdb;
        $tables = [
            'auth_challenges', 'sessions', 'interactions', 'narrative_stats', 'content_stats',
            'served_history', 'actor_affinity', 'content_creators', 'initiative_members',
            'square_geo', 'speaker_requests', 'uploads', 'events', 'notifications', 'audit_log',
            'media_reflections', 'square_schedule', 'provinces', 'cities', 'idempotency',
        ];
        $missing = [];
        foreach ($tables as $table) {
            $name = $wpdb->prefix . 'meydan_' . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                $missing[] = $name;
            }
        }
        \WP_CLI::log('Meydan Core ' . MEYDAN_CORE_VERSION);
        \WP_CLI::log('DB version: ' . get_option('meydan_db_version', 'none'));
        \WP_CLI::log('Seed version: ' . get_option('meydan_seed_version', 'none'));
        \WP_CLI::log('Chat feature: ' . (((array) get_option('meydan_feature_flags', []))['chat'] ?? false ? 'enabled' : 'disabled'));
        if ($missing) {
            \WP_CLI::error('Missing tables: ' . implode(', ', $missing));
        }
        \WP_CLI::success('All Meydan tables are present.');
    }
}
