<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Integrations\Channels\Channels;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\SquareActivity;
use Meydan\Core\Support\UserEmails;
use WP_Error;

/** Shared write path for administrator-managed square accounts. */
final class SquareAdminService
{
    public const STATUSES = ['pending_verification', 'approved', 'rejected', 'suspended'];

    /** @param array<string,mixed> $input @return array{user_id:int,square_id:int,name:string}|WP_Error */
    public static function create(array $input): array|WP_Error
    {
        $phone = OtpService::normalizePhone((string) ($input['phone'] ?? ''));
        if ($phone === '') {
            return new WP_Error('validation_failed', 'شماره موبایل معتبر نیست.', ['status' => 422, 'fields' => ['phone' => 'invalid']]);
        }
        if (self::phoneOwner($phone) > 0) {
            return new WP_Error('validation_failed', 'این شماره موبایل قبلاً ثبت شده است.', ['status' => 422, 'fields' => ['phone' => 'taken']]);
        }

        $name = sanitize_text_field((string) ($input['square_name'] ?? ''));
        if ($name === '') {
            return new WP_Error('validation_failed', 'نام میدان الزامی است.', ['status' => 422, 'fields' => ['square_name' => 'required']]);
        }

        $ownerName = sanitize_text_field((string) ($input['full_name'] ?? ''));
        if ($ownerName === '') {
            return new WP_Error('validation_failed', 'نام و نام خانوادگی مالک الزامی است.', ['status' => 422, 'fields' => ['full_name' => 'required']]);
        }
        $province = (int) ($input['province_id'] ?? 0);
        $city = (int) ($input['city_id'] ?? 0);
        if ($province <= 0 || $city <= 0 || !self::cityBelongsTo($city, $province)) {
            return new WP_Error('validation_failed', 'استان و شهر معتبر نیستند.', ['status' => 422, 'fields' => ['city_id' => 'invalid']]);
        }
        $address = sanitize_textarea_field((string) ($input['address'] ?? ''));
        if ($address === '') {
            return new WP_Error('validation_failed', 'نشانی میدان الزامی است.', ['status' => 422, 'fields' => ['address' => 'required']]);
        }
        $email = sanitize_email((string) ($input['email'] ?? ''));
        if ($email !== '' && (!is_email($email) || email_exists($email))) {
            return new WP_Error('validation_failed', 'ایمیل معتبر نیست یا قبلاً ثبت شده است.', ['status' => 422, 'fields' => ['email' => 'invalid_or_taken']]);
        }
        $lat = self::coordinate($input['latitude'] ?? null, NAN);
        $lng = self::coordinate($input['longitude'] ?? null, NAN);
        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return new WP_Error('validation_failed', 'مختصات خارج از محدوده مجاز است.', ['status' => 422, 'fields' => ['latitude' => 'invalid', 'longitude' => 'invalid']]);
        }

        $status = sanitize_key((string) ($input['status'] ?? 'pending_verification'));
        if (!in_array($status, ['pending_verification', 'approved'], true)) {
            return new WP_Error('validation_failed', 'وضعیت اولیه فقط می‌تواند pending_verification یا approved باشد.', ['status' => 422, 'fields' => ['status' => 'invalid']]);
        }
        $approved = $status === 'approved';
        $display = $ownerName;
        $userId = wp_insert_user([
            'user_login' => 'meydan_internal_' . strtolower(wp_generate_password(20, false, false)),
            'user_pass' => wp_generate_password(64, true, true),
            'display_name' => $display,
            'user_email' => $email,
            'role' => 'meydan_user',
        ]);
        if (is_wp_error($userId)) {
            return new WP_Error('internal_error', 'ساخت حساب مالک ناموفق بود.', ['status' => 500]);
        }
        $userId = (int) $userId;
        update_user_meta($userId, 'meydan_account_type', 'square');
        update_user_meta($userId, 'meydan_phone_hash', Crypto::hash($phone));
        update_user_meta($userId, 'meydan_phone_ciphertext', Crypto::encrypt($phone));
        update_user_meta($userId, 'meydan_full_name', $display);
        update_user_meta($userId, 'meydan_province_id', $province);
        update_user_meta($userId, 'meydan_city_id', $city);
        update_user_meta($userId, 'meydan_about', wp_kses_post((string) ($input['description'] ?? '')));
        UserEmails::ensureEmail($userId);

        $avatar = max(0, (int) ($input['avatar_media_id'] ?? 0));
        $squareId = wp_insert_post([
            'post_type' => 'meydan_square',
            'post_status' => $approved ? 'publish' : 'pending',
            'post_title' => $name,
            'post_content' => wp_kses_post((string) ($input['description'] ?? '')),
            'post_author' => $userId,
        ], true);
        if (is_wp_error($squareId)) {
            wp_delete_user($userId);
            return new WP_Error('internal_error', 'ساخت میدان ناموفق بود.', ['status' => 500]);
        }
        $squareId = (int) $squareId;
        update_post_meta($squareId, 'meydan_owner_user_id', $userId);
        update_post_meta($squareId, 'meydan_approval_status', $status);
        update_post_meta($squareId, 'meydan_verified', $approved ? 1 : 0);
        update_post_meta($squareId, 'meydan_contact_name', sanitize_text_field((string) ($input['contact_name'] ?? '')));
        update_post_meta($squareId, 'meydan_contact_phone', sanitize_text_field((string) ($input['contact_phone'] ?? '')));
        if ($avatar > 0 && wp_attachment_is_image($avatar)) {
            update_post_meta($squareId, 'meydan_avatar_media_id', $avatar);
            update_user_meta($userId, 'meydan_avatar_media_id', $avatar);
        }
        update_user_meta($userId, 'meydan_square_id', $squareId);
        if ((string) ($input['start_date'] ?? '') !== '') {
            SquareActivity::setStartDate($squareId, sanitize_text_field((string) $input['start_date']));
        }
        self::saveGeo($squareId, $province, $city, $address, $lat, $lng);
        self::saveChannels($squareId, $userId, $input);
        (new \WP_User($userId))->set_role('meydan_square');
        AuditLogger::log('square_created_manually', 'square', $squareId, null, ['user_id' => $userId, 'approval_status' => $status, 'province_id' => $province, 'city_id' => $city]);

        return ['user_id' => $userId, 'square_id' => $squareId, 'name' => $name];
    }

    /** @param array<string,mixed> $input */
    public static function update(int $id, array $input): true|WP_Error
    {
        if (get_post_type($id) !== 'meydan_square' || get_post_status($id) === 'trash') {
            return new WP_Error('not_found', 'میدان پیدا نشد.', ['status' => 404]);
        }
        $post = ['ID' => $id];
        if (array_key_exists('name', $input) || array_key_exists('square_name', $input)) {
            $post['post_title'] = sanitize_text_field((string) ($input['name'] ?? $input['square_name']));
        }
        if (array_key_exists('description', $input)) {
            $post['post_content'] = wp_kses_post((string) $input['description']);
            $owner = (int) get_post_meta($id, 'meydan_owner_user_id', true);
            if ($owner > 0) update_user_meta($owner, 'meydan_about', $post['post_content']);
        }
        $result = wp_update_post($post, true);
        if (is_wp_error($result)) return $result;
        foreach (['contact_name', 'contact_phone'] as $key) {
            if (array_key_exists($key, $input)) update_post_meta($id, 'meydan_' . $key, sanitize_text_field((string) $input[$key]));
        }
        if (array_key_exists('avatar_media_id', $input)) update_post_meta($id, 'meydan_avatar_media_id', max(0, (int) $input['avatar_media_id']));
        if (array_key_exists('start_date', $input)) SquareActivity::setStartDate($id, sanitize_text_field((string) $input['start_date']));
        $geo = self::geo($id);
        if (isset($input['province_id'], $input['city_id'], $input['address'], $input['latitude'], $input['longitude'])) {
            $province = (int) $input['province_id']; $city = (int) $input['city_id'];
            if (!self::cityBelongsTo($city, $province)) return new WP_Error('validation_failed', 'استان و شهر معتبر نیستند.', ['status' => 422]);
            self::saveGeo($id, $province, $city, (string) $input['address'], (float) $input['latitude'], (float) $input['longitude']);
        } elseif ($geo && array_key_exists('address', $input)) {
            self::saveGeo($id, (int) $geo['province_id'], (int) $geo['city_id'], (string) $input['address'], (float) $geo['latitude'], (float) $geo['longitude']);
        }
        $owner = (int) get_post_meta($id, 'meydan_owner_user_id', true);
        if ($owner > 0) self::saveChannels($id, $owner, $input);
        AuditLogger::log('square_updated', 'square', $id, null, Serializer::square($id));
        return true;
    }

    private static function saveChannels(int $squareId, int $userId, array $input): void
    {
        foreach (['eitaa' => 'eitaa_channel', 'bale' => 'bale_channel'] as $kind => $key) {
            if (array_key_exists($key, $input)) Channels::store($userId, $kind, (string) $input[$key]);
        }
        foreach (Channels::summary($userId) as $kind => $value) {
            if ($value !== '') update_post_meta($squareId, 'meydan_' . $kind, \Meydan\Core\Admin\ManualSquare::channelUrl($kind, $value));
            else delete_post_meta($squareId, 'meydan_' . $kind);
        }
    }

    private static function saveGeo(int $id, int $province, int $city, string $address, float $lat, float $lng): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'meydan_square_geo', ['square_id' => $id, 'province_id' => $province, 'city_id' => $city, 'address' => sanitize_textarea_field($address), 'latitude' => $lat, 'longitude' => $lng, 'updated_at' => current_time('mysql', true)]);
    }

    private static function geo(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    private static function cityBelongsTo(int $city, int $province): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d AND active=1", $city, $province)) > 0;
    }

    private static function phoneOwner(string $phone): int
    {
        $users = get_users(['meta_key' => 'meydan_phone_hash', 'meta_value' => Crypto::hash($phone), 'number' => 1, 'fields' => 'ID']);
        return $users ? (int) $users[0] : 0;
    }

    private static function coordinate(mixed $value, float $fallback): float
    {
        $value = trim(strtr((string) $value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        return $value !== '' && is_numeric($value) ? (float) $value : $fallback;
    }
}
