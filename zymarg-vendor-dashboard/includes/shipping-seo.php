<?php
/**
 * ZYMARG Vendor Dashboard — Native Shipping fees + Store SEO.
 *
 * Two Dokan-Pro-style capabilities on free Dokan Lite:
 *
 *  1. Per-vendor flat shipping fee — each vendor sets a flat shipping charge for
 *     their items plus an optional "free shipping over X" threshold. At
 *     checkout one shipping fee per vendor is added to the cart (skipped when
 *     the vendor's subtotal passes the free threshold).
 *
 *  2. Store SEO — a meta title and description for the vendor's store page,
 *     output in <title> and <meta name="description"> with no SEO plugin.
 *
 * Both render in the in-shell "Shipping" screen and are independently
 * toggleable (Settings -> ZYMARG Vendor: "Shipping fees" and "Store SEO").
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the shipping screen / fees are active.
 *
 * @return bool
 */
function zymarg_vd_shipping_enabled() {
	// Dokan Pro provides vendor shipping; defer to it to avoid double charges.
	// On Lite-only the native per-vendor shipping fee runs.
	if ( function_exists( 'zymarg_vd_pro_active' ) && zymarg_vd_pro_active() ) {
		return false;
	}
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'shipping' );
}

/**
 * Whether the Store SEO sub-feature is active.
 *
 * @return bool
 */
function zymarg_vd_seo_enabled() {
	// Stand down when a dedicated SEO solution (Yoast / Rank Math / Dokan Pro
	// Rank Math) is present, to avoid duplicate meta tags.
	if ( function_exists( 'zymarg_vd_seo_solution_active' ) && zymarg_vd_seo_solution_active() ) {
		return false;
	}
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'store_seo' );
}

/**
 * Register the toggles.
 *
 * @param array $registry Feature registry.
 * @return array
 */
function zymarg_vd_shipping_seo_registry( $registry ) {
	$registry['shipping']  = __( 'Shipping fees (native flat per-vendor + free-over)', 'zymarg-vendor-dashboard' );
	$registry['store_seo'] = __( 'Store SEO (meta title / description on the store page)', 'zymarg-vendor-dashboard' );
	return $registry;
}
add_filter( 'zymarg_vd_feature_registry', 'zymarg_vd_shipping_seo_registry' );

/**
 * Render the Shipping screen in-shell.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_shipping_native_section( $sections ) {
	if ( zymarg_vd_shipping_enabled() ) {
		$sections[] = 'shipping';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_shipping_native_section' );

/**
 * Render the section.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_shipping_render( $html, $active, $user ) {
	if ( 'shipping' !== $active || ! zymarg_vd_shipping_enabled() ) {
		return $html;
	}
	return zymarg_vd_render_shipping_seo( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_shipping_render', 10, 3 );

/**
 * Enqueue assets.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_shipping_assets( $ver ) {
	if ( ! zymarg_vd_shipping_enabled() ) {
		return;
	}
	if ( function_exists( 'zymarg_vd_enqueue_addons_css' ) ) {
		zymarg_vd_enqueue_addons_css( $ver );
	}
	wp_enqueue_script( 'zymarg-vd-shipping-seo', ZYMARG_VD_URL . 'assets/js/shipping-seo.js', array(), $ver, true );
	wp_localize_script(
		'zymarg-vd-shipping-seo',
		'ZymargShippingSeo',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_shipping_seo' ),
			'i18n'    => array(
				'saving' => __( 'Saving…', 'zymarg-vendor-dashboard' ),
				'error'  => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_shipping_assets' );

/* ====================================================================== *
 * Data
 * ====================================================================== */

/**
 * A vendor's shipping settings.
 *
 * @param int $vendor_id Vendor ID.
 * @return array{enabled:bool,fee:float,free_over:float}
 */
function zymarg_vd_vendor_shipping( $vendor_id ) {
	$saved = get_user_meta( $vendor_id, '_zv_shipping', true );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'enabled'   => ! empty( $saved['enabled'] ),
		'fee'       => isset( $saved['fee'] ) ? (float) $saved['fee'] : 0.0,
		'free_over' => isset( $saved['free_over'] ) ? (float) $saved['free_over'] : 0.0,
	);
}

/**
 * A vendor's SEO settings.
 *
 * @param int $vendor_id Vendor ID.
 * @return array{title:string,desc:string}
 */
function zymarg_vd_vendor_seo( $vendor_id ) {
	$saved = get_user_meta( $vendor_id, '_zv_seo', true );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'title' => isset( $saved['title'] ) ? $saved['title'] : '',
		'desc'  => isset( $saved['desc'] ) ? $saved['desc'] : '',
	);
}

/* ====================================================================== *
 * Section render
 * ====================================================================== */

/**
 * Render the Shipping + SEO settings screen.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_shipping_seo( $user ) {
	$vendor_id = (int) $user->ID;
	$ship      = zymarg_vd_vendor_shipping( $vendor_id );
	$seo       = zymarg_vd_vendor_seo( $vendor_id );
	$symbol    = get_woocommerce_currency_symbol();

	ob_start();
	?>
	<header class="zymarg-vendor-greeting">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php esc_html_e( 'Shipping & SEO', 'zymarg-vendor-dashboard' ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Set a flat shipping fee for your products and tune how your store appears in search.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
	</header>

	<form class="zymarg-zpe-form" id="zymarg-zsh-form">
		<div class="zymarg-zpe-layout">
			<!-- Shipping card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Shipping fee', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<label class="zymarg-zp-check">
						<input type="checkbox" name="shipping_enabled" value="1" <?php checked( $ship['enabled'] ); ?>>
						<?php esc_html_e( 'Charge a flat shipping fee for my products', 'zymarg-vendor-dashboard' ); ?>
					</label>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Flat fee per order (%s)', 'zymarg-vendor-dashboard' ), esc_html( $symbol ) ); ?></span>
						<input type="number" name="shipping_fee" value="<?php echo esc_attr( $ship['fee'] ); ?>" step="0.01" min="0">
					</label>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Free shipping when my items total reaches (%s) — 0 to disable', 'zymarg-vendor-dashboard' ), esc_html( $symbol ) ); ?></span>
						<input type="number" name="shipping_free_over" value="<?php echo esc_attr( $ship['free_over'] ); ?>" step="0.01" min="0">
					</label>
					<span class="zymarg-zp-field__hint"><?php esc_html_e( 'This adds one shipping fee for your items at checkout. Use it when your marketplace charges shipping as a fee rather than via WooCommerce shipping zones.', 'zymarg-vendor-dashboard' ); ?></span>
				</div>
			</div><!-- /.zymarg-zpe-card Shipping -->

			<!-- SEO card -->
			<?php if ( zymarg_vd_seo_enabled() ) : ?>
			<div class="zymarg-zpe-card zymarg-zpe-card--right">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Store SEO', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Meta title', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" name="seo_title" value="<?php echo esc_attr( $seo['title'] ); ?>" maxlength="70">
						<span class="zymarg-zp-field__hint"><?php esc_html_e( 'Up to ~60 characters works best.', 'zymarg-vendor-dashboard' ); ?></span>
					</label>
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Meta description', 'zymarg-vendor-dashboard' ); ?></span>
						<textarea name="seo_desc" rows="3" maxlength="320"><?php echo esc_textarea( $seo['desc'] ); ?></textarea>
						<span class="zymarg-zp-field__hint"><?php esc_html_e( 'A short summary of your store for search results (~155 characters).', 'zymarg-vendor-dashboard' ); ?></span>
					</label>
				</div>
			</div><!-- /.zymarg-zpe-card SEO -->
			<?php endif; ?>
		</div>

		<div class="zymarg-zpe-actions">
			<button type="submit" class="zymarg-vendor-cta zymarg-zpe-save zymarg-zsh-save">
				<span><?php esc_html_e( 'Save', 'zymarg-vendor-dashboard' ); ?></span>
			</button>
			<span class="zymarg-zp-msg" role="status" aria-live="polite"></span>
		</div>
	</form>
	<?php
	return (string) ob_get_clean();
}

/* ====================================================================== *
 * Save (AJAX)
 * ====================================================================== */

/**
 * AJAX: save shipping + SEO settings.
 *
 * @return void
 */
function zymarg_vd_shipping_seo_save_ajax() {
	check_ajax_referer( 'zymarg_vd_shipping_seo', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'zymarg_os_can_view_vendor_dashboard' ) || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! zymarg_vd_shipping_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'This screen is turned off.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$vendor_id = get_current_user_id();

	$shipping = array(
		'enabled'   => ! empty( $_POST['shipping_enabled'] ) ? 1 : 0,
		'fee'       => isset( $_POST['shipping_fee'] ) ? max( 0, (float) wp_unslash( $_POST['shipping_fee'] ) ) : 0.0,
		'free_over' => isset( $_POST['shipping_free_over'] ) ? max( 0, (float) wp_unslash( $_POST['shipping_free_over'] ) ) : 0.0,
	);
	update_user_meta( $vendor_id, '_zv_shipping', $shipping );

	if ( zymarg_vd_seo_enabled() ) {
		$seo = array(
			'title' => isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '',
			'desc'  => isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '',
		);
		update_user_meta( $vendor_id, '_zv_seo', $seo );
	}

	wp_send_json_success( array( 'message' => __( 'Saved.', 'zymarg-vendor-dashboard' ) ) );
}
add_action( 'wp_ajax_zymarg_vd_shipping_seo_save', 'zymarg_vd_shipping_seo_save_ajax' );

/* ====================================================================== *
 * Checkout: per-vendor shipping fee
 * ====================================================================== */

/**
 * Add one shipping fee per vendor in the cart.
 *
 * @param WC_Cart $cart Cart.
 * @return void
 */
function zymarg_vd_apply_shipping_fees( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	if ( ! zymarg_vd_shipping_enabled() ) {
		return;
	}
	if ( ! is_a( $cart, 'WC_Cart' ) ) {
		return;
	}

	// Sum each vendor's item subtotal.
	$by_vendor = array();
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['product_id'] ) ) {
			continue;
		}
		$vendor_id = (int) get_post_field( 'post_author', $item['product_id'] );
		if ( ! $vendor_id ) {
			continue;
		}
		$line = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
		if ( ! isset( $by_vendor[ $vendor_id ] ) ) {
			$by_vendor[ $vendor_id ] = 0.0;
		}
		$by_vendor[ $vendor_id ] += $line;
	}

	foreach ( $by_vendor as $vendor_id => $subtotal ) {
		$s = zymarg_vd_vendor_shipping( $vendor_id );
		if ( ! $s['enabled'] || $s['fee'] <= 0 ) {
			continue;
		}
		if ( $s['free_over'] > 0 && $subtotal >= $s['free_over'] ) {
			continue;
		}
		$store = function_exists( 'zymarg_os_vendor_store_name' ) ? zymarg_os_vendor_store_name( $vendor_id ) : '';
		$label = $store
			/* translators: %s store name. */
			? sprintf( __( 'Shipping — %s', 'zymarg-vendor-dashboard' ), $store )
			: __( 'Shipping', 'zymarg-vendor-dashboard' );
		$cart->add_fee( $label, $s['fee'], false );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'zymarg_vd_apply_shipping_fees', 20 );

/* ====================================================================== *
 * Store SEO output
 * ====================================================================== */

/**
 * The vendor whose store page is being viewed (best effort).
 *
 * @return int 0 when not on a store page.
 */
function zymarg_vd_current_store_vendor() {
	if ( function_exists( 'dokan_is_store_page' ) && dokan_is_store_page() ) {
		$author = get_query_var( 'author' );
		if ( $author ) {
			return (int) $author;
		}
		$name = get_query_var( 'author_name' );
		if ( $name ) {
			$u = get_user_by( 'slug', $name );
			if ( $u ) {
				return (int) $u->ID;
			}
		}
	}
	if ( is_author() ) {
		return (int) get_queried_object_id();
	}
	return 0;
}

/**
 * Filter the document title on the store page.
 *
 * @param array $parts Title parts.
 * @return array
 */
function zymarg_vd_seo_title( $parts ) {
	if ( ! zymarg_vd_seo_enabled() ) {
		return $parts;
	}
	$vendor = zymarg_vd_current_store_vendor();
	if ( ! $vendor ) {
		return $parts;
	}
	$seo = zymarg_vd_vendor_seo( $vendor );
	if ( '' !== $seo['title'] ) {
		$parts['title'] = $seo['title'];
	}
	return $parts;
}
add_filter( 'document_title_parts', 'zymarg_vd_seo_title' );

/**
 * Output a meta description on the store page.
 *
 * @return void
 */
function zymarg_vd_seo_meta_description() {
	if ( ! zymarg_vd_seo_enabled() ) {
		return;
	}
	$vendor = zymarg_vd_current_store_vendor();
	if ( ! $vendor ) {
		return;
	}
	$seo = zymarg_vd_vendor_seo( $vendor );
	if ( '' === $seo['desc'] ) {
		return;
	}
	echo '<meta name="description" content="' . esc_attr( $seo['desc'] ) . '" />' . "\n";
}
add_action( 'wp_head', 'zymarg_vd_seo_meta_description', 1 );
