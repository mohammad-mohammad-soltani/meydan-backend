<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\UserEmails;
use WP_Error;
use WP_REST_Request;
use WP_User;

/** Administrator-only account management; account deletion is reversible. */
final class AdminUserController extends BaseController
{
    private const ROLES = [
        'meydan_user', 'meydan_speaker', 'meydan_square', 'meydan_content_editor',
        'meydan_moderator', 'meydan_manager', 'meydan_support', 'administrator',
    ];
    private const ROLE_LABELS = [
        'meydan_user' => 'کاربر عادی', 'meydan_speaker' => 'سخنران', 'meydan_square' => 'مالک میدان',
        'meydan_content_editor' => 'ویرایشگر محتوا', 'meydan_moderator' => 'ناظر',
        'meydan_manager' => 'مدیر میدان', 'meydan_support' => 'پشتیبان', 'administrator' => 'مدیرکل',
    ];

    public function roles()
    {
        $registered = wp_roles()->roles;
        return Response::ok(array_values(array_map(static fn($role) => [
            'value' => $role, 'label' => self::ROLE_LABELS[$role],
        ], array_filter(self::ROLES, static fn($role) => isset($registered[$role])))));
    }

    public function list(WP_REST_Request $r)
    {
        $page = max(1, (int) ($r->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($r->get_param('per_page') ?: 20)));
        $role = sanitize_key((string) $r->get_param('role'));
        $status = sanitize_key((string) $r->get_param('status'));
        if ($role !== '' && !in_array($role, self::ROLES, true)) return Response::error('validation_failed', 'نقش معتبر نیست.', 422);
        if ($status !== '' && !in_array($status, ['active', 'disabled'], true)) return Response::error('validation_failed', 'وضعیت معتبر نیست.', 422);
        $q = trim((string) $r->get_param('q'));
        $digits = strtr($q, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $phone = preg_replace('/[^+0-9]/', '', $digits);
        if (str_starts_with($phone, '09')) $phone = '+98' . substr($phone, 1);
        if (str_starts_with($phone, '0098')) $phone = '+98' . substr($phone, 4);
        $ids = get_users(['fields' => 'ID', 'orderby' => 'registered', 'order' => 'DESC', 'number' => -1]);
        $matched = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $user = get_userdata($id);
            if (!$user) continue;
            if ($role !== '' && !in_array($role, (array) $user->roles, true)) continue;
            if ($status !== '' && (UserAccess::disabled($id) ? 'disabled' : 'active') !== $status) continue;
            if ($q !== '') {
                $name = (string) get_user_meta($id, 'meydan_full_name', true) . ' ' . $user->display_name;
                $mobile = $this->phone($id);
                if (mb_stripos($name, $q) === false && stripos($user->user_email, $q) === false
                    && ($phone === '' || strpos($mobile, $phone) === false)) continue;
            }
            $matched[] = $id;
        }
        $total = count($matched);
        return Response::ok(array_map(fn($id) => $this->serialize($id), array_slice($matched, ($page - 1) * $perPage, $perPage)), [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function get(WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        return get_userdata($id) ? Response::ok($this->serialize($id)) : Response::error('not_found', 'کاربر پیدا نشد.', 404);
    }

    public function create(WP_REST_Request $r)
    {
        $p = $this->json($r);
        $validated = $this->validate($p, 0);
        if (is_wp_error($validated)) return $this->error($validated);
        $role = (string) $p['role'];
        $name = sanitize_text_field((string) $p['full_name']);
        $email = sanitize_email((string) ($p['email'] ?? ''));
        $id = wp_insert_user([
            'user_login' => 'meydan_internal_' . strtolower(wp_generate_password(20, false, false)),
            'user_pass' => wp_generate_password(64, true, true), 'display_name' => $name,
            'user_email' => $email, 'role' => 'meydan_user',
        ]);
        if (is_wp_error($id)) return $this->error($id);
        $id = (int) $id;
        $this->saveProfile($id, $p, $validated);
        $changed = $this->changeRole($id, $role, $p);
        if (is_wp_error($changed)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($id);
            return $this->error($changed);
        }
        AuditLogger::log('user_created', 'user', $id, null, ['role' => $role]);
        return Response::ok($this->serialize($id), [], 201);
    }

    public function update(WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        if (!get_userdata($id)) return Response::error('not_found', 'کاربر پیدا نشد.', 404);
        $p = $this->json($r);
        $validated = $this->validate($p, $id);
        if (is_wp_error($validated)) return $this->error($validated);
        $before = $this->serialize($id);
        if (array_key_exists('role', $p)) {
            $changed = $this->changeRole($id, (string) $p['role'], $p);
            if (is_wp_error($changed)) return $this->error($changed);
        }
        $this->saveProfile($id, $p, $validated);
        AuditLogger::log('user_updated', 'user', $id, ['role' => $before['role']], ['role' => (string) get_userdata($id)->roles[0], 'fields' => array_keys($p)]);
        return Response::ok($this->serialize($id));
    }

    public function status(WP_REST_Request $r)
    {
        $id = (int) $r['id'];
        $p = $this->json($r);
        if (!isset($p['disabled']) || !is_bool($p['disabled'])) return Response::error('validation_failed', 'وضعیت معتبر نیست.', 422);
        $before = get_userdata($id) ? $this->serialize($id) : null;
        $result = UserAccess::setDisabled($id, $p['disabled']);
        if (is_wp_error($result)) return $this->error($result);
        AuditLogger::log($p['disabled'] ? 'user_disabled' : 'user_enabled', 'user', $id, ['disabled' => $before['disabled']], ['disabled' => $p['disabled']]);
        return Response::ok($this->serialize($id));
    }

    private function validate(array $p, int $id): array|WP_Error
    {
        $fields = [];
        if (!$id || array_key_exists('full_name', $p)) {
            if (trim((string) ($p['full_name'] ?? '')) === '') $fields['full_name'] = 'required';
        }
        if (!$id || array_key_exists('role', $p)) {
            if (!in_array((string) ($p['role'] ?? ''), self::ROLES, true)) $fields['role'] = 'invalid';
        }
        $phone = null;
        if (!$id || array_key_exists('phone', $p)) {
            $phone = OtpService::normalizePhone((string) ($p['phone'] ?? ''));
            if ($phone === '') $fields['phone'] = 'invalid';
            else {
                $matches = get_users(['meta_key' => 'meydan_phone_hash', 'meta_value' => Crypto::hash($phone), 'fields' => 'ID']);
                if (array_filter($matches, static fn($candidate) => (int) $candidate !== $id)) $fields['phone'] = 'taken';
            }
        }
        if (array_key_exists('email', $p)) {
            $email = sanitize_email((string) $p['email']);
            if ($email !== '' && (!is_email($email) || (($owner = email_exists($email)) && (int) $owner !== $id))) $fields['email'] = 'invalid_or_taken';
        }
        foreach (['avatar_media_id', 'cover_media_id'] as $key) {
            if (array_key_exists($key, $p) && $p[$key] !== null && ((int) $p[$key] < 0 || ((int) $p[$key] > 0 && !wp_attachment_is_image((int) $p[$key])))) $fields[$key] = 'invalid';
        }
        $province = (int) (array_key_exists('province_id', $p) ? $p['province_id'] : ($id ? get_user_meta($id, 'meydan_province_id', true) : 0));
        $city = (int) (array_key_exists('city_id', $p) ? $p['city_id'] : ($id ? get_user_meta($id, 'meydan_city_id', true) : 0));
        if ($province < 0 || $city < 0) $fields['city_id'] = 'invalid';
        if ($city > 0) {
            global $wpdb;
            if ($province <= 0 || !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d AND active=1", $city, $province))) $fields['city_id'] = 'invalid';
        }
        if ($fields) return new WP_Error('validation_failed', 'اطلاعات واردشده معتبر نیست.', ['status' => 422, 'fields' => $fields]);
        return ['phone' => $phone];
    }

    private function saveProfile(int $id, array $p, array $validated): void
    {
        if (array_key_exists('phone', $p) && $validated['phone']) {
            update_user_meta($id, 'meydan_phone_hash', Crypto::hash($validated['phone']));
            update_user_meta($id, 'meydan_phone_ciphertext', Crypto::encrypt($validated['phone']));
            UserEmails::ensureEmail($id);
        }
        $update = ['ID' => $id];
        if (array_key_exists('full_name', $p)) {
            $name = sanitize_text_field((string) $p['full_name']);
            $update['display_name'] = $name;
            update_user_meta($id, 'meydan_full_name', $name);
        }
        if (array_key_exists('email', $p) && (string) $p['email'] !== '') $update['user_email'] = sanitize_email((string) $p['email']);
        if (count($update) > 1) wp_update_user($update);
        // All Meydan accounts authenticate with their mobile number, so a
        // submitted or legacy email must not replace the canonical address.
        UserEmails::ensureEmail($id);
        foreach (['headline', 'location_label'] as $key) if (array_key_exists($key, $p)) update_user_meta($id, 'meydan_' . $key, sanitize_text_field((string) $p[$key]));
        if (array_key_exists('about', $p)) update_user_meta($id, 'meydan_about', wp_kses_post((string) $p['about']));
        foreach (['province_id', 'city_id', 'avatar_media_id', 'cover_media_id'] as $key) if (array_key_exists($key, $p)) update_user_meta($id, 'meydan_' . $key, max(0, (int) $p[$key]));
    }

    private function changeRole(int $id, string $role, array $p): true|WP_Error
    {
        $user = get_userdata($id);
        if (!$user || !in_array($role, self::ROLES, true)) return new WP_Error('validation_failed', 'نقش معتبر نیست.', ['status' => 422]);
        $oldRole = (string) ($user->roles[0] ?? 'meydan_user');
        if ($oldRole === $role) return true;
        if ($oldRole === 'administrator' && UserAccess::activeAdminCount() <= 1) {
            return new WP_Error('validation_failed', 'نقش آخرین مدیرکل را نمی‌توان تغییر داد.', ['status' => 422, 'fields' => ['role' => 'last_admin']]);
        }
        if ($id === get_current_user_id() && $oldRole === 'administrator') {
            return new WP_Error('validation_failed', 'نقش مدیر فعلی را نمی‌توان تغییر داد.', ['status' => 422]);
        }
        if ($role === 'meydan_square' && Actor::squareId($id) <= 0) {
            $square = $this->createSquare($id, $p);
            if (is_wp_error($square)) return $square;
        }
        if ($oldRole === 'meydan_square' && $role !== 'meydan_square') {
            $squareId = Actor::squareId($id);
            if ($squareId) update_post_meta($squareId, 'meydan_disabled_by_owner', '1');
        }
        if ($role === 'meydan_speaker') {
            if ($oldRole === 'meydan_square' || $oldRole === 'administrator') $user->set_role('meydan_user');
            $promoted = SpeakerService::promote($id);
            if (is_wp_error($promoted)) {
                $user->set_role($oldRole);
                return $promoted;
            }
        } else {
            $user->set_role($role);
            update_user_meta($id, 'meydan_account_type', $role === 'meydan_square' ? 'square' : 'user');
        }
        if ($role === 'meydan_square' && ($squareId = Actor::squareId($id))) delete_post_meta($squareId, 'meydan_disabled_by_owner');
        (new \Meydan\Core\Auth\SessionService())->revokeAll($id);
        AuditLogger::log('user_role_changed', 'user', $id, ['role' => $oldRole], ['role' => $role]);
        return true;
    }

    private function createSquare(int $id, array $p): int|WP_Error
    {
        $square = is_array($p['square'] ?? null) ? $p['square'] : [];
        $fields = [];
        foreach (['name', 'address', 'province_id', 'city_id', 'latitude', 'longitude'] as $key) {
            if (!isset($square[$key]) || trim((string) $square[$key]) === '') $fields['square.' . $key] = 'required';
        }
        global $wpdb;
        if (!$fields) {
            $city = (int) $square['city_id'];
            $province = (int) $square['province_id'];
            if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d AND active=1", $city, $province))) $fields['square.city_id'] = 'invalid';
            if (!is_numeric($square['latitude']) || abs((float) $square['latitude']) > 90) $fields['square.latitude'] = 'invalid';
            if (!is_numeric($square['longitude']) || abs((float) $square['longitude']) > 180) $fields['square.longitude'] = 'invalid';
        }
        if ($fields) return new WP_Error('validation_failed', 'اطلاعات میدان کامل یا معتبر نیست.', ['status' => 422, 'fields' => $fields]);
        $post = wp_insert_post(['post_type' => 'meydan_square', 'post_status' => 'pending',
            'post_title' => sanitize_text_field((string) $square['name']), 'post_author' => $id], true);
        if (is_wp_error($post)) return $post;
        update_user_meta($id, 'meydan_square_id', (int) $post);
        update_post_meta($post, 'meydan_owner_user_id', $id);
        update_post_meta($post, 'meydan_approval_status', 'pending_verification');
        update_post_meta($post, 'meydan_verified', 0);
        $saved = $wpdb->replace($wpdb->prefix . 'meydan_square_geo', [
            'square_id' => (int) $post, 'province_id' => (int) $square['province_id'],
            'city_id' => (int) $square['city_id'], 'address' => sanitize_textarea_field((string) $square['address']),
            'latitude' => (float) $square['latitude'], 'longitude' => (float) $square['longitude'],
            'updated_at' => current_time('mysql', true),
        ]);
        if (!$saved) {
            delete_user_meta($id, 'meydan_square_id');
            wp_delete_post((int) $post, true);
            return new WP_Error('internal_error', 'ثبت موقعیت میدان ممکن نشد.', ['status' => 500]);
        }
        return (int) $post;
    }

    private function phone(int $id): string
    {
        $cipher = (string) get_user_meta($id, 'meydan_phone_ciphertext', true);
        if ($cipher === '') return '';
        try { return Crypto::decrypt($cipher); } catch (\Throwable) { return ''; }
    }

    private function serialize(int $id): array
    {
        $user = get_userdata($id);
        $avatar = (int) get_user_meta($id, 'meydan_avatar_media_id', true);
        $cover = (int) get_user_meta($id, 'meydan_cover_media_id', true);
        return ['id' => $id, 'full_name' => (string) get_user_meta($id, 'meydan_full_name', true) ?: $user->display_name,
            'phone' => $this->phone($id), 'email' => UserEmails::isPlaceholder($user->user_email) ? '' : $user->user_email,
            'role' => (string) ($user->roles[0] ?? ''), 'roles' => array_values((array) $user->roles),
            'disabled' => UserAccess::disabled($id), 'headline' => (string) get_user_meta($id, 'meydan_headline', true),
            'about' => (string) get_user_meta($id, 'meydan_about', true),
            'location_label' => (string) get_user_meta($id, 'meydan_location_label', true),
            'province_id' => (int) get_user_meta($id, 'meydan_province_id', true) ?: null,
            'city_id' => (int) get_user_meta($id, 'meydan_city_id', true) ?: null,
            'avatar_media_id' => $avatar ?: null, 'avatar_url' => $avatar ? wp_get_attachment_url($avatar) : null,
            'cover_media_id' => $cover ?: null, 'cover_url' => $cover ? wp_get_attachment_url($cover) : null,
            'square_id' => Actor::squareId($id) ?: null, 'registered_at' => $user->user_registered];
    }
}
