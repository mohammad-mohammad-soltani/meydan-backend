<?php

declare(strict_types=1);

namespace Meydan\Core\Auth;

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
            return new WP_Error('sms_not_configured', 'ارسال پیامک در تنظیمات غیرفعال است.');
        }
        $endpoint = trim((string) ($settings['endpoint'] ?? (defined('MEYDAN_SMS_ENDPOINT') ? MEYDAN_SMS_ENDPOINT : 'https://api.iranpayamak.com/ws/v1/sms/pattern')));
        if ($endpoint === '' || $endpoint === 'https://edge.ippanel.com/v1/api/send') {
            $endpoint = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
        }
        $token = (string) ($settings['token'] ?? (defined('MEYDAN_SMS_TOKEN') ? MEYDAN_SMS_TOKEN : ''));
        $from = trim((string) ($settings['from_number'] ?? (defined('MEYDAN_SMS_FROM_NUMBER') ? MEYDAN_SMS_FROM_NUMBER : '')));
        $pattern = trim((string) ($settings['pattern_code'] ?? (defined('MEYDAN_SMS_PATTERN_CODE') ? MEYDAN_SMS_PATTERN_CODE : '')));
        $numberFormat = trim((string) ($settings['number_format'] ?? 'english'));
        if ($token === '' || $from === '' || $pattern === '') {
            return new WP_Error('sms_not_configured', 'سرویس پیامک پیکربندی نشده است.');
        }

        $recipient = self::recipientNumber($phone);
        if ($recipient === '') {
            return new WP_Error('sms_invalid_recipient', 'شماره گیرنده پیامک معتبر نیست.');
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $token,
            ]),
            'body' => wp_json_encode([
                'code' => $pattern,
                'attributes' => ['var1' => $code],
                'recipient' => $recipient,
                'line_number' => $from,
                'number_format' => in_array($numberFormat, ['english', 'persian'], true) ? $numberFormat : 'english',
            ]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('sms_provider_error', 'ارسال پیامک ناموفق بود.');
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
}
