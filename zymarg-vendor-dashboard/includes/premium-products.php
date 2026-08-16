<?php
/**
 * ZYMARG Vendor Dashboard -- Premium product controls (PHASE 4 + PHASE 8).
 *
 * Once a vendor is approved for a functionality, this is where they choose
 * which products it applies to:
 *
 *   Featured Items -- pick the products to feature.
 *   Flash Sale     -- pick a product, set a sale price and an optional
 *                     start/end window.
 *
 * WHY THIS SCREEN IS BUILT AROUND SEARCH
 * --------------------------------------
 * The first version rendered a vendor's 50 newest products as a flat list of
 * checkboxes. For a vendor with thousands of products, product 51 was simply
 * unreachable. This version searches instead, and keeps the chosen products
 * pinned in a tray above the search results so a selection never scrolls or
 * filters out of view.
 *
 * THE SAVE MODEL, AND THE BUG IT AVOIDS
 * -------------------------------------
 * The first version saved by looping over the products it had rendered and
 * clearing the flag on any that were not ticked. That is safe only while the
 * whole catalogue is on screen. With search it would be destructive: search
 * "shoes", save, and every featured product outside that search would be
 * silently un-featured.
 *
 * So the tray -- not the search results -- is the payload. It always holds
 * the complete selection, rendered from stored state and edited in place.
 * The server diffs it against what is stored and touches only the difference.
 *
 * WHAT THIS FILE WRITES
 * ---------------------
 * Only the Premium post meta defined in premium.php. It never touches
 * _sale_price, _price, or the product_visibility taxonomy. That is the whole
 * reason the vendor Flash Sale cannot collide with the Product Grid plugin's
 * Featured/Flash sources or with WooCommerce's own sale system.
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.40.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many search results to return per page.
 *
 * @return int
 */
function zymarg_vd_premium_product_limit() {
	return (int) apply_filters( 'zymarg_vd_premium_product_limit', 20 );
}

/**
 * Search a vendor's published products.
 *
 * Name matching uses the normal search. An exact SKU is resolved separately
 * and promoted to the front, because WooCommerce does not include SKUs in the
 * default post search and a vendor looking up a SKU expects an exact hit.
 *
 * @param int   $vendor_id Vendor user ID.
 * @param array $args      term, page, exclude.
 * @return array{ids:array<int,int>,has_more:bool}
 */
function zymarg_vd_premium_vendor_products( $vendor_id, $args = array() ) {
	$vendor_id = (int) $vendor_id;
	if ( $vendor_id <= 0 ) {
		return array(
			'ids'      => array(),
			'has_more' => false,
		);
	}

	$term    = isset( $args['term'] ) ? trim( (string) $args['term'] ) : '';
	$page    = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
	$exclude = isset( $args['exclude'] ) ? array_map( 'intval', (array) $args['exclude'] ) : array();
	$per     = zymarg_vd_premium_product_limit();

	$query = array(
		'post_type'        => 'product',
		'post_status'      => 'publish',
		'author'           => $vendor_id,
		'posts_per_page'   => $per + 1, // One extra: its presence means "has more".
		'paged'            => $page,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	);

	if ( '' !== $term ) {
		$query['s'] = $term;
	}

	if ( ! empty( $exclude ) ) {
		$query['post__not_in'] = $exclude;
	}

	$ids = array_map( 'intval', (array) get_posts( $query ) );

	$has_more = count( $ids ) > $per;
	if ( $has_more ) {
		array_pop( $ids );
	}

	// Exact SKU match, first page only.
	if ( '' !== $term && 1 === $page && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$sku_id = (int) wc_get_product_id_by_sku( $term );

		if (
			$sku_id > 0
			&& ! in_array( $sku_id, $ids, true )
			&& ! in_array( $sku_id, $exclude, true )
			&& zymarg_vd_premium_vendor_owns_product( $sku_id, $vendor_id )
		) {
			array_unshift( $ids, $sku_id );
		}
	}

	return array(
		'ids'      => $ids,
		'has_more' => $has_more,
	);
}

/**
 * Format a stored datetime for a datetime-local input.
 *
 * @param string $stored Stored value.
 * @return string Value in Y-m-d\TH:i, or ''.
 */
function zymarg_vd_premium_datetime_input_value( $stored ) {
	$stored = trim( (string) $stored );
	if ( '' === $stored ) {
		return '';
	}

	$time = strtotime( $stored );
	return $time ? gmdate( 'Y-m-d\TH:i', $time ) : '';
}

/**
 * Normalize a datetime-local value for storage.
 *
 * @param string $raw Raw submitted value.
 * @return string Value in Y-m-d H:i:s, or ''.
 */
function zymarg_vd_premium_normalize_datetime( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$time = strtotime( $raw );
	return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '';
}

/* ---------------------------------------------------------------------- *
 * 1. RENDER
 * ---------------------------------------------------------------------- */

/**
 * One product row.
 *
 * The same markup serves the tray and the search results, so a row keeps its
 * price and date values when it moves between them -- there is only ever one
 * definition of what a row looks like.
 *
 * @param int    $product_id Product ID.
 * @param string $feature    Feature key.
 * @param bool   $picked     Whether the row starts in the tray.
 * @return string
 */
function zymarg_vd_premium_render_product_row( $product_id, $feature, $picked ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return '';
	}

	$is_flash = ( ZYMARG_VD_PREMIUM_FLASH === $feature );
	$field    = 'zvd-pick-' . esc_attr( $feature ) . '-' . (int) $product_id;
	$data     = $is_flash ? zymarg_vd_premium_get_flash_data( $product_id ) : array();

	ob_start();
	?>
	<li class="zvd-premium-product<?php echo $is_flash ? ' zvd-premium-product--flash' : ''; ?>"
		data-product="<?php echo esc_attr( $product_id ); ?>">
		<div class="zvd-premium-product__head">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $field ); ?>"
				class="zvd-premium-pick"
				data-product="<?php echo esc_attr( $product_id ); ?>"
				<?php checked( (bool) $picked ); ?>
			/>
			<label for="<?php echo esc_attr( $field ); ?>" class="zvd-premium-product__label">
				<?php echo esc_html( $product->get_name() ); ?>
			</label>
			<span class="zvd-premium-product__regular">
				<?php
				/* translators: %s: the product's normal price. */
				printf(
					esc_html__( 'Normal: %s', 'zymarg-vendor-dashboard' ),
					wp_kses_post( wc_price( (float) $product->get_regular_price() ) )
				);
				?>
			</span>
		</div>

		<?php if ( $is_flash ) : ?>
			<div class="zvd-premium-product__fields">
				<label class="zvd-premium-field">
					<span><?php esc_html_e( 'Flash price', 'zymarg-vendor-dashboard' ); ?></span>
					<input type="number" step="0.01" min="0" class="zvd-premium-flash-price"
						value="<?php echo esc_attr( isset( $data['price'] ) ? $data['price'] : '' ); ?>" />
				</label>

				<label class="zvd-premium-field">
					<span><?php esc_html_e( 'Starts', 'zymarg-vendor-dashboard' ); ?></span>
					<input type="datetime-local" class="zvd-premium-flash-start"
						value="<?php echo esc_attr( zymarg_vd_premium_datetime_input_value( isset( $data['start'] ) ? $data['start'] : '' ) ); ?>" />
				</label>

				<label class="zvd-premium-field">
					<span><?php esc_html_e( 'Ends', 'zymarg-vendor-dashboard' ); ?></span>
					<input type="datetime-local" class="zvd-premium-flash-end"
						value="<?php echo esc_attr( zymarg_vd_premium_datetime_input_value( isset( $data['end'] ) ? $data['end'] : '' ) ); ?>" />
				</label>
			</div>
		<?php endif; ?>
	</li>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the product controls for one approved functionality.
 *
 * Returns an empty string when the vendor is not approved, so the caller can
 * append this unconditionally.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return string
 */
function zymarg_vd_premium_render_product_controls( $vendor_id, $feature ) {
	if ( ! zymarg_vd_premium_vendor_can_use( $vendor_id, $feature ) ) {
		return '';
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		return '';
	}

	$is_flash = ( ZYMARG_VD_PREMIUM_FLASH === $feature );
	$selected = zymarg_vd_premium_selected_ids( $vendor_id, $feature );
	$max      = zymarg_vd_premium_max_for( $vendor_id, $feature );
	$min      = zymarg_vd_premium_min_for( $vendor_id, $feature );

	// The opening page of results excludes anything already in the tray, so a
	// product is never offered twice.
	$results = zymarg_vd_premium_vendor_products(
		$vendor_id,
		array(
			'exclude' => $selected,
		)
	);

	ob_start();
	?>
	<div class="zvd-premium-products"
		data-feature="<?php echo esc_attr( $feature ); ?>"
		data-max="<?php echo esc_attr( $max ); ?>"
		data-min="<?php echo esc_attr( $min ); ?>">

		<h4 class="zvd-premium-products__title">
			<?php
			if ( $is_flash ) {
				esc_html_e( 'Choose your flash sale products', 'zymarg-vendor-dashboard' );
			} else {
				esc_html_e( 'Choose your featured products', 'zymarg-vendor-dashboard' );
			}
			?>
		</h4>

		<p class="zvd-premium-products__hint">
			<?php if ( $is_flash ) : ?>
				<?php esc_html_e( 'The flash price must be lower than the normal price. Leave the dates empty to start straight away and run until you turn it off.', 'zymarg-vendor-dashboard' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Search your catalogue and pick the products you want to highlight.', 'zymarg-vendor-dashboard' ); ?>
			<?php endif; ?>
		</p>

		<p class="zvd-premium-count" aria-live="polite">
			<span class="zvd-premium-count__text"></span>
		</p>

		<h5 class="zvd-premium-tray__title"><?php esc_html_e( 'Your selection', 'zymarg-vendor-dashboard' ); ?></h5>

		<ul class="zvd-premium-tray zvd-premium-products__list">
			<?php
			foreach ( $selected as $product_id ) {
				echo zymarg_vd_premium_render_product_row( $product_id, $feature, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</ul>

		<p class="zvd-premium-tray__empty<?php echo empty( $selected ) ? '' : ' zvd-is-hidden'; ?>">
			<?php esc_html_e( 'Nothing chosen yet. Find a product below and tick it.', 'zymarg-vendor-dashboard' ); ?>
		</p>

		<hr class="zvd-rule" />

		<label class="zvd-premium-field zvd-premium-search__field">
			<span><?php esc_html_e( 'Find a product', 'zymarg-vendor-dashboard' ); ?></span>
			<input type="search" class="zvd-premium-search"
				placeholder="<?php esc_attr_e( 'Search by name or SKU', 'zymarg-vendor-dashboard' ); ?>" />
		</label>

		<ul class="zvd-premium-results zvd-premium-products__list">
			<?php
			foreach ( $results['ids'] as $product_id ) {
				echo zymarg_vd_premium_render_product_row( $product_id, $feature, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</ul>

		<p class="zvd-premium-results__empty zvd-is-hidden">
			<?php esc_html_e( 'No products match that search.', 'zymarg-vendor-dashboard' ); ?>
		</p>

		<div class="zvd-premium-results__more<?php echo $results['has_more'] ? '' : ' zvd-is-hidden'; ?>">
			<button type="button" class="zvd-btn zvd-btn--secondary zvd-premium-load-more" data-page="1">
				<?php esc_html_e( 'Load more', 'zymarg-vendor-dashboard' ); ?>
			</button>
		</div>

		<div class="zvd-premium-products__actions">
			<button type="button" class="zvd-btn zvd-btn--primary <?php echo $is_flash ? 'zvd-premium-save-flash' : 'zvd-premium-save-featured'; ?>">
				<?php
				if ( $is_flash ) {
					esc_html_e( 'Save flash sale', 'zymarg-vendor-dashboard' );
				} else {
					esc_html_e( 'Save featured products', 'zymarg-vendor-dashboard' );
				}
				?>
			</button>
			<span class="zvd-status-msg zvd-premium-products__status" aria-live="polite"></span>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------- *
 * 2. AJAX
 * ---------------------------------------------------------------------- */

/**
 * Shared guard for the product-control endpoints.
 *
 * @param string $feature Feature key.
 * @return int Vendor user ID.
 */
function zymarg_vd_premium_products_guard( $feature ) {
	check_ajax_referer( 'zymarg_vd_premium_vendor', 'nonce' );

	$vendor_id = get_current_user_id();
	if ( $vendor_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	// The approval gate, re-checked on every request. A vendor whose approval
	// was revoked while this screen sat open cannot save from a stale page.
	if ( ! zymarg_vd_premium_vendor_can_use( $vendor_id, $feature ) ) {
		wp_send_json_error(
			array( 'message' => __( 'This is not available on your account right now. Reload the page.', 'zymarg-vendor-dashboard' ) ),
			403
		);
	}

	return $vendor_id;
}

/**
 * Does this vendor own this product?
 *
 * Ownership is checked per product on every save, so a crafted request
 * cannot reach into another vendor's catalogue.
 *
 * @param int $product_id Product ID.
 * @param int $vendor_id  Vendor user ID.
 * @return bool
 */
function zymarg_vd_premium_vendor_owns_product( $product_id, $vendor_id ) {
	return zymarg_vd_premium_product_vendor_id( $product_id ) === (int) $vendor_id;
}

/**
 * Read the requested feature from the request.
 *
 * @return string
 */
function zymarg_vd_premium_requested_feature() {
	$feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';

	return zymarg_vd_premium_is_feature( $feature ) ? $feature : ZYMARG_VD_PREMIUM_FEATURED;
}

/**
 * AJAX: search the vendor's catalogue.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_search_products() {
	$feature   = zymarg_vd_premium_requested_feature();
	$vendor_id = zymarg_vd_premium_products_guard( $feature );

	$term    = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	$page    = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
	$exclude = isset( $_POST['exclude'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['exclude'] ) ) : array();

	$results = zymarg_vd_premium_vendor_products(
		$vendor_id,
		array(
			'term'    => $term,
			'page'    => $page,
			'exclude' => $exclude,
		)
	);

	$html = '';
	foreach ( $results['ids'] as $product_id ) {
		$html .= zymarg_vd_premium_render_product_row( $product_id, $feature, false );
	}

	wp_send_json_success(
		array(
			'html'     => $html,
			'hasMore'  => (bool) $results['has_more'],
			'page'     => $page,
			'count'    => count( $results['ids'] ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_search_products', 'zymarg_vd_premium_ajax_search_products' );

/**
 * AJAX: save the vendor's Featured Items selection.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_save_featured() {
	$vendor_id = zymarg_vd_premium_products_guard( ZYMARG_VD_PREMIUM_FEATURED );

	$selected = isset( $_POST['products'] ) ? (array) wp_unslash( $_POST['products'] ) : array();
	$selected = array_values( array_unique( array_map( 'intval', $selected ) ) );

	// Ownership first, so a crafted payload cannot inflate the count.
	$selected = array_values(
		array_filter(
			$selected,
			function ( $product_id ) use ( $vendor_id ) {
				return zymarg_vd_premium_vendor_owns_product( $product_id, $vendor_id );
			}
		)
	);

	$max = zymarg_vd_premium_max_for( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED );
	if ( count( $selected ) > $max ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %d: maximum number of products. */
					__( 'You can feature up to %d products. Remove a few and save again.', 'zymarg-vendor-dashboard' ),
					$max
				),
			),
			400
		);
	}

	// Diff against what is stored. Only the difference is written, so products
	// the vendor never saw in this session are left exactly as they were.
	$current = zymarg_vd_premium_selected_ids( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED );

	foreach ( array_diff( $selected, $current ) as $product_id ) {
		update_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FEATURED, 'yes' );
	}

	foreach ( array_diff( $current, $selected ) as $product_id ) {
		delete_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FEATURED );
	}

	$count = count( $selected );
	$min   = zymarg_vd_premium_min_for( $vendor_id, ZYMARG_VD_PREMIUM_FEATURED );

	$message = sprintf(
		/* translators: %d: number of featured products. */
		_n( '%d product featured.', '%d products featured.', $count, 'zymarg-vendor-dashboard' ),
		$count
	);

	// Saving fewer than the minimum is allowed -- a vendor needs to be able to
	// build the list up over several visits -- but say plainly that the
	// section is not live yet, or they will wonder why nothing changed.
	if ( $min > 0 && $count < $min ) {
		$message .= ' ' . sprintf(
			/* translators: %d: minimum number of products required. */
			__( 'Not live yet: pick at least %d for this section to show on your store page.', 'zymarg-vendor-dashboard' ),
			$min
		);
	}

	wp_send_json_success(
		array(
			'message' => $message,
			'count'   => $count,
			'live'    => ( 0 === $min || $count >= $min ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_save_featured', 'zymarg_vd_premium_ajax_save_featured' );

/**
 * AJAX: save the vendor's Flash Sale setup.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_save_flash() {
	$vendor_id = zymarg_vd_premium_products_guard( ZYMARG_VD_PREMIUM_FLASH );

	$rows = isset( $_POST['rows'] ) ? (array) wp_unslash( $_POST['rows'] ) : array();

	$max = zymarg_vd_premium_max_for( $vendor_id, ZYMARG_VD_PREMIUM_FLASH );
	if ( count( $rows ) > $max ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %d: maximum number of products. */
					__( 'You can run a flash sale on up to %d products. Remove a few and save again.', 'zymarg-vendor-dashboard' ),
					$max
				),
			),
			400
		);
	}

	$saved    = array();
	$problems = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['product'] ) ) {
			continue;
		}

		$product_id = (int) $row['product'];
		if ( ! zymarg_vd_premium_vendor_owns_product( $product_id, $vendor_id ) ) {
			continue;
		}

		$price   = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$regular = $product ? (float) $product->get_regular_price() : 0.0;
		$name    = $product ? $product->get_name() : (string) $product_id;

		// Reject a price that would not actually be a discount. The read layer
		// ignores such a price anyway; refusing it here means the vendor finds
		// out now instead of wondering why nothing changed on their store.
		if ( $price <= 0 ) {
			$problems[] = sprintf(
				/* translators: %s: product name. */
				__( '%s needs a flash price above zero.', 'zymarg-vendor-dashboard' ),
				$name
			);
			continue;
		}

		if ( $regular > 0 && $price >= $regular ) {
			$problems[] = sprintf(
				/* translators: %s: product name. */
				__( '%s: the flash price must be lower than the normal price.', 'zymarg-vendor-dashboard' ),
				$name
			);
			continue;
		}

		$start = zymarg_vd_premium_normalize_datetime( isset( $row['start'] ) ? $row['start'] : '' );
		$end   = zymarg_vd_premium_normalize_datetime( isset( $row['end'] ) ? $row['end'] : '' );

		if ( '' !== $start && '' !== $end && strtotime( $end ) <= strtotime( $start ) ) {
			$problems[] = sprintf(
				/* translators: %s: product name. */
				__( '%s: the end time must be after the start time.', 'zymarg-vendor-dashboard' ),
				$name
			);
			continue;
		}

		update_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_ON, 'yes' );
		update_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_PRICE, (string) $price );
		update_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_START, $start );
		update_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_END, $end );

		$saved[] = $product_id;
	}

	// Anything stored but no longer in the tray is switched off. A row that
	// failed validation is NOT cleared -- it stays as it was, so a typo in a
	// price cannot silently end a running sale.
	$current = zymarg_vd_premium_selected_ids( $vendor_id, ZYMARG_VD_PREMIUM_FLASH );
	$keep    = array_merge( $saved, zymarg_vd_premium_flash_rows_attempted( $rows ) );

	$removed = 0;
	foreach ( array_diff( $current, $keep ) as $product_id ) {
		if ( ! zymarg_vd_premium_vendor_owns_product( $product_id, $vendor_id ) ) {
			continue;
		}
		delete_post_meta( $product_id, ZYMARG_VD_PREMIUM_META_FLASH_ON );
		$removed++;
	}

	// WooCommerce caches variation price sets; a changed flash window must
	// invalidate them or a variable product keeps quoting the old price.
	if ( ( ! empty( $saved ) || $removed > 0 ) && function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	if ( ! empty( $problems ) ) {
		wp_send_json_error(
			array(
				'message'  => implode( ' ', $problems ),
				'saved'    => count( $saved ),
				'problems' => $problems,
			),
			400
		);
	}

	$count = count( $saved );

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %d: number of products on flash sale. */
				_n( '%d product on flash sale.', '%d products on flash sale.', $count, 'zymarg-vendor-dashboard' ),
				$count
			),
			'count'   => $count,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_save_flash', 'zymarg_vd_premium_ajax_save_flash' );

/**
 * The product IDs a flash payload tried to set, valid or not.
 *
 * Used so a row that failed validation is left alone rather than switched
 * off: the vendor asked for it to be on, they just typed something wrong.
 *
 * @param array $rows Submitted rows.
 * @return array<int,int>
 */
function zymarg_vd_premium_flash_rows_attempted( array $rows ) {
	$ids = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && ! empty( $row['product'] ) ) {
			$ids[] = (int) $row['product'];
		}
	}

	return $ids;
}
