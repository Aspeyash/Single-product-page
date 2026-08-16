<?php
/**
 * ZYMARG Vendor Dashboard — Dokan / Dokan Pro compatibility monitor.
 *
 * Quietly tracks the highest Dokan Lite and Dokan Pro versions this build has
 * been validated against. When the site is running a NEWER major/minor version,
 * a single dismissible admin notice asks the marketplace owner to do a 5-minute
 * staging check, so any future Dokan API change is caught early rather than
 * surfacing as a confused vendor support ticket.
 *
 * Read-only: this file never disables anything and never blocks features. It
 * only surfaces a notice (to admins, dismissible per-version-pair).
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * The highest Dokan / Dokan Pro versions this build is known-good against.
 * Filterable so a future release can bump these without rewriting the monitor.
 *
 * @return array{dokan:string,dokan_pro:string}
 */
function zymarg_vd_validated_versions() {
	return (array) apply_filters(
		'zymarg_vd_validated_versions',
		array(
			'dokan'     => '5.0.4',
			'dokan_pro' => '5.0.2',
		)
	);
}

/**
 * The installed Dokan Lite version (best effort).
 *
 * Reads the constant Dokan Lite has been defining since well before this
 * plugin existed; falls back to scanning the plugin file's "Version:" header.
 *
 * @return string Empty string if Dokan Lite isn't active.
 */
function zymarg_vd_dokan_lite_version() {
	if ( defined( 'DOKAN_PLUGIN_VERSION' ) ) {
		return (string) DOKAN_PLUGIN_VERSION;
	}
	// Fallback: scan plugin headers (rare path — keeps us resilient if WeDevs
	// renames the constant in a future release).
	if ( function_exists( 'get_plugins' ) ) {
		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			if ( false !== strpos( $file, 'dokan-lite/' ) || 'dokan.php' === basename( $file ) ) {
				if ( ! empty( $data['Version'] ) ) {
					return (string) $data['Version'];
				}
			}
		}
	}
	return '';
}

/**
 * The installed Dokan Pro version (best effort).
 *
 * @return string Empty string if Dokan Pro isn't active.
 */
function zymarg_vd_dokan_pro_version() {
	if ( defined( 'DOKAN_PRO_PLUGIN_VERSION' ) ) {
		return (string) DOKAN_PRO_PLUGIN_VERSION;
	}
	if ( function_exists( 'get_plugins' ) ) {
		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			if ( false !== strpos( $file, 'dokan-pro/' ) ) {
				if ( ! empty( $data['Version'] ) ) {
					return (string) $data['Version'];
				}
			}
		}
	}
	return '';
}

/**
 * Compare two semver-ish strings at MAJOR.MINOR granularity.
 *
 * Patch updates (5.0.2 → 5.0.3) don't trip the notice — they are very low
 * risk. Minor/major bumps (5.0 → 5.1, 5.x → 6.x) do.
 *
 * @param string $current   Installed version.
 * @param string $validated Highest validated version.
 * @return bool True when current is newer at MAJOR or MINOR level.
 */
function zymarg_vd_is_newer_minor( $current, $validated ) {
	if ( '' === $current || '' === $validated ) {
		return false;
	}
	$c = array_pad( explode( '.', preg_replace( '/[^0-9.].*$/', '', $current ) ), 3, '0' );
	$v = array_pad( explode( '.', preg_replace( '/[^0-9.].*$/', '', $validated ) ), 3, '0' );

	$ci = (int) $c[0] * 1000 + (int) $c[1];
	$vi = (int) $v[0] * 1000 + (int) $v[1];

	return $ci > $vi;
}

/**
 * Compute the current "warning state".
 *
 * @return array{warn:bool,dokan:string,pro:string,dokan_ok:string,pro_ok:string,key:string}
 */
function zymarg_vd_compat_state() {
	$validated = zymarg_vd_validated_versions();
	$dokan     = zymarg_vd_dokan_lite_version();
	$pro       = zymarg_vd_dokan_pro_version();

	$warn_lite = zymarg_vd_is_newer_minor( $dokan, $validated['dokan'] );
	$warn_pro  = zymarg_vd_is_newer_minor( $pro, $validated['dokan_pro'] );

	return array(
		'warn'     => ( $warn_lite || $warn_pro ),
		'dokan'    => $dokan,
		'pro'      => $pro,
		'dokan_ok' => $validated['dokan'],
		'pro_ok'   => $validated['dokan_pro'],
		// Dismiss key includes ZYMARG version + the two Dokan versions so a
		// "later" version dismissal does not silence a "still later" one.
		'key'      => 'zvd_' . ZYMARG_VD_VERSION . '_' . $dokan . '_' . $pro,
	);
}

/**
 * Whether the admin user dismissed the current state.
 *
 * @param string $key Dismiss key from zymarg_vd_compat_state().
 * @return bool
 */
function zymarg_vd_compat_dismissed( $key ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return true; // No admin = nothing to show.
	}
	$saved = (array) get_user_meta( $user_id, '_zvd_compat_dismissed', true );
	return in_array( $key, $saved, true );
}

/**
 * AJAX: dismiss the current state for the current admin.
 *
 * @return void
 */
function zymarg_vd_compat_dismiss_ajax() {
	check_ajax_referer( 'zvd_compat_dismiss', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array(), 403 );
	}
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error( array() );
	}
	$user_id = get_current_user_id();
	$saved   = (array) get_user_meta( $user_id, '_zvd_compat_dismissed', true );
	if ( ! in_array( $key, $saved, true ) ) {
		$saved[] = $key;
		// Cap so this can never grow unboundedly.
		if ( count( $saved ) > 30 ) {
			$saved = array_slice( $saved, -30 );
		}
		update_user_meta( $user_id, '_zvd_compat_dismissed', $saved );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_zymarg_vd_compat_dismiss', 'zymarg_vd_compat_dismiss_ajax' );

/**
 * Render the admin notice when an unvalidated Dokan/Pro minor or major is
 * installed.
 *
 * @return void
 */
function zymarg_vd_compat_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$state = zymarg_vd_compat_state();
	if ( ! $state['warn'] || zymarg_vd_compat_dismissed( $state['key'] ) ) {
		return;
	}

	$bits = array();
	if ( '' !== $state['dokan'] && zymarg_vd_is_newer_minor( $state['dokan'], $state['dokan_ok'] ) ) {
		$bits[] = sprintf(
			/* translators: 1: installed Dokan version, 2: highest validated. */
			__( 'Dokan Lite %1$s — last validated against %2$s', 'zymarg-vendor-dashboard' ),
			esc_html( $state['dokan'] ),
			esc_html( $state['dokan_ok'] )
		);
	}
	if ( '' !== $state['pro'] && zymarg_vd_is_newer_minor( $state['pro'], $state['pro_ok'] ) ) {
		$bits[] = sprintf(
			/* translators: 1: installed Dokan Pro version, 2: highest validated. */
			__( 'Dokan Pro %1$s — last validated against %2$s', 'zymarg-vendor-dashboard' ),
			esc_html( $state['pro'] ),
			esc_html( $state['pro_ok'] )
		);
	}

	$pro_active = function_exists( 'zymarg_vd_pro_active' ) && zymarg_vd_pro_active();
	$key        = $state['key'];
	$nonce      = wp_create_nonce( 'zvd_compat_dismiss' );
	?>
	<div class="notice notice-info is-dismissible zvd-compat-notice" data-zvd-key="<?php echo esc_attr( $key ); ?>" data-zvd-nonce="<?php echo esc_attr( $nonce ); ?>">
		<p>
			<strong><?php esc_html_e( 'Dokan version check', 'zymarg-vendor-dashboard' ); ?></strong><br>
			<?php esc_html_e( 'You are running a newer Dokan version than this build of ZYMARG Vendor Dashboard has been personally validated against:', 'zymarg-vendor-dashboard' ); ?>
		</p>
		<ul class="zvd-list-disc">
			<?php foreach ( $bits as $line ) : ?>
				<li><?php echo esc_html( $line ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<?php esc_html_e( 'Everything should still work — but Dokan changes its APIs once in a while, so a 5-minute sanity pass is wise:', 'zymarg-vendor-dashboard' ); ?>
		</p>
		<ul class="zvd-list-disc">
			<li><?php esc_html_e( 'Open your vendor dashboard — does it load cleanly?', 'zymarg-vendor-dashboard' ); ?></li>
			<li><?php esc_html_e( 'Try a withdrawal request — does it land in "Pending"?', 'zymarg-vendor-dashboard' ); ?></li>
			<li><?php esc_html_e( 'Open an order from the Orders tab — does the detail render?', 'zymarg-vendor-dashboard' ); ?></li>
			<?php if ( $pro_active ) : ?>
				<li><?php esc_html_e( 'Open a Dokan Pro sub-page (e.g. Return Requests, Coupons) on its own URL — does it render natively?', 'zymarg-vendor-dashboard' ); ?></li>
			<?php endif; ?>
		</ul>
		<p>
			<?php esc_html_e( 'If anything misbehaves, drop a note and the compat layer gets a patch. If everything works (likely!), dismiss this — we will re-check on the next Dokan major release.', 'zymarg-vendor-dashboard' ); ?>
		</p>
	</div>
	<script>
	( function () {
		var notices = document.querySelectorAll( '.zvd-compat-notice' );
		notices.forEach( function ( n ) {
			n.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var body = new FormData();
				body.append( 'action', 'zymarg_vd_compat_dismiss' );
				body.append( 'key', n.getAttribute( 'data-zvd-key' ) );
				body.append( 'nonce', n.getAttribute( 'data-zvd-nonce' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
			} );
		} );
	}() );
	</script>
	<?php
}
add_action( 'admin_notices', 'zymarg_vd_compat_notice' );

/**
 * Add a compact "Dokan compatibility" line to the ZYMARG Vendor settings page
 * so you can always see what versions are installed AND what we are validated
 * against, even after you have dismissed the warning.
 *
 * @return void
 */
function zymarg_vd_compat_settings_footer() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, 'zymarg-vendor-dashboard' ) ) {
		return;
	}
	$state = zymarg_vd_compat_state();
	?>
	<div class="zvd-compat-card">
		<h2 class="zvd-mt-9"><?php esc_html_e( 'Dokan compatibility', 'zymarg-vendor-dashboard' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dokan Lite installed', 'zymarg-vendor-dashboard' ); ?></th>
					<td>
						<?php echo $state['dokan'] ? '<code>' . esc_html( $state['dokan'] ) . '</code>' : '<em>' . esc_html__( 'not active', 'zymarg-vendor-dashboard' ) . '</em>'; ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s validated version. */
								esc_html__( 'Validated up to %s.', 'zymarg-vendor-dashboard' ),
								'<code>' . esc_html( $state['dokan_ok'] ) . '</code>'
							);
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dokan Pro installed', 'zymarg-vendor-dashboard' ); ?></th>
					<td>
						<?php echo $state['pro'] ? '<code>' . esc_html( $state['pro'] ) . '</code>' : '<em>' . esc_html__( 'not active (free Dokan Lite only)', 'zymarg-vendor-dashboard' ) . '</em>'; ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s validated version. */
								esc_html__( 'Validated up to %s.', 'zymarg-vendor-dashboard' ),
								'<code>' . esc_html( $state['pro_ok'] ) . '</code>'
							);
							?>
						</span>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'On Dokan Lite, every native ZYMARG module runs (so the dashboard behaves like Dokan Pro). With Dokan Pro active, overlapping modules automatically stand down so Pro owns them.', 'zymarg-vendor-dashboard' ); ?>
		</p>
	</div>
	<?php
}
