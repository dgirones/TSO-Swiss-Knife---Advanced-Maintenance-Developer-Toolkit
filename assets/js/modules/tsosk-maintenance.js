/**
 * TSO Swiss Knife – Admin JavaScript: maintenance tab.
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

	// Visitor page fields shared by Save and Preview: the logo, the heading and every input marked data-m-field.
	function tsoskMaintFields() {
		var data = {
			logo_id   : $( '#tsosk-m-logo-id' ).val() || 0,
			page_title: $( '#tsosk-m-page-title' ).val() || ''
		};
		$( '[data-m-field]' ).each( function () {
			var $el = $( this );
			data[ $el.attr( 'data-m-field' ) ] = $el.is( ':checkbox' ) ? ( $el.prop( 'checked' ) ? '1' : '' ) : ( $el.val() || '' );
		} );
		return data;
	}

	$( document ).on( 'click', '#tsosk-m-colors-reset', function () {
		$( '.tsosk-maint-color input[type="color"]' ).each( function () {
			$( this ).val( $( this ).attr( 'data-default' ) );
		} );
	} );

	$( document ).on( 'click', '#tsosk-m-sched-clear', function () {
		$( '#tsosk-m-sched-start, #tsosk-m-sched-end' ).val( '' );
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
					var isOn = ( 'undefined' !== typeof r.data.active ) ? r.data.active : r.data.enabled;
					if ( isOn ) {
						$badge.text( tsosk.i18n.on ).removeClass( 'tsosk-badge-ok' ).addClass( 'tsosk-badge-warn' );
					} else {
						$badge.text( tsosk.i18n.off ).removeClass( 'tsosk-badge-warn' ).addClass( 'tsosk-badge-ok' );
					}
					$btn.text( r.data.enabled ? tsosk.i18n.disable : tsosk.i18n.enable );
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

		var saveData = tsoskMaintFields();
		saveData.nonce         = nonce;
		saveData.message       = message;
		saveData.whitelist_ips = ips;

		ajaxPost( {
			action : 'tsosk_maintenance_save',
			data   : saveData,
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
		$.each( tsoskMaintFields(), function ( name, value ) {
			$form.append( $( '<textarea>', { name: name } ).css( 'display', 'none' ).val( value ) );
		} );
		$form.append( $( '<textarea>', { name: 'message' } ).css( 'display', 'none' ).val( message ) );
		window.open( '', 'tsosk_maintenance_preview' );
		$form.appendTo( 'body' ).submit().remove();
	} );

} )( jQuery );
