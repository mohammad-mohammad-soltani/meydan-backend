<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class Migrations
{
    private const VERSION = '1.0.0';
    private const OPTION = 'meydan_eitaa_db_version';

    public static function maybeRun(): void
    {
        if ((string) get_option(self::OPTION, '') !== self::VERSION) {
            self::run();
        }
    }

    public static function run(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $imports = $wpdb->prefix . 'meydan_eitaa_imports';
        $checkpoints = $wpdb->prefix . 'meydan_eitaa_checkpoints';

        dbDelta("CREATE TABLE {$imports} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_key VARCHAR(255) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            channel_id VARCHAR(64) NOT NULL,
            square_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            narrative_id BIGINT UNSIGNED NOT NULL,
            grouped_id VARCHAR(80) NULL,
            message_ids LONGTEXT NOT NULL,
            published_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_key (source_key),
            KEY square_updated (square_id, updated_at),
            KEY narrative_id (narrative_id),
            KEY channel_id (channel_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$checkpoints} (
            square_id BIGINT UNSIGNED NOT NULL,
            last_success_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (square_id),
            KEY last_success_at (last_success_at)
        ) {$charset};");

        update_option(self::OPTION, self::VERSION, false);
    }
}
