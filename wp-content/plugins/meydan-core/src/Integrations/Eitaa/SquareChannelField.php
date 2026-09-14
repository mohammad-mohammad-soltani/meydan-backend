<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class SquareChannelField
{
    public static function register(): void
    {
        add_action('show_user_profile', [self::class, 'render']);
        add_action('edit_user_profile', [self::class, 'render']);
        add_action('personal_options_update', [self::class, 'save']);
        add_action('edit_user_profile_update', [self::class, 'save']);
    }

    public static function render(\WP_User $user): void
    {
        if (!current_user_can('edit_user', $user->ID) || !in_array('meydan_square', (array) $user->roles, true)) {
            return;
        }
        $value = (string) get_user_meta($user->ID, 'meydan_eitaa_channel', true);
        wp_nonce_field('meydan_eitaa_channel_' . $user->ID, 'meydan_eitaa_channel_nonce');
        echo '<h2>اتصال ایتا</h2><table class="form-table"><tr>';
        echo '<th><label for="meydan_eitaa_channel">کانال ایتا</label></th><td>';
        echo '<input id="meydan_eitaa_channel" name="meydan_eitaa_channel" class="regular-text" value="' . esc_attr($value) . '" placeholder="@channel یا شناسه عددی">';
        echo '<p class="description">شناسه عددی، @username یا لینک کانال ایتا. خالی بودن این فیلد همگام‌سازی این میدان را غیرفعال می‌کند.</p>';
        echo '</td></tr></table>';
    }

    public static function save(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        $user = get_userdata($userId);
        if (!$user || !in_array('meydan_square', (array) $user->roles, true)) {
            return;
        }
        $nonce = (string) ($_POST['meydan_eitaa_channel_nonce'] ?? '');
        if ($nonce === '' || !wp_verify_nonce($nonce, 'meydan_eitaa_channel_' . $userId)) {
            return;
        }
        $raw = trim((string) wp_unslash($_POST['meydan_eitaa_channel'] ?? ''));
        $value = self::normalize($raw);
        if ($value === '') {
            delete_user_meta($userId, 'meydan_eitaa_channel');
        } else {
            update_user_meta($userId, 'meydan_eitaa_channel', $value);
        }
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://(?:www\.)?(?:eitaa\.com|eitaa\.ir)/#i', '', $value) ?? $value;
        $value = trim($value, "/ \t\n\r\0\x0B");
        if ($value === '') {
            return '';
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return ltrim($value, '-');
        }
        $value = ltrim($value, '@');
        return preg_match('/^[A-Za-z0-9_]{3,64}$/', $value) ? '@' . $value : '';
    }
}
