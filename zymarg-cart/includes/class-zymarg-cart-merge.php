<?php
/**
 * Guest-to-logged-in merge handler for ZYMARG Cart Save-for-Later.
 *
 * When a guest customer logs in (via any mechanism — standard WP login,
 * WooCommerce checkout login, or the my-account page), this class:
 *
 * 1. Reads the guest's session Save-for-Later list.
 * 2. Merges it into the user's permanent user-meta list.
 * 3. Deduplicates by item key (product + variation hash).
 * 4. Respects the configured max-items limit.
 * 5. Clears the session list after a successful merge.
 * 6. Enforces the max-items limit on the resulting merged list.
 *
 * A merge-lock transient prevents double-firing when multiple login hooks
 * fire in the same request (e.g. wp_login + woocommerce_customer_login).
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Merge {

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Hook registration — called by Zymarg_Cart::register_hooks().
	// -------------------------------------------------------------------------

	/**
	 * Registers all WordPress and WooCommerce login hooks that trigger a merge.
	 *
	 * Called once from the core plugin class after all includes are loaded.
	 *
	 * Hook ordering:
	 * Multiple hooks are registered intentionally — different login paths fire
	 * different actions, and we want the merge to run on whichever fires first.
	 * The transient lock inside merge_session_to_usermeta() (30s TTL, keyed by
	 * user ID) ensures the actual merge runs at most once per login request
	 * even when several of these hooks fire in the same response.
	 *
	 * Priority chain (lowest priority runs first):
	 *   - wp_login                           @ 10  — standard WP login.
	 *   - woocommerce_checkout_login_customer @ 20 — WC checkout login (after session init).
	 *   - woocommerce_customer_login         @ 20  — WC my-account login (after session init).
	 *   - set_logged_in_cookie               @ 10  — fallback for non-standard auth (REST, app passwords).
	 *
	 * Note on Partial-Checkout hook: Zymarg_Cart_Partial::on_login is registered
	 * at priority 25 on wp_login so it runs AFTER this merge (priority 10),
	 * because the partial-checkout backup migration depends on session data
	 * that the merge step does not touch.
	 */
	public static function init(): void {

		// Standard WordPress login (covers my-account page, wp-login.php,
		// and programmatic wp_signon() calls).
		add_action( 'wp_login', [ self::class, 'on_wp_login' ], 10, 2 );

		// WooCommerce checkout — fires when a returning customer logs in
		// during checkout. Priority 20 ensures WC session is initialised.
		add_action( 'woocommerce_checkout_login_customer', [ self::class, 'on_customer_id_login' ], 20 );

		// WooCommerce my-account login form.
		add_action( 'woocommerce_customer_login', [ self::class, 'on_customer_id_login' ], 20 );

		// REST API / application password authentication edge case.
		// The transient lock guarantees this is a no-op when wp_login already merged.
		add_action( 'set_logged_in_cookie', [ self::class, 'on_set_logged_in_cookie' ], 10, 6 );
	}

	// -------------------------------------------------------------------------
	// Login hook callbacks.
	// -------------------------------------------------------------------------

	/**
	 * Handles the standard wp_login action.
	 *
	 * @param string   $username The user's login name.
	 * @param \WP_User $user     The WP_User object for the logged-in user.
	 */
	public static function on_wp_login( string $username, \WP_User $user ): void {
		self::merge_session_to_usermeta( $user->ID );
	}

	/**
	 * Handles WooCommerce hooks that provide only a customer/user ID.
	 *
	 * @param int $customer_id WordPress user ID.
	 */
	public static function on_customer_id_login( int $customer_id ): void {
		self::merge_session_to_usermeta( $customer_id );
	}

	/**
	 * Handles the set_logged_in_cookie action as a fallback for non-standard
	 * authentication flows (e.g. REST API, application passwords) where neither
	 * wp_login nor any of the WooCommerce login actions fire.
	 *
	 * The transient lock inside merge_session_to_usermeta() makes this a no-op
	 * when one of the higher-priority hooks already ran the merge for this
	 * user in the current request.
	 *
	 * Use the $user_id parameter directly — do NOT call is_user_logged_in()
	 * here because by the time this action fires, wp_set_current_user() has
	 * usually already been called and is_user_logged_in() would return true,
	 * preventing the fallback from ever running.
	 *
	 * @param string $logged_in_cookie The logged-in cookie value.
	 * @param int    $expire           Cookie expiration timestamp.
	 * @param int    $expiration       Cookie expiration duration.
	 * @param int    $user_id          WordPress user ID.
	 * @param string $logged_in        'logged_in' string.
	 * @param string $token            Authentication token.
	 */
	public static function on_set_logged_in_cookie(
		string $logged_in_cookie,
		int $expire,
		int $expiration,
		int $user_id,
		string $logged_in,
		string $token
	): void {
		if ( $user_id > 0 ) {
			self::merge_session_to_usermeta( $user_id );
		}
	}

	// -------------------------------------------------------------------------
	// Core merge logic.
	// -------------------------------------------------------------------------

	/**
	 * Performs the full guest-session → user-meta merge for a given user.
	 *
	 * A per-user transient lock (TTL: 30 seconds) prevents this method from
	 * running more than once per login request even if multiple hooks fire.
	 *
	 * Flow:
	 * 1. Acquire merge lock — bail if already locked.
	 * 2. Read session saved items.
	 * 3. Bail early if nothing to merge.
	 * 4. Delegate to Zymarg_Cart_Usermeta::merge_items().
	 * 5. Clear the session list.
	 * 6. Enforce max-items limit on the resulting user list.
	 * 7. Fire action hooks for other plugins to react.
	 * 8. Release merge lock.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function merge_session_to_usermeta( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// Acquire merge lock — prevents double execution in the same request.
		$lock_key = 'zymarg_merge_lock_' . $user_id;
		if ( get_transient( $lock_key ) ) {
			Zymarg_Cart_Helpers::log(
				'Merge::merge_session_to_usermeta — skipped (lock active).',
				[ 'user_id' => $user_id ]
			);
			return;
		}

		set_transient( $lock_key, true, 30 );

		try {
			// Read session items — the data to merge.
			$session_items = Zymarg_Cart_Session::get_raw_for_merge();

			if ( empty( $session_items ) ) {
				Zymarg_Cart_Helpers::log(
					'Merge::merge_session_to_usermeta — no session items, skipping.',
					[ 'user_id' => $user_id ]
				);
				return;
			}

			Zymarg_Cart_Helpers::log(
				'Merge::merge_session_to_usermeta — starting merge.',
				[
					'user_id'        => $user_id,
					'session_count'  => count( $session_items ),
				]
			);

			/**
			 * Allow other plugins to modify session items before they are merged.
			 *
			 * @param array<string, array<string, mixed>> $session_items Items about to be merged.
			 * @param int                                 $user_id       Target user ID.
			 */
			$session_items = (array) apply_filters(
				'zymarg_cart_before_merge',
				$session_items,
				$user_id
			);

			// Perform the merge via the usermeta class.
			$result = Zymarg_Cart_Usermeta::merge_items( $user_id, $session_items );

			// Clear the session list regardless of how many items were merged
			// so the guest list is never shown again after login.
			Zymarg_Cart_Session::clear_all();

			// Enforce max limit on the now-merged user list.
			Zymarg_Cart_Usermeta::enforce_max_limit( $user_id );

			Zymarg_Cart_Helpers::log(
				'Merge::merge_session_to_usermeta — merge complete.',
				[
					'user_id'    => $user_id,
					'merged'     => $result['merged'],
					'skipped'    => $result['skipped'],
					'over_limit' => $result['over_limit'],
					'total_saved' => Zymarg_Cart_Usermeta::count( $user_id ),
				]
			);

			/**
			 * Fires after a successful guest-to-user merge.
			 *
			 * @param int                   $user_id WordPress user ID.
			 * @param array{merged: int, skipped: int, over_limit: int} $result Merge summary.
			 */
			do_action( 'zymarg_cart_after_merge', $user_id, $result );

		} finally {
			// Always release the lock so subsequent page loads work normally.
			delete_transient( $lock_key );
		}
	}

	// -------------------------------------------------------------------------
	// Public helpers.
	// -------------------------------------------------------------------------

	/**
	 * Returns a summary array suitable for inclusion in an AJAX response after
	 * a programmatic merge (e.g. via WooCommerce checkout login).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{user_id: int, saved_count: int}
	 */
	public static function get_merge_summary( int $user_id ): array {
		return [
			'user_id'     => $user_id,
			'saved_count' => Zymarg_Cart_Usermeta::count( $user_id ),
		];
	}
}
