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

        $endpoint = defined('MEYDAN_SMS_ENDPOINT') ? trim((string) MEYDAN_SMS_ENDPOINT) : '';
        $token = defined('MEYDAN_SMS_TOKEN') ? (string) MEYDAN_SMS_TOKEN : '';
        if ($endpoint === '') {
            return new WP_Error('sms_not_configured', 'سرویس پیامک پیکربندی نشده است.');
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => array_filter([
                'Content-Type' => 'application/json',
                'Authorization' => $token !== '' ? 'Bearer ' . $token : null,
            ]),
            'body' => wp_json_encode(['phone' => $phone, 'code' => $code]),
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
