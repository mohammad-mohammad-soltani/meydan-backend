<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class AdminPage
{
    private const PAGE = 'meydan-eitaa-sync';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_meydan_eitaa_send_code', [self::class, 'sendCode']);
        add_action('admin_post_meydan_eitaa_verify_code', [self::class, 'verifyCode']);
        add_action('admin_post_meydan_eitaa_logout', [self::class, 'logout']);
    }

    public static function menu(): void
    {
        add_submenu_page('meydan', 'همگام‌سازی ایتا', 'همگام‌سازی ایتا', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی کافی ندارید.');
        $notice = get_transient(self::noticeKey());
        delete_transient(self::noticeKey());
        $status = (new WorkerClient())->status();
        $authorized = !is_wp_error($status) && !empty($status['authorized']);
        $phone = !is_wp_error($status) ? (string) ($status['phone'] ?? '') : '';
        $username = !is_wp_error($status) ? (string) ($status['username'] ?? '') : '';

        echo '<div class="wrap"><h1>همگام‌سازی ایتا</h1>';
        echo '<p>احراز هویت روی سرور EitaaUserBot انجام می‌شود و توکن/session ایتا وارد وردپرس نمی‌شود.</p>';
        if (is_array($notice)) {
            echo '<div class="notice notice-' . esc_attr((string) ($notice['type'] ?? 'info')) . ' is-dismissible"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
        }
        if (is_wp_error($status)) {
            echo '<div class="notice notice-error"><p>' . esc_html($status->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice ' . ($authorized ? 'notice-success' : 'notice-warning') . '"><p>';
            echo $authorized ? 'اتصال ایتا فعال است.' : 'هنوز حساب ایتا احراز هویت نشده است.';
            if ($phone !== '') echo ' شماره: <code>' . esc_html($phone) . '</code>';
            if ($username !== '') echo ' — کاربر: <code>@' . esc_html(ltrim($username, '@')) . '</code>';
            echo '</p></div>';
        }

        echo '<div style="max-width:760px;display:grid;gap:20px">';
        echo '<div class="card"><h2>۱. ارسال کد</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('meydan_eitaa_send_code');
        echo '<input type="hidden" name="action" value="meydan_eitaa_send_code">';
        echo '<p><label>شماره موبایل<br><input class="regular-text" name="phone" inputmode="tel" placeholder="0912... یا +98912..." required></label></p>';
        submit_button('ارسال کد ایتا', 'primary', 'submit', false);
        echo '</form></div>';

        echo '<div class="card"><h2>۲. تأیید کد</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('meydan_eitaa_verify_code');
        echo '<input type="hidden" name="action" value="meydan_eitaa_verify_code">';
        echo '<p><label>کد پیامک<br><input class="regular-text" name="code" inputmode="numeric" autocomplete="one-time-code" required></label></p>';
        echo '<p><label>رمز دومرحله‌ای (اگر فعال است)<br><input class="regular-text" type="password" name="password" autocomplete="current-password"></label></p>';
        submit_button('تأیید و ذخیره Session', 'primary', 'submit', false);
        echo '</form></div>';

        if ($authorized) {
            echo '<div class="card"><h2>خروج حساب</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('meydan_eitaa_logout');
            echo '<input type="hidden" name="action" value="meydan_eitaa_logout">';
            submit_button('خروج از ایتا', 'delete', 'submit', false);
            echo '</form></div>';
        }
        echo '</div></div>';
    }

    public static function sendCode(): void
    {
        self::guard('meydan_eitaa_send_code');
        $phone = sanitize_text_field((string) wp_unslash($_POST['phone'] ?? ''));
        self::finish((new WorkerClient())->sendCode($phone), 'کد ورود ایتا ارسال شد.');
    }

    public static function verifyCode(): void
    {
        self::guard('meydan_eitaa_verify_code');
        $code = sanitize_text_field((string) wp_unslash($_POST['code'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        self::finish((new WorkerClient())->verifyCode($code, $password), 'حساب ایتا با موفقیت احراز هویت شد.');
    }

    public static function logout(): void
    {
        self::guard('meydan_eitaa_logout');
        self::finish((new WorkerClient())->logout(), 'Session ایتا حذف شد.');
    }

    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) wp_die('دسترسی کافی ندارید.');
        check_admin_referer($action);
    }

    private static function finish(array|\WP_Error $result, string $success): never
    {
        set_transient(self::noticeKey(), [
            'type' => is_wp_error($result) ? 'error' : 'success',
            'message' => is_wp_error($result) ? $result->get_error_message() : $success,
        ], 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }

    private static function noticeKey(): string
    {
        return 'meydan_eitaa_notice_' . get_current_user_id();
    }
}
