<?php
/**
 * Reviews Engine - shortcode.
 *
 * For placements with no code: ordinary pages, page builders, widget areas.
 * Plugins should call zymarg_reviews_render() directly instead.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'zymarg_reviews', [ $this, 'render' ] );
	}

	/**
	 * [zymarg_reviews product_id="13" layout="compact" limit="5" show_form="no"]
	 * [zymarg_reviews vendor_id="42"] renders that store's reviews, read only.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'product_id'   => 0,
				'vendor_id'    => 0,
				'page'         => 1,
				'layout'       => '',
				'limit'        => 0,
				'show_summary' => '',
				'show_filters' => '',
				'show_form'    => '',
			],
			$atts,
			'zymarg_reviews'
		);

		$bool = static function ( $val ) {
			if ( '' === $val || null === $val ) {
				return null;
			}
			return in_array( strtolower( (string) $val ), [ '1', 'yes', 'true', 'on' ], true );
		};

		return (string) zymarg_reviews_render(
			[
				'product_id'   => (int) $atts['product_id'],
				'vendor_id'    => (int) $atts['vendor_id'],
				'page'         => max( 1, (int) $atts['page'] ),
				'layout'       => (string) $atts['layout'],
				'limit'        => (int) $atts['limit'],
				'show_summary' => $bool( $atts['show_summary'] ),
				'show_filters' => $bool( $atts['show_filters'] ),
				'show_form'    => $bool( $atts['show_form'] ),
				'echo'         => false,
			]
		);
	}
}
