<?php
/**
 * ZYMARG Vendor Dashboard — Dokan Pro compatibility (hybrid / Option C).
 *
 * When Dokan Pro is active, the native ZYMARG modules that would overlap a Pro
 * feature automatically stand down so Pro owns that feature — no duplication,
 * no conflict. When Pro (or the specific Pro module) is absent, the native
 * ZYMARG modules run, so the dashboard stays fully featured on free Dokan Lite.
 *
 * This layer ONLY READS whether Pro / its modules are active. It never
 * activates anything, never touches a licence, and adds no paid dependency:
 * everything ZYMARG provides remains free and self-contained.
 *
 * C1 behaviour: when Pro is active and the visitor is on one of Dokan's own
 * dashboard sub-pages (e.g. Return Requests, Table Rate Shipping), the ZYMARG
 * shell steps aside so Pro renders its real UI there. The ZYMARG shell still
 * owns the base dashboard and its native-only sections (reached via ?vsection=).
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is Dokan Pro active?
 *
 * @return bool
 */
function zymarg_vd_pro_active() {
	return function_exists( 'dokan_pro' ) || defined( 'DOKAN_PRO_PLUGIN_VERSION' );
}

/**
 * Is a specific Dokan Pro module active? Defensive — never fatals if the Pro
 * API shape changes.
 *
 * @param string $slug Module slug (e.g. 'rma', 'seller_vacation', 'table_rate_shipping').
 * @return bool
 */
function zymarg_vd_pro_module_active( $slug ) {
	if ( ! function_exists( 'dokan_pro' ) ) {
		return false;
	}
	try {
		$pro = dokan_pro();
		if ( ! is_object( $pro ) || ! isset( $pro->module ) || ! is_object( $pro->module ) ) {
			return false;
		}
		if ( ! method_exists( $pro->module, 'is_active' ) ) {
			return false;
		}
		return (bool) $pro->module->is_active( $slug );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Is a dedicated SEO solution present (Yoast / Rank Math / Dokan Rank Math
 * module)? If so, the native ZYMARG store-SEO output stands down to avoid
 * duplicate meta tags.
 *
 * @return bool
 */
function zymarg_vd_seo_solution_active() {
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return true;
	}
	return zymarg_vd_pro_module_active( 'rank_math' );
}

/**
 * Are we currently on one of Dokan's own dashboard sub-pages (an endpoint
 * deeper than the dashboard base, e.g. /dashboard/return-request/)?
 *
 * The ZYMARG native sections live on the base path with a ?vsection= query
 * arg, so they are NOT treated as Dokan sub-pages.
 *
 * @return bool
 */
function zymarg_vd_on_dokan_subpage() {
	if ( ! function_exists( 'dokan_get_navigation_url' ) ) {
		return false;
	}
	global $wp;
	if ( ! isset( $wp ) || ! is_object( $wp ) ) {
		return false;
	}

	$base = trim( (string) wp_parse_url( dokan_get_navigation_url(), PHP_URL_PATH ), '/' );
	if ( '' === $base ) {
		return false;
	}
	$current = trim( (string) ( isset( $wp->request ) ? $wp->request : '' ), '/' );
	if ( '' === $current || $current === $base ) {
		return false;
	}

	return 0 === strpos( $current . '/', $base . '/' );
}

/**
 * C1: let the ZYMARG shell step aside on Dokan's own dashboard sub-pages when
 * Pro is active, so Pro renders its native UI there.
 *
 * @param bool $is Whether the current request is the ZYMARG dashboard context.
 * @return bool
 */
function zymarg_vd_pro_step_aside( $is ) {
	if ( $is && zymarg_vd_pro_active() && zymarg_vd_on_dokan_subpage() ) {
		return false;
	}
	return $is;
}
add_filter( 'zymarg_os_is_vendor_dashboard', 'zymarg_vd_pro_step_aside', 20 );

/**
 * Whether Pro's seller-vacation module owns vacation handling.
 *
 * @return bool
 */
function zymarg_vd_vacation_managed_by_pro() {
	return zymarg_vd_pro_module_active( 'seller_vacation' );
}
