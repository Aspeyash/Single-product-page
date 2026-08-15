<?php
/**
 * ZYMARG Store Page -- admin-managed product grid sections.
 *
 * Storage and validation for the ordered list of product sections that
 * render on the vendor store page, above the category sidebar + "All
 * Products" grid. Each row runs one [zymarg_products] shortcode against
 * the Product Grid engine's `current_vendor` source, so an admin can
 * change what a section shows -- or add a brand new section entirely --
 * without a plugin update.
 *
 * DELIBERATELY SCOPED TO current_vendor
 * --------------------------------------
 * Every row on a store page is inherently about the vendor whose store is
 * being viewed. Unlike the ZYMARG Single Product plugin's own section list
 * (which mixes `source="vendor"`, `source="similar"`, `source="recommended"`,
 * etc. on a single product page), a store page has exactly one subject, so
 * only `source="current_vendor"` is accepted here. A shortcode with any
 * other source -- or with the attribute left out entirely, which the engine
 * would otherwise resolve to its catalogue-wide "all" source -- is rejected
 * on save. See sanitize_rows().
 *
 * WHY THIS PLUGIN OWNS THE HEADING, NOT THE ENGINE
 * -------------------------------------------------
 * Same reasoning as ZYMARG Single Product's Sections class: the engine's own
 * heading block is switched off (force_no_heading()) so a heading can
 * disappear together with an empty grid. The template buffers the shortcode
 * output first and only prints a heading once it knows there is something to
 * head; the engine cannot do that, because it decides its heading before it
 * knows whether its own query returned rows.
 *
 * THE "ALL PRODUCTS" ROW IS SPECIAL, BUT NOT BY ID
 * --------------------------------------------------
 * Exactly one row is expected to carry current_vendor_subset="all" (or no
 * subset at all, which the engine treats identically) -- this is the row the
 * template renders inside the existing category-sidebar layout, mounted at
 * #product-grid, with the engine's own native pagination taking over from
 * the old Dokan-REST-driven grid. It is identified by ITS QUERY, via
 * subset_of(), the same way ZYMARG Single Product's Sections class
 * identifies a vendor-scoped row by its `source` attribute rather than by a
 * hardcoded row id -- so renaming or reordering the row in the admin never
 * breaks the special-case detection.
 *
 * @package ZYMARG_Store_Page
 * @since   1.23.0
 */

defined( 'ABSPATH' ) || exit;

class ZYMARG_SP_Store_Sections {

	/** Option key holding the live, ordered section list. */
	const OPTION_KEY = 'zymarg_sp_store_sections';

	/**
	 * Option key holding a one-step rollback snapshot.
	 *
	 * Written whenever a save actually changes the section list, so an
	 * accidental edit can be undone from the same screen. Never rendered.
	 */
	const BACKUP_KEY = 'zymarg_sp_store_sections_backup';

	/** Recognised current_vendor_subset values, in the engine's own order. */
	const SUBSETS = array( 'all', 'featured', 'trending', 'best_selling' );

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Default section rows, shipped on a fresh install.
	 *
	 * Array order IS render order. The three rows correspond 1:1 to the
	 * mockup approved by the site owner: Trending and Best Selling as
	 * non-paginated sliders above the category sidebar, and All Products as
	 * the existing sidebar + infinite-scroll grid, now sourced from the
	 * engine's current_vendor / all subset instead of a direct Dokan REST
	 * call.
	 *
	 * @return array
	 */
	public static function default_sections() {
		return array(
			array(
				'id'        => 'sec_trending',
				'label'     => 'Trending',
				'enabled'   => true,
				'heading'   => 'Trending',
				'show_link' => false,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="current_vendor" current_vendor_subset="trending" layout="slider" limit="8" columns="5" columns_tablet="4" columns_mobile="2" card_template="zymarg"]',
			),
			array(
				'id'        => 'sec_best_selling',
				'label'     => 'Best Selling',
				'enabled'   => true,
				'heading'   => 'Best Selling',
				'show_link' => false,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="current_vendor" current_vendor_subset="best_selling" layout="slider" limit="8" columns="5" columns_tablet="4" columns_mobile="2" card_template="zymarg"]',
			),
			array(
				'id'        => 'sec_all_products',
				'label'     => 'All Products',
				'enabled'   => true,
				// Deliberately blank: "All Products" is rendered inside the
				// existing sidebar layout, whose own heading/toolbar markup
				// this plugin already owns (see templates/store.php). The
				// generic section renderer is never used for this row.
				'heading'   => '',
				'show_link' => false,
				'link_url'  => '',
				'shortcode' => '[zymarg_products source="current_vendor" current_vendor_subset="all" layout="grid" columns="4" columns_tablet="3" columns_mobile="2" pagination="infinite" max_products="40" max_products_tablet="30" max_products_mobile="20" batch_size="20" batch_size_tablet="20" batch_size_mobile="10" card_template="zymarg"]',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	/**
	 * The live, ordered section list.
	 *
	 * This option key is new as of 1.23.0 -- no existing install has a prior
	 * value to migrate, so a missing option simply means "use the shipped
	 * defaults", with no upgrade path to maintain.
	 *
	 * @return array
	 */
	public static function get_all() {
		$rows = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $rows ) ) {
			return self::default_sections();
		}

		return $rows;
	}

	/**
	 * Only the rows that are both enabled and carry a non-empty shortcode.
	 *
	 * @return array
	 */
	public static function get_enabled() {
		return array_values(
			array_filter(
				self::get_all(),
				static function ( $row ) {
					return is_array( $row )
						&& ! empty( $row['enabled'] )
						&& '' !== trim( (string) ( $row['shortcode'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * The one enabled row that renders as the sidebar + infinite-scroll All
	 * Products grid, or null when no row resolves to that subset.
	 *
	 * Only the FIRST match (in render order) is treated specially. Any
	 * further row that also resolves to the 'all' subset renders as an
	 * ordinary generic section instead of a second sidebar layout, which
	 * this plugin does not support duplicating on one page.
	 *
	 * @return array|null
	 */
	public static function get_all_products_row() {
		foreach ( self::get_enabled() as $row ) {
			if ( self::is_all_products_row( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Every enabled row EXCEPT the one returned by get_all_products_row().
	 *
	 * These render as generic heading + shortcode blocks, in order, above
	 * the sidebar layout.
	 *
	 * @return array
	 */
	public static function get_generic_rows() {
		$all_products_row = self::get_all_products_row();
		$seen_all_row      = false;

		return array_values(
			array_filter(
				self::get_enabled(),
				static function ( $row ) use ( $all_products_row, &$seen_all_row ) {
					if ( ! $seen_all_row
						&& null !== $all_products_row
						&& ( $row['id'] ?? null ) === ( $all_products_row['id'] ?? null )
					) {
						$seen_all_row = true;
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * Persist a new section list.
	 *
	 * @param array $raw Raw rows from the admin POST (already JSON-decoded).
	 * @return bool True on success.
	 */
	public static function save( array $raw ) {
		$clean = self::sanitize_rows( $raw );

		$previous = self::get_all();
		if ( $previous !== $clean ) {
			update_option( self::BACKUP_KEY, $previous, false );
		}

		return update_option( self::OPTION_KEY, $clean, false );
	}

	/**
	 * Swap the stored section list with the rollback snapshot.
	 *
	 * The current list becomes the new snapshot, so pressing this twice
	 * undoes itself.
	 *
	 * @return array|false The restored rows on success, false when there is
	 *                      no snapshot to restore.
	 */
	public static function restore() {
		$backup = get_option( self::BACKUP_KEY, null );

		if ( ! is_array( $backup ) || empty( $backup ) ) {
			return false;
		}

		$current = self::get_all();

		update_option( self::OPTION_KEY, $backup, false );
		update_option( self::BACKUP_KEY, $current, false );

		return $backup;
	}

	// -------------------------------------------------------------------------
	// Shortcode allow-list + source restriction
	// -------------------------------------------------------------------------

	/**
	 * Shortcodes a section row is allowed to run.
	 *
	 * Restricted to [zymarg_products] only -- unlike ZYMARG Single Product,
	 * which also allows its wishlist and recently-viewed shortcodes. Store
	 * page sections have no equivalent use case for either, so the list is
	 * deliberately narrower. Filterable for parity with the rest of the
	 * ZYMARG stack, though narrowing further is the only safe direction.
	 *
	 * @return array
	 */
	public static function allowed_shortcodes() {
		return (array) apply_filters(
			'zymarg_sp_allowed_store_section_shortcodes',
			array( 'zymarg_products' )
		);
	}

	/**
	 * Whether the opening tag of a shortcode string is allow-listed.
	 *
	 * @param string $shortcode Raw shortcode string.
	 * @param array  $allowed   Allow-listed tags.
	 * @return bool
	 */
	private static function shortcode_tag_is_allowed( $shortcode, array $allowed ) {
		if ( ! preg_match( '/\[\s*([a-zA-Z0-9_-]+)/', $shortcode, $m ) ) {
			return false;
		}
		return in_array( $m[1], $allowed, true );
	}

	/**
	 * Read the source attribute out of a shortcode string.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string Lower-cased source, or '' when the attribute is absent.
	 */
	public static function source_of( $shortcode ) {
		if ( preg_match( '/\bsource=("|\')(.*?)\1/', (string) $shortcode, $m ) ) {
			return strtolower( trim( $m[2] ) );
		}
		return '';
	}

	/**
	 * Read the layout attribute out of a shortcode string.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string 'slider' or 'grid' (the engine's own default).
	 */
	public static function layout_of( $shortcode ) {
		if ( preg_match( '/\blayout=("|\')(.*?)\1/', (string) $shortcode, $m ) ) {
			return strtolower( trim( $m[2] ) );
		}
		return 'grid';
	}

	/**
	 * Read an arbitrary attribute's raw value out of a shortcode string.
	 *
	 * Generic counterpart to source_of() / layout_of() / subset_of() above,
	 * for attributes that have no dedicated parser of their own -- e.g. the
	 * responsive column overrides (columns, columns_tablet, columns_mobile,
	 * gap) that the AJAX card-repaint bridge needs to mirror from the "All
	 * Products" row's own shortcode.
	 *
	 * @param string $shortcode Shortcode.
	 * @param string $name      Attribute name.
	 * @return string Raw attribute value, or '' when the attribute is absent.
	 */
	public static function attr_of( $shortcode, $name ) {
		$pattern = '/\b' . preg_quote( (string) $name, '/' ) . '=("|\')(.*?)\1/';
		if ( preg_match( $pattern, (string) $shortcode, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * Read the current_vendor_subset attribute out of a shortcode string.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string One of self::SUBSETS. Absent resolves to 'all', matching
	 *                the engine's own Source_Current_Vendor default.
	 */
	public static function subset_of( $shortcode ) {
		if ( preg_match( '/\bcurrent_vendor_subset=("|\')(.*?)\1/', (string) $shortcode, $m ) ) {
			$subset = strtolower( trim( $m[2] ) );
			if ( in_array( $subset, self::SUBSETS, true ) ) {
				return $subset;
			}
		}
		return 'all';
	}

	/**
	 * Whether a row resolves to the sidebar + infinite-scroll All Products
	 * grid, i.e. current_vendor_subset is 'all' (or absent).
	 *
	 * @param array $row Section row.
	 * @return bool
	 */
	public static function is_all_products_row( array $row ) {
		$shortcode = (string) ( $row['shortcode'] ?? '' );
		return '' !== $shortcode && 'all' === self::subset_of( $shortcode );
	}

	/**
	 * Whether a shortcode's source is explicitly current_vendor.
	 *
	 * Deliberately strict: an ABSENT source attribute resolves to the
	 * engine's catalogue-wide 'all' source (every vendor's products), not to
	 * current_vendor, so a shortcode with no source attribute at all must
	 * fail this check rather than pass by default.
	 *
	 * @param string $shortcode Shortcode.
	 * @return bool
	 */
	private static function source_is_current_vendor( $shortcode ) {
		return 'current_vendor' === self::source_of( $shortcode );
	}

	/**
	 * Force the engine's heading block off for a section shortcode.
	 *
	 * Any author-supplied show_heading is stripped first, so a leftover
	 * show_heading="yes" cannot produce two headings once this plugin's own
	 * heading markup wraps the shortcode's output.
	 *
	 * @param string $shortcode Shortcode.
	 * @return string
	 */
	public static function force_no_heading( $shortcode ) {
		$shortcode = (string) preg_replace( '/\s+show_heading=("|\')[^"\']*\1/', '', (string) $shortcode );

		$pos = strrpos( $shortcode, ']' );
		if ( false === $pos ) {
			return $shortcode;
		}

		return substr( $shortcode, 0, $pos ) . ' show_heading="no"' . substr( $shortcode, $pos );
	}

	/**
	 * Resolve a section's link, for rows with show_link enabled.
	 *
	 * Every row on a store page is already vendor-scoped, so -- unlike
	 * ZYMARG Single Product's {vendor_name} token resolution, which only
	 * applies to a subset of sources -- there is no per-row vendor detection
	 * needed here. A row's link, when shown, always points at the URL the
	 * admin typed.
	 *
	 * @param array $row Section row.
	 * @return array [] when no link should render, else [ 'url', 'text' ].
	 */
	public static function link( array $row ) {
		if ( empty( $row['show_link'] ) ) {
			return array();
		}

		$url = trim( (string) ( $row['link_url'] ?? '' ) );
		if ( '' === $url ) {
			return array();
		}

		return array(
			'url'  => $url,
			'text' => __( 'Explore More', 'zymarg-store-page' ),
		);
	}

	// -------------------------------------------------------------------------
	// Sanitising
	// -------------------------------------------------------------------------

	/**
	 * Sanitise the posted section list.
	 *
	 * A shortcode that fails EITHER the tag allow-list OR the current_vendor
	 * source restriction is blanked out rather than rejecting the whole row
	 * -- the row survives with an empty shortcode, which simply renders
	 * nothing, exactly like an intentionally empty row does.
	 *
	 * @param array $raw Raw rows, e.g. json_decode()'d from the admin POST.
	 * @return array Clean, re-indexed rows. Array order is render order.
	 */
	public static function sanitize_rows( array $raw ) {
		$allowed = self::allowed_shortcodes();
		$rows    = array();
		$index   = 0;

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$index;

			$shortcode = isset( $row['shortcode'] )
				? sanitize_text_field( wp_unslash( (string) $row['shortcode'] ) )
				: '';

			if ( '' !== $shortcode
				&& ( ! self::shortcode_tag_is_allowed( $shortcode, $allowed )
					|| ! self::source_is_current_vendor( $shortcode ) )
			) {
				$shortcode = '';
			}

			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = 'sec_' . $index . '_' . substr( md5( (string) microtime( true ) . $index ), 0, 6 );
			}

			$enabled   = $row['enabled'] ?? false;
			$show_link = $row['show_link'] ?? false;

			$link_url = isset( $row['link_url'] ) ? trim( (string) wp_unslash( $row['link_url'] ) ) : '';
			$link_url = ( '' === $link_url ) ? '' : esc_url_raw( $link_url );

			$rows[] = array(
				'id'        => $id,
				'label'     => sanitize_text_field( wp_unslash( (string) ( $row['label'] ?? '' ) ) ),
				'enabled'   => ( true === $enabled || '1' === $enabled || 1 === $enabled || 'true' === $enabled ),
				'heading'   => sanitize_text_field( wp_unslash( (string) ( $row['heading'] ?? '' ) ) ),
				'show_link' => ( true === $show_link || '1' === $show_link || 1 === $show_link || 'true' === $show_link ),
				'link_url'  => $link_url,
				'shortcode' => $shortcode,
			);
		}

		return $rows;
	}
}
