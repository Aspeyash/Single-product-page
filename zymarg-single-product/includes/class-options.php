<?php
/**
 * Options — single serialised array storage for all plugin settings.
 *
 * All reads go through Options::get( 'key', $default ).
 * All writes go through Options::set( [ 'key' => value ] ).
 *
 * @version 1.1.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Options {

	const OPTION_KEY = 'zymarg_sp_settings';

	/** @var array|null In-memory cache after first DB read. */
	private static $cache = null;

	// ── Defaults ────────────────────────────────────────────────────────────

	public static function defaults(): array {
		return [

			// ── General ─────────────────────────────────────────────────────
			'template_override_enabled' => true,
			'override_priority'         => 1,

			// Breadcrumbs
			'show_breadcrumbs'          => true,
			'breadcrumb_separator'      => '›',

			// Seller card
			'show_seller_card'          => true,
			'show_visit_store'          => true,
			'show_chat_btn'             => true,
			'chat_url'                  => '',

			// Tabs / accordions
			'show_description_tab'      => true,
			'show_reviews_tab'          => true,
			'description_open_default'  => true,
			'reviews_open_default'      => false,
			'description_label'         => 'Description',
			'reviews_label'             => 'Reviews ({count})',

			// Product grid sections
			'show_seller_products'      => true,
			'show_similar_products'     => true,
			'show_recommended'          => true,
			'seller_products_title'     => 'More from this Seller',
			'similar_products_title'    => 'Similar Products',
			'recommended_title'         => 'Recommended for You',

			// v2.1.0 - ordered, user-managed product grid sections.
			// The three keys above are retained only so existing installs can
			// be migrated into this list; the template no longer reads them.
			'product_sections'          => self::default_sections(),

			// v2.2.0 - one-step rollback snapshot, written on every save that
			// changes the section list. Never rendered; never posted.
			'product_sections_backup'   => [],

			// ── Gallery ─────────────────────────────────────────────────────
			'gallery_desktop_layout'    => 'vertical-left',
			'gallery_tablet_layout'     => 'vertical-left',
			'gallery_show_thumbs_desktop' => true,
			'gallery_show_thumbs_tablet'  => true,
			'gallery_show_thumbs_mobile'  => true,
			// v2.3.0 - when the mobile toggle above is OFF, which product types lose
			// the thumbnail rail. 'all' reproduces pre-2.3.0 behaviour exactly.
			'gallery_thumbs_mobile_scope' => 'all', // all | variable | simple
			'gallery_mobile_layout'     => 'carousel',
			'gallery_show_counter'      => true,
			'gallery_counter_format'    => '{current} / {total}',
			'gallery_thumb_size'        => 'medium',
			'gallery_max_thumbs'        => 6,
			'gallery_hover_zoom'        => true,
			'gallery_lightbox'          => true,
			'gallery_lazy_thumbs'       => true,
			'gallery_show_sale_badge'   => true,
			'gallery_sale_badge_text'   => '-{percent}%',
			'gallery_badge_position'    => 'top-left',
			'gallery_show_wishlist'     => true,
			'product_video_enabled'     => true,

			// ── Swatches ────────────────────────────────────────────────────
			'swatch_shape'              => 'rounded',
			'swatch_color_size'         => '44px',
			'swatch_label_padding'      => '8px 14px',
			'swatch_oos_behavior'       => 'blur',
			'swatch_tooltip'            => true,
			'swatch_tooltip_position'   => 'top',
			'swatch_auto_select'        => true,
			'swatch_show_clear'         => true,
			'swatch_clear_label'        => 'Clear',
			'swatch_show_attr_label'    => true,
			'swatch_show_selected_val'  => true,

			// ── Price ────────────────────────────────────────────────────────
			'price_variable_display'    => 'lowest',
			'price_from_prefix'         => 'From',
			'price_regular_position'    => 'inline',
			'price_old_style'           => 'strikethrough',
			'price_heading_on_sale'     => true,
			'price_heading_sale_text'   => 'Limited Time Offer',
			'price_heading_ending_soon' => true,
			'price_heading_ending_text' => 'Ends in {hours} hours!',
			'price_heading_regular'     => false,
			'price_heading_regular_text'=> 'Price',
			'price_heading_oos'         => true,
			'price_heading_oos_text'    => 'Currently Unavailable',
			'price_show_savings'        => true,
			'price_savings_format'      => 'both',
			'price_savings_prefix'      => 'Save',
			'price_show_free_hint'      => false,
			'price_free_threshold'      => 2000,
			'price_free_hint_text'      => 'Free shipping over {amount}',
			'price_change_animation'    => 'fade',
			'price_loading_skeleton'    => false,

			// ── Add to Cart ──────────────────────────────────────────────────
			'qty_show_stepper'          => true,
			'qty_default'               => 1,
			'qty_min'                   => 1,
			'qty_max'                   => 0,
			'qty_sync_sticky'           => true,
			'atc_btn_text'              => 'Add to Cart',
			'atc_btn_text_loading'      => 'Adding…',
			'atc_btn_text_done'         => 'Added!',
			'buynow_show'               => true,
			'buynow_text'               => 'Buy Now',
			'buynow_position'           => 'below',
			'buynow_session_ttl'        => 15,
			'sticky_bar_enabled'        => true,
			'sticky_bar_content'        => 'qty-atc-buynow',

			// ── Trust & Shipping ─────────────────────────────────────────────
			'show_sold_by'              => true,
			'trust_badge_1_enabled'     => true,
			'trust_badge_1_text'        => 'Free shipping on orders over ৳500',
			'trust_badge_2_enabled'     => true,
			'trust_badge_2_text'        => '30-day hassle-free returns',
			'trust_badge_3_enabled'     => true,
			'trust_badge_3_text'        => '1-year official warranty',
			'trust_badge_4_enabled'     => true,
			'trust_badge_4_text'        => 'Secure checkout — SSL encrypted',
			'trust_badge_5_enabled'     => false,
			'trust_badge_5_text'        => '',
			'show_stock_status'         => true,
			'show_low_stock_warning'    => true,
			'low_stock_threshold'       => 5,
			'show_delivery_info'        => true,
			'delivery_icon'             => '🚚',
			'delivery_window_text'      => 'Delivery in 3–5 business days',
			'ships_from_text'           => 'Ships from seller warehouse',
			'show_shipping_returns'     => true,
			'shipping_text'             => 'Free standard shipping over ৳500. Express available at checkout.',
			'returns_text'              => '30-day free returns. Item must be in original condition.',
			'show_secure_note'          => true,
			'secure_note_text'          => '🔒 100% Secure Payment · Buyer Protection',

			// ── Reviews (legacy — owned by ZYMARG Reviews Engine since v2.0.0) ──
			// These keys are no longer read or rendered by this plugin. They stay in
			// storage only so the engine's one-time migration can still pick up a
			// site's existing preferences. Do not add UI for them here.
			// Display
			'reviews_show_summary'          => true,
			'reviews_show_breakdown_bars'   => true,
			'reviews_show_filters'          => true,
			'reviews_show_verified_badge'   => true,
			'reviews_show_media'            => true,
			'reviews_show_load_more'        => true,
			'reviews_show_bg_gradient'      => true,
			'reviews_enable_schema'         => true,
			// Feed
			'reviews_default_sort'          => 'recent',
			'reviews_per_page'              => 5,
			'reviews_summary_heading'       => 'Customer Reviews',
			'reviews_filter_all_label'      => 'All Reviews',
			'reviews_filter_media_label'    => 'With Photos',
			'reviews_load_more_label'       => 'Load more reviews',
			// Form
			'reviews_form_visibility'       => 'gated',
			'reviews_form_heading'          => 'Write a Review',
			'reviews_form_subheading'       => 'Share your experience with other shoppers',
			'reviews_form_title_placeholder'=> 'Summarize your review',
			'reviews_form_body_placeholder' => 'What did you like or dislike?',
			'reviews_form_submit_label'     => 'Submit Review',
			'reviews_form_success_message'  => 'Thank you for your review!',
			// Submission behavior
			'reviews_allow_media_upload'    => true,
			'reviews_max_media_files'       => 4,
			'reviews_max_media_size_kb'     => 2048,
			'reviews_allow_video_upload'    => true,
			'reviews_max_video_size_kb'     => 20480,
			'reviews_window_days'           => 15,
			'reviews_auto_approve_verified' => false,
			// Report moderation
			'reviews_reports_auto_unapprove' => false,
			'reviews_reports_threshold'      => 3,
			'reviews_reports_notify_email'   => true,
			'reviews_reports_notify_address' => '',
		];
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Get one setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Override default (uses built-in defaults if null).
	 * @return mixed
	 */
	/**
	 * Default product grid sections (v2.1.0).
	 *
	 * Each row is: id, label, enabled, shortcode.
	 * Array order IS render order - reordering the array reorders the page.
	 *
	 * @return array
	 */
	public static function default_sections(): array {
		return [
			[
				'id'        => 'sec_seller',
				'label'     => 'More from this Seller',
				'enabled'   => true,
				'heading'   => 'More from {vendor_name}',
				'show_link' => true,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="vendor" limit="10" layout="slider" card_template="zymarg" columns="5" columns_tablet="4" columns_mobile="2"]',
			],
			[
				'id'        => 'sec_similar',
				'label'     => 'Similar Products',
				'enabled'   => true,
				'heading'   => 'Similar Products',
				'show_link' => false,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="similar" limit="10" layout="slider" card_template="zymarg" columns="5" columns_tablet="4" columns_mobile="2"]',
			],
			[
				'id'        => 'sec_recommended',
				'label'     => 'Recommended for You',
				'enabled'   => true,
				'heading'   => 'Recommended for You',
				'show_link' => false,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="recommended" limit="8" layout="grid" card_template="zymarg" columns="4" columns_tablet="4" columns_mobile="2"]',
			],
		];
	}

	/**
	 * One-time migration of the three legacy hardcoded sections (v2.1.0).
	 *
	 * Carries the saved toggle and title of each old section into the new
	 * ordered list so an upgraded site looks identical after activation.
	 * Fresh installs skip this entirely - defaults() already supplies the list.
	 *
	 * @return void
	 */
	public static function maybe_migrate_sections(): void {
		$saved = get_option( self::OPTION_KEY, [] );

		// Fresh install, or already migrated: nothing to carry over.
		if ( ! is_array( $saved ) || empty( $saved ) || array_key_exists( 'product_sections', $saved ) ) {
			return;
		}

		$rows = self::default_sections();
		$map  = [
			'sec_seller'      => [ 'show_seller_products', 'seller_products_title' ],
			'sec_similar'     => [ 'show_similar_products', 'similar_products_title' ],
			'sec_recommended' => [ 'show_recommended', 'recommended_title' ],
		];

		foreach ( $rows as $i => $row ) {
			if ( ! isset( $map[ $row['id'] ] ) ) {
				continue;
			}
			list( $toggle_key, $title_key ) = $map[ $row['id'] ];

			if ( array_key_exists( $toggle_key, $saved ) ) {
				$rows[ $i ]['enabled'] = (bool) $saved[ $toggle_key ];
			}

			if ( ! empty( $saved[ $title_key ] ) ) {
				$title                   = str_replace( '"', '', (string) $saved[ $title_key ] );
				$rows[ $i ]['label']     = $title;
				$rows[ $i ]['shortcode'] = preg_replace(
					'/heading_text="[^"]*"/',
					'heading_text="' . $title . '"',
					$rows[ $i ]['shortcode']
				);
			}
		}

		$saved['product_sections'] = $rows;
		update_option( self::OPTION_KEY, $saved );
		self::flush();
	}

	/**
	 * Seed the v2.2.0 per-row heading fields on an existing section list.
	 *
	 * Installs upgraded from v2.1.0 already own a product_sections list, so
	 * maybe_migrate_sections() correctly declines to touch it. This fills in the
	 * three new keys without disturbing the shortcode the site owner has tuned:
	 *
	 *  - heading   comes from the shortcode's own heading_text, falling back to
	 *              the admin label, so the front end reads the same after upgrade
	 *  - show_link comes from show_view_all
	 *  - link_url  comes from view_all_url, but only on non-vendor sections,
	 *              because vendor sections resolve their own store link
	 *
	 * The shortcode itself is left exactly as stored. Any heading or view-all
	 * attributes still in it are inert, because every section is rendered with
	 * show_heading="no" forced on (see Sections::force_no_heading).
	 *
	 * @return void
	 */
	public static function maybe_upgrade_sections(): void {
		$saved = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $saved ) || ! isset( $saved['product_sections'] ) || ! is_array( $saved['product_sections'] ) ) {
			return;
		}

		$rows    = $saved['product_sections'];
		$touched = false;

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || array_key_exists( 'heading', $row ) ) {
				continue;
			}

			$code = (string) ( $row['shortcode'] ?? '' );

			$heading = '';
			if ( preg_match( '/\bheading_text="([^"]*)"/', $code, $m ) ) {
				$heading = trim( $m[1] );
			}
			if ( '' === $heading ) {
				$heading = trim( (string) ( $row['label'] ?? '' ) );
			}

			$show_link = (bool) preg_match( '/\bshow_view_all="(yes|true|1)"/i', $code );

			$is_vendor = false;
			if ( preg_match( '/\bsource="([^"]*)"/', $code, $m ) ) {
				$is_vendor = in_array( strtolower( trim( $m[1] ) ), [ 'vendor', 'current_vendor' ], true );
			}

			$link_url = '';
			if ( ! $is_vendor && preg_match( '/\bview_all_url="([^"]*)"/', $code, $m ) ) {
				$link_url = trim( $m[1] );
			}

			$rows[ $i ]['heading']   = $heading;
			$rows[ $i ]['show_link'] = $show_link;
			$rows[ $i ]['link_url']  = $link_url;
			$touched                 = true;
		}

		if ( ! $touched ) {
			return;
		}

		$saved['product_sections'] = $rows;
		update_option( self::OPTION_KEY, $saved );
		self::flush();
	}

	public static function get( string $key, $default = null ) {
		$all      = self::all();
		$defaults = self::defaults();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		return $defaults[ $key ] ?? null;
	}

	/**
	 * Get all settings (merged with defaults so new keys always exist).
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, [] );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Merge and save an array of changed settings.
	 *
	 * @param array $data Key→value pairs to update.
	 * @return bool
	 */
	public static function set( array $data ): bool {
		$current     = self::all();
		$updated     = array_merge( $current, $data );
		self::$cache = $updated;
		return update_option( self::OPTION_KEY, $updated );
	}

	/**
	 * Write defaults to DB on first activation (does not overwrite existing).
	 */
	public static function set_defaults(): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', 'no' );
		}
	}

	/** Bust the in-memory cache (useful after save). */
	public static function flush(): void {
		self::$cache = null;
	}
}
