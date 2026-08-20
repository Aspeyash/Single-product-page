<?php
/**
 * WordPress user-meta storage for ZYMARG Cart — logged-in users.
 *
 * Stores the Save-for-Later list as a serialised array in the WordPress
 * user-meta table under the key '_zymarg_saved_items'. Data persists
 * indefinitely across sessions and devices for the same account.
 *
 * This class mirrors the public API of Zymarg_Cart_Session but operates on
 * user meta rather than WC session, and always requires a $user_id argument.
 *
 * Additional capabilities (not in the session class):
 * - update_item_prices()  — refreshes prices and sets price_changed flag.
 * - check_stock_status()  — returns current stock info for all saved items.
 * - enforce_max_limit()   — trims oldest items when the saved list is over limit.
 *
 * @package ZymargCart
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zymarg_Cart_Usermeta {

	// -------------------------------------------------------------------------
	// Prevent instantiation — static class only.
	// -------------------------------------------------------------------------

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Core CRUD methods.
	// -------------------------------------------------------------------------

	/**
	 * Saves a cart item to the user-meta Save-for-Later list.
	 *
	 * If the item key already exists the existing entry is left intact
	 * (no duplicates). Returns the item key on success or false on failure.
	 *
	 * @param int                  $user_id       WordPress user ID.
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
		int $user_id,
		int $product_id,
		int $variation_id  = 0,
		int $quantity       = 1,
		array $variation    = [],
		string $cart_item_key = '',
		float $saved_price  = 0.0
	): string|false {
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}

		$saved    = self::get_saved_items( $user_id );
		$item_key = Zymarg_Cart_Helpers::generate_item_key( $product_id, $variation_id, $variation );

		// Already saved — do not duplicate, return existing key.
		if ( isset( $saved[ $item_key ] ) ) {
			return $item_key;
		}

		// Enforce max-items limit.
		$max = Zymarg_Cart_Helpers::get_max_saved_items();
		if ( count( $saved ) >= $max ) {
			Zymarg_Cart_Helpers::log(
				'Usermeta::save_item — max saved-items limit reached.',
				[ 'user_id' => $user_id, 'limit' => $max ],
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
			'item_key'            => $item_key,
			'cart_item_key'       => $cart_item_key,
			'product_id'          => $product_id,
			'variation_id'        => $variation_id,
			'quantity'            => $quantity,
			'variation'           => $variation,
			'saved_at'            => time(),
			'saved_price'         => $saved_price,
			'current_price'       => $saved_price,
			'price_changed'       => false,
			'merged_from_session' => false,
			'merged_at'           => null,
		];

		update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $saved );

		Zymarg_Cart_Helpers::log(
			'Usermeta::save_item — item saved.',
			[ 'user_id' => $user_id, 'item_key' => $item_key, 'product_id' => $product_id ]
		);

		do_action( 'zymarg_cart_usermeta_item_saved', $user_id, $item_key, $saved[ $item_key ] );

		return $item_key;
	}

	/**
	 * Returns all saved items for a user as an associative array keyed by
	 * item key. Returns an empty array if no items exist or user ID is invalid.
	 *
	 * Lazy migration (since v1.1.0): if any item's array-key does not match
	 * the hash that {@see Zymarg_Cart_Helpers::generate_item_key()} produces
	 * for that item's product/variation today, the entire array is re-keyed
	 * and written back to user meta. This handles the v1.0.x → v1.1.0 hash
	 * format change (serialize → wp_json_encode) without requiring any cron
	 * job or batch migration. Idempotent and zero-cost for already-migrated
	 * data.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_saved_items( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$saved = get_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, true );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return [];
		}

		return self::maybe_rekey_items( $user_id, $saved );
	}

	/**
	 * Re-keys a user's saved-items array if any item's stored hash no longer
	 * matches the canonical hash produced by Helpers::generate_item_key().
	 *
	 * No-op when every key already matches (the common path post-migration).
	 *
	 * @param int                                  $user_id WordPress user ID.
	 * @param array<string, array<string, mixed>>  $saved   Current saved-items array.
	 *
	 * @return array<string, array<string, mixed>> Possibly re-keyed array.
	 */
	private static function maybe_rekey_items( int $user_id, array $saved ): array {
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
			$item['item_key']      = $new_key;
			$new_saved[ $new_key ] = $item;
		}

		update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $new_saved );

		Zymarg_Cart_Helpers::log(
			'Usermeta::maybe_rekey_items — saved items lazily migrated to v1.1.0 hash format.',
			[ 'user_id' => $user_id, 'count' => count( $new_saved ) ]
		);

		return $new_saved;
	}

	/**
	 * Returns a single saved item for a user by item key, or null if not found.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $item_key Item key (MD5 hash).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_item( int $user_id, string $item_key ): ?array {
		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$saved    = self::get_saved_items( $user_id );
		return $saved[ $item_key ] ?? null;
	}

	/**
	 * Removes a single item from a user's saved list.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $item_key Item key to remove.
	 *
	 * @return bool True if removed, false if not found or user ID invalid.
	 */
	public static function remove_item( int $user_id, string $item_key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$saved    = self::get_saved_items( $user_id );

		if ( ! isset( $saved[ $item_key ] ) ) {
			return false;
		}

		$removed = $saved[ $item_key ];
		unset( $saved[ $item_key ] );

		update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $saved );

		Zymarg_Cart_Helpers::log(
			'Usermeta::remove_item — item removed.',
			[ 'user_id' => $user_id, 'item_key' => $item_key ]
		);

		do_action( 'zymarg_cart_usermeta_item_removed', $user_id, $item_key, $removed );

		return true;
	}

	/**
	 * Moves a saved item back into the WooCommerce active cart.
	 *
	 * On success: removes the item from the saved list and returns true.
	 * On failure: leaves the saved list intact and returns false.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $item_key Item key to move back.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function move_to_cart( int $user_id, string $item_key ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! Zymarg_Cart_Helpers::is_cart_available() ) {
			Zymarg_Cart_Helpers::log( 'Usermeta::move_to_cart — WC cart unavailable.', null, 'warning' );
			return false;
		}

		$item_key = Zymarg_Cart_Helpers::sanitize_item_key( $item_key );
		$item     = self::get_item( $user_id, $item_key );

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
				'Usermeta::move_to_cart — WC add_to_cart failed.',
				[ 'user_id' => $user_id, 'item_key' => $item_key, 'product_id' => $item['product_id'] ],
				'warning'
			);
			return false;
		}

		self::remove_item( $user_id, $item_key );

		do_action( 'zymarg_cart_usermeta_item_moved_to_cart', $user_id, $item_key, $item, $added );

		return true;
	}

	/**
	 * Clears the entire saved list for a user by deleting the user-meta entry.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function clear_all( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED );

		Zymarg_Cart_Helpers::log(
			'Usermeta::clear_all — saved list cleared.',
			[ 'user_id' => $user_id ]
		);

		do_action( 'zymarg_cart_usermeta_cleared', $user_id );
	}

	// -------------------------------------------------------------------------
	// Price refresh.
	// -------------------------------------------------------------------------

	/**
	 * Refreshes the current_price and price_changed flag for every item in a
	 * user's saved list by re-querying WooCommerce product data.
	 *
	 * Intended to be called on cart page load and by a scheduled cron job so
	 * that price-change badges are always accurate.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, array<string, mixed>> Updated saved items.
	 */
	public static function update_item_prices( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$saved   = self::get_saved_items( $user_id );
		$changed = false;

		foreach ( $saved as $key => $item ) {
			$price_id = ( (int) $item['variation_id'] > 0 )
				? (int) $item['variation_id']
				: (int) $item['product_id'];

			$product = wc_get_product( $price_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$current     = (float) $product->get_price();
			$saved_price = (float) ( $item['saved_price'] ?? $current );

			$saved[ $key ]['current_price'] = $current;
			$saved[ $key ]['price_changed']  = ( abs( $current - $saved_price ) > 0.001 );
			$changed                          = true;
		}

		if ( $changed ) {
			update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $saved );
		}

		return $saved;
	}

	// -------------------------------------------------------------------------
	// Stock status.
	// -------------------------------------------------------------------------

	/**
	 * Returns a per-item stock status map for all items in a user's saved list.
	 *
	 * Used by the saved-items template to show "Out of stock" banners and by
	 * the AJAX totals handler to flag unavailable items.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, array{status: string, is_in_stock: bool, qty: int|null, low_stock: bool, insufficient: bool}>
	 */
	public static function check_stock_status( int $user_id ): array {
		$saved    = self::get_saved_items( $user_id );
		$statuses = [];

		foreach ( $saved as $key => $item ) {
			$price_id = ( (int) $item['variation_id'] > 0 )
				? (int) $item['variation_id']
				: (int) $item['product_id'];

			$statuses[ $key ] = Zymarg_Cart_Helpers::get_stock_info(
				$price_id,
				(int) ( $item['quantity'] ?? 1 )
			);
		}

		return $statuses;
	}

	// -------------------------------------------------------------------------
	// Limit enforcement.
	// -------------------------------------------------------------------------

	/**
	 * Trims a user's saved list to the configured maximum by removing the
	 * oldest items (lowest saved_at timestamp) when over the limit.
	 *
	 * Called automatically by the merge class after merging session items so
	 * a user's list never silently exceeds the cap.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function enforce_max_limit( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$saved = self::get_saved_items( $user_id );
		$max   = Zymarg_Cart_Helpers::get_max_saved_items();

		if ( count( $saved ) <= $max ) {
			return;
		}

		// Sort by saved_at ascending — oldest entries first.
		uasort(
			$saved,
			static fn( array $a, array $b ): int =>
				(int) ( $a['saved_at'] ?? 0 ) <=> (int) ( $b['saved_at'] ?? 0 )
		);

		// Keep the newest $max entries.
		$saved = array_slice( $saved, -$max, $max, true );

		update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $saved );

		Zymarg_Cart_Helpers::log(
			'Usermeta::enforce_max_limit — list trimmed.',
			[ 'user_id' => $user_id, 'max' => $max, 'kept' => count( $saved ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Query helpers.
	// -------------------------------------------------------------------------

	/**
	 * Returns the number of items in a user's saved list.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function count( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		return count( self::get_saved_items( $user_id ) );
	}

	/**
	 * Checks whether a specific product + variation combination is already
	 * saved for a user.
	 *
	 * @param int                  $user_id
	 * @param int                  $product_id
	 * @param int                  $variation_id
	 * @param array<string,string> $variation
	 *
	 * @return bool
	 */
	public static function is_saved(
		int $user_id,
		int $product_id,
		int $variation_id = 0,
		array $variation  = []
	): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$item_key = Zymarg_Cart_Helpers::generate_item_key( $product_id, $variation_id, $variation );
		return isset( self::get_saved_items( $user_id )[ $item_key ] );
	}

	// -------------------------------------------------------------------------
	// Merge helper — called by Zymarg_Cart_Merge.
	// -------------------------------------------------------------------------

	/**
	 * Merges a pre-built array of session items directly into a user's saved
	 * list. Used exclusively by Zymarg_Cart_Merge::merge_session_to_usermeta()
	 * so merge logic lives in one place.
	 *
	 * @param int                                   $user_id      WordPress user ID.
	 * @param array<string, array<string, mixed>>   $items_to_add Items to add, keyed by item key.
	 *
	 * @return array{merged: int, skipped: int, over_limit: int} Summary counts.
	 */
	public static function merge_items(
		int $user_id,
		array $items_to_add
	): array {
		if ( $user_id <= 0 || empty( $items_to_add ) ) {
			return [ 'merged' => 0, 'skipped' => 0, 'over_limit' => 0 ];
		}

		$saved      = self::get_saved_items( $user_id );
		$max        = Zymarg_Cart_Helpers::get_max_saved_items();
		$merged     = 0;
		$skipped    = 0;
		$over_limit = 0;

		foreach ( $items_to_add as $key => $item ) {
			// Already in user meta — skip.
			if ( isset( $saved[ $key ] ) ) {
				++$skipped;
				continue;
			}

			// Over limit — stop.
			if ( count( $saved ) >= $max ) {
				++$over_limit;
				continue;
			}

			// Stamp the merged metadata and add.
			$saved[ $key ] = array_merge( $item, [
				'merged_from_session' => true,
				'merged_at'           => time(),
			] );

			++$merged;
		}

		if ( $merged > 0 ) {
			update_user_meta( $user_id, Zymarg_Cart_Helpers::USERMETA_KEY_SAVED, $saved );
		}

		return [
			'merged'     => $merged,
			'skipped'    => $skipped,
			'over_limit' => $over_limit,
		];
	}
}
