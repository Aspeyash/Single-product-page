<?php
/**
 * Plugin Name:       ZYMARG Cart
 * Plugin URI:        https://zymarg.com
 * Description:       A standalone multi-vendor cart page for ZYMARG marketplace. Overrides the WooCommerce default cart page with a fully featured custom cart — partial checkout, Save for Later (hybrid session/usermeta), per-product coupons, Dokan vendor grouping, sticky totals, and an admin settings panel. Zero Elementor dependency.
 * Version:           2.4.0
 * Author:            ZYMARG
 * Author URI:        https://zymarg.com
 * Text Domain:       zymarg-cart
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * WC requires at least: 9.0
 * WC tested up to:   9.9
 *
 * @package ZymargCart
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── PHP version gate ───────────────────────────────────────────────────────
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				wp_kses_post( sprintf(
					/* translators: 1: Min PHP version. 2: Current PHP version. */
					__( '<strong>ZYMARG Cart</strong> requires PHP <strong>%1$s</strong> or higher. Your server is running PHP <strong>%2$s</strong>.', 'zymarg-cart' ),
					'8.1',
					PHP_VERSION
				) ) .
				'</p></div>';
		}
	);
	return;
}

// ── Constants ──────────────────────────────────────────────────────────────
define( 'ZYMARG_CART_VERSION',  '2.4.0' );
define( 'ZYMARG_CART_FILE',     __FILE__ );
define( 'ZYMARG_CART_PATH',     plugin_dir_path( __FILE__ ) );
define( 'ZYMARG_CART_URL',      plugin_dir_url( __FILE__ ) );
define( 'ZYMARG_CART_BASENAME', plugin_basename( __FILE__ ) );
define( 'ZYMARG_CART_MIN_PHP',  '8.1' );
define( 'ZYMARG_CART_MIN_WP',   '6.0' );
define( 'ZYMARG_CART_MIN_WC',   '9.0' );
define( 'ZYMARG_CART_MIN_DOKAN','3.0.0' );

// ── Load core class ────────────────────────────────────────────────────────
require_once ZYMARG_CART_PATH . 'includes/class-zymarg-cart.php';

// ── Activation / deactivation ──────────────────────────────────────────────
register_activation_hook( __FILE__,   [ 'Zymarg_Cart', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Zymarg_Cart', 'deactivate' ] );

// ── Boot ───────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', [ 'Zymarg_Cart', 'get_instance' ] );
