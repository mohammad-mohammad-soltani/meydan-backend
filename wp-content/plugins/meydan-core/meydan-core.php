<?php
/**
 * Plugin Name: Meydan Core
 * Plugin URI: https://github.com/mohammad-mohammad-soltani/meydan-backend
 * Description: Headless WordPress backend and REST API for the Meydan project.
 * Version: 1.0.0
 * Author: محمد محمد سلطانی
 * Text Domain: meydan-core
 * Requires at least: 7.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MEYDAN_CORE_VERSION', '1.0.0');
define('MEYDAN_CORE_FILE', __FILE__);
define('MEYDAN_CORE_DIR', plugin_dir_path(__FILE__));

$meydanComposerAutoload = MEYDAN_CORE_DIR . 'vendor/autoload.php';
if (is_readable($meydanComposerAutoload)) {
    require_once $meydanComposerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Meydan\\Core\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = MEYDAN_CORE_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, ['Meydan\\Core\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Meydan\\Core\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Meydan\Core\Plugin::instance()->boot();
});
