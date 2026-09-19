<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Integrations\Channels\Channels;
use Meydan\Core\Support\Actor;
use WP_Error;

/**
 * Single write path for speaker accounts, shared by wp-admin and REST.
 *
 * A speaker is a WordPress user holding the `meydan_speaker` role, not a post:
 * the profile lives in user meta and the role is the single source of truth, so
 * no intermediary entity exists between the account and its speaker profile.
 * The role carries exactly the same abilities as a regular Meydan user.
 */
final class SpeakerService
{
    public const ROLE = 'meydan_speaker';

    /**
     * Topical speaker categories (سیاسی، اقتصادی، …).
     *
     * The vocabulary is a constant because WordPress taxonomies cannot attach
     * to users; the selected slugs per speaker are stored in the
     * `meydan_speaker_categories` user meta.
     */
    public const SPEAKER_CATEGORIES = [
        'siyasi' => 'سیاسی',
        'eqtesadi' => 'اقتصادی',
        'maarif' => 'معارف',
        'resanei' => 'رسانه‌ای',
        'ejtemaei' => 'اجتماعی',
    ];

    /**
     * Legacy taxonomy name.
     *
     * Kept only so the 1.2.0 migration can read terms that were stored before
     * speakers became accounts; nothing registers or writes it any more.
     */
    public const SPEAKER_CATEGORY_TAXONOMY = 'meydan_speaker_category';

    /** User meta keys holding the speaker profile. */
    public const META_KEYS = ['role', 'handle', 'expertise', 'initials'];

    /**
     * Grants the speaker role to an existing account.
     *
     * Promotion never touches administrators or squares: a square is an
     * inviter, and demoting an administrator would lock them out.
     */
    public static function promote(int $userId): true|WP_Error
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new WP_Error('not_found', 'کاربر پیدا نشد.', ['status' => 404]);
        }
        $roles = (array) $user->roles;
        if (in_array('administrator', $roles, true) || in_array('meydan_square', $roles, true)) {
            return new WP_Error('validation_failed', 'این حساب را نمی‌توان به سخنران تبدیل کرد.', ['status' => 422, 'fields' => ['user_id' => 'not_eligible']]);
        }

        // Idempotent: promoting an existing speaker just repairs the account type.
        if (!in_array(self::ROLE, $roles, true)) {
            $user->set_role(self::ROLE);
        }
        update_user_meta($userId, 'meydan_account_type', 'speaker');
        if ((string) get_user_meta($userId, 'meydan_full_name', true) === '') {
            update_user_meta($userId, 'meydan_full_name', $user->display_name);
        }

        return true;
    }

    /** Removes the speaker role, keeping the account and its profile meta. */
    public static function demote(int $userId): true|WP_Error
    {
        if (!Actor::isSpeaker($userId)) {
            return new WP_Error('not_found', 'سخنران پیدا نشد.', ['status' => 404]);
        }

        $user = get_userdata($userId);
        if ($user) {
            $user->set_role('meydan_user');
        }
        update_user_meta($userId, 'meydan_account_type', 'user');

        return true;
    }

    /** A user can become a speaker unless it is an admin, square, official or already one. */
    public static function isPromotable(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }
        $roles = (array) $user->roles;
        return !in_array('administrator', $roles, true)
            && !in_array('meydan_square', $roles, true)
            && !in_array('meydan_official', $roles, true)
            && !in_array(self::ROLE, $roles, true);
    }

    /**
     * Creates or updates a speaker profile on the user account.
     *
     * Keys accepted: name, bio, role, handle, expertise, initials,
     * avatar_media_id, verified, cities[], categories[], social_links[].
     * Absent keys are left untouched.
     *
     * @param array<string,mixed> $input
     */
    public static function save(array $input, int $userId): true|WP_Error
    {
        if ($userId <= 0 || !Actor::isSpeaker($userId)) {
            return new WP_Error('not_found', 'سخنران پیدا نشد.', ['status' => 404]);
        }

        if (array_key_exists('name', $input)) {
            $name = sanitize_text_field((string) $input['name']);
            if ($name !== '') {
                update_user_meta($userId, 'meydan_full_name', $name);
                // Keep the core display name in sync so user lists and pickers show it.
                wp_update_user(['ID' => $userId, 'display_name' => $name]);
            }
        }

        if (array_key_exists('bio', $input)) {
            update_user_meta($userId, 'meydan_about', wp_kses_post((string) $input['bio']));
        }

        foreach (self::META_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                update_user_meta($userId, 'meydan_' . $key, sanitize_text_field((string) $input[$key]));
            }
        }

        if (array_key_exists('avatar_media_id', $input)) {
            self::applyAvatar($userId, (int) $input['avatar_media_id']);
        }

        if (array_key_exists('verified', $input)) {
            update_user_meta($userId, 'meydan_verified', filter_var($input['verified'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (array_key_exists('cities', $input)) {
            $cities = array_values(array_unique(array_filter(array_map('intval', (array) $input['cities']))));
            update_user_meta($userId, 'meydan_cities', $cities);
        }

        if (array_key_exists('social_links', $input)) {
            // Shared normaliser: the platform key vocabulary is the same.
            update_user_meta($userId, 'meydan_social_links', CreatorService::normalizeSocialLinks((array) $input['social_links']));
        }

        foreach (['eitaa' => 'eitaa_channel', 'bale' => 'bale_channel'] as $kind => $key) {
            if (array_key_exists($key, $input)) {
                Channels::store($userId, $kind, (string) $input[$key]);
            }
        }

        if (array_key_exists('categories', $input)) {
            // Intersect against the seeded slugs so an unknown value cannot
            // silently create a stray term. A speaker may hold several.
            $categories = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['categories']),
                array_keys(self::SPEAKER_CATEGORIES)
            ));
            update_user_meta($userId, 'meydan_speaker_categories', $categories);
        }

        return true;
    }

    /**
     * slug => Persian label for speaker categories.
     *
     * @return array<string,string>
     */
    public static function categoryOptions(): array
    {
        return self::SPEAKER_CATEGORIES;
    }

    /** Category vocabulary in the API shape used by clients. */
    public static function categoryTerms(): array
    {
        $out = [];
        foreach (self::SPEAKER_CATEGORIES as $slug => $name) {
            $out[] = [
                'slug' => $slug,
                'name' => $name,
                'count' => self::countUsersWithCategory($slug),
            ];
        }
        return $out;
    }

    /** Categories of one speaker resolved to `{slug,name}` pairs. */
    public static function categoriesOf(int $userId): array
    {
        $slugs = array_values(array_filter(array_map(
            'sanitize_key',
            (array) get_user_meta($userId, 'meydan_speaker_categories', true)
        )));

        $out = [];
        foreach ($slugs as $slug) {
            $out[] = [
                'slug' => $slug,
                'name' => self::SPEAKER_CATEGORIES[$slug] ?? $slug,
            ];
        }
        return $out;
    }

    /** Accounts selectable as speakers: never an admin, square, official or existing speaker. */
    public static function promotableUsers(): array
    {
        $out = [];
        foreach (get_users(['orderby' => 'display_name']) as $user) {
            $id = (int) $user->ID;
            if (self::isPromotable($id)) {
                $out[$id] = (string) $user->display_name;
            }
        }
        return $out;
    }

    private static function countUsersWithCategory(string $slug): int
    {
        $ids = get_users([
            'role' => self::ROLE,
            'fields' => 'ID',
            'meta_query' => [[
                'key' => 'meydan_speaker_categories',
                'value' => $slug,
                'compare' => 'LIKE',
            ]],
        ]);
        return is_array($ids) ? count($ids) : 0;
    }

    private static function applyAvatar(int $userId, int $mediaId): void
    {
        if ($mediaId > 0 && (!wp_attachment_is_image($mediaId) || !current_user_can('edit_post', $mediaId))) {
            return;
        }
        if ($mediaId > 0) {
            update_user_meta($userId, 'meydan_avatar_media_id', $mediaId);
        } else {
            delete_user_meta($userId, 'meydan_avatar_media_id');
        }
    }
}
