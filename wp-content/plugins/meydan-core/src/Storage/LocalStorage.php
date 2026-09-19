<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

use RuntimeException;

final class LocalStorage implements StorageInterface
{
    public function __construct(
        private readonly string $root,
        private readonly string $publicUrl,
        private readonly string $prefix = '',
        private readonly bool $checksumEnabled = true,
        private readonly int $checksumMaxBytes = 268435456,
    ) {
    }

    public function put(string $localPath, string $key, string $mimeType): array
    {
        if (!is_file($localPath)) {
            throw new RuntimeException('Storage source file does not exist.');
        }
        $key = $this->key($key);
        $destination = $this->path($key);
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Storage destination cannot be created.');
        }
        $input = fopen($localPath, 'rb');
        $output = fopen($destination, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Storage stream cannot be opened.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Storage stream copy failed.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $size = (int) filesize($destination);
        $checksum = $this->checksum($localPath, $size);
        return ['key' => $key, 'size' => $size, 'checksum' => $checksum, 'etag' => null];
    }

    public function delete(string $key): void
    {
        $path = $this->path($this->key($key));
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Storage object could not be deleted.');
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($this->key($key)));
    }

    public function url(string $key): string
    {
        $segments = array_map('rawurlencode', explode('/', $this->key($key)));
        return rtrim($this->publicUrl, '/') . '/' . implode('/', $segments);
    }

    public function head(string $key): array
    {
        $key = $this->key($key);
        $path = $this->path($key);
        if (!is_file($path)) {
            throw new RuntimeException('Storage object does not exist.');
        }
        $size = (int) filesize($path);
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
        return [
            'key' => $key,
            'size' => $size,
            'metadata_size' => $size,
            'content_type' => $mime,
            'checksum' => $this->checksum($path, $size),
            'etag' => null,
        ];
    }

    private function key(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $segments = explode('/', $key);
        if ($key === '' || in_array('.', $segments, true) || in_array('..', $segments, true) || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new RuntimeException('Invalid storage key.');
        }
        $prefix = trim($this->prefix, '/');
        if ($prefix !== '' && $key !== $prefix && !str_starts_with($key, $prefix . '/')) {
            $key = $prefix . '/' . $key;
        }
        return $key;
    }

    private function path(string $key): string
    {
        $root = rtrim($this->root, '/');
        $path = $root . '/' . ltrim($key, '/');
        if (!str_starts_with($path, $root . '/')) {
            throw new RuntimeException('Invalid storage key.');
        }
        return $path;
    }

    private function checksum(string $path, int $size): ?string
    {
        return $this->checksumEnabled && ($this->checksumMaxBytes <= 0 || $size <= $this->checksumMaxBytes)
            ? hash_file('sha256', $path) ?: null
            : null;
    }
}
