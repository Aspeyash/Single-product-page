/**
 * ZYMARG Vendor Dashboard -- live Premium pending-request menu badge.
 *
 * Keeps the count bubble on "Vendor Hub" and "Premium" in the admin sidebar
 * in sync with zymarg_vd_premium_pending_count() WITHOUT a page reload, by
 * riding WordPress core's own Heartbeat API -- the same polling loop already
 * running on every wp-admin screen for post-lock and autosave, rather than
 * adding a second independent setInterval/AJAX cycle of our own.
 *
 * Heartbeat ticks every 15-60 seconds depending on the site's own Heartbeat
 * interval setting, so "live" here means "catches up automatically within
 * roughly that window with nobody touching anything," the same responsiveness
 * WordPress core's own Plugins-update bubble and WooCommerce's own
 * order-count bubble would have if either rode Heartbeat for their count too.
 *
 * Loaded on EVERY wp-admin screen (see zymarg_vd_enqueue_menu_branding() in
 * includes/admin-hub.php), because the "Vendor Hub" / "Premium" sidebar items
 * themselves are visible everywhere, not only on the plugin's own screens.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function ($) {
	'use strict';

	/**
	 * Write a new count into every badge element on the page.
	 *
	 * There are two: the top-level "Vendor Hub" item and its "Premium"
	 * submenu item. Both carry [data-zvd-premium-badge="1"], written by
	 * zymarg_vd_premium_menu_title_with_badge() in includes/premium.php, so
	 * this only ever has to know that selector -- never how many places the
	 * badge lives, or what that number happens to be today.
	 *
	 * @param {number} count Current pending-request count.
	 */
	function render(count) {
		var $badges = $('[data-zvd-premium-badge="1"]');
		if (!$badges.length) {
			return;
		}

		var n = parseInt(count, 10);
		if (isNaN(n) || n <= 0) {
			$badges.addClass('zvd-is-hidden').find('.plugin-count').text('');
			return;
		}

		$badges.removeClass('zvd-is-hidden').find('.plugin-count').text(n);
	}

	// Every Heartbeat tick response includes zymarg_vd_premium_pending
	// (see zymarg_vd_premium_heartbeat_received() in includes/premium-admin.php,
	// hooked to both 'heartbeat_send' and 'heartbeat_received' so the badge
	// starts updating from the very first tick, not the second one).
	$(document).on('heartbeat-tick', function (event, data) {
		if (data && Object.prototype.hasOwnProperty.call(data, 'zymarg_vd_premium_pending')) {
			render(data.zymarg_vd_premium_pending);
		}
	});

})(jQuery);
