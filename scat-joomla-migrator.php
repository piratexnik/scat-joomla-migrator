<?php

declare(strict_types=1);

/**
 * Plugin Name: SCaT Joomla 1.5 to WordPress Content Migrator
 * Description: Інструмент міграції контенту (статті, новини, категорії, WebP-зображення) з Joomla 1.5 на WordPress + Polylang.
 * Version: 1.12
 * Author: SCaT
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: scat-joomla-migrator
 */

defined('ABSPATH') || exit;

define('SCAT_MIGRATOR_VERSION', '1.12');
define('SCAT_MIGRATOR_FILE', __FILE__);
define('SCAT_MIGRATOR_BASENAME', plugin_basename(__FILE__));
define('SCAT_MIGRATOR_DIR', plugin_dir_path(__FILE__));
define('SCAT_MIGRATOR_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'ScatMigrator\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = SCAT_MIGRATOR_DIR . 'includes/' . str_replace('\\', '/', $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

add_action('plugins_loaded', static function (): void {
    if (is_admin() || wp_doing_cron()) {
        (new ScatMigrator\Updater())->init();
    }

    if (is_admin()) {
        (new ScatMigrator\AdminPage())->init();
        (new ScatMigrator\ImageMigrator())->init();
        (new ScatMigrator\ArchiveBrowser())->init();
        (new ScatMigrator\DatabaseAnalyzer())->init();
    }

    (new ScatMigrator\ContentMigrator())->init();
});
