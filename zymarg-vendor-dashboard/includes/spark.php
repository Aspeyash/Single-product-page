<?php
/**
 * ZYMARG Discovery Spark -- shared brand mark and loading indicator.
 *
 * The Spark is BOTH the logo and the only loading indicator allowed anywhere
 * in ZYMARG, front end and back end. There are no spinners in this system.
 *
 * IMPORTANT -- function naming. The canonical helper `zymarg_discovery_spark()`
 * is owned by the zymarg-os THEME (inc/discovery-spark.php), which declares it
 * WITHOUT a function_exists guard. Plugins load before the theme, so if this
 * plugin declared that name the theme would fatal with "Cannot redeclare".
 *
 * Therefore this plugin NEVER declares a shared global name. It declares
 * plugin-prefixed wrappers that delegate to the theme's canonical helpers when
 * they exist and fall back to a local copy when they do not (theme switched,
 * theme not yet loaded, or the plugin used standalone).
 *
 * Do not "simplify" these wrappers back to the shared names.
 *
 * The two shared STYLESHEET handles are different: WordPress deduplicates
 * styles by handle, so those stay canonical and are guarded with wp_style_is.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zymarg_vd_register_shared_brand_assets' ) ) {
	/**
	 * Register the two shared ZYMARG stylesheets under their canonical handles.
	 *
	 * The handles MUST be spelled identically in every ZYMARG plugin.
	 * WordPress deduplicates by handle, so a mismatch means two copies load and
	 * the later one silently wins for every plugin on the page.
	 *
	 * The shared files are versioned independently of the plugin: bump
	 * ZYMARG_TOKENS_VERSION only when a token actually changes.
	 *
	 * @return void
	 */
	function zymarg_vd_register_shared_brand_assets() {
		if ( ! defined( 'ZYMARG_TOKENS_VERSION' ) ) {
			define( 'ZYMARG_TOKENS_VERSION', '2.0.0' );
		}

		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_VD_URL . 'assets/css/zymarg-tokens.css',
				array(),
				ZYMARG_TOKENS_VERSION
			);
		}

		if ( ! wp_style_is( 'zymarg-spark', 'registered' ) ) {
			wp_register_style(
				'zymarg-spark',
				ZYMARG_VD_URL . 'assets/css/zymarg-spark.css',
				array( 'zymarg-tokens' ),
				ZYMARG_TOKENS_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'zymarg_vd_register_shared_brand_assets', 1 );
	add_action( 'wp_enqueue_scripts', 'zymarg_vd_register_shared_brand_assets', 1 );
}

if ( ! function_exists( 'zymarg_vd_spark' ) ) {
	/**
	 * Return the Discovery Spark markup.
	 *
	 * Delegates to the theme's zymarg_discovery_spark() when that exists so the
	 * theme stays the single source of truth for the brand mark. Only falls back
	 * to the local copy below when the theme helper is absent.
	 *
	 * The SVG is copied verbatim from the brand document. Do not re-path it, do
	 * not merge the groups, and do not run it through an SVG optimiser -- the
	 * group classes drive the animation and an optimiser will strip them.
	 *
	 * Always size with a size class, never with a transform scale, which would
	 * leave the lens offset behind.
	 *
	 * @param array $args {
	 *     Optional arguments.
	 *
	 *     @type string $size  One of sm|md|lg|xl|xxl|header. Default 'md'.
	 *     @type string $label Accessible label. Default 'ZYMARG Discovery Spark'.
	 *     @type string $class Extra classes for the wrapper span.
	 * }
	 * @return string Escaped-safe SVG markup.
	 */
	function zymarg_vd_spark( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'size'  => 'md',
				'label' => __( 'ZYMARG Discovery Spark', 'zymarg-vendor-dashboard' ),
				'class' => '',
			)
		);

		$allowed = array( 'sm', 'md', 'lg', 'xl', 'xxl', 'header' );
		$size    = in_array( $args['size'], $allowed, true ) ? $args['size'] : 'md';

		// The theme owns the mark. Hand off to it whenever it is loaded.
		if ( function_exists( 'zymarg_discovery_spark' ) ) {
			$theme_args = $args;

			// 'header' is a slot this plugin adds for the 44px wp-admin header and
			// the theme does not know it. Ask for a size the theme definitely has
			// and let .zvd-header__mark size the SVG with width/height in CSS
			// (never a transform scale, which would strip the lens offset).
			if ( 'header' === $size ) {
				$theme_args['size']  = 'lg';
				$theme_args['class'] = trim( $args['class'] . ' zymarg-spark--header' );
			}

			return zymarg_discovery_spark( $theme_args );
		}

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

if ( ! function_exists( 'zymarg_vd_loading' ) ) {
	/**
	 * Return a complete loading region: Spark plus the required visible text.
	 *
	 * One Spark per loading region. Never a spinner.
	 *
	 * @param string $text Visible status text. Default 'Loading'.
	 * @param string $size Spark size class suffix. Default 'md'.
	 * @return string
	 */
	function zymarg_vd_loading( $text = '', $size = 'md' ) {
		if ( '' === $text ) {
			$text = __( 'Loading', 'zymarg-vendor-dashboard' );
		}

		if ( function_exists( 'zymarg_loading' ) ) {
			return zymarg_loading( $text, $size );
		}

		return '<div class="zymarg-loading" role="status" aria-live="polite">'
			. zymarg_vd_spark( array( 'size' => $size, 'label' => $text ) )
			. '<span class="zymarg-loading__text">' . esc_html( $text ) . '</span>'
			. '</div>';
	}
}
