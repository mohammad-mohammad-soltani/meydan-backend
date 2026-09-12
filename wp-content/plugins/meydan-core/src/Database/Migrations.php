<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

use Meydan\Core\Notifications\NotificationService;

final class Migrations
{
    public const VERSION = '1.0.2';

    public static function maybeRun(): void
    {
        if ((string) get_option('meydan_db_version', '') !== self::VERSION) {
            self::run();
        }
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
            requester_name VARCHAR(190) NULL,
            requester_phone VARCHAR(64) NULL,
            venue VARCHAR(255) NOT NULL,
            requested_at DATETIME NOT NULL,
            note TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            internal_note TEXT NULL,
            assigned_manager BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY creator_id (creator_id),
            KEY status (status),
            KEY requester_user_id (requester_user_id)
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
