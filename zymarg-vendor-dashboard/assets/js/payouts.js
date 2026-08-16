/**
 * ZYMARG Vendor Dashboard — Payouts.
 *
 * Handles the payout-method form, the withdrawal request form and request
 * cancellation. Vanilla JS, no dependencies, to match the rest of the plugin.
 */
( function () {
	'use strict';

	var cfg = window.ZymargPayouts || {};
	var i18n = cfg.i18n || {};

	function post( action, data, done ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) { done( res ); } )
			.catch( function () {
				done( { success: false, data: { message: i18n.error || 'Error' } } );
			} );
	}

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

	/* ---- Method form: toggle field groups -------------------------- */
	function initMethodToggle() {
		var sel = document.getElementById( 'zymarg-zp-method-select' );
		var form = document.getElementById( 'zymarg-zp-method' );
		if ( ! sel || ! form ) {
			return;
		}

		function sync() {
			var chosen = sel.value;
			form.querySelectorAll( '.zymarg-zp-fields' ).forEach( function ( group ) {
				if ( group.getAttribute( 'data-method' ) === chosen ) {
					group.removeAttribute( 'hidden' );
				} else {
					group.setAttribute( 'hidden', 'hidden' );
				}
			} );
		}
		sel.addEventListener( 'change', sync );
		sync();
	}

	/* ---- Method form: save ----------------------------------------- */
	function initMethodSave() {
		var form = document.getElementById( 'zymarg-zp-method' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var sel = document.getElementById( 'zymarg-zp-method-select' );
			var method = sel ? sel.value : '';
			var btn = form.querySelector( '.zymarg-zp-save' );
			var msg = form.querySelector( '.zymarg-zp-msg' );
			var group = form.querySelector( '.zymarg-zp-fields[data-method="' + method + '"]' );
			var fields = {};

			if ( group ) {
				group.querySelectorAll( 'input[data-field]' ).forEach( function ( input ) {
					var key = input.getAttribute( 'data-field' ).split( '.' )[ 1 ];
					fields[ key ] = input.value;
				} );
			}

			var data = { method: method };
			Object.keys( fields ).forEach( function ( k ) {
				data[ 'fields[' + k + ']' ] = fields[ k ];
			} );
			if ( form.querySelector( 'input[name="make_default"]:checked' ) ) {
				data.make_default = '1';
			}

			if ( btn ) {
				btn.disabled = true;
			}
			setMsg( msg, i18n.working || '…', true );

			post( 'zymarg_vd_payout_save_method', data, function ( res ) {
				if ( btn ) {
					btn.disabled = false;
				}
				if ( res && res.success ) {
					setMsg( msg, ( res.data && res.data.message ) || 'Saved', true );
				} else {
					setMsg( msg, ( res && res.data && res.data.message ) || i18n.error, false );
				}
			} );
		} );
	}

	/* ---- Request form ---------------------------------------------- */
	function initRequest() {
		var form = document.getElementById( 'zymarg-zp-request' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var amount = parseFloat( form.querySelector( 'input[name="amount"]' ).value );
			var available = parseFloat( form.getAttribute( 'data-available' ) );
			var min = parseFloat( form.getAttribute( 'data-min' ) );
			var msg = form.querySelector( '.zymarg-zp-msg' );
			var btn = form.querySelector( '.zymarg-zp-submit' );

			if ( isNaN( amount ) || amount < min ) {
				setMsg( msg, ( i18n.error || 'Error' ), false );
				return;
			}
			if ( amount > available ) {
				setMsg( msg, ( i18n.error || 'Error' ), false );
				return;
			}

			var data = {
				amount: amount,
				method: form.querySelector( 'select[name="method"]' ).value,
				note: form.querySelector( 'input[name="note"]' ).value
			};

			if ( btn ) {
				btn.disabled = true;
			}
			setMsg( msg, i18n.working || '…', true );

			post( 'zymarg_vd_payout_request', data, function ( res ) {
				if ( res && res.success ) {
					setMsg( msg, ( res.data && res.data.message ) || 'OK', true );
					if ( res.data && res.data.reload ) {
						window.location.reload();
					}
				} else {
					if ( btn ) {
						btn.disabled = false;
					}
					setMsg( msg, ( res && res.data && res.data.message ) || i18n.error, false );
				}
			} );
		} );
	}

	/* ---- Cancel buttons --------------------------------------------
	 * Delegated on `document` (queries the button fresh at click time), so
	 * it works for SPA-swapped content too and is bound EXACTLY ONCE via
	 * the module guard — re-running init() (on section swap) won't stack it.
	 * ---------------------------------------------------------------- */
	var cancelBound = false;
	function initCancel() {
		if ( cancelBound ) {
			return;
		}
		cancelBound = true;
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.zymarg-zp-cancel' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			if ( ! window.confirm( i18n.confirmCancel || 'Cancel?' ) ) {
				return;
			}
			btn.disabled = true;
			post( 'zymarg_vd_payout_cancel', { id: btn.getAttribute( 'data-id' ) }, function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					btn.disabled = false;
					window.alert( ( res && res.data && res.data.message ) || i18n.error );
				}
			} );
		} );
	}

	function init() {
		initMethodToggle();
		initMethodSave();
		initRequest();
		initCancel();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	// Re-bind the Payouts form after an SPA section swap.
	document.addEventListener( 'zymarg-vd:section-loaded', init );
}() );
