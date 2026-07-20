<?php
/**
 * TSO Swiss Knife – Sandbox MU-plugin installer and session manager.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Sandbox_Mu
 */
class TSOSK_Sandbox_Mu {

	/** MU-plugin filename (must match mu-plugin/tsosk-sandbox-loader.php). */
	public const MU_FILENAME = 'tsosk-sandbox-loader.php';

	/** Option storing sessions keyed by token. */
	public const SESSIONS_OPTION = 'tsosk_sandbox_sessions';

	/** User meta: selected plugin list. */
	public const META_PLUGINS = 'tsosk_sandbox_plugins';

	/** User meta: active session token. */
	public const META_TOKEN = 'tsosk_sandbox_token';

	/** Signed session cookie name. */
	public const COOKIE_NAME = 'tsosk_sandbox';

	/** Session lifetime in seconds (7 days). */
	public const SESSION_TTL = 604800;

	/**
	 * Whether the sandbox MU loader file exists.
	 *
	 * @return bool
	 */
	public static function is_loader_installed(): bool {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return false;
		}
		return file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILENAME );
	}

	/**
	 * Install or refresh the MU-plugin loader from the bundled template.
	 *
	 * Uses the WordPress Filesystem API for writes outside uploads.
	 *
	 * @return true|WP_Error
	 */
	public static function install_loader() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return new WP_Error( 'no_mu_dir', __( 'Must-use plugins directory is not available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$source = TSOSK_PATH . 'mu-plugin/' . self::MU_FILENAME;
		$dest   = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILENAME;

		if ( ! file_exists( $source ) ) {
			return new WP_Error( 'missing_template', __( 'Sandbox loader template is missing from the plugin package.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			tsosk_require_wp_admin( 'includes/file.php' );
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new WP_Error( 'fs_unavailable', __( 'Could not initialise the WordPress filesystem.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! $wp_filesystem->is_dir( WPMU_PLUGIN_DIR ) && ! $wp_filesystem->mkdir( WPMU_PLUGIN_DIR ) ) {
			return new WP_Error( 'mu_not_writable', __( 'Could not create the must-use plugins directory.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read bundled template before FS write.
		$content = file_get_contents( $source );
		if ( false === $content ) {
			return new WP_Error( 'read_failed', __( 'Could not read the sandbox loader template.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! $wp_filesystem->put_contents( $dest, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'write_failed', __( 'Could not install the sandbox loader in must-use plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return true;
	}

	/**
	 * Remove the MU loader when no sessions remain.
	 *
	 * @return void
	 */
	public static function maybe_remove_loader(): void {
		if ( ! self::is_loader_installed() ) {
			return;
		}

		$sessions = get_option( self::SESSIONS_OPTION, array() );
		if ( is_array( $sessions ) && ! empty( $sessions ) ) {
			return;
		}

		$path = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILENAME;
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Remove the MU loader unconditionally (uninstall/deactivate cleanup).
	 */
	public static function remove_loader(): void {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}
		$path = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILENAME;
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Start a sandbox session for a user.
	 *
	 * @param int           $user_id User ID.
	 * @param array<string> $plugins Plugin basenames.
	 * @return true|WP_Error
	 */
	public static function start_session( int $user_id, array $plugins ) {
		if ( ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return new WP_Error(
				'tsosk_no_auth_key',
				__( 'Plugin sandbox requires AUTH_KEY in wp-config.php. Generate secure keys before using sandbox mode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		$install = self::install_loader();
		if ( is_wp_error( $install ) ) {
			return $install;
		}

		self::end_session( $user_id );

		$token = bin2hex( random_bytes( 16 ) );
		$sessions = get_option( self::SESSIONS_OPTION, array() );
		if ( ! is_array( $sessions ) ) {
			$sessions = array();
		}

		$sessions[ $token ] = array(
			'user_id'      => $user_id,
			'plugins'      => array_values( $plugins ),
			'swiss_knife'  => TSOSK_BASENAME,
			'expires'      => time() + self::SESSION_TTL,
			'started'      => time(),
		);

		update_option( self::SESSIONS_OPTION, $sessions, false );
		update_user_meta( $user_id, self::META_PLUGINS, $plugins );
		update_user_meta( $user_id, self::META_TOKEN, $token );

		self::set_session_cookie( $token, $user_id );

		return true;
	}

	/**
	 * End sandbox for one user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function end_session( int $user_id ): void {
		$token = get_user_meta( $user_id, self::META_TOKEN, true );
		if ( is_string( $token ) && '' !== $token ) {
			self::remove_token( $token );
		}

		delete_user_meta( $user_id, self::META_PLUGINS );
		delete_user_meta( $user_id, self::META_TOKEN );
		self::clear_session_cookie();

		self::maybe_remove_loader();
	}

	/**
	 * Remove all sandbox sessions and the MU loader.
	 */
	public static function purge_all(): void {
		delete_option( self::SESSIONS_OPTION );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
				self::META_PLUGINS,
				self::META_TOKEN
			)
		);

		self::clear_session_cookie();
		self::remove_loader();
	}

	/**
	 * Delete one token from session storage.
	 *
	 * @param string $token Session token.
	 */
	private static function remove_token( string $token ): void {
		$sessions = get_option( self::SESSIONS_OPTION, array() );
		if ( ! is_array( $sessions ) ) {
			return;
		}
		unset( $sessions[ $token ] );
		if ( empty( $sessions ) ) {
			delete_option( self::SESSIONS_OPTION );
		} else {
			update_option( self::SESSIONS_OPTION, $sessions, false );
		}
	}

	/**
	 * Set the signed sandbox cookie.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id User ID.
	 */
	private static function set_session_cookie( string $token, int $user_id ): void {
		$value  = self::sign_cookie( $token, $user_id );
		$secure = is_ssl();
		$expire = time() + self::SESSION_TTL;

		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expire,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// Make the cookie available on the current request too.
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	/**
	 * Clear the sandbox cookie.
	 */
	public static function clear_session_cookie(): void {
		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	/**
	 * Build signed cookie payload.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	private static function sign_cookie( string $token, int $user_id ): string {
		if ( ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return '';
		}
		$sig = hash_hmac( 'sha256', $token . '|' . $user_id, AUTH_KEY );
		return $token . '.' . $user_id . '.' . $sig;
	}
}
