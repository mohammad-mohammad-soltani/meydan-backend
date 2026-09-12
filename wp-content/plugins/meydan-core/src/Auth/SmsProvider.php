<?php

declare(strict_types=1);

namespace Meydan\Core\Auth;

use Meydan\Core\Audit\AuditLogger;
use WP_Error;

final class SmsProvider
{
    public function send(string $phone, string $code): true|WP_Error
    {
        $dev = defined('MEYDAN_DEV_OTP_CODE') ? (string) MEYDAN_DEV_OTP_CODE : '';
        if ($dev !== '' && wp_get_environment_type() === 'local') {
            // Local acceptance mode: no external SMS is sent. The known code lives only in deployment config.
            return true;
        }

        return $this->sendToProvider($phone, $code);
    }

    public function sendTest(string $phone, string $code): true|WP_Error
    {
        return $this->sendToProvider($phone, $code);
    }

    private function sendToProvider(string $phone, string $code): true|WP_Error
    {
        $settings = (array) get_option('meydan_sms_settings', []);
        $enabled = !array_key_exists('enabled', $settings) || (bool) $settings['enabled'];
        if (!$enabled) {
            return $this->failure('sms_not_configured', 'ارسال پیامک در تنظیمات غیرفعال است.', ['reason' => 'disabled']);
        }
        $endpoint = trim((string) ($settings['endpoint'] ?? (defined('MEYDAN_SMS_ENDPOINT') ? MEYDAN_SMS_ENDPOINT : 'https://api.iranpayamak.com/ws/v1/sms/pattern')));
        if ($endpoint === '' || $endpoint === 'https://edge.ippanel.com/v1/api/send') {
            $endpoint = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
        }
        $token = (string) ($settings['token'] ?? (defined('MEYDAN_SMS_TOKEN') ? MEYDAN_SMS_TOKEN : ''));
        $from = trim((string) ($settings['from_number'] ?? (defined('MEYDAN_SMS_FROM_NUMBER') ? MEYDAN_SMS_FROM_NUMBER : '')));
        $pattern = trim((string) ($settings['pattern_code'] ?? (defined('MEYDAN_SMS_PATTERN_CODE') ? MEYDAN_SMS_PATTERN_CODE : '')));
        if ($token === '' || $from === '' || $pattern === '') {
            return $this->failure('sms_not_configured', 'سرویس پیامک پیکربندی نشده است.', [
                'provider' => 'iranpayamak',
                'reason' => 'missing_token_or_line_or_pattern',
            ]);
        }

        $recipient = self::recipientNumber($phone);
        if ($recipient === '') {
            return $this->failure('sms_invalid_recipient', 'شماره گیرنده پیامک معتبر نیست.', [
                'provider' => 'iranpayamak',
                'reason' => 'invalid_recipient_format',
            ]);
        }

        $timeout = (int) ($settings['timeout'] ?? (defined('MEYDAN_SMS_TIMEOUT') ? (int) MEYDAN_SMS_TIMEOUT : 60));
        if ($timeout < 5) {
            $timeout = 5;
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => $timeout,
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $token,
            ]),
            'body' => wp_json_encode([
                'code' => $pattern,
                'attributes' => ['code' => $code],
                'recipient' => $recipient,
                'line_number' => $from,
                'number_format' => 'english',
            ]),
        ]);
        if (is_wp_error($response)) {
            return $this->failure('sms_transport_error', 'ارتباط با ایران‌پیامک ناموفق بود.', [
                'provider' => 'iranpayamak',
                'reason' => 'wp_http_error',
                'transport_error' => $response->get_error_message(),
            ]);
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $body = substr((string) wp_remote_retrieve_body($response), 0, 4000);
            $message = 'ایران‌پیامک درخواست را نپذیرفت. HTTP ' . $status . '.';
            if ($body !== '') {
                $message .= ' پاسخ provider: ' . $body;
            }
            return $this->failure('sms_provider_error', $message, [
                'provider' => 'iranpayamak',
                'http_status' => $status,
                'response_body' => $body,
            ]);
        }
        return true;
    }

    private static function recipientNumber(string $phone): string
    {
        $phone = trim($phone);
        if (preg_match('/^\+98(\d{10})$/', $phone, $matches)) {
            return '0' . $matches[1];
        }
        return preg_match('/^09\d{9}$/', $phone) ? $phone : '';
    }

    private function failure(string $code, string $message, array $details = []): WP_Error
    {
        AuditLogger::log('sms_provider_error', 'sms', null, null, [
            'error_code' => $code,
            'message' => $message,
            'details' => $details,
        ]);
        return new WP_Error($code, $message, $details);
    }
}
