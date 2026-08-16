/**
 * ZYMARG Vendor Dashboard — Refund Requests.
 *
 * Buyer side: submit a refund request from the order page.
 * Vendor side: approve (with amount + note) or reject a request.
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.ZymargRefunds || {};
	var i18n = cfg.i18n || {};

	function post( data, done ) {
		var body = new FormData();
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

	/* ---- Buyer request --------------------------------------------- */
	function initBuyer() {
		var form = document.getElementById( 'zymarg-zr-request' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msg = form.querySelector( '.zymarg-zp-msg' );
			var btn = form.querySelector( '.zymarg-zr-btn' );
			var vendorEl = form.querySelector( '[name="vendor"]' );
			var amountEl = form.querySelector( '[name="amount"]' );
			var reasonEl = form.querySelector( '[name="reason"]' );

			if ( reasonEl && ! reasonEl.value.trim() ) {
				setMsg( msg, i18n.error || 'Error', false );
				return;
			}

			if ( btn ) { btn.disabled = true; }
			setMsg( msg, i18n.working || '…', true );

			post( {
				action: 'zymarg_vd_refund_request',
				order: form.getAttribute( 'data-order' ),
				vendor: vendorEl ? vendorEl.value : '',
				amount: amountEl ? amountEl.value : '',
				reason: reasonEl ? reasonEl.value : ''
			}, function ( res ) {
				if ( res && res.success ) {
					setMsg( msg, ( res.data && res.data.message ) || 'OK', true );
					form.reset();
					var submit = form.querySelector( '.zymarg-zr-btn' );
					if ( submit ) { submit.disabled = true; }
				} else {
					if ( btn ) { btn.disabled = false; }
					setMsg( msg, ( res && res.data && res.data.message ) || i18n.error, false );
				}
			} );
		} );
	}

	/* ---- Vendor approve / reject ----------------------------------- */
	var vendorBound = false;
	function initVendor() {
		if ( vendorBound ) {
			return;
		}
		vendorBound = true;
		document.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( ! target.closest ) {
				return;
			}
			var approveBtn = target.closest( '.zymarg-zr-approve' );
			var rejectBtn = target.closest( '.zymarg-zr-reject' );
			if ( ! approveBtn && ! rejectBtn ) {
				return;
			}
			e.preventDefault();

			var btn = approveBtn || rejectBtn;
			var wrap = btn.closest( '.zymarg-zr-actions' );
			if ( ! wrap ) {
				return;
			}
			var id = wrap.getAttribute( 'data-id' );
			var amountEl = wrap.querySelector( '.zymarg-zr-amount' );
			var noteEl = wrap.querySelector( '.zymarg-zr-note' );

			if ( rejectBtn && ! window.confirm( i18n.confirmReject || 'Reject?' ) ) {
				return;
			}

			wrap.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );

			post( {
				action: 'zymarg_vd_refund_action',
				id: id,
				do: approveBtn ? 'approve' : 'reject',
				amount: amountEl ? amountEl.value : '',
				note: noteEl ? noteEl.value : ''
			}, function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					wrap.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } );
					window.alert( ( res && res.data && res.data.message ) || i18n.error );
				}
			} );
		} );
	}

	function init() {
		initBuyer();
		initVendor();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	// Re-bind refund forms after an SPA section swap.
	document.addEventListener( 'zymarg-vd:section-loaded', init );
}() );
