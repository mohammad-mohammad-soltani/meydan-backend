<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Integrations\Bale\Settings;
use Meydan\Core\Notifications\NotificationService;

final class SettingsPage
{
    private const JSON_SECTIONS = [
        'feature_flags' => ['پرچم‌های قابلیت', 'فعال/غیرفعال کردن قابلیت‌های نسخه فعلی. هر کلید باید مقدار true یا false داشته باشد.', 7],
        'quick_actions' => ['اقدام‌های سریع', 'تنظیمات اقدام‌های سریع قابل نمایش در کلاینت و پنل.', 7],
        'ranking' => ['رتبه‌بندی Timeline', 'وزن‌ها و قواعد رتبه‌بندی محتوا. فقط در صورت آشنایی با مدل امتیازدهی ویرایش کنید.', 9],
        'timeline' => ['Timeline', 'تنظیمات صفحه Timeline مانند اندازه صفحه، تنوع و کش.', 9],
        'trends' => ['روندها و ترندها', 'تنظیمات محاسبه موضوعات محبوب و بازه زمانی آن‌ها.', 8],
    ];

    private const NOTIFICATION_LABELS = [
        'like' => 'پسند روایت',
        'repost' => 'بازنشر روایت',
        'quote' => 'نقل‌قول روایت',
        'follow' => 'دنبال‌کردن',
        'comment' => 'نظر جدید',
        'comment_reply' => 'پاسخ به نظر',
        'mention' => 'اشاره به کاربر',
        'comment_mention' => 'اشاره در نظر',
        'profile_post' => 'روایت جدید از نمایه‌ای که اعلانش روشن است',
        'initiative_join' => 'پیوستن به کار',
        'work_message_updated' => 'به‌روزرسانی پیام کار',
        'work_task_created' => 'وظیفه جدید در کار',
        'work_task_assigned' => 'مسئولیت وظیفه',
        'work_task_status' => 'تغییر وضعیت وظیفه',
        'work_task_reminder' => 'یادآوری وظیفه',
        'work_meeting_created' => 'جلسه جدید در کار',
        'work_announcement' => 'اعلان در کار',
        'work_announcement_seen' => 'دیده‌شدن اعلان',
        'work_announcement_reminder' => 'یادآوری اعلان',
        'work_poll_created' => 'نظرسنجی جدید در کار',
        'work_mention' => 'اشاره در کار',
        'work_member_joined' => 'عضو جدید در کار',
        'work_role_changed' => 'تغییر نقش در کار',
        'initiative_update' => 'به‌روزرسانی کار',
        'initiative_join_confirmed' => 'تأیید عضویت در کار',
        'media_reflection_added' => 'بازتاب رسانه‌ای',
        'square_verified' => 'تأیید میدان',
        'square_rejected' => 'رد میدان',
        'speaker_request_created' => 'ثبت درخواست سخنران',
        'speaker_request_status_changed' => 'تغییر وضعیت درخواست سخنران',
        'speaker_invitation' => 'دعوت سخنرانی',
        'speaker_invitation_accepted' => 'پذیرش دعوت سخنرانی',
        'speaker_invitation_rejected' => 'رد دعوت سخنرانی',
        'admin_notice' => 'پیام مدیریت',
        'system' => 'اعلان سیستم',
        'content_published' => 'انتشار محتوا',
    ];

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه دسترسی به تنظیمات میدان را ندارید.', 'meydan-core'));
        }

        $sms = (array) get_option('meydan_sms_settings', []);
        $smsEndpoint = (string) ($sms['endpoint'] ?? '');
        if ($smsEndpoint === '' || $smsEndpoint === 'https://edge.ippanel.com/v1/api/send') {
            $smsEndpoint = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
        }
        echo '<div class="wrap meydan-admin meydan-settings" dir="rtl">';
        echo '<div class="meydan-page-header"><div><span class="meydan-eyebrow">مرکز کنترل</span><h1>تنظیمات میدان</h1><p>تنظیمات اتصال، تجربه کاربری و رفتار API را از یک محل مدیریت کنید. هر بخش توضیح کوتاه و راهنمای ویرایش دارد.</p></div><div class="meydan-header-mark" aria-hidden="true">M</div></div>';
        settings_errors('meydan');
        echo '<form method="post" enctype="multipart/form-data" class="meydan-settings-form">';
        wp_nonce_field('meydan_admin_action');
        echo '<input type="hidden" name="meydan_admin_action" value="settings_save">';

        echo '<section class="meydan-panel meydan-panel-accent"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">اتصال پیامک</span><h2>ارائه‌دهنده OTP</h2><p>برای ورود بدون رمز عبور، کد یک‌بارمصرف از طریق ایران‌پیامک (FarazSMS) و الگوی ثبت‌شده ارسال می‌شود. توکن به‌صورت ماسک‌شده نگهداری می‌شود و در صفحه دوباره نمایش داده نمی‌شود.</p></div><span class="meydan-status-dot">امن</span></div><div class="meydan-form-grid">';
        self::toggle('sms_enabled', 'فعال‌سازی ارسال پیامک', !isset($sms['enabled']) || (bool) $sms['enabled'], 'در محیط local اگر کد توسعه تعریف شده باشد، ارسال واقعی انجام نمی‌شود.');
        self::text('sms_endpoint', 'آدرس API', $smsEndpoint, 'آدرس رسمی ارسال پیامک الگویی ایران‌پیامک؛ فقط در صورت نیاز به proxy تغییر دهید.', 'url');
        self::text('sms_token', 'API Key', (string) ($sms['token'] ?? ''), 'کلید API به‌صورت کامل نمایش داده می‌شود؛ برای پاک کردن، گزینه پاک‌سازی را فعال کنید.', 'text');
        self::text('sms_from_number', 'شماره خط فرستنده', (string) ($sms['from_number'] ?? ''), 'شماره خط اختصاصی ایران‌پیامک که در درخواست با line_number ارسال می‌شود.', 'text');
        self::text('sms_pattern_code', 'کد الگوی پیامک', (string) ($sms['pattern_code'] ?? ''), 'شناسه الگو را از پنل ایران‌پیامک دریافت کنید؛ متغیر الگوی شما باید code باشد تا مقدار OTP با attributes.code ارسال شود.', 'text');
        self::text('sms_timeout', 'مهلت انتظار پاسخ (ثانیه)', (string) ($sms['timeout'] ?? 60), 'اگر ایران‌پیامک پاسخ را با تأخیر بدهد، مقدار پیش‌فرض ۶۰ ثانیه مانع خطای «ارتباط ناموفق» می‌شود در حالی که پیامک ارسال شده است.', 'number');
        self::toggle('sms_clear_token', 'پاک‌سازی توکن ذخیره‌شده', false, 'برای حذف توکن فعلی؛ این گزینه بعد از ذخیره دوباره خاموش می‌شود.');
        echo '</div><p class="meydan-help"><strong>قرارداد ارسال:</strong> درخواست POST با هدرهای <code>Api-Key</code> و <code>Accept: application/json</code> به endpoint رسمی ارسال می‌شود. بدنه شامل <code>code</code> شناسه الگو، <code>attributes.code</code> مقدار OTP، <code>recipient</code>، <code>line_number</code> و <code>number_format=english</code> است.</p>';
        echo '<div class="meydan-sms-test"><div><strong>تست واقعی اتصال و الگو</strong><p>پس از ذخیره تنظیمات، یک شماره واقعی را وارد کنید. این دکمه حتی در محیط local مستقیماً با ایران‌پیامک تماس می‌گیرد و فقط پس از پذیرش درخواست توسط ارائه‌دهنده پیام موفقیت نشان می‌دهد.</p></div><div class="meydan-sms-test-form"><label class="meydan-field"><span class="meydan-label">شماره گیرنده تست</span><input type="tel" name="sms_test_phone" inputmode="tel" placeholder="09120000000" autocomplete="tel"><small>شماره با فرمت 09 یا +98 وارد شود.</small></label><button type="submit" name="meydan_admin_action" value="sms_test" class="button button-secondary">ارسال پیامک تست واقعی</button></div></div></section>';

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">رفتار عمومی</span><h2>تنظیمات API</h2><p>این بخش روی کلاینت‌های متصل به بک‌اند اثر می‌گذارد. Originها را بدون مسیر و هرکدام در یک خط وارد کنید.</p></div></div>';
        self::textarea('api_allowed_origins', 'Originهای مجاز CORS', implode("\n", array_map('strval', (array) (($data = (array) get_option('meydan_api_settings', []))['allowed_origins'] ?? []))), 'مثال: https://app.example.com');
        echo '</section>';

        self::renderBale();
        self::renderEitaa();
        self::renderNotificationTemplates();

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">پیشرفته</span><h2>تنظیمات تخصصی</h2><p>این گزینه‌ها برای مدیر فنی هستند. ساختار JSON را معتبر نگه دارید؛ خطای JSON باعث ذخیره نشدن همان بخش می‌شود. قالب اعلان‌ها از بخش اختصاصی بالا مدیریت می‌شوند.</p></div></div><div class="meydan-advanced-grid">';
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
        $sms['endpoint'] = esc_url_raw((string) wp_unslash($_POST['sms_endpoint'] ?? '')) ?: 'https://api.iranpayamak.com/ws/v1/sms/pattern';
        $sms['from_number'] = sanitize_text_field(wp_unslash($_POST['sms_from_number'] ?? ''));
        $sms['pattern_code'] = sanitize_text_field(wp_unslash($_POST['sms_pattern_code'] ?? ''));
        $sms['timeout'] = max(5, min(180, (int) ($_POST['sms_timeout'] ?? 60)));
        if (!empty($_POST['sms_clear_token'])) {
            unset($sms['token']);
        } elseif (isset($_POST['sms_token']) && trim((string) wp_unslash($_POST['sms_token'])) !== '') {
            $sms['token'] = sanitize_text_field(wp_unslash($_POST['sms_token']));
        }
        update_option('meydan_sms_settings', $sms, false);

        $api = (array) get_option('meydan_api_settings', []);
        $api['allowed_origins'] = array_values(array_filter(array_map(static fn($origin): string => esc_url_raw(trim((string) $origin)), preg_split('/\R/', (string) wp_unslash($_POST['api_allowed_origins'] ?? '')) ?: [])));
        update_option('meydan_api_settings', $api, false);

        if (isset($_POST['bale_chat_id']) || isset($_POST['bale_token']) || isset($_POST['bale_enabled']) || isset($_POST['bale_webhook_secret'])) {
            $raw = [];
            foreach ([
                'enabled', 'token', 'chat_id', 'base_url', 'timeout', 'clear_token',
                'error_reporting', 'report_fatals', 'report_warnings', 'report_notices',
                'report_eitaa', 'pending_squares', 'rate_limit', 'throttle_window',
                'include_site_label', 'webhook_secret',
            ] as $field) {
                $raw[$field] = wp_unslash($_POST['bale_' . $field] ?? '');
            }

            $result = Settings::sanitize($raw);
            foreach ($result['errors'] as $message) {
                add_settings_error('meydan', 'bale_invalid_' . md5($message), $message, 'error');
            }
            if ($result['values']) {
                Settings::update($result['values']);
            }
        }

        if (isset($_POST['eitaa_sync_secret']) || isset($_POST['eitaa_service_url'])) {
            update_option('meydan_eitaa_service_url', esc_url_raw(trim((string) wp_unslash($_POST['eitaa_service_url'] ?? ''))), false);
            if (!empty($_POST['eitaa_clear_secret'])) {
                update_option('meydan_eitaa_sync_secret', '', false);
            } else {
                $secret = trim((string) wp_unslash($_POST['eitaa_sync_secret'] ?? ''));
                if ($secret !== '') {
                    update_option('meydan_eitaa_sync_secret', $secret, false);
                }
            }
        }

        self::saveNotificationTemplates();

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
        AuditLogger::log('settings_updated', 'settings', null, null, ['keys' => array_merge(['sms_settings', 'api_settings', 'bale_settings', 'notification_templates'], $saved)]);
        add_settings_error('meydan', 'settings_saved', 'تنظیمات میدان با موفقیت ذخیره شد.', 'updated');
    }

    private static function renderNotificationTemplates(): void
    {
        $overrides = (array) get_option('meydan_notification_templates', []);

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">مرکز اعلان‌ها</span><h2>قالب اعلان‌ها</h2><p>عنوان، متن و آیکن هر اعلان را بدون ویرایش JSON مدیریت کنید. برای اعلان‌های کاربرمحور مثل لایک، نظر و دعوت سخنران، آواتار کاربر اولویت دارد و فایل آپلودشده فقط fallback است.</p></div></div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">';
        foreach (NotificationService::TEMPLATES as $type => $defaults) {
            $custom = (array) ($overrides[$type] ?? []);
            $title = (string) ($custom['title'] ?? $defaults['title']);
            $body = (string) ($custom['body'] ?? $defaults['body']);
            $iconMediaId = (int) ($custom['icon_media_id'] ?? 0);
            $iconUrl = $iconMediaId > 0 ? wp_get_attachment_image_url($iconMediaId, 'thumbnail') : false;
            if (!$iconUrl && $iconMediaId > 0) {
                $iconUrl = wp_get_attachment_url($iconMediaId);
            }
            $label = self::NOTIFICATION_LABELS[$type] ?? $type;

            echo '<div class="meydan-advanced-card" style="padding:16px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px"><div><strong style="display:block">' . esc_html($label) . '</strong><code>' . esc_html($type) . '</code></div>';
            if ($iconUrl) {
                echo '<img src="' . esc_url((string) $iconUrl) . '" alt="" style="width:52px;height:52px;border-radius:14px;object-fit:cover">';
            }
            echo '</div>';
            echo '<label class="meydan-field"><span class="meydan-label">عنوان</span><input type="text" name="meydan_notification_templates[' . esc_attr($type) . '][title]" value="' . esc_attr($title) . '"></label>';
            echo '<label class="meydan-field" style="margin-top:10px"><span class="meydan-label">متن</span><textarea rows="3" name="meydan_notification_templates[' . esc_attr($type) . '][body]">' . esc_textarea($body) . '</textarea><small>برای نام کاربر می‌توانید از <code>{actor}</code> استفاده کنید.</small></label>';
            echo '<label class="meydan-field" style="margin-top:10px"><span class="meydan-label">آیکن اعلان</span><input type="file" name="meydan_notification_icon_' . esc_attr($type) . '" accept="image/*"><small>اگر اعلان actor داشته باشد، مثل سخنران، لایک یا نظر، آواتار همان actor نمایش داده می‌شود و این فایل fallback است.</small></label>';
            if ($iconMediaId > 0) {
                echo '<label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="meydan_notification_icon_remove[' . esc_attr($type) . ']" value="1"> حذف آیکن فعلی</label>';
            }
            echo '</div>';
        }
        echo '</div></section>';
    }

    private static function saveNotificationTemplates(): void
    {
        $posted = isset($_POST['meydan_notification_templates'])
            ? (array) wp_unslash($_POST['meydan_notification_templates'])
            : [];
        $templates = (array) get_option('meydan_notification_templates', []);
        $remove = isset($_POST['meydan_notification_icon_remove'])
            ? (array) wp_unslash($_POST['meydan_notification_icon_remove'])
            : [];

        foreach (NotificationService::TEMPLATES as $type => $defaults) {
            $row = (array) ($posted[$type] ?? []);
            $current = (array) ($templates[$type] ?? []);
            $current['title'] = sanitize_text_field((string) ($row['title'] ?? $current['title'] ?? $defaults['title']));
            $current['body'] = sanitize_textarea_field((string) ($row['body'] ?? $current['body'] ?? $defaults['body']));

            if (!empty($remove[$type])) {
                unset($current['icon_media_id']);
            }

            $fileField = 'meydan_notification_icon_' . $type;
            $uploadError = isset($_FILES[$fileField]['error']) ? (int) $_FILES[$fileField]['error'] : UPLOAD_ERR_NO_FILE;
            if ($uploadError === UPLOAD_ERR_OK) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attachmentId = media_handle_upload($fileField, 0);
                if (is_wp_error($attachmentId)) {
                    add_settings_error('meydan', 'notification_icon_' . $type, sprintf('آیکن اعلان «%s» ذخیره نشد: %s', self::NOTIFICATION_LABELS[$type] ?? $type, $attachmentId->get_error_message()), 'error');
                } else {
                    $current['icon_media_id'] = (int) $attachmentId;
                }
            } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
                add_settings_error('meydan', 'notification_icon_upload_' . $type, sprintf('آپلود آیکن اعلان «%s» با خطا مواجه شد.', self::NOTIFICATION_LABELS[$type] ?? $type), 'error');
            }

            $templates[$type] = $current;
        }

        update_option('meydan_notification_templates', $templates, false);
    }

    /** Renders the Bale bot configuration panel. */
    private static function renderBale(): void
    {
        $bale = Settings::get();
        $configured = Settings::isReady();

        echo '<section class="meydan-panel meydan-panel-accent"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">ربات بله</span><h2>پیکربندی ربات بله</h2><p>همه گزینه‌های ربات از همین صفحه تنظیم می‌شود؛ نیازی به ویرایش فایل یا تنظیم متغیر محیطی نیست. ربات از چت مقصد برای اطلاع‌رسانی خطاهای پروژه، خطاهای همگام‌سازی ایتا و اعلان میدان‌های در انتظار تأیید استفاده می‌کند.</p></div><span class="meydan-status-dot">' . ($configured ? 'فعال' : 'غیرفعال') . '</span></div>';

        echo '<h3 class="meydan-section-kicker" style="margin:18px 0 6px">اتصال</h3><div class="meydan-form-grid">';
        self::toggle('bale_enabled', 'فعال‌سازی اعلان‌های بله', $bale['enabled'], 'با خاموش کردن این گزینه هیچ پیامی به بله ارسال نمی‌شود، هرچند توکن ذخیره بماند.');
        $hasToken = $bale['token'] !== '';
        self::secret('bale_token', 'توکن ربات بله', $hasToken, 'توکن را از BotFather بله بگیرید؛ قالب آن شبیه 123456789:ABCdef... است. برای حفظ توکن فعلی خالی بگذارید.');
        self::text('bale_chat_id', 'شناسه چت مقصد (chat_id)', $bale['chat_id'], 'شناسه عددی گروه یا کانال مقصد، مانند -1001234567890. ربات باید عضو آن گروه/کانال باشد.', 'text');
        self::text('bale_base_url', 'آدرس پایه API بله', $bale['base_url'], 'پیش‌فرض https://tapi.bale.ai است؛ فقط در صورت استفاده از پروکسی تغییر دهید.', 'url');
        self::text('bale_timeout', 'مهلت انتظار پاسخ (ثانیه)', (string) $bale['timeout'], 'بین ۵ تا ۶۰ ثانیه. برای شبکه‌های کند مقدار بیشتری بگذارید.', 'number');
        self::toggle('bale_clear_token', 'پاک‌سازی توکن ذخیره‌شده', false, 'برای حذف توکن فعلی؛ بعد از ذخیره خودش خاموش می‌شود.');
        echo '</div>';

        echo '<h3 class="meydan-section-kicker" style="margin:22px 0 6px">مواردی که گزارش می‌شود</h3><div class="meydan-form-grid">';
        self::toggle('bale_error_reporting', 'گزارش خطاهای پروژه', $bale['error_reporting'], 'کلید اصلی گزارش خطا. با خاموش کردن آن، هیچ خطای PHP یا استثنایی ارسال نمی‌شود.');
        self::toggle('bale_report_fatals', 'خطاهای مرگبار و استثناها', $bale['report_fatals'], 'خطاهایی که اجرای برنامه را متوقف می‌کنند، همراه با فایل و شماره خط.');
        self::toggle('bale_report_warnings', 'هشدارها (Warnings)', $bale['report_warnings'], 'هشدارهای PHP. برای پیگیری مشکلات پنهان مفید است ولی حجم پیام را بیشتر می‌کند.');
        self::toggle('bale_report_notices', 'نوتیس‌ها و Deprecated', $bale['report_notices'], 'معمولاً پرحجم و کم‌اهمیت؛ فقط برای عیب‌یابی موقت روشن کنید.');
        self::toggle('bale_report_eitaa', 'خطاهای همگام‌سازی ایتا', $bale['report_eitaa'], 'هر خطای سرویس ایتا یا ورود محتوا، همراه با متد، مسیر و کد HTTP.');
        self::toggle('bale_pending_squares', 'اعلان میدان‌های در انتظار تأیید', $bale['pending_squares'], 'لینک میدان همراه دو دکمه «تأیید» و «لغو» به چت ارسال می‌شود.');
        self::toggle('bale_include_site_label', 'درج نام سایت در پیام‌ها', $bale['include_site_label'], 'برای وقتی چند محیط (تست/اصلی) به یک چت گزارش می‌دهند، مفید است.');
        echo '</div>';

        echo '<h3 class="meydan-section-kicker" style="margin:22px 0 6px">کنترل حجم پیام</h3><div class="meydan-form-grid">';
        self::text('bale_rate_limit', 'حداکثر پیام در دقیقه', (string) $bale['rate_limit'], 'سقف ارسال برای جلوگیری از سرریز چت هنگام بروز خطای تکرارشونده. بین ۱ تا ۱۲۰.', 'number');
        self::text('bale_throttle_window', 'پنجره تکرارنشدن پیام یکسان (ثانیه)', (string) $bale['throttle_window'], 'اگر خطای یکسانی دوباره رخ دهد، تا این مدت دوباره ارسال نمی‌شود. بین ۳۰ تا ۳۶۰۰ ثانیه.', 'number');
        echo '</div>';

        echo '<h3 class="meydan-section-kicker" style="margin:22px 0 6px">وبهوک دکمه‌های تأیید</h3>';
        self::text('bale_webhook_secret', 'کلید امنیتی وبهوک', $bale['webhook_secret'], 'بله این کلید را در هر درخواست برمی‌گرداند تا درخواست‌های جعلی رد شوند. اگر خالی باشد هنگام اولین استفاده ساخته می‌شود.', 'text');
        echo '<p class="meydan-help">با تغییر این کلید، باید وبهوک را دوباره ثبت کنید؛ دکمه «تولید کلید امنیتی جدید» خودش این کار را انجام می‌دهد.</p>';
        echo '<p class="meydan-help"><strong>نکته امنیتی:</strong> برای کار کردن دکمه‌های تأیید و لغو، بله باید بتواند به این سایت درخواست بدهد؛ پس آدرس وبهوک باید روی دامنه عمومی و با HTTPS باشد. روی محیط local این قابلیت کار نمی‌کند (بقیه اعلان‌ها کار می‌کنند).</p>';
        echo '<div class="meydan-sms-test"><div><strong>مدیریت وبهوک و تست اتصال</strong><p>ابتدا تنظیمات را ذخیره کنید، سپس یکی از دکمه‌های زیر را بزنید.</p><div class="meydan-sms-test-form"><button type="submit" name="meydan_admin_action" value="bale_test" class="button button-secondary">ثبت وبهوک و ارسال پیام تست</button><button type="submit" name="meydan_admin_action" value="bale_webhook_info" class="button">مشاهده وضعیت وبهوک</button><button type="submit" name="meydan_admin_action" value="bale_webhook_remove" class="button button-link-delete">حذف وبهوک</button><button type="submit" name="meydan_admin_action" value="bale_rotate_secret" class="button">تولید کلید امنیتی جدید</button></div></div>';
        if ($configured) {
            echo '<p class="meydan-help">آدرس وبهوک این سایت: <code>' . esc_html(self::webhookUrl()) . '</code></p>';
        } else {
            echo '<p class="meydan-help">آدرس وبهوک این سایت (پس از تکمیل توکن و شناسه چت فعال می‌شود): <code>' . esc_html(self::webhookUrl()) . '</code></p>';
        }
        echo '</section>';
    }

    public static function webhookUrl(): string
    {
        return rest_url('meydan/v1/integrations/bale/webhook');
    }

    /** Eitaa sync connection, editable here instead of only via constants. */
    private static function renderEitaa(): void
    {
        $secret = (string) get_option('meydan_eitaa_sync_secret', '');
        $serviceUrl = (string) get_option('meydan_eitaa_service_url', '');
        if ($serviceUrl === '') {
            $serviceUrl = defined('EITAA_SERVICE_URL') ? (string) constant('EITAA_SERVICE_URL') : (string) getenv('EITAA_SERVICE_URL');
        }
        $secretSet = $secret !== '' || trim((string) \Meydan\Core\Integrations\Eitaa\Auth::secret()) !== '';

        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">همگام‌سازی ایتا</span><h2>اتصال سرویس ایتا</h2><p>کلید مشترک بین این سایت و سرویس EitaaUserBot. این مقدار باید در هر دو طرف یکی باشد؛ در غیر این صورت درخواست‌های همگام‌سازی با خطای امضا رد می‌شوند.</p></div><span class="meydan-status-dot">' . ($secretSet ? 'تنظیم شده' : 'تنظیم نشده') . '</span></div><div class="meydan-form-grid">';
        self::text('eitaa_service_url', 'آدرس سرویس ایتا', $serviceUrl, 'آدرس داخلی سرویس EitaaUserBot، مثلاً http://eitaa-api:3000.', 'url');
        self::secret('eitaa_sync_secret', 'کلید همگام‌سازی (Sync Secret)', \Meydan\Core\Integrations\Eitaa\Auth::secret() !== '', 'باید با کلید سرویس EitaaUserBot یکسان باشد. برای حفظ مقدار فعلی خالی بگذارید.');
        self::toggle('eitaa_clear_secret', 'پاک‌سازی کلید ذخیره‌شده', false, 'برای حذف کلید فعلی؛ بعد از ذخیره خودش خاموش می‌شود.');
        echo '</div><p class="meydan-help">اگر این دو فیلد خالی بمانند، مقادیر <code>MEYDAN_EITAA_SYNC_SECRET</code> و <code>EITAA_SERVICE_URL</code> از تنظیمات محیطی خوانده می‌شوند. نقاط ورود همگام‌سازی زیر مسیر <code>/wp-json/meydan/v1/integrations/eitaa/</code> هستند.</p></section>';
    }

    private static function text(string $name, string $label, string $value, string $help, string $type): void
    {
        echo '<label class="meydan-field"><span class="meydan-label">' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" autocomplete="off"><small>' . esc_html($help) . '</small></label>';
    }

    private static function select(string $name, string $label, string $value, array $options, string $help): void
    {
        echo '<label class="meydan-field"><span class="meydan-label">' . esc_html($label) . '</span><select name="' . esc_attr($name) . '">';
        foreach ($options as $optionValue => $optionLabel) {
            echo '<option value="' . esc_attr((string) $optionValue) . '" ' . selected($value, (string) $optionValue, false) . '>' . esc_html((string) $optionLabel) . '</option>';
        }
        echo '</select><small>' . esc_html($help) . '</small></label>';
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
