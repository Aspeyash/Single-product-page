<?php
/**
 * Frontend template — Empty Cart State.
 *
 * Shown when the WC cart has no items. Renders an SVG illustration,
 * a customisable message, and a Continue Shopping button.
 *
 * Variables inherited from cart-body.php scope:
 *   @var array $settings  Elementor control values.
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Resolve display values ─────────────────────────────────────────────────
$show_illus    = 'yes' === ( $settings['show_empty_cart_illus'] ?? 'yes' );
$empty_message = ! empty( $settings['empty_cart_message'] )
	? esc_html( $settings['empty_cart_message'] )
	: esc_html__( 'Your cart is empty.', 'zymarg-cart' );

$btn_text = ! empty( $settings['continue_shopping_text'] )
	? esc_html( $settings['continue_shopping_text'] )
	: esc_html__( 'Continue Shopping', 'zymarg-cart' );

$btn_url_data = $settings['continue_shopping_url'] ?? [];
$btn_url      = ! empty( $btn_url_data['url'] ) ? esc_url( $btn_url_data['url'] ) : esc_url( home_url( '/shop/' ) );
$btn_target   = ! empty( $btn_url_data['is_external'] ) ? '_blank' : '_self';
$btn_rel      = ! empty( $btn_url_data['nofollow'] ) ? 'nofollow' : '';

$sub_message  = esc_html__( "Looks like you haven't added anything yet.", 'zymarg-cart' );

?>
<div class="zymarg-cart-empty" role="status" aria-live="polite">

	<?php if ( $show_illus ) : ?>
		<div class="zymarg-empty-illustration" aria-hidden="true">
			<?php
			// Inline the SVG from the assets folder if it exists, otherwise output inline fallback.
			$svg_path = ZYMARG_CART_PATH . 'assets/images/empty-cart.svg';
			if ( file_exists( $svg_path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo file_get_contents( $svg_path );
			} else {
				// Minimal fallback cart illustration.
				?>
				<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120" fill="none" role="img" aria-hidden="true">
					<circle cx="60" cy="60" r="60" fill="#eaedff"/>
					<path d="M30 38h8l10 34h32l8-24H48" stroke="#9500a5" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
					<circle cx="52" cy="80" r="4" fill="#9500a5"/>
					<circle cx="72" cy="80" r="4" fill="#9500a5"/>
					<path d="M44 50h30" stroke="#9500a5" stroke-width="2.5" stroke-linecap="round"/>
					<path d="M47 58h24" stroke="#9500a5" stroke-width="2.5" stroke-linecap="round"/>
				</svg>
				<?php
			}
			?>
		</div>
	<?php endif; ?>

	<p class="zymarg-empty-message"><?php echo $empty_message; ?></p>
	<p class="zymarg-empty-sub"><?php echo $sub_message; ?></p>

	<a
		href="<?php echo $btn_url; ?>"
		class="zymarg-continue-shopping"
		target="<?php echo esc_attr( $btn_target ); ?>"
		<?php echo $btn_rel ? 'rel="' . esc_attr( $btn_rel ) . '"' : ''; ?>
	>
		<?php echo Zymarg_Cart_Helpers::icon( 'arrow-left' ); ?>
		<?php echo $btn_text; ?>
	</a>

</div>
<?php
