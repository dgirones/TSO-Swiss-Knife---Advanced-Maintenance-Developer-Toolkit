/**
 * TSO Swiss Knife – Admin JavaScript: view-counter tab.
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
	var tsoskCloseInfoPops = tsoskCore.tsoskCloseInfoPops;


	// ── View Counter ───────────────────────────────────────────────────────

	$( document ).on( 'click', '#tsosk-vc-save', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-vc-settings-msg' );
		var types = [];

		$( '.tsosk-vc-post-type:checked' ).each( function () {
			types.push( $( this ).val() );
		} );

		var roles = [];
		$( '.tsosk-vc-exclude-role:checked' ).each( function () {
			roles.push( $( this ).val() );
		} );

		ajaxPost( {
			action : 'tsosk_vc_save_settings',
			data   : {
				nonce                 : nonce,
				post_types            : types,
				exclude_logged_in     : $( '#tsosk-vc-exclude-logged-in' ).is( ':checked' ) ? 1 : '',
				track_outbound_links  : $( '#tsosk-vc-track-links' ).is( ':checked' ) ? 1 : '',
				track_header_links    : $( '#tsosk-vc-track-header-links' ).is( ':checked' ) ? 1 : '',
				track_downloads       : $( '#tsosk-vc-track-downloads' ).is( ':checked' ) ? 1 : '',
				track_download_links  : $( '#tsosk-vc-track-download-links' ).is( ':checked' ) ? 1 : '',
				download_extensions   : $( '#tsosk-vc-download-extensions' ).val(),
				download_images       : $( '#tsosk-vc-download-images' ).is( ':checked' ) ? 1 : '',
				exclude_roles         : roles,
				exclude_ips           : $( '#tsosk-vc-exclude-ips' ).val(),
				email_weekly_summary  : $( '#tsosk-vc-email-weekly' ).is( ':checked' ) ? 1 : '',
				email_period_days     : $( '#tsosk-vc-email-period' ).val(),
				email_recipient       : $( '#tsosk-vc-email-recipient' ).val()
			},
			success: function ( r ) {
				showMsg( $msg, ( r && r.data ) || tsosk.i18n.done, r && r.success ? 'ok' : 'error' );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	$( document ).on( 'click', '.tsosk-vc-fix-day', function () {
		var $btn = $( this );
		var ask  = $btn.data( 'confirm' );
		if ( ask && ! window.confirm( ask ) ) { // eslint-disable-line no-alert
			return;
		}
		var $msg = $( '#tsosk-vc-fix-msg' );
		$btn.prop( 'disabled', true );
		ajaxPost( {
			action : 'tsosk_vc_fix_day',
			data   : { nonce: $btn.data( 'nonce' ), date: $btn.data( 'date' ), mode: $btn.data( 'mode' ) },
			success: function ( r ) {
				if ( r.success ) {
					window.location.reload();
					return;
				}
				showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-vc-import', function () {
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-vc-import-msg' );
		var source = $( '#tsosk-vc-import-source' ).val();
		var done   = String( $btn.attr( 'data-imported' ) || '' ).split( ',' );
		var force  = '';

		// Importing adds to the counters: confirm before running the same source twice.
		if ( -1 !== $.inArray( source, done ) ) {
			if ( ! window.confirm( tsosk.i18n.vc_import_again_confirm ) ) {
				return;
			}
			force = 1;
		} else if ( '1' === String( $btn.attr( 'data-has-data' ) ) && ! window.confirm( tsosk.i18n.vc_import_has_data_confirm ) ) {
			// No import is recorded, but the counter already holds data (it may come from an earlier import).
			return;
		}

		ajaxPost( {
			action : 'tsosk_vc_import',
			data   : {
				nonce : nonce,
				source: source,
				force : force
			},
			success: function ( r ) {
				// Persistent (non-fading) result so the outcome of the import stays readable.
				var $result = $( '#tsosk-vc-import-result' );
				$result.text( ( r && r.data ) || tsosk.i18n.done ).toggleClass( 'is-error', ! ( r && r.success ) ).prop( 'hidden', false );
				if ( r && r.success ) {
					$btn.attr( 'data-has-data', '1' );
					$btn.attr( 'data-imported', ( String( $btn.attr( 'data-imported' ) || '' ) + ',' + source ).replace( /^,/, '' ) );
					tsooskVcLoadTopContent( $( '.tsosk-vc-period' ).first().closest( '.tsosk-filter-pills' ) );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-vc-reset', function () {
		if ( ! window.confirm( tsosk.i18n.vc_reset_confirm ) ) {
			return;
		}
		var $btn  = $( this );
		var nonce = $btn.data( 'nonce' );
		var $msg  = $( '#tsosk-vc-reset-msg' );

		ajaxPost( {
			action : 'tsosk_vc_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				showMsg( $msg, ( r && r.data ) || tsosk.i18n.done, r && r.success ? 'ok' : 'error' );
				if ( r && r.success ) {
					// Counters are empty again: importing is safe again.
					$( '#tsosk-vc-import' ).attr( { 'data-imported': '', 'data-has-data': '0' } );
					$( '#tsosk-vc-import-result' ).prop( 'hidden', true ).text( '' );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
			}
		} );
	} );

	// View Counter: Top Content report (period pills, built entirely with DOM
	// methods below — never string-concatenated HTML — since row values come
	// from the site's own titles/URLs and must be treated as untrusted text).
	function tsooskVcLoadTopContent( $pills ) {
		var nonce  = $pills.data( 'nonce' );
		var period = $pills.find( '.tsosk-filter-active' ).data( 'period' ) || '30';

		ajaxPost( {
			action : 'tsosk_vc_top_content',
			data   : { nonce: nonce, period: period },
			success: function ( r ) {
				if ( ! r || ! r.success ) {
					return;
				}
				var $postsBody = $( '#tsosk-vc-top-posts-table tbody' ).empty();
				var $linksBody = $( '#tsosk-vc-top-links-table tbody' ).empty();
				var $dlBody    = $( '#tsosk-vc-top-downloads-table tbody' ).empty();

				if ( ! r.data.posts.length ) {
					$postsBody.append( $( '<tr>' ).append( $( '<td colspan="2">' ).text( tsosk.i18n.vc_no_data ) ) );
				}
				$.each( r.data.posts, function ( i, row ) {
					var $link = $( '<a>' ).attr( 'href', row.edit_url ).text( row.title );
					$postsBody.append(
						$( '<tr>' ).append(
							$( '<td>' ).append( $link ),
							$( '<td>' ).text( row.total )
						)
					);
				} );

				if ( ! r.data.links.length ) {
					$linksBody.append( $( '<tr>' ).append( $( '<td colspan="2">' ).text( tsosk.i18n.vc_no_data ) ) );
				}
				$.each( r.data.links, function ( i, row ) {
					var $link = $( '<a>' ).attr( { href: row.url, target: '_blank', rel: 'noopener noreferrer' } ).text( row.url );
					$linksBody.append(
						$( '<tr>' ).append(
							$( '<td>' ).append( $link ),
							$( '<td>' ).text( row.total )
						)
					);
				} );

				var downloads = r.data.downloads || [];
				if ( ! downloads.length ) {
					$dlBody.append( $( '<tr>' ).append( $( '<td colspan="2">' ).text( tsosk.i18n.vc_no_data ) ) );
				}
				$.each( downloads, function ( i, row ) {
					var name  = String( row.url ).split( '/' ).pop() || row.url;
					var $link = $( '<a>' ).attr( { href: row.url, target: '_blank', rel: 'noopener noreferrer', title: row.url } ).text( name );
					$dlBody.append(
						$( '<tr>' ).append(
							$( '<td>' ).append( $link ),
							$( '<td>' ).text( row.total )
						)
					);
				} );
			}
		} );
	}

	$( document ).on( 'click', '.tsosk-vc-period', function () {
		var $pills = $( this ).closest( '.tsosk-filter-pills' );
		$pills.find( '.tsosk-vc-period' ).removeClass( 'tsosk-filter-active' );
		$( this ).addClass( 'tsosk-filter-active' );
		tsooskVcLoadTopContent( $pills );
	} );

	if ( $( '#tsosk-vc-top-posts-table' ).length ) {
		var $vcPills = $( '.tsosk-vc-period' ).first().closest( '.tsosk-filter-pills' );
		// Overview tiles use 7-day totals: open the matching Top Content column with that period.
		var vcHash = window.location.hash || '';
		if ( '#tsosk-vc-top-posts' === vcHash || '#tsosk-vc-top-downloads' === vcHash ) {
			$vcPills.find( '.tsosk-vc-period' ).removeClass( 'tsosk-filter-active' );
			$vcPills.find( '.tsosk-vc-period[data-period="7"]' ).addClass( 'tsosk-filter-active' );
		}
		tsooskVcLoadTopContent( $vcPills );
	}

	window.addEventListener( 'scroll', tsoskCloseInfoPops, true );

} )( jQuery );
