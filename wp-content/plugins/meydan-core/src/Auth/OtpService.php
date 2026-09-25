<?php

declare(strict_types=1);

namespace Meydan\Core\Auth;

use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\RateLimiter;
use WP_Error;

final class OtpService
{
    public const EXPIRES = 120;
    public const RESEND_AFTER = 60;

    /**
     * Local development escape hatch for the challenge lifetime.
     *
     * The shipped contract (see SPEC.md) keeps a challenge valid for EXPIRES
     * seconds. While developing against the pinned MEYDAN_DEV_OTP_CODE the
     * developer usually learns the code out-of-band, which makes the two minute
     * window easy to miss, so MEYDAN_DEV_OTP_TTL may extend it. The override is
     * ignored unless wp_get_environment_type() is 'local'.
     */
    private static function expiresIn(): int
    {
        $override = defined('MEYDAN_DEV_OTP_TTL') ? (int) MEYDAN_DEV_OTP_TTL : 0;

        return ($override > 0 && wp_get_environment_type() === 'local') ? $override : self::EXPIRES;
    }

    public function request(string $phone): array|WP_Error
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '') {
            return new WP_Error('validation_failed', 'شماره موبایل معتبر نیست.', ['status' => 422, 'fields' => ['phone' => 'invalid']]);
        }

        $phoneHash = Crypto::hash($phone);
        $dev = defined('MEYDAN_DEV_OTP_CODE') ? trim((string) MEYDAN_DEV_OTP_CODE) : '';
        $code = ($dev !== '' && wp_get_environment_type() === 'local') ? $dev : (string) random_int(100000, 999999);
        if (!preg_match('/^\d{6}$/', $code)) {
            return new WP_Error('otp_config_invalid', 'تنظیمات OTP محلی معتبر نیست.', ['status' => 500]);
        }

        $challenge = Crypto::randomToken(18, 'otp_');
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_auth_challenges';
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::expiresIn());
        $inserted = $wpdb->insert($table, [
            'challenge_id' => $challenge,
            'phone_hash' => $phoneHash,
            'phone_ciphertext' => Crypto::encrypt($phone),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'purpose' => 'login',
            'attempt_count' => 0,
            'expires_at' => $expires,
            'ip_hash' => RateLimiter::ip(),
            'created_at' => $now,
        ]);
        if (!$inserted) {
            return new WP_Error('internal_error', 'ایجاد چالش ورود ناموفق بود.', ['status' => 500]);
        }

        $sent = (new SmsProvider())->send($phone, $code);
        $deliveryStatus = 'sent';
        if (is_wp_error($sent)) {
            // A timeout means the provider might already have accepted and sent
            // the SMS. Preserve this challenge so a received code remains usable.
            if (!$this->isTransportUncertain($sent)) {
                $wpdb->delete($table, ['challenge_id' => $challenge]);
                return $sent;
            }
            $deliveryStatus = 'uncertain';
        }

        $response = [
            'challenge_id' => $challenge,
            'expires_in' => self::expiresIn(),
            'resend_after' => self::RESEND_AFTER,
            'delivery_status' => $deliveryStatus,
        ];
        // Never expose an OTP outside local development. Local SMS is intentionally
        // bypassed, so the frontend needs a visible development code to continue.
        if ($dev !== '' && wp_get_environment_type() === 'local') {
            $response['dev_code'] = $code;
        }
        return $response;
    }

    private function isTransportUncertain(WP_Error $error): bool
    {
        return $error->get_error_code() === 'sms_transport_error';
    }

    public function verify(string $challengeId, string $code, bool $persistentDevice = false): array|WP_Error
    {
        $code = self::normalizeDigits(trim($code));
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_auth_challenges';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE challenge_id = %s LIMIT 1", $challengeId));
        if (!$row || $row->consumed_at !== null) {
            return new WP_Error('otp_invalid', 'کد یا چالش ورود معتبر نیست.', ['status' => 422]);
        }
        if (strtotime((string) $row->expires_at . ' UTC') < time()) {
            return new WP_Error('otp_expired', 'کد ورود منقضی شده است.', ['status' => 422]);
        }
        if ((int) $row->attempt_count >= 5) {
            return new WP_Error('otp_locked', 'تعداد تلاش‌های ورود بیش از حد مجاز است.', ['status' => 429]);
        }

        $wpdb->query($wpdb->prepare("UPDATE {$table} SET attempt_count = attempt_count + 1 WHERE id = %d", (int) $row->id));
        if (!password_verify($code, (string) $row->code_hash)) {
            return new WP_Error('otp_invalid', 'کد یا چالش ورود معتبر نیست.', ['status' => 422]);
        }

        $phone = Crypto::decrypt((string) $row->phone_ciphertext);
        $userId = $this->findUserByPhoneHash((string) $row->phone_hash);
        $now = current_time('mysql', true);
        $wpdb->update($table, ['consumed_at' => $now], ['id' => (int) $row->id]);

        if ($userId > 0) {
            if (UserAccess::disabled($userId)) return new WP_Error('account_disabled', 'این حساب غیرفعال است.', ['status' => 403]);
            $session = (new SessionService())->issue(
                $userId,
                $persistentDevice ? 'Naghshman Android' : null,
                $persistentDevice,
            );
            if (is_wp_error($session)) {
                return $session;
            }
            return [
                'authenticated' => true,
                'access_token' => $session['access_token'],
                'expires_in' => $session['expires_in'],
                'account' => [
                    'id' => $userId,
                    'account_type' => (string) get_user_meta($userId, 'meydan_account_type', true) ?: 'user',
                ],
            ];
        }

        $registrationToken = Crypto::randomToken(32, 'reg_');
        $wpdb->update($table, [
            'registration_token_hash' => Crypto::hash($registrationToken),
            'registration_expires_at' => gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS),
        ], ['id' => (int) $row->id]);

        return [
            'authenticated' => false,
            'registration_required' => true,
            'registration_token' => $registrationToken,
        ];
    }

    public function consumeRegistrationToken(string $token): string|WP_Error
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_auth_challenges';
        $hash = Crypto::hash($token);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE registration_token_hash = %s AND registration_expires_at >= UTC_TIMESTAMP() LIMIT 1",
            $hash
        ));
        if (!$row) {
            return new WP_Error('registration_token_invalid', 'توکن ثبت‌نام معتبر یا فعال نیست.', ['status' => 422]);
        }
        $wpdb->update($table, ['registration_token_hash' => null, 'registration_expires_at' => null], ['id' => (int) $row->id]);
        return Crypto::decrypt((string) $row->phone_ciphertext);
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = self::normalizeDigits($phone);
        $phone = preg_replace('/[\s\-()]/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '0098')) {
            $phone = '+98' . substr($phone, 4);
        } elseif (str_starts_with($phone, '09')) {
            $phone = '+98' . substr($phone, 1);
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : '';
    }

    private static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function findUserByPhoneHash(string $hash): int
    {
        $users = get_users([
            'meta_key' => 'meydan_phone_hash',
            'meta_value' => $hash,
            'number' => 1,
            'fields' => 'ID',
        ]);
        return $users ? (int) $users[0] : 0;
    }
}
