<?php
/**
 * Reinforced Partial Checkout — Solution 1 (Temporary Cart Swap).
 *
 * Allows customers to check out with only their selected cart items while
 * the unselected items are preserved and automatically restored after the
 * order is placed.
 *
 * -------------------------------------------------------------------------
 * FLOW
 * -------------------------------------------------------------------------
 *
 * CHECKOUT INITIATION (triggered by customer clicking "Proceed to Checkout"):
 *   1. AJAX handler calls backup_cart( $selected_keys ).
 *   2. Full cart is serialised and saved to backup storage.
 *   3. All non-selected items are removed from the active WC cart.
 *   4. WC checkout now only sees the selected items.
 *   5. Customer is redirected to WC checkout URL.
 *
 * AFTER SUCCESSFUL ORDER (reinforced — 3 hooks):
 *   Hook A: woocommerce_thankyou               → customer is on /thankyou/ page.
 *   Hook B: woocommerce_order_status_processing → may fire inline during COD
 *                                                 checkout submission OR from
 *                                                 a payment gateway webhook.
 *   Hook C: woocommerce_order_status_completed  → may be admin action.
 *
 *   v1.1.4: ALL three hooks queue unselected items to a pending-restore
 *   storage (user-meta for logged-in customers, WC session for guests),
 *   and the items are restored on the next /cart/ visit by
 *   maybe_restore_cart(). The pre-1.1.4 "restore directly to cart when
 *   the customer is active" path was racy: for COD orders, the
 *   `woocommerce_order_status_processing` hook fires INLINE during the
 *   checkout submission BEFORE WooCommerce calls empty_cart() — so any
 *   items added via add_to_cart() in that hook were immediately wiped,
 *   then the per-order transient lock blocked the later
 *   `woocommerce_thankyou` hook from doing a second restore. Net result
 *   pre-1.1.4: COD customers lost their unselected items entirely.
 *   Always-defer-to-pending sidesteps the race because
 *   maybe_restore_cart() runs on `woocommerce_before_cart` priority 5,
 *   long after WC's empty_cart() has happened.
 *
 * CART PAGE RETURN WITHOUT COMPLETING ORDER (abandonment):
 *   woocommerce_before_cart fires → backup found → unselected items restored.
 *
 * BACKUP EXPIRY (2-hour default):
 *   woocommerce_cart_loaded_from_session → check_backup_expiry() →
 *   auto-restore expired backup so items are never permanently lost.
 *
 * -------------------------------------------------------------------------
 * STORAGE
 * -------------------------------------------------------------------------
 *   Guest        → WC session key:   _zymarg_cart_backup
 *   Logged-in    → user meta key:    _zymarg_cart_backup
 *   Pending restore (logged-in)   →  _zymarg_pending_restore (user meta)
 *   Pending restore (guest, v1.1.4) → _zymarg_pending_restore (WC session)
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Partial {

	/** User-meta key for items queued for restore after a background webhook. */
	private const USERMETA_KEY_PENDING = '_zymarg_pending_restore';

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Hook registration.
	// -------------------------------------------------------------------------

	/**
	 * Registers all WordPress and WooCommerce hooks.
	 * Called once from Zymarg_Cart::register_hooks() after all includes load.
	 *
	 * Hook ordering note:
	 * The wp_login hook here is registered at priority 25, AFTER
	 * Zymarg_Cart_Merge::on_wp_login (priority 10), so the Save-for-Later
	 * merge runs first and the partial-checkout backup migration runs
	 * after. The two operate on different storage keys so they do not
	 * conflict, but keeping a deterministic order makes log traces and
	 * debugging predictable.
	 */
	public static function init(): void {

		// ── Reinforced backup clear — 3 hooks ─────────────────────────────
		// Hook A: standard checkout completion (customer active).
		add_action( 'woocommerce_thankyou',                   [ self::class, 'on_order_complete' ], 10, 1 );

		// Hook B: order → processing (covers iPay88, Billplz, FPX gateway callbacks).
		add_action( 'woocommerce_order_status_processing',    [ self::class, 'on_order_complete' ], 10, 1 );

		// Hook C: order → completed (covers COD, manual admin completion).
		add_action( 'woocommerce_order_status_completed',     [ self::class, 'on_order_complete' ], 10, 1 );

		// ── Thank you page: immediate restore (Option B) ──────────────────
		// Priority 20 — runs AFTER on_order_complete (priority 10) has already
		// queued unselected items to _zymarg_pending_restore. We then restore
		// them directly to the WC cart while the customer's session is still
		// active on the thank you page. This means unselected items are back
		// in the cart before the customer navigates away — no need to visit
		// the cart page first.
		// The cart-page maybe_restore_cart() remains as a safety net for
		// background gateway webhooks (iPay88, Billplz, FPX) where the customer
		// session may not be active during on_order_complete.
		add_action( 'woocommerce_thankyou', [ self::class, 'restore_pending_on_thankyou' ], 20, 1 );

		// ── Cart page hooks ───────────────────────────────────────────────
		// Restore abandoned checkout backup when customer returns to cart page.
		add_action( 'woocommerce_before_cart', [ self::class, 'maybe_restore_cart' ], 5 );

		// Check backup expiry on every cart session load.
		add_action( 'woocommerce_cart_loaded_from_session', [ self::class, 'check_backup_expiry' ], 10 );

		// ── Login migration ───────────────────────────────────────────────
		// Migrate a guest session backup to user meta on login so it is
		// not lost when the WC session is replaced with the user session.
		add_action( 'wp_login', [ self::class, 'on_login' ], 25, 2 );
	}

	// =========================================================================
	// PUBLIC METHODS — called by the AJAX handler and templates.
	// =========================================================================

	/**
	 * Serialises the entire WC cart to backup storage and removes all items
	 * that are NOT in $selected_keys from the active cart.
	 *
	 * Called by: Zymarg_Cart_Ajax::handle_zymarg_partial_checkout()
	 *
	 * @param array<int, string> $selected_keys WC cart item keys to KEEP for checkout.
	 * @return bool True on success, false if backup could not be stored.
	 */
	public static function backup_cart( array $selected_keys ): bool {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::log( 'Partial::backup_cart — WC cart unavailable.', null, 'warning' );
			return false;
		}

		if ( empty( $selected_keys ) ) {
			Zymarg_Cart_Helpers::log( 'Partial::backup_cart — no selected keys supplied.', null, 'warning' );
			return false;
		}

		$cart      = WC()->cart;
		$all_items = $cart->get_cart();

		if ( empty( $all_items ) ) {
			return false;
		}

		// Serialise every cart item (removes WC_Product objects).
		$backup_items = [];
		foreach ( $all_items as $key => $item ) {
			$backup_items[ $key ] = self::serialize_cart_item( $key, $item );
		}

		$now    = time();
		$expiry = Zymarg_Cart_Helpers::get_backup_expiry();

		$backup = [
			'backed_up_at'  => $now,
			'expires_at'    => $now + $expiry,
			'selected_keys' => array_values( array_unique( $selected_keys ) ),
			'user_id'       => get_current_user_id(),
			'items'         => $backup_items,
		];

		$stored = self::set_backup_storage( $backup );

		if ( $stored ) {
			Zymarg_Cart_Helpers::log(
				'Partial::backup_cart — backup created.',
				[
					'total_items'    => count( $backup_items ),
					'selected_count' => count( $selected_keys ),
					'expires_in'     => $expiry . 's',
				]
			);

			/**
			 * Fires after a partial-checkout backup is successfully created.
			 *
			 * @param array<string, mixed> $backup The full backup array.
			 */
			do_action( 'zymarg_cart_backup_created', $backup );
		}

		return $stored;
	}

	/**
	 * Removes all cart items whose key is NOT in $selected_keys.
	 *
	 * Must be called immediately after backup_cart() so only the selected
	 * items remain in the active cart before WC checkout processes them.
	 *
	 * @param array<int, string> $selected_keys Cart item keys to KEEP.
	 * @return bool True if at least one item was removed.
	 */
	public static function remove_unselected( array $selected_keys ): bool {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return false;
		}

		$cart     = WC()->cart;
		$all_keys = array_keys( $cart->get_cart() );
		$removed  = false;

		foreach ( $all_keys as $key ) {
			if ( ! in_array( $key, $selected_keys, true ) ) {
				$cart->remove_cart_item( $key );
				$removed = true;
			}
		}

		if ( $removed ) {
			// Recalculate so checkout sees correct totals immediately.
			$cart->calculate_totals();

			Zymarg_Cart_Helpers::log(
				'Partial::remove_unselected — unselected items removed.',
				[ 'kept' => count( $selected_keys ) ]
			);
		}

		return $removed;
	}

	/**
	 * Adds all backed-up cart items that are not already in the active cart
	 * back to WC cart, then clears the backup.
	 *
	 * @return bool True if at least one item was restored.
	 */
	public static function restore_cart(): bool {
		$backup = self::get_backup_storage();

		if ( $backup === null ) {
			return false;
		}

		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			return false;
		}

		$cart         = WC()->cart;
		$current_keys = array_keys( $cart->get_cart() );
		$restored     = 0;
		$failed       = 0;

		foreach ( $backup['items'] as $key => $item ) {
			// Skip items already in the active cart.
			if ( in_array( $key, $current_keys, true ) ) {
				continue;
			}

			$added = $cart->add_to_cart(
				(int) ( $item['product_id']    ?? 0 ),
				(int) ( $item['quantity']      ?? 1 ),
				(int) ( $item['variation_id']  ?? 0 ),
				(array) ( $item['variation']   ?? [] ),
				(array) ( $item['cart_item_data'] ?? [] )
			);

			if ( false !== $added ) {
				++$restored;
			} else {
				++$failed;
				Zymarg_Cart_Helpers::log(
					'Partial::restore_cart — failed to restore item.',
					[ 'product_id' => $item['product_id'] ?? 0 ],
					'warning'
				);
			}
		}

		// Always clear the backup after an attempt, success or partial.
		self::delete_backup_storage_for_user( get_current_user_id() );

		if ( $restored > 0 ) {
			$cart->calculate_totals();
		}

		Zymarg_Cart_Helpers::log(
			'Partial::restore_cart — complete.',
			[ 'restored' => $restored, 'failed' => $failed ]
		);

		/**
		 * Fires after a partial-checkout cart restoration attempt.
		 *
		 * @param int $restored Number of items successfully restored.
		 * @param int $failed   Number of items that could not be added.
		 */
		do_action( 'zymarg_cart_restored', $restored, $failed );

		return $restored > 0;
	}

	/**
	 * Checks whether the stored backup has exceeded its expiry time.
	 * If expired, triggers an automatic restore.
	 *
	 * Called on: woocommerce_cart_loaded_from_session
	 */
	public static function check_backup_expiry(): void {
		$backup = self::get_backup_storage();

		if ( $backup === null ) {
			return;
		}

		$expires_at = (int) ( $backup['expires_at'] ?? 0 );

		if ( $expires_at > 0 && time() > $expires_at ) {
			Zymarg_Cart_Helpers::log(
				'Partial::check_backup_expiry — backup expired, triggering restore.',
				[ 'expired_seconds_ago' => time() - $expires_at ]
			);

			self::restore_cart();
		}
	}

	/**
	 * Returns true if a backup exists in storage for the current user/session.
	 *
	 * @return bool
	 */
	public static function has_backup(): bool {
		return self::get_backup_storage() !== null;
	}

	/**
	 * Returns the raw backup array or null if none exists.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_backup(): ?array {
		return self::get_backup_storage();
	}

	/**
	 * Outputs a hidden sentinel div that the JS layer uses to detect an active
	 * restoration in progress and show a brief loading overlay.
	 */
	public static function show_restore_spinner(): void {
		if ( ! self::has_backup() && ! self::has_pending_restore() ) {
			return;
		}

		// The JS module (zymarg-cart.js) watches for this element on DOMContentLoaded.
		echo '<div id="zymarg-restore-sentinel" aria-hidden="true" style="display:none;" '
			. 'data-has-backup="' . ( self::has_backup() ? '1' : '0' ) . '" '
			. 'data-has-pending="' . ( self::has_pending_restore() ? '1' : '0' ) . '">'
			. '</div>';
	}

	// =========================================================================
	// ORDER HOOK CALLBACKS
	// =========================================================================

	/**
	 * Fires for three order hooks after a successful order.
	 *
	 * A per-order transient lock prevents all three hooks from running the
	 * same logic for the same order within a 90-second window.
	 *
	 * Logic:
	 * 1. Get the backup for the order's customer.
	 * 2. Identify the UNSELECTED items (not purchased in this order).
	 * 3. Restore unselected items — either directly to WC cart (if customer
	 *    is active) or to a _zymarg_pending_restore user-meta key (if this
	 *    is a background gateway webhook where the customer has no active session).
	 * 4. Clear the backup.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function on_order_complete( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		// Transient lock — prevents duplicate execution across the 3 hooks.
		$lock_key = 'zymarg_partial_lock_' . $order_id;
		if ( get_transient( $lock_key ) ) {
			Zymarg_Cart_Helpers::log(
				'Partial::on_order_complete — lock active, skipping.',
				[ 'order_id' => $order_id ]
			);
			return;
		}

		set_transient( $lock_key, true, 90 );

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Abstract_Order ) {
				return;
			}

			$customer_id = (int) $order->get_customer_id();
			$backup      = self::get_backup_for_customer( $customer_id );

			if ( $backup === null ) {
				Zymarg_Cart_Helpers::log(
					'Partial::on_order_complete — no backup found.',
					[ 'order_id' => $order_id, 'customer_id' => $customer_id ]
				);
				return;
			}

			$selected_keys    = (array) ( $backup['selected_keys'] ?? [] );
			$unselected_items = self::filter_unselected_items( $backup['items'], $selected_keys );

			Zymarg_Cart_Helpers::log(
				'Partial::on_order_complete — processing.',
				[
					'order_id'         => $order_id,
					'customer_id'      => $customer_id,
					'unselected_count' => count( $unselected_items ),
				]
			);

			// ─── Queue unselected items for pending restore ─────────────────
			//
			// v1.1.4: ALWAYS queue to pending-restore storage, regardless of
			// whether the customer is "active" right now. This is to avoid the
			// race against WC's own empty_cart() — see the class docblock for
			// the full COD failure trace. Items are restored on the next
			// /cart/ visit by maybe_restore_cart().
			if ( ! empty( $unselected_items ) ) {
				if ( $customer_id > 0 ) {
					// Logged-in customer — persist to user meta.
					update_user_meta(
						$customer_id,
						self::USERMETA_KEY_PENDING,
						array_values( $unselected_items )
					);

					Zymarg_Cart_Helpers::log(
						'Partial::on_order_complete — items queued in pending restore (user_meta).',
						[ 'customer_id' => $customer_id, 'count' => count( $unselected_items ) ]
					);
				} else {
					// Guest — persist to WC session (v1.1.4).
					$session = Zymarg_Cart_Helpers::get_wc_session();
					if ( $session ) {
						$session->set(
							Zymarg_Cart_Helpers::SESSION_KEY_PENDING,
							array_values( $unselected_items )
						);

						Zymarg_Cart_Helpers::log(
							'Partial::on_order_complete — items queued in pending restore (session).',
							[ 'count' => count( $unselected_items ) ]
						);
					}
				}
			}

			// Clear the backup regardless of restoration method.
			self::delete_backup_storage_for_customer( $customer_id );

			Zymarg_Cart_Helpers::log(
				'Partial::on_order_complete — backup cleared.',
				[ 'order_id' => $order_id ]
			);

			/**
			 * Fires after partial-checkout post-order processing is complete.
			 *
			 * @param int                          $order_id         WC order ID.
			 * @param array<string, array<string,mixed>> $unselected_items Items queued for restore.
			 */
			do_action( 'zymarg_cart_partial_checkout_complete', $order_id, $unselected_items );

		} finally {
			delete_transient( $lock_key );
		}
	}

	// =========================================================================
	// THANK YOU PAGE: IMMEDIATE RESTORE (Option B)
	// =========================================================================

	/**
	 * Fires on woocommerce_thankyou at priority 20 — after on_order_complete
	 * (priority 10) has already queued unselected items to pending restore.
	 *
	 * Restores the pending items directly to the WC cart while the customer's
	 * session is still active on the thank you page. This means the cart is
	 * fully restored before the customer navigates away, eliminating the need
	 * to visit the cart page first.
	 *
	 * Safety checks:
	 * — Only runs if WC cart is available (customer session is active).
	 * — Only runs if a pending restore queue exists.
	 * — Clears the pending queue after restore to prevent double-restore
	 *   on the subsequent cart page visit.
	 * — Logs all outcomes for debugging.
	 *
	 * The cart-page maybe_restore_cart() remains as a safety net for gateway
	 * webhook scenarios (iPay88, Billplz, FPX) where this thankyou hook may
	 * fire before the gateway has confirmed the order, meaning on_order_complete
	 * has not yet queued the pending restore — in those cases the cart page
	 * restore handles it.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function restore_pending_on_thankyou( int $order_id ): void {

		if ( $order_id <= 0 ) {
			return;
		}

		// Must have an active WC cart session to restore to.
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::log(
				'Partial::restore_pending_on_thankyou — cart unavailable, skipping.',
				[ 'order_id' => $order_id ]
			);
			return;
		}

		$restored = false;

		// ── Logged-in customer: read from user meta ────────────────────────
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$pending = get_user_meta( $user_id, self::USERMETA_KEY_PENDING, true );

			if ( ! empty( $pending ) && is_array( $pending ) ) {
				Zymarg_Cart_Helpers::log(
					'Partial::restore_pending_on_thankyou — restoring from user_meta.',
					[ 'order_id' => $order_id, 'user_id' => $user_id, 'count' => count( $pending ) ]
				);

				self::add_items_to_cart( $pending );
				delete_user_meta( $user_id, self::USERMETA_KEY_PENDING );
				$restored = true;
			}

		// ── Guest customer: read from WC session ───────────────────────────
		} else {
			$session = Zymarg_Cart_Helpers::get_wc_session();
			if ( $session ) {
				$pending = $session->get( Zymarg_Cart_Helpers::SESSION_KEY_PENDING );

				if ( ! empty( $pending ) && is_array( $pending ) ) {
					Zymarg_Cart_Helpers::log(
						'Partial::restore_pending_on_thankyou — restoring from session.',
						[ 'order_id' => $order_id, 'count' => count( $pending ) ]
					);

					self::add_items_to_cart( $pending );
					$session->set( Zymarg_Cart_Helpers::SESSION_KEY_PENDING, null );
					$restored = true;
				}
			}
		}

		if ( $restored ) {
			Zymarg_Cart_Helpers::log(
				'Partial::restore_pending_on_thankyou — restore complete.',
				[ 'order_id' => $order_id ]
			);

			/**
			 * Fires after unselected items are successfully restored on the
			 * thank you page.
			 *
			 * @param int $order_id The completed order ID.
			 */
			do_action( 'zymarg_cart_restored_on_thankyou', $order_id );

		} else {
			Zymarg_Cart_Helpers::log(
				'Partial::restore_pending_on_thankyou — no pending items found (may be gateway webhook scenario).',
				[ 'order_id' => $order_id ]
			);
		}
	}

	// =========================================================================
	// CART PAGE HOOKS
	// =========================================================================

	/**
	 * Fires on woocommerce_before_cart (priority 5 — before cart renders).
	 *
	 * Handles two restoration scenarios:
	 * A) Pending restore (from background gateway webhook after order success).
	 * B) Backup restore (customer returned from checkout without ordering).
	 */
	public static function maybe_restore_cart(): void {
		$restored = false;

		// ─── Scenario A: pending restore queued by on_order_complete ────────
		//
		// v1.1.4: now handles BOTH logged-in (user meta) AND guest (WC session)
		// pending queues. Pre-1.1.4 only checked user_meta, so guest customers
		// who completed a partial checkout never got their unselected items
		// back.
		if ( is_user_logged_in() ) {
			$pending = get_user_meta( get_current_user_id(), self::USERMETA_KEY_PENDING, true );

			if ( ! empty( $pending ) && is_array( $pending ) ) {
				Zymarg_Cart_Helpers::log(
					'Partial::maybe_restore_cart — processing pending restore (user_meta).',
					[ 'count' => count( $pending ) ]
				);

				self::add_items_to_cart( $pending );
				delete_user_meta( get_current_user_id(), self::USERMETA_KEY_PENDING );
				$restored = true;
			}
		} else {
			$session = Zymarg_Cart_Helpers::get_wc_session();
			if ( $session ) {
				$pending = $session->get( Zymarg_Cart_Helpers::SESSION_KEY_PENDING );

				if ( ! empty( $pending ) && is_array( $pending ) ) {
					Zymarg_Cart_Helpers::log(
						'Partial::maybe_restore_cart — processing pending restore (session).',
						[ 'count' => count( $pending ) ]
					);

					self::add_items_to_cart( $pending );
					$session->set( Zymarg_Cart_Helpers::SESSION_KEY_PENDING, null );
					$restored = true;
				}
			}
		}

		// ─── Scenario B: customer returned from abandoned checkout ───────────
		$backup = self::get_backup_storage();

		if ( $backup !== null ) {
			// Prevent restore if we are still in the same checkout request.
			$checkout_lock = 'zymarg_checkout_initiated_' . self::current_user_key();
			if ( get_transient( $checkout_lock ) ) {
				return;
			}

			Zymarg_Cart_Helpers::log(
				'Partial::maybe_restore_cart — restoring abandoned checkout backup.',
				[ 'item_count' => count( $backup['items'] ?? [] ) ]
			);

			$restore_result = self::restore_cart();

			if ( $restore_result ) {
				$restored = true;
			}
		}

		// Show a "cart restored" notice after either restore scenario.
		if ( $restored && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				(string) apply_filters(
					'zymarg_cart_restored_notice_text',
					__( 'Your cart has been restored.', 'zymarg-cart' )
				),
				'notice'
			);
		}
	}

	// =========================================================================
	// LOGIN HOOK CALLBACK
	// =========================================================================

	/**
	 * When a guest logs in, migrates their session backup to user meta so it
	 * is not lost when the WC session context changes post-login.
	 *
	 * @param string   $username Login name (unused).
	 * @param \WP_User $user     The logged-in user object.
	 */
	public static function on_login( string $username, \WP_User $user ): void {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return;
		}

		// ── Migrate the partial-checkout backup ──────────────────────────
		$session_backup = $session->get( Zymarg_Cart_Helpers::SESSION_KEY_BACKUP );
		if ( ! empty( $session_backup ) && is_array( $session_backup ) ) {
			// Only migrate if the user does not already have a user-meta backup
			// (don't overwrite a more recent backup with a stale session one).
			$existing = get_user_meta( $user->ID, Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP, true );

			if ( empty( $existing ) ) {
				update_user_meta(
					$user->ID,
					Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP,
					$session_backup
				);

				$session->set( Zymarg_Cart_Helpers::SESSION_KEY_BACKUP, null );

				Zymarg_Cart_Helpers::log(
					'Partial::on_login — guest backup migrated to user meta.',
					[ 'user_id' => $user->ID ]
				);
			}
		}

		// ── Migrate the post-order pending-restore queue (v1.1.4) ─────────
		// A guest may have placed an order, started the pending-restore queue
		// in their WC session, and then logged in before visiting /cart/. In
		// that case we move the queue to user-meta so maybe_restore_cart()
		// finds it on the next cart load.
		$session_pending = $session->get( Zymarg_Cart_Helpers::SESSION_KEY_PENDING );
		if ( ! empty( $session_pending ) && is_array( $session_pending ) ) {
			$existing_pending = get_user_meta( $user->ID, self::USERMETA_KEY_PENDING, true );

			if ( empty( $existing_pending ) ) {
				update_user_meta(
					$user->ID,
					self::USERMETA_KEY_PENDING,
					$session_pending
				);

				$session->set( Zymarg_Cart_Helpers::SESSION_KEY_PENDING, null );

				Zymarg_Cart_Helpers::log(
					'Partial::on_login — guest pending-restore migrated to user meta.',
					[ 'user_id' => $user->ID, 'count' => count( $session_pending ) ]
				);
			}
		}
	}

	// =========================================================================
	// PRIVATE — STORAGE
	// =========================================================================

	/**
	 * Reads backup data from the appropriate storage for the CURRENT user.
	 * Logged-in: user meta. Guest: WC session.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_backup_storage(): ?array {
		if ( is_user_logged_in() ) {
			$data = get_user_meta(
				get_current_user_id(),
				Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP,
				true
			);
		} else {
			$session = Zymarg_Cart_Helpers::get_wc_session();
			$data    = $session?->get( Zymarg_Cart_Helpers::SESSION_KEY_BACKUP );
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Reads backup data for a SPECIFIC customer ID (for order hooks that
	 * run in a different user context, e.g. gateway webhooks or admin actions).
	 *
	 * @param int $customer_id WC customer / WP user ID (0 for guests).
	 * @return array<string, mixed>|null
	 */
	private static function get_backup_for_customer( int $customer_id ): ?array {
		if ( $customer_id > 0 ) {
			$data = get_user_meta(
				$customer_id,
				Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP,
				true
			);
		} else {
			// Guest: try from current session (thankyou hook runs in customer session).
			$session = Zymarg_Cart_Helpers::get_wc_session();
			$data    = $session?->get( Zymarg_Cart_Helpers::SESSION_KEY_BACKUP );
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Saves backup data to the appropriate storage for the CURRENT user.
	 *
	 * @param array<string, mixed> $backup Backup payload.
	 * @return bool
	 */
	private static function set_backup_storage( array $backup ): bool {
		if ( is_user_logged_in() ) {
			return (bool) update_user_meta(
				get_current_user_id(),
				Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP,
				$backup
			);
		}

		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return false;
		}

		$session->set( Zymarg_Cart_Helpers::SESSION_KEY_BACKUP, $backup );
		return true;
	}

	/**
	 * Deletes backup for the CURRENT user (used during restore_cart flow).
	 *
	 * @param int $user_id Current user ID (0 for guests).
	 */
	private static function delete_backup_storage_for_user( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP );
		}

		// Also clear session backup (covers both logged-in and guest cases).
		Zymarg_Cart_Helpers::get_wc_session()?->set(
			Zymarg_Cart_Helpers::SESSION_KEY_BACKUP,
			null
		);
	}

	/**
	 * Deletes backup for a SPECIFIC customer (used in order hook context
	 * where the current user may not be the customer).
	 *
	 * @param int $customer_id WC customer / WP user ID (0 for guests).
	 */
	private static function delete_backup_storage_for_customer( int $customer_id ): void {
		if ( $customer_id > 0 ) {
			delete_user_meta( $customer_id, Zymarg_Cart_Helpers::USERMETA_KEY_BACKUP );
		}

		// For guest orders: order hooks fire in the customer's session context.
		Zymarg_Cart_Helpers::get_wc_session()?->set(
			Zymarg_Cart_Helpers::SESSION_KEY_BACKUP,
			null
		);
	}

	// =========================================================================
	// PRIVATE — CART ITEM HELPERS
	// =========================================================================

	/**
	 * Serialises a WC cart item into a plain array safe for session/meta storage.
	 * Removes the WC_Product data object and computed line-total fields.
	 *
	 * @param string               $cart_key  WC cart item key.
	 * @param array<string, mixed> $cart_item Raw WC cart item array.
	 *
	 * @return array<string, mixed>
	 */
	private static function serialize_cart_item( string $cart_key, array $cart_item ): array {
		// Keys that WC re-calculates on add_to_cart — no need to store.
		static $skip = [
			'data',               // WC_Product object — NOT serializable.
			'line_total',
			'line_tax',
			'line_subtotal',
			'line_subtotal_tax',
			'line_tax_data',
		];

		// Collect extra cart_item_data (custom meta from third-party plugins).
		$extra = [];
		foreach ( $cart_item as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			// Skip the four standard WC keys; we store them explicitly below.
			if ( in_array( $key, [ 'product_id', 'variation_id', 'quantity', 'variation' ], true ) ) {
				continue;
			}

			// Drop any values that are objects (cannot be serialised to session/meta).
			if ( is_object( $value ) ) {
				continue;
			}

			$extra[ $key ] = $value;
		}

		return [
			'cart_key'       => $cart_key,
			'product_id'     => (int) ( $cart_item['product_id']   ?? 0 ),
			'variation_id'   => (int) ( $cart_item['variation_id'] ?? 0 ),
			'quantity'       => (int) ( $cart_item['quantity']     ?? 1 ),
			'variation'      => (array) ( $cart_item['variation']  ?? [] ),
			'cart_item_data' => $extra,
		];
	}

	/**
	 * Adds a collection of serialised cart items back to the WC cart.
	 * Skips any item whose key is already present in the active cart.
	 *
	 * @param array<int|string, array<string, mixed>> $items Items to add.
	 */
	private static function add_items_to_cart( array $items ): void {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() || empty( $items ) ) {
			return;
		}

		$cart         = WC()->cart;
		$current_keys = array_keys( $cart->get_cart() );

		foreach ( $items as $item ) {
			// Skip if already in cart by key.
			$item_key = $item['cart_key'] ?? '';
			if ( $item_key !== '' && in_array( $item_key, $current_keys, true ) ) {
				continue;
			}

			$cart->add_to_cart(
				(int) ( $item['product_id']    ?? 0 ),
				(int) ( $item['quantity']      ?? 1 ),
				(int) ( $item['variation_id']  ?? 0 ),
				(array) ( $item['variation']   ?? [] ),
				(array) ( $item['cart_item_data'] ?? [] )
			);
		}

		$cart->calculate_totals();
	}

	/**
	 * Filters a backup items array to return only items that were NOT selected
	 * for checkout (i.e. the items that need to be preserved).
	 *
	 * @param array<string, array<string, mixed>> $all_items     All backed-up items.
	 * @param array<int, string>                  $selected_keys Keys that were checked out.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filter_unselected_items(
		array $all_items,
		array $selected_keys
	): array {
		return array_filter(
			$all_items,
			static fn( string $key ): bool => ! in_array( $key, $selected_keys, true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	// =========================================================================
	// PRIVATE — UTILITIES
	// =========================================================================

	/**
	 * Returns a unique string key for the current user suitable for use in
	 * transient names. Logged-in: "u_{user_id}". Guest: "s_{hash}".
	 *
	 * @return string
	 */
	private static function current_user_key(): string {
		if ( is_user_logged_in() ) {
			return 'u_' . get_current_user_id();
		}

		$session = Zymarg_Cart_Helpers::get_wc_session();
		$sess_id = $session ? $session->get_customer_id() : uniqid( 'anon_', true );

		return 's_' . substr( md5( (string) $sess_id ), 0, 12 );
	}

	/**
	 * Returns true if the current logged-in user has a pending restore queue.
	 *
	 * @return bool
	 */
	private static function has_pending_restore(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$pending = get_user_meta( get_current_user_id(), self::USERMETA_KEY_PENDING, true );
		return ! empty( $pending ) && is_array( $pending );
	}
}
