/**
 * TSO Swiss Knife – Admin JavaScript: slow-queries tab.
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
	var sqClearHash = tsoskCore.sqClearHash;


	// Slow Query Monitor: sub-tabs (Settings by default; a reload keeps the tab; links with a #anchor open the tab that holds it).
	function sqShowTab( tab ) {
		var $tab = $( '.tsosk-sq-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.tsosk-sq-tab' ).removeClass( 'nav-tab-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'nav-tab-active' ).attr( 'aria-selected', 'true' );
		$( '.tsosk-sq-panel' ).prop( 'hidden', true );
		$( '#tsosk-sq-panel-' + tab ).prop( 'hidden', false );
		try {
			window.sessionStorage.setItem( 'tsosk_sq_tab', tab );
		} catch ( e ) {
			// Storage unavailable: the tab just is not remembered.
		}
	}

	// Open the tab that contains the element named by the URL hash and scroll to it.
	function sqShowTabForHash() {
		var hash = window.location.hash || '';
		if ( ! $( '.tsosk-sq-tabs' ).length || 2 > hash.length || 0 !== hash.indexOf( '#tsosk-sq-' ) ) {
			return false;
		}
		var $target = $( hash );
		var $panel = $target.closest( '.tsosk-sq-panel' );
		if ( $target.length && $panel.length ) {
			sqShowTab( String( $panel.attr( 'id' ) ).replace( 'tsosk-sq-panel-', '' ) );
			if ( $target[0].scrollIntoView ) {
				$target[0].scrollIntoView( { block: 'start' } );
			}
			return true;
		}
		// The section is not rendered (for example no previous request recorded yet): still open the tab it belongs to.
		var owner = { '#tsosk-sq-log': 'slow', '#tsosk-sq-patterns': 'slow', '#tsosk-sq-origins': 'slow', '#tsosk-sq-settings': 'config' };
		var fallback = owner[ hash ] || ( 0 === hash.indexOf( '#tsosk-sq-last-' ) || 0 === hash.indexOf( '#tsosk-sq-live-' ) ? 'queries' : '' );
		if ( '' === fallback ) {
			return false;
		}
		sqShowTab( fallback );
		return true;
	}

	$( document ).on( 'click', '.tsosk-sq-tab', function ( e ) {
		e.preventDefault();
		sqShowTab( $( this ).data( 'tab' ) );
		sqClearHash();
	} );

	$( window ).on( 'hashchange', sqShowTabForHash );

	$( function () {
		if ( ! $( '.tsosk-sq-tabs' ).length ) {
			return;
		}
		if ( sqShowTabForHash() ) {
			return;
		}
		// Opening the module from the menu always starts on Settings; only a page reload (for example after saving) keeps the tab.
		var saved = '';
		var isReload = false;
		try {
			var nav = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType( 'navigation' ) : [];
			isReload = nav.length > 0 && 'reload' === nav[ 0 ].type;
			saved = isReload ? ( window.sessionStorage.getItem( 'tsosk_sq_tab' ) || '' ) : '';
		} catch ( e ) {
			saved = '';
		}
		sqShowTab( saved || 'config' );
	} );


	// ── Slow Query Monitor ────────────────────────────────────────────────────

	var tsosk_sq = { page: 1, total_pages: 1, lastSearch: '' };

	// Expand / collapse long SQL (and fingerprints) in tables.
	$( document ).on( 'click', '.tsosk-sq-sql-fold-btn', function () {
		var $btn   = $( this );
		var $wrap  = $btn.closest( '.tsosk-sq-sql-fold' );
		var open   = ! $wrap.hasClass( 'is-open' );
		$wrap.toggleClass( 'is-open', open );
		$wrap.find( '.tsosk-sq-sql-preview' ).prop( 'hidden', open );
		$wrap.find( '.tsosk-sq-sql-code--full' ).prop( 'hidden', ! open );
		$btn.attr( 'aria-expanded', open ? 'true' : 'false' );
		$btn.find( '.tsosk-sq-sql-fold-show' ).prop( 'hidden', open );
		$btn.find( '.tsosk-sq-sql-fold-hide' ).prop( 'hidden', ! open );
	} );

	function tsoskSqDupeTable( panel ) {
		return $( '#tsosk-sq-' + panel + '-dupe-table' );
	}

	function tsoskSqParseDupeJson( panel ) {
		var el = document.getElementById( 'tsosk-sq-' + panel + '-dupe-json' );
		if ( ! el || ! el.textContent ) {
			return null;
		}
		try {
			return JSON.parse( el.textContent );
		} catch ( e ) {
			return null;
		}
	}

	function tsoskSqOriginLabelEn( origin ) {
		origin = String( origin || '' );
		if ( origin.indexOf( 'plugin:' ) === 0 ) {
			return 'Plugin: ' + origin.slice( 7 );
		}
		if ( origin.indexOf( 'mu-plugin:' ) === 0 ) {
			return 'MU-plugin: ' + origin.slice( 10 );
		}
		if ( origin.indexOf( 'theme:' ) === 0 ) {
			return 'Theme: ' + origin.slice( 6 );
		}
		if ( origin === 'core' ) {
			return 'WordPress Core';
		}
		if ( origin === 'unknown' ) {
			return 'Unknown';
		}
		return origin || 'Unknown';
	}

	function tsoskSqMapLines( map, emptyLine, labelFn ) {
		var keys = map && typeof map === 'object' ? Object.keys( map ) : [];
		if ( ! keys.length ) {
			return [ emptyLine ];
		}
		var lines = [];
		keys.slice( 0, 8 ).forEach( function ( k ) {
			var label = labelFn ? labelFn( k ) : k;
			lines.push( '- ' + label + ' ×' + String( map[ k ] ) );
		} );
		return lines;
	}

	function tsoskSqBuildDupeReport( payload, selectedIds ) {
		var meta = ( payload && payload.meta ) ? payload.meta : {};
		var all  = ( payload && payload.rows ) ? payload.rows : [];
		var want = {};
		( selectedIds || [] ).forEach( function ( id ) { want[ String( id ) ] = true; } );
		var rows = all.filter( function ( r ) { return want[ String( r.id ) ]; } );
		var lines = [
			'# Duplicate database queries (TSO Swiss Knife — Slow Query Monitor)',
			'URL: ' + ( meta.url ? meta.url : '(unknown)' ),
			'Queries on page: ' + String( meta.query_count || 0 ) + ' · Duplicate patterns in this report: ' + String( rows.length ),
			'Origin detection: ' + ( meta.detect ? 'on' : 'off (turn on in Monitor Settings, then reload the page you are debugging)' ),
			''
		];
		if ( ! rows.length ) {
			lines.push( 'No duplicate patterns selected.' );
			return lines.join( '\n' ) + '\n';
		}
		rows.forEach( function ( row, idx ) {
			if ( idx >= 25 ) {
				return;
			}
			lines.push( '## ' + String( idx + 1 ) + '. ×' + String( row.count ) + ' · ' + Number( row.time_ms || 0 ).toFixed( 2 ) + ' ms total' );
			lines.push( 'SQL:' );
			lines.push( String( row.sql || '' ) );
			lines.push( '' );
			lines.push( 'Callers:' );
			lines = lines.concat( tsoskSqMapLines( row.callers, '- (none recorded)' ) );
			lines.push( '' );
			lines.push( 'Components:' );
			lines = lines.concat( tsoskSqMapLines( row.components, meta.detect ? '- (unknown)' : '- (enable Origin detection to attribute plugins)', tsoskSqOriginLabelEn ) );
			lines.push( '' );
			lines.push( 'Potential troublemakers:' );
			lines = lines.concat( tsoskSqMapLines( row.troublemakers, meta.detect ? '- (none identified beyond the callers above)' : '- (enable Origin detection)' ) );
			lines.push( '' );
		} );
		if ( rows.length > 25 ) {
			lines.push( '… and ' + String( rows.length - 25 ) + ' more patterns.' );
		}
		return lines.join( '\n' );
	}

	$( document ).on( 'change', '.tsosk-sq-dupe-pick-all', function () {
		var $table = $( this ).closest( 'table' );
		$table.find( '.tsosk-sq-dupe-pick' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	$( document ).on( 'change', '.tsosk-sq-dupe-pick', function () {
		var $table = $( this ).closest( 'table' );
		var $picks = $table.find( '.tsosk-sq-dupe-pick' );
		var allOn  = $picks.length && $picks.filter( ':checked' ).length === $picks.length;
		$table.find( '.tsosk-sq-dupe-pick-all' ).prop( 'checked', allOn );
	} );

	function tsoskSqCopyText( text, $msg, $fallback ) {
		var doneLabel = ( tsosk.i18n && tsosk.i18n.sq_copied ) ? tsosk.i18n.sq_copied : ( ( tsosk.i18n && tsosk.i18n.email_copied ) ? tsosk.i18n.email_copied : 'Copied' );
		function onCopied() {
			showMsg( $msg, doneLabel, 'ok' );
		}
		if ( ! text ) {
			showMsg( $msg, ( tsosk.i18n && tsosk.i18n.sq_copy_none ) ? tsosk.i18n.sq_copy_none : tsosk.i18n.error, 'error' );
			return;
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onCopied ).catch( function () {
				try {
					if ( $fallback && $fallback.length ) {
						$fallback.val( text ).prop( 'hidden', false ).trigger( 'select' );
						document.execCommand( 'copy' );
						$fallback.prop( 'hidden', true );
					}
					onCopied();
				} catch ( e ) {
					showMsg( $msg, tsosk.i18n.error, 'error' );
				}
			} );
			return;
		}
		try {
			if ( $fallback && $fallback.length ) {
				$fallback.val( text ).prop( 'hidden', false ).trigger( 'select' );
				document.execCommand( 'copy' );
				$fallback.prop( 'hidden', true );
			}
			onCopied();
		} catch ( e2 ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
		}
	}

	// Copy a Query Monitor–style duplicate report (English) for pasting into an AI chat.
	$( document ).on( 'click', '.tsosk-sq-copy-report', function () {
		var $btn  = $( this );
		var panel = $btn.data( 'dupe-panel' );
		var msgId = $btn.data( 'msg' );
		var $msg  = msgId ? $( '#' + msgId ) : $btn.siblings( '.tsosk-ajax-msg' ).first();

		if ( ! panel ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		var payload = tsoskSqParseDupeJson( String( panel ) );
		var ids     = [];
		tsoskSqDupeTable( String( panel ) ).find( '.tsosk-sq-dupe-pick:checked' ).each( function () {
			ids.push( String( $( this ).val() ) );
		} );
		if ( ! payload || ! payload.rows ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		if ( ! ids.length ) {
			showMsg( $msg, ( tsosk.i18n && tsosk.i18n.sq_copy_none ) ? tsosk.i18n.sq_copy_none : tsosk.i18n.error, 'error' );
			return;
		}
		tsoskSqCopyText( tsoskSqBuildDupeReport( payload, ids ), $msg, $() );
	} );

	function tsoskSqCopyPluginsSelected() {
		var out = [];
		$( '#tsosk-sq-copy-plugins .tsosk-sq-copy-plugin:checked' ).each( function () {
			var v = $( this ).val();
			if ( v ) {
				out.push( String( v ) );
			}
		} );
		return out;
	}

	$( document ).on( 'click', '#tsosk-sq-copy-plugins-tso', function ( e ) {
		e.preventDefault();
		$( '#tsosk-sq-copy-plugins .tsosk-sq-copy-plugin' ).each( function () {
			var slug = String( $( this ).val() || '' );
			$( this ).prop( 'checked', slug.indexOf( 'tso-' ) === 0 );
		} );
	} );

	$( document ).on( 'click', '#tsosk-sq-copy-plugins-none', function ( e ) {
		e.preventDefault();
		$( '#tsosk-sq-copy-plugins .tsosk-sq-copy-plugin' ).prop( 'checked', false );
	} );

	// Save settings
	$( document ).on( 'click', '#tsosk-sq-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-sq-settings-msg' );
		var nonce = $btn.data( 'nonce' );
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_sq_save_settings',
			data   : {
				nonce        : nonce,
				enabled      : $( '#tsosk-sq-enabled' ).prop( 'checked' ) ? 1 : 0,
				threshold_ms : $( '#tsosk-sq-threshold' ).val(),
				max_entries  : $( '#tsosk-sq-max-entries' ).val(),
				exclude_ajax : $( '#tsosk-sq-exclude-ajax' ).prop( 'checked' ) ? 1 : 0,
				exclude_cron : $( '#tsosk-sq-exclude-cron' ).prop( 'checked' ) ? 1 : 0,
				show_admin_bar : $( '#tsosk-sq-show-admin-bar' ).prop( 'checked' ) ? 1 : 0,
				capture_origin : $( '#tsosk-sq-capture-origin' ).prop( 'checked' ) ? 1 : 0,
				copy_plugins : JSON.stringify( tsoskSqCopyPluginsSelected() ),
				ignore_patterns : $( '#tsosk-sq-ignore-patterns' ).val() || ''
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message, r.data.warn_savequeries ? 'warn' : 'ok' );
					sqShowTab( 'config' );
					sqClearHash();
					setTimeout( function () { window.location.reload(); }, 1000 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			}
		} );
	} );

	// Batch expand/collapse
	$( document ).on( 'click', '.tsosk-sq-batch-header', function ( e ) {
		if ( $( e.target ).closest( '.tsosk-sq-delete-batch' ).length ) { return; }
		var $body = $( this ).siblings( '.tsosk-sq-batch-body' );
		var $icon = $( this ).find( '.tsosk-sq-toggle-icon' );
		$body.toggleClass( 'open' );
		$icon.text( $body.hasClass( 'open' ) ? '▲' : '▼' );
	} );

	// Delete single batch
	$( document ).on( 'click', '.tsosk-sq-delete-batch', function ( e ) {
		e.stopPropagation();
		var $btn  = $( this );
		var id    = $btn.attr( 'data-id' ) || $btn.data( 'id' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-sq-log-msg' );
		if ( ! id ) { return; }
		if ( ! confirm( tsosk.i18n.sq_delete_confirm ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_sq_delete_entry',
			data   : { nonce: nonce, id: id },
			success: function ( r ) {
				if ( r.success ) {
					$( '.tsosk-sq-batch[data-id="' + id + '"], #tsosk-sq-batch-' + id ).fadeOut( 300, function () { $( this ).remove(); } );
					showMsg( $msg, r.data, 'ok' );
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

	// Clear all
	$( document ).on( 'click', '#tsosk-sq-clear-btn', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-sq-log-msg' );
		if ( ! confirm( tsosk.i18n.sq_clear_confirm ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_sq_clear_log',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					$( '#tsosk-sq-batches' ).empty();
					$btn.prop( 'disabled', false );
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

	// Ignore SQL fingerprint pattern
	$( document ).on( 'click', '.tsosk-sq-ignore-pattern', function () {
		var $btn    = $( this );
		var nonce   = $btn.attr( 'data-nonce' );
		var pattern = $btn.attr( 'data-pattern' );
		var $msg    = $( '#tsosk-sq-pattern-msg' );
		var label   = $btn.text();
		if ( ! pattern ) { return; }
		if ( ! confirm( tsosk.i18n.sq_ignore_confirm || 'Ignore this SQL pattern?' ) ) { return; }
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_sq_ignore_pattern',
			data   : { nonce: nonce, pattern: pattern },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
					if ( r.data && r.data.patterns && $( '#tsosk-sq-ignore-patterns' ).length ) {
						$( '#tsosk-sq-ignore-patterns' ).val( r.data.patterns.join( '\n' ) );
					}
					$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
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

	// Search + pagination
	function tsosk_sq_load( page ) {
		tsosk_sq.page = page || 1;
		var nonce  = $( '#tsosk-sq-search-btn' ).data( 'nonce' );
		var search = $( '#tsosk-sq-search' ).val().trim();
		tsosk_sq.lastSearch = search;

		$( '#tsosk-sq-batches' ).html( '<p style="color:#646970;padding:12px;">' + tsosk.i18n.running + '…</p>' );

		ajaxPost( {
			action : 'tsosk_sq_get_log',
			data   : { nonce: nonce, page: tsosk_sq.page, search: search },
			success: function ( r ) {
				if ( ! r.success ) {
					$( '#tsosk-sq-batches' ).html( '<p style="color:#d63638;">' + ( r.data || tsosk.i18n.error ) + '</p>' );
					return;
				}
				tsosk_sq.total_pages = r.data.total_pages;
				tsosk_sq_render( r.data );
			},
			error: function () {
				$( '#tsosk-sq-batches' ).html( '<p style="color:#d63638;">' + tsosk.i18n.error + '</p>' );
			}
		} );
	}

	function tsosk_sq_render( data ) {
		var $wrap = $( '#tsosk-sq-batches' );
		$wrap.empty();

		if ( ! data.items || ! data.items.length ) {
			$wrap.html( '<p style="color:#646970;padding:8px 0;">' + tsosk.i18n.sq_no_results + '</p>' );
			$( '#tsosk-sq-pagination-top, #tsosk-sq-pagination-bot' ).empty();
			return;
		}

		var nonce = $( '#tsosk-sq-search-btn' ).data( 'nonce' );

		$.each( data.items, function ( i, batch ) {
			var maxTime = 0;
			$.each( batch.queries || [], function ( j, q ) {
				if ( parseFloat( q.time ) > maxTime ) { maxTime = parseFloat( q.time ); }
			} );
			var worstCls    = maxTime > 500 ? 'tsosk-sq-warn' : ( maxTime > 200 ? 'tsosk-sq-amber' : '' );
			var badgeCls    = batch.slow_count > 5 ? 'tsosk-badge-warn' : 'tsosk-badge-info';
			var batchId     = batch.id || ( 'tmp-' + i );
			var batchIdAttr = String( batchId ).replace( /"/g, '' );

			var $batch = $( '<div class="tsosk-sq-batch"></div>' )
				.attr( 'id', 'tsosk-sq-batch-' + batchIdAttr )
				.attr( 'data-id', batchIdAttr );

			var headerHtml = '<div class="tsosk-sq-batch-header" data-id="' + batchIdAttr + '">'
				+ '<span class="tsosk-badge ' + badgeCls + ' tsosk-sq-batch-badge">' + batch.slow_count + ' ' + tsosk.i18n.sq_slow + '</span>'
				+ '<span class="tsosk-sq-batch-url">' + $( '<span>' ).text( batch.url || '—' ).html() + '</span>'
				+ '<span class="tsosk-sq-batch-meta">' + new Date( batch.ts * 1000 ).toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + ' UTC</span>'
				+ ( batch.load_ms > 0 ? '<span class="tsosk-sq-batch-meta tsosk-sq-batch-meta-muted">Page: ' + parseFloat( batch.load_ms ).toFixed(1) + ' ms</span>' : '' )
				+ '<span class="tsosk-sq-batch-worst ' + worstCls + '">Worst: ' + maxTime.toFixed(2) + ' ms</span>'
				+ '<button type="button" class="button button-small tsosk-sq-delete-batch tsosk-sq-delete-batch-btn" data-id="' + batchIdAttr + '" data-nonce="' + nonce + '">' + tsosk.i18n.delete + '</button>'
				+ '<span class="tsosk-sq-toggle-icon">▼</span>'
				+ '</div>';

			var bodyHtml = '<div class="tsosk-sq-batch-body">';
			$.each( batch.queries || [], function ( j, q ) {
				var t  = parseFloat( q.time );
				var tc = t > 500 ? 'tsosk-sq-warn' : ( t > 200 ? 'tsosk-sq-amber' : '' );
				var kw = ( q.sql || '' ).split( ' ' )[0].toUpperCase();
				var kwColors = { SELECT:'#2271b1', INSERT:'#16a34a', UPDATE:'#d97706', DELETE:'#d63638', CREATE:'#7c3aed', DROP:'#d63638' };
				var kwc = kwColors[kw] || '#374151';
				bodyHtml += '<div class="tsosk-sq-query-row">'
					+ '<div class="tsosk-sq-query-meta">'
					+ '<span class="tsosk-sq-query-time ' + tc + '">' + t.toFixed(3) + ' ms</span>'
					+ '<span class="tsosk-badge tsosk-sq-kw-badge" style="background:' + kwc + '20;color:' + kwc + ';">' + $('<span>').text(kw).html() + '</span>'
					+ '<span class="tsosk-sq-query-num">#' + (j+1) + '</span>'
					+ ( q.origin_label ? '<span class="tsosk-badge tsosk-sq-origin-badge">' + $('<span>').text(q.origin_label).html() + '</span>' : '' )
					+ '</div>'
					+ '<div class="tsosk-sq-query-sql">' + $('<span>').text(q.sql || '').html() + '</div>'
					+ ( q.caller ? '<div class="tsosk-sq-query-caller"><span class="tsosk-sq-caller-arrow">↳</span> ' + $('<span>').text(q.caller).html() + '</div>' : '' )
					+ '</div>';
			} );
			bodyHtml += '</div>';

			$batch.append( headerHtml ).append( bodyHtml );
			$wrap.append( $batch );
		} );

		// Pagination
		var pgHtml = '';
		if ( data.total_pages > 1 ) {
			pgHtml += '<span style="font-size:12px;color:#646970;">' + tsosk.i18n.sq_page + ' ' + data.page + ' ' + tsosk.i18n.sq_of + ' ' + data.total_pages + '</span> ';
			if ( data.page > 1 ) { pgHtml += '<button class="tsosk-oe-page-btn tsosk-sq-page-btn" data-page="' + (data.page-1) + '">&larr;</button>'; }
			for ( var p = Math.max(1,data.page-2); p <= Math.min(data.total_pages,data.page+2); p++ ) {
				pgHtml += '<button class="tsosk-oe-page-btn' + (p===data.page?' is-current':'') + ' tsosk-sq-page-btn" data-page="' + p + '">' + p + '</button>';
			}
			if ( data.page < data.total_pages ) { pgHtml += '<button class="tsosk-oe-page-btn tsosk-sq-page-btn" data-page="' + (data.page+1) + '">&rarr;</button>'; }
		}
		$( '#tsosk-sq-pagination-top, #tsosk-sq-pagination-bot' ).html( pgHtml );
	}

	$( document ).on( 'click', '.tsosk-sq-page-btn', function () {
		tsosk_sq_load( parseInt( $( this ).data( 'page' ), 10 ) );
	} );
	$( document ).on( 'click', '#tsosk-sq-search-btn', function () {
		tsosk_sq_load( 1 );
	} );
	$( document ).on( 'keydown', '#tsosk-sq-search', function ( e ) {
		if ( e.key === 'Enter' ) { tsosk_sq_load( 1 ); }
	} );

	// Slow Query Monitor: live SAVEQUERIES table filters (duplicates / slow / search).
	function tsosk_sq_apply_debug_filters() {
		var $filter = $( '#tsosk-sq-filter' );
		if ( ! $filter.length ) {
			return;
		}
		var q      = $filter.val().toLowerCase();
		var onlyDu = $( '#tsosk-sq-dupes-only' ).prop( 'checked' );
		var onlySl = $( '#tsosk-sq-slow-only' ).prop( 'checked' );
		var $rows  = $( '#tsosk-sq-table tbody .tsosk-sq-row' );
		var shown  = 0;
		$rows.each( function () {
			var $row  = $( this );
			var sql   = String( $row.attr( 'data-sql' ) || '' ).toLowerCase();
			// Prefer attr over .data() — jQuery coerces data-dupe="0" to number 0.
			var isDu  = String( $row.attr( 'data-dupe' ) || '0' ) === '1';
			var isSl  = String( $row.attr( 'data-slow' ) || '0' ) === '1';
			var hide  = ( q && sql.indexOf( q ) === -1 )
				|| ( onlyDu && ! isDu )
				|| ( onlySl && ! isSl );
			$row.toggleClass( 'tsosk-sq-hidden', hide );
			if ( ! hide ) {
				shown++;
			}
		} );
		var $count = $( '#tsosk-sq-count-shown' );
		if ( $count.length ) {
			$count.text( shown + ' / ' + $rows.length );
		}
	}

	$( document ).on( 'input', '#tsosk-sq-filter', tsosk_sq_apply_debug_filters );
	$( document ).on( 'change', '#tsosk-sq-dupes-only, #tsosk-sq-slow-only', tsosk_sq_apply_debug_filters );
	tsosk_sq_apply_debug_filters();

} )( jQuery );
