/**
 * ZYMARG Store Listing  --  store-listing.js
 *
 * Two small jobs, both of which degrade gracefully:
 *
 *   1. Follow / unfollow, reusing the REST routes the Follow module already
 *      exposes. No new endpoint, no new nonce scheme.
 *   2. Auto-submit the sort dropdown, so choosing a sort does not require a
 *      second click on Search.
 *
 * Search and sorting work with JavaScript switched off, because they are a
 * plain GET form. This file only makes them nicer.
 *
 * No jQuery -- the Store Page front end has never loaded it.
 *
 * @package ZYMARG_Store_Page
 */

( function () {
	'use strict';

	var CFG = window.ZYMARG_LISTING || {};

	// ── Sort auto-submit ────────────────────────────────────────────────

	function initSort() {
		var select = document.querySelector( '[data-zsl-sort]' );

		if ( ! select ) {
			return;
		}

		var form = select.closest( 'form' );

		if ( ! form ) {
			return;
		}

		select.addEventListener( 'change', function () {
			// Drop back to page one: staying on page 7 of a re-sorted list is
			// meaningless and often lands on nothing.
			var page = form.querySelector( 'input[name="store_page"]' );

			if ( page ) {
				page.remove();
			}

			form.submit();
		} );
	}

	// ── Follow ───────────────────────────────────────────────────────

	function setFollowState( btn, following ) {
		btn.setAttribute( 'aria-pressed', following ? 'true' : 'false' );

		var label = btn.querySelector( '[data-zsl-follow-label]' );

		if ( label ) {
			label.textContent = following
				? ( CFG.i18n && CFG.i18n.following ? CFG.i18n.following : 'Following' )
				: ( CFG.i18n && CFG.i18n.follow ? CFG.i18n.follow : 'Follow' );
		}
	}

	function updateFollowerCount( storeId, delta ) {
		var node = document.querySelector( '[data-zsl-followers="' + storeId + '"] strong' );

		if ( ! node ) {
			return;
		}

		// Read back the rendered number rather than trusting a local counter,
		// so a stale tab cannot drift.
		var current = parseInt( node.textContent.replace( /[^0-9]/g, '' ), 10 );

		if ( isNaN( current ) ) {
			return;
		}

		var next = current + delta;

		node.textContent = next < 0 ? '0' : String( next );
	}

	function initFollow() {
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-zsl-follow]' );

			if ( ! btn ) {
				return;
			}

			event.preventDefault();

			// Not signed in: send them to log in and bring them back here.
			if ( ! CFG.isLoggedIn ) {
				if ( CFG.loginUrl ) {
					window.location.href = CFG.loginUrl;
				}
				return;
			}

			if ( btn.classList.contains( 'is-busy' ) ) {
				return;
			}

			var storeId   = btn.getAttribute( 'data-zsl-follow' );
			var following = btn.getAttribute( 'aria-pressed' ) === 'true';
			var route     = following ? 'unfollow' : 'follow';

			btn.classList.add( 'is-busy' );

			// Optimistic flip, reverted below if the request fails.
			setFollowState( btn, ! following );
			updateFollowerCount( storeId, following ? -1 : 1 );

			window.fetch( CFG.apiBase + CFG.followNs + '/' + route, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CFG.nonce
				},
				body: JSON.stringify( { store_id: parseInt( storeId, 10 ) } )
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Request failed' );
					}
					return response.json();
				} )
				.then( function ( data ) {
					// Trust the server's own numbers when it returns them.
					if ( data && typeof data.following !== 'undefined' ) {
						setFollowState( btn, !! data.following );
					}

					// The REST route returns `followers_count`, not `count`.
					if ( data && typeof data.followers_count !== 'undefined' ) {
						var node = document.querySelector( '[data-zsl-followers="' + storeId + '"] strong' );

						if ( node ) {
							node.textContent = String( data.followers_count );
						}
					}
				} )
				.catch( function () {
					// Put it back the way it was rather than leaving a lie on screen.
					setFollowState( btn, following );
					updateFollowerCount( storeId, following ? 1 : -1 );
				} )
				.then( function () {
					btn.classList.remove( 'is-busy' );
				} );
		} );
	}

	// ── Infinite scroll ───────────────────────────────────────
	//
	// Appends the next page of cards as the shopper reaches the bottom. The
	// server-rendered numbered pager stays in the DOM and is only hidden once
	// this has actually taken over -- so a browser without IntersectionObserver,
	// a blocked script, or a crawler still gets a fully navigable directory.
	//
	// If a request fails the pager comes straight back. The shopper is told the
	// truth and handed a working control, rather than being left at the bottom
	// of a list that silently stopped growing.

	function initInfinite() {
		var grid = document.querySelector( '[data-zsl-grid]' );

		if ( ! grid ) {
			return;
		}

		var sentinel = document.querySelector( '[data-zsl-sentinel]' );
		var loader   = document.querySelector( '[data-zsl-infinite]' );
		var text     = document.querySelector( '[data-zsl-infinite-text]' );
		var retry    = document.querySelector( '[data-zsl-retry]' );
		var status   = document.querySelector( '[data-zsl-a11y]' );
		var pager    = document.querySelector( '[data-zsl-pager]' );

		var supported = sentinel &&
			window.IntersectionObserver &&
			window.fetch &&
			CFG.ajaxUrl &&
			CFG.listNonce;

		if ( ! supported ) {
			return; // Numbered pager stays visible and keeps working.
		}

		var paged  = parseInt( grid.getAttribute( 'data-zsl-paged' ), 10 ) || 1;
		var pages  = parseInt( grid.getAttribute( 'data-zsl-pages' ), 10 ) || 1;
		var total  = parseInt( grid.getAttribute( 'data-zsl-total' ), 10 ) || 0;
		var search = grid.getAttribute( 'data-zsl-search' ) || '';
		var sort   = grid.getAttribute( 'data-zsl-sortkey' ) || '';

		if ( pages <= 1 ) {
			return;
		}

		var busy    = false;
		var stopped = false;
		var observer;

		function i18n( key, fallback ) {
			return ( CFG.i18n && CFG.i18n[ key ] ) ? CFG.i18n[ key ] : fallback;
		}

		function announce( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		function showLoading() {
			if ( loader ) {
				loader.hidden = false;
				loader.classList.add( 'is-busy' );
				loader.classList.remove( 'is-error' );
			}
			if ( text ) {
				text.textContent = i18n( 'loading', 'Loading more stores...' );
			}
			if ( retry ) {
				retry.hidden = true;
			}
		}

		function hideLoader() {
			if ( loader ) {
				loader.hidden = true;
				loader.classList.remove( 'is-busy' );
			}
		}

		// Stop for a good reason: everything has been shown.
		function finish() {
			stopped = true;

			if ( observer ) {
				observer.disconnect();
			}

			var message = i18n( 'end', 'You have seen all %d stores.' ).replace( '%d', String( total ) );

			if ( loader ) {
				loader.hidden = false;
				loader.classList.remove( 'is-busy' );
				loader.classList.remove( 'is-error' );
				loader.classList.add( 'is-done' );
			}
			if ( text ) {
				text.textContent = message;
			}
			if ( retry ) {
				retry.hidden = true;
			}

			announce( message );
		}

		// Stop for a bad reason: say so, and give the pager back.
		function fail() {
			busy = false;

			if ( observer ) {
				observer.disconnect();
			}

			var message = i18n( 'error', 'Could not load more stores.' );

			if ( loader ) {
				loader.hidden = false;
				loader.classList.remove( 'is-busy' );
				loader.classList.add( 'is-error' );
			}
			if ( text ) {
				text.textContent = message;
			}
			if ( retry ) {
				retry.hidden = false;
			}

			// The shopper is not stranded: the real pager returns.
			if ( pager ) {
				pager.hidden = false;
			}

			announce( message );
		}

		function loadNext() {
			if ( busy || stopped || paged >= pages ) {
				return;
			}

			busy = true;
			showLoading();

			var body = new window.FormData();
			body.append( 'action', 'zymarg_sp_load_stores' );
			body.append( 'nonce', CFG.listNonce );
			body.append( 'paged', String( paged + 1 ) );
			body.append( 'search', search );
			body.append( 'sort', sort );

			window.fetch( CFG.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Request failed' );
					}
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						throw new Error( 'Unexpected response' );
					}

					var data = payload.data;

					// An empty page is not an error, but it is the end. Never
					// keep asking, and never pretend something arrived.
					if ( ! data.count || ! data.html ) {
						busy = false;
						finish();
						return;
					}

					var holder = document.createElement( 'div' );
					holder.innerHTML = data.html;

					while ( holder.firstElementChild ) {
						grid.appendChild( holder.firstElementChild );
					}

					paged = parseInt( data.paged, 10 ) || ( paged + 1 );

					if ( data.pages ) {
						pages = parseInt( data.pages, 10 ) || pages;
					}
					if ( typeof data.total !== 'undefined' ) {
						total = parseInt( data.total, 10 ) || total;
					}

					grid.setAttribute( 'data-zsl-paged', String( paged ) );

					// Keep the address bar honest, so a reload or a shared link
					// lands where the shopper actually is.
					if ( window.history && window.history.replaceState ) {
						try {
							var url = new window.URL( window.location.href );
							url.searchParams.set( 'store_page', String( paged ) );
							window.history.replaceState( null, '', url.toString() );
						} catch ( e ) {
							// A URL the browser will not parse is not worth failing over.
						}
					}

					announce(
						i18n( 'added', '%d more stores loaded.' ).replace( '%d', String( data.count ) )
					);

					busy = false;

					if ( paged >= pages ) {
						finish();
					} else {
						hideLoader();
					}
				} )
				.catch( function () {
					fail();
				} );
		}

		if ( retry ) {
			retry.addEventListener( 'click', function () {
				stopped = false;

				if ( pager ) {
					pager.hidden = true;
				}

				observer.observe( sentinel );
				loadNext();
			} );
		}

		observer = new window.IntersectionObserver( function ( entries ) {
			var i;

			for ( i = 0; i < entries.length; i++ ) {
				if ( entries[ i ].isIntersecting ) {
					loadNext();
					break;
				}
			}
		}, { rootMargin: '600px 0px' } );

		// Only now, with a working observer in hand, retire the pager.
		if ( pager ) {
			pager.hidden = true;
		}

		observer.observe( sentinel );
	}

	// ── Boot ─────────────────────────────────────────────────────────

	function boot() {
		initSort();
		initFollow();
		initInfinite();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
