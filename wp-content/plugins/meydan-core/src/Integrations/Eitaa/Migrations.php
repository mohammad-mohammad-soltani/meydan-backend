<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

final class Migrations
{
    private const VERSION = '1.1.0';
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

        self::repairImportedLineBreaks();
        update_option(self::OPTION, self::VERSION, false);
    }

    /**
     * Repairs HTML produced by the old Eitaa renderer.
     *
     * nl2br(false) inserted <br> but kept the source newline, so the stored
     * value contained pairs such as "<br>\n". The frontend converts <br> back
     * to a newline, which made every source line break appear twice. Restrict
     * this one-time repair to narratives explicitly marked as Eitaa imports.
     */
    private static function repairImportedLineBreaks(): void
    {
        global $wpdb;

        $posts = $wpdb->posts;
        $meta = $wpdb->postmeta;

        $wpdb->query(
            "UPDATE {$posts} AS p
             INNER JOIN {$meta} AS pm
                ON pm.post_id = p.ID
               AND pm.meta_key = 'meydan_import_source'
               AND pm.meta_value = 'eitaa'
             SET p.post_content =
                 REPLACE(
                   REPLACE(
                     REPLACE(
                       REPLACE(
                         REPLACE(
                           REPLACE(p.post_content,
                             CONCAT('<br>', CHAR(13), CHAR(10)), '<br>'),
                           CONCAT('<br>', CHAR(10)), '<br>'),
                         CONCAT('<br>', CHAR(13)), '<br>'),
                       CONCAT('<br />', CHAR(13), CHAR(10)), '<br />'),
                     CONCAT('<br />', CHAR(10)), '<br />'),
                   CONCAT('<br />', CHAR(13)), '<br />')
             WHERE p.post_type = 'meydan_narrative'
               AND (
                    p.post_content LIKE CONCAT('%<br>', CHAR(10), '%')
                 OR p.post_content LIKE CONCAT('%<br>', CHAR(13), '%')
                 OR p.post_content LIKE CONCAT('%<br />', CHAR(10), '%')
                 OR p.post_content LIKE CONCAT('%<br />', CHAR(13), '%')
               )"
        );
    }
}
