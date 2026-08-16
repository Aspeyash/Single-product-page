<?php
/**
 * Plugin Name:       ZYMARG Reviews Engine
 * Plugin URI:        https://zymarg.com/
 * Description:       Product review engine: data, settings, submission, media, moderation and rendering. Consumed by ZYMARG Single Product, ZYMARG Single Store and any other plugin, page or shortcode.
 * Version:           1.3.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            ZYMARG
 * Text Domain:       zymarg-reviews-engine
 * Domain Path:       /languages
 *
 * @package ZymargReviewsEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -- Constants ---------------------------------------------------------------
define( 'ZYMARG_RE_VERSION',    '1.3.2' );
define( 'ZYMARG_RE_FILE',       __FILE__ );
define( 'ZYMARG_RE_PATH',       plugin_dir_path( __FILE__ ) );
define( 'ZYMARG_RE_URL',        plugin_dir_url( __FILE__ ) );
define( 'ZYMARG_RE_ASSETS_URL', ZYMARG_RE_URL . 'assets/' );
define( 'ZYMARG_RE_TPL_PATH',   ZYMARG_RE_PATH . 'templates/' );

// -- Includes ----------------------------------------------------------------
require_once ZYMARG_RE_PATH . 'includes/class-settings.php';
require_once ZYMARG_RE_PATH . 'includes/class-permissions.php';
require_once ZYMARG_RE_PATH . 'includes/class-icons.php';
require_once ZYMARG_RE_PATH . 'includes/class-review-tracker.php';
require_once ZYMARG_RE_PATH . 'includes/class-account-button.php';
require_once ZYMARG_RE_PATH . 'includes/class-data-builder.php';
require_once ZYMARG_RE_PATH . 'includes/class-ajax.php';
require_once ZYMARG_RE_PATH . 'includes/class-reports.php';
require_once ZYMARG_RE_PATH . 'includes/class-assets.php';
require_once ZYMARG_RE_PATH . 'includes/class-shortcode.php';
require_once ZYMARG_RE_PATH . 'includes/class-placement.php';
require_once ZYMARG_RE_PATH . 'includes/class-admin.php';
require_once ZYMARG_RE_PATH . 'includes/api.php';

/**
 * Is a legacy embedded/standalone reviews copy already owning the front end?
 *
 * ZYMARG Single Product < 2.0 ships a complete embedded copy of this code under
 * the ZymargSPReviews namespace. While that copy is active it keeps ownership of
 * the AJAX endpoints and assets, and the engine runs in settings-only mode so
 * handlers are never double-bound.
 */
function zymarg_re_legacy_copy_active(): bool {
	return class_exists( 'ZymargSPReviews\\Plugin' ) || class_exists( 'ZymargReviews\\Ajax' );
}

// -- Boot --------------------------------------------------------------------
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'zymarg-reviews-engine', false, dirname( plugin_basename( ZYMARG_RE_FILE ) ) . '/languages' );

		// Settings are owned by the engine even while a legacy copy still renders
		// the front end, so the admin surface always loads.
		if ( is_admin() ) {
			ZymargReviewsEngine\Admin::instance();
		}

		if ( zymarg_re_legacy_copy_active() ) {
			return;
		}

		ZymargReviewsEngine\Ajax::instance();
		ZymargReviewsEngine\Reports::instance();
		ZymargReviewsEngine\Assets::instance();
		ZymargReviewsEngine\Shortcode::instance();

		// Self-placement. Does nothing until an administrator picks a placement
		// mode, so updating the engine never changes an existing site's output.
		ZymargReviewsEngine\Placement::instance()->init();

		// The My Account "Write a Review" entry point. Needs WooCommerce for the
		// order and account-page functions it hooks into.
		if ( function_exists( 'WC' ) ) {
			ZymargReviewsEngine\Account_Button::instance();
		}
	},
	5
);

// -- Cache invalidation ------------------------------------------------------
/**
 * Flush the cached store-wide aggregate for whichever vendor owns the product a
 * review belongs to.
 *
 * Without this a new, newly approved or newly deleted review would sit behind
 * the six-hour transient and a store's score would visibly disagree with the
 * reviews printed directly underneath it.
 *
 * @param int|string $comment_id Comment ID.
 */
function zymarg_re_flush_vendor_cache_for_comment( $comment_id ): void {
	$comment = get_comment( (int) $comment_id );
	if ( ! $comment || 'review' !== $comment->comment_type ) {
		return;
	}

	$vendor_id = (int) get_post_field( 'post_author', (int) $comment->comment_post_ID );
	ZymargReviewsEngine\Data_Builder::flush_vendor_aggregate( $vendor_id );
}

add_action( 'wp_insert_comment', 'zymarg_re_flush_vendor_cache_for_comment', 10, 1 );

// 'delete_comment', not 'deleted_comment': the row still has to exist for us to
// find out which product, and therefore which vendor, it belonged to.
add_action( 'delete_comment', 'zymarg_re_flush_vendor_cache_for_comment', 10, 1 );

add_action(
	'transition_comment_status',
	function ( $new_status, $old_status, $comment ) {
		if ( $comment instanceof WP_Comment ) {
			zymarg_re_flush_vendor_cache_for_comment( $comment->comment_ID );
		}
	},
	10,
	3
);

add_action(
	'edit_comment',
	function ( $comment_id ) {
		zymarg_re_flush_vendor_cache_for_comment( $comment_id );
	},
	10,
	1
);

// -- Activation --------------------------------------------------------------
register_activation_hook(
	ZYMARG_RE_FILE,
	function () {
		ZymargReviewsEngine\Settings::install();
	}
);

// -- Notice when two placements would collide --------------------------------
/**
 * Warn when the engine is placing the section itself while ZYMARG Single Product
 * is still configured to render its own reviews accordion.
 *
 * The renderer's per-request guard means only one section is actually printed,
 * so this is a configuration smell rather than a broken page. It is worth saying
 * out loud because the surviving section is the consumer's, which is the exact
 * arrangement self-placement is meant to replace.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'ZymargReviewsEngine\\Placement' ) || ! ZymargReviewsEngine\Placement::is_active() ) {
			return;
		}

		$sp = get_option( 'zymarg_sp_settings', array() );
		if ( ! is_array( $sp ) || empty( $sp['show_reviews_tab'] ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'ZYMARG Reviews Engine is set to place the review section itself, but ZYMARG Single Product is still set to show its own reviews accordion. Only one section is printed, and right now it is the one Single Product renders. Turn off “Show Reviews accordion” in ZYMARG Single Product so the engine owns placement.', 'zymarg-reviews-engine' );
		echo '</p></div>';
	}
);

// -- Notice while a legacy copy is active ------------------------------------
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! zymarg_re_legacy_copy_active() ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'ZYMARG Reviews Engine is installed and owns your review settings, but an older embedded copy of the reviews code is still rendering the front end. Update ZYMARG Single Product to 2.0 or later to switch rendering over to the engine.', 'zymarg-reviews-engine' );
		echo '</p></div>';
	}
);
