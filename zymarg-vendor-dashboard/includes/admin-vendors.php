<?php
/**
 * ZYMARG Vendor Dashboard -- Admin Commission page.
 *
 * Lists all vendor accounts and allows per-vendor commission override.
 * Writes directly to Dokan Lite's user_meta keys so the commission engine
 * picks them up with no filter hooks required.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Commission submenu under Vendor Hub.
 *
 * @return void
 */
function zymarg_vd_register_admin_vendors_menu() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'Commission', 'zymarg-vendor-dashboard' ),
		__( 'Commission', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vendor-vendors',
		'zymarg_vd_render_admin_vendors_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_register_admin_vendors_menu', 10 );

/**
 * Enqueue vendor commission page scripts on the Vendors admin page.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function zymarg_vd_admin_vendors_enqueue( $hook_suffix ) {
	if ( 'vendor-hub_page_zymarg-vendor-vendors' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'zymarg-vd-admin-vendors',
		ZYMARG_VD_URL . 'assets/js/admin-vendors.js',
		array( 'jquery' ),
		ZYMARG_VD_VERSION,
		true
	);

	wp_localize_script(
		'zymarg-vd-admin-vendors',
		'ZymargVendors',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_vendor_commission' ),
			'i18n'    => array(
				'searching' => __( 'Searching...', 'zymarg-vendor-dashboard' ),
				'noResults' => __( 'No sellers match that search.', 'zymarg-vendor-dashboard' ),
				'loading'   => __( 'Loading...', 'zymarg-vendor-dashboard' ),
				'loadMore'  => __( 'Load more sellers', 'zymarg-vendor-dashboard' ),
				'network'   => __( 'Network error. Please try again.', 'zymarg-vendor-dashboard' ),
				/* translators: %d: number of sellers currently listed. */
				'showing'   => __( 'Showing %d', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_admin_vendors_enqueue' );

/**
 * AJAX handler: save per-vendor commission override.
 *
 * @return void
 */
function zymarg_vd_ajax_save_vendor_commission() {
	check_ajax_referer( 'zymarg_vd_vendor_commission', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0;
	if ( ! $vendor_id || ! get_user_by( 'id', $vendor_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid vendor.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	$commission_type = isset( $_POST['commission_type'] ) ? sanitize_text_field( wp_unslash( $_POST['commission_type'] ) ) : '';

	$allowed_types = array( 'percentage', 'flat', 'combine', '' );
	if ( ! in_array( $commission_type, $allowed_types, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid commission type.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// "Use Global Default" -- delete all three keys so Dokan falls back.
	if ( '' === $commission_type ) {
		delete_user_meta( $vendor_id, 'dokan_admin_percentage' );
		delete_user_meta( $vendor_id, 'dokan_admin_percentage_type' );
		delete_user_meta( $vendor_id, 'dokan_admin_additional_fee' );
		wp_send_json_success( array( 'message' => __( 'Commission reset to global default.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Validate percentage (0-100).
	$percentage = isset( $_POST['percentage'] ) ? floatval( $_POST['percentage'] ) : 0;
	if ( $percentage < 0 || $percentage > 100 ) {
		wp_send_json_error( array( 'message' => __( 'Percentage must be between 0 and 100.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// Validate flat fee (>= 0).
	$flat_fee = isset( $_POST['flat_fee'] ) ? floatval( $_POST['flat_fee'] ) : 0;
	if ( $flat_fee < 0 ) {
		wp_send_json_error( array( 'message' => __( 'Flat fee cannot be negative.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// Write meta.
	update_user_meta( $vendor_id, 'dokan_admin_percentage_type', $commission_type );
	update_user_meta( $vendor_id, 'dokan_admin_percentage', (string) $percentage );

	if ( in_array( $commission_type, array( 'flat', 'combine' ), true ) ) {
		update_user_meta( $vendor_id, 'dokan_admin_additional_fee', (string) $flat_fee );
	} else {
		// Percentage-only: clear flat fee.
		delete_user_meta( $vendor_id, 'dokan_admin_additional_fee' );
	}

	// Verification fields (optional -- included when the verification section is present).
	if ( isset( $_POST['verification_level'] ) ) {
		$verification_level = sanitize_text_field( wp_unslash( $_POST['verification_level'] ) );
		$allowed_levels     = array( '', 'id', 'full' );
		if ( in_array( $verification_level, $allowed_levels, true ) ) {
			update_user_meta( $vendor_id, '_zymarg_vd_verification_level', $verification_level );
		}
	}
	if ( isset( $_POST['verification_note'] ) ) {
		$verification_note = sanitize_textarea_field( wp_unslash( $_POST['verification_note'] ) );
		update_user_meta( $vendor_id, '_zymarg_vd_verification_note', $verification_note );
	}

	// Store page badges.
	//
	// The script always submits every badge key, including the unticked ones as
	// "0". That matters: if it only sent the ticked boxes, un-ticking a badge
	// would send nothing and the old grant would silently survive, so an admin
	// could never revoke a badge once given.
	if ( isset( $_POST['badges'] ) && function_exists( 'zymarg_vd_update_store_badges' ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is whitelisted and cast to bool inside zymarg_vd_update_store_badges().
		$raw_badges = wp_unslash( $_POST['badges'] );

		if ( is_array( $raw_badges ) ) {
			zymarg_vd_update_store_badges( $vendor_id, $raw_badges );
		}
	}

	wp_send_json_success( array( 'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_save_vendor_commission', 'zymarg_vd_ajax_save_vendor_commission' );

/**
 * Render the Vendors admin page.
 *
 * @return void
 */
/**
 * How many vendor cards to render per batch.
 *
 * The screen used to render every seller in one page load. Each card is about
 * 4 KB of HTML and costs 7 user-meta reads, so a marketplace with 1000 sellers
 * produced a ~4 MB page with 9000 form controls and no way to find anybody.
 *
 * @return int Cards per batch.
 */
function zymarg_vd_admin_vendors_per_page() {
	$per_page = (int) apply_filters( 'zymarg_vd_admin_vendors_per_page', 20 );

	return max( 1, $per_page );
}

/**
 * Query one batch of vendor accounts.
 *
 * Fetches one row more than the batch size. If that extra row comes back we
 * know another batch exists, which avoids a second COUNT query just to decide
 * whether to show the Load more button.
 *
 * @param array $args {
 *     Optional. Query arguments.
 *
 *     @type string $search Search term. Empty string for no filtering.
 *     @type int    $page   1-based batch number.
 * }
 * @return array {
 *     @type WP_User[] $vendors  Vendor accounts for this batch.
 *     @type bool      $has_more Whether a further batch exists.
 *     @type int       $page     The batch number actually used.
 * }
 */
function zymarg_vd_query_admin_vendors( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'search' => '',
			'page'   => 1,
		)
	);

	$per_page = zymarg_vd_admin_vendors_per_page();
	$page     = max( 1, (int) $args['page'] );

	$query_args = array(
		'role__in' => array( 'seller', 'vendor' ),
		'orderby'  => 'display_name',
		'order'    => 'ASC',
		'number'   => $per_page + 1,
		'offset'   => ( $page - 1 ) * $per_page,
	);

	$search = trim( (string) $args['search'] );
	if ( '' !== $search ) {
		$query_args['search']         = '*' . $search . '*';
		$query_args['search_columns'] = array(
			'user_login',
			'user_email',
			'user_nicename',
			'display_name',
		);
	}

	$vendors  = get_users( $query_args );
	$has_more = count( $vendors ) > $per_page;

	if ( $has_more ) {
		array_pop( $vendors );
	}

	return array(
		'vendors'  => $vendors,
		'has_more' => $has_more,
		'page'     => $page,
	);
}

/**
 * Render a single vendor card.
 *
 * Extracted from the page template so the AJAX search handler renders exactly
 * the same markup as the first page load. One source of truth for the card.
 *
 * @param WP_User $vendor Vendor account.
 * @return void
 */
function zymarg_vd_render_vendor_card( $vendor ) {
	if ( ! $vendor instanceof WP_User ) {
		return;
	}
	?>
					<?php
					$store_name      = '';
					$dokan_settings  = get_user_meta( $vendor->ID, 'dokan_profile_settings', true );
					if ( is_array( $dokan_settings ) && ! empty( $dokan_settings['store_name'] ) ) {
						$store_name = $dokan_settings['store_name'];
					}
					if ( empty( $store_name ) ) {
						$store_name = $vendor->display_name;
					}

					$current_type       = get_user_meta( $vendor->ID, 'dokan_admin_percentage_type', true );
					$current_percentage = get_user_meta( $vendor->ID, 'dokan_admin_percentage', true );
					$current_flat       = get_user_meta( $vendor->ID, 'dokan_admin_additional_fee', true );
					$is_global          = empty( $current_type );

					$current_verification = get_user_meta( $vendor->ID, '_zymarg_vd_verification_level', true );
					$current_ver_note     = get_user_meta( $vendor->ID, '_zymarg_vd_verification_note', true );
					?>
					<div class="zymarg-vendor-card" data-vendor-id="<?php echo esc_attr( $vendor->ID ); ?>">
						<div class="zymarg-vendor-card__header">
							<div class="zymarg-vendor-card__info">
								<span class="zymarg-vendor-card__name"><?php echo esc_html( $store_name ); ?></span>
								<span class="zymarg-vendor-card__email"><?php echo esc_html( $vendor->user_email ); ?></span>
							</div>
							<div class="zymarg-vendor-card__status">
								<?php if ( $is_global ) : ?>
									<span class="zymarg-vendor-card__badge zymarg-vendor-card__badge--global"><?php esc_html_e( 'Using category/global default', 'zymarg-vendor-dashboard' ); ?></span>
								<?php else : ?>
									<span class="zymarg-vendor-card__badge zymarg-vendor-card__badge--custom">
										<?php
										if ( 'percentage' === $current_type ) {
											/* translators: %s: percentage value */
											printf( esc_html__( '%s%% commission', 'zymarg-vendor-dashboard' ), esc_html( $current_percentage ) );
										} elseif ( 'flat' === $current_type ) {
											/* translators: %s: flat fee value */
											printf( esc_html__( '%s BDT flat', 'zymarg-vendor-dashboard' ), esc_html( $current_flat ) );
										} elseif ( 'combine' === $current_type ) {
											/* translators: 1: percentage, 2: flat fee */
											printf( esc_html__( '%1$s%% + %2$s BDT', 'zymarg-vendor-dashboard' ), esc_html( $current_percentage ), esc_html( $current_flat ) );
										}
										?>
									</span>
								<?php endif; ?>
							</div>
						</div>

						<div class="zymarg-vendor-card__fields">
							<div class="zymarg-vendor-card__field">
								<label class="zymarg-vendor-card__label" for="commission-type-<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Commission Type', 'zymarg-vendor-dashboard' ); ?></label>
								<select class="zymarg-vendor-card__select zymarg-commission-type" id="commission-type-<?php echo esc_attr( $vendor->ID ); ?>" data-vendor-id="<?php echo esc_attr( $vendor->ID ); ?>">
									<option value="" <?php selected( $is_global ); ?>><?php esc_html_e( 'Use Global Default', 'zymarg-vendor-dashboard' ); ?></option>
									<option value="percentage" <?php selected( $current_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'zymarg-vendor-dashboard' ); ?></option>
									<option value="flat" <?php selected( $current_type, 'flat' ); ?>><?php esc_html_e( 'Flat', 'zymarg-vendor-dashboard' ); ?></option>
									<option value="combine" <?php selected( $current_type, 'combine' ); ?>><?php esc_html_e( 'Combine (% + flat)', 'zymarg-vendor-dashboard' ); ?></option>
								</select>
							</div>

							<div class="zymarg-vendor-card__field zymarg-field-percentage<?php echo $is_global ? ' zvd-is-hidden' : ''; ?>">
								<label class="zymarg-vendor-card__label" for="commission-percentage-<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Percentage (%)', 'zymarg-vendor-dashboard' ); ?></label>
								<input type="number" class="zymarg-vendor-card__input zymarg-commission-percentage" id="commission-percentage-<?php echo esc_attr( $vendor->ID ); ?>" min="0" max="100" step="0.01" value="<?php echo esc_attr( $current_percentage ); ?>" placeholder="0">
							</div>

							<div class="zymarg-vendor-card__field zymarg-field-flat<?php echo ( ! in_array( $current_type, array( 'flat', 'combine' ), true ) ) ? ' zvd-is-hidden' : ''; ?>">
								<label class="zymarg-vendor-card__label" for="commission-flat-<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Flat Fee (BDT)', 'zymarg-vendor-dashboard' ); ?></label>
								<input type="number" class="zymarg-vendor-card__input zymarg-commission-flat" id="commission-flat-<?php echo esc_attr( $vendor->ID ); ?>" min="0" step="0.01" value="<?php echo esc_attr( $current_flat ); ?>" placeholder="0">
							</div>
						</div>

						<div class="zymarg-vendor-card__verification">
							<div class="zymarg-vendor-card__fields">
								<div class="zymarg-vendor-card__field">
									<label class="zymarg-vendor-card__label" for="verification-level-<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Verification', 'zymarg-vendor-dashboard' ); ?></label>
									<select class="zymarg-vendor-card__select zymarg-verification-level" id="verification-level-<?php echo esc_attr( $vendor->ID ); ?>">
										<option value="" <?php selected( $current_verification, '' ); ?>><?php esc_html_e( 'Unverified', 'zymarg-vendor-dashboard' ); ?></option>
										<option value="id" <?php selected( $current_verification, 'id' ); ?>><?php esc_html_e( 'ID Verified', 'zymarg-vendor-dashboard' ); ?></option>
										<option value="full" <?php selected( $current_verification, 'full' ); ?>><?php esc_html_e( 'Fully Verified', 'zymarg-vendor-dashboard' ); ?></option>
									</select>
								</div>
								<div class="zymarg-vendor-card__field zymarg-field-vernote zvd-field--grow">
									<label class="zymarg-vendor-card__label" for="verification-note-<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Verification Note', 'zymarg-vendor-dashboard' ); ?></label>
									<textarea class="zymarg-vendor-card__input zymarg-verification-note" id="verification-note-<?php echo esc_attr( $vendor->ID ); ?>" rows="1" placeholder="<?php esc_attr_e( 'Optional internal note...', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $current_ver_note ); ?></textarea>
								</div>
							</div>
						</div>

						<?php
						if ( function_exists( 'zymarg_vd_render_store_badge_fields' ) ) {
							zymarg_vd_render_store_badge_fields( $vendor->ID );
						}
						?>

						<div class="zymarg-vendor-card__actions">
							<button type="button" class="zymarg-vendor-card__save" data-vendor-id="<?php echo esc_attr( $vendor->ID ); ?>"><?php esc_html_e( 'Save', 'zymarg-vendor-dashboard' ); ?></button>
							<span class="zymarg-vendor-card__feedback"></span>
						</div>
					</div>
	<?php
}

/**
 * AJAX handler: search vendors and return a batch of rendered cards.
 *
 * @return void
 */
function zymarg_vd_ajax_search_vendors() {
	check_ajax_referer( 'zymarg_vd_vendor_commission', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	$page   = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

	$result = zymarg_vd_query_admin_vendors(
		array(
			'search' => $search,
			'page'   => $page,
		)
	);

	ob_start();
	foreach ( $result['vendors'] as $vendor ) {
		zymarg_vd_render_vendor_card( $vendor );
	}
	$html = ob_get_clean();

	wp_send_json_success(
		array(
			'html'    => $html,
			'hasMore' => $result['has_more'],
			'page'    => $result['page'],
			'count'   => count( $result['vendors'] ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_search_vendors', 'zymarg_vd_ajax_search_vendors' );

/**
 * Render the Commission admin page.
 *
 * @return void
 */
function zymarg_vd_render_admin_vendors_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_ajax = ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
	if ( ! $is_ajax ) {
		echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
	}

	echo '<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ) . '" class="zvd-back zvd-nav-link">&larr; Back to Vendor Hub</a>';

	$result   = zymarg_vd_query_admin_vendors( array( 'page' => 1 ) );
	$vendors  = $result['vendors'];
	$has_more = $result['has_more'];
	?>
	<div class="wrap zymarg-admin-vendors-wrap">
		<?php
		zymarg_vd_admin_header(
			__( 'Commission', 'zymarg-vendor-dashboard' ),
			__( 'Set per-vendor commission rates, verification, and store page badges.', 'zymarg-vendor-dashboard' )
		);
		?>

		<div class="zvd-vendor-search">
			<label class="zvd-vendor-search__label" for="zvd-vendor-search"><?php esc_html_e( 'Find a seller', 'zymarg-vendor-dashboard' ); ?></label>
			<input type="search" id="zvd-vendor-search" class="zvd-vendor-search__field" placeholder="<?php esc_attr_e( 'Search by name or email...', 'zymarg-vendor-dashboard' ); ?>" autocomplete="off">
			<span class="zvd-vendor-search__status" id="zvd-vendor-search-status" role="status" aria-live="polite"></span>
		</div>

		<div class="zymarg-admin-vendors-grid" id="zvd-vendor-results">
			<?php
			foreach ( $vendors as $vendor ) {
				zymarg_vd_render_vendor_card( $vendor );
			}
			?>
		</div>

		<div class="zymarg-admin-vendors-empty<?php echo empty( $vendors ) ? '' : ' zvd-is-hidden'; ?>" id="zvd-vendor-empty">
			<p><?php esc_html_e( 'No vendor accounts found. Users with the "seller" or "vendor" role will appear here.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>

		<div class="zvd-vendor-more<?php echo $has_more ? '' : ' zvd-is-hidden'; ?>" id="zvd-vendor-more">
			<button type="button" class="zvd-btn zvd-vendor-load-more" id="zvd-vendor-load-more" data-page="1"><?php esc_html_e( 'Load more sellers', 'zymarg-vendor-dashboard' ); ?></button>
		</div>
	</div>

	<?php
	if ( ! $is_ajax ) {
		echo '</div><!-- #zymarg-admin-ajax-content -->';
	}
}
