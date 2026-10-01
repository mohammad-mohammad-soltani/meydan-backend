<?php

declare(strict_types=1);

namespace Meydan\Core;

use Meydan\Core\Admin\Admin;
use Meydan\Core\Admin\LocationPicker;
use Meydan\Core\Admin\ManualSquare;
use Meydan\Core\Auth\SessionService;
use Meydan\Core\Auth\GuestSessionService;
use Meydan\Core\Database\ChatMigrations;
use Meydan\Core\Database\Migrations;
use Meydan\Core\Database\PushMigrations;
use Meydan\Core\Domain\NarrativeCleanup;
use Meydan\Core\Domain\Registrations;
use Meydan\Core\Domain\UserAccess;
use Meydan\Core\Integrations\Eitaa\AdminPage;
use Meydan\Core\Integrations\Bale\AdminPage as BaleAdminPage;
use Meydan\Core\Integrations\Bale\Migrations as BaleMigrations;
use Meydan\Core\Integrations\Bale\Controller as BaleSyncController;
use Meydan\Core\Integrations\Eitaa\Migrations as EitaaMigrations;
use Meydan\Core\Integrations\Eitaa\SquareChannelField;
use Meydan\Core\Integrations\Bale\ErrorReporter as BaleErrorReporter;
use Meydan\Core\Integrations\Bale\EventSubscriber as BaleEventSubscriber;
use Meydan\Core\Integrations\Bale\WebhookController as BaleWebhookController;
use Meydan\Core\Notifications\AsyncDispatcher;
use Meydan\Core\Notifications\NativeExpoPush;
use Meydan\Core\Rest\ChatRoutes;
use Meydan\Core\Rest\PushRoutes;
use Meydan\Core\Rest\Routes;
use Meydan\Core\Support\ApiMiddleware;
use Meydan\Core\Support\CampaignCurrentGuard;
use Meydan\Core\Support\Cors;
use Meydan\Core\Support\GoodAction;
use Meydan\Core\Support\RootResponse;
use Meydan\Core\Support\SquareActivity;
use Meydan\Core\Support\UserEmails;
use Meydan\Core\Uploads\UploadCache;
use Meydan\Core\Storage\WordPressMediaHooks;
use Meydan\Core\Storage\VideoPosterBackfill;
use Meydan\Core\Timeline\NarrativeFeatureRefreshCron;

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
        ChatMigrations::run();
        PushMigrations::run();
        EitaaMigrations::run();
        BaleMigrations::run();
        Registrations::registerRolesAndCapabilities();
        Registrations::registerPostTypes();
        Registrations::registerTaxonomies();
        flush_rewrite_rules();
        UploadCache::ensure();
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
        ChatMigrations::maybeRun();
        PushMigrations::maybeRun();
        NativeExpoPush::register();
        AsyncDispatcher::register();
        EitaaMigrations::maybeRun();
        BaleMigrations::maybeRun();

        add_action('init', [Registrations::class, 'registerPostTypes']);
        add_action('init', [Registrations::class, 'registerTaxonomies']);
        add_action('init', [Registrations::class, 'registerRolesAndCapabilities'], 20);
        // Data migrations need the post types registered above, so they cannot
        // run from maybeRun() on plugins_loaded.
        add_action('init', [Migrations::class, 'runDeferred'], 25);
        NarrativeCleanup::register();
        \Meydan\Core\Support\Quotes::register();

        add_filter('determine_current_user', [SessionService::class, 'authenticateBearer'], 30);
        add_filter('determine_current_user', [UserAccess::class, 'currentUser'], 99);
        add_filter('wp_authenticate_user', [UserAccess::class, 'allowWordPressLogin'], 99);
        add_action('init', [GuestSessionService::class, 'ensureGuestCookie'], 1);
        // Uploads are immutable by filename; keep their long-lived cache rule in place.
        add_action('init', [UploadCache::class, 'ensure'], 5);
        WordPressMediaHooks::register();
        VideoPosterBackfill::register();
        NarrativeFeatureRefreshCron::register();

        CampaignCurrentGuard::register();
        GoodAction::register();
        RootResponse::register();
        add_action('rest_api_init', [Routes::class, 'register']);
        add_action('rest_api_init', [ChatRoutes::class, 'register']);
        add_action('rest_api_init', [PushRoutes::class, 'register']);
        add_action('rest_api_init', static fn() => \Meydan\Core\Integrations\Eitaa\Controller::register());
        add_action('rest_api_init', [BaleSyncController::class, 'register']);
        add_action('rest_api_init', [BaleWebhookController::class, 'register']);
        ApiMiddleware::register();
        Cors::register();

        // Bale operational alerting: project errors (requirement 2) and
        // pending-square approval buttons (requirement 3). The reporter is
        // armed early so it also captures errors raised later in this request.
        BaleErrorReporter::register();
        BaleEventSubscriber::register();
        add_action('meydan_bale_pending_square', [BaleEventSubscriber::class, 'notifyPending'], 10, 1);

        if (is_admin()) {
            Admin::instance()->register();
            AdminPage::register();
            BaleAdminPage::register();
            SquareChannelField::register();
            SquareActivity::registerAdmin();
            ManualSquare::register();
            LocationPicker::register();
        }

        // Internal accounts never authenticate by email, but WordPress refuses
        // to save the user edit screen while the address is empty. Keep one set
        // for every account and repair the ones created before this rule.
        UserEmails::register();
        UserEmails::maybeBackfill();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('meydan', \Meydan\Core\Support\CliCommand::class);
        }
    }
}
