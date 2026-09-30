<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

use Meydan\Core\Domain\SpeakerService;
use Meydan\Core\Notifications\NotificationService;

final class Migrations
{
    public const VERSION = '1.4.4';

    /**
     * Legacy speaker-post meta holding the linked user id.
     *
     * Pre-1.2.0 keys, read only by the speaker-to-user migration; speakers are
     * users now, so the reverse user meta is deleted as part of that migration.
     */
    public const SPEAKER_USER_META = 'meydan_speaker_user_id';

    /** @deprecated Legacy user meta mirroring a speaker post id. */
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
     * Data migrations that need registered post types or roles.
     *
     * `maybeRun()` fires on `plugins_loaded`, but post types and the speaker
     * role are only available on the later `init` hook, so `wp_insert_user` for
     * a speaker account would fail there. This is hooked to `init` instead,
     * after `Registrations`.
     */
    public static function runDeferred(): void
    {
        // Guarded and cheap: migrateSpeakerUsers() returns immediately once done.
        self::migrateSpeakerUsers();
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
            refresh_expires_at DATETIME NULL,
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

        $sql[] = "CREATE TABLE {$p}report_days (
            report_date DATE NOT NULL,
            title VARCHAR(255) NULL,
            subtitle VARCHAR(255) NULL,
            description TEXT NULL,
            text_color CHAR(7) NULL,
            background_color CHAR(7) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (report_date)
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
            storage_scope VARCHAR(32) NULL,
            storage_owner VARCHAR(190) NULL,
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

        // Pre-scored, viewer-independent Timeline ranking features. One row per
        // published narrative, kept warm by Timeline\NarrativeFeatureRefreshCron
        // and nudged incrementally by Stats::incrementNarrative(), so
        // Timeline\FeatureHydrator reads a ranking-ready row per candidate
        // instead of recomputing ~10 queries per candidate on every request.
        $sql[] = "CREATE TABLE {$p}narrative_features (
            narrative_id BIGINT UNSIGNED NOT NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT '',
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            city_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            province_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            has_media TINYINT UNSIGNED NOT NULL DEFAULT 0,
            initiative_boost TINYINT UNSIGNED NOT NULL DEFAULT 0,
            media_reflection_boost TINYINT UNSIGNED NOT NULL DEFAULT 0,
            post_date_gmt DATETIME NOT NULL,
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reposts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
            computed_at DATETIME NOT NULL,
            PRIMARY KEY (narrative_id),
            KEY actor (actor_type, actor_id)
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

        self::repairSessionSchema();

        self::seedOptions();
        update_option('meydan_db_version', self::VERSION, false);
    }

    /**
     * dbDelta does not reliably relax an existing NOT NULL column. Native
     * sessions deliberately have no refresh expiry, so repair pre-1.4.2
     * installs explicitly before any session can be issued.
     */
    private static function repairSessionSchema(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_sessions';
        $refreshExpiry = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'refresh_expires_at'");
        if ($refreshExpiry && strtoupper((string) $refreshExpiry->Null) !== 'YES') {
            $wpdb->query("ALTER TABLE {$table} MODIFY refresh_expires_at DATETIME NULL");
        }

        $persistentDevice = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'persistent_device'");
        if (!$persistentDevice) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN persistent_device TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    /**
     * Turns speaker posts into speaker accounts (1.2.0).
     *
     * A speaker is now a user holding the `meydan_speaker` role, so the curated
     * profile moves off the post onto the account and the post type is retired.
     * Two sources are handled: speaker posts left by the 1.1.0 split, and — on
     * installs where that split never ran — the speaker-tagged creators with no
     * `meydan_content_creators` rows. A creator cited by content is a content
     * producer and must keep its post id, since that table stores bare ids.
     *
     * Idempotent and replay-safe: the recorded option short-circuits later runs.
     *
     * `$force` bypasses that guard so the seeder can import demo speakers after
     * it has created the creators and their content links.
     */
    public static function migrateSpeakerUsers(bool $force = false): void
    {
        if (!$force && get_option('meydan_speaker_users_migrated', null) !== null) {
            return;
        }

        $sources = get_posts([
            'post_type' => 'meydan_speaker',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $legacyCreators = !$sources;
        if ($legacyCreators) {
            $sources = get_posts([
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
        }

        global $wpdb;
        // Previous run's post => user map, so a forced re-run reuses the account
        // it already created instead of minting a duplicate.
        $existing = (array) get_option('meydan_speaker_user_map', []);
        $map = [];
        foreach ($sources as $postId) {
            $postId = (int) $postId;
            if ($legacyCreators) {
                $contentRefs = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}meydan_content_creators WHERE creator_id = %d",
                    $postId
                ));
                if ($contentRefs > 0) {
                    continue;
                }
            }

            $source = get_post($postId);
            if (!$source || $source->post_status === 'trash') {
                continue;
            }

            $userId = (int) ($existing[$postId] ?? 0);
            if ($userId <= 0 || !get_userdata($userId)) {
                $userId = (int) get_post_meta($postId, self::SPEAKER_USER_META, true);
            }
            if ($userId <= 0 || !get_userdata($userId)) {
                $userId = (int) get_post_meta($postId, self::LEGACY_SPEAKER_USER_META, true);
            }
            if ($userId <= 0 || !get_userdata($userId)) {
                $userId = self::createSpeakerUser($source);
            }
            if ($userId <= 0) {
                continue;
            }

            self::applySpeakerProfile($userId, $source);
            $map[$postId] = $userId;
        }

        self::repointSpeakerRequests($map);
        // Merge so a forced seeder run keeps the entries of an earlier run.
        update_option('meydan_speaker_user_map', $map + $existing, false);
        update_option('meydan_speaker_users_migrated', 1, false);
    }

    /** Creates the account that now backs a migrated speaker profile. */
    private static function createSpeakerUser(\WP_Post $source): int
    {
        if (!function_exists('wp_insert_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $userId = wp_insert_user([
            'user_login' => 'meydan_internal_' . strtolower(wp_generate_password(20, false, false)),
            'user_pass' => wp_generate_password(64, true, true),
            'display_name' => $source->post_title !== '' ? $source->post_title : 'سخنران',
            'role' => SpeakerService::ROLE,
        ]);

        return is_wp_error($userId) ? 0 : (int) $userId;
    }

    /**
     * Copies the curated post profile onto the account.
     *
     * Existing user meta wins, so a linked account that already filled a field
     * in wp-admin is never overwritten by the older post copy.
     */
    private static function applySpeakerProfile(int $userId, \WP_Post $source): void
    {
        $user = get_userdata($userId);
        $roles = $user ? (array) $user->roles : [];
        // An administrator or square is never rewritten: the profile copy is
        // still useful, but the account type must follow the role it keeps.
        $eligible = $user && !in_array('administrator', $roles, true) && !in_array('meydan_square', $roles, true);
        if ($eligible && !in_array(SpeakerService::ROLE, $roles, true)) {
            $user->set_role(SpeakerService::ROLE);
        }
        if ($eligible) {
            update_user_meta($userId, 'meydan_account_type', 'speaker');
        }

        if ((string) get_user_meta($userId, 'meydan_full_name', true) === '') {
            update_user_meta($userId, 'meydan_full_name', $source->post_title !== '' ? $source->post_title : ($user?->display_name ?: 'سخنران'));
        }
        if ($source->post_content !== '' && (string) get_user_meta($userId, 'meydan_about', true) === '') {
            update_user_meta($userId, 'meydan_about', wp_kses_post($source->post_content));
        }

        foreach (['role', 'handle', 'expertise', 'initials', 'verified', 'cities', 'social_links', 'avatar_media_id'] as $key) {
            $value = get_post_meta($source->ID, 'meydan_' . $key, true);
            if ($value !== '' && $value !== null && get_user_meta($userId, 'meydan_' . $key, true) === '') {
                update_user_meta($userId, 'meydan_' . $key, $value);
            }
        }

        $categories = self::sourceCategories($source->ID);
        if ($categories && !get_user_meta($userId, 'meydan_speaker_categories', true)) {
            update_user_meta($userId, 'meydan_speaker_categories', $categories);
        }

        // The reverse post link belonged to the post-backed model.
        delete_user_meta($userId, self::USER_SPEAKER_META);
        delete_user_meta($userId, self::LEGACY_USER_SPEAKER_META);
    }

    /**
     * Category slugs of a legacy speaker post.
     *
     * Read with a direct query because the taxonomy is no longer registered by
     * the time this migration runs.
     *
     * @return array<int,string>
     */
    private static function sourceCategories(int $postId): array
    {
        global $wpdb;
        $slugs = $wpdb->get_col($wpdb->prepare(
            "SELECT t.slug FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id = %d AND tt.taxonomy = %s",
            $postId,
            SpeakerService::SPEAKER_CATEGORY_TAXONOMY
        ));

        return array_values(array_filter(array_map('sanitize_key', $slugs ?: [])));
    }

    /**
     * Repoints legacy request rows from the speaker post id to the account id.
     *
     * `creator_id` and `speaker_user_id` both hold the speaker user id once the
     * row is migrated, matching the columns' meaning after 1.2.0.
     *
     * @param array<int,int> $map post id => user id
     */
    private static function repointSpeakerRequests(array $map): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_speaker_requests';
        $rows = $wpdb->get_results("SELECT id, creator_id, speaker_user_id FROM {$table}", ARRAY_A);

        foreach ($rows ?: [] as $row) {
            $creatorId = (int) $row['creator_id'];
            $speakerUserId = (int) $row['speaker_user_id'];
            if ($speakerUserId > 0 && get_userdata($speakerUserId) && self::isSpeakerAccount($speakerUserId)) {
                continue;
            }

            $userId = (int) ($map[$creatorId] ?? 0);
            if ($userId <= 0 && self::isSpeakerAccount($creatorId)) {
                $userId = $creatorId;
            }
            if ($userId <= 0) {
                continue;
            }

            $wpdb->update($table, [
                'creator_id' => $userId,
                'speaker_user_id' => $userId,
            ], ['id' => (int) $row['id']]);
        }
    }

    /** Role-based speaker check that does not depend on the Actor helper. */
    private static function isSpeakerAccount(int $userId): bool
    {
        $user = $userId > 0 ? get_userdata($userId) : false;
        return (bool) ($user && in_array(SpeakerService::ROLE, (array) $user->roles, true));
    }

    private static function seedOptions(): void
    {
        if (get_option('meydan_speaker_category_options', null) === null) {
            $categories = SpeakerService::SPEAKER_CATEGORIES;
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", 'meydan_speaker_categories'));
            foreach ($ids as $id) {
                foreach ((array) get_user_meta((int) $id, 'meydan_speaker_categories', true) as $slug) {
                    $slug = sanitize_key((string) $slug);
                    if ($slug !== '' && !isset($categories[$slug])) $categories[$slug] = $slug;
                }
            }
            add_option('meydan_speaker_category_options', $categories, '', false);
        }
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
