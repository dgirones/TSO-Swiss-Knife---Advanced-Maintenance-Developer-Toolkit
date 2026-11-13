/**
 * TSO Swiss Knife – Admin JavaScript: file-integrity tab.
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


	// ── File Integrity ────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-fi-scan, #tsosk-fi-force-scan', function () {
		var $btn   = $( this );
		var force  = $btn.data( 'force' ) ? 1 : 0;
		var nonce  = $btn.data( 'nonce' );
		var $msg   = $( '#tsosk-fi-msg' );
		var $prog  = $( '#tsosk-fi-progress' );
		var $bar   = $( '#tsosk-fi-bar' );
		var $label = $( '#tsosk-fi-progress-label' );
		var $res   = $( '#tsosk-fi-results' );

		$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', true );
		$prog.show();
		$bar.css( 'width', '10%' );
		$label.text( tsosk.i18n.fi_connecting );

		var progInterval = setInterval( function () {
			var cur = parseFloat( $bar.css( 'width' ) ) / parseFloat( $bar.parent().css( 'width' ) ) * 100;
			if ( cur < 85 ) {
				$bar.css( 'width', Math.min( cur + 8, 85 ) + '%' );
			}
		}, 600 );

		$.ajax( {
			url    : tsosk.ajax_url,
			type   : 'POST',
			timeout: 120000,
			data   : { action: 'tsosk_fi_scan', nonce: nonce, force: force },
			success: function ( r ) {
				clearInterval( progInterval );
				$bar.css( 'width', '100%' );
				setTimeout( function () { $prog.fadeOut( 300 ); }, 600 );

				if ( r.success ) {
					$res.html( r.data.html || tsosk.i18n.fi_no_html );
					var issues = ( typeof r.data.n_issues === 'number' )
						? r.data.n_issues
						: ( ( r.data.modified || [] ).length + ( r.data.missing || [] ).length );
					if ( issues === 0 ) {
						showMsg( $msg, tsosk.i18n.fi_clean, 'ok' );
					} else {
						showMsg( $msg, issues + ' ' + tsosk.i18n.fi_issues, 'warn' );
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', false );
			},
			error: function ( xhr ) {
				clearInterval( progInterval );
				$prog.fadeOut( 300 );
				var msg = ( xhr.statusText === 'timeout' )
					? tsosk.i18n.fi_timeout
					: tsosk.i18n.error;
				showMsg( $msg, msg, 'error' );
				$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-fi-view', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var $old = $row.next( '.tsosk-fi-view-row' );
		if ( $old.length ) {
			$old.remove();
			return;
		}
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_fi_view',
			data   : { nonce: $btn.data( 'nonce' ), file: $btn.data( 'file' ) },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				var $pre = $( '<pre class="tsosk-code" style="white-space:pre-wrap;word-break:break-all;margin:0;max-height:220px;overflow:auto;"></pre>' );
				$pre.text( r && r.success ? r.data.content : ( ( r && r.data ) || tsosk.i18n.error ) );
				var $cell = $( '<td></td>' ).attr( 'colspan', $row.children().length ).append( $pre );
				$row.after( $( '<tr class="tsosk-fi-view-row"></tr>' ).append( $cell ) );
			},
			error: function () {
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// After ignoring / un-ignoring a file the server sends back the results (without the ignored files) and the
	// "Ignored Files" card, so the lists and counters are right at once and a file is never shown in both.
	function tsoskFiApplyIgnoreChange( data, $msg ) {
		var d = ( data && 'object' === typeof data ) ? data : { message: data };
		if ( d.html ) {
			$( '#tsosk-fi-results' ).html( d.html );
		}
		$( '#tsosk-fi-ignored-wrap' ).html( d.ignored_html || '' );
		showMsg( $msg, d.message || tsosk.i18n.done, 'ok' );
	}

	$( document ).on( 'click', '.tsosk-fi-ignore', function () {
		var $btn  = $( this );
		var file  = $btn.data( 'file' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-fi-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_fi_ignore',
			data   : { nonce: nonce, file: file },
			success: function ( r ) {
				if ( r.success ) {
					tsoskFiApplyIgnoreChange( r.data, $msg );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_ignore );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_ignore );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-fi-unignore', function () {
		var $btn  = $( this );
		var file  = $btn.data( 'file' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-fi-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_fi_unignore',
			data   : { nonce: nonce, file: file },
			success: function ( r ) {
				if ( r.success ) {
					tsoskFiApplyIgnoreChange( r.data, $msg );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_remove );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_remove );
			}
		} );
	} );

} )( jQuery );
