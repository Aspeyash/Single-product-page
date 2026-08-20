<?php
/**
 * AJAX handlers for ZYMARG Cart.
 *
 * Every interactive cart action (quantity update, variation change, coupon
 * apply, save for later, partial checkout, etc.) is handled here as a
 * static method on this class.
 *
 * Naming convention:
 *   public static function handle_{action_name}(): never
 *   where {action_name} matches the wp_ajax_* hook registered in the core class.
 *
 * Every handler follows the same pattern:
 *   1. Verify nonce — die with 403 JSON on failure.
 *   2. Sanitize all inputs.
 *   3. Validate business rules.
 *   4. Call the appropriate data-layer / Dokan / Partial class.
 *   5. Send a structured JSON response via Zymarg_Cart_Helpers::send_success()
 *      or send_error() — both call wp_send_json() and exit.
 *
 * Response envelope (every response):
 * {
 *   "success": bool,
 *   "message": string,        // Human-readable (shown in UI)
 *   "nonce":   string,        // Fresh nonce for the next request
 *   "data":    {...}           // Handler-specific payload
 * }
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Ajax {

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// =========================================================================
	// SHARED PRIVATE HELPERS
	// =========================================================================

	/**
	 * Verifies the ZYMARG cart nonce from $_POST['nonce'].
	 * Terminates with a 403 JSON error response if the nonce is invalid
	 * or expired — the request must not proceed.
	 */
	private static function verify_nonce_or_die(): void {
		$nonce = self::get_post_string( 'nonce' );
		if ( ! Zymarg_Cart_Helpers::verify_nonce( $nonce ) ) {
			Zymarg_Cart_Helpers::send_error(
				__( 'Security check failed. Please refresh the page and try again.', 'zymarg-cart' ),
				403
			);
		}
	}

	/**
	 * Returns a sanitized string value from $_POST.
	 *
	 * @param string $key     POST key.
	 * @param string $default Fallback value.
	 */
	private static function get_post_string( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = $_POST[ $key ] ?? $default;
		return sanitize_text_field( wp_unslash( (string) $raw ) );
	}

	/**
	 * Returns a sanitized positive integer from $_POST.
	 *
	 * @param string $key     POST key.
	 * @param int    $default Fallback value.
	 */
	private static function get_post_int( string $key, int $default = 0 ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return max( 0, (int) ( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Decodes and sanitizes the JSON-encoded selected_keys array from $_POST.
	 * Returns an array of cart item key strings.
	 *
	 * @return array<int, string>
	 */
	private static function get_selected_keys(): array {
		$raw     = self::get_post_string( 'selected_keys', '[]' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		return array_map( 'sanitize_text_field', $decoded );
	}

	/**
	 * Decodes and sanitizes a JSON-encoded attributes object from $_POST.
	 * Returns a flat string-keyed array of variation attribute values.
	 *
	 * @param string $key POST key (default 'attributes').
	 * @return array<string, string>
	 */
	private static function get_post_attributes( string $key = 'attributes' ): array {
		$raw     = self::get_post_string( $key, '{}' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$clean = [];
		foreach ( $decoded as $attr => $value ) {
			$clean[ sanitize_key( $attr ) ] = sanitize_text_field( (string) $value );
		}
		return $clean;
	}

	/**
	 * Returns the current WC cart item count.
	 *
	 * @return int
	 */
	private static function current_item_count(): int {
		return Zymarg_Cart_Helpers::is_cart_available()
			? (int) WC()->cart->get_cart_contents_count()
			: 0;
	}

	/**
	 * Returns the current saved-for-later item count for the active user.
	 *
	 * @return int
	 */
	private static function current_saved_count(): int {
		$user_id = get_current_user_id();
		return $user_id > 0
			? Zymarg_Cart_Usermeta::count( $user_id )
			: Zymarg_Cart_Session::count();
	}

	/**
	 * Checks whether any items from a specific vendor remain in the WC cart
	 * after an item was removed / saved-for-later.
	 *
	 * @param int $vendor_id Dokan vendor user ID.
	 * @return bool
	 */
	private static function vendor_has_remaining_items( int $vendor_id ): bool {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( Zymarg_Cart_Dokan::get_vendor_id_by_product( $pid ) === $vendor_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a transient key for the "checkout initiated" lock scoped to
	 * the current user or session (prevents maybe_restore_cart from firing
	 * immediately after partial checkout initiates).
	 *
	 * @return string
	 */
	private static function checkout_lock_key(): string {
		if ( is_user_logged_in() ) {
			return 'zymarg_checkout_initiated_u_' . get_current_user_id();
		}
		$session = Zymarg_Cart_Helpers::get_wc_session();
		$sess_id = $session ? (string) $session->get_customer_id() : uniqid( 'a_', true );
		return 'zymarg_checkout_initiated_s_' . substr( md5( $sess_id ), 0, 12 );
	}

	// =========================================================================
	// HANDLER: Update quantity
	// =========================================================================

	/**
	 * Updates the quantity of a cart item.
	 *
	 * POST: cart_item_key, quantity, selected_keys (JSON)
	 * Returns: subtotal_html, vendor_subtotal_html, totals, item_count.
	 */
	public static function handle_zymarg_update_quantity(): void {
		self::verify_nonce_or_die();

		$cart_item_key = self::get_post_string( 'cart_item_key' );
		$quantity      = max( 1, self::get_post_int( 'quantity', 1 ) );
		$selected_keys = self::get_selected_keys();

		if ( empty( $cart_item_key ) || ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid request.', 'zymarg-cart' ) );
		}

		$cart      = WC()->cart;
		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( ! $cart_item ) {
			Zymarg_Cart_Helpers::send_error( __( 'Cart item not found.', 'zymarg-cart' ) );
		}

		// Stock check before updating.
		$product_id   = (int) ( $cart_item['product_id']   ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$price_id     = $variation_id ?: $product_id;
		$stock        = Zymarg_Cart_Helpers::get_stock_info( $price_id, $quantity );

		if ( ! $stock['is_in_stock'] ) {
			Zymarg_Cart_Helpers::send_error( __( 'This item is currently out of stock.', 'zymarg-cart' ) );
		}

		if ( $stock['insufficient'] ) {
			Zymarg_Cart_Helpers::send_error(
				sprintf(
					/* translators: %d: Available stock quantity. */
					__( 'Only %d available. Please reduce the quantity.', 'zymarg-cart' ),
					(int) $stock['qty']
				)
			);
		}

		$cart->set_quantity( $cart_item_key, $quantity, true );

		$product       = $cart_item['data'] instanceof \WC_Product
			? $cart_item['data']
			: wc_get_product( $price_id );
		$unit_price    = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;
		$line_subtotal = round( $unit_price * $quantity, wc_get_price_decimals() );

		// Vendor-level subtotal for selected items.
		$vendor_id       = Zymarg_Cart_Dokan::get_vendor_id_by_product( $product_id );
		$vendor_selected = array_filter(
			$selected_keys,
			static function ( string $k ) use ( $vendor_id ): bool {
				$item = WC()->cart->get_cart_item( $k );
				if ( ! $item ) {
					return false;
				}
				return Zymarg_Cart_Dokan::get_vendor_id_by_product( (int) ( $item['product_id'] ?? 0 ) ) === $vendor_id;
			}
		);

		$vendor_subtotal = Zymarg_Cart_Dokan::get_vendor_subtotal( $vendor_id, array_values( $vendor_selected ) );

		Zymarg_Cart_Helpers::send_success( [
			'cart_item_key'        => $cart_item_key,
			'quantity'             => $quantity,
			'unit_price'           => $unit_price,
			'unit_price_html'      => wc_price( $unit_price ),
			'subtotal'             => $line_subtotal,
			'subtotal_html'        => wc_price( $line_subtotal ),
			'vendor_id'            => $vendor_id,
			'vendor_subtotal'      => $vendor_subtotal,
			'vendor_subtotal_html' => wc_price( $vendor_subtotal ),
			'stock'                => $stock,
			'item_count'           => self::current_item_count(),
			'totals'               => Zymarg_Cart_Dokan::get_totals_for_selected( $selected_keys ),
		] );
	}

	// =========================================================================
	// HANDLER: Change variation
	// =========================================================================

	/**
	 * Replaces a cart item's variation (remove old, add new with updated data).
	 *
	 * POST: cart_item_key, variation_id, attributes (JSON), selected_keys (JSON)
	 * Returns: old_cart_key, new_cart_key, prices, stock, totals.
	 */
	public static function handle_zymarg_change_variation(): void {
		self::verify_nonce_or_die();

		$old_key      = self::get_post_string( 'cart_item_key' );
		$variation_id = self::get_post_int( 'variation_id' );
		$attributes   = self::get_post_attributes();
		$selected_keys = self::get_selected_keys();

		if ( empty( $old_key ) || $variation_id <= 0 || ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid variation request.', 'zymarg-cart' ) );
		}

		$cart      = WC()->cart;
		$cart_item = $cart->get_cart_item( $old_key );

		if ( ! $cart_item ) {
			Zymarg_Cart_Helpers::send_error( __( 'Cart item not found.', 'zymarg-cart' ) );
		}

		$product_id    = (int) ( $cart_item['product_id'] ?? 0 );
		$quantity      = (int) ( $cart_item['quantity']   ?? 1 );
		$extra_data    = $cart_item['cart_item_data'] ?? [];

		// Validate the new variation belongs to the same parent product.
		$variation_obj = wc_get_product( $variation_id );
		if (
			! $variation_obj instanceof \WC_Product_Variation ||
			(int) $variation_obj->get_parent_id() !== $product_id
		) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid variation for this product.', 'zymarg-cart' ) );
		}

		// Stock check.
		$stock = Zymarg_Cart_Helpers::get_stock_info( $variation_id, $quantity );
		if ( ! $stock['is_in_stock'] ) {
			Zymarg_Cart_Helpers::send_error( __( 'Selected variation is out of stock.', 'zymarg-cart' ) );
		}

		// Remove old, add new.
		$cart->remove_cart_item( $old_key );

		$new_key = $cart->add_to_cart(
			$product_id,
			$quantity,
			$variation_id,
			$attributes,
			$extra_data
		);

		if ( false === $new_key ) {
			// Rollback: re-add the original item.
			$cart->add_to_cart(
				$product_id,
				$quantity,
				(int) ( $cart_item['variation_id'] ?? 0 ),
				(array) ( $cart_item['variation']  ?? [] ),
				$extra_data
			);
			Zymarg_Cart_Helpers::send_error( __( 'Could not update variation. Please try again.', 'zymarg-cart' ) );
		}

		// Detect merge: WooCommerce returns the EXISTING cart key when the target
		// variation is already in the cart. In that case new_key !== old_key but
		// new_key is already present as a separate row in the DOM. We must tell
		// the JS to remove the changed row and update the surviving row instead.
		$merged          = false;
		$merged_quantity = $quantity; // fallback

		if ( $new_key !== $old_key ) {
			// Check if new_key was already in the cart BEFORE the old item was
			// removed — i.e. it is a pre-existing row, not a freshly created one.
			// After WC merges, the surviving item holds the combined quantity.
			$surviving_item = $cart->get_cart_item( $new_key );
			if ( $surviving_item && isset( $surviving_item['quantity'] ) ) {
				$merged_quantity = (int) $surviving_item['quantity'];
				// A merge happened when the surviving quantity is greater than
				// the quantity we just added (old row's qty was folded in).
				if ( $merged_quantity > $quantity ) {
					$merged = true;
				}
			}
		}

		// Update selected_keys: remove old_key, keep new_key (already present or
		// newly added). For a merge, old_key disappears from the DOM so remove it.
		$updated_selected = array_values(
			array_unique(
				array_map(
					static fn( string $k ) => $k === $old_key ? $new_key : $k,
					$selected_keys
				)
			)
		);

		$unit_price      = (float) $variation_obj->get_price();
		$merged_subtotal = round( $unit_price * $merged_quantity, wc_get_price_decimals() );
		$line_subtotal   = $merged ? $merged_subtotal : round( $unit_price * $quantity, wc_get_price_decimals() );

		// Variation attribute labels for display.
		$variation_labels = Zymarg_Cart_Helpers::format_variation_labels( $attributes );

		// Vendor subtotal — needed so the vendor subtotal bar updates immediately
		// after a variation change or merge, without requiring a page reload.
		$vendor_id       = Zymarg_Cart_Dokan::get_vendor_id_by_product( $product_id );
		$vendor_selected = array_filter(
			$updated_selected,
			static function ( string $k ) use ( $vendor_id ): bool {
				$item = WC()->cart->get_cart_item( $k );
				if ( ! $item ) {
					return false;
				}
				return Zymarg_Cart_Dokan::get_vendor_id_by_product( (int) ( $item['product_id'] ?? 0 ) ) === $vendor_id;
			}
		);
		$vendor_subtotal = Zymarg_Cart_Dokan::get_vendor_subtotal( $vendor_id, array_values( $vendor_selected ) );

		Zymarg_Cart_Helpers::send_success( [
			'old_cart_key'          => $old_key,
			'new_cart_key'          => $new_key,
			'variation_id'          => $variation_id,
			'variation_labels'      => $variation_labels,
			'unit_price'            => $unit_price,
			'unit_price_html'       => wc_price( $unit_price ),
			'subtotal'              => $line_subtotal,
			'subtotal_html'         => wc_price( $line_subtotal ),
			'sku'                   => $variation_obj->get_sku(),
			'stock'                 => $stock,
			// Variation image — each variation can have its own image in WooCommerce.
			// Return it so the JS can update the row image immediately on switch/merge.
			'variation_image_url'   => ( function() use ( $variation_obj, $product_id ) {
				$img_id = (int) $variation_obj->get_image_id();
				if ( $img_id > 0 ) {
					$src = wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' );
					if ( $src ) {
						return esc_url( $src );
					}
				}
				// Fall back to parent product image.
				$parent = wc_get_product( $product_id );
				if ( $parent ) {
					$parent_img_id = (int) $parent->get_image_id();
					if ( $parent_img_id > 0 ) {
						$src = wp_get_attachment_image_url( $parent_img_id, 'woocommerce_thumbnail' );
						if ( $src ) {
							return esc_url( $src );
						}
					}
				}
				return esc_url( wc_placeholder_img_src( 'woocommerce_thumbnail' ) );
			} )(),
			'updated_selected_keys' => $updated_selected,
			'item_count'            => self::current_item_count(),
			'totals'                => Zymarg_Cart_Dokan::get_totals_for_selected( $updated_selected ),
			// Vendor subtotal bar.
			'vendor_id'             => $vendor_id,
			'vendor_subtotal'       => $vendor_subtotal,
			'vendor_subtotal_html'  => wc_price( $vendor_subtotal ),
			// Merge payload — only meaningful when merged === true.
			'merged'                => $merged,
			'merged_quantity'       => $merged_quantity,
			'merged_subtotal_html'  => wc_price( $merged_subtotal ),
		] );
	}

	// =========================================================================
	// HANDLER: Remove item
	// =========================================================================

	/**
	 * Removes a cart item (triggered by delete button in edit mode).
	 *
	 * POST: cart_item_key, selected_keys (JSON)
	 * Returns: vendor_id, vendor_empty, item_count, totals.
	 */
	public static function handle_zymarg_remove_item(): void {
		self::verify_nonce_or_die();

		$cart_item_key = self::get_post_string( 'cart_item_key' );
		$selected_keys = self::get_selected_keys();

		if ( empty( $cart_item_key ) || ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid request.', 'zymarg-cart' ) );
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! $cart_item ) {
			Zymarg_Cart_Helpers::send_error( __( 'Cart item not found.', 'zymarg-cart' ) );
		}

		$product_id = (int) ( $cart_item['product_id'] ?? 0 );
		$vendor_id  = Zymarg_Cart_Dokan::get_vendor_id_by_product( $product_id );

		WC()->cart->remove_cart_item( $cart_item_key );
		WC()->cart->calculate_totals();

		// Update selected_keys to exclude the removed item.
		$updated_selected = array_values(
			array_filter( $selected_keys, static fn( $k ) => $k !== $cart_item_key )
		);

		Zymarg_Cart_Helpers::send_success( [
			'cart_item_key' => $cart_item_key,
			'vendor_id'     => $vendor_id,
			'vendor_empty'  => ! self::vendor_has_remaining_items( $vendor_id ),
			'item_count'    => self::current_item_count(),
			'cart_empty'    => self::current_item_count() === 0,
			'updated_selected_keys' => $updated_selected,
			'totals'        => Zymarg_Cart_Dokan::get_totals_for_selected( $updated_selected ),
		] );
	}

	// =========================================================================
	// HANDLER: Apply coupon
	// =========================================================================

	/**
	 * Applies a coupon code to the WC cart.
	 * Handles both per-product WC coupons and Dokan vendor coupons.
	 *
	 * POST: coupon_code, product_id (optional), vendor_id (optional), selected_keys (JSON)
	 * Returns: coupon_code, discount, discount_html, totals.
	 */
	public static function handle_zymarg_apply_coupon(): void {
		self::verify_nonce_or_die();

		$coupon_code   = self::get_post_string( 'coupon_code' );
		$product_id    = self::get_post_int( 'product_id' );
		$vendor_id     = self::get_post_int( 'vendor_id' );
		$selected_keys = self::get_selected_keys();

		if ( empty( $coupon_code ) ) {
			Zymarg_Cart_Helpers::send_error( __( 'Please enter a coupon code.', 'zymarg-cart' ) );
		}

		$result = Zymarg_Cart_Dokan::apply_coupon( $coupon_code, $product_id, $vendor_id );

		if ( ! $result['success'] ) {
			Zymarg_Cart_Helpers::send_error( $result['message'] );
		}

		Zymarg_Cart_Helpers::send_success(
			[
				'coupon_code'   => $result['coupon_code'],
				'discount'      => $result['discount'],
				'discount_html' => $result['discount_html'],
				'coupons'       => Zymarg_Cart_Dokan::get_applied_coupons(),
				'totals'        => Zymarg_Cart_Dokan::get_totals_for_selected( $selected_keys ),
			],
			$result['message']
		);
	}

	// =========================================================================
	// HANDLER: Remove coupon
	// =========================================================================

	/**
	 * Removes an applied coupon from the cart.
	 *
	 * POST: coupon_code, selected_keys (JSON)
	 * Returns: coupons (updated list), totals.
	 */
	public static function handle_zymarg_remove_coupon(): void {
		self::verify_nonce_or_die();

		$coupon_code   = self::get_post_string( 'coupon_code' );
		$selected_keys = self::get_selected_keys();

		$result = Zymarg_Cart_Dokan::remove_coupon( $coupon_code );

		if ( ! $result['success'] ) {
			Zymarg_Cart_Helpers::send_error( $result['message'] );
		}

		Zymarg_Cart_Helpers::send_success(
			[
				'coupons' => Zymarg_Cart_Dokan::get_applied_coupons(),
				'totals'  => Zymarg_Cart_Dokan::get_totals_for_selected( $selected_keys ),
			],
			$result['message']
		);
	}

	// =========================================================================
	// HANDLER: Save for later
	// =========================================================================

	/**
	 * Moves a cart item to the Save-for-Later list.
	 * Guest → WC session. Logged-in → user meta.
	 *
	 * POST: cart_item_key, selected_keys (JSON)
	 * Returns: item_key, vendor_id, vendor_empty, item_count, saved_count, totals.
	 */
	public static function handle_zymarg_save_for_later(): void {
		self::verify_nonce_or_die();

		$cart_item_key = self::get_post_string( 'cart_item_key' );
		$selected_keys = self::get_selected_keys();

		if ( empty( $cart_item_key ) || ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid request.', 'zymarg-cart' ) );
		}

		$cart      = WC()->cart;
		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( ! $cart_item ) {
			Zymarg_Cart_Helpers::send_error( __( 'Cart item not found.', 'zymarg-cart' ) );
		}

		$product_id   = (int) ( $cart_item['product_id']   ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$quantity     = (int) ( $cart_item['quantity']     ?? 1 );
		$variation    = (array) ( $cart_item['variation']  ?? [] );
		$vendor_id    = Zymarg_Cart_Dokan::get_vendor_id_by_product( $product_id );

		// Get unit price for saved item record.
		$price_id    = $variation_id ?: $product_id;
		$product_obj = $cart_item['data'] instanceof \WC_Product
			? $cart_item['data']
			: wc_get_product( $price_id );
		$saved_price = $product_obj instanceof \WC_Product ? (float) $product_obj->get_price() : 0.0;

		// Save to appropriate storage.
		$user_id  = get_current_user_id();
		$item_key = false;

		if ( $user_id > 0 ) {
			$item_key = Zymarg_Cart_Usermeta::save_item(
				$user_id,
				$product_id,
				$variation_id,
				$quantity,
				$variation,
				$cart_item_key,
				$saved_price
			);
		} else {
			$item_key = Zymarg_Cart_Session::save_item(
				$product_id,
				$variation_id,
				$quantity,
				$variation,
				$cart_item_key,
				$saved_price
			);
		}

		if ( false === $item_key ) {
			Zymarg_Cart_Helpers::send_error(
				__( 'Could not save item. You may have reached the maximum saved items limit.', 'zymarg-cart' )
			);
		}

		// Remove from active cart.
		$cart->remove_cart_item( $cart_item_key );
		$cart->calculate_totals();

		$updated_selected = array_values(
			array_filter( $selected_keys, static fn( $k ) => $k !== $cart_item_key )
		);

		// Render the saved item row HTML so JS can inject it immediately into the
		// saved section without a page reload. We replicate the same variables
		// the cart-body-saved-item-row.php template expects.
		$settings         = [];  // No Elementor settings in AJAX context; template uses defaults.
		$saved_item_key   = $item_key;
		$saved_item       = [
			'product_id'    => $product_id,
			'variation_id'  => $variation_id,
			'quantity'      => $quantity,
			'variation'     => $variation,
			'saved_price'   => $saved_price,
			'current_price' => $saved_price,
			'price_changed' => false,
		];
		$show_move_btn    = true;
		$show_remove_btn  = true;
		$show_price_changed = true;

		ob_start();
		include ZYMARG_CART_PATH . 'templates/cart-body-saved-item-row.php';
		$saved_item_html = ob_get_clean();

		Zymarg_Cart_Helpers::send_success(
			[
				'item_key'              => $item_key,
				'cart_item_key'         => $cart_item_key,
				'vendor_id'             => $vendor_id,
				'vendor_empty'          => ! self::vendor_has_remaining_items( $vendor_id ),
				'item_count'            => self::current_item_count(),
				'saved_count'           => self::current_saved_count(),
				'cart_empty'            => self::current_item_count() === 0,
				'updated_selected_keys' => $updated_selected,
				'totals'                => Zymarg_Cart_Dokan::get_totals_for_selected( $updated_selected ),
				// Rendered HTML for the new saved item row — injected by JS directly.
				'saved_item_html'       => $saved_item_html,
			],
			__( 'Item saved for later.', 'zymarg-cart' )
		);
	}

	// =========================================================================
	// HANDLER: Move to cart
	// =========================================================================

	/**
	 * Moves an item from the Save-for-Later list back into the WC cart.
	 *
	 * POST: item_key, selected_keys (JSON)
	 * Returns: new_cart_key, item_count, saved_count, vendor_id, totals.
	 */
	public static function handle_zymarg_move_to_cart(): void {
		self::verify_nonce_or_die();

		$item_key      = self::get_post_string( 'item_key' );
		$selected_keys = self::get_selected_keys();

		if ( empty( $item_key ) ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid item key.', 'zymarg-cart' ) );
		}

		$user_id = get_current_user_id();

		// Get the saved item data before moving (so we can return vendor info).
		$saved_item = $user_id > 0
			? Zymarg_Cart_Usermeta::get_item( $user_id, $item_key )
			: Zymarg_Cart_Session::get_item( $item_key );

		if ( $saved_item === null ) {
			Zymarg_Cart_Helpers::send_error( __( 'Saved item not found.', 'zymarg-cart' ) );
		}

		// Move to cart.
		$moved = $user_id > 0
			? Zymarg_Cart_Usermeta::move_to_cart( $user_id, $item_key )
			: Zymarg_Cart_Session::move_to_cart( $item_key );

		if ( ! $moved ) {
			Zymarg_Cart_Helpers::send_error(
				__( 'Could not add item to cart. It may be out of stock.', 'zymarg-cart' )
			);
		}

		$product_id = (int) ( $saved_item['product_id'] ?? 0 );
		$vendor_id  = Zymarg_Cart_Dokan::get_vendor_id_by_product( $product_id );

		// Find the new WC cart key for the moved item.
		$new_cart_key = self::find_cart_key_for_product(
			$product_id,
			(int) ( $saved_item['variation_id'] ?? 0 ),
			(array) ( $saved_item['variation']  ?? [] )
		);

		Zymarg_Cart_Helpers::send_success(
			[
				'item_key'     => $item_key,
				'new_cart_key' => $new_cart_key,
				'vendor_id'    => $vendor_id,
				'vendor_info'  => Zymarg_Cart_Dokan::get_vendor_info( $vendor_id ),
				'item_count'   => self::current_item_count(),
				'saved_count'  => self::current_saved_count(),
				'totals'       => Zymarg_Cart_Dokan::get_totals_for_selected( $selected_keys ),
			],
			__( 'Item moved to cart.', 'zymarg-cart' )
		);
	}

	// =========================================================================
	// HANDLER: Remove saved item
	// =========================================================================

	/**
	 * Permanently removes an item from the Save-for-Later list.
	 *
	 * POST: item_key
	 * Returns: item_key, saved_count.
	 */
	public static function handle_zymarg_remove_saved(): void {
		self::verify_nonce_or_die();

		$item_key = self::get_post_string( 'item_key' );

		if ( empty( $item_key ) ) {
			Zymarg_Cart_Helpers::send_error( __( 'Invalid item key.', 'zymarg-cart' ) );
		}

		$user_id = get_current_user_id();
		$removed = $user_id > 0
			? Zymarg_Cart_Usermeta::remove_item( $user_id, $item_key )
			: Zymarg_Cart_Session::remove_item( $item_key );

		if ( ! $removed ) {
			Zymarg_Cart_Helpers::send_error( __( 'Saved item not found.', 'zymarg-cart' ) );
		}

		Zymarg_Cart_Helpers::send_success(
			[
				'item_key'    => $item_key,
				'saved_count' => self::current_saved_count(),
			],
			__( 'Item removed.', 'zymarg-cart' )
		);
	}

	// =========================================================================
	// HANDLER: Get totals
	// =========================================================================

	/**
	 * Recalculates cart totals for the current selection.
	 * Called in real time on every checkbox state change.
	 *
	 * POST: selected_keys (JSON)
	 * Returns: totals breakdown.
	 */
	public static function handle_zymarg_get_totals(): void {
		self::verify_nonce_or_die();

		$selected_keys = self::get_selected_keys();

		Zymarg_Cart_Helpers::send_success( [
			'selected_count' => count( $selected_keys ),
			'totals'         => Zymarg_Cart_Dokan::get_totals_for_selected( $selected_keys ),
		] );
	}

	// =========================================================================
	// HANDLER: Partial checkout
	// =========================================================================

	/**
	 * Initiates the Solution 1 partial checkout:
	 * backs up the full cart, removes unselected items, returns checkout URL.
	 *
	 * POST: selected_keys (JSON)
	 * Returns: checkout_url.
	 */
	public static function handle_zymarg_partial_checkout(): void {
		self::verify_nonce_or_die();

		$selected_keys = self::get_selected_keys();

		if ( empty( $selected_keys ) ) {
			Zymarg_Cart_Helpers::send_error(
				__( 'Please select at least one item to checkout.', 'zymarg-cart' )
			);
		}

		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::send_error( __( 'Cart unavailable. Please refresh.', 'zymarg-cart' ) );
		}

		// Validate all selected keys exist in the current cart.
		$cart      = WC()->cart;
		$cart_keys = array_keys( $cart->get_cart() );

		foreach ( $selected_keys as $key ) {
			if ( ! in_array( $key, $cart_keys, true ) ) {
				Zymarg_Cart_Helpers::send_error(
					__( 'One or more selected items are no longer in your cart. Please refresh.', 'zymarg-cart' )
				);
			}
		}

		// Check all selected items are purchasable AND have enough stock for
		// the requested quantity. v1.1.3: pre-1.1.3 only called is_in_stock(),
		// which returns true as long as ANY units are available — so a cart
		// line with qty 5 against stock of 3 would pass this check and
		// silently fail (or be silently capped) at WC checkout.
		foreach ( $selected_keys as $key ) {
			$item       = $cart->get_cart_item( $key );
			$qty        = (int) ( $item['quantity'] ?? 0 );
			$price_id   = (int) ( $item['variation_id'] ?? 0 ) ?: (int) ( $item['product_id'] ?? 0 );
			$product    = $item['data'] instanceof \WC_Product ? $item['data'] : wc_get_product( $price_id );

			if ( ! $product instanceof \WC_Product || ! $product->is_in_stock() ) {
				Zymarg_Cart_Helpers::send_error(
					sprintf(
						/* translators: %s: Product name. */
						__( '"%s" is out of stock and cannot be checked out.', 'zymarg-cart' ),
						$product instanceof \WC_Product ? wp_strip_all_tags( $product->get_name() ) : __( 'A product', 'zymarg-cart' )
					)
				);
			}

			// has_enough_stock() respects backorder settings and only blocks
			// when the cart quantity exceeds available stock for products that
			// don't allow backorders.
			if ( $qty > 0 && ! $product->has_enough_stock( $qty ) ) {
				$available = $product->managing_stock() ? (int) $product->get_stock_quantity() : 0;
				Zymarg_Cart_Helpers::send_error(
					sprintf(
						/* translators: 1: Product name, 2: Available quantity. */
						__( 'Only %2$d of "%1$s" available — please reduce the quantity before checking out.', 'zymarg-cart' ),
						wp_strip_all_tags( $product->get_name() ),
						$available
					)
				);
			}
		}

		// Set checkout-initiated lock so maybe_restore_cart skips this request.
		// v1.1.3: TTL reduced from 30 s to 5 s. The lock only needs to cover
		// the window between this AJAX response and the JS-driven redirect to
		// the checkout page. Longer windows blocked legitimate cart restores
		// in other browser tabs (Issue #9 in the v1.1.3 audit).
		set_transient( self::checkout_lock_key(), true, 5 );

		// Backup full cart and remove unselected items.
		$backed_up = Zymarg_Cart_Partial::backup_cart( $selected_keys );
		if ( ! $backed_up ) {
			delete_transient( self::checkout_lock_key() );
			Zymarg_Cart_Helpers::send_error(
				__( 'Could not initiate checkout. Please refresh and try again.', 'zymarg-cart' )
			);
		}

		Zymarg_Cart_Partial::remove_unselected( $selected_keys );

		Zymarg_Cart_Helpers::log(
			'Ajax::partial_checkout — initiated.',
			[ 'selected_count' => count( $selected_keys ) ]
		);

		Zymarg_Cart_Helpers::send_success(
			[
				'checkout_url'   => wc_get_checkout_url(),
				'selected_count' => count( $selected_keys ),
			],
			__( 'Redirecting to checkout…', 'zymarg-cart' )
		);
	}

	// =========================================================================
	// HANDLER: Restore cart
	// =========================================================================

	/**
	 * Explicitly restores the cart backup via AJAX.
	 * Called by the JS layer when the restore-sentinel div is detected on
	 * the cart page (see Zymarg_Cart_Partial::show_restore_spinner()).
	 *
	 * POST: (none beyond nonce)
	 * Returns: restored (bool), item_count.
	 */
	public static function handle_zymarg_restore_cart(): void {
		self::verify_nonce_or_die();

		$restored   = Zymarg_Cart_Partial::restore_cart();
		$item_count = self::current_item_count();

		Zymarg_Cart_Helpers::send_success( [
			'restored'   => $restored,
			'item_count' => $item_count,
			'cart_empty' => $item_count === 0,
		] );
	}

	// =========================================================================
	// PRIVATE — CART UTILITIES
	// =========================================================================

	/**
	 * Finds the WC cart item key for a product/variation combination after it
	 * has been added to the cart (e.g. after move_to_cart).
	 *
	 * @param int                  $product_id
	 * @param int                  $variation_id
	 * @param array<string,string> $variation
	 *
	 * @return string Cart item key, or empty string if not found.
	 */
	private static function find_cart_key_for_product(
		int $product_id,
		int $variation_id = 0,
		array $variation  = []
	): string {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return '';
		}

		$expected_key = Zymarg_Cart_Helpers::generate_item_key(
			$product_id,
			$variation_id,
			$variation
		);

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$item_key = Zymarg_Cart_Helpers::generate_item_key(
				(int) ( $item['product_id']   ?? 0 ),
				(int) ( $item['variation_id'] ?? 0 ),
				(array) ( $item['variation']  ?? [] )
			);
			if ( $item_key === $expected_key ) {
				return $key;
			}
		}

		return '';
	}
}
