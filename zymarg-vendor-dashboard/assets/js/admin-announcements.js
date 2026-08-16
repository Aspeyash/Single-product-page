/**
 * ZYMARG Vendor Dashboard -- Admin Announcements CRUD.
 *
 * Handles creating, deactivating, and deleting announcements via AJAX.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function ($) {
	'use strict';

	function showFeedback(msg, isError) {
		var $fb = $('.zymarg-announce-form__feedback');
		$fb.text(msg)
			.removeClass('zymarg-announce-form__feedback--success zymarg-announce-form__feedback--error')
			.addClass(isError ? 'zymarg-announce-form__feedback--error' : 'zymarg-announce-form__feedback--success');
		setTimeout(function () {
			$fb.text('').removeClass('zymarg-announce-form__feedback--success zymarg-announce-form__feedback--error');
		}, 4000);
	}

	function init() {
		// Toggle vendor multi-select visibility.
		$(document).off('change.zymargAnnounce', '#zymarg-announce-target');
		$(document).on('change.zymargAnnounce', '#zymarg-announce-target', function () {
			var $vendors = $('.zymarg-announce-form__vendors');
			// State class, not an inline style.
			$vendors.toggleClass('zvd-is-hidden', $(this).val() !== 'select');
		});

		// Create announcement form submit.
		$(document).off('submit.zymargAnnounce', '#zymarg-announce-form');
		$(document).on('submit.zymargAnnounce', '#zymarg-announce-form', function (e) {
			e.preventDefault();

			var $form = $(this);
			var $btn = $form.find('.zymarg-announce-submit');

			var title = $('#zymarg-announce-title').val().trim();
			var body = $('#zymarg-announce-body').val().trim();
			var targetType = $('#zymarg-announce-target').val();
			var target = 'all';

			if (targetType === 'select') {
				var selected = $('#zymarg-announce-vendor-select').val();
				if (!selected || selected.length === 0) {
					showFeedback('Please select at least one vendor.', true);
					return;
				}
				target = selected.join(',');
			}

			if (!title) {
				showFeedback('Title is required.', true);
				return;
			}

			$btn.prop('disabled', true).text('Publishing...');

			$.ajax({
				url: ZymargAnnouncements.ajaxUrl,
				type: 'POST',
				data: {
					action: 'zymarg_vd_create_announcement',
					nonce: ZymargAnnouncements.nonce,
					title: title,
					body: body,
					target: target
				},
				success: function (response) {
					if (response.success) {
						showFeedback(response.data.message, false);

						// Insert the new row in place -- no page reload.
						var $list = $('#zymarg-announce-list');
						$list.find('.zymarg-announce-empty').remove();
						if (response.data.html) {
							// List is ordered newest-first, so prepend.
							$(response.data.html).hide().prependTo($list).slideDown(200);
						}

						// Reset the form ready for the next announcement.
						$form[0].reset();
						$('.zymarg-announce-form__vendors').addClass('zvd-is-hidden');
					} else {
						showFeedback(response.data.message || 'Error creating announcement.', true);
					}
				},
				error: function () {
					showFeedback('Network error. Please try again.', true);
				},
				complete: function () {
					$btn.prop('disabled', false).text('Publish Announcement');
				}
			});
		});

		// Deactivate button.
		$(document).off('click.zymargAnnounce', '.zymarg-announce-deactivate');
		$(document).on('click.zymargAnnounce', '.zymarg-announce-deactivate', function () {
			var $btn = $(this);
			var postId = $btn.data('id');
			var $row = $btn.closest('.zymarg-announce-row');

			if (!confirm('Deactivate this announcement?')) {
				return;
			}

			$btn.prop('disabled', true).text('...');

			$.ajax({
				url: ZymargAnnouncements.ajaxUrl,
				type: 'POST',
				data: {
					action: 'zymarg_vd_deactivate_announcement',
					nonce: ZymargAnnouncements.nonce,
					post_id: postId
				},
				success: function (response) {
					if (response.success) {
						$row.find('.zymarg-announce-status--active')
							.removeClass('zymarg-announce-status--active')
							.addClass('zymarg-announce-status--expired')
							.text('Expired');
						$btn.remove();
					} else {
						$btn.prop('disabled', false).text('Deactivate');
						showFeedback(response.data.message || 'Error.', true);
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Deactivate');
					showFeedback('Network error.', true);
				}
			});
		});

		// Delete button.
		$(document).off('click.zymargAnnounce', '.zymarg-announce-delete');
		$(document).on('click.zymargAnnounce', '.zymarg-announce-delete', function () {
			var $btn = $(this);
			var postId = $btn.data('id');
			var $row = $btn.closest('.zymarg-announce-row');

			if (!confirm('Permanently delete this announcement?')) {
				return;
			}

			$btn.prop('disabled', true).text('...');

			$.ajax({
				url: ZymargAnnouncements.ajaxUrl,
				type: 'POST',
				data: {
					action: 'zymarg_vd_delete_announcement',
					nonce: ZymargAnnouncements.nonce,
					post_id: postId
				},
				success: function (response) {
					if (response.success) {
						$row.slideUp(200, function () { $(this).remove(); });
					} else {
						$btn.prop('disabled', false).text('Delete');
						showFeedback(response.data.message || 'Error.', true);
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Delete');
					showFeedback('Network error.', true);
				}
			});
		});
	}

	// Initialize on DOM ready.
	$(document).ready(init);

	/*
	 * Re-initialise after an admin SPA content swap.
	 *
	 * The admin router (admin-hub.js) calls window.ZymargAnnouncementsInit
	 * by name and broadcasts 'zymarg:contentSwapped'. The frontend vendor
	 * dashboard uses a different event name, 'zymarg-vd:section-loaded',
	 * which nothing in the admin ever fires -- so listening for it here was
	 * dead code. Bind both the named global and the correct admin event.
	 */
	window.ZymargAnnouncementsInit = init;
	$(document).on('zymarg:contentSwapped', init);

})(jQuery);
