/**
 * TSO Swiss Knife – Admin JavaScript: security tab.
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


	// ── Security Review ────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-security-save', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-msg' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var data = { nonce: $btn.data( 'nonce' ) };

		// Only the constant toggles; rows locked by wp-config.php are disabled and must not be written to the TSO config.
		$( 'input[id^="tsosk-sec-"][data-const]' ).not( ':disabled' ).each( function () {
			var key = 'tsosk_security_' + $( this ).data( 'const' );
			data[ key ] = $( this ).prop( 'checked' ) ? 1 : 0;
		} );

		data.tsosk_security_disable_xmlrpc    = $( '#tsosk-sec-disable-xmlrpc' ).prop( 'checked' ) ? 1 : 0;
		data.tsosk_security_disable_feeds     = $( '#tsosk-sec-disable-feeds' ).prop( 'checked' ) ? 1 : 0;
		data.tsosk_security_disable_pingbacks = $( '#tsosk-sec-disable-pingbacks' ).prop( 'checked' ) ? 1 : 0;
		data.tsosk_security_block_author_enum = $( '#tsosk-sec-block-author-enum' ).prop( 'checked' ) ? 1 : 0;

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

	$( document ).on( 'change', '#tsosk-sec-force-server-rules', function () {
		var unlock = $( this ).prop( 'checked' );
		$( '#tsosk-sec-block-indexes, #tsosk-sec-deny-wp-config, #tsosk-sec-deny-htaccess, #tsosk-sec-deny-sensitive, #tsosk-sec-block-php-cache' ).prop( 'disabled', ! unlock );
	} );

	$( document ).on( 'click', '#tsosk-security-save-server-rules', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-server-rules-msg' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var locked = '1' === String( $btn.data( 'locked' ) || '0' );
		var force = $( '#tsosk-sec-force-server-rules' ).prop( 'checked' );
		if ( locked && ! force ) {
			showMsg( $msg, ( tsosk.i18n && tsosk.i18n.sec_server_locked ) || 'Tick the override option to write the server rules while a security plugin is active.', 'error' );
			return;
		}
		var data = {
			nonce: $btn.data( 'nonce' ),
			tsosk_security_block_indexes: $( '#tsosk-sec-block-indexes' ).prop( 'checked' ) ? 1 : 0,
			tsosk_security_deny_wp_config: $( '#tsosk-sec-deny-wp-config' ).prop( 'checked' ) ? 1 : 0,
			tsosk_security_deny_htaccess: $( '#tsosk-sec-deny-htaccess' ).prop( 'checked' ) ? 1 : 0,
			tsosk_security_deny_sensitive: $( '#tsosk-sec-deny-sensitive' ).prop( 'checked' ) ? 1 : 0,
			tsosk_security_block_php_cache: $( '#tsosk-sec-block-php-cache' ).prop( 'checked' ) ? 1 : 0,
			tsosk_security_force_server_rules: force ? 1 : 0
		};

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_save_server_rules',
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

	$( document ).on( 'click', '#tsosk-security-save-alerts', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-alerts-msg' );
		var saveLabel = $btn.data( 'save-label' ) || $btn.text();
		var data = {
			nonce  : $btn.data( 'nonce' ),
			enabled: $( '#tsosk-sec-alerts-enabled' ).prop( 'checked' ) ? 1 : 0,
			email  : $( '#tsosk-sec-alerts-email' ).val()
		};

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_save_alerts',
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

	$( document ).on( 'click', '.tsosk-security-revoke-app-password', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-app-passwords-msg' );
		var data = {
			nonce  : $btn.data( 'nonce' ),
			user_id: $btn.data( 'user-id' ),
			uuid   : $btn.data( 'uuid' )
		};

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_revoke_app_password',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_revoke );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_revoke );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-security-test-uploads-php', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-uploads-msg' );
		var data = { nonce: $btn.data( 'nonce' ) };

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_test_uploads_php',
			data   : data,
			success: function ( r ) {
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_test_now );
				if ( r.success ) {
					showMsg( $msg, r.data.message || tsosk.i18n.done, r.data.status === 'executed' ? 'error' : 'ok' );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_test_now );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-security-protect-uploads', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-security-uploads-msg' );
		var data = { nonce: $btn.data( 'nonce' ) };

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_protect_uploads',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_enable_protection );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_enable_protection );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-security-revoke-all-app-passwords', function () {
		if ( ! window.confirm( tsosk.i18n.sec_revoke_all_confirm ) ) {
			return;
		}
		var $btn = $( this );
		var $msg = $( '#tsosk-security-app-passwords-msg' );
		var data = { nonce: $btn.data( 'nonce' ) };

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_security_revoke_all_app_passwords',
			data   : data,
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_revoke_all );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.sec_revoke_all );
			}
		} );
	} );

	// Security Review: sub-tabs (hash anchors from Overview open the panel that holds them).
	function secShowTab( tab ) {
		var $tab = $( '.tsosk-sec-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.tsosk-sec-tab' ).removeClass( 'nav-tab-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'nav-tab-active' ).attr( 'aria-selected', 'true' );
		$( '.tsosk-sec-panel' ).prop( 'hidden', true );
		$( '#tsosk-sec-panel-' + tab ).prop( 'hidden', false );
		try {
			window.sessionStorage.setItem( 'tsosk_sec_tab', tab );
		} catch ( e ) {
			// Storage unavailable: the tab just is not remembered.
		}
	}

	function secShowTabForHash() {
		var hash = window.location.hash || '';
		if ( ! $( '.tsosk-sec-tabs' ).length || 2 > hash.length || 0 !== hash.indexOf( '#tsosk-security-' ) ) {
			return false;
		}
		var $target = $( hash );
		var $panel = $target.closest( '.tsosk-sec-panel' );
		if ( $target.length && $panel.length ) {
			secShowTab( String( $panel.attr( 'id' ) ).replace( 'tsosk-sec-panel-', '' ) );
			if ( $target[0].scrollIntoView ) {
				$target[0].scrollIntoView( { block: 'start' } );
			}
			return true;
		}
		return false;
	}

	$( document ).on( 'click', '.tsosk-sec-tab', function ( e ) {
		e.preventDefault();
		secShowTab( $( this ).data( 'tab' ) );
	} );

	$( function () {
		if ( ! $( '.tsosk-sec-tabs' ).length ) {
			return;
		}
		if ( secShowTabForHash() ) {
			return;
		}
		var saved = '';
		try {
			saved = window.sessionStorage.getItem( 'tsosk_sec_tab' ) || '';
		} catch ( e ) {
			saved = '';
		}
		if ( saved ) {
			secShowTab( saved );
		}
	} );

} )( jQuery );
