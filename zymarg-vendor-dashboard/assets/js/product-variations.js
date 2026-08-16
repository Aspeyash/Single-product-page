/**
 * ZYMARG Vendor Dashboard -- Product Variations (Variable Product Stage 2).
 *
 * Handles generating variations, saving variation data, removing individual
 * variations, and toggling stock fields per variation row. Vanilla JS, no
 * dependencies.
 */
( function () {
	'use strict';

	var cfg = window.ZymargProductEditor || {};

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

	/* ---- Generate variations ---------------------------------------- */
	function initGenerate() {
		var btn = document.getElementById( 'zymarg-zpe-generate-variations' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var msg   = document.querySelector( '.zymarg-zpe-var-msg' );
			var list  = document.getElementById( 'zymarg-zpe-var-list' );
			var form  = document.getElementById( 'zymarg-zpe-form' );
			var pidEl = form ? form.querySelector( 'input[name="product_id"]' ) : null;
			var pid   = pidEl ? pidEl.value : '0';

			if ( ! pid || '0' === pid ) {
				setMsg( msg, 'Save the product first.', false );
				return;
			}

			btn.disabled = true;
			setMsg( msg, 'Generating...', true );

			var body = new FormData();
			body.append( 'action', 'zymarg_vd_generate_variations' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'product_id', pid );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					btn.disabled = false;
					if ( res && res.success ) {
						setMsg( msg, res.data.message || 'Done.', true );
						if ( list && res.data.html ) {
							list.innerHTML = res.data.html;
							bindVariationRows();
						}
					} else {
						setMsg( msg, ( res && res.data && res.data.message ) || 'Error.', false );
					}
				} )
				.catch( function () {
					btn.disabled = false;
					setMsg( msg, 'Network error.', false );
				} );
		} );
	}

	/* ---- Save variations -------------------------------------------- */
	function initSave() {
		var btn = document.getElementById( 'zymarg-zpe-save-variations' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var msg   = document.querySelector( '.zymarg-zpe-var-save-msg' );
			var list  = document.getElementById( 'zymarg-zpe-var-list' );
			var form  = document.getElementById( 'zymarg-zpe-form' );
			var pidEl = form ? form.querySelector( 'input[name="product_id"]' ) : null;
			var pid   = pidEl ? pidEl.value : '0';

			if ( ! pid || '0' === pid ) {
				setMsg( msg, 'Save the product first.', false );
				return;
			}

			var rows = list ? list.querySelectorAll( '.zymarg-zpe-var-row' ) : [];
			if ( ! rows.length ) {
				setMsg( msg, 'No variations to save.', false );
				return;
			}

			btn.disabled = true;
			setMsg( msg, 'Saving...', true );

			var body = new FormData();
			body.append( 'action', 'zymarg_vd_save_variations' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'product_id', pid );

			for ( var i = 0; i < rows.length; i++ ) {
				var row = rows[ i ];
				var vid = row.getAttribute( 'data-variation-id' );
				if ( ! vid ) {
					continue;
				}

				body.append( 'variation_ids[]', vid );

				// Regular price.
				var regInput = row.querySelector( 'input[name="var_regular_price[' + vid + ']"]' );
				body.append( 'var_regular_price[' + vid + ']', regInput ? regInput.value : '' );

				// Sale price.
				var saleInput = row.querySelector( 'input[name="var_sale_price[' + vid + ']"]' );
				body.append( 'var_sale_price[' + vid + ']', saleInput ? saleInput.value : '' );

				// SKU.
				var skuInput = row.querySelector( 'input[name="var_sku[' + vid + ']"]' );
				body.append( 'var_sku[' + vid + ']', skuInput ? skuInput.value : '' );

				// Manage stock.
				var manageCb = row.querySelector( 'input[name="var_manage_stock[' + vid + ']"]' );
				if ( manageCb && manageCb.checked ) {
					body.append( 'var_manage_stock[' + vid + ']', '1' );
				}

				// Stock qty.
				var stockInput = row.querySelector( 'input[name="var_stock[' + vid + ']"]' );
				body.append( 'var_stock[' + vid + ']', stockInput ? stockInput.value : '' );

				// Enabled.
				var enabledCb = row.querySelector( 'input[name="var_enabled[' + vid + ']"]' );
				if ( enabledCb && enabledCb.checked ) {
					body.append( 'var_enabled[' + vid + ']', '1' );
				}
			}

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					btn.disabled = false;
					if ( res && res.success ) {
						setMsg( msg, res.data.message || 'Saved.', true );
					} else {
						setMsg( msg, ( res && res.data && res.data.message ) || 'Error.', false );
					}
				} )
				.catch( function () {
					btn.disabled = false;
					setMsg( msg, 'Network error.', false );
				} );
		} );
	}

	/* ---- Remove a single variation ---------------------------------- */
	function bindRemoveButtons() {
		var list = document.getElementById( 'zymarg-zpe-var-list' );
		if ( ! list ) {
			return;
		}

		list.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.zymarg-zpe-var-row__remove' );
			if ( ! btn ) {
				return;
			}

			var vid = btn.getAttribute( 'data-id' );
			if ( ! vid ) {
				return;
			}

			if ( ! confirm( 'Remove this variation?' ) ) {
				return;
			}

			var row = btn.closest( '.zymarg-zpe-var-row' );
			if ( row ) {
				row.style.opacity = '0.5';
				row.style.pointerEvents = 'none';
			}

			var body = new FormData();
			body.append( 'action', 'zymarg_vd_delete_variation' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'variation_id', vid );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.success ) {
						if ( row ) {
							row.style.transition = 'opacity 0.3s ease, max-height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
							row.style.opacity = '0';
							row.style.maxHeight = '0';
							row.style.margin = '0';
							row.style.padding = '0';
							row.style.overflow = 'hidden';
							setTimeout( function () {
								if ( row.parentNode ) {
									row.parentNode.removeChild( row );
								}
							}, 350 );
						}
					} else {
						if ( row ) {
							row.style.opacity = '1';
							row.style.pointerEvents = '';
						}
						alert( ( res && res.data && res.data.message ) || 'Could not delete variation.' );
					}
				} )
				.catch( function () {
					if ( row ) {
						row.style.opacity = '1';
						row.style.pointerEvents = '';
					}
					alert( 'Network error.' );
				} );
		} );
	}

	/* ---- Manage stock toggle per variation row ---------------------- */
	function bindStockToggles() {
		var list = document.getElementById( 'zymarg-zpe-var-list' );
		if ( ! list ) {
			return;
		}

		list.addEventListener( 'change', function ( e ) {
			if ( ! e.target.classList.contains( 'zymarg-zpe-var-manage-cb' ) ) {
				return;
			}

			var wrap = e.target.closest( '.zymarg-zpe-var-stock-wrap' );
			if ( ! wrap ) {
				return;
			}

			var qtyField = wrap.querySelector( '.zymarg-zpe-var-qty-field' );
			if ( ! qtyField ) {
				return;
			}

			if ( e.target.checked ) {
				qtyField.removeAttribute( 'hidden' );
			} else {
				qtyField.setAttribute( 'hidden', 'hidden' );
			}
		} );
	}

	/* ---- Hide/show variations card on type toggle -------------------- */
	function initTypeWatch() {
		var typeSelect = document.getElementById( 'zymarg-zpe-product-type' );
		var varCard    = document.getElementById( 'zymarg-zpe-variations' );
		if ( ! typeSelect || ! varCard ) {
			return;
		}

		function sync() {
			if ( typeSelect.value === 'variable' ) {
				varCard.removeAttribute( 'hidden' );
			} else {
				varCard.setAttribute( 'hidden', 'hidden' );
			}
		}

		typeSelect.addEventListener( 'change', sync );
		// Initial state is handled by PHP (rendered only if variable + saved).
	}

	/* ---- Bind all variation row interactions ------------------------- */
	function bindVariationRows() {
		// Stock toggles are handled by delegation, so no re-bind needed.
		// Remove buttons are also delegated. Nothing extra to do here.
	}

	function init() {
		initGenerate();
		initSave();
		bindRemoveButtons();
		bindStockToggles();
		initTypeWatch();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Re-init on SPA section load.
	document.addEventListener( 'zymarg-vd:section-loaded', init );
}() );
