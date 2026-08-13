<?php
/**
 * Asset Loader
 *
 * Enqueues stylesheets and scripts only on Dokan store pages so that
 * the plugin has zero performance impact on every other page.
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Assets {

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue() {
		// The store directory loads its own, much smaller bundle and then
		// stops. It must not fall through to the store-page block below,
		// where every value is resolved from a single vendor that does not
		// exist on this page.
		if ( class_exists( 'ZYMARG_SP_Store_Listing' ) && ZYMARG_SP_Store_Listing::is_store_listing() ) {
			self::enqueue_listing();
			return;
		}

		if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
			return;
		}

		// ── Fonts ──────────────────────────────────────────────────────────
		wp_enqueue_style(
			'zymarg-inter-font',
			'https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5/index.min.css',
			[],
			ZYMARG_SP_VERSION
		);

		/*
		 * ── Pre-compiled Tailwind (v1.19.1) ───────────────────────────────
		 *
		 * THE LAYOUT SHIFT THIS FIXES
		 *
		 * store.php is written almost entirely in Tailwind utilities -- 117 of
		 * its 140 class attributes -- and the only thing generating those rules
		 * was the browser build enqueued below: a JIT compiler that has to
		 * download from a CDN, parse, scan the DOM and inject a stylesheet
		 * before any of them exist. Until it finished, the page had no layout
		 * at all: no container widths, no grids, no flex. Everything was
		 * full-width block flow, which is why a single product card filled the
		 * screen and the whole page snapped into position a moment later.
		 *
		 * The proof was that the store *listing* and the /flash-sale/ page never
		 * shifted. Both take their layout from ordinary stylesheets -- zsl-
		 * classes in store-listing.css, plain CSS on the flash page -- and only
		 * the store page depended on the compiler.
		 *
		 * This is the same utilities, compiled ahead of time with the Tailwind
		 * v4 CLI against the same @theme block store.php declares, so the output
		 * is what the browser build was producing anyway. It is a normal
		 * stylesheet, so it is in the <head> and in force before first paint.
		 *
		 * Regenerate with assets/css/store-tailwind.src.css after adding
		 * utilities to a template.
		 *
		 * The browser build below is deliberately KEPT. A utility assembled at
		 * runtime in JavaScript cannot be seen by a static scan, so the compiler
		 * remains as the safety net for anything this file missed. It no longer
		 * has to be present for the page to lay out, which was the actual bug.
		 */
		wp_enqueue_style(
			'zymarg-store-tailwind',
			ZYMARG_SP_URL . 'assets/css/store-tailwind.css',
			[],
			ZYMARG_SP_VERSION
		);

		// ── Tailwind CSS v4 (browser build) ───────────────────────────────
		wp_enqueue_script(
			'tailwind-css-v4',
			'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
			[],
			ZYMARG_SP_VERSION,
			false // load in <head> so Tailwind can scan the DOM
		);

		// ── Plugin stylesheet (design tokens + AURA search styles) ────────
		wp_enqueue_style(
			'zymarg-store-page',
			ZYMARG_SP_URL . 'assets/css/store-page.css',
			[ 'zymarg-inter-font', 'zymarg-store-tailwind' ],
			ZYMARG_SP_VERSION
		);

		/*
		 * Content Width (v1.21.0, aligned across plugins in v1.21.1).
		 *
		 * Every section of the store template is capped by the Tailwind utility
		 * max-w-7xl (80rem = 1280px), written directly into the markup rather
		 * than through a token, so there is no variable to retarget. The utility
		 * itself has to be overridden.
		 *
		 * Unscoped by class on purpose: the sections are siblings with no shared
		 * wrapper to hang a selector on. It is still safe, because this rides the
		 * zymarg-store-page handle, which is enqueued on store pages and nowhere
		 * else.
		 *
		 * !important is required rather than lazy. The Tailwind browser build
		 * injects its own .max-w-7xl rule into the document at runtime, after
		 * this sheet and at identical specificity, so it would otherwise win on
		 * source order alone.
		 *
		 * 0 means leave the shipped design alone, matching the Homepage and
		 * Connection Engine plugins, so the three controls read the same way to
		 * an administrator. The 769px floor keeps phones on their own layout.
		 */
		$zsp_w_opts = get_option( 'zymarg_sp_options', [] );
		$zsp_pw     = isset( $zsp_w_opts['page_width'] ) ? (int) $zsp_w_opts['page_width'] : 0;
		if ( $zsp_pw > 0 ) {
			$zsp_pw = min( 100, $zsp_pw );
			wp_add_inline_style(
				'zymarg-store-page',
				'@media (min-width:769px){.max-w-7xl,.zsp-premium{max-width:' . $zsp_pw . 'vw !important;}}'
			);
		}

		// ── Dark mode overlay ──────────────────────────────────────────
		// Loads after the main stylesheet. Inert in light mode.
		wp_enqueue_style(
			'zymarg-store-page-dark',
			ZYMARG_SP_URL . 'assets/css/store-page-dark.css',
			[ 'zymarg-store-page' ],
			ZYMARG_SP_VERSION
		);

		// ── Plugin JavaScript ─────────────────────────────────────────────
		wp_enqueue_script(
			'zymarg-store-page',
			ZYMARG_SP_URL . 'assets/js/store-page.js',
			[],
			ZYMARG_SP_VERSION,
			true // footer
		);

		// ── Premium carousel ──────────────────────────────────────────────
		// v1.18.0: the bespoke Premium carousel is gone. The Premium sections now
		// render through the Product Grid engine, so when the admin chooses the
		// carousel layout the engine's own slider handles it -- including its
		// Swiper bundle, which it enqueues itself. premium-carousel.css/js were
		// removed rather than left loading beside a slider they no longer drive.
		//
		// Card CSS/JS is not enqueued here either: ZYMARG_SP_Grid_Bridge asks the
		// engine where the active card template's assets live and enqueues those,
		// so a Template Pack update ships new styles without a change in this
		// plugin.

		// Pass dynamic data (store ID, API base, shop URL, admin options) to JS.
		$store_user = self::get_current_store_user();
		$opts         = get_option( 'zymarg_sp_options', [] );
		$per_page     = isset( $opts['products_per_page'] ) ? (int) $opts['products_per_page'] : 8;
		$show_aura    = isset( $opts['show_aura_search'] )  ? (bool) $opts['show_aura_search']  : true;
		$show_reviews = isset( $opts['show_reviews'] )      ? (bool) $opts['show_reviews']      : true;
		$no_results_slug = isset( $opts['no_results_slug'] ) ? $opts['no_results_slug'] : 'community';

		$store_id      = $store_user ? (int) $store_user->ID : 0;
		$is_following  = $store_id && class_exists( 'ZYMARG_SP_Follow' )
		                 ? ZYMARG_SP_Follow::current_user_follows( $store_id )
		                 : false;
		$followers_count = $store_id && class_exists( 'ZYMARG_SP_Follow' )
		                 ? ZYMARG_SP_Follow::get_count( $store_id )
		                 : 0;

		$store_info = $store_user ? dokan_get_store_info( $store_user->ID ) : [];
$store_name = '';

if ( $store_user ) {
    if ( ! empty( $store_info['store_name'] ) ) {
        $store_name = $store_info['store_name'];
    } else {
        $store_name = $store_user->display_name;
    }
}
$config = [
				'storeId'        => $store_id,
				'apiBase'        => esc_url_raw( rest_url() ),
				'shopUrl'        => $store_user ? esc_url( dokan_get_store_url( $store_user->ID ) ) : home_url( '/' ),
				'ajaxUrl'        => esc_url( admin_url( 'admin-ajax.php' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'perPage'        => $per_page,
				'showAura'       => $show_aura,
				'showReviews'    => $show_reviews,
				'storeName' => $store_name,
				'communityUrl'   => esc_url( home_url( '/' . $no_results_slug ) ),
				'isLoggedIn'     => is_user_logged_in(),
				'loginUrl'       => esc_url( wp_login_url( $store_user ? dokan_get_store_url( $store_user->ID ) : home_url( '/' ) ) ),
				'isFollowing'    => $is_following,
				'followersCount' => $followers_count,
				'followNs'       => defined( 'ZYMARG_VD_API_NS' ) ? ZYMARG_VD_API_NS : 'zymarg/v1',
				'chatEnabled'    => false, // overridden to true by ZYMARG_SP_Chat when comm plugin is active

				// v1.18.1: the product grid no longer builds cards in JS. It
				// resolves which products to show exactly as before, then posts
				// the IDs here and injects the ZYMARG card markup it gets back,
				// so search, sort and category filtering keep working while the
				// card itself comes from the Template Pack.
				'cardsAction'    => class_exists( 'ZYMARG_SP_Grid_Bridge' ) ? ZYMARG_SP_Grid_Bridge::AJAX_ACTION : '',
				'cardsNonce'     => class_exists( 'ZYMARG_SP_Grid_Bridge' ) ? wp_create_nonce( ZYMARG_SP_Grid_Bridge::AJAX_NONCE ) : '',
			];

		/**
		 * Allow other modules (e.g. ZYMARG_SP_Chat) to extend the JS config.
		 *
		 * @param array $config The config array passed to wp_localize_script.
		 */
		$config = apply_filters( 'zymarg_sp_config', $config );
		wp_localize_script(
			'zymarg-store-page',
			'ZYMARG_CONFIG',
			$config
		);
	}

	/**
	 * Assets for the store directory at /store-listing/.
	 *
	 * Deliberately separate from the store-page bundle: the directory needs
	 * the brand tokens, the font, Tailwind (the shared badge helpers emit
	 * Tailwind classes) and its own sheet -- but none of the store-page CSS
	 * or JS, which is built around one vendor.
	 *
	 * @return void
	 */
	private static function enqueue_listing() {
		// ── Brand tokens ──────────────────────────────────────────────────
		// Registered by spark.php on every request; enqueued here because a
		// registered stylesheet that is never enqueued does not load, and the
		// whole sheet below is written in --zym-* tokens.
		if ( wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_enqueue_style( 'zymarg-tokens' );
		}

		wp_enqueue_style(
			'zymarg-inter-font',
			'https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5/index.min.css',
			[],
			ZYMARG_SP_VERSION
		);

		wp_enqueue_script(
			'tailwind-css-v4',
			'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
			[],
			ZYMARG_SP_VERSION,
			false // <head>, so Tailwind can scan the DOM
		);

		wp_enqueue_style(
			'zymarg-store-listing',
			ZYMARG_SP_URL . 'assets/css/store-listing.css',
			[ 'zymarg-inter-font' ],
			ZYMARG_SP_VERSION
		);

		wp_enqueue_script(
			'zymarg-store-listing',
			ZYMARG_SP_URL . 'assets/js/store-listing.js',
			[],
			ZYMARG_SP_VERSION,
			true // footer
		);

		wp_localize_script(
			'zymarg-store-listing',
			'ZYMARG_LISTING',
			[
				'apiBase'    => esc_url_raw( rest_url() ),
				'followNs'   => defined( 'ZYMARG_VD_API_NS' ) ? ZYMARG_VD_API_NS : 'zymarg/v1',
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'listNonce'  => wp_create_nonce( 'zymarg_sp_listing' ),
				'isLoggedIn' => is_user_logged_in(),
				'loginUrl'   => wp_login_url( ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ),
				'i18n'       => [
					'follow'    => __( 'Follow', 'zymarg-store-page' ),
					'following' => __( 'Following', 'zymarg-store-page' ),
					'loading'   => __( 'Loading more stores…', 'zymarg-store-page' ),
					/* translators: %d: total number of stores */
					'end'       => __( 'You have seen all %d stores.', 'zymarg-store-page' ),
					/* translators: %d: number of stores just added */
					'added'     => __( '%d more stores loaded.', 'zymarg-store-page' ),
					'error'     => __( 'Could not load more stores.', 'zymarg-store-page' ),
				],
			]
		);
	}

	/**
	 * Retrieve the WP_User object for the store currently being viewed.
	 *
	 * @return WP_User|false
	 */
	private static function get_current_store_user() {
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$store_name = get_query_var( 'author_name' );
			if ( $store_name ) {
				return get_user_by( 'slug', $store_name );
			}
		}
		return false;
	}
}
