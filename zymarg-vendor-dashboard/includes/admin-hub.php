<?php
/**
 * ZYMARG Vendor Dashboard -- Admin Hub ("Vendor Hub" top-level menu).
 *
 * Registers a single "Vendor Hub" top-level admin menu with a branded hub page
 * and makes EVERY screen beneath it load over AJAX, with no full page reload.
 *
 * v1.39.0 -- Full-AJAX admin + design-token compliance:
 *   - Every hub screen is registered in one place (zymarg_vd_admin_sections),
 *     including Vendors and Push Notifications, which previously fell through
 *     to a hard browser navigation.
 *   - Every hub screen's own JS/CSS is enqueued on EVERY hub page, so a screen
 *     swapped in over AJAX is fully wired up. Previously each screen enqueued
 *     its script only on its own hook, so an AJAX-swapped screen arrived dead
 *     and the only way to make it work was to reload.
 *   - The loading indicator is the Discovery Spark. The old CSS spinner is
 *     removed: ZYMARG has no spinners.
 *   - All presentation moved to assets/css/zymarg-admin.css and scoped to the
 *     .zymarg-admin wrapper. No inline styles, no raw colours.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

require_once ZYMARG_VD_DIR . 'includes/spark.php';

/**
 * Register the top-level "Vendor Hub" admin menu and its hub landing page.
 *
 * Priority 9 ensures the parent menu exists before sub-menu registrations
 * in settings.php, payouts.php, and instructions.php fire at default priority.
 *
 * @return void
 */
function zymarg_vd_register_admin_hub_menu() {
	add_menu_page(
		__( 'Vendor Hub', 'zymarg-vendor-dashboard' ),
		__( 'Vendor Hub', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vendor-hub',
		'zymarg_vd_render_admin_hub_page',
		'dashicons-store',
		3
	);

	// First submenu item mirrors the parent so WP shows "Hub" instead of repeating "Vendor Hub".
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'Vendor Hub', 'zymarg-vendor-dashboard' ),
		__( 'Hub', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vendor-hub',
		'zymarg_vd_render_admin_hub_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_register_admin_hub_menu', 9 );

/**
 * Sidebar parent-menu branding (Design Tokens v3 section 2.16).
 *
 * Scoped to #toplevel_page_zymarg-vendor-hub and enqueued on every admin
 * page, not only Vendor Hub screens: the sidebar exists everywhere, so
 * the branding has to travel with it or the top-level item would only
 * look like ours while the admin was already inside the plugin.
 *
 * @return void
 */
function zymarg_vd_enqueue_menu_branding() {
	// Registers zymarg-tokens under its canonical handle if no sibling
	// ZYMARG plugin has beaten us to it. Idempotent thanks to the
	// wp_style_is() guard inside.
	if ( function_exists( 'zymarg_vd_register_shared_brand_assets' ) ) {
		zymarg_vd_register_shared_brand_assets();
	}

	wp_enqueue_style( 'zymarg-tokens' );

	wp_enqueue_style(
		'zymarg-vd-menu',
		ZYMARG_VD_URL . 'assets/css/zymarg-vd-menu.css',
		array( 'zymarg-tokens' ),
		ZYMARG_VD_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_enqueue_menu_branding' );

/* ---------------------------------------------------------------------- *
 * Section registry
 * ---------------------------------------------------------------------- */

/**
 * Every Vendor Hub admin screen: slug => render callback + capability.
 *
 * This is the SINGLE source of truth. The AJAX handler, the screen detection,
 * the asset loader and the JS slug allow-list are all derived from it, so a
 * new screen can never again be half-registered and silently fall back to a
 * full page load.
 *
 * @return array<string,array{callback:string,cap:string}>
 */
function zymarg_vd_admin_section_map() {
	$map = array(
		'zymarg-vendor-hub' => array(
			'callback' => 'zymarg_vd_render_admin_hub_page',
			'cap'      => 'manage_options',
		),
		'zymarg-vendor-dashboard' => array(
			'callback' => 'zymarg_vd_render_settings_page',
			'cap'      => 'manage_options',
		),
		'zymarg-vendor-payouts' => array(
			'callback' => 'zymarg_vd_payouts_render_admin_page',
			'cap'      => 'manage_woocommerce',
		),
		'zymarg-vendor-vendors' => array(
			'callback' => 'zymarg_vd_render_admin_vendors_page',
			'cap'      => 'manage_options',
		),
		'zymarg-vendor-announcements' => array(
			'callback' => 'zymarg_vd_render_admin_announcements_page',
			'cap'      => 'manage_options',
		),
		'zymarg-vd-push' => array(
			'callback' => 'zymarg_vd_push_render_settings',
			'cap'      => 'manage_options',
		),
		'zymarg-vd-instructions' => array(
			'callback' => 'zymarg_vd_render_instructions_page',
			'cap'      => 'manage_options',
		),
	);

	/**
	 * Filter the Vendor Hub AJAX section map.
	 *
	 * @param array $map Slug => array( callback, cap ).
	 */
	return apply_filters( 'zymarg_vd_admin_sections', $map );
}

/**
 * Back-compat shim: slug => callback.
 *
 * @return array<string,string>
 */
function zymarg_vd_admin_sections() {
	return wp_list_pluck( zymarg_vd_admin_section_map(), 'callback' );
}

/**
 * The admin screen IDs / hook suffixes for every hub screen.
 *
 * @return string[]
 */
function zymarg_vd_admin_hub_hooks() {
	$hooks = array( 'toplevel_page_zymarg-vendor-hub' );

	foreach ( array_keys( zymarg_vd_admin_section_map() ) as $slug ) {
		if ( 'zymarg-vendor-hub' === $slug ) {
			continue;
		}
		$hooks[] = 'vendor-hub_page_' . $slug;
	}

	return $hooks;
}

/**
 * Check whether the current admin screen is one of the Vendor Hub pages.
 *
 * @return bool
 */
function zymarg_vd_is_hub_page() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}

	return in_array( $screen->id, zymarg_vd_admin_hub_hooks(), true );
}

/* ---------------------------------------------------------------------- *
 * The admin shell wrapper
 * ---------------------------------------------------------------------- */

/**
 * Whether the current render is an AJAX section render (inner content only).
 *
 * @return bool
 */
function zymarg_vd_is_ajax_render() {
	return ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
}

/**
 * Open the admin shell.
 *
 * On a normal page load this prints the swap container. On an AJAX render it
 * prints nothing, because the container already exists in the DOM and only its
 * inner HTML is being replaced.
 *
 * The container carries the .zymarg-admin class, which is what scopes every
 * back end design token. Tokens are never declared at document root in
 * wp-admin, or they would leak into WordPress chrome.
 *
 * @return void
 */
function zymarg_vd_admin_shell_open() {
	if ( zymarg_vd_is_ajax_render() ) {
		return;
	}
	echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
}

/**
 * Close the admin shell.
 *
 * @return void
 */
function zymarg_vd_admin_shell_close() {
	if ( zymarg_vd_is_ajax_render() ) {
		return;
	}
	echo '</div><!-- #zymarg-admin-ajax-content -->';
}

/**
 * Render the standard "Back to Vendor Hub" link.
 *
 * @return void
 */
function zymarg_vd_admin_back_link() {
	printf(
		'<a href="%1$s" class="zvd-back zvd-nav-link">&larr; %2$s</a>',
		esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ),
		esc_html__( 'Back to Vendor Hub', 'zymarg-vendor-dashboard' )
	);
}

/**
 * Render the standard ZYMARG admin header.
 *
 * One per screen, and identical on every screen including submenu screens.
 *
 * @param string $title    The wordmark text.
 * @param string $subtitle Muted subtitle.
 * @param string $actions  Optional extra action button markup for the right group.
 * @return void
 */
function zymarg_vd_admin_header( $title, $subtitle = '', $actions = '' ) {
	?>
	<div class="zvd-header">
		<div class="zvd-header__left">
			<?php
			// The Spark renders at 44x44 directly on white in the admin header.
			echo zymarg_vd_spark( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'size'  => 'header',
					'label' => 'ZYMARG',
				)
			);
			?>
			<div class="zvd-header__text">
				<h1 class="zvd-wordmark"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="zvd-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<div class="zvd-header__right">
			<span class="zvd-version"><?php echo esc_html( 'v' . ZYMARG_VD_VERSION ); ?></span>
			<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------------- *
 * AJAX section loading
 * ---------------------------------------------------------------------- */

/**
 * AJAX handler: load a Vendor Hub section's HTML without a full page reload.
 *
 * @return void
 */
function zymarg_vd_ajax_load_section() {
	check_ajax_referer( 'zymarg_vd_admin_nav', 'nonce' );

	$section  = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
	$sections = zymarg_vd_admin_section_map();

	if ( ! isset( $sections[ $section ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown section.', 'zymarg-vendor-dashboard' ) ), 400 );
	}

	$config = $sections[ $section ];

	if ( ! current_user_can( $config['cap'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	if ( ! is_callable( $config['callback'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Section not available.', 'zymarg-vendor-dashboard' ) ), 500 );
	}

	// Signal render callbacks to skip the wrapper div (AJAX returns inner content only).
	$GLOBALS['zymarg_vd_ajax_render'] = true;

	ob_start();
	call_user_func( $config['callback'] );
	$html = ob_get_clean();

	unset( $GLOBALS['zymarg_vd_ajax_render'] );

	wp_send_json_success(
		array(
			'html'  => $html,
			'slug'  => $section,
			'title' => zymarg_vd_admin_section_title( $section ),
		)
	);
}
add_action( 'wp_ajax_zymarg_vd_hub_load_section', 'zymarg_vd_ajax_load_section' );

/**
 * Human-readable title for a section, used to update document.title on swap.
 *
 * @param string $slug Section slug.
 * @return string
 */
function zymarg_vd_admin_section_title( $slug ) {
	$titles = array(
		'zymarg-vendor-hub'           => __( 'Vendor Hub', 'zymarg-vendor-dashboard' ),
		'zymarg-vendor-dashboard'     => __( 'Vendor Dashboard', 'zymarg-vendor-dashboard' ),
		'zymarg-vendor-payouts'       => __( 'ZYMARG Payouts', 'zymarg-vendor-dashboard' ),
		'zymarg-vendor-vendors'       => __( 'Vendors', 'zymarg-vendor-dashboard' ),
		'zymarg-vendor-announcements' => __( 'Announcements', 'zymarg-vendor-dashboard' ),
		'zymarg-vd-push'              => __( 'Push Notifications', 'zymarg-vendor-dashboard' ),
		'zymarg-vd-premium'           => __( 'Premium', 'zymarg-vendor-dashboard' ),
		'zymarg-vd-instructions'      => __( 'D-Instruction', 'zymarg-vendor-dashboard' ),
	);

	return isset( $titles[ $slug ] ) ? $titles[ $slug ] : __( 'Vendor Hub', 'zymarg-vendor-dashboard' );
}

/* ---------------------------------------------------------------------- *
 * Assets
 * ---------------------------------------------------------------------- */

/**
 * Enqueue hub assets on EVERY hub screen.
 *
 * This is the fix for the "some sections need a refresh" behaviour. Each
 * screen used to enqueue its own script only on its own hook suffix, so when
 * a screen was swapped in over AJAX its JavaScript and its localized nonce
 * were simply not on the page. The markup arrived, nothing was wired to it,
 * and the only way to get a working screen was to reload.
 *
 * Loading every hub screen's assets on every hub screen is a few KB and makes
 * the whole hub genuinely single-page.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function zymarg_vd_admin_hub_enqueue( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, zymarg_vd_admin_hub_hooks(), true ) ) {
		return;
	}

	zymarg_vd_register_shared_brand_assets();

	// Shared brand layer first: tokens, then the Spark, then this plugin's admin CSS.
	wp_enqueue_style( 'zymarg-tokens' );
	wp_enqueue_style( 'zymarg-spark' );

	wp_enqueue_style(
		'zymarg-vd-admin',
		ZYMARG_VD_URL . 'assets/css/zymarg-admin.css',
		array( 'zymarg-tokens', 'zymarg-spark' ),
		ZYMARG_VD_VERSION
	);

	wp_enqueue_script(
		'zymarg-vd-admin-hub',
		ZYMARG_VD_URL . 'assets/js/admin-hub.js',
		array( 'jquery' ),
		ZYMARG_VD_VERSION,
		true
	);

	wp_localize_script(
		'zymarg-vd-admin-hub',
		'ZymargAdminHub',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'zymarg_vd_admin_nav' ),
			'slugs'      => array_keys( zymarg_vd_admin_section_map() ),
			'spark'      => zymarg_vd_spark( array( 'size' => 'xl', 'label' => __( 'Loading', 'zymarg-vendor-dashboard' ) ) ),
			'sparkSmall' => zymarg_vd_spark( array( 'size' => 'sm', 'label' => __( 'Saving', 'zymarg-vendor-dashboard' ) ) ),
			'i18n'       => array(
				'loading' => __( 'Loading', 'zymarg-vendor-dashboard' ),
				'saving'  => __( 'Saving', 'zymarg-vendor-dashboard' ),
				'failed'  => __( 'That did not load. Try again.', 'zymarg-vendor-dashboard' ),
				'retry'   => __( 'Retry', 'zymarg-vendor-dashboard' ),
				'saved'   => __( 'Saved.', 'zymarg-vendor-dashboard' ),
				'error'   => __( 'Error:', 'zymarg-vendor-dashboard' ),
				'success' => __( 'Success:', 'zymarg-vendor-dashboard' ),
			),
		)
	);

	/*
	 * Now let every other hub screen enqueue its own assets, by calling its
	 * enqueue callback with the hook suffix it expects. Each of those callbacks
	 * early-returns unless it sees its own hook, so we hand each one its own.
	 */
	$section_enqueuers = array(
		'vendor-hub_page_zymarg-vendor-announcements' => 'zymarg_vd_admin_announcements_enqueue',
		'vendor-hub_page_zymarg-vendor-vendors'       => 'zymarg_vd_admin_vendors_enqueue',
	);

	/**
	 * Filter the per-section admin enqueue callbacks run on every hub screen.
	 *
	 * @param array  $section_enqueuers Hook suffix => callback.
	 * @param string $hook_suffix       The real current hook suffix.
	 */
	$section_enqueuers = apply_filters( 'zymarg_vd_admin_section_enqueuers', $section_enqueuers, $hook_suffix );

	foreach ( $section_enqueuers as $their_hook => $callback ) {
		if ( $their_hook === $hook_suffix ) {
			continue; // It will run on its own anyway.
		}
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $their_hook );
		}
	}
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_admin_hub_enqueue' );

/* ---------------------------------------------------------------------- *
 * Hub landing page
 * ---------------------------------------------------------------------- */

/**
 * Render the hub landing page with its branded cards.
 *
 * @return void
 */
function zymarg_vd_render_admin_hub_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	zymarg_vd_admin_shell_open();

	$cards = array(
		array(
			'title'       => __( 'ZYMARG Vendor', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Feature toggles and plugin settings.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vendor-dashboard' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1.08 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1.08 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1.08z"/></svg>',
		),
		array(
			'title'       => __( 'ZYMARG Payouts', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Manage vendor payout requests and methods.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vendor-payouts' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		),
		array(
			'title'       => __( 'Vendors', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Per-vendor commission overrides.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vendor-vendors' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		),
		array(
			'title'       => __( 'Announcements', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Broadcast notices to your vendors.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vendor-announcements' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11v2a1 1 0 0 0 1 1h3l4 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M16 8a5 5 0 0 1 0 8"/></svg>',
		),
		array(
			'title'       => __( 'Push Notifications', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Firebase push to vendor mobile apps.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vd-push' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
		),
		array(
			'title'       => __( 'D-Instruction', 'zymarg-vendor-dashboard' ),
			'description' => __( 'Plugin documentation and feature guides.', 'zymarg-vendor-dashboard' ),
			'url'         => admin_url( 'admin.php?page=zymarg-vd-instructions' ),
			'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
		),
	);

	zymarg_vd_admin_header(
		__( 'Vendor Hub', 'zymarg-vendor-dashboard' ),
		__( 'All ZYMARG vendor administration in one place.', 'zymarg-vendor-dashboard' )
	);
	?>
	<div class="zvd-grid">
		<?php foreach ( $cards as $card ) : ?>
			<a href="<?php echo esc_url( $card['url'] ); ?>" class="zvd-hub-card zvd-nav-link">
				<span class="zvd-hub-card__icon"><?php echo $card['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="zvd-hub-card__title"><?php echo esc_html( $card['title'] ); ?></span>
				<span class="zvd-hub-card__desc"><?php echo esc_html( $card['description'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php

	zymarg_vd_admin_shell_close();
}
