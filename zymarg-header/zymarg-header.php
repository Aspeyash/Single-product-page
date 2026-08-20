<?php
/**
 * Plugin Name: ZYMARG Header
 * Plugin URI:  https://zymarg.com.bd
 * Description: Standalone site header for ZYMARG — two-row layout (top bar + header bar), sticky on scroll, responsive. Self-contained cart mini-panel (live sync, remove, qty stepper, full admin controls). Integrates with ZYMARG Search System and WCPG Wishlist when active. No dependency on Theme Builder.
 * Version:     1.4.0
 * Author:      ZYMARG
 * Author URI:  https://zymarg.com.bd
 * Text Domain: zymarg-header
 * Domain Path: /languages
 * ZYMARG Plugin: true
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 *
 * @package ZymargHeader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZYMARG_HEADER_VERSION', '1.4.0' );
define( 'ZYMARG_HEADER_FILE',    __FILE__ );
define( 'ZYMARG_HEADER_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ZYMARG_HEADER_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Load the plugin after all plugins are loaded so class_exists() checks
 * against Search System and WCPG resolve correctly.
 * Priority 10 — intentionally before Theme Builder (priority 20) so
 * Cart_Ajax::register_hooks() fires early enough to hook into WC AJAX.
 */
add_action( 'plugins_loaded', static function () {
	require_once ZYMARG_HEADER_DIR . 'includes/class-settings.php';
	require_once ZYMARG_HEADER_DIR . 'includes/class-display-conditions.php'; // v1.1.17
	require_once ZYMARG_HEADER_DIR . 'includes/class-cart-ajax.php'; // v1.1.0: own cart engine
	require_once ZYMARG_HEADER_DIR . 'includes/class-assets.php';
	require_once ZYMARG_HEADER_DIR . 'includes/class-renderer.php';
	require_once ZYMARG_HEADER_DIR . 'includes/class-header.php';
	require_once ZYMARG_HEADER_DIR . 'includes/class-admin.php';

	ZymargHeader\Header::instance();
	ZymargHeader\Admin::instance();
}, 10 );
