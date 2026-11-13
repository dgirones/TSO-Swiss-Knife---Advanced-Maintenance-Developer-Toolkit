/**
 * TSO Swiss Knife – Admin JavaScript: health tab.
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


	$( document ).on( 'click', '#tsosk-health-save-suppress', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-health-suppress-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_health_save_suppress',
			data   : {
				nonce: nonce,
				suppress_blog_public: $( '#tsosk-health-suppress-blog-public' ).is( ':checked' ) ? 1 : 0,
				suppress_debug_enabled: $( '#tsosk-health-suppress-debug' ).is( ':checked' ) ? 1 : 0,
				suppress_inactive_plugins: $( '#tsosk-health-suppress-inactive-plugins' ).is( ':checked' ) ? 1 : 0,
				suppress_inactive_themes: $( '#tsosk-health-suppress-inactive-themes' ).is( ':checked' ) ? 1 : 0
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
