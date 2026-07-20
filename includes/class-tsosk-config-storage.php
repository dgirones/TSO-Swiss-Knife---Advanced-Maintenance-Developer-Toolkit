<?php
/**
 * TSO Swiss Knife – JSON config storage in uploads (WordPress.org compliant).
 *
 * Replaces legacy auto-generated PHP flag files with non-executable JSON.
 * Early constants are applied when the main plugin file loads.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Config_Storage
 */
class TSOSK_Config_Storage {

	public const DEBUG_JSON     = 'tsosk-debug-flags.json';
	public const SECURITY_JSON  = 'tsosk-security-flags.json';
	public const PROFILES_JSON  = 'tsosk-profiles-flags.json';

	public const LEGACY_DEBUG    = 'tsosk-debug-flags.php';
	public const LEGACY_SECURITY = 'tsosk-security-flags.php';
	public const LEGACY_PROFILES = 'tsosk-profiles-flags.php';

	/**
	 * Absolute path to the writable config directory under uploads/{plugin-slug}/config.
	 *
	 * @return string
	 */
	public static function get_dir(): string {
		if ( defined( 'TSOSK_CONFIG_DIR' ) && '' !== TSOSK_CONFIG_DIR ) {
			return TSOSK_CONFIG_DIR;
		}
		if ( function_exists( 'tsosk_get_uploads_subdir' ) ) {
			$dir = tsosk_get_uploads_subdir( 'config' );
			if ( '' !== $dir ) {
				return $dir;
			}
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) && empty( $uploads['error'] ) ) {
			$slug = defined( 'TSOSK_UPLOADS_SLUG' ) ? TSOSK_UPLOADS_SLUG : 'tso-swiss-knife-advanced-maintenance-developer-toolkit';
			return trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . $slug . '/config';
		}
		return '';
	}

	/**
	 * Legacy short uploads folders used before the plugin-slug layout.
	 *
	 * @return string[] Absolute paths that may still contain data.
	 */
	public static function get_legacy_upload_dirs(): array {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			return array();
		}
		$base = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		return array(
			'config' => $base . 'tsosk-config',
			'logs'   => $base . 'tsosk-logs',
			'l10n'   => $base . 'tsosk-l10n',
		);
	}

	/**
	 * Apply debug, security and profile constants from JSON (and legacy PHP once).
	 */
	public static function apply_early_constants(): void {
		self::maybe_migrate_legacy_configs();
		self::apply_debug_constants();
		self::apply_security_constants();
		self::apply_profiles_constants();
	}

	/**
	 * Runtime hooks that cannot be expressed as define() calls.
	 */
	public static function apply_runtime_hooks(): void {
		$data = self::read_json( self::SECURITY_JSON );
		if ( ! empty( $data['force_ssl_admin_override_off'] ) ) {
			add_filter( 'force_ssl_admin', '__return_false', 99 );
		}
	}

	/**
	 * @param string $filename JSON filename inside the config directory.
	 * @return array<string, mixed>
	 */
	public static function read_json( string $filename ): array {
		$path = self::path_for( $filename );
		if ( ! is_readable( $path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param string               $filename JSON filename.
	 * @param array<string, mixed> $data     Payload.
	 * @return true|WP_Error
	 */
	public static function write_json( string $filename, array $data ) {
		$dir = self::get_dir();
		if ( ! self::ensure_dir( $dir ) ) {
			return new WP_Error( 'not_writable', __( 'The config directory is not writable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'encode_failed', __( 'Could not encode config data.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$path = self::path_for( $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- non-executable JSON in uploads.
		if ( false === file_put_contents( $path, $json . "\n" ) ) {
			return new WP_Error( 'write_failed', __( 'Could not write the config file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		return true;
	}

	/**
	 * @param string $filename JSON filename.
	 * @return true
	 */
	public static function delete_json( string $filename ): bool {
		$path = self::path_for( $filename );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		return true;
	}

	/**
	 * Whether a JSON config file exists.
	 *
	 * @param string $filename JSON filename.
	 * @return bool
	 */
	public static function json_exists( string $filename ): bool {
		return file_exists( self::path_for( $filename ) );
	}

	/**
	 * @param array{debug:bool,log:bool,display:bool,script:bool,queries:bool} $flags Debug flags.
	 * @return true|WP_Error
	 */
	public static function save_debug_flags( array $flags ) {
		$any_on = $flags['debug'] || $flags['log'] || $flags['display'] || $flags['script'] || $flags['queries'];
		if ( ! $any_on ) {
			self::delete_json( self::DEBUG_JSON );
			self::delete_legacy( self::LEGACY_DEBUG );
			return true;
		}
		return self::write_json(
			self::DEBUG_JSON,
			array(
				'version' => 1,
				'flags'   => array(
					'WP_DEBUG'         => (bool) $flags['debug'],
					'WP_DEBUG_LOG'     => (bool) $flags['log'],
					'WP_DEBUG_DISPLAY' => (bool) $flags['display'],
					'SCRIPT_DEBUG'     => (bool) $flags['script'],
					'SAVEQUERIES'      => (bool) $flags['queries'],
				),
			)
		);
	}

	/**
	 * @return array{debug:bool,log:bool,display:bool,script:bool,queries:bool}
	 */
	public static function get_debug_flags(): array {
		$defaults = array(
			'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script'  => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'queries' => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
		);
		$data = self::read_json( self::DEBUG_JSON );
		if ( empty( $data['flags'] ) || ! is_array( $data['flags'] ) ) {
			return $defaults;
		}
		$map = array(
			'debug'   => 'WP_DEBUG',
			'log'     => 'WP_DEBUG_LOG',
			'display' => 'WP_DEBUG_DISPLAY',
			'script'  => 'SCRIPT_DEBUG',
			'queries' => 'SAVEQUERIES',
		);
		$out = array();
		foreach ( $map as $key => $constant ) {
			$out[ $key ] = array_key_exists( $constant, $data['flags'] )
				? (bool) $data['flags'][ $constant ]
				: $defaults[ $key ];
		}
		return $out;
	}

	/**
	 * @param array<string,bool> $flags Security constants.
	 * @param bool               $force_ssl_override_off Whether to disable FORCE_SSL_ADMIN via filter.
	 * @return true|WP_Error
	 */
	public static function save_security_flags( array $flags, bool $force_ssl_override_off = false ) {
		$any_on = in_array( true, array_values( $flags ), true ) || $force_ssl_override_off;
		if ( ! $any_on ) {
			self::delete_json( self::SECURITY_JSON );
			self::delete_legacy( self::LEGACY_SECURITY );
			return true;
		}
		$constants = array();
		foreach ( $flags as $constant => $enabled ) {
			if ( $enabled ) {
				$constants[ $constant ] = true;
			}
		}
		return self::write_json(
			self::SECURITY_JSON,
			array(
				'version'                     => 1,
				'constants'                   => $constants,
				'force_ssl_admin_override_off' => $force_ssl_override_off,
			)
		);
	}

	/**
	 * @return array{constants:array<string,bool>,force_ssl_admin_override_off:bool}
	 */
	public static function get_security_flags(): array {
		$data = self::read_json( self::SECURITY_JSON );
		$constants = array(
			'DISALLOW_FILE_EDIT' => false,
			'DISALLOW_FILE_MODS' => false,
			'FORCE_SSL_ADMIN'    => false,
		);
		if ( ! empty( $data['constants'] ) && is_array( $data['constants'] ) ) {
			foreach ( array_keys( $constants ) as $name ) {
				$constants[ $name ] = ! empty( $data['constants'][ $name ] );
			}
		}
		return array(
			'constants'                    => $constants,
			'force_ssl_admin_override_off' => ! empty( $data['force_ssl_admin_override_off'] ),
		);
	}

	/**
	 * @param array<string, mixed> $constants Profile constants.
	 * @return true|WP_Error
	 */
	public static function save_profiles_constants( array $constants ) {
		$payload = array();
		foreach ( $constants as $constant => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}
			$payload[ $constant ] = $value;
		}
		if ( empty( $payload ) ) {
			self::delete_json( self::PROFILES_JSON );
			self::delete_legacy( self::LEGACY_PROFILES );
			return true;
		}
		return self::write_json(
			self::PROFILES_JSON,
			array(
				'version'   => 1,
				'constants' => $payload,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_profiles_constants(): array {
		$defaults = array(
			'DISABLE_WP_CRON'     => false,
			'CONCATENATE_SCRIPTS' => false,
			'COMPRESS_SCRIPTS'    => false,
			'WP_POST_REVISIONS'   => null,
			'AUTOSAVE_INTERVAL'   => null,
			'EMPTY_TRASH_DAYS'    => null,
		);
		$data = self::read_json( self::PROFILES_JSON );
		if ( empty( $data['constants'] ) || ! is_array( $data['constants'] ) ) {
			return $defaults;
		}
		foreach ( $defaults as $constant => $default ) {
			if ( array_key_exists( $constant, $data['constants'] ) ) {
				$defaults[ $constant ] = $data['constants'][ $constant ];
			}
		}
		return $defaults;
	}

	/**
	 * Whether a constant is defined in a TSO JSON config (not wp-config).
	 *
	 * @param string $constant Constant name.
	 * @return bool
	 */
	public static function constant_defined_in_tsosk_config( string $constant ): bool {
		foreach ( array( self::DEBUG_JSON, self::SECURITY_JSON, self::PROFILES_JSON ) as $file ) {
			$data = self::read_json( $file );
			if ( isset( $data['flags'][ $constant ] ) && (bool) $data['flags'][ $constant ] ) {
				return true;
			}
			if ( isset( $data['constants'][ $constant ] ) ) {
				$value = $data['constants'][ $constant ];
				if ( is_bool( $value ) && $value ) {
					return true;
				}
				if ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Directory for plugin-managed log archives (uploads only).
	 *
	 * @return string
	 */
	public static function get_log_archive_dir(): string {
		$base = '';
		if ( function_exists( 'tsosk_get_uploads_subdir' ) ) {
			$base = tsosk_get_uploads_subdir( 'logs/archives' );
		}
		if ( '' === $base ) {
			$config = self::get_dir();
			$base   = '' !== $config ? trailingslashit( dirname( $config ) ) . 'logs/archives' : '';
		}
		if ( '' === $base ) {
			return '';
		}
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			self::protect_dir( $base );
		}
		return $base;
	}

	/**
	 * Paths where the plugin may truncate or rewrite log files.
	 *
	 * @return string[]
	 */
	public static function get_writable_log_roots(): array {
		$roots = array();

		// Only plugin-owned uploads logs may be truncated/rewritten (WordPress.org write policy).
		$logs_dir = function_exists( 'tsosk_get_uploads_subdir' ) ? tsosk_get_uploads_subdir( 'logs' ) : '';
		if ( '' !== $logs_dir ) {
			$roots[] = wp_normalize_path( $logs_dir );
		}

		$legacy = self::get_legacy_upload_dirs();
		if ( ! empty( $legacy['logs'] ) ) {
			$roots[] = wp_normalize_path( $legacy['logs'] );
		}

		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * Whether a validated log path may be modified by the plugin.
	 *
	 * @param string $path Normalized log path.
	 * @return bool
	 */
	public static function is_managed_log_path( string $path ): bool {
		$path = wp_normalize_path( $path );
		$real = realpath( $path );
		if ( is_string( $real ) && '' !== $real ) {
			$path = wp_normalize_path( $real );
		}
		foreach ( self::get_writable_log_roots() as $root ) {
			$root = untrailingslashit( wp_normalize_path( $root ) );
			if ( $path === $root ) {
				return true;
			}
			if ( is_dir( $root ) && 0 === strpos( $path, trailingslashit( $root ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $filename JSON filename.
	 * @return string
	 */
	private static function path_for( string $filename ): string {
		return trailingslashit( self::get_dir() ) . $filename;
	}

	/**
	 * @param string $dir Config directory.
	 * @return bool
	 */
	private static function ensure_dir( string $dir ): bool {
		if ( '' === $dir ) {
			return false;
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect_dir( $dir );
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * @param string $dir Directory to protect from direct web access.
	 */
	private static function protect_dir( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	private static function apply_debug_constants(): void {
		$data = self::read_json( self::DEBUG_JSON );
		if ( empty( $data['flags'] ) || ! is_array( $data['flags'] ) ) {
			return;
		}
		foreach ( $data['flags'] as $const_name => $value ) {
			if ( ! is_string( $const_name ) ) {
				continue;
			}
			self::define_wp_constant_if_absent( $const_name, (bool) $value );
		}
	}

	private static function apply_security_constants(): void {
		$data = self::read_json( self::SECURITY_JSON );
		if ( empty( $data['constants'] ) || ! is_array( $data['constants'] ) ) {
			return;
		}
		foreach ( $data['constants'] as $const_name => $value ) {
			if ( ! is_string( $const_name ) || ! $value ) {
				continue;
			}
			self::define_wp_constant_if_absent( $const_name, true );
		}
	}

	private static function apply_profiles_constants(): void {
		$data = self::read_json( self::PROFILES_JSON );
		if ( empty( $data['constants'] ) || ! is_array( $data['constants'] ) ) {
			return;
		}
		foreach ( $data['constants'] as $const_name => $value ) {
			if ( ! is_string( $const_name ) ) {
				continue;
			}
			if ( is_bool( $value ) && $value ) {
				self::define_wp_constant_if_absent( $const_name, true );
			} elseif ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
				self::define_wp_constant_if_absent( $const_name, (int) $value );
			}
		}
	}

	/**
	 * Define a WordPress/PHP constant from saved JSON when not already set.
	 *
	 * @param string $constant_name Constant identifier (e.g. WP_DEBUG).
	 * @param mixed  $value         Constant value.
	 */
	private static function define_wp_constant_if_absent( string $constant_name, $value ): void {
		if ( defined( $constant_name ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Applies WP core constant names from JSON config.
		define( $constant_name, $value );
	}

	private static function maybe_migrate_legacy_configs(): void {
		self::maybe_migrate_legacy_upload_folders();
		self::migrate_legacy_debug();
		self::migrate_legacy_security();
		self::migrate_legacy_profiles();
	}

	/**
	 * Move JSON/config files from uploads/tsosk-config into uploads/{slug}/config.
	 */
	private static function maybe_migrate_legacy_upload_folders(): void {
		$new_dir = self::get_dir();
		if ( '' === $new_dir ) {
			return;
		}

		$legacy = self::get_legacy_upload_dirs();
		$old    = $legacy['config'] ?? '';
		if ( '' === $old || ! is_dir( $old ) || wp_normalize_path( $old ) === wp_normalize_path( $new_dir ) ) {
			return;
		}

		if ( ! self::ensure_dir( $new_dir ) ) {
			return;
		}

		$names = array(
			self::DEBUG_JSON,
			self::SECURITY_JSON,
			self::PROFILES_JSON,
			self::LEGACY_DEBUG,
			self::LEGACY_SECURITY,
			self::LEGACY_PROFILES,
		);
		foreach ( $names as $name ) {
			$from = trailingslashit( $old ) . $name;
			$to   = trailingslashit( $new_dir ) . $name;
			if ( is_readable( $from ) && ! file_exists( $to ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- local uploads migration.
				@rename( $from, $to );
			}
		}
	}

	private static function legacy_path( string $legacy_filename ): string {
		$uploads = self::path_for( $legacy_filename );
		if ( is_readable( $uploads ) ) {
			return $uploads;
		}
		$legacy_dirs = self::get_legacy_upload_dirs();
		if ( ! empty( $legacy_dirs['config'] ) ) {
			$legacy_uploads = trailingslashit( $legacy_dirs['config'] ) . $legacy_filename;
			if ( is_readable( $legacy_uploads ) ) {
				return $legacy_uploads;
			}
		}
		$mu = trailingslashit( WPMU_PLUGIN_DIR ) . $legacy_filename;
		return is_readable( $mu ) ? $mu : $uploads;
	}

	private static function migrate_legacy_debug(): void {
		if ( self::json_exists( self::DEBUG_JSON ) ) {
			return;
		}
		$legacy = self::legacy_path( self::LEGACY_DEBUG );
		if ( ! is_readable( $legacy ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $legacy );
		$map = array(
			'WP_DEBUG'         => 'debug',
			'WP_DEBUG_LOG'     => 'log',
			'WP_DEBUG_DISPLAY' => 'display',
			'SCRIPT_DEBUG'     => 'script',
			'SAVEQUERIES'      => 'queries',
		);
		$flags = array(
			'debug'   => false,
			'log'     => false,
			'display' => false,
			'script'  => false,
			'queries' => false,
		);
		foreach ( $map as $constant => $key ) {
			if ( preg_match( "/define\(\s*'" . preg_quote( $constant, '/' ) . "'\s*,\s*true\s*\)/i", $src ) ) {
				$flags[ $key ] = true;
			}
		}
		self::save_debug_flags( $flags );
		self::delete_legacy( self::LEGACY_DEBUG );
	}

	private static function migrate_legacy_security(): void {
		if ( self::json_exists( self::SECURITY_JSON ) ) {
			return;
		}
		$legacy = self::legacy_path( self::LEGACY_SECURITY );
		if ( ! is_readable( $legacy ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $legacy );
		$flags = array(
			'DISALLOW_FILE_EDIT' => (bool) preg_match( "/define\(\s*'DISALLOW_FILE_EDIT'\s*,\s*true\s*\)/", $src ),
			'DISALLOW_FILE_MODS' => (bool) preg_match( "/define\(\s*'DISALLOW_FILE_MODS'\s*,\s*true\s*\)/", $src ),
			'FORCE_SSL_ADMIN'    => (bool) preg_match( "/define\(\s*'FORCE_SSL_ADMIN'\s*,\s*true\s*\)/", $src ),
		);
		$override_off = (bool) preg_match( "/add_filter\s*\(\s*'force_ssl_admin'/", $src );
		if ( $override_off ) {
			$flags['FORCE_SSL_ADMIN'] = false;
		}
		self::save_security_flags( $flags, $override_off );
		self::delete_legacy( self::LEGACY_SECURITY );
	}

	private static function migrate_legacy_profiles(): void {
		if ( self::json_exists( self::PROFILES_JSON ) ) {
			return;
		}
		$legacy = self::legacy_path( self::LEGACY_PROFILES );
		if ( ! is_readable( $legacy ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $legacy );
		$constants = array();
		foreach ( array( 'DISABLE_WP_CRON', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS' ) as $bool_const ) {
			if ( preg_match( "/define\(\s*'" . preg_quote( $bool_const, '/' ) . "'\s*,\s*true\s*\)/", $src ) ) {
				$constants[ $bool_const ] = true;
			}
		}
		foreach ( array( 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS' ) as $num_const ) {
			if ( preg_match( "/define\(\s*'" . preg_quote( $num_const, '/' ) . "'\s*,\s*(\d+)\s*\)/", $src, $m ) ) {
				$constants[ $num_const ] = (int) $m[1];
			}
		}
		self::save_profiles_constants( $constants );
		self::delete_legacy( self::LEGACY_PROFILES );
	}

	/**
	 * @param string $legacy_filename Legacy PHP filename.
	 */
	private static function delete_legacy( string $legacy_filename ): void {
		$path = self::path_for( $legacy_filename );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		$mu = trailingslashit( WPMU_PLUGIN_DIR ) . $legacy_filename;
		if ( file_exists( $mu ) ) {
			wp_delete_file( $mu );
		}
	}
}
