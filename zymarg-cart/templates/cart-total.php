<?php
/**
 * Frontend template — Widget 3 Cart Total.
 *
 * Layout:
 *   ┌──────────────────────────────────────────────────┐
 *   │  [^] Order Summary (n items)         ৳ XX.XX    │  ← Subtotal bar (always visible, clickable)
 *   ├──────────────────────────────────────────────────┤
 *   │  Subtotal                            ৳ XX.XX    │
 *   │  Discount (COUPONCODE)             − ৳ XX.XX    │  ← Part A: Breakdown panel
 *   │  Shipping                            ৳ XX.XX    │     (hidden by default, slides on click)
 *   │  VAT                                 ৳ XX.XX    │  ← Hidden by default since v1.4.4
 *   │  ──────────────────────────────────────────────  │
 *   │  Grand Total                         ৳ XX.XX    │
 *   ├──────────────────────────────────────────────────┤
 *   │  [✓] 4 of 4 selected   ৳ XX.XX   [Checkout →]   │  ← Part B: Action bar (always visible)
 *   └──────────────────────────────────────────────────┘
 *
 * Variables from Zymarg_Widget_Cart_Total::render():
 *   @var array  $settings    Elementor control values.
 *   @var array  $totals      Cart totals from Zymarg_Cart_Dokan::get_totals_for_selected([]).
 *   @var int    $item_count  Total WC cart item count.
 *
 * CSS classes updated in real time by ZymargAjax.applyTotals():
 *   .zymarg-total-row--subtotal .zymarg-total-value
 *   .zymarg-total-row--discount                          (shown/hidden based on discount > 0)
 *   .zymarg-total-row--discount .zymarg-total-value
 *   .zymarg-total-row--shipping .zymarg-total-value
 *   .zymarg-total-row--tax      .zymarg-total-value
 *   .zymarg-total-row--grand    .zymarg-total-value
 *   .zymarg-action-grand-total
 *   .zymarg-selected-label
 *   .zymarg-subtotal-bar-amount
 *   .zymarg-applied-coupons
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Resolve totals (safe defaults when cart is empty / unavailable) ─────────
$subtotal      = (float)  ( $totals['subtotal']            ?? 0.0 );
$discount      = (float)  ( $totals['discount']            ?? 0.0 );
$shipping      = (float)  ( $totals['shipping']            ?? 0.0 );
$tax           = (float)  ( $totals['tax']                 ?? 0.0 );
$grand_total   = (float)  ( $totals['grand_total']         ?? 0.0 );
$subtotal_html = wp_kses_post( $totals['subtotal_html']    ?? wc_price( 0 ) );
$discount_html = wp_kses_post( $totals['discount_html']    ?? wc_price( 0 ) );
$shipping_html = wp_kses_post( $totals['shipping_html']    ?? __( 'Calculated at checkout', 'zymarg-cart' ) );
$tax_html      = wp_kses_post( $totals['tax_html']         ?? wc_price( 0 ) );
$grand_html    = wp_kses_post( $totals['grand_total_html'] ?? wc_price( 0 ) );
$coupons       = (array)  ( $totals['coupons']             ?? [] );
$ship_calc     = (bool)   ( $totals['shipping_calculated'] ?? false );
$selected_count = (int)   ( $totals['selected_count']      ?? $item_count );

// ── Resolve settings with safe defaults ─────────────────────────────────────
$show_subtotal_bar   = 'yes' === ( $settings['show_subtotal_bar']     ?? 'yes' );
$show_bar_arrow      = true; // Always show arrow icon regardless of Elementor setting (v1.0.8).
$show_subtotal_line  = 'yes' === ( $settings['show_subtotal_line']    ?? 'yes' );
$show_discount_line  = 'yes' === ( $settings['show_discount_line']    ?? 'yes' );
$show_shipping_line  = 'yes' === ( $settings['show_shipping_line']    ?? 'yes' );
$show_vendor_ship    = 'yes' === ( $settings['show_shipping_per_vendor'] ?? 'yes' );
$show_tax_line       = 'yes' === ( $settings['show_tax_line']         ?? 'yes' );
$show_panel_grand    = 'yes' === ( $settings['show_panel_grand']      ?? 'yes' );
$show_divider        = 'yes' === ( $settings['show_divider']          ?? 'yes' );
$show_master_cb      = 'yes' === ( $settings['show_master_cb']        ?? 'yes' );
$show_select_label   = 'yes' === ( $settings['show_select_label']     ?? 'yes' );
$show_selected_count = 'yes' === ( $settings['show_selected_count']   ?? 'yes' );
$show_action_grand   = 'yes' === ( $settings['show_action_grand']     ?? 'yes' );
$show_grand_label    = 'yes' === ( $settings['show_action_grand_label'] ?? 'yes' );
$show_checkout_btn   = 'yes' === ( $settings['show_checkout_btn']     ?? 'yes' );
$show_checkout_icon  = 'yes' === ( $settings['show_checkout_icon']    ?? 'yes' );

// ── Sticky mode (v1.5.0) — responsive D / T / M opt-in toggles ───────────
$sticky_desktop = 'yes' === ( $settings['sticky_desktop'] ?? 'no'  );
$sticky_tablet  = 'yes' === ( $settings['sticky_tablet']  ?? 'no'  );
$sticky_mobile  = 'yes' === ( $settings['sticky_mobile']  ?? 'yes' );

// ── Auto-expand-on-select toggle (v1.5.0) — inline mode only ─────────────
$auto_expand_on_select = 'yes' === ( $settings['auto_expand_on_select'] ?? 'yes' );

// ── Final note text (v1.5.0) — shown in inline breakdown AND sticky popup ─
$show_final_note = 'yes' === ( $settings['popup_final_note_show'] ?? 'yes' );
$final_note_text = isset( $settings['popup_final_note_text'] )
	? (string) $settings['popup_final_note_text']
	: __( 'Final price displayed at checkout', 'zymarg-cart' );

$animate            = 'yes' === ( $settings['animate_breakdown']     ?? 'yes' );
$speed              = (int)  ( $settings['animation_speed']['size']  ?? 300 );
$open_default       = false; // Always collapsed on load; auto-expands when items are selected (v1.0.7).
$btn_loading_on     = 'yes' === ( $settings['checkout_btn_loading']  ?? 'yes' );

// ── Text labels ───────────────────────────────────────────────────────────
$order_summary_text = ! empty( $settings['order_summary_text'] )
	? esc_html( $settings['order_summary_text'] )
	: esc_html__( 'Order Summary', 'zymarg-cart' );

/**
 * Filters the default tax line label.
 * See Zymarg_Cart::build_localized_data() for documentation.
 *
 * @since 1.1.0
 */
$tax_label_default = (string) apply_filters(
	'zymarg_cart_tax_label',
	__( 'VAT', 'zymarg-cart' )
);
$tax_label_text = ! empty( $settings['tax_label_text'] )
	? esc_html( $settings['tax_label_text'] )
	: esc_html( $tax_label_default );

$grand_label_text = ! empty( $settings['grand_total_label_text'] )
	? esc_html( $settings['grand_total_label_text'] )
	: esc_html__( 'Grand Total', 'zymarg-cart' );

// Keep both raw and escaped forms of the checkout button label.
// The aria-label below combines the raw value with the price-stripped grand
// total, then escapes the COMBINED string once via esc_attr — pre-escaping
// either part would result in double-escaping (e.g. "M&M's" → "M&amp;amp;M…").
$checkout_btn_text_raw = ! empty( $settings['checkout_btn_text'] )
	? (string) $settings['checkout_btn_text']
	: __( 'Proceed to Checkout', 'zymarg-cart' );
$checkout_btn_text = esc_html( $checkout_btn_text_raw );

// ── Selected label: "n of n selected" ───────────────────────────────────
$selected_label = sprintf(
	/* translators: 1: Selected count, 2: Total count. */
	__( '%1$d of %2$d selected', 'zymarg-cart' ),
	$selected_count,
	$item_count
);

// ── Per-vendor shipping (if toggle enabled) ───────────────────────────────
$vendor_shipping_rows = [];
if ( $show_vendor_ship && $ship_calc ) {
	$vendor_ids = Zymarg_Cart_Dokan::get_cart_vendor_ids();
	foreach ( $vendor_ids as $vid ) {
		$ship = Zymarg_Cart_Dokan::get_vendor_shipping( $vid );
		if ( 'calculated' === $ship['status'] && $ship['cost'] > 0.0 ) {
			$info = Zymarg_Cart_Dokan::get_vendor_info( $vid );
			$vendor_shipping_rows[] = [
				'label' => wp_strip_all_tags( $info['store_name'] ?? '' ),
				'html'  => $ship['html'],
			];
		}
	}
}

// ── CSS class for breakdown panel open/closed ─────────────────────────────
$panel_class = 'zymarg-breakdown-panel';
if ( $open_default ) {
	$panel_class .= ' breakdown-open';
}

?>
<div class="zymarg-cart-total"
	data-widget="cart-total"
	data-animate="<?php echo $animate ? '1' : '0'; ?>"
	data-speed="<?php echo esc_attr( (string) $speed ); ?>"
	data-item-count="<?php echo esc_attr( (string) $item_count ); ?>"
	data-sticky-desktop="<?php echo $sticky_desktop ? 'yes' : 'no'; ?>"
	data-sticky-tablet="<?php echo $sticky_tablet ? 'yes' : 'no'; ?>"
	data-sticky-mobile="<?php echo $sticky_mobile ? 'yes' : 'no'; ?>"
	data-auto-expand="<?php echo $auto_expand_on_select ? 'yes' : 'no'; ?>">

	<?php /* ── Subtotal bar (always visible, clickable) ────────────────── */ ?>
	<?php if ( $show_subtotal_bar ) : ?>
		<div class="zymarg-subtotal-bar"
			role="button"
			tabindex="0"
			aria-expanded="<?php echo $open_default ? 'true' : 'false'; ?>"
			aria-controls="zymarg-breakdown-panel"
			aria-label="<?php esc_attr_e( 'Toggle order summary', 'zymarg-cart' ); ?>">

			<div class="zymarg-subtotal-bar-left">
				<?php if ( $show_bar_arrow ) : ?>
					<?php /* Inline SVG chevron — baseline points UP (collapsed state).
					        CSS rotates 180° when .breakdown-arrow-open is added → points DOWN (expanded).
					        v1.3.0: switched from a hand-rolled SVG to Helpers::icon('chevron-up') so
					        all icons go through the same helper. The .zymarg-breakdown-arrow class
					        is preserved on the wrapper so existing widget controls (rotation,
					        color, font-size) keep working. */ ?>
					<?php echo Zymarg_Cart_Helpers::icon( 'chevron-up', 'zymarg-breakdown-arrow' ); ?>
				<?php endif; ?>
				<span class="zymarg-subtotal-bar-label">
					<?php echo $order_summary_text; ?>
					<?php if ( $show_selected_count && $item_count > 0 ) : ?>
						<span class="zymarg-subtotal-bar-count" aria-live="polite">
							(<?php echo esc_html( $selected_label ); ?>)
						</span>
					<?php endif; ?>
				</span>
			</div>

			<div class="zymarg-subtotal-bar-right">
				<span class="zymarg-subtotal-bar-amount" aria-live="polite">
					<?php echo $subtotal_html; ?>
				</span>
			</div>

		</div>
	<?php endif; ?>

	<?php /* ── Part A: Breakdown panel (hidden by default) ───────────────── */ ?>
	<div id="zymarg-breakdown-panel"
		class="<?php echo esc_attr( $panel_class ); ?>"
		data-open="<?php echo $open_default ? '1' : '0'; ?>"
		aria-hidden="<?php echo $open_default ? 'false' : 'true'; ?>">

		<div class="zymarg-breakdown-inner">

			<?php /* Breakdown rows extracted to a partial (v1.5.0) so the sticky
			        popup can render the exact same content. AJAX updates target
			        rows by class, so both copies stay in sync automatically. */ ?>
			<?php include ZYMARG_CART_PATH . 'templates/cart-total-breakdown-rows.php'; ?>

			<?php /* Divider + in-breakdown Grand Total removed in v1.1.2 — */
			/* the Grand Total in the action bar is the only one shown now. */
			/* Elementor controls $show_divider, $show_panel_grand, and    */
			/* grand_total_label_text are kept in the widget but no longer */
			/* render anything here.                                       */ ?>

		</div>
	</div>

	<?php /* ── Part B: Action bar (always visible) ───────────────────────── */ ?>
	<div class="zymarg-action-bar">

		<?php /* Master checkbox + selected count */ ?>
		<div class="zymarg-master-select">

			<?php if ( $show_master_cb ) : ?>
				<input
					type="checkbox"
					class="zymarg-master-cb"
					id="zymarg-master-cb"
					checked
					aria-label="<?php esc_attr_e( 'Select all items', 'zymarg-cart' ); ?>"
				>
				<label for="zymarg-master-cb" class="screen-reader-text">
					<?php esc_html_e( 'Select all items', 'zymarg-cart' ); ?>
				</label>
			<?php endif; ?>

			<?php if ( $show_select_label ) : ?>
				<span class="zymarg-selected-label" aria-live="polite">
					<?php echo esc_html( $selected_label ); ?>
				</span>
			<?php endif; ?>

		</div>

		<?php /* Grand total amount */ ?>
		<?php if ( $show_action_grand ) : ?>
			<div class="zymarg-action-grand-wrap">

				<?php if ( $show_grand_label ) : ?>
					<span class="zymarg-action-grand-label"><?php echo $grand_label_text; ?></span>
				<?php endif; ?>

				<span class="zymarg-action-grand-total" aria-live="polite">
					<?php echo $grand_html; ?>
				</span>

			</div>
		<?php endif; ?>

		<?php /* Checkout button */ ?>
		<?php if ( $show_checkout_btn ) : ?>
			<button
				type="button"
				class="zymarg-checkout-btn"
				data-loading="<?php echo $btn_loading_on ? '1' : '0'; ?>"
				aria-label="<?php echo esc_attr( $checkout_btn_text_raw . ' — ' . wp_strip_all_tags( $grand_html ) ); ?>"
			>
				<?php if ( $show_checkout_icon ) : ?>
					<?php echo Zymarg_Cart_Helpers::icon( 'lock', 'zymarg-btn-icon' ); ?>
				<?php endif; ?>
				<span class="zymarg-btn-label"><?php echo $checkout_btn_text; ?></span>
				<?php echo Zymarg_Cart_Helpers::icon( 'arrow-right', 'zymarg-btn-arrow' ); ?>
				<span class="zymarg-btn-spinner" aria-hidden="true"></span>
			</button>
		<?php endif; ?>

	</div>

	<?php /* ── Sticky popup (v1.5.0) ─────────────────────────────────────── */ ?>
	<?php /* Rendered once, sibling of the action bar inside the widget wrapper.
	        Shown only when sticky mode is active for the current breakpoint
	        and the user taps the subtotal bar. JS toggles its visibility.
	        Same breakdown rows as the inline panel; AJAX updates auto-sync
	        because applyTotals() targets rows by CSS class.

	        Note (v1.5.1): the `hidden` HTML attribute is intentionally NOT
	        used here. CSS (visibility + opacity) handles show/hide so the
	        transition can animate. `aria-hidden` is the a11y signal. */ ?>
	<div class="zymarg-sticky-popup"
		id="zymarg-sticky-popup"
		role="region"
		aria-label="<?php echo esc_attr( $order_summary_text ); ?>"
		aria-hidden="true">
		<div class="zymarg-sticky-popup-inner">
			<div class="zymarg-sticky-popup-header"><?php echo esc_html( $order_summary_text ); ?></div>
			<div class="zymarg-sticky-popup-body">
				<?php include ZYMARG_CART_PATH . 'templates/cart-total-breakdown-rows.php'; ?>
			</div>
		</div>
	</div>

</div>
<?php
