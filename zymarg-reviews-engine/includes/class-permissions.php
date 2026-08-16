<?php
/**
 * Interaction permissions — one place that decides who may read, react and
 * reply, so the templates and the AJAX handlers can never disagree.
 *
 * Hiding a button in a template is cosmetic: wp_ajax_zymarg_review_vote and
 * wp_ajax_zymarg_reply_review stay reachable whatever the markup says. Every
 * check here is therefore called from both sides.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permissions {

	/**
	 * May the current visitor read reviews at all?
	 *
	 * 'logged_in' hides the whole section from guests. The master
	 * reviews_enabled switch is checked separately in zymarg_reviews_render().
	 */
	public static function can_read(): bool {
		$mode = (string) Settings::get( 'reviews_visibility', 'everyone' );

		if ( 'logged_in' === $mode && ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Filter whether the current visitor may read reviews.
		 *
		 * @param bool   $can_read Result so far.
		 * @param string $mode     Configured visibility mode.
		 */
		return (bool) apply_filters( 'zymarg_reviews_can_read', true, $mode );
	}

	// ── Reactions ────────────────────────────────────────────────────────────

	/** Is the reaction (helpful / not helpful) feature switched on? */
	public static function reactions_enabled(): bool {
		return (bool) Settings::get( 'reviews_enable_reactions', true );
	}

	/**
	 * May the current user actually record a reaction?
	 *
	 * Votes are stored per user ID, so a guest has nowhere to store one.
	 */
	public static function can_react(): bool {
		if ( ! self::reactions_enabled() ) {
			return false;
		}

		return (bool) apply_filters( 'zymarg_reviews_can_react', is_user_logged_in() );
	}

	/**
	 * Should the reaction buttons be printed for this visitor?
	 *
	 * Before 1.0.4 the buttons were always printed and the nonce always minted,
	 * so a guest could click "Helpful" and receive a bare "Unauthorized."
	 * 'hide' removes them from guests entirely; 'prompt' keeps them visible but
	 * marked so the script can ask the visitor to log in instead of firing a
	 * request that is guaranteed to fail.
	 */
	public static function show_reaction_buttons(): bool {
		if ( ! self::reactions_enabled() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			return true;
		}

		return 'prompt' === (string) Settings::get( 'reviews_reactions_guests', 'prompt' );
	}

	// ── Replies ──────────────────────────────────────────────────────────────

	/** Is the reply feature switched on at all? */
	public static function replies_enabled(): bool {
		return (bool) Settings::get( 'reviews_enable_replies', true );
	}

	/**
	 * Does the current user hold a store-side role?
	 *
	 * Site-wide managers qualify everywhere. On a multivendor marketplace the
	 * vendor who owns the product is the seller for reviews on that product,
	 * and vendors normally hold neither manage_woocommerce nor
	 * moderate_comments — so ownership of the reviewed product counts too.
	 *
	 * @param int $product_id Product the review belongs to. 0 skips the
	 *                        ownership test and checks manager caps only.
	 */
	public static function is_seller( int $product_id = 0 ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'moderate_comments' ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! $product_id ) {
			return false;
		}

		$is_owner = (int) get_post_field( 'post_author', $product_id ) === $user_id;

		/**
		 * Filter whether the current user is the seller for this product.
		 *
		 * Marketplaces that do not model vendor ownership as post authorship
		 * can override the result here.
		 *
		 * @param bool $is_owner   Result of the post-author test.
		 * @param int  $product_id Product ID.
		 * @param int  $user_id    Current user ID.
		 */
		return (bool) apply_filters( 'zymarg_reviews_is_seller', $is_owner, $product_id, $user_id );
	}

	/** May the current user post a seller reply on this product's reviews? */
	public static function can_seller_reply( int $product_id = 0 ): bool {
		return self::replies_enabled()
			&& (bool) Settings::get( 'reviews_allow_seller_replies', true )
			&& self::is_seller( $product_id );
	}

	/**
	 * May the current user post a plain customer reply?
	 *
	 * Sellers are excluded here so their replies always travel down the seller
	 * branch and get the badge and the pinned position.
	 */
	public static function can_customer_reply( int $product_id = 0 ): bool {
		if ( ! self::replies_enabled() || ! Settings::get( 'reviews_allow_customer_replies', false ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( self::can_seller_reply( $product_id ) ) {
			return false;
		}

		return (bool) apply_filters( 'zymarg_reviews_can_customer_reply', true, $product_id );
	}

	/** May the current user post any kind of reply? */
	public static function can_reply( int $product_id = 0 ): bool {
		return self::can_seller_reply( $product_id ) || self::can_customer_reply( $product_id );
	}

	// ── Reply limits ─────────────────────────────────────────────────────────

	/**
	 * How many replies may this user leave on one review?
	 *
	 * @param bool $is_seller Whether the seller cap applies instead.
	 * @return int Cap, or 0 for unlimited.
	 */
	public static function replies_per_review_cap( bool $is_seller ): int {
		$key = $is_seller
			? 'reviews_seller_replies_per_review'
			: 'reviews_customer_replies_per_review';

		return max( 0, (int) Settings::get( $key, 0 ) );
	}

	/**
	 * How many replies has this user already left on this review?
	 *
	 * Counts held replies too: a reply waiting for approval has been used up,
	 * otherwise a moderated user could queue an unlimited number.
	 *
	 * @param int $review_id Parent review comment ID.
	 * @param int $user_id   User ID.
	 * @return int
	 */
	public static function replies_used( int $review_id, int $user_id ): int {
		if ( ! $review_id || ! $user_id ) {
			return 0;
		}

		return (int) get_comments(
			array(
				'parent'  => $review_id,
				'user_id' => $user_id,
				'type'    => 'review',
				'status'  => 'all',
				'count'   => true,
			)
		);
	}

	/**
	 * Has the current user used up their replies on this review?
	 *
	 * @param int  $review_id Parent review comment ID.
	 * @param bool $is_seller Whether to apply the seller cap.
	 * @return bool
	 */
	public static function reply_cap_reached( int $review_id, bool $is_seller ): bool {
		$cap = self::replies_per_review_cap( $is_seller );
		if ( $cap < 1 ) {
			return false; // Unlimited.
		}

		return self::replies_used( $review_id, get_current_user_id() ) >= $cap;
	}

	/**
	 * May the current user reply to this specific review right now?
	 *
	 * Combines the capability checks with the per-review cap, so the reply form
	 * disappears once a user has used up their allowance instead of offering a
	 * form the handler will reject.
	 *
	 * @param int $review_id  Review comment ID.
	 * @param int $product_id Product the review belongs to.
	 * @return bool
	 */
	public static function can_reply_to( int $review_id, int $product_id = 0 ): bool {
		$is_seller = self::can_seller_reply( $product_id );

		if ( ! $is_seller && ! self::can_customer_reply( $product_id ) ) {
			return false;
		}

		return ! self::reply_cap_reached( $review_id, $is_seller );
	}

	/** Does the flood guard apply to the current user? */
	public static function rate_limit_applies( bool $is_seller ): bool {
		if ( (int) Settings::get( 'reviews_reply_rate_limit', 5 ) < 1 ) {
			return false; // Guard switched off entirely.
		}
		if ( ! $is_seller ) {
			return true;
		}

		return (bool) Settings::get( 'reviews_reply_rate_sellers', false );
	}

	/** Should seller replies be pinned above customer replies? */
	public static function seller_reply_first(): bool {
		return (bool) Settings::get( 'reviews_seller_reply_first', true );
	}

	/**
	 * Order a reply set so seller replies come first, each group still in
	 * chronological order.
	 *
	 * usort is not stable before PHP 8.0 and this plugin requires 8.0, so the
	 * original order inside each group survives.
	 *
	 * @param array $replies   Reply rows.
	 * @param string $flag_key Array key holding the "is a seller reply" flag.
	 * @return array
	 */
	public static function sort_replies( array $replies, string $flag_key = 'is_owner' ): array {
		if ( ! self::seller_reply_first() || count( $replies ) < 2 ) {
			return $replies;
		}

		usort(
			$replies,
			static function ( $a, $b ) use ( $flag_key ) {
				$a_owner = ! empty( $a[ $flag_key ] ) ? 0 : 1;
				$b_owner = ! empty( $b[ $flag_key ] ) ? 0 : 1;
				return $a_owner <=> $b_owner;
			}
		);

		return $replies;
	}

	/**
	 * Same ordering for a list of WP_Comment objects.
	 *
	 * @param array $comments WP_Comment[].
	 * @return array
	 */
	public static function sort_reply_comments( array $comments ): array {
		if ( ! self::seller_reply_first() || count( $comments ) < 2 ) {
			return $comments;
		}

		usort(
			$comments,
			static function ( $a, $b ) {
				$a_owner = get_comment_meta( $a->comment_ID, '_zymarg_store_reply', true ) ? 0 : 1;
				$b_owner = get_comment_meta( $b->comment_ID, '_zymarg_store_reply', true ) ? 0 : 1;
				return $a_owner <=> $b_owner;
			}
		);

		return $comments;
	}
}
