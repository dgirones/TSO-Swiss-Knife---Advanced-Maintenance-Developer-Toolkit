/**
 * TSO Swiss Knife – Admin JavaScript: custom-404 tab.
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


	// ── Custom 404 page ───────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-custom-404-preview', function () {
		var $btn    = $( this );
		var $msg    = $( '#tsosk-custom-404-msg' );
		var pageId  = $( '#tsosk-custom-404-page' ).val();
		var nonce   = $btn.data( 'preview-nonce' );
		var homeUrl = $btn.data( 'home-url' ) || '/';

		if ( ! pageId || pageId === '0' ) {
			showMsg( $msg, tsosk.i18n.custom_404_select_preview || tsosk.i18n.error, 'error' );
			return;
		}

		var url = homeUrl + ( homeUrl.indexOf( '?' ) >= 0 ? '&' : '?' )
			+ 'tsosk_404_preview=1'
			+ '&page_id=' + encodeURIComponent( pageId )
			+ '&preview_nonce=' + encodeURIComponent( nonce );

		if ( $( '#tsosk-custom-404-send-410' ).is( ':checked' ) ) {
			url += '&send_410=1';
		}

		window.open( url, '_blank', 'noopener,noreferrer' );
	} );

	$( document ).on( 'click', '#tsosk-custom-404-save', function () {
		var nonce   = $( this ).data( 'nonce' );
		var $msg    = $( '#tsosk-custom-404-msg' );
		var pageId  = $( '#tsosk-custom-404-page' ).val();

		ajaxPost( {
			action : 'tsosk_custom_404_save',
			data   : {
				nonce              : nonce,
				page_id            : pageId,
				hide_from_search   : $( '#tsosk-custom-404-hide-search' ).is( ':checked' ) ? 1 : 0,
				hide_from_admin    : $( '#tsosk-custom-404-hide-admin' ).is( ':checked' ) ? 1 : 0,
				force_direct_404   : $( '#tsosk-custom-404-force-direct' ).is( ':checked' ) ? 1 : 0,
				disable_url_guess  : $( '#tsosk-custom-404-disable-guess' ).is( ':checked' ) ? 1 : 0,
				send_410           : $( '#tsosk-custom-404-send-410' ).is( ':checked' ) ? 1 : 0
			},
			success: function ( r ) {
				var text = r.success ? ( r.data && r.data.message ? r.data.message : tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				showMsg( $msg, text, r.success ? 'ok' : 'error' );
				if ( r.success && r.data ) {
					var $badge = $( '#tsosk-custom-404-status' );
					if ( r.data.active ) {
						$badge.attr( 'class', 'tsosk-badge tsosk-badge-ok' ).text( tsosk.i18n.custom_404_active || 'Custom 404 active' );
					} else {
						$badge.attr( 'class', 'tsosk-badge tsosk-badge-info' ).text( tsosk.i18n.custom_404_default || 'Using theme default 404' );
					}
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	// Custom 404: copy a shortcode from its card.
	$( document ).on( 'click', '.tsosk-sc-copy', function () {
		var $btn     = $( this );
		var text     = String( $btn.attr( 'data-copy' ) || '' );
		var original = $btn.data( 'label' ) || $btn.text();
		var done     = String( $btn.attr( 'data-done' ) || 'Copied' );
		$btn.data( 'label', original );
		function onCopied() {
			$btn.text( done );
			setTimeout( function () {
				$btn.text( original );
			}, 1500 );
		}
		function fallback() {
			var $tmp = $( '<textarea readonly></textarea>' ).val( text ).css( { position: 'fixed', opacity: 0 } ).appendTo( 'body' );
			$tmp[ 0 ].select();
			try {
				document.execCommand( 'copy' );
				onCopied();
			} catch ( e ) {
				$btn.text( tsosk.i18n.error );
			}
			$tmp.remove();
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onCopied ).catch( fallback );
		} else {
			fallback();
		}
	} );

} )( jQuery );
