/**
 * ZYMARG Vendor Dashboard — Shipping fees + Store SEO.
 *
 * Submits the shipping/SEO settings form via AJAX. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.ZymargShippingSeo || {};
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

	function init() {
		var form = document.getElementById( 'zymarg-zsh-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var msg = form.querySelector( '.zymarg-zp-msg' );
			var btn = form.querySelector( '.zymarg-zsh-save' );

			var body = new FormData( form );
			body.append( 'action', 'zymarg_vd_shipping_seo_save' );
			body.append( 'nonce', cfg.nonce );

			if ( btn ) { btn.disabled = true; }
			setMsg( msg, i18n.saving || '…', true );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( btn ) { btn.disabled = false; }
					if ( res && res.success ) {
						setMsg( msg, ( res.data && res.data.message ) || 'OK', true );
					} else {
						setMsg( msg, ( res && res.data && res.data.message ) || i18n.error, false );
					}
				} )
				.catch( function () {
					if ( btn ) { btn.disabled = false; }
					setMsg( msg, i18n.error || 'Error', false );
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	// Re-bind the Shipping & SEO form after an SPA section swap.
	document.addEventListener( 'zymarg-vd:section-loaded', init );
}() );
