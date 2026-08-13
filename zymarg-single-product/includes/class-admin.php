<?php
/**
 * Admin settings page — 7 JS-powered tabs, no page reload on tab switch.
 * v2.0.0: the Reviews tab only keeps accordion presentation; the engine owns the rest.
 * AJAX save so the page never needs to reload at all.
 *
 * @version 1.1.2
 * @package ZymargSingleProduct
 */

namespace ZymargSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_menu_branding' ] );
		add_action( 'wp_ajax_zymarg_sp_save',  [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_zymarg_sp_restore_sections', [ $this, 'ajax_restore_sections' ] );
	}

	/**
	 * Sidebar parent-menu branding (Design Tokens v3 section 2.16).
	 *
	 * Scoped to #toplevel_page_zymarg-single-product and enqueued on
	 * every admin page.
	 *
	 * FIXED in v2.4.6: this plugin's constants used to be ZYMARG_SP_*,
	 * which collided with the separate ZYMARG Store Page plugin's own
	 * (unrelated) ZYMARG_SP_* constants when both were active. Renamed
	 * to ZYMARG_SNGL_* with if ( ! defined() ) guards -- see the header
	 * comment in zymarg-single-product.php for the full history and the
	 * naming rule for any new global identifiers added to this plugin.
	 *
	 * @return void
	 */
	public function enqueue_menu_branding(): void {
		if ( ! wp_style_is( 'zymarg-tokens', 'registered' ) ) {
			wp_register_style(
				'zymarg-tokens',
				ZYMARG_SNGL_URL . 'assets/css/zymarg-tokens.css',
				[],
				ZYMARG_SNGL_VERSION
			);
		}
		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style(
			'zymarg-sp-single-menu',
			ZYMARG_SNGL_URL . 'assets/css/zymarg-sp-single-menu.css',
			[ 'zymarg-tokens' ],
			ZYMARG_SNGL_VERSION
		);
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_menu_page(
			__( 'ZYMARG Single Product', 'zymarg-single-product' ),
			__( 'Single Product', 'zymarg-single-product' ),
			'manage_options',
			'zymarg-single-product',
			[ $this, 'render_page' ],
			'dashicons-store',
			58
		);
	}

	// ── AJAX save ─────────────────────────────────────────────────────────────

	public function ajax_save(): void {
		check_ajax_referer( 'zymarg_sngl_admin_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'zymarg-single-product' ) ] );
		}

		$raw      = $_POST['settings'] ?? [];
		$defaults = Options::defaults();
		$clean    = [];

		$textarea_keys = [ 'shipping_text', 'returns_text' ];
		$email_keys    = [ 'reviews_reports_notify_address' ];

		// v2.3.0 - radio values arrive as free-form strings and sanitize_text_field()
		// validates nothing, so any enumerated key is checked against its own
		// allow-list below and falls back to the default when it does not match.
		$enum_keys = [
			'gallery_thumbs_mobile_scope' => [ 'all', 'variable', 'simple' ],
		];

		// v2.0.0 — review settings are owned by the ZYMARG Reviews Engine plugin.
		// They are no longer rendered on this screen, so they are never present in
		// the POST body. Carry the stored values through untouched instead of
		// letting the "missing checkbox means false" rule wipe them, so the engine
		// can still migrate them whenever it is activated.
		$sp_owned_review_keys = [ 'reviews_open_default', 'reviews_label' ];

		foreach ( $defaults as $key => $default ) {
			// v2.1.0 - repeater arrays (product_sections) travel as a JSON string
			// because jQuery drops empty arrays from a POST body and
			// sanitize_text_field() returns '' when handed an array. Decode and
			// sanitise row by row instead of letting the scalar rules below
			// silently wipe the whole list on every save.
			if ( is_array( $default ) ) {
				if ( ! array_key_exists( $key, $raw ) ) {
					// Not submitted - keep what is stored rather than wiping it.
					$clean[ $key ] = Options::get( $key, $default );
					continue;
				}
				$clean[ $key ] = ( 'product_sections' === $key )
					? self::sanitize_sections( $raw[ $key ] )
					: $default;
				continue;
			}

			if ( str_starts_with( $key, 'reviews_' ) && ! in_array( $key, $sp_owned_review_keys, true ) ) {
				$clean[ $key ] = Options::get( $key, $default );
				continue;
			}
			if ( ! array_key_exists( $key, $raw ) ) {
				// Unchecked checkboxes won't be in POST — treat as false.
				$clean[ $key ] = is_bool( $default ) ? false : $default;
				continue;
			}

			$val = $raw[ $key ];

			if ( is_bool( $default ) ) {
				$clean[ $key ] = ( 'true' === $val || '1' === $val || true === $val );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = absint( $val );
			} elseif ( is_float( $default ) ) {
				$clean[ $key ] = (float) $val;
			} elseif ( in_array( $key, $textarea_keys, true ) ) {
				$clean[ $key ] = sanitize_textarea_field( wp_unslash( $val ) );
			} elseif ( in_array( $key, $email_keys, true ) ) {
				$clean[ $key ] = sanitize_email( wp_unslash( $val ) );
			} else {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $val ) );
			}
		}

		// v2.2.0 - keep one step of history whenever the section list changes,
		// so an unintended edit can be rolled back from the same screen.
		if ( isset( $clean['product_sections'] ) ) {
			$previous = Options::get( 'product_sections', [] );
			if ( is_array( $previous ) && $previous !== $clean['product_sections'] ) {
				$clean['product_sections_backup'] = $previous;
			}
		}

		foreach ( $enum_keys as $enum_key => $allowed_values ) {
			if ( isset( $clean[ $enum_key ] ) && ! in_array( $clean[ $enum_key ], $allowed_values, true ) ) {
				$clean[ $enum_key ] = $defaults[ $enum_key ];
			}
		}

		Options::set( $clean );
		Options::flush();

		wp_send_json_success( [ 'message' => __( 'Settings saved!', 'zymarg-single-product' ) ] );
	}

	/**
	 * Swap the stored section list with the rollback snapshot.
	 *
	 * The current list becomes the new snapshot, so the button also undoes
	 * itself if it was pressed by mistake.
	 *
	 * @return void
	 */
	public function ajax_restore_sections(): void {
		check_ajax_referer( 'zymarg_sp_restore_sections', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'zymarg-single-product' ) ] );
		}

		$backup = Options::get( 'product_sections_backup', [] );

		if ( ! is_array( $backup ) || empty( $backup ) ) {
			wp_send_json_error( [ 'message' => __( 'There is no previous version to restore.', 'zymarg-single-product' ) ] );
		}

		$current = Options::get( 'product_sections', [] );

		Options::set(
			[
				'product_sections'        => $backup,
				'product_sections_backup' => is_array( $current ) ? $current : [],
			]
		);
		Options::flush();

		wp_send_json_success( [ 'message' => __( 'Previous sections restored.', 'zymarg-single-product' ) ] );
	}

	// ── Page render ───────────────────────────────────────────────────────────

	// -- Section repeater sanitising (v2.1.0) ---------------------------------

	/**
	 * Shortcodes a section row is allowed to run.
	 *
	 * Restricted to the ZYMARG Product Grid engine's own tags so a typo cannot
	 * quietly execute an unrelated shortcode. Filterable for future engines.
	 *
	 * @return array
	 */
	private static function allowed_shortcodes(): array {
		return (array) apply_filters(
			'zymarg_sp_allowed_section_shortcodes',
			[ 'zymarg_products', 'zymarg_wcpg_wishlist', 'zymarg_wcpg_recently_viewed' ]
		);
	}

	/**
	 * Whether the opening tag of a shortcode string is allowlisted.
	 *
	 * Only the first tag is inspected, which is all a section row should hold.
	 *
	 * @param string $shortcode Raw shortcode string.
	 * @param array  $allowed   Allowlisted tags.
	 * @return bool
	 */
	private static function shortcode_is_allowed( string $shortcode, array $allowed ): bool {
		if ( ! preg_match( '/\\[\\s*([a-zA-Z0-9_-]+)/', $shortcode, $m ) ) {
			return false;
		}
		return in_array( $m[1], $allowed, true );
	}

	/**
	 * Sanitise the posted section list.
	 *
	 * @param mixed $raw JSON string (normal path) or array (defensive).
	 * @return array Clean, re-indexed rows. Array order is render order.
	 */
	private static function sanitize_sections( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = null;
		}

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$allowed = self::allowed_shortcodes();
		$rows    = [];
		$index   = 0;

		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$index;

			$shortcode = isset( $row['shortcode'] )
				? sanitize_text_field( wp_unslash( (string) $row['shortcode'] ) )
				: '';

			if ( '' !== $shortcode && ! self::shortcode_is_allowed( $shortcode, $allowed ) ) {
				$shortcode = '';
			}

			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = 'sec_' . $index . '_' . substr( md5( (string) microtime( true ) . $index ), 0, 6 );
			}

			$enabled   = $row['enabled'] ?? false;
			$show_link = $row['show_link'] ?? false;

			// A blank URL is stored as a blank string, never guessed at. Only
			// vendor sections resolve a link on their own.
			$link_url = isset( $row['link_url'] ) ? trim( (string) wp_unslash( $row['link_url'] ) ) : '';
			$link_url = ( '' === $link_url ) ? '' : esc_url_raw( $link_url );

			$rows[] = [
				'id'        => $id,
				'label'     => sanitize_text_field( wp_unslash( (string) ( $row['label'] ?? '' ) ) ),
				'enabled'   => ( true === $enabled || '1' === $enabled || 1 === $enabled || 'true' === $enabled ),
				'heading'   => sanitize_text_field( wp_unslash( (string) ( $row['heading'] ?? '' ) ) ),
				'show_link' => ( true === $show_link || '1' === $show_link || 1 === $show_link || 'true' === $show_link ),
				'link_url'  => $link_url,
				'shortcode' => $shortcode,
			];
		}

		return $rows;
	}

	// -- Tab: Grid Sections (v2.1.0) ------------------------------------------

	/**
	 * Ordered, user-managed product grid sections.
	 *
	 * Rows are drag-reorderable; array order is render order on the front end.
	 * Inputs deliberately use data-row-field rather than data-key so the flat
	 * collectSettings() sweep in the admin JS never picks them up.
	 *
	 * @param array $o Current options.
	 * @return void
	 */
	private function render_tab_sections( array $o ): void {
		$sections = ( isset( $o['product_sections'] ) && is_array( $o['product_sections'] ) )
			? $o['product_sections']
			: Options::default_sections();
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-sections" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-sections">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Product Grid Sections', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-sp-sections__lede">
						<?php esc_html_e( 'These sections render below the product tabs, in the order listed. Drag a row by its handle to move it up or down. Each row runs one ZYMARG Product Grid shortcode, so you can change the source, layout or card without editing any code.', 'zymarg-single-product' ); ?>
					</p>
					<p class="zymarg-sp-sections__lede">
						<strong><?php esc_html_e( 'Rows open locked.', 'zymarg-single-product' ); ?></strong>
						<?php esc_html_e( 'Press Edit on a row before anything in it can be changed, so simply visiting this screen cannot alter what your product pages render. Removing a row asks for confirmation, and the list from before your last save can be restored below.', 'zymarg-single-product' ); ?>
					</p>
					<p class="zymarg-sp-sections__lede">
						<strong><?php esc_html_e( 'Empty sections hide themselves.', 'zymarg-single-product' ); ?></strong>
						<?php esc_html_e( 'If a source returns no products - a seller with nothing else listed, for example - the whole section disappears and the next one moves up. Leave the empty_message attribute off to keep that behaviour.', 'zymarg-single-product' ); ?>
					</p>

					<div id="zymarg-sp-sections" class="zymarg-sp-sections">
						<?php foreach ( $sections as $row ) : ?>
							<?php $this->render_section_row( is_array( $row ) ? $row : [] ); ?>
						<?php endforeach; ?>
					</div>

					<div class="zymarg-sp-sections__actions">
						<button type="button" class="zymarg-sp-sections__add" id="zymarg-sp-add-section">
							<span aria-hidden="true">+</span> <?php esc_html_e( 'Add Section', 'zymarg-single-product' ); ?>
						</button>

						<?php
						$zymarg_sp_backup = Options::get( 'product_sections_backup', [] );
						if ( is_array( $zymarg_sp_backup ) && ! empty( $zymarg_sp_backup ) ) :
							?>
							<button type="button" class="zymarg-sp-sections__restore" id="zymarg-sp-restore-sections">
								<?php
								printf(
									/* translators: %d: number of sections in the rollback snapshot. */
									esc_html__( 'Restore previous (%d sections)', 'zymarg-single-product' ),
									count( $zymarg_sp_backup )
								);
								?>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Shortcode Cheat Sheet', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-sp-sections__lede">
						<?php esc_html_e( 'Common sources for this page:', 'zymarg-single-product' ); ?>
					</p>
					<ul class="zymarg-sp-sections__list">
						<li><code>source="vendor"</code> - <?php esc_html_e( 'other products from the seller of the product being viewed (the current product is excluded).', 'zymarg-single-product' ); ?></li>
						<li><code>source="similar"</code> - <?php esc_html_e( 'products related to the one being viewed.', 'zymarg-single-product' ); ?></li>
						<li><code>source="recommended"</code> - <?php esc_html_e( 'personalised picks based on browsing affinity.', 'zymarg-single-product' ); ?></li>
						<li><code>source="flash_deals"</code> - <?php esc_html_e( 'time-limited deals with a live countdown.', 'zymarg-single-product' ); ?></li>
					</ul>
					<p class="zymarg-sp-sections__lede">
						<?php esc_html_e( 'Useful attributes: layout="slider|grid", card_template="zymarg|flash|classic", limit, columns, show_heading, heading_text, show_view_all, view_all_auto_vendor.', 'zymarg-single-product' ); ?>
					</p>
					<p class="zymarg-sp-sections__lede">
						<?php esc_html_e( 'Tip: with source="vendor", set view_all_auto_vendor="yes" and leave view_all_url empty - the engine resolves the seller store link for you.', 'zymarg-single-product' ); ?>
					</p>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * A single section repeater row.
	 *
	 * @param array $row Row data: id, label, enabled, shortcode.
	 * @return void
	 */
	private function render_section_row( array $row ): void {
		$id        = (string) ( $row['id'] ?? '' );
		$label     = (string) ( $row['label'] ?? '' );
		$heading   = (string) ( $row['heading'] ?? '' );
		$link_url  = (string) ( $row['link_url'] ?? '' );
		$shortcode = (string) ( $row['shortcode'] ?? '' );
		$enabled   = ! empty( $row['enabled'] );
		$show_link = ! empty( $row['show_link'] );
		$is_vendor = Sections::is_vendor_source( $shortcode );
		$source    = Sections::source_of( $shortcode );
		$layout    = Sections::layout_of( $shortcode );
		?>
		<div class="zymarg-sp-section-row is-locked<?php echo $enabled ? '' : ' is-disabled'; ?>" data-row-id="<?php echo esc_attr( $id ); ?>">

			<div class="zymarg-sp-section-row__head">
				<span class="zymarg-sp-section-row__handle" title="<?php esc_attr_e( 'Drag to reorder', 'zymarg-single-product' ); ?>" aria-hidden="true">&#8942;&#8942;</span>

				<input type="text"
					class="zymarg-sp-input zymarg-sp-section-row__label"
					data-row-field="label"
					value="<?php echo esc_attr( $label ); ?>"
					readonly
					placeholder="<?php esc_attr_e( 'Section name (admin only)', 'zymarg-single-product' ); ?>">

				<span class="zymarg-sp-section-row__meta" data-row-meta>
					<?php echo esc_html( ( '' !== $source ? $source : '?' ) . ' · ' . $layout ); ?>
				</span>

				<label class="zymarg-sp-toggle zymarg-sp-section-row__toggle">
					<input type="checkbox" data-row-field="enabled" disabled <?php checked( $enabled ); ?>>
					<span class="zymarg-sp-toggle__track"></span>
				</label>

				<button type="button" class="zymarg-sp-section-row__edit"><?php esc_html_e( 'Edit', 'zymarg-single-product' ); ?></button>
				<button type="button" class="zymarg-sp-section-row__remove" aria-label="<?php esc_attr_e( 'Remove section', 'zymarg-single-product' ); ?>">&times;</button>
			</div>

			<div class="zymarg-sp-section-row__body">

				<div class="zymarg-sp-section-row__field">
					<label class="zymarg-sp-section-row__flabel"><?php esc_html_e( 'Section heading (shown on the page)', 'zymarg-single-product' ); ?></label>
					<input type="text"
						class="zymarg-sp-input"
						data-row-field="heading"
						value="<?php echo esc_attr( $heading ); ?>"
						readonly
						placeholder="<?php esc_attr_e( 'More from {vendor_name}', 'zymarg-single-product' ); ?>">
					<p class="zymarg-sp-section-row__hint">
						<?php
						printf(
							/* translators: %s: the {vendor_name} token. */
							esc_html__( 'Use %s to print the seller shop name. It resolves on vendor sections only, and is removed elsewhere. Leave the field empty for no heading.', 'zymarg-single-product' ),
							'<code>{vendor_name}</code>'
						);
						?>
					</p>
				</div>

				<div class="zymarg-sp-section-row__field zymarg-sp-section-row__field--inline">
					<label class="zymarg-sp-toggle">
						<input type="checkbox" data-row-field="show_link" disabled <?php checked( $show_link ); ?>>
						<span class="zymarg-sp-toggle__track"></span>
					</label>
					<span class="zymarg-sp-section-row__flabel"><?php esc_html_e( 'Show the section link', 'zymarg-single-product' ); ?></span>
				</div>

				<div class="zymarg-sp-section-row__field">
					<label class="zymarg-sp-section-row__flabel"><?php esc_html_e( 'Link URL', 'zymarg-single-product' ); ?></label>
					<input type="url"
						class="zymarg-sp-input"
						data-row-field="link_url"
						value="<?php echo esc_attr( $link_url ); ?>"
						readonly
						placeholder="https://">
					<p class="zymarg-sp-section-row__hint">
						<?php if ( $is_vendor ) : ?>
							<strong><?php esc_html_e( 'Vendor section:', 'zymarg-single-product' ); ?></strong>
							<?php esc_html_e( 'the seller store link is resolved automatically and this field is ignored. The link reads Explore Store.', 'zymarg-single-product' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Leave empty and no link renders at all. There is no automatic fallback outside vendor sections. The link reads Explore More.', 'zymarg-single-product' ); ?>
						<?php endif; ?>
					</p>
				</div>

				<div class="zymarg-sp-section-row__field">
					<label class="zymarg-sp-section-row__flabel"><?php esc_html_e( 'Shortcode', 'zymarg-single-product' ); ?></label>
					<textarea class="zymarg-sp-input zymarg-sp-textarea zymarg-sp-section-row__shortcode"
						data-row-field="shortcode"
						rows="3"
						readonly
						spellcheck="false"
						placeholder="[zymarg_products source=&quot;vendor&quot; limit=&quot;10&quot;]"><?php echo esc_textarea( $shortcode ); ?></textarea>
					<p class="zymarg-sp-section-row__warn" hidden></p>
				</div>

			</div>
		</div>
		<?php
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts = Options::all();
		?>
		<div class="wrap zymarg-single-product-admin">

			<!-- Header -->
			<div class="zymarg-single-product-admin__header">
				<div class="zymarg-single-product-admin__header-inner">
					<div class="zymarg-single-product-admin__logo">
						<span class="zymarg-single-product-admin__logo-icon">🛍</span>
						<div>
							<h1 class="zymarg-single-product-admin__title">ZYMARG Single Product</h1>
							<p class="zymarg-single-product-admin__version">v<?php echo esc_html( ZYMARG_SNGL_VERSION ); ?></p>
						</div>
					</div>
					<button type="button" id="zymarg-sp-save-btn" class="zymarg-single-product-admin__save-btn">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
							<path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/>
						</svg>
						<?php esc_html_e( 'Save Settings', 'zymarg-single-product' ); ?>
					</button>
				</div>
			</div>

			<!-- Status bar -->
			<div id="zymarg-sp-status" class="zymarg-single-product-admin__status" role="status" aria-live="polite"></div>

			<!-- Tab Navigation -->
			<div class="zymarg-single-product-admin__tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Settings tabs', 'zymarg-single-product' ); ?>">
				<?php
				$tabs = [
					'gallery'     => [ 'icon' => '🖼', 'label' => __( 'Gallery', 'zymarg-single-product' ) ],
					'swatches'    => [ 'icon' => '🎨', 'label' => __( 'Swatches', 'zymarg-single-product' ) ],
					'price'       => [ 'icon' => '💰', 'label' => __( 'Price', 'zymarg-single-product' ) ],
					'addtocart'   => [ 'icon' => '🛒', 'label' => __( 'Add to Cart', 'zymarg-single-product' ) ],
					'trust'       => [ 'icon' => '🛡', 'label' => __( 'Trust & Shipping', 'zymarg-single-product' ) ],
					'reviews'     => [ 'icon' => '⭐', 'label' => __( 'Reviews', 'zymarg-single-product' ) ],
					'sections'    => [ 'icon' => '🧩', 'label' => __( 'Grid Sections', 'zymarg-single-product' ) ],
					'general'     => [ 'icon' => '⚙', 'label' => __( 'General', 'zymarg-single-product' ) ],
				];
				foreach ( $tabs as $id => $tab ) :
					?>
					<button type="button"
						class="zymarg-single-product-admin__tab-btn"
						role="tab"
						data-tab="<?php echo esc_attr( $id ); ?>"
						aria-controls="zymarg-sp-tab-<?php echo esc_attr( $id ); ?>"
						aria-selected="false"
						id="zymarg-sp-tabnav-<?php echo esc_attr( $id ); ?>">
						<span class="zymarg-single-product-admin__tab-icon"><?php echo esc_html( $tab['icon'] ); ?></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<!-- Tab Panels -->
			<div class="zymarg-single-product-admin__panels">

				<?php $this->render_tab_gallery( $opts ); ?>
				<?php $this->render_tab_swatches( $opts ); ?>
				<?php $this->render_tab_price( $opts ); ?>
				<?php $this->render_tab_addtocart( $opts ); ?>
				<?php $this->render_tab_trust( $opts ); ?>
				<?php $this->render_tab_reviews( $opts ); ?>
				<?php $this->render_tab_sections( $opts ); ?>
				<?php $this->render_tab_general( $opts ); ?>

			</div><!-- /.panels -->

		</div><!-- /.wrap -->
		<?php
	}

	// ── Tab: Gallery ──────────────────────────────────────────────────────────

	private function render_tab_gallery( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-gallery" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-gallery">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Layout', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_radio( 'gallery_desktop_layout', __( 'Desktop layout', 'zymarg-single-product' ), $o, [
						'vertical-left'  => __( 'Vertical Left (thumbs left)', 'zymarg-single-product' ),
						'vertical-right' => __( 'Vertical Right (thumbs right)', 'zymarg-single-product' ),
						'stacked'        => __( 'Stacked (thumbs below)', 'zymarg-single-product' ),
						'grid'           => __( 'Grid', 'zymarg-single-product' ),
					] );
					$this->field_radio( 'gallery_tablet_layout', __( 'Tablet layout', 'zymarg-single-product' ), $o, [
						'vertical-left'  => __( 'Vertical Left (thumbs left)', 'zymarg-single-product' ),
						'vertical-right' => __( 'Vertical Right (thumbs right)', 'zymarg-single-product' ),
						'stacked'        => __( 'Stacked (thumbs below)', 'zymarg-single-product' ),
						'grid'           => __( 'Grid', 'zymarg-single-product' ),
					] );
					$this->field_radio( 'gallery_mobile_layout', __( 'Mobile layout', 'zymarg-single-product' ), $o, [
						'carousel' => __( 'Carousel', 'zymarg-single-product' ),
						'stacked'  => __( 'Stacked', 'zymarg-single-product' ),
						'grid'     => __( 'Grid', 'zymarg-single-product' ),
					] );
					$this->field_toggle( 'gallery_show_thumbs_desktop', __( 'Show thumbnails on desktop', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'gallery_show_thumbs_tablet',  __( 'Show thumbnails on tablet', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'gallery_show_thumbs_mobile',  __( 'Show thumbnails on mobile', 'zymarg-single-product' ), $o );
					$this->field_radio( 'gallery_thumbs_mobile_scope', __( 'When hidden on mobile, apply to', 'zymarg-single-product' ), $o, [
						'all'      => __( 'All products', 'zymarg-single-product' ),
						'variable' => __( 'Variable products only (simple products keep thumbnails)', 'zymarg-single-product' ),
						'simple'   => __( 'Simple products only (variable products keep thumbnails)', 'zymarg-single-product' ),
					] );
					$this->field_toggle( 'gallery_show_counter',    __( 'Show image counter overlay', 'zymarg-single-product' ), $o );
					$this->field_text(   'gallery_counter_format',  __( 'Counter format', 'zymarg-single-product' ), $o, __( 'Tokens: {current} {total}', 'zymarg-single-product' ) );
					$this->field_radio( 'gallery_thumb_size', __( 'Thumbnail size', 'zymarg-single-product' ), $o, [
						'small'  => __( 'Small (56px)', 'zymarg-single-product' ),
						'medium' => __( 'Medium (72px)', 'zymarg-single-product' ),
						'large'  => __( 'Large (88px)', 'zymarg-single-product' ),
					] );
					$this->field_number( 'gallery_max_thumbs', __( 'Max visible thumbnails before scroll', 'zymarg-single-product' ), $o, 1, 20 );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Interaction', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'gallery_hover_zoom',   __( 'Enable hover zoom on desktop', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'gallery_lightbox',     __( 'Enable lightbox on click', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'gallery_lazy_thumbs',  __( 'Lazy load thumbnails', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Sale Badge', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'gallery_show_sale_badge', __( 'Show sale badge', 'zymarg-single-product' ), $o );
					$this->field_text(   'gallery_sale_badge_text', __( 'Sale badge text', 'zymarg-single-product' ), $o, __( 'Token: {percent}', 'zymarg-single-product' ) );
					$this->field_radio( 'gallery_badge_position', __( 'Badge position', 'zymarg-single-product' ), $o, [
						'top-left'  => __( 'Top Left', 'zymarg-single-product' ),
						'top-right' => __( 'Top Right', 'zymarg-single-product' ),
					] );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Wishlist', 'zymarg-single-product' ); ?></h2>
					<?php $this->field_toggle( 'gallery_show_wishlist', __( 'Show wishlist button', 'zymarg-single-product' ), $o ); ?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Product Video', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-single-product-admin__hint"><?php esc_html_e( 'Adds a “Watch video” button to the gallery that opens an overlay player. Set each product’s video URL in the product editor (Product data → General → Product Video URL). Supports YouTube, Vimeo, and direct MP4/WebM/OGG.', 'zymarg-single-product' ); ?></p>
					<?php $this->field_toggle( 'product_video_enabled', __( 'Enable product video in gallery', 'zymarg-single-product' ), $o ); ?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Tab: Swatches ─────────────────────────────────────────────────────────

	private function render_tab_swatches( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-swatches" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-swatches">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Native Attribute Swatches', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-single-product-admin__hint">
						<?php esc_html_e( 'Swatch type (Color, Image, Label, Button) is set per attribute under Products → Attributes. Edit an attribute, choose its Type, then open “Configure terms” to set each term’s color or image. The styles below control how those swatches appear on the product page.', 'zymarg-single-product' ); ?>
					</p>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Shape & Size', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_radio( 'swatch_shape', __( 'Swatch shape', 'zymarg-single-product' ), $o, [
						'rounded' => __( 'Rounded', 'zymarg-single-product' ),
						'circle'  => __( 'Circle', 'zymarg-single-product' ),
						'square'  => __( 'Square', 'zymarg-single-product' ),
					] );
					$this->field_radio( 'swatch_color_size', __( 'Color swatch size', 'zymarg-single-product' ), $o, [
						'44px' => '44px',
						'56px' => '56px',
						'64px' => '64px',
					] );
					$this->field_radio( 'swatch_label_padding', __( 'Label swatch padding', 'zymarg-single-product' ), $o, [
						'8px 14px'  => __( 'Compact', 'zymarg-single-product' ),
						'10px 18px' => __( 'Normal', 'zymarg-single-product' ),
						'12px 22px' => __( 'Spacious', 'zymarg-single-product' ),
					] );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Out-of-Stock Behavior', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_radio( 'swatch_oos_behavior', __( 'Out of stock display', 'zymarg-single-product' ), $o, [
						'blur'      => __( 'Blur', 'zymarg-single-product' ),
						'crossout'  => __( 'Cross out', 'zymarg-single-product' ),
						'hide'      => __( 'Hide', 'zymarg-single-product' ),
					] );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Tooltip', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'swatch_tooltip', __( 'Show tooltip on hover', 'zymarg-single-product' ), $o );
					$this->field_radio( 'swatch_tooltip_position', __( 'Tooltip position', 'zymarg-single-product' ), $o, [
						'top'    => __( 'Top', 'zymarg-single-product' ),
						'bottom' => __( 'Bottom', 'zymarg-single-product' ),
					] );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Selection Behavior', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'swatch_auto_select',     __( 'Auto-select default variation', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'swatch_show_clear',       __( 'Show "Clear selection" link', 'zymarg-single-product' ), $o );
					$this->field_text(   'swatch_clear_label',      __( 'Clear link label', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'swatch_show_attr_label',  __( 'Show attribute label above swatches', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'swatch_show_selected_val', __( 'Show selected value next to label', 'zymarg-single-product' ), $o );
					?>
				</div>


			</div>
		</div>
		<?php
	}

	// ── Tab: Price ───────────────────────────────────────────────────────────

	private function render_tab_price( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-price" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-price">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Display', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_radio( 'price_variable_display', __( 'Variable product price display', 'zymarg-single-product' ), $o, [
						'lowest' => __( 'Lowest price only', 'zymarg-single-product' ),
						'from'   => __( 'Lowest with "From" prefix', 'zymarg-single-product' ),
						'range'  => __( 'Price range (low – high)', 'zymarg-single-product' ),
					] );
					$this->field_text( 'price_from_prefix', __( '"From" prefix text', 'zymarg-single-product' ), $o );
					$this->field_radio( 'price_regular_position', __( 'Regular price position (on sale)', 'zymarg-single-product' ), $o, [
						'inline' => __( 'Inline subscript', 'zymarg-single-product' ),
						'beside' => __( 'Beside', 'zymarg-single-product' ),
						'below'  => __( 'Below', 'zymarg-single-product' ),
						'hide'   => __( 'Hide', 'zymarg-single-product' ),
					] );
					$this->field_radio( 'price_old_style', __( 'Old price style', 'zymarg-single-product' ), $o, [
						'strikethrough' => __( 'Strikethrough', 'zymarg-single-product' ),
						'underline'     => __( 'Underline', 'zymarg-single-product' ),
					] );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Smart Heading', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-single-product-admin__hint"><?php esc_html_e( 'A small label shown above the price that adapts to product state.', 'zymarg-single-product' ); ?></p>
					<?php
					$this->field_toggle_text( 'price_heading_on_sale',     'price_heading_sale_text',    __( 'When on sale', 'zymarg-single-product' ), $o );
					$this->field_toggle_text( 'price_heading_ending_soon', 'price_heading_ending_text',  __( 'Sale ends within 24h', 'zymarg-single-product' ), $o, '{hours}' );
					$this->field_toggle_text( 'price_heading_regular',     'price_heading_regular_text', __( 'Regular price', 'zymarg-single-product' ), $o );
					$this->field_toggle_text( 'price_heading_oos',         'price_heading_oos_text',     __( 'Out of stock', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'You-Save Indicator', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'price_show_savings', __( 'Show savings badge on sale', 'zymarg-single-product' ), $o );
					$this->field_radio( 'price_savings_format', __( 'Format', 'zymarg-single-product' ), $o, [
						'both'    => __( 'Save {amount} ({percent}%)', 'zymarg-single-product' ),
						'amount'  => __( 'Save {amount}', 'zymarg-single-product' ),
						'percent' => __( 'Save {percent}%', 'zymarg-single-product' ),
					] );
					$this->field_text( 'price_savings_prefix', __( 'Prefix word', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Free Shipping Hint', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'price_show_free_hint',  __( 'Show free shipping hint below price', 'zymarg-single-product' ), $o );
					$this->field_number( 'price_free_threshold',  __( 'Threshold amount', 'zymarg-single-product' ), $o, 0, 999999 );
					$this->field_text(   'price_free_hint_text',  __( 'Hint text', 'zymarg-single-product' ), $o, __( 'Token: {amount}', 'zymarg-single-product' ) );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Animation & Loading', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_radio( 'price_change_animation', __( 'Price change animation (on variation select)', 'zymarg-single-product' ), $o, [
						'fade'  => __( 'Fade in', 'zymarg-single-product' ),
						'slide' => __( 'Slide up + fade', 'zymarg-single-product' ),
						'none'  => __( 'No animation', 'zymarg-single-product' ),
					] );
					$this->field_toggle( 'price_loading_skeleton', __( 'Show shimmer skeleton during variation lookup', 'zymarg-single-product' ), $o );
					?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Tab: Add to Cart ─────────────────────────────────────────────────────

	private function render_tab_addtocart( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-addtocart" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-addtocart">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Quantity Stepper', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'qty_show_stepper',  __( 'Show quantity stepper', 'zymarg-single-product' ), $o );
					$this->field_number( 'qty_default',       __( 'Default quantity', 'zymarg-single-product' ), $o, 1, 999 );
					$this->field_number( 'qty_min',           __( 'Min quantity', 'zymarg-single-product' ), $o, 1, 999 );
					$this->field_number( 'qty_max',           __( 'Max quantity (0 = no limit)', 'zymarg-single-product' ), $o, 0, 9999 );
					$this->field_toggle( 'qty_sync_sticky',   __( 'Sync sticky bar stepper with main stepper', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Add to Cart Button', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_text( 'atc_btn_text',          __( 'Button text', 'zymarg-single-product' ), $o );
					$this->field_text( 'atc_btn_text_loading',  __( 'Text while adding', 'zymarg-single-product' ), $o );
					$this->field_text( 'atc_btn_text_done',     __( 'Text when added', 'zymarg-single-product' ), $o );
					?>
					<!-- v2.4.4 - the added-to-cart toast + "View Cart" link were
					     removed per user request (WooCommerce's own "View cart"
					     link is now suppressed too), so their settings are gone. -->
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Buy Now', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'buynow_show',        __( 'Show Buy Now button', 'zymarg-single-product' ), $o );
					$this->field_text(   'buynow_text',        __( 'Button text', 'zymarg-single-product' ), $o );
					$this->field_radio( 'buynow_position', __( 'Position', 'zymarg-single-product' ), $o, [
						'below' => __( 'Below Add to Cart', 'zymarg-single-product' ),
						'above' => __( 'Above Add to Cart', 'zymarg-single-product' ),
					] );
					$this->field_number( 'buynow_session_ttl', __( 'Session TTL (minutes)', 'zymarg-single-product' ), $o, 1, 120 );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Mobile Sticky Bar', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'sticky_bar_enabled', __( 'Enable sticky bar on mobile', 'zymarg-single-product' ), $o );
					$this->field_radio( 'sticky_bar_content', __( 'Sticky bar content', 'zymarg-single-product' ), $o, [
						'atc-only'       => __( 'Add to Cart only', 'zymarg-single-product' ),
						'atc-buynow'     => __( 'Add to Cart + Buy Now', 'zymarg-single-product' ),
						'qty-atc-buynow' => __( 'Qty + Add to Cart + Buy Now', 'zymarg-single-product' ),
					] );
					?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Tab: Trust & Shipping ─────────────────────────────────────────────────

	private function render_tab_trust( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-trust" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-trust">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Trust Badges', 'zymarg-single-product' ); ?></h2>
					<p class="zymarg-single-product-admin__hint"><?php esc_html_e( 'Toggle each badge and customise its text.', 'zymarg-single-product' ); ?></p>
					<?php for ( $i = 1; $i <= 5; $i++ ) :
						$this->field_toggle_text(
							"trust_badge_{$i}_enabled",
							"trust_badge_{$i}_text",
							sprintf( __( 'Badge %d', 'zymarg-single-product' ), $i ),
							$o
						);
					endfor; ?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Stock Status', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_stock_status',       __( 'Show stock status', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'show_low_stock_warning',  __( 'Show "Only X left!" low-stock warning', 'zymarg-single-product' ), $o );
					$this->field_number( 'low_stock_threshold',     __( 'Low-stock threshold', 'zymarg-single-product' ), $o, 1, 100 );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Delivery Info', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_delivery_info',   __( 'Show delivery info block', 'zymarg-single-product' ), $o );
					$this->field_text(   'delivery_icon',        __( 'Icon (emoji)', 'zymarg-single-product' ), $o );
					$this->field_text(   'delivery_window_text', __( 'Delivery window text', 'zymarg-single-product' ), $o );
					$this->field_text(   'ships_from_text',      __( 'Ships from text', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Shipping & Returns', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle(   'show_shipping_returns', __( 'Show Shipping & Returns block', 'zymarg-single-product' ), $o );
					$this->field_textarea( 'shipping_text',         __( 'Shipping text', 'zymarg-single-product' ), $o );
					$this->field_textarea( 'returns_text',          __( 'Returns text', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Secure Payment Note', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_secure_note', __( 'Show secure payment note', 'zymarg-single-product' ), $o );
					$this->field_text(   'secure_note_text', __( 'Note text', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Sold By Row', 'zymarg-single-product' ); ?></h2>
					<?php $this->field_toggle( 'show_sold_by', __( 'Show "Sold by" row in buy box', 'zymarg-single-product' ), $o ); ?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Tab: General ──────────────────────────────────────────────────────────

	private function render_tab_general( array $o ): void {
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-general" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-general">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Template Override', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'template_override_enabled', __( 'Enable template override (emergency kill switch)', 'zymarg-single-product' ), $o );
					$this->field_number( 'override_priority', __( 'Override priority (lower = higher priority)', 'zymarg-single-product' ), $o, 1, 99 );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Breadcrumbs', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_breadcrumbs',      __( 'Show breadcrumbs', 'zymarg-single-product' ), $o );
					$this->field_text(   'breadcrumb_separator',  __( 'Breadcrumb separator', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Seller Card', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_seller_card',  __( 'Show seller card section', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'show_visit_store',  __( 'Show "Visit Store" button', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'show_chat_btn',     __( 'Show "Chat" button', 'zymarg-single-product' ), $o );
					$this->field_text(   'chat_url',          __( 'Chat URL (leave blank for Dokan auto)', 'zymarg-single-product' ), $o );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Product Accordions', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_description_tab',     __( 'Show Description accordion', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'show_reviews_tab',         __( 'Show Reviews accordion', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'description_open_default', __( 'Description open by default', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'reviews_open_default',     __( 'Reviews open by default', 'zymarg-single-product' ), $o );
					$this->field_text(   'description_label',        __( 'Description accordion label', 'zymarg-single-product' ), $o );
					$this->field_text(   'reviews_label',            __( 'Reviews accordion label', 'zymarg-single-product' ), $o, __( 'Token: {count}', 'zymarg-single-product' ) );
					?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Tab: Reviews ───────────────────────────────────────────────────

	/**
	 * v2.0.0 — every review setting moved to the ZYMARG Reviews Engine plugin.
	 * What stays here is only how the single product page presents the reviews
	 * accordion: whether to show it, whether it starts open, and its label.
	 */
	private function render_tab_reviews( array $o ): void {
		$engine_active = function_exists( 'zymarg_reviews_render' );
		$engine_url    = admin_url( 'admin.php?page=zymarg-reviews-engine' );
		?>
		<div class="zymarg-single-product-admin__panel" id="zymarg-sp-tab-reviews" role="tabpanel" aria-labelledby="zymarg-sp-tabnav-reviews">
			<div class="zymarg-single-product-admin__panel-inner">

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Reviews Accordion', 'zymarg-single-product' ); ?></h2>
					<?php
					$this->field_toggle( 'show_reviews_tab',     __( 'Show Reviews accordion', 'zymarg-single-product' ), $o );
					$this->field_toggle( 'reviews_open_default', __( 'Reviews open by default', 'zymarg-single-product' ), $o );
					$this->field_text(   'reviews_label',        __( 'Reviews accordion label', 'zymarg-single-product' ), $o, __( 'Token: {count}', 'zymarg-single-product' ) );
					?>
				</div>

				<div class="zymarg-single-product-admin__section">
					<h2 class="zymarg-single-product-admin__section-title"><?php esc_html_e( 'Reviews Engine', 'zymarg-single-product' ); ?></h2>
					<?php if ( $engine_active ) : ?>
						<p class="zymarg-single-product-admin__note">
							<?php esc_html_e( 'Layout, feed, form, media, moderation and email settings for reviews live in the ZYMARG Reviews Engine plugin. Changes there apply everywhere reviews are shown.', 'zymarg-single-product' ); ?>
						</p>
						<p><a class="button button-secondary" href="<?php echo esc_url( $engine_url ); ?>"><?php esc_html_e( 'Open Reviews Engine settings', 'zymarg-single-product' ); ?></a></p>
					<?php else : ?>
						<p class="zymarg-single-product-admin__note zymarg-single-product-admin__note--warn">
							<?php esc_html_e( 'ZYMARG Reviews Engine is not active. The Reviews accordion stays hidden until you install and activate it. Your reviews and their settings are safe in the meantime.', 'zymarg-single-product' ); ?>
						</p>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Field helpers ─────────────────────────────────────────────────────────

	private function field_toggle( string $key, string $label, array $o ): void {
		$checked = ! empty( $o[ $key ] );
		?>
		<div class="zymarg-sp-field zymarg-sp-field--toggle">
			<label class="zymarg-sp-toggle">
				<input type="checkbox"
					name="<?php echo esc_attr( $key ); ?>"
					data-key="<?php echo esc_attr( $key ); ?>"
					value="1"
					<?php checked( $checked ); ?>>
				<span class="zymarg-sp-toggle__track"></span>
				<span class="zymarg-sp-toggle__label"><?php echo esc_html( $label ); ?></span>
			</label>
		</div>
		<?php
	}

	private function field_toggle_text( string $toggle_key, string $text_key, string $label, array $o, string $hint = '' ): void {
		$checked = ! empty( $o[ $toggle_key ] );
		$val     = esc_attr( $o[ $text_key ] ?? '' );
		?>
		<div class="zymarg-sp-field zymarg-sp-field--toggle-text">
			<div class="zymarg-sp-field__toggle-row">
				<label class="zymarg-sp-toggle">
					<input type="checkbox"
						name="<?php echo esc_attr( $toggle_key ); ?>"
						data-key="<?php echo esc_attr( $toggle_key ); ?>"
						value="1"
						<?php checked( $checked ); ?>>
					<span class="zymarg-sp-toggle__track"></span>
					<span class="zymarg-sp-toggle__label"><?php echo esc_html( $label ); ?></span>
				</label>
			</div>
			<div class="zymarg-sp-field__text-row <?php echo $checked ? '' : 'is-hidden'; ?>" data-controlled-by="<?php echo esc_attr( $toggle_key ); ?>">
				<input type="text"
					name="<?php echo esc_attr( $text_key ); ?>"
					data-key="<?php echo esc_attr( $text_key ); ?>"
					value="<?php echo $val; // phpcs:ignore ?>"
					class="zymarg-sp-input">
				<?php if ( $hint ) : ?>
					<span class="zymarg-sp-field__hint"><?php echo esc_html( $hint ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_text( string $key, string $label, array $o, string $hint = '' ): void {
		$val = esc_attr( $o[ $key ] ?? '' );
		?>
		<div class="zymarg-sp-field">
			<label class="zymarg-sp-field__label" for="zsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text"
				id="zsp-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				data-key="<?php echo esc_attr( $key ); ?>"
				value="<?php echo $val; // phpcs:ignore ?>"
				class="zymarg-sp-input">
			<?php if ( $hint ) : ?>
				<span class="zymarg-sp-field__hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	private function field_textarea( string $key, string $label, array $o ): void {
		$val = esc_textarea( $o[ $key ] ?? '' );
		?>
		<div class="zymarg-sp-field">
			<label class="zymarg-sp-field__label" for="zsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea
				id="zsp-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				data-key="<?php echo esc_attr( $key ); ?>"
				class="zymarg-sp-input zymarg-sp-textarea"
				rows="3"><?php echo $val; // phpcs:ignore ?></textarea>
		</div>
		<?php
	}

	private function field_number( string $key, string $label, array $o, int $min = 0, int $max = 9999 ): void {
		$val = (int) ( $o[ $key ] ?? 0 );
		?>
		<div class="zymarg-sp-field zymarg-sp-field--number">
			<label class="zymarg-sp-field__label" for="zsp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number"
				id="zsp-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				data-key="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $val ); ?>"
				min="<?php echo esc_attr( $min ); ?>"
				max="<?php echo esc_attr( $max ); ?>"
				class="zymarg-sp-input zymarg-sp-input--number">
		</div>
		<?php
	}

	private function field_radio( string $key, string $label, array $o, array $options ): void {
		$current = $o[ $key ] ?? array_key_first( $options );
		?>
		<div class="zymarg-sp-field zymarg-sp-field--radio">
			<span class="zymarg-sp-field__label"><?php echo esc_html( $label ); ?></span>
			<div class="zymarg-sp-radio-group">
				<?php foreach ( $options as $val => $opt_label ) : ?>
					<label class="zymarg-sp-radio">
						<input type="radio"
							name="<?php echo esc_attr( $key ); ?>"
							data-key="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( $val ); ?>"
							<?php checked( $current, $val ); ?>>
						<span class="zymarg-sp-radio__label"><?php echo esc_html( $opt_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
