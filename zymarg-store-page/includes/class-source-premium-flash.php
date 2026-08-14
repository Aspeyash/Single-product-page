<?php
/**
 * ZYMARG Store Page -- "Premium Flash Sale" engine source.
 *
 * Registers Vendor Dashboard Premium flash sales as a first-class ZYMARG WC
 * Product Grid source, so the engine can query it like any other. That is what
 * gives the /flash-sale/ page load-more, infinite scroll and the engine's
 * render cache -- none of which were possible while the page handed the engine
 * a pre-fetched list, because load-more works by re-running the query with an
 * offset and there was no query to re-run.
 *
 * WHY THE GATE STILL LIVES IN THE VENDOR DASHBOARD
 * -----------------------------------------------
 * Whether a product's flash sale is live is decided by exactly one function,
 * and this class calls it:
 *
 *   zymarg_vd_premium_flash_is_live( $product_id )
 *
 * That applies the admin master switch, the vendor's approval state, a positive
 * price and both ends of the date window. None of it is reimplemented here, so
 * the Premium approval workflow keeps sole authority over what appears and this
 * source cannot drift away from it.
 *
 * WHY THE WINDOW IS NOT CHECKED IN SQL
 * ------------------------------------
 * It would be faster, and it is the obvious optimisation, but it would mean
 * writing Premium's liveness rule a second time -- in a different language,
 * against unindexed date strings, with "empty means open-ended" on both ends
 * and a site-local/UTC comparison that Premium performs in PHP. Two copies of
 * that rule would eventually disagree, and the disagreement would show up as
 * products appearing on a page that Premium considers expired.
 *
 * So SQL narrows to the one thing it can narrow safely -- the flag -- and the
 * rule stays where it is written. The cost is bounded by scanning in batches
 * and stopping as soon as the requested page is full.
 *
 * SCALE
 * -----
 * The naive version of this loaded every flagged product, filtered in PHP,
 * then showed the first 24: slow on a large catalogue and incapable of showing
 * the 25th. This walks a cursor in fixed batches and stops the moment it has
 * enough survivors for the requested offset, so cost is proportional to the
 * page being viewed rather than to the size of the catalogue.
 *
 * @package ZYMARG_Store_Page
 * @since   1.19.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the source with the engine.
 *
 * The class is only declared when the engine is present, because it extends an
 * engine class -- extending a class that does not exist is a fatal error, so
 * the declaration cannot sit at file scope unguarded.
 */
/**
 * Declare the source class, if it can be declared.
 *
 * Split out of the registry filter and given its own early hook because of a
 * bug in 1.19.0: the class used to be declared *inside* that filter, which only
 * runs when the engine resolves a source -- i.e. during a render. Any code that
 * checked class_exists() beforehand to decide whether to render at all saw
 * nothing, skipped the source, and fell through to an empty page. The guard ran
 * before the thing it was guarding.
 *
 * @return bool Whether the class is available.
 */
function zymarg_sp_declare_premium_flash_source() {
		if ( ! class_exists( '\Zymarg\WCPG\Query\Source_Base' ) ) {
			return false;
		}
		if ( ! function_exists( 'zymarg_vd_premium_flash_is_live' ) || ! defined( 'ZYMARG_VD_PREMIUM_META_FLASH_ON' ) ) {
			return false;
		}

		if ( ! class_exists( 'ZYMARG_SP_Source_Premium_Flash' ) ) {

			/**
			 * Premium flash sales as an engine source.
			 */
			class ZYMARG_SP_Source_Premium_Flash extends \Zymarg\WCPG\Query\Source_Base {

				/** Products examined per database round trip. */
				const BATCH = 100;

				/** Hard ceiling on products examined for one page request. */
				const MAX_SCAN = 2000;

				/**
				 * @param array $settings Flattened render settings.
				 * @return \WC_Product[]|string
				 */
				public function get_products( array $settings ) {
					if ( ! function_exists( 'wc_get_product' ) ) {
						return self::HIDE_WIDGET;
					}

					$limit  = max( 1, (int) $this->get_limit( $settings ) );
					$offset = isset( $settings['_offset'] ) ? max( 0, (int) $settings['_offset'] ) : 0;

					// Optional vendor scoping, so the same source can serve a
					// single store as well as the marketplace-wide page.
					$vendor_id = 0;
					if ( ! empty( $settings['premium_flash_vendor'] ) ) {
						$vendor_id = (int) $settings['premium_flash_vendor'];
					}

					$needed  = $offset + $limit;
					$live    = array();
					$cursor  = 0;
					$scanned = 0;

					while ( count( $live ) < $needed && $scanned < self::MAX_SCAN ) {
						$batch = $this->fetch_batch( $cursor, $vendor_id, $settings );
						if ( empty( $batch ) ) {
							break; // No more flagged products at all.
						}

						$cursor  += count( $batch );
						$scanned += count( $batch );

						foreach ( $batch as $pid ) {
							if ( zymarg_vd_premium_flash_is_live( (int) $pid ) ) {
								$live[] = (int) $pid;
							}
						}
					}

					if ( empty( $live ) ) {
						return self::HIDE_WIDGET;
					}

					$page_ids = array_slice( $live, $offset, $limit );
					if ( empty( $page_ids ) ) {
						// Past the end of the list. Not an error -- load-more
						// asked for a page that does not exist, and an empty
						// array is how the engine learns to stop.
						return array();
					}

					$products = $this->fetch_products_by_ids( $page_ids, $limit );

					// Deliberately NOT run through apply_shared_filters(): its
					// stock and visibility tests would second-guess a product a
					// vendor chose and an admin approved, putting that decision
					// in two places.
					return is_array( $products ) ? array_values( $products ) : array();
				}

				/**
				 * One page of flagged candidate IDs.
				 *
				 * Ordered newest first. Ordering by the flash end date would put
				 * the most urgent sale first, which suits a countdown page, but
				 * it cannot be done correctly in SQL here: the end date is an
				 * unindexed string and an empty value means "no finish", which
				 * sorts before every real date and would float the least urgent
				 * sales to the top. Doing it properly in PHP would mean reading
				 * every flash product on every page load -- exactly the cost
				 * this class exists to avoid.
				 *
				 * Override with zymarg_sp_premium_flash_query_args if a
				 * particular catalogue wants different ordering.
				 *
				 * @param int   $cursor    Offset into the flagged set.
				 * @param int   $vendor_id Vendor to scope to, 0 for site-wide.
				 * @param array $settings  Render settings.
				 * @return int[]
				 */
				private function fetch_batch( $cursor, $vendor_id, array $settings ) {
					$args = array(
						'post_type'        => 'product',
						'post_status'      => 'publish',
						'posts_per_page'   => self::BATCH,
						'offset'           => (int) $cursor,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'ignore_sticky_posts' => true,
						'suppress_filters' => false,
						'orderby'          => 'date',
						'order'            => 'DESC',
						'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => ZYMARG_VD_PREMIUM_META_FLASH_ON,
								'value' => 'yes',
							),
						),
					);

					if ( $vendor_id > 0 ) {
						$args['author'] = $vendor_id;
					}

					/**
					 * Filter the candidate query for one batch.
					 *
					 * Note this runs per batch and must not set 'offset' --
					 * the cursor owns that.
					 *
					 * @since 1.19.0
					 *
					 * @param array $args     WP_Query arguments.
					 * @param array $settings Render settings.
					 */
					$args = (array) apply_filters( 'zymarg_sp_premium_flash_query_args', $args, $settings );

					// Restored after filtering: a filter that changed these
					// would silently break paging.
					$args['offset']         = (int) $cursor;
					$args['posts_per_page'] = self::BATCH;

					return array_map( 'intval', (array) get_posts( $args ) );
				}
			}
		}

	return class_exists( 'ZYMARG_SP_Source_Premium_Flash' );
}

/*
 * zymarg_wcpg_init fires from the engine's own boot on plugins_loaded:20, which
 * is after Source_Base can be autoloaded and well before any render. Declaring
 * here means class_exists( 'ZYMARG_SP_Source_Premium_Flash' ) is answerable by
 * the time a template asks.
 */
add_action( 'zymarg_wcpg_init', 'zymarg_sp_declare_premium_flash_source', 20 );

add_filter(
	'zymarg_wcpg_source_registry',
	static function ( $registry ) {
		if ( ! is_array( $registry ) ) {
			return $registry;
		}

		// Called again rather than assumed: the registry filter can run in an
		// AJAX request where zymarg_wcpg_init fired on a different code path.
		if ( zymarg_sp_declare_premium_flash_source() ) {
			$registry['premium_flash'] = 'ZYMARG_SP_Source_Premium_Flash';
		}

		return $registry;
	},
	20
);
