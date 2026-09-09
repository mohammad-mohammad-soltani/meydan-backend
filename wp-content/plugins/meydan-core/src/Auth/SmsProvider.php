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

        $settings = (array) get_option('meydan_sms_settings', []);
        $enabled = !array_key_exists('enabled', $settings) || (bool) $settings['enabled'];
        if (!$enabled) {
            return new WP_Error('sms_not_configured', 'ارسال پیامک در تنظیمات غیرفعال است.');
        }
        $endpoint = trim((string) ($settings['endpoint'] ?? (defined('MEYDAN_SMS_ENDPOINT') ? MEYDAN_SMS_ENDPOINT : 'https://edge.ippanel.com/v1/api/send')));
        $token = (string) ($settings['token'] ?? (defined('MEYDAN_SMS_TOKEN') ? MEYDAN_SMS_TOKEN : ''));
        $from = trim((string) ($settings['from_number'] ?? (defined('MEYDAN_SMS_FROM_NUMBER') ? MEYDAN_SMS_FROM_NUMBER : '')));
        $pattern = trim((string) ($settings['pattern_code'] ?? (defined('MEYDAN_SMS_PATTERN_CODE') ? MEYDAN_SMS_PATTERN_CODE : '')));
        if ($token === '' || $from === '' || $pattern === '') {
            return new WP_Error('sms_not_configured', 'سرویس پیامک پیکربندی نشده است.');
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => array_filter([
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ]),
            'body' => wp_json_encode([
                'sending_type' => 'pattern',
                'from_number' => $from,
                'code' => $pattern,
                'recipients' => [$phone],
                'params' => ['code' => $code],
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
}
