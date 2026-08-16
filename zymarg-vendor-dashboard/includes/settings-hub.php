<?php
/**
 * ZYMARG Vendor Dashboard — Native Settings Hub (Accordion).
 *
 * Plugin-level Settings page rendered in-shell when vendors click "Settings"
 * in the sidebar. As of v1.32.0 this is an accordion of 11 collapsible cards:
 *
 *   1. Account
 *   2. Change Password
 *   3. Notification Preferences
 *   4. Store Preferences
 *   5. Store Profile                    (NEW in v1.32.0 — absorbed the old
 *                                         standalone "Store Settings" screen:
 *                                         store name, public phone, banner,
 *                                         address, and Vacation mode)
 *   6. Tax & Business Info
 *   7. SEO & Store Meta
 *   8. Social Links
 *   9. Data Export
 *  10. Danger Zone
 *  11. Login & Security (ZLS bridge)
 *
 * (The old Section 11 "Push Notification Opt-in" was removed in v1.31.0 —
 * see zymarg_vd_settings_migrate_push_optin_prefs() below.)
 *
 * The Preferences (timezone) form is still rendered as-is below all cards.
 *
 * Fixes the /dashboard/settings/ redirect loop by registering 'settings' as
 * a native section so it no longer falls through to Dokan's URL.
 *
 * Toggle via Settings -> ZYMARG Vendor ("Settings" feature). When off, the
 * section hands back to Dokan.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the native Settings hub screen is active.
 *
 * @return bool
 */
function zymarg_vd_settings_hub_enabled() {
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'settings' );
}

/**
 * Re-label the toggle in the admin feature registry.
 *
 * @param array $registry Feature registry.
 * @return array
 */
function zymarg_vd_settings_hub_registry( $registry ) {
	$registry['settings'] = __( 'Settings (native accordion -- Account, Password, Notifications, ...)', 'zymarg-vendor-dashboard' );
	return $registry;
}
add_filter( 'zymarg_vd_feature_registry', 'zymarg_vd_settings_hub_registry' );

/**
 * Register 'settings' as a native section so the shell renders it in-page.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_settings_hub_native_section( $sections ) {
	if ( zymarg_vd_settings_hub_enabled() ) {
		$sections[] = 'settings';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_settings_hub_native_section' );

/**
 * Render the section when active.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section key.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_settings_hub_render( $html, $active, $user ) {
	if ( 'settings' !== $active || ! zymarg_vd_settings_hub_enabled() ) {
		return $html;
	}
	return zymarg_vd_render_settings_hub( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_settings_hub_render', 10, 3 );

/**
 * Enqueue assets when the settings hub is active.
 *
 * The shared addons.css already carries the .zymarg-vs-* accordion styles
 * appended in v1.28.0. The section-specific JS (accordion behaviour + the
 * three AJAX forms for Account / Password / Notifications) is enqueued by
 * `zymarg_vd_settings_page_enqueue()` in vendor-dashboard.php so it lives
 * next to the AJAX endpoints it talks to.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_settings_hub_assets( $ver ) {
	if ( ! zymarg_vd_settings_hub_enabled() ) {
		return;
	}
	if ( function_exists( 'zymarg_vd_enqueue_addons_css' ) ) {
		zymarg_vd_enqueue_addons_css( $ver );
	}
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_settings_hub_assets' );

/* ====================================================================== *
 * Section render
 * ====================================================================== */

/**
 * Render the Settings accordion page.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_hub( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
		return '';
	}

	$display_name = $user->display_name;
	$email        = $user->user_email;

	// Phone — split into +880 country code + local 10-digit body for display.
	$phone_raw   = (string) get_user_meta( $user->ID, '_zymarg_vd_phone', true );
	$phone_local = preg_replace( '/\D+/', '', $phone_raw );
	if ( strlen( $phone_local ) > 10 ) {
		// Strip the country code if the stored value included it.
		$phone_local = substr( $phone_local, -10 );
	}

	$avatar_url = get_avatar_url( $user->ID, array( 'size' => 128 ) );

	// Notification defaults (all TRUE unless the vendor has explicitly saved).
	$notif_prefs = zymarg_vd_settings_get_notification_prefs( $user->ID );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--row">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Settings', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Your account, security and preferences', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-vs" data-vs-root="1">

		<?php /* -------- 1. Account -------- */ ?>
		<section class="zymarg-vs-card is-open" data-vs-section="account" data-vs-open="1">
			<button type="button" class="zymarg-vs-card__toggle" aria-expanded="true">
				<span class="zymarg-vs-card__num">1</span>
				<span class="zymarg-vs-card__title"><?php esc_html_e( 'Account', 'zymarg-vendor-dashboard' ); ?></span>
				<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
			</button>
			<div class="zymarg-vs-card__body">
				<form class="zymarg-vs-form zymarg-vs-account" id="zymarg-vs-account-form" novalidate>
					<div class="zymarg-vs-avatar-row">
						<img id="zymarg-vs-avatar-preview" class="zymarg-vs-avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="96" height="96">
						<div class="zymarg-vs-avatar-actions">
							<button type="button" class="zymarg-vs-btn zymarg-vs-btn--ghost" id="zymarg-vs-avatar-change">
								<?php esc_html_e( 'Change avatar', 'zymarg-vendor-dashboard' ); ?>
							</button>
							<p class="zymarg-vs-hint"><?php esc_html_e( 'JPG or PNG, cropped and compressed to ~50 KB.', 'zymarg-vendor-dashboard' ); ?></p>
						</div>
					</div>

					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-display-name"><?php esc_html_e( 'Display name', 'zymarg-vendor-dashboard' ); ?></label>
						<input type="text" id="zymarg-vs-display-name" name="display_name" value="<?php echo esc_attr( $display_name ); ?>" autocomplete="name" required>
					</div>

					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-email"><?php esc_html_e( 'Email', 'zymarg-vendor-dashboard' ); ?></label>
						<div class="zymarg-vs-email-row">
							<input type="email" id="zymarg-vs-email" name="email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" disabled required>
							<button type="button" class="zymarg-vs-linkbtn" id="zymarg-vs-email-change">
								<?php esc_html_e( 'Change', 'zymarg-vendor-dashboard' ); ?>
							</button>
						</div>
						<div class="zymarg-vs-email-confirm" id="zymarg-vs-email-confirm" hidden>
							<label class="zymarg-zp-field__label" for="zymarg-vs-email-pw"><?php esc_html_e( 'Current password', 'zymarg-vendor-dashboard' ); ?></label>
							<div class="zymarg-vs-pw-wrap">
								<input type="password" id="zymarg-vs-email-pw" name="current_password" autocomplete="current-password">
								<button type="button" class="zymarg-vs-pw-toggle" aria-label="<?php esc_attr_e( 'Show password', 'zymarg-vendor-dashboard' ); ?>" data-vs-eye>
									<span class="zymarg-vs-pw-toggle__icon">&#128065;</span>
								</button>
							</div>
							<p class="zymarg-vs-hint"><?php esc_html_e( 'Required to change your email address.', 'zymarg-vendor-dashboard' ); ?></p>
						</div>
					</div>

					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-phone"><?php esc_html_e( 'Phone', 'zymarg-vendor-dashboard' ); ?></label>
						<div class="zymarg-vs-phone-row">
							<span class="zymarg-vs-phone-cc" aria-hidden="true">+880</span>
							<input type="tel" id="zymarg-vs-phone" name="phone" value="<?php echo esc_attr( $phone_local ); ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="1XXXXXXXXX">
						</div>
						<p class="zymarg-vs-hint"><?php esc_html_e( '10 digits after the +880 country code.', 'zymarg-vendor-dashboard' ); ?></p>
					</div>

					<div class="zymarg-vs-save-row">
						<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
							<?php esc_html_e( 'Save Account', 'zymarg-vendor-dashboard' ); ?>
						</button>
						<span class="zymarg-vs-flash" data-vs-flash></span>
					</div>
				</form>
			</div>
		</section>

		<?php /* -------- 2. Change Password -------- */ ?>
		<section class="zymarg-vs-card" data-vs-section="password" data-vs-open="0">
			<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
				<span class="zymarg-vs-card__num">2</span>
				<span class="zymarg-vs-card__title"><?php esc_html_e( 'Change Password', 'zymarg-vendor-dashboard' ); ?></span>
				<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
			</button>
			<div class="zymarg-vs-card__body">
				<form class="zymarg-vs-form zymarg-vs-password" id="zymarg-vs-password-form" novalidate>
					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-pw-current"><?php esc_html_e( 'Current password', 'zymarg-vendor-dashboard' ); ?></label>
						<div class="zymarg-vs-pw-wrap">
							<input type="password" id="zymarg-vs-pw-current" name="current_password" autocomplete="current-password" required>
							<button type="button" class="zymarg-vs-pw-toggle" aria-label="<?php esc_attr_e( 'Show password', 'zymarg-vendor-dashboard' ); ?>" data-vs-eye>
								<span class="zymarg-vs-pw-toggle__icon">&#128065;</span>
							</button>
						</div>
					</div>

					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-pw-new"><?php esc_html_e( 'New password', 'zymarg-vendor-dashboard' ); ?></label>
						<div class="zymarg-vs-pw-wrap">
							<input type="password" id="zymarg-vs-pw-new" name="new_password" autocomplete="new-password" minlength="8" required>
							<button type="button" class="zymarg-vs-pw-toggle" aria-label="<?php esc_attr_e( 'Show password', 'zymarg-vendor-dashboard' ); ?>" data-vs-eye>
								<span class="zymarg-vs-pw-toggle__icon">&#128065;</span>
							</button>
						</div>
						<div class="zymarg-vs-strength" aria-hidden="true" data-vs-strength>
							<span></span><span></span><span></span><span></span>
						</div>
						<p class="zymarg-vs-hint"><?php esc_html_e( 'At least 8 characters. Mix letters, numbers and symbols for a stronger password.', 'zymarg-vendor-dashboard' ); ?></p>
					</div>

					<div class="zymarg-zp-field">
						<label class="zymarg-zp-field__label" for="zymarg-vs-pw-confirm"><?php esc_html_e( 'Confirm new password', 'zymarg-vendor-dashboard' ); ?></label>
						<div class="zymarg-vs-pw-wrap">
							<input type="password" id="zymarg-vs-pw-confirm" name="confirm_password" autocomplete="new-password" minlength="8" required>
							<button type="button" class="zymarg-vs-pw-toggle" aria-label="<?php esc_attr_e( 'Show password', 'zymarg-vendor-dashboard' ); ?>" data-vs-eye>
								<span class="zymarg-vs-pw-toggle__icon">&#128065;</span>
							</button>
						</div>
					</div>

					<div class="zymarg-vs-save-row">
						<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
							<?php esc_html_e( 'Update Password', 'zymarg-vendor-dashboard' ); ?>
						</button>
						<span class="zymarg-vs-flash" data-vs-flash></span>
					</div>
				</form>
			</div>
		</section>

		<?php /* -------- 3. Notification Preferences -------- */ ?>
		<section class="zymarg-vs-card" data-vs-section="notifications" data-vs-open="0">
			<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
				<span class="zymarg-vs-card__num">3</span>
				<span class="zymarg-vs-card__title"><?php esc_html_e( 'Notification Preferences', 'zymarg-vendor-dashboard' ); ?></span>
				<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
			</button>
			<div class="zymarg-vs-card__body">
				<form class="zymarg-vs-form zymarg-vs-notif" id="zymarg-vs-notif-form" novalidate>
					<div class="zymarg-vs-notif-banner zymarg-vs-notif-banner--info" role="note">
						<span class="zymarg-vs-notif-banner__icon" aria-hidden="true">&#9432;</span>
						<span class="zymarg-vs-notif-banner__text"><?php esc_html_e( 'SMS delivery is not yet active on this store — your SMS preferences are saved and will apply once the SMS gateway is enabled.', 'zymarg-vendor-dashboard' ); ?></span>
					</div>
					<div class="zymarg-vs-notif-grid" role="table" aria-label="<?php esc_attr_e( 'Notification preferences', 'zymarg-vendor-dashboard' ); ?>">
						<div class="zymarg-vs-notif-grid__head" role="row">
							<span class="zymarg-vs-notif-grid__event"><?php esc_html_e( 'Event', 'zymarg-vendor-dashboard' ); ?></span>
							<span class="zymarg-vs-notif-grid__col"><?php esc_html_e( 'Email', 'zymarg-vendor-dashboard' ); ?></span>
							<span class="zymarg-vs-notif-grid__col"><?php esc_html_e( 'Push', 'zymarg-vendor-dashboard' ); ?></span>
							<span class="zymarg-vs-notif-grid__col"><?php esc_html_e( 'SMS', 'zymarg-vendor-dashboard' ); ?></span>
						</div>
						<?php foreach ( zymarg_vd_settings_notification_events() as $event_key => $event_label ) :
							$email_on = ! empty( $notif_prefs[ $event_key ]['email'] );
							$push_on  = ! empty( $notif_prefs[ $event_key ]['push'] );
							$sms_on   = ! empty( $notif_prefs[ $event_key ]['sms'] );
							?>
							<div class="zymarg-vs-notif-grid__row" role="row" data-event="<?php echo esc_attr( $event_key ); ?>">
								<span class="zymarg-vs-notif-grid__event"><?php echo esc_html( $event_label ); ?></span>
								<label class="zymarg-vs-notif-grid__col zymarg-vs-toggle">
									<input type="checkbox" name="prefs[<?php echo esc_attr( $event_key ); ?>][email]" value="1" <?php checked( $email_on ); ?>>
									<span class="zymarg-vs-toggle__track" aria-hidden="true"><span class="zymarg-vs-toggle__thumb"></span></span>
									<span class="screen-reader-text"><?php
										/* translators: %s: event label */
										printf( esc_html__( 'Email for %s', 'zymarg-vendor-dashboard' ), esc_html( $event_label ) ); ?></span>
								</label>
								<label class="zymarg-vs-notif-grid__col zymarg-vs-toggle">
									<input type="checkbox" name="prefs[<?php echo esc_attr( $event_key ); ?>][push]" value="1" <?php checked( $push_on ); ?>>
									<span class="zymarg-vs-toggle__track" aria-hidden="true"><span class="zymarg-vs-toggle__thumb"></span></span>
									<span class="screen-reader-text"><?php
										/* translators: %s: event label */
										printf( esc_html__( 'Push for %s', 'zymarg-vendor-dashboard' ), esc_html( $event_label ) ); ?></span>
								</label>
								<label class="zymarg-vs-notif-grid__col zymarg-vs-toggle">
									<input type="checkbox" name="prefs[<?php echo esc_attr( $event_key ); ?>][sms]" value="1" <?php checked( $sms_on ); ?>>
									<span class="zymarg-vs-toggle__track" aria-hidden="true"><span class="zymarg-vs-toggle__thumb"></span></span>
									<span class="screen-reader-text"><?php
										/* translators: %s: event label */
										printf( esc_html__( 'SMS for %s', 'zymarg-vendor-dashboard' ), esc_html( $event_label ) ); ?></span>
								</label>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="zymarg-vs-save-row">
						<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
							<?php esc_html_e( 'Save Preferences', 'zymarg-vendor-dashboard' ); ?>
						</button>
						<span class="zymarg-vs-flash" data-vs-flash></span>
					</div>
				</form>
			</div>
		</section>

		<?php
		// Real card renderers (v1.29.0+) — one helper per section.
		echo zymarg_vd_render_settings_card_store_preferences( $user );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_store_profile( $user );      // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_business( $user );           // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_seo( $user );                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_social( $user );             // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_data_export( $user );        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_danger_zone( $user );        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_vd_render_settings_card_login_security( $user );     // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

	</div><?php // .zymarg-vs ?>

	<?php echo zymarg_vd_render_preferences_card( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * The canonical list of vendor-facing notification events.
 *
 * Keys are stable identifiers (used in user_meta + AJAX payloads); values are
 * the human labels shown in the Notifications grid.
 *
 * @return array<string,string>
 */
function zymarg_vd_settings_notification_events() {
	return array(
		'new_order'             => __( 'New order', 'zymarg-vendor-dashboard' ),
		'order_status_changed'  => __( 'Order status changed', 'zymarg-vendor-dashboard' ),
		'low_stock'             => __( 'Low stock', 'zymarg-vendor-dashboard' ),
		'new_review'            => __( 'New review', 'zymarg-vendor-dashboard' ),
		'new_message'           => __( 'New message', 'zymarg-vendor-dashboard' ),
		'payout_approved'       => __( 'Payout approved', 'zymarg-vendor-dashboard' ),
		'payout_paid'           => __( 'Payout paid', 'zymarg-vendor-dashboard' ),
		'announcement'          => __( 'Announcement', 'zymarg-vendor-dashboard' ),
		'refund_request'        => __( 'Refund request', 'zymarg-vendor-dashboard' ),
	);
}

/**
 * One-time migration: fold the now-removed Section 11 "Push Notification
 * Opt-in" per-user prefs (`_zymarg_vd_push_prefs`) into the unified
 * `_zymarg_vd_notification_prefs` store, so a vendor who had already
 * muted a push event there doesn't silently have it reset to "on" now
 * that Section 3's Push column is the single source of truth.
 *
 * Runs at most once per user: it only fires when
 * `_zymarg_vd_notification_prefs` has NEVER been saved for that user (if
 * the vendor already touched Section 3, that data wins and is left
 * alone). The old meta key's event names ('order_status') differ from
 * the notification-events registry ('order_status_changed'), so they are
 * explicitly mapped. The old meta key is deleted once migrated so this
 * never runs twice.
 *
 * @param int $user_id User ID.
 * @return void
 */
function zymarg_vd_settings_migrate_push_optin_prefs( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	$old_push = get_user_meta( $user_id, '_zymarg_vd_push_prefs', true );
	if ( ! is_array( $old_push ) || empty( $old_push ) ) {
		// Nothing to migrate — still remove a stray non-array value, if any.
		delete_user_meta( $user_id, '_zymarg_vd_push_prefs' );
		return;
	}

	// Section 3 already has its own saved state -> that's authoritative, don't clobber it.
	$existing = get_user_meta( $user_id, '_zymarg_vd_notification_prefs', true );
	if ( is_array( $existing ) && ! empty( $existing ) ) {
		delete_user_meta( $user_id, '_zymarg_vd_push_prefs' );
		return;
	}

	// Old Section 11 event key -> current notification-events registry key.
	$key_map = array(
		'new_order'    => 'new_order',
		'order_status' => 'order_status_changed',
		'new_message'  => 'new_message',
		'low_stock'    => 'low_stock',
		'announcement' => 'announcement',
	);

	$events = zymarg_vd_settings_notification_events();
	$merged = array();
	foreach ( array_keys( $events ) as $event_key ) {
		$merged[ $event_key ] = array(
			'email' => true,
			'push'  => true,
			'sms'   => false,
		);
	}
	foreach ( $key_map as $old_key => $new_key ) {
		if ( isset( $old_push[ $old_key ] ) && isset( $merged[ $new_key ] ) ) {
			$merged[ $new_key ]['push'] = (bool) $old_push[ $old_key ];
		}
	}

	update_user_meta( $user_id, '_zymarg_vd_notification_prefs', $merged );
	delete_user_meta( $user_id, '_zymarg_vd_push_prefs' );
}

/**
 * Read the notification preferences user_meta and shape it into a full
 * per-event array. Email and Push default to TRUE (on-by-default for new
 * vendors), while SMS defaults to FALSE (opt-in) since live SMS delivery
 * isn't wired up yet — the preference persists so it "just works" once the
 * SMS gateway is enabled.
 *
 * @param int $user_id User ID.
 * @return array<string,array{email:bool,push:bool,sms:bool}>
 */
function zymarg_vd_settings_get_notification_prefs( $user_id ) {
	zymarg_vd_settings_migrate_push_optin_prefs( $user_id );

	$saved  = get_user_meta( (int) $user_id, '_zymarg_vd_notification_prefs', true );
	$events = zymarg_vd_settings_notification_events();
	$out    = array();

	foreach ( array_keys( $events ) as $event_key ) {
		$has_email = isset( $saved[ $event_key ]['email'] );
		$has_push  = isset( $saved[ $event_key ]['push'] );
		$has_sms   = isset( $saved[ $event_key ]['sms'] );
		$out[ $event_key ] = array(
			'email' => $has_email ? (bool) $saved[ $event_key ]['email'] : true,
			'push'  => $has_push  ? (bool) $saved[ $event_key ]['push']  : true,
			'sms'   => $has_sms   ? (bool) $saved[ $event_key ]['sms']   : false,
		);
	}

	return $out;
}

/**
 * Render the Timezone form (bare inline) below the accordion.
 *
 * No section title, no card wrapper — just the field + Save button sitting on
 * the page. Kept intentionally minimal per user preference.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_preferences_card( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
		return '';
	}

	$current_tz = function_exists( 'zymarg_vd_get_vendor_timezone' )
		? zymarg_vd_get_vendor_timezone( $user->ID )
		: ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' );

	$has_custom = (bool) get_user_meta( $user->ID, '_zymarg_vd_timezone', true );

	// Success/error flash after admin-post save.
	$flash = '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['zvd_prefs'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_key( wp_unslash( $_GET['zvd_prefs'] ) );
		if ( 'saved' === $state ) {
			$flash = '<p class="zymarg-vd-tz-flash zymarg-vd-tz-flash--ok">' . esc_html__( 'Timezone saved.', 'zymarg-vendor-dashboard' ) . '</p>';
		} elseif ( 'invalid' === $state ) {
			$flash = '<p class="zymarg-vd-tz-flash zymarg-vd-tz-flash--err">' . esc_html__( 'Invalid timezone. Please pick one from the list.', 'zymarg-vendor-dashboard' ) . '</p>';
		} elseif ( 'nope' === $state ) {
			$flash = '<p class="zymarg-vd-tz-flash zymarg-vd-tz-flash--err">' . esc_html__( 'You are not allowed to change this.', 'zymarg-vendor-dashboard' ) . '</p>';
		}
	}

	// wp_timezone_choice() emits <option> tags for every IANA + UTC offset zone,
	// with the given tz selected. It's a WP-native, fully i18n'd helper.
	$options_html = wp_timezone_choice( $current_tz, get_user_locale( $user->ID ) );

	ob_start();
	?>
	<form class="zymarg-vd-tz" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="zymarg_vd_save_prefs" />
		<?php wp_nonce_field( 'zymarg_vd_save_prefs', 'zymarg_vd_prefs_nonce' ); ?>
		<input type="hidden" name="_redirect" value="<?php echo esc_url( wp_get_referer() ? wp_get_referer() : home_url( '/dashboard/settings/' ) ); ?>" />

		<?php echo $flash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above ?>

		<label for="zymarg-vd-tz" class="zymarg-vd-tz__label"><?php esc_html_e( 'Timezone', 'zymarg-vendor-dashboard' ); ?></label>
		<div class="zymarg-vd-tz__row">
			<select name="zymarg_vd_timezone" id="zymarg-vd-tz" class="zymarg-vd-tz__select">
				<?php echo $options_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_timezone_choice() escapes internally ?>
			</select>
			<button type="submit" class="zymarg-vd-tz__save"><?php esc_html_e( 'Save', 'zymarg-vendor-dashboard' ); ?></button>
			<?php if ( $has_custom ) : ?>
				<button type="submit" name="zymarg_vd_timezone" value="" class="zymarg-vd-tz__reset"><?php esc_html_e( 'Reset', 'zymarg-vendor-dashboard' ); ?></button>
			<?php endif; ?>
		</div>
	</form>

	<?php
	return (string) ob_get_clean();
}

/**
 * Handle the Preferences form submit (currently: timezone).
 *
 * Nonce + capability guarded. Validates the timezone via timezone_open()
 * before writing user_meta. An empty submit deletes the meta (returns to
 * site default). Redirects back to the referring page with a status flag.
 *
 * @return void
 */
function zymarg_vd_save_prefs_handler() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_die( esc_html__( 'You must be logged in.', 'zymarg-vendor-dashboard' ), '', array( 'response' => 403 ) );
	}

	$referer = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) : '';
	if ( '' === $referer ) {
		$referer = wp_get_referer() ? wp_get_referer() : home_url( '/dashboard/settings/' );
	}

	// Nonce check.
	if ( ! isset( $_POST['zymarg_vd_prefs_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['zymarg_vd_prefs_nonce'] ) ), 'zymarg_vd_save_prefs' ) ) {
		wp_safe_redirect( add_query_arg( 'zvd_prefs', 'nope', $referer ) );
		exit;
	}

	// Capability check — must be able to view the vendor dashboard.
	$can = function_exists( 'zymarg_os_can_view_vendor_dashboard' )
		? zymarg_os_can_view_vendor_dashboard()
		: current_user_can( 'read' );
	if ( ! $can ) {
		wp_safe_redirect( add_query_arg( 'zvd_prefs', 'nope', $referer ) );
		exit;
	}

	$raw = isset( $_POST['zymarg_vd_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['zymarg_vd_timezone'] ) ) : '';

	if ( '' === $raw ) {
		// Reset to site default => remove meta.
		delete_user_meta( $user_id, '_zymarg_vd_timezone' );
		wp_safe_redirect( add_query_arg( 'zvd_prefs', 'saved', $referer ) );
		exit;
	}

	// Validate: must be a real timezone string PHP accepts.
	if ( false === timezone_open( $raw ) ) {
		wp_safe_redirect( add_query_arg( 'zvd_prefs', 'invalid', $referer ) );
		exit;
	}

	update_user_meta( $user_id, '_zymarg_vd_timezone', $raw );
	wp_safe_redirect( add_query_arg( 'zvd_prefs', 'saved', $referer ) );
	exit;
}
add_action( 'admin_post_zymarg_vd_save_prefs', 'zymarg_vd_save_prefs_handler' );


/* ====================================================================== *
 * v1.29.0 — Settings sections 4-7 (real forms) and 8-11 (still placeholders).
 *
 * Every real card follows the accordion pattern established in v1.28.0
 * (section-1..3): outer <section class="zymarg-vs-card">, a header button
 * toggle, and a body form that submits over AJAX using the shared
 * `zymarg_vendor_action` nonce. See assets/js/vendor-settings.js for the
 * matching client side and includes/vendor-dashboard.php for the AJAX
 * handlers (`zymarg_vd_settings_save_*`).
 *
 * Sections 8-11 stay as "Coming in this release" placeholders — a follow-up
 * delegation will replace them with real forms.
 * ====================================================================== */

/**
 * Section 4 — Store Preferences.
 *
 * Renders auto-accept-orders toggle, minimum-order-value (BDT prefix), and
 * a default order-note template with a live char counter. Values are stored
 * as user_meta and consumed by the runtime hooks in vendor-dashboard.php.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_store_preferences( $user ) {
	$auto_accept = (int) get_user_meta( $user->ID, '_zymarg_vd_auto_accept_orders', true );
	$min_order   = (int) get_user_meta( $user->ID, '_zymarg_vd_min_order_value', true );
	$note        = (string) get_user_meta( $user->ID, '_zymarg_vd_default_order_note', true );
	$note_len    = function_exists( 'mb_strlen' ) ? mb_strlen( $note ) : strlen( $note );

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="store-preferences" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">4</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Store Preferences', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<form class="zymarg-vs-form" id="zymarg-vs-store-preferences-form" novalidate>

				<div class="zymarg-zp-field">
					<div class="zymarg-vs-toggle-row">
						<label class="zymarg-zp-field__label" for="zymarg-vs-auto-accept"><?php esc_html_e( 'Auto-accept orders', 'zymarg-vendor-dashboard' ); ?></label>
						<label class="zymarg-vs-toggle">
							<input type="checkbox" id="zymarg-vs-auto-accept" name="auto_accept" value="1" <?php checked( $auto_accept, 1 ); ?>>
							<span class="zymarg-vs-toggle__track" aria-hidden="true"><span class="zymarg-vs-toggle__thumb"></span></span>
						</label>
					</div>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'When on, new pending orders auto-move to Processing after 5 minutes without your action.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-min-order"><?php esc_html_e( 'Minimum order value', 'zymarg-vendor-dashboard' ); ?></label>
					<div class="zymarg-vs-currency-row">
						<span class="zymarg-vs-currency-cc" aria-hidden="true">&#2547;</span>
						<input type="number" id="zymarg-vs-min-order" name="min_order_value" value="<?php echo esc_attr( $min_order > 0 ? $min_order : '' ); ?>" min="0" step="1" placeholder="0">
					</div>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Set 0 or leave blank for no minimum.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-default-order-note"><?php esc_html_e( 'Default order note', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-default-order-note" name="default_order_note" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'Thank you for shopping with us! Your order will be dispatched within 24-48 hours.', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>
					<div class="zymarg-vs-counter" id="zymarg-vs-default-order-note-counter"><?php echo (int) $note_len; ?>/500</div>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Auto-attached as a customer-visible note on every new order you receive.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-vs-save-row">
					<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
						<?php esc_html_e( 'Save Store Preferences', 'zymarg-vendor-dashboard' ); ?>
					</button>
					<span class="zymarg-vs-flash" data-vs-flash></span>
				</div>
			</form>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 5 — Store Profile.
 *
 * The vendor's PUBLIC storefront profile — store name, banner image, and
 * structured address — plus Vacation mode. All fields read/write Dokan's
 * own `dokan_profile_settings` user meta directly, so the public Dokan
 * store page always stays in sync (this is the same storage the standalone
 * "Store Settings" screen used before it was folded into this accordion in
 * v1.32.0 — no data migration needed, only the UI moved).
 *
 * v1.33.0 — REMOVED the "Public phone" and "Show my email address on the
 * store page" fields entirely. Those wrote straight into Dokan's own
 * `phone` / `show_email` keys, which Dokan's native store-header template
 * (templates/store-header.php) reads to print the vendor's real phone
 * number and email address DIRECTLY on the public storefront, in front of
 * any customer. This marketplace's policy is that customers never see a
 * vendor's raw contact details — only admin should have them, and buyers
 * reach vendors exclusively through the built-in Contact Seller messaging
 * feature ([zymarg_contact_seller], see vendor-dashboard.php), which never
 * exposes a phone number or email. Any phone/show_email values a vendor
 * had already saved (e.g. during this plugin's trial period, before this
 * fix) are force-cleared on upgrade by
 * `zymarg_vd_scrub_public_contact_details()` below, so nothing already
 * saved keeps leaking after this version installs.
 *
 * Vacation mode is centralized HERE (not in Danger Zone) because the real
 * storefront effects — the away-notice on product pages
 * (`zymarg_vd_vacation_product_notice()`) and the optional add-to-cart
 * pause (`zymarg_vd_vacation_purchasable()`), both in vendor-dashboard.php —
 * read the `setting_go_vacation` key that THIS section's toggle writes.
 * (Danger Zone used to have its own "Deactivate Store" vacation toggle that
 * wrote a differently-spelled key, `setting_go_vocation` — a typo that
 * silently disconnected it from the real effects. That action was removed
 * in v1.32.0; this is now the one and only vacation control.)
 *
 * @param WP_User $user Current user.
 * @return string
 */
/**
 * Word limits for the seller-written store copy.
 *
 * A minimum stops a one-word "story" from rendering as an empty-looking
 * block; a maximum stops a wall of text from overflowing the card and
 * pushing the rest of the store page around. Empty is always allowed and is
 * never checked against the minimum -- clearing every story field is the
 * documented way to hide the Our Story section entirely.
 *
 * @return array Field key => array{min:int,max:int,label:string}.
 */
function zymarg_vd_story_limits() {
	$limits = array(
		'store_tagline'  => array(
			'min'   => 2,
			'max'   => 12,
			'label' => __( 'Store tagline', 'zymarg-vendor-dashboard' ),
		),
		'story_headline' => array(
			'min'   => 3,
			'max'   => 14,
			'label' => __( 'Story headline', 'zymarg-vendor-dashboard' ),
		),
		'story_short'    => array(
			'min'   => 20,
			'max'   => 120,
			'label' => __( 'Your story', 'zymarg-vendor-dashboard' ),
		),
		'story_more'     => array(
			'min'   => 20,
			'max'   => 200,
			'label' => __( 'More of your story', 'zymarg-vendor-dashboard' ),
		),
	);

	/**
	 * Filter the store copy word limits.
	 *
	 * @param array $limits Field key => array{min:int,max:int,label:string}.
	 */
	return apply_filters( 'zymarg_vd_story_limits', $limits );
}

/**
 * Count words in a way that works outside English too.
 *
 * str_word_count() is Latin-alphabet only and returns 0 for Bangla, so it
 * cannot be used here. Splitting on Unicode whitespace counts words in any
 * script the seller writes in, and matches what the browser-side counter
 * does so the two never disagree.
 *
 * @param string $text Raw text.
 * @return int
 */
function zymarg_vd_count_words( $text ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );
	if ( '' === $text ) {
		return 0;
	}
	$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $parts ) ? count( $parts ) : 0;
}

/**
 * Render the word counter that sits under a story field.
 *
 * @param string $key Field key from zymarg_vd_story_limits().
 * @return string
 */
function zymarg_vd_story_counter( $key ) {
	$limits = zymarg_vd_story_limits();
	if ( ! isset( $limits[ $key ] ) ) {
		return '';
	}

	return sprintf(
		'<p class="zymarg-vs-hint zymarg-vs-wordcount" data-word-counter-for="%1$s" data-word-min="%2$d" data-word-max="%3$d"></p>',
		esc_attr( $key ),
		(int) $limits[ $key ]['min'],
		(int) $limits[ $key ]['max']
	);
}

function zymarg_vd_render_settings_card_store_profile( $user ) {
	$vendor_id = (int) $user->ID;
	$profile   = get_user_meta( $vendor_id, 'dokan_profile_settings', true );
	$profile   = is_array( $profile ) ? $profile : array();

	$store_name = isset( $profile['store_name'] ) ? (string) $profile['store_name'] : '';
	if ( '' === $store_name && function_exists( 'zymarg_os_vendor_store_name' ) ) {
		$store_name = (string) zymarg_os_vendor_store_name( $vendor_id );
	}

	$store_tagline = isset( $profile['store_tagline'] ) ? (string) $profile['store_tagline'] : '';

	// Store story.
	//
	// The short paragraph stays in Dokan's own `store_description` key so it
	// remains readable by Dokan and by anything else already using it. The
	// headline and the Read More continuation are ZYMARG additions with no
	// Dokan equivalent, so they live in their own user meta.
	$story_short    = isset( $profile['store_description'] ) ? (string) $profile['store_description'] : '';
	$story_headline = (string) get_user_meta( $vendor_id, '_zymarg_vd_story_headline', true );
	$story_more     = (string) get_user_meta( $vendor_id, '_zymarg_vd_story_more', true );

	$address = isset( $profile['address'] ) && is_array( $profile['address'] ) ? $profile['address'] : array();
	$addr    = function ( $k ) use ( $address ) {
		return isset( $address[ $k ] ) ? (string) $address[ $k ] : '';
	};

	// Banner — same dual-fallback pattern as the sidebar avatar
	// (zymarg_os_vendor_store_avatar_html()): prefer Dokan's own profile
	// key (works even if another tool set it), fall back to the plugin's
	// own cached URL from the last in-shell upload.
	$banner_url = '';
	if ( ! empty( $profile['banner'] ) ) {
		$banner_url = (string) wp_get_attachment_image_url( (int) $profile['banner'], 'large' );
	}
	if ( '' === $banner_url ) {
		$banner_url = (string) get_user_meta( $vendor_id, '_zymarg_store_banner_url', true );
	}

	$managed_by_pro = function_exists( 'zymarg_vd_vacation_managed_by_pro' ) && zymarg_vd_vacation_managed_by_pro();
	$vac_on         = isset( $profile['setting_go_vacation'] ) && 'yes' === $profile['setting_go_vacation'];
	$vac_msg        = isset( $profile['setting_vacation_message'] ) ? (string) $profile['setting_vacation_message'] : '';
	$vac_cart       = isset( $profile['zymarg_vacation_disable_cart'] ) && 'yes' === $profile['zymarg_vacation_disable_cart'];

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="store-profile" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">5</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Store Profile', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<p class="zymarg-vs-hint" style="margin:0 0 14px;"><?php esc_html_e( 'Your public store profile — kept in sync with your storefront.', 'zymarg-vendor-dashboard' ); ?></p>

			<form class="zymarg-vs-form" id="zymarg-vs-store-profile-form" novalidate>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-sp-store-name"><?php esc_html_e( 'Store name', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-sp-store-name" name="store_name" value="<?php echo esc_attr( $store_name ); ?>" maxlength="120">
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Buyers reach you only through Contact Seller messaging — your phone/email are never shown on your storefront.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-sp-store-tagline"><?php esc_html_e( 'Store tagline', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-sp-store-tagline" name="store_tagline" value="<?php echo esc_attr( $store_tagline ); ?>" maxlength="70" placeholder="<?php esc_attr_e( 'e.g. Built by hand in Dhaka since 2019', 'zymarg-vendor-dashboard' ); ?>">
					<p class="zymarg-vs-hint"><?php esc_html_e( 'The short line under your store name at the top of your store page.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php echo zymarg_vd_story_counter( 'store_tagline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-sp-story-headline"><?php esc_html_e( 'Story headline', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-sp-story-headline" name="story_headline" value="<?php echo esc_attr( $story_headline ); ?>" maxlength="90" placeholder="<?php esc_attr_e( 'e.g. Handmade leather goods, built to last', 'zymarg-vendor-dashboard' ); ?>">
					<p class="zymarg-vs-hint"><?php esc_html_e( 'One line at the top of the Our Story block on your store page.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php echo zymarg_vd_story_counter( 'story_headline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-sp-story-short"><?php esc_html_e( 'Your story', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-sp-story-short" name="story_short" rows="4" maxlength="600" placeholder="<?php esc_attr_e( 'Who you are, what you make, and why buyers should trust you.', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $story_short ); ?></textarea>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'The first paragraph buyers read. Leave every story field empty to hide the section completely.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php echo zymarg_vd_story_counter( 'story_short' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-sp-story-more"><?php esc_html_e( 'More of your story (optional)', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-sp-story-more" name="story_more" rows="4" maxlength="900" placeholder="<?php esc_attr_e( 'Materials, process, guarantees — anything that needs more room.', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $story_more ); ?></textarea>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Hidden behind the Read More button. Leave empty and no button is shown.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php echo zymarg_vd_story_counter( 'story_more' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label"><?php esc_html_e( 'Store banner', 'zymarg-vendor-dashboard' ); ?></label>

					<div class="zymarg-vs-banner<?php echo $banner_url ? ' has-image' : ''; ?>" data-zvu-zone="banner">
						<button type="button" class="zymarg-vs-banner__area" data-zvu-toggle="banner" aria-label="<?php esc_attr_e( 'Upload store banner', 'zymarg-vendor-dashboard' ); ?>">
							<img src="<?php echo esc_url( $banner_url ); ?>" alt="" class="zymarg-vs-banner-img">
							<span class="zymarg-vs-banner__empty">
								<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
								<span><?php esc_html_e( 'Tap to upload a banner', 'zymarg-vendor-dashboard' ); ?></span>
							</span>
							<span class="zymarg-vs-banner__overlay" aria-hidden="true">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
								<span><?php esc_html_e( 'Change banner', 'zymarg-vendor-dashboard' ); ?></span>
							</span>
						</button>
						<button type="button" class="zymarg-vs-banner__remove" data-zvu-remove="banner">
							<?php esc_html_e( 'Remove', 'zymarg-vendor-dashboard' ); ?>
						</button>
					</div>
					<input type="file" id="zvu-banner-file" accept="image/*" hidden>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Recommended: wide, 4:1 ratio (e.g. 1600×400). Uploads instantly — no need to click Save.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label"><?php esc_html_e( 'Address', 'zymarg-vendor-dashboard' ); ?></label>
					<div class="zymarg-vs-addr-grid">
						<input type="text" name="address[street_1]" value="<?php echo esc_attr( $addr( 'street_1' ) ); ?>" placeholder="<?php esc_attr_e( 'Street address', 'zymarg-vendor-dashboard' ); ?>">
						<input type="text" name="address[street_2]" value="<?php echo esc_attr( $addr( 'street_2' ) ); ?>" placeholder="<?php esc_attr_e( 'Area / Street 2', 'zymarg-vendor-dashboard' ); ?>">
						<input type="text" name="address[city]" value="<?php echo esc_attr( $addr( 'city' ) ); ?>" placeholder="<?php esc_attr_e( 'City', 'zymarg-vendor-dashboard' ); ?>">
						<input type="text" name="address[zip]" value="<?php echo esc_attr( $addr( 'zip' ) ); ?>" placeholder="<?php esc_attr_e( 'Postcode / ZIP', 'zymarg-vendor-dashboard' ); ?>">
						<input type="text" name="address[state]" value="<?php echo esc_attr( $addr( 'state' ) ); ?>" placeholder="<?php esc_attr_e( 'State / Division', 'zymarg-vendor-dashboard' ); ?>">
						<input type="text" name="address[country]" value="<?php echo esc_attr( $addr( 'country' ) ? $addr( 'country' ) : 'BD' ); ?>" placeholder="<?php esc_attr_e( 'Country', 'zymarg-vendor-dashboard' ); ?>">
					</div>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label"><?php esc_html_e( 'Vacation mode', 'zymarg-vendor-dashboard' ); ?></label>
					<?php if ( $managed_by_pro ) : ?>
						<p class="zymarg-vs-hint"><?php esc_html_e( 'Vacation is managed by Dokan Pro — your existing settings carry over and are used as-is.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php else : ?>
						<label class="zymarg-zp-check">
							<input type="checkbox" name="vacation_on" value="1" <?php checked( $vac_on ); ?>>
							<?php esc_html_e( 'I am away — show a notice on my store', 'zymarg-vendor-dashboard' ); ?>
						</label>
						<textarea name="vacation_message" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. We are closed for Eid and will ship orders from the 15th.', 'zymarg-vendor-dashboard' ); ?>" style="margin-top:8px;"><?php echo esc_textarea( $vac_msg ); ?></textarea>
						<label class="zymarg-zp-check" style="margin-top:8px;">
							<input type="checkbox" name="vacation_disable_cart" value="1" <?php checked( $vac_cart ); ?>>
							<?php esc_html_e( 'Also pause sales (hide Add to cart on my products) while away', 'zymarg-vendor-dashboard' ); ?>
						</label>
					<?php endif; ?>
				</div>

				<div class="zymarg-vs-save-row">
					<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
						<?php esc_html_e( 'Save Store Profile', 'zymarg-vendor-dashboard' ); ?>
					</button>
					<span class="zymarg-vs-flash" data-vs-flash></span>
				</div>
			</form>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 6 — Tax & Business Info.
 *
 * Five optional identity fields used by future invoicing / compliance work.
 * Kept private (never displayed publicly). Empty values wipe the meta so
 * `get_user_meta()` returns '' rather than persisting stale data.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_business( $user ) {
	$bin           = (string) get_user_meta( $user->ID, '_zymarg_vd_business_bin', true );
	$tin           = (string) get_user_meta( $user->ID, '_zymarg_vd_business_tin', true );
	$biz_name      = (string) get_user_meta( $user->ID, '_zymarg_vd_business_name', true );
	$trade_license = (string) get_user_meta( $user->ID, '_zymarg_vd_business_trade_license', true );
	$address       = (string) get_user_meta( $user->ID, '_zymarg_vd_business_address', true );

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="tax-business" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">6</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Tax & Business Info', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<div class="zymarg-vs-business-notice">
				<span class="zymarg-vs-business-notice__icon" aria-hidden="true">&#9432;</span>
				<span><?php esc_html_e( 'Used for invoice generation and Bangladesh Bank compliance. Kept private — never shown publicly.', 'zymarg-vendor-dashboard' ); ?></span>
			</div>

			<form class="zymarg-vs-form" id="zymarg-vs-tax-business-form" novalidate>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-biz-bin"><?php esc_html_e( 'Business Identification Number (BIN)', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-biz-bin" name="business_bin" value="<?php echo esc_attr( $bin ); ?>" maxlength="13" inputmode="numeric" pattern="[0-9]*" placeholder="<?php esc_attr_e( 'Your 13-digit BIN', 'zymarg-vendor-dashboard' ); ?>">
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-biz-tin"><?php esc_html_e( 'Tax Identification Number (TIN)', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-biz-tin" name="business_tin" value="<?php echo esc_attr( $tin ); ?>" maxlength="12" inputmode="numeric" pattern="[0-9]*" placeholder="<?php esc_attr_e( 'Your 12-digit TIN', 'zymarg-vendor-dashboard' ); ?>">
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-biz-name"><?php esc_html_e( 'Registered business name', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-biz-name" name="business_name" value="<?php echo esc_attr( $biz_name ); ?>" maxlength="100">
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-biz-trade-license"><?php esc_html_e( 'Trade license number', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-biz-trade-license" name="business_trade_license" value="<?php echo esc_attr( $trade_license ); ?>" maxlength="30">
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-biz-address"><?php esc_html_e( 'Business address', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-biz-address" name="business_address" rows="3" maxlength="500"><?php echo esc_textarea( $address ); ?></textarea>
				</div>

				<div class="zymarg-vs-save-row">
					<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
						<?php esc_html_e( 'Save Business Info', 'zymarg-vendor-dashboard' ); ?>
					</button>
					<span class="zymarg-vs-flash" data-vs-flash></span>
				</div>
			</form>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 7 — SEO & Store Meta.
 *
 * Vendors set a title, description, OG image (via WP media library), and
 * optional OG title/description overrides. The actual store-page <head>
 * emission lives in `zymarg_vd_seo_render_meta_tags()` below.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_seo( $user ) {
	$seo_title    = (string) get_user_meta( $user->ID, '_zymarg_vd_seo_title', true );
	$seo_desc     = (string) get_user_meta( $user->ID, '_zymarg_vd_seo_desc', true );
	$og_title     = (string) get_user_meta( $user->ID, '_zymarg_vd_og_title', true );
	$og_desc      = (string) get_user_meta( $user->ID, '_zymarg_vd_og_desc', true );
	$og_image_id  = (int) get_user_meta( $user->ID, '_zymarg_vd_og_image_id', true );
	$og_image_url = $og_image_id ? wp_get_attachment_image_url( $og_image_id, 'medium' ) : '';

	$title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $seo_title ) : strlen( $seo_title );
	$desc_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $seo_desc ) : strlen( $seo_desc );
	$otit_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $og_title ) : strlen( $og_title );
	$odes_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $og_desc ) : strlen( $og_desc );

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="seo-store-meta" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">7</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'SEO & Store Meta', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<form class="zymarg-vs-form" id="zymarg-vs-seo-form" novalidate>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-seo-title"><?php esc_html_e( 'Store SEO title', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-seo-title" name="seo_title" value="<?php echo esc_attr( $seo_title ); ?>" maxlength="60" placeholder="<?php esc_attr_e( "e.g. Prian's Store — Handcrafted goods from Bangladesh", 'zymarg-vendor-dashboard' ); ?>">
					<div class="zymarg-vs-counter<?php echo $title_len >= 60 ? ' is-over' : ''; ?>" id="zymarg-vs-seo-title-counter"><?php echo (int) $title_len; ?>/60</div>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Shown as the tab title and in search engine results (max 60 characters).', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-seo-desc"><?php esc_html_e( 'Meta description', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-seo-desc" name="seo_desc" rows="2" maxlength="160" placeholder="<?php esc_attr_e( 'A short sentence about your store that appears in search engine results.', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $seo_desc ); ?></textarea>
					<div class="zymarg-vs-counter<?php echo $desc_len >= 160 ? ' is-over' : ''; ?>" id="zymarg-vs-seo-desc-counter"><?php echo (int) $desc_len; ?>/160</div>
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Max 160 characters. Shows on Google search results under your store title.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label"><?php esc_html_e( 'Social share image', 'zymarg-vendor-dashboard' ); ?></label>
					<div class="zymarg-vs-og-actions">
						<button type="button" class="zymarg-vs-btn zymarg-vs-btn--ghost zymarg-vs-og-choose"><?php esc_html_e( 'Choose image', 'zymarg-vendor-dashboard' ); ?></button>
						<button type="button" class="zymarg-vs-og-remove" style="<?php echo $og_image_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'zymarg-vendor-dashboard' ); ?></button>
					</div>
					<input type="hidden" id="zymarg-vs-og-image-id" name="og_image_id" value="<?php echo esc_attr( $og_image_id ? $og_image_id : '' ); ?>">
					<img src="<?php echo esc_url( $og_image_url ); ?>" alt="" class="zymarg-vs-og-preview" style="max-width:200px; height:auto; border-radius:8px; border:1px solid var(--zv-border); <?php echo $og_image_url ? '' : 'display:none;'; ?>">
					<p class="zymarg-vs-hint"><?php esc_html_e( 'Recommended: 1200×630 pixels. Shown when your store link is shared on Facebook, WhatsApp, or Twitter.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-og-title"><?php esc_html_e( 'Social share title (optional)', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="text" id="zymarg-vs-og-title" name="og_title" value="<?php echo esc_attr( $og_title ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'Falls back to Store SEO title', 'zymarg-vendor-dashboard' ); ?>">
					<div class="zymarg-vs-counter<?php echo $otit_len >= 100 ? ' is-over' : ''; ?>" id="zymarg-vs-og-title-counter"><?php echo (int) $otit_len; ?>/100</div>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-og-desc"><?php esc_html_e( 'Social share description (optional)', 'zymarg-vendor-dashboard' ); ?></label>
					<textarea id="zymarg-vs-og-desc" name="og_desc" rows="2" maxlength="200" placeholder="<?php esc_attr_e( 'Falls back to Meta description', 'zymarg-vendor-dashboard' ); ?>"><?php echo esc_textarea( $og_desc ); ?></textarea>
					<div class="zymarg-vs-counter<?php echo $odes_len >= 200 ? ' is-over' : ''; ?>" id="zymarg-vs-og-desc-counter"><?php echo (int) $odes_len; ?>/200</div>
				</div>

				<div class="zymarg-vs-save-row">
					<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
						<?php esc_html_e( 'Save SEO', 'zymarg-vendor-dashboard' ); ?>
					</button>
					<span class="zymarg-vs-flash" data-vs-flash></span>
				</div>
			</form>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 8 — Social Links.
 *
 * Four Dokan-managed URLs (fb / instagram / twitter / youtube — same key
 * names Dokan itself uses in `dokan_profile_settings['social']`), plus two
 * native fields we own (WhatsApp digits + TikTok URL). This is the SINGLE
 * canonical Social Links location — the standalone "Store Settings" screen
 * used to have its own duplicate social-links block writing the same
 * fb/instagram/twitter/youtube keys (harmlessly redundant) plus a SEPARATE
 * WhatsApp field in a different meta key (NOT harmless — the two disagreed
 * silently). That whole screen was removed in v1.32.0; this card was
 * already the more complete one (adds TikTok) so it was kept as-is.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_social( $user ) {
	$profile = get_user_meta( $user->ID, 'dokan_profile_settings', true );
	if ( ! is_array( $profile ) ) {
		$profile = array();
	}
	$social = isset( $profile['social'] ) && is_array( $profile['social'] ) ? $profile['social'] : array();

	$fb        = isset( $social['fb'] ) ? (string) $social['fb'] : '';
	$instagram = isset( $social['instagram'] ) ? (string) $social['instagram'] : '';
	$twitter   = isset( $social['twitter'] ) ? (string) $social['twitter'] : '';
	$youtube   = isset( $social['youtube'] ) ? (string) $social['youtube'] : '';

	$whatsapp_raw = (string) get_user_meta( $user->ID, '_zymarg_vd_social_whatsapp', true );
	$whatsapp     = preg_replace( '/\D+/', '', $whatsapp_raw );
	if ( strlen( $whatsapp ) > 10 ) {
		$whatsapp = substr( $whatsapp, -10 );
	}
	$tiktok = (string) get_user_meta( $user->ID, '_zymarg_vd_social_tiktok', true );

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="social-links" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">8</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Social Links', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<form class="zymarg-vs-form" id="zymarg-vs-social-links-form" novalidate>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-fb"><?php esc_html_e( 'Facebook URL', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="url" id="zymarg-vs-social-fb" name="social_fb" value="<?php echo esc_attr( $fb ); ?>" placeholder="https://facebook.com/yourstore">
					<span class="zymarg-vs-fielderr" data-vs-err></span>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-instagram"><?php esc_html_e( 'Instagram URL', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="url" id="zymarg-vs-social-instagram" name="social_instagram" value="<?php echo esc_attr( $instagram ); ?>" placeholder="https://instagram.com/yourstore">
					<span class="zymarg-vs-fielderr" data-vs-err></span>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-whatsapp"><?php esc_html_e( 'WhatsApp number', 'zymarg-vendor-dashboard' ); ?></label>
					<div class="zymarg-vs-phone-row">
						<span class="zymarg-vs-phone-cc" aria-hidden="true">+880</span>
						<input type="tel" id="zymarg-vs-social-whatsapp" name="social_whatsapp" value="<?php echo esc_attr( $whatsapp ); ?>" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="1XXXXXXXXX">
					</div>
					<?php if ( '' !== $whatsapp ) : ?>
						<a class="zymarg-vs-linkbtn" href="<?php echo esc_url( 'https://wa.me/880' . $whatsapp ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Test WhatsApp link', 'zymarg-vendor-dashboard' ); ?></a>
					<?php endif; ?>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-youtube"><?php esc_html_e( 'YouTube URL', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="url" id="zymarg-vs-social-youtube" name="social_youtube" value="<?php echo esc_attr( $youtube ); ?>" placeholder="https://youtube.com/@yourstore">
					<span class="zymarg-vs-fielderr" data-vs-err></span>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-twitter"><?php esc_html_e( 'Twitter/X URL', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="url" id="zymarg-vs-social-twitter" name="social_twitter" value="<?php echo esc_attr( $twitter ); ?>" placeholder="https://twitter.com/yourstore">
					<span class="zymarg-vs-fielderr" data-vs-err></span>
				</div>

				<div class="zymarg-zp-field">
					<label class="zymarg-zp-field__label" for="zymarg-vs-social-tiktok"><?php esc_html_e( 'TikTok URL', 'zymarg-vendor-dashboard' ); ?></label>
					<input type="url" id="zymarg-vs-social-tiktok" name="social_tiktok" value="<?php echo esc_attr( $tiktok ); ?>" placeholder="https://tiktok.com/@yourstore">
					<span class="zymarg-vs-fielderr" data-vs-err></span>
				</div>

				<div class="zymarg-vs-save-row">
					<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--primary">
						<?php esc_html_e( 'Save Social Links', 'zymarg-vendor-dashboard' ); ?>
					</button>
					<span class="zymarg-vs-flash" data-vs-flash></span>
				</div>
			</form>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * v1.30.0 — Settings sections 8-10 real card renderers (renumbered 9-11 in
 * v1.32.0 when Section 5 "Store Profile" was inserted).
 *
 * Section 9  : Data Export       — admin-post.php CSV streams (orders/customers/products).
 * Section 10 : Danger Zone       — closure request, delete account (scheduled).
 * Section 11 : Login & Security  — read-only ZLS bridge (sessions/events/passkeys).
 *
 * (Section "Push Notification Opt-in" was removed in v1.31.0 — it
 * duplicated the Push column already present in Section 3's Notification
 * Preferences grid, and the two wrote to different user_meta keys that
 * could silently disagree. The real push sender now reads its per-user
 * gate from Section 3's data instead; see push-notifications.php.
 *
 * v1.32.0: the standalone "Store Settings" screen was removed and folded
 * into this accordion as the new Section 5 "Store Profile" — which is also
 * where Vacation mode now lives exclusively. Danger Zone's own vacation
 * toggle (which had a typo'd meta key, disconnected from the real
 * storefront effects) was removed here as part of the same change.)
 * ====================================================================== */

/**
 * Section 9 — Data Export.
 *
 * Three vertically-stacked buttons that POST to admin-post.php endpoints
 * (real form submits — browsers handle the resulting CSV download natively).
 * Rate limited to 1 export per type per vendor per minute; the error
 * message is surfaced as a `?export_error=…` query arg the JS toasts on
 * load, then strips via history.replaceState.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_data_export( $user ) {
	$nonce      = wp_create_nonce( 'zymarg_vendor_action' );
	$post_url   = admin_url( 'admin-post.php' );
	$export_err = '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['export_error'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_err = sanitize_text_field( wp_unslash( $_GET['export_error'] ) );
	}

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="data-export" data-vs-open="0" data-vs-export-error="<?php echo esc_attr( $export_err ); ?>">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">9</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Data Export', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
			<p class="zymarg-vs-export-intro"><?php esc_html_e( 'Download a copy of your store data as CSV — one export per type per minute.', 'zymarg-vendor-dashboard' ); ?></p>

			<div class="zymarg-vs-export-grid">
				<?php
				$types = array(
					'orders'    => array(
						'label'   => __( 'Export Orders', 'zymarg-vendor-dashboard' ),
						'sub'     => __( 'Downloads all your orders as CSV.', 'zymarg-vendor-dashboard' ),
						'icon'    => '&#128179;',
					),
					'customers' => array(
						'label'   => __( 'Export Customers', 'zymarg-vendor-dashboard' ),
						'sub'     => __( 'Distinct buyers who purchased from you.', 'zymarg-vendor-dashboard' ),
						'icon'    => '&#128100;',
					),
					'products'  => array(
						'label'   => __( 'Export Products', 'zymarg-vendor-dashboard' ),
						'sub'     => __( 'Every product in your catalog.', 'zymarg-vendor-dashboard' ),
						'icon'    => '&#128230;',
					),
				);
				foreach ( $types as $type => $meta ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="zymarg-vs-export-form">
						<input type="hidden" name="action" value="zymarg_vd_settings_export_<?php echo esc_attr( $type ); ?>">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<button type="submit" class="zymarg-vs-export-btn">
							<span class="zymarg-vs-export-btn__icon" aria-hidden="true"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="zymarg-vs-export-btn__label"><?php echo esc_html( $meta['label'] ); ?></span>
							<span class="zymarg-vs-export-btn__sub"><?php echo esc_html( $meta['sub'] ); ?></span>
						</button>
					</form>
				<?php endforeach; ?>
			</div>

			<p class="zymarg-vs-hint zymarg-vs-export-note"><?php esc_html_e( 'Rate limited to 1 export per type per minute. CSV filenames include your store slug and today\'s date.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 10 — Danger Zone.
 *
 * Two escalating destructive actions:
 *   A) Close Store       — flags for admin review (typed-confirm on store name).
 *   B) Delete Account    — scheduled 7-day WP-Cron delete (typed-confirm "DELETE MY ACCOUNT").
 *
 * (v1.32.0: the "Deactivate Store" vacation toggle that used to live here
 * was removed — it wrote a typo'd meta key, `setting_go_vocation`, that
 * was silently disconnected from the real storefront vacation effects.
 * Vacation mode now lives exclusively in Section 5 "Store Profile", using
 * the correct key, `setting_go_vacation`.)
 *
 * If a closure or a deletion is already pending the UI swaps to a status
 * card with an "Undo" button rather than showing the destructive form.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_danger_zone( $user ) {
	$user_id = (int) $user->ID;

	// Store name for the typed-confirm hint (falls back to display name).
	$store_name = '';
	if ( function_exists( 'dokan_get_store_info' ) ) {
		$info = (array) dokan_get_store_info( $user_id );
		if ( ! empty( $info['store_name'] ) ) {
			$store_name = (string) $info['store_name'];
		}
	}
	if ( '' === $store_name ) {
		$store_name = (string) $user->display_name;
	}

	// Pending flags.
	$close_at   = (string) get_user_meta( $user_id, '_zymarg_vd_close_requested', true );
	$delete_at  = (int) get_user_meta( $user_id, '_zymarg_vd_delete_scheduled', true );

	ob_start();
	?>
	<section class="zymarg-vs-card zymarg-vs-danger" data-vs-section="danger-zone" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">10</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Danger Zone', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">

			<p class="zymarg-vs-danger-intro"><?php esc_html_e( 'These actions affect the visibility or lifecycle of your account. Some are reversible; some are not.', 'zymarg-vendor-dashboard' ); ?></p>

			<?php /* ----- Action A: Close Store Permanently ----- */ ?>
			<div class="zymarg-vs-danger-row" data-vs-danger="close">
				<div class="zymarg-vs-danger-row__text">
					<h3 class="zymarg-vs-danger-row__title"><?php esc_html_e( 'Close store permanently', 'zymarg-vendor-dashboard' ); ?></h3>
					<p class="zymarg-vs-danger-row__desc"><?php esc_html_e( 'Flags your store for closure review. A marketplace admin will contact you within 3 business days to confirm and archive your data. Your buyer/order history is preserved.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>
				<div class="zymarg-vs-danger-row__action zymarg-vs-danger-row__action--wide">
					<?php if ( '' !== $close_at ) : ?>
						<div class="zymarg-vs-danger-status zymarg-vs-danger-status--pending" data-vs-close-status>
							<p class="zymarg-vs-danger-status__title"><?php esc_html_e( 'Closure request submitted.', 'zymarg-vendor-dashboard' ); ?></p>
							<p class="zymarg-vs-danger-status__desc">
								<?php
								/* translators: %s: date string. */
								printf( esc_html__( 'An admin will contact you within 3 business days (requested %s). To cancel this request, contact support or click Cancel below.', 'zymarg-vendor-dashboard' ), esc_html( mysql2date( get_option( 'date_format', 'Y-m-d' ), $close_at ) ) );
								?>
							</p>
							<button type="button" class="zymarg-vs-btn zymarg-vs-btn--ghost" data-vs-cancel-close><?php esc_html_e( 'Cancel Request', 'zymarg-vendor-dashboard' ); ?></button>
							<span class="zymarg-vs-flash" data-vs-flash></span>
						</div>
					<?php else : ?>
						<form class="zymarg-vs-form zymarg-vs-danger-form" data-vs-close-form novalidate>
							<div class="zymarg-zp-field">
								<label class="zymarg-zp-field__label" for="zymarg-vs-close-reason"><?php esc_html_e( 'Reason (optional)', 'zymarg-vendor-dashboard' ); ?></label>
								<textarea id="zymarg-vs-close-reason" name="close_reason" rows="2" maxlength="500" placeholder="<?php esc_attr_e( 'Tell us why you\'re closing (helps us improve).', 'zymarg-vendor-dashboard' ); ?>"></textarea>
							</div>
							<div class="zymarg-zp-field">
								<label class="zymarg-zp-field__label" for="zymarg-vs-close-confirm"><?php esc_html_e( 'Type your store name to confirm', 'zymarg-vendor-dashboard' ); ?></label>
								<p class="zymarg-vs-hint zymarg-vs-hint--confirm"><?php
									/* translators: %s: store name. */
									printf( esc_html__( 'Store name: %s', 'zymarg-vendor-dashboard' ), '<code>' . esc_html( $store_name ) . '</code>' );
								?></p>
								<input type="text" id="zymarg-vs-close-confirm" class="zymarg-vs-typedconfirm" name="close_confirm" data-vs-expected="<?php echo esc_attr( $store_name ); ?>" autocomplete="off">
							</div>
							<div class="zymarg-vs-save-row">
								<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--danger" disabled data-vs-close-submit>
									<?php esc_html_e( 'Submit Closure Request', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<span class="zymarg-vs-flash" data-vs-flash></span>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php /* ----- Action B: Delete Account (scheduled) ----- */ ?>
			<div class="zymarg-vs-danger-row" data-vs-danger="delete">
				<div class="zymarg-vs-danger-row__text">
					<h3 class="zymarg-vs-danger-row__title"><?php esc_html_e( 'Delete account', 'zymarg-vendor-dashboard' ); ?></h3>
					<p class="zymarg-vs-danger-row__desc"><?php esc_html_e( 'Permanently deletes your account 7 days from submission. During the wait window you can cancel. Consider downloading your data first — see Section 9: Data Export.', 'zymarg-vendor-dashboard' ); ?></p>
					<a class="zymarg-vs-linkbtn" href="#" data-vs-jump-to="data-export"><?php esc_html_e( '→ Download your data first', 'zymarg-vendor-dashboard' ); ?></a>
				</div>
				<div class="zymarg-vs-danger-row__action zymarg-vs-danger-row__action--wide">
					<?php if ( $delete_at > 0 ) : ?>
						<div class="zymarg-vs-danger-status zymarg-vs-danger-status--countdown" data-vs-delete-status>
							<p class="zymarg-vs-danger-status__title"><?php esc_html_e( 'Account scheduled for deletion.', 'zymarg-vendor-dashboard' ); ?></p>
							<p class="zymarg-vs-danger-status__desc">
								<?php
								/* translators: %s: scheduled deletion date. */
								printf( esc_html__( 'Your account will be permanently deleted on %s. Click below to cancel.', 'zymarg-vendor-dashboard' ), esc_html( wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $delete_at ) ) );
								?>
							</p>
							<button type="button" class="zymarg-vs-btn zymarg-vs-btn--danger-lg" data-vs-cancel-delete><?php esc_html_e( 'Cancel Deletion', 'zymarg-vendor-dashboard' ); ?></button>
							<span class="zymarg-vs-flash" data-vs-flash></span>
						</div>
					<?php else : ?>
						<form class="zymarg-vs-form zymarg-vs-danger-form" data-vs-delete-form novalidate>
							<div class="zymarg-zp-field">
								<label class="zymarg-zp-field__label" for="zymarg-vs-delete-confirm"><?php esc_html_e( 'Type DELETE MY ACCOUNT to confirm', 'zymarg-vendor-dashboard' ); ?></label>
								<p class="zymarg-vs-hint zymarg-vs-hint--confirm"><?php esc_html_e( 'Type the exact phrase in ALL CAPS to enable the button.', 'zymarg-vendor-dashboard' ); ?></p>
								<input type="text" id="zymarg-vs-delete-confirm" class="zymarg-vs-typedconfirm" name="delete_confirm" data-vs-expected="DELETE MY ACCOUNT" autocomplete="off" spellcheck="false">
							</div>
							<div class="zymarg-vs-save-row">
								<button type="submit" class="zymarg-vs-btn zymarg-vs-btn--danger" disabled data-vs-delete-submit>
									<?php esc_html_e( 'Schedule Account Deletion (7 days)', 'zymarg-vendor-dashboard' ); ?>
								</button>
								<span class="zymarg-vs-flash" data-vs-flash></span>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>

		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Section 10 — Login & Security (ZLS bridge, DISPLAY-ONLY).
 *
 * Reads three ZLS-owned tables directly (no write path):
 *   - {prefix}zls_refresh_tokens : active refresh sessions
 *   - {prefix}zls_login_events   : audit ledger (last 10 events)
 *   - {prefix}zls_passkeys       : registered WebAuthn credentials
 *
 * If ZLS isn't active (ZLS_VERSION not defined) we render a graceful empty
 * state card instead of hard-failing.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_settings_card_login_security( $user ) {
	$user_id = (int) $user->ID;

	ob_start();
	?>
	<section class="zymarg-vs-card" data-vs-section="login-security" data-vs-open="0">
		<button type="button" class="zymarg-vs-card__toggle" aria-expanded="false">
			<span class="zymarg-vs-card__num">11</span>
			<span class="zymarg-vs-card__title"><?php esc_html_e( 'Login & Security', 'zymarg-vendor-dashboard' ); ?></span>
			<span class="zymarg-vs-card__chevron" aria-hidden="true">&#x25BE;</span>
		</button>
		<div class="zymarg-vs-card__body">
		<?php if ( ! defined( 'ZLS_VERSION' ) ) : ?>
			<div class="zymarg-vs-zls-empty">
				<p class="zymarg-vs-zls-empty__title"><?php esc_html_e( 'Login & Security module not active', 'zymarg-vendor-dashboard' ); ?></p>
				<p class="zymarg-vs-zls-empty__desc"><?php esc_html_e( 'The ZYMARG Login & Security plugin is not active on this store. Ask your marketplace admin to enable it to see your sign-in history and manage active sessions.', 'zymarg-vendor-dashboard' ); ?></p>
			</div>
		<?php else :
			global $wpdb;

			$refresh_table  = $wpdb->prefix . 'zls_refresh_tokens';
			$events_table   = $wpdb->prefix . 'zls_login_events';
			$passkeys_table = $wpdb->prefix . 'zls_passkeys';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, device_label, last_used_at, expires_at, created_at FROM {$refresh_table} WHERE user_id = %d AND expires_at > NOW() ORDER BY last_used_at DESC LIMIT 20",
					$user_id
				)
			);
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_type, method, status, ip, country, device, created_at FROM {$events_table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 10",
					$user_id
				)
			);
			$passkeys = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, device_name, last_used_at, created_at FROM {$passkeys_table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 20",
					$user_id
				)
			);
			// phpcs:enable

			// The session with the most recent last_used_at is considered the current one.
			$current_session_id = 0;
			if ( ! empty( $sessions ) ) {
				$current_session_id = (int) $sessions[0]->id;
			}
			?>
			<div class="zymarg-vs-zls">
				<p class="zymarg-vs-zls-lead">
					<?php esc_html_e( 'Your account security — active sessions, recent sign-in activity, and registered passkeys. You can revoke sessions and remove passkeys directly from here.', 'zymarg-vendor-dashboard' ); ?>
				</p>

				<?php /* --- Active sessions --- */ ?>
				<div class="zymarg-vs-zls-block">
					<h3 class="zymarg-vs-zls-block__title"><?php esc_html_e( 'Active sessions', 'zymarg-vendor-dashboard' ); ?></h3>
					<?php if ( empty( $sessions ) ) : ?>
						<p class="zymarg-vs-zls-empty__desc"><?php esc_html_e( 'No active refresh sessions.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php else : ?>
						<table class="zymarg-vs-zls-table">
							<thead><tr>
								<th><?php esc_html_e( 'Device', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Action', 'zymarg-vendor-dashboard' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $sessions as $s ) :
								$is_current = ( (int) $s->id === $current_session_id );
								?>
								<tr>
									<td><?php echo esc_html( '' !== (string) $s->device_label ? $s->device_label : __( '(unlabeled)', 'zymarg-vendor-dashboard' ) ); ?></td>
									<td><?php echo esc_html( $s->last_used_at ? human_time_diff( strtotime( (string) $s->last_used_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'zymarg-vendor-dashboard' ) : '—' ); ?></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format', 'Y-m-d' ), (string) $s->expires_at ) ); ?></td>
									<td>
										<?php if ( $is_current ) : ?>
											<span class="zymarg-vs-zls-current"><?php esc_html_e( 'Current', 'zymarg-vendor-dashboard' ); ?></span>
										<?php else : ?>
											<button type="button" class="zymarg-vs-zls-revoke" data-token-id="<?php echo esc_attr( $s->id ); ?>"><?php esc_html_e( 'Revoke', 'zymarg-vendor-dashboard' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<?php /* --- Recent sign-ins --- */ ?>
				<div class="zymarg-vs-zls-block">
					<h3 class="zymarg-vs-zls-block__title"><?php esc_html_e( 'Recent sign-in activity', 'zymarg-vendor-dashboard' ); ?></h3>
					<?php if ( empty( $events ) ) : ?>
						<p class="zymarg-vs-zls-empty__desc"><?php esc_html_e( 'No recent events recorded.', 'zymarg-vendor-dashboard' ); ?></p>
					<?php else : ?>
						<table class="zymarg-vs-zls-table">
							<thead><tr>
								<th><?php esc_html_e( 'When', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Event', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'IP', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Country', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Device', 'zymarg-vendor-dashboard' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $events as $ev ) :
								$evt_label = strtoupper( (string) $ev->event_type );
								$status = (string) $ev->status;
								?>
								<tr>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), (string) $ev->created_at ) ); ?></td>
									<td><span class="zymarg-vs-zls-badge zymarg-vs-zls-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $evt_label ); ?></span></td>
									<td><?php echo esc_html( (string) $ev->ip ); ?></td>
									<td><?php echo esc_html( (string) $ev->country ); ?></td>
									<td><?php echo esc_html( (string) $ev->device ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<?php /* --- Passkeys --- */ ?>
				<div class="zymarg-vs-zls-block">
					<h3 class="zymarg-vs-zls-block__title"><?php esc_html_e( 'Passkeys', 'zymarg-vendor-dashboard' ); ?></h3>
					<?php if ( empty( $passkeys ) ) : ?>
						<p class="zymarg-vs-zls-empty__desc">
							<?php esc_html_e( 'No passkeys registered yet. Passkeys are registered at the login screen — use your browser or the ZYMARG app to add one next time you sign in.', 'zymarg-vendor-dashboard' ); ?>
						</p>
					<?php else : ?>
						<table class="zymarg-vs-zls-table">
							<thead><tr>
								<th><?php esc_html_e( 'Label', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Registered', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'zymarg-vendor-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Action', 'zymarg-vendor-dashboard' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $passkeys as $pk ) : ?>
								<tr>
									<td><?php echo esc_html( '' !== (string) $pk->device_name ? $pk->device_name : __( '(unlabeled)', 'zymarg-vendor-dashboard' ) ); ?></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format', 'Y-m-d' ), (string) $pk->created_at ) ); ?></td>
									<td><?php echo esc_html( $pk->last_used_at ? mysql2date( get_option( 'date_format', 'Y-m-d' ), (string) $pk->last_used_at ) : '—' ); ?></td>
									<td>
										<button type="button" class="zymarg-vs-zls-remove-pk" data-passkey-id="<?php echo esc_attr( $pk->id ); ?>"><?php esc_html_e( 'Remove', 'zymarg-vendor-dashboard' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * v1.29.0 — SEO tag emission on Dokan vendor store pages.
 * ====================================================================== */

/**
 * Emit meta description + Open Graph tags in <head> when the current
 * request is a Dokan vendor store page and the vendor has set values in
 * Section 6.
 *
 * @return void
 */
function zymarg_vd_seo_render_meta_tags() {
	// Detect if we're on a Dokan vendor store page.
	if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
		return;
	}
	// Resolve the store's owning user.
	$vendor_id = 0;
	if ( function_exists( 'dokan_get_store_url' ) ) {
		$vendor_id = (int) get_query_var( 'author' );
		if ( ! $vendor_id ) {
			$slug = get_query_var( 'author_name' );
			$user = $slug ? get_user_by( 'slug', $slug ) : null;
			if ( $user ) {
				$vendor_id = (int) $user->ID;
			}
		}
	}
	if ( ! $vendor_id ) {
		return;
	}

	$seo_title    = (string) get_user_meta( $vendor_id, '_zymarg_vd_seo_title', true );
	$seo_desc     = (string) get_user_meta( $vendor_id, '_zymarg_vd_seo_desc', true );
	$og_title     = (string) get_user_meta( $vendor_id, '_zymarg_vd_og_title', true );
	$og_desc      = (string) get_user_meta( $vendor_id, '_zymarg_vd_og_desc', true );
	$og_image_id  = (int) get_user_meta( $vendor_id, '_zymarg_vd_og_image_id', true );
	$og_image_url = $og_image_id ? wp_get_attachment_image_url( $og_image_id, 'full' ) : '';

	$final_og_title = '' !== $og_title ? $og_title : $seo_title;
	$final_og_desc  = '' !== $og_desc ? $og_desc : $seo_desc;

	if ( '' !== $seo_desc ) {
		echo '<meta name="description" content="' . esc_attr( $seo_desc ) . '">' . "\n";
	}
	if ( '' !== $final_og_title ) {
		echo '<meta property="og:title" content="' . esc_attr( $final_og_title ) . '">' . "\n";
	}
	if ( '' !== $final_og_desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $final_og_desc ) . '">' . "\n";
	}
	if ( $og_image_url ) {
		echo '<meta property="og:image" content="' . esc_url( $og_image_url ) . '">' . "\n";
	}
	echo '<meta property="og:type" content="website">' . "\n";
	if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->request ) ) {
		echo '<meta property="og:url" content="' . esc_url( home_url( add_query_arg( array(), $GLOBALS['wp']->request ) ) ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'zymarg_vd_seo_render_meta_tags', 5 );

/**
 * Override the document <title> on Dokan vendor store pages when the vendor
 * has set an SEO title in Section 6.
 *
 * @param string $title Existing title.
 * @return string
 */
function zymarg_vd_seo_filter_document_title( $title ) {
	if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
		return $title;
	}
	$vendor_id = (int) get_query_var( 'author' );
	if ( ! $vendor_id ) {
		$slug = get_query_var( 'author_name' );
		$user = $slug ? get_user_by( 'slug', $slug ) : null;
		if ( $user ) {
			$vendor_id = (int) $user->ID;
		}
	}
	if ( ! $vendor_id ) {
		return $title;
	}
	$custom = (string) get_user_meta( $vendor_id, '_zymarg_vd_seo_title', true );
	return '' !== $custom ? $custom : $title;
}
add_filter( 'pre_get_document_title', 'zymarg_vd_seo_filter_document_title', 20 );
