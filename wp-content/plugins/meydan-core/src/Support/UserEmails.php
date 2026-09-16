<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

/**
 * Public accounts are OTP-only and their WordPress username/password are
 * internal random values, so nothing ever asked WordPress for an email. That
 * left `user_email` empty, and WordPress refuses to save the user edit screen
 * with an empty address ("Please enter an email address"), which blocked every
 * other wp-admin profile edit as well.
 *
 * This class guarantees that every internal account owns a unique, valid,
 * never-deliverable placeholder address. Real addresses entered by an operator
 * are always kept: the placeholder is only written when the address is missing.
 */
final class UserEmails
{
    /** Bumped to re-run the one-off repair of legacy empty addresses. */
    public const BACKFILL_VERSION = '1.0.1';

    private static bool $booted = false;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Any account created through wp_insert_user()/wp_create_user().
        add_action('user_register', [self::class, 'onUserRegistered'], 20);
        // Fills the address before wp_insert_user() validates it, so an empty
        // submitted value is never rejected.
        add_filter('pre_user_email', [self::class, 'filterPreUserEmail'], 10, 1);
        // The user edit screen validates before wp_update_user() runs; this
        // repairs the user object and drops the empty-email error.
        add_action('user_profile_update_errors', [self::class, 'onProfileUpdateErrors'], 10, 3);
    }

    public static function onUserRegistered(int|string $userId): void
    {
        self::ensureEmail((int) $userId);
    }

    /**
     * Repairs legacy accounts created before this class existed: both the empty
     * addresses and any address WordPress would reject. Runs once per
     * BACKFILL_VERSION from Plugin::boot().
     */
    public static function maybeBackfill(): void
    {
        if (get_option('meydan_user_email_backfill') === self::BACKFILL_VERSION) {
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT ID,user_email FROM {$wpdb->users} ORDER BY ID ASC LIMIT 2000", ARRAY_A) ?: [];
        foreach ($rows as $row) {
            if (!is_email(trim((string) $row['user_email']))) {
                self::ensureEmail((int) $row['ID']);
            }
        }

        update_option('meydan_user_email_backfill', self::BACKFILL_VERSION, false);
    }

    /**
     * Gives the account a placeholder address when it has none. Accounts that
     * already hold a valid address are never touched.
     */
    public static function ensureEmail(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $current = trim((string) get_userdata($userId)?->user_email);
        if (is_email($current)) {
            return $current;
        }

        $email = self::uniqueEmail($userId);
        if ($email === '') {
            return '';
        }

        // wp_update_user() would recurse through pre_user_email; a direct
        // database write is the only safe path here.
        self::writeEmail($userId, $email);
        clean_user_cache($userId);

        return $email;
    }

    /**
     * A unique placeholder address that can be passed to wp_insert_user()
     * before the account exists. `user_register` then keeps it as-is.
     */
    public static function placeholderEmail(int $seed = 0): string
    {
        $local = 'meydan-user-' . strtolower(wp_generate_password(12, false, false));
        if ($seed > 0) {
            $local .= '-' . $seed;
        }
        $email = $local . '@' . self::domain();

        $suffix = 1;
        while (email_exists($email)) {
            $suffix++;
            $email = $local . '-' . $suffix . '@' . self::domain();
            if ($suffix > 200) {
                return '';
            }
        }

        return $email;
    }

    public static function filterPreUserEmail(mixed $value): mixed    {
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
        // Only repair a missing or empty address. An address the operator typed
        // is left alone so its own validation error still surfaces.
        if (is_email($submitted) || $submitted !== '') {
            return;
        }

        $email = self::ensureEmail((int) $user->ID);
        if ($email === '') {
            return;
        }

        $user->user_email = $email;
        // edit_user() reports an empty submitted value as `invalid_email`.
        $errors->remove('invalid_email');
        $errors->remove('empty_email');
    }

    /** The account currently being edited, when a profile form is posting. */
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

    private static function uniqueEmail(int $userId): string
    {
        $base = self::baseEmail($userId);
        if ($base === '') {
            return '';
        }
        [$local, $domain] = explode('@', $base, 2);

        $candidate = $base;
        $suffix = 1;
        while (email_exists($candidate)) {
            $owner = (int) email_exists($candidate);
            if ($owner === $userId) {
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

    /**
     * Stable identifier for the placeholder. The phone is the account's real
     * identity, so it is preferred; the account id keeps it collision-free.
     */
    private static function baseEmail(int $userId): string
    {
        $identifier = '';
        $phoneHash = (string) get_user_meta($userId, 'meydan_phone_hash', true);
        if ($phoneHash !== '') {
            $identifier = substr($phoneHash, 0, 24);
        }
        if ($identifier === '') {
            $identifier = 'user-' . $userId;
        }

        $domain = self::domain();

        return $identifier . '@' . $domain;
    }

    /**
     * Domain of the placeholder address. WordPress rejects a single-label
     * domain, so a local host (localhost, 127.0.0.1) gets a `.local` suffix:
     * localhost becomes localhost.local, which is_email() accepts.
     */
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

    /** True when the address is one of our generated placeholders. */
    public static function isPlaceholder(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@' . self::domain());
    }
}
