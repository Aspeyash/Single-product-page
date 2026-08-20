<?php
/**
 * Admin settings page.
 *
 * v1.1.0:  Full cart settings added — covers every control that existed in
 *          the Theme Builder Cart Elementor widget, now configured here instead.
 * v1.1.20: ZYMARG Suite integration — registers as a subpage under the ZYMARG
 *          Suite hub when active; falls back to a standalone top-level menu when
 *          the hub is inactive or not installed.
 * v1.1.21: Fully AJAX — form save and tab switching no longer reload the page.
 *          Success/error toast appears in-page; current tab is preserved.
 *
 * @package ZymargHeader
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private static ?Admin $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		add_action( 'admin_menu',            array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_branding' ) );
		add_action( 'wp_ajax_zymarg_header_save', array( $this, 'ajax_save' ) );
		add_filter( 'zymarg_suite_register_plugin', array( $this, 'register_with_suite' ) );
	}

	/**
	 * Sidebar parent-menu branding (Design Tokens v3 section 2.16).
	 *
	 * Runs on every admin page. Scoped to #toplevel_page_zymarg-header,
	 * which only exists when this plugin registers a standalone top-level
	 * menu (i.e. when the ZYMARG Suite is inactive). When Suite is active
	 * and Header registers as a submenu under it, the ID selector has no
	 * match and this stylesheet is inert -- exactly the right behaviour.
	 *
	 * @return void
	 */
	public function enqueue_menu_branding(): void {
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_HEADER_URL . 'assets/css/zymarg-tokens.css',
				array(),
				ZYMARG_HEADER_VERSION
			);
		}
		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style(
			'zymarg-header-menu',
			ZYMARG_HEADER_URL . 'assets/css/zymarg-header-menu.css',
			array( 'zymarg-tokens' ),
			ZYMARG_HEADER_VERSION
		);
	}

	/**
	 * Register this plugin with the ZYMARG Suite dashboard.
	 * Provides the settings URL and icon for the plugin card.
	 *
	 * @param array $plugins Plugins registered with the suite.
	 * @return array
	 */
	public function register_with_suite( array $plugins ): array {
		$plugins[ plugin_basename( ZYMARG_HEADER_FILE ) ] = array(
			'settings_url' => admin_url( 'admin.php?page=zymarg-header' ),
			'icon'         => 'dashicons-align-center',
		);
		return $plugins;
	}

	/* ── Menu ──────────────────────────────────────────────────── */

	public function add_menu(): void {
		if ( function_exists( 'zymarg_hub_menu_slug' ) ) {
			// ZYMARG Suite is active — register as a subpage under the hub.
			add_submenu_page(
				zymarg_hub_menu_slug(),
				__( 'ZYMARG Header', 'zymarg-header' ),
				__( 'Header', 'zymarg-header' ),
				'manage_options',
				'zymarg-header',
				array( $this, 'render_page' )
			);
		} else {
			// ZYMARG Suite is inactive — fall back to a standalone top-level menu.
			add_menu_page(
				__( 'ZYMARG Header', 'zymarg-header' ),
				__( 'ZYMARG Header', 'zymarg-header' ),
				'manage_options',
				'zymarg-header',
				array( $this, 'render_page' ),
				'dashicons-align-center',
				58
			);
		}
	}

	/* ── AJAX save (primary path — no page reload) ────────────── */

	/**
	 * Handles the AJAX form save.
	 * Receives the entire serialized form as `$_POST['form_data']`,
	 * parses it, sanitizes, saves, and returns JSON.
	 *
	 * @since 1.1.21
	 */
	public function ajax_save(): void {
		if ( ! check_ajax_referer( 'zymarg_header_ajax_save', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'zymarg-header' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'zymarg-header' ) ), 403 );
		}

		// form_data is a URL-encoded string from jQuery's .serialize()
		// parse_str correctly handles array notation: option_key[field]=value
		$raw_string = isset( $_POST['form_data'] ) ? wp_unslash( $_POST['form_data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		parse_str( $raw_string, $parsed );

		$raw  = isset( $parsed[ Settings::OPTION_KEY ] ) ? $parsed[ Settings::OPTION_KEY ] : array();
		$data = $this->sanitize( is_array( $raw ) ? $raw : array() );
		update_option( Settings::OPTION_KEY, $data );

		wp_send_json_success( array( 'message' => esc_html__( 'Settings saved.', 'zymarg-header' ) ) );
	}

	/* ── Settings save (POST fallback — non-JS environments) ──── */

	/**
	 * Hooked to admin_init. Processes the form POST before the page renders.
	 * Uses a custom nonce instead of the WordPress Settings API so there are
	 * no dependency issues with options.php, allowed_options, or capability
	 * checks outside our control.
	 *
	 * @since 1.1.14
	 */
	public function register_settings(): void {
		if ( ! isset( $_POST['_zymarg_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zymarg_nonce'] ) ), 'zymarg_header_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'zymarg-header' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'zymarg-header' ) );
		}

		$raw  = isset( $_POST[ Settings::OPTION_KEY ] ) ? wp_unslash( $_POST[ Settings::OPTION_KEY ] ) : array();
		$data = $this->sanitize( is_array( $raw ) ? $raw : array() );
		update_option( Settings::OPTION_KEY, $data );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'zymarg-header', 'zymarg_saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ── Sanitize ──────────────────────────────────────────────── */

	public function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$bool = static function ( $v ) { return isset( $v ) ? '1' : '0'; };
		$int  = static function ( $v, $default ) { return (string) (int) ( $v ?? $default ); };
		$color = static function ( $v, $default ) {
			$v = sanitize_text_field( $v ?? '' );
			return '' !== $v ? $v : $default;
		};
		$text = static function ( $v, $default = '' ) {
			return sanitize_text_field( $v ?? $default );
		};
		$url = static function ( $v ) {
			return esc_url_raw( $v ?? '' );
		};
		$select = static function ( $v, array $allowed, $default ) {
			return in_array( $v, $allowed, true ) ? $v : $default;
		};

		return array(
			// Logo
			'logo_image_id'               => absint( $input['logo_image_id'] ?? 0 ),
			'logo_url'                    => $url( $input['logo_url'] ?? '/' ),
			'logo_alt'                    => $text( $input['logo_alt'] ?? 'ZYMARG' ),
			// Top bar
			'seller_url'                  => $url( $input['seller_url'] ?? '/become-a-seller/' ),
			'seller_label'                => $text( $input['seller_label'] ?? 'Become a Seller' ),
			'login_url'                   => $url( $input['login_url'] ?? '' ),
			'register_url'                => $url( $input['register_url'] ?? '' ),
			// Header bar
			'account_url'                 => $url( $input['account_url'] ?? '' ),
			'wishlist_url'                => $url( $input['wishlist_url'] ?? '/wishlist/' ),
			'cart_url'                    => $url( $input['cart_url'] ?? '' ),

			// Cart: display

			'cart_text'                   => $text( $input['cart_text'] ?? 'Cart' ),
			'cart_show_badge'             => $bool( $input['cart_show_badge'] ?? null ),
			'cart_count_type'             => $select( $input['cart_count_type'] ?? '', array( 'total_qty', 'unique' ), 'total_qty' ),
			'cart_show_zero'              => $bool( $input['cart_show_zero'] ?? null ),
			'cart_show_subtotal'          => $bool( $input['cart_show_subtotal'] ?? null ),

			// Cart: click action
			'cart_click_action'           => $select( $input['cart_click_action'] ?? '', array( 'dropdown', 'offcanvas', 'popup', 'cart', 'checkout' ), 'dropdown' ),
			'cart_offcanvas_position'     => $select( $input['cart_offcanvas_position'] ?? '', array( 'right', 'left', 'bottom' ), 'right' ),

			// Cart: icon
			'cart_badge_position'         => $select( $input['cart_badge_position'] ?? '', array( 'top-right', 'top-left', 'bottom-right', 'bottom-left' ), 'top-right' ),

			// Cart: mini-cart panel
			'cart_panel_title'            => $text( $input['cart_panel_title'] ?? 'Your Cart' ),
			'cart_dropdown_width'         => $int( $input['cart_dropdown_width'] ?? '', 380 ),
			'cart_items_max_height'       => $int( $input['cart_items_max_height'] ?? '', 320 ),
			'cart_show_product_image'     => $bool( $input['cart_show_product_image'] ?? null ),
			'cart_show_sku'               => $bool( $input['cart_show_sku'] ?? null ),
			'cart_show_attributes'        => $bool( $input['cart_show_attributes'] ?? null ),
			'cart_allow_qty_update'       => $bool( $input['cart_allow_qty_update'] ?? null ),
			'cart_allow_remove'           => $bool( $input['cart_allow_remove'] ?? null ),
			'cart_show_view_cart'         => $bool( $input['cart_show_view_cart'] ?? null ),
			'cart_view_cart_text'         => $text( $input['cart_view_cart_text'] ?? 'View Cart' ),
			'cart_show_checkout'          => $bool( $input['cart_show_checkout'] ?? null ),
			'cart_checkout_text'          => $text( $input['cart_checkout_text'] ?? 'Checkout' ),
			'cart_show_continue'          => $bool( $input['cart_show_continue'] ?? null ),
			'cart_continue_text'          => $text( $input['cart_continue_text'] ?? 'Continue Shopping' ),
			'cart_continue_url'           => $url( $input['cart_continue_url'] ?? '' ),

			// Cart: empty state
			'cart_empty_title'            => $text( $input['cart_empty_title'] ?? 'Your cart is empty' ),
			'cart_empty_text'             => sanitize_textarea_field( $input['cart_empty_text'] ?? '' ),
			'cart_empty_button_text'      => $text( $input['cart_empty_button_text'] ?? 'Start Shopping' ),
			'cart_empty_button_url'       => $url( $input['cart_empty_button_url'] ?? '' ),

			// Display conditions
			'display_mode'                => $select( $input['display_mode'] ?? '', array( 'everywhere', 'show_on', 'hide_on' ), 'everywhere' ),
			'display_rules_json'          => self::sanitize_rules_json( $input['display_rules_json'] ?? '[]' ),

			// Wishlist
			'wishlist_badge_animation'    => $select( $input['wishlist_badge_animation'] ?? '', array( 'none', 'bounce', 'pulse', 'shake', 'scale', 'fade' ), 'none' ),

			// Cart: animation
			'cart_badge_animation'        => $select( $input['cart_badge_animation'] ?? '', array( 'none', 'bounce', 'pulse', 'shake', 'scale', 'fade' ), 'none' ),
			'cart_open_animation'         => $select( $input['cart_open_animation'] ?? '', array( 'fade', 'slide', 'zoom', 'scale' ), 'slide' ),

			// Cart: mobile
			'cart_mobile_bottom_sheet'    => $bool( $input['cart_mobile_bottom_sheet'] ?? null ),

			// Cart style: trigger
			'cart_icon_hover_color'       => $color( $input['cart_icon_hover_color'] ?? '', '#9500a5' ),
			'cart_trigger_gap'            => $int( $input['cart_trigger_gap'] ?? '', 2 ),
			'cart_trigger_padding_top'    => $int( $input['cart_trigger_padding_top'] ?? '', 0 ),
			'cart_trigger_padding_right'  => $int( $input['cart_trigger_padding_right'] ?? '', 0 ),
			'cart_trigger_padding_bottom' => $int( $input['cart_trigger_padding_bottom'] ?? '', 0 ),
			'cart_trigger_padding_left'   => $int( $input['cart_trigger_padding_left'] ?? '', 0 ),
			'cart_trigger_bg'             => $color( $input['cart_trigger_bg'] ?? '', '' ),
			'cart_trigger_radius'         => $int( $input['cart_trigger_radius'] ?? '', 0 ),

			// Cart style: badge
			'cart_badge_bg'               => $color( $input['cart_badge_bg'] ?? '', 'linear-gradient(135deg,#9500a5 0%,#bd00d1 100%)' ),
			'cart_badge_color'            => $color( $input['cart_badge_color'] ?? '', '#ffffff' ),
			'cart_badge_size'             => $int( $input['cart_badge_size'] ?? '', 17 ),
			'cart_badge_font_size'        => (string) (float) ( $input['cart_badge_font_size'] ?? 9.5 ), // float — preserves 9.5
			'cart_badge_radius'           => $int( $input['cart_badge_radius'] ?? '', 20 ),
			'cart_badge_offset_x'         => (string) (int) ( $input['cart_badge_offset_x'] ?? -8 ),
			'cart_badge_offset_y'         => (string) (int) ( $input['cart_badge_offset_y'] ?? -6 ),

			// Cart style: text & subtotal
			'cart_text_color'             => $color( $input['cart_text_color'] ?? '', '#131b2e' ),
			'cart_subtotal_color'         => $color( $input['cart_subtotal_color'] ?? '', '#9500a5' ),

			// Cart style: panel
			'cart_panel_bg'               => $color( $input['cart_panel_bg'] ?? '', '#ffffff' ),
			'cart_panel_title_color'      => $color( $input['cart_panel_title_color'] ?? '', '#131b2e' ),
			'cart_panel_border_color'     => $color( $input['cart_panel_border_color'] ?? '', '' ),
			'cart_panel_border_width'     => $int( $input['cart_panel_border_width'] ?? '', 0 ),
			'cart_panel_radius'           => $int( $input['cart_panel_radius'] ?? '', 12 ),
			'cart_panel_shadow'           => sanitize_text_field( $input['cart_panel_shadow'] ?? '0 8px 40px rgba(0,0,0,0.12)' ),
			'cart_overlay_bg'             => sanitize_text_field( $input['cart_overlay_bg'] ?? 'rgba(19,27,46,0.45)' ),
			'cart_scrollbar_color'        => $color( $input['cart_scrollbar_color'] ?? '', '#d8bfd3' ),

			// Cart style: product rows
			'cart_item_title_color'       => $color( $input['cart_item_title_color'] ?? '', '#131b2e' ),
			'cart_item_price_color'       => $color( $input['cart_item_price_color'] ?? '', '#9500a5' ),
			'cart_item_divider_color'     => $color( $input['cart_item_divider_color'] ?? '', '#eaedff' ),
			'cart_qty_color'              => $color( $input['cart_qty_color'] ?? '', '#9500a5' ),

			// Cart style: footer buttons
			'cart_checkout_bg'            => $color( $input['cart_checkout_bg'] ?? '', '#9500a5' ),
			'cart_checkout_color'         => $color( $input['cart_checkout_color'] ?? '', '#ffd6fb' ),
			'cart_checkout_hover_bg'      => $color( $input['cart_checkout_hover_bg'] ?? '', '#bd00d1' ),
			'cart_viewcart_color'         => $color( $input['cart_viewcart_color'] ?? '', '#9500a5' ),
			'cart_btn_radius'             => $int( $input['cart_btn_radius'] ?? '', 10 ),

			// Cart style: empty state
			'cart_empty_icon_color'       => $color( $input['cart_empty_icon_color'] ?? '', '#857183' ),
			'cart_empty_title_color'      => $color( $input['cart_empty_title_color'] ?? '', '#131b2e' ),
			'cart_empty_text_color'       => $color( $input['cart_empty_text_color'] ?? '', '#534152' ),
		);
	}

	/* ── Asset enqueue ─────────────────────────────────────────── */

	public function enqueue_assets( string $hook ): void {
		// Matches all three possible hook names:
		//   zymarg-suite_page_zymarg-header  (subpage under ZYMARG Suite)
		//   toplevel_page_zymarg-header       (standalone fallback top-level menu)
		//   settings_page_zymarg-header       (legacy — Settings menu)
		if ( false === strpos( $hook, 'zymarg-header' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'zymarg-header-admin',
			ZYMARG_HEADER_URL . 'assets/js/zymarg-header-admin.js',
			array( 'jquery', 'wp-color-picker' ),
			ZYMARG_HEADER_VERSION,
			true
		);
		wp_localize_script(
			'zymarg-header-admin',
			'zymargAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'zymarg_header_ajax_save' ),
				'savedMsg'  => __( 'Settings saved.', 'zymarg-header' ),
				'savingMsg' => __( 'Saving…', 'zymarg-header' ),
				'errorMsg'  => __( 'Save failed. Please try again.', 'zymarg-header' ),
			)
		);
	}

	/* ── Helpers ───────────────────────────────────────────────── */

	/** Output a hidden-input bool field (checkbox). */
	private function bool_row( string $label, string $key, string $desc = '' ): void {
		$val     = Settings::get( $key );
		$checked = '1' === (string) $val ? ' checked' : '';
		$k       = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $k ); ?>" value="1"<?php echo $checked; ?>>
					<?php if ( $desc ) : ?><span class="description"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/** Output a text input row. */
	private function text_row( string $label, string $key, string $desc = '', string $type = 'text' ): void {
		$val = Settings::get( $key );
		$k   = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" class="regular-text" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $val ); ?>">
				<?php if ( $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** Output a color picker row (uses WP Iris). */
	private function color_row( string $label, string $key, string $default = '' ): void {
		$val = Settings::get( $key, $default );
		$k   = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="text" class="zymarg-color-field" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $val ); ?>" data-default-color="<?php echo esc_attr( $default ); ?>">
			</td>
		</tr>
		<?php
	}

	/** Output a plain text input for values that allow rgba/CSS (no Iris). */
	private function css_value_row( string $label, string $key, string $desc = '' ): void {
		$val = Settings::get( $key );
		$k   = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $val ); ?>">
				<?php if ( $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** Output a number input row. */
	private function num_row( string $label, string $key, int $min = 0, int $max = 9999, string $unit = 'px', string $desc = '' ): void {
		$val = Settings::get( $key );
		$k   = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="any" style="width:80px" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $val ); ?>">
				<span style="margin-left:4px;color:#646970"><?php echo esc_html( $unit ); ?></span>
				<?php if ( $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** Output a <select> row. */
	private function select_row( string $label, string $key, array $options, string $desc = '' ): void {
		$val = Settings::get( $key );
		$k   = Settings::OPTION_KEY . "[{$key}]";
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $k ); ?>">
					<?php foreach ( $options as $v => $l ) : ?>
						<option value="<?php echo esc_attr( $v ); ?>"<?php selected( $val, $v ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/* ── Settings page HTML ────────────────────────────────────── */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$logo_id    = (int) Settings::get( 'logo_image_id' );
		$logo_thumb = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$ok         = Settings::OPTION_KEY;
		$ver        = ZYMARG_HEADER_VERSION;
		?>
		<style>
		/* ── ZYMARG Admin — branded styles ──────────────────────────── */
		.zymarg-admin-wrap { max-width: 900px; }

		/* Branded header */
		.zymarg-admin-header {
			display: flex; align-items: center; gap: 14px;
			background: linear-gradient(135deg,#2d0033 0%,#9500a5 60%,#bd00d1 100%);
			border-radius: 10px; padding: 18px 28px; margin-bottom: 0;
			box-shadow: 0 4px 20px rgba(149,0,165,0.25);
		}
		.zymarg-admin-brand {
			font-size: 22px; font-weight: 800; color: #fff;
			letter-spacing: 2px; font-family: 'Segoe UI',sans-serif;
		}
		.zymarg-admin-brand span { color: #ffd6fb; }
		.zymarg-admin-subtitle {
			font-size: 13px; color: rgba(255,255,255,0.75);
			font-weight: 400; letter-spacing: 0.5px;
		}
		.zymarg-admin-badge {
			margin-left: auto; background: rgba(255,255,255,0.15);
			color: #ffd6fb; font-size: 11px; font-weight: 600;
			padding: 3px 10px; border-radius: 20px; letter-spacing: 0.5px;
		}

		/* Tab navigation */
		.zymarg-tab-nav {
			display: flex; gap: 0;
			border-bottom: 2px solid #e2d5e8;
			margin-bottom: 0; background: #fff;
			border-radius: 0; padding: 0 4px;
		}
		.zymarg-tab-btn {
			background: none; border: none; cursor: pointer;
			padding: 14px 22px; font-size: 13px; font-weight: 600;
			color: #534152; border-bottom: 3px solid transparent;
			margin-bottom: -2px; transition: color 0.15s, border-color 0.15s;
			letter-spacing: 0.2px; position: relative; top: 0;
		}
		.zymarg-tab-btn:hover { color: #9500a5; }
		.zymarg-tab-btn.is-active {
			color: #9500a5; border-bottom-color: #9500a5;
		}

		/* Tab panels */
		.zymarg-tab-panel { padding: 28px 0 0; }

		/* Section headers */
		.zymarg-section {
			font-size: 13px; font-weight: 700; color: #9500a5;
			text-transform: uppercase; letter-spacing: 1px;
			padding: 0 0 8px 14px; margin: 28px 0 4px;
			border-left: 4px solid #9500a5;
		}
		.zymarg-section:first-of-type { margin-top: 0; }

		/* Table tweaks */
		.zymarg-admin-wrap .form-table th {
			width: 220px; padding: 12px 10px 12px 0;
		}
		.zymarg-admin-wrap .form-table td { padding: 10px 10px 10px 0; }

		/* Submit row */
		.zymarg-submit-row {
			padding: 24px 0 8px; border-top: 1px solid #e2d5e8; margin-top: 32px;
			display: flex; align-items: center; gap: 16px;
		}
		.zymarg-submit-row .button-primary {
			background: #9500a5 !important; border-color: #7a008c !important;
			box-shadow: 0 2px 8px rgba(149,0,165,0.3) !important;
			font-weight: 600 !important; padding: 8px 28px !important;
			font-size: 14px !important; height: auto !important;
			transition: background 0.15s, opacity 0.15s !important;
		}
		.zymarg-submit-row .button-primary:hover {
			background: #bd00d1 !important; border-color: #9500a5 !important;
		}
		.zymarg-submit-row .button-primary:disabled {
			opacity: 0.6 !important; cursor: not-allowed !important;
		}

		/* AJAX toast notice */
		#zymarg-ajax-notice {
			display: none;
			padding: 10px 18px; border-radius: 6px; font-size: 13px;
			font-weight: 600; border-left: 4px solid;
		}
		#zymarg-ajax-notice.is-success {
			background: #f0faf3; border-color: #28a745; color: #1a6b2e;
		}
		#zymarg-ajax-notice.is-error {
			background: #fdf2f2; border-color: #dc3545; color: #8b1a1a;
		}
		</style>

		<div class="wrap zymarg-admin-wrap">

			<!-- AJAX toast notice (shown/hidden by JS — no reload needed) -->
			<div id="zymarg-ajax-notice" role="status" aria-live="polite"></div>

			<!-- Branded header -->
			<div class="zymarg-admin-header">
				<div>
					<div class="zymarg-admin-brand">ZYM<span>ARG</span></div>
					<div class="zymarg-admin-subtitle">Header Plugin Settings</div>
				</div>
				<span class="zymarg-admin-badge">v<?php echo esc_html( $ver ); ?></span>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'zymarg_header_save', '_zymarg_nonce' ); ?>

				<!-- Tab navigation -->
				<nav class="zymarg-tab-nav" role="tablist">
					<button type="button" class="zymarg-tab-btn" data-tab="general"   role="tab" aria-selected="false"><?php esc_html_e( 'General',    'zymarg-header' ); ?></button>
					<button type="button" class="zymarg-tab-btn" data-tab="display"   role="tab" aria-selected="false"><?php esc_html_e( 'Display',    'zymarg-header' ); ?></button>
					<button type="button" class="zymarg-tab-btn" data-tab="wishlist"  role="tab" aria-selected="false"><?php esc_html_e( 'Wishlist',   'zymarg-header' ); ?></button>
					<button type="button" class="zymarg-tab-btn" data-tab="cart"      role="tab" aria-selected="false"><?php esc_html_e( 'Cart',       'zymarg-header' ); ?></button>
					<button type="button" class="zymarg-tab-btn" data-tab="cartstyle" role="tab" aria-selected="false"><?php esc_html_e( 'Cart Style', 'zymarg-header' ); ?></button>
				</nav>

				<!-- ╔═══════════════ TAB: GENERAL ═══════════════╗ -->
				<div class="zymarg-tab-panel" id="zymarg-tab-general" role="tabpanel">

					<div class="zymarg-section"><?php esc_html_e( 'Logo', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Logo Image', 'zymarg-header' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo $ok; ?>[logo_image_id]" id="zymarg_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
								<div id="zymarg_logo_preview" style="margin-bottom:8px">
									<?php if ( $logo_thumb ) : ?><img src="<?php echo esc_url( $logo_thumb ); ?>" style="max-height:60px;display:block"><?php endif; ?>
								</div>
								<button type="button" class="button" id="zymarg_logo_upload"><?php esc_html_e( 'Select / Change Logo', 'zymarg-header' ); ?></button>
								<button type="button" class="button" id="zymarg_logo_remove" style="<?php echo $logo_id ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'zymarg-header' ); ?></button>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Logo URL', 'zymarg-header' ); ?></th>
							<td><input type="url" class="regular-text" name="<?php echo $ok; ?>[logo_url]" value="<?php echo esc_attr( Settings::get( 'logo_url', '/' ) ); ?>"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Logo Alt Text', 'zymarg-header' ); ?></th>
							<td><input type="text" class="regular-text" name="<?php echo $ok; ?>[logo_alt]" value="<?php echo esc_attr( Settings::get( 'logo_alt', 'ZYMARG' ) ); ?>"></td>
						</tr>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Top Bar', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->text_row( __( 'Become a Seller — Label', 'zymarg-header' ), 'seller_label' ); ?>
						<?php $this->text_row( __( 'Become a Seller — URL',   'zymarg-header' ), 'seller_url', '', 'url' ); ?>
						<?php $this->text_row( __( 'Login URL',               'zymarg-header' ), 'login_url',    __( 'Leave blank for WordPress default.', 'zymarg-header' ), 'url' ); ?>
						<?php $this->text_row( __( 'Register URL',            'zymarg-header' ), 'register_url', __( 'Leave blank for WordPress default.', 'zymarg-header' ), 'url' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Links', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->text_row( __( 'My Account URL',    'zymarg-header' ), 'account_url',  __( 'Leave blank to auto-resolve from WooCommerce.', 'zymarg-header' ), 'url' ); ?>
						<?php $this->text_row( __( 'Wishlist URL',      'zymarg-header' ), 'wishlist_url', '', 'url' ); ?>
						<?php $this->text_row( __( 'Cart URL Override', 'zymarg-header' ), 'cart_url',     __( 'Leave blank to auto-resolve from WooCommerce.', 'zymarg-header' ), 'url' ); ?>
					</table>

				</div><!-- /tab general -->

				<!-- ╔════════════ TAB: DISPLAY CONDITIONS ══════════╗ -->
				<div class="zymarg-tab-panel" id="zymarg-tab-display" role="tabpanel" hidden>

					<div class="zymarg-section"><?php esc_html_e( 'Display Conditions', 'zymarg-header' ); ?></div>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Header Visibility', 'zymarg-header' ); ?></th>
							<td>
								<?php $dmode = Settings::get( 'display_mode', 'everywhere' ); ?>
								<fieldset>
									<?php foreach ( array(
										'everywhere' => __( 'Show on all pages', 'zymarg-header' ),
										'show_on'    => __( 'Show <strong>only</strong> on pages matching conditions below', 'zymarg-header' ),
										'hide_on'    => __( 'Hide on pages matching conditions below', 'zymarg-header' ),
									) as $val => $label ) : ?>
									<label style="display:block;margin-bottom:8px">
										<input type="radio" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[display_mode]"
											value="<?php echo esc_attr( $val ); ?>"
											<?php checked( $dmode, $val ); ?>>
										<?php echo wp_kses( $label, array( 'strong' => array() ) ); ?>
									</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Conditions', 'zymarg-header' ); ?></div>
					<p style="color:#534152;margin:0 0 16px">
						<?php esc_html_e( 'Rules use OR logic — the header shows/hides if ANY condition matches. Ignored when "Show on all pages" is selected.', 'zymarg-header' ); ?>
					</p>

					<!-- Hidden field holds the JSON array of rules -->
					<input type="hidden"
						name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[display_rules_json]"
						id="zymarg-display-rules-json"
						value="<?php echo esc_attr( Settings::get( 'display_rules_json', '[]' ) ); ?>">

					<!-- Rule rows rendered by JS -->
					<div id="zymarg-rules-list" style="margin-bottom:12px"></div>

					<button type="button" id="zymarg-add-rule"
						style="background:#9500a5;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">
						+ <?php esc_html_e( 'Add Condition', 'zymarg-header' ); ?>
					</button>

					<style>
					.zymarg-rule-row {
						display:flex;align-items:center;gap:10px;
						background:#faf8ff;border:1px solid #e2d5e8;
						border-radius:8px;padding:10px 14px;margin-bottom:8px;
					}
					.zymarg-rule-type { min-width:200px;border-radius:4px;border:1px solid #c3a7cc;padding:6px 8px }
					.zymarg-rule-value { flex:1;border-radius:4px;border:1px solid #c3a7cc;padding:6px 8px }
					.zymarg-rule-remove {
						background:none;border:none;color:#a32d2d;cursor:pointer;
						font-size:18px;line-height:1;padding:2px 6px;border-radius:4px;
					}
					.zymarg-rule-remove:hover { background:#fcebeb }
					</style>

				</div><!-- /tab display -->

				<!-- ╔══════════════ TAB: WISHLIST ════════════════╗ -->
				<div class="zymarg-tab-panel" id="zymarg-tab-wishlist" role="tabpanel" hidden>

					<div class="zymarg-section"><?php esc_html_e( 'Badge Animation', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->select_row(
							__( 'Animation (on count change)', 'zymarg-header' ),
							'wishlist_badge_animation',
							array(
								'none'   => __( 'None',   'zymarg-header' ),
								'bounce' => __( 'Bounce', 'zymarg-header' ),
								'pulse'  => __( 'Pulse',  'zymarg-header' ),
								'shake'  => __( 'Shake',  'zymarg-header' ),
								'scale'  => __( 'Scale',  'zymarg-header' ),
								'fade'   => __( 'Fade',   'zymarg-header' ),
							),
							__( 'Fires without page reload whenever the wishlist count changes.', 'zymarg-header' )
						); ?>
					</table>

				</div><!-- /tab wishlist -->

				<!-- ╔════════════════ TAB: CART ══════════════════╗ -->
				<div class="zymarg-tab-panel" id="zymarg-tab-cart" role="tabpanel" hidden>

					<div class="zymarg-section"><?php esc_html_e( 'Display', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->text_row( __( 'Label Text', 'zymarg-header' ), 'cart_text' ); ?>
						<?php $this->bool_row( __( 'Show Count Badge', 'zymarg-header' ), 'cart_show_badge' ); ?>
						<?php $this->select_row( __( 'Count Shows', 'zymarg-header' ), 'cart_count_type', array(
							'total_qty' => __( 'Total quantity (e.g. 5)', 'zymarg-header' ),
							'unique'    => __( 'Unique products (e.g. 3)', 'zymarg-header' ),
						) ); ?>
						<?php $this->bool_row( __( 'Show badge when empty (0)', 'zymarg-header' ), 'cart_show_zero' ); ?>
						<?php $this->bool_row( __( 'Show Subtotal next to icon', 'zymarg-header' ), 'cart_show_subtotal' ); ?>
						<?php $this->select_row( __( 'Badge Position', 'zymarg-header' ), 'cart_badge_position', array(
							'top-right'    => __( 'Top Right',    'zymarg-header' ),
							'top-left'     => __( 'Top Left',     'zymarg-header' ),
							'bottom-right' => __( 'Bottom Right', 'zymarg-header' ),
							'bottom-left'  => __( 'Bottom Left',  'zymarg-header' ),
						) ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Click Action', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->select_row( __( 'On Click', 'zymarg-header' ), 'cart_click_action', array(
							'dropdown'  => __( 'Open mini-cart dropdown',  'zymarg-header' ),
							'offcanvas' => __( 'Open off-canvas drawer',   'zymarg-header' ),
							'popup'     => __( 'Open popup cart',          'zymarg-header' ),
							'cart'      => __( 'Go to Cart page',          'zymarg-header' ),
							'checkout'  => __( 'Go to Checkout page',      'zymarg-header' ),
						) ); ?>
						<?php $this->select_row( __( 'Drawer Slides From', 'zymarg-header' ), 'cart_offcanvas_position', array(
							'right'  => __( 'Right',  'zymarg-header' ),
							'left'   => __( 'Left',   'zymarg-header' ),
							'bottom' => __( 'Bottom', 'zymarg-header' ),
						), __( 'Applies when "Off-canvas drawer" is selected above.', 'zymarg-header' ) ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Mini Cart Panel', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->text_row( __( 'Panel Title', 'zymarg-header' ), 'cart_panel_title' ); ?>
						<?php $this->num_row( __( 'Dropdown Width', 'zymarg-header' ), 'cart_dropdown_width', 260, 560 ); ?>
						<?php $this->num_row( __( 'Items Max Height', 'zymarg-header' ), 'cart_items_max_height', 120, 800 ); ?>
						<?php $this->bool_row( __( 'Product Image', 'zymarg-header' ), 'cart_show_product_image' ); ?>
						<?php $this->bool_row( __( 'Show SKU', 'zymarg-header' ), 'cart_show_sku' ); ?>
						<?php $this->bool_row( __( 'Show Variation / Attributes', 'zymarg-header' ), 'cart_show_attributes' ); ?>
						<?php $this->bool_row( __( 'Quantity Controls', 'zymarg-header' ), 'cart_allow_qty_update' ); ?>
						<?php $this->bool_row( __( 'Allow Remove Item', 'zymarg-header' ), 'cart_allow_remove' ); ?>
						<tr><th colspan="2" style="padding-top:16px"><strong><?php esc_html_e( 'Footer Buttons', 'zymarg-header' ); ?></strong></th></tr>
						<?php $this->bool_row( __( 'View Cart Button', 'zymarg-header' ), 'cart_show_view_cart' ); ?>
						<?php $this->text_row( __( 'View Cart Text', 'zymarg-header' ), 'cart_view_cart_text' ); ?>
						<?php $this->bool_row( __( 'Checkout Button', 'zymarg-header' ), 'cart_show_checkout' ); ?>
						<?php $this->text_row( __( 'Checkout Text', 'zymarg-header' ), 'cart_checkout_text' ); ?>
						<?php $this->bool_row( __( 'Continue Shopping Button', 'zymarg-header' ), 'cart_show_continue' ); ?>
						<?php $this->text_row( __( 'Continue Shopping Text', 'zymarg-header' ), 'cart_continue_text' ); ?>
						<?php $this->text_row( __( 'Continue Shopping URL', 'zymarg-header' ), 'cart_continue_url', __( 'Leave blank to default to the Shop page.', 'zymarg-header' ), 'url' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Empty State', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->text_row( __( 'Title', 'zymarg-header' ), 'cart_empty_title' ); ?>
						<tr>
							<th><?php esc_html_e( 'Description', 'zymarg-header' ); ?></th>
							<td><textarea class="large-text" rows="2" name="<?php echo $ok; ?>[cart_empty_text]"><?php echo esc_textarea( Settings::get( 'cart_empty_text' ) ); ?></textarea></td>
						</tr>
						<?php $this->text_row( __( 'Button Text', 'zymarg-header' ), 'cart_empty_button_text' ); ?>
						<?php $this->text_row( __( 'Button URL', 'zymarg-header' ), 'cart_empty_button_url', __( 'Leave blank to default to the Shop page.', 'zymarg-header' ), 'url' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Animation', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->select_row( __( 'Badge Animation (on count change)', 'zymarg-header' ), 'cart_badge_animation', array(
							'none'   => __( 'None',   'zymarg-header' ),
							'bounce' => __( 'Bounce', 'zymarg-header' ),
							'pulse'  => __( 'Pulse',  'zymarg-header' ),
							'shake'  => __( 'Shake',  'zymarg-header' ),
							'scale'  => __( 'Scale',  'zymarg-header' ),
							'fade'   => __( 'Fade',   'zymarg-header' ),
						) ); ?>
						<?php $this->select_row( __( 'Panel Open Animation', 'zymarg-header' ), 'cart_open_animation', array(
							'fade'  => __( 'Fade',  'zymarg-header' ),
							'slide' => __( 'Slide', 'zymarg-header' ),
							'zoom'  => __( 'Zoom',  'zymarg-header' ),
							'scale' => __( 'Scale', 'zymarg-header' ),
						) ); ?>
						<?php $this->bool_row( __( 'Bottom sheet on mobile', 'zymarg-header' ), 'cart_mobile_bottom_sheet', __( 'Dropdown / popup / off-canvas becomes a full-width bottom sheet on screens ≤ 767px.', 'zymarg-header' ) ); ?>
					</table>

				</div><!-- /tab cart -->

				<!-- ╔══════════════ TAB: CART STYLE ══════════════╗ -->
				<div class="zymarg-tab-panel" id="zymarg-tab-cartstyle" role="tabpanel" hidden>

					<div class="zymarg-section"><?php esc_html_e( 'Trigger & Icon', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Icon Hover Color', 'zymarg-header' ), 'cart_icon_hover_color', '#9500a5' ); ?>
						<?php $this->num_row( __( 'Gap (icon ↔ text)', 'zymarg-header' ), 'cart_trigger_gap', 0, 30 ); ?>
						<tr>
							<th><?php esc_html_e( 'Padding (T / R / B / L)', 'zymarg-header' ); ?></th>
							<td style="display:flex;gap:8px;align-items:center">
								<?php foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) : ?>
									<input type="number" min="0" max="60" style="width:60px" placeholder="<?php echo ucfirst( $side[0] ); ?>"
										name="<?php echo $ok; ?>[cart_trigger_padding_<?php echo $side; ?>]"
										value="<?php echo esc_attr( Settings::get( "cart_trigger_padding_{$side}", '0' ) ); ?>">
								<?php endforeach; ?>
								<span style="color:#646970">px</span>
							</td>
						</tr>
						<?php $this->color_row( __( 'Background', 'zymarg-header' ), 'cart_trigger_bg', '' ); ?>
						<?php $this->num_row( __( 'Border Radius', 'zymarg-header' ), 'cart_trigger_radius', 0, 50 ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Count Badge', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->css_value_row( __( 'Background', 'zymarg-header' ), 'cart_badge_bg', __( 'Hex, rgba, or gradient. E.g. linear-gradient(135deg,#9500a5 0%,#bd00d1 100%)', 'zymarg-header' ) ); ?>
						<?php $this->color_row( __( 'Text Color', 'zymarg-header' ), 'cart_badge_color', '#ffffff' ); ?>
						<?php $this->num_row( __( 'Size (height + min-width)', 'zymarg-header' ), 'cart_badge_size', 14, 34 ); ?>
						<?php $this->num_row( __( 'Font Size', 'zymarg-header' ), 'cart_badge_font_size', 8, 18 ); ?>
						<?php $this->num_row( __( 'Border Radius', 'zymarg-header' ), 'cart_badge_radius', 0, 20, 'px — use 20 for pill/circle' ); ?>
						<?php $this->num_row( __( 'Offset X', 'zymarg-header' ), 'cart_badge_offset_x', -30, 30, 'px — negative moves left' ); ?>
						<?php $this->num_row( __( 'Offset Y', 'zymarg-header' ), 'cart_badge_offset_y', -30, 30, 'px — negative moves up' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Text & Subtotal', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Text Color', 'zymarg-header' ), 'cart_text_color', '#131b2e' ); ?>
						<?php $this->color_row( __( 'Subtotal Color', 'zymarg-header' ), 'cart_subtotal_color', '#9500a5' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Panel', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Background', 'zymarg-header' ), 'cart_panel_bg', '#ffffff' ); ?>
						<?php $this->color_row( __( 'Title Color', 'zymarg-header' ), 'cart_panel_title_color', '#131b2e' ); ?>
						<?php $this->num_row( __( 'Border Radius', 'zymarg-header' ), 'cart_panel_radius', 0, 40 ); ?>
						<?php $this->color_row( __( 'Border Color', 'zymarg-header' ), 'cart_panel_border_color', '' ); ?>
						<?php $this->num_row( __( 'Border Width', 'zymarg-header' ), 'cart_panel_border_width', 0, 4 ); ?>
						<?php $this->css_value_row( __( 'Box Shadow', 'zymarg-header' ), 'cart_panel_shadow', __( 'Standard CSS box-shadow value.', 'zymarg-header' ) ); ?>
						<?php $this->css_value_row( __( 'Overlay Color (offcanvas/popup)', 'zymarg-header' ), 'cart_overlay_bg', __( 'Supports rgba. E.g. rgba(19,27,46,0.45)', 'zymarg-header' ) ); ?>
						<?php $this->color_row( __( 'Scrollbar Color', 'zymarg-header' ), 'cart_scrollbar_color', '#d8bfd3' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Product Rows', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Title Color', 'zymarg-header' ), 'cart_item_title_color', '#131b2e' ); ?>
						<?php $this->color_row( __( 'Price Color', 'zymarg-header' ), 'cart_item_price_color', '#9500a5' ); ?>
						<?php $this->color_row( __( 'Divider Color', 'zymarg-header' ), 'cart_item_divider_color', '#eaedff' ); ?>
						<?php $this->color_row( __( 'Quantity Button Color', 'zymarg-header' ), 'cart_qty_color', '#9500a5' ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Footer Buttons', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Checkout Background', 'zymarg-header' ), 'cart_checkout_bg', '#9500a5' ); ?>
						<?php $this->color_row( __( 'Checkout Text Color', 'zymarg-header' ), 'cart_checkout_color', '#ffd6fb' ); ?>
						<?php $this->color_row( __( 'Checkout Hover Background', 'zymarg-header' ), 'cart_checkout_hover_bg', '#bd00d1' ); ?>
						<?php $this->color_row( __( 'View Cart Color', 'zymarg-header' ), 'cart_viewcart_color', '#9500a5' ); ?>
						<?php $this->num_row( __( 'Button Border Radius', 'zymarg-header' ), 'cart_btn_radius', 0, 30 ); ?>
					</table>

					<div class="zymarg-section"><?php esc_html_e( 'Empty State', 'zymarg-header' ); ?></div>
					<table class="form-table">
						<?php $this->color_row( __( 'Icon Color', 'zymarg-header' ), 'cart_empty_icon_color', '#857183' ); ?>
						<?php $this->color_row( __( 'Title Color', 'zymarg-header' ), 'cart_empty_title_color', '#131b2e' ); ?>
						<?php $this->color_row( __( 'Text Color', 'zymarg-header' ), 'cart_empty_text_color', '#534152' ); ?>
					</table>

				</div><!-- /tab cartstyle -->

				<div class="zymarg-submit-row">
					<?php submit_button( null, 'primary', 'submit', false, array( 'id' => 'zymarg-save-btn' ) ); ?>
					<span id="zymarg-save-notice" style="display:none;font-size:13px;font-weight:600"></span>
				</div>

			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize the display-rules JSON string.
	 * @since 1.1.17
	 */
	private static function sanitize_rules_json( string $raw ): string {
		$rules = json_decode( stripslashes( $raw ), true );
		if ( ! is_array( $rules ) ) {
			return '[]';
		}
		$allowed_types = array(
			'front_page', 'blog', '404', 'search', 'archive', 'singular',
			'woo_shop', 'woo_product', 'woo_cart', 'woo_checkout', 'woo_account',
			'page', 'post_type', 'url',
			// Dokan — @since 1.1.18
			'dokan_store', 'dokan_store_listing', 'dokan_dashboard',
			'dokan_orders', 'dokan_settings_page',
		);
		$clean = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type = sanitize_key( $rule['type'] ?? '' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$clean[] = array(
				'type'  => $type,
				'value' => sanitize_text_field( $rule['value'] ?? '' ),
			);
		}
		return wp_json_encode( $clean ) ?: '[]';
	}
}
