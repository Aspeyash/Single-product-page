<?php
/**
 * Settings helper — reads all plugin options and provides typed defaults.
 *
 * All settings are stored as a single WordPress option under the key
 * 'zymarg_cart_settings'. Sub-keys match the HTML field names in the admin.
 *
 * Three public getters return the relevant section as an array:
 *   Zymarg_Cart_Settings::get_header()  → Header tab values
 *   Zymarg_Cart_Settings::get_body()    → Body tab values
 *   Zymarg_Cart_Settings::get_total()   → Total tab values
 *   Zymarg_Cart_Settings::all()         → Everything merged
 *
 * Templates call these getters and treat the result exactly as they
 * previously treated Elementor's $settings array — so the key names
 * match the old Elementor control IDs wherever possible, keeping the
 * template diffs minimal.
 *
 * @package ZymargCart
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Settings {

	/** WordPress option key. */
	const OPTION_KEY = 'zymarg_cart_settings';

	/** Cached option value for the current request. */
	private static ?array $cache = null;

	// ── Public getters ─────────────────────────────────────────────────────

	public static function all(): array {
		return self::load();
	}

	public static function get_header(): array {
		return self::load()['header'] ?? [];
	}

	public static function get_body(): array {
		return self::load()['body'] ?? [];
	}

	public static function get_total(): array {
		return self::load()['total'] ?? [];
	}

	// ── Internal loader ────────────────────────────────────────────────────

	private static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		self::$cache = array_replace_recursive( self::defaults(), $stored );
		return self::$cache;
	}

	/** Clears the in-memory cache (call after saving). */
	public static function flush(): void {
		self::$cache = null;
	}

	// ── Defaults ───────────────────────────────────────────────────────────

	/**
	 * Returns the full defaults array.
	 * Every key here maps to a field in the admin settings page.
	 * 'yes' / 'no' strings match the convention used in the original
	 * Elementor controls so templates require zero changes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return [

			// ── Header tab ────────────────────────────────────────────────
			'header' => [
				// Visibility
				'show_cart_icon'       => 'yes',
				'show_cart_title'      => 'yes',
				'show_item_count'      => 'yes',
				'show_edit_btn'        => 'yes',
				'show_delete_btn'      => 'yes',
				// Labels
				'cart_title'           => '',   // Empty = default "My Cart"
				'edit_btn_label'       => '',   // Empty = "Edit"
				'done_btn_label'       => '',   // Empty = "Done"
				'delete_btn_label'     => '',   // Empty = "Delete"
				// Behaviour
				'edit_confirm_dialog'  => 'yes',
				'confirm_dialog_text'  => '',   // Empty = default message
			],

			// ── Body tab ─────────────────────────────────────────────────
			'body' => [
				// Vendor row
				'show_vendor_checkbox' => 'yes',
				'show_vendor_link'     => 'yes',
				'show_vendor_arrow'    => 'yes',
				'show_table_headers'   => 'yes',
				'show_vendor_subtotal' => 'yes',
				'vendor_icon_type'     => 'vendor_profile', // 'vendor_profile' | 'static_icon'
				'vendor_static_icon'   => 'building-store',
				// Product row
				'show_product_checkbox'   => 'yes',
				'show_product_image'      => 'yes',
				'show_product_sku'        => 'yes',
				'show_stock_warning'      => 'yes',
				'show_product_price'      => 'yes',
				'show_variation_dropdown' => 'yes',
				'show_qty_stepper'        => 'yes',
				'show_coupon_field'       => 'yes',
				'show_save_later_btn'     => 'yes',
				'show_save_later_icon'    => 'yes',
				// Labels
				'have_coupon_text'        => '',  // Empty = "Have a coupon?"
				'save_later_label'        => '',  // Empty = "Save for later"
				'move_to_cart_label'      => '',  // Empty = "Move to Cart"
				'saved_section_title'     => '',  // Empty = "Saved for Later"
				'empty_cart_message'      => '',  // Empty = "Your cart is empty."
				'continue_shopping_text'  => '',  // Empty = "Continue Shopping"
				'continue_shopping_url'   => [ 'url' => '', 'is_external' => false, 'nofollow' => false ],
				// Save for Later
				'save_later_enabled'      => 'yes',
				'show_saved_below_cart'   => 'yes',
				'show_saved_count_badge'  => 'yes',
				'show_move_to_cart_btn'   => 'yes',
				'show_remove_saved_btn'   => 'yes',
				'show_price_changed'      => 'yes',
				'save_later_max'          => 10,
				// Empty cart illustration
				'show_empty_cart_illus'   => 'yes',
				// Behaviour
				'mobile_breakpoint'       => 480,
			],

			// ── Total tab ─────────────────────────────────────────────────
			'total' => [
				// Subtotal bar
				'show_subtotal_bar'          => 'yes',
				'show_selected_count'        => 'yes',
				// Breakdown panel lines
				'show_subtotal_line'         => 'yes',
				'show_discount_line'         => 'yes',
				'show_shipping_line'         => 'yes',
				'show_shipping_per_vendor'   => 'yes',
				'show_tax_line'              => 'no',   // Hidden by default (VAT-inclusive)
				// Action bar
				'show_master_cb'             => 'yes',
				'show_select_label'          => 'yes',
				'show_action_grand'          => 'yes',
				'show_action_grand_label'    => 'yes',
				'show_checkout_btn'          => 'yes',
				'show_checkout_icon'         => 'yes',
				// Labels
				'order_summary_text'         => '',   // Empty = "Order Summary"
				'tax_label'                  => '',   // Empty = filter default "VAT"
				'grand_total_label_text'     => '',   // Empty = "Grand Total"
				'checkout_btn_text'          => '',   // Empty = "Proceed to Checkout"
				// Sticky
				'sticky_desktop'             => 'no',
				'sticky_tablet'              => 'no',
				'sticky_mobile'              => 'yes',
				// Animation
				'animate_breakdown'          => 'yes',
				'animation_speed'            => [ 'size' => 300 ],
				'auto_expand_on_select'      => 'yes',
				// Footer note
				'popup_final_note_show'      => 'yes',
				'popup_final_note_text'      => '',   // Empty = "Final price displayed at checkout"
				// Checkout behaviour
				'checkout_btn_loading'       => 'yes',
				// Confirm dialog text (also used in header for delete)
				'confirm_dialog_text'        => '',
			],
		];
	}

	// ── Sanitizer (used by admin save) ────────────────────────────────────

	/**
	 * Sanitizes raw POST data from the settings form.
	 *
	 * @param array<string, mixed> $raw Raw $_POST data.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( array $raw ): array {
		$defaults = self::defaults();
		$clean    = [];

		// ── Header ────────────────────────────────────────────────────────
		$h = $raw['header'] ?? [];
		$clean['header'] = [
			'show_cart_icon'      => ( $h['show_cart_icon']      ?? '' ) === '1' ? 'yes' : 'no',
			'show_cart_title'     => ( $h['show_cart_title']     ?? '' ) === '1' ? 'yes' : 'no',
			'show_item_count'     => ( $h['show_item_count']     ?? '' ) === '1' ? 'yes' : 'no',
			'show_edit_btn'       => ( $h['show_edit_btn']       ?? '' ) === '1' ? 'yes' : 'no',
			'show_delete_btn'     => ( $h['show_delete_btn']     ?? '' ) === '1' ? 'yes' : 'no',
			'cart_title'          => sanitize_text_field( $h['cart_title']          ?? '' ),
			'edit_btn_label'      => sanitize_text_field( $h['edit_btn_label']      ?? '' ),
			'done_btn_label'      => sanitize_text_field( $h['done_btn_label']      ?? '' ),
			'delete_btn_label'    => sanitize_text_field( $h['delete_btn_label']    ?? '' ),
			'edit_confirm_dialog' => ( $h['edit_confirm_dialog'] ?? '' ) === '1' ? 'yes' : 'no',
			'confirm_dialog_text' => sanitize_text_field( $h['confirm_dialog_text'] ?? '' ),
		];

		// ── Body ──────────────────────────────────────────────────────────
		$b = $raw['body'] ?? [];
		$toggles_b = [
			'show_vendor_checkbox', 'show_vendor_link', 'show_vendor_arrow',
			'show_table_headers', 'show_vendor_subtotal',
			'show_product_checkbox', 'show_product_image', 'show_product_sku',
			'show_stock_warning', 'show_product_price', 'show_variation_dropdown',
			'show_qty_stepper', 'show_coupon_field', 'show_save_later_btn',
			'show_save_later_icon', 'save_later_enabled', 'show_saved_below_cart',
			'show_saved_count_badge', 'show_move_to_cart_btn', 'show_remove_saved_btn',
			'show_price_changed', 'show_empty_cart_illus',
		];
		$text_b = [
			'have_coupon_text', 'save_later_label', 'move_to_cart_label',
			'saved_section_title', 'empty_cart_message', 'continue_shopping_text',
			'vendor_icon_type', 'vendor_static_icon',
		];
		$clean['body'] = [];
		foreach ( $toggles_b as $k ) {
			$clean['body'][ $k ] = ( $b[ $k ] ?? '' ) === '1' ? 'yes' : 'no';
		}
		foreach ( $text_b as $k ) {
			$clean['body'][ $k ] = sanitize_text_field( $b[ $k ] ?? '' );
		}
		$clean['body']['mobile_breakpoint'] = max( 320, min( 1200, (int) ( $b['mobile_breakpoint'] ?? 480 ) ) );
		$clean['body']['save_later_max']    = max( 1, min( 50, (int) ( $b['save_later_max'] ?? 10 ) ) );
		$clean['body']['continue_shopping_url'] = [
			'url'         => esc_url_raw( $b['continue_shopping_url'] ?? '' ),
			'is_external' => ! empty( $b['continue_shopping_external'] ),
			'nofollow'    => ! empty( $b['continue_shopping_nofollow'] ),
		];

		// ── Total ─────────────────────────────────────────────────────────
		$t = $raw['total'] ?? [];
		$toggles_t = [
			'show_subtotal_bar', 'show_selected_count', 'show_subtotal_line',
			'show_discount_line', 'show_shipping_line', 'show_shipping_per_vendor',
			'show_tax_line', 'show_master_cb', 'show_select_label',
			'show_action_grand', 'show_action_grand_label', 'show_checkout_btn',
			'show_checkout_icon', 'sticky_desktop', 'sticky_tablet', 'sticky_mobile',
			'animate_breakdown', 'auto_expand_on_select', 'popup_final_note_show',
			'checkout_btn_loading',
		];
		$text_t = [
			'order_summary_text', 'tax_label', 'grand_total_label_text',
			'checkout_btn_text', 'popup_final_note_text', 'confirm_dialog_text',
		];
		$clean['total'] = [];
		foreach ( $toggles_t as $k ) {
			$clean['total'][ $k ] = ( $t[ $k ] ?? '' ) === '1' ? 'yes' : 'no';
		}
		foreach ( $text_t as $k ) {
			$clean['total'][ $k ] = sanitize_text_field( $t[ $k ] ?? '' );
		}
		$clean['total']['animation_speed'] = [ 'size' => max( 100, min( 2000, (int) ( $t['animation_speed'] ?? 300 ) ) ) ];

		return $clean;
	}
}
