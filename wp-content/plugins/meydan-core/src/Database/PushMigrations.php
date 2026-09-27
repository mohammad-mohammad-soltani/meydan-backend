<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

final class PushMigrations
{
    private const VERSION = '1.1.0';
    private const OPTION = 'meydan_push_db_version';

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

        $table = $wpdb->prefix . 'meydan_push_subscriptions';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_success_at DATETIME NULL,
            failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_id (user_id),
            KEY updated_at (updated_at)
        ) {$charset};";

        dbDelta($sql);

        $native = $wpdb->prefix . 'meydan_native_push_tokens';
        dbDelta("CREATE TABLE {$native} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token VARCHAR(600) NOT NULL,
            platform VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_success_at DATETIME NULL,
            failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id),
            KEY updated_at (updated_at)
        ) {$charset};");
        update_option(self::OPTION, self::VERSION, false);
    }
}
