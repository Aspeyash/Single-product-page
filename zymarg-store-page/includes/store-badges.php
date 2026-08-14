<?php
/**
 * Store badges — read side.
 *
 * The Vendor Dashboard owns the badge grants; this plugin only reads them and
 * draws the marks beside the store name. Every read goes through
 * function_exists() so the store still renders correctly if the Vendor
 * Dashboard is deactivated — in that case no badges show, which is the safe
 * direction to fail.
 *
 * Three marks only, in this order:
 *   1. tick             — verified tick beside the name
 *   2. official         — OFFICIAL STORE pill
 *   3. verified_seller  — Verified Seller pill
 *
 * @package ZYMARG_Store_Page
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the badge grants for a store.
 *
 * @param int $store_id Vendor user ID.
 * @return array<string,bool> Keys: tick, official, verified_seller.
 */
function zymarg_sp_store_badges( $store_id ) {
	$store_id = absint( $store_id );

	$badges = array(
		'tick'            => false,
		'official'        => false,
		'verified_seller' => false,
	);

	if ( $store_id <= 0 ) {
		return $badges;
	}

	if ( function_exists( 'zymarg_vd_store_badges' ) ) {
		$granted = zymarg_vd_store_badges( $store_id );

		if ( is_array( $granted ) ) {
			foreach ( $badges as $key => $unused ) {
				$badges[ $key ] = ! empty( $granted[ $key ] );
			}
		}
	}

	return $badges;
}

/**
 * Is one badge granted for this store?
 *
 * @param int    $store_id Vendor user ID.
 * @param string $key      Badge key.
 * @return bool
 */
function zymarg_sp_store_badge( $store_id, $key ) {
	$badges = zymarg_sp_store_badges( $store_id );

	return ! empty( $badges[ $key ] );
}

/**
 * The verified tick mark.
 *
 * Rendered at three different sizes (sticky header, mobile title, desktop
 * title), so the size class is passed in rather than duplicating the path
 * data three times in the template.
 *
 * @param string $size_class Tailwind size utilities, e.g. "h-6 w-6".
 * @return string SVG markup, or an empty string when not granted.
 */
function zymarg_sp_badge_tick( $size_class = 'h-5 w-5' ) {
	$path = 'M16.403 12.652a3 3 0 0 0 0-5.304 3 3 0 0 0-3.75-3.751 3 3 0 0 0-5.305 0 3 3 0 0 0-3.751 3.75 3 3 0 0 0 0 5.305 3 3 0 0 0 3.75 3.751 3 3 0 0 0 5.305 0 3 3 0 0 0 3.751-3.75Zm-2.546-4.46a.75.75 0 0 0-1.214-.883l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z';

	return sprintf(
		'<svg class="%1$s shrink-0 text-zy-primary" viewBox="0 0 20 20" fill="currentColor" role="img" aria-label="%2$s"><path fill-rule="evenodd" d="%3$s" clip-rule="evenodd"/></svg>',
		esc_attr( $size_class ),
		esc_attr__( 'Verified', 'zymarg-store-page' ),
		esc_attr( $path )
	);
}

/**
 * A pill badge beside the store name.
 *
 * @param string $label Visible text.
 * @param string $style Either "gradient" (strong) or "soft".
 * @return string
 */
function zymarg_sp_badge_pill( $label, $style = 'gradient' ) {
	$classes = 'gradient' === $style
		? 'rounded-full bg-zy-gradient px-3 py-1 text-xs font-semibold tracking-wide text-white shadow-lg'
		: 'rounded-full bg-zy-container px-3 py-1 text-xs font-semibold tracking-wide text-zy-primary';

	return sprintf(
		'<span class="%1$s whitespace-nowrap">%2$s</span>',
		esc_attr( $classes ),
		esc_html( $label )
	);
}

/**
 * Render the full badge row for a store, in the fixed order.
 *
 * Returns an empty string when the admin has granted nothing, so the template
 * adds no stray spacing for a store with no badges.
 *
 * @param int   $store_id   Vendor user ID.
 * @param array $args       Optional. {
 *     @type string $tick_size  Tailwind size utilities for the tick.
 *     @type bool   $show_pills Whether to render the two text pills.
 * }
 * @return string
 */
function zymarg_sp_store_badge_row( $store_id, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'tick_size'  => 'h-5 w-5',
			'show_pills' => true,
		)
	);

	$badges = zymarg_sp_store_badges( $store_id );
	$out    = '';

	if ( ! empty( $badges['tick'] ) ) {
		$out .= zymarg_sp_badge_tick( $args['tick_size'] );
	}

	if ( ! empty( $args['show_pills'] ) ) {
		if ( ! empty( $badges['official'] ) ) {
			$out .= zymarg_sp_badge_pill( __( 'OFFICIAL STORE', 'zymarg-store-page' ), 'gradient' );
		}

		if ( ! empty( $badges['verified_seller'] ) ) {
			$out .= zymarg_sp_badge_pill( __( 'VERIFIED SELLER', 'zymarg-store-page' ), 'soft' );
		}
	}

	return $out;
}
