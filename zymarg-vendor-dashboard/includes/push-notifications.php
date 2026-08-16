<?php
/**
 * ZYMARG Vendor Dashboard — Push Notifications (Firebase Cloud Messaging).
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS IS
 * -----------------------------------------------------------------------------
 * The full server-side plumbing for sending push notifications to a vendor's
 * mobile app (Flutter / native). Built against Google's CURRENT FCM HTTP v1
 * API (service-account + OAuth2) — the legacy "server key" endpoint was shut
 * down by Google in 2024, so this is future-proof.
 *
 * It is SAFE TO SHIP INACTIVE: with no service-account JSON configured and the
 * master toggle OFF (the default), every send is a silent no-op. Nothing fires
 * on your live site until you deliberately turn it on and paste your Firebase
 * service-account JSON.
 *
 * PIECES:
 *   1. Device registry   — vendors' app devices (multi-device), in user_meta.
 *   2. REST endpoints     — /devices/register + /devices/unregister (zymarg/v1).
 *   3. FCM v1 sender       — JWT->OAuth2->send, with dead-token cleanup + caching.
 *   4. Event bridge        — new order / order status / new message / low stock /
 *                            announcement  ->  push.
 *   5. Admin settings page — Vendor Hub -> Push Notifications (JSON, toggles, test).
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * 0. CONFIG ACCESSORS
 * ====================================================================== */

/**
 * Whether the push system is switched on. Default OFF.
 *
 * @return bool
 */
function zymarg_vd_push_enabled() {
	return (bool) get_option( 'zymarg_vd_push_enabled', 0 );
}

/**
 * Get the Firebase service-account JSON as a decoded array, or null.
 *
 * Constant-first (most secure): define ZYMARG_FCM_SERVICE_ACCOUNT in wp-config
 * as either the raw JSON string OR an absolute path to the .json file. Falls
 * back to the option saved on the admin page.
 *
 * @return array|null
 */
function zymarg_vd_push_service_account() {
	$raw = '';

	if ( defined( 'ZYMARG_FCM_SERVICE_ACCOUNT' ) && ZYMARG_FCM_SERVICE_ACCOUNT ) {
		$val = ZYMARG_FCM_SERVICE_ACCOUNT;
		// If it's a path to a readable file, load it; else treat as raw JSON.
		if ( is_string( $val ) && strlen( $val ) < 512 && is_readable( $val ) ) {
			$raw = (string) file_get_contents( $val ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$raw = (string) $val;
		}
	}

	if ( '' === $raw ) {
		$raw = (string) get_option( 'zymarg_vd_fcm_service_account', '' );
	}

	if ( '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) || empty( $data['project_id'] ) ) {
		return null;
	}
	return $data;
}

/**
 * Per-event enable map. All default ON (only matter once the master is ON).
 *
 * @return array<string,bool>
 */
function zymarg_vd_push_events() {
	$saved = get_option( 'zymarg_vd_push_events', array() );
	$defaults = array(
		'new_order'    => true,
		'order_status' => true,
		'new_message'  => true,
		'low_stock'    => true,
		'announcement' => true,
	);
	$out = array();
	foreach ( $defaults as $k => $def ) {
		$out[ $k ] = isset( $saved[ $k ] ) ? (bool) $saved[ $k ] : $def;
	}
	return $out;
}

/**
 * Whether a specific event type should fire a push.
 *
 * Three gates, in order:
 *   1. Global push on/off (Firebase configured + admin master switch).
 *   2. Admin per-event toggle (option 'zymarg_vd_push_events').
 *   3. Per-user opt-in — the Push column of Section 3 "Notification
 *      Preferences" in the vendor's Settings page
 *      (`_zymarg_vd_notification_prefs[event]['push']`, via
 *      `zymarg_vd_settings_get_notification_prefs()` in settings-hub.php).
 *      If $user_id is null we skip this gate — some events (e.g. broadcast
 *      announcements) call the helper without a target user in scope. When
 *      $user_id IS provided and the user has an explicit false for the
 *      event's push channel, the push is suppressed. A vendor who has
 *      never opened Settings gets the default (opt-in / TRUE).
 *
 * NOTE (v1.31.0): this used to read a separate, vendor-facing "Push
 * Notification Opt-in" settings card (Section 11) that wrote to its own
 * `_zymarg_vd_push_prefs` meta key. That card was removed because it
 * duplicated the Push column here and the two could silently disagree
 * (two different meta keys, two different UIs, no sync). Section 3 is now
 * the single source of truth for a vendor's push preference; any
 * previously-saved Section 11 choice is migrated into it automatically —
 * see `zymarg_vd_settings_migrate_push_optin_prefs()` in settings-hub.php.
 *
 * The event KEYS differ slightly between the two registries this bridges
 * (this module uses 'order_status'; the notification-events registry
 * uses 'order_status_changed'), so they are mapped explicitly below.
 *
 * The result is filterable via `zymarg_vd_push_user_opt_in` so third-party
 * code can layer additional preferences (quiet hours, do-not-disturb, ...).
 *
 * @param string   $event    Event key (e.g. 'new_order', 'announcement').
 * @param int|null $user_id  Optional target user ID for the per-user gate.
 * @return bool
 */
function zymarg_vd_push_event_on( $event, $user_id = null ) {
	if ( ! zymarg_vd_push_enabled() ) {
		return false;
	}
	$events = zymarg_vd_push_events();
	$is_on  = ! empty( $events[ $event ] );

	if ( $is_on && null !== $user_id && (int) $user_id > 0 ) {
		// This module's event keys ('order_status') vs. the Settings
		// notification-events registry's keys ('order_status_changed').
		$notif_key_map = array(
			'new_order'    => 'new_order',
			'order_status' => 'order_status_changed',
			'new_message'  => 'new_message',
			'low_stock'    => 'low_stock',
			'announcement' => 'announcement',
		);
		$notif_key = isset( $notif_key_map[ $event ] ) ? $notif_key_map[ $event ] : $event;

		if ( function_exists( 'zymarg_vd_settings_get_notification_prefs' ) ) {
			$prefs = zymarg_vd_settings_get_notification_prefs( (int) $user_id );
			if ( isset( $prefs[ $notif_key ]['push'] ) ) {
				$is_on = (bool) $prefs[ $notif_key ]['push'];
			}
			// Unknown key in the registry => default true (opt-in by default).
		} else {
			// Settings module not loaded for some reason — fail open (opted-in),
			// matching the previous default behaviour.
			$is_on = $is_on;
		}
	}

	/**
	 * Filter whether a push should fire for a given event / user.
	 *
	 * @param bool     $is_on   Current decision.
	 * @param int|null $user_id Target user (may be null).
	 * @param string   $event   Event key.
	 */
	return (bool) apply_filters( 'zymarg_vd_push_user_opt_in', $is_on, $user_id, $event );
}

/* ====================================================================== *
 * 1. DEVICE REGISTRY (per user, multi-device, in user_meta)
 * ====================================================================== */

/**
 * Meta key holding a user's registered devices.
 */
const ZYMARG_VD_DEVICES_META = '_zymarg_vd_devices';

/**
 * Get a user's registered devices.
 *
 * @param int $user_id User ID.
 * @return array<string,array> Keyed by token.
 */
function zymarg_vd_get_devices( $user_id ) {
	$devices = get_user_meta( (int) $user_id, ZYMARG_VD_DEVICES_META, true );
	return is_array( $devices ) ? $devices : array();
}

/**
 * Register / refresh a device token for a user.
 *
 * @param int    $user_id  User ID.
 * @param string $token    FCM registration token.
 * @param string $platform 'android' | 'ios' | 'web'.
 * @param string $app_ver  Optional app version.
 * @return void
 */
function zymarg_vd_register_device( $user_id, $token, $platform = '', $app_ver = '' ) {
	$user_id = (int) $user_id;
	$token   = trim( (string) $token );
	if ( ! $user_id || '' === $token ) {
		return;
	}
	$devices           = zymarg_vd_get_devices( $user_id );
	$devices[ $token ] = array(
		'token'       => $token,
		'platform'    => sanitize_key( $platform ),
		'app_version' => sanitize_text_field( $app_ver ),
		'registered'  => isset( $devices[ $token ]['registered'] ) ? $devices[ $token ]['registered'] : gmdate( 'c' ),
		'last_seen'   => gmdate( 'c' ),
	);
	// Cap stored devices (drop oldest) to avoid unbounded growth.
	if ( count( $devices ) > 10 ) {
		uasort(
			$devices,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['last_seen'] ?? '' ), (string) ( $b['last_seen'] ?? '' ) );
			}
		);
		$devices = array_slice( $devices, -10, null, true );
	}
	update_user_meta( $user_id, ZYMARG_VD_DEVICES_META, $devices );
}

/**
 * Remove a device token from a user (logout, or dead token cleanup).
 *
 * @param int    $user_id User ID.
 * @param string $token   FCM token.
 * @return void
 */
function zymarg_vd_unregister_device( $user_id, $token ) {
	$user_id = (int) $user_id;
	$token   = trim( (string) $token );
	$devices = zymarg_vd_get_devices( $user_id );
	if ( isset( $devices[ $token ] ) ) {
		unset( $devices[ $token ] );
		update_user_meta( $user_id, ZYMARG_VD_DEVICES_META, $devices );
	}
}

/* ====================================================================== *
 * 2. REST ENDPOINTS (device register / unregister) — in zymarg/v1
 * ====================================================================== */

/**
 * Register the device endpoints. Uses the same auth as the rest of zymarg/v1.
 *
 * @return void
 */
function zymarg_vd_push_register_routes() {
	$ns   = defined( 'ZYMARG_VD_API_NS' ) ? ZYMARG_VD_API_NS : 'zymarg/v1';
	$auth = function_exists( 'zymarg_vd_rest_permission' ) ? 'zymarg_vd_rest_permission' : '__return_true';

	register_rest_route( $ns, '/devices/register', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'zymarg_vd_rest_device_register',
		'permission_callback' => $auth,
		'args'                => array(
			'token'    => array( 'type' => 'string', 'required' => true ),
			'platform' => array( 'type' => 'string', 'required' => false ),
		),
	) );

	register_rest_route( $ns, '/devices/unregister', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'zymarg_vd_rest_device_unregister',
		'permission_callback' => $auth,
		'args'                => array(
			'token' => array( 'type' => 'string', 'required' => true ),
		),
	) );
}
add_action( 'rest_api_init', 'zymarg_vd_push_register_routes' );

/**
 * POST /devices/register — the app sends its FCM token here after login.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function zymarg_vd_rest_device_register( $req ) {
	$token = (string) $req->get_param( 'token' );
	if ( '' === trim( $token ) ) {
		return new WP_Error( 'zymarg_bad_request', __( 'A device token is required.', 'zymarg-vendor-dashboard' ), array( 'status' => 400 ) );
	}
	$user_id = get_current_user_id();
	zymarg_vd_register_device(
		$user_id,
		$token,
		(string) $req->get_param( 'platform' ),
		(string) $req->get_param( 'app_version' )
	);
	$payload = array( 'registered' => true, 'device_count' => count( zymarg_vd_get_devices( $user_id ) ) );
	return function_exists( 'zymarg_vd_rest_ok' ) ? zymarg_vd_rest_ok( $payload, array(), 201 ) : new WP_REST_Response( array( 'data' => $payload ), 201 );
}

/**
 * POST /devices/unregister — the app calls this on logout.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function zymarg_vd_rest_device_unregister( $req ) {
	zymarg_vd_unregister_device( get_current_user_id(), (string) $req->get_param( 'token' ) );
	$payload = array( 'unregistered' => true );
	return function_exists( 'zymarg_vd_rest_ok' ) ? zymarg_vd_rest_ok( $payload ) : new WP_REST_Response( array( 'data' => $payload ), 200 );
}

/* ====================================================================== *
 * 3. FCM HTTP v1 SENDER (JWT -> OAuth2 access token -> send)
 * ====================================================================== */

/**
 * Obtain (and cache) a Google OAuth2 access token for FCM, minted from the
 * service account's private key via a signed JWT (RS256).
 *
 * @param array $sa Service account array.
 * @return string|WP_Error Access token or error.
 */
function zymarg_vd_push_access_token( $sa ) {
	$cache_key = 'zymarg_vd_fcm_token_' . md5( $sa['client_email'] );
	$cached    = get_transient( $cache_key );
	if ( $cached ) {
		return $cached;
	}

	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'zymarg_no_openssl', __( 'PHP OpenSSL is required to sign the Firebase token.', 'zymarg-vendor-dashboard' ) );
	}

	$now       = time();
	$token_uri = ! empty( $sa['token_uri'] ) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token';

	$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
	$claim  = array(
		'iss'   => $sa['client_email'],
		'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
		'aud'   => $token_uri,
		'iat'   => $now,
		'exp'   => $now + 3600,
	);

	$segments  = array(
		zymarg_vd_b64url( wp_json_encode( $header ) ),
		zymarg_vd_b64url( wp_json_encode( $claim ) ),
	);
	$signing_input = implode( '.', $segments );

	$signature = '';
	$ok        = openssl_sign( $signing_input, $signature, $sa['private_key'], 'sha256WithRSAEncryption' );
	if ( ! $ok ) {
		return new WP_Error( 'zymarg_sign_failed', __( 'Could not sign the Firebase auth token (check the private key).', 'zymarg-vendor-dashboard' ) );
	}
	$jwt = $signing_input . '.' . zymarg_vd_b64url( $signature );

	$resp = wp_remote_post(
		$token_uri,
		array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( empty( $body['access_token'] ) ) {
		return new WP_Error( 'zymarg_token_failed', __( 'Firebase rejected the service account.', 'zymarg-vendor-dashboard' ), $body );
	}

	$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 300 ) : 3300;
	set_transient( $cache_key, $body['access_token'], $ttl );
	return $body['access_token'];
}

/**
 * URL-safe base64 (JWT flavour).
 *
 * @param string $data Raw data.
 * @return string
 */
function zymarg_vd_b64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Send a push to every device of a given user.
 *
 * @param int    $user_id User to notify.
 * @param string $title   Notification title.
 * @param string $body    Notification body.
 * @param array  $data    Optional data map (values coerced to strings).
 * @return array|WP_Error  Result summary, or WP_Error if not configured.
 */
function zymarg_vd_push_send( $user_id, $title, $body, $data = array() ) {
	if ( ! zymarg_vd_push_enabled() ) {
		return new WP_Error( 'zymarg_push_off', 'push disabled' );
	}
	$sa = zymarg_vd_push_service_account();
	if ( ! $sa ) {
		return new WP_Error( 'zymarg_push_unconfigured', 'no service account' );
	}
	$devices = zymarg_vd_get_devices( $user_id );
	if ( empty( $devices ) ) {
		return array( 'sent' => 0, 'devices' => 0 );
	}

	$token = zymarg_vd_push_access_token( $sa );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	// FCM v1 requires all data values to be strings.
	$data_str = array();
	foreach ( (array) $data as $k => $v ) {
		$data_str[ (string) $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
	}

	$url  = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send';
	$sent = 0;

	foreach ( array_keys( $devices ) as $device_token ) {
		$message = array(
			'message' => array(
				'token'        => $device_token,
				'notification' => array(
					'title' => (string) $title,
					'body'  => (string) $body,
				),
				'data'         => $data_str,
				'android'      => array( 'priority' => 'high' ),
				'apns'         => array( 'headers' => array( 'apns-priority' => '10' ) ),
			),
		);

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $message ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 === $code ) {
			$sent++;
		} elseif ( in_array( $code, array( 404, 400 ), true ) ) {
			// UNREGISTERED / invalid token — prune it so we stop trying.
			$rbody = json_decode( wp_remote_retrieve_body( $resp ), true );
			$status = isset( $rbody['error']['details'][0]['errorCode'] ) ? $rbody['error']['details'][0]['errorCode'] : '';
			if ( 'UNREGISTERED' === $status || 'INVALID_ARGUMENT' === $status || 404 === $code ) {
				zymarg_vd_unregister_device( $user_id, $device_token );
			}
		}
	}

	return array( 'sent' => $sent, 'devices' => count( $devices ) );
}

/* ====================================================================== *
 * 4. EVENT BRIDGE (WordPress events -> push)
 * ====================================================================== */

/**
 * Notify the vendor(s) who own products in an order.
 *
 * @param int    $order_id Order ID.
 * @param string $title    Title.
 * @param string $body_tpl Body template (%s replaced with order number).
 * @param string $type     Data 'type' for app routing.
 * @return void
 */
function zymarg_vd_push_notify_order_vendors( $order_id, $title, $body, $type ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( (int) $order_id );
	if ( ! ( $order instanceof WC_Order ) ) {
		return;
	}
	$vendors = array();
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( $pid ) {
			$author = (int) get_post_field( 'post_author', $pid );
			if ( $author ) {
				$vendors[ $author ] = true;
			}
		}
	}
	// Map the coarse $type back to the fine-grained event key used by
	// zymarg_vd_push_event_on() so each vendor's per-user opt-in preference
	// can be honoured before we spend a Firebase call on them.
	$event_key = ( 'new_order' === $type ) ? 'new_order' : ( ( 'order_status' === $type ) ? 'order_status' : $type );
	foreach ( array_keys( $vendors ) as $vendor_id ) {
		if ( ! zymarg_vd_push_event_on( $event_key, $vendor_id ) ) {
			continue;
		}
		zymarg_vd_push_send(
			$vendor_id,
			$title,
			$body,
			array( 'type' => $type, 'order_id' => (string) $order_id, 'screen' => 'orders' )
		);
	}
}

/**
 * New order -> push to the order's vendors.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function zymarg_vd_push_on_new_order( $order_id ) {
	if ( ! zymarg_vd_push_event_on( 'new_order' ) ) {
		return;
	}
	$number = $order_id;
	if ( function_exists( 'wc_get_order' ) ) {
		$o = wc_get_order( $order_id );
		if ( $o ) {
			$number = $o->get_order_number();
		}
	}
	zymarg_vd_push_notify_order_vendors(
		$order_id,
		__( 'New order received', 'zymarg-vendor-dashboard' ),
		sprintf( /* translators: %s order number. */ __( 'You have a new order #%s.', 'zymarg-vendor-dashboard' ), $number ),
		'new_order'
	);
}
add_action( 'woocommerce_new_order', 'zymarg_vd_push_on_new_order', 20, 1 );

/**
 * Order status changed -> push to the order's vendors.
 *
 * @param int    $order_id Order ID.
 * @param string $old      Old status.
 * @param string $new      New status.
 * @return void
 */
function zymarg_vd_push_on_status( $order_id, $old, $new ) {
	if ( ! zymarg_vd_push_event_on( 'order_status' ) ) {
		return;
	}
	$label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $new ) : $new;
	zymarg_vd_push_notify_order_vendors(
		$order_id,
		__( 'Order updated', 'zymarg-vendor-dashboard' ),
		sprintf( /* translators: 1 order id 2 status. */ __( 'Order #%1$s is now %2$s.', 'zymarg-vendor-dashboard' ), $order_id, $label ),
		'order_status'
	);
}
add_action( 'woocommerce_order_status_changed', 'zymarg_vd_push_on_status', 20, 3 );

/**
 * New buyer message -> push to the vendor.
 *
 * Fires when a zymarg_message is inserted whose author is NOT the vendor
 * (i.e. the customer sent it).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 * @param bool    $update  Whether this is an update.
 * @return void
 */
function zymarg_vd_push_on_message( $post_id, $post, $update ) {
	if ( $update || 'zymarg_message' !== $post->post_type ) {
		return;
	}
	$vendor_id   = (int) get_post_meta( $post_id, '_zymarg_vendor', true );
	$customer_id = (int) get_post_meta( $post_id, '_zymarg_customer', true );
	// Only notify the vendor when the CUSTOMER is the sender.
	if ( ! $vendor_id || (int) $post->post_author === $vendor_id ) {
		return;
	}
	if ( ! zymarg_vd_push_event_on( 'new_message', $vendor_id ) ) {
		return;
	}
	$cust = get_userdata( $customer_id );
	$name = $cust ? $cust->display_name : __( 'a customer', 'zymarg-vendor-dashboard' );
	zymarg_vd_push_send(
		$vendor_id,
		__( 'New message', 'zymarg-vendor-dashboard' ),
		sprintf( /* translators: %s customer name. */ __( 'New message from %s.', 'zymarg-vendor-dashboard' ), $name ),
		array( 'type' => 'new_message', 'customer_id' => (string) $customer_id, 'screen' => 'messages' )
	);
}
add_action( 'wp_insert_post', 'zymarg_vd_push_on_message', 20, 3 );

/**
 * Phase 5 — Vendor reply -> push to the buyer.
 *
 * Fires on every new zymarg_message post. Mirrors zymarg_vd_push_on_message()
 * but in the opposite direction: when the VENDOR is the post_author we push the
 * CUSTOMER (buyer) so they know the seller replied.
 *
 * Gates:
 *   1. Global push must be ON.
 *   2. 'new_message' event must be ON (same admin toggle as the vendor side).
 *   3. The buyer must have at least one registered device.
 *
 * Note: there is currently no per-buyer push preference UI (Phase 8 adds
 * unread dots; a buyer preference setting is a future phase). We use
 * zymarg_vd_push_event_on('new_message') without a user_id so we skip the
 * per-vendor prefs gate — the admin 'new_message' toggle acts as the master.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update (we only care about inserts).
 * @return void
 */
function zymarg_vd_push_on_vendor_reply( $post_id, $post, $update ) {
	if ( $update || 'zymarg_message' !== $post->post_type ) {
		return;
	}

	$vendor_id   = (int) get_post_meta( $post_id, '_zymarg_vendor', true );
	$customer_id = (int) get_post_meta( $post_id, '_zymarg_customer', true );

	// Only fire when the VENDOR is the sender (post_author === vendor).
	if ( ! $vendor_id || (int) $post->post_author !== $vendor_id ) {
		return;
	}

	if ( ! $customer_id ) {
		return;
	}

	// Respect the admin 'new_message' toggle (no per-user gate for buyers yet).
	if ( ! zymarg_vd_push_event_on( 'new_message' ) ) {
		return;
	}

	$store_name = function_exists( 'zymarg_os_vendor_store_name' )
		? zymarg_os_vendor_store_name( $vendor_id )
		: __( 'the seller', 'zymarg-vendor-dashboard' );

	zymarg_vd_push_send(
		$customer_id,
		/* translators: %s store/seller name. */
		sprintf( __( 'New reply from %s', 'zymarg-vendor-dashboard' ), $store_name ),
		wp_trim_words( $post->post_content, 15, '…' ),
		array(
			'type'        => 'vendor_reply',
			'vendor_id'   => (string) $vendor_id,
			'customer_id' => (string) $customer_id,
			'screen'      => 'messages',
		)
	);
}
add_action( 'wp_insert_post', 'zymarg_vd_push_on_vendor_reply', 20, 3 );

/**
 * Low stock -> push to the product's vendor.
 *
 * @param WC_Product $product Product.
 * @return void
 */
function zymarg_vd_push_on_low_stock( $product ) {
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}
	$pid       = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$vendor_id = (int) get_post_field( 'post_author', $pid );
	if ( ! $vendor_id ) {
		return;
	}
	if ( ! zymarg_vd_push_event_on( 'low_stock', $vendor_id ) ) {
		return;
	}
	zymarg_vd_push_send(
		$vendor_id,
		__( 'Low stock alert', 'zymarg-vendor-dashboard' ),
		sprintf( /* translators: %s product name. */ __( '%s is running low on stock.', 'zymarg-vendor-dashboard' ), $product->get_name() ),
		array( 'type' => 'low_stock', 'product_id' => (string) $pid, 'screen' => 'products' )
	);
}
add_action( 'woocommerce_low_stock', 'zymarg_vd_push_on_low_stock', 20, 1 );

/**
 * Public helper other modules can call to push an announcement to a vendor.
 *
 * @param int    $vendor_id Vendor.
 * @param string $title     Title.
 * @param string $body      Body.
 * @return void
 */
function zymarg_vd_push_announcement( $vendor_id, $title, $body ) {
	if ( ! zymarg_vd_push_event_on( 'announcement', $vendor_id ) ) {
		return;
	}
	zymarg_vd_push_send(
		$vendor_id,
		$title ? $title : __( 'New announcement', 'zymarg-vendor-dashboard' ),
		$body,
		array( 'type' => 'announcement', 'screen' => 'notifications' )
	);
}

/* ====================================================================== *
 * 5. ADMIN SETTINGS PAGE (Vendor Hub -> Push Notifications)
 * ====================================================================== */

/**
 * Register the settings submenu.
 *
 * @return void
 */
function zymarg_vd_push_menu() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'Push Notifications', 'zymarg-vendor-dashboard' ),
		__( 'Push Notifications', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vd-push',
		'zymarg_vd_push_render_settings'
	);
}
add_action( 'admin_menu', 'zymarg_vd_push_menu' );

/**
 * Handle the settings form submit + the "send test" action.
 *
 * @return void
 */
function zymarg_vd_push_handle_save() {
	if ( ! isset( $_POST['zymarg_vd_push_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['zymarg_vd_push_nonce'] ) ), 'zymarg_vd_push_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	update_option( 'zymarg_vd_push_enabled', empty( $_POST['push_enabled'] ) ? 0 : 1 );

	$events = array();
	foreach ( array( 'new_order', 'order_status', 'new_message', 'low_stock', 'announcement' ) as $k ) {
		$events[ $k ] = empty( $_POST[ 'evt_' . $k ] ) ? 0 : 1;
	}
	update_option( 'zymarg_vd_push_events', $events );

	// Only overwrite the JSON if a new one was pasted, and never when locked by constant.
	if ( ! defined( 'ZYMARG_FCM_SERVICE_ACCOUNT' ) && isset( $_POST['fcm_json'] ) ) {
		$json = trim( (string) wp_unslash( $_POST['fcm_json'] ) ); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotValidated
		if ( '' !== $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) && ! empty( $decoded['private_key'] ) ) {
				update_option( 'zymarg_vd_fcm_service_account', wp_json_encode( $decoded ) );
			}
		}
	}

	// Phase 4 — buyer reply email master toggle.
	if ( function_exists( 'zymarg_vd_buyer_email_set_enabled' ) ) {
		zymarg_vd_buyer_email_set_enabled( ! empty( $_POST['buyer_email_enabled'] ) );
	}

	add_settings_error( 'zymarg_vd_push', 'saved', __( 'Notification settings saved.', 'zymarg-vendor-dashboard' ), 'updated' );
}
add_action( 'admin_init', 'zymarg_vd_push_handle_save' );

/**
 * AJAX: send a test push to the current admin's own registered devices.
 *
 * @return void
 */
function zymarg_vd_push_test_ajax() {
	check_ajax_referer( 'zymarg_vd_push_test', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$result = zymarg_vd_push_send(
		get_current_user_id(),
		__( 'ZYMARG test push', 'zymarg-vendor-dashboard' ),
		__( 'If you can read this, push notifications are working.', 'zymarg-vendor-dashboard' ),
		array( 'type' => 'test' )
	);
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() . ' (' . $result->get_error_code() . ')' ) );
	}
	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: 1 sent 2 devices. */
			__( 'Sent to %1$d of %2$d of your registered devices. If you have 0 devices, register one from the app first.', 'zymarg-vendor-dashboard' ),
			(int) $result['sent'],
			(int) $result['devices']
		),
	) );
}
add_action( 'wp_ajax_zymarg_vd_push_test', 'zymarg_vd_push_test_ajax' );

/**
 * Render the Push Notifications admin page.
 *
 * @return void
 */
function zymarg_vd_push_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Join the Vendor Hub AJAX shell. Without this wrapper the router has
	// nothing to swap into and this screen forces a full browser navigation.
	if ( function_exists( 'zymarg_vd_admin_shell_open' ) ) {
		zymarg_vd_admin_shell_open();
	}

	settings_errors( 'zymarg_vd_push' );

	$enabled       = zymarg_vd_push_enabled();
	$events        = zymarg_vd_push_events();
	$sa            = zymarg_vd_push_service_account();
	$locked        = defined( 'ZYMARG_FCM_SERVICE_ACCOUNT' );
	$configured    = (bool) $sa;
	$project       = $sa ? $sa['project_id'] : '';
	$email_enabled = function_exists( 'zymarg_vd_buyer_email_enabled' ) ? zymarg_vd_buyer_email_enabled() : true;
	?>
	<div class="wrap">
		<?php
		if ( function_exists( 'zymarg_vd_admin_back_link' ) ) {
			zymarg_vd_admin_back_link();
		}
		if ( function_exists( 'zymarg_vd_admin_header' ) ) {
			zymarg_vd_admin_header(
				__( 'Push Notifications', 'zymarg-vendor-dashboard' ),
				__( 'Firebase Cloud Messaging (HTTP v1) for vendor mobile apps.', 'zymarg-vendor-dashboard' )
			);
		}
		?>
		<p><?php esc_html_e( 'Send push notifications to vendors\' mobile apps via Firebase Cloud Messaging (HTTP v1). Safe to leave off until you paste your Firebase service-account JSON below.', 'zymarg-vendor-dashboard' ); ?></p>

		<?php // State is never signalled by colour alone: the label carries the word. ?>
		<div class="zvd-notice <?php echo $configured ? 'zvd-notice--success' : 'zvd-notice--error'; ?>">
			<strong class="zvd-notice__label"><?php esc_html_e( 'Status:', 'zymarg-vendor-dashboard' ); ?></strong>
			<?php if ( $configured ) : ?>
				<?php echo esc_html( sprintf( /* translators: %s project. */ __( 'Firebase connected (project: %s).', 'zymarg-vendor-dashboard' ), $project ) ); ?>
				<?php echo $enabled ? esc_html__( 'Push is ON.', 'zymarg-vendor-dashboard' ) : esc_html__( 'Push is OFF — turn it on below.', 'zymarg-vendor-dashboard' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Not configured yet. Paste your Firebase service-account JSON below to activate.', 'zymarg-vendor-dashboard' ); ?>
			<?php endif; ?>
		</div>

		<form method="post" class="zvd-narrow">
			<?php wp_nonce_field( 'zymarg_vd_push_save', 'zymarg_vd_push_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable push', 'zymarg-vendor-dashboard' ); ?></th>
					<td><label><input type="checkbox" name="push_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Master switch — send push notifications', 'zymarg-vendor-dashboard' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Events', 'zymarg-vendor-dashboard' ); ?></th>
					<td>
						<label class="zvd-checklist__item"><input type="checkbox" name="evt_new_order" value="1" <?php checked( $events['new_order'] ); ?>> <?php esc_html_e( 'New order', 'zymarg-vendor-dashboard' ); ?></label>
						<label class="zvd-checklist__item"><input type="checkbox" name="evt_order_status" value="1" <?php checked( $events['order_status'] ); ?>> <?php esc_html_e( 'Order status changed', 'zymarg-vendor-dashboard' ); ?></label>
						<label class="zvd-checklist__item"><input type="checkbox" name="evt_new_message" value="1" <?php checked( $events['new_message'] ); ?>> <?php esc_html_e( 'New buyer message', 'zymarg-vendor-dashboard' ); ?></label>
						<label class="zvd-checklist__item"><input type="checkbox" name="evt_low_stock" value="1" <?php checked( $events['low_stock'] ); ?>> <?php esc_html_e( 'Low stock', 'zymarg-vendor-dashboard' ); ?></label>
						<label class="zvd-checklist__item"><input type="checkbox" name="evt_announcement" value="1" <?php checked( $events['announcement'] ); ?>> <?php esc_html_e( 'Announcements', 'zymarg-vendor-dashboard' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Firebase service-account JSON', 'zymarg-vendor-dashboard' ); ?></th>
					<td>
						<?php if ( $locked ) : ?>
							<p class="zvd-locked-note"><?php esc_html_e( 'Locked — defined in wp-config.php via ZYMARG_FCM_SERVICE_ACCOUNT (most secure). Edit it there.', 'zymarg-vendor-dashboard' ); ?></p>
						<?php else : ?>
							<textarea name="fcm_json" rows="8" class="zvd-textarea-code" placeholder='<?php echo $configured ? esc_attr__( 'A service account is saved. Paste a new one here only to replace it.', 'zymarg-vendor-dashboard' ) : '{ "type": "service_account", "project_id": "...", "private_key": "-----BEGIN PRIVATE KEY-----...", "client_email": "...@....iam.gserviceaccount.com" }'; ?>'></textarea>
							<p class="description"><?php esc_html_e( 'Firebase Console -> Project Settings -> Service Accounts -> Generate new private key. Paste the whole JSON. (For best security, put it in wp-config.php as ZYMARG_FCM_SERVICE_ACCOUNT instead.)', 'zymarg-vendor-dashboard' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save notification settings', 'zymarg-vendor-dashboard' ) ); ?>
		</form>

		<hr class="zvd-rule">

		<!-- ── Phase 4: Buyer Reply Email Notifications ── -->
		<div class="zvd-panel">
			<div class="zvd-panel__head">
				<div class="zvd-panel__icon">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				</div>
				<h2 class="zvd-panel__title"><?php esc_html_e( 'Buyer Reply Emails (Phase 4)', 'zymarg-vendor-dashboard' ); ?></h2>
			</div>
			<div class="zvd-panel__body">
				<p class="zvd-body-text">
					<?php esc_html_e( 'When a seller sends a message to a buyer, the buyer receives a branded email notification with a preview of the message and a link to their inbox. Buyers can unsubscribe individually via the link in each email.', 'zymarg-vendor-dashboard' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'zymarg_vd_push_save', 'zymarg_vd_push_nonce' ); ?>
					<label class="zvd-check-row">
						<input type="hidden"   name="buyer_email_enabled" value="0">
						<input type="checkbox" name="buyer_email_enabled" value="1" <?php checked( $email_enabled ); ?>>
						<?php esc_html_e( 'Enable buyer reply email notifications', 'zymarg-vendor-dashboard' ); ?>
					</label>
					<p class="zvd-hint">
						<?php esc_html_e( 'Default: ON. Disable to stop all buyer reply emails site-wide (individual opt-outs still apply).', 'zymarg-vendor-dashboard' ); ?>
					</p>

					<div class="zvd-callout">
						<strong><?php esc_html_e( 'Custom template:', 'zymarg-vendor-dashboard' ); ?></strong>
						<?php esc_html_e( 'Add the filter', 'zymarg-vendor-dashboard' ); ?>
						<code class="zvd-inline-code">zymarg_vd_buyer_reply_email_body</code>
						<?php esc_html_e( 'in your theme\'s functions.php to replace the default branded template with your own HTML. The filter receives the default HTML and a context array (buyer_name, store_name, inbox_url, message_body, etc.).', 'zymarg-vendor-dashboard' ); ?>
					</div>

					<?php submit_button( __( 'Save email settings', 'zymarg-vendor-dashboard' ), 'secondary', 'submit_email', false ); ?>
				</form>
			</div>
		</div>

		<hr class="zvd-rule">

		<div class="zvd-narrow">
			<h2 class="zvd-section-heading"><?php esc_html_e( 'Send a test push', 'zymarg-vendor-dashboard' ); ?></h2>
			<p class="zvd-body-text"><?php esc_html_e( 'Sends a test notification to YOUR own registered devices. Register a device from the app first (log in as yourself in the app).', 'zymarg-vendor-dashboard' ); ?></p>
			<button type="button" class="button button-secondary" id="zymarg-vd-push-test"><?php esc_html_e( 'Send test to my devices', 'zymarg-vendor-dashboard' ); ?></button>
			<span id="zymarg-vd-push-test-msg" class="zvd-status-msg" role="status" aria-live="polite"></span>
		</div>

		<script>
		( function () {
			var btn = document.getElementById( 'zymarg-vd-push-test' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var msg = document.getElementById( 'zymarg-vd-push-test-msg' );
				btn.disabled = true; msg.textContent = '<?php echo esc_js( __( 'Sending…', 'zymarg-vendor-dashboard' ) ); ?>';
				var fd = new FormData();
				fd.append( 'action', 'zymarg_vd_push_test' );
				fd.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'zymarg_vd_push_test' ) ); ?>' );
				fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						btn.disabled = false;
						msg.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Done.';
						// Token-driven state class, never an inline colour.
						msg.className = 'zvd-status-msg ' + ( ( res && res.success ) ? 'zvd-status-msg--ok' : 'zvd-status-msg--err' );
					} )
					.catch( function () { btn.disabled = false; msg.textContent = 'Error'; msg.className = 'zvd-status-msg zvd-status-msg--err'; } );
			} );
		}() );
		</script>
	</div>
	<?php

	if ( function_exists( 'zymarg_vd_admin_shell_close' ) ) {
		zymarg_vd_admin_shell_close();
	}
}
