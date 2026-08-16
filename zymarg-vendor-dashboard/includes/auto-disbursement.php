<?php
/**
 * ZYMARG Vendor Dashboard — Auto-Disbursement (Scheduled Payouts).
 *
 * Automatically creates approved payout requests for eligible vendors on a
 * configurable schedule (weekly/biweekly/monthly). The admin still manually
 * transfers the money -- this automates the approval step so vendors do not
 * have to request manually.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * Settings helpers
 * ====================================================================== */

/**
 * Get auto-disbursement settings.
 *
 * @return array
 */
function zymarg_vd_auto_disbursement_settings() {
	$defaults = array(
		'enabled'     => false,
		'frequency'   => 'monthly',
		'min_balance' => zymarg_vd_payout_min(),
		'day_of_week' => 1, // Monday (1=Mon ... 7=Sun).
		'day_of_month' => 1,
	);
	$saved = get_option( 'zymarg_vd_auto_disbursement', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Get last run result.
 *
 * @return array
 */
function zymarg_vd_auto_disbursement_last_run() {
	$data = get_option( 'zymarg_vd_auto_disbursement_last_run', array() );
	return is_array( $data ) ? $data : array();
}

/* ====================================================================== *
 * Cron schedule management
 * ====================================================================== */

define( 'ZYMARG_VD_AUTODISB_HOOK', 'zymarg_vd_auto_disbursement_run' );

/**
 * Register custom cron intervals.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function zymarg_vd_auto_disbursement_cron_schedules( $schedules ) {
	$schedules['zymarg_vd_biweekly'] = array(
		'interval' => 15 * DAY_IN_SECONDS,
		'display'  => __( 'Twice a month (ZYMARG)', 'zymarg-vendor-dashboard' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'zymarg_vd_auto_disbursement_cron_schedules' );

/**
 * Schedule or reschedule the cron event based on current settings.
 *
 * @return void
 */
function zymarg_vd_auto_disbursement_schedule() {
	$settings = zymarg_vd_auto_disbursement_settings();

	// Always clear existing schedule first.
	$timestamp = wp_next_scheduled( ZYMARG_VD_AUTODISB_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, ZYMARG_VD_AUTODISB_HOOK );
	}

	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	// Calculate next run time at 06:00 site time.
	$site_tz   = wp_timezone();
	$now       = new DateTimeImmutable( 'now', $site_tz );
	$frequency = $settings['frequency'];

	switch ( $frequency ) {
		case 'weekly':
			// Next Monday at 06:00.
			$next = $now->modify( 'next Monday' )->setTime( 6, 0, 0 );
			$recurrence = 'weekly';
			break;

		case 'biweekly':
			// 1st or 15th of the month at 06:00.
			$day = (int) $now->format( 'j' );
			if ( $day < 15 ) {
				$next = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), 15 )->setTime( 6, 0, 0 );
			} else {
				$next = $now->modify( 'first day of next month' )->setTime( 6, 0, 0 );
			}
			$recurrence = 'zymarg_vd_biweekly';
			break;

		case 'monthly':
		default:
			// 1st of next month at 06:00.
			$next = $now->modify( 'first day of next month' )->setTime( 6, 0, 0 );
			$recurrence = 'monthly';
			break;
	}

	wp_schedule_event( $next->getTimestamp(), $recurrence, ZYMARG_VD_AUTODISB_HOOK );
}

/**
 * Register the monthly schedule if WP does not have it by default.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function zymarg_vd_auto_disbursement_monthly_schedule( $schedules ) {
	if ( ! isset( $schedules['monthly'] ) ) {
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month (ZYMARG)', 'zymarg-vendor-dashboard' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'zymarg_vd_auto_disbursement_monthly_schedule' );

/* ====================================================================== *
 * Cron callback — process auto-payouts
 * ====================================================================== */

/**
 * Process auto-disbursement for all eligible vendors.
 *
 * @return array Summary of the run.
 */
function zymarg_vd_auto_disbursement_process() {
	$settings    = zymarg_vd_auto_disbursement_settings();
	$min_balance = (float) $settings['min_balance'];

	// Get all vendor users.
	$vendors = get_users(
		array(
			'role__in' => array( 'seller', 'vendor' ),
			'fields'   => 'ID',
		)
	);

	$processed    = 0;
	$total_amount = 0.0;
	$skipped      = array();

	foreach ( $vendors as $vendor_id ) {
		$vendor_id = (int) $vendor_id;

		// Check balance.
		$balance = zymarg_vd_payout_balance( $vendor_id );
		if ( $balance['available'] < $min_balance ) {
			$skipped[] = array(
				'vendor_id' => $vendor_id,
				'reason'    => 'insufficient_balance',
				'balance'   => $balance['available'],
			);
			continue;
		}

		// Check saved payout method.
		$saved_methods = zymarg_vd_get_payout_methods( $vendor_id );
		$default_key   = zymarg_vd_get_default_payout_method( $vendor_id );
		if ( empty( $saved_methods ) || empty( $default_key ) ) {
			$skipped[] = array(
				'vendor_id' => $vendor_id,
				'reason'    => 'no_payout_method',
			);
			continue;
		}

		// Check for existing pending/approved (not yet paid) requests.
		$existing = new WP_Query(
			array(
				'post_type'      => ZYMARG_PAYOUT_CPT,
				'post_status'    => 'any',
				'author'         => $vendor_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_zv_status',
						'value'   => array( 'pending', 'approved' ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( ! empty( $existing->posts ) ) {
			$skipped[] = array(
				'vendor_id' => $vendor_id,
				'reason'    => 'existing_pending_request',
			);
			continue;
		}

		// Create payout request (auto-approved).
		$amount      = $balance['available'];
		$method_data = isset( $saved_methods[ $default_key ] ) ? $saved_methods[ $default_key ] : array();

		$post_id = wp_insert_post(
			array(
				'post_type'   => ZYMARG_PAYOUT_CPT,
				'post_status' => 'publish',
				'post_author' => $vendor_id,
				'post_title'  => sprintf( 'Auto-Payout #%d', time() ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$skipped[] = array(
				'vendor_id' => $vendor_id,
				'reason'    => 'insert_failed',
			);
			continue;
		}

		update_post_meta( $post_id, '_zv_amount', $amount );
		update_post_meta( $post_id, '_zv_method', $default_key );
		update_post_meta( $post_id, '_zv_method_detail', $method_data );
		update_post_meta( $post_id, '_zv_status', 'approved' );
		update_post_meta( $post_id, '_zv_auto', '1' );
		update_post_meta( $post_id, '_zv_created', current_time( 'mysql' ) );

		$processed++;
		$total_amount += $amount;
	}

	$result = array(
		'timestamp'    => current_time( 'mysql' ),
		'count'        => $processed,
		'total_amount' => $total_amount,
		'skipped'      => $skipped,
	);

	update_option( 'zymarg_vd_auto_disbursement_last_run', $result );

	return $result;
}
add_action( ZYMARG_VD_AUTODISB_HOOK, 'zymarg_vd_auto_disbursement_process' );

/* ====================================================================== *
 * AJAX: Save settings
 * ====================================================================== */

/**
 * AJAX handler: save auto-disbursement settings and reschedule cron.
 *
 * @return void
 */
function zymarg_vd_save_auto_disbursement_ajax() {
	check_ajax_referer( 'zymarg_vd_auto_disbursement', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$enabled     = ! empty( $_POST['enabled'] );
	$frequency   = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'monthly';
	$min_balance = isset( $_POST['min_balance'] ) ? (float) wp_unslash( $_POST['min_balance'] ) : zymarg_vd_payout_min();
	$day_of_week = isset( $_POST['day_of_week'] ) ? (int) wp_unslash( $_POST['day_of_week'] ) : 1;
	$day_of_month = isset( $_POST['day_of_month'] ) ? (int) wp_unslash( $_POST['day_of_month'] ) : 1;

	// Validate.
	if ( ! in_array( $frequency, array( 'weekly', 'biweekly', 'monthly' ), true ) ) {
		$frequency = 'monthly';
	}
	if ( $min_balance < 0 ) {
		$min_balance = zymarg_vd_payout_min();
	}
	$day_of_week  = max( 1, min( 7, $day_of_week ) );
	$day_of_month = max( 1, min( 28, $day_of_month ) );

	$settings = array(
		'enabled'      => $enabled,
		'frequency'    => $frequency,
		'min_balance'  => $min_balance,
		'day_of_week'  => $day_of_week,
		'day_of_month' => $day_of_month,
	);

	update_option( 'zymarg_vd_auto_disbursement', $settings );
	zymarg_vd_auto_disbursement_schedule();

	$next = wp_next_scheduled( ZYMARG_VD_AUTODISB_HOOK );

	wp_send_json_success(
		array(
			'message'  => __( 'Auto-disbursement settings saved.', 'zymarg-vendor-dashboard' ),
			'next_run' => $next ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_save_auto_disbursement', 'zymarg_vd_save_auto_disbursement_ajax' );

/* ====================================================================== *
 * AJAX: Run now (manual trigger)
 * ====================================================================== */

/**
 * AJAX handler: manually trigger auto-disbursement.
 *
 * @return void
 */
function zymarg_vd_run_auto_disbursement_ajax() {
	check_ajax_referer( 'zymarg_vd_auto_disbursement', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$result = zymarg_vd_auto_disbursement_process();

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: 1: number of vendors paid, 2: total amount. */
				__( 'Auto-disbursement complete. %1$d vendor(s) processed, total amount: %2$s.', 'zymarg-vendor-dashboard' ),
				$result['count'],
				wp_strip_all_tags( function_exists( 'wc_price' ) ? wc_price( $result['total_amount'] ) : number_format( $result['total_amount'], 2 ) )
			),
			'result' => $result,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_run_auto_disbursement', 'zymarg_vd_run_auto_disbursement_ajax' );

/* ====================================================================== *
 * Admin UI: Auto-Disbursement card on the Payouts admin page
 * ====================================================================== */

/**
 * Render the Auto-Disbursement settings card (called from the admin payouts page).
 *
 * @return void
 */
function zymarg_vd_auto_disbursement_admin_card() {
	$settings = zymarg_vd_auto_disbursement_settings();
	$last_run = zymarg_vd_auto_disbursement_last_run();
	$next     = wp_next_scheduled( ZYMARG_VD_AUTODISB_HOOK );
	$nonce    = wp_create_nonce( 'zymarg_vd_auto_disbursement' );
	?>
	<div class="zvdp-auto-card" id="zymarg-auto-disbursement">
		<div class="zvdp-auto-card__header">
			<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			<h3><?php esc_html_e( 'Auto-Disbursement', 'zymarg-vendor-dashboard' ); ?></h3>
		</div>
		<p class="zvdp-auto-card__desc"><?php esc_html_e( 'Automatically create approved payout requests for eligible vendors on a schedule. You still transfer money manually -- this skips the vendor request + admin approval step.', 'zymarg-vendor-dashboard' ); ?></p>

		<form id="zymarg-auto-disb-form" class="zvdp-auto-form">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

			<label class="zvdp-auto-field">
				<span class="zvdp-auto-field__label"><?php esc_html_e( 'Enable auto-disbursement', 'zymarg-vendor-dashboard' ); ?></span>
				<label class="zvdp-toggle">
					<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
					<span class="zvdp-toggle__slider"></span>
				</label>
			</label>

			<label class="zvdp-auto-field">
				<span class="zvdp-auto-field__label"><?php esc_html_e( 'Frequency', 'zymarg-vendor-dashboard' ); ?></span>
				<select name="frequency">
					<option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly (every Monday)', 'zymarg-vendor-dashboard' ); ?></option>
					<option value="biweekly" <?php selected( $settings['frequency'], 'biweekly' ); ?>><?php esc_html_e( 'Biweekly (1st and 15th)', 'zymarg-vendor-dashboard' ); ?></option>
					<option value="monthly" <?php selected( $settings['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly (1st of month)', 'zymarg-vendor-dashboard' ); ?></option>
				</select>
			</label>

			<label class="zvdp-auto-field">
				<span class="zvdp-auto-field__label"><?php esc_html_e( 'Minimum balance (BDT)', 'zymarg-vendor-dashboard' ); ?></span>
				<input type="number" name="min_balance" value="<?php echo esc_attr( $settings['min_balance'] ); ?>" min="0" step="1">
			</label>

			<div class="zvdp-auto-actions">
				<button type="submit" class="zvdp-btn zvdp-btn--primary"><?php esc_html_e( 'Save settings', 'zymarg-vendor-dashboard' ); ?></button>
				<button type="button" class="zvdp-btn zvdp-btn--ghost" id="zymarg-auto-disb-run"><?php esc_html_e( 'Run now', 'zymarg-vendor-dashboard' ); ?></button>
			</div>
			<div class="zvdp-auto-msg" id="zymarg-auto-disb-msg" role="status" aria-live="polite"></div>
		</form>

		<div class="zvdp-auto-status">
			<?php if ( $next ) : ?>
				<div class="zvdp-auto-status__item">
					<strong><?php esc_html_e( 'Next scheduled run:', 'zymarg-vendor-dashboard' ); ?></strong>
					<?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
				</div>
			<?php elseif ( $settings['enabled'] ) : ?>
				<div class="zvdp-auto-status__item"><?php esc_html_e( 'Cron not yet scheduled. Save settings to schedule.', 'zymarg-vendor-dashboard' ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $last_run ) ) : ?>
				<div class="zvdp-auto-status__item">
					<strong><?php esc_html_e( 'Last run:', 'zymarg-vendor-dashboard' ); ?></strong>
					<?php
					printf(
						/* translators: 1: date, 2: vendors processed, 3: total amount. */
						esc_html__( '%1$s — %2$d vendor(s) paid, total %3$s', 'zymarg-vendor-dashboard' ),
						esc_html( $last_run['timestamp'] ),
						(int) $last_run['count'],
						esc_html( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $last_run['total_amount'] ) ) : number_format( $last_run['total_amount'], 2 ) )
					);
					?>
					<?php if ( ! empty( $last_run['skipped'] ) ) : ?>
						<span class="zvdp-auto-skipped">(<?php echo esc_html( count( $last_run['skipped'] ) ); ?> <?php esc_html_e( 'skipped', 'zymarg-vendor-dashboard' ); ?>)</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
	(function(){
		var form = document.getElementById('zymarg-auto-disb-form');
		var msgEl = document.getElementById('zymarg-auto-disb-msg');
		var runBtn = document.getElementById('zymarg-auto-disb-run');
		if (!form) return;

		function showMsg(text, type) {
			msgEl.textContent = text;
			msgEl.className = 'zvdp-auto-msg zvdp-auto-msg--' + type;
		}

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var fd = new FormData(form);
			fd.append('action', 'zymarg_vd_save_auto_disbursement');
			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res.success) {
						showMsg(res.data.message, 'success');
					} else {
						showMsg(res.data && res.data.message ? res.data.message : 'Error', 'error');
					}
				})
				.catch(function(){ showMsg('Network error.', 'error'); });
		});

		runBtn.addEventListener('click', function(){
			if (!confirm('Run auto-disbursement now? This will create approved payouts for all eligible vendors.')) return;
			var fd = new FormData();
			fd.append('action', 'zymarg_vd_run_auto_disbursement');
			fd.append('nonce', form.querySelector('[name=nonce]').value);
			runBtn.disabled = true;
			runBtn.textContent = 'Running...';
			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(res){
					runBtn.disabled = false;
					runBtn.textContent = 'Run now';
					if (res.success) {
						showMsg(res.data.message, 'success');
					} else {
						showMsg(res.data && res.data.message ? res.data.message : 'Error', 'error');
					}
				})
				.catch(function(){
					runBtn.disabled = false;
					runBtn.textContent = 'Run now';
					showMsg('Network error.', 'error');
				});
		});
	})();
	</script>

	<?php
}
