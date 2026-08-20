<?php
/**
 * Header bootstrap — registers all hooks.
 *
 * v1.1.0:
 *  - enqueue_assets moved to priority 20 (fixes TB script timing race).
 *  - Cart_Ajax::register_hooks() called here (owns fragment filter + AJAX).
 *  - No dependency on Theme Builder for cart functionality.
 *
 * @package ZymargHeader
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Header {

	private static ?Header $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		// ── v1.1.11 migration: reset cart_trigger_gap if still at old default (8) ──
		// cart_trigger_gap default changed from 8 → 2 to match Account/Wishlist gap.
		// WordPress keeps option values in the DB even after plugin deletion, so we
		// reset it once here if it's still the old value.
		$saved = get_option( 'zymarg_header_settings', array() );
		$dirty = false;
		if ( isset( $saved['cart_trigger_gap'] ) && '8' === (string) $saved['cart_trigger_gap'] ) {
			$saved['cart_trigger_gap'] = '2';
			$dirty = true;
		}
		// ── v1.1.12 migration: reset cart badge defaults to match wishlist badge ──
		if ( isset( $saved['cart_badge_bg'] ) && '#9500a5' === $saved['cart_badge_bg'] ) {
			$saved['cart_badge_bg'] = 'linear-gradient(135deg,#9500a5 0%,#bd00d1 100%)';
			$dirty = true;
		}
		if ( isset( $saved['cart_badge_size'] ) && '18' === (string) $saved['cart_badge_size'] ) {
			$saved['cart_badge_size'] = '17';
			$dirty = true;
		}
		if ( isset( $saved['cart_badge_font_size'] ) && '11' === (string) $saved['cart_badge_font_size'] ) {
			$saved['cart_badge_font_size'] = '9.5';
			$dirty = true;
		}
		if ( $dirty ) {
			update_option( 'zymarg_header_settings', $saved );
		}

		// Register our own cart AJAX + fragment filter.
		// Gated on WooCommerce inside Cart_Ajax::register_hooks().
		Cart_Ajax::register_hooks();

		// Asset registration runs early so other plugins can depend on handles.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// Enqueue assets at priority 20.
		// MUST be > 10 so Theme Builder's register_scripts() (priority 10) has
		// already run if both plugins are active. Previously at priority 10 this
		// ran before TB, causing wp_script_is('zymarg-tb-cart','registered')
		// to return false and cart.js to never load. Fixed in v1.0.2 / v1.1.0.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		// Inject the header HTML at the very start of <body>.
		add_action( 'wp_body_open', array( $this, 'inject' ) );
	}

	/* ── Asset hooks ───────────────────────────────────────────── */

	public function register_assets(): void {
		Assets::register();
	}

	public function enqueue_assets(): void {
		Assets::enqueue();
	}

	/* ── Header output ─────────────────────────────────────────── */

	public function inject(): void {
		// Check display conditions before rendering — @since 1.1.17
		if ( ! Display_Conditions::should_render() ) {
			return;
		}
		Renderer::render();
	}
}
