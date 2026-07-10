<?php
/**
 * Plugin Name: TSO Swiss Knife – Sandbox Loader
 * Description: Must-use loader for per-admin plugin sandbox sessions. Installed automatically by TSO Swiss Knife.
 * Version:     1.0.0
 * Author:      Tu Soporte Online
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option storing active sandbox sessions keyed by token. */
const TSOSK_SANDBOX_SESSIONS_OPTION = 'tsosk_sandbox_sessions';

/** Cookie name for the signed sandbox session. */
const TSOSK_SANDBOX_COOKIE = 'tsosk_sandbox';

/**
 * HMAC secret for cookie signing (falls back when AUTH_KEY is unavailable).
 *
 * @return string
 */
function tsosk_mu_sandbox_secret(): string {
	if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
		return AUTH_KEY;
	}
	return '';
}

/**
 * Build a signed cookie value: token.user_id.signature
 *
 * @param string $token   Session token.
 * @param int    $user_id User ID.
 * @return string
 */
function tsosk_mu_sandbox_sign_cookie( string $token, int $user_id ): string {
	$sig = hash_hmac( 'sha256', $token . '|' . $user_id, tsosk_mu_sandbox_secret() );
	return $token . '.' . $user_id . '.' . $sig;
}

/**
 * Parse and verify the sandbox cookie.
 *
 * @return array{token:string,user_id:int}|null
 */
function tsosk_mu_sandbox_parse_cookie(): ?array {
	if ( empty( $_COOKIE[ TSOSK_SANDBOX_COOKIE ] ) ) {
		return null;
	}

	$raw = sanitize_text_field( wp_unslash( $_COOKIE[ TSOSK_SANDBOX_COOKIE ] ) );
	$parts = explode( '.', $raw );
	if ( 3 !== count( $parts ) ) {
		return null;
	}

	$token   = preg_replace( '/[^a-f0-9]/', '', $parts[0] );
	$user_id = absint( $parts[1] );
	$sig     = preg_replace( '/[^a-f0-9]/', '', $parts[2] );

	if ( strlen( $token ) < 20 || ! $user_id || strlen( $sig ) < 32 ) {
		return null;
	}

	$expected = hash_hmac( 'sha256', $token . '|' . $user_id, tsosk_mu_sandbox_secret() );
	if ( '' === tsosk_mu_sandbox_secret() || ! hash_equals( $expected, $sig ) ) {
		return null;
	}

	return array(
		'token'   => $token,
		'user_id' => $user_id,
	);
}

/**
 * Load a valid sandbox session from the database.
 *
 * @return array{user_id:int,plugins:array<string>,swiss_knife:string}|null
 */
function tsosk_mu_sandbox_get_session(): ?array {
	$cookie = tsosk_mu_sandbox_parse_cookie();
	if ( null === $cookie ) {
		return null;
	}

	$sessions = get_option( TSOSK_SANDBOX_SESSIONS_OPTION, array() );
	if ( ! is_array( $sessions ) || ! isset( $sessions[ $cookie['token'] ] ) ) {
		return null;
	}

	$session = $sessions[ $cookie['token'] ];
	if ( ! is_array( $session ) ) {
		return null;
	}

	if ( absint( $session['user_id'] ?? 0 ) !== $cookie['user_id'] ) {
		return null;
	}

	if ( empty( $session['expires'] ) || time() > (int) $session['expires'] ) {
		tsosk_mu_sandbox_remove_token( $cookie['token'] );
		return null;
	}

	$plugins = isset( $session['plugins'] ) && is_array( $session['plugins'] ) ? $session['plugins'] : array();
	$swiss   = isset( $session['swiss_knife'] ) ? (string) $session['swiss_knife'] : '';

	return array(
		'user_id'      => $cookie['user_id'],
		'plugins'      => $plugins,
		'swiss_knife'  => $swiss,
	);
}

/**
 * Remove one session token from storage.
 *
 * @param string $token Session token.
 */
function tsosk_mu_sandbox_remove_token( string $token ): void {
	$sessions = get_option( TSOSK_SANDBOX_SESSIONS_OPTION, array() );
	if ( ! is_array( $sessions ) || ! isset( $sessions[ $token ] ) ) {
		return;
	}
	unset( $sessions[ $token ] );
	if ( empty( $sessions ) ) {
		delete_option( TSOSK_SANDBOX_SESSIONS_OPTION );
	} else {
		update_option( TSOSK_SANDBOX_SESSIONS_OPTION, $sessions, false );
	}
}

/**
 * Filter active plugins before WordPress loads plugin files.
 *
 * @param mixed $pre Option pre-filter value.
 * @return array|string|null
 */
function tsosk_mu_sandbox_filter_active_plugins( $pre ) {
	$session = tsosk_mu_sandbox_get_session();
	if ( null === $session || empty( $session['plugins'] ) ) {
		return $pre;
	}

	$plugins = array_values( array_unique( array_map( 'strval', $session['plugins'] ) ) );
	$knife   = $session['swiss_knife'];

	if ( $knife && ! in_array( $knife, $plugins, true ) ) {
		$plugins[] = $knife;
	}

	return $plugins;
}

/**
 * Filter network-active plugins on multisite.
 *
 * @param mixed $pre Option pre-filter value.
 * @return array|false|null
 */
function tsosk_mu_sandbox_filter_sitewide_plugins( $pre ) {
	$session = tsosk_mu_sandbox_get_session();
	if ( null === $session || ! is_array( $pre ) || empty( $session['plugins'] ) ) {
		return $pre;
	}

	$allowed = array_fill_keys( $session['plugins'], true );
	if ( ! empty( $session['swiss_knife'] ) ) {
		$allowed[ $session['swiss_knife'] ] = true;
	}

	return array_intersect_key( $pre, $allowed );
}

add_filter( 'pre_option_active_plugins', 'tsosk_mu_sandbox_filter_active_plugins', 1 );
add_filter( 'pre_site_option_active_sitewide_plugins', 'tsosk_mu_sandbox_filter_sitewide_plugins', 1 );
