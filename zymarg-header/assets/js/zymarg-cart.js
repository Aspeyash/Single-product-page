/**
 * ZYMARG Header — Cart widget behaviour.
 *
 * Self-contained port of the Theme Builder's cart.js. Identical logic,
 * two changes only:
 *   1. Config object: window.ZymargHdrCart  (was ZymargTBCart)
 *   2. AJAX actions:  zymarg_hdr_cart_remove / zymarg_hdr_cart_qty
 *                     (were zymarg_tb_cart_remove / zymarg_tb_cart_qty)
 *
 * All CSS class names (.zymarg-tb-cart*, .zymarg-tb-cart-fragload, etc.)
 * are unchanged — the Renderer outputs the same HTML structure.
 *
 * Synchronisation layers (unchanged from TB v3.20.1):
 *   Layer 1 (primary)  : MutationObserver on .zymarg-tb-cart-fragload
 *   Layer 2 (secondary): jQuery WC events (added_to_cart, wc_fragments_refreshed …)
 *   Layer 3 (cold-cache): syncAll() on window.load
 *
 * @package ZymargHeader
 * @since   1.1.0
 */
( function () {
	'use strict';

	var CFG         = window.ZymargHdrCart || { ajaxUrl: '', nonce: '', i18n: {} };
	var $           = window.jQuery;
	var currentOpen = null;

	var inFlightRemove = false;
	var qtyTimers      = {};
	var qtyPending     = {};

	/* ----------------------------------------------------------------- *
	 * Utilities
	 * ----------------------------------------------------------------- */

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function t( key, fallback ) {
		return ( CFG.i18n && CFG.i18n[ key ] ) ? CFG.i18n[ key ] : fallback;
	}

	function getFocusable( container ) {
		return Array.prototype.slice.call(
			container.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]),' +
				'select:not([disabled]), textarea:not([disabled]),' +
				'[tabindex]:not([tabindex="-1"])'
			)
		).filter( function ( el ) {
			return el.offsetParent !== null || el === document.activeElement;
		} );
	}

	/* ----------------------------------------------------------------- *
	 * Count / subtotal sync
	 * ----------------------------------------------------------------- */

	function readFragload() {
		var el = document.querySelector( '.zymarg-tb-cart-fragload' );
		if ( ! el ) { return null; }
		return {
			qty:      parseInt( el.getAttribute( 'data-qty' )    || '0', 10 ),
			unique:   parseInt( el.getAttribute( 'data-unique' ) || '0', 10 ),
			subtotal: el.getAttribute( 'data-subtotal' ) || ''
		};
	}

	function syncInstance( root, data, animate ) {
		var type     = root.getAttribute( 'data-count-type' ) || 'total_qty';
		var showZero = root.getAttribute( 'data-show-zero' ) === '1';
		var count    = ( type === 'unique' ) ? data.unique : data.qty;

		var badge = root.querySelector( '.zymarg-tb-cart__count' );
		if ( badge ) {
			var prev = badge.getAttribute( 'data-prev' );
			badge.textContent = String( count );
			badge.style.display = ( count === 0 && ! showZero ) ? 'none' : '';

			if ( animate && prev !== null && prev !== String( count ) ) {
				var anim = root.getAttribute( 'data-badge-anim' ) || 'bounce';
				if ( anim !== 'none' ) {
					badge.classList.remove( 'is-animating' );
					void badge.offsetWidth;
					badge.classList.add( 'is-animating' );
					badge.addEventListener( 'animationend', function handler() {
						badge.classList.remove( 'is-animating' );
						badge.removeEventListener( 'animationend', handler );
					} );
				}
			}
			badge.setAttribute( 'data-prev', String( count ) );
		}

		var textCount = root.querySelector( '.zymarg-tb-cart__text-count' );
		if ( textCount ) { textCount.textContent = '(' + count + ')'; }

		Array.prototype.forEach.call(
			root.querySelectorAll( '.zymarg-tb-cart__subtotal' ),
			function ( s ) { s.innerHTML = data.subtotal; }
		);

		var body = root.querySelector( '.zymarg-tb-cart__body' );
		if ( body ) { body.classList.toggle( 'is-empty', data.qty === 0 ); }

		var trigger = root.querySelector( '.zymarg-tb-cart__trigger' );
		if ( trigger ) {
			trigger.setAttribute(
				'aria-label',
				t( 'viewCart', 'View cart' ) + ', ' + data.qty
			);
		}
	}

	function syncAll( animate ) {
		var data = readFragload();
		if ( ! data ) { return; }
		Array.prototype.forEach.call(
			document.querySelectorAll( '.zymarg-tb-cart' ),
			function ( root ) { syncInstance( root, data, animate ); }
		);
	}

	/* ----------------------------------------------------------------- *
	 * MutationObserver on .zymarg-tb-cart-fragload
	 * ----------------------------------------------------------------- */

	var fragObserver       = null;
	var fragParentObserver = null;
	var syncing            = false;

	function reconnectFragObserver() {
		if ( fragObserver )       { fragObserver.disconnect();       fragObserver = null; }
		if ( fragParentObserver ) { fragParentObserver.disconnect(); fragParentObserver = null; }

		var el = document.querySelector( '.zymarg-tb-cart-fragload' );
		if ( ! el ) { return; }

		fragObserver = new MutationObserver( function () {
			if ( syncing ) { return; }
			syncing = true;
			syncAll( true );
			syncing = false;
		} );
		fragObserver.observe( el, {
			attributes:      true,
			attributeFilter: [ 'data-qty', 'data-unique', 'data-subtotal' ]
		} );

		if ( el.parentNode ) {
			fragParentObserver = new MutationObserver( function ( records ) {
				var changed = false;
				records.forEach( function ( r ) {
					r.removedNodes.forEach( function ( n ) {
						if ( n.classList && n.classList.contains( 'zymarg-tb-cart-fragload' ) ) {
							changed = true;
						}
					} );
				} );
				if ( changed ) {
					reconnectFragObserver();
					if ( syncing ) { return; }
					syncing = true;
					syncAll( true );
					syncing = false;
				}
			} );
			fragParentObserver.observe( el.parentNode, { childList: true } );
		}
	}

	function startFragObserver() {
		var el = document.querySelector( '.zymarg-tb-cart-fragload' );
		if ( el ) {
			reconnectFragObserver();
		} else {
			var waitObs = new MutationObserver( function () {
				var found = document.querySelector( '.zymarg-tb-cart-fragload' );
				if ( found ) {
					waitObs.disconnect();
					reconnectFragObserver();
					syncAll( false );
				}
			} );
			waitObs.observe( document.body, { childList: true, subtree: true } );
		}
	}

	/* ----------------------------------------------------------------- *
	 * Fragment application
	 * ----------------------------------------------------------------- */

	function applyFragments( frags ) {
		if ( ! frags || typeof frags !== 'object' ) { return; }
		if ( fragObserver )       { fragObserver.disconnect(); }
		if ( fragParentObserver ) { fragParentObserver.disconnect(); }

		Object.keys( frags ).forEach( function ( sel ) {
			var html = frags[ sel ];
			Array.prototype.forEach.call(
				document.querySelectorAll( sel ),
				function ( node ) {
					var tmp   = document.createElement( 'div' );
					tmp.innerHTML = String( html ).trim();
					var fresh = tmp.firstElementChild;
					if ( fresh ) { node.replaceWith( fresh ); }
				}
			);
		} );

		reconnectFragObserver();
	}

	/* ----------------------------------------------------------------- *
	 * Sticky ATC bar hiding (WooSwatches .wse-widget-add-to-cart)
	 * ----------------------------------------------------------------- */

	var STICKY_ATC_HIDDEN_CLASS = 'zymarg-tb-cart-sticky-atc-hidden';
	var STICKY_ATC_SEL          = '.wse-widget-add-to-cart';

	function isMobileBottomSheet() {
		return window.matchMedia( '(max-width: 767px)' ).matches;
	}

	function hideStickyAtc() {
		if ( ! isMobileBottomSheet() ) { return; }
		Array.prototype.forEach.call(
			document.querySelectorAll( STICKY_ATC_SEL ),
			function ( el ) { el.classList.add( STICKY_ATC_HIDDEN_CLASS ); }
		);
	}

	function showStickyAtc() {
		Array.prototype.forEach.call(
			document.querySelectorAll( STICKY_ATC_SEL ),
			function ( el ) { el.classList.remove( STICKY_ATC_HIDDEN_CLASS ); }
		);
	}

	/* ----------------------------------------------------------------- *
	 * Open / close
	 * ----------------------------------------------------------------- */

	function isModal( root ) {
		var action = root.getAttribute( 'data-action' );
		if ( action === 'offcanvas' || action === 'popup' ) { return true; }
		// Dropdown on mobile with the bottom-sheet setting enabled slides up
		// from the bottom and should behave like a modal: backdrop + scroll lock.
		if ( action === 'dropdown'
			&& root.classList.contains( 'zymarg-tb-cart--mobile-sheet' )
			&& isMobileBottomSheet() ) {
			return true;
		}
		return false;
	}

	function openPanel( root ) {
		if ( currentOpen && currentOpen !== root ) { closePanel( currentOpen ); }
		var panel   = root.querySelector( '.zymarg-tb-cart__panel' );
		var overlay = root.querySelector( '.zymarg-tb-cart__overlay' );
		var trigger = root.querySelector( '.zymarg-tb-cart__trigger' );
		if ( ! panel ) { return; }

		panel.hidden = false;
		if ( overlay ) { overlay.hidden = false; }
		void panel.offsetWidth;
		panel.classList.add( 'is-open' );
		if ( overlay ) { overlay.classList.add( 'is-open' ); }
		root.classList.add( 'is-open' );
		if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'true' ); }
		if ( isModal( root ) ) { document.body.classList.add( 'zymarg-tb-cart-locked' ); }

		hideStickyAtc();

		currentOpen = root;
		var closeBtn = panel.querySelector( '.zymarg-tb-cart__close' );
		if ( closeBtn ) { closeBtn.focus(); }
		document.addEventListener( 'keydown', onKeydown );
		document.addEventListener( 'click',   onDocClick, true );
	}

	function closePanel( root ) {
		var panel   = root.querySelector( '.zymarg-tb-cart__panel' );
		var overlay = root.querySelector( '.zymarg-tb-cart__overlay' );
		var trigger = root.querySelector( '.zymarg-tb-cart__trigger' );
		if ( ! panel ) { return; }

		panel.classList.remove( 'is-open' );
		if ( overlay ) { overlay.classList.remove( 'is-open' ); }
		root.classList.remove( 'is-open' );
		if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
		document.body.classList.remove( 'zymarg-tb-cart-locked' );

		var done = function () {
			panel.hidden = true;
			if ( overlay ) { overlay.hidden = true; }
			panel.removeEventListener( 'transitionend', done );
			showStickyAtc();
		};
		panel.addEventListener( 'transitionend', done );
		setTimeout( done, 400 );

		if ( trigger ) { trigger.focus(); }
		currentOpen = null;
		document.removeEventListener( 'keydown', onKeydown );
		document.removeEventListener( 'click',   onDocClick, true );
	}

	function onKeydown( e ) {
		if ( ! currentOpen ) { return; }
		if ( e.key === 'Escape' || e.key === 'Esc' ) {
			e.preventDefault();
			closePanel( currentOpen );
			return;
		}
		if ( e.key === 'Tab' && isModal( currentOpen ) ) {
			var panel      = currentOpen.querySelector( '.zymarg-tb-cart__panel' );
			var focusables = getFocusable( panel );
			if ( ! focusables.length ) { return; }
			var first = focusables[ 0 ];
			var last  = focusables[ focusables.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault(); last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault(); first.focus();
			}
		}
	}

	function onDocClick( e ) {
		if ( ! currentOpen ) { return; }
		if ( currentOpen.contains( e.target ) ) { return; }
		closePanel( currentOpen );
	}

	/* ----------------------------------------------------------------- *
	 * AJAX helpers
	 * ----------------------------------------------------------------- */

	function doPost( action, extra, bodyEl, onDone ) {
		if ( ! CFG.ajaxUrl ) { return; }
		if ( bodyEl ) { bodyEl.classList.add( 'is-loading' ); }

		var params = new URLSearchParams();
		params.append( 'action',   action );
		params.append( 'security', CFG.nonce );
		Object.keys( extra ).forEach( function ( k ) {
			params.append( k, extra[ k ] );
		} );

		fetch( CFG.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        params.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data ) {
					if ( res.data.nonce ) { CFG.nonce = res.data.nonce; }
					applyFragments( res.data.fragments );
					syncAll( true );
					if ( $ ) { $( document.body ).trigger( 'wc_fragments_refreshed' ); }
				}
			} )
			.catch( function () {} )
			.then( function () {
				if ( bodyEl ) { bodyEl.classList.remove( 'is-loading' ); }
				if ( onDone ) { onDone(); }
			} );
	}

	/* ----------------------------------------------------------------- *
	 * Remove item
	 * ----------------------------------------------------------------- */

	function onRemove( btn ) {
		if ( inFlightRemove ) { return; }
		var key    = btn.getAttribute( 'data-key' );
		if ( ! key ) { return; }
		var bodyEl = btn.closest( '.zymarg-tb-cart__body' );
		var item   = btn.closest( '.zymarg-tb-cart__item' );
		if ( item ) { item.classList.add( 'is-removing' ); }
		inFlightRemove = true;
		doPost(
			'zymarg_hdr_cart_remove',          // ← Header's own AJAX action
			{ key: key },
			bodyEl,
			function () { inFlightRemove = false; }
		);
	}

	/* ----------------------------------------------------------------- *
	 * Qty stepper — per-key debounce (200 ms)
	 * ----------------------------------------------------------------- */

	function onQty( btn ) {
		var dir  = btn.getAttribute( 'data-dir' );
		var wrap = btn.closest( '.zymarg-tb-cart__qty' );
		if ( ! wrap ) { return; }

		var key   = wrap.getAttribute( 'data-key' );
		var valEl = wrap.querySelector( '.zymarg-tb-cart__qty-val' );
		var cur   = parseInt( ( valEl && valEl.textContent ) || '1', 10 );
		var next  = ( dir === 'up' ) ? cur + 1 : cur - 1;
		if ( next < 0 ) { next = 0; }

		if ( valEl ) { valEl.textContent = String( next ); }
		qtyPending[ key ] = next;

		if ( qtyTimers[ key ] ) { clearTimeout( qtyTimers[ key ] ); }
		qtyTimers[ key ] = setTimeout( function () {
			delete qtyTimers[ key ];
			dispatchQty( key, qtyPending[ key ], btn );
		}, 200 );
	}

	function dispatchQty( key, qty, btn ) {
		var bodyEl = btn ? btn.closest( '.zymarg-tb-cart__body' ) : null;
		doPost(
			'zymarg_hdr_cart_qty',             // ← Header's own AJAX action
			{ key: key, qty: qty },
			bodyEl,
			function () {
				if ( key in qtyPending && qtyPending[ key ] !== qty ) {
					dispatchQty( key, qtyPending[ key ], btn );
				} else {
					delete qtyPending[ key ];
				}
			}
		);
	}

	/* ----------------------------------------------------------------- *
	 * Init
	 * ----------------------------------------------------------------- */

	function initRoot( root ) {
		if ( ! root || root.getAttribute( 'data-zic-init' ) === '1' ) { return; }
		root.setAttribute( 'data-zic-init', '1' );

		var action  = root.getAttribute( 'data-action' );
		var trigger = root.querySelector( '.zymarg-tb-cart__trigger' );

		if ( action !== 'cart' && action !== 'checkout' && trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( root.classList.contains( 'is-open' ) ) {
					closePanel( root );
				} else {
					openPanel( root );
				}
			} );

			var closeBtn = root.querySelector( '.zymarg-tb-cart__close' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function () { closePanel( root ); } );
			}

			var overlay = root.querySelector( '.zymarg-tb-cart__overlay' );
			if ( overlay ) {
				overlay.addEventListener( 'click', function () { closePanel( root ); } );
			}
		}
	}

	function initAll() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.zymarg-tb-cart' ),
			initRoot
		);
		syncAll( false );
	}

	/* ----------------------------------------------------------------- *
	 * Delegated click handler (survives fragment DOM swaps)
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var removeBtn = e.target.closest ? e.target.closest( '.zymarg-tb-cart__item-remove' ) : null;
		if ( removeBtn ) { e.preventDefault(); onRemove( removeBtn ); return; }

		var qtyBtn = e.target.closest ? e.target.closest( '.zymarg-tb-cart__qty-btn' ) : null;
		if ( qtyBtn ) { e.preventDefault(); onQty( qtyBtn ); }
	} );

	/* ----------------------------------------------------------------- *
	 * Live sync — fetch fresh fragments when needed
	 * ----------------------------------------------------------------- */

	var FRAGLOAD_KEY = '.zymarg-tb-cart-fragload';

	function fragmentsHaveFragload( frags ) {
		return frags &&
			typeof frags === 'object' &&
			Object.prototype.hasOwnProperty.call( frags, FRAGLOAD_KEY );
	}

	function fetchAndSync() {
		var wcAjax =
			( window.WSEParams    && WSEParams.wc_ajax_url    ) ||
			( window.ZYMARGWCPG   && ZYMARGWCPG.wc_ajax_url  ) ||
			'/?wc-ajax=%%endpoint%%';
		var url = wcAjax.replace( '%%endpoint%%', 'get_refreshed_fragments' );

		window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.fragments ) {
					applyFragments( data.fragments );
					syncAll( true );
				}
			} )
			.catch( function () {} );
	}

	/* ----------------------------------------------------------------- *
	 * jQuery WC event bindings — Layer 2 sync
	 * ----------------------------------------------------------------- */

	var jqEventsBound = false;

	function bindJqueryEvents() {
		var jq = window.jQuery;
		if ( ! jq || jqEventsBound ) { return; }
		jqEventsBound = true;

		jq( document.body ).on( 'added_to_cart', function ( _e, fragments ) {
			if ( fragmentsHaveFragload( fragments ) ) {
				applyFragments( fragments );
				syncAll( true );
			} else {
				fetchAndSync();
			}
		} );

		jq( document.body ).on( 'wc_fragments_refreshed', function ( _e, fragments ) {
			if ( fragmentsHaveFragload( fragments ) ) {
				applyFragments( fragments );
				syncAll( true );
			} else {
				syncAll( true );
			}
		} );

		jq( document.body ).on(
			'removed_from_cart wc_fragments_loaded',
			function () { syncAll( true ); }
		);
	}

	/* ----------------------------------------------------------------- *
	 * Boot
	 * ----------------------------------------------------------------- */

	ready( function () {
		initAll();
		startFragObserver(); // Layer 1: MutationObserver
		bindJqueryEvents();  // Layer 2: jQuery WC events
	} );

	window.addEventListener( 'load', function () {
		bindJqueryEvents();
		syncAll( false ); // Layer 3: cold-cache first-load sync
	} );

}() );
