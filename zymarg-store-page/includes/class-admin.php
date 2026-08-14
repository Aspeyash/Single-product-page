<?php
/**
 * Admin Panel
 *
 * Registers the ZYMARG Store Page admin menu and settings screen.
 *
 * DESIGN TOKENS
 * -------------
 * This screen carries no inline styles and no hardcoded colours. All styling
 * lives in assets/css/zymarg-sp-admin.css and resolves through --zym-* tokens.
 *
 * This plugin does NOT define or redefine any --zym-* token. The Vendor
 * Dashboard plugin owns the token file. We register the shared "zymarg-tokens"
 * handle only when nothing else already has, so whichever plugin loads first
 * provides the tokens and there is never a duplicate or conflicting copy.
 *
 * SAVING
 * ------
 * The form saves over Ajax with no page reload. It still carries
 * action="options.php" and the Settings API nonce, so with JavaScript off the
 * browser posts normally and WordPress saves exactly as it did before.
 *
 * Options stored under 'zymarg_sp_options':
 *   products_per_page  int   4-48, step 4   (default 8)
 *   show_aura_search   bool                 (default true)
 *   show_reviews       bool                 (default true)
 *   no_results_slug    string               (default 'community')
 *
 * @package ZYMARG_Store_Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Admin {

	public static function init() {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ], 9 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
		add_action( 'wp_ajax_zymarg_sp_save_settings', [ __CLASS__, 'ajax_save_settings' ] );

		// Admin-managed product grid sections (Trending / Best Selling / All
		// Products). Deliberately separate AJAX actions from the settings save
		// above -- the section repeater has its own validation (shortcode
		// allow-list + current_vendor source restriction) and its own one-step
		// backup/restore, mirroring how ZYMARG Single Product keeps its section
		// repeater's save action apart from its general settings save.
		add_action( 'wp_ajax_zymarg_sp_save_store_sections',    [ __CLASS__, 'ajax_save_store_sections' ] );
		add_action( 'wp_ajax_zymarg_sp_restore_store_sections', [ __CLASS__, 'ajax_restore_store_sections' ] );

		add_filter( 'plugin_action_links_' . plugin_basename( ZYMARG_SP_FILE ),
		            [ __CLASS__, 'action_links' ] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// SVG icon (inline data URI) — flat storefront glyph.
	//
	// WordPress renders a data-URI menu icon as a CSS BACKGROUND IMAGE. Two
	// things follow from that, and both bit the previous "Z on gradient tile"
	// icon:
	//
	//   1. CSS animation inside a background-image SVG does not run in any
	//      browser, so anything ambitious (the Discovery Spark, a gradient
	//      transition) can never move. The sidebar is not the place for it.
	//   2. `fill="currentColor"` has no colour to inherit inside a background
	//      image, so it resolves to black. The shipped SVG therefore carries a
	//      real colour: #a7aaad, the neutral core uses for its own icons, so
	//      the fallback reads as native if CSS cannot enhance it.
	//
	// assets/css/zymarg-sp-menu.css then paints the item with the brand
	// gradient and drops a surface-white copy of this exact glyph on top via
	// mask-image, which is what makes the icon stay legible on the gradient.
	// ──────────────────────────────────────────────────────────────────────
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad">'
			// The awning: a trapezoid across the top, wider at the base.
			. '<path d="M3 2h14l1 4H2z"/>'
			// The shop body: rectangle 3,7-17,17 with a doorway cut out.
			. '<path d="M3 7h14v10h-5v-6H8v6H3z"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Top-level menu + sub-pages
	// ──────────────────────────────────────────────────────────────────────
	public static function add_menu() {
		add_menu_page(
			__( 'Store Page', 'zymarg-store-page' ),
			__( 'Store Page', 'zymarg-store-page' ),
			'manage_options',
			'zymarg-store-page',
			[ __CLASS__, 'render_settings_page' ],
			self::menu_icon(),
			58
		);

		// Remove the auto-generated duplicate first submenu, keep only Settings
		remove_submenu_page( 'zymarg-store-page', 'zymarg-store-page' );

		add_submenu_page(
			'zymarg-store-page',
			__( 'Store Page — Settings', 'zymarg-store-page' ),
			__( 'Settings', 'zymarg-store-page' ),
			'manage_options',
			'zymarg-store-page',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// Assets — tokens, stylesheet, Ajax script.
	//
	// Two audiences:
	//
	//   1. The MENU BRANDING (assets/css/zymarg-sp-menu.css). The sidebar
	//      exists on every admin page, not only ours, so this stylesheet has
	//      to load everywhere -- otherwise the top-level item would only look
	//      branded while you were already inside the plugin. It is the one
	//      admin asset that is not gated on our own screen hook.
	//
	//   2. The SETTINGS PAGE bundle (tokens + Spark + zymarg-sp-admin.css and
	//      its script). These are loaded only on our screens, as before.
	// ──────────────────────────────────────────────────────────────────────
	public static function enqueue_admin_assets( $hook ) {
		// Menu branding first, always.
		self::enqueue_menu_branding();

		if ( strpos( $hook, 'zymarg-store-page' ) === false ) {
			return;
		}

		// Shared token + Spark layer, under their canonical handles. Whichever
		// ZYMARG plugin loads first supplies the files; this call is a no-op if
		// that has already happened, so a second copy never loads.
		if ( function_exists( 'zymarg_sp_register_shared_brand_assets' ) ) {
			zymarg_sp_register_shared_brand_assets();
		}

		wp_enqueue_style( 'zymarg-tokens' );
		wp_enqueue_style( 'zymarg-spark' );

		wp_enqueue_style(
			'zymarg-sp-admin',
			ZYMARG_SP_URL . 'assets/css/zymarg-sp-admin.css',
			[ 'zymarg-tokens', 'zymarg-spark' ],
			ZYMARG_SP_VERSION
		);

		wp_enqueue_script(
			'zymarg-sp-admin',
			ZYMARG_SP_URL . 'assets/js/zymarg-sp-admin.js',
			[],
			ZYMARG_SP_VERSION,
			true
		);

		wp_localize_script( 'zymarg-sp-admin', 'ZymargSPAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'zymarg_sp_save_settings' ),
			'i18n'    => [
				'saving'   => __( 'Saving...', 'zymarg-store-page' ),
				'saved'    => __( 'Settings saved.', 'zymarg-store-page' ),
				'failed'   => __( 'Could not save. Please try again.', 'zymarg-store-page' ),
				'enabled'  => __( 'Enabled', 'zymarg-store-page' ),
				'disabled' => __( 'Disabled', 'zymarg-store-page' ),
				'on'       => __( 'ON', 'zymarg-store-page' ),
				'off'      => __( 'OFF', 'zymarg-store-page' ),
			],
		] );

		// ── Product grid sections repeater ──────────────────────────────
		// Separate script from zymarg-sp-admin above: the section repeater
		// has its own Ajax actions, its own validation, and needs
		// jquery-ui-sortable, which the settings form itself does not.
		wp_enqueue_script(
			'zymarg-sp-store-sections',
			ZYMARG_SP_URL . 'assets/js/zymarg-sp-store-sections.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			ZYMARG_SP_VERSION,
			true
		);

		wp_localize_script( 'zymarg-sp-store-sections', 'ZymargSPStoreSections', [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'saveNonce'      => wp_create_nonce( 'zymarg_sp_save_store_sections' ),
			'restoreNonce'   => wp_create_nonce( 'zymarg_sp_restore_store_sections' ),
			'allowedShortcodes' => class_exists( 'ZYMARG_SP_Store_Sections' )
				? array_values( ZYMARG_SP_Store_Sections::allowed_shortcodes() )
				: [ 'zymarg_products' ],
			'i18n'           => [
				'saved'           => __( 'Sections saved.', 'zymarg-store-page' ),
				'failed'          => __( 'Error saving. Please try again.', 'zymarg-store-page' ),
				'edit'            => __( 'Edit', 'zymarg-store-page' ),
				'done'            => __( 'Done', 'zymarg-store-page' ),
				'untitled'        => __( 'this section', 'zymarg-store-page' ),
				'confirmRemove'   => __( 'Remove "%s"? It will stop rendering on the store page once you save.', 'zymarg-store-page' ),
				'confirmRestore'  => __( 'Restore the section list from before your last save? The list you have now becomes the restore point, so you can swap back.', 'zymarg-store-page' ),
				'confirmSave'     => __( 'Save these section changes?', 'zymarg-store-page' ),
				'invalid'         => __( 'Fix these section problems before saving:', 'zymarg-store-page' ),
				'restoreFailed'   => __( 'Restore failed. Please reload and try again.', 'zymarg-store-page' ),
				'notAllowedTag'   => __( 'is not a ZYMARG Product Grid shortcode and would be discarded on save.', 'zymarg-store-page' ),
				'mustBeCurrentVendor' => __( 'must use source="current_vendor" — this is the only source a store page section can run, and would be discarded on save.', 'zymarg-store-page' ),
				'unbalancedBrackets'  => __( 'Unbalanced square brackets.', 'zymarg-store-page' ),
				'unbalancedQuotes'    => __( 'Unbalanced quotation marks.', 'zymarg-store-page' ),
				'mustStartEnd'        => __( 'Must start with [ and end with ].', 'zymarg-store-page' ),
			],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Sidebar menu branding.
	//
	// Scoped in CSS to #toplevel_page_zymarg-store-page, an ID that only
	// exists because this plugin registered that menu. It therefore cannot
	// reach any other menu item, any other plugin, or the front end. The
	// tokens are a hard dependency because the file consumes --zym-gradient
	// and --zym-color-surface, and no token value is ever redefined.
	//
	// This runs on every admin page: the sidebar is everywhere, so the
	// stylesheet has to be everywhere or the top-level item would only look
	// branded while you were already inside the plugin.
	// ──────────────────────────────────────────────────────────────────────
	private static function enqueue_menu_branding() {
		// Register the shared tokens stylesheet if a sibling ZYMARG plugin has
		// not already. Idempotent thanks to the wp_style_is() guards inside.
		if ( function_exists( 'zymarg_sp_register_shared_brand_assets' ) ) {
			zymarg_sp_register_shared_brand_assets();
		}

		wp_enqueue_style( 'zymarg-tokens' );

		wp_enqueue_style(
			'zymarg-sp-menu',
			ZYMARG_SP_URL . 'assets/css/zymarg-sp-menu.css',
			[ 'zymarg-tokens' ],
			ZYMARG_SP_VERSION
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// Settings registration — only the option group/name; no WP Settings
	// API fields needed because we render our own styled form below.
	// ──────────────────────────────────────────────────────────────────────
	public static function register_settings() {
		register_setting( 'zymarg_sp_options', 'zymarg_sp_options', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_options' ],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Sanitize & save
	// ──────────────────────────────────────────────────────────────────────
	public static function sanitize_options( $input ) {
		$output                      = [];
		$output['products_per_page'] = isset( $input['products_per_page'] )
		                               ? max( 4, min( 48, absint( $input['products_per_page'] ) ) )
		                               : 8;
		                               $output['page_width']        = isset( $input['page_width'] )
		                                                              ? max( 0, min( 100, absint( $input['page_width'] ) ) )
		                                                              : 0;
		$output['show_aura_search']  = ! empty( $input['show_aura_search'] ) ? 1 : 0;
		$output['show_reviews']      = ! empty( $input['show_reviews'] )     ? 1 : 0;
		$output['no_results_slug']   = isset( $input['no_results_slug'] )
		                               ? trim( sanitize_text_field( $input['no_results_slug'] ), '/' )
		                               : 'community';
		return $output;
	}

	// ──────────────────────────────────────────────────────────────────────
	// Ajax save — same capability check, same sanitizer as the classic path.
	// ──────────────────────────────────────────────────────────────────────
	public static function ajax_save_settings() {
		if ( ! check_ajax_referer( 'zymarg_sp_save_settings', 'nonce', false ) ) {
			wp_send_json_error( [
				'message' => __( 'Your session expired. Reload the page and try again.', 'zymarg-store-page' ),
			], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to change these settings.', 'zymarg-store-page' ),
			], 403 );
		}

		/*
		 * Two accepted payload shapes, on purpose.
		 *
		 * Since 1.20.0 the script posts the whole form, so fields arrive under
		 * their real names as a nested zymarg_sp_options[...] array. Before that
		 * it hand-picked four values and posted them as flat top-level keys.
		 *
		 * Both are still read here. The version bump busts the script's cache
		 * query string, but a proxy or service worker holding the old file would
		 * otherwise save nothing at all and report success while doing it --
		 * a silent data-loss failure that is very hard to diagnose from the
		 * admin screen. Accepting the legacy shape costs one branch.
		 */
		if ( isset( $_POST['zymarg_sp_options'] ) && is_array( $_POST['zymarg_sp_options'] ) ) {
			$raw = wp_unslash( $_POST['zymarg_sp_options'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by sanitize_options() below.
		} else {
			$raw = [
				'products_per_page' => isset( $_POST['products_per_page'] )
					? wp_unslash( $_POST['products_per_page'] ) : 8,
				'show_aura_search'  => ! empty( $_POST['show_aura_search'] ) && '0' !== $_POST['show_aura_search'],
				'show_reviews'      => ! empty( $_POST['show_reviews'] ) && '0' !== $_POST['show_reviews'],
				'no_results_slug'   => isset( $_POST['no_results_slug'] )
					? wp_unslash( $_POST['no_results_slug'] ) : 'community',
			];
		}

		$clean = self::sanitize_options( $raw );
		update_option( 'zymarg_sp_options', $clean );

		/*
		 * The Flash Sale hero keeps its settings in its own option, so it is
		 * saved separately here. It is only written when its key is present:
		 * an old cached script posts no hero fields, and rebuilding the option
		 * from an empty array would reset every hero control to its default.
		 */
		$hero = null;

		if ( class_exists( 'ZYMARG_SP_Flash_Hero' )
			&& isset( $_POST[ ZYMARG_SP_Flash_Hero::OPTION ] )
			&& is_array( $_POST[ ZYMARG_SP_Flash_Hero::OPTION ] ) ) {

			$hero = ZYMARG_SP_Flash_Hero::sanitize(
				wp_unslash( $_POST[ ZYMARG_SP_Flash_Hero::OPTION ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the call itself.
			);

			update_option( ZYMARG_SP_Flash_Hero::OPTION, $hero );
		}

		wp_send_json_success( [
			'message' => __( 'Settings saved.', 'zymarg-store-page' ),
			'options' => $clean,
			'hero'    => $hero,
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Ajax save/restore — admin-managed product grid sections.
	//
	// Own nonce, own action, own capability check -- deliberately not folded
	// into ajax_save_settings() above. That handler posts the entire settings
	// form as one payload; the section repeater has its own guarded save path
	// (allow-list + current_vendor restriction) that should not depend on, or
	// be blocked by, anything else on the settings screen.
	// ──────────────────────────────────────────────────────────────────────

	public static function ajax_save_store_sections() {
		if ( ! check_ajax_referer( 'zymarg_sp_save_store_sections', 'nonce', false ) ) {
			wp_send_json_error( [
				'message' => __( 'Your session expired. Reload the page and try again.', 'zymarg-store-page' ),
			], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to change these settings.', 'zymarg-store-page' ),
			], 403 );
		}

		if ( ! class_exists( 'ZYMARG_SP_Store_Sections' ) ) {
			wp_send_json_error( [ 'message' => 'sections_unavailable' ], 500 );
		}

		$raw_sections = isset( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by sanitize_rows() below.

		if ( ! is_string( $raw_sections ) || '' === $raw_sections ) {
			wp_send_json_error( [ 'message' => __( 'No section data received.', 'zymarg-store-page' ) ] );
		}

		$decoded = json_decode( $raw_sections, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid section data format.', 'zymarg-store-page' ) ] );
		}

		ZYMARG_SP_Store_Sections::save( $decoded );

		wp_send_json_success( [
			'message'  => __( 'Sections saved.', 'zymarg-store-page' ),
			'sections' => ZYMARG_SP_Store_Sections::get_all(),
		] );
	}

	/**
	 * Swap the stored section list with the rollback snapshot.
	 *
	 * The current list becomes the new snapshot, so pressing this twice
	 * undoes itself -- same contract as ZYMARG Single Product's own
	 * ajax_restore_sections().
	 */
	public static function ajax_restore_store_sections() {
		if ( ! check_ajax_referer( 'zymarg_sp_restore_store_sections', 'nonce', false ) ) {
			wp_send_json_error( [
				'message' => __( 'Your session expired. Reload the page and try again.', 'zymarg-store-page' ),
			], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to change these settings.', 'zymarg-store-page' ),
			], 403 );
		}

		if ( ! class_exists( 'ZYMARG_SP_Store_Sections' ) ) {
			wp_send_json_error( [ 'message' => 'sections_unavailable' ], 500 );
		}

		$restored = ZYMARG_SP_Store_Sections::restore();

		if ( false === $restored ) {
			wp_send_json_error( [ 'message' => __( 'There is no previous version to restore.', 'zymarg-store-page' ) ] );
		}

		wp_send_json_success( [
			'message'  => __( 'Previous sections restored.', 'zymarg-store-page' ),
			'sections' => $restored,
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Helper: render a toggle field
	// ──────────────────────────────────────────────────────────────────────
	private static function toggle_field( $name, $checked, $label, $desc ) {
		$id = 'zymarg_' . $name;
		echo '<div class="zsp-field">';
		echo '<label class="zsp-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="zsp-toggle-wrap">';
		echo '<span class="zsp-toggle">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="zymarg_sp_options[' . esc_attr( $name ) . ']" value="1" ' . checked( $checked, true, false ) . ' />';
		echo '<span class="zsp-toggle__slider"></span>';
		echo '</span>';
		// The word, not just the pill colour, carries the state.
		echo '<span class="zsp-toggle-state">'
		     . esc_html( $checked ? __( 'Enabled', 'zymarg-store-page' ) : __( 'Disabled', 'zymarg-store-page' ) )
		     . '</span>';
		echo '</div>';
		echo '<p class="zsp-field-desc">' . esc_html( $desc ) . '</p>';
		echo '</div>';
	}

	// ──────────────────────────────────────────────────────────────────────
	// Render settings page
	// ──────────────────────────────────────────────────────────────────────
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dokan_active = ( class_exists( 'WeDevs_Dokan' ) || function_exists( 'dokan' ) );
		$tpl_exists   = file_exists( ZYMARG_SP_TEMPLATES . 'store.php' );
		$override_on  = $dokan_active && $tpl_exists;

		$opts            = get_option( 'zymarg_sp_options', [] );
		$ppv             = isset( $opts['products_per_page'] ) ? (int) $opts['products_per_page'] : 8;
		$pwv            = isset( $opts['page_width'] ) ? (int) $opts['page_width'] : 0;
		$show_aura       = isset( $opts['show_aura_search'] )  ? (bool) $opts['show_aura_search']  : true;
		$show_reviews    = isset( $opts['show_reviews'] )      ? (bool) $opts['show_reviews']      : true;
		$no_results_slug = isset( $opts['no_results_slug'] )   ? $opts['no_results_slug']          : 'community';
		?>

		<div class="zymarg-sp-admin">

			<!-- ── Header ── -->
			<div class="zsp-header">
				<div class="zsp-header__left">
					<?php
					// The Spark renders at 44x44 directly on white in the admin
					// header, with no container or tint behind it.
					if ( function_exists( 'zymarg_sp_spark' ) ) {
						echo zymarg_sp_spark( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							[
								'size'  => 'header',
								'label' => 'ZYMARG',
								'class' => 'zsp-header__mark',
							]
						);
					}
					?>
					<div class="zsp-header__text">
						<h1 class="zsp-wordmark"><?php esc_html_e( 'ZYMARG Store Page', 'zymarg-store-page' ); ?></h1>
						<p class="zsp-subtitle"><?php esc_html_e( 'Premium Dokan vendor store template — drop-in, no theme edits required.', 'zymarg-store-page' ); ?></p>
					</div>
				</div>
				<div class="zsp-header__right">
					<span class="zsp-badge">v<?php echo esc_html( ZYMARG_SP_VERSION ); ?></span>
				</div>
			</div>

			<!-- ── Override status banner ── -->
			<?php if ( $override_on ) : ?>
			<div class="zsp-banner zsp-banner--active">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
				<?php esc_html_e( 'Override is ACTIVE — all Dokan store pages are using the ZYMARG design.', 'zymarg-store-page' ); ?>
			</div>
			<?php else : ?>
			<div class="zsp-banner zsp-banner--inactive">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
				<?php esc_html_e( 'Override is INACTIVE — Dokan or the template file is missing.', 'zymarg-store-page' ); ?>
			</div>
			<?php endif; ?>

			<!-- ── Two-column layout ── -->
			<div class="zsp-layout">

				<!-- Main: Settings -->
				<div class="zsp-main">
					<div class="zsp-card">
						<div class="zsp-card__head">
							<div class="zsp-card__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
							</div>
							<h2><?php esc_html_e( 'Settings', 'zymarg-store-page' ); ?></h2>
						</div>
						<div class="zsp-card__body">
							<?php // action/nonce are kept so the form still saves if JavaScript is unavailable. ?>
							<form method="post" action="options.php" id="zsp-settings-form">
								<?php settings_fields( 'zymarg_sp_options' ); ?>

								<!-- ── Products per page ── -->
								<p class="zsp-section-label"><?php esc_html_e( 'Product Grid', 'zymarg-store-page' ); ?></p>
								<div class="zsp-field">
									<label class="zsp-label" for="zymarg_per_page">
										<?php esc_html_e( 'Products per page', 'zymarg-store-page' ); ?>
									</label>
									<input type="number" id="zymarg_per_page" min="4" max="48" step="4"
										name="zymarg_sp_options[products_per_page]"
										value="<?php echo esc_attr( $ppv ); ?>" />
									<p class="zsp-field-desc"><?php esc_html_e( 'How many products load per page. Multiple of 4 recommended. Default: 8.', 'zymarg-store-page' ); ?></p>
								</div>

								<!-- ── Content width ── -->
								<div class="zsp-field">
									<label class="zsp-label" for="zymarg_page_width">
										<?php esc_html_e( 'Content Width', 'zymarg-store-page' ); ?>
									</label>
									<input type="number" id="zymarg_page_width" min="0" max="100" step="1"
										name="zymarg_sp_options[page_width]"
										value="<?php echo esc_attr( $pwv ); ?>" />
									<p class="zsp-field-desc"><?php esc_html_e( 'How much of the screen width the page content uses, as a percentage of the viewport, on screens above 768px. 0 is the design as shipped, which caps content at a fixed 1280px - on a 2560px or 4K monitor that leaves roughly half the screen empty. Any other value is a percentage that keeps adjusting itself to whatever screen the page is opened on, so 92 fills 92 percent of a 1440px laptop and of an 8K display alike. 100 runs content to the very edges. Phones are unaffected. This is the same control, with the same numbers, as Content Width on the Homepage and Connection Engine plugins - set all three to the same value for a consistent site. Default: 0.', 'zymarg-store-page' ); ?></p>
								</div>

								<div class="zsp-divider"></div>

								<!-- ── Section visibility toggles ── -->
								<p class="zsp-section-label"><?php esc_html_e( 'Section Visibility', 'zymarg-store-page' ); ?></p>

								<?php
								self::toggle_field(
									'show_aura_search',
									$show_aura,
									__( 'AURA live-search bar', 'zymarg-store-page' ),
									__( 'Show the AURA search bar in the sticky header. Queries the Dokan REST API with debounced live results.', 'zymarg-store-page' )
								);

								self::toggle_field(
									'show_reviews',
									$show_reviews,
									__( 'Customer Reviews section', 'zymarg-store-page' ),
									__( 'Show the "What buyers are saying" reviews section on the store page. When disabled the section is completely removed from the page output.', 'zymarg-store-page' )
								);
								?>

								<div class="zsp-divider"></div>

								<!-- ── No-results CTA slug ── -->
								<p class="zsp-section-label"><?php esc_html_e( 'Search Empty State', 'zymarg-store-page' ); ?></p>
								<div class="zsp-field">
									<label class="zsp-label" for="zymarg_no_results_slug">
										<?php esc_html_e( 'No-results CTA slug', 'zymarg-store-page' ); ?>
									</label>
									<input type="text" id="zymarg_no_results_slug" class="zsp-input--wide"
										name="zymarg_sp_options[no_results_slug]"
										value="<?php echo esc_attr( $no_results_slug ); ?>"
										placeholder="community" />
									<p class="zsp-field-desc"><?php esc_html_e( 'Page slug the "Request Here" button links to when a search returns zero results. Do not include leading or trailing slashes. Default: community.', 'zymarg-store-page' ); ?></p>
								</div>

								<div class="zsp-divider"></div>

							<?php
							/*
							 * The Flash Sale hero panel, rendered inside this
							 * same card and this same form.
							 *
							 * Not a sibling card, though visually that would be
							 * tidier. The form opens inside this card's body, so
							 * closing the card to start another one would put a
							 * </div> in the middle of an open <form> and the
							 * markup would be mis-nested. Browsers recover from
							 * that by closing the form early, which would leave
							 * every hero field outside it -- they would silently
							 * never submit, and the screen would still report a
							 * successful save.
							 *
							 * One form also means one Save button covers both
							 * option groups, with no way to save half a screen.
							 * The hero's option is registered into this group,
							 * which is what keeps the no-JavaScript options.php
							 * path saving both of them.
							 */
							if ( class_exists( 'ZYMARG_SP_Flash_Hero' ) ) :
								$zsp_flash_url = ZYMARG_SP_Flash_Sale::page_url();
								?>
								<div class="zsp-divider"></div>

								<p class="zsp-section-heading">
									<span class="zsp-section-heading__mark" aria-hidden="true">
										<svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
									</span>
									<?php esc_html_e( 'Flash Sale Hero', 'zymarg-store-page' ); ?>
								</p>

								<p class="zsp-field-desc zsp-intro">
									<?php esc_html_e( 'Everything about the banner at the top of your Flash Sale page. Every field is optional: leave one empty and the shipped design is used for it, so an untouched install looks exactly as it does today.', 'zymarg-store-page' ); ?>
									<?php if ( '' !== $zsp_flash_url ) : ?>
										<a href="<?php echo esc_url( $zsp_flash_url ); ?>" target="_blank" rel="noopener">
											<?php esc_html_e( 'View the page', 'zymarg-store-page' ); ?>
										</a>
									<?php endif; ?>
								</p>

								<?php
								ZYMARG_SP_Flash_Hero::render_fields();

								echo '<div class="zsp-divider"></div>';
							endif;
							?>

								<div class="zsp-save-row">
									<button type="submit" class="zsp-save-btn">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
										<?php esc_html_e( 'Save Settings', 'zymarg-store-page' ); ?>
									</button>
									<?php // Ajax result lands here; announced to screen readers. ?>
									<span id="zsp-save-status" class="zsp-save-status" role="status" aria-live="polite"></span>
								</div>
							</form>
						</div>
					</div>
				</div>

				<!-- Aside: Status + Quick Links -->
				<div class="zsp-aside">

					<!-- Status card -->
					<div class="zsp-card zsp-card--flush">
						<div class="zsp-card__head">
							<div class="zsp-card__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
							</div>
							<h2><?php esc_html_e( 'Plugin Status', 'zymarg-store-page' ); ?></h2>
						</div>
						<div class="zsp-card__body zsp-card__body--tight">
							<?php
							// The data-zsp-status key lets the Ajax save keep these rows
							// current now that the page no longer reloads.
							$status_items = [
								[ 'label' => __( 'Dokan plugin active',   'zymarg-store-page' ), 'ok' => $dokan_active, 'key' => '' ],
								[ 'label' => __( 'Template file present', 'zymarg-store-page' ), 'ok' => $tpl_exists,   'key' => '' ],
								[ 'label' => __( 'Store override active', 'zymarg-store-page' ), 'ok' => $override_on,  'key' => '' ],
								[ 'label' => __( 'AURA search enabled',   'zymarg-store-page' ), 'ok' => $show_aura,    'key' => 'show_aura_search' ],
								[ 'label' => __( 'Reviews section on',    'zymarg-store-page' ), 'ok' => $show_reviews, 'key' => 'show_reviews' ],
							];

							echo '<ul class="zsp-status-list">';
							foreach ( $status_items as $item ) {
								$state = $item['ok'] ? 'ok' : 'err';
								echo '<li' . ( $item['key'] ? ' data-zsp-status="' . esc_attr( $item['key'] ) . '"' : '' ) . '>';
								echo '<span class="zsp-status-dot zsp-status-dot--' . esc_attr( $state ) . '"></span>';
								echo esc_html( $item['label'] );
								echo '<span class="zsp-status-value zsp-status-value--' . esc_attr( $state ) . '">'
								     . ( $item['ok'] ? esc_html__( 'ON', 'zymarg-store-page' ) : esc_html__( 'OFF', 'zymarg-store-page' ) )
								     . '</span>';
								echo '</li>';
							}
							echo '</ul>';
							?>
						</div>
					</div>

					<!-- Quick links card -->
					<div class="zsp-card zsp-card--flush">
						<div class="zsp-card__head">
							<div class="zsp-card__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
							</div>
							<h2><?php esc_html_e( 'Quick Links', 'zymarg-store-page' ); ?></h2>
						</div>
						<div class="zsp-card__body zsp-card__body--tighter">
							<ul class="zsp-links">
								<?php if ( function_exists( 'dokan_get_store_url' ) ) : ?>
								<li>
									<a href="<?php echo esc_url( dokan_get_store_url( get_current_user_id() ) ); ?>" target="_blank">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
										<?php esc_html_e( 'Preview your store', 'zymarg-store-page' ); ?>
									</a>
								</li>
								<?php endif; ?>
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=dokan' ) ); ?>">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
										<?php esc_html_e( 'Go to Dokan Dashboard', 'zymarg-store-page' ); ?>
									</a>
								</li>
								<li>
									<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 0 1-.657.643 48.39 48.39 0 0 1-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 0 1-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 0 0-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 0 1-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 0 0 .657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 0 1-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 0 0 5.427-.63 48.05 48.05 0 0 0 .582-4.717.532.532 0 0 0-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.959.401v0a.656.656 0 0 0 .658-.663 48.422 48.422 0 0 0-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 0 1-.61-.58v0Z"/></svg>
										<?php esc_html_e( 'Manage Plugins', 'zymarg-store-page' ); ?>
									</a>
								</li>
							</ul>
						</div>
					</div>

					<!-- Version chip -->
					<div class="zsp-center">
						<span class="zsp-version-chip">
							ZYMARG Store Page &nbsp;·&nbsp; <strong>v<?php echo esc_html( ZYMARG_SP_VERSION ); ?></strong>
						</span>
					</div>

				</div><!-- /.zsp-aside -->
			</div><!-- /.zsp-layout -->

			<?php self::render_store_sections_card(); ?>

		</div><!-- /.zymarg-sp-admin -->
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────
	// Product grid sections — Trending / Best Selling / All Products by
	// default, each an admin-editable [zymarg_products] shortcode against
	// the engine's current_vendor source.
	//
	// Deliberately its own full-width card below the two-column settings
	// layout, not squeezed into .zsp-main: a shortcode textarea and a
	// drag-reorderable list both want more horizontal room than the
	// settings column gives every other field on this screen.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * The Product Grid Sections card.
	 *
	 * @return void
	 */
	private static function render_store_sections_card() {
		if ( ! class_exists( 'ZYMARG_SP_Store_Sections' ) ) {
			return;
		}

		$sections = ZYMARG_SP_Store_Sections::get_all();
		$backup   = get_option( ZYMARG_SP_Store_Sections::BACKUP_KEY, [] );
		?>
		<div class="zsp-card zsp-card--sections">
			<div class="zsp-card__head">
				<div class="zsp-card__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
				</div>
				<h2><?php esc_html_e( 'Product Grid Sections', 'zymarg-store-page' ); ?></h2>
			</div>
			<div class="zsp-card__body">

				<p class="zsp-field-desc zsp-sections__lede">
					<?php esc_html_e( 'These sections render on the store page, above the category sidebar, in the order listed. Drag a row by its handle to move it up or down. Each row runs one ZYMARG Product Grid shortcode against this vendor\'s own products, so you can change the layout, limit or card without a plugin update.', 'zymarg-store-page' ); ?>
				</p>
				<p class="zsp-field-desc zsp-sections__lede">
					<strong><?php esc_html_e( 'Rows open locked.', 'zymarg-store-page' ); ?></strong>
					<?php esc_html_e( 'Press Edit on a row before anything in it can be changed, so simply visiting this screen cannot alter what shoppers see. Removing a row asks for confirmation, and the list from before your last save can be restored below.', 'zymarg-store-page' ); ?>
				</p>
				<p class="zsp-field-desc zsp-sections__lede">
					<strong><?php esc_html_e( 'Every section must use source="current_vendor".', 'zymarg-store-page' ); ?></strong>
					<?php esc_html_e( 'A store page always shows one vendor\'s own products, so that is the only source a row here can run. Available subsets: all, featured, trending, best_selling.', 'zymarg-store-page' ); ?>
				</p>
				<p class="zsp-field-desc zsp-sections__lede">
					<strong><?php esc_html_e( 'One row is special.', 'zymarg-store-page' ); ?></strong>
					<?php esc_html_e( 'Whichever enabled row uses current_vendor_subset="all" (the default when the attribute is left out) renders inside the existing category sidebar layout, with infinite scroll, instead of as a standalone block. Only the first such row is treated this way.', 'zymarg-store-page' ); ?>
				</p>

				<div id="zsp-store-sections" class="zsp-sections">
					<?php foreach ( $sections as $row ) : ?>
						<?php self::render_store_section_row( is_array( $row ) ? $row : [] ); ?>
					<?php endforeach; ?>
				</div>

				<div class="zsp-sections__actions">
					<button type="button" class="zsp-sections__add" id="zsp-add-store-section">
						<span aria-hidden="true">+</span> <?php esc_html_e( 'Add Section', 'zymarg-store-page' ); ?>
					</button>

					<button type="button" class="zsp-sections__save" id="zsp-save-store-sections">
						<?php esc_html_e( 'Save Sections', 'zymarg-store-page' ); ?>
					</button>

					<?php if ( is_array( $backup ) && ! empty( $backup ) ) : ?>
						<button type="button" class="zsp-sections__restore" id="zsp-restore-store-sections">
							<?php
							printf(
								/* translators: %d: number of sections in the rollback snapshot. */
								esc_html__( 'Restore previous (%d sections)', 'zymarg-store-page' ),
								count( $backup )
							);
							?>
						</button>
					<?php endif; ?>

					<span id="zsp-sections-status" class="zsp-save-status" role="status" aria-live="polite"></span>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * A single section repeater row.
	 *
	 * @param array $row Row data: id, label, enabled, heading, show_link,
	 *                   link_url, shortcode.
	 * @return void
	 */
	private static function render_store_section_row( array $row ) {
		$id        = (string) ( $row['id'] ?? '' );
		$label     = (string) ( $row['label'] ?? '' );
		$heading   = (string) ( $row['heading'] ?? '' );
		$link_url  = (string) ( $row['link_url'] ?? '' );
		$shortcode = (string) ( $row['shortcode'] ?? '' );
		$enabled   = ! empty( $row['enabled'] );
		$show_link = ! empty( $row['show_link'] );
		$source    = ZYMARG_SP_Store_Sections::source_of( $shortcode );
		$layout    = ZYMARG_SP_Store_Sections::layout_of( $shortcode );
		$subset    = ZYMARG_SP_Store_Sections::subset_of( $shortcode );
		$is_all    = ZYMARG_SP_Store_Sections::is_all_products_row( $row );
		?>
		<div class="zsp-section-row is-locked<?php echo $enabled ? '' : ' is-disabled'; ?>" data-row-id="<?php echo esc_attr( $id ); ?>">

			<div class="zsp-section-row__head">
				<span class="zsp-section-row__handle" title="<?php esc_attr_e( 'Drag to reorder', 'zymarg-store-page' ); ?>" aria-hidden="true">&#8942;&#8942;</span>

				<input type="text"
					class="zymarg-sp-admin-input zsp-section-row__label"
					data-row-field="label"
					value="<?php echo esc_attr( $label ); ?>"
					readonly
					placeholder="<?php esc_attr_e( 'Section name (admin only)', 'zymarg-store-page' ); ?>">

				<span class="zsp-section-row__meta" data-row-meta>
					<?php
					echo esc_html(
						( '' !== $source ? $source : '?' ) . ' · ' . $layout . ' · ' . $subset
						. ( $is_all ? ' · ' . __( 'special: All Products', 'zymarg-store-page' ) : '' )
					);
					?>
				</span>

				<label class="zsp-toggle zsp-section-row__toggle">
					<input type="checkbox" data-row-field="enabled" disabled <?php checked( $enabled ); ?>>
					<span class="zsp-toggle__slider"></span>
				</label>

				<button type="button" class="zsp-section-row__edit"><?php esc_html_e( 'Edit', 'zymarg-store-page' ); ?></button>
				<button type="button" class="zsp-section-row__remove" aria-label="<?php esc_attr_e( 'Remove section', 'zymarg-store-page' ); ?>">&times;</button>
			</div>

			<div class="zsp-section-row__body">

				<div class="zsp-section-row__field">
					<label class="zsp-section-row__flabel"><?php esc_html_e( 'Section heading (shown on the page)', 'zymarg-store-page' ); ?></label>
					<input type="text"
						class="zymarg-sp-admin-input"
						data-row-field="heading"
						value="<?php echo esc_attr( $heading ); ?>"
						readonly
						placeholder="<?php esc_attr_e( 'Trending', 'zymarg-store-page' ); ?>">
					<p class="zsp-section-row__hint">
						<?php esc_html_e( 'Plain text only. Leave empty for no heading. Ignored on the All Products row, which uses this plugin\'s existing All Products toolbar instead.', 'zymarg-store-page' ); ?>
					</p>
				</div>

				<div class="zsp-section-row__field zsp-section-row__field--inline">
					<label class="zsp-toggle">
						<input type="checkbox" data-row-field="show_link" disabled <?php checked( $show_link ); ?>>
						<span class="zsp-toggle__slider"></span>
					</label>
					<span class="zsp-section-row__flabel"><?php esc_html_e( 'Show the section link', 'zymarg-store-page' ); ?></span>
				</div>

				<div class="zsp-section-row__field">
					<label class="zsp-section-row__flabel"><?php esc_html_e( 'Link URL', 'zymarg-store-page' ); ?></label>
					<input type="url"
						class="zymarg-sp-admin-input"
						data-row-field="link_url"
						value="<?php echo esc_attr( $link_url ); ?>"
						readonly
						placeholder="https://">
					<p class="zsp-section-row__hint">
						<?php esc_html_e( 'Leave empty and no link renders at all. The link reads "Explore More".', 'zymarg-store-page' ); ?>
					</p>
				</div>

				<div class="zsp-section-row__field">
					<label class="zsp-section-row__flabel"><?php esc_html_e( 'Shortcode', 'zymarg-store-page' ); ?></label>
					<textarea class="zymarg-sp-admin-input zsp-textarea zsp-section-row__shortcode"
						data-row-field="shortcode"
						rows="3"
						readonly
						spellcheck="false"
						placeholder="[zymarg_products source=&quot;current_vendor&quot; current_vendor_subset=&quot;all&quot; limit=&quot;8&quot;]"><?php echo esc_textarea( $shortcode ); ?></textarea>
					<p class="zsp-section-row__hint">
						<?php esc_html_e( 'Must use source="current_vendor". Available current_vendor_subset values: all, featured, trending, best_selling.', 'zymarg-store-page' ); ?>
					</p>
					<p class="zsp-section-row__warn" hidden></p>
				</div>

			</div>
		</div>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────
	// Plugin action links
	// ──────────────────────────────────────────────────────────────────────
	public static function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=zymarg-store-page' ) ),
			esc_html__( 'Settings', 'zymarg-store-page' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
