<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

final class MediaLogger
{
    private const FIELDS = ['attachment_id', 'upload_session_id', 'storage_key', 'driver', 'status', 'error_category'];

    public static function log(string $event, array $context = []): void
    {
        try {
            $payload = ['event' => $event, 'timestamp' => gmdate('c')];
            foreach (self::FIELDS as $field) {
                if (isset($context[$field]) && (is_scalar($context[$field]) || $context[$field] === null)) {
                    $payload[$field] = $context[$field];
                }
            }
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) error_log('[meydan-media] ' . $encoded);
        } catch (\Throwable) {
            // Observability must never alter upload or deletion behavior.
        }
    }
}
