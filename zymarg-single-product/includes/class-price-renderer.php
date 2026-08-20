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
		$is_in_stock = $product->is_in_stock();
		$is_variable = $product->is_type( 'variable' );

		// ── Smart heading ────────────────────────────────────────────────────
		// v2.6.0 - rebuilt as a badge (icon + text + optional live countdown)
		// instead of plain text. See get_heading_state() below.
		$heading = self::get_heading_state( $product, $is_in_stock );

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

			<?php
			// v2.6.0 - always print the slot, even when there is nothing to show
			// right now (type 'none'), so the JS live-update path in
			// zymarg-sp-price.js has a stable element to insert/replace/remove
			// the badge in after a variation is selected, without having to
			// create the wrapper itself.
			?>
			<div class="zymarg-sp-heading-slot"><?php echo self::render_heading_badge( $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from escaped parts, see render_heading_badge(). ?></div>

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

	// ── Smart heading (v2.6.0 rework) ────────────────────────────────────────
	//
	// Two states only, in priority order:
	//   1. Out of stock            - always wins, regardless of any sale.
	//   2. On sale WITH a schedule - a live countdown badge (HH:MM:SS, no cap),
	//      shared text whether the schedule comes from the Vendor Dashboard's
	//      Premium Flash Sale feature (checked first, wins if live) or a plain
	//      native WooCommerce scheduled sale (checked only if Flash Sale is not
	//      live). No schedule at all (either source) means no badge - a
	//      permanent/manual sale price with no end date is not "flash", so it
	//      renders no heading, matching the removed always-on "Limited Time
	//      Offer" heading being retired entirely.
	//
	// $product is the PARENT product on initial page load (matches every other
	// aggregate value computed by the template for a variable product, e.g.
	// $is_in_stock). Once a shopper selects a specific variation, this PHP
	// state is superseded on the front end by the live JS path in
	// assets/js/zymarg-sp-price.js, which re-evaluates OOS + native schedule
	// against that exact variation (Flash Sale data never varies by variation
	// - see resolve_flash_end()).

	/**
	 * Resolve which heading state applies right now for $product.
	 *
	 * @return array{type:string,text:string,end:?int} type is one of
	 *              'oos' | 'flash' | 'none'.
	 */
	private static function get_heading_state( \WC_Product $product, bool $is_in_stock ): array {
		if ( ! $is_in_stock && Options::get( 'price_heading_oos' ) ) {
			return [
				'type' => 'oos',
				'text' => self::oos_text(),
				'end'  => null,
			];
		}

		if ( ! Options::get( 'price_heading_flash_enabled' ) ) {
			return [ 'type' => 'none', 'text' => '', 'end' => null ];
		}

		$flash_end = self::resolve_flash_end( $product->get_id() );
		if ( null !== $flash_end ) {
			return [
				'type' => 'flash',
				'text' => self::flash_text(),
				'end'  => $flash_end,
			];
		}

		$native_end = self::resolve_native_sale_end( $product );
		if ( null !== $native_end ) {
			return [
				'type' => 'flash',
				'text' => self::flash_text(),
				'end'  => $native_end,
			];
		}

		return [ 'type' => 'none', 'text' => '', 'end' => null ];
	}

	/**
	 * Out of stock heading text, safe against a blank saved value.
	 *
	 * Options::get() only falls back to a default when the setting key is
	 * missing entirely, never when it is saved as an empty string - so a
	 * blank price_heading_oos_text used to render no heading at all, with no
	 * error. Guarded here rather than in Options::get() itself, so no other
	 * setting's behaviour is touched.
	 *
	 * @return string
	 */
	private static function oos_text(): string {
		$text = trim( (string) Options::get( 'price_heading_oos_text', '' ) );
		return '' !== $text ? $text : 'Currently Unavailable';
	}

	/**
	 * Flash-countdown heading text (shared between both schedule sources),
	 * safe against a blank saved value - same reasoning as oos_text().
	 *
	 * @return string
	 */
	private static function flash_text(): string {
		$text = trim( (string) Options::get( 'price_heading_flash_text', '' ) );
		return '' !== $text ? $text : 'Flash Sale · Ends in';
	}

	/**
	 * The Vendor Dashboard Premium Flash Sale end time for a product, if that
	 * feature is installed, active, and currently live for this product.
	 *
	 * Flash Sale data always lives on the PARENT product id, even for a
	 * variable product - the Vendor Dashboard's own price-override filters
	 * resolve every variation back to its parent id before reading it (see
	 * zymarg_vd_premium_filter_variation_price()). So this never varies by
	 * variation and callers always pass the parent id.
	 *
	 * A live flash sale with no end date set (open-ended) returns null, same
	 * as "no schedule" - there is nothing to count down to, and this is NOT
	 * a signal to fall through to the native check below: WooCommerce's own
	 * sale fields are deliberately never written by the flash price layer
	 * (by that plugin's own design), so is_on_sale() will already be false
	 * for a flash-priced product and the native check would find nothing
	 * anyway.
	 *
	 * @param int $product_id Parent product id.
	 * @return int|null Unix timestamp, or null if not live / no end date.
	 */
	private static function resolve_flash_end( int $product_id ): ?int {
		if ( ! function_exists( 'zymarg_vd_premium_flash_is_live' ) || ! function_exists( 'zymarg_vd_premium_get_flash_data' ) ) {
			return null;
		}
		if ( ! zymarg_vd_premium_flash_is_live( $product_id ) ) {
			return null;
		}

		$data = zymarg_vd_premium_get_flash_data( $product_id );
		$end  = trim( (string) ( $data['end'] ?? '' ) );
		if ( '' === $end ) {
			return null;
		}

		$ts = strtotime( $end );
		return ( $ts && $ts > time() ) ? $ts : null;
	}

	/**
	 * A native WooCommerce scheduled-sale end time for $target, if it is on
	 * sale and has an end date set.
	 *
	 * $target is whichever object the caller actually wants evaluated: the
	 * parent/simple product on initial render, or - via the JS live-update
	 * path reading get_localized_heading_data() plus the per-variation data
	 * injected by inject_variation_sale_end() - a specific variation once one
	 * is selected. This fixes the previous bug of always reading the parent's
	 * own (almost always empty) sale-schedule fields regardless of which
	 * variation was active.
	 *
	 * @param \WC_Product $target Product or variation to inspect.
	 * @return int|null Unix timestamp, or null if not on sale / no end date.
	 */
	private static function resolve_native_sale_end( \WC_Product $target ): ?int {
		if ( ! $target->is_on_sale() ) {
			return null;
		}

		$to = $target->get_date_on_sale_to();
		if ( ! ( $to instanceof \WC_DateTime ) ) {
			return null;
		}

		$ts = $to->getTimestamp();
		return ( $ts > time() ) ? $ts : null;
	}

	/**
	 * Render the heading state as a badge (icon + text + optional live
	 * countdown). Returns '' for the 'none' state.
	 *
	 * @param array $state Return value of get_heading_state().
	 * @return string
	 */
	private static function render_heading_badge( array $state ): string {
		$type = $state['type'] ?? 'none';
		if ( 'none' === $type ) {
			return '';
		}

		$is_flash = ( 'flash' === $type );
		$classes  = 'zymarg-sp-heading-badge zymarg-sp-heading-badge--' . $type;
		$end_attr = ( $is_flash && ! empty( $state['end'] ) )
			? ' data-end="' . esc_attr( (string) $state['end'] ) . '"'
			: '';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-heading-type="<?php echo esc_attr( $type ); ?>"<?php echo $end_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_attr() above. ?>>
			<?php echo $is_flash ? self::icon_bolt() : self::icon_oos(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no user data. ?>
			<span class="zymarg-sp-heading-badge__text"><?php echo esc_html( $state['text'] ); ?></span>
			<?php if ( $is_flash && ! empty( $state['end'] ) ) : ?>
				<span class="zymarg-sp-heading-badge__countdown" aria-hidden="true">
					<span class="zymarg-sp-heading-badge__unit" data-unit="h">00</span><span class="zymarg-sp-heading-badge__sep">:</span>
					<span class="zymarg-sp-heading-badge__unit" data-unit="m">00</span><span class="zymarg-sp-heading-badge__sep">:</span>
					<span class="zymarg-sp-heading-badge__unit" data-unit="s">00</span>
				</span>
			<?php endif; ?>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Lightning-bolt icon for the flash-countdown badge.
	 *
	 * Same path data as the ZYMARG Template Pack's "flash" card template
	 * badge, so the icon identity matches across the site.
	 *
	 * @return string
	 */
	private static function icon_bolt(): string {
		return '<svg class="zymarg-sp-heading-badge__icon" viewBox="0 0 320 512" aria-hidden="true" focusable="false"><path d="M296 160H180.6l42.6-129.8C227.2 15 215.7 0 200 0H56C44 0 33.8 8.9 32.2 20.8l-32 240C-1.7 275.2 9.5 288 24 288h118.7L96.6 482.5c-3.6 15.2 8 29.5 23.3 29.5 8.4 0 16.4-4.4 20.8-12l176-304c9.3-15.9-2.2-36-20.7-36z"/></svg>';
	}

	/**
	 * "Unavailable" glyph for the out-of-stock badge (prohibition circle).
	 *
	 * @return string
	 */
	private static function icon_oos(): string {
		return '<svg class="zymarg-sp-heading-badge__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm5.293-15.293a1 1 0 0 1 0 1.414L7.879 17.535a1 1 0 0 1-1.414-1.414L16.879 6.707a1 1 0 0 1 1.414 0z"/></svg>';
	}

	/**
	 * Data the front-end JS needs to keep the heading badge correct after a
	 * variation is selected, without another server round trip.
	 *
	 * Flash Sale liveness/end time never varies by variation (see
	 * resolve_flash_end()); the native-schedule end time for the SPECIFIC
	 * selected variation is instead read from the per-variation
	 * zymarg_sale_end field injected by inject_variation_sale_end() below.
	 *
	 * @param int $product_id Parent product id.
	 * @return array
	 */
	public static function get_localized_heading_data( int $product_id ): array {
		$oos_enabled    = (bool) Options::get( 'price_heading_oos' );
		$flash_enabled  = (bool) Options::get( 'price_heading_flash_enabled' );
		$flash_end      = $flash_enabled ? self::resolve_flash_end( $product_id ) : null;

		return [
			'oos_enabled'   => $oos_enabled,
			'oos_text'      => self::oos_text(),
			'flash_enabled' => $flash_enabled,
			'flash_text'    => self::flash_text(),
			'flash_live'    => $flash_enabled && ( null !== $flash_end ),
			'flash_end'     => $flash_end ?? 0,
		];
	}

	/**
	 * Register hooks. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'inject_variation_sale_end' ], 10, 3 );
	}

	/**
	 * Expose each variation's own native sale-end date to the front end.
	 *
	 * WooCommerce's variation payload already carries is_on_sale, but not the
	 * sale end date, so the JS heading logic has no way to build a countdown
	 * for a specific variation without this. get_date_on_sale_to() is called
	 * on the VARIATION object itself, which is the fix for the previous bug
	 * of only ever reading the parent product's (almost always empty)
	 * schedule fields.
	 *
	 * @param array                  $data      Variation data sent to the browser.
	 * @param \WC_Product_Variable   $product   Parent product.
	 * @param \WC_Product_Variation  $variation Variation being serialised.
	 * @return array
	 */
	public static function inject_variation_sale_end( $data, $product, $variation ) {
		unset( $product );
		$to = ( $variation instanceof \WC_Product ) ? $variation->get_date_on_sale_to() : null;
		$data['zymarg_sale_end'] = ( $to instanceof \WC_DateTime ) ? $to->getTimestamp() : 0;
		return $data;
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

}
