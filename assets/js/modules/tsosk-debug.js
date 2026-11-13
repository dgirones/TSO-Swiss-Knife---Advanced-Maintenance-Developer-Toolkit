/**
 * TSO Swiss Knife – Admin JavaScript: debug tab.
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


	// ── Debug Mode ─────────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-log-msg' );
		var path  = $btn.data( 'log-path' );
		var label = $btn.data( 'log-label' ) || path;
		var confirmMsg = tsosk.i18n.debug_empty_log_confirm.replace( '%s', label );

		if ( ! confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_debug_clear_log',
			data   : { nonce: nonce, log_path: path },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					var anchor = $btn.closest( 'tr' ).find( 'a[href^="#tsosk-log-"]' ).attr( 'href' );
					if ( anchor ) {
						$( anchor ).find( '.tsosk-log-preview' ).empty();
					}
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

	$( document ).on( 'click', '.tsosk-shrink-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-log-msg' );
		var path  = $btn.data( 'log-path' );
		var label = $btn.data( 'log-label' ) || path;
		var confirmMsg = ( tsosk.i18n.debug_shrink_log_confirm || 'Keep only the last 500 lines of %s? Older lines will be archived.' ).replace( '%s', label );

		if ( ! confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_debug_shrink_log',
			data   : { nonce: nonce, log_path: path, keep_lines: 500 },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) {
					setTimeout( function () { window.location.reload(); }, 1200 );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	function tsoskScrollLogPreviewToEnd( path ) {
		var $preview = path
			? tsoskFindLogTargets( path ).card.find( '.tsosk-log-preview' )
			: $( [] );

		if ( ! $preview.length ) {
			return;
		}

		var el = $preview.get( 0 );
		el.scrollTop = el.scrollHeight;
	}

	function tsoskFindLogTargets( path ) {
		return {
			card: $( '.tsosk-log-card' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} ),
			row: $( '.tsosk-log-row' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} ),
			buttons: $( '.tsosk-refresh-log' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} )
		};
	}

	function tsoskApplyLogRefresh( path, data ) {
		var targets = tsoskFindLogTargets( path );

		if ( ! data.unchanged && data.html && targets.card.length ) {
			targets.card.find( '.tsosk-log-preview' ).html( data.html );
			targets.card.find( '.tsosk-log-size' ).text( data.size );
			targets.card.attr( 'data-log-modified', data.modified );
			targets.card.find( '.tsosk-log-search' ).trigger( 'input' );
		}

		if ( targets.row.length ) {
			targets.row.find( '.tsosk-log-size-cell' ).text( data.size );
			targets.row.find( '.tsosk-log-modified-cell' ).text( data.modified_label || '' );
			targets.row.attr( 'data-log-modified', data.modified );
		}
	}

	$( document ).on( 'click', '.tsosk-refresh-log', function () {
		var $btn   = $( this );
		var nonce  = $btn.data( 'nonce' );
		var path   = $btn.attr( 'data-log-path' ) || '';
		var $msg   = $( '#tsosk-log-msg' );
		var targets = tsoskFindLogTargets( path );
		var source = targets.card.length ? targets.card : targets.row;
		var lastModified = parseInt( source.attr( 'data-log-modified' ) || '0', 10 );
		var label = tsosk.i18n.refresh_log || 'Refresh';

		if ( ! path ) {
			return;
		}

		targets.buttons.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_debug_refresh_log',
			data   : { nonce: nonce, log_path: path, last_modified: lastModified },
			success: function ( r ) {
				if ( r.success && r.data ) {
					tsoskApplyLogRefresh( path, r.data );
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
					tsoskScrollLogPreviewToEnd( path );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				targets.buttons.prop( 'disabled', false ).text( label );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				targets.buttons.prop( 'disabled', false ).text( label );
			}
		} );
	} );

	// Log previews open at the end (newest lines), the first time each one is shown.
	function tsoskScrollPreviewsToEndOnShow() {
		var $previews = $( '.tsosk-log-preview' );
		if ( ! $previews.length ) {
			return;
		}
		function toEnd( el ) {
			el.scrollTop = el.scrollHeight;
		}
		if ( ! window.IntersectionObserver ) {
			$previews.each( function () { toEnd( this ); } );
			return;
		}
		var observer = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					toEnd( entry.target );
					observer.unobserve( entry.target );
				}
			} );
		} );
		$previews.each( function () { observer.observe( this ); } );
	}

	$( tsoskScrollPreviewsToEndOnShow );

	$( document ).on( 'click', '.tsosk-log-scroll-end', function () {
		var $card = $( this ).closest( '.tsosk-log-card' );
		var path  = $card.attr( 'data-log-path' ) || '';

		if ( path ) {
			tsoskScrollLogPreviewToEnd( path );
		}

		var $preview = $card.find( '.tsosk-log-preview' );
		if ( $preview.length && $preview.get( 0 ).scrollIntoView ) {
			$preview.get( 0 ).scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}
	} );

	$( document ).on( 'input change', '.tsosk-log-search, .tsosk-log-level', function () {
		var $card = $( this ).closest( '.tsosk-card' );
		var query = $card.find( '.tsosk-log-search' ).val().toLowerCase();
		var level = $card.find( '.tsosk-log-level' ).val();

		$card.find( '.tsosk-log-line' ).each( function () {
			var $line = $( this );
			var textMatches = ! query || $line.text().toLowerCase().indexOf( query ) !== -1;
			var levelMatches = level === 'all' || $line.data( 'level' ) === level;
			$line.toggle( textMatches && levelMatches );
		} );
	} );

	$( document ).on( 'click', '#tsosk-log-snippet-copy', function () {
		var $msg = $( '#tsosk-log-snippet-msg' );
		var text = $.trim( $( '#tsosk-log-snippet' ).text() );
		var doneLabel = ( tsosk.i18n && tsosk.i18n.copied ) ? tsosk.i18n.copied : 'Copied';
		function fallback() {
			var range = document.createRange();
			range.selectNodeContents( document.getElementById( 'tsosk-log-snippet' ) );
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			try {
				document.execCommand( 'copy' );
				showMsg( $msg, doneLabel, 'ok' );
			} catch ( e ) {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				showMsg( $msg, doneLabel, 'ok' );
			} ).catch( fallback );
		} else {
			fallback();
		}
	} );

} )( jQuery );
