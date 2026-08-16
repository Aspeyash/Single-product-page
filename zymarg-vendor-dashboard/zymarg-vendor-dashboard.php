<?php
/**
 * Plugin Name:       ZYMARG Vendor Dashboard
 * Plugin URI:        https://github.com/Aspeyash/Wordpress-Theme
 * Description:       A custom, on-brand vendor "business operating system" for WooCommerce + Dokan marketplaces — Dashboard, Products (native add/edit), Orders, Earnings, Analytics, Promotions (native coupons), Reviews, Messages, Customers, native Store Settings and native Payouts (bKash / Nagad / Rocket / bank). Pairs with the ZYMARG OS theme but works standalone on any theme.
 * Version: 1.46.13
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZYMARG
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zymarg-vendor-dashboard
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ---- Constants ------------------------------------------------------- */
define( 'ZYMARG_VD_FILE', __FILE__ );
define( 'ZYMARG_VD_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZYMARG_VD_URL', plugin_dir_url( __FILE__ ) );

/*
 * Version is read straight from the plugin header above, so there is a SINGLE
 * source of truth. Bump only the "Version:" line on a release and everything
 * that uses the version (asset cache-busting, the D-Instruction page, etc.)
 * updates automatically.
 */
if ( ! defined( 'ZYMARG_VD_VERSION' ) ) {
	$zymarg_vd_header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
	define( 'ZYMARG_VD_VERSION', ! empty( $zymarg_vd_header['Version'] ) ? $zymarg_vd_header['Version'] : '0.0.0' );
}

/**
 * Load the plugin once all plugins are available, so we can check for
 * WooCommerce. The vendor dashboard needs WooCommerce; Dokan is optional
 * (it enriches the data — without it the dashboard still renders for admins
 * and falls back gracefully).
 *
 * @return void
 */
function zymarg_vd_bootstrap() {
	// Admin hub menu (top-level "Vendor" parent for all admin screens).
	require_once ZYMARG_VD_DIR . 'includes/admin-hub.php';

	// Admin vendors page (per-vendor commission overrides).
	require_once ZYMARG_VD_DIR . 'includes/admin-vendors.php';

	// Admin-granted store badges. Always loaded, because the Store Page reads
	// these on the front end and not only inside wp-admin.
	require_once ZYMARG_VD_DIR . 'includes/store-badges.php';

	// Vendor verification badges (loaded always -- badge display is frontend).
	require_once ZYMARG_VD_DIR . 'includes/verification.php';

	// Vendor announcements (loaded always -- vendors query announcements on frontend).
	require_once ZYMARG_VD_DIR . 'includes/announcements.php';

	// Settings/feature-toggle layer + the D-Instruction docs (admin, no WC needed).
	require_once ZYMARG_VD_DIR . 'includes/settings.php';
	require_once ZYMARG_VD_DIR . 'includes/instructions.php';
	require_once ZYMARG_VD_DIR . 'includes/compat-monitor.php';

	// Premium (Flash Sale + Featured Items). Loaded before the WooCommerce
	// check so the admin screen and the approval state helpers work even on a
	// site where WooCommerce is temporarily inactive. Its price layer guards
	// itself with class_exists( 'WooCommerce' ) and stays dormant without it.
	require_once ZYMARG_VD_DIR . 'includes/premium.php';
	require_once ZYMARG_VD_DIR . 'includes/premium-admin.php';

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'zymarg_vd_woocommerce_notice' );
		return;
	}
	require_once ZYMARG_VD_DIR . 'includes/vendor-dashboard.php';
	require_once ZYMARG_VD_DIR . 'includes/pro-compat.php';
	require_once ZYMARG_VD_DIR . 'includes/payouts.php';
	require_once ZYMARG_VD_DIR . 'includes/auto-disbursement.php';
	require_once ZYMARG_VD_DIR . 'includes/product-editor.php';
	require_once ZYMARG_VD_DIR . 'includes/settings-hub.php';

	// Premium vendor screen. Required after vendor-dashboard.php because it
	// hooks that shell's nav / native-section / render filters.
	// Must load before premium-vendor.php, which calls into it to render the
	// product-level controls.
	require_once ZYMARG_VD_DIR . 'includes/premium-products.php';
	require_once ZYMARG_VD_DIR . 'includes/premium-vendor.php';
	// v1.32.0: store-settings.php no longer has its own screen — it now only
	// holds vacation-mode's always-on storefront effects (away-notice,
	// Add-to-cart pause). Still required directly so those hooks register.
	require_once ZYMARG_VD_DIR . 'includes/store-settings.php';
	require_once ZYMARG_VD_DIR . 'includes/refunds.php';
	require_once ZYMARG_VD_DIR . 'includes/shipping-seo.php';
	require_once ZYMARG_VD_DIR . 'includes/vendor-staff.php';

	// Public JSON REST API (app contract) — loaded after the data functions it
	// wraps. Powers native apps (Flutter etc.) via /wp-json/zymarg/v1/.
	require_once ZYMARG_VD_DIR . 'includes/rest-api.php';

	// Push notifications (Firebase FCM HTTP v1). Safe no-op until configured.
	require_once ZYMARG_VD_DIR . 'includes/push-notifications.php';

	// Phase 4 — email buyer when vendor replies. Safe no-op until toggled on.
	require_once ZYMARG_VD_DIR . 'includes/buyer-email-notify.php';
}
add_action( 'plugins_loaded', 'zymarg_vd_bootstrap' );

/**
 * Admin notice when WooCommerce is missing.
 *
 * @return void
 */
function zymarg_vd_woocommerce_notice() {
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'ZYMARG Vendor Dashboard needs WooCommerce active. (Dokan is recommended for full multi-vendor data.)', 'zymarg-vendor-dashboard' );
	echo '</p></div>';
}

/**
 * Flush rewrite rules on activation so the message post type + any endpoints
 * register cleanly.
 *
 * @return void
 */
function zymarg_vd_activate() {
	if ( class_exists( 'WooCommerce' ) ) {
		require_once ZYMARG_VD_DIR . 'includes/vendor-dashboard.php';
		if ( function_exists( 'zymarg_os_vendor_register_message_cpt' ) ) {
			zymarg_os_vendor_register_message_cpt();
		}
		require_once ZYMARG_VD_DIR . 'includes/vendor-staff.php';
		if ( function_exists( 'zymarg_vd_staff_activate' ) ) {
			zymarg_vd_staff_activate();
		}
		zymarg_vd_maybe_create_dashboard_page();
	}
	flush_rewrite_rules();
}

/**
 * Ensure a vendor dashboard page exists so the takeover has something to render.
 *
 * If a page with the dashboard slug already exists (e.g. Dokan's own dashboard
 * page), we leave it alone — the the_content filter takes it over at runtime.
 * Only when no such page exists do we create one holding the shortcode, so the
 * dashboard works out of the box even without Dokan.
 *
 * @return void
 */
function zymarg_vd_maybe_create_dashboard_page() {
	$slug = function_exists( 'zymarg_os_vendor_dashboard_slug' ) ? zymarg_os_vendor_dashboard_slug() : 'dashboard';

	if ( get_page_by_path( $slug ) ) {
		return; // An existing (Dokan or manual) dashboard page — taken over automatically.
	}

	wp_insert_post(
		array(
			'post_title'   => __( 'Dashboard', 'zymarg-vendor-dashboard' ),
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[zymarg_vendor_dashboard]',
		)
	);
}
register_activation_hook( __FILE__, 'zymarg_vd_activate' );

/**
 * Clean up rewrite rules on deactivation. (Vendor data — messages, coupons,
 * reviews — is intentionally preserved.)
 *
 * @return void
 */
function zymarg_vd_deactivate() {
	if ( function_exists( 'zymarg_vd_staff_deactivate' ) ) {
		zymarg_vd_staff_deactivate();
	}
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'zymarg_vd_deactivate' );
