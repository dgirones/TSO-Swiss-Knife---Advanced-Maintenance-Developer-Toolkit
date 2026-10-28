/**
 * TSO Swiss Knife – Maintenance mode visitor page: live countdown to the announced return.
 */
( function () {
	'use strict';

	var box = document.querySelector( '.tsosk-maint-countdown' );
	if ( ! box ) {
		return;
	}
	var until = parseInt( box.getAttribute( 'data-until' ), 10 ) * 1000;
	if ( ! until ) {
		return;
	}
	// Use the server clock, so a wrong clock on the visitor's device does not shift the countdown.
	var serverNow = parseInt( box.getAttribute( 'data-now' ), 10 ) * 1000;
	var offset    = serverNow ? serverNow - Date.now() : 0;

	function now() {
		return Date.now() + offset;
	}
	var units = [
		{ key: 'd', size: 86400 },
		{ key: 'h', size: 3600 },
		{ key: 'm', size: 60 },
		{ key: 's', size: 1 }
	];

	// Reload once when the time is up (the page may be gone by then); never more than once a minute.
	function reloadOnce() {
		var key = 'tsoskMaintReload';
		try {
			var last = parseInt( window.sessionStorage.getItem( key ), 10 ) || 0;
			if ( Date.now() - last < 60000 ) {
				return;
			}
			window.sessionStorage.setItem( key, String( Date.now() ) );
		} catch ( e ) {
			return;
		}
		window.setTimeout( function () {
			window.location.reload();
		}, 3000 );
	}

	function pad( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	function tick() {
		var left = Math.max( 0, Math.floor( ( until - now() ) / 1000 ) );
		box.textContent = '';
		units.forEach( function ( unit ) {
			var value = Math.floor( left / unit.size );
			left -= value * unit.size;
			var cell  = document.createElement( 'div' );
			var num   = document.createElement( 'strong' );
			var label = document.createElement( 'span' );
			num.textContent   = 'd' === unit.key ? String( value ) : pad( value );
			label.textContent = box.getAttribute( 'data-' + unit.key ) || '';
			cell.appendChild( num );
			cell.appendChild( label );
			box.appendChild( cell );
		} );
		if ( until <= now() ) {
			window.clearInterval( timer );
			reloadOnce();
		}
	}

	var timer = window.setInterval( tick, 1000 );
	tick();
}() );
