/**
 * ZYMARG Vendor Dashboard -- Premium admin screen.
 *
 * Master switches + approval queue, all over AJAX.
 *
 * Every handler is delegated from `document` and namespaced, and init() is
 * re-run on `zymarg-vd:section-loaded`, so the screen keeps working after the
 * hub swaps it in without a page load. Handlers are always unbound before
 * being rebound, or a swapped-in screen would fire each action twice.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function ($) {
	'use strict';

	var NS = '.zymargPremium';

	/**
	 * Config, with safe fallbacks so a missing localization can never throw.
	 */
	function cfg() {
		return window.ZymargPremiumAdmin || { ajaxUrl: '', nonce: '', i18n: {} };
	}

	function t(key, fallback) {
		var i18n = cfg().i18n || {};
		return i18n[key] || fallback;
	}

	/**
	 * Write a status message using state classes only -- never inline colour.
	 *
	 * @param {jQuery} $el     Status element.
	 * @param {string} message Text to show.
	 * @param {string} state   'ok', 'err' or '' while working.
	 */
	function status($el, message, state) {
		if (!$el.length) {
			return;
		}
		$el.removeClass('zvd-status-msg--ok zvd-status-msg--err').text(message);
		if (state === 'ok') {
			$el.addClass('zvd-status-msg--ok');
		} else if (state === 'err') {
			$el.addClass('zvd-status-msg--err');
		}
	}

	/**
	 * POST to admin-ajax with the Premium nonce.
	 *
	 * @param {string}   action Action suffix.
	 * @param {Object}   data   Extra payload.
	 * @param {Function} done   Success callback.
	 * @param {Function} fail   Failure callback, given a message.
	 */
	function post(action, data, done, fail) {
		var payload = $.extend({ action: action, nonce: cfg().nonce }, data || {});

		$.ajax({
			url: cfg().ajaxUrl,
			type: 'POST',
			data: payload
		}).done(function (response) {
			if (response && response.success) {
				done(response.data || {});
			} else {
				var msg = (response && response.data && response.data.message)
					? response.data.message
					: t('failed', 'That did not save. Try again.');
				fail(msg);
			}
		}).fail(function () {
			fail(t('network', 'Network error.'));
		});
	}

	/**
	 * Run a decision (approve / reject / revoke) on one queue row.
	 *
	 * @param {jQuery} $row   The row.
	 * @param {string} action AJAX action suffix.
	 * @param {string} okKey  i18n key for the success message.
	 */
	function decide($row, action, okKey) {
		var $status = $row.find('.zvd-premium-row-status');
		var $buttons = $row.find('button');

		$buttons.prop('disabled', true);
		status($status, t('working', 'Working'), '');

		post(
			action,
			{
				vendor: $row.data('vendor'),
				feature: $row.data('feature'),
				note: $row.find('.zvd-premium-note').val() || ''
			},
			function (data) {
				status($status, data.message || t(okKey, 'Done.'), 'ok');
				// The row's decision is made; keep the outcome visible but inert.
				$row.find('.zvd-premium-note').prop('disabled', true);
			},
			function (message) {
				$buttons.prop('disabled', false);
				status($status, message, 'err');
			}
		);
	}

	function init() {
		// --- Master switches -------------------------------------------------
		$(document).off('submit' + NS, '#zvd-premium-switches');
		$(document).on('submit' + NS, '#zvd-premium-switches', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var $status = $('#zvd-premium-switch-status');
			var data = {};

			$form.find('input[type="checkbox"]').each(function () {
				data[this.name] = this.checked ? 1 : 0;
			});

			$btn.prop('disabled', true);
			status($status, t('saving', 'Saving'), '');

			post(
				'zymarg_vd_premium_save_switches',
				data,
				function (payload) {
					$btn.prop('disabled', false);
					status($status, payload.message || t('saved', 'Saved.'), 'ok');
				},
				function (message) {
					$btn.prop('disabled', false);
					status($status, message, 'err');
				}
			);
		});

		// --- Approve ---------------------------------------------------------
		$(document).off('click' + NS, '.zvd-premium-approve');
		$(document).on('click' + NS, '.zvd-premium-approve', function () {
			decide($(this).closest('.zvd-premium-row'), 'zymarg_vd_premium_approve', 'approved');
		});

		// --- Reject ----------------------------------------------------------
		$(document).off('click' + NS, '.zvd-premium-reject');
		$(document).on('click' + NS, '.zvd-premium-reject', function () {
			decide($(this).closest('.zvd-premium-row'), 'zymarg_vd_premium_reject', 'rejected');
		});

		// --- Revoke ----------------------------------------------------------
		$(document).off('click' + NS, '.zvd-premium-revoke');
		$(document).on('click' + NS, '.zvd-premium-revoke', function () {
			var confirmMsg = t('confirmRevoke', 'Revoke this vendor\'s access?');
			if (!window.confirm(confirmMsg)) {
				return;
			}
			decide($(this).closest('.zvd-premium-row'), 'zymarg_vd_premium_revoke', 'revoked');
		});

		// --- Limits and display ----------------------------------------------
		$(document).off('submit' + NS, '#zvd-premium-display');
		$(document).on('submit' + NS, '#zvd-premium-display', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var $status = $('#zvd-premium-display-status');
			var data = {};

			$form.find('input[type="number"]').each(function () {
				data[this.name] = this.value;
			});
			$form.find('input[type="radio"]:checked').each(function () {
				data[this.name] = this.value;
			});

			$btn.prop('disabled', true);
			status($status, t('saving', 'Saving'), '');

			post(
				'zymarg_vd_premium_save_display',
				data,
				function (payload) {
					$btn.prop('disabled', false);
					status($status, payload.message || t('saved', 'Saved.'), 'ok');

					// Re-sync from what was actually stored. The server clamps
					// every value, so an out-of-range entry must not sit on
					// screen looking like it was accepted.
					var saved = payload.settings || {};
					$form.find('input[type="number"]').each(function () {
						if (Object.prototype.hasOwnProperty.call(saved, this.name)) {
							this.value = saved[this.name];
						}
					});
					$form.find('input[type="radio"]').each(function () {
						if (Object.prototype.hasOwnProperty.call(saved, this.name)) {
							this.checked = (this.value === saved[this.name]);
						}
					});
				},
				function (message) {
					$btn.prop('disabled', false);
					status($status, message, 'err');
				}
			);
		});

		// --- Per-vendor limit overrides --------------------------------------
		$(document).off('click' + NS, '.zvd-premium-save-limits');
		$(document).on('click' + NS, '.zvd-premium-save-limits', function () {
			var $btn = $(this);
			var $row = $btn.closest('.zvd-premium-row');
			var $status = $row.find('.zvd-premium-row-status');
			var limits = {};

			// Blank fields are sent deliberately: an empty value clears the
			// override and hands the vendor back to the global setting.
			$row.find('.zvd-premium-limit').each(function () {
				limits[$(this).data('key')] = this.value;
			});

			$btn.prop('disabled', true);
			status($status, t('saving', 'Saving'), '');

			post(
				'zymarg_vd_premium_save_vendor_limits',
				{ vendor: $row.data('vendor'), limits: limits },
				function (payload) {
					$btn.prop('disabled', false);
					status($status, payload.message || t('saved', 'Saved.'), 'ok');
				},
				function (message) {
					$btn.prop('disabled', false);
					status($status, message, 'err');
				}
			);
		});
	}

	$(document).ready(init);

	// Re-initialize after an AJAX section swap (SPA nav).
	$(document).on('zymarg-vd:section-loaded', init);

})(jQuery);
