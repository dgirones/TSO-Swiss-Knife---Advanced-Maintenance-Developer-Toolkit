/**
 * TSO Swiss Knife – View Counter front-end beacon.
 *
 * Fires a tiny anonymous "hit" request after the page has rendered, so it
 * keeps working even when the HTML itself was served from a full-page cache.
 * Always goes through admin-ajax.php — never the REST API — because a
 * logged-in visitor's browser sends WordPress' auth cookies with a same-origin
 * sendBeacon()/fetch() call, and WordPress' own REST layer then requires a
 * valid per-user nonce for any cookie-authenticated REST request (even one
 * whose own permission_callback is public); without that nonce it replies
 * with a 403, which a plain fetch().catch() never notices because it only
 * rejects on network failure, not on HTTP error responses. admin-ajax.php
 * has no such cookie/nonce gate, so it works the same for anonymous visitors
 * and logged-in admins alike. No cookies are set by this script.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.tsoskViewCounter ) {
		return;
	}

	var cfg = window.tsoskViewCounter;

	function sendBeacon( params ) {
		var body = new URLSearchParams( Object.assign( { action: 'tsosk_vc_hit' }, params ) ).toString();

		if ( navigator.sendBeacon ) {
			var blob = new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } );
			if ( navigator.sendBeacon( cfg.ajaxUrl, blob ) ) {
				return;
			}
		}

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
			credentials: 'same-origin',
			keepalive: true
		} ).catch( function () {
			// Nothing more to try for this hit.
		} );
	}

	function trackPageView() {
		if ( ! cfg.trackId || Number( cfg.trackId ) <= 0 || ! cfg.type ) {
			return;
		}
		sendBeacon( { type: cfg.type, id: String( cfg.trackId ) } );
	}

	function trackOutboundClicks() {
		if ( ! cfg.trackLinks ) {
			return;
		}
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( 'a[data-tsosk-vc-url]' ) : null;
			if ( ! link ) {
				return;
			}
			var url = link.getAttribute( 'data-tsosk-vc-url' );
			if ( ! url ) {
				return;
			}
			sendBeacon( { type: 'link', id: '0', url: url } );
		}, true );
	}

	// External links in the theme header (social icons...). Visitors only: the server
	// only flags trackHeaderLinks for logged-out requests and re-checks on every hit.
	function trackHeaderClicks() {
		if ( ! cfg.trackHeaderLinks ) {
			return;
		}
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( 'a[href]' ) : null;
			if ( ! link || link.hasAttribute( 'data-tsosk-vc-url' ) || link.closest( '#wpadminbar' ) ) {
				return;
			}
			var siteHeader = link.closest( 'header, #masthead, .site-header, [role="banner"]' );
			// A <header> inside an article/main (post title block, byline...) is content, not the site header.
			if ( ! siteHeader || siteHeader.closest( 'article, main, .entry-content' ) ) {
				return;
			}
			if ( ! /^https?:$/.test( link.protocol ) || link.hostname === window.location.hostname ) {
				return;
			}
			sendBeacon( { type: 'link', id: '0', url: link.href, src: 'header' } );
		}, true );
	}

	// Downloads: <a download> pointing at a file on this very site, plus normal links to files
	// whose extension is in the configured list (no download attribute needed). The server
	// re-validates the host, the setting and the exclusions (users, roles, IPs) on every hit.
	function trackDownloadClicks() {
		if ( ! cfg.trackDownloads ) {
			return;
		}
		var exts = Array.isArray( cfg.downloadExts ) ? cfg.downloadExts : [];
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( 'a[href]' ) : null;
			if ( ! link || ! /^https?:$/.test( link.protocol ) || link.hostname !== window.location.hostname ) {
				return;
			}
			if ( ! link.hasAttribute( 'download' ) ) {
				var match = /\.([a-z0-9]+)$/i.exec( link.pathname );
				if ( ! match || -1 === exts.indexOf( match[ 1 ].toLowerCase() ) ) {
					return;
				}
			}
			sendBeacon( { type: 'download', id: '0', url: link.href } );
		}, true );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			trackPageView();
			trackOutboundClicks();
			trackHeaderClicks();
			trackDownloadClicks();
		} );
	} else {
		trackPageView();
		trackOutboundClicks();
		trackHeaderClicks();
		trackDownloadClicks();
	}
} )();
