/**
 * TSO Swiss Knife – Admin JavaScript: cron tab.
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


	// ── Cron Manager ───────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-cron-run', function () {
		var $btn   = $( this );
		var hook   = $btn.data( 'hook' );
		var ts     = $btn.data( 'timestamp' );
		var sig    = $btn.data( 'sig' );
		var nonce  = $btn.data( 'nonce' );
		var $row   = $btn.closest( 'tr' );
		var $msg   = $row.find( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_cron_run',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cron-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn  = $( this );
		var hook  = $btn.data( 'hook' );
		var ts    = $btn.data( 'timestamp' );
		var sig   = $btn.data( 'sig' );
		var nonce = $btn.data( 'nonce' );
		var $row  = $btn.closest( 'tr' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_cron_delete',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					$row.fadeOut( 300, function () { $( this ).remove(); } );
				} else {
					alert( r.data || tsosk.i18n.error );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				alert( tsosk.i18n.error );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cron-edit', function () {
		var $btn = $( this );
		var ts   = parseInt( $btn.data( 'timestamp' ), 10 );
		var when = new Date( ts * 1000 );
		var pad  = function ( n ) { return n < 10 ? '0' + n : String( n ); };
		var local = when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() )
			+ 'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );

		$( '#tsosk-cron-edit-hook' ).val( $btn.data( 'hook' ) );
		$( '#tsosk-cron-edit-timestamp' ).val( $btn.data( 'timestamp' ) );
		$( '#tsosk-cron-edit-sig' ).val( $btn.data( 'sig' ) );
		$( '#tsosk-cron-edit-when' ).val( local );
		$( '#tsosk-cron-edit-schedule' ).val( $btn.data( 'schedule' ) || 'single' );
		$( '#tsosk-cron-edit-msg' ).hide().text( '' );
		$( '#tsosk-cron-edit-panel' ).slideDown( 200 );
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-cron-edit-panel' ).offset().top - 40 }, 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-cancel', function () {
		$( '#tsosk-cron-edit-panel' ).slideUp( 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-cron-edit-msg' );
		var when  = $( '#tsosk-cron-edit-when' ).val();
		if ( ! when ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		var newTs = Math.floor( new Date( when ).getTime() / 1000 );
		if ( ! newTs || isNaN( newTs ) ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cron_reschedule',
			data   : {
				nonce         : $( '#tsosk-cron-edit-nonce' ).val(),
				hook          : $( '#tsosk-cron-edit-hook' ).val(),
				timestamp     : $( '#tsosk-cron-edit-timestamp' ).val(),
				sig           : $( '#tsosk-cron-edit-sig' ).val(),
				new_timestamp : newTs,
				schedule      : $( '#tsosk-cron-edit-schedule' ).val()
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.cron_rescheduled, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
