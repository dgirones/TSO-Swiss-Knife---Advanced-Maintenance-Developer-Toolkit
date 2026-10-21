/**
 * Slow Query Monitor – refresh the toolbar item once the page is (almost) finished.
 *
 * The toolbar is printed before the rest of the page runs its queries, so the
 * server sends the final numbers in window.tsoskSqLive and this script swaps them in.
 *
 * @package TSO_Swiss_Knife
 */
( function () {
	'use strict';

	var data = window.tsoskSqLive;
	if ( ! data || ! data.text ) {
		return;
	}

	var root = document.getElementById( 'wp-admin-bar-tsosk-slow-queries' );
	if ( ! root ) {
		return;
	}

	Object.keys( data.text ).forEach( function ( key ) {
		var value = String( data.text[ key ] );
		var nodes = root.querySelectorAll( '[data-tsosk-sq="' + key + '"]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			node.textContent = value;
			// Pills disappear when there is nothing to show.
			if ( key === 'dups_pill' || key === 'slow_pill' ) {
				node.hidden = '' === value;
			}
		} );
	} );

	// Colour of the "slow" and "duplicate" counters in the submenu.
	Object.keys( data.tone || {} ).forEach( function ( key ) {
		var nodes = root.querySelectorAll( '[data-tsosk-sq="' + key + '"]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			node.classList.remove( 'tsosk-sq-v--warn', 'tsosk-sq-v--bad' );
			if ( data.tone[ key ] ) {
				node.classList.add( 'tsosk-sq-v--' + data.tone[ key ] );
			}
		} );
	} );

	var dot = root.querySelector( '.tsosk-sq-dot' );
	if ( dot && data.severity ) {
		dot.className = 'tsosk-sq-dot tsosk-sq-dot--' + data.severity;
	}

	root.classList.remove( 'tsosk-sq-ab-warn', 'tsosk-sq-ab-alert' );
	if ( 'warn' === data.severity || 'alert' === data.severity ) {
		root.classList.add( 'tsosk-sq-ab-' + data.severity );
	}

	var link = root.querySelector( ':scope > .ab-item' );
	if ( link && data.tooltip ) {
		link.setAttribute( 'title', data.tooltip );
	}
}() );
