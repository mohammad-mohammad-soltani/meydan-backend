<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

/**
 * Public accounts are OTP-only and their WordPress username/password are
 * internal values. WordPress still expects a valid email on profile edits, so
 * accounts without a real email receive a generated placeholder.
 *
 * Whenever a phone number is available, the placeholder is derived from that
 * phone number so wp-admin shows a stable, recognizable value instead of a
 * random string. Real operator-supplied email addresses are never replaced.
 */
final class UserEmails
{
    /** Bumped to migrate legacy random/hash placeholders to phone-based ones. */
    public const BACKFILL_VERSION = '1.1.0';

    private static bool $booted = false;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('user_register', [self::class, 'onUserRegistered'], 20);
        add_filter('pre_user_email', [self::class, 'filterPreUserEmail'], 10, 1);
        add_action('user_profile_update_errors', [self::class, 'onProfileUpdateErrors'], 10, 3);
    }

    public static function onUserRegistered(int|string $userId): void
    {
        self::ensureEmail((int) $userId);
    }

    /**
     * Migrate old empty, random and hash-based placeholders. Calling
     * ensureEmail() for every row is safe because real email addresses are
     * explicitly preserved.
     */
    public static function maybeBackfill(): void
    {
        if (get_option('meydan_user_email_backfill') === self::BACKFILL_VERSION) {
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 2000", ARRAY_A) ?: [];
        foreach ($rows as $row) {
            self::ensureEmail((int) $row['ID']);
        }

        update_option('meydan_user_email_backfill', self::BACKFILL_VERSION, false);
    }

    /**
     * Ensures a valid address. If this account has a phone and its current
     * address is one of Meydan's legacy generated placeholders, it is upgraded
     * to <phone-digits>@<site-host>.
     */
    public static function ensureEmail(int $userId, string $phone = ''): string
    {
        if ($userId <= 0) {
            return '';
        }

        $current = trim((string) get_userdata($userId)?->user_email);
        $phone = $phone !== '' ? $phone : self::phoneForUser($userId);

        if ($phone !== '') {
            $phoneEmail = self::placeholderEmailForPhone($phone, $userId);
            if ($phoneEmail !== '') {
                if (is_email($current) && !self::isGeneratedPlaceholderForUser($current, $userId)) {
                    return $current;
                }
                if ($current !== $phoneEmail) {
                    self::writeEmail($userId, $phoneEmail);
                    clean_user_cache($userId);
                }
                return $phoneEmail;
            }
        }

        if (is_email($current)) {
            return $current;
        }

        $email = self::uniqueLocalEmail('user-' . $userId, $userId);
        if ($email === '') {
            return '';
        }

        self::writeEmail($userId, $email);
        clean_user_cache($userId);

        return $email;
    }

    /**
     * Unique placeholder for flows that do not have the phone number yet.
     * Phone-aware registration should prefer placeholderEmailForPhone().
     */
    public static function placeholderEmail(int $seed = 0): string
    {
        $local = 'meydan-user-' . strtolower(wp_generate_password(12, false, false));
        if ($seed > 0) {
            $local .= '-' . $seed;
        }

        return self::uniqueLocalEmail($local, $seed);
    }

    /**
     * Stable placeholder derived from the normalized phone number.
     * Example: +989121234567 -> 989121234567@example.test
     */
    public static function placeholderEmailForPhone(string $phone, int $userId = 0): string
    {
        $digits = preg_replace('/\D+/', '', self::normalizeDigits($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        return self::uniqueLocalEmail($digits, $userId);
    }

    public static function filterPreUserEmail(mixed $value): mixed
    {
        $email = trim((string) $value);
        if (is_email($email)) {
            return $email;
        }

        $userId = self::submittedUserId();
        if ($userId <= 0) {
            return $value;
        }

        $fallback = self::ensureEmail($userId);

        return $fallback !== '' ? $fallback : $value;
    }

    public static function onProfileUpdateErrors(\WP_Error &$errors, bool $update, mixed $user): void
    {
        if (!$update || !is_object($user) || empty($user->ID)) {
            return;
        }
        $submitted = trim((string) ($user->user_email ?? ''));
        if (is_email($submitted) || $submitted !== '') {
            return;
        }

        $email = self::ensureEmail((int) $user->ID);
        if ($email === '') {
            return;
        }

        $user->user_email = $email;
        $errors->remove('invalid_email');
        $errors->remove('empty_email');
    }

    private static function submittedUserId(): int
    {
        if (!isset($_POST['user_id'])) {
            return 0;
        }
        $userId = (int) $_POST['user_id'];
        if ($userId <= 0) {
            return 0;
        }
        $nonceChecked = isset($_POST['_wpnonce']) || isset($_POST['_wpnonce_create-user']);
        if (!$nonceChecked) {
            return 0;
        }

        return current_user_can('edit_user', $userId) ? $userId : 0;
    }

    private static function phoneForUser(int $userId): string
    {
        $ciphertext = trim((string) get_user_meta($userId, 'meydan_phone_ciphertext', true));
        if ($ciphertext === '') {
            return '';
        }

        try {
            return Crypto::decrypt($ciphertext);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isGeneratedPlaceholderForUser(string $email, int $userId): bool
    {
        if (!self::isPlaceholder($email)) {
            return false;
        }

        $local = strtolower((string) strstr($email, '@', true));
        if ($local === '') {
            return false;
        }

        if (str_starts_with($local, 'meydan-user-')) {
            return true;
        }

        if (preg_match('/^user-' . preg_quote((string) $userId, '/') . '(?:-\d+)?$/', $local)) {
            return true;
        }

        $phoneHash = strtolower(trim((string) get_user_meta($userId, 'meydan_phone_hash', true)));
        if ($phoneHash !== '') {
            $legacyHash = preg_quote(substr($phoneHash, 0, 24), '/');
            if (preg_match('/^' . $legacyHash . '(?:-\d+)?$/', $local)) {
                return true;
            }
        }

        return false;
    }

    private static function uniqueLocalEmail(string $local, int $userId = 0): string
    {
        $local = strtolower(trim($local));
        $local = preg_replace('/[^a-z0-9._-]/', '', $local) ?? '';
        if ($local === '') {
            return '';
        }

        $domain = self::domain();
        $candidate = $local . '@' . $domain;
        $suffix = 1;

        while ($owner = email_exists($candidate)) {
            if ($userId > 0 && (int) $owner === $userId) {
                return $candidate;
            }
            $suffix++;
            $candidate = $local . '-' . $suffix . '@' . $domain;
            if ($suffix > 200) {
                return '';
            }
        }

        return $candidate;
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

    private static function domain(): string
    {
        $host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';
        $host = strtolower(trim($host, '.'));
        if ($host === '') {
            return 'meydan.local';
        }
        if (!str_contains($host, '.')) {
            return $host . '.local';
        }

        return $host;
    }

    private static function writeEmail(int $userId, string $email): void
    {
        global $wpdb;
        $wpdb->update($wpdb->users, ['user_email' => $email], ['ID' => $userId]);
    }

    /** True when the address uses Meydan's generated-email domain. */
    public static function isPlaceholder(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@' . self::domain());
    }
}
