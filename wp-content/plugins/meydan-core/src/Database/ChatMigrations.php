<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

final class ChatMigrations
{
    private const VERSION = '1.0.0';

    public static function maybeRun(): void
    {
        if ((string) get_option('meydan_chat_db_version', '') !== self::VERSION) {
            self::run();
        }
    }

    public static function run(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'meydan_chat_';

        dbDelta("CREATE TABLE {$p}conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(16) NOT NULL DEFAULT 'direct',
            direct_key VARCHAR(64) NULL,
            title VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            last_message_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY direct_key (direct_key),
            KEY updated_at (updated_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}participants (
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            joined_at DATETIME NOT NULL,
            last_read_message_id BIGINT UNSIGNED NULL,
            archived_at DATETIME NULL,
            PRIMARY KEY (conversation_id, user_id),
            KEY user_id (user_id),
            KEY archived_at (archived_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_user_id BIGINT UNSIGNED NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            body LONGTEXT NOT NULL,
            reply_to_id BIGINT UNSIGNED NULL,
            forwarded_from_message_id BIGINT UNSIGNED NULL,
            attachment_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            edited_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY sender_client (sender_user_id, client_id),
            KEY conversation_message (conversation_id, id),
            KEY sender_user_id (sender_user_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}reactions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            reaction VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id, reaction),
            KEY user_id (user_id)
        ) {$charset};");

        update_option('meydan_chat_db_version', self::VERSION, false);
    }
}
