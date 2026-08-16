<?php
/**
 * Reviews Engine - public API.
 *
 * The only surface consumers should touch. Every function is guarded with
 * function_exists so a second copy of the engine can never fatal the site.
 *
 * @package ZymargReviewsEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zymarg_reviews_get_setting' ) ) {
	/**
	 * Read one engine setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	function zymarg_reviews_get_setting( string $key, $default = null ) {
		return ZymargReviewsEngine\Settings::get( $key, $default );
	}
}

if ( ! function_exists( 'zymarg_reviews_get_settings' ) ) {
	/**
	 * Read every engine setting.
	 *
	 * @return array<string,mixed>
	 */
	function zymarg_reviews_get_settings(): array {
		return ZymargReviewsEngine\Settings::all();
	}
}

if ( ! function_exists( 'zymarg_reviews_get_data' ) ) {
	/**
	 * Build the review data set for a scope.
	 *
	 * @param array $args {
	 *     Scope arguments.
	 *
	 *     @type int   $product_id Product to build for. Defaults to the current product.
	 *     @type int   $vendor_id  Vendor user ID for a store-wide, read-only scope.
	 *                             Takes precedence over $product_id when set.
	 *     @type int   $page       1-based page of the review feed. Vendor scope only.
	 *     @type array $settings   Pre-resolved settings, optional.
	 * }
	 * @return array Empty array when the scope cannot be resolved.
	 */
	function zymarg_reviews_get_data( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'vendor_id'  => 0,
				'page'       => 1,
				'settings'   => array(),
			)
		);

		$settings = is_array( $args['settings'] ) && $args['settings']
			? $args['settings']
			: ZymargReviewsEngine\Data_Builder::settings();

		// Store-wide scope, checked first: a caller that asks for a vendor is
		// never asking for whichever product happens to be in the loop.
		$vendor_id = (int) $args['vendor_id'];
		if ( $vendor_id > 0 ) {
			$data = ZymargReviewsEngine\Data_Builder::build_vendor( $vendor_id, $settings, (int) $args['page'] );

			/** This filter is documented later in this function. */
			return apply_filters( 'zymarg_reviews_data', $data, $args, $settings );
		}

		$product_id = (int) $args['product_id'];
		if ( ! $product_id ) {
			$product_id = (int) get_the_ID();
		}
		if ( ! $product_id ) {
			return array();
		}

		$data = ZymargReviewsEngine\Data_Builder::build( $product_id, $settings );

		/**
		 * Filter the review data before it reaches a consumer.
		 *
		 * @param array $data     Built data.
		 * @param array $args     Scope arguments.
		 * @param array $settings Resolved settings.
		 */
		return apply_filters( 'zymarg_reviews_data', $data, $args, $settings );
	}
}

if ( ! function_exists( 'zymarg_reviews_enqueue' ) ) {
	/**
	 * Ensure the engine's front-end assets are queued for this request.
	 *
	 * @param int $product_id Optional product context for the localised config.
	 */
	function zymarg_reviews_enqueue( int $product_id = 0 ): void {
		if ( class_exists( 'ZymargReviewsEngine\\Assets' ) ) {
			ZymargReviewsEngine\Assets::instance()->enqueue( $product_id );
		}
	}
}

if ( ! function_exists( 'zymarg_reviews_available' ) ) {
	/**
	 * Is the engine actually able to render reviews right now?
	 *
	 * Consumers should prefer this over function_exists( 'zymarg_reviews_render' ).
	 * The API functions stay defined even when the engine runs in settings-only
	 * mode behind a legacy embedded copy, so function_exists on its own reports a
	 * renderer that will deliberately return nothing.
	 */
	function zymarg_reviews_available(): bool {
		if ( ! class_exists( 'ZymargReviewsEngine\\Assets' ) ) {
			return false;
		}
		if ( function_exists( 'zymarg_re_legacy_copy_active' ) && zymarg_re_legacy_copy_active() ) {
			return false;
		}
		return (bool) ZymargReviewsEngine\Settings::get( 'reviews_enabled', true );
	}
}

if ( ! function_exists( 'zymarg_reviews_version' ) ) {
	/**
	 * The running engine version, for a consumer's compatibility handshake.
	 */
	function zymarg_reviews_version(): string {
		return defined( 'ZYMARG_RE_VERSION' ) ? (string) ZYMARG_RE_VERSION : '';
	}
}

if ( ! function_exists( 'zymarg_reviews_is_placing_itself' ) ) {
	/**
	 * Is the engine putting the section on the page by itself?
	 *
	 * A consumer can call this to skip its own render call and hand placement
	 * over to the engine, rather than relying on an administrator remembering to
	 * switch the consumer's own toggle off.
	 */
	function zymarg_reviews_is_placing_itself(): bool {
		return class_exists( 'ZymargReviewsEngine\\Placement' )
			&& ZymargReviewsEngine\Placement::is_active();
	}
}

if ( ! function_exists( 'zymarg_reviews_render' ) ) {
	/**
	 * Render the review section.
	 *
	 * @param array $args {
	 *     Render arguments. Anything not listed falls back to the saved settings.
	 *
	 *     @type int    $product_id   Product to render for.
	 *     @type string $layout       full | compact | list.
	 *     @type int    $limit        Reviews per page.
	 *     @type bool   $show_summary Show the rating summary.
	 *     @type bool   $show_filters Show the filter bar.
	 *     @type bool   $show_form    Show the submission form.
	 *     @type bool   $echo         Echo (default) or return the markup.
	 * }
	 * @return string Markup when $echo is false, otherwise an empty string.
	 */
	function zymarg_reviews_render( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id'   => 0,
				'vendor_id'    => 0,
				'page'         => 1,
				'layout'       => '',
				'limit'        => 0,
				'show_summary' => null,
				'show_filters' => null,
				'show_form'    => null,
				'echo'         => true,
			)
		);

		if ( ! ZymargReviewsEngine\Settings::get( 'reviews_enabled', true ) ) {
			return '';
		}

		// Read visibility. 'logged_in' hides the section from guests entirely.
		if ( ! ZymargReviewsEngine\Permissions::can_read() ) {
			return '';
		}

		// Store-wide scope: vendor_id takes precedence over product_id.
		$vendor_id  = (int) $args['vendor_id'];
		$product_id = 0;

		if ( ! $vendor_id ) {
			$product_id = (int) $args['product_id'];
			if ( ! $product_id ) {
				$product_id = (int) get_the_ID();
			}
			if ( ! $product_id || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
				return '';
			}
		}

		// One review section per scope per request.
		//
		// Without this, a site running both a consumer template call and an
		// engine placement prints the section twice: duplicate DOM ids, two
		// Load More buttons paginating the same feed, and doubled schema.
		static $rendered = array();
		$scope_key = $vendor_id ? 'v' . $vendor_id : 'p' . $product_id;

		/**
		 * Filter whether one scope may render more than once per request.
		 *
		 * @param bool   $allow     Default false.
		 * @param string $scope_key Scope identifier.
		 * @param array  $args      Render arguments.
		 */
		if ( isset( $rendered[ $scope_key ] )
			&& ! apply_filters( 'zymarg_reviews_allow_duplicate', false, $scope_key, $args ) ) {
			return '';
		}

		$settings = ZymargReviewsEngine\Data_Builder::settings();

		// Per-placement overrides. A store page can ask for a compact, form-less
		// section without changing the saved settings.
		$yesno = static function ( $val ): string {
			return $val ? 'yes' : 'no';
		};
		if ( $args['layout'] ) {
			$settings['layout'] = sanitize_key( $args['layout'] );
		}
		if ( (int) $args['limit'] > 0 ) {
			$settings['reviews_per_page'] = (int) $args['limit'];
		}
		if ( null !== $args['show_summary'] ) {
			$settings['show_summary'] = $yesno( $args['show_summary'] );
		}
		if ( null !== $args['show_filters'] ) {
			$settings['show_filters'] = $yesno( $args['show_filters'] );
		}
		if ( null !== $args['show_form'] && ! $args['show_form'] ) {
			$settings['form_visibility'] = 'hidden';
		}

		// A store-wide feed spans many products. No single product to review,
		// no honest purchase gate. The form never appears on store-wide feeds.
		if ( $vendor_id ) {
			$settings['form_visibility'] = 'never';
		}

		/**
		 * Filter the settings used for one specific placement.
		 *
		 * @param array $settings Resolved settings.
		 * @param array $args     Render arguments.
		 */
		$settings = apply_filters( 'zymarg_reviews_render_settings', $settings, $args );

		$data = zymarg_reviews_get_data(
			array(
				'product_id' => $product_id,
				'vendor_id'  => $vendor_id,
				'page'       => max( 1, (int) $args['page'] ),
				'settings'   => $settings,
			)
		);
		if ( ! $data ) {
			return '';
		}

		// Claimed only now that the section is certain to be produced. Marking
		// it earlier would let a call that bailed on empty data block the real
		// placement later in the same request.
		$rendered[ $scope_key ] = true;

		zymarg_reviews_enqueue( $product_id );

		$widget_id = $vendor_id
			? 'zymarg-reviews-vendor-' . $vendor_id
			: 'zymarg-reviews-' . $product_id;

		/**
		 * Filter the template file used to render the section.
		 *
		 * Consumers that want their own markup can point this at their own file;
		 * the engine's default template is the reference implementation.
		 *
		 * @param string $template Absolute path.
		 * @param array  $args     Render arguments.
		 */
		$template = apply_filters( 'zymarg_reviews_template_path', ZYMARG_RE_TPL_PATH . 'reviews.php', $args );
		if ( ! $template || ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		include $template;
		$markup = (string) ob_get_clean();

		if ( $args['echo'] ) {
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return '';
		}
		return $markup;
	}
}

// Action form of the renderer, for consumers that prefer hooks.
add_action(
	'zymarg_reviews_render',
	function ( $args = array() ) {
		zymarg_reviews_render( is_array( $args ) ? $args : array( 'product_id' => (int) $args ) );
	}
);

// Backwards compatible alias for ZYMARG Single Product < 2.0.
add_action(
	'zymarg_sp_reviews_render',
	function ( $product_id ) {
		if ( ! zymarg_re_legacy_copy_active() ) {
			zymarg_reviews_render( array( 'product_id' => (int) $product_id ) );
		}
	}
);
