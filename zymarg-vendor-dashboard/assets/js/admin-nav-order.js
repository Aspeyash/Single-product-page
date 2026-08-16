/**
 * ZYMARG Vendor Dashboard — sidebar menu order control.
 *
 * Drag-to-sort (desktop) plus arrow buttons (keyboard + touch) for the vendor
 * dashboard sidebar order on the settings screen.
 *
 * Every listener is delegated from `document`, so the control keeps working
 * after the Vendor Hub swaps a section in over AJAX — there is no initialiser
 * to re-run and no state to rebuild. The only thing `zymarg:contentSwapped`
 * does is re-sync the arrow buttons' disabled state on freshly injected markup.
 *
 * The hidden input is the single source of truth handed to PHP; it is
 * recomputed from live DOM order after every change.
 *
 * @package ZYMARG_Vendor_Dashboard
 */
( function () {
	'use strict';

	var SCOPE = '[data-zvds-navorder]';
	var LIST  = '[data-zvds-navorder-list]';
	var ITEM  = '.zvds-navorder__item';
	var INPUT = '[data-zvds-navorder-input]';

	var dragEl = null;

	/**
	 * Nearest control wrapper for a node, or null.
	 *
	 * @param {Element|null} el Starting node.
	 * @return {Element|null} The wrapper.
	 */
	function scopeOf( el ) {
		return el && el.closest ? el.closest( SCOPE ) : null;
	}

	/**
	 * Resolve an event target to an Element that supports closest().
	 *
	 * Guards against text nodes and the document itself.
	 *
	 * @param {EventTarget} target Event target.
	 * @param {string}      sel    Selector to match.
	 * @return {Element|null} Matched element.
	 */
	function closestFrom( target, sel ) {
		if ( ! target || ! target.closest ) {
			return null;
		}
		return target.closest( sel );
	}

	/**
	 * Write the live DOM order into the hidden input and refresh button states.
	 *
	 * @param {Element|null} scope The control wrapper.
	 * @return {void}
	 */
	function sync( scope ) {
		if ( ! scope ) {
			return;
		}

		var input = scope.querySelector( INPUT );
		var list  = scope.querySelector( LIST );
		if ( ! input || ! list ) {
			return;
		}

		var items = list.querySelectorAll( ITEM );
		var keys  = [];

		Array.prototype.forEach.call( items, function ( li, i ) {
			var key = li.getAttribute( 'data-key' );
			if ( key ) {
				keys.push( key );
			}

			// First item cannot move up, last cannot move down.
			var up   = li.querySelector( '[data-zvds-move="up"]' );
			var down = li.querySelector( '[data-zvds-move="down"]' );
			if ( up ) {
				up.disabled = ( 0 === i );
			}
			if ( down ) {
				down.disabled = ( i === items.length - 1 );
			}
		} );

		input.value = keys.join( ',' );
	}

	/**
	 * Sync every control currently in the DOM.
	 *
	 * @return {void}
	 */
	function syncAll() {
		Array.prototype.forEach.call( document.querySelectorAll( SCOPE ), sync );
	}

	/* --- Arrow buttons: the accessible, touch-friendly path -------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = closestFrom( e.target, '[data-zvds-move]' );
		if ( ! btn || btn.disabled ) {
			return;
		}

		var li    = btn.closest( ITEM );
		var scope = scopeOf( li );
		if ( ! li || ! scope || ! li.parentNode ) {
			return;
		}

		// These are type="button", but stop any chance of submitting the form.
		e.preventDefault();

		var dir = btn.getAttribute( 'data-zvds-move' );

		if ( 'up' === dir && li.previousElementSibling ) {
			li.parentNode.insertBefore( li, li.previousElementSibling );
		} else if ( 'down' === dir && li.nextElementSibling ) {
			li.parentNode.insertBefore( li.nextElementSibling, li );
		} else {
			return; // Already at the end of its travel.
		}

		sync( scope );

		/*
		 * Keep keyboard focus with the item the user is moving. If it has hit
		 * the end of the list that button is now disabled, so hand focus to the
		 * opposite arrow rather than dropping it to the body.
		 */
		var same = li.querySelector( '[data-zvds-move="' + dir + '"]' );
		if ( same && ! same.disabled ) {
			same.focus();
			return;
		}
		var opposite = li.querySelector( '[data-zvds-move="' + ( 'up' === dir ? 'down' : 'up' ) + '"]' );
		if ( opposite && ! opposite.disabled ) {
			opposite.focus();
		}
	} );

	/* --- Drag to sort: progressive enhancement on top -------------------- */

	document.addEventListener( 'dragstart', function ( e ) {
		var li = closestFrom( e.target, ITEM );
		if ( ! li || ! scopeOf( li ) ) {
			return;
		}

		dragEl = li;
		li.classList.add( 'is-dragging' );

		if ( e.dataTransfer ) {
			e.dataTransfer.effectAllowed = 'move';
			// Firefox refuses to start a drag without payload.
			try {
				e.dataTransfer.setData( 'text/plain', li.getAttribute( 'data-key' ) || '' );
			} catch ( ignore ) {
				/* noop */
			}
		}
	} );

	document.addEventListener( 'dragover', function ( e ) {
		if ( ! dragEl ) {
			return;
		}

		var over = closestFrom( e.target, ITEM );
		if ( ! over || over === dragEl ) {
			return;
		}

		// Never let an item escape into a different list.
		if ( over.parentNode !== dragEl.parentNode ) {
			return;
		}

		e.preventDefault();

		// Insert before or after depending on which half of the row we are over,
		// so the drop position matches what the user sees.
		var box   = over.getBoundingClientRect();
		var after = ( e.clientY - box.top ) > ( box.height / 2 );

		over.parentNode.insertBefore( dragEl, after ? over.nextSibling : over );
	} );

	document.addEventListener( 'drop', function ( e ) {
		if ( dragEl ) {
			e.preventDefault(); // Suppress the browser's default drop handling.
		}
	} );

	document.addEventListener( 'dragend', function () {
		if ( ! dragEl ) {
			return;
		}

		dragEl.classList.remove( 'is-dragging' );

		var scope = scopeOf( dragEl );
		dragEl = null;
		sync( scope );
	} );

	/* --- Boot ------------------------------------------------------------ */

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', syncAll );
	} else {
		syncAll();
	}

	// The hub router announces AJAX section swaps on this jQuery event.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'zymarg:contentSwapped', syncAll );
	}
}() );
