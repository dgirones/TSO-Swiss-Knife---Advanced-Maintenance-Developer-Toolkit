/**
 * TSO Swiss Knife – Admin JavaScript: constants tab.
 *
 * Split from tsosk-admin.js; loaded only on the tab(s) that render this module's markup.
 * Shared helpers come from the core script through window.tsoskAdmin.
 *
 * @package TSO_Swiss_Knife
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';



	// ── Constants: live search ──────────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-constants-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '.tsosk-constant-row' ).each( function () {
			var name = $( this ).find( 'td:first' ).text().toLowerCase();
			$( this ).toggle( name.indexOf( q ) !== -1 );
		} );
		// Hide group headers that have no visible rows.
		$( '.tsosk-card' ).each( function () {
			var $card    = $( this );
			var visible  = $card.find( '.tsosk-constant-row:visible' ).length;
			$card.toggle( visible > 0 );
		} );
	} );

} )( jQuery );
