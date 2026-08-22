<?php
/**
 * Plugin Name: Manfaa Cashback
 * Plugin URI: https://manfaa.app/docs/integration-guide.html
 * Description: Pays Manfaa cashback on WooCommerce orders. Buyers enter their Manfaa code on the cart; cashback is posted when the order reaches the status you choose and reversed on cancellation or refund.
 * Version: 0.3.1
 * Author: Manfaa
 * Author URI: https://manfaa.app
 * Text Domain: manfaa-cashback
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 * License: GPLv2 or later
 *
 * This file must stay parseable by PHP 7.4: it is the only code that runs
 * before the PHP and WooCommerce version gates below, and a parse error
 * here would take the whole site down instead of showing a notice.
 */

defined( 'ABSPATH' ) || exit;

define( 'MANFAA_CASHBACK_VERSION', '0.3.1' );
define( 'MANFAA_CASHBACK_FILE', __FILE__ );
define( 'MANFAA_CASHBACK_DIR', __DIR__ );
define( 'MANFAA_CASHBACK_MIN_WC', '9.0' );
define( 'MANFAA_CASHBACK_MIN_PHP', '8.1' );

/**
 * Declared in before_woocommerce_init, as WooCommerce requires: the plugin
 * stores everything through the order object and the CRUD API, so HPOS is
 * fully supported, and the cart/checkout panel is a real inner block.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		$problem = null;

		if ( version_compare( PHP_VERSION, MANFAA_CASHBACK_MIN_PHP, '<' ) ) {
			$problem = sprintf( 'Manfaa Cashback needs PHP %s or newer; this site runs %s.', MANFAA_CASHBACK_MIN_PHP, PHP_VERSION );
		} elseif ( ! defined( 'WC_VERSION' ) ) {
			$problem = 'Manfaa Cashback needs WooCommerce to be installed and active.';
		} elseif ( version_compare( WC_VERSION, MANFAA_CASHBACK_MIN_WC, '<' ) ) {
			$problem = sprintf( 'Manfaa Cashback needs WooCommerce %s or newer; this site runs %s.', MANFAA_CASHBACK_MIN_WC, WC_VERSION );
		}

		if ( $problem !== null ) {
			add_action(
				'admin_notices',
				function () use ( $problem ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $problem ) . '</p></div>';
				}
			);

			return;
		}

		require_once __DIR__ . '/src/Autoloader.php';
		\Manfaa\Cashback\Autoloader::register();
		\Manfaa\Cashback\Plugin::instance()->boot();
	},
	// Before WooCommerce's own plugins_loaded(10), which is where the Blocks
	// package boots and fires `woocommerce_blocks_loaded` — the hook the
	// Store API extension below must already be listening on. Every plugin
	// file is included by the time plugins_loaded runs, so WC_VERSION is
	// defined here whatever the alphabetical load order.
	5
);

register_activation_hook( __FILE__, function () {
	// Remembered so "only orders placed after activation" has a clock to
	// compare against; never overwritten by a re-activation.
	if ( ! get_option( 'manfaa_cashback_activated_at' ) ) {
		add_option( 'manfaa_cashback_activated_at', time(), '', false );
	}
} );
