<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

final class Crypto
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, wp_salt('auth'));
    }

    public static function randomToken(int $bytes = 32, string $prefix = ''): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function encrypt(string $plaintext): string
    {
        $key = hash('sha256', wp_salt('secure_auth'), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid encrypted value.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', wp_salt('secure_auth'), true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }
        return $plain;
    }
}
