<?php
/**
 * Frontend template — Single Saved-for-Later Item Row.
 *
 * Renders one saved item card with: product image, title + variation labels,
 * price (with "price changed" badge when applicable), stock status warning,
 * a "Move to Cart" button, and a "Remove" button.
 *
 * Variables inherited from cart-body-saved-items.php scope:
 *   @var array  $settings          Elementor control values.
 *   @var string $saved_item_key    Save-for-Later item key (MD5 hash).
 *   @var array  $saved_item        Saved item data array.
 *   @var int    $user_id           Current WordPress user ID (0 for guests).
 *   @var bool   $show_move_btn     Whether to show the Move to Cart button.
 *   @var bool   $show_remove_btn   Whether to show the Remove button.
 *   @var bool   $show_price_changed Whether to show the price-changed badge.
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Resolve saved item data ────────────────────────────────────────────────
$s_product_id   = (int) ( $saved_item['product_id']   ?? 0 );
$s_variation_id = (int) ( $saved_item['variation_id'] ?? 0 );
$s_quantity     = (int) ( $saved_item['quantity']      ?? 1 );
$s_variation    = (array) ( $saved_item['variation']   ?? [] );
$s_saved_price  = (float) ( $saved_item['saved_price'] ?? 0.0 );
$s_curr_price   = (float) ( $saved_item['current_price'] ?? $s_saved_price );
$s_price_changed = (bool) ( $saved_item['price_changed']  ?? false );

if ( $s_product_id <= 0 ) {
	return;
}

// ── Fetch product display data ─────────────────────────────────────────────
$display = Zymarg_Cart_Helpers::get_product_display_data( $s_product_id, $s_variation_id );

if ( empty( $display ) ) {
	return;
}

$s_title           = $display['title']       ?? '';
$s_url             = $display['permalink']   ?? '#';
$s_image_url       = $display['image_url']   ?? wc_placeholder_img_src( 'woocommerce_thumbnail' );
$s_sku             = $display['sku']         ?? '';
$s_is_in_stock     = $display['is_in_stock'] ?? true;
$s_stock_qty       = $display['stock_qty']   ?? null;
$s_is_purchasable  = $display['is_purchasable'] ?? true;

// ── Variation labels ───────────────────────────────────────────────────────
$s_var_labels = ! empty( $s_variation )
	? Zymarg_Cart_Helpers::format_variation_labels( $s_variation )
	: '';

// ── Label values ──────────────────────────────────────────────────────────
$move_label = ! empty( $settings['move_to_cart_label'] )
	? esc_html( $settings['move_to_cart_label'] )
	: esc_html__( 'Move to Cart', 'zymarg-cart' );

$remove_label = esc_html__( 'Remove', 'zymarg-cart' );

// ── Row CSS classes ────────────────────────────────────────────────────────
$row_classes = [ 'zymarg-saved-item-row' ];
if ( ! $s_is_in_stock )   { $row_classes[] = 'zymarg-saved-out-of-stock'; }
if ( ! $s_is_purchasable ){ $row_classes[] = 'zymarg-saved-unpurchasable'; }

?>
<div class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
	data-item-key="<?php echo esc_attr( $saved_item_key ); ?>"
	data-product-id="<?php echo esc_attr( (string) $s_product_id ); ?>">

	<?php /* ── Product image ──────────────────────────────────────────── */ ?>
	<a href="<?php echo esc_url( $s_url ); ?>" class="zymarg-saved-img-link" tabindex="-1" aria-hidden="true">
		<div class="zymarg-saved-img-wrap">
			<img
				src="<?php echo esc_url( $s_image_url ); ?>"
				alt="<?php echo esc_attr( $s_title ); ?>"
				loading="lazy"
				class="zymarg-saved-img"
			>
		</div>
	</a>

	<?php /* ── Product info ─────────────────────────────────────────────── */ ?>
	<div class="zymarg-saved-info">

		<a href="<?php echo esc_url( $s_url ); ?>" class="zymarg-saved-title">
			<?php echo esc_html( $s_title ); ?>
		</a>

		<?php if ( ! empty( $s_var_labels ) ) : ?>
			<span class="zymarg-saved-var-labels"><?php echo esc_html( $s_var_labels ); ?></span>
		<?php endif; ?>

		<?php /* Price + change badge */ ?>
		<div class="zymarg-saved-price-wrap">
			<span class="zymarg-saved-price">
				<?php echo wp_kses_post( wc_price( $s_curr_price ) ); ?>
			</span>
			<?php if ( $show_price_changed && $s_price_changed ) : ?>
				<span class="zymarg-price-changed-badge"
					title="<?php echo esc_attr( sprintf(
						/* translators: %s: Original price. */
						__( 'Was %s', 'zymarg-cart' ),
						strip_tags( wc_price( $s_saved_price ) )
					) ); ?>">
					<?php echo Zymarg_Cart_Helpers::icon( 'trending-up' ); ?>
					<?php esc_html_e( 'Price changed', 'zymarg-cart' ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php /* Qty saved */ ?>
		<?php if ( $s_quantity > 1 ) : ?>
			<span class="zymarg-saved-qty">
				<?php echo esc_html( sprintf(
					/* translators: %d: Quantity. */
					__( 'Qty: %d', 'zymarg-cart' ),
					$s_quantity
				) ); ?>
			</span>
		<?php endif; ?>

		<?php /* Stock warning for saved items */ ?>
		<?php if ( ! $s_is_in_stock ) : ?>
			<div class="zymarg-stock-warning zymarg-out-of-stock" role="alert">
				<?php echo Zymarg_Cart_Helpers::icon( 'alert-triangle' ); ?>
				<?php esc_html_e( 'Out of stock', 'zymarg-cart' ); ?>
			</div>
		<?php endif; ?>

	</div>

	<?php /* ── Action buttons ─────────────────────────────────────────── */ ?>
	<div class="zymarg-saved-actions">

		<?php if ( $show_move_btn ) : ?>
			<button
				type="button"
				class="zymarg-move-to-cart-btn"
				data-item-key="<?php echo esc_attr( $saved_item_key ); ?>"
				<?php disabled( ! $s_is_in_stock || ! $s_is_purchasable ); ?>
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: Product title. */
					__( 'Move %s to cart', 'zymarg-cart' ),
					$s_title
				) ); ?>"
			>
				<?php echo Zymarg_Cart_Helpers::icon( 'shopping-cart-plus' ); ?>
				<span><?php echo $move_label; ?></span>
			</button>
		<?php endif; ?>

		<?php if ( $show_remove_btn ) : ?>
			<button
				type="button"
				class="zymarg-remove-saved-btn"
				data-item-key="<?php echo esc_attr( $saved_item_key ); ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: Product title. */
					__( 'Remove %s from saved list', 'zymarg-cart' ),
					$s_title
				) ); ?>"
			>
				<?php echo Zymarg_Cart_Helpers::icon( 'x' ); ?>
				<span class="zymarg-remove-saved-label"><?php echo $remove_label; ?></span>
			</button>
		<?php endif; ?>

	</div>

</div>
<?php
