<?php
/**
 * Follow / Unfollow Logic
 *
 * Stores follow relationships in user meta on the *current user* (the shopper):
 *   meta_key: _zymarg_followed_stores  (serialised array of vendor IDs)
 *
 * And on the *vendor* for a fast follower count:
 *   meta_key: _zymarg_followers_count  (int)
 *
 * Exposes two REST endpoints (requires a logged-in user):
 *   POST /wp-json/zymarg/v1/follow   { store_id }  → follow
 *   POST /wp-json/zymarg/v1/unfollow { store_id }  → unfollow
 *
 * And one public endpoint for checking state:
 *   GET  /wp-json/zymarg/v1/follow-status?store_id=123
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Follow {

	const META_FOLLOWED  = '_zymarg_followed_stores'; // on the shopper
	const META_COUNT        = '_zymarg_followers_count'; // on the vendor
	const META_FOLLOW_DATES = '_zymarg_follow_dates';      // on the shopper: [ vendor_id => unix_timestamp ]

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST routes
	// ─────────────────────────────────────────────────────────────────────────

	public static function register_routes() {
		// Reuse vendor dashboard namespace if active, else default to zymarg/v1.
		$ns = defined( 'ZYMARG_VD_API_NS' ) ? ZYMARG_VD_API_NS : 'zymarg/v1';

		register_rest_route( $ns, '/follow', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_follow' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
			'args'                => self::store_id_arg(),
		] );

		register_rest_route( $ns, '/unfollow', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_unfollow' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
			'args'                => self::store_id_arg(),
		] );

		register_rest_route( $ns, '/follow-status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_status' ],
			'permission_callback' => '__return_true',
			'args'                => self::store_id_arg(),
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Handlers
	// ─────────────────────────────────────────────────────────────────────────

	public static function handle_follow( WP_REST_Request $request ) {
		$store_id  = (int) $request->get_param( 'store_id' );
		$user_id   = get_current_user_id();

		if ( ! self::vendor_exists( $store_id ) ) {
			return new WP_Error( 'invalid_store', __( 'Store not found.', 'zymarg-store-page' ), [ 'status' => 404 ] );
		}

		$followed = self::get_followed_stores( $user_id );

		if ( ! in_array( $store_id, $followed, true ) ) {
			$followed[] = $store_id;
			update_user_meta( $user_id, self::META_FOLLOWED, $followed );
			// Record the exact timestamp this follow happened.
			$dates               = self::get_follow_dates( $user_id );
			$dates[ $store_id ]  = time();
			update_user_meta( $user_id, self::META_FOLLOW_DATES, $dates );
			self::increment_count( $store_id, 1 );
		}

		return rest_ensure_response( [
			'following'       => true,
			'followers_count' => self::get_count( $store_id ),
		] );
	}

	public static function handle_unfollow( WP_REST_Request $request ) {
		$store_id = (int) $request->get_param( 'store_id' );
		$user_id  = get_current_user_id();

		if ( ! self::vendor_exists( $store_id ) ) {
			return new WP_Error( 'invalid_store', __( 'Store not found.', 'zymarg-store-page' ), [ 'status' => 404 ] );
		}

		$followed = self::get_followed_stores( $user_id );
		$new      = array_values( array_filter( $followed, fn( $id ) => $id !== $store_id ) );

		if ( count( $new ) !== count( $followed ) ) {
			update_user_meta( $user_id, self::META_FOLLOWED, $new );
			// Remove the follow timestamp for this store.
			$dates = self::get_follow_dates( $user_id );
			unset( $dates[ $store_id ] );
			update_user_meta( $user_id, self::META_FOLLOW_DATES, $dates );
			self::increment_count( $store_id, -1 );
		}

		return rest_ensure_response( [
			'following'       => false,
			'followers_count' => self::get_count( $store_id ),
		] );
	}

	public static function handle_status( WP_REST_Request $request ) {
		$store_id  = (int) $request->get_param( 'store_id' );
		$user_id   = get_current_user_id();
		$following = $user_id ? in_array( $store_id, self::get_followed_stores( $user_id ), true ) : false;

		return rest_ensure_response( [
			'following'       => $following,
			'followers_count' => self::get_count( $store_id ),
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	public static function require_login() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'You must be logged in to follow a store.', 'zymarg-store-page' ), [ 'status' => 401 ] );
		}
		return true;
	}

	private static function store_id_arg() {
		return [
			'store_id' => [
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	private static function vendor_exists( int $store_id ): bool {
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = dokan_get_store_info( $store_id );
			return ! empty( $info );
		}
		// Fallback: check user exists and has seller role.
		$user = get_userdata( $store_id );
		return $user && in_array( 'seller', (array) $user->roles, true );
	}

	/**
	 * Return the follow-date map for a shopper: [ vendor_id => unix_timestamp ].
	 *
	 * @param int $user_id Shopper user ID.
	 * @return array<int,int>
	 */
	public static function get_follow_dates( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_FOLLOW_DATES, true );
		return is_array( $raw ) ? array_map( 'intval', $raw ) : [];
	}

	/**
	 * Return the Unix timestamp when $user_id followed $store_id, or 0 if unknown.
	 *
	 * @param int $user_id  Shopper user ID.
	 * @param int $store_id Vendor user ID.
	 * @return int
	 */
	public static function get_follow_date( int $user_id, int $store_id ): int {
		$dates = self::get_follow_dates( $user_id );
		return isset( $dates[ $store_id ] ) ? (int) $dates[ $store_id ] : 0;
	}

	private static function get_followed_stores( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_FOLLOWED, true );
		return is_array( $raw ) ? array_map( 'intval', $raw ) : [];
	}

	public static function get_count( int $store_id ): int {
		// Prefer native Dokan function when available.
		if ( function_exists( 'dokan_get_store_followers' ) ) {
			$val = dokan_get_store_followers( $store_id );
			if ( is_numeric( $val ) ) {
				return (int) $val;
			}
		}
		return (int) get_user_meta( $store_id, self::META_COUNT, true );
	}

	private static function increment_count( int $store_id, int $delta ) {
		// If Dokan owns the count we leave it alone — it hooks its own logic.
		if ( function_exists( 'dokan_get_store_followers' ) ) {
			return;
		}
		$current = (int) get_user_meta( $store_id, self::META_COUNT, true );
		update_user_meta( $store_id, self::META_COUNT, max( 0, $current + $delta ) );
	}

	/**
	 * Whether the current visitor already follows $store_id.
	 * Used by the template to seed the initial button state.
	 */
	public static function current_user_follows( int $store_id ): bool {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		return in_array( $store_id, self::get_followed_stores( $uid ), true );
	}
}
