<?php
/**
 * ZYMARG Vendor Dashboard — feature toggles + settings screen.
 *
 * Lets the marketplace owner switch individual dashboard features on/off from
 * Settings -> ZYMARG Vendor. Disabled features are removed from the sidebar,
 * blocked on direct URL access (fall back to the Dashboard), dropped from the
 * Quick Actions row, and (for Messages) disable the buyer shortcode too.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * The toggleable feature registry (key => label). The Dashboard home is always
 * on and intentionally not listed.
 *
 * @return array<string,string>
 */
function zymarg_vd_feature_registry() {
	return apply_filters(
		'zymarg_vd_feature_registry',
		array(
			'quick_actions'          => __( 'Quick Actions row (on the Dashboard)', 'zymarg-vendor-dashboard' ),
			'insights_attribution'   => __( 'Show "Powered by ZYMARG Insights" line under Analytics stats', 'zymarg-vendor-dashboard' ),
			'insights_install_prompt' => __( 'Show ZYMARG Insights install prompt when analytics data is unavailable', 'zymarg-vendor-dashboard' ),
			'products'       => __( 'Products', 'zymarg-vendor-dashboard' ),
			'orders'         => __( 'Orders', 'zymarg-vendor-dashboard' ),
			'earnings'       => __( 'Earnings', 'zymarg-vendor-dashboard' ),
			'analytics'      => __( 'Analytics', 'zymarg-vendor-dashboard' ),
			'promotions'     => __( 'Promotions (coupons)', 'zymarg-vendor-dashboard' ),
			'reviews'        => __( 'Reviews', 'zymarg-vendor-dashboard' ),
			'messages'       => __( 'Messages', 'zymarg-vendor-dashboard' ),
			'contact_seller' => __( 'Contact Seller button (on product pages)', 'zymarg-vendor-dashboard' ),
			'customers'      => __( 'Customers', 'zymarg-vendor-dashboard' ),
			'followers'      => __( 'Followers', 'zymarg-vendor-dashboard' ),
			'staff'          => __( 'Staff accounts', 'zymarg-vendor-dashboard' ),
			'verification'   => __( 'Verification badges (shown on store pages + product cards)', 'zymarg-vendor-dashboard' ),
			'announcements'  => __( 'Announcements (admin notices shown in vendor Notifications)', 'zymarg-vendor-dashboard' ),
			'notifications'  => __( 'Notifications', 'zymarg-vendor-dashboard' ),
			'shipping'       => __( 'Shipping (links to Dokan)', 'zymarg-vendor-dashboard' ),
			'payments'       => __( 'Payments (links to Dokan)', 'zymarg-vendor-dashboard' ),
			'support'        => __( 'Support', 'zymarg-vendor-dashboard' ),
			'support_contact_card' => __( '&nbsp;&nbsp;&nbsp;&nbsp;— Show "Contact Support" card in Support section', 'zymarg-vendor-dashboard' ),
			'support_help_card'    => __( '&nbsp;&nbsp;&nbsp;&nbsp;— Show "Help Center" card in Support section (requires Help Center URL below)', 'zymarg-vendor-dashboard' ),
			'settings'       => __( 'Settings (links to Dokan)', 'zymarg-vendor-dashboard' ),
		)
	);
}

/**
 * Default state for the two Support-card feature flags added in v1.46.3.
 *
 * Contact Support: default ON — matches the previous behavior where the
 * (broken) Support section would have shown a Contact card if it had rendered.
 *
 * Help Center: default OFF — the store owner needs to build out help content
 * AND set a destination URL before this is useful. Explicit opt-in so the
 * upgrade doesn't ship a broken-looking link.
 *
 * @param bool   $enabled Whether the feature is enabled.
 * @param string $key     Feature key.
 * @return bool
 */
add_filter( 'zymarg_vd_feature_enabled', function ( $enabled, $key ) {
	if ( 'support_help_card' === $key ) {
		// Also require the URL to be set — otherwise even an ON toggle
		// hides the card, matching the theme's behavior.
		$features = get_option( 'zymarg_vd_features', array() );
		if ( ! isset( $features['support_help_card'] ) ) {
			return false;
		}
		$url = trim( (string) get_option( 'zymarg_vd_support_help_url', '' ) );
		return $enabled && '' !== $url;
	}
	return $enabled;
}, 10, 2 );

/**
 * Saved feature state merged with defaults (everything on by default).
 *
 * @return array<string,bool>
 */
function zymarg_vd_features() {
	$defaults = array();
	foreach ( zymarg_vd_feature_registry() as $key => $label ) {
		$defaults[ $key ] = true;
	}
	$saved = get_option( 'zymarg_vd_features', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, $defaults );
}

/**
 * Whether a feature is enabled (default: yes).
 *
 * @param string $key Feature key.
 * @return bool
 */
function zymarg_vd_feature_enabled( $key ) {
	$features = zymarg_vd_features();
	$enabled  = isset( $features[ $key ] ) ? (bool) $features[ $key ] : true;

	/**
	 * Filter whether a single dashboard feature is enabled.
	 *
	 * @param bool   $enabled Whether the feature is on.
	 * @param string $key     Feature key.
	 */
	return (bool) apply_filters( 'zymarg_vd_feature_enabled', $enabled, $key );
}

/**
 * Remove disabled items from the vendor sidebar nav. Nav items are
 * [ key, label, icon, dokan_ep ]; the Dashboard (key 'dashboard') is never
 * in the registry, so it always stays.
 *
 * @param array $items Nav items.
 * @return array
 */
function zymarg_vd_filter_nav_items( $items ) {
	return array_values(
		array_filter(
			$items,
			function ( $item ) {
				return zymarg_vd_feature_enabled( $item[0] );
			}
		)
	);
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_filter_nav_items' );

/* ---------------------------------------------------------------------- *
 * Sidebar menu order (admin-sortable)
 *
 * The marketplace owner can drag the vendor sidebar into any order from the
 * settings screen. Design notes, because the naive approach is a footgun:
 *
 * - The order is stored as a SPARSE WEIGHT MAP ( key => int ), never as a
 *   positional list. The nav array is not static: 'refunds', 'staff' and
 *   'premium' only appear under certain conditions, disabled features are
 *   removed, staff logins see a subset, and other plugins may add their own
 *   items through the public 'zymarg_os_vendor_nav_items' filter. Rebuilding
 *   the nav from a stored list would silently drop any item the admin has
 *   never seen — i.e. permanently hide a section from vendors.
 *
 * - The sort therefore only ever REORDERS. It never adds and never removes:
 *   the same number of items goes out as came in. Keys with no saved weight
 *   inherit their natural position, so anything new lands where the code put
 *   it until the owner decides otherwise.
 *
 * - Menu position carries no functional meaning anywhere in the plugin.
 *   Routing is by ?vsection=<key>, feature gating is by key, staff
 *   permissions are by key and the SPA binds by [data-section] attribute.
 *   Reordering cannot affect access control or routing.
 *
 * - With the option unset this whole layer is a no-op and the sidebar is
 *   byte-identical to a build without it.
 * ---------------------------------------------------------------------- */

/**
 * The saved sidebar order as a sanitised weight map.
 *
 * @return array<string,int> Nav key => sort weight. Empty when unconfigured.
 */
function zymarg_vd_nav_order() {
	$saved = get_option( 'zymarg_vd_nav_order', array() );
	if ( ! is_array( $saved ) ) {
		return array();
	}

	$weights = array();
	foreach ( $saved as $key => $weight ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}
		$weights[ $key ] = (int) $weight;
	}

	return $weights;
}

/**
 * Apply the owner's custom sidebar order.
 *
 * Runs at priority 30 — after every core hook that inserts into the nav
 * (payouts/staff at 5, refunds at 6, feature removal at 10, premium append and
 * staff filtering at 20) so the sort sees the final item set.
 *
 * @param array $items Nav items, each [ key, label, icon, dokan_ep ].
 * @return array Same items, reordered. Never a different count.
 */
function zymarg_vd_apply_nav_order( $items ) {
	if ( ! is_array( $items ) || count( $items ) < 2 ) {
		return $items;
	}

	$weights = zymarg_vd_nav_order();
	if ( empty( $weights ) ) {
		return $items; // Unconfigured — leave the natural order completely alone.
	}

	// Decorate with a weight plus the original index, so the sort is stable and
	// items sharing a weight keep their relative order instead of shuffling.
	$decorated = array();
	foreach ( $items as $index => $item ) {
		$key = ( is_array( $item ) && isset( $item[0] ) ) ? sanitize_key( (string) $item[0] ) : '';

		$decorated[] = array(
			'weight' => ( '' !== $key && array_key_exists( $key, $weights ) ) ? $weights[ $key ] : ( $index * 10 ),
			'index'  => $index,
			'item'   => $item,
		);
	}

	usort(
		$decorated,
		static function ( $a, $b ) {
			if ( $a['weight'] === $b['weight'] ) {
				return $a['index'] < $b['index'] ? -1 : ( $a['index'] > $b['index'] ? 1 : 0 );
			}
			return $a['weight'] < $b['weight'] ? -1 : 1;
		}
	);

	// Rebuild by hand: guarantees the item payloads are passed through
	// untouched and that the count going out matches the count coming in.
	$sorted = array();
	foreach ( $decorated as $entry ) {
		$sorted[] = $entry['item'];
	}

	return $sorted;
}
add_filter( 'zymarg_os_vendor_nav_items', 'zymarg_vd_apply_nav_order', 30 );

/**
 * Turn a posted list of nav keys into a weight map and persist it.
 *
 * @param string $raw Comma-separated nav keys in the desired order.
 * @return void
 */
function zymarg_vd_save_nav_order( $raw ) {
	$keys = array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw ) ) );
	$keys = array_values( array_unique( $keys ) );
	$keys = array_slice( $keys, 0, 100 ); // Sanity cap; the real nav is ~18 items.

	if ( empty( $keys ) ) {
		// Nothing usable posted — fall back to the built-in order rather than
		// persisting an empty map.
		delete_option( 'zymarg_vd_nav_order' );
		return;
	}

	$weights = array();
	$weight  = 0;
	foreach ( $keys as $key ) {
		$weights[ $key ] = $weight;
		$weight         += 10;
	}

	update_option( 'zymarg_vd_nav_order', $weights );
}

/**
 * Render the drag-to-sort sidebar order control.
 *
 * Lives inside the feature-toggle form, so it saves with the same nonce and
 * needs no extra AJAX endpoint. Progressive enhancement: the hidden input
 * carries the current order server-side, so submitting with JavaScript off is
 * a harmless no-op instead of a wipe.
 *
 * @return void
 */
function zymarg_vd_render_nav_order_control() {
	// The vendor shell (and therefore the nav definition) only loads when
	// WooCommerce is active. Without it there is no menu to sort.
	if ( ! function_exists( 'zymarg_os_vendor_nav_items' ) ) {
		return;
	}

	$items = zymarg_os_vendor_nav_items();
	if ( ! is_array( $items ) || count( $items ) < 2 ) {
		return;
	}

	// Normalise to [ key, label ] pairs, skipping anything malformed.
	$rows = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || ! isset( $item[0] ) ) {
			continue;
		}
		$key = sanitize_key( (string) $item[0] );
		if ( '' === $key ) {
			continue;
		}
		$rows[] = array(
			'key'   => $key,
			'label' => isset( $item[1] ) && '' !== $item[1] ? (string) $item[1] : $key,
		);
	}

	if ( count( $rows ) < 2 ) {
		return;
	}

	$is_custom = ! empty( zymarg_vd_nav_order() );
	$initial   = implode( ',', wp_list_pluck( $rows, 'key' ) );
	?>
	<div class="zvds-navorder" data-zvds-navorder>
		<div class="zvds-navorder__head">
			<h2 class="zvds-navorder__title"><?php esc_html_e( 'Sidebar menu order', 'zymarg-vendor-dashboard' ); ?></h2>
			<?php if ( $is_custom ) : ?>
				<span class="zvds-navorder__badge"><?php esc_html_e( 'Custom order', 'zymarg-vendor-dashboard' ); ?></span>
			<?php else : ?>
				<span class="zvds-navorder__badge zvds-navorder__badge--default"><?php esc_html_e( 'Default order', 'zymarg-vendor-dashboard' ); ?></span>
			<?php endif; ?>
		</div>

		<p class="zvds-navorder__desc">
			<?php esc_html_e( 'Drag an item, or use the arrow buttons, to choose the order vendors see in their dashboard sidebar. Only items that are currently switched on are listed. Turning a feature back on later restores it at its default position.', 'zymarg-vendor-dashboard' ); ?>
		</p>

		<input type="hidden" name="zymarg_vd_nav_order" value="<?php echo esc_attr( $initial ); ?>" data-zvds-navorder-input>

		<ul class="zvds-navorder__list" data-zvds-navorder-list>
			<?php foreach ( $rows as $row ) : ?>
				<li class="zvds-navorder__item" data-key="<?php echo esc_attr( $row['key'] ); ?>" draggable="true">
					<span class="zvds-navorder__grip" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
					</span>
					<span class="zvds-navorder__label"><?php echo esc_html( $row['label'] ); ?></span>
					<code class="zvds-navorder__key"><?php echo esc_html( $row['key'] ); ?></code>
					<span class="zvds-navorder__btns">
						<button
							type="button"
							class="zvds-navorder__btn"
							data-zvds-move="up"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: menu item name. */ __( 'Move %s up', 'zymarg-vendor-dashboard' ), $row['label'] ) ); ?>"
						>&uarr;</button>
						<button
							type="button"
							class="zvds-navorder__btn"
							data-zvds-move="down"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: menu item name. */ __( 'Move %s down', 'zymarg-vendor-dashboard' ), $row['label'] ) ); ?>"
						>&darr;</button>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

	</div>
	<?php
}

/**
 * Render the "Reset to default order" button.
 *
 * Deliberately rendered AFTER the form's primary save button rather than inside
 * the panel above. A form's first submit button is the one a browser activates
 * on implicit submission (a stray Enter keypress), and having that silently
 * reset the owner's menu order would be a nasty little trap. Save comes first
 * in the DOM; this sits beside it as the secondary action.
 *
 * Only shown once a custom order exists — there is nothing to reset otherwise.
 *
 * @return void
 */
function zymarg_vd_render_nav_order_reset() {
	if ( ! function_exists( 'zymarg_os_vendor_nav_items' ) ) {
		return;
	}
	if ( empty( zymarg_vd_nav_order() ) ) {
		return;
	}
	?>
	<button type="submit" name="zymarg_vd_nav_reset" value="1" class="zvds-navorder__reset">
		<?php esc_html_e( 'Reset to default order', 'zymarg-vendor-dashboard' ); ?>
	</button>
	<?php
}

/**
 * Load the sidebar-order script on the Vendor Hub screens.
 *
 * Every hub screen loads every hub screen's assets (see admin-hub.php) because
 * the hub is a single-page app — a script that only loaded on its own hook
 * would be missing after an AJAX section swap.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 * @return void
 */
function zymarg_vd_nav_order_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'zymarg_vd_admin_hub_hooks' ) ) {
		return;
	}
	if ( ! in_array( $hook_suffix, zymarg_vd_admin_hub_hooks(), true ) ) {
		return;
	}

	wp_enqueue_script(
		'zymarg-vd-admin-nav-order',
		ZYMARG_VD_URL . 'assets/js/admin-nav-order.js',
		array(),
		ZYMARG_VD_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'zymarg_vd_nav_order_enqueue' );

/* ---------------------------------------------------------------------- *
 * Admin settings screen
 * ---------------------------------------------------------------------- */

/**
 * Register the settings page under the Vendor hub menu.
 *
 * @return void
 */
function zymarg_vd_register_settings_page() {
	add_submenu_page(
		'zymarg-vendor-hub',
		__( 'ZYMARG Vendor Dashboard', 'zymarg-vendor-dashboard' ),
		__( 'Vendor Dashboard', 'zymarg-vendor-dashboard' ),
		'manage_options',
		'zymarg-vendor-dashboard',
		'zymarg_vd_render_settings_page'
	);
}
add_action( 'admin_menu', 'zymarg_vd_register_settings_page' );

/**
 * Add a "Settings" link on the Plugins list row.
 *
 * @param array $links Action links.
 * @return array
 */
function zymarg_vd_plugin_action_links( $links ) {
	$url   = admin_url( 'admin.php?page=zymarg-vendor-dashboard' );
	$links = array_merge(
		array( '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'zymarg-vendor-dashboard' ) . '</a>' ),
		$links
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( ZYMARG_VD_FILE ), 'zymarg_vd_plugin_action_links' );

/**
 * Render the settings page.
 *
 * @return void
 */
function zymarg_vd_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_ajax = ! empty( $GLOBALS['zymarg_vd_ajax_render'] );
	if ( ! $is_ajax ) {
		echo '<div id="zymarg-admin-ajax-content" class="zymarg-admin">';
	}

	echo '<a href="' . esc_url( admin_url( 'admin.php?page=zymarg-vendor-hub' ) ) . '" class="zvd-back zvd-nav-link">&larr; Back to Vendor Hub</a>';

	if ( isset( $_POST['zymarg_vd_save'] ) && check_admin_referer( 'zymarg_vd_settings' ) ) {
		$submitted = isset( $_POST['features'] ) && is_array( $_POST['features'] ) ? wp_unslash( $_POST['features'] ) : array();
		$new       = array();
		foreach ( zymarg_vd_feature_registry() as $key => $label ) {
			$new[ $key ] = isset( $submitted[ $key ] );
		}
		update_option( 'zymarg_vd_features', $new );

		// v1.46.3 — Help Center URL for the Support section. Kept next to
		// the Feature Flags because it's the destination for the
		// support_help_card toggle above; blank means "not ready yet", which
		// implicitly hides the card even when the toggle is on.
		if ( isset( $_POST['zymarg_vd_support_help_url'] ) ) {
			$help_url = esc_url_raw( wp_unslash( (string) $_POST['zymarg_vd_support_help_url'] ) );
			update_option( 'zymarg_vd_support_help_url', $help_url );
		}

		/*
		 * Sidebar order. Only touched when its control was actually on the
		 * submitted form: the AI settings form below posts the same marker and
		 * nonce but does not render this field, and an unguarded save there
		 * would wipe a configured order.
		 *
		 * Reset is checked first, because clicking it still submits the hidden
		 * order field alongside it.
		 */
		if ( isset( $_POST['zymarg_vd_nav_reset'] ) ) {
			delete_option( 'zymarg_vd_nav_order' );
		} elseif ( isset( $_POST['zymarg_vd_nav_order'] ) ) {
			zymarg_vd_save_nav_order( sanitize_text_field( wp_unslash( $_POST['zymarg_vd_nav_order'] ) ) );
		}

		// Save AI subtitle settings.
		$ai_raw = isset( $_POST['zymarg_vd_ai'] ) && is_array( $_POST['zymarg_vd_ai'] ) ? wp_unslash( $_POST['zymarg_vd_ai'] ) : array();
		$ai_new = array(
			'enabled'  => ! empty( $ai_raw['enabled'] ),
			'provider' => isset( $ai_raw['provider'] ) ? sanitize_key( $ai_raw['provider'] ) : 'openai',
			'api_key'  => isset( $ai_raw['api_key'] )  ? sanitize_text_field( trim( $ai_raw['api_key'] ) ) : '',
			'model'    => isset( $ai_raw['model'] )    ? sanitize_text_field( trim( $ai_raw['model'] ) )    : '',
		);
		// Validate provider value — only allow known providers.
		if ( ! in_array( $ai_new['provider'], array( 'openai', 'anthropic' ), true ) ) {
			$ai_new['provider'] = 'openai';
		}
		update_option( 'zymarg_vd_ai', $ai_new );

		// Bust all per-vendor AI subtitle caches so vendors see the new config on next load.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_zymarg_vd_ai_sub_%' OR option_name LIKE '_transient_timeout_zymarg_vd_ai_sub_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'zymarg-vendor-dashboard' ) . '</p></div>';
	}

	$features = zymarg_vd_features();
	$ai_cfg   = get_option( 'zymarg_vd_ai', array() );
	$ai_on    = ! empty( $ai_cfg['enabled'] );
	$ai_prov  = isset( $ai_cfg['provider'] ) ? $ai_cfg['provider'] : 'openai';
	$ai_key   = isset( $ai_cfg['api_key'] )  ? $ai_cfg['api_key']  : '';
	$ai_model = isset( $ai_cfg['model'] )    ? $ai_cfg['model']    : '';
	?>
	<div class="wrap zymarg-vd-settings">
		<?php
		zymarg_vd_admin_header(
			__( 'ZYMARG Vendor Dashboard', 'zymarg-vendor-dashboard' ),
			__( 'Toggle dashboard features on or off. Disabled features are hidden from vendors and blocked on direct access.', 'zymarg-vendor-dashboard' )
		);
		?>

		<form method="post">
			<?php wp_nonce_field( 'zymarg_vd_settings' ); ?>
			<input type="hidden" name="zymarg_vd_save" value="1">

			<div class="zvds-toggles">
				<?php foreach ( zymarg_vd_feature_registry() as $key => $label ) : ?>
					<div class="zvds-toggle-row">
						<div class="zvds-toggle-info">
							<span class="zvds-toggle-label"><?php echo wp_kses( $label, array() ); ?></span>
						</div>
						<label class="zvds-switch">
							<input type="checkbox" name="features[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $features[ $key ] ) ); ?>>
							<span class="zvds-switch__slider"></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>

			<?php // v1.46.3 — Help Center URL for the Support section. ?>
			<div class="zvds-support-url" style="margin-top:14px;padding:14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;max-width:820px;">
				<label for="zymarg_vd_support_help_url" style="display:block;font-weight:600;margin-bottom:6px;">
					<?php esc_html_e( 'Help Center URL (for the Support section "Help Center" card)', 'zymarg-vendor-dashboard' ); ?>
				</label>
				<input
					type="url"
					id="zymarg_vd_support_help_url"
					name="zymarg_vd_support_help_url"
					value="<?php echo esc_attr( (string) get_option( 'zymarg_vd_support_help_url', '' ) ); ?>"
					placeholder="https://example.com/help"
					style="width:100%;max-width:520px;"
				>
				<p class="description" style="margin-top:6px;color:#6b7280;font-size:12px;">
					<?php esc_html_e( 'When empty, the Help Center card stays hidden even if the toggle above is on. Set this when your help content is ready.', 'zymarg-vendor-dashboard' ); ?>
				</p>
			</div>

			<?php zymarg_vd_render_nav_order_control(); ?>

			<div class="zvds-actions">
				<?php // Primary action first: a browser's implicit submission picks the first submit button. ?>
				<button type="submit" class="zvds-save-btn"><?php esc_html_e( 'Save changes', 'zymarg-vendor-dashboard' ); ?></button>
				<?php zymarg_vd_render_nav_order_reset(); ?>
			</div>
		</form>

		<!-- ── AI Greeting Intelligence ────────────────────────────── -->
		<div class="zvds-ai-section">
			<div class="zvds-ai-header">
				<div class="zvds-ai-header__icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73A2 2 0 0 1 10 4a2 2 0 0 1 2-2z"/><circle cx="7.5" cy="14.5" r="1.5"/><circle cx="16.5" cy="14.5" r="1.5"/></svg>
				</div>
				<div>
					<h2 class="zvds-ai-title"><?php esc_html_e( 'AI Greeting Intelligence', 'zymarg-vendor-dashboard' ); ?></h2>
					<p class="zvds-ai-desc"><?php esc_html_e( 'Power the dashboard subtitle with a live AI-generated insight — personalised to each vendor\'s real sales data, pending orders, stock levels, and trends. Cached per vendor for 1 hour. Falls back to smart rules silently if unconfigured.', 'zymarg-vendor-dashboard' ); ?></p>
				</div>
			</div>

			<form method="post" class="zvds-ai-form">
				<?php wp_nonce_field( 'zymarg_vd_settings' ); ?>
				<input type="hidden" name="zymarg_vd_save" value="1">
				<?php // Re-submit current feature toggles so they aren't wiped on AI-only saves. ?>
				<?php foreach ( $features as $fkey => $fval ) : ?>
					<?php if ( $fval ) : ?>
						<input type="hidden" name="features[<?php echo esc_attr( $fkey ); ?>]" value="1">
					<?php endif; ?>
				<?php endforeach; ?>

				<div class="zvds-ai-fields">

					<!-- Enable toggle -->
					<div class="zvds-ai-row zvds-ai-row--toggle">
						<div class="zvds-ai-row__label">
							<span><?php esc_html_e( 'Enable AI subtitle', 'zymarg-vendor-dashboard' ); ?></span>
							<small><?php esc_html_e( 'When on, the greeting subtitle is generated by AI. When off, smart rules (Tier 1 + 2) still run — no generic messages.', 'zymarg-vendor-dashboard' ); ?></small>
						</div>
						<label class="zvds-switch">
							<input type="checkbox" name="zymarg_vd_ai[enabled]" value="1" <?php checked( $ai_on ); ?> id="zvds-ai-toggle">
							<span class="zvds-switch__slider"></span>
						</label>
					</div>

					<!-- Provider -->
					<div class="zvds-ai-row">
						<label class="zvds-ai-row__label" for="zvds-ai-provider">
							<span><?php esc_html_e( 'AI Provider', 'zymarg-vendor-dashboard' ); ?></span>
							<small><?php esc_html_e( 'OpenAI (GPT-4o-mini) is cheaper. Anthropic (Claude Haiku) is slightly more natural. Both cost ~$0.001 per call.', 'zymarg-vendor-dashboard' ); ?></small>
						</label>
						<select name="zymarg_vd_ai[provider]" id="zvds-ai-provider" class="zvds-ai-select">
							<option value="openai"     <?php selected( $ai_prov, 'openai' ); ?>><?php esc_html_e( 'OpenAI (GPT-4o-mini)', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="anthropic"  <?php selected( $ai_prov, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude Haiku)', 'zymarg-vendor-dashboard' ); ?></option>
						</select>
					</div>

					<!-- API Key -->
					<div class="zvds-ai-row">
						<label class="zvds-ai-row__label" for="zvds-ai-key">
							<span><?php esc_html_e( 'API Key', 'zymarg-vendor-dashboard' ); ?></span>
							<small>
								<?php esc_html_e( 'OpenAI: starts with', 'zymarg-vendor-dashboard' ); ?> <code>sk-</code> &nbsp;|&nbsp;
								<?php esc_html_e( 'Anthropic: starts with', 'zymarg-vendor-dashboard' ); ?> <code>sk-ant-</code>
							</small>
						</label>
						<div class="zvds-ai-key-wrap">
							<input
								type="password"
								name="zymarg_vd_ai[api_key]"
								id="zvds-ai-key"
								class="zvds-ai-input"
								value="<?php echo esc_attr( $ai_key ); ?>"
								placeholder="<?php esc_attr_e( 'Paste your API key here', 'zymarg-vendor-dashboard' ); ?>"
								autocomplete="new-password"
							>
							<button type="button" class="zvds-ai-eye" aria-label="<?php esc_attr_e( 'Show/hide key', 'zymarg-vendor-dashboard' ); ?>" onclick="var f=document.getElementById('zvds-ai-key');f.type=f.type==='password'?'text':'password';">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
							</button>
						</div>
					</div>

					<!-- Model override -->
					<div class="zvds-ai-row">
						<label class="zvds-ai-row__label" for="zvds-ai-model">
							<span><?php esc_html_e( 'Model (optional override)', 'zymarg-vendor-dashboard' ); ?></span>
							<small><?php esc_html_e( 'Leave blank to use the recommended default for your provider. Advanced: enter any model ID your key has access to.', 'zymarg-vendor-dashboard' ); ?></small>
						</label>
						<input
							type="text"
							name="zymarg_vd_ai[model]"
							id="zvds-ai-model"
							class="zvds-ai-input"
							value="<?php echo esc_attr( $ai_model ); ?>"
							placeholder="<?php echo 'openai' === $ai_prov ? 'gpt-4o-mini' : 'claude-haiku-4-5'; ?>"
						>
					</div>

					<!-- Status indicator -->
					<div class="zvds-ai-status">
						<?php if ( $ai_on && '' !== $ai_key ) : ?>
							<span class="zvds-ai-status__dot zvds-ai-status__dot--on"></span>
							<?php esc_html_e( 'AI subtitle is active. Vendors see a new AI-generated insight every hour.', 'zymarg-vendor-dashboard' ); ?>
						<?php elseif ( $ai_on && '' === $ai_key ) : ?>
							<span class="zvds-ai-status__dot zvds-ai-status__dot--warn"></span>
							<?php esc_html_e( 'AI is enabled but no API key is set — falling back to smart rules until a key is saved.', 'zymarg-vendor-dashboard' ); ?>
						<?php else : ?>
							<span class="zvds-ai-status__dot zvds-ai-status__dot--off"></span>
							<?php esc_html_e( 'AI subtitle is off. Smart rules (Tier 1 + 2) are still active — vendors never see generic messages.', 'zymarg-vendor-dashboard' ); ?>
						<?php endif; ?>
					</div>

				</div><!-- .zvds-ai-fields -->

				<button type="submit" class="zvds-save-btn zvd-mt-8"><?php esc_html_e( 'Save AI settings', 'zymarg-vendor-dashboard' ); ?></button>
			</form>
		</div><!-- .zvds-ai-section -->

		<?php if ( function_exists( 'zymarg_vd_compat_settings_footer' ) ) { zymarg_vd_compat_settings_footer(); } ?>
	</div>

	<?php
	if ( ! $is_ajax ) {
		echo '</div><!-- #zymarg-admin-ajax-content -->';
	}
}
