<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

interface StorageInterface
{
    /** @return array{key:string,size:int,checksum:?string,etag:?string} */
    public function put(string $localPath, string $key, string $mimeType): array;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    public function url(string $key): string;

    /** @return array{key:string,size:int,metadata_size:int,content_type:string,checksum:?string,etag:?string} */
    public function head(string $key): array;
}
