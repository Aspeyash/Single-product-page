<?php
/**
 * Reviews Engine — admin control page.
 *
 * One top-level menu, eight tabs, fully AJAX: switching tabs and saving never
 * reload the page.
 *
 * @package ZymargReviewsEngine
 */

namespace ZymargReviewsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public const SLUG        = 'zymarg-reviews-engine';
	public const NONCE       = 'zymarg_re_admin';
	public const CAP         = 'manage_options';
	private const HANDLE     = 'zymarg-re-admin';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'menu_branding' ] );
		add_action( 'wp_ajax_zymarg_re_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_zymarg_re_reset', [ $this, 'ajax_reset' ] );
		add_action( 'wp_ajax_zymarg_re_import', [ $this, 'ajax_import' ] );
	}

	/**
	 * Sidebar parent-menu branding (Design Tokens v3 section 2.16).
	 *
	 * Scoped to #toplevel_page_zymarg-reviews-engine and enqueued on
	 * every admin page. Brand gradient bar at rest, surface-white
	 * dashicon and label in every state.
	 */
	public function menu_branding(): void {
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_RE_ASSETS_URL . 'css/zymarg-tokens.css',
				[],
				ZYMARG_RE_VERSION
			);
		}
		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style(
			'zymarg-re-menu',
			ZYMARG_RE_ASSETS_URL . 'css/zymarg-re-menu.css',
			[ 'zymarg-tokens' ],
			ZYMARG_RE_VERSION
		);
	}

	// ── Menu ────────────────────────────────────────────────────────────

	public function menu(): void {
		add_menu_page(
			__( 'ZYMARG Reviews', 'zymarg-reviews-engine' ),
			__( 'ZYMARG Reviews', 'zymarg-reviews-engine' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render_page' ],
			'dashicons-star-filled',
			56
		);
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, ZYMARG_RE_ASSETS_URL . 'css/admin.css', [], ZYMARG_RE_VERSION );
		wp_enqueue_script( self::HANDLE, ZYMARG_RE_ASSETS_URL . 'js/admin.js', [], ZYMARG_RE_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'ZymargREAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => [
					'saving'   => __( 'Saving…', 'zymarg-reviews-engine' ),
					'saved'    => __( 'Settings saved', 'zymarg-reviews-engine' ),
					'failed'   => __( 'Could not save. Please try again.', 'zymarg-reviews-engine' ),
					'dirty'    => __( 'You have unsaved changes.', 'zymarg-reviews-engine' ),
					'confirm'  => __( 'Reset every setting back to its default?', 'zymarg-reviews-engine' ),
					'imported' => __( 'Settings imported', 'zymarg-reviews-engine' ),
					'badJson'  => __( 'That is not valid settings JSON.', 'zymarg-reviews-engine' ),
				],
			]
		);
	}

	// ── Schema ────────────────────────────────────────────────────────

	/**
	 * Tab → section → field definition for the whole control page.
	 *
	 * @return array
	 */
	public function schema(): array {
		return [
			'general' => [
				'label'    => __( 'General', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Engine', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_enabled', 'type' => 'toggle', 'label' => __( 'Enable reviews', 'zymarg-reviews-engine' ), 'desc' => __( 'Turn the whole review section off everywhere without deactivating the plugin.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_enable_schema', 'type' => 'toggle', 'label' => __( 'Output review schema', 'zymarg-reviews-engine' ), 'desc' => __( 'Adds JSON-LD aggregate rating markup for search engines.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Feed', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_default_sort', 'type' => 'select', 'label' => __( 'Default sort', 'zymarg-reviews-engine' ), 'options' => [ 'recent' => __( 'Most recent', 'zymarg-reviews-engine' ), 'highest' => __( 'Highest rated', 'zymarg-reviews-engine' ), 'lowest' => __( 'Lowest rated', 'zymarg-reviews-engine' ), 'helpful' => __( 'Most helpful', 'zymarg-reviews-engine' ) ] ],
							[ 'key' => 'reviews_per_page', 'type' => 'number', 'label' => __( 'Reviews per page', 'zymarg-reviews-engine' ), 'min' => 1, 'max' => 100 ],
							[ 'key' => 'reviews_summary_heading', 'type' => 'text', 'label' => __( 'Section heading', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Background', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_show_bg_gradient', 'type' => 'toggle', 'label' => __( 'Show background gradient', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_gradient_image', 'type' => 'url', 'label' => __( 'Gradient overlay image URL', 'zymarg-reviews-engine' ), 'desc' => __( 'Optional. Leave empty for the plain gradient.', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'display' => [
				'label'    => __( 'Display', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Layout', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_layout', 'type' => 'select', 'label' => __( 'Default layout', 'zymarg-reviews-engine' ), 'options' => [ 'full' => __( 'Full', 'zymarg-reviews-engine' ), 'compact' => __( 'Compact', 'zymarg-reviews-engine' ), 'list' => __( 'List', 'zymarg-reviews-engine' ) ], 'desc' => __( 'Individual placements can override this.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_columns', 'type' => 'number', 'label' => __( 'Review columns', 'zymarg-reviews-engine' ), 'min' => 1, 'max' => 4 ],
						],
					],
					[
						'title'  => __( 'Components', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_show_summary', 'type' => 'toggle', 'label' => __( 'Rating summary', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_breakdown_bars', 'type' => 'toggle', 'label' => __( 'Star breakdown bars', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_filters', 'type' => 'toggle', 'label' => __( 'Filter bar', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_verified_badge', 'type' => 'toggle', 'label' => __( 'Verified purchase badge', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_media', 'type' => 'toggle', 'label' => __( 'Photos and videos on reviews', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_load_more', 'type' => 'toggle', 'label' => __( 'Load more button', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Labels', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_filter_all_label', 'type' => 'text', 'label' => __( '“All reviews” label', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_filter_media_label', 'type' => 'text', 'label' => __( '“With photos” label', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_load_more_label', 'type' => 'text', 'label' => __( '“Load more” label', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'placement' => [
				'label'    => __( 'Placement', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'How reviews reach the page', 'zymarg-reviews-engine' ),
						'fields' => [
							[
								'key'     => 'reviews_placement_mode',
								'type'    => 'select',
								'label'   => __( 'Placement mode', 'zymarg-reviews-engine' ),
								'options' => [
									'off'       => __( 'Off — a consumer plugin or theme places the section', 'zymarg-reviews-engine' ),
									'hook'      => __( 'Hook — the engine places the section itself', 'zymarg-reviews-engine' ),
									'shortcode' => __( 'Shortcode — the engine runs the shortcode below', 'zymarg-reviews-engine' ),
								],
								'desc'    => __( 'Switch this to Hook and turn off “Show Reviews accordion” in ZYMARG Single Product. The engine then owns placement, so a new review feature never needs a Single Product release. Leave it Off to keep the current behaviour.', 'zymarg-reviews-engine' ),
							],
							[
								'key'     => 'reviews_placement_hook',
								'type'    => 'select',
								'label'   => __( 'Where on the page', 'zymarg-reviews-engine' ),
								'options' => Placement::hooks(),
								'desc'    => __( 'The anchor the section is printed at. The ZYMARG Single Product entries only fire on that plugin’s product template; the WooCommerce entries work on any theme.', 'zymarg-reviews-engine' ),
							],
							[
								'key'   => 'reviews_placement_priority',
								'type'  => 'number',
								'label' => __( 'Priority', 'zymarg-reviews-engine' ),
								'min'   => 1,
								'max'   => 999,
								'desc'  => __( 'Ordering against anything else hooked to the same anchor. Lower prints earlier.', 'zymarg-reviews-engine' ),
							],
						],
					],
					[
						'title'  => __( 'Accordion', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_placement_accordion', 'type' => 'toggle', 'label' => __( 'Wrap the section in an accordion', 'zymarg-reviews-engine' ), 'desc' => __( 'Reproduces the collapsible panel ZYMARG Single Product rendered, reusing that plugin’s own accordion styling.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_placement_label', 'type' => 'text', 'label' => __( 'Accordion label', 'zymarg-reviews-engine' ), 'desc' => __( 'Use {count} for the number of reviews. Replies are not counted.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_placement_open_default', 'type' => 'toggle', 'label' => __( 'Open by default', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Shortcode mode', 'zymarg-reviews-engine' ),
						'fields' => [
							[
								'key'   => 'reviews_placement_shortcode',
								'type'  => 'text',
								'label' => __( 'Shortcode to run', 'zymarg-reviews-engine' ),
								'desc'  => __( 'Only used in Shortcode mode. Use {product_id} for the current product, for example [zymarg_reviews product_id="{product_id}" layout="compact"]. Only the zymarg_reviews tag is accepted; anything else falls back to the normal renderer so a typo cannot take reviews off your site.', 'zymarg-reviews-engine' ),
							],
						],
					],
				],
			],

			'interactions' => [
				'label'    => __( 'Interactions', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Who can read reviews', 'zymarg-reviews-engine' ),
						'fields' => [
							[
								'key'     => 'reviews_visibility',
								'type'    => 'select',
								'label'   => __( 'Review visibility', 'zymarg-reviews-engine' ),
								'options' => [
									'everyone'  => __( 'Everyone, including guests', 'zymarg-reviews-engine' ),
									'logged_in' => __( 'Logged-in users only', 'zymarg-reviews-engine' ),
								],
								'desc'    => __( 'Logged-in only hides the whole review section from guests, including the Load More endpoint.', 'zymarg-reviews-engine' ),
							],
						],
					],
					[
						'title'  => __( 'Reactions', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_enable_reactions', 'type' => 'toggle', 'label' => __( 'Enable reactions', 'zymarg-reviews-engine' ), 'desc' => __( 'The helpful / not helpful buttons on each review.', 'zymarg-reviews-engine' ) ],
							[
								'key'     => 'reviews_reactions_guests',
								'type'    => 'select',
								'label'   => __( 'Guests', 'zymarg-reviews-engine' ),
								'options' => [
									'prompt' => __( 'Show the buttons and ask them to log in', 'zymarg-reviews-engine' ),
									'hide'   => __( 'Hide the buttons from guests', 'zymarg-reviews-engine' ),
								],
								'desc'    => __( 'A reaction is stored against a user account, so guests can never record one.', 'zymarg-reviews-engine' ),
							],
						],
					],
					[
						'title'  => __( 'Replies', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_enable_replies', 'type' => 'toggle', 'label' => __( 'Enable replies', 'zymarg-reviews-engine' ), 'desc' => __( 'Master switch. Off hides every reply and every reply form.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_allow_seller_replies', 'type' => 'toggle', 'label' => __( 'Sellers can reply', 'zymarg-reviews-engine' ), 'desc' => __( 'Shop managers everywhere, plus the vendor who owns the product being reviewed.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_allow_customer_replies', 'type' => 'toggle', 'label' => __( 'Customers can reply', 'zymarg-reviews-engine' ), 'desc' => __( 'Lets any logged-in customer reply to a review. Replies are plain text.', 'zymarg-reviews-engine' ) ],
							[
								'key'     => 'reviews_customer_reply_moderation',
								'type'    => 'select',
								'label'   => __( 'Customer replies', 'zymarg-reviews-engine' ),
								'options' => [
									'publish' => __( 'Publish immediately', 'zymarg-reviews-engine' ),
									'hold'    => __( 'Hold for approval', 'zymarg-reviews-engine' ),
								],
								'desc'    => __( 'Held replies wait in WP Admin → Comments. Seller replies always publish immediately.', 'zymarg-reviews-engine' ),
							],
							[ 'key' => 'reviews_seller_reply_first', 'type' => 'toggle', 'label' => __( 'Seller replies first', 'zymarg-reviews-engine' ), 'desc' => __( 'Pins seller replies above customer replies on every review.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'How many replies are allowed', 'zymarg-reviews-engine' ),
						'fields' => [
							[
								'key'   => 'reviews_customer_replies_per_review',
								'type'  => 'number',
								'label' => __( 'Customer replies per review', 'zymarg-reviews-engine' ),
								'min'   => 0,
								'max'   => 50,
								'desc'  => __( 'How many times one customer may reply to the same review. 0 means unlimited. Replies waiting for approval count towards the total.', 'zymarg-reviews-engine' ),
							],
							[
								'key'   => 'reviews_seller_replies_per_review',
								'type'  => 'number',
								'label' => __( 'Seller replies per review', 'zymarg-reviews-engine' ),
								'min'   => 0,
								'max'   => 50,
								'desc'  => __( 'How many times the seller or a shop manager may reply to the same review. 0 means unlimited. Set it to 1 for one official answer per review.', 'zymarg-reviews-engine' ),
							],
							[
								'key'   => 'reviews_reply_rate_limit',
								'type'  => 'number',
								'label' => __( 'Flood guard: replies per window', 'zymarg-reviews-engine' ),
								'min'   => 0,
								'max'   => 100,
								'desc'  => __( 'Total replies one user may post across all reviews inside the window below. 0 turns the guard off.', 'zymarg-reviews-engine' ),
							],
							[
								'key'   => 'reviews_reply_rate_minutes',
								'type'  => 'number',
								'label' => __( 'Flood guard: window in minutes', 'zymarg-reviews-engine' ),
								'min'   => 1,
								'max'   => 1440,
							],
							[ 'key' => 'reviews_reply_rate_sellers', 'type' => 'toggle', 'label' => __( 'Apply the flood guard to sellers too', 'zymarg-reviews-engine' ), 'desc' => __( 'Off by default: sellers and shop managers are trusted and answer many reviews in one sitting.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Writing a review', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_my_account_button', 'type' => 'toggle', 'label' => __( 'Show the review button in My Account', 'zymarg-reviews-engine' ), 'desc' => __( 'Adds a Write a Review link to each eligible item on completed orders. Without it a buyer has no way to reach the review form.', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'form' => [
				'label'    => __( 'Review Form', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Visibility', 'zymarg-reviews-engine' ),
						'fields' => [
							// Verified buyers only is the sole supported mode: the submission
							// handler requires a valid order, order item and URL nonce, so the
							// other two options rendered a form that always failed to submit.
							[ 'key' => 'reviews_form_visibility', 'type' => 'select', 'label' => __( 'Who sees the form', 'zymarg-reviews-engine' ), 'options' => [ 'gated' => __( 'Verified buyers only', 'zymarg-reviews-engine' ) ] ],
						],
					],
					[
						'title'  => __( 'Copy', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_form_heading', 'type' => 'text', 'label' => __( 'Form heading', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_subheading', 'type' => 'text', 'label' => __( 'Form subheading', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_body_placeholder', 'type' => 'text', 'label' => __( 'Body placeholder', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_submit_label', 'type' => 'text', 'label' => __( 'Submit button label', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_success_message', 'type' => 'text', 'label' => __( 'Success message', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_button_label', 'type' => 'text', 'label' => __( 'Open-form button label', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_button_label_done', 'type' => 'text', 'label' => __( 'Button label after submitting', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Validation', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_form_require_rating', 'type' => 'toggle', 'label' => __( 'Star rating required', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_min_length', 'type' => 'number', 'label' => __( 'Minimum body length', 'zymarg-reviews-engine' ), 'min' => 0, 'max' => 2000, 'desc' => __( 'Characters. 0 disables the check.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_form_max_length', 'type' => 'number', 'label' => __( 'Maximum body length', 'zymarg-reviews-engine' ), 'min' => 50, 'max' => 20000 ],
						],
					],
				],
			],

			'media' => [
				'label'    => __( 'Media', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Photos', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_allow_media_upload', 'type' => 'toggle', 'label' => __( 'Allow photo uploads', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_max_media_files', 'type' => 'number', 'label' => __( 'Maximum files per review', 'zymarg-reviews-engine' ), 'min' => 1, 'max' => 20 ],
							[ 'key' => 'reviews_max_media_size_kb', 'type' => 'number', 'label' => __( 'Maximum photo size (KB)', 'zymarg-reviews-engine' ), 'min' => 128, 'max' => 51200 ],
							[ 'key' => 'reviews_allowed_image_types', 'type' => 'text', 'label' => __( 'Allowed image types', 'zymarg-reviews-engine' ), 'desc' => __( 'Comma separated extensions.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Video', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_allow_video_upload', 'type' => 'toggle', 'label' => __( 'Allow video uploads', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_max_video_size_kb', 'type' => 'number', 'label' => __( 'Maximum video size (KB)', 'zymarg-reviews-engine' ), 'min' => 1024, 'max' => 102400, 'desc' => __( 'Still bounded by your server upload_max_filesize and post_max_size.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_allowed_video_types', 'type' => 'text', 'label' => __( 'Allowed video types', 'zymarg-reviews-engine' ), 'desc' => __( 'Comma separated extensions.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Gallery', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_enable_gallery', 'type' => 'toggle', 'label' => __( 'Customer media gallery', 'zymarg-reviews-engine' ), 'desc' => __( 'Opens a full gallery when a customer photo or video is clicked.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_show_media_strip', 'type' => 'toggle', 'label' => __( '“Customer photos” strip', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_media_strip_count', 'type' => 'number', 'label' => __( 'Tiles in the strip', 'zymarg-reviews-engine' ), 'min' => 3, 'max' => 24 ],
						],
					],
				],
			],

			'submission' => [
				'label'    => __( 'Submission', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Eligibility', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_require_purchase', 'type' => 'toggle', 'label' => __( 'Require a purchase', 'zymarg-reviews-engine' ), 'desc' => __( 'Only customers who bought the product may submit.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_one_per_product', 'type' => 'toggle', 'label' => __( 'One review per customer per product', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_window_days', 'type' => 'number', 'label' => __( 'Review window (days)', 'zymarg-reviews-engine' ), 'min' => 0, 'max' => 365, 'desc' => __( 'Days after delivery a customer may review. 0 means no limit.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Approval', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_auto_approve_verified', 'type' => 'toggle', 'label' => __( 'Auto-approve verified buyers', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_send_reminder_email', 'type' => 'toggle', 'label' => __( 'Send review reminder emails', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'moderation' => [
				'label'    => __( 'Moderation', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Reported reviews', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_reports_auto_unapprove', 'type' => 'toggle', 'label' => __( 'Auto-unapprove at the threshold', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_reports_threshold', 'type' => 'number', 'label' => __( 'Report threshold', 'zymarg-reviews-engine' ), 'min' => 2, 'max' => 100 ],
							[ 'key' => 'reviews_reports_notify_email', 'type' => 'toggle', 'label' => __( 'Email me on a new report', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_reports_notify_address', 'type' => 'email', 'label' => __( 'Notification address', 'zymarg-reviews-engine' ), 'desc' => __( 'Leave empty to use the site admin email.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Report reasons', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_report_reasons', 'type' => 'textarea', 'label' => __( 'Reasons offered to shoppers', 'zymarg-reviews-engine' ), 'desc' => __( 'One per line. Leave empty for a plain report button with no reason picker.', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'emails' => [
				'label'    => __( 'Emails', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'New review', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_email_admin_new', 'type' => 'toggle', 'label' => __( 'Notify on every new review', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_email_admin_address', 'type' => 'email', 'label' => __( 'Send to', 'zymarg-reviews-engine' ), 'desc' => __( 'Leave empty to use the site admin email.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_email_new_subject', 'type' => 'text', 'label' => __( 'Subject', 'zymarg-reviews-engine' ), 'desc' => __( 'Tokens: {product} {author} {rating}', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_email_new_body', 'type' => 'textarea', 'label' => __( 'Body', 'zymarg-reviews-engine' ), 'desc' => __( 'Tokens: {product} {author} {rating} {review} {link}', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Store reply', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_email_reply_notify', 'type' => 'toggle', 'label' => __( 'Tell the reviewer when the store replies', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_email_reply_subject', 'type' => 'text', 'label' => __( 'Subject', 'zymarg-reviews-engine' ), 'desc' => __( 'Tokens: {product} {author}', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_email_reply_body', 'type' => 'textarea', 'label' => __( 'Body', 'zymarg-reviews-engine' ), 'desc' => __( 'Tokens: {product} {author} {reply} {link}', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],

			'advanced' => [
				'label'    => __( 'Advanced', 'zymarg-reviews-engine' ),
				'sections' => [
					[
						'title'  => __( 'Performance', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_cache_ttl', 'type' => 'number', 'label' => __( 'Cache lifetime (seconds)', 'zymarg-reviews-engine' ), 'min' => 0, 'max' => 86400, 'desc' => __( '0 disables caching of computed review data.', 'zymarg-reviews-engine' ) ],
							[ 'key' => 'reviews_load_base_css', 'type' => 'toggle', 'label' => __( 'Load the engine stylesheet', 'zymarg-reviews-engine' ), 'desc' => __( 'Turn off if a consumer plugin or theme provides its own complete styling.', 'zymarg-reviews-engine' ) ],
						],
					],
					[
						'title'  => __( 'Data', 'zymarg-reviews-engine' ),
						'fields' => [
							[ 'key' => 'reviews_delete_data_on_uninstall', 'type' => 'toggle', 'label' => __( 'Delete settings on uninstall', 'zymarg-reviews-engine' ), 'desc' => __( 'Reviews, votes and media are never deleted — only this plugin’s settings.', 'zymarg-reviews-engine' ) ],
						],
					],
				],
			],
		];
	}

	// ── Page ────────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$schema = $this->schema();
		$values = Settings::all();
		$first  = array_key_first( $schema );
		?>
		<div class="wrap zymarg-re">
			<h1 class="zymarg-re__title"><?php esc_html_e( 'ZYMARG Reviews Engine', 'zymarg-reviews-engine' ); ?></h1>
			<p class="zymarg-re__sub">
				<?php esc_html_e( 'Every review setting lives here. Consumers such as the Single Product page read from this engine.', 'zymarg-reviews-engine' ); ?>
			</p>

			<div class="zymarg-re__layout">
				<nav class="zymarg-re__tabs" role="tablist">
					<?php foreach ( $schema as $tab_id => $tab ) : ?>
						<button type="button"
							class="zymarg-re__tab<?php echo $tab_id === $first ? ' is-active' : ''; ?>"
							role="tab"
							aria-selected="<?php echo $tab_id === $first ? 'true' : 'false'; ?>"
							data-re-tab="<?php echo esc_attr( $tab_id ); ?>">
							<?php echo esc_html( $tab['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</nav>

				<form class="zymarg-re__form" data-re-form novalidate>
					<?php foreach ( $schema as $tab_id => $tab ) : ?>
						<section class="zymarg-re__panel<?php echo $tab_id === $first ? ' is-active' : ''; ?>"
							data-re-panel="<?php echo esc_attr( $tab_id ); ?>" role="tabpanel">
							<?php foreach ( $tab['sections'] as $section ) : ?>
								<div class="zymarg-re__section">
									<h2><?php echo esc_html( $section['title'] ); ?></h2>
									<?php foreach ( $section['fields'] as $field ) : ?>
										<?php $this->render_field( $field, $values ); ?>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>

							<?php if ( 'advanced' === $tab_id ) : ?>
								<div class="zymarg-re__section">
									<h2><?php esc_html_e( 'Export / import', 'zymarg-reviews-engine' ); ?></h2>
									<p class="zymarg-re__desc"><?php esc_html_e( 'Copy this JSON to move settings between sites, or paste a saved copy and import.', 'zymarg-reviews-engine' ); ?></p>
									<textarea class="zymarg-re__json" data-re-json rows="8" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
									<p>
										<button type="button" class="button" data-re-import><?php esc_html_e( 'Import from box', 'zymarg-reviews-engine' ); ?></button>
										<button type="button" class="button button-link-delete" data-re-reset><?php esc_html_e( 'Reset all settings', 'zymarg-reviews-engine' ); ?></button>
									</p>
								</div>

								<div class="zymarg-re__section">
									<h2><?php esc_html_e( 'Migration', 'zymarg-reviews-engine' ); ?></h2>
									<p class="zymarg-re__desc">
										<?php
										$migrated = get_option( Settings::MIGRATED );
										if ( $migrated ) {
											/* translators: %s: ISO timestamp. */
											echo esc_html( sprintf( __( 'Settings were migrated from ZYMARG Single Product on %s.', 'zymarg-reviews-engine' ), $migrated ) );
										} else {
											esc_html_e( 'No migration has run yet.', 'zymarg-reviews-engine' );
										}
										?>
									</p>
								</div>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>

					<div class="zymarg-re__bar">
						<button type="submit" class="button button-primary" data-re-save><?php esc_html_e( 'Save changes', 'zymarg-reviews-engine' ); ?></button>
						<span class="zymarg-re__status" data-re-status aria-live="polite"></span>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one field row.
	 *
	 * @param array $field  Field definition.
	 * @param array $values Current values.
	 */
	private function render_field( array $field, array $values ): void {
		$key   = $field['key'];
		$type  = $field['type'];
		$value = $values[ $key ] ?? '';
		$id    = 'zre-' . str_replace( '_', '-', $key );
		?>
		<div class="zymarg-re__field zymarg-re__field--<?php echo esc_attr( $type ); ?>">
			<label class="zymarg-re__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<div class="zymarg-re__control">
				<?php if ( 'toggle' === $type ) : ?>
					<label class="zymarg-re__switch">
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" data-re-key="<?php echo esc_attr( $key ); ?>" <?php checked( (bool) $value ); ?>>
						<span class="zymarg-re__slider" aria-hidden="true"></span>
					</label>

				<?php elseif ( 'select' === $type ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" data-re-key="<?php echo esc_attr( $key ); ?>">
						<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
							<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( (string) $value, (string) $opt_val ); ?>>
								<?php echo esc_html( $opt_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

				<?php elseif ( 'number' === $type ) : ?>
					<input type="number" id="<?php echo esc_attr( $id ); ?>" data-re-key="<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						min="<?php echo esc_attr( (string) ( $field['min'] ?? 0 ) ); ?>"
						max="<?php echo esc_attr( (string) ( $field['max'] ?? 999999 ) ); ?>" step="1">

				<?php elseif ( 'textarea' === $type ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" data-re-key="<?php echo esc_attr( $key ); ?>" rows="5"><?php echo esc_textarea( (string) $value ); ?></textarea>

				<?php else : ?>
					<input type="<?php echo 'email' === $type ? 'email' : ( 'url' === $type ? 'url' : 'text' ); ?>"
						id="<?php echo esc_attr( $id ); ?>" data-re-key="<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text">
				<?php endif; ?>

				<?php if ( ! empty( $field['desc'] ) ) : ?>
					<p class="zymarg-re__desc"><?php echo esc_html( $field['desc'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── AJAX ────────────────────────────────────────────────────────────

	public function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'zymarg-reviews-engine' ) ], 403 );
		}

		$raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to save.', 'zymarg-reviews-engine' ) ], 400 );
		}

		$saved = Settings::update( $raw );
		wp_send_json_success(
			[
				'message'  => __( 'Settings saved', 'zymarg-reviews-engine' ),
				'settings' => $saved,
			]
		);
	}

	public function ajax_reset(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'zymarg-reviews-engine' ) ], 403 );
		}

		Settings::reset();
		wp_send_json_success(
			[
				'message'  => __( 'Settings reset', 'zymarg-reviews-engine' ),
				'settings' => Settings::all(),
			]
		);
	}

	public function ajax_import(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'zymarg-reviews-engine' ) ], 403 );
		}

		$raw = isset( $_POST['json'] ) ? json_decode( wp_unslash( $_POST['json'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'That is not valid settings JSON.', 'zymarg-reviews-engine' ) ], 400 );
		}

		$saved = Settings::update( $raw );
		wp_send_json_success(
			[
				'message'  => __( 'Settings imported', 'zymarg-reviews-engine' ),
				'settings' => $saved,
			]
		);
	}
}
