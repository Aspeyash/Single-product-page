<?php
/**
 * Core plugin class — Standalone Cart Page edition.
 *
 * Responsibilities:
 * - Singleton entry point.
 * - Dependency validation (WooCommerce, Dokan Pro).
 * - Admin notice system for missing dependencies.
 * - WooCommerce cart page override.
 * - Frontend asset enqueue (CSS + JS) — only on the cart page.
 * - Admin settings page registration.
 * - AJAX action registration for both logged-in and guest users.
 * - WooCommerce HPOS compatibility declaration.
 * - Activation and deactivation routines.
 *
 * @package ZymargCart
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart {

	// ── Singleton ──────────────────────────────────────────────────────────

	private static ?Zymarg_Cart $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// HPOS must be declared regardless of other deps.
		add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );

		if ( ! $this->check_dependencies() ) {
			$this->register_dependency_notices();
			return;
		}

		$this->load_textdomain();
		$this->load_includes();
		$this->register_hooks();
	}

	private function __clone() {}

	public function __wakeup(): void {
		throw new \LogicException( 'Zymarg_Cart singleton cannot be unserialized.' );
	}

	// ── Dependency check ───────────────────────────────────────────────────

	private array $missing_dependencies = [];

	private function check_dependencies(): bool {
		$this->missing_dependencies = [];

		// WooCommerce.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->missing_dependencies[] = sprintf(
				__( 'WooCommerce %s or higher (not installed / not active)', 'zymarg-cart' ),
				ZYMARG_CART_MIN_WC
			);
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, ZYMARG_CART_MIN_WC, '<' ) ) {
			$this->missing_dependencies[] = sprintf(
				__( 'WooCommerce %1$s or higher (you have %2$s)', 'zymarg-cart' ),
				ZYMARG_CART_MIN_WC,
				WC_VERSION
			);
		}

		// Dokan Pro.
		$dokan_active = (
			class_exists( 'WeDevs_Dokan' ) ||
			function_exists( 'dokan' ) ||
			defined( 'DOKAN_PRO_PLUGIN_VERSION' )
		);
		if ( ! $dokan_active ) {
			$this->missing_dependencies[] = sprintf(
				__( 'Dokan Pro %s or higher (not installed / not active)', 'zymarg-cart' ),
				ZYMARG_CART_MIN_DOKAN
			);
		}

		return empty( $this->missing_dependencies );
	}

	private function register_dependency_notices(): void {
		add_action( 'admin_notices', [ $this, 'show_dependency_notice' ] );
	}

	public function show_dependency_notice(): void {
		$list = '<ul style="margin:.4em 0 0 1.2em;list-style:disc">';
		foreach ( $this->missing_dependencies as $dep ) {
			$list .= '<li>' . esc_html( $dep ) . '</li>';
		}
		$list .= '</ul>';

		echo '<div class="notice notice-error"><p>' .
			wp_kses_post( sprintf(
				__( '<strong>ZYMARG Cart</strong> cannot run — missing or outdated dependencies: %s', 'zymarg-cart' ),
				$list
			) ) .
			'</p></div>';
	}

	// ── Textdomain ─────────────────────────────────────────────────────────

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'zymarg-cart',
			false,
			dirname( ZYMARG_CART_BASENAME ) . '/languages'
		);
	}

	// ── Include files ──────────────────────────────────────────────────────

	private function load_includes(): void {
		$files = [
			'includes/class-zymarg-cart-helpers.php',
			'includes/class-zymarg-cart-settings.php',
			'includes/class-zymarg-cart-admin.php',
			'includes/class-zymarg-cart-session.php',
			'includes/class-zymarg-cart-usermeta.php',
			'includes/class-zymarg-cart-merge.php',
			'includes/class-zymarg-cart-partial.php',
			'includes/class-zymarg-cart-dokan.php',
			'includes/class-zymarg-cart-ajax.php',
			'includes/class-zymarg-cart-page.php',
		];

		foreach ( $files as $relative_path ) {
			$abs = ZYMARG_CART_PATH . $relative_path;
			if ( file_exists( $abs ) ) {
				require_once $abs;
			}
		}
	}

	// ── Hooks ──────────────────────────────────────────────────────────────

	private function register_hooks(): void {

		// Frontend assets — cart page only.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Cart page override.
		Zymarg_Cart_Page::init();

		// Admin panel.
		Zymarg_Cart_Admin::init();

		// Guest → logged-in Save for Later merge.
		Zymarg_Cart_Merge::init();

		// Partial checkout session management.
		Zymarg_Cart_Partial::init();

		// AJAX handlers.
		$this->register_ajax_handlers();

		// Activation notice.
		add_action( 'admin_notices', [ $this, 'show_activation_notice' ] );
	}

	// ── Frontend assets ────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		// Primary check.
		$on_cart = function_exists( 'is_cart' ) && is_cart();

		// Fallback: post ID match for cases where is_cart() returns false
		// too early (e.g. during wp_enqueue_scripts before WC fully loaded).
		if ( ! $on_cart ) {
			global $post;
			$cart_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'cart' ) : 0;
			$on_cart      = $cart_page_id > 0
				&& $post instanceof WP_Post
				&& $post->ID === $cart_page_id;
		}

		if ( ! $on_cart ) {
			return;
		}

		// 0. Shared ZYMARG brand tokens. Must load before everything else
		// that references --zym-* custom properties. Guarded registration
		// so whichever ZYMARG plugin loads first "wins" and every other
		// plugin reuses the same stylesheet (see ZYMARG Design Tokens doc,
		// "THE SHARED FILES" section). Previously this was enqueued only
		// in wp-admin; now also loaded on the front-end cart page so the
		// --zc-* aliases in zymarg-cart-vars.css / zymarg-cart.css resolve.
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_CART_URL . 'assets/css/zymarg-tokens.css',
				[],
				ZYMARG_CART_VERSION
			);
		}
		wp_enqueue_style( 'zymarg-tokens' );

		// 1. CSS custom properties (brand tokens). Must load first.
		// NOTE: Handles are prefixed 'zymarg-cp-' (cart-page) to avoid
		// conflicts with the zymarg-header plugin which already owns
		// the 'zymarg-cart' and 'zymarg-cart-mobile' style/script handles.
		wp_enqueue_style(
			'zymarg-cp-vars',
			ZYMARG_CART_URL . 'assets/css/zymarg-cart-vars.css',
			[ 'zymarg-tokens' ],
			ZYMARG_CART_VERSION
		);

		// 2. Main component styles.
		wp_enqueue_style(
			'zymarg-cp-main',
			ZYMARG_CART_URL . 'assets/css/zymarg-cart.css',
			[ 'zymarg-cp-vars' ],
			ZYMARG_CART_VERSION
		);

		// 3. Mobile overrides.
		wp_enqueue_style(
			'zymarg-cp-mobile',
			ZYMARG_CART_URL . 'assets/css/zymarg-cart-mobile.css',
			[ 'zymarg-cp-main' ],
			ZYMARG_CART_VERSION
		);

		wp_enqueue_script(
			'zymarg-cp-core',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart.js',
			[ 'jquery' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_enqueue_script(
			'zymarg-cp-checkbox',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart-checkbox.js',
			[ 'zymarg-cp-core' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_enqueue_script(
			'zymarg-cp-ajax',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart-ajax.js',
			[ 'zymarg-cp-core' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_enqueue_script(
			'zymarg-cp-breakdown',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart-breakdown.js',
			[ 'zymarg-cp-core' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_enqueue_script(
			'zymarg-cp-sticky',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart-sticky.js',
			[ 'zymarg-cp-core', 'zymarg-cp-breakdown' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_enqueue_script(
			'zymarg-cp-edit-mode',
			ZYMARG_CART_URL . 'assets/js/zymarg-cart-edit-mode.js',
			[ 'zymarg-cp-core' ],
			ZYMARG_CART_VERSION,
			true
		);

		wp_localize_script( 'zymarg-cp-core', 'zymargCartData', $this->build_localized_data() );
	}

	// ── CSS variable injection ─────────────────────────────────────────────

	// ── Localized data for JS ──────────────────────────────────────────────

	private function build_localized_data(): array {
		$cart       = WC()->cart;
		$item_count = $cart instanceof \WC_Cart ? $cart->get_cart_contents_count() : 0;
		$s          = Zymarg_Cart_Settings::get_total();

		return [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'zymarg_cart_nonce' ),
			'itemCount'     => $item_count,
			'currency'      => get_woocommerce_currency_symbol(),
			'currencyPos'   => get_option( 'woocommerce_currency_pos', 'left' ),
			'thousandSep'   => wc_get_price_thousand_separator(),
			'decimalSep'    => wc_get_price_decimal_separator(),
			'numDecimals'   => wc_get_price_decimals(),
			'isLoggedIn'    => is_user_logged_in(),
			'userId'        => get_current_user_id(),
			'hasBackup'     => class_exists( 'Zymarg_Cart_Partial' ) && Zymarg_Cart_Partial::has_backup(),
			'sessionExpiry' => (int) apply_filters( 'zymarg_cart_session_expiry', 7200 ),
			'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'i18n' => [
				'saveForLater'   => __( 'Save for Later',                    'zymarg-cart' ),
				'saved'          => __( 'Saved',                             'zymarg-cart' ),
				'moveToCart'     => __( 'Move to Cart',                      'zymarg-cart' ),
				'remove'         => __( 'Remove',                            'zymarg-cart' ),
				'delete'         => __( 'Delete',                            'zymarg-cart' ),
				'edit'           => __( 'Edit',                              'zymarg-cart' ),
				'done'           => __( 'Done',                              'zymarg-cart' ),
				'apply'          => __( 'Apply',                             'zymarg-cart' ),
				'haveCoupon'     => __( 'Have a coupon?',                    'zymarg-cart' ),
				'couponApplied'  => __( 'Coupon applied',                    'zymarg-cart' ),
				'couponInvalid'  => __( 'Invalid coupon code',               'zymarg-cart' ),
				'couponExpired'  => __( 'Coupon expired',                    'zymarg-cart' ),
				'outOfStock'     => __( 'Out of stock — remove to proceed',  'zymarg-cart' ),
				'lowStock'       => __( 'Only %d left',                      'zymarg-cart' ),
				'continueShop'   => __( 'Continue Shopping',                 'zymarg-cart' ),
				'emptyCart'      => __( 'Your cart is empty',                'zymarg-cart' ),
				'emptyMessage'   => __( "Looks like you haven't added anything yet.", 'zymarg-cart' ),
				'checkout'       => __( 'Proceed to Checkout',               'zymarg-cart' ),
				'confirmDelete'  => $s['confirm_dialog_text'] ?? __( 'Are you sure you want to remove the selected items?', 'zymarg-cart' ),
				'selectAll'      => __( 'Select All',                        'zymarg-cart' ),
				'selectedOf'     => __( '%1$d of %2$d selected',             'zymarg-cart' ),
				'calculating'    => __( 'Calculating…',                      'zymarg-cart' ),
				'updating'       => __( 'Updating…',                         'zymarg-cart' ),
				'restoring'      => __( 'Restoring your cart…',              'zymarg-cart' ),
				'calcAtCheckout' => __( 'Calculated at checkout',            'zymarg-cart' ),
				'priceChanged'   => __( 'Price changed',                     'zymarg-cart' ),
				'savedItems'     => __( 'Saved for Later',                   'zymarg-cart' ),
				'noSavedItems'   => __( 'No saved items',                    'zymarg-cart' ),
				'error'          => __( 'Something went wrong. Please try again.', 'zymarg-cart' ),
				'visitStore'     => __( 'Visit store',                       'zymarg-cart' ),
				'subtotal'       => __( 'Subtotal',                          'zymarg-cart' ),
				'discount'       => __( 'Discount',                          'zymarg-cart' ),
				'shipping'       => __( 'Shipping',                          'zymarg-cart' ),
				'tax'            => (string) apply_filters(
					'zymarg_cart_tax_label',
					$s['tax_label'] ?? __( 'VAT', 'zymarg-cart' )
				),
				'grandTotal'     => __( 'Grand Total',                       'zymarg-cart' ),
				'orderSummary'   => $s['order_summary_text'] ?? __( 'Order Summary', 'zymarg-cart' ),
			],
		];
	}

	// ── AJAX handler registration ──────────────────────────────────────────

	private function register_ajax_handlers(): void {
		$actions = [
			'zymarg_update_quantity',
			'zymarg_change_variation',
			'zymarg_remove_item',
			'zymarg_apply_coupon',
			'zymarg_remove_coupon',
			'zymarg_save_for_later',
			'zymarg_move_to_cart',
			'zymarg_remove_saved',
			'zymarg_get_totals',
			'zymarg_partial_checkout',
			'zymarg_restore_cart',
		];

		foreach ( $actions as $action ) {
			$handler = [ 'Zymarg_Cart_Ajax', 'handle_' . $action ];
			add_action( 'wp_ajax_' . $action,        $handler );
			add_action( 'wp_ajax_nopriv_' . $action, $handler );
		}
	}

	// ── HPOS compatibility ─────────────────────────────────────────────────

	public function declare_hpos_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				ZYMARG_CART_FILE,
				true
			);
		}
	}

	// ── Activation notice ──────────────────────────────────────────────────

	public function show_activation_notice(): void {
		if ( ! get_transient( 'zymarg_cart_activated' ) ) {
			return;
		}
		delete_transient( 'zymarg_cart_activated' );
		echo '<div class="notice notice-success is-dismissible"><p>' .
			wp_kses_post( sprintf(
				__( '<strong>%s</strong> activated. Go to <a href="%s">ZYMARG Cart → Settings</a> to configure your cart page.', 'zymarg-cart' ),
				'ZYMARG Cart v' . ZYMARG_CART_VERSION,
				admin_url( 'admin.php?page=zymarg-cart-settings' )
			) ) .
			'</p></div>';
	}

	// ── Activation / deactivation ──────────────────────────────────────────

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, ZYMARG_CART_MIN_PHP, '<' ) ) {
			deactivate_plugins( ZYMARG_CART_BASENAME );
			wp_die(
				wp_kses_post( sprintf(
					__( '<strong>ZYMARG Cart</strong> requires PHP <strong>%1$s</strong> or higher. Your server is running PHP <strong>%2$s</strong>.', 'zymarg-cart' ),
					ZYMARG_CART_MIN_PHP,
					PHP_VERSION
				) ),
				esc_html__( 'Plugin activation failed', 'zymarg-cart' ),
				[ 'back_link' => true ]
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, ZYMARG_CART_MIN_WP, '<' ) ) {
			deactivate_plugins( ZYMARG_CART_BASENAME );
			wp_die(
				wp_kses_post( sprintf(
					__( '<strong>ZYMARG Cart</strong> requires WordPress <strong>%1$s</strong> or higher.', 'zymarg-cart' ),
					ZYMARG_CART_MIN_WP
				) ),
				esc_html__( 'Plugin activation failed', 'zymarg-cart' ),
				[ 'back_link' => true ]
			);
		}

		set_transient( 'zymarg_cart_activated', true, MINUTE_IN_SECONDS * 5 );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		delete_transient( 'zymarg_cart_activated' );
		flush_rewrite_rules();
	}
}
