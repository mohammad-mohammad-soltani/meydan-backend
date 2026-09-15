<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use Meydan\Core\Integrations\Bale\EventSubscriber as BaleEvents;
use WP_Error;

final class EitaaServiceClient
{
    public function status(): array|WP_Error { return $this->request('GET', '/meydan-admin/eitaa/status'); }
    public function sendCode(string $phone): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/send-code', ['phone' => $phone]); }
    public function verifyCode(string $code, string $password = ''): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/verify-code', ['code' => $code, 'password' => $password]); }
    public function logout(): array|WP_Error { return $this->request('POST', '/meydan-admin/eitaa/logout'); }

    /** @return array<string,mixed>|WP_Error */
    private function request(string $method, string $path, array $payload = []): array|WP_Error
    {
        $base = rtrim(self::serviceUrl(), '/');
        $secret = Auth::secret();
        if ($base === '' || $secret === '') {
            return $this->fail(new WP_Error('eitaa_service_unconfigured', 'آدرس سرویس یا کلید اتصال ایتا تنظیم نشده است.'), $method, $path);
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
            return $this->fail($response, $method, $path);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return $this->fail(new WP_Error('eitaa_service_bad_response', 'پاسخ سرویس ایتا معتبر نیست.', ['status' => $status]), $method, $path);
        }
        if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
            return $this->fail(new WP_Error(
                (string) ($decoded['error'] ?? 'eitaa_service_error'),
                (string) ($decoded['message'] ?? 'عملیات سرویس ایتا ناموفق بود.'),
                ['status' => $status ?: 502]
            ), $method, $path);
        }
        return $decoded;
    }

    /**
     * Every Eitaa failure is surfaced to the Bale chat (requirement 1) before
     * being handed back to the caller, so a sync problem reaches the operator
     * even when the admin page is never opened.
     */
    private function fail(WP_Error $error, string $method, string $path): WP_Error
    {
        BaleEvents::reportEitaaFailure('سرویس ایتا', $error, [
            'method' => $method,
            'path' => $path,
            'http_status' => $error->get_error_data()['status'] ?? null,
        ]);
        return $error;
    }

    private static function serviceUrl(): string
    {
        $option = trim((string) get_option('meydan_eitaa_service_url', ''));
        if ($option !== '') {
            return $option;
        }
        if (defined('EITAA_SERVICE_URL')) {
            $value = trim((string) constant('EITAA_SERVICE_URL'));
            if ($value !== '') return $value;
        }
        return trim((string) getenv('EITAA_SERVICE_URL'));
    }
}
