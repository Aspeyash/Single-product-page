<?php
/**
 * ZYMARG Vendor Dashboard — Buyer Email Notifications (Phase 4).
 *
 * Sends a branded email to the buyer whenever the seller sends them a reply.
 * The email is delivered via wp_mail() (respects the site's configured mailer).
 *
 * ── DESIGN SYSTEM ────────────────────────────────────────────────────────────
 * Default template uses ZYMARG brand colours:
 *   Primary    #9500A5   Secondary  #BD00D1
 *   Accent     #FEA9FF   Dark       #36003D
 *   BG         #FAF5FB   Border     #D8BFD3
 *
 * ── CUSTOM TEMPLATE HOOK ─────────────────────────────────────────────────────
 * Filter `zymarg_vd_buyer_reply_email_body` to provide an entirely custom HTML
 * body.  Your callback receives:
 *
 *   apply_filters( 'zymarg_vd_buyer_reply_email_body', $html, array(
 *       'buyer_name'   => string,
 *       'store_name'   => string,
 *       'store_url'    => string,
 *       'inbox_url'    => string,
 *       'message_body' => string,   // plain text of the seller's message
 *       'site_name'    => string,
 *       'vendor_id'    => int,
 *       'customer_id'  => int,
 *   ) );
 *
 * Return your own HTML string to replace the default template completely.
 * Useful if you want to match a custom brand or transactional email service.
 *
 * ── OPT-OUT ──────────────────────────────────────────────────────────────────
 * A buyer can opt out by visiting:
 *   {inbox_url}?zymarg_email_optout=1&nonce={nonce}
 * (The unsubscribe link in every email resolves to this URL.)
 * Their preference is stored in user_meta: _zymarg_buyer_notify_email = 0.
 * Re-subscribe is automatic the next time they log in (or they can toggle it
 * in their account, if you build that UI — the meta key is public API).
 *
 * @package ZYMARG_Vendor_Dashboard
 * @since   1.34.0
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * 1. ADMIN SETTINGS (email master toggle — lives on the Push page)
 * ====================================================================== */

/**
 * Whether buyer reply emails are enabled globally.  Default ON.
 *
 * @return bool
 */
function zymarg_vd_buyer_email_enabled() {
	return (bool) get_option( 'zymarg_vd_buyer_email_enabled', 1 );
}

/**
 * Persist the master toggle (called from the Push Notifications save handler).
 *
 * @param bool $enabled
 * @return void
 */
function zymarg_vd_buyer_email_set_enabled( $enabled ) {
	update_option( 'zymarg_vd_buyer_email_enabled', $enabled ? 1 : 0 );
}

/* ====================================================================== *
 * 2. PER-BUYER OPT-OUT HANDLING
 * ====================================================================== */

/**
 * Meta key for the buyer's own email preference.
 */
const ZYMARG_VD_BUYER_EMAIL_META = '_zymarg_buyer_notify_email';

/**
 * Whether a specific buyer has email notifications enabled.
 * Defaults to TRUE (opt-in by default — buyers must actively unsubscribe).
 *
 * @param int $buyer_id Buyer user ID.
 * @return bool
 */
function zymarg_vd_buyer_email_wants_email( $buyer_id ) {
	$meta = get_user_meta( (int) $buyer_id, ZYMARG_VD_BUYER_EMAIL_META, true );
	// Empty string = never saved = default on.
	return ( '' === $meta ) ? true : (bool) $meta;
}

/**
 * Unsubscribe a buyer (saves 0 to their meta).
 *
 * @param int $buyer_id Buyer user ID.
 * @return void
 */
function zymarg_vd_buyer_email_unsubscribe( $buyer_id ) {
	update_user_meta( (int) $buyer_id, ZYMARG_VD_BUYER_EMAIL_META, 0 );
}

/**
 * Handle the one-click unsubscribe link in emails.
 * URL pattern: ?zymarg_email_optout=1&uid={buyer_id}&nonce={nonce}
 *
 * @return void
 */
function zymarg_vd_handle_email_optout() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( empty( $_GET['zymarg_email_optout'] ) ) {
		return;
	}
	$uid   = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
	$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
	// phpcs:enable

	if ( ! $uid || ! wp_verify_nonce( $nonce, 'zymarg_buyer_email_optout_' . $uid ) ) {
		return; // Invalid or tampered link — silently do nothing.
	}

	zymarg_vd_buyer_email_unsubscribe( $uid );

	// Show a brief on-screen confirmation, then let the page render normally.
	add_action( 'wp_head', static function () {
		echo '<style>.zymarg-optout-notice{position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#36003D;color:#FEA9FF;padding:14px 28px;border-radius:12px;font-family:sans-serif;font-size:14px;font-weight:600;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.3);}' . "\n" . '</style>';
	} );
	add_action( 'wp_footer', static function () {
		echo '<div class="zymarg-optout-notice">✓ You have been unsubscribed from seller reply emails.</div>' . "\n";
	} );
}
add_action( 'template_redirect', 'zymarg_vd_handle_email_optout', 5 );

/* ====================================================================== *
 * 3. EMAIL SENDING
 * ====================================================================== */

/**
 * Build the unsubscribe URL for a buyer.
 *
 * @param int    $buyer_id  Buyer user ID.
 * @param string $inbox_url Base URL to append parameters to.
 * @return string
 */
function zymarg_vd_buyer_email_optout_url( $buyer_id, $inbox_url ) {
	return add_query_arg(
		array(
			'zymarg_email_optout' => '1',
			'uid'                 => $buyer_id,
			'nonce'               => wp_create_nonce( 'zymarg_buyer_email_optout_' . $buyer_id ),
		),
		$inbox_url ? $inbox_url : home_url( '/' )
	);
}

/**
 * Build the default branded HTML email body.
 *
 * @param array $ctx Context: buyer_name, store_name, store_url, inbox_url,
 *                             message_body, site_name, vendor_id, customer_id.
 * @return string  Full HTML string (not escaped — wp_mail handles Content-Type).
 */
function zymarg_vd_buyer_email_default_body( array $ctx ) {
	$buyer_name   = esc_html( $ctx['buyer_name'] );
	$store_name   = esc_html( $ctx['store_name'] );
	$store_url    = esc_url( $ctx['store_url'] );
	$inbox_url    = esc_url( $ctx['inbox_url'] );
	$message_body = nl2br( esc_html( $ctx['message_body'] ) );
	$site_name    = esc_html( $ctx['site_name'] );
	$optout_url   = esc_url( zymarg_vd_buyer_email_optout_url( $ctx['customer_id'], $ctx['inbox_url'] ) );

	return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New reply from ' . $store_name . '</title>
</head>
<body style="margin:0;padding:0;background:#FAF5FB;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">

  <!-- Wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#FAF5FB;padding:32px 16px;">
    <tr><td align="center">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;border:1.5px solid #D8BFD3;box-shadow:0 4px 24px rgba(149,0,165,0.08);">

        <!-- Header gradient banner -->
        <tr>
          <td style="background:linear-gradient(135deg,#9500A5 0%,#BD00D1 60%,#FEA9FF 130%);padding:32px 40px;text-align:center;">
            <div style="display:inline-block;background:rgba(255,255,255,0.18);border:2px solid rgba(255,255,255,0.35);border-radius:14px;width:52px;height:52px;line-height:52px;font-size:28px;font-weight:900;color:#fff;margin-bottom:12px;">Z</div>
            <div style="color:#fff;font-size:22px;font-weight:800;line-height:1.2;">' . $site_name . '</div>
            <div style="color:rgba(255,255,255,0.85);font-size:13px;margin-top:4px;">Message notification</div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px 28px;">

            <!-- Greeting -->
            <p style="margin:0 0 6px;font-size:18px;font-weight:700;color:#36003D;">Hi ' . $buyer_name . ' 👋</p>
            <p style="margin:0 0 24px;font-size:15px;color:#534152;line-height:1.55;">
              You have a new reply from <strong style="color:#9500A5;">' . $store_name . '</strong>.
            </p>

            <!-- Message preview box -->
            <div style="background:#FAF5FB;border-left:4px solid #9500A5;border-radius:0 12px 12px 0;padding:18px 20px;margin-bottom:28px;">
              <div style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#a08fa0;margin-bottom:8px;">Message</div>
              <div style="font-size:14px;color:#36003D;line-height:1.6;">' . $message_body . '</div>
            </div>

            <!-- CTA button -->
            <div style="text-align:center;margin-bottom:28px;">
              <a href="' . $inbox_url . '"
                 style="display:inline-block;background:linear-gradient(135deg,#9500A5,#BD00D1);color:#fff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 36px;border-radius:12px;letter-spacing:0.02em;box-shadow:0 4px 14px rgba(149,0,165,0.3);">
                View in My Messages →
              </a>
            </div>

            <!-- Store link -->
            <p style="margin:0 0 4px;text-align:center;font-size:13px;color:#7a5e79;">
              From: <a href="' . $store_url . '" style="color:#9500A5;font-weight:600;text-decoration:none;">' . $store_name . '</a>
            </p>

          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#FAF5FB;border-top:1.5px solid #EDE0ED;padding:20px 40px;text-align:center;">
            <p style="margin:0 0 6px;font-size:12px;color:#a08fa0;">
              You\'re receiving this because you have an active conversation on <strong>' . $site_name . '</strong>.
            </p>
            <p style="margin:0;font-size:12px;color:#a08fa0;">
              <a href="' . $optout_url . '" style="color:#9500A5;text-decoration:underline;">Unsubscribe from seller reply emails</a>
            </p>
          </td>
        </tr>

      </table>
      <!-- /Card -->

    </td></tr>
  </table>
  <!-- /Wrapper -->

</body>
</html>';
}

/**
 * Send a reply-notification email to the buyer.
 *
 * All three gates must pass before an email is sent:
 *   1. Global master toggle (option zymarg_vd_buyer_email_enabled).
 *   2. Per-buyer opt-in  (_zymarg_buyer_notify_email).
 *   3. Buyer must have a valid email address.
 *
 * @param int    $vendor_id   Vendor (sender) user ID.
 * @param int    $customer_id Buyer  (recipient) user ID.
 * @param string $message_body Plain-text message content.
 * @return bool  True if wp_mail() was called (not a delivery guarantee).
 */
function zymarg_vd_buyer_email_notify( $vendor_id, $customer_id, $message_body ) {
	// Gate 1 — global master switch.
	if ( ! zymarg_vd_buyer_email_enabled() ) {
		return false;
	}

	// Gate 2 — per-buyer preference.
	if ( ! zymarg_vd_buyer_email_wants_email( $customer_id ) ) {
		return false;
	}

	// Gate 3 — valid buyer account + email.
	$buyer = get_userdata( (int) $customer_id );
	if ( ! $buyer || ! is_email( $buyer->user_email ) ) {
		return false;
	}

	// Build context.
	$store_name = function_exists( 'zymarg_os_vendor_store_name' )
		? zymarg_os_vendor_store_name( $vendor_id )
		: get_userdata( $vendor_id )->display_name;

	$store_url = '';
	if ( function_exists( 'zymarg_os_vendor_store_url' ) ) {
		$store_url = zymarg_os_vendor_store_url( $vendor_id );
	} elseif ( function_exists( 'dokan_get_store_url' ) ) {
		$store_url = dokan_get_store_url( $vendor_id );
	}

	// Inbox URL: try the store-page plugin's configured option first, then
	// fall back to the page hosting [zymarg_my_messages].
	$inbox_url = '';
	$sp_opts   = get_option( 'zymarg_sp_options', array() );
	if ( ! empty( $sp_opts['inbox_url'] ) ) {
		$inbox_url = $sp_opts['inbox_url'];
	}
	if ( '' === $inbox_url ) {
		// Search for a page with the shortcode as a fallback.
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			's'              => '[zymarg_my_messages]',
		) );
		if ( ! empty( $pages ) ) {
			$inbox_url = get_permalink( $pages[0]->ID );
		}
	}
	if ( '' === $inbox_url ) {
		$inbox_url = home_url( '/my-messages/' );
	}

	$ctx = array(
		'buyer_name'   => $buyer->display_name,
		'store_name'   => $store_name,
		'store_url'    => $store_url,
		'inbox_url'    => $inbox_url,
		'message_body' => $message_body,
		'site_name'    => get_bloginfo( 'name' ),
		'vendor_id'    => (int) $vendor_id,
		'customer_id'  => (int) $customer_id,
	);

	// Build email body — filterable so custom templates can take over.
	$html = zymarg_vd_buyer_email_default_body( $ctx );

	/**
	 * Filter the buyer reply email HTML body.
	 *
	 * Return a custom HTML string to completely replace the default template.
	 *
	 * @param string $html Full HTML body.
	 * @param array  $ctx  Context array (see module docblock for keys).
	 */
	$html = apply_filters( 'zymarg_vd_buyer_reply_email_body', $html, $ctx );

	// Subject line — filterable.
	$subject = sprintf(
		/* translators: %s store name. */
		__( 'You have a new reply from %s', 'zymarg-vendor-dashboard' ),
		$store_name
	);

	/**
	 * Filter the buyer reply email subject.
	 *
	 * @param string $subject Default subject.
	 * @param array  $ctx     Context array.
	 */
	$subject = apply_filters( 'zymarg_vd_buyer_reply_email_subject', $subject, $ctx );

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
	);

	return wp_mail( $buyer->user_email, $subject, $html, $headers );
}
