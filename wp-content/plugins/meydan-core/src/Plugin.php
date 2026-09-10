<?php

declare(strict_types=1);

namespace Meydan\Core;

use Meydan\Core\Admin\Admin;
use Meydan\Core\Auth\SessionService;
use Meydan\Core\Auth\GuestSessionService;
use Meydan\Core\Database\Migrations;
use Meydan\Core\Domain\Registrations;
use Meydan\Core\Rest\Routes;
use Meydan\Core\Support\ApiMiddleware;
use Meydan\Core\Support\Cors;
use Meydan\Core\Support\GoodAction;
use Meydan\Core\Support\SquareActivity;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function activate(): void
    {
        Migrations::run();
        Registrations::registerRolesAndCapabilities();
        Registrations::registerPostTypes();
        Registrations::registerTaxonomies();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        Migrations::maybeRun();

        add_action('init', [Registrations::class, 'registerPostTypes']);
        add_action('init', [Registrations::class, 'registerTaxonomies']);
        add_action('init', [Registrations::class, 'registerRolesAndCapabilities'], 20);

        add_filter('determine_current_user', [SessionService::class, 'authenticateBearer'], 30);
        add_action('init', [GuestSessionService::class, 'ensureGuestCookie'], 1);

        GoodAction::register();
        add_action('rest_api_init', [Routes::class, 'register']);
        ApiMiddleware::register();
        Cors::register();

        if (is_admin()) {
            Admin::instance()->register();
            SquareActivity::registerAdmin();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('meydan', \Meydan\Core\Support\CliCommand::class);
        }
    }
}
