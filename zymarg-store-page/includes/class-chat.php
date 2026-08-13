<?php
/**
 * Chat Integration — ZSS side
 *
 * The ZYMARG Store Page plugin is intentionally NOT responsible for any
 * messaging behaviour. All of the following are owned by the
 * ZYMARG Communication plugin (v1.18.0+):
 *
 *   - Enqueueing popup / chat-core / inbox JS on store pages.
 *   - Injecting chatEnabled, commApiBase, commNonce into ZYMARG_CONFIG
 *     (via the `zymarg_sp_config` filter defined in class-assets.php).
 *   - Rendering the #zy-chat-overlay popup container (wp_footer hook).
 *   - Wiring [data-chat-btn] click → open conversation via popup.js.
 *
 * ZSS responsibilities (unchanged):
 *   - Render [data-chat-btn] buttons with data-seller-id in store.php.
 *   - Fire apply_filters( 'zymarg_sp_config', $config ) in class-assets.php
 *     so the Comm plugin can extend the JS config object.
 *
 * This class is kept so zymarg_sp_init() can still call ZYMARG_SP_Chat::init()
 * without errors. The helper is_comm_active() remains available to any future
 * template code that needs to gate on the Comm plugin being present.
 *
 * @package ZYMARG_Store_Page
 * @since   1.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZYMARG_SP_Chat {

	/**
	 * No-op. All messaging wiring is handled by the ZYMARG Communication plugin.
	 */
	public static function init(): void {
		// Intentionally empty — see class docblock above.
	}

	/**
	 * Returns true when the ZYMARG Communication plugin is active.
	 * Detected via the constant it defines on boot.
	 *
	 * @return bool
	 */
	public static function is_comm_active(): bool {
		return defined( 'ZYMARG_COMM_API_NAMESPACE' );
	}
}
