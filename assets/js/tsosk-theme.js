/**
 * TSO Swiss Knife - day / night theme (auto, day, night).
 *
 * Same system as the other TSO plugins: "auto" follows sunrise and sunset for the
 * site coordinates, and the choice is remembered in this browser. Loaded in the
 * <head> so the theme is applied before the page is first painted.
 *
 * Config comes from PHP as window.tsoskThemeConfig ({ lat, lng, i18n }).
 */
( function () {
	'use strict';

	var KEY = 'tsosk_ui_theme';
	var cfg = window.tsoskThemeConfig || {};
	var i18n = cfg.i18n || {};
	var LAT = parseFloat( cfg.lat );
	var LNG = parseFloat( cfg.lng );
	if ( isNaN( LAT ) || isNaN( LNG ) ) {
		LAT = 41.39;
		LNG = 2.17;
	}

	var DAY_MS = 86400000;
	var J1970 = 2440588;
	var J2000 = 2451545;
	var RAD = Math.PI / 180;
	var E = RAD * 23.4397;
	var autoTimer = null;

	function readPref() {
		var p = '';
		try { p = window.localStorage.getItem( KEY ) || ''; } catch ( e ) { p = ''; }
		return ( 'day' === p || 'night' === p || 'auto' === p ) ? p : 'auto';
	}

	function savePref( pref ) {
		try { window.localStorage.setItem( KEY, pref ); } catch ( e ) { /* storage blocked: the choice just won't persist */ }
	}

	/* Sunrise / sunset (SunCalc algorithm, public domain). */
	function toJulian( date ) { return date.valueOf() / DAY_MS - 0.5 + J1970; }
	function fromJulian( j ) { return new Date( ( j + 0.5 - J1970 ) * DAY_MS ); }
	function toDays( date ) { return toJulian( date ) - J2000; }
	function declination( l, b ) {
		return Math.asin( Math.sin( b ) * Math.cos( E ) + Math.cos( b ) * Math.sin( E ) * Math.sin( l ) );
	}
	function solarMeanAnomaly( d ) { return RAD * ( 357.5291 + 0.98560028 * d ); }
	function eclipticLongitude( M ) {
		var C = RAD * ( 1.9148 * Math.sin( M ) + 0.02 * Math.sin( 2 * M ) + 0.0003 * Math.sin( 3 * M ) );
		return M + C + RAD * 102.9372 + Math.PI;
	}
	function julianCycle( d, lw ) { return Math.round( d - 0.0009 - lw / ( 2 * Math.PI ) ); }
	function approxTransit( Ht, lw, n ) { return 0.0009 + ( Ht + lw ) / ( 2 * Math.PI ) + n; }
	function solarTransitJ( ds, M, L ) { return J2000 + ds + 0.0053 * Math.sin( M ) - 0.0069 * Math.sin( 2 * L ); }
	function hourAngle( h, phi, d ) {
		return Math.acos( ( Math.sin( h ) - Math.sin( phi ) * Math.sin( d ) ) / ( Math.cos( phi ) * Math.cos( d ) ) );
	}

	function sunTimes( date ) {
		try {
			var lw = RAD * -LNG;
			var phi = RAD * LAT;
			var d = toDays( date );
			var n = julianCycle( d, lw );
			var ds = approxTransit( 0, lw, n );
			var M = solarMeanAnomaly( ds );
			var L = eclipticLongitude( M );
			var dec = declination( L, 0 );
			var Jnoon = solarTransitJ( ds, M, L );
			var w = hourAngle( -0.833 * RAD, phi, dec );
			var Jset = solarTransitJ( approxTransit( w, lw, n ), M, L );
			var Jrise = Jnoon - ( Jset - Jnoon );
			var sunrise = fromJulian( Jrise );
			var sunset = fromJulian( Jset );
			if ( isNaN( sunrise.getTime() ) || isNaN( sunset.getTime() ) ) {
				return null;
			}
			return { sunrise: sunrise, sunset: sunset };
		} catch ( e ) {
			return null;
		}
	}

	function fromSolar() {
		var now = new Date();
		var times = sunTimes( now );
		if ( ! times ) {
			var h = now.getHours();
			return ( h >= 7 && h < 20 ) ? 'day' : 'night';
		}
		return ( now >= times.sunrise && now < times.sunset ) ? 'day' : 'night';
	}

	function resolve( pref ) {
		return ( 'day' === pref || 'night' === pref ) ? pref : fromSolar();
	}

	function clock( date ) {
		try {
			return date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) {
			return ( date.getHours() < 10 ? '0' : '' ) + date.getHours() + ':' + ( date.getMinutes() < 10 ? '0' : '' ) + date.getMinutes();
		}
	}

	function text( key, fallback ) {
		return i18n[ key ] ? i18n[ key ] : fallback;
	}

	function updateButton( pref, resolved ) {
		var btn = document.getElementById( 'tsosk-theme-toggle' );
		if ( ! btn ) {
			return;
		}
		var label = 'auto' === pref ? text( 'auto', 'Auto mode' ) : ( 'day' === pref ? text( 'day', 'Day mode' ) : text( 'night', 'Night mode' ) );
		var icon = 'auto' === pref ? '🌓' : ( 'night' === resolved ? '🌙' : '☀️' );
		var title = label;
		if ( 'auto' === pref ) {
			title += ' — ' + text( 'autoHint', 'Follows sunrise and sunset (changes with the seasons)' );
			var times = sunTimes( new Date() );
			if ( times ) {
				title += ' · ' + clock( times.sunrise ) + '–' + clock( times.sunset );
			}
		}
		btn.setAttribute( 'aria-pressed', 'auto' === pref ? 'mixed' : ( 'night' === resolved ? 'true' : 'false' ) );
		btn.setAttribute( 'title', title );
		var iconEl = btn.querySelector( '.tsosk-theme-icon' );
		var labelEl = btn.querySelector( '.tsosk-theme-label' );
		if ( iconEl ) { iconEl.textContent = icon; }
		if ( labelEl ) { labelEl.textContent = label; }
	}

	function apply() {
		var pref = readPref();
		var resolved = resolve( pref );
		var root = document.documentElement;
		root.setAttribute( 'data-tsosk-theme', resolved );
		root.setAttribute( 'data-tsosk-theme-pref', pref );
		var wraps = document.querySelectorAll( '.tsosk-wrap' );
		for ( var i = 0; i < wraps.length; i++ ) {
			wraps[ i ].setAttribute( 'data-theme', resolved );
			wraps[ i ].setAttribute( 'data-theme-pref', pref );
		}
		updateButton( pref, resolved );

		// In auto mode, re-check every minute so the page flips at dusk/dawn without a reload.
		if ( autoTimer ) {
			window.clearInterval( autoTimer );
			autoTimer = null;
		}
		if ( 'auto' === pref ) {
			autoTimer = window.setInterval( function () {
				if ( 'auto' === readPref() && root.getAttribute( 'data-tsosk-theme' ) !== fromSolar() ) {
					apply();
				}
			}, 60000 );
		}
	}

	function nextPref( current ) {
		if ( 'auto' === current ) { return 'day'; }
		if ( 'day' === current ) { return 'night'; }
		return 'auto';
	}

	// Before first paint: the <html> attribute is what the stylesheet keys on.
	apply();

	document.addEventListener( 'DOMContentLoaded', function () {
		apply();
		var btn = document.getElementById( 'tsosk-theme-toggle' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				savePref( nextPref( readPref() ) );
				apply();
			} );
		}
	} );
}() );
