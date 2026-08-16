<?php
/**
 * ZYMARG Store Page -- Premium sections (Flash Sale / Featured Items).
 *
 * Renders a vendor's Flash Sale and Featured Items sections on their store
 * page, but only when the marketplace admin has approved that vendor for that
 * functionality.
 *
 * CROSS-PLUGIN CONTRACT
 * ---------------------
 * The Vendor Dashboard owns Premium entirely: the admin master switch, whether
 * the seller can even see the feature, the seller's request, the pending state,
 * the approval, the per-vendor caps, and the product meta. This file only
 * *reads*, and it reads through two functions:
 *
 *   zymarg_vd_premium_get_vendor_flash_ids()
 *   zymarg_vd_premium_get_vendor_featured_ids()
 *
 * Both apply that entire gate internally and return an empty array when the
 * vendor is not approved. No approval logic is duplicated here -- a rule that
 * exists in two places is a rule that will eventually disagree with itself.
 *
 * Every entry point is wrapped in function_exists(). If the Vendor Dashboard is
 * deactivated these sections vanish rather than fataling the storefront.
 *
 * WHO DRAWS THE CARD (changed in 1.18.0)
 * --------------------------------------
 * Not this file, any more. Cards are rendered by the Product Grid engine using
 * the ZYMARG Template Pack's registered card templates -- 'flash' for Flash
 * Sale, 'zymarg' for Featured Items -- so a Template Pack update restyles both
 * sections with no change here. The hand-rolled card this file used to carry
 * has been removed; the design is no longer copied into this plugin and so
 * cannot fall behind.
 *
 * FEEDING THE FLASH CARD'S COUNTDOWN AND SCARCITY BAR
 * ---------------------------------------------------
 * The flash card resolves both from WooCommerce's on-sale fields. Premium
 * cannot be read that way, by design: the Vendor Dashboard applies the flash
 * price at runtime through woocommerce_product_get_price and deliberately never
 * writes _sale_price, which is what keeps Premium products out of WooCommerce's
 * global on-sale lists, the homepage Flash Deals and the /flash-sale/ page.
 *
 * So the card's two window filters (Template Pack 1.7.0) are hooked around the
 * Flash Sale render only, and fed from Premium's own dates. Nothing here reads
 * or writes _sale_price, get_sale_price or is_on_sale, so that isolation holds.
 *
 * @package ZYMARG_Store_Page
 * @since   1.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the Premium bridge available at all?
 *
 * @return bool
 */
function zymarg_sp_premium_available() {
	return function_exists( 'zymarg_vd_premium_get_vendor_flash_ids' )
		&& function_exists( 'zymarg_vd_premium_get_vendor_featured_ids' );
}

/**
 * The display settings the marketplace admin chose.
 *
 * Layout and rotation are an admin decision that applies to every vendor, so
 * they are read from the Vendor Dashboard rather than stored again here.
 *
 * @return array<string,mixed>
 */
function zymarg_sp_premium_display() {
	if ( function_exists( 'zymarg_vd_premium_display_settings' ) ) {
		$display = (array) zymarg_vd_premium_display_settings();

		/*
		 * v1.24.2: back-compat with a Vendor Dashboard version older than
		 * 1.46.14, which added columns_desktop/tablet/mobile to this array.
		 * An older Vendor Dashboard's copy of this array simply will not
		 * carry those three keys yet, so they are filled in here rather than
		 * assumed present -- the same 4/3/2 defaults Vendor Dashboard itself
		 * ships as of 1.46.14 (zymarg_vd_premium_display_defaults()).
		 */
		$display += array(
			'columns_desktop' => 4,
			'columns_tablet'  => 3,
			'columns_mobile'  => 2,
		);

		return $display;
	}

	return array(
		'layout'          => 'grid',
		'rotation'        => 'step',
		'marquee_speed'   => 40,
		'glide_speed'     => 400,
		'featured_max'    => 10,
		'flash_max'       => 10,
		'columns_desktop' => 4,
		'columns_tablet'  => 3,
		'columns_mobile'  => 2,
	);
}

/**
 * How many products to show in a Premium section.
 *
 * The cap belongs to the admin, so it is read per vendor from the Vendor
 * Dashboard. The old hardcoded 8 survives only as the fallback for when that
 * plugin is unavailable.
 *
 * @param int    $store_id Vendor user ID.
 * @param string $which    'flash' or 'featured'.
 * @return int
 */
function zymarg_sp_premium_limit( $store_id = 0, $which = 'featured' ) {
	$limit = 8;

	if ( function_exists( 'zymarg_vd_premium_max_for' ) ) {
		$feature = ( 'flash' === $which ) ? 'flash_sale' : 'featured';
		$limit   = (int) zymarg_vd_premium_max_for( $store_id, $feature );
	}

	return (int) apply_filters( 'zymarg_sp_premium_limit', max( 1, $limit ), $store_id, $which );
}

/**
 * The product IDs for one Premium section.
 *
 * @param int    $store_id Vendor user ID.
 * @param string $which    'flash' or 'featured'.
 * @return array<int,int>
 */
function zymarg_sp_premium_ids( $store_id, $which ) {
	if ( ! zymarg_sp_premium_available() ) {
		return array();
	}

	$limit = zymarg_sp_premium_limit( $store_id, $which );

	if ( 'flash' === $which ) {
		return (array) zymarg_vd_premium_get_vendor_flash_ids( $store_id, $limit );
	}

	return (array) zymarg_vd_premium_get_vendor_featured_ids( $store_id, $limit );
}

/* ====================================================================== *
 * PREMIUM'S SALE WINDOW -> THE FLASH CARD
 * ====================================================================== */

/**
 * Convert a Premium date string into a real UTC timestamp.
 *
 * Premium stores plain date strings and decides liveness with
 * `current_time( 'timestamp' ) > strtotime( $end )`. Since WordPress puts PHP
 * in UTC, strtotime() yields UTC midnight while current_time() is site-local,
 * so the instant Premium actually stops applying the flash price is
 * `strtotime( $end ) - gmt_offset`.
 *
 * The countdown has to hit zero at that same instant, not at UTC midnight, or
 * a Bangladesh store would show a timer that expires six hours early. Mirroring
 * Premium's own arithmetic keeps the two in step rather than merely close.
 *
 * @param string $date Date string as stored by Premium.
 * @return int UTC timestamp, or 0 when unparseable/empty.
 */
function zymarg_sp_premium_window_ts( $date ) {
	$date = trim( (string) $date );
	if ( '' === $date ) {
		return 0;
	}

	$parsed = strtotime( $date );
	if ( ! $parsed ) {
		return 0;
	}

	$offset = (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;

	return (int) ( $parsed - $offset );
}

/**
 * Does this product have a live Premium flash sale?
 *
 * @param mixed $product WC_Product or anything else.
 * @return int Product ID when it does, 0 otherwise.
 */
function zymarg_sp_premium_flash_product_id( $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
		return 0;
	}
	if ( ! function_exists( 'zymarg_vd_premium_flash_is_live' ) || ! function_exists( 'zymarg_vd_premium_get_flash_data' ) ) {
		return 0;
	}

	$product_id = (int) $product->get_id();

	return zymarg_vd_premium_flash_is_live( $product_id ) ? $product_id : 0;
}

/**
 * Feed the flash card's countdown from Premium's end date.
 *
 * @param int   $end_ts  Value resolved by the card.
 * @param mixed $product Product being rendered.
 * @return int
 */
function zymarg_sp_premium_filter_flash_end( $end_ts, $product ) {
	$product_id = zymarg_sp_premium_flash_product_id( $product );
	if ( ! $product_id ) {
		return $end_ts;
	}

	$data = (array) zymarg_vd_premium_get_flash_data( $product_id );
	$end  = isset( $data['end'] ) ? $data['end'] : '';

	// An open-ended Premium flash sale is legitimate: the vendor set a price
	// with no finish. There is no honest countdown for that, so 0 hides it
	// rather than inventing a deadline.
	return zymarg_sp_premium_window_ts( $end );
}

/**
 * Feed the flash card's scarcity bar from Premium's start date.
 *
 * Returning 0 leaves the bar empty, which is the correct answer for a sale with
 * no recorded start. Left to the engine this would fall back to the product's
 * created date and count the product's entire sales history as "sold in this
 * sale" -- an older product would open its flash sale looking nearly sold out.
 *
 * @param int   $window_start Value resolved by the card.
 * @param mixed $product      Product being rendered.
 * @return int
 */
function zymarg_sp_premium_filter_flash_window_start( $window_start, $product ) {
	$product_id = zymarg_sp_premium_flash_product_id( $product );
	if ( ! $product_id ) {
		return $window_start;
	}

	$data  = (array) zymarg_vd_premium_get_flash_data( $product_id );
	$start = isset( $data['start'] ) ? $data['start'] : '';

	return zymarg_sp_premium_window_ts( $start );
}

/* ====================================================================== *
 * SECTION RENDER
 * ====================================================================== */

/**
 * Map the admin's Premium display choice onto engine layout config.
 *
 * @param array $display Premium display settings.
 * @return array<string,mixed>
 */
function zymarg_sp_premium_layout_config( array $display ) {
	$layout   = isset( $display['layout'] ) ? (string) $display['layout'] : 'grid';
	$rotation = isset( $display['rotation'] ) ? (string) $display['rotation'] : 'step';
	$glide    = isset( $display['glide_speed'] ) ? (int) $display['glide_speed'] : 400;

	/*
	 * v1.24.2/1.24.3: responsive columns, admin-configurable, for BOTH
	 * layout modes.
	 *
	 * Previously Grid hardcoded 'columns' => 4 with no responsive keys at
	 * all (fixed in 1.24.2), and Carousel carried no column count whatsoever
	 * -- the engine's slider template reads exactly the same
	 * columns/columns_tablet/columns_mobile settings to decide how many
	 * cards are visible at once per breakpoint (slidesPerView), so the
	 * admin's three numbers apply identically to both layouts; only the
	 * engine's own layout.type decides whether they become a static grid
	 * or a slider's per-view count.
	 *
	 * columns_desktop/tablet/mobile come from the Vendor Dashboard's own
	 * Premium Display settings screen (added in Vendor Dashboard v1.46.14),
	 * shared between Flash Sale and Featured Items since both render on the
	 * same store page. zymarg_sp_premium_display() always returns all three
	 * keys -- falling back to 4/3/2 when the Vendor Dashboard is an older
	 * version that predates them -- so no extra isset() guard is needed
	 * here.
	 */
	$responsive_columns = array(
		'responsive' => array(
			'tablet' => array(
				'layout' => array(
					'columns' => max( 1, min( 6, (int) $display['columns_tablet'] ) ),
				),
			),
			'mobile' => array(
				'layout' => array(
					'columns' => max( 1, min( 6, (int) $display['columns_mobile'] ) ),
				),
			),
		),
	);

	if ( 'carousel' !== $layout ) {
		return array_merge(
			array(
				'layout' => array(
					'type'    => 'grid',
					'columns' => max( 1, min( 6, (int) $display['columns_desktop'] ) ),
				),
			),
			$responsive_columns
		);
	}

	return array_merge(
		array(
			'layout' => array(
				'type'    => 'slider',
				'columns' => max( 1, min( 6, (int) $display['columns_desktop'] ) ),
				'slider'  => array(
					'speed' => max( 100, $glide ),
					// Premium's "continuous" rotation is a marquee. The engine's
					// slider has no marquee mode, so continuous maps to free
					// momentum scrolling -- the nearest honest equivalent. The
					// marquee speed setting therefore no longer applies.
					'free_scroll' => ( 'continuous' === $rotation ),
				),
			),
		),
		$responsive_columns
	);
}

/**
 * Render one Premium section.
 *
 * Returns an empty string when the vendor is not approved or has nothing to
 * show, so the section is absent from the markup entirely rather than
 * rendering an empty shell.
 *
 * @param int    $store_id Vendor user ID.
 * @param string $which    'flash' or 'featured'.
 * @return string
 */
function zymarg_sp_premium_section( $store_id, $which ) {
	$product_ids = zymarg_sp_premium_ids( $store_id, $which );
	if ( empty( $product_ids ) ) {
		return '';
	}

	if ( ! class_exists( 'ZYMARG_SP_Grid_Bridge' ) || ! ZYMARG_SP_Grid_Bridge::is_active() ) {
		return '';
	}

	$is_flash = ( 'flash' === $which );
	$display  = zymarg_sp_premium_display();
	$overrides = zymarg_sp_premium_layout_config( $display );

	/*
	 * v1.25.0: hide the vendor row on Featured Items ("Handpicked") cards.
	 *
	 * Every card on a store page already belongs to the vendor whose store
	 * is being viewed, so a "sold by {vendor}" row on the card is redundant
	 * here. Scoped to Featured Items only, per the site owner's request --
	 * Flash Sale keeps the engine/Template Pack default. The 'zymarg' card
	 * template defaults show_vendor to true unless a caller explicitly
	 * overrides it (see ZYMARG Template Pack's apply_defaults()), so it has
	 * to be set here rather than simply omitted.
	 */
	if ( ! $is_flash ) {
		$overrides['card']['show_vendor'] = false;
	}

	if ( $is_flash ) {
		$eyebrow = __( 'Limited Time', 'zymarg-store-page' );
		$heading = __( 'Flash Sale', 'zymarg-store-page' );
		$anchor  = 'zy-flash-sale';
		$card    = ZYMARG_SP_Grid_Bridge::CARD_FLASH;
	} else {
		$eyebrow = __( 'Handpicked', 'zymarg-store-page' );
		$heading = __( 'Featured Items', 'zymarg-store-page' );
		$anchor  = 'zy-featured-items';
		$card    = ZYMARG_SP_Grid_Bridge::CARD_GENERAL;
	}

	$grid = ZYMARG_SP_Grid_Bridge::render_products( $product_ids, $card, $overrides );

	if ( '' === $grid ) {
		return '';
	}

	ob_start();
	?>
	<?php
	/*
	 * v1.18.3: the zsp-premium-* classes carry the real layout.
	 *
	 * The Tailwind utilities below are kept, but they cannot be relied on for
	 * first paint. This page loads Tailwind's *browser* build -- a JIT compiler
	 * that downloads, scans the DOM and only then generates CSS -- so until it
	 * finishes, max-w-7xl does not exist. The section rendered full-bleed and
	 * the card grid stretched across the whole page, then snapped once Tailwind
	 * landed. On a reload the compiler is cached and it looked fine, which is
	 * what made this read as intermittent.
	 *
	 * store-page.css is ordinary CSS enqueued on wp_enqueue_scripts, so it is
	 * in the <head> before anything paints. The layout now comes from there.
	 */
	?>
	<section id="<?php echo esc_attr( $anchor ); ?>"
		aria-labelledby="<?php echo esc_attr( $anchor ); ?>-heading"
		class="zsp-premium mx-auto max-w-7xl px-4 pt-12 sm:px-6 lg:px-8">

		<div class="zsp-premium__head flex flex-wrap items-end justify-between gap-3">
			<div>
				<p class="text-xs font-semibold uppercase tracking-[0.2em] text-zy-secondary">
					<?php echo esc_html( $eyebrow ); ?>
				</p>
				<?php
				/*
				 * Hidden visually, kept in the DOM on purpose.
				 *
				 * The eyebrow above already names the section, so showing both
				 * reads as a duplicate. The section's aria-labelledby points at
				 * this heading, so removing it outright would leave the landmark
				 * unnamed for screen readers.
				 */
				?>
				<h2 id="<?php echo esc_attr( $anchor ); ?>-heading" class="sr-only">
					<?php echo esc_html( $heading ); ?>
				</h2>
			</div>

			<?php if ( $is_flash ) : ?>
				<span class="rounded-full bg-zy-gradient px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-white">
					<?php esc_html_e( 'On now', 'zymarg-store-page' ); ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="zsp-premium-grid mt-6">
			<?php
			// Pre-escaped by the engine's own template layer.
			echo $grid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Echo both Premium sections, Flash Sale first.
 *
 * Called from templates/store.php. Safe to call unconditionally.
 *
 * @param int $store_id Vendor user ID.
 * @return void
 */
function zymarg_sp_premium_render_all( $store_id ) {
	$store_id = (int) $store_id;
	if ( $store_id <= 0 || ! zymarg_sp_premium_available() ) {
		return;
	}

	echo zymarg_sp_premium_section( $store_id, 'flash' );    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo zymarg_sp_premium_section( $store_id, 'featured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/*
 * Hooked globally rather than around each render (changed in 1.19.0).
 *
 * Load-more re-renders cards in a separate AJAX request, where nothing this
 * plugin wrapped around the first render is still in scope. Scoped filters left
 * every appended card without a countdown while the first page had one.
 *
 * Global is safe because both callbacks return the incoming value untouched
 * unless the product has a live Premium flash sale, so engine-sourced flash
 * deals elsewhere -- the homepage, a WooCommerce sale window -- are unaffected.
 * A product in both systems gets Premium's window, which is correct: Premium is
 * what is actually discounting it.
 */
add_filter( 'zymarg_pack_flash_end_ts', 'zymarg_sp_premium_filter_flash_end', 10, 2 );
add_filter( 'zymarg_pack_flash_window_start', 'zymarg_sp_premium_filter_flash_window_start', 10, 2 );
