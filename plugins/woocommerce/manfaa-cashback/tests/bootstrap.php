<?php
/**
 * PHPUnit bootstrap: the WordPress test library (wp-phpunit) with
 * WooCommerce loaded as a plugin and installed, then this plugin.
 *
 * Needs WP_TESTS_DIR-style config from the environment:
 *   MANFAA_WP_DIR   path to a WordPress checkout (the dev store)
 *   MANFAA_DB_*     test database (dropped and recreated on every run)
 */

declare(strict_types=1);

$wpDir = getenv('MANFAA_WP_DIR') ?: dirname(__DIR__, 2).'/dev-site';
$testsDir = getenv('WP_PHPUNIT__DIR') ?: __DIR__.'/../vendor/wp-phpunit/wp-phpunit';

if (! is_dir($wpDir) || ! is_dir($testsDir)) {
    fwrite(STDERR, "WordPress ($wpDir) or wp-phpunit ($testsDir) not found.\n");
    exit(1);
}

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');
putenv('MANFAA_WP_DIR='.$wpDir);

require_once __DIR__.'/../vendor/autoload.php';
require_once $testsDir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($wpDir): void {
    require $wpDir.'/wp-content/plugins/woocommerce/woocommerce.php';
    require dirname(__DIR__).'/manfaa-cashback.php';
});

// WooCommerce's own bootstrap does the same: install once the theme is set
// up, so the tables, roles and capabilities exist before the first test.
tests_add_filter('setup_theme', static function (): void {
    define('WP_UNINSTALL_PLUGIN', true);
    define('WC_REMOVE_ALL_DATA', true);
    update_option('woocommerce_currency', 'MVR');

    // MANFAA_HPOS=1 runs the whole suite on High-Performance Order Storage.
    if (getenv('MANFAA_HPOS') === '1') {
        update_option('woocommerce_custom_orders_table_enabled', 'yes');
        update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
    }

    WC_Install::install();
    $GLOBALS['wp_roles'] = null;
    wp_roles();
    update_option('manfaa_cashback_activated_at', 1);
});

require $testsDir.'/includes/bootstrap.php';
