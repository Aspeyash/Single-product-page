<?php
/**
 * ZYMARG Vendor Dashboard — uninstall handler.
 *
 * Fired by WordPress when the plugin is removed via Plugins -> Delete.
 *
 * THIS FILE IS NON-DESTRUCTIVE BY DEFAULT.
 *
 * All vendor data is preserved on uninstall, so a future reinstall or
 * upgrade can pick up exactly where you left off. Specifically preserved:
 *
 *   - Vendor payout methods (bKash / Nagad / Rocket / bank details)
 *   - Withdrawal requests (zymarg_payout custom post type)
 *   - Refund requests (zymarg_refund custom post type)
 *   - Per-vendor shipping fee settings (_zv_shipping)
 *   - Per-vendor store SEO meta (_zv_seo)
 *   - Plugin-owned store-avatar attachments (_zymarg_store_avatar_*)
 *   - Dismissed compatibility warnings (_zvd_compat_dismissed)
 *   - Feature toggles (zymarg_vd_features option)
 *
 * Dokan's own data (dokan_profile_settings, dokan_store_name, etc.) is
 * NEVER touched here — that data belongs to Dokan, not ZYMARG.
 *
 * For DEV RESETS (full data wipe), opt in by adding ONE of these BEFORE
 * clicking Delete:
 *
 *   // In wp-config.php
 *   define( 'ZYMARG_VD_DELETE_ALL_DATA', true );
 *
 *   // Or via a mu-plugin / theme functions.php
 *   add_filter( 'zymarg_vd_delete_data_on_uninstall', '__return_true' );
 *
 * @package ZYMARG_Vendor_Dashboard
 */

// Bail unless we're actually firing from WordPress's uninstall path.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* ---------------------------------------------------------------------- *
 * 1. Always-on cleanup (short-lived infrastructure only).
 * ---------------------------------------------------------------------- *
 *
 * No transients / rate-limit caches are currently in use, but this is the
 * hook point for any future additions so we never accidentally orphan
 * those in the DB.
 */
do_action( 'zymarg_vd_uninstall_cleanup' );

/* ---------------------------------------------------------------------- *
 * 2. Opt-in destructive wipe decision.
 * ---------------------------------------------------------------------- */

$zvd_delete_data = (
	( defined( 'ZYMARG_VD_DELETE_ALL_DATA' ) && ZYMARG_VD_DELETE_ALL_DATA )
	|| ( function_exists( 'apply_filters' ) && apply_filters( 'zymarg_vd_delete_data_on_uninstall', false ) )
);

if ( ! $zvd_delete_data ) {
	// Default path: keep all vendor data, exit cleanly.
	return;
}

/* ---------------------------------------------------------------------- *
 * 3. DESTRUCTIVE WIPE (only when explicitly opted in above).
 * ---------------------------------------------------------------------- */

global $wpdb;

// Plugin-owned user meta keys.
$zvd_user_meta_keys = array(
	'_zv_payout_methods',
	'_zv_payout_default',
	'_zv_shipping',
	'_zv_seo',
	'_zymarg_store_avatar_id',
	'_zymarg_store_avatar_url',
	'_zvd_compat_dismissed',
);
foreach ( $zvd_user_meta_keys as $zvd_meta_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $zvd_meta_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery, WordPress.DB.DirectDatabaseQuery
}

// Plugin-owned options.
delete_option( 'zymarg_vd_features' );

// Plugin-owned custom post types (postmeta cascades via wp_delete_post).
$zvd_cpts = array( 'zymarg_payout', 'zymarg_refund' );
foreach ( $zvd_cpts as $zvd_cpt ) {
	$zvd_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
			$zvd_cpt
		)
	);
	foreach ( $zvd_ids as $zvd_id ) {
		wp_delete_post( (int) $zvd_id, true );
	}
}

/**
 * Fires after a destructive ZYMARG VD uninstall has completed.
 * Add-on modules can hook in to clean their own data.
 */
do_action( 'zymarg_vd_uninstall_destructive_done' );
