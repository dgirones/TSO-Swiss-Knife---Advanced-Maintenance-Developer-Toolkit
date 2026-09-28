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

	if ( 'undefined' === typeof window.tsookViewCounter ) {
		return;
	}

	var cfg = window.tsookViewCounter;

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

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			trackPageView();
			trackOutboundClicks();
		} );
	} else {
		trackPageView();
		trackOutboundClicks();
	}
} )();
