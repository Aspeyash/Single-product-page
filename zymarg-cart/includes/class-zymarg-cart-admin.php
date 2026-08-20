<?php
/**
 * Admin settings page for ZYMARG Cart.
 *
 * Registers WP Admin → ZYMARG Cart → Settings with three tabs:
 *   Tab 1 — Header Settings
 *   Tab 2 — Body Settings
 *   Tab 3 — Total Settings
 *
 * Brand colors are no longer configurable per-site here — the plugin now
 * uses the shared ZYMARG Design Tokens (zymarg-tokens.css) as the single
 * source of truth for all brand colors, including dark mode. See the
 * "🎨 Style" tab removal in this version's changelog.
 *
 * Settings are stored via Zymarg_Cart_Settings::OPTION_KEY.
 *
 * @package ZymargCart
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Admin {

	const PAGE_SLUG = 'zymarg-cart-settings';
	const NONCE_KEY = 'zymarg_cart_admin_save';

	public static function init(): void {
		add_action( 'admin_menu',        [ self::class, 'register_menu' ] );
		add_action( 'admin_post_zymarg_cart_save_settings', [ self::class, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_menu_branding' ] );
	}

	// ── Sidebar parent-menu branding (Design Tokens v3 section 2.16) ──
	// Scoped to #toplevel_page_zymarg-cart-settings and enqueued on every
	// admin page. Runs alongside the existing enqueue_admin_assets() which
	// is screen-gated for the settings page's own CSS/JS bundle.
	public static function enqueue_menu_branding(): void {
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_CART_URL . 'assets/css/zymarg-tokens.css',
				[],
				ZYMARG_CART_VERSION
			);
		}
		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style(
			'zymarg-cart-menu',
			ZYMARG_CART_URL . 'assets/css/zymarg-cart-menu.css',
			[ 'zymarg-tokens' ],
			ZYMARG_CART_VERSION
		);
	}

	// ── Menu ──────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_menu_page(
			__( 'ZYMARG Cart', 'zymarg-cart' ),
			__( 'ZYMARG Cart', 'zymarg-cart' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
			'dashicons-cart',
			58
		);
	}

	// ── Admin assets ──────────────────────────────────────────────────────

	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		// Inline admin CSS — small enough to not need a separate file.
		$css = self::admin_css();
		wp_register_style( 'zymarg-cart-admin', false );
		wp_enqueue_style( 'zymarg-cart-admin' );
		wp_add_inline_style( 'zymarg-cart-admin', $css );
	}

	// ── Save handler ──────────────────────────────────────────────────────

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'zymarg-cart' ) );
		}

		check_admin_referer( self::NONCE_KEY );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw  = $_POST['zymarg_cart'] ?? [];
		$data = Zymarg_Cart_Settings::sanitize( (array) $raw );

		update_option( Zymarg_Cart_Settings::OPTION_KEY, $data );
		Zymarg_Cart_Settings::flush();

		$active_tab = sanitize_key( $_POST['_active_tab'] ?? 'header' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $active_tab . '&saved=1' ) );
		exit;
	}

	// ── Page renderer ─────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$initial_tab = sanitize_key( $_GET['tab'] ?? 'header' );
		$saved       = ! empty( $_GET['saved'] );
		$s           = Zymarg_Cart_Settings::all();

		$tabs = [
			'header' => '🏷 Header',
			'body'   => '📦 Body',
			'total'  => '💳 Total',
		];
		?>
		<div class="zc-admin-wrap">

			<?php if ( $saved ) : ?>
				<div class="zc-notice zc-notice--success" id="zc-save-notice">
					✓ Settings saved successfully.
				</div>
			<?php endif; ?>

			<div class="zc-admin-header">
				<div class="zc-admin-logo">
					<span class="zc-logo-icon">🛒</span>
					<div>
						<div class="zc-logo-title">ZYMARG Cart</div>
						<div class="zc-logo-version">v<?php echo esc_html( ZYMARG_CART_VERSION ); ?> · Standalone Edition</div>
					</div>
				</div>
				<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" target="_blank" class="zc-preview-btn">
					Preview Cart Page ↗
				</a>
			</div>

			<div class="zc-admin-tabs" role="tablist">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<button type="button"
					        class="zc-tab<?php echo $slug === $initial_tab ? ' zc-tab--active' : ''; ?>"
					        role="tab"
					        data-tab="<?php echo esc_attr( $slug ); ?>"
					        aria-selected="<?php echo $slug === $initial_tab ? 'true' : 'false'; ?>"
					        aria-controls="zc-panel-<?php echo esc_attr( $slug ); ?>"
					        id="zc-tab-<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zc-admin-form">
				<?php wp_nonce_field( self::NONCE_KEY ); ?>
				<input type="hidden" name="action" value="zymarg_cart_save_settings">
				<input type="hidden" name="_active_tab" id="zc-active-tab-input" value="<?php echo esc_attr( $initial_tab ); ?>">

				<div class="zc-tab-panel<?php echo $initial_tab === 'header' ? ' zc-tab-panel--active' : ''; ?>"
				     id="zc-panel-header" role="tabpanel" aria-labelledby="zc-tab-header">
					<?php self::render_header_tab( $s['header'] ); ?>
				</div>

				<div class="zc-tab-panel<?php echo $initial_tab === 'body' ? ' zc-tab-panel--active' : ''; ?>"
				     id="zc-panel-body" role="tabpanel" aria-labelledby="zc-tab-body">
					<?php self::render_body_tab( $s['body'] ); ?>
				</div>

				<div class="zc-tab-panel<?php echo $initial_tab === 'total' ? ' zc-tab-panel--active' : ''; ?>"
				     id="zc-panel-total" role="tabpanel" aria-labelledby="zc-tab-total">
					<?php self::render_total_tab( $s['total'] ); ?>
				</div>

				<div class="zc-save-bar">
					<button type="submit" class="zc-save-btn">Save Settings</button>
					<span class="zc-save-hint">All tabs are saved together.</span>
				</div>
			</form>

		</div>

		<script>
		(function () {
			'use strict';

			var wrap   = document.querySelector('.zc-admin-wrap');
			var tabs   = wrap ? wrap.querySelectorAll('.zc-tab') : [];
			var panels = wrap ? wrap.querySelectorAll('.zc-tab-panel') : [];
			var input  = document.getElementById('zc-active-tab-input');
			var notice = document.getElementById('zc-save-notice');

			// Auto-dismiss save notice after 3 s
			if (notice) {
				setTimeout(function () {
					notice.style.transition = 'opacity .4s';
					notice.style.opacity = '0';
					setTimeout(function () { notice.remove(); }, 420);
				}, 3000);
			}

			function activateTab(slug) {
				tabs.forEach(function (btn) {
					var active = btn.dataset.tab === slug;
					btn.classList.toggle('zc-tab--active', active);
					btn.setAttribute('aria-selected', active ? 'true' : 'false');
				});

				panels.forEach(function (panel) {
					panel.classList.toggle('zc-tab-panel--active', panel.id === 'zc-panel-' + slug);
				});

				if (input) input.value = slug;

				// Update URL without reload so refresh / back-button lands on same tab
				if (window.history && window.history.replaceState) {
					var url = new URL(window.location.href);
					url.searchParams.set('tab', slug);
					window.history.replaceState({ tab: slug }, '', url.toString());
				}
			}

			tabs.forEach(function (btn) {
				btn.addEventListener('click', function () {
					activateTab(btn.dataset.tab);
				});
			});

			// Browser back / forward support
			window.addEventListener('popstate', function (e) {
				activateTab((e.state && e.state.tab) || 'header');
			});

			// Keyboard: ← → Home End (ARIA tabs pattern)
			tabs.forEach(function (btn, i) {
				btn.addEventListener('keydown', function (e) {
					var n = null;
					if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
					if (e.key === 'ArrowLeft')  n = (i - 1 + tabs.length) % tabs.length;
					if (e.key === 'Home')        n = 0;
					if (e.key === 'End')         n = tabs.length - 1;
					if (n !== null) {
						e.preventDefault();
						tabs[n].focus();
						activateTab(tabs[n].dataset.tab);
					}
				});
			});
		}());
		</script>
		<?php
	}

	// ── Tab: Header ───────────────────────────────────────────────────────

	private static function render_header_tab( array $h ): void {
		?>
		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Visibility', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'header[show_cart_icon]',      $h['show_cart_icon'],      __( 'Cart Icon',       'zymarg-cart' ) );
				self::toggle( 'header[show_cart_title]',     $h['show_cart_title'],     __( 'Cart Title',      'zymarg-cart' ) );
				self::toggle( 'header[show_item_count]',     $h['show_item_count'],     __( 'Item Count Badge','zymarg-cart' ) );
				self::toggle( 'header[show_edit_btn]',       $h['show_edit_btn'],       __( 'Edit Button',     'zymarg-cart' ) );
				self::toggle( 'header[show_delete_btn]',     $h['show_delete_btn'],     __( 'Delete Button',   'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Labels', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::text( 'header[cart_title]',       $h['cart_title'],       __( 'Cart Title',        'zymarg-cart' ), __( 'Default: My Cart',  'zymarg-cart' ) );
				self::text( 'header[edit_btn_label]',   $h['edit_btn_label'],   __( 'Edit Button Label', 'zymarg-cart' ), __( 'Default: Edit',     'zymarg-cart' ) );
				self::text( 'header[done_btn_label]',   $h['done_btn_label'],   __( 'Done Button Label', 'zymarg-cart' ), __( 'Default: Done',     'zymarg-cart' ) );
				self::text( 'header[delete_btn_label]', $h['delete_btn_label'], __( 'Delete Button Label','zymarg-cart' ), __( 'Default: Delete',  'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Behaviour', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php self::toggle( 'header[edit_confirm_dialog]', $h['edit_confirm_dialog'], __( 'Delete Confirmation Dialog', 'zymarg-cart' ), __( 'Show a confirmation prompt before items are deleted in edit mode.', 'zymarg-cart' ) ); ?>
				<?php self::text( 'header[confirm_dialog_text]', $h['confirm_dialog_text'], __( 'Confirmation Message', 'zymarg-cart' ), __( 'Default: Are you sure you want to remove the selected items?', 'zymarg-cart' ) ); ?>
			</div>
		</div>
		<?php
	}

	// ── Tab: Body ─────────────────────────────────────────────────────────

	private static function render_body_tab( array $b ): void {
		?>
		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Vendor Row', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'body[show_vendor_checkbox]', $b['show_vendor_checkbox'], __( 'Vendor Checkbox',  'zymarg-cart' ) );
				self::toggle( 'body[show_vendor_link]',     $b['show_vendor_link'],     __( 'Store Name Link',  'zymarg-cart' ) );
				self::toggle( 'body[show_vendor_arrow]',    $b['show_vendor_arrow'],    __( 'Link Arrow Icon',  'zymarg-cart' ) );
				self::toggle( 'body[show_table_headers]',   $b['show_table_headers'],   __( 'Column Headers',   'zymarg-cart' ) );
				self::toggle( 'body[show_vendor_subtotal]', $b['show_vendor_subtotal'], __( 'Vendor Subtotal Row','zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Vendor Identity Icon', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::radio(
					'body[vendor_icon_type]',
					$b['vendor_icon_type'],
					__( 'Vendor Icon Mode', 'zymarg-cart' ),
					[
						'vendor_profile' => __( 'Dokan Profile Photo (per-vendor)', 'zymarg-cart' ),
						'static_icon'    => __( 'Static Icon (same for all vendors)', 'zymarg-cart' ),
					]
				);
				self::select(
					'body[vendor_static_icon]',
					$b['vendor_static_icon'],
					__( 'Static Icon', 'zymarg-cart' ),
					[
						'building-store' => __( 'Store / Shop', 'zymarg-cart' ),
						'shopping-bag'   => __( 'Shopping Bag', 'zymarg-cart' ),
						'user'           => __( 'User / Person', 'zymarg-cart' ),
						'star'           => __( 'Star', 'zymarg-cart' ),
						'package'        => __( 'Package / Box', 'zymarg-cart' ),
						'briefcase'      => __( 'Briefcase', 'zymarg-cart' ),
						'tag'            => __( 'Tag', 'zymarg-cart' ),
					],
					__( 'Only used when Static Icon mode is selected above.', 'zymarg-cart' )
				);
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Product Row', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'body[show_product_checkbox]',   $b['show_product_checkbox'],   __( 'Product Checkbox',      'zymarg-cart' ) );
				self::toggle( 'body[show_product_image]',      $b['show_product_image'],      __( 'Product Image',         'zymarg-cart' ) );
				self::toggle( 'body[show_product_sku]',        $b['show_product_sku'],        __( 'SKU',                   'zymarg-cart' ) );
				self::toggle( 'body[show_stock_warning]',      $b['show_stock_warning'],      __( 'Stock Warning',         'zymarg-cart' ) );
				self::toggle( 'body[show_product_price]',      $b['show_product_price'],      __( 'Unit Price Column',     'zymarg-cart' ) );
				self::toggle( 'body[show_variation_dropdown]', $b['show_variation_dropdown'], __( 'Variation Switcher',    'zymarg-cart' ) );
				self::toggle( 'body[show_qty_stepper]',        $b['show_qty_stepper'],        __( 'Quantity Stepper',      'zymarg-cart' ) );
				self::toggle( 'body[show_coupon_field]',       $b['show_coupon_field'],       __( 'Per-Product Coupon',    'zymarg-cart' ) );
				self::toggle( 'body[show_save_later_btn]',     $b['show_save_later_btn'],     __( 'Save for Later Button', 'zymarg-cart' ) );
				self::toggle( 'body[show_save_later_icon]',    $b['show_save_later_icon'],    __( 'Save for Later Icon',   'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Save for Later', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'body[save_later_enabled]',     $b['save_later_enabled'],     __( 'Enable Save for Later',        'zymarg-cart' ) );
				self::toggle( 'body[show_saved_below_cart]',  $b['show_saved_below_cart'],  __( 'Show Saved Items Below Cart',  'zymarg-cart' ) );
				self::toggle( 'body[show_saved_count_badge]', $b['show_saved_count_badge'], __( 'Show Saved Count Badge',       'zymarg-cart' ) );
				self::toggle( 'body[show_move_to_cart_btn]',  $b['show_move_to_cart_btn'],  __( 'Show Move to Cart Button',     'zymarg-cart' ) );
				self::toggle( 'body[show_remove_saved_btn]',  $b['show_remove_saved_btn'],  __( 'Show Remove Saved Button',     'zymarg-cart' ) );
				self::toggle( 'body[show_price_changed]',     $b['show_price_changed'],     __( 'Show Price Changed Badge',     'zymarg-cart' ) );
				self::number( 'body[save_later_max]',         (string) $b['save_later_max'], __( 'Max Saved Items',             'zymarg-cart' ), '1', '50', __( 'Maximum number of items a user can save. Default: 10.', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Labels', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::text( 'body[have_coupon_text]',       $b['have_coupon_text'],       __( '"Have a coupon?" Text',    'zymarg-cart' ), __( 'Default: Have a coupon?',     'zymarg-cart' ) );
				self::text( 'body[save_later_label]',       $b['save_later_label'],       __( 'Save for Later Label',    'zymarg-cart' ), __( 'Default: Save for later',     'zymarg-cart' ) );
				self::text( 'body[move_to_cart_label]',     $b['move_to_cart_label'],     __( 'Move to Cart Label',      'zymarg-cart' ), __( 'Default: Move to Cart',       'zymarg-cart' ) );
				self::text( 'body[saved_section_title]',    $b['saved_section_title'],    __( 'Saved Section Title',     'zymarg-cart' ), __( 'Default: Saved for Later',    'zymarg-cart' ) );
				self::text( 'body[empty_cart_message]',     $b['empty_cart_message'],     __( 'Empty Cart Message',      'zymarg-cart' ), __( 'Default: Your cart is empty.','zymarg-cart' ) );
				self::text( 'body[continue_shopping_text]', $b['continue_shopping_text'], __( 'Continue Shopping Label', 'zymarg-cart' ), __( 'Default: Continue Shopping',  'zymarg-cart' ) );
				$url_val = is_array( $b['continue_shopping_url'] ) ? ( $b['continue_shopping_url']['url'] ?? '' ) : $b['continue_shopping_url'];
				self::url( 'body[continue_shopping_url]', $url_val, __( 'Continue Shopping URL', 'zymarg-cart' ), __( 'Default: /shop/', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Behaviour', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'body[show_empty_cart_illus]', $b['show_empty_cart_illus'], __( 'Show Empty Cart Illustration', 'zymarg-cart' ) );
				self::number( 'body[mobile_breakpoint]', (string) $b['mobile_breakpoint'], __( 'Mobile Breakpoint (px)', 'zymarg-cart' ), '320', '1200', __( 'Below this width the cart switches to mobile card layout. Default: 480.', 'zymarg-cart' ) );
				?>
			</div>
		</div>
		<?php
	}

	// ── Tab: Total ────────────────────────────────────────────────────────

	private static function render_total_tab( array $t ): void {
		?>
		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Subtotal Bar', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'total[show_subtotal_bar]',   $t['show_subtotal_bar'],   __( 'Show Subtotal Bar',      'zymarg-cart' ) );
				self::toggle( 'total[show_selected_count]', $t['show_selected_count'], __( 'Show Selected Count',    'zymarg-cart' ) );
				self::text(   'total[order_summary_text]',  $t['order_summary_text'],  __( 'Order Summary Label',    'zymarg-cart' ), __( 'Default: Order Summary', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Breakdown Panel Lines', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'total[show_subtotal_line]',        $t['show_subtotal_line'],        __( 'Subtotal Line',                'zymarg-cart' ) );
				self::toggle( 'total[show_discount_line]',        $t['show_discount_line'],        __( 'Discount Line',                'zymarg-cart' ) );
				self::toggle( 'total[show_shipping_line]',        $t['show_shipping_line'],        __( 'Shipping Line',                'zymarg-cart' ) );
				self::toggle( 'total[show_shipping_per_vendor]',  $t['show_shipping_per_vendor'],  __( 'Per-Vendor Shipping',          'zymarg-cart' ) );
				self::toggle( 'total[show_tax_line]',             $t['show_tax_line'],             __( 'Tax Line',                     'zymarg-cart' ), __( 'Hidden by default. Enable to show the tax row. Use Tax Label below to customise the label.', 'zymarg-cart' ) );
				self::text(   'total[tax_label]',                 $t['tax_label'],                 __( 'Tax Label',                    'zymarg-cart' ), __( 'Default: VAT. Override per region, e.g. "Tax (6% SST)" for Malaysia, "GST" for India/Australia.', 'zymarg-cart' ) );
				self::toggle( 'total[popup_final_note_show]',     $t['popup_final_note_show'],     __( 'Footer Note',                  'zymarg-cart' ) );
				self::text(   'total[popup_final_note_text]',     $t['popup_final_note_text'],     __( 'Footer Note Text',             'zymarg-cart' ), __( 'Default: Final price displayed at checkout', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Action Bar', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'total[show_master_cb]',          $t['show_master_cb'],          __( 'Select All Checkbox',   'zymarg-cart' ) );
				self::toggle( 'total[show_select_label]',       $t['show_select_label'],       __( 'Selected Count Label',  'zymarg-cart' ) );
				self::toggle( 'total[show_action_grand]',       $t['show_action_grand'],       __( 'Grand Total Amount',    'zymarg-cart' ) );
				self::toggle( 'total[show_action_grand_label]', $t['show_action_grand_label'], __( 'Grand Total Label',     'zymarg-cart' ) );
				self::text(   'total[grand_total_label_text]',  $t['grand_total_label_text'],  __( 'Grand Total Label Text','zymarg-cart' ), __( 'Default: Grand Total', 'zymarg-cart' ) );
				self::toggle( 'total[show_checkout_btn]',       $t['show_checkout_btn'],       __( 'Checkout Button',       'zymarg-cart' ) );
				self::toggle( 'total[show_checkout_icon]',      $t['show_checkout_icon'],      __( 'Checkout Button Icon',  'zymarg-cart' ) );
				self::text(   'total[checkout_btn_text]',       $t['checkout_btn_text'],       __( 'Checkout Button Text',  'zymarg-cart' ), __( 'Default: Proceed to Checkout', 'zymarg-cart' ) );
				self::toggle( 'total[checkout_btn_loading]',    $t['checkout_btn_loading'],    __( 'Loading State on Checkout Button', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Sticky Mode', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'total[sticky_desktop]', $t['sticky_desktop'], __( 'Sticky on Desktop', 'zymarg-cart' ), __( 'Pins the Cart Total to the bottom of the screen on desktop.', 'zymarg-cart' ) );
				self::toggle( 'total[sticky_tablet]',  $t['sticky_tablet'],  __( 'Sticky on Tablet',  'zymarg-cart' ) );
				self::toggle( 'total[sticky_mobile]',  $t['sticky_mobile'],  __( 'Sticky on Mobile',  'zymarg-cart' ), __( 'ON by default. The breakdown slides up as a popup above the sticky bar.', 'zymarg-cart' ) );
				?>
			</div>
		</div>

		<div class="zc-section">
			<div class="zc-section-title"><?php esc_html_e( 'Animation', 'zymarg-cart' ); ?></div>
			<div class="zc-fields">
				<?php
				self::toggle( 'total[animate_breakdown]',    $t['animate_breakdown'],    __( 'Animate Breakdown Panel',      'zymarg-cart' ) );
				self::toggle( 'total[auto_expand_on_select]',$t['auto_expand_on_select'],__( 'Auto-expand on Item Selection','zymarg-cart' ), __( 'Inline mode only. Auto-opens the breakdown when items are selected.', 'zymarg-cart' ) );
				$speed_val = is_array( $t['animation_speed'] ) ? (string) ( $t['animation_speed']['size'] ?? 300 ) : (string) $t['animation_speed'];
				self::number( 'total[animation_speed]', $speed_val, __( 'Animation Speed (ms)', 'zymarg-cart' ), '100', '2000', __( 'Default: 300ms', 'zymarg-cart' ) );
				?>
			</div>
		</div>
		<?php
	}

	// ── Field helpers ─────────────────────────────────────────────────────

	/**
	 * Convert "section[key]" → "section][key" so the final HTML attribute
	 * becomes name="zymarg_cart[section][key]" (properly nested for PHP).
	 *
	 * Without this, name="zymarg_cart[section[key]]" is treated by PHP as
	 * $_POST['zymarg_cart']['section[key]'] (flat, literal string key) rather
	 * than $_POST['zymarg_cart']['section']['key'] (nested array), causing
	 * the sanitizer to find nothing and save every toggle as 'no'.
	 */
	private static function field_name( string $name ): string {
		// "header[show_cart_icon]" → "header][show_cart_icon"
		// Result in HTML: zymarg_cart[header][show_cart_icon]
		return str_replace( '[', '][', $name );
	}

	private static function toggle( string $name, string $value, string $label, string $hint = '' ): void {
		$id      = 'zc_' . md5( $name );
		$checked = 'yes' === $value;
		$fname   = self::field_name( $name );
		?>
		<div class="zc-field zc-field--toggle">
			<label class="zc-toggle-label" for="<?php echo esc_attr( $id ); ?>">
				<div class="zc-toggle-switch">
					<input type="hidden"   name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]" value="0">
					<input type="checkbox" name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
					       id="<?php echo esc_attr( $id ); ?>"
					       value="1"
					       <?php checked( $checked ); ?>>
					<span class="zc-toggle-track"><span class="zc-toggle-thumb"></span></span>
				</div>
				<span class="zc-field-label"><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( $hint ) : ?>
				<p class="zc-field-hint"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function text( string $name, string $value, string $label, string $placeholder = '' ): void {
		$id    = 'zc_' . md5( $name );
		$fname = self::field_name( $name );
		?>
		<div class="zc-field">
			<label class="zc-field-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text"
			       id="<?php echo esc_attr( $id ); ?>"
			       name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
			       value="<?php echo esc_attr( $value ); ?>"
			       placeholder="<?php echo esc_attr( $placeholder ); ?>"
			       class="zc-input">
		</div>
		<?php
	}

	private static function url( string $name, string $value, string $label, string $placeholder = '' ): void {
		$id    = 'zc_' . md5( $name );
		$fname = self::field_name( $name );
		?>
		<div class="zc-field">
			<label class="zc-field-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="url"
			       id="<?php echo esc_attr( $id ); ?>"
			       name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
			       value="<?php echo esc_attr( $value ); ?>"
			       placeholder="<?php echo esc_attr( $placeholder ?: 'https://' ); ?>"
			       class="zc-input">
		</div>
		<?php
	}

	private static function number( string $name, string $value, string $label, string $min, string $max, string $hint = '' ): void {
		$id    = 'zc_' . md5( $name );
		$fname = self::field_name( $name );
		?>
		<div class="zc-field">
			<label class="zc-field-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number"
			       id="<?php echo esc_attr( $id ); ?>"
			       name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
			       value="<?php echo esc_attr( $value ); ?>"
			       min="<?php echo esc_attr( $min ); ?>"
			       max="<?php echo esc_attr( $max ); ?>"
			       class="zc-input zc-input--number">
			<?php if ( $hint ) : ?><p class="zc-field-hint"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private static function select( string $name, string $value, string $label, array $options, string $hint = '' ): void {
		$id    = 'zc_' . md5( $name );
		$fname = self::field_name( $name );
		?>
		<div class="zc-field">
			<label class="zc-field-label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>"
			        name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
			        class="zc-input zc-select">
				<?php foreach ( $options as $opt_val => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $hint ) : ?><p class="zc-field-hint"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private static function radio( string $name, string $value, string $label, array $options ): void {
		$fname = self::field_name( $name );
		?>
		<div class="zc-field">
			<span class="zc-field-label"><?php echo esc_html( $label ); ?></span>
			<div class="zc-radio-group">
				<?php foreach ( $options as $opt_val => $opt_label ) :
					$id = 'zc_' . md5( $name . $opt_val );
					?>
					<label class="zc-radio-label" for="<?php echo esc_attr( $id ); ?>">
						<input type="radio"
						       id="<?php echo esc_attr( $id ); ?>"
						       name="zymarg_cart[<?php echo esc_attr( $fname ); ?>]"
						       value="<?php echo esc_attr( $opt_val ); ?>"
						       <?php checked( $value, $opt_val ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ── Admin CSS ─────────────────────────────────────────────────────────

	private static function admin_css(): string {
		return '
/* ── ZYMARG Cart Admin ───────────────────────────────────────────── */
/* Uses the shared --zym-color-* tokens from zymarg-tokens.css, which is
   enqueued unconditionally on every admin page via enqueue_menu_branding().
   No brand color values are redefined here — only referenced. */
.zc-admin-wrap { max-width: 900px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.zc-notice { padding: 12px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
.zc-notice--success { background: var(--zym-color-success-bg); color: var(--zym-color-success-text); border-left: 4px solid var(--zym-color-success-text); }
.zc-admin-header { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, var(--zym-color-primary) 0%, var(--zym-color-primary-container) 100%); border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; }
.zc-admin-logo { display: flex; align-items: center; gap: 14px; }
.zc-logo-icon { font-size: 28px; }
.zc-logo-title { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
.zc-logo-version { font-size: 11px; color: rgba(255,255,255,0.75); margin-top: 2px; }
.zc-preview-btn { background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); color: #fff; border-radius: 8px; padding: 8px 16px; font-size: 13px; text-decoration: none; font-weight: 500; }
.zc-admin-tabs { display: flex; gap: 4px; margin-bottom: 0; border-bottom: 2px solid var(--zym-color-outline-variant); }
.zc-tab { display: inline-flex; align-items: center; padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--zym-color-text-muted-brand); background: transparent; border: none; border-radius: 8px 8px 0 0; border-bottom: 2px solid transparent; margin-bottom: -2px; cursor: pointer; transition: color .15s, border-color .15s, background .15s; font-family: inherit; line-height: 1; }
.zc-tab:hover { color: var(--zym-color-primary); background: rgba(149,0,165,0.04); }
.zc-tab:focus-visible { outline: 2px solid var(--zym-color-primary); outline-offset: 2px; }
.zc-tab--active { color: var(--zym-color-primary); border-bottom-color: var(--zym-color-primary); font-weight: 600; }
.zc-admin-form { background: #fff; border: 1px solid var(--zym-color-outline-variant); border-top: none; border-radius: 0 0 12px 12px; }
.zc-tab-panel { display: none; padding: 28px 28px 0; }
.zc-tab-panel--active { display: block; animation: zcFadeIn .18s ease; }
@keyframes zcFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
.zc-section { margin-bottom: 28px; }
.zc-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zym-color-primary); margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--zym-color-surface-container); }
.zc-fields { display: flex; flex-direction: column; gap: 10px; }
.zc-field { display: flex; flex-direction: column; gap: 4px; }
.zc-field--toggle { flex-direction: row; align-items: flex-start; flex-wrap: wrap; gap: 8px; }
.zc-field-label { font-size: 13px; font-weight: 600; color: var(--zym-color-text-body); }
.zc-field-hint { font-size: 12px; color: var(--zym-color-outline); margin: 2px 0 0; }
.zc-input { border: 1px solid var(--zym-color-outline-variant); border-radius: 6px; padding: 7px 10px; font-size: 13px; color: var(--zym-color-text-body); background: var(--zym-color-surface-component); width: 100%; max-width: 420px; }
.zc-input:focus { outline: none; border-color: var(--zym-color-primary); box-shadow: 0 0 0 2px rgba(149,0,165,0.1); }
.zc-input--number { max-width: 120px; }
.zc-select { max-width: 280px; }
/* Toggle switch */
.zc-toggle-label { display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
.zc-toggle-switch { position: relative; flex-shrink: 0; }
.zc-toggle-switch input[type="hidden"] { display: none; }
.zc-toggle-switch input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; }
.zc-toggle-track { display: block; width: 40px; height: 22px; background: var(--zym-color-outline-variant); border-radius: 11px; transition: background .2s; position: relative; }
.zc-toggle-thumb { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
.zc-toggle-switch input:checked + .zc-toggle-track { background: var(--zym-color-primary); }
.zc-toggle-switch input:checked + .zc-toggle-track .zc-toggle-thumb { transform: translateX(18px); }
/* Radio */
.zc-radio-group { display: flex; flex-direction: column; gap: 6px; margin-top: 4px; }
.zc-radio-label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--zym-color-text-body); cursor: pointer; }
.zc-radio-label input { accent-color: var(--zym-color-primary); }
/* Save bar */
.zc-save-bar { padding: 20px 28px; border-top: 1px solid var(--zym-color-surface-container); margin-top: 8px; background: var(--zym-color-surface-component); border-radius: 0 0 12px 12px; display: flex; align-items: center; gap: 16px; }
.zc-save-hint { font-size: 12px; color: var(--zym-color-outline); }
.zc-save-btn { background: linear-gradient(135deg, var(--zym-color-primary) 0%, var(--zym-color-primary-container) 100%); color: var(--zym-color-primary-fixed); border: none; border-radius: 8px; padding: 10px 28px; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity .15s; }
.zc-save-btn:hover { opacity: 0.9; }
		';
	}
}
