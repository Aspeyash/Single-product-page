<?php
/**
 * Plugin core singleton.
 *
 * @version 1.1.1
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

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
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_woo_missing' ] );
			return;
		}

		// v2.1.0 - carry legacy hardcoded sections into the new ordered list.
		Options::maybe_migrate_sections();
		Options::maybe_upgrade_sections();

		Assets::instance()->init();
		Template_Override::instance()->init();
		Buy_Now::instance()->init();
		Wishlist_Ajax::instance()->init();
		Admin::instance()->init();

		// v1.0.13 - suppress WooCommerce's default AJAX add-to-cart notice for our flagged requests.
		add_action( 'wc_ajax_add_to_cart', [ $this, 'suppress_ajax_atc_notice' ], 0 );
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'clear_ajax_atc_notices' ] );

		// ── Native swatches engine (WSE_* → native port) ───────────────────
		Swatches\Attribute_Types::instance();
		Swatches\Term_Meta::instance();
		Swatches\Renderer::instance();
		Product_Video::instance();

		// Swatch image size (image swatches + admin term preview).
		add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
	}

	/**
	 * Suppresses WooCommerce's "added to cart" notice on our flagged AJAX
	 * requests so the theme toast is the only feedback (v1.0.13).
	 */
	public function suppress_ajax_atc_notice(): void {
		if ( isset( $_POST['zymarg_atc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_filter( 'option_woocommerce_cart_redirect_after_add', static function () {
				return 'no';
			} );
		}
	}

	/**
	 * Clears queued WooCommerce notices for our flagged AJAX add-to-cart (v1.0.13).
	 *
	 * @param array $fragments Refreshed cart fragments.
	 * @return array
	 */
	public function clear_ajax_atc_notices( $fragments ) {
		if ( isset( $_POST['zymarg_atc'] ) && function_exists( 'wc_clear_notices' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wc_clear_notices();
		}
		return $fragments;
	}

	/**
	 * Registers the square swatch thumbnail size (80×80, hard crop).
	 */
	public function register_image_sizes(): void {
		add_image_size( 'zymarg_sp_swatch', 80, 80, true );
	}

	public function notice_woo_missing(): void {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'ZYMARG Single Product requires WooCommerce to be active.', 'zymarg-single-product' );
		echo '</p></div>';
	}
}
