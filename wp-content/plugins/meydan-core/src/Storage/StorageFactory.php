<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

final class StorageFactory
{
    public static function make(): StorageInterface
    {
        $driver = strtolower((string) getenv('MEDIA_STORAGE'));

        if ($driver === 's3') {
            return new S3Storage();
        }

        return new LocalStorage();
    }
}
