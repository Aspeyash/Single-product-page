<?php
/**
 * Asset registration and enqueueing.
 *
 * v1.1.0: Registers and enqueues the Header plugin's own cart script
 * (zymarg-cart.js) and stylesheet (zymarg-cart.css) unconditionally,
 * with no dependency on Theme Builder being active.
 *
 * Inline cart styles (color/size customisations from admin settings) are
 * appended via wp_add_inline_style() after zymarg-cart is enqueued.
 *
 * @package ZymargHeader
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	const STYLE_HANDLE      = 'zymarg-header';
	const SCRIPT_HANDLE     = 'zymarg-header-js';
	const CART_STYLE_HANDLE = 'zymarg-cart';
	const CART_SCRIPT_HANDLE= 'zymarg-cart-js';

	/* ── Registration (priority 5) ─────────────────────────────── */

	public static function register(): void {
		// Shared ZYMARG brand tokens. Must load before everything else that
		// references --zym-* custom properties. Guarded registration so
		// whichever ZYMARG plugin loads first "wins" and every other plugin
		// reuses the same stylesheet (see ZYMARG Design Tokens doc, "THE
		// SHARED FILES" section). Previously enqueued only in wp-admin via
		// Admin::enqueue_menu_branding(); now also registered here so the
		// front-end header/cart CSS resolves its --zym-* references,
		// including dark-mode support (new in this version).
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_HEADER_URL . 'assets/css/zymarg-tokens.css',
				array(),
				ZYMARG_HEADER_VERSION
			);
		}

		// Header base styles
		wp_register_style(
			self::STYLE_HANDLE,
			ZYMARG_HEADER_URL . 'assets/css/zymarg-header.css',
			array( 'zymarg-tokens' ),
			ZYMARG_HEADER_VERSION
		);

		// Header base script
		wp_register_script(
			self::SCRIPT_HANDLE,
			ZYMARG_HEADER_URL . 'assets/js/zymarg-header.js',
			array( 'jquery' ),
			ZYMARG_HEADER_VERSION,
			true
		);

		// Cart stylesheet — self-contained copy of TB's cart.css
		wp_register_style(
			self::CART_STYLE_HANDLE,
			ZYMARG_HEADER_URL . 'assets/css/zymarg-cart.css',
			array( 'zymarg-tokens' ),
			ZYMARG_HEADER_VERSION
		);

		// Cart script — self-contained port of TB's cart.js
		wp_register_script(
			self::CART_SCRIPT_HANDLE,
			ZYMARG_HEADER_URL . 'assets/js/zymarg-cart.js',
			array( 'jquery' ),
			ZYMARG_HEADER_VERSION,
			true
		);

		// Localize cart config for zymarg-cart.js
		if ( function_exists( 'WC' ) ) {
			wp_localize_script(
				self::CART_SCRIPT_HANDLE,
				'ZymargHdrCart',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Cart_Ajax::NONCE_ACTION ),
					'i18n'    => array(
						'viewCart' => __( 'View cart', 'zymarg-header' ),
					),
				)
			);
		}
	}

	/* ── Enqueue (priority 20) ─────────────────────────────────── */

	public static function enqueue(): void {
		/* Skip ALL assets when display conditions say the header should not
		 * render on this page. This prevents the header CSS from adding body
		 * padding / top-offset space even though no HTML is output.
		 * wp_enqueue_scripts fires after the WP query is set up, so all
		 * template conditionals (is_page, dokan_is_store_page, etc.) work
		 * correctly here. @since 1.1.19 */
		if ( ! Display_Conditions::should_render() ) {
			return;
		}

		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		// Cart assets — enqueued unconditionally when WooCommerce is active.
		// No conditional check on TB's registered handles needed anymore.
		if ( function_exists( 'WC' ) ) {
			wp_enqueue_style( self::CART_STYLE_HANDLE );
			wp_enqueue_script( self::CART_SCRIPT_HANDLE );

			// Append admin-configured style values (colors, sizes) as scoped CSS.
			// Scoped to .z-hdr-cart-root so it never affects TB Cart widgets
			// elsewhere on the page if Theme Builder is also active.
			$inline_css = Settings::cart_inline_css();
			if ( '' !== $inline_css ) {
				wp_add_inline_style( self::CART_STYLE_HANDLE, $inline_css );
			}
		}
	}
}
