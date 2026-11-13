/**
 * TSO Swiss Knife – Admin JavaScript: content-audit tab.
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


	// ── Content Audit: remove broken shortcodes ──
	function tsoskCaRemoveShortcode( postId, shortcode, $btn ) {
		var $msg = $( '#tsosk-ca-shortcodes-msg' );
		// Lock every button of the table: two removals on the same post would read the same content
		// and the second save would silently undo the first.
		var $all = $( '#tsosk-ca-shortcodes-table button' ).add( $btn );
		$all.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_ca_remove_shortcode',
			data   : {
				nonce     : $btn.data( 'nonce' ),
				post_id   : postId,
				shortcode : shortcode || ''
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$all.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$all.prop( 'disabled', false );
			}
		} );
	}

	$( document ).on( 'click', '.tsosk-ca-remove-sc', function () {
		var $btn = $( this );
		if ( ! confirm( tsosk.i18n.ca_remove_sc_confirm ) ) { return; }
		tsoskCaRemoveShortcode( $btn.data( 'postId' ), $btn.data( 'shortcode' ), $btn );
	} );

	$( document ).on( 'click', '.tsosk-ca-remove-all-sc', function () {
		var $btn = $( this );
		if ( ! confirm( tsosk.i18n.ca_remove_all_confirm ) ) { return; }
		tsoskCaRemoveShortcode( $btn.data( 'postId' ), '', $btn );
	} );

} )( jQuery );
