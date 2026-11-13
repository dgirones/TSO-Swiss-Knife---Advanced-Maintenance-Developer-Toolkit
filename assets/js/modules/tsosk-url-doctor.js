/**
 * TSO Swiss Knife – Admin JavaScript: url-doctor tab.
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


	// ── URL & HTTPS Doctor ─────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-url-doctor-probe', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-url-doctor-msg' );
		var $box  = $( '#tsosk-url-doctor-probe-result' );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_url_doctor_probe',
			data   : { nonce: nonce },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					return;
				}
				var data = r.data || {};
				var $p = $( '<p/>' );
				var okText = ( tsosk.i18n && tsosk.i18n.ok_label ) ? tsosk.i18n.ok_label : 'OK';
				var warnText = ( tsosk.i18n && tsosk.i18n.needs_attention ) ? tsosk.i18n.needs_attention : '!';
				var $badge = $( '<span/>' )
					.addClass( data.ok ? 'tsosk-badge tsosk-badge-ok' : 'tsosk-badge tsosk-badge-warn' )
					.text( data.ok ? okText : warnText );
				$p.append( $badge ).append( document.createTextNode( ' ' + ( data.message || '' ) ) );
				if ( data.final_url ) {
					$p.append( document.createElement( 'br' ) );
					$p.append( $( '<code/>' ).text( String( data.final_url ) ) );
				}
				if ( data.time_text ) {
					$p.append( document.createElement( 'br' ) );
					$p.append( $( '<span/>' ).addClass( 'description' ).text( data.time_text ) );
				}
				$box.empty().append( $p );
				showMsg( $msg, tsosk.i18n.done, 'ok' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-url-doctor-leftovers', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-url-doctor-leftovers-msg' );
		var $box  = $( '#tsosk-url-doctor-leftovers-result' );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_url_doctor_leftovers',
			data   : { nonce: nonce },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					return;
				}
				var data = r.data || {};
				var $p = $( '<p/>' );
				$p.append( document.createTextNode( data.message || '' ) );
				if ( data.replace_url && ! data.skip ) {
					$p.append( document.createElement( 'br' ) );
					$p.append(
						$( '<a/>' )
							.attr( 'href', data.replace_url )
							.text( data.replace_label || 'Search & Replace' )
					);
				}
				if ( data.counts_label && ! data.skip ) {
					$p.append( document.createElement( 'br' ) );
					$p.append(
						$( '<span/>' )
							.addClass( 'description' )
							.text( data.counts_label )
					);
				}
				if ( data.note && ! data.skip ) {
					$p.append( document.createElement( 'br' ) );
					$p.append( $( '<span/>' ).addClass( 'description' ).text( data.note ) );
				}
				$box.empty().append( $p );
				showMsg( $msg, tsosk.i18n.done, 'ok' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
