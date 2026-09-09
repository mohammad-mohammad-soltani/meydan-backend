<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

final class Dashboard
{
    public static function render(): void
    {
        global $wpdb;
        $counts = [
            ['روایت‌های منتشرشده', (int) (wp_count_posts('meydan_narrative')->publish ?? 0), 'محتوای اجتماعی قابل مشاهده برای کاربران.', 'edit.php?post_type=meydan_narrative'],
            ['محتوای مرجع', (int) (wp_count_posts('meydan_content')->publish ?? 0), 'مقاله، فایل، پادکست و منابع مدیریت‌شده.', 'edit.php?post_type=meydan_content'],
            ['میدان‌های فعال', (int) (wp_count_posts('meydan_square')->publish ?? 0), 'مکان‌ها و اجتماع‌های ثبت‌شده در نقشه.', 'edit.php?post_type=meydan_square'],
            ['در انتظار بررسی', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='meydan_approval_status' WHERE p.post_type='meydan_square' AND m.meta_value='pending_verification'"), 'درخواست‌هایی که نیاز به تصمیم مدیر دارند.', 'admin.php?page=meydan-square-approvals'],
        ];
        echo '<div class="wrap meydan-admin" dir="rtl"><div class="meydan-page-header"><div><span class="meydan-eyebrow">مرکز فرماندهی</span><h1>داشبورد میدان</h1><p>نمایی سریع از وضعیت محتوا، میدان‌ها و عملیات مدیریتی. از اینجا می‌توانید به مهم‌ترین کارهای روزانه برسید و قبل از تغییرات حساس وضعیت سیستم را ببینید.</p></div><div class="meydan-header-mark" aria-hidden="true">M</div></div><div class="meydan-stat-strip meydan-dashboard-stats">';
        foreach ($counts as [$label, $value, $description, $url]) {
            echo '<a class="meydan-dashboard-card" href="' . esc_url(admin_url($url)) . '"><strong>' . esc_html((string) $value) . '</strong><span>' . esc_html($label) . '</span><small>' . esc_html($description) . '</small></a>';
        }
        echo '</div><div class="meydan-geo-grid"><section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">شروع سریع</span><h2>کارهای پرتکرار</h2><p>مسیرهای اصلی مدیریت میدان را بدون جست‌وجو در منو باز کنید.</p></div></div><div class="meydan-quick-links"><a href="' . esc_url(admin_url('post-new.php?post_type=meydan_content')) . '"><b>+ افزودن محتوا</b><span>ساخت منبع جدید برای بخش محتوا</span></a><a href="' . esc_url(admin_url('admin.php?page=meydan-geo')) . '"><b>استان‌ها و شهرها</b><span>مدیریت یا همگام‌سازی داده جغرافیایی</span></a><a href="' . esc_url(admin_url('admin.php?page=meydan-settings')) . '"><b>تنظیمات اتصال</b><span>SMS، CORS و گزینه‌های تخصصی API</span></a></div></section><section class="meydan-panel"><div class="meydan-panel-heading"><div><span class="meydan-section-kicker">زیرساخت</span><h2>API میدان</h2><p>کلاینت‌ها از این namespace برای ارتباط با بک‌اند استفاده می‌کنند.</p></div></div><div class="meydan-api-callout"><code>/wp-json/meydan/v1</code><span>REST namespace فعال</span></div><p class="meydan-help">قبل از انتشار تغییرات، smoke test لوکال و بررسی permissionهای endpointها را انجام دهید.</p></section></div></div>';
    }
}
