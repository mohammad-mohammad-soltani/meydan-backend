<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use RuntimeException;
use Throwable;

final class WordPressMediaHooks
{
    private const DRIVER = '_meydan_storage_driver';
    private const KEY = '_meydan_storage_key';
    private const DERIVATIVES = '_meydan_storage_derivative_keys';
    private const SIZE = '_meydan_storage_size';
    private const CHECKSUM = '_meydan_storage_checksum';
    private const ETAG = '_meydan_storage_etag';
    private const PENDING = '_meydan_storage_pending';

    public static function register(): void
    {
        add_action('add_attachment', [self::class, 'markPending'], 10, 1);
        // New Meydan S3 uploads intentionally keep one original object only.
        // This is scoped to pending Meydan attachments; legacy/local media and
        // other plugins retain WordPress' normal image-size behavior.
        add_filter('intermediate_image_sizes_advanced', [self::class, 'disablePendingSizes'], 1, 3);
        add_filter('wp_generate_attachment_metadata', [self::class, 'offloadGeneratedMetadata'], 20, 3);
        add_filter('wp_get_attachment_url', [self::class, 'attachmentUrl'], 20, 2);
        add_filter('wp_calculate_image_srcset', [self::class, 'imageSrcset'], 20, 5);
        add_filter('pre_delete_attachment', [self::class, 'deleteObjectsBeforeAttachment'], 10, 3);
        add_action('delete_attachment', [self::class, 'afterDelete'], 10, 2);
    }

    public static function markPending(int $attachmentId): void
    {
        if (self::s3Enabled()) {
            $path = (string) get_attached_file($attachmentId, true);
            if ($path === '' || !is_file($path)) return;
            add_post_meta($attachmentId, self::PENDING, 1, true);
            MediaLogger::log('upload_started', self::logContext($attachmentId, '', '', 'started'));
            $mime = (string) get_post_mime_type($attachmentId);
            try {
                $validation = self::pipeline()->validateFile($path, $mime);
                if ($validation !== null) {
                    MediaLogger::log('validation_failed', self::logContext($attachmentId, '', '', 'failed', 'validation'));
                    self::failAttachment($attachmentId, $validation->get_error_message());
                }
            } catch (Throwable $error) {
                if (get_post($attachmentId)) self::failAttachment($attachmentId, 'پیکربندی فضای ذخیره‌سازی معتبر نیست.');
                throw $error;
            }
        }
    }

    public static function offloadGeneratedMetadata(array $metadata, int $attachmentId, string $context): array
    {
        if ($context !== 'create' || !self::s3Enabled() || !(int) get_post_meta($attachmentId, self::PENDING, true)) {
            return $metadata;
        }
        $localPath = (string) get_attached_file($attachmentId, true);
        if ($localPath === '' || !is_file($localPath)) {
            MediaLogger::log('validation_failed', self::logContext($attachmentId, '', '', 'failed', 'filesystem'));
            self::failAttachment($attachmentId, 'فایل موقت آپلود پیدا نشد.');
        }

        $sessionId = (string) get_post_meta($attachmentId, 'meydan_upload_session_id', true);
        MediaLogger::log('staging_created', self::logContext($attachmentId, $sessionId, '', 'completed'));
        $mime = (string) get_post_mime_type($attachmentId);
        $pipeline = self::pipeline();
        $validation = $pipeline->validateFile($localPath, $mime);
        if ($validation !== null) {
            MediaLogger::log('validation_failed', self::logContext($attachmentId, $sessionId, '', 'failed', 'validation'));
            self::failAttachment($attachmentId, $validation->get_error_message());
        }

        $originalName = (string) get_post_meta($attachmentId, 'meydan_original_filename', true);
        $logicalKey = self::uniqueLogicalKey($pipeline->storage(), self::basePath($attachmentId), $originalName !== '' ? $originalName : basename($localPath));
        $uploaded = [];
        MediaLogger::log('s3_upload_started', self::logContext($attachmentId, $sessionId, $logicalKey, 'started'));
        try {
            $original = $pipeline->upload($localPath, $logicalKey, $mime);
            $uploaded[] = $original['key'];
            // Videos have one small poster derivative. It lets a feed paint the
            // cover without downloading any bytes of the much larger video.
            // Other generated WordPress sizes remain disabled for S3 uploads.
            $derivatives = [];
            $posterPath = (string) get_post_meta($attachmentId, 'meydan_poster_path', true);
            if ($posterPath !== '' && is_file($posterPath)) {
                try {
                    $poster = $pipeline->upload($posterPath, self::posterLogicalKey($logicalKey), 'image/jpeg');
                    $uploaded[] = $poster['key'];
                    $derivatives['poster'] = $poster['key'];
                } catch (Throwable $posterError) {
                    // A poster improves first paint but must never make the
                    // completed original video unavailable.
                    error_log('Meydan video poster upload failed for attachment ' . $attachmentId . ': ' . $posterError->getMessage());
                    delete_post_meta($attachmentId, 'meydan_poster_path');
                    delete_post_meta($attachmentId, 'meydan_poster_url');
                }
            }

            update_post_meta($attachmentId, self::DRIVER, 's3');
            update_post_meta($attachmentId, self::KEY, $original['key']);
            update_post_meta($attachmentId, self::DERIVATIVES, $derivatives);
            update_post_meta($attachmentId, self::SIZE, $original['size']);
            update_post_meta($attachmentId, self::CHECKSUM, $original['checksum']);
            update_post_meta($attachmentId, self::ETAG, $original['etag']);
            update_post_meta($attachmentId, '_wp_attached_file', $original['key']);
            delete_post_meta($attachmentId, self::PENDING);

            if (isset($derivatives['poster'])) {
                update_post_meta($attachmentId, 'meydan_poster_url', $pipeline->storage()->url($derivatives['poster']));
                delete_post_meta($attachmentId, 'meydan_poster_path');
            }
            self::removeStaging($localPath, $metadata, $attachmentId);
            MediaLogger::log('s3_upload_completed', self::logContext($attachmentId, $sessionId, $original['key'], 'completed'));
            MediaLogger::log('attachment_created', self::logContext($attachmentId, $sessionId, $original['key'], 'completed'));
            return $metadata;
        } catch (Throwable $error) {
            $category = str_contains($error->getMessage(), 'HEAD verification') ? 'verification' : 'network';
            if ($category === 'verification') {
                MediaLogger::log('head_verification_failed', self::logContext($attachmentId, $sessionId, $logicalKey, 'failed', 'verification'));
            }
            MediaLogger::log('rollback_started', self::logContext($attachmentId, $sessionId, $logicalKey, 'started', $category));
            $rollbackOk = true;
            foreach (array_reverse($uploaded) as $key) {
                try { $pipeline->storage()->delete($key); } catch (Throwable) { $rollbackOk = false; }
            }
            MediaLogger::log('rollback_completed', self::logContext($attachmentId, $sessionId, $logicalKey, $rollbackOk ? 'completed' : 'failed', $category));
            self::failAttachment($attachmentId, 'انتقال فایل به فضای ذخیره‌سازی ناموفق بود.');
        }
    }

    public static function attachmentUrl(string|false $url, int $attachmentId): string|false
    {
        if ((string) get_post_meta($attachmentId, self::DRIVER, true) !== 's3') return $url;
        $key = (string) get_post_meta($attachmentId, self::KEY, true);
        if ($key === '') return $url;
        try { return StorageFactory::create()->url($key); } catch (Throwable) { return $url; }
    }

    public static function imageSrcset(array|false $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array|false
    {
        if (!is_array($sources) || (string) get_post_meta($attachmentId, self::DRIVER, true) !== 's3') return $sources;
        $derivatives = (array) get_post_meta($attachmentId, self::DERIVATIVES, true);
        if (!$derivatives) return $sources;
        try {
            $storage = StorageFactory::create();
            foreach ($sources as &$source) {
                $filename = basename((string) ($source['url'] ?? ''));
                foreach ($derivatives as $key) {
                    if (basename((string) $key) === $filename) {
                        $source['url'] = $storage->url((string) $key);
                        break;
                    }
                }
            }
            unset($source);
        } catch (Throwable) {
            return $sources;
        }
        return $sources;
    }

    public static function disablePendingSizes(array $sizes, array $imageMeta, int $attachmentId): array
    {
        if (self::s3Enabled() && (int) get_post_meta($attachmentId, self::PENDING, true)) {
            return [];
        }
        return $sizes;
    }

    public static function deleteObjectsBeforeAttachment(mixed $delete, \WP_Post $post, bool $forceDelete): mixed
    {
        $attachmentId = (int) $post->ID;
        if ((string) get_post_meta($attachmentId, self::DRIVER, true) !== 's3') return $delete;
        $key = (string) get_post_meta($attachmentId, self::KEY, true);
        $derivatives = array_values(array_filter(array_map('strval', (array) get_post_meta($attachmentId, self::DERIVATIVES, true))));
        try {
            $pipeline = self::pipeline();
            MediaLogger::log('s3_delete_started', self::logContext($attachmentId, '', $key, 'started'));
            $pipeline->deleteObjects($derivatives, $key);
            MediaLogger::log('s3_delete_completed', self::logContext($attachmentId, '', $key, 'completed'));
            return $delete;
        } catch (Throwable) {
            MediaLogger::log('s3_delete_failed', self::logContext($attachmentId, '', $key, 'failed', 'network'));
            return false;
        }
    }

    public static function afterDelete(int $attachmentId, \WP_Post $post): void
    {
        // Reserved for non-blocking lifecycle cleanup after a successful deletion.
    }

    public static function pipeline(): MediaPipeline
    {
        $mimes = self::allowedMimes();
        return new MediaPipeline(StorageFactory::create(), self::maxFileSize(), $mimes, static function (string $path): string {
            if (function_exists('wp_check_filetype_and_ext')) {
                $check = wp_check_filetype_and_ext($path, basename($path));
                if (!empty($check['type'])) return (string) $check['type'];
            }
            return (string) (mime_content_type($path) ?: 'application/octet-stream');
        });
    }

    /** @return list<string> */
    public static function allowedMimes(): array
    {
        $configured = trim((string) getenv('MEDIA_ALLOWED_MIME_TYPES'));
        if ($configured !== '') return array_values(array_unique(array_filter(array_map('trim', explode(',', $configured)))));
        return array_values(array_unique(array_map('strval', function_exists('get_allowed_mime_types') ? get_allowed_mime_types() : [])));
    }

    public static function maxFileSize(): int
    {
        $configured = (int) getenv('MEDIA_MAX_FILE_SIZE');
        return $configured > 0 ? $configured : 104857600;
    }

    private static function s3Enabled(): bool
    {
        return strtolower(trim((string) getenv('MEDIA_STORAGE'))) === 's3';
    }

    private static function basePath(int $attachmentId): string
    {
        $scope = sanitize_key((string) get_post_meta($attachmentId, 'meydan_storage_scope', true));
        $owner = sanitize_file_name((string) get_post_meta($attachmentId, 'meydan_storage_owner', true));
        if (in_array($scope, ['eitaa', 'bale'], true) && $owner !== '') return $scope . '/' . $owner;
        if (in_array($scope, ['users', 'squares', 'posts'], true) && $owner !== '') return $scope . '/' . $owner . '/' . gmdate('Y/m');
        $parent = (int) wp_get_post_parent_id($attachmentId);
        if ($parent > 0) return 'posts/' . $parent . '/' . gmdate('Y/m');
        $author = (int) get_post_field('post_author', $attachmentId);
        $squareId = $author > 0 ? (int) get_user_meta($author, 'meydan_square_id', true) : 0;
        if ($squareId > 0) return 'squares/' . $squareId . '/' . gmdate('Y/m');
        if ($author > 0) return 'users/' . $author . '/' . gmdate('Y/m');
        return 'uploads/' . gmdate('Y/m');
    }

    private static function uniqueLogicalKey(StorageInterface $storage, string $directory, string $filename): string
    {
        $filename = function_exists('sanitize_file_name') ? sanitize_file_name($filename) : basename($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = $extension !== '' ? substr($filename, 0, -strlen($extension) - 1) : $filename;
        for ($index = 0; $index < 10000; $index++) {
            $suffix = $index === 0 ? '' : '-' . $index;
            $candidate = trim($directory, '/') . '/' . $stem . $suffix . ($extension !== '' ? '.' . $extension : '');
            if (!$storage->exists($candidate)) return $candidate;
        }
        throw new RuntimeException('Unable to allocate a unique storage key.');
    }

    private static function posterLogicalKey(string $originalKey): string
    {
        $directory = trim(dirname($originalKey), '/.');
        $stem = pathinfo($originalKey, PATHINFO_FILENAME);
        $suffix = substr(hash('sha256', $originalKey), 0, 12);
        return ($directory !== '' ? $directory . '/' : '') . $stem . '-poster-' . $suffix . '.jpg';
    }

    /** @return array<string,string> */
    private static function derivativeFiles(string $original, array $metadata, int $attachmentId): array
    {
        $files = [];
        foreach ((array) ($metadata['sizes'] ?? []) as $name => $size) {
            if (!empty($size['file'])) $files[(string) $name] = dirname($original) . '/' . basename((string) $size['file']);
        }
        if (!empty($metadata['original_image'])) {
            $files['original_image'] = dirname($original) . '/' . basename((string) $metadata['original_image']);
        }
        $poster = (string) get_post_meta($attachmentId, 'meydan_poster_path', true);
        if ($poster !== '' && is_file($poster)) $files['poster'] = $poster;
        return $files;
    }

    private static function removeStaging(string $original, array $metadata, int $attachmentId): void
    {
        foreach (self::derivativeFiles($original, $metadata, $attachmentId) as $path) if (is_file($path)) @unlink($path);
        if (is_file($original)) @unlink($original);
    }

    private static function mimeFor(string $path): string
    {
        if (function_exists('wp_check_filetype')) {
            $check = wp_check_filetype(basename($path));
            if (!empty($check['type'])) return (string) $check['type'];
        }
        return (string) (mime_content_type($path) ?: 'application/octet-stream');
    }

    private static function failAttachment(int $attachmentId, string $message): never
    {
        delete_post_meta($attachmentId, self::PENDING);
        wp_delete_attachment($attachmentId, true);
        throw new RuntimeException($message);
    }

    /** @return array<string,mixed> */
    private static function logContext(int $attachmentId, string $sessionId, string $key, string $status, string $category = ''): array
    {
        return ['attachment_id' => $attachmentId, 'upload_session_id' => $sessionId, 'storage_key' => $key,
            'driver' => 's3', 'status' => $status, 'error_category' => $category];
    }
}
