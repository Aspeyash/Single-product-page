<?php
/**
 * ZYMARG Vendor Dashboard — Phase 0 (shell) + Phase 1 (signature Dashboard).
 *
 * A custom, on-brand "business operating system" for marketplace vendors that
 * sits ON TOP of Dokan Lite (free). It is intentionally a custom shell (not a
 * re-skin) so the vendor experience matches the ZYMARG design language and the
 * mobile-first architecture (the web dashboard mirrors the same data the mobile
 * app pulls from Dokan's REST API).
 *
 * How it connects:
 *   - It "takes over" the vendor dashboard page (slug: `dashboard`, the page
 *     that normally holds Dokan's [dokan-dashboard] shortcode) via a the_content
 *     filter — so vendors land on the ZYMARG shell automatically.
 *   - It also exposes a [zymarg_vendor_dashboard] shortcode for any page.
 *
 * Access:
 *   - Dokan sellers see the dashboard.
 *   - Shop managers / admins see it too (for previewing the design).
 *   - Logged-out users get a sign-in prompt; logged-in non-vendors get a
 *     friendly "become a vendor" panel.
 *
 * Data layer (Phase 1):
 *   - Best-effort from Dokan + WooCommerce with safe fallbacks, so the screen
 *     renders cleanly even on a brand-new store with no data yet. Deeper Dokan
 *     coupling (per-section CRUD) lands in later phases.
 *
 * @package ZYMARG_OS
 */

defined( 'ABSPATH' ) || exit;

/* ====================================================================== *
 * 0. DATA CACHE (Phase 1 perf work — v1.13.93)
 *
 * The 4 heavy dashboard-data functions (collect_data, orders_buckets,
 * earnings_data, analytics_data) each fire many WC / Dokan queries per call.
 * On a Pantheon dev tier with no persistent object cache, every vendor page
 * load re-computes everything from scratch (500-800 queries, ~10s wall time).
 *
 * We wrap each function in a short-lived, per-vendor transient. TTLs are
 * intentionally small (60s / 120s) so stale data self-heals; we ALSO flush
 * precisely on real state changes (order status change, product save,
 * comment change) so vendors see updates immediately after they act.
 *
 * Cache is BYPASSED when WP_DEBUG is on (so developers always see live data),
 * and overridable via the `zymarg_vd_no_cache` filter.
 * ====================================================================== */

/**
 * Whether the vendor-dashboard data cache should be bypassed for this request.
 * Defaults to bypass when WP_DEBUG is on. Filter to force on/off in dev.
 *
 * @return bool
 */
function zymarg_vd_bypass_cache() {
	$bypass = defined( 'WP_DEBUG' ) && WP_DEBUG;
	return (bool) apply_filters( 'zymarg_vd_no_cache', $bypass );
}

/**
 * Get a value from a short-lived transient cache, or compute + store it.
 *
 * @param string   $key      Transient key (already vendor-scoped by the caller).
 * @param int      $ttl      TTL in seconds.
 * @param callable $callback Producer function returning the value to cache.
 * @return mixed
 */
function zymarg_vd_cache_get_or_set( $key, $ttl, $callback ) {
	if ( zymarg_vd_bypass_cache() ) {
		return call_user_func( $callback );
	}
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}
	$value = call_user_func( $callback );
	// `false` is transient-sentinel for "miss"; never cache it.
	if ( false !== $value ) {
		set_transient( $key, $value, (int) $ttl );
	}
	return $value;
}

/**
 * Flush every cached slice for a single vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return void
 */
function zymarg_vd_cache_flush_vendor( $vendor_id ) {
	$vendor_id = (int) $vendor_id;
	if ( $vendor_id <= 0 ) {
		return;
	}
	delete_transient( 'zymarg_vd_c_dash_' . $vendor_id );
	delete_transient( 'zymarg_vd_c_ord_'  . $vendor_id );
	delete_transient( 'zymarg_vd_c_earn_' . $vendor_id );
	delete_transient( 'zymarg_vd_c_ana_'  . $vendor_id );
}

/**
 * Flush cached data for every vendor whose product is in this order.
 *
 * Uses the product's post_author to identify the vendor (Dokan convention).
 *
 * @param int $order_id WC order ID.
 * @return void
 */
function zymarg_vd_cache_flush_for_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( (int) $order_id );
	if ( ! ( $order instanceof WC_Order ) ) {
		return;
	}
	$vendor_ids = array();
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( $pid <= 0 ) {
			continue;
		}
		$vendor = (int) get_post_field( 'post_author', $pid );
		if ( $vendor > 0 ) {
			$vendor_ids[] = $vendor;
		}
	}
	foreach ( array_unique( $vendor_ids ) as $vid ) {
		zymarg_vd_cache_flush_vendor( $vid );
	}
}

/**
 * Bridge signature: woocommerce_order_status_changed passes 4 args.
 *
 * @param int      $order_id Order ID.
 * @param string   $old      Old status.
 * @param string   $new      New status.
 * @param WC_Order $order    Order object (unused).
 * @return void
 */
function zymarg_vd_cache_flush_on_order_status( $order_id, $old, $new, $order ) {
	unset( $old, $new, $order );
	zymarg_vd_cache_flush_for_order( $order_id );
}
add_action( 'woocommerce_order_status_changed', 'zymarg_vd_cache_flush_on_order_status', 10, 4 );
add_action( 'woocommerce_new_order',             'zymarg_vd_cache_flush_for_order',       10, 1 );
add_action( 'woocommerce_order_refunded',        'zymarg_vd_cache_flush_for_order',       10, 1 );

/**
 * Flush the vendor's cache when they save/update/delete one of their products.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function zymarg_vd_cache_flush_on_product_save( $post_id, $post ) {
	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	zymarg_vd_cache_flush_vendor( (int) $post->post_author );
}
add_action( 'save_post_product', 'zymarg_vd_cache_flush_on_product_save', 20, 2 );

/**
 * Flush the vendor's cache when a product is deleted or trashed.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function zymarg_vd_cache_flush_on_product_delete( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}
	zymarg_vd_cache_flush_vendor( (int) $post->post_author );
}
add_action( 'before_delete_post', 'zymarg_vd_cache_flush_on_product_delete', 20, 1 );
add_action( 'wp_trash_post',      'zymarg_vd_cache_flush_on_product_delete', 20, 1 );

/**
 * Flush the vendor's cache when a review is added / status-changed on their
 * product.
 *
 * @param int   $comment_id Comment ID.
 * @param mixed $extra      Second-arg placeholder (varies per hook).
 * @return void
 */
function zymarg_vd_cache_flush_on_comment( $comment_id, $extra = null ) {
	unset( $extra );
	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return;
	}
	$post = get_post( (int) $comment->comment_post_ID );
	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}
	zymarg_vd_cache_flush_vendor( (int) $post->post_author );
}
add_action( 'comment_post',           'zymarg_vd_cache_flush_on_comment', 20, 2 );
add_action( 'wp_set_comment_status',  'zymarg_vd_cache_flush_on_comment', 20, 2 );
add_action( 'edit_comment',           'zymarg_vd_cache_flush_on_comment', 20, 1 );
add_action( 'deleted_comment',        'zymarg_vd_cache_flush_on_comment', 20, 1 );

/* ====================================================================== *
 * 1. CONTEXT DETECTION + ACCESS
 * ====================================================================== */

/**
 * The page slug that hosts the vendor dashboard. Filterable so sites that use
 * a different slug (or a translated one) can point it elsewhere.
 *
 * @return string
 */
function zymarg_os_vendor_dashboard_slug() {
	return (string) apply_filters( 'zymarg_os_vendor_dashboard_slug', 'dashboard' );
}

/**
 * Whether the current request is the vendor dashboard context.
 *
 * True when:
 *   - Dokan reports a seller-dashboard page, OR
 *   - we're on the page whose slug matches zymarg_os_vendor_dashboard_slug(), OR
 *   - the current singular content embeds the [zymarg_vendor_dashboard] shortcode.
 *
 * @return bool
 */
function zymarg_os_is_vendor_dashboard() {
	$is = false;

	if ( function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard() ) {
		$is = true;
	}

	if ( ! $is && is_page() ) {
		$slug = zymarg_os_vendor_dashboard_slug();
		if ( is_page( $slug ) ) {
			$is = true;
		}
	}

	if ( ! $is && is_singular() ) {
		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'zymarg_vendor_dashboard' ) ) {
			$is = true;
		}
	}

	return (bool) apply_filters( 'zymarg_os_is_vendor_dashboard', $is );
}

/**
 * Whether the given user is a marketplace vendor/seller.
 *
 * @param int $user_id Optional user ID (defaults to current).
 * @return bool
 */
function zymarg_os_user_is_vendor( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	// Dokan's own check first.
	if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $user_id ) ) {
		return true;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	// Common multivendor seller roles / Dokan capability.
	$roles = (array) $user->roles;
	if ( array_intersect( $roles, array( 'seller', 'vendor', 'dc_vendor', 'wcfm_vendor' ) ) ) {
		return true;
	}
	if ( user_can( $user, 'dokandar' ) ) {
		return true;
	}

	return (bool) apply_filters( 'zymarg_os_user_is_vendor', false, $user_id );
}

/**
 * Whether the current user may view the dashboard (vendor OR a previewing
 * admin / shop manager).
 *
 * @return bool
 */
function zymarg_os_can_view_vendor_dashboard() {
	if ( zymarg_os_user_is_vendor() ) {
		return true;
	}
	if ( function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( get_current_user_id() ) ) {
		return true;
	}
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/* ====================================================================== *
 * 2. TAKEOVER + SHORTCODE
 * ====================================================================== */

/**
 * Take over the vendor dashboard page content with the ZYMARG shell.
 *
 * Runs early on the main query's the_content. Returns our rendered dashboard
 * instead of Dokan's default markup. We only swap in the main loop / main query
 * to avoid hijacking widgets or secondary loops.
 *
 * @param string $content Original content.
 * @return string
 */
function zymarg_os_vendor_dashboard_takeover( $content ) {
	if ( ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	if ( ! zymarg_os_is_vendor_dashboard() ) {
		return $content;
	}

	// If the content already contains our shortcode, let the shortcode render it
	// (avoid double output).
	if ( has_shortcode( (string) $content, 'zymarg_vendor_dashboard' ) ) {
		return $content;
	}

	// Prevent WordPress's wpautop() from injecting empty <p> tags between our
	// grid children — those <p> elements become grid items and break the layout.
	remove_filter( 'the_content', 'wpautop' );

	return zymarg_os_render_vendor_dashboard();
}
add_filter( 'the_content', 'zymarg_os_vendor_dashboard_takeover', 9 );

/**
 * Shortcode: [zymarg_vendor_dashboard]
 *
 * @return string
 */
function zymarg_os_vendor_dashboard_shortcode() {
	remove_filter( 'the_content', 'wpautop' );
	return zymarg_os_render_vendor_dashboard();
}
add_shortcode( 'zymarg_vendor_dashboard', 'zymarg_os_vendor_dashboard_shortcode' );

/**
 * Body classes for the vendor dashboard (so the full-bleed layout + theme
 * tweaks apply).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function zymarg_os_vendor_body_classes( $classes ) {
	if ( zymarg_os_is_vendor_dashboard() ) {
		$classes[] = 'zymarg-vendor-active';
	}
	return $classes;
}
add_filter( 'body_class', 'zymarg_os_vendor_body_classes' );

/**
 * Hide the redundant page title on the dashboard (our header handles context).
 *
 * @param bool $show Whether to show the page title.
 * @return bool
 */
function zymarg_os_vendor_hide_page_title( $show ) {
	if ( zymarg_os_is_vendor_dashboard() ) {
		return false;
	}
	return $show;
}
add_filter( 'zymarg_os_show_page_title', 'zymarg_os_vendor_hide_page_title' );

/**
 * Force full-bleed layout from the first paint (no reflow / "dancing").
 *
 * @return void
 */
function zymarg_os_vendor_critical_css() {
	if ( ! zymarg_os_is_vendor_dashboard() ) {
		return;
	}
	?>
	<style id="zymarg-vendor-critical">
	body.zymarg-vendor-active .zymarg-container,
	body.zymarg-vendor-active .zymarg-site-content,
	body.zymarg-vendor-active .zymarg-entry,
	body.zymarg-vendor-active .zymarg-entry__content,
	body.zymarg-vendor-active .zymarg-main,
	body.zymarg-vendor-active .zymarg-woo-main,
	body.zymarg-vendor-active #content {
		max-width: none !important;
		width: 100% !important;
		padding: 0 !important;
		margin: 0 !important;
	}
	</style>
	<?php
}
add_action( 'wp_head', 'zymarg_os_vendor_critical_css', 1 );

/* ====================================================================== *
 * 3. ASSETS
 * ====================================================================== */

/**
 * Enqueue the vendor dashboard stylesheet + script in context.
 *
 * @return void
 */
function zymarg_os_vendor_assets() {
	if ( ! zymarg_os_is_vendor_dashboard() && ! zymarg_os_buyer_messages_context() && ! zymarg_os_contact_seller_context() ) {
		return;
	}
	$ver = ZYMARG_VD_VERSION;

	wp_enqueue_style(
		'zymarg-os-vendor-dashboard',
		ZYMARG_VD_URL . 'assets/css/vendor-dashboard.css',
		array(),
		$ver
	);

	// Use the ZYMARG OS theme's Discovery Spark mark when it is available
	// (the plugin degrades gracefully on any other theme).
	if ( wp_style_is( 'zymarg-os-discovery-spark', 'registered' ) ) {
		wp_enqueue_style( 'zymarg-os-discovery-spark' );
	}

	wp_enqueue_script(
		'zymarg-os-vendor-dashboard',
		ZYMARG_VD_URL . 'assets/js/vendor-dashboard.js',
		array(),
		$ver,
		true
	);

	// Greeting stays consistent between server and client: both use the vendor's
	// chosen timezone (Settings -> Preferences -> Timezone). Falls back to the
	// site timezone. Client uses Intl.DateTimeFormat with the same tz string,
	// so the JS won't reshuffle the PHP-rendered greeting => no visible flash.
	wp_localize_script(
		'zymarg-os-vendor-dashboard',
		'ZymargVendor',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'zymarg_vendor_action' ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restUrl'   => rest_url( 'zymarg-vd/v1/section' ),
			'tz'        => zymarg_vd_get_vendor_timezone(),
			'spa'       => array(
				'nonce'    => wp_create_nonce( 'zymarg_vd_spa' ),
				'sections' => array_values( zymarg_os_vendor_native_sections() ),
				// Branded loading state — Discovery Spark (xl) when available, else a simple spinner.
				'loading'  => '<div class="zymarg-vendor-loading" aria-live="polite" aria-busy="true">'
					. ( function_exists( 'zymarg_vd_spark' )
						? zymarg_vd_spark( array( 'size' => 'xl', 'label' => __( 'Loading…', 'zymarg-vendor-dashboard' ) ) )
						: '<span class="zymarg-vendor-loading__spinner" aria-hidden="true"></span>' )
					. '</div>',
			),
			'greet'   => array(
				'morning'   => __( 'Good morning', 'zymarg-vendor-dashboard' ),
				'afternoon' => __( 'Good afternoon', 'zymarg-vendor-dashboard' ),
				'evening'   => __( 'Good evening', 'zymarg-vendor-dashboard' ),
			),
			'i18n'    => array(
				'confirmDelete'  => __( 'Move this product to trash?', 'zymarg-vendor-dashboard' ),
				'confirmCancel'  => __( 'Cancel this order?', 'zymarg-vendor-dashboard' ),
				'orderApproved'  => __( 'Order approved.', 'zymarg-vendor-dashboard' ),
				'orderShipped'   => __( 'Order marked as shipped.', 'zymarg-vendor-dashboard' ),
				'orderDelivered' => __( 'Order marked as delivered.', 'zymarg-vendor-dashboard' ),
				'orderCancelled' => __( 'Order cancelled.', 'zymarg-vendor-dashboard' ),
				'working'        => __( 'Working…', 'zymarg-vendor-dashboard' ),
				'error'          => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
				'buyerTyping'    => __( 'Buyer is typing', 'zymarg-vendor-dashboard' ),
				'sellerTyping'   => __( 'Seller is typing', 'zymarg-vendor-dashboard' ),
			),
			// Communication plugin integration.
			'commEnabled'  => defined( 'ZYMARG_COMM_API_NAMESPACE' ),
			'commApiBase'  => defined( 'ZYMARG_COMM_API_NAMESPACE' ) ? esc_url_raw( rest_url( 'zymarg-comm/v1' ) ) : '',
			'commNonce'    => wp_create_nonce( 'wp_rest' ),
		)
	);

	// Store-image uploader (crop + adaptive compress) — only on the dashboard.
	if ( zymarg_os_is_vendor_dashboard() ) {
		wp_enqueue_style(
			'zymarg-vd-store-upload',
			ZYMARG_VD_URL . 'assets/css/store-upload.css',
			array( 'zymarg-os-vendor-dashboard' ),
			$ver
		);
		wp_enqueue_script(
			'zymarg-vd-store-upload',
			ZYMARG_VD_URL . 'assets/js/store-upload.js',
			array(),
			$ver,
			true
		);
		wp_localize_script(
			'zymarg-vd-store-upload',
			'ZymargVDUpload',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'zymarg_vd_store_upload' ),
				'targetKB' => (int) apply_filters( 'zymarg_vd_upload_target_kb', 50 ),
				'maxDim'   => (int) apply_filters( 'zymarg_vd_upload_max_dim', 800 ),
				'i18n'     => array(
					'cropTitle'       => __( 'Crop your store image', 'zymarg-vendor-dashboard' ),
					'cropTitleAvatar' => __( 'Crop your store image', 'zymarg-vendor-dashboard' ),
					'cropTitleBanner' => __( 'Crop your store banner', 'zymarg-vendor-dashboard' ),
					'ratioFree'    => __( 'Free', 'zymarg-vendor-dashboard' ),
					'cancel'       => __( 'Cancel', 'zymarg-vendor-dashboard' ),
					'save'         => __( 'Save photo', 'zymarg-vendor-dashboard' ),
					'loadErr'      => __( 'Could not load that image.', 'zymarg-vendor-dashboard' ),
					'uploadFail'   => __( 'Upload failed. Please try again.', 'zymarg-vendor-dashboard' ),
					'confirmRemove' => __( 'Remove this image? You can upload a new one anytime.', 'zymarg-vendor-dashboard' ),
					'removeError'  => __( 'Could not remove that image. Please try again.', 'zymarg-vendor-dashboard' ),
				),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'zymarg_os_vendor_assets', 20 );

/**
 * Let add-on modules enqueue their own assets in the same vendor-dashboard
 * context (after the core stylesheet, so the cascade order is correct).
 *
 * @return void
 */
function zymarg_os_vendor_addon_assets() {
	if ( ! zymarg_os_is_vendor_dashboard() ) {
		return;
	}

	// NOTE (Phase 6): Comm plugin asset enqueueing has been removed from here.
	// The Communication plugin's VendorInbox class now hooks into
	// 'zymarg_os_vendor_enqueue_assets' (fired below) and enqueues
	// zymarg-inbox + zymarg-live-chat + zymargLiveChat config itself.

	/**
	 * Fires when vendor-dashboard assets load, for add-on modules.
	 *
	 * @param string $ver Plugin version (cache-buster).\
	 */
	do_action( 'zymarg_os_vendor_enqueue_assets', ZYMARG_VD_VERSION );
}
add_action( 'wp_enqueue_scripts', 'zymarg_os_vendor_addon_assets', 21 );

/* ==========================================================================
 * Phase 6 — Unread Count in Buyer Account Nav                 v1.35.0
 *
 * Adds a "Messages" item to the WooCommerce My Account navigation and drives
 * a live unread-count badge via the Communication plugin REST API (same 8-second
 * polling pattern as the vendor-side initMessagesComm() background poll).
 *
 * Files touched: includes/vendor-dashboard.php, assets/css/vendor-dashboard.css
 * ========================================================================== */

/**
 * Returns the URL of the page that hosts the [zymarg_my_messages] shortcode.
 *
 * Priority order:
 *   1. URL stored in the Store Page plugin's settings (zymarg_sp_options → inbox_url).
 *   2. First published page found whose post_content contains the shortcode tag.
 *
 * Result is cached in a static variable so the DB query runs at most once per
 * request. Returns an empty string when no page can be located.
 *
 * @return string Absolute URL or empty string.
 */
function zymarg_vd_get_buyer_inbox_url() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	// 1. Store Page plugin setting — the admin-configured value is most reliable.
	$opts = get_option( 'zymarg_sp_options', array() );
	if ( ! empty( $opts['inbox_url'] ) ) {
		$cached = esc_url_raw( trim( $opts['inbox_url'] ) );
		return $cached;
	}

	// 2. DB lookup: find the published page containing the shortcode.
	global $wpdb;
	$like    = '%' . $wpdb->esc_like( 'zymarg_my_messages' ) . '%';
	$page_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type   = 'page'
			   AND post_content LIKE %s
			 LIMIT 1",
			$like
		)
	);

	$cached = $page_id ? (string) get_permalink( (int) $page_id ) : '';
	return $cached;
}

/**
 * Inject a "Messages" link into the WooCommerce My Account navigation.
 *
 * Only added when:
 *   - The Communication plugin is active (ZYMARG_COMM_API_NAMESPACE is defined).
 *   - An inbox page URL can be resolved by zymarg_vd_get_buyer_inbox_url().
 *
 * The item is inserted immediately after "dashboard" so it sits near the top
 * of the nav. Falls back to appending at the end when "dashboard" is absent.
 *
 * @param array<string,string> $items Existing nav items keyed by endpoint slug.
 * @return array<string,string>
 */
function zymarg_vd_buyer_nav_messages_item( $items ) {
	if ( ! defined( 'ZYMARG_COMM_API_NAMESPACE' ) ) {
		return $items;
	}
	if ( ! zymarg_vd_get_buyer_inbox_url() ) {
		return $items;
	}

	$new      = array();
	$inserted = false;
	foreach ( $items as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'dashboard' === $key && ! $inserted ) {
			$new['zymarg-messages'] = __( 'Messages', 'zymarg-vendor-dashboard' );
			$inserted = true;
		}
	}
	if ( ! $inserted ) {
		// "dashboard" was not in the list — append Messages at the end.
		$new['zymarg-messages'] = __( 'Messages', 'zymarg-vendor-dashboard' );
	}
	return $new;
}
add_filter( 'woocommerce_account_menu_items', 'zymarg_vd_buyer_nav_messages_item', 10 );

/**
 * Override the URL WooCommerce generates for the 'zymarg-messages' endpoint
 * so it links to the buyer inbox page rather than a /my-account/zymarg-messages/
 * sub-page (which would require a WC rewrite endpoint we don't register).
 *
 * @param string $url       The URL WC built for this endpoint.
 * @param string $endpoint  The endpoint slug being resolved.
 * @param string $value     Endpoint value (unused).
 * @param string $permalink Base permalink (unused).
 * @return string
 */
function zymarg_vd_buyer_nav_messages_url( $url, $endpoint, $value, $permalink ) {
	if ( 'zymarg-messages' === $endpoint ) {
		$inbox = zymarg_vd_get_buyer_inbox_url();
		return $inbox ? $inbox : $url;
	}
	return $url;
}
add_filter( 'woocommerce_get_endpoint_url', 'zymarg_vd_buyer_nav_messages_url', 10, 4 );

/**
 * Enqueue the lightweight badge assets on WooCommerce account pages.
 *
 * Uses wp_add_inline_script (no extra HTTP request) to attach a small polling
 * script to a no-src script handle. The script mirrors the 8-second unread
 * poll inside initMessagesComm() but targets the buyer-facing account nav:
 *   - Polls /zymarg-comm/v1/conversations?per_page=50 every 8 s.
 *   - Sums unread_count across all conversations.
 *   - Injects / updates a <span class="zymarg-nav-badge"> inside the
 *     "Messages" nav link added by zymarg_vd_buyer_nav_messages_item().
 *   - Removes the badge when the total drops to zero.
 *
 * @return void
 */
function zymarg_vd_buyer_nav_badge_assets() {
	// Logged-in users on WC account pages only.
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	// Without the Comm plugin there is no REST endpoint to poll.
	if ( ! defined( 'ZYMARG_COMM_API_NAMESPACE' ) ) {
		return;
	}

	// Ensure the badge CSS is on the page. vendor-dashboard.css already
	// contains the Phase-6 .zymarg-nav-badge rules; the main enqueue only
	// runs on vendor/buyer-messages pages, so load it here when needed.
	if ( ! wp_style_is( 'zymarg-os-vendor-dashboard', 'enqueued' ) ) {
		wp_enqueue_style(
			'zymarg-os-vendor-dashboard',
			ZYMARG_VD_URL . 'assets/css/vendor-dashboard.css',
			array(),
			ZYMARG_VD_VERSION
		);
	}

	// Register a no-src script handle — WordPress outputs the inline <script>
	// blocks attached to it without generating a separate HTTP request.
	wp_register_script(
		'zymarg-buyer-nav-badge',
		false,  // no external src — inline blocks only.
		array(),
		ZYMARG_VD_VERSION,
		true    // load in footer.
	);
	wp_enqueue_script( 'zymarg-buyer-nav-badge' );

	// Config object injected before the poll script runs.
	$config = array(
		'commApiBase' => esc_url_raw( rest_url( 'zymarg-comm/v1' ) ),
		'restNonce'   => wp_create_nonce( 'wp_rest' ),
	);
	wp_add_inline_script(
		'zymarg-buyer-nav-badge',
		'window.ZymargBuyerNavBadge=' . wp_json_encode( $config ) . ';',
		'before'
	);

	// Badge-poll inline script.
	// phpcs:disable Squiz.Strings.ConcatenationSpacing
	$inline_js = <<<'ENDJS'
(function (cfg) {
	'use strict';
	var apiBase = (cfg.commApiBase || '').replace(/\/$/, '');
	var nonce   = cfg.restNonce   || '';
	if (!apiBase || !nonce) { return; }

	/* WC renders: <li class="...--zymarg-messages"><a href="...">Messages</a></li> */
	var NAV_SEL = '.woocommerce-MyAccount-navigation-link--zymarg-messages a';

	function getLink() {
		return document.querySelector(NAV_SEL);
	}

	function updateBadge(total) {
		var a = getLink();
		if (!a) { return; }
		var badge = a.querySelector('.zymarg-nav-badge');
		if (total > 0) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'zymarg-nav-badge';
				a.appendChild(badge);
			}
			var label = total > 99 ? '99+' : String(total);
			badge.textContent = label;
			badge.setAttribute('aria-label', label + ' unread messages');
		} else if (badge) {
			badge.remove();
		}
	}

	function poll() {
		fetch(apiBase + '/conversations?per_page=50', {
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		})
		.then(function (r) { return r.ok ? r.json() : null; })
		.then(function (payload) {
			if (!payload) { return; }
			var convs = Array.isArray(payload) ? payload : (payload.data || []);
			var total = 0;
			convs.forEach(function (c) { total += (c.unread_count || 0); });
			updateBadge(total);
		})
		.catch(function () { /* network hiccup — next tick retries */ });
	}

	/* Run once when DOM is ready, then every 8 s — matches vendor-side cadence. */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			poll();
			setInterval(poll, 8000);
		});
	} else {
		poll();
		setInterval(poll, 8000);
	}
}(window.ZymargBuyerNavBadge || {}));
ENDJS;
	// phpcs:enable

	wp_add_inline_script( 'zymarg-buyer-nav-badge', $inline_js );
}
add_action( 'wp_enqueue_scripts', 'zymarg_vd_buyer_nav_badge_assets', 22 );

/* -- end Phase 6 ---------------------------------------------------------- */

/**
 * Dequeue Dokan's default dashboard-home scripts on OUR takeover page.
 *
 * Dokan Lite's vendor dashboard uses a customizable/draggable widget grid
 * (customizable-dashboard.js — a gridstack/masonry layout). On a full page
 * load it repositions OUR shell's cards into a broken masonry layout. We
 * replace Dokan's dashboard entirely, so these scripts are unnecessary here.
 * Scoped strictly to zymarg_os_is_vendor_dashboard() so Dokan's own pages
 * (product edit, withdraw, etc.) are untouched. Matched by src path (not
 * handle) so it survives Dokan handle renames. Runs at priority 100 so it
 * fires AFTER Dokan enqueues.
 */
function zymarg_vd_dequeue_dokan_dashboard_assets() {
	if ( ! function_exists( 'zymarg_os_is_vendor_dashboard' ) || ! zymarg_os_is_vendor_dashboard() ) {
		return;
	}
	$needles = (array) apply_filters(
		'zymarg_vd_dokan_dequeue_paths',
		array(
			'dokan-lite/assets/js/customizable-dashboard',
			'dokan-lite/assets/js/dashboard.js',
			'dokan-lite/assets/js/dashboard-charts',
			'dokan-lite/assets/js/store-performance',
			'dokan-lite/assets/js/vendor-dashboard/reports',
		)
	);

	if ( isset( $GLOBALS['wp_scripts'] ) && $GLOBALS['wp_scripts'] instanceof WP_Scripts ) {
		foreach ( (array) $GLOBALS['wp_scripts']->registered as $handle => $script ) {
			if ( empty( $script->src ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $script->src, $needle ) ) {
					wp_dequeue_script( $handle );
					break;
				}
			}
		}
	}

	if ( isset( $GLOBALS['wp_styles'] ) && $GLOBALS['wp_styles'] instanceof WP_Styles ) {
		foreach ( (array) $GLOBALS['wp_styles']->registered as $handle => $style ) {
			if ( empty( $style->src ) ) {
				continue;
			}
			if ( false !== strpos( $style->src, 'dokan-lite/assets/js/vendor-dashboard/reports' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'zymarg_vd_dequeue_dokan_dashboard_assets', 100 );

/**
 * Per-target storage config for the store-image uploader.
 *
 * v1.33.0: generalized from avatar-only to also cover Section 5 "Store
 * Profile"'s banner, so both share one crop+compress+upload pipeline
 * (store-upload.js) instead of the banner using WordPress's admin Media
 * Library picker (wrong tool for a vendor-facing, mobile-friendly upload).
 *
 * Each target defines: its own plugin-side meta-key pair (so avatar and
 * banner never collide or overwrite each other), which `dokan_profile_settings`
 * key it mirrors into (so the public Dokan store page picks it up), and the
 * WP image size used when resolving a URL for that attachment.
 *
 * @param string $target 'avatar' or 'banner'.
 * @return array{meta_id:string,meta_url:string,profile_key:string,image_size:string}|null
 */
function zymarg_vd_store_upload_target_config( $target ) {
	$map = array(
		'avatar' => array(
			'meta_id'     => '_zymarg_store_avatar_id',
			'meta_url'    => '_zymarg_store_avatar_url',
			'profile_key' => 'gravatar',
			'image_size'  => 'thumbnail',
		),
		'banner' => array(
			'meta_id'     => '_zymarg_store_banner_id',
			'meta_url'    => '_zymarg_store_banner_url',
			'profile_key' => 'banner',
			'image_size'  => 'large',
		),
	);
	return isset( $map[ $target ] ) ? $map[ $target ] : null;
}

/**
 * AJAX: receive a cropped + compressed store image (avatar OR banner — see
 * `target` in the POST body), save it as a WP attachment, cache the URL in
 * plugin meta, and wire it into Dokan's own store-profile key so the public
 * store page picks it up too.
 *
 * @return void
 */
function zymarg_vd_upload_store_image_ajax() {
	check_ajax_referer( 'zymarg_vd_store_upload', '_wpnonce' );

	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( empty( $_FILES['image']['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file received.', 'zymarg-vendor-dashboard' ) ) );
	}

	$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'avatar';
	$cfg    = zymarg_vd_store_upload_target_config( $target );
	if ( ! $cfg ) {
		wp_send_json_error( array( 'message' => __( 'Unknown upload target.', 'zymarg-vendor-dashboard' ) ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$user_id = get_current_user_id();

	// Best-effort cleanup of the previous plugin-owned image FOR THIS TARGET
	// ONLY, so the media library doesn't accumulate duplicates and the
	// avatar/banner attachments never interfere with each other.
	$old_id = (int) get_user_meta( $user_id, $cfg['meta_id'], true );
	if ( $old_id ) {
		wp_delete_attachment( $old_id, true );
	}

	// Allow our compressed types even if the site usually wouldn't.
	add_filter( 'upload_mimes', 'zymarg_vd_allow_store_upload_mimes', 20 );
	$attachment_id = media_handle_upload( 'image', 0 );
	remove_filter( 'upload_mimes', 'zymarg_vd_allow_store_upload_mimes', 20 );

	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
	}

	$url = wp_get_attachment_image_url( $attachment_id, $cfg['image_size'] );
	if ( ! $url ) {
		$url = wp_get_attachment_url( $attachment_id );
	}
	if ( ! $url ) {
		wp_send_json_error( array( 'message' => __( 'Could not finalize the upload.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Plugin-side cache for fast rendering and non-Dokan setups.
	update_user_meta( $user_id, $cfg['meta_id'], $attachment_id );
	update_user_meta( $user_id, $cfg['meta_url'], $url );

	// Wire into Dokan's own profile key (gravatar for avatar, banner for
	// banner) so the public store page reflects the new image automatically.
	$profile               = get_user_meta( $user_id, 'dokan_profile_settings', true );
	$profile               = is_array( $profile ) ? $profile : array();
	$profile[ $cfg['profile_key'] ] = (int) $attachment_id;
	update_user_meta( $user_id, 'dokan_profile_settings', $profile );

	wp_send_json_success(
		array(
			'url'     => $url,
			'target'  => $target,
			'message' => 'banner' === $target
				? __( 'Store banner updated.', 'zymarg-vendor-dashboard' )
				: __( 'Store image updated.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_upload_store_image', 'zymarg_vd_upload_store_image_ajax' );

/**
 * AJAX: remove the current vendor's store image (avatar OR banner) — deletes
 * the attachment and clears that target's plugin meta. Also clears Dokan's
 * mirrored profile key when it points to our attachment, so the public
 * store page reverts in lockstep with the dashboard.
 *
 * @return void
 */
function zymarg_vd_remove_store_image_ajax() {
	check_ajax_referer( 'zymarg_vd_store_upload', '_wpnonce' );

	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'avatar';
	$cfg    = zymarg_vd_store_upload_target_config( $target );
	if ( ! $cfg ) {
		wp_send_json_error( array( 'message' => __( 'Unknown upload target.', 'zymarg-vendor-dashboard' ) ) );
	}

	$user_id = get_current_user_id();
	$old_id  = (int) get_user_meta( $user_id, $cfg['meta_id'], true );

	if ( $old_id ) {
		wp_delete_attachment( $old_id, true );
	}

	delete_user_meta( $user_id, $cfg['meta_id'] );
	delete_user_meta( $user_id, $cfg['meta_url'] );

	// Mirror to Dokan: only clear that profile key if it pointed to our attachment.
	$profile = get_user_meta( $user_id, 'dokan_profile_settings', true );
	$profile = is_array( $profile ) ? $profile : array();
	if ( isset( $profile[ $cfg['profile_key'] ] ) && (int) $profile[ $cfg['profile_key'] ] === $old_id ) {
		$profile[ $cfg['profile_key'] ] = '';
		update_user_meta( $user_id, 'dokan_profile_settings', $profile );
	}

	wp_send_json_success(
		array(
			'target'     => $target,
			'message'    => 'banner' === $target
				? __( 'Store banner removed.', 'zymarg-vendor-dashboard' )
				: __( 'Store photo removed.', 'zymarg-vendor-dashboard' ),
			'defaultUrl' => 'avatar' === $target ? get_avatar_url( $user_id, array( 'size' => 80 ) ) : '',
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_remove_store_image', 'zymarg_vd_remove_store_image_ajax' );

/**
 * Make sure WebP + JPEG uploads from our compressor are always accepted.
 *
 * @param array $mimes Existing mime map.
 * @return array
 */
function zymarg_vd_allow_store_upload_mimes( $mimes ) {
	$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
	$mimes['webp']        = 'image/webp';
	$mimes['png']         = 'image/png';
	return $mimes;
}

/* ====================================================================== *
 * 4. NAVIGATION
 * ====================================================================== */

/**
 * Section keys that ZYMARG renders natively INSIDE the shell (vs. linking out
 * to a Dokan page). Phase 1: dashboard. Phase 2 adds products + orders.
 *
 * @return string[]
 */
function zymarg_os_vendor_native_sections() {
	return (array) apply_filters( 'zymarg_os_vendor_native_sections', array( 'dashboard', 'products', 'orders', 'earnings', 'analytics', 'promotions', 'reviews', 'messages', 'customers', 'followers', 'notifications' ) );
}

/**
 * URL of a native in-shell section (dashboard base, or ?vsection=key).
 *
 * @param string $key Section key.
 * @return string
 */
function zymarg_os_vendor_section_url( $key ) {
	$dashboard = zymarg_os_vendor_dashboard_base_url();
	if ( 'dashboard' === $key ) {
		return $dashboard;
	}
	return add_query_arg( 'vsection', rawurlencode( $key ), $dashboard );
}

/**
 * Direct URL to a Dokan dashboard endpoint (used for write flows we reuse from
 * Dokan: add/edit product, withdraw…). Falls back to the dashboard base
 * when Dokan isn't providing that endpoint.
 *
 * @param string $ep Dokan navigation endpoint.
 * @return string
 */
function zymarg_os_vendor_dokan_url( $ep ) {
	if ( '' !== $ep && function_exists( 'dokan_get_navigation_url' ) ) {
		$url = dokan_get_navigation_url( $ep );
		if ( $url ) {
			return $url;
		}
	}
	return zymarg_os_vendor_dashboard_base_url();
}

/**
 * The URL the "Withdraw" actions point to. By default this hands off to
 * Dokan's withdraw page; the native Payouts module filters this to the
 * in-shell Payouts screen when that feature is enabled.
 *
 * @return string
 */
function zymarg_os_vendor_withdraw_url() {
	return (string) apply_filters( 'zymarg_os_vendor_withdraw_url', zymarg_os_vendor_dokan_url( 'withdraw' ) );
}

/**
 * The URL the "Store Settings" / "Store Banner" actions point to. By default
 * this hands off to Dokan's store settings page; the native Store Settings
 * module filters it to the in-shell screen when enabled.
 *
 * @return string
 */
function zymarg_os_vendor_store_settings_url() {
	// v1.32.0: the standalone Store Settings screen was removed — its fields
	// (store name, banner, address, vacation) live in the Settings accordion
	// as Section 5 "Store Profile" now, so this points there instead.
	return (string) apply_filters( 'zymarg_os_vendor_store_settings_url', zymarg_os_vendor_section_url( 'settings' ) );
}

/**
 * Resolve a sidebar nav URL.
 *   - Native sections (Dashboard, Products, Orders) render in the shell.
 *   - Everything else links to Dokan's real page when available, otherwise a
 *     tasteful in-shell "coming soon" placeholder.
 *
 * @param string $key      Section key.
 * @param string $dokan_ep Dokan navigation endpoint (empty = dashboard root).
 * @return string
 */
function zymarg_os_vendor_nav_url( $key, $dokan_ep = '' ) {
	if ( in_array( $key, zymarg_os_vendor_native_sections(), true ) ) {
		return zymarg_os_vendor_section_url( $key );
	}

	if ( '' !== $dokan_ep && function_exists( 'dokan_get_navigation_url' ) ) {
		$url = dokan_get_navigation_url( $dokan_ep );
		if ( $url ) {
			return $url;
		}
	}

	return zymarg_os_vendor_section_url( $key );
}

/**
 * Base URL of the vendor dashboard page.
 *
 * @return string
 */
function zymarg_os_vendor_dashboard_base_url() {
	if ( function_exists( 'dokan_get_navigation_url' ) ) {
		$url = dokan_get_navigation_url();
		if ( $url ) {
			return $url;
		}
	}
	$page = get_page_by_path( zymarg_os_vendor_dashboard_slug() );
	if ( $page ) {
		return get_permalink( $page );
	}
	return home_url( '/' . zymarg_os_vendor_dashboard_slug() . '/' );
}

/**
 * The vendor dashboard navigation definition.
 *
 * @return array<int,array<string,string>> List of [ key, label, icon, dokan_ep ].
 */
function zymarg_os_vendor_nav_items() {
	$items = array(
		array( 'dashboard',      __( 'Dashboard', 'zymarg-vendor-dashboard' ),      'home',     '' ),
		array( 'notifications',  __( 'Notifications', 'zymarg-vendor-dashboard' ),  'bell',     '' ),
		array( 'products',       __( 'Products', 'zymarg-vendor-dashboard' ),       'box',      'products' ),
		array( 'orders',         __( 'Orders', 'zymarg-vendor-dashboard' ),         'cart',     'orders' ),
		array( 'earnings',       __( 'Earnings', 'zymarg-vendor-dashboard' ),       'wallet',   'withdraw' ),
		array( 'analytics',      __( 'Analytics', 'zymarg-vendor-dashboard' ),      'chart',    'reports' ),
		array( 'promotions',     __( 'Promotions', 'zymarg-vendor-dashboard' ),     'megaphone','coupons' ),
		array( 'reviews',        __( 'Reviews', 'zymarg-vendor-dashboard' ),        'star',     'reviews' ),
		array( 'messages',       __( 'Messages', 'zymarg-vendor-dashboard' ),       'chat',     'support' ),
		array( 'customers',      __( 'Customers', 'zymarg-vendor-dashboard' ),      'users',    '' ),
		array( 'followers',      __( 'Followers', 'zymarg-vendor-dashboard' ),      'followers', '' ),
		array( 'shipping',       __( 'Shipping', 'zymarg-vendor-dashboard' ),       'truck',    'settings/shipping' ),
		array( 'payments',       __( 'Payments', 'zymarg-vendor-dashboard' ),       'card',     'withdraw' ),
		array( 'support',        __( 'Support', 'zymarg-vendor-dashboard' ),        'headset',  'support' ),
		array( 'settings',       __( 'Settings', 'zymarg-vendor-dashboard' ),       'gear',     'settings' ),
	);

	return apply_filters( 'zymarg_os_vendor_nav_items', $items );
}

/**
 * Which nav key is currently active.
 *
 * @return string
 */
function zymarg_os_vendor_active_section() {
	$section = 'dashboard';
	if ( isset( $_GET['vsection'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$section = sanitize_key( wp_unslash( $_GET['vsection'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	} elseif ( function_exists( 'get_query_var' ) ) {
		$page = get_query_var( 'page' );
		if ( $page && is_string( $page ) ) {
			$section = sanitize_key( $page );
		}
	}

	// If the section is switched off in settings, fall back to the Dashboard.
	if ( 'dashboard' !== $section && function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( $section ) ) {
		$section = 'dashboard';
	}

	return $section;
}

/* ====================================================================== *
 * 5. SHELL RENDER
 * ====================================================================== */

/**
 * Render the whole vendor dashboard (shell + active section). This is the
 * single entry point used by both the takeover filter and the shortcode.
 *
 * @return string HTML.
 */
function zymarg_os_render_vendor_dashboard() {
	// Access gates ------------------------------------------------------
	if ( ! is_user_logged_in() ) {
		return zymarg_os_vendor_gate_login();
	}
	if ( ! zymarg_os_can_view_vendor_dashboard() ) {
		return zymarg_os_vendor_gate_become_vendor();
	}

	$user    = wp_get_current_user();
	$active  = zymarg_os_vendor_active_section();
	$is_real = zymarg_os_user_is_vendor( $user->ID );

	// Staff members are treated as real vendors for rendering.
	if ( ! $is_real && function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $user->ID ) ) {
		$is_real = true;
	}

	ob_start();
	?>
	<div class="zymarg-vendor-wrap">
		<button class="zymarg-vendor-hamburger" id="zymarg-vendor-hamburger" aria-label="<?php esc_attr_e( 'Open menu', 'zymarg-vendor-dashboard' ); ?>" aria-expanded="false">
			<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
		</button>
		<div class="zymarg-vendor-overlay" id="zymarg-vendor-overlay"></div>

		<?php echo zymarg_os_vendor_sidebar( $active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<main class="zymarg-vendor-main">
			<div class="zymarg-vendor-orb zymarg-vendor-orb--1" aria-hidden="true"></div>
			<div class="zymarg-vendor-orb zymarg-vendor-orb--2" aria-hidden="true"></div>
			<div class="zymarg-vendor-content" data-zv-content>
				<?php echo zymarg_vd_render_section_content( $active, $user, $is_real ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</main>
	</div>
	<?php echo zymarg_os_vendor_logout_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php echo zymarg_os_vendor_confirm_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the inner content of a single dashboard section.
 *
 * This is the SINGLE SOURCE OF TRUTH for section dispatch, used by BOTH the
 * full-page shell render (above) AND the SPA AJAX endpoint. Keeping it in one
 * place means the two render paths can never drift apart.
 *
 * @param string  $active  Active section key.
 * @param WP_User $user    Current user.
 * @param bool    $is_real Whether the user is a real vendor (vs admin preview).
 * @return string Section HTML.
 */
function zymarg_vd_render_section_content( $active, $user, $is_real ) {
	// STAFF PERMISSION GATE — enforced for EVERY section (native + add-on),
	// covering both the full-page render and the SPA AJAX endpoint (both call
	// this one function). Must run on the REAL logged-in user, BEFORE the
	// vendor-swap below, so direct URLs (?vsection=) and SPA fetches can't
	// bypass a staff member's granted permissions.
	if ( function_exists( 'zymarg_vd_staff_section_allowed' )
		&& function_exists( 'zymarg_vd_staff_access_denied' )
		&& ! zymarg_vd_staff_section_allowed( $active ) ) {
		return zymarg_vd_staff_access_denied();
	}

	// When staff is logged in, swap $user to the vendor so data queries
	// use the vendor's user_id (products, orders, etc.).
	if ( function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $user->ID ) ) {
		$vendor_id = zymarg_vd_staff_vendor_id( $user->ID );
		if ( $vendor_id ) {
			$vendor_user = get_userdata( $vendor_id );
			if ( $vendor_user ) {
				$user = $vendor_user;
			}
		}
	}

	ob_start();

	if ( ! $is_real ) {
		echo zymarg_os_vendor_preview_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( 'dashboard' === $active ) {
		echo zymarg_os_vendor_render_dashboard_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'products' === $active ) {
		echo zymarg_os_vendor_render_products_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'orders' === $active ) {
		echo zymarg_os_vendor_render_orders_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'earnings' === $active ) {
		echo zymarg_os_vendor_render_earnings_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'analytics' === $active ) {
		echo zymarg_os_vendor_render_analytics_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'promotions' === $active ) {
		echo zymarg_os_vendor_render_promotions_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'reviews' === $active ) {
		echo zymarg_os_vendor_render_reviews_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'messages' === $active ) {
		$ext = (string) apply_filters( 'zymarg_os_vendor_render_section', '', 'messages', $user );
		echo '' !== $ext ? $ext : zymarg_os_vendor_render_messages_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'customers' === $active ) {
		echo zymarg_os_vendor_render_customers_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'followers' === $active ) {
		echo zymarg_os_vendor_render_followers_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'notifications' === $active ) {
		echo zymarg_os_vendor_render_notifications_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'support' === $active ) {
		// v1.46.3 — Native Support section. Previously fell through to the
		// "coming soon" placeholder, which is what caused the "click Support,
		// see nothing" bug. This renders the same two-card layout as the
		// theme's My Account -> Support panel, gated by the same-shape
		// feature flags (support_contact_card, support_help_card).
		echo zymarg_vd_render_support_section( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		/**
		 * Allow add-on modules (Payouts, Store Settings, Shipping, …) to render
		 * a native in-shell section. Return a non-empty HTML string to take
		 * over; otherwise the graceful "coming soon" placeholder is shown.
		 *
		 * @param string  $html    Section HTML (empty by default).
		 * @param string  $active  Active section key.
		 * @param WP_User $user    Current user.
		 */
		$ext = (string) apply_filters( 'zymarg_os_vendor_render_section', '', $active, $user );
		echo '' !== $ext ? $ext : zymarg_os_vendor_render_placeholder_section( $active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return (string) ob_get_clean();
}

/**
 * SPA AJAX endpoint: return the HTML for a single native section.
 *
 * Powers the in-shell navigation so switching sections doesn't re-boot the
 * whole WordPress + WooCommerce + Dokan + Elementor stack (which is what made
 * every section take ~10s). The sidebar, header and footer stay put; only the
 * .zymarg-vendor-content area is swapped client-side.
 *
 * Security: nonce + capability gated. Section is validated against the native
 * sections allowlist and the feature toggles, exactly like a full-page load.
 *
 * @return void
 */
function zymarg_vd_load_section_ajax() {
	// Use the same nonce as all other vendor AJAX endpoints — this nonce is
	// already proven to work through Dokan's request interceptor because the
	// existing order-action/product-action/coupon/review handlers use it.
	if ( ! check_ajax_referer( 'zymarg_vendor_action', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'bad_nonce' ), 400 );
	}

	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'dashboard';

	// Only native (in-shell) sections may be loaded over SPA.
	$allowed = zymarg_os_vendor_native_sections();
	if ( ! in_array( $section, $allowed, true ) ) {
		$section = 'dashboard';
	}

	// Respect feature toggles — a disabled section falls back to the Dashboard,
	// mirroring zymarg_os_vendor_active_section().
	if ( 'dashboard' !== $section && function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( $section ) ) {
		$section = 'dashboard';
	}

	$user    = wp_get_current_user();
	$is_real = zymarg_os_user_is_vendor( $user->ID );

	// Staff members are treated as real vendors for rendering.
	if ( ! $is_real && function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $user->ID ) ) {
		$is_real = true;
	}

	$html    = zymarg_vd_render_section_content( $section, $user, $is_real );

	wp_send_json_success(
		array(
			'html'    => $html,
			'section' => $section,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_load_section', 'zymarg_vd_load_section_ajax' );

/**
 * REST API endpoint: alternative path to load a dashboard section.
 *
 * This exists as a fallback when admin-ajax.php is intercepted by Dokan Lite's
 * requests.js wrapper. The REST endpoint uses its own wp_rest nonce and is
 * independent of the admin-ajax pipeline.
 *
 * Route: POST /wp-json/zymarg-vd/v1/section
 */
function zymarg_vd_register_rest_routes() {
	register_rest_route(
		'zymarg-vd/v1',
		'/section',
		array(
			'methods'             => 'POST',
			'callback'            => 'zymarg_vd_rest_load_section',
			'permission_callback' => function () {
				return current_user_can( 'read' ) && zymarg_os_can_view_vendor_dashboard();
			},
		)
	);
}
add_action( 'rest_api_init', 'zymarg_vd_register_rest_routes' );

/**
 * REST callback: render a vendor dashboard section and return JSON.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response
 */
function zymarg_vd_rest_load_section( $request ) {
	$body    = $request->get_json_params();
	$section = isset( $body['section'] ) ? sanitize_key( $body['section'] ) : 'dashboard';

	// Only native (in-shell) sections may be loaded over SPA.
	$allowed = zymarg_os_vendor_native_sections();
	if ( ! in_array( $section, $allowed, true ) ) {
		$section = 'dashboard';
	}

	// Respect feature toggles.
	if ( 'dashboard' !== $section && function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( $section ) ) {
		$section = 'dashboard';
	}

	$user    = wp_get_current_user();
	$is_real = zymarg_os_user_is_vendor( $user->ID );

	// Staff members are treated as real vendors for rendering.
	if ( ! $is_real && function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $user->ID ) ) {
		$is_real = true;
	}

	$html = zymarg_vd_render_section_content( $section, $user, $is_real );

	return new WP_REST_Response(
		array(
			'html'    => $html,
			'section' => $section,
		),
		200
	);
}

/**
 * Sidebar markup.
 *
 * @param string $active Active section key.
 * @return string
 */
function zymarg_os_vendor_sidebar( $active ) {
	$user       = wp_get_current_user();
	$is_staff   = function_exists( 'zymarg_vd_is_staff' ) && zymarg_vd_is_staff( $user->ID );
	$display_id = $is_staff && function_exists( 'zymarg_vd_staff_vendor_id' )
		? zymarg_vd_staff_vendor_id( $user->ID )
		: $user->ID;
	$store_name = zymarg_os_vendor_store_name( $display_id );
	$avatar     = function_exists( 'zymarg_os_get_user_avatar_url' )
		? zymarg_os_get_user_avatar_url( $display_id, 96 )
		: get_avatar_url( $display_id, array( 'size' => 96 ) );

	ob_start();
	?>
	<aside class="zymarg-vendor-sidebar" id="zymarg-vendor-sidebar">
		<div class="zymarg-vendor-brand">
			<?php
			if ( function_exists( 'zymarg_vd_spark' ) ) {
				echo zymarg_vd_spark( array( 'size' => 'md', 'label' => 'ZYMARG' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<span class="zymarg-vendor-brand__txt">ZYMARG <em><?php esc_html_e( 'Seller', 'zymarg-vendor-dashboard' ); ?></em></span>
		</div>

		<div class="zymarg-vendor-store">
			<?php if ( ! $is_staff ) : ?>
			<a class="zymarg-vendor-store__avatarlink" href="#" title="<?php esc_attr_e( 'Change store image', 'zymarg-vendor-dashboard' ); ?>" data-zvu-toggle>
				<?php echo zymarg_os_vendor_store_avatar_html( $display_id, $store_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="zymarg-vendor-store__cam" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></span>
			</a>
			<?php else : ?>
			<span class="zymarg-vendor-store__avatarlink">
				<?php echo zymarg_os_vendor_store_avatar_html( $display_id, $store_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<?php endif; ?>
			<?php if ( ! $is_staff ) : ?>
			<div class="zymarg-vendor-store__picker" id="zvu-picker" hidden>
				<button type="button" class="zvu-picker__opt" data-zvu-change>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
					<?php esc_html_e( 'Change photo', 'zymarg-vendor-dashboard' ); ?>
				</button>
				<button type="button" class="zvu-picker__opt zvu-picker__opt--remove" data-zvu-remove>
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
					<?php esc_html_e( 'Remove photo', 'zymarg-vendor-dashboard' ); ?>
				</button>
			</div>
			<input type="file" id="zvu-file" accept="image/*" hidden>
			<?php endif; ?>
			<div class="zymarg-vendor-store__meta">
				<span class="zymarg-vendor-store__name"><?php echo esc_html( $store_name ); ?><?php echo zymarg_vd_verification_badge( $display_id, 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php if ( $is_staff ) : ?>
					<span class="zymarg-vendor-store__staff-label"><?php
						/* translators: %s: staff first name */
						printf( esc_html__( 'Staff: %s', 'zymarg-vendor-dashboard' ), esc_html( $user->first_name ? $user->first_name : $user->display_name ) );
					?></span>
				<?php else : ?>
				<a class="zymarg-vendor-store__link" href="<?php echo esc_url( zymarg_os_vendor_store_url( $display_id ) ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'View store', 'zymarg-vendor-dashboard' ); ?>
				</a>
				<?php echo zymarg_vd_vendor_verification_status( $display_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</div>

		<nav class="zymarg-vendor-nav" aria-label="<?php esc_attr_e( 'Vendor dashboard', 'zymarg-vendor-dashboard' ); ?>">
			<?php
			$native_sections = zymarg_os_vendor_native_sections();
			$has_unread_announcements = function_exists( 'zymarg_vd_has_unread_announcements' ) && zymarg_vd_has_unread_announcements( $user->ID );
			foreach ( zymarg_os_vendor_nav_items() as $item ) :
				list( $key, $label, $icon, $ep ) = $item;
				$url       = zymarg_os_vendor_nav_url( $key, $ep );
				$is_active = ( $key === $active );
				// Only native (in-shell) sections are SPA-navigable; links that
				// point out to a real Dokan page navigate normally.
				$is_spa = in_array( $key, $native_sections, true );
				?>
				<a class="zymarg-vendor-nav__link<?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-section="<?php echo esc_attr( $key ); ?>"<?php echo $is_spa ? ' data-spa="1"' : ''; ?>>
					<span class="zymarg-vendor-nav__icon"><?php echo zymarg_os_vendor_icon( $icon ); // phpcs:ignore ?></span>
					<span class="zymarg-vendor-nav__label"><?php echo esc_html( $label ); ?></span>
					<?php if ( 'notifications' === $key && $has_unread_announcements ) : ?>
						<span class="zymarg-vendor-nav__dot" aria-label="<?php esc_attr_e( 'Unread announcements', 'zymarg-vendor-dashboard' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>

			<span class="zymarg-vendor-nav__divider" role="separator"></span>

			<?php
			// Dark-mode toggle switch — provided by the ZYMARG OS theme via the
			// [zymarg_theme_switch] shortcode (since v5.8.11). Guarded so the
			// vendor sidebar still renders cleanly if the shortcode is not
			// available (e.g. an older theme version or a non-ZYMARG theme).
			if ( shortcode_exists( 'zymarg_theme_switch' ) ) :
				?>
				<div class="zymarg-vendor-nav__theme-switch" style="padding:10px 14px;">
					<?php echo do_shortcode( '[zymarg_theme_switch]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output escaped internally. ?>
				</div>
			<?php endif; ?>

			<a class="zymarg-vendor-nav__link zymarg-vendor-nav__link--logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" data-vendor-logout>
				<span class="zymarg-vendor-nav__icon"><?php echo zymarg_os_vendor_icon( 'logout' ); // phpcs:ignore ?></span>
				<span class="zymarg-vendor-nav__label"><?php esc_html_e( 'Logout', 'zymarg-vendor-dashboard' ); ?></span>
			</a>
		</nav>
	</aside>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * 6. DASHBOARD SECTION (Phase 1 — the signature screen)
 * ====================================================================== */

/**
 * Render the signature Dashboard: greeting, quick actions, stat cards,
 * revenue chart, latest orders, low stock, recent reviews.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_dashboard_section( $user ) {
	$data  = zymarg_os_vendor_collect_data( $user->ID );
	$first = $user->first_name ? $user->first_name : $user->display_name;

	ob_start();
	?>
	<?php /* v1.46.12 — --personal marks the only greeting whose <h1> is NOT
	         the plain section name (this one reads "Good morning, {first}"),
	         so the CSS rule that hides the section-name h1 on every other
	         section explicitly excludes greetings carrying this class.
	         Adding the modifier here means the timezone-aware greeting and
	         the waving-hand emoji stay visible; nothing else changes. */ ?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--personal">
		<div>
			<h1 class="zymarg-vendor-greeting__title">
				<?php
				printf(
					/* translators: 1: time-of-day greeting (e.g. Good morning), 2: vendor first name. */
					esc_html__( '%1$s, %2$s', 'zymarg-vendor-dashboard' ),
					'<span class="zymarg-vendor-greeting__time" data-zv-greeting>' . esc_html( zymarg_os_vendor_time_greeting() ) . '</span>',
					esc_html( $first )
				);
				?>
				<span class="zymarg-vendor-greeting__wave" aria-hidden="true">&#128075;</span>
			</h1>
			<p class="zymarg-vendor-greeting__sub"><?php echo esc_html( zymarg_os_vendor_subtitle_message( $data ) ); ?></p>
		</div>
	</header>

	<?php echo zymarg_os_vendor_quick_actions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<section class="zymarg-vendor-stats" aria-label="<?php esc_attr_e( 'Key metrics', 'zymarg-vendor-dashboard' ); ?>">
		<?php
		echo zymarg_os_vendor_stat_card(
			__( "Today's Sales", 'zymarg-vendor-dashboard' ),
			wp_kses_post( wc_price( $data['today_sales'] ) ),
			'wallet',
			$data['sales_delta']
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_os_vendor_stat_card(
			__( "Today's Orders", 'zymarg-vendor-dashboard' ),
			(string) (int) $data['today_orders'],
			'cart',
			null
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_os_vendor_stat_card(
			__( 'Pending Orders', 'zymarg-vendor-dashboard' ),
			(string) (int) $data['pending_orders'],
			'clock',
			null
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo zymarg_os_vendor_stat_card(
			__( 'Store Rating', 'zymarg-vendor-dashboard' ),
			$data['rating'] ? esc_html( number_format_i18n( $data['rating'], 1 ) ) : '&mdash;',
			'star',
			null
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>

	<div class="zymarg-vendor-grid">
		<section class="zymarg-vendor-card zymarg-vendor-card--chart">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Revenue', 'zymarg-vendor-dashboard' ); ?></h2>
				<span class="zymarg-vendor-card__hint"><?php esc_html_e( 'Last 7 days', 'zymarg-vendor-dashboard' ); ?></span>
			</div>
			<?php echo zymarg_os_vendor_revenue_chart( $data['revenue_series'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>

		<section class="zymarg-vendor-card zymarg-vendor-card--orders">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Latest Orders', 'zymarg-vendor-dashboard' ); ?></h2>
				<a class="zymarg-vendor-card__more" href="<?php echo esc_url( zymarg_os_vendor_nav_url( 'orders', 'orders' ) ); ?>"><?php esc_html_e( 'View all', 'zymarg-vendor-dashboard' ); ?></a>
			</div>
			<?php echo zymarg_os_vendor_latest_orders( $data['latest_orders'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>

		<section class="zymarg-vendor-card zymarg-vendor-card--stock">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Low Stock', 'zymarg-vendor-dashboard' ); ?></h2>
				<a class="zymarg-vendor-card__more" href="<?php echo esc_url( zymarg_os_vendor_nav_url( 'products', 'products' ) ); ?>"><?php esc_html_e( 'Manage', 'zymarg-vendor-dashboard' ); ?></a>
			</div>
			<?php echo zymarg_os_vendor_low_stock( $data['low_stock'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>

		<section class="zymarg-vendor-card zymarg-vendor-card--reviews">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Recent Reviews', 'zymarg-vendor-dashboard' ); ?></h2>
				<a class="zymarg-vendor-card__more" href="<?php echo esc_url( zymarg_os_vendor_nav_url( 'reviews', 'reviews' ) ); ?>"><?php esc_html_e( 'View all', 'zymarg-vendor-dashboard' ); ?></a>
			</div>
			<?php echo zymarg_os_vendor_recent_reviews( $data['recent_reviews'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Quick Actions row.
 *
 * @return string
 */
function zymarg_os_vendor_quick_actions() {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'quick_actions' ) ) {
		return '';
	}

	/*
	 * "Add Product" carries the verb because its target is the *create* URL, not
	 * the products list — matching the wording already used in the Products
	 * section header. The old "Coupon" action was dropped: it pointed at exactly
	 * the same promotions section URL as "Promotion", so the row shipped two
	 * buttons to one destination. Coupon creation itself is untouched — the
	 * "Create coupon" form lives in Promotions.
	 */
	$actions = array(
		array( __( 'Home', 'zymarg-vendor-dashboard' ),        'home',           home_url( '/' ), '' ),
		array( __( 'Add Product', 'zymarg-vendor-dashboard' ), 'plus-box',       zymarg_os_vendor_new_product_url(), 'products' ),
		array( __( 'Withdraw', 'zymarg-vendor-dashboard' ),    'plus-wallet',    zymarg_os_vendor_withdraw_url(), 'payments' ),
		array( __( 'Banner', 'zymarg-vendor-dashboard' ),      'plus-image',     zymarg_os_vendor_store_settings_url(), 'settings' ),
		array( __( 'Promotion', 'zymarg-vendor-dashboard' ),   'plus-megaphone', zymarg_os_vendor_section_url( 'promotions' ), 'promotions' ),
	);

	// Drop actions whose target feature is switched off.
	$actions = array_values(
		array_filter(
			$actions,
			function ( $a ) {
				if ( '' === $a[3] ) {
					return true; // No section gate (e.g. Home).
				}
				return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( $a[3] );
			}
		)
	);

	if ( empty( $actions ) ) {
		return '';
	}

	ob_start();
	?>
	<section class="zymarg-vendor-quick" aria-label="<?php esc_attr_e( 'Quick actions', 'zymarg-vendor-dashboard' ); ?>">
		<?php foreach ( $actions as $a ) : ?>
			<a class="zymarg-vendor-quick__btn" href="<?php echo esc_url( $a[2] ); ?>">
				<span class="zymarg-vendor-quick__icon"><?php echo zymarg_os_vendor_icon( $a[1] ); // phpcs:ignore ?></span>
				<span class="zymarg-vendor-quick__label"><?php echo esc_html( $a[0] ); ?></span>
			</a>
		<?php endforeach; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * A single stat card.
 *
 * @param string      $label Card label.
 * @param string      $value Pre-escaped value HTML.
 * @param string      $icon  Icon key.
 * @param float|null  $delta Optional percentage delta vs previous period.
 * @return string
 */
function zymarg_os_vendor_stat_card( $label, $value, $icon, $delta = null ) {
	$delta_html = '';
	if ( null !== $delta ) {
		$dir   = $delta >= 0 ? 'up' : 'down';
		$arrow = $delta >= 0 ? '&#9650;' : '&#9660;';
		$delta_html = sprintf(
			'<span class="zymarg-vendor-stat__delta zymarg-vendor-stat__delta--%1$s">%2$s %3$s%%</span>',
			esc_attr( $dir ),
			$arrow,
			esc_html( number_format_i18n( abs( $delta ), 1 ) )
		);
	}

	return sprintf(
		'<div class="zymarg-vendor-stat">
			<span class="zymarg-vendor-stat__icon">%1$s</span>
			<div class="zymarg-vendor-stat__body">
				<span class="zymarg-vendor-stat__label">%2$s</span>
				<span class="zymarg-vendor-stat__value">%3$s</span>
				%4$s
			</div>
		</div>',
		zymarg_os_vendor_icon( $icon ),
		esc_html( $label ),
		$value, // already escaped by caller
		$delta_html
	);
}

/**
 * Lightweight inline-SVG revenue chart (no JS library). Renders a smooth area
 * line over the 7-day series.
 *
 * @param array $series List of [ 'label' => 'Mon', 'value' => float ].
 * @return string
 */
function zymarg_os_vendor_revenue_chart( $series ) {
	$series = array_values( (array) $series );
	$n      = count( $series );
	if ( $n < 2 ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'Not enough data yet — your revenue trend will appear here.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$w   = 640;
	$h   = 220;
	$pad = 24;
	$max = 0.0;
	foreach ( $series as $pt ) {
		$max = max( $max, (float) $pt['value'] );
	}
	if ( $max <= 0 ) {
		$max = 1; // avoid divide-by-zero; flat line at baseline.
	}

	$step   = ( $w - $pad * 2 ) / ( $n - 1 );
	$points = array();
	foreach ( $series as $i => $pt ) {
		$x = $pad + $i * $step;
		$y = $h - $pad - ( ( (float) $pt['value'] / $max ) * ( $h - $pad * 2 ) );
		$points[] = array( round( $x, 1 ), round( $y, 1 ) );
	}

	$line = '';
	foreach ( $points as $i => $p ) {
		$line .= ( 0 === $i ? 'M' : 'L' ) . $p[0] . ' ' . $p[1] . ' ';
	}
	$area = $line . 'L' . $points[ $n - 1 ][0] . ' ' . ( $h - $pad ) . ' L' . $points[0][0] . ' ' . ( $h - $pad ) . ' Z';

	$dots = '';
	$labels = '';
	foreach ( $points as $i => $p ) {
		$dots .= sprintf( '<circle cx="%1$s" cy="%2$s" r="3.5" class="zymarg-vendor-chart__dot"><title>%3$s</title></circle>', $p[0], $p[1], esc_attr( wp_strip_all_tags( wc_price( $series[ $i ]['value'] ) ) ) );
		$lbl = isset( $series[ $i ]['label'] ) ? (string) $series[ $i ]['label'] : '';
		if ( '' !== $lbl ) {
			$labels .= sprintf( '<text x="%1$s" y="%2$s" class="zymarg-vendor-chart__xlabel">%3$s</text>', $p[0], $h - 4, esc_html( $lbl ) );
		}
	}

	return sprintf(
		'<div class="zymarg-vendor-chart">
			<svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%7$s">
				<defs>
					<linearGradient id="zymargVendorArea" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%%" stop-color="var(--color-primary)" stop-opacity="0.28"/>
						<stop offset="100%%" stop-color="var(--color-primary)" stop-opacity="0"/>
					</linearGradient>
				</defs>
				<path d="%3$s" class="zymarg-vendor-chart__area" fill="url(#zymargVendorArea)"/>
				<path d="%4$s" class="zymarg-vendor-chart__line" fill="none"/>
				%5$s
				%6$s
			</svg>
		</div>',
		$w,
		$h,
		esc_attr( $area ),
		esc_attr( trim( $line ) ),
		$dots,
		$labels,
		esc_attr__( '7-day revenue trend', 'zymarg-vendor-dashboard' )
	);
}

/**
 * Latest orders list.
 *
 * @param array $orders List of order summary arrays.
 * @return string
 */
function zymarg_os_vendor_latest_orders( $orders ) {
	if ( empty( $orders ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No orders yet. Your most recent orders will show up here.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$rows = '';
	foreach ( $orders as $o ) {
		$rows .= sprintf(
			'<li class="zymarg-vendor-order">
				<span class="zymarg-vendor-order__id">#%1$s</span>
				<span class="zymarg-vendor-order__cust">%2$s</span>
				<span class="zymarg-vendor-order__total">%3$s</span>
				<span class="zymarg-vendor-order__status zymarg-vendor-order__status--%4$s">%5$s</span>
			</li>',
			esc_html( $o['number'] ),
			esc_html( $o['customer'] ),
			wp_kses_post( wc_price( $o['total'] ) ),
			esc_attr( $o['status_key'] ),
			esc_html( $o['status_label'] )
		);
	}

	return '<ul class="zymarg-vendor-orders-list">' . $rows . '</ul>'; // phpcs:ignore
}

/**
 * Low-stock list.
 *
 * @param array $items List of [ 'name', 'stock', 'edit' ].
 * @return string
 */
function zymarg_os_vendor_low_stock( $items ) {
	if ( empty( $items ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'All good — nothing is running low.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$rows = '';
	foreach ( $items as $it ) {
		$rows .= sprintf(
			'<li class="zymarg-vendor-stock">
				<a class="zymarg-vendor-stock__name" href="%1$s">%2$s</a>
				<span class="zymarg-vendor-stock__qty">%3$s %4$s</span>
			</li>',
			esc_url( $it['edit'] ),
			esc_html( $it['name'] ),
			esc_html( number_format_i18n( (int) $it['stock'] ) ),
			esc_html__( 'left', 'zymarg-vendor-dashboard' )
		);
	}

	return '<ul class="zymarg-vendor-stock-list">' . $rows . '</ul>'; // phpcs:ignore
}

/**
 * Recent reviews list.
 *
 * @param array $reviews List of [ 'author', 'rating', 'text', 'product' ].
 * @return string
 */
function zymarg_os_vendor_recent_reviews( $reviews ) {
	if ( empty( $reviews ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No reviews yet. Buyer feedback will appear here.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$rows = '';
	foreach ( $reviews as $r ) {
		$stars = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$stars .= '<span class="zymarg-vendor-star' . ( $i <= (int) $r['rating'] ? ' is-on' : '' ) . '">&#9733;</span>';
		}
		$rows .= sprintf(
			'<li class="zymarg-vendor-review">
				<div class="zymarg-vendor-review__top">
					<span class="zymarg-vendor-review__author">%1$s</span>
					<span class="zymarg-vendor-review__stars">%2$s</span>
				</div>
				<p class="zymarg-vendor-review__text">%3$s</p>
				<span class="zymarg-vendor-review__product">%4$s</span>
			</li>',
			esc_html( $r['author'] ),
			$stars, // static markup
			esc_html( wp_trim_words( $r['text'], 22 ) ),
			esc_html( $r['product'] )
		);
	}

	return '<ul class="zymarg-vendor-reviews-list">' . $rows . '</ul>'; // phpcs:ignore
}

/* ====================================================================== *
 * 6b. PRODUCTS SECTION (Phase 2 — card grid + actions)
 * ====================================================================== */

/**
 * Render the Products section: a card grid (no ugly tables) with per-product
 * metrics and an actions menu (Edit, Feature, Hide, Duplicate, Delete).
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_products_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$paged     = isset( $_GET['vpage'] ) ? max( 1, absint( $_GET['vpage'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$search    = isset( $_GET['vsearch'] ) ? sanitize_text_field( wp_unslash( $_GET['vsearch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$add_url   = zymarg_os_vendor_new_product_url();
	$base_url  = zymarg_os_vendor_section_url( 'products' );

	$q = zymarg_os_vendor_query_products( $user->ID, $is_vendor, $paged, $search );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--row">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Products', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub">
				<?php
				/* translators: %d: number of products. */
				printf( esc_html( _n( '%d product in your store.', '%d products in your store.', (int) $q->found_posts, 'zymarg-vendor-dashboard' ) ), (int) $q->found_posts );
				?>
			</p>
		</div>
		<a class="zymarg-vendor-cta" href="<?php echo esc_url( $add_url ); ?>">
			<?php echo zymarg_os_vendor_icon( 'plus-box' ); // phpcs:ignore ?>
			<span><?php esc_html_e( 'Add Product', 'zymarg-vendor-dashboard' ); ?></span>
		</a>
	</header>

	<form class="zymarg-vendor-toolbar" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<input type="hidden" name="vsection" value="products">
		<div class="zymarg-vendor-search">
			<?php echo zymarg_os_vendor_icon( 'lifebuoy' ); // simple decorative; replaced via CSS ?>
			<input type="search" name="vsearch" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search your products…', 'zymarg-vendor-dashboard' ); ?>">
		</div>
		<button type="submit" class="zymarg-vendor-toolbar__btn"><?php esc_html_e( 'Search', 'zymarg-vendor-dashboard' ); ?></button>
		<?php if ( '' !== $search ) : ?>
			<a class="zymarg-vendor-toolbar__clear" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'zymarg-vendor-dashboard' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( ! $q->have_posts() ) : ?>
		<div class="zymarg-vendor-card zymarg-vendor-soon">
			<?php echo zymarg_os_vendor_icon( 'box' ); // phpcs:ignore ?>
			<h2><?php esc_html_e( 'No products yet', 'zymarg-vendor-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'Add your first product to start selling.', 'zymarg-vendor-dashboard' ); ?></p>
			<a class="zymarg-vendor-soon__btn" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Product', 'zymarg-vendor-dashboard' ); ?></a>
		</div>
	<?php else : ?>
		<div class="zymarg-vp-grid">
			<?php
			while ( $q->have_posts() ) {
				$q->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					echo zymarg_os_vendor_product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			wp_reset_postdata();
			?>
		</div>

		<?php
		if ( $q->max_num_pages > 1 ) {
			echo '<nav class="zymarg-vendor-pager">';
			if ( $paged > 1 ) {
				echo '<a class="zymarg-vendor-pager__btn" href="' . esc_url( add_query_arg( 'vpage', $paged - 1, $base_url ) ) . '">' . esc_html__( 'Previous', 'zymarg-vendor-dashboard' ) . '</a>';
			}
			echo '<span class="zymarg-vendor-pager__info">' . sprintf( /* translators: 1: current page 2: total pages. */ esc_html__( 'Page %1$d of %2$d', 'zymarg-vendor-dashboard' ), (int) $paged, (int) $q->max_num_pages ) . '</span>';
			if ( $paged < $q->max_num_pages ) {
				echo '<a class="zymarg-vendor-pager__btn" href="' . esc_url( add_query_arg( 'vpage', $paged + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'zymarg-vendor-dashboard' ) . '</a>';
			}
			echo '</nav>';
		}
		?>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Query the vendor's products (scoped by author for real vendors).
 *
 * @param int    $vendor_id Vendor user ID.
 * @param bool   $is_vendor Whether to scope by author.
 * @param int    $paged     Page number.
 * @param string $search    Optional search term.
 * @return WP_Query
 */
function zymarg_os_vendor_query_products( $vendor_id, $is_vendor, $paged, $search ) {
	$per_page = (int) apply_filters( 'zymarg_os_vendor_products_per_page', 24 );
	$args     = array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $is_vendor ) {
		$args['author'] = (int) $vendor_id;
	}
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	return new WP_Query( $args );
}

/**
 * Render a single product card.
 *
 * @param WC_Product $product Product object.
 * @return string
 */
function zymarg_os_vendor_product_card( $product ) {
	$id        = $product->get_id();
	$edit_url  = zymarg_os_vendor_product_edit_url( $id );
	$view_url  = get_permalink( $id );
	$img       = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'zymarg-vp-card__img', 'loading' => 'lazy' ) );
	$status    = get_post_status( $id );
	$featured  = $product->is_featured();
	$hidden    = 'hidden' === $product->get_catalog_visibility();
	$views     = zymarg_os_vendor_product_views( $id );

	// Stock display.
	if ( $product->managing_stock() ) {
		$stock_qty = (int) $product->get_stock_quantity();
		$stock_txt = sprintf( /* translators: %d: stock quantity. */ esc_html__( '%d in stock', 'zymarg-vendor-dashboard' ), $stock_qty );
		$stock_low = $stock_qty <= (int) apply_filters( 'zymarg_os_vendor_low_stock_threshold', 5 );
	} else {
		$in_stock  = $product->is_in_stock();
		$stock_txt = $in_stock ? esc_html__( 'In stock', 'zymarg-vendor-dashboard' ) : esc_html__( 'Out of stock', 'zymarg-vendor-dashboard' );
		$stock_low = ! $in_stock;
	}

	$status_label = array(
		'publish' => __( 'Live', 'zymarg-vendor-dashboard' ),
		'draft'   => __( 'Draft', 'zymarg-vendor-dashboard' ),
		'pending' => __( 'Pending', 'zymarg-vendor-dashboard' ),
		'private' => __( 'Private', 'zymarg-vendor-dashboard' ),
	);
	$st_label = isset( $status_label[ $status ] ) ? $status_label[ $status ] : ucfirst( $status );

	ob_start();
	?>
	<article class="zymarg-vp-card" data-product="<?php echo esc_attr( $id ); ?>" data-featured="<?php echo $featured ? '1' : '0'; ?>" data-hidden="<?php echo $hidden ? '1' : '0'; ?>">
		<div class="zymarg-vp-card__media">
			<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<span class="zymarg-vp-badge zymarg-vp-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $st_label ); ?></span>
			<span class="zymarg-vp-feature<?php echo $featured ? ' is-on' : ''; ?>" title="<?php esc_attr_e( 'Featured', 'zymarg-vendor-dashboard' ); ?>" aria-hidden="true">&#9733;</span>
		</div>

		<div class="zymarg-vp-card__body">
			<a class="zymarg-vp-card__name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
			<div class="zymarg-vp-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

			<div class="zymarg-vp-metrics">
				<span class="zymarg-vp-metric<?php echo $stock_low ? ' is-low' : ''; ?>">
					<em><?php echo esc_html( $stock_txt ); ?></em>
				</span>
				<span class="zymarg-vp-metric">
					<strong><?php echo esc_html( number_format_i18n( (int) $product->get_total_sales() ) ); ?></strong>
					<em><?php esc_html_e( 'sold', 'zymarg-vendor-dashboard' ); ?></em>
				</span>
				<span class="zymarg-vp-metric">
					<strong><?php echo null === $views ? '&mdash;' : esc_html( number_format_i18n( $views ) ); ?></strong>
					<em><?php esc_html_e( 'views', 'zymarg-vendor-dashboard' ); ?></em>
				</span>
			</div>
		</div>

		<div class="zymarg-vp-card__foot">
			<a class="zymarg-vp-edit" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'zymarg-vendor-dashboard' ); ?></a>
			<div class="zymarg-vp-menu">
				<button type="button" class="zymarg-vp-menu__btn" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'More actions', 'zymarg-vendor-dashboard' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
				</button>
				<div class="zymarg-vp-menu__list" hidden>
					<a class="zymarg-vp-menu__item" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'zymarg-vendor-dashboard' ); ?></a>
					<a class="zymarg-vp-menu__item" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'zymarg-vendor-dashboard' ); ?></a>
					<button type="button" class="zymarg-vp-menu__item" data-vp-action="<?php echo $featured ? 'unfeature' : 'feature'; ?>"><?php echo $featured ? esc_html__( 'Unfeature', 'zymarg-vendor-dashboard' ) : esc_html__( 'Feature', 'zymarg-vendor-dashboard' ); ?></button>
					<button type="button" class="zymarg-vp-menu__item" data-vp-action="<?php echo $hidden ? 'show' : 'hide'; ?>"><?php echo $hidden ? esc_html__( 'Unhide', 'zymarg-vendor-dashboard' ) : esc_html__( 'Hide', 'zymarg-vendor-dashboard' ); ?></button>
					<button type="button" class="zymarg-vp-menu__item" data-vp-action="duplicate"><?php esc_html_e( 'Duplicate', 'zymarg-vendor-dashboard' ); ?></button>
					<button type="button" class="zymarg-vp-menu__item zymarg-vp-menu__item--danger" data-vp-action="delete"><?php esc_html_e( 'Delete', 'zymarg-vendor-dashboard' ); ?></button>
				</div>
			</div>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Best-effort product view count (no standard WooCommerce meta exists; we read
 * a few common keys and otherwise return null so the UI shows a dash).
 *
 * @param int $product_id Product ID.
 * @return int|null
 */
function zymarg_os_vendor_product_views( $product_id ) {
	$keys = (array) apply_filters( 'zymarg_os_vendor_view_meta_keys', array( '_zymarg_views', 'zymarg_views', '_product_views_count' ) );
	foreach ( $keys as $key ) {
		$val = get_post_meta( $product_id, $key, true );
		if ( '' !== $val && null !== $val ) {
			return (int) $val;
		}
	}
	return null;
}

/* ====================================================================== *
 * 6c. ORDERS SECTION (Phase 2 — tabs)
 * ====================================================================== */

/**
 * Render the Orders section with tabs: Pending, Processing, Shipped, Delivered,
 * Cancelled, Refunds.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_orders_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$buckets   = zymarg_os_vendor_orders_buckets( $user->ID, $is_vendor );

	$tabs = array(
		'pending'    => __( 'Pending', 'zymarg-vendor-dashboard' ),
		'processing' => __( 'Processing', 'zymarg-vendor-dashboard' ),
		'shipped'    => __( 'Shipped', 'zymarg-vendor-dashboard' ),
		'delivered'  => __( 'Delivered', 'zymarg-vendor-dashboard' ),
		'cancelled'  => __( 'Cancelled', 'zymarg-vendor-dashboard' ),
		'refunds'    => __( 'Refunds', 'zymarg-vendor-dashboard' ),
	);

	// Default to the first tab that actually has orders, else "pending".
	$default = 'pending';
	foreach ( $tabs as $key => $label ) {
		if ( ! empty( $buckets[ $key ] ) ) {
			$default = $key;
			break;
		}
	}

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Orders', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Track and manage every order across its lifecycle.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-vo-tabs" role="tablist">
		<?php foreach ( $tabs as $key => $label ) :
			$count = count( $buckets[ $key ] );
			?>
			<button type="button" class="zymarg-vo-tab<?php echo $key === $default ? ' is-active' : ''; ?>" data-votab="<?php echo esc_attr( $key ); ?>" data-count="<?php echo esc_attr( number_format_i18n( $count ) ); ?>" role="tab">
				<?php echo esc_html( $label ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $tabs as $key => $label ) : ?>
		<div class="zymarg-vo-panel<?php echo $key === $default ? ' is-active' : ''; ?>" id="zymarg-vo-<?php echo esc_attr( $key ); ?>" role="tabpanel">
			<?php echo zymarg_os_vendor_orders_list( $buckets[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endforeach; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Build status buckets of the vendor's orders in one query.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array<string,array>
 */
function zymarg_os_vendor_orders_buckets( $vendor_id, $is_vendor ) {
	$vendor_id = (int) $vendor_id;
	$flag      = $is_vendor ? '1' : '0';
	return zymarg_vd_cache_get_or_set(
		'zymarg_vd_c_ord_' . $vendor_id . '_' . $flag,
		(int) apply_filters( 'zymarg_vd_cache_ttl_orders', 60 ),
		function () use ( $vendor_id, $is_vendor ) {
			return zymarg_os_vendor_orders_buckets_impl( $vendor_id, $is_vendor );
		}
	);
}

/**
 * Uncached inner producer for the Orders section bucket data. Not called
 * directly outside the cache wrapper above.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether scoping to a vendor.
 * @return array
 */
function zymarg_os_vendor_orders_buckets_impl( $vendor_id, $is_vendor ) {
	$buckets = array(
		'pending'    => array(),
		'processing' => array(),
		'shipped'    => array(),
		'delivered'  => array(),
		'cancelled'  => array(),
		'refunds'    => array(),
	);

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $buckets;
	}

	// "Shipped" statuses are filterable (courier plugins); normalise to no wc- prefix.
	$shipped_statuses = array_map(
		function ( $s ) {
			return preg_replace( '/^wc-/', '', $s );
		},
		(array) apply_filters( 'zymarg_os_shipped_statuses', array( 'wc-shipped', 'wc-in-transit' ) )
	);

	$orders = wc_get_orders(
		array(
			'limit'   => (int) apply_filters( 'zymarg_os_vendor_orders_limit', 120 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		)
	);

	foreach ( (array) $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}
		$vendor_total = zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
		if ( $vendor_total <= 0 && $is_vendor ) {
			continue;
		}

		$status  = $order->get_status();
		$created = $order->get_date_created();
		$summary = array(
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'customer'     => trim( $order->get_formatted_billing_full_name() ) ? trim( $order->get_formatted_billing_full_name() ) : __( 'Guest', 'zymarg-vendor-dashboard' ),
			'date'         => $created ? $created->date_i18n( get_option( 'date_format' ) ) : '',
			'items'        => zymarg_os_vendor_count_items( $order, $vendor_id, $is_vendor ),
			'total'        => $vendor_total,
			'status_key'   => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'view'         => zymarg_os_vendor_order_view_url( $order->get_id() ),
		);

		if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
			$buckets['pending'][] = $summary;
		} elseif ( 'processing' === $status ) {
			$buckets['processing'][] = $summary;
		} elseif ( in_array( $status, $shipped_statuses, true ) ) {
			$buckets['shipped'][] = $summary;
		} elseif ( 'completed' === $status ) {
			$buckets['delivered'][] = $summary;
		} elseif ( in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			$buckets['cancelled'][] = $summary;
		} elseif ( 'refunded' === $status ) {
			$buckets['refunds'][] = $summary;
		}
	}

	return $buckets;
}

/**
 * Count the vendor's line items in an order (all items for admin preview).
 *
 * @param WC_Order $order     Order.
 * @param int      $vendor_id Vendor user ID.
 * @param bool     $is_vendor Whether to scope to the vendor.
 * @return int
 */
function zymarg_os_vendor_count_items( $order, $vendor_id, $is_vendor ) {
	$count = 0;
	foreach ( $order->get_items() as $item ) {
		if ( $is_vendor ) {
			$author = (int) get_post_field( 'post_author', $item->get_product_id() );
			if ( $author !== (int) $vendor_id ) {
				continue;
			}
		}
		$count += (int) $item->get_quantity();
	}
	return $count;
}

/**
 * Order details URL (Dokan order view when available).
 *
 * @param int $order_id Order ID.
 * @return string
 */
function zymarg_os_vendor_order_view_url( $order_id ) {
	if ( function_exists( 'dokan_get_navigation_url' ) ) {
		$u = dokan_get_navigation_url( 'orders' );
		if ( $u ) {
			return add_query_arg( 'order_id', $order_id, $u );
		}
	}
	return '#';
}

/**
 * Render an orders list (or empty state) for a tab.
 *
 * @param array $orders List of order summaries.
 * @return string
 */
function zymarg_os_vendor_orders_list( $orders ) {
	if ( empty( $orders ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'Nothing here right now.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$rows = '';
	foreach ( $orders as $o ) {
		$view_link = '#' !== $o['view'] ? '<a class="zymarg-vo-view" href="' . esc_url( $o['view'] ) . '">' . esc_html__( 'View', 'zymarg-vendor-dashboard' ) . '</a>' : '';

		// Build action buttons based on order status.
		$action_buttons = '';
		$sk = $o['status_key'];
		if ( in_array( $sk, array( 'pending', 'on-hold' ), true ) ) {
			$action_buttons .= '<button type="button" class="zymarg-vo-action" data-vo-action="approve" data-order="' . esc_attr( $o['id'] ) . '">' . esc_html__( 'Approve', 'zymarg-vendor-dashboard' ) . '</button>';
			$action_buttons .= '<button type="button" class="zymarg-vo-action" data-vo-action="cancel" data-order="' . esc_attr( $o['id'] ) . '">' . esc_html__( 'Cancel', 'zymarg-vendor-dashboard' ) . '</button>';
		} elseif ( 'processing' === $sk ) {
			$action_buttons .= '<button type="button" class="zymarg-vo-action" data-vo-action="ship" data-order="' . esc_attr( $o['id'] ) . '">' . esc_html__( 'Ship', 'zymarg-vendor-dashboard' ) . '</button>';
		} elseif ( in_array( $sk, array( 'shipped', 'in-transit' ), true ) ) {
			$action_buttons .= '<button type="button" class="zymarg-vo-action" data-vo-action="deliver" data-order="' . esc_attr( $o['id'] ) . '">' . esc_html__( 'Delivered', 'zymarg-vendor-dashboard' ) . '</button>';
		}

		// Build the meta string: "Jun 28 · 1 item · Customer Name"
		$meta_str = $o['date'] . ' &middot; ' . sprintf(
			/* translators: %d item count. */
			_n( '%d item', '%d items', (int) $o['items'], 'zymarg-vendor-dashboard' ),
			(int) $o['items']
		) . ' &middot; ' . $o['customer'];

		$rows .= sprintf(
			'<div class="zymarg-vo-order" data-order="%1$s">
				<div class="zymarg-vo-info">
					<span class="zymarg-vo-order__id">#%2$s</span>
					<span class="zymarg-vo-sep">&middot;</span>
					<span class="zymarg-vo-order__meta">%3$s</span>
					<span class="zymarg-vo-sep">&middot;</span>
					<span class="zymarg-vo-order__total">%4$s</span>
				</div>
				<div class="zymarg-vo-btns">
					<span class="zymarg-vo-order__status zymarg-vendor-order__status--%5$s">%6$s</span>
					%7$s
					%8$s
				</div>
			</div>',
			esc_attr( $o['id'] ),
			esc_html( $o['number'] ),
			wp_kses( $meta_str, array() ),
			wp_kses_post( wc_price( $o['total'] ) ),
			esc_attr( $o['status_key'] ),
			esc_html( $o['status_label'] ),
			$view_link,
			$action_buttons
		);
	}
	return '<div class="zymarg-vo-list">' . $rows . '</div>'; // phpcs:ignore
}

/* ====================================================================== *
 * 6c-ii. ORDER ACTIONS (AJAX: approve / ship / deliver / cancel)
 * ====================================================================== */

/**
 * Handle an order action from the Orders list.
 *
 * @return void
 */
function zymarg_os_vendor_order_action_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$order_id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
	$action   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$order    = $order_id ? wc_get_order( $order_id ) : null;

	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Ownership: vendor must have items in this order, or user is admin/shop-manager.
	$user_id   = get_current_user_id();
	$is_vendor = function_exists( 'zymarg_os_user_is_vendor' ) ? zymarg_os_user_is_vendor( $user_id ) : false;
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		if ( ! function_exists( 'zymarg_os_vendor_order_total_for' ) || zymarg_os_vendor_order_total_for( $order, $user_id, $is_vendor ) <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'You can only manage your own orders.', 'zymarg-vendor-dashboard' ) ), 403 );
		}
	}

	$current_status = $order->get_status(); // e.g. 'pending', 'processing', 'on-hold'

	// Validate allowed transitions.
	$allowed = array(
		'approve' => array( 'pending', 'on-hold' ),
		'ship'    => array( 'processing' ),
		'deliver' => array( 'shipped', 'in-transit' ),
		'cancel'  => array( 'pending', 'on-hold' ),
	);

	if ( ! isset( $allowed[ $action ] ) || ! in_array( $current_status, $allowed[ $action ], true ) ) {
		wp_send_json_error( array( 'message' => __( 'This action is not available for the current order status.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Map action to new WooCommerce status.
	switch ( $action ) {
		case 'approve':
			$new_status = 'processing';
			break;
		case 'ship':
			// Use 'shipped' if the status is registered, otherwise fall back to 'completed'.
			$statuses   = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			$new_status = isset( $statuses['wc-shipped'] ) ? 'shipped' : 'completed';
			break;
		case 'deliver':
			$new_status = 'completed';
			break;
		case 'cancel':
			$new_status = 'cancelled';
			break;
		default:
			wp_send_json_error( array( 'message' => __( 'Invalid action.', 'zymarg-vendor-dashboard' ) ) );
	}

	$order->update_status( $new_status, __( 'Status updated via ZYMARG Vendor Dashboard.', 'zymarg-vendor-dashboard' ) );

	// Build a human label for the new status.
	$statuses      = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
	$status_label  = isset( $statuses[ 'wc-' . $new_status ] ) ? $statuses[ 'wc-' . $new_status ] : ucfirst( $new_status );

	wp_send_json_success( array(
		'new_status'       => $new_status,
		'new_status_label' => $status_label,
	) );
}
add_action( 'wp_ajax_zymarg_vendor_order_action', 'zymarg_os_vendor_order_action_ajax' );

/* ====================================================================== *
 * 6d. PRODUCT ACTIONS (AJAX: feature / hide / duplicate / delete)
 * ====================================================================== */

/**
 * Handle a product action from the Products card menu.
 *
 * @return void
 */
function zymarg_os_vendor_product_action_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'wc_get_product' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
	$action     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$product    = $product_id ? wc_get_product( $product_id ) : null;

	if ( ! $product ) {
		wp_send_json_error( array( 'message' => __( 'Product not found.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Ownership: the product author, or a store manager/admin.
	$author = (int) get_post_field( 'post_author', $product_id );
	if ( $author !== (int) get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You can only manage your own products.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	switch ( $action ) {
		case 'feature':
		case 'unfeature':
			$product->set_featured( 'feature' === $action );
			$product->save();
			wp_send_json_success( array( 'featured' => 'feature' === $action ) );
			break;

		case 'hide':
		case 'show':
			$product->set_catalog_visibility( 'hide' === $action ? 'hidden' : 'visible' );
			$product->save();
			wp_send_json_success( array( 'hidden' => 'hide' === $action ) );
			break;

		case 'duplicate':
			$new_id = zymarg_os_vendor_duplicate_product( $product );
			if ( $new_id ) {
				wp_send_json_success(
					array(
						'id'      => $new_id,
						'message' => __( 'Product duplicated as a draft.', 'zymarg-vendor-dashboard' ),
						'reload'  => true,
					)
				);
			}
			wp_send_json_error( array( 'message' => __( 'Could not duplicate this product.', 'zymarg-vendor-dashboard' ) ) );
			break;

		case 'delete':
			wp_trash_post( $product_id );
			wp_send_json_success( array( 'deleted' => true, 'message' => __( 'Product moved to trash.', 'zymarg-vendor-dashboard' ) ) );
			break;

		default:
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'zymarg-vendor-dashboard' ) ) );
	}
}
add_action( 'wp_ajax_zymarg_vendor_product_action', 'zymarg_os_vendor_product_action_ajax' );

/**
 * Duplicate a product using WooCommerce's own duplicator (reliable: copies
 * meta, images, variations). Returns the new product ID, or 0 on failure.
 *
 * @param WC_Product $product Product to duplicate.
 * @return int
 */
function zymarg_os_vendor_duplicate_product( $product ) {
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		$file = WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		return 0;
	}
	$dup = new WC_Admin_Duplicate_Product();
	$new = $dup->product_duplicate( $product );
	return $new ? (int) $new->get_id() : 0;
}

/* ====================================================================== *
 * 6e. EARNINGS SECTION (Phase 3)
 * ====================================================================== */

/**
 * Render the Earnings section: Today / This Week / This Month earnings plus
 * Available Balance, Withdrawn and Pending Withdrawal, an earnings trend, and a
 * Withdraw CTA that hands off to Dokan.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_earnings_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$data      = zymarg_os_vendor_earnings_data( $user->ID, $is_vendor );
	$withdraw  = zymarg_os_vendor_withdraw_url();

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--row">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Earnings', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Your income at a glance — and your balance ready to withdraw.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
		<a class="zymarg-vendor-cta" href="<?php echo esc_url( $withdraw ); ?>">
			<?php echo zymarg_os_vendor_icon( 'plus-wallet' ); // phpcs:ignore ?>
			<span><?php esc_html_e( 'Withdraw', 'zymarg-vendor-dashboard' ); ?></span>
		</a>
	</header>

	<section class="zymarg-vendor-stats zymarg-vendor-stats--3">
		<?php
		echo zymarg_os_vendor_stat_card( __( "Today's Earnings", 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $data['today'] ) ), 'wallet', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'This Week', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $data['week'] ) ), 'chart', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'This Month', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $data['month'] ) ), 'chart', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Available Balance', 'zymarg-vendor-dashboard' ), null === $data['available'] ? '&mdash;' : wp_kses_post( wc_price( $data['available'] ) ), 'card', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Withdrawn', 'zymarg-vendor-dashboard' ), null === $data['withdrawn'] ? '&mdash;' : wp_kses_post( wc_price( $data['withdrawn'] ) ), 'wallet', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Pending Withdrawal', 'zymarg-vendor-dashboard' ), null === $data['pending'] ? '&mdash;' : wp_kses_post( wc_price( $data['pending'] ) ), 'clock', null ); // phpcs:ignore
		?>
	</section>

	<section class="zymarg-vendor-card zymarg-vendor-card--chart">
		<div class="zymarg-vendor-card__head">
			<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Earnings trend', 'zymarg-vendor-dashboard' ); ?></h2>
			<span class="zymarg-vendor-card__hint"><?php esc_html_e( 'Last 30 days', 'zymarg-vendor-dashboard' ); ?></span>
		</div>
		<?php echo zymarg_os_vendor_revenue_chart( $data['series'] ); // phpcs:ignore ?>
	</section>

	<?php if ( null === $data['available'] ) : ?>
		<p class="zymarg-vendor-note"><?php esc_html_e( 'Balance, withdrawn and pending figures appear once Dokan payouts are active. The earnings totals above are calculated from your orders.', 'zymarg-vendor-dashboard' ); ?></p>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Gather earnings figures + a 30-day series.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array
 */
function zymarg_os_vendor_earnings_data( $vendor_id, $is_vendor ) {
	$vendor_id = (int) $vendor_id;
	$flag      = $is_vendor ? '1' : '0';
	return zymarg_vd_cache_get_or_set(
		'zymarg_vd_c_earn_' . $vendor_id . '_' . $flag,
		(int) apply_filters( 'zymarg_vd_cache_ttl_earnings', 120 ),
		function () use ( $vendor_id, $is_vendor ) {
			return zymarg_os_vendor_earnings_data_impl( $vendor_id, $is_vendor );
		}
	);
}

/**
 * Uncached inner producer for the Earnings section data. Not called directly
 * outside the cache wrapper above.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether scoping to a vendor.
 * @return array
 */
function zymarg_os_vendor_earnings_data_impl( $vendor_id, $is_vendor ) {
	$out = array(
		'today'     => 0.0,
		'week'      => 0.0,
		'month'     => 0.0,
		'available' => null,
		'withdrawn' => null,
		'pending'   => null,
		'series'    => zymarg_os_vendor_empty_series(),
	);

	if ( function_exists( 'wc_get_orders' ) ) {
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$after = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', $now ) );
		$orders = wc_get_orders(
			array(
				'limit'        => (int) apply_filters( 'zymarg_os_vendor_orders_limit', 300 ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>=' . $after,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'return'       => 'objects',
			)
		);

		$today_str = current_time( 'Y-m-d' );
		$this_month = current_time( 'Y-m' );
		$week_start = strtotime( '-6 days', strtotime( $today_str ) );

		// 30-day skeleton (oldest -> newest), labels only every ~5 days.
		$series_map = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$series_map[ gmdate( 'Y-m-d', strtotime( "-{$i} days", $now ) ) ] = 0.0;
		}

		foreach ( (array) $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			$amt = zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
			if ( $amt <= 0 && $is_vendor ) {
				continue;
			}
			$created = $order->get_date_created();
			$day     = $created ? $created->date( 'Y-m-d' ) : $today_str;

			if ( isset( $series_map[ $day ] ) ) {
				$series_map[ $day ] += $amt;
			}
			if ( $day === $today_str ) {
				$out['today'] += $amt;
			}
			if ( strtotime( $day ) >= $week_start ) {
				$out['week'] += $amt;
			}
			if ( 0 === strpos( $day, $this_month ) ) {
				$out['month'] += $amt;
			}
		}

		$keys  = array_keys( $series_map );
		$total = count( $keys );
		$series = array();
		foreach ( $keys as $idx => $k ) {
			// Label first, last and every 5th point.
			$show = ( 0 === $idx % 5 ) || ( $idx === $total - 1 );
			$series[] = array(
				'label' => $show ? date_i18n( 'j M', strtotime( $k ) ) : '',
				'value' => (float) $series_map[ $k ],
			);
		}
		$out['series'] = $series;
	}

	// Dokan balance / withdraw figures (when available).
	if ( $is_vendor && function_exists( 'dokan_get_seller_balance' ) ) {
		$out['available'] = (float) dokan_get_seller_balance( $vendor_id, false );

		global $wpdb;
		$table = $wpdb->prefix . 'dokan_withdraw';
		// Only query if the Dokan withdraw table exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		if ( $exists === $table ) {
			$out['withdrawn'] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE user_id = %d AND status = 1", $vendor_id ) ); // phpcs:ignore WordPress.DB
			$out['pending']   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE user_id = %d AND status = 0", $vendor_id ) ); // phpcs:ignore WordPress.DB
		}
	}

	return $out;
}

/* ====================================================================== *
 * 6f. ANALYTICS SECTION (Phase 3)
 * ====================================================================== */

/**
 * Render the Analytics section: Revenue, Orders, Visitors, Conversion + a
 * revenue chart and a Top Products list.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_analytics_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$data      = zymarg_os_vendor_analytics_data( $user->ID, $is_vendor );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Analytics', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Performance over the last 30 days.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<section class="zymarg-vendor-stats">
		<?php
		echo zymarg_os_vendor_stat_card( __( 'Revenue', 'zymarg-vendor-dashboard' ), wp_kses_post( wc_price( $data['revenue'] ) ), 'wallet', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Orders', 'zymarg-vendor-dashboard' ), (string) (int) $data['orders'], 'cart', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Visitors', 'zymarg-vendor-dashboard' ), null === $data['visitors'] ? '&mdash;' : esc_html( number_format_i18n( $data['visitors'] ) ), 'users', null ); // phpcs:ignore
		echo zymarg_os_vendor_stat_card( __( 'Conversion', 'zymarg-vendor-dashboard' ), null === $data['conversion'] ? '&mdash;' : esc_html( number_format_i18n( $data['conversion'], 1 ) . '%' ), 'chart', null ); // phpcs:ignore
		?>
	</section>

	<div class="zymarg-vendor-grid zymarg-vendor-grid--analytics">
		<section class="zymarg-vendor-card zymarg-vendor-card--chart">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Revenue', 'zymarg-vendor-dashboard' ); ?></h2>
				<span class="zymarg-vendor-card__hint"><?php esc_html_e( 'Last 30 days', 'zymarg-vendor-dashboard' ); ?></span>
			</div>
			<?php echo zymarg_os_vendor_revenue_chart( $data['series'] ); // phpcs:ignore ?>
		</section>

		<section class="zymarg-vendor-card">
			<div class="zymarg-vendor-card__head">
				<h2 class="zymarg-vendor-card__title"><?php esc_html_e( 'Top Products', 'zymarg-vendor-dashboard' ); ?></h2>
				<a class="zymarg-vendor-card__more" href="<?php echo esc_url( zymarg_os_vendor_section_url( 'products' ) ); ?>"><?php esc_html_e( 'All products', 'zymarg-vendor-dashboard' ); ?></a>
			</div>
			<?php echo zymarg_os_vendor_top_products( $data['top'] ); // phpcs:ignore ?>
		</section>
	</div>

	<?php
	$insights_active = class_exists( 'ZYMARG_Insights\\Core\\Plugin' ) || defined( 'ZYMARG_INSIGHTS_VERSION' );
	if ( null === $data['visitors'] ) :
		if ( ! $insights_active && ( ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'insights_install_prompt' ) ) ) : ?>
			<p class="zymarg-vendor-note zymarg-vendor-note--insights"><?php esc_html_e( 'Install ZYMARG Insights to see visitor and conversion data here.', 'zymarg-vendor-dashboard' ); ?></p>
		<?php endif;
	elseif ( $insights_active && ( ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'insights_attribution' ) ) ) : ?>
		<p class="zymarg-vendor-attribution"><?php
			printf(
				/* translators: %s: ZYMARG Insights link */
				esc_html__( 'Powered by %s', 'zymarg-vendor-dashboard' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-insights' ) ) . '">ZYMARG Insights</a>'
			);
		?></p>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Gather analytics figures (revenue, orders, visitors, conversion, top products).
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array
 */
function zymarg_os_vendor_analytics_data( $vendor_id, $is_vendor ) {
	$vendor_id = (int) $vendor_id;
	$flag      = $is_vendor ? '1' : '0';
	return zymarg_vd_cache_get_or_set(
		'zymarg_vd_c_ana_' . $vendor_id . '_' . $flag,
		(int) apply_filters( 'zymarg_vd_cache_ttl_analytics', 120 ),
		function () use ( $vendor_id, $is_vendor ) {
			return zymarg_os_vendor_analytics_data_impl( $vendor_id, $is_vendor );
		}
	);
}

/**
 * Uncached inner producer for the Analytics section data. Not called directly
 * outside the cache wrapper above.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether scoping to a vendor.
 * @return array
 */
function zymarg_os_vendor_analytics_data_impl( $vendor_id, $is_vendor ) {
	$out = array(
		'revenue'    => 0.0,
		'orders'     => 0,
		'visitors'   => null,
		'conversion' => null,
		'series'     => zymarg_os_vendor_empty_series(),
		'top'        => array(),
	);

	if ( function_exists( 'wc_get_orders' ) ) {
		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$after  = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', $now ) );
		$orders = wc_get_orders(
			array(
				'limit'        => (int) apply_filters( 'zymarg_os_vendor_orders_limit', 300 ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>=' . $after,
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'return'       => 'objects',
			)
		);

		$series_map = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$series_map[ gmdate( 'Y-m-d', strtotime( "-{$i} days", $now ) ) ] = 0.0;
		}

		foreach ( (array) $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			$amt = zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
			if ( $amt <= 0 && $is_vendor ) {
				continue;
			}
			$out['revenue'] += $amt;
			$out['orders']++;
			$created = $order->get_date_created();
			$day     = $created ? $created->date( 'Y-m-d' ) : current_time( 'Y-m-d' );
			if ( isset( $series_map[ $day ] ) ) {
				$series_map[ $day ] += $amt;
			}
		}

		$keys   = array_keys( $series_map );
		$total  = count( $keys );
		$series = array();
		foreach ( $keys as $idx => $k ) {
			$show     = ( 0 === $idx % 5 ) || ( $idx === $total - 1 );
			$series[] = array(
				'label' => $show ? date_i18n( 'j M', strtotime( $k ) ) : '',
				'value' => (float) $series_map[ $k ],
			);
		}
		$out['series'] = $series;
	}

	// Visitors are not tracked natively — expose a filter so any analytics
	// source can supply a 30-day visitor count.
	$visitors = apply_filters( 'zymarg_os_vendor_visitors', null, $vendor_id, 30 );
	if ( null !== $visitors && is_numeric( $visitors ) ) {
		$out['visitors']   = (int) $visitors;
		$out['conversion'] = $out['visitors'] > 0 ? round( ( $out['orders'] / $out['visitors'] ) * 100, 1 ) : 0.0;
	}

	$out['top'] = zymarg_os_vendor_top_products_data( $vendor_id, $is_vendor );

	return $out;
}

/**
 * Top products by lifetime units sold (scoped to the vendor).
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope by author.
 * @return array
 */
function zymarg_os_vendor_top_products_data( $vendor_id, $is_vendor ) {
	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'orderby'        => 'meta_value_num',
		'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'order'          => 'DESC',
	);
	if ( $is_vendor ) {
		$args['author'] = (int) $vendor_id;
	}
	$q   = new WP_Query( $args );
	$out = array();
	foreach ( $q->posts as $p ) {
		$product = wc_get_product( $p->ID );
		if ( ! $product ) {
			continue;
		}
		$out[] = array(
			'name'  => get_the_title( $p ),
			'sales' => (int) $product->get_total_sales(),
			'price' => $product->get_price_html(),
			'edit'  => zymarg_os_vendor_product_edit_url( $p->ID ),
		);
	}
	wp_reset_postdata();
	return $out;
}

/**
 * Render the Top Products list (with a relative sales bar).
 *
 * @param array $items Top product rows.
 * @return string
 */
function zymarg_os_vendor_top_products( $items ) {
	if ( empty( $items ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No sales data yet. Your best sellers will rank here.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$max = 0;
	foreach ( $items as $it ) {
		$max = max( $max, (int) $it['sales'] );
	}
	$max = $max > 0 ? $max : 1;

	$rows = '';
	$rank = 0;
	foreach ( $items as $it ) {
		$rank++;
		$pct   = (int) round( ( (int) $it['sales'] / $max ) * 100 );
		$rows .= sprintf(
			'<li class="zymarg-va-top__row">
				<span class="zymarg-va-top__rank">%1$d</span>
				<div class="zymarg-va-top__body">
					<a class="zymarg-va-top__name" href="%2$s">%3$s</a>
					<span class="zymarg-va-top__bar"><span style="width:%4$d%%"></span></span>
				</div>
				<span class="zymarg-va-top__sales">%5$s</span>
			</li>',
			$rank,
			esc_url( $it['edit'] ),
			esc_html( $it['name'] ),
			$pct,
			esc_html( sprintf( /* translators: %s units sold. */ _n( '%s sold', '%s sold', (int) $it['sales'], 'zymarg-vendor-dashboard' ), number_format_i18n( (int) $it['sales'] ) ) )
		);
	}
	return '<ul class="zymarg-va-top">' . $rows . '</ul>'; // phpcs:ignore
}

/* ====================================================================== *
 * 6g. PROMOTIONS SECTION (Phase 4 — native vendor coupons)
 * ====================================================================== */

/**
 * Render the Promotions section: a coupon creator (works on Dokan Lite) and the
 * vendor's coupon list. New coupons are auto-restricted to the vendor's own
 * products so they never discount another seller's items.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_promotions_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$coupons   = zymarg_os_vendor_query_coupons( $user->ID, $is_vendor );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Promotions', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Create coupons for your store. They apply only to your products.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-zpe-layout zymarg-vc-layout">
		<!-- Create Coupon card -->
		<div class="zymarg-zpe-card zymarg-zpe-card--left">
			<div class="zymarg-zpe-card__accent"></div>
			<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Create Coupon', 'zymarg-vendor-dashboard' ); ?></div>
			<div class="zymarg-zpe-card__body">
				<form class="zymarg-zpe-form" id="zymarg-vc-form">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Coupon code', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" name="code" required placeholder="<?php esc_attr_e( 'e.g. EID20', 'zymarg-vendor-dashboard' ); ?>">
					</label>
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Discount type', 'zymarg-vendor-dashboard' ); ?></span>
							<select name="type">
								<option value="percent"><?php esc_html_e( 'Percentage (%)', 'zymarg-vendor-dashboard' ); ?></option>
								<option value="fixed_cart"><?php esc_html_e( 'Fixed cart amount', 'zymarg-vendor-dashboard' ); ?></option>
								<option value="fixed_product"><?php esc_html_e( 'Fixed product amount', 'zymarg-vendor-dashboard' ); ?></option>
							</select>
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Amount', 'zymarg-vendor-dashboard' ); ?></span>
							<input type="number" name="amount" min="0" step="0.01" required placeholder="20">
						</label>
					</div>
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Expiry date (optional)', 'zymarg-vendor-dashboard' ); ?></span>
							<input type="date" name="expiry">
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Usage limit (optional)', 'zymarg-vendor-dashboard' ); ?></span>
							<input type="number" name="usage_limit" min="0" step="1" placeholder="<?php esc_attr_e( 'Unlimited', 'zymarg-vendor-dashboard' ); ?>">
						</label>
					</div>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Minimum spend (optional)', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="number" name="min_spend" min="0" step="0.01" placeholder="0">
					</label>
					<div class="zymarg-zpe-actions">
						<button type="submit" class="zymarg-vendor-cta zymarg-zpe-save zymarg-vc-submit">
							<?php echo zymarg_os_vendor_icon( 'plus-ticket' ); // phpcs:ignore ?>
							<span><?php esc_html_e( 'Create coupon', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
					</div>
					<p class="zymarg-vc-msg" hidden></p>
				</form>
			</div>
		</div><!-- /.zymarg-zpe-card Create Coupon -->

		<!-- Your Coupons card -->
		<div class="zymarg-zpe-card zymarg-zpe-card--right">
			<div class="zymarg-zpe-card__accent"></div>
			<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Your Coupons', 'zymarg-vendor-dashboard' ); ?></div>
			<div class="zymarg-zpe-card__body">
				<?php echo zymarg_os_vendor_coupons_list( $coupons ); // phpcs:ignore ?>
			</div>
		</div><!-- /.zymarg-zpe-card Your Coupons -->
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Query the vendor's coupons.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope by author.
 * @return WC_Coupon[]
 */
function zymarg_os_vendor_query_coupons( $vendor_id, $is_vendor ) {
	$args = array(
		'post_type'      => 'shop_coupon',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => 50,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $is_vendor ) {
		$args['author'] = (int) $vendor_id;
	}
	$posts   = get_posts( $args );
	$coupons = array();
	foreach ( $posts as $p ) {
		$coupons[] = new WC_Coupon( $p->ID );
	}
	return $coupons;
}

/**
 * Render the coupon list (or empty state).
 *
 * @param WC_Coupon[] $coupons Coupons.
 * @return string
 */
function zymarg_os_vendor_coupons_list( $coupons ) {
	if ( empty( $coupons ) ) {
		return '<div class="zymarg-vendor-empty zymarg-vendor-empty--centered">'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="color:#bd00d1;margin-bottom:12px;opacity:.7"><path d="M2 9a3 3 0 0 1 3 3 3 3 0 0 1-3 3v4h20v-4a3 3 0 0 1 0-6V5H2z"/><line x1="13" y1="9" x2="13" y2="9.01"/><line x1="13" y1="12" x2="13" y2="12.01"/><line x1="13" y1="15" x2="13" y2="15.01"/></svg>'
			. '<p>' . esc_html__( 'No coupons yet. Create your first one to drive sales.', 'zymarg-vendor-dashboard' ) . '</p>'
			. '</div>';
	}

	$rows = '';
	foreach ( $coupons as $coupon ) {
		$type   = $coupon->get_discount_type();
		$amount = ( 'percent' === $type )
			? rtrim( rtrim( (string) $coupon->get_amount(), '0' ), '.' ) . '%'
			: wp_strip_all_tags( wc_price( $coupon->get_amount() ) );
		$expiry = $coupon->get_date_expires();
		$limit  = $coupon->get_usage_limit();
		$used   = $coupon->get_usage_count();

		$meta = array();
		$meta[] = sprintf( /* translators: %s discount value. */ esc_html__( '%s off', 'zymarg-vendor-dashboard' ), $amount );
		$meta[] = $limit ? sprintf( /* translators: 1 used 2 limit. */ esc_html__( 'Used %1$d / %2$d', 'zymarg-vendor-dashboard' ), (int) $used, (int) $limit ) : sprintf( /* translators: %d used. */ esc_html__( 'Used %d', 'zymarg-vendor-dashboard' ), (int) $used );
		if ( $expiry ) {
			$meta[] = sprintf( /* translators: %s date. */ esc_html__( 'Expires %s', 'zymarg-vendor-dashboard' ), $expiry->date_i18n( get_option( 'date_format' ) ) );
		}

		$rows .= sprintf(
			'<li class="zymarg-vc-item" data-coupon="%1$d">
				<div class="zymarg-vc-item__main">
					<span class="zymarg-vc-code">%2$s</span>
					<span class="zymarg-vc-meta">%3$s</span>
				</div>
				<button type="button" class="zymarg-vc-del" data-vc-delete aria-label="%4$s">&times;</button>
			</li>',
			(int) $coupon->get_id(),
			esc_html( strtoupper( $coupon->get_code() ) ),
			esc_html( implode( ' · ', $meta ) ),
			esc_attr__( 'Delete coupon', 'zymarg-vendor-dashboard' )
		);
	}
	return '<ul class="zymarg-vc-list">' . $rows . '</ul>'; // phpcs:ignore
}

/**
 * AJAX: create a vendor coupon (restricted to the vendor's products).
 *
 * @return void
 */
function zymarg_os_vendor_create_coupon_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() || ! class_exists( 'WC_Coupon' ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();
	$is_vendor = zymarg_os_user_is_vendor( $vendor_id );

	$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
	if ( '' === $code ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a coupon code.', 'zymarg-vendor-dashboard' ) ) );
	}
	if ( wc_get_coupon_id_by_code( $code ) ) {
		wp_send_json_error( array( 'message' => __( 'That coupon code already exists.', 'zymarg-vendor-dashboard' ) ) );
	}

	$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'percent';
	$type   = in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ? $type : 'percent';
	$amount = isset( $_POST['amount'] ) ? wc_format_decimal( wp_unslash( $_POST['amount'] ) ) : 0;
	if ( (float) $amount <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Enter a discount amount greater than zero.', 'zymarg-vendor-dashboard' ) ) );
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );

	if ( ! empty( $_POST['expiry'] ) ) {
		$coupon->set_date_expires( sanitize_text_field( wp_unslash( $_POST['expiry'] ) ) );
	}
	if ( ! empty( $_POST['usage_limit'] ) ) {
		$coupon->set_usage_limit( absint( $_POST['usage_limit'] ) );
	}
	if ( ! empty( $_POST['min_spend'] ) ) {
		$coupon->set_minimum_amount( wc_format_decimal( wp_unslash( $_POST['min_spend'] ) ) );
	}

	// Restrict to this vendor's products so it never discounts other sellers.
	if ( $is_vendor ) {
		$ids = zymarg_os_vendor_product_ids( $vendor_id );
		if ( ! empty( $ids ) ) {
			$coupon->set_product_ids( $ids );
		}
	}

	$coupon->save();

	// Set the post author so the coupon belongs to the vendor.
	wp_update_post(
		array(
			'ID'          => $coupon->get_id(),
			'post_author' => $vendor_id,
		)
	);

	wp_send_json_success(
		array(
			'reload'  => true,
			'message' => __( 'Coupon created.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vendor_create_coupon', 'zymarg_os_vendor_create_coupon_ajax' );

/**
 * AJAX: delete (trash) a vendor coupon.
 *
 * @return void
 */
function zymarg_os_vendor_delete_coupon_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$coupon_id = isset( $_POST['coupon'] ) ? absint( $_POST['coupon'] ) : 0;
	if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Coupon not found.', 'zymarg-vendor-dashboard' ) ) );
	}

	$author = (int) get_post_field( 'post_author', $coupon_id );
	if ( $author !== (int) get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You can only manage your own coupons.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	wp_trash_post( $coupon_id );
	wp_send_json_success( array( 'deleted' => true ) );
}
add_action( 'wp_ajax_zymarg_vendor_delete_coupon', 'zymarg_os_vendor_delete_coupon_ajax' );

/**
 * Get the vendor's product IDs (capped, filterable).
 *
 * @param int $vendor_id Vendor user ID.
 * @return int[]
 */
function zymarg_os_vendor_product_ids( $vendor_id ) {
	$limit = (int) apply_filters( 'zymarg_os_vendor_product_ids_limit', 500 );
	$q     = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'author'         => (int) $vendor_id,
			'fields'         => 'ids',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
		)
	);
	return array_map( 'intval', (array) $q->posts );
}

/* ====================================================================== *
 * 6h. REVIEWS SECTION (Phase 4 — reply / hide / report / filter)
 * ====================================================================== */

/**
 * Render the Reviews section: rating filter tabs + a manageable list of reviews
 * on the vendor's products, each with Reply / Hide / Report actions.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_reviews_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );

	// Paged. The list used to stop dead at 40 reviews with no way to reach the
	// rest, so a seller with any real history could not see or reply to their
	// older buyers at all.
	$vr_per_page = max( 1, (int) apply_filters( 'zymarg_os_vendor_reviews_limit', 40 ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
	$vr_page  = isset( $_GET['zy_vr_page'] ) ? max( 1, (int) $_GET['zy_vr_page'] ) : 1;
	$vr_total = zymarg_os_vendor_reviews_count( $user->ID, $is_vendor );
	$vr_pages = max( 1, (int) ceil( $vr_total / $vr_per_page ) );

	if ( $vr_page > $vr_pages ) {
		$vr_page = $vr_pages;
	}

	$reviews = zymarg_os_vendor_reviews_data( $user->ID, $is_vendor, $vr_page, $vr_per_page );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Reviews', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Reply to buyers, and moderate reviews on your products.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-vr-filters">
		<?php
		$filters = array(
			'all' => __( 'All', 'zymarg-vendor-dashboard' ),
			'5'   => '5 ★',
			'4'   => '4 ★',
			'3'   => '3 ★',
			'2'   => '2 ★',
			'1'   => '1 ★',
		);
		foreach ( $filters as $key => $label ) :
			?>
			<button type="button" class="zymarg-vr-filter<?php echo 'all' === $key ? ' is-active' : ''; ?>" data-vr-filter="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( empty( $reviews ) ) : ?>
		<div class="zymarg-vendor-card zymarg-vendor-soon">
			<?php echo zymarg_os_vendor_icon( 'star' ); // phpcs:ignore ?>
			<h2><?php esc_html_e( 'No reviews yet', 'zymarg-vendor-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'When buyers review your products, they show up here for you to reply to and manage.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	<?php else : ?>
		<div class="zymarg-vr-list">
			<?php
			// Note: the star filter buttons above act on the reviews currently on
			// screen, i.e. this page of results.
			foreach ( $reviews as $r ) {
				echo zymarg_os_vendor_review_card( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php if ( $vr_pages > 1 ) : ?>
			<?php $vr_base = zymarg_os_vendor_section_url( 'reviews' ); ?>
			<nav class="zymarg-vr-pagination" aria-label="<?php esc_attr_e( 'Review pages', 'zymarg-vendor-dashboard' ); ?>">
				<?php if ( $vr_page > 1 ) : ?>
					<a class="zymarg-vr-page-link" rel="prev" href="<?php echo esc_url( add_query_arg( 'zy_vr_page', $vr_page - 1, $vr_base ) ); ?>"><?php esc_html_e( '← Newer', 'zymarg-vendor-dashboard' ); ?></a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<span class="zymarg-vr-page-status">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: current page, 2: total pages, 3: total review count. */
							__( 'Page %1$s of %2$s · %3$s reviews', 'zymarg-vendor-dashboard' ),
							number_format_i18n( $vr_page ),
							number_format_i18n( $vr_pages ),
							number_format_i18n( $vr_total )
						)
					);
					?>
				</span>
				<?php if ( $vr_page < $vr_pages ) : ?>
					<a class="zymarg-vr-page-link" rel="next" href="<?php echo esc_url( add_query_arg( 'zy_vr_page', $vr_page + 1, $vr_base ) ); ?>"><?php esc_html_e( 'Older →', 'zymarg-vendor-dashboard' ); ?></a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Build the shared review query for a vendor.
 *
 * Kept in one place so the list and its count can never disagree about which
 * reviews exist.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array|null Query args, or null when the vendor owns no products.
 */
function zymarg_os_vendor_reviews_query_args( $vendor_id, $is_vendor ) {
	$args = array(
		'type'    => 'review',
		'status'  => 'all',
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	);

	if ( $is_vendor ) {
		$ids = zymarg_os_vendor_product_ids( $vendor_id );
		if ( empty( $ids ) ) {
			return null;
		}
		$args['post__in'] = $ids;
	}

	return $args;
}

/**
 * Count every review on the vendor's products.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return int
 */
function zymarg_os_vendor_reviews_count( $vendor_id, $is_vendor ) {
	$args = zymarg_os_vendor_reviews_query_args( $vendor_id, $is_vendor );
	if ( null === $args ) {
		return 0;
	}

	$args['count'] = true;

	return (int) get_comments( $args );
}

/**
 * Fetch reviews on the vendor's products.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @param int  $page      1-based page number.
 * @param int  $per_page  Reviews per page.
 * @return array
 */
function zymarg_os_vendor_reviews_data( $vendor_id, $is_vendor, $page = 1, $per_page = 0 ) {
	$per_page = (int) $per_page > 0
		? (int) $per_page
		: max( 1, (int) apply_filters( 'zymarg_os_vendor_reviews_limit', 40 ) );
	$page     = max( 1, (int) $page );

	$args = array(
		'type'    => 'review',
		'status'  => 'all',
		'number'  => $per_page,
		'offset'  => ( $page - 1 ) * $per_page,
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	);

	if ( $is_vendor ) {
		$ids = zymarg_os_vendor_product_ids( $vendor_id );
		if ( empty( $ids ) ) {
			return array();
		}
		$args['post__in'] = $ids;
	}

	$comments = get_comments( $args );
	$out      = array();
	foreach ( (array) $comments as $c ) {
		if ( in_array( $c->comment_approved, array( 'spam', 'trash' ), true ) ) {
			continue;
		}
		$out[] = array(
			'id'       => (int) $c->comment_ID,
			'author'   => $c->comment_author ? $c->comment_author : __( 'Anonymous', 'zymarg-vendor-dashboard' ),
			'rating'   => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
			'text'     => $c->comment_content,
			'date'     => get_comment_date( get_option( 'date_format' ), $c->comment_ID ),
			'product'  => get_the_title( $c->comment_post_ID ),
			'approved' => '1' === (string) $c->comment_approved,
			// The report system stores a counter under '_zymarg_report_count'.
			// The old '_zymarg_reported' key is never written by anything.
			'reported' => ( (int) get_comment_meta( $c->comment_ID, '_zymarg_report_count', true ) ) > 0,
		);
	}
	return $out;
}

/**
 * Render one review card with action menu.
 *
 * @param array $r Review row.
 * @return string
 */
function zymarg_os_vendor_review_card( $r ) {
	$stars = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$stars .= '<span class="zymarg-vendor-star' . ( $i <= (int) $r['rating'] ? ' is-on' : '' ) . '">&#9733;</span>';
	}

	ob_start();
	?>
	<article class="zymarg-vr-card<?php echo $r['approved'] ? '' : ' is-hidden'; ?>" data-review="<?php echo esc_attr( $r['id'] ); ?>" data-rating="<?php echo esc_attr( $r['rating'] ); ?>">
		<div class="zymarg-vr-card__top">
			<div>
				<span class="zymarg-vr-author"><?php echo esc_html( $r['author'] ); ?></span>
				<span class="zymarg-vr-stars"><?php echo $stars; // phpcs:ignore ?></span>
			</div>
			<span class="zymarg-vr-date"><?php echo esc_html( $r['date'] ); ?></span>
		</div>
		<p class="zymarg-vr-text"><?php echo esc_html( $r['text'] ); ?></p>
		<div class="zymarg-vr-card__foot">
			<span class="zymarg-vr-product"><?php echo esc_html( $r['product'] ); ?></span>
			<div class="zymarg-vr-actions">
				<button type="button" class="zymarg-vr-act" data-vr-action="reply"><?php esc_html_e( 'Reply', 'zymarg-vendor-dashboard' ); ?></button>
				<button type="button" class="zymarg-vr-act" data-vr-action="<?php echo $r['approved'] ? 'hide' : 'show'; ?>"><?php echo $r['approved'] ? esc_html__( 'Hide', 'zymarg-vendor-dashboard' ) : esc_html__( 'Unhide', 'zymarg-vendor-dashboard' ); ?></button>
				<button type="button" class="zymarg-vr-act<?php echo $r['reported'] ? ' is-reported' : ''; ?>" data-vr-action="report"><?php echo $r['reported'] ? esc_html__( 'Reported', 'zymarg-vendor-dashboard' ) : esc_html__( 'Report', 'zymarg-vendor-dashboard' ); ?></button>
			</div>
		</div>
		<div class="zymarg-vr-reply" hidden>
			<textarea class="zymarg-vr-reply__text" rows="2" placeholder="<?php esc_attr_e( 'Write a public reply…', 'zymarg-vendor-dashboard' ); ?>"></textarea>
			<button type="button" class="zymarg-vendor-cta zymarg-vr-reply__send" data-vr-reply-send><?php esc_html_e( 'Send reply', 'zymarg-vendor-dashboard' ); ?></button>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * AJAX: review actions (reply / hide / show / report).
 *
 * @return void
 */
function zymarg_os_vendor_review_action_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$comment_id = isset( $_POST['review'] ) ? absint( $_POST['review'] ) : 0;
	$action     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$comment    = $comment_id ? get_comment( $comment_id ) : null;

	if ( ! $comment ) {
		wp_send_json_error( array( 'message' => __( 'Review not found.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Ownership: the reviewed product must belong to the current user.
	$product_author = (int) get_post_field( 'post_author', $comment->comment_post_ID );
	if ( $product_author !== (int) get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You can only manage reviews on your own products.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	switch ( $action ) {
		case 'reply':
			$text = isset( $_POST['text'] ) ? wp_kses_post( wp_unslash( $_POST['text'] ) ) : '';
			if ( '' === trim( $text ) ) {
				wp_send_json_error( array( 'message' => __( 'Reply cannot be empty.', 'zymarg-vendor-dashboard' ) ) );
			}
			$user = wp_get_current_user();
			$new  = wp_insert_comment(
				array(
					'comment_post_ID'      => $comment->comment_post_ID,
					'comment_parent'       => $comment_id,
					'comment_content'      => $text,
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_approved'     => 1,
					// MUST be 'review'. The Reviews Engine reads replies with
					// type => 'review'; without this the reply is stored as a
					// plain comment and never renders anywhere on the front end.
					'comment_type'         => 'review',
				)
			);
			if ( $new ) {
				// Flags the reply as the store owner's, so it is badged as a
				// seller response rather than another customer comment.
				add_comment_meta( (int) $new, '_zymarg_store_reply', 1, true );
				wp_send_json_success( array( 'replied' => true, 'message' => __( 'Reply posted.', 'zymarg-vendor-dashboard' ) ) );
			}
			wp_send_json_error( array( 'message' => __( 'Could not post the reply.', 'zymarg-vendor-dashboard' ) ) );
			break;

		case 'hide':
			wp_set_comment_status( $comment_id, 'hold' );
			wp_send_json_success( array( 'hidden' => true ) );
			break;

		case 'show':
			wp_set_comment_status( $comment_id, 'approve' );
			wp_send_json_success( array( 'hidden' => false ) );
			break;

		case 'report':
			update_comment_meta( $comment_id, '_zymarg_reported', 1 );
			wp_send_json_success( array( 'reported' => true, 'message' => __( 'Review reported to the marketplace admin.', 'zymarg-vendor-dashboard' ) ) );
			break;

		default:
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'zymarg-vendor-dashboard' ) ) );
	}
}
add_action( 'wp_ajax_zymarg_vendor_review_action', 'zymarg_os_vendor_review_action_ajax' );

/* ====================================================================== *
 * 6i. MESSAGES SECTION (Phase 5 — buyer <-> vendor inbox)
 * ====================================================================== */

/**
 * Register the private message store. A message is a `zymarg_message` post:
 *   post_author       = sender user ID
 *   post_content      = message body
 *   meta _zymarg_vendor   = vendor user ID
 *   meta _zymarg_customer = customer user ID
 * A "thread" is the pair (vendor, customer).
 *
 * @return void
 */
function zymarg_os_vendor_register_message_cpt() {
	register_post_type(
		'zymarg_message',
		array(
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'supports'            => array( 'author', 'editor', 'custom-fields' ),
		)
	);
}
add_action( 'init', 'zymarg_os_vendor_register_message_cpt' );

/**
 * Render the Messages section — a two-pane Messenger-style inbox.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_messages_section( $user ) {

	// ── Legacy fallback only (Communication plugin not active). ───────────────
	// When Comm plugin IS active, zymarg_os_vendor_render_section filter fires
	// BEFORE this function is called, so VendorInbox::maybe_render() handles it
	// and this function is never reached for the messages section.
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$threads   = zymarg_os_vendor_threads( $user->ID, $is_vendor );
	$preselect = isset( $_GET['thread'] ) ? absint( $_GET['thread'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Messages', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Chat with your buyers — replies are saved to each conversation.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<?php if ( empty( $threads ) ) : ?>
		<div class="zymarg-vendor-card zymarg-vendor-soon">
			<?php echo zymarg_os_vendor_icon( 'chat' ); // phpcs:ignore ?>
			<h2><?php esc_html_e( 'No conversations yet', 'zymarg-vendor-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'When you have customers, you can start a conversation here. New buyer messages will also appear in this inbox.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	<?php else : ?>
		<div class="zymarg-vm" data-preselect="<?php echo esc_attr( $preselect ); ?>">
			<aside class="zymarg-vm__list">
				<?php foreach ( $threads as $t ) : ?>
					<button type="button" class="zymarg-vm-conv" data-customer="<?php echo esc_attr( $t['id'] ); ?>">
						<span class="zymarg-vm-conv__avatar"><?php echo esc_html( zymarg_os_vendor_initials( $t['name'] ) ); ?></span>
						<span class="zymarg-vm-conv__body">
							<span class="zymarg-vm-conv__name"><?php echo esc_html( $t['name'] ); ?></span>
							<span class="zymarg-vm-conv__snippet"><?php echo esc_html( $t['snippet'] ? wp_trim_words( $t['snippet'], 7 ) : __( 'Start a conversation', 'zymarg-vendor-dashboard' ) ); ?></span>
						</span>
						<?php if ( $t['time'] ) : ?>
							<span class="zymarg-vm-conv__time"><?php echo esc_html( $t['time'] ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</aside>

			<section class="zymarg-vm__thread">
				<div class="zymarg-vm__empty"><?php esc_html_e( 'Select a conversation to start chatting.', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-vm__head" hidden>
					<button type="button" class="zymarg-vm__back" aria-label="<?php esc_attr_e( 'Back', 'zymarg-vendor-dashboard' ); ?>">&larr;</button>
					<span class="zymarg-vm__title"></span>
				</div>
				<div class="zymarg-vm__messages" hidden></div>
				<form class="zymarg-vm__composer" hidden>
					<textarea class="zymarg-vm__input" rows="1" placeholder="<?php esc_attr_e( 'Type a message…', 'zymarg-vendor-dashboard' ); ?>"></textarea>
					<button type="submit" class="zymarg-vm__send" aria-label="<?php esc_attr_e( 'Send', 'zymarg-vendor-dashboard' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3 20l18-8L3 4v6l12 2-12 2z"/></svg>
					</button>
				</form>
			</section>
		</div>
	<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

// NOTE (Phase 6): zymarg_os_vendor_render_messages_section_comm() has been removed.
// The Communication plugin's VendorInbox class (includes/frontend/vendor-inbox.php)
// now hooks into 'zymarg_os_vendor_render_section' and renders the unified
// .zymarg-inbox[data-role="seller"] markup. inbox.js boots the seller surface.
// zymarg_os_vendor_render_messages_section() below remains as legacy fallback
// when the Communication plugin is not active.


/**
 * Build the vendor's conversation list: everyone they've messaged + everyone
 * who has ordered from them, most-recent activity first.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array
 */
function zymarg_os_vendor_threads( $vendor_id, $is_vendor ) {
	$candidates = array();

	// Customers from messages.
	foreach ( zymarg_os_vendor_message_customer_ids( $vendor_id ) as $cid ) {
		$candidates[ $cid ] = true;
	}

	// Customers from orders (so the inbox isn't empty before any message).
	foreach ( zymarg_os_vendor_customers_data( $vendor_id, $is_vendor ) as $c ) {
		if ( $c['id'] > 0 ) {
			$candidates[ $c['id'] ] = true;
		}
	}

	$threads = array();
	foreach ( array_keys( $candidates ) as $cid ) {
		$last = zymarg_os_vendor_thread_last_message( $vendor_id, $cid );
		$user = get_userdata( $cid );
		$threads[] = array(
			'id'      => $cid,
			'name'    => $user ? $user->display_name : sprintf( /* translators: %d id. */ __( 'Customer #%d', 'zymarg-vendor-dashboard' ), $cid ),
			'snippet' => $last ? $last['body'] : '',
			'time'    => $last ? $last['time'] : '',
			'ts'      => $last ? $last['ts'] : 0,
		);
	}

	// Most recent activity first; ones with messages bubble up.
	usort(
		$threads,
		function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		}
	);

	return array_slice( $threads, 0, 50 );
}

/**
 * Distinct customer IDs the vendor has messages with.
 *
 * @param int $vendor_id Vendor user ID.
 * @return int[]
 */
function zymarg_os_vendor_message_customer_ids( $vendor_id ) {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm2.meta_value
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_zymarg_vendor' AND pm1.meta_value = %d
			 JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_zymarg_customer'
			 WHERE p.post_type = 'zymarg_message' AND p.post_status = 'publish'",
			$vendor_id
		)
	); // phpcs:ignore WordPress.DB
	return array_map( 'intval', (array) $ids );
}

/**
 * Last message of a thread.
 *
 * @param int $vendor_id   Vendor user ID.
 * @param int $customer_id Customer user ID.
 * @return array|null
 */
function zymarg_os_vendor_thread_last_message( $vendor_id, $customer_id ) {
	$posts = zymarg_os_vendor_thread_query( $vendor_id, $customer_id, 1, 'DESC' );
	if ( empty( $posts ) ) {
		return null;
	}
	$p = $posts[0];
	return array(
		'body' => $p->post_content,
		'time' => human_time_diff( get_post_time( 'U', true, $p ), current_time( 'timestamp', true ) ) . ' ' . __( 'ago', 'zymarg-vendor-dashboard' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		'ts'   => (int) get_post_time( 'U', true, $p ),
	);
}

/**
 * Query messages of a thread.
 *
 * @param int    $vendor_id   Vendor user ID.
 * @param int    $customer_id Customer user ID.
 * @param int    $limit       Max messages.
 * @param string $order       ASC|DESC.
 * @return WP_Post[]
 */
function zymarg_os_vendor_thread_query( $vendor_id, $customer_id, $limit = 100, $order = 'ASC' ) {
	return get_posts(
		array(
			'post_type'      => 'zymarg_message',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => $order,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => '_zymarg_vendor',
					'value' => (int) $vendor_id,
				),
				array(
					'key'   => '_zymarg_customer',
					'value' => (int) $customer_id,
				),
			),
		)
	);
}

/**
 * Render the message bubbles for a thread.
 *
 * @param int $vendor_id   Vendor user ID.
 * @param int $customer_id Customer user ID.
 * @return string
 */
function zymarg_os_vendor_thread_bubbles( $vendor_id, $customer_id ) {
	$messages = zymarg_os_vendor_thread_query( $vendor_id, $customer_id, 200, 'ASC' );
	return zymarg_os_msg_bubbles_html( $messages, $vendor_id );
}

/**
 * Render message bubbles, aligning the current viewer's messages to the right.
 *
 * @param WP_Post[] $messages Message posts (ASC).
 * @param int       $mine_id  The viewer's user ID.
 * @return string
 */
function zymarg_os_msg_bubbles_html( $messages, $mine_id ) {
	if ( empty( $messages ) ) {
		return '<p class="zymarg-vm__hint">' . esc_html__( 'No messages yet — say hello!', 'zymarg-vendor-dashboard' ) . '</p>';
	}
	$html = '';
	foreach ( $messages as $m ) {
		$mine  = ( (int) $m->post_author === (int) $mine_id );
		$html .= sprintf(
			'<div class="zymarg-vm-bubble %1$s"><span class="zymarg-vm-bubble__text">%2$s</span><span class="zymarg-vm-bubble__time">%3$s</span></div>',
			$mine ? 'is-mine' : 'is-them',
			nl2br( esc_html( $m->post_content ) ),
			esc_html( get_post_time( get_option( 'time_format' ) . ', ' . get_option( 'date_format' ), false, $m ) )
		);
	}
	return $html;
}

/**
 * AJAX: load a thread's bubbles.
 *
 * @return void
 */
function zymarg_os_vendor_msg_thread_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$vendor_id   = get_current_user_id();
	$customer_id = isset( $_POST['customer'] ) ? absint( $_POST['customer'] ) : 0;
	if ( ! $customer_id ) {
		wp_send_json_error( array( 'message' => __( 'No conversation selected.', 'zymarg-vendor-dashboard' ) ) );
	}
	$cust = get_userdata( $customer_id );
	wp_send_json_success(
		array(
			'html' => zymarg_os_vendor_thread_bubbles( $vendor_id, $customer_id ),
			'name' => $cust ? $cust->display_name : sprintf( /* translators: %d id. */ __( 'Customer #%d', 'zymarg-vendor-dashboard' ), $customer_id ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vendor_msg_thread', 'zymarg_os_vendor_msg_thread_ajax' );

/**
 * AJAX: send a message to a customer.
 *
 * @return void
 */
function zymarg_os_vendor_msg_send_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$vendor_id   = get_current_user_id();
	$customer_id = isset( $_POST['customer'] ) ? absint( $_POST['customer'] ) : 0;
	$body        = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

	if ( ! $customer_id || '' === trim( $body ) ) {
		wp_send_json_error( array( 'message' => __( 'Write a message first.', 'zymarg-vendor-dashboard' ) ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'zymarg_message',
			'post_status'  => 'publish',
			'post_author'  => $vendor_id,
			'post_content' => $body,
			'meta_input'   => array(
				'_zymarg_vendor'   => $vendor_id,
				'_zymarg_customer' => $customer_id,
			),
		)
	);

	if ( ! $id || is_wp_error( $id ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not send the message.', 'zymarg-vendor-dashboard' ) ) );
	}

	$bubble = sprintf(
		'<div class="zymarg-vm-bubble is-mine"><span class="zymarg-vm-bubble__text">%1$s</span><span class="zymarg-vm-bubble__time">%2$s</span></div>',
		nl2br( esc_html( $body ) ),
		esc_html( date_i18n( get_option( 'time_format' ) ) )
	);

	// Phase 4 — notify the buyer by email when the vendor replies.
	if ( function_exists( 'zymarg_vd_buyer_email_notify' ) ) {
		zymarg_vd_buyer_email_notify( $vendor_id, $customer_id, $body );
	}

	wp_send_json_success( array( 'bubble' => $bubble ) );
}
add_action( 'wp_ajax_zymarg_vendor_msg_send', 'zymarg_os_vendor_msg_send_ajax' );

/**
 * Store avatar HTML: Dokan store logo, else a custom-uploaded avatar, else an
 * on-brand gradient "initials" chip — never the grey Gravatar mystery-person.
 *
 * @param int    $vendor_id Vendor user ID.
 * @param string $name      Store/display name (used for the initials).
 * @return string
 */
function zymarg_os_vendor_store_avatar_html( $vendor_id, $name ) {
	$url = '';

	// 1. Dokan store logo, when set.
	if ( function_exists( 'dokan_get_store_info' ) ) {
		$info = dokan_get_store_info( $vendor_id );
		if ( ! empty( $info['gravatar'] ) ) {
			$img = wp_get_attachment_image_url( (int) $info['gravatar'], 'thumbnail' );
			if ( $img ) {
				$url = $img;
			}
		}
	}

	// 2. Plugin-cached store image (set by the in-shell uploader).
	if ( '' === $url ) {
		$custom = get_user_meta( $vendor_id, '_zymarg_store_avatar_url', true );
		if ( $custom ) {
			$url = $custom;
		}
	}

	if ( $url ) {
		return sprintf(
			'<img class="zymarg-vendor-store__avatar" src="%s" alt="" width="44" height="44">',
			esc_url( $url )
		);
	}

	// 3. Clean gradient initials instead of the default grey icon.
	return sprintf(
		'<span class="zymarg-vendor-store__avatar zymarg-vendor-store__avatar--initials" aria-hidden="true">%s</span>',
		esc_html( zymarg_os_vendor_initials( $name ) )
	);
}

/**
 * Initials for an avatar chip.
 *
 * @param string $name Name.
 * @return string
 */
function zymarg_os_vendor_initials( $name ) {
	$name  = trim( wp_strip_all_tags( (string) $name ) );
	if ( '' === $name ) {
		return '?';
	}
	$parts = preg_split( '/\s+/', $name );
	$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
	$last  = count( $parts ) > 1 ? ( function_exists( 'mb_substr' ) ? mb_substr( end( $parts ), 0, 1 ) : substr( end( $parts ), 0, 1 ) ) : '';
	return strtoupper( $first . $last );
}

/* ====================================================================== *
 * 6j. CUSTOMERS SECTION (Phase 5)
 * ====================================================================== */

/**
 * Render the Customers section: Recent, Repeat and Top buyers tabs.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_customers_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$customers = zymarg_os_vendor_customers_data( $user->ID, $is_vendor );

	// Build the four views.
	$recent = $customers; // already sorted by recency.
	$repeat = array_values(
		array_filter(
			$customers,
			function ( $c ) {
				return $c['orders'] > 1;
			}
		)
	);
	$top = $customers;
	usort(
		$top,
		function ( $a, $b ) {
			return $b['spent'] <=> $a['spent'];
		}
	);

	// Inactive = last order older than the threshold (default 60 days, filterable).
	$inactive_days = (int) apply_filters( 'zymarg_os_vendor_customers_inactive_days', 60 );
	$cutoff        = time() - ( $inactive_days * DAY_IN_SECONDS );
	$inactive      = array_values(
		array_filter(
			$customers,
			function ( $c ) use ( $cutoff ) {
				return ! empty( $c['last_ts'] ) && $c['last_ts'] < $cutoff;
			}
		)
	);
	usort(
		$inactive,
		function ( $a, $b ) {
			// Longest-lapsed first.
			return $a['last_ts'] <=> $b['last_ts'];
		}
	);

	$tabs = array(
		'recent'   => array( __( 'Recent Customers', 'zymarg-vendor-dashboard' ), array_slice( $recent, 0, 20 ) ),
		'repeat'   => array( __( 'Repeat Customers', 'zymarg-vendor-dashboard' ), array_slice( $repeat, 0, 20 ) ),
		'top'      => array( __( 'Top Buyers', 'zymarg-vendor-dashboard' ), array_slice( $top, 0, 20 ) ),
		'inactive' => array( __( 'Inactive', 'zymarg-vendor-dashboard' ), array_slice( $inactive, 0, 20 ) ),
	);

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Customers', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'The people buying from your store.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<div class="zymarg-vo-tabs zymarg-vo-tabs--customers" role="tablist">
		<?php
		$first = true;
		foreach ( $tabs as $key => $tab ) :
			?>
			<button type="button" class="zymarg-vo-tab zymarg-vcu-tab<?php echo $first ? ' is-active' : ''; ?>" data-vcutab="<?php echo esc_attr( $key ); ?>" data-count="<?php echo esc_attr( number_format_i18n( count( $tab[1] ) ) ); ?>" role="tab">
				<?php echo esc_html( $tab[0] ); ?>
			</button>
			<?php
			$first = false;
		endforeach;
		?>
	</div>

	<?php
	$first = true;
	foreach ( $tabs as $key => $tab ) :
		?>
		<div class="zymarg-vcu-panel<?php echo $first ? ' is-active' : ''; ?>" id="zymarg-vcu-<?php echo esc_attr( $key ); ?>" role="tabpanel">
			<?php echo zymarg_os_vendor_customers_list( $tab[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		$first = false;
	endforeach;
	?>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * Followers section
 * Reads data written by ZYMARG_SP_Follow (zymarg-store-page plugin).
 *   Vendor meta  : _zymarg_followers_count  (int)
 *   Shopper meta : _zymarg_followed_stores  (serialised int[])
 * ====================================================================== */

/**
 * Render the Followers section of the vendor dashboard.
 *
 * @param WP_User $user The vendor user.
 * @return string HTML.
 */
function zymarg_os_vendor_render_followers_section( $user ) {
	$vendor_id         = (int) $user->ID;
	$follower_count    = zymarg_vd_followers_get_count( $vendor_id );
	$store_page_active = class_exists( 'ZYMARG_SP_Follow' );

	// Read URL params — sanitised; defaults applied below.
	$search  = isset( $_GET['flq'] )      ? sanitize_text_field( wp_unslash( $_GET['flq'] ) )      : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$orderby = isset( $_GET['flsort'] )   ? sanitize_key( $_GET['flsort'] )                        : 'followed'; // phpcs:ignore WordPress.Security.NonceVerification
	$order   = isset( $_GET['florder'] )  ? ( 'asc' === strtolower( $_GET['florder'] ) ? 'ASC' : 'DESC' ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification
	$page    = isset( $_GET['flpage'] )   ? max( 1, (int) $_GET['flpage'] )                        : 1; // phpcs:ignore WordPress.Security.NonceVerification

	$result   = zymarg_vd_followers_get_list( $vendor_id, array(
		'search'   => $search,
		'orderby'  => $orderby,
		'order'    => $order,
		'per_page' => 20,
		'page'     => $page,
	) );
	$followers = $result['users'];
	$total     = $result['total'];
	$pages     = $result['pages'];

	// Build base URL for sort/pagination links (strips existing fl* params).
	$base_url = remove_query_arg( array( 'flq', 'flsort', 'florder', 'flpage' ) );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Followers', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Shoppers who are following your store.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<?php if ( ! $store_page_active ) : ?>
		<div class="zymarg-vendor-notice zymarg-vendor-notice--info" style="margin:24px 0;padding:16px 20px;border-radius:8px;background:var(--zv-surface,#f5f5f5);border-left:4px solid var(--color-primary,#9500a5);">
			<strong><?php esc_html_e( 'ZYMARG Store Page plugin not active', 'zymarg-vendor-dashboard' ); ?></strong><br>
			<?php esc_html_e( 'Install and activate the ZYMARG Store Page plugin to enable the follow / unfollow feature on your store page and track your followers here.', 'zymarg-vendor-dashboard' ); ?>
		</div>
	<?php endif; ?>

	<div class="zymarg-vendor-stats-row" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;">
		<?php echo zymarg_os_vendor_stat_card( __( 'Total Followers', 'zymarg-vendor-dashboard' ), number_format_i18n( $follower_count ), 'followers', null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo zymarg_os_vendor_stat_card( __( 'New This Month', 'zymarg-vendor-dashboard' ), number_format_i18n( zymarg_vd_followers_count_since( $vendor_id, strtotime( 'first day of this month 00:00:00' ) ) ), 'followers', null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( 0 === $follower_count && '' === $search ) : ?>
		<div class="zymarg-vendor-empty" style="text-align:center;padding:48px 24px;color:var(--zv-muted,#888);">
			<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="opacity:.4;margin-bottom:16px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L17 16.22l-3.84-3.83a5.5 5.5 0 0 1 7.68-7.78z"/></svg>
			<p style="margin:0;font-size:15px;"><?php esc_html_e( 'No followers yet. Share your store link to get started!', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	<?php else : ?>

		<!-- ── Search bar ──────────────────────────────────────────────── -->
		<form method="get" class="zymarg-vf-search-form" style="display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap;">
			<?php
			// Preserve all current non-fl* query vars (page, section, etc.)
			foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( in_array( $k, array( 'flq', 'flsort', 'florder', 'flpage' ), true ) ) continue;
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			?>
			<div style="position:relative;flex:1;min-width:200px;">
				<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);opacity:.45;pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="text" name="flq" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name or username…', 'zymarg-vendor-dashboard' ); ?>" style="width:100%;padding:8px 12px 8px 34px;border:1px solid var(--zv-border,#e5e7eb);border-radius:8px;font-size:13px;background:var(--zv-surface,#fff);color:var(--zv-text,#111);box-sizing:border-box;">
			</div>
			<button type="submit" style="padding:8px 16px;border-radius:8px;background:var(--zv-grad,linear-gradient(135deg,#9500a5,#bd00d1 60%,#fea9ff));color:var(--color-on-primary,#fff);border:none;font-size:13px;font-weight:600;cursor:pointer;"><?php esc_html_e( 'Search', 'zymarg-vendor-dashboard' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" style="padding:8px 14px;border-radius:8px;border:1px solid var(--zv-border,#e5e7eb);font-size:13px;color:var(--zv-muted,#888);text-decoration:none;"><?php esc_html_e( 'Clear', 'zymarg-vendor-dashboard' ); ?></a>
			<?php endif; ?>
		</form>

		<!-- ── Results summary ─────────────────────────────────────────── -->
		<p style="font-size:13px;color:var(--zv-muted,#888);margin:0 0 14px;">
			<?php
			if ( '' !== $search ) {
				printf(
					/* translators: 1: result count, 2: search term */
					esc_html__( '%1$d result(s) for "%2$s"', 'zymarg-vendor-dashboard' ),
					(int) $total,
					esc_html( $search )
				);
			} else {
				$from = ( ( $page - 1 ) * 20 ) + 1;
				$to   = min( $page * 20, $total );
				printf(
					/* translators: 1: from, 2: to, 3: total */
					esc_html__( 'Showing %1$d–%2$d of %3$d followers', 'zymarg-vendor-dashboard' ),
					(int) $from, (int) $to, (int) $total
				);
			}
			?>
		</p>

		<?php if ( empty( $followers ) ) : ?>
			<div class="zymarg-vendor-empty" style="text-align:center;padding:36px 24px;color:var(--zv-muted,#888);">
				<p style="margin:0;font-size:14px;"><?php esc_html_e( 'No followers match your search.', 'zymarg-vendor-dashboard' ); ?></p>
			</div>
		<?php else : ?>

			<!-- ── Table ───────────────────────────────────────────────── -->
			<div class="zymarg-vo-table-wrap" style="overflow-x:auto;">
				<table class="zymarg-vo-table" style="width:100%;border-collapse:collapse;">
					<thead>
						<tr>
							<?php
							// Sortable column helper.
							$cols = array(
								'name'     => __( 'Follower', 'zymarg-vendor-dashboard' ),
								'username' => __( 'Username', 'zymarg-vendor-dashboard' ),
								'email'    => __( 'Email', 'zymarg-vendor-dashboard' ),    // not sortable
								'followed' => __( 'Followed Since', 'zymarg-vendor-dashboard' ),
							);
							$th_style = 'text-align:left;padding:10px 14px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--zv-muted,#888);border-bottom:1px solid var(--zv-border,#e5e7eb);';
							foreach ( $cols as $col_key => $col_label ) :
								if ( 'email' === $col_key ) :
									?>
									<th style="<?php echo esc_attr( $th_style ); ?>"><?php echo esc_html( $col_label ); ?></th>
									<?php
								else :
									// Toggle order: if already sorting by this col flip direction, else default DESC.
									$is_active   = ( $col_key === $orderby );
									$next_order  = ( $is_active && 'DESC' === $order ) ? 'asc' : 'desc';
									$sort_url    = add_query_arg( array( 'flsort' => $col_key, 'florder' => $next_order, 'flpage' => 1 ), $base_url );
									if ( '' !== $search ) $sort_url = add_query_arg( 'flq', $search, $sort_url );
									$arrow = '';
									if ( $is_active ) {
										$arrow = 'DESC' === $order
											? ' <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="vertical-align:middle;"><polyline points="6 9 12 15 18 9"/></svg>'
											: ' <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="vertical-align:middle;"><polyline points="18 15 12 9 6 15"/></svg>';
									}
									?>
									<th style="<?php echo esc_attr( $th_style ); ?>">
										<a href="<?php echo esc_url( $sort_url ); ?>" style="text-decoration:none;color:inherit;display:inline-flex;align-items:center;gap:3px;<?php echo $is_active ? 'color:var(--color-primary,#9500a5);' : ''; ?>">
											<?php echo esc_html( $col_label ) . $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</a>
									</th>
									<?php
								endif;
							endforeach;
							?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $followers as $follower ) : ?>
							<tr>
								<td style="padding:12px 14px;border-bottom:1px solid var(--zv-border,#e5e7eb);vertical-align:middle;">
									<div style="display:flex;align-items:center;gap:10px;">
										<?php echo get_avatar( $follower->ID, 36, '', '', array( 'style' => 'border-radius:50%;flex-shrink:0;' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<span style="font-weight:500;"><?php echo esc_html( $follower->display_name ); ?></span>
									</div>
								</td>
								<td style="padding:12px 14px;border-bottom:1px solid var(--zv-border,#e5e7eb);color:var(--zv-muted,#888);font-size:13px;"><?php echo esc_html( $follower->user_login ); ?></td>
								<td style="padding:12px 14px;border-bottom:1px solid var(--zv-border,#e5e7eb);color:var(--zv-muted,#888);font-size:13px;"><?php echo esc_html( zymarg_vd_mask_email( $follower->user_email ) ); ?></td>
								<td style="padding:12px 14px;border-bottom:1px solid var(--zv-border,#e5e7eb);color:var(--zv-muted,#888);font-size:13px;">
									<?php if ( ! empty( $follower->follow_date ) ) : ?>
										<?php echo esc_html( date_i18n( get_option( 'date_format' ), $follower->follow_date ) ); ?>
									<?php else : ?>
										<span title="<?php esc_attr_e( 'Followed before date tracking was enabled', 'zymarg-vendor-dashboard' ); ?>" style="color:var(--zv-muted,#bbb);">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- ── Pagination ──────────────────────────────────────────── -->
			<?php if ( $pages > 1 ) : ?>
				<nav class="zymarg-vf-pagination" style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap;" aria-label="<?php esc_attr_e( 'Followers pagination', 'zymarg-vendor-dashboard' ); ?>">
					<?php
					$pg_base = add_query_arg( array( 'flsort' => $orderby, 'florder' => strtolower( $order ) ), $base_url );
					if ( '' !== $search ) $pg_base = add_query_arg( 'flq', $search, $pg_base );

					$btn_base  = 'display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--zv-border,#e5e7eb);color:var(--zv-text,#111);background:var(--zv-surface,#fff);';
					$btn_act   = $btn_base . 'background:var(--zv-grad,linear-gradient(135deg,#9500a5,#bd00d1 60%,#fea9ff));color:var(--color-on-primary,#fff);border-color:transparent;box-shadow:0 4px 10px rgba(149,0,165,.22);';
					$btn_dis   = $btn_base . 'opacity:.4;pointer-events:none;';

					// Prev arrow.
					if ( $page > 1 ) {
						echo '<a href="' . esc_url( add_query_arg( 'flpage', $page - 1, $pg_base ) ) . '" style="' . esc_attr( $btn_base ) . '" aria-label="' . esc_attr__( 'Previous', 'zymarg-vendor-dashboard' ) . '">&larr;</a>';
					} else {
						echo '<span style="' . esc_attr( $btn_dis ) . '">&larr;</span>';
					}

					// Page numbers — show window of 5 around current.
					$start = max( 1, $page - 2 );
					$end   = min( $pages, $page + 2 );
					if ( $start > 1 ) {
						echo '<a href="' . esc_url( add_query_arg( 'flpage', 1, $pg_base ) ) . '" style="' . esc_attr( $btn_base ) . '">1</a>';
						if ( $start > 2 ) echo '<span style="padding:0 4px;color:var(--zv-muted,#888);">&hellip;</span>';
					}
					for ( $i = $start; $i <= $end; $i++ ) {
						$style = ( $i === $page ) ? $btn_act : $btn_base;
						echo '<a href="' . esc_url( add_query_arg( 'flpage', $i, $pg_base ) ) . '" style="' . esc_attr( $style ) . '" aria-current="' . ( $i === $page ? 'page' : 'false' ) . '">' . (int) $i . '</a>';
					}
					if ( $end < $pages ) {
						if ( $end < $pages - 1 ) echo '<span style="padding:0 4px;color:var(--zv-muted,#888);">&hellip;</span>';
						echo '<a href="' . esc_url( add_query_arg( 'flpage', $pages, $pg_base ) ) . '" style="' . esc_attr( $btn_base ) . '">' . (int) $pages . '</a>';
					}

					// Next arrow.
					if ( $page < $pages ) {
						echo '<a href="' . esc_url( add_query_arg( 'flpage', $page + 1, $pg_base ) ) . '" style="' . esc_attr( $btn_base ) . '" aria-label="' . esc_attr__( 'Next', 'zymarg-vendor-dashboard' ) . '">&rarr;</a>';
					} else {
						echo '<span style="' . esc_attr( $btn_dis ) . '">&rarr;</span>';
					}
					?>
				</nav>
			<?php endif; ?>

		<?php endif; // empty followers after search ?>
	<?php endif; // follower_count === 0 && no search ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Mask an email address for display to vendors.
 *
 * e.g. john.doe@gmail.com → j***.d**@gmail.com
 *
 * Shows only the first character of the local-part and the first character
 * of each dot-segment, masking the rest. The domain is shown in full so the
 * vendor can still identify the email provider without seeing the full address.
 *
 * @param string $email Raw email address.
 * @return string Masked email.
 */
function zymarg_vd_mask_email( $email ) {
	if ( ! is_email( $email ) ) {
		return $email;
	}
	$parts  = explode( '@', $email, 2 );
	$local  = $parts[0];
	$domain = $parts[1];
	// Mask each dot-segment: keep first char, replace rest with ***.
	$segments = explode( '.', $local );
	$masked   = array_map(
		function ( $seg ) {
			if ( strlen( $seg ) <= 1 ) {
				return $seg . '*';
			}
			return mb_substr( $seg, 0, 1 ) . str_repeat( '*', min( 3, mb_strlen( $seg ) - 1 ) );
		},
		$segments
	);
	return implode( '.', $masked ) . '@' . $domain;
}

/**
 * Return the total follower count for a vendor.
 * Prefers the native Dokan function, then ZYMARG_SP_Follow, then raw meta.
 *
 * @param int $vendor_id Vendor user ID.
 * @return int
 */
function zymarg_vd_followers_get_count( $vendor_id ) {
	if ( class_exists( 'ZYMARG_SP_Follow' ) ) {
		return ZYMARG_SP_Follow::get_count( $vendor_id );
	}
	if ( function_exists( 'dokan_get_store_followers' ) ) {
		$val = dokan_get_store_followers( $vendor_id );
		if ( is_numeric( $val ) ) {
			return (int) $val;
		}
	}
	return (int) get_user_meta( $vendor_id, '_zymarg_followers_count', true );
}

/**
 * Return WP_User objects for all users who follow $vendor_id.
 *
 * Queries users whose _zymarg_followed_stores meta contains the vendor ID.
 * Capped at 200 rows to avoid memory issues on large sites.
 *
 * @param int $vendor_id Vendor user ID.
 * @return WP_User[]
 */
/**
 * Fetch followers for a vendor with optional search, sort, and pagination.
 *
 * @param int    $vendor_id  Vendor user ID.
 * @param array  $args {
 *   @type string $search   Filter by display_name or user_login (partial, case-insensitive).
 *   @type string $orderby  'name' | 'username' | 'followed' (default 'followed').
 *   @type string $order    'ASC' | 'DESC' (default 'DESC').
 *   @type int    $per_page Rows per page (default 20).
 *   @type int    $page     1-based page number (default 1).
 * }
 * @return array{ users: WP_User[], total: int, pages: int }
 */
function zymarg_vd_followers_get_list( $vendor_id, $args = array() ) {
	$args = wp_parse_args( $args, array(
		'search'   => '',
		'orderby'  => 'followed',
		'order'    => 'DESC',
		'per_page' => 20,
		'page'     => 1,
	) );

	$search   = sanitize_text_field( $args['search'] );
	$per_page = (int) $args['per_page']; // 0 = fetch all (used by count_since)
	$page     = max( 1, (int) $args['page'] );
	$order    = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

	// Map our orderby keys to WP_User_Query keys.
	$orderby_map = array(
		'name'     => 'display_name',
		'username' => 'login',
		'followed' => 'registered', // will be overridden post-query for follow_date sort
	);
	$wc_orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'registered';

	$query_args = array(
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_zymarg_followed_stores',
				'value'   => 'i:' . (int) $vendor_id . ';',
				'compare' => 'LIKE',
			),
		),
		'fields'  => 'all',
		'orderby' => $wc_orderby,
		'order'   => $order,
	);

	// Search: WP_User_Query 'search' matches login, email, display_name.
	if ( '' !== $search ) {
		$query_args['search']         = '*' . esc_attr( $search ) . '*';
		$query_args['search_columns'] = array( 'display_name', 'user_login' );
	}

	// For follow_date sort we must fetch everything, then sort+slice in PHP.
	// per_page=0 also means fetch everything (used by count_since).
	if ( 'followed' === $args['orderby'] || 0 === $per_page ) {
		$query_args['number'] = 0; // no limit
	} else {
		$query_args['number']      = $per_page;
		$query_args['offset']      = ( $page - 1 ) * $per_page;
		$query_args['count_total'] = true;
	}

	$q     = new WP_User_Query( $query_args );
	$users = $q->get_results();
	if ( ! is_array( $users ) ) {
		$users = array();
	}

	// Attach real follow_date to every user.
	foreach ( $users as $u ) {
		$u->follow_date = class_exists( 'ZYMARG_SP_Follow' )
			? ZYMARG_SP_Follow::get_follow_date( $u->ID, (int) $vendor_id )
			: 0;
	}

	// For follow_date sort: sort in PHP, then paginate.
	if ( 'followed' === $args['orderby'] ) {
		usort( $users, function ( $a, $b ) use ( $order ) {
			$ta = ! empty( $a->follow_date ) ? $a->follow_date : 0;
			$tb = ! empty( $b->follow_date ) ? $b->follow_date : 0;
			return 'ASC' === $order ? $ta <=> $tb : $tb <=> $ta;
		} );
		$total = count( $users );
		// per_page=0 means caller wants all rows (e.g. count_since).
		if ( $per_page > 0 ) {
			$users = array_slice( $users, ( $page - 1 ) * $per_page, $per_page );
		}
	} else {
		$total = (int) $q->get_total();
	}

	$pages = ( $per_page > 0 && $total > 0 ) ? (int) ceil( $total / $per_page ) : 1;

	return array(
		'users'  => $users,
		'total'  => $total,
		'pages'  => max( 1, $pages ),
		'page'   => $page,
		'search' => $search,
		'orderby'=> $args['orderby'],
		'order'  => $order,
	);
}

/**
 * Count followers who registered (as WordPress users) on or after $since.
 *
 * NOTE: WordPress user registration date ≠ follow date — we use it as an
 * approximation because the follow relationship stores no timestamp. A future
 * release of ZYMARG Store Page may add per-follow timestamps; update this
 * function when that lands.
 *
 * @param int $vendor_id Vendor user ID.
 * @param int $since     Unix timestamp threshold.
 * @return int
 */
function zymarg_vd_followers_count_since( $vendor_id, $since ) {
	$followers = zymarg_vd_followers_get_list( $vendor_id, array( 'per_page' => 0, 'orderby' => 'followed' ) );
	$count     = 0;
	$users = isset( $followers['users'] ) ? $followers['users'] : $followers;
	foreach ( $users as $f ) {
		// Prefer the real follow timestamp; fall back to WP registration date
		// for followers who existed before Phase 1 (follow date not recorded).
		$ts = ! empty( $f->follow_date ) ? (int) $f->follow_date : strtotime( $f->user_registered );
		if ( $ts >= $since ) {
			$count++;
		}
	}
	return $count;
}

/**
 * Aggregate the vendor's customers from orders.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array
 */
function zymarg_os_vendor_customers_data( $vendor_id, $is_vendor ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'limit'   => (int) apply_filters( 'zymarg_os_vendor_customers_orders_limit', 400 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
			'return'  => 'objects',
		)
	);

	$map = array();
	foreach ( (array) $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}
		$amt = zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
		if ( $amt <= 0 && $is_vendor ) {
			continue;
		}
		$cid   = (int) $order->get_customer_id();
		$email = strtolower( (string) $order->get_billing_email() );
		$key   = $cid ? 'u' . $cid : ( $email ? 'e' . $email : 'o' . $order->get_id() );
		$name  = trim( $order->get_formatted_billing_full_name() );
		$ts    = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = array(
				'id'      => $cid,
				'name'    => $name ? $name : __( 'Guest', 'zymarg-vendor-dashboard' ),
				'email'   => $email,
				'orders'  => 0,
				'spent'   => 0.0,
				'last'    => '',
				'last_ts' => 0,
			);
		}
		$map[ $key ]['orders']++;
		$map[ $key ]['spent'] += $amt;
		if ( $ts > $map[ $key ]['last_ts'] ) {
			$map[ $key ]['last_ts'] = $ts;
			$map[ $key ]['last']    = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '';
		}
	}

	$list = array_values( $map );
	usort(
		$list,
		function ( $a, $b ) {
			return $b['last_ts'] <=> $a['last_ts'];
		}
	);
	return $list;
}

/**
 * Render a customers list for a tab.
 *
 * @param array $customers Customer rows.
 * @return string
 */
function zymarg_os_vendor_customers_list( $customers ) {
	if ( empty( $customers ) ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'No customers in this view yet.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$msg_base = zymarg_os_vendor_section_url( 'messages' );
	$rows     = '';
	foreach ( $customers as $c ) {
		$message = '';
		if ( $c['id'] > 0 ) {
			$message = '<a class="zymarg-vo-view" href="' . esc_url( add_query_arg( 'thread', $c['id'], $msg_base ) ) . '">' . esc_html__( 'Message', 'zymarg-vendor-dashboard' ) . '</a>';
		}
		$rows .= sprintf(
			'<div class="zymarg-vcu-row">
				<span class="zymarg-vcu-avatar">%1$s</span>
				<span class="zymarg-vcu-name">%2$s</span>
				%9$s
				<span class="zymarg-vcu-email">%3$s</span>
				<span class="zymarg-vcu-stat zymarg-vcu-stat--orders"><strong>%4$s</strong> %5$s</span>
				<span class="zymarg-vcu-stat zymarg-vcu-stat--spent"><strong>%6$s</strong> %7$s</span>
				<span class="zymarg-vcu-last">%8$s</span>
			</div>',
			esc_html( zymarg_os_vendor_initials( $c['name'] ) ),
			esc_html( $c['name'] ),
			esc_html( $c['email'] ),
			esc_html( number_format_i18n( $c['orders'] ) ),
			esc_html__( 'orders', 'zymarg-vendor-dashboard' ),
			wp_kses_post( wc_price( $c['spent'] ) ),
			esc_html__( 'spent', 'zymarg-vendor-dashboard' ),
			esc_html( $c['last'] ),
			$message
		);
	}
	return '<div class="zymarg-vcu-list">' . $rows . '</div>'; // phpcs:ignore
}

/* ====================================================================== *
 * 6k. NOTIFICATIONS SECTION (Phase 6 lite)
 * ====================================================================== */

/**
 * Render the Notifications feed — a single chronological stream of new orders,
 * low stock, new reviews and new buyer messages, with type filters.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_os_vendor_render_notifications_section( $user ) {
	$is_vendor = zymarg_os_user_is_vendor( $user->ID );
	$items     = zymarg_os_vendor_notifications_data( $user->ID, $is_vendor );

	// Vendor announcements.
	$announcements = array();
	$read_list     = array();
	if ( function_exists( 'zymarg_vd_get_vendor_announcements' ) ) {
		$announcements = zymarg_vd_get_vendor_announcements( $user->ID );
		$read_list     = get_user_meta( $user->ID, '_zymarg_vd_read_announcements', true );
		if ( ! is_array( $read_list ) ) {
			$read_list = array();
		}
	}

	$filters = array(
		'all'     => __( 'All', 'zymarg-vendor-dashboard' ),
		'order'   => __( 'Orders', 'zymarg-vendor-dashboard' ),
		'stock'   => __( 'Stock', 'zymarg-vendor-dashboard' ),
		'review'  => __( 'Reviews', 'zymarg-vendor-dashboard' ),
		'message' => __( 'Messages', 'zymarg-vendor-dashboard' ),
	);

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Notifications', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Everything that needs your attention, in one place.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<?php if ( ! empty( $announcements ) ) : ?>
	<div class="zymarg-announcements-section">
		<?php foreach ( $announcements as $ann ) :
			$is_unread = ! in_array( $ann->ID, $read_list, true );
			?>
			<div class="zymarg-announcement-card<?php echo $is_unread ? ' is-unread' : ''; ?>" data-announce-id="<?php echo esc_attr( $ann->ID ); ?>">
				<div class="zymarg-announcement-card__accent"></div>
				<div class="zymarg-announcement-card__content">
					<div class="zymarg-announcement-card__header">
						<h3 class="zymarg-announcement-card__title">
							<?php echo esc_html( $ann->post_title ); ?>
							<?php if ( $is_unread ) : ?>
								<span class="zymarg-announcement-card__badge"><?php esc_html_e( 'NEW', 'zymarg-vendor-dashboard' ); ?></span>
							<?php endif; ?>
						</h3>
						<span class="zymarg-announcement-card__date"><?php echo esc_html( get_the_date( 'M j, Y', $ann ) ); ?></span>
					</div>
					<?php if ( ! empty( $ann->post_content ) ) : ?>
						<p class="zymarg-announcement-card__body"><?php echo esc_html( $ann->post_content ); ?></p>
					<?php endif; ?>
					<?php if ( $is_unread ) : ?>
						<button type="button" class="zymarg-announcement-mark-read" data-announce-id="<?php echo esc_attr( $ann->ID ); ?>"><?php esc_html_e( 'Mark as read', 'zymarg-vendor-dashboard' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="zymarg-zpe-card">
		<div class="zymarg-zpe-card__accent"></div>
		<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Activity Feed', 'zymarg-vendor-dashboard' ); ?></div>
		<div class="zymarg-zpe-card__body">

	<div class="zymarg-vn-filters">
		<?php foreach ( $filters as $key => $label ) : ?>
			<button type="button" class="zymarg-vn-filter<?php echo 'all' === $key ? ' is-active' : ''; ?>" data-vn-filter="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( empty( $items ) ) : ?>
		<div class="zymarg-vendor-soon" style="padding:24px;text-align:center;">
			<?php echo zymarg_os_vendor_icon( 'bell' ); // phpcs:ignore ?>
			<h2><?php esc_html_e( "You're all caught up", 'zymarg-vendor-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'New orders, low-stock alerts, reviews and messages will show up here.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	<?php else : ?>
		<div class="zymarg-vn-list">
			<?php
			foreach ( $items as $n ) {
				printf(
					'<a class="zymarg-vn-item" data-type="%1$s" href="%2$s">
						<span class="zymarg-vn-icon zymarg-vn-icon--%1$s">%3$s</span>
						<span class="zymarg-vn-body">
							<span class="zymarg-vn-title">%4$s</span>
							<span class="zymarg-vn-desc">%5$s</span>
						</span>
						<span class="zymarg-vn-time">%6$s</span>
					</a>',
					esc_attr( $n['type'] ),
					esc_url( $n['url'] ),
					zymarg_os_vendor_icon( $n['icon'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $n['title'] ),
					esc_html( $n['desc'] ),
					esc_html( $n['time'] )
				);
			}
			?>
		</div>
	<?php endif; ?>

		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Aggregate notification events (orders, low stock, reviews, messages).
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope to the vendor.
 * @return array
 */
function zymarg_os_vendor_notifications_data( $vendor_id, $is_vendor ) {
	$events = array();
	$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

	// New orders.
	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders(
			array(
				'limit'   => 10,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'return'  => 'objects',
			)
		);
		foreach ( (array) $orders as $o ) {
			if ( ! is_a( $o, 'WC_Order' ) ) {
				continue;
			}
			$amt = zymarg_os_vendor_order_total_for( $o, $vendor_id, $is_vendor );
			if ( $amt <= 0 && $is_vendor ) {
				continue;
			}
			$c    = $o->get_date_created();
			$name = trim( $o->get_formatted_billing_full_name() );
			$events[] = array(
				'type'  => 'order',
				'icon'  => 'cart',
				'title' => sprintf( /* translators: %s order number. */ __( 'New order #%s', 'zymarg-vendor-dashboard' ), $o->get_order_number() ),
				'desc'  => ( $name ? $name . ' · ' : '' ) . wp_strip_all_tags( wc_price( $amt ) ),
				'ts'    => $c ? $c->getTimestamp() : 0,
				'url'   => zymarg_os_vendor_section_url( 'orders' ),
			);
		}
	}

	// Low stock.
	foreach ( zymarg_os_vendor_low_stock_products( $vendor_id, $is_vendor ) as $ls ) {
		$events[] = array(
			'type'  => 'stock',
			'icon'  => 'box',
			'title' => __( 'Low stock', 'zymarg-vendor-dashboard' ),
			'desc'  => $ls['name'] . ' — ' . sprintf( /* translators: %d units left. */ _n( '%d left', '%d left', (int) $ls['stock'], 'zymarg-vendor-dashboard' ), (int) $ls['stock'] ),
			'ts'    => $now,
			'url'   => zymarg_os_vendor_section_url( 'products' ),
		);
	}

	// New reviews.
	$rev_args = array(
		'type'    => 'review',
		'status'  => 'approve',
		'number'  => 8,
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	);
	if ( $is_vendor ) {
		$pids = zymarg_os_vendor_product_ids( $vendor_id );
		if ( ! empty( $pids ) ) {
			$rev_args['post__in'] = $pids;
		} else {
			$rev_args = null;
		}
	}
	if ( $rev_args ) {
		foreach ( (array) get_comments( $rev_args ) as $c ) {
			$rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );

			// A review left without a star rating is announced as a review, not as
			// a five-star review. The old fallback told the seller they had just
			// received top marks from a buyer who never gave any.
			$review_title = $rating > 0
				? sprintf( /* translators: %d star rating. */ _n( 'New %d-star review', 'New %d-star review', $rating, 'zymarg-vendor-dashboard' ), $rating )
				: __( 'New review', 'zymarg-vendor-dashboard' );

			$events[] = array(
				'type'  => 'review',
				'icon'  => 'star',
				'title' => $review_title,
				'desc'  => get_the_title( $c->comment_post_ID ),
				'ts'    => strtotime( $c->comment_date_gmt . ' GMT' ),
				'url'   => zymarg_os_vendor_section_url( 'reviews' ),
			);
		}
	}

	// New buyer messages.
	$msgs = get_posts(
		array(
			'post_type'      => 'zymarg_message',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'author__not_in' => array( (int) $vendor_id ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_zymarg_vendor',
					'value' => (int) $vendor_id,
				),
			),
		)
	);
	foreach ( (array) $msgs as $m ) {
		$cid  = (int) get_post_meta( $m->ID, '_zymarg_customer', true );
		$cust = get_userdata( $cid );
		$events[] = array(
			'type'  => 'message',
			'icon'  => 'chat',
			'title' => sprintf( /* translators: %s customer name. */ __( 'New message from %s', 'zymarg-vendor-dashboard' ), $cust ? $cust->display_name : __( 'a customer', 'zymarg-vendor-dashboard' ) ),
			'desc'  => wp_trim_words( $m->post_content, 10 ),
			'ts'    => (int) get_post_time( 'U', true, $m ),
			'url'   => add_query_arg( 'thread', $cid, zymarg_os_vendor_section_url( 'messages' ) ),
		);
	}

	// Sort newest first and format the relative time.
	usort(
		$events,
		function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		}
	);
	$events = array_slice( $events, 0, 25 );
	foreach ( $events as &$e ) {
		$e['time'] = $e['ts'] ? human_time_diff( $e['ts'], $now ) . ' ' . __( 'ago', 'zymarg-vendor-dashboard' ) : '';
	}
	unset( $e );

	return $events;
}

/* ====================================================================== *
 * 6l. BUYER-SIDE MESSAGES (Phase 6 lite — two-way chat)
 * ====================================================================== */

/**
 * Whether the current request renders the buyer messages shortcode.
 *
 * @return bool
 */
function zymarg_os_buyer_messages_context() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_post();
	return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'zymarg_my_messages' );
}

/**
 * Shortcode [zymarg_my_messages] — a buyer's inbox: their conversations with
 * vendors, with a thread view + reply. Reuses the dashboard inbox UI/CSS.
 *
 * @return string
 */
function zymarg_os_buyer_messages_shortcode() {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'messages' ) ) {
		return '';
	}
	if ( ! is_user_logged_in() ) {
		return '<p class="zymarg-vendor-empty">' . esc_html__( 'Please sign in to view your messages.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$uid        = get_current_user_id();
	$vendor_ids = zymarg_os_buyer_vendor_ids( $uid );
	$preselect  = isset( $_GET['thread'] ) ? absint( $_GET['thread'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

	ob_start();
	if ( empty( $vendor_ids ) ) {
		echo '<p class="zymarg-vendor-empty">' . esc_html__( 'You have no messages yet. When a seller messages you, the conversation will appear here.', 'zymarg-vendor-dashboard' ) . '</p>';
		return (string) ob_get_clean();
	}

	// Build thread previews, most-recent first.
	$threads = array();
	foreach ( $vendor_ids as $vid ) {
		$last      = zymarg_os_vendor_thread_last_message( $vid, $uid );
		$threads[] = array(
			'id'      => $vid,
			'name'    => zymarg_os_vendor_store_name( $vid ),
			'snippet' => $last ? $last['body'] : '',
			'time'    => $last ? $last['time'] : '',
			'ts'      => $last ? $last['ts'] : 0,
		);
	}
	usort(
		$threads,
		function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		}
	);
	?>
	<div class="zymarg-vm zymarg-bm" data-msg-thread-action="zymarg_buyer_msg_thread" data-msg-send-action="zymarg_buyer_msg_send" data-msg-peer-param="vendor" data-preselect="<?php echo esc_attr( $preselect ); ?>">
		<aside class="zymarg-vm__list">
			<?php foreach ( $threads as $t ) : ?>
				<button type="button" class="zymarg-vm-conv" data-customer="<?php echo esc_attr( $t['id'] ); ?>">
					<span class="zymarg-vm-conv__avatar"><?php echo esc_html( zymarg_os_vendor_initials( $t['name'] ) ); ?></span>
					<span class="zymarg-vm-conv__body">
						<span class="zymarg-vm-conv__name"><?php echo esc_html( $t['name'] ); ?></span>
						<span class="zymarg-vm-conv__snippet"><?php echo esc_html( $t['snippet'] ? wp_trim_words( $t['snippet'], 7 ) : __( 'Open conversation', 'zymarg-vendor-dashboard' ) ); ?></span>
					</span>
					<?php if ( $t['time'] ) : ?>
						<span class="zymarg-vm-conv__time"><?php echo esc_html( $t['time'] ); ?></span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</aside>
		<section class="zymarg-vm__thread">
			<div class="zymarg-vm__empty"><?php esc_html_e( 'Select a conversation.', 'zymarg-vendor-dashboard' ); ?></div>
			<div class="zymarg-vm__head" hidden>
				<button type="button" class="zymarg-vm__back" aria-label="<?php esc_attr_e( 'Back', 'zymarg-vendor-dashboard' ); ?>">&larr;</button>
				<span class="zymarg-vm__title"></span>
			</div>
			<div class="zymarg-vm__messages" hidden></div>
			<form class="zymarg-vm__composer" hidden>
				<textarea class="zymarg-vm__input" rows="1" placeholder="<?php esc_attr_e( 'Type a message…', 'zymarg-vendor-dashboard' ); ?>"></textarea>
				<button type="submit" class="zymarg-vm__send" aria-label="<?php esc_attr_e( 'Send', 'zymarg-vendor-dashboard' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3 20l18-8L3 4v6l12 2-12 2z"/></svg>
				</button>
			</form>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'zymarg_my_messages', 'zymarg_os_buyer_messages_shortcode' );

/**
 * Distinct vendor IDs a customer has conversations with.
 *
 * @param int $customer_id Customer user ID.
 * @return int[]
 */
function zymarg_os_buyer_vendor_ids( $customer_id ) {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm1.meta_value
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_zymarg_customer' AND pm2.meta_value = %d
			 JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_zymarg_vendor'
			 WHERE p.post_type = 'zymarg_message' AND p.post_status = 'publish'",
			$customer_id
		)
	); // phpcs:ignore WordPress.DB
	return array_map( 'intval', (array) $ids );
}

/**
 * AJAX: buyer loads a thread with a vendor.
 *
 * @return void
 */
function zymarg_os_buyer_msg_thread_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$uid    = get_current_user_id();
	$vendor = isset( $_POST['vendor'] ) ? absint( $_POST['vendor'] ) : 0;
	if ( ! $vendor ) {
		wp_send_json_error( array( 'message' => __( 'No conversation selected.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Phase 8: stamp the read time so the unread poll knows this thread was seen.
	// Key is per-vendor so each thread has its own cursor.
	update_user_meta( $uid, '_zymarg_bm_read_' . $vendor, time() );

	$messages = zymarg_os_vendor_thread_query( $vendor, $uid, 200, 'ASC' );
	wp_send_json_success(
		array(
			'html' => zymarg_os_msg_bubbles_html( $messages, $uid ),
			'name' => zymarg_os_vendor_store_name( $vendor ),
		)
	);
}
add_action( 'wp_ajax_zymarg_buyer_msg_thread', 'zymarg_os_buyer_msg_thread_ajax' );

/**
 * AJAX: buyer sends a message to a vendor.
 *
 * @return void
 */
function zymarg_os_buyer_msg_send_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$uid    = get_current_user_id();
	$vendor = isset( $_POST['vendor'] ) ? absint( $_POST['vendor'] ) : 0;
	$body   = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

	if ( ! $vendor || '' === trim( $body ) ) {
		wp_send_json_error( array( 'message' => __( 'Write a message first.', 'zymarg-vendor-dashboard' ) ) );
	}
	if ( ! zymarg_os_user_is_vendor( $vendor ) ) {
		wp_send_json_error( array( 'message' => __( 'That seller is unavailable.', 'zymarg-vendor-dashboard' ) ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'zymarg_message',
			'post_status'  => 'publish',
			'post_author'  => $uid,
			'post_content' => $body,
			'meta_input'   => array(
				'_zymarg_vendor'   => $vendor,
				'_zymarg_customer' => $uid,
			),
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not send the message.', 'zymarg-vendor-dashboard' ) ) );
	}

	$bubble = sprintf(
		'<div class="zymarg-vm-bubble is-mine"><span class="zymarg-vm-bubble__text">%1$s</span><span class="zymarg-vm-bubble__time">%2$s</span></div>',
		nl2br( esc_html( $body ) ),
		esc_html( date_i18n( get_option( 'time_format' ) ) )
	);
	wp_send_json_success( array( 'bubble' => $bubble ) );
}
add_action( 'wp_ajax_zymarg_buyer_msg_send', 'zymarg_os_buyer_msg_send_ajax' );

/* ====================================================================== *
 * Phase 8 — Buyer Inbox Unread State
 *
 * Returns unread message counts per vendor thread so the buyer inbox can
 * render an unread dot + bold styling on conversation rows — mirroring the
 * vendor-side initMessagesComm() background poll.
 *
 * Read state is tracked via a user-meta key per vendor:
 *   _zymarg_bm_read_{vendor_id}  =>  Unix timestamp of last thread open.
 *
 * A message is "unread" when:
 *   - Its post_date_gmt is after the stored read timestamp (or the key is
 *     absent, meaning the thread was never explicitly opened), AND
 *   - It was NOT sent by the buyer (post_author != buyer UID), because the
 *     buyer obviously already read their own sent messages.
 * ====================================================================== */

/**
 * AJAX: return unread message counts for each vendor thread the buyer has.
 *
 * Response shape:
 *   { "success": true, "data": { "123": 2, "456": 0, ... } }
 *
 * Keys are vendor user IDs (string). Values are integer unread counts.
 *
 * @return void
 */
function zymarg_os_buyer_msg_unread_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$uid        = get_current_user_id();
	$vendor_ids = zymarg_os_buyer_vendor_ids( $uid );
	$counts     = array();

	foreach ( $vendor_ids as $vid ) {
		// Get the timestamp the buyer last opened this thread (0 = never opened).
		$read_ts = (int) get_user_meta( $uid, '_zymarg_bm_read_' . $vid, true );

		// Count messages in this thread that:
		//   - Were sent AFTER the last-read timestamp, and
		//   - Were NOT sent by the buyer (i.e. they were sent by the vendor).
		$args = array(
			'post_type'      => 'zymarg_message',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids', // lightweight — only fetch IDs.
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_zymarg_vendor',
					'value' => $vid,
					'type'  => 'NUMERIC',
				),
				array(
					'key'   => '_zymarg_customer',
					'value' => $uid,
					'type'  => 'NUMERIC',
				),
			),
			// Only posts from the vendor (post_author = $vid) count as unread
			// for the buyer. Messages the buyer sent are always "read".
			'author'         => $vid,
		);

		if ( $read_ts > 0 ) {
			// Only messages newer than the last-read stamp are unread.
			$args['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d H:i:s', $read_ts ),
					'column'    => 'post_date_gmt',
					'inclusive' => false,
				),
			);
		}

		$counts[ (string) $vid ] = (int) count( get_posts( $args ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts
	}

	wp_send_json_success( $counts );
}
add_action( 'wp_ajax_zymarg_buyer_msg_unread', 'zymarg_os_buyer_msg_unread_ajax' );

/* ====================================================================== *
 * 6m. CONTACT SELLER (Phase 6 lite — buyers start a chat from a product)
 * ====================================================================== */

/**
 * Whether assets should load for the contact-seller button (a product page
 * with the feature enabled).
 *
 * @return bool
 */
function zymarg_os_contact_seller_context() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return false;
	}
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'contact_seller' );
}

/**
 * Build the "Contact seller" UI for a given vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function zymarg_os_contact_seller_html( $vendor_id ) {
	$vendor_id = (int) $vendor_id;
	if ( ! $vendor_id || ! zymarg_os_user_is_vendor( $vendor_id ) ) {
		return '';
	}
	// Don't show on the seller's own product.
	if ( get_current_user_id() === $vendor_id ) {
		return '';
	}

	$store = zymarg_os_vendor_store_name( $vendor_id );

	ob_start();
	if ( ! is_user_logged_in() ) {
		$login = wp_login_url( get_permalink() );
		?>
		<div class="zymarg-cs">
			<a class="zymarg-cs__btn" href="<?php echo esc_url( $login ); ?>">
				<?php echo zymarg_os_vendor_icon( 'chat' ); // phpcs:ignore ?>
				<span><?php echo esc_html( sprintf( /* translators: %s store name. */ __( 'Sign in to contact %s', 'zymarg-vendor-dashboard' ), $store ) ); ?></span>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
	?>
	<div class="zymarg-cs" data-vendor="<?php echo esc_attr( $vendor_id ); ?>">
		<button type="button" class="zymarg-cs__btn" data-cs-toggle>
			<?php echo zymarg_os_vendor_icon( 'chat' ); // phpcs:ignore ?>
			<span><?php echo esc_html( sprintf( /* translators: %s store name. */ __( 'Contact %s', 'zymarg-vendor-dashboard' ), $store ) ); ?></span>
		</button>
		<form class="zymarg-cs__form" hidden>
			<textarea class="zymarg-cs__input" rows="3" required placeholder="<?php echo esc_attr( sprintf( /* translators: %s store name. */ __( 'Ask %s a question about this product…', 'zymarg-vendor-dashboard' ), $store ) ); ?>"></textarea>
			<button type="submit" class="zymarg-cs__btn zymarg-cs__send"><?php esc_html_e( 'Send message', 'zymarg-vendor-dashboard' ); ?></button>
			<p class="zymarg-cs__msg" hidden></p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Auto-render the contact-seller button on single product pages.
 *
 * @return void
 */
function zymarg_os_contact_seller_auto() {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'contact_seller' ) ) {
		return;
	}
	$product_id = get_the_ID();
	if ( ! $product_id ) {
		return;
	}
	$vendor_id = (int) get_post_field( 'post_author', $product_id );
	echo zymarg_os_contact_seller_html( $vendor_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_single_product_summary', 'zymarg_os_contact_seller_auto', 45 );

/**
 * Shortcode [zymarg_contact_seller vendor="123"] — defaults to the current
 * product's vendor when no ID is given.
 *
 * @param array $atts Attributes.
 * @return string
 */
function zymarg_os_contact_seller_shortcode( $atts ) {
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'contact_seller' ) ) {
		return '';
	}
	$atts   = shortcode_atts( array( 'vendor' => 0 ), $atts, 'zymarg_contact_seller' );
	$vendor = absint( $atts['vendor'] );
	if ( ! $vendor ) {
		$pid = get_the_ID();
		if ( $pid && 'product' === get_post_type( $pid ) ) {
			$vendor = (int) get_post_field( 'post_author', $pid );
		}
	}
	return zymarg_os_contact_seller_html( $vendor );
}
add_shortcode( 'zymarg_contact_seller', 'zymarg_os_contact_seller_shortcode' );

/**
 * AJAX: a buyer sends a "contact seller" message from a product page.
 *
 * @return void
 */
function zymarg_os_contact_seller_send_ajax() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please sign in to message the seller.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$uid    = get_current_user_id();
	$vendor = isset( $_POST['vendor'] ) ? absint( $_POST['vendor'] ) : 0;
	$body   = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

	if ( ! $vendor || '' === trim( $body ) ) {
		wp_send_json_error( array( 'message' => __( 'Write a message first.', 'zymarg-vendor-dashboard' ) ) );
	}
	if ( ! zymarg_os_user_is_vendor( $vendor ) || $vendor === $uid ) {
		wp_send_json_error( array( 'message' => __( 'This seller is unavailable.', 'zymarg-vendor-dashboard' ) ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'zymarg_message',
			'post_status'  => 'publish',
			'post_author'  => $uid,
			'post_content' => $body,
			'meta_input'   => array(
				'_zymarg_vendor'   => $vendor,
				'_zymarg_customer' => $uid,
			),
		)
	);
	if ( ! $id || is_wp_error( $id ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not send the message. Please try again.', 'zymarg-vendor-dashboard' ) ) );
	}

	wp_send_json_success( array( 'message' => __( 'Message sent! The seller will reply in your messages.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_contact_seller_send', 'zymarg_os_contact_seller_send_ajax' );

/* ====================================================================== *
 * 7. PLACEHOLDER SECTIONS (filled in later phases)
 * ====================================================================== */

/**
 * A tasteful "coming in the next phase" panel for sections not yet built.
 *
 * @param string $section Section key.
 * @return string
 */
function zymarg_os_vendor_render_placeholder_section( $section ) {
	$labels = array();
	foreach ( zymarg_os_vendor_nav_items() as $item ) {
		$labels[ $item[0] ] = $item[1];
	}
	$title = isset( $labels[ $section ] ) ? $labels[ $section ] : ucwords( str_replace( '-', ' ', $section ) );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php echo esc_html( $title ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'This section is part of the ZYMARG vendor experience rollout.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>
	<div class="zymarg-vendor-card zymarg-vendor-soon">
		<?php
		if ( function_exists( 'zymarg_vd_spark' ) ) {
			echo zymarg_vd_spark( array( 'size' => 'xl', 'label' => 'ZYMARG' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<h2><?php esc_html_e( 'Coming soon', 'zymarg-vendor-dashboard' ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: %s section name. */ __( 'The %s screen is being crafted in a follow-up phase. For now, the live Dashboard gives you the pulse of your store.', 'zymarg-vendor-dashboard' ), $title ) ); ?></p>
		<a class="zymarg-vendor-soon__btn" href="<?php echo esc_url( zymarg_os_vendor_dashboard_base_url() ); ?>"><?php esc_html_e( 'Back to Dashboard', 'zymarg-vendor-dashboard' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * 8. ACCESS GATES
 * ====================================================================== */

/**
 * Logged-out gate.
 *
 * @return string
 */
function zymarg_os_vendor_gate_login() {
	$login = wp_login_url( zymarg_os_vendor_dashboard_base_url() );
	ob_start();
	?>
	<div class="zymarg-vendor-gate">
		<?php
		if ( function_exists( 'zymarg_vd_spark' ) ) {
			echo zymarg_vd_spark( array( 'size' => 'xl', 'label' => 'ZYMARG' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<h1><?php esc_html_e( 'Vendor sign in', 'zymarg-vendor-dashboard' ); ?></h1>
		<p><?php esc_html_e( 'Sign in to your seller account to manage your store.', 'zymarg-vendor-dashboard' ); ?></p>
		<a class="zymarg-vendor-gate__btn" href="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Sign in', 'zymarg-vendor-dashboard' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Logged-in-but-not-a-vendor gate.
 *
 * @return string
 */
function zymarg_os_vendor_gate_become_vendor() {
	$register = '';
	if ( function_exists( 'dokan_get_page_url' ) ) {
		$register = dokan_get_page_url( 'myaccount' );
	}
	if ( ! $register ) {
		$register = home_url( '/vendor-registration/' );
	}
	ob_start();
	?>
	<div class="zymarg-vendor-gate">
		<?php
		if ( function_exists( 'zymarg_vd_spark' ) ) {
			echo zymarg_vd_spark( array( 'size' => 'xl', 'label' => 'ZYMARG' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<h1><?php esc_html_e( 'Start selling on ZYMARG', 'zymarg-vendor-dashboard' ); ?></h1>
		<p><?php esc_html_e( 'This area is for sellers. Open your store to access the vendor dashboard, list products and start earning.', 'zymarg-vendor-dashboard' ); ?></p>
		<a class="zymarg-vendor-gate__btn" href="<?php echo esc_url( $register ); ?>"><?php esc_html_e( 'Become a vendor', 'zymarg-vendor-dashboard' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Notice shown when an admin / shop manager (not an actual vendor) previews the
 * dashboard, so the data context is clear.
 *
 * @return string
 */
function zymarg_os_vendor_preview_notice() {
	return '<div class="zymarg-vendor-preview-note">'
		. '<strong>' . esc_html__( 'Preview mode', 'zymarg-vendor-dashboard' ) . '</strong> &mdash; '
		. esc_html__( 'You are viewing the vendor dashboard as an admin. Figures reflect store-wide data where a vendor scope is unavailable.', 'zymarg-vendor-dashboard' )
		. '</div>';
}

/**
 * Logout confirmation modal (shared markup).
 *
 * @return string
 */
function zymarg_os_vendor_logout_modal() {
	ob_start();
	?>
	<div class="zymarg-vendor-logout-modal" id="zymarg-vendor-logout-modal" hidden>
		<div class="zymarg-vendor-logout-modal__overlay" data-vendor-logout-close></div>
		<div class="zymarg-vendor-logout-modal__dialog">
			<button type="button" class="zymarg-vendor-logout-modal__close" data-vendor-logout-close aria-label="<?php esc_attr_e( 'Close', 'zymarg-vendor-dashboard' ); ?>">&times;</button>
			<div class="zymarg-vendor-logout-modal__icon"><?php echo zymarg_os_vendor_icon( 'logout' ); // phpcs:ignore ?></div>
			<h3><?php esc_html_e( 'Sign out?', 'zymarg-vendor-dashboard' ); ?></h3>
			<p><?php esc_html_e( 'Are you sure you want to log out of your seller account?', 'zymarg-vendor-dashboard' ); ?></p>
			<div class="zymarg-vendor-logout-modal__actions">
				<button type="button" class="zymarg-vendor-logout-modal__btn zymarg-vendor-logout-modal__btn--cancel" data-vendor-logout-close><?php esc_html_e( 'Cancel', 'zymarg-vendor-dashboard' ); ?></button>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="zymarg-vendor-logout-modal__btn zymarg-vendor-logout-modal__btn--confirm"><?php esc_html_e( 'Yes, log out', 'zymarg-vendor-dashboard' ); ?></a>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the centered confirm modal for order cancel action.
 *
 * @return string
 */
function zymarg_os_vendor_confirm_modal() {
	ob_start();
	?>
	<div class="zymarg-vendor-confirm-modal" id="zymarg-confirm-modal" hidden>
		<div class="zymarg-vendor-confirm-modal__overlay" data-confirm-close></div>
		<div class="zymarg-vendor-confirm-modal__dialog">
			<div class="zymarg-vendor-confirm-modal__icon">
				<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
			</div>
			<h3><?php esc_html_e( 'Cancel this order?', 'zymarg-vendor-dashboard' ); ?></h3>
			<p><?php esc_html_e( 'This action cannot be undone.', 'zymarg-vendor-dashboard' ); ?></p>
			<div class="zymarg-vendor-confirm-modal__actions">
				<button type="button" class="zymarg-vendor-confirm-modal__btn zymarg-vendor-confirm-modal__btn--cancel" data-confirm-close><?php esc_html_e( 'No, keep it', 'zymarg-vendor-dashboard' ); ?></button>
				<button type="button" class="zymarg-vendor-confirm-modal__btn zymarg-vendor-confirm-modal__btn--confirm" id="zymarg-confirm-yes"><?php esc_html_e( 'Yes, cancel order', 'zymarg-vendor-dashboard' ); ?></button>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * 9. DATA LAYER (best-effort Dokan + WooCommerce, with safe fallbacks)
 * ====================================================================== */

/**
 * Get the effective timezone for a vendor. Vendor-chosen (Settings -> Preferences)
 * wins over the site timezone. Always returns a valid IANA timezone string.
 *
 * @param int|null $user_id Vendor user ID. Defaults to current user.
 * @return string IANA timezone identifier (e.g. "Asia/Dhaka").
 */
if ( ! function_exists( 'zymarg_vd_get_vendor_timezone' ) ) {
	function zymarg_vd_get_vendor_timezone( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		$tz      = '';
		if ( $user_id > 0 ) {
			$saved = get_user_meta( $user_id, '_zymarg_vd_timezone', true );
			if ( is_string( $saved ) && '' !== $saved && false !== timezone_open( $saved ) ) {
				$tz = $saved;
			}
		}
		if ( '' === $tz ) {
			// Fall back to WP site timezone (respects Settings -> General -> Timezone).
			$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : ( get_option( 'timezone_string' ) ?: 'UTC' );
			// Normalize old-style "UTC+6" offsets to a valid PHP tz string that DateTimeZone accepts.
			if ( false === timezone_open( $tz ) ) {
				$tz = 'UTC';
			}
		}
		return $tz;
	}
}

/**
 * Time-of-day greeting string. Uses the vendor's chosen timezone (or the site
 * timezone as fallback) so it stays consistent with what the JS on the client
 * computes — no more "afternoon -> evening" flash.
 *
 * @param int|null $user_id Vendor user ID. Defaults to current user.
 * @return string
 */
function zymarg_os_vendor_time_greeting( $user_id = null ) {
	$tz   = zymarg_vd_get_vendor_timezone( $user_id );
	$hour = 0;
	try {
		$dt   = new DateTime( 'now', new DateTimeZone( $tz ) );
		$hour = (int) $dt->format( 'G' );
	} catch ( Exception $e ) {
		$hour = (int) current_time( 'G' );
	}
	if ( $hour < 12 ) {
		return __( 'Good morning', 'zymarg-vendor-dashboard' );
	}
	if ( $hour < 17 ) {
		return __( 'Good afternoon', 'zymarg-vendor-dashboard' );
	}
	return __( 'Good evening', 'zymarg-vendor-dashboard' );
}

/**
 * 3-Tier AI-powered dashboard subtitle message.
 *
 * TIER 1 — Priority ladder (always runs, no randomness):
 *   Deterministic rules based on live $data signals. Highest-urgency signal wins.
 *
 * TIER 2 — 7-day trend pattern detector (runs when Tier 1 has nothing critical):
 *   Analyses revenue_series to detect streaks, dips, best-day patterns, first sale
 *   of day, and momentum shifts. Data-specific language, no dice rolls.
 *
 * TIER 3 — LLM-generated insight (runs when AI is configured, cached 1 hr):
 *   Passes a live snapshot to OpenAI or Anthropic. Returns one hyper-personalised
 *   sentence (≤12 words) with actual ৳ figures and context. Falls back to Tier 2
 *   silently if the API call fails or no key is configured.
 *
 * Fallback chain: Tier 3a (Insight Engine) → Tier 3b (LLM API) → Tier 2 → Tier 1 → time-of-day pool.
 * Nothing breaks if Automation or AI is not configured — always falls back gracefully.
 *
 * @param array $data Vendor dashboard data (today_sales, today_orders, sales_delta,
 *                    pending_orders, low_stock, rating, revenue_series).
 * @param int   $vendor_id Optional vendor user ID for AI cache keying. Default current user.
 * @return string Subtitle message (plain text, safe to esc_html).
 */
function zymarg_os_vendor_subtitle_message( $data, $vendor_id = 0 ) {
	if ( ! $vendor_id ) {
		$vendor_id = get_current_user_id();
	}

	// Resolve vendor timezone once — used by Tier 1 and as context for Tier 3.
	$tz   = function_exists( 'zymarg_vd_get_vendor_timezone' ) ? zymarg_vd_get_vendor_timezone( $vendor_id ) : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' );
	$hour = 12;
	try {
		$dt   = new DateTime( 'now', new DateTimeZone( $tz ) );
		$hour = (int) $dt->format( 'G' );
	} catch ( Exception $e ) {
		$hour = (int) current_time( 'G' );
	}

	// Normalise inputs — guard against missing keys so every tier is safe.
	$pending       = isset( $data['pending_orders'] ) ? (int) $data['pending_orders'] : 0;
	$today_sales   = isset( $data['today_sales'] )   ? (float) $data['today_sales']   : 0.0;
	$today_orders  = isset( $data['today_orders'] )  ? (int) $data['today_orders']    : 0;
	$sales_delta   = isset( $data['sales_delta'] )   ? $data['sales_delta']           : null; // float|null
	$low_stock     = isset( $data['low_stock'] )     ? (array) $data['low_stock']     : array();
	$rating        = isset( $data['rating'] )        ? (float) $data['rating']        : 0.0;
	$series        = isset( $data['revenue_series'] ) ? (array) $data['revenue_series'] : array();

	/* =========================================================
	 * TIER 3a — ZYMARG Insight Engine (local, zero cost, zero config)
	 * Delegates to the Level 3 engine in the Automation plugin when
	 * active. Fully local: no API key, no network call, no rate limit.
	 * Caching is owned by the engine (55-65 min jittered transient).
	 * Falls through silently if Automation is not active.
	 * ======================================================== */
	if ( function_exists( 'zymarg_auto_generate_insight' ) ) {
		// Wrapped defensively: even though the engine is exception-safe, a fatal
		// here would take down the dashboard. Never trust an external plugin call.
		try {
			$insight = zymarg_auto_generate_insight( $data, 'vendor_greeting', $vendor_id );
			if ( is_string( $insight ) && '' !== $insight ) {
				return $insight;
			}
		} catch ( \Throwable $e ) {
			// Fall through to Tier 3b / Tier 1 / Tier 2 silently.
		}
	}

	/* =========================================================
	 * TIER 3b — LLM API insight (optional, only if key configured)
	 * Only runs when Automation is NOT active (Tier 3a fell through)
	 * and an API key is set in VD's own AI settings panel.
	 * ======================================================== */
	$ai_cfg = get_option( 'zymarg_vd_ai', array() );
	$ai_on  = ! empty( $ai_cfg['enabled'] ) && ! empty( $ai_cfg['api_key'] );

	if ( $ai_on ) {
		$cache_key = 'zymarg_vd_ai_sub_' . $vendor_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		// Build snapshot for the prompt.
		$snapshot = array(
			'today_sales'   => $today_sales,
			'today_orders'  => $today_orders,
			'sales_delta'   => $sales_delta,   // % vs yesterday, or null
			'pending_orders'=> $pending,
			'low_stock_count' => count( $low_stock ),
			'low_stock_item'  => ! empty( $low_stock[0]['name'] ) ? $low_stock[0]['name'] : '',
			'rating'        => $rating,
			'revenue_trend' => array_column( $series, 'value' ), // 7-day values
			'hour_of_day'   => $hour,
			'currency'      => '৳',
		);
		$ai_msg = zymarg_vd_ai_subtitle_generate( $snapshot, $ai_cfg );
		if ( $ai_msg ) {
			set_transient( $cache_key, $ai_msg, HOUR_IN_SECONDS );
			return $ai_msg;
		}
		// If AI call failed, fall through to Tier 1/2 silently.
	}

	/* =========================================================
	 * TIER 1 — Priority ladder (deterministic, no randomness)
	 * Highest-urgency actionable signal always wins.
	 * ======================================================== */

	// P1: Multiple pending orders — vendor needs to act NOW.
	if ( $pending >= 5 ) {
		return sprintf(
			/* translators: %d: number of pending orders */
			_n( '%d order is waiting — customers are watching the clock.', '%d orders need your attention right now.', $pending, 'zymarg-vendor-dashboard' ),
			$pending
		);
	}
	if ( $pending > 0 ) {
		return sprintf(
			/* translators: %d: number of pending orders */
			_n( 'You have %d pending order to review.', 'You have %d pending orders to review.', $pending, 'zymarg-vendor-dashboard' ),
			$pending
		);
	}

	// P2: Rating dropped below 4.0 — reputation at risk.
	if ( $rating > 0 && $rating < 4.0 ) {
		return sprintf(
			/* translators: %s: rating value e.g. 3.8 */
			__( 'Your rating is %s — a few quick replies could turn it around.', 'zymarg-vendor-dashboard' ),
			number_format_i18n( $rating, 1 )
		);
	}

	// P3: Sales dropped vs yesterday — negative delta.
	if ( null !== $sales_delta && $sales_delta < -15 ) {
		return sprintf(
			/* translators: %s: percentage drop e.g. 23 */
			__( 'Sales are down %s%% vs yesterday — check your listings.', 'zymarg-vendor-dashboard' ),
			number_format_i18n( abs( $sales_delta ), 0 )
		);
	}

	// P4: Low stock on a product — operational risk.
	if ( ! empty( $low_stock ) ) {
		$item = $low_stock[0];
		if ( ! empty( $item['name'] ) && isset( $item['stock'] ) ) {
			return sprintf(
				/* translators: 1: product name, 2: remaining stock count */
				__( '"%1$s" has only %2$d left — restock before it runs out.', 'zymarg-vendor-dashboard' ),
				$item['name'],
				(int) $item['stock']
			);
		}
		return __( 'One of your products is almost out of stock — restock now.', 'zymarg-vendor-dashboard' );
	}

	// P5: Sales are up — celebrate with a real number.
	if ( null !== $sales_delta && $sales_delta >= 50 ) {
		return sprintf(
			/* translators: %s: percentage increase e.g. 120 */
			__( 'Sales up %s%% vs yesterday — incredible run!', 'zymarg-vendor-dashboard' ),
			number_format_i18n( $sales_delta, 0 )
		);
	}
	if ( null !== $sales_delta && $sales_delta > 0 ) {
		return sprintf(
			/* translators: %s: percentage increase e.g. 18 */
			__( 'Sales up %s%% vs yesterday — keep it going!', 'zymarg-vendor-dashboard' ),
			number_format_i18n( $sales_delta, 0 )
		);
	}

	// P6: No orders yet today (afternoon/evening only — morning is too early to worry).
	if ( $today_orders === 0 && $hour >= 14 ) {
		return __( 'No orders yet today — try sharing a product or running a promo.', 'zymarg-vendor-dashboard' );
	}

	/* =========================================================
	 * TIER 2 — 7-day trend pattern detector
	 * Uses revenue_series data that is already loaded — no extra
	 * queries. Detects streaks, dips, momentum shifts, milestones.
	 * ======================================================== */
	if ( count( $series ) >= 3 ) {
		$values = array_column( $series, 'value' );
		$n      = count( $values );

		// Detect a growth streak (last 3 consecutive days all positive + growing).
		$streak = 0;
		for ( $i = $n - 1; $i >= 1; $i-- ) {
			if ( (float) $values[ $i ] > (float) $values[ $i - 1 ] && (float) $values[ $i ] > 0 ) {
				$streak++;
			} else {
				break;
			}
		}
		if ( $streak >= 3 ) {
			return sprintf(
				/* translators: %d: number of consecutive growth days */
				_n( '%d-day sales streak — your strongest run this month!', '%d-day sales streak — your strongest run this month!', $streak, 'zymarg-vendor-dashboard' ),
				$streak
			);
		}

		// Detect today as first sale after a dry streak (≥2 zero days then today > 0).
		if ( $today_orders > 0 ) {
			$dry_days = 0;
			for ( $i = $n - 2; $i >= 0; $i-- ) {
				if ( (float) $values[ $i ] <= 0 ) {
					$dry_days++;
				} else {
					break;
				}
			}
			if ( $dry_days >= 2 ) {
				return __( "Sales are back — you've broken the quiet spell. Keep it up!", 'zymarg-vendor-dashboard' );
			}
		}

		// Detect today as best day in the 7-day window.
		if ( $today_sales > 0 ) {
			$past_max = 0.0;
			for ( $i = 0; $i < $n - 1; $i++ ) {
				if ( (float) $values[ $i ] > $past_max ) {
					$past_max = (float) $values[ $i ];
				}
			}
			if ( $past_max > 0 && $today_sales > $past_max ) {
				return sprintf(
					/* translators: %s: formatted currency amount e.g. ৳4,200 */
					__( '%s earned today — your best day this week!', 'zymarg-vendor-dashboard' ),
					html_entity_decode( strip_tags( wc_price( $today_sales ) ), ENT_QUOTES, 'UTF-8' )
				);
			}
		}

		// Detect a sharp dip after a strong stretch (last day < 30% of 3-day avg).
		if ( $n >= 4 ) {
			$recent_avg = ( (float) $values[ $n - 4 ] + (float) $values[ $n - 3 ] + (float) $values[ $n - 2 ] ) / 3;
			if ( $recent_avg > 0 && (float) $values[ $n - 1 ] < $recent_avg * 0.3 ) {
				return __( 'Quiet today after a strong stretch — a flash promo could help.', 'zymarg-vendor-dashboard' );
			}
		}
	}

	// Tier 2: First sale of the day just came in.
	if ( $today_orders === 1 && $today_sales > 0 ) {
		return __( 'First order of the day is in — great start, keep going!', 'zymarg-vendor-dashboard' );
	}

	/* =========================================================
	 * TIME-OF-DAY FALLBACK (only reached when nothing actionable)
	 * ======================================================== */
	if ( $hour < 12 ) {
		$messages = array(
			__( "Let's grow your business today.", 'zymarg-vendor-dashboard' ),
			__( 'A fresh day, a fresh opportunity!', 'zymarg-vendor-dashboard' ),
			__( 'Ready to make today count?', 'zymarg-vendor-dashboard' ),
		);
	} elseif ( $hour < 17 ) {
		$messages = array(
			__( 'Keep the momentum going!', 'zymarg-vendor-dashboard' ),
			__( "You're on a roll — strong finish ahead!", 'zymarg-vendor-dashboard' ),
			__( 'Halfway through the day — make it count.', 'zymarg-vendor-dashboard' ),
		);
	} else {
		$messages = array(
			__( 'Great work today — time to recharge.', 'zymarg-vendor-dashboard' ),
			__( 'Another productive day in the books!', 'zymarg-vendor-dashboard' ),
			__( 'Rest up — tomorrow is another chance to grow.', 'zymarg-vendor-dashboard' ),
		);
	}

	return $messages[ array_rand( $messages ) ];
}

/**
 * Tier 3: Call the configured AI provider (OpenAI or Anthropic) and return a
 * single hyper-personalised subtitle sentence for the vendor dashboard greeting.
 *
 * The result is intentionally NOT cached here — the caller (`zymarg_os_vendor_subtitle_message`)
 * owns the 1-hour transient so the same cached string is reused on every page load
 * within the hour without extra API calls.
 *
 * On ANY failure (network, quota, bad response, malformed JSON) the function returns
 * an empty string so the caller falls back to Tier 1/2 silently — no PHP warning,
 * no visible error to the vendor.
 *
 * @param array $snapshot Vendor data snapshot (today_sales, today_orders, sales_delta,
 *                        pending_orders, low_stock_count, low_stock_item, rating,
 *                        revenue_trend, hour_of_day, currency).
 * @param array $ai_cfg   AI config from option 'zymarg_vd_ai':
 *                        provider (openai|anthropic), api_key, model.
 * @return string Single sentence (≤15 words), or empty string on failure.
 */
function zymarg_vd_ai_subtitle_generate( $snapshot, $ai_cfg ) {
	$provider = isset( $ai_cfg['provider'] ) ? sanitize_key( $ai_cfg['provider'] ) : 'openai';
	$api_key  = isset( $ai_cfg['api_key'] )  ? trim( $ai_cfg['api_key'] )          : '';
	$model    = isset( $ai_cfg['model'] )    ? trim( $ai_cfg['model'] )             : '';

	if ( '' === $api_key ) {
		return '';
	}

	// Defaults per provider if admin left the model field blank.
	if ( '' === $model ) {
		$model = ( 'anthropic' === $provider ) ? 'claude-haiku-4-5' : 'gpt-4o-mini';
	}

	// Build a tight, data-rich system + user prompt.
	$trend_str = implode( ', ', array_map( 'number_format', (array) $snapshot['revenue_trend'] ) );
	$delta_str = null !== $snapshot['sales_delta']
		? ( $snapshot['sales_delta'] >= 0 ? '+' . $snapshot['sales_delta'] . '%' : $snapshot['sales_delta'] . '%' )
		: 'no comparison data';

	$system_prompt = 'You are a smart, concise business coach for a vendor on ZYMARG — a Bangladeshi e-commerce marketplace. '
		. 'Your job: write ONE short sentence (maximum 12 words) that feels personal, data-specific, and genuinely useful to the vendor. '
		. 'Rules: (1) Always reference an actual number or metric from the data — NEVER be generic. '
		. '(2) Currency is ' . $snapshot['currency'] . ' (Bangladeshi Taka). '
		. '(3) If there is an urgent action (pending orders, low stock, rating drop) lead with that. '
		. '(4) Never say "Great work today!" or any cliché motivational phrase. '
		. '(5) No markdown, no quotes, no emoji — plain text only. '
		. '(6) Output ONLY the sentence. Nothing else.';

	$user_prompt = 'Vendor dashboard snapshot right now: '
		. 'Today\'s sales: ' . $snapshot['currency'] . number_format( $snapshot['today_sales'], 0 ) . '. '
		. 'Today\'s orders: ' . $snapshot['today_orders'] . '. '
		. 'Sales vs yesterday: ' . $delta_str . '. '
		. 'Pending orders: ' . $snapshot['pending_orders'] . '. '
		. 'Low-stock products: ' . $snapshot['low_stock_count']
		. ( $snapshot['low_stock_item'] ? ' (worst offender: ' . $snapshot['low_stock_item'] . ')' : '' ) . '. '
		. 'Store rating: ' . ( $snapshot['rating'] > 0 ? $snapshot['rating'] : 'no rating yet' ) . '. '
		. '7-day revenue trend (oldest to newest, ' . $snapshot['currency'] . '): ' . $trend_str . '. '
		. 'Current hour (vendor local time, 24h): ' . $snapshot['hour_of_day'] . '. '
		. 'Write the one-sentence insight now.';

	// --- Build provider-specific request ---
	if ( 'anthropic' === $provider ) {
		$endpoint = 'https://api.anthropic.com/v1/messages';
		$headers  = array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
		);
		$body = wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => 60,
			'system'     => $system_prompt,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
		) );
	} else {
		// Default: OpenAI-compatible (also works with any OpenAI-compatible endpoint).
		$endpoint = 'https://api.openai.com/v1/chat/completions';
		$headers  = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);
		$body = wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => 60,
			'messages'   => array(
				array( 'role' => 'system',  'content' => $system_prompt ),
				array( 'role' => 'user',    'content' => $user_prompt ),
			),
		) );
	}

	$response = wp_remote_post( $endpoint, array(
		'headers' => $headers,
		'body'    => $body,
		'timeout' => 8, // 8s hard limit — must not slow down dashboard load noticeably.
	) );

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( (int) $http_code !== 200 ) {
		return '';
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}

	// Extract text from provider-specific response envelope.
	$text = '';
	if ( 'anthropic' === $provider ) {
		$text = isset( $decoded['content'][0]['text'] ) ? $decoded['content'][0]['text'] : '';
	} else {
		$text = isset( $decoded['choices'][0]['message']['content'] ) ? $decoded['choices'][0]['message']['content'] : '';
	}

	$text = sanitize_text_field( trim( $text ) );

	// Safety: reject empty, excessively long, or HTML-containing responses.
	if ( '' === $text || strlen( $text ) > 200 || $text !== wp_strip_all_tags( $text ) ) {
		return '';
	}

	return $text;
}

/**
 * Vendor store display name.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function zymarg_os_vendor_store_name( $vendor_id ) {
	if ( function_exists( 'dokan_get_store_info' ) ) {
		$info = dokan_get_store_info( $vendor_id );
		if ( ! empty( $info['store_name'] ) ) {
			return $info['store_name'];
		}
	}
	$user = get_userdata( $vendor_id );
	return $user ? $user->display_name : __( 'My Store', 'zymarg-vendor-dashboard' );
}

/**
 * Vendor public store URL.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function zymarg_os_vendor_store_url( $vendor_id ) {
	if ( function_exists( 'dokan_get_store_url' ) ) {
		$url = dokan_get_store_url( $vendor_id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/' );
}

/**
 * Collect everything the Dashboard screen needs in one pass.
 *
 * Strategy: pull recent orders once (last 7 days) and derive sales, order
 * counts, the revenue series and the latest-orders list from that single set.
 * Products / low-stock / reviews are queried separately and scoped to the
 * vendor where possible.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array
 */
function zymarg_os_vendor_collect_data( $vendor_id ) {
	$vendor_id = (int) $vendor_id;
	return zymarg_vd_cache_get_or_set(
		'zymarg_vd_c_dash_' . $vendor_id,
		(int) apply_filters( 'zymarg_vd_cache_ttl_dashboard', 60 ),
		function () use ( $vendor_id ) {
			return zymarg_os_vendor_collect_data_impl( $vendor_id );
		}
	);
}

/**
 * Uncached inner producer for the Dashboard-home data. Not called directly
 * outside the cache wrapper above.
 *
 * @param int $vendor_id Vendor user ID.
 * @return array
 */
function zymarg_os_vendor_collect_data_impl( $vendor_id ) {
	$is_vendor = zymarg_os_user_is_vendor( $vendor_id );

	$data = array(
		'today_sales'    => 0.0,
		'sales_delta'    => null,
		'today_orders'   => 0,
		'pending_orders' => 0,
		'rating'         => 0.0,
		'revenue_series' => array(),
		'latest_orders'  => array(),
		'low_stock'      => array(),
		'recent_reviews' => array(),
	);

	if ( ! function_exists( 'wc_get_orders' ) ) {
		$data['revenue_series'] = zymarg_os_vendor_empty_series();
		return $data;
	}

	// ---- Orders (last 8 days so we can show a delta) ------------------
	$after = gmdate( 'Y-m-d H:i:s', strtotime( '-8 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$args  = array(
		'limit'        => 300,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'date_created' => '>=' . $after,
		'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
		'return'       => 'objects',
	);
	$orders = wc_get_orders( $args );

	$today        = current_time( 'Y-m-d' );
	$yesterday    = gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$series_map   = zymarg_os_vendor_series_skeleton();
	$today_sales  = 0.0;
	$yest_sales   = 0.0;
	$today_orders = 0;
	$pending      = 0;
	$latest       = array();

	foreach ( (array) $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}

		$vendor_total = zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor );
		if ( $vendor_total <= 0 && $is_vendor ) {
			continue; // order has nothing from this vendor.
		}

		$created = $order->get_date_created();
		$day_key = $created ? $created->date( 'Y-m-d' ) : $today;

		if ( isset( $series_map[ $day_key ] ) ) {
			$series_map[ $day_key ] += $vendor_total;
		}

		if ( $day_key === $today ) {
			$today_sales += $vendor_total;
			$today_orders++;
		} elseif ( $day_key === $yesterday ) {
			$yest_sales += $vendor_total;
		}

		$status = $order->get_status();
		if ( in_array( $status, array( 'processing', 'on-hold' ), true ) ) {
			$pending++;
		}

		if ( count( $latest ) < 6 ) {
			$name = trim( $order->get_formatted_billing_full_name() );
			$latest[] = array(
				'number'       => $order->get_order_number(),
				'customer'     => $name ? $name : __( 'Guest', 'zymarg-vendor-dashboard' ),
				'total'        => $vendor_total,
				'status_key'   => $status,
				'status_label' => wc_get_order_status_name( $status ),
			);
		}
	}

	// Build the 7-day series (oldest -> newest) for the chart.
	$series = array();
	$keys   = array_keys( $series_map );
	sort( $keys );
	$last7  = array_slice( $keys, -7 );
	foreach ( $last7 as $k ) {
		$series[] = array(
			'label' => date_i18n( 'D', strtotime( $k ) ),
			'value' => (float) $series_map[ $k ],
		);
	}

	$data['today_sales']    = $today_sales;
	$data['today_orders']   = $today_orders;
	$data['pending_orders'] = $pending;
	$data['latest_orders']  = $latest;
	$data['revenue_series'] = $series;

	if ( $yest_sales > 0 ) {
		$data['sales_delta'] = round( ( ( $today_sales - $yest_sales ) / $yest_sales ) * 100, 1 );
	} elseif ( $today_sales > 0 ) {
		$data['sales_delta'] = 100.0;
	}

	// ---- Rating -------------------------------------------------------
	if ( $is_vendor && function_exists( 'dokan_get_seller_rating' ) ) {
		$rating = dokan_get_seller_rating( $vendor_id );
		if ( is_array( $rating ) && isset( $rating['rating'] ) ) {
			$data['rating'] = (float) $rating['rating'];
		}
	}

	// ---- Low stock ----------------------------------------------------
	$data['low_stock'] = zymarg_os_vendor_low_stock_products( $vendor_id, $is_vendor );

	// ---- Recent reviews ----------------------------------------------
	$data['recent_reviews'] = zymarg_os_vendor_fetch_reviews( $vendor_id, $is_vendor );

	return $data;
}

/**
 * Calculate a vendor's revenue share within a single order.
 *
 * For real vendors we sum line items whose product belongs to the vendor
 * (Dokan stores products as posts authored by the vendor). For admin preview
 * (not a vendor) we return the whole order total.
 *
 * @param WC_Order $order     Order.
 * @param int      $vendor_id Vendor user ID.
 * @param bool     $is_vendor Whether scoping to a vendor.
 * @return float
 */
function zymarg_os_vendor_order_total_for( $order, $vendor_id, $is_vendor ) {
	if ( ! $is_vendor ) {
		return (float) $order->get_total();
	}

	// Prefer Dokan's per-vendor sub-order total when available.
	if ( function_exists( 'dokan_get_seller_amount_from_order' ) ) {
		$amt = dokan_get_seller_amount_from_order( $order->get_id(), $vendor_id );
		if ( is_numeric( $amt ) ) {
			return (float) $amt;
		}
	}

	$total = 0.0;
	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();
		if ( ! $product_id ) {
			continue;
		}
		$author = (int) get_post_field( 'post_author', $product_id );
		if ( $author === (int) $vendor_id ) {
			$total += (float) $item->get_total();
		}
	}
	return $total;
}

/**
 * Low-stock products for the vendor.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope by author.
 * @return array
 */
function zymarg_os_vendor_low_stock_products( $vendor_id, $is_vendor ) {
	$threshold = (int) apply_filters( 'zymarg_os_vendor_low_stock_threshold', 5 );

	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 6,
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			array(
				'key'   => '_manage_stock',
				'value' => 'yes',
			),
			array(
				'key'     => '_stock',
				'value'   => $threshold,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		),
		'orderby'        => 'meta_value_num',
		'meta_key'       => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'order'          => 'ASC',
	);
	if ( $is_vendor ) {
		$args['author'] = (int) $vendor_id;
	}

	$q     = new WP_Query( $args );
	$items = array();
	foreach ( $q->posts as $p ) {
		$stock = get_post_meta( $p->ID, '_stock', true );
		$items[] = array(
			'name'  => get_the_title( $p ),
			'stock' => (int) $stock,
			'edit'  => zymarg_os_vendor_product_edit_url( $p->ID ),
		);
	}
	wp_reset_postdata();
	return $items;
}

/**
 * Edit URL for a product (Dokan front-end edit when available).
 *
 * @param int $product_id Product ID.
 * @return string
 */
function zymarg_os_vendor_product_edit_url( $product_id ) {
	$url = '';
	if ( function_exists( 'dokan_edit_product_url' ) ) {
		$url = dokan_edit_product_url( $product_id );
	}
	if ( ! $url ) {
		$url = get_permalink( $product_id );
	}
	/**
	 * Filter the product edit URL. The native product editor points this at
	 * the in-shell editor when enabled.
	 *
	 * @param string $url        Default (Dokan) edit URL.
	 * @param int    $product_id Product ID.
	 */
	return (string) apply_filters( 'zymarg_os_vendor_product_edit_url', $url, $product_id );
}

/**
 * URL to create a new product. By default hands off to Dokan; the native
 * product editor filters this to the in-shell editor when enabled.
 *
 * @return string
 */
function zymarg_os_vendor_new_product_url() {
	return (string) apply_filters( 'zymarg_os_vendor_new_product_url', zymarg_os_vendor_dokan_url( 'new-product' ) );
}

/**
 * Recent reviews on the vendor's products.
 *
 * @param int  $vendor_id Vendor user ID.
 * @param bool $is_vendor Whether to scope by product author.
 * @return array
 */
function zymarg_os_vendor_fetch_reviews( $vendor_id, $is_vendor ) {
	$args = array(
		'status'  => 'approve',
		'type'    => 'review',
		'number'  => 20,
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	);

	// Scope the query itself to the vendor's products. It used to pull the 20
	// newest reviews on the whole marketplace and then discard the ones that
	// were not the vendor's, so a smaller seller could see an empty widget
	// while having plenty of recent reviews of their own.
	if ( $is_vendor ) {
		$pids = zymarg_os_vendor_product_ids( $vendor_id );
		if ( empty( $pids ) ) {
			return array();
		}
		$args['post__in'] = $pids;
	}

	$comments = get_comments( $args );

	$out = array();
	foreach ( (array) $comments as $c ) {
		$product_id = (int) $c->comment_post_ID;
		$rating     = (int) get_comment_meta( $c->comment_ID, 'rating', true );
		$out[]      = array(
			'author'  => $c->comment_author ? $c->comment_author : __( 'Anonymous', 'zymarg-vendor-dashboard' ),
			// An unrated review reports 0, not a fabricated 5.
			'rating'  => $rating,
			'text'    => $c->comment_content,
			'product' => get_the_title( $product_id ),
		);
		if ( count( $out ) >= 4 ) {
			break;
		}
	}
	return $out;
}

/**
 * Skeleton map of the last 8 days => 0.0 (keyed Y-m-d).
 *
 * @return array<string,float>
 */
function zymarg_os_vendor_series_skeleton() {
	$map = array();
	for ( $i = 7; $i >= 0; $i-- ) {
		$key         = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$map[ $key ] = 0.0;
	}
	return $map;
}

/**
 * An all-zero 7-point series (used when WooCommerce is unavailable).
 *
 * @return array
 */
function zymarg_os_vendor_empty_series() {
	$series = array();
	for ( $i = 6; $i >= 0; $i-- ) {
		$series[] = array(
			'label' => date_i18n( 'D', strtotime( "-{$i} days", current_time( 'timestamp' ) ) ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			'value' => 0.0,
		);
	}
	return $series;
}

/* ====================================================================== *
 * 10. ICONS
 * ====================================================================== */

/**
 * Inline SVG icon by key (stroke-based, 24x24, matches the account icon set).
 *
 * @param string $icon Icon key.
 * @return string
 */
function zymarg_os_vendor_icon( $icon ) {
	$o = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">';
	$c = '</svg>';

	$paths = array(
		'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
		'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
		'cart'      => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
		'wallet'    => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
		'chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
		'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'star'      => '<polygon points="12 2 15.1 8.3 22 9.2 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.2 8.9 8.3 12 2"/>',
		'chat'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
		'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'followers' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20.84 4.61a5.5 5.5 0 0 1 0 7.78L17 16.22l-3.84-3.83a5.5 5.5 0 0 1 7.68-7.78z"/>',
		'truck'     => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
		'card'      => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
		'store'     => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 3 0"/>',
		'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
		'lifebuoy'  => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>',
		'headset'   => '<path d="M3 14v-2a9 9 0 0 1 18 0v2"/><rect x="2" y="14" width="5" height="7" rx="2"/><rect x="17" y="14" width="5" height="7" rx="2"/><path d="M17 21a4 4 0 0 1-4 4h-1"/>',
		'globe'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
		'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
		'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'refund'    => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="9.5" y1="10.5" x2="14.5" y2="10.5"/>',
		// Quick-action (plus-badged) icons.
		'plus-box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><line x1="12" y1="9" x2="12" y2="15"/><line x1="9" y1="12" x2="15" y2="12"/>',
		'plus-ticket'   => '<path d="M2 9a3 3 0 0 1 3 3 3 3 0 0 1-3 3v4h20v-4a3 3 0 0 1 0-6V5H2z"/><line x1="12" y1="9" x2="12" y2="15"/><line x1="9" y1="12" x2="15" y2="12"/>',
		'plus-wallet'   => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><line x1="16" y1="11" x2="16" y2="17"/><line x1="13" y1="14" x2="19" y2="14"/>',
		'plus-image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
		'plus-megaphone'=> '<path d="M3 11l18-5v12L3 14v-3z"/><line x1="9" y1="19" x2="9" y2="13"/>',
	);

	$inner = isset( $paths[ $icon ] ) ? $paths[ $icon ] : '';
	return $inner ? $o . $inner . $c : '';
}


/* ====================================================================== *
 * 10.4 — Register 'support' as a native (SPA-eligible) section (v1.46.4).
 *
 * BUG FIX for v1.46.3: The Support section was correctly rendered by
 * zymarg_vd_render_support_section() below, but clicking "Support" in the
 * sidebar still caused a full page reload AND showed no content — because
 * 'support' was not in the zymarg_os_vendor_native_sections() allow-list.
 * That list drives THREE things in one go:
 *   1. Whether the sidebar link gets `data-spa="1"` — JS intercepts SPA
 *      links and swaps content via AJAX; non-SPA links get a full
 *      browser navigation (the "page reload" bug the user reported).
 *   2. Whether the wp_ajax_zymarg_vd_load_section endpoint accepts the
 *      section key — it falls back to 'dashboard' otherwise, which is
 *      why the user saw the Dashboard content instead of Support.
 *   3. Whether the /wp-json/zymarg-vd/v1/section REST endpoint accepts
 *      the section key — same fall-through behavior.
 *
 * One filter, three fixes at once. Same pattern used by premium-vendor.php,
 * refunds.php, shipping-seo.php, vendor-staff.php.
 * ====================================================================== */
function zymarg_vd_support_native_section( $sections ) {
	$sections = is_array( $sections ) ? $sections : array();
	if ( ! in_array( 'support', $sections, true ) ) {
		$sections[] = 'support';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_support_native_section' );


/* ====================================================================== *
 * 10.5 — Native Support section renderer (v1.46.3).
 *
 * BEFORE v1.46.3, the 'support' section key had no renderer, so clicking
 * "Support" in the sidebar fell through the switch in
 * zymarg_vd_render_section_content() to the "coming soon" placeholder —
 * which showed as a blank card. This function fixes that by rendering the
 * exact same two-card layout as the theme's My Account -> Support panel,
 * gated by the two Feature Flags added to the settings screen
 * (support_contact_card, support_help_card).
 *
 * WIRING:
 *   - Contact Support card is a <button data-zymarg-support-start> — the
 *     ZYMARG Communication plugin's support.js hydrates it and opens (or
 *     resumes) the seller's admin thread inline right below.
 *   - Help Center card is a plain <a>, only rendered when both its Feature
 *     Flag is ON and the Help Center URL option is a non-empty URL.
 *   - Inline inbox uses .zymarg-vd-support-inbox as its wrapper so its CSS
 *     overrides (hide filter tabs, hide search, single-column layout,
 *     smaller typing indicator) cannot leak into the main Messages inbox
 *     or the buyer inbox on My Account.
 *
 * DEPENDENCIES:
 *   - ZYMARG Communication plugin (optional but expected) for the button
 *     hydration and REST endpoint. Wrapped in shortcode_exists() so this
 *     function degrades gracefully when the plugin is not installed.
 *   - Feature flags fall through zymarg_vd_feature_enabled() so any custom
 *     override via the `zymarg_vd_feature_enabled` filter still applies.
 *
 * @param WP_User $user The current vendor user.
 * @return string HTML.
 * ====================================================================== */
function zymarg_vd_render_support_section( $user ) {
	$show_contact = function_exists( 'zymarg_vd_feature_enabled' )
		? zymarg_vd_feature_enabled( 'support_contact_card' )
		: true;
	$help_url     = trim( (string) get_option( 'zymarg_vd_support_help_url', '' ) );
	$show_help    = function_exists( 'zymarg_vd_feature_enabled' )
		? zymarg_vd_feature_enabled( 'support_help_card' )
		: false;
	$show_help    = $show_help && '' !== $help_url;

	$comm_active  = defined( 'ZYMARG_COMM_VERSION' ) && shortcode_exists( 'zymarg_support_chat' );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zvd-support-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Support', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Get help with your orders, account, or any issues.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<?php if ( ! $show_contact && ! $show_help ) : ?>

	<section class="zymarg-vendor-card zymarg-vendor-soon zvd-support-card">
		<?php
		if ( function_exists( 'zymarg_vd_spark' ) ) {
			echo zymarg_vd_spark( array( 'size' => 'xl', 'label' => 'ZYMARG' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<h2><?php esc_html_e( 'Support is currently off', 'zymarg-vendor-dashboard' ); ?></h2>
		<p><?php esc_html_e( 'Enable the Contact Support and / or Help Center card under ZYMARG Vendor → Feature Flags to bring this section online.', 'zymarg-vendor-dashboard' ); ?></p>
	</section>

	<?php else : ?>

	<?php /* v1.46.10 — Tiles now float directly under the greeting, matching the
	          theme's My Account -> Support layout. Dropping the wrapping
	          .zymarg-vendor-card (and its "How can we help?" heading) means the
	          greeting reads as the section's title and the tiles as its content,
	          which is how every other action-card surface on the marketplace
	          reads. Kept the .zvd-support-tiles wrapper so the tiles group
	          themselves into a grid and so the paired margin-bottom rule below
	          can space them away from the inline inbox that follows. */ ?>
	<div class="zvd-support-tiles">
		<?php if ( $show_contact ) : ?>
		<button
			type="button"
			class="zvd-support-tile"
			data-zymarg-support-start
			data-rest="<?php echo esc_attr( esc_url_raw( rest_url( 'zymarg-comm/v1' ) ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-inline-target="#zymarg-vd-support-inbox"
			data-busy-label="<?php esc_attr_e( 'Opening…', 'zymarg-vendor-dashboard' ); ?>"
			data-error-label="<?php esc_attr_e( 'Could not open support. Please try again.', 'zymarg-vendor-dashboard' ); ?>"
		>
			<span class="zvd-support-tile__icon" aria-hidden="true"><?php echo zymarg_os_vendor_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="zvd-support-tile__body">
				<span class="zvd-support-tile__title"><?php esc_html_e( 'Contact Support', 'zymarg-vendor-dashboard' ); ?></span>
			</span>
		</button>
		<?php endif; ?>

		<?php if ( $show_help ) : ?>
		<a class="zvd-support-tile" href="<?php echo esc_url( $help_url ); ?>">
			<span class="zvd-support-tile__icon" aria-hidden="true"><?php echo zymarg_os_vendor_icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="zvd-support-tile__body">
				<span class="zvd-support-tile__title"><?php esc_html_e( 'Help Center', 'zymarg-vendor-dashboard' ); ?></span>
				<span class="zvd-support-tile__desc"><?php esc_html_e( 'FAQs and guides', 'zymarg-vendor-dashboard' ); ?></span>
			</span>
		</a>
		<?php endif; ?>
	</div>

	<?php if ( $show_contact && $comm_active ) : ?>
	<section id="zymarg-vd-support-inbox" class="zymarg-vendor-card zvd-support-card zvd-support-inbox" hidden>
		<?php echo zymarg_vd_support_inbox_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</section>
	<?php elseif ( $show_contact && ! $comm_active ) : ?>
	<section class="zymarg-vendor-card zvd-support-card zvd-support-notice">
		<p><?php esc_html_e( 'Contact Support is powered by the ZYMARG Communication plugin. Install and activate it to enable admin messaging on this dashboard.', 'zymarg-vendor-dashboard' ); ?></p>
	</section>
	<?php endif; ?>

	<?php endif; // end show_contact || show_help ?>

	<style id="zvd-support-css">
	/* ============================================================================
	   ZYMARG Vendor Dashboard — Support section (v1.46.6)

	   Design-token discipline: EVERY spacing / colour / radius / shadow value
	   here references a plugin token, with the token's own fallback chain.
	   Zero magic numbers except where the plugin itself has none (icon square
	   sizing, tile grid min-column).

	   Namespace: .zvd-support-* — deliberately DIFFERENT from the settings
	   hub's .zymarg-vs-* namespace, which owns 208 selectors including its
	   own .zymarg-vs-card with a gradient bar. Anything I add here needs to
	   be uniquely prefixed so the two never conflict.

	   Token map (values shown are the plugin's :root defaults):
	     --zv-gap:        20px  — card-to-card spacing
	     --zv-gap-inner:  12px  — inside-card row / tile spacing
	     --zv-radius:     18px  — card corner radius
	     --zv-card-bg:  surface-lowest colour token
	     --zv-border:   outline-variant colour token
	     --zv-shadow:   card shadow tuple
	   ============================================================================ */

	/* Every Support card gets --zv-gap of breathing room below it, matching
	   the plugin's own convention (see .zymarg-vendor-grid { gap: var(--zv-gap) }
	   and .zymarg-vendor-stats { gap: var(--zv-gap); margin-bottom: var(--zv-gap) }).
	   Scoped via .zvd-support-card so no other card in the plugin is touched
	   AND the rule survives without the previous wrapper div — my markup now
	   sits directly inside .zymarg-vendor-content, matching every other
	   section's structure. */
	.zvd-support-card { margin-bottom: var(--zv-gap, 20px); }
	.zvd-support-card:last-of-type { margin-bottom: 0; }

	/* v1.46.11 — Support section greeting subtitle.
	   The shared .zymarg-vendor-greeting__sub is `.98rem` (15.68px at 16px root),
	   which wraps "Get help with your orders, account, or any issues." to two
	   lines on 375px viewports. The theme's My Account .panel-desc is 14px
	   and fits on one line at the same width. Matching the theme size here,
	   scoped via .zvd-support-greeting so the base greeting on Dashboard /
	   Orders / Earnings / etc. is untouched — those subtitles ship as-is on
	   purpose. The class is added on the Support-section header only. */
	.zvd-support-greeting .zymarg-vendor-greeting__sub {
		font-size: 14px;
		line-height: 1.6;
	}

	/* ==========================================================================
	   Tile grid — v1.46.10 rewrite, now matches the theme's My Account
	   -> Support layout byte-for-byte.

	   Before this release the tiles used the plugin's .zymarg-vendor-stat
	   shell (20px padding, 46px icon square, --zv-radius/--zv-shadow) so a
	   Contact/Help pair rendered as two large stat-card-sized panels. The
	   theme's My Account uses .action-card at half that scale — a compact
	   38x38 icon and 13/11px text — and the user preferred the compact
	   look. Every value below is aligned to the theme's action-card:
	     - grid: 1 col base, 2 cols >=480, 3 cols >=768 (same breakpoints)
	     - grid gap: 12px, matches .cards-grid
	     - tile: 12px radius, 1.25rem padding, 12px inner gap
	     - icon square: 38x38, 8px radius, --color-surface-container fill
	     - title: 13px / 600
	     - desc:  11px / muted
	     - hover: lift + primary border, same 6px shadow tint

	   Namespaced .zvd-support-* rather than reusing .action-card so the
	   plugin never depends on the theme. On the ZYMARG OS theme the two
	   surfaces render identically because the shape and tokens are the
	   same; on any other theme the plugin owns its own copy of the CSS
	   and the design still holds.
	   ========================================================================== */
	.zvd-support-tiles {
		display: grid;
		grid-template-columns: 1fr;
		gap: 12px;
		margin-bottom: var(--zv-gap, 20px);
	}
	@media (min-width: 480px) { .zvd-support-tiles { grid-template-columns: repeat(2, 1fr); } }
	@media (min-width: 768px) { .zvd-support-tiles { grid-template-columns: repeat(3, 1fr); } }

	.zvd-support-tile {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 1.25rem;
		/* --zv-radius (18px). Same value the theme's `body.zymarg-myaccount-active`
		   override bumps .action-card to, so the two surfaces match the shape
		   a user actually sees on My Account, not the base .action-card default. */
		border-radius: var(--zv-radius, 18px);
		background: var(--zv-card-bg, var(--color-surface-lowest, #fff));
		border: 1px solid var(--zv-border, var(--color-outline-variant, #d8bfd3));
		text-decoration: none;
		cursor: pointer;
		font: inherit;
		/* text-align:start normalises <button>'s default text-align:center so
		   the button and anchor tiles render identically. Same fix landed in
		   the theme's .action-card in 5.16.8. */
		text-align: start;
		color: inherit;
		transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease, background .2s ease;
	}
	.zvd-support-tile:hover,
	.zvd-support-tile:focus-visible {
		transform: translateY(-2px);
		border-color: var(--color-primary, var(--zym-color-primary, #9500A5));
		box-shadow: 0 6px 20px rgba(149, 0, 165, .08);
		background: var(--color-surface-lowest, #fff);
		outline: none;
	}

	.zvd-support-tile__icon {
		display: inline-flex;
		flex: 0 0 38px;
		width: 38px;
		height: 38px;
		align-items: center;
		justify-content: center;
		/* 13px matches the theme's `body.zymarg-myaccount-active` icon override
		   (.action-card .ac-icon), which is also .zymarg-vendor-stat__icon's
		   radius — the icon tile shape is consistent across every surface. */
		border-radius: 13px;
		background: var(--color-surface-container, var(--zym-color-container, #EAEDFF));
		color: var(--color-primary, var(--zym-color-primary, #9500A5));
	}
	.zvd-support-tile__icon svg {
		width: 18px;
		height: 18px;
		/* v1.46.11 — force stroke-width to 2 so the tile icon matches the
		   theme's My Account tile icon exactly. zymarg_os_vendor_icon() sets
		   1.9 on every icon (globally correct for the plugin's own sidebar
		   and stat-card icons), and zymarg_os_account_icon() over in the
		   theme sets 2 on this one. CSS beats the SVG attribute, so the
		   override lives here and only in the Support tile — every other
		   icon in the plugin still renders at 1.9. */
		stroke-width: 2;
	}

	.zvd-support-tile__body {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}
	.zvd-support-tile__title {
		/* v1.46.11 — one-notch bump from 13px so "Contact Support" doesn't
		   sit dwarfed by the 38x38 icon square next to it. Matching change
		   in the theme's .action-card .ac-label at 5.16.9 keeps parity. */
		font-size: 14px;
		font-weight: 600;
		color: var(--color-text-body, var(--zym-color-text-body, #131b2e));
	}
	.zvd-support-tile__desc {
		font-size: 11px;
		color: var(--color-text-muted, var(--zym-color-neutral-text, #534152));
	}

	/* ---- Inline inbox card ------------------------------------------------
	   Overrides the .zymarg-vendor-card padding because the inbox is a
	   self-contained component that renders its own internal chrome. Kept
	   scoped so no other card in the plugin is affected. */
	.zvd-support-inbox { padding: 0; overflow: hidden; }
	.zvd-support-inbox[hidden] { display: none; }

	/* v1.46.9 — companion to the .is-host-chrome opt-out in
	   zymarg_vd_support_inbox_html(), which v1.46.13 made conditional on the
	   Communication plugin being older than 1.32.11. Both declarations below
	   stay correct either way: with the class the inbox must drop its own frame
	   so this card can be the chrome, and without it (1.32.11+, where the
	   Communication plugin flattens the surface itself) they simply agree with
	   what that plugin already does. Two consequences to absorb:

	   1. Until v1.46.8 the Communication plugin flattened our nested inbox with
	      `.zymarg-vendor-main .zymarg-inbox:not(.is-host-chrome){border-radius:0}`.
	      Opting out of that selector restores the inbox's own 1px border, radius
	      and shadow — which, inside a .zymarg-vendor-card that already draws all
	      three, reads as a double-walled box. THIS card is the chrome, so the
	      inbox drops its own and becomes the card's body.
	   2. `.zymarg-inbox.is-host-chrome` sizes itself with
	      `calc(100dvh - --zi-offset - --zi-gutter)`. The Communication plugin only
	      publishes --zi-gutter for its --vendor and --buyer variants, not --support,
	      so ours would fall back to a hardcoded 24px. Point it at the same value
	      .zymarg-vendor-main is padded with so the inbox closes on the identical
	      gutter every other panel ends on. */
	.zvd-support-inbox .zymarg-inbox {
		--zi-gutter: clamp(16px, 3vw, 28px);
		border: 0;
		border-radius: 0;
		box-shadow: none;
	}
	.zvd-support-inbox .zymarg-inbox__filters,
	.zvd-support-inbox .zymarg-inbox__search-toggle,
	.zvd-support-inbox .zymarg-inbox__search,
	.zvd-support-inbox .zymarg-inbox__list { display: none !important; }
	.zvd-support-inbox .zymarg-inbox__body { grid-template-columns: 1fr; }
	.zvd-support-inbox .zymarg-inbox__thread { width: 100%; }
	.zvd-support-inbox .zymarg-inbox__header { padding: 12px 16px; }
	.zvd-support-inbox .zymarg-inbox__title { font-size: 15px; }

	/* Smaller typing indicator so "Alpha is typing…" reads as an inline
	   status line rather than a chat bubble. */
	.zvd-support-inbox .zymarg-inbox__typing { padding: 0 16px 4px; }
	.zvd-support-inbox .zymarg-inbox__typing-dots { padding: 2px 6px; background: transparent; gap: 2px; }
	.zvd-support-inbox .zymarg-inbox__typing-dots i { width: 4px; height: 4px; }

	.zvd-support-notice p {
		margin: 0;
		color: var(--color-text-muted, var(--zym-color-neutral-text, #646970));
	}
	</style>

	<?php
	return (string) ob_get_clean();
}

/**
 * 10.5b — Inline support inbox markup, with the host-chrome opt-out applied.
 *
 * BUG FIX for v1.46.9 — "the Support section has no gap on top or at the sides".
 *
 * The ZYMARG Communication plugin ships this pair of rules (inbox.css, added in
 * its 1.24.14) so the *Messages* section can render its inbox edge to edge:
 *
 *     .zymarg-vendor-main:has(.zymarg-inbox:not(.is-host-chrome))    { padding: 0 }
 *     .zymarg-vendor-content:has(> .zymarg-inbox:not(.is-host-chrome)) { max-width: none; margin: 0 }
 *
 * Note the asymmetry: the second selector is scoped to a DIRECT child (`>`),
 * but the first is not. So the first matches whenever a `.zymarg-inbox` exists
 * ANYWHERE inside the column — including ours, which is deliberately nested
 * inside a `.zymarg-vendor-card`. That zeroed `.zymarg-vendor-main`'s
 * `clamp(16px, 3vw, 28px)` frame padding for the whole Support section, so the
 * greeting and the "How can we help?" card sat flush against the sidebar and
 * overflowed the right edge — while every other section kept its 28px frame.
 *
 * Worse, it applied on first paint: our inbox ships `hidden`, but `:has()`
 * matches on DOM presence, not on whether a node renders. Nothing had to be
 * clicked for the layout to break.
 *
 * The Communication plugin documents `.is-host-chrome` as the opt-out for
 * exactly this case — "the host has already drawn its own section heading, so
 * the inbox stops presenting itself as a standalone page and becomes one card
 * inside a normal page". That is precisely our situation: we draw the
 * `.zymarg-vendor-greeting` and the card wrapper, so the inbox does not own the
 * column. Adding the class is therefore the sanctioned fix, and it means the
 * Communication plugin is never edited.
 *
 * The class is injected after do_shortcode() because [zymarg_support_chat] has
 * no host_chrome attribute — SupportChat::renderInline() calls inboxRoot()
 * without it. The regex is anchored so it can only ever hit the inbox ROOT:
 *   - `(?![\w-])` rejects `zymarg-inbox__header`, `zymarg-inbox--support`, etc.
 *   - the `is-host-chrome` lookahead makes it idempotent.
 *   - the limit of 1 stops at the outermost node, which is emitted first.
 * If upstream ever renames the class the regex simply no-ops and we are back to
 * today's behaviour rather than emitting broken markup.
 *
 * @return string Inline support inbox HTML, or '' when the shortcode is absent.
 */
function zymarg_vd_support_inbox_html() {
	$html = do_shortcode(
		'[zymarg_support_chat mode="inline" title="' . esc_attr__( 'Contact Support', 'zymarg-vendor-dashboard' ) . '"]'
	);

	if ( '' === trim( (string) $html ) ) {
		return '';
	}

	/*
	 * v1.46.13 — STOP injecting the class on Communication 1.32.11+.
	 *
	 * The workaround above was aimed at the right symptom but the wrong layer.
	 * The actual cause was that the Communication plugin's own rule matched any
	 * `.zymarg-inbox` in the column on DOM PRESENCE:
	 *
	 *     .zymarg-vendor-main:has(.zymarg-inbox:not(.is-host-chrome)){padding:0}
	 *
	 * That plugin fixed it at source in 1.32.11: the rule is now scoped to the
	 * Messages variant (`.zymarg-inbox--vendor`), and the support surface is
	 * governed by rules gated on `.is-support-live`, a class its support.js sets
	 * only while the inbox is genuinely on screen. So the frame padding is no
	 * longer stripped on first paint and there is nothing left to opt out of.
	 *
	 * Continuing to inject was actively harmful once SupportChat learned to read
	 * `surface.host_native_chrome` itself (Communication 1.32.9): forcing the
	 * class OVERRODE that setting, pinning the seller support inbox into card
	 * mode no matter how the toggle was set, while the buyer support inbox
	 * followed it. Same plugin, same setting, two different results — the seller
	 * surface could never match the buyer one. Letting the shortcode's own
	 * output stand makes the setting authoritative on both sides.
	 *
	 * The injection is KEPT for older Communication builds, which have neither
	 * the scoped rule nor the `.is-support-live` gate, and where the first-paint
	 * bug this function was written for is therefore still real.
	 */
	$comm_scopes_by_variant = defined( 'ZYMARG_COMM_VERSION' )
		&& version_compare( (string) ZYMARG_COMM_VERSION, '1.32.11', '>=' );

	if ( $comm_scopes_by_variant ) {
		return $html;
	}

	$patched = preg_replace(
		'/class="zymarg-inbox(?![\w-])(?![^"]*is-host-chrome)([^"]*)"/',
		'class="zymarg-inbox$1 is-host-chrome"',
		$html,
		1
	);

	// preg_replace() returns null on error — never ship a blank inbox for it.
	return null === $patched ? $html : $patched;
}


/* ====================================================================== *
 * Native Settings page (v1.28.0) — accordion JS enqueue + AJAX endpoints.
 *
 * The accordion shell is rendered by includes/settings-hub.php. Sections
 * 1 (Account), 2 (Change Password) and 3 (Notification Preferences) are
 * the real ones and post here; sections 4-11 are placeholder cards.
 *
 * All three endpoints share:
 *   - action prefix : wp_ajax_zymarg_vd_settings_*
 *   - nonce         : ZymargVendor.nonce (action 'zymarg_vendor_action')
 *   - capability    : zymarg_os_can_view_vendor_dashboard() OR admin
 * ====================================================================== */

/**
 * Enqueue assets/js/vendor-settings.js on the vendor-dashboard context.
 *
 * Mirrors the pattern used by store-settings / payouts / shipping-seo (they
 * enqueue on `zymarg_os_vendor_enqueue_assets`, not section-scoped, so the
 * script is present for both the initial full page load and every SPA swap).
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_settings_page_enqueue( $ver ) {
	// Respect the Settings feature toggle so admins can turn it off.
	if ( function_exists( 'zymarg_vd_feature_enabled' ) && ! zymarg_vd_feature_enabled( 'settings' ) ) {
		return;
	}
	// v1.29.0 — Section 7 (SEO) uses wp.media() to pick the OG image.
	// It's cheap to call unconditionally; wp_enqueue_media() no-ops if
	// media JS is already registered on the current page.
	if ( function_exists( 'wp_enqueue_media' ) ) {
		wp_enqueue_media();
	}
	wp_enqueue_script(
		'zymarg-vd-vendor-settings',
		ZYMARG_VD_URL . 'assets/js/vendor-settings.js',
		array( 'jquery', 'zymarg-os-vendor-dashboard' ),
		$ver,
		true
	);
	wp_localize_script(
		'zymarg-vd-vendor-settings',
		'ZymargVendorSettings',
		array(
			'i18n' => array(
				'saving'         => __( 'Saving…', 'zymarg-vendor-dashboard' ),
				'saved'          => __( 'Saved.', 'zymarg-vendor-dashboard' ),
				'error'          => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
				'pwMismatch'     => __( 'The new passwords do not match.', 'zymarg-vendor-dashboard' ),
				'pwTooShort'     => __( 'Password must be at least 8 characters.', 'zymarg-vendor-dashboard' ),
				'phoneInvalid'   => __( 'Please enter a valid 10-digit phone number.', 'zymarg-vendor-dashboard' ),
				'emailInvalid'   => __( 'Please enter a valid email address.', 'zymarg-vendor-dashboard' ),
				'emailPwNeeded'  => __( 'Enter your current password to change your email.', 'zymarg-vendor-dashboard' ),
				'showPassword'   => __( 'Show password', 'zymarg-vendor-dashboard' ),
				'hidePassword'   => __( 'Hide password', 'zymarg-vendor-dashboard' ),
				'passwordUpdated'=> __( 'Password updated.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_settings_page_enqueue' );

/**
 * Small guard shared by every Settings AJAX endpoint.
 *
 * Verifies the shared 'zymarg_vendor_action' nonce and checks the caller can
 * view the vendor dashboard (vendor / staff / admin). On failure it responds
 * with a JSON error and exits — otherwise returns the current user ID.
 *
 * @return int
 */
function zymarg_vd_settings_ajax_guard() {
	if ( ! check_ajax_referer( 'zymarg_vendor_action', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Session expired. Please refresh and try again.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	$can_view = function_exists( 'zymarg_os_can_view_vendor_dashboard' ) && zymarg_os_can_view_vendor_dashboard();
	if ( ! $can_view && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	return (int) get_current_user_id();
}

/**
 * AJAX: save Section 1 (Account) — display name, email, phone.
 *
 * Email changes require the caller to submit their current password so a
 * stolen session cookie cannot silently repoint the account. Phone is
 * stored digits-only in user_meta `_zymarg_vd_phone` (10 digits, without
 * the +880 country code that's rendered as a static prefix in the UI).
 *
 * @return void
 */
function zymarg_vd_ajax_save_account() {
	$user_id = zymarg_vd_settings_ajax_guard();
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => __( 'Account not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
	$email_in     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone_in     = isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '';
	$current_pw   = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';

	if ( '' === $display_name ) {
		wp_send_json_error( array( 'message' => __( 'Display name cannot be empty.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// Phone: digits only, must be exactly 10 digits (post +880 country code).
	$phone_digits = preg_replace( '/\D+/', '', (string) $phone_in );
	if ( '' !== $phone_digits && 10 !== strlen( $phone_digits ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid 10-digit phone number.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// Email: only touch WP if it actually changed (avoid needless password prompts).
	$email_changed = false;
	if ( $email_in && $email_in !== $user->user_email ) {
		if ( ! is_email( $email_in ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'zymarg-vendor-dashboard' ) ), 400 );
		}
		if ( '' === $current_pw ) {
			wp_send_json_error( array( 'message' => __( 'Enter your current password to change your email.', 'zymarg-vendor-dashboard' ) ), 400 );
		}
		if ( ! wp_check_password( $current_pw, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => __( 'Current password is incorrect.', 'zymarg-vendor-dashboard' ) ), 403 );
		}
		if ( email_exists( $email_in ) && (int) email_exists( $email_in ) !== $user->ID ) {
			wp_send_json_error( array( 'message' => __( 'That email is already in use.', 'zymarg-vendor-dashboard' ) ), 400 );
		}
		$email_changed = true;
	}

	$update = array(
		'ID'           => $user->ID,
		'display_name' => $display_name,
	);
	if ( $email_changed ) {
		$update['user_email'] = $email_in;
	}
	$result = wp_update_user( $update );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
	}

	if ( '' === $phone_digits ) {
		delete_user_meta( $user->ID, '_zymarg_vd_phone' );
	} else {
		update_user_meta( $user->ID, '_zymarg_vd_phone', $phone_digits );
	}

	$refreshed = get_user_by( 'id', $user->ID );
	wp_send_json_success(
		array(
			'message'      => __( 'Account saved.', 'zymarg-vendor-dashboard' ),
			'display_name' => $refreshed ? $refreshed->display_name : $display_name,
			'email'        => $refreshed ? $refreshed->user_email : $email_in,
			'phone'        => $phone_digits,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_account', 'zymarg_vd_ajax_save_account' );

/**
 * AJAX: Section 2 (Change Password).
 *
 * Verifies the current password, enforces a ≥ 8-char new password that
 * matches the confirmation field, then rotates the password and re-signs
 * the caller in so they don't get bounced to wp-login. If ZLS is active
 * we also ask it to invalidate every OTHER session for defense in depth.
 *
 * @return void
 */
function zymarg_vd_ajax_change_password() {
	$user_id = zymarg_vd_settings_ajax_guard();
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => __( 'Account not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	$current_pw = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
	$new_pw     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
	$confirm_pw = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

	if ( '' === $current_pw || '' === $new_pw || '' === $confirm_pw ) {
		wp_send_json_error( array( 'message' => __( 'All password fields are required.', 'zymarg-vendor-dashboard' ) ), 400 );
	}
	if ( strlen( $new_pw ) < 8 ) {
		wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'zymarg-vendor-dashboard' ) ), 400 );
	}
	if ( $new_pw !== $confirm_pw ) {
		wp_send_json_error( array( 'message' => __( 'The new passwords do not match.', 'zymarg-vendor-dashboard' ) ), 400 );
	}
	if ( ! wp_check_password( $current_pw, $user->user_pass, $user->ID ) ) {
		wp_send_json_error( array( 'message' => __( 'Current password is incorrect.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	// Rotate the password. wp_set_password() also destroys ALL sessions,
	// so we immediately re-issue an auth cookie for the current request
	// or the user gets 401'd on their very next XHR.
	wp_set_password( $new_pw, $user->ID );
	wp_set_auth_cookie( $user->ID, true );
	wp_set_current_user( $user->ID );

	// ZLS bridge: if the theme's Login & Security module is around, tell it
	// to explicitly nuke any other sessions too (belt + suspenders).
	if ( function_exists( 'zls_destroy_other_sessions' ) ) {
		zls_destroy_other_sessions( $user->ID );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Password updated.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_change_password', 'zymarg_vd_ajax_change_password' );

/**
 * AJAX: Section 3 (Notification Preferences).
 *
 * Accepts a { event: { email: 0|1, push: 0|1, sms: 0|1 } } payload, walks the
 * known event registry (so unknown keys can't leak into user_meta), casts
 * every channel to bool, and persists the result. Consumers read the meta
 * via `zymarg_vd_settings_get_notification_prefs()`. SMS is stored today
 * even though live delivery is not yet wired up — the preference is future
 * proof for when the SMS gateway is enabled.
 *
 * @return void
 */
function zymarg_vd_ajax_save_notifications() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard.
	$raw_prefs = isset( $_POST['prefs'] ) ? wp_unslash( $_POST['prefs'] ) : array();
	if ( ! is_array( $raw_prefs ) ) {
		$raw_prefs = array();
	}

	$events = function_exists( 'zymarg_vd_settings_notification_events' )
		? zymarg_vd_settings_notification_events()
		: array();

	$clean = array();
	foreach ( array_keys( $events ) as $event_key ) {
		$row = isset( $raw_prefs[ $event_key ] ) && is_array( $raw_prefs[ $event_key ] )
			? $raw_prefs[ $event_key ]
			: array();
		$clean[ $event_key ] = array(
			'email' => ! empty( $row['email'] ),
			'push'  => ! empty( $row['push'] ),
			'sms'   => ! empty( $row['sms'] ),
		);
	}

	update_user_meta( $user_id, '_zymarg_vd_notification_prefs', $clean );

	wp_send_json_success(
		array(
			'message' => __( 'Preferences saved.', 'zymarg-vendor-dashboard' ),
			'prefs'   => $clean,
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_notifications', 'zymarg_vd_ajax_save_notifications' );


/* ====================================================================== *
 * v1.29.0 — Settings AJAX endpoints for sections 4-7, plus the runtime
 * side-effects that consume the meta they persist (min-order enforcement,
 * default order note attachment, auto-accept cron).
 *
 * All four endpoints share the guard + response shape of Sections 1-3, so
 * the JS in vendor-settings.js can talk to them with the same helper.
 * ====================================================================== */

/**
 * AJAX: Section 4 (Store Preferences).
 *
 * Persists three vendor-level preferences:
 *   - auto-accept toggle
 *   - minimum order value (BDT integer)
 *   - default order note template (<=500 chars)
 *
 * Empty min value falls back to 0 (== disabled). Empty note deletes the meta.
 * The auto-accept meta is also mirrored to a global option flag so the cron
 * knows whether ANY vendor still has it on before touching pending orders.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_save_store_preferences() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	$auto_accept = ! empty( $_POST['auto_accept'] ) ? 1 : 0;
	$min_raw     = isset( $_POST['min_order_value'] ) ? wp_unslash( $_POST['min_order_value'] ) : '';
	$note_raw    = isset( $_POST['default_order_note'] ) ? (string) wp_unslash( $_POST['default_order_note'] ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$min = 0;
	if ( '' !== trim( (string) $min_raw ) ) {
		$min = (int) $min_raw;
		if ( $min < 0 ) {
			$min = 0;
		}
	}

	$note = sanitize_textarea_field( $note_raw );
	if ( function_exists( 'mb_substr' ) ) {
		$note = mb_substr( $note, 0, 500 );
	} else {
		$note = substr( $note, 0, 500 );
	}

	update_user_meta( $user_id, '_zymarg_vd_auto_accept_orders', $auto_accept );
	if ( $min > 0 ) {
		update_user_meta( $user_id, '_zymarg_vd_min_order_value', $min );
	} else {
		delete_user_meta( $user_id, '_zymarg_vd_min_order_value' );
	}
	if ( '' !== $note ) {
		update_user_meta( $user_id, '_zymarg_vd_default_order_note', $note );
	} else {
		delete_user_meta( $user_id, '_zymarg_vd_default_order_note' );
	}

	// Update the "any vendor on auto-accept?" global flag + cron schedule.
	zymarg_vd_auto_accept_refresh_schedule();

	wp_send_json_success(
		array(
			'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_store_preferences', 'zymarg_vd_ajax_settings_save_store_preferences' );

/**
 * AJAX: Section 5 (Store Profile).
 *
 * Persists the vendor's public storefront profile into Dokan's own
 * `dokan_profile_settings` user meta (read → mutate → write, so unrelated
 * keys like `social` from Section 8 are never clobbered): store name,
 * structured address, and Vacation mode (toggle + message + "also disable
 * Add to cart" flag). Vacation writes to the CORRECT key,
 * `setting_go_vacation` — this is now the only vacation control in the
 * plugin; the real storefront effects in `zymarg_vd_vacation_product_notice()`
 * / `zymarg_vd_vacation_purchasable()` (store-settings.php) read this exact
 * key, so the toggle here is actually wired to what shoppers see.
 *
 * v1.33.0: this handler NO LONGER touches `phone` or `show_email` — see
 * the docblock on zymarg_vd_render_settings_card_store_profile() above for
 * why those fields were removed entirely (they displayed real vendor
 * contact details to customers on the public storefront). It also no
 * longer touches `banner` — the banner now uploads instantly via the same
 * crop+compress flow as the Section 1 avatar (zymarg_vd_upload_store_image_ajax(),
 * generalized in v1.33.0 to take a `target` of 'avatar' or 'banner'), not
 * as a deferred field inside this form.
 *
 * When Dokan Pro's own seller-vacation module is active, the vacation
 * fields are not posted (the form doesn't render them) and any existing
 * vacation keys are left untouched — Pro owns that instead.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_save_store_profile() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	// Enforce the word limits before anything is written, so a rejected save
	// leaves the stored copy exactly as it was. The browser checks the same
	// limits for instant feedback, but that check is only a convenience: this
	// is the one that actually protects the store page layout.
	$story_limits = zymarg_vd_story_limits();
	foreach ( $story_limits as $field => $rule ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			continue;
		}

		$words = zymarg_vd_count_words( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidationSanitization

		// Empty clears the field, which is always allowed.
		if ( 0 === $words ) {
			continue;
		}

		if ( $words < (int) $rule['min'] ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: field name, 2: minimum words, 3: words written. */
						__( '%1$s needs at least %2$d words. You wrote %3$d.', 'zymarg-vendor-dashboard' ),
						$rule['label'],
						(int) $rule['min'],
						$words
					),
					'field'   => $field,
				)
			);
		}

		if ( $words > (int) $rule['max'] ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: field name, 2: maximum words, 3: words written. */
						__( '%1$s can be at most %2$d words. You wrote %3$d.', 'zymarg-vendor-dashboard' ),
						$rule['label'],
						(int) $rule['max'],
						$words
					),
					'field'   => $field,
				)
			);
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$profile = get_user_meta( $user_id, 'dokan_profile_settings', true );
	$profile = is_array( $profile ) ? $profile : array();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	if ( isset( $_POST['store_name'] ) ) {
		$profile['store_name'] = sanitize_text_field( wp_unslash( $_POST['store_name'] ) );
	}

	// Store tagline and story.
	//
	// Tagline and the short story go into Dokan's own profile keys, which the
	// store page template already reads. Both are deliberately allowed to be
	// saved empty: an empty story is how a seller hides the Our Story block.
	if ( isset( $_POST['store_tagline'] ) ) {
		$profile['store_tagline'] = sanitize_text_field( wp_unslash( $_POST['store_tagline'] ) );
	}
	if ( isset( $_POST['story_short'] ) ) {
		$profile['store_description'] = sanitize_textarea_field( wp_unslash( $_POST['story_short'] ) );
	}

	// Address.
	if ( isset( $_POST['address'] ) && is_array( $_POST['address'] ) ) {
		$in   = wp_unslash( $_POST['address'] ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$keys = array( 'street_1', 'street_2', 'city', 'zip', 'state', 'country' );
		$addr = isset( $profile['address'] ) && is_array( $profile['address'] ) ? $profile['address'] : array();
		foreach ( $keys as $k ) {
			$addr[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		}
		$profile['address'] = $addr;
	}

	// Vacation — only when Dokan Pro isn't already managing it (its own
	// module owns these keys in that case, and the form doesn't post them).
	$managed_by_pro = function_exists( 'zymarg_vd_vacation_managed_by_pro' ) && zymarg_vd_vacation_managed_by_pro();
	if ( ! $managed_by_pro ) {
		$profile['setting_go_vacation']          = ! empty( $_POST['vacation_on'] ) ? 'yes' : 'no';
		$profile['setting_vacation_message']     = isset( $_POST['vacation_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vacation_message'] ) ) : '';
		$profile['zymarg_vacation_disable_cart'] = ! empty( $_POST['vacation_disable_cart'] ) ? 'yes' : 'no';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	update_user_meta( $user_id, 'dokan_profile_settings', $profile );

	// Mirror store name to the meta key some themes read (same as the old
	// standalone Store Settings screen used to do).
	if ( isset( $profile['store_name'] ) && '' !== $profile['store_name'] ) {
		update_user_meta( $user_id, 'dokan_store_name', $profile['store_name'] );
	}

	// Story headline and the Read More continuation have no Dokan equivalent,
	// so they live in their own user meta. Empty values are written rather
	// than skipped, otherwise a seller could never clear what they wrote.
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	if ( isset( $_POST['story_headline'] ) ) {
		update_user_meta( $user_id, '_zymarg_vd_story_headline', sanitize_text_field( wp_unslash( $_POST['story_headline'] ) ) );
	}
	if ( isset( $_POST['story_more'] ) ) {
		update_user_meta( $user_id, '_zymarg_vd_story_more', sanitize_textarea_field( wp_unslash( $_POST['story_more'] ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Fires after Section 5 "Store Profile" is saved.
	 *
	 * @param int   $user_id User ID.
	 * @param array $profile Saved profile.
	 */
	do_action( 'zymarg_vd_store_settings_saved', $user_id, $profile );

	wp_send_json_success(
		array(
			'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_store_profile', 'zymarg_vd_ajax_settings_save_store_profile' );

/**
 * v1.33.0 — One-time cleanup: force-clear `phone` and `show_email` from
 * EVERY vendor's `dokan_profile_settings`, so nobody who already saved a
 * value in the now-removed "Public phone" / "Show my email" fields (e.g.
 * during this plugin's pre-launch trial) keeps leaking that data on the
 * public storefront after this version installs. Runs at most once —
 * gated on the option below — and only clears those two keys; every other
 * `dokan_profile_settings` key (store_name, address, banner, social,
 * vacation, etc.) is left completely untouched.
 *
 * @return void
 */
function zymarg_vd_scrub_public_contact_details() {
	$done = get_option( 'zymarg_vd_contact_scrub_1_33_0' );
	if ( $done ) {
		return;
	}

	$vendors = get_users(
		array(
			'role__in' => array( 'seller', 'vendor' ),
			'fields'   => 'ID',
			'number'   => -1,
		)
	);

	foreach ( $vendors as $vendor_id ) {
		$profile = get_user_meta( (int) $vendor_id, 'dokan_profile_settings', true );
		if ( ! is_array( $profile ) ) {
			continue;
		}
		$changed = false;
		if ( isset( $profile['phone'] ) && '' !== $profile['phone'] ) {
			$profile['phone'] = '';
			$changed          = true;
		}
		if ( isset( $profile['show_email'] ) && 'yes' === $profile['show_email'] ) {
			$profile['show_email'] = 'no';
			$changed               = true;
		}
		if ( $changed ) {
			update_user_meta( (int) $vendor_id, 'dokan_profile_settings', $profile );
		}
	}

	update_option( 'zymarg_vd_contact_scrub_1_33_0', 1 );
}
add_action( 'admin_init', 'zymarg_vd_scrub_public_contact_details' );

/**
 * AJAX: Section 6 (Tax & Business Info).
 *
 * Five optional identity fields. Every empty value deletes the corresponding
 * user_meta so `get_user_meta()` returns '' instead of an empty-string row
 * lingering in the DB.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_save_business() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	$bin           = isset( $_POST['business_bin'] ) ? sanitize_text_field( wp_unslash( $_POST['business_bin'] ) ) : '';
	$tin           = isset( $_POST['business_tin'] ) ? sanitize_text_field( wp_unslash( $_POST['business_tin'] ) ) : '';
	$name          = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';
	$trade_license = isset( $_POST['business_trade_license'] ) ? sanitize_text_field( wp_unslash( $_POST['business_trade_license'] ) ) : '';
	$address       = isset( $_POST['business_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['business_address'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$map = array(
		'_zymarg_vd_business_bin'           => $bin,
		'_zymarg_vd_business_tin'           => $tin,
		'_zymarg_vd_business_name'          => $name,
		'_zymarg_vd_business_trade_license' => $trade_license,
		'_zymarg_vd_business_address'       => $address,
	);

	foreach ( $map as $meta_key => $value ) {
		if ( '' === $value ) {
			delete_user_meta( $user_id, $meta_key );
		} else {
			update_user_meta( $user_id, $meta_key, $value );
		}
	}

	wp_send_json_success(
		array(
			'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_business', 'zymarg_vd_ajax_settings_save_business' );

/**
 * AJAX: Section 7 (SEO & Store Meta).
 *
 * Persists five fields: seo_title, seo_desc, og_image_id (attachment ID),
 * og_title, og_desc. Empty values wipe the corresponding meta. The image
 * ID is coerced to a positive int and validated against
 * wp_get_attachment_url() to make sure a random number can't be smuggled in.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_save_seo() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	$seo_title = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '';
	$seo_desc  = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
	$og_title  = isset( $_POST['og_title'] ) ? sanitize_text_field( wp_unslash( $_POST['og_title'] ) ) : '';
	$og_desc   = isset( $_POST['og_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['og_desc'] ) ) : '';
	$og_image  = isset( $_POST['og_image_id'] ) ? (int) wp_unslash( $_POST['og_image_id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Clamp lengths defensively (browser maxlength is only advisory).
	if ( function_exists( 'mb_substr' ) ) {
		$seo_title = mb_substr( $seo_title, 0, 60 );
		$seo_desc  = mb_substr( $seo_desc, 0, 160 );
		$og_title  = mb_substr( $og_title, 0, 100 );
		$og_desc   = mb_substr( $og_desc, 0, 200 );
	} else {
		$seo_title = substr( $seo_title, 0, 60 );
		$seo_desc  = substr( $seo_desc, 0, 160 );
		$og_title  = substr( $og_title, 0, 100 );
		$og_desc   = substr( $og_desc, 0, 200 );
	}

	// Validate the attachment id if one was supplied.
	if ( $og_image > 0 && ! wp_get_attachment_url( $og_image ) ) {
		$og_image = 0;
	}

	$strings = array(
		'_zymarg_vd_seo_title' => $seo_title,
		'_zymarg_vd_seo_desc'  => $seo_desc,
		'_zymarg_vd_og_title'  => $og_title,
		'_zymarg_vd_og_desc'   => $og_desc,
	);
	foreach ( $strings as $meta_key => $value ) {
		if ( '' === $value ) {
			delete_user_meta( $user_id, $meta_key );
		} else {
			update_user_meta( $user_id, $meta_key, $value );
		}
	}
	if ( $og_image > 0 ) {
		update_user_meta( $user_id, '_zymarg_vd_og_image_id', $og_image );
	} else {
		delete_user_meta( $user_id, '_zymarg_vd_og_image_id' );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_seo', 'zymarg_vd_ajax_settings_save_seo' );

/**
 * AJAX: Section 8 (Social Links).
 *
 * Facebook / Instagram / Twitter / YouTube are stored back into Dokan's own
 * `dokan_profile_settings['social']` sub-array (keys: fb, instagram,
 * twitter, youtube — same names Dokan itself uses in
 * includes/store-settings.php). WhatsApp digits + TikTok URL are two native
 * fields we own outright.
 *
 * Reads the existing `dokan_profile_settings` array first and only mutates
 * the four social sub-keys, so other keys (store_name, phone, address, etc.)
 * are preserved verbatim.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_save_social() {
	$user_id = zymarg_vd_settings_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	$fb        = isset( $_POST['social_fb'] ) ? esc_url_raw( wp_unslash( $_POST['social_fb'] ) ) : '';
	$instagram = isset( $_POST['social_instagram'] ) ? esc_url_raw( wp_unslash( $_POST['social_instagram'] ) ) : '';
	$twitter   = isset( $_POST['social_twitter'] ) ? esc_url_raw( wp_unslash( $_POST['social_twitter'] ) ) : '';
	$youtube   = isset( $_POST['social_youtube'] ) ? esc_url_raw( wp_unslash( $_POST['social_youtube'] ) ) : '';
	$tiktok    = isset( $_POST['social_tiktok'] ) ? esc_url_raw( wp_unslash( $_POST['social_tiktok'] ) ) : '';
	$wa_raw    = isset( $_POST['social_whatsapp'] ) ? (string) wp_unslash( $_POST['social_whatsapp'] ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$whatsapp = preg_replace( '/\D+/', '', $wa_raw );
	if ( '' !== $whatsapp && 10 !== strlen( $whatsapp ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid 10-digit WhatsApp number.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// Read → mutate → write, so we don't clobber unrelated keys.
	$profile = get_user_meta( $user_id, 'dokan_profile_settings', true );
	if ( ! is_array( $profile ) ) {
		$profile = array();
	}
	$social = isset( $profile['social'] ) && is_array( $profile['social'] ) ? $profile['social'] : array();

	$social['fb']        = $fb;
	$social['instagram'] = $instagram;
	$social['twitter']   = $twitter;
	$social['youtube']   = $youtube;

	$profile['social'] = $social;
	update_user_meta( $user_id, 'dokan_profile_settings', $profile );

	if ( '' === $whatsapp ) {
		delete_user_meta( $user_id, '_zymarg_vd_social_whatsapp' );
	} else {
		update_user_meta( $user_id, '_zymarg_vd_social_whatsapp', $whatsapp );
	}
	if ( '' === $tiktok ) {
		delete_user_meta( $user_id, '_zymarg_vd_social_tiktok' );
	} else {
		update_user_meta( $user_id, '_zymarg_vd_social_tiktok', $tiktok );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_settings_save_social', 'zymarg_vd_ajax_settings_save_social' );

/* ====================================================================== *
 * v1.29.0 — Runtime side-effects that consume Section 4 (Store Prefs) meta.
 * These fire on every page load / order create — they're NOT inside AJAX
 * handlers.
 * ====================================================================== */

/**
 * WooCommerce validation hook — block add-to-cart when the product's
 * vendor has a minimum order value set and this vendor's current cart
 * subtotal is below it.
 *
 * @param bool $passed  Whether validation passed so far.
 * @param int  $prod_id Product being added.
 * @return bool
 */
function zymarg_vd_min_order_validation( $passed, $prod_id ) {
	if ( ! $passed || ! function_exists( 'WC' ) ) {
		return $passed;
	}
	$product = get_post( (int) $prod_id );
	if ( ! $product ) {
		return $passed;
	}
	$vendor_id = (int) $product->post_author;
	if ( ! $vendor_id ) {
		return $passed;
	}

	$min = (int) get_user_meta( $vendor_id, '_zymarg_vd_min_order_value', true );
	if ( $min <= 0 ) {
		return $passed;
	}

	$cart = WC()->cart;
	if ( ! $cart ) {
		return $passed;
	}

	// Sum only the items in the cart that belong to this vendor.
	$vendor_subtotal = 0.0;
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['product_id'] ) ) {
			continue;
		}
		$item_prod = get_post( (int) $item['product_id'] );
		if ( ! $item_prod || (int) $item_prod->post_author !== $vendor_id ) {
			continue;
		}
		if ( isset( $item['line_total'] ) ) {
			$vendor_subtotal += (float) $item['line_total'];
		} elseif ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_price' ) ) {
			$qty              = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$vendor_subtotal += (float) $item['data']->get_price() * $qty;
		}
	}

	if ( $vendor_subtotal < (float) $min ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			/* translators: %s: minimum order value in BDT. */
			wc_add_notice( sprintf( __( 'This seller requires a minimum order of ৳%s.', 'zymarg-vendor-dashboard' ), number_format_i18n( $min ) ), 'error' );
		}
		return false;
	}
	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'zymarg_vd_min_order_validation', 10, 2 );

/**
 * WooCommerce new-order hook — auto-attach the vendor's default order note
 * as a customer-visible order note if:
 *   1. The order contains at least one item authored by the vendor, and
 *   2. That vendor has a non-empty `_zymarg_vd_default_order_note` set.
 *
 * Runs once per vendor per order so a multi-vendor cart gets one note per
 * seller (each vendor speaks for their own items).
 *
 * @param int $order_id New order ID.
 * @return void
 */
function zymarg_vd_auto_attach_order_note( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( (int) $order_id );
	if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
		return;
	}

	$seen_vendors = array();
	foreach ( $order->get_items() as $item ) {
		if ( ! method_exists( $item, 'get_product_id' ) ) {
			continue;
		}
		$product_id = (int) $item->get_product_id();
		if ( ! $product_id ) {
			continue;
		}
		$post = get_post( $product_id );
		if ( ! $post ) {
			continue;
		}
		$vendor_id = (int) $post->post_author;
		if ( ! $vendor_id || isset( $seen_vendors[ $vendor_id ] ) ) {
			continue;
		}
		$seen_vendors[ $vendor_id ] = true;

		$note = (string) get_user_meta( $vendor_id, '_zymarg_vd_default_order_note', true );
		if ( '' === trim( $note ) ) {
			continue;
		}
		// add_order_note( $note, $is_customer_note = 1 ) surfaces it to the buyer.
		$order->add_order_note( $note, 1 );
	}
}
add_action( 'woocommerce_new_order', 'zymarg_vd_auto_attach_order_note', 20 );

/**
 * Add a "every 5 minutes" custom cron schedule for the auto-accept sweep.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function zymarg_vd_add_five_minute_cron_schedule( $schedules ) {
	if ( ! isset( $schedules['zymarg_vd_five_minutes'] ) ) {
		$schedules['zymarg_vd_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (ZYMARG Vendor auto-accept)', 'zymarg-vendor-dashboard' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'zymarg_vd_add_five_minute_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

/**
 * Recompute the "any vendor has auto-accept on" global option flag and
 * (un)schedule the cron accordingly. Called whenever Section 4's meta
 * changes.
 *
 * @return void
 */
function zymarg_vd_auto_accept_refresh_schedule() {
	global $wpdb;
	// Any user_meta row with _zymarg_vd_auto_accept_orders = 1?
	$any_on = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_zymarg_vd_auto_accept_orders' AND meta_value = '1'"
	) > 0;

	update_option( 'zymarg_vd_auto_accept_any', $any_on ? 1 : 0 );

	$scheduled = wp_next_scheduled( 'zymarg_vd_auto_accept_orders_cron' );
	if ( $any_on && ! $scheduled ) {
		wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'zymarg_vd_five_minutes', 'zymarg_vd_auto_accept_orders_cron' );
	} elseif ( ! $any_on && $scheduled ) {
		wp_unschedule_event( $scheduled, 'zymarg_vd_auto_accept_orders_cron' );
	}
}

/**
 * Cron callback: promote pending orders (>=5 minutes old) to processing
 * when every vendor whose products are in the order has auto-accept on.
 *
 * We stay conservative: if the order has items from multiple vendors and
 * even ONE of them has auto-accept off, we skip the whole order so the
 * disagreeing vendor can still review it manually.
 *
 * @return void
 */
function zymarg_vd_auto_accept_cron() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	// Fast exit when no vendor has it on.
	if ( ! (int) get_option( 'zymarg_vd_auto_accept_any', 0 ) ) {
		return;
	}

	// Pending orders older than 5 minutes.
	$cutoff = time() - ( 5 * MINUTE_IN_SECONDS );
	$orders = wc_get_orders(
		array(
			'status'       => 'pending',
			'limit'        => 50,
			'date_created' => '<' . gmdate( 'Y-m-d H:i:s', $cutoff ),
			'return'       => 'objects',
		)
	);
	if ( empty( $orders ) ) {
		zymarg_vd_auto_accept_refresh_schedule();
		return;
	}

	foreach ( $orders as $order ) {
		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			continue;
		}
		$vendor_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$post = get_post( (int) $item->get_product_id() );
			if ( ! $post ) {
				continue;
			}
			$vendor_ids[ (int) $post->post_author ] = true;
		}
		if ( empty( $vendor_ids ) ) {
			continue;
		}

		$all_on = true;
		foreach ( array_keys( $vendor_ids ) as $vid ) {
			if ( 1 !== (int) get_user_meta( $vid, '_zymarg_vd_auto_accept_orders', true ) ) {
				$all_on = false;
				break;
			}
		}
		if ( $all_on ) {
			$order->update_status( 'processing', __( 'Auto-accepted by ZYMARG Vendor Dashboard (all vendors opted in).', 'zymarg-vendor-dashboard' ) );
		}
	}

	// Refresh the global flag once we're done in case the last vendor flipped off.
	zymarg_vd_auto_accept_refresh_schedule();
}
add_action( 'zymarg_vd_auto_accept_orders_cron', 'zymarg_vd_auto_accept_cron' );


/* ====================================================================== *
 * v1.30.0 — Settings Sections 9-11: Data Export, Danger Zone, ZLS Bridge.
 * All AJAX endpoints reuse the same guard as Sections 1-7:
 * shared `zymarg_vendor_action` nonce + `zymarg_vd_settings_ajax_guard()`.
 *
 * Data Export uses admin-post.php (real form submits) so the browser can
 * stream CSVs to disk natively; rate-limit errors redirect back with an
 * `?export_error=…` query arg the Settings JS toasts on load.
 * ====================================================================== */

/* ---------------------------------------------------------------------- *
 * Section 9 — Data Export (admin-post.php CSV streams).
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'zymarg_vd_export_vendor_slug' ) ) {
	/**
	 * Best-effort filesystem-safe store slug for a vendor. Falls back to
	 * user_login when Dokan isn't available.
	 *
	 * @param int $user_id Vendor ID.
	 * @return string
	 */
	function zymarg_vd_export_vendor_slug( $user_id ) {
		$slug = '';
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = (array) dokan_get_store_info( (int) $user_id );
			if ( ! empty( $info['store_name'] ) ) {
				$slug = sanitize_title( (string) $info['store_name'] );
			}
		}
		if ( '' === $slug ) {
			$u = get_userdata( (int) $user_id );
			if ( $u ) {
				$slug = sanitize_title( $u->user_login );
			}
		}
		return '' !== $slug ? $slug : 'vendor-' . (int) $user_id;
	}
}

/**
 * Enforce a 60-second per-vendor per-type export rate limit.
 *
 * @param int    $user_id Vendor ID.
 * @param string $type    'orders' | 'customers' | 'products'.
 * @return int Seconds remaining (0 if not rate limited).
 */
function zymarg_vd_export_rate_remaining( $user_id, $type ) {
	$key      = 'zymarg_vd_export_ratelimit_' . (int) $user_id . '_' . sanitize_key( $type );
	$expires  = (int) get_transient( $key );
	$now      = time();
	if ( $expires > $now ) {
		return $expires - $now;
	}
	return 0;
}

/**
 * Set the rate-limit transient (60s TTL) for a vendor/type pair.
 *
 * @param int    $user_id Vendor ID.
 * @param string $type    Export type.
 * @return void
 */
function zymarg_vd_export_rate_set( $user_id, $type ) {
	$key = 'zymarg_vd_export_ratelimit_' . (int) $user_id . '_' . sanitize_key( $type );
	set_transient( $key, time() + 60, 60 );
}

/**
 * Common bootstrap for the three admin-post CSV export handlers. Returns
 * the current user ID or redirects back with an `?export_error=…` on
 * failure (bad nonce, no cap, rate-limited).
 *
 * @param string $type 'orders' | 'customers' | 'products'.
 * @return int Vendor ID (only returns on success).
 */
function zymarg_vd_export_bootstrap( $type ) {
	$referer = wp_get_referer() ? wp_get_referer() : home_url( '/dashboard/settings/' );

	// Auth.
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( add_query_arg( 'export_error', rawurlencode( __( 'You must be signed in to export data.', 'zymarg-vendor-dashboard' ) ), $referer ) );
		exit;
	}
	// Nonce.
	$nonce_ok = isset( $_REQUEST['nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['nonce'] ) ), 'zymarg_vendor_action' );
	if ( ! $nonce_ok ) {
		wp_safe_redirect( add_query_arg( 'export_error', rawurlencode( __( 'Session expired. Please refresh and try again.', 'zymarg-vendor-dashboard' ) ), $referer ) );
		exit;
	}
	// Capability.
	$can = function_exists( 'zymarg_os_can_view_vendor_dashboard' ) ? zymarg_os_can_view_vendor_dashboard() : current_user_can( 'read' );
	if ( ! $can ) {
		wp_safe_redirect( add_query_arg( 'export_error', rawurlencode( __( 'You are not allowed to export data.', 'zymarg-vendor-dashboard' ) ), $referer ) );
		exit;
	}
	$user_id = (int) get_current_user_id();
	// Rate limit.
	$remaining = zymarg_vd_export_rate_remaining( $user_id, $type );
	if ( $remaining > 0 ) {
		/* translators: %d: seconds. */
		$msg = sprintf( __( 'Please wait %d seconds before exporting again.', 'zymarg-vendor-dashboard' ), $remaining );
		wp_safe_redirect( add_query_arg( 'export_error', rawurlencode( $msg ), $referer ) );
		exit;
	}
	zymarg_vd_export_rate_set( $user_id, $type );
	return $user_id;
}

/**
 * Send CSV response headers + open php://output for fputcsv writes.
 * Caller is responsible for closing and calling exit.
 *
 * @param string $filename Filename (no path).
 * @return resource
 */
function zymarg_vd_export_open_stream( $filename ) {
	// Best-effort: kill any buffering the shell may have started so the
	// CSV stream doesn't get wrapped in HTML.
	while ( ob_get_level() > 0 ) {
		@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	$fh = fopen( 'php://output', 'w' );
	// BOM so Excel opens UTF-8 correctly.
	fwrite( $fh, "\xEF\xBB\xBF" );
	return $fh;
}

/**
 * Fetch vendor-scoped orders (multi-strategy for Dokan Lite / no-Dokan).
 *
 * @param int $user_id Vendor ID.
 * @return int[] Order IDs.
 */
function zymarg_vd_export_get_vendor_order_ids( $user_id ) {
	global $wpdb;
	$user_id = (int) $user_id;
	$ids     = array();

	// Preferred path: Dokan Lite's dokan_get_seller_orders() if available.
	if ( function_exists( 'dokan_get_seller_orders' ) ) {
		$rows = dokan_get_seller_orders( $user_id, 'all', 'all', -1, 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r->order_id ) ) {
					$ids[] = (int) $r->order_id;
				} elseif ( is_numeric( $r ) ) {
					$ids[] = (int) $r;
				}
			}
		}
	}
	if ( ! empty( $ids ) ) {
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	// Fallback: dokan sub-order table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$table = $wpdb->prefix . 'dokan_orders';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists === $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE seller_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $rid ) {
			$ids[] = (int) $rid;
		}
	}

	// Final fallback: postmeta _dokan_vendor_id (or all orders owned by author on non-multivendor).
	if ( empty( $ids ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'shop_order' AND pm.meta_key = '_dokan_vendor_id' AND pm.meta_value = %d",
			$user_id
		) );
		foreach ( (array) $rows as $rid ) {
			$ids[] = (int) $rid;
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * admin-post handler: Export Orders.
 *
 * @return void
 */
function zymarg_vd_settings_export_orders() {
	$user_id  = zymarg_vd_export_bootstrap( 'orders' );
	$slug     = zymarg_vd_export_vendor_slug( $user_id );
	$filename = $slug . '-orders-' . gmdate( 'Y-m-d' ) . '.csv';

	$fh = zymarg_vd_export_open_stream( $filename );
	fputcsv( $fh, array( 'order_id', 'date_created', 'status', 'customer_name', 'customer_email', 'item_count', 'subtotal', 'shipping_total', 'tax_total', 'total', 'payment_method' ) );

	$order_ids = zymarg_vd_export_get_vendor_order_ids( $user_id );
	if ( function_exists( 'wc_get_order' ) ) {
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
				continue;
			}
			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$name    = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );

			$item_count = 0;
			$subtotal   = 0.0;
			foreach ( $order->get_items() as $it ) {
				if ( method_exists( $it, 'get_product_id' ) ) {
					$p = get_post( (int) $it->get_product_id() );
					if ( $p && (int) $p->post_author === $user_id ) {
						$item_count += (int) $it->get_quantity();
						$subtotal   += (float) $it->get_subtotal();
					}
				}
			}

			fputcsv( $fh, array(
				$order->get_id(),
				$created ? $created->date( 'Y-m-d H:i:s' ) : '',
				$order->get_status(),
				$name,
				$order->get_billing_email(),
				$item_count,
				number_format( $subtotal, 2, '.', '' ),
				number_format( (float) $order->get_shipping_total(), 2, '.', '' ),
				number_format( (float) $order->get_total_tax(), 2, '.', '' ),
				number_format( (float) $order->get_total(), 2, '.', '' ),
				(string) $order->get_payment_method_title(),
			) );
		}
	}
	fclose( $fh );
	exit;
}
add_action( 'admin_post_zymarg_vd_settings_export_orders', 'zymarg_vd_settings_export_orders' );

/**
 * admin-post handler: Export Customers.
 *
 * Distinct buyers across the vendor's orders, with first/last purchase
 * date, total orders and total spent (vendor-scoped subtotal).
 *
 * @return void
 */
function zymarg_vd_settings_export_customers() {
	$user_id  = zymarg_vd_export_bootstrap( 'customers' );
	$slug     = zymarg_vd_export_vendor_slug( $user_id );
	$filename = $slug . '-customers-' . gmdate( 'Y-m-d' ) . '.csv';

	$fh = zymarg_vd_export_open_stream( $filename );
	fputcsv( $fh, array( 'customer_id', 'display_name', 'email', 'first_purchase_date', 'last_purchase_date', 'total_orders', 'total_spent' ) );

	$order_ids = zymarg_vd_export_get_vendor_order_ids( $user_id );
	$agg       = array(); // keyed by customer_id (or email for guests).

	if ( function_exists( 'wc_get_order' ) ) {
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
				continue;
			}
			$cid   = (int) $order->get_customer_id();
			$email = (string) $order->get_billing_email();
			$key   = $cid > 0 ? 'u_' . $cid : 'g_' . strtolower( $email );

			// Sum only the vendor's line items.
			$vendor_total = 0.0;
			foreach ( $order->get_items() as $it ) {
				if ( method_exists( $it, 'get_product_id' ) ) {
					$p = get_post( (int) $it->get_product_id() );
					if ( $p && (int) $p->post_author === $user_id ) {
						$vendor_total += (float) $it->get_total();
					}
				}
			}
			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$dstr    = $created ? $created->date( 'Y-m-d H:i:s' ) : '';

			if ( ! isset( $agg[ $key ] ) ) {
				$name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
				if ( '' === $name && $cid > 0 ) {
					$u = get_userdata( $cid );
					if ( $u ) {
						$name = $u->display_name;
					}
				}
				$agg[ $key ] = array(
					'customer_id'  => $cid,
					'display_name' => $name,
					'email'        => $email,
					'first'        => $dstr,
					'last'         => $dstr,
					'orders'       => 0,
					'spent'        => 0.0,
				);
			}
			$agg[ $key ]['orders']++;
			$agg[ $key ]['spent'] += $vendor_total;
			if ( '' !== $dstr && ( '' === $agg[ $key ]['first'] || strcmp( $dstr, $agg[ $key ]['first'] ) < 0 ) ) {
				$agg[ $key ]['first'] = $dstr;
			}
			if ( '' !== $dstr && strcmp( $dstr, $agg[ $key ]['last'] ) > 0 ) {
				$agg[ $key ]['last'] = $dstr;
			}
		}
	}

	foreach ( $agg as $row ) {
		fputcsv( $fh, array(
			$row['customer_id'],
			$row['display_name'],
			$row['email'],
			$row['first'],
			$row['last'],
			$row['orders'],
			number_format( (float) $row['spent'], 2, '.', '' ),
		) );
	}
	fclose( $fh );
	exit;
}
add_action( 'admin_post_zymarg_vd_settings_export_customers', 'zymarg_vd_settings_export_customers' );

/**
 * admin-post handler: Export Products.
 *
 * @return void
 */
function zymarg_vd_settings_export_products() {
	$user_id  = zymarg_vd_export_bootstrap( 'products' );
	$slug     = zymarg_vd_export_vendor_slug( $user_id );
	$filename = $slug . '-products-' . gmdate( 'Y-m-d' ) . '.csv';

	$fh = zymarg_vd_export_open_stream( $filename );
	fputcsv( $fh, array( 'product_id', 'sku', 'name', 'type', 'price', 'sale_price', 'stock_status', 'stock_qty', 'total_sales', 'date_created' ) );

	$q = new WP_Query( array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'author'         => $user_id,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	if ( ! empty( $q->posts ) && function_exists( 'wc_get_product' ) ) {
		foreach ( $q->posts as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p ) { continue; }
			$created = $p->get_date_created();
			fputcsv( $fh, array(
				$p->get_id(),
				(string) $p->get_sku(),
				(string) $p->get_name(),
				(string) $p->get_type(),
				number_format( (float) $p->get_regular_price(), 2, '.', '' ),
				'' === (string) $p->get_sale_price() ? '' : number_format( (float) $p->get_sale_price(), 2, '.', '' ),
				(string) $p->get_stock_status(),
				null === $p->get_stock_quantity() ? '' : (int) $p->get_stock_quantity(),
				(int) $p->get_total_sales(),
				$created ? $created->date( 'Y-m-d H:i:s' ) : '',
			) );
		}
	}
	fclose( $fh );
	exit;
}
add_action( 'admin_post_zymarg_vd_settings_export_products', 'zymarg_vd_settings_export_products' );


/* ---------------------------------------------------------------------- *
 * v1.32.0 — Section 10 (Danger Zone) no longer has its own vacation
 * toggle. zymarg_vd_ajax_settings_toggle_vacation() used to live here,
 * writing a typo'd meta key (`setting_go_vocation`) that was silently
 * disconnected from the real storefront vacation effects (which read
 * `setting_go_vacation`, in store-settings.php). Vacation mode now lives
 * exclusively in Section 5 "Store Profile" (zymarg_vd_ajax_settings_save_store_profile()
 * below), using the correct key, so the toggle vendors see is actually
 * wired to what happens on their storefront.
 * ---------------------------------------------------------------------- */

/**
 * AJAX: submit a store-closure request.
 *
 * Stores `_zymarg_vd_close_requested` (mysql timestamp) + `_zymarg_vd_close_reason`,
 * and emails the marketplace admin.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_request_close() {
	$user_id = zymarg_vd_settings_ajax_guard();
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => __( 'Account not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard.
	$reason    = isset( $_POST['close_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['close_reason'] ) ) : '';
	$confirmed = isset( $_POST['close_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['close_confirm'] ) ) : '';
	// phpcs:enable

	$store_name = $user->display_name;
	if ( function_exists( 'dokan_get_store_info' ) ) {
		$info = (array) dokan_get_store_info( $user_id );
		if ( ! empty( $info['store_name'] ) ) {
			$store_name = (string) $info['store_name'];
		}
	}
	// Typed-confirm — case-insensitive.
	if ( 0 !== strcasecmp( trim( $confirmed ), trim( (string) $store_name ) ) ) {
		wp_send_json_error( array( 'message' => __( 'The store name confirmation did not match. Please try again.', 'zymarg-vendor-dashboard' ) ), 400 );
	}
	if ( function_exists( 'mb_substr' ) ) {
		$reason = mb_substr( $reason, 0, 500 );
	} else {
		$reason = substr( $reason, 0, 500 );
	}

	update_user_meta( $user_id, '_zymarg_vd_close_requested', current_time( 'mysql' ) );
	if ( '' !== $reason ) {
		update_user_meta( $user_id, '_zymarg_vd_close_reason', $reason );
	} else {
		delete_user_meta( $user_id, '_zymarg_vd_close_reason' );
	}

	// Notify admin.
	$store_url = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $user_id ) : '';
	$subj      = sprintf( '[%s] Vendor closure request: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $store_name );
	$lines     = array(
		'Vendor:      ' . $store_name . ' (' . $user->user_email . ')',
		'Store URL:   ' . $store_url,
		'Requested:   ' . current_time( 'mysql' ),
		'',
		'Reason:',
		'' !== $reason ? $reason : '(none provided)',
	);
	@wp_mail( get_option( 'admin_email' ), $subj, implode( "\n", $lines ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	wp_send_json_success( array(
		'message' => __( 'Closure request submitted. An admin will contact you within 3 business days.', 'zymarg-vendor-dashboard' ),
	) );
}
add_action( 'wp_ajax_zymarg_vd_settings_request_close', 'zymarg_vd_ajax_settings_request_close' );

/**
 * AJAX: cancel a pending store-closure request.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_cancel_close() {
	$user_id = zymarg_vd_settings_ajax_guard();
	delete_user_meta( $user_id, '_zymarg_vd_close_requested' );
	delete_user_meta( $user_id, '_zymarg_vd_close_reason' );
	wp_send_json_success( array( 'message' => __( 'Closure request cancelled.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_settings_cancel_close', 'zymarg_vd_ajax_settings_cancel_close' );

/**
 * AJAX: schedule account deletion (7 days out).
 *
 * @return void
 */
function zymarg_vd_ajax_settings_schedule_delete() {
	$user_id = zymarg_vd_settings_ajax_guard();
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		wp_send_json_error( array( 'message' => __( 'Account not found.', 'zymarg-vendor-dashboard' ) ), 404 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard.
	$confirmed = isset( $_POST['delete_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_confirm'] ) ) : '';
	if ( 'DELETE MY ACCOUNT' !== trim( $confirmed ) ) {
		wp_send_json_error( array( 'message' => __( 'You must type DELETE MY ACCOUNT exactly to confirm.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	// 7 days from now.
	$when = time() + ( 7 * DAY_IN_SECONDS );
	update_user_meta( $user_id, '_zymarg_vd_delete_scheduled', $when );

	// Schedule the WP-Cron single event.
	wp_clear_scheduled_hook( 'zymarg_vd_delete_vendor_account', array( $user_id ) );
	wp_schedule_single_event( $when, 'zymarg_vd_delete_vendor_account', array( $user_id ) );

	// Emails (admin + vendor).
	$date_str = wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $when );
	@wp_mail( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		get_option( 'admin_email' ),
		sprintf( '[%s] Vendor account deletion scheduled: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $user->display_name ),
		sprintf( "Vendor %s (ID %d, email %s) scheduled account deletion for %s. Deletion will run automatically unless cancelled.", $user->display_name, $user_id, $user->user_email, $date_str )
	);
	@wp_mail( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$user->user_email,
		sprintf( '[%s] Your account is scheduled for deletion', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
		sprintf( "Hello %s,\n\nYour account will be permanently deleted on %s.\n\nIf you change your mind, sign in and click 'Cancel Deletion' in Settings > Danger Zone before that time.\n\nThank you.", $user->display_name, $date_str )
	);

	wp_send_json_success( array(
		'message'      => __( 'Account scheduled for deletion.', 'zymarg-vendor-dashboard' ),
		'scheduled_at' => $when,
		'display'      => $date_str,
	) );
}
add_action( 'wp_ajax_zymarg_vd_settings_schedule_delete', 'zymarg_vd_ajax_settings_schedule_delete' );

/**
 * AJAX: cancel a pending account deletion.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_cancel_delete() {
	$user_id = zymarg_vd_settings_ajax_guard();
	delete_user_meta( $user_id, '_zymarg_vd_delete_scheduled' );
	wp_clear_scheduled_hook( 'zymarg_vd_delete_vendor_account', array( $user_id ) );
	wp_send_json_success( array( 'message' => __( 'Account deletion cancelled.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_settings_cancel_delete', 'zymarg_vd_ajax_settings_cancel_delete' );

/**
 * WP-Cron callback: actually delete the vendor account if the scheduled
 * timestamp meta still exists (i.e. the vendor didn't cancel).
 *
 * @param int $user_id Vendor ID.
 * @return void
 */
function zymarg_vd_do_delete_vendor_account( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	$scheduled = (int) get_user_meta( $user_id, '_zymarg_vd_delete_scheduled', true );
	if ( $scheduled <= 0 ) {
		return; // Vendor cancelled — nullify.
	}
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	// Reassign to 0 (== keep as author with author intact — WP treats 0 as "no reassign").
	wp_delete_user( $user_id, 0 );
}
add_action( 'zymarg_vd_delete_vendor_account', 'zymarg_vd_do_delete_vendor_account', 10, 1 );


/* ---------------------------------------------------------------------- *
 * v1.31.0 — Section 11 "Push Notification Opt-in" was removed (it
 * duplicated the Push column in Section 3's Notification Preferences
 * grid, and the two disagreed because they wrote to different user_meta
 * keys). The AJAX handlers that used to live here
 * (zymarg_vd_settings_save_push / zymarg_vd_settings_test_push) were
 * removed with it. `zymarg_vd_push_event_on()` in push-notifications.php
 * now reads its per-user push gate straight from Section 3's
 * `_zymarg_vd_notification_prefs[event]['push']` instead of the old,
 * now-deleted `_zymarg_vd_push_prefs` meta key (which is migrated into
 * the new key on first read for anyone who had already set a preference
 * — see zymarg_vd_settings_get_notification_prefs() in settings-hub.php).
 * A "Send test push" utility, if wanted again, belongs on the plugin's
 * admin-side Push Notifications settings page (zymarg_vd_push_test_ajax()
 * in push-notifications.php), not duplicated per-vendor.
 * ---------------------------------------------------------------------- */


/* ====================================================================== *
 * v1.30.2 — Section 10: Revoke session + Remove passkey AJAX endpoints.
 * ====================================================================== */

/**
 * AJAX: revoke a ZLS refresh token belonging to the current user.
 *
 * Deletes a single row from {prefix}zls_refresh_tokens, constrained by
 * both `id` and `user_id` so a vendor can only revoke their own sessions.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_revoke_session() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$user_id  = get_current_user_id();
	$token_id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;

	if ( ! $token_id ) {
		wp_send_json_error( array( 'message' => 'missing_token_id' ) );
	}

	// Only allow revoking tokens that belong to this user.
	global $wpdb;
	$table   = $wpdb->prefix . 'zls_refresh_tokens';
	$deleted = $wpdb->delete( $table, array( 'id' => $token_id, 'user_id' => $user_id ), array( '%d', '%d' ) );

	if ( false === $deleted ) {
		wp_send_json_error( array( 'message' => 'db_error' ) );
	}
	if ( 0 === $deleted ) {
		wp_send_json_error( array( 'message' => 'not_found' ) );
	}
	wp_send_json_success( array( 'message' => 'revoked', 'token_id' => $token_id ) );
}
add_action( 'wp_ajax_zymarg_vd_settings_revoke_session', 'zymarg_vd_ajax_settings_revoke_session' );

/**
 * AJAX: remove a ZLS passkey (WebAuthn credential) belonging to the current user.
 *
 * Deletes a single row from {prefix}zls_passkeys, constrained by both
 * `id` and `user_id` so a vendor can only remove their own passkeys.
 *
 * @return void
 */
function zymarg_vd_ajax_settings_remove_passkey() {
	check_ajax_referer( 'zymarg_vendor_action', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$user_id    = get_current_user_id();
	$passkey_id = isset( $_POST['passkey_id'] ) ? absint( $_POST['passkey_id'] ) : 0;

	if ( ! $passkey_id ) {
		wp_send_json_error( array( 'message' => 'missing_passkey_id' ) );
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'zls_passkeys';
	$deleted = $wpdb->delete( $table, array( 'id' => $passkey_id, 'user_id' => $user_id ), array( '%d', '%d' ) );

	if ( false === $deleted ) {
		wp_send_json_error( array( 'message' => 'db_error' ) );
	}
	if ( 0 === $deleted ) {
		wp_send_json_error( array( 'message' => 'not_found' ) );
	}
	wp_send_json_success( array( 'message' => 'removed', 'passkey_id' => $passkey_id ) );
}
add_action( 'wp_ajax_zymarg_vd_settings_remove_passkey', 'zymarg_vd_ajax_settings_remove_passkey' );
