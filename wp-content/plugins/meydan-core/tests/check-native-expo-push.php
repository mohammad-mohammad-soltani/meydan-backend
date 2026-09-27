<?php

declare(strict_types=1);

namespace {
    class WP_Error {}
    function sanitize_text_field(string $value): string { return trim($value); }
    function sanitize_textarea_field(string $value): string { return trim($value); }
    function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? ''; }
    function esc_url_raw(string $value): string { return $value; }
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
}

namespace {
    require_once __DIR__ . '/../src/Notifications/NativeExpoPush.php';

    use Meydan\Core\Notifications\NativeExpoPush;

    if (!NativeExpoPush::isValidToken('ExponentPushToken[abc_123-xyz]')) {
        throw new RuntimeException('A valid Expo token must be accepted.');
    }
    if (NativeExpoPush::isValidToken('https://push.example/device')) {
        throw new RuntimeException('Only Expo tokens may enter the native transport.');
    }

    $payload = NativeExpoPush::payload(
        'ExponentPushToken[abc_123-xyz]',
        'عنوان',
        'متن',
        '/posts/42',
        ['notification_id' => '42', 'tag' => 'post-42'],
    );
    if (($payload['to'] ?? '') !== 'ExponentPushToken[abc_123-xyz]') {
        throw new RuntimeException('The Expo payload must target the saved native token.');
    }
    if (($payload['data']['url'] ?? '') !== '/posts/42') {
        throw new RuntimeException('The notification payload must preserve the in-app route.');
    }
    if (($payload['channelId'] ?? '') !== 'default') {
        throw new RuntimeException('Android notifications must use the configured channel.');
    }
    if (($payload['tag'] ?? '') !== 'post-42') {
        throw new RuntimeException('Grouped notifications must replace their earlier Android tray entry.');
    }

    if (NativeExpoPush::receiptAction(['status' => 'ok']) !== 'success') {
        throw new RuntimeException('A successful Expo receipt must complete the delivery.');
    }
    if (NativeExpoPush::receiptAction(['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]) !== 'delete') {
        throw new RuntimeException('An unregistered device must be removed after its receipt arrives.');
    }
    if (NativeExpoPush::receiptAction(['status' => 'error', 'details' => ['error' => 'InvalidCredentials']]) !== 'retry') {
        throw new RuntimeException('Transient provider receipt failures must remain observable for retry.');
    }

    echo "Native Expo Push contract OK.\n";
}
