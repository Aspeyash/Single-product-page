<?php
/**
 * Embedded Reviews — Ajax (re-namespaced from ZymargReviews to ZymargReviewsEngine).
 * Identical logic to ZYMARG Reviews v1.1.2 class-ajax.php; only the
 * namespace, class references, and template path constant differ.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_zymarg_submit_review',        [ $this, 'submit_review' ] );
		add_action( 'wp_ajax_zymarg_load_reviews',         [ $this, 'load_reviews' ] );
		add_action( 'wp_ajax_nopriv_zymarg_load_reviews',  [ $this, 'load_reviews' ] );
		add_action( 'wp_ajax_zymarg_reply_review',         [ $this, 'reply_review' ] );
		add_action( 'wp_ajax_zymarg_review_vote',          [ $this, 'review_vote' ] );
		add_action( 'wp_ajax_zymarg_report_review',        [ $this, 'report_review' ] );
	}

	protected function respond( bool $ok, string $message = '', array $extra = [] ): void {
		wp_send_json( array_merge( [ 'success' => $ok, 'message' => $message ], $extra ) );
	}

	// ── Submit Review ────────────────────────────────────────────────────────

	public function submit_review(): void {
		if ( ! check_ajax_referer( 'zymarg_submit_review', '_ajax_nonce', false ) ) {
			$this->respond( false, __( 'Security check failed. Please refresh and try again.', 'zymarg-reviews-engine' ) );
		}
		if ( ! is_user_logged_in() ) {
			$this->respond( false, __( 'Please log in to submit a review.', 'zymarg-reviews-engine' ) );
		}

		$product_id    = isset( $_POST['product_id'] )    ? absint( $_POST['product_id'] )    : 0;
		$order_id      = isset( $_POST['order_id'] )      ? absint( $_POST['order_id'] )      : 0;
		$order_item_id = isset( $_POST['order_item_id'] ) ? absint( $_POST['order_item_id'] ) : 0;
		$url_nonce     = isset( $_POST['url_nonce'] )     ? sanitize_text_field( wp_unslash( $_POST['url_nonce'] ) ) : '';
		$rating        = isset( $_POST['rating'] )        ? max( 1, min( 5, (int) $_POST['rating'] ) ) : 0;
		$body          = isset( $_POST['review_body'] )   ? wp_kses_post( wp_unslash( $_POST['review_body'] ) )   : '';

		if ( ! $product_id || ! $order_id || ! $order_item_id ) {
			$this->respond( false, __( 'Missing required information.', 'zymarg-reviews-engine' ) );
		}
		if ( $rating < 1 ) {
			$this->respond( false, __( 'Please choose a star rating.', 'zymarg-reviews-engine' ) );
		}
		if ( '' === $body ) {
			$this->respond( false, __( 'Please write your review.', 'zymarg-reviews-engine' ) );
		}
		if ( ! Review_Tracker::verify_url_nonce( $url_nonce, $order_item_id ) ) {
			$this->respond( false, __( 'This review link is invalid or has expired.', 'zymarg-reviews-engine' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) $this->respond( false, __( 'Order not found.', 'zymarg-reviews-engine' ) );
		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			$this->respond( false, __( 'You are not allowed to review this order.', 'zymarg-reviews-engine' ) );
		}
		if ( ! $order->has_status( 'completed' ) ) {
			$this->respond( false, __( 'Reviews can only be submitted for completed orders.', 'zymarg-reviews-engine' ) );
		}
		if ( ! Review_Tracker::is_within_window( $order ) ) {
			$this->respond( false, __( 'The review window for this order has passed.', 'zymarg-reviews-engine' ) );
		}
		if ( Review_Tracker::is_item_reviewed( $order_item_id ) ) {
			$this->respond( false, __( 'You have already reviewed this item.', 'zymarg-reviews-engine' ) );
		}

		$item = $order->get_item( $order_item_id );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) || (int) $item->get_product_id() !== $product_id ) {
			$this->respond( false, __( 'Order item / product mismatch.', 'zymarg-reviews-engine' ) );
		}

		// Which variation this review is about, e.g. "Color: Black, Size: M".
		// $item is the exact line item WooCommerce validated above as the
		// customer's own completed purchase, so this is the real attribute/value
		// pair they bought -- not a guess or a stand-in for the current product
		// state, which could have changed since. Simple products have no
		// variation and this correctly resolves to ''.
		$variation_label = Data_Builder::format_order_item_variation( $item );

		$auto_approve = ( 'yes' === Settings::get( 'auto_approve_verified', 'no' ) );
		$user         = wp_get_current_user();

		$comment_id = wp_insert_comment( wp_slash( [
			'comment_post_ID'      => $product_id,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => '',
			'comment_content'      => $body,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => $user->ID,
			'comment_approved'     => $auto_approve ? 1 : 0,
		] ) );

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			$this->respond( false, __( 'Could not save your review. Please try again.', 'zymarg-reviews-engine' ) );
		}

		add_comment_meta( $comment_id, 'rating', $rating, true );
		add_comment_meta( $comment_id, Review_Tracker::COMMENT_META_FLAG,     'yes',       true );
		add_comment_meta( $comment_id, Review_Tracker::COMMENT_META_ORDER_ID, $order_id,   true );
		add_comment_meta( $comment_id, Review_Tracker::COMMENT_META_ITEM_ID,  $order_item_id, true );
		add_comment_meta( $comment_id, 'verified', 1, true );
		if ( '' !== $variation_label ) {
			add_comment_meta( $comment_id, '_zymarg_review_variation', $variation_label, true );
		}

		$media_ids = [];
		if ( 'yes' === Settings::get( 'allow_media_upload', 'yes' ) && ! empty( $_FILES['media'] ) ) {
			$media_ids = $this->handle_media_upload( $_FILES['media'], $comment_id );
			if ( ! empty( $media_ids ) ) {
				update_comment_meta( $comment_id, Review_Tracker::COMMENT_META_MEDIA, $media_ids );
			}
		}

		Review_Tracker::mark_item_reviewed( $order_item_id, (int) $comment_id );
		delete_transient( 'zymarg_breakdown_' . $product_id );

		if ( function_exists( 'wc_get_product' ) ) {
			$wp = wc_get_product( $product_id );
			if ( $wp ) \WC_Comments::clear_transients( $product_id );
		}

		do_action( 'zymarg_reviews_submitted', $comment_id, $product_id, $order_id, $order_item_id );

		$message = $auto_approve
			? __( 'Thanks! Your review has been published.', 'zymarg-reviews-engine' )
			: __( 'Thanks! Your review has been submitted and is awaiting approval.', 'zymarg-reviews-engine' );

		$this->respond( true, $message, [ 'comment_id' => (int) $comment_id, 'rating' => $rating ] );
	}

	// ── Load More Reviews ─────────────────────────────────────────────────────

	public function load_reviews(): void {
		if ( ! check_ajax_referer( 'zymarg_load_reviews', '_ajax_nonce', false ) ) {
			$this->respond( false, __( 'Security check failed.', 'zymarg-reviews-engine' ) );
		}
		// Load More must respect the read gate too, or a guest could page through
		// reviews the first paint refused to show them.
		if ( ! Permissions::can_read() ) {
			$this->respond( false, __( 'Reviews are not available.', 'zymarg-reviews-engine' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		// v1.3.2 - store-wide scope. Takes precedence over product_id, mirroring
		// zymarg_reviews_get_data()/zymarg_reviews_render()'s own precedence rule.
		$vendor_id  = isset( $_POST['vendor_id'] )  ? absint( $_POST['vendor_id'] )  : 0;
		$page       = max( 1, absint( $_POST['page']     ?? 1 ) );
		$per_page   = max( 1, min( 50, absint( $_POST['per_page'] ?? 5 ) ) );
		$filter     = sanitize_key( $_POST['filter'] ?? 'all' );
		$sort       = sanitize_key( $_POST['sort']   ?? 'recent' );

		if ( ! $product_id && ! $vendor_id ) {
			$raw         = wp_unslash( $_POST['manual_reviews'] ?? '[]' ); // phpcs:ignore
			$all_reviews = json_decode( $raw, true ) ?: [];
			$total       = count( $all_reviews );
			$offset      = ( $page - 1 ) * $per_page;
			$slice       = array_slice( $all_reviews, $offset, $per_page );
			$has_more    = ( $offset + $per_page ) < $total;
			ob_start();
			foreach ( $slice as $review ) $this->render_manual_card( $review );
			$html = ob_get_clean();
			$this->respond( true, '', [ 'html' => $html, 'has_more' => $has_more, 'next_page' => $page + 1, 'loaded_count' => $offset + count( $slice ), 'total_count' => $total ] );
		}

		switch ( $sort ) {
			case 'highest': $orderby = 'meta_value_num'; $order = 'DESC'; $meta_key = 'rating'; break;
			case 'lowest':  $orderby = 'meta_value_num'; $order = 'ASC';  $meta_key = 'rating'; break;
			default:        $orderby = 'comment_date';   $order = 'DESC'; $meta_key = '';       break;
		}

		if ( $vendor_id ) {
			// Store-wide feed spans every product this vendor owns -- the same
			// product-resolution rule Data_Builder::build_vendor() uses, so a
			// vendor's "Load More" page always agrees with its first paint.
			$product_ids = Data_Builder::vendor_product_ids( $vendor_id );
			if ( empty( $product_ids ) ) {
				$this->respond( true, '', [ 'html' => '', 'has_more' => false, 'next_page' => $page + 1, 'loaded_count' => 0, 'total_count' => 0 ] );
			}
			$base_args = [ 'post__in' => $product_ids, 'status' => 'approve', 'type' => 'review', 'parent' => 0, 'orderby' => $orderby, 'order' => $order ];
		} else {
			$base_args = [ 'post_id' => $product_id, 'status' => 'approve', 'type' => 'review', 'orderby' => $orderby, 'order' => $order ];
		}
		if ( $meta_key ) $base_args['meta_key'] = $meta_key; // phpcs:ignore
		if ( 'media' === $filter ) {
			$base_args['meta_query'] = [ [ 'key' => Review_Tracker::COMMENT_META_MEDIA, 'compare' => 'EXISTS' ] ]; // phpcs:ignore
		}

		$total_count = (int) get_comments( array_merge( $base_args, [ 'count' => true ] ) );
		$comments    = get_comments( array_merge( $base_args, [ 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page ] ) );

		// Store-wide cards need to say which product each review is about; the
		// partial reads this same-named variable when it is in scope.
		$show_product_context = (bool) $vendor_id;

		ob_start();
		$tpl = ZYMARG_RE_TPL_PATH . 'partials/review-cards-loop.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
		$html = ob_get_clean();

		$loaded_so_far = ( $page - 1 ) * $per_page + count( $comments );
		$this->respond( true, '', [
			'html'         => $html,
			'has_more'     => count( $comments ) === $per_page && $loaded_so_far < $total_count,
			'next_page'    => $page + 1,
			'loaded_count' => $loaded_so_far,
			'total_count'  => $total_count,
			'sort'         => $sort,
		] );
	}

	protected function render_manual_card( array $review ): void {
		$name     = esc_html( $review['name']     ?? '' );
		$initials = esc_html( $review['initials'] ?? 'U' );
		$date     = esc_html( $review['date']     ?? '' );
		$rating   = max( 1, min( 5, (int) ( $review['rating'] ?? 5 ) ) );
		$body     = wp_kses_post( $review['body'] ?? '' );
		$verified = ! empty( $review['verified'] );
		$media    = is_array( $review['media'] ?? null ) ? $review['media'] : [];
		?>
		<article class="zymarg-review-card<?php echo $media ? ' has-media' : ''; ?>">
			<div class="zymarg-review-head">
				<div class="zymarg-review-author">
					<div class="zymarg-avatar"><?php echo $initials; // phpcs:ignore ?></div>
					<div class="zymarg-review-meta">
						<div class="zymarg-review-name-row">
							<h4 class="zymarg-reviewer-name"><?php echo $name; // phpcs:ignore ?></h4>
							<span class="zymarg-review-date"><?php echo $date; // phpcs:ignore ?></span>
							<?php if ( $verified ) : ?>
								<span class="zymarg-verified-badge" title="<?php esc_attr_e( 'Verified Buyer', 'zymarg-reviews-engine' ); ?>"><?php echo Icons::verified(); // phpcs:ignore ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<span class="zymarg-stars" aria-label="<?php echo esc_attr( sprintf( __( 'Rated %d out of 5', 'zymarg-reviews-engine' ), $rating ) ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) echo $i <= $rating ? Icons::star_filled() : Icons::star_empty(); // phpcs:ignore ?>
				</span>
			</div>
			<?php if ( $body  ) : ?><p class="zymarg-review-body"><?php echo $body;  // phpcs:ignore ?></p><?php endif; ?>
			<?php if ( $media ) : ?>
				<div class="zymarg-review-photos">
					<?php foreach ( $media as $item ) :
						// v1.1.17 - manual-mode JSON may still carry bare URL strings.
						$m        = is_array( $item ) ? $item : [ 'type' => 'image', 'url' => (string) $item, 'thumb' => (string) $item, 'id' => 0 ];
						$is_video = 'video' === ( $m['type'] ?? 'image' );
						$thumb    = $m['thumb'] ?? '';
						?>
						<button type="button" class="zymarg-review-media zymarg-review-media--<?php echo $is_video ? 'video' : 'image'; ?>" data-media-id="<?php echo esc_attr( (int) ( $m['id'] ?? 0 ) ); ?>">
							<?php if ( $thumb ) : ?>
								<img loading="lazy" class="zymarg-review-photo" src="<?php echo esc_url( $thumb ); ?>" alt="">
							<?php else : ?>
								<span class="zymarg-review-photo zymarg-review-photo--blank" aria-hidden="true"></span>
							<?php endif; ?>
							<?php if ( $is_video ) : ?>
								<span class="zymarg-media-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</article>
		<?php
	}

	// ── Reply ─────────────────────────────────────────────────────────────────

	public function reply_review(): void {
		if ( ! check_ajax_referer( 'zymarg_reply_review', '_ajax_nonce', false ) ) {
			$this->respond( false, __( 'Security check failed.', 'zymarg-reviews-engine' ) );
		}

		$parent_id = absint( $_POST['comment_id'] ?? 0 );
		if ( ! $parent_id ) {
			$this->respond( false, __( 'Missing data.', 'zymarg-reviews-engine' ) );
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent || 'review' !== $parent->comment_type ) {
			$this->respond( false, __( 'Review not found.', 'zymarg-reviews-engine' ) );
		}

		// One level only. Replies attach to reviews, never to other replies.
		if ( (int) $parent->comment_parent !== 0 ) {
			$this->respond( false, __( 'You can only reply to a review.', 'zymarg-reviews-engine' ) );
		}

		$product_id  = (int) $parent->comment_post_ID;
		$is_seller   = Permissions::can_seller_reply( $product_id );
		$is_customer = Permissions::can_customer_reply( $product_id );

		if ( ! $is_seller && ! $is_customer ) {
			$this->respond( false, __( 'Replies are not available.', 'zymarg-reviews-engine' ) );
		}

		// Replies are plain text by design, for both sellers and customers.
		$body = sanitize_textarea_field( wp_unslash( $_POST['reply_body'] ?? '' ) );
		if ( '' === trim( $body ) ) {
			$this->respond( false, __( 'Please write a reply.', 'zymarg-reviews-engine' ) );
		}

		// Per-review allowance. Checked here as well as in the template, because
		// the template only decides what to draw.
		if ( Permissions::reply_cap_reached( $parent_id, $is_seller ) ) {
			$cap = Permissions::replies_per_review_cap( $is_seller );
			$this->respond(
				false,
				sprintf(
					/* translators: %d: number of replies allowed per review. */
					_n(
						'You can only leave %d reply on a review.',
						'You can only leave %d replies on a review.',
						$cap,
						'zymarg-reviews-engine'
					),
					$cap
				)
			);
		}

		// Flood guard. Applies to customers, and to sellers only if the admin
		// has opted in.
		if ( Permissions::rate_limit_applies( $is_seller ) && ! $this->reply_rate_ok( get_current_user_id() ) ) {
			$this->respond( false, __( 'You are replying too quickly. Please wait a moment.', 'zymarg-reviews-engine' ) );
		}

		$user = wp_get_current_user();

		// Seller replies publish immediately; customer replies follow the
		// moderation setting.
		$approved = 1;
		if ( ! $is_seller && 'hold' === (string) Settings::get( 'reviews_customer_reply_moderation', 'publish' ) ) {
			$approved = 0;
		}

		$author = $is_seller
			? ( $user->display_name ?: get_bloginfo( 'name' ) )
			: ( $user->display_name ?: __( 'Customer', 'zymarg-reviews-engine' ) );

		$reply_id = wp_insert_comment( wp_slash( [
			'comment_post_ID'      => $product_id,
			'comment_author'       => $author,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => '',
			'comment_content'      => $body,
			'comment_type'         => 'review',
			'comment_parent'       => $parent_id,
			'user_id'              => $user->ID,
			'comment_approved'     => $approved,
		] ) );

		if ( ! $reply_id || is_wp_error( $reply_id ) ) {
			$this->respond( false, __( 'Could not save reply.', 'zymarg-reviews-engine' ) );
		}

		// The flag is what marks a reply as store-side: it drives the badge, the
		// store name and the pinned position. Customer replies must not carry it.
		if ( $is_seller ) {
			add_comment_meta( $reply_id, '_zymarg_store_reply', 'yes', true );
		}

		do_action( 'zymarg_review_reply_saved', $reply_id, $parent_id, $is_seller );

		// Held replies are not public yet, so send no markup to inject.
		if ( ! $approved ) {
			$this->respond(
				true,
				__( 'Thanks! Your reply has been submitted and is awaiting approval.', 'zymarg-reviews-engine' ),
				[ 'reply_id' => (int) $reply_id, 'html' => '', 'is_owner' => false, 'approved' => false ]
			);
		}

		$display_name = $is_seller ? get_bloginfo( 'name' ) : $author;

		ob_start();
		?>
		<div class="zymarg-reply<?php echo $is_seller ? ' zymarg-reply--owner' : ''; ?>" data-reply-id="<?php echo esc_attr( $reply_id ); ?>">
			<div class="zymarg-reply-header">
				<span class="zymarg-reply-author"><?php echo esc_html( $display_name ); ?></span>
				<span class="zymarg-reply-date"><?php echo esc_html( date_i18n( 'd/m/Y' ) ); ?></span>
				<?php if ( $is_seller ) : ?>
					<span class="zymarg-reply-badge"><?php esc_html_e( 'Store Owner', 'zymarg-reviews-engine' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="zymarg-reply-body"><?php echo wp_kses_post( $body ); ?></p>
		</div>
		<?php
		$html = ob_get_clean();

		$this->respond(
			true,
			__( 'Reply posted.', 'zymarg-reviews-engine' ),
			[
				'reply_id' => (int) $reply_id,
				'html'     => $html,
				'is_owner' => $is_seller,
				'approved' => true,
			]
		);
	}

	/**
	 * Allow at most 5 customer replies per user per 10 minutes.
	 *
	 * @param int $user_id Current user ID.
	 * @return bool
	 */
	protected function reply_rate_ok( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$limit   = max( 0, (int) Settings::get( 'reviews_reply_rate_limit', 5 ) );
		$minutes = max( 1, (int) Settings::get( 'reviews_reply_rate_minutes', 10 ) );

		/**
		 * Filter the reply ceiling per window.
		 *
		 * @param int $limit   Replies allowed per window.
		 * @param int $user_id Current user ID.
		 */
		$limit = (int) apply_filters( 'zymarg_reviews_reply_rate_limit', $limit, $user_id );

		if ( $limit < 1 ) {
			return true; // Guard disabled.
		}

		$key   = 'zymarg_reply_rate_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $minutes * MINUTE_IN_SECONDS );
		return true;
	}

	// ── Vote ──────────────────────────────────────────────────────────────────

	public function review_vote(): void {
		if ( ! check_ajax_referer( 'zymarg_review_vote', '_ajax_nonce', false ) || ! is_user_logged_in() ) {
			$this->respond( false, __( 'Unauthorized.', 'zymarg-reviews-engine' ) );
		}
		// The buttons can be hidden in the templates, but this endpoint stays
		// reachable, so the toggle has to be honoured here as well.
		if ( ! Permissions::can_react() ) {
			$this->respond( false, __( 'Reactions are turned off.', 'zymarg-reviews-engine' ) );
		}
		$comment_id = absint( $_POST['comment_id'] ?? 0 );
		$vote       = sanitize_key( $_POST['vote'] ?? '' );
		if ( ! $comment_id || ! in_array( $vote, [ 'like', 'dislike', 'remove' ], true ) ) {
			$this->respond( false, __( 'Invalid.', 'zymarg-reviews-engine' ) );
		}
		$uid           = get_current_user_id();
		$votes         = get_comment_meta( $comment_id, '_zymarg_votes', true );
		$votes         = is_array( $votes ) ? $votes : [];
		$prev          = $votes[ $uid ] ?? '';
		$likes         = (int) get_comment_meta( $comment_id, '_zymarg_likes',    true );
		$dislikes      = (int) get_comment_meta( $comment_id, '_zymarg_dislikes', true );
		if ( 'like'    === $prev ) $likes    = max( 0, $likes    - 1 );
		if ( 'dislike' === $prev ) $dislikes = max( 0, $dislikes - 1 );
		if ( 'remove' === $vote || $vote === $prev ) {
			unset( $votes[ $uid ] ); $new_vote = '';
		} else {
			$votes[ $uid ] = $vote; $new_vote = $vote;
			if ( 'like'    === $vote ) $likes++;
			if ( 'dislike' === $vote ) $dislikes++;
		}
		update_comment_meta( $comment_id, '_zymarg_votes',    $votes );
		update_comment_meta( $comment_id, '_zymarg_likes',    max( 0, $likes ) );
		update_comment_meta( $comment_id, '_zymarg_dislikes', max( 0, $dislikes ) );
		$this->respond( true, '', [ 'vote' => $new_vote, 'likes' => max( 0, $likes ), 'dislikes' => max( 0, $dislikes ) ] );
	}

	// ── Report ────────────────────────────────────────────────────────────────

	public function report_review(): void {
		if ( ! check_ajax_referer( 'zymarg_report_review', '_ajax_nonce', false ) || ! is_user_logged_in() ) {
			$this->respond( false, __( 'Unauthorized.', 'zymarg-reviews-engine' ) );
		}
		$comment_id = absint( $_POST['comment_id'] ?? 0 );
		if ( ! $comment_id || ! get_comment( $comment_id ) ) {
			$this->respond( false, __( 'Invalid review.', 'zymarg-reviews-engine' ) );
		}
		$uid      = get_current_user_id();
		$meta_key = '_zymarg_reported_' . $uid;
		if ( get_comment_meta( $comment_id, $meta_key, true ) ) {
			$this->respond( false, __( 'Already reported.', 'zymarg-reviews-engine' ) );
		}
		add_comment_meta( $comment_id, $meta_key, '1', true );
		$count = (int) get_comment_meta( $comment_id, '_zymarg_report_count', true );
		update_comment_meta( $comment_id, '_zymarg_report_count', $count + 1 );
		do_action( 'zymarg_review_reported', $comment_id, $uid );
		$this->respond( true, __( 'Review reported. Thank you.', 'zymarg-reviews-engine' ) );
	}

	// ── Media upload ──────────────────────────────────────────────────────────

	protected function handle_media_upload( array $files, int $comment_id ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids       = [];
		$max_files = max( 1, (int) Settings::get( 'max_media_files', 4 ) );
		$max_bytes = max( 100, (int) Settings::get( 'max_media_size_kb', 2048 ) ) * 1024;
		// v1.1.17 - review video. Videos get their own (much larger) ceiling.
		$allow_video = 'yes' === Settings::get( 'allow_video_upload', 'yes' );
		$video_bytes = max( 1024, (int) Settings::get( 'max_video_size_kb', 20480 ) ) * 1024;
		$allowed     = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
		$allowed_vid = [ 'video/mp4', 'video/webm', 'video/quicktime' ];
		$norm        = $this->normalize_files( $files );
		foreach ( $norm as $file ) {
			if ( count( $ids ) >= $max_files ) break;
			if ( ! empty( $file['error'] ) ) continue;
			$mime     = strtolower( (string) $file['type'] );
			$is_video = in_array( $mime, $allowed_vid, true );
			if ( $is_video && ! $allow_video ) continue;
			if ( ! $is_video && ! in_array( $mime, $allowed, true ) ) continue;
			if ( $file['size'] > ( $is_video ? $video_bytes : $max_bytes ) ) continue;
			$_FILES['zymarg_upload'] = $file;
			$att_id = media_handle_upload( 'zymarg_upload', 0 );
			unset( $_FILES['zymarg_upload'] );
			if ( ! is_wp_error( $att_id ) ) $ids[] = (int) $att_id;
		}
		return $ids;
	}

	protected function normalize_files( array $files ): array {
		$out = [];
		if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
			for ( $i = 0, $c = count( $files['name'] ); $i < $c; $i++ ) {
				if ( empty( $files['name'][ $i ] ) ) continue;
				$out[] = [ 'name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i] ];
			}
		} elseif ( isset( $files['name'] ) ) {
			$out[] = $files;
		}
		return $out;
	}
}
