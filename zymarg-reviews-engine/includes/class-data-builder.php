<?php
/**
 * Embedded Reviews — Data Builder.
 *
 * Plain-PHP replacement for the reference Elementor Widget's data layer.
 * Builds the $data + $settings arrays that templates/widget-render.php expects,
 * for Auto mode (the current WooCommerce product) only.
 *
 * Ported from ZYMARG Reviews v1.1.2 Widget::build_woo_data() and helpers,
 * with the Elementor dependency removed. Feature toggles / labels that were
 * Controls come from the engine's own settings store.
 *
 * @version 1.0.1
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Data_Builder {

	/**
	 * Build the widget $settings array (formerly Elementor controls).
	 *
	 * Values are read from the engine's settings store. Boolean toggles
	 * are converted to the 'yes'/'no' strings the template compares against.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$o = self::options();

		$yesno = static function ( $val ): string {
			return ! empty( $val ) ? 'yes' : 'no';
		};

		return array(
			// Display toggles.
			'show_bg_gradient'       => $yesno( $o['reviews_show_bg_gradient'] ),
			'show_summary'           => $yesno( $o['reviews_show_summary'] ),
			'show_breakdown_bars'    => $yesno( $o['reviews_show_breakdown_bars'] ),
			'show_filters'           => $yesno( $o['reviews_show_filters'] ),
			'show_load_more'         => $yesno( $o['reviews_show_load_more'] ),
			'show_verified_badge'    => $yesno( $o['reviews_show_verified_badge'] ),
			'show_review_media'      => $yesno( $o['reviews_show_media'] ),
			'enable_schema'          => $yesno( $o['reviews_enable_schema'] ),

			// Interactions. Resolved per request because they depend on the
			// current user, not only on the stored option.
			'enable_reactions'       => $yesno( Permissions::reactions_enabled() ),
			'show_reactions'         => $yesno( Permissions::show_reaction_buttons() ),
			'can_react'              => $yesno( Permissions::can_react() ),
			'enable_replies'         => $yesno( Permissions::replies_enabled() ),
			'seller_reply_first'     => $yesno( Permissions::seller_reply_first() ),

			// Feed.
			'default_sort'           => (string) $o['reviews_default_sort'],
			'reviews_per_page'       => max( 1, (int) $o['reviews_per_page'] ),
			'summary_heading'        => (string) $o['reviews_summary_heading'],
			'filter_all_label'       => (string) $o['reviews_filter_all_label'],
			'filter_media_label'     => (string) $o['reviews_filter_media_label'],
			'load_more_label'        => (string) $o['reviews_load_more_label'],

			// Form.
			'form_visibility'        => (string) $o['reviews_form_visibility'],
			'form_heading'           => (string) $o['reviews_form_heading'],
			'form_subheading'        => (string) $o['reviews_form_subheading'],
			'form_body_placeholder'  => (string) $o['reviews_form_body_placeholder'],
			'form_submit_label'      => (string) $o['reviews_form_submit_label'],
			'form_success_message'   => (string) $o['reviews_form_success_message'],

			// Fallbacks (used only when $data has no live product values). Auto
			// mode always supplies real values, but we keep safe shapes so the
			// template's array access never warns.
			'product_brand'          => '',
			'product_title'          => '',
			'product_price'          => '',
			'product_image'          => array( 'url' => '' ),
			'gradient_overlay_image' => array( 'url' => '' ),
		);
	}

	/**
	 * Build the $data array for a WooCommerce product (Auto mode).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $settings   Optional pre-built settings (see settings()).
	 * @return array
	 */
	public static function build( int $product_id, array $settings = array() ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return self::empty_data( $product_id );
		}
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}

		$avg       = (float) $product->get_average_rating();
		$count     = (int) $product->get_review_count();
		$breakdown = self::calculate_breakdown( $product );
		$per_page  = max( 1, (int) ( $settings['reviews_per_page'] ?? 5 ) );

		// Resolve sort order from the default_sort setting.
		$sort_mode = $settings['default_sort'] ?? 'recent';
		switch ( $sort_mode ) {
			case 'highest':
				$orderby  = 'meta_value_num';
				$order    = 'DESC';
				$meta_key = 'rating';
				break;
			case 'lowest':
				$orderby  = 'meta_value_num';
				$order    = 'ASC';
				$meta_key = 'rating';
				break;
			default: // recent.
				$orderby  = 'comment_date';
				$order    = 'DESC';
				$meta_key = '';
				break;
		}

		$reviews_args = array(
			'post_id' => $product->get_id(),
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => $per_page,
			'orderby' => $orderby,
			'order'   => $order,
		);
		if ( $meta_key ) {
			$reviews_args['meta_key'] = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		$total_count = (int) get_comments(
			array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'count'   => true,
			)
		);

		$comments = get_comments( $reviews_args );
		$reviews  = array();
		foreach ( $comments as $comment ) {
			$rating    = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
			$verified  = function_exists( 'wc_review_is_from_verified_owner' )
				? (bool) wc_review_is_from_verified_owner( $comment->comment_ID )
				: false;
			$media     = self::get_review_media( $comment->comment_ID );
			$author    = self::resolve_author_name( $comment );
			$initials  = self::initials_from_name( $author );
			$replies   = self::get_review_replies( $comment->comment_ID );
			$variation = self::resolve_review_variation( $comment );

			$reviews[] = array(
				'id'        => (int) $comment->comment_ID,
				'name'      => $author,
				'initials'  => $initials,
				'date'      => self::format_review_date( $comment->comment_date ),
				// ISO 8601 timestamp kept separately from the display string above
				// because schema.org/Google rich-result validation requires
				// datePublished in ISO 8601, not the site's display date format.
				'date_iso'  => self::format_review_date_iso( $comment->comment_date ),
				// A missing rating is unknown, not five stars. Inventing a perfect
				// score here silently inflates every aggregate on the site.
				'rating'    => $rating > 0 ? $rating : 0,
				'body'      => $comment->comment_content,
				'verified'  => $verified,
				'media'     => $media,
				'replies'   => $replies,
				'variation' => $variation,
			);
		}

		// Reveal the write-a-review form? (My Account review link nonce check.)
		$eval = Review_Tracker::evaluate_request( $product->get_id() );

		// Brand: product_brand taxonomy first, then a pa_brand attribute.
		$brand = '';
		if ( taxonomy_exists( 'product_brand' ) ) {
			$brand_terms = get_the_terms( $product->get_id(), 'product_brand' );
			if ( ! is_wp_error( $brand_terms ) && ! empty( $brand_terms ) ) {
				$brand = $brand_terms[0]->name;
			}
		}
		if ( ! $brand ) {
			$brand = $product->get_attribute( 'pa_brand' );
		}

		return array(
			'product_id'    => $product->get_id(),
			'product_url'   => get_permalink( $product->get_id() ),
			'brand'         => $brand,
			'title'         => $product->get_name(),
			'price'         => wp_strip_all_tags( $product->get_price_html() ),
			'image'         => wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'avg_rating'    => $avg,
			'review_count'  => $count,
			'total_reviews' => $total_count,
			'per_page'      => $per_page,
			'breakdown'     => $breakdown,
			'reviews'       => $reviews,
			'media_gallery' => self::get_product_media( $product->get_id() ), // v1.1.17 - flat, drives the strip.
			// v1.2.0 - nested by review, drives the two-axis full-screen viewer.
			'media_reviews' => self::get_grouped_review_media( $product->get_id() ),
			'all_reviews'   => array(), // Woo mode: Load More pulls from the server, not JSON.
			'reveal_form'   => $eval['reveal'],
			'order_id'      => $eval['order_id'],
			'order_item_id' => $eval['order_item_id'],
			'is_woo'        => true,
		);
	}

	/**
	 * Product IDs belonging to one vendor.
	 *
	 * Defaults to WordPress post authorship, which is how Dokan, WCFM and
	 * WC Vendors all assign a product to a store. Marketplaces that model
	 * ownership differently can short-circuit this with the filter.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return array<int,int>
	 */
	public static function vendor_product_ids( int $vendor_id ): array {
		/**
		 * Filter the product IDs owned by a vendor.
		 *
		 * Return an array to bypass the default author lookup entirely.
		 *
		 * @param array|null $ids       Product IDs, or null to use the default.
		 * @param int        $vendor_id Vendor user ID.
		 */
		$filtered = apply_filters( 'zymarg_reviews_vendor_product_ids', null, $vendor_id );
		if ( is_array( $filtered ) ) {
			return array_values( array_unique( array_map( 'intval', $filtered ) ) );
		}

		if ( $vendor_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'author'                 => $vendor_id,
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Rating aggregate across every product a vendor owns.
	 *
	 * Computed from the rating meta directly rather than averaging Woo's
	 * per-product `_wc_average_rating` values, because a store-wide average has
	 * to be weighted by each product's review count -- averaging the averages
	 * would let a product with one review outweigh a product with a thousand.
	 *
	 * Reviews with no rating are excluded from the aggregate entirely. They are
	 * still shown in the feed; they simply do not vote on the store's score.
	 *
	 * Cached for six hours, matching the per-product breakdown.
	 *
	 * @param int   $vendor_id   Vendor user ID.
	 * @param array $product_ids Pre-resolved product IDs, to save a second lookup.
	 * @return array{avg:float,count:int,bars:array<int,float>,counts:array<int,int>}
	 */
	public static function vendor_aggregate( int $vendor_id, array $product_ids = array() ): array {
		$empty = array(
			'avg'    => 0.0,
			'count'  => 0,
			'bars'   => array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 ),
			'counts' => array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 ),
		);

		if ( $vendor_id <= 0 ) {
			return $empty;
		}

		$cache_key = 'zymarg_vendor_agg_' . $vendor_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		if ( empty( $product_ids ) ) {
			$product_ids = self::vendor_product_ids( $vendor_id );
		}
		if ( empty( $product_ids ) ) {
			set_transient( $cache_key, $empty, HOUR_IN_SECONDS * 6 );
			return $empty;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// One grouped query instead of loading every comment row into memory:
		// a busy store can have tens of thousands of reviews.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cm.meta_value AS rating, COUNT(*) AS total
				 FROM {$wpdb->comments} c
				 INNER JOIN {$wpdb->commentmeta} cm
				         ON cm.comment_id = c.comment_ID
				        AND cm.meta_key = 'rating'
				 WHERE c.comment_approved = '1'
				   AND c.comment_type = 'review'
				   AND c.comment_parent = 0
				   AND c.comment_post_ID IN ({$placeholders})
				 GROUP BY cm.meta_value",
				$product_ids
			)
		);
		// phpcs:enable

		$counts = array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 );
		$sum    = 0;
		$total  = 0;
		foreach ( (array) $rows as $row ) {
			$star = (int) $row->rating;
			$n    = (int) $row->total;
			if ( $star < 1 || $star > 5 || $n < 1 ) {
				continue;
			}
			$counts[ $star ] += $n;
			$sum             += $star * $n;
			$total           += $n;
		}

		$bars = array();
		foreach ( $counts as $star => $n ) {
			$bars[ $star ] = $total > 0 ? round( ( $n / $total ) * 100, 1 ) : 0;
		}

		$out = array(
			'avg'    => $total > 0 ? round( $sum / $total, 2 ) : 0.0,
			'count'  => $total,
			'bars'   => $bars,
			'counts' => $counts,
		);

		set_transient( $cache_key, $out, HOUR_IN_SECONDS * 6 );
		return $out;
	}

	/**
	 * Clear a vendor's cached aggregate.
	 *
	 * @param int $vendor_id Vendor user ID.
	 */
	public static function flush_vendor_aggregate( int $vendor_id ): void {
		if ( $vendor_id > 0 ) {
			delete_transient( 'zymarg_vendor_agg_' . $vendor_id );
		}
	}

	/**
	 * Build the store-wide review data set for one vendor.
	 *
	 * Read-only by design. A store page displays reviews, it never collects
	 * them, so this payload carries no form state at all -- there is nothing
	 * for a caller to accidentally render a submission form from.
	 *
	 * @param int   $vendor_id Vendor user ID.
	 * @param array $settings  Optional pre-built settings (see settings()).
	 * @param int   $page      1-based page of the review feed.
	 * @return array
	 */
	public static function build_vendor( int $vendor_id, array $settings = array(), int $page = 1 ): array {
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}

		$per_page = max( 1, (int) ( $settings['reviews_per_page'] ?? 5 ) );
		$page     = max( 1, $page );

		$product_ids = self::vendor_product_ids( $vendor_id );
		$aggregate   = self::vendor_aggregate( $vendor_id, $product_ids );

		$data = array(
			'vendor_id'     => $vendor_id,
			'scope'         => 'vendor',
			'avg_rating'    => (float) $aggregate['avg'],
			// Number of reviews that actually carry a star rating. This is the
			// denominator behind avg_rating, so the two always agree.
			'review_count'  => (int) $aggregate['count'],
			'rating_counts' => $aggregate['counts'],
			'breakdown'     => $aggregate['bars'],
			// Size of the review feed, which may be larger than review_count if
			// some reviews were left without a rating.
			'total_reviews' => 0,
			'per_page'      => $per_page,
			'page'          => $page,
			'total_pages'   => 0,
			'has_more'      => false,
			'has_rating'    => (int) $aggregate['count'] > 0,
			'reviews'       => array(),
			// v1.3.2 - store-wide equivalents of build()'s media_gallery /
			// media_reviews. Powers the same customer-photos strip and
			// full-screen viewer on a vendor scope as on a single product.
			'media_gallery' => array(),
			'media_reviews' => array(),
			'is_woo'        => true,
		);

		if ( empty( $product_ids ) ) {
			return $data;
		}

		if ( 'yes' === ( $settings['show_review_media'] ?? 'yes' ) ) {
			$data['media_reviews'] = self::get_grouped_review_media_for_vendor( $product_ids );
			$data['media_gallery'] = self::get_vendor_media( $product_ids );
		}

		$feed_total = (int) get_comments(
			array(
				'post__in' => $product_ids,
				'status'   => 'approve',
				'type'     => 'review',
				'parent'   => 0,
				'count'    => true,
			)
		);

		$data['total_reviews'] = $feed_total;
		$data['total_pages']   = (int) ceil( $feed_total / $per_page );
		$data['has_more']      = ( $page * $per_page ) < $feed_total;

		if ( ! $feed_total ) {
			return $data;
		}

		$query = array(
			'post__in' => $product_ids,
			'status'   => 'approve',
			'type'     => 'review',
			'parent'   => 0,
			'number'   => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
			'orderby'  => 'comment_date',
			'order'    => 'DESC',
		);

		switch ( $settings['default_sort'] ?? 'recent' ) {
			case 'highest':
				$query['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query['orderby']  = 'meta_value_num';
				$query['order']    = 'DESC';
				break;
			case 'lowest':
				$query['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query['orderby']  = 'meta_value_num';
				$query['order']    = 'ASC';
				break;
		}

		foreach ( get_comments( $query ) as $comment ) {
			$rating     = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
			$product_id = (int) $comment->comment_post_ID;
			$author     = self::resolve_author_name( $comment );

			$data['reviews'][] = array(
				'id'            => (int) $comment->comment_ID,
				'name'          => $author,
				'initials'      => self::initials_from_name( $author ),
				'date'          => self::format_review_date( $comment->comment_date ),
				'date_iso'      => self::format_review_date_iso( $comment->comment_date ),
				// Same rule as the product feed: a missing rating is unknown,
				// never five stars.
				'rating'        => $rating > 0 ? $rating : 0,
				'body'          => $comment->comment_content,
				'verified'      => function_exists( 'wc_review_is_from_verified_owner' )
					? (bool) wc_review_is_from_verified_owner( $comment->comment_ID )
					: false,
				'media'         => self::get_review_media( $comment->comment_ID ),
				'replies'       => self::get_review_replies( $comment->comment_ID ),
				'variation'     => self::resolve_review_variation( $comment ),
				// Store-wide feeds mix products, so each card has to say which
				// product it is about.
				'product_id'    => $product_id,
				'product_title' => get_the_title( $product_id ),
				'product_url'   => (string) get_permalink( $product_id ),
				'product_image' => (string) get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' ),
			);
		}

		return $data;
	}

	/**
	 * Star-rating breakdown percentages (5..1), cached for 6 hours.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,float>
	 */
	private static function calculate_breakdown( $product ): array {
		$cache_key = 'zymarg_breakdown_' . $product->get_id();
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$counts = array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 );
		$total  = 0;

		$comments = get_comments(
			array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 0,
			)
		);
		foreach ( $comments as $c ) {
			$r = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			if ( $r >= 1 && $r <= 5 ) {
				++$counts[ $r ];
				++$total;
			}
		}

		$out = array();
		foreach ( $counts as $star => $n ) {
			$out[ $star ] = $total > 0 ? round( ( $n / $total ) * 100, 1 ) : 0;
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS * 6 );
		return $out;
	}

	/**
	 * Store-owner (and customer) replies for a review.
	 *
	 * @param int $comment_id Parent comment ID.
	 * @return array
	 */
	private static function get_review_replies( $comment_id ): array {
		$children = get_comments(
			array(
				'parent'  => $comment_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 10,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			)
		);

		$out = array();
		foreach ( $children as $child ) {
			$is_owner = (bool) get_comment_meta( $child->comment_ID, '_zymarg_store_reply', true );
			$out[]    = array(
				'id'       => (int) $child->comment_ID,
				// Store replies keep the store name; customer replies resolve live.
				'author'   => $is_owner ? $child->comment_author : self::resolve_author_name( $child ),
				'body'     => $child->comment_content,
				'date'     => self::format_review_date( $child->comment_date ),
				'is_owner' => $is_owner,
			);
		}

		// Seller replies pinned above customer replies; each group stays in
		// chronological order.
		return Permissions::sort_replies( $out, 'is_owner' );
	}

	/**
	 * Resolve review media attachment URLs.
	 *
	 * @param int $comment_id Comment ID.
	 * @return array<int,string>
	 */
	private static function get_review_media( $comment_id ): array {
		$ids = get_comment_meta( $comment_id, Review_Tracker::COMMENT_META_MEDIA, true );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}
		$items = array();
		foreach ( $ids as $id ) {
			$item = self::media_item( (int) $id );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Describe one media attachment for the review gallery.
	 *
	 * v1.1.17 - media used to be a flat list of URLs. Video support needs the
	 * mime type and a separate poster/thumbnail, so each entry is now a record.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function media_item( int $id ): array {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return array();
		}
		$mime     = (string) get_post_mime_type( $id );
		$is_video = 0 === strpos( $mime, 'video/' );
		$thumb    = '';
		$duration = '';

		if ( $is_video ) {
			// Videos only have a thumbnail if a poster image was attached.
			$poster = (int) get_post_thumbnail_id( $id );
			if ( $poster ) {
				$thumb = (string) wp_get_attachment_image_url( $poster, 'medium' );
			}

			// v1.2.0 - the viewer labels video thumbnails with their runtime
			// ("0:23"). WordPress stores this in the attachment metadata for
			// audio/video uploads; length_formatted is already human readable.
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['length_formatted'] ) ) {
				$duration = (string) $meta['length_formatted'];
			}
		} else {
			$thumb = (string) wp_get_attachment_image_url( $id, 'medium' );
		}

		return array(
			'id'       => $id,
			'type'     => $is_video ? 'video' : 'image',
			'url'      => $url,
			'thumb'    => $thumb ? $thumb : ( $is_video ? '' : $url ),
			'mime'     => $mime,
			// v1.2.0 - kept separate from `thumb` so the viewer can hand the
			// browser a real poster attribute without preloading the video file.
			'poster'   => $is_video ? $thumb : '',
			'duration' => $duration,
		);
	}

	/**
	 * Review media grouped by the review that produced it.
	 *
	 * v1.2.0 - the media viewer navigates on two axes: horizontally through the
	 * media belonging to one review, vertically between reviews. A flat list
	 * cannot express where one review's media ends and the next begins, so the
	 * viewer is fed this nested shape instead.
	 *
	 * Reviews with no media are omitted entirely: they have nothing to show in a
	 * media viewer, and including them would strand the customer on an empty
	 * slide while swiping vertically. Those reviews still render normally in the
	 * review feed itself.
	 *
	 * Not paginated. The viewer spans every approved review of the product, not
	 * only the ones currently painted on screen.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_grouped_review_media( $product_id ): array {
		return self::build_grouped_media( array( (int) $product_id ), false );
	}

	/**
	 * Review media grouped by review, across every product a vendor owns.
	 *
	 * v1.3.2 - the store-wide equivalent of get_grouped_review_media(). Each
	 * row additionally carries which product the review belongs to, since a
	 * store-wide viewer -- unlike the single-product viewer -- spans reviews
	 * about many different products.
	 *
	 * @param array<int,int> $product_ids Product IDs owned by the vendor.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_grouped_review_media_for_vendor( array $product_ids ): array {
		return self::build_grouped_media( array_map( 'intval', $product_ids ), true );
	}

	/**
	 * Shared query + shaping logic behind get_grouped_review_media() and
	 * get_grouped_review_media_for_vendor().
	 *
	 * @param array<int,int> $product_ids           One product (single-product
	 *                                               scope) or many (vendor scope).
	 * @param bool            $with_product_context  Attach product_id/title/
	 *                                                url/image to each row.
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_grouped_media( array $product_ids, bool $with_product_context ): array {
		$product_ids = array_values( array_filter( $product_ids ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$args = array(
			'status'   => 'approve',
			'type'     => 'review',
			'parent'   => 0,
			'meta_key' => Review_Tracker::COMMENT_META_MEDIA, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
		);
		if ( 1 === count( $product_ids ) ) {
			$args['post_id'] = $product_ids[0];
		} else {
			$args['post__in'] = $product_ids;
		}

		$comments   = get_comments( $args );
		$current_uid = get_current_user_id();
		$out         = array();

		foreach ( $comments as $comment ) {
			$media = self::get_review_media( $comment->comment_ID );
			if ( empty( $media ) ) {
				continue;
			}

			$cid    = (int) $comment->comment_ID;
			$rating = (int) get_comment_meta( $cid, 'rating', true );
			$author = self::resolve_author_name( $comment );

			// The viewer carries its own copy of the vote state so its counters
			// can be painted before any AJAX round trip. Anything the customer
			// then does inside the viewer is mirrored back onto the review card
			// underneath it, and vice versa, by the front-end script.
			$user_vote = '';
			if ( $current_uid ) {
				$votes     = get_comment_meta( $cid, '_zymarg_votes', true );
				$user_vote = is_array( $votes ) ? (string) ( $votes[ $current_uid ] ?? '' ) : '';
			}

			$row = array(
				'review_id'     => $cid,
				'name'          => $author,
				'initials'      => self::initials_from_name( $author ),
				'date'          => self::format_review_date( $comment->comment_date ),
				// A missing rating is unknown, not five stars.
				'rating'        => $rating > 0 ? $rating : 0,
				'body'          => (string) $comment->comment_content,
				'variation'     => self::resolve_review_variation( $comment ),
				'verified'      => function_exists( 'wc_review_is_from_verified_owner' )
					? (bool) wc_review_is_from_verified_owner( $cid )
					: false,
				'like_count'    => (int) get_comment_meta( $cid, '_zymarg_likes', true ),
				'dislike_count' => (int) get_comment_meta( $cid, '_zymarg_dislikes', true ),
				'user_vote'     => $user_vote,
				'reported'      => $current_uid
					? (bool) get_comment_meta( $cid, '_zymarg_reported_' . $current_uid, true )
					: false,
				'media'         => array_values( $media ),
			);

			// Store-wide scope spans many products, so every row has to say
			// which product it is about -- the same context the review feed
			// itself already attaches in build_vendor().
			if ( $with_product_context ) {
				$pid                    = (int) $comment->comment_post_ID;
				$row['product_id']    = $pid;
				$row['product_title'] = get_the_title( $pid );
				$row['product_url']   = (string) get_permalink( $pid );
				$row['product_image'] = (string) get_the_post_thumbnail_url( $pid, 'woocommerce_thumbnail' );
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Every piece of review media for a product, newest review first, with the
	 * reviewer context the gallery viewer needs. Not paginated - the gallery
	 * spans the whole product, not just the reviews currently on screen.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_product_media( $product_id ): array {
		// v1.2.0 - this is now a flattened projection of get_grouped_review_media()
		// rather than its own query. The customer-media strip above the feed and
		// the full-screen viewer therefore can never disagree about what media
		// exists, what order it is in, or who wrote it: there is exactly one
		// source of truth.
		return self::flatten_grouped_media( self::get_grouped_review_media( $product_id ) );
	}

	/**
	 * Store-wide equivalent of get_product_media(): every piece of review
	 * media across every product a vendor owns, flattened for the customer
	 * media strip.
	 *
	 * @param array<int,int> $product_ids Product IDs owned by the vendor.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_vendor_media( array $product_ids ): array {
		return self::flatten_grouped_media( self::get_grouped_review_media_for_vendor( $product_ids ) );
	}

	/**
	 * Flatten a get_grouped_review_media()-shaped array into the strip's
	 * one-tile-per-media-item projection.
	 *
	 * Every flattened item keeps `review_index` + `media_index`, so a click on
	 * a strip tile can open the viewer directly at the right coordinates on
	 * both axes without searching for a matching attachment id.
	 *
	 * @param array<int,array<string,mixed>> $grouped Rows shaped like
	 *                                                 get_grouped_review_media().
	 * @return array<int,array<string,mixed>>
	 */
	private static function flatten_grouped_media( array $grouped ): array {
		$out = array();

		foreach ( $grouped as $review_index => $review ) {
			// The strip shows a short excerpt, not the whole review body.
			$body = wp_strip_all_tags( (string) $review['body'] );
			if ( function_exists( 'wp_trim_words' ) ) {
				$body = wp_trim_words( $body, 60, '…' );
			}

			$context = array(
				'comment_id'   => (int) $review['review_id'],
				'review_index' => (int) $review_index,
				'name'         => $review['name'],
				'initials'     => $review['initials'],
				'date'         => $review['date'],
				'rating'       => (int) $review['rating'],
				'body'         => $body,
				'variation'    => $review['variation'],
				'likes'        => (int) $review['like_count'],
				'verified'     => (bool) $review['verified'],
			);

			// Carried through only in vendor scope (see build_grouped_media()),
			// so the strip/viewer can label a tile with its product too.
			if ( isset( $review['product_id'] ) ) {
				$context['product_id']    = $review['product_id'];
				$context['product_title'] = $review['product_title'] ?? '';
				$context['product_url']   = $review['product_url'] ?? '';
			}

			foreach ( $review['media'] as $media_index => $item ) {
				$out[] = array_merge(
					$item,
					$context,
					array( 'media_index' => (int) $media_index )
				);
			}
		}

		return $out;
	}

	/**
	 * Resolve the variation label shown on a review, e.g. "Color: Black, Size: M".
	 *
	 * New reviews (since the fix that added this method) have the label saved
	 * directly at submission time in Ajax::submit_review(), read straight off
	 * the exact order item that was purchased. Reviews submitted before that
	 * fix carry no stored value, so this falls back to resolving the label
	 * live from that same order item -- every review already links to it via
	 * the order/order-item IDs saved for the "already reviewed" check, so no
	 * backfill migration is needed. Simple products have no variation, so
	 * both paths correctly return ''.
	 *
	 * @param \WP_Comment $comment Review comment.
	 * @return string
	 */
	public static function resolve_review_variation( $comment ): string {
		$cid    = (int) $comment->comment_ID;
		$stored = (string) get_comment_meta( $cid, '_zymarg_review_variation', true );
		if ( '' !== $stored ) {
			return $stored;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order_id      = (int) get_comment_meta( $cid, Review_Tracker::COMMENT_META_ORDER_ID, true );
		$order_item_id = (int) get_comment_meta( $cid, Review_Tracker::COMMENT_META_ITEM_ID, true );
		if ( ! $order_id || ! $order_item_id ) {
			return '';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		$item = $order->get_item( $order_item_id );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return '';
		}

		return self::format_order_item_variation( $item );
	}

	/**
	 * Build a WooCommerce-native variation label from a purchased order item,
	 * e.g. "Color: Black, Size: M" -- the same attribute/value pairs and, with
	 * one deliberate difference, the same call WooCommerce's own order screen
	 * uses to print a line item's variation meta.
	 *
	 * v1.2.2 - WC_Order_Item::get_formatted_meta_data( $hideprefix = '_',
	 * $include_all = false ) defaults $include_all to false. With that default,
	 * WooCommerce silently drops an attribute row unless it can re-verify, AT
	 * READ TIME, that the row's value is still a valid attribute value on the
	 * variation product right now -- so a row visibly present on the order
	 * (confirmed directly on the WooCommerce order screen) can still come back
	 * as zero rows here if that live re-check fails for any reason. Passing
	 * $include_all = true skips that fragile re-verification and returns every
	 * order-item meta row as originally recorded, which is what a *review*
	 * needs: a permanent record of what the customer actually bought, not a
	 * label that can silently disappear later if the variation is edited.
	 * The leading-underscore hideprefix is left at its default '_', so internal
	 * order-item meta such as _dokan_commission_source is still excluded.
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return string
	 */
	public static function format_order_item_variation( $item ): string {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) || (int) $item->get_variation_id() <= 0 ) {
			return ''; // Simple product (or no variation resolvable) -- nothing to show.
		}

		if ( ! method_exists( $item, 'get_formatted_meta_data' ) ) {
			return '';
		}

		$meta = $item->get_formatted_meta_data( '_', true );
		if ( empty( $meta ) ) {
			return '';
		}

		$pairs = array();
		foreach ( $meta as $m ) {
			$label = wp_strip_all_tags( (string) ( $m->display_key ?? '' ) );
			$value = wp_strip_all_tags( (string) ( $m->display_value ?? '' ) );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$pairs[] = $label . ': ' . $value;
		}

		return implode( ', ', $pairs );
	}

	/**
	 * Resolve the name shown for a review/reply author.
	 *
	 * Registered users: always the *current* value of their WordPress profile
	 * "Display Name" field (wp_users.display_name), looked up live on every
	 * render. This is deliberately NOT the comment_author value frozen on the
	 * comment row at submission time, so a display name changed after the
	 * fact is reflected on old reviews too, not just new ones.
	 *
	 * Guests (no user_id on the comment - no WordPress account tied to the
	 * review): falls back to the stored comment_author snapshot, unchanged
	 * from the existing behaviour.
	 *
	 * @param \WP_Comment $comment Review or reply comment.
	 * @return string
	 */
	private static function resolve_author_name( $comment ): string {
		$user_id = (int) ( $comment->user_id ?? 0 );
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user && '' !== trim( (string) $user->display_name ) ) {
				return $user->display_name;
			}
		}
		return $comment->comment_author;
	}

	/**
	 * Display date for a review/reply, in the site-wide dd/mm/yyyy format used
	 * throughout the Reviews Engine UI (e.g. "11/08/2026").
	 *
	 * Deliberately independent of the WordPress Settings -> General date
	 * format, which the reviews feed no longer follows.
	 *
	 * @param string $mysql_date A comment_date (or comment_date_gmt) value.
	 * @return string
	 */
	private static function format_review_date( string $mysql_date ): string {
		return date_i18n( 'd/m/Y', strtotime( $mysql_date ) );
	}

	/**
	 * ISO 8601 timestamp for a review, used only for schema.org/JSON-LD
	 * datePublished. Rich-result validators require ISO 8601, not the
	 * plugin's dd/mm/yyyy display format.
	 *
	 * @param string $mysql_date A comment_date (or comment_date_gmt) value.
	 * @return string
	 */
	private static function format_review_date_iso( string $mysql_date ): string {
		return date_i18n( 'c', strtotime( $mysql_date ) );
	}

	/**
	 * Two-letter initials from an author display name.
	 *
	 * @param string $name Author name.
	 * @return string
	 */
	private static function initials_from_name( $name ): string {
		$name  = trim( wp_strip_all_tags( (string) $name ) );
		$parts = preg_split( '/\\s+/', $name );
		$ini   = '';
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
			if ( mb_strlen( $ini ) >= 2 ) {
				break;
			}
		}
		return $ini ?: '?';
	}

	/**
	 * Safe empty data shape (product missing) so the template never warns.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function empty_data( int $product_id ): array {
		return array(
			'product_id'    => $product_id,
			'product_url'   => '',
			'brand'         => '',
			'title'         => '',
			'price'         => '',
			'image'         => '',
			'avg_rating'    => 0.0,
			'review_count'  => 0,
			'total_reviews' => 0,
			'per_page'      => 5,
			'breakdown'     => array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 ),
			'reviews'       => array(),
			'all_reviews'   => array(),
			'reveal_form'   => false,
			'order_id'      => 0,
			'order_item_id' => 0,
			'is_woo'        => true,
		);
	}

	/**
	 * Read the unified Options store, with a safe fallback if unavailable.
	 *
	 * @return array
	 */
	private static function options(): array {
		// v1.0.0 - the engine owns its settings store. Key names are unchanged
		// from the embedded module so ported code and migration stay simple.
		$stored = Settings::all();
		if ( $stored ) {
			return $stored;
		}
		// Fallback defaults, only reachable if the settings store is unavailable.
		return array(
			'reviews_show_bg_gradient'       => true,
			'reviews_show_summary'           => true,
			'reviews_show_breakdown_bars'    => true,
			'reviews_show_filters'           => true,
			'reviews_show_load_more'         => true,
			'reviews_show_verified_badge'    => true,
			'reviews_show_media'             => true,
			'reviews_enable_schema'          => true,
			'reviews_default_sort'           => 'recent',
			'reviews_per_page'               => 5,
			'reviews_summary_heading'        => 'Customer Reviews',
			'reviews_filter_all_label'       => 'All Reviews',
			'reviews_filter_media_label'     => 'With Photos',
			'reviews_load_more_label'        => 'Load more reviews',
			'reviews_form_visibility'        => 'gated',
			'reviews_form_heading'           => 'Write a Review',
			'reviews_form_subheading'        => 'Share your experience with other shoppers',
			'reviews_form_body_placeholder'  => 'What did you like or dislike?',
			'reviews_form_submit_label'      => 'Submit Review',
			'reviews_form_success_message'   => 'Thank you for your review!',
		);
	}
}
