<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

use Meydan\Core\Audit\AuditLogger;
use WP_Error;

final class GeoManager
{
    private const API_BASE = 'https://iran-locations-api.ir/api/v1/fa';

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما اجازه مدیریت استان‌ها و شهرها را ندارید.', 'meydan-core'));
        }
        global $wpdb;
        $provinces = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_provinces ORDER BY sort_order, name", ARRAY_A) ?: [];
        $cities = $wpdb->get_results("SELECT c.*, p.name AS province_name FROM {$wpdb->prefix}meydan_cities c LEFT JOIN {$wpdb->prefix}meydan_provinces p ON p.id=c.province_id ORDER BY p.name, c.sort_order, c.name LIMIT 1500", ARRAY_A) ?: [];
        $notice = sanitize_key(wp_unslash($_GET['meydan_geo_import'] ?? ''));

        echo '<div class="wrap meydan-admin meydan-geo" dir="rtl">';
        echo '<div class="meydan-page-header"><div><span class="meydan-eyebrow">داده مرجع</span><h1>استان‌ها و شهرها</h1><p>موقعیت‌های جغرافیایی مورد استفاده در ثبت‌نام، پروفایل، میدان‌ها و فیلترهای API را مدیریت کنید.</p></div><div class="meydan-header-mark" aria-hidden="true">⌖</div></div>';
        if ($notice === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>استان‌ها و شهرها با موفقیت از API ایران Locations همگام شدند.</p></div>';
        } elseif ($notice === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p>همگام‌سازی انجام نشد. اتصال API یا پاسخ JSON را بررسی کنید و دوباره تلاش کنید.</p></div>';
        }
        echo '<section class="meydan-geo-toolbar meydan-panel"><div><span class="meydan-section-kicker">همگام‌سازی سریع</span><h2>دریافت آخرین فهرست ایران</h2><p>این عملیات استان‌ها را از <code>iran-locations-api.ir</code> می‌خواند و شهرهای هر استان را با نام یکتا به‌روزرسانی می‌کند. داده‌های دستی حذف نمی‌شوند مگر نام و استان یکسان باشد.</p></div><form method="post">';
        wp_nonce_field('meydan_admin_action');
        echo '<input type="hidden" name="meydan_admin_action" value="geo_import"><button type="submit" class="button button-primary button-hero" onclick="return confirm(\'فهرست فعلی با داده‌های API همگام شود؟\');">↻ همگام‌سازی استان‌ها و شهرها</button></form></section>';
        echo '<div class="meydan-stat-strip"><div><strong>' . esc_html((string) count($provinces)) . '</strong><span>استان ثبت‌شده</span></div><div><strong>' . esc_html((string) count($cities)) . '</strong><span>شهر نمایش‌داده‌شده</span></div><div><strong>31</strong><span>استان مرجع ایران</span></div></div>';
        echo '<div class="meydan-geo-grid"><section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">مدیریت دستی</span><h2>افزودن استان</h2><p>برای داده‌های اختصاصی یا اصلاحات داخلی، نام، slug و ترتیب نمایش را وارد کنید.</p></div></div>';
        self::form('province', [], $provinces);
        echo '</section><section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">مدیریت دستی</span><h2>افزودن شهر</h2><p>هر شهر باید به یک استان فعال متصل باشد تا در فیلترهای API قابل انتخاب شود.</p></div></div>';
        self::form('city', [], $provinces);
        echo '</section></div>';
        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">فهرست داده</span><h2>استان‌ها</h2><p>وضعیت فعال بودن و ترتیب هر استان را در همین جدول بررسی کنید.</p></div></div><div class="meydan-table-wrap"><table class="widefat striped meydan-data-table"><thead><tr><th>شناسه</th><th>نام</th><th>Slug</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($provinces as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['id']) . '</code></td><td><strong>' . esc_html($row['name']) . '</strong></td><td><code>' . esc_html($row['slug']) . '</code></td><td>' . esc_html((string) $row['sort_order']) . '</td><td>' . self::badge((int) $row['active']) . '</td><td class="meydan-row-actions">';
            self::form('province', $row, $provinces);
            self::deleteForm('province', (int) $row['id']);
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
        echo '<section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">فهرست داده</span><h2>شهرها</h2><p>برای خوانایی، نام استان کنار هر شهر نمایش داده می‌شود. حداکثر ۱۵۰۰ شهر در این صفحه نشان داده می‌شود.</p></div></div><div class="meydan-table-wrap"><table class="widefat striped meydan-data-table"><thead><tr><th>شناسه</th><th>استان</th><th>نام</th><th>Slug</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($cities as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['id']) . '</code></td><td>' . esc_html((string) $row['province_name']) . '</td><td><strong>' . esc_html($row['name']) . '</strong></td><td><code>' . esc_html($row['slug']) . '</code></td><td>' . esc_html((string) $row['sort_order']) . '</td><td>' . self::badge((int) $row['active']) . '</td><td class="meydan-row-actions">';
            self::form('city', $row, $provinces);
            self::deleteForm('city', (int) $row['id']);
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section></div>';
    }

    public static function importFromApi(): bool
    {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $states = self::getJson(self::API_BASE . '/states');
        $source = self::API_BASE . '/states';
        if (is_wp_error($states) || !is_array($states)) {
            // The provider's state endpoint has intermittently returned 500 while
            // its city endpoint remains available. Keep the documented API IDs so
            // the city requests still come from the provider and remain joinable.
            $states = self::fallbackStates();
            $source = 'iran-locations-api:fallback-state-map';
        }
        global $wpdb;
        $provinceIds = [];
        $provinceCount = 0;
        $cityCount = 0;
        foreach ($states as $index => $state) {
            if (!is_array($state) || trim((string) ($state['name'] ?? '')) === '') {
                continue;
            }
            $name = sanitize_text_field((string) $state['name']);
            $stateId = (int) ($state['id'] ?? 0);
            $slug = sanitize_title($name) ?: 'province-' . ($stateId ?: ($index + 1));
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}meydan_provinces WHERE slug=%s", $slug));
            $data = ['name' => $name, 'slug' => $slug, 'sort_order' => (int) $index, 'active' => 1];
            if ($existing) {
                $wpdb->update($wpdb->prefix . 'meydan_provinces', $data, ['id' => (int) $existing]);
                $provinceId = (int) $existing;
            } else {
                $wpdb->insert($wpdb->prefix . 'meydan_provinces', $data);
                $provinceId = (int) $wpdb->insert_id;
            }
            if (!$provinceId) {
                continue;
            }
            $provinceIds[(int) ($state['id'] ?? 0)] = $provinceId;
            $provinceCount++;
            if (!$stateId) {
                continue;
            }
            $cities = self::getJson(self::API_BASE . '/cities?state_id=' . $stateId);
            if (is_wp_error($cities) || !is_array($cities)) {
                continue;
            }
            foreach ($cities as $cityIndex => $city) {
                if (!is_array($city) || trim((string) ($city['name'] ?? '')) === '') {
                    continue;
                }
                $cityName = sanitize_text_field((string) $city['name']);
                $cityApiId = (int) ($city['id'] ?? 0);
                $citySlug = sanitize_title($cityName) ?: 'city-' . ($cityApiId ?: ($cityIndex + 1));
                $cityData = ['province_id' => $provinceId, 'name' => $cityName, 'slug' => $citySlug, 'sort_order' => (int) $cityIndex, 'active' => 1];
                $cityId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}meydan_cities WHERE province_id=%d AND slug=%s", $provinceId, $citySlug));
                if ($cityId) {
                    $wpdb->update($wpdb->prefix . 'meydan_cities', $cityData, ['id' => (int) $cityId]);
                } else {
                    $wpdb->insert($wpdb->prefix . 'meydan_cities', $cityData);
                }
                $cityCount++;
            }
        }
        AuditLogger::log('geo_imported', 'geo', null, null, ['source' => $source, 'provinces' => $provinceCount, 'cities' => $cityCount]);
        return $provinceCount > 0;
    }

    private static function fallbackStates(): array
    {
        $names = ['آذربایجان شرقی', 'آذربایجان غربی', 'اردبیل', 'اصفهان', 'البرز', 'ایلام', 'بوشهر', 'تهران', 'چهارمحال و بختیاری', 'خراسان جنوبی', 'خراسان رضوی', 'خراسان شمالی', 'خوزستان', 'زنجان', 'سمنان', 'سیستان و بلوچستان', 'فارس', 'قزوین', 'قم', 'کردستان', 'کرمان', 'کرمانشاه', 'کهگیلویه و بویراحمد', 'گلستان', 'گیلان', 'لرستان', 'مازندران', 'مرکزی', 'هرمزگان', 'همدان', 'یزد'];
        return array_map(static fn(string $name, int $index): array => ['id' => $index + 1, 'name' => $name], $names, array_keys($names));
    }

    private static function getJson(string $url): array|WP_Error
    {
        $response = wp_safe_remote_get($url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('geo_api_http_error', 'سرویس موقعیت جغرافیایی پاسخ موفق نداد.', ['status' => $code]);
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : new WP_Error('geo_api_invalid_json', 'پاسخ سرویس موقعیت جغرافیایی JSON معتبر نیست.');
    }

    private static function form(string $kind, array $row, array $provinces): void
    {
        echo '<form method="post" class="meydan-inline-form">';
        wp_nonce_field('meydan_admin_action');
        echo '<input type="hidden" name="meydan_admin_action" value="geo_' . esc_attr($kind) . '_save"><input type="hidden" name="id" value="' . esc_attr((string) ($row['id'] ?? 0)) . '">';
        if ($kind === 'city') {
            echo '<label class="screen-reader-text" for="meydan-province-' . esc_attr((string) ($row['id'] ?? 'new')) . '">استان</label><select id="meydan-province-' . esc_attr((string) ($row['id'] ?? 'new')) . '" name="province_id" required><option value="">انتخاب استان</option>';
            foreach ($provinces as $province) {
                echo '<option value="' . esc_attr((string) $province['id']) . '" ' . selected((int) ($row['province_id'] ?? 0), (int) $province['id'], false) . '>' . esc_html($province['name']) . '</option>';
            }
            echo '</select>';
        }
        echo '<label class="screen-reader-text">نام</label><input name="name" required placeholder="نام ' . ($kind === 'city' ? 'شهر' : 'استان') . '" value="' . esc_attr((string) ($row['name'] ?? '')) . '"><label class="screen-reader-text">Slug</label><input name="slug" required placeholder="slug انگلیسی" value="' . esc_attr((string) ($row['slug'] ?? '')) . '"><label class="screen-reader-text">ترتیب</label><input type="number" name="sort_order" min="0" value="' . esc_attr((string) ($row['sort_order'] ?? 0)) . '" aria-label="ترتیب نمایش"><label class="meydan-check"><input type="checkbox" name="active" value="1" ' . checked((int) ($row['active'] ?? 1), 1, false) . '> فعال</label><button class="button" type="submit">ذخیره</button></form>';
    }

    private static function deleteForm(string $kind, int $id): void
    {
        echo '<form method="post" class="meydan-delete-form" onsubmit="return confirm(\'این مورد حذف شود؟\');">';
        wp_nonce_field('meydan_admin_action');
        echo '<input type="hidden" name="meydan_admin_action" value="geo_delete"><input type="hidden" name="kind" value="' . esc_attr($kind) . '"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '"><button class="button-link-delete" type="submit">حذف</button></form>';
    }

    private static function badge(int $active): string
    {
        return $active ? '<span class="meydan-badge is-active">فعال</span>' : '<span class="meydan-badge is-muted">غیرفعال</span>';
    }
}
