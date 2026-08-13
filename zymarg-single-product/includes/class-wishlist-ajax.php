<?php
/**
 * Wishlist AJAX bridge (v2.4.4).
 *
 * Soft dependency on the ZYMARG WC Product Grid plugin: the wishlist button
 * on the single product gallery persists through Product Grid's own
 * Wishlist_Store (user meta for logged-in visitors, cookie + WC session for
 * guests) so a product wishlisted from a Product Grid card and from this
 * plugin's gallery button share the same list.
 *
 * This file only ever CALLS Wishlist_Store's already-public static methods.
 * Nothing in the ZYMARG WC Product Grid plugin is modified.
 *
 * When Product Grid is not active, the wishlist button hides itself on the
 * front end and an admin notice explains why (same soft-dependency pattern
 * already used for the ZYMARG Reviews Engine).
 *
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wishlist_Ajax {

	const TOGGLE_ACTION  = 'zymarg_sp_wishlist_toggle';
	const HYDRATE_ACTION = 'zymarg_sp_wishlist_hydrate';
	const NONCE_ACTION   = 'zymarg_sp_wishlist_nonce';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Whether the Product Grid plugin's wishlist storage layer is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( '\Zymarg\WCPG\Wishlist\Wishlist_Store' );
	}

	public function init(): void {
		add_action( 'wp_ajax_'        . self::TOGGLE_ACTION, [ $this, 'handle_toggle' ] );
		add_action( 'wp_ajax_nopriv_' . self::TOGGLE_ACTION, [ $this, 'handle_toggle' ] );

		add_action( 'wp_ajax_'        . self::HYDRATE_ACTION, [ $this, 'handle_hydrate' ] );
		add_action( 'wp_ajax_nopriv_' . self::HYDRATE_ACTION, [ $this, 'handle_hydrate' ] );

		if ( ! self::is_available() ) {
			add_action( 'admin_notices', [ $this, 'notice_grid_missing' ] );
		}
	}

	/**
	 * AJAX: toggle / add / remove the current visitor's wishlist state.
	 */
	public function handle_toggle(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'Wishlist is unavailable.', 'zymarg-single-product' ) ], 503 );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$op         = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'toggle';

		if ( ! in_array( $op, [ 'add', 'remove', 'toggle' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid operation.', 'zymarg-single-product' ) ], 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'zymarg-single-product' ) ], 404 );
		}

		$store  = '\Zymarg\WCPG\Wishlist\Wishlist_Store';
		$was_in = $store::has( $product_id );
		$is_in  = $was_in;

		switch ( $op ) {
			case 'add':
				$store::add( $product_id );
				$is_in = true;
				break;
			case 'remove':
				$store::remove( $product_id );
				$is_in = false;
				break;
			case 'toggle':
				if ( $was_in ) {
					$store::remove( $product_id );
					$is_in = false;
				} else {
					$store::add( $product_id );
					$is_in = true;
				}
				break;
		}

		do_action( 'zymarg_sp_wishlist_toggled', $product_id, $is_in );

		wp_send_json_success(
			[
				'product_id'  => $product_id,
				'in_wishlist' => $is_in,
				'count'       => count( $store::get_ids() ),
				'message'     => $is_in
					? __( 'Added to wishlist', 'zymarg-single-product' )
					: __( 'Removed from wishlist', 'zymarg-single-product' ),
			]
		);
	}

	/**
	 * AJAX: cache-safe initial state for the current product, read on page
	 * load so the heart shows the right state even behind full-page caching.
	 */
	public function handle_hydrate(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'Wishlist is unavailable.', 'zymarg-single-product' ) ], 503 );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );

		wp_send_json_success(
			[
				'product_id'  => $product_id,
				'in_wishlist' => \Zymarg\WCPG\Wishlist\Wishlist_Store::has( $product_id ),
			]
		);
	}

	/**
	 * Localisation data for the front-end JS. Returns null when the wishlist
	 * button should not render/act (Product Grid missing, or the admin
	 * setting is off).
	 *
	 * @return array|null
	 */
	public static function js_data(): ?array {
		if ( ! Options::get( 'gallery_show_wishlist' ) || ! self::is_available() ) {
			return null;
		}

		return [
			'enabled'        => true,
			'toggle_action'  => self::TOGGLE_ACTION,
			'hydrate_action' => self::HYDRATE_ACTION,
			'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
		];
	}

	/**
	 * Admin notice: wishlist needs the ZYMARG WC Product Grid plugin.
	 * Mirrors the existing Reviews Engine soft-dependency notice.
	 */
	public function notice_grid_missing(): void {
		if ( ! Options::get( 'gallery_show_wishlist' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'zymarg_sp_wishlist_notice_off', true ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible" id="zymarg-sp-wishlist-notice">
			<p>
				<strong><?php esc_html_e( 'ZYMARG Single Product', 'zymarg-single-product' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'the gallery wishlist button needs the ZYMARG WC Product Grid plugin. Install and activate it to let visitors save this product to their wishlist.', 'zymarg-single-product' ); ?>
			</p>
		</div>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '#zymarg-sp-wishlist-notice .notice-dismiss' );
			if ( ! btn ) { return; }
			var body = new FormData();
			body.append( 'action', 'zymarg_sp_dismiss_wishlist_notice' );
			body.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'zymarg_sp_wishlist_notice' ) ); ?>' );
			fetch( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', body: body } );
		} );
		</script>
		<?php
	}
}

add_action(
	'wp_ajax_zymarg_sp_dismiss_wishlist_notice',
	function () {
		check_ajax_referer( 'zymarg_sp_wishlist_notice', 'nonce' );
		update_user_meta( get_current_user_id(), 'zymarg_sp_wishlist_notice_off', 1 );
		wp_send_json_success();
	}
);
