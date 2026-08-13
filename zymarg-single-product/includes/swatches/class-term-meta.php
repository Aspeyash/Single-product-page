<?php
/**
 * Admin term meta fields for global WooCommerce attribute terms.
 *
 * Ported natively from WSE_Term_Meta. Adds a color picker or image uploader
 * to each attribute term add/edit screen (Products → Attributes → Configure
 * terms). Standalone — stores swatch data under our own keys:
 *
 *   zymarg_swatch_color  → sanitized hex color string  e.g. '#ff0000'
 *   zymarg_swatch_image  → WordPress attachment ID       e.g. 42
 *
 * @version 1.0.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Term_Meta {

	const META_COLOR = 'zymarg_swatch_color';
	const META_IMAGE = 'zymarg_swatch_image';
	const IMAGE_SIZE = 'zymarg_sp_swatch';
	const NONCE      = 'zymarg_sp_term_meta';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->hooks();
	}

	private function hooks(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
		add_action( 'admin_init', array( $this, 'register_form_hooks' ) );
		add_action( 'created_term', array( $this, 'save_term_meta' ), 10, 3 );
		add_action( 'edited_term',  array( $this, 'save_term_meta' ), 10, 3 );
	}

	/**
	 * Enqueues wp-color-picker, wp.media, and our admin JS only on WC
	 * attribute term screens (edit-pa_*).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function admin_enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'edit-pa_' ) === false ) {
			return;
		}

		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();

		wp_enqueue_script(
			'zymarg-sp-term-meta',
			ZYMARG_SNGL_ASSETS . 'js/zymarg-sp-term-meta.js',
			array( 'jquery', 'wp-color-picker' ),
			ZYMARG_SNGL_VERSION,
			true
		);

		wp_localize_script(
			'zymarg-sp-term-meta',
			'ZymargSPTermMeta',
			array(
				'choose_image' => esc_html__( 'Choose Swatch Image', 'zymarg-single-product' ),
				'use_image'    => esc_html__( 'Use this image', 'zymarg-single-product' ),
				'remove'       => esc_html__( 'Remove image', 'zymarg-single-product' ),
				'placeholder'  => esc_url( wc_placeholder_img_src( self::IMAGE_SIZE ) ),
			)
		);
	}

	/**
	 * Registers add/edit form field actions for every WC attribute taxonomy.
	 */
	public function register_form_hooks(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( empty( $attribute_taxonomies ) ) {
			return;
		}
		foreach ( $attribute_taxonomies as $taxonomy_obj ) {
			$tax = wc_attribute_taxonomy_name( $taxonomy_obj->attribute_name );
			add_action( "{$tax}_add_form_fields", array( $this, 'render_add_form_field' ) );
			add_action( "{$tax}_edit_form_fields", array( $this, 'render_edit_form_field' ), 10, 2 );
		}
	}

	public function render_add_form_field( string $taxonomy ): void {
		$type = Attribute_Types::get_attribute_type( $taxonomy );
		if ( ! Attribute_Types::is_swatch_type( $taxonomy ) ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE );

		if ( 'color' === $type ) {
			?>
			<div class="form-field term-zsp-color-wrap">
				<label for="zymarg_swatch_color"><?php esc_html_e( 'Swatch Color', 'zymarg-single-product' ); ?></label>
				<input type="text" id="zymarg_swatch_color" name="zymarg_swatch_color" value="" class="zsp-color-picker" data-default-color="#ffffff" maxlength="7" />
				<p class="description"><?php esc_html_e( 'Choose the color for this attribute term swatch.', 'zymarg-single-product' ); ?></p>
			</div>
			<?php
		} elseif ( 'image' === $type ) {
			?>
			<div class="form-field term-zsp-image-wrap">
				<label for="zymarg_swatch_image"><?php esc_html_e( 'Swatch Image', 'zymarg-single-product' ); ?></label>
				<div class="zsp-image-upload-wrap">
					<img id="zsp_image_preview" src="<?php echo esc_url( wc_placeholder_img_src( self::IMAGE_SIZE ) ); ?>" alt="" class="zsp-image-preview hidden" />
					<input type="hidden" id="zymarg_swatch_image" name="zymarg_swatch_image" value="" />
					<button type="button" class="button zsp-upload-image-btn"><?php esc_html_e( 'Upload Image', 'zymarg-single-product' ); ?></button>
					<button type="button" class="button-link zsp-remove-image-btn hidden"><?php esc_html_e( 'Remove', 'zymarg-single-product' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Upload or choose an image for this attribute term swatch.', 'zymarg-single-product' ); ?></p>
			</div>
			<?php
		}
	}

	public function render_edit_form_field( $term, string $taxonomy ): void {
		$type = Attribute_Types::get_attribute_type( $taxonomy );
		if ( ! Attribute_Types::is_swatch_type( $taxonomy ) || ! is_object( $term ) ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE );

		if ( 'color' === $type ) {
			$color = (string) get_term_meta( $term->term_id, self::META_COLOR, true );
			?>
			<tr class="form-field term-zsp-color-wrap">
				<th scope="row"><label for="zymarg_swatch_color"><?php esc_html_e( 'Swatch Color', 'zymarg-single-product' ); ?></label></th>
				<td>
					<input type="text" id="zymarg_swatch_color" name="zymarg_swatch_color" value="<?php echo esc_attr( $color ); ?>" class="zsp-color-picker" data-default-color="#ffffff" maxlength="7" />
					<p class="description"><?php esc_html_e( 'Choose the color for this attribute term swatch.', 'zymarg-single-product' ); ?></p>
				</td>
			</tr>
			<?php
		} elseif ( 'image' === $type ) {
			$image_id  = (int) get_term_meta( $term->term_id, self::META_IMAGE, true );
			$image_src = $image_id ? wp_get_attachment_image_url( $image_id, self::IMAGE_SIZE ) : '';
			$has_image = ! empty( $image_src );
			?>
			<tr class="form-field term-zsp-image-wrap">
				<th scope="row"><label for="zymarg_swatch_image"><?php esc_html_e( 'Swatch Image', 'zymarg-single-product' ); ?></label></th>
				<td>
					<div class="zsp-image-upload-wrap">
						<img id="zsp_image_preview" src="<?php echo $has_image ? esc_url( $image_src ) : esc_url( wc_placeholder_img_src( self::IMAGE_SIZE ) ); ?>" alt="" class="zsp-image-preview<?php echo $has_image ? '' : ' hidden'; ?>" />
						<input type="hidden" id="zymarg_swatch_image" name="zymarg_swatch_image" value="<?php echo $image_id > 0 ? esc_attr( (string) $image_id ) : ''; ?>" />
						<button type="button" class="button zsp-upload-image-btn"><?php esc_html_e( 'Upload Image', 'zymarg-single-product' ); ?></button>
						<button type="button" class="button-link zsp-remove-image-btn<?php echo $has_image ? '' : ' hidden'; ?>"><?php esc_html_e( 'Remove', 'zymarg-single-product' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Upload or choose an image for this attribute term swatch.', 'zymarg-single-product' ); ?></p>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Saves color/image term meta on both term create and term edit.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function save_term_meta( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( strpos( $taxonomy, 'pa_' ) !== 0 ) {
			return;
		}
		if ( ! Attribute_Types::is_swatch_type( $taxonomy ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'edit_terms', $taxonomy ) ) {
			return;
		}

		$type = Attribute_Types::get_attribute_type( $taxonomy );

		if ( 'color' === $type && isset( $_POST['zymarg_swatch_color'] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST['zymarg_swatch_color'] ) );
			if ( $color ) {
				update_term_meta( $term_id, self::META_COLOR, $color );
			} else {
				delete_term_meta( $term_id, self::META_COLOR );
			}
		}

		if ( 'image' === $type && isset( $_POST['zymarg_swatch_image'] ) ) {
			$image_id = absint( $_POST['zymarg_swatch_image'] );
			if ( $image_id > 0 ) {
				update_term_meta( $term_id, self::META_IMAGE, $image_id );
			} else {
				delete_term_meta( $term_id, self::META_IMAGE );
			}
		}

		Attribute_Types::instance()->clear_cache_for( $taxonomy );
	}

	// ── Public accessors (own keys only — fresh start, no wse_* fallback) ──

	public static function get_color( int $term_id ): string {
		return (string) get_term_meta( $term_id, self::META_COLOR, true );
	}

	public static function get_image_id( int $term_id ): int {
		return (int) get_term_meta( $term_id, self::META_IMAGE, true );
	}

	public static function get_image_url( int $term_id, string $image_size = self::IMAGE_SIZE ): string {
		$image_id = self::get_image_id( $term_id );
		if ( ! $image_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $image_id, $image_size );
		return $url ?: '';
	}
}
