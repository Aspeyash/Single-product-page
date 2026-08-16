/*!
 * ZYMARG Reviews — Front-end script
 * Version: 1.0.6
 * License: GPL-2.0-or-later
 *
 * Vanilla JS, no dependencies. Handles:
 *   - Star rating input
 *   - Form submission via fetch + FormData (with media upload)
 *   - Show form on URL trigger and scroll into view
 *   - Filter / sort / load-more for review feed
 *   - Pagination count ("Showing X of Y reviews")
 *   - Manual mode Load More (JSON-encoded review list)
 *   - Store owner reply forms (toggle, submit, cancel)
 *   - Media file count display + client-side validation
 */
(function () {
	'use strict';

	var CFG = window.ZymargReviews || {};

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var widgets = document.querySelectorAll('.zymarg-reviews-widget');
		widgets.forEach(initWidget);
	});

	function initWidget(root) {
		initStarInput(root);
		initForm(root);
		initFilters(root);
		initSort(root);
		initLoadMore(root);
		initMediaCounter(root);
		initReplyForms(root);
		initVoteButtons(root);
		initDotsMenus(root);
		initReportModal(root);
		// v1.1.17 - review media gallery (defined at the foot of this file).
		if (window.zymargInitGallery) { window.zymargInitGallery(root); }
		maybeRevealAndScroll(root);
	}

	/* ------------------------------------------------------------------
	 * Star rating input
	 * ---------------------------------------------------------------- */
	function initStarInput(root) {
		var group = root.querySelector('.zymarg-rating-input');
		if (!group) return;
		var hidden = root.querySelector('.zymarg-rating-value');
		var stars  = group.querySelectorAll('.zymarg-rate-star');

		function paint(n) {
			stars.forEach(function (s, i) {
				if (i < n) { s.classList.remove('is-empty'); s.classList.add('is-filled'); }
				else       { s.classList.remove('is-filled'); s.classList.add('is-empty'); }
			});
		}

		stars.forEach(function (s) {
			s.addEventListener('mouseenter', function () { paint(parseInt(s.dataset.value, 10) || 0); });
			s.addEventListener('focus',      function () { paint(parseInt(s.dataset.value, 10) || 0); });
			s.addEventListener('click', function () {
				var v = parseInt(s.dataset.value, 10) || 0;
				if (hidden) hidden.value = String(v);
				group.dataset.value = String(v);
				paint(v);
			});
		});
		group.addEventListener('mouseleave', function () {
			paint(parseInt(group.dataset.value || '0', 10));
		});
	}

	/* ------------------------------------------------------------------
	 * Review form submit
	 * ---------------------------------------------------------------- */
	function initForm(root) {
		var form = root.querySelector('.zymarg-review-form');
		if (!form) return;

		var submitBtn = form.querySelector('.zymarg-btn-submit');
		var msg       = form.querySelector('.zymarg-form-message');

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (!CFG.ajaxUrl) {
				setMessage(msg, (CFG.i18n && CFG.i18n.genericErr) || 'Error', 'error');
				return;
			}

			var ratingInput = form.querySelector('.zymarg-rating-value');
			var rating      = ratingInput ? parseInt(ratingInput.value || '0', 10) : 0;
			if (!rating) {
				setMessage(msg, 'Please choose a rating.', 'error');
				return;
			}
			var bodyEl = form.querySelector('textarea[name="review_body"]');
			if (bodyEl && !bodyEl.value.trim()) {
				setMessage(msg, 'Please write your review.', 'error');
				bodyEl.focus();
				return;
			}

			var fileInput = form.querySelector('input[type="file"][name="media[]"]');
			if (fileInput && fileInput.files && fileInput.files.length) {
				var maxFiles = CFG.maxFiles || 4;
				var maxSize  = CFG.maxFileSize || (2048 * 1024);
				if (fileInput.files.length > maxFiles) {
					setMessage(msg, 'Too many files. Max: ' + maxFiles, 'error');
					return;
				}
				for (var i = 0; i < fileInput.files.length; i++) {
					if (fileInput.files[i].size > maxSize) {
						setMessage(msg, 'File too large: ' + fileInput.files[i].name, 'error');
						return;
					}
				}
			}

			submitBtn.disabled = true;
			var originalLabel  = submitBtn.textContent;
			submitBtn.textContent = (CFG.i18n && CFG.i18n.submitting) || 'Submitting…';
			setMessage(msg, '', '');

			var data = new FormData(form);
			data.set('action', 'zymarg_submit_review');
			if (CFG.submitNonce) data.set('_ajax_nonce', CFG.submitNonce);

			fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						var success = (form.querySelector('.zymarg-form-message')
							&& form.querySelector('.zymarg-form-message').dataset.success) || res.message || 'Thanks!';
						setMessage(msg, res.message || success, 'success');
						form.reset();
						// v1.0.8 - strip the gated review link's own query params
						// (zymarg_review, order_id, item_id, _nonce) and its
						// #zymarg-write-review hash from the visible URL right
						// after a successful submit. The form's own reveal check
						// (Review_Tracker::evaluate_request()) requires
						// zymarg_review=1 to even look at the rest of the params,
						// so once it's gone the form can never be re-revealed by
						// reloading or reopening this URL - only a fresh "Write a
						// Review" link (freshly minted per order item) can do
						// that, and is_item_reviewed() blocks it anyway once this
						// item has been reviewed. Other params already on the URL
						// (e.g. campaign/UTM tags) are left untouched.
						cleanReviewUrl();
						setTimeout(function () {
							var section = root.querySelector('#zymarg-write-review');
							if (section) section.style.display = 'none';
						}, 1800);
					} else {
						setMessage(msg, (res && res.message) || ((CFG.i18n && CFG.i18n.genericErr) || 'Error'), 'error');
						submitBtn.disabled = false;
						submitBtn.textContent = originalLabel;
					}
				})
				.catch(function () {
					setMessage(msg, (CFG.i18n && CFG.i18n.genericErr) || 'Error', 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;
				});
		});
	}

	function setMessage(el, text, kind) {
		if (!el) return;
		el.textContent = text || '';
		el.classList.remove('is-error', 'is-success');
		if (kind === 'error')   el.classList.add('is-error');
		if (kind === 'success') el.classList.add('is-success');
	}

	/**
	 * Remove the gated review link's own params + hash from the address bar
	 * without reloading the page (history.replaceState). No-op if the
	 * browser doesn't support the History API or none of the params are
	 * present.
	 */
	function cleanReviewUrl() {
		if (!window.history || !window.history.replaceState) return;

		var url = new URL(window.location.href);
		var keys = ['zymarg_review', 'order_id', 'item_id', '_nonce'];
		var changed = false;

		keys.forEach(function (key) {
			if (url.searchParams.has(key)) {
				url.searchParams.delete(key);
				changed = true;
			}
		});

		if (url.hash === '#zymarg-write-review') {
			url.hash = '';
			changed = true;
		}

		if (!changed) return;

		var clean = url.pathname + (url.search ? url.search : '') + (url.hash ? url.hash : '');
		window.history.replaceState(window.history.state, document.title, clean);
	}

	/* ------------------------------------------------------------------
	 * Filters (All / Media)
	 * ---------------------------------------------------------------- */
	function initFilters(root) {
		var pills = root.querySelectorAll('.zymarg-filter-pill');
		if (!pills.length) return;

		pills.forEach(function (pill) {
			pill.addEventListener('click', function () {
				pills.forEach(function (p) { p.classList.remove('is-active'); });
				pill.classList.add('is-active');

				var filter = pill.dataset.filter || 'all';
				var feed   = root.querySelector('.zymarg-feed');
				if (!feed) return;

				if (filter === 'all') {
					feed.querySelectorAll('.zymarg-review-card').forEach(function (c) {
						c.style.display = '';
					});
				} else if (filter === 'media') {
					feed.querySelectorAll('.zymarg-review-card').forEach(function (c) {
						c.style.display = c.classList.contains('has-media') ? '' : 'none';
					});
				}
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Sort
	 *
	 * For WooCommerce mode (product_id > 0): reload the feed via AJAX
	 * so ordering is applied by MySQL, not just on the loaded subset.
	 *
	 * For Manual mode (product_id === 0): sort the already-rendered cards
	 * in the DOM (same as before — there's no server to call).
	 * ---------------------------------------------------------------- */
	function initSort(root) {
		var sel  = root.querySelector('.zymarg-sort-select');
		var feed = root.querySelector('.zymarg-feed');
		if (!sel || !feed) return;

		sel.addEventListener('change', function () {
			var mode      = sel.value;
			var productId = parseInt(feed.dataset.productId || '0', 10);

			if (productId && CFG.ajaxUrl) {
				// WooCommerce mode — reload page 1 from the server with new sort.
				var btn     = root.querySelector('.zymarg-btn-load-more');
				var perPage = btn ? parseInt(btn.dataset.perPage || '5', 10) : 5;

				var data = new FormData();
				data.set('action',      'zymarg_load_reviews');
				data.set('_ajax_nonce', CFG.loadNonce || '');
				data.set('product_id',  String(productId));
				data.set('page',        '1');
				data.set('per_page',    String(perPage));
				data.set('filter',      currentFilter(root));
				data.set('sort',        mode);

				if (btn) { btn.disabled = true; }

				fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							feed.innerHTML = res.html || '';
							// Re-init reply forms on freshly injected cards.
							initReplyForms(root);
							initVoteButtons(root);
							initDotsMenus(root);
							// Reset load-more state.
							if (btn) {
								btn.dataset.page = '2';
								btn.dataset.sort = mode;
								btn.style.display = res.has_more ? '' : 'none';
								btn.disabled = false;
							}
							// Update pagination count.
							updatePaginationCount(root, res.loaded_count || perPage, res.total_count || 0);
						} else {
							if (btn) btn.disabled = false;
						}
					})
					.catch(function () {
						if (btn) btn.disabled = false;
					});

			} else {
				// Manual mode — DOM sort only.
				var cards = Array.prototype.slice.call(feed.querySelectorAll('.zymarg-review-card'));
				cards.sort(function (a, b) {
					var ar = ratingFromCard(a);
					var br = ratingFromCard(b);
					var ad = dateFromCard(a);
					var bd = dateFromCard(b);
					if (mode === 'highest') return br - ar;
					if (mode === 'lowest')  return ar - br;
					return bd - ad;
				});
				cards.forEach(function (c) { feed.appendChild(c); });
			}
		});
	}

	function currentFilter(root) {
		var active = root.querySelector('.zymarg-filter-pill.is-active');
		return active ? (active.dataset.filter || 'all') : 'all';
	}

	function ratingFromCard(card) {
		return card.querySelectorAll('.zymarg-stars .zymarg-star.is-filled').length;
	}
	function dateFromCard(card) {
		var d = card.querySelector('.zymarg-review-date');
		if (!d) return 0;
		var t = Date.parse(d.textContent.trim());
		return isNaN(t) ? 0 : t;
	}

	/* ------------------------------------------------------------------
	 * Pagination count helper
	 * ---------------------------------------------------------------- */
	function updatePaginationCount(root, loaded, total) {
		var counter = root.querySelector('.zymarg-pagination-count');
		if (!counter || !total) return;
		counter.textContent = 'Showing ' + loaded + ' of ' + total + ' reviews';
	}

	/* ------------------------------------------------------------------
	 * Load more
	 * ---------------------------------------------------------------- */
	function initLoadMore(root) {
		var btn = root.querySelector('.zymarg-btn-load-more');
		if (!btn) return;

		btn.addEventListener('click', function () {
			var feed       = root.querySelector('.zymarg-feed');
			var productId  = feed ? parseInt(feed.dataset.productId || '0', 10) : 0;
			if (!CFG.ajaxUrl) return;

			var page       = parseInt(btn.dataset.page     || '2', 10);
			var perPage    = parseInt(btn.dataset.perPage  || '5', 10);
			var sort       = btn.dataset.sort              || feed.dataset.sort || 'recent';
			var filter     = currentFilter(root);

			btn.disabled    = true;
			var originalTxt = btn.textContent;
			btn.textContent = (CFG.i18n && CFG.i18n.loading) || 'Loading…';

			var data = new FormData();
			data.set('action',      'zymarg_load_reviews');
			data.set('_ajax_nonce', CFG.loadNonce || '');
			data.set('product_id',  String(productId));
			data.set('page',        String(page));
			data.set('per_page',    String(perPage));
			data.set('filter',      filter);
			data.set('sort',        sort);

			// Manual mode: pass all reviews as JSON so server can paginate.
			if (!productId && feed && feed.dataset.manualReviews) {
				data.set('manual_reviews', feed.dataset.manualReviews);
			}

			fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						if (res.html) {
							feed.insertAdjacentHTML('beforeend', res.html);
							// Re-init reply forms on newly injected cards.
							initReplyForms(root);
							initVoteButtons(root);
							initDotsMenus(root);
						}
						// Update pagination count.
						if (res.loaded_count && res.total_count) {
							updatePaginationCount(root, res.loaded_count, res.total_count);
						}
						if (res.has_more) {
							btn.dataset.page = String(res.next_page);
							btn.disabled = false;
							btn.textContent = originalTxt;
						} else {
							btn.style.display = 'none';
						}
					} else {
						btn.disabled = false;
						btn.textContent = originalTxt;
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = originalTxt;
				});
		});
	}

	/* ------------------------------------------------------------------
	 * Store owner reply forms
	 *
	 * Toggle open/close reply form per card. Submit via AJAX.
	 * Only rendered for users with manage_woocommerce capability (PHP-gated),
	 * so no capability check needed in JS.
	 * ---------------------------------------------------------------- */
	function initReplyForms(root) {
		// Toggle buttons — open/close the reply textarea.
		var toggleBtns = root.querySelectorAll('.zymarg-btn-reply-toggle');
		toggleBtns.forEach(function (btn) {
			// Avoid double-binding if initReplyForms is called again after Load More.
			if (btn.dataset.replyBound) return;
			btn.dataset.replyBound = '1';

			btn.addEventListener('click', function () {
				var commentId = btn.dataset.commentId;
				var wrap      = document.getElementById('zymarg-reply-form-' + commentId);
				if (!wrap) return;
				var isOpen = wrap.classList.contains('is-open');
				wrap.classList.toggle('is-open', !isOpen);
				if (!isOpen) {
					var ta = wrap.querySelector('.zymarg-reply-textarea');
					if (ta) ta.focus();
				}
			});
		});

		// Cancel buttons.
		var cancelBtns = root.querySelectorAll('.zymarg-btn-reply-cancel');
		cancelBtns.forEach(function (btn) {
			if (btn.dataset.replyBound) return;
			btn.dataset.replyBound = '1';

			btn.addEventListener('click', function () {
				var wrap = btn.closest('.zymarg-reply-form-wrap');
				if (wrap) {
					wrap.classList.remove('is-open');
					var ta = wrap.querySelector('.zymarg-reply-textarea');
					if (ta) ta.value = '';
					var msg = wrap.querySelector('.zymarg-reply-msg');
					if (msg) { msg.textContent = ''; msg.className = 'zymarg-reply-msg'; }
				}
			});
		});

		// Reply forms — submit.
		var replyForms = root.querySelectorAll('.zymarg-reply-form');
		replyForms.forEach(function (form) {
			if (form.dataset.replyBound) return;
			form.dataset.replyBound = '1';

			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				if (!CFG.ajaxUrl) return;

				var submitBtn   = form.querySelector('.zymarg-btn-reply-submit');
				var msg         = form.querySelector('.zymarg-reply-msg');
				var bodyEl      = form.querySelector('.zymarg-reply-textarea');
				var commentIdEl = form.querySelector('input[name="comment_id"]');
				var nonceEl     = form.querySelector('input[name="_ajax_nonce"]');

				if (!bodyEl || !bodyEl.value.trim()) {
					setMessage(msg, 'Please write a reply.', 'error');
					return;
				}

				submitBtn.disabled   = true;
				var origLabel        = submitBtn.textContent;
				submitBtn.textContent = (CFG.i18n && CFG.i18n.submitting) || 'Posting…';
				setMessage(msg, '', '');

				var data = new FormData();
				data.set('action',      'zymarg_reply_review');
				data.set('_ajax_nonce', nonceEl ? nonceEl.value : (CFG.replyNonce || ''));
				data.set('comment_id',  commentIdEl ? commentIdEl.value : '');
				data.set('reply_body',  bodyEl.value.trim());

				fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							// Inject the new reply HTML into the card's replies container.
							var card     = form.closest('.zymarg-review-card');
							var repliesWrap = card && card.querySelector('.zymarg-replies');
							// A held reply is not public yet, so the server sends no
							// markup and there is nothing to inject.
							if (res.html) {
								if (!repliesWrap) {
									// Create replies container if none existed.
									repliesWrap = document.createElement('div');
									repliesWrap.className = 'zymarg-replies';
									var mediaDiv = card.querySelector('.zymarg-review-photos');
									var anchor   = mediaDiv || card.querySelector('.zymarg-review-body');
									if (anchor && anchor.nextSibling) {
										card.insertBefore(repliesWrap, anchor.nextSibling);
									} else if (anchor) {
										card.appendChild(repliesWrap);
									}
								}

								// Match the server-side ordering: a seller reply joins the
								// pinned group at the top, ahead of the first customer
								// reply. Everything else appends.
								var firstCustomer = (res.is_owner && CFG.sellerReplyFirst)
									? repliesWrap.querySelector('.zymarg-reply:not(.zymarg-reply--owner)')
									: null;

								if (firstCustomer) {
									firstCustomer.insertAdjacentHTML('beforebegin', res.html);
								} else {
									repliesWrap.insertAdjacentHTML('beforeend', res.html);
								}
							}
							setMessage(msg, res.message || 'Reply posted.', 'success');
							bodyEl.value = '';
							setTimeout(function () {
								var wrap = form.closest('.zymarg-reply-form-wrap');
								if (wrap) wrap.classList.remove('is-open');
								setMessage(msg, '', '');
							}, 1600);
						} else {
							setMessage(msg, (res && res.message) || ((CFG.i18n && CFG.i18n.genericErr) || 'Error'), 'error');
						}
						submitBtn.disabled    = false;
						submitBtn.textContent = origLabel;
					})
					.catch(function () {
						setMessage(msg, (CFG.i18n && CFG.i18n.genericErr) || 'Error', 'error');
						submitBtn.disabled    = false;
						submitBtn.textContent = origLabel;
					});
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Media file counter (UX feedback)
	 * ---------------------------------------------------------------- */
	function initMediaCounter(root) {
		var input  = root.querySelector('input[type="file"][name="media[]"]');
		var label  = root.querySelector('.zymarg-media-count');
		if (!input || !label) return;

		var defaultText = label.textContent;
		input.addEventListener('change', function () {
			var n = input.files ? input.files.length : 0;
			if (n === 0)      { label.textContent = defaultText; }
			else if (n === 1) { label.textContent = '1 file selected'; }
			else              { label.textContent = n + ' files selected'; }
		});
	}

	/* ------------------------------------------------------------------
	 * Reveal-form-on-URL + smooth scroll
	 * ---------------------------------------------------------------- */
	function maybeRevealAndScroll(root) {
		var qs = window.location.search || '';
		if (qs.indexOf('zymarg_review=1') === -1) return;

		var section = root.querySelector('#zymarg-write-review');
		if (!section) return;

		section.style.display = '';

		setTimeout(function () {
			try {
				section.scrollIntoView({ behavior: 'smooth', block: 'start' });
			} catch (e) {
				section.scrollIntoView();
			}
		}, 200);
	}

	/* ------------------------------------------------------------------
	 * Like / Dislike vote buttons
	 * ---------------------------------------------------------------- */
	function initVoteButtons(root) {
		var btns = root.querySelectorAll('.zymarg-btn-vote');
		btns.forEach(function (btn) {
			if (btn.dataset.voteBound) return;
			btn.dataset.voteBound = '1';

			btn.addEventListener('click', function () {
				if (!CFG.ajaxUrl) return;

				// Guests have nowhere to store a vote. Rather than fire a request
				// that is guaranteed to come back "Unauthorized", say so.
				var bar = btn.closest('.zymarg-interaction-bar');
				if (bar && bar.dataset.requiresLogin === '1') {
					var note = bar.querySelector('.zymarg-vote-note');
					if (!note) {
						note = document.createElement('span');
						note.className = 'zymarg-vote-note';
						bar.appendChild(note);
					}
					note.textContent = (CFG.i18n && CFG.i18n.login_to_react) || 'Please log in to react.';
					return;
				}

				var commentId = btn.dataset.commentId || '';
				var voteType  = btn.dataset.vote || '';     // 'like' | 'dislike'
				var nonce     = btn.dataset.nonce  || '';
				var card      = btn.closest('.zymarg-review-card');
				if (!card) return;

				// Determine current state.
				var wasActive = btn.classList.contains('is-active');

				var data = new FormData();
				data.set('action',      'zymarg_review_vote');
				data.set('_ajax_nonce', nonce);
				data.set('comment_id',  commentId);
				data.set('vote',        wasActive ? 'remove' : voteType); // toggle off if already active

				fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) return;
						applyCardVoteState(card, res);

						// v1.2.0 - if the media viewer is open on this same review,
						// its counters have to move too. One result, both surfaces.
						if (window.zymargMvSync) { window.zymargMvSync(commentId, res); }
					})
					.catch(function () {});
			});
		});
	}

	/* Paint a review card's vote controls from a zymarg_review_vote response.
	 *
	 * v1.2.0 - lifted out of the click handler so the media viewer can reuse the
	 * exact same painting logic instead of keeping a second, drifting copy. The
	 * viewer and the card underneath it must never disagree about vote state.
	 */
	function applyCardVoteState(card, res) {
		if (!card || !res) return;

		var newVote   = res.vote || '';   // '' | 'like' | 'dislike'
		var likeCount = parseInt(res.likes || '0', 10);

		var likeBtn    = card.querySelector('.zymarg-btn-like');
		var dislikeBtn = card.querySelector('.zymarg-btn-dislike');
		var countEl    = card.querySelector('.zymarg-like-count');

		// Update like button state.
		if (likeBtn) {
			var likeActive = newVote === 'like';
			likeBtn.classList.toggle('is-active', likeActive);
			likeBtn.setAttribute('aria-pressed', likeActive ? 'true' : 'false');
			likeBtn.innerHTML = likeActive
				? '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>'
				: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 21h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2zM9 9l4.34-4.34L12 10h9v2l-3 7H9V9zM1 9h4v12H1z"/></svg>';
		}

		// Update dislike button state.
		if (dislikeBtn) {
			var dislikeActive = newVote === 'dislike';
			dislikeBtn.classList.toggle('is-active', dislikeActive);
			dislikeBtn.setAttribute('aria-pressed', dislikeActive ? 'true' : 'false');
			dislikeBtn.innerHTML = dislikeActive
				? '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z"/></svg>'
				: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm0 12l-4.34 4.34L12 14H3v-2l3-7h9v10zm4-12h2v12h-2V3z"/></svg>';
		}

		// Update helpful count text.
		if (countEl) {
			if (likeCount > 0 || newVote === 'like') {
				countEl.textContent = 'Helpful (' + likeCount + ')';
				countEl.style.display = '';
			} else {
				countEl.textContent = '';
				countEl.style.display = 'none';
			}
		}

		// Update dislike count text.
		var dislikeCount = parseInt(res.dislikes || '0', 10);
		var dislikeCountEl = card.querySelector('.zymarg-dislike-count');
		if (dislikeCountEl) {
			if (dislikeCount > 0 || newVote === 'dislike') {
				dislikeCountEl.textContent = String(dislikeCount);
				dislikeCountEl.style.display = '';
			} else {
				dislikeCountEl.textContent = '';
				dislikeCountEl.style.display = 'none';
			}
		}
	}

	// Shared with the media viewer IIFE at the foot of this file.
	window.zymargApplyCardVote = applyCardVoteState;

	/* ------------------------------------------------------------------
	 * Three dot menus — open/close dropdown
	 * ---------------------------------------------------------------- */
	function initDotsMenus(root) {
		var dotsBtns = root.querySelectorAll('.zymarg-btn-dots');
		dotsBtns.forEach(function (btn) {
			if (btn.dataset.dotsBound) return;
			btn.dataset.dotsBound = '1';

			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var dropdown = btn.nextElementSibling;
				if (!dropdown) return;
				var isOpen = dropdown.classList.contains('is-open');

				// Close all other open dropdowns first.
				root.querySelectorAll('.zymarg-dots-dropdown.is-open').forEach(function (d) {
					d.classList.remove('is-open');
					var b = d.previousElementSibling;
					if (b) b.setAttribute('aria-expanded', 'false');
				});

				if (!isOpen) {
					dropdown.classList.add('is-open');
					btn.setAttribute('aria-expanded', 'true');
				}
			});
		});

		// Close dropdowns when clicking outside.
		document.addEventListener('click', function () {
			root.querySelectorAll('.zymarg-dots-dropdown.is-open').forEach(function (d) {
				d.classList.remove('is-open');
				var b = d.previousElementSibling;
				if (b) b.setAttribute('aria-expanded', 'false');
			});
		});

		// Close on ESC key.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				root.querySelectorAll('.zymarg-dots-dropdown.is-open').forEach(function (d) {
					d.classList.remove('is-open');
					var b = d.previousElementSibling;
					if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
				});
			}
		});
	}

	/* ------------------------------------------------------------------
	 * Report Abuse modal
	 *
	 * The modal is built in JS and appended to document.body — NOT
	 * inside the widget div. This is critical because Elementor applies
	 * CSS transforms to sections/columns which create new stacking
	 * contexts, breaking position:fixed on any descendant element.
	 * By appending to body we guarantee position:fixed is relative to
	 * the viewport as intended.
	 * ---------------------------------------------------------------- */
	function initReportModal(root) {
		// Build modal once per page (shared across all widget instances).
		var modal = document.getElementById('zymarg-report-modal-global');
		if (!modal) {
			modal = document.createElement('div');
			modal.id        = 'zymarg-report-modal-global';
			modal.className = 'zymarg-report-modal-overlay';
			modal.setAttribute('role',       'dialog');
			modal.setAttribute('aria-modal', 'true');
			modal.setAttribute('aria-labelledby', 'zymarg-report-modal-title-global');
			modal.setAttribute('hidden', '');
			modal.innerHTML =
				'<div class="zymarg-report-modal">' +
					'<div class="zymarg-report-modal-icon" aria-hidden="true">' +
						'<svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor"><path d="M1 21L12 2l11 19H1zm11-3h2v-2h-2v2zm0-4h2v-4h-2v4z"/></svg>' +
					'</div>' +
					'<h3 class="zymarg-report-modal-title" id="zymarg-report-modal-title-global">Report this review?</h3>' +
					'<p class="zymarg-report-modal-body">This review will be flagged for moderation. False reports may result in account restrictions.</p>' +
					'<div class="zymarg-report-modal-actions">' +
						'<button type="button" class="zymarg-report-modal-btn zymarg-report-modal-btn--danger zymarg-report-modal-confirm">Report Abuse</button>' +
						'<button type="button" class="zymarg-report-modal-btn zymarg-report-modal-btn--outline zymarg-report-modal-cancel">Cancel</button>' +
					'</div>' +
					'<div class="zymarg-report-modal-msg" role="status" aria-live="polite"></div>' +
				'</div>';
			document.body.appendChild(modal);

			// Wire cancel + outside-click + ESC once globally.
			modal.querySelector('.zymarg-report-modal-cancel').addEventListener('click', closeModal);
			modal.addEventListener('click', function (e) {
				if (e.target === modal) closeModal();
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
			});
		}

		var confirmBtn = modal.querySelector('.zymarg-report-modal-confirm');
		var modalMsg   = modal.querySelector('.zymarg-report-modal-msg');

		var pendingCommentId = null;
		var pendingNonce     = null;
		var pendingDotsItem  = null;

		function openModal(commentId, nonce, dotsItem) {
			pendingCommentId = commentId;
			pendingNonce     = nonce;
			pendingDotsItem  = dotsItem;
			if (modalMsg) { modalMsg.textContent = ''; modalMsg.className = 'zymarg-modal-msg'; }
			confirmBtn.disabled = false;
			confirmBtn.textContent = 'Report Abuse';
			modal.removeAttribute('hidden');
			document.body.style.overflow = 'hidden';
			setTimeout(function () { confirmBtn.focus(); }, 50);
		}

		function closeModal() {
			modal.setAttribute('hidden', '');
			document.body.style.overflow = '';
			pendingCommentId = null;
			pendingNonce     = null;
			pendingDotsItem  = null;
		}

		// Wire report buttons — including ones injected after Load More.
		function bindReportBtns() {
			root.querySelectorAll('.zymarg-dots-item--report').forEach(function (btn) {
				if (btn.dataset.reportBound) return;
				btn.dataset.reportBound = '1';

				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					var dropdown = btn.closest('.zymarg-dots-dropdown');
					if (dropdown) {
						dropdown.classList.remove('is-open');
						var dotsBtn = dropdown.previousElementSibling;
						if (dotsBtn) dotsBtn.setAttribute('aria-expanded', 'false');
					}
					openModal(btn.dataset.commentId, btn.dataset.nonce, btn);
				});
			});
		}
		bindReportBtns();

		// Re-bind after Load More injects new cards.
		var feed = root.querySelector('.zymarg-feed');
		if (feed && window.MutationObserver) {
			var obs = new MutationObserver(function () { bindReportBtns(); });
			obs.observe(feed, { childList: true });
		}

		// Confirm — fire AJAX report.
		// We use a named handler so we can remove and re-add cleanly per widget.
		if (confirmBtn._zymargHandler) {
			confirmBtn.removeEventListener('click', confirmBtn._zymargHandler);
		}
		confirmBtn._zymargHandler = function () {
			if (!pendingCommentId || !CFG.ajaxUrl) return;

			confirmBtn.disabled      = true;
			confirmBtn.textContent   = (CFG.i18n && CFG.i18n.submitting) || 'Submitting…';
			if (modalMsg) { modalMsg.textContent = ''; modalMsg.className = 'zymarg-modal-msg'; }

			var data = new FormData();
			data.set('action',      'zymarg_report_review');
			data.set('_ajax_nonce', pendingNonce || '');
			data.set('comment_id',  pendingCommentId);

			fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						if (pendingDotsItem) {
							var reported = document.createElement('span');
							reported.className = 'zymarg-dots-item zymarg-dots-item--reported';
							reported.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6V20h2v-6h12l-2-4 2-4H6V6H4z"/></svg> Reported';
							pendingDotsItem.replaceWith(reported);
						}
						closeModal();
					} else {
						confirmBtn.disabled      = false;
						confirmBtn.textContent   = 'Report Abuse';
						if (modalMsg) {
							modalMsg.textContent = (res && res.message) || 'Something went wrong.';
							modalMsg.className   = 'zymarg-modal-msg is-error';
						}
					}
				})
				.catch(function () {
					confirmBtn.disabled    = false;
					confirmBtn.textContent = 'Report Abuse';
					if (modalMsg) {
						modalMsg.textContent = (CFG.i18n && CFG.i18n.genericErr) || 'Something went wrong.';
						modalMsg.className   = 'zymarg-modal-msg is-error';
					}
				});
		};
		confirmBtn.addEventListener('click', confirmBtn._zymargHandler);
	}
})();


/* --------------------------------------------------------------------------
   v1.2.0 - Review media viewer.

   Replaces the v1.1.17 flat gallery. Media is browsed on two axes:

     horizontal  media within the review currently being read
     vertical    the next / previous review that has media

   Reviews with no media are absent from the payload entirely, so vertical
   navigation can never land on an empty slide.

   Two visual states, one component:

     fullscreen  backdrop, page scroll locked, review sheet available
     mini        floating draggable/resizable player (desktop >=1024px),
                 no backdrop, page scroll released, caption strip only

   Switching state only toggles classes on the root. The media element is never
   re-parented, because moving a <video> makes the browser tear it down and
   restart playback at 0:00, losing the timestamp and the mute preference.

   One viewer is built per page and shared by every widget instance, the same
   pattern the Report Abuse modal uses.
   -------------------------------------------------------------------------- */
(function () {
	'use strict';

	var MINI_MIN_W = 240;
	var MINI_MAX_W = 640;
	var MINI_MIN_H = 135;
	var MINI_MAX_H = 400;
	var MINI_BREAKPOINT = 1024;

	var root      = null;   // .zymarg-mv
	var reviews   = [];     // grouped payload for the widget that opened us
	var rIdx      = 0;      // review index
	var mIdx      = 0;      // media index within that review
	var mode      = 'fullscreen';
	var userMuted = false;  // videos autoplay unmuted; this persists once set
	var lastFocus = null;
	var originId  = null;   // review id we opened from, for scroll restore
	var preloaded = [];     // keep Image() refs alive long enough to warm cache

	var CFG = window.ZymargReviews || {};

	function esc(str) {
		return String(str === undefined || str === null ? '' : str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function starsHtml(rating) {
		var out = '';
		for (var i = 1; i <= 5; i++) {
			out += '<svg class="zymarg-mv__star--' + (i <= rating ? 'on' : 'off') + '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
				'<path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.81 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
		}
		return out;
	}

	function t(key, fallback) {
		return (CFG.i18n && CFG.i18n[key]) || fallback;
	}

	function cur()    { return reviews[rIdx] || null; }
	function curMed() { var r = cur(); return r && r.media ? (r.media[mIdx] || null) : null; }

	/* ---------------------------------------------------------------- build */

	function build() {
		if (root) return root;

		root = document.createElement('div');
		root.className = 'zymarg-mv is-fullscreen';
		root.id = 'zymarg-mv-global';
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.setAttribute('aria-label', 'Customer photos and videos');
		root.hidden = true;

		root.innerHTML =
			'<div class="zymarg-mv__backdrop"></div>' +
			'<div class="zymarg-mv__frame">' +
				'<div class="zymarg-mv__stage">' +
					'<div class="zymarg-mv__media"></div>' +

					'<button type="button" class="zymarg-mv__btn zymarg-mv__nav zymarg-mv__nav--prev" aria-label="Previous photo or video">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></button>' +
					'<button type="button" class="zymarg-mv__btn zymarg-mv__nav zymarg-mv__nav--next" aria-label="Next photo or video">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6 8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></button>' +
					'<button type="button" class="zymarg-mv__btn zymarg-mv__nav zymarg-mv__nav--down" aria-label="Previous reviewer">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg></button>' +
					'<button type="button" class="zymarg-mv__btn zymarg-mv__nav zymarg-mv__nav--up" aria-label="Next reviewer">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg></button>' +

					'<button type="button" class="zymarg-mv__btn zymarg-mv__mute" aria-label="Mute video">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"></svg></button>' +
					'<button type="button" class="zymarg-mv__btn zymarg-mv__mini" aria-label="Shrink to mini player">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 11h-8v6h8v-6zm4 10V3H1v18h22zM3 5h18v14H3V5z"/></svg></button>' +
					'<button type="button" class="zymarg-mv__btn zymarg-mv__close" aria-label="Close viewer">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>' +

					'<div class="zymarg-mv__pos" aria-live="polite">' +
						'<span class="zymarg-mv__pos-review"></span>' +
						'<span class="zymarg-mv__pos-media"></span>' +
					'</div>' +

					'<div class="zymarg-mv__hover">' +
						'<div class="zymarg-mv__hover-top">' +
							'<span class="zymarg-mv__stars"></span>' +
							'<span class="zymarg-mv__name"></span>' +
							'<span class="zymarg-mv__verified"></span>' +
						'</div>' +
						'<p class="zymarg-mv__hover-body"></p>' +
					'</div>' +

					'<div class="zymarg-mv__resize" role="separator" aria-label="Resize mini player"></div>' +
				'</div>' +

				'<div class="zymarg-mv__sheet">' +
					'<div class="zymarg-mv__author">' +
						'<span class="zymarg-mv__stars"></span>' +
						'<span class="zymarg-mv__name"></span>' +
						'<span class="zymarg-mv__verified"></span>' +
						'<span class="zymarg-mv__date"></span>' +
					'</div>' +
					'<a class="zymarg-mv__product" href="#"></a>' +
					'<span class="zymarg-mv__variation"></span>' +
					'<p class="zymarg-mv__body"></p>' +
					'<button type="button" class="zymarg-mv__expand"></button>' +
					'<div class="zymarg-mv__actions">' +
						'<button type="button" class="zymarg-mv__act zymarg-mv__act--like" aria-label="Helpful" aria-pressed="false">' +
							'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2zM9 9l4.34-4.34L12 10h9v2l-3 7H9V9zM1 9h4v12H1z"/></svg></button>' +
						'<span class="zymarg-mv__count zymarg-mv__count--like"></span>' +
						'<button type="button" class="zymarg-mv__act zymarg-mv__act--dislike" aria-label="Not helpful" aria-pressed="false">' +
							'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm0 12l-4.34 4.34L12 14H3v-2l3-7h9v10zm4-12h2v12h-2V3z"/></svg></button>' +
						'<span class="zymarg-mv__count zymarg-mv__count--dislike"></span>' +
						'<button type="button" class="zymarg-mv__act zymarg-mv__act--report" aria-label="Report abuse">' +
							'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6V20h2v-6h12l-2-4 2-4H6V6H4z"/></svg></button>' +
					'</div>' +
				'</div>' +

				'<div class="zymarg-mv__caption" tabindex="0" role="button" aria-label="Drag to move the mini player. Hold Shift and press the arrow keys to move it with the keyboard.">' +
					'<span class="zymarg-mv__stars"></span>' +
					'<span class="zymarg-mv__name"></span>' +
				'</div>' +
			'</div>';

		document.body.appendChild(root);

		var q = function (sel) { return root.querySelector(sel); };

		q('.zymarg-mv__close').addEventListener('click', close);
		q('.zymarg-mv__mini').addEventListener('click', function () {
			setMode(mode === 'mini' ? 'fullscreen' : 'mini');
		});
		q('.zymarg-mv__nav--prev').addEventListener('click', function () { goMedia(-1); });
		q('.zymarg-mv__nav--next').addEventListener('click', function () { goMedia(1); });
		q('.zymarg-mv__nav--up').addEventListener('click', function () { goReview(1); });
		q('.zymarg-mv__nav--down').addEventListener('click', function () { goReview(-1); });
		q('.zymarg-mv__mute').addEventListener('click', toggleMute);
		q('.zymarg-mv__expand').addEventListener('click', toggleSheet);

		// Clicking the darkened page area closes, exactly like the old viewer.
		q('.zymarg-mv__backdrop').addEventListener('click', close);

		// Tap the media itself to play/pause a video.
		q('.zymarg-mv__media').addEventListener('click', function () {
			var v = root.querySelector('.zymarg-mv__media video');
			if (!v) return;
			if (v.paused) { v.play().catch(function () {}); } else { v.pause(); }
		});

		q('.zymarg-mv__act--like').addEventListener('click', function () { vote('like'); });
		q('.zymarg-mv__act--dislike').addEventListener('click', function () { vote('dislike'); });
		q('.zymarg-mv__act--report').addEventListener('click', report);

		initTouch(q('.zymarg-mv__frame'));
		initDrag(q('.zymarg-mv__caption'));
		initResize(q('.zymarg-mv__resize'));

		document.addEventListener('keydown', onKey);

		// A browser resized below the mini breakpoint has no business showing a
		// floating player, so fall back to full screen.
		window.addEventListener('resize', function () {
			if (mode === 'mini' && window.innerWidth < MINI_BREAKPOINT) setMode('fullscreen');
			if (mode === 'mini') clampMini();
		});

		return root;
	}

	/* --------------------------------------------------------------- render */

	function render() {
		var r = cur();
		var m = curMed();
		if (!r || !m) return;

		var media = root.querySelector('.zymarg-mv__media');

		// Stop and detach any outgoing video so its buffer is released. Only the
		// active slide ever holds a video source; adjacent ones stay as posters.
		var old = media.querySelector('video');
		if (old) {
			try { old.pause(); } catch (e) {}
			old.removeAttribute('src');
			try { old.load(); } catch (e) {}
		}

		if (m.type === 'video') {
			media.innerHTML = '<video playsinline preload="metadata"' +
				(m.poster ? ' poster="' + esc(m.poster) + '"' : '') +
				' src="' + esc(m.url) + '"></video>';
			root.classList.add('has-video');
			var v = media.querySelector('video');
			playVideo(v);
		} else {
			media.innerHTML = '<img src="' + esc(m.url) + '" alt="' +
				esc('Review media from ' + (r.name || '')) + '">';
			root.classList.remove('has-video');
		}

		paintMeta(r);
		paintPosition(r);
		paintVote(r);
		paintNav();
		preload();
	}

	function paintMeta(r) {
		var stars = starsHtml(parseInt(r.rating, 10) || 0);
		root.querySelectorAll('.zymarg-mv__stars').forEach(function (n) { n.innerHTML = stars; });
		root.querySelectorAll('.zymarg-mv__name').forEach(function (n) { n.textContent = r.name || ''; });
		root.querySelectorAll('.zymarg-mv__verified').forEach(function (n) {
			n.textContent = r.verified ? 'Verified Purchase' : '';
		});

		var date = root.querySelector('.zymarg-mv__date');
		if (date) date.textContent = r.date || '';

		// Store-wide scope only: media rows carry product_title/product_url
		// when they came from a vendor feed (see get_vendor_media() on the PHP
		// side). Single-product scope never sets these, so the link stays
		// hidden there exactly as before.
		var product = root.querySelector('.zymarg-mv__product');
		if (product) {
			if (r.product_title) {
				product.textContent = r.product_title;
				product.href = r.product_url || '#';
				product.style.display = '';
			} else {
				product.style.display = 'none';
			}
		}

		var vari = root.querySelector('.zymarg-mv__variation');
		if (vari) {
			vari.textContent = r.variation || '';
			vari.style.display = r.variation ? '' : 'none';
		}

		var body = root.querySelector('.zymarg-mv__body');
		if (body) body.textContent = r.body || '';

		var hoverBody = root.querySelector('.zymarg-mv__hover-body');
		if (hoverBody) hoverBody.textContent = r.body || '';

		// Collapse back to the preview height whenever the reviewer changes, and
		// only offer "View Review" when there is actually more text to reveal.
		var sheet = root.querySelector('.zymarg-mv__sheet');
		sheet.classList.remove('is-expanded');
		setExpandLabel(false);
		if (body) {
			sheet.classList.toggle('has-overflow', (r.body || '').length > 110);
		}
	}

	function paintPosition(r) {
		var total = reviews.length;
		var pr = root.querySelector('.zymarg-mv__pos-review');
		var pm = root.querySelector('.zymarg-mv__pos-media');
		if (pr) {
			pr.textContent = total > 1 ? ('Review ' + (rIdx + 1) + ' of ' + total) : '';
		}
		if (pm) {
			pm.textContent = (mIdx + 1) + ' / ' + r.media.length;
		}
	}

	function paintNav() {
		var r = cur();
		root.querySelector('.zymarg-mv__nav--prev').disabled = mIdx <= 0;
		root.querySelector('.zymarg-mv__nav--next').disabled = mIdx >= r.media.length - 1;
		root.querySelector('.zymarg-mv__nav--down').disabled = rIdx <= 0;
		root.querySelector('.zymarg-mv__nav--up').disabled   = rIdx >= reviews.length - 1;
	}

	function paintVote(r) {
		var likeBtn = root.querySelector('.zymarg-mv__act--like');
		var disBtn  = root.querySelector('.zymarg-mv__act--dislike');
		var likeC   = root.querySelector('.zymarg-mv__count--like');
		var disC    = root.querySelector('.zymarg-mv__count--dislike');

		var likeOn = r.user_vote === 'like';
		var disOn  = r.user_vote === 'dislike';

		likeBtn.classList.toggle('is-active', likeOn);
		likeBtn.setAttribute('aria-pressed', likeOn ? 'true' : 'false');
		disBtn.classList.toggle('is-active', disOn);
		disBtn.setAttribute('aria-pressed', disOn ? 'true' : 'false');

		var lc = parseInt(r.like_count, 10) || 0;
		var dc = parseInt(r.dislike_count, 10) || 0;
		likeC.textContent = (lc > 0 || likeOn) ? ('Helpful (' + lc + ')') : '';
		disC.textContent  = (dc > 0 || disOn) ? String(dc) : '';

		var rep = root.querySelector('.zymarg-mv__act--report');
		rep.disabled = !!r.reported;
		rep.setAttribute('aria-label', r.reported ? 'Already reported' : 'Report abuse');
	}

	function setExpandLabel(expanded) {
		var btn = root.querySelector('.zymarg-mv__expand');
		if (btn) btn.textContent = expanded ? 'Hide review' : 'View Review';
	}

	function toggleSheet() {
		var sheet = root.querySelector('.zymarg-mv__sheet');
		var next  = !sheet.classList.contains('is-expanded');
		sheet.classList.toggle('is-expanded', next);
		setExpandLabel(next);
	}

	/* ----------------------------------------------------------------- video */

	function playVideo(v) {
		if (!v) return;
		v.muted = userMuted;
		paintMute();
		var p = v.play();
		if (p && p.catch) {
			p.catch(function () {
				// Some browsers and OS power-saving modes refuse unmuted autoplay
				// outright. Rather than show a dead player, fall back to muted and
				// reflect that in the icon so the customer can unmute deliberately.
				userMuted = true;
				v.muted = true;
				paintMute();
				v.play().catch(function () {});
			});
		}
	}

	function toggleMute() {
		userMuted = !userMuted;
		var v = root.querySelector('.zymarg-mv__media video');
		if (v) v.muted = userMuted;
		paintMute();
	}

	function paintMute() {
		var btn = root.querySelector('.zymarg-mv__mute');
		if (!btn) return;
		var svg = btn.querySelector('svg');
		svg.innerHTML = userMuted
			? '<path d="M16.5 12A4.5 4.5 0 0 0 14 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.8 8.8 0 0 0 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3 3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 0 0 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4 9.91 6.09 12 8.18V4z"/>'
			: '<path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.47 4.47 0 0 0 16.5 12zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>';
		btn.setAttribute('aria-label', userMuted ? 'Unmute video' : 'Mute video');
	}

	/* ------------------------------------------------------------ navigation */

	function goMedia(d) {
		var r = cur();
		if (!r) return;
		var next = mIdx + d;
		if (next < 0 || next >= r.media.length) { bounce(); return; }
		mIdx = next;
		render();
	}

	function goReview(d) {
		var next = rIdx + d;
		if (next < 0 || next >= reviews.length) { bounce(); return; }
		rIdx = next;
		mIdx = 0;
		render();
	}

	function bounce() {
		var frame = root.querySelector('.zymarg-mv__frame');
		if (!frame || frame.dataset.bouncing) return;
		frame.dataset.bouncing = '1';
		frame.style.transform = 'scale(.995)';
		setTimeout(function () {
			frame.style.transform = '';
			delete frame.dataset.bouncing;
		}, 120);
	}

	/* -------------------------------------------------------------- preload */

	function preload() {
		preloaded = [];
		var r = cur();
		if (!r) return;

		var urls = [];
		// Neighbours within this review.
		[mIdx - 1, mIdx + 1].forEach(function (i) {
			var it = r.media[i];
			if (it && it.type === 'image') urls.push(it.url);
		});
		// First item of the adjacent reviews, since that is where a vertical
		// swipe lands.
		[rIdx - 1, rIdx + 1].forEach(function (i) {
			var rr = reviews[i];
			var it = rr && rr.media ? rr.media[0] : null;
			if (it && it.type === 'image') urls.push(it.url);
		});

		// Videos are deliberately NOT preloaded. A customer browsing twenty
		// reviews would otherwise pull down hundreds of megabytes; their poster
		// images are already in the payload and cost nothing.
		urls.forEach(function (u) {
			var img = new Image();
			img.src = u;
			preloaded.push(img);
		});
	}

	/* ----------------------------------------------------------------- modes */

	function setMode(next) {
		if (next === 'mini' && window.innerWidth < MINI_BREAKPOINT) return;
		mode = next;

		root.classList.toggle('is-mini', mode === 'mini');
		root.classList.toggle('is-fullscreen', mode === 'fullscreen');

		// Full screen is a modal: the page behind is inert and must not scroll.
		// Mini is picture-in-picture: the whole point is that the customer keeps
		// browsing, so the lock is released and the backdrop goes away.
		document.body.style.overflow = mode === 'fullscreen' ? 'hidden' : '';
		root.setAttribute('aria-modal', mode === 'fullscreen' ? 'true' : 'false');

		var btn = root.querySelector('.zymarg-mv__mini');
		btn.setAttribute('aria-label', mode === 'mini' ? 'Expand to full screen' : 'Shrink to mini player');

		if (mode === 'mini') {
			// Always reopen at the dock corner rather than remembering a previous
			// drag, so the player never reappears somewhere the customer has since
			// forgotten about.
			root.style.setProperty('--zv-mini-w', '320px');
			root.style.setProperty('--zv-mini-h', '180px');
			root.style.setProperty('--zv-mini-right', '20px');
			positionAboveScrollTop();
		}
	}

	/* Measure the theme's scroll-to-top button instead of assuming its size.
	 * ZYMARG OS lets it be resized, moved to the left, or switched off, so a
	 * hardcoded offset would eventually be wrong. */
	function positionAboveScrollTop() {
		var gap = 12;
		var fallback = 76;
		var btn = document.querySelector('.zymarg-scroll-top');

		if (!btn) {
			root.style.setProperty('--zv-mini-bottom', fallback + 'px');
			return;
		}

		var cs = window.getComputedStyle(btn);
		// Sitting on the left, or hidden, means there is nothing to avoid.
		if (cs.display === 'none' || cs.right === 'auto') {
			root.style.setProperty('--zv-mini-bottom', '20px');
			return;
		}

		var rect = btn.getBoundingClientRect();
		var fromBottom = window.innerHeight - rect.top;
		if (!rect.height || fromBottom <= 0) {
			root.style.setProperty('--zv-mini-bottom', fallback + 'px');
			return;
		}
		root.style.setProperty('--zv-mini-bottom', Math.round(fromBottom + gap) + 'px');
	}

	function miniBox() {
		var frame = root.querySelector('.zymarg-mv__frame');
		return frame.getBoundingClientRect();
	}

	function clampMini() {
		var b = miniBox();
		var right  = parseFloat(root.style.getPropertyValue('--zv-mini-right'))  || 20;
		var bottom = parseFloat(root.style.getPropertyValue('--zv-mini-bottom')) || 76;

		right  = Math.min(Math.max(right, 0), Math.max(0, window.innerWidth - b.width));
		bottom = Math.min(Math.max(bottom, 0), Math.max(0, window.innerHeight - b.height));

		root.style.setProperty('--zv-mini-right', Math.round(right) + 'px');
		root.style.setProperty('--zv-mini-bottom', Math.round(bottom) + 'px');
	}

	/* ------------------------------------------------------------ drag/resize */

	function initDrag(handle) {
		var startX = 0, startY = 0, startR = 0, startB = 0, dragging = false;

		handle.addEventListener('pointerdown', function (e) {
			if (mode !== 'mini') return;
			dragging = true;
			root.classList.add('is-dragging');
			startX = e.clientX;
			startY = e.clientY;
			startR = parseFloat(root.style.getPropertyValue('--zv-mini-right'))  || 20;
			startB = parseFloat(root.style.getPropertyValue('--zv-mini-bottom')) || 76;
			try { handle.setPointerCapture(e.pointerId); } catch (err) {}
		});

		handle.addEventListener('pointermove', function (e) {
			if (!dragging) return;
			// Anchored bottom-right, so dragging right/down decreases the offsets.
			root.style.setProperty('--zv-mini-right',  (startR - (e.clientX - startX)) + 'px');
			root.style.setProperty('--zv-mini-bottom', (startB - (e.clientY - startY)) + 'px');
			clampMini();
		});

		function end(e) {
			if (!dragging) return;
			dragging = false;
			root.classList.remove('is-dragging');
			try { handle.releasePointerCapture(e.pointerId); } catch (err) {}
			clampMini();
		}
		handle.addEventListener('pointerup', end);
		handle.addEventListener('pointercancel', end);

		// Keyboard equivalent. Bare arrows already navigate media and reviewers,
		// so moving the window is deliberately gated behind Shift.
		handle.addEventListener('keydown', function (e) {
			if (mode !== 'mini' || !e.shiftKey) return;
			var stepPx = 20;
			var r = parseFloat(root.style.getPropertyValue('--zv-mini-right'))  || 20;
			var b = parseFloat(root.style.getPropertyValue('--zv-mini-bottom')) || 76;
			if (e.key === 'ArrowLeft')  { root.style.setProperty('--zv-mini-right',  (r + stepPx) + 'px'); }
			else if (e.key === 'ArrowRight') { root.style.setProperty('--zv-mini-right',  (r - stepPx) + 'px'); }
			else if (e.key === 'ArrowDown')  { root.style.setProperty('--zv-mini-bottom', (b - stepPx) + 'px'); }
			else if (e.key === 'ArrowUp')    { root.style.setProperty('--zv-mini-bottom', (b + stepPx) + 'px'); }
			else { return; }
			e.preventDefault();
			e.stopPropagation();
			clampMini();
		});
	}

	function initResize(handle) {
		var startX = 0, startY = 0, startW = 0, startH = 0, sizing = false;

		handle.addEventListener('pointerdown', function (e) {
			if (mode !== 'mini') return;
			sizing = true;
			root.classList.add('is-resizing');
			var b = miniBox();
			startX = e.clientX; startY = e.clientY;
			startW = b.width;   startH = b.height;
			try { handle.setPointerCapture(e.pointerId); } catch (err) {}
			e.preventDefault();
		});

		handle.addEventListener('pointermove', function (e) {
			if (!sizing) return;
			// Handle is top-left on a bottom-right anchored box, so moving up and
			// left grows it.
			var w = startW - (e.clientX - startX);
			var h = startH - (e.clientY - startY);
			w = Math.min(Math.max(w, MINI_MIN_W), MINI_MAX_W);
			h = Math.min(Math.max(h, MINI_MIN_H), MINI_MAX_H);
			root.style.setProperty('--zv-mini-w', Math.round(w) + 'px');
			root.style.setProperty('--zv-mini-h', Math.round(h) + 'px');
			clampMini();
		});

		function end(e) {
			if (!sizing) return;
			sizing = false;
			root.classList.remove('is-resizing');
			try { handle.releasePointerCapture(e.pointerId); } catch (err) {}
			clampMini();
		}
		handle.addEventListener('pointerup', end);
		handle.addEventListener('pointercancel', end);
	}

	/* ------------------------------------------------------------------ touch */

	function initTouch(stage) {
		var x0 = null, y0 = null;

		stage.addEventListener('touchstart', function (e) {
			// A touch that starts inside the review sheet is its own scroll area
			// (long reviews scroll internally when expanded), not a media swipe --
			// leaving x0 null here means touchmove/touchend below both ignore it,
			// through the same guard, rather than needing a second check in each.
			if (e.target && e.target.closest && e.target.closest('.zymarg-mv__sheet')) {
				x0 = null; y0 = null;
				return;
			}
			x0 = e.touches[0].clientX;
			y0 = e.touches[0].clientY;
		}, { passive: true });

		// v1.3.1 - touchstart/touchend alone only measure a completed swipe; they
		// never tell the browser to withhold the page's own scrolling. On mobile,
		// document.body.style.overflow = 'hidden' is not enough by itself -- the
		// page still scrolls under the finger via native touch momentum unless a
		// touchmove listener actively cancels it. Full screen is a modal, so the
		// page behind it must not move at all. Mini is deliberately the opposite
		// (picture-in-picture: the customer keeps browsing the page while it
		// floats), so this only ever calls preventDefault() while in full screen.
		//
		// Bound on the frame, not just the stage, because the floating review
		// sheet (v1.3.0) is a sibling of the stage that visually sits on top of
		// the media -- a touch starting there would otherwise miss a
		// stage-only listener entirely and still leak through to the page.
		stage.addEventListener('touchmove', function (e) {
			// x0 is null for a touch that started inside the sheet (see
			// touchstart above), so its own internal scroll is left alone.
			if (mode !== 'fullscreen' || x0 === null || !e.cancelable) {
				return;
			}
			e.preventDefault();
		}, { passive: false } );

		stage.addEventListener('touchend', function (e) {
			if (x0 === null) return;
			var dx = e.changedTouches[0].clientX - x0;
			var dy = e.changedTouches[0].clientY - y0;
			x0 = null; y0 = null;

			// Whichever axis dominated decides the meaning of the gesture, so a
			// slightly diagonal swipe still does the obvious thing.
			if (Math.abs(dx) < 45 && Math.abs(dy) < 45) return;
			if (Math.abs(dx) >= Math.abs(dy)) {
				goMedia(dx < 0 ? 1 : -1);
			} else {
				goReview(dy < 0 ? 1 : -1);
			}
		}, { passive: true });
	}

	/* --------------------------------------------------------------- keyboard */

	function onKey(e) {
		if (!root || root.hidden) return;
		// Shift+arrows belong to the drag handle when the mini player is focused.
		if (e.shiftKey) return;

		// In mini mode the page behind is live and the customer is scrolling it,
		// so swallowing the arrow keys globally would break the page. Only act on
		// keys while focus is actually inside the player. Full screen is a modal,
		// so there it is correct to take them unconditionally.
		if (mode === 'mini' && e.key !== 'Escape' && !root.contains(document.activeElement)) return;

		switch (e.key) {
			case 'Escape':     e.preventDefault(); close();          break;
			case 'ArrowRight': e.preventDefault(); goMedia(1);       break;
			case 'ArrowLeft':  e.preventDefault(); goMedia(-1);      break;
			case 'ArrowDown':  e.preventDefault(); goReview(1);      break;
			case 'ArrowUp':    e.preventDefault(); goReview(-1);     break;
			case 'm': case 'M':
				if (root.classList.contains('has-video')) { e.preventDefault(); toggleMute(); }
				break;
			default: break;
		}
	}

	/* ------------------------------------------------------------ vote/report */

	function toast(msg) {
		var n = root.querySelector('.zymarg-mv__toast');
		if (!n) {
			n = document.createElement('div');
			n.className = 'zymarg-mv__toast';
			n.setAttribute('role', 'status');
			root.querySelector('.zymarg-mv__frame').appendChild(n);
		}
		n.textContent = msg;
		n.classList.add('is-on');
		clearTimeout(n._t);
		n._t = setTimeout(function () { n.classList.remove('is-on'); }, 2600);
	}

	/* A review that has been unapproved or deleted mid-session cannot be voted
	 * on. There is no live push channel to learn about it, so the failed action
	 * is the signal: say so plainly, then move the customer along rather than
	 * leaving them on a dead slide. */
	function dropCurrentReview() {
		reviews.splice(rIdx, 1);
		if (!reviews.length) { close(); return; }
		if (rIdx >= reviews.length) rIdx = reviews.length - 1;
		mIdx = 0;
		render();
	}

	function card() {
		var r = cur();
		if (!r) return null;
		return document.querySelector('.zymarg-review-card[data-comment-id="' + r.review_id + '"]');
	}

	function vote(type) {
		var r = cur();
		if (!r || !CFG.ajaxUrl) return;
		if (!CFG.canReact) { toast(t('login_to_react', 'Please log in to react.')); return; }

		var c = card();
		var nonce = '';
		if (c) {
			var srcBtn = c.querySelector('.zymarg-btn-vote');
			nonce = srcBtn ? (srcBtn.dataset.nonce || '') : '';
		}

		var data = new FormData();
		data.set('action', 'zymarg_review_vote');
		data.set('_ajax_nonce', nonce);
		data.set('comment_id', r.review_id);
		data.set('vote', r.user_vote === type ? 'remove' : type);

		fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (res) { return res.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					toast('This review is no longer available.');
					dropCurrentReview();
					return;
				}
				r.user_vote     = res.vote || '';
				r.like_count    = parseInt(res.likes || '0', 10);
				r.dislike_count = parseInt(res.dislikes || '0', 10);
				paintVote(r);
				// Keep the card underneath in step, using the card's own painter.
				if (c && window.zymargApplyCardVote) window.zymargApplyCardVote(c, res);
			})
			.catch(function () { toast(t('genericErr', 'Something went wrong. Please try again.')); });
	}

	function report() {
		var r = cur();
		if (!r || r.reported) return;
		var c = card();
		// Reuse the card's own Report control so the existing confirmation modal,
		// nonce and success handling all apply unchanged.
		var btn = c ? c.querySelector('.zymarg-dots-item--report') : null;
		if (!btn) { toast(t('genericErr', 'Something went wrong. Please try again.')); return; }
		btn.click();
		r.reported = true;
		paintVote(r);
	}

	/* ------------------------------------------------------------- open/close */

	function open(data, reviewIndex, mediaIndex) {
		build();
		if (!data || !data.length) return;

		reviews = data;
		rIdx = Math.min(Math.max(parseInt(reviewIndex, 10) || 0, 0), reviews.length - 1);
		var r = reviews[rIdx];
		mIdx = Math.min(Math.max(parseInt(mediaIndex, 10) || 0, 0), r.media.length - 1);

		lastFocus = document.activeElement;
		originId  = r.review_id;
		userMuted = false;   // fresh session: videos start with sound

		// Opening from a review card while the mini player is already up keeps the
		// player small, per the interaction spec.
		if (mode !== 'mini') setMode('fullscreen');

		render();
		root.hidden = false;
		root.querySelector('.zymarg-mv__close').focus();
	}

	function close() {
		if (!root || root.hidden) return;

		var v = root.querySelector('.zymarg-mv__media video');
		if (v) { try { v.pause(); } catch (e) {} }

		root.hidden = true;
		document.body.style.overflow = '';
		mode = 'fullscreen';
		root.classList.remove('is-mini');
		root.classList.add('is-fullscreen');

		restoreScroll();

		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
	}

	/* Put the customer back where they were, by element rather than by pixel
	 * offset: Load More may have injected cards above in the meantime, so a
	 * remembered scrollY would no longer point at the same review. */
	function restoreScroll() {
		if (!originId) return;
		var c = document.querySelector('.zymarg-review-card[data-comment-id="' + originId + '"]');
		if (!c) return;

		// The reviews section lives inside a <details> accordion on the single
		// product template. If the customer collapsed it while the mini player was
		// open, the card is hidden and there is nothing to scroll to, so reopen it.
		var acc = c.closest('details');
		if (acc && !acc.open) acc.open = true;

		if (c.scrollIntoView) c.scrollIntoView({ behavior: 'smooth', block: 'center' });
		c.classList.add('is-mv-return');
		setTimeout(function () { c.classList.remove('is-mv-return'); }, 1400);
	}

	/* ------------------------------------------------------------------ wiring */

	// Called by the card vote handler so a vote cast on the card is reflected in
	// an open viewer showing the same review.
	window.zymargMvSync = function (commentId, res) {
		if (!root || root.hidden || !res) return;
		var r = cur();
		if (!r || String(r.review_id) !== String(commentId)) return;
		r.user_vote     = res.vote || '';
		r.like_count    = parseInt(res.likes || '0', 10);
		r.dislike_count = parseInt(res.dislikes || '0', 10);
		paintVote(r);
	};

	window.zymargInitGallery = function (widget) {
		var payload = widget.querySelector('.zymarg-media-reviews-data');
		if (!payload) return;

		var data;
		try { data = JSON.parse(payload.textContent || '[]'); }
		catch (err) { return; }
		if (!data || !data.length) return;

		widget.addEventListener('click', function (e) {
			if (!e.target.closest) return;

			// Strip tiles, "See all" and "+N" address the viewer by coordinates.
			var byCoords = e.target.closest('[data-review-index][data-media-index]');
			if (byCoords && widget.contains(byCoords)) {
				e.preventDefault();
				open(data,
					parseInt(byCoords.getAttribute('data-review-index'), 10) || 0,
					parseInt(byCoords.getAttribute('data-media-index'), 10) || 0);
				return;
			}

			// Tiles inside a review card resolve by (review id, attachment id).
			// Doing the lookup here rather than printing indices into the markup is
			// what lets Load-More-injected cards work with no extra server data.
			var tile = e.target.closest('.zymarg-review-media');
			if (tile && widget.contains(tile)) {
				var host = tile.closest('.zymarg-review-card');
				var cid  = host ? host.getAttribute('data-comment-id') : null;
				var mid  = parseInt(tile.getAttribute('data-media-id'), 10) || 0;

				for (var i = 0; i < data.length; i++) {
					if (String(data[i].review_id) !== String(cid)) continue;
					for (var j = 0; j < data[i].media.length; j++) {
						if (parseInt(data[i].media[j].id, 10) === mid) {
							e.preventDefault();
							open(data, i, j);
							return;
						}
					}
				}
			}
		});
	};
})();
