/**
 * ZYMARG Single Product — Attribute term meta admin UI v1.0.2
 *
 * Powers the color picker + image uploader on WooCommerce attribute term
 * screens (Products → Attributes → Configure terms). Selectors match
 * ZymargSP\Swatches\Term_Meta field markup. Standalone from WSE.
 */
(function ($) {
	'use strict';

	const L = window.ZymargSPTermMeta || {};

	function initColorPickers(context) {
		$(context || document).find('.zsp-color-picker').each(function () {
			const $el = $(this);
			if ($el.data('zsp-cp')) return;
			if (typeof $el.wpColorPicker === 'function') {
				$el.wpColorPicker();
				$el.data('zsp-cp', true);
			}
		});
	}

	function openMedia($btn) {
		const $wrap    = $btn.closest('.zsp-image-upload-wrap');
		const $input   = $wrap.find('input[name="zymarg_swatch_image"]');
		const $preview = $wrap.find('.zsp-image-preview');
		const $remove  = $wrap.find('.zsp-remove-image-btn');

		const frame = wp.media({
			title:    L.choose_image || 'Choose Swatch Image',
			button:   { text: L.use_image || 'Use this image' },
			library:  { type: 'image' },
			multiple: false,
		});

		frame.on('select', function () {
			const att = frame.state().get('selection').first().toJSON();
			let url = att.url;
			if (att.sizes) {
				if (att.sizes.thumbnail) url = att.sizes.thumbnail.url;
				else if (att.sizes.medium) url = att.sizes.medium.url;
			}
			$input.val(att.id);
			$preview.attr('src', url).removeClass('hidden');
			$remove.removeClass('hidden');
		});

		frame.open();
	}

	function removeImage($btn) {
		const $wrap = $btn.closest('.zsp-image-upload-wrap');
		$wrap.find('input[name="zymarg_swatch_image"]').val('');
		$wrap.find('.zsp-image-preview').attr('src', L.placeholder || '').addClass('hidden');
		$btn.addClass('hidden');
	}

	$(function () {
		initColorPickers();

		$(document).on('click', '.zsp-upload-image-btn', function (e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) return;
			openMedia($(this));
		});

		$(document).on('click', '.zsp-remove-image-btn', function (e) {
			e.preventDefault();
			removeImage($(this));
		});

		// Re-init + reset the add-term form after WP's inline "Add" AJAX.
		$(document).ajaxSuccess(function (e, xhr, settings) {
			if (settings && settings.data && settings.data.indexOf('action=add-tag') !== -1) {
				initColorPickers();
				const $wrap = $('.term-zsp-image-wrap .zsp-image-upload-wrap');
				$wrap.find('input[name="zymarg_swatch_image"]').val('');
				$wrap.find('.zsp-image-preview').addClass('hidden');
				$wrap.find('.zsp-remove-image-btn').addClass('hidden');
			}
		});
	});

})(jQuery);
