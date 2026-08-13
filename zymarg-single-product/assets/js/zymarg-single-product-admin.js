/**
 * ZYMARG Single Product — Admin JS v1.0.0
 * JS-powered tab switching (no page reload) · AJAX save · toggle-text reveal
 *
 * Renamed from zymarg-single-product-admin.js in v2.4.6 -- that filename, its enqueue
 * handle, and its window-global name were identical to the ones used by the
 * separate ZYMARG Store Page plugin's own (unrelated) admin script.
 */
(function ($) {
	'use strict';

	const Admin = window.zymargSingleProductAdmin || {};

	/* ── Tab switching ───────────────────────────────────────────────────── */

	function initTabs() {
		const $nav    = $('.zymarg-single-product-admin__tabs-nav');
		const $panels = $('.zymarg-single-product-admin__panel');
		const $btns   = $nav.find('.zymarg-single-product-admin__tab-btn');

		function activateTab(id) {
			$btns.attr('aria-selected', 'false');
			$panels.removeClass('is-active');
			const $btn   = $nav.find('[data-tab="' + id + '"]');
			const $panel = $('#zymarg-sp-tab-' + id);
			$btn.attr('aria-selected', 'true');
			$panel.addClass('is-active');
			// Persist active tab in sessionStorage so refresh restores it.
			try { sessionStorage.setItem('zymargSPTab', id); } catch (e) {}
		}

		$btns.on('click', function () {
			activateTab($(this).data('tab'));
		});

		// Keyboard nav within tablist.
		$btns.on('keydown', function (e) {
			const $all = $btns.toArray();
			const idx  = $all.indexOf(this);
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
				e.preventDefault();
				const next = $all[(idx + 1) % $all.length];
				$(next).focus().trigger('click');
			}
			if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
				e.preventDefault();
				const prev = $all[(idx - 1 + $all.length) % $all.length];
				$(prev).focus().trigger('click');
			}
			if (e.key === 'Home') { e.preventDefault(); $($all[0]).focus().trigger('click'); }
			if (e.key === 'End')  { e.preventDefault(); $($all[$all.length - 1]).focus().trigger('click'); }
		});

		// Restore saved tab or default to first.
		let saved = '';
		try { saved = sessionStorage.getItem('zymargSPTab') || ''; } catch (e) {}
		const firstId = $btns.first().data('tab');
		activateTab(saved && $nav.find('[data-tab="' + saved + '"]').length ? saved : firstId);
	}

	/* ── Toggle-text reveal ──────────────────────────────────────────────── */

	function initToggleTextReveal() {
		// When a toggle that controls a sibling text-row changes, show/hide the row.
		$(document).on('change', '.zymarg-sp-toggle input[type="checkbox"]', function () {
			const key     = $(this).data('key');
			const $parent = $(this).closest('.zymarg-sp-field--toggle-text');
			if (!$parent.length) return;
			const $textRow = $parent.find('[data-controlled-by="' + key + '"]');
			if ($textRow.length) {
				$textRow.toggleClass('is-hidden', !this.checked);
			}
		});
	}

	/* ── Collect settings ────────────────────────────────────────────────── */

	function collectSettings() {
		const settings = {};

		// Text inputs and textareas.
		$('[data-key]').filter('input[type="text"], input[type="number"], textarea').each(function () {
			settings[$(this).data('key')] = $(this).val();
		});

		// Checkboxes — all known toggle keys default to false, then set true if checked.
		$('[data-key]').filter('input[type="checkbox"]').each(function () {
			const key = $(this).data('key');
			settings[key] = this.checked ? 'true' : 'false';
		});

		// Radios — only the checked one.
		$('[data-key]').filter('input[type="radio"]:checked').each(function () {
			settings[$(this).data('key')] = $(this).val();
		});

		// Repeater rows travel as JSON: jQuery drops empty arrays from a POST
		// body, and PHP's sanitize_text_field() returns '' if handed an array.
		// See Admin::sanitize_sections() for the other half of this contract.
		settings.product_sections = JSON.stringify(collectSections());

		return settings;
	}

	/* ── Status bar ──────────────────────────────────────────────────────── */

	function showStatus(msg, isError) {
		const $s = $('#zymarg-sp-status');
		$s.text(msg)
			.removeClass('is-success is-error')
			.addClass(isError ? 'is-error' : 'is-success');
		clearTimeout($s.data('timer'));
		$s.data('timer', setTimeout(function () {
			$s.removeClass('is-success is-error').text('');
		}, 4000));
	}

	/* ── AJAX Save ───────────────────────────────────────────────────────── */

	function initSave() {
		$('#zymarg-sp-save-btn').on('click', function () {
			const $btn = $(this);

			// Refuse structurally broken shortcodes up front, so the server's
			// allow-list never has to blank one out behind the user's back.
			const problems = validateSections();
			if (problems.length) {
				window.alert(
					(Admin.invalid_text || 'Fix these section problems before saving:')
					+ '\n\n\u2022 ' + problems.join('\n\u2022 ')
				);
				return;
			}

			// Spell out what is about to change on the front end.
			const changes = describeChanges();
			if (changes.length) {
				const head = Admin.confirm_save || 'Save these section changes?';
				if (!window.confirm(head + '\n\n\u2022 ' + changes.join('\n\u2022 '))) { return; }
			}

			$btn.addClass('is-saving').find('svg').css('animation', 'spin .7s linear infinite');

			const settings = collectSettings();

			$.ajax({
				url:  Admin.ajax_url,
				type: 'POST',
				data: {
					action:   'zymarg_sp_save',
					nonce:    Admin.nonce,
					settings: settings,
				},
				success: function (res) {
					$btn.removeClass('is-saving').find('svg').css('animation', '');
					if (res.success) {
						showStatus(Admin.saved_text || 'Settings saved!', false);

						// Re-lock every row and rebase the diff, so the next visit
						// starts from the same safe state as a fresh page load.
						$('#zymarg-sp-sections .zymarg-sp-section-row').each(function () {
							setRowLocked($(this), true);
						});
						refreshSnapshot();
					} else {
						showStatus((res.data && res.data.message) || Admin.error_text || 'Error saving.', true);
					}
				},
				error: function () {
					$btn.removeClass('is-saving').find('svg').css('animation', '');
					showStatus(Admin.error_text || 'Error saving. Please try again.', true);
				},
			});
		});

		// Ctrl/Cmd + S shortcut. Deliberately inert while a section field has
		// focus: hitting save-by-habit mid-edit is exactly how an unintended
		// shortcode change would get persisted.
		$(document).on('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && e.key === 's') {
				e.preventDefault();

				const $active = $(document.activeElement);
				if ($active.is('[data-row-field="shortcode"]')
					|| $active.is('[data-row-field="heading"]')
					|| $active.is('[data-row-field="link_url"]')) {
					return;
				}

				$('#zymarg-sp-save-btn').trigger('click');
			}
		});
	}


	/* -- Grid section repeater (v2.2.0) ---------------------------------- */

	// Rows open locked. Nothing inside a row is writable until Edit is pressed,
	// so opening this tab to look at a section cannot change what the front end
	// renders. The snapshot below is taken once the rows are locked, and is what
	// Save diffs against to describe the change set.
	var sectionsSnapshot = '[]';

	function collectSections() {
		const rows = [];

		$('#zymarg-sp-sections .zymarg-sp-section-row').each(function () {
			const $row = $(this);
			rows.push({
				id:        String($row.attr('data-row-id') || ''),
				label:     $row.find('[data-row-field="label"]').val() || '',
				enabled:   $row.find('[data-row-field="enabled"]').is(':checked'),
				heading:   $row.find('[data-row-field="heading"]').val() || '',
				show_link: $row.find('[data-row-field="show_link"]').is(':checked'),
				link_url:  $row.find('[data-row-field="link_url"]').val() || '',
				shortcode: $row.find('[data-row-field="shortcode"]').val() || ''
			});
		});

		return rows;
	}

	function attr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// Locking uses readonly for text and disabled for checkboxes. jQuery .val()
	// and :checked still read both, so collectSections() is unaffected.
	function setRowLocked($row, locked) {
		$row.toggleClass('is-locked', !!locked);

		$row.find('[data-row-field="label"], [data-row-field="heading"], [data-row-field="link_url"], [data-row-field="shortcode"]')
			.prop('readonly', !!locked);

		$row.find('[data-row-field="enabled"], [data-row-field="show_link"]')
			.prop('disabled', !!locked);

		$row.find('.zymarg-sp-section-row__edit')
			.text(locked ? (Admin.edit_text || 'Edit') : (Admin.done_text || 'Done'))
			.toggleClass('is-editing', !locked);
	}

	function rowMeta($row) {
		const code = String($row.find('[data-row-field="shortcode"]').val() || '');
		const src  = (code.match(/\bsource=("|')(.*?)\1/) || [])[2] || '?';
		const lay  = (code.match(/\blayout=("|')(.*?)\1/) || [])[2] || 'grid';
		$row.find('[data-row-meta]').text(src.toLowerCase() + ' \u00b7 ' + lay.toLowerCase());
	}

	// Client-side mirror of the server's allow-list, so a bad tag is refused
	// before it is saved rather than silently blanked afterwards.
	function validateSections() {
		const errors  = [];
		const allowed = Admin.allowed_shortcodes || ['zymarg_products'];

		$('#zymarg-sp-sections .zymarg-sp-section-row').each(function () {
			const $row  = $(this);
			const code  = String($row.find('[data-row-field="shortcode"]').val() || '').trim();
			const name  = $row.find('[data-row-field="label"]').val() || (Admin.untitled_text || 'this section');
			const $warn = $row.find('.zymarg-sp-section-row__warn');

			$row.removeClass('has-error');
			$warn.attr('hidden', 'hidden').text('');

			// An empty box is legitimate: the section simply does not render.
			if ('' === code) { return; }

			const opens  = (code.match(/\[/g) || []).length;
			const closes = (code.match(/\]/g) || []).length;
			const quotes = (code.match(/"/g) || []).length;
			const tag    = (code.match(/^\[\s*([a-z0-9_]+)/i) || [])[1] || '';

			let msg = '';

			if ('[' !== code.charAt(0) || ']' !== code.charAt(code.length - 1)) {
				msg = 'Must start with [ and end with ].';
			} else if (opens !== closes) {
				msg = 'Unbalanced square brackets.';
			} else if (quotes % 2 !== 0) {
				msg = 'Unbalanced quotation marks.';
			} else if (!tag || allowed.indexOf(tag.toLowerCase()) === -1) {
				msg = '"' + tag + '" is not a ZYMARG Product Grid shortcode and would be discarded on save.';
			}

			if (msg) {
				$row.addClass('has-error');
				$warn.text(msg).removeAttr('hidden');
				errors.push(name + ' \u2014 ' + msg);
			}
		});

		return errors;
	}

	// Plain-language diff of the row set, shown before the save goes out.
	function describeChanges() {
		let before;
		try { before = JSON.parse(sectionsSnapshot || '[]'); } catch (e) { before = []; }

		const after = collectSections();
		const lines = [];
		const prev  = {};

		before.forEach(function (r) { prev[r.id] = r; });

		const beforeIds = before.map(function (r) { return r.id; });
		const afterIds  = after.map(function (r) { return r.id; });

		after.forEach(function (row) {
			const was  = prev[row.id];
			const name = row.label || row.heading || row.id;

			if (!was) { lines.push('Added section: ' + name); return; }

			if (was.shortcode !== row.shortcode) { lines.push('SHORTCODE changed: ' + name); }
			if (was.heading   !== row.heading)   { lines.push('Heading changed: ' + name); }
			if (was.label     !== row.label)     { lines.push('Admin name changed: ' + name); }
			if (!!was.enabled !== !!row.enabled) { lines.push((row.enabled ? 'Enabled: ' : 'DISABLED: ') + name); }
			if (!!was.show_link !== !!row.show_link) { lines.push('Link toggle changed: ' + name); }
			if ((was.link_url || '') !== (row.link_url || '')) { lines.push('Link URL changed: ' + name); }
		});

		before.forEach(function (row) {
			if (afterIds.indexOf(row.id) === -1) {
				lines.push('REMOVED section: ' + (row.label || row.heading || row.id));
			}
		});

		if (beforeIds.join('|') !== afterIds.join('|')
			&& beforeIds.length === afterIds.length) {
			lines.push('Section order changed.');
		}

		return lines;
	}

	function refreshSnapshot() {
		sectionsSnapshot = JSON.stringify(collectSections());
	}

	function newRowMarkup(id) {
		const ph = '[zymarg_products source=&quot;vendor&quot; limit=&quot;10&quot;]';

		return '<div class="zymarg-sp-section-row" data-row-id="' + attr(id) + '">' +
			'<div class="zymarg-sp-section-row__head">' +
				'<span class="zymarg-sp-section-row__handle" aria-hidden="true">&#8942;&#8942;</span>' +
				'<input type="text" class="zymarg-sp-input zymarg-sp-section-row__label" data-row-field="label" value="" placeholder="Section name (admin only)">' +
				'<span class="zymarg-sp-section-row__meta" data-row-meta>? \u00b7 grid</span>' +
				'<label class="zymarg-sp-toggle zymarg-sp-section-row__toggle">' +
					'<input type="checkbox" data-row-field="enabled" checked>' +
					'<span class="zymarg-sp-toggle__track"></span>' +
				'</label>' +
				'<button type="button" class="zymarg-sp-section-row__edit">' + attr(Admin.done_text || 'Done') + '</button>' +
				'<button type="button" class="zymarg-sp-section-row__remove" aria-label="Remove section">&times;</button>' +
			'</div>' +
			'<div class="zymarg-sp-section-row__body">' +
				'<div class="zymarg-sp-section-row__field">' +
					'<label class="zymarg-sp-section-row__flabel">Section heading (shown on the page)</label>' +
					'<input type="text" class="zymarg-sp-input" data-row-field="heading" value="" placeholder="More from {vendor_name}">' +
					'<p class="zymarg-sp-section-row__hint">Use <code>{vendor_name}</code> to print the seller shop name. It resolves on vendor sections only. Leave empty for no heading.</p>' +
				'</div>' +
				'<div class="zymarg-sp-section-row__field zymarg-sp-section-row__field--inline">' +
					'<label class="zymarg-sp-toggle">' +
						'<input type="checkbox" data-row-field="show_link">' +
						'<span class="zymarg-sp-toggle__track"></span>' +
					'</label>' +
					'<span class="zymarg-sp-section-row__flabel">Show the section link</span>' +
				'</div>' +
				'<div class="zymarg-sp-section-row__field">' +
					'<label class="zymarg-sp-section-row__flabel">Link URL</label>' +
					'<input type="url" class="zymarg-sp-input" data-row-field="link_url" value="" placeholder="https://">' +
					'<p class="zymarg-sp-section-row__hint">Vendor sections resolve the seller store automatically and ignore this field. Elsewhere, empty means no link renders.</p>' +
				'</div>' +
				'<div class="zymarg-sp-section-row__field">' +
					'<label class="zymarg-sp-section-row__flabel">Shortcode</label>' +
					'<textarea class="zymarg-sp-input zymarg-sp-textarea zymarg-sp-section-row__shortcode" data-row-field="shortcode" rows="3" spellcheck="false" placeholder="' + ph + '"></textarea>' +
					'<p class="zymarg-sp-section-row__warn" hidden></p>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	function initSections() {
		const $list = $('#zymarg-sp-sections');
		if (!$list.length) { return; }

		// Server markup already carries readonly/disabled; this keeps the JS view
		// of lock state authoritative and refreshes the source/layout chips.
		$list.find('.zymarg-sp-section-row').each(function () {
			setRowLocked($(this), true);
			rowMeta($(this));
		});

		refreshSnapshot();

		// Drag to reorder. Array order is render order, so no index field to sync.
		if ($.fn.sortable) {
			$list.sortable({
				handle:               '.zymarg-sp-section-row__handle',
				items:                '> .zymarg-sp-section-row',
				axis:                 'y',
				opacity:              0.7,
				placeholder:          'zymarg-sp-section-row__placeholder',
				forcePlaceholderSize: true
			});
		}

		$('#zymarg-sp-add-section').on('click', function () {
			const id = 'sec_' + Date.now().toString(36);
			$list.append(newRowMarkup(id));
			const $row = $list.find('.zymarg-sp-section-row').last();
			setRowLocked($row, false);
			$row.find('[data-row-field="label"]').trigger('focus');
		});

		$list.on('click', '.zymarg-sp-section-row__edit', function () {
			const $row   = $(this).closest('.zymarg-sp-section-row');
			const locked = $row.hasClass('is-locked');

			setRowLocked($row, !locked);

			if (locked) {
				$row.find('[data-row-field="heading"]').trigger('focus');
			} else {
				rowMeta($row);
				validateSections();
			}
		});

		$list.on('click', '.zymarg-sp-section-row__remove', function () {
			const $row = $(this).closest('.zymarg-sp-section-row');
			const name = $row.find('[data-row-field="label"]').val()
				|| $row.find('[data-row-field="heading"]').val()
				|| (Admin.untitled_text || 'this section');
			const tmpl = Admin.confirm_remove
				|| 'Remove "%s"? It will stop rendering on every product page once you save.';

			if (!window.confirm(tmpl.replace('%s', name))) { return; }

			$row.remove();
		});

		$list.on('change', '[data-row-field="enabled"]', function () {
			$(this).closest('.zymarg-sp-section-row')
				.toggleClass('is-disabled', !this.checked);
		});

		$list.on('input', '[data-row-field="shortcode"]', function () {
			rowMeta($(this).closest('.zymarg-sp-section-row'));
		});

		$list.on('blur', '[data-row-field="shortcode"]', function () {
			validateSections();
		});

		$('#zymarg-sp-restore-sections').on('click', function () {
			const msg = Admin.confirm_restore
				|| 'Restore the section list from before your last save?';

			if (!window.confirm(msg)) { return; }

			const $btn = $(this).prop('disabled', true);

			$.ajax({
				url:  Admin.ajax_url,
				type: 'POST',
				data: {
					action: 'zymarg_sp_restore_sections',
					nonce:  Admin.restore_nonce
				},
				success: function (res) {
					if (res && res.success) {
						window.location.reload();
						return;
					}
					$btn.prop('disabled', false);
					showStatus((res && res.data && res.data.message) || Admin.restore_failed || 'Restore failed.', true);
				},
				error: function () {
					$btn.prop('disabled', false);
					showStatus(Admin.restore_failed || 'Restore failed.', true);
				}
			});
		});
	}

	/* ── Init ────────────────────────────────────────────────────────────── */

	$(function () {
		initTabs();
		initToggleTextReveal();
		initSections();
		initSave();
	});

})(jQuery);
