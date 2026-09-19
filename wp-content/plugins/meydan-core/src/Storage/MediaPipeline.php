<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use WP_Error;

final class MediaPipeline
{
    /** @param list<string> $allowedMimeTypes */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $maxFileSize,
        private readonly array $allowedMimeTypes,
        private readonly mixed $mimeDetector = null,
    ) {
    }

    public function validateDeclared(string $filename, int $size, string $mimeType): ?WP_Error
    {
        if ($size <= 0 || ($this->maxFileSize > 0 && $size > $this->maxFileSize)) {
            return new WP_Error('file_too_large', 'اندازه فایل بیش از حد مجاز است.');
        }
        if (!$this->mimeAllowed($mimeType)) {
            return new WP_Error('mime_not_allowed', 'نوع فایل مجاز نیست.');
        }
        return null;
    }

    public function validateFile(string $path, string $expectedMime): ?WP_Error
    {
        if (!is_file($path)) return new WP_Error('staging_missing', 'فایل موقت پیدا نشد.');
        $size = (int) filesize($path);
        if ($this->maxFileSize > 0 && $size > $this->maxFileSize) return new WP_Error('file_too_large', 'اندازه فایل بیش از حد مجاز است.');
        $actual = $this->mimeDetector !== null ? (string) ($this->mimeDetector)($path) : (string) (mime_content_type($path) ?: '');
        if (!$this->mimeAllowed($actual) || ($expectedMime !== '' && $actual !== $expectedMime)) {
            return new WP_Error('mime_not_allowed', 'نوع واقعی فایل مجاز نیست.');
        }
        return null;
    }

    /** @return array{key:string,size:int,checksum:?string,etag:?string} */
    public function upload(string $path, string $key, string $mimeType): array
    {
        $result = $this->storage->put($path, $key, $mimeType);
        $head = $this->storage->head($result['key']);
        if ($head['size'] !== $result['size'] || $head['metadata_size'] !== $result['size']
            || ($head['content_type'] !== '' && $head['content_type'] !== $mimeType)
            || ($result['checksum'] !== null && $head['checksum'] !== $result['checksum'])) {
            try { $this->storage->delete($result['key']); } catch (\Throwable) {}
            throw new \RuntimeException('Storage HEAD verification failed.');
        }
        return $result;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    /** @param list<string> $derivativeKeys */
    public function deleteObjects(array $derivativeKeys, string $originalKey): void
    {
        foreach ($derivativeKeys as $key) $this->storage->delete($key);
        if ($originalKey !== '') $this->storage->delete($originalKey);
    }

    private function mimeAllowed(string $mime): bool
    {
        return $mime !== '' && in_array(strtolower($mime), array_map('strtolower', $this->allowedMimeTypes), true);
    }
}
