/**
 * ZYMARG Store Page -- Flash Sale hero countdown.
 *
 * WHAT THIS IS AND IS NOT RESPONSIBLE FOR
 * --------------------------------------
 * The server has already printed the deadline, both as a machine-readable
 * timestamp in data-zfs-countdown and as a formatted date inside the <time>
 * element. This file only replaces that date with a ticking duration.
 *
 * That split is deliberate and matters on this page:
 *
 *   - With JavaScript off, blocked, or still loading, the shopper reads a real
 *     end date rather than an empty chip or a timer stuck at zero.
 *   - The deadline is ABSOLUTE, never a duration. A page cache can serve this
 *     HTML an hour later and the countdown is still correct, which a
 *     server-rendered "ends in 2 hours" could not be.
 *
 * There is no spinner and no layout shift: the chip is already the right shape
 * before this runs, and the digits are tabular so they do not jitter as they
 * change.
 *
 * @package ZYMARG_Store_Page
 * @since   1.20.0
 */
( function () {
	'use strict';

	var chips = document.querySelectorAll( '[data-zfs-countdown]' );

	if ( ! chips.length ) {
		return;
	}

	/*
	 * Respect a reduced-motion preference by not animating at all: the deadline
	 * is printed once as a static duration and left alone. A once-per-second
	 * repaint is exactly the kind of unrequested motion that setting asks us to
	 * stop, and the information is identical either way.
	 */
	var still = window.matchMedia
		&& window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Format a remaining duration in seconds as a compact human string.
	 *
	 * Deliberately drops units that would read as noise: once there are days
	 * left the seconds are irrelevant, and showing them invites the shopper to
	 * watch a number that will not change their decision.
	 *
	 * @param {number} left Seconds remaining.
	 * @return {string}
	 */
	function format( left ) {
		var d = Math.floor( left / 86400 );
		var h = Math.floor( ( left % 86400 ) / 3600 );
		var m = Math.floor( ( left % 3600 ) / 60 );
		var s = Math.floor( left % 60 );

		function pad( n ) {
			return n < 10 ? '0' + n : String( n );
		}

		if ( d > 0 ) {
			return d + 'd ' + pad( h ) + 'h ' + pad( m ) + 'm';
		}

		if ( h > 0 ) {
			return pad( h ) + ':' + pad( m ) + ':' + pad( s );
		}

		return pad( m ) + ':' + pad( s );
	}

	/**
	 * Update one chip. Returns false once its deadline has passed.
	 *
	 * @param {Element} chip The chip element.
	 * @return {boolean} Whether this chip is still counting.
	 */
	function tick( chip ) {
		var target = parseInt( chip.getAttribute( 'data-zfs-countdown' ), 10 );
		var slot   = chip.querySelector( 'time' );

		if ( ! slot || isNaN( target ) ) {
			return false;
		}

		var left = target - Math.floor( Date.now() / 1000 );

		if ( left <= 0 ) {
			/*
			 * Expired while the shopper was on the page. Removing the chip is
			 * the honest outcome: the products below are re-queried server-side
			 * and this sale is no longer among them, so leaving "00:00" on
			 * screen would describe a page state that no longer exists.
			 */
			chip.remove();
			return false;
		}

		slot.textContent = format( left );
		return true;
	}

	var live = Array.prototype.filter.call( chips, tick );

	if ( still || ! live.length ) {
		return;
	}

	var timer = window.setInterval( function () {
		live = live.filter( tick );

		if ( ! live.length ) {
			window.clearInterval( timer );
		}
	}, 1000 );
}() );
