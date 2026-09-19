<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

interface StorageInterface
{
    public function put(string $localPath, string $key, string $contentType = ''): string;

    public function url(string $key): string;
}
