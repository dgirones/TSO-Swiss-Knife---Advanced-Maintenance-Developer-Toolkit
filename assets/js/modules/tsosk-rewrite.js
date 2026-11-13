/**
 * TSO Swiss Knife – Admin JavaScript: rewrite tab.
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


	// ── Rewrite Rules ──────────────────────────────────────────────────────

	// Soft and hard flush share one handler (the buttons carry data-hard). Both are locked while a
	// flush runs, so a soft and a hard flush can never overlap.
	$( document ).on( 'click', '#tsosk-flush-soft, #tsosk-flush-hard', function () {
		var $btn    = $( this );
		var $both   = $( '#tsosk-flush-soft, #tsosk-flush-hard' );
		var $msg    = $( '#tsosk-rewrite-msg' );
		var hard    = 1 === parseInt( $btn.data( 'hard' ), 10 ) ? 1 : 0;

		function unlock() {
			$both.prop( 'disabled', false );
			$( '#tsosk-flush-soft' ).text( tsosk.i18n.soft_flush );
			$( '#tsosk-flush-hard' ).text( tsosk.i18n.hard_flush );
		}

		$both.prop( 'disabled', true );
		$btn.text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_flush_rewrite',
			data   : { nonce: $btn.data( 'nonce' ), hard: hard },
			success: function ( r ) {
				var d    = r.data;
				var text = ( d && 'object' === typeof d ) ? d.message : d;
				if ( r.success && d && d.warning ) {
					// Partly done (e.g. .htaccess not writable): keep the warning on screen longer.
					showMsg( $msg, text || tsosk.i18n.done, 'warn', 15000 );
				} else {
					showMsg( $msg, text || ( r.success ? tsosk.i18n.done : tsosk.i18n.error ), r.success ? 'ok' : 'error', r.success ? 6000 : 0 );
				}
				unlock();
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				unlock();
			}
		} );
	} );

	// Rewrite rules: live filter.
	$( document ).on( 'input', '#tsosk-rewrite-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '#tsosk-rewrite-table tbody tr' ).each( function () {
			var rule  = $( this ).find( 'td:first' ).text().toLowerCase();
			var query = $( this ).find( 'td:last' ).text().toLowerCase();
			$( this ).toggle( rule.indexOf( q ) !== -1 || query.indexOf( q ) !== -1 );
		} );
	} );

} )( jQuery );
