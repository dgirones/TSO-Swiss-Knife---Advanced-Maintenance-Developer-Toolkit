/**
 * TSO Swiss Knife – Admin JavaScript: leftovers tab.
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



	// ── Plugin & Theme Leftovers ───────────────────────────────────────────
	var tsoskLoSources = [ 'options', 'postmeta', 'usermeta', 'termmeta', 'commentmeta', 'posts' ];
	var tsoskLoGroups  = {};

	function tsoskLoStrings() {
		try {
			return JSON.parse( $( '#tsosk-lo-app' ).attr( 'data-strings' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function tsoskLoMerge( list ) {
		$.each( list || [], function ( i, g ) {
			var k   = g.source + '\u0001' + g.key + '\u0001' + g.slug_key;
			var cur = tsoskLoGroups[ k ];
			if ( ! cur ) {
				tsoskLoGroups[ k ] = $.extend( {}, g, { links: ( g.links || [] ).slice( 0, 5 ) } );
				return;
			}
			cur.rows      += g.rows;
			cur.deletable += g.deletable;
			cur.mixed     += g.mixed;
			cur.large     = ( cur.large || 0 ) + ( g.large || 0 );
			if ( g.links ) {
				cur.links = cur.links.concat( g.links ).slice( 0, 5 );
			}
		} );
	}

	function tsoskLoUpdateSelected() {
		var L = tsoskLoStrings(), total = 0;
		$( '.tsosk-lo-pick:checked' ).each( function () {
			total += parseInt( $( this ).attr( 'data-count' ), 10 ) || 0;
		} );
		$( '#tsosk-lo-delete' ).prop( 'disabled', 0 === total );
		$( '#tsosk-lo-selected' ).text( total ? String( ( 1 === total && L.selected_one ) ? L.selected_one : ( L.selected || '%d' ) ).replace( '%d', String( total ) ) : ( L.pick_hint || '' ) );
		return total;
	}

	// Refresh only the "Automatic backups" box so the new backup is available right away.
	// message: shown once the box has been replaced.
	function tsoskLoRefreshBackupBox( message ) {
		var openIds = [];
		$( '#tsosk-lo-backup details[open]' ).each( function () {
			openIds.push( $( this ).attr( 'data-id' ) );
		} );
		$.ajax( { url: window.location.href, cache: false, dataType: 'html' } ).done( function ( html ) {
			var $new = $( '<div/>' ).append( $.parseHTML( html ) ).find( '#tsosk-lo-backup' ).first(),
				$old = $( '#tsosk-lo-backup' );
			if ( ! $new.length ) {
				$old.remove();
				if ( message ) {
					showMsg( $( '#tsosk-lo-status' ), message, 'ok', 30000 );
				}
				return;
			}
			$new.find( 'details' ).each( function () {
				if ( -1 !== $.inArray( $( this ).attr( 'data-id' ), openIds ) ) {
					$( this ).attr( 'open', 'open' );
				}
			} );
			if ( $old.length ) {
				$old.replaceWith( $new );
			} else {
				// Same place as on a fresh page load: after the last card.
				( $( '#tsosk-lo-watch-card' ).length ? $( '#tsosk-lo-watch-card' ) : $( '#tsosk-lo-app' ) ).after( $new );
			}
			if ( message ) {
				showMsg( $( '#tsosk-lo-restore-msg' ), message, 'ok', 60000 );
			}
		} );
	}

	// Remove from the list the rows that were just deleted, and tidy up cards and blocks that are left empty.
	// forceKey: an option deleted on its own (it leaves every card that listed it).
	function tsoskLoRemoveDeleted( forceKey ) {
		var L = tsoskLoStrings(), goneKeys = {}, $gone;
		if ( forceKey ) {
			goneKeys[ forceKey ] = true;
		} else {
			$( '.tsosk-lo-pick:checked' ).each( function () {
				var $cb = $( this );
				$cb.closest( 'tr' ).addClass( 'tsosk-lo-row--gone' );
				// An option is a single row: it leaves every card that listed it.
				// Exception: uninstall_plugins is shared, only this plugin's entry is removed from it.
				if ( 'options' === $cb.attr( 'data-source' ) && 'uninstall_plugins' !== $cb.attr( 'data-key' ) ) {
					goneKeys[ $cb.attr( 'data-key' ) ] = true;
				}
			} );
		}
		$( '#tsosk-lo-results tr[data-source="options"]' ).each( function () {
			if ( goneKeys[ $( this ).attr( 'data-key' ) ] ) {
				$( this ).addClass( 'tsosk-lo-row--gone' );
			}
		} );
		$gone = $( '.tsosk-lo-row--gone' );
		$gone.next( '.tsosk-lo-peek' ).remove();
		$gone.remove();

		$( '#tsosk-lo-results .tsosk-lo-card' ).each( function () {
			var $card = $( this );
			if ( ! $card.find( 'tbody tr' ).not( '.tsosk-lo-peek' ).length ) {
				$card.remove();
			} else if ( ! $card.find( '.tsosk-lo-pick' ).length && ! $card.closest( '.tsosk-lo-section--info' ).length && $( '.tsosk-lo-section--info' ).length ) {
				// Nothing deletable is left in this card: it moves to the information-only block.
				$card.addClass( 'tsosk-lo-card--info' ).find( '.tsosk-lo-allwrap' ).remove();
				$( '.tsosk-lo-section--info' ).append( $card );
			}
		} );
		if ( ! $( '.tsosk-lo-section--del .tsosk-lo-card' ).length ) {
			tsoskLoParkActions();
			$( '#tsosk-lo-actions' ).hide();
			$( '.tsosk-lo-section--del' ).remove();
		}
		if ( ! $( '.tsosk-lo-section--info .tsosk-lo-card' ).length ) {
			$( '.tsosk-lo-section--info' ).remove();
		}
		var left = $( '#tsosk-lo-results .tsosk-lo-card' ).length;
		if ( left ) {
			$( '.tsosk-lo-summary' ).text( String( L.summary ).replace( '%1$d', String( left ) ) );
		} else {
			$( '#tsosk-lo-results' ).empty().append( $( '<p class="tsosk-lo-empty"></p>' ).text( L.nothing ) );
		}
		$( '.tsosk-lo-all' ).prop( 'checked', false );
		tsoskLoUpdateSelected();
	}

	// The delete bar is shown inside the green "can be deleted" block; park it outside the results before they are cleared.
	function tsoskLoParkActions() {
		$( '#tsosk-lo-app' ).append( $( '#tsosk-lo-actions' ) );
	}

	function tsoskLoRender() {
		var L = tsoskLoStrings(), $res = $( '#tsosk-lo-results' ), bySlug = {}, weight = {};
		tsoskLoParkActions();
		$res.empty();
		$.each( tsoskLoGroups, function ( k, g ) {
			( bySlug[ g.slug_key ] = bySlug[ g.slug_key ] || [] ).push( g );
			weight[ g.slug_key ] = ( weight[ g.slug_key ] || 0 ) + g.rows;
		} );
		var slugs = Object.keys( bySlug ).sort( function ( a, b ) { return weight[ b ] - weight[ a ]; } );
		if ( ! slugs.length ) {
			$res.append( $( '<p class="tsosk-lo-empty"></p>' ).text( L.nothing ) );
			$( '#tsosk-lo-actions' ).hide();
			return;
		}
		$res.append( $( '<p class="tsosk-lo-summary"></p>' ).text( String( L.summary ).replace( '%1$d', String( slugs.length ) ) ) );

		// Two clearly separated blocks: what can be deleted, and what is shown for information only.
		var $secDel = $( '<div class="tsosk-lo-section tsosk-lo-section--del"></div>' ), $secInfo = $( '<div class="tsosk-lo-section tsosk-lo-section--info"></div>' ), nDel = 0, nInfo = 0;
		$secDel.append( $( '<h3 class="tsosk-lo-section-title"></h3>' ).text( L.section_del ) ).append( $( '<p class="description"></p>' ).text( L.section_del_desc ) );
		$secInfo.append( $( '<h3 class="tsosk-lo-section-title"></h3>' ).text( L.section_info ) ).append( $( '<p class="description"></p>' ).text( L.section_info_desc ) );

		$.each( slugs, function ( i, slugKey ) {
			var parts = slugKey.split( ':' ), type = parts[ 0 ], slug = parts.slice( 1 ).join( ':' );
			var $card = $( '<div class="tsosk-lo-card"></div>' );
			var $head = $( '<div class="tsosk-lo-head"></div>' );
			$head.append( $( '<strong class="tsosk-lo-slug"></strong>' ).text( slug ) );
			$head.append( $( '<span class="tsosk-badge tsosk-badge-warn"></span>' ).text( L[ type ] || type ) );
			$head.append( $( '<span class="tsosk-lo-ni"></span>' ).text( L.not_installed ) );
			var canPick = false;
			$.each( bySlug[ slugKey ], function ( j, g ) {
				if ( g.deletable > 0 && ! g.protected ) {
					canPick = true;
				}
			} );
			if ( ! canPick ) {
				$card.addClass( 'tsosk-lo-card--info' );
			}
			if ( canPick ) {
				var $all = $( '<input type="checkbox" class="tsosk-lo-all">' );
				$head.append( $( '<label class="tsosk-lo-allwrap"></label>' ).append( $all ).append( document.createTextNode( ' ' + L.select_all ) ) );
			}
			$card.append( $head );

			var $table = $( '<table class="widefat striped tsosk-table tsosk-lo-table"><thead><tr><th class="tsosk-lo-c-pick"></th><th></th><th></th><th></th><th></th></tr></thead><tbody></tbody></table>' );
			$table.find( 'th' ).eq( 1 ).text( L.where );
			$table.find( 'th' ).eq( 2 ).text( L.name );
			$table.find( 'th' ).eq( 3 ).text( L.rows );
			$table.find( 'th' ).eq( 4 ).text( L.example );
			var $body = $table.find( 'tbody' );
			var hasPosts = false;

			$.each( bySlug[ slugKey ], function ( j, g ) {
				var $tr = $( '<tr></tr>' ).attr( 'data-source', g.source ).attr( 'data-key', g.key );
				var $pick = $( '<td class="tsosk-lo-c-pick"></td>' );
				if ( g.deletable > 0 && ! g.protected ) {
					$pick.append( $( '<input type="checkbox" class="tsosk-lo-pick">' )
						.attr( 'data-source', g.source ).attr( 'data-key', g.key ).attr( 'data-slug', slugKey ).attr( 'data-count', g.deletable ) );
				} else if ( 'posts' !== g.source ) {
					$tr.addClass( 'tsosk-lo-row--locked' );
					$pick.append( $( '<span class="tsosk-lo-lock"></span>' ).text( g.protected ? L.badge_protected : ( g.large > 0 ? L.badge_large : L.badge_installed ) ) );
				}
				$tr.append( $pick );
				$tr.append( $( '<td></td>' ).attr( 'data-label', L.where ).text( L[ 'src_' + g.source ] || g.source ) );
				var $keyCell = $( '<td class="tsosk-lo-key"></td>' ).attr( 'data-label', L.name ).append( g.key ? $( '<code></code>' ).text( g.key ) : '' );
				if ( g.key && 'posts' !== g.source ) {
					$keyCell.append( $( '<div></div>' ).append(
						$( '<a href="#" class="tsosk-lo-view"></a>' )
							.text( L.view ).attr( 'data-source', g.source ).attr( 'data-key', g.key ).attr( 'data-slug', slugKey )
					) );
				}
				$tr.append( $keyCell );
				var $rows = $( '<td></td>' ).attr( 'data-label', L.rows ).text( String( g.rows ) );
				if ( g.mixed > 0 && 'posts' !== g.source ) {
					$rows.append( $( '<div class="tsosk-lo-note"></div>' ).text( g.mixed + ' ' + ( g.protected ? L.protected_note : L.mixed_note ) ) );
				}
				if ( g.large > 0 && 'posts' !== g.source ) {
					$rows.append( $( '<div class="tsosk-lo-note"></div>' ).text( g.large + ' ' + L.large_note ) );
				}
				$tr.append( $rows );
				var $ex = $( '<td class="tsosk-lo-example"></td>' ).attr( 'data-label', L.example );
				if ( g.sample ) {
					$ex.append( $( '<code></code>' ).text( g.sample ) );
				}
				if ( g.links && g.links.length ) {
					hasPosts = true;
					var $ul = $( '<div class="tsosk-lo-links"></div>' );
					$.each( g.links, function ( n, l ) {
						$ul.append( $( '<a target="_blank" rel="noopener"></a>' ).attr( 'href', l.url ).text( '#' + l.id + ( l.title ? ' ' + l.title : '' ) ) );
					} );
					$ex.append( $ul );
				}
				$tr.append( $ex );
				$body.append( $tr );
			} );
			$card.append( $( '<div class="tsosk-lo-scroll"></div>' ).append( $table ) );
			if ( hasPosts ) {
				$card.append( $( '<p class="description tsosk-lo-posts-note"></p>' ).text( L.posts_note ) );
			}
			if ( canPick ) {
				$secDel.append( $card );
				++nDel;
			} else {
				$secInfo.append( $card );
				++nInfo;
			}
		} );
		if ( nDel ) {
			$secDel.children( 'p.description' ).after( $( '#tsosk-lo-actions' ) );
			$res.append( $secDel );
		}
		if ( nInfo ) {
			$res.append( $secInfo );
		}
		$( '#tsosk-lo-actions' ).toggle( nDel > 0 );
		tsoskLoUpdateSelected();
	}

	$( document ).on( 'click', '#tsosk-lo-scan', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-status' );
		var nonce = $( '#tsosk-lo-app' ).attr( 'data-nonce' );
		var si = 0, scanned = 0;
		tsoskLoGroups = {};
		$btn.prop( 'disabled', true );
		tsoskLoParkActions();
		$( '#tsosk-lo-results' ).empty();
		$( '#tsosk-lo-actions' ).hide();

		function fail( msg ) {
			showMsg( $status, msg || L.error, 'error', 30000 );
			$btn.prop( 'disabled', false );
		}
		function step( after ) {
			if ( si >= tsoskLoSources.length ) {
				$btn.prop( 'disabled', false );
				tsoskLoRender();
				showMsg( $status, L.scan_done, 'ok' );
				return;
			}
			showMsg( $status, L.scanning + ' ' + ( L[ 'src_' + tsoskLoSources[ si ] ] || '' ) + ( scanned ? ' (' + scanned + ')' : '' ), 'ok', 600000 );
			ajaxPost( {
				action : 'tsosk_lo_scan',
				data   : { nonce: nonce, source: tsoskLoSources[ si ], after: after },
				success: function ( r ) {
					if ( ! r.success ) {
						fail( r.data );
						return;
					}
					tsoskLoMerge( r.data.groups );
					scanned += r.data.scanned || 0;
					if ( r.data.next ) {
						step( r.data.next );
					} else {
						si++;
						step( 0 );
					}
				},
				error: function () { fail(); }
			} );
		}
		step( 0 );
	} );

	// "View": a few rows of the group, with the text around the stale path.
	$( document ).on( 'click', '.tsosk-lo-view', function ( e ) {
		e.preventDefault();
		var $a = $( this ), L = tsoskLoStrings(), $tr = $a.closest( 'tr' ), $next = $tr.next( '.tsosk-lo-peek' );
		if ( $next.length ) {
			$next.remove();
			$a.text( L.view );
			return;
		}
		$a.text( L.hide );
		var $peek = $( '<tr class="tsosk-lo-peek"><td></td><td colspan="4"></td></tr>' );
		$tr.after( $peek );
		ajaxPost( {
			action : 'tsosk_lo_peek',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), source: $a.attr( 'data-source' ), key: $a.attr( 'data-key' ), slug_key: $a.attr( 'data-slug' ) },
			success: function ( r ) {
				var $cell = $peek.children().eq( 1 );
				if ( ! r.success || ! r.data.rows.length ) {
					$cell.text( r.success ? L.peek_none : ( r.data || L.error ) );
					return;
				}
				var canForce = 'options' === $a.attr( 'data-source' ) && r.data.rows.length === 1 && 'large' === r.data.rows[ 0 ].reason;
				$.each( r.data.rows, function ( i, row ) {
					var $item = $( '<div class="tsosk-lo-peek-row"></div>' );
					$item.append( $( '<strong></strong>' ).text( '#' + row.id + ' (' + row.size + ' B) — ' + ( row.deletable ? L.peek_deletable : ( 'installed' === row.reason ? L.peek_installed + ( row.installed && row.installed.length ? ' (' + row.installed.join( ', ' ) + ')' : '' ) : ( 'protected' === row.reason ? L.peek_protected : ( 'large' === row.reason ? L.peek_large : L.peek_kept ) ) ) ) ) );
					if ( row.why ) {
						$item.append( $( '<p class="tsosk-lo-why"></p>' ).text( row.why ) );
					}
					$item.append( $( '<code class="tsosk-lo-excerpt"></code>' ).text( row.excerpt ) );
					$cell.append( $item );
				} );
				if ( canForce ) {
					var $use = $( '<div class="tsosk-lo-usage"></div>' );
					$use.append( $( '<button type="button" class="button tsosk-lo-usage-btn"></button>' ).text( L.usage_btn )
						.attr( 'data-source', $a.attr( 'data-source' ) ).attr( 'data-key', $a.attr( 'data-key' ) ) );
					$use.append( $( '<div class="tsosk-lo-usage-out"></div>' ) );
					$cell.append( $use );
				}
			},
			error: function () { $peek.children().eq( 1 ).text( L.error ); }
		} );
	} );

	$( document ).on( 'click', '.tsosk-lo-usage-btn', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $out = $btn.siblings( '.tsosk-lo-usage-out' );
		var source = $btn.attr( 'data-source' ), key = $btn.attr( 'data-key' );
		$btn.prop( 'disabled', true );
		$out.empty().text( L.usage_running );
		ajaxPost( {
			action : 'tsosk_lo_usage',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), source: source, key: key },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				$out.empty();
				if ( ! r.success ) {
					$out.text( r.data || L.error );
					return;
				}
				var d = r.data || {};
				if ( d.exact && d.exact.length ) {
					$out.append( $( '<p class="tsosk-lo-usage-bad"></p>' ).text( L.usage_found + d.exact.join( ', ' ) ) );
				}
				if ( d.prefix && d.prefix.length ) {
					$out.append( $( '<p class="tsosk-lo-usage-bad"></p>' ).text( L.usage_prefix + d.prefix.join( ', ' ) ) );
				}
				if ( d.truncated ) {
					$out.append( $( '<p class="tsosk-lo-usage-bad"></p>' ).text( L.usage_trunc ) );
				}
				if ( ! ( d.exact && d.exact.length ) && ! ( d.prefix && d.prefix.length ) && ! d.truncated ) {
					$out.append( $( '<p class="tsosk-lo-usage-ok"></p>' ).text( String( L.usage_none ).replace( '%d', String( d.scanned || 0 ) ) ) );
					$out.append( $( '<button type="button" class="button button-primary tsosk-lo-force-btn"></button>' ).text( L.force_btn )
						.attr( 'data-source', source ).attr( 'data-key', key ) );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
				$out.text( L.error );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-lo-force-btn', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-status' );
		var source = $btn.attr( 'data-source' ), key = $btn.attr( 'data-key' );
		if ( ! window.confirm( L.confirm_force ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		showMsg( $status, L.scanning, 'ok', 600000 );
		ajaxPost( {
			action : 'tsosk_lo_force_delete',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), source: source, key: key },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $status, r.data.message, 'ok', 60000 );
					tsoskLoRemoveDeleted( key );
					tsoskLoRefreshBackupBox();
				} else {
					showMsg( $status, r.data || L.error, 'error', 30000 );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $status, L.error, 'error', 30000 );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'change', '.tsosk-lo-pick', tsoskLoUpdateSelected );
	$( document ).on( 'change', '.tsosk-lo-all', function () {
		$( this ).closest( '.tsosk-lo-card' ).find( '.tsosk-lo-pick' ).prop( 'checked', $( this ).prop( 'checked' ) );
		tsoskLoUpdateSelected();
	} );

	$( document ).on( 'click', '#tsosk-lo-delete', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-status' );
		var items = [];
		$( '.tsosk-lo-pick:checked' ).each( function () {
			items.push( { source: $( this ).attr( 'data-source' ), key: $( this ).attr( 'data-key' ), slug_key: $( this ).attr( 'data-slug' ) } );
		} );
		var total = tsoskLoUpdateSelected();
		if ( ! items.length || ! window.confirm( String( L.confirm_delete ).replace( '%d', String( total ) ) ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		showMsg( $status, L.scanning, 'ok', 600000 );
		ajaxPost( {
			action : 'tsosk_lo_delete',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), items: JSON.stringify( items ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $status, r.data.message, 'ok', 60000 );
					// Keep the results on screen: only the rows that were deleted disappear (no page reload).
					tsoskLoRemoveDeleted();
					$btn.prop( 'disabled', true );
					tsoskLoRefreshBackupBox();
				} else {
					showMsg( $status, r.data || L.error, 'error', 30000 );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $status, L.error, 'error', 30000 );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// Backups panel: restore a batch or picked rows, delete one or all.
	function tsoskLoBackupCall( $btn, action, data, confirmText ) {
		var L = tsoskLoStrings(), $msg = $( '#tsosk-lo-restore-msg' ), $box = $btn.closest( '#tsosk-lo-backup' );
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		$box.find( 'button' ).prop( 'disabled', true );
		ajaxPost( {
			action : action,
			data   : $.extend( { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ) }, data ),
			success: function ( r ) {
				if ( r.success ) {
					tsoskLoRefreshBackupBox( r.data.message );
				} else {
					$box.find( 'button' ).prop( 'disabled', false );
					showMsg( $msg, r.data || L.error, 'error', 30000 );
				}
			},
			error: function () {
				$box.find( 'button' ).prop( 'disabled', false );
				showMsg( $msg, L.error, 'error', 30000 );
			}
		} );
	}

	$( document ).on( 'click', '.tsosk-lo-bk-restore-all', function () {
		var $btn = $( this );
		tsoskLoBackupCall( $btn, 'tsosk_lo_restore', { id: $btn.closest( 'details' ).attr( 'data-id' ) }, tsoskLoStrings().confirm_restore );
	} );

	$( document ).on( 'click', '.tsosk-lo-bk-restore-sel', function () {
		var $btn = $( this ), $d = $btn.closest( 'details' ), picked = [];
		$d.find( '.tsosk-lo-bk-pick:checked' ).each( function () {
			picked.push( parseInt( $( this ).val(), 10 ) );
		} );
		if ( ! picked.length ) {
			showMsg( $( '#tsosk-lo-restore-msg' ), tsoskLoStrings().bk_none, 'error', 8000 );
			return;
		}
		tsoskLoBackupCall( $btn, 'tsosk_lo_restore', { id: $d.attr( 'data-id' ), rows: JSON.stringify( picked ) }, tsoskLoStrings().confirm_restore_sel );
	} );

	$( document ).on( 'click', '.tsosk-lo-bk-del', function () {
		var $btn = $( this );
		tsoskLoBackupCall( $btn, 'tsosk_lo_backup_delete', { id: $btn.closest( 'details' ).attr( 'data-id' ) }, tsoskLoStrings().confirm_bk_del );
	} );

	$( document ).on( 'click', '#tsosk-lo-bk-delall', function () {
		tsoskLoBackupCall( $( this ), 'tsosk_lo_backup_delete', { all: '1' }, tsoskLoStrings().confirm_bk_delall );
	} );

	$( document ).on( 'change', '.tsosk-lo-bk-pickall', function () {
		$( this ).closest( 'table' ).find( '.tsosk-lo-bk-pick' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	// ── Prefix census ──────────────────────────────────────────────────────
	var tsoskLoCensusNote = '';

	function tsoskLoFormatBytes( n ) {
		if ( n >= 1048576 ) {
			return ( n / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( n >= 1024 ) {
			return Math.round( n / 1024 ) + ' KB';
		}
		return n + ' B';
	}

	// "Ignore" link under a prefix or type name: the person is sure it is in use and does not want to see it again.
	function tsoskLoIgnoreButton( kind, source, name ) {
		return $( '<div/>' ).append( $( '<button type="button" class="button-link tsosk-lo-ignore"/>' ).attr( { 'data-kind': kind, 'data-source': source, 'data-name': name } ).text( tsoskLoStrings().census_ignore_btn ) );
	}

	// The list of ignored items, with a button to show each one again.
	function tsoskLoRenderIgnored( list ) {
		var L = tsoskLoStrings(), $box = $( '#tsosk-lo-ignored' ).empty();
		if ( ! list || ! list.length ) {
			return;
		}
		var $det = $( '<details/>' ).append( $( '<summary/>' ).text( String( L.census_ignored_title || '' ).replace( '%d', list.length ) ) );
		$det.append( $( '<p class="description"/>' ).text( L.census_ignored_note ) );
		var $ul = $( '<ul/>' );
		$.each( list, function ( i, it ) {
			var label = 'g' === it.kind ? it.name + ' (' + ( L[ 'src_' + it.source ] || it.source ) + ')' : it.name;
			$ul.append( $( '<li/>' ).append( $( '<code/>' ).text( label ) ).append( ' ' ).append( $( '<button type="button" class="button-link tsosk-lo-unignore"/>' ).attr( { 'data-kind': it.kind, 'data-source': it.source, 'data-name': it.name } ).text( L.census_unignore_btn ) ) );
		} );
		$box.append( $det.append( $ul ) );
	}

	// Copies each column title onto its cells so narrow screens can show them as labelled cards.
	function tsoskLoLabelCells( $table ) {
		var labels = [];
		$table.find( 'thead th' ).each( function () {
			labels.push( $( this ).text() );
		} );
		$table.find( 'tbody tr' ).each( function () {
			$( this ).children( 'td' ).each( function ( i ) {
				if ( labels[ i ] ) {
					$( this ).attr( 'data-label', labels[ i ] );
				}
			} );
		} );
	}

	function tsoskLoRenderTypes( types, $res, L ) {
		if ( ! types.length ) {
			return;
		}
		$res.append( $( '<h4/>' ).text( L.census_types_title ) );
		var $table = $( '<table class="widefat striped tsosk-table tsosk-lo-census-table"/>' ), $head = $( '<tr/>' );
		$.each( [ L.census_delete_col, L.census_name + ' / ' + L.where, L.rows + ' · ' + L.census_meta_rows, L.census_conf, L.census_evidence, L.census_samples ], function ( i, label ) {
			$head.append( $( '<th/>' ).text( label || '' ) );
		} );
		$table.append( $( '<thead/>' ).append( $head ) );
		var $body = $( '<tbody/>' );
		$.each( types, function ( i, c ) {
			var $tr = $( '<tr/>' ), $pick = $( '<td/>' );
			if ( 'post_type' === c.kind && c.rows > 0 ) {
				$pick.append( $( '<input type="checkbox" class="tsosk-lo-type-pick"/>' ).attr( { 'data-name': c.name, 'data-rows': c.rows, 'data-weak': ( 'high' === c.confidence && 'none' === c.tier ) ? '' : '1', 'aria-label': ( L.census_delete_col || '' ) + ' ' + c.name } ) );
			}
			$tr.append( $pick );
			$tr.append( $( '<td/>' ).append( $( '<code/>' ).text( c.name ) ).append( $( '<span class="tsosk-lo-sub"/>' ).text( L[ 'census_kind_' + c.kind ] || c.kind ) ).append( tsoskLoIgnoreButton( 't', '', c.name ) ) );
			$tr.append( $( '<td/>' ).text( c.rows + ' · ' + c.meta ) );
			$tr.append( $( '<td/>' ).text( L[ 'conf_' + c.confidence ] || c.confidence ) );
			$tr.append( $( '<td class="tsosk-lo-wrapcell"/>' ).text( 'few' === c.tier ? ( L.census_ev_few || '' ) + ( c.mentions || [] ).join( ', ' ) : ( L.census_ev_none || '' ) ) );
			$tr.append( $( '<td class="tsosk-lo-wrapcell"/>' ).text( ( c.samples || [] ).join( ', ' ) ) );
			$body.append( $tr );
		} );
		$table.append( $body );
		tsoskLoLabelCells( $table );
		$res.append( $( '<div class="tsosk-lo-census-wrap"/>' ).append( $table ) );
		if ( $table.find( '.tsosk-lo-type-pick' ).length ) {
			$res.append( $( '<p/>' ).append( $( '<button type="button" class="button" id="tsosk-lo-types-delete"/>' ).text( L.census_tdel_btn ) ) );
		}
		$res.append( $( '<p class="description"/>' ).text( L.census_types_note ) );
	}

	function tsoskLoRenderCensus( d ) {
		var L = tsoskLoStrings(), $res = $( '#tsosk-lo-census-results' ).empty();
		var $sum = $( '<p/>' ).text( String( L.census_summary || '' ).replace( '%1$d', d.checked ).replace( '%2$d', d.files ).replace( '%3$d', d.total ) );
		$res.append( $sum );
		$res.append( $( '<div id="tsosk-lo-ignored"/>' ) );
		tsoskLoRenderIgnored( d.ignored || [] );
		if ( d.few ) {
			$res.append( $( '<p class="description"/>' ).text( String( L.census_summary_few || '' ).replace( '%d', d.few ) ) );
		}
		if ( d.truncated ) {
			$res.append( $( '<p class="description"/>' ).text( L.census_trunc ) );
		}
		if ( d.cut ) {
			$res.append( $( '<p class="description"/>' ).text( L.census_cut ) );
		}
		tsoskLoRenderTypes( d.types || [], $res, L );
		if ( ! d.candidates || ! d.candidates.length ) {
			if ( ! d.types || ! d.types.length ) {
				$res.append( $( '<p/>' ).text( L.census_none ) );
			}
			return;
		}
		var $table = $( '<table class="widefat striped tsosk-table tsosk-lo-census-table"/>' );
		var $head = $( '<tr/>' );
		$.each( [ L.census_watch_col, L.census_delete_col, L.census_prefix + ' / ' + L.where, L.census_keys + ' · ' + L.rows + ' · ' + L.census_size, L.census_conf, L.census_evidence, L.census_samples ], function ( i, label ) {
			$head.append( $( '<th/>' ).text( label || '' ) );
		} );
		$table.append( $( '<thead/>' ).append( $head ) );
		var $body = $( '<tbody/>' );
		$.each( d.candidates, function ( i, c ) {
			var $tr = $( '<tr/>' );
			$tr.append( $( '<td/>' ).append( $( '<input type="checkbox" class="tsosk-lo-watch-pick" checked="checked"/>' ).attr( { 'data-source': c.source, 'data-prefix': c.prefix, 'aria-label': ( L.census_watch_col || '' ) + ' ' + c.prefix } ) ) );
			// Low-evidence groups (short prefix, nothing else to go on) cannot be deleted from here; the server refuses them too.
			var $del = $( '<td/>' );
			if ( 'few' === c.tier || 'low' !== c.confidence ) {
				$del.append( $( '<input type="checkbox" class="tsosk-lo-del-pick"/>' ).attr( { 'data-source': c.source, 'data-prefix': c.prefix, 'data-few': 'few' === c.tier ? '1' : '0', 'aria-label': ( L.census_delete_col || '' ) + ' ' + c.prefix } ) );
			}
			$tr.append( $del );
			$tr.append( $( '<td/>' ).append( $( '<code/>' ).text( c.prefix ) ).append( $( '<span class="tsosk-lo-sub"/>' ).text( L[ 'src_' + c.source ] || c.source ) ).append( tsoskLoIgnoreButton( 'g', c.source, c.prefix ) ) );
			$tr.append( $( '<td/>' ).text( c.keys + ' · ' + c.rows + ' · ' + tsoskLoFormatBytes( c.bytes ) ) );
			$tr.append( $( '<td/>' ).text( L[ 'conf_' + c.confidence ] || c.confidence ) );
			$tr.append( $( '<td class="tsosk-lo-wrapcell"/>' ).text( 'few' === c.tier ? ( L.census_ev_few || '' ) + ( c.mentions || [] ).join( ', ' ) : ( L.census_ev_none || '' ) ) );
			$tr.append( $( '<td class="tsosk-lo-wrapcell"/>' ).text( ( c.samples || [] ).join( ', ' ) ) );
			$body.append( $tr );
		} );
		$table.append( $body );
		tsoskLoLabelCells( $table );
		$res.append( $( '<div class="tsosk-lo-census-wrap"/>' ).append( $table ) );
		$res.append( $( '<p/>' ).append( $( '<button type="button" class="button" id="tsosk-lo-watch-start"/>' ).text( L.watch_btn ) ).append( ' ' ).append( $( '<button type="button" class="button" id="tsosk-lo-census-delete"/>' ).text( L.census_delete_btn ) ) );
		$res.append( $( '<p class="description"/>' ).text( L.census_note ) );
	}

	$( document ).on( 'click', '#tsosk-lo-census', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-census-status' );
		$btn.prop( 'disabled', true );
		$( '#tsosk-lo-census-results' ).empty();
		showMsg( $status, L.census_running, 'ok', 600000 );
		ajaxPost( {
			action : 'tsosk_lo_census',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ) },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					showMsg( $status, r.data || L.error, 'error', 30000 );
					return;
				}
				if ( tsoskLoCensusNote ) {
					showMsg( $status, tsoskLoCensusNote, 'ok', 120000 );
					tsoskLoCensusNote = '';
				} else {
					showMsg( $status, L.scan_done, 'ok' );
				}
				tsoskLoRenderCensus( r.data );
			},
			error: function () {
				$btn.prop( 'disabled', false );
				showMsg( $status, L.error, 'error', 30000 );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-lo-census-delete', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-census-status' ), items = [], few = [];
		$( '.tsosk-lo-del-pick:checked' ).each( function () {
			var isFew = '1' === $( this ).attr( 'data-few' );
			items.push( { source: $( this ).attr( 'data-source' ), prefix: $( this ).attr( 'data-prefix' ), accept_few: isFew } );
			if ( isFew ) {
				few.push( $( this ).attr( 'data-prefix' ) );
			}
		} );
		if ( ! items.length ) {
			showMsg( $status, L.census_delete_pick, 'error', 8000 );
			return;
		}
		if ( ! window.confirm( String( L.census_delete_confirm || '' ).replace( '%d', items.length ) ) ) {
			return;
		}
		if ( few.length && ! window.confirm( String( L.census_delete_few || '' ).replace( '%s', few.join( ', ' ) ) ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		showMsg( $status, L.census_deleting, 'ok', 600000 );
		ajaxPost( {
			action : 'tsosk_lo_census_delete',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), items: JSON.stringify( items ) },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					showMsg( $status, r.data || L.error, 'error', 60000 );
					return;
				}
				tsoskLoRefreshBackupBox( '' );
				tsoskLoCensusNote = r.data.message;
				$( '#tsosk-lo-census' ).trigger( 'click' );
			},
			error: function () {
				$btn.prop( 'disabled', false );
				showMsg( $status, L.error, 'error', 30000 );
			}
		} );
	} );


	$( document ).on( 'click', '.tsosk-lo-ignore, .tsosk-lo-unignore', function () {
		var $b = $( this ), L = tsoskLoStrings(), adding = $b.hasClass( 'tsosk-lo-ignore' ), $status = $( '#tsosk-lo-census-status' );
		if ( adding && ! window.confirm( L.census_ignore_confirm ) ) {
			return;
		}
		$b.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_lo_census_ignore',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), mode: adding ? 'add' : 'remove', kind: $b.attr( 'data-kind' ), source: $b.attr( 'data-source' ), name: $b.attr( 'data-name' ) },
			success: function ( r ) {
				if ( ! r.success ) {
					$b.prop( 'disabled', false );
					showMsg( $status, r.data || L.error, 'error', 30000 );
					return;
				}
				if ( adding ) {
					$b.closest( 'tr' ).remove();
				}
				tsoskLoRenderIgnored( r.data.ignored );
			},
			error: function () {
				$b.prop( 'disabled', false );
				showMsg( $status, L.error, 'error', 30000 );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-lo-types-delete', function () {
		var $btn = $( this ), L = tsoskLoStrings(), $status = $( '#tsosk-lo-census-status' ), names = [], weak = [], entries = 0;
		$( '.tsosk-lo-type-pick:checked' ).each( function () {
			names.push( $( this ).attr( 'data-name' ) );
			if ( '1' === $( this ).attr( 'data-weak' ) ) {
				weak.push( $( this ).attr( 'data-name' ) );
			}
			entries += parseInt( $( this ).attr( 'data-rows' ), 10 ) || 0;
		} );
		if ( ! names.length ) {
			showMsg( $status, L.census_tdel_pick, 'error', 8000 );
			return;
		}
		if ( ! window.confirm( String( L.census_tdel_confirm || '' ).replace( '%1$d', names.length ).replace( '%2$d', entries ) ) ) {
			return;
		}
		if ( weak.length && ! window.confirm( String( L.census_tdel_few || '' ).replace( '%s', weak.join( ', ' ) ) ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		showMsg( $status, L.census_deleting, 'ok', 600000 );
		ajaxPost( {
			action : 'tsosk_lo_census_delete_types',
			data   : { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ), names: JSON.stringify( names ), force: weak.length ? '1' : '' },
			success: function ( r ) {
				$btn.prop( 'disabled', false );
				if ( ! r.success ) {
					showMsg( $status, r.data || L.error, 'error', 60000 );
					return;
				}
				tsoskLoRefreshBackupBox( '' );
				tsoskLoCensusNote = r.data.message;
				$( '#tsosk-lo-census' ).trigger( 'click' );
			},
			error: function () {
				$btn.prop( 'disabled', false );
				showMsg( $status, L.error, 'error', 30000 );
			}
		} );
	} );

	// ── Read watch ─────────────────────────────────────────────────────────
	function tsoskLoWatchRender( d ) {
		var L = tsoskLoStrings(), $res = $( '#tsosk-lo-watch-results' ).empty();
		if ( ! d || ! d.active ) {
			$res.append( $( '<p/>' ).text( L.watch_inactive ) );
			return;
		}
		$res.append( $( '<p/>' ).text( String( L.watch_summary || '' ).replace( '%1$d', d.items.length ).replace( '%2$s', d.started ).replace( '%3$d', d.days_left ) ) );
		var $table = $( '<table class="widefat striped tsosk-table tsosk-lo-census-table"/>' ), $head = $( '<tr/>' );
		$.each( [ L.census_prefix, L.where, L.census_keys, L.watch_last, L.watch_readers ], function ( i, label ) {
			$head.append( $( '<th/>' ).text( label || '' ) );
		} );
		$table.append( $( '<thead/>' ).append( $head ) );
		var $body = $( '<tbody/>' );
		$.each( d.items, function ( i, it ) {
			var $tr = $( '<tr/>' );
			$tr.append( $( '<td/>' ).append( $( '<code/>' ).text( it.prefix ) ) );
			$tr.append( $( '<td/>' ).text( L[ 'src_' + it.source ] || it.source ) );
			$tr.append( $( '<td/>' ).text( it.source === 'options' ? it.keys : '' ) );
			$tr.append( $( '<td/>' ).text( it.last || L.watch_never ) );
			$tr.append( $( '<td class="tsosk-lo-wrapcell"/>' ).text( ( it.readers || [] ).join( ', ' ) ) );
			$body.append( $tr );
		} );
		$table.append( $body );
		tsoskLoLabelCells( $table );
		$res.append( $( '<div class="tsosk-lo-census-wrap"/>' ).append( $table ) );
	}

	function tsoskLoWatchCall( action, data, doneMsg ) {
		var L = tsoskLoStrings(), $status = $( '#tsosk-lo-watch-status' );
		ajaxPost( {
			action : action,
			data   : $.extend( { nonce: $( '#tsosk-lo-app' ).attr( 'data-nonce' ) }, data || {} ),
			success: function ( r ) {
				if ( ! r.success ) {
					showMsg( $status, r.data || L.error, 'error', 30000 );
					return;
				}
				tsoskLoWatchRender( r.data );
				if ( doneMsg ) {
					showMsg( $status, doneMsg, 'ok' );
				}
			},
			error: function () { showMsg( $status, L.error, 'error', 30000 ); }
		} );
	}

	$( document ).on( 'click', '#tsosk-lo-watch-start', function () {
		var L = tsoskLoStrings(), items = [];
		$( '.tsosk-lo-watch-pick:checked' ).each( function () {
			items.push( { source: $( this ).attr( 'data-source' ), prefix: $( this ).attr( 'data-prefix' ) } );
		} );
		if ( ! items.length ) {
			showMsg( $( '#tsosk-lo-census-status' ), L.watch_pick, 'error', 8000 );
			return;
		}
		tsoskLoWatchCall( 'tsosk_lo_watch_start', { items: JSON.stringify( items ) }, L.watch_started );
		$( '#tsosk-lo-watch-card' )[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	} );

	$( document ).on( 'click', '#tsosk-lo-watch-refresh', function () {
		tsoskLoWatchCall( 'tsosk_lo_watch_status', {}, '' );
	} );

	$( document ).on( 'click', '#tsosk-lo-watch-stop', function () {
		var L = tsoskLoStrings();
		if ( window.confirm( L.watch_confirm_stop ) ) {
			tsoskLoWatchCall( 'tsosk_lo_watch_stop', {}, L.watch_stopped );
		}
	} );

	$( function () {
		if ( $( '#tsosk-lo-watch-card' ).length ) {
			tsoskLoWatchCall( 'tsosk_lo_watch_status', {}, '' );
		}
	} );

} )( jQuery );
