/**
 * TSO Swiss Knife – Admin JavaScript: options-editor tab.
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


	// ── Options Editor ─────────────────────────────────────────────────────


	var tsosk_oe = {
		lastSearch     : '',
		currentPage    : 1,
		totalPages     : 1,
		sortCol        : 'option_name',
		sortDir        : 'ASC',
		showProtected  : false,
		exactSearch    : false,
		autoOpen       : false,
		readOnly       : false,
	};

	// Tab switching
	$( document ).on( 'click', '.tsosk-oe-tab-btn', function () {
		var tab = $( this ).data( 'tab' );
		$( '.tsosk-oe-tab-btn' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		$( '.tsosk-oe-panel' ).hide();
		$( '#tsosk-oe-panel-' + tab ).show();
		$( '#tsosk-oe-editor' ).hide();
		// Re-trigger search when returning to Browse tab if table is empty
		if ( tab === 'list' ) {
			var $tbody = $( '#tsosk-oe-tbody' );
			if ( ! $tbody.find( 'tr' ).length || $tbody.find( 'td[colspan]' ).length ) {
				tsosk_oe_search( tsosk_oe.lastSearch || '', tsosk_oe.currentPage || 1 );
			}
		}
	} );

	// Auto-load all options when Browse tab is first visible
	var tsosk_oe_search_seq = 0;
	function tsosk_oe_search( search, page ) {
		tsosk_oe.lastSearch  = search;
		tsosk_oe.currentPage = page || 1;
		var nonce    = $( '#tsosk-oe-nonce' ).val();
		var $msg     = $( '#tsosk-oe-search-msg' );
		var $spinner = $( '#tsosk-oe-tbody' );
		var reqId    = ++tsosk_oe_search_seq;

		$spinner.html( '<tr><td colspan="6" style="text-align:center;padding:14px;color:#666;"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' + tsosk.i18n.loading + '</td></tr>' );

		ajaxPost( {
			action : 'tsosk_oe_search',
			data   : {
				nonce            : nonce,
				search           : search,
				page             : tsosk_oe.currentPage,
				sort_col         : tsosk_oe.sortCol,
				sort_dir         : tsosk_oe.sortDir,
				filter_type      : $( '#tsosk-oe-filter-type' ).val() || '',
				filter_autoload  : $( '#tsosk-oe-filter-autoload' ).val() || '',
				min_size_kb      : $( '#tsosk-oe-min-size' ).val() || 0,
				show_protected   : tsosk_oe.showProtected ? 1 : 0,
				exact            : tsosk_oe.exactSearch ? 1 : 0,
			},
			success: function ( r ) {
				if ( reqId !== tsosk_oe_search_seq ) {
					return;
				}
				if ( r.success ) {
					tsosk_oe.totalPages = r.data.total_pages;
					tsosk_oe_render_table( r.data );
					showMsg( $msg, r.data.total + ' ' + tsosk.i18n.oe_options, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
			},
			error: function () {
				if ( reqId !== tsosk_oe_search_seq ) {
					return;
				}
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	}

	var tsosk_oe_search_timer = null;
	function tsosk_oe_schedule_search() {
		clearTimeout( tsosk_oe_search_timer );
		tsosk_oe_search_timer = setTimeout( function () {
			tsosk_oe_search( $( '#tsosk-oe-search' ).val().trim(), 1 );
		}, 350 );
	}

	// Auto-load on page ready (always load when editor is present)
	$( function () {
		if ( ! $( '#tsosk-oe-nonce' ).length ) {
			return;
		}
		var initialSearch = '';
		var autoOpen      = false;
		if ( typeof URLSearchParams !== 'undefined' ) {
			var params = new URLSearchParams( window.location.search );
			var oeParam = params.get( 'oe_search' );
			if ( oeParam ) {
				initialSearch = oeParam;
				$( '#tsosk-oe-search' ).val( initialSearch );
			}
			if ( '1' === params.get( 'oe_protected' ) ) {
				tsosk_oe.showProtected = true;
				var $protBtn = $( '#tsosk-oe-toggle-protected' );
				if ( $protBtn.length ) {
					$protBtn.attr( 'aria-pressed', 'true' );
					$protBtn.text( tsosk.i18n.oe_hide_protected || $protBtn.text() );
					$protBtn.addClass( 'button-primary' );
				}
			}
			if ( '1' === params.get( 'oe_exact' ) ) {
				tsosk_oe.exactSearch = true;
				autoOpen = true;
			}
		}
		tsosk_oe.autoOpen = autoOpen;
		tsosk_oe_search( initialSearch, 1 );
	} );

	$( document ).on( 'input', '#tsosk-oe-search', function () {
		tsosk_oe_schedule_search();
	} );
	// Filter dropdowns auto-trigger search
	$( document ).on( 'change', '#tsosk-oe-filter-type', function () {
		tsosk_oe_search( $( '#tsosk-oe-search' ).val().trim(), 1 );
	} );
	$( document ).on( 'change', '#tsosk-oe-filter-autoload', function () {
		tsosk_oe_search( $( '#tsosk-oe-search' ).val().trim(), 1 );
	} );
	$( document ).on( 'input', '#tsosk-oe-min-size', function () {
		tsosk_oe_schedule_search();
	} );
	$( document ).on( 'click', '#tsosk-oe-toggle-protected', function () {
		tsosk_oe.showProtected = ! tsosk_oe.showProtected;
		var $btn = $( this );
		$btn.attr( 'aria-pressed', tsosk_oe.showProtected ? 'true' : 'false' );
		$btn.text( tsosk_oe.showProtected ? tsosk.i18n.oe_hide_protected : tsosk.i18n.oe_show_protected );
		$btn.toggleClass( 'button-primary', tsosk_oe.showProtected );
		tsosk_oe_search( $( '#tsosk-oe-search' ).val().trim(), 1 );
	} );
	// Clear all filters
	$( document ).on( 'click', '#tsosk-oe-clear-filters', function () {
		$( '#tsosk-oe-search' ).val( '' );
		$( '#tsosk-oe-filter-type' ).val( '' );
		$( '#tsosk-oe-filter-autoload' ).val( '' );
		$( '#tsosk-oe-min-size' ).val( '' );
		tsosk_oe.sortCol = 'option_name';
		tsosk_oe.sortDir = 'ASC';
		$( '.tsosk-oe-sortable .tsosk-sort-icon' ).text( '' );
		$( '.tsosk-oe-sortable[data-col="option_name"] .tsosk-sort-icon' ).text( '▲' );
		tsosk_oe_search( '', 1 );
	} );

	// Sortable column headers
	$( document ).on( 'click', '.tsosk-oe-sortable', function () {
		var col = $( this ).data( 'col' );
		if ( tsosk_oe.sortCol === col ) {
			tsosk_oe.sortDir = tsosk_oe.sortDir === 'ASC' ? 'DESC' : 'ASC';
		} else {
			tsosk_oe.sortCol = col;
			tsosk_oe.sortDir = 'ASC';
		}
		// Update icons on all sortable headers
		$( '.tsosk-oe-sortable .tsosk-sort-icon' ).text( '' );
		$( this ).find( '.tsosk-sort-icon' ).text( tsosk_oe.sortDir === 'ASC' ? '▲' : '▼' );
		// Highlight active column
		$( '.tsosk-oe-sortable' ).css( 'color', '' );
		$( this ).css( 'color', '#2271b1' );
		tsosk_oe_search( tsosk_oe.lastSearch, 1 );
	} );

	function tsosk_oe_render_table( data ) {
		var $tbody = $( '#tsosk-oe-tbody' );
		var nonce  = $( '#tsosk-oe-nonce' ).val();
		var items  = data.items || [];

		$tbody.empty();
		if ( ! items.length ) {
			$tbody.append( '<tr><td colspan="6" style="text-align:center;color:#646970;">' + tsosk.i18n.oe_no_results + '</td></tr>' );
			$( '#tsosk-oe-pagination-top, #tsosk-oe-pagination-bottom' ).html( '' );
			return;
		}

		$.each( items, function ( i, item ) {
			var isCaution   = !! item.caution;
			var isProtected = !! item.protected;
			var sizeStr     = item.size > 1024 ? ( ( item.size / 1024 ).toFixed( 1 ) + ' KB' ) : ( item.size + ' B' );
			var typeKnown   = { serialized: 1, json: 1, integer: 1, text: 1 };
			var typeClass   = 'tsosk-oe-type-' + ( typeKnown[ item.type ] ? item.type : 'text' );
			var autoOn      = item.autoload === 'yes' || item.autoload === 'on' || item.autoload === 'auto-on' || item.autoload === 'auto';

			var badge_al    = '<span class="tsosk-oe-badge ' + ( autoOn ? 'tsosk-oe-badge-warn tsosk-oe-al-on' : 'tsosk-oe-badge-neutral tsosk-oe-al-off' ) + '" style="padding:2px 7px;border-radius:10px;font-size:11px;">' + item.autoload + '</span>';
			var badge_type  = '<span class="tsosk-oe-badge tsosk-oe-badge-neutral ' + typeClass + '" style="padding:2px 7px;border-radius:10px;font-size:11px;">' + item.type + '</span>';

			var actions = '';
			var $actionsWrap = $( '<div></div>' );
			if ( isProtected ) {
				$actionsWrap.append(
					$( '<button type="button" class="button button-small tsosk-oe-edit-btn"></button>' )
						.attr( { 'data-name': item.name, 'data-protected': '1', 'data-caution': '0', 'data-nonce': nonce } )
						.text( tsosk.i18n.oe_view )
				);
			} else {
				$actionsWrap.append(
					$( '<button type="button" class="button button-small tsosk-oe-edit-btn"></button>' )
						.attr( { 'data-name': item.name, 'data-protected': '0', 'data-caution': isCaution ? '1' : '0', 'data-nonce': nonce } )
						.text( tsosk.i18n.oe_edit )
				);
				$actionsWrap.append( ' ' );
				$actionsWrap.append(
					$( '<button type="button" class="button button-small button-link-delete tsosk-oe-delete-btn"></button>' )
						.attr( { 'data-name': item.name, 'data-nonce': nonce } )
						.text( tsosk.i18n.oe_delete )
				);
			}
			actions = $actionsWrap.html();

			var $tr = $( '<tr>' + ( isProtected ? ' class="tsosk-oe-protected"' : '' ) + '>' );
			var nameCell = $( '<td class="tsosk-oe-cell-name" style="word-break:break-all;font-family:monospace;font-size:12px;">' );
			nameCell.append( $( '<span>' ).text( item.name ) );
			if ( isProtected ) {
				nameCell.append( ' ' ).append( $( '<span class="tsosk-badge tsosk-badge-warn" style="font-size:10px;vertical-align:middle;">' ).text( tsosk.i18n.oe_protected ) );
			}
			$tr.append( nameCell );
			$tr.append( $( '<td class="tsosk-oe-cell-meta" style="font-size:12px;">' ).text( sizeStr ) );
			$tr.append( $( '<td>' ).html( badge_al ) );
			$tr.append( $( '<td>' ).html( badge_type ) );
			$tr.append( $( '<td class="tsosk-oe-cell-meta" style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' ).text( item.preview ) );
			$tr.append( $( '<td class="tsosk-actions">' ).html( actions ) );
			$tbody.append( $tr );
		} );

		tsosk_oe_render_pagination( data );

		if ( tsosk_oe.autoOpen && items.length === 1 ) {
			tsosk_oe.autoOpen = false;
			$tbody.find( '.tsosk-oe-edit-btn' ).first().trigger( 'click' );
		}
	}

	function tsosk_oe_render_pagination( data ) {
		var pg  = data.page;
		var tot = data.total_pages;
		var html = '';
		if ( tot <= 1 ) {
			$( '#tsosk-oe-pagination-top, #tsosk-oe-pagination-bottom' ).html( '' );
			return;
		}
		html += '<span style="font-size:12px;color:#666;">' + tsosk.i18n.oe_page + ' ' + pg + ' ' + tsosk.i18n.oe_of + ' ' + tot + ' — ' + data.total + ' ' + tsosk.i18n.oe_options + '</span> ';
		if ( pg > 1 ) { html += '<button class="tsosk-oe-page-btn" data-page="' + (pg-1) + '">&#8592;</button>'; }
		var start = Math.max( 1, pg - 2 ), end = Math.min( tot, pg + 2 );
		for ( var i = start; i <= end; i++ ) {
			var cls = i === pg ? ' is-current' : '';
			html += '<button class="tsosk-oe-page-btn' + cls + '" data-page="' + i + '">' + i + '</button>';
		}
		if ( pg < tot ) { html += '<button class="tsosk-oe-page-btn" data-page="' + (pg+1) + '">&#8594;</button>'; }
		$( '#tsosk-oe-pagination-top, #tsosk-oe-pagination-bottom' ).html( html );
	}

	$( document ).on( 'click', '.tsosk-oe-page-btn', function () {
		var pg = parseInt( $( this ).data( 'page' ), 10 );
		if ( pg ) { tsosk_oe_search( tsosk_oe.lastSearch, pg ); }
	} );

	// Edit
	$( document ).on( 'click', '.tsosk-oe-edit-btn', function () {
		var $btn      = $( this );
		var name      = $btn.data( 'name' );
		var caution   = $btn.data( 'caution' );
		var isProtected = !! $btn.data( 'protected' );
		var nonce     = $btn.data( 'nonce' );
		var label     = isProtected ? tsosk.i18n.oe_view : tsosk.i18n.oe_edit;
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_oe_get',
			data   : { nonce: nonce, name: name },
			success: function ( r ) {
				$btn.prop( 'disabled', false ).text( label );
				if ( ! r.success ) { alert( r.data || tsosk.i18n.error ); return; }
				tsosk_oe_open_editor( r.data, !! caution );
			},
			error: function () { $btn.prop( 'disabled', false ).text( label ); }
		} );
	} );

	function tsosk_oe_open_editor( data, caution ) {
		tsosk_oe.editingName = data.name;
		tsosk_oe.rawValue    = data.raw;
		tsosk_oe.prettyValue = data.pretty;
		tsosk_oe.readOnly    = !! data.protected;
		$( '#tsosk-oe-editing-name' ).text( data.name );
		$( '#tsosk-oe-editing-type' ).text( data.type );
		$( '#tsosk-oe-editing-autoload' ).val( ( data.autoload === 'yes' || data.autoload === 'on' || data.autoload === 'auto-on' || data.autoload === 'auto' ) ? 'yes' : 'no' );
		$( '#tsosk-oe-caution-banner' ).toggle( caution && ! tsosk_oe.readOnly );
		$( '#tsosk-oe-protected-banner' ).toggle( tsosk_oe.readOnly );
		$( '#tsosk-oe-serialized-note' ).toggle( data.type === 'serialized' );
		$( '#tsosk-oe-json-note' ).toggle( data.type === 'json' );
		$( '#tsosk-oe-raw-preview' ).hide();
		$( '#tsosk-oe-toggle-view' ).text( tsosk.i18n.oe_raw );
		if ( data.type === 'serialized' ) {
			$( '#tsosk-oe-value' ).val( data.raw );
			$( '#tsosk-oe-raw-preview' ).text( data.pretty ).show();
		} else {
			$( '#tsosk-oe-value' ).val( data.pretty );
		}
		$( '#tsosk-oe-value' ).prop( 'readonly', tsosk_oe.readOnly );
		$( '#tsosk-oe-editing-autoload' ).prop( 'disabled', tsosk_oe.readOnly );
		$( '#tsosk-oe-save-btn' ).prop( 'disabled', tsosk_oe.readOnly ).toggle( ! tsosk_oe.readOnly );
		$( '#tsosk-oe-save-msg' ).text( '' ).hide();
		$( '#tsosk-oe-editor' ).show();
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-oe-editor' ).offset().top - 60 }, 200 );
		if ( ! tsosk_oe.readOnly ) {
			$( '#tsosk-oe-value' ).trigger( 'focus' );
		}
	}

	$( document ).on( 'click', '#tsosk-oe-toggle-view', function () {
		var $pre = $( '#tsosk-oe-raw-preview' );
		if ( $pre.is( ':visible' ) ) { $pre.hide(); $( this ).text( tsosk.i18n.oe_raw ); }
		else { $pre.show(); $( this ).text( tsosk.i18n.oe_pretty ); }
	} );

	// Save
	$( document ).on( 'click', '#tsosk-oe-save-btn', function () {
		var $btn = $( this ), $msg = $( '#tsosk-oe-save-msg' );
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_oe_save',
			data   : { nonce: $btn.data( 'nonce' ), name: tsosk_oe.editingName, value: $( '#tsosk-oe-value' ).val(), autoload: $( '#tsosk-oe-editing-autoload' ).val() },
			success: function ( r ) {
				if ( r.success ) { showMsg( $msg, r.data, 'ok' ); tsosk_oe_search( tsosk_oe.lastSearch, tsosk_oe.currentPage ); }
				else { showMsg( $msg, r.data, 'error' ); }
				$btn.prop( 'disabled', false ).text( tsosk.i18n.oe_save );
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); $btn.prop( 'disabled', false ).text( tsosk.i18n.oe_save ); }
		} );
	} );

	$( document ).on( 'click', '#tsosk-oe-cancel-btn', function () {
		$( '#tsosk-oe-editor' ).hide();
		$( '#tsosk-oe-value' ).prop( 'readonly', false );
		$( '#tsosk-oe-editing-autoload' ).prop( 'disabled', false );
		$( '#tsosk-oe-save-btn' ).prop( 'disabled', false ).show();
	} );

	// Delete
	$( document ).on( 'click', '.tsosk-oe-delete-btn', function () {
		var $btn = $( this ), name = $btn.data( 'name' ), nonce = $btn.data( 'nonce' );
		var $msg = $( '#tsosk-oe-search-msg' );
		if ( ! confirm( tsosk.i18n.oe_confirm_del ) ) { return; }
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_oe_delete',
			data   : { nonce: nonce, name: name },
			success: function ( r ) {
				if ( r.success ) { $btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } ); showMsg( $msg, r.data, 'ok' ); if ( tsosk_oe.editingName === name ) { $( '#tsosk-oe-editor' ).hide(); } }
				else { showMsg( $msg, r.data, 'error' ); $btn.prop( 'disabled', false ).text( tsosk.i18n.oe_delete ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); $btn.prop( 'disabled', false ).text( tsosk.i18n.oe_delete ); }
		} );
	} );

	// Add new option — value type hints
	function tsosk_oe_update_add_hint() {
		var hints = {
			text       : tsosk.i18n.oe_add_hint_text,
			integer    : tsosk.i18n.oe_add_hint_integer,
			json       : tsosk.i18n.oe_add_hint_json,
			serialized : tsosk.i18n.oe_add_hint_serialized,
		};
		var type = $( '#tsosk-oe-new-value-type' ).val() || 'text';
		$( '#tsosk-oe-new-value-hint' ).text( hints[ type ] || hints.text );
	}
	$( document ).on( 'change', '#tsosk-oe-new-value-type', tsosk_oe_update_add_hint );
	$( function () {
		if ( $( '#tsosk-oe-new-value-type' ).length ) {
			tsosk_oe_update_add_hint();
		}
	} );

	// Add new option
	$( document ).on( 'click', '#tsosk-oe-add-btn', function () {
		var $btn = $( this ), $msg = $( '#tsosk-oe-add-msg' );
		var name = $( '#tsosk-oe-new-name' ).val().trim();
		if ( ! name ) { showMsg( $msg, tsosk.i18n.oe_no_results, 'error' ); $( '#tsosk-oe-new-name' ).trigger( 'focus' ); return; }
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_oe_add',
			data   : {
				nonce      : $btn.data( 'nonce' ),
				name       : name,
				value      : $( '#tsosk-oe-new-value' ).val(),
				value_type : $( '#tsosk-oe-new-value-type' ).val() || 'text',
				autoload   : $( '#tsosk-oe-new-autoload' ).val(),
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					$( '#tsosk-oe-new-name, #tsosk-oe-new-value' ).val( '' );
					$( '#tsosk-oe-new-value-type' ).val( 'text' );
					tsosk_oe_update_add_hint();
					tsosk_oe_search( '', 1 );
				} else {
					showMsg( $msg, r.data, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.oe_add );
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); $btn.prop( 'disabled', false ).text( tsosk.i18n.oe_add ); }
		} );
	} );

} )( jQuery );
