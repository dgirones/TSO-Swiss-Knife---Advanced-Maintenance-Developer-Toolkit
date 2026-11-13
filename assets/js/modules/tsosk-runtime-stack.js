/**
 * TSO Swiss Knife – Admin JavaScript: runtime-stack tab.
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


	$( document ).on( 'click', '#tsosk-runtime-opcache', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-runtime-opcache-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_runtime_opcache_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-runtime-copy-summary', function () {
		var $msg = $( '#tsosk-runtime-copy-msg' );
		var text = $( '#tsosk-runtime-summary' ).val() || '';
		var doneLabel = ( tsosk.i18n && tsosk.i18n.email_copied ) ? tsosk.i18n.email_copied : 'Copied';
		function onCopied() {
			showMsg( $msg, doneLabel, 'ok' );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onCopied ).catch( function () {
				$( '#tsosk-runtime-summary' ).trigger( 'select' );
				try {
					document.execCommand( 'copy' );
					onCopied();
				} catch ( e ) {
					showMsg( $msg, tsosk.i18n.error, 'error' );
				}
			} );
		} else {
			$( '#tsosk-runtime-summary' ).trigger( 'select' );
			try {
				document.execCommand( 'copy' );
				onCopied();
			} catch ( e ) {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		}
	} );

} )( jQuery );
