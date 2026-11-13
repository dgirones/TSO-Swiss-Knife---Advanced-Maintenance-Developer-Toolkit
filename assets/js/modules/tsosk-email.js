/**
 * TSO Swiss Knife – Admin JavaScript: email tab.
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


	// ── Email Diagnostics ──────────────────────────────────────────────────

	function tsoskEmailPayloadMessage( data, fallback ) {
		if ( data && typeof data === 'object' && data.message ) {
			return data.message;
		}
		if ( typeof data === 'string' && data ) {
			return data;
		}
		return fallback || tsosk.i18n.error;
	}

	function tsoskEmailApplyResultsHtml( data ) {
		if ( data && typeof data === 'object' && data.html ) {
			$( '#tsosk-email-results' ).html( data.html );
		}
	}

	$( document ).on( 'click', '#tsosk-email-send-test', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-email-msg' );
		var format = $( 'input[name="tsosk_email_format"]:checked' ).val() || 'plain';

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_email_send_test',
			data   : {
				nonce  : $btn.data( 'nonce' ),
				email  : $( '#tsosk-email-test-address' ).val(),
				cc     : $( '#tsosk-email-test-cc' ).val(),
				format : format
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsoskEmailPayloadMessage( r.data, tsosk.i18n.done ), 'ok' );
					tsoskEmailApplyResultsHtml( r.data );
				} else {
					showMsg( $msg, tsoskEmailPayloadMessage( r.data, tsosk.i18n.error ), 'error' );
					tsoskEmailApplyResultsHtml( r.data );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.send_test );
			},
			error: function ( xhr ) {
				var data = xhr && xhr.responseJSON ? xhr.responseJSON.data : null;
				showMsg( $msg, tsoskEmailPayloadMessage( data, tsosk.i18n.error ), 'error' );
				tsoskEmailApplyResultsHtml( data );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.send_test );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-email-copy-report', function () {
		var $msg = $( '#tsosk-email-copy-msg' );
		var text = $( '#tsosk-email-report' ).val() || '';
		var doneLabel = ( tsosk.i18n && tsosk.i18n.email_copied ) ? tsosk.i18n.email_copied : 'Copied';

		function onCopied() {
			showMsg( $msg, doneLabel, 'ok' );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onCopied ).catch( function () {
				$( '#tsosk-email-report' ).trigger( 'select' );
				try {
					document.execCommand( 'copy' );
					onCopied();
				} catch ( e ) {
					showMsg( $msg, tsosk.i18n.error, 'error' );
				}
			} );
			return;
		}

		$( '#tsosk-email-report' ).trigger( 'select' );
		try {
			document.execCommand( 'copy' );
			onCopied();
		} catch ( e2 ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
		}
	} );

} )( jQuery );
