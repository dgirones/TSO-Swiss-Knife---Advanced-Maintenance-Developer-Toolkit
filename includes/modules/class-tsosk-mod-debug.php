<?php
/**
 * TSO Swiss Knife – Module: Debug Mode Switch.
 *
 * Writes/removes a must-use plugin to toggle WP_DEBUG, WP_DEBUG_LOG,
 * WP_DEBUG_DISPLAY, SCRIPT_DEBUG and SAVEQUERIES at runtime.
 * This approach avoids touching wp-config.php directly.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Debug
 */
class TSOSK_Mod_Debug {

	/** @var TSOSK_Mod_Debug|null */
	private static $instance = null;

	/** Path to the MU-plugin file this module manages. */
	private const MU_FILE = 'tsosk-debug-flags.php';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_debug_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_debug_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_debug_shrink_log', array( $this, 'ajax_shrink_log' ) );
		add_action( 'admin_post_tsosk_debug_download_log', array( $this, 'download_log' ) );
		add_action( 'wp_ajax_tsosk_debug_wpconfig_save',   array( $this, 'ajax_wpconfig_save' ) );
		add_action( 'wp_ajax_tsosk_debug_developer_mode', array( $this, 'ajax_developer_mode' ) );
	}

	/**
	 * Called early by the main loader (plugins_loaded) so constants set by
	 * the MU-plugin are already in effect; nothing extra to do here.
	 */
	public function init(): void {}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns the full path to the config file (inside wp-content/uploads/tsosk-config/).
	 *
	 * @return string
	 */
	private function config_path(): string {
		return trailingslashit( TSOSK_CONFIG_DIR ) . self::MU_FILE;
	}

	/**
	 * Returns the path to the default debug.log file.
	 *
	 * @return string
	 */
	private function log_path(): string {
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Returns known WordPress/PHP log files that are safe to inspect from admin.
	 *
	 * @return array<int, array{label:string,path:string,exists:bool,readable:bool,writable:bool,size:int,modified:int,preview:string}>
	 */
	private function get_log_files(): array {
		$candidates = array(
			__( 'WordPress debug.log', 'tso-swiss-knife' ) => $this->log_path(),
			__( 'WordPress root error_log', 'tso-swiss-knife' ) => ABSPATH . 'error_log',
			__( 'wp-admin error_log', 'tso-swiss-knife' ) => ABSPATH . 'wp-admin/error_log',
			__( 'wp-includes error_log', 'tso-swiss-knife' ) => ABSPATH . 'wp-includes/error_log',
			__( 'wp-content error_log', 'tso-swiss-knife' ) => WP_CONTENT_DIR . '/error_log',
			__( 'uploads error_log', 'tso-swiss-knife' ) => WP_CONTENT_DIR . '/uploads/error_log',
			__( 'plugins error_log', 'tso-swiss-knife' ) => WP_PLUGIN_DIR . '/error_log',
		);

		if ( function_exists( 'get_theme_root' ) ) {
			$candidates[ __( 'themes error_log', 'tso-swiss-knife' ) ] = trailingslashit( get_theme_root() ) . 'error_log';
		}

		$php_error_log = ini_get( 'error_log' );
		if ( is_string( $php_error_log ) && '' !== $php_error_log ) {
			$candidates[ __( 'PHP error_log setting', 'tso-swiss-knife' ) ] = $php_error_log;
		}

		$logs = array();
		$seen = array();

		foreach ( $candidates as $label => $path ) {
			$path = $this->normalize_log_path( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			$identity = file_exists( $path ) ? ( realpath( $path ) ?: $path ) : $path;
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;

			$exists = file_exists( $path );
			$size = $exists ? (int) filesize( $path ) : 0;

			$logs[] = array(
				'label'    => (string) $label,
				'path'     => $path,
				'exists'   => $exists,
				'readable' => $exists && is_readable( $path ),
				'writable' => $exists && wp_is_writable( $path ),
				'size'     => $size,
				'modified' => $exists ? (int) filemtime( $path ) : 0,
				'preview'  => $exists && is_readable( $path ) ? $this->read_log_preview( $path ) : '',
			);
		}

		usort(
			$logs,
			static function ( array $a, array $b ): int {
				if ( $a['exists'] !== $b['exists'] ) {
					return $a['exists'] ? -1 : 1;
				}
				if ( ! $a['exists'] ) {
					return strcmp( $a['label'], $b['label'] );
				}
				return $b['modified'] <=> $a['modified'];
			}
		);

		return $logs;
	}

	/**
	 * Normalize a log path and keep inspection scoped to the WordPress install.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private function normalize_log_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path || 'syslog' === strtolower( $path ) ) {
			return '';
		}

		if ( ! preg_match( '#^([A-Za-z]:)?[\\\\/]#', $path ) ) {
			$path = ABSPATH . ltrim( $path, '/\\' );
		}

		$path = wp_normalize_path( $path );
		$allowed_roots = array(
			wp_normalize_path( ABSPATH ),
			wp_normalize_path( WP_CONTENT_DIR ),
		);

		foreach ( $allowed_roots as $root ) {
			if ( 0 === strpos( $path, trailingslashit( $root ) ) || $path === untrailingslashit( $root ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Read the end of a log file without loading very large files into memory.
	 *
	 * @param string $path Log file path.
	 * @return string
	 */
	private function read_log_preview( string $path ): string {
		$max_bytes = 200 * 1024;
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return '';
		}

		$offset = max( 0, $size - $max_bytes );
		$length = min( $max_bytes, $size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local log preview.
		$content = file_get_contents( $path, false, null, $offset, $length );
		if ( false === $content ) {
			return '';
		}

		if ( $offset > 0 ) {
			$first_break = strpos( $content, "\n" );
			if ( false !== $first_break ) {
				$content = substr( $content, $first_break + 1 );
			}
			$content = "[...]\n" . $content;
		}

		return $content;
	}

	/**
	 * Check whether a path belongs to the detected log list.
	 *
	 * @param string $path Raw log path.
	 * @return string
	 */
	private function validate_log_path( string $path ): string {
		$path = $this->normalize_log_path( $path );
		if ( '' === $path ) {
			return '';
		}

		$target = realpath( $path ) ?: $path;
		foreach ( $this->get_log_files() as $log ) {
			$known = realpath( $log['path'] ) ?: $log['path'];
			if ( $known === $target ) {
				return $log['path'];
			}
		}

		return '';
	}

	/**
	 * Download a validated log file.
	 */
	public function download_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife' ) );
		}
		check_admin_referer( 'tsosk_debug_download_log' );

		$posted_log = isset( $_GET['log_path'] ) ? sanitize_text_field( wp_unslash( $_GET['log_path'] ) ) : '';
		$log = $this->validate_log_path( $posted_log );
		if ( '' === $log || ! file_exists( $log ) || ! is_readable( $log ) ) {
			wp_die( esc_html__( 'Log file not found.', 'tso-swiss-knife' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $log ) ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $log ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- controlled local log download.
		readfile( $log );
		exit;
	}

	/**
	 * Classify a log line for filtering.
	 *
	 * @param string $line Log line.
	 * @return string
	 */
	private function classify_log_line( string $line ): string {
		if ( preg_match( '/fatal|parse error|critical/i', $line ) ) {
			return 'error';
		}
		if ( preg_match( '/warning/i', $line ) ) {
			return 'warning';
		}
		if ( preg_match( '/deprecated/i', $line ) ) {
			return 'deprecated';
		}
		if ( preg_match( '/notice/i', $line ) ) {
			return 'notice';
		}
		return 'info';
	}

	/**
	 * Reads current debug settings from the MU-plugin file.
	 *
	 * @return array{debug:bool,log:bool,display:bool,script:bool,queries:bool}
	 */
	private function get_settings(): array {
		$defaults = array(
			'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script'  => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'queries' => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
		);

		$path = $this->config_path();
		if ( ! file_exists( $path ) ) {
			return $defaults;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local MU-plugin file.
		$src = file_get_contents( $path );
		if ( ! $src ) {
			return $defaults;
		}

		// Parse booleans written by write_mu_plugin().
		$map = array(
			'debug'   => 'WP_DEBUG',
			'log'     => 'WP_DEBUG_LOG',
			'display' => 'WP_DEBUG_DISPLAY',
			'script'  => 'SCRIPT_DEBUG',
			'queries' => 'SAVEQUERIES',
		);
		$out = array();
		foreach ( $map as $key => $constant ) {
			if ( preg_match( "/define\(\s*'" . preg_quote( $constant, '/' ) . "'\s*,\s*(true|false)\s*\)/i", $src, $m ) ) {
				$out[ $key ] = ( 'true' === strtolower( $m[1] ) );
			} else {
				$out[ $key ] = $defaults[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Writes or removes the MU-plugin file according to the given settings.
	 *
	 * @param array $s Settings array.
	 * @return true|WP_Error
	 */
	private function write_mu_plugin( array $s ) {
		// If all flags are off, remove the file so we leave no trace.
		$any_on = $s['debug'] || $s['log'] || $s['display'] || $s['script'] || $s['queries'];

		$path = $this->config_path();

		if ( ! $any_on ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return true;
		}

		$bool = static function ( bool $v ): string {
			return $v ? 'true' : 'false';
		};

		$content  = "<?php\n";
		$content .= "/**\n";
		$content .= " * TSO Swiss Knife – Debug Flags (auto-generated, do not edit).\n";
		$content .= " * tsosw\n";
		$content .= " */\n";
		$content .= "if ( ! defined( 'WP_DEBUG' ) )         { define( 'WP_DEBUG',         " . $bool( $s['debug'] )   . " ); }\n";
		$content .= "if ( ! defined( 'WP_DEBUG_LOG' ) )     { define( 'WP_DEBUG_LOG',     " . $bool( $s['log'] )     . " ); }\n";
		$content .= "if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) { define( 'WP_DEBUG_DISPLAY', " . $bool( $s['display'] ) . " ); }\n";
		$content .= "if ( ! defined( 'SCRIPT_DEBUG' ) )     { define( 'SCRIPT_DEBUG',     " . $bool( $s['script'] )  . " ); }\n";
		$content .= "if ( ! defined( 'SAVEQUERIES' ) )      { define( 'SAVEQUERIES',      " . $bool( $s['queries'] ) . " ); }\n";

		// Ensure the config directory exists and is web-access protected.
		$dir = TSOSK_CONFIG_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$this->protect_config_dir( $dir );
		}

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'not_writable', __( 'The config directory is not writable.', 'tso-swiss-knife' ) );
		}

		// Remove legacy mu-plugins file if still present (migration).
		$legacy = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
		if ( file_exists( $legacy ) ) {
			wp_delete_file( $legacy );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem not available this early; TSOSK_CONFIG_DIR is inside wp-content/uploads.
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'write_failed', __( 'Could not write the config file.', 'tso-swiss-knife' ) );
		}
		return true;
	}

	/**
	 * Create .htaccess + index.php in the config dir to prevent direct web access.
	 *
	 * @param string $dir Absolute path to the config directory.
	 */
	private function protect_config_dir( string $dir ): void {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	// ── wp-config.php helpers ────────────────────────────────────────────────

	/**
	 * Constants this module can safely modify in wp-config.php.
	 *
	 * @return array<string,string> constant_name => default_safe_value
	 */
	private function managed_constants(): array {
		return array(
			'WP_DEBUG'         => 'false',
			'WP_DEBUG_LOG'     => 'false',
			'WP_DEBUG_DISPLAY' => 'false',
			'SCRIPT_DEBUG'     => 'false',
			'SAVEQUERIES'      => 'false',
		);
	}

	/**
	 * Find the wp-config.php path (handles the "one level up" pattern).
	 *
	 * @return string Absolute path, or empty string if not found.
	 */
	private function wpconfig_path(): string {
		// 1. Standard: wp-config.php at ABSPATH.
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		// 2. WordPress installed in a subdirectory: wp-config.php is one level up
		//    (documented WordPress layout — sits alongside wp-settings.php).
		$parent = dirname( untrailingslashit( ABSPATH ) ) . '/wp-config.php';
		if ( file_exists( $parent ) ) {
			return $parent;
		}
		// 3. Try document root (e.g. cPanel installs where DOCUMENT_ROOT differs from ABSPATH).
		$doc_root = isset( $_SERVER['DOCUMENT_ROOT'] )
			? rtrim( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ), '/' )
			: '';
		if ( $doc_root && file_exists( $doc_root . '/wp-config.php' ) ) {
			return $doc_root . '/wp-config.php';
		}
		// 4. Walk up from ABSPATH (max 4 levels).
		$dir = untrailingslashit( ABSPATH );
		for ( $i = 0; $i < 4; $i++ ) {
			$up = dirname( $dir );
			if ( $up === $dir ) { break; }
			if ( file_exists( $up . '/wp-config.php' ) ) {
				return $up . '/wp-config.php';
			}
			$dir = $up;
		}
		return '';
	}

	/**
	 * Read the current define() values for our managed constants from wp-config.php.
	 *
	 * Returns an array of: constant => ['defined'=>bool,'value'=>'true'|'false'|null,'line'=>int]
	 *
	 * @return array<string,array{defined:bool,value:string|null,line:int}>
	 */
	private function read_wpconfig_constants(): array {
		$result = array();
		foreach ( array_keys( $this->managed_constants() ) as $c ) {
			$result[ $c ] = array( 'defined' => false, 'value' => null, 'line' => 0 );
		}

		$path = $this->wpconfig_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_file -- reading local config file.
		$lines = file( $path );
		if ( ! is_array( $lines ) ) {
			return $result;
		}

		foreach ( $lines as $ln => $line ) {
			foreach ( array_keys( $result ) as $c ) {
				// Match: define( 'CONSTANT', true/false ); — allow spaces and single/double quotes.
				if ( preg_match( '/^\s*define\s*\(\s*[\'\"]{1}' . preg_quote( $c, '/' ) . '[\'\"]{1}\s*,\s*(true|false)\s*\)/i', $line, $m ) ) {
					$result[ $c ] = array(
						'defined' => true,
						'value'   => strtolower( $m[1] ),
						'line'    => $ln + 1,
					);
				}
			}
		}

		return $result;
	}

	/**
	 * AJAX: save one constant to wp-config.php.
	 *
	 * Changes an existing define() line, or appends a new one before "stop editing".
	 */
	public function ajax_wpconfig_save(): void {
		check_ajax_referer( 'tsosk_debug_wpconfig_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$constant = isset( $_POST['constant'] ) ? sanitize_text_field( wp_unslash( $_POST['constant'] ) ) : '';
		$value    = isset( $_POST['value'] )    ? sanitize_text_field( wp_unslash( $_POST['value'] ) )    : '';

		// Whitelist: only our managed constants, only true/false.
		if ( ! array_key_exists( $constant, $this->managed_constants() ) ) {
			wp_send_json_error( __( 'Unknown constant.', 'tso-swiss-knife' ) );
		}
		if ( ! in_array( $value, array( 'true', 'false' ), true ) ) {
			wp_send_json_error( __( 'Invalid value. Only true or false are accepted.', 'tso-swiss-knife' ) );
		}

		$path = $this->wpconfig_path();
		if ( '' === $path ) {
			wp_send_json_error( __( 'wp-config.php not found.', 'tso-swiss-knife' ) );
		}
		if ( ! wp_is_writable( $path ) ) {
			wp_send_json_error( __( 'wp-config.php is not writable. Change file permissions to 644 temporarily, save, then restore to 440 or 400.', 'tso-swiss-knife' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_file -- reading local config file.
		$lines = file( $path );
		if ( ! is_array( $lines ) ) {
			wp_send_json_error( __( 'Could not read wp-config.php.', 'tso-swiss-knife' ) );
		}

		$new_define = "define( '{$constant}', {$value} );
";
		$replaced   = false;

		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^\s*define\s*\(\s*[\'\"]{1}' . preg_quote( $constant, '/' ) . '[\'\"]{1}\s*,\s*(true|false)\s*\)/i', $line ) ) {
				// Preserve any inline comment after the closing ).
				$comment = '';
				if ( preg_match( '/(\s*\/\/[^\n]*)$/m', $line, $cm ) ) {
					$comment = $cm[1];
				}
				$lines[ $i ] = "define( '{$constant}', {$value} );{$comment}
";
				$replaced    = true;
				break;
			}
		}

		if ( ! $replaced ) {
			// Append before the "stop editing" marker or at the end.
			$insert_before = array( "/* That's all, stop editing!", "/** That's all, stop editing", 'require_once' );
			foreach ( $lines as $i => $line ) {
				foreach ( $insert_before as $marker ) {
					if ( false !== strpos( $line, $marker ) ) {
						array_splice( $lines, $i, 0, array( $new_define ) );
						$replaced = true;
						break 2;
					}
				}
			}
			if ( ! $replaced ) {
				$lines[] = $new_define;
			}
		}

		$new_content = implode( '', $lines );

		// Sanity check: result must still open with <?php.
		if ( 0 !== strpos( ltrim( $new_content ), '<?php' ) ) {
			wp_send_json_error( __( 'Safety check failed: modified content does not start with <?php. Changes were NOT saved.', 'tso-swiss-knife' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct write required for wp-config.php.
		$result = file_put_contents( $path, $new_content );
		if ( false === $result ) {
			wp_send_json_error( __( 'Could not write wp-config.php.', 'tso-swiss-knife' ) );
		}

		TSOSK_Activity_Log::log(
			'debug',
			'update',
			sprintf(
				/* translators: 1: constant name, 2: value */
				__( 'wp-config.php: %1$s set to %2$s.', 'tso-swiss-knife' ),
				$constant,
				$value
			),
			array( 'constant' => $constant )
		);

		wp_send_json_success( array(
			'message'  => sprintf(
				/* translators: 1: constant name, 2: value */
				__( '%1$s set to %2$s in wp-config.php. Reload the page to see the new actual value.', 'tso-swiss-knife' ),
				$constant,
				$value
			),
			'constant' => $constant,
			'value'    => $value,
		) );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * Merge SAVEQUERIES into the shared debug config file (used by Slow Query Monitor).
	 *
	 * @param bool $enable Whether SAVEQUERIES should be on.
	 * @return true|WP_Error
	 */
	public function set_savequeries_flag( bool $enable ) {
		$s            = $this->get_settings();
		$s['queries'] = $enable;
		return $this->write_mu_plugin( $s );
	}

	/**
	 * Apply or remove the unified developer preset (log errors, no on-screen display, log queries).
	 *
	 * @param bool $enable True to enable preset.
	 * @return true|WP_Error
	 */
	public function apply_developer_preset( bool $enable ) {
		if ( $enable ) {
			return $this->write_mu_plugin(
				array(
					'debug'   => true,
					'log'     => true,
					'display' => false,
					'script'  => false,
					'queries' => true,
				)
			);
		}
		return $this->write_mu_plugin(
			array(
				'debug'   => false,
				'log'     => false,
				'display' => false,
				'script'  => false,
				'queries' => false,
			)
		);
	}

	/**
	 * AJAX: enable/disable developer mode preset.
	 */
	public function ajax_developer_mode(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}
		$enable = ! empty( $_POST['enable'] );
		$result = $this->apply_developer_preset( $enable );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TSOSK_Activity_Log::log(
			'debug',
			$enable ? 'enable' : 'disable',
			$enable
				? __( 'Developer mode enabled.', 'tso-swiss-knife' )
				: __( 'Developer mode disabled.', 'tso-swiss-knife' )
		);
		wp_send_json_success(
			array(
				'message' => $enable
					? __( 'Developer mode enabled. Reload the page to apply.', 'tso-swiss-knife' )
					: __( 'Developer mode disabled. Reload the page to apply.', 'tso-swiss-knife' ),
				'active'  => $enable,
			)
		);
	}

	/**
	 * AJAX: save debug settings.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$raw_flags = array();
		if ( isset( $_POST['flags'] ) && is_array( $_POST['flags'] ) ) {
			$raw_flags = array_map( 'sanitize_text_field', wp_unslash( $_POST['flags'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$s = array(
			'debug'   => $this->is_enabled_flag( 'debug', $raw_flags ),
			'log'     => $this->is_enabled_flag( 'log', $raw_flags ),
			'display' => $this->is_enabled_flag( 'display', $raw_flags ),
			'script'  => $this->is_enabled_flag( 'script', $raw_flags ),
			'queries' => $this->is_enabled_flag( 'queries', $raw_flags ),
		);

		$result = $this->write_mu_plugin( $s );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		TSOSK_Activity_Log::log( 'debug', 'save', __( 'Debug flags saved.', 'tso-swiss-knife' ) );
		wp_send_json_success( __( 'Debug settings saved. Reload the page to see the new constants.', 'tso-swiss-knife' ) );
	}

	/**
	 * Normalize a posted debug flag from either flat or nested AJAX payloads.
	 *
	 * @param string $key       Flag key.
	 * @param array  $raw_flags Nested flags payload.
	 * @return bool
	 */
	private function is_enabled_flag( string $key, array $raw_flags ): bool {
		$value = null;

		if ( array_key_exists( $key, $raw_flags ) ) {
			$value = $raw_flags[ $key ];
		}

		return in_array( $value, array( '1', 1, true, 'true', 'on', 'yes' ), true );
	}

	/**
	 * AJAX: truncate the debug.log file.
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$posted_log = isset( $_POST['log_path'] ) ? sanitize_text_field( wp_unslash( $_POST['log_path'] ) ) : $this->log_path();
		$log = $this->validate_log_path( $posted_log );
		if ( '' === $log ) {
			wp_send_json_error( __( 'Invalid log file.', 'tso-swiss-knife' ) );
		}
		if ( ! file_exists( $log ) ) {
			wp_send_json_error( __( 'Log file not found.', 'tso-swiss-knife' ) );
		}
		if ( ! wp_is_writable( $log ) ) {
			wp_send_json_error( __( 'Log file is not writable.', 'tso-swiss-knife' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- truncate local file.
		$result = file_put_contents( $log, '' );
		if ( false === $result ) {
			wp_send_json_error( __( 'Could not clear the log file.', 'tso-swiss-knife' ) );
		}
		TSOSK_Activity_Log::log( 'debug', 'delete', __( 'Debug log file cleared.', 'tso-swiss-knife' ) );
		wp_send_json_success( __( 'Log file emptied. The file was kept on disk.', 'tso-swiss-knife' ) );
	}

	/**
	 * AJAX: keep only the last N lines of a log file (archive older content).
	 */
	public function ajax_shrink_log(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$posted_log = isset( $_POST['log_path'] ) ? sanitize_text_field( wp_unslash( $_POST['log_path'] ) ) : $this->log_path();
		$keep_lines = isset( $_POST['keep_lines'] ) ? max( 100, min( 5000, absint( wp_unslash( $_POST['keep_lines'] ) ) ) ) : 500;
		$log        = $this->validate_log_path( $posted_log );
		if ( '' === $log || ! file_exists( $log ) || ! is_readable( $log ) ) {
			wp_send_json_error( __( 'Log file not found or not readable.', 'tso-swiss-knife' ) );
		}
		if ( ! wp_is_writable( $log ) ) {
			wp_send_json_error( __( 'Log file is not writable.', 'tso-swiss-knife' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = (string) file_get_contents( $log );
		if ( '' === $content ) {
			wp_send_json_success( __( 'Log file is already empty.', 'tso-swiss-knife' ) );
		}

		$lines = preg_split( '/\R/', $content );
		if ( ! is_array( $lines ) ) {
			$lines = array( $content );
		}
		$total = count( $lines );
		if ( $total <= $keep_lines ) {
			wp_send_json_success(
				sprintf(
					/* translators: 1: line count, 2: max lines */
					__( 'No shrink needed — file has %1$d lines (limit %2$d).', 'tso-swiss-knife' ),
					$total,
					$keep_lines
				)
			);
		}

		$archive_dir = trailingslashit( TSOSK_CONFIG_DIR ) . 'log-archives';
		if ( ! is_dir( $archive_dir ) ) {
			wp_mkdir_p( $archive_dir );
		}
		$archive_name = basename( $log ) . '.' . gmdate( 'Y-m-d-His' ) . '.bak';
		$archive_path = $archive_dir . '/' . $archive_name;
		$removed      = array_slice( $lines, 0, $total - $keep_lines );
		$kept         = array_slice( $lines, -$keep_lines );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $archive_path, implode( "\n", $removed ) . "\n" ) ) {
			wp_send_json_error( __( 'Could not write archive file.', 'tso-swiss-knife' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $log, implode( "\n", $kept ) . "\n" ) ) {
			wp_send_json_error( __( 'Could not rewrite log file.', 'tso-swiss-knife' ) );
		}

		TSOSK_Activity_Log::log(
			'debug',
			'update',
			sprintf(
				/* translators: 1: lines kept, 2: archive filename */
				__( 'Debug log shrunk to last %1$d lines (archive: %2$s).', 'tso-swiss-knife' ),
				$keep_lines,
				$archive_name
			)
		);

		wp_send_json_success(
			sprintf(
				/* translators: 1: lines kept, 2: archive path */
				__( 'Log reduced to the last %1$d lines. Older entries archived to %2$s', 'tso-swiss-knife' ),
				$keep_lines,
				$archive_path
			)
		);
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the Debug Mode tab.
	 */
	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_debug_nonce' );
		$settings = $this->get_settings();
		$log_path = $this->log_path();
		$log_size = file_exists( $log_path ) ? size_format( filesize( $log_path ), 2 ) : false;
		$mu_exists        = file_exists( $this->config_path() );
		$legacy_exists    = file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE );
		$logs             = $this->get_log_files();
		$wpconfig_path    = $this->wpconfig_path();
		$wpconfig_state   = $this->read_wpconfig_constants();
		// If path wasn't found initially, try again now that we know the constants were readable.
		if ( '' === $wpconfig_path ) {
			$wpconfig_path = $this->wpconfig_path(); // retry with all strategies.
		}
		$wpconfig_exists  = '' !== $wpconfig_path;
		// If path wasn't found above but constants were read from somewhere, re-check after state.
		// (read_wpconfig_constants already verified readability internally.)
		$wpconfig_write   = $wpconfig_exists && wp_is_writable( $wpconfig_path );
		$wpconfig_nonce   = wp_create_nonce( 'tsosk_debug_wpconfig_nonce' );

		$dev_active = class_exists( 'TSOSK_Site_Status' ) && TSOSK_Site_Status::is_developer_mode_active();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Enable logging and inspect error files on this site. Constants already defined in wp-config.php take precedence over the plugin config file.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-card tsosk-dev-mode-card">
			<h3><?php esc_html_e( 'Developer mode', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Recommended on staging: one click enables WP_DEBUG, debug.log, SAVEQUERIES and hides errors from visitors. Settings are saved to tsosk-config and only apply when those constants are not already set in wp-config.php.', 'tso-swiss-knife' ); ?>
			</p>
			<div class="tsosk-dev-mode-actions">
				<button type="button" class="button button-primary" id="tsosk-debug-developer-on"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        <?php disabled( $dev_active ); ?>>
					<?php esc_html_e( 'Enable developer mode', 'tso-swiss-knife' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="tsosk-debug-developer-off"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        <?php disabled( ! $dev_active ); ?>>
					<?php esc_html_e( 'Disable developer mode', 'tso-swiss-knife' ); ?>
				</button>
				<span class="tsosk-badge <?php echo $dev_active ? 'tsosk-badge-warn' : 'tsosk-badge-info'; ?>" id="tsosk-dev-mode-badge">
					<?php echo $dev_active ? esc_html__( 'Active', 'tso-swiss-knife' ) : esc_html__( 'Inactive', 'tso-swiss-knife' ); ?>
				</span>
			</div>
			<span class="tsosk-ajax-msg" id="tsosk-debug-msg"></span>
		</div>

<?php if ( $legacy_exists ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php
			printf(
				/* translators: %s: legacy file path */
				esc_html__( 'Legacy file found in mu-plugins: %s — save settings once to migrate it to the correct location and delete the old file.', 'tso-swiss-knife' ),
				'<code>' . esc_html( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE ) . '</code>'
			);
			?>
		</div>
		<?php endif; ?>
		<?php if ( $mu_exists ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php
			printf(
				/* translators: %s: file path */
				/* translators: %s: file path */
			esc_html__( 'Active config file: %s', 'tso-swiss-knife' ),
				'<code>' . esc_html( $this->config_path() ) . '</code>'
			);
			?>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Debug Constants', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Current values for each constant. Use the buttons to add or change them directly in wp-config.php when you need full control.', 'tso-swiss-knife' ); ?>
			</p>

			<div class="tsosk-debug-flags" id="tsosk-debug-form">
				<?php
				$flags = array(
					'debug'   => array(
						'label'   => 'WP_DEBUG',
						'desc'    => __( 'Enables PHP error reporting and additional debug information.', 'tso-swiss-knife' ),
						'current' => defined( 'WP_DEBUG' ) ? ( WP_DEBUG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife' ),
					),
					'log'     => array(
						'label'   => 'WP_DEBUG_LOG',
						'desc'    => __( 'Saves errors to wp-content/debug.log.', 'tso-swiss-knife' ),
						'current' => defined( 'WP_DEBUG_LOG' ) ? ( WP_DEBUG_LOG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife' ),
					),
					'display' => array(
						'label'   => 'WP_DEBUG_DISPLAY',
						'desc'    => __( 'Shows PHP errors on screen (disable on production).', 'tso-swiss-knife' ),
						'current' => defined( 'WP_DEBUG_DISPLAY' ) ? ( WP_DEBUG_DISPLAY ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife' ),
					),
					'script'  => array(
						'label'   => 'SCRIPT_DEBUG',
						'desc'    => __( 'Loads unminified CSS/JS from WordPress core.', 'tso-swiss-knife' ),
						'current' => defined( 'SCRIPT_DEBUG' ) ? ( SCRIPT_DEBUG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife' ),
					),
					'queries' => array(
						'label'   => 'SAVEQUERIES',
						'desc'    => __( 'Saves all DB queries to $wpdb->queries array.', 'tso-swiss-knife' ),
						'current' => defined( 'SAVEQUERIES' ) ? ( SAVEQUERIES ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife' ),
					),
				);
				foreach ( $flags as $key => $flag ) :
					$actual_on    = in_array( $flag['current'], array( 'true', '1' ), true );
					$flag_constant = $flag['label'];
					$wc_state      = $wpconfig_state[ $flag_constant ] ?? array( 'defined' => false, 'value' => null, 'line' => 0 );
					$in_wpconfig   = $wc_state['defined'];
					$wc_val        = $wc_state['value']; // 'true'|'false'|null
					$toggle_to     = $in_wpconfig
						? ( ( 'true' === $wc_val ) ? 'false' : 'true' )
						: ( $actual_on ? 'false' : 'true' );
					$wc_mismatch   = $in_wpconfig && $wc_val !== ( $actual_on ? 'true' : 'false' );
					$show_wpconfig_ctrl = $wpconfig_exists || $in_wpconfig;
					$eff_write          = $wpconfig_write || ( $wpconfig_exists && wp_is_writable( $wpconfig_path ) );
				?>
				<div class="tsosk-flag-row<?php echo $wc_mismatch ? ' tsosk-flag-mismatch' : ''; ?>">

					<?php /* ── Constant name ── */ ?>
					<span class="tsosk-flag-label" style="min-width:160px;font-weight:600;">
						<?php echo esc_html( $flag_constant ); ?>
					</span>

					<?php /* ── Description ── */ ?>
					<span class="tsosk-flag-desc"><?php echo esc_html( $flag['desc'] ); ?></span>

					<?php /* ── Current value badge ── */ ?>
					<span class="tsosk-flag-current">
						<?php esc_html_e( 'Actual:', 'tso-swiss-knife' ); ?>
						<code class="<?php echo $actual_on ? 'tsosk-val-true' : 'tsosk-val-false'; ?>">
							<?php echo esc_html( $flag['current'] ); ?>
						</code>
					</span>

					<?php /* ── wp-config.php control ── */ ?>
					<?php if ( $show_wpconfig_ctrl ) : ?>
					<div style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
						<?php if ( $in_wpconfig ) : ?>
						<span class="tsosk-badge tsosk-badge-<?php echo $wc_mismatch ? 'warn' : 'info'; ?>"
						      style="font-size:11px;">
							<?php
							/* translators: %s: constant value in wp-config.php */
							printf( esc_html__( 'wp-config.php → %s', 'tso-swiss-knife' ), esc_html( (string) ( $wc_val ?? 'n/a' ) ) );
							?>
						</span>
						<?php endif; ?>
						<?php if ( $eff_write ) : ?>
						<button type="button"
						        class="button button-small tsosk-debug-wpconfig-btn"
						        data-constant="<?php echo esc_attr( $flag_constant ); ?>"
						        data-value="<?php echo esc_attr( $toggle_to ); ?>"
						        data-nonce="<?php echo esc_attr( $wpconfig_nonce ); ?>"
						        style="font-size:11px;height:24px;line-height:22px;padding:0 8px;">
							<?php
							if ( $in_wpconfig ) {
								// Toggle existing value
								$btn_icon = ( 'true' === $wc_val ) ? '✕' : '✓';
								printf(
									/* translators: 1: icon, 2: true or false */
									esc_html__( '%1$s Set to %2$s in wp-config.php', 'tso-swiss-knife' ),
									esc_html( (string) $btn_icon ),
									esc_html( (string) $toggle_to )
								);
							} else {
								// Add new define
								printf(
									/* translators: 1: constant name, 2: true or false */
									esc_html__( '+ Add %1$s = %2$s to wp-config.php', 'tso-swiss-knife' ),
									esc_html( (string) $flag_constant ),
									esc_html( (string) $toggle_to )
								);
							}
							?>
						</button>
						<?php else : ?>
						<span class="description" style="font-size:11px;">
							<?php esc_html_e( '(wp-config.php is read-only)', 'tso-swiss-knife' ); ?>
						</span>
						<?php endif; ?>
					</div>
					<?php endif; ?>

				</div>
				<?php endforeach; ?>

				<details class="tsosk-debug-advanced">
					<summary><?php esc_html_e( 'Advanced: write debug flags to wp-config.php', 'tso-swiss-knife' ); ?></summary>
					<p class="description">
						<?php esc_html_e( 'Use this only when you want the settings hard-coded in wp-config.php instead of tsosk-config. A backup is recommended before modifying wp-config.php.', 'tso-swiss-knife' ); ?>
					</p>
					<button class="button button-secondary" id="tsosk-enable-wp-log" type="button">
						<?php esc_html_e( 'Enable debug.log in wp-config.php', 'tso-swiss-knife' ); ?>
					</button>
				</details>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Available Error Logs', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Shows readable WordPress/PHP error logs detected inside this WordPress installation. Previews are limited to the end of each file.', 'tso-swiss-knife' ); ?>
			</p>
			<div class="tsosk-notice tsosk-notice-info">
				<strong><?php esc_html_e( 'debug.log', 'tso-swiss-knife' ); ?></strong> —
				<?php esc_html_e( 'Created by WordPress when WP_DEBUG=true and WP_DEBUG_LOG=true. Use the constants above to enable it.', 'tso-swiss-knife' ); ?>
				<br>
				<strong><?php esc_html_e( 'error_log', 'tso-swiss-knife' ); ?></strong> —
				<?php esc_html_e( 'Created by PHP/your hosting server when PHP error logging is active. This plugin can read and clear it, but cannot enable it (that requires server/php.ini configuration).', 'tso-swiss-knife' ); ?>
			</div>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Log', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Path', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Modified', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?>
								<span class="tsosk-th-hint"><?php esc_html_e( 'View = scroll to preview below. Empty = delete entries, keep file.', 'tso-swiss-knife' ); ?></span>
							</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log['label'] ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $log['path'] ); ?></td>
							<td><?php echo $log['exists'] ? esc_html( size_format( $log['size'], 2 ) ) : esc_html__( 'Not found', 'tso-swiss-knife' ); ?></td>
							<td><?php echo $log['modified'] ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['modified'] ) ) : esc_html__( 'Unknown', 'tso-swiss-knife' ); ?></td>
							<td class="tsosk-log-actions-cell">
								<div class="tsosk-log-actions">
									<?php if ( $log['exists'] && $log['readable'] ) : ?>
										<a href="#tsosk-log-<?php echo esc_attr( md5( $log['path'] ) ); ?>" class="button button-small">
											<?php esc_html_e( 'View content', 'tso-swiss-knife' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( $log['exists'] && $log['writable'] ) : ?>
										<button type="button" class="button button-small tsosk-shrink-log"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>"
										        data-log-path="<?php echo esc_attr( $log['path'] ); ?>"
										        data-log-label="<?php echo esc_attr( $log['label'] ); ?>">
											<?php esc_html_e( 'Shrink (keep last 500 lines)', 'tso-swiss-knife' ); ?>
										</button>
										<button type="button" class="button button-small button-link-delete tsosk-clear-log"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>"
										        data-log-path="<?php echo esc_attr( $log['path'] ); ?>"
										        data-log-label="<?php echo esc_attr( $log['label'] ); ?>">
											<?php esc_html_e( 'Empty file', 'tso-swiss-knife' ); ?>
										</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<span class="tsosk-ajax-msg" id="tsosk-log-msg"></span>
		</div>

		<?php foreach ( $logs as $log ) : ?>
			<?php if ( ! $log['exists'] || ! $log['readable'] ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<div class="tsosk-card" id="tsosk-log-<?php echo esc_attr( md5( $log['path'] ) ); ?>">
				<h3><?php echo esc_html( $log['label'] ); ?></h3>
				<p>
					<code><?php echo esc_html( $log['path'] ); ?></code>
					-
					<strong><?php echo esc_html( size_format( $log['size'], 2 ) ); ?></strong>
				</p>
				<div class="tsosk-toolbar">
					<input type="text" class="tsosk-log-search" placeholder="<?php esc_attr_e( 'Search this log...', 'tso-swiss-knife' ); ?>">
					<select class="tsosk-log-level">
						<option value="all"><?php esc_html_e( 'All levels', 'tso-swiss-knife' ); ?></option>
						<option value="error"><?php esc_html_e( 'Errors', 'tso-swiss-knife' ); ?></option>
						<option value="warning"><?php esc_html_e( 'Warnings', 'tso-swiss-knife' ); ?></option>
						<option value="notice"><?php esc_html_e( 'Notices', 'tso-swiss-knife' ); ?></option>
						<option value="deprecated"><?php esc_html_e( 'Deprecated', 'tso-swiss-knife' ); ?></option>
					</select>
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'tsosk_debug_download_log', 'log_path' => $log['path'] ), admin_url( 'admin-post.php' ) ), 'tsosk_debug_download_log' ) ); ?>">
						<?php esc_html_e( 'Download Log', 'tso-swiss-knife' ); ?>
					</a>
				</div>
				<div class="tsosk-log-preview" id="tsosk-debug-log-content-<?php echo esc_attr( md5( $log['path'] ) ); ?>">
					<?php
					$lines = preg_split( '/\R/', $log['preview'] );
					if ( is_array( $lines ) ) :
						foreach ( $lines as $line ) :
							if ( '' === $line ) {
								continue;
							}
							?>
							<div class="tsosk-log-line" data-level="<?php echo esc_attr( $this->classify_log_line( $line ) ); ?>"><?php echo esc_html( $line ); ?></div>
							<?php
						endforeach;
					endif;
					?>
				</div>
			</div>
		<?php endforeach; ?>

		<?php /* ── SAVEQUERIES viewer ── */ ?>
		<?php
		global $wpdb;
		$sq_enabled = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$sq_queries = $sq_enabled && is_array( $wpdb->queries ) ? $wpdb->queries : array();
		$sq_count   = count( $sq_queries );
		$sq_total   = $sq_enabled ? array_sum( array_column( $sq_queries, 1 ) ) : 0;
		// Find slowest query
		$sq_max     = $sq_count ? max( array_column( $sq_queries, 1 ) ) : 0;
		// Find duplicate queries
		$sq_sql_map = array();
		foreach ( $sq_queries as $q ) {
			$sql = preg_replace( '/\s+/', ' ', trim( (string) $q[0] ) );
			$sq_sql_map[ $sql ] = ( $sq_sql_map[ $sql ] ?? 0 ) + 1;
		}
		$sq_dupes = array_filter( $sq_sql_map, fn( $n ) => $n > 1 );
		?>

		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-database" aria-hidden="true"></span>
				<?php esc_html_e( 'Database Queries (SAVEQUERIES)', 'tso-swiss-knife' ); ?>
				<?php if ( $sq_enabled && $sq_count ) : ?>
				<span class="tsosk-badge <?php echo $sq_count > 100 ? 'tsosk-badge-warn' : 'tsosk-badge-info'; ?>"
				      style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) $sq_count ); ?> queries
				</span>
				<?php endif; ?>
			</h3>

			<?php if ( ! $sq_enabled ) : ?>
			<div class="tsosk-notice tsosk-notice-info">
				<strong><?php esc_html_e( 'SAVEQUERIES is not active.', 'tso-swiss-knife' ); ?></strong>
				<?php esc_html_e( 'Enable it above, save and reload this page to see the full list of database queries executed on this page load. Only use this while debugging — it has a memory overhead on every request.', 'tso-swiss-knife' ); ?>
			</div>
			<?php elseif ( ! $sq_count ) : ?>
			<p class="description">
				<?php esc_html_e( 'SAVEQUERIES is active but no queries were recorded yet. Reload the page.', 'tso-swiss-knife' ); ?>
			</p>
			<?php else : ?>

			<?php /* Stats row */ ?>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_count > 100 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( (string) $sq_count ); ?>
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Total queries', 'tso-swiss-knife' ); ?></span>
				</div>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_total > 0.5 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( number_format( $sq_total * 1000, 2 ) ); ?> ms
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Total time', 'tso-swiss-knife' ); ?></span>
				</div>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_max > 0.1 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( number_format( $sq_max * 1000, 2 ) ); ?> ms
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Slowest query', 'tso-swiss-knife' ); ?></span>
				</div>
				<?php if ( ! empty( $sq_dupes ) ) : ?>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val tsosk-sq-warn">
						<?php echo esc_html( (string) count( $sq_dupes ) ); ?>
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Duplicate queries', 'tso-swiss-knife' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $sq_dupes ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ Duplicate queries detected.', 'tso-swiss-knife' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of distinct duplicate queries */
					esc_html__( '%d distinct queries are executed more than once. This often indicates a plugin calling get_option(), get_post_meta() or similar in a loop without caching.', 'tso-swiss-knife' ),
					(int) count( $sq_dupes )
				);
				?>
			</div>
			<?php endif; ?>

			<?php /* Filter bar */ ?>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
				<input type="text" id="tsosk-sq-filter"
				       placeholder="<?php esc_attr_e( 'Filter queries…', 'tso-swiss-knife' ); ?>"
				       style="min-width:220px;" autocomplete="off">
				<label style="font-size:13px;">
					<input type="checkbox" id="tsosk-sq-dupes-only">
					<?php esc_html_e( 'Show duplicates only', 'tso-swiss-knife' ); ?>
				</label>
				<label style="font-size:13px;">
					<input type="checkbox" id="tsosk-sq-slow-only">
					<?php esc_html_e( 'Show slow only (>5 ms)', 'tso-swiss-knife' ); ?>
				</label>
				<span id="tsosk-sq-count-shown" style="font-size:12px;color:#646970;"></span>
			</div>

			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-sq-table">
				<thead><tr>
					<th style="width:44px;">#</th>
					<th style="width:70px;"><?php esc_html_e( 'Time', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'SQL', 'tso-swiss-knife' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Called by', 'tso-swiss-knife' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				// Sort by time descending so slowest first
				$sorted = $sq_queries;
				usort( $sorted, fn( $a, $b ) => $b[1] <=> $a[1] );
				foreach ( $sorted as $i => $q ) :
					$sql_raw   = (string) $q[0];
					$time_ms   = (float) $q[1] * 1000;
					$caller    = (string) ( $q[2] ?? '' );
					$sql_clean = preg_replace( '/\s+/', ' ', trim( $sql_raw ) );
					$is_slow   = $time_ms > 5;
					$is_dupe   = ( $sq_sql_map[ $sql_clean ] ?? 0 ) > 1;
					// Extract first keyword
					$kw        = strtoupper( strtok( $sql_clean, ' ' ) );
					$kw_color  = array(
						'SELECT' => '#2271b1', 'INSERT' => '#16a34a', 'UPDATE' => '#d97706',
						'DELETE' => '#d63638', 'CREATE' => '#7c3aed', 'DROP' => '#d63638',
						'ALTER'  => '#7c3aed', 'SHOW'   => '#646970',
					);
					$kw_c      = $kw_color[ $kw ] ?? '#374151';
				?>
				<tr class="tsosk-sq-row<?php
					echo $is_slow ? ' tsosk-sq-slow' : '';
					echo $is_dupe ? ' tsosk-sq-dupe' : '';
				?>"
				    data-sql="<?php echo esc_attr( strtolower( $sql_clean ) ); ?>"
				    data-dupe="<?php echo $is_dupe ? '1' : '0'; ?>"
				    data-slow="<?php echo $is_slow ? '1' : '0'; ?>">
					<td style="color:#646970;font-size:12px;"><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
					<td style="font-family:monospace;font-size:12px;">
						<span style="color:<?php echo esc_attr( $is_slow ? '#d63638' : ( $time_ms > 2 ? '#d97706' : '#16a34a' ) ); ?>;font-weight:600;">
							<?php echo esc_html( number_format( $time_ms, 3 ) ); ?> ms
						</span>
					</td>
					<td style="word-break:break-word;">
						<?php if ( $is_dupe ) : ?>
						<span class="tsosk-badge tsosk-badge-warn" style="font-size:10px;margin-right:4px;">
							<?php
							printf(
								/* translators: %d: number of times query ran */
								esc_html__( '×%d', 'tso-swiss-knife' ),
								(int) $sq_sql_map[ $sql_clean ]
							);
							?>
						</span>
						<?php endif; ?>
						<span class="tsosk-badge" style="background:<?php echo esc_attr( $kw_c ); ?>20;color:<?php echo esc_attr( $kw_c ); ?>;font-size:10px;margin-right:4px;">
							<?php echo esc_html( $kw ); ?>
						</span>
						<code style="font-size:11px;color:#1d2327;background:none;word-break:break-all;">
							<?php echo esc_html( mb_substr( $sql_clean, strlen( $kw ) + 1 ) ); ?>
						</code>
					</td>
					<td style="font-size:11px;color:#646970;word-break:break-word;">
						<?php
						// Show last 3 meaningful frames
						$frames = array_filter(
							array_map( 'trim', explode( ',', $caller ) ),
							fn( $f ) => '' !== $f && ! in_array( $f, array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_var', 'wpdb->get_row', 'wpdb->prepare' ), true )
						);
						$frames = array_slice( array_values( $frames ), -3 );
						echo esc_html( implode( ' → ', $frames ) );
						?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<?php endif; ?>
		</div>

		<?php
	}
}
