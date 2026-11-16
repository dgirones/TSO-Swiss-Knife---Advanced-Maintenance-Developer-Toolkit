/**
 * TSO Swiss Knife – Admin JavaScript: users tab.
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
	var tsoskUsersFill = tsoskCore.tsoskUsersFill;


	$( document ).on( 'click', '.tsosk-user-close-sessions', function () {
		var $btn   = $( this );
		var login  = $btn.attr( 'data-user-login' ) || '';
		var label  = $btn.text();
		if ( ! window.confirm( tsoskUsersFill( tsosk.i18n.close_user_sessions_confirm, login ) ) ) {
			return;
		}
		var $msg = $btn.siblings( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_user_destroy_sessions',
			data   : { nonce: $btn.data( 'nonce' ), user_id: $btn.data( 'user-id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					if ( $btn.closest( '#tsosk-users-sessions-list' ).length ) {
						// Refresh only the session cards so the selected role filter is kept.
						setTimeout( function () { tsoskUsersReloadSessions( true ); }, 600 );
					} else {
						$btn.prop( 'disabled', false ).text( label );
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( label );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( label );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-user-close-one-session', function () {
		var $btn  = $( this );
		var login = $btn.attr( 'data-user-login' ) || '';
		if ( ! window.confirm( tsoskUsersFill( tsosk.i18n.close_one_session_confirm, login ) ) ) {
			return;
		}
		var $row = $btn.closest( '.tsosk-users-session-row' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_user_destroy_one_session',
			data   : { nonce: $btn.data( 'nonce' ), user_id: $btn.data( 'user-id' ), token: $btn.data( 'token' ) },
			success: function ( r ) {
				if ( r.success ) {
					$row.fadeOut( 300, function () {
						$( this ).remove();
						// Re-render so the session counters of the account stay accurate.
						tsoskUsersReloadSessions( true );
					} );
				} else {
					window.alert( r.data || tsosk.i18n.error );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				window.alert( tsosk.i18n.error );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-user-force-pwd', function () {
		var $btn  = $( this );
		var login = $btn.attr( 'data-user-login' ) || '';
		if ( ! window.confirm( tsoskUsersFill( tsosk.i18n.force_pwd_confirm, login ) ) ) { return; }
		var $msg = $btn.siblings( '.tsosk-ajax-msg' );
		ajaxPost( {
			action : 'tsosk_user_force_password',
			data   : { nonce: $btn.data( 'nonce' ), user_id: $btn.data( 'user-id' ) },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	function tsoskUsersCheckedIds( $panel ) {
		var ids = [];
		$panel.find( '.tsosk-users-cb:checked' ).each( function () { ids.push( $( this ).val() ); } );
		return ids;
	}

	$( document ).on( 'click', '#tsosk-users-bulk-delete', function () {
		var $panel = $( this ).closest( '.tsosk-users-panel' );
		var nonce  = $( this ).data( 'nonce' ) || $( '#tsosk-users-bulk-nonce' ).val();
		var ids    = tsoskUsersCheckedIds( $panel );
		if ( ! ids.length ) {
			window.alert( tsosk.i18n.users_select_one );
			return;
		}
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $msg = $( '#tsosk-users-bulk-msg' );
		ajaxPost( {
			action : 'tsosk_user_bulk_inactive',
			data   : {
				nonce: nonce,
				bulk_action: 'delete',
				user_ids: ids,
				days: $( '#tsosk-users-inactive-days' ).val() || 90,
				reassign_to: $( '#tsosk-users-reassign' ).val() || 0
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskUsersReloadKeepTab( 700 ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	// Reload the module but come back to the tab the admin was working in.
	function tsoskUsersReloadKeepTab( delay ) {
		try {
			window.sessionStorage.setItem( 'tsosk_users_tab_reload', '1' );
		} catch ( e ) {
			// Without storage the module simply opens on its first tab.
		}
		setTimeout( function () { window.location.reload(); }, delay || 0 );
	}

	function tsoskUsersUpdateCounts( info ) {
		if ( ! info || ! info.counts ) {
			return;
		}
		$.each( info.counts, function ( slug, total ) {
			var el = document.getElementById( 'tsosk-users-count-' + slug );
			if ( el ) {
				el.textContent = String( total );
			}
		} );
	}

	// "Select all matching users": the server resolves the IDs from the active search and role filter.
	var tsoskUsersAllResults = false;
	function tsoskUsersResetAllResults() {
		tsoskUsersAllResults = false;
		$( '#tsosk-users-select-note' ).prop( 'hidden', true ).empty();
		$( '#tsosk-users-all-table .tsosk-users-select-all' ).prop( 'checked', false );
	}

	function tsoskUsersRenderSelectNote() {
		var $note = $( '#tsosk-users-select-note' );
		var total = parseInt( $( '#tsosk-users-search' ).attr( 'data-total' ), 10 ) || 0;
		var limit = parseInt( $( '#tsosk-users-search' ).attr( 'data-limit' ), 10 ) || 0;
		var rows  = $( '#tsosk-users-all-body .tsosk-users-cb' ).length;
		if ( ! $( '#tsosk-users-all-table .tsosk-users-select-all' ).prop( 'checked' ) || total <= rows ) {
			tsoskUsersAllResults = false;
			$note.prop( 'hidden', true ).empty();
			return;
		}
		$note.empty().prop( 'hidden', false );
		if ( tsoskUsersAllResults ) {
			$note.append( document.createTextNode( tsoskUsersFill( tsosk.i18n.users_all_results_selected, String( total ) ) + ' ' ) );
			$( '<button type="button" class="button-link" id="tsosk-users-clear-matching"></button>' ).text( tsosk.i18n.users_clear_selection ).appendTo( $note );
			if ( limit > 0 && total > limit ) {
				$note.append( document.createTextNode( ' ' + tsoskUsersFill( tsosk.i18n.users_all_results_capped, String( limit ) ) ) );
			}
		} else {
			$( '<button type="button" class="button-link" id="tsosk-users-select-matching"></button>' ).text( tsoskUsersFill( tsosk.i18n.users_select_all_results, String( total ) ) ).appendTo( $note );
		}
	}

	$( document ).on( 'change', '#tsosk-users-all-table .tsosk-users-select-all', function () {
		tsoskUsersAllResults = false;
		tsoskUsersRenderSelectNote();
	} );
	$( document ).on( 'change', '#tsosk-users-all-body .tsosk-users-cb', function () {
		if ( tsoskUsersAllResults || $( '#tsosk-users-all-table .tsosk-users-select-all' ).prop( 'checked' ) ) {
			tsoskUsersResetAllResults();
		}
	} );
	$( document ).on( 'click', '#tsosk-users-select-matching', function () {
		tsoskUsersAllResults = true;
		tsoskUsersRenderSelectNote();
	} );
	$( document ).on( 'click', '#tsosk-users-clear-matching', function () {
		tsoskUsersResetAllResults();
		$( '#tsosk-users-all-body .tsosk-users-cb' ).prop( 'checked', false );
	} );

	$( document ).on( 'click', '.tsosk-users-undo-btn', function () {
		var $btn = $( this );
		var $msg = $btn.siblings( '.tsosk-ajax-msg' ).first();
		var isAll = 'all' === $btn.attr( 'data-context' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_user_undo_role',
			data   : { nonce: $btn.attr( 'data-nonce' ) },
			success: function ( r ) {
				var info = r.success && r.data && 'object' === typeof r.data ? r.data : null;
				showMsg( $msg, info ? ( info.message || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( ! r.success ) {
					$btn.prop( 'disabled', false );
					return;
				}
				$btn.remove();
				tsoskUsersUpdateCounts( info );
				if ( isAll ) {
					tsoskUsersSearch( 0 );
				} else {
					$( '#tsosk-users-inactive-days' ).trigger( 'change' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-users-apply-role', function () {
		var $btn     = $( this );
		var $panel   = $btn.closest( '.tsosk-users-panel' );
		var $select  = $panel.find( '.tsosk-users-role-select' );
		var role     = $select.val();
		var roleName = $select.find( 'option:selected' ).text();
		var ids      = tsoskUsersCheckedIds( $panel );
		if ( ! role ) {
			window.alert( tsosk.i18n.users_pick_role );
			return;
		}
		if ( ! ids.length ) {
			window.alert( tsosk.i18n.users_select_one );
			return;
		}
		if ( ! window.confirm( tsoskUsersFill( tsosk.i18n.users_role_confirm, roleName ) ) ) {
			return;
		}
		var allResults = tsoskUsersAllResults && 'all' === $btn.data( 'context' );
		var totalNum   = parseInt( $( '#tsosk-users-search' ).attr( 'data-total' ), 10 ) || 0;
		var limitNum   = parseInt( $( '#tsosk-users-search' ).attr( 'data-limit' ), 10 ) || 0;
		var totalText  = String( limitNum > 0 && totalNum > limitNum ? limitNum : totalNum );
		if ( allResults && ! window.confirm( tsoskUsersFill( tsosk.i18n.users_all_results_confirm, totalText ) ) ) {
			return;
		}
		if ( 'administrator' === role ) {
			var promote = 0;
			$panel.find( '.tsosk-users-cb:checked' ).each( function () {
				var roles = String( $( this ).attr( 'data-roles' ) || '' ).split( ',' );
				if ( roles.indexOf( 'administrator' ) === -1 ) {
					promote++;
				}
			} );
			var promoteText = allResults ? totalText : String( promote );
			if ( ( allResults || promote > 0 ) && ! window.confirm( tsoskUsersFill( tsosk.i18n.users_admin_confirm, promoteText ) ) ) {
				return;
			}
		}
		var $msg = $panel.find( '.tsosk-ajax-msg' ).first();
		var post = {
			nonce: $btn.data( 'nonce' ),
			role: role,
			context: $btn.data( 'context' ),
			user_ids: ids,
			days: $( '#tsosk-users-inactive-days' ).val() || 90
		};
		if ( allResults ) {
			post.all_results = 1;
			post.search      = $( '#tsosk-users-search' ).val() || '';
			post.filter_role = $( '#tsosk-users-role-filter' ).val() || '';
		}
		$panel.find( '.tsosk-users-undo-btn' ).remove();
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_user_set_role',
			data   : post,
			success: function ( r ) {
				var info = r.success && r.data && 'object' === typeof r.data ? r.data : null;
				var text = info ? info.message : r.data;
				showMsg( $msg, r.success ? ( text || tsosk.i18n.done ) : ( text || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					return;
				}
				tsoskUsersUpdateCounts( info );
				var isAll = 'all' === $btn.data( 'context' );
				if ( info && info.undo ) {
					$( '<button type="button" class="button button-small tsosk-users-undo-btn"></button>' )
						.text( tsosk.i18n.users_undo )
						.attr( 'data-nonce', $btn.data( 'nonce' ) )
						.attr( 'data-context', isAll ? 'all' : 'inactive' )
						.insertAfter( $msg );
				}
				if ( isAll ) {
					tsoskUsersResetAllResults();
					tsoskUsersSearch( 0 );
				} else if ( info && info.undo ) {
					// Keep the Undo button reachable: refresh the list instead of reloading.
					$( '#tsosk-users-inactive-days' ).trigger( 'change' );
				} else {
					tsoskUsersReloadKeepTab( 700 );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'change', '.tsosk-users-select-all', function () {
		$( this ).closest( 'table' ).find( '.tsosk-users-cb:enabled' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	var tsoskUsersSortKeys = { login: true, email: true, role: true, registered: true, last: true };
	function tsoskUsersApplySort() {
		var $table = $( '#tsosk-users-all-table' );
		var $btn   = $table.find( 'button.tsosk-users-col-select.is-sorted' ).first();
		if ( ! $btn.length ) {
			return;
		}
		var key = String( $btn.attr( 'data-sort' ) || '' );
		if ( ! Object.prototype.hasOwnProperty.call( tsoskUsersSortKeys, key ) ) {
			return;
		}
		var dir   = $btn.hasClass( 'is-desc' ) ? -1 : 1;
		var $body = $( '#tsosk-users-all-body' );
		var rows  = $body.children( 'tr' ).not( '.tsosk-users-empty' ).get();
		rows.sort( function ( a, b ) {
			var av = $( a ).attr( 'data-' + key ) || '';
			var bv = $( b ).attr( 'data-' + key ) || '';
			if ( 'registered' === key || 'last' === key ) {
				return ( ( parseInt( av, 10 ) || 0 ) - ( parseInt( bv, 10 ) || 0 ) ) * dir;
			}
			av = String( av );
			bv = String( bv );
			if ( av.localeCompare ) {
				return av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } ) * dir;
			}
			if ( av < bv ) {
				return -1 * dir;
			}
			return av > bv ? dir : 0;
		} );
		$.each( rows, function ( _, row ) {
			$body.append( row );
		} );
	}

	$( document ).on( 'click', '#tsosk-users-all-table button.tsosk-users-col-select', function () {
		var $btn = $( this );
		var key  = String( $btn.attr( 'data-sort' ) || '' );
		if ( ! Object.prototype.hasOwnProperty.call( tsoskUsersSortKeys, key ) ) {
			return;
		}
		var desc = $btn.hasClass( 'is-sorted' ) && ! $btn.hasClass( 'is-desc' );
		var $head = $btn.closest( 'thead' );
		$head.find( 'button.tsosk-users-col-select' ).removeClass( 'is-sorted is-desc' );
		$head.find( 'th' ).attr( 'aria-sort', 'none' );
		$btn.addClass( 'is-sorted' );
		if ( desc ) {
			$btn.addClass( 'is-desc' );
		}
		$btn.closest( 'th' ).attr( 'aria-sort', desc ? 'descending' : 'ascending' );
		tsoskUsersApplySort();
	} );

	var tsoskUsersSearchSeq = 0;
	function tsoskUsersSearch( offset ) {
		var $input = $( '#tsosk-users-search' );
		if ( ! $input.length ) {
			return;
		}
		offset = offset || 0;
		var seq   = ++tsoskUsersSearchSeq;
		var $more = $( '#tsosk-users-more' );
		if ( offset > 0 ) {
			$more.prop( 'disabled', true );
		}
		$( '#tsosk-users-search-status' ).text( tsosk.i18n.users_searching || '' );
		ajaxPost( {
			action : 'tsosk_users_search',
			data   : {
				nonce: $input.data( 'nonce' ),
				search: $input.val() || '',
				role: $( '#tsosk-users-role-filter' ).val() || '',
				offset: offset
			},
			success: function ( r ) {
				if ( seq !== tsoskUsersSearchSeq ) {
					return;
				}
				if ( ! r.success || ! r.data ) {
					$more.prop( 'disabled', false );
					$( '#tsosk-users-search-status' ).text( ( r && r.data ) || tsosk.i18n.error );
					return;
				}
				if ( offset > 0 ) {
					if ( r.data.html ) {
						$( '#tsosk-users-all-body' ).append( r.data.html );
					}
				} else {
					$( '#tsosk-users-all-body' ).html( r.data.html || '' );
					if ( null !== r.data.total && undefined !== r.data.total ) {
						$input.attr( 'data-total', String( r.data.total ) );
					}
					tsoskUsersResetAllResults();
				}
				$( '#tsosk-users-search-status' ).text( r.data.message || '' );
				$more.prop( 'disabled', false ).prop( 'hidden', ! r.data.has_more );
				tsoskUsersApplySort();
			},
			error: function () {
				if ( seq !== tsoskUsersSearchSeq ) {
					return;
				}
				$more.prop( 'disabled', false );
				$( '#tsosk-users-search-status' ).text( tsosk.i18n.error );
			}
		} );
	}

	var tsoskUsersSearchTimer = null;
	$( document ).on( 'input search', '#tsosk-users-search', function () {
		window.clearTimeout( tsoskUsersSearchTimer );
		tsoskUsersSearchTimer = window.setTimeout( function () {
			tsoskUsersSearch( 0 );
		}, 250 );
	} );

	$( document ).on( 'change', '#tsosk-users-role-filter', function () {
		tsoskUsersSearch( 0 );
	} );

	$( document ).on( 'click', '#tsosk-users-more', function () {
		var shown = $( '#tsosk-users-all-body tr' ).not( '.tsosk-users-empty' ).length;
		tsoskUsersSearch( shown );
	} );

	var tsoskUsersSessionsSeq = 0;
	function tsoskUsersReloadSessions( silent ) {
		var $select = $( '#tsosk-users-sessions-role' );
		var $note   = $( '#tsosk-users-sessions-note' );
		if ( ! $select.length ) {
			return;
		}
		var seq = ++tsoskUsersSessionsSeq;
		if ( ! silent ) {
			$note.text( tsosk.i18n.users_searching || '' );
		}
		ajaxPost( {
			action : 'tsosk_users_sessions',
			data   : { nonce: $select.data( 'nonce' ), role: $select.val() || '' },
			success: function ( r ) {
				if ( seq !== tsoskUsersSessionsSeq ) {
					return;
				}
				if ( ! r.success || ! r.data ) {
					$note.text( ( r && r.data ) || tsosk.i18n.error );
					return;
				}
				$( '#tsosk-users-sessions-list' ).html( r.data.html || '' );
				$note.text( r.data.message || '' );
			},
			error: function () {
				if ( seq === tsoskUsersSessionsSeq ) {
					$note.text( tsosk.i18n.error );
				}
			}
		} );
	}

	$( document ).on( 'change', '#tsosk-users-sessions-role', function () {
		tsoskUsersReloadSessions( false );
	} );

	var tsoskUsersInactiveSeq = 0;
	$( document ).on( 'change', '#tsosk-users-inactive-days', function () {
		var $select = $( this );
		var seq     = ++tsoskUsersInactiveSeq;
		try {
			window.sessionStorage.setItem( 'tsosk_users_inactive_days', $select.val() || '90' );
		} catch ( e ) {
			// The threshold still applies for this visit.
		}
		ajaxPost( {
			action : 'tsosk_users_inactive',
			data   : { nonce: $select.data( 'nonce' ), days: $select.val() || 90 },
			success: function ( r ) {
				if ( seq !== tsoskUsersInactiveSeq ) {
					return;
				}
				if ( ! r.success || ! r.data ) {
					window.alert( ( r && r.data ) || tsosk.i18n.error );
					return;
				}
				$( '#tsosk-users-inactive-body' ).html( r.data.html || '' );
				$( '#tsosk-users-inactive-count' ).text( r.data.count_label || '' );
				$( '#tsosk-users-inactive-label' ).text( r.data.label || '' );
			},
			error: function () {
				if ( seq === tsoskUsersInactiveSeq ) {
					window.alert( tsosk.i18n.error );
				}
			}
		} );
	} );

	function tsoskUsersFilterHistory() {
		var query  = String( $( '#tsosk-users-history-search' ).val() || '' ).toLowerCase();
		var status = $( '#tsosk-users-history-status' ).val() || '';
		$( '#tsosk-users-history-table tbody tr' ).each( function () {
			var $row    = $( this );
			var matches = ( ! status || $row.attr( 'data-status' ) === status ) && ( ! query || $row.text().toLowerCase().indexOf( query ) !== -1 );
			$row.toggle( matches );
		} );
	}

	$( document ).on( 'input search', '#tsosk-users-history-search', tsoskUsersFilterHistory );
	$( document ).on( 'change', '#tsosk-users-history-status', tsoskUsersFilterHistory );

	function tsoskUsersShowTab( tab ) {
		var $tab = $( '.tsosk-users-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.tsosk-users-tab' ).removeClass( 'nav-tab-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'nav-tab-active' ).attr( 'aria-selected', 'true' );
		$( '.tsosk-users-panel' ).prop( 'hidden', true );
		$( '#tsosk-users-panel-' + tab ).prop( 'hidden', false );
		try {
			window.sessionStorage.setItem( 'tsosk_users_tab', tab );
		} catch ( e ) {
			// Storage unavailable: the tab just is not remembered.
		}
	}

	$( document ).on( 'click', '.tsosk-users-tab', function ( e ) {
		e.preventDefault();
		tsoskUsersShowTab( $( this ).data( 'tab' ) );
	} );

	$( function () {
		if ( ! $( '.tsosk-users-tabs' ).length ) {
			return;
		}
		// Deep-link from Overview (or bookmarks): #tsosk-users-panel-all opens All users.
		var hash = window.location.hash || '';
		if ( 0 === hash.indexOf( '#tsosk-users-panel-' ) ) {
			tsoskUsersShowTab( hash.replace( '#tsosk-users-panel-', '' ) );
			return;
		}
		// Open on Sessions unless this load follows a reload triggered from inside the module.
		var saved = '';
		try {
			if ( '1' === window.sessionStorage.getItem( 'tsosk_users_tab_reload' ) ) {
				saved = window.sessionStorage.getItem( 'tsosk_users_tab' ) || '';
			}
			window.sessionStorage.removeItem( 'tsosk_users_tab_reload' );
		} catch ( e ) {
			saved = '';
		}
		if ( saved ) {
			tsoskUsersShowTab( saved );
		}
		var $days = $( '#tsosk-users-inactive-days' );
		var remembered = '';
		try {
			remembered = window.sessionStorage.getItem( 'tsosk_users_inactive_days' ) || '';
		} catch ( e ) {
			remembered = '';
		}
		if ( [ '30', '60', '90', '180', '365' ].indexOf( remembered ) !== -1 && remembered !== String( $days.val() ) ) {
			$days.val( remembered ).trigger( 'change' );
		}
	} );

	$( document ).on( 'click', '#tsosk-users-clear-history', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) { return; }
		var $btn = $( this );
		var $msg = $( '#tsosk-users-history-msg' );
		ajaxPost( {
			action : 'tsosk_users_clear_history',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskUsersReloadKeepTab( 600 ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

} )( jQuery );
