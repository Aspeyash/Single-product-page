<?php
/**
 * The "Write a Review" entry point in My Account.
 *
 * Review_Tracker already builds the signed, per-order-item review URL and
 * Review_Tracker::evaluate_request() already validates it, but nothing ever
 * handed that URL to the buyer, so get_review_url() had no callers and the
 * reviews_form_button_label / _done settings had no readers. This class is
 * that missing link.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Account_Button {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Per line item, on the My Account order detail screen.
		add_action( 'woocommerce_order_item_meta_end', [ $this, 'render_item_button' ], 10, 3 );

		// Per order, on the My Account orders list — buyers land here first.
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'orders_list_action' ], 10, 2 );
	}

	/** Is the entry point switched on? */
	protected function enabled(): bool {
		return (bool) Settings::get( 'reviews_enabled', true )
			&& (bool) Settings::get( 'reviews_my_account_button', true );
	}

	/**
	 * Would evaluate_request() accept a review for this item right now?
	 *
	 * Deliberately mirrors Review_Tracker rather than reimplementing it, so the
	 * button can never appear for an item the gate would reject.
	 *
	 * @param \WC_Order                 $order Order.
	 * @param \WC_Order_Item_Product    $item  Line item.
	 * @return bool
	 */
	protected function item_is_reviewable( $order, $item ): bool {
		if ( ! $order instanceof \WC_Order || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return false;
		}
		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			return false;
		}
		if ( ! $order->has_status( 'completed' ) ) {
			return false;
		}
		if ( ! Review_Tracker::is_within_window( $order ) ) {
			return false;
		}

		$product_id = (int) $item->get_product_id();
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render the button under a line item on the order detail screen.
	 *
	 * woocommerce_order_item_meta_end also fires while order emails render, so
	 * the account-page guard keeps a nonce-bearing link out of email bodies.
	 *
	 * @param int                    $item_id Order item ID.
	 * @param \WC_Order_Item_Product $item    Line item.
	 * @param \WC_Order              $order   Order.
	 */
	public function render_item_button( $item_id, $item, $order ): void {
		if ( ! $this->enabled() || is_admin() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( ! $this->item_is_reviewable( $order, $item ) ) {
			return;
		}

		$item_id = (int) $item_id;

		// Already reviewed: say so, rather than offering a link the gate rejects.
		if ( Review_Tracker::is_item_reviewed( $item_id ) ) {
			printf(
				'<p class="zymarg-review-cta zymarg-review-cta--done"><span class="zymarg-review-cta__done">%s</span></p>',
				esc_html( (string) Settings::get( 'button_label_done', __( 'Review Submitted', 'zymarg-reviews-engine' ) ) )
			);
			return;
		}

		$url = Review_Tracker::get_review_url(
			(int) $item->get_product_id(),
			(int) $order->get_id(),
			$item_id
		);

		if ( ! $url ) {
			return;
		}

		printf(
			'<p class="zymarg-review-cta"><a class="zymarg-review-cta__link button" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html( (string) Settings::get( 'button_label', __( 'Write a Review', 'zymarg-reviews-engine' ) ) )
		);
	}

	/**
	 * Add a "Write a Review" action to the orders list.
	 *
	 * A single order can hold several reviewable items, so this points at the
	 * order detail screen where the per-item buttons live rather than guessing
	 * which product the buyer meant.
	 *
	 * @param array     $actions Existing actions.
	 * @param \WC_Order $order   Order.
	 * @return array
	 */
	public function orders_list_action( $actions, $order ): array {
		$actions = is_array( $actions ) ? $actions : [];

		if ( ! $this->enabled() || ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( ! $order->has_status( 'completed' ) || ! Review_Tracker::is_within_window( $order ) ) {
			return $actions;
		}

		$pending = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $this->item_is_reviewable( $order, $item ) ) {
				continue;
			}
			if ( ! Review_Tracker::is_item_reviewed( (int) $item_id ) ) {
				$pending = true;
				break;
			}
		}

		if ( ! $pending ) {
			return $actions;
		}

		$actions['zymarg_review'] = [
			'url'  => $order->get_view_order_url(),
			'name' => (string) Settings::get( 'button_label', __( 'Write a Review', 'zymarg-reviews-engine' ) ),
		];

		return $actions;
	}
}
