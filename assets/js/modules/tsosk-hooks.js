/**
 * TSO Swiss Knife – Admin JavaScript: hooks tab.
 *
 * Split from tsosk-admin.js; loaded only on the tab(s) that render this module's markup.
 * Shared helpers come from the core script through window.tsoskAdmin.
 *
 * @package TSO_Swiss_Knife
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';



	// ── Hooks Inspector: live search ────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-hooks-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '#tsosk-hooks-table tbody tr' ).each( function () {
			var hook = $( this ).find( 'td:first' ).text().toLowerCase();
			$( this ).toggle( hook.indexOf( q ) !== -1 );
		} );
	} );

} )( jQuery );
