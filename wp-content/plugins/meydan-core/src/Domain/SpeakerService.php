<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

use Meydan\Core\Database\Migrations;
use Meydan\Core\Support\Actor;
use WP_Error;

/**
 * Single write path for `meydan_speaker` posts, shared by wp-admin and REST.
 *
 * Speakers are their own entity and are unrelated to `meydan_creator`
 * (content producers): a speaker is backed by a real user account, receives
 * invitations and owns the contact number that is revealed on acceptance.
 */
final class SpeakerService
{
    public const POST_TYPE = 'meydan_speaker';

    /**
     * Topical speaker categories (سیاسی، اقتصادی، …). Seeded from this map and
     * admin-editable afterwards, so an admin rename is preserved.
     */
    public const SPEAKER_CATEGORIES = [
        'siyasi' => 'سیاسی',
        'eqtesadi' => 'اقتصادی',
        'maarif' => 'معارف',
        'resanei' => 'رسانه‌ای',
        'ejtemaei' => 'اجتماعی',
    ];

    public const SPEAKER_CATEGORY_TAXONOMY = 'meydan_speaker_category';

    /** Postmeta keys mirroring CreatorService, read by Serializer::speaker(). */
    public const META_KEYS = ['role', 'handle', 'expertise', 'initials'];

    /**
     * Creates or updates a speaker profile.
     *
     * Keys accepted: name, bio, role, handle, expertise, initials,
     * avatar_media_id, verified, cities[], categories[], social_links[],
     * user_id. Absent keys are left untouched.
     *
     * @param array<string,mixed> $input
     */
    public static function save(array $input, int $id = 0): int|WP_Error
    {
        if ($id > 0 && get_post_type($id) !== self::POST_TYPE) {
            return new WP_Error('not_found', 'سخنران پیدا نشد.', ['status' => 404]);
        }

        $name = array_key_exists('name', $input) ? sanitize_text_field((string) $input['name']) : '';
        $hasBio = array_key_exists('bio', $input);

        if ($id === 0) {
            if ($name === '') {
                return new WP_Error('validation_failed', 'نام سخنران الزامی است.', ['status' => 422, 'fields' => ['name' => 'required']]);
            }
            $result = wp_insert_post([
                'post_type' => self::POST_TYPE,
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

        if (array_key_exists('categories', $input)) {
            // Intersect against the seeded slugs so an unknown value cannot
            // silently create a stray term. A speaker may hold several.
            $categories = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['categories']),
                array_keys(self::SPEAKER_CATEGORIES)
            ));
            wp_set_post_terms($id, $categories, self::SPEAKER_CATEGORY_TAXONOMY);
        }

        foreach (self::META_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                update_post_meta($id, 'meydan_' . $key, sanitize_text_field((string) $input[$key]));
            }
        }

        if (array_key_exists('avatar_media_id', $input)) {
            self::applyAvatar($id, (int) $input['avatar_media_id']);
        }

        if (array_key_exists('verified', $input)) {
            update_post_meta($id, 'meydan_verified', filter_var($input['verified'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (array_key_exists('cities', $input)) {
            $cities = array_values(array_unique(array_filter(array_map('intval', (array) $input['cities']))));
            update_post_meta($id, 'meydan_cities', $cities);
        }

        if (array_key_exists('social_links', $input)) {
            // Shared normaliser: the platform key vocabulary is the same.
            update_post_meta($id, 'meydan_social_links', CreatorService::normalizeSocialLinks((array) $input['social_links']));
        }

        if (array_key_exists('user_id', $input)) {
            self::linkUser($id, (int) $input['user_id']);
        }

        return $id;
    }

    /**
     * Links a speaker profile to the user account that backs it.
     *
     * The user becomes the source of truth for invitations, notifications and
     * the revealed contact number; the speaker post stays the public profile.
     * Passing 0 unlinks, leaving the profile public but non-invitable.
     */
    public static function linkUser(int $speakerId, int $userId): void
    {
        if ($speakerId <= 0) {
            return;
        }

        $previous = (int) get_post_meta($speakerId, Migrations::SPEAKER_USER_META, true);

        if ($userId <= 0 || !get_userdata($userId)) {
            delete_post_meta($speakerId, Migrations::SPEAKER_USER_META);
            if ($previous > 0) {
                delete_user_meta($previous, Migrations::USER_SPEAKER_META);
                Actor::forgetSpeakerLink($previous);
            }
            return;
        }

        // A user backs at most one speaker profile, so drop any stale link.
        if ($previous > 0 && $previous !== $userId) {
            delete_user_meta($previous, Migrations::USER_SPEAKER_META);
            Actor::forgetSpeakerLink($previous);
        }

        // Re-linking must detach whichever profile previously owned this user.
        $existingSpeaker = (int) get_user_meta($userId, Migrations::USER_SPEAKER_META, true);
        if ($existingSpeaker > 0 && $existingSpeaker !== $speakerId) {
            delete_post_meta($existingSpeaker, Migrations::SPEAKER_USER_META);
        }

        update_post_meta($speakerId, Migrations::SPEAKER_USER_META, $userId);
        update_user_meta($userId, Migrations::USER_SPEAKER_META, $speakerId);
        Actor::forgetSpeakerLink($userId);
    }

    /** User id linked to a speaker post, or 0. */
    public static function linkedUserId(int $speakerId): int
    {
        return (int) get_post_meta($speakerId, Migrations::SPEAKER_USER_META, true);
    }

    /**
     * slug => Persian label for speaker categories.
     *
     * Live terms win over the const so an admin rename is reflected without a
     * deploy; the const seeds them and covers the pre-seed window.
     *
     * @return array<string,string>
     */
    public static function categoryOptions(): array
    {
        $terms = get_terms(['taxonomy' => self::SPEAKER_CATEGORY_TAXONOMY, 'hide_empty' => false]);
        $known = self::SPEAKER_CATEGORIES;
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $known[$term->slug] ??= $term->name;
            }
        }
        return $known;
    }

    /** Category taxonomy terms in the API shape used by clients. */
    public static function categoryTerms(): array
    {
        $terms = get_terms(['taxonomy' => self::SPEAKER_CATEGORY_TAXONOMY, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(static fn($term): array => [
            'slug' => (string) $term->slug,
            'name' => (string) $term->name,
            'count' => (int) $term->count,
        ], $terms);
    }

    /** Terms of one speaker resolved to `{slug,name}` pairs. */
    public static function categoriesOf(int $speakerId): array
    {
        $terms = wp_get_post_terms($speakerId, self::SPEAKER_CATEGORY_TAXONOMY);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(static fn($term): array => [
            'slug' => (string) $term->slug,
            'name' => (string) $term->name,
        ], $terms);
    }

    /** Users selectable as the account behind a speaker: never a square. */
    public static function linkableUsers(): array
    {
        $users = get_users(['fields' => ['ID', 'display_name'], 'orderby' => 'display_name']);
        $out = [];
        foreach ($users as $user) {
            $id = (int) $user->ID;
            // A square is an inviter, not an invitee.
            if (Actor::isSquare($id)) {
                continue;
            }
            $out[$id] = (string) $user->display_name;
        }
        return $out;
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
