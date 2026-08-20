<?php
/**
 * Display Conditions — decides whether the header should render on the
 * current page based on admin-configured include / exclude rules.
 *
 * Rules are stored as a JSON array under the key `display_rules_json`
 * inside the main option. Each rule is an associative array:
 *   [ 'type' => 'front_page', 'value' => '' ]
 *   [ 'type' => 'page',       'value' => '42' ]   // page ID
 *   [ 'type' => 'url',        'value' => '/shop' ] // substring or wildcard
 *
 * Logic:
 *   mode = everywhere → always render
 *   mode = show_on    → render only when ANY rule matches
 *   mode = hide_on    → render only when NO rule matches
 *
 * Rules within a set are evaluated with OR logic: a single match is
 * enough to consider the set "matched".
 *
 * @package ZymargHeader
 * @since   1.1.17
 */

namespace ZymargHeader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Display_Conditions {

	/**
	 * Return true if the header should be rendered on the current page.
	 * Called from Header::inject() after wp_body_open fires (template
	 * conditionals are available at that point).
	 */
	public static function should_render(): bool {
		$mode = Settings::get( 'display_mode', 'everywhere' );

		if ( 'everywhere' === $mode ) {
			return true;
		}

		$json  = Settings::get( 'display_rules_json', '[]' );
		$rules = json_decode( $json ?: '[]', true );

		if ( ! is_array( $rules ) || empty( $rules ) ) {
			// No rules configured — fall back to showing everywhere.
			return true;
		}

		$matched = self::evaluate( $rules );

		if ( 'show_on' === $mode ) {
			return $matched;
		}
		if ( 'hide_on' === $mode ) {
			return ! $matched;
		}

		return true;
	}

	/* ── Rule evaluation ────────────────────────────────────────── */

	/**
	 * Returns true if ANY rule in the set matches the current page.
	 *
	 * @param array $rules
	 */
	private static function evaluate( array $rules ): bool {
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( self::rule_matches( $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test a single rule against the current WordPress page context.
	 *
	 * @param array $rule
	 */
	private static function rule_matches( array $rule ): bool {
		$type  = sanitize_key( $rule['type'] ?? '' );
		$value = sanitize_text_field( $rule['value'] ?? '' );

		switch ( $type ) {

			// ── Generic WordPress conditionals ──────────────────
			case 'front_page':
				return is_front_page();

			case 'blog':
				return is_home();

			case '404':
				return is_404();

			case 'search':
				return is_search();

			case 'archive':
				return is_archive();

			case 'singular':
				return is_singular();

			// ── WooCommerce ─────────────────────────────────────
			case 'woo_shop':
				return function_exists( 'is_shop' ) && is_shop();

			case 'woo_product':
				return function_exists( 'is_product' ) && is_product();

			case 'woo_cart':
				return function_exists( 'is_cart' ) && is_cart();

			case 'woo_checkout':
				return function_exists( 'is_checkout' ) && is_checkout();

			case 'woo_account':
				return function_exists( 'is_account_page' ) && is_account_page();

			// ── Custom: specific page by ID ──────────────────────
			case 'page':
				if ( '' === $value ) {
					return false;
				}
				return is_page( (int) $value );

			// ── Custom: post type (singular or archive) ──────────
			case 'post_type':
				if ( '' === $value ) {
					return false;
				}
				return is_singular( $value ) || is_post_type_archive( $value );

			// ── Custom: URL contains / wildcard ──────────────────
			case 'url':
				if ( '' === $value ) {
					return false;
				}
				$uri = isset( $_SERVER['REQUEST_URI'] )
					? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
					: '';
				if ( false !== strpos( $value, '*' ) ) {
					return fnmatch( $value, $uri );
				}
				return false !== strpos( $uri, $value );

			// ── Dokan multi-vendor pages ─────────────────────────
			// All checks are guarded with function_exists() so they
			// fail gracefully when Dokan is not active.

			case 'dokan_store':
				// Single vendor store page — e.g. /store/vendor-name/
				return function_exists( 'dokan_is_store_page' ) && dokan_is_store_page();

			case 'dokan_store_listing':
				// All-stores listing page — e.g. /store/
				return function_exists( 'dokan_is_store_listing' ) && dokan_is_store_listing();

			case 'dokan_dashboard':
				// Vendor dashboard page (all sub-pages share the same WP page).
				// Dokan stores the dashboard page ID in the 'dokan_pages' option.
				if ( ! function_exists( 'dokan' ) ) {
					return false;
				}
				$dokan_pages  = get_option( 'dokan_pages', array() );
				$dashboard_id = ! empty( $dokan_pages['dashboard'] ) ? (int) $dokan_pages['dashboard'] : 0;
				return $dashboard_id > 0 && is_page( $dashboard_id );

			case 'dokan_orders':
				// Vendor orders sub-page (?page=dokan-dashboard&action=orders or
				// the 'orders' endpoint on the dashboard page).
				if ( ! function_exists( 'dokan' ) ) {
					return false;
				}
				$dokan_pages  = get_option( 'dokan_pages', array() );
				$dashboard_id = ! empty( $dokan_pages['dashboard'] ) ? (int) $dokan_pages['dashboard'] : 0;
				if ( $dashboard_id === 0 || ! is_page( $dashboard_id ) ) {
					return false;
				}
				// Dokan uses 'orders' as a rewrite endpoint on the dashboard URL.
				return 'orders' === get_query_var( 'orders', null )
					|| 'orders' === get_query_var( 'current-section', null )
					|| isset( $_GET['action'] ) && 'orders' === sanitize_key( $_GET['action'] );

			case 'dokan_settings_page':
				// Vendor settings sub-page.
				if ( ! function_exists( 'dokan' ) ) {
					return false;
				}
				$dokan_pages  = get_option( 'dokan_pages', array() );
				$dashboard_id = ! empty( $dokan_pages['dashboard'] ) ? (int) $dokan_pages['dashboard'] : 0;
				if ( $dashboard_id === 0 || ! is_page( $dashboard_id ) ) {
					return false;
				}
				return 'settings' === get_query_var( 'settings', null )
					|| isset( $_GET['action'] ) && 'settings' === sanitize_key( $_GET['action'] );

			default:
				return false;
		}
	}
}
