<?php
/**
 * AJAX partial: render a list of review cards.
 *
 * Variables in scope (provided by class-ajax.php):
 *   $comments  WP_Comment[]
 *
 * @package ZymargReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $comments ) ) {
	return;
}

$tracker         = '\ZymargReviewsEngine\Review_Tracker';
$perms           = '\ZymargReviewsEngine\Permissions';

// Interaction state. Reply permission depends on which product the review
// belongs to, so it is resolved per card inside the loop.
$show_reactions  = $perms::show_reaction_buttons();
$can_react       = $perms::can_react();
$replies_on      = $perms::replies_enabled();

$vote_nonce      = $can_react ? wp_create_nonce( 'zymarg_review_vote' ) : '';
$report_nonce    = wp_create_nonce( 'zymarg_report_review' );
$store_name      = get_bloginfo( 'name' );
$current_uid     = get_current_user_id();

// v1.3.2 - store-wide feeds span many products, so each card needs to say
// which product it is about. Set by class-ajax.php's load_reviews() only
// when the request is vendor-scoped; product-scoped requests never define
// this, so the block below stays off there exactly as before.
$show_product_context = isset( $show_product_context ) ? (bool) $show_product_context : false;

foreach ( $comments as $comment ) :
	$cid      = (int) $comment->comment_ID;
	$rating   = max( 1, min( 5, (int) get_comment_meta( $cid, 'rating', true ) ) );
	$verified = function_exists( 'wc_review_is_from_verified_owner' )
		? (bool) wc_review_is_from_verified_owner( $cid ) : false;

	// Likes count + user's own vote.
	$like_count    = (int) get_comment_meta( $cid, '_zymarg_likes', true );
	$dislike_count = (int) get_comment_meta( $cid, '_zymarg_dislikes', true );
	$user_vote  = '';
	if ( $current_uid ) {
		$votes     = get_comment_meta( $cid, '_zymarg_votes', true );
		$user_vote = is_array( $votes ) ? ( $votes[ $current_uid ] ?? '' ) : '';
	}
	$reported = (bool) get_comment_meta( $cid, '_zymarg_reported_' . $current_uid, true );

	// Media.
	$media_ids  = get_comment_meta( $cid, $tracker::COMMENT_META_MEDIA, true );
	$media_urls = array();
	if ( is_array( $media_ids ) ) {
		foreach ( $media_ids as $mid ) {
			// v1.1.17 - structured media records (image / video), not bare URLs.
			$item = \ZymargReviewsEngine\Data_Builder::media_item( (int) $mid );
			if ( ! empty( $item ) ) { $media_urls[] = $item; }
		}
	}

	// Replies. Seller replies are pinned above customer replies when enabled.
	$reply_comments = array();
	if ( $replies_on ) {
		$reply_comments = get_comments( array(
			'parent'  => $cid, 'status' => 'approve',
			'orderby' => 'comment_date', 'order' => 'ASC', 'number' => 10,
		) );
		$reply_comments = $perms::sort_reply_comments( $reply_comments );
	}

	// Who may reply to this particular review, and have they any replies left?
	$card_product_id = (int) $comment->comment_post_ID;
	$can_reply       = $perms::can_reply_to( $cid, $card_product_id );
	$reply_nonce     = $can_reply ? wp_create_nonce( 'zymarg_reply_review' ) : '';

	// Reviewer name: live display_name lookup for registered users, so a name
	// changed in the profile after the review was left is reflected here too.
	// Guests (no user_id on the comment) keep the stored comment_author.
	$review_author = $comment->comment_author;
	if ( ! empty( $comment->user_id ) ) {
		$review_user = get_userdata( (int) $comment->user_id );
		if ( $review_user && '' !== trim( (string) $review_user->display_name ) ) {
			$review_author = $review_user->display_name;
		}
	}

	// Initials.
	$parts    = preg_split( '/\s+/', trim( wp_strip_all_tags( $review_author ) ) );
	$initials = '';
	foreach ( $parts as $p ) {
		if ( '' === $p ) { continue; }
		$initials .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		if ( mb_strlen( $initials ) >= 2 ) { break; }
	}
	$initials = $initials ?: '?';
	?>
	<article class="zymarg-review-card<?php echo ! empty( $media_urls ) ? ' has-media' : ''; ?>" data-comment-id="<?php echo esc_attr( $cid ); ?>">

		<!-- Card header: avatar + meta (name / date / stars) + three dot menu -->
		<div class="zymarg-review-head">
			<div class="zymarg-review-author">
				<div class="zymarg-avatar"><?php echo esc_html( $initials ); ?></div>
				<div class="zymarg-review-meta">
					<div class="zymarg-review-name-row">
						<h4 class="zymarg-reviewer-name"><?php echo esc_html( $review_author ); ?></h4>
						<span class="zymarg-review-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $comment->comment_date ) ) ); ?></span>
					</div>
					<?php if ( $show_product_context ) :
						$zre_product_id = (int) $comment->comment_post_ID;
						$zre_p_title    = get_the_title( $zre_product_id );
						$zre_p_url      = get_permalink( $zre_product_id );
						$zre_p_thumb    = get_the_post_thumbnail_url( $zre_product_id, 'woocommerce_thumbnail' );
						if ( $zre_p_title ) :
						?>
						<a class="zymarg-review-product" href="<?php echo esc_url( $zre_p_url ); ?>">
							<?php if ( $zre_p_thumb ) : ?>
								<img class="zymarg-review-product__thumb" src="<?php echo esc_url( $zre_p_thumb ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<span class="zymarg-review-product__title"><?php echo esc_html( $zre_p_title ); ?></span>
						</a>
						<?php endif; ?>
					<?php endif; ?>
					<?php $variation_name = \ZymargReviewsEngine\Data_Builder::resolve_review_variation( $comment );
					if ( $variation_name ) : ?>
						<span class="zymarg-review-variation"><?php echo esc_html( $variation_name ); ?></span>
					<?php endif; ?>
					<!-- Stars below date + variation -->
					<span class="zymarg-stars" aria-label="<?php echo esc_attr( sprintf( __( 'Rated %d out of 5', 'zymarg-reviews' ), $rating ) ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) :
							echo $i <= $rating
								? \ZymargReviewsEngine\Icons::star_filled()
								: \ZymargReviewsEngine\Icons::star_empty(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						endfor; ?>
					</span>
				</div>
			</div>
			<!-- Three dot menu -->
			<div class="zymarg-card-actions">
				<button type="button" class="zymarg-btn-dots"
					aria-label="<?php esc_attr_e( 'More actions', 'zymarg-reviews' ); ?>"
					aria-haspopup="true" aria-expanded="false">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
				</button>
				<div class="zymarg-dots-dropdown" role="menu">
					<?php if ( $reported ) : ?>
						<span class="zymarg-dots-item zymarg-dots-item--reported">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6V20h2v-6h12l-2-4 2-4H6V6H4z"/></svg>
							<?php esc_html_e( 'Reported', 'zymarg-reviews' ); ?>
						</span>
					<?php else : ?>
						<button type="button" class="zymarg-dots-item zymarg-dots-item--report" role="menuitem"
							data-comment-id="<?php echo esc_attr( $cid ); ?>"
							data-nonce="<?php echo esc_attr( $report_nonce ); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6V20h2v-6h12l-2-4 2-4H6V6H4z"/></svg>
							<?php esc_html_e( 'Report Abuse', 'zymarg-reviews' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<p class="zymarg-review-body"><?php echo wp_kses_post( $comment->comment_content ); ?></p>

		<?php if ( ! empty( $media_urls ) ) : ?>
			<div class="zymarg-review-photos">
				<?php foreach ( $media_urls as $zsp_m ) :
												$zsp_is_video = 'video' === ( $zsp_m['type'] ?? 'image' );
												$zsp_thumb    = $zsp_m['thumb'] ?? '';
												?>
												<button type="button"
													class="zymarg-review-media zymarg-review-media--<?php echo $zsp_is_video ? 'video' : 'image'; ?>"
													data-media-id="<?php echo esc_attr( (int) ( $zsp_m['id'] ?? 0 ) ); ?>"
													aria-label="<?php echo $zsp_is_video ? esc_attr__( 'Play review video', 'zymarg-reviews' ) : esc_attr__( 'View review photo', 'zymarg-reviews' ); ?>">
													<?php if ( $zsp_thumb ) : ?>
														<img loading="lazy" class="zymarg-review-photo" src="<?php echo esc_url( $zsp_thumb ); ?>" alt="">
													<?php else : ?>
														<span class="zymarg-review-photo zymarg-review-photo--blank" aria-hidden="true"></span>
													<?php endif; ?>
													<?php if ( $zsp_is_video ) : ?>
														<span class="zymarg-media-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
														<?php if ( ! empty( $zsp_m['duration'] ) ) : ?>
															<span class="zymarg-media-duration"><?php echo esc_html( $zsp_m['duration'] ); ?></span>
														<?php endif; ?>
													<?php endif; ?>
												</button>
											<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $reply_comments ) ) : ?>
			<div class="zymarg-replies">
				<?php foreach ( $reply_comments as $reply ) :
					$is_owner = (bool) get_comment_meta( $reply->comment_ID, '_zymarg_store_reply', true );

					// Store replies keep the store name. Customer replies resolve
					// live from their current profile display_name, same rule as
					// top-level reviews; guests keep the stored comment_author.
					$reply_author = $reply->comment_author;
					if ( ! $is_owner && ! empty( $reply->user_id ) ) {
						$reply_user = get_userdata( (int) $reply->user_id );
						if ( $reply_user && '' !== trim( (string) $reply_user->display_name ) ) {
							$reply_author = $reply_user->display_name;
						}
					}
					?>
					<div class="zymarg-reply<?php echo $is_owner ? ' zymarg-reply--owner' : ''; ?>">
						<div class="zymarg-reply-header">
							<span class="zymarg-reply-author"><?php echo $is_owner ? esc_html( $store_name ) : esc_html( $reply_author ); ?></span>
							<span class="zymarg-reply-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $reply->comment_date ) ) ); ?></span>
							<?php if ( $is_owner ) : ?>
								<span class="zymarg-reply-badge"><?php esc_html_e( 'Store Owner', 'zymarg-reviews' ); ?></span>
							<?php endif; ?>
						</div>
						<?php // New replies are sanitised to plain text on save. Output stays
						// wp_kses_post so replies stored before 1.0.4 keep rendering as they
						// always did instead of showing their markup as visible text. ?>
						<p class="zymarg-reply-body"><?php echo wp_kses_post( $reply->comment_content ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $can_reply ) : ?>
			<button type="button" class="zymarg-btn-reply-toggle" data-comment-id="<?php echo esc_attr( $cid ); ?>">
				<?php esc_html_e( 'Reply', 'zymarg-reviews' ); ?>
			</button>
			<div class="zymarg-reply-form-wrap" id="zymarg-reply-form-<?php echo esc_attr( $cid ); ?>">
				<form class="zymarg-reply-form" novalidate>
					<input type="hidden" name="action"      value="zymarg_reply_review">
					<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( $reply_nonce ); ?>">
					<input type="hidden" name="comment_id"  value="<?php echo esc_attr( $cid ); ?>">
					<textarea class="zymarg-reply-textarea" name="reply_body" rows="3" placeholder="<?php esc_attr_e( 'Write your reply…', 'zymarg-reviews' ); ?>"></textarea>
					<div class="zymarg-reply-actions">
						<button type="submit" class="zymarg-btn-reply-submit"><?php esc_html_e( 'Post Reply', 'zymarg-reviews' ); ?></button>
						<button type="button" class="zymarg-btn-reply-cancel"><?php esc_html_e( 'Cancel', 'zymarg-reviews' ); ?></button>
					</div>
					<div class="zymarg-reply-msg" role="status" aria-live="polite"></div>
				</form>
			</div>
		<?php endif; ?>

		<!-- Like / Dislike interaction bar -->
		<?php if ( $show_reactions ) : ?>
		<div class="zymarg-interaction-bar"<?php echo $can_react ? '' : ' data-requires-login="1"'; ?>>
			<div class="zymarg-vote-group">
				<button type="button"
					class="zymarg-btn-vote zymarg-btn-like<?php echo 'like' === $user_vote ? ' is-active' : ''; ?>"
					data-comment-id="<?php echo esc_attr( $cid ); ?>"
					data-vote="like"
					data-nonce="<?php echo esc_attr( $vote_nonce ); ?>"
					aria-label="<?php esc_attr_e( 'Helpful', 'zymarg-reviews' ); ?>"
					aria-pressed="<?php echo 'like' === $user_vote ? 'true' : 'false'; ?>">
					<?php if ( 'like' === $user_vote ) : ?>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
					<?php else : ?>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 21h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2zM9 9l4.34-4.34L12 10h9v2l-3 7H9V9zM1 9h4v12H1z"/></svg>
					<?php endif; ?>
				</button>
				<span class="zymarg-vote-count zymarg-like-count"<?php echo ( $like_count < 1 && 'like' !== $user_vote ) ? ' style="display:none"' : ''; ?>>
					<?php if ( $like_count > 0 || 'like' === $user_vote ) :
						echo esc_html( sprintf( __( 'Helpful (%d)', 'zymarg-reviews' ), $like_count ) );
					endif; ?>
				</span>
				<button type="button"
					class="zymarg-btn-vote zymarg-btn-dislike<?php echo 'dislike' === $user_vote ? ' is-active' : ''; ?>"
					data-comment-id="<?php echo esc_attr( $cid ); ?>"
					data-vote="dislike"
					data-nonce="<?php echo esc_attr( $vote_nonce ); ?>"
					aria-label="<?php esc_attr_e( 'Not helpful', 'zymarg-reviews' ); ?>"
					aria-pressed="<?php echo 'dislike' === $user_vote ? 'true' : 'false'; ?>">
					<?php if ( 'dislike' === $user_vote ) : ?>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
					<?php else : ?>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm0 12l-4.34 4.34L12 14H3v-2l3-7h9v10zm4-12h2v12h-2V3z"/></svg>
					<?php endif; ?>
				</button>
				<span class="zymarg-vote-count zymarg-dislike-count"<?php echo ( $dislike_count < 1 && 'dislike' !== $user_vote ) ? ' style="display:none"' : ''; ?>>
					<?php echo esc_html( $dislike_count ); ?>
				</span>
			</div>
		</div>
		<?php endif; ?>

	</article>
<?php endforeach; ?>
