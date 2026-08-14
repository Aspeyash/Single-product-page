<?php
/**
 * Plugin Name:       ZYMARG Store Page
 * Plugin URI:        https://zymarg.com/plugins/zymarg-store-page
 * Description:       Replaces the default Dokan vendor store page with the premium ZYMARG Store Page design — hero banner, trust highlights, featured collections carousel, category sidebar, AURA Studio live-search (vendor-scoped), dynamic product grid via Dokan REST API, customer-reviews section with star breakdowns, and a collapsible store-story panel. Automatically overrides Dokan's store template on activation; no theme edits required.
 * Version:           1.22.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZYMARG
 * Author URI:        https://zymarg.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zymarg-store-page
 * Domain Path:       /languages
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'ZYMARG_SP_VERSION' ) ) {
	define( 'ZYMARG_SP_VERSION', '1.22.3' );
}
if ( ! defined( 'ZYMARG_SP_FILE' ) ) {
	define( 'ZYMARG_SP_FILE',      __FILE__ );
}
if ( ! defined( 'ZYMARG_SP_DIR' ) ) {
	define( 'ZYMARG_SP_DIR',       plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ZYMARG_SP_URL' ) ) {
	define( 'ZYMARG_SP_URL',       plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'ZYMARG_SP_TEMPLATES' ) ) {
	define( 'ZYMARG_SP_TEMPLATES', ZYMARG_SP_DIR . 'templates/' );
}

// ──────────────────────────────────────────────────────────────────────────────
// Dependency check — Dokan must be active.
// ──────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, static function () {
	if ( ! class_exists( 'WeDevs_Dokan' ) && ! function_exists( 'dokan' ) ) {
		deactivate_plugins( plugin_basename( ZYMARG_SP_FILE ) );
		wp_die(
			esc_html__( 'ZYMARG Store Page requires the Dokan plugin to be installed and active. Please install Dokan first.', 'zymarg-store-page' ),
			esc_html__( 'Plugin Activation Error', 'zymarg-store-page' ),
			[ 'back_link' => true ]
		);
	}

	// Provision the Flash Sale page. Loaded explicitly because zymarg_sp_init()
	// has not run at activation time.
	//
	// This is only the fresh-install path. An install that was already active
	// when this version landed never fires this hook again, which is why
	// ZYMARG_SP_Flash_Sale also runs a one-time check on admin_init.
	require_once ZYMARG_SP_DIR . 'includes/class-flash-sale.php';
	ZYMARG_SP_Flash_Sale::activate();
} );

// ──────────────────────────────────────────────────────────────────────────────
// Deactivation — deliberately does NOT remove the Flash Sale page.
//
// The page may hold the marketplace owner's own copy, translations, SEO
// metadata and inbound links. Deleting it because a plugin was switched off,
// perhaps only to debug something, would destroy work this plugin does not own.
// ──────────────────────────────────────────────────────────────────────────────

// ──────────────────────────────────────────────────────────────────────────────
// Boot the plugin after all plugins have loaded.
// ──────────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'zymarg_sp_init' );
function zymarg_sp_init() {
	// Only proceed when Dokan is available.
	if ( ! class_exists( 'WeDevs_Dokan' ) && ! function_exists( 'dokan' ) ) {
		add_action( 'admin_notices', 'zymarg_sp_missing_dokan_notice' );
		return;
	}

	// Load sub-modules. The Spark helper comes first -- the admin header uses
	// it, and it registers the shared brand stylesheet handles.
	require_once ZYMARG_SP_DIR . 'includes/spark.php';
	require_once ZYMARG_SP_DIR . 'includes/class-template-override.php';

	// The one place this plugin talks to the Product Grid engine. Loaded before
	// anything that renders a product card, which is now every card surface:
	// no hand-rolled product card remains in this plugin.
	require_once ZYMARG_SP_DIR . 'includes/class-grid-bridge.php';

	// Registers Premium flash sales as a real engine source, which is what
	// gives the /flash-sale/ page working load-more. Self-registering on the
	// engine's source-registry filter; inert when the engine or the Vendor
	// Dashboard is absent.
	require_once ZYMARG_SP_DIR . 'includes/class-source-premium-flash.php';

	require_once ZYMARG_SP_DIR . 'includes/class-assets.php';
	require_once ZYMARG_SP_DIR . 'includes/class-admin.php';
	require_once ZYMARG_SP_DIR . 'includes/class-follow.php';
	require_once ZYMARG_SP_DIR . 'includes/class-following.php';
	require_once ZYMARG_SP_DIR . 'includes/class-chat.php';

	// Premium sections (Flash Sale / Featured Items). Read-only bridge to the
	// Vendor Dashboard plugin; every entry point is function_exists-guarded, so
	// this stays inert when that plugin is not active.
	require_once ZYMARG_SP_DIR . 'includes/premium-sections.php';

	// Store badges (verified tick / OFFICIAL STORE / VERIFIED SELLER). Another
	// read-only bridge to the Vendor Dashboard: the admin grants each badge per
	// vendor there, and nothing renders here unless it has been granted.
	require_once ZYMARG_SP_DIR . 'includes/store-badges.php';
	require_once ZYMARG_SP_DIR . 'includes/class-store-listing.php';

	// Marketplace-wide Flash Sale page. Owns only the URL and the page chrome:
	// the products come from the Product Grid engine's flash_deals source and
	// the card design from the Template Pack, so both stay updatable without
	// touching this plugin.
	require_once ZYMARG_SP_DIR . 'includes/class-flash-sale.php';

	// The Flash Sale hero's control surface. Two self-contained files with no
	// dependency on any other ZYMARG plugin: the design engine that confines a
	// pasted HTML document to its own section, and the settings registry that
	// turns admin controls into CSS custom properties. The design engine loads
	// first because the hero's sanitiser reads its RAW_KEYS list.
	require_once ZYMARG_SP_DIR . 'includes/class-flash-design.php';
	require_once ZYMARG_SP_DIR . 'includes/class-flash-hero.php';

	ZYMARG_SP_Grid_Bridge::init();
	ZYMARG_SP_Store_Listing::init();
	ZYMARG_SP_Flash_Sale::init();
	ZYMARG_SP_Flash_Hero::init();
	ZYMARG_SP_Template_Override::init();
	ZYMARG_SP_Assets::init();
	ZYMARG_SP_Admin::init();
	ZYMARG_SP_Follow::init();
	ZYMARG_SP_Following::init();
	ZYMARG_SP_Chat::init();
}

// ──────────────────────────────────────────────────────────────────────────────
// Admin notice — Dokan missing.
// ──────────────────────────────────────────────────────────────────────────────
function zymarg_sp_missing_dokan_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'ZYMARG Store Page requires the Dokan plugin. Please install and activate Dokan.', 'zymarg-store-page' );
	echo '</p></div>';
}
