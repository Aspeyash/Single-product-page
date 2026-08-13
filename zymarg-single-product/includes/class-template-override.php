<?php
/**
 * Template Override.
 *
 * Dual-hook strategy so nothing — Elementor, Divi, other plugins — can
 * replace our template when the override is enabled:
 *
 * 1. `woocommerce_locate_template` at priority 1:
 *    Intercepts WooCommerce's internal template lookup before any theme
 *    or plugin running at the default priority (10+).
 *
 * 2. `template_include` at priority 99:
 *    Belt-and-braces fallback. If anything replaces the WC single-product
 *    template further down the stack (Elementor Theme Builder, Divi, etc.)
 *    this hook fires after all of them and restores ours.
 *
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Override {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		if ( ! Options::get( 'template_override_enabled' ) ) {
			return;
		}

		$priority = max( 1, (int) Options::get( 'override_priority', 1 ) );

		// Hook 1 — WooCommerce template lookup.
		add_filter(
			'woocommerce_locate_template',
			[ $this, 'intercept_locate' ],
			$priority,
			3
		);

		// Hook 2 — WordPress final template resolution (priority 99).
		add_filter(
			'template_include',
			[ $this, 'intercept_template_include' ],
			99
		);
	}

	/**
	 * Hook 1: replace single-product.php in WC's template lookup.
	 *
	 * @param string $template      Located template path.
	 * @param string $template_name Template name being looked up.
	 * @param string $template_path Template base path.
	 * @return string
	 */
	public function intercept_locate( string $template, string $template_name, string $template_path ): string {
		if ( 'single-product.php' !== $template_name ) {
			return $template;
		}
		return $this->our_template();
	}

	/**
	 * Hook 2: override the final resolved template when on a single product page.
	 *
	 * This fires after Elementor, Divi, and any page-builder that hooks
	 * `template_include` at priority < 99.
	 *
	 * @param string $template Resolved template file.
	 * @return string
	 */
	public function intercept_template_include( string $template ): string {
		if ( ! is_product() ) {
			return $template;
		}

		$ours = $this->our_template();
		if ( file_exists( $ours ) ) {
			return $ours;
		}

		return $template;
	}

	/**
	 * Absolute path to our single-product template.
	 *
	 * @return string
	 */
	private function our_template(): string {
		return ZYMARG_SNGL_TPL_PATH . 'woocommerce/single-product.php';
	}
}
