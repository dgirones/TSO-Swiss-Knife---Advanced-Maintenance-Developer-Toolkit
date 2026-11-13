/**
 * TSO Swiss Knife – Admin JavaScript: search-replace tab.
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



	// ── Search & Replace ───────────────────────────────────────────────────────

	var tsosk_sr_preview_data = null; // Cached preview results.

	function tsosk_sr_invalidate_preview() {
		tsosk_sr_preview_data = null;
		$( '#tsosk-sr-execute-btn, #tsosk-sr-cancel-btn' ).hide();
		$( '.tsosk-sr-execute-hint' ).hide();
	}

	$( document ).on( 'input change', '#tsosk-sr-search, #tsosk-sr-replace, #tsosk-sr-case, #tsosk-sr-regex, #tsosk-sr-guid', tsosk_sr_invalidate_preview );
	$( document ).on( 'change', '.tsosk-sr-table-cb', tsosk_sr_invalidate_preview );

	// Table selection helpers (act on the tables currently shown by the filter).
	function tsosk_sr_update_count() {
		var $count = $( '#tsosk-sr-table-count' );
		if ( ! $count.length ) {
			return;
		}
		var $all   = $( '.tsosk-sr-table-cb' );
		var shown  = $all.filter( function () { return ! $( this ).closest( 'label' ).hasClass( 'tsosk-sr-hidden' ); } ).length;
		var total  = $all.length;
		var sel    = $all.filter( ':checked' ).length;
		var text   = shown ? String( $count.data( 'template' ) ) : String( $count.data( 'empty' ) );
		$count.text( text.replace( '{shown}', shown ).replace( '{total}', total ).replace( '{selected}', sel ) );
	}
	function tsosk_sr_visible_cbs() {
		return $( '.tsosk-sr-table-cb' ).filter( function () { return ! $( this ).closest( 'label' ).hasClass( 'tsosk-sr-hidden' ); } );
	}
	$( document ).on( 'input search', '#tsosk-sr-table-filter', function () {
		var q = $.trim( $( this ).val() ).toLowerCase();
		$( '.tsosk-sr-table-cb' ).each( function () {
			var match = ! q || String( $( this ).val() ).toLowerCase().indexOf( q ) !== -1;
			$( this ).closest( 'label' ).toggleClass( 'tsosk-sr-hidden', ! match );
		} );
		tsosk_sr_update_count();
	} );
	$( document ).on( 'change', '.tsosk-sr-table-cb', tsosk_sr_update_count );
	$( tsosk_sr_update_count );
	$( document ).on( 'click', '#tsosk-sr-select-wp', function () {
		tsosk_sr_visible_cbs().each( function () {
			$( this ).prop( 'checked', $( this ).data( 'is-wp' ) === 1 || $( this ).data( 'is-wp' ) === '1' );
		} );
		tsosk_sr_update_count();
		tsosk_sr_invalidate_preview();
	} );
	$( document ).on( 'click', '#tsosk-sr-select-all', function () {
		tsosk_sr_visible_cbs().prop( 'checked', true );
		tsosk_sr_update_count();
		tsosk_sr_invalidate_preview();
	} );
	$( document ).on( 'click', '#tsosk-sr-select-none', function () {
		tsosk_sr_visible_cbs().prop( 'checked', false );
		tsosk_sr_update_count();
		tsosk_sr_invalidate_preview();
	} );

	// Collect selected tables
	function tsosk_sr_get_tables() {
		var tables = [];
		$( '.tsosk-sr-table-cb:checked' ).each( function () {
			tables.push( $( this ).val() );
		} );
		return tables;
	}

	// Collect form params
	function tsosk_sr_params() {
		return {
			nonce          : $( '#tsosk-sr-preview-btn' ).data( 'nonce' ),
			search         : $( '#tsosk-sr-search' ).val(),
			replace        : $( '#tsosk-sr-replace' ).val(),
			case_sensitive : $( '#tsosk-sr-case' ).prop( 'checked' ) ? 1 : 0,
			is_regex       : $( '#tsosk-sr-regex' ).prop( 'checked' ) ? 1 : 0,
			include_guid   : $( '#tsosk-sr-guid' ).prop( 'checked' ) ? 1 : 0,
			tables         : tsosk_sr_get_tables(),
		};
	}

	// ── Preview ──
	$( document ).on( 'click', '#tsosk-sr-preview-btn', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-sr-msg' );
		var params = tsosk_sr_params();

		if ( ! params.search ) {
			showMsg( $msg, tsosk.i18n.sr_empty_search, 'error' );
			return;
		}
		if ( ! params.tables.length ) {
			showMsg( $msg, tsosk.i18n.sr_no_tables, 'error' );
			return;
		}

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		$( '#tsosk-sr-execute-btn, #tsosk-sr-cancel-btn' ).hide();
		$( '.tsosk-sr-execute-hint' ).hide();
		$( '#tsosk-sr-preview-wrap' ).hide();
		tsosk_sr_preview_data = null;

		ajaxPost( {
			action : 'tsosk_sr_preview',
			data   : params,
			success: function ( r ) {
				$btn.prop( 'disabled', false ).text( '🔍 ' + tsosk.i18n.sr_preview_btn );
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					return;
				}
				tsosk_sr_preview_data = r.data;
				tsosk_sr_render_preview( r.data );
				if ( r.data.total_rows > 0 ) {
					$( '#tsosk-sr-execute-btn' ).show();
					$( '#tsosk-sr-cancel-btn' ).show();
					$( '.tsosk-sr-execute-hint' ).show();
				}
				showMsg( $msg, '', '' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( '🔍 ' + tsosk.i18n.sr_preview_btn );
			}
		} );
	} );

	// True when at least one matching table holds visible content (posts, comments, terms…).
	function tsosk_sr_is_content_table( data, name ) {
		return $.inArray( name, data.content_tables || [] ) !== -1;
	}
	function tsosk_sr_has_content_match( data ) {
		var found = false;
		$.each( data.results || {}, function ( name ) {
			if ( tsosk_sr_is_content_table( data, name ) ) {
				found = true;
				return false;
			}
		} );
		return found;
	}

	// Preview warnings: sampled tables, skipped tables and untouched cells (all text is escaped).
	function tsosk_sr_append_warnings( $notice, data ) {
		var lines = [];
		if ( data.truncated && data.truncated.length ) {
			lines.push( tsosk.i18n.sr_truncated_note.replace( '%s', data.truncated.join( ', ' ) ) );
		}
		if ( data.skipped_tables && data.skipped_tables.length ) {
			lines.push( tsosk.i18n.sr_skipped_tables.replace( '%s', data.skipped_tables.join( ', ' ) ) );
		}
		if ( data.skipped_cells ) {
			lines.push( tsosk.i18n.sr_skipped_cells.replace( '%d', String( data.skipped_cells ) ) );
			if ( data.skipped_where && data.skipped_where.length ) {
				lines.push( tsosk.i18n.sr_skipped_where.replace( '%s', data.skipped_where.join( ', ' ) ) );
			}
		}
		if ( ! $( '#tsosk-sr-replace' ).val() ) {
			lines.push( tsosk.i18n.sr_warn_empty_replace );
		}
		if ( data.total_rows && ! tsosk_sr_has_content_match( data ) ) {
			lines.push( tsosk.i18n.sr_warn_internal_only );
		}
		$.each( lines, function ( i, line ) {
			$notice.append( $( '<div style="margin-top:6px;"></div>' ).text( line ) );
		} );
	}

	// ── Render preview ──
	function tsosk_sr_render_preview( data ) {
		var $wrap   = $( '#tsosk-sr-preview-wrap' );
		var $notice = $( '#tsosk-sr-preview-notice' );
		var $body   = $( '#tsosk-sr-preview-body' );

		$body.empty();
		$wrap.show();
		if ( $wrap.length && $wrap[0].scrollIntoView ) {
			$wrap[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}

		if ( ! data.total_rows ) {
			$notice.html( $( '<strong></strong>' ).text( data.skipped_cells ? tsosk.i18n.sr_none_changeable : tsosk.i18n.sr_preview_none ).prop( 'outerHTML' ) );
			tsosk_sr_append_warnings( $notice, data );
			$notice.show();
			return;
		}

		var totalCells = 0;
		$.each( data.results, function ( tableName, rows ) {
			totalCells += rows.length;
		} );
		$notice.html(
			$( '<strong></strong>' ).text(
				tsosk.i18n.sr_found_summary.replace( '%1$s', String( data.total_rows ) ).replace( '%2$s', String( Object.keys( data.results ).length ) )
			).prop( 'outerHTML' ) + ' ' + tsosk.i18n.sr_confirm_hint
		);
		tsosk_sr_append_warnings( $notice, data );
		$notice.show();

		$.each( data.results, function ( tableName, rows ) {
			var $section = $( '<div class="tsosk-sr-table-section"></div>' );
			var $header  = $( '<div class="tsosk-sr-table-header"></div>' );
			$header.append( '<span class="tsosk-code" style="font-size:13px;">' + $( '<span>' ).text( tableName ).html() + '</span>' );
			$header.append( '<span class="tsosk-badge tsosk-badge-warn" style="font-size:11px;">' + rows.length + ' ' + tsosk.i18n.sr_rows + '</span>' );
			if ( tsosk_sr_is_content_table( data, tableName ) ) {
				$header.append( $( '<span class="tsosk-badge tsosk-badge-ok" style="font-size:11px;"></span>' ).text( tsosk.i18n.sr_badge_content ) );
			} else {
				$header.append( $( '<span class="tsosk-badge tsosk-badge-info" style="font-size:11px;"></span>' ).text( tsosk.i18n.sr_badge_internal ) );
			}
			$section.append( $header );

			$.each( rows, function ( i, row ) {
				if ( i >= 10 ) {
					// Show max 10 rows per table in preview for readability
					$section.append( '<div class="tsosk-sr-row" style="color:#8c8f94;font-size:12px;font-style:italic;">…' + ( rows.length - 10 ) + ' ' + tsosk.i18n.sr_more + '</div>' );
					return false;
				}
				var $row = $( '<div class="tsosk-sr-row"></div>' );
				$row.append( '<div style="font-size:11px;color:#8c8f94;margin-bottom:4px;">' + tsosk.i18n.sr_pk + ': <code>' + $( '<span>' ).text( String( row.pk ) ).html() + '</code></div>' );

				$.each( row.changes, function ( colName, diff ) {
					var $colWrap = $( '<div style="margin-bottom:6px;"></div>' );
					$colWrap.append( '<div class="tsosk-sr-col-label">' + $( '<span>' ).text( colName ).html() + ':</div>' );

					var $before = $( '<div></div>' );
					$before.append( '<span style="font-size:10px;color:#8c8f94;margin-right:4px;">BEFORE</span>' );
					$before.append( '<span class="tsosk-sr-diff-before">' + $( '<span>' ).text( diff.before ).html() + ( diff.before.length >= 300 ? '…' : '' ) + '</span>' );

					var $after = $( '<div style="margin-top:3px;"></div>' );
					$after.append( '<span style="font-size:10px;color:#8c8f94;margin-right:4px;">AFTER</span>' );
					$after.append( '<span class="tsosk-sr-diff-after">' + $( '<span>' ).text( diff.after ).html() + ( diff.after.length >= 300 ? '…' : '' ) + '</span>' );

					$colWrap.append( $before ).append( $after );
					$row.append( $colWrap );
				} );

				$section.append( $row );
			} );

			$body.append( $section );
		} );
	}

	// ── Execute ──
	$( document ).on( 'click', '#tsosk-sr-execute-btn', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-sr-msg' );

		if ( ! tsosk_sr_preview_data ) {
			showMsg( $msg, tsosk.i18n.sr_stale_preview, 'error' );
			return;
		}

		// One single confirmation: every warning that applies is listed in the same dialog.
		var confirmLines = [];
		if ( ! $( '#tsosk-sr-replace' ).val() ) {
			confirmLines.push( '• ' + tsosk.i18n.sr_warn_empty_replace );
		}
		if ( ! tsosk_sr_has_content_match( tsosk_sr_preview_data ) ) {
			confirmLines.push( '• ' + tsosk.i18n.sr_warn_internal_only );
		}
		if ( tsosk_sr_preview_data.has_backup ) {
			confirmLines.push( '• ' + tsosk.i18n.sr_backup_overwrite_note );
		}
		confirmLines.push( tsosk.i18n.sr_confirm_exec );
		if ( ! window.confirm( confirmLines.join( '\n\n' ) ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		$( '#tsosk-sr-preview-btn' ).prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, 'ok', 600000 );

		ajaxPost( {
			action : 'tsosk_sr_execute',
			data   : tsosk_sr_params(),
			success: function ( r ) {
				$btn.prop( 'disabled', false ).text( '⚡ ' + tsosk.i18n.sr_execute_btn );
				$( '#tsosk-sr-preview-btn' ).prop( 'disabled', false );
				if ( r.success ) {
					var warnings = ( r.data.errors || [] ).slice();
					if ( r.data.skipped_cells ) {
						warnings.push( tsosk.i18n.sr_skipped_cells.replace( '%d', String( r.data.skipped_cells ) ) );
						if ( r.data.skipped_where && r.data.skipped_where.length ) {
							warnings.push( tsosk.i18n.sr_skipped_where.replace( '%s', r.data.skipped_where.join( ', ' ) ) );
						}
					}
					showMsg( $msg, r.data.message, 'ok' );
					$( '#tsosk-sr-execute-btn, #tsosk-sr-cancel-btn' ).hide();
					$( '.tsosk-sr-execute-hint' ).hide();
					$( '#tsosk-sr-preview-wrap' ).hide();
					tsosk_sr_preview_data = null;
					if ( warnings.length ) {
						// Keep the page so the warnings can be read; the backup panel refreshes on the next load.
						var $warn = $( '<div class="tsosk-notice tsosk-notice-warn" style="margin-top:8px;"></div>' );
						$warn.append( $( '<strong></strong>' ).text( tsosk.i18n.sr_exec_warnings ) );
						$.each( warnings, function ( i, w ) {
							$warn.append( $( '<div></div>' ).text( String( w ) ) );
						} );
						$msg.after( $warn );
					} else {
						// Reload after 1.5s to refresh history
						setTimeout( function () { window.location.reload(); }, 1500 );
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error', 60000 );
				}
			},
			error: function ( xhr ) {
				// A timeout or a PHP fatal gives no JSON: say what the server answered and keep the message on screen.
				var status = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
				showMsg( $msg, tsosk.i18n.sr_exec_failed + status, 'error', 120000 );
				$btn.prop( 'disabled', false ).text( '⚡ ' + tsosk.i18n.sr_execute_btn );
				$( '#tsosk-sr-preview-btn' ).prop( 'disabled', false );
			}
		} );
	} );

	// ── Cancel ──
	$( document ).on( 'click', '#tsosk-sr-cancel-btn', function () {
		tsosk_sr_invalidate_preview();
		$( '#tsosk-sr-preview-wrap' ).hide();
	} );

	// ── Restore automatic backup ──
	$( document ).on( 'click', '#tsosk-sr-backup-restore-btn', function () {
		var $btn     = $( this );
		var origText = $btn.text();
		var $msg     = $( '#tsosk-sr-backup-msg' );

		if ( ! confirm( tsosk.i18n.sr_backup_confirm_restore ) ) { return; }

		$btn.prop( 'disabled', true ).text( tsosk.i18n.loading );
		ajaxPost( {
			action : 'tsosk_sr_restore_backup',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1500 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( origText );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( origText );
			}
		} );
	} );

} )( jQuery );
