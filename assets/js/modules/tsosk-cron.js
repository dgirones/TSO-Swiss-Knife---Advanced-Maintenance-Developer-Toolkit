/**
 * TSO Swiss Knife – Admin JavaScript: cron tab.
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


	// ── Cron Manager ───────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-cron-run', function () {
		var $btn   = $( this );
		var hook   = $btn.data( 'hook' );
		var ts     = $btn.data( 'timestamp' );
		var sig    = $btn.data( 'sig' );
		var nonce  = $btn.data( 'nonce' );
		var $row   = $btn.closest( 'tr' );
		var $msg   = $row.find( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_cron_run',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cron-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn  = $( this );
		var hook  = $btn.data( 'hook' );
		var ts    = $btn.data( 'timestamp' );
		var sig   = $btn.data( 'sig' );
		var nonce = $btn.data( 'nonce' );
		var $row  = $btn.closest( 'tr' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_cron_delete',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					$row.fadeOut( 300, function () { $( this ).remove(); } );
				} else {
					alert( r.data || tsosk.i18n.error );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				alert( tsosk.i18n.error );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cron-edit', function () {
		var $btn = $( this );
		var ts   = parseInt( $btn.data( 'timestamp' ), 10 );
		var when = new Date( ts * 1000 );
		var pad  = function ( n ) { return n < 10 ? '0' + n : String( n ); };
		var local = when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() )
			+ 'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );

		$( '#tsosk-cron-edit-hook' ).val( $btn.data( 'hook' ) );
		$( '#tsosk-cron-edit-timestamp' ).val( $btn.data( 'timestamp' ) );
		$( '#tsosk-cron-edit-sig' ).val( $btn.data( 'sig' ) );
		$( '#tsosk-cron-edit-when' ).val( local );
		$( '#tsosk-cron-edit-schedule' ).val( $btn.data( 'schedule' ) || 'single' );
		$( '#tsosk-cron-edit-msg' ).hide().text( '' );
		$( '#tsosk-cron-edit-panel' ).slideDown( 200 );
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-cron-edit-panel' ).offset().top - 40 }, 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-cancel', function () {
		$( '#tsosk-cron-edit-panel' ).slideUp( 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-cron-edit-msg' );
		var when  = $( '#tsosk-cron-edit-when' ).val();
		if ( ! when ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		var newTs = Math.floor( new Date( when ).getTime() / 1000 );
		if ( ! newTs || isNaN( newTs ) ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cron_reschedule',
			data   : {
				nonce         : $( '#tsosk-cron-edit-nonce' ).val(),
				hook          : $( '#tsosk-cron-edit-hook' ).val(),
				timestamp     : $( '#tsosk-cron-edit-timestamp' ).val(),
				sig           : $( '#tsosk-cron-edit-sig' ).val(),
				new_timestamp : newTs,
				schedule      : $( '#tsosk-cron-edit-schedule' ).val()
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.cron_rescheduled, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
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

	// ── Bulk selection ─────────────────────────────────────────────────────

	var $bulk = $( '#tsosk-cron-bulk' );

	// Checkboxes of the rows that are not hidden by the "without callback" filter.
	function cronBoxes() {
		return $( '#tsosk-cron-table tbody tr' ).not( '.tsosk-cron-row-hide' ).find( '.tsosk-cron-select' );
	}

	function cronSelected() {
		return cronBoxes().filter( ':checked' );
	}

	function cronRefreshBulk() {
		var total = cronBoxes().length;
		var n     = cronSelected().length;
		$( '#tsosk-cron-select-all' )
			.prop( 'checked', total > 0 && n === total )
			.prop( 'indeterminate', n > 0 && n < total );
		$( '#tsosk-cron-bulk-run, #tsosk-cron-bulk-delete' ).prop( 'disabled', 0 === n );
		$( '#tsosk-cron-bulk-count' ).text( n ? tsosk.i18n.cron_bulk_selected.replace( '%d', n ) : '' );
		$( '#tsosk-cron-table tbody tr' ).each( function () {
			$( this ).toggleClass( 'tsosk-cron-row-selected', $( this ).find( '.tsosk-cron-select' ).is( ':checked' ) );
		} );
	}

	$( document ).on( 'change', '#tsosk-cron-select-all', function () {
		cronBoxes().prop( 'checked', this.checked );
		cronRefreshBulk();
	} );

	$( document ).on( 'change', '.tsosk-cron-select', cronRefreshBulk );

	$( document ).on( 'click', '#tsosk-cron-select-none', function () {
		$( '#tsosk-cron-table .tsosk-cron-select' ).prop( 'checked', false );
		cronRefreshBulk();
	} );

	// Filter: only the events whose hook has no callback loaded.
	function cronSetNoCallbackFilter( on ) {
		var $btn = $( '#tsosk-cron-nocb' );
		if ( ! $btn.length ) {
			return;
		}
		$( '#tsosk-cron-table tbody tr' ).each( function () {
			$( this ).toggleClass( 'tsosk-cron-row-hide', on && ! $( this ).is( '[data-nocb]' ) );
		} );
		$( '#tsosk-cron-table .tsosk-cron-select' ).each( function () {
			if ( $( this ).closest( 'tr' ).hasClass( 'tsosk-cron-row-hide' ) ) {
				$( this ).prop( 'checked', false );
			}
		} );
		$btn.attr( 'aria-pressed', on ? 'true' : 'false' ).text( $btn.attr( on ? 'data-label-on' : 'data-label-off' ) );
		cronRefreshBulk();
	}

	$( document ).on( 'click', '#tsosk-cron-nocb', function () {
		var on = 'true' !== $( this ).attr( 'aria-pressed' );
		cronSetNoCallbackFilter( on );
		// Keep the address in step so a page reload does not re-apply a filter that was switched off.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search + ( on ? '#tsosk-cron-nocb' : '' ) );
		}
	} );

	// The dashboard tile links here with #tsosk-cron-nocb: open with the filter already applied.
	if ( '#tsosk-cron-nocb' === window.location.hash && $( '#tsosk-cron-nocb' ).length ) {
		cronSetNoCallbackFilter( true );
	}

	function cronBulk( mode ) {
		var $sel = cronSelected();
		var n    = $sel.length;
		var $msg = $( '#tsosk-cron-bulk-msg' );
		if ( ! n ) {
			return;
		}
		var q = 'delete' === mode ? tsosk.i18n.cron_bulk_confirm_delete : tsosk.i18n.cron_bulk_confirm_run;
		if ( ! window.confirm( q.replace( '%d', n ) ) ) {
			return;
		}
		var items = $sel.map( function () {
			return { hook: $( this ).data( 'hook' ), timestamp: $( this ).data( 'timestamp' ), sig: $( this ).data( 'sig' ) };
		} ).get();

		$( '#tsosk-cron-bulk-run, #tsosk-cron-bulk-delete' ).prop( 'disabled', true );
		showMsg( $msg, 'run' === mode ? tsosk.i18n.running : '…', 'ok' );

		ajaxPost( {
			action : 'tsosk_cron_bulk',
			data   : { nonce: $bulk.data( 'nonce' ), mode: mode, items: JSON.stringify( items ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					cronRefreshBulk();
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				cronRefreshBulk();
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-cron-bulk-run', function () { cronBulk( 'run' ); } );
	$( document ).on( 'click', '#tsosk-cron-bulk-delete', function () { cronBulk( 'delete' ); } );

	// ── Column sorting (click a header: asc → desc → default order) ────────

	var cronSort = { key: '', dir: '' };

	function cronApplySort() {
		var $tb   = $( '#tsosk-cron-table tbody' );
		var $rows = $tb.children( 'tr' ).get();
		var idx   = $( '#tsosk-cron-table thead th' ).index( $( '#tsosk-cron-table thead th[data-sort-key="' + cronSort.key + '"]' ) );
		var numeric = 'time' === cronSort.key || 'interval' === cronSort.key;

		$rows.sort( function ( a, b ) {
			if ( ! cronSort.key ) {
				return parseInt( $( a ).data( 'default' ), 10 ) - parseInt( $( b ).data( 'default' ), 10 );
			}
			var va = $( a ).children( 'td' ).eq( idx ).data( 'sort' );
			var vb = $( b ).children( 'td' ).eq( idx ).data( 'sort' );
			var r;
			if ( numeric ) {
				r = ( parseInt( va, 10 ) || 0 ) - ( parseInt( vb, 10 ) || 0 );
			} else {
				r = String( va ).localeCompare( String( vb ) );
			}
			if ( 0 === r ) {
				r = parseInt( $( a ).data( 'default' ), 10 ) - parseInt( $( b ).data( 'default' ), 10 );
			}
			return 'desc' === cronSort.dir ? -r : r;
		} );
		$.each( $rows, function ( _i, row ) { $tb.append( row ); } );

		$( '#tsosk-cron-table thead th.tsosk-th-sortable' ).each( function () {
			var active = $( this ).data( 'sort-key' ) === cronSort.key;
			$( this )
				.attr( 'aria-sort', active ? ( 'desc' === cronSort.dir ? 'descending' : 'ascending' ) : 'none' )
				.toggleClass( 'tsosk-sort-asc', active && 'asc' === cronSort.dir )
				.toggleClass( 'tsosk-sort-desc', active && 'desc' === cronSort.dir );
		} );
		$( '#tsosk-cron-sort-select' ).val( cronSort.key );
	}

	function cronSortBy( key ) {
		if ( cronSort.key !== key ) {
			cronSort = { key: key, dir: 'asc' };
		} else if ( 'asc' === cronSort.dir ) {
			cronSort.dir = 'desc';
		} else {
			cronSort = { key: '', dir: '' };
		}
		cronApplySort();
	}

	$( document ).on( 'click', '#tsosk-cron-table th.tsosk-th-sortable', function () {
		cronSortBy( $( this ).data( 'sort-key' ) );
	} );

	$( document ).on( 'keydown', '#tsosk-cron-table th.tsosk-th-sortable', function ( e ) {
		if ( 'Enter' === e.key || ' ' === e.key ) {
			e.preventDefault();
			cronSortBy( $( this ).data( 'sort-key' ) );
		}
	} );

	$( document ).on( 'change', '#tsosk-cron-sort-select', function () {
		var key = $( this ).val();
		cronSort = key ? { key: key, dir: 'asc' } : { key: '', dir: '' };
		cronApplySort();
	} );

} )( jQuery );
