/**
 * ZYMARG Single Product — Native swatch interaction v1.1.1
 *
 * Handles visual selection, keyboard (roving tabindex radiogroup), clearing,
 * and availability (out-of-stock) state for the server-rendered swatches from
 * ZymargSP\Swatches\Renderer. Selection is written to the hidden native
 * <select>, so wc-add-to-cart-variation.js keeps driving variations, gallery,
 * price, and ATC. This file never touches gallery or price directly.
 */
(function ($) {
	'use strict';

	function forms() {
		return $('.variations_form');
	}

	function selectForWrap($wrap) {
		return $wrap.find('.zymarg-sp-select-hidden select').first();
	}

	function currentSelections($form) {
		const sel = {};
		$form.find('select[name^="attribute_"]').each(function () {
			sel[this.name] = $(this).val() || '';
		});
		return sel;
	}

	function isValueAvailable(variations, name, value, selections) {
		for (let i = 0; i < variations.length; i++) {
			const v = variations[i];
			if (!v.is_in_stock || !v.is_purchasable) continue;
			const attrs = v.attributes || {};
			const av = attrs[name];
			if (av !== undefined && av !== '' && av !== value) continue;
			let ok = true;
			for (const k in selections) {
				if (k === name) continue;
				const s = selections[k];
				if (!s) continue;
				const va = attrs[k];
				if (va !== undefined && va !== '' && va !== s) { ok = false; break; }
			}
			if (ok) return true;
		}
		return false;
	}

	function updateAvailability($form) {
		const variations = $form.data('product_variations');
		// When WooCommerce loads variations via AJAX the data is `false`; in that
		// case we trust the server-rendered availability classes.
		if (!variations || !variations.length) return;
		const selections = currentSelections($form);
		$form.find('.zymarg-sp-swatch-wrap').each(function () {
			const $wrap = $(this);
			const name  = selectForWrap($wrap).attr('name');
			if (!name) return;
			$wrap.find('.zymarg-sp-swatch').each(function () {
				const $sw   = $(this);
				const val   = String($sw.data('value'));
				const avail = isValueAvailable(variations, name, val, selections);
				$sw.toggleClass('disabled', !avail);
				$sw.attr('aria-disabled', avail ? 'false' : 'true');
				if (!avail) $sw.attr('tabindex', '-1');
			});
		});
	}

	function scrollSwatchIntoView($wrap) {
		const $ul  = $wrap.find('.zymarg-sp-swatches').first();
		const $sel = $wrap.find('.zymarg-sp-swatch.selected').first();
		if (!$ul.length || !$sel.length) return;
		const ul = $ul[0];
		const el = $sel[0];
		const cRect = ul.getBoundingClientRect();
		const eRect = el.getBoundingClientRect();
		if (ul.scrollWidth > ul.clientWidth + 1) {
			const delta = (eRect.left - cRect.left) - (ul.clientWidth - eRect.width) / 2;
			ul.scrollTo({ left: ul.scrollLeft + delta, behavior: 'smooth' });
		}
		if (ul.scrollHeight > ul.clientHeight + 1) {
			const delta = (eRect.top - cRect.top) - (ul.clientHeight - eRect.height) / 2;
			ul.scrollTo({ top: ul.scrollTop + delta, behavior: 'smooth' });
		}
	}

	function reflectWrap($wrap) {
		const val = selectForWrap($wrap).val() || '';
		let selectedLabel = '';
		$wrap.find('.zymarg-sp-swatch').each(function () {
			const $sw   = $(this);
			const isSel = val !== '' && String($sw.data('value')) === val;
			$sw.toggleClass('selected', isSel);
			$sw.attr('aria-checked', isSel ? 'true' : 'false');
			$sw.attr('tabindex', isSel ? '0' : '-1');
			if (isSel) selectedLabel = $sw.data('label') || val;
		});
		if (!val) {
			const $first = $wrap.find('.zymarg-sp-swatch:not(.disabled)').first();
			if ($first.length) $first.attr('tabindex', '0');
		}
		$wrap.find('.zymarg-sp-swatch-selected-val').text(selectedLabel);
		scrollSwatchIntoView($wrap);
	}

	function reflectAll($form) {
		$form.find('.zymarg-sp-swatch-wrap').each(function () { reflectWrap($(this)); });
	}

	function selectSwatch($sw) {
		if ($sw.hasClass('disabled')) return;
		const $wrap = $sw.closest('.zymarg-sp-swatch-wrap');
		selectForWrap($wrap).val(String($sw.data('value'))).trigger('change');
		reflectWrap($wrap);
	}

	function clearWrap($wrap) {
		selectForWrap($wrap).val('').trigger('change');
		reflectWrap($wrap);
	}

	// v1.1.0 - click a selected swatch again to deselect (toggle).
	function toggleSwatch($sw) {
		if ($sw.hasClass('disabled')) return;
		if ($sw.hasClass('selected')) {
			clearWrap($sw.closest('.zymarg-sp-swatch-wrap'));
		} else {
			selectSwatch($sw);
		}
	}

	// v1.1.17 - selection follows focus (ARIA radiogroup behaviour).
	// Arrow keys used to move focus only, so the variation never changed until
	// Enter. We now commit the value as focus lands, debounced so holding an
	// arrow key does not fire a WooCommerce variation lookup per keystroke.
	let commitTimer = null;

	function commitFocused($sw, immediate) {
		if (commitTimer) { clearTimeout(commitTimer); commitTimer = null; }
		if (immediate) { selectSwatch($sw); return; }
		commitTimer = setTimeout(function () {
			commitTimer = null;
			// Only commit if the swatch still holds focus (user stopped moving).
			if ($sw.is(':focus')) selectSwatch($sw);
		}, 120);
	}

	function focusMove($wrap, $current, dir) {
		const $items = $wrap.find('.zymarg-sp-swatch:not(.disabled)');
		const idx = $items.index($current);
		if (idx === -1) return;
		let next = idx + dir;
		if (next < 0) next = $items.length - 1;
		if (next >= $items.length) next = 0;
		$items.attr('tabindex', '-1');
		const $next = $items.eq(next);
		$next.attr('tabindex', '0').trigger('focus');
		commitFocused($next, false);
	}

	// v1.1.17 - Home / End jump to the first / last selectable swatch.
	function focusEdge($wrap, last) {
		const $items = $wrap.find('.zymarg-sp-swatch:not(.disabled)');
		if (!$items.length) return;
		const $target = last ? $items.last() : $items.first();
		$items.attr('tabindex', '-1');
		$target.attr('tabindex', '0').trigger('focus');
		commitFocused($target, false);
	}

	function bindForm($form) {
		if ($form.data('zsp-swatch-bound')) return;
		$form.data('zsp-swatch-bound', true);

		$form.on('click', '.zymarg-sp-swatch', function (e) {
			e.preventDefault();
			toggleSwatch($(this));
		});

		$form.on('keydown', '.zymarg-sp-swatch', function (e) {
			const $wrap = $(this).closest('.zymarg-sp-swatch-wrap');
			switch (e.key) {
				case 'ArrowRight':
				case 'ArrowDown':
					e.preventDefault(); focusMove($wrap, $(this), 1); break;
				case 'ArrowLeft':
				case 'ArrowUp':
					e.preventDefault(); focusMove($wrap, $(this), -1); break;
				case 'Home':
					e.preventDefault(); focusEdge($wrap, false); break;
				case 'End':
					e.preventDefault(); focusEdge($wrap, true); break;
				case ' ':
				case 'Enter':
					// Enter / Space still toggles, so keyboard users can deselect.
					e.preventDefault(); toggleSwatch($(this)); break;
			}
		});

		// Mirror WooCommerce variation state into swatch UI.
		// v1.0.8 #1 — reflect swatch selection whenever WooCommerce settles on a
		// variation, including programmatic changes from gallery / thumbnail /
		// lightbox navigation. found_variation fires exactly when price + Add to
		// Cart update, so the swatch highlight now switches simultaneously with them.
		$form.on('woocommerce_update_variation_values woocommerce_variation_has_changed check_variations found_variation hide_variation', function () {
			updateAvailability($form);
			reflectAll($form);
		});
		$form.on('reset_data', function () {
			updateAvailability($form);
			reflectAll($form);
		});

		updateAvailability($form);
		reflectAll($form);
	}

	$(function () {
		forms().each(function () { bindForm($(this)); });
		$(document.body).on('wc_variation_form', '.variations_form', function () {
			bindForm($(this));
		});
	});

})(jQuery);
