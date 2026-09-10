<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Error;
use WP_Post;

final class SquareActivity
{
    private const META_KEY = 'meydan_start_date';
    private const NONCE_ACTION = 'meydan_save_square_start_date';
    private const NONCE_NAME = 'meydan_square_start_date_nonce';

    public static function registerAdmin(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post_meydan_square', [self::class, 'saveAdminValue'], 20, 2);
    }

    public static function startDate(int $squareId): ?string
    {
        $value = trim((string) get_post_meta($squareId, self::META_KEY, true));
        return self::isValidDate($value) ? $value : null;
    }

    public static function activeNights(int $squareId): int
    {
        return self::activeNightsFromDate(self::startDate($squareId));
    }

    public static function activeNightsFromDate(?string $startDate): int
    {
        if (!$startDate || !self::isValidDate($startDate)) {
            return 0;
        }

        $timezone = wp_timezone();
        $start = new \DateTimeImmutable($startDate . ' 00:00:00', $timezone);
        $today = new \DateTimeImmutable('today', $timezone);

        if ($start > $today) {
            return 0;
        }

        return (int) $start->diff($today)->days + 1;
    }

    public static function setStartDate(int $squareId, mixed $value): true|WP_Error
    {
        $date = trim(sanitize_text_field((string) $value));

        if ($date === '') {
            delete_post_meta($squareId, self::META_KEY);
            return true;
        }

        if (!self::isValidDate($date)) {
            return new WP_Error(
                'validation_failed',
                'تاریخ شروع میدان معتبر نیست. فرمت صحیح YYYY-MM-DD است.',
                ['status' => 422]
            );
        }

        update_post_meta($squareId, self::META_KEY, $date);
        return true;
    }

    public static function addMetaBox(): void
    {
        add_meta_box(
            'meydan-square-start-date',
            'تاریخ شروع فعالیت میدان',
            [self::class, 'renderMetaBox'],
            'meydan_square',
            'side',
            'default'
        );
    }

    public static function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $value = self::startDate((int) $post->ID) ?? '';

        echo '<p><label for="meydan_start_date"><strong>تاریخ شروع میدان</strong></label></p>';
        echo '<input type="date" id="meydan_start_date" name="meydan_start_date" value="' . esc_attr($value) . '" style="width:100%">';
        echo '<p class="description">تعداد شب‌های فعال به‌صورت خودکار از این تاریخ تا امروز محاسبه می‌شود.</p>';

        if ($value !== '') {
            echo '<p><strong>شب‌های فعال:</strong> ' . esc_html((string) self::activeNightsFromDate($value)) . '</p>';
        }
    }

    public static function saveAdminValue(int $postId, WP_Post $post): void
    {
        if ($post->post_type !== 'meydan_square') {
            return;
        }
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])),
                self::NONCE_ACTION
            )
        ) {
            return;
        }

        self::setStartDate($postId, $_POST['meydan_start_date'] ?? '');
    }

    private static function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
