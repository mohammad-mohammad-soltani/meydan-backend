<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

use Meydan\Core\Notifications\NotificationService;

final class Migrations
{
    public const VERSION = '1.1.0';

    /** Speaker postmeta holding the linked WordPress user id. */
    public const SPEAKER_USER_META = 'meydan_speaker_user_id';

    /** User meta mirroring the linked speaker post id, for reverse lookups. */
    public const USER_SPEAKER_META = 'meydan_user_speaker_id';

    /**
     * Pre-1.1.0 keys, when speakers were `meydan_creator` posts. Read as a
     * fallback so an unmigrated install keeps working; never written.
     */
    public const LEGACY_SPEAKER_USER_META = 'meydan_creator_user_id';
    public const LEGACY_USER_SPEAKER_META = 'meydan_speaker_creator_id';

    public static function maybeRun(): void
    {
        if ((string) get_option('meydan_db_version', '') !== self::VERSION) {
            self::run();
        }
    }

    /**
     * Data migrations that need a registered post type.
     *
     * `maybeRun()` fires on `plugins_loaded`, but post types are only
     * registered on the later `init` hook — so `wp_insert_post` for a plugin
     * post type silently fails there. This is hooked to `init` instead, after
     * `Registrations::registerPostTypes()`.
     */
    public static function runDeferred(): void
    {
        // Guarded and cheap: migrateSpeakers() returns immediately once done.
        self::migrateSpeakers();
    }

    public static function run(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'meydan_';
        $sql = [];

        $sql[] = "CREATE TABLE {$p}auth_challenges (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge_id VARCHAR(80) NOT NULL,
            phone_hash CHAR(64) NOT NULL,
            phone_ciphertext TEXT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            purpose VARCHAR(32) NOT NULL DEFAULT 'login',
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            registration_token_hash CHAR(64) NULL,
            registration_expires_at DATETIME NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY challenge_id (challenge_id),
            KEY phone_hash (phone_hash),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            access_token_hash CHAR(64) NOT NULL,
            refresh_token_hash CHAR(64) NOT NULL,
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            device_name VARCHAR(190) NULL,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY access_token_hash (access_token_hash),
            UNIQUE KEY refresh_token_hash (refresh_token_hash),
            KEY user_id (user_id),
            KEY refresh_expires_at (refresh_expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}interactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(64) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_interaction (user_id, object_type, object_id, action),
            KEY object_lookup (object_type, object_id, action),
            KEY user_action (user_id, action)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}narrative_stats (
            narrative_id BIGINT UNSIGNED NOT NULL,
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reposts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (narrative_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}content_stats (
            content_id BIGINT UNSIGNED NOT NULL,
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bookmarks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (content_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}served_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            viewer_type VARCHAR(16) NOT NULL,
            viewer_id VARCHAR(80) NOT NULL,
            narrative_id BIGINT UNSIGNED NOT NULL,
            served_at DATETIME NOT NULL,
            source VARCHAR(48) NULL,
            PRIMARY KEY (id),
            KEY viewer_served (viewer_type, viewer_id, served_at),
            KEY narrative_id (narrative_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}actor_affinity (
            viewer_user_id BIGINT UNSIGNED NOT NULL,
            target_actor_type VARCHAR(16) NOT NULL,
            target_actor_id BIGINT UNSIGNED NOT NULL,
            score DECIMAL(14,6) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (viewer_user_id, target_actor_type, target_actor_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}content_creators (
            content_id BIGINT UNSIGNED NOT NULL,
            creator_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            role_label VARCHAR(190) NULL,
            PRIMARY KEY (content_id, creator_id),
            KEY creator_id (creator_id),
            KEY content_position (content_id, position)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}initiative_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            initiative_id BIGINT UNSIGNED NOT NULL,
            member_type VARCHAR(16) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            guest_id VARCHAR(64) NULL,
            joined_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_member (initiative_id, user_id),
            UNIQUE KEY uniq_guest_member (initiative_id, guest_id),
            KEY initiative_status (initiative_id, status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}square_geo (
            square_id BIGINT UNSIGNED NOT NULL,
            province_id BIGINT UNSIGNED NOT NULL,
            city_id BIGINT UNSIGNED NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            address TEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (square_id),
            KEY province_id (province_id),
            KEY city_id (city_id),
            KEY latitude (latitude),
            KEY longitude (longitude)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}speaker_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            creator_id BIGINT UNSIGNED NOT NULL,
            requester_user_id BIGINT UNSIGNED NULL,
            inviter_user_id BIGINT UNSIGNED NULL,
            speaker_user_id BIGINT UNSIGNED NULL,
            initiative_id BIGINT UNSIGNED NULL,
            requester_name VARCHAR(190) NULL,
            requester_phone VARCHAR(64) NULL,
            venue VARCHAR(255) NOT NULL,
            requested_at DATETIME NOT NULL,
            requested_date DATE NULL,
            requested_time VARCHAR(8) NULL,
            location VARCHAR(255) NULL,
            message TEXT NULL,
            note TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            accepted_at DATETIME NULL,
            decided_at DATETIME NULL,
            internal_note TEXT NULL,
            assigned_manager BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY creator_id (creator_id),
            KEY status (status),
            KEY requester_user_id (requester_user_id),
            KEY inviter_user_id (inviter_user_id),
            KEY speaker_user_id (speaker_user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}uploads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            upload_id VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(190) NOT NULL,
            size BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(64) NOT NULL,
            chunk_size INT UNSIGNED NOT NULL DEFAULT 5242880,
            status VARCHAR(32) NOT NULL DEFAULT 'started',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY upload_id (upload_id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            viewer_type VARCHAR(16) NOT NULL,
            viewer_id VARCHAR(80) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient_user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(64) NOT NULL,
            actor_type VARCHAR(16) NULL,
            actor_id BIGINT UNSIGNED NULL,
            entity_type VARCHAR(64) NULL,
            entity_id BIGINT UNSIGNED NULL,
            parent_entity_type VARCHAR(64) NULL,
            parent_entity_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            deep_link VARCHAR(500) NULL,
            group_key VARCHAR(190) NULL,
            payload_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            archived_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY recipient_created (recipient_user_id, created_at),
            KEY recipient_read (recipient_user_id, read_at),
            KEY group_key (group_key),
            KEY entity_lookup (entity_type, entity_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(80) NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY admin_id (admin_id),
            KEY action (action),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}media_reflections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            narrative_id BIGINT UNSIGNED NOT NULL,
            outlet VARCHAR(190) NOT NULL,
            outlet_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            summary TEXT NULL,
            url VARCHAR(1000) NOT NULL,
            logo_media_id BIGINT UNSIGNED NULL,
            published_at DATETIME NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'published',
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY narrative_position (narrative_id, position),
            KEY outlet_id (outlet_id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}square_schedule (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            square_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            location_label VARCHAR(255) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'published',
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY square_start (square_id, starts_at),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}provinces (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY active_sort (active, sort_order)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}cities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            province_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY province_slug (province_id, slug),
            KEY province_active (province_id, active, sort_order)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}idempotency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner_key VARCHAR(100) NOT NULL,
            route VARCHAR(255) NOT NULL,
            idem_key VARCHAR(190) NOT NULL,
            response_json LONGTEXT NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_idem (owner_key, route, idem_key),
            KEY expires_at (expires_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::seedOptions();
        update_option('meydan_db_version', self::VERSION, false);
    }

    /**
     * Moves existing speaker-tagged `meydan_creator` posts onto the dedicated
     * `meydan_speaker` post type (1.1.0).
     *
     * Only creators with no `meydan_content_creators` rows are moved: a creator
     * cited by content is a content producer and must keep its post id, since
     * that table stores bare ids with no foreign key. The `speaker` term is not
     * a sufficient signal on its own — SeedData blanket-tagged every demo
     * creator with it.
     *
     * Idempotent and replay-safe: `run()` re-executes on every version change.
     */
    private static function migrateSpeakers(): void
    {
        if (get_option('meydan_speaker_split_done', null) !== null) {
            return;
        }

        // Guard against running before the post type exists: `wp_insert_post`
        // would fail and we would record a false "done". Deferred to `init`.
        if (!post_type_exists('meydan_speaker')) {
            return;
        }

        global $wpdb;
        $creators = get_posts([
            'post_type' => 'meydan_creator',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [[
                'taxonomy' => 'meydan_creator_type',
                'field' => 'slug',
                'terms' => 'speaker',
            ]],
        ]);

        $map = [];
        foreach ($creators as $creatorId) {
            $creatorId = (int) $creatorId;

            $contentRefs = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_content_creators WHERE creator_id = %d",
                $creatorId
            ));
            if ($contentRefs > 0) {
                continue;
            }

            $source = get_post($creatorId);
            if (!$source || $source->post_status === 'trash') {
                continue;
            }

            $speakerId = wp_insert_post([
                'post_type' => 'meydan_speaker',
                'post_status' => 'publish',
                'post_title' => $source->post_title,
                'post_content' => $source->post_content,
                'post_date' => $source->post_date,
                'post_author' => (int) $source->post_author,
            ], true);
            if (is_wp_error($speakerId) || !$speakerId) {
                continue;
            }
            $speakerId = (int) $speakerId;

            foreach (['role', 'handle', 'expertise', 'initials', 'verified', 'cities', 'social_links', 'avatar_media_id'] as $key) {
                $value = get_post_meta($creatorId, 'meydan_' . $key, true);
                if ($value !== '' && $value !== null) {
                    update_post_meta($speakerId, 'meydan_' . $key, $value);
                }
            }

            $terms = wp_get_post_terms($creatorId, 'meydan_speaker_category', ['fields' => 'ids']);
            if (!is_wp_error($terms) && $terms) {
                wp_set_post_terms($speakerId, $terms, 'meydan_speaker_category');
            }

            $userId = (int) get_post_meta($creatorId, self::LEGACY_SPEAKER_USER_META, true);
            if ($userId > 0 && get_userdata($userId)) {
                update_post_meta($speakerId, self::SPEAKER_USER_META, $userId);
            }

            // The creator post is left untouched: content may still reference
            // it, and the speaker now lives on the new entity.
            $map[$creatorId] = $speakerId;
        }

        update_option('meydan_speaker_split_v1_map', $map, false);
        update_option('meydan_speaker_split_done', 1, false);

        // Runs exactly once, right after the posts exist, so the reverse
        // usermeta mirror is populated without a per-request scan.
        self::syncSpeakerLinks();
    }

    /**
     * Mirrors the speaker -> user link onto the user so a logged-in speaker can
     * resolve their own profile in one lookup instead of scanning posts.
     */
    private static function syncSpeakerLinks(): void
    {
        $speakerIds = get_posts([
            'post_type' => 'meydan_speaker',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($speakerIds as $speakerId) {
            $userId = (int) get_post_meta((int) $speakerId, self::SPEAKER_USER_META, true);
            if ($userId > 0 && get_userdata($userId)) {
                update_user_meta($userId, self::USER_SPEAKER_META, (int) $speakerId);
            }
        }
    }

    private static function seedOptions(): void
    {
        if (get_option('meydan_feature_flags', null) === null) {
            add_option('meydan_feature_flags', [
                'chat' => false,
                'speaker_requests' => true,
                'initiatives' => true,
                'notifications' => true,
            ], '', false);
        }
        if (get_option('meydan_quick_actions', null) === null) {
            add_option('meydan_quick_actions', [], '', false);
        }
        if (get_option('meydan_ranking', null) === null) {
            add_option('meydan_ranking', [
                'affinity' => 3.0,
                'recency' => 2.1,
                'engagement_quality' => 1.5,
                'locality' => 0.9,
                'media_affinity' => 0.6,
                'media_reflection_boost' => 0.5,
                'initiative_boost' => 0.6,
                'exploration_boost' => 0.4,
                'recently_served_penalty' => 2.0,
                'recency_hours' => 18.0,
                'affinity_half_life_days' => 30.0,
            ], '', false);
        }
        if (get_option('meydan_timeline', null) === null) {
            add_option('meydan_timeline', [
                'batch_size' => 100,
                'pool_following' => 400,
                'pool_interaction' => 250,
                'pool_local' => 200,
                'pool_trending' => 300,
                'pool_exploration' => 100,
                'mix_following' => 0.50,
                'mix_interaction' => 0.25,
                'mix_local_trending' => 0.15,
                'mix_exploration' => 0.10,
                'max_per_actor' => 3,
            ], '', false);
        }
        if (get_option('meydan_trends', null) === null) {
            add_option('meydan_trends', [
                'likes' => 1.0,
                'reposts' => 2.0,
                'comments' => 2.5,
                'shares' => 1.5,
                'windows' => ['1h', '6h', '24h'],
                'manual' => [],
            ], '', false);
        }
        // Merge instead of guarding on null: an already-present but empty option
        // (the state that produced generic «اعلان میدان» copy) must still be filled.
        $existingTemplates = (array) get_option('meydan_notification_templates', []);
        $missingTemplates = array_diff_key(NotificationService::TEMPLATES, $existingTemplates);
        if ($missingTemplates) {
            update_option('meydan_notification_templates', NotificationService::TEMPLATES + $existingTemplates, false);
        }
        if (get_option('meydan_api_settings', null) === null) {
            add_option('meydan_api_settings', [
                'allowed_origins' => [],
                'max_upload_size' => 104857600,
                'allow_guest_speaker_requests' => true,
            ], '', false);
        }
    }
}
