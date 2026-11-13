/**
 * TSO Swiss Knife – Admin JavaScript: meta-editor tab.
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


	// ── Meta Editor ───────────────────────────────────────────────────────────
	var tsoskMe = { currentPage: 1, lastSearch: '', context: 'post', editingId: 0, editingContext: 'post' };
	var tsosk_me_search_seq = 0;

	function tsoskMeSearch( page ) {
		if ( ! $( '#tsosk-me-search-btn' ).length ) { return; }
		tsoskMe.currentPage = page || 1;
		var newContext      = $( '#tsosk-me-context' ).val() || 'post';
		if ( newContext !== tsoskMe.context ) {
			// Context changed: the open editor row belongs to the other table, so close it.
			tsoskMe.editingId = 0;
			$( '#tsosk-me-editor' ).hide();
		}
		tsoskMe.context     = newContext;
		tsoskMe.lastSearch  = $( '#tsosk-me-search' ).val().trim();
		var nonce    = $( '#tsosk-me-search-btn' ).data( 'nonce' );
		var $msg     = $( '#tsosk-me-search-msg' );
		var $tbody   = $( '#tsosk-me-tbody' );
		var reqId    = ++tsosk_me_search_seq;
		$tbody.html( '<tr><td colspan="5" style="text-align:center;">' + tsosk.i18n.loading + '</td></tr>' );
		ajaxPost( {
			action : 'tsosk_me_search',
			data   : {
				nonce     : nonce,
				context   : tsoskMe.context,
				search    : tsoskMe.lastSearch,
				object_id : $( '#tsosk-me-object-id' ).val() || 0,
				page      : tsoskMe.currentPage
			},
			success: function ( r ) {
				if ( reqId !== tsosk_me_search_seq ) {
					return;
				}
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$tbody.html( '<tr><td colspan="5">' + tsosk.i18n.oe_no_results + '</td></tr>' );
					return;
				}
				tsoskMeRender( r.data );
				showMsg( $msg, r.data.total + ' rows', 'ok' );
			},
			error: function () {
				if ( reqId !== tsosk_me_search_seq ) {
					return;
				}
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	}

	function tsoskMeRender( data ) {
		var $tbody = $( '#tsosk-me-tbody' );
		var items  = data.items || [];
		$tbody.empty();
		if ( ! items.length ) {
			$tbody.append( '<tr><td colspan="5" style="text-align:center;color:#646970;">' + tsosk.i18n.oe_no_results + '</td></tr>' );
			$( '#tsosk-me-pagination' ).html( '' );
			return;
		}
		items.forEach( function ( row ) {
			var prot = row.protected ? ' <span class="tsosk-badge">' + tsosk.i18n.oe_protected + '</span>' : '';
			var $tr  = $( '<tr></tr>' );
			$tr.append( '<td>' + row.meta_id + '</td>' );
			$tr.append( '<td>' + row.object_id + '</td>' );
			$tr.append( '<td><code>' + $( '<span>' ).text( row.key ).html() + '</code>' + prot + '</td>' );
			$tr.append( '<td><code style="font-size:11px;">' + $( '<span>' ).text( row.preview ).html() + '</code></td>' );
			var $btn = $( '<button type="button" class="button button-small tsosk-me-edit">' + tsosk.i18n.oe_edit + '</button>' );
			$btn.data( 'meta', row );
			if ( row.protected ) { $btn.prop( 'disabled', true ); }
			$tr.append( $( '<td></td>' ).append( $btn ) );
			$tbody.append( $tr );
		} );
		var pg = data.total_pages || 1;
		var $pag = $( '#tsosk-me-pagination' );
		$pag.html( '' );
		if ( pg > 1 ) {
			for ( var p = 1; p <= pg; p++ ) {
				$pag.append( $( '<button type="button" class="button button-small tsosk-me-page">' + p + '</button>' ).data( 'page', p ) );
			}
		}
	}

	$( document ).on( 'click', '#tsosk-me-search-btn', function () { tsoskMeSearch( 1 ); } );
	$( document ).on( 'keydown', '#tsosk-me-search', function ( e ) {
		if ( e.key === 'Enter' ) { tsoskMeSearch( 1 ); }
	} );
	$( document ).on( 'change', '#tsosk-me-context', function () { tsoskMeSearch( 1 ); } );
	$( document ).on( 'click', '.tsosk-me-page', function () { tsoskMeSearch( $( this ).data( 'page' ) ); } );

	$( document ).on( 'click', '.tsosk-me-edit', function () {
		var row = $( this ).data( 'meta' );
		var nonce = $( '#tsosk-me-save' ).data( 'nonce' );
		ajaxPost( {
			action : 'tsosk_me_get',
			data   : { nonce: nonce, context: tsoskMe.context, meta_id: row.meta_id },
			success: function ( r ) {
				if ( r.success ) {
					tsoskMe.editingId      = r.data.meta_id;
					tsoskMe.editingContext = tsoskMe.context;
					$( '#tsosk-me-editor' ).show();
					$( '#tsosk-me-edit-key' ).text( r.data.key );
					$( '#tsosk-me-edit-object' ).text( r.data.object_id );
					$( '#tsosk-me-edit-value' ).val( r.data.value ).prop( 'disabled', r.data.protected );
					$( '#tsosk-me-save, #tsosk-me-delete' ).prop( 'disabled', r.data.protected );
					$( '#tsosk-me-edit-msg' ).hide().text( '' );
				} else {
					showMsg( $( '#tsosk-me-search-msg' ), r.data || tsosk.i18n.error, 'error' );
				}
			},
			error: function () {
				showMsg( $( '#tsosk-me-search-msg' ), tsosk.i18n.error, 'error' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-me-save', function () {
		var $msg = $( '#tsosk-me-edit-msg' );
		if ( ! tsoskMe.editingId ) { return; }
		ajaxPost( {
			action : 'tsosk_me_save',
			data   : {
				nonce   : $( this ).data( 'nonce' ),
				context : tsoskMe.editingContext,
				meta_id : tsoskMe.editingId,
				value   : $( '#tsosk-me-edit-value' ).val()
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskMeSearch( tsoskMe.currentPage ); }
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-me-delete', function () {
		if ( ! window.confirm( tsosk.i18n.oe_confirm_del ) ) { return; }
		var $msg = $( '#tsosk-me-edit-msg' );
		if ( ! tsoskMe.editingId ) { return; }
		ajaxPost( {
			action : 'tsosk_me_delete',
			data   : { nonce: $( this ).data( 'nonce' ), context: tsoskMe.editingContext, meta_id: tsoskMe.editingId },
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) {
					$( '#tsosk-me-editor' ).hide();
					tsoskMeSearch( tsoskMe.currentPage );
				}
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-me-add-btn', function () {
		var $msg = $( '#tsosk-me-add-msg' );
		ajaxPost( {
			action : 'tsosk_me_add',
			data   : {
				nonce     : $( this ).data( 'nonce' ),
				context   : tsoskMe.context,
				object_id : $( '#tsosk-me-add-object' ).val(),
				meta_key  : $( '#tsosk-me-add-key' ).val(),
				value     : $( '#tsosk-me-add-value' ).val()
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) {
					// Show the new row: filter by the object it was added to and drop the text filter.
					$( '#tsosk-me-object-id' ).val( $( '#tsosk-me-add-object' ).val() );
					$( '#tsosk-me-search' ).val( '' );
					tsoskMeSearch( 1 );
				}
			}
		} );
	} );

} )( jQuery );
