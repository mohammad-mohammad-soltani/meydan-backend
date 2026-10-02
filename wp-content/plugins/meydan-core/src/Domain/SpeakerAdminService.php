<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\Handles;
use Meydan\Core\Support\UserEmails;
use WP_Error;

/** Creates an OTP-login user and its speaker role as one recoverable operation. */
final class SpeakerAdminService
{
    /** @param array<string,mixed> $input @return array{user_id:int}|WP_Error */
    public static function create(array $input): array|WP_Error
    {
        $phone = OtpService::normalizePhone((string) ($input['phone'] ?? ''));
        if ($phone === '') {
            return self::validation('شماره موبایل معتبر نیست.', ['phone' => 'invalid']);
        }
        if (self::phoneOwner($phone) > 0) {
            return self::validation('این شماره موبایل قبلاً ثبت شده است.', ['phone' => 'taken']);
        }

        $name = sanitize_text_field((string) ($input['full_name'] ?? ''));
        if ($name === '') {
            return self::validation('نام کامل الزامی است.', ['full_name' => 'required']);
        }
        $rawEmail = trim((string) ($input['email'] ?? ''));
        $email = sanitize_email($rawEmail);
        if ($rawEmail !== '' && (!is_email($rawEmail) || email_exists($email))) {
            return self::validation('ایمیل معتبر نیست یا قبلاً ثبت شده است.', ['email' => 'invalid_or_taken']);
        }

        $province = (int) ($input['province_id'] ?? 0);
        $city = (int) ($input['city_id'] ?? 0);
        if ($province > 0 && !self::provinceExists($province)) {
            return self::validation('استان انتخاب‌شده معتبر نیست.', ['province_id' => 'invalid']);
        }
        if ($city > 0 && ($province <= 0 || !self::cityBelongsTo($city, $province))) {
            return self::validation('شهر انتخاب‌شده معتبر نیست.', ['city_id' => 'invalid']);
        }

        $rawHandle = trim((string) ($input['handle'] ?? ''));
        $handle = null;
        if ($rawHandle !== '') {
            $handle = Handles::validate($rawHandle);
            if (is_wp_error($handle)) return $handle;
        }

        $userId = wp_insert_user([
            'user_login' => 'meydan_internal_' . strtolower(wp_generate_password(20, false, false)),
            'user_pass' => wp_generate_password(64, true, true),
            'display_name' => $name,
            'user_email' => $email,
            'role' => 'meydan_user',
        ]);
        if (is_wp_error($userId)) {
            return new WP_Error('internal_error', 'ساخت حساب سخنران ناموفق بود.', ['status' => 500]);
        }
        $userId = (int) $userId;

        update_user_meta($userId, 'meydan_phone_hash', Crypto::hash($phone));
        update_user_meta($userId, 'meydan_phone_ciphertext', Crypto::encrypt($phone));
        update_user_meta($userId, 'meydan_full_name', $name);
        update_user_meta($userId, 'meydan_about', wp_kses_post((string) ($input['about'] ?? '')));
        if ($province > 0) update_user_meta($userId, 'meydan_province_id', $province);
        if ($city > 0) update_user_meta($userId, 'meydan_city_id', $city);
        UserEmails::ensureEmail($userId);

        Handles::store($userId, $handle ?? Handles::generate($name));

        $promoted = SpeakerService::promote($userId);
        if (is_wp_error($promoted)) {
            self::rollback($userId);
            return $promoted;
        }
        $profile = [];
        foreach (['avatar_media_id', 'verified', 'cities', 'categories', 'social_links', 'eitaa_channel', 'bale_channel'] as $key) {
            if (array_key_exists($key, $input)) $profile[$key] = $input[$key];
        }
        $saved = SpeakerService::save($profile, $userId);
        if (is_wp_error($saved)) {
            self::rollback($userId);
            return $saved;
        }

        AuditLogger::log('speaker_created_manually', 'speaker', $userId, null, [
            'province_id' => $province,
            'city_id' => $city,
            'verified' => !empty($profile['verified']),
        ]);
        return ['user_id' => $userId];
    }

    /** @param array<string,string> $fields */
    private static function validation(string $message, array $fields): WP_Error
    {
        return new WP_Error('validation_failed', $message, ['status' => 422, 'fields' => $fields]);
    }

    private static function rollback(int $userId): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }

    private static function phoneOwner(string $phone): int
    {
        $users = get_users([
            'meta_key' => 'meydan_phone_hash',
            'meta_value' => Crypto::hash($phone),
            'number' => 1,
            'fields' => 'ID',
        ]);
        return $users ? (int) $users[0] : 0;
    }

    private static function provinceExists(int $province): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_provinces WHERE id=%d AND active=1",
            $province,
        )) > 0;
    }

    private static function cityBelongsTo(int $city, int $province): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d AND active=1",
            $city,
            $province,
        )) > 0;
    }
}
