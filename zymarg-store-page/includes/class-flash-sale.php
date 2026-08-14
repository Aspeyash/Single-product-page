<?php
/**
 * ZYMARG Store Page -- Marketplace-wide Flash Sale page.
 *
 * A standalone destination at /flash-sale/ that shows every flash-sale product
 * running anywhere on the marketplace, from every vendor, on one page.
 *
 * WHAT THIS FILE DOES AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------
 * It provides a page and nothing else. It does not decide what a flash sale
 * is, it does not query products, and it does not draw a card. Three plugins
 * already own those jobs and each keeps ownership:
 *
 *   ZYMARG WC Product Grid  -- owns "which products are flash deals"
 *                              (Query\Source_Flash_Deals + Flash_Deals_Validator),
 *                              the grid, the countdown, load-more and caching.
 *   ZYMARG Template Pack    -- owns the card DESIGN, registered as the 'flash'
 *                              card template. Its style.css and script.js sit
 *                              beside the template and the engine discovers
 *                              them automatically.
 *   This plugin             -- owns the URL and the page chrome.
 *
 * The practical consequence, and the reason it is built this way: updating
 * ZYMARG Template Pack changes the cards on this page with no change here.
 * The design is not copied into this plugin, so it cannot fall behind.
 *
 * WHY Source_Flash_Deals AND NOT THE VENDOR DASHBOARD'S FLASH META
 * ----------------------------------------------------------------
 * The Vendor Dashboard also has a flash-sale feature (_zymarg_vd_flash_*),
 * used by premium-sections.php to draw a strip on a single vendor's store
 * page. That is a different mechanism with a different definition of "live".
 *
 * The 'flash' card template is built against the ENGINE's definition, and says
 * so in its own header: every product reaching it has passed
 * Flash_Deals_Validator, which is what lets the card render its stock bar and
 * countdown unconditionally without the row's geometry shifting. Feeding that
 * card from the Vendor Dashboard's meta instead would hand it products missing
 * the data those two elements require.
 *
 * So this page asks the engine, using the engine's own site-wide scope.
 *
 * WHY A REAL PAGE AND NOT A REWRITE RULE
 * --------------------------------------
 * This plugin registers no rewrite rules at all today. Adding one would bring
 * a flush-rewrite lifecycle it does not currently have, and a missed flush is
 * a 404 on a live storefront. A real page instead: the marketplace owner can
 * rename it, translate it and put it in a menu, and it is matched the same
 * three ways the store directory is matched.
 *
 * @package ZYMARG_Store_Page
 * @since   1.17.0
 */

defined( 'ABSPATH' ) || exit;

class ZYMARG_SP_Flash_Sale {

	/** Where the provisioned page ID is remembered. */
	const PAGE_OPTION = 'zymarg_sp_flash_sale_page_id';

	/** Marks which plugin version last ran provisioning, so it runs once. */
	const SETUP_OPTION = 'zymarg_sp_flash_sale_setup';

	/** Usable inside any page or template, independent of the route. */
	const SHORTCODE = 'zymarg_flash_sale';

	/** The engine source that defines a flash deal. */
	const SOURCE = 'flash_deals';

	/** The Template Pack card template this page renders with. */
	const CARD = 'flash';

	/** Products in the first batch. Load-more fetches the rest. */
	const PER_PAGE = 24;

	/** Cached list of live Premium flash product IDs, marketplace-wide. */
	const CACHE_KEY = 'zymarg_sp_flash_live_ids';

	/**
	 * Short on purpose: a Premium flash window can open or close at any minute,
	 * and the cache is also flushed on product save, so this is only the
	 * backstop for a window that expires with nothing else happening.
	 */
	const CACHE_TTL = 300;

	// ─────────────────────────────────────────────────────────────────────────
	// Boot
	// ─────────────────────────────────────────────────────────────────────────

	public static function init() {
		// Provisioning. Activation covers fresh installs; the admin_init pass
		// covers installs already active when this version landed, where the
		// activation hook will never fire again.
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_page' ) );

		// Route. Priority 99 matches the store templates. Registered as its own
		// filter rather than folded into ZYMARG_SP_Template_Override so neither
		// route can break the other.
		add_filter( 'template_include', array( __CLASS__, 'override_template' ), 99 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );

		// Keep the cached list honest. Without these a vendor switching a flash
		// sale on would wait out the TTL before appearing on this page.
		add_action( 'save_post_product', array( __CLASS__, 'flush_cache' ), 20 );
		add_action( 'trashed_post', array( __CLASS__, 'flush_cache' ), 20 );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_cache' ), 20 );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ), 20 );
	}

	/**
	 * Drop the cached liveness list.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Slug
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * The slug this page lives on.
	 *
	 * Singular, matching what the rest of the ecosystem already calls this
	 * feature: the engine's source key is flash_deals, the Template Pack card
	 * is 'flash', the Vendor Dashboard's feature key is 'flash_sale' and the
	 * store-page section anchor is zy-flash-sale. Nothing here is pluralised.
	 *
	 * @return string
	 */
	public static function default_slug() {
		$slug = sanitize_title( (string) apply_filters( 'zymarg_sp_flash_sale_slug', 'flash-sale' ) );

		return '' !== $slug ? $slug : 'flash-sale';
	}

	/**
	 * Page title, used only when this plugin creates the page itself.
	 *
	 * Never applied to a page that already existed -- an admin's own title is
	 * their decision.
	 *
	 * @return string
	 */
	public static function default_title() {
		return (string) apply_filters( 'zymarg_sp_flash_sale_title', __( 'Flash Sale', 'zymarg-store-page' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Provisioning
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * The provisioned page ID, or 0.
	 *
	 * Verifies the remembered ID still resolves to a live page, so a page the
	 * admin trashed does not leave a dangling pointer behind.
	 *
	 * @return int
	 */
	public static function page_id() {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $id <= 0 ) {
			return 0;
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return 0;
		}
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Make sure the page exists, without ever creating a second one.
	 *
	 * Order matters, and each step avoids a specific mistake:
	 *
	 *   1. Already remembered  -> nothing to do. Keeps this idempotent, so it
	 *                             is safe to call on every admin request.
	 *   2. Already at the slug -> adopt it. An admin who built this page by
	 *                             hand before updating must not end up with
	 *                             both "flash-sale" and "flash-sale-2".
	 *   3. Slug held elsewhere -> stand down. If another post type owns the
	 *                             slug, adding a page there would leave two
	 *                             things fighting over one URL.
	 *   4. Otherwise           -> create it.
	 *
	 * @return int Page ID, or 0 when the page could not be provisioned.
	 */
	public static function ensure_page() {
		$existing = self::page_id();
		if ( $existing > 0 ) {
			return $existing;
		}

		$slug     = self::default_slug();
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

		// 2. A page already sits on this slug -- adopt rather than duplicate.
		//    Drafts included: the admin may simply not have published it yet,
		//    and creating a rival page underneath them is worse than waiting.
		$found = get_posts(
			array(
				'post_type'        => 'page',
				'name'             => $slug,
				'post_status'      => $statuses,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		if ( ! empty( $found ) ) {
			update_option( self::PAGE_OPTION, (int) $found[0], false );

			return (int) $found[0];
		}

		// 3. Something that is not a page owns this slug. Do not fight for it.
		$clash = get_posts(
			array(
				'post_type'        => 'any',
				'name'             => $slug,
				'post_status'      => $statuses,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		if ( ! empty( $clash ) ) {
			return 0;
		}

		// 4. Create it. Deliberately not added to any menu: where this appears
		//    in navigation is the marketplace owner's decision.
		//
		//    The shortcode goes in the content as a safety net. The template
		//    renders the grid directly and never outputs the_content, so the
		//    two cannot both fire -- but if another plugin ever wins
		//    template_include, the page still shows its grid instead of
		//    rendering blank.
		$page_id = wp_insert_post(
			array(
				'post_title'     => self::default_title(),
				'post_name'      => $slug,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_content'   => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		update_option( self::PAGE_OPTION, (int) $page_id, false );

		/**
		 * Fires once, immediately after the Flash Sale page is created.
		 *
		 * @param int $page_id New page ID.
		 */
		do_action( 'zymarg_sp_flash_sale_page_created', (int) $page_id );

		return (int) $page_id;
	}

	/**
	 * Run provisioning once per plugin version, from the admin only.
	 *
	 * Gated on a stored version string so the common path is a single option
	 * read. Skipped during Ajax and cron: neither is a good moment to be
	 * inserting posts as a side effect of an unrelated request.
	 *
	 * @return void
	 */
	public static function maybe_ensure_page() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( get_option( self::SETUP_OPTION ) === ZYMARG_SP_VERSION ) {
			return;
		}

		self::ensure_page();

		// Written even when provisioning declined, so a slug collision does not
		// mean retrying on every admin request forever.
		update_option( self::SETUP_OPTION, ZYMARG_SP_VERSION, false );
	}

	/**
	 * Activation entry point. Fresh installs land here.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_page();
		update_option( self::SETUP_OPTION, ZYMARG_SP_VERSION, false );
	}

	/**
	 * Permalink of the Flash Sale page, or '' when there isn't one.
	 *
	 * Public so other ZYMARG plugins can link here without guessing the slug.
	 *
	 * @return string
	 */
	public static function page_url() {
		$id = self::page_id();

		return $id > 0 ? (string) get_permalink( $id ) : '';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Detection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Are we on the marketplace Flash Sale page?
	 *
	 * Three ways, for the same reason the store directory has three: any single
	 * one of them can be defeated by a site that renames or rebuilds things.
	 *
	 * @return bool
	 */
	public static function is_flash_sale() {
		if ( is_admin() ) {
			return false;
		}

		$id = self::page_id();
		if ( $id > 0 && is_page( $id ) ) {
			return true;
		}

		if ( ! is_page() ) {
			return false;
		}

		if ( is_page( self::default_slug() ) ) {
			return true;
		}

		$post = get_post();

		return $post instanceof WP_Post
			&& has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Routing and assets
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Swap in the Flash Sale template.
	 *
	 * @param string $template WordPress-resolved template.
	 * @return string
	 */
	public static function override_template( $template ) {
		if ( ! self::is_flash_sale() ) {
			return $template;
		}

		$ours = ZYMARG_SP_TEMPLATES . 'flash-sale.php';

		return file_exists( $ours ) ? $ours : $template;
	}

	/**
	 * Page-chrome styles for this page only.
	 *
	 * Just the header band and layout wrapper. The cards bring their own CSS:
	 * the engine's Asset_Manager loads the Template Pack's flash/style.css
	 * because it sits beside the registered template. Restyling a card here
	 * would fight that sheet and would go stale the moment Template Pack ships
	 * a new design, which is exactly what this page is built to avoid.
	 *
	 * Note there is no Tailwind here. The store page and store directory
	 * templates need the Tailwind browser build because they are written in
	 * utilities; this page is not, so it does not pull that payload in.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_flash_sale() ) {
			return;
		}

		// Registered by spark.php on every request. A registered-but-never-
		// enqueued sheet does not load, and the sheet below is written
		// entirely in --zym-* tokens, so dark mode follows for free.
		if ( wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_enqueue_style( 'zymarg-tokens' );
		}

		wp_enqueue_style(
			'zymarg-sp-flash-sale',
			ZYMARG_SP_URL . 'assets/css/flash-sale.css',
			array(),
			ZYMARG_SP_VERSION
		);

		/*
		 * The hero's admin-set custom properties, attached to the sheet they
		 * override so they land in the same place and never flash the shipped
		 * design first. Emits nothing at all when every control is at its
		 * default, which is the case on a fresh install.
		 */
		if ( class_exists( 'ZYMARG_SP_Flash_Hero' ) ) {
			ZYMARG_SP_Flash_Hero::inline_css( 'zymarg-sp-flash-sale' );

			/*
			 * The countdown script, and only when a countdown is actually being
			 * drawn. The server prints the absolute deadline into the markup, so
			 * this file only ticks -- with it blocked or broken the deadline is
			 * still readable as a formatted date.
			 */
			$zfs_hero = ZYMARG_SP_Flash_Hero::get_settings();

			if ( ! empty( $zfs_hero['show_countdown'] ) && ZYMARG_SP_Flash_Hero::soonest_end() > 0 ) {
				wp_enqueue_script(
					'zymarg-sp-flash-hero',
					ZYMARG_SP_URL . 'assets/js/flash-hero.js',
					array(),
					ZYMARG_SP_VERSION,
					true
				);
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rendering
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Is the Product Grid engine available to render through?
	 *
	 * @return bool
	 */
	public static function engine_available() {
		return class_exists( '\Zymarg\WCPG\Api\Public_API' )
			&& method_exists( '\Zymarg\WCPG\Api\Public_API', 'render' );
	}

	/**
	 * Is the Template Pack's flash card registered?
	 *
	 * Checked separately from the engine because they are separate plugins and
	 * can be deactivated independently. Without it the engine would silently
	 * fall back to its own default card, which renders no countdown and no
	 * stock bar -- a materially different page. Better to say so than to
	 * quietly serve a lesser design.
	 *
	 * @return bool
	 */
	public static function card_available() {
		if ( ! class_exists( '\Zymarg\WCPG\Templates\Template_Manager' ) ) {
			return false;
		}

		// The engine exposes no public "is this card registered" call, so this
		// leans on the Template Pack being loaded at all. Its whole job is to
		// register the card on zymarg_wcpg_init.
		return defined( 'ZYMARG_TEMPLATE_PACK_VERSION' )
			|| class_exists( 'Zymarg_Template_Pack_Badge_Resolver' );
	}

	/**
	 * The render config handed to the engine.
	 *
	 * Only the keys this page genuinely has an opinion about are set. Anything
	 * omitted keeps the engine's default, and the Template Pack layers its own
	 * brand defaults on top -- so the page inherits improvements to either
	 * plugin instead of pinning them.
	 *
	 * @return array<string,mixed>
	 */
	public static function render_config() {
		$per_page = (int) apply_filters( 'zymarg_sp_flash_per_page', self::PER_PAGE );
		$per_page = max( 1, min( 100, $per_page ) ); // Query_Builder::MAX_PRODUCTS.

		$config = array(
			'query'      => array(
				'source' => self::SOURCE,

				// The whole point of this page. 'auto' would scope to a vendor
				// whenever a vendor context happened to be resolvable, which on
				// a standalone marketplace page would silently show one seller's
				// deals instead of everyone's.
				'flash_vendor_scope' => 'site',

				// Ending soonest first. Already the source's default; stated
				// explicitly because this page is a countdown page and that
				// ordering is a requirement here, not a preference.
				'flash_orderby'      => 'sale_end',

				'limit'              => $per_page,
			),
			'layout'     => array(
				'type'    => 'grid',
				'columns' => 4,
			),
			'card'       => array(
				'template' => self::CARD,
			),
			'pagination' => array(
				'mode' => 'load_more',
			),
		);

		/**
		 * Filter the Flash Sale page's grid config.
		 *
		 * @param array $config Engine render config.
		 */
		return (array) apply_filters( 'zymarg_sp_flash_render_config', $config );
	}

	/**
	 * The Flash Sale grid, as markup.
	 *
	 * Single renderer, shared by the template and the shortcode, so the two can
	 * never drift into two different Flash Sale grids.
	 *
	 * Returns '' when there is nothing to show. Callers decide what to put in
	 * that space -- this does not invent a placeholder card or a fake count.
	 *
	 * @return string
	 */
	/**
	 * Every Vendor Dashboard Premium flash sale running right now, marketplace-wide.
	 *
	 * WHY THIS EXISTS (fixed in 1.18.3)
	 * ---------------------------------
	 * This page originally asked the engine's flash_deals source, whose
	 * definition of a flash sale is "on sale in WooCommerce with a future sale
	 * end date". Premium never satisfies that and never will: it applies its
	 * price at runtime and deliberately leaves _sale_price empty, so
	 * wc_get_product_ids_on_sale() cannot see it. On a marketplace whose flash
	 * sales are all run through Premium, this page was therefore always empty.
	 *
	 * Premium exposes only a per-vendor lookup, so the query here is by its meta
	 * flag and every candidate is then put through Premium's own liveness test.
	 * That test applies the admin master switch, the vendor's approval and the
	 * date window, so the approval workflow keeps full authority and no part of
	 * it is reimplemented here.
	 *
	 * @return array<int,int> Product IDs, soonest-ending first.
	 */
	public static function premium_flash_ids() {
		if ( ! function_exists( 'zymarg_vd_premium_flash_is_live' )
			|| ! function_exists( 'zymarg_vd_premium_get_flash_data' )
			|| ! defined( 'ZYMARG_VD_PREMIUM_META_FLASH_ON' ) ) {
			return array();
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ceiling = max( 1, (int) apply_filters( 'zymarg_sp_flash_scan_ceiling', 800 ) );

		$candidates = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => $ceiling,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => ZYMARG_VD_PREMIUM_META_FLASH_ON,
						'value' => 'yes',
					),
				),
			)
		);

		$live = array();
		foreach ( (array) $candidates as $pid ) {
			$pid = (int) $pid;
			if ( ! zymarg_vd_premium_flash_is_live( $pid ) ) {
				continue;
			}

			$data = (array) zymarg_vd_premium_get_flash_data( $pid );
			$end  = isset( $data['end'] ) ? (string) $data['end'] : '';
			$ts   = ( '' !== $end && function_exists( 'zymarg_sp_premium_window_ts' ) )
				? zymarg_sp_premium_window_ts( $end )
				: 0;

			// Open-ended sales sort last: they are not about to disappear, so
			// they do not deserve the top of a countdown page.
			$live[ $pid ] = $ts > 0 ? $ts : PHP_INT_MAX;
		}

		asort( $live, SORT_NUMERIC );
		$ids = array_map( 'intval', array_keys( $live ) );

		/**
		 * Filter the marketplace-wide list of live Premium flash product IDs.
		 *
		 * @since 1.18.3
		 *
		 * @param array<int,int> $ids Product IDs, soonest-ending first.
		 */
		$ids = array_values( (array) apply_filters( 'zymarg_sp_flash_live_ids', $ids ) );

		set_transient( self::CACHE_KEY, $ids, self::CACHE_TTL );

		return $ids;
	}

	public static function render_grid() {
		if ( ! self::engine_available() ) {
			return '';
		}

		/*
		 * Premium first, through the registered 'premium_flash' source rather
		 * than a pre-fetched list.
		 *
		 * That distinction is the whole point. A pre-fetched list skips the
		 * engine's Query Engine, and load-more works by re-running the query
		 * with an offset -- so there was nothing to re-run, the page stopped at
		 * its first batch, and a marketplace with a thousand live flash sales
		 * could only ever show the first two dozen of them. As a registered
		 * source it pages like any other grid.
		 *
		 * The Premium approval workflow still decides what appears: the source
		 * puts every candidate through zymarg_vd_premium_flash_is_live().
		 */
		$source_ready = function_exists( 'zymarg_sp_declare_premium_flash_source' )
			&& zymarg_sp_declare_premium_flash_source();

		if ( $source_ready && class_exists( 'ZYMARG_SP_Grid_Bridge' ) ) {
			$html = ZYMARG_SP_Grid_Bridge::render_source(
				'premium_flash',
				ZYMARG_SP_Grid_Bridge::CARD_FLASH,
				max( 1, min( 100, (int) apply_filters( 'zymarg_sp_flash_per_page', self::PER_PAGE ) ) ),
				array(
					'layout'     => array(
						'type'    => 'grid',
						'columns' => 4,
					),
					'pagination' => array(
						'mode' => 'load_more',
					),
				)
			);

			if ( '' !== $html ) {
				return $html;
			}
		}

		// Fallback: a site whose flash sales run on WooCommerce sale windows
		// rather than Premium still gets a populated page.
		$html = \Zymarg\WCPG\Api\Public_API::render(
			array(
				'config'    => self::render_config(),
				'widget_id' => 'zymarg-sp-flash-sale',
			)
		);

		// HIDE_WIDGET is not an error: the source is telling us nothing
		// qualifies right now. On a widget you suppress the section; on a page
		// whose entire purpose is this grid, the page still has to say
		// something, so the empty state is the template's call.
		if ( ! is_string( $html ) || \Zymarg\WCPG\Api\Public_API::HIDE_WIDGET === $html ) {
			return '';
		}

		return $html;
	}

	/**
	 * [zymarg_flash_sale]
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return self::render_grid();
	}
}
