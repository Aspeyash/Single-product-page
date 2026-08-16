<?php
/**
 * Reviews Engine - front-end assets.
 *
 * Assets are registered on every request but only enqueued when something
 * actually renders reviews, so pages without a review section stay clean.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public const HANDLE_CSS = 'zymarg-reviews-engine';
	public const HANDLE_JS  = 'zymarg-reviews-engine';

	/** @var self|null */
	private static $instance = null;

	/** @var bool Guard so repeated render calls localise only once. */
	private $localised = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ], 5 );
	}

	/** Register handles without queueing them. */
	public function register(): void {
		if ( Settings::get( 'reviews_load_base_css', true ) ) {
			wp_register_style(
				self::HANDLE_CSS,
				ZYMARG_RE_ASSETS_URL . 'css/zymarg-reviews.css',
				[],
				ZYMARG_RE_VERSION
			);
		}

		wp_register_script(
			self::HANDLE_JS,
			ZYMARG_RE_ASSETS_URL . 'js/zymarg-reviews.js',
			[],
			ZYMARG_RE_VERSION,
			true
		);
	}

	/**
	 * Queue the assets for this request.
	 *
	 * @param int $product_id Product context for the localised config.
	 */
	public function enqueue( int $product_id = 0 ): void {
		if ( ! wp_script_is( self::HANDLE_JS, 'registered' ) ) {
			$this->register();
		}

		if ( Settings::get( 'reviews_load_base_css', true ) ) {
			wp_enqueue_style( self::HANDLE_CSS );
		}
		wp_enqueue_script( self::HANDLE_JS );

		if ( $this->localised ) {
			return;
		}
		$this->localised = true;

		// The front-end script reads window.ZymargReviews. Key names and nonce
		// actions must stay in sync with the AJAX class; renaming them would break
		// any page already cached with older nonces.
		$max_files = max( 1, (int) Settings::get( 'reviews_max_media_files', 4 ) );
		$max_kb    = max( 1, (int) Settings::get( 'reviews_max_media_size_kb', 2048 ) );

		wp_localize_script(
			self::HANDLE_JS,
			'ZymargReviews',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'productId'   => $product_id,
				'submitNonce' => wp_create_nonce( 'zymarg_submit_review' ),
				'loadNonce'   => wp_create_nonce( 'zymarg_load_reviews' ),
				'replyNonce'  => Permissions::can_reply( $product_id ) ? wp_create_nonce( 'zymarg_reply_review' ) : '',
				'maxFiles'    => $max_files,
				'maxFileSize' => $max_kb * 1024,
				// Lets the script place an injected seller reply the same way the
				// server would have rendered it.
				'sellerReplyFirst' => Permissions::seller_reply_first(),
				'canReact'         => Permissions::can_react(),
				'canReply'         => Permissions::can_reply( $product_id ),
				'i18n'        => [
					'loading'     => __( 'Loading...', 'zymarg-reviews-engine' ),
					'submitting'  => __( 'Submitting...', 'zymarg-reviews-engine' ),
					'genericErr'  => __( 'Something went wrong. Please try again.', 'zymarg-reviews-engine' ),
					'error'       => __( 'Something went wrong. Please try again.', 'zymarg-reviews-engine' ),
					'thank_you'   => (string) Settings::get( 'reviews_form_success_message', __( 'Thank you for your review!', 'zymarg-reviews-engine' ) ),
					'report_done' => __( 'Reported.', 'zymarg-reviews-engine' ),
					'login_to_react' => __( 'Please log in to react.', 'zymarg-reviews-engine' ),
					'helpful'     => __( 'Helpful', 'zymarg-reviews-engine' ),
					'load_more'   => (string) Settings::get( 'reviews_load_more_label', __( 'Load more reviews', 'zymarg-reviews-engine' ) ),
				],
			]
		);
	}
}
