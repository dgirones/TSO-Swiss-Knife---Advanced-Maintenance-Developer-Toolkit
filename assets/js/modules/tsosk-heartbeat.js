/**
 * TSO Swiss Knife – Admin JavaScript: heartbeat tab.
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
	var showMsg = tsoskCore.showMsg;


	// ── Heartbeat Controls ─────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-heartbeat-save', function () {
		var $btn     = $( this );
		var nonce    = $btn.data( 'nonce' );
		var $msg     = $( '#tsosk-heartbeat-msg' );
		var mode     = $( 'input[name="tsosk_heartbeat_mode"]:checked' ).val();
		var interval = parseInt( $( '#tsosk-heartbeat-interval' ).val(), 10 ) || 0;

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_heartbeat_save',
			data   : { nonce: nonce, mode: mode, interval: interval },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
