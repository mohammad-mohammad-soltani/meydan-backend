<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Database\Migrations;
use Meydan\Core\Domain\Registrations;

final class CliCommand
{
    /** Run/repair database migrations. */
    public function migrate(): void
    {
        Migrations::run();
        Registrations::registerRolesAndCapabilities();
        \WP_CLI::success('Meydan migrations completed: ' . Migrations::VERSION);
    }

    /** Print backend status. */
    public function status(): void
    {
        global $wpdb;
        $tables = [
            'auth_challenges', 'sessions', 'interactions', 'narrative_stats', 'content_stats',
            'served_history', 'actor_affinity', 'content_creators', 'initiative_members',
            'square_geo', 'speaker_requests', 'uploads', 'events', 'notifications', 'audit_log',
            'media_reflections', 'square_schedule', 'provinces', 'cities', 'idempotency',
        ];
        $missing = [];
        foreach ($tables as $table) {
            $name = $wpdb->prefix . 'meydan_' . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                $missing[] = $name;
            }
        }
        \WP_CLI::log('Meydan Core ' . MEYDAN_CORE_VERSION);
        \WP_CLI::log('DB version: ' . get_option('meydan_db_version', 'none'));
        \WP_CLI::log('Chat feature: ' . (((array) get_option('meydan_feature_flags', []))['chat'] ?? false ? 'enabled' : 'disabled'));
        if ($missing) {
            \WP_CLI::error('Missing tables: ' . implode(', ', $missing));
        }
        \WP_CLI::success('All Meydan tables are present.');
    }
}
