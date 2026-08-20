<?php
/**
 * Frontend template — Widget 2 Cart Body (main entry point).
 *
 * Variables from Zymarg_Widget_Cart_Body::render():
 *   @var array  $settings        Elementor control values.
 *   @var array  $grouped_vendors Vendor-grouped cart data from Zymarg_Cart_Dokan.
 *   @var bool   $is_empty        True when WC cart has no items.
 *   @var int    $user_id         Current WordPress user ID (0 for guests).
 *   @var array  $saved_items     Save-for-Later items (session or user meta).
 *   @var array  $applied_coupons Applied WC/Dokan coupons with discount data.
 *
 * Template hierarchy:
 *   cart-body.php
 *     → cart-body-empty.php          (when cart is empty)
 *     → cart-body-vendor-group.php   (one per Dokan vendor)
 *         → cart-body-product-row.php (one per product in vendor)
 *     → cart-body-saved-items.php    (Save-for-Later section)
 *         → cart-body-saved-item-row.php
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Visibility flags ───────────────────────────────────────────────────────
$save_later_enabled  = 'yes' === ( $settings['save_later_enabled']  ?? 'yes' );
$show_saved_below    = 'yes' === ( $settings['show_saved_below_cart'] ?? 'yes' );
$has_saved_items     = ! empty( $saved_items );
$show_coupon_field   = 'yes' === ( $settings['show_coupon_field']    ?? 'yes' );

// ── Show restore-spinner sentinel (checked by JS) ──────────────────────────
Zymarg_Cart_Partial::show_restore_spinner();

?>
<div class="zymarg-cart-body"
	data-widget="cart-body"
	data-coupon="<?php echo $show_coupon_field ? 'yes' : 'no'; ?>"
	data-mobile-bp="<?php echo esc_attr( (int) ( $settings['mobile_breakpoint'] ?? 480 ) ); ?>">

	<?php if ( $is_empty ) : ?>

		<?php include ZYMARG_CART_PATH . 'templates/cart-body-empty.php'; ?>

	<?php else : ?>

		<?php
		foreach ( $grouped_vendors as $vendor_id => $vendor_group ) {
			include ZYMARG_CART_PATH . 'templates/cart-body-vendor-group.php';
		}
		?>

	<?php endif; ?>

	<?php
	// ── Save-for-Later section ─────────────────────────────────────────────
	if ( $save_later_enabled && $show_saved_below && $has_saved_items ) {
		include ZYMARG_CART_PATH . 'templates/cart-body-saved-items.php';
	}
	?>

</div>
<?php
