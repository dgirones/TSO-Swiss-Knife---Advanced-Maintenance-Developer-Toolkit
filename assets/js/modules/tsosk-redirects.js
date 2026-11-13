/**
 * TSO Swiss Knife – Admin JavaScript: redirects tab.
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


	// ── Health Report ──────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-404-save-alerts', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-404-alerts-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_save_alerts',
			data   : {
				nonce              : $btn.data( 'nonce' ),
				enabled            : $( '#tsosk-alerts-enabled' ).prop( 'checked' ) ? 1 : 0,
				email              : $( '#tsosk-alerts-email' ).val(),
				not_found_threshold: $( '#tsosk-alerts-404-threshold' ).val()
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save_alerts );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save_alerts );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-404-test-alert', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-404-alerts-msg' );
		var label = $btn.text();

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_test_alert',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false ).text( label );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( label );
			}
		} );
	} );

	// ── Redirects ──────────────────────────────────────────────────────────

	var TSOSK_404_PREFILL_KEY = 'tsosk_404_prefill_queue';

	function tsosk404GetPrefillQueue() {
		try {
			var raw = sessionStorage.getItem( TSOSK_404_PREFILL_KEY );
			var arr = raw ? JSON.parse( raw ) : [];
			return Array.isArray( arr ) ? arr.filter( Boolean ) : [];
		} catch ( e ) {
			return [];
		}
	}

	// Source currently shown in the form that came from the queue ('' when typed/edited by hand).
	var tsosk404QueueCurrent = '';

	function tsosk404RefreshQueueUi() {
		var active = tsosk404GetPrefillQueue().length > 0 || '' !== tsosk404QueueCurrent;
		$( '#tsosk-redirect-skip, #tsosk-redirect-cancel-queue' ).prop( 'hidden', ! active );
	}

	function tsosk404SetPrefillQueue( sources ) {
		try {
			sessionStorage.setItem( TSOSK_404_PREFILL_KEY, JSON.stringify( sources || [] ) );
		} catch ( err ) {
			// sessionStorage unavailable — queue falls back to single-row prefill.
		}
		tsosk404RefreshQueueUi();
	}

	function tsosk404UncheckSource( source ) {
		$( '#tsosk-404-table .tsosk-404-select' ).each( function () {
			if ( String( $( this ).data( 'source' ) || '' ) === source ) {
				$( this ).prop( 'checked', false );
			}
		} );
		var $rows     = $( '#tsosk-404-table .tsosk-404-select' );
		var $checked  = $rows.filter( ':checked' );
		$( '#tsosk-404-select-all' ).prop( 'checked', $rows.length > 0 && $checked.length === $rows.length );
	}

	// The redirect form lives in the "Redirects" sub-tab, but the buttons that fill it live in
	// the "404 Monitor" sub-tab: switch tab first, otherwise the form is hidden and nothing seems to happen.
	function tsosk404FocusRedirectForm() {
		rdShowTab( 'rules' );
		var $source = $( '#tsosk-redirect-source' );
		$( '#tsosk-redirect-target' ).focus();
		if ( $source.length && $source.is( ':visible' ) ) {
			$( 'html, body' ).animate( { scrollTop: $source.offset().top - 80 }, 200 );
		}
	}

	function tsosk404PrefillFromQueue( introTotal, silent ) {
		var queue = tsosk404GetPrefillQueue();
		if ( ! queue.length ) {
			return false;
		}
		var source = queue.shift();
		tsosk404SetPrefillQueue( queue );
		if ( ! source ) {
			return tsosk404PrefillFromQueue( introTotal, silent );
		}
		// Do not clear the remaining queue when resetting fields.
		resetRedirectForm( false );
		tsosk404QueueCurrent = source;
		tsosk404RefreshQueueUi();
		$( '#tsosk-redirect-source' ).val( source );
		tsosk404UncheckSource( source );
		var remaining = queue.length;
		var $msg      = $( '#tsosk-redirect-msg' );
		if ( silent ) {
			// The caller shows its own message (skip / duplicate).
		} else if ( introTotal && introTotal > 1 && tsosk.i18n.redirects_prefill_queue ) {
			showMsg( $msg, tsosk.i18n.redirects_prefill_queue.replace( '%1$d', String( introTotal ) ), 'ok' );
		} else if ( remaining > 0 && tsosk.i18n.redirects_prefill_next ) {
			showMsg( $msg, tsosk.i18n.redirects_prefill_next.replace( '%1$d', String( remaining ) ), 'ok' );
		}
		tsosk404FocusRedirectForm();
		return true;
	}

	function resetRedirectForm( clearQueue ) {
		if ( false !== clearQueue ) {
			tsosk404SetPrefillQueue( [] );
		}
		tsosk404QueueCurrent = '';
		tsosk404RefreshQueueUi();
		$( '#tsosk-redirect-id' ).val( '' );
		$( '#tsosk-redirect-source' ).val( '' );
		$( '#tsosk-redirect-target' ).val( '' );
		$( '#tsosk-redirect-match-type' ).val( 'exact' );
		$( '#tsosk-redirect-status' ).val( '301' );
		$( '#tsosk-redirect-enabled' ).prop( 'checked', true );
		$( '#tsosk-redirect-save' ).text( tsosk.i18n.save_redirect );
	}

	// "Clear Form" only empties the fields; the pending queue is kept (use Skip / Cancel queue).
	$( document ).on( 'click', '#tsosk-redirect-reset-form', function () {
		resetRedirectForm( false );
	} );

	$( document ).on( 'click', '#tsosk-redirect-skip', function () {
		var $msg = $( '#tsosk-redirect-msg' );
		if ( tsosk404PrefillFromQueue( 0, true ) ) {
			var remaining = tsosk404GetPrefillQueue().length;
			showMsg( $msg, ( tsosk.i18n.redirects_skipped_next || '' ).replace( '%1$d', String( remaining ) ), 'ok' );
			return;
		}
		resetRedirectForm( false );
		showMsg( $msg, tsosk.i18n.redirects_queue_done || tsosk.i18n.done, 'ok' );
	} );

	$( document ).on( 'click', '#tsosk-redirect-cancel-queue', function () {
		resetRedirectForm( true );
		showMsg( $( '#tsosk-redirect-msg' ), tsosk.i18n.redirects_queue_cancelled || tsosk.i18n.done, 'ok' );
	} );

	$( function () {
		tsosk404RefreshQueueUi();
	} );

	$( document ).on( 'click', '.tsosk-redirect-edit', function () {
		var $btn = $( this );
		$( '#tsosk-redirect-id' ).val( $btn.data( 'id' ) );
		$( '#tsosk-redirect-source' ).val( $btn.data( 'source' ) );
		$( '#tsosk-redirect-target' ).val( $btn.data( 'target' ) );
		$( '#tsosk-redirect-match-type' ).val( $btn.data( 'match-type' ) || 'exact' );
		$( '#tsosk-redirect-status' ).val( String( $btn.data( 'status' ) ) );
		$( '#tsosk-redirect-enabled' ).prop( 'checked', $btn.data( 'enabled' ) === 1 || $btn.data( 'enabled' ) === '1' );
		$( '#tsosk-redirect-save' ).text( tsosk.i18n.update_redirect );
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-redirect-source' ).offset().top - 80 }, 200 );
	} );

	$( document ).on( 'click', '#tsosk-redirect-save', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_redirect_save',
			data   : {
				nonce      : $btn.data( 'nonce' ),
				redirect_id: $( '#tsosk-redirect-id' ).val(),
				source     : $( '#tsosk-redirect-source' ).val(),
				target     : $( '#tsosk-redirect-target' ).val(),
				match_type : $( '#tsosk-redirect-match-type' ).val(),
				status     : $( '#tsosk-redirect-status' ).val(),
				enabled    : $( '#tsosk-redirect-enabled' ).prop( 'checked' ) ? 1 : 0
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					if ( tsosk404GetPrefillQueue().length ) {
						tsosk404PrefillFromQueue( 0 );
						$btn.prop( 'disabled', false ).text( tsosk.i18n.save_redirect );
						return;
					}
					tsosk404SetPrefillQueue( [] );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					var errData = r.data;
					var isObj   = errData && 'object' === typeof errData;
					var errText = isObj ? errData.message : errData;
					// A queued URL that already has a rule must not block the rest of the batch.
					if ( isObj && 'duplicate' === errData.code && '' !== tsosk404QueueCurrent && ! $( '#tsosk-redirect-id' ).val() && $( '#tsosk-redirect-source' ).val() === tsosk404QueueCurrent ) {
						var dupSource = tsosk404QueueCurrent;
						tsosk404UncheckSource( dupSource );
						if ( tsosk404PrefillFromQueue( 0, true ) ) {
							showMsg( $msg, ( tsosk.i18n.redirects_dup_skipped || '' ).replace( '%1$s', dupSource ).replace( '%2$d', String( tsosk404GetPrefillQueue().length ) ), 'ok' );
						} else {
							resetRedirectForm( false );
							showMsg( $msg, ( tsosk.i18n.redirects_dup_skipped_last || '' ).replace( '%1$s', dupSource ), 'ok' );
						}
						$btn.prop( 'disabled', false ).text( tsosk.i18n.save_redirect );
						return;
					}
					showMsg( $msg, errText || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.save_redirect );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save_redirect );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-redirect-toggle', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-msg' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_redirect_toggle',
			data   : { nonce: $btn.data( 'nonce' ), redirect_id: $btn.data( 'id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
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

	$( document ).on( 'click', '.tsosk-redirect-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-msg' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_redirect_delete',
			data   : { nonce: $btn.data( 'nonce' ), redirect_id: $btn.data( 'id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
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

	// ── Redirect Rules list: filter, sort, select, bulk actions, reorder, CSV ──

	// 404 Monitor: click a header to sort the table (URL, visits, last visit, referrer, IP, action).
	function tsosk404Sort( $th ) {
		var col     = String( $th.data( 'sort' ) );
		var numeric = ( 'hits' === col || 'last' === col || 'covered' === col );
		var current = $th.hasClass( 'tsosk-sort-asc' ) ? 'asc' : ( $th.hasClass( 'tsosk-sort-desc' ) ? 'desc' : '' );
		var dir;
		if ( ! current ) {
			dir = ( 'hits' === col || 'last' === col ) ? 'desc' : 'asc'; // Most visits / most recent first.
		} else {
			dir = 'asc' === current ? 'desc' : 'asc';
		}
		$( '#tsosk-404-table th.tsosk-th-sortable' ).removeClass( 'tsosk-sort-asc tsosk-sort-desc' ).attr( 'aria-sort', 'none' );
		$th.addClass( 'asc' === dir ? 'tsosk-sort-asc' : 'tsosk-sort-desc' ).attr( 'aria-sort', 'asc' === dir ? 'ascending' : 'descending' );

		var value = function ( tr ) {
			var $tr = $( tr );
			switch ( col ) {
				case 'url':
					return String( $tr.find( '.tsosk-404-select' ).data( 'source' ) || '' ).toLowerCase();
				case 'hits':
					return parseInt( $tr.attr( 'data-hits' ), 10 ) || 0;
				case 'last':
					return parseInt( $tr.attr( 'data-last' ), 10 ) || 0;
				case 'covered':
					return parseInt( $tr.attr( 'data-covered' ), 10 ) || 0;
				case 'ref':
					return $.trim( $tr.find( '.tsosk-404-col-ref' ).text() ).toLowerCase();
				case 'ip':
					return $.trim( $tr.find( '.tsosk-404-col-ip' ).text() ).toLowerCase();
				default:
					return '';
			}
		};
		var $body = $( '#tsosk-404-table tbody' );
		var rows  = $body.children( 'tr' ).get();
		rows.sort( function ( a, b ) {
			var va = value( a );
			var vb = value( b );
			var diff = numeric ? va - vb : String( va ).localeCompare( String( vb ), undefined, { numeric: true } );
			if ( 'desc' === dir ) {
				diff = -diff;
			}
			return diff || ( ( parseInt( $( a ).attr( 'data-index' ), 10 ) || 0 ) - ( parseInt( $( b ).attr( 'data-index' ), 10 ) || 0 ) );
		} );
		$.each( rows, function ( i, row ) {
			$body.append( row );
		} );
	}
	$( document ).on( 'click', '#tsosk-404-table th.tsosk-th-sortable', function () {
		tsosk404Sort( $( this ) );
	} );
	$( document ).on( 'keydown', '#tsosk-404-table th.tsosk-th-sortable', function ( e ) {
		if ( 'Enter' === e.key || ' ' === e.key ) {
			e.preventDefault();
			tsosk404Sort( $( this ) );
		}
	} );

	// 404 Monitor: only rows not hidden by the live filter count for selection.
	function tsosk404Boxes() {
		return $( '#tsosk-404-table tbody tr' ).not( '.tsosk-404-hidden' ).find( '.tsosk-404-select' );
	}

	function tsosk404UpdateCount() {
		var $count = $( '#tsosk-404-count' );
		if ( ! $count.length ) {
			return;
		}
		var total = $( '#tsosk-404-table tbody tr' ).length;
		var shown = $( '#tsosk-404-table tbody tr' ).not( '.tsosk-404-hidden' ).length;
		var text  = shown ? String( $count.data( 'template' ) ) : String( $count.data( 'empty' ) );
		$count.text( text.replace( '{shown}', shown ).replace( '{total}', total ) );
	}

	$( document ).on( 'input search', '#tsosk-404-search', function () {
		var q = String( $( this ).val() || '' ).trim().toLowerCase();
		$( '#tsosk-404-table tbody tr' ).each( function () {
			var $tr   = $( this );
			var match = ! q || $tr.text().toLowerCase().indexOf( q ) !== -1 || String( $tr.find( '.tsosk-404-select' ).data( 'source' ) || '' ).toLowerCase().indexOf( q ) !== -1;
			$tr.toggleClass( 'tsosk-404-hidden', ! match );
			if ( ! match ) {
				// Never leave hidden rows selected: bulk actions must only touch what is visible.
				$tr.find( '.tsosk-404-select' ).prop( 'checked', false );
			}
		} );
		tsosk404UpdateCount();
		tsosk404SyncSelectAll();
	} );
	$( tsosk404UpdateCount );

	function tsosk404SyncSelectAll() {
		var $rows    = tsosk404Boxes();
		var $checked = $rows.filter( ':checked' );
		$( '#tsosk-404-select-all' ).prop( 'checked', $rows.length > 0 && $checked.length === $rows.length );
	}

	function tsoskVisibleRuleBoxes() {
		return $( '#tsosk-redirects-table tbody tr' ).not( '.tsosk-row-hidden' ).find( '.tsosk-redirect-select' );
	}

	function tsoskRulesCompare( sort ) {
		var num = function ( el, key ) {
			return parseInt( $( el ).attr( 'data-' + key ), 10 ) || 0;
		};
		return function ( a, b ) {
			var diff = 0;
			switch ( sort ) {
				case 'hits_desc':
					diff = num( b, 'hits' ) - num( a, 'hits' );
					break;
				case 'hits_asc':
					diff = num( a, 'hits' ) - num( b, 'hits' );
					break;
				case 'last_desc':
					diff = num( b, 'last-hit' ) - num( a, 'last-hit' );
					break;
				case 'last_asc':
					diff = num( a, 'last-hit' ) - num( b, 'last-hit' );
					break;
				case 'source_az':
					diff = String( $( a ).attr( 'data-source' ) ).localeCompare( String( $( b ).attr( 'data-source' ) ) );
					break;
				default:
					break;
			}
			// Ties (and the default order) keep the real evaluation order.
			return diff || ( num( a, 'index' ) - num( b, 'index' ) );
		};
	}

	function tsoskRuleMatchesFilter( $row, filter ) {
		var status = parseInt( $row.attr( 'data-status' ), 10 ) || 0;
		switch ( filter ) {
			case 'enabled':
				return '1' === String( $row.attr( 'data-enabled' ) );
			case 'disabled':
				return '1' !== String( $row.attr( 'data-enabled' ) );
			case 'gone':
				return 410 === status || 451 === status;
			case 'redirect':
				return status >= 300 && status < 400;
			case 'pattern':
				return 'exact' !== String( $row.attr( 'data-match' ) );
			default:
				return true;
		}
	}

	function tsoskRulesApply() {
		var $table = $( '#tsosk-redirects-table' );
		if ( ! $table.length ) {
			return;
		}
		var query  = String( $( '#tsosk-redirect-search' ).val() || '' ).trim().toLowerCase();
		var filter = String( $( '#tsosk-redirect-filter' ).val() || 'all' );
		var sort   = String( $( '#tsosk-redirect-sort' ).val() || 'default' );
		var $body  = $table.children( 'tbody' );
		var rows   = $body.children( 'tr' ).get();

		rows.sort( tsoskRulesCompare( sort ) );
		$.each( rows, function ( i, row ) {
			$body.append( row );
		} );

		var shown = 0;
		$( rows ).each( function () {
			var $row = $( this );
			var hay  = String( $row.attr( 'data-search' ) || '' ).toLowerCase();
			var ok   = ( '' === query || -1 !== hay.indexOf( query ) ) && tsoskRuleMatchesFilter( $row, filter );
			$row.toggleClass( 'tsosk-row-hidden', ! ok );
			if ( ok ) {
				shown++;
			} else {
				// A hidden row must never stay selected: a bulk action would hit it unseen.
				$row.find( '.tsosk-redirect-select' ).prop( 'checked', false );
			}
		} );

		var viewActive = 'default' !== sort || '' !== query || 'all' !== filter;
		$table.toggleClass( 'tsosk-rules-view-active', viewActive );
		$( '#tsosk-redirect-no-match' ).prop( 'hidden', shown > 0 || ! rows.length );
		if ( tsosk.i18n.redirects_showing ) {
			$( '#tsosk-redirect-count' ).text( tsosk.i18n.redirects_showing.replace( '%1$d', String( shown ) ).replace( '%2$d', String( rows.length ) ) );
		}
		tsosk404SyncRuleSelectAll();
	}

	$( document ).on( 'input change', '#tsosk-redirect-search, #tsosk-redirect-filter, #tsosk-redirect-sort', tsoskRulesApply );

	$( function () {
		tsoskRulesApply();
	} );

	$( document ).on( 'click', '#tsosk-redirect-select-visible', function () {
		tsoskVisibleRuleBoxes().prop( 'checked', true );
		tsosk404SyncRuleSelectAll();
	} );

	$( document ).on( 'click', '#tsosk-redirect-select-none', function () {
		$( '#tsosk-redirects-table .tsosk-redirect-select' ).prop( 'checked', false );
		tsosk404SyncRuleSelectAll();
	} );

	$( document ).on( 'click', '#tsosk-404-select-all-btn', function () {
		tsosk404Boxes().prop( 'checked', true );
		tsosk404SyncSelectAll();
	} );

	$( document ).on( 'click', '#tsosk-404-select-none-btn', function () {
		tsosk404Boxes().prop( 'checked', false );
		tsosk404SyncSelectAll();
	} );

	// Rules with no traffic for N months (or never hit and older than N months).
	$( document ).on( 'click', '#tsosk-redirect-select-unused', function () {
		var months = parseInt( $( '#tsosk-redirect-unused-months' ).val(), 10 );
		if ( ! months || months < 1 ) {
			months = 6;
		}
		var cutoff = Math.floor( Date.now() / 1000 ) - months * 30 * 86400;
		var count  = 0;
		var $msg   = $( '#tsosk-redirect-bulk-msg' );
		$( '#tsosk-redirects-table .tsosk-redirect-select' ).prop( 'checked', false );
		tsoskVisibleRuleBoxes().each( function () {
			var $row    = $( this ).closest( 'tr' );
			var hits    = parseInt( $row.attr( 'data-hits' ), 10 ) || 0;
			var last    = parseInt( $row.attr( 'data-last-hit' ), 10 ) || 0;
			var created = parseInt( $row.attr( 'data-created' ), 10 ) || 0;
			var unused  = 0 === hits ? ( 0 === created || created < cutoff ) : ( last > 0 && last < cutoff );
			if ( unused ) {
				$( this ).prop( 'checked', true );
				count++;
			}
		} );
		tsosk404SyncRuleSelectAll();
		if ( count && tsosk.i18n.redirects_unused_selected ) {
			showMsg( $msg, tsosk.i18n.redirects_unused_selected.replace( '%1$d', String( count ) ).replace( '%2$d', String( months ) ), 'ok' );
		} else {
			showMsg( $msg, tsosk.i18n.redirects_unused_none || tsosk.i18n.done, 'ok' );
		}
	} );

	// Shift+click selects the whole range between two checkboxes (both tables).
	var tsoskLastClicked = {};
	$( document ).on( 'click', '#tsosk-redirects-table .tsosk-redirect-select, #tsosk-404-table .tsosk-404-select', function ( e ) {
		var is404 = $( this ).hasClass( 'tsosk-404-select' );
		var key   = is404 ? '404' : 'rules';
		var $all  = is404 ? tsosk404Boxes() : tsoskVisibleRuleBoxes();
		var idx   = $all.index( this );
		if ( e.shiftKey && undefined !== tsoskLastClicked[ key ] && tsoskLastClicked[ key ] < $all.length && idx > -1 ) {
			var from = Math.min( idx, tsoskLastClicked[ key ] );
			var to   = Math.max( idx, tsoskLastClicked[ key ] );
			$all.slice( from, to + 1 ).prop( 'checked', $( this ).prop( 'checked' ) );
		}
		tsoskLastClicked[ key ] = idx;
		if ( is404 ) {
			tsosk404SyncSelectAll();
		} else {
			tsosk404SyncRuleSelectAll();
		}
	} );

	function tsoskSelectedRuleIds() {
		var ids = [];
		$( '#tsosk-redirects-table .tsosk-redirect-select:checked' ).each( function () {
			var id = String( $( this ).data( 'id' ) || '' );
			if ( id ) {
				ids.push( id );
			}
		} );
		return ids;
	}

	$( document ).on( 'click', '.tsosk-redirect-bulk-toggle', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-bulk-msg' );
		var ids  = tsoskSelectedRuleIds();
		if ( ! ids.length ) {
			showMsg( $msg, tsosk.i18n.redirects_select_rules || tsosk.i18n.error, 'error' );
			return;
		}
		var originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_redirect_bulk_toggle',
			data   : { nonce: $btn.data( 'nonce' ), ids: ids, enabled: 1 === parseInt( $btn.data( 'enabled' ), 10 ) ? 1 : 0 },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-redirect-move-btn', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-bulk-msg' );
		$( '.tsosk-redirect-move-btn' ).prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_redirect_move',
			data   : { nonce: $btn.data( 'nonce' ), redirect_id: $btn.data( 'id' ), direction: $btn.data( 'dir' ) },
			success: function ( r ) {
				if ( r.success ) {
					window.location.reload();
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$( '.tsosk-redirect-move-btn' ).prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$( '.tsosk-redirect-move-btn' ).prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-redirect-export', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-io-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_redirect_export',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success || ! r.data || 'string' !== typeof r.data.csv ) {
					showMsg( $msg, ( 'string' === typeof r.data && r.data ) || tsosk.i18n.error, 'error' );
					return;
				}
				var blob = new window.Blob( [ '\ufeff' + r.data.csv ], { type: 'text/csv;charset=utf-8' } );
				var url  = window.URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				a.href     = url;
				a.download = r.data.filename || 'tsosk-redirects.csv';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				setTimeout( function () {
					window.URL.revokeObjectURL( url );
				}, 1000 );
				showMsg( $msg, tsosk.i18n.redirects_export_done || tsosk.i18n.done, 'ok' );
			},
			error: function () {
				$btn.prop( 'disabled', false );
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	$( document ).on( 'change', '#tsosk-redirect-import-file', function () {
		var file = this.files && this.files[ 0 ];
		if ( ! file || ! window.FileReader ) {
			return;
		}
		var reader = new window.FileReader();
		reader.onload = function () {
			$( '#tsosk-redirect-import-text' ).val( String( reader.result || '' ).replace( /^\ufeff/, '' ) );
		};
		reader.readAsText( file );
	} );

	$( document ).on( 'click', '#tsosk-redirect-import', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-redirect-io-msg' );
		var csv  = String( $( '#tsosk-redirect-import-text' ).val() || '' ).trim();
		if ( '' === csv ) {
			showMsg( $msg, tsosk.i18n.redirects_import_empty || tsosk.i18n.error, 'error' );
			return;
		}
		var originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_redirect_import',
			data   : { nonce: $btn.data( 'nonce' ), csv: csv },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					if ( r.data && r.data.created ) {
						setTimeout( function () {
							window.location.reload();
						}, 1500 );
						return;
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( originalText );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	} );

	// Redirect Rules list: select several rules and delete them in one go.
	function tsosk404SyncRuleSelectAll() {
		var $rows    = tsoskVisibleRuleBoxes();
		var $checked = $rows.filter( ':checked' );
		$( '#tsosk-redirect-select-all' ).prop( 'checked', $rows.length > 0 && $checked.length === $rows.length );
	}

	$( document ).on( 'change', '#tsosk-redirect-select-all', function () {
		tsoskVisibleRuleBoxes().prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	$( document ).on( 'change', '#tsosk-redirects-table .tsosk-redirect-select', tsosk404SyncRuleSelectAll );

	$( document ).on( 'click', '#tsosk-redirect-delete-selected', function () {
		var $btn = $( this );
		var ids  = tsoskSelectedRuleIds();
		var $msg = $( '#tsosk-redirect-bulk-msg' );
		if ( ! ids.length ) {
			showMsg( $msg, tsosk.i18n.redirects_select_rules || tsosk.i18n.error, 'error' );
			return;
		}
		if ( tsosk.i18n.redirects_confirm_delete_selected && ! window.confirm( tsosk.i18n.redirects_confirm_delete_selected.replace( '%1$d', String( ids.length ) ) ) ) {
			return;
		}
		var originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_redirect_delete_selected',
			data   : { nonce: $btn.data( 'nonce' ), ids: ids },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-404-create-redirect', function () {
		resetRedirectForm();
		$( '#tsosk-redirect-source' ).val( $( this ).data( 'source' ) );
		tsosk404FocusRedirectForm();
	} );

	$( document ).on( 'change', '#tsosk-404-select-all', function () {
		tsosk404Boxes().prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	$( document ).on( 'change', '#tsosk-404-table .tsosk-404-select', function () {
		var $rows    = tsosk404Boxes();
		var $checked = $rows.filter( ':checked' );
		$( '#tsosk-404-select-all' ).prop( 'checked', $rows.length > 0 && $checked.length === $rows.length );
	} );

	$( document ).on( 'click', '#tsosk-404-prefill-selected', function () {
		var $checked = $( '#tsosk-404-table .tsosk-404-select:checked' );
		if ( ! $checked.length ) {
			window.alert( tsosk.i18n.redirects_select_404 || tsosk.i18n.error );
			return;
		}
		var sources        = [];
		var skippedCovered = 0;
		var $prefillMsg    = $( '#tsosk-404-msg' );
		var $formMsg       = $( '#tsosk-redirect-msg' );
		$checked.each( function () {
			var $row   = $( this );
			var source = String( $row.data( 'source' ) || '' );
			if ( ! source ) {
				return;
			}
			// Already covered by an enabled rule: saving would only fail as a duplicate.
			if ( $row.data( 'covered' ) ) {
				skippedCovered++;
				$row.prop( 'checked', false );
				return;
			}
			sources.push( source );
		} );
		if ( ! sources.length ) {
			if ( skippedCovered ) {
				showMsg( $prefillMsg, tsosk.i18n.redirects_all_covered || tsosk.i18n.error, 'error' );
			}
			return;
		}
		tsosk404SetPrefillQueue( sources.slice() );
		tsosk404PrefillFromQueue( sources.length );
		if ( skippedCovered && tsosk.i18n.redirects_skip_covered ) {
			var note = tsosk.i18n.redirects_skip_covered.replace( '%1$d', String( skippedCovered ) );
			showMsg( $formMsg, sources.length > 1 ? $formMsg.text() + ' ' + note : note, 'ok' );
		}
	} );

	// Bulk: one exact rule per selected URL (410, or 301 to one target). Covered URLs are skipped.
	function tsosk404Bulk( mode, $btn ) {
		var paths = [];
		$( '#tsosk-404-table .tsosk-404-select:checked' ).each( function () {
			var source = String( $( this ).data( 'source' ) || '' );
			if ( source ) {
				paths.push( source );
			}
		} );
		if ( ! paths.length ) {
			window.alert( tsosk.i18n.redirects_select_404 || tsosk.i18n.error );
			return;
		}
		var target = '';
		if ( 'redirect' === mode ) {
			target = String( $( '#tsosk-404-bulk-target' ).val() || '' ).trim();
			if ( '' === target ) {
				window.alert( tsosk.i18n.redirects_bulk_need_target || tsosk.i18n.error );
				return;
			}
		}
		var confirmText = 'gone' === mode ? tsosk.i18n.redirects_bulk_confirm_gone : tsosk.i18n.redirects_bulk_confirm_redirect;
		if ( confirmText && ! window.confirm( confirmText.replace( '%1$d', String( paths.length ) ) ) ) {
			return;
		}

		var $msg         = $( '#tsosk-404-msg' );
		var originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_bulk_rules',
			data   : { nonce: $btn.data( 'nonce' ), mode: mode, target: target, paths: paths },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					var text = r.data && 'object' === typeof r.data ? r.data.message : r.data;
					showMsg( $msg, text || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-404-bulk-gone', function () {
		tsosk404Bulk( 'gone', $( this ) );
	} );

	$( document ).on( 'click', '#tsosk-404-bulk-redirect', function () {
		tsosk404Bulk( 'redirect', $( this ) );
	} );

	// Suggested patterns: one 410 rule for a whole first-segment prefix (e.g. /en/, /ca/).
	$( document ).on( 'click', '.tsosk-404-prefix-gone', function () {
		var $btn   = $( this );
		var prefix = String( $btn.data( 'prefix' ) || '' );
		if ( ! prefix ) {
			return;
		}
		if ( tsosk.i18n.redirects_prefix_confirm && ! window.confirm( tsosk.i18n.redirects_prefix_confirm.replace( '%1$s', '/' + prefix + '/' ) ) ) {
			return;
		}
		var $msg         = $( '#tsosk-404-msg' );
		var originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_prefix_gone',
			data   : { nonce: $btn.data( 'nonce' ), prefix: prefix },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 900 );
				} else {
					var text = r.data && 'object' === typeof r.data ? r.data.message : r.data;
					showMsg( $msg, text || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-404-prefix-prefill', function () {
		var prefix = String( $( this ).data( 'prefix' ) || '' );
		if ( ! prefix ) {
			return;
		}
		resetRedirectForm();
		$( '#tsosk-redirect-match-type' ).val( 'regex' );
		$( '#tsosk-redirect-source' ).val( '^/' + prefix + '(/|$)' );
		tsosk404FocusRedirectForm();
	} );

	$( document ).on( 'click', '#tsosk-404-clear', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-404-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_clear',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					$( '#tsosk-404-select-all' ).prop( 'checked', false );
					$( '#tsosk-404-table tbody tr' ).fadeOut( 300, function () {
						$( this ).remove();
					} );
					$btn.fadeOut( 300 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.clear_404_log );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.clear_404_log );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-404-delete-selected', function () {
		var $btn     = $( this );
		var $checked = $( '#tsosk-404-table .tsosk-404-select:checked' );
		if ( ! $checked.length ) {
			window.alert( tsosk.i18n.redirects_select_404 || tsosk.i18n.error );
			return;
		}
		var paths = [];
		$checked.each( function () {
			var source = String( $( this ).data( 'source' ) || '' );
			if ( source ) {
				paths.push( source );
			}
		} );
		if ( ! paths.length ) {
			return;
		}

		var $msg         = $( '#tsosk-404-msg' );
		var originalText = $btn.text();

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_404_delete_selected',
			data   : { nonce: $btn.data( 'nonce' ), paths: paths },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					$checked.each( function () {
						$( this ).closest( 'tr' ).fadeOut( 300, function () {
							$( this ).remove();
						} );
					} );
					$( '#tsosk-404-select-all' ).prop( 'checked', false );
					$btn.prop( 'disabled', false ).text( originalText );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( originalText );
			}
		} );
	} );

	// Redirects: sub-tabs (remembered for the browser session).
	function rdShowTab( tab ) {
		var $tab = $( '.tsosk-rd-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.tsosk-rd-tab' ).removeClass( 'nav-tab-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'nav-tab-active' ).attr( 'aria-selected', 'true' );
		$( '.tsosk-rd-panel' ).prop( 'hidden', true );
		$( '#tsosk-rd-panel-' + tab ).prop( 'hidden', false );
		try {
			window.sessionStorage.setItem( 'tsosk_rd_tab', tab );
		} catch ( e ) {
			// Storage unavailable: the tab just is not remembered.
		}
	}

	$( document ).on( 'click', '.tsosk-rd-tab', function ( e ) {
		e.preventDefault();
		rdShowTab( $( this ).data( 'tab' ) );
	} );

	$( function () {
		if ( ! $( '.tsosk-rd-tabs' ).length ) {
			return;
		}
		var saved = '';
		try {
			saved = window.sessionStorage.getItem( 'tsosk_rd_tab' ) || '';
		} catch ( e ) {
			saved = '';
		}
		if ( saved ) {
			rdShowTab( saved );
		}
	} );

} )( jQuery );
