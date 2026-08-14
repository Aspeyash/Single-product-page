<?php
/**
 * ZYMARG Discovery Spark -- brand mark accessor for this plugin.
 *
 * The Spark is both the logo and the only loading indicator allowed in ZYMARG.
 * There are no spinners in this system.
 *
 * IMPORTANT -- function naming. The canonical helper `zymarg_discovery_spark()`
 * is owned by the zymarg-os THEME (inc/discovery-spark.php), which declares it
 * WITHOUT a function_exists guard. Plugins load before the theme, so a plugin
 * that declares that name makes the theme fatal with "Cannot redeclare".
 *
 * This plugin therefore NEVER declares a shared global name. It declares one
 * plugin-prefixed wrapper that delegates, in order, to:
 *
 *   1. zymarg_discovery_spark()  -- the theme, canonical owner of the mark
 *   2. zymarg_vd_spark()         -- the Vendor Dashboard plugin's wrapper
 *   3. a local fallback copy     -- standalone use, or theme switched
 *
 * Do not "simplify" this wrapper back to the shared name.
 *
 * Stylesheet handles are a different matter: WordPress deduplicates styles by
 * handle, so `zymarg-tokens` and `zymarg-spark` stay canonical and are guarded
 * with wp_style_is() instead.
 *
 * @package ZYMARG_Store_Page
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zymarg_sp_register_shared_brand_assets' ) ) {
	/**
	 * Register the two shared ZYMARG stylesheets under their canonical handles.
	 *
	 * The handles MUST be spelled identically in every ZYMARG plugin. WordPress
	 * deduplicates by handle, so a mismatch means two copies load and the later
	 * one silently wins for every plugin on the page.
	 *
	 * Whichever ZYMARG plugin runs first supplies the files; the others defer.
	 *
	 * @return void
	 */
	function zymarg_sp_register_shared_brand_assets() {
		if ( ! defined( 'ZYMARG_TOKENS_VERSION' ) ) {
			define( 'ZYMARG_TOKENS_VERSION', '2.0.0' );
		}

		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_SP_URL . 'assets/css/zymarg-tokens.css',
				array(),
				ZYMARG_TOKENS_VERSION
			);
		}

		if ( ! wp_style_is( 'zymarg-spark', 'registered' ) ) {
			wp_register_style(
				'zymarg-spark',
				ZYMARG_SP_URL . 'assets/css/zymarg-spark.css',
				array( 'zymarg-tokens' ),
				ZYMARG_TOKENS_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'zymarg_sp_register_shared_brand_assets', 1 );
	add_action( 'wp_enqueue_scripts', 'zymarg_sp_register_shared_brand_assets', 1 );
}

if ( ! function_exists( 'zymarg_sp_spark' ) ) {
	/**
	 * Return the Discovery Spark markup.
	 *
	 * Always size with a size class, never with a transform scale, which would
	 * leave the lens offset behind.
	 *
	 * @param array $args {
	 *     Optional arguments.
	 *
	 *     @type string $size  One of sm|md|lg|xl|xxl|header. Default 'md'.
	 *     @type string $label Accessible label.
	 *     @type string $class Extra classes for the wrapper span.
	 * }
	 * @return string Spark markup.
	 */
	function zymarg_sp_spark( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'size'  => 'md',
				'label' => __( 'ZYMARG Discovery Spark', 'zymarg-store-page' ),
				'class' => '',
			)
		);

		$allowed = array( 'sm', 'md', 'lg', 'xl', 'xxl', 'header' );
		$size    = in_array( $args['size'], $allowed, true ) ? $args['size'] : 'md';

		// The theme owns the mark. Hand off to it whenever it is loaded.
		if ( function_exists( 'zymarg_discovery_spark' ) ) {
			$theme_args = $args;

			// 'header' is the 44px wp-admin slot, which the theme does not know
			// about. Ask for a size it definitely has and let the CSS class do
			// the sizing with width/height -- never a transform scale.
			if ( 'header' === $size ) {
				$theme_args['size']  = 'lg';
				$theme_args['class'] = trim( $args['class'] . ' zymarg-spark--header' );
			}

			return zymarg_discovery_spark( $theme_args );
		}

		// Next preference: the Vendor Dashboard plugin's wrapper, if installed.
		if ( function_exists( 'zymarg_vd_spark' ) ) {
			return zymarg_vd_spark( $args );
		}

		// Local fallback. The SVG is copied verbatim from the brand document.
		// Do not re-path it, do not merge the groups, and do not run it through
		// an SVG optimiser -- the group classes drive the animation and an
		// optimiser will strip them.
		$classes = trim( 'zymarg-spark zymarg-spark--' . $size . ' ' . $args['class'] );

		ob_start();
		?>
<span class="<?php echo esc_attr( $classes ); ?>">
	<svg class="zymarg-spark__svg"
		 viewBox="0 0 24 24"
		 xmlns="http://www.w3.org/2000/svg"
		 role="img"
		 aria-label="<?php echo esc_attr( $args['label'] ); ?>"
		 focusable="false">

		<g class="zymarg-spark-group--accent">
			<path class="zymarg-spark-item--purple"
				  d="M10.4 5.4c0 1.32-0.24 2.4-1.44 2.4 1.2 0 1.44 1.08 1.44 2.4 0-1.32 0.24-2.4 1.44-2.4-1.2 0-1.44-1.08-1.44-2.4z"/>
			<path class="zymarg-spark-item--gold"
				  d="M10.4 6.0c0 0.96-0.18 1.8-1.08 1.8 0.9 0 1.08 0.84 1.08 1.8 0-0.9 0.18-1.8 1.08-1.8-0.9 0-1.08-0.84-1.08-1.8z"/>
		</g>

		<g class="zymarg-spark-group--companion">
			<path class="zymarg-spark-item--purple"
				  d="M9.5 10.92c0 2.25-0.45 4.12-2.4 4.12 1.95 0 2.4 1.87 2.4 4.12 0-2.25 0.45-4.12 2.4-4.12-1.95 0-2.4-1.87-2.4-4.12z"/>
			<path class="zymarg-spark-item--gold"
				  d="M9.5 11.5c0 1.9-0.38 3.54-2.0 3.54 1.62 0 2.0 1.64 2.0 3.54 0-1.9 0.38-3.54 2.0-3.54-1.62 0-2.0-1.64-2.0-3.54z"/>
		</g>

		<g class="zymarg-spark-group--hero">
			<path class="zymarg-spark-item--purple"
				  d="M15.2 5.6c0 3.45-0.69 6.3-4.08 6.3 3.39 0 4.08 2.85 4.08 6.3 0-3.45 0.69-6.3 4.08-6.3-3.39 0-4.08-2.85-4.08-6.3z"/>
			<path class="zymarg-spark-item--gold"
				  d="M15.2 6.5c0 2.9-0.58 5.4-3.39 5.4 2.81 0 3.39 2.5 3.39 5.4 0-2.9 0.58-5.4 3.39-5.4-2.81 0-3.39-2.5-3.39-5.4z"/>
		</g>

	</svg>
</span>
		<?php
		return ob_get_clean();
	}
}
