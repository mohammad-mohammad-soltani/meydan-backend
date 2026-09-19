<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use RuntimeException;

final class StorageFactory
{
    /** @param array<string,string|null>|null $environment */
    public static function create(?array $environment = null, mixed $s3Client = null): StorageInterface
    {
        $get = static function (string $key, string $default = '') use ($environment): string {
            if ($environment !== null) return (string) ($environment[$key] ?? $default);
            $value = getenv($key);
            return $value === false ? $default : (string) $value;
        };
        $driver = strtolower(trim($get('MEDIA_STORAGE', 'local')));
        $prefix = trim($get('MEDIA_STORAGE_PREFIX'), '/');
        $publicUrl = rtrim($get('MEDIA_PUBLIC_URL'), '/');
        $checksumEnabled = !in_array(strtolower($get('MEDIA_STORAGE_SHA256_VERIFY', 'true')), ['0', 'false', 'no', 'off'], true);
        $checksumMax = max(0, (int) $get('MEDIA_STORAGE_SHA256_MAX_BYTES', '268435456'));

        if ($driver === 'local') {
            $root = $get('MEDIA_STORAGE_LOCAL_ROOT');
            if ($root === '' && function_exists('wp_get_upload_dir')) {
                $uploads = wp_get_upload_dir();
                $root = (string) ($uploads['basedir'] ?? '');
                $publicUrl = $publicUrl ?: (string) ($uploads['baseurl'] ?? '');
            }
            if ($root === '') throw new RuntimeException('Local storage root is not configured.');
            return new LocalStorage($root, $publicUrl, $prefix, $checksumEnabled, $checksumMax);
        }
        if ($driver !== 's3') throw new RuntimeException('Unsupported media storage driver.');
        foreach (['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'MEDIA_PUBLIC_URL', 'MEDIA_STORAGE_PREFIX'] as $required) {
            if (trim($get($required)) === '') throw new RuntimeException($required . ' is required for S3 media storage.');
        }
        if ($s3Client === null && !class_exists('Aws\\S3\\S3Client')) {
            throw new RuntimeException('AWS SDK is not installed. Run Composer install for meydan-core.');
        }
        return new S3Storage([
            'endpoint' => $get('S3_ENDPOINT'), 'region' => $get('S3_REGION'), 'bucket' => $get('S3_BUCKET'),
            'access_key' => $get('S3_ACCESS_KEY'), 'secret_key' => $get('S3_SECRET_KEY'),
            'public_url' => $publicUrl, 'prefix' => $prefix,
            'checksum_enabled' => $checksumEnabled, 'checksum_max_bytes' => $checksumMax,
        ], $s3Client);
    }
}
