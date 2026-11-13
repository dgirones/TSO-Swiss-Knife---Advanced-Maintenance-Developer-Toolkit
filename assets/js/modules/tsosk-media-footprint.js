/**
 * TSO Swiss Knife – Admin JavaScript: media-footprint tab.
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



	// ── Media Footprint & Image Sizes Audit ───────────────────────────────────

	// Footprint scan: runs in short server batches; "Resume" continues an interrupted scan.
	function tsoskFootprintRun( $btn, start ) {
		var $main = $( '#tsosk-media-footprint-scan' );
		var $msg  = $( '#tsosk-media-footprint-msg' );
		var $prog = $( '#tsosk-media-footprint-progress' );
		var $out  = $( '#tsosk-media-footprint-results' );
		var nonce = $btn.data( 'nonce' );
		var label = tsosk.i18n.media_footprint_scan || 'Scan uploads folder';
		var fails = 0;

		function finish() {
			$main.prop( 'disabled', false ).text( label );
			$( '#tsosk-media-footprint-resume' ).prop( 'disabled', false );
		}

		function tick( first ) {
			ajaxPost( {
				action : 'tsosk_media_footprint_scan',
				data   : { nonce: nonce, start: first ? 1 : 0 },
				success: function ( r ) {
					fails = 0;
					if ( ! r.success ) {
						showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
						finish();
						return;
					}
					if ( r.data.done ) {
						$( '#tsosk-media-footprint-resume-note' ).remove();
						if ( r.data.html ) {
							$out.html( r.data.html );
						}
						$prog.text( '' );
						showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
						finish();
						return;
					}
					if ( r.data.progress ) {
						$prog.text( r.data.progress );
					}
					tick( false );
				},
				error: function () {
					// The batch position is stored on the server: retry, then offer Resume instead of starting over.
					fails++;
					if ( fails <= 2 ) {
						setTimeout( function () { tick( false ); }, 1500 * fails );
						return;
					}
					showMsg( $msg, tsosk.i18n.media_footprint_interrupted || tsosk.i18n.error, 'error' );
					finish();
					if ( ! $( '#tsosk-media-footprint-resume' ).length ) {
						$main.after( $( '<button type="button" class="button" id="tsosk-media-footprint-resume"></button>' )
							.attr( 'data-nonce', nonce )
							.text( tsosk.i18n.media_resume || 'Resume' ) );
					}
				}
			} );
		}

		$main.prop( 'disabled', true ).text( tsosk.i18n.running );
		$( '#tsosk-media-footprint-resume' ).prop( 'disabled', true );
		showMsg( $msg, '', '' );
		tick( start );
	}

	$( document ).on( 'click', '#tsosk-media-footprint-scan', function () {
		tsoskFootprintRun( $( this ), true );
	} );

	$( document ).on( 'click', '#tsosk-media-footprint-resume', function () {
		tsoskFootprintRun( $( this ), false );
	} );

	$( document ).on( 'click', '#tsosk-media-hygiene-scan', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-media-hygiene-msg' );
		var $out = $( '#tsosk-media-hygiene-results' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );

		ajaxPost( {
			action : 'tsosk_media_hygiene_scan',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success && r.data && r.data.html ) {
					$out.html( r.data.html );
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.media_hygiene_scan || 'Scan removable folders' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.media_hygiene_scan || 'Scan removable folders' );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-media-hygiene-delete', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-media-hygiene-msg' );
		var $out = $( '#tsosk-media-hygiene-results' );
		var $quarantineOut = $( '#tsosk-media-quarantine-results' );
		var label = $btn.data( 'label' ) || '';
		var confirmMsg = tsosk.i18n.media_hygiene_delete_confirm || 'Move this folder to quarantine?';

		if ( label ) {
			confirmMsg = label + '\n\n' + confirmMsg;
		}
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, '' );

		ajaxPost( {
			action : 'tsosk_media_hygiene_delete',
			data   : {
				nonce     : $btn.data( 'nonce' ),
				folder_id : $btn.data( 'folder-id' )
			},
			success: function ( r ) {
				if ( r.success ) {
					if ( r.data && r.data.html ) {
						$out.html( r.data.html );
					}
					if ( r.data && r.data.quarantine_html ) {
						$quarantineOut.html( r.data.quarantine_html );
					}
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
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

	$( document ).on( 'click', '.tsosk-media-quarantine-restore', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-media-hygiene-msg' );
		var $out = $( '#tsosk-media-quarantine-results' );

		if ( ! window.confirm( tsosk.i18n.media_quarantine_restore_confirm || 'Restore this folder?' ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, '' );

		ajaxPost( {
			action : 'tsosk_media_quarantine_restore',
			data   : {
				nonce    : $btn.data( 'nonce' ),
				entry_id : $btn.data( 'entry-id' )
			},
			success: function ( r ) {
				if ( r.success ) {
					if ( r.data && r.data.html ) {
						$out.html( r.data.html );
					}
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
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

	$( document ).on( 'click', '.tsosk-media-quarantine-purge', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-media-hygiene-msg' );
		var $out = $( '#tsosk-media-quarantine-results' );
		var label = $btn.data( 'label' ) || '';
		var confirmMsg = tsosk.i18n.media_quarantine_purge_confirm || 'Permanently delete this folder now?';

		if ( label ) {
			confirmMsg = label + '\n\n' + confirmMsg;
		}
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, '' );

		ajaxPost( {
			action : 'tsosk_media_quarantine_purge',
			data   : {
				nonce    : $btn.data( 'nonce' ),
				entry_id : $btn.data( 'entry-id' )
			},
			success: function ( r ) {
				if ( r.success ) {
					if ( r.data && r.data.html ) {
						$out.html( r.data.html );
					}
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
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

} )( jQuery );
