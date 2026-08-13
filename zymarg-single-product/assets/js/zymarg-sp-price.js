/**
 * ZYMARG Single Product — Live price updates v1.0.2
 *
 * For variable products, swaps the current price (inline) and old price
 * (subscript) as the shopper selects a variation, keeping the exact same
 * markup the server renders (see class-price-renderer.php). Simple products
 * are fully server-rendered and ignored here.
 */
(function ($) {
	'use strict';

	const SP    = window.zymargSP || {};
	const PRICE = SP.price || {};

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

	$(function () {
		const $block = $('.zymarg-sp-price-block[data-is-variable="1"]').first();
		if (!$block.length) return;
		const $form = $('.variations_form').first();
		if (!$form.length) return;

		$form.on('found_variation', function (e, variation) {
			const current = variation.display_price;
			const regular = variation.display_regular_price;
			const onSale  = (typeof regular !== 'undefined') && parseFloat(regular) > parseFloat(current);
			applyState($block, current, regular, onSale);
		});

		$form.on('reset_data', function () {
			restore($block);
		});
	});

})(jQuery);
