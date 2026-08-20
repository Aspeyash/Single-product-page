<?php
/**
 * Frontend template — Vendor Group Block.
 *
 * Renders one complete vendor block: the vendor header row (Container A)
 * followed by all product rows from that vendor (Container B), then an
 * optional vendor subtotal footer row.
 *
 * Variables inherited from cart-body.php scope:
 *   @var array  $settings         Elementor control values.
 *   @var int    $vendor_id        Dokan vendor user ID.
 *   @var array  $vendor_group     Vendor data: vendor_info, items, subtotal,
 *                                 selected_subtotal, shipping, tax, item_count, selected_count.
 *   @var array  $applied_coupons  Applied coupon data.
 *
 * @package ZymargCart
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Unpack vendor data ─────────────────────────────────────────────────────
$vendor_info      = $vendor_group['vendor_info']   ?? [];
$items            = $vendor_group['items']          ?? [];
$vendor_subtotal  = $vendor_group['subtotal']       ?? 0.0;
$vendor_item_count = $vendor_group['item_count']   ?? 0;

// ── Vendor display values ──────────────────────────────────────────────────
$store_name   = esc_html( $vendor_info['store_name'] ?? __( 'Store', 'zymarg-cart' ) );
$store_url    = esc_url( $vendor_info['store_url']   ?? '' );

// ── Visibility flags ───────────────────────────────────────────────────────
$show_vendor_cb      = 'yes' === ( $settings['show_vendor_checkbox']  ?? 'yes' );
$show_vendor_link    = 'yes' === ( $settings['show_vendor_link']      ?? 'yes' );
$show_vendor_arrow   = 'yes' === ( $settings['show_vendor_arrow']     ?? 'yes' );
$show_headers        = 'yes' === ( $settings['show_table_headers']    ?? 'yes' );
$show_vendor_sub     = 'yes' === ( $settings['show_vendor_subtotal']  ?? 'yes' );

// ── v1.3.1 — Vendor identity icon mode ─────────────────────────────────────
// Either show the per-vendor Dokan profile photo (current behaviour) OR a
// single static icon shared across all vendors. Static-icon mode also
// requires picking which icon (default: building-store).
$vendor_icon_type   = (string) ( $settings['vendor_icon_type']   ?? 'vendor_profile' );
$vendor_static_icon = (string) ( $settings['vendor_static_icon'] ?? 'building-store' );

// ── All items in this vendor group start as selected (JS manages state) ────
$all_selected = true;

if ( empty( $items ) ) {
	return;
}

?>
<div class="zymarg-vendor-block" data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>">

	<?php /* ── Container A: Vendor header row ──────────────────────────── */ ?>
	<div class="zymarg-vendor-row" data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>">

		<?php if ( $show_vendor_cb ) : ?>
			<span class="zymarg-vendor-cb-wrap">
				<input
					type="checkbox"
					class="zymarg-vendor-cb"
					data-vendor-id="<?php echo esc_attr( (string) $vendor_id ); ?>"
					<?php checked( $all_selected ); ?>
					aria-label="<?php echo esc_attr( sprintf(
						/* translators: %s: Store name. */
						__( 'Select all products from %s', 'zymarg-cart' ),
						$vendor_info['store_name'] ?? ''
					) ); ?>"
				>
			</span>
		<?php endif; ?>

		<div class="zymarg-vendor-identity">

			<?php if ( 'static_icon' === $vendor_icon_type ) : ?>
				<?php /* v1.3.1 — Static icon mode: same icon for every vendor.
				             Replaces the per-vendor profile photo. */ ?>
				<?php echo Zymarg_Cart_Helpers::icon( $vendor_static_icon, 'zymarg-vendor-static-icon' ); ?>
			<?php elseif ( ! empty( $vendor_info['avatar_url'] ) ) : ?>
				<?php /* Default mode: Dokan per-vendor profile photo. */ ?>
				<img
					src="<?php echo esc_url( $vendor_info['avatar_url'] ); ?>"
					alt="<?php echo esc_attr( $vendor_info['store_name'] ?? '' ); ?>"
					class="zymarg-vendor-avatar"
					width="24"
					height="24"
					loading="lazy"
				>
			<?php endif; ?>

			<?php if ( $show_vendor_link && $store_url ) : ?>
				<a
					href="<?php echo $store_url; ?>"
					class="zymarg-vendor-name zymarg-vendor-link"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php echo $store_name; ?>
					<?php if ( $show_vendor_arrow ) : ?>
						<?php echo Zymarg_Cart_Helpers::icon( 'chevron-right', 'zymarg-vendor-arrow' ); ?>
					<?php endif; ?>
				</a>
			<?php else : ?>
				<span class="zymarg-vendor-name"><?php echo $store_name; ?></span>
			<?php endif; ?>

		</div>

		<span class="zymarg-vendor-item-count" aria-hidden="true">
			<?php echo esc_html( sprintf(
				/* translators: %d: Number of items. */
				_n( '%d item', '%d items', $vendor_item_count, 'zymarg-cart' ),
				$vendor_item_count
			) ); ?>
		</span>

	</div>

	<?php /* ── Column headers (optional, shown above first product row) ──── */ ?>
	<?php if ( $show_headers ) : ?>
		<div class="zymarg-table-header" aria-hidden="true">
			<div class="zymarg-th zymarg-th-cb"></div>
			<div class="zymarg-th zymarg-th-img"></div>
			<div class="zymarg-th zymarg-th-title"><?php esc_html_e( 'Product', 'zymarg-cart' ); ?></div>
			<div class="zymarg-th zymarg-th-price"><?php esc_html_e( 'Price', 'zymarg-cart' ); ?></div>
			<div class="zymarg-th zymarg-th-variation"><?php esc_html_e( 'Variation / Qty', 'zymarg-cart' ); ?></div>
			<div class="zymarg-th zymarg-th-subtotal"><?php esc_html_e( 'Subtotal', 'zymarg-cart' ); ?></div>
			<div class="zymarg-th zymarg-th-coupon"><?php esc_html_e( 'Coupon', 'zymarg-cart' ); ?></div>
		</div>
	<?php endif; ?>

	<?php /* ── Container B: Product rows ──────────────────────────────────── */ ?>
	<div class="zymarg-product-rows">
		<?php foreach ( $items as $cart_item_key => $item ) :
			include ZYMARG_CART_PATH . 'templates/cart-body-product-row.php';
		endforeach; ?>
	</div>

	<?php /* ── Vendor Subtotal footer ──────────────────────────────────────── */ ?>
	<?php if ( $show_vendor_sub ) : ?>
		<div class="zymarg-vendor-subtotal-row">
			<span class="zymarg-vendor-subtotal-label">
				<?php echo esc_html( sprintf(
					/* translators: %s: Store name. */
					__( '%s subtotal', 'zymarg-cart' ),
					$vendor_info['store_name'] ?? ''
				) ); ?>
			</span>
			<span class="zymarg-vendor-subtotal-value">
				<?php echo wc_price( $vendor_subtotal ); ?>
			</span>
		</div>
	<?php endif; ?>

</div>
<?php
