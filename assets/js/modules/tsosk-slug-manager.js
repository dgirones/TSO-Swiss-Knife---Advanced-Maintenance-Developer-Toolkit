/**
 * TSO Swiss Knife – Admin JavaScript: slug-manager tab.
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



	// ── Slug Manager ──────────────────────────────────────────────────────

	var tsosk_sm = {
		lastSearch  : '',
		currentPage : 1,
		totalPages  : 1,
		bulkPreview : null,
	};

	// Long-slug threshold currently set in the toolbar (same value the audit lists were built with).
	function tsoskSmThreshold() {
		return Math.max( 10, Math.min( 200, parseInt( $( '#tsosk-sm-threshold' ).val(), 10 ) || 75 ) );
	}

	function tsoskSmBuildBulkTable( changes, cols ) {
		if ( ! changes || ! changes.length ) {
			return null;
		}
		var $table = $( '<table class="tsosk-sm-bulk-summary-table"></table>' );
		var $thead = $( '<thead><tr></tr></thead>' );
		cols.forEach( function ( col ) {
			$thead.find( 'tr' ).append( $( '<th></th>' ).text( col.label ) );
		} );
		var $tbody = $( '<tbody></tbody>' );
		changes.forEach( function ( row ) {
			var $tr = $( '<tr></tr>' );
			cols.forEach( function ( col ) {
				var val = typeof col.value === 'function' ? col.value( row ) : ( row[ col.key ] || '' );
				var $td = $( '<td></td>' );
				if ( col.code ) {
					$td.addClass( 'tsosk-code' );
				}
				$td.text( val );
				$tr.append( $td );
			} );
			$tbody.append( $tr );
		} );
		$table.append( $thead, $tbody );
		return $table;
	}

	function tsoskSmRenderBulkSummary( $summary, data, mode ) {
		$summary.empty().show();
		var title = mode === 'preview'
			? ( tsosk.i18n.sm_bulk_preview_title || 'Bulk slug fix preview' )
			: ( tsosk.i18n.done || 'Done' );
		$summary.append( $( '<p><strong></strong>' ).text( title ) );
		if ( data.message ) {
			$summary.append( $( '<p></p>' ).text( data.message ) );
		}

		var changeCols = [
			{ label: tsosk.i18n.sm_bulk_col_post || 'Post', value: function ( row ) { return row.title || ( '#' + row.id ); } },
			{ label: tsosk.i18n.sm_bulk_col_before || 'Before', key: 'old_slug', code: true },
			{ label: tsosk.i18n.sm_bulk_col_after || 'After', key: 'new_slug', code: true },
		];
		var $changeTable = tsoskSmBuildBulkTable( data.changes || [], changeCols );
		if ( $changeTable ) {
			$summary.append( $changeTable );
		}

		var skipped = data.skipped || [];
		if ( skipped.length && Array.isArray( skipped ) && skipped[0] && skipped[0].reason ) {
			var skipCols = [
				{ label: tsosk.i18n.sm_bulk_col_post || 'Post', value: function ( row ) { return row.title || ( '#' + row.id ); } },
				{ label: tsosk.i18n.sm_bulk_col_reason || 'Reason', key: 'reason' },
			];
			var $skipTable = tsoskSmBuildBulkTable( skipped, skipCols );
			if ( $skipTable ) {
				$summary.append( $( '<p><strong></strong>' ).text( tsosk.i18n.sm_bulk_col_skipped || 'Skipped' ) );
				$summary.append( $skipTable );
			}
		}

		if ( data.errors && data.errors.length ) {
			var $errors = $( '<p class="description"></p>' );
			data.errors.forEach( function ( err ) {
				$errors.append( document.createTextNode( err ) ).append( '<br>' );
			} );
			$summary.append( $errors );
		}

		if ( data.redirect && tsosk.i18n.sm_redirect_note ) {
			$summary.append( $( '<p class="description"></p>' ).text( tsosk.i18n.sm_redirect_note ) );
		}

		if ( mode === 'preview' && ( data.changes || [] ).length ) {
			var $actions = $( '<div class="tsosk-sm-bulk-preview-actions"></div>' );
			$actions.append(
				$( '<button type="button" class="button button-primary" id="tsosk-sm-bulk-confirm"></button>' )
					.text( tsosk.i18n.sm_bulk_confirm_btn || 'Confirm changes' )
			);
			$actions.append(
				$( '<button type="button" class="button" id="tsosk-sm-bulk-preview-cancel"></button>' )
					.text( tsosk.i18n.cancel || 'Cancel' )
			);
			$summary.append( $actions );
		}

		$( 'html, body' ).animate( { scrollTop: $summary.offset().top - 80 }, 200 );
	}

	// ── Checkbox helpers ──────────────────────────────────────────────────

	$( document ).on( 'change', '.tsosk-sm-check-all', function () {
		var $table = $( this ).closest( 'table' );
		$table.find( '.tsosk-sm-row-check' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	$( document ).on( 'click', '#tsosk-sm-select-all-long', function () {
		$( '.tsosk-sm-row-check' ).prop( 'checked', true );
		$( '.tsosk-sm-check-all' ).prop( 'checked', true );
	} );

	$( document ).on( 'click', '#tsosk-sm-deselect-all-long', function () {
		$( '.tsosk-sm-row-check' ).prop( 'checked', false );
		$( '.tsosk-sm-check-all' ).prop( 'checked', false );
	} );

	// ── Edit / rename panel ───────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-sm-edit-btn', function () {
		var $btn     = $( this );
		var id       = $btn.data( 'id' );
		var title    = $btn.data( 'title' );
		var slug     = $btn.data( 'slug' );
		var len      = $btn.data( 'len' );
		var editLink = $btn.data( 'editLink' ) || $btn.data( 'edit-link' ) || '';

		$( '#tsosk-sm-rename-post-id' ).val( id );
		$( '#tsosk-sm-rename-title' ).text( title || tsosk.i18n.no_title );
		if ( editLink ) {
			$( '#tsosk-sm-rename-edit-link' ).attr( 'href', editLink ).show();
		} else {
			$( '#tsosk-sm-rename-edit-link' ).hide();
		}
		$( '#tsosk-sm-rename-current' ).text( slug );
		$( '#tsosk-sm-rename-len' ).text( len );
		$( '#tsosk-sm-rename-new' ).val( slug );
		$( '#tsosk-sm-rename-new-len' ).text( len + ' ' + tsosk.i18n.sm_chars );
		$( '#tsosk-sm-rename-msg' ).text( '' ).removeClass( 'is-error' ).hide();
		$( '#tsosk-sm-rename-urls' ).empty().hide();

		$( '#tsosk-sm-rename-panel' ).show();
		$( 'html, body' ).animate(
			{ scrollTop: $( '#tsosk-sm-rename-panel' ).offset().top - 60 }, 200
		);
		$( '#tsosk-sm-rename-new' ).trigger( 'focus' ).trigger( 'select' );
	} );

	// Live character count while typing
	$( document ).on( 'input', '#tsosk-sm-rename-new', function () {
		var len = $( this ).val().length;
		var $lbl = $( '#tsosk-sm-rename-new-len' );
		var thr   = tsoskSmThreshold();
		var color = len > thr ? '#b45309' : ( len > Math.round( thr * 0.6 ) ? '#646970' : '#2271b1' );
		$lbl.text( len + ' ' + tsosk.i18n.sm_chars ).css( 'color', color );
	} );

	$( document ).on( 'click', '#tsosk-sm-rename-cancel', function () {
		$( '#tsosk-sm-rename-panel' ).hide();
	} );

	$( document ).on( 'click', '#tsosk-sm-rename-save', function () {
		var $btn      = $( this );
		var $msg      = $( '#tsosk-sm-rename-msg' );
		var nonce     = $btn.data( 'nonce' );
		var postId    = $( '#tsosk-sm-rename-post-id' ).val();
		var newSlug   = $( '#tsosk-sm-rename-new' ).val().trim();
		var doRedir   = $( '#tsosk-sm-rename-redirect' ).prop( 'checked' ) ? 1 : 0;

		if ( ! newSlug ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sm_rename',
			data   : { nonce: nonce, post_id: postId, new_slug: newSlug, auto_redirect: doRedir },
			success: function ( r ) {
				if ( r.success ) {
					// The server reports the slug WordPress really saved (it may differ from what was typed).
					var saved = String( r.data.new_slug );
					var thr   = tsoskSmThreshold();
					showMsg( $msg, r.data.message, 'ok' );
					// Update the row in the table.
					var $row = $( '#tsosk-sm-row-' + postId );
					$row.find( '.tsosk-sm-slug-col a' ).text( saved ).attr( { href: r.data.new_permalink, title: saved } );
					$row.find( 'td[data-label]' ).has( 'span[style*="font-weight:600"]' ).find( 'span' ).first()
						.text( saved.length ).css( 'color', saved.length > thr ? '#b45309' : '' );
					$row.toggleClass( 'tsosk-row-warn', saved.length > thr );
					$( '#tsosk-sm-rename-current' ).text( saved );
					$( '#tsosk-sm-rename-len' ).text( saved.length );
					$( '#tsosk-sm-rename-new' ).val( saved );
					$( '#tsosk-sm-rename-new-len' ).text( saved.length + ' ' + tsosk.i18n.sm_chars );
					// Keep the Rename buttons in sync so reopening the panel shows the new slug.
					$( '#tsosk-sm-row-' + postId + ' .tsosk-sm-edit-btn, #tsosk-sm-tr-' + postId + ' .tsosk-sm-edit-btn' )
						.attr( { 'data-slug': saved, 'data-len': saved.length } )
						.data( { slug: saved, len: saved.length } );
					// Also update search results row if present
					$( '#tsosk-sm-tr-' + postId + ' .tsosk-sm-slug-cell a' ).text( saved ).attr( { href: r.data.new_permalink, title: saved } );
					$( '#tsosk-sm-tr-' + postId + ' .tsosk-sm-len-cell' ).text( saved.length )
						.attr( 'style', saved.length > thr ? 'font-weight:600;color:#b45309;' : '' );
					// Show exactly which URL moved where.
					if ( r.data.old_permalink && tsosk.i18n.sm_url_moved ) {
						$( '#tsosk-sm-rename-urls' )
							.text( tsosk.i18n.sm_url_moved.replace( '%1$s', r.data.old_permalink ).replace( '%2$s', r.data.new_permalink ) )
							.show();
					}
				} else {
					showMsg( $msg, r.data, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sm_save_slug );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sm_save_slug );
			}
		} );
	} );

	// ── Category URL redirects ────────────────────────────────────────────

	function tsoskSmCatRun( apply ) {
		var $card  = $( '#tsosk-sm-cat-card' );
		var $msg   = $( '#tsosk-sm-cat-msg' );
		var $scan  = $( '#tsosk-sm-cat-scan' );
		var $apply = $( '#tsosk-sm-cat-apply' );
		var $list  = $( '#tsosk-sm-cat-list' );

		$scan.prop( 'disabled', true );
		$apply.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, 'ok', 60000 );

		ajaxPost( {
			action : 'tsosk_sm_cat_redirects',
			data   : { nonce: $card.data( 'nonce' ), apply: apply ? 1 : 0 },
			success: function ( r ) {
				$list.empty().hide();
				if ( ! r.success ) {
					showMsg( $msg, r.data, 'error', 60000 );
					$apply.hide();
					return;
				}
				var data   = r.data || {};
				var $table = tsoskSmBuildBulkTable( data.rows, [
					{ label: $card.data( 'col-old' ), key: 'old', code: true },
					{ label: $card.data( 'col-new' ), key: 'new', code: true }
				] );
				showMsg( $msg, data.message || tsosk.i18n.done, 'ok', 60000 );
				if ( $table ) {
					$list.append( $table ).show();
				}
				$apply.toggle( ! apply && ( data.missing || 0 ) > 0 );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error', 60000 );
			},
			complete: function () {
				$scan.prop( 'disabled', false );
				$apply.prop( 'disabled', false );
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-sm-cat-scan', function () {
		tsoskSmCatRun( false );
	} );

	$( document ).on( 'click', '#tsosk-sm-cat-apply', function () {
		tsoskSmCatRun( true );
	} );

	// ── Bulk Fix ──────────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-sm-bulk-fix', function () {
		var $btn    = $( this );
		var $msg    = $( '#tsosk-sm-bulk-msg' );
		var nonce   = $btn.data( 'nonce' );
		var ids     = $( '.tsosk-sm-row-check:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		if ( ids.length === 0 ) {
			showMsg( $msg, tsosk.i18n.sm_no_posts, 'error' );
			return;
		}

		var doRedir   = $( '#tsosk-sm-auto-redirect' ).prop( 'checked' ) ? 1 : 0;
		var threshold = parseInt( $( '#tsosk-sm-threshold' ).val(), 10 ) || 75;

		tsosk_sm.bulkPreview = { ids: ids, threshold: threshold, doRedir: doRedir, nonce: nonce };

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );

		ajaxPost( {
			action : 'tsosk_sm_bulk_preview',
			data   : { nonce: nonce, post_ids: ids, threshold: threshold, auto_redirect: doRedir },
			success: function ( r ) {
				if ( r.success ) {
					var data = r.data || {};
					var $summary = $( '#tsosk-sm-bulk-summary' );
					if ( $summary.length ) {
						tsoskSmRenderBulkSummary( $summary, data, 'preview' );
					}
					if ( ! ( data.changes || [] ).length ) {
						showMsg( $msg, data.message || tsosk.i18n.sm_no_posts, 'error' );
					}
				} else {
					showMsg( $msg, r.data, 'error' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sm_bulk_fix );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-sm-bulk-preview-cancel', function () {
		tsosk_sm.bulkPreview = null;
		$( '#tsosk-sm-bulk-summary' ).empty().hide();
		$( '#tsosk-sm-bulk-msg' ).empty();
	} );

	$( document ).on( 'click', '#tsosk-sm-bulk-confirm', function () {
		var preview = tsosk_sm.bulkPreview;
		if ( ! preview || ! preview.ids || ! preview.ids.length ) {
			return;
		}

		var $btn  = $( '#tsosk-sm-bulk-fix' );
		var $msg  = $( '#tsosk-sm-bulk-msg' );
		var $conf = $( this );

		$conf.prop( 'disabled', true );
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sm_bulk_fix',
			data   : {
				nonce         : preview.nonce,
				post_ids      : preview.ids,
				threshold     : preview.threshold,
				auto_redirect : preview.doRedir,
			},
			success: function ( r ) {
				if ( r.success ) {
					var data    = r.data || {};
					var message = data.message || tsosk.i18n.done;
					showMsg( $msg, message, 'ok' );

					var $summary = $( '#tsosk-sm-bulk-summary' );
					if ( $summary.length ) {
						tsoskSmRenderBulkSummary( $summary, data, 'result' );
					}

					( data.changes || [] ).forEach( function ( row ) {
						var $slugCell = $( '#tsosk-sm-row-' + row.id + ' .tsosk-sm-slug-col a' );
						if ( $slugCell.length ) {
							$slugCell.text( row.new_slug ).attr( 'title', row.new_slug );
						}
						$( '#tsosk-sm-row-' + row.id ).removeClass( 'tsosk-row-warn' );
						$( '#tsosk-sm-row-' + row.id + ' .tsosk-sm-row-check' ).prop( 'checked', false );
					} );
					tsosk_sm.bulkPreview = null;
				} else {
					showMsg( $msg, r.data, 'error' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			},
			complete: function () {
				$conf.prop( 'disabled', false );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sm_bulk_fix );
			}
		} );
	} );

	// ── Search & Edit tab ─────────────────────────────────────────────────

	var tsosk_sm_search_seq = 0;
	function tsosk_sm_search( q, page ) {
		tsosk_sm.lastSearch  = q;
		tsosk_sm.currentPage = page || 1;
		var nonce     = $( '#tsosk-sm-search-btn' ).data( 'nonce' );
		var $msg      = $( '#tsosk-sm-search-msg' );
		var postType  = $( '#tsosk-sm-post-type' ).val();
		var reqId     = ++tsosk_sm_search_seq;

		$( '#tsosk-sm-search-btn, #tsosk-sm-load-all' ).prop( 'disabled', true );
		$( '#tsosk-sm-search-placeholder' ).hide();

		ajaxPost( {
			action : 'tsosk_sm_search',
			data   : { nonce: nonce, q: q, post_type: postType, page: tsosk_sm.currentPage },
			success: function ( r ) {
				if ( reqId !== tsosk_sm_search_seq ) {
					return;
				}
				if ( r.success ) {
					tsosk_sm.totalPages = r.data.total_pages;
					tsosk_sm_render_results( r.data );
					$( '#tsosk-sm-search-results' ).show();
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$( '#tsosk-sm-search-btn, #tsosk-sm-load-all' ).prop( 'disabled', false );
			},
			error: function () {
				if ( reqId !== tsosk_sm_search_seq ) {
					return;
				}
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$( '#tsosk-sm-search-btn, #tsosk-sm-load-all' ).prop( 'disabled', false );
			}
		} );
	}

	function tsosk_sm_render_results( data ) {
		var $tbody   = $( '#tsosk-sm-search-tbody' );
		var nonce    = $( '#tsosk-sm-search-btn' ).data( 'nonce' );
		var items    = data.items || [];

		$tbody.empty();

		if ( ! items.length ) {
			$tbody.append( '<tr><td colspan="6" style="text-align:center;color:#646970;">' +
				tsosk.i18n.sm_no_posts + '</td></tr>' );
			$( '#tsosk-sm-pagination-top, #tsosk-sm-pagination-bottom' ).empty();
			return;
		}

		var thr = tsoskSmThreshold();
		$.each( items, function ( i, item ) {
			var $tr = $( '<tr id="tsosk-sm-tr-' + item.id + '"></tr>' );
			var $titleCell = $( '<td></td>' ).attr( 'data-label', tsosk.i18n.sm_col_title || 'Title' );
			$titleCell.append( $( '<strong></strong>' ).text( item.title || tsosk.i18n.no_title ) );
			if ( item.edit_link ) {
				$titleCell.append( ' ' ).append(
					$( '<a target="_blank" rel="noopener noreferrer" class="tsosk-sm-edit-link"></a>' )
						.attr( 'href', item.edit_link )
						.text( tsosk.i18n.edit + ' ↗' )
				);
			}
			if ( item.date ) {
				$titleCell.append( $( '<span class="tsosk-sm-meta"></span>' ).text( item.date ) );
			}
			$tr.append( $titleCell );
			$tr.append( $( '<td class="tsosk-code" style="font-size:12px;"></td>' ).attr( 'data-label', tsosk.i18n.sm_col_type || 'Type' ).text( item.type ) );
			$tr.append( $( '<td></td>' ).attr( 'data-label', tsosk.i18n.sm_col_status || 'Status' ).text( item.status ) );
			var $slugLink = $( '<a target="_blank" rel="noopener noreferrer"></a>' )
				.attr( 'href', item.permalink )
				.attr( 'title', item.slug )
				.text( item.slug );
			var $slugCell = $( '<td class="tsosk-code tsosk-sm-slug-cell tsosk-sm-slug-col"></td>' ).attr( 'data-label', tsosk.i18n.sm_col_slug || 'Slug' ).append( $slugLink );
			if ( item.path && item.path !== item.slug ) {
				$slugCell.append( $( '<span class="tsosk-sm-meta"></span>' ).text( '/' + item.path + '/' ) );
			}
			$tr.append( $slugCell );
			$tr.append( $( '<td class="tsosk-sm-len-cell"></td>' ).attr( { 'data-label': tsosk.i18n.sm_col_chars || 'Chars', style: item.len > thr ? 'font-weight:600;color:#b45309;' : '' } ).text( item.len ) );
			var $btn = $( '<button type="button" class="button button-small tsosk-sm-edit-btn"></button>' )
				.text( tsosk.i18n.sm_rename )
				.data( {
					id       : item.id,
					title    : item.title,
					slug     : item.slug,
					len      : item.len,
					editLink : item.edit_link || '',
					nonce    : nonce,
				} );
			$tr.append( $( '<td></td>' ).attr( 'data-label', tsosk.i18n.sm_col_actions || 'Actions' ).append( $btn ) );
			$tbody.append( $tr );
		} );

		// Pagination
		$( '#tsosk-sm-pagination-top, #tsosk-sm-pagination-bottom' ).each( function () {
			tsosk_sm_render_pagination( $( this ), data );
		} );
	}

	function tsosk_sm_render_pagination( $el, data ) {
		$el.empty();
		if ( data.total_pages <= 1 ) { return; }

		$el.append( '<span style="font-size:12px;color:#646970;">' +
			tsosk.i18n.oe_page + ' ' + data.page + ' ' + tsosk.i18n.oe_of + ' ' + data.total_pages +
			' &mdash; ' + data.total + ' ' + tsosk.i18n.sm_total_items + '</span>' );

		if ( data.page > 1 ) {
			$el.append( ' <button class="tsosk-oe-page-btn tsosk-sm-page-btn" data-page="' +
				( data.page - 1 ) + '">&larr; ' + tsosk.i18n.previous + '</button>' );
		}
		if ( data.page < data.total_pages ) {
			$el.append( ' <button class="tsosk-oe-page-btn tsosk-sm-page-btn" data-page="' +
				( data.page + 1 ) + '">' + tsosk.i18n.next + ' &rarr;</button>' );
		}
	}

	// Threshold and post type filter the audit lists on the server, so reload with them in the URL.
	// On the Search tab only the post type applies, and it is applied through the search request.
	$( document ).on( 'change', '#tsosk-sm-threshold, #tsosk-sm-post-type', function () {
		if ( $( '#tsosk-sm-search-input' ).length ) {
			if ( 'tsosk-sm-post-type' === this.id && $( '#tsosk-sm-search-results' ).is( ':visible' ) ) {
				tsosk_sm_search( tsosk_sm.lastSearch, 1 );
			}
			return;
		}
		var url  = new window.URL( window.location.href );
		var type = String( $( '#tsosk-sm-post-type' ).val() || '' );
		if ( type ) {
			url.searchParams.set( 'sm_type', type );
		} else {
			url.searchParams.delete( 'sm_type' );
		}
		url.searchParams.set( 'sm_threshold', String( tsoskSmThreshold() ) );
		window.location.href = url.toString();
	} );

	$( document ).on( 'click', '.tsosk-sm-page-btn', function () {
		tsosk_sm_search( tsosk_sm.lastSearch, parseInt( $( this ).data( 'page' ), 10 ) );
	} );

	$( document ).on( 'click', '#tsosk-sm-search-btn', function () {
		tsosk_sm_search( $( '#tsosk-sm-search-input' ).val().trim(), 1 );
	} );

	$( document ).on( 'keydown', '#tsosk-sm-search-input', function ( e ) {
		if ( e.key === 'Enter' ) { tsosk_sm_search( $( this ).val().trim(), 1 ); }
	} );

	$( document ).on( 'click', '#tsosk-sm-load-all', function () {
		$( '#tsosk-sm-search-input' ).val( '' );
		tsosk_sm_search( '', 1 );
	} );

	$( function () {
		if ( $( '#tsosk-sm-search-btn' ).length && window.location.search.indexOf( 'sm_tab=search' ) !== -1 ) {
			tsosk_sm_search( '', 1 );
		}
	} );

} )( jQuery );
