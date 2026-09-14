<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use WP_Error;

final class WorkerClient
{
    public function status(): array|WP_Error { return $this->request('GET', '/meydan-admin/eitaa/status'); }
    public function sendCode(string $phone): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/send-code', ['phone' => $phone]); }
    public function verifyCode(string $code, string $password = ''): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/verify-code', ['code' => $code, 'password' => $password]); }
    public function logout(): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/logout'); }

    /** @return array<string,mixed>|WP_Error */
    private function request(string $method, string $path, array $payload = []): array|WP_Error
    {
        $base = rtrim(self::workerUrl(), '/');
        $secret = Auth::secret();
        if ($base === '' || $secret === '') {
            return new WP_Error('eitaa_worker_unconfigured', 'آدرس worker یا کلید اتصال ایتا تنظیم نشده است.');
        }
        $body = $method === 'GET' ? '' : (wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $response = wp_remote_request($base . $path, [
            'method' => $method,
            'timeout' => 25,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Meydan-Eitaa-Timestamp' => $timestamp,
                'X-Meydan-Eitaa-Nonce' => $nonce,
                'X-Meydan-Eitaa-Signature' => $signature,
            ],
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return new WP_Error('eitaa_worker_bad_response', 'پاسخ worker معتبر نیست.');
        }
        if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
            return new WP_Error(
                (string) ($decoded['error'] ?? 'eitaa_worker_error'),
                (string) ($decoded['message'] ?? 'عملیات worker ناموفق بود.'),
                ['status' => $status ?: 502]
            );
        }
        return $decoded;
    }

    private static function workerUrl(): string
    {
        if (defined('EITAA_WORKER_URL')) {
            $value = trim((string) constant('EITAA_WORKER_URL'));
            if ($value !== '') return $value;
        }
        return trim((string) getenv('EITAA_WORKER_URL'));
    }
}
