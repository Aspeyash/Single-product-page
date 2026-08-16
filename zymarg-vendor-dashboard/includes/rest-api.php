<?php
/**
 * ZYMARG Vendor Dashboard — Public JSON REST API (app contract).
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS IS
 * -----------------------------------------------------------------------------
 * A stable, client-agnostic, backend-agnostic JSON API for building a mobile /
 * desktop app (Flutter, React Native, native, or a second website) on top of
 * the ZYMARG vendor dashboard.
 *
 * Namespace:  zymarg/v1   →  https://your-site.com/wp-json/zymarg/v1/...
 *
 * DESIGN PRINCIPLES (why it's "universal"):
 *   1. CONTRACT, not implementation. Every response has a fixed SHAPE wrapped in
 *      a consistent envelope: { data: {...}, meta: {...} }. The client codes
 *      against the shape, never against WordPress internals.
 *   2. BACKEND-AGNOSTIC. meta.source + meta.api_version let the same app talk to
 *      a different backend later (e.g. a VPS/headless service) as long as that
 *      backend returns the SAME shape. Swap the server, keep the contract, the
 *      app never knows.
 *   3. DON'T REINVENT. Products, coupons, order-writes and withdrawals already
 *      have first-class REST APIs (WooCommerce /wc/v3 and Dokan /dokan/v1). This
 *      layer only adds the vendor-scoped AGGREGATIONS those APIs don't provide
 *      (dashboard KPIs, earnings series, analytics, notifications, messages).
 *
 * AUTH: WordPress Application Passwords (Basic auth) — built into WP 5.6+, no
 * plugin needed. Same-origin web clients may also use cookie auth + the
 * X-WP-Nonce header. See the D-Instruction page for the full developer guide.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/** The public API namespace/version. Bump the version if the SHAPE changes. */
define( 'ZYMARG_VD_API_NS', 'zymarg/v1' );
define( 'ZYMARG_VD_API_VERSION', '1.0' );

/* ====================================================================== *
 * Registration
 * ====================================================================== */

/**
 * Register every public app endpoint.
 *
 * @return void
 */
function zymarg_vd_rest_register() {
	$ns   = ZYMARG_VD_API_NS;
	$auth = 'zymarg_vd_rest_permission';

	register_rest_route( $ns, '/me', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_me',
		'permission_callback' => $auth,
	) );

	register_rest_route( $ns, '/dashboard', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_dashboard',
		'permission_callback' => $auth,
	) );

	register_rest_route( $ns, '/orders', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_orders',
		'permission_callback' => $auth,
		'args'                => array(
			'status' => array( 'type' => 'string', 'required' => false ),
		),
	) );

	register_rest_route( $ns, '/earnings', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_earnings',
		'permission_callback' => $auth,
	) );

	register_rest_route( $ns, '/analytics', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_analytics',
		'permission_callback' => $auth,
	) );

	register_rest_route( $ns, '/notifications', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_notifications',
		'permission_callback' => $auth,
	) );

	// Messages: thread list, single thread, and send.
	register_rest_route( $ns, '/messages', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'zymarg_vd_rest_messages_list',
			'permission_callback' => $auth,
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'zymarg_vd_rest_messages_send',
			'permission_callback' => $auth,
			'args'                => array(
				'customer_id' => array( 'type' => 'integer', 'required' => true ),
				'body'        => array( 'type' => 'string', 'required' => true ),
			),
		),
	) );

	register_rest_route( $ns, '/messages/(?P<customer_id>\d+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'zymarg_vd_rest_messages_thread',
		'permission_callback' => $auth,
		'args'                => array(
			'customer_id' => array( 'type' => 'integer', 'required' => true ),
		),
	) );
}
add_action( 'rest_api_init', 'zymarg_vd_rest_register' );

/* ====================================================================== *
 * Shared helpers
 * ====================================================================== */

/**
 * Permission gate for every app endpoint: a logged-in user who may view the
 * vendor dashboard (a real vendor, a granted staff member, or an admin).
 *
 * Works with Application Passwords (Basic auth) and same-origin cookie auth.
 *
 * @return bool|WP_Error
 */
function zymarg_vd_rest_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'zymarg_unauthorized', __( 'Authentication required.', 'zymarg-vendor-dashboard' ), array( 'status' => 401 ) );
	}
	if ( ! function_exists( 'zymarg_os_can_view_vendor_dashboard' ) || ! zymarg_os_can_view_vendor_dashboard() ) {
		return new WP_Error( 'zymarg_forbidden', __( 'You do not have a vendor account.', 'zymarg-vendor-dashboard' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * The effective vendor ID for the authenticated request.
 *
 * Staff members act on their parent vendor's data; everyone else acts on their
 * own ID. Mirrors the web dashboard's vendor-resolution logic.
 *
 * @return int
 */
function zymarg_vd_rest_vendor_id() {
	$uid = get_current_user_id();
	if ( function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $uid ) && function_exists( 'zymarg_vd_staff_vendor_id' ) ) {
		$vendor = (int) zymarg_vd_staff_vendor_id( $uid );
		if ( $vendor > 0 ) {
			return $vendor;
		}
	}
	return (int) $uid;
}

/**
 * Whether the effective user is a real vendor (vs an admin previewing).
 *
 * @param int $vendor_id Effective vendor ID.
 * @return bool
 */
function zymarg_vd_rest_is_vendor( $vendor_id ) {
	if ( function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( get_current_user_id() ) ) {
		return true; // staff always operate in a real vendor's scope
	}
	return function_exists( 'zymarg_os_user_is_vendor' ) ? (bool) zymarg_os_user_is_vendor( $vendor_id ) : false;
}

/**
 * Wrap a data payload in the standard envelope.
 *
 * The `meta` block is the backbone of the backend-agnostic contract: a client
 * can read meta.api_version + meta.source to stay compatible across backends.
 *
 * @param mixed $data   The payload.
 * @param array $extra  Optional extra meta (e.g. pagination).
 * @param int   $status HTTP status.
 * @return WP_REST_Response
 */
function zymarg_vd_rest_ok( $data, $extra = array(), $status = 200 ) {
	$meta = array_merge(
		array(
			'api_version'    => ZYMARG_VD_API_VERSION,
			'plugin_version' => defined( 'ZYMARG_VD_VERSION' ) ? ZYMARG_VD_VERSION : null,
			'source'         => 'wordpress',
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'currency_symbol'=> function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '',
			'generated_at'   => gmdate( 'c' ),
		),
		$extra
	);
	return new WP_REST_Response( array( 'data' => $data, 'meta' => $meta ), $status );
}

/* ====================================================================== *
 * Endpoints
 * ====================================================================== */

/**
 * GET /me — the authenticated vendor's identity + store profile.
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_me() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$user      = get_userdata( $vendor_id );
	$me        = wp_get_current_user();

	$verification = function_exists( 'zymarg_vd_is_vendor_verified' ) ? zymarg_vd_is_vendor_verified( $vendor_id ) : '';

	return zymarg_vd_rest_ok( array(
		'vendor_id'    => $vendor_id,
		'display_name' => $user ? $user->display_name : '',
		'store_name'   => function_exists( 'zymarg_os_vendor_store_name' ) ? zymarg_os_vendor_store_name( $vendor_id ) : '',
		'store_url'    => function_exists( 'zymarg_os_vendor_store_url' ) ? zymarg_os_vendor_store_url( $vendor_id ) : '',
		'avatar_url'   => get_avatar_url( $vendor_id, array( 'size' => 128 ) ),
		'verification' => $verification ? $verification : 'none', // 'full' | 'id' | 'none'
		'is_staff'     => function_exists( 'zymarg_vd_is_staff' ) ? (bool) zymarg_vd_is_staff( $me->ID ) : false,
		'is_vendor'       => zymarg_vd_rest_is_vendor( $vendor_id ),
		'email'           => $me->user_email,
		'followers_count' => class_exists( 'ZYMARG_SP_Follow' ) ? ZYMARG_SP_Follow::get_count( $vendor_id ) : (int) get_user_meta( $vendor_id, '_zymarg_followers_count', true ),
	) );
}

/**
 * GET /dashboard — the home overview KPIs, chart series and panels.
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_dashboard() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	if ( ! function_exists( 'zymarg_os_vendor_collect_data' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$d = zymarg_os_vendor_collect_data( $vendor_id );

	return zymarg_vd_rest_ok( array(
		'today_sales'    => (float) ( $d['today_sales'] ?? 0 ),
		'sales_delta'    => isset( $d['sales_delta'] ) ? $d['sales_delta'] : null,
		'today_orders'   => (int) ( $d['today_orders'] ?? 0 ),
		'pending_orders' => (int) ( $d['pending_orders'] ?? 0 ),
		'rating'         => (float) ( $d['rating'] ?? 0 ),
		'revenue_series' => array_values( (array) ( $d['revenue_series'] ?? array() ) ),
		'latest_orders'  => array_values( (array) ( $d['latest_orders'] ?? array() ) ),
		'low_stock'      => array_values( (array) ( $d['low_stock'] ?? array() ) ),
		'recent_reviews' => array_values( (array) ( $d['recent_reviews'] ?? array() ) ),
	) );
}

/**
 * GET /orders — the vendor's orders grouped into lifecycle buckets.
 *
 * Optional ?status=pending|processing|shipped|delivered|cancelled|refunds
 * returns just that bucket. Order WRITES (approve/ship/etc.) use WooCommerce
 * core: PUT /wp-json/wc/v3/orders/{id} { "status": "..." }.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function zymarg_vd_rest_orders( $req ) {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$is_vendor = zymarg_vd_rest_is_vendor( $vendor_id );
	if ( ! function_exists( 'zymarg_os_vendor_orders_buckets' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$buckets = zymarg_os_vendor_orders_buckets( $vendor_id, $is_vendor );

	$status = sanitize_key( (string) $req->get_param( 'status' ) );
	if ( $status && isset( $buckets[ $status ] ) ) {
		return zymarg_vd_rest_ok(
			array_values( $buckets[ $status ] ),
			array( 'status' => $status, 'count' => count( $buckets[ $status ] ) )
		);
	}

	// Full bucket set + counts.
	$counts = array();
	foreach ( $buckets as $k => $rows ) {
		$counts[ $k ] = count( $rows );
	}
	return zymarg_vd_rest_ok( $buckets, array( 'counts' => $counts ) );
}

/**
 * GET /earnings — today/week/month + Dokan balance figures + 30-day series.
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_earnings() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$is_vendor = zymarg_vd_rest_is_vendor( $vendor_id );
	if ( ! function_exists( 'zymarg_os_vendor_earnings_data' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$e = zymarg_os_vendor_earnings_data( $vendor_id, $is_vendor );

	return zymarg_vd_rest_ok( array(
		'today'     => (float) ( $e['today'] ?? 0 ),
		'week'      => (float) ( $e['week'] ?? 0 ),
		'month'     => (float) ( $e['month'] ?? 0 ),
		'available' => isset( $e['available'] ) ? $e['available'] : null,
		'withdrawn' => isset( $e['withdrawn'] ) ? $e['withdrawn'] : null,
		'pending'   => isset( $e['pending'] ) ? $e['pending'] : null,
		'series'    => array_values( (array) ( $e['series'] ?? array() ) ),
	) );
}

/**
 * GET /analytics — 30-day revenue/orders/visitors/conversion + top products.
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_analytics() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$is_vendor = zymarg_vd_rest_is_vendor( $vendor_id );
	if ( ! function_exists( 'zymarg_os_vendor_analytics_data' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$a = zymarg_os_vendor_analytics_data( $vendor_id, $is_vendor );

	return zymarg_vd_rest_ok( array(
		'revenue'    => (float) ( $a['revenue'] ?? 0 ),
		'orders'     => (int) ( $a['orders'] ?? 0 ),
		'visitors'   => isset( $a['visitors'] ) ? $a['visitors'] : null,
		'conversion' => isset( $a['conversion'] ) ? $a['conversion'] : null,
		'series'     => array_values( (array) ( $a['series'] ?? array() ) ),
		'top'        => array_values( (array) ( $a['top'] ?? array() ) ),
	) );
}

/**
 * GET /notifications — the merged activity feed (orders, stock, reviews, msgs).
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_notifications() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$is_vendor = zymarg_vd_rest_is_vendor( $vendor_id );
	if ( ! function_exists( 'zymarg_os_vendor_notifications_data' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$items = zymarg_os_vendor_notifications_data( $vendor_id, $is_vendor );
	return zymarg_vd_rest_ok( array_values( (array) $items ) );
}

/**
 * GET /messages — the vendor's conversation list.
 *
 * @return WP_REST_Response
 */
function zymarg_vd_rest_messages_list() {
	$vendor_id = zymarg_vd_rest_vendor_id();
	$is_vendor = zymarg_vd_rest_is_vendor( $vendor_id );
	if ( ! function_exists( 'zymarg_os_vendor_threads' ) ) {
		return zymarg_vd_rest_ok( array() );
	}
	$threads = zymarg_os_vendor_threads( $vendor_id, $is_vendor );
	return zymarg_vd_rest_ok( array_values( (array) $threads ) );
}

/**
 * GET /messages/{customer_id} — the message history of one conversation.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function zymarg_vd_rest_messages_thread( $req ) {
	$vendor_id   = zymarg_vd_rest_vendor_id();
	$customer_id = (int) $req->get_param( 'customer_id' );
	if ( $customer_id <= 0 || ! function_exists( 'zymarg_os_vendor_thread_query' ) ) {
		return zymarg_vd_rest_ok( array() );
	}

	$posts    = zymarg_os_vendor_thread_query( $vendor_id, $customer_id, 200, 'ASC' );
	$messages = array();
	foreach ( (array) $posts as $p ) {
		$messages[] = array(
			'id'        => (int) $p->ID,
			'from'      => ( (int) $p->post_author === (int) $vendor_id ) ? 'vendor' : 'customer',
			'body'      => $p->post_content,
			'timestamp' => get_post_time( 'c', true, $p ),
		);
	}
	$customer = get_userdata( $customer_id );
	return zymarg_vd_rest_ok(
		$messages,
		array(
			'customer_id'   => $customer_id,
			'customer_name' => $customer ? $customer->display_name : '',
		)
	);
}

/**
 * POST /messages — send a message from the vendor to a customer.
 *
 * Body: { "customer_id": 123, "body": "Hi there" }
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function zymarg_vd_rest_messages_send( $req ) {
	$vendor_id   = zymarg_vd_rest_vendor_id();
	$customer_id = (int) $req->get_param( 'customer_id' );
	$body        = sanitize_textarea_field( (string) $req->get_param( 'body' ) );

	if ( $customer_id <= 0 || '' === trim( $body ) ) {
		return new WP_Error( 'zymarg_bad_request', __( 'A customer_id and a non-empty body are required.', 'zymarg-vendor-dashboard' ), array( 'status' => 400 ) );
	}

	$id = wp_insert_post( array(
		'post_type'    => 'zymarg_message',
		'post_status'  => 'publish',
		'post_author'  => $vendor_id,
		'post_content' => $body,
		'meta_input'   => array(
			'_zymarg_vendor'   => $vendor_id,
			'_zymarg_customer' => $customer_id,
		),
	) );

	if ( ! $id || is_wp_error( $id ) ) {
		return new WP_Error( 'zymarg_send_failed', __( 'Could not send the message.', 'zymarg-vendor-dashboard' ), array( 'status' => 500 ) );
	}

	return zymarg_vd_rest_ok( array(
		'id'        => (int) $id,
		'from'      => 'vendor',
		'body'      => $body,
		'timestamp' => gmdate( 'c' ),
	), array(), 201 );
}
