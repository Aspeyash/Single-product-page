<?php
/**
 * Swatch group wrapper template.
 *
 * Wraps the (hidden) native <select> plus the visual swatch list for a single
 * variation attribute. The hidden <select> MUST stay in the DOM so
 * wc-add-to-cart-variation.js keeps reading/writing attribute values.
 *
 * @var string               $html       Native WooCommerce <select> HTML.
 * @var string               $attribute  Attribute name e.g. 'pa_color'.
 * @var \WC_Product           $product    The product object.
 * @var string               $type       color|image|label|button.
 * @var string               $items_html Rendered <li> swatch items.
 * @var array<string,mixed>  $opts       Presentation options.
 *
 * @version 1.1.1
 * @package ZymargSingleProduct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opts              = isset( $opts ) && is_array( $opts ) ? $opts : array();
$shape             = $opts['shape'] ?? 'rounded';
$oos               = $opts['oos'] ?? 'blur';
$size              = $opts['size'] ?? '44px';
$tooltip           = ! empty( $opts['tooltip'] );
$tooltip_pos       = $opts['tooltip_pos'] ?? 'top';
$show_clear        = ! empty( $opts['show_clear'] );
$clear_label       = $opts['clear_label'] ?? __( 'Clear', 'zymarg-single-product' );
$show_attr_label   = ! empty( $opts['show_attr_label'] );
$show_selected_val = ! empty( $opts['show_selected_val'] );

$attr_label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attribute, $product ) : $attribute;

$ul_classes = array(
	'zymarg-sp-swatches',
	'zymarg-sp-swatches--' . sanitize_html_class( $type ),
	'zsp-shape--' . sanitize_html_class( $shape ),
	'zsp-oos--' . sanitize_html_class( $oos ),
);
if ( $tooltip ) {
	$ul_classes[] = 'zsp-has-tooltip';
	$ul_classes[] = 'zsp-tooltip--' . sanitize_html_class( $tooltip_pos );
}
?>
<div class="zymarg-sp-swatch-wrap" data-attribute="<?php echo esc_attr( $attribute ); ?>" data-type="<?php echo esc_attr( $type ); ?>" style="--zsp-swatch-size:<?php echo esc_attr( $size ); ?>;">

	<?php if ( $show_attr_label ) : ?>
		<div class="zymarg-sp-swatch-heading">
			<span class="zymarg-sp-swatch-attr-label"><?php echo esc_html( $attr_label ); ?></span>
			<?php // v1.0.8 #3 — always render the selected-value readout beside the label so it appears on selection. ?>
			<span class="zymarg-sp-swatch-selected-val" data-attribute="<?php echo esc_attr( $attribute ); ?>"></span>
		</div>
	<?php endif; ?>

	<?php // Hidden native select — required by WooCommerce variation JS. ?>
	<div class="zymarg-sp-select-hidden" aria-hidden="true">
		<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<fieldset class="zymarg-sp-swatch-fieldset">
		<legend class="screen-reader-text"><?php echo esc_html( $attr_label ); ?></legend>
		<ul class="<?php echo esc_attr( implode( ' ', $ul_classes ) ); ?>" role="radiogroup" aria-label="<?php echo esc_attr( $attr_label ); ?>">
			<?php echo $items_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</ul>
		<?php // v1.1.0 - Clear link removed; deselect by clicking the selected swatch again. ?>
	</fieldset>
</div>
