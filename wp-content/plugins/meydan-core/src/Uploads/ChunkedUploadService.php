<?php

declare(strict_types=1);

namespace Meydan\Core\Uploads;

use Meydan\Core\Support\Crypto;
use WP_Error;
use WP_REST_Request;

final class ChunkedUploadService
{
    public const CHUNK_SIZE = 5242880;

    private const BAD = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bash',
        'bat', 'cmd', 'com', 'msi', 'dll', 'so', 'cgi', 'pl', 'py', 'rb',
    ];

    public function start(array $params, int $userId): array|WP_Error
    {
        $name = sanitize_file_name((string) ($params['filename'] ?? ''));
        $mime = sanitize_mime_type((string) ($params['mime_type'] ?? ''));
        $size = (int) ($params['size'] ?? 0);
        $purpose = sanitize_key((string) ($params['purpose'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $settings = (array) get_option('meydan_api_settings', []);
        $max = (int) ($settings['max_upload_size'] ?? 0);
        if ($max <= 0) {
            $max = 100 * 1024 * 1024;
        }

        if (
            $name === ''
            || $size <= 0
            || $size > $max
            || in_array($extension, self::BAD, true)
            || !in_array($purpose, ['narrative', 'content', 'avatar', 'cover', 'creator', 'square', 'chat'], true)
        ) {
            return new WP_Error('validation_failed', 'مشخصات فایل معتبر نیست.', ['status' => 422]);
        }

        $allowed = apply_filters('meydan_allowed_upload_mimes', get_allowed_mime_types());
        $check = wp_check_filetype($name, $allowed);
        $canonicalMime = (string) ($check['type'] ?? '');
        $declaredMime = strtolower(trim($mime));
        $canonicalMajor = $canonicalMime !== '' ? strstr($canonicalMime, '/', true) : false;
        $declaredMajor = $declaredMime !== '' ? strstr($declaredMime, '/', true) : false;
        $mimeMatches = $declaredMime === ''
            || $declaredMime === $canonicalMime
            || $declaredMime === 'application/octet-stream'
            || ($canonicalMajor && $declaredMajor && $canonicalMajor === $declaredMajor && in_array($canonicalMajor, ['video', 'audio'], true))
            || ($canonicalMajor === 'video' && $declaredMime === 'application/mp4');

        if (
            empty($check['type'])
            || !$mimeMatches
            || (in_array($purpose, ['avatar', 'cover'], true) && !str_starts_with((string) $check['type'], 'image/'))
        ) {
            return new WP_Error(
                'validation_failed',
                'نوع فایل با پسوند آن سازگار نیست یا برای این کاربرد مجاز نیست.',
                ['status' => 422, 'fields' => ['mime_type' => 'invalid']],
            );
        }

        $uploadId = Crypto::randomToken(18, 'upl_');
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'meydan_uploads', [
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'filename' => $name,
            'mime_type' => $check['type'],
            'size' => $size,
            'purpose' => $purpose,
            'chunk_size' => self::CHUNK_SIZE,
            'status' => 'started',
            'created_at' => current_time('mysql', true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);

        if (!$wpdb->insert_id) {
            return new WP_Error('internal_error', 'شروع آپلود ناموفق بود.', ['status' => 500]);
        }

        $dir = $this->dir($uploadId);
        wp_mkdir_p($dir);
        $this->denyExecution($dir);

        return [
            'upload_id' => $uploadId,
            'mode' => 'chunked',
            'chunk_size' => self::CHUNK_SIZE,
        ];
    }

    public function chunk(string $uploadId, int $index, WP_REST_Request $request, int $userId): array|WP_Error
    {
        $row = $this->row($uploadId, $userId);
        if (is_wp_error($row)) {
            return $row;
        }
        if ($index < 0 || $index > 10000) {
            return new WP_Error('validation_failed', 'شماره قطعه معتبر نیست.', ['status' => 422]);
        }

        $body = $request->get_body();
        if ($body === '' || strlen($body) > self::CHUNK_SIZE) {
            return new WP_Error('validation_failed', 'اندازه قطعه معتبر نیست.', ['status' => 422]);
        }

        $file = $this->dir($uploadId) . '/' . sprintf('%06d', $index) . '.part';
        if (file_put_contents($file, $body, LOCK_EX) === false) {
            return new WP_Error('internal_error', 'ذخیره قطعه ناموفق بود.', ['status' => 500]);
        }

        return ['upload_id' => $uploadId, 'index' => $index, 'received' => strlen($body)];
    }

    public function complete(string $uploadId, int $userId): array|WP_Error
    {
        $row = $this->row($uploadId, $userId);
        if (is_wp_error($row)) {
            return $row;
        }

        $parts = glob($this->dir($uploadId) . '/*.part') ?: [];
        sort($parts, SORT_STRING);
        if (!$parts) {
            return new WP_Error('upload_incomplete', 'هیچ قطعه‌ای دریافت نشده است.', ['status' => 409]);
        }

        $assembled = $this->dir($uploadId) . '/assembled.upload';
        $output = fopen($assembled, 'wb');
        if ($output === false) {
            return new WP_Error('internal_error', 'ساخت فایل موقت آپلود ناموفق بود.', ['status' => 500]);
        }

        $total = 0;
        foreach ($parts as $part) {
            $input = fopen($part, 'rb');
            if ($input === false) {
                fclose($output);
                @unlink($assembled);
                return new WP_Error('internal_error', 'خواندن قطعه آپلود ناموفق بود.', ['status' => 500]);
            }

            while (!feof($input)) {
                $buffer = fread($input, 1024 * 1024);
                if ($buffer === false) {
                    fclose($input);
                    fclose($output);
                    @unlink($assembled);
                    return new WP_Error('internal_error', 'خواندن قطعه آپلود ناموفق بود.', ['status' => 500]);
                }
                $total += strlen($buffer);
                if ($buffer !== '' && fwrite($output, $buffer) === false) {
                    fclose($input);
                    fclose($output);
                    @unlink($assembled);
                    return new WP_Error('internal_error', 'ساخت فایل نهایی آپلود ناموفق بود.', ['status' => 500]);
                }
            }
            fclose($input);
        }
        fclose($output);

        if ($total !== (int) $row->size) {
            @unlink($assembled);
            return new WP_Error('upload_incomplete', 'اندازه فایل نهایی با مقدار اعلام‌شده برابر نیست.', ['status' => 409]);
        }

        $check = wp_check_filetype_and_ext($assembled, (string) $row->filename);
        if (empty($check['type']) || $check['type'] !== (string) $row->mime_type) {
            @unlink($assembled);
            return new WP_Error('validation_failed', 'MIME واقعی فایل با نوع اعلام‌شده سازگار نیست.', ['status' => 422]);
        }

        // Do not feed the completed upload through wp_upload_bits(): it reads the
        // whole payload into PHP memory. Large chat videos must stay streaming
        // from first chunk to final placement on disk.
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            @unlink($assembled);
            return new WP_Error('internal_error', 'مسیر آپلود وردپرس در دسترس نیست.', ['status' => 500]);
        }

        if (!wp_mkdir_p((string) $uploads['path'])) {
            @unlink($assembled);
            return new WP_Error('internal_error', 'ساخت مسیر نهایی آپلود ناموفق بود.', ['status' => 500]);
        }

        $filename = wp_unique_filename((string) $uploads['path'], (string) $row->filename);
        $destination = trailingslashit((string) $uploads['path']) . $filename;
        $moved = @rename($assembled, $destination);
        if (!$moved) {
            $moved = @copy($assembled, $destination);
            if ($moved) {
                @unlink($assembled);
            }
        }
        if (!$moved) {
            @unlink($assembled);
            return new WP_Error('internal_error', 'انتقال فایل نهایی ناموفق بود.', ['status' => 500]);
        }

        $attachmentId = wp_insert_attachment([
            'post_author' => $userId,
            'post_mime_type' => $check['type'],
            'post_title' => sanitize_text_field(pathinfo((string) $row->filename, PATHINFO_FILENAME)),
            'post_status' => 'inherit',
        ], $destination, 0, true);

        if (is_wp_error($attachmentId)) {
            @unlink($destination);
            return $attachmentId;
        }

        update_attached_file((int) $attachmentId, $destination);
        add_post_meta((int) $attachmentId, 'meydan_upload_owner_user_id', $userId, true);
        add_post_meta((int) $attachmentId, 'meydan_upload_purpose', (string) $row->purpose, true);

        // Video finalisation can take longer than the network upload. The client
        // shows this as a separate "processing" phase after upload reaches 100%.
        $video = [];
        if (str_starts_with((string) $check['type'], 'video/')) {
            try {
                $video = VideoProcessor::processAttachment((int) $attachmentId);
            } catch (\Throwable $error) {
                error_log('Meydan video processing failed for upload ' . $uploadId . ': ' . $error->getMessage());
            }
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = [];
        try {
            $metadata = (array) wp_generate_attachment_metadata((int) $attachmentId, $destination);
            if ($metadata) {
                wp_update_attachment_metadata((int) $attachmentId, $metadata);
            }
        } catch (\Throwable $error) {
            error_log('Meydan upload metadata generation failed for attachment ' . (int) $attachmentId . ': ' . $error->getMessage());
        }

        $attachmentUrl = wp_get_attachment_url((int) $attachmentId);
        if (!$attachmentUrl) {
            $attachmentUrl = trailingslashit((string) $uploads['url']) . rawurlencode($filename);
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'meydan_uploads',
            ['status' => 'completed'],
            ['id' => (int) $row->id],
        );
        $this->cleanup($uploadId);

        $payload = [
            'media_id' => (int) $attachmentId,
            'type' => $this->kind((string) $check['type']),
            'url' => (string) $attachmentUrl,
            'size' => $total,
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : null,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : null,
        ];

        if ($video) {
            $payload += $video;
        }

        return $payload;
    }

    /**
     * Removes every upload session owned by a deleted account, including
     * unfinished chunk directories on disk.
     */
    public function purgeForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'meydan_uploads';
        $ids = array_values(array_filter(array_map(
            'strval',
            $wpdb->get_col($wpdb->prepare(
                "SELECT upload_id FROM {$table} WHERE user_id=%d",
                $userId,
            )) ?: [],
        )));

        foreach ($ids as $uploadId) {
            $this->cleanup($uploadId);
        }

        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
        return count($ids);
    }

    public function abort(string $uploadId, int $userId): array|WP_Error
    {
        $row = $this->row($uploadId, $userId);
        if (is_wp_error($row)) {
            return $row;
        }
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'meydan_uploads',
            ['status' => 'aborted'],
            ['id' => (int) $row->id],
        );
        $this->cleanup($uploadId);
        return ['aborted' => true];
    }

    private function row(string $id, int $userId): mixed
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}meydan_uploads WHERE upload_id=%s AND user_id=%d AND status='started' AND expires_at>=UTC_TIMESTAMP()",
            $id,
            $userId,
        ));
        return $row ?: new WP_Error('not_found', 'آپلود فعال پیدا نشد.', ['status' => 404]);
    }

    private function dir(string $id): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit((string) $uploads['basedir']) . 'meydan-chunks/' . sanitize_file_name($id);
    }

    private function denyExecution(string $dir): void
    {
        @file_put_contents(
            $dir . '/.htaccess',
            "Options -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n",
        );
        @file_put_contents($dir . '/index.html', '');
    }

    private function cleanup(string $id): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir)) {
            return;
        }

        // A failed/retried upload may leave nested temporary entries. Remove
        // them recursively, then remove the directory only when it is empty;
        // this avoids noisy PHP warnings when cleanup races another request.
        $entries = array_merge(glob($dir . '/*') ?: [], glob($dir . '/.?*') ?: []);
        foreach ($entries as $entry) {
            $base = basename($entry);
            if ($base === '.' || $base === '..') {
                continue;
            }
            if (is_dir($entry)) {
                $this->removeDirectory($entry);
            } else {
                @unlink($entry);
            }
        }
        if (is_dir($dir) && !glob($dir . '/*') && !glob($dir . '/.?*')) {
            @rmdir($dir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (array_merge(glob($dir . '/*') ?: [], glob($dir . '/.?*') ?: []) as $entry) {
            $base = basename($entry);
            if ($base === '.' || $base === '..') {
                continue;
            }
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }
        if (is_dir($dir) && !glob($dir . '/*') && !glob($dir . '/.?*')) {
            @rmdir($dir);
        }
    }

    private function kind(string $mime): string
    {
        return str_starts_with($mime, 'image/')
            ? 'image'
            : (str_starts_with($mime, 'video/')
                ? 'video'
                : (str_starts_with($mime, 'audio/')
                    ? 'audio'
                    : (str_contains($mime, 'pdf') || str_contains($mime, 'word') || str_contains($mime, 'presentation')
                        ? 'document'
                        : 'file')));
    }
}
