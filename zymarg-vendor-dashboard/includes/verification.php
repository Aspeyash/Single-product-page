<?php
/**
 * ZYMARG Vendor Dashboard -- Vendor Verification Badges.
 *
 * Provides admin-controlled verification levels for vendors, badge rendering,
 * and public-facing display hooks.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * 1. CORE API FUNCTIONS
 * ====================================================================== */

/**
 * Check whether a vendor is verified.
 *
 * @param int $user_id The vendor user ID.
 * @return string 'full' | 'id' | '' (empty string = unverified).
 */
function zymarg_vd_is_vendor_verified( $user_id ) {
	$level = get_user_meta( absint( $user_id ), '_zymarg_vd_verification_level', true );
	if ( in_array( $level, array( 'full', 'id' ), true ) ) {
		return $level;
	}
	return '';
}

/**
 * Render the verification badge HTML for a vendor.
 *
 * @param int    $user_id The vendor user ID.
 * @param string $size    Badge size: 'sm' (16px), 'md' (20px), 'lg' (24px).
 * @return string Badge HTML or empty string if unverified.
 */
function zymarg_vd_verification_badge( $user_id, $size = 'sm' ) {
	$level = zymarg_vd_is_vendor_verified( $user_id );
	if ( '' === $level ) {
		return '';
	}

	$sizes = array(
		'sm' => 16,
		'md' => 20,
		'lg' => 24,
	);
	$px = isset( $sizes[ $size ] ) ? $sizes[ $size ] : 16;

	if ( 'full' === $level ) {
		$color = '#9500A5'; // ZYMARG brand purple.
		$title = esc_attr__( 'Fully Verified', 'zymarg-vendor-dashboard' );
		// Double checkmark SVG.
		$icon = '<path d="M4 12l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>'
			. '<path d="M9 12l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>';
	} else {
		$color = '#2196F3'; // Blue for ID verified.
		$title = esc_attr__( 'ID Verified', 'zymarg-vendor-dashboard' );
		// Single checkmark SVG.
		$icon = '<path d="M6 12l4 4 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>';
	}

	$html = sprintf(
		'<span class="zymarg-vd-badge zymarg-vd-badge--%s zymarg-vd-badge--%s" title="%s" style="display:inline-flex;align-items:center;justify-content:center;width:%dpx;height:%dpx;border-radius:50%%;background:%s;vertical-align:middle;margin-left:4px;cursor:help;">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 24 24" fill="none">%s</svg>'
		. '</span>',
		esc_attr( $level ),
		esc_attr( $size ),
		$title,
		$px,
		$px,
		esc_attr( $color ),
		(int) round( $px * 0.7 ),
		(int) round( $px * 0.7 ),
		$icon
	);

	return $html;
}

/* ====================================================================== *
 * 2. BADGE DISPLAY HOOKS
 * ====================================================================== */

/**
 * Display badge on the Dokan store page header.
 *
 * Hooks into dokan_store_header_info_fields.
 *
 * @param int $store_id The vendor/store user ID.
 * @return void
 */
function zymarg_vd_badge_on_store_header( $store_id ) {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'verification' ) ) {
		return;
	}
	$badge = zymarg_vd_verification_badge( $store_id, 'md' );
	if ( $badge ) {
		echo '<span class="zymarg-vd-store-badge">' . $badge . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is pre-escaped.
	}
}
add_action( 'dokan_store_header_info_fields', 'zymarg_vd_badge_on_store_header', 10, 1 );

/**
 * Display badge on WooCommerce product cards (after shop loop item title).
 *
 * @return void
 */
function zymarg_vd_badge_on_product_card() {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'verification' ) ) {
		return;
	}
	global $product;
	if ( ! $product ) {
		return;
	}
	$vendor_id = get_post_field( 'post_author', $product->get_id() );
	if ( ! $vendor_id ) {
		return;
	}
	$badge = zymarg_vd_verification_badge( (int) $vendor_id, 'sm' );
	if ( $badge ) {
		echo '<span class="zymarg-vd-product-badge">' . $badge . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'woocommerce_after_shop_loop_item_title', 'zymarg_vd_badge_on_product_card', 8 );

/**
 * Filter to inject badge next to the vendor store name in the sidebar.
 *
 * This is called from vendor-dashboard.php where the store name is rendered.
 *
 * @param string $store_name The store name HTML.
 * @param int    $user_id    The vendor user ID.
 * @return string
 */
function zymarg_vd_badge_in_sidebar_name( $store_name, $user_id ) {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'verification' ) ) {
		return $store_name;
	}
	$badge = zymarg_vd_verification_badge( $user_id, 'sm' );
	return $store_name . $badge;
}
add_filter( 'zymarg_vd_sidebar_store_name', 'zymarg_vd_badge_in_sidebar_name', 10, 2 );

/* ====================================================================== *
 * 3. VENDOR-FACING STATUS DISPLAY
 * ====================================================================== */

/**
 * Render verification status for the vendor in their own dashboard sidebar.
 *
 * Called directly from the sidebar template after the store name area.
 *
 * @param int $user_id The current vendor's user ID.
 * @return string HTML for the verification status line.
 */
function zymarg_vd_vendor_verification_status( $user_id ) {
	$level = zymarg_vd_is_vendor_verified( $user_id );

	if ( 'full' === $level ) {
		$badge = zymarg_vd_verification_badge( $user_id, 'sm' );
		$text  = __( 'Fully Verified', 'zymarg-vendor-dashboard' );
		return '<div class="zymarg-vd-verification-status zymarg-vd-verification-status--verified" style="display:flex;align-items:center;gap:4px;font-size:11px;color:#9500A5;margin-top:4px;padding-left:2px;">'
			. $badge . '<span>' . esc_html( $text ) . '</span></div>';
	} elseif ( 'id' === $level ) {
		$badge = zymarg_vd_verification_badge( $user_id, 'sm' );
		$text  = __( 'ID Verified', 'zymarg-vendor-dashboard' );
		return '<div class="zymarg-vd-verification-status zymarg-vd-verification-status--verified" style="display:flex;align-items:center;gap:4px;font-size:11px;color:#2196F3;margin-top:4px;padding-left:2px;">'
			. $badge . '<span>' . esc_html( $text ) . '</span></div>';
	}

	return '<div class="zymarg-vd-verification-status zymarg-vd-verification-status--unverified" style="font-size:11px;color:#999;margin-top:4px;padding-left:2px;">'
		. esc_html__( 'Not yet verified', 'zymarg-vendor-dashboard' )
		. '</div>';
}
