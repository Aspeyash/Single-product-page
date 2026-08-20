<?php
/**
 * Frontend template for Widget 1 — Cart Header.
 *
 * Variables available from Zymarg_Cart_Page::render_cart_header():
 *   $settings   array   Elementor control values (from get_settings_for_display).
 *   $item_count int     Live WooCommerce cart item count.
 *
 * Data attributes read by zymarg-cart-edit-mode.js:
 *   data-edit-label   — label for the button when NOT in edit mode.
 *   data-done-label   — label for the button WHEN in edit mode.
 *   data-confirm      — '1' when confirmation dialog is enabled.
 *   data-confirm-text — confirmation message text.
 *
 * CSS classes toggled by JavaScript:
 *   .zymarg-edit-mode  added to .zymarg-cart-wrapper (parent) by edit-mode JS.
 *
 * @package ZymargCart
 * @since   1.0.0
 *
 * @var array $settings   Elementor settings for this widget instance.
 * @var int   $item_count Current WooCommerce cart item count.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Resolve display values ─────────────────────────────────────────────────

$cart_title   = ! empty( $settings['cart_title'] )
	? esc_html( $settings['cart_title'] )
	: esc_html__( 'My Cart', 'zymarg-cart' );

$edit_label   = ! empty( $settings['edit_btn_label'] )
	? esc_attr( $settings['edit_btn_label'] )
	: esc_attr__( 'Edit', 'zymarg-cart' );

$done_label   = ! empty( $settings['done_btn_label'] )
	? esc_attr( $settings['done_btn_label'] )
	: esc_attr__( 'Done', 'zymarg-cart' );

$delete_label = ! empty( $settings['delete_btn_label'] )
	? esc_attr( $settings['delete_btn_label'] )
	: esc_attr__( 'Delete', 'zymarg-cart' );

// Item count: singular / plural label.
$item_count = (int) ( $item_count ?? 0 );
$count_label = sprintf(
	/* translators: %d: Number of items in cart. */
	_n( '(%d item)', '(%d items)', $item_count, 'zymarg-cart' ),
	$item_count
);

// Confirmation dialog settings.
$confirm_enabled = 'yes' === ( $settings['edit_confirm_dialog'] ?? 'yes' );
$confirm_text    = ! empty( $settings['confirm_dialog_text'] )
	? esc_attr( $settings['confirm_dialog_text'] )
	: esc_attr__( 'Are you sure you want to remove the selected items?', 'zymarg-cart' );

// Visibility flags.
$show_icon       = 'yes' === ( $settings['show_cart_icon']   ?? 'yes' );
$show_title      = 'yes' === ( $settings['show_cart_title']  ?? 'yes' );
$show_count      = 'yes' === ( $settings['show_item_count']  ?? 'yes' );
$show_edit_btn   = 'yes' === ( $settings['show_edit_btn']    ?? 'yes' );
$show_delete_btn = 'yes' === ( $settings['show_delete_btn']  ?? 'yes' );

?>
<div class="zymarg-cart-header"
	role="banner"
	aria-label="<?php esc_attr_e( 'Shopping cart header', 'zymarg-cart' ); ?>">

	<?php /* ── Left side: icon + title + count ─────────────────────────── */ ?>
	<div class="zymarg-header-left">

		<?php if ( $show_icon ) : ?>
			<?php echo Zymarg_Cart_Helpers::icon( 'shopping-cart', 'zymarg-cart-icon' ); ?>
		<?php endif; ?>

		<?php if ( $show_title ) : ?>
			<span class="zymarg-cart-title"><?php echo $cart_title; ?></span>
		<?php endif; ?>

		<?php if ( $show_count ) : ?>
			<span class="zymarg-item-count"
				aria-live="polite"
				aria-label="<?php esc_attr_e( 'Cart item count', 'zymarg-cart' ); ?>">
				<?php echo esc_html( $count_label ); ?>
			</span>
		<?php endif; ?>

	</div>

	<?php /* ── Right side: delete + edit buttons ─────────────────────────── */ ?>
	<div class="zymarg-header-right">

		<?php if ( $show_delete_btn ) : ?>
			<button
				class="zymarg-delete-btn"
				type="button"
				disabled
				aria-disabled="true"
				aria-label="<?php echo esc_attr( $delete_label ); ?>"
				<?php if ( $confirm_enabled ) : ?>
					data-confirm="1"
					data-confirm-text="<?php echo $confirm_text; ?>"
				<?php endif; ?>
			>
				<?php echo Zymarg_Cart_Helpers::icon( 'trash', 'zymarg-btn-icon' ); ?>
				<span class="zymarg-btn-label"><?php echo esc_html( $delete_label ); ?></span>
			</button>
		<?php endif; ?>

		<?php if ( $show_edit_btn ) : ?>
			<button
				class="zymarg-edit-btn"
				type="button"
				aria-label="<?php echo esc_attr( $edit_label ); ?>"
				aria-pressed="false"
				data-edit-label="<?php echo $edit_label; ?>"
				data-done-label="<?php echo $done_label; ?>"
			>
				<?php echo Zymarg_Cart_Helpers::icon( 'edit', 'zymarg-btn-icon' ); ?>
				<span class="zymarg-btn-label"><?php echo esc_html( $edit_label ); ?></span>
			</button>
		<?php endif; ?>

	</div>

</div>
<?php
