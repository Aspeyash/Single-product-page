<?php
/**
 * Settings helper — thin wrapper around get_option / update_option.
 *
 * All options live under a single serialised array key: `zymarg_header_settings`.
 *
 * v1.1.0: Added full cart settings (content + style). These replace the
 * previous Theme Builder dependency for the mini-cart panel.
 *
 * @package ZymargHeader
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY = 'zymarg_header_settings';

	/**
	 * Default values — used as fallback when a key is not yet saved.
	 *
	 * Cart content defaults match the Theme Builder Cart widget defaults exactly
	 * so migrating from TB → Header plugin is zero-config.
	 */
	private static array $defaults = array(

		// ── Logo ──────────────────────────────────────────────────────
		'logo_image_id'              => 0,
		'logo_url'                   => '/',
		'logo_alt'                   => 'ZYMARG',

		// ── Top bar ───────────────────────────────────────────────────
		'seller_url'                 => '/become-a-seller/',
		'seller_label'               => 'Become a Seller',
		'login_url'                  => '',
		'register_url'               => '',

		// ── Header bar ────────────────────────────────────────────────
		'account_url'                => '',
		'wishlist_url'               => '/wishlist/',
		'cart_url'                   => '',

		// ── Cart: display ─────────────────────────────────────────────

		'cart_text'                  => 'Cart',
		'cart_show_badge'            => '1',           // 1 | 0
		'cart_count_type'            => 'total_qty',   // total_qty | unique
		'cart_show_zero'             => '0',           // 1 | 0  (show badge when count is 0)
		'cart_show_subtotal'         => '0',           // 1 | 0

		// ── Cart: click action ────────────────────────────────────────
		'cart_click_action'          => 'dropdown',    // dropdown | offcanvas | popup | cart | checkout
		'cart_offcanvas_position'    => 'right',       // right | left | bottom

		// ── Cart: icon ────────────────────────────────────────────────
		'cart_badge_position'        => 'top-right',   // top-right | top-left | bottom-right | bottom-left

		// ── Cart: mini-cart panel ─────────────────────────────────────
		'cart_panel_title'           => 'Your Cart',
		'cart_dropdown_width'        => '380',         // px
		'cart_items_max_height'      => '320',         // px
		'cart_show_product_image'    => '1',
		'cart_show_sku'              => '0',
		'cart_show_attributes'       => '1',
		'cart_allow_qty_update'      => '1',
		'cart_allow_remove'          => '1',
		'cart_show_view_cart'        => '1',
		'cart_view_cart_text'        => 'View Cart',
		'cart_show_checkout'         => '1',
		'cart_checkout_text'         => 'Checkout',
		'cart_show_continue'         => '0',
		'cart_continue_text'         => 'Continue Shopping',
		'cart_continue_url'          => '',

		// ── Cart: empty state ─────────────────────────────────────────
		'cart_empty_title'           => 'Your cart is empty',
		'cart_empty_text'            => 'Looks like you have not added anything yet.',
		'cart_empty_button_text'     => 'Start Shopping',
		'cart_empty_button_url'      => '',

		// ── Display Conditions ─────────────────────────────────────
		'display_mode'               => 'everywhere',  // everywhere | show_on | hide_on
		'display_rules_json'         => '[]',           // JSON array of rule objects
		// ── Wishlist ────────────────────────────────────────────────
		'wishlist_badge_animation'   => 'none',        // none | bounce | pulse | shake | scale | fade
		// ── Cart: animation ───────────────────────────────────────────
		'cart_badge_animation'       => 'none',        // none | bounce | pulse | shake | scale | fade
		'cart_open_animation'        => 'slide',       // fade | slide | zoom | scale

		// ── Cart: mobile ──────────────────────────────────────────────
		'cart_mobile_bottom_sheet'   => '1',

		// ── Cart style: trigger ───────────────────────────────────────
		'cart_icon_hover_color'      => '#9500a5',
		'cart_trigger_gap'           => '2',           // px — matches Account / Wishlist gap @since 1.1.11
		'cart_trigger_padding_top'   => '0',
		'cart_trigger_padding_right' => '0',
		'cart_trigger_padding_bottom'=> '0',
		'cart_trigger_padding_left'  => '0',
		'cart_trigger_bg'            => '',
		'cart_trigger_radius'        => '0',           // px

		// ── Cart style: badge ─────────────────────────────────────────
		'cart_badge_bg'              => 'linear-gradient(135deg,#9500a5 0%,#bd00d1 100%)', // matches wishlist badge @since 1.1.12
		'cart_badge_color'           => '#ffffff',
		'cart_badge_size'            => '17',          // px — matches wishlist badge @since 1.1.12
		'cart_badge_font_size'       => '9.5',         // px — matches wishlist badge @since 1.1.12
		'cart_badge_radius'          => '20',          // px
		'cart_badge_offset_x'        => '-8',          // px (can be negative)
		'cart_badge_offset_y'        => '-6',          // px (can be negative)

		// ── Cart style: text & subtotal ───────────────────────────────
		'cart_text_color'            => '#131b2e',
		'cart_subtotal_color'        => '#9500a5',

		// ── Cart style: panel ─────────────────────────────────────────
		'cart_panel_bg'              => '#ffffff',
		'cart_panel_title_color'     => '#131b2e',
		'cart_panel_border_color'    => '',
		'cart_panel_border_width'    => '0',           // px
		'cart_panel_radius'          => '12',          // px
		'cart_panel_shadow'          => '0 8px 40px rgba(0,0,0,0.12)',
		'cart_scrollbar_color'       => '#d8bfd3',

		// ── Cart style: product rows ──────────────────────────────────
		'cart_item_title_color'      => '#131b2e',
		'cart_item_price_color'      => '#9500a5',
		'cart_item_divider_color'    => '#eaedff',
		'cart_qty_color'             => '#9500a5',

		// ── Cart style: footer buttons ────────────────────────────────
		'cart_checkout_bg'           => '#9500a5',
		'cart_checkout_color'        => '#ffd6fb',
		'cart_checkout_hover_bg'     => '#bd00d1',
		'cart_viewcart_color'        => '#9500a5',
		'cart_btn_radius'            => '10',          // px

		// ── Cart style: empty state ───────────────────────────────────
		'cart_empty_icon_color'      => '#857183',
		'cart_empty_title_color'     => '#131b2e',
		'cart_empty_text_color'      => '#534152',
	);

	/* -------------------------------------------------------------------
	 * Core get / save
	 * ----------------------------------------------------------------- */

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback (uses class default if not supplied).
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = get_option( self::OPTION_KEY, array() );
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		return self::$defaults[ $key ] ?? '';
	}

	/**
	 * Save a batch of settings (merged with existing).
	 *
	 * @param array $data Key => value pairs.
	 */
	public static function save( array $data ): void {
		$all = get_option( self::OPTION_KEY, array() );
		$all = array_merge( $all, $data );
		update_option( self::OPTION_KEY, $all, false );
	}

	/* -------------------------------------------------------------------
	 * Resolved URL helpers
	 * ----------------------------------------------------------------- */

	public static function login_url(): string {
		$url = (string) self::get( 'login_url' );
		return '' !== $url ? esc_url( $url ) : ( function_exists( 'wp_login_url' ) ? wp_login_url() : '/wp-login.php' );
	}

	public static function register_url(): string {
		$url = (string) self::get( 'register_url' );
		return '' !== $url ? esc_url( $url ) : ( function_exists( 'wp_registration_url' ) ? wp_registration_url() : '/wp-login.php?action=register' );
	}

	public static function account_url(): string {
		$url = (string) self::get( 'account_url' );
		if ( '' !== $url ) {
			return esc_url( $url );
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'dashboard' );
		}
		return self::login_url();
	}

	public static function cart_url(): string {
		$url = (string) self::get( 'cart_url' );
		if ( '' !== $url ) {
			return esc_url( $url );
		}
		if ( function_exists( 'wc_get_cart_url' ) ) {
			return wc_get_cart_url();
		}
		return '/cart/';
	}

	/* -------------------------------------------------------------------
	 * Cart-specific helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Whether a boolean cart setting is enabled.
	 * Stored as '1' / '0' in the options array.
	 *
	 * @param string $key Setting key.
	 * @param bool   $default_true Default if key not found.
	 * @return bool
	 */
	public static function cart_bool( string $key, bool $default_true = true ): bool {
		$val = self::get( $key, $default_true ? '1' : '0' );
		return '1' === (string) $val;
	}

	/**
	 * Whether the click action should render a panel (dropdown / offcanvas / popup).
	 */
	public static function cart_has_panel(): bool {
		return in_array(
			self::get( 'cart_click_action', 'dropdown' ),
			array( 'dropdown', 'offcanvas', 'popup' ),
			true
		);
	}

	/**
	 * Generate the inline <style> block for the cart widget based on
	 * the admin-configured style settings. Scoped to .z-hdr-cart-root so
	 * it never affects Theme Builder Cart widgets elsewhere on the page.
	 *
	 * Returns raw CSS — added via wp_add_inline_style( 'zymarg-cart', … ).
	 */
	public static function cart_inline_css(): string {
		$s   = '.z-hdr-cart-root';
		$css = '';

		/* Icon & trigger — size and base colour are inherited from the
		 * shared static rule .z-hdr-action__icon svg (zymarg-header.css),
		 * keeping the cart icon visually consistent with Account/Wishlist.
		 * Only the hover colour is set here so the admin can still adjust it.
		 */
		$css .= "{$s} .zymarg-tb-cart__trigger:hover .zymarg-tb-cart__icon,{$s} .zymarg-tb-cart__trigger:focus-visible .zymarg-tb-cart__icon{color:" . self::get( 'cart_icon_hover_color', '#9500a5' ) . "}\n";
		$gap = (int) self::get( 'cart_trigger_gap', '2' );
		$css .= "{$s} .zymarg-tb-cart__trigger{gap:{$gap}px}\n";
		$pt  = (int) self::get( 'cart_trigger_padding_top', '0' );
		$pr  = (int) self::get( 'cart_trigger_padding_right', '0' );
		$pb  = (int) self::get( 'cart_trigger_padding_bottom', '0' );
		$pl  = (int) self::get( 'cart_trigger_padding_left', '0' );
		if ( $pt || $pr || $pb || $pl ) {
			$css .= "{$s} .zymarg-tb-cart__trigger{padding:{$pt}px {$pr}px {$pb}px {$pl}px}\n";
		}
		$tbg = (string) self::get( 'cart_trigger_bg', '' );
		if ( '' !== $tbg ) {
			$css .= "{$s} .zymarg-tb-cart__trigger{background:" . $tbg . "}\n";
		}
		$tr = (int) self::get( 'cart_trigger_radius', '0' );
		if ( $tr ) {
			$css .= "{$s} .zymarg-tb-cart__trigger{border-radius:{$tr}px}\n";
		}

		/* Badge */
		$b_size    = (float) self::get( 'cart_badge_size', '17' );
		$b_font    = (float) self::get( 'cart_badge_font_size', '9.5' );
		$b_radius  = (int) self::get( 'cart_badge_radius', '20' );
		$b_offset_x = (int) self::get( 'cart_badge_offset_x', '-8' );
		$b_offset_y = (int) self::get( 'cart_badge_offset_y', '-6' );
		$css .= "{$s} .zymarg-tb-cart__count{background:" . self::get( 'cart_badge_bg', 'linear-gradient(135deg,#9500a5 0%,#bd00d1 100%)' ) . ";color:" . self::get( 'cart_badge_color', '#ffffff' ) . ";min-width:{$b_size}px;height:{$b_size}px;font-size:{$b_font}px;border-radius:{$b_radius}px;--zc-badge-x:{$b_offset_x}px;--zc-badge-y:{$b_offset_y}px}\n";

		/* Text & subtotal */
		$css .= "{$s} .zymarg-tb-cart__text{color:" . self::get( 'cart_text_color', '#131b2e' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__subtotal{color:" . self::get( 'cart_subtotal_color', '#9500a5' ) . "}\n";

		/* Panel */
		$css .= "{$s} .zymarg-tb-cart__panel{background:" . self::get( 'cart_panel_bg', '#ffffff' ) . ";border-radius:" . (int) self::get( 'cart_panel_radius', '12' ) . "px}\n";
		$css .= "{$s} .zymarg-tb-cart__panel-title{color:" . self::get( 'cart_panel_title_color', '#131b2e' ) . "}\n";
		$shadow = (string) self::get( 'cart_panel_shadow', '0 8px 40px rgba(0,0,0,0.12)' );
		if ( '' !== $shadow ) {
			$css .= "{$s} .zymarg-tb-cart__panel{box-shadow:{$shadow}}\n";
		}
		$b_color = (string) self::get( 'cart_panel_border_color', '' );
		$b_width = (int) self::get( 'cart_panel_border_width', '0' );
		if ( '' !== $b_color && $b_width > 0 ) {
			$css .= "{$s} .zymarg-tb-cart__panel{border:{$b_width}px solid {$b_color}}\n";
		}
		$scroll = (string) self::get( 'cart_scrollbar_color', '#d8bfd3' );
		if ( '' !== $scroll ) {
			$css .= "{$s} .zymarg-tb-cart__items{--zc-scroll:{$scroll}}\n";
		}
		$dw = (int) self::get( 'cart_dropdown_width', '380' );
		if ( $dw ) {
			$css .= "{$s} .zymarg-tb-cart__panel--dropdown{width:{$dw}px}\n";
		}
		$mh = (int) self::get( 'cart_items_max_height', '320' );
		if ( $mh ) {
			$css .= "{$s} .zymarg-tb-cart__items{max-height:{$mh}px}\n";
		}

		/* Product rows */
		$css .= "{$s} .zymarg-tb-cart__item-title{color:" . self::get( 'cart_item_title_color', '#131b2e' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__item-price{color:" . self::get( 'cart_item_price_color', '#9500a5' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__item{border-bottom-color:" . self::get( 'cart_item_divider_color', '#eaedff' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__qty-btn{color:" . self::get( 'cart_qty_color', '#9500a5' ) . "}\n";

		/* Footer buttons */
		$btn_r = (int) self::get( 'cart_btn_radius', '10' );
		$css .= "{$s} .zymarg-tb-cart__btn{border-radius:{$btn_r}px}\n";
		$css .= "{$s} .zymarg-tb-cart__btn--primary{background:" . self::get( 'cart_checkout_bg', '#9500a5' ) . ";color:" . self::get( 'cart_checkout_color', '#ffd6fb' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__btn--primary:hover,{$s} .zymarg-tb-cart__btn--primary:focus-visible{background:" . self::get( 'cart_checkout_hover_bg', '#bd00d1' ) . "}\n";
		$vc_color = self::get( 'cart_viewcart_color', '#9500a5' );
		$css .= "{$s} .zymarg-tb-cart__btn--outline{color:{$vc_color};border-color:{$vc_color}}\n";

		/* Empty state */
		$css .= "{$s} .zymarg-tb-cart__empty-icon{color:" . self::get( 'cart_empty_icon_color', '#857183' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__empty-title{color:" . self::get( 'cart_empty_title_color', '#131b2e' ) . "}\n";
		$css .= "{$s} .zymarg-tb-cart__empty-text{color:" . self::get( 'cart_empty_text_color', '#534152' ) . "}\n";

		return $css;
	}
}
