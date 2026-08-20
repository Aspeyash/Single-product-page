<?php
/**
 * WooCommerce session storage for ZYMARG Cart — guest users.
 *
 * Stores the Save-for-Later list in the WooCommerce session so guest customers
 * can save items without being logged in. Data persists for the lifetime of
 * the WC session (default 48 hours on most hosts).
 *
 * All public methods are static so they can be called from AJAX handlers,
 * templates, and the merge class without needing an instance.
 *
 * Data structure for each saved item (stored as an associative array keyed
 * by the item's MD5 hash):
 *
 * [
 *   'item_key'      => string,  // MD5( product_id|variation_id|variation )
 *   'cart_item_key' => string,  // Original WC cart item key (for reference)
 *   'product_id'    => int,
 *   'variation_id'  => int,     // 0 for simple / non-variable products
 *   'quantity'      => int,
 *   'variation'     => array,   // e.g. ['attribute_pa_color' => 'red']
 *   'saved_at'      => int,     // Unix timestamp
 *   'saved_price'   => float,   // Price at the moment of saving
 *   'current_price' => float,   // Same as saved_price on first save
 *   'price_changed' => bool,    // Updated by update_prices()
 * ]
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Session {

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Core CRUD methods.
	// -------------------------------------------------------------------------

	/**
	 * Saves a cart item to the session Save-for-Later list.
	 *
	 * If the item key already exists the existing entry is left intact
	 * (no duplicates). Returns the item key on success or false on failure
	 * (e.g. session unavailable, max limit reached).
	 *
	 * @param int                  $product_id    Parent product ID.
	 * @param int                  $variation_id  Variation ID (0 for simple).
	 * @param int                  $quantity      Quantity to save.
	 * @param array<string,string> $variation     Variation attribute array.
	 * @param string               $cart_item_key Original WC cart item key.
	 * @param float                $saved_price   Unit price at time of saving.
	 *
	 * @return string|false Item key on success, false on failure.
	 */
	public static function save_item(
		int $product_id,
		int $variation_id  = 0,
		int $quantity       = 1,
		array $variation    = [],
		string $cart_item_key = '',
		float $saved_price  = 0.0
	): string|false {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			Zymarg_Cart_Helpers::log( 'Session::save_item — WC session unavailable.', null, 'warning' );
			return false;
		}

		if ( $product_id <= 0 ) {
			return false;
		}

		$saved    = self::get_saved_items();
		$item_key = Zymarg_Cart_Helpers::generate_item_key( $product_id, $variation_id, $variation );

		// Already saved — do not duplicate, return existing key.
		if ( isset( $saved[ $item_key ] ) ) {
			return $item_key;
		}

		// Enforce max-items limit.
		$max = Zymarg_Cart_Helpers::get_max_saved_items();
		if ( count( $saved ) >= $max ) {
			Zymarg_Cart_Helpers::log(
				'Session::save_item — max saved-items limit reached.',
				[ 'limit' => $max ],
				'warning'
			);
			return false;
		}

		// Resolve current price if not supplied.
		if ( $saved_price <= 0.0 ) {
			$price_id    = $variation_id > 0 ? $variation_id : $product_id;
			$product_obj = wc_get_product( $price_id );
			$saved_price = $product_obj instanceof \WC_Product
				? (float) $product_obj->get_price()
				: 0.0;
		}

		$saved[ $item_key ] = [
			'item_key'      => $item_key,
			'cart_item_key' => $cart_item_key,
			'product_id'    => $product_id,
			'variation_id'  => $variation_id,
			'quantity'      => $quantity,
			'variation'     => $variation,
			'saved_at'      => time(),
			'saved_price'   => $saved_price,
			'current_price' => $saved_price,
			'price_changed' => false,
		];

		$session->set( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, $saved );

		Zymarg_Cart_Helpers::log(
			'Session::save_item — item saved.',
			[ 'item_key' => $item_key, 'product_id' => $product_id ]
		);

		do_action( 'zymarg_cart_session_item_saved', $item_key, $saved[ $item_key ] );

		return $item_key;
	}

	/**
	 * Returns all saved items from the session as an associative array keyed
	 * by item key. Returns an empty array if the session is unavailable or
	 * no items have been saved.
	 *
	 * Lazy migration (since v1.1.0): if any item's array-key does not match
	 * the hash that {@see Zymarg_Cart_Helpers::generate_item_key()} produces
	 * for that item's product/variation today, the entire array is re-keyed
	 * and written back. This handles the v1.0.x → v1.1.0 hash format change
	 * (serialize → wp_json_encode) without requiring any cron job or batch
	 * migration. Idempotent and zero-cost for already-migrated data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_saved_items(): array {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return [];
		}

		$saved = $session->get( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, [] );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return [];
		}

		return self::maybe_rekey_items( $session, $saved );
	}

	/**
	 * Re-keys a session saved-items array if any item's stored hash no longer
	 * matches the canonical hash produced by Helpers::generate_item_key().
	 *
	 * No-op when every key already matches (the common path post-migration).
	 *
	 * @param \WC_Session                          $session WC session handle for write-back.
	 * @param array<string, array<string, mixed>>  $saved   Current saved-items array.
	 *
	 * @return array<string, array<string, mixed>> Possibly re-keyed array.
	 */
	private static function maybe_rekey_items( \WC_Session $session, array $saved ): array {
		$needs_rekey = false;
		foreach ( $saved as $key => $item ) {
			$expected = Zymarg_Cart_Helpers::generate_item_key(
				(int) ( $item['product_id'] ?? 0 ),
				(int) ( $item['variation_id'] ?? 0 ),
				(array) ( $item['variation'] ?? [] )
			);
			if ( $expected !== $key ) {
				$needs_rekey = true;
				break;
			}
		}

		if ( ! $needs_rekey ) {
			return $saved;
		}

		$new_saved = [];
		foreach ( $saved as $item ) {
			$new_key = Zymarg_Cart_Helpers::generate_item_key(
				(int) ( $item['product_id'] ?? 0 ),
				(int) ( $item['variation_id'] ?? 0 ),
				(array) ( $item['variation'] ?? [] )
			);
			$item['item_key']        = $new_key;
			$new_saved[ $new_key ]   = $item;
		}

		$session->set( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, $new_saved );

		Zymarg_Cart_Helpers::log(
			'Session::maybe_rekey_items — saved items lazily migrated to v1.1.0 hash format.',
			[ 'count' => count( $new_saved ) ]
		);

		return $new_saved;
	}

	/**
	 * Returns a single saved item by its item key, or null if not found.
	 *
	 * @param string $item_key Item key (MD5 hash).
	 * @return array<string, mixed>|null
	 */
	public static function get_item( string $item_key ): ?array {
		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$saved    = self::get_saved_items();
		return $saved[ $item_key ] ?? null;
	}

	/**
	 * Removes a single item from the session Save-for-Later list.
	 *
	 * @param string $item_key Item key to remove.
	 * @return bool True if the item was found and removed, false otherwise.
	 */
	public static function remove_item( string $item_key ): bool {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return false;
		}

		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$saved    = self::get_saved_items();

		if ( ! isset( $saved[ $item_key ] ) ) {
			return false;
		}

		$removed = $saved[ $item_key ];
		unset( $saved[ $item_key ] );
		$session->set( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, $saved );

		Zymarg_Cart_Helpers::log(
			'Session::remove_item — item removed.',
			[ 'item_key' => $item_key ]
		);

		do_action( 'zymarg_cart_session_item_removed', $item_key, $removed );

		return true;
	}

	/**
	 * Moves a saved item back into the WooCommerce active cart.
	 *
	 * On success: removes the item from the saved list and returns true.
	 * On failure (WC add_to_cart returns false): leaves the saved list intact
	 * and returns false so the UI can show an error message.
	 *
	 * @param string $item_key Item key to move back.
	 * @return bool True on success, false on failure.
	 */
	public static function move_to_cart( string $item_key ): bool {
		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::log( 'Session::move_to_cart — WC cart unavailable.', null, 'warning' );
			return false;
		}

		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$item     = self::get_item( $item_key );

		if ( $item === null ) {
			return false;
		}

		$added = WC()->cart->add_to_cart(
			$item['product_id'],
			$item['quantity'],
			$item['variation_id'],
			$item['variation']
		);

		if ( false === $added ) {
			Zymarg_Cart_Helpers::log(
				'Session::move_to_cart — WC add_to_cart failed.',
				[ 'item_key' => $item_key, 'product_id' => $item['product_id'] ],
				'warning'
			);
			return false;
		}

		self::remove_item( $item_key );

		do_action( 'zymarg_cart_session_item_moved_to_cart', $item_key, $item, $added );

		return true;
	}

	/**
	 * Clears the entire Save-for-Later list from the session.
	 * Called by the merge class after a successful login merge.
	 */
	public static function clear_all(): void {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return;
		}

		$session->set( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, [] );

		Zymarg_Cart_Helpers::log( 'Session::clear_all — saved list cleared.' );

		do_action( 'zymarg_cart_session_cleared' );
	}

	// -------------------------------------------------------------------------
	// Query helpers.
	// -------------------------------------------------------------------------

	/**
	 * Returns the number of items currently in the session saved list.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::get_saved_items() );
	}

	/**
	 * Checks whether a specific product + variation combination is already
	 * saved in the session.
	 *
	 * @param int                  $product_id
	 * @param int                  $variation_id
	 * @param array<string,string> $variation
	 *
	 * @return bool
	 */
	public static function is_saved(
		int $product_id,
		int $variation_id = 0,
		array $variation  = []
	): bool {
		$item_key = Zymarg_Cart_Helpers::generate_item_key( $product_id, $variation_id, $variation );
		return isset( self::get_saved_items()[ $item_key ] );
	}

	// -------------------------------------------------------------------------
	// Price refresh.
	// -------------------------------------------------------------------------

	/**
	 * Refreshes the current_price and price_changed flag for every item in
	 * the session saved list by querying WooCommerce product data.
	 *
	 * Called on cart page load so price badges are always accurate.
	 *
	 * @return array<string, array<string, mixed>> Updated saved items.
	 */
	public static function update_prices(): array {
		$session = Zymarg_Cart_Helpers::get_wc_session();
		if ( ! $session ) {
			return [];
		}

		$saved   = self::get_saved_items();
		$changed = false;

		foreach ( $saved as $key => $item ) {
			$price_id = ( $item['variation_id'] > 0 ) ? $item['variation_id'] : $item['product_id'];
			$product  = wc_get_product( $price_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$current = (float) $product->get_price();
			$saved_p = (float) ( $item['saved_price'] ?? $current );

			$saved[ $key ]['current_price'] = $current;
			$saved[ $key ]['price_changed']  = ( abs( $current - $saved_p ) > 0.001 );
			$changed                          = true;
		}

		if ( $changed ) {
			$session->set( Zymarg_Cart_Helpers::SESSION_KEY_SAVED, $saved );
		}

		return $saved;
	}

	// -------------------------------------------------------------------------
	// Data export for merge.
	// -------------------------------------------------------------------------

	/**
	 * Returns the raw saved-items array for use by the merge class during
	 * the guest-to-logged-in transition. Does NOT clear the list — the merge
	 * class is responsible for calling clear_all() after successful migration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_raw_for_merge(): array {
		return self::get_saved_items();
	}
}
