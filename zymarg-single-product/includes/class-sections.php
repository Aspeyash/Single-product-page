<?php
/**
 * Sections - heading, link and shortcode helpers for product grid sections.
 *
 * This plugin owns the heading markup for every grid section. The engine's own
 * heading block is deliberately switched off (see force_no_heading) so that a
 * heading can disappear together with an empty grid: the template buffers the
 * shortcode output first and only prints a heading once it knows there is
 * something to head. An engine-printed heading cannot do that, because the
 * engine decides its heading before it knows whether the query returned rows.
 *
 * @version 2.2.0
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sections {

	/** Token substituted with the vendor shop name inside a heading. */
	const TOKEN = '{vendor_name}';

	/** Sources treated as vendor-scoped, i.e. eligible for the auto store link. */
	const VENDOR_SOURCES = [ 'vendor', 'current_vendor' ];

	/**
	 * Stored section rows.
	 *
	 * @return array
	 */
	public static function rows(): array {
		$rows = Options::get( 'product_sections', [] );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Read the source attribute out of a shortcode string.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string Lowercased source, or '' when absent.
	 */
	public static function source_of( string $shortcode ): string {
		if ( preg_match( '/\bsource=("|\')(.*?)\1/', $shortcode, $m ) ) {
			return strtolower( trim( $m[2] ) );
		}
		return '';
	}

	/**
	 * Read the layout attribute out of a shortcode string.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string 'slider' or 'grid' (engine default).
	 */
	public static function layout_of( string $shortcode ): string {
		if ( preg_match( '/\blayout=("|\')(.*?)\1/', $shortcode, $m ) ) {
			return strtolower( trim( $m[2] ) );
		}
		return 'grid';
	}

	/**
	 * Whether this section is vendor-scoped.
	 *
	 * @param string $shortcode Shortcode.
	 * @return bool
	 */
	public static function is_vendor_source( string $shortcode ): bool {
		return in_array( self::source_of( $shortcode ), self::VENDOR_SOURCES, true );
	}

	/**
	 * Force the engine's heading block off for a section shortcode.
	 *
	 * Any author-supplied show_heading is stripped first, so a leftover
	 * show_heading="yes" from an earlier release cannot produce two headings.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string
	 */
	public static function force_no_heading( string $shortcode ): string {
		$shortcode = (string) preg_replace( '/\s+show_heading=("|\')[^"\']*\1/', '', $shortcode );

		$pos = strrpos( $shortcode, ']' );
		if ( false === $pos ) {
			return $shortcode;
		}

		return substr( $shortcode, 0, $pos ) . ' show_heading="no"' . substr( $shortcode, $pos );
	}

	/**
	 * Vendor (author) id for a product.
	 *
	 * Dokan stores the vendor as the product's post author, which is also how
	 * the engine's own resolver starts. On a product page the product is known,
	 * so no page detection is needed.
	 *
	 * @param mixed $product WC_Product.
	 * @return int 0 when unresolved.
	 */
	public static function vendor_id( $product ): int {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}

		$author = (int) get_post_field( 'post_author', $product->get_id() );

		return $author > 0 ? $author : 0;
	}

	/**
	 * Vendor shop name.
	 *
	 * @param int $vendor_id Vendor id.
	 * @return string '' when unresolved.
	 */
	public static function vendor_name( int $vendor_id ): string {
		if ( $vendor_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'dokan_get_vendor' ) ) {
			$vendor = dokan_get_vendor( $vendor_id );
			if ( is_object( $vendor ) && method_exists( $vendor, 'get_shop_name' ) ) {
				$name = $vendor->get_shop_name();
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					return trim( $name );
				}
			}
		}

		$user = get_userdata( $vendor_id );
		if ( $user && '' !== trim( (string) $user->display_name ) ) {
			return trim( (string) $user->display_name );
		}

		return '';
	}

	/**
	 * Vendor store URL.
	 *
	 * Prefers the engine's public resolver so there is one source of truth, and
	 * falls back to Dokan directly when the engine is not active. Both results
	 * are type-checked before use.
	 *
	 * @param int $vendor_id Vendor id.
	 * @return string '' when unresolved.
	 */
	public static function store_url( int $vendor_id ): string {
		if ( $vendor_id <= 0 ) {
			return '';
		}

		$candidates = [
			'\Zymarg\WCPG\Vendor\Vendor_Resolver',
			'\Zymarg\WCPG\Vendor_Resolver',
		];

		foreach ( $candidates as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'get_store_url' ) ) {
				$url = call_user_func( [ $class, 'get_store_url' ], $vendor_id );
				if ( is_string( $url ) && '' !== trim( $url ) ) {
					return trim( $url );
				}
			}
		}

		if ( function_exists( 'dokan_get_store_url' ) ) {
			$url = dokan_get_store_url( $vendor_id );
			if ( is_string( $url ) && '' !== trim( $url ) ) {
				return trim( $url );
			}
		}

		return '';
	}

	/**
	 * Resolve a section heading for output.
	 *
	 * The {vendor_name} token resolves only on vendor-scoped sections. On any
	 * other source there is no vendor context, so the token is removed rather
	 * than filled with a misleading fallback.
	 *
	 * @param array $row       Section row.
	 * @param bool  $is_vendor Whether the section is vendor-scoped.
	 * @param int   $vendor_id Vendor id.
	 * @return string '' when the section has no heading.
	 */
	public static function heading( array $row, bool $is_vendor, int $vendor_id ): string {
		$raw = trim( (string) ( $row['heading'] ?? '' ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( false === strpos( $raw, self::TOKEN ) ) {
			return $raw;
		}

		if ( $is_vendor ) {
			$name = self::vendor_name( $vendor_id );
			if ( '' === $name ) {
				$name = __( 'this Seller', 'zymarg-single-product' );
			}
			$out = str_replace( self::TOKEN, $name, $raw );
		} else {
			$out = str_replace( self::TOKEN, '', $raw );
		}

		$out = (string) preg_replace( '/\s+/', ' ', $out );

		return trim( $out );
	}

	/**
	 * Resolve the section link.
	 *
	 * Auto resolution is vendor-only. A non-vendor section links only when an
	 * explicit URL is supplied; a blank URL never falls back to the store.
	 *
	 * @param array $row       Section row.
	 * @param bool  $is_vendor Whether the section is vendor-scoped.
	 * @param int   $vendor_id Vendor id.
	 * @return array [] when no link should render, else [ 'url', 'text' ].
	 */
	public static function link( array $row, bool $is_vendor, int $vendor_id ): array {
		if ( empty( $row['show_link'] ) ) {
			return [];
		}

		if ( $is_vendor ) {
			$url  = self::store_url( $vendor_id );
			$text = __( 'Explore Store', 'zymarg-single-product' );
		} else {
			$url  = trim( (string) ( $row['link_url'] ?? '' ) );
			$text = __( 'Explore More', 'zymarg-single-product' );
		}

		if ( '' === $url ) {
			return [];
		}

		return [
			'url'  => $url,
			'text' => $text,
		];
	}
}
