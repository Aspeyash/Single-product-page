/**
 * ZYMARG Vendor Dashboard -- Premium section (vendor side).
 *
 * Turning a functionality on sends a request; turning it off withdraws it.
 * Once approved, the vendor searches their catalogue and picks products.
 *
 * THE TRAY IS THE SELECTION
 * -------------------------
 * Chosen products live in a tray above the search results and are never
 * filtered away by a search. The tray is what gets saved, so searching can
 * never cause an unrelated product to be dropped -- the failure mode of a
 * picker that saves "whatever is currently on screen".
 *
 * Dependency-free on purpose. The vendor shell (vendor-dashboard.js) and
 * every other vendor-facing script in this plugin are vanilla JS, so this
 * file must not assume jQuery exists on the front end.
 *
 * Handlers are delegated from `document` and bound exactly once, so the
 * screen survives the dashboard's SPA section swaps without stacking
 * duplicate listeners.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function () {
	'use strict';

	var bound = false;
	var SEARCH_DEBOUNCE = 300;

	function cfg() {
		return window.ZymargPremiumVendor || { ajaxUrl: '', nonce: '', i18n: {} };
	}

	function t(key, fallback) {
		var i18n = cfg().i18n || {};
		return i18n[key] || fallback;
	}

	/**
	 * Nearest ancestor matching a selector, inclusive of the element itself.
	 *
	 * @param {Element} el       Starting element.
	 * @param {string}  selector CSS selector.
	 * @return {Element|null} Match or null.
	 */
	function closest(el, selector) {
		if (!el || !el.closest) {
			return null;
		}
		return el.closest(selector);
	}

	/**
	 * Status message via state classes only -- never inline colour.
	 *
	 * @param {Element} el      Status element.
	 * @param {string}  message Text.
	 * @param {string}  state   'ok', 'err', or '' while working.
	 */
	function status(el, message, state) {
		if (!el) {
			return;
		}
		el.classList.remove('zvd-status-msg--ok', 'zvd-status-msg--err');
		el.textContent = message;
		if (state === 'ok') {
			el.classList.add('zvd-status-msg--ok');
		} else if (state === 'err') {
			el.classList.add('zvd-status-msg--err');
		}
	}

	/**
	 * POST to admin-ajax and hand back the parsed response.
	 *
	 * @param {URLSearchParams} body Form body.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function post(body) {
		return window.fetch(cfg().ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	/**
	 * Pull a human message out of a failed response.
	 *
	 * @param {Object} response Parsed response.
	 * @return {string} Message.
	 */
	function errorMessage(response) {
		if (response && response.data && response.data.message) {
			return response.data.message;
		}
		return t('failed', 'That did not work. Try again.');
	}

	/* ------------------------------------------------------------------ *
	 * Approval request / withdraw
	 * ------------------------------------------------------------------ */

	/**
	 * Repaint a row for its new status.
	 *
	 * @param {Element} item    The row.
	 * @param {Object}  payload Server response data.
	 */
	function repaint(item, payload) {
		var chip = item.querySelector('.zvd-premium-item__chip');
		var help = item.querySelector('.zvd-premium-item__help');
		var btn = item.querySelector('.zvd-premium-request, .zvd-premium-off');

		if (chip && payload.chip) {
			chip.classList.remove('zvd-chip--success', 'zvd-chip--error');
			chip.textContent = payload.chip;
		}
		if (help && payload.help) {
			help.textContent = payload.help;
		}
		if (!btn) {
			return;
		}

		if (payload.status === 'pending') {
			btn.classList.remove('zvd-premium-request', 'zvd-btn--primary');
			btn.classList.add('zvd-premium-off', 'zvd-btn--secondary');
			btn.textContent = btn.getAttribute('data-cancel-label') || 'Cancel request';
		} else if (payload.status === 'off') {
			btn.classList.remove('zvd-premium-off', 'zvd-btn--secondary');
			btn.classList.add('zvd-premium-request', 'zvd-btn--primary');
			btn.textContent = btn.getAttribute('data-on-label') || 'Turn on';
		}
	}

	/**
	 * Show the "contact admin for approval" popup.
	 *
	 * @param {string} message Optional message from the server.
	 */
	function openApprovalModal(message) {
		var modal = document.getElementById('zvd-premium-modal');
		if (!modal) {
			return;
		}

		if (message) {
			var text = modal.querySelector('[data-premium-modal-text]');
			if (text) {
				text.textContent = message;
			}
		}

		modal.hidden = false;

		var focusTarget = modal.querySelector('.zvd-btn--primary');
		if (focusTarget) {
			focusTarget.focus();
		}
	}

	/**
	 * Hide the approval popup.
	 */
	function closeApprovalModal() {
		var modal = document.getElementById('zvd-premium-modal');
		if (modal) {
			modal.hidden = true;
		}
	}

	/**
	 * Send one vendor action (request / turn off).
	 *
	 * @param {Element} item   The row.
	 * @param {string}  action AJAX action name.
	 */
	function send(item, action) {
		var statusEl = item.querySelector('.zvd-premium-item__status');
		var buttons = item.querySelectorAll('button');
		var i;

		for (i = 0; i < buttons.length; i++) {
			buttons[i].disabled = true;
		}
		status(statusEl, t('working', 'Working'), '');

		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg().nonce);
		body.append('feature', item.getAttribute('data-feature') || '');

		post(body).then(function (response) {
			for (i = 0; i < buttons.length; i++) {
				buttons[i].disabled = false;
			}

			if (response && response.success) {
				var data = response.data || {};
				status(statusEl, data.message || '', 'ok');
				repaint(item, data);

				// Only on a fresh request -- not on turning something off.
				if (data.status === 'pending') {
					openApprovalModal(data.message);
				}
			} else {
				status(statusEl, errorMessage(response), 'err');
			}
		}).catch(function () {
			for (i = 0; i < buttons.length; i++) {
				buttons[i].disabled = false;
			}
			status(statusEl, t('network', 'Network error.'), 'err');
		});
	}

	/* ------------------------------------------------------------------ *
	 * Product picker
	 * ------------------------------------------------------------------ */

	function trayOf(wrap) {
		return wrap.querySelector('.zvd-premium-tray');
	}

	function resultsOf(wrap) {
		return wrap.querySelector('.zvd-premium-results');
	}

	/**
	 * The product IDs currently in the tray. This is the whole selection.
	 *
	 * @param {Element} wrap Picker wrapper.
	 * @return {Array<string>} Product IDs.
	 */
	function trayIds(wrap) {
		var rows = trayOf(wrap) ? trayOf(wrap).querySelectorAll('.zvd-premium-product') : [];
		var ids = [];

		for (var i = 0; i < rows.length; i++) {
			var id = rows[i].getAttribute('data-product');
			if (id) {
				ids.push(id);
			}
		}

		return ids;
	}

	function maxOf(wrap) {
		return parseInt(wrap.getAttribute('data-max'), 10) || 0;
	}

	function minOf(wrap) {
		return parseInt(wrap.getAttribute('data-min'), 10) || 0;
	}

	/**
	 * Refresh the counter, the empty note, and the at-capacity state.
	 *
	 * @param {Element} wrap Picker wrapper.
	 */
	function refresh(wrap) {
		var count = trayIds(wrap).length;
		var max = maxOf(wrap);
		var min = minOf(wrap);
		var countEl = wrap.querySelector('.zvd-premium-count__text');
		var emptyEl = wrap.querySelector('.zvd-premium-tray__empty');

		if (countEl) {
			var text = count + ' / ' + max + ' ' + t('chosen', 'chosen');
			if (min > 0 && count < min) {
				// %d is substituted here rather than built by string joining, so
				// a translation can put the number wherever its grammar needs it.
				text += ' \u2014 ' + t('needMin', 'pick at least %d to go live').replace('%d', min);
			}
			countEl.textContent = text;
		}

		if (emptyEl) {
			emptyEl.classList.toggle('zvd-is-hidden', count > 0);
		}

		// At capacity, stop offering more. Already-chosen rows stay enabled so
		// the vendor can always take something back out.
		var full = (max > 0 && count >= max);
		var results = resultsOf(wrap);
		if (results) {
			var picks = results.querySelectorAll('.zvd-premium-pick');
			for (var i = 0; i < picks.length; i++) {
				picks[i].disabled = full;
			}
		}
	}

	/**
	 * Move a row between the tray and the results list.
	 *
	 * The row element itself moves, so anything typed into a flash price or
	 * date field survives the move.
	 *
	 * @param {Element} checkbox The pick checkbox that changed.
	 */
	function togglePick(checkbox) {
		var wrap = closest(checkbox, '.zvd-premium-products');
		var row = closest(checkbox, '.zvd-premium-product');
		if (!wrap || !row) {
			return;
		}

		var tray = trayOf(wrap);
		var results = resultsOf(wrap);
		var statusEl = wrap.querySelector('.zvd-premium-products__status');

		if (checkbox.checked) {
			var max = maxOf(wrap);
			if (max > 0 && trayIds(wrap).length >= max) {
				// Refuse rather than silently trim on save.
				checkbox.checked = false;
				status(
					statusEl,
					t('atMax', 'That is the most you can choose. Remove one first.'),
					'err'
				);
				return;
			}
			if (tray) {
				tray.appendChild(row);
			}
		} else if (results) {
			// Put it back at the top of the results so it is easy to re-tick.
			results.insertBefore(row, results.firstChild);
		}

		refresh(wrap);
	}

	/**
	 * Run a catalogue search.
	 *
	 * @param {Element} wrap   Picker wrapper.
	 * @param {number}  page   Page number.
	 * @param {boolean} append Append to the list instead of replacing it.
	 */
	function search(wrap, page, append) {
		var results = resultsOf(wrap);
		var input = wrap.querySelector('.zvd-premium-search');
		var moreWrap = wrap.querySelector('.zvd-premium-results__more');
		var moreBtn = wrap.querySelector('.zvd-premium-load-more');
		var emptyEl = wrap.querySelector('.zvd-premium-results__empty');
		var statusEl = wrap.querySelector('.zvd-premium-products__status');

		if (!results) {
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'zymarg_vd_premium_search_products');
		body.append('nonce', cfg().nonce);
		body.append('feature', wrap.getAttribute('data-feature') || '');
		body.append('term', input ? input.value : '');
		body.append('page', String(page));

		// Never offer something that is already chosen.
		var chosen = trayIds(wrap);
		for (var i = 0; i < chosen.length; i++) {
			body.append('exclude[]', chosen[i]);
		}

		if (moreBtn) {
			moreBtn.disabled = true;
		}

		post(body).then(function (response) {
			if (moreBtn) {
				moreBtn.disabled = false;
			}

			if (!response || !response.success) {
				status(statusEl, errorMessage(response), 'err');
				return;
			}

			var data = response.data || {};

			if (append) {
				results.insertAdjacentHTML('beforeend', data.html || '');
			} else {
				results.innerHTML = data.html || '';
			}

			if (emptyEl) {
				var nothing = (!append && !data.count);
				emptyEl.classList.toggle('zvd-is-hidden', !nothing);
			}

			if (moreWrap) {
				moreWrap.classList.toggle('zvd-is-hidden', !data.hasMore);
			}
			if (moreBtn) {
				moreBtn.setAttribute('data-page', String(data.page || page));
			}

			refresh(wrap);
		}).catch(function () {
			if (moreBtn) {
				moreBtn.disabled = false;
			}
			status(statusEl, t('network', 'Network error.'), 'err');
		});
	}

	/**
	 * Save the Featured Items selection.
	 *
	 * @param {Element} btn The save button.
	 */
	function saveFeatured(btn) {
		var wrap = closest(btn, '.zvd-premium-products');
		if (!wrap) {
			return;
		}

		var statusEl = wrap.querySelector('.zvd-premium-products__status');
		var ids = trayIds(wrap);

		var body = new URLSearchParams();
		body.append('action', 'zymarg_vd_premium_save_featured');
		body.append('nonce', cfg().nonce);

		// The tray is the complete selection, so the server can safely diff
		// against stored state. An empty tray legitimately means "none".
		for (var i = 0; i < ids.length; i++) {
			body.append('products[]', ids[i]);
		}

		btn.disabled = true;
		status(statusEl, t('working', 'Working'), '');

		post(body).then(function (response) {
			btn.disabled = false;
			if (response && response.success) {
				status(statusEl, (response.data || {}).message || '', 'ok');
			} else {
				status(statusEl, errorMessage(response), 'err');
			}
		}).catch(function () {
			btn.disabled = false;
			status(statusEl, t('network', 'Network error.'), 'err');
		});
	}

	/**
	 * Save the Flash Sale rows.
	 *
	 * @param {Element} btn The save button.
	 */
	function saveFlash(btn) {
		var wrap = closest(btn, '.zvd-premium-products');
		if (!wrap) {
			return;
		}

		var tray = trayOf(wrap);
		var statusEl = wrap.querySelector('.zvd-premium-products__status');
		var rows = tray ? tray.querySelectorAll('.zvd-premium-product') : [];

		var body = new URLSearchParams();
		body.append('action', 'zymarg_vd_premium_save_flash');
		body.append('nonce', cfg().nonce);

		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var price = row.querySelector('.zvd-premium-flash-price');
			var start = row.querySelector('.zvd-premium-flash-start');
			var end = row.querySelector('.zvd-premium-flash-end');

			// Nested keys, so PHP parses this into $_POST['rows'][i]['product'].
			body.append('rows[' + i + '][product]', row.getAttribute('data-product') || '');
			body.append('rows[' + i + '][price]', price ? price.value : '');
			body.append('rows[' + i + '][start]', start ? start.value : '');
			body.append('rows[' + i + '][end]', end ? end.value : '');
		}

		btn.disabled = true;
		status(statusEl, t('working', 'Working'), '');

		post(body).then(function (response) {
			btn.disabled = false;
			if (response && response.success) {
				status(statusEl, (response.data || {}).message || '', 'ok');
			} else {
				// Validation problems must stay readable, not flash past.
				status(statusEl, errorMessage(response), 'err');
			}
		}).catch(function () {
			btn.disabled = false;
			status(statusEl, t('network', 'Network error.'), 'err');
		});
	}

	/* ------------------------------------------------------------------ *
	 * Wiring
	 * ------------------------------------------------------------------ */

	/**
	 * Refresh every picker on the page. Safe to call repeatedly.
	 */
	function refreshAll() {
		var wraps = document.querySelectorAll('.zvd-premium-products');
		for (var i = 0; i < wraps.length; i++) {
			refresh(wraps[i]);
		}
	}

	function init() {
		refreshAll();

		if (bound) {
			// Handlers are delegated from document, so they already cover any
			// markup swapped in later. Binding again would double every action.
			return;
		}
		bound = true;

		var searchTimer = null;

		document.addEventListener('click', function (event) {
			var target = event.target;

			var request = closest(target, '.zvd-premium-request');
			if (request) {
				send(closest(request, '.zvd-premium-item'), 'zymarg_vd_premium_vendor_request');
				return;
			}

			var off = closest(target, '.zvd-premium-off');
			if (off) {
				var item = closest(off, '.zvd-premium-item');
				// Only warn when something approved is about to be given up.
				if (item && item.querySelector('.zvd-chip--success')) {
					if (!window.confirm(t('confirmOff', 'Turn this off? You will need approval again to switch it back on.'))) {
						return;
					}
				}
				send(item, 'zymarg_vd_premium_vendor_off');
				return;
			}

			if (closest(target, '[data-premium-modal-close]')) {
				closeApprovalModal();
				return;
			}

			var more = closest(target, '.zvd-premium-load-more');
			if (more) {
				var wrapMore = closest(more, '.zvd-premium-products');
				var next = (parseInt(more.getAttribute('data-page'), 10) || 1) + 1;
				if (wrapMore) {
					search(wrapMore, next, true);
				}
				return;
			}

			var saveFeaturedBtn = closest(target, '.zvd-premium-save-featured');
			if (saveFeaturedBtn) {
				saveFeatured(saveFeaturedBtn);
				return;
			}

			var saveFlashBtn = closest(target, '.zvd-premium-save-flash');
			if (saveFlashBtn) {
				saveFlash(saveFlashBtn);
			}
		});

		// Picking a product moves its row between the results and the tray.
		document.addEventListener('change', function (event) {
			var pick = closest(event.target, '.zvd-premium-pick');
			if (pick) {
				togglePick(pick);
			}
		});

		// Debounced search: one request after typing stops, not one per key.
		document.addEventListener('input', function (event) {
			var input = closest(event.target, '.zvd-premium-search');
			if (!input) {
				return;
			}

			var wrap = closest(input, '.zvd-premium-products');
			if (!wrap) {
				return;
			}

			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(function () {
				search(wrap, 1, false);
			}, SEARCH_DEBOUNCE);
		});

		// Enter in the search box should search now, not submit anything.
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeApprovalModal();
				return;
			}

			if (event.key !== 'Enter') {
				return;
			}

			var input = closest(event.target, '.zvd-premium-search');
			if (!input) {
				return;
			}

			event.preventDefault();
			window.clearTimeout(searchTimer);

			var wrap = closest(input, '.zvd-premium-products');
			if (wrap) {
				search(wrap, 1, false);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// The dashboard swaps sections in without a page load; re-run so the
	// counters reflect markup that just arrived.
	document.addEventListener('zymarg-vd:section-loaded', init);
})();
