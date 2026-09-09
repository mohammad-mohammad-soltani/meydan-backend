<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Cursor
{
    public static function encode(array $payload): string
    {
        $json = wp_json_encode($payload);
        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): array
    {
        if (!$cursor) {
            return [];
        }
        $padded = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json = base64_decode($padded, true);
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }
}
