<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

final class Registrations
{
    public static function registerRolesAndCapabilities(): void
    {
        $allCaps = self::capabilities();

        add_role('meydan_user', 'Meydan User', ['read' => true, 'read_meydan' => true, 'publish_meydan_narratives' => true, 'edit_own_meydan_narratives' => true]);
        add_role('meydan_square', 'Meydan Square', ['read' => true, 'read_meydan' => true, 'publish_meydan_narratives' => true, 'edit_own_meydan_narratives' => true]);
        foreach (EntityKinds::ROLE_LABELS as $role => $label) {
            add_role($role, 'Meydan ' . ucfirst(substr($role, 7)), ['read' => true, 'read_meydan' => true, 'publish_meydan_narratives' => true, 'edit_own_meydan_narratives' => true]);
        }
        // A speaker is an account type, not an entity: the role carries the
        // same abilities as a regular user and nothing more.
        add_role('meydan_speaker', 'Meydan Speaker', ['read' => true, 'read_meydan' => true, 'publish_meydan_narratives' => true, 'edit_own_meydan_narratives' => true]);
        // Official public figures are normal user actors with a dedicated account
        // role. The role itself grants the grey official badge in clients.
        add_role('meydan_official', 'Meydan Official', ['read' => true, 'read_meydan' => true, 'publish_meydan_narratives' => true, 'edit_own_meydan_narratives' => true]);
        add_role('meydan_content_editor', 'Meydan Content Editor', [
            'read' => true,
            'read_meydan' => true,
            'manage_meydan_content' => true,
            'manage_meydan_creators' => true,
        ]);
        add_role('meydan_moderator', 'Meydan Moderator', [
            'read' => true,
            'read_meydan' => true,
            'moderate_meydan_narratives' => true,
        ]);
        add_role('meydan_manager', 'Meydan Manager', array_merge(['read' => true], array_fill_keys(array_keys($allCaps), true)));
        add_role('meydan_support', 'Meydan Support', [
            'read' => true,
            'read_meydan' => true,
            'manage_meydan_speakers' => true,
            'manage_meydan_officials' => true,
            'manage_meydan_speaker_requests' => true,
            'manage_meydan_notifications' => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            foreach (array_keys($allCaps) as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /** @return array<string,bool> */
    public static function capabilities(): array
    {
        return array_fill_keys([
            'read_meydan',
            'publish_meydan_narratives',
            'edit_own_meydan_narratives',
            'moderate_meydan_narratives',
            'manage_meydan_content',
            'manage_meydan_creators',
            'manage_meydan_speakers',
            'manage_meydan_officials',
            'manage_meydan_squares',
            'verify_meydan_squares',
            'manage_meydan_memorials',
            'manage_meydan_initiatives',
            'manage_meydan_campaigns',
            'manage_meydan_media_reflections',
            'manage_meydan_speaker_requests',
            'manage_meydan_notifications',
            'manage_meydan_stats',
            'view_meydan_audit_log',
        ], true);
    }

    public static function registerPostTypes(): void
    {
        self::postType('meydan_narrative', 'روایت‌ها', 'روایت', true, 'moderate_meydan_narratives', ['editor', 'author', 'thumbnail', 'comments']);
        self::postType('meydan_content', 'محتوا', 'محتوا', true, 'manage_meydan_content', ['title', 'editor', 'excerpt', 'thumbnail']);
        self::postType('meydan_creator', 'تولیدکنندگان', 'تولیدکننده', true, 'manage_meydan_creators', ['title', 'editor', 'thumbnail']);
        // Speakers have no post type: a speaker is a user account holding the
        // `meydan_speaker` role, with the profile stored in user meta.
        self::postType('meydan_media_outlet', 'رسانه‌ها', 'رسانه', true, 'manage_meydan_media_reflections', ['title', 'thumbnail']);
        self::postType('meydan_square', 'میدان‌ها', 'میدان', true, 'manage_meydan_squares', ['title', 'editor', 'thumbnail', 'author']);
        // Media, collectives and organizations are entities of their own, not squares.
        self::postType('meydan_media_acct', 'حساب‌های رسانه', 'حساب رسانه', true, 'manage_meydan_squares', ['title', 'editor', 'thumbnail', 'author']);
        self::postType('meydan_collective', 'مجموعه‌ها', 'مجموعه', true, 'manage_meydan_squares', ['title', 'editor', 'thumbnail', 'author']);
        self::postType('meydan_organization', 'سازمان‌ها', 'سازمان', true, 'manage_meydan_squares', ['title', 'editor', 'thumbnail', 'author']);
        // A memorial is a biographical profile (یادبود): its own entity kind,
        // managed only through the admin REST API for now.
        self::postType('meydan_memorial', 'یادبودها', 'یادبود', true, 'manage_meydan_memorials', ['title', 'editor', 'thumbnail', 'author']);
        self::postType('meydan_initiative', 'ابتکارها', 'ابتکار', true, 'manage_meydan_initiatives', ['title', 'editor', 'thumbnail']);
        self::postType('meydan_campaign', 'کمپین‌ها', 'کمپین', true, 'manage_meydan_campaigns', ['title', 'editor', 'thumbnail']);
    }

    private static function postType(string $type, string $plural, string $singular, bool $public, string $cap, array $supports): void
    {
        register_post_type($type, [
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
                'add_new_item' => 'افزودن ' . $singular,
                'edit_item' => 'ویرایش ' . $singular,
            ],
            'public' => $public,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => $supports,
            'capabilities' => [
                'edit_post' => $cap,
                'read_post' => 'read_meydan',
                'delete_post' => $cap,
                'edit_posts' => $cap,
                'edit_others_posts' => $cap,
                'publish_posts' => $cap,
                'read_private_posts' => $cap,
                'delete_posts' => $cap,
                'delete_private_posts' => $cap,
                'delete_published_posts' => $cap,
                'delete_others_posts' => $cap,
                'edit_private_posts' => $cap,
                'edit_published_posts' => $cap,
                'create_posts' => $cap,
            ],
            'map_meta_cap' => false,
        ]);
    }

    public static function registerTaxonomies(): void
    {
        self::taxonomy('meydan_content_category', ['meydan_content'], 'دسته‌های محتوا', true);
        self::taxonomy('meydan_narrative_tag', ['meydan_narrative'], 'برچسب‌های روایت', false);
        // A new/removed tag invalidates the composer's cached hashtag suggestions.
        add_action('set_object_terms', static function ($objectId, $terms, $ttIds, $taxonomy): void {
            if ($taxonomy === 'meydan_narrative_tag') {
                \Meydan\Core\Rest\ExploreController::bumpSuggestVersion();
            }
        }, 10, 4);
        self::taxonomy('meydan_content_tag', ['meydan_content'], 'برچسب‌های محتوا', false);
        self::taxonomy('meydan_creator_type', ['meydan_creator'], 'نوع تولیدکننده', false);
        // Speaker topical categories are user meta (`meydan_speaker_categories`)
        // whose vocabulary comes from SpeakerService::SPEAKER_CATEGORIES; no
        // taxonomy is registered because WordPress taxonomies cannot attach to
        // users, and storing them on a post would reintroduce a speaker entity.
        self::taxonomy('meydan_topic', ['meydan_narrative', 'meydan_content'], 'موضوع‌ها', false);

        $types = ['speaker', 'reciter', 'writer', 'journalist', 'designer', 'media_team', 'institution', 'studio', 'other'];
        foreach ($types as $type) {
            if (!term_exists($type, 'meydan_creator_type')) {
                wp_insert_term($type, 'meydan_creator_type', ['slug' => $type]);
            }
        }
    }

    private static function taxonomy(string $name, array $types, string $label, bool $hierarchical): void
    {
        register_taxonomy($name, $types, [
            'label' => $label,
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'hierarchical' => $hierarchical,
            'rewrite' => false,
        ]);
    }
}
