/**
 * TSO Swiss Knife – Admin JavaScript: action-scheduler tab.
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


	// ── Action Scheduler ─────────────────────────────────────────────────────

	var tsoskAschedPage = 1;

	function tsoskEscHtml( text ) {
		return $( '<div/>' ).text( text == null ? '' : String( text ) ).html();
	}

	function tsoskAschedLoad( page ) {
		var $tbody = $( '#tsosk-asched-tbody' );
		var $msg   = $( '#tsosk-asched-msg' );
		var nonce  = $( '#tsosk-asched-nonce' ).val();
		if ( ! $tbody.length || ! nonce ) { return; }

		tsoskAschedPage = page || 1;
		$tbody.html( '<tr><td colspan="6">' + tsosk.i18n.loading + '</td></tr>' );

		ajaxPost( {
			action : 'tsosk_asched_list',
			data   : {
				nonce  : nonce,
				status : $( '#tsosk-asched-status' ).val(),
				page   : tsoskAschedPage
			},
			success: function ( r ) {
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$tbody.html( '<tr><td colspan="6">' + tsosk.i18n.error + '</td></tr>' );
					return;
				}
				var rows = r.data.rows || [];
				if ( ! rows.length ) {
					$tbody.html( '<tr><td colspan="6">' + tsosk.i18n.no_matches + '</td></tr>' );
				} else {
					var html = '';
					rows.forEach( function ( row ) {
						var status = row.status || '';
						var canCancel = status === 'pending' || status === 'in-progress';
						html += '<tr data-id="' + row.id + '">';
						html += '<td>' + row.id + '</td>';
						html += '<td class="tsosk-code">' + tsoskEscHtml( row.hook || '—' ) + '</td>';
						html += '<td>' + tsoskEscHtml( row.group || '—' ) + '</td>';
						html += '<td>' + tsoskEscHtml( status || '—' ) + '</td>';
						html += '<td>' + tsoskEscHtml( row.schedule || '—' ) + '</td>';
						html += '<td class="tsosk-actions">';
						if ( canCancel ) {
							html += '<button type="button" class="button button-small tsosk-asched-cancel" data-id="' + row.id + '">' + tsoskEscHtml( tsosk.i18n.cancel ) + '</button> ';
						}
						html += '<button type="button" class="button button-small tsosk-asched-delete" data-id="' + row.id + '">' + tsoskEscHtml( tsosk.i18n.delete ) + '</button>';
						html += '</td></tr>';
					} );
					$tbody.html( html );
				}
				var pages = r.data.pages || 1;
				var $pag  = $( '#tsosk-asched-pagination' );
				$pag.empty();
				$pag.append(
					'<span style="font-size:12px;color:#646970;margin-right:8px;">' +
					tsosk.i18n.sr_rows + ': ' + ( r.data.total || 0 ) + ' · ' +
					tsoskAschedPage + '/' + pages + '</span>'
				);
				if ( tsoskAschedPage > 1 ) {
					$pag.append( ' <button type="button" class="button button-small tsosk-asched-page" data-page="' + ( tsoskAschedPage - 1 ) + '">&larr; ' + tsosk.i18n.previous + '</button>' );
				}
				if ( tsoskAschedPage < pages ) {
					$pag.append( ' <button type="button" class="button button-small tsosk-asched-page" data-page="' + ( tsoskAschedPage + 1 ) + '">' + tsosk.i18n.next + ' &rarr;</button>' );
				}
				showMsg( $msg, '', 'ok' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$tbody.html( '<tr><td colspan="6">' + tsosk.i18n.error + '</td></tr>' );
			}
		} );
	}

	if ( $( '#tsosk-asched-tbody' ).length ) {
		tsoskAschedLoad( 1 );
	}

	$( document ).on( 'click', '#tsosk-asched-refresh', function () {
		tsoskAschedLoad( 1 );
	} );

	$( document ).on( 'change', '#tsosk-asched-status', function () {
		tsoskAschedLoad( 1 );
	} );

	$( document ).on( 'click', '.tsosk-asched-page', function () {
		tsoskAschedLoad( parseInt( $( this ).data( 'page' ), 10 ) || 1 );
	} );

	$( document ).on( 'click', '.tsosk-asched-cancel', function () {
		if ( ! window.confirm( tsosk.i18n.asched_cancel_confirm ) ) { return; }
		var id    = $( this ).data( 'id' );
		var nonce = $( '#tsosk-asched-nonce' ).val();
		var $msg  = $( '#tsosk-asched-msg' );
		ajaxPost( {
			action : 'tsosk_asched_cancel',
			data   : { nonce: nonce, action_id: id },
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskAschedLoad( tsoskAschedPage ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	$( document ).on( 'click', '.tsosk-asched-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) { return; }
		var id    = $( this ).data( 'id' );
		var nonce = $( '#tsosk-asched-nonce' ).val();
		var $msg  = $( '#tsosk-asched-msg' );
		ajaxPost( {
			action : 'tsosk_asched_delete',
			data   : { nonce: nonce, action_id: id },
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskAschedLoad( tsoskAschedPage ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

} )( jQuery );
