<?php
/**
 * ZYMARG Vendor Dashboard -- Premium (Flash Sale + Featured Items).
 *
 * PHASE 1: foundation only. State, helpers, the isolated price layer and the
 * defensive query exclusion. No UI is registered here -- the admin screen,
 * the vendor dashboard menu and the store-page sections come in later phases
 * and all read their state through the helpers below.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS AT ALL (read before changing anything)
 * ---------------------------------------------------------------------------
 * The marketplace already has TWO other systems that mean "featured" and
 * "flash sale":
 *
 *   1. ZYMARG WC Product Grid -- its `featured` source queries the WooCommerce
 *      `product_visibility` taxonomy for the `featured` term. Its
 *      `current_vendor` source has a `featured` subset doing the same.
 *   2. ZYMARG Homepage -- its "Featured Products" section maps onto that same
 *      engine source, and its "Flash Deals" section is defined as "on-sale
 *      products" (sale price + sale end date).
 *
 * Vendor Premium must NEVER feed either of them. The separation is structural,
 * not cosmetic, and it rests on three independent layers:
 *
 *   LAYER 1 -- SEPARATE STORAGE.
 *      We never write the `product_visibility` / `featured` term, and we never
 *      write `_sale_price`. Premium state lives exclusively in the meta keys
 *      declared as constants below.
 *
 *   LAYER 2 -- SEPARATE RENDER PATH.
 *      Premium products are only ever rendered by the vendor dashboard and the
 *      Dokan store page. Premium is not registered as a Product Grid source
 *      and not registered as a homepage section.
 *
 *   LAYER 3 -- DEFENSIVE EXCLUSION.
 *      zymarg_vd_premium_exclude_from_grid_args() hooks the Product Grid's own
 *      `zymarg_wcpg_query_args` filter and strips Premium products out of grid
 *      queries anyway. This is belt and braces for the day somebody wires a
 *      vendor product into a homepage section by hand.
 *
 * ---------------------------------------------------------------------------
 * THE PRICE LAYER, AND WHY IT DOES NOT TOUCH _sale_price
 * ---------------------------------------------------------------------------
 * WooCommerce builds its on-sale lists by reading `_sale_price` straight from
 * the database (see wc_get_product_ids_on_sale()), NOT by running the runtime
 * price filters. So by applying the flash price purely through
 * `woocommerce_product_get_price` and friends, and leaving `_sale_price`
 * empty, a flash product is structurally invisible to every on-sale query on
 * the site -- homepage Flash Deals, [sale_products], sale badges, all of it.
 * It is not filtered out after the fact; it is never in the result set.
 *
 * The trade-off is deliberate: WooCommerce will not render its native
 * strikethrough price, because as far as WooCommerce is concerned the product
 * is not on sale. Premium renders its own badge and countdown instead. Do not
 * "fix" this by also filtering woocommerce_product_get_sale_price or
 * woocommerce_product_is_on_sale -- that reopens the leak this file exists to
 * close.
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.40.0
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------- *
 * 1. CONSTANTS -- the single source of truth for every Premium key
 * ---------------------------------------------------------------------- */

/** Option holding the admin master switches. */
const ZYMARG_VD_PREMIUM_OPTION = 'zymarg_vd_premium';

/** The two Premium functionalities. */
const ZYMARG_VD_PREMIUM_FLASH    = 'flash_sale';
const ZYMARG_VD_PREMIUM_FEATURED = 'featured';

/** Per-vendor request states. */
const ZYMARG_VD_PREMIUM_OFF      = 'off';
const ZYMARG_VD_PREMIUM_PENDING  = 'pending';
const ZYMARG_VD_PREMIUM_APPROVED = 'approved';
const ZYMARG_VD_PREMIUM_REJECTED = 'rejected';

/** Product meta -- vendor's Featured Items picks. */
const ZYMARG_VD_PREMIUM_META_FEATURED = '_zymarg_vd_premium_featured';

/** Product meta -- Flash Sale price layer. */
const ZYMARG_VD_PREMIUM_META_FLASH_ON    = '_zymarg_vd_flash_enabled';
const ZYMARG_VD_PREMIUM_META_FLASH_PRICE = '_zymarg_vd_flash_price';
const ZYMARG_VD_PREMIUM_META_FLASH_START = '_zymarg_vd_flash_start';
const ZYMARG_VD_PREMIUM_META_FLASH_END   = '_zymarg_vd_flash_end';

/**
 * Both Premium functionality keys.
 *
 * @return array<int,string>
 */
function zymarg_vd_premium_features() {
	return array( ZYMARG_VD_PREMIUM_FLASH, ZYMARG_VD_PREMIUM_FEATURED );
}

/**
 * Human label for a Premium functionality.
 *
 * @param string $feature Feature key.
 * @return string
 */
function zymarg_vd_premium_label( $feature ) {
	if ( ZYMARG_VD_PREMIUM_FLASH === $feature ) {
		return __( 'Flash Sale', 'zymarg-vendor-dashboard' );
	}
	if ( ZYMARG_VD_PREMIUM_FEATURED === $feature ) {
		return __( 'Featured Items', 'zymarg-vendor-dashboard' );
	}
	return '';
}

/**
 * Is this a Premium functionality key we recognise?
 *
 * @param string $feature Feature key.
 * @return bool
 */
function zymarg_vd_premium_is_feature( $feature ) {
	return in_array( $feature, zymarg_vd_premium_features(), true );
}

/* ---------------------------------------------------------------------- *
 * 2. ADMIN MASTER SWITCHES
 * ---------------------------------------------------------------------- */

/**
 * The admin master switches, normalised.
 *
 * Both default to OFF, so installing this build changes nothing on the site
 * until an admin deliberately turns a functionality on.
 *
 * @return array<string,bool>
 */
function zymarg_vd_premium_settings() {
	$saved = get_option( ZYMARG_VD_PREMIUM_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	$out = array();
	foreach ( zymarg_vd_premium_features() as $feature ) {
		$out[ $feature ] = ! empty( $saved[ $feature ] );
	}
	return $out;
}

/**
 * Has the admin switched this functionality on marketplace-wide?
 *
 * Nothing about Premium is visible to any vendor while this is false.
 *
 * @param string $feature Feature key.
 * @return bool
 */
function zymarg_vd_premium_master_enabled( $feature ) {
	if ( ! zymarg_vd_premium_is_feature( $feature ) ) {
		return false;
	}
	$settings = zymarg_vd_premium_settings();
	return ! empty( $settings[ $feature ] );
}

/**
 * Is at least one Premium functionality switched on?
 *
 * Drives whether the Premium menu appears on the vendor dashboard at all.
 *
 * @return bool
 */
function zymarg_vd_premium_any_master_enabled() {
	foreach ( zymarg_vd_premium_settings() as $on ) {
		if ( $on ) {
			return true;
		}
	}
	return false;
}

/**
 * Persist the admin master switches.
 *
 * @param array<string,bool> $switches Feature key => on/off.
 * @return array<string,bool> The stored, normalised switches.
 */
function zymarg_vd_premium_update_settings( array $switches ) {
	$clean = array();
	foreach ( zymarg_vd_premium_features() as $feature ) {
		$clean[ $feature ] = ! empty( $switches[ $feature ] );
	}
	update_option( ZYMARG_VD_PREMIUM_OPTION, $clean );

	/**
	 * Fires after the Premium master switches change.
	 *
	 * @param array<string,bool> $clean New switch state.
	 */
	do_action( 'zymarg_vd_premium_settings_updated', $clean );

	return $clean;
}

/* ---------------------------------------------------------------------- *
 * 3. PER-VENDOR REQUEST STATE
 * ---------------------------------------------------------------------- */

/**
 * User-meta key holding a vendor's state for one functionality.
 *
 * @param string $feature Feature key.
 * @return string Empty string for an unknown feature.
 */
function zymarg_vd_premium_state_meta_key( $feature ) {
	if ( ! zymarg_vd_premium_is_feature( $feature ) ) {
		return '';
	}
	return '_zymarg_vd_premium_' . $feature . '_state';
}

/**
 * A vendor's full state record for one functionality.
 *
 * Always returns a complete record, so callers never have to defend against
 * missing keys.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return array{status:string,requested_at:string,decided_at:string,decided_by:int,note:string}
 */
function zymarg_vd_premium_get_state( $vendor_id, $feature ) {
	$empty = array(
		'status'       => ZYMARG_VD_PREMIUM_OFF,
		'requested_at' => '',
		'decided_at'   => '',
		'decided_by'   => 0,
		'note'         => '',
	);

	$key = zymarg_vd_premium_state_meta_key( $feature );
	if ( '' === $key || $vendor_id <= 0 ) {
		return $empty;
	}

	$saved = get_user_meta( (int) $vendor_id, $key, true );
	if ( ! is_array( $saved ) ) {
		return $empty;
	}

	$state = wp_parse_args( $saved, $empty );

	$valid = array(
		ZYMARG_VD_PREMIUM_OFF,
		ZYMARG_VD_PREMIUM_PENDING,
		ZYMARG_VD_PREMIUM_APPROVED,
		ZYMARG_VD_PREMIUM_REJECTED,
	);
	if ( ! in_array( $state['status'], $valid, true ) ) {
		$state['status'] = ZYMARG_VD_PREMIUM_OFF;
	}

	$state['decided_by'] = (int) $state['decided_by'];

	return $state;
}

/**
 * A vendor's status string for one functionality.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return string One of the ZYMARG_VD_PREMIUM_* status constants.
 */
function zymarg_vd_premium_get_status( $vendor_id, $feature ) {
	$state = zymarg_vd_premium_get_state( $vendor_id, $feature );
	return $state['status'];
}

/**
 * Write a vendor's state for one functionality.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @param array  $changes   Partial record to merge over the current one.
 * @return array The stored record.
 */
function zymarg_vd_premium_set_state( $vendor_id, $feature, array $changes ) {
	$key = zymarg_vd_premium_state_meta_key( $feature );
	if ( '' === $key || $vendor_id <= 0 ) {
		return array();
	}

	$state = zymarg_vd_premium_get_state( $vendor_id, $feature );
	$state = array_merge( $state, $changes );

	$state['decided_by'] = (int) $state['decided_by'];
	$state['note']       = sanitize_textarea_field( (string) $state['note'] );

	update_user_meta( (int) $vendor_id, $key, $state );

	/**
	 * Fires whenever a vendor's Premium state changes.
	 *
	 * @param int    $vendor_id Vendor user ID.
	 * @param string $feature   Feature key.
	 * @param array  $state     New state record.
	 */
	do_action( 'zymarg_vd_premium_state_changed', (int) $vendor_id, $feature, $state );

	return $state;
}

/**
 * Vendor asks the admin to unlock a functionality.
 *
 * Refused when the master switch is off, so a vendor can never queue up a
 * request for something the marketplace does not offer.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return bool True when the request was recorded.
 */
function zymarg_vd_premium_request( $vendor_id, $feature ) {
	if ( ! zymarg_vd_premium_master_enabled( $feature ) || $vendor_id <= 0 ) {
		return false;
	}

	$status = zymarg_vd_premium_get_status( $vendor_id, $feature );
	if ( ZYMARG_VD_PREMIUM_APPROVED === $status || ZYMARG_VD_PREMIUM_PENDING === $status ) {
		return false; // Already approved, or already waiting.
	}

	zymarg_vd_premium_set_state(
		$vendor_id,
		$feature,
		array(
			'status'       => ZYMARG_VD_PREMIUM_PENDING,
			'requested_at' => current_time( 'mysql' ),
			'decided_at'   => '',
			'decided_by'   => 0,
			'note'         => '',
		)
	);

	return true;
}

/**
 * Vendor switches a functionality back off.
 *
 * Their product-level picks are intentionally left intact, so switching the
 * functionality on again later restores the previous setup rather than
 * silently wiping the vendor's work.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return bool
 */
function zymarg_vd_premium_withdraw( $vendor_id, $feature ) {
	if ( ! zymarg_vd_premium_is_feature( $feature ) || $vendor_id <= 0 ) {
		return false;
	}

	zymarg_vd_premium_set_state(
		$vendor_id,
		$feature,
		array(
			'status'     => ZYMARG_VD_PREMIUM_OFF,
			'decided_at' => current_time( 'mysql' ),
			'decided_by' => 0,
		)
	);

	return true;
}

/**
 * Admin approves a vendor's request.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @param string $note      Optional note shown to the vendor.
 * @return bool
 */
function zymarg_vd_premium_approve( $vendor_id, $feature, $note = '' ) {
	if ( ! zymarg_vd_premium_is_feature( $feature ) || $vendor_id <= 0 ) {
		return false;
	}

	zymarg_vd_premium_set_state(
		$vendor_id,
		$feature,
		array(
			'status'     => ZYMARG_VD_PREMIUM_APPROVED,
			'decided_at' => current_time( 'mysql' ),
			'decided_by' => get_current_user_id(),
			'note'       => $note,
		)
	);

	return true;
}

/**
 * Admin rejects a vendor's request.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @param string $note      Optional reason shown to the vendor.
 * @return bool
 */
function zymarg_vd_premium_reject( $vendor_id, $feature, $note = '' ) {
	if ( ! zymarg_vd_premium_is_feature( $feature ) || $vendor_id <= 0 ) {
		return false;
	}

	zymarg_vd_premium_set_state(
		$vendor_id,
		$feature,
		array(
			'status'     => ZYMARG_VD_PREMIUM_REJECTED,
			'decided_at' => current_time( 'mysql' ),
			'decided_by' => get_current_user_id(),
			'note'       => $note,
		)
	);

	return true;
}

/**
 * THE gate. Can this vendor actually use this functionality right now?
 *
 * Every render path -- vendor dashboard, product controls, store page, price
 * layer -- must go through this one function. Both conditions have to hold:
 * the admin master switch is on, AND this vendor is approved.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return bool
 */
function zymarg_vd_premium_vendor_can_use( $vendor_id, $feature ) {
	if ( ! zymarg_vd_premium_master_enabled( $feature ) ) {
		return false;
	}
	return ZYMARG_VD_PREMIUM_APPROVED === zymarg_vd_premium_get_status( $vendor_id, $feature );
}

/**
 * Every vendor currently waiting on a decision.
 *
 * Powers the admin approval queue in phase 2.
 *
 * @param string $feature Optional feature key; omit for both.
 * @return array<int,array{vendor_id:int,feature:string,state:array}>
 */
function zymarg_vd_premium_pending_requests( $feature = '' ) {
	$features = zymarg_vd_premium_is_feature( $feature )
		? array( $feature )
		: zymarg_vd_premium_features();

	$meta_query = array( 'relation' => 'OR' );
	foreach ( $features as $key ) {
		$meta_query[] = array(
			'key'     => zymarg_vd_premium_state_meta_key( $key ),
			'compare' => 'EXISTS',
		);
	}

	$users = get_users(
		array(
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'fields'     => 'ID',
			'number'     => 500,
		)
	);

	$out = array();
	foreach ( $users as $vendor_id ) {
		foreach ( $features as $key ) {
			$state = zymarg_vd_premium_get_state( (int) $vendor_id, $key );
			if ( ZYMARG_VD_PREMIUM_PENDING !== $state['status'] ) {
				continue;
			}
			$out[] = array(
				'vendor_id' => (int) $vendor_id,
				'feature'   => $key,
				'state'     => $state,
			);
		}
	}

	return $out;
}

/**
 * How many requests are waiting. Drives the menu bubble in phase 2.
 *
 * @return int
 */
function zymarg_vd_premium_pending_count() {
	return count( zymarg_vd_premium_pending_requests() );
}

/**
 * Build a menu label with the WordPress-core-style count bubble appended.
 *
 * Matches the markup WordPress core itself uses for the Plugins update count
 * and WooCommerce's own order count (an <span class="update-plugins"> wrapping
 * a <span class="plugin-count">), so the bubble inherits WordPress's own menu
 * styling for free -- no bespoke CSS needed to look "native" in the sidebar.
 *
 * The bubble's own wrapper carries a stable class + data attribute so the
 * front-end JS (zymarg_vd_premium_admin_enqueue()) can find and update it
 * live without a page reload, the same way WooCommerce's own order badge
 * would need to be found and patched by any JS wanting to update it live.
 *
 * @param string $label Menu label text.
 * @return string Label HTML, with the bubble appended only when count > 0.
 */
function zymarg_vd_premium_menu_title_with_badge( $label ) {
	$count = zymarg_vd_premium_pending_count();

	if ( $count <= 0 ) {
		// Still wrap in the same markup, just empty and hidden, so the live
		// JS has one consistent element to find and show/update/hide later
		// without ever needing to inject brand-new markup into the DOM.
		return $label . ' <span class="update-plugins zvd-premium-badge zvd-is-hidden" data-zvd-premium-badge="1"><span class="plugin-count"></span></span>';
	}

	return $label . sprintf(
		' <span class="update-plugins zvd-premium-badge" data-zvd-premium-badge="1"><span class="plugin-count">%s</span></span>',
		esc_html( number_format_i18n( $count ) )
	);
}

/* ---------------------------------------------------------------------- *
 * 4. PRODUCT-LEVEL STATE
 * ---------------------------------------------------------------------- */

/**
 * Which vendor owns a product.
 *
 * @param int $product_id Product ID.
 * @return int Vendor user ID, or 0.
 */
function zymarg_vd_premium_product_vendor_id( $product_id ) {
	$post = get_post( (int) $product_id );
	return $post ? (int) $post->post_author : 0;
}

/**
 * Is this product one of its vendor's Featured Items, and allowed to be?
 *
 * The approval gate is applied here rather than at save time, so revoking a
 * vendor's approval instantly hides their featured products everywhere
 * without having to rewrite a single row of product meta.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function zymarg_vd_premium_is_featured_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return false;
	}
	if ( 'yes' !== get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FEATURED, true ) ) {
		return false;
	}

	$vendor_id = zymarg_vd_premium_product_vendor_id( $product_id );
	return zymarg_vd_premium_vendor_can_use( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED );
}

/**
 * Has this product been flagged for Premium in any way?
 *
 * Deliberately ignores approval state: this answers "is there Premium data on
 * this product", which is what the grid exclusion needs. A product whose
 * vendor lost approval must still stay out of grid queries.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function zymarg_vd_premium_product_is_flagged( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return false;
	}
	if ( 'yes' === get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FEATURED, true ) ) {
		return true;
	}
	return 'yes' === get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_ON, true );
}

/**
 * The flash-sale window and price stored on a product, unvalidated.
 *
 * @param int $product_id Product ID.
 * @return array{enabled:bool,price:string,start:string,end:string}
 */
function zymarg_vd_premium_get_flash_data( $product_id ) {
	$product_id = (int) $product_id;

	return array(
		'enabled' => 'yes' === get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_ON, true ),
		'price'   => (string) get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_PRICE, true ),
		'start'   => (string) get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_START, true ),
		'end'     => (string) get_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_END, true ),
	);
}

/**
 * Is a product's flash sale live at this moment?
 *
 * An empty start means "already running"; an empty end means "no end date".
 * All comparisons use site time, matching how the vendor entered the dates.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function zymarg_vd_premium_flash_is_live( $product_id ) {
	$data = zymarg_vd_premium_get_flash_data( $product_id );

	if ( ! $data['enabled'] || '' === $data['price'] ) {
		return false;
	}
	if ( (float) $data['price'] <= 0 ) {
		return false;
	}

	$vendor_id = zymarg_vd_premium_product_vendor_id( $product_id );
	if ( ! zymarg_vd_premium_vendor_can_use( $vendor_id, ZYMARG_VD_PREMIUM_FLASH ) ) {
		return false;
	}

	$now = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

	if ( '' !== $data['start'] ) {
		$start = strtotime( $data['start'] );
		if ( $start && $now < $start ) {
			return false;
		}
	}
	if ( '' !== $data['end'] ) {
		$end = strtotime( $data['end'] );
		if ( $end && $now > $end ) {
			return false;
		}
	}

	return true;
}

/**
 * The effective flash price for a product, or null when no flash is live.
 *
 * Guards against a flash price at or above the regular price, which would
 * quietly raise the price instead of discounting it.
 *
 * @param int   $product_id    Product ID.
 * @param float $regular_price Regular price to compare against.
 * @return float|null
 */
function zymarg_vd_premium_flash_price( $product_id, $regular_price = 0.0 ) {
	if ( ! zymarg_vd_premium_flash_is_live( $product_id ) ) {
		return null;
	}

	$data  = zymarg_vd_premium_get_flash_data( $product_id );
	$price = (float) $data['price'];

	if ( $regular_price > 0 && $price >= $regular_price ) {
		return null;
	}

	return $price;
}

/* ---------------------------------------------------------------------- *
 * 5. THE ISOLATED PRICE LAYER
 *
 * Runtime only. Never writes _sale_price. See the file header for why.
 * ---------------------------------------------------------------------- */

/**
 * Apply a live flash price to a simple product or a single variation.
 *
 * @param string|float $price   Current price.
 * @param object       $product WC_Product instance.
 * @return string|float
 */
function zymarg_vd_premium_filter_price( $price, $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
		return $price;
	}

	// A variation's flash data lives on its parent product.
	$product_id = (int) $product->get_id();
	if ( method_exists( $product, 'get_parent_id' ) ) {
		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id > 0 ) {
			$product_id = $parent_id;
		}
	}

	$flash = zymarg_vd_premium_flash_price( $product_id, (float) $price );

	return ( null === $flash ) ? $price : $flash;
}

/**
 * Apply a live flash price inside WooCommerce's variation price ranges.
 *
 * @param string|float $price     Current price.
 * @param object       $variation WC_Product_Variation instance.
 * @param object       $product   Parent WC_Product instance.
 * @return string|float
 */
function zymarg_vd_premium_filter_variation_price( $price, $variation, $product ) {
	unset( $variation );

	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
		return $price;
	}

	$flash = zymarg_vd_premium_flash_price( (int) $product->get_id(), (float) $price );

	return ( null === $flash ) ? $price : $flash;
}

/**
 * Bust WooCommerce's variation price cache while a flash sale is live.
 *
 * Without this, WooCommerce would serve a cached price range from before the
 * flash started -- or keep serving the discounted range after it ended.
 *
 * @param array  $hash    Cache hash components.
 * @param object $product Parent WC_Product instance.
 * @return array
 */
function zymarg_vd_premium_variation_prices_hash( $hash, $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
		return $hash;
	}

	$product_id = (int) $product->get_id();
	if ( zymarg_vd_premium_flash_is_live( $product_id ) ) {
		$data                    = zymarg_vd_premium_get_flash_data( $product_id );
		$hash['zymarg_vd_flash'] = $data['price'] . '|' . $data['start'] . '|' . $data['end'];
	}

	return $hash;
}

/**
 * Register the price layer. WooCommerce only.
 *
 * Priority 99 so the flash price is the last word, and any earlier pricing
 * logic still gets to run first.
 *
 * @return void
 */
function zymarg_vd_premium_register_price_layer() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_filter( 'woocommerce_product_get_price', 'zymarg_vd_premium_filter_price', 99, 2 );
	add_filter( 'woocommerce_product_variation_get_price', 'zymarg_vd_premium_filter_price', 99, 2 );
	add_filter( 'woocommerce_variation_prices_price', 'zymarg_vd_premium_filter_variation_price', 99, 3 );
	add_filter( 'woocommerce_get_variation_prices_hash', 'zymarg_vd_premium_variation_prices_hash', 99, 2 );
}
add_action( 'init', 'zymarg_vd_premium_register_price_layer' );

/* ---------------------------------------------------------------------- *
 * 6. LAYER 3 -- DEFENSIVE EXCLUSION FROM PRODUCT GRID / HOMEPAGE
 *
 * Hooks the Product Grid's own public filter. The Product Grid plugin is NOT
 * modified: `zymarg_wcpg_query_args` is applied in Source_All::build_args(),
 * and Source_Featured extends Source_All, so every grid source that matters
 * passes through here.
 * ---------------------------------------------------------------------- */

/**
 * Strip Premium products out of every ZYMARG Product Grid query.
 *
 * Matches products whose Premium meta is missing OR not 'yes', which keeps
 * ordinary products untouched while removing anything a vendor has flagged.
 *
 * @param array $args     WP_Query args being built by the grid.
 * @param array $settings Grid settings for this instance.
 * @return array
 */
function zymarg_vd_premium_exclude_from_grid_args( $args, $settings = array() ) {
	/**
	 * Allow this exclusion to be switched off for a specific grid.
	 *
	 * Leave it alone unless you have a very good reason: turning it off is
	 * what lets vendor Premium products leak onto the homepage.
	 *
	 * @param bool  $exclude  Whether to exclude Premium products.
	 * @param array $settings Grid settings for this instance.
	 */
	if ( ! apply_filters( 'zymarg_vd_premium_exclude_from_grid', true, $settings ) ) {
		return $args;
	}

	$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
		? $args['meta_query']
		: array();

	foreach ( array( ZYMARG_VD_PREMIUM_META_FEATURED, ZYMARG_VD_PREMIUM_META_FLASH_ON ) as $meta_key ) {
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => $meta_key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $meta_key,
				'value'   => 'yes',
				'compare' => '!=',
			),
		);
	}

	if ( count( $meta_query ) > 1 && empty( $meta_query['relation'] ) ) {
		$meta_query['relation'] = 'AND';
	}

	$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

	return $args;
}
add_filter( 'zymarg_wcpg_query_args', 'zymarg_vd_premium_exclude_from_grid_args', 20, 2 );

/* ---------------------------------------------------------------------- *
 * 7. QUERY HELPERS FOR THE PREMIUM RENDER PATHS
 *
 * These are the ONLY sanctioned way to fetch Premium products. They are used
 * by the store page in phase 5. They are not registered as grid sources.
 * ---------------------------------------------------------------------- */

/**
 * A vendor's live Featured Items.
 *
 * Returns an empty array unless the vendor is approved, so a caller can render
 * unconditionally and the section simply disappears when approval is missing.
 *
 * @param int $vendor_id Vendor user ID.
 * @param int $limit     Maximum products.
 * @return array<int,int> Product IDs.
 */
function zymarg_vd_premium_get_vendor_featured_ids( $vendor_id, $limit = 12 ) {
	$vendor_id = (int) $vendor_id;
	if ( ! zymarg_vd_premium_vendor_can_use( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED ) ) {
		return array();
	}

	// Go-live gate. The section stays off the store page until the vendor has
	// picked the minimum the admin set. Checked at READ time, so lowering the
	// minimum publishes an existing selection instantly, with no data rewrite.
	if ( ! zymarg_vd_premium_featured_is_live( $vendor_id ) ) {
		return array();
	}

	// The admin's maximum always beats whatever the caller asked for.
	$limit = max( 1, min( max( 1, (int) $limit ), zymarg_vd_premium_max_for( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED ) ) );

	return get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'author'           => $vendor_id,
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => ZYMARG_VD_PREMIUM_META_FEATURED,
					'value' => 'yes',
				),
			),
		)
	);
}

/**
 * A vendor's currently-live Flash Sale products.
 *
 * The date window is re-checked in PHP rather than in SQL, so the definition
 * of "live" lives in exactly one place: zymarg_vd_premium_flash_is_live().
 *
 * @param int $vendor_id Vendor user ID.
 * @param int $limit     Maximum products.
 * @return array<int,int> Product IDs.
 */
function zymarg_vd_premium_get_vendor_flash_ids( $vendor_id, $limit = 12 ) {
	$vendor_id = (int) $vendor_id;
	if ( ! zymarg_vd_premium_vendor_can_use( $vendor_id, ZYMARG_VD_PREMIUM_FLASH ) ) {
		return array();
	}

	// Flash Sale has no go-live minimum -- one timed discount is a real offer.
	// The admin's maximum still applies.
	$limit = max( 1, min( max( 1, (int) $limit ), zymarg_vd_premium_max_for( $vendor_id, ZYMARG_VD_PREMIUM_FLASH ) ) );

	$candidates = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'author'           => $vendor_id,
			'posts_per_page'   => $limit * 3,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => ZYMARG_VD_PREMIUM_META_FLASH_ON,
					'value' => 'yes',
				),
			),
		)
	);

	$live = array();
	foreach ( $candidates as $product_id ) {
		if ( zymarg_vd_premium_flash_is_live( (int) $product_id ) ) {
			$live[] = (int) $product_id;
		}
		if ( count( $live ) >= (int) $limit ) {
			break;
		}
	}

	return $live;
}

/* ====================================================================== *
 * DISPLAY SETTINGS AND PER-VENDOR LIMITS                      v1.41.0
 *
 * The admin owns every number here. Vendors pick which products, never how
 * many and never how they move.
 *
 * WHY A SEPARATE OPTION
 * ---------------------
 * zymarg_vd_premium_update_settings() rewrites ZYMARG_VD_PREMIUM_OPTION with
 * only the feature booleans. Anything else stored alongside them would be
 * silently destroyed the next time an admin saved the master switches, so
 * these settings live in their own option.
 *
 * FEATURED MINIMUM
 * ----------------
 * A vendor may save fewer than the minimum -- they need to be able to build
 * the selection up over several visits. The minimum is a GO-LIVE gate: the
 * section stays off the store page until it is met.
 * ====================================================================== */

const ZYMARG_VD_PREMIUM_DISPLAY_OPTION = 'zymarg_vd_premium_display';
const ZYMARG_VD_PREMIUM_META_LIMITS    = '_zymarg_vd_premium_limits';

/**
 * Factory defaults for the display settings.
 *
 * Speed names and ranges deliberately mirror the ZYMARG Homepage plugin's
 * rotation controls, so the two admin screens read the same way.
 *
 * @return array<string,mixed>
 */
function zymarg_vd_premium_display_defaults() {
	return array(
		'featured_min'    => 6,
		'featured_max'    => 10,
		'flash_max'       => 10,
		'layout'          => 'grid',
		'rotation'        => 'step',
		'marquee_speed'   => 40,
		'glide_speed'     => 400,
		// v1.46.14: responsive column counts, used only when layout is 'grid'.
		// Carousel has no column count of its own -- it already has its own
		// speed/rotation controls above, which is why these three are only
		// ever read by the store page's grid layout, never its carousel one.
		// Shared between the Flash Sale and Featured Items sections, since
		// both render on the same store page and both use the same grid.
		'columns_desktop' => 4,
		'columns_tablet'  => 3,
		'columns_mobile'  => 2,
	);
}

/**
 * Clamp an integer into a range, falling back when it is not usable.
 *
 * @param mixed $value    Raw value.
 * @param int   $min      Lowest allowed.
 * @param int   $max      Highest allowed.
 * @param int   $fallback Value to use when empty or non-numeric.
 * @return int
 */
function zymarg_vd_premium_clamp_int( $value, $min, $max, $fallback ) {
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return (int) $fallback;
	}

	$value = (int) $value;
	if ( $value < $min ) {
		return (int) $min;
	}
	if ( $value > $max ) {
		return (int) $max;
	}

	return $value;
}

/**
 * Sanitize a set of display settings.
 *
 * @param array $raw Raw values.
 * @return array<string,mixed>
 */
function zymarg_vd_premium_sanitize_display( array $raw ) {
	$defaults = zymarg_vd_premium_display_defaults();

	$featured_max = zymarg_vd_premium_clamp_int(
		isset( $raw['featured_max'] ) ? $raw['featured_max'] : null,
		1,
		50,
		$defaults['featured_max']
	);

	$featured_min = zymarg_vd_premium_clamp_int(
		isset( $raw['featured_min'] ) ? $raw['featured_min'] : null,
		0,
		50,
		$defaults['featured_min']
	);

	// A minimum above the maximum is unsatisfiable: the vendor could never
	// reach it, so the section would never go live and nobody could tell why.
	if ( $featured_min > $featured_max ) {
		$featured_min = $featured_max;
	}

	$layout   = ( isset( $raw['layout'] ) && 'carousel' === $raw['layout'] ) ? 'carousel' : 'grid';
	$rotation = ( isset( $raw['rotation'] ) && 'continuous' === $raw['rotation'] ) ? 'continuous' : 'step';

	return array(
		'featured_min'  => $featured_min,
		'featured_max'  => $featured_max,
		'flash_max'     => zymarg_vd_premium_clamp_int(
			isset( $raw['flash_max'] ) ? $raw['flash_max'] : null,
			1,
			50,
			$defaults['flash_max']
		),
		'layout'        => $layout,
		'rotation'      => $rotation,
		'marquee_speed' => zymarg_vd_premium_clamp_int(
			isset( $raw['marquee_speed'] ) ? $raw['marquee_speed'] : null,
			10,
			200,
			$defaults['marquee_speed']
		),
		'glide_speed'   => zymarg_vd_premium_clamp_int(
			isset( $raw['glide_speed'] ) ? $raw['glide_speed'] : null,
			100,
			3000,
			$defaults['glide_speed']
		),
		// v1.46.14: 1-6 mirrors the column bound the Product Grid engine
		// itself enforces (Config_Normalizer::NUMERIC_BOUNDS, 'layout.columns'
		// => [1, 6]), so a value saved here can never be clamped a second time,
		// silently, further down the pipeline.
		'columns_desktop' => zymarg_vd_premium_clamp_int(
			isset( $raw['columns_desktop'] ) ? $raw['columns_desktop'] : null,
			1,
			6,
			$defaults['columns_desktop']
		),
		'columns_tablet'  => zymarg_vd_premium_clamp_int(
			isset( $raw['columns_tablet'] ) ? $raw['columns_tablet'] : null,
			1,
			6,
			$defaults['columns_tablet']
		),
		'columns_mobile'  => zymarg_vd_premium_clamp_int(
			isset( $raw['columns_mobile'] ) ? $raw['columns_mobile'] : null,
			1,
			6,
			$defaults['columns_mobile']
		),
	);
}

/**
 * The current display settings, always complete and in range.
 *
 * @return array<string,mixed>
 */
function zymarg_vd_premium_display_settings() {
	$saved = get_option( ZYMARG_VD_PREMIUM_DISPLAY_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	return zymarg_vd_premium_sanitize_display( $saved );
}

/**
 * Save the display settings.
 *
 * @param array $raw Raw values.
 * @return array<string,mixed> The stored settings.
 */
function zymarg_vd_premium_update_display_settings( array $raw ) {
	$clean = zymarg_vd_premium_sanitize_display( $raw );
	update_option( ZYMARG_VD_PREMIUM_DISPLAY_OPTION, $clean );

	/**
	 * Fires after the Premium display settings change.
	 *
	 * @param array<string,mixed> $clean New settings.
	 */
	do_action( 'zymarg_vd_premium_display_updated', $clean );

	return $clean;
}

/**
 * A vendor's limit overrides. Empty values mean "use the global setting".
 *
 * @param int $vendor_id Vendor user ID.
 * @return array<string,int|null>
 */
function zymarg_vd_premium_vendor_limit_overrides( $vendor_id ) {
	$saved = get_user_meta( (int) $vendor_id, ZYMARG_VD_PREMIUM_META_LIMITS, true );
	$saved = is_array( $saved ) ? $saved : array();

	$out = array();
	foreach ( array( 'featured_min', 'featured_max', 'flash_max' ) as $key ) {
		$out[ $key ] = ( isset( $saved[ $key ] ) && '' !== $saved[ $key ] && null !== $saved[ $key ] )
			? (int) $saved[ $key ]
			: null;
	}

	return $out;
}

/**
 * Save a vendor's limit overrides. Blank values clear the override.
 *
 * @param int   $vendor_id Vendor user ID.
 * @param array $raw       Raw values.
 * @return array<string,int|null> The stored overrides.
 */
function zymarg_vd_premium_update_vendor_limits( $vendor_id, array $raw ) {
	$vendor_id = (int) $vendor_id;
	$clean     = array();

	foreach ( array( 'featured_min', 'featured_max', 'flash_max' ) as $key ) {
		$value = isset( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';

		if ( '' === $value || ! is_numeric( $value ) ) {
			continue;
		}

		$low          = ( 'featured_min' === $key ) ? 0 : 1;
		$clean[ $key ] = zymarg_vd_premium_clamp_int( $value, $low, 50, $low );
	}

	if ( empty( $clean ) ) {
		delete_user_meta( $vendor_id, ZYMARG_VD_PREMIUM_META_LIMITS );
	} else {
		update_user_meta( $vendor_id, ZYMARG_VD_PREMIUM_META_LIMITS, $clean );
	}

	return zymarg_vd_premium_vendor_limit_overrides( $vendor_id );
}

/**
 * The effective limits for one vendor: global settings plus any override.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array{featured_min:int,featured_max:int,flash_max:int}
 */
function zymarg_vd_premium_vendor_limits( $vendor_id ) {
	$settings  = zymarg_vd_premium_display_settings();
	$overrides = zymarg_vd_premium_vendor_limit_overrides( $vendor_id );

	$featured_max = ( null !== $overrides['featured_max'] ) ? $overrides['featured_max'] : $settings['featured_max'];
	$featured_min = ( null !== $overrides['featured_min'] ) ? $overrides['featured_min'] : $settings['featured_min'];

	// Same unsatisfiable-minimum guard as the global settings, applied after
	// the override is merged in.
	if ( $featured_min > $featured_max ) {
		$featured_min = $featured_max;
	}

	return array(
		'featured_min' => (int) $featured_min,
		'featured_max' => (int) $featured_max,
		'flash_max'    => (int) ( ( null !== $overrides['flash_max'] ) ? $overrides['flash_max'] : $settings['flash_max'] ),
	);
}

/**
 * How many products this vendor may pick for a functionality.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return int
 */
function zymarg_vd_premium_max_for( $vendor_id, $feature ) {
	$limits = zymarg_vd_premium_vendor_limits( $vendor_id );

	return ( ZYMARG_VD_PREMIUM_FEATURED === $feature )
		? $limits['featured_max']
		: $limits['flash_max'];
}

/**
 * How many products are needed before a functionality goes live.
 *
 * Flash Sale has no minimum: one timed discount is a perfectly good offer.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return int
 */
function zymarg_vd_premium_min_for( $vendor_id, $feature ) {
	if ( ZYMARG_VD_PREMIUM_FEATURED !== $feature ) {
		return 0;
	}

	$limits = zymarg_vd_premium_vendor_limits( $vendor_id );

	return $limits['featured_min'];
}

/**
 * How many products a vendor currently has flagged for a functionality.
 *
 * Counts what is actually stored, not what is on screen, so it stays honest
 * when a vendor has products the current search does not show.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return int
 */
function zymarg_vd_premium_selected_count( $vendor_id, $feature ) {
	return count( zymarg_vd_premium_selected_ids( $vendor_id, $feature ) );
}

/**
 * Every product ID a vendor has flagged for a functionality.
 *
 * Unlike the store-page getters this ignores approval, the go-live minimum
 * and the flash window: it is the raw stored selection, which is what the
 * vendor's own editing screen has to show them.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return array<int,int>
 */
function zymarg_vd_premium_selected_ids( $vendor_id, $feature ) {
	$vendor_id = (int) $vendor_id;
	if ( $vendor_id <= 0 ) {
		return array();
	}

	$meta_key = ( ZYMARG_VD_PREMIUM_FEATURED === $feature )
		? ZYMARG_VD_PREMIUM_META_FEATURED
		: ZYMARG_VD_PREMIUM_META_FLASH_ON;

	$ids = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'author'           => $vendor_id,
			'posts_per_page'   => 100,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => $meta_key,
					'value' => 'yes',
				),
			),
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Has this vendor's Featured section met its go-live minimum?
 *
 * @param int $vendor_id Vendor user ID.
 * @return bool
 */
function zymarg_vd_premium_featured_is_live( $vendor_id ) {
	$min = zymarg_vd_premium_min_for( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED );
	if ( $min <= 0 ) {
		return true;
	}

	return zymarg_vd_premium_selected_count( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED ) >= $min;
}
