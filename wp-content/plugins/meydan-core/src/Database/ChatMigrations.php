<?php

declare(strict_types=1);

namespace Meydan\Core\Database;

final class ChatMigrations
{
    private const VERSION = '1.2.0';

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
            notifications_muted TINYINT(1) NOT NULL DEFAULT 0,
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

        self::createWorkTables($p, $charset);
        self::repairWorkSchema($p);

        update_option('meydan_chat_db_version', self::VERSION, false);
    }

    /**
     * Work groups ("کارها") reuse the chat conversation/participant/message
     * tables with type='work' and add these side tables. Every table has a
     * composite primary key, plus a user_id key where a "mine" filter needs it.
     */
    private static function createWorkTables(string $p, string $charset): void
    {
        dbDelta("CREATE TABLE {$p}message_mentions (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY user_message (user_id, message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}message_audience (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY user_message (user_id, message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}work_task_people (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT 'assignee',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY user_message (user_id, message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}work_task_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            done_by BIGINT UNSIGNED NULL,
            sort INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY message_sort (message_id, sort)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}work_meeting_rsvps (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            response VARCHAR(8) NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY user_message (user_id, message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}work_announcement_seen (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            seen_at DATETIME NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY user_message (user_id, message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$p}work_poll_votes (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            option_index SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (message_id, user_id),
            KEY message_option (message_id, option_index)
        ) {$charset};");
    }

    /**
     * dbDelta cannot reliably add columns/keys to a live table, so the work
     * columns are added with explicit, idempotent ALTERs.
     */
    private static function repairWorkSchema(string $p): void
    {
        global $wpdb;

        $columns = static function (string $table) use ($wpdb): array {
            return array_map('strtolower', $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0) ?: []);
        };
        $keys = static function (string $table) use ($wpdb): array {
            return array_map('strtolower', $wpdb->get_col("SHOW INDEX FROM {$table}", 2) ?: []);
        };
        $addColumns = static function (string $table, array $defs) use ($wpdb, $columns): void {
            $have = $columns($table);
            foreach ($defs as $name => $ddl) {
                if (!in_array($name, $have, true)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$ddl}");
                }
            }
        };
        $addKeys = static function (string $table, array $defs) use ($wpdb, $keys): void {
            $have = $keys($table);
            foreach ($defs as $name => $ddl) {
                if (!in_array($name, $have, true)) {
                    $wpdb->query("ALTER TABLE {$table} ADD {$ddl}");
                }
            }
        };

        $conversations = "{$p}conversations";
        $addColumns($conversations, [
            'initiative_id' => 'BIGINT UNSIGNED NULL',
            'description' => 'TEXT NULL',
            'avatar_media_id' => 'BIGINT UNSIGNED NULL',
        ]);
        $addKeys($conversations, [
            'initiative_id' => 'UNIQUE KEY initiative_id (initiative_id)',
            'type_updated' => 'KEY type_updated (type, updated_at, id)',
        ]);

        $participants = "{$p}participants";
        $addColumns($participants, ['role' => "VARCHAR(16) NOT NULL DEFAULT 'member'"]);
        $addKeys($participants, ['conversation_role' => 'KEY conversation_role (conversation_id, role)']);

        $messages = "{$p}messages";
        $addColumns($messages, [
            'kind' => "VARCHAR(16) NOT NULL DEFAULT 'text'",
            'payload_json' => 'LONGTEXT NULL',
            'is_private' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'pinned_at' => 'DATETIME NULL',
            'thread_root_id' => 'BIGINT UNSIGNED NULL',
            'due_at' => 'DATETIME NULL',
            'task_status' => 'VARCHAR(8) NULL',
        ]);
        $addKeys($messages, [
            'conversation_kind' => 'KEY conversation_kind (conversation_id, kind, id)',
            'conversation_pinned' => 'KEY conversation_pinned (conversation_id, pinned_at)',
            'reply_to' => 'KEY reply_to (reply_to_id)',
            'thread_root' => 'KEY thread_root (thread_root_id)',
            'task_status_due' => 'KEY task_status_due (kind, task_status, due_at)',
        ]);
    }
}
