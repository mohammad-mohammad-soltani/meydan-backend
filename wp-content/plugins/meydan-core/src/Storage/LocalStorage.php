<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

final class LocalStorage implements StorageInterface
{
    public function put(string $localPath, string $key, string $contentType = ''): string
    {
        return $this->url($key);
    }

    public function url(string $key): string
    {
        return trailingslashit(wp_upload_dir()['baseurl']) . ltrim($key, '/');
    }
}
