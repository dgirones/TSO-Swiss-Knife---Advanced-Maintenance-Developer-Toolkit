/**
 * TSO Swiss Knife – Admin JavaScript: login-protect tab.
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
			notify_mass_window: $( '#tsosk-lp-mass-window' ).val(),
			user_lockout   : $( '#tsosk-lp-user-lockout' ).prop( 'checked' ) ? 1 : 0,
			user_max_attempts: $( '#tsosk-lp-user-max' ).val(),
			user_window    : $( '#tsosk-lp-user-window' ).val()
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
					// Reload: lockouts still running are kept, so the table is not simply emptied.
					setTimeout( function () { window.location.reload(); }, 1200 );
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


	// Reset the failure counter of one username.
	$( document ).on( 'click', '.tsosk-lp-reset-user', function () {
		var $btn  = $( this );
		var user  = $btn.data( 'user' );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-lp-msg' );

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_lp_reset_user',
			data   : { nonce: nonce, user: user },
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

} )( jQuery );
