<?php
/**
 * Cart AJAX + Fragment helpers — zero Theme Builder dependency.
 *
 * Self-contained port of ZymargThemeBuilder\Cart_Ajax. Owns its own:
 *  - nonce action  : zymarg_hdr_cart
 *  - AJAX actions  : zymarg_hdr_cart_remove, zymarg_hdr_cart_qty
 *  - fragment filter hooked to woocommerce_add_to_cart_fragments
 *
 * The JS counterpart (zymarg-cart.js) reads window.ZymargHdrCart for
 * ajaxUrl, nonce, and i18n, and posts to the actions above.
 *
 * @package ZymargHeader
 * @since   1.1.0
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart AJAX service for the Header plugin.
 */
class Cart_Ajax {

	const NONCE_ACTION = 'zymarg_hdr_cart';

	/* -------------------------------------------------------------------
	 * Hook registration
	 * ----------------------------------------------------------------- */

	/**
	 * Wire all hooks. Called from Header::init().
	 * Gated on WooCommerce being active.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Fragment filter — fires on every WC AJAX add-to-cart call,
		// including WCPG, WooSwatches, and native WC. No Elementor guard.
		add_filter( 'woocommerce_add_to_cart_fragments', array( static::class, 'cart_fragments' ) );

		// AJAX endpoints — logged-in and guest.
		add_action( 'wp_ajax_zymarg_hdr_cart_remove',        array( static::class, 'ajax_remove_item' ) );
		add_action( 'wp_ajax_nopriv_zymarg_hdr_cart_remove', array( static::class, 'ajax_remove_item' ) );
		add_action( 'wp_ajax_zymarg_hdr_cart_qty',           array( static::class, 'ajax_update_qty' ) );
		add_action( 'wp_ajax_nopriv_zymarg_hdr_cart_qty',    array( static::class, 'ajax_update_qty' ) );
	}

	/* -------------------------------------------------------------------
	 * Fragment filter
	 * ----------------------------------------------------------------- */

	/**
	 * Inject the ZYMARG cart fragload element + items list into every
	 * woocommerce_add_to_cart_fragments response.
	 *
	 * @param array $fragments Existing fragments.
	 * @return array
	 */
	public static function cart_fragments( array $fragments ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $fragments;
		}
		$count_type = Settings::get( 'cart_count_type', 'total_qty' );
		$data       = self::get_cart_snapshot( $count_type );

		$fragments['.zymarg-tb-cart-fragload'] = self::fragload_html( $data );
		$fragments['.zymarg-tb-cart__items']   =
			'<ul class="zymarg-tb-cart__items" aria-live="polite">'
			. self::items_html( $data['items'] ) . '</ul>';

		return $fragments;
	}

	/* -------------------------------------------------------------------
	 * AJAX endpoints
	 * ----------------------------------------------------------------- */

	/**
	 * AJAX: remove a cart item, return refreshed fragments.
	 */
	public static function ajax_remove_item(): void {
		self::verify_ajax();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'zymarg-header' ) ), 400 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing item key.', 'zymarg-header' ) ), 400 );
		}
		WC()->cart->remove_cart_item( $key );
		WC()->cart->calculate_totals();
		if ( WC()->session ) {
			WC()->session->save_data();
		}
		self::send_fragment_response();
	}

	/**
	 * AJAX: set the quantity of a cart item, return refreshed fragments.
	 */
	public static function ajax_update_qty(): void {
		self::verify_ajax();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'zymarg-header' ) ), 400 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$qty = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : -1;
		if ( '' === $key || $qty < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'zymarg-header' ) ), 400 );
		}
		if ( 0 === $qty ) {
			WC()->cart->remove_cart_item( $key );
		} else {
			WC()->cart->set_quantity( $key, $qty, true );
		}
		WC()->cart->calculate_totals();
		if ( WC()->session ) {
			WC()->session->save_data();
		}
		self::send_fragment_response();
	}

	/* -------------------------------------------------------------------
	 * Cart data helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Build a normalised cart snapshot array.
	 *
	 * @param string $count_type 'total_qty' | 'unique'
	 * @return array{count:int,qty:int,unique:int,subtotal:string,items:array}
	 */
	public static function get_cart_snapshot( string $count_type = 'total_qty' ): array {
		$qty      = 0;
		$unique   = 0;
		$subtotal = '';
		$items    = array();

		if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && WC()->cart ) {
			$qty      = (int) WC()->cart->get_cart_contents_count();
			$unique   = count( WC()->cart->get_cart() );
			$subtotal = (string) WC()->cart->get_cart_subtotal();
			$items    = self::collect_items();
		}

		$count = ( 'unique' === $count_type ) ? $unique : $qty;

		return array(
			'count'    => (int) $count,
			'qty'      => (int) $qty,
			'unique'   => (int) $unique,
			'subtotal' => $subtotal,
			'items'    => $items,
		);
	}

	/**
	 * Collect WooCommerce cart items into a normalised array.
	 *
	 * @return array
	 */
	public static function collect_items(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$product_id = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : $product->get_id();
			$parent     = wc_get_product( $product_id );
			$name       = $parent instanceof \WC_Product ? $parent->get_name() : $product->get_name();
			$permalink  = $parent instanceof \WC_Product
				? $parent->get_permalink()
				: $product->get_permalink( $item );

			$image_id  = $product->get_image_id();
			$image_url = $image_id
				? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
				: (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );

			$attrs = '';
			if ( function_exists( 'wc_get_formatted_cart_item_data' ) ) {
				$attrs = trim( wp_strip_all_tags( wc_get_formatted_cart_item_data( $item ) ) );
			}

			$out[] = array(
				'key'       => (string) $key,
				'name'      => (string) $name,
				'permalink' => (string) $permalink,
				'image'     => $image_url,
				'sku'       => (string) $product->get_sku(),
				'attrs'     => $attrs,
				'qty'       => (int) $item['quantity'],
				'price'     => WC()->cart->get_product_subtotal( $product, $item['quantity'] ),
			);
		}
		return $out;
	}

	/**
	 * Build the hidden fragload <span> HTML.
	 * Watched by zymarg-cart.js MutationObserver.
	 *
	 * @param array $data Cart snapshot.
	 * @return string
	 */
	public static function fragload_html( array $data ): string {
		return sprintf(
			'<span class="zymarg-tb-cart-fragload" data-qty="%1$d" data-unique="%2$d" data-subtotal="%3$s" hidden></span>',
			(int) $data['qty'],
			(int) $data['unique'],
			esc_attr( $data['subtotal'] )
		);
	}

	/**
	 * Build <li> markup for a set of cart items.
	 *
	 * @param array $items Normalised items array.
	 * @return string
	 */
	public static function items_html( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}
		$html = '';
		foreach ( $items as $it ) {
			$html .= '<li class="zymarg-tb-cart__item" data-key="' . esc_attr( $it['key'] ) . '">';

			$html .= '<a class="zymarg-tb-cart__item-img" href="' . esc_url( $it['permalink'] ) . '" tabindex="-1" aria-hidden="true">';
			$html .= '<img src="' . esc_url( $it['image'] ) . '" alt="" loading="lazy" />';
			$html .= '</a>';

			$html .= '<div class="zymarg-tb-cart__item-info">';
			$html .= '<a class="zymarg-tb-cart__item-title" href="' . esc_url( $it['permalink'] ) . '">' . esc_html( $it['name'] ) . '</a>';
			if ( '' !== $it['sku'] ) {
				$html .= '<span class="zymarg-tb-cart__item-sku">'
					. esc_html( sprintf( /* translators: %s: product SKU */ __( 'SKU: %s', 'zymarg-header' ), $it['sku'] ) )
					. '</span>';
			}
			if ( '' !== $it['attrs'] ) {
				$html .= '<span class="zymarg-tb-cart__item-attrs">' . esc_html( $it['attrs'] ) . '</span>';
			}

			$html .= '<div class="zymarg-tb-cart__item-priceline">';
			$html .= '<div class="zymarg-tb-cart__qty" data-key="' . esc_attr( $it['key'] ) . '">';
			$html .= '<button type="button" class="zymarg-tb-cart__qty-btn" data-dir="down" aria-label="'
				. esc_attr__( 'Decrease quantity', 'zymarg-header' ) . '">&minus;</button>';
			$html .= '<span class="zymarg-tb-cart__qty-val">' . esc_html( (string) $it['qty'] ) . '</span>';
			$html .= '<button type="button" class="zymarg-tb-cart__qty-btn" data-dir="up" aria-label="'
				. esc_attr__( 'Increase quantity', 'zymarg-header' ) . '">&plus;</button>';
			$html .= '</div>';
			$html .= '<span class="zymarg-tb-cart__item-price">' . wp_kses_post( $it['price'] ) . '</span>';
			$html .= '</div>'; // priceline
			$html .= '</div>'; // info

			$html .= '<button type="button" class="zymarg-tb-cart__item-remove" data-key="' . esc_attr( $it['key'] ) . '" aria-label="'
				. esc_attr( sprintf(
					/* translators: %s: product name */
					__( 'Remove %s', 'zymarg-header' ),
					$it['name']
				) ) . '">&times;</button>';

			$html .= '</li>';
		}
		return $html;
	}

	/* -------------------------------------------------------------------
	 * Internal helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Verify the cart nonce from $_POST['security']. Exits on failure.
	 */
	public static function verify_ajax(): void {
		$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh.', 'zymarg-header' ) ),
				403
			);
		}
	}

	/**
	 * Send a standard WC-style fragments response that zymarg-cart.js expects.
	 */
	public static function send_fragment_response(): void {
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );
		$cart_hash = WC()->cart->get_cart_hash();
		wp_send_json_success(
			array(
				'fragments' => $fragments,
				'cart_hash' => $cart_hash,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/* -------------------------------------------------------------------
	 * URL helpers
	 * ----------------------------------------------------------------- */

	/** @return string */
	public static function cart_url(): string {
		$override = Settings::cart_url();
		if ( '' !== $override ) {
			return $override;
		}
		return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
	}

	/** @return string */
	public static function checkout_url(): string {
		return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
	}

	/** @return string */
	public static function shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}
}
