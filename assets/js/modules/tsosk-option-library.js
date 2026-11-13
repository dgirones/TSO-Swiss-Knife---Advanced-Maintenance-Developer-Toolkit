/**
 * TSO Swiss Knife – Admin JavaScript: option-library tab.
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


	// ── Option Library preview ────────────────────────────────────────────────
	$( document ).on( 'click', '.tsosk-ol-preview', function ( e ) {
		e.preventDefault();
		e.stopPropagation();

		var $btn   = $( this );
		var $box   = $( '#tsosk-ol-preview-box' );
		var $msg   = $( '#tsosk-ol-preview-msg' );
		var name   = $btn.attr( 'data-name' ) || $btn.data( 'name' );
		var nonce  = $btn.attr( 'data-nonce' ) || $btn.data( 'nonce' );
		var label  = $btn.data( 'save-label' ) || $btn.text();

		if ( ! name || ! nonce || ! $box.length ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$( '.tsosk-ol-option-row' ).removeClass( 'is-preview-active' );
		$btn.closest( '.tsosk-ol-option-row' ).addClass( 'is-preview-active' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		$box.show();
		$( '#tsosk-ol-preview-hint' ).hide();

		ajaxPost( {
			action : 'tsosk_ol_preview',
			data   : { nonce: nonce, name: name },
			success: function ( r ) {
				if ( r && r.success && r.data ) {
					$( '#tsosk-ol-preview-name' ).text( r.data.name );
					var meta = 'autoload: ' + r.data.autoload + ', ' + r.data.size + ' bytes';
					if ( r.data.protected ) {
						meta += ' — ' + ( tsosk.i18n.oe_protected || 'Protected' );
					}
					$( '#tsosk-ol-preview-meta' ).text( meta );
					$( '#tsosk-ol-preview-value' ).text( r.data.preview || '' );
					$( '#tsosk-ol-preview-edit' ).attr( 'href', r.data.edit_url || '#' );
					var loaded = tsosk.i18n.ol_preview_loaded || tsosk.i18n.done;
					showMsg( $msg, loaded, 'ok' );
					if ( $box[0] && $box[0].scrollIntoView ) {
						$box[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
					}
				} else {
					var err = ( r && r.data ) ? ( r.data.message || r.data ) : tsosk.i18n.error;
					showMsg( $msg, err, 'error' );
				}
				$btn.prop( 'disabled', false ).text( label );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( label );
			}
		} );
	} );

} )( jQuery );
