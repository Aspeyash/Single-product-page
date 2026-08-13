<?php
/**
 * Core swatch HTML renderer (native port of WSE_Swatch_Renderer).
 *
 * Intercepts WooCommerce's woocommerce_dropdown_variation_attribute_options_html
 * filter and replaces the native <select> with visual swatch markup, while
 * keeping the original <select> hidden in the DOM so wc-add-to-cart-variation.js
 * keeps working unchanged. Global (taxonomy) attributes only.
 *
 * @version 1.0.5
 * @package ZymargSingleProduct
 */

namespace ZymargSP\Swatches;

use ZymargSP\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {

	const IMAGE_SIZE = 'zymarg_sp_swatch';

	/** @var self|null */
	private static $instance = null;

	/** @var array<int,array<int,array<string,mixed>>> */
	private $variation_cache = array();

	/** @var array<string,string> */
	private $template_path_cache = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'render' ), 20, 2 );
	}

	/**
	 * Intercepts the WC dropdown filter and returns swatch HTML.
	 *
	 * @param string               $html WooCommerce's native <select> HTML.
	 * @param array<string,mixed>  $args Filter arguments from WC.
	 * @return string
	 */
	public function render( string $html, array $args ): string {

		$product = $args['product'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}

		$attribute = (string) ( $args['attribute'] ?? '' );
		if ( '' === $attribute ) {
			return $html;
		}

		if ( ! (bool) apply_filters( 'zymarg_sp_swatches_enabled', true, $attribute, $product ) ) {
			return $html;
		}

		$type = Attribute_Types::get_attribute_type( $attribute );
		if ( ! in_array( $type, Attribute_Types::SUPPORTED_TYPES, true ) ) {
			return $html;
		}

		$selected_value = (string) ( $args['selected'] ?? '' );
		$swatch_data    = $this->build_swatch_data( $args, $type, $selected_value );
		if ( empty( $swatch_data ) ) {
			return $html;
		}

		$any_selected = false;
		foreach ( $swatch_data as $value => $swatch ) {
			if ( (string) $value === $selected_value ) {
				$any_selected = true;
				break;
			}
		}

		$items_html              = '';
		$first_available_emitted = false;

		foreach ( $swatch_data as $value => $swatch ) {
			$swatch['is_selected'] = ( (string) $value === $selected_value );

			$is_first_focusable = false;
			if ( ! $any_selected && ! $first_available_emitted && ! empty( $swatch['is_available'] ) ) {
				$is_first_focusable      = true;
				$first_available_emitted = true;
			}

			$template_name = ( 'button' === $type ) ? 'label.php' : ( $type . '.php' );

			$items_html .= $this->include_template(
				$template_name,
				array(
					'value'              => (string) $value,
					'swatch'             => $swatch,
					'attribute'          => $attribute,
					'is_selected'        => $swatch['is_selected'],
					'is_first_focusable' => $is_first_focusable,
				)
			);
		}

		$output = $this->include_template(
			'wrapper.php',
			array(
				'html'       => $html,
				'attribute'  => $attribute,
				'product'    => $product,
				'type'       => $type,
				'items_html' => $items_html,
				'opts'       => $this->wrapper_options(),
			)
		);

		return (string) apply_filters( 'zymarg_sp_swatch_html', $output, $attribute, $product );
	}

	/**
	 * Collects the admin-configured presentation options for the wrapper.
	 *
	 * @return array<string,mixed>
	 */
	private function wrapper_options(): array {
		return array(
			'shape'             => (string) Options::get( 'swatch_shape', 'rounded' ),
			'size'              => (string) Options::get( 'swatch_color_size', '44px' ),
			'oos'               => (string) Options::get( 'swatch_oos_behavior', 'blur' ),
			'tooltip'           => (bool) Options::get( 'swatch_tooltip', true ),
			'tooltip_pos'       => (string) Options::get( 'swatch_tooltip_position', 'top' ),
			'show_clear'        => (bool) Options::get( 'swatch_show_clear', true ),
			'clear_label'       => (string) Options::get( 'swatch_clear_label', __( 'Clear', 'zymarg-single-product' ) ),
			'show_attr_label'   => (bool) Options::get( 'swatch_show_attr_label', true ),
			'show_selected_val' => (bool) Options::get( 'swatch_show_selected_val', true ),
		);
	}

	/**
	 * Builds swatch data for all options of an attribute.
	 *
	 * @param array<string,mixed> $args
	 * @param string              $type
	 * @param string              $selected_value
	 * @return array<string,array<string,mixed>>
	 */
	private function build_swatch_data( array $args, string $type, string $selected_value ): array {
		$product   = $args['product'];
		$attribute = $args['attribute'];
		$options   = $args['options'] ?? array();
		if ( empty( $options ) ) {
			return array();
		}

		$product_attributes = $product->get_attributes();
		$is_taxonomy        = isset( $product_attributes[ $attribute ] ) && $product_attributes[ $attribute ]->is_taxonomy();

		$swatch_data = array();
		foreach ( $options as $option_value ) {
			$swatch_data[ $option_value ] = $this->build_single_swatch( (string) $option_value, $attribute, $type, $product, $is_taxonomy );
		}
		return $swatch_data;
	}

	/**
	 * Builds swatch data for a single option value.
	 *
	 * @return array<string,mixed>
	 */
	private function build_single_swatch( string $option_value, string $attribute, string $type, \WC_Product $product, bool $is_taxonomy ): array {
		$term_id = 0;
		$label   = $option_value;

		if ( $is_taxonomy ) {
			$term = get_term_by( 'slug', $option_value, $attribute );
			if ( $term instanceof \WP_Term ) {
				$label   = $term->name;
				$term_id = $term->term_id;
			}
		}

		$swatch = array(
			'value'        => $option_value,
			'label'        => $label,
			'type'         => $type,
			'is_available' => $this->is_term_available( $product, $attribute, $option_value ),
		);

		switch ( $type ) {
			case 'color':
				$swatch['color'] = $term_id ? Term_Meta::get_color( $term_id ) : '';
				if ( empty( $swatch['color'] ) ) {
					$swatch['color'] = '#e0e0e0';
				}
				break;

			case 'image':
				$swatch['image_id']  = $term_id ? Term_Meta::get_image_id( $term_id ) : 0;
				$swatch['image_url'] = $this->get_image_with_fallback( $product, $attribute, $option_value, $term_id );
				break;
		}

		return $swatch;
	}

	/**
	 * True if a term value has at least one available variation.
	 */
	private function is_term_available( \WC_Product $product, string $attribute, string $value ): bool {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return true;
		}
		$variations = $this->get_available_variations( $product );
		$attr_key   = 'attribute_' . sanitize_title( $attribute );
		foreach ( $variations as $variation ) {
			$attr_val = $variation['attributes'][ $attr_key ] ?? null;
			if ( null === $attr_val ) {
				continue;
			}
			if ( '' === $attr_val || $attr_val === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_available_variations( \WC_Product $product ): array {
		$id = $product->get_id();
		if ( ! isset( $this->variation_cache[ $id ] ) ) {
			$this->variation_cache[ $id ] = ( $product instanceof \WC_Product_Variable )
				? $product->get_available_variations()
				: array();
		}
		return $this->variation_cache[ $id ];
	}

	/**
	 * Returns the best available image URL for an image swatch.
	 * Chain: term meta → matching variation → parent featured → placeholder.
	 */
	private function get_image_with_fallback( \WC_Product $product, string $attribute, string $value, int $term_id ): string {
		if ( $term_id > 0 ) {
			$url = Term_Meta::get_image_url( $term_id, 'full' );
			if ( ! empty( $url ) ) {
				return $url;
			}
		}

		$variation = $this->find_matching_variation( $product, $attribute, $value );
		if ( $variation instanceof \WC_Product_Variation ) {
			$img_id = $variation->get_image_id();
			if ( $img_id ) {
				$url = wp_get_attachment_image_url( $img_id, 'full' );
				if ( $url ) {
					return $url;
				}
			}
		}

		$parent_img_id = $product->get_image_id();
		if ( $parent_img_id ) {
			$url = wp_get_attachment_image_url( $parent_img_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}

		return wc_placeholder_img_src( self::IMAGE_SIZE );
	}

	private function find_matching_variation( \WC_Product $product, string $attribute, string $value ): ?\WC_Product_Variation {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return null;
		}
		$attr_key   = 'attribute_' . sanitize_title( $attribute );
		$variations = $this->get_available_variations( $product );
		foreach ( $variations as $variation_data ) {
			$attr_val = $variation_data['attributes'][ $attr_key ] ?? null;
			if ( null === $attr_val || ( '' !== $attr_val && $attr_val !== $value ) ) {
				continue;
			}
			$variation = wc_get_product( $variation_data['variation_id'] );
			if ( $variation instanceof \WC_Product_Variation && $variation->get_image_id() ) {
				return $variation;
			}
		}
		return null;
	}

	// ── Template system with LFI guard ──

	public function locate_template( string $template_name ): string {
		$template_name = basename( $template_name );
		if ( '' === $template_name || ! str_ends_with( $template_name, '.php' ) ) {
			return '';
		}
		if ( isset( $this->template_path_cache[ $template_name ] ) ) {
			return $this->template_path_cache[ $template_name ];
		}

		$sub_dir   = 'zymarg-single-product/swatches/';
		$locations = array(
			get_stylesheet_directory() . '/' . $sub_dir . $template_name,
			get_template_directory() . '/' . $sub_dir . $template_name,
			ZYMARG_SNGL_TPL_PATH . 'swatches/' . $template_name,
		);

		$found = '';
		foreach ( $locations as $path ) {
			if ( file_exists( $path ) ) {
				$found = $path;
				break;
			}
		}
		$this->template_path_cache[ $template_name ] = $found;
		return $found;
	}

	public function include_template( string $template_name, array $args = array() ): string {
		$path = $this->locate_template( $template_name );
		if ( '' === $path || ! $this->is_safe_template_path( $path ) ) {
			return '';
		}
		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args, EXTR_SKIP );
		include $path;
		return (string) ob_get_clean();
	}

	private function is_safe_template_path( string $path ): bool {
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return false;
		}
		$allowed = array(
			realpath( ZYMARG_SNGL_TPL_PATH ),
			realpath( get_stylesheet_directory() . '/zymarg-single-product' ),
			realpath( get_template_directory() . '/zymarg-single-product' ),
		);
		foreach ( $allowed as $dir ) {
			if ( $dir && str_starts_with( $real_path, $dir . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}
		return false;
	}
}
