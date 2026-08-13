<?php
/**
 * Assets — enqueue CSS and JS for the front-end template and admin panel.
 *
 * @version 1.0.11
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	const HANDLE_CSS   = 'zymarg-sp-style';
	const HANDLE_JS    = 'zymarg-sp-script';
	// Renamed from 'zymarg-sp-admin' in v2.4.6: that exact handle is also used
	// by the separate ZYMARG Store Page plugin for its own unrelated admin
	// CSS/JS file. Under the same handle, WordPress's enqueue dedup means
	// whichever plugin registers first "wins" the handle and the other
	// plugin's admin assets never load at all (no error, just missing styles
	// and inert JS on that plugin's own settings screen).
	const HANDLE_ADMIN = 'zymarg-single-product-admin';

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
		add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend' ] );
		// v2.1.0 - grid sections live in plugin options, not post_content, so
		// the engine's own shortcode preloader cannot see them. Priority 6
		// matches the engine's own preloader so assets still land in <head>.
		add_action( 'wp_enqueue_scripts',    [ $this, 'preload_section_assets' ], 6 );
		add_filter( 'zymarg_wcpg_force_shortcode_asset_preload', [ $this, 'force_engine_preload' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	// ── Front-end ─────────────────────────────────────────────────────────────

	public function enqueue_frontend(): void {
		if ( ! is_product() ) {
			return;
		}

		// ── CSS ──────────────────────────────────────────────────────────────
		wp_enqueue_style(
			self::HANDLE_CSS,
			ZYMARG_SNGL_ASSETS . 'css/zymarg-sp.css',
			[],
			ZYMARG_SNGL_VERSION
		);

		// ── JS ───────────────────────────────────────────────────────────────
		// We depend on WooCommerce's variation JS so it binds data-product_variations.
		wp_enqueue_script( 'wc-add-to-cart-variation' );

		wp_enqueue_script(
			self::HANDLE_JS,
			ZYMARG_SNGL_ASSETS . 'js/zymarg-sp.js',
			[ 'jquery', 'wc-add-to-cart-variation' ],
			ZYMARG_SNGL_VERSION,
			true
		);

		// Native swatch interaction (WSE port) — selection, keyboard, availability.
		wp_enqueue_script(
			'zymarg-sp-swatches',
			ZYMARG_SNGL_ASSETS . 'js/zymarg-sp-swatches.js',
			[ 'jquery', 'wc-add-to-cart-variation', self::HANDLE_JS ],
			ZYMARG_SNGL_VERSION,
			true
		);

		// Live price updates for variable products (WSE port).
		wp_enqueue_script(
			'zymarg-sp-price',
			ZYMARG_SNGL_ASSETS . 'js/zymarg-sp-price.js',
			[ 'jquery', 'wc-add-to-cart-variation', self::HANDLE_JS ],
			ZYMARG_SNGL_VERSION,
			true
		);

		// ── Localise ─────────────────────────────────────────────────────────
		global $product;
		$wc_product = ( $product instanceof \WC_Product ) ? $product : wc_get_product( get_the_ID() );

		$js_opts = Options::all();

		wp_localize_script( self::HANDLE_JS, 'zymargSP', [
			'ajax_url'           => admin_url( 'admin-ajax.php' ),
			// v1.0.11 #3 — WC-AJAX endpoint for WooCommerce's native add-to-cart
			// handler. The front-end JS swaps %%endpoint%% for 'add_to_cart'.
			'wc_ajax_url'        => class_exists( '\\WC_AJAX' ) ? \WC_AJAX::get_endpoint( '%%endpoint%%' ) : '',
			'buy_now'            => Buy_Now::js_data(),
			'nonce_atc'          => wp_create_nonce( 'zymarg_sp_atc' ),
			'product_id'         => $wc_product ? $wc_product->get_id() : 0,
			'is_variable'        => $wc_product ? $wc_product->is_type( 'variable' ) : false,
			'currency_symbol'    => get_woocommerce_currency_symbol(),
			'atc_text'           => $js_opts['atc_btn_text'],
			'atc_text_loading'   => $js_opts['atc_btn_text_loading'],
			'atc_text_done'      => $js_opts['atc_btn_text_done'],
			// v2.4.4 - the added-to-cart "View cart" link + toast were removed
			// per user request; the toast element/JS itself is kept for the
			// wishlist toggle and error feedback.
			'wishlist'           => Wishlist_Ajax::js_data(),
			'swatch_shape'       => $js_opts['swatch_shape'],
			'swatch_oos'         => $js_opts['swatch_oos_behavior'],
			'swatch_tooltip'     => $js_opts['swatch_tooltip'],
			'swatch_tooltip_pos' => $js_opts['swatch_tooltip_position'],
			'swatch_auto_select' => $js_opts['swatch_auto_select'],
			'gallery_zoom'       => $js_opts['gallery_hover_zoom'],
			'gallery_lightbox'   => $js_opts['gallery_lightbox'],
			'price_anim'         => $js_opts['price_change_animation'],
			'sticky_enabled'     => $js_opts['sticky_bar_enabled'],
			'qty_min'            => max( 1, (int) $js_opts['qty_min'] ),
			'qty_max'            => (int) $js_opts['qty_max'],
			'qty_default'        => max( 1, (int) $js_opts['qty_default'] ),
			'qty_sync_sticky'    => $js_opts['qty_sync_sticky'],
			'price'              => [
				'decimals'     => wc_get_price_decimals(),
				'decimal_sep'  => wc_get_price_decimal_separator(),
				'thousand_sep' => wc_get_price_thousand_separator(),
				'symbol'       => get_woocommerce_currency_symbol(),
				'format'       => get_woocommerce_price_format(),
			],
			'i18n'               => [
				'sold_out'         => __( 'Sold Out',    'zymarg-single-product' ),
				'unavailable'      => __( 'Unavailable', 'zymarg-single-product' ),
				'select_options'   => __( 'Select options', 'zymarg-single-product' ),
				'clear'            => $js_opts['swatch_clear_label'],
				'buynow_text'      => $js_opts['buynow_text'],
			],
		] );

		// v2.0.0 — review markup, styles and scripts now belong to the standalone
		// ZYMARG Reviews Engine plugin. It registers and localises its own assets;
		// we only ask it to load them on this product page.
		if ( function_exists( 'zymarg_reviews_enqueue' ) ) {
			zymarg_reviews_enqueue( $wc_product ? $wc_product->get_id() : (int) get_the_ID() );
		}
	}


	// -- Product grid section assets (v2.1.0) ---------------------------------

	/**
	 * Whether this page has at least one enabled section carrying a shortcode.
	 *
	 * @return bool
	 */
	private static function has_enabled_sections(): bool {
		$sections = Options::get( 'product_sections', [] );

		if ( ! is_array( $sections ) ) {
			return false;
		}

		foreach ( $sections as $section ) {
			if ( is_array( $section ) && ! empty( $section['enabled'] ) && '' !== trim( (string) ( $section['shortcode'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Force the engine's early asset enqueue on product pages that use sections.
	 *
	 * The engine only scans $post->post_content for its shortcodes, so sections
	 * stored as plugin options are invisible to it and the grid would otherwise
	 * paint unstyled before snapping into place.
	 *
	 * @param bool $force Incoming value.
	 * @return bool
	 */
	public function force_engine_preload( $force ) {
		if ( function_exists( 'is_product' ) && is_product() && self::has_enabled_sections() ) {
			return true;
		}

		return $force;
	}

	/**
	 * Resolve and enqueue the engine assets each stored section needs.
	 *
	 * Mirrors the engine's own preloader: only the layout type and the card
	 * template change which assets are required, and Asset_Manager decides the
	 * rest. Every enqueue is idempotent, so the real render is a no-op.
	 *
	 * @return void
	 */
	public function preload_section_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		if ( ! class_exists( '\\Zymarg\\WCPG\\Services\\Asset_Manager' ) ) {
			return;
		}

		$sections = Options::get( 'product_sections', [] );

		if ( ! is_array( $sections ) ) {
			return;
		}

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['enabled'] ) ) {
				continue;
			}

			$code = trim( (string) ( $section['shortcode'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}

			$atts = shortcode_parse_atts( trim( $code, '[]' ) );
			if ( ! is_array( $atts ) ) {
				$atts = [];
			}

			$layout = ( isset( $atts['layout'] ) && 'slider' === strtolower( trim( (string) $atts['layout'] ) ) )
				? 'slider'
				: 'grid';

			$template = isset( $atts['card_template'] ) ? sanitize_key( (string) $atts['card_template'] ) : '';

			\Zymarg\WCPG\Services\Asset_Manager::enqueue_for_render(
				[
					'layout' => [ 'type' => $layout ],
					'card'   => [ 'template' => '' !== $template ? $template : 'classic' ],
				]
			);
		}
	}

	// -- Admin ----------------------------------------------------------------


	public function enqueue_admin( string $hook ): void {
		// Only load on our settings page.
		if ( ! str_contains( $hook, 'zymarg-single-product' ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE_ADMIN,
			ZYMARG_SNGL_ASSETS . 'css/zymarg-single-product-admin.css',
			[],
			ZYMARG_SNGL_VERSION
		);

		wp_enqueue_script(
			self::HANDLE_ADMIN,
			ZYMARG_SNGL_ASSETS . 'js/zymarg-single-product-admin.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			ZYMARG_SNGL_VERSION,
			true
		);

		wp_localize_script( self::HANDLE_ADMIN, 'zymargSingleProductAdmin', [
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'zymarg_sngl_admin_save' ),
			'saved_text'  => __( 'Settings saved!', 'zymarg-single-product' ),
			'error_text'  => __( 'Error saving. Please try again.', 'zymarg-single-product' ),
			'settings'    => Options::all(),

			// v2.2.0 - section editing guards.
			'restore_nonce'      => wp_create_nonce( 'zymarg_sp_restore_sections' ),
			'allowed_shortcodes' => array_values(
				(array) apply_filters(
					'zymarg_sp_allowed_section_shortcodes',
					[ 'zymarg_products', 'zymarg_wcpg_wishlist', 'zymarg_wcpg_recently_viewed' ]
				)
			),
			'edit_text'          => __( 'Edit', 'zymarg-single-product' ),
			'done_text'          => __( 'Done', 'zymarg-single-product' ),
			'untitled_text'      => __( 'this section', 'zymarg-single-product' ),
			'confirm_remove'     => __( 'Remove "%s"? It will stop rendering on every product page once you save.', 'zymarg-single-product' ),
			'confirm_restore'    => __( 'Restore the section list from before your last save? The list you have now becomes the restore point, so you can swap back.', 'zymarg-single-product' ),
			'confirm_save'       => __( 'Save these section changes?', 'zymarg-single-product' ),
			'invalid_text'       => __( 'Fix these section problems before saving:', 'zymarg-single-product' ),
			'restore_failed'     => __( 'Restore failed. Please reload and try again.', 'zymarg-single-product' ),
		] );
	}
}
