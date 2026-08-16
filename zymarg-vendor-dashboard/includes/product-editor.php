<?php
/**
 * ZYMARG Vendor Dashboard — Native Product Editor.
 *
 * Lets vendors create and edit products from inside the ZYMARG shell instead of
 * handing off to Dokan's product form. Works on Dokan Lite (no Dokan Pro): a
 * complete simple-product editor (title, descriptions, images + gallery, price,
 * SKU, inventory, categories, tags, shipping class, virtual /
 * downloadable flags, status and featured).
 *
 * Toggle it from Settings -> ZYMARG Vendor ("Native product editor"). When off,
 * the Edit / Add buttons fall back to Dokan's own form.
 *
 * @package ZYMARG_Vendor_Dashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the native product editor is active.
 *
 * @return bool
 */
function zymarg_vd_product_editor_enabled() {
	// When Dokan Pro is active it owns full product management (all types),
	// so the native simple-product editor stands down. On Lite-only it runs.
	if ( function_exists( 'zymarg_vd_pro_active' ) && zymarg_vd_pro_active() ) {
		return false;
	}
	return ! function_exists( 'zymarg_vd_feature_enabled' ) || zymarg_vd_feature_enabled( 'product_editor' );
}

/**
 * Register the toggle.
 *
 * @param array $registry Feature registry.
 * @return array
 */
function zymarg_vd_product_editor_registry( $registry ) {
	$registry['product_editor'] = __( 'Native product editor (add / edit products in the dashboard)', 'zymarg-vendor-dashboard' );
	return $registry;
}
add_filter( 'zymarg_vd_feature_registry', 'zymarg_vd_product_editor_registry' );

/**
 * Register the in-shell section key.
 *
 * @param array $sections Native section keys.
 * @return array
 */
function zymarg_vd_product_editor_native_section( $sections ) {
	if ( zymarg_vd_product_editor_enabled() ) {
		$sections[] = 'product-edit';
	}
	return $sections;
}
add_filter( 'zymarg_os_vendor_native_sections', 'zymarg_vd_product_editor_native_section' );

/**
 * Point "Edit" at the native editor.
 *
 * @param string $url        Default URL.
 * @param int    $product_id Product ID.
 * @return string
 */
function zymarg_vd_product_editor_edit_url( $url, $product_id ) {
	if ( zymarg_vd_product_editor_enabled() && function_exists( 'zymarg_os_vendor_section_url' ) ) {
		return add_query_arg( 'pid', (int) $product_id, zymarg_os_vendor_section_url( 'product-edit' ) );
	}
	return $url;
}
add_filter( 'zymarg_os_vendor_product_edit_url', 'zymarg_vd_product_editor_edit_url', 10, 2 );

/**
 * Point "Add Product" at the native editor.
 *
 * @param string $url Default URL.
 * @return string
 */
function zymarg_vd_product_editor_new_url( $url ) {
	if ( zymarg_vd_product_editor_enabled() && function_exists( 'zymarg_os_vendor_section_url' ) ) {
		return add_query_arg( 'pid', 'new', zymarg_os_vendor_section_url( 'product-edit' ) );
	}
	return $url;
}
add_filter( 'zymarg_os_vendor_new_product_url', 'zymarg_vd_product_editor_new_url' );

/**
 * Render the editor section.
 *
 * @param string  $html   Existing HTML.
 * @param string  $active Active section.
 * @param WP_User $user   Current user.
 * @return string
 */
function zymarg_vd_product_editor_render( $html, $active, $user ) {
	if ( 'product-edit' !== $active || ! zymarg_vd_product_editor_enabled() ) {
		return $html;
	}
	return zymarg_vd_render_product_editor( $user );
}
add_filter( 'zymarg_os_vendor_render_section', 'zymarg_vd_product_editor_render', 10, 3 );

/**
 * Enqueue editor assets.
 *
 * @param string $ver Plugin version.
 * @return void
 */
function zymarg_vd_product_editor_assets( $ver ) {
	if ( ! zymarg_vd_product_editor_enabled() ) {
		return;
	}
	if ( function_exists( 'zymarg_vd_enqueue_addons_css' ) ) {
		zymarg_vd_enqueue_addons_css( $ver );
	}
	wp_enqueue_script(
		'zymarg-vd-product-editor',
		ZYMARG_VD_URL . 'assets/js/product-editor.js',
		array(),
		$ver,
		true
	);
	wp_enqueue_script(
		'zymarg-vd-product-attributes',
		ZYMARG_VD_URL . 'assets/js/product-attributes.js',
		array( 'zymarg-vd-product-editor' ),
		$ver,
		true
	);
	wp_enqueue_script(
		'zymarg-vd-product-variations',
		ZYMARG_VD_URL . 'assets/js/product-variations.js',
		array( 'zymarg-vd-product-editor' ),
		$ver,
		true
	);
	wp_localize_script(
		'zymarg-vd-product-editor',
		'ZymargProductEditor',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_vd_product_editor' ),
			'i18n'    => array(
				'saving'  => __( 'Saving…', 'zymarg-vendor-dashboard' ),
				'error'   => __( 'Something went wrong. Please try again.', 'zymarg-vendor-dashboard' ),
				'noTitle' => __( 'Please enter a product name.', 'zymarg-vendor-dashboard' ),
			),
		)
	);
}
add_action( 'zymarg_os_vendor_enqueue_assets', 'zymarg_vd_product_editor_assets' );

/* ====================================================================== *
 * Section render
 * ====================================================================== */

/**
 * Resolve the product being edited from the request, enforcing ownership.
 *
 * @param int $user_id Current user ID.
 * @return array{product:?WC_Product,is_new:bool,error:string}
 */
function zymarg_vd_product_editor_resolve( $user_id ) {
	$raw = isset( $_GET['pid'] ) ? sanitize_text_field( wp_unslash( $_GET['pid'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification

	if ( 'new' === $raw || '' === $raw || '0' === $raw ) {
		return array(
			'product' => null,
			'is_new'  => true,
			'error'   => '',
		);
	}

	$product = wc_get_product( (int) $raw );
	if ( ! $product ) {
		return array(
			'product' => null,
			'is_new'  => true,
			'error'   => __( 'That product could not be found.', 'zymarg-vendor-dashboard' ),
		);
	}

	if ( ! zymarg_vd_product_editor_can_edit( $product, $user_id ) ) {
		return array(
			'product' => null,
			'is_new'  => false,
			'error'   => __( 'You can only edit your own products.', 'zymarg-vendor-dashboard' ),
		);
	}

	return array(
		'product' => $product,
		'is_new'  => false,
		'error'   => '',
	);
}

/**
 * Whether a user may edit a product (its author, or a shop manager / admin).
 *
 * @param WC_Product $product Product.
 * @param int        $user_id User ID.
 * @return bool
 */
function zymarg_vd_product_editor_can_edit( $product, $user_id ) {
	$author = (int) get_post_field( 'post_author', $product->get_id() );
	if ( $author === (int) $user_id ) {
		return true;
	}
	return user_can( $user_id, 'manage_woocommerce' );
}

/**
 * Render the product editor form.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function zymarg_vd_render_product_editor( $user ) {
	$resolved = zymarg_vd_product_editor_resolve( (int) $user->ID );
	$product  = $resolved['product'];
	$is_new   = $resolved['is_new'];
	$back     = zymarg_os_vendor_section_url( 'products' );

	if ( $resolved['error'] ) {
		return '<div class="zymarg-vendor-card zymarg-vendor-soon">'
			. '<h2>' . esc_html__( 'Not available', 'zymarg-vendor-dashboard' ) . '</h2>'
			. '<p>' . esc_html( $resolved['error'] ) . '</p>'
			. '<a class="zymarg-vendor-soon__btn" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to Products', 'zymarg-vendor-dashboard' ) . '</a>'
			. '</div>';
	}

	// Current values.
	$pid          = $product ? $product->get_id() : 0;
	$title        = $product ? $product->get_name() : '';
	$desc         = $product ? $product->get_description() : '';
	$short        = $product ? $product->get_short_description() : '';
	$regular      = $product ? $product->get_regular_price() : '';
	$sale         = $product ? $product->get_sale_price() : '';
	$sku          = $product ? $product->get_sku() : '';
	$manage_stock = $product ? $product->get_manage_stock() : false;
	$stock_qty    = $product ? $product->get_stock_quantity() : '';
	$stock_status = $product ? $product->get_stock_status() : 'instock';
	$virtual      = $product ? $product->is_virtual() : false;
	$downloadable = $product ? $product->is_downloadable() : false;
	$featured     = $product ? $product->is_featured() : false;
	$status       = $product ? $product->get_status() : 'publish';
	$cat_ids      = $product ? $product->get_category_ids() : array();
	$tags         = '';
	if ( $product ) {
		$terms = get_the_terms( $pid, 'product_tag' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$tags = implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}
	}
	$img_id  = $product ? $product->get_image_id() : 0;
	$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';

	// Shipping / dimensions.
	$weight            = $product ? $product->get_weight() : '';
	$length            = $product ? $product->get_length() : '';
	$width             = $product ? $product->get_width() : '';
	$height            = $product ? $product->get_height() : '';
	$shipping_class_id = $product ? $product->get_shipping_class_id() : 0;

	// Gallery.
	$gallery_ids = $product ? $product->get_gallery_image_ids() : array();

	// Product type detection.
	$product_type = 'simple';
	if ( $product && $product->is_type( 'variable' ) ) {
		$product_type = 'variable';
	}

	// Existing attributes (for variable products).
	$existing_attributes = array();
	if ( $product ) {
		$raw_attrs = $product->get_attributes();
		if ( ! empty( $raw_attrs ) ) {
			foreach ( $raw_attrs as $attr_key => $attr_obj ) {
				if ( is_a( $attr_obj, 'WC_Product_Attribute' ) ) {
					$existing_attributes[] = array(
						'name'         => $attr_obj->get_name(),
						'is_taxonomy'  => $attr_obj->is_taxonomy() ? 1 : 0,
						'value'        => $attr_obj->is_taxonomy() ? '' : implode( ' | ', $attr_obj->get_options() ),
						'terms'        => $attr_obj->is_taxonomy() ? $attr_obj->get_options() : array(),
						'is_variation' => $attr_obj->get_variation() ? 1 : 0,
						'is_visible'   => $attr_obj->get_visible() ? 1 : 0,
						'position'     => $attr_obj->get_position(),
					);
				}
			}
		}
	}

	// Global attribute taxonomies for the dropdown.
	$global_attributes = wc_get_attribute_taxonomies();

	$heading = $is_new ? __( 'Add product', 'zymarg-vendor-dashboard' ) : __( 'Edit product', 'zymarg-vendor-dashboard' );

	ob_start();
	?>
	<header class="zymarg-vendor-greeting zymarg-vendor-greeting--row">
		<div>
			<h1 class="zymarg-vendor-greeting__title"><?php echo esc_html( $heading ); ?></h1>
			<p class="zymarg-vendor-greeting__sub"><?php esc_html_e( 'Create or update a product without leaving your dashboard.', 'zymarg-vendor-dashboard' ); ?></p>
		</div>
		<a class="zymarg-vendor-cta zymarg-vendor-cta--ghost" href="<?php echo esc_url( $back ); ?>">
			<span><?php esc_html_e( 'Back to Products', 'zymarg-vendor-dashboard' ); ?></span>
		</a>
	</header>

	<form class="zymarg-zpe-form" id="zymarg-zpe-form" enctype="multipart/form-data" data-redirect="<?php echo esc_url( $back ); ?>">
		<input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">

		<div class="zymarg-zpe-layout">

			<!-- General card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'General', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Product type', 'zymarg-vendor-dashboard' ); ?></span>
						<select name="product_type" id="zymarg-zpe-product-type">
							<option value="simple" <?php selected( $product_type, 'simple' ); ?>><?php esc_html_e( 'Simple product', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="variable" <?php selected( $product_type, 'variable' ); ?>><?php esc_html_e( 'Variable product', 'zymarg-vendor-dashboard' ); ?></option>
						</select>
					</label>

					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Product name', 'zymarg-vendor-dashboard' ); ?> *</span>
						<input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" required maxlength="200">
					</label>

					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Short description', 'zymarg-vendor-dashboard' ); ?></span>
						<textarea name="short_description" rows="3"><?php echo esc_textarea( $short ); ?></textarea>
					</label>

					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Full description', 'zymarg-vendor-dashboard' ); ?></span>
						<textarea name="description" rows="5"><?php echo esc_textarea( $desc ); ?></textarea>
					</label>
				</div>
			</div><!-- /.zymarg-zpe-card General -->

			<!-- Pricing card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left" id="zymarg-zpe-pricing" <?php echo 'variable' === $product_type ? 'hidden' : ''; ?>>
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Pricing', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Regular price', 'zymarg-vendor-dashboard' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</span>
							<input type="number" name="regular_price" value="<?php echo esc_attr( $regular ); ?>" step="0.01" min="0">
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Sale price', 'zymarg-vendor-dashboard' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</span>
							<input type="number" name="sale_price" value="<?php echo esc_attr( $sale ); ?>" step="0.01" min="0">
						</label>
					</div>
				</div>
			</div><!-- /.zymarg-zpe-card Pricing -->

			<!-- Attributes card (variable products) -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left" id="zymarg-zpe-attributes" <?php echo 'variable' !== $product_type ? 'hidden' : ''; ?>>
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Attributes', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<p class="zymarg-zpe-attr-hint"><?php esc_html_e( 'Add attributes like Size or Color. Mark them "Used for variations" to create product variations in Stage 2.', 'zymarg-vendor-dashboard' ); ?></p>

					<div id="zymarg-zpe-attr-list">
						<?php
						// Render existing attributes.
						if ( ! empty( $existing_attributes ) ) {
							foreach ( $existing_attributes as $idx => $attr ) {
								zymarg_vd_render_attribute_row( $idx, $attr, $global_attributes );
							}
						}
						?>
					</div>

					<div class="zymarg-zpe-attr-add">
						<select id="zymarg-zpe-attr-source">
							<option value=""><?php esc_html_e( 'Custom attribute', 'zymarg-vendor-dashboard' ); ?></option>
							<?php if ( ! empty( $global_attributes ) ) : ?>
								<?php foreach ( $global_attributes as $tax_attr ) : ?>
									<option value="<?php echo esc_attr( 'pa_' . $tax_attr->attribute_name ); ?>"><?php echo esc_html( $tax_attr->attribute_label ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<button type="button" class="zymarg-vendor-cta zymarg-vendor-cta--sm" id="zymarg-zpe-attr-add-btn">
							<span><?php esc_html_e( '+ Add attribute', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
					</div>

					<!-- Template for new attribute rows (used by JS) -->
					<template id="zymarg-zpe-attr-row-template">
						<div class="zymarg-zpe-attr-row" data-index="__INDEX__">
							<div class="zymarg-zpe-attr-row__header">
								<span class="zymarg-zpe-attr-row__name-display"></span>
								<button type="button" class="zymarg-zpe-attr-row__remove">&times;</button>
							</div>
							<div class="zymarg-zpe-attr-row__body">
								<input type="hidden" name="attribute_names[]" value="">
								<input type="hidden" name="attribute_is_taxonomy[]" value="0">
								<input type="hidden" name="attribute_position[]" value="__INDEX__">
								<div class="zymarg-zpe-attr-row__name-field">
									<label class="zymarg-zp-field">
										<span class="zymarg-zp-field__label"><?php esc_html_e( 'Name', 'zymarg-vendor-dashboard' ); ?></span>
										<input type="text" class="zymarg-zpe-attr-name" placeholder="<?php esc_attr_e( 'e.g. Size', 'zymarg-vendor-dashboard' ); ?>">
									</label>
								</div>
								<div class="zymarg-zpe-attr-row__values-field">
									<label class="zymarg-zp-field">
										<span class="zymarg-zp-field__label"><?php esc_html_e( 'Values (pipe separated)', 'zymarg-vendor-dashboard' ); ?></span>
										<input type="text" class="zymarg-zpe-attr-values" name="attribute_values[]" placeholder="<?php esc_attr_e( 'e.g. S | M | L | XL', 'zymarg-vendor-dashboard' ); ?>">
									</label>
								</div>
								<label class="zymarg-zp-check">
									<input type="checkbox" class="zymarg-zpe-attr-variation" name="attribute_variation[]" value="__INDEX__" checked>
									<?php esc_html_e( 'Used for variations', 'zymarg-vendor-dashboard' ); ?>
								</label>
							</div>
						</div>
					</template>

					<?php
					// Pass global attribute terms as JSON for JS usage.
					$global_terms_data = array();
					if ( ! empty( $global_attributes ) ) {
						foreach ( $global_attributes as $tax_attr ) {
							$taxonomy_name = 'pa_' . $tax_attr->attribute_name;
							$terms         = get_terms( array(
								'taxonomy'   => $taxonomy_name,
								'hide_empty' => false,
								'orderby'    => 'name',
							) );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
								$global_terms_data[ $taxonomy_name ] = array();
								foreach ( $terms as $term ) {
									$global_terms_data[ $taxonomy_name ][] = array(
										'slug' => $term->slug,
										'name' => $term->name,
										'id'   => $term->term_id,
									);
								}
							}
						}
					}
					?>
					<script type="application/json" id="zymarg-zpe-global-attr-terms"><?php echo wp_json_encode( $global_terms_data ); ?></script>
				</div>
			</div><!-- /.zymarg-zpe-card Attributes -->

			<!-- Variations card (variable products, only after first save) -->
			<?php if ( 'variable' === $product_type && $pid ) : ?>
			<div class="zymarg-zpe-card zymarg-zpe-card--left zymarg-zpe-card--variations" id="zymarg-zpe-variations">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Variations', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<div class="zymarg-zpe-var-actions">
						<button type="button" class="zymarg-vendor-cta zymarg-vendor-cta--sm" id="zymarg-zpe-generate-variations">
							<span><?php esc_html_e( 'Generate variations', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
						<span class="zymarg-zpe-var-msg" role="status" aria-live="polite"></span>
					</div>

					<div class="zymarg-zpe-var-list" id="zymarg-zpe-var-list">
						<?php echo zymarg_vd_render_variations_list( $pid ); // phpcs:ignore ?>
					</div>

					<div class="zymarg-zpe-var-save-wrap" id="zymarg-zpe-var-save-wrap">
						<button type="button" class="zymarg-vendor-cta zymarg-vendor-cta--sm" id="zymarg-zpe-save-variations">
							<span><?php esc_html_e( 'Save variations', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
						<span class="zymarg-zpe-var-save-msg" role="status" aria-live="polite"></span>
					</div>
				</div>
			</div><!-- /.zymarg-zpe-card Variations -->
			<?php endif; ?>

			<!-- Inventory card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Inventory', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'SKU', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" name="sku" value="<?php echo esc_attr( $sku ); ?>" maxlength="100">
					</label>

				<div class="zymarg-zpe-inv">
					<label class="zymarg-zp-check">
						<input type="checkbox" name="manage_stock" value="1" id="zymarg-zpe-manage" <?php checked( $manage_stock ); ?>>
						<?php esc_html_e( 'Track stock quantity', 'zymarg-vendor-dashboard' ); ?>
					</label>

					<label class="zymarg-zp-field" id="zymarg-zpe-stockqty" <?php echo $manage_stock ? '' : 'hidden'; ?>>
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Stock quantity', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="number" name="stock_quantity" value="<?php echo esc_attr( $stock_qty ); ?>" step="1">
					</label>

					<label class="zymarg-zp-field" id="zymarg-zpe-stockstatus" <?php echo $manage_stock ? 'hidden' : ''; ?>>
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Stock status', 'zymarg-vendor-dashboard' ); ?></span>
						<select name="stock_status">
							<option value="instock" <?php selected( $stock_status, 'instock' ); ?>><?php esc_html_e( 'In stock', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="outofstock" <?php selected( $stock_status, 'outofstock' ); ?>><?php esc_html_e( 'Out of stock', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="onbackorder" <?php selected( $stock_status, 'onbackorder' ); ?>><?php esc_html_e( 'On backorder', 'zymarg-vendor-dashboard' ); ?></option>
						</select>
					</label>
				</div>
				</div>
			</div><!-- /.zymarg-zpe-card Inventory -->

			<!-- Shipping card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--left zymarg-zpe-card--shipping" id="zymarg-zpe-shipping" <?php echo $virtual ? 'hidden' : ''; ?>>
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Shipping', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Weight (%s)', 'zymarg-vendor-dashboard' ), esc_html( get_option( 'woocommerce_weight_unit', 'kg' ) ) ); ?></span>
							<input type="number" name="weight" value="<?php echo esc_attr( $weight ); ?>" step="0.01" min="0">
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php esc_html_e( 'Shipping class', 'zymarg-vendor-dashboard' ); ?></span>
							<select name="shipping_class">
								<option value=""><?php esc_html_e( 'No shipping class', 'zymarg-vendor-dashboard' ); ?></option>
								<?php
								$shipping_classes = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
								if ( ! is_wp_error( $shipping_classes ) && ! empty( $shipping_classes ) ) {
									foreach ( $shipping_classes as $sc ) {
										echo '<option value="' . esc_attr( $sc->term_id ) . '" ' . selected( $shipping_class_id, $sc->term_id, false ) . '>' . esc_html( $sc->name ) . '</option>';
									}
								}
								?>
							</select>
						</label>
					</div>
					<div class="zymarg-zpe-row">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Length (%s)', 'zymarg-vendor-dashboard' ), esc_html( get_option( 'woocommerce_dimension_unit', 'cm' ) ) ); ?></span>
							<input type="number" name="length" value="<?php echo esc_attr( $length ); ?>" step="0.01" min="0">
						</label>
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Width (%s)', 'zymarg-vendor-dashboard' ), esc_html( get_option( 'woocommerce_dimension_unit', 'cm' ) ) ); ?></span>
							<input type="number" name="width" value="<?php echo esc_attr( $width ); ?>" step="0.01" min="0">
						</label>
					</div>
					<div class="zymarg-zpe-row zymarg-zpe-row--half">
						<label class="zymarg-zp-field">
							<span class="zymarg-zp-field__label"><?php printf( esc_html__( 'Height (%s)', 'zymarg-vendor-dashboard' ), esc_html( get_option( 'woocommerce_dimension_unit', 'cm' ) ) ); ?></span>
							<input type="number" name="height" value="<?php echo esc_attr( $height ); ?>" step="0.01" min="0">
						</label>
					</div>
				</div>
			</div><!-- /.zymarg-zpe-card Shipping -->

			<!-- Publish card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--right">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Publish', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Status', 'zymarg-vendor-dashboard' ); ?></span>
						<select name="status">
							<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'Published (live)', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'zymarg-vendor-dashboard' ); ?></option>
							<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending review', 'zymarg-vendor-dashboard' ); ?></option>
						</select>
					</label>

					<label class="zymarg-zp-check">
						<input type="checkbox" name="featured" value="1" <?php checked( $featured ); ?>>
						<?php esc_html_e( 'Featured product', 'zymarg-vendor-dashboard' ); ?>
					</label>
					<label class="zymarg-zp-check">
						<input type="checkbox" name="virtual" value="1" <?php checked( $virtual ); ?>>
						<?php esc_html_e( 'Virtual (no shipping)', 'zymarg-vendor-dashboard' ); ?>
					</label>
					<label class="zymarg-zp-check">
						<input type="checkbox" name="downloadable" value="1" <?php checked( $downloadable ); ?>>
						<?php esc_html_e( 'Downloadable', 'zymarg-vendor-dashboard' ); ?>
					</label>

					<div class="zymarg-zpe-actions">
						<button type="submit" class="zymarg-vendor-cta zymarg-zpe-save">
							<?php echo zymarg_os_vendor_icon( 'plus-box' ); // phpcs:ignore ?>
							<span><?php echo $is_new ? esc_html__( 'Create product', 'zymarg-vendor-dashboard' ) : esc_html__( 'Save changes', 'zymarg-vendor-dashboard' ); ?></span>
						</button>
						<a class="zymarg-vendor-cta zymarg-vendor-cta--ghost" href="<?php echo esc_url( $back ); ?>"><span><?php esc_html_e( 'Cancel', 'zymarg-vendor-dashboard' ); ?></span></a>
					</div>
					<span class="zymarg-zp-msg" role="status" aria-live="polite"></span>
				</div>
			</div><!-- /.zymarg-zpe-card Publish -->

			<!-- Product Image card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--right">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Product Image', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<div class="zymarg-zpe-image">
						<div class="zymarg-zpe-image__preview-wrap" <?php echo $img_url ? '' : 'hidden'; ?>>
							<img src="<?php echo esc_url( $img_url ); ?>" alt="" class="zymarg-zpe-image__preview" id="zymarg-zpe-img-preview">
							<div class="zymarg-zpe-image__remove-popup" hidden>
								<button type="button" class="zymarg-zpe-image__remove-btn" id="zymarg-zpe-img-remove"><?php esc_html_e( 'Remove image', 'zymarg-vendor-dashboard' ); ?></button>
							</div>
						</div>
						<div class="zymarg-zpe-image__upload" <?php echo $img_url ? 'hidden' : ''; ?> id="zymarg-zpe-img-zone">
							<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
							<span><?php esc_html_e( 'Click to upload', 'zymarg-vendor-dashboard' ); ?></span>
						</div>
					</div>
					<input type="file" name="featured_image" accept="image/*" id="zymarg-zpe-img-input" hidden>
					<input type="hidden" name="remove_featured_image" value="0" id="zymarg-zpe-img-remove-flag">
				</div>
			</div><!-- /.zymarg-zpe-card Product Image -->

			<!-- Gallery card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--right">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Gallery', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<?php if ( ! empty( $gallery_ids ) ) : ?>
					<div class="zymarg-zpe-gallery__thumbs">
						<?php foreach ( $gallery_ids as $gid ) :
							$gurl = wp_get_attachment_image_url( $gid, 'thumbnail' );
							if ( $gurl ) : ?>
						<div class="zymarg-zpe-gallery__item" data-id="<?php echo esc_attr( $gid ); ?>">
							<img src="<?php echo esc_url( $gurl ); ?>" alt="" class="zymarg-zpe-gallery__thumb">
							<button type="button" class="zymarg-zpe-gallery__remove">&times;</button>
						</div>
						<?php endif; endforeach; ?>
					</div>
					<?php endif; ?>
					<div class="zymarg-zpe-gallery__upload" id="zymarg-zpe-gallery-zone">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
						<span><?php esc_html_e( 'Click to add images', 'zymarg-vendor-dashboard' ); ?></span>
					</div>
					<input type="file" name="gallery_images[]" accept="image/*" multiple id="zymarg-zpe-gallery-input" hidden>
					<input type="hidden" name="remove_gallery_ids" value="" id="zymarg-zpe-gallery-remove-ids">
					<div class="zymarg-zpe-gallery__thumbs zymarg-zpe-gallery__new-preview" hidden></div>
				</div>
			</div><!-- /.zymarg-zpe-card Gallery -->

			<!-- Categories & Tags card -->
			<div class="zymarg-zpe-card zymarg-zpe-card--right">
				<div class="zymarg-zpe-card__accent"></div>
				<div class="zymarg-zpe-card__header"><?php esc_html_e( 'Categories & Tags', 'zymarg-vendor-dashboard' ); ?></div>
				<div class="zymarg-zpe-card__body">
					<fieldset class="zymarg-zp-field zymarg-zpe-cats">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Categories', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" class="zymarg-zpe-cats__search" placeholder="<?php esc_attr_e( 'Search categories...', 'zymarg-vendor-dashboard' ); ?>" id="zymarg-zpe-cat-search">
						<div class="zymarg-zpe-cats__list">
							<?php echo zymarg_vd_product_editor_category_checkboxes( $cat_ids ); // phpcs:ignore ?>
						</div>
					</fieldset>

					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Tags (comma separated)', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" name="tags" value="<?php echo esc_attr( $tags ); ?>">
					</label>
				</div>
			</div><!-- /.zymarg-zpe-card Categories & Tags -->

		</div><!-- /.zymarg-zpe-layout -->
	</form>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render category checkboxes in a hierarchical tree.
 *
 * @param array $selected Selected term IDs.
 * @return string
 */
function zymarg_vd_product_editor_category_checkboxes( $selected ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => (int) apply_filters( 'zymarg_vd_product_editor_max_cats', 200 ),
			'orderby'    => 'name',
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '<p class="zymarg-vendor-note">' . esc_html__( 'No categories found.', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$selected = array_map( 'intval', (array) $selected );

	// Build a parent-to-children map.
	$children_map = array();
	foreach ( $terms as $term ) {
		$parent = (int) $term->parent;
		if ( ! isset( $children_map[ $parent ] ) ) {
			$children_map[ $parent ] = array();
		}
		$children_map[ $parent ][] = $term;
	}

	return zymarg_vd_product_editor_render_cat_tree( $children_map, $selected, 0, 0 );
}

/**
 * Recursively render category tree.
 *
 * @param array $children_map Parent-to-children map.
 * @param array $selected     Selected term IDs.
 * @param int   $parent_id    Current parent term ID.
 * @param int   $depth        Current nesting depth.
 * @return string
 */
function zymarg_vd_product_editor_render_cat_tree( $children_map, $selected, $parent_id, $depth ) {
	if ( ! isset( $children_map[ $parent_id ] ) ) {
		return '';
	}

	$out = '';
	foreach ( $children_map[ $parent_id ] as $term ) {
		$indent_class = $depth > 0 ? ' zymarg-zpe-cat--child' : '';
		$indent_style = $depth > 0 ? ' style="padding-left:' . ( $depth * 22 ) . 'px"' : '';

		$out .= '<label class="zymarg-zpe-cat' . $indent_class . '" data-depth="' . esc_attr( $depth ) . '"' . $indent_style . '>'
			. '<input type="checkbox" name="categories[]" value="' . esc_attr( $term->term_id ) . '" ' . checked( in_array( (int) $term->term_id, $selected, true ), true, false ) . '>'
			. '<span>' . esc_html( $term->name ) . '</span>'
			. '</label>';

		// Recurse into children.
		$out .= zymarg_vd_product_editor_render_cat_tree( $children_map, $selected, (int) $term->term_id, $depth + 1 );
	}

	return $out;
}

/* ====================================================================== *
 * Attribute row renderer (for existing attributes on edit)
 * ====================================================================== */

/**
 * Render a single attribute row in the editor form.
 *
 * @param int   $index            Row index.
 * @param array $attr             Attribute data (name, is_taxonomy, value, terms, is_variation, is_visible, position).
 * @param array $global_attributes Global WC attribute taxonomies.
 * @return void
 */
function zymarg_vd_render_attribute_row( $index, $attr, $global_attributes ) {
	$is_taxonomy  = ! empty( $attr['is_taxonomy'] );
	$attr_name    = $attr['name'];
	$display_name = $attr_name;

	if ( $is_taxonomy ) {
		$tax = get_taxonomy( $attr_name );
		if ( $tax ) {
			$display_name = $tax->labels->singular_name ? $tax->labels->singular_name : $tax->labels->name;
		}
	}

	$values       = $is_taxonomy ? '' : ( isset( $attr['value'] ) ? $attr['value'] : '' );
	$is_variation = ! empty( $attr['is_variation'] ) ? 1 : 0;
	$position     = isset( $attr['position'] ) ? (int) $attr['position'] : $index;

	// For taxonomy attributes, get the selected term IDs.
	$selected_terms = array();
	if ( $is_taxonomy && ! empty( $attr['terms'] ) ) {
		$selected_terms = array_map( 'intval', $attr['terms'] );
	}
	?>
	<div class="zymarg-zpe-attr-row" data-index="<?php echo esc_attr( $index ); ?>">
		<div class="zymarg-zpe-attr-row__header">
			<span class="zymarg-zpe-attr-row__name-display"><?php echo esc_html( $display_name ); ?></span>
			<button type="button" class="zymarg-zpe-attr-row__remove">&times;</button>
		</div>
		<div class="zymarg-zpe-attr-row__body">
			<input type="hidden" name="attribute_names[]" value="<?php echo esc_attr( $attr_name ); ?>">
			<input type="hidden" name="attribute_is_taxonomy[]" value="<?php echo $is_taxonomy ? '1' : '0'; ?>">
			<input type="hidden" name="attribute_position[]" value="<?php echo esc_attr( $position ); ?>">

			<?php if ( ! $is_taxonomy ) : ?>
				<div class="zymarg-zpe-attr-row__name-field">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Name', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" class="zymarg-zpe-attr-name" value="<?php echo esc_attr( $attr_name ); ?>">
					</label>
				</div>
				<div class="zymarg-zpe-attr-row__values-field">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Values (pipe separated)', 'zymarg-vendor-dashboard' ); ?></span>
						<input type="text" class="zymarg-zpe-attr-values" name="attribute_values[]" value="<?php echo esc_attr( $values ); ?>" placeholder="<?php esc_attr_e( 'e.g. S | M | L | XL', 'zymarg-vendor-dashboard' ); ?>">
					</label>
				</div>
			<?php else : ?>
				<div class="zymarg-zpe-attr-row__values-field zymarg-zpe-attr-row__values-field--taxonomy" data-taxonomy="<?php echo esc_attr( $attr_name ); ?>">
					<label class="zymarg-zp-field">
						<span class="zymarg-zp-field__label"><?php esc_html_e( 'Select terms', 'zymarg-vendor-dashboard' ); ?></span>
						<div class="zymarg-zpe-attr-terms">
							<?php
							$terms = get_terms( array(
								'taxonomy'   => $attr_name,
								'hide_empty' => false,
								'orderby'    => 'name',
							) );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$checked = in_array( (int) $term->term_id, $selected_terms, true ) ? 'checked' : '';
									echo '<label class="zymarg-zp-check zymarg-zpe-attr-term">';
									echo '<input type="checkbox" name="attribute_term_' . esc_attr( $attr_name ) . '[]" value="' . esc_attr( $term->slug ) . '" ' . $checked . '>';
									echo esc_html( $term->name );
									echo '</label>';
								}
							}
							?>
						</div>
					</label>
					<input type="hidden" class="zymarg-zpe-attr-values" name="attribute_values[]" value="">
				</div>
			<?php endif; ?>

			<label class="zymarg-zp-check">
				<input type="checkbox" class="zymarg-zpe-attr-variation" name="attribute_variation[]" value="<?php echo esc_attr( $index ); ?>" <?php checked( $is_variation ); ?>>
				<?php esc_html_e( 'Used for variations', 'zymarg-vendor-dashboard' ); ?>
			</label>
		</div>
	</div>
	<?php
}

/* ====================================================================== *
 * Save (AJAX)
 * ====================================================================== */

/**
 * AJAX: create or update a simple product.
 *
 * @return void
 */
function zymarg_vd_product_editor_save_ajax() {
	check_ajax_referer( 'zymarg_vd_product_editor', 'nonce' );

	if ( ! is_user_logged_in() || ! function_exists( 'zymarg_os_can_view_vendor_dashboard' ) || ! zymarg_os_can_view_vendor_dashboard() ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zymarg-vendor-dashboard' ) ), 403 );
	}
	if ( ! zymarg_vd_product_editor_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'The product editor is turned off.', 'zymarg-vendor-dashboard' ) ), 403 );
	}

	$user_id = get_current_user_id();
	$pid     = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
	$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

	if ( '' === $title ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a product name.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Determine product type from the form.
	$requested_type = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : 'simple';
	if ( ! in_array( $requested_type, array( 'simple', 'variable' ), true ) ) {
		$requested_type = 'simple';
	}

	// Load or create.
	if ( $pid ) {
		$product = wc_get_product( $pid );
		if ( ! $product || ! zymarg_vd_product_editor_can_edit( $product, $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You can only edit your own products.', 'zymarg-vendor-dashboard' ) ), 403 );
		}
		// Allow simple and variable products to be edited.
		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => __( 'This product type can only be edited in the full product form.', 'zymarg-vendor-dashboard' ) ) );
		}

		// Handle type switching: if requested type differs, re-instantiate.
		if ( 'variable' === $requested_type && ! $product->is_type( 'variable' ) ) {
			// Convert simple to variable.
			wp_set_object_terms( $pid, 'variable', 'product_type' );
			// Clear WC product cache so wc_get_product returns the right class.
			wc_delete_product_transients( $pid );
			clean_post_cache( $pid );
			$product = wc_get_product( $pid );
		} elseif ( 'simple' === $requested_type && ! $product->is_type( 'simple' ) ) {
			// Convert variable back to simple.
			wp_set_object_terms( $pid, 'simple', 'product_type' );
			wc_delete_product_transients( $pid );
			clean_post_cache( $pid );
			$product = wc_get_product( $pid );
		}
	} else {
		if ( 'variable' === $requested_type ) {
			$product = new WC_Product_Variable();
		} else {
			$product = new WC_Product_Simple();
		}
	}

	// Core text fields.
	$product->set_name( $title );
	$product->set_short_description( isset( $_POST['short_description'] ) ? wp_kses_post( wp_unslash( $_POST['short_description'] ) ) : '' );
	$product->set_description( isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '' );

	// Pricing (only for simple products - variable products derive price from variations).
	if ( 'simple' === $requested_type ) {
		$regular = isset( $_POST['regular_price'] ) ? wc_clean( wp_unslash( $_POST['regular_price'] ) ) : '';
		$sale    = isset( $_POST['sale_price'] ) ? wc_clean( wp_unslash( $_POST['sale_price'] ) ) : '';
		$product->set_regular_price( '' === $regular ? '' : wc_format_decimal( $regular ) );
		if ( '' !== $sale && '' !== $regular && (float) $sale >= (float) $regular ) {
			wp_send_json_error( array( 'message' => __( 'The sale price must be lower than the regular price.', 'zymarg-vendor-dashboard' ) ) );
		}
		$product->set_sale_price( '' === $sale ? '' : wc_format_decimal( $sale ) );

		/*
		 * The sale start/end date fields were removed from this editor — vendors
		 * set a sale price, not a campaign window.
		 *
		 * Deliberately DO NOT call set_date_on_sale_from()/set_date_on_sale_to()
		 * here. The fields no longer post, so passing the missing values through
		 * would resolve to null and silently wipe any schedule an admin had set
		 * in wp-admin every time the vendor saved. Leaving the setters out means
		 * existing dates are preserved untouched.
		 */
	}

	// SKU (guard against duplicates).
	$sku = isset( $_POST['sku'] ) ? wc_clean( wp_unslash( $_POST['sku'] ) ) : '';
	try {
		$product->set_sku( $sku );
	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => __( 'That SKU is already used by another product.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Inventory.
	$manage = ! empty( $_POST['manage_stock'] );
	$product->set_manage_stock( $manage );
	if ( $manage ) {
		$qty = isset( $_POST['stock_quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['stock_quantity'] ) ) : 0;
		$product->set_stock_quantity( $qty );
		$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
	} else {
		$status_in = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : 'instock';
		if ( ! in_array( $status_in, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$status_in = 'instock';
		}
		$product->set_stock_status( $status_in );
	}

	// Flags.
	$product->set_virtual( ! empty( $_POST['virtual'] ) );
	$product->set_downloadable( ! empty( $_POST['downloadable'] ) );
	$product->set_featured( ! empty( $_POST['featured'] ) );

	// Weight and dimensions (only relevant for non-virtual products).
	if ( empty( $_POST['virtual'] ) ) {
		$product->set_weight( isset( $_POST['weight'] ) ? wc_clean( wp_unslash( $_POST['weight'] ) ) : '' );
		$product->set_length( isset( $_POST['length'] ) ? wc_clean( wp_unslash( $_POST['length'] ) ) : '' );
		$product->set_width( isset( $_POST['width'] ) ? wc_clean( wp_unslash( $_POST['width'] ) ) : '' );
		$product->set_height( isset( $_POST['height'] ) ? wc_clean( wp_unslash( $_POST['height'] ) ) : '' );
	} else {
		$product->set_weight( '' );
		$product->set_length( '' );
		$product->set_width( '' );
		$product->set_height( '' );
	}

	// Shipping class.
	$shipping_class = isset( $_POST['shipping_class'] ) ? absint( wp_unslash( $_POST['shipping_class'] ) ) : 0;
	$product->set_shipping_class_id( $shipping_class );

	// Status.
	$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
	if ( ! in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ) {
		$status = 'publish';
	}
	$product->set_status( $status );

	// Categories.
	if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
		$cats = array_map( 'intval', wp_unslash( $_POST['categories'] ) );
		$product->set_category_ids( array_filter( $cats ) );
	} else {
		$product->set_category_ids( array() );
	}

	// Tags.
	$tags = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
	$tag_names = array_filter( array_map( 'trim', explode( ',', $tags ) ) );

	// Save (assign author for new products).
	$is_new = ! $pid;
	$product->save();
	$new_id = $product->get_id();

	if ( $is_new && $new_id ) {
		wp_update_post(
			array(
				'ID'          => $new_id,
				'post_author' => $user_id,
			)
		);
	}

	// Tags (after save, needs the ID).
	wp_set_object_terms( $new_id, $tag_names ? $tag_names : null, 'product_tag', false );

	// Save attributes (for variable products).
	if ( 'variable' === $requested_type && isset( $_POST['attribute_names'] ) && is_array( $_POST['attribute_names'] ) ) {
		$attr_names      = array_map( 'sanitize_text_field', wp_unslash( $_POST['attribute_names'] ) );
		$attr_is_tax     = isset( $_POST['attribute_is_taxonomy'] ) ? array_map( 'absint', wp_unslash( $_POST['attribute_is_taxonomy'] ) ) : array();
		$attr_positions  = isset( $_POST['attribute_position'] ) ? array_map( 'absint', wp_unslash( $_POST['attribute_position'] ) ) : array();
		$attr_values_raw = isset( $_POST['attribute_values'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['attribute_values'] ) ) : array();
		$attr_variations = isset( $_POST['attribute_variation'] ) ? array_map( 'absint', wp_unslash( $_POST['attribute_variation'] ) ) : array();

		$product_attributes = array();

		foreach ( $attr_names as $i => $name ) {
			if ( '' === $name ) {
				continue;
			}

			$is_taxonomy  = isset( $attr_is_tax[ $i ] ) ? (int) $attr_is_tax[ $i ] : 0;
			$position     = isset( $attr_positions[ $i ] ) ? (int) $attr_positions[ $i ] : $i;
			$values       = isset( $attr_values_raw[ $i ] ) ? $attr_values_raw[ $i ] : '';
			$is_variation = in_array( $i, $attr_variations, true ) ? 1 : 0;

			if ( $is_taxonomy ) {
				// Taxonomy attribute: name is like "pa_color".
				$taxonomy_name = $name;
				$attr_key      = sanitize_title( $taxonomy_name );

				// Get selected term slugs from the dedicated term field.
				$term_slugs = array();
				$term_field_key = 'attribute_term_' . $taxonomy_name;
				if ( isset( $_POST[ $term_field_key ] ) && is_array( $_POST[ $term_field_key ] ) ) {
					$term_slugs = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $term_field_key ] ) );
				}

				// Set terms on the product.
				wp_set_object_terms( $new_id, $term_slugs, $taxonomy_name );

				$product_attributes[ $attr_key ] = array(
					'name'         => $taxonomy_name,
					'value'        => '',
					'position'     => $position,
					'is_visible'   => 1,
					'is_variation' => $is_variation,
					'is_taxonomy'  => 1,
				);
			} else {
				// Custom attribute.
				$attr_key = sanitize_title( $name );

				// Clean pipe-separated values.
				$clean_values = implode( ' | ', array_filter( array_map( 'trim', explode( '|', $values ) ) ) );

				$product_attributes[ $attr_key ] = array(
					'name'         => $name,
					'value'        => $clean_values,
					'position'     => $position,
					'is_visible'   => 1,
					'is_variation' => $is_variation,
					'is_taxonomy'  => 0,
				);
			}
		}

		update_post_meta( $new_id, '_product_attributes', $product_attributes );
	} elseif ( 'simple' === $requested_type ) {
		// When switching back to simple, clear attributes meta (optional, keeps things tidy).
		// Note: we intentionally do NOT delete existing attributes when switching to simple -
		// they stay in the database and WC just ignores them for simple products.
	}

	// Remove featured image if flagged.
	if ( ! empty( $_POST['remove_featured_image'] ) && '1' === $_POST['remove_featured_image'] && $new_id ) {
		delete_post_thumbnail( $new_id );
	}

	// Featured image upload.
	$image_warning = '';
	if ( ! empty( $_FILES['featured_image']['tmp_name'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attach_id = media_handle_upload( 'featured_image', $new_id );
		if ( ! is_wp_error( $attach_id ) ) {
			set_post_thumbnail( $new_id, $attach_id );
		} else {
			$image_warning = $attach_id->get_error_message();
		}
	}

	// Remove gallery images if requested.
	if ( ! empty( $_POST['remove_gallery_ids'] ) ) {
		$remove_ids  = array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['remove_gallery_ids'] ) ) ) );
		$remove_ids  = array_filter( $remove_ids );
		if ( ! empty( $remove_ids ) ) {
			$current_gallery = $product->get_gallery_image_ids();
			$current_gallery = array_diff( $current_gallery, $remove_ids );
			$product->set_gallery_image_ids( array_values( $current_gallery ) );
			$product->save();
		}
	}

	// Gallery images upload.
	$gallery_warning = '';
	if ( ! empty( $_FILES['gallery_images']['tmp_name'][0] ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$gallery_ids    = $product->get_gallery_image_ids();
		$file_count     = count( $_FILES['gallery_images']['tmp_name'] );
		$gallery_failed = 0;
		$gallery_total  = 0;

		for ( $i = 0; $i < $file_count; $i++ ) {
			if ( empty( $_FILES['gallery_images']['tmp_name'][ $i ] ) ) {
				continue;
			}
			$gallery_total++;
			// Prepare single file array for media_handle_upload.
			$file_array = array(
				'name'     => $_FILES['gallery_images']['name'][ $i ],
				'type'     => $_FILES['gallery_images']['type'][ $i ],
				'tmp_name' => $_FILES['gallery_images']['tmp_name'][ $i ],
				'error'    => $_FILES['gallery_images']['error'][ $i ],
				'size'     => $_FILES['gallery_images']['size'][ $i ],
			);
			$_FILES['gallery_upload'] = $file_array;
			$gal_attach_id = media_handle_upload( 'gallery_upload', $new_id );
			if ( ! is_wp_error( $gal_attach_id ) ) {
				$gallery_ids[] = $gal_attach_id;
			} else {
				$gallery_failed++;
			}
		}

		$product->set_gallery_image_ids( $gallery_ids );
		$product->save();

		if ( $gallery_failed > 0 ) {
			/* translators: 1: number of failed images, 2: total number of gallery images attempted */
			$gallery_warning = sprintf( __( '%1$d of %2$d gallery images failed to upload.', 'zymarg-vendor-dashboard' ), $gallery_failed, $gallery_total );
		}
	}

	/**
	 * Fires after a product is saved via the native editor.
	 *
	 * @param int  $new_id  Product ID.
	 * @param int  $user_id Vendor user ID.
	 * @param bool $is_new  Whether the product was just created.
	 */
	do_action( 'zymarg_vd_product_saved', $new_id, $user_id, $is_new );

	$response_data = array(
		'message'    => $is_new ? __( 'Product created.', 'zymarg-vendor-dashboard' ) : __( 'Product updated.', 'zymarg-vendor-dashboard' ),
		'product_id' => $new_id,
	);

	if ( $image_warning ) {
		$response_data['image_warning'] = $image_warning;
	}

	if ( $gallery_warning ) {
		$response_data['gallery_warning'] = $gallery_warning;
	}

	wp_send_json_success( $response_data );
}
add_action( 'wp_ajax_zymarg_vd_product_save', 'zymarg_vd_product_editor_save_ajax' );

/* ====================================================================== *
 * Variations (Stage 2) — Render, Generate, Save, Delete
 * ====================================================================== */

/**
 * Render the variations list for a variable product.
 *
 * @param int $product_id Product ID.
 * @return string HTML.
 */
function zymarg_vd_render_variations_list( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return '';
	}

	$children = $product->get_children();
	if ( empty( $children ) ) {
		return '<p class="zymarg-zpe-var-empty">' . esc_html__( 'No variations yet. Add attributes and click "Generate variations".', 'zymarg-vendor-dashboard' ) . '</p>';
	}

	$currency = get_woocommerce_currency_symbol();
	$out      = '';

	foreach ( $children as $index => $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			continue;
		}

		$attrs         = $variation->get_attributes();
		$attr_labels   = array();
		$parent_attrs  = $product->get_attributes();

		foreach ( $attrs as $attr_key => $attr_val ) {
			$label = $attr_val;
			// Try to get a human-readable label.
			if ( taxonomy_exists( $attr_key ) ) {
				$term = get_term_by( 'slug', $attr_val, $attr_key );
				if ( $term ) {
					$label = $term->name;
				}
			}
			$attr_labels[] = $label;
		}

		$combo         = implode( ' / ', $attr_labels );
		$reg_price     = $variation->get_regular_price();
		$sale_price    = $variation->get_sale_price();
		$var_sku       = $variation->get_sku();
		$manage        = $variation->get_manage_stock();
		$stock_qty     = $variation->get_stock_quantity();
		$enabled       = 'publish' === $variation->get_status();

		$out .= '<div class="zymarg-zpe-var-row" data-variation-id="' . esc_attr( $variation_id ) . '">';
		$out .= '<div class="zymarg-zpe-var-row__header">';
		$out .= '<span class="zymarg-zpe-var-row__combo">' . esc_html( $combo ) . '</span>';
		$out .= '<button type="button" class="zymarg-zpe-var-row__remove" data-id="' . esc_attr( $variation_id ) . '" title="' . esc_attr__( 'Remove', 'zymarg-vendor-dashboard' ) . '">&times;</button>';
		$out .= '</div>';

		$out .= '<div class="zymarg-zpe-var-row__fields">';

		// Regular price.
		$out .= '<label class="zymarg-zp-field zymarg-zpe-var-field">';
		$out .= '<span class="zymarg-zp-field__label">' . esc_html__( 'Price', 'zymarg-vendor-dashboard' ) . ' (' . esc_html( $currency ) . ')</span>';
		$out .= '<input type="number" name="var_regular_price[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $reg_price ) . '" step="0.01" min="0">';
		$out .= '</label>';

		// Sale price.
		$out .= '<label class="zymarg-zp-field zymarg-zpe-var-field">';
		$out .= '<span class="zymarg-zp-field__label">' . esc_html__( 'Sale', 'zymarg-vendor-dashboard' ) . ' (' . esc_html( $currency ) . ')</span>';
		$out .= '<input type="number" name="var_sale_price[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $sale_price ) . '" step="0.01" min="0">';
		$out .= '</label>';

		// SKU.
		$out .= '<label class="zymarg-zp-field zymarg-zpe-var-field">';
		$out .= '<span class="zymarg-zp-field__label">' . esc_html__( 'SKU', 'zymarg-vendor-dashboard' ) . '</span>';
		$out .= '<input type="text" name="var_sku[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $var_sku ) . '">';
		$out .= '</label>';

		// Manage stock + stock qty.
		$out .= '<div class="zymarg-zpe-var-field zymarg-zpe-var-stock-wrap">';
		$out .= '<label class="zymarg-zp-check">';
		$out .= '<input type="checkbox" name="var_manage_stock[' . esc_attr( $variation_id ) . ']" value="1" class="zymarg-zpe-var-manage-cb" ' . checked( $manage, true, false ) . '>';
		$out .= esc_html__( 'Stock', 'zymarg-vendor-dashboard' );
		$out .= '</label>';
		$out .= '<label class="zymarg-zp-field zymarg-zpe-var-qty-field" ' . ( $manage ? '' : 'hidden' ) . '>';
		$out .= '<input type="number" name="var_stock[' . esc_attr( $variation_id ) . ']" value="' . esc_attr( $stock_qty ) . '" step="1" min="0" placeholder="' . esc_attr__( 'Qty', 'zymarg-vendor-dashboard' ) . '">';
		$out .= '</label>';
		$out .= '</div>';

		// Enabled toggle.
		$out .= '<div class="zymarg-zpe-var-field">';
		$out .= '<label class="zymarg-zp-check">';
		$out .= '<input type="checkbox" name="var_enabled[' . esc_attr( $variation_id ) . ']" value="1" ' . checked( $enabled, true, false ) . '>';
		$out .= esc_html__( 'Enabled', 'zymarg-vendor-dashboard' );
		$out .= '</label>';
		$out .= '</div>';

		$out .= '</div>'; // .zymarg-zpe-var-row__fields
		$out .= '</div>'; // .zymarg-zpe-var-row
	}

	return $out;
}

/**
 * Verify product ownership for variation AJAX endpoints.
 *
 * @param int $product_id Product ID.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function zymarg_vd_verify_variation_access( $product_id ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'not_logged_in', __( 'Not allowed.', 'zymarg-vendor-dashboard' ) );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error( 'not_found', __( 'Product not found.', 'zymarg-vendor-dashboard' ) );
	}

	$user_id = get_current_user_id();
	if ( ! zymarg_vd_product_editor_can_edit( $product, $user_id ) ) {
		return new WP_Error( 'forbidden', __( 'You can only edit your own products.', 'zymarg-vendor-dashboard' ) );
	}

	return true;
}

/**
 * AJAX: Generate variations for all missing attribute combinations.
 *
 * @return void
 */
function zymarg_vd_generate_variations_ajax() {
	check_ajax_referer( 'zymarg_vd_product_editor', 'nonce' );

	$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
	$access     = zymarg_vd_verify_variation_access( $product_id );
	if ( is_wp_error( $access ) ) {
		wp_send_json_error( array( 'message' => $access->get_error_message() ), 403 );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product->is_type( 'variable' ) ) {
		wp_send_json_error( array( 'message' => __( 'This product is not a variable product.', 'zymarg-vendor-dashboard' ) ) );
	}

	$attributes = $product->get_attributes();
	if ( empty( $attributes ) ) {
		wp_send_json_error( array( 'message' => __( 'No attributes found. Add attributes first, then save the product.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Collect attributes marked for variations.
	$variation_attrs = array();
	foreach ( $attributes as $attr_key => $attr_obj ) {
		if ( ! $attr_obj->get_variation() ) {
			continue;
		}

		$options = array();
		if ( $attr_obj->is_taxonomy() ) {
			$terms = get_terms( array(
				'taxonomy'   => $attr_obj->get_name(),
				'hide_empty' => false,
				'object_ids' => $product_id,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$options[] = $term->slug;
				}
			}
		} else {
			$options = $attr_obj->get_options();
		}

		if ( ! empty( $options ) ) {
			$variation_attrs[ $attr_obj->get_name() ] = $options;
		}
	}

	if ( empty( $variation_attrs ) ) {
		wp_send_json_error( array( 'message' => __( 'No attributes are marked "Used for variations". Check the boxes and save first.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Generate all combinations.
	$combinations = array( array() );
	foreach ( $variation_attrs as $attr_name => $values ) {
		$new_combinations = array();
		foreach ( $combinations as $combo ) {
			foreach ( $values as $val ) {
				$new_combo               = $combo;
				$new_combo[ $attr_name ] = $val;
				$new_combinations[]      = $new_combo;
			}
		}
		$combinations = $new_combinations;
	}

	// Get existing variation attribute maps.
	$existing_children = $product->get_children();
	$existing_combos   = array();
	foreach ( $existing_children as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( ! $child ) {
			continue;
		}
		$child_attrs = $child->get_attributes();
		// Normalize: sort by key for comparison.
		ksort( $child_attrs );
		$existing_combos[] = $child_attrs;
	}

	// Create missing variations.
	$created = 0;
	foreach ( $combinations as $combo ) {
		// Normalize for comparison.
		$normalized = array();
		foreach ( $combo as $k => $v ) {
			// Use the sanitized taxonomy/attribute name as key.
			$key               = sanitize_title( $k );
			$normalized[ $key ] = strtolower( $v );
		}
		ksort( $normalized );

		// Check if this combination already exists.
		$exists = false;
		foreach ( $existing_combos as $ec ) {
			$ec_normalized = array();
			foreach ( $ec as $ek => $ev ) {
				$ec_normalized[ sanitize_title( $ek ) ] = strtolower( $ev );
			}
			ksort( $ec_normalized );

			if ( $ec_normalized === $normalized ) {
				$exists = true;
				break;
			}
		}

		if ( $exists ) {
			continue;
		}

		// Create the variation.
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( $combo );
		$variation->set_status( 'publish' );
		$variation->set_stock_status( 'instock' );
		$variation->save();
		$created++;
	}

	// Clear caches so the product picks up new children.
	wc_delete_product_transients( $product_id );
	clean_post_cache( $product_id );

	// Re-render the variations list.
	$html = zymarg_vd_render_variations_list( $product_id );

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: %d: number of variations created */
			__( '%d variation(s) created.', 'zymarg-vendor-dashboard' ),
			$created
		),
		'html'    => $html,
	) );
}
add_action( 'wp_ajax_zymarg_vd_generate_variations', 'zymarg_vd_generate_variations_ajax' );

/**
 * AJAX: Save variation data.
 *
 * Structures the POST data in WooCommerce's expected format and calls
 * WC_Meta_Box_Product_Data::save_variations() for maximum compatibility.
 *
 * @return void
 */
function zymarg_vd_save_variations_ajax() {
	check_ajax_referer( 'zymarg_vd_product_editor', 'nonce' );

	$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
	$access     = zymarg_vd_verify_variation_access( $product_id );
	if ( is_wp_error( $access ) ) {
		wp_send_json_error( array( 'message' => $access->get_error_message() ), 403 );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		wp_send_json_error( array( 'message' => __( 'This product is not a variable product.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Get the variation IDs from the POST data.
	$variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['variation_ids'] ) ) : array();
	if ( empty( $variation_ids ) ) {
		wp_send_json_error( array( 'message' => __( 'No variations to save.', 'zymarg-vendor-dashboard' ) ) );
	}

	// Build the $_POST array in WC's expected format for WC_Meta_Box_Product_Data::save_variations().
	$wc_post_data = array();
	$wc_post_data['variable_post_id']       = array();
	$wc_post_data['variable_regular_price']  = array();
	$wc_post_data['variable_sale_price']     = array();
	$wc_post_data['variable_sku']            = array();
	$wc_post_data['variable_manage_stock']   = array();
	$wc_post_data['variable_stock']          = array();
	$wc_post_data['variable_stock_status']   = array();
	$wc_post_data['variable_enabled']        = array();
	$wc_post_data['variation_menu_order']    = array();

	$reg_prices    = isset( $_POST['var_regular_price'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['var_regular_price'] ) ) : array();
	$sale_prices   = isset( $_POST['var_sale_price'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['var_sale_price'] ) ) : array();
	$skus          = isset( $_POST['var_sku'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['var_sku'] ) ) : array();
	$manage_stocks = isset( $_POST['var_manage_stock'] ) ? wp_unslash( (array) $_POST['var_manage_stock'] ) : array();
	$stocks        = isset( $_POST['var_stock'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['var_stock'] ) ) : array();
	$enabled_arr   = isset( $_POST['var_enabled'] ) ? wp_unslash( (array) $_POST['var_enabled'] ) : array();

	// Collect variation attributes for the WC format.
	$var_attributes = $product->get_attributes();
	$attr_keys      = array();
	foreach ( $var_attributes as $attr_key => $attr_obj ) {
		if ( $attr_obj->get_variation() ) {
			$attr_keys[] = $attr_obj->get_name();
		}
	}

	// Initialize attribute arrays in WC format.
	foreach ( $attr_keys as $ak ) {
		$field_name = 'attribute_' . sanitize_title( $ak );
		$wc_post_data[ $field_name ] = array();
	}

	foreach ( $variation_ids as $idx => $vid ) {
		$variation = wc_get_product( $vid );
		if ( ! $variation || (int) $variation->get_parent_id() !== $product_id ) {
			continue;
		}

		$wc_post_data['variable_post_id'][]      = $vid;
		$wc_post_data['variable_regular_price'][] = isset( $reg_prices[ $vid ] ) ? $reg_prices[ $vid ] : '';
		$wc_post_data['variable_sale_price'][]    = isset( $sale_prices[ $vid ] ) ? $sale_prices[ $vid ] : '';
		$wc_post_data['variable_sku'][]           = isset( $skus[ $vid ] ) ? $skus[ $vid ] : '';
		$wc_post_data['variable_manage_stock'][]  = isset( $manage_stocks[ $vid ] ) ? 'yes' : 'no';
		$wc_post_data['variable_stock'][]         = isset( $stocks[ $vid ] ) ? $stocks[ $vid ] : '';
		$wc_post_data['variable_stock_status'][]  = 'instock';
		$wc_post_data['variable_enabled'][]       = isset( $enabled_arr[ $vid ] ) ? 'on' : '';
		$wc_post_data['variation_menu_order'][]   = $idx;

		// Per-variation attributes.
		$var_attrs = $variation->get_attributes();
		foreach ( $attr_keys as $ak ) {
			$field_name                        = 'attribute_' . sanitize_title( $ak );
			$wc_post_data[ $field_name ][]     = isset( $var_attrs[ sanitize_title( $ak ) ] ) ? $var_attrs[ sanitize_title( $ak ) ] : '';
		}
	}

	// Set WC max_variation_id needed by save_variations.
	$wc_post_data['product-type'] = 'variable';

	// Try to use WC_Meta_Box_Product_Data::save_variations() if available.
	$used_wc_save = false;
	if ( class_exists( 'WC_Meta_Box_Product_Data' ) && method_exists( 'WC_Meta_Box_Product_Data', 'save_variations' ) ) {
		// Temporarily override $_POST with our structured data.
		$original_post = $_POST;
		$_POST         = array_merge( $_POST, $wc_post_data );

		try {
			WC_Meta_Box_Product_Data::save_variations( $product_id, get_post( $product_id ) );
			$used_wc_save = true;
		} catch ( Exception $e ) {
			// Fall through to manual save.
			$used_wc_save = false;
		}

		$_POST = $original_post;
	}

	// Manual fallback if WC's save did not run.
	if ( ! $used_wc_save ) {
		foreach ( $variation_ids as $idx => $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || (int) $variation->get_parent_id() !== $product_id ) {
				continue;
			}

			$reg = isset( $reg_prices[ $vid ] ) ? $reg_prices[ $vid ] : '';
			$sal = isset( $sale_prices[ $vid ] ) ? $sale_prices[ $vid ] : '';

			$variation->set_regular_price( '' !== $reg ? wc_format_decimal( $reg ) : '' );
			$variation->set_sale_price( '' !== $sal ? wc_format_decimal( $sal ) : '' );

			$var_sku_val = isset( $skus[ $vid ] ) ? $skus[ $vid ] : '';
			try {
				$variation->set_sku( $var_sku_val );
			} catch ( Exception $e ) {
				// SKU conflict - skip.
			}

			$var_manage = isset( $manage_stocks[ $vid ] );
			$variation->set_manage_stock( $var_manage );
			if ( $var_manage ) {
				$qty = isset( $stocks[ $vid ] ) ? (int) $stocks[ $vid ] : 0;
				$variation->set_stock_quantity( $qty );
				$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
			} else {
				$variation->set_stock_status( 'instock' );
			}

			$var_enabled = isset( $enabled_arr[ $vid ] );
			$variation->set_status( $var_enabled ? 'publish' : 'private' );
			$variation->set_menu_order( $idx );
			$variation->save();
		}
	}

	// Sync parent variable product price range.
	WC_Product_Variable::sync( $product_id );
	wc_delete_product_transients( $product_id );

	wp_send_json_success( array(
		'message' => __( 'Variations saved.', 'zymarg-vendor-dashboard' ),
	) );
}
add_action( 'wp_ajax_zymarg_vd_save_variations', 'zymarg_vd_save_variations_ajax' );

/**
 * AJAX: Delete a single variation.
 *
 * @return void
 */
function zymarg_vd_delete_variation_ajax() {
	check_ajax_referer( 'zymarg_vd_product_editor', 'nonce' );

	$variation_id = isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0;
	if ( ! $variation_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid variation.', 'zymarg-vendor-dashboard' ) ) );
	}

	$variation = wc_get_product( $variation_id );
	if ( ! $variation || 'variation' !== $variation->get_type() ) {
		wp_send_json_error( array( 'message' => __( 'Variation not found.', 'zymarg-vendor-dashboard' ) ) );
	}

	$product_id = $variation->get_parent_id();
	$access     = zymarg_vd_verify_variation_access( $product_id );
	if ( is_wp_error( $access ) ) {
		wp_send_json_error( array( 'message' => $access->get_error_message() ), 403 );
	}

	// Force delete the variation post.
	wp_delete_post( $variation_id, true );

	// Sync parent.
	WC_Product_Variable::sync( $product_id );
	wc_delete_product_transients( $product_id );

	wp_send_json_success( array(
		'message' => __( 'Variation deleted.', 'zymarg-vendor-dashboard' ),
	) );
}
add_action( 'wp_ajax_zymarg_vd_delete_variation', 'zymarg_vd_delete_variation_ajax' );
