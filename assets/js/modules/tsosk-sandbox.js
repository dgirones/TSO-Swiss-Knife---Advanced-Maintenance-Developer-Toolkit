/**
 * TSO Swiss Knife – Admin JavaScript: sandbox tab.
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


	// ── Plugin Conflict Sandbox ─────────────────────────────────────────────

	// Helper buttons: Select All.
	$( document ).on( 'click', '#tsosk-sb-select-all', function () {
		$( '.tsosk-sandbox-cb' ).prop( 'checked', true );
	} );

	// Helper buttons: Select Normally Active.
	$( document ).on( 'click', '#tsosk-sb-select-active', function () {
		$( '.tsosk-sandbox-cb' ).each( function () {
			var $cb = $( this );
			var $row = $cb.closest( '.tsosk-plugin-row' );
			$cb.prop( 'checked', $row.data( 'normal' ) === 1 || $row.data( 'normal' ) === '1' || $row.data( 'normal' ) === true );
		} );
	} );

	// Helper buttons: Deselect All.
	$( document ).on( 'click', '#tsosk-sb-select-none', function () {
		$( '.tsosk-sandbox-cb' ).prop( 'checked', false );
	} );

	$( document ).on( 'click', '#tsosk-sandbox-apply', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_sandbox ) ) {
			return;
		}
		var $btn    = $( this );
		var nonce   = $btn.data( 'nonce' );
		var $msg    = $( '#tsosk-sandbox-msg' );
		var plugins = [];

		$( '.tsosk-sandbox-cb:checked' ).each( function () {
			plugins.push( $( this ).val() );
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sandbox_apply',
			data   : { nonce: nonce, plugins: plugins },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					// Reload after a short delay so the sandbox takes effect.
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.apply_sandbox );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.apply_sandbox );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-sandbox-reset', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-sandbox-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sandbox_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.reset_sandbox );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.reset_sandbox );
			}
		} );
	} );

	// ── Plugin Sandbox: automatic bisection ─────────────────────────────────

	function tsoskBisectPost( action, extra, $btn, $msg, origText ) {
		var data = { nonce: $btn.data( 'nonce' ) };
		if ( extra ) {
			$.extend( data, extra );
		}
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : action,
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( origText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( origText );
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-bisect-start', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_bisect_start ) ) {
			return;
		}
		var $btn = $( this );
		tsoskBisectPost( 'tsosk_sandbox_bisect_start', null, $btn, $( '#tsosk-bisect-start-msg' ), $btn.text() );
	} );

	$( document ).on( 'click', '#tsosk-bisect-yes', function () {
		var $btn = $( this );
		tsoskBisectPost( 'tsosk_sandbox_bisect_vote', { reproduced: 'yes' }, $btn, $( '#tsosk-bisect-msg' ), $btn.text() );
	} );

	$( document ).on( 'click', '#tsosk-bisect-no', function () {
		var $btn = $( this );
		tsoskBisectPost( 'tsosk_sandbox_bisect_vote', { reproduced: 'no' }, $btn, $( '#tsosk-bisect-msg' ), $btn.text() );
	} );

	$( document ).on( 'click', '#tsosk-bisect-cancel', function () {
		var $btn = $( this );
		tsoskBisectPost( 'tsosk_sandbox_bisect_cancel', null, $btn, $( '#tsosk-bisect-msg' ), $btn.text() );
	} );

} )( jQuery );
