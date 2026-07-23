/**
 * TSO Swiss Knife – Admin JavaScript
 *
 * Handles all AJAX interactions for every module tab.
 * All data is read from data-* attributes on buttons so nonces
 * are always fresh (rendered server-side per request).
 *
 * @package TSO_Swiss_Knife
 * @since   1.0.0
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Show a brief status message inside an element, auto-hide after 4 s.
	 *
	 * @param {jQuery} $el      Target element.
	 * @param {string} msg      Message text.
	 * @param {string} [type]   'ok' | 'warn' | 'error'  (default 'ok').
	 */
	function showMsg( $el, msg, type ) {
		var cls = 'tsosk-msg tsosk-msg--' + ( type || 'ok' );
		$el.attr( 'class', cls ).text( msg ).show();
		clearTimeout( $el.data( 'timer' ) );
		$el.data( 'timer', setTimeout( function () {
			$el.fadeOut( 300 );
		}, 4000 ) );
	}

	/**
	 * Generic AJAX POST wrapper.
	 *
	 * @param {Object}   params
	 * @param {string}   params.action   WP AJAX action slug.
	 * @param {Object}   params.data     Extra POST data.
	 * @param {Function} params.success  Callback( response ).
	 * @param {Function} [params.error]  Optional error callback( xhr ).
	 */
	function ajaxPost( params ) {
		var request = $.post(
			tsosk.ajax_url,
			$.extend( { action: params.action }, params.data ),
			function ( response ) {
				if ( params.success ) {
					params.success( response );
				}
			},
			'json'
		);
		request.fail( function ( xhr ) {
			if ( params.error ) {
				params.error( xhr );
			}
		} );
		if ( params.complete ) {
			request.always( params.complete );
		}
	}

	// ── Cron Manager ───────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-cron-run', function () {
		var $btn   = $( this );
		var hook   = $btn.data( 'hook' );
		var ts     = $btn.data( 'timestamp' );
		var sig    = $btn.data( 'sig' );
		var nonce  = $btn.data( 'nonce' );
		var $row   = $btn.closest( 'tr' );
		var $msg   = $row.find( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_cron_run',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.run );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cron-delete', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn  = $( this );
		var hook  = $btn.data( 'hook' );
		var ts    = $btn.data( 'timestamp' );
		var sig   = $btn.data( 'sig' );
		var nonce = $btn.data( 'nonce' );
		var $row  = $btn.closest( 'tr' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_cron_delete',
			data   : { hook: hook, timestamp: ts, sig: sig, nonce: nonce },
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

	$( document ).on( 'click', '.tsosk-cron-edit', function () {
		var $btn = $( this );
		var ts   = parseInt( $btn.data( 'timestamp' ), 10 );
		var when = new Date( ts * 1000 );
		var pad  = function ( n ) { return n < 10 ? '0' + n : String( n ); };
		var local = when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() )
			+ 'T' + pad( when.getHours() ) + ':' + pad( when.getMinutes() );

		$( '#tsosk-cron-edit-hook' ).val( $btn.data( 'hook' ) );
		$( '#tsosk-cron-edit-timestamp' ).val( $btn.data( 'timestamp' ) );
		$( '#tsosk-cron-edit-sig' ).val( $btn.data( 'sig' ) );
		$( '#tsosk-cron-edit-when' ).val( local );
		$( '#tsosk-cron-edit-schedule' ).val( $btn.data( 'schedule' ) || 'single' );
		$( '#tsosk-cron-edit-msg' ).hide().text( '' );
		$( '#tsosk-cron-edit-panel' ).slideDown( 200 );
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-cron-edit-panel' ).offset().top - 40 }, 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-cancel', function () {
		$( '#tsosk-cron-edit-panel' ).slideUp( 200 );
	} );

	$( document ).on( 'click', '#tsosk-cron-edit-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-cron-edit-msg' );
		var when  = $( '#tsosk-cron-edit-when' ).val();
		if ( ! when ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		var newTs = Math.floor( new Date( when ).getTime() / 1000 );
		if ( ! newTs || isNaN( newTs ) ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cron_reschedule',
			data   : {
				nonce         : $( '#tsosk-cron-edit-nonce' ).val(),
				hook          : $( '#tsosk-cron-edit-hook' ).val(),
				timestamp     : $( '#tsosk-cron-edit-timestamp' ).val(),
				sig           : $( '#tsosk-cron-edit-sig' ).val(),
				new_timestamp : newTs,
				schedule      : $( '#tsosk-cron-edit-schedule' ).val()
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.cron_rescheduled, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
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

	// ── Debug Mode ─────────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-log-msg' );
		var path  = $btn.data( 'log-path' );
		var label = $btn.data( 'log-label' ) || path;
		var confirmMsg = tsosk.i18n.debug_empty_log_confirm.replace( '%s', label );

		if ( ! confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_debug_clear_log',
			data   : { nonce: nonce, log_path: path },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					var anchor = $btn.closest( 'tr' ).find( 'a[href^="#tsosk-log-"]' ).attr( 'href' );
					if ( anchor ) {
						$( anchor ).find( '.tsosk-log-preview' ).empty();
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-shrink-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-log-msg' );
		var path  = $btn.data( 'log-path' );
		var label = $btn.data( 'log-label' ) || path;
		var confirmMsg = ( tsosk.i18n.debug_shrink_log_confirm || 'Keep only the last 500 lines of %s? Older lines will be archived.' ).replace( '%s', label );

		if ( ! confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_debug_shrink_log',
			data   : { nonce: nonce, log_path: path, keep_lines: 500 },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) {
					setTimeout( function () { window.location.reload(); }, 1200 );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	function tsoskFindLogTargets( path ) {
		return {
			card: $( '.tsosk-log-card' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} ),
			row: $( '.tsosk-log-row' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} ),
			buttons: $( '.tsosk-refresh-log' ).filter( function () {
				return $( this ).attr( 'data-log-path' ) === path;
			} )
		};
	}

	function tsoskApplyLogRefresh( path, data ) {
		var targets = tsoskFindLogTargets( path );

		if ( ! data.unchanged && data.html && targets.card.length ) {
			targets.card.find( '.tsosk-log-preview' ).html( data.html );
			targets.card.find( '.tsosk-log-size' ).text( data.size );
			targets.card.attr( 'data-log-modified', data.modified );
			targets.card.find( '.tsosk-log-search' ).trigger( 'input' );
		}

		if ( targets.row.length ) {
			targets.row.find( '.tsosk-log-size-cell' ).text( data.size );
			targets.row.find( '.tsosk-log-modified-cell' ).text( data.modified_label || '' );
			targets.row.attr( 'data-log-modified', data.modified );
		}
	}

	$( document ).on( 'click', '.tsosk-refresh-log', function () {
		var $btn   = $( this );
		var nonce  = $btn.data( 'nonce' );
		var path   = $btn.attr( 'data-log-path' ) || '';
		var $msg   = $( '#tsosk-log-msg' );
		var targets = tsoskFindLogTargets( path );
		var source = targets.card.length ? targets.card : targets.row;
		var lastModified = parseInt( source.attr( 'data-log-modified' ) || '0', 10 );
		var label = tsosk.i18n.refresh_log || 'Refresh';

		if ( ! path ) {
			return;
		}

		targets.buttons.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_debug_refresh_log',
			data   : { nonce: nonce, log_path: path, last_modified: lastModified },
			success: function ( r ) {
				if ( r.success && r.data ) {
					tsoskApplyLogRefresh( path, r.data );
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				targets.buttons.prop( 'disabled', false ).text( label );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				targets.buttons.prop( 'disabled', false ).text( label );
			}
		} );
	} );

	$( document ).on( 'input change', '.tsosk-log-search, .tsosk-log-level', function () {
		var $card = $( this ).closest( '.tsosk-card' );
		var query = $card.find( '.tsosk-log-search' ).val().toLowerCase();
		var level = $card.find( '.tsosk-log-level' ).val();

		$card.find( '.tsosk-log-line' ).each( function () {
			var $line = $( this );
			var textMatches = ! query || $line.text().toLowerCase().indexOf( query ) !== -1;
			var levelMatches = level === 'all' || $line.data( 'level' ) === level;
			$line.toggle( textMatches && levelMatches );
		} );
	} );

	// ── Health Report ──────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-health-save-alerts', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-health-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_health_save_alerts',
			data   : {
				nonce              : $btn.data( 'nonce' ),
				enabled            : $( '#tsosk-alerts-enabled' ).prop( 'checked' ) ? 1 : 0,
				email              : $( '#tsosk-alerts-email' ).val(),
				not_found_threshold: $( '#tsosk-alerts-404-threshold' ).val()
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save_alerts );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save_alerts );
			}
		} );
	} );

	// ── WP Internals ───────────────────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-internals-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '.tsosk-internals-row' ).each( function () {
			$( this ).toggle( $( this ).text().toLowerCase().indexOf( q ) !== -1 );
		} );
	} );

	// ── Users & Sessions ───────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-user-close-sessions', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $btn = $( this );
		var $msg = $btn.siblings( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_user_destroy_sessions',
			data   : { nonce: $btn.data( 'nonce' ), user_id: $btn.data( 'user-id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 600 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.close_all_sessions );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.close_all_sessions );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-user-close-one-session', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_user_destroy_one_session',
			data   : { nonce: $btn.data( 'nonce' ), user_id: $btn.data( 'user-id' ), token: $btn.data( 'token' ) },
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

	$( document ).on( 'click', '.tsosk-user-force-pwd', function () {
		if ( ! window.confirm( tsosk.i18n.force_pwd_confirm ) ) { return; }
		var $btn = $( this );
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

	function tsoskUsersBulkInactive( action ) {
		var nonce = $( '#tsosk-users-bulk-nonce' ).val();
		var ids   = [];
		$( '.tsosk-users-inactive-cb:checked' ).each( function () { ids.push( $( this ).val() ); } );
		if ( ! ids.length ) {
			window.alert( tsosk.i18n.users_select_one );
			return;
		}
		if ( ! window.confirm( action === 'delete' ? tsosk.i18n.confirm_delete : tsosk.i18n.users_bulk_confirm ) ) {
			return;
		}
		var $msg = $( '#tsosk-users-bulk-msg' );
		ajaxPost( {
			action : 'tsosk_user_bulk_inactive',
			data   : { nonce: nonce, bulk_action: action, user_ids: ids, days: 90 },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { setTimeout( function () { window.location.reload(); }, 700 ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	}

	$( document ).on( 'click', '#tsosk-users-bulk-subscriber', function () { tsoskUsersBulkInactive( 'subscriber' ); } );
	$( document ).on( 'click', '#tsosk-users-bulk-delete', function () { tsoskUsersBulkInactive( 'delete' ); } );
	$( document ).on( 'change', '#tsosk-users-select-all', function () {
		$( '.tsosk-users-inactive-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
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
				if ( r.success ) { setTimeout( function () { window.location.reload(); }, 600 ); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	// ── Media Cleaner ──────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-media-full-review', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-media-full-review-msg' );
		var $prog = $( '#tsosk-media-full-review-progress' );
		var nonce = $btn.data( 'nonce' );
		var label = tsosk.i18n.media_full_review || 'Run full media review';

		function tick( start ) {
			ajaxPost( {
				action : 'tsosk_media_full_review',
				data   : { nonce: nonce, start: start ? 1 : 0 },
				success: function ( r ) {
					if ( ! r.success ) {
						showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
						$btn.prop( 'disabled', false ).text( label );
						return;
					}
					if ( r.data.progress ) {
						$prog.text( r.data.progress );
					}
					if ( r.data.done ) {
						if ( r.data.html ) {
							if ( r.data.html.missing ) {
								$( '#tsosk-media-missing-wrap' ).html( r.data.html.missing );
							}
							if ( r.data.html.orphans ) {
								$( '#tsosk-media-orphans-wrap' ).html( r.data.html.orphans );
							}
						}
						showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
						$btn.prop( 'disabled', false ).text( label );
						return;
					}
					tick( false );
				},
				error: function () {
					showMsg( $msg, tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( label );
				}
			} );
		}

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );
		$prog.text( tsosk.i18n.media_full_review_starting || 'Starting full media review…' );
		tick( true );
	} );

	$( document ).on( 'click', '.tsosk-media-regenerate', function () {
		var $btn = $( this );
		var $msg = $btn.siblings( '.tsosk-ajax-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_media_regenerate',
			data   : { nonce: $btn.data( 'nonce' ), attachment_id: $btn.data( 'attachment-id' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.regenerate );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.regenerate );
			}
		} );
	} );

	// ── Email Diagnostics ──────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-email-send-test', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-email-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_email_send_test',
			data   : { nonce: $btn.data( 'nonce' ), email: $( '#tsosk-email-test-address' ).val() },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.send_test );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.send_test );
			}
		} );
	} );

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

	// ── Constants: live search ──────────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-constants-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '.tsosk-constant-row' ).each( function () {
			var name = $( this ).find( 'td:first' ).text().toLowerCase();
			$( this ).toggle( name.indexOf( q ) !== -1 );
		} );
		// Hide group headers that have no visible rows.
		$( '.tsosk-card' ).each( function () {
			var $card    = $( this );
			var visible  = $card.find( '.tsosk-constant-row:visible' ).length;
			$card.toggle( visible > 0 );
		} );
	} );

	// ── REST API Controls ──────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-rest-save', function () {
		var $btn       = $( this );
		var nonce      = $btn.data( 'nonce' );
		var $msg       = $( '#tsosk-rest-msg' );
		var mode       = $( 'input[name="tsosk_rest_mode"]:checked' ).val();
		var disabled_ns = [];

		$( '.tsosk-ns-cb:checked' ).each( function () {
			disabled_ns.push( $( this ).val() );
		} );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_rest_save',
			data   : { nonce: nonce, mode: mode, disabled_namespaces: disabled_ns },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// ── Heartbeat Controls ─────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-heartbeat-save', function () {
		var $btn     = $( this );
		var nonce    = $btn.data( 'nonce' );
		var $msg     = $( '#tsosk-heartbeat-msg' );
		var mode     = $( 'input[name="tsosk_heartbeat_mode"]:checked' ).val();
		var interval = parseInt( $( '#tsosk-heartbeat-interval' ).val(), 10 ) || 0;

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_heartbeat_save',
			data   : { nonce: nonce, mode: mode, interval: interval },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// ── Update Manager ───────────────────────────────────────────────────────

	$( document ).on( 'change', 'input[name="tsosk_um_preset"]', function () {
		var preset = $( 'input[name="tsosk_um_preset"]:checked' ).val();
		$( '.tsosk-um-custom-panel' ).toggle( preset === 'custom' );
	} );

	$( document ).on( 'input', '#tsosk-um-plugin-filter', function () {
		var q = $( this ).val().toLowerCase();
		$( '.tsosk-um-plugin-row' ).each( function () {
			var hay = $( this ).data( 'search' ) || '';
			$( this ).toggle( ! q || String( hay ).indexOf( q ) !== -1 );
		} );
	} );

	$( document ).on( 'click', '#tsosk-um-run-updates', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-um-run-msg' );
		var label = $btn.text();

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_um_run_updates',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					var text = ( r.data && r.data.message ) ? r.data.message : ( r.data || tsosk.i18n.done );
					showMsg( $msg, text, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1500 );
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

	$( document ).on( 'click', '#tsosk-um-save', function () {
		var $btn   = $( this );
		var nonce  = $btn.data( 'nonce' );
		var $msg   = $( '#tsosk-um-msg' );
		var preset = $( 'input[name="tsosk_um_preset"]:checked' ).val();
		var pluginRules = {};

		$( '.tsosk-um-plugin-row' ).each( function () {
			var file = $( this ).data( 'plugin' );
			if ( ! file ) {
				return;
			}
			pluginRules[ file ] = {
				block: $( this ).find( '.tsosk-um-plugin-block' ).is( ':checked' ) ? 1 : 0
			};
		} );

		var data = {
			nonce              : nonce,
			preset             : preset,
			plugin_rules       : JSON.stringify( pluginRules ),
			email_core_major   : $( '#tsosk-um-email-core-major' ).is( ':checked' ) ? 1 : 0,
			email_core_minor   : $( '#tsosk-um-email-core-minor' ).is( ':checked' ) ? 1 : 0,
			email_core_fail    : $( '#tsosk-um-email-core-fail' ).is( ':checked' ) ? 1 : 0,
			email_manual_core  : $( '#tsosk-um-email-manual-core' ).is( ':checked' ) ? 1 : 0,
			email_plugin       : $( '#tsosk-um-email-plugin' ).is( ':checked' ) ? 1 : 0,
			email_theme        : $( '#tsosk-um-email-theme' ).is( ':checked' ) ? 1 : 0
		};

		if ( preset === 'custom' ) {
			data.block_core         = $( '#tsosk-um-block-core' ).is( ':checked' ) ? 1 : 0;
			data.block_plugins      = $( '#tsosk-um-block-plugins' ).is( ':checked' ) ? 1 : 0;
			data.block_themes       = $( '#tsosk-um-block-themes' ).is( ':checked' ) ? 1 : 0;
			data.block_translations = $( '#tsosk-um-block-translations' ).is( ':checked' ) ? 1 : 0;
			data.hide_update_nags   = $( '#tsosk-um-hide-nags' ).is( ':checked' ) ? 1 : 0;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_um_save',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// ── Hooks Inspector: live search ────────────────────────────────────────

	$( document ).on( 'input', '#tsosk-hooks-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '#tsosk-hooks-table tbody tr' ).each( function () {
			var hook = $( this ).find( 'td:first' ).text().toLowerCase();
			$( this ).toggle( hook.indexOf( q ) !== -1 );
		} );
	} );

	// ── Rewrite Rules ──────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-flush-soft', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-rewrite-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_flush_rewrite',
			data   : { nonce: nonce, hard: 0 },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.soft_flush );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.soft_flush );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-flush-hard', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-rewrite-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_flush_rewrite',
			data   : { nonce: nonce, hard: 1 },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.hard_flush );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.hard_flush );
			}
		} );
	} );

	// Rewrite rules: live filter.
	$( document ).on( 'input', '#tsosk-rewrite-search', function () {
		var q = $( this ).val().toLowerCase();
		$( '#tsosk-rewrite-table tbody tr' ).each( function () {
			var rule  = $( this ).find( 'td:first' ).text().toLowerCase();
			var query = $( this ).find( 'td:last' ).text().toLowerCase();
			$( this ).toggle( rule.indexOf( q ) !== -1 || query.indexOf( q ) !== -1 );
		} );
	} );

	// ── Maintenance Mode ───────────────────────────────────────────────────

	var tsoskMaintLogoFrame = null;

	function tsoskMaintLogoPreviewUrl( attachment ) {
		if ( attachment.sizes && attachment.sizes.large ) {
			return attachment.sizes.large.url;
		}
		if ( attachment.sizes && attachment.sizes.medium ) {
			return attachment.sizes.medium.url;
		}
		return attachment.url || '';
	}

	$( document ).on( 'click', '#tsosk-m-logo-select', function ( e ) {
		e.preventDefault();
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}
		if ( tsoskMaintLogoFrame ) {
			tsoskMaintLogoFrame.open();
			return;
		}
		tsoskMaintLogoFrame = wp.media( {
			title   : tsosk.i18n.maint_select_logo || 'Select logo',
			button  : { text: tsosk.i18n.maint_use_logo || 'Use this image' },
			library : { type: 'image' },
			multiple: false
		} );
		tsoskMaintLogoFrame.on( 'select', function () {
			var attachment = tsoskMaintLogoFrame.state().get( 'selection' ).first().toJSON();
			var url        = tsoskMaintLogoPreviewUrl( attachment );
			$( '#tsosk-m-logo-id' ).val( attachment.id || 0 );
			$( '#tsosk-m-logo-preview' ).addClass( 'has-logo' ).html(
				url ? $( '<img>', { src: url, alt: '' } ) : ''
			);
			$( '#tsosk-m-logo-remove' ).prop( 'disabled', ! attachment.id );
		} );
		tsoskMaintLogoFrame.open();
	} );

	$( document ).on( 'click', '#tsosk-m-logo-remove', function () {
		$( '#tsosk-m-logo-id' ).val( '0' );
		$( '#tsosk-m-logo-preview' ).removeClass( 'has-logo' ).empty();
		$( this ).prop( 'disabled', true );
	} );

	$( document ).on( 'click', '#tsosk-maintenance-toggle', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-maintenance-toggle-msg' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_maintenance_toggle',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
					// Update status badge text.
					var $badge = $( '#tsosk-maintenance-status' );
					if ( r.data.enabled ) {
						$badge.text( tsosk.i18n.on ).removeClass( 'tsosk-badge-ok' ).addClass( 'tsosk-badge-warn' );
						$btn.text( tsosk.i18n.disable );
					} else {
						$badge.text( tsosk.i18n.off ).removeClass( 'tsosk-badge-warn' ).addClass( 'tsosk-badge-ok' );
						$btn.text( tsosk.i18n.enable );
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-maintenance-save', function () {
		var $btn    = $( this );
		var nonce   = $btn.data( 'nonce' );
		var $msg    = $( '#tsosk-maintenance-save-msg' );
		var message = $( '#tsosk-m-message' ).val();
		var ips     = $( '#tsosk-m-ips' ).val();

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_maintenance_save',
			data   : {
				nonce        : nonce,
				message      : message,
				page_title   : $( '#tsosk-m-page-title' ).val() || '',
				whitelist_ips: ips,
				logo_id      : $( '#tsosk-m-logo-id' ).val() || 0
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-maintenance-preview', function () {
		var nonce   = $( this ).data( 'nonce' );
		var message = $( '#tsosk-m-message' ).val();
		var $form   = $( '<form>', {
			method : 'POST',
			action : tsosk.ajax_url,
			target : 'tsosk_maintenance_preview'
		} );

		$form.append( $( '<input>', { type: 'hidden', name: 'action', value: 'tsosk_maintenance_preview' } ) );
		$form.append( $( '<input>', { type: 'hidden', name: 'nonce', value: nonce } ) );
		$form.append( $( '<input>', { type: 'hidden', name: 'logo_id', value: $( '#tsosk-m-logo-id' ).val() || 0 } ) );
		$form.append( $( '<input>', { type: 'hidden', name: 'page_title', value: $( '#tsosk-m-page-title' ).val() || '' } ) );
		$form.append( $( '<textarea>', { name: 'message' } ).css( 'display', 'none' ).val( message ) );
		window.open( '', 'tsosk_maintenance_preview' );
		$form.appendTo( 'body' ).submit().remove();
	} );

	// ── Plugin Conflict Sandbox ─────────────────────────────────────────────

	// Helper buttons: Select All.
	$( document ).on( 'click', '#tsosk-sb-select-all', function () {
		$( '.tsosk-sandbox-cb' ).prop( 'checked', true );
	} );

	// Helper buttons: Select Normally Active.
	$( document ).on( 'click', '#tsosk-sb-select-active', function () {
		$( '.tsosk-sandbox-cb' ).each( function () {
			var $cb = $( this );
			var $row = $cb.closest( '.tsosk-plugin-row' );
			$cb.prop( 'checked', $row.data( 'normal' ) === 1 || $row.data( 'normal' ) === '1' || $row.data( 'normal' ) === true );
		} );
	} );

	// Helper buttons: Deselect All.
	$( document ).on( 'click', '#tsosk-sb-select-none', function () {
		$( '.tsosk-sandbox-cb' ).prop( 'checked', false );
	} );

	$( document ).on( 'click', '#tsosk-sandbox-apply', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_sandbox ) ) {
			return;
		}
		var $btn    = $( this );
		var nonce   = $btn.data( 'nonce' );
		var $msg    = $( '#tsosk-sandbox-msg' );
		var plugins = [];

		$( '.tsosk-sandbox-cb:checked' ).each( function () {
			plugins.push( $( this ).val() );
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sandbox_apply',
			data   : { nonce: nonce, plugins: plugins },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					// Reload after a short delay so the sandbox takes effect.
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.apply_sandbox );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.apply_sandbox );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-sandbox-reset', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-sandbox-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sandbox_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.reset_sandbox );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.reset_sandbox );
			}
		} );
	} );

	// ── Redirects ──────────────────────────────────────────────────────────

	function resetRedirectForm() {
		$( '#tsosk-redirect-id' ).val( '' );
		$( '#tsosk-redirect-source' ).val( '' );
		$( '#tsosk-redirect-target' ).val( '' );
		$( '#tsosk-redirect-match-type' ).val( 'exact' );
		$( '#tsosk-redirect-status' ).val( '301' );
		$( '#tsosk-redirect-enabled' ).prop( 'checked', true );
		$( '#tsosk-redirect-save' ).text( tsosk.i18n.save_redirect );
	}

	$( document ).on( 'click', '#tsosk-redirect-reset-form', function () {
		resetRedirectForm();
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
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
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

	$( document ).on( 'click', '.tsosk-404-create-redirect', function () {
		resetRedirectForm();
		$( '#tsosk-redirect-source' ).val( $( this ).data( 'source' ) );
		$( '#tsosk-redirect-target' ).focus();
		$( 'html, body' ).animate( { scrollTop: $( '#tsosk-redirect-source' ).offset().top - 80 }, 200 );
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

	// ── File Integrity ────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-fi-scan, #tsosk-fi-force-scan', function () {
		var $btn   = $( this );
		var force  = $btn.data( 'force' ) ? 1 : 0;
		var nonce  = $btn.data( 'nonce' );
		var $msg   = $( '#tsosk-fi-msg' );
		var $prog  = $( '#tsosk-fi-progress' );
		var $bar   = $( '#tsosk-fi-bar' );
		var $label = $( '#tsosk-fi-progress-label' );
		var $res   = $( '#tsosk-fi-results' );

		$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', true );
		$prog.show();
		$bar.css( 'width', '10%' );
		$label.text( tsosk.i18n.fi_connecting );

		var progInterval = setInterval( function () {
			var cur = parseFloat( $bar.css( 'width' ) ) / parseFloat( $bar.parent().css( 'width' ) ) * 100;
			if ( cur < 85 ) {
				$bar.css( 'width', Math.min( cur + 8, 85 ) + '%' );
			}
		}, 600 );

		$.ajax( {
			url    : tsosk.ajax_url,
			type   : 'POST',
			timeout: 120000,
			data   : { action: 'tsosk_fi_scan', nonce: nonce, force: force },
			success: function ( r ) {
				clearInterval( progInterval );
				$bar.css( 'width', '100%' );
				setTimeout( function () { $prog.fadeOut( 300 ); }, 600 );

				if ( r.success ) {
					$res.html( r.data.html || tsosk.i18n.fi_no_html );
					var issues = ( typeof r.data.n_issues === 'number' )
						? r.data.n_issues
						: ( ( r.data.modified || [] ).length + ( r.data.missing || [] ).length );
					if ( issues === 0 ) {
						showMsg( $msg, tsosk.i18n.fi_clean, 'ok' );
					} else {
						showMsg( $msg, issues + ' ' + tsosk.i18n.fi_issues, 'warn' );
					}
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', false );
			},
			error: function ( xhr ) {
				clearInterval( progInterval );
				$prog.fadeOut( 300 );
				var msg = ( xhr.statusText === 'timeout' )
					? tsosk.i18n.fi_timeout
					: tsosk.i18n.error;
				showMsg( $msg, msg, 'error' );
				$( '#tsosk-fi-scan, #tsosk-fi-force-scan' ).prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-fi-ignore', function () {
		var $btn  = $( this );
		var file  = $btn.data( 'file' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-fi-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_fi_ignore',
			data   : { nonce: nonce, file: file },
			success: function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_ignore );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_ignore );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-fi-unignore', function () {
		var $btn  = $( this );
		var file  = $btn.data( 'file' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-fi-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_fi_unignore',
			data   : { nonce: nonce, file: file },
			success: function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_remove );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.fi_remove );
			}
		} );
	} );

	// ── Security Review ────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-security-save', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-msg' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var data = { nonce: $btn.data( 'nonce' ) };

		$( '[id^="tsosk-sec-"]' ).each( function () {
			var key = 'tsosk_security_' + $( this ).data( 'const' );
			data[ key ] = $( this ).prop( 'checked' ) ? 1 : 0;
		} );

		data.tsosk_security_disable_xmlrpc = $( '#tsosk-sec-disable-xmlrpc' ).prop( 'checked' ) ? 1 : 0;
		data.tsosk_security_disable_feeds  = $( '#tsosk-sec-disable-feeds' ).prop( 'checked' ) ? 1 : 0;

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_save',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( saveLabel );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( saveLabel );
			}
		} );
	} );

	// ── Hidden WordPress Profiles ─────────────────────────────────────────
	var tsoskHpPresets = {
		performance: [
			'disable_wp_cron', 'concatenate_scripts', 'disable_emojis', 'disable_embeds'
		],
		content: [
			'limit_revisions', 'slow_autosave', 'empty_trash'
		],
		privacy: [
			'close_comments', 'disable_emojis', 'disable_embeds'
		]
	};

	$( document ).on( 'click', '[data-tsosk-hp-preset]', function () {
		var preset = $( this ).data( 'tsosk-hp-preset' );
		var fields = preset === 'clear' ? [] : ( tsoskHpPresets[ preset ] || [] );
		$( '[data-hp-field]' ).each( function () {
			var field = $( this ).data( 'hp-field' );
			var isRt  = field.indexOf( 'rt_' ) === 0;
			var key   = isRt ? field.slice( 3 ) : field;
			var match = false;
			fields.forEach( function ( f ) {
				if ( f === key || ( isRt && f === key ) || ( ! isRt && f === field ) ) {
					match = true;
				}
			} );
			if ( preset === 'clear' ) {
				if ( $( this ).is( ':checkbox' ) ) {
					$( this ).prop( 'checked', false );
				}
			} else if ( $( this ).is( ':checkbox' ) ) {
				$( this ).prop( 'checked', match );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-hp-save', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-hp-msg' );
		var $panel = $( '#tsosk-hp-panel' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var data = { nonce: $btn.data( 'nonce' ) };

		$panel.find( 'input[name^="tsosk_hp_"]' ).each( function () {
			var $el = $( this );
			var name = $el.attr( 'name' );
			if ( ! name ) {
				return;
			}
			if ( 'checkbox' === $el.attr( 'type' ) ) {
				data[ name ] = $el.prop( 'checked' ) ? 1 : 0;
			} else if ( 'number' === $el.attr( 'type' ) ) {
				data[ name ] = $el.val();
			}
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_hidden_profiles_save',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( saveLabel );
				}
			},
			error: function ( xhr ) {
				var errMsg = tsosk.i18n.error;
				if ( xhr && xhr.responseText === '0' ) {
					errMsg = tsosk.i18n.error + ' (session expired — reload the page and try again)';
				}
				showMsg( $msg, errMsg, 'error' );
				$btn.prop( 'disabled', false ).text( saveLabel );
			}
		} );
	} );


	// ── Login Protection ──────────────────────────────────────────────────

	// Save settings
	$( document ).on( 'click', '#tsosk-lp-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-lp-msg' );
		var nonce = $btn.data( 'nonce' );
		var data  = {
			nonce           : nonce,
			custom_url      : $( '#tsosk-lp-custom-url' ).prop( 'checked' ) ? 1 : 0,
			login_slug      : $( '#tsosk-lp-slug' ).val(),
			brute_force     : $( '#tsosk-lp-brute' ).prop( 'checked' ) ? 1 : 0,
			block_forbidden_usernames: $( '#tsosk-lp-block-forbidden' ).prop( 'checked' ) ? 1 : 0,
			max_attempts    : $( '#tsosk-lp-max' ).val(),
			lockout_window  : $( '#tsosk-lp-window' ).val(),
			lockout_duration: $( '#tsosk-lp-duration' ).val(),
			notify_email    : $( '#tsosk-lp-notify' ).prop( 'checked' ) ? 1 : 0,
			notify_address  : $( '#tsosk-lp-notify-addr' ).val(),
			whitelist_ips   : $( '#tsosk-lp-whitelist' ).val(),
			login_maintenance: $( '#tsosk-lp-maintenance' ).prop( 'checked' ) ? 1 : 0,
			login_maintenance_ips: $( '#tsosk-lp-maintenance-ips' ).val(),
			email_2fa       : $( '#tsosk-lp-2fa' ).prop( 'checked' ) ? 1 : 0,
			role_whitelist_ips: $( '#tsosk-lp-role-ips' ).val(),
			notify_mass_threshold: $( '#tsosk-lp-mass-threshold' ).val(),
			notify_mass_window: $( '#tsosk-lp-mass-window' ).val()
		};
		data.email_2fa_roles = [];
		$( 'input[name="email_2fa_roles[]"]:checked' ).each( function () {
			data.email_2fa_roles.push( $( this ).val() );
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_save',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			}
		} );
	} );

	// ── Comment Anti-Spam ─────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-cas-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-cas-msg' );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_cas_save',
			data   : {
				nonce                   : nonce,
				enabled                 : $( '#tsosk-cas-enabled' ).prop( 'checked' ) ? 1 : 0,
				protect_comments        : $( '#tsosk-cas-protect-comments' ).prop( 'checked' ) ? 1 : 0,
				protect_contact_forms   : $( '#tsosk-cas-protect-forms' ).prop( 'checked' ) ? 1 : 0,
				honeypot                : $( '#tsosk-cas-honeypot' ).prop( 'checked' ) ? 1 : 0,
				time_trap               : $( '#tsosk-cas-time-trap' ).prop( 'checked' ) ? 1 : 0,
				rate_limit              : $( '#tsosk-cas-rate-limit' ).prop( 'checked' ) ? 1 : 0,
				block_disposable_email  : $( '#tsosk-cas-disposable' ).prop( 'checked' ) ? 1 : 0,
				duplicate_check         : $( '#tsosk-cas-duplicate' ).prop( 'checked' ) ? 1 : 0,
				block_cyrillic          : $( '#tsosk-cas-cyrillic' ).prop( 'checked' ) ? 1 : 0,
				skip_logged_in          : $( '#tsosk-cas-skip-logged-in' ).prop( 'checked' ) ? 1 : 0,
				log_blocks              : $( '#tsosk-cas-log-blocks' ).prop( 'checked' ) ? 1 : 0,
				min_submit_seconds      : $( '#tsosk-cas-min-seconds' ).val(),
				max_submit_seconds      : $( '#tsosk-cas-max-seconds' ).val(),
				rate_limit_count        : $( '#tsosk-cas-rate-count' ).val(),
				rate_limit_window       : $( '#tsosk-cas-rate-window' ).val(),
				max_links               : $( '#tsosk-cas-max-links' ).val(),
				duplicate_window        : $( '#tsosk-cas-dup-window' ).val(),
				spam_action             : $( '#tsosk-cas-spam-action' ).val(),
				cloud_mode              : $( '#tsosk-cas-cloud-mode' ).val(),
				cleantalk_key           : $( '#tsosk-cas-cleantalk-key' ).val(),
				stopforumspam_enabled   : $( '#tsosk-cas-sfs' ).prop( 'checked' ) ? 1 : 0,
				sfs_min_confidence      : $( '#tsosk-cas-sfs-confidence' ).val(),
				abuseipdb_enabled       : $( '#tsosk-cas-abuseipdb' ).prop( 'checked' ) ? 1 : 0,
				abuseipdb_key           : $( '#tsosk-cas-abuseipdb-key' ).val(),
				abuseipdb_min_score     : $( '#tsosk-cas-abuseipdb-score' ).val(),
				abuseipdb_max_age_days  : $( '#tsosk-cas-abuseipdb-age' ).val(),
				honeypot_httpbl_enabled : $( '#tsosk-cas-httpbl' ).prop( 'checked' ) ? 1 : 0,
				honeypot_access_key     : $( '#tsosk-cas-httpbl-key' ).val(),
				honeypot_min_threat     : $( '#tsosk-cas-httpbl-threat' ).val(),
				block_keywords          : $( '#tsosk-cas-keywords' ).val(),
				block_urls              : $( '#tsosk-cas-urls' ).val(),
				custom_disposable_domains: $( '#tsosk-cas-domains' ).val(),
				whitelist_ips           : $( '#tsosk-cas-whitelist' ).val()
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-cas-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_cas_clear_log',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					window.location.reload();
				} else {
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// Generate slug
	$( document ).on( 'click', '#tsosk-lp-generate', function () {
		var $btn  = $( this );
		var nonce = $( '#tsosk-lp-save' ).data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_lp_generate_slug',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success && r.data && r.data.slug ) {
					$( '#tsosk-lp-slug' ).val( r.data.slug ).trigger( 'focus' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () { $btn.prop( 'disabled', false ); }
		} );
	} );

	// Add my IP to whitelist
	$( document ).on( 'click', '#tsosk-lp-add-my-ip', function () {
		var ip      = $( this ).data( 'ip' );
		var $area   = $( '#tsosk-lp-whitelist' );
		var current = $area.val().trim();
		$area.val( current ? current + '\n' + ip : ip );
		$( this ).prop( 'disabled', true );
	} );

	// Unlock specific IP
	$( document ).on( 'click', '.tsosk-lp-unlock', function () {
		var $btn  = $( this );
		var ip    = $btn.data( 'ip' );
		var idx   = $btn.data( 'idx' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-lp-log-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_unlock',
			data   : { nonce: nonce, ip: ip, idx: idx },
			success: function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).find( '.tsosk-badge-warn' ).first()
						.removeClass( 'tsosk-badge-warn' )
						.text( tsosk.i18n.expired || 'Expired' );
					$btn.closest( 'tr' ).removeClass( 'tsosk-row-warn' );
					$btn.closest( 'td' ).html( '<span style="color:#ccc">&mdash;</span>' );
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_unlock );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_unlock );
			}
		} );
	} );

	// Unlock all
	$( document ).on( 'click', '#tsosk-lp-unlock-all', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-lp-log-msg' );

		if ( ! confirm( tsosk.i18n.lp_unlock_all_confirm ) ) { return; }

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_unlock_all',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					$( '#tsosk-lp-log-table .tsosk-badge-warn' ).each( function () {
						$( this ).removeClass( 'tsosk-badge-warn' ).text( tsosk.i18n.expired || 'Expired' );
					} );
					$( '#tsosk-lp-log-table tr.tsosk-row-warn' ).removeClass( 'tsosk-row-warn' );
					$( '.tsosk-lp-unlock' ).closest( 'td' ).html( '<span style="color:#ccc">&mdash;</span>' );
					$btn.fadeOut( 300 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_unlock_all );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_unlock_all );
			}
		} );
	} );

	// Clear log
	$( document ).on( 'click', '#tsosk-lp-clear-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-lp-log-msg' );

		if ( ! confirm( tsosk.i18n.lp_clear_log_confirm ) ) { return; }

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_clear_log',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					$( '#tsosk-lp-log-table tbody' ).empty();
					$btn.fadeOut( 300 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_clear_log );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_clear_log );
			}
		} );
	} );

	// Reset attempt counter for IP
	$( document ).on( 'click', '.tsosk-lp-reset-counter', function () {
		var $btn  = $( this );
		var ip    = $btn.data( 'ip' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-lp-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_unlock',
			data   : { nonce: nonce, ip: ip, reset_attempts_only: 1 },
			success: function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_reset );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.lp_reset );
			}
		} );
	} );


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
			var typeColor   = { serialized: '#7c3aed', json: '#0369a1', integer: '#0f766e', text: '#374151' };
			var tc          = typeColor[ item.type ] || '#374151';
			var alColor     = item.autoload === 'yes' || item.autoload === 'on' ? '#d97706' : '#6b7280';

			var badge_al    = '<span style="background:' + ( item.autoload === 'yes' || item.autoload === 'on' ? '#fef3c7' : '#f3f4f6' ) + ';color:' + alColor + ';padding:2px 7px;border-radius:10px;font-size:11px;">' + item.autoload + '</span>';
			var badge_type  = '<span style="background:#f3f4f6;color:' + tc + ';padding:2px 7px;border-radius:10px;font-size:11px;">' + item.type + '</span>';

			var actions;
			if ( isProtected ) {
				actions = '<button class="button button-small tsosk-oe-edit-btn" data-name="' + item.name + '" data-protected="1" data-caution="0" data-nonce="' + nonce + '">' + tsosk.i18n.oe_view + '</button>';
			} else {
				actions = '<button class="button button-small tsosk-oe-edit-btn" data-name="' + item.name + '" data-protected="0" data-caution="' + ( isCaution ? 1 : 0 ) + '" data-nonce="' + nonce + '">' + tsosk.i18n.oe_edit + '</button> ';
				actions += '<button class="button button-small button-link-delete tsosk-oe-delete-btn" data-name="' + item.name + '" data-nonce="' + nonce + '">' + tsosk.i18n.oe_delete + '</button>';
			}

			var $tr = $( '<tr>' + ( isProtected ? ' class="tsosk-oe-protected"' : '' ) + '>' );
			var nameCell = $( '<td style="word-break:break-all;font-family:monospace;font-size:12px;color:#1d2327;">' );
			nameCell.append( $( '<span>' ).text( item.name ) );
			if ( isProtected ) {
				nameCell.append( ' ' ).append( $( '<span class="tsosk-badge tsosk-badge-warn" style="font-size:10px;vertical-align:middle;">' ).text( tsosk.i18n.oe_protected ) );
			}
			$tr.append( nameCell );
			$tr.append( $( '<td style="font-size:12px;color:#374151;">' ).text( sizeStr ) );
			$tr.append( $( '<td>' ).html( badge_al ) );
			$tr.append( $( '<td>' ).html( badge_type ) );
			$tr.append( $( '<td style="font-size:12px;color:#374151;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' ).text( item.preview ) );
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
		$( '#tsosk-oe-editing-autoload' ).val( ( data.autoload === 'yes' || data.autoload === 'on' ) ? 'yes' : 'no' );
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


	// ── Slug Manager ──────────────────────────────────────────────────────

	var tsosk_sm = {
		lastSearch  : '',
		currentPage : 1,
		totalPages  : 1,
		bulkPreview : null,
	};

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
		var color = len > 50 ? '#b45309' : ( len > 30 ? '#646970' : '#2271b1' );
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
					showMsg( $msg, r.data.message, 'ok' );
					// Update the row in the table.
					var $row = $( '#tsosk-sm-row-' + postId );
					$row.find( '.tsosk-code a' ).text( r.data.new_slug ).attr( 'href', r.data.new_permalink );
					$row.find( 'td:nth-last-child(2) span' ).text( r.data.new_slug.length );
					$row.removeClass( 'tsosk-row-warn' );
					$( '#tsosk-sm-rename-current' ).text( r.data.new_slug );
					$( '#tsosk-sm-rename-len' ).text( r.data.new_slug.length );
					// Also update search results row if present
					$( '#tsosk-sm-tr-' + postId + ' .tsosk-sm-slug-cell' ).text( r.data.new_slug );
					$( '#tsosk-sm-tr-' + postId + ' .tsosk-sm-len-cell' ).text( r.data.new_slug.length );
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
		var threshold = parseInt( $( '#tsosk-sm-threshold' ).val(), 10 ) || 50;

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

	function tsosk_sm_search( q, page ) {
		tsosk_sm.lastSearch  = q;
		tsosk_sm.currentPage = page || 1;
		var nonce     = $( '#tsosk-sm-search-btn' ).data( 'nonce' );
		var $msg      = $( '#tsosk-sm-search-msg' );
		var postType  = $( '#tsosk-sm-post-type' ).val();

		$( '#tsosk-sm-search-btn, #tsosk-sm-load-all' ).prop( 'disabled', true );
		$( '#tsosk-sm-search-placeholder' ).hide();

		ajaxPost( {
			action : 'tsosk_sm_search',
			data   : { nonce: nonce, q: q, post_type: postType, page: tsosk_sm.currentPage },
			success: function ( r ) {
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

		$.each( items, function ( i, item ) {
			var lenColor = item.len > 50 ? ' style="font-weight:600;color:#b45309;"' : '';
			var $tr = $( '<tr id="tsosk-sm-tr-' + item.id + '"></tr>' );
			var $titleCell = $( '<td></td>' );
			$titleCell.append( $( '<strong></strong>' ).text( item.title || tsosk.i18n.no_title ) );
			if ( item.edit_link ) {
				$titleCell.append( ' ' ).append(
					$( '<a target="_blank" rel="noopener noreferrer" class="tsosk-sm-edit-link"></a>' )
						.attr( 'href', item.edit_link )
						.text( tsosk.i18n.edit + ' ↗' )
				);
			}
			$tr.append( $titleCell );
			$tr.append( $( '<td class="tsosk-code" style="font-size:12px;"></td>' ).text( item.type ) );
			$tr.append( $( '<td></td>' ).text( item.status ) );
			var $slugLink = $( '<a target="_blank" rel="noopener noreferrer"></a>' )
				.attr( 'href', item.permalink )
				.attr( 'title', item.slug )
				.text( item.slug );
			$tr.append( $( '<td class="tsosk-code tsosk-sm-slug-cell tsosk-sm-slug-col"></td>' ).append( $slugLink ) );
			$tr.append( $( '<td class="tsosk-sm-len-cell"></td>' ).attr( 'style', item.len > 50 ? 'font-weight:600;color:#b45309;' : '' ).text( item.len ) );
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
			$tr.append( $( '<td></td>' ).append( $btn ) );
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


	// ── Mobile tools menu ─────────────────────────────────────────────────────

	function tsoskMobileNavQuery() {
		return window.matchMedia( '(max-width: 782px)' );
	}

	function tsoskSetMobileNavOpen( open ) {
		var $wrap = $( '.tsosk-wrap' );
		var $toggle = $( '#tsosk-mobile-nav-toggle' );
		$wrap.toggleClass( 'tsosk-mobile-nav-open', open );
		if ( $toggle.length ) {
			$toggle.attr( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	$( document ).on( 'click', '#tsosk-mobile-nav-toggle', function () {
		if ( ! tsoskMobileNavQuery().matches ) {
			return;
		}
		var open = ! $( '.tsosk-wrap' ).hasClass( 'tsosk-mobile-nav-open' );
		tsoskSetMobileNavOpen( open );
	} );

	$( document ).on( 'click', '.tsosk-sidebar-tools .tsosk-nav-item', function () {
		if ( tsoskMobileNavQuery().matches ) {
			tsoskSetMobileNavOpen( false );
		}
	} );

	$( window ).on( 'resize.tsoskMobileNav', function () {
		if ( ! tsoskMobileNavQuery().matches ) {
			tsoskSetMobileNavOpen( false );
		}
	} );


	// ══ Sidebar Organiser ════════════════════════════════════════════════════

	( function () {
		var $sidebars    = $( '#tsosk-sidebars' );
		var $sidebar     = $( '#tsosk-sidebar-nav' );
		var $favSidebar  = $( '#tsosk-sidebar-favorites' );
		var $favList     = $( '#tsosk-favorites-list' );
		var $navList     = $( '#tsosk-nav-list' );
		var $editBar     = $( '#tsosk-sidebar-edit-bar' );
		var $actionsBar  = $( '#tsosk-sidebar-actions' );
		var $btnOrganise = $( '#tsosk-sidebar-organise' );
		var $btnSave     = $( '#tsosk-sidebar-save' );
		var $btnReset    = $( '#tsosk-sidebar-reset' );
		var $btnCancel   = $( '#tsosk-sidebar-cancel' );

		if ( ! $sidebar.length ) { return; }

		var nonce            = $sidebar.data( 'nonce' ) || $favSidebar.data( 'nonce' );
		var originalListHtml = null;
		var originalFavHtml  = null;
		var groupLabels      = tsosk.sidebar_groups || {};
		var groupOrder       = tsosk.sidebar_group_order || Object.keys( groupLabels );

		function rowSlug( $row ) {
			return $row.attr( 'data-slug' ) || '';
		}

		function destroyDragReorder() {
			if ( dragState ) {
				finishDrag( null, true );
			}
			$navList.find( '.tsosk-nav-edit-section-list' ).off( '.tsoskNavReorder' );
			$favList.off( '.tsoskNavReorder' );
			$( document ).off( '.tsoskNavReorder' );
			$( window ).off( '.tsoskNavReorder' );
			$( 'body > .tsosk-nav-drag-ghost' ).remove();
		}

		var dragState = null;

		function pointInRect( x, y, rect ) {
			return x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
		}

		function sectionGroupForList( $list ) {
			return $list.closest( '.tsosk-nav-edit-section' ).attr( 'data-group' ) || 'site';
		}

		function findTargetSectionList( clientX, clientY ) {
			var $target = null;

			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				var rect = this.getBoundingClientRect();
				if ( pointInRect( clientX, clientY, rect ) ) {
					$target = $( this ).find( '.tsosk-nav-edit-section-list' ).first();
					return false;
				}
			} );

			return $target;
		}

		function applyRowSection( $row, $list ) {
			if ( ! $row.length || ! $list || ! $list.length ) {
				return;
			}
			$row.attr( 'data-group', sectionGroupForList( $list ) );
		}

		function insertRowAtClientY( $list, $row, clientY ) {
			if ( ! $list.length || ! $row.length ) {
				return;
			}

			var inserted = false;

			$list.children( '.tsosk-nav-row' ).each( function () {
				var rect = this.getBoundingClientRect();
				if ( clientY < rect.top + ( rect.height / 2 ) ) {
					$( this ).before( $row );
					inserted = true;
					return false;
				}
			} );

			if ( ! inserted ) {
				$list.append( $row );
			}
		}

		function restoreRowOrigin( state ) {
			if ( ! state.$row || ! state.$row.length || ! state.$sourceList.length ) {
				return;
			}

			if ( state.originNext && document.contains( state.originNext ) ) {
				$( state.originNext ).before( state.$row );
				return;
			}

			state.$sourceList.append( state.$row );
		}

		function movePlaceholder( $list, clientY ) {
			if ( ! dragState || ! dragState.$placeholder ) {
				return;
			}
			var $placeholder = dragState.$placeholder;
			var $rows        = $list.children( '.tsosk-nav-row' );
			var inserted     = false;

			$rows.each( function () {
				var rect = this.getBoundingClientRect();
				var mid  = rect.top + ( rect.height / 2 );
				if ( clientY < mid ) {
					$( this ).before( $placeholder );
					inserted = true;
					return false;
				}
			} );

			if ( ! inserted ) {
				$list.append( $placeholder );
			}
		}

		function finishDrag( e, cancel ) {
			if ( ! dragState ) {
				return;
			}

			var state        = dragState;
			var clientY      = ( e && typeof e.clientY === 'number' ) ? e.clientY : state.lastClientY;
			var droppedOnFav = false;

			dragState = null;

			$( document ).off( '.tsoskNavReorder' );
			$( window ).off( '.tsoskNavReorder' );
			$navList.find( '.tsosk-nav-edit-section-list' ).removeClass( 'is-drop-hover' );
			$sidebars.removeClass( 'tsosk-nav-dragging' );
			$favList.removeClass( 'is-drop-target' );

			if ( state.$ghost && state.$ghost.length ) {
				state.$ghost.remove();
			}

			if ( state.$placeholder && state.$placeholder.length ) {
				state.$placeholder.remove();
			}

			if ( cancel || ! state.$row || ! state.$row.length ) {
				restoreRowOrigin( state );
				return;
			}

			if ( ! state.fromFavorites && $favList.length && typeof clientY === 'number' ) {
				droppedOnFav = pointInRect(
					e ? e.clientX : state.lastClientX,
					clientY,
					$favList[0].getBoundingClientRect()
				);
			}

			if ( state.fromFavorites ) {
				insertRowAtClientY( $favList, state.$row, clientY );
			} else {
				var $targetList = state.$targetList && state.$targetList.length
					? state.$targetList
					: state.$sourceList;

				if ( typeof clientY === 'number' ) {
					movePlaceholder( $targetList, clientY );
				}

				insertRowAtClientY( $targetList, state.$row, clientY );
				applyRowSection( state.$row, $targetList );
			}

			if ( droppedOnFav ) {
				addRowToFavorites( state.$row );
			}
		}

		function onDragMove( e ) {
			if ( ! dragState ) {
				return;
			}
			e.preventDefault();

			dragState.lastClientY = e.clientY;
			dragState.lastClientX = e.clientX;
			dragState.$ghost.css( 'top', ( e.clientY - dragState.offsetY ) + 'px' );

			if ( dragState.fromFavorites ) {
				movePlaceholder( $favList, e.clientY );
				return;
			}

			var $targetList = findTargetSectionList( e.clientX, e.clientY ) || dragState.$sourceList;
			dragState.$targetList = $targetList;
			$navList.find( '.tsosk-nav-edit-section-list' ).removeClass( 'is-drop-hover' );
			$targetList.addClass( 'is-drop-hover' );
			movePlaceholder( $targetList, e.clientY );

			if ( $favList.length ) {
				$favList.toggleClass(
					'is-drop-target',
					pointInRect( e.clientX, e.clientY, $favList[0].getBoundingClientRect() )
				);
			}
		}

		function onDragEnd( e ) {
			if ( ! dragState ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			finishDrag( e, false );
		}

		function startRowDrag( $list, $row, e, fromFavorites ) {
			if ( dragState ) {
				finishDrag( null, true );
			}

			e.preventDefault();
			e.stopPropagation();

			var rect         = $row[0].getBoundingClientRect();
			var originNext   = $row.next( '.tsosk-nav-row' )[0] || null;
			var $placeholder = $( '<div class="tsosk-sortable-placeholder" aria-hidden="true"></div>' );
			$placeholder.height( $row.outerHeight() );
			$row.before( $placeholder );
			$row.detach();

			var $ghost = $row.clone();
			$ghost.addClass( 'tsosk-nav-drag-ghost' );
			$ghost.find( '.tsosk-add-fav' ).remove();
			$( 'body' ).append( $ghost );
			$ghost.css( {
				position      : 'fixed',
				left          : rect.left + 'px',
				top           : rect.top + 'px',
				width         : rect.width + 'px',
				zIndex        : 100100,
				pointerEvents : 'none',
				margin        : 0
			} );

			dragState = {
				$row         : $row,
				$sourceList  : $list,
				$targetList  : $list,
				$placeholder : $placeholder,
				$ghost       : $ghost,
				offsetY      : e.clientY - rect.top,
				lastClientY  : e.clientY,
				lastClientX  : e.clientX,
				originNext   : originNext,
				fromFavorites: !! fromFavorites
			};

			$sidebars.addClass( 'tsosk-nav-dragging' );
			$favList.addClass( 'is-drop-target' );

			$( document )
				.on( 'pointermove.tsoskNavReorder mousemove.tsoskNavReorder touchmove.tsoskNavReorder', onDragMove );
			$( window )
				.on( 'pointerup.tsoskNavReorder pointercancel.tsoskNavReorder mouseup.tsoskNavReorder touchend.tsoskNavReorder', onDragEnd );
		}

		function initDragReorder() {
			$navList.find( '.tsosk-nav-edit-section-list' ).each( function () {
				var $list = $( this );
				$list.on( 'pointerdown.tsoskNavReorder', '.tsosk-drag-handle', function ( e ) {
					if ( ! $sidebars.hasClass( 'tsosk-editing' ) ) {
						return;
					}
					if ( 'mouse' === e.pointerType && 0 !== e.button ) {
						return;
					}
					var $row = $( this ).closest( '.tsosk-nav-row' );
					if ( ! $row.length ) {
						return;
					}
					startRowDrag( $list, $row, e, false );
				} );
			} );

			if ( ! $favList.length ) {
				return;
			}

			$favList.on( 'pointerdown.tsoskNavReorder', '.tsosk-drag-handle', function ( e ) {
				if ( ! $favSidebar.hasClass( 'tsosk-editing' ) ) {
					return;
				}
				if ( 'mouse' === e.pointerType && 0 !== e.button ) {
					return;
				}
				var $row = $( this ).closest( '.tsosk-nav-row' );
				if ( ! $row.length ) {
					return;
				}
				startRowDrag( $favList, $row, e, true );
			} );
		}

		function dedupeFavorite( $row ) {
			var slug = rowSlug( $row );
			if ( ! slug ) {
				$row.remove();
				return false;
			}
			var dupes = 0;
			$favList.find( '.tsosk-nav-row' ).each( function () {
				if ( rowSlug( $( this ) ) === slug ) {
					dupes += 1;
				}
			} );
			if ( dupes > 1 ) {
				$row.remove();
				return false;
			}
			$row.addClass( 'tsosk-fav-row' );
			$row.find( '.tsosk-hide-tab' ).remove();
			if ( ! $row.find( '.tsosk-remove-fav' ).length ) {
				$row.append(
					$( '<button type="button" class="tsosk-remove-fav"></button>' )
						.attr( 'title', tsosk.i18n.favorites_remove )
						.text( '✕' )
				);
			}
			updateFavoritesEmptyState();
			return true;
		}

		function updateFavoritesEmptyState() {
			var hasRows = $favList.find( '.tsosk-nav-row' ).length > 0;
			$favList.toggleClass( 'is-empty', ! hasRows );
		}

		function isFavoriteSlug( slug ) {
			var found = false;
			$favList.find( '.tsosk-nav-row' ).each( function () {
				if ( rowSlug( $( this ) ) === slug ) {
					found = true;
					return false;
				}
			} );
			return found;
		}

		function addRowToFavorites( $sourceRow ) {
			var slug = rowSlug( $sourceRow );
			if ( ! slug || isFavoriteSlug( slug ) ) {
				return false;
			}
			var $clone = $sourceRow.clone();
			$clone.removeClass( 'tsosk-dragging tsosk-drop-before tsosk-drop-after ui-sortable-helper' );
			$clone.find( '.tsosk-add-fav' ).remove();
			$favList.append( $clone );
			dedupeFavorite( $clone );
			return true;
		}

		function attachAddFavoriteButtons() {
			$navList.find( '.tsosk-nav-edit-section-list .tsosk-nav-row' ).each( function () {
				var $row = $( this );
				if ( $row.find( '.tsosk-add-fav' ).length ) {
					return;
				}
				$row.find( '.tsosk-hide-tab' ).before(
					$( '<button type="button" class="tsosk-add-fav" aria-label="★"></button>' )
						.attr( 'title', tsosk.i18n.favorites_add || 'Add to favorites' )
						.append( $( '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>' ) )
				);
			} );
		}

		/**
		 * Rebuild nav list into category blocks (all groups shown, including empty drop zones).
		 */
		function buildEditSections() {
			var buckets = {};

			groupOrder.forEach( function ( gid ) {
				buckets[ gid ] = [];
			} );

			$navList.find( '.tsosk-nav-row' ).each( function () {
				var $row = $( this );
				var gid  = $row.attr( 'data-group' ) || 'site';
				if ( ! buckets[ gid ] ) {
					buckets[ gid ] = [];
				}
				buckets[ gid ].push( $row );
			} );

			$navList.empty();

			groupOrder.forEach( function ( gid ) {
				var title = groupLabels[ gid ] || gid;
				var $sec  = $( '<div class="tsosk-nav-edit-section"></div>' ).attr( 'data-group', gid );
				$sec.append( $( '<div class="tsosk-nav-edit-section-title"></div>' ).text( title ) );
				var $list = $( '<div class="tsosk-nav-edit-section-list"></div>' );
				( buckets[ gid ] || [] ).forEach( function ( $row ) {
					$list.append( $row );
				} );
				$sec.append( $list );
				$navList.append( $sec );
			} );

			Object.keys( buckets ).forEach( function ( gid ) {
				if ( -1 !== groupOrder.indexOf( gid ) ) {
					return;
				}
				var title = groupLabels[ gid ] || gid;
				var $sec  = $( '<div class="tsosk-nav-edit-section"></div>' ).attr( 'data-group', gid );
				$sec.append( $( '<div class="tsosk-nav-edit-section-title"></div>' ).text( title ) );
				var $list = $( '<div class="tsosk-nav-edit-section-list"></div>' );
				buckets[ gid ].forEach( function ( $row ) {
					$list.append( $row );
				} );
				$sec.append( $list );
				$navList.append( $sec );
			} );
		}

		function getTabGroups() {
			var groups = {};

			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				var groupId = $( this ).attr( 'data-group' ) || 'site';
				$( this ).find( '.tsosk-nav-row' ).each( function () {
					var slug = rowSlug( $( this ) );
					if ( slug ) {
						groups[ slug ] = groupId;
					}
				} );
			} );

			return groups;
		}

		/* ── snapshot of current order ─────────────────────────────── */
		function getOrder() {
			var order = [];
			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				$( this ).find( '.tsosk-nav-row' ).each( function () {
					var slug = rowSlug( $( this ) );
					if ( slug ) {
						order.push( slug );
					}
				} );
			} );
			if ( order.length ) {
				return order;
			}
			return $navList.find( '.tsosk-nav-row' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}
		function getHidden() {
			return $navList.find( '.tsosk-nav-row.tsosk-nav-hidden' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}

		function getFavorites() {
			return $favList.find( '.tsosk-nav-row' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}

		/* ── Enter edit mode ────────────────────────────────────────── */
		$btnOrganise.on( 'click', function () {
			originalListHtml = $navList.html();
			originalFavHtml  = $favList.html();
			$sidebars.addClass( 'tsosk-editing' );
			$favSidebar.addClass( 'tsosk-editing' );
			$actionsBar.hide();
			$editBar.show();
			buildEditSections();
			attachAddFavoriteButtons();
			initDragReorder();
			updateFavoritesEmptyState();
		} );

		$( document ).on( 'click', '.tsosk-add-fav', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! $sidebars.hasClass( 'tsosk-editing' ) ) {
				return;
			}
			addRowToFavorites( $( this ).closest( '.tsosk-nav-row' ) );
		} );

		/* ── Cancel edit mode ───────────────────────────────────────── */
		$btnCancel.on( 'click', function () {
			exitEditMode( false );
		} );

		function exitEditMode( keepChanges ) {
			destroyDragReorder();
			if ( ! keepChanges ) {
				if ( null !== originalListHtml ) {
					$navList.html( originalListHtml );
				}
				if ( null !== originalFavHtml ) {
					$favList.html( originalFavHtml );
				}
			}
			$sidebars.removeClass( 'tsosk-editing' );
			$favSidebar.removeClass( 'tsosk-editing' );
			$editBar.hide();
			$actionsBar.show();
			originalListHtml = null;
			originalFavHtml  = null;
			updateFavoritesEmptyState();
		}

		/* ── Save ───────────────────────────────────────────────────── */
		$btnSave.on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( '…' );

			ajaxPost( {
				action : 'tsosk_save_sidebar_order',
				data   : {
					nonce         : nonce,
					order_json    : JSON.stringify( getOrder() ),
					groups_json   : JSON.stringify( getTabGroups() ),
					hidden_json   : JSON.stringify( getHidden() ),
					favorites_json: JSON.stringify( getFavorites() )
				},
				success: function ( r ) {
					if ( r.success ) {
						// Reload so PHP rebuilds grouped sidebar from saved flat order.
						window.location.reload();
						return;
					} else {
						alert( ( r.data || tsosk.i18n.error ) );
					}
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sidebar_save );
				},
				error: function () {
					alert( tsosk.i18n.error );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sidebar_save );
				}
			} );
		} );

		/* ── Reset to default ───────────────────────────────────────── */
		$btnReset.on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			ajaxPost( {
				action : 'tsosk_reset_sidebar_order',
				data   : { nonce: nonce },
				success: function ( r ) {
					if ( r.success ) {
						window.location.reload();
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

		/* ── Remove from favorites ──────────────────────────────────── */
		$( document ).on( 'click', '.tsosk-remove-fav', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! $favSidebar.hasClass( 'tsosk-editing' ) ) {
				return;
			}
			$( this ).closest( '.tsosk-nav-row' ).remove();
			updateFavoritesEmptyState();
		} );

		/* ── Hide / show tab toggle ─────────────────────────────────── */
		$( document ).on( 'click', '.tsosk-hide-tab', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $row    = $( this ).closest( '.tsosk-nav-row' );
			var hidden  = $row.hasClass( 'tsosk-nav-hidden' );
			if ( hidden ) {
				$row.removeClass( 'tsosk-nav-hidden' );
				$( this ).attr( 'title', tsosk.i18n.sidebar_hide ).text( '✕' );
			} else {
				$row.addClass( 'tsosk-nav-hidden' );
				$( this ).attr( 'title', tsosk.i18n.sidebar_show ).text( '＋' );
			}
		} );

		updateFavoritesEmptyState();

	} )();


	// ── Media Footprint & Image Sizes Audit ───────────────────────────────────

	$( document ).on( 'click', '#tsosk-media-footprint-scan', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-media-footprint-msg' );
		var $out = $( '#tsosk-media-footprint-results' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );

		ajaxPost( {
			action : 'tsosk_media_footprint_scan',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success && r.data && r.data.html ) {
					$out.html( r.data.html );
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.media_footprint_scan || 'Scan uploads folder' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.media_footprint_scan || 'Scan uploads folder' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-image-sizes-scan', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-image-sizes-scan-msg' );
		var $out = $( '#tsosk-image-sizes-results' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );

		ajaxPost( {
			action : 'tsosk_image_sizes_audit_scan',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success && r.data && r.data.html ) {
					$out.html( r.data.html );
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_scan || 'Run image sizes audit' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_scan || 'Run image sizes audit' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-image-sizes-save', function () {
		var $btn      = $( this );
		var $msg      = $( '#tsosk-image-sizes-save-msg' );
		var disabled  = [];

		$( '.tsosk-img-audit-size-toggle:not(:checked)' ).each( function () {
			var name = $( this ).data( 'name' );
			if ( name ) {
				disabled.push( name );
			}
		} );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_image_sizes_audit_save',
			data   : { nonce: $btn.data( 'nonce' ), disabled: disabled },
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_save || 'Save image size settings' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_save || 'Save image size settings' );
			}
		} );
	} );

	// ── Server Files ──────────────────────────────────────────────────────

	$( document ).on( 'click', '.tsosk-sf-save', function () {
		var $btn    = $( this );
		var key     = $btn.data( 'key' );
		var nonce   = $btn.data( 'nonce' );
		var content = $( '#tsosk-sf-content-' + key ).val();
		var $msg    = $( '.tsosk-sf-msg-' + key );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_sf_save',
			data   : { nonce: nonce, key: key, content: content },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false ).text( tsosk.i18n.save );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );


	// ── Slow Query Monitor ────────────────────────────────────────────────────

	var tsosk_sq = { page: 1, total_pages: 1, lastSearch: '' };

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
				ignore_patterns : $( '#tsosk-sq-ignore-patterns' ).val() || ''
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message, r.data.warn_savequeries ? 'warn' : 'ok' );
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
		var idx   = $btn.data( 'idx' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-sq-log-msg' );
		if ( ! confirm( tsosk.i18n.sq_delete_confirm ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_sq_delete_entry',
			data   : { nonce: nonce, idx: idx },
			success: function ( r ) {
				if ( r.success ) {
					$( '#tsosk-sq-batch-' + idx ).fadeOut( 300, function () { $( this ).remove(); } );
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
			var worstColor = maxTime > 500 ? '#d63638' : ( maxTime > 200 ? '#d97706' : '#374151' );
			var badgeCls   = batch.slow_count > 5 ? 'tsosk-badge-warn' : 'tsosk-badge-info';

			var $batch = $( '<div class="tsosk-sq-batch" id="tsosk-sq-batch-' + batch.idx + '"></div>' );

			var headerHtml = '<div class="tsosk-sq-batch-header" data-idx="' + batch.idx + '">'
				+ '<span class="tsosk-badge ' + badgeCls + '" style="font-size:11px;flex-shrink:0;">' + batch.slow_count + ' ' + tsosk.i18n.sq_slow + '</span>'
				+ '<span class="tsosk-sq-batch-url">' + $( '<span>' ).text( batch.url || '—' ).html() + '</span>'
				+ '<span style="font-size:11px;color:#646970;white-space:nowrap;">' + new Date( batch.ts * 1000 ).toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + ' UTC</span>'
				+ ( batch.load_ms > 0 ? '<span style="font-size:11px;color:#8c8f94;white-space:nowrap;">Page: ' + parseFloat( batch.load_ms ).toFixed(1) + ' ms</span>' : '' )
				+ '<span style="font-size:11px;font-weight:700;color:' + worstColor + ';white-space:nowrap;">Worst: ' + maxTime.toFixed(2) + ' ms</span>'
				+ '<button class="button button-small tsosk-sq-delete-batch" style="margin-left:auto;" data-idx="' + batch.idx + '" data-nonce="' + nonce + '">' + tsosk.i18n.delete + '</button>'
				+ '<span class="tsosk-sq-toggle-icon" style="font-size:12px;color:#646970;">▼</span>'
				+ '</div>';

			var bodyHtml = '<div class="tsosk-sq-batch-body">';
			$.each( batch.queries || [], function ( j, q ) {
				var t  = parseFloat( q.time );
				var tc = t > 500 ? '#d63638' : ( t > 200 ? '#d97706' : '#374151' );
				var kw = ( q.sql || '' ).split( ' ' )[0].toUpperCase();
				var kwColors = { SELECT:'#2271b1', INSERT:'#16a34a', UPDATE:'#d97706', DELETE:'#d63638', CREATE:'#7c3aed', DROP:'#d63638' };
				var kwc = kwColors[kw] || '#374151';
				bodyHtml += '<div class="tsosk-sq-query-row">'
					+ '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">'
					+ '<span style="font-size:12px;font-weight:700;color:' + tc + ';">' + t.toFixed(3) + ' ms</span>'
					+ '<span class="tsosk-badge" style="font-size:10px;background:' + kwc + '20;color:' + kwc + ';">' + $('<span>').text(kw).html() + '</span>'
					+ '<span style="font-size:11px;color:#8c8f94;">#' + (j+1) + '</span>'
					+ '</div>'
					+ '<div class="tsosk-sq-query-sql">' + $('<span>').text(q.sql || '').html() + '</div>'
					+ ( q.caller ? '<div class="tsosk-sq-query-caller"><span style="color:#8c8f94;">↳</span> ' + $('<span>').text(q.caller).html() + '</div>' : '' )
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
			var sql   = String( $row.data( 'sql' ) || $row.attr( 'data-sql' ) || '' ).toLowerCase();
			var isDu  = String( $row.data( 'dupe' ) || $row.attr( 'data-dupe' ) ) === '1';
			var isSl  = String( $row.data( 'slow' ) || $row.attr( 'data-slow' ) ) === '1';
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


	// ── Search & Replace ───────────────────────────────────────────────────────

	var tsosk_sr_preview_data = null; // Cached preview results.

	function tsosk_sr_invalidate_preview() {
		tsosk_sr_preview_data = null;
		$( '#tsosk-sr-execute-btn, #tsosk-sr-cancel-btn' ).hide();
		$( '.tsosk-sr-execute-hint' ).hide();
	}

	$( document ).on( 'input change', '#tsosk-sr-search, #tsosk-sr-replace, #tsosk-sr-case, #tsosk-sr-regex', tsosk_sr_invalidate_preview );
	$( document ).on( 'change', '.tsosk-sr-table-cb', tsosk_sr_invalidate_preview );

	// Table selection helpers
	$( document ).on( 'click', '#tsosk-sr-select-wp', function () {
		$( '.tsosk-sr-table-cb' ).each( function () {
			$( this ).prop( 'checked', $( this ).data( 'is-wp' ) === 1 || $( this ).data( 'is-wp' ) === '1' );
		} );
		tsosk_sr_invalidate_preview();
	} );
	$( document ).on( 'click', '#tsosk-sr-select-all', function () {
		$( '.tsosk-sr-table-cb' ).prop( 'checked', true );
		tsosk_sr_invalidate_preview();
	} );
	$( document ).on( 'click', '#tsosk-sr-select-none', function () {
		$( '.tsosk-sr-table-cb' ).prop( 'checked', false );
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

	// ── Render preview ──
	function tsosk_sr_render_preview( data ) {
		var $wrap   = $( '#tsosk-sr-preview-wrap' );
		var $notice = $( '#tsosk-sr-preview-notice' );
		var $body   = $( '#tsosk-sr-preview-body' );

		$body.empty();
		$wrap.show();

		if ( ! data.total_rows ) {
			$notice.html( '<strong>' + tsosk.i18n.sr_preview_none + '</strong>' ).show();
			return;
		}

		var totalCells = 0;
		$.each( data.results, function ( tableName, rows ) {
			totalCells += rows.length;
		} );
		$notice.html(
			'<strong>' +
			data.total_rows + ' ' + tsosk.i18n.sr_rows + ' ' +
			tsosk.i18n.sr_matches + ' ' +
			Object.keys( data.results ).length + ' ' + tsosk.i18n.sr_tables +
			'.</strong> ' + tsosk.i18n.sr_confirm_hint
		).show();

		$.each( data.results, function ( tableName, rows ) {
			var $section = $( '<div class="tsosk-sr-table-section"></div>' );
			var $header  = $( '<div class="tsosk-sr-table-header"></div>' );
			$header.append( '<span class="tsosk-code" style="font-size:13px;">' + $( '<span>' ).text( tableName ).html() + '</span>' );
			$header.append( '<span class="tsosk-badge tsosk-badge-warn" style="font-size:11px;">' + rows.length + ' ' + tsosk.i18n.sr_rows + '</span>' );
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

		if ( ! confirm( tsosk.i18n.sr_confirm_exec ) ) { return; }

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		$( '#tsosk-sr-preview-btn' ).prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_sr_execute',
			data   : tsosk_sr_params(),
			success: function ( r ) {
				$btn.prop( 'disabled', false ).text( '⚡ ' + tsosk.i18n.sr_execute_btn );
				$( '#tsosk-sr-preview-btn' ).prop( 'disabled', false );
				if ( r.success ) {
					showMsg( $msg, r.data.message, 'ok' );
					$( '#tsosk-sr-execute-btn, #tsosk-sr-cancel-btn' ).hide();
					$( '.tsosk-sr-execute-hint' ).hide();
					$( '#tsosk-sr-preview-wrap' ).hide();
					tsosk_sr_preview_data = null;
					// Reload after 1.5s to refresh history
					setTimeout( function () { window.location.reload(); }, 1500 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
				$( '#tsosk-sr-preview-btn' ).prop( 'disabled', false );
			}
		} );
	} );

	// ── Cancel ──
	$( document ).on( 'click', '#tsosk-sr-cancel-btn', function () {
		tsosk_sr_invalidate_preview();
		$( '#tsosk-sr-preview-wrap' ).hide();
	} );

	// ── Activity History: clear log ──
	$( document ).on( 'click', '#tsosk-history-clear', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-history-msg' );
		if ( ! confirm( tsosk.i18n.history_clear ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_history_clear',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data, 'ok' );
					setTimeout( function () { window.location.reload(); }, 800 );
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

	$( document ).on( 'change', '#tsosk-history-log-all', function () {
		var on = $( this ).is( ':checked' );
		$( '#tsosk-history-modules' ).css( 'opacity', on ? '0.55' : '1' );
		if ( on ) {
			$( '.tsosk-history-module' ).prop( 'checked', true );
		}
	} );

	$( document ).on( 'click', '#tsosk-history-save-settings', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-history-settings-msg' );
		var modules = $( '.tsosk-history-module:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_history_save',
			data   : {
				nonce   : $btn.data( 'nonce' ),
				log_all : $( '#tsosk-history-log-all' ).is( ':checked' ) ? 1 : 0,
				limit   : $( '#tsosk-history-limit' ).val(),
				modules : modules
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	// ── Content Audit: remove broken shortcodes ──
	function tsoskCaRemoveShortcode( postId, shortcode, $btn ) {
		var $msg = $( '#tsosk-ca-shortcodes-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_ca_remove_shortcode',
			data   : {
				nonce     : $btn.data( 'nonce' ),
				post_id   : postId,
				shortcode : shortcode || ''
			},
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data.message || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
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
	}

	$( document ).on( 'click', '.tsosk-ca-remove-sc', function () {
		var $btn = $( this );
		if ( ! confirm( tsosk.i18n.ca_remove_sc_confirm ) ) { return; }
		tsoskCaRemoveShortcode( $btn.data( 'postId' ), $btn.data( 'shortcode' ), $btn );
	} );

	$( document ).on( 'click', '.tsosk-ca-remove-all-sc', function () {
		var $btn = $( this );
		if ( ! confirm( tsosk.i18n.ca_remove_all_confirm ) ) { return; }
		tsoskCaRemoveShortcode( $btn.data( 'postId' ), '', $btn );
	} );

	// ── Meta Editor ───────────────────────────────────────────────────────────
	var tsoskMe = { currentPage: 1, lastSearch: '', context: 'post', editingId: 0 };

	function tsoskMeSearch( page ) {
		if ( ! $( '#tsosk-me-search-btn' ).length ) { return; }
		tsoskMe.currentPage = page || 1;
		tsoskMe.context     = $( '#tsosk-me-context' ).val() || 'post';
		tsoskMe.lastSearch  = $( '#tsosk-me-search' ).val().trim();
		var nonce    = $( '#tsosk-me-search-btn' ).data( 'nonce' );
		var $msg     = $( '#tsosk-me-search-msg' );
		var $tbody   = $( '#tsosk-me-tbody' );
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
				if ( ! r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$tbody.html( '<tr><td colspan="5">' + tsosk.i18n.oe_no_results + '</td></tr>' );
					return;
				}
				tsoskMeRender( r.data );
				showMsg( $msg, r.data.total + ' rows', 'ok' );
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
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
					tsoskMe.editingId = r.data.meta_id;
					$( '#tsosk-me-editor' ).show();
					$( '#tsosk-me-edit-key' ).text( r.data.key );
					$( '#tsosk-me-edit-object' ).text( r.data.object_id );
					$( '#tsosk-me-edit-value' ).val( r.data.value ).prop( 'disabled', r.data.protected );
					$( '#tsosk-me-save, #tsosk-me-delete' ).prop( 'disabled', r.data.protected );
				}
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-me-save', function () {
		var $msg = $( '#tsosk-me-edit-msg' );
		ajaxPost( {
			action : 'tsosk_me_save',
			data   : {
				nonce   : $( this ).data( 'nonce' ),
				context : tsoskMe.context,
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
		ajaxPost( {
			action : 'tsosk_me_delete',
			data   : { nonce: $( this ).data( 'nonce' ), context: tsoskMe.context, meta_id: tsoskMe.editingId },
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
				if ( r.success ) { tsoskMeSearch( 1 ); }
			}
		} );
	} );

	// ── Option Library preview ────────────────────────────────────────────────
	$( document ).on( 'click', '.tsosk-ol-preview', function ( e ) {
		e.preventDefault();
		e.stopPropagation();

		var $btn   = $( this );
		var $box   = $( '#tsosk-ol-preview-box' );
		var $msg   = $( '#tsosk-ol-preview-msg' );
		var name   = $btn.attr( 'data-name' ) || $btn.data( 'name' );
		var nonce  = $btn.attr( 'data-nonce' ) || $btn.data( 'nonce' );
		var label  = $btn.data( 'save-label' ) || $btn.text();

		if ( ! name || ! nonce || ! $box.length ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}

		$( '.tsosk-ol-option-row' ).removeClass( 'is-preview-active' );
		$btn.closest( '.tsosk-ol-option-row' ).addClass( 'is-preview-active' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		$box.show();
		$( '#tsosk-ol-preview-hint' ).hide();

		ajaxPost( {
			action : 'tsosk_ol_preview',
			data   : { nonce: nonce, name: name },
			success: function ( r ) {
				if ( r && r.success && r.data ) {
					$( '#tsosk-ol-preview-name' ).text( r.data.name );
					var meta = 'autoload: ' + r.data.autoload + ', ' + r.data.size + ' bytes';
					if ( r.data.protected ) {
						meta += ' — ' + ( tsosk.i18n.oe_protected || 'Protected' );
					}
					$( '#tsosk-ol-preview-meta' ).text( meta );
					$( '#tsosk-ol-preview-value' ).text( r.data.preview || '' );
					$( '#tsosk-ol-preview-edit' ).attr( 'href', r.data.edit_url || '#' );
					var loaded = tsosk.i18n.ol_preview_loaded || tsosk.i18n.done;
					showMsg( $msg, loaded, 'ok' );
					if ( $box[0] && $box[0].scrollIntoView ) {
						$box[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
					}
				} else {
					var err = ( r && r.data ) ? ( r.data.message || r.data ) : tsosk.i18n.error;
					showMsg( $msg, err, 'error' );
				}
				$btn.prop( 'disabled', false ).text( label );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( label );
			}
		} );
	} );

	// ── Site Snapshot import ──────────────────────────────────────────────────
	function tsoskSnapshotRefreshImportSections() {
		var $box    = $( '#tsosk-snapshot-import-sections' );
		var $list   = $( '#tsosk-snapshot-import-list' );
		var json    = $( '#tsosk-snapshot-json' ).val().trim();
		var labels = ( typeof tsosk !== 'undefined' && tsosk.snapshot_section_labels )
			? tsosk.snapshot_section_labels
			: {};

		$list.empty();
		if ( ! json ) {
			$box.hide();
			return;
		}

		var data;
		try {
			data = JSON.parse( json );
		} catch ( e ) {
			$box.hide();
			return;
		}

		var sections = data.sections || {};
		var keys = Object.keys( sections );
		if ( ! keys.length ) {
			$box.hide();
			return;
		}

		keys.forEach( function ( id ) {
			var label = labels[ id ] || id;
			$list.append(
				'<li style="margin-bottom:6px;"><label>'
				+ '<input type="checkbox" class="tsosk-snapshot-import-section" value="' + id + '" checked> '
				+ label.replace( /</g, '&lt;' )
				+ '</label></li>'
			);
		} );
		$box.show();
	}

	$( document ).on( 'submit', '#tsosk-snapshot-export-form', function () {
		var checked = $( this ).find( 'input[name="sections[]"]:checked' ).length;
		if ( checked < 1 ) {
			window.alert( tsosk.i18n.snapshot_no_sections );
			return false;
		}
	} );

	$( document ).on( 'change', '#tsosk-snapshot-file', function ( e ) {
		var file = e.target.files && e.target.files[0];
		var $msg = $( '#tsosk-snapshot-msg' );
		if ( ! file ) { return; }
		var reader = new FileReader();
		reader.onload = function ( ev ) {
			$( '#tsosk-snapshot-json' ).val( ev.target.result || '' );
			tsoskSnapshotRefreshImportSections();
			$msg.hide().text( '' );
		};
		reader.onerror = function () {
			showMsg( $msg, tsosk.i18n.error, 'error' );
		};
		reader.readAsText( file );
	} );

	$( document ).on( 'input', '#tsosk-snapshot-json', tsoskSnapshotRefreshImportSections );

	$( document ).on( 'click', '#tsosk-snapshot-import', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-snapshot-msg' );
		var json = $( '#tsosk-snapshot-json' ).val().trim();
		if ( ! json ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		if ( ! window.confirm( tsosk.i18n.snapshot_confirm_import ) ) { return; }

		var importSections = [];
		if ( $( '#tsosk-snapshot-import-sections' ).is( ':visible' ) ) {
			$( '.tsosk-snapshot-import-section:checked' ).each( function () {
				importSections.push( $( this ).val() );
			} );
			if ( importSections.length < 1 ) {
				window.alert( tsosk.i18n.snapshot_no_sections );
				return;
			}
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_snapshot_import',
			data   : { nonce: $btn.data( 'nonce' ), snapshot: json, import_sections: importSections },
			success: function ( r ) {
				if ( r.success ) {
					$( '#tsosk-snapshot-json' ).val( '' );
					$( '#tsosk-snapshot-file' ).val( '' );
					$( '#tsosk-snapshot-import-sections' ).hide();
					$( '#tsosk-snapshot-import-list' ).empty();
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

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

	// ── Roles & capabilities ─────────────────────────────────────────────────

	function tsoskEncodeRoleSlug( slug ) {
		try {
			return window.btoa( unescape( encodeURIComponent( slug || '' ) ) );
		} catch ( e ) {
			return slug || '';
		}
	}

	function tsoskText( value ) {
		return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
	}

	function tsoskRolesLoad() {
		var $tbody = $( '#tsosk-roles-tbody' );
		var $msg   = $( '#tsosk-roles-msg' );
		var nonce  = $( '#tsosk-roles-nonce' ).val();
		var role   = $( '#tsosk-roles-select' ).val();
		if ( ! $tbody.length || ! nonce ) { return; }

		$tbody.html( '<tr><td colspan="3">' + tsoskText( tsosk.i18n.loading ) + '</td></tr>' );

		ajaxPost( {
			action : 'tsosk_roles_caps',
			data   : { nonce: nonce, role_slug: tsoskEncodeRoleSlug( role ) },
			success: function ( r ) {
				if ( ! r.success ) {
					var errText = typeof r.data === 'string' ? r.data : tsosk.i18n.error;
					showMsg( $msg, errText, 'error' );
					$tbody.html( '<tr><td colspan="3">' + tsoskText( errText ) + '</td></tr>' );
					return;
				}
				var caps = r.data.caps || [];
				$( '#tsosk-roles-count' ).text( caps.length );
				if ( ! caps.length ) {
					$tbody.html( '<tr><td colspan="3">' + tsoskText( tsosk.i18n.no_matches ) + '</td></tr>' );
					return;
				}
				var isAdmin = role === 'administrator' || r.data.read_only;
				var html    = '';
				caps.forEach( function ( item ) {
					var cap  = typeof item === 'string' ? item : ( item.cap || '' );
					var desc = typeof item === 'object' && item.description ? item.description : '';
					var danger = item.dangerous ? ' <span class="tsosk-badge tsosk-badge-warn">' + tsoskText( tsosk.i18n.dangerous_cap || 'Risk' ) + '</span>' : '';
					html += '<tr><td class="tsosk-code">' + tsoskText( cap ) + danger + '</td><td class="description">' + tsoskText( desc || '—' ) + '</td><td>';
					if ( ! isAdmin ) {
						html += '<button type="button" class="button button-small tsosk-roles-remove" data-cap="' + tsoskText( cap ) + '">' + tsoskText( tsosk.i18n.delete ) + '</button>';
					} else {
						html += '—';
					}
					html += '</td></tr>';
				} );
				$tbody.html( html );
				$( '#tsosk-roles-add-box' ).toggle( ! isAdmin );
				if ( isAdmin && r.data.admin_role ) {
					showMsg( $msg, tsosk.i18n.roles_admin_readonly || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, tsosk.i18n.done, 'ok' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$tbody.html( '<tr><td colspan="3">' + tsoskText( tsosk.i18n.error ) + '</td></tr>' );
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-roles-load', tsoskRolesLoad );
	$( document ).on( 'change', '#tsosk-roles-select', tsoskRolesLoad );
	if ( $( '#tsosk-roles-select' ).length && $( '#tsosk-roles-nonce' ).val() ) {
		tsoskRolesLoad();
	}

	$( document ).on( 'click', '#tsosk-health-save-suppress', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-health-suppress-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_health_save_suppress',
			data   : {
				nonce: nonce,
				suppress_blog_public: $( '#tsosk-health-suppress-blog-public' ).is( ':checked' ) ? 1 : 0,
				suppress_debug_enabled: $( '#tsosk-health-suppress-debug' ).is( ':checked' ) ? 1 : 0
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? ( r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-add-cap', function () {
		var cap   = $( '#tsosk-roles-new-cap' ).val().trim();
		var role  = $( '#tsosk-roles-select' ).val();
		var nonce = $( '#tsosk-roles-nonce' ).val();
		var $msg  = $( '#tsosk-roles-msg' );
		if ( ! cap ) { return; }
		ajaxPost( {
			action : 'tsosk_roles_add_cap',
			data   : { nonce: nonce, role_slug: tsoskEncodeRoleSlug( role ), cap: cap },
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) {
					$( '#tsosk-roles-new-cap' ).val( '' );
					tsoskRolesLoad();
				}
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	$( document ).on( 'click', '.tsosk-roles-remove', function () {
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) { return; }
		var cap   = $( this ).data( 'cap' );
		var role  = $( '#tsosk-roles-select' ).val();
		var nonce = $( '#tsosk-roles-nonce' ).val();
		var $msg  = $( '#tsosk-roles-msg' );
		ajaxPost( {
			action : 'tsosk_roles_remove_cap',
			data   : { nonce: nonce, role_slug: tsoskEncodeRoleSlug( role ), cap: cap },
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskRolesLoad(); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-compare', function () {
		var nonce = $( '#tsosk-roles-nonce' ).val();
		var $out  = $( '#tsosk-roles-compare-out' );
		ajaxPost( {
			action : 'tsosk_roles_compare',
			data   : {
				nonce: nonce,
				role_a_slug: tsoskEncodeRoleSlug( $( '#tsosk-roles-compare-a' ).val() ),
				role_b_slug: tsoskEncodeRoleSlug( $( '#tsosk-roles-compare-b' ).val() )
			},
			success: function ( r ) {
				if ( ! r.success ) {
					$out.hide();
					return;
				}
				var d = r.data || {};
				var text = ( tsosk.i18n.roles_only_a || 'Only in A' ) + ':\n' + ( d.only_a || [] ).join( ', ' ) + '\n\n'
					+ ( tsosk.i18n.roles_only_b || 'Only in B' ) + ':\n' + ( d.only_b || [] ).join( ', ' ) + '\n\n'
					+ ( tsosk.i18n.roles_both || 'In both' ) + ':\n' + ( d.both || [] ).join( ', ' );
				$out.text( text ).show();
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-clone', function () {
		var slug = $( '#tsosk-roles-clone-slug' ).val().trim();
		var label = $( '#tsosk-roles-clone-label' ).val().trim();
		if ( ! slug || ! label ) { return; }
		var $msg = $( '#tsosk-roles-msg' );
		ajaxPost( {
			action : 'tsosk_roles_clone',
			data   : {
				nonce: $( '#tsosk-roles-nonce' ).val(),
				source_slug: tsoskEncodeRoleSlug( $( '#tsosk-roles-select' ).val() ),
				new_role: slug,
				new_label: label
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-apply-template', function () {
		var $msg = $( '#tsosk-roles-msg' );
		ajaxPost( {
			action : 'tsosk_roles_apply_template',
			data   : {
				nonce: $( '#tsosk-roles-nonce' ).val(),
				role_slug: tsoskEncodeRoleSlug( $( '#tsosk-roles-select' ).val() ),
				template: $( '#tsosk-roles-template' ).val()
			},
			success: function ( r ) {
				showMsg( $msg, r.success ? r.data : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( r.success ) { tsoskRolesLoad(); }
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	// ── Global tab search (Fase 4) ────────────────────────────────────────────

	var tsoskSearchActive = -1;

	function tsoskSearchFilter( query ) {
		var items = tsosk.tab_search || [];
		query   = ( query || '' ).toLowerCase().trim();
		if ( ! query ) {
			return items.slice( 0, 12 );
		}
		var tokens = query.split( /\s+/ ).filter( function ( token ) {
			return token.length > 0;
		} );
		return items.filter( function ( item ) {
			var hay = item.search || '';
			for ( var i = 0; i < tokens.length; i++ ) {
				if ( hay.indexOf( tokens[ i ] ) === -1 ) {
					return false;
				}
			}
			return true;
		} ).slice( 0, 12 );
	}

	function tsoskSearchRender( matches ) {
		var $list = $( '#tsosk-global-search-results' );
		if ( ! $list.length ) {
			return;
		}
		tsoskSearchActive = -1;
		if ( ! matches.length ) {
			$list.html(
				'<li><span class="tsosk-search-no-results">' +
				( tsosk.i18n.search_no_results || tsosk.i18n.no_matches ) +
				'</span></li>'
			).prop( 'hidden', false );
			return;
		}
		var html = '';
		matches.forEach( function ( item, i ) {
			html += '<li data-index="' + i + '">' +
				'<a href="' + item.url + '" class="tsosk-search-result">' +
				item.label +
				'<span class="tsosk-search-result-group">' + item.group + '</span>' +
				'</a></li>';
		} );
		$list.html( html ).prop( 'hidden', false );
	}

	function tsoskSearchNavigate( url ) {
		if ( url ) {
			window.location.href = url;
		}
	}

	function tsoskSearchSetActive( index ) {
		var $items = $( '#tsosk-global-search-results li[data-index]' );
		$items.removeClass( 'is-active' );
		tsoskSearchActive = index;
		if ( index >= 0 && index < $items.length ) {
			$items.eq( index ).addClass( 'is-active' );
		}
	}

	$( document ).on( 'input', '#tsosk-global-search-input', function () {
		tsoskSearchRender( tsoskSearchFilter( $( this ).val() ) );
	} );

	$( document ).on( 'focus', '#tsosk-global-search-input', function () {
		tsoskSearchRender( tsoskSearchFilter( $( this ).val() ) );
	} );

	$( document ).on( 'keydown', '#tsosk-global-search-input', function ( e ) {
		var $list    = $( '#tsosk-global-search-results' );
		var $links   = $list.find( 'li[data-index] a' );
		var count    = $links.length;

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			tsoskSearchSetActive( count ? ( ( tsoskSearchActive + 1 ) % count ) : -1 );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			tsoskSearchSetActive( count ? ( ( tsoskSearchActive - 1 + count ) % count ) : -1 );
		} else if ( e.key === 'Enter' ) {
			if ( tsoskSearchActive >= 0 && $links.eq( tsoskSearchActive ).length ) {
				e.preventDefault();
				tsoskSearchNavigate( $links.eq( tsoskSearchActive ).attr( 'href' ) );
			}
		} else if ( e.key === 'Escape' ) {
			$list.prop( 'hidden', true );
			$( this ).blur();
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '#tsosk-global-search' ).length ) {
			$( '#tsosk-global-search-results' ).prop( 'hidden', true );
		}
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' ) {
			var $input = $( '#tsosk-global-search-input' );
			if ( $input.length ) {
				e.preventDefault();
				$input.trigger( 'focus' ).select();
			}
		}
	} );

	// ── Developer mode preset ─────────────────────────────────────────────────

	function tsoskDeveloperMode( enable, $msg ) {
		var nonce = tsosk.debug_nonce || $( '#tsosk-debug-developer-on' ).data( 'nonce' );
		if ( ! nonce ) {
			return;
		}
		ajaxPost( {
			action : 'tsosk_debug_developer_mode',
			data   : { nonce: nonce, enable: enable ? 1 : 0 },
			success: function ( r ) {
				var text = r.success ? ( r.data && r.data.message ? r.data.message : tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				if ( $msg && $msg.length ) {
					showMsg( $msg, text, r.success ? 'ok' : 'error' );
				}
				if ( r.success ) {
					window.location.reload();
				}
			},
			error: function () {
				if ( $msg && $msg.length ) {
					showMsg( $msg, tsosk.i18n.error, 'error' );
				}
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-debug-developer-on, #tsosk-header-dev-mode-on', function () {
		tsoskDeveloperMode( true, $( '#tsosk-debug-msg' ) );
	} );

	$( document ).on( 'click', '#tsosk-debug-developer-off', function () {
		tsoskDeveloperMode( false, $( '#tsosk-debug-msg' ) );
	} );

	// ── Admin Menu Editor ─────────────────────────────────────────────────────

	function tsoskAmGetTopSlug( $topRow ) {
		return String( $topRow.attr( 'data-slug' ) || '' );
	}

	var tsoskAmExpandedSections = {};

	function tsoskAmSectionSlug( $row ) {
		return String( $row.attr( 'data-am-section' ) || $row.attr( 'data-slug' ) || '' );
	}

	function tsoskAmSubParentSlug( $row ) {
		return String( $row.attr( 'data-am-parent-section' ) || '' );
	}

	function tsoskAmTopRowBySection( sectionSlug ) {
		return $( '#tsosk-am-tbody tr.tsosk-am-row-top' ).filter( function () {
			return tsoskAmSectionSlug( $( this ) ) === sectionSlug;
		} );
	}

	function tsoskAmSubRowsBySection( sectionSlug ) {
		return $( '#tsosk-am-tbody tr.tsosk-am-row-sub, #tsosk-am-tbody tr.tsosk-am-row-nested-top' ).filter( function () {
			return tsoskAmGetSubParentSlug( $( this ) ) === sectionSlug;
		} );
	}

	function tsoskAmSetSectionExpanded( sectionSlug, expanded ) {
		if ( ! sectionSlug ) {
			return;
		}
		if ( expanded ) {
			tsoskAmExpandedSections[ sectionSlug ] = true;
		} else {
			delete tsoskAmExpandedSections[ sectionSlug ];
		}

		var $top = tsoskAmTopRowBySection( sectionSlug );
		$top.toggleClass( 'is-am-expanded', expanded );

		var $toggle = $top.find( '.tsosk-am-toggle' );
		if ( $toggle.length ) {
			$toggle.attr( 'aria-expanded', expanded ? 'true' : 'false' );
			$toggle.find( '.dashicons' )
				.toggleClass( 'dashicons-arrow-right-alt2', ! expanded )
				.toggleClass( 'dashicons-arrow-down-alt2', expanded );
		}

		tsoskAmSubRowsBySection( sectionSlug ).each( function () {
			var $sub = $( this );
			$sub.toggleClass( 'is-am-collapsed', ! expanded );
			$sub.prop( 'hidden', ! expanded );
		} );

		if ( $.fn.sortable && $( '#tsosk-am-tbody' ).hasClass( 'ui-sortable' ) ) {
			$( '#tsosk-am-tbody' ).sortable( 'refresh' );
		}
	}

	function tsoskAmApplyCollapseState() {
		$( '#tsosk-am-tbody tr.tsosk-am-row-top.tsosk-am-has-children' ).each( function () {
			var slug = tsoskAmSectionSlug( $( this ) );
			tsoskAmSetSectionExpanded( slug, !! tsoskAmExpandedSections[ slug ] );
		} );
	}

	function tsoskAmExpandAllSections( expand ) {
		$( '#tsosk-am-tbody tr.tsosk-am-row-top.tsosk-am-has-children' ).each( function () {
			tsoskAmSetSectionExpanded( tsoskAmSectionSlug( $( this ) ), expand );
		} );
	}

	function tsoskAmToggleSection( sectionSlug ) {
		tsoskAmSetSectionExpanded( sectionSlug, ! tsoskAmExpandedSections[ sectionSlug ] );
	}

	function tsoskAmGetSubParentSlug( $row ) {
		var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
		if ( nestUnder && ( $row.hasClass( 'tsosk-am-row-nested-top' ) || $row.hasClass( 'tsosk-am-row-top' ) ) ) {
			return nestUnder;
		}
		return $row.find( '.tsosk-am-parent' ).val() || $row.attr( 'data-am-parent-section' ) || '';
	}

	function tsoskAmAssignParentFromDrag( $dragged ) {
		if ( ! $dragged || ! $dragged.length ) {
			return;
		}

		if ( $dragged.hasClass( 'tsosk-am-row-nested-top' ) ) {
			var $prevTop = $dragged.prevAll( 'tr.tsosk-am-row-top:first' );
			if ( $prevTop.length ) {
				var parentSlug = tsoskAmGetTopSlug( $prevTop );
				$dragged.find( '.tsosk-am-top-nest' ).val( parentSlug );
				$dragged.attr( 'data-am-parent-section', parentSlug );
			}
			return;
		}

		if ( $dragged.hasClass( 'tsosk-am-row-sub' ) ) {
			var $prevTop = $dragged.prevAll( 'tr.tsosk-am-row-top:first' );
			if ( $prevTop.length ) {
				$dragged.find( '.tsosk-am-parent' ).val( tsoskAmGetTopSlug( $prevTop ) );
			}
			return;
		}

		if ( $dragged.hasClass( 'tsosk-am-row-top' ) ) {
			var $prev = $dragged.prev( 'tr.tsosk-am-row' );
			if ( ! $prev.length ) {
				$dragged.find( '.tsosk-am-top-nest' ).val( '' );
				return;
			}
			if ( $prev.hasClass( 'tsosk-am-row-top' ) ) {
				$dragged.find( '.tsosk-am-top-nest' ).val( tsoskAmGetTopSlug( $prev ) );
				$dragged.attr( 'data-am-parent-section', tsoskAmGetTopSlug( $prev ) );
				return;
			}
			if ( $prev.hasClass( 'tsosk-am-row-sub' ) || $prev.hasClass( 'tsosk-am-row-nested-top' ) ) {
				var parentSlug = $prev.find( '.tsosk-am-parent' ).val() || $prev.attr( 'data-am-parent-section' ) || '';
				if ( ! parentSlug ) {
					var $sectionTop = $prev.prevAll( 'tr.tsosk-am-row-top:first' );
					if ( $sectionTop.length ) {
						parentSlug = tsoskAmGetTopSlug( $sectionTop );
					}
				}
				$dragged.find( '.tsosk-am-top-nest' ).val( parentSlug );
				$dragged.attr( 'data-am-parent-section', parentSlug );
			}
		}
	}

	function tsoskAmNormalizeRows( expandSection ) {
		var $tbody = $( '#tsosk-am-tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		var rows = [];
		$tbody.find( 'tr.tsosk-am-row' ).each( function () {
			rows.push( $( this ).detach() );
		} );

		// Nested plugin menus restored to a top-level section.
		rows.forEach( function ( $row ) {
			if ( ! $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				return;
			}
			var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			if ( nestUnder ) {
				return;
			}
			var topSlug = tsoskAmGetTopSlug( $row );
			$row.removeClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' )
				.addClass( 'tsosk-am-row-top' )
				.attr( 'data-am-section', topSlug )
				.removeAttr( 'data-am-parent-section' );
		} );

		// Top-level plugin menus configured to live under another section.
		rows.forEach( function ( $row ) {
			if ( ! $row.hasClass( 'tsosk-am-row-top' ) || $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				return;
			}
			var topSlug   = tsoskAmGetTopSlug( $row );
			var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			if ( ! nestUnder || nestUnder === topSlug ) {
				return;
			}
			$row.removeClass( 'tsosk-am-row-top' )
				.addClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' )
				.attr( 'data-am-parent-section', nestUnder )
				.removeAttr( 'data-am-section' );
		} );

		var topRows          = [];
		var childrenByParent = {};

		rows.forEach( function ( $row ) {
			if ( $row.hasClass( 'tsosk-am-row-top' ) ) {
				topRows.push( $row );
				return;
			}
			var parent = tsoskAmGetSubParentSlug( $row );
			if ( ! parent ) {
				return;
			}
			if ( ! childrenByParent[ parent ] ) {
				childrenByParent[ parent ] = [];
			}
			childrenByParent[ parent ].push( $row );
		} );

		topRows.forEach( function ( $top ) {
			var slug = tsoskAmGetTopSlug( $top );
			$top.addClass( 'tsosk-am-row-top' ).removeClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' );
			$top.attr( 'data-am-section', slug ).removeAttr( 'data-am-parent-section' );
			$tbody.append( $top );

			var kids = childrenByParent[ slug ] || [];
			kids.forEach( function ( $child ) {
				$tbody.append( $child );
			} );
		} );

		rows.forEach( function ( $row ) {
			if ( $row.hasClass( 'tsosk-am-row-top' ) ) {
				return;
			}
			var parent = tsoskAmGetSubParentSlug( $row );
			if ( parent && childrenByParent[ parent ] ) {
				var parentIsTop = topRows.some( function ( $top ) {
					return tsoskAmGetTopSlug( $top ) === parent;
				} );
				if ( parentIsTop ) {
					return;
				}
			}
			$tbody.append( $row );
		} );

		if ( expandSection ) {
			tsoskAmSetSectionExpanded( expandSection, true );
		} else {
			tsoskAmApplyCollapseState();
		}

		if ( $.fn.sortable && $tbody.hasClass( 'ui-sortable' ) ) {
			$tbody.sortable( 'refresh' );
		}
	}

	function tsoskAmCollectItems() {
		var items = [];
		var sort  = 0;

		function pushRow( $row ) {
			var isTopRow = $row.hasClass( 'tsosk-am-row-top' ) && ! $row.hasClass( 'tsosk-am-row-nested-top' );
			var item     = {
				id     : $row.attr( 'data-id' ),
				slug   : $.trim( $row.attr( 'data-slug' ) || '' ),
				visible: ! $row.find( '.tsosk-am-hide' ).is( ':checked' ),
				label  : $.trim( $row.find( '.tsosk-am-label' ).val() ),
				sort   : sort
			};

			if ( ! isTopRow ) {
				item.effective_parent = tsoskAmGetSubParentSlug( $row );
				item.parent_slug      = $row.find( '.tsosk-am-parent' ).val() || item.effective_parent || '';
				item.orig_parent        = $row.attr( 'data-orig-parent' ) || '';
			}
			if ( $row.hasClass( 'tsosk-am-row-top' ) || $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				item.nest_under = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			}

			items.push( item );
			sort += 1;
		}

		// Preserve exact on-screen order from the main list first.
		$( '#tsosk-am-tbody > tr.tsosk-am-row' ).each( function () {
			pushRow( $( this ) );
		} );
		$( '#tsosk-am-hidden-tbody > tr.tsosk-am-row' ).each( function () {
			pushRow( $( this ) );
		} );

		return items;
	}

	/**
	 * Build top/sub menu order maps from the current table layout (visual order).
	 *
	 * @return {{top_order: string[], sub_order: Object.<string, string[]>}}
	 */
	function tsoskAmBuildSectionOrders() {
		var topOrder    = [];
		var subOrder    = {};
		var subOrderIds = {};
		var currentSection = '';

		// Read exact tbody DOM order (do not regroup — preserves drag order).
		$( '#tsosk-am-tbody > tr.tsosk-am-row' ).each( function () {
			var $row   = $( this );
			var slug   = $.trim( $row.attr( 'data-slug' ) || '' );
			var id     = $.trim( $row.attr( 'data-id' ) || '' );
			var isTop  = $row.hasClass( 'tsosk-am-row-top' ) && ! $row.hasClass( 'tsosk-am-row-nested-top' );

			if ( isTop ) {
				if ( slug && topOrder.indexOf( slug ) === -1 ) {
					topOrder.push( slug );
				}
				currentSection = slug;
				return;
			}

			if ( ! slug || ! id ) {
				return;
			}

			var parent = tsoskAmGetSubParentSlug( $row ) || currentSection;
			if ( ! parent ) {
				return;
			}

			if ( ! subOrder[ parent ] ) {
				subOrder[ parent ]    = [];
				subOrderIds[ parent ] = [];
			}
			if ( subOrder[ parent ].indexOf( slug ) === -1 ) {
				subOrder[ parent ].push( slug );
			}
			if ( subOrderIds[ parent ].indexOf( id ) === -1 ) {
				subOrderIds[ parent ].push( id );
			}
		} );

		return {
			top_order     : topOrder,
			sub_order     : subOrder,
			sub_order_ids : subOrderIds
		};
	}

	var tsoskAmScrollActive = false;
	var tsoskAmScrollY      = 0;
	var tsoskAmScrollRaf    = null;

	function tsoskAmGetScrollTargets() {
		var targets = [];
		$( '.tsosk-content, #wpbody-content, #wpcontent, #wpwrap' ).each( function () {
			if ( this.scrollHeight > this.clientHeight + 2 ) {
				targets.push( this );
			}
		} );
		if ( document.scrollingElement ) {
			targets.push( document.scrollingElement );
		} else {
			targets.push( document.documentElement );
		}
		return targets;
	}

	function tsoskAmAutoScrollStep() {
		if ( ! tsoskAmScrollActive ) {
			tsoskAmScrollRaf = null;
			return;
		}

		var edge    = 80;
		var maxStep = 22;
		var y       = tsoskAmScrollY;

		function stepForRect( rect ) {
			if ( y < rect.top + edge ) {
				return -maxStep * Math.min( 1, ( rect.top + edge - y ) / edge );
			}
			if ( y > rect.bottom - edge ) {
				return maxStep * Math.min( 1, ( y - ( rect.bottom - edge ) ) / edge );
			}
			return 0;
		}

		tsoskAmGetScrollTargets().forEach( function ( el ) {
			var rect = ( el === document.documentElement || el === document.scrollingElement )
				? { top: 0, bottom: window.innerHeight }
				: el.getBoundingClientRect();
			var step = stepForRect( rect );
			if ( ! step ) {
				return;
			}
			if ( el === document.documentElement || el === document.scrollingElement ) {
				window.scrollBy( 0, step );
			} else {
				el.scrollTop += step;
			}
		} );

		tsoskAmScrollRaf = window.requestAnimationFrame( tsoskAmAutoScrollStep );
	}

	function tsoskAmBindDragScroll() {
		$( document ).on( 'mousemove.tsoskAmScroll touchmove.tsoskAmScroll', function ( e ) {
			tsoskAmUpdatePointerY( e );
		} );
	}

	function tsoskAmUnbindDragScroll() {
		$( document ).off( 'mousemove.tsoskAmScroll touchmove.tsoskAmScroll' );
	}

	function tsoskAmStartAutoScroll( event ) {
		tsoskAmUpdatePointerY( event );
		tsoskAmScrollActive = true;
		tsoskAmBindDragScroll();
		if ( ! tsoskAmScrollRaf ) {
			tsoskAmScrollRaf = window.requestAnimationFrame( tsoskAmAutoScrollStep );
		}
	}

	function tsoskAmStopAutoScroll() {
		tsoskAmScrollActive = false;
		tsoskAmUnbindDragScroll();
		if ( tsoskAmScrollRaf ) {
			window.cancelAnimationFrame( tsoskAmScrollRaf );
			tsoskAmScrollRaf = null;
		}
	}

	function tsoskAmUpdatePointerY( event ) {
		if ( event && typeof event.clientY === 'number' ) {
			tsoskAmScrollY = event.clientY;
			return;
		}
		var oe = event && event.originalEvent;
		if ( oe && oe.touches && oe.touches.length ) {
			tsoskAmScrollY = oe.touches[0].clientY;
		} else if ( oe && oe.changedTouches && oe.changedTouches.length ) {
			tsoskAmScrollY = oe.changedTouches[0].clientY;
		}
	}

	function tsoskAmInitSortable() {
		var $tbody = $( '#tsosk-am-tbody' );
		if ( ! $tbody.length || ! $.fn.sortable ) {
			return;
		}
		if ( $tbody.hasClass( 'ui-sortable' ) ) {
			$tbody.sortable( 'destroy' );
		}

		$tbody.sortable( {
			handle               : '.tsosk-am-handle',
			items                : '> tr.tsosk-am-row:not([hidden])',
			axis                 : 'y',
			appendTo               : document.body,
			scroll                 : false,
			tolerance              : 'pointer',
			distance               : 4,
			cursor                 : 'grabbing',
			forcePlaceholderSize   : true,
			cancel                 : 'input,textarea,select,button,a,label,.tsosk-am-toggle',
			helper                 : function ( e, $row ) {
				var $helper = $row.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $row.children().eq( index ).outerWidth() );
				} );
				$helper.css( {
					width    : $row.outerWidth(),
					display  : 'table',
					tableLayout : 'fixed',
					position : 'absolute',
					zIndex   : 100100
				} );
				return $helper;
			},
			start                : function ( event, ui ) {
				ui.item.addClass( 'tsosk-am-dragging' );
				ui.placeholder.height( ui.item.outerHeight() );
				tsoskAmStartAutoScroll( event );
			},
			sort             : function ( event ) {
				tsoskAmUpdatePointerY( event );
			},
			stop             : function ( event, ui ) {
				ui.item.removeClass( 'tsosk-am-dragging' );
				tsoskAmStopAutoScroll();
				tsoskAmAssignParentFromDrag( ui.item );
			}
		} );
	}

	function tsoskAmInit() {
		if ( ! $( '#tsosk-am-tbody' ).length ) {
			return;
		}
		tsoskAmInitSortable();
		tsoskAmExpandedSections = {};
		tsoskAmApplyCollapseState();
		var expandSections = $( '#tsosk-am-table' ).data( 'expand-sections' );
		if ( expandSections ) {
			String( expandSections ).split( ',' ).forEach( function ( slug ) {
				slug = $.trim( slug );
				if ( slug ) {
					tsoskAmSetSectionExpanded( slug, true );
				}
			} );
		}
	}

	$( tsoskAmInit );

	$( document ).on( 'click', '.tsosk-am-toggle', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $top = $( this ).closest( 'tr.tsosk-am-row-top' );
		tsoskAmToggleSection( tsoskAmSectionSlug( $top ) );
	} );

	$( document ).on( 'click', 'tr.tsosk-am-row-top.tsosk-am-has-children', function ( e ) {
		if ( $( e.target ).closest( '.tsosk-am-handle, input, select, textarea, button, a, label' ).length ) {
			return;
		}
		tsoskAmToggleSection( tsoskAmSectionSlug( $( this ) ) );
	} );

	$( document ).on( 'click', '#tsosk-am-expand-all', function ( e ) {
		e.preventDefault();
		tsoskAmExpandAllSections( true );
	} );

	$( document ).on( 'click', '#tsosk-am-collapse-all', function ( e ) {
		e.preventDefault();
		tsoskAmExpandAllSections( false );
	} );

	$( document ).on( 'change', '.tsosk-am-parent', function () {
		var $row = $( this ).closest( 'tr.tsosk-am-row-sub' );
		$row.attr( 'data-am-parent-section', $( this ).val() || '' );
		tsoskAmNormalizeRows();
	} );

	$( document ).on( 'change', '.tsosk-am-top-nest', function () {
		var nest = $.trim( $( this ).val() || '' );
		tsoskAmNormalizeRows( nest );
	} );

	$( document ).on( 'change', '.tsosk-am-hide', function () {
		var $row = $( this ).closest( 'tr' );
		var hidden = $( this ).is( ':checked' );
		$row.toggleClass( 'tsosk-am-row-is-hidden', hidden );
		$row.find( '.tsosk-am-label' ).prop( 'disabled', hidden );
	} );

	$( document ).on( 'click', '#tsosk-am-show-hidden', function ( e ) {
		e.preventDefault();
		var $section = $( '#tsosk-am-hidden-section' );
		if ( ! $section.length ) {
			return;
		}

		$section.addClass( 'is-highlighted' );
		setTimeout( function () {
			$section.removeClass( 'is-highlighted' );
		}, 2200 );

		var scrollTop = Math.max( 0, $section.offset().top - 100 );
		$( 'html, body, .tsosk-content' ).animate( { scrollTop: scrollTop }, 300 );

		var $first = $section.find( 'tr.tsosk-am-row' ).first();
		if ( $first.length ) {
			$first.addClass( 'tsosk-am-row-highlight' );
			setTimeout( function () {
				$first.removeClass( 'tsosk-am-row-highlight' );
			}, 2200 );
		}
	} );

	$( document ).on( 'click', '#tsosk-am-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-am-msg' );
		var nonce = $btn.data( 'nonce' );

		// Apply nest/unnest row layout, then read order from the table (sections stay collapsed).
		tsoskAmNormalizeRows();
		var sectionOrders = tsoskAmBuildSectionOrders();

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_am_save',
			data   : {
				nonce         : nonce,
				items         : JSON.stringify( tsoskAmCollectItems() ),
				sub_order     : JSON.stringify( sectionOrders.sub_order ),
				sub_order_ids : JSON.stringify( sectionOrders.sub_order_ids ),
				top_order     : JSON.stringify( sectionOrders.top_order )
			},
			success: function ( r ) {
				if ( r.success ) {
					var text = tsosk.i18n.done;
					if ( r.data && typeof r.data === 'object' && r.data.message ) {
						text = r.data.message;
					} else if ( typeof r.data === 'string' && r.data ) {
						text = r.data;
					}
					showMsg( $msg, text, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					var errText = tsosk.i18n.error;
					if ( typeof r.data === 'string' && r.data ) {
						errText = r.data;
					} else if ( r.data && r.data.message ) {
						errText = r.data.message;
					}
					showMsg( $msg, errText, 'error' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-am-reset', function () {
		if ( ! window.confirm( tsosk.i18n.am_reset_confirm || 'Reset the admin menu to WordPress defaults?' ) ) {
			return;
		}
		var $btn  = $( this );
		var $msg  = $( '#tsosk-am-msg' );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_am_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
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

	// ── Custom 404 page ───────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-custom-404-preview', function () {
		var $btn    = $( this );
		var $msg    = $( '#tsosk-custom-404-msg' );
		var pageId  = $( '#tsosk-custom-404-page' ).val();
		var nonce   = $btn.data( 'preview-nonce' );
		var homeUrl = $btn.data( 'home-url' ) || '/';

		if ( ! pageId || pageId === '0' ) {
			showMsg( $msg, tsosk.i18n.custom_404_select_preview || tsosk.i18n.error, 'error' );
			return;
		}

		var url = homeUrl + ( homeUrl.indexOf( '?' ) >= 0 ? '&' : '?' )
			+ 'tsosk_404_preview=1'
			+ '&page_id=' + encodeURIComponent( pageId )
			+ '&preview_nonce=' + encodeURIComponent( nonce );

		if ( $( '#tsosk-custom-404-send-410' ).is( ':checked' ) ) {
			url += '&send_410=1';
		}

		window.open( url, '_blank', 'noopener,noreferrer' );
	} );

	$( document ).on( 'click', '#tsosk-custom-404-save', function () {
		var nonce   = $( this ).data( 'nonce' );
		var $msg    = $( '#tsosk-custom-404-msg' );
		var pageId  = $( '#tsosk-custom-404-page' ).val();

		ajaxPost( {
			action : 'tsosk_custom_404_save',
			data   : {
				nonce              : nonce,
				page_id            : pageId,
				hide_from_search   : $( '#tsosk-custom-404-hide-search' ).is( ':checked' ) ? 1 : 0,
				hide_from_admin    : $( '#tsosk-custom-404-hide-admin' ).is( ':checked' ) ? 1 : 0,
				force_direct_404   : $( '#tsosk-custom-404-force-direct' ).is( ':checked' ) ? 1 : 0,
				disable_url_guess  : $( '#tsosk-custom-404-disable-guess' ).is( ':checked' ) ? 1 : 0,
				send_410           : $( '#tsosk-custom-404-send-410' ).is( ':checked' ) ? 1 : 0
			},
			success: function ( r ) {
				var text = r.success ? ( r.data && r.data.message ? r.data.message : tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				showMsg( $msg, text, r.success ? 'ok' : 'error' );
				if ( r.success && r.data ) {
					var $badge = $( '#tsosk-custom-404-status' );
					if ( r.data.active ) {
						$badge.attr( 'class', 'tsosk-badge tsosk-badge-ok' ).text( tsosk.i18n.custom_404_active || 'Custom 404 active' );
					} else {
						$badge.attr( 'class', 'tsosk-badge tsosk-badge-info' ).text( tsosk.i18n.custom_404_default || 'Using theme default 404' );
					}
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

} )( jQuery );