<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use RuntimeException;
use Throwable;

final class S3Storage implements StorageInterface
{
    private const MULTIPART_THRESHOLD = 67108864;
    private const MULTIPART_PART_SIZE = 16777216;

    private readonly object $client;
    private readonly string $bucket;
    private readonly string $publicUrl;
    private readonly string $prefix;
    private readonly bool $checksumEnabled;
    private readonly int $checksumMaxBytes;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, ?object $client = null, private readonly mixed $multipartFactory = null)
    {
        foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'public_url', 'prefix'] as $required) {
            if (trim((string) ($config[$required] ?? '')) === '') {
                throw new RuntimeException($required . ' is required for S3 media storage.');
            }
        }
        $this->bucket = (string) $config['bucket'];
        $this->publicUrl = rtrim((string) $config['public_url'], '/');
        $this->prefix = trim((string) $config['prefix'], '/');
        $this->checksumEnabled = (bool) ($config['checksum_enabled'] ?? true);
        $this->checksumMaxBytes = max(0, (int) ($config['checksum_max_bytes'] ?? 268435456));
        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => (string) $config['region'],
            'endpoint' => (string) $config['endpoint'],
            'use_path_style_endpoint' => false,
            'signature_version' => 'v4',
            'credentials' => ['key' => (string) $config['access_key'], 'secret' => (string) $config['secret_key']],
        ]);
    }

    public function put(string $localPath, string $key, string $mimeType): array
    {
        if (!is_file($localPath)) throw new RuntimeException('Storage source file does not exist.');
        $size = (int) filesize($localPath);
        $key = $this->key($key);
        $checksum = $this->checksumEnabled && ($this->checksumMaxBytes <= 0 || $size <= $this->checksumMaxBytes)
            ? (hash_file('sha256', $localPath) ?: null)
            : null;
        $metadata = ['meydan-size' => (string) $size];
        if ($checksum !== null) $metadata['meydan-sha256'] = $checksum;

        try {
            if ($size < self::MULTIPART_THRESHOLD) {
                $result = $this->client->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'SourceFile' => $localPath,
                    'ContentType' => $mimeType,
                    'Metadata' => $metadata,
                ]);
            } else {
                $result = $this->multipart($localPath, $key, $mimeType, $metadata);
            }
        } catch (Throwable $error) {
            try { $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]); } catch (Throwable) {}
            throw $error;
        }

        return [
            'key' => $key,
            'size' => $size,
            'checksum' => $checksum,
            'etag' => $this->etag($this->value($result, 'ETag')),
        ];
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->key($key)]);
        } catch (Throwable $error) {
            $code = method_exists($error, 'getAwsErrorCode') ? (string) $error->getAwsErrorCode() : '';
            if (!in_array($code, ['NoSuchKey', 'NotFound', '404'], true)) throw $error;
        }
    }

    public function exists(string $key): bool
    {
        return (bool) $this->client->doesObjectExistV2($this->bucket, $this->key($key));
    }

    public function url(string $key): string
    {
        $segments = array_map('rawurlencode', explode('/', $this->key($key)));
        return $this->publicUrl . '/' . implode('/', $segments);
    }

    public function head(string $key): array
    {
        $key = $this->key($key);
        $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        $metadata = (array) ($this->value($result, 'Metadata') ?? []);
        return [
            'key' => $key,
            'size' => (int) $this->value($result, 'ContentLength'),
            'metadata_size' => isset($metadata['meydan-size']) ? (int) $metadata['meydan-size'] : 0,
            'content_type' => (string) $this->value($result, 'ContentType'),
            'checksum' => isset($metadata['meydan-sha256']) ? (string) $metadata['meydan-sha256'] : null,
            'etag' => $this->etag($this->value($result, 'ETag')),
        ];
    }

    /** @param array<string,string> $metadata */
    private function multipart(string $path, string $key, string $mimeType, array $metadata): mixed
    {
        try {
            if (is_callable($this->multipartFactory)) {
                return ($this->multipartFactory)($this->client, $path, [
                    'bucket' => $this->bucket, 'key' => $key, 'content_type' => $mimeType,
                    'metadata' => $metadata, 'part_size' => self::MULTIPART_PART_SIZE,
                ]);
            }
            $uploader = new MultipartUploader($this->client, $path, [
                'bucket' => $this->bucket,
                'key' => $key,
                'part_size' => self::MULTIPART_PART_SIZE,
                'before_initiate' => static function ($command) use ($mimeType, $metadata): void {
                    $command['ContentType'] = $mimeType;
                    $command['Metadata'] = $metadata;
                },
            ]);
            return $uploader->upload();
        } catch (Throwable $error) {
            $uploadId = $this->uploadId($error);
            if ($uploadId !== '') {
                try {
                    $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
                } catch (Throwable) {
                    // Preserve the upload failure; lifecycle logging records cleanup failures upstream.
                }
            }
            throw $error;
        }
    }

    private function uploadId(Throwable $error): string
    {
        if (method_exists($error, 'getUploadId')) return (string) $error->getUploadId();
        if (method_exists($error, 'getState')) {
            $state = $error->getState();
            if (is_object($state) && method_exists($state, 'getUploadId')) return (string) $state->getUploadId();
            if (is_object($state) && method_exists($state, 'getId')) {
                $id = (array) $state->getId();
                return (string) ($id['UploadId'] ?? $id['upload_id'] ?? '');
            }
        }
        return '';
    }

    private function key(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $segments = explode('/', $key);
        if ($key === '' || in_array('.', $segments, true) || in_array('..', $segments, true) || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new RuntimeException('Invalid storage key.');
        }
        if ($key !== $this->prefix && !str_starts_with($key, $this->prefix . '/')) $key = $this->prefix . '/' . $key;
        return $key;
    }

    private function value(mixed $result, string $key): mixed
    {
        if (is_array($result)) return $result[$key] ?? null;
        if ($result instanceof \ArrayAccess) return $result[$key] ?? null;
        return null;
    }

    private function etag(mixed $etag): ?string
    {
        $etag = trim((string) $etag, "\" ");
        return $etag !== '' ? $etag : null;
    }
}
