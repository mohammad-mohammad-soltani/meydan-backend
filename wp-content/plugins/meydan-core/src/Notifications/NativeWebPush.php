<?php

declare(strict_types=1);

namespace Meydan\Core\Notifications;

use WP_Error;

/**
 * Standards-based Web Push transport implemented directly in WordPress.
 *
 * No Firebase/OneSignal/Pushe account or SDK is required. Browsers still use
 * their own standards-mandated push endpoints (for example Chrome's push
 * endpoint, Mozilla Push, or APNs for Safari), while Meydan owns subscriptions,
 * VAPID identity, payload encryption and delivery.
 */
final class NativeWebPush
{
    private const VAPID_PRIVATE_OPTION = 'meydan_webpush_vapid_private';
    private const VAPID_PUBLIC_OPTION = 'meydan_webpush_vapid_public';
    private const MAX_PAYLOAD_BYTES = 2800;
    private const TTL = 86400;

    public static function isAvailable(): bool
    {
        return extension_loaded('openssl')
            && function_exists('openssl_pkey_new')
            && function_exists('openssl_pkey_derive')
            && function_exists('openssl_encrypt');
    }

    public static function isConfigured(): bool
    {
        return self::isAvailable() && self::ensureVapidKeys();
    }

    public static function publicKey(): string
    {
        if (!self::ensureVapidKeys()) {
            return '';
        }
        return trim((string) get_option(self::VAPID_PUBLIC_OPTION, ''));
    }

    /**
     * @param array{endpoint?:mixed,keys?:mixed} $subscription
     */
    public static function subscribe(int $userId, array $subscription, ?string $userAgent = null): true|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('webpush_unauthenticated', 'برای فعال‌سازی اعلان باید وارد شوید.', ['status' => 401]);
        }
        if (!self::isConfigured()) {
            return new WP_Error('webpush_unavailable', 'Web Push روی این سرور در دسترس نیست.', ['status' => 503]);
        }

        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = isset($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));

        if (!self::validEndpoint($endpoint) || !self::validKey($p256dh, 65) || !self::validKey($auth, 16)) {
            return new WP_Error('webpush_invalid_subscription', 'اشتراک Web Push معتبر نیست.', ['status' => 422]);
        }

        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $hash = hash('sha256', $endpoint);
        $ua = mb_substr(sanitize_text_field((string) $userAgent), 0, 255);

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id,endpoint_hash,endpoint,p256dh,auth,user_agent,created_at,updated_at,failure_count)\n"
            . "VALUES (%d,%s,%s,%s,%s,%s,%s,%s,0)\n"
            . "ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),user_agent=VALUES(user_agent),updated_at=VALUES(updated_at),failure_count=0",
            $userId,
            $hash,
            $endpoint,
            $p256dh,
            $auth,
            $ua,
            $now,
            $now,
        );

        if ($wpdb->query($sql) === false) {
            return new WP_Error('webpush_subscribe_failed', 'ذخیره اشتراک اعلان انجام نشد.', ['status' => 500]);
        }

        return true;
    }

    public static function unsubscribe(int $userId, string $endpoint): true|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('webpush_unauthenticated', 'برای غیرفعال‌سازی اعلان باید وارد شوید.', ['status' => 401]);
        }
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return new WP_Error('webpush_invalid_endpoint', 'آدرس اشتراک معتبر نیست.', ['status' => 422]);
        }

        global $wpdb;
        $wpdb->delete(self::table(), [
            'user_id' => $userId,
            'endpoint_hash' => hash('sha256', $endpoint),
        ], ['%d', '%s']);
        return true;
    }

    public static function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $deepLink = null,
        ?string $iconUrl = null,
        array $data = [],
    ): void {
        if ($userId <= 0) {
            return;
        }
        self::sendToUsers([$userId], $title, $body, $deepLink, $iconUrl, $data);
    }

    /** @param int[] $userIds */
    public static function sendToUsers(
        array $userIds,
        string $title,
        string $body,
        ?string $deepLink = null,
        ?string $iconUrl = null,
        array $data = [],
    ): void {
        if (!self::isConfigured()) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return;
        }

        $payload = self::payload($title, $body, $deepLink, $iconUrl, $data);
        if ($payload === '') {
            return;
        }

        global $wpdb;
        foreach (array_chunk($ids, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $query = $wpdb->prepare(
                "SELECT id,endpoint,p256dh,auth FROM " . self::table() . " WHERE user_id IN ({$placeholders}) ORDER BY id ASC",
                ...$chunk,
            );
            $rows = $wpdb->get_results($query, ARRAY_A) ?: [];
            foreach ($rows as $row) {
                self::deliver($row, $payload);
            }
        }
    }

    /** @param array<string,mixed> $data */
    private static function payload(string $title, string $body, ?string $deepLink, ?string $iconUrl, array $data): string
    {
        $safeData = [];
        foreach ($data as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || (!is_scalar($value) && $value !== null)) {
                continue;
            }
            $safeData[$key] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        $payload = [
            // Every browser push has the same app title and a two-part body,
            // mirroring a Telegram-style notification: event type, then text.
            'title' => 'نقش من',
            'type' => mb_substr(sanitize_text_field($title), 0, 160),
            'message' => mb_substr(sanitize_textarea_field($body), 0, 700),
            'url' => self::deepLink($deepLink),
            'icon' => self::icon($iconUrl),
            'tag' => isset($safeData['tag']) ? mb_substr((string) $safeData['tag'], 0, 100) : null,
            'data' => $safeData,
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }
        if (strlen($json) <= self::MAX_PAYLOAD_BYTES) {
            return $json;
        }

        // Keep metadata and navigation intact; only the human-readable message
        // is shortened if a very long notification would exceed a push record.
        $payload['message'] = mb_substr((string) $payload['message'], 0, 260) . '…';
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    /** @param array{id:mixed,endpoint:mixed,p256dh:mixed,auth:mixed} $row */
    private static function deliver(array $row, string $payload): void
    {
        $endpoint = trim((string) ($row['endpoint'] ?? ''));
        if (!self::validEndpoint($endpoint)) {
            self::deleteSubscription((int) ($row['id'] ?? 0));
            return;
        }

        $encrypted = self::encrypt(
            $payload,
            (string) ($row['p256dh'] ?? ''),
            (string) ($row['auth'] ?? ''),
        );
        if ($encrypted === null) {
            self::markFailure((int) ($row['id'] ?? 0));
            return;
        }

        $authorization = self::vapidAuthorization($endpoint);
        if ($authorization === null) {
            self::markFailure((int) ($row['id'] ?? 0));
            return;
        }

        $response = wp_safe_remote_post($endpoint, [
            'timeout' => 8,
            'redirection' => 0,
            'headers' => [
                'Authorization' => $authorization,
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) self::TTL,
                'Urgency' => 'high',
            ],
            'body' => $encrypted,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            self::markFailure((int) ($row['id'] ?? 0));
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [201, 202], true)) {
            self::markSuccess((int) ($row['id'] ?? 0));
            return;
        }
        if (in_array($status, [404, 410], true)) {
            self::deleteSubscription((int) ($row['id'] ?? 0));
            return;
        }
        self::markFailure((int) ($row['id'] ?? 0));
    }

    private static function encrypt(string $payload, string $p256dh, string $auth): ?string
    {
        $clientPublic = self::decode($p256dh);
        $authSecret = self::decode($auth);
        if (strlen($clientPublic) !== 65 || strlen($authSecret) !== 16 || $clientPublic[0] !== "\x04") {
            return null;
        }

        $serverPrivate = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($serverPrivate === false) {
            return null;
        }
        $details = openssl_pkey_get_details($serverPrivate);
        $serverPublic = self::rawPublicKey($details);
        if ($serverPublic === null) {
            return null;
        }

        $clientKey = openssl_pkey_get_public(self::publicKeyPem($clientPublic));
        if ($clientKey === false) {
            return null;
        }
        $sharedSecret = openssl_pkey_derive($clientKey, $serverPrivate, 32);
        if (!is_string($sharedSecret) || strlen($sharedSecret) === 0) {
            return null;
        }

        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00" . $clientPublic . $serverPublic;
        $ikm = self::hkdfExpand($prkKey, $keyInfo, 32);

        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = self::hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

        // RFC 8188: 0x02 marks the final record; no padding is needed here.
        $plaintext = $payload . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            return null;
        }

        $recordSize = 4096;
        return $salt . pack('N', $recordSize) . chr(strlen($serverPublic)) . $serverPublic . $ciphertext . $tag;
    }

    private static function vapidAuthorization(string $endpoint): ?string
    {
        if (!self::ensureVapidKeys()) {
            return null;
        }

        $parts = wp_parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $audience = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if ($port > 0 && !(($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))) {
            $audience .= ':' . $port;
        }

        $privatePem = (string) get_option(self::VAPID_PRIVATE_OPTION, '');
        $public = (string) get_option(self::VAPID_PUBLIC_OPTION, '');
        $privateKey = openssl_pkey_get_private($privatePem);
        if ($privateKey === false || $public === '') {
            return null;
        }

        $header = self::encode((string) wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::encode((string) wp_json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => self::vapidSubject(),
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;
        $signatureDer = '';
        if (!openssl_sign($unsigned, $signatureDer, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $signature = self::ecdsaDerToJose($signatureDer);
        if ($signature === null) {
            return null;
        }

        return 'vapid t=' . $unsigned . '.' . self::encode($signature) . ', k=' . $public;
    }

    private static function ensureVapidKeys(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        $private = trim((string) get_option(self::VAPID_PRIVATE_OPTION, ''));
        $public = trim((string) get_option(self::VAPID_PUBLIC_OPTION, ''));
        if ($private !== '' && $public !== '') {
            return true;
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            return false;
        }
        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem)) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        $rawPublic = self::rawPublicKey($details);
        if ($rawPublic === null) {
            return false;
        }

        update_option(self::VAPID_PRIVATE_OPTION, $privatePem, false);
        update_option(self::VAPID_PUBLIC_OPTION, self::encode($rawPublic), false);
        return true;
    }

    private static function rawPublicKey(array|false $details): ?string
    {
        if (!is_array($details) || empty($details['ec']) || !is_array($details['ec'])) {
            return null;
        }
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y)) {
            return null;
        }
        $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        return "\x04" . $x . $y;
    }

    private static function publicKeyPem(string $raw): string
    {
        // SubjectPublicKeyInfo for id-ecPublicKey + prime256v1 followed by the
        // uncompressed 65-byte P-256 point.
        $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
        $der = $prefix . $raw;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function hkdfExpand(string $prk, string $info, int $length): string
    {
        $output = '';
        $previous = '';
        $counter = 1;
        while (strlen($output) < $length) {
            $previous = hash_hmac('sha256', $previous . $info . chr($counter), $prk, true);
            $output .= $previous;
            $counter++;
        }
        return substr($output, 0, $length);
    }

    private static function ecdsaDerToJose(string $der): ?string
    {
        $offset = 0;
        if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x30) {
            return null;
        }
        if (self::derLength($der, $offset) === null || !isset($der[$offset]) || ord($der[$offset++]) !== 0x02) {
            return null;
        }
        $rLen = self::derLength($der, $offset);
        if ($rLen === null || $rLen < 1 || $offset + $rLen > strlen($der)) {
            return null;
        }
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x02) {
            return null;
        }
        $sLen = self::derLength($der, $offset);
        if ($sLen === null || $sLen < 1 || $offset + $sLen > strlen($der)) {
            return null;
        }
        $s = substr($der, $offset, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($r) !== 32 || strlen($s) !== 32) {
            return null;
        }
        return $r . $s;
    }

    private static function derLength(string $der, int &$offset): ?int
    {
        if (!isset($der[$offset])) {
            return null;
        }
        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) {
            return $length;
        }
        $bytes = $length & 0x7f;
        if ($bytes < 1 || $bytes > 4 || $offset + $bytes > strlen($der)) {
            return null;
        }
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    }

    private static function validEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 2048 || !str_starts_with($endpoint, 'https://')) {
            return false;
        }
        return wp_http_validate_url($endpoint) !== false;
    }

    private static function validKey(string $value, int $expectedBytes): bool
    {
        $decoded = self::decode($value);
        return strlen($decoded) === $expectedBytes;
    }

    private static function deepLink(?string $deepLink): string
    {
        $value = trim((string) $deepLink);
        if ($value === '' || (!str_starts_with($value, '/') && !str_starts_with($value, 'https://'))) {
            return '/';
        }
        return mb_substr($value, 0, 700);
    }

    private static function icon(?string $iconUrl): ?string
    {
        $value = trim((string) $iconUrl);
        return $value !== '' && str_starts_with($value, 'https://') ? esc_url_raw($value) : null;
    }

    private static function vapidSubject(): string
    {
        $email = sanitize_email((string) get_option('admin_email', ''));
        if ($email !== '') {
            return 'mailto:' . $email;
        }
        $home = home_url('/');
        return str_starts_with($home, 'https://') ? $home : 'https://localhost.invalid/';
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meydan_push_subscriptions';
    }

    private static function markSuccess(int $id): void
    {
        if ($id <= 0) return;
        global $wpdb;
        $wpdb->update(self::table(), [
            'last_success_at' => current_time('mysql', true),
            'failure_count' => 0,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id], ['%s', '%d', '%s'], ['%d']);
    }

    private static function markFailure(int $id): void
    {
        if ($id <= 0) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET failure_count=failure_count+1,updated_at=%s WHERE id=%d',
            current_time('mysql', true),
            $id,
        ));
        // Stale subscriptions that repeatedly fail are eventually removed even
        // when a vendor does not return the canonical 404/410 response.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE id=%d AND failure_count>=10',
            $id,
        ));
    }

    private static function deleteSubscription(int $id): void
    {
        if ($id <= 0) return;
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }
}
