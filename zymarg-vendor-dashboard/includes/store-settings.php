<?php
/**
 * ZYMARG Vendor Dashboard — Vacation mode storefront effects.
 *
 * v1.32.0: the standalone "Store Settings" in-shell SCREEN (its own sidebar
 * nav item, its own page, its own AJAX save handler) was REMOVED. Every
 * field it edited (store name, public phone, email visibility, banner,
 * address, vacation mode) now lives in the main Settings accordion as
 * Section 5 "Store Profile" — see includes/settings-hub.php
 * (zymarg_vd_render_settings_card_store_profile()) and the matching AJAX
 * handler zymarg_vd_ajax_settings_save_store_profile() in
 * includes/vendor-dashboard.php. No data migration was needed: both the
 * old screen and the new section read/write the exact same
 * `dokan_profile_settings` user-meta keys.
 *
 * What's LEFT in this file are the runtime, always-on storefront EFFECTS of
 * vacation mode — these have nothing to do with which screen edits the
 * setting, so they stay here as the one focused home for "what vacation
 * mode actually does on the frontend":
 *   - `zymarg_vd_store_get_profile()`      — shared profile-meta reader.
 *   - `zymarg_vd_vendor_on_vacation()`     — is this vendor away right now?
 *   - `zymarg_vd_vacation_product_notice()` — away-notice on product pages.
 *   - `zymarg_vd_vacation_purchasable()`   — optional Add-to-cart pause.
 *
 * All four defer entirely to Dokan Pro's own seller-vacation module when
 * it's active (`zymarg_vd_vacation_managed_by_pro()`, in pro-compat.php).
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the Dokan store profile settings (array) for a vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array
 */
function zymarg_vd_store_get_profile( $vendor_id ) {
	$profile = get_user_meta( $vendor_id, 'dokan_profile_settings', true );
	return is_array( $profile ) ? $profile : array();
}

/* ====================================================================== *
 * Vacation mode — storefront effects
 * ====================================================================== */

/**
 * Whether a vendor is currently on vacation.
 *
 * @param int $vendor_id Vendor user ID.
 * @return bool
 */
function zymarg_vd_vendor_on_vacation( $vendor_id ) {
	$profile = zymarg_vd_store_get_profile( $vendor_id );
	return isset( $profile['setting_go_vacation'] ) && 'yes' === $profile['setting_go_vacation'];
}

/**
 * Show the vacation notice on a vendor's single product page.
 *
 * @return void
 */
function zymarg_vd_vacation_product_notice() {
	if ( function_exists( 'zymarg_vd_vacation_managed_by_pro' ) && zymarg_vd_vacation_managed_by_pro() ) {
		return; // Dokan Pro's seller-vacation module handles this.
	}
	if ( ! is_product() ) {
		return;
	}
	global $post;
	if ( ! $post ) {
		return;
	}
	$vendor_id = (int) $post->post_author;
	if ( ! zymarg_vd_vendor_on_vacation( $vendor_id ) ) {
		return;
	}
	$profile = zymarg_vd_store_get_profile( $vendor_id );
	$msg     = isset( $profile['setting_vacation_message'] ) && '' !== $profile['setting_vacation_message']
		? $profile['setting_vacation_message']
		: __( 'This store is currently away. Orders may be delayed.', 'zymarg-vendor-dashboard' );

	echo '<div class="zymarg-vacation-notice woocommerce-info">' . esc_html( $msg ) . '</div>';
}
add_action( 'woocommerce_single_product_summary', 'zymarg_vd_vacation_product_notice', 6 );

/**
 * Optionally pause sales: make a vendor's products non-purchasable while away.
 *
 * @param bool       $purchasable Whether the product is purchasable.
 * @param WC_Product $product     Product.
 * @return bool
 */
function zymarg_vd_vacation_purchasable( $purchasable, $product ) {
	if ( function_exists( 'zymarg_vd_vacation_managed_by_pro' ) && zymarg_vd_vacation_managed_by_pro() ) {
		return $purchasable; // Dokan Pro's seller-vacation module handles this.
	}
	if ( ! $purchasable || ! is_a( $product, 'WC_Product' ) ) {
		return $purchasable;
	}
	$pid       = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$vendor_id = (int) get_post_field( 'post_author', $pid );
	if ( ! $vendor_id || ! zymarg_vd_vendor_on_vacation( $vendor_id ) ) {
		return $purchasable;
	}
	$profile = zymarg_vd_store_get_profile( $vendor_id );
	if ( isset( $profile['zymarg_vacation_disable_cart'] ) && 'yes' === $profile['zymarg_vacation_disable_cart'] ) {
		return false;
	}
	return $purchasable;
}
add_filter( 'woocommerce_is_purchasable', 'zymarg_vd_vacation_purchasable', 10, 2 );
add_filter( 'woocommerce_variation_is_purchasable', 'zymarg_vd_vacation_purchasable', 10, 2 );
