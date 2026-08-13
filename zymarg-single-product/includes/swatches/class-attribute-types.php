<?php
/**
 * Native WooCommerce attribute-type registration and detection.
 *
 * Ported from WooSwatches for Elementor (WSE_Attribute_Types) into the
 * ZymargSP\Swatches namespace. Registers Color, Image, Label, and Button
 * as native WooCommerce attribute types so they appear in the WC attribute
 * type dropdown at Products → Attributes, renders WC's own term-selector UI
 * for those custom types on the product editor, and provides the central
 * get_attribute_type() lookup used by the renderer + term meta.
 *
 * Global (taxonomy) attributes only — no local per-product attributes.
 *
 * @version 1.0.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP\Swatches;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Attribute_Types {

	/** All swatch types this plugin supports. */
	const SUPPORTED_TYPES = array( 'color', 'image', 'label', 'button' );

	/** Fallback type — leave WooCommerce's native dropdown in place. */
	const FALLBACK_TYPE = 'select';

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,string> Request-scoped type cache keyed by attribute name (no pa_). */
	private $type_cache = array();

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
		// Register our custom types in WooCommerce's attribute type selector.
		add_filter( 'product_attributes_type_selector', array( $this, 'register_types' ) );

		// Render WC's standard term-selection UI for our custom types on the
		// product editor Attributes panel.
		add_action( 'woocommerce_product_option_terms', array( $this, 'render_term_selector_for_custom_types' ), 10, 3 );

		// Clear the in-memory type cache when a WC attribute taxonomy changes.
		add_action( 'woocommerce_attribute_added',   array( $this, 'clear_cache' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'clear_cache' ) );
		add_action( 'woocommerce_attribute_deleted', array( $this, 'clear_cache' ) );
	}

	/**
	 * Appends our swatch types to WooCommerce's built-in type list.
	 *
	 * @param array<string,string> $types Existing type key → label pairs.
	 * @return array<string,string>
	 */
	public function register_types( array $types ): array {
		$types['color']  = esc_html__( 'Color',  'zymarg-single-product' );
		$types['image']  = esc_html__( 'Image',  'zymarg-single-product' );
		$types['label']  = esc_html__( 'Label',  'zymarg-single-product' );
		$types['button'] = esc_html__( 'Button', 'zymarg-single-product' );
		return $types;
	}

	/**
	 * Renders the Value(s) UI inside WC's product-editor Attributes panel when
	 * the attribute's stored type is one of our custom swatch types. Mirrors
	 * WC's standard select UI byte-for-byte so WC's own admin JS keeps working.
	 *
	 * @param object|null           $attribute_taxonomy Row from wp_woocommerce_attribute_taxonomies.
	 * @param int                   $i                  Loop index of this attribute.
	 * @param \WC_Product_Attribute $attribute          The product attribute object.
	 */
	public function render_term_selector_for_custom_types( $attribute_taxonomy, $i, $attribute ): void {

		// Guard 1 — must be a global (taxonomy) attribute.
		if ( is_object( $attribute )
			&& method_exists( $attribute, 'is_taxonomy' )
			&& ! $attribute->is_taxonomy() ) {
			return;
		}

		// Guard 2 — only global (taxonomy) attributes have a taxonomy row.
		if ( empty( $attribute_taxonomy ) || ! isset( $attribute_taxonomy->attribute_type ) ) {
			return;
		}

		// Guard 3 — only for our custom swatch types.
		if ( ! in_array( $attribute_taxonomy->attribute_type, self::SUPPORTED_TYPES, true ) ) {
			return;
		}

		$taxonomy_name = is_object( $attribute ) && method_exists( $attribute, 'get_name' )
			? $attribute->get_name()
			: 'pa_' . $attribute_taxonomy->attribute_name;

		$selected_terms = array();
		if ( is_object( $attribute ) && method_exists( $attribute, 'get_terms' ) ) {
			$terms = $attribute->get_terms();
			if ( is_array( $terms ) ) {
				$selected_terms = $terms;
			}
		}

		// Output mirrors WC's html-product-attribute.php select block. The
		// `woocommerce` text domain is intentional so WC's translations apply.
		?>
		<select multiple="multiple"
			data-minimum_input_length="0"
			data-limit="50"
			data-return_id="id"
			data-placeholder="<?php esc_attr_e( 'Select terms', 'woocommerce' ); ?>"
			class="multiselect attribute_values wc-taxonomy-term-search"
			name="attribute_values[<?php echo esc_attr( (string) $i ); ?>][]"
			data-taxonomy="<?php echo esc_attr( $taxonomy_name ); ?>">
			<?php foreach ( $selected_terms as $term ) : ?>
				<?php if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) { continue; } ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" selected="selected">
					<?php echo esc_html( apply_filters( 'woocommerce_product_attribute_term_name', $term->name, $term ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button class="button plus select_all_attributes"><?php esc_html_e( 'Select all', 'woocommerce' ); ?></button>
		<button class="button minus select_no_attributes"><?php esc_html_e( 'Select none', 'woocommerce' ); ?></button>
		<button class="button fr plus add_new_attribute"><?php esc_html_e( 'Create value', 'woocommerce' ); ?></button>
		<?php
	}

	/**
	 * Returns the swatch type for a given global attribute.
	 *
	 * @param string $attribute_name Raw attribute name e.g. 'pa_color' or 'color'.
	 * @return string color|image|label|button|select|text
	 */
	public static function get_attribute_type( string $attribute_name ): string {
		$self = self::instance();

		$name = sanitize_key( str_replace( 'pa_', '', $attribute_name ) );
		if ( '' === $name ) {
			return self::FALLBACK_TYPE;
		}

		if ( isset( $self->type_cache[ $name ] ) ) {
			return $self->type_cache[ $name ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$db_type = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_type FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
				$name
			)
		);

		$type = ! empty( $db_type ) ? $db_type : self::FALLBACK_TYPE;

		/** Developer filter — allows runtime override. */
		$type = (string) apply_filters( 'zymarg_sp_attribute_type', $type, $attribute_name );

		$self->type_cache[ $name ] = $type;

		return $type;
	}

	/**
	 * True if the attribute should render as a swatch.
	 *
	 * @param string $attribute_name Raw attribute name.
	 * @return bool
	 */
	public static function is_swatch_type( string $attribute_name ): bool {
		return in_array( self::get_attribute_type( $attribute_name ), self::SUPPORTED_TYPES, true );
	}

	public function clear_cache(): void {
		$this->type_cache = array();
	}

	public function clear_cache_for( string $attribute_name ): void {
		$name = sanitize_key( str_replace( 'pa_', '', $attribute_name ) );
		unset( $this->type_cache[ $name ] );
	}
}
