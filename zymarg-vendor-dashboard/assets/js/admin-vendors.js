/**
 * ZYMARG Vendor Dashboard -- Admin Vendors commission management.
 *
 * Handles commission type toggling and AJAX save per vendor card.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function ($) {
	'use strict';

	/**
	 * Toggle field visibility based on commission type selection.
	 */
	function toggleFields($card, type) {
		var $pct  = $card.find('.zymarg-field-percentage');
		var $flat = $card.find('.zymarg-field-flat');

		// Toggle a token-driven state class instead of writing inline styles.
		var showPct  = ( type === 'percentage' || type === 'combine' );
		var showFlat = ( type === 'flat' || type === 'combine' );

		$pct.toggleClass('zvd-is-hidden', !showPct);
		$flat.toggleClass('zvd-is-hidden', !showFlat);
	}

	/**
	 * Show inline feedback on a vendor card.
	 */
	function showFeedback($card, message, isError) {
		var $fb = $card.find('.zymarg-vendor-card__feedback');
		$fb.text(message)
			.removeClass('zymarg-vendor-card__feedback--success zymarg-vendor-card__feedback--error')
			.addClass(isError ? 'zymarg-vendor-card__feedback--error' : 'zymarg-vendor-card__feedback--success');

		setTimeout(function () {
			$fb.removeClass('zymarg-vendor-card__feedback--success zymarg-vendor-card__feedback--error');
		}, 4000);
	}

	// Debounce for the seller search box, in milliseconds.
	var SEARCH_DEBOUNCE = 300;

	var searchTimer   = null;
	var currentSearch = '';
	var inFlight      = null;

	/**
	 * Read a localized string with a safe fallback.
	 */
	function str(key, fallback) {
		if (typeof ZymargVendors !== 'undefined' && ZymargVendors.i18n && ZymargVendors.i18n[key]) {
			return ZymargVendors.i18n[key];
		}

		return fallback;
	}

	/**
	 * Fetch a batch of vendor cards.
	 *
	 * @param {string}  search Search term.
	 * @param {number}  page   1-based batch number.
	 * @param {boolean} append True to add to the list, false to replace it.
	 */
	function fetchVendors(search, page, append) {
		var $results = $('#zvd-vendor-results');
		var $empty   = $('#zvd-vendor-empty');
		var $more    = $('#zvd-vendor-more');
		var $btn     = $('#zvd-vendor-load-more');
		var $status  = $('#zvd-vendor-search-status');

		if (!$results.length) {
			return;
		}

		// A slow earlier request must never overwrite a newer one. Typing fast
		// would otherwise leave the results showing a stale search term.
		if (inFlight) {
			inFlight.abort();
		}

		$status.text(append ? str('loading', 'Loading...') : str('searching', 'Searching...'));

		if (append) {
			$btn.prop('disabled', true).text(str('loading', 'Loading...'));
		}

		inFlight = $.ajax({
			url: ZymargVendors.ajaxUrl,
			type: 'POST',
			data: {
				action: 'zymarg_vd_search_vendors',
				nonce: ZymargVendors.nonce,
				search: search,
				page: page
			},
			success: function (response) {
				if (!response || !response.success) {
					$status.text(str('network', 'Network error. Please try again.'));
					return;
				}

				if (append) {
					$results.append(response.data.html);
				} else {
					$results.html(response.data.html);
				}

				var shown = $results.find('.zymarg-vendor-card').length;

				$empty.toggleClass('zvd-is-hidden', shown > 0);
				if (!shown) {
					$empty.find('p').text(str('noResults', 'No sellers match that search.'));
				}

				$more.toggleClass('zvd-is-hidden', !response.data.hasMore);
				$btn.attr('data-page', response.data.page).data('page', response.data.page);

				$status.text(str('showing', 'Showing %d').replace('%d', shown));
			},
			error: function (jqXHR, textStatus) {
				// An aborted request is us superseding it, not a real failure.
				if (textStatus === 'abort') {
					return;
				}

				$status.text(str('network', 'Network error. Please try again.'));
			},
			complete: function () {
				inFlight = null;
				$btn.prop('disabled', false).text(str('loadMore', 'Load more sellers'));
			}
		});
	}

	/**
	 * Initialize event handlers (called on page load and after AJAX nav swap).
	 */
	function init() {
		// Commission type change.
		$(document).off('change.zymargVendors', '.zymarg-commission-type');
		$(document).on('change.zymargVendors', '.zymarg-commission-type', function () {
			var $card = $(this).closest('.zymarg-vendor-card');
			toggleFields($card, $(this).val());
		});

		// Save button.
		$(document).off('click.zymargVendors', '.zymarg-vendor-card__save');
		$(document).on('click.zymargVendors', '.zymarg-vendor-card__save', function () {
			var $btn  = $(this);
			var $card = $btn.closest('.zymarg-vendor-card');
			var vendorId = $btn.data('vendor-id');

			var commissionType = $card.find('.zymarg-commission-type').val();
			var percentage     = $card.find('.zymarg-commission-percentage').val() || '0';
			var flatFee        = $card.find('.zymarg-commission-flat').val() || '0';
			var verificationLevel = $card.find('.zymarg-verification-level').val() || '';
			var verificationNote  = $card.find('.zymarg-verification-note').val() || '';

			// Collect EVERY badge box, not just the ticked ones.
			// An unticked checkbox submits nothing by default, so if we only sent
			// the ticked boxes the server could not tell "revoke this badge" apart
			// from "this field was not on the form", and a granted badge could
			// never be taken away. Sending '1'/'0' for all keys makes it explicit.
			var badges = {};
			$card.find('.zymarg-store-badge').each(function () {
				var key = $(this).data('badge');
				if (key) {
					badges[key] = $(this).is(':checked') ? '1' : '0';
				}
			});

			$btn.prop('disabled', true).text('Saving...');

			$.ajax({
				url: ZymargVendors.ajaxUrl,
				type: 'POST',
				data: {
					action: 'zymarg_vd_save_vendor_commission',
					nonce: ZymargVendors.nonce,
					vendor_id: vendorId,
					commission_type: commissionType,
					percentage: percentage,
					flat_fee: flatFee,
					verification_level: verificationLevel,
					verification_note: verificationNote,
					badges: badges
				},
				success: function (response) {
					if (response.success) {
						showFeedback($card, response.data.message, false);
					} else {
						showFeedback($card, response.data.message || 'Error saving.', true);
					}
				},
				error: function () {
					showFeedback($card, 'Network error. Please try again.', true);
				},
				complete: function () {
					$btn.prop('disabled', false).text('Save');
				}
			});
		});

		// Seller search.
		$(document).off('input.zymargVendors', '#zvd-vendor-search');
		$(document).on('input.zymargVendors', '#zvd-vendor-search', function () {
			var term = $(this).val() || '';

			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				currentSearch = term;
				fetchVendors(term, 1, false);
			}, SEARCH_DEBOUNCE);
		});

		// Enter must not submit anything, it should just search immediately.
		$(document).off('keydown.zymargVendors', '#zvd-vendor-search');
		$(document).on('keydown.zymargVendors', '#zvd-vendor-search', function (e) {
			if (e.key !== 'Enter') {
				return;
			}

			e.preventDefault();
			clearTimeout(searchTimer);
			currentSearch = $(this).val() || '';
			fetchVendors(currentSearch, 1, false);
		});

		// Load more.
		$(document).off('click.zymargVendors', '#zvd-vendor-load-more');
		$(document).on('click.zymargVendors', '#zvd-vendor-load-more', function () {
			var page = parseInt($(this).attr('data-page'), 10) || 1;

			fetchVendors(currentSearch, page + 1, true);
		});
	}

	// Initialize on DOM ready.
	$(document).ready(init);

	// Re-initialize after AJAX section swap (SPA nav).
	$(document).on('zymarg-vd:section-loaded', init);

})(jQuery);
