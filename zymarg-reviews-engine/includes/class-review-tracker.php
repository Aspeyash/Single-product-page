<?php
/**
 * Embedded Reviews — Review_Tracker (re-namespaced from ZymargReviews).
 * Exact logic from ZYMARG Reviews v1.1.2 class-review-tracker.php.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Review_Tracker {

	const META_SUBMITTED       = '_zymarg_review_submitted';
	const META_COMMENT_ID      = '_zymarg_review_comment_id';
	const COMMENT_META_FLAG    = '_zymarg_review';
	const COMMENT_META_ORDER_ID= '_zymarg_order_id';
	const COMMENT_META_ITEM_ID = '_zymarg_order_item_id';
	const COMMENT_META_MEDIA   = '_zymarg_review_media';

	public static function is_item_reviewed( $order_item_id ): bool {
		return 'yes' === wc_get_order_item_meta( $order_item_id, self::META_SUBMITTED, true );
	}

	public static function mark_item_reviewed( $order_item_id, int $comment_id ): void {
		wc_update_order_item_meta( $order_item_id, self::META_SUBMITTED, 'yes' );
		wc_update_order_item_meta( $order_item_id, self::META_COMMENT_ID, $comment_id );
	}

	public static function is_within_window( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$completed_date = $order->get_date_completed();
		if ( ! $completed_date ) {
			return false;
		}
		$window_days = (int) Settings::get( 'review_window_days', 15 );
		$diff_days   = ( current_time( 'timestamp', true ) - $completed_date->getTimestamp() ) / DAY_IN_SECONDS;
		return $diff_days <= $window_days;
	}

	public static function get_review_url( int $product_id, int $order_id, int $order_item_id ): string {
		$product = wc_get_product( $product_id );
		if ( ! $product ) return '';
		$base = get_permalink( $product->get_id() );
		if ( ! $base ) return '';
		$url = add_query_arg( [
			'zymarg_review' => 1,
			'order_id'      => $order_id,
			'item_id'       => $order_item_id,
			'_nonce'        => wp_create_nonce( 'zymarg_review_' . $order_item_id ),
		], $base );
		return $url . '#zymarg-write-review';
	}

	public static function verify_url_nonce( string $nonce, int $order_item_id ): bool {
		return (bool) wp_verify_nonce( $nonce, 'zymarg_review_' . $order_item_id );
	}

	public static function evaluate_request( int $product_id ): array {
		$result = [ 'reveal' => false, 'order_id' => 0, 'order_item_id' => 0 ];
		if ( empty( $_GET['zymarg_review'] ) ) { // phpcs:ignore
			return $result;
		}
		$order_id      = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_item_id = isset( $_GET['item_id'] )  ? absint( $_GET['item_id'] )  : 0;
		$nonce         = isset( $_GET['_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ) : '';
		if ( ! $order_id || ! $order_item_id || ! $nonce ) return $result;
		if ( ! self::verify_url_nonce( $nonce, $order_item_id ) ) return $result;
		if ( ! is_user_logged_in() ) return $result;
		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) return $result;
		if ( ! $order->has_status( 'completed' ) || ! self::is_within_window( $order ) ) return $result;
		if ( self::is_item_reviewed( $order_item_id ) ) return $result;
		$item = $order->get_item( $order_item_id );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) return $result;
		if ( (int) $item->get_product_id() !== $product_id ) return $result;
		$result['reveal']        = true;
		$result['order_id']      = $order_id;
		$result['order_item_id'] = $order_item_id;
		return $result;
	}
}
