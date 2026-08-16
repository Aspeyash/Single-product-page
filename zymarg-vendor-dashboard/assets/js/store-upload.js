/**
 * ZYMARG Vendor Dashboard — Store Image upload.
 * -----------------------------------------------------------------------------
 * Click a photo control → picker (Gallery / Camera, via the OS file input —
 * NEVER the WordPress admin Media Library, which vendors have no business
 * opening and which doesn't work as a picker on mobile) → free-form cropper
 * with aspect-ratio chips (Free / 1:1 / 4:3 / 16:9, banner defaults to 4:1) →
 * adaptive compressor that targets a target KB (WebP when supported, JPEG
 * fallback) → AJAX upload that writes the target-specific storage.
 *
 * v1.33.0: generalized from a hardcoded singleton (sidebar avatar only) into
 * a multi-instance controller so the SAME crop+compress+upload flow also
 * powers the Section 5 "Store Profile" banner control. Each instance is
 * described by a small config object (see INSTANCES below) rather than
 * hardcoded element IDs, and `initAll()` re-binds every instance whenever
 * called — including on the `zymarg-vd:section-loaded` SPA-swap event, since
 * the banner control lives inside the SPA-swapped Settings content while the
 * sidebar avatar does not.
 *
 * Zero external deps — native canvas + MediaDevices.
 *
 * Localized config (window.ZymargVDUpload):
 *   ajaxUrl       string
 *   nonce         string
 *   targetKB      number   (default 50)
 *   maxDim        number   (default 800 — caps the final image's longest side)
 *   i18n          object   (label strings)
 *
 * @package ZYMARG_Vendor_Dashboard
 */
(function () {
	'use strict';

	var CFG = window.ZymargVDUpload || {};
	var TARGET_BYTES = (CFG.targetKB || 50) * 1024;
	var MAX_DIM = CFG.maxDim || 800;
	var I = CFG.i18n || {};

	function $(s, c) { return (c || document).querySelector(s); }

	/**
	 * Each entry describes one upload instance by selector, not by a
	 * hardcoded element reference — re-queried fresh every init() call so
	 * SPA-swapped markup (Section 5's banner) is picked up correctly.
	 *
	 *   target       string   posted to the AJAX endpoint so the PHP side
	 *                         knows which meta/attachment slot to write.
	 *   toggleSel     string   the clickable control that opens picker/file input
	 *   pickerSel     string   the Change/Remove popup (optional — banner has none,
	 *                          it always goes straight to file picking since
	 *                          there's no "has photo" cam-icon affordance to toggle)
	 *   fileSel       string   the hidden <input type=file>
	 *   changeSel     string   "Change" button inside the picker (optional)
	 *   removeSel     string   "Remove" button (in or out of a picker)
	 *   defaultRatio  number|null  starting aspect-ratio chip (null = Free)
	 *   onUploaded    function(url) — instance-specific DOM update after a
	 *                 successful upload.
	 *   onRemoved     function(defaultUrl) — instance-specific DOM update
	 *                 after a successful remove.
	 */
	var INSTANCES = [
		{
			target: 'avatar',
			toggleSel: '[data-zvu-toggle]',
			pickerSel: '#zvu-picker',
			fileSel: '#zvu-file',
			changeSel: '[data-zvu-change]',
			removeSel: '[data-zvu-remove]',
			defaultRatio: 1,
			cropTitle: I.cropTitleAvatar || I.cropTitle || 'Crop your store image',
			onUploaded: function (url) {
				var link = $('[data-zvu-toggle]');
				if (!link) { return; }
				var inner = link.querySelector('.zymarg-vendor-store__avatar');
				var cam = link.querySelector('.zymarg-vendor-store__cam');
				if (inner) { inner.remove(); }
				var img = document.createElement('img');
				img.className = 'zymarg-vendor-store__avatar';
				img.alt = '';
				img.width = 44; img.height = 44;
				img.src = url + (url.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
				link.insertBefore(img, cam || null);
				link.classList.add('has-photo');
			},
			onRemoved: function (defaultUrl) {
				var link = $('[data-zvu-toggle]');
				if (!link) { return; }
				var inner = link.querySelector('.zymarg-vendor-store__avatar');
				var cam = link.querySelector('.zymarg-vendor-store__cam');
				if (inner) { inner.remove(); }
				var img = document.createElement('img');
				img.className = 'zymarg-vendor-store__avatar';
				img.alt = '';
				img.width = 44; img.height = 44;
				img.src = defaultUrl + (defaultUrl.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
				link.insertBefore(img, cam || null);
				link.classList.remove('has-photo');
			}
		},
		{
			target: 'banner',
			toggleSel: '[data-zvu-toggle="banner"]',
			pickerSel: null, // no Change/Remove popup — the zone itself opens the file input.
			fileSel: '#zvu-banner-file',
			changeSel: null,
			removeSel: '[data-zvu-remove="banner"]',
			defaultRatio: 4, // 4:1 wide banner
			cropTitle: I.cropTitleBanner || 'Crop your store banner',
			onUploaded: function (url) {
				var zone = $('[data-zvu-zone="banner"]');
				if (!zone) { return; }
				zone.classList.add('has-image');
				var img = zone.querySelector('.zymarg-vs-banner-img');
				if (img) {
					img.src = url + (url.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
				}
			},
			onRemoved: function () {
				var zone = $('[data-zvu-zone="banner"]');
				if (!zone) { return; }
				zone.classList.remove('has-image');
				var img = zone.querySelector('.zymarg-vs-banner-img');
				if (img) { img.src = ''; }
			}
		}
	];

	var boundToggles = new WeakMap();

	function bindInstance(cfg) {
		var toggle = $(cfg.toggleSel);
		var fileInput = $(cfg.fileSel);
		if (!toggle || !fileInput) { return; }

		var picker = cfg.pickerSel ? $(cfg.pickerSel) : null;
		var changeBtn = cfg.changeSel ? $(cfg.changeSel) : null;
		var removeBtn = cfg.removeSel ? $(cfg.removeSel) : null;

		function showPicker() { if (picker) { picker.hidden = false; } }
		function hidePicker() { if (picker) { picker.hidden = true; } }
		function isPickerOpen() { return picker && !picker.hidden; }
		function openFileInput() { fileInput.value = ''; fileInput.click(); }

		// Avoid double-binding the SAME element across repeated init() calls
		// (SPA re-init, or the avatar instance which never gets swapped).
		if (boundToggles.get(toggle) === cfg.target) {
			// Already bound to this exact target — just re-bind file input's
			// change listener too (fresh element after an SPA swap wouldn't
			// hit this path, only a same-DOM re-init would, and re-adding an
			// identical listener is harmless — browsers dedupe identical fn
			// refs, but ours are fresh closures per bindInstance() call, so
			// skip entirely to avoid stacking).
			return;
		}
		boundToggles.set(toggle, cfg.target);

		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			// Instances with a picker (avatar): once a photo is set, clicking
			// opens the Change/Remove popup instead of jumping straight to the
			// file picker. Instances with no picker (banner): always go
			// straight to the OS file picker.
			if (picker && toggle.classList.contains('has-photo')) {
				if (isPickerOpen()) { hidePicker(); } else { showPicker(); }
				return;
			}
			openFileInput();
		});

		if (picker) {
			document.addEventListener('click', function (e) {
				if (!isPickerOpen()) { return; }
				if (e.target.closest && e.target.closest((cfg.pickerSel || '') + ', ' + cfg.toggleSel)) { return; }
				hidePicker();
			});
		}

		if (changeBtn) {
			changeBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hidePicker();
				openFileInput();
			});
		}

		if (removeBtn) {
			removeBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hidePicker();
				var confirmMsg = I.confirmRemove || 'Remove this image? You can upload a new one anytime.';
				if (!window.confirm(confirmMsg)) { return; }

				var fd = new FormData();
				fd.append('action', 'zymarg_vd_remove_store_image');
				fd.append('_wpnonce', CFG.nonce || '');
				fd.append('target', cfg.target);

				fetch(CFG.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success) {
							cfg.onRemoved(res.data && res.data.defaultUrl ? res.data.defaultUrl : '');
						} else {
							alert((res && res.data && res.data.message) || I.removeError || 'Could not remove that image. Please try again.');
						}
					})
					.catch(function () { alert(I.removeError || 'Could not remove that image. Please try again.'); });
			});
		}

		// (Banner has no picker — `if (picker)` above is false for it, so its
		// toggle click always falls through to openFileInput() directly.)

		fileInput.addEventListener('change', function () {
			var f = fileInput.files && fileInput.files[0];
			if (f && /^image\//.test(f.type)) { openCropper(f, cfg); }
		});

		// On (re)init: reflect current has-photo state from existing markup.
		if (cfg.target === 'avatar' && toggle.querySelector('img.zymarg-vendor-store__avatar')) {
			toggle.classList.add('has-photo');
		}
	}

	function initAll() {
		INSTANCES.forEach(bindInstance);
	}

	/* ── Crop modal: free-form + aspect chips ─────────────────────────── */
	function openCropper(file, cfg) {
		var url = URL.createObjectURL(file);
		var ov = buildOverlay(cfg.cropTitle || I.cropTitle || 'Crop your image');
		var body = ov.querySelector('.zvu-modal');
		var foot = ov.querySelector('.zvu-modal__foot');

		// Aspect chips — banner instances get a 4:1 chip added and pre-selected.
		var ratios = document.createElement('div');
		ratios.className = 'zvu-ratios';
		var ratioDefs = [
			{ key: 'free', label: I.ratioFree || 'Free', val: null },
			{ key: '1-1',  label: '1 : 1',  val: 1 },
			{ key: '4-3',  label: '4 : 3',  val: 4 / 3 },
			{ key: '16-9', label: '16 : 9', val: 16 / 9 }
		];
		if (cfg.target === 'banner') {
			ratioDefs.push({ key: '4-1', label: '4 : 1', val: 4 });
		}
		var initialRatio = cfg.defaultRatio || null;
		ratioDefs.forEach(function (r) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'zvu-ratio' + (r.val === initialRatio ? ' is-active' : (null === initialRatio && 'free' === r.key ? ' is-active' : ''));
			b.textContent = r.label;
			b.dataset.ratio = r.key;
			b.addEventListener('click', function () {
				ratios.querySelectorAll('.zvu-ratio').forEach(function (x) { x.classList.remove('is-active'); });
				b.classList.add('is-active');
				applyRatio(r.val);
			});
			ratios.appendChild(b);
		});
		body.insertBefore(ratios, foot);

		// Stage + image + frame + handles
		var stage = document.createElement('div');
		stage.className = 'zvu-stage';
		stage.innerHTML =
			'<img class="zvu-img" alt="">' +
			'<div class="zvu-frame">' +
				'<span class="zvu-handle zvu-handle--nw" data-h="nw"></span>' +
				'<span class="zvu-handle zvu-handle--ne" data-h="ne"></span>' +
				'<span class="zvu-handle zvu-handle--sw" data-h="sw"></span>' +
				'<span class="zvu-handle zvu-handle--se" data-h="se"></span>' +
			'</div>';
		body.insertBefore(stage, ratios);

		var cancel = document.createElement('button');
		cancel.className = 'zvu-btn';
		cancel.textContent = I.cancel || 'Cancel';
		var save = document.createElement('button');
		save.className = 'zvu-btn zvu-btn--primary';
		save.textContent = I.save || 'Save photo';
		foot.appendChild(cancel); foot.appendChild(save);

		var img = stage.querySelector('.zvu-img');
		var frame = stage.querySelector('.zvu-frame');

		// State
		var ratio = initialRatio;
		var imgX = 0, imgY = 0, imgW = 0, imgH = 0; // displayed image bounds in stage
		var fx = 0, fy = 0, fw = 0, fh = 0;        // frame in stage coords

		function close() { URL.revokeObjectURL(url); closeOverlay(ov); }
		ov.querySelector('.zvu-modal__close').addEventListener('click', close);
		cancel.addEventListener('click', close);
		ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });

		// Load image
		var started = false;
		function start() {
			if (started) { return; } started = true;
			layout();
		}
		img.onload = start;
		img.onerror = function () { close(); alert(I.loadErr || 'Could not load that image.'); };
		img.src = url;
		if (img.complete && img.naturalWidth) { start(); }

		function layout() {
			var sw = stage.clientWidth, sh = stage.clientHeight;
			var iw = img.naturalWidth, ih = img.naturalHeight;
			// Fit the image inside the stage with letterbox.
			var s = Math.min(sw / iw, sh / ih);
			imgW = Math.round(iw * s);
			imgH = Math.round(ih * s);
			imgX = Math.round((sw - imgW) / 2);
			imgY = Math.round((sh - imgH) / 2);
			img.style.left = imgX + 'px';
			img.style.top  = imgY + 'px';
			img.style.width  = imgW + 'px';
			img.style.height = imgH + 'px';

			if (ratio) {
				// Start the frame at the requested ratio, as large as fits.
				if (imgW / imgH > ratio) {
					fh = imgH; fw = Math.round(fh * ratio);
				} else {
					fw = imgW; fh = Math.round(fw / ratio);
				}
				fx = imgX + Math.round((imgW - fw) / 2);
				fy = imgY + Math.round((imgH - fh) / 2);
			} else {
				// Free — start the frame at ~80% of the image, centered.
				fw = Math.round(imgW * 0.8);
				fh = Math.round(imgH * 0.8);
				fx = imgX + Math.round((imgW - fw) / 2);
				fy = imgY + Math.round((imgH - fh) / 2);
			}
			renderFrame();
		}

		function renderFrame() {
			frame.style.left = fx + 'px';
			frame.style.top  = fy + 'px';
			frame.style.width  = fw + 'px';
			frame.style.height = fh + 'px';
		}

		function applyRatio(r) {
			ratio = r;
			if (r === null) { return; }
			// Re-fit current frame to the ratio, keeping center.
			var cx = fx + fw / 2, cy = fy + fh / 2;
			var nw, nh;
			if (fw / fh > r) {
				// Too wide → keep height, shrink width.
				nh = fh; nw = nh * r;
			} else {
				nw = fw; nh = nw / r;
			}
			// Clamp within image.
			if (nw > imgW) { nw = imgW; nh = nw / r; }
			if (nh > imgH) { nh = imgH; nw = nh * r; }
			fw = Math.round(nw); fh = Math.round(nh);
			fx = Math.round(cx - fw / 2); fy = Math.round(cy - fh / 2);
			clampFrame();
			renderFrame();
		}

		function clampFrame() {
			if (fw < 32) { fw = 32; }
			if (fh < 32) { fh = 32; }
			if (fw > imgW) { fw = imgW; }
			if (fh > imgH) { fh = imgH; }
			if (fx < imgX) { fx = imgX; }
			if (fy < imgY) { fy = imgY; }
			if (fx + fw > imgX + imgW) { fx = imgX + imgW - fw; }
			if (fy + fh > imgY + imgH) { fy = imgY + imgH - fh; }
		}

		// Drag the frame body
		frame.addEventListener('mousedown', startDragFrame);
		frame.addEventListener('touchstart', startDragFrame, { passive: false });
		function startDragFrame(e) {
			if (e.target.classList && e.target.classList.contains('zvu-handle')) { return; }
			e.preventDefault();
			var p = pt(e);
			var sx0 = fx, sy0 = fy, mx = p.x, my = p.y;
			function move(ev) {
				ev.preventDefault();
				var q = pt(ev);
				fx = sx0 + (q.x - mx); fy = sy0 + (q.y - my);
				clampFrame(); renderFrame();
			}
			function up() {
				document.removeEventListener('mousemove', move);
				document.removeEventListener('touchmove', move);
				document.removeEventListener('mouseup', up);
				document.removeEventListener('touchend', up);
			}
			document.addEventListener('mousemove', move);
			document.addEventListener('touchmove', move, { passive: false });
			document.addEventListener('mouseup', up);
			document.addEventListener('touchend', up);
		}

		// Corner-handle resize
		stage.querySelectorAll('.zvu-handle').forEach(function (h) {
			h.addEventListener('mousedown', function (e) { startResize(e, h.dataset.h); });
			h.addEventListener('touchstart', function (e) { startResize(e, h.dataset.h); }, { passive: false });
		});
		function startResize(e, corner) {
			e.preventDefault(); e.stopPropagation();
			var p = pt(e);
			var sx0 = fx, sy0 = fy, sw0 = fw, sh0 = fh, mx = p.x, my = p.y;
			function move(ev) {
				ev.preventDefault();
				var q = pt(ev), dx = q.x - mx, dy = q.y - my;
				var nx = sx0, ny = sy0, nw = sw0, nh = sh0;
				if (corner === 'se') { nw = sw0 + dx; nh = sh0 + dy; }
				if (corner === 'sw') { nx = sx0 + dx; nw = sw0 - dx; nh = sh0 + dy; }
				if (corner === 'ne') { ny = sy0 + dy; nw = sw0 + dx; nh = sh0 - dy; }
				if (corner === 'nw') { nx = sx0 + dx; ny = sy0 + dy; nw = sw0 - dx; nh = sh0 - dy; }
				if (ratio) {
					// Constrain to aspect: pick the dimension that changed more.
					var byW = nw / ratio;
					var byH = nh * ratio;
					if (Math.abs(byW - nh) < Math.abs(byH - nw)) {
						// Adjust height to match width
						var newH = nw / ratio;
						if (corner === 'nw' || corner === 'ne') { ny = ny - (newH - nh); }
						nh = newH;
					} else {
						var newW = nh * ratio;
						if (corner === 'nw' || corner === 'sw') { nx = nx - (newW - nw); }
						nw = newW;
					}
				}
				fx = Math.round(nx); fy = Math.round(ny);
				fw = Math.round(nw); fh = Math.round(nh);
				clampFrame(); renderFrame();
			}
			function up() {
				document.removeEventListener('mousemove', move);
				document.removeEventListener('touchmove', move);
				document.removeEventListener('mouseup', up);
				document.removeEventListener('touchend', up);
			}
			document.addEventListener('mousemove', move);
			document.addEventListener('touchmove', move, { passive: false });
			document.addEventListener('mouseup', up);
			document.addEventListener('touchend', up);
		}

		function pt(e) {
			var rect = stage.getBoundingClientRect();
			var ev = e.touches ? e.touches[0] : e;
			return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
		}

		save.addEventListener('click', function () {
			save.disabled = true;
			cancel.disabled = true;
			doSaveCrop().then(function (res) {
				return uploadBlob(res.blob, res.ext, cfg.target);
			}).then(function (newUrl) {
				cfg.onUploaded(newUrl);
				setTimeout(close, 400);
			}).catch(function (err) {
				alert((err && err.message) || I.uploadFail || 'Upload failed. Please try again.');
				save.disabled = false; cancel.disabled = false;
			});
		});

		function doSaveCrop() {
			// Map frame (stage coords) → source-image pixels.
			var s = img.naturalWidth / imgW; // pixels per stage-px
			var sx = Math.max(0, Math.round((fx - imgX) * s));
			var sy = Math.max(0, Math.round((fy - imgY) * s));
			var sW = Math.round(fw * s);
			var sH = Math.round(fh * s);
			if (sx + sW > img.naturalWidth)  { sW = img.naturalWidth  - sx; }
			if (sy + sH > img.naturalHeight) { sH = img.naturalHeight - sy; }

			// Cap output to MAX_DIM on the longest side. Banner keeps its wide
			// aspect but the same longest-side cap as avatar (still ≤ ~50KB target).
			var outW = sW, outH = sH;
			var longest = Math.max(sW, sH);
			if (longest > MAX_DIM) {
				var k = MAX_DIM / longest;
				outW = Math.round(sW * k);
				outH = Math.round(sH * k);
			}

			return new Promise(function (resolve) {
				var c = document.createElement('canvas');
				c.width = outW; c.height = outH;
				var ctx = c.getContext('2d');
				ctx.fillStyle = '#ffffff';
				ctx.fillRect(0, 0, outW, outH);
				ctx.drawImage(img, sx, sy, sW, sH, 0, 0, outW, outH);
				adaptiveCompress(c).then(resolve);
			});
		}
	}

	/* ── Adaptive compressor: target ≤ TARGET_BYTES ───────────────────── */
	function adaptiveCompress(canvas) {
		return canSupportWebP().then(function (webp) {
			var mimes = webp ? ['image/webp', 'image/jpeg'] : ['image/jpeg'];
			var qualities = [0.9, 0.85, 0.8, 0.75, 0.7, 0.65, 0.6];
			var scales = [1, 0.85, 0.7, 0.55];
			var best = null;

			function step(si, mi, qi) {
				return new Promise(function (resolve) {
					if (si >= scales.length) { resolve(best); return; }
					if (mi >= mimes.length) { resolve(step(si + 1, 0, 0)); return; }
					if (qi >= qualities.length) { resolve(step(si, mi + 1, 0)); return; }

					var s = scales[si], mime = mimes[mi], q = qualities[qi];
					var c = scaleCanvas(canvas, s);
					c.toBlob(function (blob) {
						if (!blob) { resolve(step(si, mi, qi + 1)); return; }
						if (blob.size <= TARGET_BYTES) {
							resolve({ blob: blob, bytes: blob.size, mime: mime, quality: q, ext: extOf(mime) });
							return;
						}
						if (!best || blob.size < best.bytes) {
							best = { blob: blob, bytes: blob.size, mime: mime, quality: q, ext: extOf(mime) };
						}
						resolve(step(si, mi, qi + 1));
					}, mime, q);
				});
			}
			return step(0, 0, 0);
		});
	}

	function scaleCanvas(src, factor) {
		if (factor === 1) { return src; }
		var c = document.createElement('canvas');
		c.width  = Math.max(64, Math.round(src.width * factor));
		c.height = Math.max(64, Math.round(src.height * factor));
		var ctx = c.getContext('2d');
		ctx.imageSmoothingQuality = 'high';
		ctx.drawImage(src, 0, 0, c.width, c.height);
		return c;
	}

	function extOf(mime) { return mime === 'image/webp' ? 'webp' : 'jpg'; }

	var _webpCache = null;
	function canSupportWebP() {
		if (_webpCache !== null) { return Promise.resolve(_webpCache); }
		return new Promise(function (resolve) {
			var c = document.createElement('canvas');
			c.width = c.height = 1;
			c.toBlob(function (b) {
				_webpCache = !!(b && b.type === 'image/webp');
				resolve(_webpCache);
			}, 'image/webp', 0.8);
		});
	}

	/* ── Upload ────────────────────────────────────────────────────────── */
	function uploadBlob(blob, ext, target) {
		var fd = new FormData();
		fd.append('action', 'zymarg_vd_upload_store_image');
		fd.append('_wpnonce', CFG.nonce || '');
		fd.append('target', target);
		fd.append('image', blob, target + '-image.' + ext);
		return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success || !res.data || !res.data.url) {
					throw new Error((res && res.data && res.data.message) || (I.uploadFail || 'Upload failed.'));
				}
				return res.data.url;
			});
	}

	/* ── Overlay helpers ──────────────────────────────────────────────── */
	function buildOverlay(title) {
		var ov = document.createElement('div');
		ov.className = 'zvu-overlay';
		ov.innerHTML =
			'<div class="zvu-modal">' +
				'<div class="zvu-modal__head">' +
					'<span class="zvu-modal__title"></span>' +
					'<button type="button" class="zvu-modal__close" aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="zvu-modal__foot"></div>' +
			'</div>';
		ov.querySelector('.zvu-modal__title').textContent = title;
		document.body.appendChild(ov);
		return ov;
	}

	function closeOverlay(ov) {
		if (ov && ov.parentNode) { ov.parentNode.removeChild(ov); }
	}

	/* ── Boot + SPA re-init ───────────────────────────────────────────── */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
	// Section 5's banner control lives inside the SPA-swapped Settings
	// content, so re-run init() after every section swap (the avatar
	// instance's WeakMap guard makes re-running this harmless/no-op for it).
	document.addEventListener('zymarg-vd:section-loaded', initAll);
})();
