<?php
/**
 * Breadcrumbs renderer.
 *
 * Outputs a WC-aware breadcrumb trail:
 *   Home › Category › Sub-category › Product Name
 *
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Breadcrumbs {

	/**
	 * Render breadcrumbs HTML if enabled.
	 *
	 * @param \WC_Product $product Current product.
	 * @return void
	 */
	public static function render( \WC_Product $product ): void {
		if ( ! Options::get( 'show_breadcrumbs' ) ) {
			return;
		}

		$sep   = esc_html( Options::get( 'breadcrumb_separator', '›' ) );
		$crumbs = self::build( $product );

		echo '<nav class="zymarg-sp-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'zymarg-single-product' ) . '">';
		echo '<ol class="zymarg-sp-breadcrumbs__list">';

		$last = count( $crumbs ) - 1;
		foreach ( $crumbs as $i => $crumb ) {
			$is_last = ( $i === $last );
			if ( $is_last ) {
				echo '<li class="zymarg-sp-breadcrumbs__item zymarg-sp-breadcrumbs__item--current" aria-current="page">';
				echo '<span>' . esc_html( $crumb['label'] ) . '</span>';
			} else {
				echo '<li class="zymarg-sp-breadcrumbs__item">';
				echo '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
				echo '<span class="zymarg-sp-breadcrumbs__sep" aria-hidden="true">' . $sep . '</span>'; // phpcs:ignore
			}
			echo '</li>';
		}

		echo '</ol></nav>';
	}

	/**
	 * Build the crumb array.
	 *
	 * @param \WC_Product $product
	 * @return array  [ ['label' => '', 'url' => ''] ]
	 */
	private static function build( \WC_Product $product ): array {
		$crumbs = [];

		// Home.
		$crumbs[] = [
			'label' => __( 'Home', 'zymarg-single-product' ),
			'url'   => home_url( '/' ),
		];

		// WooCommerce shop page.
		$shop_id = wc_get_page_id( 'shop' );
		if ( $shop_id && $shop_id > 0 ) {
			$crumbs[] = [
				'label' => get_the_title( $shop_id ),
				'url'   => get_permalink( $shop_id ),
			];
		}

		// Primary product category chain (deepest first, then reversed).
		$terms = wc_get_product_terms(
			$product->get_id(),
			'product_cat',
			[ 'orderby' => 'parent', 'order' => 'ASC' ]
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			// Find the deepest term.
			$deepest = null;
			foreach ( $terms as $term ) {
				if ( null === $deepest || $term->parent > $deepest->parent ) {
					$deepest = $term;
				}
			}

			// Walk up ancestors.
			$chain = [];
			$current = $deepest;
			while ( $current ) {
				$chain[] = $current;
				$parent_id = (int) $current->parent;
				if ( ! $parent_id ) {
					break;
				}
				$parent = get_term( $parent_id, 'product_cat' );
				$current = ( $parent && ! is_wp_error( $parent ) ) ? $parent : null;
			}
			$chain = array_reverse( $chain );

			foreach ( $chain as $term ) {
				$crumbs[] = [
					'label' => $term->name,
					'url'   => get_term_link( $term ),
				];
			}
		}

		// Product itself (no URL — it's the current page).
		$crumbs[] = [
			'label' => $product->get_name(),
			'url'   => '',
		];

		return $crumbs;
	}
}
