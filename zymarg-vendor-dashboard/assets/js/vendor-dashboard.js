/**
 * ZYMARG Vendor Dashboard — front-end behaviour.
 *
 * Lightweight, dependency-free:
 *   - Mobile sidebar open/close (hamburger + overlay + ESC).
 *   - Logout confirmation modal.
 *
 * @package ZYMARG_OS
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		/* ---- Timezone-aware greeting -----------------------------------
		 * Uses the vendor's chosen timezone (ZymargVendor.tz) via
		 * Intl.DateTimeFormat so the client hour matches what the server
		 * used to render the greeting => no visible flash on load.
		 * Silently no-ops if Intl is missing or the tz string is bad;
		 * the server-rendered greeting stays intact.
		 *
		 * Named + re-runnable so it re-applies after an SPA section swap
		 * (the greeting lives inside the swapped Dashboard content).
		 * -------------------------------------------------------------- */
		function initGreeting() {
			var el = document.querySelector('[data-zv-greeting]');
			if (!el || typeof window.ZymargVendor === 'undefined' || !ZymargVendor.greet) { return; }
			var h = -1;
			try {
				if (ZymargVendor.tz && typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
					var fmt = new Intl.DateTimeFormat('en-US', {
						timeZone: ZymargVendor.tz,
						hour12: false,
						hour: 'numeric'
					});
					var parsed = parseInt(fmt.format(new Date()), 10);
					if (!isNaN(parsed) && parsed >= 0 && parsed <= 23) {
						h = parsed;
					}
				}
			} catch (e) { /* invalid tz — leave server greeting */ }
			if (h < 0) { return; } // no reliable hour => trust the server render
			var word = h < 12 ? ZymargVendor.greet.morning
				: (h < 17 ? ZymargVendor.greet.afternoon : ZymargVendor.greet.evening);
			if (word && el.textContent !== word) {
				el.textContent = word;
			}
		}
		initGreeting();

		var sidebar = document.getElementById('zymarg-vendor-sidebar');
		var burger = document.getElementById('zymarg-vendor-hamburger');
		var overlay = document.getElementById('zymarg-vendor-overlay');

		function openSidebar() {
			if (!sidebar) { return; }
			sidebar.classList.add('is-open');
			if (overlay) { overlay.classList.add('is-open'); }
			if (burger) { burger.setAttribute('aria-expanded', 'true'); }
		}

		function closeSidebar() {
			if (!sidebar) { return; }
			sidebar.classList.remove('is-open');
			if (overlay) { overlay.classList.remove('is-open'); }
			if (burger) { burger.setAttribute('aria-expanded', 'false'); }
		}

		if (burger) {
			burger.addEventListener('click', function () {
				if (sidebar && sidebar.classList.contains('is-open')) {
					closeSidebar();
				} else {
					openSidebar();
				}
			});

			/* ---- Hamburger nudge (once per session) ---- */
			if (!sessionStorage.getItem('zvd_nudge_seen')) {
				burger.classList.add('zvd-nudge-active');
				burger.addEventListener('animationend', function onNudgeEnd() {
					burger.classList.remove('zvd-nudge-active');
					burger.removeEventListener('animationend', onNudgeEnd);
					try { sessionStorage.setItem('zvd_nudge_seen', '1'); } catch (e) { /* quota */ }
				});
				// Fallback: if animation doesn't fire (reduced-motion), clean up after 4s.
				setTimeout(function () {
					if (burger.classList.contains('zvd-nudge-active')) {
						burger.classList.remove('zvd-nudge-active');
						try { sessionStorage.setItem('zvd_nudge_seen', '1'); } catch (e) { /* quota */ }
					}
				}, 4000);
			}
		}
		if (overlay) {
			overlay.addEventListener('click', closeSidebar);
		}

		/* ---- Logout confirmation modal ------------------------------- */
		var modal = document.getElementById('zymarg-vendor-logout-modal');

		function openModal() {
			if (modal) { modal.hidden = false; }
		}
		function closeModal() {
			if (modal) { modal.hidden = true; }
		}

		document.querySelectorAll('[data-vendor-logout]').forEach(function (el) {
			el.addEventListener('click', function (e) {
				if (modal) {
					e.preventDefault();
					openModal();
				}
			});
		});
		document.querySelectorAll('[data-vendor-logout-close]').forEach(function (el) {
			el.addEventListener('click', closeModal);
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeSidebar();
				closeModal();
				closeAllProductMenus();
			}
		});

		/* ---- Orders tabs --------------------------------------------- */
		document.addEventListener('click', function (e) {
			var tab = e.target.closest ? e.target.closest('.zymarg-vo-tab') : null;
			if (!tab) { return; }
			var key = tab.getAttribute('data-votab');
			document.querySelectorAll('.zymarg-vo-tab').forEach(function (t) {
				t.classList.toggle('is-active', t === tab);
			});
			document.querySelectorAll('.zymarg-vo-panel').forEach(function (p) {
				p.classList.toggle('is-active', p.id === 'zymarg-vo-' + key);
			});
		});

		/* ---- Product card "•••" menu --------------------------------- */
		function closeAllProductMenus() {
			document.querySelectorAll('.zymarg-vp-menu__list').forEach(function (l) { l.hidden = true; });
			document.querySelectorAll('.zymarg-vp-menu__btn').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
		}
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.zymarg-vp-menu__btn') : null;
			if (btn) {
				var list = btn.parentElement.querySelector('.zymarg-vp-menu__list');
				var willOpen = list && list.hidden;
				closeAllProductMenus();
				if (list && willOpen) { list.hidden = false; btn.setAttribute('aria-expanded', 'true'); }
				return;
			}
			if (!e.target.closest || !e.target.closest('.zymarg-vp-menu__list')) {
				closeAllProductMenus();
			}
		});

		/* ---- Product actions (feature / hide / duplicate / delete) --- */
		var V = window.ZymargVendor || {};
		document.addEventListener('click', function (e) {
			var item = e.target.closest ? e.target.closest('[data-vp-action]') : null;
			if (!item) { return; }
			e.preventDefault();
			var action = item.getAttribute('data-vp-action');
			var card = item.closest('.zymarg-vp-card');
			if (!card || !V.ajaxUrl) { return; }
			var id = card.getAttribute('data-product');

			if (action === 'delete' && !window.confirm((V.i18n && V.i18n.confirmDelete) || 'Delete this product?')) {
				return;
			}

			closeAllProductMenus();
			card.classList.add('is-busy');

			var fd = new FormData();
			fd.append('action', 'zymarg_vendor_product_action');
			fd.append('do', action);
			fd.append('product', id);
			fd.append('nonce', V.nonce || '');

			fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					card.classList.remove('is-busy');
					if (!res || !res.success) {
						window.alert((res && res.data && res.data.message) || (V.i18n && V.i18n.error) || 'Error');
						return;
					}
					var d = res.data || {};
					if (d.deleted) {
						card.style.transition = 'opacity .25s ease, transform .25s ease';
						card.style.opacity = '0';
						card.style.transform = 'scale(.96)';
						setTimeout(function () { card.remove(); }, 260);
						return;
					}
					if (d.reload) { window.location.reload(); return; }
					if (typeof d.featured !== 'undefined') {
						card.setAttribute('data-featured', d.featured ? '1' : '0');
						var star = card.querySelector('.zymarg-vp-feature');
						if (star) { star.classList.toggle('is-on', !!d.featured); }
						relabel(card, 'feature', 'unfeature', d.featured);
					}
					if (typeof d.hidden !== 'undefined') {
						card.setAttribute('data-hidden', d.hidden ? '1' : '0');
						relabel(card, 'hide', 'show', d.hidden);
					}
				})
				.catch(function () {
					card.classList.remove('is-busy');
					window.alert((V.i18n && V.i18n.error) || 'Error');
				});
		});

		/* ---- Promotions: create coupon ------------------------------
		 * Named + re-runnable for SPA. The form lives inside the swapped
		 * Promotions content, so it must be (re)bound after each section
		 * load. Elements are fresh after a swap, so no double-bind.
		 * ------------------------------------------------------------- */
		function initCouponForm() {
			var couponForm = document.getElementById('zymarg-vc-form');
			if (!couponForm) { return; }
			couponForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = couponForm.querySelector('.zymarg-vc-msg');
				var fd = new FormData(couponForm);
				fd.append('action', 'zymarg_vendor_create_coupon');
				fd.append('nonce', (window.ZymargVendor || {}).nonce || '');
				var submit = couponForm.querySelector('.zymarg-vc-submit');
				if (submit) { submit.disabled = true; }
				fetch((window.ZymargVendor || {}).ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (submit) { submit.disabled = false; }
						if (msg) {
							msg.hidden = false;
							msg.textContent = (res && res.data && res.data.message) || '';
							msg.className = 'zymarg-vc-msg ' + (res && res.success ? 'is-ok' : 'is-err');
						}
						if (res && res.success) { setTimeout(function () { window.location.reload(); }, 700); }
					})
					.catch(function () { if (submit) { submit.disabled = false; } });
			});
		}
		initCouponForm();

		/* ---- Promotions: delete coupon ------------------------------ */
		document.addEventListener('click', function (e) {
			var del = e.target.closest ? e.target.closest('[data-vc-delete]') : null;
			if (!del) { return; }
			var item = del.closest('.zymarg-vc-item');
			if (!item || !window.confirm('Delete this coupon?')) { return; }
			var fd = new FormData();
			fd.append('action', 'zymarg_vendor_delete_coupon');
			fd.append('coupon', item.getAttribute('data-coupon'));
			fd.append('nonce', (window.ZymargVendor || {}).nonce || '');
			item.style.opacity = '.5';
			fetch((window.ZymargVendor || {}).ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) { item.remove(); }
					else { item.style.opacity = '1'; }
				})
				.catch(function () { item.style.opacity = '1'; });
		});

		/* ---- Reviews: rating filter --------------------------------- */
		document.addEventListener('click', function (e) {
			var f = e.target.closest ? e.target.closest('[data-vr-filter]') : null;
			if (!f) { return; }
			var val = f.getAttribute('data-vr-filter');
			document.querySelectorAll('.zymarg-vr-filter').forEach(function (b) { b.classList.toggle('is-active', b === f); });
			document.querySelectorAll('.zymarg-vr-card').forEach(function (c) {
				var show = (val === 'all') || (c.getAttribute('data-rating') === val);
				c.style.display = show ? '' : 'none';
			});
		});

		/* ---- Reviews: actions (reply / hide / show / report) -------- */
		document.addEventListener('click', function (e) {
			var act = e.target.closest ? e.target.closest('[data-vr-action]') : null;
			if (act) {
				var card = act.closest('.zymarg-vr-card');
				var which = act.getAttribute('data-vr-action');
				if (which === 'reply') {
					var box = card.querySelector('.zymarg-vr-reply');
					if (box) { box.hidden = !box.hidden; }
					return;
				}
				vendorReviewRequest(card, which, '', act);
				return;
			}
			var send = e.target.closest ? e.target.closest('[data-vr-reply-send]') : null;
			if (send) {
				var rcard = send.closest('.zymarg-vr-card');
				var ta = rcard.querySelector('.zymarg-vr-reply__text');
				var text = ta ? ta.value.trim() : '';
				if (!text) { return; }
				vendorReviewRequest(rcard, 'reply', text, send);
			}
		});

		function vendorReviewRequest(card, action, text, btn) {
			var V = window.ZymargVendor || {};
			if (!card || !V.ajaxUrl) { return; }
			var fd = new FormData();
			fd.append('action', 'zymarg_vendor_review_action');
			fd.append('do', action);
			fd.append('review', card.getAttribute('data-review'));
			fd.append('text', text || '');
			fd.append('nonce', V.nonce || '');
			if (btn) { btn.disabled = true; }
			fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (btn) { btn.disabled = false; }
					if (!res || !res.success) {
						window.alert((res && res.data && res.data.message) || (V.i18n && V.i18n.error) || 'Error');
						return;
					}
					var d = res.data || {};
					if (d.replied) {
						var box = card.querySelector('.zymarg-vr-reply');
						if (box) { box.hidden = true; box.querySelector('.zymarg-vr-reply__text').value = ''; }
						window.alert(d.message || 'Reply posted.');
					}
					if (typeof d.hidden !== 'undefined') {
						card.classList.toggle('is-hidden', d.hidden);
						var t = card.querySelector('[data-vr-action="hide"], [data-vr-action="show"]');
						if (t) {
							t.setAttribute('data-vr-action', d.hidden ? 'show' : 'hide');
							t.textContent = d.hidden ? 'Unhide' : 'Hide';
						}
					}
					if (d.reported) {
						var rep = card.querySelector('[data-vr-action="report"]');
						if (rep) { rep.classList.add('is-reported'); rep.textContent = 'Reported'; }
					}
				})
				.catch(function () { if (btn) { btn.disabled = false; } });
		}

		/* ---- Customers tabs ----------------------------------------- */
		document.addEventListener('click', function (e) {
			var tab = e.target.closest ? e.target.closest('.zymarg-vcu-tab') : null;
			if (!tab) { return; }
			var key = tab.getAttribute('data-vcutab');
			document.querySelectorAll('.zymarg-vcu-tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
			document.querySelectorAll('.zymarg-vcu-panel').forEach(function (p) {
				p.classList.toggle('is-active', p.id === 'zymarg-vcu-' + key);
			});
		});

		/* ---- Messages: inbox (vendor + buyer) -----------------------
		 * Named + re-runnable for SPA (lives inside swapped Messages
		 * content). Fresh element after each swap => no double-bind.
		 * ------------------------------------------------------------- */
		function initMessages() {
			var V  = window.ZymargVendor || {};
			var vm = document.querySelector('.zymarg-vm');
			if (!vm) { return; }

			// Comm mode is now handled by inbox.js from the Communication plugin.
			// This always calls the legacy path; if Comm is active, VendorInbox
			// renders .zymarg-inbox (not .zymarg-vm), so initMessagesLegacy exits
			// harmlessly (no .zymarg-vm element found).
			initMessagesLegacy(vm, V);
		}

		// NOTE (Phase 6): initMessagesComm() removed — inbox.js (Comm plugin) owns this now.

		
		/* ── Legacy messages (no Communication plugin) ───────────────────── */
		function initMessagesLegacy(vm, V) {
			var threadAction = vm.getAttribute('data-msg-thread-action') || 'zymarg_vendor_msg_thread';
			var sendAction   = vm.getAttribute('data-msg-send-action')   || 'zymarg_vendor_msg_send';
			var peerParam    = vm.getAttribute('data-msg-peer-param')    || 'customer';
			var listEl   = vm.querySelector('.zymarg-vm__list');
			var emptyEl  = vm.querySelector('.zymarg-vm__empty');
			var headEl   = vm.querySelector('.zymarg-vm__head');
			var titleEl  = vm.querySelector('.zymarg-vm__title');
			var msgsEl   = vm.querySelector('.zymarg-vm__messages');
			var composer = vm.querySelector('.zymarg-vm__composer');
			var input    = vm.querySelector('.zymarg-vm__input');
			var current  = 0;

			function openThread(conv) {
				if (!conv) { return; }
				current = conv.getAttribute('data-customer');
				vm.querySelectorAll('.zymarg-vm-conv').forEach(function (c) { c.classList.toggle('is-active', c === conv); });
				vm.classList.add('is-thread-open');
				if (emptyEl)  { emptyEl.hidden  = true; }
				if (headEl)   { headEl.hidden   = false; }
				if (msgsEl)   { msgsEl.hidden   = false; msgsEl.innerHTML = '<p class="zymarg-vm__hint">' + ((V.i18n && V.i18n.working) || 'Loading…') + '</p>'; }
				if (composer) { composer.hidden = false; }

				var fd = new FormData();
				fd.append('action', threadAction);
				fd.append(peerParam, current);
				fd.append('nonce', V.nonce || '');
				fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							if (titleEl) { titleEl.textContent = res.data.name || ''; }
							if (msgsEl)  { msgsEl.innerHTML = res.data.html || ''; msgsEl.scrollTop = msgsEl.scrollHeight; }
						}
					});
			}

			if (listEl) {
				listEl.addEventListener('click', function (e) {
					var conv = e.target.closest('.zymarg-vm-conv');
					if (conv) {
						// Phase 8: clear unread state immediately on open so the dot
						// and bold styling don't linger while the thread is active.
						conv.classList.remove('is-unread');
						var dot = conv.querySelector('.zymarg-vm-conv__unread-dot');
						if (dot) { dot.remove(); }
						openThread(conv);
					}
				});
			}

			var backBtn = vm.querySelector('.zymarg-vm__back');
			if (backBtn) { backBtn.addEventListener('click', function () { vm.classList.remove('is-thread-open'); }); }

			if (composer) {
				composer.addEventListener('submit', function (e) {
					e.preventDefault();
					var body = input ? input.value.trim() : '';
					if (!body || !current) { return; }
					var fd = new FormData();
					fd.append('action', sendAction);
					fd.append(peerParam, current);
					fd.append('body', body);
					fd.append('nonce', V.nonce || '');
					if (input) { input.value = ''; }
					fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (res && res.success && msgsEl) {
								var hint = msgsEl.querySelector('.zymarg-vm__hint');
								if (hint) { hint.remove(); }
								msgsEl.insertAdjacentHTML('beforeend', res.data.bubble || '');
								msgsEl.scrollTop = msgsEl.scrollHeight;
							} else if (res && res.data && res.data.message) {
								window.alert(res.data.message);
							}
						});
				});
			}

			var pre    = vm.getAttribute('data-preselect');
			var target = (pre && pre !== '0') ? vm.querySelector('.zymarg-vm-conv[data-customer="' + pre + '"]') : null;
			if (!target && window.innerWidth >= 760) { target = vm.querySelector('.zymarg-vm-conv'); }
			if (target) { openThread(target); }

			// ── Phase 8: Buyer inbox unread poll ─────────────────────────────
			// Only runs on the buyer-facing [zymarg_my_messages] shortcode
			// container (.zymarg-bm), not on the vendor legacy inbox.
			// Polls zymarg_buyer_msg_unread every 8 s — matching the vendor-side
			// cadence — and renders unread dots on conversation rows exactly like
			// conversation-list.js (Comm plugin) does on the vendor/buyer side.
			if (vm.classList.contains('zymarg-bm') && listEl && V.ajaxUrl && V.nonce) {
				(function startBuyerUnreadPoll() {
					function refreshBuyerUnreadBadges() {
						var fd = new FormData();
						fd.append('action', 'zymarg_buyer_msg_unread');
						fd.append('nonce', V.nonce);
						fetch(V.ajaxUrl, {
							method: 'POST',
							body: fd,
							credentials: 'same-origin'
						})
						.then(function (r) { return r.ok ? r.json() : null; })
						.then(function (res) {
							if (!res || !res.success || !res.data) { return; }
							var counts = res.data; // { "vendorId": unreadCount, ... }
							Object.keys(counts).forEach(function (vid) {
								var row = listEl.querySelector('.zymarg-vm-conv[data-customer="' + vid + '"]');
								if (!row) { return; }

								var hasUnread = counts[vid] > 0;
								// Don't mark the active (open) thread — user is reading it.
								var isActive  = row.classList.contains('is-active');

								row.classList.toggle('is-unread', hasUnread && !isActive);

								// Add or remove the unread dot.
								var dot = row.querySelector('.zymarg-vm-conv__unread-dot');
								if (hasUnread && !isActive) {
									if (!dot) {
										dot = document.createElement('span');
										dot.className = 'zymarg-vm-conv__unread-dot';
										dot.setAttribute('aria-label', 'Unread messages');
										// Prevent dot click from bubbling to the row click
										// handler and causing a double-open flash.
										dot.addEventListener('click', function (e) { e.stopPropagation(); });
										row.appendChild(dot);
									}
								} else if (dot) {
									dot.remove();
								}
							});
						})
						.catch(function () { /* network hiccup — next tick retries */ });
					}

					// Run once on load, then every 8 seconds.
					refreshBuyerUnreadBadges();
					setInterval(refreshBuyerUnreadBadges, 8000);
				})();
			}
		}

		initMessages();

		/* ---- Notifications: type filter ----------------------------- */
		document.addEventListener('click', function (e) {
			var f = e.target.closest ? e.target.closest('[data-vn-filter]') : null;
			if (!f) { return; }
			var val = f.getAttribute('data-vn-filter');
			document.querySelectorAll('.zymarg-vn-filter').forEach(function (b) { b.classList.toggle('is-active', b === f); });
			document.querySelectorAll('.zymarg-vn-item').forEach(function (it) {
				var show = (val === 'all') || (it.getAttribute('data-type') === val);
				it.style.display = show ? '' : 'none';
			});
		});

		/* ---- Contact seller (product pages) ------------------------- */
		document.addEventListener('click', function (e) {
			var t = e.target.closest ? e.target.closest('.zymarg-cs__btn[data-cs-toggle]') : null;
			if (!t) { return; }
			var box = t.closest('.zymarg-cs');
			var form = box ? box.querySelector('.zymarg-cs__form') : null;
			if (form) { form.hidden = !form.hidden; if (!form.hidden) { var ta = form.querySelector('textarea'); if (ta) { ta.focus(); } } }
		});
		document.addEventListener('submit', function (e) {
			var form = e.target.closest ? e.target.closest('.zymarg-cs__form') : null;
			if (!form) { return; }
			e.preventDefault();
			var box = form.closest('.zymarg-cs');
			var Vc = window.ZymargVendor || {};
			var ta = form.querySelector('textarea');
			var body = ta ? ta.value.trim() : '';
			var msg = form.querySelector('.zymarg-cs__msg');
			if (!body || !box || !Vc.ajaxUrl) { return; }
			var fd = new FormData();
			fd.append('action', 'zymarg_contact_seller_send');
			fd.append('vendor', box.getAttribute('data-vendor'));
			fd.append('body', body);
			fd.append('nonce', Vc.nonce || '');
			var btn = form.querySelector('button[type="submit"]');
			if (btn) { btn.disabled = true; }
			fetch(Vc.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (btn) { btn.disabled = false; }
					if (msg) {
						msg.hidden = false;
						msg.textContent = (res && res.data && res.data.message) || '';
						msg.className = 'zymarg-cs__msg ' + (res && res.success ? 'is-ok' : 'is-err');
					}
					if (res && res.success && ta) { ta.value = ''; }
				})
				.catch(function () { if (btn) { btn.disabled = false; } });
		});

		/* ---- Order actions (approve / ship / deliver / cancel) ------ */
		var pendingCancelRow = null;
		var pendingCancelOrderId = null;

		// Confirm modal helpers.
		function showConfirmModal(row, orderId) {
			pendingCancelRow = row;
			pendingCancelOrderId = orderId;
			var modal = document.getElementById('zymarg-confirm-modal');
			if (modal) { modal.removeAttribute('hidden'); }
		}
		function hideConfirmModal() {
			var modal = document.getElementById('zymarg-confirm-modal');
			if (modal) { modal.setAttribute('hidden', ''); }
			pendingCancelRow = null;
			pendingCancelOrderId = null;
		}

		// Close confirm modal on overlay / cancel button click.
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-confirm-close]')) {
				hideConfirmModal();
			}
		});

		// Confirm modal "Yes, cancel order" button.
		var confirmYesBtn = document.getElementById('zymarg-confirm-yes');
		if (confirmYesBtn) {
			confirmYesBtn.addEventListener('click', function () {
				if (!pendingCancelRow || !pendingCancelOrderId) { hideConfirmModal(); return; }
				hideConfirmModal();
				executeOrderAction('cancel', pendingCancelOrderId, pendingCancelRow);
			});
		}

		function executeOrderAction(action, orderId, row) {
			row.classList.add('is-busy');

			var fd = new FormData();
			fd.append('action', 'zymarg_vendor_order_action');
			fd.append('do', action);
			fd.append('order', orderId);
			fd.append('nonce', V.nonce || '');

			fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					row.classList.remove('is-busy');
					if (!res || !res.success) {
						window.alert((res && res.data && res.data.message) || (V.i18n && V.i18n.error) || 'Error');
						return;
					}
					// Animate row out.
					row.style.transition = 'opacity .25s ease, transform .25s ease';
					row.style.opacity = '0';
					row.style.transform = 'scale(.96)';
					setTimeout(function () { row.remove(); }, 260);

					// Update tab counts.
					var d = res.data || {};
					var statusTabMap = { processing: 'processing', shipped: 'shipped', completed: 'delivered', cancelled: 'cancelled' };
					var targetTab = statusTabMap[d.new_status] || '';

					// Decrement current tab count.
					var activeTab = document.querySelector('.zymarg-vo-tab.is-active');
					if (activeTab) {
						var cur = parseInt(activeTab.dataset.count, 10) || 0;
						activeTab.dataset.count = Math.max(0, cur - 1);
					}

					// Increment target tab count.
					if (targetTab) {
						var target = document.querySelector('.zymarg-vo-tab[data-votab="' + targetTab + '"]');
						if (target) {
							var tc = parseInt(target.dataset.count, 10) || 0;
							target.dataset.count = tc + 1;
						}
					}

					// Toast message.
					var toastMsgs = {
						approve: (V.i18n && V.i18n.orderApproved) || 'Order approved.',
						ship: (V.i18n && V.i18n.orderShipped) || 'Order marked as shipped.',
						deliver: (V.i18n && V.i18n.orderDelivered) || 'Order marked as delivered.',
						cancel: (V.i18n && V.i18n.orderCancelled) || 'Order cancelled.'
					};
					var existing = document.querySelector('.zymarg-vo-toast');
					if (existing) { existing.remove(); }
					var toast = document.createElement('div');
					toast.className = 'zymarg-vo-toast';
					toast.textContent = toastMsgs[action] || 'Done.';
					document.body.appendChild(toast);
					setTimeout(function () { if (toast.parentNode) { toast.remove(); } }, 2500);
				})
				.catch(function () {
					row.classList.remove('is-busy');
					window.alert((V.i18n && V.i18n.error) || 'Error');
				});
		}

		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.zymarg-vo-action') : null;
			if (!btn) { return; }
			e.preventDefault();
			var action = btn.getAttribute('data-vo-action');
			var orderId = btn.getAttribute('data-order');
			var row = btn.closest('.zymarg-vo-order');
			if (!action || !orderId || !row || !V.ajaxUrl) { return; }

			if (action === 'cancel') {
				showConfirmModal(row, orderId);
				return;
			}

			executeOrderAction(action, orderId, row);
		});

		// Flip an action menu item between its two states after a toggle.
		function relabel(card, onAction, offAction, isOn) {
			var labels = {
				feature: 'Feature', unfeature: 'Unfeature',
				hide: 'Hide', show: 'Unhide'
			};
			var sel = isOn ? '[data-vp-action="' + onAction + '"]' : '[data-vp-action="' + offAction + '"]';
			var btn = card.querySelector(sel);
			if (!btn) { return; }
			if (isOn) {
				btn.setAttribute('data-vp-action', offAction);
				btn.textContent = labels[offAction];
			} else {
				btn.setAttribute('data-vp-action', onAction);
				btn.textContent = labels[onAction];
			}
		}

		/* ---- Support: reveal the inline inbox --------------------------
		 * Moved here in v1.46.9. This lived in a <script id="zvd-support-js">
		 * inlined into the Support section's own markup, which meant it ran
		 * on a full page load but NEVER after an SPA swap: navTo() injects
		 * sections with `c.innerHTML = html`, and innerHTML does not execute
		 * <script> tags. So clicking "Contact Support" did nothing whenever
		 * the vendor reached Support from the sidebar (the normal path) —
		 * it only worked if they hard-reloaded on ?vsection=support.
		 *
		 * Living in this file, it is re-bound by the section-loaded hook
		 * below like every other direct-bound handler.
		 *
		 * The tile is also what the Communication plugin's support.js
		 * hydrates (via data-zymarg-support-start) to create/fetch the
		 * thread. Both handlers are additive: that one opens the
		 * conversation, this one un-hides the card holding it.
		 * -------------------------------------------------------------- */
		function initSupport() {
			var btn = document.querySelector('.zvd-support-tile[data-zymarg-support-start][data-inline-target="#zymarg-vd-support-inbox"]');
			if (!btn || btn.getAttribute('data-zvd-support-bound') === '1') { return; }
			var target = document.querySelector('#zymarg-vd-support-inbox');
			if (!target) { return; }
			// Guard against double-binding if this ever runs twice for one node.
			btn.setAttribute('data-zvd-support-bound', '1');
			btn.addEventListener('click', function () {
				target.hidden = false;
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		}
		initSupport();

		/* ---- SPA re-init ---------------------------------------------
		 * After the SPA navigator swaps in a new section, re-bind the
		 * direct-bound (non-delegated) handlers that live INSIDE the
		 * swapped content. All the other handlers above are delegated on
		 * `document`, so they keep working across swaps without re-binding.
		 * ------------------------------------------------------------- */
		document.addEventListener('zymarg-vd:section-loaded', function () {
			initGreeting();
			initCouponForm();
			initMessages();
			initSupport();
		});
	});

	/* ================================================================
	 * SPA NAVIGATION
	 *
	 * Turns sidebar section clicks into in-place AJAX swaps instead of
	 * full-page reloads. Only links flagged data-spa="1" (native, in-shell
	 * sections) are intercepted; Dokan/external links navigate normally.
	 *
	 * On any failure it falls back to a normal navigation (progressive
	 * enhancement) — so if the endpoint, network or JS breaks, the vendor
	 * still gets where they're going.
	 *
	 * NOTE: Dokan Lite's requests.js wraps BOTH fetch() AND XMLHttpRequest,
	 * intercepting all outgoing requests and injecting its own auth logic
	 * (which causes 403 on our endpoint). We bypass this by creating an
	 * iframe-sourced XMLHttpRequest that Dokan's wrapper cannot see.
	 * ================================================================ */
	(function () {
		var V = window.ZymargVendor || {};
		var SPA = V.spa || {};
		if (!V.ajaxUrl || !V.nonce || !SPA.sections) { return; } // Not configured.

		/* ---- Iframe-sourced clean XHR (Dokan bypass) ---- */
		var spaFrame = document.createElement('iframe');
		spaFrame.style.display = 'none';
		spaFrame.src = 'about:blank';
		document.body.appendChild(spaFrame);
		var CleanXHR = spaFrame.contentWindow.XMLHttpRequest;

		function contentEl() { return document.querySelector('[data-zv-content]'); }

		function setActiveNav(key) {
			document.querySelectorAll('.zymarg-vendor-nav__link[data-section]').forEach(function (l) {
				l.classList.toggle('is-active', l.getAttribute('data-section') === key);
			});
		}

		function closeSidebar() {
			var sb = document.getElementById('zymarg-vendor-sidebar');
			var ov = document.getElementById('zymarg-vendor-overlay');
			var bg = document.getElementById('zymarg-vendor-hamburger');
			if (sb) { sb.classList.remove('is-open'); }
			if (ov) { ov.classList.remove('is-open'); }
			if (bg) { bg.setAttribute('aria-expanded', 'false'); }
		}

		/**
		 * Attempt to load a section via the iframe's clean XMLHttpRequest
		 * (bypasses Dokan's XHR/fetch wrapper). Posts to admin-ajax.php.
		 */
		function fetchViaCleanXHR(key) {
			return new Promise(function (resolve) {
				try {
					var xhr = new CleanXHR();
					xhr.open('POST', V.ajaxUrl, true);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onreadystatechange = function () {
						if (xhr.readyState !== 4) { return; }
						if (xhr.status === 200) {
							try {
								var res = JSON.parse(xhr.responseText);
								resolve((res && res.success && res.data) ? res.data.html : null);
							} catch (e) { resolve(null); }
						} else {
							resolve(null);
						}
					};
					xhr.onerror = function () { resolve(null); };
					xhr.send(
						'action=zymarg_vd_load_section' +
						'&section=' + encodeURIComponent(key) +
						'&nonce=' + encodeURIComponent(V.nonce)
					);
				} catch (e) {
					resolve(null);
				}
			});
		}

		/**
		 * Fallback: load a section via the WP REST API endpoint.
		 * Uses fetch (even if Dokan wraps it, the REST nonce is wp_rest
		 * and the endpoint is independent of admin-ajax.php).
		 */
		function fetchViaREST(key) {
			if (!V.restUrl || !V.restNonce) { return Promise.resolve(null); }
			return new Promise(function (resolve) {
				try {
					var xhr = new CleanXHR();
					xhr.open('POST', V.restUrl, true);
					xhr.setRequestHeader('Content-Type', 'application/json');
					xhr.setRequestHeader('X-WP-Nonce', V.restNonce);
					xhr.onreadystatechange = function () {
						if (xhr.readyState !== 4) { return; }
						if (xhr.status === 200) {
							try {
								var res = JSON.parse(xhr.responseText);
								resolve(res && res.html ? res.html : null);
							} catch (e) { resolve(null); }
						} else {
							resolve(null);
						}
					};
					xhr.onerror = function () { resolve(null); };
					xhr.send(JSON.stringify({ section: key }));
				} catch (e) {
					resolve(null);
				}
			});
		}

		/**
		 * Combined fetch: try clean XHR to admin-ajax first, then REST
		 * endpoint as fallback. Returns HTML string or null.
		 */
		function fetchSection(key) {
			return fetchViaCleanXHR(key).then(function (html) {
				if (html !== null) { return html; }
				return fetchViaREST(key);
			});
		}

		function navTo(key, url, push) {
			var c = contentEl();
			if (!c) { window.location.href = url; return; }

			c.classList.add('is-loading');
			if (SPA.loading) { c.innerHTML = SPA.loading; }

			fetchSection(key).then(function (html) {
				if (html === null || typeof html === 'undefined') {
					// Server declined / unexpected — fall back to a real load.
					window.location.href = url;
					return;
				}
				c.innerHTML = html;
				c.classList.remove('is-loading');
				setActiveNav(key);
				// Let section-scoped scripts (this file + addon files) re-bind.
				document.dispatchEvent(new CustomEvent('zymarg-vd:section-loaded', { detail: { section: key } }));
				// Also dispatch the unified event for inbox.js (Comm plugin) re-init on SPA swap.
				if (key === 'messages') {
					document.dispatchEvent(new CustomEvent('zymarg:section:swapped', { detail: { section: key } }));
				}
				window.scrollTo(0, 0);
				if (push && window.history && window.history.pushState) {
					window.history.pushState({ zvsec: key }, '', url);
				}
				if (window.innerWidth < 1024) { closeSidebar(); }
			}).catch(function () {
				window.location.href = url; // network / parse error — graceful fallback.
			});
		}

		// Intercept clicks on SPA-eligible sidebar links.
		// Uses CAPTURE phase (third arg = true) so we fire BEFORE any other
		// click handlers (e.g. Dokan's requests.js) that might navigate first.
		document.addEventListener('click', function (e) {
			var link = e.target.closest ? e.target.closest('a[data-spa="1"]') : null;
			if (!link) { return; }
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; } // allow open-in-new-tab
			var key = link.getAttribute('data-section');
			if (!key) { return; }
			e.preventDefault();
			e.stopImmediatePropagation(); // prevent Dokan or other handlers from navigating
			navTo(key, link.href, true);
		}, true);

		// Back / forward buttons.
		window.addEventListener('popstate', function (ev) {
			var key = ev.state && ev.state.zvsec;
			if (key) { navTo(key, location.href, false); }
		});

		// Seed the initial history state so the first Back returns here cleanly.
		var initialActive = document.querySelector('.zymarg-vendor-nav__link.is-active[data-spa="1"]');
		if (initialActive && window.history && window.history.replaceState) {
			window.history.replaceState({ zvsec: initialActive.getAttribute('data-section') }, '', location.href);
		}
	})();

	/* ================================================================
	 * ANNOUNCEMENT MARK-AS-READ (delegated, SPA-safe)
	 * ================================================================ */
	(function () {
		var V = window.ZymargVendor || {};
		if (!V.ajaxUrl || !V.nonce) { return; }

		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.zymarg-announcement-mark-read') : null;
			if (!btn) { return; }
			e.preventDefault();

			var postId = btn.getAttribute('data-announce-id');
			if (!postId) { return; }

			btn.disabled = true;
			btn.textContent = V.i18n && V.i18n.working ? V.i18n.working : 'Working...';

			var fd = new FormData();
			fd.append('action', 'zymarg_vd_mark_announcement_read');
			fd.append('nonce', V.nonce);
			fd.append('post_id', postId);

			fetch(V.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						var card = btn.closest('.zymarg-announcement-card');
						if (card) {
							card.classList.remove('is-unread');
							var badge = card.querySelector('.zymarg-announcement-card__badge');
							if (badge) { badge.remove(); }
						}
						btn.remove();
						// Remove nav dot if no more unread.
						var remaining = document.querySelectorAll('.zymarg-announcement-card.is-unread');
						if (remaining.length === 0) {
							var dot = document.querySelector('.zymarg-vendor-nav__dot');
							if (dot) { dot.remove(); }
						}
					} else {
						btn.disabled = false;
						btn.textContent = 'Mark as read';
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = 'Mark as read';
				});
		});
	})();
})();
