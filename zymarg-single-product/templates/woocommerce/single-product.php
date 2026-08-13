<?php
/**
 * ZYMARG Single Product Template.
 *
 * Overrides WooCommerce's single-product.php.
 * Exact visual structure from the HTML design file, all sections
 * driven by Options. Reviews are rendered by the ZYMARG Reviews Engine plugin.
 *
 * @package ZymargSingleProduct
 * @version 1.1.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZymargSP\Options;
use ZymargSP\Breadcrumbs;
use ZymargSP\Seller_Card;
use ZymargSP\Price_Renderer;

get_header( 'shop' );

// ── Bootstrap product object ─────────────────────────────────────────────────
global $product;
if ( ! is_a( $product, 'WC_Product' ) ) {
	$product = wc_get_product( get_the_ID() );
}
if ( ! $product ) {
	get_footer( 'shop' );
	return;
}

$product_id   = $product->get_id();
$is_variable  = $product->is_type( 'variable' );
$is_in_stock  = $product->is_in_stock();
$stock_qty    = $product->get_stock_quantity();
$low_thresh   = (int) Options::get( 'low_stock_threshold', 5 );

// ── Variations data (for JS) ─────────────────────────────────────────────────
$variations_json = '';
if ( $is_variable ) {
	/** @var WC_Product_Variable $product */
	$available = $product->get_available_variations();
	$variations_json = wp_json_encode( $available );
}

// ── Gallery images ──────────────────────────────────────────────────────────
$attachment_ids = $product->get_gallery_image_ids();
array_unshift( $attachment_ids, $product->get_image_id() );

// Variable products: also surface each variation's feature image as a thumbnail.
if ( $is_variable ) {
	foreach ( $product->get_children() as $zsp_child_id ) {
		$zsp_variation = wc_get_product( $zsp_child_id );
		if ( $zsp_variation && $zsp_variation->get_image_id() ) {
			$attachment_ids[] = $zsp_variation->get_image_id();
		}
	}
}

$attachment_ids = array_values( array_filter( array_unique( $attachment_ids ) ) );

// ── Review count ────────────────────────────────────────────────────────────
$review_count = $product->get_review_count();
$avg_rating   = $product->get_average_rating();

// ── Options cache ────────────────────────────────────────────────────────────
$opts = Options::all();

// Reviews label with {count} token.
$reviews_label = str_replace( '{count}', number_format_i18n( $review_count ), $opts['reviews_label'] );

// Sticky bar CSS class.
$sticky_content_class = 'zymarg-sp-sticky--' . esc_attr( $opts['sticky_bar_content'] );
?>

<div class="zymarg-single-product" data-product-id="<?php echo esc_attr( $product_id ); ?>">
<main class="container">

	<?php do_action( 'zymarg_sp_before_breadcrumbs', $product ); ?>

	<!-- ① BREADCRUMBS -->
	<?php Breadcrumbs::render( $product ); ?>

	<?php do_action( 'zymarg_sp_after_breadcrumbs', $product ); ?>

	<!-- ② PRODUCT SECTION — 3 col desktop / 2 row tablet / stacked mobile -->
	<section class="product-section">

		<!-- Column 1: GALLERY -->
		<div class="col-gallery">
			<?php
			// v1.1.6 - thumbnail sizing vars live on the wrap so BOTH the rail and the main image inherit them
			$thumb_size_map = [ 'small' => 56, 'medium' => 72, 'large' => 88 ];
			$thumb_px       = $thumb_size_map[ $opts['gallery_thumb_size'] ] ?? 72;
			$visible_thumbs = max( 1, (int) $opts['gallery_max_thumbs'] );

			// v2.3.0 - the mobile thumbnail toggle can be narrowed to one product type.
			// Scope only matters while the toggle is OFF; 'all' keeps prior behaviour.
			// $is_variable is resolved near the top of this template.
			$zsp_hide_thumbs_mobile = empty( $opts['gallery_show_thumbs_mobile'] );
			if ( $zsp_hide_thumbs_mobile ) {
				$zsp_thumb_scope = $opts['gallery_thumbs_mobile_scope'] ?? 'all';
				if ( 'variable' === $zsp_thumb_scope && ! $is_variable ) {
					$zsp_hide_thumbs_mobile = false;
				}
				if ( 'simple' === $zsp_thumb_scope && $is_variable ) {
					$zsp_hide_thumbs_mobile = false;
				}
			}
			?>
			<div class="gallery-wrap gallery-wrap--<?php echo esc_attr( $opts['gallery_desktop_layout'] ); ?> gallery-tablet--<?php echo esc_attr( $opts['gallery_tablet_layout'] ); ?> gallery-mobile--<?php echo esc_attr( $opts['gallery_mobile_layout'] ); ?><?php echo empty( $opts['gallery_show_thumbs_desktop'] ) ? ' zsp-hide-thumbs-desktop' : ''; echo empty( $opts['gallery_show_thumbs_tablet'] ) ? ' zsp-hide-thumbs-tablet' : ''; echo $zsp_hide_thumbs_mobile ? ' zsp-hide-thumbs-mobile' : ''; ?>" style="--thumb-size:<?php echo esc_attr( $thumb_px ); ?>px; --thumb-visible:<?php echo esc_attr( $visible_thumbs ); ?>;">

				<!-- Main image -->
				<div class="gallery-main" id="zymarg-sp-main-img">
					<?php if ( $product->get_image_id() ) :
						echo wp_get_attachment_image( $product->get_image_id(), 'full', false, [
							'id'      => 'zymarg-sp-main-img-el',
							'class'   => 'zymarg-sp-gallery__main-img',
							'loading' => 'eager',
							'alt'     => esc_attr( $product->get_name() ),
						] );
					else : ?>
						<div class="zymarg-sp-gallery__no-image">
							<?php echo wc_placeholder_img( 'woocommerce_single' ); // phpcs:ignore ?>
						</div>
					<?php endif; ?>

					<!-- Sale badge -->
					<?php if ( $opts['gallery_show_sale_badge'] && $product->is_on_sale() ) :
						if ( $is_variable ) {
							/** @var WC_Product_Variable $product */
							$prices  = $product->get_variation_prices( true );
							$min_reg = current( $prices['regular_price'] );
							$min_sal = current( $prices['sale_price'] );
							$pct     = $min_reg > 0 ? round( ( 1 - $min_sal / $min_reg ) * 100 ) : 0;
						} else {
							$reg = (float) $product->get_regular_price();
							$sal = (float) $product->get_sale_price();
							$pct = $reg > 0 ? round( ( 1 - $sal / $reg ) * 100 ) : 0;
						}
						$badge_text = str_replace( '{percent}', $pct, $opts['gallery_sale_badge_text'] );
						$badge_pos  = 'top-right' === $opts['gallery_badge_position'] ? 'gallery-badge--right' : '';
						?>
						<span class="gallery-badge <?php echo esc_attr( $badge_pos ); ?>">
							<?php echo esc_html( $badge_text ); ?>
						</span>
					<?php endif; ?>

					<!-- Wishlist button (v2.4.4 - flat/transparent, idle/active heart
					     icons swapped via aria-pressed, same mechanism as the
					     ZYMARG WC Product Grid card hearts) -->
					<?php if ( $opts['gallery_show_wishlist'] ) : ?>
						<button class="gallery-wish zymarg-sp-wishlist-btn"
							aria-pressed="false"
							aria-label="<?php esc_attr_e( 'Add to wishlist', 'zymarg-single-product' ); ?>"
							data-product-id="<?php echo esc_attr( $product_id ); ?>">
							<span class="zymarg-sp-wish__icon-idle" aria-hidden="true">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
								</svg>
							</span>
							<span class="zymarg-sp-wish__icon-active" aria-hidden="true">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="none">
									<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
								</svg>
							</span>
						</button>
					<?php endif; ?>

					<!-- Image counter -->
					<?php if ( $opts['gallery_show_counter'] && count( $attachment_ids ) > 1 ) : ?>
						<span class="zymarg-sp-gallery__counter" aria-live="polite">
							<?php
							$fmt = str_replace( [ '{current}', '{total}' ], [ '<span data-current>1</span>', count( $attachment_ids ) ], $opts['gallery_counter_format'] );
							echo wp_kses_post( $fmt );
							?>
						</span>
					<?php endif; ?>
					<!-- Product video trigger -->
					<?php
					$zsp_video = ! empty( $opts['product_video_enabled'] ) ? \ZymargSP\Product_Video::get_product_video_data( $product_id ) : null;
					if ( $zsp_video ) :
						?>
						<button type="button" class="zymarg-sp-video-trigger" aria-label="<?php esc_attr_e( 'Watch product video', 'zymarg-single-product' ); ?>">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
							<span><?php esc_html_e( 'Watch video', 'zymarg-single-product' ); ?></span>
						</button>
					<?php endif; ?>
				</div><!-- /.gallery-main -->

				<!-- Thumbnails -->
				<?php if ( count( $attachment_ids ) > 1 ) : ?>
					<div class="gallery-thumbs"><!-- v1.1.6 - --thumb-size / --thumb-visible inherited from .gallery-wrap -->
						<?php
						$i = 0;
						foreach ( $attachment_ids as $att_id ) :
							$lazy = ( $i > 0 && $opts['gallery_lazy_thumbs'] ) ? 'lazy' : 'eager';
							?>
							<button class="thumb <?php echo 0 === $i ? 'active' : ''; ?>"
								data-full="<?php echo esc_url( wp_get_attachment_url( $att_id ) ); ?>"
								data-img-id="<?php echo esc_attr( $att_id ); ?>"
								data-index="<?php echo esc_attr( $i ); ?>"
								aria-label="<?php echo esc_attr( sprintf( __( 'Product image %d', 'zymarg-single-product' ), $i + 1 ) ); ?>">
								<?php echo wp_get_attachment_image( $att_id, 'thumbnail', false, [ 'loading' => $lazy ] ); // phpcs:ignore ?>
							</button>
						<?php
							$i++;
						endforeach;
						?>
					</div>
				<?php endif; ?>

			</div><!-- /.gallery-wrap -->

			<?php if ( ! empty( $zsp_video ) ) : ?>
				<div id="zymarg-sp-video-overlay" class="zymarg-sp-video-overlay" data-video-type="<?php echo esc_attr( $zsp_video['type'] ); ?>" data-video-embed="<?php echo esc_url( $zsp_video['embed'] ); ?>" hidden>
					<button type="button" class="zymarg-sp-video-overlay__close" aria-label="<?php esc_attr_e( 'Close video', 'zymarg-single-product' ); ?>">&times;</button>
					<div class="zymarg-sp-video-overlay__inner">
						<div class="zymarg-sp-video-overlay__player"></div>
					</div>
				</div>
			<?php endif; ?>
		</div><!-- /.col-gallery -->

		<!-- Column 2: PRODUCT INFO + VARIATIONS -->
		<div class="col-info">

			<h1 class="product-title"><?php the_title(); ?></h1>

			<!-- Rating row -->
			<div class="rating-row">
				<?php if ( $avg_rating > 0 ) : ?>
					<span class="zymarg-sp-stars" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Rated %s out of 5', 'zymarg-single-product' ), $avg_rating ) ); ?>">
						<span class="zymarg-sp-stars__fill" style="width: <?php echo esc_attr( ( (float) $avg_rating / 5 ) * 100 ); ?>%;"></span>
					</span>
					<strong><?php echo esc_html( number_format( (float) $avg_rating, 1 ) ); ?></strong>
				<?php endif; ?>
				<?php if ( $review_count > 0 ) : ?>
					<a href="#zymarg-reviews">
						(<?php echo esc_html( number_format_i18n( $review_count ) . ' ' . _n( 'review', 'reviews', $review_count, 'zymarg-single-product' ) ); ?>)
					</a>
				<?php endif; ?>
				<?php
				$sold = get_post_meta( $product_id, 'total_sales', true );
				if ( $sold > 0 ) :
					?>
					<span class="zymarg-sp-sold-count">
						<?php echo esc_html( number_format_i18n( (int) $sold ) . ' ' . __( 'sold', 'zymarg-single-product' ) ); ?>
					</span>
				<?php endif; ?>
			</div><!-- /.rating-row -->

			<?php
			// v1.1.18 - product categories, directly under the rating / sold row.
			$zsp_cats = wc_get_product_category_list( $product_id, '<span class="sep">,</span> ' );
			if ( $zsp_cats ) :
				?>
				<div class="product-cats">
					<span class="product-cats__label"><?php esc_html_e( 'Category:', 'zymarg-single-product' ); ?></span>
					<span class="product-cats__list"><?php echo wp_kses_post( $zsp_cats ); ?></span>
				</div><!-- /.product-cats -->
			<?php endif; ?>

			<!-- Price row -->
			<div class="price-row">
				<?php Price_Renderer::render( $product ); ?>
			</div>

			<!-- Short description -->
			<?php if ( $product->get_short_description() ) : ?>
				<div class="short-desc">
					<?php echo wp_kses_post( $product->get_short_description() ); ?>
				</div>
			<?php endif; ?>

			<!-- Variations / swatches - WooCommerce native form with our CSS -->
			<div class="zymarg-sp-variations-slot"><!-- v2.3.0 - mobile ordering is CSS-owned; see zymarg-sp.css -->
			<?php if ( $is_variable ) : ?>
				<?php
				// WooCommerce outputs the variations form here.
				// Our JS (zymarg-sp.js) picks up .variations_form and:
				//   - renders swatches on top of the native <select>s
				//   - listens to found_variation / reset_data events
				//   - updates price, gallery, ATC button state
				do_action( 'woocommerce_variable_add_to_cart' );
				?>
			<?php elseif ( $product->is_type( 'simple' ) ) : ?>
				<?php
				// Simple product: just the hidden quantity field for the buy box.
				// The actual ATC button is in the buy box column.
				?>
				<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">
				<input type="hidden" name="variation_id" value="0">
			<?php else : ?>
				<?php do_action( 'woocommerce_single_product_summary' ); ?>
			<?php endif; ?>
			</div><!-- /.zymarg-sp-variations-slot -->

		</div><!-- /.col-info -->

		<!-- Column 3: BUY BOX -->
		<div class="col-buybox">
			<div class="buy-box">

				<!-- Sold by -->
				<?php if ( $opts['show_sold_by'] ) :
					$author_id  = (int) get_post_field( 'post_author', $product_id );
					$store_name = '';
					$store_url  = '';
					if ( function_exists( 'dokan_get_store_info' ) ) {
						$info       = dokan_get_store_info( $author_id );
						$store_name = $info['store_name'] ?? '';
						$store_url  = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $author_id ) : '';
					}
					if ( ! $store_name ) {
						$u          = get_user_by( 'id', $author_id );
						$store_name = $u ? $u->display_name : get_bloginfo( 'name' );
					}
					?>
					<div class="sold-by">
						<span><?php esc_html_e( 'Sold by', 'zymarg-single-product' ); ?></span>
						<?php if ( $store_url ) : ?>
							<a href="<?php echo esc_url( $store_url ); ?>"><?php echo esc_html( $store_name ); ?></a>
						<?php else : ?>
							<strong><?php echo esc_html( $store_name ); ?></strong>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Trust badges -->
				<ul class="trust-list">
					<?php for ( $i = 1; $i <= 5; $i++ ) :
						if ( ! $opts[ "trust_badge_{$i}_enabled" ] ) continue;
						$text = trim( $opts[ "trust_badge_{$i}_text" ] );
						if ( ! $text ) continue;
						?>
						<li><?php echo esc_html( $text ); ?></li>
					<?php endfor; ?>
				</ul>

				<?php
				// v1.1.20 - the buy-box stock block was removed. Stock now appears once,
				// in the attribute heading row, and only when the variation is unavailable.
				?>

				<!-- Delivery info -->
				<?php if ( $opts['show_delivery_info'] ) : ?>
					<div class="delivery-info">
						<?php echo esc_html( $opts['delivery_icon'] ); ?>
						<?php echo esc_html( $opts['delivery_window_text'] ); ?><br>
						📍 <?php echo esc_html( $opts['ships_from_text'] ); ?>
					</div>
				<?php endif; ?>

				<!-- Shipping & Returns block -->
				<?php if ( $opts['show_shipping_returns'] ) : ?>
					<div class="ship-return">
						<h3><?php echo esc_html( $opts['delivery_icon'] ); ?> <?php esc_html_e( 'Shipping &amp; Returns', 'zymarg-single-product' ); ?></h3>
						<?php if ( $opts['shipping_text'] ) : ?>
							<p><strong><?php esc_html_e( 'Shipping:', 'zymarg-single-product' ); ?></strong>
							<?php echo esc_html( $opts['shipping_text'] ); ?></p>
						<?php endif; ?>
						<?php if ( $opts['returns_text'] ) : ?>
							<p><strong><?php esc_html_e( 'Returns:', 'zymarg-single-product' ); ?></strong>
							<?php echo esc_html( $opts['returns_text'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Quantity stepper + ATC + Buy Now -->
				<?php if ( $is_in_stock ) :
					$qty_default = max( 1, (int) $opts['qty_default'] );
					$qty_min     = max( 1, (int) $opts['qty_min'] );
					$qty_max     = (int) $opts['qty_max'];
					$max_attr    = $qty_max > 0 ? $qty_max : ( $stock_qty ?: '' );
					$gate_dis    = $product->is_type( 'variable' ) ? ' disabled' : '';
					?>

					<?php if ( $opts['qty_show_stepper'] ) : ?>
						<div class="qty-label"><?php esc_html_e( 'Quantity', 'zymarg-single-product' ); ?></div>
						<div class="qty-stepper" id="zymarg-sp-qty-stepper">
							<button type="button" class="zymarg-sp-qty-btn zymarg-sp-qty-btn--minus"
								aria-label="<?php esc_attr_e( 'Decrease quantity', 'zymarg-single-product' ); ?>">−</button>
							<input type="number"
								id="zymarg-sp-qty"
								class="zymarg-sp-qty-input"
								name="quantity"
								value="<?php echo esc_attr( $qty_default ); ?>"
								min="<?php echo esc_attr( $qty_min ); ?>"
								<?php if ( $max_attr ) : ?>max="<?php echo esc_attr( $max_attr ); ?>"<?php endif; ?>
								aria-label="<?php esc_attr_e( 'Quantity', 'zymarg-single-product' ); ?>">
							<button type="button" class="zymarg-sp-qty-btn zymarg-sp-qty-btn--plus"
								aria-label="<?php esc_attr_e( 'Increase quantity', 'zymarg-single-product' ); ?>">+</button>
						</div>
					<?php endif; ?>

					<!-- ATC button (conditionally positioned relative to Buy Now) -->
					<?php
					$atc_btn = '<button type="button" id="zymarg-sp-atc-btn" class="btn btn-cart"
						data-product-id="' . esc_attr( $product_id ) . '"' . $gate_dis . '>' .
						esc_html( $opts['atc_btn_text'] ) . '</button>';
					$buy_btn = '';
					if ( $opts['buynow_show'] ) {
						$buy_btn = '<button type="button" id="zymarg-sp-buy-btn" class="btn btn-buy"
							data-product-id="' . esc_attr( $product_id ) . '"' . $gate_dis . '>' .
							esc_html( $opts['buynow_text'] ) . '</button>';
					}

					if ( 'above' === $opts['buynow_position'] ) {
						echo $buy_btn . $atc_btn; // phpcs:ignore
					} else {
						echo $atc_btn . $buy_btn; // phpcs:ignore
					}
					?>

				<?php else : ?>
					<button type="button" class="btn btn-cart" disabled>
						<?php esc_html_e( 'Out of Stock', 'zymarg-single-product' ); ?>
					</button>
				<?php endif; ?>

				<!-- Secure note -->
				<?php if ( $opts['show_secure_note'] && $opts['secure_note_text'] ) : ?>
					<div class="secure-note"><?php echo esc_html( $opts['secure_note_text'] ); ?></div>
				<?php endif; ?>

			</div><!-- /.buy-box -->
		</div><!-- /.col-buybox -->

	</section><!-- /.product-section -->

	<?php do_action( 'zymarg_sp_after_product_section', $product ); ?>

	<!-- ③ SELLER CARD -->
	<?php Seller_Card::render( $product ); ?>

	<?php do_action( 'zymarg_sp_after_seller_card', $product ); ?>

	<!-- ④ PRODUCT TABS: Description + Reviews -->
	<section id="zymarg-reviews" style="box-shadow:none;background:transparent;padding:0">

		<?php if ( $opts['show_description_tab'] ) : ?>
			<details class="acc" <?php echo $opts['description_open_default'] ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $opts['description_label'] ); ?></summary>
				<div class="acc-body">
					<?php
					the_content();
					// Attributes table from WooCommerce.
					if ( $product->has_attributes() ) :
						?>
						<h3><?php esc_html_e( 'Specifications', 'zymarg-single-product' ); ?></h3>
						<table class="spec-table">
							<?php foreach ( $product->get_attributes() as $attribute ) :
								if ( ! $attribute->get_visible() ) continue;
								$values = [];
								if ( $attribute->is_taxonomy() ) {
									$terms = wc_get_product_terms( $product_id, $attribute->get_name(), [ 'fields' => 'names' ] );
									$values = $terms;
								} else {
									$values = $attribute->get_options();
								}
								?>
								<tr>
									<td><?php echo esc_html( wc_attribute_label( $attribute->get_name() ) ); ?></td>
									<td><?php echo esc_html( implode( ', ', $values ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endif; ?>
				</div>
			</details>
		<?php endif; ?>

		<?php
		// v2.0.0 — reviews are produced by the ZYMARG Reviews Engine plugin. Soft
		// dependency: with the engine missing we simply skip the accordion instead
		// of falling back to WooCommerce comments, so the page never mixes designs.
		if ( $opts['show_reviews_tab'] && function_exists( 'zymarg_reviews_render' ) ) :
			?>
			<details class="acc" <?php echo $opts['reviews_open_default'] ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $reviews_label ); ?></summary>
				<div class="acc-body">
					<?php zymarg_reviews_render( [ 'product_id' => $product_id ] ); ?>
				</div>
			</details>
			<?php
		endif;
		?>

	</section>

	<?php do_action( 'zymarg_sp_after_tabs', $product ); ?>

	<!-- PRODUCT GRID SECTIONS - v2.1.0 (replaces the three hardcoded placeholders) -->
	<?php
	/**
	 * Ordered, admin-managed product grid sections.
	 *
	 * Each row runs one ZYMARG Product Grid shortcode and the engine owns the
	 * whole section: heading, View All link, cards and slider all come from the
	 * shortcode. That is deliberate - when a source has nothing to show it
	 * returns the engine's hide sentinel, the shortcode returns an empty string,
	 * and no heading is left stranded above an empty box.
	 *
	 * Each row is buffered and the wrapper skipped when the output is empty,
	 * otherwise an empty <section> would still contribute its 32px bottom
	 * margin as a visible gap and the next section would not close up.
	 */
	$zymarg_sp_sections = ( isset( $opts['product_sections'] ) && is_array( $opts['product_sections'] ) )
		? $opts['product_sections']
		: [];

	foreach ( $zymarg_sp_sections as $zymarg_sp_section ) :
		if ( ! is_array( $zymarg_sp_section ) || empty( $zymarg_sp_section['enabled'] ) ) {
			continue;
		}

		$zymarg_sp_code = trim( (string) ( $zymarg_sp_section['shortcode'] ?? '' ) );
		if ( '' === $zymarg_sp_code ) {
			continue;
		}

		// v2.2.0 - this plugin owns the section heading, so the engine's own
		// heading block is forced off. Order matters: the shortcode is rendered
		// into a buffer and an empty result skips the row entirely, which is what
		// makes the heading vanish together with an empty grid instead of being
		// left stranded above blank space.
		$zymarg_sp_html = trim( do_shortcode( \ZymargSP\Sections::force_no_heading( $zymarg_sp_code ) ) );
		if ( '' === $zymarg_sp_html ) {
			continue;
		}

		$zymarg_sp_is_vendor = \ZymargSP\Sections::is_vendor_source( $zymarg_sp_code );
		$zymarg_sp_vendor_id = $zymarg_sp_is_vendor ? \ZymargSP\Sections::vendor_id( $product ) : 0;
		$zymarg_sp_heading   = \ZymargSP\Sections::heading( $zymarg_sp_section, $zymarg_sp_is_vendor, $zymarg_sp_vendor_id );
		$zymarg_sp_link      = \ZymargSP\Sections::link( $zymarg_sp_section, $zymarg_sp_is_vendor, $zymarg_sp_vendor_id );
		?>
		<section class="zymarg-sp-grid-section zymarg-sp-grid-section--engine" data-section-id="<?php echo esc_attr( (string) ( $zymarg_sp_section['id'] ?? '' ) ); ?>">

			<?php if ( '' !== $zymarg_sp_heading || ! empty( $zymarg_sp_link ) ) : ?>
				<div class="zymarg-sp-section-head">
					<?php if ( '' !== $zymarg_sp_heading ) : ?>
						<h2 class="zymarg-sp-section-head__title"><?php echo esc_html( $zymarg_sp_heading ); ?></h2>
					<?php endif; ?>

					<?php if ( ! empty( $zymarg_sp_link ) ) : ?>
						<a class="zymarg-sp-section-head__link"
							href="<?php echo esc_url( $zymarg_sp_link['url'] ); ?>"
							aria-label="<?php echo esc_attr( $zymarg_sp_link['text'] ); ?>">
							<span class="zymarg-sp-section-head__txt"><?php echo esc_html( $zymarg_sp_link['text'] ); ?></span>
							<span class="zymarg-sp-section-head__arw" aria-hidden="true">&rarr;</span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php echo $zymarg_sp_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- engine shortcode output, already escaped upstream. ?>
		</section>
		<?php
	endforeach;

	// The three original section hooks are kept as bare anchors for backwards
	// compatibility. They no longer print any wrapper markup of their own.
	do_action( 'zymarg_sp_seller_products', $product );
	do_action( 'zymarg_sp_similar_products', $product );
	do_action( 'zymarg_sp_recommended_products', $product );
	?>


</main><!-- /.container -->
</div><!-- /.zymarg-single-product -->

<!-- STICKY MOBILE BAR -->
<?php if ( $opts['sticky_bar_enabled'] ) : ?>
<div class="sticky-bar <?php echo esc_attr( $sticky_content_class ); ?>" id="zymarg-sp-sticky-bar" aria-hidden="true">

	<?php $gate_dis = $product->is_type( 'variable' ) ? ' disabled' : ''; ?>

	<?php if ( in_array( $opts['sticky_bar_content'], [ 'qty-atc-buynow' ], true ) && $opts['qty_show_stepper'] ) :
		$qty_default = max( 1, (int) $opts['qty_default'] );
		$qty_min     = max( 1, (int) $opts['qty_min'] );
		$qty_max     = (int) $opts['qty_max'];
		$max_attr    = $qty_max > 0 ? $qty_max : ( $stock_qty ?: '' );
		?>
		<div class="qty-stepper" id="zymarg-sp-qty-stepper-sticky">
			<button type="button" class="zymarg-sp-qty-btn zymarg-sp-qty-btn--minus"
				aria-label="<?php esc_attr_e( 'Decrease quantity', 'zymarg-single-product' ); ?>">−</button>
			<input type="number"
				id="zymarg-sp-qty-sticky"
				class="zymarg-sp-qty-input"
				name="quantity"
				value="<?php echo esc_attr( $qty_default ); ?>"
				min="<?php echo esc_attr( $qty_min ); ?>"
				<?php if ( $max_attr ) : ?>max="<?php echo esc_attr( $max_attr ); ?>"<?php endif; ?>>
			<button type="button" class="zymarg-sp-qty-btn zymarg-sp-qty-btn--plus"
				aria-label="<?php esc_attr_e( 'Increase quantity', 'zymarg-single-product' ); ?>">+</button>
		</div>
	<?php endif; ?>

	<button type="button" class="btn btn-cart zymarg-sp-sticky-atc"
		data-product-id="<?php echo esc_attr( $product_id ); ?>"<?php echo $gate_dis; // phpcs:ignore ?>>
		<?php echo esc_html( $opts['atc_btn_text'] ); ?>
	</button>

	<?php if ( $opts['buynow_show'] && 'atc-only' !== $opts['sticky_bar_content'] ) : ?>
		<button type="button" class="btn btn-buy zymarg-sp-sticky-buy"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"<?php echo $gate_dis; // phpcs:ignore ?>>
			<?php echo esc_html( $opts['buynow_text'] ); ?>
		</button>
	<?php endif; ?>

</div><!-- /.sticky-bar -->
<?php endif; ?>

<!-- Toast notification -->
<div id="zymarg-sp-toast" class="zymarg-sp-toast" role="status" aria-live="polite" aria-atomic="true"></div>

<!-- Lightbox -->
<?php if ( $opts['gallery_lightbox'] ) : ?>
<div id="zymarg-sp-lightbox" class="zymarg-sp-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Product image lightbox', 'zymarg-single-product' ); ?>" hidden>
	<button class="zymarg-sp-lightbox__close" aria-label="<?php esc_attr_e( 'Close', 'zymarg-single-product' ); ?>">
		<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
	</button>
	<div class="zymarg-sp-lightbox__img-wrap">
		<img src="" alt="" class="zymarg-sp-lightbox__img" id="zymarg-sp-lightbox-img">
	</div>
	<button class="zymarg-sp-lightbox__nav zymarg-sp-lightbox__nav--prev" aria-label="<?php esc_attr_e( 'Previous image', 'zymarg-single-product' ); ?>">‹</button>
	<button class="zymarg-sp-lightbox__nav zymarg-sp-lightbox__nav--next" aria-label="<?php esc_attr_e( 'Next image', 'zymarg-single-product' ); ?>">›</button>
</div>
<?php endif; ?>

<?php
get_footer( 'shop' );
