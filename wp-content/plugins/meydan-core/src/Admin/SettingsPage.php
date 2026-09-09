<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

use Meydan\Core\Audit\AuditLogger;

final class SettingsPage
{
    private const JSON_SECTIONS = [
        'feature_flags' => ['پرچم‌های قابلیت', 'فعال/غیرفعال کردن قابلیت‌های نسخه فعلی. هر کلید باید مقدار true یا false داشته باشد.', 7],
        'quick_actions' => ['اقدام‌های سریع', 'تنظیمات اقدام‌های سریع قابل نمایش در کلاینت و پنل.', 7],
        'ranking' => ['رتبه‌بندی Timeline', 'وزن‌ها و قواعد رتبه‌بندی محتوا. فقط در صورت آشنایی با مدل امتیازدهی ویرایش کنید.', 9],
        'timeline' => ['Timeline', 'تنظیمات صفحه Timeline مانند اندازه صفحه، تنوع و کش.', 9],
        'trends' => ['روندها و ترندها', 'تنظیمات محاسبه موضوعات محبوب و بازه زمانی آن‌ها.', 8],
        'notification_templates' => ['قالب اعلان‌ها', 'قالب‌های متنی اعلان‌ها با کلیدهای قابل استفاده در سرویس اعلان.', 12],
    ];

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به تنظیمات میدان را ندارید.', 'meydan-core'));
        }

        $sms = (array) get_option('meydan_sms_settings', []);
        echo '<div class="wrap meydan-admin meydan-settings" dir="rtl">';
        echo '<div class="meydan-page-header"><div><span class="meydan-eyebrow">مرکز کنترل</span><h1>تنظیمات میدان</h1><p>تنظیمات اتصال، تجربه کاربری و رفتار API را از یک محل مدیریت کنید. هر بخش توضیح کوتاه و راهنمای ویرایش دارد.</p></div><div class="meydan-header-mark" aria-hidden="true">M</div></div>';
        settings_errors('meydan');
        echo '<form method="post" class="meydan-settings-form">';
        wp_nonce_field('meydan_admin_action');
        echo '<input type="hidden" name="meydan_admin_action" value="settings_save">';

        echo '<section class="meydan-panel meydan-panel-accent"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">اتصال پیامک</span><h2>ارائه‌دهنده OTP</h2><p>برای ورود بدون رمز عبور، کد یک‌بارمصرف از طریق IPPanel/FarazSMS ارسال می‌شود. توکن به‌صورت ماسک‌شده نگهداری می‌شود و در صفحه دوباره نمایش داده نمی‌شود.</p></div><span class="meydan-status-dot">امن</span></div><div class="meydan-form-grid">';
        self::toggle('sms_enabled', 'فعال‌سازی ارسال پیامک', !isset($sms['enabled']) || (bool) $sms['enabled'], 'در محیط local اگر کد توسعه تعریف شده باشد، ارسال واقعی انجام نمی‌شود.');
        self::text('sms_endpoint', 'آدرس API', (string) ($sms['endpoint'] ?? 'https://edge.ippanel.com/v1/api/send'), 'آدرس رسمی Edge Pattern Send؛ فقط در صورت نیاز به proxy تغییر دهید.', 'url');
        self::secret('sms_token', 'API Token', !empty($sms['token']), 'توکن را برای ثبت یا جایگزینی وارد کنید. برای پاک کردن، گزینه پاک‌سازی را فعال کنید.');
        self::text('sms_from_number', 'شماره فرستنده', (string) ($sms['from_number'] ?? ''), 'فرمت پیشنهادی: +98... یا شماره اختصاصی پنل پیامک.', 'text');
        self::text('sms_pattern_code', 'کد الگوی پیامک', (string) ($sms['pattern_code'] ?? ''), 'کدی که در پنل IPPanel برای متن OTP ساخته‌اید.', 'text');
        self::toggle('sms_clear_token', 'پاک‌سازی توکن ذخیره‌شده', false, 'برای حذف توکن فعلی؛ این گزینه بعد از ذخیره دوباره خاموش می‌شود.');
        echo '</div><p class="meydan-help"><strong>قرارداد ارسال:</strong> درخواست با <code>sending_type=pattern</code>، پارامتر <code>code</code> و هدر Authorization ارسال می‌شود. قبل از فعال‌سازی، یک شماره تست را بررسی کنید.</p></section>';

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">رفتار عمومی</span><h2>تنظیمات API</h2><p>این بخش روی کلاینت‌های متصل به بک‌اند اثر می‌گذارد. Originها را بدون مسیر و هرکدام در یک خط وارد کنید.</p></div></div>';
        self::textarea('api_allowed_origins', 'Originهای مجاز CORS', implode("\n", array_map('strval', (array) (($data = (array) get_option('meydan_api_settings', []))['allowed_origins'] ?? []))), 'مثال: https://app.example.com');
        echo '</section>';

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">پیشرفته</span><h2>تنظیمات تخصصی</h2><p>این گزینه‌ها برای مدیر فنی هستند. ساختار JSON را معتبر نگه دارید؛ خطای JSON باعث ذخیره نشدن همان بخش می‌شود.</p></div></div><div class="meydan-advanced-grid">';
        foreach (self::JSON_SECTIONS as $key => [$label, $description, $rows]) {
            $value = wp_json_encode(get_option('meydan_' . $key, []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            echo '<details class="meydan-advanced-card"><summary><span>' . esc_html($label) . '</span><small>' . esc_html($description) . '</small></summary><label class="meydan-field meydan-json-field"><span class="meydan-label">JSON</span><textarea class="large-text code" rows="' . (int) $rows . '" name="meydan_' . esc_attr($key) . '_json" spellcheck="false">' . esc_textarea((string) $value) . '</textarea></label></details>';
        }
        echo '</div></section>';
        echo '<div class="meydan-form-actions"><button type="submit" class="button button-primary button-hero">ذخیره همه تنظیمات</button><span>تغییرات با ثبت فرم در audit log ثبت می‌شوند.</span></div></form></div>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $sms = (array) get_option('meydan_sms_settings', []);
        $sms['enabled'] = !empty($_POST['sms_enabled']);
        $sms['endpoint'] = esc_url_raw((string) wp_unslash($_POST['sms_endpoint'] ?? '')) ?: 'https://edge.ippanel.com/v1/api/send';
        $sms['from_number'] = sanitize_text_field(wp_unslash($_POST['sms_from_number'] ?? ''));
        $sms['pattern_code'] = sanitize_text_field(wp_unslash($_POST['sms_pattern_code'] ?? ''));
        if (!empty($_POST['sms_clear_token'])) {
            unset($sms['token']);
        } elseif (isset($_POST['sms_token']) && trim((string) wp_unslash($_POST['sms_token'])) !== '') {
            $sms['token'] = sanitize_text_field(wp_unslash($_POST['sms_token']));
        }
        update_option('meydan_sms_settings', $sms, false);

        $api = (array) get_option('meydan_api_settings', []);
        $api['allowed_origins'] = array_values(array_filter(array_map(static fn($origin): string => esc_url_raw(trim((string) $origin)), preg_split('/\R/', (string) wp_unslash($_POST['api_allowed_origins'] ?? '')) ?: [])));
        update_option('meydan_api_settings', $api, false);

        $saved = [];
        foreach (array_keys(self::JSON_SECTIONS) as $key) {
            $field = 'meydan_' . $key . '_json';
            if (!isset($_POST[$field])) {
                continue;
            }
            $value = json_decode(wp_unslash((string) $_POST[$field]), true);
            if (is_array($value)) {
                update_option('meydan_' . $key, $value, false);
                $saved[] = $key;
            } else {
                add_settings_error('meydan', 'invalid_json_' . $key, sprintf('بخش «%s» ذخیره نشد؛ JSON معتبر نیست.', $key), 'error');
            }
        }
        AuditLogger::log('settings_updated', 'settings', null, null, ['keys' => array_merge(['sms_settings', 'api_settings'], $saved)]);
        add_settings_error('meydan', 'settings_saved', 'تنظیمات میدان با موفقیت ذخیره شد.', 'updated');
    }

    private static function text(string $name, string $label, string $value, string $help, string $type): void
    {
        echo '<label class="meydan-field"><span class="meydan-label">' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" autocomplete="off"><small>' . esc_html($help) . '</small></label>';
    }

    private static function secret(string $name, string $label, bool $configured, string $help): void
    {
        echo '<label class="meydan-field"><span class="meydan-label">' . esc_html($label) . ($configured ? ' <em class="meydan-configured">تنظیم شده</em>' : '') . '</span><input type="password" name="' . esc_attr($name) . '" value="" autocomplete="new-password" placeholder="برای حفظ مقدار فعلی خالی بگذارید"><small>' . esc_html($help) . '</small></label>';
    }

    private static function textarea(string $name, string $label, string $value, string $help): void
    {
        echo '<label class="meydan-field meydan-span-2"><span class="meydan-label">' . esc_html($label) . '</span><textarea name="' . esc_attr($name) . '" rows="4">' . esc_textarea($value) . '</textarea><small>' . esc_html($help) . '</small></label>';
    }

    private static function toggle(string $name, string $label, bool $checked, string $help): void
    {
        echo '<label class="meydan-toggle"><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '><span class="meydan-toggle-track" aria-hidden="true"></span><span><strong>' . esc_html($label) . '</strong><small>' . esc_html($help) . '</small></span></label>';
    }
}
