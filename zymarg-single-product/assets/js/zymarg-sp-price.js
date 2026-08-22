/**
 * ZYMARG Single Product — Live price updates v2.6.0
 *
 * For variable products, swaps the current price (inline), old price
 * (subscript) and the Smart Heading badge as the shopper selects a
 * variation, keeping the exact same markup the server renders (see
 * class-price-renderer.php). Simple products are fully server-rendered and
 * only the heading countdown ticker (if any) is bound here.
 */
(function ($) {
	'use strict';

	const SP      = window.zymargSP || {};
	const PRICE   = SP.price || {};
	const HEADING = SP.heading || {};

	function fmtNumber(n) {
		const dec = (typeof PRICE.decimals === 'number') ? PRICE.decimals : 2;
		n = parseFloat(n);
		if (isNaN(n)) n = 0;
		const parts = n.toFixed(dec).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, PRICE.thousand_sep || ',');
		return dec > 0 ? parts.join(PRICE.decimal_sep || '.') : parts[0];
	}

	function fmtPrice(n) {
		const num    = fmtNumber(n);
		const fmt    = PRICE.format || '%1$s%2$s';
		const symbol = '<span class="woocommerce-Price-currencySymbol">' + (PRICE.symbol || '') + '</span>';
		const inner  = fmt.replace('%1$s', symbol).replace('%2$s', num);
		return '<span class="woocommerce-Price-amount amount"><bdi>' + inner + '</bdi></span>';
	}

	function animate($block) {
		const anim = $block.data('price-anim');
		if (!anim || anim === 'none') return;
		$block.removeClass('zsp-price-anim--fade zsp-price-anim--slide');
		void $block[0].offsetWidth;
		$block.addClass('zsp-price-anim--' + anim);
	}

	function updateSavings($block, current, regular, onSale) {
		const $sav = $block.find('.zymarg-sp-price-savings');
		if (!$sav.length) return;
		const saved = parseFloat(regular) - parseFloat(current);
		if (!onSale || !(saved > 0)) { $sav.hide().empty(); return; }
		const pct     = Math.round(saved / parseFloat(regular) * 100);
		const prefix  = $block.data('savings-prefix') || 'Save';
		const fmt     = $block.data('savings-format') || 'both';
		const amtHtml = fmtPrice(saved);
		const pctHtml = '<span class="zymarg-sp-price__save-pct">' + pct + '%</span>';
		let inner;
		if (fmt === 'amount')       inner = prefix + ' ' + amtHtml;
		else if (fmt === 'percent') inner = prefix + ' ' + pctHtml;
		else                        inner = prefix + ' ' + amtHtml + ' (' + pctHtml + ')';
		$sav.html('<span class="zymarg-sp-price__save-badge">' + inner + '</span>').show();
	}

	function applyState($block, current, regular, onSale) {
		const $cur = $block.find('.zymarg-sp-price-current');
		const $was = $block.find('.zymarg-sp-price-was');
		$cur.html(fmtPrice(current));
		if (onSale) {
			$was.html('<sub>' + fmtPrice(regular) + '</sub>').show();
		} else {
			$was.hide().empty();
		}
		updateSavings($block, current, regular, onSale);
		animate($block);
	}

	function restore($block) {
		const current = $block.data('initial-current');
		const regular = $block.data('initial-regular');
		const onSale  = parseInt($block.data('initial-on-sale'), 10) === 1;
		applyState($block, current, regular, onSale);
	}

	/* ── Smart Heading badge (v2.6.0) ───────────────────────────────────────
	 * Two states: 'oos' (out of stock) and 'flash' (on sale + has a live
	 * countdown, from either the Vendor Dashboard Premium Flash Sale feature
	 * or a native WooCommerce scheduled sale - see class-price-renderer.php).
	 * The countdown always renders as HH:MM:SS, uncapped, ticking every
	 * second, driven purely off a Unix end timestamp so re-rendering the
	 * badge (e.g. on variation change) never leaves stale digits behind -
	 * the very next tick always recomputes from data-end.
	 */

	// Same path data as class-price-renderer.php's icon_bolt()/icon_oos(), so
	// the markup this JS builds is visually identical to what the server
	// would have rendered for the same state.
	const ICON_BOLT = '<svg class="zymarg-sp-heading-badge__icon" viewBox="0 0 320 512" aria-hidden="true" focusable="false"><path d="M296 160H180.6l42.6-129.8C227.2 15 215.7 0 200 0H56C44 0 33.8 8.9 32.2 20.8l-32 240C-1.7 275.2 9.5 288 24 288h118.7L96.6 482.5c-3.6 15.2 8 29.5 23.3 29.5 8.4 0 16.4-4.4 20.8-12l176-304c9.3-15.9-2.2-36-20.7-36z"/></svg>';
	const ICON_OOS  = '<svg class="zymarg-sp-heading-badge__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm5.293-15.293a1 1 0 0 1 0 1.414L7.879 17.535a1 1 0 0 1-1.414-1.414L16.879 6.707a1 1 0 0 1 1.414 0z"/></svg>';

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// Static countdown fragment shared by both badge shapes.
	const COUNTDOWN_HTML = '<span class="zymarg-sp-heading-badge__countdown" aria-hidden="true">'
		+ '<span class="zymarg-sp-heading-badge__unit" data-unit="h">00</span><span class="zymarg-sp-heading-badge__sep">:</span>'
		+ '<span class="zymarg-sp-heading-badge__unit" data-unit="m">00</span><span class="zymarg-sp-heading-badge__sep">:</span>'
		+ '<span class="zymarg-sp-heading-badge__unit" data-unit="s">00</span>'
		+ '</span>';

	/**
	 * Build the badge markup for a heading state.
	 *
	 * v2.7.0 - HEADING.band_layout picks between the original single-line
	 * pill (icon + label + inline countdown, one row) and the band layout's
	 * two-line variant (icon + label on row 1, "Ends in" + countdown on row
	 * 2) - mirrors class-price-renderer.php's render_heading_badge()/
	 * band_label_text() exactly, so a variation change never produces markup
	 * different from what the server would have rendered for the same state.
	 *
	 * @param {string} type 'oos' | 'flash'.
	 * @param {string} text Full configured heading text (pill uses as-is).
	 * @param {number} end  Unix end timestamp (flash only).
	 */
	function buildBadgeHtml(type, text, end) {
		const isFlash  = ('flash' === type);
		const isBand   = !!HEADING.band_layout;
		const hasEnd   = isFlash && !!end;
		const bandCls  = isBand ? ' zymarg-sp-heading-badge--band' : '';
		const icon     = isFlash ? ICON_BOLT : ICON_OOS;

		// Band label uses the short line-1 text (pre-computed server-side,
		// same split rule as band_label_text()); pill keeps the full text.
		const label = isBand
			? escapeHtml(isFlash ? (HEADING.band_flash_label || text) : (HEADING.band_oos_label || text))
			: escapeHtml(text);

		if ('oos' !== type && 'flash' !== type) {
			return '';
		}

		const endAttr = hasEnd ? ' data-end="' + (parseInt(end, 10) || 0) + '"' : '';

		let html = '<div class="zymarg-sp-heading-badge zymarg-sp-heading-badge--' + type + bandCls + '" data-heading-type="' + type + '"' + endAttr + '>';
		html += '<span class="zymarg-sp-heading-badge__label-row">';
		html += icon;
		html += '<span class="zymarg-sp-heading-badge__text">' + label + '</span>';
		if (hasEnd && !isBand) {
			html += COUNTDOWN_HTML;
		}
		html += '</span>';
		if (hasEnd && isBand) {
			const endsLabel = escapeHtml(HEADING.band_ends_label || 'Ends in');
			html += '<span class="zymarg-sp-heading-badge__countdown-row">';
			html += '<span class="zymarg-sp-heading-badge__ends-label">' + endsLabel + '</span>';
			html += COUNTDOWN_HTML;
			html += '</span>';
		}
		html += '</div>';

		return html;
	}

	function pad2(n) {
		n = Math.max(0, Math.floor(n));
		return (n < 10 ? '0' : '') + n;
	}

	// Always HH:MM:SS, never days/weeks - hours simply grows past two digits
	// for a countdown further out than 99 hours (~4 days) instead of ever
	// switching format.
	function renderCountdown($badge) {
		const end = parseInt($badge.attr('data-end'), 10) || 0;
		if (!end) return;

		const diff = end - Math.floor(Date.now() / 1000);
		if (diff <= 0) {
			// Expired: the countdown badge has nothing left to show. The slot
			// is cleared rather than left frozen at 00:00:00 - a fresh page
			// load will correctly show 'oos' instead if stock also ran out.
			$badge.closest('.zymarg-sp-heading-slot').empty();
			return;
		}

		const h = Math.floor(diff / 3600);
		const m = Math.floor((diff % 3600) / 60);
		const s = diff % 60;
		$badge.find('[data-unit="h"]').text(pad2(h));
		$badge.find('[data-unit="m"]').text(pad2(m));
		$badge.find('[data-unit="s"]').text(pad2(s));
	}

	let headingTicker = null;

	function tickAllHeadings() {
		$('.zymarg-sp-heading-badge--flash[data-end]').each(function () {
			renderCountdown($(this));
		});
	}

	// Started lazily, only once a flash badge actually exists on the page, so
	// a page with neither an out-of-stock nor a flash-sale product never runs
	// an interval at all.
	function ensureHeadingTicker() {
		if (headingTicker) return;
		headingTicker = setInterval(tickAllHeadings, 1000);
		tickAllHeadings();
	}

	/**
	 * Resolve and paint the heading badge for a specific selected variation.
	 *
	 * Priority mirrors class-price-renderer.php exactly:
	 *   1. Out of stock (this variation) wins outright.
	 *   2. Vendor Dashboard Premium Flash Sale - parent-level data, so its
	 *      liveness/end time never changes by variation; HEADING.flash_end
	 *      already reflects the one truth for this product.
	 *   3. Native WooCommerce scheduled sale on THIS variation specifically -
	 *      read from variation.zymarg_sale_end, injected server-side by
	 *      Price_Renderer::inject_variation_sale_end() via the
	 *      woocommerce_available_variation filter (WooCommerce does not send
	 *      the sale end date by default).
	 *   4. Otherwise, no badge.
	 *
	 * @param {jQuery} $slot     The .zymarg-sp-heading-slot element.
	 * @param {Object} variation WooCommerce's found_variation payload.
	 */
	/**
	 * v2.7.0 - when the band layout is active, the coloured background lives
	 * on the .zymarg-sp-price-band wrapper (one level up from the heading
	 * slot), not on the badge itself - so a variation change must also swap
	 * that wrapper's zymarg-sp-price-band--{type} modifier class, or the
	 * background would stay stuck on whatever state the page loaded with.
	 * No-op when the band layout is off; that markup does not exist then.
	 *
	 * @param {jQuery} $slot The .zymarg-sp-heading-slot element.
	 * @param {string} type  'oos' | 'flash' | 'none'.
	 */
	function setBandType($slot, type) {
		if (!HEADING.band_layout) return;
		const $band = $slot.closest('.zymarg-sp-price-band');
		if (!$band.length) return;
		$band.removeClass('zymarg-sp-price-band--oos zymarg-sp-price-band--flash zymarg-sp-price-band--none')
			.addClass('zymarg-sp-price-band--' + type)
			.attr('data-band-type', type);
	}

	function updateHeadingForVariation($slot, variation) {
		const inStock = !!variation.is_in_stock;

		if (!inStock && HEADING.oos_enabled) {
			setBandType($slot, 'oos');
			$slot.html(buildBadgeHtml('oos', HEADING.oos_text));
			return;
		}

		if (HEADING.flash_enabled) {
			if (HEADING.flash_live && HEADING.flash_end) {
				setBandType($slot, 'flash');
				$slot.html(buildBadgeHtml('flash', HEADING.flash_text, HEADING.flash_end));
				ensureHeadingTicker();
				return;
			}

			const current = parseFloat(variation.display_price);
			const regular = parseFloat(variation.display_regular_price);
			const onSale  = !isNaN(regular) && regular > current;
			const end     = parseInt(variation.zymarg_sale_end, 10) || 0;
			const now     = Math.floor(Date.now() / 1000);

			if (onSale && end > now) {
				setBandType($slot, 'flash');
				$slot.html(buildBadgeHtml('flash', HEADING.flash_text, end));
				ensureHeadingTicker();
				return;
			}
		}

		setBandType($slot, 'none');
		$slot.empty();
	}

	$(function () {
		// The heading slot is always printed (see class-price-renderer.php),
		// on both simple and variable products, so it is resolved
		// independently of whether a variations form exists on this page.
		const $headingSlot = $('.zymarg-sp-heading-slot').first();
		if ($headingSlot.length) {
			// Snapshot the server-rendered state so reset_data can restore it
			// exactly, without recomputing anything client-side. v2.7.0 - also
			// snapshot the band wrapper's initial modifier class/attribute, so
			// reset_data can put the band background back the way it loaded in,
			// not just the badge markup inside it.
			$headingSlot.data('initial-html', $headingSlot.html());
			const $initialBand = $headingSlot.closest('.zymarg-sp-price-band');
			if ($initialBand.length) {
				$headingSlot.data('initial-band-type', $initialBand.attr('data-band-type') || 'none');
			}
			if ($headingSlot.find('.zymarg-sp-heading-badge--flash[data-end]').length) {
				ensureHeadingTicker();
			}
		}

		const $block = $('.zymarg-sp-price-block[data-is-variable="1"]').first();
		const $form  = $('.variations_form').first();
		if (!$form.length) return;

		$form.on('found_variation', function (e, variation) {
			if ($headingSlot.length) {
				updateHeadingForVariation($headingSlot, variation);
			}
			if ($block.length) {
				const current = variation.display_price;
				const regular = variation.display_regular_price;
				const onSale  = (typeof regular !== 'undefined') && parseFloat(regular) > parseFloat(current);
				applyState($block, current, regular, onSale);
			}
		});

		$form.on('reset_data', function () {
			if ($headingSlot.length) {
				$headingSlot.html($headingSlot.data('initial-html'));
				setBandType($headingSlot, $headingSlot.data('initial-band-type') || 'none');
				if ($headingSlot.find('.zymarg-sp-heading-badge--flash[data-end]').length) {
					ensureHeadingTicker();
				}
			}
			if ($block.length) {
				restore($block);
			}
		});
	});

})(jQuery);
