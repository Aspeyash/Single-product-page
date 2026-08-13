<?php
/**
 * Buy Now — standalone, no WSE dependency.
 *
 * Flow:
 *  1. AJAX handler saves current cart → session, empties cart, adds product,
 *     sets a TTL timestamp, then returns the checkout URL.
 *  2. On every front-end page load (non-checkout) the restore check runs and
 *     swaps the cart back when the session exists and TTL has expired OR the
 *     user has navigated away from checkout.
 *  3. On order completion / payment the cart is restored and session keys
 *     are cleared.
 *
 * Session keys:
 *   zymarg_sp_bnw_saved_cart  — serialised original cart contents
 *   zymarg_sp_bnw_expires_at  — Unix timestamp of TTL expiry
 *
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Buy_Now {

	const SESSION_CART    = 'zymarg_sp_bnw_saved_cart';
	const SESSION_EXPIRES = 'zymarg_sp_bnw_expires_at';
	const AJAX_ACTION     = 'zymarg_sp_buy_now';
	const NONCE_ACTION    = 'zymarg_sp_buy_now_nonce';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		// AJAX — logged-in and guest.
		add_action( 'wp_ajax_'        . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );

		// Cart restore checks.
		add_action( 'wp',                        [ $this, 'maybe_restore_on_navigation' ] );
		add_action( 'woocommerce_thankyou',      [ $this, 'restore_after_order' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'restore_after_order' ] );
	}

	// ── AJAX handler ─────────────────────────────────────────────────────────

	public function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id   = absint( $_POST['product_id']   ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$quantity     = max( 1, absint( $_POST['quantity'] ?? 1 ) );
		$attributes   = $this->sanitize_attributes( $_POST['attributes'] ?? [] );

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product.', 'zymarg-single-product' ) ] );
		}

		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( [ 'message' => __( 'Product not available.', 'zymarg-single-product' ) ] );
		}

		// Save existing cart.
		$this->save_cart();

		// Empty cart, add Buy Now item.
		WC()->cart->empty_cart();

		$added = WC()->cart->add_to_cart(
			$product_id,
			$quantity,
			$variation_id,
			$attributes
		);

		if ( ! $added ) {
			// Restore immediately if add failed.
			$this->restore_cart();
			wp_send_json_error( [ 'message' => __( 'Could not add product to cart.', 'zymarg-single-product' ) ] );
		}

		// Set TTL.
		$ttl = max( 1, (int) Options::get( 'buynow_session_ttl', 15 ) );
		WC()->session->set( self::SESSION_EXPIRES, time() + ( $ttl * MINUTE_IN_SECONDS ) );

		wp_send_json_success( [
			'checkout_url' => wc_get_checkout_url(),
		] );
	}

	// ── Restore checks ───────────────────────────────────────────────────────

	/**
	 * Restore cart when user navigates away from checkout or TTL expires.
	 */
	public function maybe_restore_on_navigation(): void {
		if ( ! $this->has_saved_cart() ) {
			return;
		}

		$expires = (int) WC()->session->get( self::SESSION_EXPIRES, 0 );
		$ttl_expired = $expires > 0 && time() > $expires;
		$left_checkout = ! is_checkout() && ! is_cart();

		if ( $ttl_expired || $left_checkout ) {
			$this->restore_cart();
		}
	}

	/**
	 * Restore cart after a successful order / payment.
	 */
	public function restore_after_order(): void {
		if ( $this->has_saved_cart() ) {
			$this->restore_cart();
		}
	}

	// ── Internal helpers ─────────────────────────────────────────────────────

	private function has_saved_cart(): bool {
		if ( ! WC()->session ) {
			return false;
		}
		return ! empty( WC()->session->get( self::SESSION_CART ) );
	}

	private function save_cart(): void {
		if ( ! WC()->cart || ! WC()->session ) {
			return;
		}
		WC()->session->set(
			self::SESSION_CART,
			WC()->cart->get_cart_for_session()
		);
	}

	private function restore_cart(): void {
		if ( ! WC()->cart || ! WC()->session ) {
			return;
		}

		$saved = WC()->session->get( self::SESSION_CART );

		WC()->cart->empty_cart( false );

		if ( is_array( $saved ) ) {
			foreach ( $saved as $cart_item_key => $item ) {
				WC()->cart->add_to_cart(
					$item['product_id'],
					$item['quantity'],
					$item['variation_id'] ?? 0,
					$item['variation']    ?? [],
					$item
				);
			}
		}

		// Clear Buy Now session keys.
		WC()->session->set( self::SESSION_CART, null );
		WC()->session->set( self::SESSION_EXPIRES, null );
	}

	private function sanitize_attributes( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$clean = [];
		foreach ( $raw as $key => $value ) {
			$clean[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}
		return $clean;
	}

	/** Localisation data for JS. */
	public static function js_data(): array {
		return [
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'action'      => self::AJAX_ACTION,
			'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
			'checkout_url'=> wc_get_checkout_url(),
		];
	}
}
