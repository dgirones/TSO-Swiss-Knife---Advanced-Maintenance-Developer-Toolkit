/**
 * TSO Swiss Knife – Admin JavaScript: rest-api tab.
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


	// ── REST API Controls ──────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-rest-save', function () {
		var $btn       = $( this );
		var nonce      = $btn.data( 'nonce' );
		var $msg       = $( '#tsosk-rest-msg' );
		var mode       = $( 'input[name="tsosk_rest_mode"]:checked' ).val();
		var disabled_ns = [];
		var blockUserEnum = $( '#tsosk-rest-block-user-enum' ).prop( 'checked' ) ? 1 : 0;

		$( '.tsosk-ns-cb:checked' ).each( function () {
			disabled_ns.push( $( this ).val() );
		} );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_rest_save',
			data   : { nonce: nonce, mode: mode, disabled_namespaces: disabled_ns, block_user_enumeration: blockUserEnum },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
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

	$( document ).on( 'click', '#tsosk-rest-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_rest_clear_log',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					window.location.reload();
				} else {
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
