/**
 * TSO Swiss Knife – Admin JavaScript: media-cleaner tab.
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


	// ── Media Cleaner ──────────────────────────────────────────────────────

	// Full media review: runs in batches; "Resume" continues an interrupted scan from the stored state.
	function tsoskMediaRun( $btn, start ) {
		var $main = $( '#tsosk-media-full-review' );
		var $msg  = $( '#tsosk-media-full-review-msg' );
		var $prog = $( '#tsosk-media-full-review-progress' );
		var nonce = $btn.data( 'nonce' );
		var label = tsosk.i18n.media_full_review || 'Run full media review';
		var fails = 0;

		function finish() {
			$main.prop( 'disabled', false ).text( label );
			$( '#tsosk-media-full-review-resume' ).prop( 'disabled', false );
		}

		function tick( first ) {
			ajaxPost( {
				action : 'tsosk_media_full_review',
				data   : { nonce: nonce, start: first ? 1 : 0 },
				success: function ( r ) {
					fails = 0;
					if ( ! r.success ) {
						showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
						finish();
						return;
					}
					if ( r.data.progress ) {
						$prog.text( r.data.progress );
					}
					if ( r.data.done ) {
						var html = r.data.html || {};
						$( '#tsosk-media-resume-note' ).remove();
						if ( undefined !== html.summary ) {
							$( '#tsosk-media-summary-wrap' ).html( html.summary );
						}
						if ( html.missing ) {
							$( '#tsosk-media-missing-wrap' ).html( html.missing );
						}
						if ( undefined !== html.duplicates ) {
							$( '#tsosk-media-duplicates-wrap' ).html( html.duplicates );
						}
						if ( html.orphans ) {
							$( '#tsosk-media-orphans-wrap' ).html( html.orphans );
						}
						showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
						finish();
						return;
					}
					tick( false );
				},
				error: function () {
					// A slow or failed request keeps the stored state: retry a few times, then offer Resume.
					fails++;
					if ( fails <= 2 ) {
						setTimeout( function () { tick( false ); }, 1500 * fails );
						return;
					}
					showMsg( $msg, tsosk.i18n.media_scan_interrupted || tsosk.i18n.error, 'error' );
					finish();
					if ( ! $( '#tsosk-media-full-review-resume' ).length ) {
						$main.after( $( '<button type="button" class="button" id="tsosk-media-full-review-resume"></button>' )
							.attr( 'data-nonce', nonce )
							.text( tsosk.i18n.media_resume || 'Resume' ) );
					}
				}
			} );
		}

		$main.prop( 'disabled', true ).text( tsosk.i18n.running );
		$( '#tsosk-media-full-review-resume' ).prop( 'disabled', true );
		showMsg( $msg, '', '' );
		if ( start ) {
			$prog.text( tsosk.i18n.media_full_review_starting || 'Starting full media review…' );
		}
		tick( start );
	}

	$( document ).on( 'click', '#tsosk-media-full-review', function () {
		tsoskMediaRun( $( this ), true );
	} );

	$( document ).on( 'click', '#tsosk-media-full-review-resume', function () {
		tsoskMediaRun( $( this ), false );
	} );

	$( document ).on( 'click', '.tsosk-media-regenerate', function () {
		var $btn = $( this );
		var $msg = $btn.siblings( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_media_regenerate',
			data   : { nonce: $btn.data( 'nonce' ), attachment_id: $btn.data( 'attachment-id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.regenerate );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.regenerate );
			}
		} );
	} );

} )( jQuery );
