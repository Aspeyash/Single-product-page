<?php
/**
 * Plugin Name:       ZYMARG Single Product
 * Plugin URI:        https://github.com/Aspeyash/zymarg-single-product
 * Description:       Standalone single product page template for ZYMARG Marketplace. Overrides WooCommerce default single product template with a fully custom layout including gallery, swatches, price display, add to cart, buy now, seller card, and reviews (rendered by ZYMARG Reviews Engine). All sections are configurable from the admin panel.
 * Version:           2.6.0
 * Author:            ZYMARG
 * Author URI:        https://github.com/Aspeyash
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zymarg-single-product
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 *
 * @package ZymargSingleProduct
 *
 * ─────────────────────────────────────────────────────────────────────────
 * IMPORTANT — sibling plugin runs alongside this one on the ZYMARG site
 * ─────────────────────────────────────────────────────────────────────────
 * "ZYMARG Store Page" (slug: zymarg-store-page, replaces Dokan's vendor
 * store page) is a SEPARATE plugin that is routinely activated on the SAME
 * WordPress install as this plugin. It is NOT a dependency of this plugin
 * and this plugin does not require it, but assume it may be active at any
 * time when writing new code here.
 *
 * Known history: both plugins previously defined an IDENTICAL set of PHP
 * constants (ZYMARG_SP_VERSION, ZYMARG_SP_FILE, ZYMARG_SP_URL, etc.) because
 * "SP" was independently short for "Single Product" here and "Store Page"
 * over there. Whichever plugin's main file loaded first silently won each
 * define() (a duplicate define() is a non-fatal PHP notice, not a crash),
 * so the OTHER plugin silently inherited the wrong plugin's version string,
 * file path, and asset URL -- broken/missing CSS and JS with no visible
 * error. Fixed in v2.4.6 by renaming this plugin's constants to the
 * ZYMARG_SNGL_* prefix and adding if ( ! defined() ) guards.
 *
 * RULE FOR ALL FUTURE WORK ON THIS PLUGIN:
 * 1. Never introduce a PHP constant, function name, class name (outside the
 *    ZymargSP\ namespace already used here), WordPress option name, admin
 *    menu slug, or wp_ajax_ action name using a bare "ZYMARG_SP_" / "SP_" /
 *    "zymarg_sp_" style prefix -- that exact family belongs to ZYMARG Store
 *    Page too. Prefer ZYMARG_SNGL_* / zymarg_sngl_* for anything new here.
 * 2. Enqueue handles and CSS classes that are genuinely meant to be SHARED
 *    across every ZYMARG plugin (e.g. the `zymarg-tokens` design-token
 *    stylesheet) are fine and intentional -- do not "fix" those. The
 *    problem is only accidental collisions on names that were meant to be
 *    private to one plugin.
 * 3. Before adding any new global-scope identifier, grep the ZYMARG Store
 *    Page plugin source (when available) for the same string first.
 * ─────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────────────
// Renamed from ZYMARG_SP_* (v2.4.5 and earlier) to ZYMARG_SNGL_* in v2.4.6.
// ZYMARG_SP_* is also used by the separate "ZYMARG Store Page" plugin for its
// own (unrelated) constants of the same name. With both plugins active,
// whichever one's main file loaded first would silently "win" the define(),
// and the other would inherit the wrong plugin's file path / URL / version
// (a duplicate define() is a non-fatal PHP notice, not a crash, so the
// breakage was silent: wrong asset URLs, wrong plugin_basename() references).
// Guarded with if ( ! defined() ) as defense-in-depth against any future
// third plugin picking the same name.
if ( ! defined( 'ZYMARG_SNGL_VERSION' ) ) {
	define( 'ZYMARG_SNGL_VERSION',   '2.6.0' );
}
if ( ! defined( 'ZYMARG_SNGL_FILE' ) ) {
	define( 'ZYMARG_SNGL_FILE',      __FILE__ );
}
if ( ! defined( 'ZYMARG_SNGL_PATH' ) ) {
	define( 'ZYMARG_SNGL_PATH',      plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ZYMARG_SNGL_URL' ) ) {
	define( 'ZYMARG_SNGL_URL',       plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'ZYMARG_SNGL_BASENAME' ) ) {
	define( 'ZYMARG_SNGL_BASENAME',  plugin_basename( __FILE__ ) );
}
if ( ! defined( 'ZYMARG_SNGL_ASSETS' ) ) {
	define( 'ZYMARG_SNGL_ASSETS',    ZYMARG_SNGL_URL  . 'assets/' );
}
if ( ! defined( 'ZYMARG_SNGL_TPL_PATH' ) ) {
	define( 'ZYMARG_SNGL_TPL_PATH',  ZYMARG_SNGL_PATH . 'templates/' );
}

// ── HPOS compatibility ─────────────────────────────────────────────────────
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

// ── Bootstrap ──────────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain(
			'zymarg-single-product',
			false,
			dirname( ZYMARG_SNGL_BASENAME ) . '/languages'
		);

		require_once ZYMARG_SNGL_PATH . 'includes/class-options.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-sections.php';
		// v2.5.1 - ZYMARG Discovery Spark accessor. Loaded early, before
		// class-assets.php and class-admin.php, since both call zymarg_sngl_spark().
		require_once ZYMARG_SNGL_PATH . 'includes/spark.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-assets.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-template-override.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-buy-now.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-wishlist-ajax.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-breadcrumbs.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-seller-card.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-price-renderer.php';

		// Native swatches engine (WSE_* → native port).
		require_once ZYMARG_SNGL_PATH . 'includes/swatches/class-attribute-types.php';
		require_once ZYMARG_SNGL_PATH . 'includes/swatches/class-term-meta.php';
		require_once ZYMARG_SNGL_PATH . 'includes/swatches/class-renderer.php';
		require_once ZYMARG_SNGL_PATH . 'includes/swatches/class-product-video.php';

		require_once ZYMARG_SNGL_PATH . 'includes/class-admin.php';
		require_once ZYMARG_SNGL_PATH . 'includes/class-plugin.php';

		ZymargSP\Plugin::instance()->init();
	},
	5
);

// ── Activation / deactivation ──────────────────────────────────────────────
register_activation_hook(
	__FILE__,
	function () {
		require_once ZYMARG_SNGL_PATH . 'includes/class-options.php';
		ZymargSP\Options::set_defaults();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);

// ── Reviews Engine soft dependency (v2.0.0) ────────────────────────
// Reviews used to live inside this plugin. They now come from the standalone
// ZYMARG Reviews Engine plugin. The dependency is soft: everything else on the
// single product page keeps working when the engine is missing, we only hide
// the reviews accordion and warn administrators.

function zymarg_sp_reviews_engine_active(): bool {
	return function_exists( 'zymarg_reviews_render' );
}

add_action(
	'admin_notices',
	function () {
		if ( zymarg_sp_reviews_engine_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'zymarg_sp_engine_notice_off', true ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible" id="zymarg-sp-engine-notice">
			<p>
				<strong><?php esc_html_e( 'ZYMARG Single Product', 'zymarg-single-product' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'the Reviews accordion needs the ZYMARG Reviews Engine plugin. Install and activate it to show product reviews again. Your existing reviews and review settings are untouched.', 'zymarg-single-product' ); ?>
			</p>
		</div>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '#zymarg-sp-engine-notice .notice-dismiss' );
			if ( ! btn ) { return; }
			var body = new FormData();
			body.append( 'action', 'zymarg_sp_dismiss_engine_notice' );
			body.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'zymarg_sp_engine_notice' ) ); ?>' );
			fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', body: body } );
		} );
		</script>
		<?php
	}
);

add_action(
	'wp_ajax_zymarg_sp_dismiss_engine_notice',
	function () {
		check_ajax_referer( 'zymarg_sp_engine_notice', 'nonce' );
		update_user_meta( get_current_user_id(), 'zymarg_sp_engine_notice_off', 1 );
		wp_send_json_success();
	}
);
