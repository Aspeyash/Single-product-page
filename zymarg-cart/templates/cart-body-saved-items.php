<?php
/**
 * Frontend template — Save-for-Later section.
 *
 * Renders the "Saved for Later" section that appears below all vendor groups.
 * Loops through saved items and includes cart-body-saved-item-row.php for each.
 *
 * Variables inherited from cart-body.php scope:
 *   @var array $settings      Elementor control values.
 *   @var array $saved_items   Saved items array keyed by item_key.
 *   @var int   $user_id       Current WordPress user ID (0 for guests).
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $saved_items ) ) {
	return;
}

// ── Resolve display values ─────────────────────────────────────────────────
$section_title = ! empty( $settings['saved_section_title'] )
	? esc_html( $settings['saved_section_title'] )
	: esc_html__( 'Saved for Later', 'zymarg-cart' );

$saved_count = count( $saved_items );

// ── Visibility flags ───────────────────────────────────────────────────────
$show_count_badge    = 'yes' === ( $settings['show_saved_count_badge'] ?? 'yes' );
$show_move_btn       = 'yes' === ( $settings['show_move_to_cart_btn']  ?? 'yes' );
$show_remove_btn     = 'yes' === ( $settings['show_remove_saved_btn']  ?? 'yes' );
$show_price_changed  = 'yes' === ( $settings['show_price_changed']     ?? 'yes' );

// Refresh prices + stock for logged-in users before rendering.
if ( $user_id > 0 ) {
	$saved_items = Zymarg_Cart_Usermeta::update_item_prices( $user_id );
}

?>
<div class="zymarg-saved-section"
	data-saved-count="<?php echo esc_attr( (string) $saved_count ); ?>"
	aria-label="<?php esc_attr_e( 'Saved for later items', 'zymarg-cart' ); ?>">

	<div class="zymarg-saved-header">

		<span class="zymarg-saved-title"><?php echo $section_title; ?></span>

		<?php if ( $show_count_badge ) : ?>
			<span class="zymarg-saved-count-badge">
				<?php echo esc_html( (string) $saved_count ); ?>
			</span>
		<?php endif; ?>

	</div>

	<div class="zymarg-saved-items">
		<?php foreach ( $saved_items as $saved_item_key => $saved_item ) :
			include ZYMARG_CART_PATH . 'templates/cart-body-saved-item-row.php';
		endforeach; ?>
	</div>

</div>
<?php
