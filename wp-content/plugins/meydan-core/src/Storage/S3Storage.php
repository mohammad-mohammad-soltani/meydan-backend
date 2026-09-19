<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

final class S3Storage implements StorageInterface
{
    public function put(string $localPath, string $key, string $contentType = ''): string
    {
        // S3 upload implementation is intentionally isolated here.
        // Credentials and endpoint configuration will be injected from settings.
        if (!is_file($localPath)) {
            throw new \RuntimeException('Storage source file does not exist.');
        }

        throw new \RuntimeException('S3 adapter is not configured yet.');
    }

    public function url(string $key): string
    {
        return trailingslashit((string) getenv('MEDIA_PUBLIC_URL')) . ltrim($key, '/');
    }
}
