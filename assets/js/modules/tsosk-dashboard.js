/**
 * TSO Swiss Knife – Admin JavaScript: dashboard tab.
 *
 * Split from tsosk-admin.js; loaded only on the tab(s) that render this module's markup.
 * Shared helpers come from the core script through window.tsoskAdmin.
 *
 * @package TSO_Swiss_Knife
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';

	var tsoskCore = window.tsoskAdmin;
	var ajaxPost = tsoskCore.ajaxPost;


	// ── Overview: ignore warning ───────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-dash-ignore', function () {
		var $btn  = $( this );
		var $li   = $btn.closest( 'li' );
		// Prefer attr(): jQuery .data('check-id') is wrong — HTML data-check-id maps to .data('checkId').
		var nonce = $btn.attr( 'data-nonce' ) || $btn.data( 'nonce' ) || '';
		var id    = $btn.attr( 'data-check-id' ) || $li.attr( 'data-check-id' ) || $btn.data( 'checkId' ) || '';
		var scope = $btn.attr( 'data-scope' ) || $btn.data( 'scope' ) || '';
		if ( ! nonce || ! id || ! scope ) {
			window.alert( tsosk.i18n.error );
			return;
		}
		$li.find( '.tsosk-dash-ignore' ).prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_dashboard_ignore',
			data   : { nonce: nonce, check_id: id, scope: scope },
			success: function ( r ) {
				if ( ! r || ! r.success ) {
					window.alert( ( r && r.data ) || tsosk.i18n.error );
					$li.find( '.tsosk-dash-ignore' ).prop( 'disabled', false );
					return;
				}
				$li.fadeOut( 250, function () {
					$( this ).remove();
					if ( ! $( '.tsosk-dash-issues > li' ).length ) {
						window.location.reload();
						return;
					}
					// Refresh the score ring and counts without leaving the page mid-action.
					window.setTimeout( function () { window.location.reload(); }, 350 );
				} );
			},
			error: function ( xhr ) {
				var msg = tsosk.i18n.error;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) {
					msg = xhr.responseJSON.data;
				}
				window.alert( msg );
				$li.find( '.tsosk-dash-ignore' ).prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
