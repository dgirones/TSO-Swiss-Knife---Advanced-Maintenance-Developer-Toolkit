<?php
/**
 * TSO Swiss Knife – Support / donation helpers (shared TSO brand).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Support
 */
class TSOSK_Support {

	/**
	 * Current request path, percent-decoded (UTF-8 kept) and sanitized.
	 *
	 * Decoding must happen before sanitize_text_field(): that function strips
	 * percent-encoded octets, which turned "/%C3%B1and%C3%BA/" into "/and/".
	 *
	 * @return string Path such as "/ñandú/", or '' when unavailable.
	 */
	public static function get_request_path_decoded(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below after decoding.
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		return sanitize_text_field( rawurldecode( $path ) );
	}

	/**
	 * Current request URI (path + query) for logs and display, percent-decoded and sanitized.
	 *
	 * @return string
	 */
	public static function get_request_uri_for_display(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized after decoding.
		return sanitize_text_field( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}

	/**
	 * Ko-fi donation URL shown in the admin header and Plugins screen.
	 *
	 * @return string
	 */
	public static function get_kofi_donate_url(): string {
		$default = 'https://ko-fi.com/deadko_cat';

		/**
		 * Filter the Ko-fi donation URL for TSO Swiss Knife.
		 *
		 * @param string $url Donation page URL.
		 */
		return (string) apply_filters( 'tsosk_kofi_donate_url', $default );
	}

	/**
	 * Donate button label for the current plugin admin language.
	 *
	 * @return string
	 */
	public static function get_donate_label(): string {
		$lang = TSOSK_I18n::get_admin_language_key();

		if ( '' === $lang ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			if ( str_starts_with( $locale, 'ca' ) ) {
				$lang = 'ca';
			} elseif ( str_starts_with( $locale, 'es' ) ) {
				$lang = 'es';
			} else {
				$lang = 'en';
			}
		}

		if ( 'ca' === $lang ) {
			return '☕ Dona suport al plugin';
		}
		if ( 'es' === $lang ) {
			return '☕ Apoya este plugin';
		}

		return __( '☕ Support this plugin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Echo the donate link markup for the admin header.
	 */
	public static function render_donate_button(): void {
		?>
		<a class="tsosk-donate-btn"
			href="<?php echo esc_url( self::get_kofi_donate_url() ); ?>"
			target="_blank"
			rel="noopener noreferrer">
			<?php echo esc_html( self::get_donate_label() ); ?>
		</a>
		<?php
	}

	/**
	 * Echo the Auto / Day / Night theme toggle for the admin header.
	 */
	public static function render_theme_toggle(): void {
		?>
		<button type="button" id="tsosk-theme-toggle" class="tsosk-theme-btn" aria-pressed="mixed" title="<?php echo esc_attr__( 'Auto mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
			<span class="tsosk-theme-icon" aria-hidden="true">🌓</span>
			<span class="tsosk-theme-label"><?php echo esc_html__( 'Auto mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
		</button>
		<?php
	}

	/**
	 * WordPress site timezone string (Settings → General).
	 */
	public static function theme_timezone_string(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			return (string) wp_timezone_string();
		}
		return (string) get_option( 'timezone_string', '' );
	}

	/**
	 * Approximate lat/lng for sunrise/sunset, derived from the site timezone.
	 *
	 * Same table as the other TSO plugins so the auto day/night switch happens at the same time everywhere.
	 *
	 * @return array{lat: float, lng: float}
	 */
	public static function theme_coords(): array {
		$tz = self::theme_timezone_string();

		$map = array(
			'Europe/Madrid'                  => array( 40.42, -3.70 ),
			'Europe/Andorra'                 => array( 42.51, 1.52 ),
			'Atlantic/Canary'                => array( 28.29, -16.63 ),
			'Europe/London'                  => array( 51.51, -0.13 ),
			'Europe/Paris'                   => array( 48.86, 2.35 ),
			'Europe/Berlin'                  => array( 52.52, 13.41 ),
			'Europe/Rome'                    => array( 41.90, 12.50 ),
			'Europe/Lisbon'                  => array( 38.72, -9.14 ),
			'Europe/Brussels'                => array( 50.85, 4.35 ),
			'Europe/Amsterdam'               => array( 52.37, 4.90 ),
			'Europe/Zurich'                  => array( 47.37, 8.54 ),
			'Europe/Vienna'                  => array( 48.21, 16.37 ),
			'America/Mexico_City'            => array( 19.43, -99.13 ),
			'America/New_York'               => array( 40.71, -74.01 ),
			'America/Chicago'                => array( 41.88, -87.63 ),
			'America/Denver'                 => array( 39.74, -104.99 ),
			'America/Los_Angeles'            => array( 34.05, -118.24 ),
			'America/Argentina/Buenos_Aires' => array( -34.60, -58.38 ),
			'America/Sao_Paulo'              => array( -23.55, -46.63 ),
			'America/Bogota'                 => array( 4.71, -74.07 ),
			'America/Lima'                   => array( -12.05, -77.04 ),
			'America/Santiago'               => array( -33.45, -70.67 ),
			'America/Caracas'                => array( 10.48, -66.90 ),
			'America/Guayaquil'              => array( -2.17, -79.92 ),
			'America/Panama'                 => array( 8.98, -79.52 ),
			'America/Costa_Rica'             => array( 9.93, -84.08 ),
			'America/Guatemala'              => array( 14.63, -90.51 ),
			'America/Havana'                 => array( 23.11, -82.37 ),
			'America/Puerto_Rico'            => array( 18.47, -66.11 ),
			'America/Santo_Domingo'          => array( 18.49, -69.93 ),
			'Asia/Tokyo'                     => array( 35.68, 139.69 ),
			'Australia/Sydney'               => array( -33.87, 151.21 ),
			'UTC'                            => array( 0.0, 0.0 ),
		);

		if ( isset( $map[ $tz ] ) ) {
			return array(
				'lat' => (float) $map[ $tz ][0],
				'lng' => (float) $map[ $tz ][1],
			);
		}

		$regions = array(
			'Europe/'    => array( 41.39, 2.17 ),
			'America/'   => array( 19.43, -99.13 ),
			'Atlantic/'  => array( 28.29, -16.63 ),
			'Asia/'      => array( 35.68, 139.69 ),
			'Australia/' => array( -33.87, 151.21 ),
			'Pacific/'   => array( -33.87, 151.21 ),
		);
		foreach ( $regions as $prefix => $coords ) {
			if ( 0 === strpos( $tz, $prefix ) ) {
				return array(
					'lat' => (float) $coords[0],
					'lng' => (float) $coords[1],
				);
			}
		}

		// Default: Iberian Peninsula.
		return array(
			'lat' => 41.39,
			'lng' => 2.17,
		);
	}

	/**
	 * Whether it is currently daytime for the site coordinates (sunrise to sunset).
	 *
	 * Used for the initial data-theme in the HTML, before the theme script runs.
	 */
	public static function theme_is_daytime_now(): bool {
		$coords = self::theme_coords();
		$hour   = (int) current_time( 'G' );
		if ( ! function_exists( 'date_sun_info' ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$info = date_sun_info( time(), (float) $coords['lat'], (float) $coords['lng'] );
		if ( ! is_array( $info ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$rise = $info['sunrise'] ?? false;
		$set  = $info['sunset'] ?? false;
		// Polar day/night: sunrise/sunset may be true/false instead of timestamps.
		if ( true === $rise ) {
			return true;
		}
		if ( false === $rise && false === $set ) {
			return false;
		}
		if ( ! is_int( $rise ) || ! is_int( $set ) ) {
			return ( $hour >= 7 && $hour < 20 );
		}
		$now = time();
		return ( $now >= $rise && $now < $set );
	}

	/**
	 * Read a GET field as text: unslash + UTF-8/null-byte cleanup, then sanitize_text_field.
	 *
	 * Use on admin screens after capability checks. Not for JSON or serialized blobs.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	public static function get_query_scalar( string $key ): string {
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Caller is a manage_options admin screen (Search & Replace prefill).
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Raw GET cleaned with wp_check_invalid_utf8 + null-byte strip, then sanitize_text_field below (Search & Replace / URL Doctor prefill callers).
		$raw = wp_unslash( $_GET[ $key ] );
		if ( ! is_string( $raw ) ) {
			if ( is_scalar( $raw ) || null === $raw ) {
				$raw = (string) $raw;
			} else {
				return '';
			}
		}

		$raw     = str_replace( "\0", '', $raw );
		$checked = wp_check_invalid_utf8( $raw, true );
		if ( ! is_string( $checked ) ) {
			return '';
		}

		return sanitize_text_field( $checked );
	}

	/**
	 * Read a POST field as text: unslash + UTF-8/null-byte cleanup.
	 *
	 * Callers must verify nonce/capability before using this helper.
	 * Keeps JSON / serialized shapes (unlike sanitize_text_field).
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	public static function get_post_scalar( string $key ): string {
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce before use.
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Raw POST body cleaned with wp_check_invalid_utf8 + null-byte strip below (must not use sanitize_text_field: destroys JSON/serialized option values).
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) ) {
			if ( is_scalar( $raw ) || null === $raw ) {
				$raw = (string) $raw;
			} else {
				return '';
			}
		}

		$raw     = str_replace( "\0", '', $raw );
		$checked = wp_check_invalid_utf8( $raw, true );
		return is_string( $checked ) ? $checked : '';
	}

	/**
	 * Decode a JSON POST field into an array after UTF-8 cleanup.
	 *
	 * @param string $key POST key.
	 * @return array<mixed>
	 */
	public static function get_post_json_array( string $key ): array {
		return self::decode_json_array( self::get_post_scalar( $key ) );
	}

	/**
	 * Sanitize a scalar string meant for option/meta storage (keeps JSON/serialized shape).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_stored_scalar( $value ): string {
		if ( ! is_string( $value ) ) {
			if ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;
			} else {
				$value = '';
			}
		}

		$value   = str_replace( "\0", '', $value );
		$checked = wp_check_invalid_utf8( $value, true );
		return is_string( $checked ) ? $checked : '';
	}

	/**
	 * Decode a JSON POST body into an array after UTF-8 cleanup.
	 *
	 * @param mixed $raw Unslashed JSON string.
	 * @return array<mixed>
	 */
	public static function decode_json_array( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$raw     = self::sanitize_stored_scalar( $raw );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether the current user may run actions that touch the whole database or the server files.
	 *
	 * On a multisite network "manage_options" belongs to every subsite administrator, but these actions
	 * reach shared tables (users, sitemeta, other sites) and files such as .htaccess, which WordPress itself
	 * reserves for super admins. On a single site it is the usual "manage_options".
	 *
	 * @return bool
	 */
	public static function can_manage_sensitive(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * Plugin files that are active for the current site, including network-activated ones on multisite.
	 *
	 * @return string[] Plugin basenames such as "akismet/akismet.php".
	 */
	public static function get_active_plugin_files(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( 'strval', $active ) ) );
	}

	/** PHP branch => date on which it stops receiving security fixes (php.net supported versions). */
	public const PHP_EOL_DATES = array(
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
	);

	/**
	 * Whether the running PHP branch no longer receives security fixes.
	 *
	 * @return bool
	 */
	public static function is_php_end_of_life(): bool {
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		if ( ! isset( self::PHP_EOL_DATES[ $branch ] ) ) {
			return version_compare( PHP_VERSION, '7.4', '<' );
		}
		return time() > (int) strtotime( self::PHP_EOL_DATES[ $branch ] . ' 23:59:59 UTC' );
	}

	/** Size above which an active debug log is reported as too big, everywhere the plugin shows it. */
	public const DEBUG_LOG_WARN_BYTES = 10 * MB_IN_BYTES;

	/**
	 * Path of the debug log WordPress is writing to right now: a custom string in WP_DEBUG_LOG (for example
	 * the managed log of the Debug tab), otherwise wp-content/debug.log.
	 *
	 * @return string Absolute path (the file may not exist).
	 */
	public static function get_active_debug_log_path(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return wp_normalize_path( WP_DEBUG_LOG );
		}
		return trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'debug.log';
	}

	/**
	 * Debug log files that exist right now.
	 *
	 * That is the log WordPress writes to (see get_active_debug_log_path()) plus wp-content/debug.log: when
	 * WP_DEBUG_LOG points somewhere else (the managed log of the Debug tab) an older wp-content/debug.log is
	 * often still on disk, and the Debug tab lists it, so the Overview and the Health Report must see it too.
	 *
	 * @return array<int, array{path: string, size: int, readable: bool, active: bool}>
	 */
	public static function get_debug_log_files(): array {
		$active = self::get_active_debug_log_path();
		$paths  = array(
			$active,
			trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'debug.log',
		);

		$seen = array();
		$out  = array();
		foreach ( $paths as $path ) {
			if ( '' === $path || ! is_file( $path ) ) {
				continue;
			}
			$real     = realpath( $path );
			$identity = false !== $real ? wp_normalize_path( $real ) : $path;
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;

			$readable = is_readable( $path );
			$out[]    = array(
				'path'     => $path,
				'size'     => $readable ? (int) filesize( $path ) : 0,
				'readable' => $readable,
				'active'   => $path === $active,
			);
		}

		return $out;
	}

	/**
	 * Best-effort visitor IP, shared by every module.
	 *
	 * Uses REMOTE_ADDR by default. Behind a CDN or reverse proxy REMOTE_ADDR is the proxy's address, so
	 * the real header can be named explicitly, either with the TSOSK_TRUSTED_PROXY_HEADER constant
	 * (e.g. 'HTTP_CF_CONNECTING_IP' or 'HTTP_X_FORWARDED_FOR') or with the 'tsosk_trusted_proxy_header'
	 * filter. It is opt-in because those headers can be forged when the origin is reachable directly.
	 *
	 * @return string Valid IPv4/IPv6 address, or an empty string.
	 */
	public static function get_client_ip(): string {

		$header = defined( 'TSOSK_TRUSTED_PROXY_HEADER' ) ? (string) TSOSK_TRUSTED_PROXY_HEADER : '';
		/**
			* Filters the server variable that carries the real client IP behind a trusted proxy.
		*
		* @param string $header Server variable name (empty = use REMOTE_ADDR only).
		 */
		$header = (string) apply_filters( 'tsosk_trusted_proxy_header', $header );
		$header = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', $header ) ?? '' );

		if ( '' !== $header && isset( $_SERVER[ $header ] ) ) {
			$raw   = sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) );
			$parts = array_map( 'trim', explode( ',', $raw ) );
			// X-Forwarded-For can be forged by the client, which only controls the LEFT side of the list: proxies append
			// the address they saw on the right. Walk from the right and take the first public address, so a forged
			// leftmost entry is never trusted. Single-value headers (CF-Connecting-IP) have one entry and work the same.
			$valid = array();
			foreach ( array_reverse( $parts ) as $candidate ) {
				if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				$valid[] = $candidate;
				if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
			if ( ! empty( $valid ) ) {
				return $valid[0]; // Only private addresses (intranet or internal proxies): the rightmost one.
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}
}
