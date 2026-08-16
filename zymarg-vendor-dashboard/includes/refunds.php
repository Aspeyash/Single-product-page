<?php
/**
 * ZYMARG Vendor Dashboard — Native Refund Requests.
 *
 * A self-contained refund-request workflow that works on Dokan Lite (no Dokan
 * Pro): buyers request a refund from their order page, vendors review the
 * requests in a "Refunds" screen in the dashboard and Approve (which records a
 * WooCommerce refund for the vendor's share) or Reject with a note.
 *
 * Toggle via Settings -> ZYMARG Vendor ("Refund requests").
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ZYMARG_REFUND_CPT' ) ) {
	define( 'ZYMARG_REFUND_CPT', 'zymarg_refund' );
}

/**
 * Whether the refund-requests feature is active.
 *
 * @return bool
 */
function zymarg_vd_refunds_enabled() {
	// Defer to Dokan Pro's RMA module when it is active; otherwise (incl.
	// Lite-only) the native refund workflow runs.
	if ( function_exists( 'zymarg_vd_pro_module_active' ) && zymarg_vd_pro_module_active( 'rma' ) ) {
		return false;
	}
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'refunds' );
}

/**
 * Refund request statuses.
 *
 * @return array<string,string>
 */
function zymarg_vd_refund_statuses() {
	return array(
		'pending'  => __( 'Pending', 'zymarg-vendor-dashboard' ),
		'approved' => __( 'Approved', 'zymarg-vendor-dashboard' ),
		'rejected' => __( 'Rejected', 'zymarg-vendor-dashboard' ),
	);
}

/**
 * How many days after an order a refund may be requested.
 *
 * @return int
 */
function zymarg_vd_refund_window_days() {
	return (int) apply_filters( 'zymarg_vd_refund_window_days', 14 );
}

/* ====================================================================== *
 * Post type
 * ====================================================================== */

/**
 * Register the (private) refund request post type.
 *
 * @return void
 */
function zymarg_vd_register_refund_cpt() {
	register_post_type(
		ZYMARG_REFUND_CPT,
		array(
			'label'               => __( 'Refund Requests', 'zymarg-vendor-dashboard' ),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'supports'            => array( 'author' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'zymarg_vd_register_refund_cpt' );

/* ====================================================================== *
 * Dashboard wiring (toggle / nav / section / assets)
 * ====================================================================== */

/**
 * Register the toggle.
 *
 * @param array $registry Feature registry.
 * @return array
 */
function zymarg_vd_refunds_registry( $registry ) {
	$registry['refunds'] = __( 'Refund requests (buyers request, vendors approve)', 'zymarg-vendor-dashboard' );
	return $registry;
}
add_filter( 'zymarg_vd_feature_registry', 'zymarg_vd_refunds_registry' );

/**
 * Add a "Refunds" item to the sidebar (after Orders).
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_refunds_nav_item( $items ) {
	$mine = zymarg_vd_refunds_enabled();
	$rma  = function_exists( 'zymarg_vd_pro_module_active' ) && zymarg_vd_pro_module_active( 'rma' );
	if ( ! $mine && ! $rma ) {
		return $items;
	}
	// Native section when mine is on; otherwise link to Dokan Pro's RMA page.
	$ep  = $mine ? '' : 'return-request';
	$new = array();
	foreach ( $items as $item ) {
		$new[] = $item;
		if ( isset( $item[0] ) && 'orders' === $item[0] ) {
			$new[] = array( 'refunds', __( 'Refunds', 'zymarg-vendor-dashboard' ), 'refund', $ep );
		}
	}
	return $new;
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_refunds_nav_item', 6 );

/**
 * Register the in-shell section key.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_refunds_native_section( $sections ) {
	if ( zymarg_vd_refunds_enabled() ) {
		$sections[] = 'refunds';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_refunds_native_section' );

/**
 * Render the section.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_refunds_render( $html, $active, $user ) {
	if ( 'refunds' !== $active || ! zymarg_vd_refunds_enabled() ) {
		return $html;
	}
	return zymarg_vd_render_refunds_section( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_refunds_render', 10, 3 );

/**
 * Enqueue refund assets on the vendor dashboard AND the customer order page.
 *
 * @return void
 */
function zymarg_vd_refunds_enqueue() {
	if ( ! zymarg_vd_refunds_enabled() ) {
		return;
	}
	$on_dashboard = function_exists( 'zymarg_os_is_vendor_dashboard' ) && zymarg_os_is_vendor_dashboard();
	$on_account   = function_exists( 'is_account_page' ) && is_account_page();

	if ( ! $on_dashboard && ! $on_account ) {
		return;
	}

	$ver = ZYMARG_VD_VERSION;
	if ( function_exists( 'zymarg_vd_enqueue_addons_css' ) ) {
		zymarg_vd_enqueue_addons_css( $ver );
	} else {
		wp_enqueue_style( 'zymarg-vd-addons', ZYMARG_VD_URL . 'assets/css/addons.css', array(), $ver );
	}

	wp_enqueue_script( 'zymarg-vd-refunds', ZYMARG_VD_URL . 'assets/js/refunds.js', array(), $ver, true );
	wp_localize_script(
		'zymarg-vd-refunds',
		'ZymargRefunds',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_refunds' ),
			'i18n'    => array(
				'working'       => __( 'Working…', 'zymarg-vendor-dashboard' ),
				'confirmReject' => __( 'Reject this refund request?', 'zymarg-vendor-dashboard' ),
				'error'         => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'zymarg_vd_refunds_enqueue', 22 );

/* ====================================================================== *
 * Helpers
 * ====================================================================== */

/**
 * Distinct vendors (product authors) in an order, with their share.
 *
 * @param WC_Order $order Order.
 * @return array<int,array{name:string,amount:float}>
 */
function zymarg_vd_refund_order_vendors( $order ) {
	$vendors = array();
	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();
		if ( ! $product_id ) {
			continue;
		}
		$vendor_id = (int) get_post_field( 'post_author', $product_id );
		if ( ! $vendor_id ) {
			continue;
		}
		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			$name = function_exists( 'zymarg_os_vendor_store_name' ) ? zymarg_os_vendor_store_name( $vendor_id ) : '';
			$vendors[ $vendor_id ] = array(
				'name'   => $name ? $name : get_the_author_meta( 'display_name', $vendor_id ),
				'amount' => 0.0,
			);
		}
		$vendors[ $vendor_id ]['amount'] += (float) $item->get_total() + (float) $item->get_total_tax();
	}
	return $vendors;
}

/**
 * Whether a customer already has a pending/approved request for an order+vendor.
 *
 * @param int $order_id  Order ID.
 * @param int $vendor_id Vendor ID.
 * @return bool
 */
function zymarg_vd_refund_exists( $order_id, $vendor_id ) {
	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_REFUND_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => '_zr_order',
					'value' => (int) $order_id,
				),
				array(
					'key'   => '_zr_vendor',
					'value' => (int) $vendor_id,
				),
				array(
					'key'     => '_zr_status',
					'value'   => array( 'pending', 'approved' ),
					'compare' => 'IN',
				),
			),
		)
	);
	return ! empty( $q->posts );
}

/**
 * Whether an order is within the refund window and refundable.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function zymarg_vd_refund_order_eligible( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return false;
	}
	$ok_status = (array) apply_filters( 'zymarg_vd_refund_order_statuses', array( 'processing', 'completed', 'on-hold' ) );
	if ( ! in_array( $order->get_status(), $ok_status, true ) ) {
		return false;
	}
	if ( $order->get_remaining_refund_amount() <= 0 ) {
		return false;
	}
	$created = $order->get_date_created();
	if ( $created ) {
		$days = ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS;
		if ( $days > zymarg_vd_refund_window_days() ) {
			return false;
		}
	}
	return true;
}

/* ====================================================================== *
 * Buyer: request form on the order page
 * ====================================================================== */

/**
 * Show a refund-request form under the order details table.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function zymarg_vd_refund_buyer_form( $order ) {
	if ( ! zymarg_vd_refunds_enabled() || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}
	if ( get_current_user_id() !== (int) $order->get_customer_id() ) {
		return;
	}
	if ( ! zymarg_vd_refund_order_eligible( $order ) ) {
		return;
	}

	$vendors = zymarg_vd_refund_order_vendors( $order );
	// Drop vendors that already have an open request.
	foreach ( array_keys( $vendors ) as $vid ) {
		if ( zymarg_vd_refund_exists( $order->get_id(), $vid ) ) {
			unset( $vendors[ $vid ] );
		}
	}
	if ( empty( $vendors ) ) {
		return;
	}

	$symbol = get_woocommerce_currency_symbol();
	?>
	<section class="zymarg-zr-buyer">
		<h2 class="zymarg-zr-buyer__title"><?php esc_html_e( 'Need a refund?', 'zymarg-vendor-dashboard' ); ?></h2>
		<form class="zymarg-zr-buyer__form" id="zymarg-zr-request" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
			<?php if ( count( $vendors ) > 1 ) : ?>
				<label class="zymarg-zp-field">
					<span class="zymarg-zp-field__label"><?php esc_html_e( 'Which seller?', 'zymarg-vendor-dashboard' ); ?></span>
					<select name="vendor">
						<?php foreach ( $vendors as $vid => $info ) : ?>
							<option value="<?php echo esc_attr( $vid ); ?>"><?php echo esc_html( $info['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php else : ?>
				<?php
				$only = array_key_first( $vendors );
				?>
				<input type="hidden" name="vendor" value="<?php echo esc_attr( $only ); ?>">
			<?php endif; ?>

			<label class="zymarg-zp-field">
				<span class="zymarg-zp-field__label"><?php printf( /* translators: %s currency symbol. */ esc_html__( 'Amount requested (%s) — leave blank for full', 'zymarg-vendor-dashboard' ), esc_html( $symbol ) ); ?></span>
				<input type="number" name="amount" step="0.01" min="0">
			</label>

			<label class="zymarg-zp-field">
				<span class="zymarg-zp-field__label"><?php esc_html_e( 'Reason', 'zymarg-vendor-dashboard' ); ?></span>
				<textarea name="reason" rows="3" required maxlength="500" placeholder="<?php esc_attr_e( 'Tell the seller why you need a refund', 'zymarg-vendor-dashboard' ); ?>"></textarea>
			</label>

			<div class="zymarg-zp-form__foot">
				<button type="submit" class="zymarg-zr-btn"><?php esc_html_e( 'Request refund', 'zymarg-vendor-dashboard' ); ?></button>
				<span class="zymarg-zp-msg" role="status" aria-live="polite"></span>
			</div>
		</form>
	</section>
	<?php
}
add_action( 'woocommerce_order_details_after_order_table', 'zymarg_vd_refund_buyer_form', 20 );

/**
 * AJAX: buyer submits a refund request.
 *
 * @return void
 */
function zymarg_vd_refund_request_ajax() {
	check_ajax_referer( 'zymarg_vd_refunds', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please sign in.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! zymarg_vd_refunds_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Refund requests are turned off.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$order_id  = isset( $_POST['order'] ) ? (int) $_POST['order'] : 0;
	$vendor_id = isset( $_POST['vendor'] ) ? (int) $_POST['vendor'] : 0;
	$amount    = isset( $_POST['amount'] ) && '' !== $_POST['amount'] ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
	$reason    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

	$order = wc_get_order( $order_id );
	if ( ! $order || get_current_user_id() !== (int) $order->get_customer_id() ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}
	if ( '' === $reason ) {
		wp_send_json_error( array( 'message' => __( 'Please add a reason.', 'zymarg-vendor-dashboard' ) ) );
	}
	if ( ! zymarg_vd_refund_order_eligible( $order ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is not eligible for a refund request.', 'zymarg-vendor-dashboard' ) ) );
	}

	$vendors = zymarg_vd_refund_order_vendors( $order );
	if ( ! isset( $vendors[ $vendor_id ] ) ) {
		wp_send_json_error( array( 'message' => __( 'That seller is not in this order.', 'zymarg-vendor-dashboard' ) ) );
	}
	if ( zymarg_vd_refund_exists( $order_id, $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You already have a refund request for this seller on this order.', 'zymarg-vendor-dashboard' ) ) );
	}

	$max = (float) $vendors[ $vendor_id ]['amount'];
	if ( $amount > $max + 0.001 ) {
		$amount = $max;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => ZYMARG_REFUND_CPT,
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
			'post_title'  => sprintf( 'Refund req — order #%d', $order_id ),
		),
		true
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Could not save your request.', 'zymarg-vendor-dashboard' ) ) );
	}

	update_post_meta( $post_id, '_zr_order', $order_id );
	update_post_meta( $post_id, '_zr_vendor', $vendor_id );
	update_post_meta( $post_id, '_zr_amount', $amount );
	update_post_meta( $post_id, '_zr_reason', $reason );
	update_post_meta( $post_id, '_zr_status', 'pending' );

	$order->add_order_note( sprintf( /* translators: %s amount. */ __( 'Customer requested a refund (%s) via ZYMARG.', 'zymarg-vendor-dashboard' ), $amount > 0 ? wp_strip_all_tags( wc_price( $amount ) ) : __( 'full', 'zymarg-vendor-dashboard' ) ) );

	/**
	 * Fires after a buyer requests a refund.
	 *
	 * @param int $post_id   Request ID.
	 * @param int $order_id  Order ID.
	 * @param int $vendor_id Vendor ID.
	 */
	do_action( 'zymarg_vd_refund_requested', $post_id, $order_id, $vendor_id );

	wp_send_json_success( array( 'message' => __( 'Refund requested. The seller will review it.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_refund_request', 'zymarg_vd_refund_request_ajax' );

/* ====================================================================== *
 * Vendor: review screen
 * ====================================================================== */

/**
 * Render the vendor's Refunds section.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_refunds_section( $user ) {
	$vendor_id = (int) $user->ID;
	$requests  = zymarg_vd_refund_get_for_vendor( $vendor_id );
	$labels    = zymarg_vd_refund_statuses();

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Refund requests', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Review and process refund requests from your customers.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-zpe-card">
		<div class="zymarg-zpe-card__accent"></div>
		<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Refund Requests', 'zymarg-vendor-dashboard' ); ?></div>
		<div class="zymarg-zpe-card__body">
		<?php if ( empty( $requests ) ) : ?>
			<p class="zymarg-vendor-empty"><?php esc_html_e( 'No refund requests yet.', 'zymarg-vendor-dashboard' ); ?></p>
		<?php else : ?>
			<div class="zymarg-zp-table-wrap">
				<table class="zymarg-zp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'zymarg-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Order', 'zymarg-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'zymarg-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Requested', 'zymarg-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'zymarg-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Status', 'zymarg-vendor-dashboard' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $requests as $r ) : ?>
							<tr data-id="<?php echo esc_attr( $r['id'] ); ?>">
								<td data-th="<?php esc_attr_e( 'Date', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_html( $r['date'] ); ?></td>
								<td data-th="<?php esc_attr_e( 'Order', 'zymarg-vendor-dashboard' ); ?>">#<?php echo esc_html( $r['order_id'] ); ?></td>
								<td data-th="<?php esc_attr_e( 'Customer', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_html( $r['customer'] ); ?></td>
								<td data-th="<?php esc_attr_e( 'Requested', 'zymarg-vendor-dashboard' ); ?>"><?php echo $r['amount'] > 0 ? wp_kses_post( wc_price( $r['amount'] ) ) : esc_html__( 'Full', 'zymarg-vendor-dashboard' ); ?></td>
								<td data-th="<?php esc_attr_e( 'Reason', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_html( $r['reason'] ); ?></td>
								<td data-th="<?php esc_attr_e( 'Status', 'zymarg-vendor-dashboard' ); ?>">
									<span class="zymarg-zp-badge zymarg-zp-badge--<?php echo esc_attr( 'approved' === $r['status'] ? 'paid' : ( 'rejected' === $r['status'] ? 'rejected' : 'pending' ) ); ?>">
										<?php echo esc_html( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ); ?>
									</span>
									<?php if ( ! empty( $r['admin_note'] ) ) : ?>
										<span class="zymarg-zp-adminnote"><?php echo esc_html( $r['admin_note'] ); ?></span>
									<?php endif; ?>
								</td>
								<td data-th="">
									<?php if ( 'pending' === $r['status'] ) : ?>
										<div class="zymarg-zr-actions" data-id="<?php echo esc_attr( $r['id'] ); ?>" data-max="<?php echo esc_attr( $r['max'] ); ?>">
											<input type="number" class="zymarg-zr-amount" step="0.01" min="0" max="<?php echo esc_attr( $r['max'] ); ?>" value="<?php echo esc_attr( $r['amount'] > 0 ? $r['amount'] : $r['max'] ); ?>" placeholder="<?php esc_attr_e( 'Amount', 'zymarg-vendor-dashboard' ); ?>">
											<input type="text" class="zymarg-zr-note" placeholder="<?php esc_attr_e( 'Note', 'zymarg-vendor-dashboard' ); ?>">
											<button type="button" class="zymarg-zr-approve" data-id="<?php echo esc_attr( $r['id'] ); ?>"><?php esc_html_e( 'Approve & refund', 'zymarg-vendor-dashboard' ); ?></button>
											<button type="button" class="zymarg-zr-reject" data-id="<?php echo esc_attr( $r['id'] ); ?>"><?php esc_html_e( 'Reject', 'zymarg-vendor-dashboard' ); ?></button>
										</div>
									<?php elseif ( 'approved' === $r['status'] && $r['refunded'] > 0 ) : ?>
										<?php echo wp_kses_post( wc_price( $r['refunded'] ) ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Fetch refund requests for a vendor (newest first).
 *
 * @param int $vendor_id Vendor ID.
 * @return array
 */
function zymarg_vd_refund_get_for_vendor( $vendor_id ) {
	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_REFUND_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_zr_vendor',
					'value' => (int) $vendor_id,
				),
			),
		)
	);

	$rows = array();
	foreach ( $q->posts as $post ) {
		$order_id = (int) get_post_meta( $post->ID, '_zr_order', true );
		$order    = wc_get_order( $order_id );
		$max      = 0.0;
		if ( $order ) {
			$vendors = zymarg_vd_refund_order_vendors( $order );
			$max     = isset( $vendors[ $vendor_id ] ) ? (float) $vendors[ $vendor_id ]['amount'] : 0.0;
			$remain  = (float) $order->get_remaining_refund_amount();
			if ( $remain >= 0 && $remain < $max ) {
				$max = $remain;
			}
		}
		$customer = $order ? trim( $order->get_formatted_billing_full_name() ) : '';
		$rows[]   = array(
			'id'         => $post->ID,
			'order_id'   => $order_id,
			'customer'   => $customer ? $customer : ( '#' . $post->post_author ),
			'amount'     => (float) get_post_meta( $post->ID, '_zr_amount', true ),
			'reason'     => (string) get_post_meta( $post->ID, '_zr_reason', true ),
			'status'     => (string) get_post_meta( $post->ID, '_zr_status', true ),
			'admin_note' => (string) get_post_meta( $post->ID, '_zr_admin_note', true ),
			'refunded'   => (float) get_post_meta( $post->ID, '_zr_refunded', true ),
			'max'        => $max,
			'date'       => get_the_date( get_option( 'date_format' ), $post ),
		);
	}
	return $rows;
}

/**
 * AJAX: vendor approves (refunds) or rejects a request.
 *
 * @return void
 */
function zymarg_vd_refund_action_ajax() {
	check_ajax_referer( 'zymarg_vd_refunds', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'zymarg_os_can_view_vendor_dashboard' ) || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! zymarg_vd_refunds_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Refund requests are turned off.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();
	$id        = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$action    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$amount    = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
	$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	$post = $id ? get_post( $id ) : null;
	if ( ! $post || ZYMARG_REFUND_CPT !== $post->post_type ) {
		wp_send_json_error( array( 'message' => __( 'Request not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	$req_vendor = (int) get_post_meta( $id, '_zr_vendor', true );
	if ( $req_vendor !== (int) $vendor_id && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You can only handle your own requests.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( 'pending' !== get_post_meta( $id, '_zr_status', true ) ) {
		wp_send_json_error( array( 'message' => __( 'This request was already handled.', 'zymarg-vendor-dashboard' ) ) );
	}

	if ( 'reject' === $action ) {
		update_post_meta( $id, '_zr_status', 'rejected' );
		if ( $note ) {
			update_post_meta( $id, '_zr_admin_note', $note );
		}
		wp_send_json_success( array( 'message' => __( 'Request rejected.', 'zymarg-vendor-dashboard' ), 'reload' => true ) );
	}

	if ( 'approve' !== $action ) {
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Approve -> create a WooCommerce refund record for the amount.
	$order_id = (int) get_post_meta( $id, '_zr_order', true );
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	$remaining = (float) $order->get_remaining_refund_amount();
	if ( $amount <= 0 ) {
		$amount = $remaining;
	}
	if ( $amount > $remaining + 0.001 ) {
		$amount = $remaining;
	}
	if ( $amount <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'There is nothing left to refund on this order.', 'zymarg-vendor-dashboard' ) ) );
	}

	$reason = sprintf( /* translators: %d vendor id. */ __( 'Approved by seller via ZYMARG. %s', 'zymarg-vendor-dashboard' ), $note );

	$refund = wc_create_refund(
		array(
			'amount'         => wc_format_decimal( $amount ),
			'reason'         => $reason,
			'order_id'       => $order_id,
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);

	if ( is_wp_error( $refund ) ) {
		wp_send_json_error( array( 'message' => $refund->get_error_message() ) );
	}

	update_post_meta( $id, '_zr_status', 'approved' );
	update_post_meta( $id, '_zr_refunded', (float) $amount );
	if ( $note ) {
		update_post_meta( $id, '_zr_admin_note', $note );
	}

	/**
	 * Fires after a vendor approves a refund request.
	 *
	 * @param int   $id       Request ID.
	 * @param int   $order_id Order ID.
	 * @param float $amount   Refunded amount.
	 */
	do_action( 'zymarg_vd_refund_approved', $id, $order_id, $amount );

	wp_send_json_success( array( 'message' => __( 'Refund recorded.', 'zymarg-vendor-dashboard' ), 'reload' => true ) );
}
add_action( 'wp_ajax_zymarg_vd_refund_action', 'zymarg_vd_refund_action_ajax' );
