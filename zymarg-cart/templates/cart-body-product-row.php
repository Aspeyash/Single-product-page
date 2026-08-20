<?php
/**
 * Frontend template — Single Product Row (Container B).
 *
 * Renders one complete product row inside a vendor group. Six column layout
 * on desktop; stacked card on mobile (CSS handles the breakpoint switch).
 *
 * Columns:
 *   1. Checkbox (checkout selection / delete selection in edit mode)
 *   2. Product image (linked to product page)
 *   3. Title (2 lines) + SKU + stock warning + Save for Later
 *   4. Variation dropdown(s) + Quantity stepper
 *   5. Subtotal (unit price × qty) + unit price breakdown
 *   6. Coupon ("Have a coupon?" toggle → input + apply) + feedback
 *
 * Variables inherited from cart-body-vendor-group.php scope:
 *   @var array  $settings         Elementor control values.
 *   @var string $cart_item_key    WC cart item key.
 *   @var array  $item             Product display data from build_item_display_data().
 *   @var int    $vendor_id        Dokan vendor user ID.
 *   @var array  $applied_coupons  Applied coupon data array.
 *
 * Data attributes read by JavaScript modules:
 *   zymarg-cart-checkbox.js   → .zymarg-product-cb value / data-cart-key
 *   zymarg-cart-ajax.js       → data-cart-key on row, stepper, select, buttons
 *   zymarg-cart-edit-mode.js  → .zymarg-edit-mode state on wrapper
 *   zymarg-cart.js            → data-variation-id on select options
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Visibility flags ───────────────────────────────────────────────────────
$show_cb           = 'yes' === ( $settings['show_product_checkbox']   ?? 'yes' );
$show_image        = 'yes' === ( $settings['show_product_image']      ?? 'yes' );
$show_sku          = 'yes' === ( $settings['show_product_sku']        ?? 'yes' );
$show_stock        = 'yes' === ( $settings['show_stock_warning']      ?? 'yes' );
$show_variation    = 'yes' === ( $settings['show_variation_dropdown'] ?? 'yes' );
$show_qty          = 'yes' === ( $settings['show_qty_stepper']        ?? 'yes' );
$show_product_price = 'yes' === ( $settings['show_product_price']     ?? 'yes' ); // v1.2.0 — was show_unit_price (× qty line removed).
$show_coupon       = 'yes' === ( $settings['show_coupon_field']       ?? 'yes' );
$save_later_on     = 'yes' === ( $settings['save_later_enabled']      ?? 'yes' );
$show_save_later   = $save_later_on && 'yes' === ( $settings['show_save_later_btn'] ?? 'yes' );
$show_save_icon    = $save_later_on && 'yes' === ( $settings['show_save_later_icon'] ?? 'yes' );

// ── Item data shorthand ────────────────────────────────────────────────────
$key          = $item['key']            ?? '';
$product_id   = (int) ( $item['product_id']   ?? 0 );
$variation_id = (int) ( $item['variation_id'] ?? 0 );
$quantity     = (int) ( $item['quantity']      ?? 1 );
$is_selected  = (bool) ( $item['is_selected']  ?? true );
$is_in_stock  = (bool) ( $item['is_in_stock']  ?? true );
$low_stock    = (bool) ( $item['low_stock']    ?? false );
$stock_qty    = $item['stock_qty'] ?? null;
$is_saved     = (bool) ( $item['is_saved']     ?? false );

// ── Coupon: find any applied coupons that relate to this product ───────────
$row_coupons = [];
foreach ( $applied_coupons as $coupon_data ) {
	$wc_coupon   = new \WC_Coupon( $coupon_data['code'] );
	$product_ids = $wc_coupon->get_product_ids();
	if ( empty( $product_ids ) || in_array( $product_id, $product_ids, true ) ) {
		$row_coupons[] = $coupon_data;
	}
}

// ── Stock warning message ──────────────────────────────────────────────────
$stock_message = '';
$stock_class   = '';
if ( ! $is_in_stock ) {
	$stock_message = __( 'Out of stock — remove to proceed', 'zymarg-cart' );
	$stock_class   = 'zymarg-out-of-stock';
} elseif ( $low_stock && is_int( $stock_qty ) ) {
	$stock_message = sprintf(
		/* translators: %d: Number of items left. */
		__( 'Only %d left', 'zymarg-cart' ),
		$stock_qty
	);
	$stock_class = 'zymarg-low-stock';
}

// ── Build variation attribute selects ────────────────────────────────────
$attr_selects = [];
if ( $show_variation && $item['is_variable'] && ! empty( $item['available_variations'] ) ) {
	foreach ( $item['available_variations'] as $var ) {
		foreach ( (array) ( $var['attributes'] ?? [] ) as $attr_key => $attr_val ) {
			if ( '' === $attr_val ) {
				continue; // Skip "any" values.
			}
			$clean = sanitize_key( $attr_key );
			if ( ! isset( $attr_selects[ $clean ] ) ) {
				$taxonomy = str_replace( 'attribute_', '', $clean );
				$attr_selects[ $clean ] = [
					'key'     => $attr_key,
					'label'   => wc_attribute_label( $taxonomy ),
					'taxonomy'=> $taxonomy,
					'values'  => [],
					'current' => $item['variation'][ $attr_key ] ?? '',
				];
			}
			if ( ! in_array( $attr_val, $attr_selects[ $clean ]['values'], true ) ) {
				$attr_selects[ $clean ]['values'][] = $attr_val;
			}
		}
	}
}

// Build JSON of all variation data for JS (variation switcher AJAX).
$variations_json = ! empty( $item['available_variations'] )
	? esc_attr( wp_json_encode( $item['available_variations'] ) )
	: '[]';

// ── Save for Later label ───────────────────────────────────────────────────
$save_later_label = ! empty( $settings['save_later_label'] )
	? esc_html( $settings['save_later_label'] )
	: esc_html__( 'Save for later', 'zymarg-cart' );

// ── Have a coupon text ─────────────────────────────────────────────────────
$have_coupon_text = ! empty( $settings['have_coupon_text'] )
	? esc_html( $settings['have_coupon_text'] )
	: esc_html__( 'Have a coupon?', 'zymarg-cart' );

// ── Row CSS classes ────────────────────────────────────────────────────────
$row_classes = [ 'zymarg-product-row' ];
if ( ! $is_in_stock )  { $row_classes[] = 'zymarg-row-out-of-stock'; }
if ( $is_saved )       { $row_classes[] = 'zymarg-row-saved'; }
// v1.3.1 — flag rows whose product is simple (no variation switcher), so CSS
// can re-align the qty stepper (left-center) and subtotal (center-center)
// for a more balanced visual when the variation column is otherwise empty.
if ( empty( $item['is_variable'] ) ) { $row_classes[] = 'zymarg-row-simple'; }

?>
<div class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
	data-cart-key="<?php echo esc_attr( $key ); ?>"
	data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
	data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>"
	data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>"
	data-qty="<?php echo esc_attr( (string) $quantity ); ?>"
	data-variations="<?php echo $variations_json; ?>">

	<?php /* ── Col 1: Checkbox ─────────────────────────────────────────── */ ?>
	<?php if ( $show_cb ) : ?>
		<div class="zymarg-col zymarg-col-cb">
			<input
				type="checkbox"
				class="zymarg-product-cb"
				value="<?php echo esc_attr( $key ); ?>"
				data-cart-key="<?php echo esc_attr( $key ); ?>"
				data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>"
				<?php checked( $is_selected ); ?>
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: Product title. */
					__( 'Select %s', 'zymarg-cart' ),
					$item['product_title'] ?? ''
				) ); ?>"
			>
		</div>
	<?php endif; ?>

	<?php /* ── Col 2: Product image ──────────────────────────────────────── */ ?>
	<?php if ( $show_image ) : ?>
		<div class="zymarg-col zymarg-col-img">
			<a
				href="<?php echo esc_url( $item['product_url'] ?? '#' ); ?>"
				class="zymarg-product-img-link"
				tabindex="-1"
				aria-hidden="true"
			>
				<div class="zymarg-product-img-wrap">
					<img
						src="<?php echo esc_url( $item['product_image'] ?? wc_placeholder_img_src() ); ?>"
						alt="<?php echo esc_attr( $item['product_title'] ?? '' ); ?>"
						loading="lazy"
						class="zymarg-product-img"
					>
				</div>
			</a>
		</div>
	<?php endif; ?>

	<?php /* ── Col 3: Title + SKU + warning + Save for Later ──────────── */ ?>
	<div class="zymarg-col zymarg-col-title">

		<a
			href="<?php echo esc_url( $item['product_url'] ?? '#' ); ?>"
			class="zymarg-product-title"
		><?php echo esc_html( $item['product_title'] ?? '' ); ?></a>

		<?php if ( $show_sku && ! empty( $item['sku'] ) ) : ?>
			<span class="zymarg-product-sku">
				<?php echo esc_html( __( 'SKU: ', 'zymarg-cart' ) . $item['sku'] ); ?>
			</span>
		<?php endif; ?>

		<?php if ( ! empty( $item['variation_labels'] ) ) : ?>
			<span class="zymarg-variation-labels">
				<?php echo esc_html( $item['variation_labels'] ); ?>
			</span>
		<?php endif; ?>

		<?php if ( $show_stock && ! empty( $stock_message ) ) : ?>
			<div class="zymarg-stock-warning <?php echo esc_attr( $stock_class ); ?>"
				 role="alert">
				<?php echo Zymarg_Cart_Helpers::icon( 'alert-triangle' ); ?>
				<?php echo esc_html( $stock_message ); ?>
			</div>
		<?php elseif ( $show_stock ) : ?>
			<div class="zymarg-stock-warning" style="display:none;" aria-live="polite"></div>
		<?php endif; ?>

		<?php if ( $show_save_later ) : ?>
			<button
				type="button"
				class="zymarg-save-later-btn zymarg-save-later-btn--desktop"
				data-cart-key="<?php echo esc_attr( $key ); ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: Product title. */
					__( 'Save %s for later', 'zymarg-cart' ),
					$item['product_title'] ?? ''
				) ); ?>"
			>
				<?php if ( $show_save_icon ) : ?>
					<?php echo Zymarg_Cart_Helpers::icon( 'bookmark' ); ?>
				<?php endif; ?>
				<span><?php echo $save_later_label; ?></span>
			</button>
		<?php endif; ?>

	</div>

	<?php /* ── Col 4: Product price (v1.2.1 — own column on desktop,
	             repositioned under image on mobile via grid-area) ─────── */ ?>
	<?php if ( $show_product_price ) : ?>
		<div class="zymarg-col zymarg-col-price">
			<span class="zymarg-product-price"><?php echo wp_kses_post( $item['unit_price_html'] ?? wc_price( 0 ) ); ?></span>
		</div>
	<?php endif; ?>

	<?php /* ── Col 5: Variation dropdown(s) + Quantity stepper ──────────── */ ?>
	<div class="zymarg-col zymarg-col-variation">

		<?php if ( $show_variation && ! empty( $attr_selects ) ) : ?>
			<div class="zymarg-variation-selects">
				<?php foreach ( $attr_selects as $attr_clean => $attr ) : ?>
					<div class="zymarg-variation-field">
						<label
							for="zymarg-var-<?php echo esc_attr( $key . '-' . $attr_clean ); ?>"
							class="zymarg-variation-label screen-reader-text"
						><?php echo esc_html( $attr['label'] ); ?></label>
						<select
							id="zymarg-var-<?php echo esc_attr( $key . '-' . $attr_clean ); ?>"
							class="zymarg-variation-select"
							data-cart-key="<?php echo esc_attr( $key ); ?>"
							data-attr-key="<?php echo esc_attr( $attr['key'] ); ?>"
							data-prev-val="<?php echo esc_attr( $attr['current'] ); ?>"
							aria-label="<?php echo esc_attr( $attr['label'] ); ?>"
						>
							<?php foreach ( $attr['values'] as $val ) :
								$term = taxonomy_exists( $attr['taxonomy'] )
									? get_term_by( 'slug', $val, $attr['taxonomy'] )
									: false;
								$display = $term instanceof \WP_Term ? $term->name : ucfirst( $val );
							?>
								<option
									value="<?php echo esc_attr( $val ); ?>"
									<?php selected( $attr['current'], $val ); ?>
								><?php echo esc_html( $display ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endforeach; ?>
			</div>
		<?php elseif ( $show_variation && ! $item['is_variable'] && ! empty( $item['variation_labels'] ) ) : ?>
			<div class="zymarg-variation-static">
				<?php echo esc_html( $item['variation_labels'] ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $show_qty ) : ?>
			<div class="zymarg-qty-stepper"
				data-cart-key="<?php echo esc_attr( $key ); ?>"
				data-min="1"
				data-max="<?php echo esc_attr( is_int( $stock_qty ) ? (string) $stock_qty : '99' ); ?>"
				role="group"
				aria-label="<?php esc_attr_e( 'Quantity', 'zymarg-cart' ); ?>">

				<button type="button" class="zymarg-qty-btn zymarg-qty-minus"
					aria-label="<?php esc_attr_e( 'Decrease quantity', 'zymarg-cart' ); ?>"
					<?php disabled( $quantity <= 1 ); ?>>
					<?php echo Zymarg_Cart_Helpers::icon( 'minus' ); ?>
				</button>

				<span class="zymarg-qty-value"
					aria-live="polite"
					aria-label="<?php esc_attr_e( 'Current quantity', 'zymarg-cart' ); ?>">
					<?php echo esc_html( (string) $quantity ); ?>
				</span>

				<button type="button" class="zymarg-qty-btn zymarg-qty-plus"
					aria-label="<?php esc_attr_e( 'Increase quantity', 'zymarg-cart' ); ?>"
					<?php disabled( is_int( $stock_qty ) && $quantity >= $stock_qty ); ?>>
					<?php echo Zymarg_Cart_Helpers::icon( 'plus' ); ?>
				</button>

			</div>
		<?php endif; ?>

	</div>

	<?php /* ── Col 5: Subtotal ──────────────────────────────────────────── */ ?>
	<div class="zymarg-col zymarg-col-subtotal">

		<div class="zymarg-subtotal-amount">
			<?php echo wp_kses_post( $item['subtotal_html'] ?? wc_price( 0 ) ); ?>
		</div>

		<?php /* v1.2.0 — removed the "unit price × qty" breakdown line.
		           The unit price is now shown separately under the title (desktop)
		           and under the image (mobile). */ ?>

	</div>

	<?php /* ── Col 6: Coupon ───────────────────────────────────────────── */ ?>
	<?php if ( $show_coupon ) : ?>
		<div class="zymarg-col zymarg-col-coupon">

			<?php if ( ! empty( $row_coupons ) ) : ?>
				<?php foreach ( $row_coupons as $rc ) : ?>
					<div class="zymarg-applied-coupon-badge"
						data-coupon="<?php echo esc_attr( $rc['code'] ); ?>">
						<?php echo Zymarg_Cart_Helpers::icon( 'discount-2' ); ?>
						<span><?php echo esc_html( $rc['code'] ); ?></span>
						<span class="zymarg-coupon-disc">
							&minus; <?php echo wp_kses_post( $rc['discount_html'] ); ?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<button
				type="button"
				class="zymarg-coupon-toggle zymarg-coupon-toggle--desktop"
				aria-expanded="false"
				aria-controls="zymarg-coupon-form-<?php echo esc_attr( $key ); ?>"
			>
				<?php echo Zymarg_Cart_Helpers::icon( 'tag' ); ?>
				<span><?php echo $have_coupon_text; ?></span>
			</button>

			<div
				id="zymarg-coupon-form-<?php echo esc_attr( $key ); ?>"
				class="zymarg-coupon-form"
				aria-hidden="true"
				data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
				data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>"
			>
				<input
					type="text"
					class="zymarg-coupon-input"
					placeholder="<?php esc_attr_e( 'Coupon code', 'zymarg-cart' ); ?>"
					aria-label="<?php esc_attr_e( 'Enter coupon code', 'zymarg-cart' ); ?>"
					autocomplete="off"
				>
				<button
					type="button"
					class="zymarg-coupon-apply"
					data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
					data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>"
				>
					<?php esc_html_e( 'Apply', 'zymarg-cart' ); ?>
				</button>
			</div>

			<div class="zymarg-coupon-feedback" aria-live="polite" role="status"></div>

		</div>
	<?php endif; ?>

	<?php /* ── Mobile-only: actions row at the bottom of col 3 (v1.2.0)  ── */ ?>
	<?php if ( $show_save_later || $show_coupon ) : ?>
		<div class="zymarg-col zymarg-col-mobile-actions"
			data-has-save="<?php echo $show_save_later ? '1' : '0'; ?>"
			data-has-coupon="<?php echo $show_coupon ? '1' : '0'; ?>">

			<?php if ( $show_save_later ) : ?>
				<button
					type="button"
					class="zymarg-save-later-btn zymarg-save-later-btn--mobile"
					data-cart-key="<?php echo esc_attr( $key ); ?>"
					aria-label="<?php echo esc_attr( sprintf(
						/* translators: %s: Product title. */
						__( 'Save %s for later', 'zymarg-cart' ),
						$item['product_title'] ?? ''
					) ); ?>"
				>
					<?php if ( $show_save_icon ) : ?>
						<?php echo Zymarg_Cart_Helpers::icon( 'bookmark' ); ?>
					<?php endif; ?>
					<span><?php echo $save_later_label; ?></span>
				</button>
			<?php endif; ?>

			<?php if ( $show_coupon ) : ?>
				<button
					type="button"
					class="zymarg-coupon-toggle zymarg-coupon-toggle--mobile"
					aria-expanded="false"
					aria-controls="zymarg-coupon-form-<?php echo esc_attr( $key ); ?>"
				>
					<?php echo Zymarg_Cart_Helpers::icon( 'tag' ); ?>
					<span><?php echo $have_coupon_text; ?></span>
				</button>
			<?php endif; ?>

		</div>
	<?php endif; ?>

</div>
<?php
