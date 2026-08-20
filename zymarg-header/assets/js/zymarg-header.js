/**
 * zymarg-header.js — Header behaviour.
 *
 * Handles:
 *   1. Sticky scroll — toggles .is-sticky on .z-hdr-wrap and
 *      .hdr-sticky on <body> once the user scrolls past the
 *      38 px top-bar, shrinking the header to just the bar.
 *   2. Wishlist badge — listens for WCPG jQuery events and
 *      updates every .z-hdr-wishlist-count badge live.
 *
 * No dependency on Theme Builder. Cart live-sync is handled
 * entirely by zymarg-cart.js (loaded separately).
 *
 * @package ZymargHeader
 * @since   1.1.2
 * @updated 1.1.6 Wishlist badge pop animation (mirrors cart badge bounce).
 */
( function () {
	'use strict';

	/* ── 1. Sticky scroll ───────────────────────────────────── */
	function initSticky() {
		var wrap    = document.getElementById( 'zHdrWrap' );
		var topbar  = document.querySelector( '.z-hdr-topbar' );
		var body    = document.body;
		var threshold = topbar ? ( topbar.offsetHeight || 38 ) : 38;

		if ( ! wrap ) { return; }

		function onScroll() {
			if ( window.scrollY >= threshold ) {
				wrap.classList.add( 'is-sticky' );
				body.classList.add( 'hdr-sticky' );
			} else {
				wrap.classList.remove( 'is-sticky' );
				body.classList.remove( 'hdr-sticky' );
			}
		}

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll(); /* run once on load to reflect current scroll position */
	}

	/* ── 2. Wishlist badge ──────────────────────────────────── */
	function initWishlist() {
		var jq = window.jQuery;
		if ( ! jq ) { return; }

		function setCount( count ) {
			var n = parseInt( count, 10 ) || 0;
			var badges = document.querySelectorAll( '.z-hdr-wishlist-count' );
			badges.forEach( function ( badge ) {
				/* Track previous value so we only animate on a real change.
				 * data-prev is seeded in PHP (class-renderer.php) so the
				 * first paint never triggers the animation. */
				var prev = badge.getAttribute( 'data-prev' );

				badge.textContent = String( n );
				if ( n > 0 ) {
					badge.removeAttribute( 'hidden' );
				} else {
					badge.setAttribute( 'hidden', '' );
				}

				/* Animate only when the count genuinely changes and the admin
				 * has chosen an animation type (not 'none'). Reads
				 * data-wl-badge-anim from the parent wishlist action element —
				 * set by class-renderer.php from the wishlist_badge_animation
				 * setting. Mirrors zymarg-cart.js syncInstance(). @since 1.1.13 */
				if ( prev !== null && prev !== String( n ) ) {
					var action = badge.closest ? badge.closest( '.z-hdr-action--wishlist' ) : null;
					var anim   = action ? ( action.getAttribute( 'data-wl-badge-anim' ) || 'none' ) : 'none';
					if ( 'none' !== anim ) {
						badge.classList.remove( 'is-animating' );
						void badge.offsetWidth; /* force reflow so CSS restarts */
						badge.classList.add( 'is-animating' );
						badge.addEventListener( 'animationend', function handler() {
							badge.classList.remove( 'is-animating' );
							badge.removeEventListener( 'animationend', handler );
						} );
					}
				}

				badge.setAttribute( 'data-prev', String( n ) );
			} );
		}

		/* WCPG fires these on the $(document) object */
		jq( document ).on( 'zymarg_wcpg:wishlist:changed', function ( _e, data ) {
			if ( data && typeof data.count !== 'undefined' ) {
				setCount( data.count );
			}
		} );

		jq( document ).on( 'zymarg_wcpg:wishlist:hydrated', function ( _e, data ) {
			if ( data && typeof data.count !== 'undefined' ) {
				setCount( data.count );
			}
		} );
	}

	/* ── Boot ───────────────────────────────────────────────── */
	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		initSticky();
		initWishlist();
	} );

}() );
