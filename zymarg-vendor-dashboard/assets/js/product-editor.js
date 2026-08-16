/**
 * ZYMARG Vendor Dashboard — Native Product Editor.
 *
 * Submits the add/edit product form via AJAX (multipart, so the featured image
 * uploads with it), toggles inventory fields, and previews the chosen image.
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.ZymargProductEditor || {};
	var i18n = cfg.i18n || {};

	function setMsg( el, text, ok ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.remove( 'is-ok', 'is-err' );
		if ( text ) {
			el.classList.add( ok ? 'is-ok' : 'is-err' );
		}
	}

	/* ---- Inventory field toggle ------------------------------------ */
	function initStockToggle() {
		var manage = document.getElementById( 'zymarg-zpe-manage' );
		var qty = document.getElementById( 'zymarg-zpe-stockqty' );
		var status = document.getElementById( 'zymarg-zpe-stockstatus' );
		if ( ! manage ) {
			return;
		}
		function sync() {
			if ( manage.checked ) {
				if ( qty ) { qty.removeAttribute( 'hidden' ); }
				if ( status ) { status.setAttribute( 'hidden', 'hidden' ); }
			} else {
				if ( qty ) { qty.setAttribute( 'hidden', 'hidden' ); }
				if ( status ) { status.removeAttribute( 'hidden' ); }
			}
		}
		manage.addEventListener( 'change', sync );
		sync();
	}

	/* ---- Image upload zone + remove popup ----------------------------- */
	function initImageUploadZone() {
		var zone = document.getElementById( 'zymarg-zpe-img-zone' );
		var input = document.getElementById( 'zymarg-zpe-img-input' );
		var previewWrap = document.querySelector( '.zymarg-zpe-image__preview-wrap' );
		var preview = document.getElementById( 'zymarg-zpe-img-preview' );
		var removePopup = document.querySelector( '.zymarg-zpe-image__remove-popup' );
		var removeBtn = document.getElementById( 'zymarg-zpe-img-remove' );
		var removeFlag = document.getElementById( 'zymarg-zpe-img-remove-flag' );

		if ( ! zone || ! input ) {
			return;
		}

		zone.addEventListener( 'click', function () {
			input.click();
		} );

		input.addEventListener( 'change', function () {
			if ( ! input.files || ! input.files[0] ) {
				return;
			}
			var url = URL.createObjectURL( input.files[0] );
			if ( preview ) {
				preview.src = url;
			}
			if ( previewWrap ) {
				previewWrap.removeAttribute( 'hidden' );
			}
			if ( zone ) {
				zone.setAttribute( 'hidden', 'hidden' );
			}
			if ( removeFlag ) {
				removeFlag.value = '0';
			}
			if ( removePopup ) {
				removePopup.setAttribute( 'hidden', 'hidden' );
			}
		} );

		if ( preview ) {
			preview.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( removePopup ) {
					if ( removePopup.hasAttribute( 'hidden' ) ) {
						removePopup.removeAttribute( 'hidden' );
					} else {
						removePopup.setAttribute( 'hidden', 'hidden' );
					}
				}
			} );
		}

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( input ) {
					input.value = '';
				}
				if ( previewWrap ) {
					previewWrap.setAttribute( 'hidden', 'hidden' );
				}
				if ( zone ) {
					zone.removeAttribute( 'hidden' );
				}
				if ( removeFlag ) {
					removeFlag.value = '1';
				}
				if ( removePopup ) {
					removePopup.setAttribute( 'hidden', 'hidden' );
				}
			} );
		}

		document.addEventListener( 'click', function () {
			if ( removePopup && ! removePopup.hasAttribute( 'hidden' ) ) {
				removePopup.setAttribute( 'hidden', 'hidden' );
			}
		} );

		if ( removePopup ) {
			removePopup.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );
		}
	}

	/* ---- Virtual checkbox toggle (shipping section) ---------------- */
	function initVirtualToggle() {
		var virtualCb = document.querySelector( 'input[name="virtual"]' );
		var shipping = document.getElementById( 'zymarg-zpe-shipping' );
		if ( ! virtualCb || ! shipping ) {
			return;
		}
		function sync() {
			if ( virtualCb.checked ) {
				shipping.setAttribute( 'hidden', 'hidden' );
			} else {
				shipping.removeAttribute( 'hidden' );
			}
		}
		virtualCb.addEventListener( 'change', sync );
		sync();
	}

	/* ---- Gallery preview ------------------------------------------- */
	function initGalleryPreview() {
		var zone = document.getElementById( 'zymarg-zpe-gallery-zone' );
		var input = document.getElementById( 'zymarg-zpe-gallery-input' );
		var previewWrap = document.querySelector( '.zymarg-zpe-gallery__new-preview' );
		var removeIdsInput = document.getElementById( 'zymarg-zpe-gallery-remove-ids' );

		if ( ! input || ! previewWrap ) {
			return;
		}

		// Upload zone click triggers the hidden file input.
		if ( zone ) {
			zone.addEventListener( 'click', function () {
				input.click();
			} );
		}

		// Per-thumbnail remove buttons.
		var items = document.querySelectorAll( '.zymarg-zpe-gallery__item' );
		for ( var k = 0; k < items.length; k++ ) {
			( function ( item ) {
				var btn = item.querySelector( '.zymarg-zpe-gallery__remove' );
				if ( ! btn ) {
					return;
				}
				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					var id = item.getAttribute( 'data-id' );
					if ( id && removeIdsInput ) {
						var current = removeIdsInput.value ? removeIdsInput.value.split( ',' ) : [];
						current.push( id );
						removeIdsInput.value = current.join( ',' );
					}
					item.parentNode.removeChild( item );
				} );
			} )( items[ k ] );
		}

		// File input change - preview new files.
		input.addEventListener( 'change', function () {
			// Revoke existing object URLs to prevent memory leaks.
			var existingImgs = previewWrap.querySelectorAll( 'img' );
			for ( var j = 0; j < existingImgs.length; j++ ) {
				if ( existingImgs[ j ].src ) {
					URL.revokeObjectURL( existingImgs[ j ].src );
				}
			}
			previewWrap.innerHTML = '';
			if ( ! input.files || ! input.files.length ) {
				previewWrap.setAttribute( 'hidden', 'hidden' );
				return;
			}
			previewWrap.removeAttribute( 'hidden' );
			for ( var i = 0; i < input.files.length; i++ ) {
				var img = document.createElement( 'img' );
				img.className = 'zymarg-zpe-gallery__thumb';
				img.src = URL.createObjectURL( input.files[ i ] );
				img.alt = '';
				previewWrap.appendChild( img );
			}
		} );
	}

	/* ---- Category search/filter ---------------------------------------- */
	function initCategorySearch() {
		var search = document.getElementById( 'zymarg-zpe-cat-search' );
		if ( ! search ) {
			return;
		}
		var list = search.parentElement.querySelector( '.zymarg-zpe-cats__list' );
		if ( ! list ) {
			return;
		}
		search.addEventListener( 'input', function () {
			var query = search.value.toLowerCase().trim();
			var labels = list.querySelectorAll( '.zymarg-zpe-cat' );
			for ( var i = 0; i < labels.length; i++ ) {
				var text = labels[ i ].textContent.toLowerCase();
				if ( ! query || text.indexOf( query ) !== -1 ) {
					labels[ i ].style.display = '';
				} else {
					labels[ i ].style.display = 'none';
				}
			}
		} );
	}

	/* ---- Submit ----------------------------------------------------- */
	function initSubmit() {
		var form = document.getElementById( 'zymarg-zpe-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var msg = form.querySelector( '.zymarg-zp-msg' );
			var btn = form.querySelector( '.zymarg-zpe-save' );
			var titleInput = form.querySelector( 'input[name="title"]' );

			if ( titleInput && ! titleInput.value.trim() ) {
				setMsg( msg, i18n.noTitle || 'Name required', false );
				titleInput.focus();
				return;
			}

			var body = new FormData( form );
			body.append( 'action', 'zymarg_vd_product_save' );
			body.append( 'nonce', cfg.nonce );

			if ( btn ) {
				btn.disabled = true;
			}
			setMsg( msg, i18n.saving || '…', true );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						var redirect = form.getAttribute( 'data-redirect' );
						if ( res.data && res.data.image_warning ) {
							if ( btn ) {
								btn.disabled = false;
							}
							setMsg( msg, res.data.image_warning, false );
							if ( redirect ) {
								setTimeout( function () {
									window.location.href = redirect;
								}, 3000 );
							}
						} else if ( res.data && res.data.gallery_warning ) {
							if ( btn ) {
								btn.disabled = false;
							}
							setMsg( msg, res.data.gallery_warning, false );
							if ( redirect ) {
								setTimeout( function () {
									window.location.href = redirect;
								}, 3000 );
							}
						} else {
							setMsg( msg, ( res.data && res.data.message ) || 'OK', true );
							if ( redirect ) {
								window.location.href = redirect;
							}
						}
					} else {
						if ( btn ) {
							btn.disabled = false;
						}
						setMsg( msg, ( res && res.data && res.data.message ) || i18n.error, false );
					}
				} )
				.catch( function () {
					if ( btn ) {
						btn.disabled = false;
					}
					setMsg( msg, i18n.error || 'Error', false );
				} );
		} );
	}

	function init() {
		initStockToggle();
		initImageUploadZone();
		initVirtualToggle();
		initGalleryPreview();
		initCategorySearch();
		initSubmit();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
