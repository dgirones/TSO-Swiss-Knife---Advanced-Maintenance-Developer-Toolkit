/**
 * TSO Swiss Knife – Admin JavaScript: server-files tab.
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


	// ── Server Files ──────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-sf-save', function () {
		var $btn    = $( this );
		var key     = $btn.data( 'key' );
		var nonce   = $btn.data( 'nonce' );
		var content = $( '#tsosk-sf-content-' + key ).val();
		var $msg    = $( '.tsosk-sf-msg-' + key );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sf_save',
			data   : { nonce: nonce, key: key, content: content },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );


	// Sitemap review: the table is drawn without HTTP calls, then verified in the background.
	function tsoskSitemapCheck( refresh ) {
		var $card = $( '#tsosk-sf-sitemaps' );
		if ( ! $card.length ) {
			return;
		}
		var $btn = $( '#tsosk-sf-sitemaps-recheck' ).prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_sf_sitemap_check',
			data   : { nonce: $card.data( 'nonce' ), refresh: refresh ? '1' : '0' },
			success: function ( r ) {
				if ( r.success && r.data && typeof r.data.html === 'string' ) {
					$( '#tsosk-sf-sitemaps-body' ).html( r.data.html );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				$btn.prop( 'disabled', false );
			}
		} );
	}
	$( function () {
		tsoskSitemapCheck( false );
	} );
	$( document ).on( 'click', '#tsosk-sf-sitemaps-recheck', function () {
		tsoskSitemapCheck( true );
	} );

	$( document ).on( 'click', '.tsosk-sf-previous', function () {
		var $btn = $( this );
		var key  = $btn.data( 'key' );
		var $msg = $( '.tsosk-sf-msg-' + key );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_sf_previous',
			data   : { nonce: $btn.data( 'nonce' ), key: key },
			success: function ( r ) {
				if ( r.success ) {
					$( '#tsosk-sf-content-' + key ).val( r.data.content );
					showMsg( $msg, r.data.message, 'ok' );
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

	// Put the editor back to the content saved on the server (the textarea's original value).
	$( document ).on( 'click', '.tsosk-sf-discard', function () {
		var $btn  = $( this );
		var key   = $btn.data( 'key' );
		var $area = $( '#tsosk-sf-content-' + key );
		if ( ! $area.length ) {
			return;
		}
		$area.val( $area.prop( 'defaultValue' ) );
		showMsg( $( '.tsosk-sf-msg-' + key ), $btn.attr( 'data-done' ), 'ok' );
	} );

	// Insert the security-headers block into the .htaccess editor (nothing is saved until "Save Changes").
	$( document ).on( 'click', '.tsosk-sf-headers', function () {
		var $btn  = $( this );
		var key   = $btn.data( 'key' );
		var $msg  = $( '.tsosk-sf-msg-' + key );
		var $area = $( '#tsosk-sf-content-' + key );
		var block = String( $btn.attr( 'data-block' ) || '' );
		var cur   = String( $area.val() || '' );

		if ( cur.indexOf( '# BEGIN TSOSK Security Headers' ) !== -1 ) {
			showMsg( $msg, $btn.attr( 'data-exists' ), 'error' );
			return;
		}
		var wp = cur.indexOf( '# BEGIN WordPress' );
		if ( wp !== -1 ) {
			cur = cur.slice( 0, wp ) + block + '\n\n' + cur.slice( wp );
		} else {
			cur = ( cur.replace( /\s+$/, '' ) ? cur.replace( /\s+$/, '' ) + '\n\n' : '' ) + block + '\n';
		}
		$area.val( cur ).trigger( 'focus' );
		showMsg( $msg, $btn.attr( 'data-done' ), 'ok' );
	} );

} )( jQuery );
