<?php
/**
 * Reviews Engine - settings store.
 *
 * The engine owns its own option row. Internal key names are kept identical to
 * the ones the embedded module used inside zymarg_sp_settings, so ported code
 * needs no rewriting and migration is a straight copy.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	public const OPTION      = 'zymarg_reviews_engine_settings';
	public const MIGRATED    = 'zymarg_reviews_engine_migrated';
	private const LEGACY_OPT = 'zymarg_sp_settings';

	/** @var array<string,mixed>|null Runtime cache. */
	private static $cache = null;

	/**
	 * Every setting the engine understands, with its default.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// -- General --
			'reviews_enabled'                  => true,
			'reviews_default_sort'             => 'recent',
			'reviews_per_page'                 => 5,
			'reviews_summary_heading'          => 'Customer Reviews',
			'reviews_enable_schema'            => true,
			'reviews_show_bg_gradient'         => true,
			'reviews_gradient_image'           => '',

			// -- Display --
			'reviews_layout'                   => 'full',
			'reviews_columns'                  => 1,
			'reviews_show_summary'             => true,
			'reviews_show_breakdown_bars'      => true,
			'reviews_show_filters'             => true,
			'reviews_show_verified_badge'      => true,
			'reviews_show_media'               => true,
			'reviews_show_load_more'           => true,
			'reviews_filter_all_label'         => 'All Reviews',
			'reviews_filter_media_label'       => 'With Photos',
			'reviews_load_more_label'          => 'Load more reviews',

			// -- Interactions --
			// Every interaction ships in its own toggle so the whole layer can be
			// switched off again without touching markup or losing stored data.
			// Defaults reproduce the pre-1.0.4 behaviour, except that customer
			// replies are new and therefore start disabled.
			'reviews_visibility'                => 'everyone',
			'reviews_enable_reactions'          => true,
			'reviews_reactions_guests'          => 'prompt',
			'reviews_enable_replies'            => true,
			'reviews_allow_seller_replies'      => true,
			'reviews_allow_customer_replies'    => false,
			'reviews_seller_reply_first'        => true,
			'reviews_customer_reply_moderation' => 'publish',
			'reviews_my_account_button'         => true,

			// Reply limits. 0 means unlimited, which is how replies behaved before
			// these settings existed, so upgrading imposes no new cap.
			'reviews_customer_replies_per_review' => 0,
			'reviews_seller_replies_per_review'   => 0,
			'reviews_reply_rate_limit'            => 5,
			'reviews_reply_rate_minutes'          => 10,
			'reviews_reply_rate_sellers'          => false,

			// -- Placement --
			// How the review section reaches the page. 'off' keeps the pre-1.0.6
			// behaviour, where a consumer plugin's template calls the renderer, so
			// updating the engine never changes what an existing site prints.
			'reviews_placement_mode'           => 'off',
			'reviews_placement_hook'           => 'zymarg_sp_after_tabs',
			'reviews_placement_priority'       => 10,
			'reviews_placement_accordion'      => true,
			'reviews_placement_label'          => 'Reviews ({count})',
			'reviews_placement_open_default'   => false,
			'reviews_placement_shortcode'      => '',

			// -- Review form --
			'reviews_form_visibility'          => 'gated',
			'reviews_form_heading'             => 'Write a Review',
			'reviews_form_subheading'          => 'Share your experience with other shoppers',
			'reviews_form_body_placeholder'    => 'What did you like or dislike?',
			'reviews_form_submit_label'        => 'Submit Review',
			'reviews_form_success_message'     => 'Thank you for your review!',
			'reviews_form_button_label'        => 'Write a Review',
			'reviews_form_button_label_done'   => 'Review Submitted',
			'reviews_form_require_rating'      => true,
			'reviews_form_min_length'          => 0,
			'reviews_form_max_length'          => 5000,

			// -- Media --
			'reviews_allow_media_upload'       => true,
			'reviews_max_media_files'          => 4,
			'reviews_max_media_size_kb'        => 2048,
			'reviews_allowed_image_types'      => 'jpg, jpeg, png, webp, gif',
			'reviews_allow_video_upload'       => true,
			'reviews_max_video_size_kb'        => 20480,
			'reviews_allowed_video_types'      => 'mp4, webm, mov',
			'reviews_enable_gallery'           => true,
			'reviews_show_media_strip'         => true,
			'reviews_media_strip_count'        => 6,

			// -- Submission and eligibility --
			'reviews_window_days'              => 15,
			'reviews_auto_approve_verified'    => false,
			'reviews_require_purchase'         => false,
			'reviews_one_per_product'          => true,
			'reviews_send_reminder_email'      => false,

			// -- Moderation and reports --
			'reviews_reports_auto_unapprove'   => false,
			'reviews_reports_threshold'        => 3,
			'reviews_reports_notify_email'     => true,
			'reviews_reports_notify_address'   => '',
			'reviews_report_reasons'           => "Spam or advertising\nOffensive or abusive language\nNot about this product\nFake or incentivised review\nContains personal information",

			// -- Emails --
			'reviews_email_admin_new'          => false,
			'reviews_email_admin_address'      => '',
			'reviews_email_new_subject'        => 'New review on {product}',
			'reviews_email_new_body'           => "{author} left a {rating}-star review on {product}.\n\n{review}\n\nModerate: {link}",
			'reviews_email_reply_notify'       => true,
			'reviews_email_reply_subject'      => 'The store replied to your review of {product}',
			'reviews_email_reply_body'         => "Hi {author},\n\nThe store replied to your review of {product}:\n\n{reply}\n\nView it here: {link}",

			// -- Advanced --
			'reviews_cache_ttl'                => 300,
			'reviews_load_base_css'            => true,
			'reviews_delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Value types, used for sanitising saves.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		$types = array();
		foreach ( self::defaults() as $key => $default ) {
			if ( is_bool( $default ) ) {
				$types[ $key ] = 'bool';
			} elseif ( is_int( $default ) ) {
				$types[ $key ] = 'int';
			} else {
				$types[ $key ] = 'text';
			}
		}

		// Overrides where the default value does not imply the right handling.
		$types['reviews_report_reasons']         = 'textarea';
		$types['reviews_email_new_body']         = 'textarea';
		$types['reviews_email_reply_body']       = 'textarea';
		$types['reviews_reports_notify_address'] = 'email';
		$types['reviews_email_admin_address']    = 'email';
		$types['reviews_gradient_image']         = 'url';

		return $types;
	}

	/** Allowed values for the enumerated settings. */
	public static function choices(): array {
		return array(
			'reviews_default_sort'    => array( 'recent', 'highest', 'lowest', 'helpful' ),
			// 'gated' only. The submission handler always requires a valid order,
			// order item and URL nonce, so 'always' and 'logged_in' rendered a form
			// that could never be submitted. Any value stored previously now falls
			// back to the 'gated' default on save.
			'reviews_form_visibility' => array( 'gated' ),
			'reviews_layout'          => array( 'full', 'compact', 'list' ),

			'reviews_visibility'                => array( 'everyone', 'logged_in' ),
			'reviews_reactions_guests'          => array( 'hide', 'prompt' ),
			'reviews_customer_reply_moderation' => array( 'publish', 'hold' ),

			'reviews_placement_mode' => array( 'off', 'hook', 'shortcode' ),
			// The anchor list is owned by Placement so the dropdown and the save
			// allowlist can never disagree, including hooks a theme adds through
			// the zymarg_reviews_placement_hooks filter.
			'reviews_placement_hook' => class_exists( __NAMESPACE__ . '\\Placement' )
				? array_keys( Placement::hooks() )
				: array( 'zymarg_sp_after_tabs' ),
		);
	}

	/** Numeric bounds, applied on save. */
	public static function bounds(): array {
		return array(
			'reviews_per_page'          => array( 1, 100 ),
			'reviews_columns'           => array( 1, 4 ),
			'reviews_form_min_length'   => array( 0, 2000 ),
			'reviews_form_max_length'   => array( 50, 20000 ),
			'reviews_max_media_files'   => array( 1, 20 ),
			'reviews_max_media_size_kb' => array( 128, 51200 ),
			'reviews_max_video_size_kb' => array( 1024, 102400 ),
			'reviews_media_strip_count' => array( 3, 24 ),
			'reviews_window_days'       => array( 0, 365 ),
			'reviews_reports_threshold' => array( 2, 100 ),
			'reviews_cache_ttl'         => array( 0, 86400 ),

			'reviews_customer_replies_per_review' => array( 0, 50 ),
			'reviews_seller_replies_per_review'   => array( 0, 50 ),
			'reviews_reply_rate_limit'            => array( 0, 100 ),
			'reviews_reply_rate_minutes'          => array( 1, 1440 ),

			'reviews_placement_priority'          => array( 1, 999 ),
		);
	}

	/** All settings, defaults merged with stored values. */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		/**
		 * Filter the resolved engine settings.
		 *
		 * @param array $settings Resolved settings.
		 */
		self::$cache = apply_filters( 'zymarg_reviews_settings', array_merge( self::defaults(), $stored ) );
		return self::$cache;
	}

	/**
	 * Short legacy keys used by the ported submission code, mapped onto the
	 * canonical reviews_* keys plus the value shape those callers expect.
	 *
	 * The ported code compares against 'yes'/'no' strings, so those keys must
	 * keep returning strings rather than booleans.
	 *
	 * @return array<string,array{key:string,type:string}>
	 */
	public static function aliases(): array {
		return array(
			'allow_media_upload'    => array( 'key' => 'reviews_allow_media_upload', 'type' => 'yesno' ),
			'allow_video_upload'    => array( 'key' => 'reviews_allow_video_upload', 'type' => 'yesno' ),
			'auto_approve_verified' => array( 'key' => 'reviews_auto_approve_verified', 'type' => 'yesno' ),
			'send_reminder_email'   => array( 'key' => 'reviews_send_reminder_email', 'type' => 'yesno' ),
			'review_window_days'    => array( 'key' => 'reviews_window_days', 'type' => 'int' ),
			'max_media_files'       => array( 'key' => 'reviews_max_media_files', 'type' => 'int' ),
			'max_media_size_kb'     => array( 'key' => 'reviews_max_media_size_kb', 'type' => 'int' ),
			'max_video_size_kb'     => array( 'key' => 'reviews_max_video_size_kb', 'type' => 'int' ),
			'button_label'          => array( 'key' => 'reviews_form_button_label', 'type' => 'text' ),
			'button_label_done'     => array( 'key' => 'reviews_form_button_label_done', 'type' => 'text' ),
		);
	}

	/**
	 * Read a single setting.
	 *
	 * Accepts either a canonical reviews_* key or one of the short legacy keys
	 * the ported code still uses.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all     = self::all();
		$aliases = self::aliases();

		if ( isset( $aliases[ $key ] ) ) {
			$target = $aliases[ $key ]['key'];
			$value  = $all[ $target ] ?? null;

			switch ( $aliases[ $key ]['type'] ) {
				case 'yesno':
					return ! empty( $value ) ? 'yes' : 'no';
				case 'int':
					return (int) $value;
				default:
					return null !== $value ? $value : $default;
			}
		}

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return null !== $default ? $default : ( self::defaults()[ $key ] ?? null );
	}

	/**
	 * Persist a set of raw (unsanitised) values.
	 *
	 * @param array $raw Incoming values.
	 * @return array The stored settings.
	 */
	public static function update( array $raw ): array {
		$clean = self::sanitize( $raw );
		$next  = array_merge( self::all(), $clean );
		update_option( self::OPTION, $next, false );
		self::$cache = null;
		do_action( 'zymarg_reviews_settings_saved', $clean );
		return self::all();
	}

	/**
	 * Sanitise incoming values against the known schema. Unknown keys are dropped.
	 *
	 * @param array $raw Incoming values.
	 * @return array
	 */
	public static function sanitize( array $raw ): array {
		$types    = self::types();
		$choices  = self::choices();
		$bounds   = self::bounds();
		$defaults = self::defaults();
		$out      = array();

		foreach ( $raw as $key => $value ) {
			if ( ! isset( $types[ $key ] ) ) {
				continue;
			}

			switch ( $types[ $key ] ) {
				case 'bool':
					$out[ $key ] = in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
					break;

				case 'int':
					$int = (int) $value;
					if ( isset( $bounds[ $key ] ) ) {
						$int = max( $bounds[ $key ][0], min( $bounds[ $key ][1], $int ) );
					}
					$out[ $key ] = $int;
					break;

				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( wp_unslash( (string) $value ) );
					break;

				case 'email':
					$addr        = sanitize_email( wp_unslash( (string) $value ) );
					$out[ $key ] = is_email( $addr ) ? $addr : '';
					break;

				case 'url':
					$out[ $key ] = esc_url_raw( wp_unslash( (string) $value ) );
					break;

				default:
					$text = sanitize_text_field( wp_unslash( (string) $value ) );
					if ( isset( $choices[ $key ] ) && ! in_array( $text, $choices[ $key ], true ) ) {
						$text = (string) $defaults[ $key ];
					}
					$out[ $key ] = $text;
					break;
			}
		}

		// Keep the two length bounds coherent regardless of save order.
		if ( isset( $out['reviews_form_min_length'], $out['reviews_form_max_length'] )
			&& $out['reviews_form_min_length'] > $out['reviews_form_max_length'] ) {
			$out['reviews_form_min_length'] = 0;
		}

		return $out;
	}

	/**
	 * First-run install: seed defaults and migrate from the Single Product store.
	 */
	public static function install(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, array(), '', false );
		}
		self::migrate();
	}

	/**
	 * One-time migration of reviews_* keys out of zymarg_sp_settings.
	 *
	 * Review content itself (comments and comment meta) is untouched - only
	 * configuration moves.
	 *
	 * @return int Number of keys migrated.
	 */
	public static function migrate(): int {
		if ( get_option( self::MIGRATED ) ) {
			return 0;
		}

		$legacy = get_option( self::LEGACY_OPT, array() );
		if ( ! is_array( $legacy ) || ! $legacy ) {
			update_option( self::MIGRATED, gmdate( 'c' ), false );
			return 0;
		}

		$known = self::defaults();
		$carry = array();
		foreach ( $legacy as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'reviews_' ) && array_key_exists( $key, $known ) ) {
				$carry[ $key ] = $value;
			}
		}

		if ( $carry ) {
			update_option( self::OPTION, self::sanitize( $carry ), false );
			self::$cache = null;
		}

		update_option( self::MIGRATED, gmdate( 'c' ), false );
		return count( $carry );
	}

	/** Reset every setting back to its default. */
	public static function reset(): void {
		update_option( self::OPTION, array(), false );
		self::$cache = null;
	}
}
