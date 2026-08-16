<?php
/**
 * Reviews Engine - front-end placement.
 *
 * Lets the engine put the review section on the page by itself instead of
 * waiting for a consumer plugin's template to call zymarg_reviews_render().
 * That is what makes a consumer such as ZYMARG Single Product freezable: a new
 * review feature ships with an engine update and needs no consumer release.
 *
 * Nothing here is enabled by default. A site that already renders reviews from
 * its theme or from ZYMARG Single Product keeps behaving exactly as it did
 * until an administrator picks a placement mode.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Placement {

	/** Shortcode tag the engine will execute in shortcode mode. */
	public const ALLOWED_TAG = 'zymarg_reviews';

	/** @var self|null */
	private static $instance = null;

	/** @var bool Guard so init() binds its actions only once. */
	private $bound = false;

	/** @var array<int,int> Cached approved top-level review counts, per product. */
	private static $counts = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * The anchors the engine is allowed to hook, as hook name => label.
	 *
	 * An allowlist rather than a free-text field. An arbitrary hook name is a
	 * silent no-op at best, and at worst prints a whole review section inside an
	 * HTML attribute, a feed or a transactional email.
	 *
	 * The zymarg_sp_* entries are the hooks ZYMARG Single Product 2.0.0 exposes.
	 * The woocommerce_* entries let the engine place itself on any other theme,
	 * so a site is never dependent on Single Product being installed.
	 *
	 * @return array<string,string>
	 */
	public static function hooks(): array {
		$hooks = array(
			'zymarg_sp_after_tabs'                    => __( 'ZYMARG Single Product: after the tabs (recommended)', 'zymarg-reviews-engine' ),
			'zymarg_sp_after_product_section'          => __( 'ZYMARG Single Product: after the product section', 'zymarg-reviews-engine' ),
			'zymarg_sp_after_seller_card'              => __( 'ZYMARG Single Product: after the seller card', 'zymarg-reviews-engine' ),
			'zymarg_sp_after_breadcrumbs'              => __( 'ZYMARG Single Product: after the breadcrumbs', 'zymarg-reviews-engine' ),
			'woocommerce_after_single_product_summary' => __( 'WooCommerce: after the product summary', 'zymarg-reviews-engine' ),
			'woocommerce_after_single_product'         => __( 'WooCommerce: after the product', 'zymarg-reviews-engine' ),
		);

		/**
		 * Filter the placement anchors offered in the admin dropdown.
		 *
		 * A theme that fires its own hook can add it here and it becomes
		 * selectable and savable, because the settings allowlist reads this same
		 * list.
		 *
		 * @param array<string,string> $hooks Hook name => human label.
		 */
		return (array) apply_filters( 'zymarg_reviews_placement_hooks', $hooks );
	}

	/** The configured mode, normalised. */
	public static function mode(): string {
		$mode = (string) Settings::get( 'reviews_placement_mode', 'off' );
		return in_array( $mode, array( 'off', 'hook', 'shortcode' ), true ) ? $mode : 'off';
	}

	/** Is the engine placing the section itself on this site? */
	public static function is_active(): bool {
		return 'off' !== self::mode() && (bool) Settings::get( 'reviews_enabled', true );
	}

	/**
	 * Bind the placement and its assets.
	 *
	 * Called on plugins_loaded, so the target hook has not fired yet no matter
	 * how early in the template it lives.
	 */
	public function init(): void {
		if ( $this->bound ) {
			return;
		}
		$this->bound = true;

		if ( ! self::is_active() ) {
			return;
		}

		$hook = (string) Settings::get( 'reviews_placement_hook', 'zymarg_sp_after_tabs' );
		if ( ! isset( self::hooks()[ $hook ] ) ) {
			$hook = 'zymarg_sp_after_tabs';
		}

		$priority = (int) Settings::get( 'reviews_placement_priority', 10 );

		add_action( $hook, array( $this, 'render' ), $priority );

		// Assets have to be queued during wp_enqueue_scripts to reach wp_head.
		// Waiting for the render call would push the stylesheet to the footer and
		// show a flash of unstyled reviews, which is why the engine no longer
		// relies on a consumer plugin enqueueing on its behalf.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Queue the front-end assets on pages this placement will render on.
	 */
	public function enqueue(): void {
		if ( ! self::is_active() || ! $this->is_target_page() ) {
			return;
		}
		if ( ! Permissions::can_read() ) {
			return;
		}
		zymarg_reviews_enqueue( (int) get_the_ID() );
	}

	/**
	 * Print the review section.
	 *
	 * @param mixed $product Whatever the anchor hook passes. WC_Product on the
	 *                       ZYMARG Single Product hooks, nothing on the
	 *                       WooCommerce ones.
	 */
	public function render( $product = null ): void {
		if ( ! self::is_active() || ! $this->is_target_page() ) {
			return;
		}

		$product_id = $product instanceof \WC_Product
			? (int) $product->get_id()
			: (int) get_the_ID();

		if ( ! $product_id ) {
			return;
		}

		$markup = 'shortcode' === self::mode()
			? $this->render_shortcode( $product_id )
			: (string) zymarg_reviews_render(
				array(
					'product_id' => $product_id,
					'echo'       => false,
				)
			);

		// An empty string is the engine declining to render: reviews switched
		// off, hidden from guests, already rendered once on this request, or no
		// such product. Printing a heading and an empty accordion around that
		// would be worse than printing nothing.
		if ( '' === trim( $markup ) ) {
			return;
		}

		echo $this->wrap( $markup, $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode mode: run the stored shortcode string.
	 *
	 * The stored value is never handed to do_shortcode() unfiltered. Only the
	 * engine's own tag is accepted, so a typo or a pasted snippet from another
	 * plugin cannot be executed from this field.
	 *
	 * @param int $product_id Current product.
	 * @return string
	 */
	private function render_shortcode( int $product_id ): string {
		$raw = trim( (string) Settings::get( 'reviews_placement_shortcode', '' ) );

		// Empty or unusable field: fall back to the normal renderer rather than
		// silently dropping the review section off the site.
		if ( '' === $raw || ! self::shortcode_is_valid( $raw ) ) {
			return (string) zymarg_reviews_render(
				array(
					'product_id' => $product_id,
					'echo'       => false,
				)
			);
		}

		$raw = str_replace( '{product_id}', (string) $product_id, $raw );

		return (string) do_shortcode( $raw );
	}

	/**
	 * Is this string a single, well-formed engine shortcode?
	 *
	 * @param string $raw Candidate shortcode.
	 */
	public static function shortcode_is_valid( string $raw ): bool {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return false;
		}

		// Exactly one tag, and it has to be ours.
		if ( 1 !== preg_match_all( '/\[([a-z0-9_-]+)/i', $raw, $matches ) ) {
			return false;
		}
		if ( self::ALLOWED_TAG !== strtolower( $matches[1][0] ) ) {
			return false;
		}

		// Balanced brackets, and nothing outside them.
		return (bool) preg_match( '/^\[' . self::ALLOWED_TAG . '[^\[\]]*\]$/i', $raw );
	}

	/**
	 * Wrap the section so it lands in the same visual position, and inside the
	 * same accordion, that ZYMARG Single Product used to render.
	 *
	 * The acc / acc-body classes come from the Single Product stylesheet, which
	 * loads on every product page. On any other theme the accordion is a plain
	 * <details> element, which is styled by the browser and still usable.
	 *
	 * @param string $markup     Rendered review section.
	 * @param int    $product_id Current product.
	 */
	private function wrap( string $markup, int $product_id ): string {
		if ( ! Settings::get( 'reviews_placement_accordion', true ) ) {
			return '<section class="zymarg-re-placement" id="zymarg-reviews">' . $markup . '</section>';
		}

		$label = str_replace(
			'{count}',
			number_format_i18n( self::review_count( $product_id ) ),
			(string) Settings::get( 'reviews_placement_label', 'Reviews ({count})' )
		);

		$open = Settings::get( 'reviews_placement_open_default', false ) ? ' open' : '';

		return '<section class="zymarg-re-placement" id="zymarg-reviews">'
			. '<details class="acc"' . $open . '>'
			. '<summary>' . esc_html( $label ) . '</summary>'
			. '<div class="acc-body">' . $markup . '</div>'
			. '</details>'
			. '</section>';
	}

	/**
	 * Approved top-level reviews for a product.
	 *
	 * Deliberately not get_comments_number(): since 1.0.4 replies are stored as
	 * child comments, so the WordPress count would report "Reviews (31)" for a
	 * product with 12 reviews and 19 replies.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function review_count( int $product_id ): int {
		if ( isset( self::$counts[ $product_id ] ) ) {
			return self::$counts[ $product_id ];
		}

		$count = (int) get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'status'  => 'approve',
				'parent'  => 0,
				'count'   => true,
			)
		);

		self::$counts[ $product_id ] = $count;
		return $count;
	}

	/**
	 * Are we on a single product page in a normal front-end request?
	 *
	 * Feeds, REST responses, admin screens and AJAX are all excluded: the
	 * section is interactive markup carrying per-request nonces and has no
	 * meaning in any of them.
	 */
	private function is_target_page(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		return is_main_query() ? true : in_the_loop();
	}
}
