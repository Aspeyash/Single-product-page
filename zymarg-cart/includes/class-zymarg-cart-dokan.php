<?php
/**
 * Dokan Pro integration for ZYMARG Cart.
 *
 * Provides all vendor-aware data that Widget 2 needs to render the cart body:
 * - Groups cart items by Dokan vendor/store.
 * - Fetches vendor display info (name, URL, avatar, banner).
 * - Reads per-vendor shipping packages from WooCommerce/Dokan.
 * - Calculates per-vendor subtotals and tax from selected items.
 * - Applies and removes WC / Dokan vendor coupons with detailed feedback.
 *
 * Compatibility:
 * - Designed for Dokan Pro 3.x and above.
 * - Falls back gracefully when Dokan functions are unavailable so the plugin
 *   does not cause a fatal error on sites where Dokan is temporarily disabled.
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Dokan {

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// =========================================================================
	// AVAILABILITY & COMPATIBILITY
	// =========================================================================

	/**
	 * Returns true when all required Dokan functions are present and callable.
	 *
	 * Called before every method that delegates to Dokan so the plugin never
	 * fatals if Dokan is deactivated after ZYMARG Cart is installed.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return (
			function_exists( 'dokan_get_seller_id_by_product' ) &&
			function_exists( 'dokan_get_store_url' ) &&
			function_exists( 'dokan_get_store_info' )
		);
	}

	/**
	 * Returns an associative array describing the current Dokan environment.
	 * Used by the admin-notice system if Dokan requirements are not met.
	 *
	 * @return array{available: bool, version: string|null, has_pro: bool}
	 */
	public static function environment_info(): array {
		return [
			'available' => self::is_available(),
			'version'   => defined( 'DOKAN_PLUGIN_VERSION' )
				? DOKAN_PLUGIN_VERSION
				: ( defined( 'DOKAN_PRO_PLUGIN_VERSION' ) ? DOKAN_PRO_PLUGIN_VERSION : null ),
			'has_pro'   => defined( 'DOKAN_PRO_PLUGIN_VERSION' ),
		];
	}

	// =========================================================================
	// CART GROUPING — Primary method for Widget 2 template
	// =========================================================================

	/**
	 * Groups the current WooCommerce cart items by Dokan vendor/store.
	 *
	 * Returns a keyed array where each key is a vendor ID and the value
	 * contains everything Widget 2 needs to render one vendor block:
	 * vendor display info, all product rows, subtotals, shipping, and tax.
	 *
	 * @param array<int, string> $selected_keys Cart item keys currently checked
	 *                                          for checkout. Pass an empty array
	 *                                          to treat all items as selected.
	 *
	 * @return array<int, array<string, mixed>> Vendor-keyed cart data.
	 *                                          Empty array when cart is empty.
	 */
	public static function get_cart_grouped_by_vendor( array $selected_keys = [] ): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return [];
		}

		$cart_items = WC()->cart->get_cart();
		if ( empty( $cart_items ) ) {
			return [];
		}

		// Cache shipping lookups — one WC shipping calculation per request.
		$shipping_cache = [];
		$groups         = [];

		foreach ( $cart_items as $cart_item_key => $cart_item ) {
			$product_id = (int) ( $cart_item['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			$vendor_id   = self::get_vendor_id_by_product( $product_id );
			// Empty selected_keys means no items are checked (page load default).
			// Only mark selected when the key is explicitly in the list.
			$is_selected = ! empty( $selected_keys ) &&
				in_array( $cart_item_key, $selected_keys, true );

			// Initialise this vendor's group on first encounter.
			if ( ! isset( $groups[ $vendor_id ] ) ) {
				if ( ! isset( $shipping_cache[ $vendor_id ] ) ) {
					$shipping_cache[ $vendor_id ] = self::get_vendor_shipping( $vendor_id );
				}

				$groups[ $vendor_id ] = [
					'vendor_id'         => $vendor_id,
					'vendor_info'       => self::get_vendor_info( $vendor_id ),
					'items'             => [],
					'subtotal'          => 0.0,
					'selected_subtotal' => 0.0,
					'shipping'          => $shipping_cache[ $vendor_id ],
					'tax'               => 0.0,
					'item_count'        => 0,
					'selected_count'    => 0,
				];
			}

			// Build full item display data for the template.
			$item_data = self::build_item_display_data(
				$cart_item_key,
				$cart_item,
				$is_selected,
				$vendor_id
			);

			$groups[ $vendor_id ]['items'][ $cart_item_key ] = $item_data;
			$groups[ $vendor_id ]['subtotal']   += $item_data['line_subtotal'];
			$groups[ $vendor_id ]['item_count'] += 1;

			if ( $is_selected ) {
				$groups[ $vendor_id ]['selected_subtotal'] += $item_data['line_subtotal'];
				$groups[ $vendor_id ]['selected_count']    += 1;
			}
		}

		// Resolve per-vendor tax after all items are grouped.
		foreach ( array_keys( $groups ) as $vendor_id ) {
			$v_selected_keys = empty( $selected_keys )
				? array_keys( $groups[ $vendor_id ]['items'] )
				: array_intersect( array_keys( $groups[ $vendor_id ]['items'] ), $selected_keys );

			$groups[ $vendor_id ]['tax'] = self::get_vendor_tax( $vendor_id, array_values( $v_selected_keys ) );
		}

		/**
		 * Filters the fully-built vendor-grouped cart data before it is
		 * passed to Widget 2's template.
		 *
		 * @param array<int, array<string, mixed>> $groups        Vendor-grouped cart.
		 * @param array<int, string>               $selected_keys Selected cart item keys.
		 */
		return (array) apply_filters( 'zymarg_cart_grouped_by_vendor', $groups, $selected_keys );
	}

	// =========================================================================
	// VENDOR INFO
	// =========================================================================

	/**
	 * Returns display-safe info for a Dokan vendor/store.
	 *
	 * Tries the Dokan API first, falls back to WordPress user-meta, then to
	 * WordPress display name so there is always something to show.
	 *
	 * @param int $vendor_id WordPress user ID of the vendor.
	 *
	 * @return array{vendor_id: int, store_name: string, store_url: string, avatar_url: string, banner_url: string}
	 */
	public static function get_vendor_info( int $vendor_id ): array {
		if ( $vendor_id <= 0 ) {
			return self::unknown_vendor_info();
		}

		// ── Store name ────────────────────────────────────────────────────────
		$store_info = function_exists( 'dokan_get_store_info' )
			? (array) dokan_get_store_info( $vendor_id )
			: [];

		$store_name = (string) ( $store_info['store_name'] ?? '' );

		if ( empty( $store_name ) ) {
			$store_name = (string) get_user_meta( $vendor_id, 'dokan_store_name', true );
		}

		if ( empty( $store_name ) ) {
			$user       = get_userdata( $vendor_id );
			$store_name = $user ? $user->display_name : __( 'Unknown Store', 'zymarg-cart' );
		}

		// ── Store URL ─────────────────────────────────────────────────────────
		$store_url = function_exists( 'dokan_get_store_url' )
			? (string) dokan_get_store_url( $vendor_id )
			: (string) get_author_posts_url( $vendor_id );

		// ── Avatar / logo ─────────────────────────────────────────────────────
		$avatar_url = '';
		$gravatar   = $store_info['gravatar'] ?? '';
		$avatar_id  = is_numeric( $gravatar ) ? (int) $gravatar : 0;

		if ( $avatar_id > 0 ) {
			$avatar_url = (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' );
		}

		if ( empty( $avatar_url ) ) {
			$avatar_url = (string) get_avatar_url(
				$vendor_id,
				[ 'size' => 60, 'default' => 'mysteryman' ]
			);
		}

		// ── Banner ────────────────────────────────────────────────────────────
		$banner_url = '';
		$banner     = $store_info['banner'] ?? '';
		$banner_id  = is_numeric( $banner ) ? (int) $banner : 0;

		if ( $banner_id > 0 ) {
			$banner_url = (string) wp_get_attachment_image_url( $banner_id, 'large' );
		}

		return [
			'vendor_id'   => $vendor_id,
			'store_name'  => wp_strip_all_tags( $store_name ),
			'store_url'   => esc_url( $store_url ),
			'avatar_url'  => esc_url( $avatar_url ),
			'banner_url'  => esc_url( $banner_url ),
		];
	}

	// =========================================================================
	// VENDOR ID RESOLUTION
	// =========================================================================

	/**
	 * Returns the Dokan vendor/seller ID for a given product.
	 *
	 * Falls back to the post author ID when the Dokan function is unavailable.
	 * Returns 0 if the product cannot be found.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return int Vendor user ID, or 0.
	 */
	public static function get_vendor_id_by_product( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'dokan_get_seller_id_by_product' ) ) {
			return (int) dokan_get_seller_id_by_product( $product_id );
		}

		// Fallback: read post author directly.
		$post = get_post( $product_id );
		return $post ? (int) $post->post_author : 0;
	}

	/**
	 * Returns an array of unique vendor IDs currently present in the WC cart.
	 *
	 * @return array<int, int>
	 */
	public static function get_cart_vendor_ids(): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return [];
		}

		$vendor_ids = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( $product_id > 0 ) {
				$vendor_ids[] = self::get_vendor_id_by_product( $product_id );
			}
		}

		return array_values( array_unique( array_filter( $vendor_ids ) ) );
	}

	// =========================================================================
	// SHIPPING
	// =========================================================================

	/**
	 * Returns shipping data for a vendor's package.
	 *
	 * Dokan Pro splits the WC cart into one shipping package per vendor via
	 * the woocommerce_cart_shipping_packages filter. This method locates the
	 * package for the given vendor and reads the first (or chosen) rate.
	 *
	 * Returns a "Calculated at checkout" placeholder when:
	 * - No destination address is known yet.
	 * - Dokan has not yet split packages.
	 * - No rates have been calculated for this package.
	 *
	 * @param int $vendor_id Dokan vendor user ID.
	 *
	 * @return array{status: string, cost: float, label: string, method_id: string, html: string}
	 */
	public static function get_vendor_shipping( int $vendor_id ): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return self::shipping_pending();
		}

		try {
			$packages = WC()->cart->get_shipping_packages();
		} catch ( \Throwable $e ) {
			Zymarg_Cart_Helpers::log(
				'Dokan::get_vendor_shipping — exception reading packages.',
				[ 'error' => $e->getMessage() ],
				'warning'
			);
			return self::shipping_pending();
		}

		$package_index = 0;

		foreach ( $packages as $pkg_key => $package ) {
			$pkg_vendor = self::get_package_vendor_id( $package );

			if ( $pkg_vendor !== $vendor_id ) {
				++$package_index;
				continue;
			}

			// Try rates already attached to the package array.
			$rates = is_array( $package['rates'] ?? null ) ? $package['rates'] : [];

			// If not there, try the WC session cache.
			if ( empty( $rates ) ) {
				$session_data = WC()->session?->get( 'shipping_for_package_' . $package_index );
				if ( is_array( $session_data ) && ! empty( $session_data ) ) {
					$rates = $session_data;
				}
			}

			if ( empty( $rates ) ) {
				return self::shipping_pending();
			}

			// Determine the chosen method for this package index.
			$chosen_methods = (array) ( WC()->session?->get( 'chosen_shipping_methods' ) ?? [] );
			$method_key     = $chosen_methods[ $package_index ] ?? null;

			if ( $method_key === null || ! isset( $rates[ $method_key ] ) ) {
				$method_key = array_key_first( $rates );
			}

			if ( ! isset( $rates[ $method_key ] ) ) {
				return self::shipping_pending();
			}

			return self::build_shipping_result( $rates[ $method_key ] );
		}

		return self::shipping_pending();
	}

	// =========================================================================
	// SUBTOTALS & TAX
	// =========================================================================

	/**
	 * Calculates the subtotal (unit price × qty) for a vendor's items.
	 * When $selected_keys is provided, only those items are counted.
	 *
	 * @param int                $vendor_id    Dokan vendor user ID.
	 * @param array<int, string> $selected_keys Cart item keys to include (empty = all).
	 *
	 * @return float
	 */
	public static function get_vendor_subtotal(
		int $vendor_id,
		array $selected_keys = []
	): float {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return 0.0;
		}

		$subtotal = 0.0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $selected_keys ) && ! in_array( $key, $selected_keys, true ) ) {
				continue;
			}

			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( self::get_vendor_id_by_product( $product_id ) !== $vendor_id ) {
				continue;
			}

			$price_id = (int) ( $item['variation_id'] ?? 0 ) ?: $product_id;
			$product  = $item['data'] instanceof \WC_Product
				? $item['data']
				: wc_get_product( $price_id );

			if ( $product instanceof \WC_Product ) {
				$subtotal += (float) $product->get_price() * (int) ( $item['quantity'] ?? 1 );
			}
		}

		return round( $subtotal, wc_get_price_decimals() );
	}

	/**
	 * Returns the total WooCommerce-calculated line tax for a vendor's items.
	 * When $selected_keys is provided, only those items are counted.
	 *
	 * @param int                $vendor_id     Dokan vendor user ID.
	 * @param array<int, string> $selected_keys Cart item keys to include (empty = all).
	 *
	 * @return float
	 */
	public static function get_vendor_tax(
		int $vendor_id,
		array $selected_keys = []
	): float {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return 0.0;
		}

		$tax_total = 0.0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $selected_keys ) && ! in_array( $key, $selected_keys, true ) ) {
				continue;
			}

			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( self::get_vendor_id_by_product( $product_id ) !== $vendor_id ) {
				continue;
			}

			$tax_total += (float) ( $item['line_tax'] ?? 0.0 );
		}

		return round( $tax_total, wc_get_price_decimals() );
	}

	// =========================================================================
	// COUPONS
	// =========================================================================

	/**
	 * Applies a coupon code to the WC cart with detailed structured feedback.
	 *
	 * Handles both standard WooCommerce per-product coupons and Dokan Pro
	 * vendor coupons — Dokan integrates its coupon system with WC so a single
	 * apply_coupon() call works for both types.
	 *
	 * @param string $coupon_code Raw coupon code from the customer.
	 * @param int    $product_id  Optional: product context for error messaging.
	 * @param int    $vendor_id   Optional: vendor context for error messaging.
	 *
	 * @return array{success: bool, type: string, message: string, discount: float, discount_html: string, coupon_code: string}
	 */
	public static function apply_coupon(
		string $coupon_code,
		int $product_id  = 0,
		int $vendor_id   = 0
	): array {
		$coupon_code = wc_format_coupon_code( sanitize_text_field( $coupon_code ) );

		if ( empty( $coupon_code ) ) {
			return self::coupon_error( 'empty', __( 'Please enter a coupon code.', 'zymarg-cart' ) );
		}

		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return self::coupon_error( 'unavailable', __( 'Cart unavailable. Please refresh and try again.', 'zymarg-cart' ) );
		}

		// Already applied?
		if ( WC()->cart->has_discount( $coupon_code ) ) {
			return self::coupon_error( 'already_applied', __( 'This coupon is already applied.', 'zymarg-cart' ) );
		}

		// Pre-validate via WC_Coupon before touching the cart.
		$coupon = new \WC_Coupon( $coupon_code );

		if ( ! $coupon->get_id() ) {
			return self::coupon_error( 'not_found', __( 'Coupon not found.', 'zymarg-cart' ) );
		}

		if ( $coupon->is_expired() ) {
			return self::coupon_error( 'expired', __( 'This coupon has expired.', 'zymarg-cart' ) );
		}

		// Clear existing notices so we can capture only this apply attempt.
		wc_clear_notices();

		$applied = WC()->cart->apply_coupon( $coupon_code );

		if ( $applied ) {
			WC()->cart->calculate_totals();

			$discount      = (float) WC()->cart->get_coupon_discount_amount( $coupon_code );
			$discount_html = wc_price( $discount );

			return [
				'success'       => true,
				'type'          => 'applied',
				'message'       => sprintf(
					/* translators: %s: Coupon code. */
					__( 'Coupon "%s" applied successfully.', 'zymarg-cart' ),
					esc_html( $coupon_code )
				),
				'discount'      => $discount,
				'discount_html' => $discount_html,
				'coupon_code'   => $coupon_code,
			];
		}

		// Collect the WC error message.
		$error_message = self::extract_wc_error_notice();
		wc_clear_notices();

		return self::coupon_error( 'invalid', $error_message ?: __( 'Invalid coupon code.', 'zymarg-cart' ) );
	}

	/**
	 * Removes an applied coupon from the cart.
	 *
	 * @param string $coupon_code The coupon code to remove.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function remove_coupon( string $coupon_code ): array {
		$coupon_code = wc_format_coupon_code( sanitize_text_field( $coupon_code ) );

		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return [
				'success' => false,
				'message' => __( 'Cart unavailable.', 'zymarg-cart' ),
			];
		}

		if ( ! WC()->cart->has_discount( $coupon_code ) ) {
			return [
				'success' => false,
				'message' => __( 'Coupon is not currently applied.', 'zymarg-cart' ),
			];
		}

		WC()->cart->remove_coupon( $coupon_code );
		WC()->cart->calculate_totals();

		return [
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Coupon code. */
				__( 'Coupon "%s" removed.', 'zymarg-cart' ),
				esc_html( $coupon_code )
			),
		];
	}

	/**
	 * Returns all applied coupons with their discount amounts and types.
	 *
	 * @return array<int, array{code: string, discount: float, discount_html: string, type: string}>
	 */
	public static function get_applied_coupons(): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return [];
		}

		$result = [];

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon   = new \WC_Coupon( $code );
			$discount = (float) WC()->cart->get_coupon_discount_amount( $code );

			$result[] = [
				'code'          => $code,
				'discount'      => $discount,
				'discount_html' => wc_price( $discount ),
				'type'          => $coupon->get_discount_type(),
				'description'   => $coupon->get_description(),
				'is_vendor_coupon' => (bool) get_post_meta( $coupon->get_id(), 'dokan_product_restriction', true ),
			];
		}

		return $result;
	}

	// =========================================================================
	// CART TOTALS (for Widget 3 AJAX response)
	// =========================================================================

	/**
	 * Returns a fully computed totals summary for the currently selected items.
	 *
	 * Called by Zymarg_Cart_Ajax::handle_zymarg_get_totals() to populate
	 * Widget 3's breakdown panel in real time.
	 *
	 * @param array<int, string> $selected_keys Cart item keys selected for checkout.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_totals_for_selected( array $selected_keys ): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return self::empty_totals();
		}

		$cart          = WC()->cart;
		$subtotal      = 0.0;
		$tax           = 0.0;
		$shipping      = 0.0;
		$selected_count = 0;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! in_array( $key, $selected_keys, true ) ) {
				continue;
			}

			$price_id = (int) ( $item['variation_id'] ?? 0 ) ?: (int) ( $item['product_id'] ?? 0 );
			$product  = $item['data'] instanceof \WC_Product
				? $item['data']
				: wc_get_product( $price_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$qty       = (int) ( $item['quantity'] ?? 1 );
			$subtotal += (float) $product->get_price() * $qty;
			$tax      += (float) ( $item['line_tax'] ?? 0.0 );
			++$selected_count;
		}

		// Sum shipping across all vendor packages for selected vendors.
		$selected_vendor_ids = self::get_vendor_ids_for_keys( $selected_keys );
		foreach ( $selected_vendor_ids as $vendor_id ) {
			$ship = self::get_vendor_shipping( $vendor_id );
			if ( $ship['status'] === 'calculated' ) {
				$shipping += $ship['cost'];
			}
		}

		// Applied coupon discounts (WC already applied these to line items,
		// but we surface them separately for Widget 3's breakdown display).
		$discount = (float) $cart->get_discount_total();

		$grand_total = $subtotal - $discount + $shipping + $tax;
		$grand_total = max( 0.0, $grand_total );

		// Build per-vendor subtotals for selected items only (v1.0.8).
		$vendor_subtotals = [];
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! in_array( $key, $selected_keys, true ) ) {
				continue;
			}
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$v_id       = self::get_vendor_id_by_product( $product_id );
			if ( ! $v_id ) {
				continue;
			}
			$price_id   = (int) ( $item['variation_id'] ?? 0 ) ?: $product_id;
			$product    = $item['data'] instanceof \WC_Product
				? $item['data']
				: wc_get_product( $price_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty = (int) ( $item['quantity'] ?? 1 );
			if ( ! isset( $vendor_subtotals[ $v_id ] ) ) {
				$vendor_subtotals[ $v_id ] = 0.0;
			}
			$vendor_subtotals[ $v_id ] += (float) $product->get_price() * $qty;
		}

		// Format vendor subtotals as HTML for the JS applyTotals() function.
		$vendor_subtotals_html = [];
		foreach ( $vendor_subtotals as $v_id => $v_sub ) {
			$vendor_subtotals_html[ $v_id ] = wc_price( round( $v_sub, wc_get_price_decimals() ) );
		}

		return [
			'subtotal'              => $subtotal,
			'subtotal_html'         => wc_price( $subtotal ),
			'discount'              => $discount,
			'discount_html'         => $discount > 0.0 ? wc_price( $discount ) : '',
			'shipping'              => $shipping,
			'shipping_html'         => $shipping > 0.0
				? wc_price( $shipping )
				: __( 'Calculated at checkout', 'zymarg-cart' ),
			'shipping_calculated'   => $shipping > 0.0,
			'tax'                   => $tax,
			'tax_html'              => wc_price( $tax ),
			'grand_total'           => $grand_total,
			'grand_total_html'      => wc_price( $grand_total ),
			'selected_count'        => $selected_count,
			'coupons'               => self::get_applied_coupons(),
			'vendor_subtotals_html' => $vendor_subtotals_html, // keyed by vendor_id (v1.0.8)
		];
	}

	// =========================================================================
	// PRIVATE — ITEM DISPLAY DATA
	// =========================================================================

	/**
	 * Builds the complete display data array for a single product row in
	 * Widget 2. This is the single source of truth for what the template sees.
	 *
	 * @param string               $cart_key   WC cart item key.
	 * @param array<string, mixed> $cart_item  Raw WC cart item array.
	 * @param bool                 $is_selected Whether this item is checked.
	 * @param int                  $vendor_id  Dokan vendor user ID.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_item_display_data(
		string $cart_key,
		array $cart_item,
		bool $is_selected,
		int $vendor_id
	): array {
		$product_id   = (int) ( $cart_item['product_id']   ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$quantity     = (int) ( $cart_item['quantity']     ?? 1 );
		$variation    = (array) ( $cart_item['variation']  ?? [] );

		// Prefer the data object already in the cart item to avoid a DB hit.
		$price_id = $variation_id > 0 ? $variation_id : $product_id;
		$product  = $cart_item['data'] instanceof \WC_Product
			? $cart_item['data']
			: wc_get_product( $price_id );
		$parent   = wc_get_product( $product_id );

		$unit_price    = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
		$line_subtotal = round( $unit_price * $quantity, wc_get_price_decimals() );

		// ── Product image ──────────────────────────────────────────────────
		$image_id  = $product instanceof \WC_Product ? (int) $product->get_image_id() : 0;
		$image_id  = $image_id ?: ( $parent instanceof \WC_Product ? (int) $parent->get_image_id() : 0 );
		$image_url = $image_id > 0
			? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
			: (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );

		// ── Stock status ───────────────────────────────────────────────────
		$stock = Zymarg_Cart_Helpers::get_stock_info( $price_id, $quantity );

		// ── Available variations (for variation switcher in cart) ──────────
		$available_variations = [];
		if ( $parent instanceof \WC_Product_Variable ) {
			foreach ( $parent->get_available_variations( 'array' ) as $var ) {
				$available_variations[] = [
					'variation_id'         => $var['variation_id'],
					'attributes'           => $var['attributes'],
					'display_price'        => $var['display_price'],
					'display_regular_price' => $var['display_regular_price'],
					'is_in_stock'          => $var['is_in_stock'],
					'variation_description' => $var['variation_description'],
				];
			}
		}

		// ── Save-for-later state ───────────────────────────────────────────
		$user_id = get_current_user_id();
		$is_saved = $user_id > 0
			? Zymarg_Cart_Usermeta::is_saved( $user_id, $product_id, $variation_id, $variation )
			: Zymarg_Cart_Session::is_saved( $product_id, $variation_id, $variation );

		return [
			// Core identifiers.
			'key'              => $cart_key,
			'product_id'       => $product_id,
			'variation_id'     => $variation_id,
			'quantity'         => $quantity,
			'variation'        => $variation,

			// WC product object — available for template logic.
			'data'             => $product,

			// Selection & vendor.
			'is_selected'      => $is_selected,
			'vendor_id'        => $vendor_id,

			// Pricing.
			'unit_price'       => $unit_price,
			'unit_price_html'  => wc_price( $unit_price ),
			'line_subtotal'    => $line_subtotal,
			'subtotal_html'    => wc_price( $line_subtotal ),

			// Display strings.
			'product_title'    => $parent instanceof \WC_Product
				? wp_strip_all_tags( $parent->get_name() )
				: '',
			'product_url'      => $parent instanceof \WC_Product
				? esc_url( (string) $parent->get_permalink() )
				: '',
			'product_image'    => esc_url( $image_url ),
			'sku'              => $product instanceof \WC_Product
				? (string) $product->get_sku()
				: '',
			'variation_labels' => Zymarg_Cart_Helpers::format_variation_labels( $variation ),

			// Stock.
			'is_in_stock'       => $stock['is_in_stock'],
			'stock_status'      => $stock['status'],
			'stock_qty'         => $stock['qty'],
			'low_stock'         => $stock['low_stock'],
			'insufficient_stock' => $stock['insufficient'],

			// Variations (for the variation dropdown in cart).
			'available_variations' => $available_variations,
			'is_variable'      => $parent instanceof \WC_Product_Variable,

			// Save for later.
			'is_saved'         => $is_saved,
		];
	}

	// =========================================================================
	// PRIVATE — HELPERS
	// =========================================================================

	/**
	 * Extracts the Dokan vendor/seller ID from a WC shipping package.
	 * Handles multiple Dokan versions that use different key names.
	 *
	 * @param array<string, mixed> $package WC shipping package.
	 * @return int Vendor ID, or 0 if not a Dokan package.
	 */
	private static function get_package_vendor_id( array $package ): int {
		// Dokan Pro: 'seller_id' key (most common).
		if ( isset( $package['seller_id'] ) ) {
			return (int) $package['seller_id'];
		}

		// Some Dokan versions use 'vendor_id'.
		if ( isset( $package['vendor_id'] ) ) {
			return (int) $package['vendor_id'];
		}

		// Last resort: read vendor from first item in the package.
		$contents = $package['contents'] ?? [];
		if ( ! empty( $contents ) ) {
			$first      = reset( $contents );
			$product_id = (int) ( $first['product_id'] ?? 0 );
			if ( $product_id > 0 ) {
				return self::get_vendor_id_by_product( $product_id );
			}
		}

		return 0;
	}

	/**
	 * Builds a shipping result array from a WC_Shipping_Rate or rate array.
	 *
	 * @param \WC_Shipping_Rate|array<string, mixed> $rate The rate object or array.
	 * @return array{status: string, cost: float, label: string, method_id: string, html: string}
	 */
	private static function build_shipping_result( mixed $rate ): array {
		if ( $rate instanceof \WC_Shipping_Rate ) {
			$cost      = (float) $rate->get_cost();
			$label     = (string) $rate->get_label();
			$method_id = (string) $rate->get_method_id();
		} else {
			$cost      = (float) ( $rate['cost']      ?? 0.0 );
			$label     = (string) ( $rate['label']     ?? __( 'Shipping', 'zymarg-cart' ) );
			$method_id = (string) ( $rate['method_id'] ?? '' );
		}

		return [
			'status'    => 'calculated',
			'cost'      => $cost,
			'label'     => wp_strip_all_tags( $label ),
			'method_id' => $method_id,
			'html'      => wc_price( $cost ),
		];
	}

	/**
	 * Returns all unique vendor IDs associated with a set of cart item keys.
	 *
	 * @param array<int, string> $cart_item_keys
	 * @return array<int, int>
	 */
	private static function get_vendor_ids_for_keys( array $cart_item_keys ): array {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return [];
		}

		$vendor_ids = [];
		$cart       = WC()->cart->get_cart();

		foreach ( $cart_item_keys as $key ) {
			if ( ! isset( $cart[ $key ] ) ) {
				continue;
			}
			$product_id  = (int) ( $cart[ $key ]['product_id'] ?? 0 );
			$vendor_ids[] = self::get_vendor_id_by_product( $product_id );
		}

		return array_values( array_unique( array_filter( $vendor_ids ) ) );
	}

	/**
	 * Extracts the first error message from the current WC notice queue.
	 *
	 * @return string Sanitised error text, or empty string.
	 */
	private static function extract_wc_error_notice(): string {
		$notices = wc_get_notices( 'error' );
		if ( empty( $notices ) ) {
			return '';
		}

		$first = reset( $notices );

		// WC 3.9+ stores notices as associative arrays; older versions as strings.
		$text = is_array( $first ) ? ( $first['notice'] ?? '' ) : $first;
		return wp_strip_all_tags( (string) $text );
	}

	/**
	 * Returns a structured coupon error response.
	 *
	 * @param string $type    Machine-readable error type.
	 * @param string $message Human-readable error message.
	 *
	 * @return array{success: false, type: string, message: string, discount: float, discount_html: string, coupon_code: string}
	 */
	private static function coupon_error( string $type, string $message ): array {
		return [
			'success'       => false,
			'type'          => $type,
			'message'       => $message,
			'discount'      => 0.0,
			'discount_html' => wc_price( 0.0 ),
			'coupon_code'   => '',
		];
	}

	/**
	 * Returns a "shipping pending" placeholder used when rates are not yet available.
	 *
	 * @return array{status: string, cost: float, label: string, method_id: string, html: string}
	 */
	private static function shipping_pending(): array {
		return [
			'status'    => 'pending',
			'cost'      => 0.0,
			'label'     => __( 'Calculated at checkout', 'zymarg-cart' ),
			'method_id' => '',
			'html'      => __( 'Calculated at checkout', 'zymarg-cart' ),
		];
	}

	/**
	 * Returns a placeholder vendor info array for unassigned products.
	 *
	 * @return array{vendor_id: int, store_name: string, store_url: string, avatar_url: string, banner_url: string}
	 */
	private static function unknown_vendor_info(): array {
		return [
			'vendor_id'  => 0,
			'store_name' => __( 'Store', 'zymarg-cart' ),
			'store_url'  => '',
			'avatar_url' => '',
			'banner_url' => '',
		];
	}

	/**
	 * Returns an empty totals structure used when the cart is unavailable.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_totals(): array {
		return [
			'subtotal'              => 0.0,
			'subtotal_html'         => wc_price( 0.0 ),
			'discount'              => 0.0,
			'discount_html'         => '',
			'shipping'              => 0.0,
			'shipping_html'         => __( 'Calculated at checkout', 'zymarg-cart' ),
			'shipping_calculated'   => false,
			'tax'                   => 0.0,
			'tax_html'              => wc_price( 0.0 ),
			'grand_total'           => 0.0,
			'grand_total_html'      => wc_price( 0.0 ),
			'selected_count'        => 0,
			'coupons'               => [],
			'vendor_subtotals_html' => [], // keyed by vendor_id (v1.0.8) — empty when cart unavailable.
		];
	}
}
