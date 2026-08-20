<?php
/**
 * Cart Page Override — Standalone Edition.
 *
 * Strategy (bulletproof — works with Astra + WooCommerce):
 *
 *   1. We use the WooCommerce cart page ID to identify the cart page.
 *   2. We hook into `the_content` filter on the cart page and replace
 *      ALL content (shortcode, block, or anything else) with our own
 *      three-section HTML output.
 *   3. We also filter `woocommerce_shortcode_cart_html` as a secondary
 *      catch for sites that rely on the [woocommerce_cart] shortcode.
 *   4. We suppress default WC notice injection that would duplicate output.
 *
 * This approach works regardless of:
 *   - Whether the cart page uses a shortcode or a WC block.
 *   - Which theme is active (Astra, Storefront, etc.).
 *   - Whether Elementor is installed or not.
 *   - Whether the WC cart template hierarchy is customised.
 *
 * The WooCommerce cart page is still the WooCommerce cart page — its
 * permalink, title, breadcrumbs, and your theme's header/footer all
 * remain intact. We only replace the inner page content area.
 *
 * @package ZymargCart
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Page {

	public static function init(): void {

		// PRIMARY: Replace the_content on the WC cart page.
		add_filter( 'the_content', [ self::class, 'replace_cart_content' ], 50 );

		// SECONDARY: Also catch [woocommerce_cart] shortcode output.
		add_filter( 'woocommerce_shortcode_cart_html', [ self::class, 'override_shortcode_html' ] );

		// TERTIARY: Catch the WC block cart render (WooCommerce Blocks).
		add_filter( 'render_block_woocommerce/cart', [ self::class, 'override_block_html' ], 10, 2 );

		// Suppress the default WC cart notices bar (we render our own status).
		add_action( 'woocommerce_before_cart', [ self::class, 'suppress_wc_notices' ], 1 );

		// Make WC treat this page as the cart page properly.
		add_filter( 'woocommerce_is_cart', [ self::class, 'force_is_cart' ] );
	}

	// ── Primary: replace_the_content ─────────────────────────────────────

	/**
	 * Replaces the WordPress post content on the WC cart page with our
	 * three-section custom cart HTML.
	 *
	 * Runs on the `the_content` filter at priority 50 (after most plugins
	 * but before most output). Only fires on the actual WC cart page.
	 *
	 * @param string $content Original post content.
	 * @return string Our cart HTML or the original content unchanged.
	 */
	public static function replace_cart_content( string $content ): string {
		if ( ! self::is_cart_page() ) {
			return $content;
		}

		// Ensure WC cart is initialised before we try to read from it.
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return $content;
		}

		ob_start();
		self::render_all();
		$output = ob_get_clean();

		return $output ?: $content;
	}

	// ── Secondary: shortcode override ─────────────────────────────────────

	/**
	 * Replaces the [woocommerce_cart] shortcode HTML output.
	 * Catches cases where the cart page content is rendered via shortcode
	 * and the_content filter alone isn't enough.
	 */
	public static function override_shortcode_html( string $html ): string {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return $html;
		}

		ob_start();
		self::render_all();
		return ob_get_clean() ?: $html;
	}

	// ── Tertiary: block override ───────────────────────────────────────────

	/**
	 * Replaces the WooCommerce Cart block HTML output.
	 * Catches Gutenberg / FSE setups using the woocommerce/cart block.
	 */
	public static function override_block_html( string $html, array $block ): string {
		if ( ! self::is_cart_page() ) {
			return $html;
		}
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return $html;
		}

		ob_start();
		self::render_all();
		return ob_get_clean() ?: $html;
	}

	// ── Suppress WC default notices ────────────────────────────────────────

	public static function suppress_wc_notices(): void {
		// Remove WC's default notice output from the cart page so our
		// custom UI is the only thing rendered in the content area.
		remove_action( 'woocommerce_before_cart', 'woocommerce_output_all_notices', 10 );
	}

	// ── Force is_cart() ───────────────────────────────────────────────────

	/**
	 * Ensures WC recognises the current page as the cart page.
	 * Needed for asset enqueue checks and WC internal routing.
	 */
	public static function force_is_cart( bool $is_cart ): bool {
		if ( $is_cart ) {
			return true;
		}
		// Only set true when we're on the actual WC cart page post.
		global $post;
		$cart_page_id = wc_get_page_id( 'cart' );
		if ( $cart_page_id > 0 && $post instanceof WP_Post && $post->ID === $cart_page_id ) {
			return true;
		}
		return $is_cart;
	}

	// ── Is cart page guard ────────────────────────────────────────────────

	/**
	 * Returns true when we are on the WooCommerce cart page.
	 * Uses both is_cart() (WC function) and a post ID check as fallback.
	 */
	private static function is_cart_page(): bool {
		// WC native check — most reliable when WC is fully loaded.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		// Fallback: post ID comparison for edge cases where is_cart()
		// returns false too early in the request lifecycle.
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$cart_page_id = wc_get_page_id( 'cart' );
		return $cart_page_id > 0 && $post->ID === $cart_page_id;
	}

	// ── Master render ─────────────────────────────────────────────────────

	/**
	 * Renders all three sections wrapped in the cart wrapper div.
	 * Called by all three override methods above.
	 */
	private static function render_all(): void {
		// Print any WC notices (e.g. "Item added to cart") before our cart.
		if ( function_exists( 'woocommerce_output_all_notices' ) ) {
			woocommerce_output_all_notices();
		}

		echo '<div class="zymarg-cart-wrapper" data-zymarg-cart="1">';
		self::render_cart_header();
		self::render_cart_body();
		self::render_cart_total();
		echo '</div><!-- /.zymarg-cart-wrapper -->';
	}

	// ── Shared data builder ───────────────────────────────────────────────

	private static ?array $cart_data_cache = null;

	private static function get_cart_data(): array {
		if ( null !== self::$cart_data_cache ) {
			return self::$cart_data_cache;
		}

		$wc_cart    = WC()->cart;
		$is_empty   = ! $wc_cart || $wc_cart->is_empty();
		$item_count = $wc_cart ? $wc_cart->get_cart_contents_count() : 0;
		$user_id    = get_current_user_id();

		$grouped_vendors = [];
		$applied_coupons = [];
		$saved_items     = [];

		if ( ! $is_empty ) {
			$grouped_vendors = Zymarg_Cart_Dokan::get_cart_grouped_by_vendor();
			$applied_coupons = Zymarg_Cart_Dokan::get_applied_coupons();
		}

		if ( 'yes' === ( Zymarg_Cart_Settings::get_body()['save_later_enabled'] ?? 'yes' ) ) {
			if ( $user_id > 0 ) {
				$saved_items = Zymarg_Cart_Usermeta::get_saved_items( $user_id );
			} else {
				$saved_items = Zymarg_Cart_Session::get_saved_items();
			}
		}

		// Initial load: pass all item keys as selected (all checked by default).
		$all_keys = $wc_cart ? array_keys( $wc_cart->get_cart() ) : [];
		$totals   = Zymarg_Cart_Dokan::get_totals_for_selected( $all_keys );

		self::$cart_data_cache = compact(
			'is_empty',
			'item_count',
			'user_id',
			'grouped_vendors',
			'applied_coupons',
			'saved_items',
			'totals'
		);

		return self::$cart_data_cache;
	}

	// ── Render: Cart Header ───────────────────────────────────────────────

	public static function render_cart_header(): void {
		$data       = self::get_cart_data();
		$settings   = Zymarg_Cart_Settings::get_header();
		$item_count = $data['item_count'];

		$tpl = ZYMARG_CART_PATH . 'templates/cart-header.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}

	// ── Render: Cart Body ─────────────────────────────────────────────────

	public static function render_cart_body(): void {
		$data = self::get_cart_data();

		$settings        = Zymarg_Cart_Settings::get_body();
		$grouped_vendors = $data['grouped_vendors'];
		$is_empty        = $data['is_empty'];
		$user_id         = $data['user_id'];
		$saved_items     = $data['saved_items'];
		$applied_coupons = $data['applied_coupons'];

		$tpl = ZYMARG_CART_PATH . 'templates/cart-body.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}

	// ── Render: Cart Total ────────────────────────────────────────────────

	public static function render_cart_total(): void {
		$data = self::get_cart_data();

		$settings   = Zymarg_Cart_Settings::get_total();
		$totals     = $data['totals'];
		$item_count = $data['item_count'];

		$tpl = ZYMARG_CART_PATH . 'templates/cart-total.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}
}
