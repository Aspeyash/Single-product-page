<?php
/**
 * ZYMARG Vendor Dashboard -- Premium vendor screen (PHASE 3).
 *
 * Adds a "Premium" item to the vendor dashboard navigation and renders the
 * section behind it: Flash Sale on top, Featured Items below, each with its
 * own request-and-approval lifecycle.
 *
 * The menu only exists when the admin has switched at least one functionality
 * on, and each row only appears when its own master switch is on. A vendor on
 * a marketplace that does not offer Premium sees nothing at all -- no empty
 * menu, no disabled teaser.
 *
 * Every decision about what a vendor may do comes from premium.php. This file
 * renders state; it does not define it.
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.40.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The vendor dashboard section key.
 */
const ZYMARG_VD_PREMIUM_SECTION = 'premium';

/* ---------------------------------------------------------------------- *
 * 1. NAVIGATION
 * ---------------------------------------------------------------------- */

/**
 * Should the Premium menu appear for the current vendor?
 *
 * Deliberately does NOT require approval: a vendor has to be able to reach
 * the screen in order to request access in the first place.
 *
 * @return bool
 */
function zymarg_vd_premium_menu_visible() {
	return zymarg_vd_premium_any_master_enabled();
}

/**
 * Add "Premium" to the vendor dashboard navigation.
 *
 * Nav items are [ key, label, icon, dokan_ep ]. Premium is native, so its
 * Dokan endpoint is intentionally empty.
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_premium_nav_item( $items ) {
	if ( ! zymarg_vd_premium_menu_visible() ) {
		return $items;
	}

	$items[] = array(
		ZYMARG_VD_PREMIUM_SECTION,
		__( 'Premium', 'zymarg-vendor-dashboard' ),
		'star',
		'',
	);

	return $items;
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_premium_nav_item', 20 );

/**
 * Register Premium as a native section so the shell renders it in-page
 * instead of handing off to Dokan.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_premium_native_section( $sections ) {
	if ( zymarg_vd_premium_menu_visible() ) {
		$sections[] = ZYMARG_VD_PREMIUM_SECTION;
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_premium_native_section' );

/* ---------------------------------------------------------------------- *
 * 2. ASSETS
 * ---------------------------------------------------------------------- */

/**
 * Enqueue the vendor-side Premium script.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_premium_vendor_assets( $ver ) {
	if ( ! zymarg_vd_premium_menu_visible() ) {
		return;
	}

	// The Premium CSS is written entirely in --zym-* design tokens, but the
	// token stylesheet was only ever enqueued in wp-admin. On the front end
	// every var() in our block resolved to nothing, so the screen rendered as
	// bare HTML. Enqueue the brand layer here, before addons.css.
	// Spark is needed too: the approval popup renders a Spark icon.
	if ( function_exists( 'zymarg_vd_register_shared_brand_assets' ) ) {
		zymarg_vd_register_shared_brand_assets();
	}
	if ( wp_style_is( 'zymarg-tokens', 'registered' ) ) {
		wp_enqueue_style( 'zymarg-tokens' );
	}
	if ( wp_style_is( 'zymarg-spark', 'registered' ) ) {
		wp_enqueue_style( 'zymarg-spark' );
	}

	if ( function_exists( 'zymarg_vd_enqueue_addons_css' ) ) {
		zymarg_vd_enqueue_addons_css( $ver );
	}

	// Dependency-free, matching every other vendor-facing script in this
	// plugin. The vendor shell is deliberately jQuery-free, so a jQuery
	// dependency here was a single point of failure for the whole screen.
	wp_enqueue_script(
		'zymarg-vd-premium-vendor',
		ZYMARG_VD_URL . 'assets/js/vendor-premium.js',
		array(),
		$ver,
		true
	);

	wp_localize_script(
		'zymarg-vd-premium-vendor',
		'ZymargPremiumVendor',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_premium_vendor' ),
			'i18n'    => array(
				'working' => __( 'Working', 'zymarg-vendor-dashboard' ),
				'sent'    => __( 'Request sent. Waiting for approval.', 'zymarg-vendor-dashboard' ),
				'off'     => __( 'Turned off.', 'zymarg-vendor-dashboard' ),
				'failed'  => __( 'That did not work. Try again.', 'zymarg-vendor-dashboard' ),
				'network' => __( 'Network error.', 'zymarg-vendor-dashboard' ),
				'confirmOff' => __( 'Turn this off? Your products stay saved, they just stop showing.', 'zymarg-vendor-dashboard' ),
				'chosen'  => __( 'chosen', 'zymarg-vendor-dashboard' ),
				/* translators: %d: minimum number of products needed to go live. */
				'needMin' => __( 'pick at least %d to go live', 'zymarg-vendor-dashboard' ),
				'atMax'   => __( 'That is the most you can choose. Remove one first.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_premium_vendor_assets' );

/* ---------------------------------------------------------------------- *
 * 3. SECTION RENDER
 * ---------------------------------------------------------------------- */

/**
 * Render the Premium section when it is the active one.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section key.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_premium_render_section( $html, $active, $user ) {
	if ( ZYMARG_VD_PREMIUM_SECTION !== $active || ! zymarg_vd_premium_menu_visible() ) {
		return $html;
	}

	return zymarg_vd_premium_render_vendor_screen( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_premium_render_section', 10, 3 );

/**
 * Status chip + explanation for one functionality.
 *
 * Status is never signalled by colour alone: every chip carries its own text.
 *
 * @param string $status One of the ZYMARG_VD_PREMIUM_* status constants.
 * @return array{label:string,modifier:string,help:string}
 */
function zymarg_vd_premium_status_display( $status ) {
	switch ( $status ) {
		case ZYMARG_VD_PREMIUM_APPROVED:
			return array(
				'label'    => __( 'Active', 'zymarg-vendor-dashboard' ),
				'modifier' => 'zvd-chip--success',
				'help'     => __( 'Approved and running on your store page.', 'zymarg-vendor-dashboard' ),
			);

		case ZYMARG_VD_PREMIUM_PENDING:
			return array(
				'label'    => __( 'Waiting for approval', 'zymarg-vendor-dashboard' ),
				'modifier' => '',
				'help'     => __( 'Your request is with the marketplace team. You will see it go live here once it is approved.', 'zymarg-vendor-dashboard' ),
			);

		case ZYMARG_VD_PREMIUM_REJECTED:
			return array(
				'label'    => __( 'Not approved', 'zymarg-vendor-dashboard' ),
				'modifier' => 'zvd-chip--error',
				'help'     => __( 'This request was not approved. You can ask again.', 'zymarg-vendor-dashboard' ),
			);

		default:
			return array(
				'label'    => __( 'Off', 'zymarg-vendor-dashboard' ),
				'modifier' => '',
				'help'     => __( 'Turn this on to send a request to the marketplace team.', 'zymarg-vendor-dashboard' ),
			);
	}
}

/**
 * Render one functionality row.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $feature   Feature key.
 * @return string
 */
function zymarg_vd_premium_render_row( $vendor_id, $feature ) {
	$state   = zymarg_vd_premium_get_state( $vendor_id, $feature );
	$status  = $state['status'];
	$display = zymarg_vd_premium_status_display( $status );

	ob_start();
	?>
	<div class="zvd-premium-item" data-feature="<?php echo esc_attr( $feature ); ?>">
		<div class="zvd-premium-item__head">
			<h3 class="zvd-premium-item__title">
				<?php echo esc_html( zymarg_vd_premium_label( $feature ) ); ?>
			</h3>
			<span class="zvd-chip <?php echo esc_attr( $display['modifier'] ); ?> zvd-premium-item__chip">
				<?php echo esc_html( $display['label'] ); ?>
			</span>
		</div>

		<p class="zvd-premium-item__help"><?php echo esc_html( $display['help'] ); ?></p>

		<?php if ( ZYMARG_VD_PREMIUM_REJECTED === $status && '' !== $state['note'] ) : ?>
			<p class="zvd-premium-item__note">
				<?php
				/* translators: %s: note left by the marketplace team. */
				printf( esc_html__( 'Note from the team: %s', 'zymarg-vendor-dashboard' ), esc_html( $state['note'] ) );
				?>
			</p>
		<?php elseif ( ZYMARG_VD_PREMIUM_APPROVED === $status && '' !== $state['note'] ) : ?>
			<p class="zvd-premium-item__note">
				<?php
				/* translators: %s: note left by the marketplace team. */
				printf( esc_html__( 'Note from the team: %s', 'zymarg-vendor-dashboard' ), esc_html( $state['note'] ) );
				?>
			</p>
		<?php endif; ?>

		<div class="zvd-premium-item__actions">
			<?php if ( ZYMARG_VD_PREMIUM_APPROVED === $status ) : ?>
				<button type="button" class="zvd-btn zvd-btn--secondary zvd-premium-off">
					<?php esc_html_e( 'Turn off', 'zymarg-vendor-dashboard' ); ?>
				</button>
			<?php elseif ( ZYMARG_VD_PREMIUM_PENDING === $status ) : ?>
				<button type="button" class="zvd-btn zvd-btn--secondary zvd-premium-off">
					<?php esc_html_e( 'Cancel request', 'zymarg-vendor-dashboard' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="zvd-btn zvd-btn--primary zvd-premium-request">
					<?php esc_html_e( 'Turn on', 'zymarg-vendor-dashboard' ); ?>
				</button>
			<?php endif; ?>

			<span class="zvd-status-msg zvd-premium-item__status" aria-live="polite"></span>
		</div>

		<?php
		// Product-level controls (Phase 4). Returns '' unless approved, so this
		// can be called unconditionally.
		echo zymarg_vd_premium_render_product_controls( $vendor_id, $feature ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the whole Premium screen for a vendor.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_premium_render_vendor_screen( $user ) {
	$vendor_id = ( $user instanceof WP_User ) ? (int) $user->ID : get_current_user_id();
	$settings  = zymarg_vd_premium_settings();

	ob_start();
	?>
	<div class="zvd-premium">
		<div class="zvd-premium__intro">
			<h2 class="zvd-premium__title"><?php esc_html_e( 'Premium', 'zymarg-vendor-dashboard' ); ?></h2>
			<p class="zvd-premium__lead">
				<?php esc_html_e( 'Extra ways to promote your products on your store page. Each one needs approval from the marketplace team before it goes live.', 'zymarg-vendor-dashboard' ); ?>
			</p>
		</div>

		<?php
		// Flash Sale first, Featured Items second -- a fixed order, so the screen
		// does not reshuffle itself as switches change.
		foreach ( array( ZYMARG_VD_PREMIUM_FLASH, ZYMARG_VD_PREMIUM_FEATURED ) as $feature ) :
			if ( empty( $settings[ $feature ] ) ) {
				continue;
			}
			echo zymarg_vd_premium_render_row( $vendor_id, $feature ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		endforeach;
		?>

		<?php
		/*
		 * Approval popup.
		 *
		 * Rendered once per screen and shown by JS after a request succeeds.
		 * Without it a vendor clicks "Turn on", sees a small status line, and
		 * has no idea that a human now has to approve them -- which reads as a
		 * broken switch rather than a queued request.
		 *
		 * Structure mirrors the dashboard's existing confirm modal (overlay +
		 * dialog + icon + actions, toggled with the `hidden` attribute) so it
		 * behaves like every other popup in the dashboard.
		 */
		?>
		<div class="zvd-premium-modal" id="zvd-premium-modal" role="dialog" aria-modal="true" aria-labelledby="zvd-premium-modal-title" hidden>
			<div class="zvd-premium-modal__overlay" data-premium-modal-close></div>
			<div class="zvd-premium-modal__dialog">
				<button type="button" class="zvd-premium-modal__close" data-premium-modal-close aria-label="<?php esc_attr_e( 'Close', 'zymarg-vendor-dashboard' ); ?>">&times;</button>

				<div class="zvd-premium-modal__icon" aria-hidden="true">
					<?php echo zymarg_vd_spark( array( 'size' => 'lg', 'label' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<h3 id="zvd-premium-modal-title" class="zvd-premium-modal__title">
					<?php esc_html_e( 'Contact admin for approval', 'zymarg-vendor-dashboard' ); ?>
				</h3>

				<p class="zvd-premium-modal__text" data-premium-modal-text>
					<?php esc_html_e( 'Your request has been sent to the marketplace team. This feature stays off until an admin approves it. If it is urgent, contact the admin directly.', 'zymarg-vendor-dashboard' ); ?>
				</p>

				<div class="zvd-premium-modal__actions">
					<?php
					$admin_email = sanitize_email( get_option( 'admin_email' ) );
					if ( $admin_email ) :
						?>
						<a class="zvd-btn zvd-btn--secondary" href="<?php echo esc_url( 'mailto:' . $admin_email ); ?>">
							<?php esc_html_e( 'Email admin', 'zymarg-vendor-dashboard' ); ?>
						</a>
					<?php endif; ?>

					<button type="button" class="zvd-btn zvd-btn--primary" data-premium-modal-close>
						<?php esc_html_e( 'Got it', 'zymarg-vendor-dashboard' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------- *
 * 4. AJAX ENDPOINTS (vendor side)
 * ---------------------------------------------------------------------- */

/**
 * Guard + resolve the feature for a vendor-side AJAX call.
 *
 * A vendor can only ever act on their own account: the vendor ID comes from
 * the session, never from the request body.
 *
 * @return array{0:int,1:string} Vendor ID and feature key.
 */
function zymarg_vd_premium_vendor_ajax_target() {
	check_ajax_referer( 'zymarg_vd_premium_vendor', 'nonce' );

	$vendor_id = get_current_user_id();
	if ( $vendor_id <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Not logged in.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';
	if ( ! zymarg_vd_premium_is_feature( $feature ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	if ( ! zymarg_vd_premium_master_enabled( $feature ) ) {
		wp_send_json_error( array( 'message' => __( 'This is not available right now.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	return array( $vendor_id, $feature );
}

/**
 * AJAX: vendor requests access.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_vendor_request() {
	list( $vendor_id, $feature ) = zymarg_vd_premium_vendor_ajax_target();

	if ( ! zymarg_vd_premium_request( $vendor_id, $feature ) ) {
		wp_send_json_error(
			array( 'message' => __( 'That request could not be sent. Reload and check the current status.', 'zymarg-vendor-dashboard' ) ),
			400
		);
	}

	$display = zymarg_vd_premium_status_display( ZYMARG_VD_PREMIUM_PENDING );

	wp_send_json_success(
		array(
			'message' => __( 'Request sent. Waiting for approval.', 'zymarg-vendor-dashboard' ),
			'status'  => ZYMARG_VD_PREMIUM_PENDING,
			'chip'    => $display['label'],
			'help'    => $display['help'],
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_vendor_request', 'zymarg_vd_premium_ajax_vendor_request' );

/**
 * AJAX: vendor turns a functionality off, or cancels a pending request.
 *
 * @return void
 */
function zymarg_vd_premium_ajax_vendor_off() {
	list( $vendor_id, $feature ) = zymarg_vd_premium_vendor_ajax_target();

	zymarg_vd_premium_withdraw( $vendor_id, $feature );

	$display = zymarg_vd_premium_status_display( ZYMARG_VD_PREMIUM_OFF );

	wp_send_json_success(
		array(
			'message' => __( 'Turned off.', 'zymarg-vendor-dashboard' ),
			'status'  => ZYMARG_VD_PREMIUM_OFF,
			'chip'    => $display['label'],
			'help'    => $display['help'],
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_premium_vendor_off', 'zymarg_vd_premium_ajax_vendor_off' );
