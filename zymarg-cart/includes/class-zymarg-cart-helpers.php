<?php
/**
 * Shared utility helpers for ZYMARG Cart.
 *
 * This class provides static utility methods used across every other class in
 * the plugin. No WordPress hooks are registered here — this is pure utility.
 *
 * Responsibilities:
 * - Item key generation (deterministic hash from product + variation data).
 * - Input sanitization.
 * - Nonce verification.
 * - Standardised JSON response helpers (success + error).
 * - Price formatting.
 * - Product display data retrieval.
 * - WC session / cart availability guards.
 * - Max saved-items limit accessor.
 * - Debug logger (WooCommerce logger wrapper).
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Helpers {

	// -------------------------------------------------------------------------
	// Constants.
	// -------------------------------------------------------------------------

	/** WC session key for the Save-for-Later list (guest users). */
	public const SESSION_KEY_SAVED = '_zymarg_saved_items';

	/** User-meta key for the Save-for-Later list (logged-in users). */
	public const USERMETA_KEY_SAVED = '_zymarg_saved_items';

	/** WC session key for the partial-checkout cart backup (guest users). */
	public const SESSION_KEY_BACKUP = '_zymarg_cart_backup';

	/** User-meta key for the partial-checkout cart backup (logged-in users). */
	public const USERMETA_KEY_BACKUP = '_zymarg_cart_backup';

	/** WC session key for the post-order pending-restore queue (guest users) (v1.1.4). */
	public const SESSION_KEY_PENDING = '_zymarg_pending_restore';

	/** Default maximum number of items a customer can save for later. */
	public const DEFAULT_MAX_SAVED = 50;

	/** Nonce action used for all AJAX calls. */
	public const NONCE_ACTION = 'zymarg_cart_nonce';

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Item key generation.
	// -------------------------------------------------------------------------

	/**
	 * Generates a deterministic, collision-resistant item key from the
	 * combination of product ID, variation ID, and variation attributes.
	 *
	 * Two calls with identical arguments always produce the same key, so the
	 * same physical product+variation will not be saved twice.
	 *
	 * Hash format (since v1.1.0):
	 *   md5( "{product_id}|{variation_id}|{json_encoded_sorted_variation}" )
	 *
	 * The pre-1.1.0 hash used PHP serialize() instead of wp_json_encode().
	 * Existing saved-items written with the old format are re-keyed lazily
	 * by Zymarg_Cart_Session::get_saved_items() and
	 * Zymarg_Cart_Usermeta::get_saved_items() the first time the data is
	 * accessed after upgrade — see those methods for the migration logic.
	 *
	 * @param int                  $product_id   WooCommerce product ID.
	 * @param int                  $variation_id WooCommerce variation ID (0 for simple products).
	 * @param array<string, mixed> $variation    Variation attributes, e.g. ['attribute_pa_size' => 'L'].
	 *
	 * @return string 32-character lowercase hex MD5 hash.
	 */
	public static function generate_item_key(
		int $product_id,
		int $variation_id = 0,
		array $variation = []
	): string {
		ksort( $variation );

		// Use wp_json_encode() instead of serialize() so the hash is stable
		// across PHP versions and free of PHP's internal serialization quirks.
		$variation_part = ! empty( $variation )
			? (string) wp_json_encode( $variation )
			: '';

		return md5(
			$product_id . '|' .
			$variation_id . '|' .
			$variation_part
		);
	}

	// -------------------------------------------------------------------------
	// Sanitization.
	// -------------------------------------------------------------------------

	/**
	 * Strips anything that is not a valid MD5 hex character from an item key.
	 * Prevents directory-traversal or injection attacks when keys are used as
	 * array indices or passed through AJAX.
	 *
	 * @param string $key Raw key from user input or URL.
	 * @return string Sanitized key (empty string if completely invalid).
	 */
	public static function sanitize_item_key( string $key ): string {
		return (string) preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );
	}

	/**
	 * Sanitizes a product or variation ID received from an AJAX request.
	 *
	 * @param mixed $id Raw value from $_POST / $_GET.
	 * @return int Positive integer or 0 if invalid.
	 */
	public static function sanitize_product_id( mixed $id ): int {
		$int = (int) $id;
		return $int > 0 ? $int : 0;
	}

	/**
	 * Sanitizes a quantity value.
	 * Returns 1 as fallback to avoid zero or negative quantities.
	 *
	 * @param mixed $qty Raw value.
	 * @return int Quantity ≥ 1.
	 */
	public static function sanitize_quantity( mixed $qty ): int {
		$int = (int) $qty;
		return $int > 0 ? $int : 1;
	}

	// -------------------------------------------------------------------------
	// Nonce verification.
	// -------------------------------------------------------------------------

	/**
	 * Verifies the ZYMARG cart AJAX nonce.
	 * Returns false (does not die) so callers can send a proper JSON error.
	 *
	 * @param string $nonce  The nonce string to verify.
	 * @param string $action Nonce action. Defaults to the plugin nonce action.
	 *
	 * @return bool True if valid, false if invalid or expired.
	 */
	public static function verify_nonce(
		string $nonce,
		string $action = self::NONCE_ACTION
	): bool {
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	// -------------------------------------------------------------------------
	// Standardised AJAX responses.
	// -------------------------------------------------------------------------

	/**
	 * Sends a JSON success response and terminates execution.
	 *
	 * A fresh nonce is included in every response so the JS layer can update
	 * its stored nonce and prevent 403s on long sessions.
	 *
	 * @param mixed  $data    Optional payload to include under the 'data' key.
	 * @param string $message Optional human-readable message.
	 *
	 * @return never
	 */
	public static function send_success( mixed $data = null, string $message = '' ): never {
		$response = [
			'success' => true,
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		];

		if ( $data !== null ) {
			$response['data'] = $data;
		}

		if ( $message !== '' ) {
			$response['message'] = $message;
		}

		wp_send_json( $response, 200 );
	}

	/**
	 * Sends a JSON error response and terminates execution.
	 *
	 * @param string $message Human-readable error description.
	 * @param int    $code    HTTP status code (default 400).
	 * @param mixed  $data    Optional debug payload (only included when WP_DEBUG is on).
	 *
	 * @return never
	 */
	public static function send_error(
		string $message,
		int $code = 400,
		mixed $data = null
	): never {
		$response = [
			'success' => false,
			'message' => $message,
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		];

		if ( $data !== null && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$response['debug'] = $data;
		}

		wp_send_json( $response, $code );
	}

	// -------------------------------------------------------------------------
	// Price formatting.
	// -------------------------------------------------------------------------

	/**
	 * Formats a numeric price using WooCommerce's configured currency symbol,
	 * decimal separator, and thousand separator.
	 *
	 * @param float $price The price to format.
	 * @return string HTML-formatted price string (e.g. "RM&nbsp;89.00").
	 */
	public static function format_price( float $price ): string {
		return wc_price( $price );
	}

	/**
	 * Returns a raw formatted price string without HTML — useful for
	 * JSON responses consumed by JavaScript.
	 *
	 * @param float $price The price to format.
	 * @return string Plain-text price string (e.g. "89.00").
	 */
	public static function format_price_raw( float $price ): string {
		return number_format(
			$price,
			wc_get_price_decimals(),
			wc_get_price_decimal_separator(),
			wc_get_price_thousand_separator()
		);
	}

	// -------------------------------------------------------------------------
	// Product display data.
	// -------------------------------------------------------------------------

	/**
	 * Retrieves all data needed to render a saved-item card in the frontend.
	 *
	 * For variable products the variation-level product is used for price and
	 * image; the parent product provides the title and permalink.
	 *
	 * @param int $product_id   Parent product ID.
	 * @param int $variation_id Variation ID (0 for simple / non-variable products).
	 *
	 * @return array<string, mixed> Associative array of display data,
	 *                              or an empty array if the product does not exist.
	 */
	public static function get_product_display_data(
		int $product_id,
		int $variation_id = 0
	): array {
		$parent  = wc_get_product( $product_id );
		$product = $variation_id > 0 ? wc_get_product( $variation_id ) : $parent;

		if ( ! $product instanceof \WC_Product || ! $parent instanceof \WC_Product ) {
			return [];
		}

		// Prefer the variation image; fall back to the parent image.
		$image_id  = $product->get_image_id() ?: $parent->get_image_id();
		$image_url = $image_id
			? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
			: (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );

		$stock_qty = $product->get_stock_quantity();

		return [
			'product_id'    => $product_id,
			'variation_id'  => $variation_id,
			'title'         => wp_strip_all_tags( $parent->get_name() ),
			'permalink'     => (string) $parent->get_permalink(),
			'image_url'     => $image_url,
			'sku'           => (string) $product->get_sku(),
			'price'         => (float) $product->get_price(),
			'price_html'    => $product->get_price_html(),
			'regular_price' => (float) $product->get_regular_price(),
			'sale_price'    => (float) $product->get_sale_price(),
			'on_sale'       => $product->is_on_sale(),
			'stock_status'  => $product->get_stock_status(),    // 'instock'|'outofstock'|'onbackorder'
			'is_in_stock'   => $product->is_in_stock(),
			'manage_stock'  => $product->managing_stock(),
			'stock_qty'     => is_int( $stock_qty ) ? $stock_qty : null,
			'is_purchasable' => $product->is_purchasable(),
			'type'          => $parent->get_type(),
		];
	}

	/**
	 * Returns variation attribute labels suitable for display beneath the
	 * product title (e.g. "Color: Red, Size: L").
	 *
	 * @param array<string, string> $variation Raw variation data from cart item.
	 * @return string Comma-separated attribute label pairs, HTML-escaped.
	 */
	public static function format_variation_labels( array $variation ): string {
		$labels = [];

		foreach ( $variation as $attr => $value ) {
			if ( '' === $value ) {
				continue;
			}

			// Convert 'attribute_pa_color' → 'Color'.
			$taxonomy = str_replace( 'attribute_', '', $attr );
			$label    = wc_attribute_label( $taxonomy );

			// For term-based attributes, get the term name.
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
				$value = $term instanceof \WP_Term ? $term->name : $value;
			}

			$labels[] = esc_html( $label ) . ': ' . esc_html( $value );
		}

		return implode( ', ', $labels );
	}

	// -------------------------------------------------------------------------
	// WooCommerce availability guards.
	// -------------------------------------------------------------------------

	/**
	 * Returns the WooCommerce session object if it is properly initialised,
	 * or null otherwise.
	 *
	 * Use this guard instead of accessing WC()->session directly so all
	 * callers handle the null case without repeated null checks.
	 *
	 * @return \WC_Session|\WC_Session_Handler|null
	 */
	public static function get_wc_session(): ?\WC_Session {
		$wc = WC();
		return ( $wc && $wc->session instanceof \WC_Session )
			? $wc->session
			: null;
	}

	/**
	 * Returns true if the WooCommerce cart object is initialised and usable.
	 *
	 * @return bool
	 */
	public static function is_cart_available(): bool {
		$wc = WC();
		return $wc && $wc->cart instanceof \WC_Cart;
	}

	// -------------------------------------------------------------------------
	// Configuration accessors.
	// -------------------------------------------------------------------------

	/**
	 * Returns the maximum number of items a customer is allowed to save for
	 * later. Filterable so site owners can override via theme or child plugin.
	 *
	 * @return int
	 */
	public static function get_max_saved_items(): int {
		return (int) apply_filters( 'zymarg_cart_max_saved_items', self::DEFAULT_MAX_SAVED );
	}

	/**
	 * Returns the partial-checkout backup expiry in seconds.
	 * Defaults to 7200 (2 hours); filterable.
	 *
	 * @return int
	 */
	public static function get_backup_expiry(): int {
		return (int) apply_filters( 'zymarg_cart_session_expiry', 7200 );
	}

	// -------------------------------------------------------------------------
	// Debug logger.
	// -------------------------------------------------------------------------

	/**
	 * Logs a message to the WooCommerce logger under the 'zymarg-cart' source.
	 * Only writes when WP_DEBUG is enabled to avoid log bloat on production.
	 *
	 * @param string $message Human-readable log entry.
	 * @param mixed  $context Optional structured context (serialised as JSON).
	 * @param string $level   Log level: 'debug'|'info'|'warning'|'error'.
	 */
	public static function log(
		string $message,
		mixed $context = null,
		string $level = 'debug'
	): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$logger  = wc_get_logger();
		$wc_ctx  = [ 'source' => 'zymarg-cart' ];
		$entry   = $context !== null
			? $message . ' | ' . wp_json_encode( $context )
			: $message;

		match ( $level ) {
			'error'   => $logger->error( $entry, $wc_ctx ),
			'warning' => $logger->warning( $entry, $wc_ctx ),
			'info'    => $logger->info( $entry, $wc_ctx ),
			default   => $logger->debug( $entry, $wc_ctx ),
		};
	}

	// -------------------------------------------------------------------------
	// Cart item data helpers.
	// -------------------------------------------------------------------------

	/**
	 * Extracts and normalises variation attributes from a WooCommerce cart
	 * item array. Ensures the array is always keyed by attribute slug.
	 *
	 * @param array<string, mixed> $cart_item Raw cart item from WC()->cart->get_cart().
	 * @return array<string, string> Normalised variation array.
	 */
	public static function normalize_variation( array $cart_item ): array {
		$raw = $cart_item['variation'] ?? [];
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Builds a canonical saved-item array from raw cart item data.
	 * This is the single source of truth for the item data structure used by
	 * both the session class and the user-meta class.
	 *
	 * @param array<string, mixed> $cart_item   WooCommerce cart item array.
	 * @param string               $cart_item_key WooCommerce cart item key.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_saved_item_from_cart( array $cart_item, string $cart_item_key ): array {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$quantity     = (int) ( $cart_item['quantity'] ?? 1 );
		$variation    = self::normalize_variation( $cart_item );

		$price_id = $variation_id ?: $product_id;
		$product  = wc_get_product( $price_id );
		$price    = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;

		return [
			'item_key'         => self::generate_item_key( $product_id, $variation_id, $variation ),
			'cart_item_key'    => $cart_item_key,
			'product_id'       => $product_id,
			'variation_id'     => $variation_id,
			'quantity'         => $quantity,
			'variation'        => $variation,
			'saved_at'         => time(),
			'saved_price'      => $price,
			'current_price'    => $price,
			'price_changed'    => false,
		];
	}

	// -------------------------------------------------------------------------
	// Stock status helpers.
	// -------------------------------------------------------------------------

	/**
	 * Returns a normalised stock status descriptor for a product/variation.
	 * Used by both the usermeta class and the AJAX totals handler.
	 *
	 * @param int $product_id   Product (or variation) ID.
	 * @param int $quantity     Desired quantity (to detect insufficient stock).
	 *
	 * @return array{status: string, is_in_stock: bool, qty: int|null, low_stock: bool}
	 */
	public static function get_stock_info( int $product_id, int $quantity = 1 ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return [
				'status'     => 'unavailable',
				'is_in_stock' => false,
				'qty'         => null,
				'low_stock'   => false,
			];
		}

		$stock_qty  = $product->get_stock_quantity();
		$low_threshold = absint(
			get_option( 'woocommerce_notify_low_stock_amount', 2 )
		);

		return [
			'status'       => $product->get_stock_status(),
			'is_in_stock'  => $product->is_in_stock(),
			'qty'          => is_int( $stock_qty ) ? $stock_qty : null,
			'low_stock'    => (
				$product->managing_stock() &&
				is_int( $stock_qty ) &&
				$stock_qty > 0 &&
				$stock_qty <= $low_threshold
			),
			'insufficient' => (
				$product->managing_stock() &&
				is_int( $stock_qty ) &&
				$stock_qty < $quantity
			),
		];
	}

	// =========================================================================
	// ICON LIBRARY (v1.3.0)
	// =========================================================================
	//
	// The plugin previously rendered icons via the Tabler Icons web-font
	// (`<i class="ti ti-name">`). On any environment that did not have the
	// Tabler font enqueued (notably the user's Pantheon dev environment and
	// many Astra / WooCommerce installs), every icon silently failed to
	// render. v1.3.0 inlines all icons as SVG instead, removing the external
	// dependency entirely.
	//
	// SVG paths are based on Tabler Icons (https://tabler-icons.io/) — MIT
	// licensed — with the `currentColor` stroke so they inherit the parent's
	// text colour, and `width: 1em; height: 1em` sizing on the wrapper span
	// so font-size scales them just like a font glyph.

	/**
	 * Returns inline-SVG icon markup for the given icon name.
	 *
	 * @param string $name           Icon identifier (e.g. 'minus', 'plus', 'shopping-cart').
	 * @param string $extra_classes  Additional CSS classes to add to the wrapper span
	 *                               (e.g. 'zymarg-cart-icon' so existing widget
	 *                               selectors targeting that class continue to work).
	 *
	 * @return string HTML markup. Empty string if the icon name is unknown.
	 */
	public static function icon( string $name, string $extra_classes = '' ): string {
		$library = self::get_icon_library();
		if ( ! isset( $library[ $name ] ) ) {
			return '';
		}

		$classes = trim( 'zymarg-icon zymarg-icon-' . sanitize_html_class( $name ) . ' ' . $extra_classes );

		return sprintf(
			'<span class="%s" aria-hidden="true">%s</span>',
			esc_attr( $classes ),
			$library[ $name ] // Path strings are static, hard-coded constants — safe to echo.
		);
	}

	/**
	 * Returns the icon SVG library, keyed by icon name.
	 * Cached in a static variable on first call.
	 *
	 * @return array<string,string>
	 */
	private static function get_icon_library(): array {
		static $library = null;
		if ( $library !== null ) {
			return $library;
		}

		$attrs = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"';

		$library = [
			// Quantity stepper.
			'minus' => '<svg ' . $attrs . '><path d="M5 12h14"/></svg>',
			'plus'  => '<svg ' . $attrs . '><path d="M12 5v14M5 12h14"/></svg>',

			// Save for Later.
			'bookmark' => '<svg ' . $attrs . '><path d="M9 4h6a2 2 0 0 1 2 2v14l-5-3-5 3V6a2 2 0 0 1 2-2"/></svg>',

			// Coupon.
			'tag'        => '<svg ' . $attrs . '><path d="M7.86 6h-2.84a2.02 2.02 0 0 0-2.02 2.02v2.84c0 .54.21 1.05.59 1.43l6.12 6.12a2.02 2.02 0 0 0 2.86 0l2.84-2.84a2.02 2.02 0 0 0 0-2.86l-6.12-6.12A2.02 2.02 0 0 0 7.86 6Z"/><path d="M6 9h.01"/></svg>',
			'discount-2' => '<svg ' . $attrs . '><path d="M9 15l6-6"/><circle cx="9.5" cy="9.5" r="1" fill="currentColor" stroke="none"/><circle cx="14.5" cy="14.5" r="1" fill="currentColor" stroke="none"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>',

			// Status indicators.
			'alert-triangle' => '<svg ' . $attrs . '><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M5 19h14a2 2 0 0 0 1.84-2.75l-7.1-12.25a2 2 0 0 0-3.5 0l-7.1 12.25A2 2 0 0 0 5 19Z"/></svg>',
			'trending-up'    => '<svg ' . $attrs . '><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>',

			// Generic actions.
			'x'     => '<svg ' . $attrs . '><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
			'check' => '<svg ' . $attrs . '><path d="M5 12l5 5L20 7"/></svg>',
			'edit'  => '<svg ' . $attrs . '><path d="M7 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-1"/><path d="M20.39 6.59a2.1 2.1 0 0 0-2.97-2.97L9 12.04V15h2.96l8.43-8.41Z"/><path d="m16 5 3 3"/></svg>',
			'trash' => '<svg ' . $attrs . '><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>',

			// Cart / shopping.
			'shopping-cart'      => '<svg ' . $attrs . '><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/><path d="M17 17H6V3H4"/><path d="m6 5 14 1-1 7H6"/></svg>',
			'shopping-cart-plus' => '<svg ' . $attrs . '><circle cx="6" cy="19" r="2"/><path d="M12.5 17H6V3H4"/><path d="m6 5 14 1-1 7h-7"/><path d="M16 19h6"/><path d="M19 16v6"/></svg>',

			// Navigation arrows.
			'arrow-left'    => '<svg ' . $attrs . '><path d="M5 12h14"/><path d="m11 18-6-6"/><path d="m11 6-6 6"/></svg>',
			'arrow-right'   => '<svg ' . $attrs . '><path d="M5 12h14"/><path d="m13 18 6-6"/><path d="m13 6 6 6"/></svg>',
			'chevron-right' => '<svg ' . $attrs . '><path d="m9 6 6 6-6 6"/></svg>',
			'chevron-up'    => '<svg ' . $attrs . '><path d="m6 15 6-6 6 6"/></svg>',
			'chevron-down'  => '<svg ' . $attrs . '><path d="m6 9 6 6 6-6"/></svg>',

			// Lock.
			'lock' => '<svg ' . $attrs . '><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',

			// Vendor identity icons (v1.3.1) — used by the cart-body widget's
			// "Vendor Identity Icon" → "Static Icon" mode as a marketplace-
			// agnostic alternative to the per-vendor profile photo.
			'user'           => '<svg ' . $attrs . '><circle cx="12" cy="8" r="4"/><path d="M6 21v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1"/></svg>',
			'building-store' => '<svg ' . $attrs . '><path d="M3 21h18"/><path d="M3 7h18l-2-3H5l-2 3"/><path d="M5 21V11"/><path d="M19 21V11"/><path d="M9 21v-6h6v6"/></svg>',
			'shopping-bag'   => '<svg ' . $attrs . '><path d="M6 8h12l-1 13H7L6 8Z"/><path d="M9 11V6a3 3 0 0 1 6 0v5"/></svg>',
			'briefcase'      => '<svg ' . $attrs . '><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg>',
		];

		return $library;
	}
}

