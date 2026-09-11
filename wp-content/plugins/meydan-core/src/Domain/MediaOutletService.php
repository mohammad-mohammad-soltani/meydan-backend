<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use WP_Error;

/** Single write path for meydan_media_outlet posts, shared by the metabox and REST. */
final class MediaOutletService
{
    public const LINKS = [
        'website' => 'وب‌سایت',
        'bale' => 'بله',
        'eitaa' => 'ایتا',
    ];

    /**
     * Keys accepted: name, avatar_media_id, website, bale, eitaa.
     * Absent keys are left untouched. The post row is only written when name
     * arrives, so the wp-admin metabox (which omits it, since core already
     * saves the title) can call this safely from save_post.
     *
     * @param array<string,mixed> $input
     */
    public static function save(array $input, int $id = 0): int|WP_Error
    {
        if ($id > 0 && get_post_type($id) !== 'meydan_media_outlet') {
            return new WP_Error('not_found', 'رسانه پیدا نشد.', ['status' => 404]);
        }

        $name = array_key_exists('name', $input) ? sanitize_text_field((string) $input['name']) : '';

        if ($id === 0) {
            if ($name === '') {
                return new WP_Error('validation_failed', 'نام رسانه الزامی است.', ['status' => 422, 'fields' => ['name' => 'required']]);
            }
            $result = wp_insert_post([
                'post_type' => 'meydan_media_outlet',
                'post_status' => 'publish',
                'post_title' => $name,
            ], true);
            if (is_wp_error($result)) {
                return $result;
            }
            $id = (int) $result;
        } elseif ($name !== '') {
            $result = wp_update_post(['ID' => $id, 'post_title' => $name], true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (array_key_exists('avatar_media_id', $input)) {
            self::applyAvatar($id, (int) $input['avatar_media_id']);
        }

        foreach (array_keys(self::LINKS) as $key) {
            if (array_key_exists($key, $input)) {
                update_post_meta($id, 'meydan_' . $key, esc_url_raw(trim((string) $input[$key])));
            }
        }

        return $id;
    }

    private static function applyAvatar(int $id, int $mediaId): void
    {
        if ($mediaId > 0 && (!wp_attachment_is_image($mediaId) || !current_user_can('edit_post', $mediaId))) {
            return;
        }
        if ($mediaId > 0) {
            update_post_meta($id, 'meydan_avatar_media_id', $mediaId);
        } else {
            delete_post_meta($id, 'meydan_avatar_media_id');
        }
    }
}
