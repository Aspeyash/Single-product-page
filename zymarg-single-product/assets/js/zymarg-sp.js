/**
 * ZYMARG Single Product — Frontend JS v1.1.2
 * Gallery · Qty stepper (stock-aware) · Variation events · ATC (AJAX) · Buy Now · Lightbox · Toast · Sliders · Product video
 *
 * Visual swatch selection now lives in zymarg-sp-swatches.js and live price
 * updates live in zymarg-sp-price.js. This file no longer builds swatch UI;
 * it only mirrors WooCommerce variation events into gallery / cart / qty state.
 */
(function ($) {
	'use strict';

	const SP = window.zymargSP || {};

	/* ── Qty helpers (stock-aware) ───────────────────────────────────────── */

	function qtyInput() {
		return $('#zymarg-sp-qty');
	}

	function qtyBounds() {
		const $main = qtyInput();
		let min  = parseInt(SP.qty_min, 10) || 1;
		let max  = parseInt(SP.qty_max, 10) || 0;
		let step = 1;
		if ($main.length) {
			const am = parseInt($main.attr('min'), 10);
			const ax = parseInt($main.attr('max'), 10);
			const as = parseInt($main.attr('step'), 10);
			if (!isNaN(am)) min = Math.max(min, am);
			if (!isNaN(ax) && ax > 0) max = (max > 0) ? Math.min(max, ax) : ax;
			if (!isNaN(as) && as > 0) step = as;
		}
		if (min < 1) min = 1;
		return { min: min, max: max, step: step };
	}

	function getQty() {
		const $main = qtyInput();
		return $main.length ? Math.max(1, parseInt($main.val(), 10) || 1) : 1;
	}

	function setQty(val) {
		const b = qtyBounds();
		val = parseInt(val, 10);
		if (isNaN(val)) val = b.min;
		val = Math.max(b.min, val);
		if (b.max > 0) val = Math.min(val, b.max);
		$('#zymarg-sp-qty, #zymarg-sp-qty-sticky').val(val);
	}

	/**
	 * Show a toast message.
	 *
	 * v2.4.8 - this now delegates to the ZYMARG OS theme's shared toast system
	 * (`window.ZymargToast`), tagging every toast with the source
	 * "zymarg-single-product". That means these messages:
	 *
	 *   - look identical to every other toast on the site (one design language
	 *     instead of this plugin's own `#zymarg-sp-toast` bar);
	 *   - appear in the theme's Toast Notification activity log, so you can see
	 *     what fired and when;
	 *   - can be switched off from Theme -> Toast Notification without editing
	 *     any plugin code.
	 *
	 * The signature is unchanged, so all existing call sites keep working. Every
	 * current caller passes an error/failure message (the success toasts were
	 * intentionally removed in v2.4.4, and wishlist success is owned by the
	 * theme's own `zymarg_wcpg:wishlist:changed` listener), so the default type
	 * is "error". Pass `opts` to override.
	 *
	 * @param {string} msg    Message to display.
	 * @param {Object} [opts] Optional extras forwarded to the theme toast:
	 *                        {string} type     "error" (default), "success", "info".
	 *                        {string} title    Optional heading.
	 *                        {number} duration Milliseconds on screen.
	 *                        {Object} action   { label, url } for an action button.
	 */
	function showToast(msg, opts) {
		if (!msg) { return; }

		opts = opts || {};

		const api = window.ZymargToast;

		if (api && typeof api.show === 'function') {
			api.show($.extend({}, opts, {
				message: msg,
				type:    opts.type || 'error',
				source:  'zymarg-single-product'
			}));
			return;
		}

		// ---- Fallback: theme toast unavailable (a different theme is active) ----
		// Keeps this plugin fully self-sufficient on any theme, using the original
		// #zymarg-sp-toast bar and its CSS exactly as before.
		const $toast = $('#zymarg-sp-toast');
		$toast.html(msg).addClass('is-visible');
		clearTimeout($toast.data('timer'));
		$toast.data('timer', setTimeout(function () { $toast.removeClass('is-visible'); }, 4000));
	}

	function getVariationId() {
		const $form = $('.variations_form');
		if (!$form.length) return 0;
		const data = $form.data('zymarg_current_variation');
		return data ? (parseInt(data.variation_id, 10) || 0) : 0;
	}

	function getAttributes() {
		const $form = $('.variations_form');
		if (!$form.length) return {};
		const attrs = {};
		$form.find('select[name^="attribute_"]').each(function () {
			attrs[$(this).attr('name')] = $(this).val() || '';
		});
		return attrs;
	}

	/* ── Gallery ─────────────────────────────────────────────────────────── */

	let galleryImages = [];
	let currentIndex  = 0;
	let suppressClick = false;

	function mainImg() {
		return $('#zymarg-sp-main-img img');
	}

	function initGallery() {
		const $thumbs = $('.thumb');

		// Build image list from thumbs (includes variation feature images).
		galleryImages = [];
		$thumbs.each(function (i) {
			galleryImages.push({
				full: $(this).data('full') || mainImg().attr('src'),
				index: i,
			});
		});

		// Thumb click → select (delegated so it always binds).
		$(document).on('click', '.thumb', function (e) {
			e.preventDefault();
			userSwitch(parseInt($(this).data('index'), 10) || 0);
		});

		// Keyboard arrows on a focused thumb move selection + image together.
		$(document).on('keydown', '.thumb', function (e) {
			switch (e.key) {
				case 'ArrowRight':
				case 'ArrowDown':
					e.preventDefault(); userSwitch(currentIndex + 1, true); break;
				case 'ArrowLeft':
				case 'ArrowUp':
					e.preventDefault(); userSwitch(currentIndex - 1, true); break;
				case 'Enter':
				case ' ':
					e.preventDefault(); userSwitch(parseInt($(this).data('index'), 10) || 0, true); break;
			}
		});

		// Touch / swipe on the main image (mobile).
		initGallerySwipe();

		// Hover zoom.
		if (SP.gallery_zoom) {
			const $main = mainImg();
			$('.gallery-main').on('mousemove', function (e) {
				const rect = this.getBoundingClientRect();
				const x = ((e.clientX - rect.left) / rect.width  * 100).toFixed(1) + '%';
				const y = ((e.clientY - rect.top)  / rect.height * 100).toFixed(1) + '%';
				$main.css({ '--zoom-x': x, '--zoom-y': y });
			}).on('mouseenter', function () {
				$main.addClass('zymarg-sp-zoom-active');
			}).on('mouseleave', function () {
				$main.removeClass('zymarg-sp-zoom-active');
			});
		}

		// Lightbox click.
		if (SP.gallery_lightbox) {
			$('.gallery-main').on('click', function (e) {
				if (suppressClick) { suppressClick = false; return; }
				if (!$(e.target).closest('.gallery-wish').length && !$(e.target).closest('.zymarg-sp-video-trigger').length) {
					openLightbox(currentIndex);
				}
			}).addClass('zymarg-sp-zoom');
		}
	}

	function switchImage(idx, focusThumb) {
		if (!galleryImages.length) return;
		idx = (idx % galleryImages.length + galleryImages.length) % galleryImages.length;
		currentIndex = idx;

		mainImg().attr('src', galleryImages[idx].full).removeAttr('srcset').removeAttr('sizes');

		const $active = $('.thumb[data-index="' + idx + '"]');
		$('.thumb').removeClass('active').attr('aria-current', 'false');
		$active.addClass('active').attr('aria-current', 'true');

		scrollThumbIntoView($active);
		if (focusThumb && $active.length) $active.trigger('focus');

		const $counter = $('.zymarg-sp-gallery__counter [data-current]');
		if ($counter.length) $counter.text(idx + 1);
	}

	function switchImageBySrc(src) {
		if (!src) return false;
		const base = String(src).split('?')[0];
		const file = base.substring(base.lastIndexOf('/') + 1);
		for (let i = 0; i < galleryImages.length; i++) {
			const g = String(galleryImages[i].full).split('?')[0];
			if (g === base || (file && g.substring(g.lastIndexOf('/') + 1) === file)) {
				switchImage(i);
				return true;
			}
		}
		return false;
	}

	function scrollThumbIntoView($thumb) {
		if (!$thumb || !$thumb.length) return;
		const $rail = $('.gallery-thumbs');
		if (!$rail.length) return;
		const rail  = $rail[0];
		const el    = $thumb[0];
		const rRect = rail.getBoundingClientRect();
		const eRect = el.getBoundingClientRect();
		// Center the active thumb within the rail (vertical or horizontal).
		if (rail.scrollHeight > rail.clientHeight + 1) {
			const delta = (eRect.top - rRect.top) - (rail.clientHeight - eRect.height) / 2;
			rail.scrollTo({ top: rail.scrollTop + delta, behavior: 'smooth' });
		}
		if (rail.scrollWidth > rail.clientWidth + 1) {
			const delta = (eRect.left - rRect.left) - (rail.clientWidth - eRect.width) / 2;
			rail.scrollTo({ left: rail.scrollLeft + delta, behavior: 'smooth' });
		}
	}

	/* ── Gallery → variation sync ────────────────────────────────────────── */

	// User-initiated image change: switch + activate the matching variation.
	function userSwitch(idx, focusThumb) {
		switchImage(idx, focusThumb);
		const g = galleryImages[currentIndex];
		if (g) activateVariationForImage(g.full);
	}

	// Map a gallery image back to its variation and select the shared
	// attribute value(s) on the hidden selects. WooCommerce then cascades to
	// swatches, price, Add to Cart and Buy Now via the normal variation event.
	function activateVariationForImage(fullUrl) {
		const $form = $('.variations_form');
		if (!$form.length || !fullUrl) return;
		const variations = $form.data('product_variations');
		if (!variations || !variations.length) return;

		const base = String(fullUrl).split('?')[0];
		const file = base.substring(base.lastIndexOf('/') + 1);

		// Variations whose feature image matches this gallery image.
		const matches = [];
		for (let i = 0; i < variations.length; i++) {
			const img  = variations[i].image || {};
			const vsrc = String(img.full_src || img.src || '').split('?')[0];
			if (!vsrc) continue;
			const vfile = vsrc.substring(vsrc.lastIndexOf('/') + 1);
			if (vsrc === base || (file && vfile === file)) matches.push(variations[i]);
		}
		if (!matches.length) return;

		// Only attribute values shared by every matching variation (e.g. Color,
		// not Size), so the user's other selections are left untouched.
		const common = {};
		const first  = matches[0].attributes || {};
		for (const name in first) {
			const val = first[name];
			if (!val) continue;
			let allSame = true;
			for (let i = 1; i < matches.length; i++) {
				if ((matches[i].attributes || {})[name] !== val) { allSame = false; break; }
			}
			if (allSame) common[name] = val;
		}

		const changed = [];
		for (const name in common) {
			const sel = $form.find('select[name="' + name + '"]');
			if (sel.length && String(sel.val() || '') !== String(common[name])) {
				sel.val(common[name]);
				changed.push(sel[0]);
			}
		}
		if (changed.length) $(changed).trigger('change');
	}

	function initGallerySwipe() {
		const el = document.getElementById('zymarg-sp-main-img');
		if (!el) return;
		let startX = 0, startY = 0, tracking = false;
		el.addEventListener('touchstart', function (e) {
			if (!e.touches || !e.touches.length) return;
			startX   = e.touches[0].clientX;
			startY   = e.touches[0].clientY;
			tracking = true;
		}, { passive: true });
		el.addEventListener('touchend', function (e) {
			if (!tracking) return;
			tracking = false;
			const t = (e.changedTouches && e.changedTouches[0]) || null;
			if (!t) return;
			const dx = t.clientX - startX;
			const dy = t.clientY - startY;
			if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
				suppressClick = true;
				setTimeout(function () { suppressClick = false; }, 350);
				userSwitch(currentIndex + (dx < 0 ? 1 : -1));
			}
		}, { passive: true });
	}

	/* ── Lightbox ────────────────────────────────────────────────────────── */

	function openLightbox(idx) {
		const $lb = $('#zymarg-sp-lightbox');
		$lb.removeAttr('hidden');
		setLightboxImage(idx);
		$(document).on('keydown.zspLB', function (e) {
			if (e.key === 'Escape')   closeLightbox();
			if (e.key === 'ArrowLeft')  setLightboxImage(currentIndex - 1);
			if (e.key === 'ArrowRight') setLightboxImage(currentIndex + 1);
		});
	}

	function setLightboxImage(idx) {
		idx = (idx + galleryImages.length) % galleryImages.length;
		// v1.0.7 — keep the main gallery + swatches/price/cart in sync while
		// navigating inside the lightbox (same reverse-sync as thumb/swipe).
		userSwitch(idx);
		$('#zymarg-sp-lightbox-img').attr('src', galleryImages[idx].full);
	}

	function closeLightbox() {
		$('#zymarg-sp-lightbox').attr('hidden', true);
		$(document).off('keydown.zspLB');
	}

	$(document).on('click', '.zymarg-sp-lightbox__close', closeLightbox);
	$(document).on('click', '#zymarg-sp-lightbox', function (e) {
		if ($(e.target).is('#zymarg-sp-lightbox')) closeLightbox();
	});
	$(document).on('click', '.zymarg-sp-lightbox__nav--prev', function () { setLightboxImage(currentIndex - 1); });
	$(document).on('click', '.zymarg-sp-lightbox__nav--next', function () { setLightboxImage(currentIndex + 1); });

	/* ── Qty stepper ─────────────────────────────────────────────────────── */

	function initQtyStepper() {
		$(document).on('click', '.zymarg-sp-qty-btn--minus', function () {
			setQty(getQty() - qtyBounds().step);
		});
		$(document).on('click', '.zymarg-sp-qty-btn--plus', function () {
			setQty(getQty() + qtyBounds().step);
		});
		$(document).on('change input', '.zymarg-sp-qty-input', function () {
			setQty($(this).val());
		});
		// Sync main ↔ sticky.
		if (SP.qty_sync_sticky) {
			$(document).on('change.zspSync', '#zymarg-sp-qty', function () {
				$('#zymarg-sp-qty-sticky').val($(this).val());
			});
			$(document).on('change.zspSync', '#zymarg-sp-qty-sticky', function () {
				$('#zymarg-sp-qty').val($(this).val());
			});
		}
	}

	/* ── Variation events (swatch UI handled by zymarg-sp-swatches.js) ────── */

	function gatePurchaseControls(enabled) {
		$('.zymarg-sp-qty-btn').prop('disabled', !enabled);
		$('.zymarg-sp-qty-input').prop('disabled', !enabled);
		$('#zymarg-sp-qty-stepper, #zymarg-sp-qty-stepper-sticky').toggleClass('zsp-gated', !enabled);
		$('#zymarg-sp-atc-btn, .zymarg-sp-sticky-atc').prop('disabled', !enabled);
		$('#zymarg-sp-buy-btn, .zymarg-sp-sticky-buy').prop('disabled', !enabled);
	}

	function initSwatches() {
		const $form = $('.variations_form');
		if (!$form.length) return;

		$form.on('found_variation', function (e, variation) {
			// Store current variation for ATC/Buy Now.
			$form.data('zymarg_current_variation', variation);

			// Sync gallery + active thumbnail to the variation image.
			if (variation.image) {
				const vsrc = variation.image.full_src || variation.image.src;
				if (!switchImageBySrc(vsrc) && vsrc) {
					mainImg().attr('src', variation.image.src || vsrc).removeAttr('srcset').removeAttr('sizes');
				}
			}

			// Stock-aware quantity bounds.
			const $qty = $('#zymarg-sp-qty, #zymarg-sp-qty-sticky');
			if (variation.min_qty) { $qty.attr('min', variation.min_qty); }
			if (variation.max_qty) { $qty.attr('max', variation.max_qty); } else { $qty.removeAttr('max'); }

			// ATC button state + purchase gating (variable products, v1.1.0).
			const $atc = $('#zymarg-sp-atc-btn, .zymarg-sp-sticky-atc');
			if (variation.is_purchasable && variation.is_in_stock) {
				gatePurchaseControls(true);
				$atc.text(SP.atc_text);
			} else {
				gatePurchaseControls(false);
				$atc.prop('disabled', true).text(SP.i18n.sold_out || 'Sold Out');
			}

			// Price animation trigger (actual swap handled by zymarg-sp-price.js).
			const $price = $('.zymarg-sp-price-block');
			$price.removeClass('price-updated');
			void $price[0] && $price[0].offsetWidth;
			$price.addClass('price-updated');
		});

		$form.on('reset_data', function () {
			$form.data('zymarg_current_variation', null);
			gatePurchaseControls(false);
			$('#zymarg-sp-atc-btn, .zymarg-sp-sticky-atc').text(SP.atc_text);
		});

		// v1.1.0 - variable products start locked until an in-stock variation is chosen.
		gatePurchaseControls(false);
	}

	/* ── Add to Cart (AJAX) ──────────────────────────────────────────────── */

	function initATC() {
		$(document).on('click', '#zymarg-sp-atc-btn, .zymarg-sp-sticky-atc', function () {
			const $btn      = $(this);
			const productId = $btn.data('product-id') || SP.product_id;
			const qty       = getQty();
			const varId     = SP.is_variable ? getVariationId() : 0;
			const attrs     = SP.is_variable ? getAttributes()  : {};

			// v1.1.0 - controls are gated until a variation is chosen; guard silently (no toast).
			if (SP.is_variable && !varId) { return; }

			$btn.prop('disabled', true).text(SP.atc_text_loading || 'Adding…');

			$.ajax({
				// v1.0.11 #3 — WooCommerce's real AJAX add-to-cart is the WC-AJAX
				// endpoint (?wc-ajax=add_to_cart), NOT admin-ajax. The old call posted
				// to admin-ajax with action 'woocommerce_ajax_add_to_cart' (no such
				// handler exists), so every click failed with "something went wrong".
				// Mirror the WooSwatches plugin: hit wc_ajax_url with the native
				// 'woocommerce_add_to_cart' action and pass the variation id as
				// product_id so WC resolves the chosen variation server-side.
				url:    (SP.wc_ajax_url || '').replace('%%endpoint%%', 'add_to_cart') || SP.ajax_url,
				type:   'POST',
				data:   {
					action:        'woocommerce_add_to_cart',
					product_id:    varId ? varId : productId,
					variation_id:  varId,
					quantity:      qty,
					zymarg_atc:    1,
				},
				success: function (res) {
					if (res.error && res.product_url) {
						window.location = res.product_url;
						return;
					}
					// v2.4.4 - no button reference passed here: WooCommerce core's own
					// add-to-cart.js injects a "View cart" link next to whichever
					// button is passed as the 3rd arg. Omitting it (matching the
					// Product Grid plugin's own technique) suppresses that link.
					// The plugin's own "item added" toast is intentionally removed too.
					$(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash]);
					$btn.text(SP.atc_text_done || 'Added!');
					setTimeout(function () {
						$btn.prop('disabled', false).text(SP.atc_text || 'Add to Cart');
					}, 2000);
				},
				error: function () {
					$btn.prop('disabled', false).text(SP.atc_text || 'Add to Cart');
					showToast('Something went wrong. Please try again.');
				},
			});
		});
	}

	/* ── Buy Now ─────────────────────────────────────────────────────────── */

	function initBuyNow() {
		$(document).on('click', '#zymarg-sp-buy-btn, .zymarg-sp-sticky-buy', function () {
			const $btn      = $(this);
			const productId = $btn.data('product-id') || SP.product_id;
			const qty       = getQty();
			const varId     = SP.is_variable ? getVariationId() : 0;
			const attrs     = SP.is_variable ? getAttributes()  : {};

			// v1.1.0 - controls are gated until a variation is chosen; guard silently (no toast).
			if (SP.is_variable && !varId) { return; }

			$btn.prop('disabled', true).text('…');

			$.ajax({
				url:  SP.ajax_url,
				type: 'POST',
				data: {
					action:       SP.buy_now.action,
					nonce:        SP.buy_now.nonce,
					product_id:   productId,
					quantity:     qty,
					variation_id: varId,
					attributes:   attrs,
				},
				success: function (res) {
					if (res.success && res.data && res.data.checkout_url) {
						window.location.href = res.data.checkout_url;
					} else {
						$btn.prop('disabled', false).text(SP.i18n && SP.i18n.buynow_text ? SP.i18n.buynow_text : 'Buy Now');
						showToast((res.data && res.data.message) || 'Something went wrong.');
					}
				},
				error: function () {
					$btn.prop('disabled', false).text(SP.i18n && SP.i18n.buynow_text ? SP.i18n.buynow_text : 'Buy Now');
					showToast('Something went wrong. Please try again.');
				},
			});
		});
	}

	/* ── Slider arrows ───────────────────────────────────────────────────── */

	function initSliders() {
		$(document).on('click', '.zymarg-sp-arrow', function () {
			const target = $(this).data('target');
			const dir    = parseInt($(this).data('dir'), 10);
			const $slider = $('#' + target);
			if ($slider.length) {
				$slider[0].scrollBy({ left: dir * 480, behavior: 'smooth' });
			}
		});
	}

	/* ── Wishlist (v2.4.4 - real persistence via ZYMARG WC Product Grid's
	   Wishlist_Store, same pattern as the Product Grid card hearts:
	   optimistic toggle, AJAX persist, rollback on failure, toast text
	   taken from the AJAX response's own message) ──────────────────────── */

	function setWishlistState($btn, on) {
		$btn.toggleClass('is-wishlisted', on).attr('aria-pressed', on ? 'true' : 'false');
	}

	function initWishlist() {
		// Cache-safe hydration: reconcile the server-rendered state on load
		// (covers full-page-cached markup where aria-pressed may be stale).
		if (SP.wishlist && SP.wishlist.enabled) {
			$.post(SP.ajax_url, {
				action: SP.wishlist.hydrate_action,
				nonce:  SP.wishlist.nonce,
				product_id: SP.product_id,
			}).done(function (res) {
				if (res && res.success && res.data) {
					setWishlistState($('.zymarg-sp-wishlist-btn'), !!res.data.in_wishlist);
				}
			});
		}

		$(document).on('click', '.zymarg-sp-wishlist-btn', function (e) {
			e.stopPropagation();
			e.preventDefault();

			if (!SP.wishlist || !SP.wishlist.enabled) { return; }

			const $btn = $(this);
			if ($btn.data('busy')) { return; }

			const wasOn = $btn.hasClass('is-wishlisted');
			$btn.data('busy', true);
			setWishlistState($btn, !wasOn); // optimistic

			$.post(SP.ajax_url, {
				action:     SP.wishlist.toggle_action,
				nonce:      SP.wishlist.nonce,
				product_id: SP.product_id,
				op:         'toggle',
			}).done(function (res) {
				if (res && res.success && res.data) {
					const on = !!res.data.in_wishlist;
					setWishlistState($btn, on);

					/**
					 * v2.4.5 - broadcast the SAME event, on the SAME target,
					 * with the SAME payload shape as the ZYMARG WC Product Grid
					 * plugin's own card hearts (zymarg-wc-product-grid/assets/js/
					 * frontend.js). The theme already listens for this event and
					 * renders its own themed toast/card (checkmark, "View
					 * wishlist" link) - firing it here makes the single product
					 * page's wishlist toast look identical to the product grid's.
					 * No internal toast on success: the theme's listener owns
					 * the success feedback now, exactly like on the grid page.
					 */
					if (typeof res.data.count !== 'undefined') {
						$(document).trigger('zymarg_wcpg:wishlist:changed', [{
							count: res.data.count,
							product_id: SP.product_id,
							in_wishlist: on,
						}]);
					}

					/**
					 * Action: zymarg_sp_wishlist_toggle
					 * Fires when the wishlist button is toggled successfully.
					 * Hooked by wishlist plugins (e.g. YITH Wishlist, TI Wishlist).
					 */
					$(document.body).trigger('zymarg_sp_wishlist_toggle', {
						product_id: SP.product_id,
						added: on,
					});
				} else {
					setWishlistState($btn, wasOn); // rollback
					showToast((res && res.data && res.data.message) || 'Something went wrong.');
				}
			}).fail(function () {
				setWishlistState($btn, wasOn); // rollback
				showToast('Something went wrong. Please try again.');
			}).always(function () {
				$btn.data('busy', false);
			});
		});
	}

	/* ── Product video overlay ───────────────────────────────────────────── */

	function initProductVideo() {
		$(document).on('click', '.zymarg-sp-video-trigger', function (e) {
			e.preventDefault();
			e.stopPropagation();
			openVideoOverlay();
		});
		$(document).on('click', '.zymarg-sp-video-overlay__close', function () {
			closeVideoOverlay();
		});
		$(document).on('click', '.zymarg-sp-video-overlay', function (e) {
			if ($(e.target).is('.zymarg-sp-video-overlay')) closeVideoOverlay();
		});
		$(document).on('keydown.zspVideo', function (e) {
			if (e.key === 'Escape') closeVideoOverlay();
		});
	}

	function openVideoOverlay() {
		const $overlay = $('#zymarg-sp-video-overlay');
		if (!$overlay.length) return;
		const $mount = $overlay.find('.zymarg-sp-video-overlay__player');
		const type   = $overlay.data('video-type');
		const src    = String($overlay.data('video-embed') || '');
		if (!$mount.data('loaded') && src) {
			let markup;
			if (type === 'mp4') {
				markup = '<video src="' + src + '" controls autoplay playsinline style="width:100%;height:100%;"></video>';
			} else {
				markup = '<iframe src="' + src + (src.indexOf('?') > -1 ? '&' : '?') + 'autoplay=1" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen frameborder="0" style="width:100%;height:100%;"></iframe>';
			}
			$mount.html(markup).data('loaded', true);
		}
		$overlay.removeAttr('hidden').addClass('is-open');
	}

	function closeVideoOverlay() {
		const $overlay = $('#zymarg-sp-video-overlay');
		if (!$overlay.length) return;
		$overlay.attr('hidden', true).removeClass('is-open');
		// Stop playback by clearing the mount.
		$overlay.find('.zymarg-sp-video-overlay__player').empty().data('loaded', false);
	}

	/* ── Sticky bar visibility ───────────────────────────────────────────── */

	function initSticky() {
		const $sticky  = $('#zymarg-sp-sticky-bar');
		const $buyBox  = $('.buy-box');
		if (!$sticky.length || !$buyBox.length) return;

		function checkScroll() {
			const boxBottom = $buyBox.offset().top + $buyBox.outerHeight();
			$sticky.attr('aria-hidden', window.scrollY < boxBottom ? 'true' : 'false');
		}
		$(window).on('scroll.zspSticky', checkScroll);
		checkScroll();
	}

	/* ── Init ────────────────────────────────────────────────────────────── */

	$(function () {
		initGallery();
		initQtyStepper();
		initSwatches();
		initATC();
		initBuyNow();
		initSliders();
		initWishlist();
		initProductVideo();
		if (SP.sticky_enabled) initSticky();
	});

})(jQuery);

/* ── v2.3.0: the v1.1.2 mobile swatch mover was removed here ──
   Mobile block order (Gallery > Swatches > Price > Title > Rating) is now the
   built-in default and is expressed as CSS flex `order` inside
   assets/css/zymarg-sp.css @media (max-width: 640px). Doing it in CSS instead
   of JS removes the visible reorder flash this block used to cause on load,
   and leaves the DOM untouched so the variations form keeps its state. */


/* --------------------------------------------------------------------------
   v1.1.16 - Smooth accordion slide.
   <details> toggles instantly by design. We intercept the summary click,
   animate the element height with the Web Animations API, and only then let
   the open state settle. Falls back to native behaviour when the API is
   missing or the visitor prefers reduced motion.
   -------------------------------------------------------------------------- */
(function () {
	'use strict';

	var DURATION = 280;
	var EASING   = 'cubic-bezier(.4, 0, .2, 1)';

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function setup(details) {
		var summary = details.querySelector('summary');
		var body    = details.querySelector('.acc-body');

		if (!summary || !body || typeof details.animate !== 'function') {
			return;
		}
		if (details.hasAttribute('data-zsp-slide')) {
			return;
		}
		details.setAttribute('data-zsp-slide', '1');

		var animation = null;
		var opening   = false;
		var closing   = false;

		function finish(isOpen) {
			details.open = isOpen;
			animation    = null;
			opening      = false;
			closing      = false;
			details.style.height = '';
			details.removeAttribute('data-zsp-animating');
		}

		function run(startHeight, endHeight, willOpen) {
			if (animation) {
				animation.cancel();
			}
			opening = willOpen;
			closing = !willOpen;
			details.setAttribute('data-zsp-animating', '1');

			animation = details.animate(
				{ height: [startHeight + 'px', endHeight + 'px'] },
				{ duration: DURATION, easing: EASING }
			);

			body.animate(
				{ opacity: willOpen ? [0, 1] : [1, 0] },
				{ duration: willOpen ? DURATION : Math.round(DURATION * 0.6), easing: 'ease' }
			);

			animation.onfinish = function () { finish(willOpen); };
			animation.oncancel = function () { opening = false; closing = false; };
		}

		function expand() {
			var start = details.offsetHeight;
			details.style.height = start + 'px';
			details.open = true;
			window.requestAnimationFrame(function () {
				run(start, summary.offsetHeight + body.offsetHeight, true);
			});
		}

		function collapse() {
			run(details.offsetHeight, summary.offsetHeight, false);
		}

		summary.addEventListener('click', function (event) {
			if (prefersReducedMotion()) {
				return; // native instant toggle
			}
			event.preventDefault();

			if (closing || !details.open) {
				expand();
			} else if (opening || details.open) {
				collapse();
			}
		});
	}

	function init() {
		var list = document.querySelectorAll('details.acc');
		for (var i = 0; i < list.length; i++) {
			setup(list[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
