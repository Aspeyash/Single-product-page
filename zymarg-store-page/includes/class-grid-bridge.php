<?php
/**
 * ZYMARG Store Page -- Product Grid engine bridge.
 *
 * The single place this plugin talks to the ZYMARG WC Product Grid engine.
 * Every product card on the store page goes through here, so no surface of
 * this plugin draws a product card of its own any more.
 *
 * WHY A BRIDGE AND NOT DIRECT CALLS
 * ---------------------------------
 * The engine documents exactly one stable entry point -- Public_API::render().
 * Everything else (Render_Engine, Query_Builder, Template_Manager) is declared
 * internal and free to change between releases. Funnelling through one class
 * means a future engine change is a fix in one file rather than a hunt across
 * templates. This mirrors the ZYMARG Homepage plugin's ProductGridBridge,
 * deliberately: that bridge is already proven in production against this same
 * engine, so its shape is copied rather than reinvented.
 *
 * WHAT THIS BRIDGE DOES NOT DECIDE
 * --------------------------------
 *   - It does not decide which products appear. Callers pass them, or name an
 *     engine source.
 *   - It does not decide how a card looks. The ZYMARG Template Pack owns that,
 *     as the registered 'zymarg' and 'flash' card templates.
 *
 * ASSETS
 * ------
 * A card template ships its own stylesheet beside its PHP file, and the engine
 * exposes the lookup but does not enqueue it for an external consumer. So the
 * consumer enqueues it -- again the same approach the Homepage plugin takes.
 *
 * @package ZYMARG_Store_Page
 * @since   1.18.0
 */

defined( 'ABSPATH' ) || exit;

class ZYMARG_SP_Grid_Bridge {

	/** The engine's one documented-stable entry point. */
	const ENGINE_API = '\\Zymarg\\WCPG\\Api\\Public_API';

	/** The engine's template registry, used only for asset lookup. */
	const ENGINE_TEMPLATES = '\\Zymarg\\WCPG\\Templates\\Template_Manager';

	/** General-purpose ZYMARG card, from the Template Pack. */
	const CARD_GENERAL = 'zymarg';

	/** Flash Sales card, from the Template Pack. */
	const CARD_FLASH = 'flash';

	/** AJAX action that repaints the store grid with engine cards. */
	const AJAX_ACTION = 'zymarg_sp_render_cards';

	/** Nonce action for that endpoint. */
	const AJAX_NONCE = 'zymarg_sp_render_cards';

	/** Hard ceiling on IDs accepted in one request. */
	const AJAX_MAX_IDS = 60;

	/**
	 * Card slugs already enqueued this request.
	 *
	 * @var array<string,bool>
	 */
	private static $enqueued = array();

	// ─────────────────────────────────────────────────────────────────────────
	// Boot
	// ─────────────────────────────────────────────────────────────────────────

	public static function init() {
		// Shoppers browse a store logged out, so the nopriv variant is not
		// optional.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_render_cards' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_render_cards' ) );

		// Priority 20: the engine registers its handles on this same hook at
		// priority 5, so they exist by the time this runs. See preload_assets()
		// for why enqueueing at render time is not good enough.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'preload_assets' ), 20 );

		// Belt to preload_assets' braces. See print_critical_css().
		add_action( 'wp_head', array( __CLASS__, 'print_critical_css' ), 2 );
	}

	/**
	 * Print the card grid's load-bearing geometry inline in the <head>.
	 *
	 * WHY INLINE, WHEN preload_assets() ALREADY ENQUEUES THESE SHEETS
	 * --------------------------------------------------------------
	 * preload_assets() is the right fix and remains the primary one, but an
	 * enqueued stylesheet is a separate HTTP request whose arrival this plugin
	 * does not fully control: a page-cache plugin can serve HTML that still
	 * points at a stale sheet, a CDN can delay it, a proxy can reorder it. When
	 * that sheet is late, a server-rendered card -- the Flash Sale section is
	 * the only card on the store page present in the initial HTML -- paints
	 * before its geometry arrives. With no aspect-ratio on the image box the
	 * product image lays out at its natural resolution, and one card fills the
	 * screen until the sheet lands. That is the reported layout shift.
	 *
	 * Inline CSS cannot be late: it is part of the HTML document itself, so it
	 * is present the instant the card is. It is deliberately the smallest set
	 * of rules that reserve space -- the grid track and the 1:1 image box, for
	 * both the general and flash cards -- and nothing cosmetic. The card's own
	 * stylesheet still owns everything else.
	 *
	 * Every rule is wrapped in :where() for zero specificity, so the engine's
	 * frontend.css and the card's style.css override all of it the moment they
	 * load, with no !important and no risk of pinning a value that later
	 * changes upstream. The worst this can do is briefly hold the right shape.
	 *
	 * @return void
	 */
	public static function print_critical_css() {
		if ( ! self::is_our_surface() ) {
			return;
		}

		// No URLs, no user data -- a static string of geometry. Safe to echo.
		echo "<style id=\"zymarg-sp-critical\">"
			// Grid track: without this a card is a full-width block.
			. ':where(.zymarg-wcpg__grid){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}'
			. '@media(min-width:768px){'
				. ':where(.zymarg-wcpg__grid--cols-3),:where(.zymarg-wcpg__grid--cols-4),'
				. ':where(.zymarg-wcpg__grid--cols-5),:where(.zymarg-wcpg__grid--cols-6){grid-template-columns:repeat(3,minmax(0,1fr))}'
			. '}'
			. '@media(min-width:1024px){'
				. ':where(.zymarg-wcpg__grid--cols-4){grid-template-columns:repeat(4,minmax(0,1fr))}'
				. ':where(.zymarg-wcpg__grid--cols-5){grid-template-columns:repeat(5,minmax(0,1fr))}'
				. ':where(.zymarg-wcpg__grid--cols-6){grid-template-columns:repeat(6,minmax(0,1fr))}'
			. '}'
			// Image box: this is what stops one product filling the viewport.
			// The link must be a filling block or the <img> escapes the square.
			. ':where(.zymarg-zc__image-wrap){position:relative;aspect-ratio:1/1;overflow:hidden}'
			. ':where(.zymarg-zc__image-link){display:block;width:100%;height:100%}'
			. ':where(.zymarg-zc__image){width:100%;height:100%;object-fit:cover;display:block}'
			// Last-ditch cover for any card whose image uses none of the above.
			. ':where(.zymarg-wcpg__card) img{max-width:100%;height:auto}'
			. '</style>';
	}

	/**
	 * Enqueue every engine and card stylesheet before first paint.
	 *
	 * THE BUG THIS FIXES
	 * ------------------
	 * The engine registers its handles on wp_enqueue_scripts but only enqueues
	 * them from inside its render routine. Every render on this plugin's pages
	 * happens inside a template -- store.php, flash-sale.php -- which runs long
	 * after wp_head() has been sent. WordPress therefore prints those <link>
	 * tags in the footer.
	 *
	 * The visible result is not a subtle flash. frontend.css is where the grid
	 * itself is defined, so until it lands every card is a plain block and the
	 * whole grid renders as a single vertical column, then snaps into place when
	 * the stylesheet finally arrives at the end of the document.
	 *
	 * So the sheets are enqueued here instead, during wp_enqueue_scripts, well
	 * before first paint. Enqueueing under the engine's own handles keeps this
	 * idempotent: when the engine reaches its render-time enqueue it finds the
	 * handle already enqueued and does nothing, and the URL and version stay
	 * exactly what the engine would have produced.
	 *
	 * The same reasoning and the same fix are documented in the ZYMARG Homepage
	 * plugin, which hit this first.
	 *
	 * @return void
	 */
	public static function preload_assets() {
		if ( ! self::is_active() || ! self::is_our_surface() ) {
			return;
		}

		// frontend.css carries the grid. Without it the cards stack vertically,
		// so this one is not optional.
		self::enqueue_registered( 'zymarg-wcpg-frontend', 'style' );

		// The cards render quick view and a slider, and both bring their own
		// sheet. Cheap to include and they would otherwise arrive late too.
		self::enqueue_registered( 'zymarg-wcpg-quickview', 'style' );
		self::enqueue_registered( 'zymarg-wcpg-slider', 'style' );

		// Scripts are deferred and delegate from document, so the footer is
		// fine for them -- but enqueueing here costs nothing and means add to
		// cart, wishlist and quick view are wired even on a render path the
		// engine did not expect.
		self::enqueue_registered( 'zymarg-wcpg-frontend', 'script' );
		self::enqueue_registered( 'zymarg-wcpg-quickview', 'script' );

		// Both cards this plugin can render. Which one appears depends on the
		// vendor's Premium approval, and that is not known this early, so both
		// are preloaded rather than guessing.
		foreach ( array( self::CARD_GENERAL, self::CARD_FLASH ) as $card ) {
			self::enqueue_card_assets( $card );
		}
	}

	/**
	 * Is this a page where this plugin renders product cards?
	 *
	 * @return bool
	 */
	private static function is_our_surface() {
		if ( function_exists( 'dokan_is_store_page' ) && dokan_is_store_page() ) {
			return true;
		}

		if ( class_exists( 'ZYMARG_SP_Flash_Sale' ) && ZYMARG_SP_Flash_Sale::is_flash_sale() ) {
			return true;
		}

		/**
		 * Filter whether engine assets are preloaded on the current request.
		 *
		 * @since 1.18.2
		 *
		 * @param bool $preload Whether to preload.
		 */
		return (bool) apply_filters( 'zymarg_sp_preload_grid_assets', false );
	}

	/**
	 * Enqueue a handle only if the engine actually registered it.
	 *
	 * Handle names are the engine's, matched by string rather than by reading
	 * its Assets constants: that class is documented as internal, so depending
	 * on its shape would be worse than depending on two stable strings. A
	 * missing handle is simply skipped.
	 *
	 * @param string $handle Asset handle.
	 * @param string $type   'style' or 'script'.
	 * @return void
	 */
	private static function enqueue_registered( $handle, $type ) {
		if ( 'script' === $type ) {
			if ( wp_script_is( $handle, 'registered' ) && ! wp_script_is( $handle, 'enqueued' ) ) {
				wp_enqueue_script( $handle );
			}
			return;
		}

		if ( wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Repaint a set of products as engine cards.
	 *
	 * This exists so the store page's existing search, sort and category
	 * filtering keep working exactly as they do while still producing ZYMARG
	 * cards. Those features already know WHICH products to show -- they resolve
	 * that from Dokan's REST API. What they could not do is draw a ZYMARG card,
	 * because the card is a PHP template. So they send the IDs here and get the
	 * markup back.
	 *
	 * Read-only: it renders products by ID and writes nothing.
	 *
	 * @return void
	 */
	public static function ajax_render_cards() {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
		}

		if ( ! self::is_active() ) {
			wp_send_json_error( array( 'message' => 'engine_inactive' ), 503 );
		}

		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= self::AJAX_MAX_IDS ) {
				break;
			}
		}

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'html'  => '',
					'count' => 0,
				)
			);
		}

		$html = self::render_products( $ids, self::CARD_GENERAL, self::all_products_layout_overrides() );

		wp_send_json_success(
			array(
				'html'  => $html,
				'count' => count( $ids ),
			)
		);
	}

	/**
	 * Layout overrides mirroring the admin's "All Products" row.
	 *
	 * WHY THIS EXISTS
	 * ----------------
	 * ajax_render_cards() repaints the grid for search results and category
	 * filtering. Its config previously hardcoded 'columns' => 4 with no
	 * responsive breakpoint keys at all, so a search or category switch
	 * always rendered a flat 4-column grid on every device -- ignoring
	 * whatever columns / columns_tablet / columns_mobile / gap the admin had
	 * configured on the "All Products" row's own shortcode (which the normal,
	 * non-AJAX page-load render DOES honour, via do_shortcode() in
	 * templates/store.php).
	 *
	 * This reads those same four attributes straight out of that row's saved
	 * shortcode -- the single source of truth already used for the initial
	 * render -- so a search/category repaint always matches it, with no
	 * separate setting to keep in sync and nothing hardcoded here.
	 *
	 * @return array<string,mixed> Config overrides, or [] when there is no
	 *                              "All Products" row to read from.
	 */
	private static function all_products_layout_overrides() {
		if ( ! class_exists( 'ZYMARG_SP_Store_Sections' ) ) {
			return array();
		}

		$row = ZYMARG_SP_Store_Sections::get_all_products_row();
		if ( null === $row ) {
			return array();
		}

		$shortcode = (string) ( $row['shortcode'] ?? '' );
		if ( '' === $shortcode ) {
			return array();
		}

		$columns        = ZYMARG_SP_Store_Sections::attr_of( $shortcode, 'columns' );
		$columns_tablet = ZYMARG_SP_Store_Sections::attr_of( $shortcode, 'columns_tablet' );
		$columns_mobile = ZYMARG_SP_Store_Sections::attr_of( $shortcode, 'columns_mobile' );
		$gap            = ZYMARG_SP_Store_Sections::attr_of( $shortcode, 'gap' );

		$overrides = array();

		if ( '' !== $columns ) {
			$overrides['layout']['columns'] = max( 1, min( 6, (int) $columns ) );
		}
		if ( '' !== $gap ) {
			$overrides['layout']['gap'] = max( 0, min( 100, (int) $gap ) );
		}
		if ( '' !== $columns_tablet ) {
			$overrides['responsive']['tablet']['layout']['columns'] = max( 1, min( 6, (int) $columns_tablet ) );
		}
		if ( '' !== $columns_mobile ) {
			$overrides['responsive']['mobile']['layout']['columns'] = max( 1, min( 6, (int) $columns_mobile ) );
		}

		return $overrides;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Availability
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Is the Product Grid engine present and usable?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( self::ENGINE_API ) && method_exists( self::ENGINE_API, 'render' );
	}

	/**
	 * Is the Template Pack present, i.e. are the ZYMARG cards registered?
	 *
	 * Checked separately from the engine because they are separate plugins that
	 * can be deactivated independently. Without the pack the engine silently
	 * falls back to its own 'classic' card, which is a materially different
	 * design -- better to be able to say so than to serve it unannounced.
	 *
	 * @return bool
	 */
	public static function has_template_pack() {
		return class_exists( 'Zymarg_Template_Pack_Badge_Resolver' )
			|| class_exists( 'Zymarg_Template_Pack_Defaults' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rendering
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render a grid of pre-fetched products.
	 *
	 * Used for both Premium sections. Passing 'products' makes the engine skip
	 * its Query Engine entirely, which is what keeps the Vendor Dashboard's
	 * Premium workflow -- master switch, approval state, per-vendor caps -- the
	 * sole authority over which products appear. The engine is asked to draw a
	 * list, never to choose one.
	 *
	 * A useful consequence: because the query is skipped, the Vendor Dashboard's
	 * own defensive grid exclusion (which hooks zymarg_wcpg_query_args, and only
	 * runs inside engine Source classes) cannot strip these products out.
	 *
	 * @param int[]                $product_ids Product IDs, in display order.
	 * @param string               $card        Card template slug.
	 * @param array<string,mixed>  $overrides   Config overrides, merged deeply.
	 * @return string HTML, or '' when nothing could be rendered.
	 */
	public static function render_products( array $product_ids, $card, array $overrides = array() ) {
		if ( ! self::is_active() ) {
			return '';
		}

		$products = self::hydrate( $product_ids );
		if ( empty( $products ) ) {
			return '';
		}

		$config = self::build_config( $card, count( $products ), '', $overrides );

		return self::call_engine(
			array(
				'config'   => $config,
				'products' => $products,
			),
			$card
		);
	}

	/**
	 * Render a grid from an engine source.
	 *
	 * Used where the engine should run the query itself, which also gives the
	 * grid working load-more -- pre-fetched products cannot paginate, because
	 * load-more re-runs the query with an offset.
	 *
	 * @param string              $source    Engine source key.
	 * @param string              $card      Card template slug.
	 * @param int                 $limit     Products per batch.
	 * @param array<string,mixed> $overrides Config overrides, merged deeply.
	 * @return string HTML, or '' when nothing could be rendered.
	 */
	public static function render_source( $source, $card, $limit = 24, array $overrides = array() ) {
		if ( ! self::is_active() || '' === $source ) {
			return '';
		}

		$config = self::build_config( $card, max( 1, (int) $limit ), $source, $overrides );

		return self::call_engine( array( 'config' => $config ), $card );
	}

	/**
	 * Invoke the engine and normalise everything it can hand back.
	 *
	 * @param array  $args Render arguments.
	 * @param string $card Card slug, for asset enqueueing.
	 * @return string
	 */
	private static function call_engine( array $args, $card ) {
		$args['widget_id'] = isset( $args['widget_id'] ) ? $args['widget_id'] : 'zymarg-sp-' . sanitize_key( $card ) . '-' . wp_rand( 1000, 9999 );

		try {
			$html = call_user_func( array( self::ENGINE_API, 'render' ), $args );
		} catch ( \Throwable $e ) {
			// The engine documents that render() never throws. A bridge should
			// not depend on another plugin honouring its own contract.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[ZYMARG Store Page] Product Grid bridge failed: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return '';
		}

		if ( ! is_string( $html ) ) {
			return '';
		}

		// HIDE_WIDGET is not an error: a context-aware source is saying nothing
		// belongs here. Callers decide what to show instead.
		if ( defined( self::ENGINE_API . '::HIDE_WIDGET' ) && constant( self::ENGINE_API . '::HIDE_WIDGET' ) === $html ) {
			return '';
		}

		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		self::enqueue_card_assets( $card );

		return $html;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Config
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * The base config every store-page grid starts from.
	 *
	 * Only keys this plugin has a genuine opinion about are set. Everything
	 * omitted keeps the engine's default and then the Template Pack's brand
	 * defaults on top, so the store page inherits improvements to either plugin
	 * instead of pinning them to whatever they were today.
	 *
	 * @param string              $card      Card template slug.
	 * @param int                 $limit     Product limit.
	 * @param string              $source    Engine source key, '' for pre-fetched.
	 * @param array<string,mixed> $overrides Config overrides.
	 * @return array<string,mixed>
	 */
	private static function build_config( $card, $limit, $source = '', array $overrides = array() ) {
		$query = array( 'limit' => max( 1, (int) $limit ) );

		if ( '' !== $source ) {
			$query['source'] = $source;
		}

		$config = array(
			'query'      => $query,
			'layout'     => array(
				'type'    => 'grid',
				'columns' => 4,
			),
			'card'       => array(
				'template' => $card,
			),
			// The store page draws its own section headings, and the Premium
			// sections are capped by the Vendor Dashboard rather than paged.
			'heading'    => array(
				'show' => false,
			),
			'pagination' => array(
				'mode' => 'none',
			),
		);

		$config = self::merge( $config, $overrides );

		/**
		 * Filter the config sent to the Product Grid engine.
		 *
		 * @since 1.18.0
		 *
		 * @param array  $config Engine config.
		 * @param string $card   Card template slug.
		 * @param string $source Engine source key, '' when products are pre-fetched.
		 */
		return (array) apply_filters( 'zymarg_sp_grid_config', $config, $card, $source );
	}

	/**
	 * Recursive array merge where the override wins on scalars.
	 *
	 * array_merge_recursive() would append scalars into arrays instead of
	 * replacing them, which silently corrupts a config.
	 *
	 * @param array $base     Base config.
	 * @param array $override Overrides.
	 * @return array
	 */
	private static function merge( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}
			$base[ $key ] = $value;
		}

		return $base;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Product IDs to WC_Product objects, preserving order and dropping anything
	 * unloadable.
	 *
	 * Visibility is deliberately NOT filtered here. These lists come from the
	 * Vendor Dashboard's Premium workflow, where a vendor chose the product and
	 * an admin approved it; second-guessing that with a catalogue-visibility
	 * test would put the same decision in two places.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return \WC_Product[]
	 */
	private static function hydrate( array $product_ids ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$out = array();
		foreach ( $product_ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product instanceof \WC_Product ) {
				$out[] = $product;
			}
		}

		return $out;
	}

	/**
	 * Enqueue a card template's own stylesheet and script.
	 *
	 * The engine resolves these from beside the template's PHP file, so a card
	 * registered by the Template Pack loads the Template Pack's assets. That is
	 * what makes a Template Pack update restyle these grids with no change here.
	 *
	 * @param string $card Card template slug.
	 * @return void
	 */
	private static function enqueue_card_assets( $card ) {
		$card = sanitize_key( (string) $card );
		if ( '' === $card || isset( self::$enqueued[ $card ] ) ) {
			return;
		}
		self::$enqueued[ $card ] = true;

		if ( ! class_exists( self::ENGINE_TEMPLATES ) || ! method_exists( self::ENGINE_TEMPLATES, 'get_card_assets' ) ) {
			return;
		}

		$assets = call_user_func( array( self::ENGINE_TEMPLATES, 'get_card_assets' ), $card );
		if ( ! is_array( $assets ) ) {
			return;
		}

		// Depend on the engine stylesheet only when it is actually registered,
		// so a change in the engine's registration order cannot drop our link.
		$deps = wp_style_is( 'zymarg-wcpg-frontend', 'registered' ) ? array( 'zymarg-wcpg-frontend' ) : array();

		if ( ! empty( $assets['css']['url'] ) ) {
			$handle = 'zymarg-wcpg-card-' . $card;
			if ( ! wp_style_is( $handle, 'enqueued' ) ) {
				if ( wp_style_is( $handle, 'registered' ) ) {
					wp_enqueue_style( $handle );
				} else {
					wp_enqueue_style(
						$handle,
						$assets['css']['url'],
						$deps,
						isset( $assets['css']['version'] ) ? $assets['css']['version'] : ZYMARG_SP_VERSION
					);
				}
			}
		}

		if ( ! empty( $assets['js']['url'] ) ) {
			$handle = 'zymarg-wcpg-card-' . $card . '-js';
			if ( ! wp_script_is( $handle, 'enqueued' ) ) {
				if ( wp_script_is( $handle, 'registered' ) ) {
					wp_enqueue_script( $handle );
				} else {
					wp_enqueue_script(
						$handle,
						$assets['js']['url'],
						array(),
						isset( $assets['js']['version'] ) ? $assets['js']['version'] : ZYMARG_SP_VERSION,
						true
					);
				}
			}
		}
	}
}
