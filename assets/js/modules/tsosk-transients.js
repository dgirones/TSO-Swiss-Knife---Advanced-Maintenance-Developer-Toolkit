/**
 * TSO Swiss Knife – Admin JavaScript: transients tab.
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


	// ── Transients Manager ─────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-transient-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn  = $( this );
		var key   = $btn.data( 'key' );
		var nonce = $btn.data( 'nonce' );
		var $row  = $btn.closest( 'tr' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_transient_delete',
			data   : { nonce: nonce, key: key },
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

	$( document ).on( 'click', '#tsosk-purge-expired', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-transient-bulk-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_transients_purge_exp',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					// Remove expired rows from table.
					$( '.tsosk-transient-expired' ).closest( 'tr' ).fadeOut( 400, function () {
						$( this ).remove();
					} );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.purge_expired );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.purge_expired );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-purge-all', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_purge ) ) {
			return;
		}
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-transient-bulk-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_transients_purge_all',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					$( '#tsosk-transients-table tbody tr' ).fadeOut( 400, function () {
						$( this ).remove();
					} );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.purge_all );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.purge_all );
			}
		} );
	} );

	// Transients: live filter.
	$( document ).on( 'input', '#tsosk-transients-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '#tsosk-transients-table tbody tr' ).each( function () {
			var key = $( this ).find( 'td:first' ).text().toLowerCase();
			$( this ).toggle( key.indexOf( q ) !== -1 );
		} );
	} );

	// Transients: status filter tabs.
	$( document ).on( 'click', '.tsosk-transients-filter', function () {
		var filter = $( this ).data( 'filter' );
		$( '.tsosk-transients-filter' ).removeClass( 'tsosk-filter-active' );
		$( this ).addClass( 'tsosk-filter-active' );
		$( '#tsosk-transients-filter-select' ).val( filter );

		$( '#tsosk-transients-table tbody tr' ).each( function () {
			if ( filter === 'all' ) {
				$( this ).show();
			} else if ( filter === 'expired' ) {
				$( this ).toggle( $( this ).hasClass( 'tsosk-transient-expired' ) );
			} else if ( filter === 'active' ) {
				$( this ).toggle( ! $( this ).hasClass( 'tsosk-transient-expired' ) );
			}
		} );
	} );

	// Transients: sync filter pills with server-side filter on load.
	( function () {
		var active = $( '.tsosk-filter-pills' ).data( 'active-filter' );
		if ( active && active !== 'all' ) {
			$( '.tsosk-transients-filter' ).removeClass( 'tsosk-filter-active' );
			$( '.tsosk-transients-filter[data-filter="' + active + '"]' ).addClass( 'tsosk-filter-active' );
		}
	} )();

	// Transients: sortable columns (client-side).
	$( document ).on( 'click', '#tsosk-transients-table th.tsosk-sortable', function () {
		var $th    = $( this );
		var col    = $th.data( 'sort' );
		var asc    = $th.hasClass( 'tsosk-sort-asc' );
		var $tbody = $( '#tsosk-transients-table tbody' );
		var rows   = $tbody.find( 'tr' ).get();

		$( '#tsosk-transients-table th.tsosk-sortable' ).removeClass( 'tsosk-sort-asc tsosk-sort-desc' );
		$th.addClass( asc ? 'tsosk-sort-desc' : 'tsosk-sort-asc' );

		rows.sort( function ( a, b ) {
			var av, bv;
			if ( col === 'key' ) {
				av = $( a ).data( 'key' ) || '';
				bv = $( b ).data( 'key' ) || '';
				return String( av ).localeCompare( String( bv ) );
			}
			if ( col === 'size' || col === 'timeout' ) {
				av = parseInt( $( a ).data( col ), 10 ) || 0;
				bv = parseInt( $( b ).data( col ), 10 ) || 0;
				return av - bv;
			}
			if ( col === 'status' ) {
				av = $( a ).data( 'status' ) || '';
				bv = $( b ).data( 'status' ) || '';
				return String( av ).localeCompare( String( bv ) );
			}
			return 0;
		} );

		if ( asc ) {
			rows.reverse();
		}
		$.each( rows, function ( _, row ) {
			$tbody.append( row );
		} );
	} );

} )( jQuery );
