/**
 * TSO Swiss Knife – Admin JavaScript: site-snapshot tab.
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


	// ── Site Snapshot import ──────────────────────────────────────────────────
	function tsoskSnapshotRefreshEnvDiff( data ) {
		var $box   = $( '#tsosk-snapshot-env-diff' );
		var $tbody = $( '#tsosk-snapshot-env-diff-table tbody' );
		var cur    = ( typeof tsosk !== 'undefined' && tsosk.snapshot_current_env ) ? tsosk.snapshot_current_env : {};
		var i18n   = ( typeof tsosk !== 'undefined' && tsosk.i18n ) ? tsosk.i18n : {};

		$tbody.empty();
		if ( ! data || 'tsosk-site-snapshot' !== data.format ) {
			$box.prop( 'hidden', true );
			return;
		}

		var fields = [
			{ label: i18n.snapshot_env_site_url || 'Site URL', file: data.site_url || '', current: cur.site_url || '', kind: 'url' },
			{ label: i18n.snapshot_env_wp_version || 'WordPress version', file: data.wp_version || '', current: cur.wp_version || '', kind: 'text' },
			{ label: i18n.snapshot_env_plugin || 'Snapshot schema', file: String( data.version || '' ), current: String( cur.schema_version || '' ), kind: 'text' },
			{ label: i18n.snapshot_env_php || 'PHP version', file: data.php_version || '', current: cur.php_version || '', kind: 'text' },
			{ label: i18n.snapshot_env_locale || 'Locale', file: data.locale || '', current: cur.locale || '', kind: 'locale' }
		];

		fields.forEach( function ( f ) {
			if ( ! f.file && ! f.current ) {
				return;
			}
			var left  = String( f.file || '' );
			var right = String( f.current || '' );
			if ( 'url' === f.kind ) {
				left  = left.replace( /\/+$/, '' ).toLowerCase();
				right = right.replace( /\/+$/, '' ).toLowerCase();
			} else if ( 'locale' === f.kind ) {
				left  = left.toLowerCase().replace( /-/g, '_' );
				right = right.toLowerCase().replace( /-/g, '_' );
			}
			var diff = left !== right;
			var $tr  = $( '<tr></tr>' );
			if ( diff ) {
				$tr.addClass( 'tsosk-snapshot-env-diff-warn' );
			}
			$tr.append( $( '<td></td>' ).text( f.label ) );
			$tr.append( $( '<td></td>' ).text( f.file || '—' ) );
			$tr.append( $( '<td></td>' ).text( f.current || '—' ) );
			$tbody.append( $tr );
		} );

		if ( $tbody.children().length ) {
			$box.prop( 'hidden', false );
		} else {
			$box.prop( 'hidden', true );
		}
	}

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
			tsoskSnapshotRefreshEnvDiff( null );
			return;
		}

		var data;
		try {
			data = JSON.parse( json );
		} catch ( e ) {
			$box.hide();
			tsoskSnapshotRefreshEnvDiff( null );
			return;
		}

		tsoskSnapshotRefreshEnvDiff( data );

		var sections = data.sections || {};
		var keys = Object.keys( sections );
		if ( ! keys.length ) {
			$box.hide();
			return;
		}

		keys.forEach( function ( id ) {
			var label = labels[ id ] || id;
			var $li = $( '<li style="margin-bottom:6px;"></li>' );
			var $label = $( '<label></label>' );
			var operational = ( typeof tsosk !== 'undefined' && tsosk.snapshot_operational ) ? tsosk.snapshot_operational : [];
			var $cb = $( '<input type="checkbox" class="tsosk-snapshot-import-section">' ).val( id );
			// Operational logs (lockouts, attempts, manifests) are unchecked by default, like on export.
			$cb.prop( 'checked', operational.indexOf( id ) === -1 );
			$label.append( $cb ).append( document.createTextNode( ' ' + String( label ) ) );
			$li.append( $label );
			$list.append( $li );
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

	$( document ).on( 'click', '#tsosk-snapshot-file-btn', function () {
		$( '#tsosk-snapshot-file' ).trigger( 'click' );
	} );

	$( document ).on( 'change', '#tsosk-snapshot-file', function ( e ) {
		var file = e.target.files && e.target.files[0];
		var $msg = $( '#tsosk-snapshot-msg' );
		var $name = $( '#tsosk-snapshot-file-name' );
		var noneLabel = ( tsosk.i18n && tsosk.i18n.snapshot_no_file ) ? tsosk.i18n.snapshot_no_file : '';
		if ( ! file ) {
			if ( noneLabel ) {
				$name.text( noneLabel );
			}
			return;
		}
		$name.text( file.name );
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

	// Show the "Undo last import" card right after an import (it only exists on the page once a backup is saved).
	function tsoskSnapshotRefreshRestoreCard() {
		$.ajax( { url: window.location.href, cache: false, dataType: 'html' } ).done( function ( html ) {
			var $new = $( '<div/>' ).append( $.parseHTML( html ) ).find( '.tsosk-snapshot-restore-card' ).first();
			$( '.tsosk-snapshot-restore-card' ).remove();
			if ( $new.length ) {
				$( '.tsosk-snapshot-import-card' ).before( $new );
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-snapshot-import', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-snapshot-msg' );
		var json = $( '#tsosk-snapshot-json' ).val().trim();
		if ( ! json ) {
			showMsg( $msg, tsosk.i18n.error, 'error' );
			return;
		}
		if ( ! $( '#tsosk-snapshot-import-sections' ).is( ':visible' ) ) {
			showMsg( $msg, tsosk.i18n.snapshot_invalid_json || tsosk.i18n.error, 'error' );
			return;
		}
		if ( ! window.confirm( tsosk.i18n.snapshot_confirm_import ) ) { return; }

		var importSections = [];
		$( '.tsosk-snapshot-import-section:checked' ).each( function () {
			importSections.push( $( this ).val() );
		} );
		if ( importSections.length < 1 ) {
			window.alert( tsosk.i18n.snapshot_no_sections );
			return;
		}

		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_snapshot_import',
			data   : { nonce: $btn.data( 'nonce' ), snapshot: json, import_sections: importSections },
			success: function ( r ) {
				if ( r.success ) {
					$( '#tsosk-snapshot-json' ).val( '' );
					$( '#tsosk-snapshot-file' ).val( '' );
					$( '#tsosk-snapshot-file-name' ).text( tsosk.i18n.snapshot_no_file || '' );
					$( '#tsosk-snapshot-import-sections' ).hide();
					$( '#tsosk-snapshot-import-list' ).empty();
					tsoskSnapshotRefreshEnvDiff( null );
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					tsoskSnapshotRefreshRestoreCard();
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

	$( document ).on( 'click', '#tsosk-snapshot-restore', function () {
		var $btn = $( this );
		var $msg = $( '#tsosk-snapshot-restore-msg' );
		if ( ! window.confirm( tsosk.i18n.snapshot_confirm_restore ) ) { return; }
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_snapshot_restore',
			data   : { nonce: $btn.data( 'nonce' ) },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					$btn.closest( '.tsosk-snapshot-restore-card' ).find( 'button, .description' ).hide();
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
