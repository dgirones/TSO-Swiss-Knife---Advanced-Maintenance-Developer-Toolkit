/**
 * TSO Swiss Knife – Admin JavaScript: hidden-profiles tab.
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


	// ── Hidden WordPress Profiles ─────────────────────────────────────────
	var tsoskHpPresets = {
		performance: [
			'disable_wp_cron', 'concatenate_scripts', 'disable_emojis', 'disable_embeds'
		],
		content: [
			'limit_revisions', 'slow_autosave', 'empty_trash'
		],
		privacy: [
			'close_comments', 'disable_emojis', 'disable_embeds'
		]
	};

	$( document ).on( 'click', '[data-tsosk-hp-preset]', function () {
		var preset = $( this ).data( 'tsosk-hp-preset' );
		var fields = preset === 'clear' ? [] : ( tsoskHpPresets[ preset ] || [] );
		$( '[data-hp-field]' ).each( function () {
			var field = $( this ).data( 'hp-field' );
			var isRt  = field.indexOf( 'rt_' ) === 0;
			var key   = isRt ? field.slice( 3 ) : field;
			var match = false;
			fields.forEach( function ( f ) {
				if ( f === key || ( isRt && f === key ) || ( ! isRt && f === field ) ) {
					match = true;
				}
			} );
			if ( preset === 'clear' ) {
				if ( $( this ).is( ':checkbox' ) ) {
					$( this ).prop( 'checked', false );
				}
			} else if ( $( this ).is( ':checkbox' ) ) {
				$( this ).prop( 'checked', match );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-hp-save', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-hp-msg' );
		var $panel = $( '#tsosk-hp-panel' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var data = { nonce: $btn.data( 'nonce' ) };

		$panel.find( 'input[name^="tsosk_hp_"]' ).each( function () {
			var $el = $( this );
			var name = $el.attr( 'name' );
			if ( ! name ) {
				return;
			}
			if ( 'checkbox' === $el.attr( 'type' ) ) {
				data[ name ] = $el.prop( 'checked' ) ? 1 : 0;
			} else if ( 'number' === $el.attr( 'type' ) ) {
				data[ name ] = $el.val();
			}
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_hidden_profiles_save',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( saveLabel );
				}
			},
			error: function ( xhr ) {
				var errMsg = tsosk.i18n.error;
				if ( xhr && xhr.responseText === '0' ) {
					errMsg = tsosk.i18n.error + ' (session expired — reload the page and try again)';
				}
				showMsg( $msg, errMsg, 'error' );
				$btn.prop( 'disabled', false ).text( saveLabel );
			}
		} );
	} );

} )( jQuery );
