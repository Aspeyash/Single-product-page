<?php
/**
 * ZYMARG Reviews — main widget render template.
 *
 * Variables in scope:
 *   $settings   array  Widget settings from Elementor.
 *   $data       array  Resolved review data.
 *   $widget_id  string Elementor widget ID for unique element IDs.
 *
 * @package ZymargReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $settings */
/** @var array $data */
/** @var string $widget_id */

$show_bg          = 'yes' === ( $settings['show_bg_gradient'] ?? 'yes' );
$show_summary     = 'yes' === ( $settings['show_summary'] ?? 'yes' );
$show_breakdown   = 'yes' === ( $settings['show_breakdown_bars'] ?? 'yes' );
$show_filters     = 'yes' === ( $settings['show_filters'] ?? 'yes' );
$show_load_more   = 'yes' === ( $settings['show_load_more'] ?? 'yes' );
$show_verified    = 'yes' === ( $settings['show_verified_badge'] ?? 'yes' );
$show_review_media = 'yes' === ( $settings['show_review_media'] ?? 'yes' );
$enable_schema    = 'yes' === ( $settings['enable_schema'] ?? 'yes' );
$default_sort     = $settings['default_sort'] ?? 'recent';
$per_page         = max( 1, (int) ( $settings['reviews_per_page'] ?? 5 ) );

// Interaction state, all driven by the admin toggles.
//
// Reply permission is product-scoped so the vendor who owns the product counts
// as the seller. On a store-wide feed there is no single product, so only
// site-wide managers get the seller branch there.
$zsp_perms      = '\ZymargReviewsEngine\Permissions';
$reply_product  = (int) ( $data['product_id'] ?? 0 );
$replies_on     = $zsp_perms::replies_enabled();
$can_reply      = $zsp_perms::can_reply( $reply_product );
$reply_nonce    = $can_reply ? wp_create_nonce( 'zymarg_reply_review' ) : '';
$show_reactions = $zsp_perms::show_reaction_buttons();
$can_react      = $zsp_perms::can_react();
$store_name     = get_bloginfo( 'name' );

// Form visibility logic.
$form_mode  = $settings['form_visibility'] ?? 'gated';
$show_form  = false;
if ( 'always' === $form_mode ) {
	$show_form = true;
} elseif ( 'never' === $form_mode ) {
	$show_form = false;
} else { // gated.
	$show_form = ! empty( $data['reveal_form'] );
}

// Compute filled stars (decimal precision via clip-path).
$avg_rating   = max( 0, min( 5, (float) ( $data['avg_rating'] ?? 0 ) ) );
$avg_full     = (int) floor( $avg_rating );
$avg_partial  = $avg_rating - $avg_full;
$count        = (int) ( $data['review_count'] ?? 0 );

// Store-wide scope has no single product, so these keys are absent there.
$brand_text   = ( $data['brand'] ?? '' ) ?: ( $settings['product_brand'] ?? '' );
$title_text   = ( $data['title'] ?? '' ) ?: ( $settings['product_title'] ?? '' );
$price_text   = ( $data['price'] ?? '' ) ?: ( $settings['product_price'] ?? '' );
$image_url    = ( $data['image'] ?? '' ) ?: ( $settings['product_image']['url'] ?? '' );
$summary_h    = $settings['summary_heading'] ?? __( 'Customer Reviews', 'zymarg-reviews' );

// Star helper closures used multiple times below.
$render_stars = function ( $rating, $size_class = '' ) {
	$rating = max( 0, min( 5, (float) $rating ) );
	$full   = (int) floor( $rating );
	$frac   = $rating - $full;
	$out    = '<span class="zymarg-stars ' . esc_attr( $size_class ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: rating */ __( 'Rated %s out of 5', 'zymarg-reviews' ), number_format_i18n( $rating, 1 ) ) ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		if ( $i <= $full ) {
			$out .= \ZymargReviewsEngine\Icons::star_filled();
		} elseif ( $i === $full + 1 && $frac > 0 ) {
			$out .= \ZymargReviewsEngine\Icons::star_partial( round( $frac * 100 ) );
		} else {
			$out .= \ZymargReviewsEngine\Icons::star_empty();
		}
	}
	$out .= '</span>';
	return $out;
};
?>
<div class="zymarg-reviews-widget" id="zymarg-widget-<?php echo esc_attr( $widget_id ); ?>" data-product-id="<?php echo esc_attr( (int) ( $data['product_id'] ?? 0 ) ); ?>">

	<?php if ( $show_bg && ! empty( $settings['gradient_overlay_image']['url'] ) ) : ?>
		<div class="zymarg-bg-decoration" style="background-image: url('<?php echo esc_url( $settings['gradient_overlay_image']['url'] ); ?>');" aria-hidden="true"></div>
	<?php endif; ?>

	<main class="zymarg-container">

		<div class="zymarg-grid">

			<!-- LEFT COLUMN -->
			<div class="zymarg-col zymarg-col-left">
				<div class="zymarg-stack">

					<?php if ( $show_summary ) : ?>
						<section class="zymarg-summary">
							<h2 class="zymarg-section-title"><?php echo esc_html( $summary_h ); ?></h2>

							<div class="zymarg-summary-top">
								<div class="zymarg-avg-rating"><?php echo esc_html( number_format_i18n( $avg_rating, 1 ) ); ?></div>
								<div class="zymarg-summary-stars">
									<?php echo $render_stars( $avg_rating, 'zymarg-stars--summary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( $count ) : ?>
										<span class="zymarg-summary-count">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: total reviews */
													__( 'Based on %s reviews', 'zymarg-reviews' ),
													number_format_i18n( $count )
												)
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<?php if ( $show_breakdown ) : ?>
								<div class="zymarg-bars">
									<?php foreach ( array( 5, 4, 3, 2, 1 ) as $star ) :
										$pct = isset( $data['breakdown'][ $star ] ) ? (float) $data['breakdown'][ $star ] : 0;
										?>
										<div class="zymarg-bar-row">
											<span class="zymarg-bar-label">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: star count */
														__( '%d star', 'zymarg-reviews' ),
														$star
													)
												);
												?>
											</span>
											<div class="zymarg-bar-track">
												<div class="zymarg-bar-fill" style="--zymarg-target-width: <?php echo esc_attr( $pct ); ?>%;"></div>
											</div>
											<span class="zymarg-bar-pct"><?php echo esc_html( number_format_i18n( $pct ) ); ?>%</span>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php
					/*
					 * Write-a-Review form.
					 *
					 * Visibility:
					 * - 'gated': hidden by default, only revealed when the
					 *   request comes from the My Account "Review" button URL.
					 * - 'always': always visible.
					 * - 'never': not rendered at all.
					 */
					if ( 'never' !== $form_mode ) :
						$form_style = $show_form ? '' : 'style="display:none"';
						?>
						<section class="zymarg-form" id="zymarg-write-review" <?php echo $form_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-mode="<?php echo esc_attr( $form_mode ); ?>">
							<h3 class="zymarg-section-title"><?php echo esc_html( $settings['form_heading'] ?? __( 'Write a Review', 'zymarg-reviews' ) ); ?></h3>
							<p class="zymarg-form-sub"><?php echo esc_html( $settings['form_subheading'] ?? '' ); ?></p>

							<form class="zymarg-review-form" novalidate>
								<input type="hidden" name="action" value="zymarg_submit_review">
								<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zymarg_submit_review' ) ); ?>">
								<input type="hidden" name="product_id" value="<?php echo esc_attr( (int) ( $data['product_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="order_id" value="<?php echo esc_attr( (int) ( $data['order_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="order_item_id" value="<?php echo esc_attr( (int) ( $data['order_item_id'] ?? 0 ) ); ?>">
								<input type="hidden" name="url_nonce" value="<?php echo esc_attr( isset( $_GET['_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
								<input type="hidden" name="rating" class="zymarg-rating-value" value="0">

								<div class="zymarg-field">
									<label class="zymarg-form-label"><?php esc_html_e( 'Rating', 'zymarg-reviews' ); ?></label>
									<div class="zymarg-stars zymarg-rating-input" role="radiogroup" aria-label="<?php esc_attr_e( 'Rating', 'zymarg-reviews' ); ?>">
										<?php
										for ( $i = 1; $i <= 5; $i++ ) {
											$label = sprintf(
												/* translators: %d: number of stars */
												_n( '%d star', '%d stars', $i, 'zymarg-reviews' ),
												$i
											);
											echo \ZymargReviewsEngine\Icons::star_input( $i, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										}
										?>
									</div>
								</div>

								<div class="zymarg-field">
									<label class="zymarg-form-label" for="zymarg-body-<?php echo esc_attr( $widget_id ); ?>"><?php esc_html_e( 'Review', 'zymarg-reviews' ); ?></label>
									<textarea id="zymarg-body-<?php echo esc_attr( $widget_id ); ?>" name="review_body" rows="4" placeholder="<?php echo esc_attr( $settings['form_body_placeholder'] ?? '' ); ?>"></textarea>
								</div>

								<?php if ( 'yes' === \ZymargReviewsEngine\Settings::get( 'allow_media_upload', 'yes' ) ) : ?>
									<div class="zymarg-field zymarg-media-field">
										<label class="zymarg-media-button" for="zymarg-media-<?php echo esc_attr( $widget_id ); ?>">
											<?php echo \ZymargReviewsEngine\Icons::add_photo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php esc_html_e( 'Add Media', 'zymarg-reviews' ); ?>
										</label>
										<?php
										// v1.1.17 - accept review video when the setting allows it.
										$zsp_accept = 'image/jpeg,image/png,image/webp,image/gif';
										if ( 'yes' === \ZymargReviewsEngine\Settings::get( 'allow_video_upload', 'yes' ) ) {
											$zsp_accept .= ',video/mp4,video/webm,video/quicktime';
										}
										?>
										<input type="file" id="zymarg-media-<?php echo esc_attr( $widget_id ); ?>" name="media[]" accept="<?php echo esc_attr( $zsp_accept ); ?>" multiple style="display:none">
										<span class="zymarg-media-count"><?php esc_html_e( 'Optional', 'zymarg-reviews' ); ?></span>
									</div>
								<?php endif; ?>

								<button type="submit" class="zymarg-btn-submit">
									<?php echo esc_html( $settings['form_submit_label'] ?? __( 'Submit Review', 'zymarg-reviews' ) ); ?>
								</button>

								<div class="zymarg-form-message" role="status" aria-live="polite" data-success="<?php echo esc_attr( $settings['form_success_message'] ?? '' ); ?>"></div>
							</form>
						</section>
					<?php endif; ?>

				</div>
			</div>

			<!-- RIGHT COLUMN -->
			<div class="zymarg-col zymarg-col-right" id="zymarg-reviews-list-<?php echo esc_attr( $widget_id ); ?>">
				<div class="zymarg-stack">

					<?php
					// v1.1.17 - Customer media strip. Aggregates every photo / video
					// across all approved reviews so shoppers can find them without
					// digging through individual cards.
					$zsp_gallery = $show_review_media ? ( $data['media_gallery'] ?? array() ) : array();
					$zsp_g_total = count( $zsp_gallery );
					$zsp_g_show  = 6;
					?>
					<?php
					// v1.2.0 - the nested, review-grouped payload that drives the
					// two-axis full-screen viewer. The flat $zsp_gallery above is a
					// projection of this same data and only paints the strip, so the
					// two can never disagree.
					$zsp_media_reviews = $show_review_media ? ( $data['media_reviews'] ?? array() ) : array();

					// Every strip tile addresses the viewer by {review_index, media_index}
					// rather than a flat offset, so a tap lands on the exact media of the
					// exact review no matter how the strip is sliced or truncated.
					$zsp_first_r = isset( $zsp_gallery[0]['review_index'] ) ? (int) $zsp_gallery[0]['review_index'] : 0;
					$zsp_first_m = isset( $zsp_gallery[0]['media_index'] ) ? (int) $zsp_gallery[0]['media_index'] : 0;
					?>
					<?php if ( $zsp_g_total > 0 ) : ?>
						<div class="zymarg-media-strip" data-total="<?php echo esc_attr( $zsp_g_total ); ?>">
							<div class="zymarg-media-strip__head">
								<h4 class="zymarg-media-strip__title"><?php esc_html_e( 'Customer photos &amp; videos', 'zymarg-reviews' ); ?></h4>
								<button type="button" class="zymarg-media-strip__all"
									data-review-index="<?php echo esc_attr( $zsp_first_r ); ?>"
									data-media-index="<?php echo esc_attr( $zsp_first_m ); ?>">
									<?php echo esc_html( sprintf( _n( 'See %d', 'See all %d', $zsp_g_total, 'zymarg-reviews' ), $zsp_g_total ) ); ?>
								</button>
							</div>
							<div class="zymarg-media-strip__row">
								<?php foreach ( array_slice( $zsp_gallery, 0, $zsp_g_show ) as $zsp_i => $zsp_item ) :
									$zsp_is_video = 'video' === ( $zsp_item['type'] ?? 'image' );
									$zsp_thumb    = $zsp_item['thumb'] ?? '';
									?>
									<button type="button" class="zymarg-media-strip__tile"
										data-review-index="<?php echo esc_attr( (int) ( $zsp_item['review_index'] ?? 0 ) ); ?>"
										data-media-index="<?php echo esc_attr( (int) ( $zsp_item['media_index'] ?? 0 ) ); ?>"
										aria-label="<?php echo esc_attr( sprintf( __( 'Open customer media from %s', 'zymarg-reviews' ), $zsp_item['name'] ?? '' ) ); ?>">
										<?php if ( $zsp_thumb ) : ?>
											<img loading="lazy" src="<?php echo esc_url( $zsp_thumb ); ?>" alt="">
										<?php else : ?>
											<span class="zymarg-review-photo--blank" aria-hidden="true"></span>
										<?php endif; ?>
										<?php if ( $zsp_is_video ) : ?>
											<span class="zymarg-media-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
											<?php if ( ! empty( $zsp_item['duration'] ) ) : ?>
												<span class="zymarg-media-duration"><?php echo esc_html( $zsp_item['duration'] ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</button>
								<?php endforeach; ?>
								<?php
								if ( $zsp_g_total > $zsp_g_show ) :
									$zsp_more = $zsp_gallery[ $zsp_g_show ] ?? array();
									?>
									<button type="button" class="zymarg-media-strip__tile zymarg-media-strip__tile--more"
										data-review-index="<?php echo esc_attr( (int) ( $zsp_more['review_index'] ?? 0 ) ); ?>"
										data-media-index="<?php echo esc_attr( (int) ( $zsp_more['media_index'] ?? 0 ) ); ?>">
										+<?php echo esc_html( $zsp_g_total - $zsp_g_show ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $zsp_media_reviews ) ) : ?>
						<script type="application/json" class="zymarg-media-reviews-data"><?php
							echo wp_json_encode( $zsp_media_reviews, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?></script>
					<?php endif; ?>

					<?php if ( $show_filters ) : ?>
						<div class="zymarg-filters">
							<div class="zymarg-filter-group">
								<button type="button" class="zymarg-filter-pill is-active" data-filter="all"><?php echo esc_html( $settings['filter_all_label'] ?? __( 'All Reviews', 'zymarg-reviews' ) ); ?></button>
								<button type="button" class="zymarg-filter-pill" data-filter="media">
									<?php echo \ZymargReviewsEngine\Icons::photo_library(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php echo esc_html( $settings['filter_media_label'] ?? __( 'Media Reviews', 'zymarg-reviews' ) ); ?>
								</button>
							</div>
							<div class="zymarg-sort">
								<select class="zymarg-sort-select" aria-label="<?php esc_attr_e( 'Sort reviews', 'zymarg-reviews' ); ?>">
									<option value="recent"  <?php selected( $default_sort, 'recent' ); ?>><?php esc_html_e( 'Most Recent',   'zymarg-reviews' ); ?></option>
									<option value="highest" <?php selected( $default_sort, 'highest' ); ?>><?php esc_html_e( 'Highest Rated', 'zymarg-reviews' ); ?></option>
									<option value="lowest"  <?php selected( $default_sort, 'lowest' ); ?>><?php esc_html_e( 'Lowest Rated',  'zymarg-reviews' ); ?></option>
								</select>
							</div>
						</div>
					<?php endif; ?>

					<?php
					// For manual mode: encode all reviews as JSON for the Load More AJAX endpoint.
					$manual_reviews_json = 'yes' !== ( $data['is_woo'] ?? false ) && ! empty( $data['all_reviews'] )
						? wp_json_encode( $data['all_reviews'] )
						: '[]';
					$total_reviews  = (int) ( $data['total_reviews'] ?? count( $data['reviews'] ) );
					$loaded_initial = count( $data['reviews'] );
					?>
					<div class="zymarg-feed"
						data-product-id="<?php echo esc_attr( (int) ( $data['product_id'] ?? 0 ) ); ?>"
						data-sort="<?php echo esc_attr( $default_sort ); ?>"
						data-manual-reviews="<?php echo esc_attr( $manual_reviews_json ); ?>">
						<?php if ( ! empty( $data['reviews'] ) ) : ?>
							<?php foreach ( $data['reviews'] as $review ) :
								$review_full = (int) $review['rating'];
								$avatar_initials = $review['initials'] ?: 'U';
								$has_media   = $show_review_media && ! empty( $review['media'] );
								// Data_Builder has already pinned seller replies first; an
								// empty set here also covers "replies switched off".
								$replies     = $replies_on ? ( $review['replies'] ?? array() ) : array();
								?>
								<?php
								$vote_nonce   = $can_react ? wp_create_nonce( 'zymarg_review_vote' ) : '';
								$report_nonce = wp_create_nonce( 'zymarg_report_review' );
								$comment_id_v = (int) ( $review['id'] ?? 0 );
								$like_count    = $comment_id_v ? (int) get_comment_meta( $comment_id_v, '_zymarg_likes', true ) : 0;
								$dislike_count = $comment_id_v ? (int) get_comment_meta( $comment_id_v, '_zymarg_dislikes', true ) : 0;
								$user_vote    = '';
								if ( $comment_id_v && is_user_logged_in() ) {
									$votes    = get_comment_meta( $comment_id_v, '_zymarg_votes', true );
									$user_vote = is_array( $votes ) ? ( $votes[ get_current_user_id() ] ?? '' ) : '';
								}
								$reported = $comment_id_v && get_comment_meta( $comment_id_v, '_zymarg_reported_' . get_current_user_id(), true );
								?>
								<article class="zymarg-review-card<?php echo $has_media ? ' has-media' : ''; ?>" data-comment-id="<?php echo esc_attr( $comment_id_v ); ?>">

									<!-- Card header: avatar + meta + three dot menu -->
									<div class="zymarg-review-head">
										<div class="zymarg-review-author">
											<div class="zymarg-avatar"><?php echo esc_html( $avatar_initials ); ?></div>
											<div class="zymarg-review-meta">
												<div class="zymarg-review-name-row">
													<h4 class="zymarg-reviewer-name"><?php echo esc_html( $review['name'] ); ?></h4>
													<span class="zymarg-review-date"><?php echo esc_html( $review['date'] ); ?></span>
												</div>
												<?php
												// Store-wide scope only: build_vendor() attaches product_id/
												// title/url/image to every row since a store feed spans many
												// products. Product scope never sets these keys, so this
												// block stays off there exactly as before.
												if ( ! empty( $review['product_title'] ) ) :
												?>
												<a class="zymarg-review-product" href="<?php echo esc_url( $review['product_url'] ?? '' ); ?>">
													<?php if ( ! empty( $review['product_image'] ) ) : ?>
														<img class="zymarg-review-product__thumb" src="<?php echo esc_url( $review['product_image'] ); ?>" alt="" loading="lazy">
													<?php endif; ?>
													<span class="zymarg-review-product__title"><?php echo esc_html( $review['product_title'] ); ?></span>
												</a>
												<?php endif; ?>
												<?php if ( ! empty( $review['variation'] ) ) : ?>
													<span class="zymarg-review-variation"><?php echo esc_html( $review['variation'] ); ?></span>
												<?php endif; ?>
												<!-- Stars now sit BELOW the date, inside the meta block -->
												<?php echo $render_stars( $review_full ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</div>
										</div>
										<!-- Three dot action menu -->
										<div class="zymarg-card-actions">
											<button type="button" class="zymarg-btn-dots" aria-label="<?php esc_attr_e( 'More actions', 'zymarg-reviews' ); ?>" aria-haspopup="true" aria-expanded="false">
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
														data-comment-id="<?php echo esc_attr( $comment_id_v ); ?>"
														data-nonce="<?php echo esc_attr( $report_nonce ); ?>">
														<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6V20h2v-6h12l-2-4 2-4H6V6H4z"/></svg>
														<?php esc_html_e( 'Report Abuse', 'zymarg-reviews' ); ?>
													</button>
												<?php endif; ?>
											</div>
										</div>
									</div>

									<?php if ( ! empty( $review['body'] ) ) : ?>
										<p class="zymarg-review-body"><?php echo wp_kses_post( $review['body'] ); ?></p>
									<?php endif; ?>

									<?php if ( $has_media ) : ?>
										<div class="zymarg-review-photos">
											<?php foreach ( $review['media'] as $zsp_m ) :
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

									<?php if ( ! empty( $replies ) ) : ?>
										<div class="zymarg-replies">
											<?php foreach ( $replies as $reply ) : ?>
												<div class="zymarg-reply<?php echo ! empty( $reply['is_owner'] ) ? ' zymarg-reply--owner' : ''; ?>" data-reply-id="<?php echo esc_attr( $reply['id'] ?? 0 ); ?>">
													<div class="zymarg-reply-header">
														<span class="zymarg-reply-author">
															<?php echo ! empty( $reply['is_owner'] ) ? esc_html( $store_name ) : esc_html( $reply['author'] ); ?>
														</span>
														<span class="zymarg-reply-date"><?php echo esc_html( $reply['date'] ); ?></span>
														<?php if ( ! empty( $reply['is_owner'] ) ) : ?>
															<span class="zymarg-reply-badge"><?php esc_html_e( 'Store Owner', 'zymarg-reviews' ); ?></span>
														<?php endif; ?>
													</div>
													<?php // Plain text is enforced on save; existing replies keep wp_kses_post. ?>
													<p class="zymarg-reply-body"><?php echo wp_kses_post( $reply['body'] ); ?></p>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>

									<?php
									// Per-review allowance is checked per card, so the form
									// disappears once this user has used up their replies.
									$card_can_reply = $can_reply
										&& ! empty( $review['id'] )
										&& $zsp_perms::can_reply_to( (int) $review['id'], $reply_product );
									?>
									<?php if ( $card_can_reply ) : ?>
										<button type="button" class="zymarg-btn-reply-toggle" data-comment-id="<?php echo esc_attr( $review['id'] ); ?>">
											<?php esc_html_e( 'Reply', 'zymarg-reviews' ); ?>
										</button>
										<div class="zymarg-reply-form-wrap" id="zymarg-reply-form-<?php echo esc_attr( $review['id'] ); ?>">
											<form class="zymarg-reply-form" novalidate>
												<input type="hidden" name="action"      value="zymarg_reply_review">
												<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( $reply_nonce ); ?>">
												<input type="hidden" name="comment_id"  value="<?php echo esc_attr( $review['id'] ); ?>">
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
												data-comment-id="<?php echo esc_attr( $comment_id_v ); ?>"
												data-vote="like"
												data-nonce="<?php echo esc_attr( $vote_nonce ); ?>"
												aria-label="<?php esc_attr_e( 'Helpful', 'zymarg-reviews' ); ?>"
												aria-pressed="<?php echo 'like' === $user_vote ? 'true' : 'false'; ?>">
												<?php if ( 'like' === $user_vote ) : ?>
													<!-- Filled thumb up (active state) -->
													<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
												<?php else : ?>
													<!-- Outline thumb up (default state) -->
													<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 21h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2zM9 9l4.34-4.34L12 10h9v2l-3 7H9V9zM1 9h4v12H1z"/></svg>
												<?php endif; ?>
											</button>
											<span class="zymarg-vote-count zymarg-like-count"<?php echo ( $like_count < 1 && 'like' !== $user_vote ) ? ' style="display:none"' : ''; ?>>
												<?php echo esc_html( sprintf( __( 'Helpful (%d)', 'zymarg-reviews' ), $like_count ) ); ?>
											</span>
											<button type="button"
												class="zymarg-btn-vote zymarg-btn-dislike<?php echo 'dislike' === $user_vote ? ' is-active' : ''; ?>"
												data-comment-id="<?php echo esc_attr( $comment_id_v ); ?>"
												data-vote="dislike"
												data-nonce="<?php echo esc_attr( $vote_nonce ); ?>"
												aria-label="<?php esc_attr_e( 'Not helpful', 'zymarg-reviews' ); ?>"
												aria-pressed="<?php echo 'dislike' === $user_vote ? 'true' : 'false'; ?>">
												<?php if ( 'dislike' === $user_vote ) : ?>
													<!-- Filled thumb down (active state) -->
													<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>
												<?php else : ?>
													<!-- Outline thumb down (default state) -->
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
						<?php else : ?>
							<div class="zymarg-empty">
								<?php esc_html_e( 'No reviews yet.', 'zymarg-reviews' ); ?>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $show_load_more && ! empty( $data['reviews'] ) && $total_reviews > $loaded_initial ) : ?>
						<div class="zymarg-pagination">
							<p class="zymarg-pagination-count">
								<?php
								printf(
									/* translators: 1: loaded count 2: total count */
									esc_html__( 'Showing %1$d of %2$d reviews', 'zymarg-reviews' ),
									$loaded_initial,
									$total_reviews
								);
								?>
							</p>
							<button type="button" class="zymarg-btn-load-more"
								data-page="2"
								data-per-page="<?php echo esc_attr( $per_page ); ?>"
								data-sort="<?php echo esc_attr( $default_sort ); ?>"
								data-total="<?php echo esc_attr( $total_reviews ); ?>">
								<?php echo esc_html( $settings['load_more_label'] ?? __( 'Load more reviews', 'zymarg-reviews' ) ); ?>
							</button>
						</div>
					<?php endif; ?>

				</div>
			</div>

		</div>
	</main>

	<?php
	/*
	 * JSON-LD Product schema for SEO (rich snippets).
	 *
	 * v1.3.2 - explicitly never emitted on a store-wide (vendor) scope.
	 *
	 * This schema block is Product-only: it has always described one
	 * product's own aggregate rating, never a store's. Before this guard,
	 * vendor scope only avoided emitting it by accident -- $title_text falls
	 * back to $settings['product_title'], which is empty outside a product
	 * context, so the "if ( $enable_schema && $title_text )" check below
	 * happened to be false. That was never a deliberate safeguard, and a
	 * future settings change (or a caller that supplies product_title as a
	 * placement override) could silently defeat it.
	 *
	 * Making the guard explicit also documents a decision the site owner
	 * made deliberately: Dokan's own store page may already emit its own
	 * structured data for the vendor as an Organization/Store, and a second,
	 * conflicting AggregateRating node on the same page would be bad for SEO
	 * rather than helpful. A genuine store-wide AggregateRating/Organization
	 * schema is a separate, larger feature (tracked in docs/PHASE-3-PLAN.md
	 * §6) that has not been built -- this guard is not a stand-in for it.
	 */
	$is_vendor_scope = 'vendor' === ( $data['scope'] ?? '' );
	if ( $enable_schema && $title_text && ! $is_vendor_scope ) :
		$schema = array(
			'@context' => 'https://schema.org/',
			'@type'    => 'Product',
			'name'     => $title_text,
		);
		if ( $image_url ) {
			$schema['image'] = $image_url;
		}
		if ( $brand_text ) {
			$schema['brand'] = array( '@type' => 'Brand', 'name' => $brand_text );
		}
		if ( $count > 0 && $avg_rating > 0 ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( $avg_rating, 1, '.', '' ),
				'reviewCount' => $count,
			);
		}
		if ( ! empty( $data['reviews'] ) ) {
			$reviews_schema = array();
			foreach ( $data['reviews'] as $r ) {
				$reviews_schema[] = array(
					'@type'        => 'Review',
					'author'       => array( '@type' => 'Person', 'name' => $r['name'] ),
					// ISO 8601, not the dd/mm/yyyy display string in $r['date'] -
					// schema.org / Google rich-result validation requires it.
					'datePublished'=> $r['date_iso'] ?? $r['date'],
					'reviewBody'   => wp_strip_all_tags( $r['body'] ),
					'reviewRating' => array(
						'@type'       => 'Rating',
						'ratingValue' => (string) $r['rating'],
						'bestRating'  => '5',
						'worstRating' => '1',
					),
				);
			}
			$schema['review'] = $reviews_schema;
		}
		?>
		<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
	<?php endif; ?>

</div>
