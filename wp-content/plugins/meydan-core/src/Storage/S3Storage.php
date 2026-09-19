<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use Aws\S3\S3Client;

final class S3Storage implements StorageInterface
{
    private S3Client $client;

    public function __construct()
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => (string) getenv('S3_REGION'),
            'endpoint' => (string) getenv('S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => (string) getenv('S3_ACCESS_KEY'),
                'secret' => (string) getenv('S3_SECRET_KEY'),
            ],
        ]);
    }

    public function put(string $localPath, string $key, string $contentType = ''): string
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('Storage source file does not exist.');
        }

        $this->client->putObject([
            'Bucket' => (string) getenv('S3_BUCKET'),
            'Key' => ltrim($key, '/'),
            'SourceFile' => $localPath,
            'ContentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
        ]);

        return $this->url($key);
    }

    public function url(string $key): string
    {
        return trailingslashit((string) getenv('MEDIA_PUBLIC_URL')) . ltrim($key, '/');
    }
}
