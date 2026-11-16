/**
 * TSO Swiss Knife – Admin JavaScript: history tab.
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


	// ── Activity History: undo one entry ──
	$( document ).on( 'click', '.tsosk-history-undo-btn', function () {
		var $btn = $( this );
		var $cell = $btn.closest( 'td' );
		if ( ! confirm( tsosk.i18n.history_undo_confirm ) ) { return; }
		$btn.prop( 'disabled', true ).text( tsosk.i18n.loading );
		ajaxPost( {
			action : 'tsosk_history_undo',
			data   : { nonce: $btn.data( 'nonce' ), id: $btn.data( 'id' ) },
			success: function ( r ) {
				if ( r.success ) {
					$cell.html( '<span class="tsosk-badge tsosk-badge-info">' + tsosk.i18n.history_reverted + '</span>' );
				} else {
					alert( r.data || tsosk.i18n.error );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.undo );
				}
			},
			error: function () {
				alert( tsosk.i18n.error );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.undo );
			}
		} );
	} );

	// ── Activity History: clear log ──
	$( document ).on( 'click', '#tsosk-history-clear', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-history-msg' );
		if ( ! confirm( tsosk.i18n.history_clear ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_history_clear',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					setTimeout( function () { window.location.reload(); }, 800 );
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

	$( document ).on( 'change', '#tsosk-history-log-all', function () {
		var on = $( this ).is( ':checked' );
		$( '#tsosk-history-modules' ).css( 'opacity', on ? '0.55' : '1' );
		if ( on ) {
			$( '.tsosk-history-module' ).prop( 'checked', true );
		}
	} );

	$( document ).on( 'click', '#tsosk-history-save-settings', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-history-settings-msg' );
		var modules = $( '.tsosk-history-module:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_history_save',
			data   : {
				nonce   : $btn.data( 'nonce' ),
				log_all : $( '#tsosk-history-log-all' ).is( ':checked' ) ? 1 : 0,
				limit   : $( '#tsosk-history-limit' ).val(),
				modules : modules
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// ── Activity History: live filters ──
	function tsoskHistoryFilter() {
		var text  = String( $( '#tsosk-history-filter-text' ).val() || '' ).toLowerCase();
		var tool  = String( $( '#tsosk-history-filter-tool' ).val() || '' );
		var user  = String( $( '#tsosk-history-filter-user' ).val() || '' );
		var shown = 0;
		var $rows = $( '#tsosk-history-table tbody tr' );
		$rows.each( function () {
			var $row = $( this );
			var ok   = ( ! tool || $row.attr( 'data-module' ) === tool )
				&& ( ! user || $row.attr( 'data-user' ) === user )
				&& ( ! text || $row.text().toLowerCase().indexOf( text ) !== -1 );
			$row.toggle( ok );
			if ( ok ) {
				shown++;
			}
		} );
		var $count = $( '#tsosk-history-filter-count' );
		var filtered = text || tool || user;
		$count.text( filtered ? ( tsosk.i18n.history_filter_count || '' ).replace( '%1$d', shown ).replace( '%2$d', $rows.length ) : '' );
		$( '#tsosk-history-filter-empty' ).prop( 'hidden', 0 !== shown );
	}
	$( document ).on( 'input change', '#tsosk-history-filter-text, #tsosk-history-filter-tool, #tsosk-history-filter-user', tsoskHistoryFilter );

} )( jQuery );
