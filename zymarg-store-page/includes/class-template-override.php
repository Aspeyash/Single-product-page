<?php
/**
 * Template Override
 *
 * Hooks into Dokan's template loader and replaces the built-in store
 * page template with ZYMARG's custom design. Works on both classic
 * themes and block themes (via template_include filter).
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Template_Override {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Dokan's primary template hook (highest priority — runs before themes).
		add_filter( 'dokan_locate_template',  [ __CLASS__, 'override_dokan_template' ], 99, 3 );

		// Fallback: generic template_include for edge-cases / custom Dokan builds.
		add_filter( 'template_include',       [ __CLASS__, 'override_via_template_include' ], 99 );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Hook 1 — dokan_locate_template
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * When Dokan is about to load store.php, redirect it to our template.
	 *
	 * @param string $template      Resolved template path.
	 * @param string $template_name Template name relative to templates/ folder.
	 * @param string $template_path The directory Dokan searched.
	 * @return string
	 */
	public static function override_dokan_template( $template, $template_name, $template_path ) {
		// The store directory. Checked first and returned separately: it must
		// NOT be folded into the list below, because every entry there resolves
		// to store.php and the directory would render as a single store page.
		if ( false !== strpos( $template_name, 'store-lists.php' ) ) {
			$listing_template = ZYMARG_SP_TEMPLATES . 'store-lists.php';
			if ( file_exists( $listing_template ) ) {
				return $listing_template;
			}
		}

		// Dokan store template file names that we want to intercept.
		$store_templates = [ 'store.php', 'store-page.php' ];

		foreach ( $store_templates as $name ) {
			if ( false !== strpos( $template_name, $name ) ) {
				$our_template = ZYMARG_SP_TEMPLATES . 'store.php';
				if ( file_exists( $our_template ) ) {
					return $our_template;
				}
			}
		}

		return $template;
	}

	// ──────────────────────────────────────────────────────────────────────
	// Hook 2 — template_include (fallback)
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * If we are on a Dokan store page and no override has fired yet,
	 * load our template directly.
	 *
	 * @param string $template WordPress-resolved template.
	 * @return string
	 */
	public static function override_via_template_include( $template ) {
		// Checked before the guard below, not after: the store directory is a
		// normal WordPress page, so it must still be detected on a Dokan build
		// where dokan_is_store_page() happens to be unavailable.
		if ( class_exists( 'ZYMARG_SP_Store_Listing' ) && ZYMARG_SP_Store_Listing::is_store_listing() ) {
			$listing_template = ZYMARG_SP_TEMPLATES . 'store-lists.php';
			if ( file_exists( $listing_template ) ) {
				return $listing_template;
			}
		}

		if ( ! function_exists( 'dokan_is_store_page' ) ) {
			return $template;
		}

		if ( dokan_is_store_page() ) {
			$our_template = ZYMARG_SP_TEMPLATES . 'store.php';
			if ( file_exists( $our_template ) ) {
				return $our_template;
			}
		}

		return $template;
	}
}
