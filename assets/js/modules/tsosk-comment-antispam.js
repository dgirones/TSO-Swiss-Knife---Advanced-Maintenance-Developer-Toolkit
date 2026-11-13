/**
 * TSO Swiss Knife – Admin JavaScript: comment-antispam tab.
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
				learning_mode           : $( '#tsosk-cas-learning-mode' ).prop( 'checked' ) ? 1 : 0,
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
				whitelist_ips           : $( '#tsosk-cas-whitelist' ).val(),
				log_max                 : $( '#tsosk-cas-log-max' ).val(),
				alert_enabled           : $( '#tsosk-cas-alert-enabled' ).prop( 'checked' ) ? 1 : 0,
				alert_email             : $( '#tsosk-cas-alert-email' ).val(),
				alert_threshold         : $( '#tsosk-cas-alert-threshold' ).val(),
				alert_provider_errors   : $( '#tsosk-cas-alert-providers' ).prop( 'checked' ) ? 1 : 0,
				protect_reviews         : $( '#tsosk-cas-protect-reviews' ).prop( 'checked' ) ? 1 : 0,
				protect_registration    : $( '#tsosk-cas-protect-registration' ).prop( 'checked' ) ? 1 : 0,
				ban_enabled             : $( '#tsosk-cas-ban-enabled' ).prop( 'checked' ) ? 1 : 0,
				ban_threshold           : $( '#tsosk-cas-ban-threshold' ).val(),
				ban_hours               : $( '#tsosk-cas-ban-hours' ).val(),
				trust_returning         : $( '#tsosk-cas-trust-returning' ).prop( 'checked' ) ? 1 : 0,
				block_message           : $( '#tsosk-cas-block-message' ).val(),
				observe_rules           : $( '.tsosk-cas-observe:checked' ).map( function () { return this.value; } ).get()
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

	$( document ).on( 'click', '#tsosk-cas-clear-learning-log', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_cas_clear_learning_log',
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

	$( document ).on( 'click', '.tsosk-cas-test-provider', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-cas-test-msg' );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cas_test_provider',
			data   : { nonce: $btn.data( 'nonce' ), provider: $btn.data( 'provider' ) },
			success: function ( r ) {
				$msg.attr( 'class', 'tsosk-msg tsosk-msg--' + ( r.success ? 'ok' : 'error' ) ).text( r.data ).show();
				$btn.prop( 'disabled', false );
			},
			error: function () {
				$msg.attr( 'class', 'tsosk-msg tsosk-msg--error' ).text( tsosk.i18n.error ).show();
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-cas-allow-ip', function () {
		var $btn = $( this );

		if ( ! window.confirm( $btn.data( 'confirm' ) ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cas_allow_ip',
			data   : { nonce: $btn.data( 'nonce' ), ip: $btn.data( 'ip' ), log: $btn.data( 'log' ) },
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

	// Comment anti-spam: sub-tabs (remembered for the browser session).
	function casShowTab( tab ) {
		var $tab = $( '.tsosk-cas-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.tsosk-cas-tab' ).removeClass( 'nav-tab-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'nav-tab-active' ).attr( 'aria-selected', 'true' );
		$( '.tsosk-cas-panel' ).prop( 'hidden', true );
		$( '#tsosk-cas-panel-' + tab ).prop( 'hidden', false );
		try {
			window.sessionStorage.setItem( 'tsosk_cas_tab', tab );
		} catch ( e ) {
			// Storage unavailable: the tab just is not remembered.
		}
	}

	$( document ).on( 'click', '.tsosk-cas-tab', function ( e ) {
		e.preventDefault();
		casShowTab( $( this ).data( 'tab' ) );
	} );

	$( function () {
		if ( ! $( '.tsosk-cas-tabs' ).length ) {
			return;
		}
		var saved = '';
		try {
			saved = window.sessionStorage.getItem( 'tsosk_cas_tab' ) || '';
		} catch ( e ) {
			saved = '';
		}
		if ( saved ) {
			casShowTab( saved );
		}
	} );

	$( document ).on( 'click', '#tsosk-cas-test-alert', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-cas-alert-msg' );

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_cas_test_alert',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				$msg.attr( 'class', 'tsosk-msg tsosk-msg--' + ( r.success ? 'ok' : 'error' ) ).text( r.data ).show();
				$btn.prop( 'disabled', false );
			},
			error: function () {
				$msg.attr( 'class', 'tsosk-msg tsosk-msg--error' ).text( tsosk.i18n.error ).show();
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
