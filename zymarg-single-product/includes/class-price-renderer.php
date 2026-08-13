<?php
/**
 * Price Renderer.
 *
 * Emits the price block with the CURRENT price inline and the OLD/regular
 * price as a subscript. Simple products use their own current/regular price;
 * variable products show the lowest current price inline with the lowest
 * regular price as a subscript, and update live as the shopper selects a
 * variation (see assets/js/zymarg-sp-price.js). The old-price style
 * (strikethrough / underline) is admin-configurable.
 *
 * @version 1.0.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Price_Renderer {

	/**
	 * Render the complete price block.
	 *
	 * @param \WC_Product $product
	 * @return void
	 */
	public static function render( \WC_Product $product ): void {
		$is_on_sale  = $product->is_on_sale();
		$is_in_stock = $product->is_in_stock();
		$is_variable = $product->is_type( 'variable' );

		// ── Smart heading ────────────────────────────────────────────────────
		$heading = self::get_smart_heading( $product, $is_on_sale, $is_in_stock );

		// ── Current / old price parts ────────────────────────────────────────
		$parts = self::get_price_parts( $product, $is_variable );

		// ── Savings (simple products render server-side; variable via JS) ─────
		$savings_html = '';
		if ( Options::get( 'price_show_savings' ) && $parts['on_sale'] && ! $is_variable ) {
			$savings_html = self::get_savings_html( $product );
		}

		// ── Free shipping hint ───────────────────────────────────────────────
		$hint_html = '';
		if ( Options::get( 'price_show_free_hint' ) ) {
			$threshold = (float) Options::get( 'price_free_threshold', 2000 );
			$price_num = (float) ( $product->get_price() ?: 0 );
			if ( $price_num < $threshold ) {
				$amount    = wc_price( $threshold );
				$text      = str_replace( '{amount}', $amount, Options::get( 'price_free_hint_text' ) );
				$hint_html = '<p class="zymarg-sp-price__hint">' . wp_kses_post( $text ) . '</p>';
			}
		}

		// ── Animation class ──────────────────────────────────────────────────
		$anim     = Options::get( 'price_change_animation', 'fade' );
		$anim_map = [
			'fade'  => 'zymarg-sp-price--anim-fade',
			'slide' => 'zymarg-sp-price--anim-slide',
			'none'  => '',
		];
		$anim_class = $anim_map[ $anim ] ?? '';

		// ── Old-price style ──────────────────────────────────────────────────
		$old_style    = Options::get( 'price_old_style', 'strikethrough' );
		$old_style    = in_array( $old_style, [ 'strikethrough', 'underline' ], true ) ? $old_style : 'strikethrough';
		$oldstyle_cls = 'zsp-oldstyle--' . $old_style;

		// ── Skeleton ─────────────────────────────────────────────────────────
		$skeleton = Options::get( 'price_loading_skeleton' )
			? '<div class="zymarg-sp-price__skeleton" aria-hidden="true"></div>'
			: '';

		$block_classes = trim( 'zymarg-sp-price-block ' . $anim_class . ' ' . $oldstyle_cls );
		?>
		<div class="<?php echo esc_attr( $block_classes ); ?>"
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-is-variable="<?php echo $is_variable ? '1' : '0'; ?>"
			data-price-anim="<?php echo esc_attr( $anim ); ?>"
			data-savings-format="<?php echo esc_attr( Options::get( 'price_savings_format', 'both' ) ); ?>"
			data-savings-prefix="<?php echo esc_attr( Options::get( 'price_savings_prefix', 'Save' ) ); ?>"
			data-initial-current="<?php echo esc_attr( (string) $parts['current'] ); ?>"
			data-initial-regular="<?php echo esc_attr( (string) $parts['regular'] ); ?>"
			data-initial-on-sale="<?php echo $parts['on_sale'] ? '1' : '0'; ?>">

			<?php if ( $heading ) : ?>
				<p class="zymarg-sp-price-block__heading"><?php echo esc_html( $heading ); ?></p>
			<?php endif; ?>

			<?php echo $skeleton; // phpcs:ignore ?>

			<div class="zymarg-sp-price-block__price" data-price-wrapper>
				<?php echo wp_kses_post( $parts['current_html'] ); ?>
				<?php echo wp_kses_post( $parts['was_html'] ); ?>
			</div>

			<?php if ( $savings_html ) : ?>
				<div class="zymarg-sp-price-block__savings zymarg-sp-price-savings">
					<?php echo wp_kses_post( $savings_html ); ?>
				</div>
			<?php else : ?>
				<div class="zymarg-sp-price-block__savings zymarg-sp-price-savings" style="display:none;"></div>
			<?php endif; ?>

			<?php echo $hint_html; // phpcs:ignore ?>

		</div>
		<?php
	}

	// ── Price parts (current inline + old subscript) ──────────────────────────

	/**
	 * Resolves the numeric current/regular amounts and their HTML.
	 *
	 * @return array{current:float,regular:float,on_sale:bool,current_html:string,was_html:string}
	 */
	private static function get_price_parts( \WC_Product $product, bool $is_variable ): array {
		$hidden_was = '<span class="zymarg-sp-price-was" style="display:none;"></span>';

		if ( ! $is_variable ) {
			$current = (float) $product->get_price();
			$regular = (float) $product->get_regular_price();
			$on_sale = $product->is_on_sale() && $regular > $current;

			$current_html = '<span class="zymarg-sp-price-current">' . wc_price( $current ) . '</span>';
			$was_html     = $on_sale
				? '<span class="zymarg-sp-price-was"><sub>' . wc_price( $regular ) . '</sub></span>'
				: $hidden_was;

			return [
				'current'      => $current,
				'regular'      => $regular,
				'on_sale'      => $on_sale,
				'current_html' => $current_html,
				'was_html'     => $was_html,
			];
		}

		/** @var \WC_Product_Variable $product */
		$display = Options::get( 'price_variable_display', 'lowest' );
		$prices  = $product->get_variation_prices( true );

		if ( empty( $prices['price'] ) ) {
			return [
				'current'      => 0.0,
				'regular'      => 0.0,
				'on_sale'      => false,
				'current_html' => '<span class="zymarg-sp-price-current">' . $product->get_price_html() . '</span>',
				'was_html'     => $hidden_was,
			];
		}

		$price_list = $prices['price'];
		asort( $price_list );
		$lowest_key = array_key_first( $price_list );
		$current    = (float) $price_list[ $lowest_key ];
		$regular    = isset( $prices['regular_price'][ $lowest_key ] ) ? (float) $prices['regular_price'][ $lowest_key ] : $current;
		$max_price  = (float) end( $price_list );
		$on_sale    = $regular > $current;

		switch ( $display ) {
			case 'from':
				$prefix       = esc_html( Options::get( 'price_from_prefix', 'From' ) );
				$current_html = '<span class="zymarg-sp-price__from-prefix">' . $prefix . '</span> <span class="zymarg-sp-price-current">' . wc_price( $current ) . '</span>';
				break;

			case 'range':
				if ( $current === $max_price ) {
					$current_html = '<span class="zymarg-sp-price-current">' . wc_price( $current ) . '</span>';
				} else {
					$current_html = '<span class="zymarg-sp-price-current">' . wc_price( $current ) . '<span class="zymarg-sp-price__range-sep">&ndash;</span>' . wc_price( $max_price ) . '</span>';
				}
				break;

			case 'lowest':
			default:
				$current_html = '<span class="zymarg-sp-price-current">' . wc_price( $current ) . '</span>';
				break;
		}

		// Old subscript only when the lowest variation is discounted and we are
		// not showing an explicit low–high range.
		$show_was = $on_sale && 'range' !== $display;
		$was_html = $show_was
			? '<span class="zymarg-sp-price-was"><sub>' . wc_price( $regular ) . '</sub></span>'
			: $hidden_was;

		return [
			'current'      => $current,
			'regular'      => $regular,
			'on_sale'      => $on_sale,
			'current_html' => $current_html,
			'was_html'     => $was_html,
		];
	}

	// ── Smart heading ────────────────────────────────────────────────────────

	private static function get_smart_heading( \WC_Product $product, bool $is_on_sale, bool $is_in_stock ): string {
		if ( ! $is_in_stock && Options::get( 'price_heading_oos' ) ) {
			return (string) Options::get( 'price_heading_oos_text', 'Currently Unavailable' );
		}

		if ( $is_on_sale ) {
			if ( Options::get( 'price_heading_ending_soon' ) ) {
				$end = self::get_sale_end_time( $product );
				if ( $end && ( $end - time() ) <= DAY_IN_SECONDS ) {
					$hours = max( 1, (int) ceil( ( $end - time() ) / HOUR_IN_SECONDS ) );
					$text  = str_replace( '{hours}', $hours, (string) Options::get( 'price_heading_ending_text' ) );
					return $text;
				}
			}
			if ( Options::get( 'price_heading_on_sale' ) ) {
				return (string) Options::get( 'price_heading_sale_text', 'Limited Time Offer' );
			}
		}

		if ( Options::get( 'price_heading_regular' ) ) {
			return (string) Options::get( 'price_heading_regular_text', 'Price' );
		}

		return '';
	}

	// ── Savings ──────────────────────────────────────────────────────────────

	private static function get_savings_html( \WC_Product $product ): string {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();

		if ( $regular <= 0 || $sale >= $regular ) {
			return '';
		}

		$saved   = $regular - $sale;
		$percent = round( ( $saved / $regular ) * 100 );
		$prefix  = esc_html( Options::get( 'price_savings_prefix', 'Save' ) );
		$format  = Options::get( 'price_savings_format', 'both' );

		$amount_html  = wc_price( $saved );
		$percent_html = '<span class="zymarg-sp-price__save-pct">' . $percent . '%</span>';

		switch ( $format ) {
			case 'amount':
				$inner = $prefix . ' ' . $amount_html;
				break;
			case 'percent':
				$inner = $prefix . ' ' . $percent_html;
				break;
			case 'both':
			default:
				$inner = $prefix . ' ' . $amount_html . ' (' . $percent_html . ')';
				break;
		}

		return '<span class="zymarg-sp-price__save-badge">' . $inner . '</span>';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function get_sale_end_time( \WC_Product $product ): ?int {
		$end_date = $product->get_date_on_sale_to();
		if ( $end_date instanceof \WC_DateTime ) {
			return $end_date->getTimestamp();
		}
		return null;
	}
}
