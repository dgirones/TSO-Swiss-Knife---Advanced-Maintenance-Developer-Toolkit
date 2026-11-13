/**
 * TSO Swiss Knife – Admin JavaScript: internals tab.
 *
 * Split from tsosk-admin.js; loaded only on the tab(s) that render this module's markup.
 * Shared helpers come from the core script through window.tsoskAdmin.
 *
 * @package TSO_Swiss_Knife
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';



	// ── WP Internals ───────────────────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-internals-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '.tsosk-internals-row' ).each( function () {
			$( this ).toggle( $( this ).text().toLowerCase().indexOf( q ) !== -1 );
		} );
	} );

} )( jQuery );
