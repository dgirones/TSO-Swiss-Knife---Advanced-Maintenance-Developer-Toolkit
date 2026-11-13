/**
 * TSO Swiss Knife – Admin JavaScript: image-sizes-audit tab.
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
				if ( r.success && r.data && r.data.html ) {
					// The button is re-rendered with the results, so the message goes to the fresh copy.
					$( '#tsosk-image-sizes-results' ).html( r.data.html );
					showMsg( $( '#tsosk-image-sizes-save-msg' ), r.data.message || tsosk.i18n.done, 'ok' );
					return;
				}
				showMsg( $msg, r.success ? ( ( r.data && r.data.message ) || r.data || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_save || 'Save image size settings' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( tsosk.i18n.image_sizes_save || 'Save image size settings' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-image-sizes-quarantine', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-image-sizes-delete-msg' );
		var $out  = $( '#tsosk-image-sizes-results' );
		var $qout = $( '#tsosk-image-sizes-quarantine-results' );
		var sizes = [];
		var btnLabel = tsosk.i18n.image_sizes_quarantine || 'Move files for selected sizes to quarantine';

		$( '.tsosk-img-audit-delete-checkbox:checked' ).each( function () {
			var name = $( this ).data( 'name' );
			if ( name ) {
				sizes.push( name );
			}
		} );

		if ( ! sizes.length ) {
			showMsg( $msg, tsosk.i18n.image_sizes_quarantine_none || 'Select at least one size first.', 'error' );
			return;
		}

		var confirmMsg = ( tsosk.i18n.image_sizes_quarantine_confirm || 'Move the existing files for %1$d selected image size(s) to quarantine? They will be kept for 30 days and can be restored, or deleted permanently at any time. Continue?' ).replace( '%1$d', String( sizes.length ) );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		showMsg( $msg, '', '' );

		// The server first reports content that still links to these sizes; the request is repeated once confirmed.
		function send( confirmRefs ) {
			ajaxPost( {
				action : 'tsosk_image_sizes_audit_quarantine',
				data   : { nonce: $btn.data( 'nonce' ), sizes: sizes, confirm_refs: confirmRefs ? 1 : 0 },
				success: function ( r ) {
					if ( r.success && r.data && r.data.needs_confirm ) {
						if ( window.confirm( r.data.message ) ) {
							send( true );
							return;
						}
						$btn.prop( 'disabled', false ).text( btnLabel );
						return;
					}
					if ( r.success ) {
						if ( r.data && r.data.html ) {
							$out.html( r.data.html );
						}
						if ( r.data && r.data.quarantine_html ) {
							$qout.html( r.data.quarantine_html );
						}
						showMsg( $( '#tsosk-image-sizes-delete-msg' ), ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
						$btn.prop( 'disabled', false ).text( btnLabel );
					} else {
						showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
						$btn.prop( 'disabled', false ).text( btnLabel );
					}
				},
				error: function () {
					showMsg( $msg, tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( btnLabel );
				}
			} );
		}
		send( false );
	} );

	$( document ).on( 'click', '.tsosk-img-adopt', function () {
		var $btn = $( this );
		var from = String( $btn.data( 'from' ) );
		var to   = String( $btn.data( 'to' ) );
		var confirmMsg = ( tsosk.i18n.image_sizes_adopt_confirm || 'Make the existing files of "%2$s" (%1$d images) available as "%3$s"? No file is moved, copied or deleted. Continue?' )
			.replace( '%1$d', String( $btn.data( 'files' ) ) )
			.replace( /%2\$s/g, from )
			.replace( /%3\$s/g, to );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		var label = $btn.text();
		$btn.prop( 'disabled', true ).text( tsosk.i18n.running );
		ajaxPost( {
			action : 'tsosk_image_sizes_audit_adopt',
			data   : { nonce: $btn.data( 'nonce' ), from: from, to: to },
			success: function ( r ) {
				if ( r.success && r.data && r.data.html ) {
					$( '#tsosk-image-sizes-results' ).html( r.data.html );
					showMsg( $( '#tsosk-image-sizes-save-msg' ), r.data.message || tsosk.i18n.done, 'ok' );
					return;
				}
				$btn.prop( 'disabled', false ).text( label );
				window.alert( r.success ? ( ( r.data && r.data.message ) || tsosk.i18n.done ) : ( r.data || tsosk.i18n.error ) );
			},
			error: function () {
				$btn.prop( 'disabled', false ).text( label );
				window.alert( tsosk.i18n.error );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-img-quarantine-restore', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-image-sizes-delete-msg' );
		var $out = $( '#tsosk-image-sizes-quarantine-results' );

		if ( ! window.confirm( tsosk.i18n.image_sizes_quarantine_restore_confirm || 'Restore these quarantined files to their original locations?' ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, '' );

		ajaxPost( {
			action : 'tsosk_image_sizes_audit_quarantine_restore',
			data   : {
				nonce    : $btn.data( 'nonce' ),
				entry_id : $btn.data( 'entry-id' )
			},
			success: function ( r ) {
				if ( r.success ) {
					if ( r.data && r.data.quarantine_html ) {
						$out.html( r.data.quarantine_html );
					}
					if ( r.data && r.data.quarantine_card_html ) {
						$( '#tsosk-isa-quarantine-card' ).replaceWith( r.data.quarantine_card_html );
					}
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
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

	$( document ).on( 'click', '.tsosk-img-quarantine-purge', function () {
		var $btn      = $( this );
		var $msg      = $( '#tsosk-image-sizes-delete-msg' );
		var $out      = $( '#tsosk-image-sizes-quarantine-results' );
		var label     = $btn.data( 'label' ) || '';
		var confirmMsg = tsosk.i18n.image_sizes_quarantine_purge_confirm || 'Permanently delete these quarantined files now? This cannot be undone.';

		if ( label ) {
			confirmMsg = label + '\n\n' + confirmMsg;
		}
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		showMsg( $msg, tsosk.i18n.running, '' );

		ajaxPost( {
			action : 'tsosk_image_sizes_audit_quarantine_purge',
			data   : {
				nonce    : $btn.data( 'nonce' ),
				entry_id : $btn.data( 'entry-id' )
			},
			success: function ( r ) {
				if ( r.success ) {
					if ( r.data && r.data.quarantine_html ) {
						$out.html( r.data.quarantine_html );
					}
					if ( r.data && r.data.quarantine_card_html ) {
						$( '#tsosk-isa-quarantine-card' ).replaceWith( r.data.quarantine_card_html );
					}
					showMsg( $msg, ( r.data && r.data.message ) || tsosk.i18n.done, 'ok' );
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

} )( jQuery );
