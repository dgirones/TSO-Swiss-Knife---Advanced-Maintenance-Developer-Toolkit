/**
 * TSO Swiss Knife – Admin JavaScript: update-manager tab.
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

	$( document ).on( 'click', '.tsosk-um-install-all', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $btn.nextAll( '.tsosk-um-install-msg' ).first();
		var label = $btn.text();
		var confirmMsg = ( tsosk.i18n.um_install_all_confirm || 'Install the pending updates now?' )
			.replace( '%1$d', String( parseInt( $btn.data( 'plugins' ), 10 ) || 0 ) )
			.replace( '%2$d', String( parseInt( $btn.data( 'themes' ), 10 ) || 0 ) )
			.replace( '%3$d', String( parseInt( $btn.data( 'translations' ), 10 ) || 0 ) );

		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		var done   = [];
		var failed = [];
		var skip   = [];

		$( '.tsosk-um-install-all, .tsosk-um-update-plugin, #tsosk-um-run-updates, #tsosk-um-install-translations' ).prop( 'disabled', true );
		// Keep the button the same width while it says "Running…", so nothing next to it moves.
		$btn.css( 'min-width', $btn.outerWidth() + 'px' ).text( tsosk.i18n.running );

		function finish( errorText ) {
			var text;
			var type = 'ok';
			if ( errorText ) {
				text = errorText;
				type = 'error';
			} else if ( failed.length ) {
				text = ( tsosk.i18n.um_install_all_partial || 'Installed: %1$d. Failed: %2$s' )
					.replace( '%1$d', String( done.length ) )
					.replace( '%2$s', failed.map( function ( f ) { return f.name + ( f.error ? ' (' + f.error + ')' : '' ); } ).join( '; ' ) );
				type = 'error';
			} else if ( done.length ) {
				text = ( tsosk.i18n.um_install_all_done || 'Pending updates installed: %d.' ).replace( '%d', String( done.length ) );
			} else {
				text = tsosk.i18n.um_install_all_none || 'Nothing to install.';
			}
			showMsg( $msg, text, type, 'error' === type ? 15000 : 4000 );
			$btn.text( label );
			if ( ! errorText && ! failed.length ) {
				setTimeout( function () { window.location.reload(); }, 2000 );
			} else {
				$( '.tsosk-um-install-all, .tsosk-um-update-plugin, #tsosk-um-run-updates, #tsosk-um-install-translations' ).prop( 'disabled', false );
			}
		}

		function next( first ) {
			ajaxPost( {
				action : 'tsosk_um_install_next',
				data   : { nonce: nonce, first: first ? 1 : 0, skip: skip.join( ',' ) },
				success: function ( r ) {
					if ( ! r.success ) {
						finish( r.data || tsosk.i18n.error );
						return;
					}
					var item = r.data ? r.data.item : null;
					if ( item ) {
						skip.push( item.key );
						( item.ok ? done : failed ).push( item );
					}
					if ( item && r.data.remaining > 0 ) {
						showMsg( $msg, ( tsosk.i18n.um_install_all_progress || 'Installing… (%d done)' ).replace( '%d', String( done.length ) ), 'ok' );
						next( false );
					} else {
						finish( '' );
					}
				},
				error: function () {
					finish( tsosk.i18n.error );
				}
			} );
		}

		next( true );
	} );

	$( document ).on( 'click', '.tsosk-um-update-plugin', function () {
		var $btn  = $( this );
		var name  = String( $btn.data( 'name' ) || '' );
		var $msg  = $( '#tsosk-um-run-msg' );
		var label = $btn.text();
		var confirmMsg = ( tsosk.i18n.um_update_plugin_confirm || 'Update %s now?' ).replace( '%s', name );

		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$( '.tsosk-um-update-plugin' ).prop( 'disabled', true );
		$btn.text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_um_update_plugin',
			data   : { nonce: $btn.data( 'nonce' ), plugin: $btn.data( 'plugin' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, ( r.data && r.data.message ) ? r.data.message : tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1500 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$( '.tsosk-um-update-plugin' ).prop( 'disabled', false );
					$btn.text( label );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$( '.tsosk-um-update-plugin' ).prop( 'disabled', false );
				$btn.text( label );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-um-install-translations', function () {
		var $btn     = $( this );
		var nonce    = $btn.data( 'nonce' );
		var pending  = parseInt( $btn.data( 'pending' ), 10 ) || 0;
		var $msg     = $( '#tsosk-um-run-msg' );
		var label    = $btn.text();
		var confirmMsg = tsosk.i18n.um_install_translations_confirm;

		if ( pending > 0 && confirmMsg ) {
			confirmMsg = confirmMsg.replace( '%d', String( pending ) );
		}

		if ( ! window.confirm( confirmMsg || label ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		$( '#tsosk-um-run-updates' ).prop( 'disabled', true );
		$btn.text( tsosk.i18n.running );

		ajaxPost( {
			action : 'tsosk_um_install_translations',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					var text = ( r.data && r.data.message ) ? r.data.message : ( r.data || tsosk.i18n.done );
					showMsg( $msg, text, 'ok' );
					setTimeout( function () { window.location.reload(); }, 1800 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( label );
					$( '#tsosk-um-run-updates' ).prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( label );
				$( '#tsosk-um-run-updates' ).prop( 'disabled', false );
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

} )( jQuery );
