<?php
/**
 * Breakdown rows partial — used by Widget 3 (Cart Total).
 *
 * Rendered in two places:
 *   1. Inside the inline breakdown panel (.zymarg-breakdown-panel)
 *   2. Inside the sticky popup (.zymarg-sticky-popup)
 *
 * Because the AJAX layer (ZymargAjax.applyTotals) targets rows by CSS class
 * (e.g. `.zymarg-total-row--subtotal .zymarg-total-value`), both copies of
 * this partial stay in sync automatically — no extra wiring required.
 *
 * Variables expected from the parent template:
 *   @var bool   $show_subtotal_line
 *   @var bool   $show_discount_line
 *   @var bool   $show_shipping_line
 *   @var bool   $show_vendor_ship
 *   @var bool   $show_tax_line
 *   @var bool   $show_final_note
 *   @var float  $discount
 *   @var string $subtotal_html
 *   @var string $discount_html
 *   @var string $shipping_html
 *   @var string $tax_html
 *   @var string $tax_label_text
 *   @var string $final_note_text
 *   @var array  $coupons
 *   @var array  $vendor_shipping_rows
 *
 * @package ZymargCart
 * @since   1.5.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php /* Subtotal row */ ?>
<?php if ( $show_subtotal_line ) : ?>
	<div class="zymarg-total-row zymarg-total-row--subtotal">
		<span class="zymarg-total-label"><?php esc_html_e( 'Subtotal', 'zymarg-cart' ); ?></span>
		<span class="zymarg-total-value" aria-live="polite"><?php echo $subtotal_html; ?></span>
	</div>
<?php endif; ?>

<?php /* Discount row — hidden when no discount, shown by JS when coupon applied */ ?>
<?php if ( $show_discount_line ) : ?>
	<div class="zymarg-total-row zymarg-total-row--discount"
		<?php echo $discount <= 0 ? 'style="display:none;"' : ''; ?>>
		<span class="zymarg-total-label">
			<?php esc_html_e( 'Discount', 'zymarg-cart' ); ?>
			<?php foreach ( $coupons as $c ) : ?>
				<span class="zymarg-coupon-code-badge">(<?php echo esc_html( $c['code'] ); ?>)</span>
			<?php endforeach; ?>
		</span>
		<span class="zymarg-total-value" aria-live="polite">
			<?php if ( $discount > 0 ) : ?>
				<span class="zymarg-discount-prefix" aria-hidden="true">&minus;&nbsp;</span><?php echo $discount_html; ?>
			<?php endif; ?>
		</span>
	</div>
<?php endif; ?>

<?php /* Shipping row — per vendor or combined */ ?>
<?php if ( $show_shipping_line ) : ?>
	<?php if ( $show_vendor_ship && ! empty( $vendor_shipping_rows ) ) : ?>
		<?php foreach ( $vendor_shipping_rows as $vsr ) : ?>
			<div class="zymarg-total-row zymarg-total-row--shipping zymarg-total-row--vendor-ship">
				<span class="zymarg-total-label">
					<?php echo esc_html( sprintf(
						/* translators: %s: Store name. */
						__( 'Shipping (%s)', 'zymarg-cart' ),
						$vsr['label']
					) ); ?>
				</span>
				<span class="zymarg-total-value"><?php echo wp_kses_post( $vsr['html'] ); ?></span>
			</div>
		<?php endforeach; ?>
	<?php else : ?>
		<div class="zymarg-total-row zymarg-total-row--shipping">
			<span class="zymarg-total-label"><?php esc_html_e( 'Shipping', 'zymarg-cart' ); ?></span>
			<span class="zymarg-total-value" aria-live="polite"><?php echo $shipping_html; ?></span>
		</div>
	<?php endif; ?>
<?php endif; ?>

<?php /* Tax row */ ?>
<?php if ( $show_tax_line ) : ?>
	<div class="zymarg-total-row zymarg-total-row--tax">
		<span class="zymarg-total-label"><?php echo $tax_label_text; ?></span>
		<span class="zymarg-total-value" aria-live="polite"><?php echo $tax_html; ?></span>
	</div>
<?php endif; ?>

<?php /* Applied coupons list (updated by ZymargAjax.applyTotals) */ ?>
<div class="zymarg-applied-coupons" aria-live="polite">
	<?php foreach ( $coupons as $c ) : ?>
		<div class="zymarg-applied-coupon" data-coupon="<?php echo esc_attr( $c['code'] ); ?>">
			<span class="zymarg-coupon-code"><?php echo esc_html( $c['code'] ); ?></span>
			<span class="zymarg-coupon-disc">&minus;&nbsp;<?php echo wp_kses_post( $c['discount_html'] ); ?></span>
			<button type="button" class="zymarg-remove-coupon"
				data-coupon="<?php echo esc_attr( $c['code'] ); ?>"
				aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s: Coupon code. */
					__( 'Remove coupon %s', 'zymarg-cart' ),
					$c['code']
				) ); ?>">
				<?php echo Zymarg_Cart_Helpers::icon( 'x' ); ?>
			</button>
		</div>
	<?php endforeach; ?>
</div>

<?php /* Final note (right-aligned) — since v1.5.0 */ ?>
<?php if ( $show_final_note && '' !== trim( $final_note_text ) ) : ?>
	<div class="zymarg-total-row zymarg-total-row--final-note">
		<span class="zymarg-final-note"><?php echo esc_html( $final_note_text ); ?></span>
	</div>
<?php endif; ?>
