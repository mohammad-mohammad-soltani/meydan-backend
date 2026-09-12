<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Support\Actor;
use WP_Error;

/** Single write path for meydan_creator posts, shared by the metabox and REST. */
final class CreatorService
{
    public const TYPE_LABELS = [
        'speaker' => 'سخنران',
        'reciter' => 'مداح',
        'writer' => 'نویسنده',
        'journalist' => 'خبرنگار',
        'designer' => 'طراح',
        'media_team' => 'تیم رسانه',
        'institution' => 'نهاد',
        'studio' => 'استودیو',
        'other' => 'سایر',
    ];

    public const SOCIAL_PLATFORMS = [
        'website' => 'وب‌سایت',
        'instagram' => 'اینستاگرام',
        'telegram' => 'تلگرام',
        'x' => 'ایکس (توییتر)',
        'youtube' => 'یوتیوب',
        'aparat' => 'آپارات',
        'linkedin' => 'لینکدین',
    ];

    /**
     * Keys accepted: name, bio, types[], role, handle, expertise, initials,
     * avatar_media_id, verified, cities[], social_links[{platform,url,label}].
     * Absent keys are left untouched. The post row is only written when
     * name/bio arrive, so the wp-admin metabox (which omits both, since core
     * already saves title/content) can call this safely from save_post.
     *
     * @param array<string,mixed> $input
     */
    public static function save(array $input, int $id = 0): int|WP_Error
    {
        if ($id > 0 && get_post_type($id) !== 'meydan_creator') {
            return new WP_Error('not_found', 'تولیدکننده پیدا نشد.', ['status' => 404]);
        }

        $name = array_key_exists('name', $input) ? sanitize_text_field((string) $input['name']) : '';
        $hasBio = array_key_exists('bio', $input);

        if ($id === 0) {
            if ($name === '') {
                return new WP_Error('validation_failed', 'نام تولیدکننده الزامی است.', ['status' => 422, 'fields' => ['name' => 'required']]);
            }
            $result = wp_insert_post([
                'post_type' => 'meydan_creator',
                'post_status' => 'publish',
                'post_title' => $name,
                'post_content' => $hasBio ? wp_kses_post((string) $input['bio']) : '',
            ], true);
            if (is_wp_error($result)) {
                return $result;
            }
            $id = (int) $result;
        } elseif ($name !== '' || $hasBio) {
            $post = ['ID' => $id];
            if ($name !== '') {
                $post['post_title'] = $name;
            }
            if ($hasBio) {
                $post['post_content'] = wp_kses_post((string) $input['bio']);
            }
            $result = wp_update_post($post, true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (array_key_exists('types', $input)) {
            $types = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['types']),
                array_keys(self::TYPE_LABELS)
            ));
            wp_set_post_terms($id, $types, 'meydan_creator_type');
        }

        foreach (['role', 'handle', 'expertise', 'initials'] as $key) {
            if (array_key_exists($key, $input)) {
                update_post_meta($id, 'meydan_' . $key, sanitize_text_field((string) $input[$key]));
            }
        }

        if (array_key_exists('avatar_media_id', $input)) {
            self::applyAvatar($id, (int) $input['avatar_media_id']);
        }

        if (array_key_exists('verified', $input)) {
            update_post_meta($id, 'meydan_verified', self::toBool($input['verified']) ? 1 : 0);
        }

        if (array_key_exists('cities', $input)) {
            $cities = array_values(array_unique(array_filter(array_map('intval', (array) $input['cities']))));
            update_post_meta($id, 'meydan_cities', $cities);
        }

        if (array_key_exists('social_links', $input)) {
            update_post_meta($id, 'meydan_social_links', self::normalizeSocialLinks((array) $input['social_links']));
        }

        if (array_key_exists('user_id', $input)) {
            self::linkUser($id, (int) $input['user_id']);
        }

        return $id;
    }

    /**
     * Links a creator profile to the user account that owns it.
     *
     * The user becomes the source of truth for invitations, notifications and
     * the revealed contact number; the creator post stays the public profile.
     * Passing 0 unlinks, leaving the profile public but non-invitable.
     */
    public static function linkUser(int $creatorId, int $userId): void
    {
        if ($creatorId <= 0) {
            return;
        }

        $previous = (int) get_post_meta($creatorId, 'meydan_creator_user_id', true);

        if ($userId <= 0 || !get_userdata($userId)) {
            delete_post_meta($creatorId, 'meydan_creator_user_id');
            if ($previous > 0) {
                delete_user_meta($previous, 'meydan_speaker_creator_id');
                Actor::forgetSpeakerLink($previous);
            }
            return;
        }

        // A user backs at most one speaker profile, so drop any stale link.
        if ($previous > 0 && $previous !== $userId) {
            delete_user_meta($previous, 'meydan_speaker_creator_id');
            Actor::forgetSpeakerLink($previous);
        }

        // Re-linking must detach whichever profile previously owned this user.
        $existingCreator = (int) get_user_meta($userId, 'meydan_speaker_creator_id', true);
        if ($existingCreator > 0 && $existingCreator !== $creatorId) {
            delete_post_meta($existingCreator, 'meydan_creator_user_id');
        }

        update_post_meta($creatorId, 'meydan_creator_user_id', $userId);
        update_user_meta($userId, 'meydan_speaker_creator_id', $creatorId);
        Actor::forgetSpeakerLink($userId);
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

    /**
     * Keeps the platform key stable for the client and stores the raw URL, so
     * the API shape stays consistent no matter which admin UI produced it.
     *
     * @param array<int,mixed> $links
     * @return array<int,array{platform:string,url:string,label:string}>
     */
    public static function normalizeSocialLinks(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $link = ['url' => $link];
            }
            if (!is_array($link)) {
                continue;
            }
            $url = esc_url_raw(trim((string) ($link['url'] ?? '')));
            if ($url === '') {
                continue;
            }
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            if (!isset(self::SOCIAL_PLATFORMS[$platform])) {
                $platform = 'other';
            }
            $row = ['platform' => $platform, 'url' => $url];
            $label = sanitize_text_field((string) ($link['label'] ?? ''));
            if ($label !== '') {
                $row['label'] = $label;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function typeOptions(): array
    {
        $terms = get_terms(['taxonomy' => 'meydan_creator_type', 'hide_empty' => false]);
        $known = self::TYPE_LABELS;
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $known[$term->slug] ??= $term->name;
            }
        }
        return $known;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
