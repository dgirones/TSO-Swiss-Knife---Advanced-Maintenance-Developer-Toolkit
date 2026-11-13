/**
 * TSO Swiss Knife – Admin JavaScript: staging tab.
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


	// ── Staging Mode ───────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-staging-save', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-staging-msg' );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_staging_save',
			data   : {
				nonce            : nonce,
				show_badge       : $( '#tsosk-staging-show-badge' ).is( ':checked' ) ? 1 : 0,
				hide_from_search : $( '#tsosk-staging-hide-search' ).is( ':checked' ) ? 1 : 0,
				block_mail       : $( '#tsosk-staging-block-mail' ).is( ':checked' ) ? 1 : 0,
				log_mail         : $( '#tsosk-staging-log-mail' ).is( ':checked' ) ? 1 : 0,
				pause_cron       : $( '#tsosk-staging-pause-cron' ).is( ':checked' ) ? 1 : 0
			},
			success: function ( r ) {
				var text = r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				showMsg( $msg, text, r.success ? 'ok' : 'error' );
				if ( r.success ) {
					setTimeout( function () { window.location.reload(); }, 500 );
					return;
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-staging-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-staging-msg' );
		var confirmMsg = ( tsosk.i18n && tsosk.i18n.confirm_clear_mail_log ) ? tsosk.i18n.confirm_clear_mail_log : tsosk.i18n.confirm_purge;
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_staging_clear_mail_log',
			data   : { nonce: nonce },
			success: function ( r ) {
				var text = r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				showMsg( $msg, text, r.success ? 'ok' : 'error' );
				if ( r.success ) {
					$( '#tsosk-staging-mail-log tbody' ).empty();
					setTimeout( function () { window.location.reload(); }, 600 );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'change', '#tsosk-staging-held-only', function () {
		var only = $( this ).prop( 'checked' );
		$( '#tsosk-staging-mail-log tbody tr' ).each( function () {
			var held = $( this ).attr( 'data-blocked' ) === '1';
			$( this ).toggle( ! only || held );
		} );
	} );

} )( jQuery );
