<?php
/**
 * Label / Button swatch item template (button is a styled label).
 *
 * @var string              $value       Option slug/value.
 * @var array<string,mixed> $swatch      Swatch data.
 * @var string              $attribute   Attribute name e.g. 'pa_size'.
 * @var bool                $is_selected Whether this option is selected.
 * @var bool                $is_first_focusable Roving-tabindex seed.
 *
 * @version 1.0.2
 * @package ZymargSingleProduct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_available = (bool) ( $swatch['is_available'] ?? true );
$is_selected  = (bool) ( $swatch['is_selected'] ?? false );
$label        = (string) ( $swatch['label'] ?? $value );
$type         = (string) ( $swatch['type'] ?? 'label' );

$classes = array( 'zymarg-sp-swatch', 'zymarg-sp-swatch--label' );
if ( 'button' === $type ) {
	$classes[] = 'zymarg-sp-swatch--button';
}
if ( $is_selected ) {
	$classes[] = 'selected';
}
if ( ! $is_available ) {
	$classes[] = 'disabled';
}

$tabindex = ( $is_selected || ! empty( $is_first_focusable ) ) ? '0' : '-1';
?>
<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	role="radio"
	aria-label="<?php echo esc_attr( $label ); ?>"
	aria-checked="<?php echo $is_selected ? 'true' : 'false'; ?>"
	aria-disabled="<?php echo $is_available ? 'false' : 'true'; ?>"
	tabindex="<?php echo esc_attr( $tabindex ); ?>"
	data-attribute="<?php echo esc_attr( $attribute ); ?>"
	data-value="<?php echo esc_attr( $value ); ?>"
	data-label="<?php echo esc_attr( $label ); ?>"
	title="<?php echo esc_attr( $label ); ?>">
	<span class="zymarg-sp-swatch-label-text"><?php echo esc_html( $label ); ?></span>
	<span class="zymarg-sp-swatch-tip" aria-hidden="true"><?php echo esc_html( $label ); ?></span>
</li>
