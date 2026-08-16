<?php
/**
 * ZYMARG Vendor Dashboard — Native Payouts (Bangladesh-ready).
 *
 * A self-contained withdrawal/payout system that runs on Dokan Lite (free) —
 * no Dokan Pro required. Vendors save a payout method (bKash, Nagad, Rocket or
 * bank transfer), request a withdrawal of their available balance, and track
 * the status. Marketplace admins approve / mark-paid / reject requests from
 * wp-admin -> Settings -> ZYMARG Payouts.
 *
 * Integration is loose: it reuses the dashboard shell, the existing "payments"
 * feature toggle, and the earnings balance helper, all guarded so nothing
 * breaks if Dokan is absent or updated.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * Constants / helpers
 * ====================================================================== */

/**
 * Custom post type that stores a single payout request.
 */
if ( ! defined( 'ZYMARG_PAYOUT_CPT' ) ) {
	define( 'ZYMARG_PAYOUT_CPT', 'zymarg_payout' );
}

/**
 * The payout methods this build supports, with their field maps. Filterable so
 * a site can add a local gateway later.
 *
 * @return array<string,array>
 */
function zymarg_vd_payout_methods() {
	return apply_filters(
		'zymarg_vd_payout_methods',
		array(
			'bkash'  => array(
				'label'  => __( 'bKash', 'zymarg-vendor-dashboard' ),
				'type'   => 'mobile',
				'fields' => array(
					'number' => __( 'bKash account number', 'zymarg-vendor-dashboard' ),
				),
			),
			'nagad'  => array(
				'label'  => __( 'Nagad', 'zymarg-vendor-dashboard' ),
				'type'   => 'mobile',
				'fields' => array(
					'number' => __( 'Nagad account number', 'zymarg-vendor-dashboard' ),
				),
			),
			'rocket' => array(
				'label'  => __( 'Rocket', 'zymarg-vendor-dashboard' ),
				'type'   => 'mobile',
				'fields' => array(
					'number' => __( 'Rocket account number', 'zymarg-vendor-dashboard' ),
				),
			),
			'bank'   => array(
				'label'  => __( 'Bank transfer', 'zymarg-vendor-dashboard' ),
				'type'   => 'bank',
				'fields' => array(
					'ac_name'   => __( 'Account holder name', 'zymarg-vendor-dashboard' ),
					'ac_number' => __( 'Account number', 'zymarg-vendor-dashboard' ),
					'bank_name' => __( 'Bank name', 'zymarg-vendor-dashboard' ),
					'branch'    => __( 'Branch', 'zymarg-vendor-dashboard' ),
					'routing'   => __( 'Routing number', 'zymarg-vendor-dashboard' ),
				),
			),
		)
	);
}

/**
 * The minimum amount a vendor may withdraw in one request.
 *
 * @return float
 */
function zymarg_vd_payout_min() {
	return (float) apply_filters( 'zymarg_vd_payout_min', 500 );
}

/**
 * The payout request statuses (key => human label).
 *
 * @return array<string,string>
 */
function zymarg_vd_payout_statuses() {
	return array(
		'pending'   => __( 'Pending', 'zymarg-vendor-dashboard' ),
		'approved'  => __( 'Approved', 'zymarg-vendor-dashboard' ),
		'paid'      => __( 'Paid', 'zymarg-vendor-dashboard' ),
		'rejected'  => __( 'Rejected', 'zymarg-vendor-dashboard' ),
		'cancelled' => __( 'Cancelled', 'zymarg-vendor-dashboard' ),
	);
}

/**
 * Whether the Payouts module is active (the "payments" feature toggle).
 *
 * @return bool
 */
function zymarg_vd_payouts_enabled() {
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'payments' );
}

/* ====================================================================== *
 * Post type
 * ====================================================================== */

/**
 * Register the (private) payout request post type.
 *
 * @return void
 */
function zymarg_vd_register_payout_cpt() {
	register_post_type(
		ZYMARG_PAYOUT_CPT,
		array(
			'label'               => __( 'Payout Requests', 'zymarg-vendor-dashboard' ),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'hierarchical'        => false,
			'supports'            => array( 'author' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'zymarg_vd_register_payout_cpt' );

/* ====================================================================== *
 * Balance maths
 * ====================================================================== */

/**
 * Sum of a vendor's payout requests in the given statuses.
 *
 * @param int      $vendor_id Vendor user ID.
 * @param string[] $statuses  Status keys.
 * @return float
 */
function zymarg_vd_payout_sum( $vendor_id, $statuses ) {
	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_PAYOUT_CPT,
			'post_status'    => 'any',
			'author'         => (int) $vendor_id,
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_zv_status',
					'value'   => (array) $statuses,
					'compare' => 'IN',
				),
			),
		)
	);

	$total = 0.0;
	foreach ( $q->posts as $pid ) {
		$total += (float) get_post_meta( $pid, '_zv_amount', true );
	}
	return $total;
}

/**
 * Compute a vendor's payout figures.
 *
 * gross    = Dokan balance when available, else lifetime earnings from
 *            completed/processing orders (vendor share).
 * inflight = pending + approved requests (reserved, not yet paid).
 * paid     = lifetime amount paid out through this module.
 * available= max(0, gross - inflight - paid).
 *
 * @param int $vendor_id Vendor user ID.
 * @return array{gross:float,available:float,inflight:float,paid:float}
 */
function zymarg_vd_payout_balance( $vendor_id ) {
	$gross = null;

	if ( function_exists( 'dokan_get_seller_balance' ) ) {
		$gross = (float) dokan_get_seller_balance( $vendor_id, false );
	}

	if ( null === $gross && function_exists( 'zymarg_os_vendor_lifetime_earnings' ) ) {
		$gross = (float) zymarg_os_vendor_lifetime_earnings( $vendor_id );
	}

	if ( null === $gross ) {
		$gross = zymarg_vd_payout_lifetime_earnings_fallback( $vendor_id );
	}

	$inflight = zymarg_vd_payout_sum( $vendor_id, array( 'pending', 'approved' ) );
	$paid     = zymarg_vd_payout_sum( $vendor_id, array( 'paid' ) );

	// When we fall back to our own lifetime-earnings figure (no Dokan balance),
	// "paid" must be subtracted too; Dokan's own balance already nets out money
	// it disbursed, but never money we disbursed, so subtract paid in all cases.
	$available = (float) $gross - $inflight - $paid;
	if ( $available < 0 ) {
		$available = 0.0;
	}

	return array(
		'gross'     => (float) $gross,
		'available' => $available,
		'inflight'  => $inflight,
		'paid'      => $paid,
	);
}

/**
 * Fallback lifetime earnings: vendor share of completed/processing orders.
 * Used only when no Dokan balance helper is available.
 *
 * @param int $vendor_id Vendor user ID.
 * @return float
 */
function zymarg_vd_payout_lifetime_earnings_fallback( $vendor_id ) {
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'zymarg_os_vendor_order_total_for' ) ) {
		return 0.0;
	}

	$is_vendor = function_exists( 'zymarg_os_user_is_vendor' ) ? zymarg_os_user_is_vendor( $vendor_id ) : true;

	$orders = wc_get_orders(
		array(
			'limit'   => (int) apply_filters( 'zymarg_vd_payout_earnings_scan', 1000 ),
			'status'  => array( 'wc-processing', 'wc-completed' ),
			'return'  => 'objects',
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);

	$total = 0.0;
	foreach ( (array) $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}
		$total += (float) zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
	}
	return $total;
}

/* ====================================================================== *
 * Vendor payout method storage
 * ====================================================================== */

/**
 * Get a vendor's saved payout methods.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array
 */
function zymarg_vd_get_payout_methods( $vendor_id ) {
	$saved = get_user_meta( $vendor_id, '_zv_payout_methods', true );
	return is_array( $saved ) ? $saved : array();
}

/**
 * Get a vendor's default payout method key.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function zymarg_vd_get_default_payout_method( $vendor_id ) {
	$default = (string) get_user_meta( $vendor_id, '_zv_payout_default', true );
	$saved   = zymarg_vd_get_payout_methods( $vendor_id );
	if ( $default && isset( $saved[ $default ] ) ) {
		return $default;
	}
	$keys = array_keys( $saved );
	return $keys ? (string) $keys[0] : '';
}

/**
 * Build a human-readable one-line summary of a stored method's details.
 *
 * @param string $method Method key.
 * @param array  $data   Field data.
 * @return string
 */
function zymarg_vd_payout_method_summary( $method, $data ) {
	$methods = zymarg_vd_payout_methods();
	if ( ! isset( $methods[ $method ] ) ) {
		return '';
	}
	$label = $methods[ $method ]['label'];

	if ( 'bank' === $methods[ $method ]['type'] ) {
		$bits = array_filter(
			array(
				isset( $data['ac_name'] ) ? $data['ac_name'] : '',
				isset( $data['ac_number'] ) ? $data['ac_number'] : '',
				isset( $data['bank_name'] ) ? $data['bank_name'] : '',
				isset( $data['branch'] ) ? $data['branch'] : '',
			)
		);
		return $label . ' — ' . implode( ', ', $bits );
	}

	$number = isset( $data['number'] ) ? $data['number'] : '';
	return $number ? $label . ' — ' . $number : $label;
}

/* ====================================================================== *
 * Dashboard wiring (toggle / nav / withdraw URL / assets)
 * ====================================================================== */

/**
 * Re-label the "payments" toggle so the admin knows it is now native.
 *
 * @param array $registry Feature registry.
 * @return array
 */
function zymarg_vd_payouts_registry_label( $registry ) {
	$registry['payments'] = __( 'Payouts (native withdraw — bKash / Nagad / Rocket / bank)', 'zymarg-vendor-dashboard' );
	return $registry;
}
add_filter( 'zymarg_vd_feature_registry', 'zymarg_vd_payouts_registry_label' );

/**
 * Render the Payouts screen in-shell instead of linking to Dokan.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_payouts_native_section( $sections ) {
	if ( zymarg_vd_payouts_enabled() ) {
		$sections[] = 'payments';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_payouts_native_section' );

/**
 * Point the "Withdraw" actions at the native Payouts screen.
 *
 * @param string $url Default (Dokan) URL.
 * @return string
 */
function zymarg_vd_payouts_withdraw_url( $url ) {
	if ( zymarg_vd_payouts_enabled() && function_exists( 'zymarg_os_vendor_section_url' ) ) {
		return zymarg_os_vendor_section_url( 'payments' );
	}
	return $url;
}
add_filter( 'zymarg_os_vendor_withdraw_url', 'zymarg_vd_payouts_withdraw_url' );

/**
 * Give the Payments nav item a clearer label.
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_payouts_nav_label( $items ) {
	foreach ( $items as &$item ) {
		if ( isset( $item[0] ) && 'payments' === $item[0] ) {
			$item[1] = __( 'Payouts', 'zymarg-vendor-dashboard' );
			$item[2] = 'wallet';
		}
	}
	unset( $item );
	return $items;
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_payouts_nav_label', 5 );

/**
 * Render the section when active.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section key.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_payouts_render( $html, $active, $user ) {
	if ( 'payments' !== $active || ! zymarg_vd_payouts_enabled() ) {
		return $html;
	}
	return zymarg_vd_render_payouts_section( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_payouts_render', 10, 3 );

/**
 * Enqueue Payouts assets (shared add-ons stylesheet + script).
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_payouts_assets( $ver ) {
	if ( ! zymarg_vd_payouts_enabled() ) {
		return;
	}
	zymarg_vd_enqueue_addons_css( $ver );

	wp_enqueue_script(
		'zymarg-vd-payouts',
		ZYMARG_VD_URL . 'assets/js/payouts.js',
		array(),
		$ver,
		true
	);
	wp_localize_script(
		'zymarg-vd-payouts',
		'ZymargPayouts',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_payouts' ),
			'i18n'    => array(
				'working'       => __( 'Working…', 'zymarg-vendor-dashboard' ),
				'confirmCancel' => __( 'Cancel this payout request?', 'zymarg-vendor-dashboard' ),
				'error'         => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_payouts_assets' );

/**
 * Enqueue the shared add-ons stylesheet exactly once.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_enqueue_addons_css( $ver ) {
	if ( wp_style_is( 'zymarg-vd-addons', 'enqueued' ) ) {
		return;
	}
	wp_enqueue_style(
		'zymarg-vd-addons',
		ZYMARG_VD_URL . 'assets/css/addons.css',
		array( 'zymarg-os-vendor-dashboard' ),
		$ver
	);
}

/* ====================================================================== *
 * Vendor-facing section
 * ====================================================================== */

/**
 * Render the Payouts section (balance, method form, request form, history).
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_payouts_section( $user ) {
	$vendor_id = (int) $user->ID;
	$balance   = zymarg_vd_payout_balance( $vendor_id );
	$methods   = zymarg_vd_payout_methods();
	$saved     = zymarg_vd_get_payout_methods( $vendor_id );
	$default   = zymarg_vd_get_default_payout_method( $vendor_id );
	$min       = zymarg_vd_payout_min();
	$requests  = zymarg_vd_get_vendor_requests( $vendor_id );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--row">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Payouts', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Withdraw your earnings to bKash, Nagad, Rocket or your bank.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<section class="zymarg-vendor-stats zymarg-vendor-stats--3">
		<?php
		echo zymarg_os_vendor_stat_card( __( 'Available Balance', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $balance['available'] ) ), 'card', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'In Progress', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $balance['inflight'] ) ), 'clock', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Paid Out', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $balance['paid'] ) ), 'wallet', null ); // phpcs:ignore
		?>
	</section>

	<div class="zymarg-zpe-layout">
		<?php echo zymarg_vd_payouts_request_card( $vendor_id, $balance, $saved, $default, $min ); // phpcs:ignore ?>
		<?php echo zymarg_vd_payouts_method_card( $vendor_id, $methods, $saved, $default ); // phpcs:ignore ?>
	</div>

	<div class="zymarg-zpe-card zvd-mt-3">
		<div class="zymarg-zpe-card__accent"></div>
		<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Withdrawal history', 'zymarg-vendor-dashboard' ); ?></div>
		<div class="zymarg-zpe-card__body">
			<?php echo zymarg_vd_payouts_history_table( $requests ); // phpcs:ignore ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The "Request a withdrawal" card.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param array  $balance   Balance figures.
 * @param array  $saved     Saved methods.
 * @param string $default   Default method key.
 * @param float  $min       Minimum withdrawal.
 * @return string
 */
function zymarg_vd_payouts_request_card( $vendor_id, $balance, $saved, $default, $min ) {
	$methods   = zymarg_vd_payout_methods();
	$can_apply = ! empty( $saved ) && $balance['available'] >= $min;

	ob_start();
	?>
	<div class="zymarg-zpe-card zymarg-zpe-card--left">
		<div class="zymarg-zpe-card__accent"></div>
		<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Request a withdrawal', 'zymarg-vendor-dashboard' ); ?></div>
		<div class="zymarg-zpe-card__body">

		<?php if ( empty( $saved ) ) : ?>
			<p class="zymarg-vendor-note"><?php esc_html_e( 'Add a payout method first (on the right), then come back to request a withdrawal.', 'zymarg-vendor-dashboard' ); ?></p>
		<?php else : ?>
			<form class="zymarg-zpe-form" id="zymarg-zp-request" data-available="<?php echo esc_attr( $balance['available'] ); ?>" data-min="<?php echo esc_attr( $min ); ?>">
				<label class="zymarg-zp-field">
					<span class="zymarg-zp-field__label"><?php esc_html_e( 'Amount', 'zymarg-vendor-dashboard' ); ?></span>
					<input type="number" name="amount" step="0.01" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $balance['available'] ); ?>" required>
					<span class="zymarg-zp-field__hint">
						<?php
						printf(
							/* translators: 1: minimum amount, 2: available balance. */
							esc_html__( 'Min %1$s · Available %2$s', 'zymarg-vendor-dashboard' ),
							wp_kses_post( wc_price( $min ) ),
							wp_kses_post( wc_price( $balance['available'] ) )
						);
						?>
					</span>
				</label>

				<label class="zymarg-zp-field">
					<span class="zymarg-zp-field__label"><?php esc_html_e( 'Send to', 'zymarg-vendor-dashboard' ); ?></span>
					<select name="method" required>
						<?php foreach ( $saved as $key => $data ) : ?>
							<?php if ( ! isset( $methods[ $key ] ) ) { continue; } ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default ); ?>>
								<?php echo esc_html( zymarg_vd_payout_method_summary( $key, $data ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="zymarg-zp-field">
					<span class="zymarg-zp-field__label"><?php esc_html_e( 'Note (optional)', 'zymarg-vendor-dashboard' ); ?></span>
					<input type="text" name="note" maxlength="200" placeholder="<?php esc_attr_e( 'Anything the admin should know', 'zymarg-vendor-dashboard' ); ?>">
				</label>

				<div class="zymarg-zp-form__foot">
					<button type="submit" class="zymarg-vendor-cta zymarg-zp-submit" <?php disabled( ! $can_apply ); ?>>
						<?php echo zymarg_os_vendor_icon( 'plus-wallet' ); // phpcs:ignore ?>
						<span><?php esc_html_e( 'Request withdrawal', 'zymarg-vendor-dashboard' ); ?></span>
					</button>
					<span class="zymarg-zp-msg" role="status" aria-live="polite"></span>
				</div>
				<?php if ( ! $can_apply && ! empty( $saved ) ) : ?>
					<p class="zymarg-vendor-note">
						<?php
						printf(
							/* translators: %s: minimum amount. */
							esc_html__( 'You need at least %s of available balance to withdraw.', 'zymarg-vendor-dashboard' ),
							wp_kses_post( wc_price( $min ) )
						);
						?>
					</p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The "Payout methods" card (save bKash / Nagad / Rocket / bank details).
 *
 * @param int    $vendor_id Vendor user ID.
 * @param array  $methods   Method registry.
 * @param array  $saved     Saved methods.
 * @param string $default   Default method key.
 * @return string
 */
function zymarg_vd_payouts_method_card( $vendor_id, $methods, $saved, $default ) {
	ob_start();
	?>
	<div class="zymarg-zpe-card zymarg-zpe-card--right">
		<div class="zymarg-zpe-card__accent"></div>
		<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Payout method', 'zymarg-vendor-dashboard' ); ?></div>
		<div class="zymarg-zpe-card__body">

		<form class="zymarg-zpe-form" id="zymarg-zp-method">
			<label class="zymarg-zp-field">
				<span class="zymarg-zp-field__label"><?php esc_html_e( 'Method', 'zymarg-vendor-dashboard' ); ?></span>
				<select name="method" id="zymarg-zp-method-select">
					<?php foreach ( $methods as $key => $m ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default ? $default : 'bkash' ); ?>>
							<?php echo esc_html( $m['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<?php foreach ( $methods as $key => $m ) : ?>
				<div class="zymarg-zp-fields" data-method="<?php echo esc_attr( $key ); ?>" <?php echo ( $key === ( $default ? $default : 'bkash' ) ) ? '' : 'hidden'; ?>>
					<?php foreach ( $m['fields'] as $fkey => $flabel ) : ?>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php echo esc_html( $flabel ); ?></span>
							<input
								type="text"
								data-field="<?php echo esc_attr( $key . '.' . $fkey ); ?>"
								value="<?php echo esc_attr( isset( $saved[ $key ][ $fkey ] ) ? $saved[ $key ][ $fkey ] : '' ); ?>"
							>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<label class="zymarg-zp-check">
				<input type="checkbox" name="make_default" value="1" checked>
				<?php esc_html_e( 'Use this as my default payout method', 'zymarg-vendor-dashboard' ); ?>
			</label>

			<div class="zymarg-zp-form__foot">
				<button type="submit" class="zymarg-vendor-cta zymarg-vendor-cta--ghost zymarg-zp-save">
					<span><?php esc_html_e( 'Save method', 'zymarg-vendor-dashboard' ); ?></span>
				</button>
				<span class="zymarg-zp-msg" role="status" aria-live="polite"></span>
			</div>
		</form>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The withdrawal history table.
 *
 * @param array $requests Request rows.
 * @return string
 */
function zymarg_vd_payouts_history_table( $requests ) {
	if ( empty( $requests ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No withdrawals yet. Your requests will appear here.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$labels = zymarg_vd_payout_statuses();

	ob_start();
	?>
	<div class="zymarg-zp-table-wrap">
		<table class="zymarg-zp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'zymarg-vendor-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'zymarg-vendor-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Method', 'zymarg-vendor-dashboard' ); ?></th>
					<th><?php esc_html_e( 'Status', 'zymarg-vendor-dashboard' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $requests as $r ) : ?>
					<tr data-id="<?php echo esc_attr( $r['id'] ); ?>">
						<td data-th="<?php esc_attr_e( 'Date', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_html( $r['date'] ); ?></td>
						<td data-th="<?php esc_attr_e( 'Amount', 'zymarg-vendor-dashboard' ); ?>"><?php echo wp_kses_post( wc_price( $r['amount'] ) ); ?></td>
						<td data-th="<?php esc_attr_e( 'Method', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_html( $r['method_summary'] ); ?></td>
						<td data-th="<?php esc_attr_e( 'Status', 'zymarg-vendor-dashboard' ); ?>">
							<span class="zymarg-zp-badge zymarg-zp-badge--<?php echo esc_attr( $r['status'] ); ?>">
								<?php echo esc_html( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ); ?>
							</span>
							<?php if ( ! empty( $r['is_auto'] ) ) : ?>
								<span class="zymarg-zp-badge zymarg-zp-badge--auto"><?php esc_html_e( 'Auto', 'zymarg-vendor-dashboard' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $r['admin_note'] ) ) : ?>
								<span class="zymarg-zp-adminnote"><?php echo esc_html( $r['admin_note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-th="">
							<?php if ( 'pending' === $r['status'] ) : ?>
								<button type="button" class="zymarg-zp-cancel" data-id="<?php echo esc_attr( $r['id'] ); ?>">
									<?php esc_html_e( 'Cancel', 'zymarg-vendor-dashboard' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Fetch a vendor's payout requests (newest first) as display rows.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array
 */
function zymarg_vd_get_vendor_requests( $vendor_id ) {
	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_PAYOUT_CPT,
			'post_status'    => 'any',
			'author'         => (int) $vendor_id,
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	$rows = array();
	foreach ( $q->posts as $post ) {
		$method = (string) get_post_meta( $post->ID, '_zv_method', true );
		$detail = get_post_meta( $post->ID, '_zv_method_detail', true );
		$detail = is_array( $detail ) ? $detail : array();
		$rows[] = array(
			'id'             => $post->ID,
			'amount'         => (float) get_post_meta( $post->ID, '_zv_amount', true ),
			'status'         => (string) get_post_meta( $post->ID, '_zv_status', true ),
			'method'         => $method,
			'method_summary' => zymarg_vd_payout_method_summary( $method, $detail ),
			'admin_note'     => (string) get_post_meta( $post->ID, '_zv_admin_note', true ),
			'is_auto'        => '1' === get_post_meta( $post->ID, '_zv_auto', true ),
			'date'           => get_the_date( get_option( 'date_format' ), $post ),
		);
	}
	return $rows;
}

/* ====================================================================== *
 * Vendor AJAX
 * ====================================================================== */

/**
 * Guard shared by the vendor AJAX handlers.
 *
 * @return int Vendor user ID on success (exits on failure).
 */
function zymarg_vd_payouts_ajax_guard() {
	check_ajax_referer( 'zymarg_vd_payouts', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'zymarg_os_can_view_vendor_dashboard' ) || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! zymarg_vd_payouts_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Payouts are turned off.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	return get_current_user_id();
}

/**
 * AJAX: save the vendor's payout method details.
 *
 * @return void
 */
function zymarg_vd_payouts_save_method_ajax() {
	$vendor_id = zymarg_vd_payouts_ajax_guard();

	$method  = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
	$methods = zymarg_vd_payout_methods();
	if ( ! isset( $methods[ $method ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown payout method.', 'zymarg-vendor-dashboard' ) ) );
	}

	$incoming = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization
	$clean    = array();
	$has_value = false;
	foreach ( $methods[ $method ]['fields'] as $fkey => $flabel ) {
		$val = isset( $incoming[ $fkey ] ) ? sanitize_text_field( $incoming[ $fkey ] ) : '';
		$clean[ $fkey ] = $val;
		if ( '' !== $val ) {
			$has_value = true;
		}
	}
	if ( ! $has_value ) {
		wp_send_json_error( array( 'message' => __( 'Please fill in your account details.', 'zymarg-vendor-dashboard' ) ) );
	}

	$saved            = zymarg_vd_get_payout_methods( $vendor_id );
	$saved[ $method ] = $clean;
	update_user_meta( $vendor_id, '_zv_payout_methods', $saved );

	if ( ! empty( $_POST['make_default'] ) ) {
		update_user_meta( $vendor_id, '_zv_payout_default', $method );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Payout method saved.', 'zymarg-vendor-dashboard' ),
			'summary' => zymarg_vd_payout_method_summary( $method, $clean ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_payout_save_method', 'zymarg_vd_payouts_save_method_ajax' );

/**
 * AJAX: create a withdrawal request.
 *
 * @return void
 */
function zymarg_vd_payouts_request_ajax() {
	$vendor_id = zymarg_vd_payouts_ajax_guard();

	$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
	$method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
	$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	$saved = zymarg_vd_get_payout_methods( $vendor_id );
	if ( ! isset( $saved[ $method ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Please choose a saved payout method.', 'zymarg-vendor-dashboard' ) ) );
	}

	$min = zymarg_vd_payout_min();
	if ( $amount < $min ) {
		wp_send_json_error(
			array(
				/* translators: %s: minimum amount. */
				'message' => sprintf( __( 'The minimum withdrawal is %s.', 'zymarg-vendor-dashboard' ), wp_strip_all_tags( wc_price( $min ) ) ),
			)
		);
	}

	$balance = zymarg_vd_payout_balance( $vendor_id );
	if ( $amount > $balance['available'] + 0.001 ) {
		wp_send_json_error(
			array(
				/* translators: %s: available balance. */
				'message' => sprintf( __( 'That is more than your available balance (%s).', 'zymarg-vendor-dashboard' ), wp_strip_all_tags( wc_price( $balance['available'] ) ) ),
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => ZYMARG_PAYOUT_CPT,
			'post_status' => 'publish',
			'post_author' => $vendor_id,
			'post_title'  => sprintf( 'Payout #%d', time() ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Could not save your request. Please try again.', 'zymarg-vendor-dashboard' ) ) );
	}

	update_post_meta( $post_id, '_zv_amount', $amount );
	update_post_meta( $post_id, '_zv_method', $method );
	update_post_meta( $post_id, '_zv_method_detail', $saved[ $method ] );
	update_post_meta( $post_id, '_zv_status', 'pending' );
	if ( $note ) {
		update_post_meta( $post_id, '_zv_note', $note );
	}

	/**
	 * Fires after a vendor submits a payout request.
	 *
	 * @param int   $post_id   Request post ID.
	 * @param int   $vendor_id Vendor user ID.
	 * @param float $amount    Requested amount.
	 */
	do_action( 'zymarg_vd_payout_requested', $post_id, $vendor_id, $amount );

	wp_send_json_success(
		array(
			'message' => __( 'Withdrawal requested. The admin will review it shortly.', 'zymarg-vendor-dashboard' ),
			'reload'  => true,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_payout_request', 'zymarg_vd_payouts_request_ajax' );

/**
 * AJAX: vendor cancels their own pending request.
 *
 * @return void
 */
function zymarg_vd_payouts_cancel_ajax() {
	$vendor_id = zymarg_vd_payouts_ajax_guard();

	$post_id = isset( $_POST['id'] ) ? (int) wp_unslash( $_POST['id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || ZYMARG_PAYOUT_CPT !== $post->post_type || (int) $post->post_author !== (int) $vendor_id ) {
		wp_send_json_error( array( 'message' => __( 'Request not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}
	if ( 'pending' !== get_post_meta( $post_id, '_zv_status', true ) ) {
		wp_send_json_error( array( 'message' => __( 'Only pending requests can be cancelled.', 'zymarg-vendor-dashboard' ) ) );
	}

	update_post_meta( $post_id, '_zv_status', 'cancelled' );

	wp_send_json_success(
		array(
			'message' => __( 'Request cancelled.', 'zymarg-vendor-dashboard' ),
			'reload'  => true,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_payout_cancel', 'zymarg_vd_payouts_cancel_ajax' );

/* ====================================================================== *
 * Admin: Payout Requests screen
 * ====================================================================== */

/**
 * Count of pending requests (for the menu bubble).
 *
 * @return int
 */
function zymarg_vd_payouts_pending_count() {
	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_PAYOUT_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_zv_status',
					'value' => 'pending',
				),
			),
		)
	);
	return (int) $q->found_posts;
}

/**
 * Register the admin "ZYMARG Payouts" screen under the Vendor hub menu.
 *
 * @return void
 */
function zymarg_vd_payouts_admin_menu() {
	$count = zymarg_vd_payouts_pending_count();
	$title = __( 'ZYMARG Payouts', 'zymarg-vendor-dashboard' );
	$menu  = $title;
	if ( $count > 0 ) {
		$menu .= ' <span class="awaiting-mod">' . (int) $count . '</span>';
	}

	add_submenu_page(
		'zymarg-vendor-hub',
		$title,
		$menu,
		'manage_woocommerce',
		'zymarg-vendor-payouts',
		'zymarg_vd_payouts_render_admin_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_payouts_admin_menu' );

/**
 * Render + process the admin payouts screen.
 *
 * @return void
 */
function zymarg_vd_payouts_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$is_ajax = ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
	if ( ! $is_ajax ) {
		echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
	}

	echo '<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ) . '" class="zvd-back zvd-nav-link">&larr; Back to Vendor Hub</a>';

	// Handle an action POST.
	if ( isset( $_POST['zymarg_payout_action'] ) && check_admin_referer( 'zymarg_payout_admin' ) ) {
		$pid    = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
		$action = sanitize_key( wp_unslash( $_POST['zymarg_payout_action'] ) );
		$note   = isset( $_POST['admin_note'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_note'] ) ) : '';
		$post   = $pid ? get_post( $pid ) : null;

		if ( $post && ZYMARG_PAYOUT_CPT === $post->post_type ) {
			$map = array(
				'approve' => 'approved',
				'paid'    => 'paid',
				'reject'  => 'rejected',
				'pending' => 'pending',
			);
			if ( isset( $map[ $action ] ) ) {
				update_post_meta( $pid, '_zv_status', $map[ $action ] );
				if ( '' !== $note ) {
					update_post_meta( $pid, '_zv_admin_note', $note );
				}
				if ( 'paid' === $action ) {
					update_post_meta( $pid, '_zv_paid_date', current_time( 'mysql' ) );
				}
				/**
				 * Fires after an admin changes a payout request status.
				 *
				 * @param int    $pid    Request ID.
				 * @param string $status New status.
				 */
				do_action( 'zymarg_vd_payout_status_changed', $pid, $map[ $action ] );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Payout request updated.', 'zymarg-vendor-dashboard' ) . '</p></div>';
			}
		}
	}

	$status_filter = isset( $_GET['zv_status'] ) ? sanitize_key( wp_unslash( $_GET['zv_status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification
	$statuses      = zymarg_vd_payout_statuses();

	$meta_query = array();
	if ( $status_filter && isset( $statuses[ $status_filter ] ) ) {
		$meta_query[] = array(
			'key'   => '_zv_status',
			'value' => $status_filter,
		);
	}

	$q = new WP_Query(
		array(
			'post_type'      => ZYMARG_PAYOUT_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);

	?>
	<div class="wrap zymarg-vd-payouts">
		<?php
		zymarg_vd_admin_header(
			__( 'ZYMARG Payouts', 'zymarg-vendor-dashboard' ),
			__( 'Review vendor withdrawal requests. After you send the money, mark the request as Paid.', 'zymarg-vendor-dashboard' )
		);
		?>

		<div class="zvdp-tabs">
			<?php
			$base = admin_url( 'admin.php?page=zymarg-vendor-payouts' );
			$tabs = array_merge( array( '' => __( 'All', 'zymarg-vendor-dashboard' ) ), $statuses );
			foreach ( $tabs as $key => $label ) :
				$url     = $key ? add_query_arg( 'zv_status', $key, $base ) : $base;
				$current = ( $key === $status_filter ) || ( '' === $key && '' === $status_filter );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="zvdp-tab<?php echo $current ? ' zvdp-tab--active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<div class="zvdp-table-card">
			<table class="zvdp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'zymarg-vendor-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Vendor', 'zymarg-vendor-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'zymarg-vendor-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Method / details', 'zymarg-vendor-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'zymarg-vendor-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Action', 'zymarg-vendor-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $q->posts ) : ?>
					<tr><td colspan="6" class="zvdp-empty"><?php esc_html_e( 'No payout requests found.', 'zymarg-vendor-dashboard' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $q->posts as $post ) :
						$vid     = (int) $post->post_author;
						$user    = get_userdata( $vid );
						$amount  = (float) get_post_meta( $post->ID, '_zv_amount', true );
						$method  = (string) get_post_meta( $post->ID, '_zv_method', true );
						$detail  = get_post_meta( $post->ID, '_zv_method_detail', true );
						$detail  = is_array( $detail ) ? $detail : array();
						$status  = (string) get_post_meta( $post->ID, '_zv_status', true );
						$vnote   = (string) get_post_meta( $post->ID, '_zv_note', true );
						$store   = function_exists( 'zymarg_os_vendor_store_name' ) ? zymarg_os_vendor_store_name( $vid ) : '';
						?>
						<tr>
							<td><?php echo esc_html( get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) ); ?></td>
							<td>
								<strong><?php echo esc_html( $store ? $store : ( $user ? $user->display_name : '#' . $vid ) ); ?></strong><br>
								<span class="zvdp-email"><?php echo esc_html( $user ? $user->user_email : '' ); ?></span>
							</td>
							<td><strong><?php echo wp_kses_post( wc_price( $amount ) ); ?></strong></td>
							<td>
								<?php echo esc_html( zymarg_vd_payout_method_summary( $method, $detail ) ); ?>
								<?php if ( $vnote ) : ?>
									<br><span class="zvdp-note"><?php echo esc_html( $vnote ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<span class="zvdp-badge zvdp-badge--<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ); ?>
								</span>
							</td>
							<td>
								<form method="post" class="zvdp-action-form">
									<?php wp_nonce_field( 'zymarg_payout_admin' ); ?>
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $post->ID ); ?>">
									<input type="text" name="admin_note" class="zvdp-note-input" placeholder="<?php esc_attr_e( 'Note (optional)', 'zymarg-vendor-dashboard' ); ?>">
									<div class="zvdp-action-btns">
										<?php if ( 'pending' === $status ) : ?>
											<button class="zvdp-btn zvdp-btn--primary" name="zymarg_payout_action" value="approve"><?php esc_html_e( 'Approve', 'zymarg-vendor-dashboard' ); ?></button>
											<button class="zvdp-btn zvdp-btn--ghost" name="zymarg_payout_action" value="reject"><?php esc_html_e( 'Reject', 'zymarg-vendor-dashboard' ); ?></button>
										<?php elseif ( 'approved' === $status ) : ?>
											<button class="zvdp-btn zvdp-btn--primary" name="zymarg_payout_action" value="paid"><?php esc_html_e( 'Mark paid', 'zymarg-vendor-dashboard' ); ?></button>
											<button class="zvdp-btn zvdp-btn--ghost" name="zymarg_payout_action" value="reject"><?php esc_html_e( 'Reject', 'zymarg-vendor-dashboard' ); ?></button>
										<?php else : ?>
											<button class="zvdp-btn zvdp-btn--ghost" name="zymarg_payout_action" value="pending"><?php esc_html_e( 'Re-open', 'zymarg-vendor-dashboard' ); ?></button>
										<?php endif; ?>
									</div>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php
	// Auto-Disbursement settings card.
	if ( function_exists( 'zymarg_vd_auto_disbursement_admin_card' ) ) {
		zymarg_vd_auto_disbursement_admin_card();
	}
	?>

	<?php
	if ( ! $is_ajax ) {
		echo '</div><!-- #zymarg-admin-ajax-content -->';
	}
}