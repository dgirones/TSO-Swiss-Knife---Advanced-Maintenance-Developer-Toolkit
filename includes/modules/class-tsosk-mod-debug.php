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

	/** JSON config filename (stored under uploads/{plugin-slug}/config). */
	private const CONFIG_FILE = 'tsosk-debug-flags.json';

	/** @deprecated Legacy PHP filename — migrated to JSON on read. */
	private const MU_FILE = 'tsosk-debug-flags.php';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_debug_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_debug_shrink_log', array( $this, 'ajax_shrink_log' ) );
		add_action( 'wp_ajax_tsosk_debug_refresh_log', array( $this, 'ajax_refresh_log' ) );
		add_action( 'admin_post_tsosk_debug_download_log', array( $this, 'download_log' ) );
		add_action( 'wp_ajax_tsosk_debug_developer_mode', array( $this, 'ajax_developer_mode' ) );
	}

	/**
	 * Called early by the main loader (plugins_loaded) so constants set by
	 * the MU-plugin are already in effect; nothing extra to do here.
	 */
	public function init(): void {}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns the full path to the config file (inside uploads/{plugin-slug}/config/).
	 *
	 * @return string
	 */
	private function config_path(): string {
		return trailingslashit( TSOSK_CONFIG_DIR ) . TSOSK_Config_Storage::DEBUG_JSON;
	}

	/**
	 * Returns the path to the default debug.log file.
	 *
	 * @return string
	 */
	private function log_path(): string {
		return trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'debug.log';
	}

	/**
	 * Returns known WordPress/PHP log files that are safe to inspect from admin.
	 *
	 * @return array<int, array{label:string,path:string,exists:bool,readable:bool,writable:bool,size:int,modified:int,preview:string}>
	 */
	private function get_log_files(): array {
		$wp_root = tsosk_get_wp_root_dir();
		$uploads     = wp_upload_dir( null, false );
		$uploads_dir = ( ! empty( $uploads['basedir'] ) && empty( $uploads['error'] ) )
			? wp_normalize_path( (string) $uploads['basedir'] )
			: '';

		$candidates = array(
			__( 'WordPress debug.log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => $this->log_path(),
			__( 'WordPress root error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => trailingslashit( $wp_root ) . 'error_log',
			__( 'wp-admin error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => trailingslashit( $wp_root ) . 'wp-admin/error_log',
			__( 'wp-includes error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => trailingslashit( $wp_root ) . 'wp-includes/error_log',
			__( 'wp-content error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'error_log',
		);

		if ( '' !== $uploads_dir ) {
			$candidates[ __( 'uploads error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ] = trailingslashit( $uploads_dir ) . 'error_log';
		}

		$candidates[ __( 'plugins error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ] = tsosk_get_plugins_dir() . 'error_log';

		if ( function_exists( 'get_theme_root' ) ) {
			$candidates[ __( 'themes error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ] = trailingslashit( get_theme_root() ) . 'error_log';
		}

		$php_error_log = ini_get( 'error_log' );
		if ( is_string( $php_error_log ) && '' !== $php_error_log ) {
			$candidates[ __( 'PHP error_log setting', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ] = $php_error_log;
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
				'writable' => $exists && wp_is_writable( $path ) && TSOSK_Config_Storage::is_managed_log_path( $path ),
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
			$path = tsosk_get_wp_root_dir() . ltrim( $path, '/\\' );
		}

		$path = wp_normalize_path( $path );
		$allowed_roots = array(
			tsosk_get_wp_root_dir(),
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
	 * Whether a validated log path may be truncated or rewritten.
	 *
	 * @param string $path Raw log path.
	 * @return string Normalized path or empty when not allowed.
	 */
	private function validate_log_writable_path( string $path ): string {
		$path = $this->validate_log_path( $path );
		if ( '' === $path || ! TSOSK_Config_Storage::is_managed_log_path( $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Download a validated log file.
	 */
	public function download_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_debug_download_log' );

		$posted_log = isset( $_GET['log_path'] ) ? sanitize_text_field( wp_unslash( $_GET['log_path'] ) ) : '';
		$log = $this->validate_log_path( $posted_log );
		if ( '' === $log || ! file_exists( $log ) || ! is_readable( $log ) ) {
			wp_die( esc_html__( 'Log file not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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
	 * Build preview markup for a readable log file.
	 *
	 * @param string $path Log file path.
	 * @return string
	 */
	private function render_log_preview_html( string $path ): string {
		$preview = $this->read_log_preview( $path );
		$lines   = preg_split( '/\R/', $preview );
		if ( ! is_array( $lines ) ) {
			return '';
		}

		$html = '';
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$html .= '<div class="tsosk-log-line" data-level="' . esc_attr( $this->classify_log_line( $line ) ) . '">'
				. esc_html( $line ) . '</div>';
		}

		return $html;
	}

	/**
	 * Format a log file modified timestamp for admin display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_log_modified( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return (string) date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * AJAX: reload the tail preview for a detected log file.
	 */
	public function ajax_refresh_log(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$posted_log = isset( $_POST['log_path'] ) ? sanitize_text_field( wp_unslash( $_POST['log_path'] ) ) : '';
		$last_mtime = isset( $_POST['last_modified'] ) ? absint( wp_unslash( $_POST['last_modified'] ) ) : 0;
		$log        = $this->validate_log_path( $posted_log );

		if ( '' === $log || ! file_exists( $log ) || ! is_readable( $log ) ) {
			wp_send_json_error( __( 'Log file not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$mtime     = (int) filemtime( $log );
		$size      = (int) filesize( $log );
		$unchanged = $last_mtime > 0 && $mtime === $last_mtime;

		wp_send_json_success(
			array(
				'html'            => $unchanged ? '' : $this->render_log_preview_html( $log ),
				'size'            => size_format( $size, 2 ),
				'modified'        => $mtime,
				'modified_label'  => $this->format_log_modified( $mtime ),
				'unchanged'       => $unchanged,
				'message'         => $unchanged
					? __( 'Log is already up to date.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Log preview updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			)
		);
	}

	/**
	 * Reads current debug settings from the MU-plugin file.
	 *
	 * @return array{debug:bool,log:bool,display:bool,script:bool,queries:bool}
	 */
	private function get_settings(): array {
		return TSOSK_Config_Storage::get_debug_flags();
	}

	/**
	 * Writes or removes the JSON config according to the given settings.
	 *
	 * @param array $s Settings array.
	 * @return true|WP_Error
	 */
	private function write_mu_plugin( array $s ) {
		return TSOSK_Config_Storage::save_debug_flags( $s );
	}

	// ── wp-config.php helpers (read-only) ────────────────────────────────────

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
	 * Copy-paste wp-config.php snippets for common debug presets.
	 *
	 * @return array<string,array{title:string,desc:string,snippet:string}>
	 */
	private function wpconfig_debug_snippets(): array {
		return array(
			'developer'   => array(
				'title'   => __( 'Developer mode (staging)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Same preset as the Developer mode button above: enable debugging, log to wp-content/debug.log, hide errors from visitors, and record DB queries for the Slow Query Monitor.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'SCRIPT_DEBUG', false );\ndefine( 'SAVEQUERIES', true );",
			),
			'production'  => array(
				'title'   => __( 'Production (debug off)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Disable all debug constants on live sites.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_LOG', false );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'SCRIPT_DEBUG', false );\ndefine( 'SAVEQUERIES', false );",
			),
			'display'     => array(
				'title'   => __( 'Show PHP errors on screen', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Local troubleshooting only — never use on production. Visitors would see PHP notices and warnings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', true );\ndefine( 'SCRIPT_DEBUG', false );\ndefine( 'SAVEQUERIES', false );",
			),
			'script'      => array(
				'title'   => __( 'Unminified core CSS/JS (SCRIPT_DEBUG)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Loads non-minified WordPress core assets. Combine with the developer preset when debugging front-end scripts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'SCRIPT_DEBUG', true );\ndefine( 'SAVEQUERIES', false );",
			),
			'queries'     => array(
				'title'   => __( 'Database query logging only (SAVEQUERIES)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Record SQL queries without full PHP debug output. View results on the Slow Query Monitor tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_LOG', false );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'SCRIPT_DEBUG', false );\ndefine( 'SAVEQUERIES', true );",
			),
			'full_local'  => array(
				'title'   => __( 'Full local debugging (all on)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'    => __( 'Maximum verbosity for a local machine: on-screen errors, debug.log, unminified assets, and query logging.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'snippet' => "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', true );\ndefine( 'SCRIPT_DEBUG', true );\ndefine( 'SAVEQUERIES', true );",
			),
		);
	}

	/**
	 * Find the wp-config.php path (handles the "one level up" pattern).
	 *
	 * @return string Absolute path, or empty string if not found.
	 */
	private function wpconfig_path(): string {
		return function_exists( 'tsosk_locate_wp_config_path' ) ? tsosk_locate_wp_config_path() : '';
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
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
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
				? __( 'Developer mode enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Developer mode disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
		wp_send_json_success(
			array(
				'message' => $enable
					? __( 'Developer mode enabled. Reload the page to apply.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Developer mode disabled. Reload the page to apply.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'active'  => $enable,
			)
		);
	}

	/**
	 * AJAX: truncate the debug.log file.
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$posted_log = isset( $_POST['log_path'] ) ? sanitize_text_field( wp_unslash( $_POST['log_path'] ) ) : $this->log_path();
		$log = $this->validate_log_writable_path( $posted_log );
		if ( '' === $log ) {
			wp_send_json_error( __( 'This log file cannot be modified from the plugin. Only files under the plugin uploads logs folder may be emptied or shrunk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! file_exists( $log ) ) {
			wp_send_json_error( __( 'Log file not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! wp_is_writable( $log ) ) {
			wp_send_json_error( __( 'Log file is not writable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- truncate local file.
		$result = file_put_contents( $log, '' );
		if ( false === $result ) {
			wp_send_json_error( __( 'Could not clear the log file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		TSOSK_Activity_Log::log( 'debug', 'delete', __( 'Debug log file cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Log file emptied. The file was kept on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: keep only the last N lines of a log file (archive older content).
	 */
	public function ajax_shrink_log(): void {
		check_ajax_referer( 'tsosk_debug_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$posted_log = isset( $_POST['log_path'] ) ? sanitize_text_field( wp_unslash( $_POST['log_path'] ) ) : $this->log_path();
		$keep_lines = isset( $_POST['keep_lines'] ) ? max( 100, min( 5000, absint( wp_unslash( $_POST['keep_lines'] ) ) ) ) : 500;
		$log        = $this->validate_log_writable_path( $posted_log );
		if ( '' === $log || ! file_exists( $log ) || ! is_readable( $log ) ) {
			wp_send_json_error( __( 'This log file cannot be modified from the plugin, or the file was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! wp_is_writable( $log ) ) {
			wp_send_json_error( __( 'Log file is not writable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = (string) file_get_contents( $log );
		if ( '' === $content ) {
			wp_send_json_success( __( 'Log file is already empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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
					__( 'No shrink needed — file has %1$d lines (limit %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$total,
					$keep_lines
				)
			);
		}

		$archive_dir = TSOSK_Config_Storage::get_log_archive_dir();
		$archive_name = basename( $log ) . '.' . gmdate( 'Y-m-d-His' ) . '.bak';
		$archive_path = $archive_dir . '/' . $archive_name;
		$removed      = array_slice( $lines, 0, $total - $keep_lines );
		$kept         = array_slice( $lines, -$keep_lines );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $archive_path, implode( "\n", $removed ) . "\n" ) ) {
			wp_send_json_error( __( 'Could not write archive file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $log, implode( "\n", $kept ) . "\n" ) ) {
			wp_send_json_error( __( 'Could not rewrite log file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		TSOSK_Activity_Log::log(
			'debug',
			'update',
			sprintf(
				/* translators: 1: lines kept, 2: archive filename */
				__( 'Debug log shrunk to last %1$d lines (archive: %2$s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$keep_lines,
				$archive_name
			)
		);

		wp_send_json_success(
			sprintf(
				/* translators: 1: lines kept, 2: archive path */
				__( 'Log reduced to the last %1$d lines. Older entries archived to %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
		$mu_exists        = TSOSK_Config_Storage::json_exists( TSOSK_Config_Storage::DEBUG_JSON );
		$legacy_exists    = defined( 'WPMU_PLUGIN_DIR' ) && file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE );
		$logs             = $this->get_log_files();
		$wpconfig_path    = $this->wpconfig_path();
		$wpconfig_state   = $this->read_wpconfig_constants();
		// If path wasn't found initially, try again now that we know the constants were readable.
		if ( '' === $wpconfig_path ) {
			$wpconfig_path = $this->wpconfig_path(); // retry with all strategies.
		}
		$wpconfig_exists  = '' !== $wpconfig_path;
		$wpconfig_write   = false;

		$dev_active = class_exists( 'TSOSK_Site_Status' ) && TSOSK_Site_Status::is_developer_mode_active();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect error logs and manage debug settings from this tab. Constants already defined in wp-config.php cannot be changed from the plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card tsosk-dev-mode-card">
			<h3><?php esc_html_e( 'Developer mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description tsosk-dev-mode-desc">
				<?php esc_html_e( 'Recommended on staging: one click saves a debug preset (WP_DEBUG, debug.log, SAVEQUERIES; errors hidden from visitors) as JSON in your uploads folder. Reload the page to apply. Does not override constants already set in wp-config.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-dev-mode-actions">
				<button type="button" class="button button-primary" id="tsosk-debug-developer-on"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        <?php disabled( $dev_active ); ?>>
					<?php esc_html_e( 'Enable developer mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="tsosk-debug-developer-off"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        <?php disabled( ! $dev_active ); ?>>
					<?php esc_html_e( 'Disable developer mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-badge <?php echo $dev_active ? 'tsosk-badge-warn' : 'tsosk-badge-info'; ?>" id="tsosk-dev-mode-badge">
					<?php echo $dev_active ? esc_html__( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</div>
			<span class="tsosk-ajax-msg" id="tsosk-debug-msg"></span>
		</div>

<?php if ( $legacy_exists ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: legacy file path */
					__( 'Legacy file found in mu-plugins: %s — save settings once to migrate it to the correct location and delete the old file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<code>' . esc_html( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE ) . '</code>'
				),
				array( 'code' => array() )
			);
			?>
		</div>
		<?php endif; ?>
		<?php if ( $mu_exists ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: file path */
					__( 'Active config file: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<code>' . esc_html( $this->config_path() ) . '</code>'
				),
				array( 'code' => array() )
			);
			?>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Debug Constants', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Values active on this page load. Use Developer mode above to toggle the debug preset. Constants locked in wp-config.php are labelled below and cannot be overridden here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<div class="tsosk-guide-card" style="margin:14px 0 18px;">
				<h4 class="tsosk-guide-title" style="font-size:14px;">
					<?php esc_html_e( 'Edit wp-config.php manually', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</h4>
				<p class="description" style="margin:0 0 10px;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: marker line in wp-config.php */
							__( 'Add or change these lines in %1$s before the line %2$s. If a constant is already defined, update its value — do not add a second define() for the same name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'<code>wp-config.php</code>',
							'<code>/* That\'s all, stop editing! Happy publishing. */</code>'
						),
						array( 'code' => array() )
					);
					?>
				</p>
				<?php if ( $wpconfig_exists ) : ?>
				<p class="description" style="margin:0 0 12px;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: absolute path to wp-config.php */
							__( 'Detected file: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'<code>' . esc_html( $wpconfig_path ) . '</code>'
						),
						array( 'code' => array() )
					);
					?>
				</p>
				<?php endif; ?>
				<p class="description" style="margin:0 0 12px;">
					<?php esc_html_e( 'This plugin can also apply the developer preset via JSON in your uploads folder (no wp-config.php edit). Constants defined in wp-config.php always win and cannot be changed from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>

				<?php foreach ( $this->wpconfig_debug_snippets() as $preset_key => $preset ) : ?>
				<details class="tsosk-debug-advanced"<?php echo 'developer' === $preset_key ? ' open' : ''; ?>>
					<summary><?php echo esc_html( $preset['title'] ); ?></summary>
					<p class="description" style="margin:0 0 8px;"><?php echo esc_html( $preset['desc'] ); ?></p>
					<pre class="tsosk-code" style="margin:0;padding:12px;background:#fff;border:1px solid #dcdcde;border-radius:3px;overflow:auto;white-space:pre;"><?php echo esc_html( $preset['snippet'] ); ?></pre>
				</details>
				<?php endforeach; ?>
			</div>

			<div class="tsosk-debug-flags" id="tsosk-debug-form">
				<?php
				$flags = array(
					'debug'   => array(
						'label'   => 'WP_DEBUG',
						'desc'    => __( 'Enables PHP error reporting and additional debug information.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'current' => defined( 'WP_DEBUG' ) ? ( WP_DEBUG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					'log'     => array(
						'label'   => 'WP_DEBUG_LOG',
						'desc'    => __( 'Saves errors to wp-content/debug.log.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'current' => defined( 'WP_DEBUG_LOG' ) ? ( WP_DEBUG_LOG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					'display' => array(
						'label'   => 'WP_DEBUG_DISPLAY',
						'desc'    => __( 'Shows PHP errors on screen (disable on production).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'current' => defined( 'WP_DEBUG_DISPLAY' ) ? ( WP_DEBUG_DISPLAY ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					'script'  => array(
						'label'   => 'SCRIPT_DEBUG',
						'desc'    => __( 'Loads unminified CSS/JS from WordPress core.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'current' => defined( 'SCRIPT_DEBUG' ) ? ( SCRIPT_DEBUG ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					'queries' => array(
						'label'   => 'SAVEQUERIES',
						'desc'    => __( 'Records DB queries for the Slow Query Monitor. View the live query list on that tab after enabling.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'current' => defined( 'SAVEQUERIES' ) ? ( SAVEQUERIES ? 'true' : 'false' ) : __( 'not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
				);
				foreach ( $flags as $key => $flag ) :
					$actual_on     = in_array( $flag['current'], array( 'true', '1' ), true );
					$flag_constant = $flag['label'];
					$wc_state      = $wpconfig_state[ $flag_constant ] ?? array( 'defined' => false, 'value' => null, 'line' => 0 );
					$in_wpconfig   = $wc_state['defined'];
					$wc_val        = $wc_state['value'];
					$wc_mismatch   = $in_wpconfig && $wc_val !== ( $actual_on ? 'true' : 'false' );
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
						<?php esc_html_e( 'Actual:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						<code class="<?php echo $actual_on ? 'tsosk-val-true' : 'tsosk-val-false'; ?>">
							<?php echo esc_html( $flag['current'] ); ?>
						</code>
					</span>

					<?php if ( $in_wpconfig ) : ?>
					<span class="tsosk-badge tsosk-badge-<?php echo $wc_mismatch ? 'warn' : 'info'; ?>"
					      style="font-size:11px;">
						<?php
						/* translators: %s: constant value in wp-config.php */
						printf( esc_html__( 'wp-config.php → %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), esc_html( (string) ( $wc_val ?? 'n/a' ) ) );
						?>
					</span>
					<?php endif; ?>

				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Available Error Logs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Shows readable WordPress/PHP error logs detected inside this WordPress installation. Previews are limited to the end of each file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-notice tsosk-notice-info">
				<strong><?php esc_html_e( 'debug.log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> —
				<?php esc_html_e( 'Created by WordPress when WP_DEBUG and WP_DEBUG_LOG are true. Enable them with Developer mode above.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<br>
				<strong><?php esc_html_e( 'error_log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> —
				<?php esc_html_e( 'Created by PHP/your hosting server when PHP error logging is active. The plugin can preview and download these files. Emptying or shrinking is limited to log files inside the plugin uploads folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Modified', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<span class="tsosk-th-hint"><?php esc_html_e( 'View = scroll to preview below. Empty = delete entries, keep file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
						<tr class="tsosk-log-row" data-log-path="<?php echo esc_attr( $log['path'] ); ?>" data-log-modified="<?php echo esc_attr( (string) $log['modified'] ); ?>">
							<td><?php echo esc_html( $log['label'] ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $log['path'] ); ?></td>
							<td class="tsosk-log-size-cell"><?php echo $log['exists'] ? esc_html( size_format( $log['size'], 2 ) ) : esc_html__( 'Not found', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
							<td class="tsosk-log-modified-cell"><?php echo $log['modified'] ? esc_html( $this->format_log_modified( $log['modified'] ) ) : esc_html__( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
							<td class="tsosk-log-actions-cell">
								<div class="tsosk-log-actions">
									<?php if ( $log['exists'] && $log['readable'] ) : ?>
										<a href="#tsosk-log-<?php echo esc_attr( md5( $log['path'] ) ); ?>" class="button button-small">
											<?php esc_html_e( 'View content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</a>
										<button type="button" class="button button-small tsosk-refresh-log"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>"
										        data-log-path="<?php echo esc_attr( $log['path'] ); ?>">
											<?php esc_html_e( 'Refresh', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( $log['exists'] && $log['writable'] ) : ?>
										<button type="button" class="button button-small tsosk-shrink-log"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>"
										        data-log-path="<?php echo esc_attr( $log['path'] ); ?>"
										        data-log-label="<?php echo esc_attr( $log['label'] ); ?>">
											<?php esc_html_e( 'Shrink (keep last 500 lines)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
										<button type="button" class="button button-small button-link-delete tsosk-clear-log"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>"
										        data-log-path="<?php echo esc_attr( $log['path'] ); ?>"
										        data-log-label="<?php echo esc_attr( $log['label'] ); ?>">
											<?php esc_html_e( 'Empty file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
			<div class="tsosk-card tsosk-log-card" id="tsosk-log-<?php echo esc_attr( md5( $log['path'] ) ); ?>"
			     data-log-path="<?php echo esc_attr( $log['path'] ); ?>"
			     data-log-modified="<?php echo esc_attr( (string) $log['modified'] ); ?>">
				<h3><?php echo esc_html( $log['label'] ); ?></h3>
				<p class="tsosk-log-meta">
					<code><?php echo esc_html( $log['path'] ); ?></code>
					-
					<strong class="tsosk-log-size"><?php echo esc_html( size_format( $log['size'], 2 ) ); ?></strong>
				</p>
				<div class="tsosk-toolbar">
					<input type="text" class="tsosk-log-search" placeholder="<?php esc_attr_e( 'Search this log...', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					<select class="tsosk-log-level">
						<option value="all"><?php esc_html_e( 'All levels', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="error"><?php esc_html_e( 'Errors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="warning"><?php esc_html_e( 'Warnings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="notice"><?php esc_html_e( 'Notices', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="deprecated"><?php esc_html_e( 'Deprecated', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					</select>
					<button type="button" class="button button-secondary tsosk-refresh-log"
					        data-nonce="<?php echo esc_attr( $nonce ); ?>"
					        data-log-path="<?php echo esc_attr( $log['path'] ); ?>">
						<?php esc_html_e( 'Refresh', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'tsosk_debug_download_log', 'log_path' => $log['path'] ), admin_url( 'admin-post.php' ) ), 'tsosk_debug_download_log' ) ); ?>">
						<?php esc_html_e( 'Download Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</a>
				</div>
				<div class="tsosk-log-preview" id="tsosk-debug-log-content-<?php echo esc_attr( md5( $log['path'] ) ); ?>">
					<?php echo $this->render_log_preview_html( $log['path'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
				</div>
			</div>
		<?php endforeach; ?>

		<?php
	}
}
