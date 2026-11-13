/**
 * TSO Swiss Knife – Admin JavaScript: roles tab.
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
	var tsoskUsersFill = tsoskCore.tsoskUsersFill;


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

	var tsoskRolesSeq = 0;
	function tsoskRolesLoad() {
		var $tbody = $( '#tsosk-roles-tbody' );
		var $msg   = $( '#tsosk-roles-msg' );
		var nonce  = $( '#tsosk-roles-nonce' ).val();
		var role   = $( '#tsosk-roles-select' ).val();
		if ( ! $tbody.length || ! nonce ) { return; }
		var seq = ++tsoskRolesSeq;

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
				if ( seq !== tsoskRolesSeq ) {
					return;
				}
				var caps = r.data.caps || [];
				var isAdmin = role === 'administrator' || r.data.read_only;
				$( '#tsosk-roles-count' ).text( caps.length );
				$( '#tsosk-roles-info' ).text( r.data.summary || '' );
				$( '.tsosk-roles-editonly' ).toggle( ! isAdmin );
				$( '#tsosk-roles-admin-note' ).prop( 'hidden', ! isAdmin );
				$( '#tsosk-roles-clone' ).prop( 'disabled', isAdmin );
				if ( ! caps.length ) {
					$tbody.html( '<tr><td colspan="3">' + tsoskText( tsosk.i18n.no_matches ) + '</td></tr>' );
					return;
				}
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
			},
			error: function () {
				if ( seq !== tsoskRolesSeq ) {
					return;
				}
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
		var cap   = String( $( this ).attr( 'data-cap' ) || '' );
		if ( 'read' === cap && ! window.confirm( tsosk.i18n.roles_remove_read_confirm ) ) { return; }
		if ( ! window.confirm( tsosk.i18n.confirm_delete ) ) { return; }
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
		var nonce  = $( '#tsosk-roles-nonce' ).val();
		var $out   = $( '#tsosk-roles-compare-out' );
		var $msg   = $( '#tsosk-roles-compare-msg' );
		var $a     = $( '#tsosk-roles-compare-a' );
		var $b     = $( '#tsosk-roles-compare-b' );
		var nameA  = $a.find( 'option:selected' ).text();
		var nameB  = $b.find( 'option:selected' ).text();
		var none   = tsosk.i18n.roles_none || '—';
		$msg.text( '' );
		ajaxPost( {
			action : 'tsosk_roles_compare',
			data   : {
				nonce: nonce,
				role_a_slug: tsoskEncodeRoleSlug( $a.val() ),
				role_b_slug: tsoskEncodeRoleSlug( $b.val() )
			},
			success: function ( r ) {
				if ( ! r.success ) {
					$out.hide();
					showMsg( $msg, ( typeof r.data === 'string' && r.data ) || tsosk.i18n.error, 'error' );
					return;
				}
				var d = r.data || {};
				var list = function ( items ) { return ( items && items.length ) ? items.join( ', ' ) : none; };
				var text = tsoskUsersFill( tsosk.i18n.roles_only_in, nameA ) + ':\n' + list( d.only_a ) + '\n\n'
					+ tsoskUsersFill( tsosk.i18n.roles_only_in, nameB ) + ':\n' + list( d.only_b ) + '\n\n'
					+ ( tsosk.i18n.roles_both || 'In both' ) + ':\n' + list( d.both );
				$out.text( text ).show();
			},
			error: function () {
				$out.hide();
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-clone', function () {
		var slug = $( '#tsosk-roles-clone-slug' ).val().trim();
		var label = $( '#tsosk-roles-clone-label' ).val().trim();
		var $msg = $( '#tsosk-roles-clone-msg' );
		if ( ! slug || ! label ) {
			showMsg( $msg, tsosk.i18n.roles_clone_required, 'error' );
			return;
		}
		ajaxPost( {
			action : 'tsosk_roles_clone',
			data   : {
				nonce: $( '#tsosk-roles-nonce' ).val(),
				source_slug: tsoskEncodeRoleSlug( $( '#tsosk-roles-select' ).val() ),
				new_role: slug,
				new_label: label
			},
			success: function ( r ) {
				var info = r.success && r.data && 'object' === typeof r.data ? r.data : null;
				showMsg( $msg, r.success ? ( info ? info.message : r.data ) : ( r.data || tsosk.i18n.error ), r.success ? 'ok' : 'error' );
				if ( info && info.role ) {
					// Make the new role available in every role list without reloading.
					$( '#tsosk-roles-select, #tsosk-roles-compare-a, #tsosk-roles-compare-b' ).each( function () {
						$( '<option></option>' ).val( info.role ).text( $( this ).is( '#tsosk-roles-select' ) ? info.label + ' (' + info.role + ')' : info.label ).appendTo( this );
					} );
					$( '#tsosk-roles-clone-slug, #tsosk-roles-clone-label' ).val( '' );
				}
			},
			error: function () { showMsg( $msg, tsosk.i18n.error, 'error' ); }
		} );
	} );

	$( document ).on( 'click', '#tsosk-roles-apply-template', function () {
		if ( ! confirm( tsosk.i18n.roles_apply_confirm || tsosk.i18n.confirm_delete ) ) {
			return;
		}
		var $msg = $( '#tsosk-roles-template-msg' );
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

} )( jQuery );
