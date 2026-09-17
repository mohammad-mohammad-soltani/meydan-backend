<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Integrations\Channels\Channels;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\UserEmails;

/**
 * Creates a square from the panel in one place: the whole flow is the same
 * shape as the public /auth/register/square path, but an operator fills every
 * field instead of the registrant.
 *
 * The result is always the same pair of objects every other part of the system
 * already expects: a `meydan_square` account (the owner) plus the
 * `meydan_square` post it owns, with the geo row and channel bindings attached.
 * Because the phone is stored exactly like OTP registration stores it, the
 * owner can immediately sign in to the app with an OTP.
 */
final class ManualSquare
{
    public const PAGE = 'meydan-square-new';
    public const ACTION = 'manual_square_create';
    public const FORM = 'meydan_manual_square';
    public const NONCE = 'meydan_manual_square_nonce';

    private const NOTICE_KEY = 'meydan_manual_square_notice';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'meydan_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_media();
        wp_register_style('meydan-manual-square', false);
        wp_enqueue_style('meydan-manual-square');
        wp_add_inline_style('meydan-manual-square', '.meydan-manual-square .meydan-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.meydan-manual-square .meydan-span-2{grid-column:1/-1}.meydan-manual-square .meydan-field input,.meydan-manual-square .meydan-field select,.meydan-manual-square .meydan-field textarea{width:100%}.meydan-manual-square .meydan-avatar{display:flex;align-items:center;gap:16px}.meydan-manual-square .meydan-avatar-preview{width:96px;height:96px;flex:0 0 96px;border-radius:50%;overflow:hidden;background:#f0f0f1;display:grid;place-items:center}.meydan-manual-square .meydan-avatar-preview img{width:96px;height:96px;object-fit:cover}.meydan-manual-square #meydan-manual-square-map{height:320px;border:1px solid #cbd5e1;border-radius:12px}.meydan-manual-square .meydan-result{margin:0 0 18px;padding:14px 18px;border:1px solid #99c7c1;border-radius:12px;background:#f0fdfa}.meydan-manual-square .meydan-result code{font-size:13px}@media(max-width:782px){.meydan-manual-square .meydan-grid{grid-template-columns:1fr}}');
        wp_add_inline_script('media-editor', <<<'JS'
jQuery(function($){
  var id = $('#meydan-manual-avatar-id'), preview = $('#meydan-manual-avatar-preview'), frame;
  $('#meydan-manual-avatar-select').on('click', function(e){
    e.preventDefault();
    frame = wp.media({title:'انتخاب آواتار میدان', button:{text:'استفاده از این تصویر'}, library:{type:'image'}, multiple:false});
    frame.on('select', function(){
      var image = frame.state().get('selection').first().toJSON();
      var src = (image.sizes && image.sizes.thumbnail && image.sizes.thumbnail.url) || image.url;
      id.val(image.id);
      preview.html($('<img>', {src:src, alt:''}));
      $('#meydan-manual-avatar-remove').prop('disabled', false);
    });
    frame.open();
  });
  $('#meydan-manual-avatar-remove').on('click', function(e){
    e.preventDefault();
    id.val('0');
    preview.html('<span class="description">بدون آواتار</span>');
    $(this).prop('disabled', true);
  });
});
JS
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_meydan_squares')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'meydan-core'));
        }

        $notice = get_transient(self::NOTICE_KEY . '_' . get_current_user_id());
        delete_transient(self::NOTICE_KEY . '_' . get_current_user_id());

        echo '<div class="wrap meydan-admin meydan-manual-square">';
        echo '<div class="meydan-page-header"><div><span class="meydan-eyebrow">ساخت دستی</span><h1>افزودن میدان و کاربر</h1>';
        echo '<p>با ثبت این فرم، هم حساب کاربری میدان ساخته می‌شود و هم شیء میدان با موقعیت، آواتار و کانال‌ها. شماره موبایل همان‌طور ذخیره می‌شود که ثبت‌نام عمومی ذخیره می‌کند، پس مالک می‌تواند بلافاصله با کد یک‌بارمصرف وارد اپ شود.</p></div>';
        echo '<div class="meydan-header-mark">م</div></div>';

        if (is_array($notice)) {
            $type = (string) ($notice['type'] ?? 'success');
            echo '<div class="notice notice-' . esc_attr($type === 'error' ? 'error' : 'success') . '"><p>' . wp_kses_post((string) ($notice['message'] ?? '')) . '</p></div>';
        }

        $provinces = self::provinces();
        if (!$provinces) {
            echo '<div class="notice notice-warning"><p>هنوز استان و شهری ثبت نشده است. ابتدا از صفحه «استان‌ها و شهرها» فهرست ایران را همگام‌سازی کنید.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="meydan-panel meydan-panel-accent">';
        wp_nonce_field(self::FORM, self::NONCE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo Channels::formNonce();
        echo '<div class="meydan-panel-heading"><div><span class="meydan-section-kicker">حساب کاربری میدان</span><h2>اطلاعات تماس و هویت</h2>';
        echo '<p>شماره موبایل شناسه ورود مالک است. اگر همین شماره قبلاً ثبت شده باشد، فرم اجازه ساخت حساب تکراری نمی‌دهد.</p></div></div>';

        echo '<div class="meydan-grid">';
        self::field('phone', 'شماره موبایل', '', 'tel', '۰۹۱۲۳۴۵۶۷۸۹ یا +98912...', true);
        self::field('full_name', 'نام و نام خانوادگی مالک', '', 'text', 'مثلاً «محمد محمد سلطانی»');
        self::field('email', 'ایمیل وردپرس', '', 'email', 'اختیاری؛ خالی بماند ایمیل داخلی ساخته می‌شود');
        self::field('contact_name', 'نام رابط میدان', '', 'text', 'اگر با مالک متفاوت است');
        self::field('contact_phone', 'تلفن رابط میدان', '', 'tel', 'اختیاری');
        echo '</div>';

        echo '<div class="meydan-panel-heading" style="margin-top:24px"><div><span class="meydan-section-kicker">شیء میدان</span><h2>نام، توضیح و آواتار</h2>';
        echo '<p>عنوان میدان در API به‌عنوان نام میدان برگردانده می‌شود. آواتار از کتابخانه رسانه انتخاب یا همان‌جا آپلود می‌شود.</p></div></div>';

        echo '<div class="meydan-grid">';
        self::field('square_name', 'نام میدان', '', 'text', 'مثلاً «میدان امام حسین»', true);
        self::field('start_date', 'تاریخ شروع فعالیت', '', 'date', 'برای محاسبه شب‌های فعال');
        echo '<div class="meydan-field meydan-span-2"><label class="meydan-label">توضیح میدان</label>';
        echo '<textarea name="description" rows="4" placeholder="معرفی کوتاه میدان"></textarea></div>';
        echo '<div class="meydan-field meydan-span-2"><span class="meydan-label">آواتار میدان</span>';
        echo '<div class="meydan-avatar"><div class="meydan-avatar-preview" id="meydan-manual-avatar-preview"><span class="description">بدون آواتار</span></div>';
        echo '<div><input type="hidden" name="avatar_media_id" id="meydan-manual-avatar-id" value="0">';
        echo '<button type="button" class="button button-primary" id="meydan-manual-avatar-select">انتخاب یا آپلود تصویر</button> ';
        echo '<button type="button" class="button" id="meydan-manual-avatar-remove" disabled>حذف آواتار</button>';
        echo '<p class="description">در API به شکل <code>avatar_url</code> برمی‌گردد.</p></div></div></div>';
        echo '</div>';

        echo '<div class="meydan-panel-heading" style="margin-top:24px"><div><span class="meydan-section-kicker">موقعیت</span><h2>استان، شهر و نشانی</h2>';
        echo '<p>مختصات را از روی نقشه انتخاب کنید یا دستی وارد کنید؛ همین مقدار در فهرست و نقشه میدان‌ها استفاده می‌شود.</p></div></div>';

        echo '<div class="meydan-grid">';
        echo '<div class="meydan-field"><label class="meydan-label" for="meydan-manual-province">استان</label><select name="province_id" id="meydan-manual-province" data-meydan-picker-province required>';
        echo '<option value="">انتخاب استان</option>';
        foreach ($provinces as $province) {
            echo '<option value="' . esc_attr((string) $province['id']) . '">' . esc_html((string) $province['name']) . '</option>';
        }
        echo '</select><small>با انتخاب استان، نقشه روی مرکز آن فوکوس می‌کند.</small></div>';
        echo '<div class="meydan-field"><label class="meydan-label" for="meydan-manual-city">شهر</label><select name="city_id" id="meydan-manual-city" data-meydan-picker-city required></select>';
        echo '<small>با انتخاب شهر، نقشه روی مرکز آن فوکوس می‌کند.</small></div>';
        echo '<div class="meydan-field meydan-span-2"><label class="meydan-label" for="meydan-manual-address">نشانی</label>';
        echo '<textarea id="meydan-manual-address" name="address" rows="3" required placeholder="با انتخاب نقطه روی نقشه خودکار پر می‌شود و قابل ویرایش است"></textarea>';
        echo '<small>نشانی میدان برای دعوت‌ها و نقشه. با کلیک روی نقشه یا جابه‌جا کردن نشانگر، خودکار پر می‌شود.</small></div>';
        self::field('latitude', 'عرض جغرافیایی', '35.6892', 'text', 'مثلاً 35.6892');
        self::field('longitude', 'طول جغرافیایی', '51.3890', 'text', 'مثلاً 51.3890');
        echo '</div>';

        echo '<div class="meydan-field meydan-span-2" style="margin-top:14px"><span class="meydan-label">انتخاب موقعیت روی نقشه</span>';
        echo '<p class="meydan-map-actions"><button type="button" class="button" data-meydan-picker-lookup>تشخیص نشانی از مختصات</button> ';
        echo '<button type="button" class="button" data-meydan-picker-locate>موقعیت فعلی من</button> ';
        echo '<span data-meydan-picker-status class="description">روی نقشه کلیک کنید یا نشانگر را بکشید؛ نشانی، استان و شهر خودکار پر می‌شود.</span></p>';
        echo '<div id="meydan-manual-square-map" data-meydan-picker data-lat="35.6892" data-lng="51.3890" data-zoom="11"'
            . ' data-lat-field="[name=latitude]" data-lng-field="[name=longitude]" data-address-field="[name=address]"'
            . ' data-province-field="[name=province_id]" data-city-field="[name=city_id]"></div></div>';

        echo '<div class="meydan-panel-heading" style="margin-top:24px"><div><span class="meydan-section-kicker">کانال‌های بیرونی</span><h2>ایتا و بله</h2>';
        echo '<p>کانال ایتا مبنای همگام‌سازی محتوا است. برای بله فعلاً شناسه کانال ذخیره می‌شود. شناسه عددی، @نام‌کاربری یا لینک کانال پذیرفته می‌شود.</p></div></div>';
        echo '<div class="meydan-grid">';
        foreach (['eitaa', 'bale'] as $kind) {
            $definition = Channels::definition($kind);
            echo '<div class="meydan-field"><label class="meydan-label" for="meydan-manual-' . esc_attr($kind) . '">' . esc_html($definition['label']) . '</label>';
            echo '<input id="meydan-manual-' . esc_attr($kind) . '" name="' . esc_attr($definition['field']) . '" type="text" value="" placeholder="' . esc_attr($definition['placeholder']) . '">';
            echo '<small>' . esc_html($definition['description']) . '</small></div>';
        }
        echo '</div>';

        echo '<div class="meydan-panel-heading" style="margin-top:24px"><div><span class="meydan-section-kicker">وضعیت</span><h2>تأیید میدان</h2>';
        echo '<p>میدان تازه به‌صورت پیش‌فرض «در انتظار تأیید» ثبت می‌شود و در فهرست عمومی دیده نمی‌شود.</p></div></div>';
        echo '<label class="meydan-toggle"><input type="checkbox" name="approve" value="1"> همین حالا تأیید و منتشر شود</label>';

        echo '<p style="margin-top:22px">';
        // Rendered by hand: wp-admin only loads template.php (submit_button())
        // from admin-header.php, which runs after this callback.
        echo '<button type="submit" class="button button-primary">' . esc_html__('ساخت کاربر و میدان', 'meydan-core') . '</button>';
        echo ' <a class="button" href="' . esc_url(admin_url('edit.php?post_type=meydan_square')) . '">فهرست میدان‌ها</a>';
        echo '</p>';
        echo '<input type="hidden" id="meydan-manual-cities" value="' . esc_attr(wp_json_encode(self::citiesByProvince(), JSON_UNESCAPED_UNICODE)) . '">';
        echo '</form>';

        self::recentTable();
        echo '</div>';
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_meydan_squares')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'meydan-core'));
        }
        check_admin_referer(self::FORM, self::NONCE);

        $input = self::validate();
        if (is_wp_error($input)) {
            self::flash('error', $input->get_error_message());
            wp_safe_redirect(self::pageUrl());
            exit;
        }

        $result = self::create($input);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
            wp_safe_redirect(self::pageUrl());
            exit;
        }

        $userLink = get_edit_user_link($result['user_id']);
        $squareLink = get_edit_post_link($result['square_id']);
        $message = sprintf(
            'میدان «%s» ساخته شد. حساب مالک <code>#%d</code> و میدان <code>#%d</code> است.',
            esc_html($result['name']),
            $result['user_id'],
            $result['square_id']
        );
        $links = [];
        if ($userLink) {
            $links[] = '<a href="' . esc_url($userLink) . '">ویرایش کاربر</a>';
        }
        if ($squareLink) {
            $links[] = '<a href="' . esc_url($squareLink) . '">ویرایش میدان</a>';
        }
        $links[] = '<a href="' . esc_url(get_permalink($result['square_id']) ?: admin_url('admin.php?page=meydan-square-approvals')) . '">مشاهده در API</a>';
        if ($links) {
            $message .= ' — ' . implode(' | ', $links);
        }
        self::flash('success', $message);

        wp_safe_redirect(self::pageUrl(['meydan_square_created' => $result['square_id']]));
        exit;
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function validate(): array|\WP_Error
    {
        $phone = OtpService::normalizePhone((string) ($_POST['phone'] ?? ''));
        if ($phone === '') {
            return new \WP_Error('meydan_square_phone', 'شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹.');
        }
        if (self::phoneOwner($phone) > 0) {
            return new \WP_Error('meydan_square_phone_taken', 'این شماره موبایل قبلاً روی یک حساب ثبت شده است. برای آن حساب میدان بسازید یا از حساب موجود استفاده کنید.');
        }

        $squareName = sanitize_text_field((string) wp_unslash($_POST['square_name'] ?? ''));
        if ($squareName === '') {
            return new \WP_Error('meydan_square_name', 'نام میدان الزامی است.');
        }

        $provinceId = (int) ($_POST['province_id'] ?? 0);
        $cityId = (int) ($_POST['city_id'] ?? 0);
        if ($provinceId <= 0 || $cityId <= 0 || !self::cityBelongsTo($cityId, $provinceId)) {
            return new \WP_Error('meydan_square_geo', 'استان و شهر را کامل و سازگار انتخاب کنید.');
        }

        $address = sanitize_textarea_field((string) wp_unslash($_POST['address'] ?? ''));
        if ($address === '') {
            return new \WP_Error('meydan_square_address', 'نشانی میدان الزامی است.');
        }

        $latitude = self::coordinate((string) ($_POST['latitude'] ?? ''), 35.6892);
        $longitude = self::coordinate((string) ($_POST['longitude'] ?? ''), 51.3890);
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return new \WP_Error('meydan_square_coordinates', 'عرض یا طول جغرافیایی خارج از محدوده مجاز است.');
        }

        $email = sanitize_email((string) wp_unslash($_POST['email'] ?? ''));
        if ($email !== '' && !is_email($email)) {
            return new \WP_Error('meydan_square_email', 'ایمیل واردشده معتبر نیست.');
        }
        if ($email !== '' && email_exists($email)) {
            return new \WP_Error('meydan_square_email_taken', 'این ایمیل قبلاً روی حساب دیگری ثبت شده است.');
        }

        return [
            'phone' => $phone,
            'square_name' => $squareName,
            'full_name' => sanitize_text_field((string) wp_unslash($_POST['full_name'] ?? '')),
            'email' => $email,
            'contact_name' => sanitize_text_field((string) wp_unslash($_POST['contact_name'] ?? '')),
            'contact_phone' => sanitize_text_field((string) wp_unslash($_POST['contact_phone'] ?? '')),
            'description' => wp_kses_post((string) wp_unslash($_POST['description'] ?? '')),
            'start_date' => sanitize_text_field((string) wp_unslash($_POST['start_date'] ?? '')),
            'avatar_media_id' => max(0, (int) ($_POST['avatar_media_id'] ?? 0)),
            'province_id' => $provinceId,
            'city_id' => $cityId,
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'eitaa_channel' => sanitize_text_field((string) ($_POST['meydan_eitaa_channel'] ?? '')),
            'bale_channel' => sanitize_text_field((string) ($_POST['meydan_bale_channel'] ?? '')),
            'approve' => !empty($_POST['approve']),
        ];
    }

    /** @param array<string,mixed> $input @return array{user_id:int,square_id:int,name:string}|\WP_Error */
    private static function create(array $input): array|\WP_Error
    {
        return \Meydan\Core\Domain\SquareAdminService::create(array_merge($input, [
            'status' => !empty($input['approve']) ? 'approved' : 'pending_verification',
        ]));

    }

    /** Mirrors the public channels onto the square post for the API serializer. */
    private static function linkChannelUrls(int $squareId, int $userId): void
    {
        $summary = Channels::summary($userId);
        if ($summary['eitaa'] !== '') {
            update_post_meta($squareId, 'meydan_eitaa', self::channelUrl('eitaa', $summary['eitaa']));
        }
        if ($summary['bale'] !== '') {
            update_post_meta($squareId, 'meydan_bale', self::channelUrl('bale', $summary['bale']));
        }
    }

    public static function channelUrl(string $kind, string $value): string
    {
        $value = ltrim($value, '@');
        if (preg_match('/^-?\d+$/', $value)) {
            return $kind === 'bale' ? 'https://ble.ir/channel/' . ltrim($value, '-') : 'https://eitaa.com/' . ltrim($value, '-');
        }

        return $kind === 'bale' ? 'https://ble.ir/' . $value : 'https://eitaa.com/' . $value;
    }

    /** @param array<string,mixed> $input */
    private static function saveGeo(int $squareId, array $input): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'meydan_square_geo', [
            'square_id' => $squareId,
            'province_id' => (int) $input['province_id'],
            'city_id' => (int) $input['city_id'],
            'address' => (string) $input['address'],
            'latitude' => (float) $input['latitude'],
            'longitude' => (float) $input['longitude'],
            'updated_at' => current_time('mysql', true),
        ]);
    }

    private static function recentTable(): void
    {
        $squares = get_posts([
            'post_type' => 'meydan_square',
            'post_status' => ['publish', 'pending', 'draft'],
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        if (!$squares) {
            return;
        }
        echo '<div class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">آخرین میدان‌ها</span><h2>تازه‌ترین میدان‌های ثبت‌شده</h2></div></div>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>نام</th><th>مالک</th><th>کانال ایتا</th><th>کانال بله</th><th>وضعیت</th></tr></thead><tbody>';
        foreach ($squares as $square) {
            $ownerId = (int) get_post_meta((int) $square->ID, 'meydan_owner_user_id', true);
            $summary = $ownerId > 0 ? Channels::summary($ownerId) : ['eitaa' => '', 'bale' => ''];
            $user = $ownerId > 0 ? get_userdata($ownerId) : null;
            echo '<tr><td>' . (int) $square->ID . '</td>';
            echo '<td><a href="' . esc_url((string) get_edit_post_link((int) $square->ID)) . '">' . esc_html((string) $square->post_title) . '</a></td>';
            echo '<td>' . ($user ? '<a href="' . esc_url((string) get_edit_user_link($ownerId)) . '">' . esc_html((string) $user->display_name) . '</a>' : '—') . '</td>';
            echo '<td>' . esc_html($summary['eitaa'] !== '' ? $summary['eitaa'] : '—') . '</td>';
            echo '<td>' . esc_html($summary['bale'] !== '' ? $summary['bale'] : '—') . '</td>';
            echo '<td><code>' . esc_html((string) get_post_meta((int) $square->ID, 'meydan_approval_status', true)) . '</code></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function field(string $name, string $label, string $value = '', string $type = 'text', string $description = '', bool $required = false): void
    {
        echo '<div class="meydan-field"><label class="meydan-label" for="meydan-manual-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
        echo '<input id="meydan-manual-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
        if ($description !== '') {
            echo '<small>' . esc_html($description) . '</small>';
        }
        echo '</div>';
    }

    /** @return array<int,array{id:int,name:string}> */
    private static function provinces(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}meydan_provinces WHERE active=1 ORDER BY sort_order,name", ARRAY_A) ?: [];
    }

    /** @return array<int,array<int,array{id:int,name:string}>> */
    private static function citiesByProvince(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,province_id,name FROM {$wpdb->prefix}meydan_cities WHERE active=1 ORDER BY sort_order,name", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['province_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }

    private static function cityBelongsTo(int $cityId, int $provinceId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d AND active=1",
            $cityId,
            $provinceId
        )) > 0;
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

    private static function coordinate(string $value, float $fallback): float
    {
        $value = trim(strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        if ($value === '' || !is_numeric($value)) {
            return $fallback;
        }

        return (float) $value;
    }

    private static function flash(string $type, string $message): void
    {
        set_transient(self::NOTICE_KEY . '_' . get_current_user_id(), ['type' => $type, 'message' => $message], 120);
    }

    /** @param array<string,scalar> $args */
    private static function pageUrl(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }
}
