<?php
/**
 * TSO Swiss Knife – Module: Site Snapshot (export/import configuration).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Site_Snapshot
 */
class TSOSK_Mod_Site_Snapshot {

	/** Current snapshot schema version. */
	private const SCHEMA_VERSION = 3;

	/** Supported schema versions for import. */
	private const SUPPORTED_VERSIONS = array( 2, 3 );

	/** Marker used when an option is missing on the source site. */
	private const MISSING = '__tsosk_missing__';

	/** @var TSOSK_Mod_Site_Snapshot|null */
	private static $instance = null;

	/**
	 * Exportable TSO Swiss Knife options (section id => option name).
	 *
	 * @return array<string, string>
	 */
	public static function get_export_map(): array {
		return array(
			'redirects'            => 'tsosk_redirect_rules',
			'heartbeat'            => 'tsosk_heartbeat_settings',
			'update_manager'       => 'tsosk_update_manager_settings',
			'rest_api'             => 'tsosk_rest_settings',
			'maintenance'          => 'tsosk_maintenance',
			'hidden_profiles'      => 'tsosk_hidden_profiles',
			'alert_settings'       => 'tsosk_alert_settings',
			'health_suppress'      => 'tsosk_health_suppress',
			'login_protect'        => 'tsosk_login_protect',
			'login_lockouts'       => 'tsosk_login_lockouts',
			'login_attempts'       => 'tsosk_login_attempts',
			'slow_queries'         => 'tsosk_slow_query_settings',
			'custom_404'           => 'tsosk_custom_404',
			'admin_menu'           => 'tsosk_admin_menu_settings',
			'admin_menu_manifest'  => 'tsosk_admin_menu_manifest',
			'disabled_image_sizes' => 'tsosk_disabled_image_sizes',
			'fi_ignored'           => 'tsosk_fi_ignored',
			'comment_antispam'     => 'tsosk_comment_antispam',
			'staging'              => 'tsosk_staging_settings',
		);
	}

	/**
	 * Operational / log-like sections (unchecked by default on export).
	 *
	 * @return string[]
	 */
	public static function get_operational_sections(): array {
		return array(
			'login_lockouts',
			'login_attempts',
			'admin_menu_manifest',
		);
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_tsosk_snapshot_export', array( $this, 'handle_export' ) );
		add_action( 'wp_ajax_tsosk_snapshot_import', array( $this, 'ajax_import' ) );
	}

	/**
	 * Human labels for export sections.
	 *
	 * @return array<string, string>
	 */
	public static function get_section_labels(): array {
		return array(
			'redirects'            => __( 'Redirects', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'heartbeat'            => __( 'Heartbeat settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update_manager'       => __( 'Update Manager settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'rest_api'             => __( 'REST API settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'maintenance'          => __( 'Maintenance mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hidden_profiles'      => __( 'Hidden WordPress profiles (runtime flags)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'alert_settings'       => __( 'Health email alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'health_suppress'      => __( 'Site Health notice suppression', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'login_protect'        => __( 'Login protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'login_lockouts'       => __( 'Login lockout log (operational)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'login_attempts'       => __( 'Login attempt counters (operational)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'slow_queries'         => __( 'Slow query monitor settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'custom_404'           => __( 'Custom 404 page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'admin_menu'           => __( 'Admin menu customizer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'admin_menu_manifest'  => __( 'Admin menu manifest (operational)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disabled_image_sizes' => __( 'Disabled image sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'fi_ignored'           => __( 'File integrity ignored files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'comment_antispam'     => __( 'Comment Anti-Spam settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'staging'              => __( 'Staging Mode settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Build snapshot payload.
	 *
	 * @param string[] $sections Section ids to include.
	 * @return array<string, mixed>
	 */
	public function build_snapshot( array $sections ): array {
		$map     = self::get_export_map();
		$payload = array();

		foreach ( $sections as $section ) {
			$section = sanitize_key( $section );
			if ( ! isset( $map[ $section ] ) ) {
				continue;
			}
			$option = $map[ $section ];
			$value  = get_option( $option, self::MISSING );
			// null = option missing on source → import will reset/delete on target.
			$payload[ $section ] = ( self::MISSING === $value ) ? null : $value;
		}

		return array(
			'format'      => 'tsosk-site-snapshot',
			'version'     => self::SCHEMA_VERSION,
			'plugin'      => 'tso-swiss-knife',
			'exported_at' => gmdate( 'c' ),
			'site_url'    => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'locale'      => get_locale(),
			'sections'    => $payload,
		);
	}

	/**
	 * Download JSON export.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_snapshot_export' );

		$sections = array();
		if ( isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ) {
			foreach ( wp_unslash( $_POST['sections'] ) as $section ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$sections[] = sanitize_key( (string) $section );
			}
		}
		if ( isset( $_POST['tsosk_snapshot_export'] ) && empty( $sections ) ) {
			wp_die(
				esc_html__( 'Select at least one section to export.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				esc_html__( 'Export/Import TSO Configuration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		if ( empty( $sections ) ) {
			$sections = array_keys( self::get_export_map() );
		}

		$data = $this->build_snapshot( $sections );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Could not encode snapshot.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$exported_sections = array_keys( $data['sections'] ?? array() );
		if ( ! empty( $exported_sections ) ) {
			TSOSK_Activity_Log::log(
				'site-snapshot',
				'export',
				sprintf(
					/* translators: %s: comma-separated section labels */
					__( 'Site snapshot exported: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$this->format_section_list( $exported_sections )
				)
			);
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/[^a-z0-9.-]/i', '', $host ) : 'site';
		$file = 'tsosk-snapshot-' . $host . '-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file ) . '"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body.
		echo $json;
		exit;
	}

	/**
	 * AJAX import.
	 */
	public function ajax_import(): void {
		check_ajax_referer( 'tsosk_snapshot_import_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$raw = TSOSK_Support::get_post_scalar( 'snapshot' );
		if ( '' === $raw ) {
			wp_send_json_error( __( 'No snapshot data received.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			wp_send_json_error( __( 'The snapshot JSON could not be parsed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ( $data['format'] ?? '' ) !== 'tsosk-site-snapshot' ) {
			wp_send_json_error( __( 'Invalid snapshot file format.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$version = absint( $data['version'] ?? 0 );
		if ( ! in_array( $version, self::SUPPORTED_VERSIONS, true ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: file version, 2: supported versions */
					__( 'Unsupported snapshot version %1$d. Supported: %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$version,
					implode( ', ', self::SUPPORTED_VERSIONS )
				)
			);
		}

		$plugin = sanitize_key( (string) ( $data['plugin'] ?? '' ) );
		if ( '' !== $plugin && 'tso-swiss-knife' !== $plugin ) {
			wp_send_json_error( __( 'This snapshot belongs to a different plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$sections = $data['sections'] ?? array();
		if ( ! is_array( $sections ) || empty( $sections ) ) {
			wp_send_json_error( __( 'Snapshot contains no sections to import.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$filter_submitted = array_key_exists( 'import_sections', $_POST );
		$sections_filter  = array();
		if ( $filter_submitted && isset( $_POST['import_sections'] ) && is_array( $_POST['import_sections'] ) ) {
			foreach ( wp_unslash( $_POST['import_sections'] ) as $section ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$sections_filter[] = sanitize_key( (string) $section );
			}
			$sections_filter = array_values( array_unique( array_filter( $sections_filter ) ) );
		}
		if ( $filter_submitted && empty( $sections_filter ) ) {
			wp_send_json_error( __( 'Select at least one section to import.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$map      = self::get_export_map();
		$imported = array();
		$skipped  = array();
		$notes    = array();
		$old_lp   = get_option( 'tsosk_login_protect', array() );
		$old_slug = ( is_array( $old_lp ) && ! empty( $old_lp['login_slug'] ) ) ? (string) $old_lp['login_slug'] : '';

		foreach ( $sections as $section => $value ) {
			$section = sanitize_key( (string) $section );
			if ( ! isset( $map[ $section ] ) ) {
				continue;
			}
			if ( ! empty( $sections_filter ) && ! in_array( $section, $sections_filter, true ) ) {
				continue;
			}

			// Explicit null = reset to defaults (delete stored option).
			if ( null === $value ) {
				delete_option( $map[ $section ] );
				$imported[] = $section;
				continue;
			}

			$validated = $this->validate_section_value( $section, $value );
			if ( is_wp_error( $validated ) ) {
				$skipped[] = $section . ': ' . $validated->get_error_message();
				continue;
			}

			$autoload = ( 'staging' === $section );
			update_option( $map[ $section ], $validated, $autoload );
			$imported[] = $section;

			if ( 'slow_queries' === $section && is_array( $validated ) && ! empty( $validated['enabled'] ) ) {
				$note = $this->maybe_enable_savequeries_after_import();
				if ( '' !== $note ) {
					$notes[] = $note;
				}
			}

			if ( 'staging' === $section && is_array( $validated ) && in_array( true, $validated, true ) ) {
				$notes[] = __( 'Staging Mode switches were imported as enabled. Confirm this is a test site, or turn them off under Staging Mode before going live.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
		}

		if ( in_array( 'login_protect', $imported, true ) ) {
			// Import forces custom_url off; drop old and imported slugs from rewrite tables before flush.
			if ( class_exists( 'TSOSK_Mod_Login_Protect' ) ) {
				if ( '' !== $old_slug ) {
					TSOSK_Mod_Login_Protect::purge_custom_login_rewrite( $old_slug );
				}
				$lp = get_option( 'tsosk_login_protect', array() );
				if ( is_array( $lp ) && ! empty( $lp['login_slug'] ) && (string) $lp['login_slug'] !== $old_slug ) {
					TSOSK_Mod_Login_Protect::purge_custom_login_rewrite( (string) $lp['login_slug'] );
				}
			}
			flush_rewrite_rules( false );
		}

		if ( empty( $imported ) ) {
			$message = ! empty( $skipped )
				? implode( ' ', $skipped )
				: __( 'No recognised sections were imported.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			wp_send_json_error( $message );
		}

		TSOSK_Activity_Log::log(
			'site-snapshot',
			'import',
			sprintf(
				/* translators: %s: comma-separated section labels */
				__( 'Site snapshot imported: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$this->format_section_list( $imported )
			)
		);

		$response = sprintf(
			/* translators: %s: comma-separated section labels */
			__( 'Imported sections: %s. Reload affected tabs to verify settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$this->format_section_list( $imported )
		);
		if ( ! empty( $skipped ) ) {
			$response .= ' ' . sprintf(
				/* translators: %s: skipped section errors */
				__( 'Skipped invalid sections: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( '; ', $skipped )
			);
		}
		if ( ! empty( $notes ) ) {
			$response .= ' ' . implode( ' ', $notes );
		}

		wp_send_json_success( $response );
	}

	/**
	 * After importing Slow Query enabled=true, try to enable SAVEQUERIES like the normal save path.
	 *
	 * @return string Notice text (empty if none).
	 */
	private function maybe_enable_savequeries_after_import(): string {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			return '';
		}
		if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
			return __( 'Slow Query Monitor is enabled in settings, but SAVEQUERIES is defined as false and cannot be overridden — change wp-config.php or Debug Mode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( class_exists( 'TSOSK_Mod_Debug' ) ) {
			$result = TSOSK_Mod_Debug::get_instance()->set_savequeries_flag( true );
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
			return __( 'SAVEQUERIES was enabled in the debug config — reload for Slow Query Monitor to capture queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'Slow Query Monitor is enabled, but SAVEQUERIES is not active — enable it in Debug Mode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Validate and sanitize one imported section payload.
	 *
	 * @param string $section Section id.
	 * @param mixed  $value   Raw JSON value.
	 * @return mixed|WP_Error
	 */
	private function validate_section_value( string $section, $value ) {
		switch ( $section ) {
			case 'redirects':
				return $this->sanitize_redirects_import( $value );
			case 'heartbeat':
				return $this->sanitize_heartbeat_import( $value );
			case 'update_manager':
				return $this->sanitize_update_manager_import( $value );
			case 'rest_api':
				return $this->sanitize_rest_api_import( $value );
			case 'maintenance':
				return $this->sanitize_maintenance_import( $value );
			case 'hidden_profiles':
				return $this->sanitize_hidden_profiles_import( $value );
			case 'alert_settings':
				return $this->sanitize_alert_settings_import( $value );
			case 'health_suppress':
				return $this->sanitize_health_suppress_import( $value );
			case 'login_protect':
				return $this->sanitize_login_protect_import( $value );
			case 'login_lockouts':
				return $this->sanitize_lockout_log_import( $value );
			case 'login_attempts':
				return $this->sanitize_attempts_import( $value );
			case 'slow_queries':
				return $this->sanitize_slow_queries_import( $value );
			case 'custom_404':
				return $this->sanitize_custom_404_import( $value );
			case 'admin_menu':
				return $this->sanitize_admin_menu_import( $value );
			case 'admin_menu_manifest':
				return $this->sanitize_admin_menu_manifest_import( $value );
			case 'fi_ignored':
			case 'disabled_image_sizes':
				return $this->sanitize_string_list_import( $value, 'fi_ignored' === $section ? 500 : 50 );
			case 'comment_antispam':
				return $this->sanitize_comment_antispam_import( $value );
			case 'staging':
				return $this->sanitize_staging_import( $value );
			default:
				return new WP_Error(
					'unknown_section',
					sprintf(
						/* translators: %s: section id */
						__( 'Unknown section "%s".', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$section
					)
				);
		}
	}

	/**
	 * @param mixed $value Raw redirects option.
	 * @return array|WP_Error
	 */
	private function sanitize_redirects_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_redirects', __( 'Redirects section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! class_exists( 'TSOSK_Mod_Redirects' ) ) {
			return new WP_Error( 'no_redirects_module', __( 'Redirects module is not available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$mod = TSOSK_Mod_Redirects::get_instance();
		$out = array();
		foreach ( $value as $id => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $rule['id'] ?? $id ) );
			if ( '' === $id ) {
				continue;
			}
			$rule['id'] = $id;
			$validated  = $mod->sanitize_rule_for_storage( $rule );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			unset( $validated['target_url'] );
			$out[ $id ] = $validated;
		}

		return $out;
	}

	/**
	 * @param mixed $value Raw heartbeat settings.
	 * @return array|WP_Error
	 */
	private function sanitize_heartbeat_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_heartbeat', __( 'Heartbeat section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$mode = sanitize_key( (string) ( $value['mode'] ?? 'default' ) );
		if ( ! in_array( $mode, array( 'default', 'disable_frontend', 'disable_post', 'disable_all' ), true ) ) {
			$mode = 'default';
		}
		return array(
			'mode'     => $mode,
			'interval' => max( 0, min( 3600, absint( $value['interval'] ?? 0 ) ) ),
		);
	}

	/**
	 * @param mixed $value Raw update manager settings.
	 * @return array|WP_Error
	 */
	private function sanitize_update_manager_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_update_manager', __( 'Update Manager section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$defaults = class_exists( 'TSOSK_Mod_Update_Manager' )
			? TSOSK_Mod_Update_Manager::get_defaults()
			: array(
				'preset'             => 'default',
				'block_core'         => false,
				'block_plugins'      => false,
				'block_themes'       => false,
				'block_translations' => false,
				'hide_update_nags'   => false,
				'email_core_major'   => true,
				'email_core_minor'   => true,
				'email_core_fail'    => true,
				'email_plugin'       => true,
				'email_theme'        => true,
				'email_manual_core'  => true,
				'plugin_rules'       => array(),
			);
		$preset = sanitize_key( (string) ( $value['preset'] ?? 'default' ) );
		if ( ! in_array( $preset, array( 'default', 'disable_all', 'custom' ), true ) ) {
			$preset = 'default';
		}
		if ( 'auto_all' === $preset ) {
			$preset = 'default';
		}
		$rules = array();
		if ( ! empty( $value['plugin_rules'] ) && is_array( $value['plugin_rules'] ) ) {
			foreach ( $value['plugin_rules'] as $file => $rule ) {
				$file = sanitize_text_field( (string) $file );
				if ( '' === $file || ! is_array( $rule ) ) {
					continue;
				}
				$rules[ $file ] = array(
					'block' => ! empty( $rule['block'] ),
				);
			}
		}
		$out = array(
			'preset'             => $preset,
			'block_core'         => ! empty( $value['block_core'] ),
			'block_plugins'      => ! empty( $value['block_plugins'] ),
			'block_themes'       => ! empty( $value['block_themes'] ),
			'block_translations' => ! empty( $value['block_translations'] ),
			'hide_update_nags'   => ! empty( $value['hide_update_nags'] ),
			'email_core_major'   => array_key_exists( 'email_core_major', $value ) ? ! empty( $value['email_core_major'] ) : $defaults['email_core_major'],
			'email_core_minor'   => array_key_exists( 'email_core_minor', $value ) ? ! empty( $value['email_core_minor'] ) : $defaults['email_core_minor'],
			'email_core_fail'    => array_key_exists( 'email_core_fail', $value ) ? ! empty( $value['email_core_fail'] ) : $defaults['email_core_fail'],
			'email_plugin'       => array_key_exists( 'email_plugin', $value ) ? ! empty( $value['email_plugin'] ) : $defaults['email_plugin'],
			'email_theme'        => array_key_exists( 'email_theme', $value ) ? ! empty( $value['email_theme'] ) : $defaults['email_theme'],
			'email_manual_core'  => array_key_exists( 'email_manual_core', $value ) ? ! empty( $value['email_manual_core'] ) : $defaults['email_manual_core'],
			'plugin_rules'       => $rules,
		);
		return $out;
	}

	/**
	 * @param mixed $value Raw REST settings.
	 * @return array|WP_Error
	 */
	private function sanitize_rest_api_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_rest', __( 'REST API section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$mode = sanitize_key( (string) ( $value['mode'] ?? 'enabled' ) );
		if ( ! in_array( $mode, array( 'enabled', 'disabled' ), true ) ) {
			$mode = 'enabled';
		}
		$namespaces = array();
		if ( ! empty( $value['disabled_namespaces'] ) && is_array( $value['disabled_namespaces'] ) ) {
			foreach ( $value['disabled_namespaces'] as $ns ) {
				$ns = sanitize_text_field( (string) $ns );
				if ( '' !== $ns ) {
					$namespaces[] = $ns;
				}
			}
		}
		return array(
			'mode'                => $mode,
			'disabled_namespaces' => array_values( array_unique( $namespaces ) ),
		);
	}

	/**
	 * @param mixed $value Raw maintenance settings.
	 * @return array|WP_Error
	 */
	private function sanitize_maintenance_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_maintenance', __( 'Maintenance section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$logo_id = absint( $value['logo_id'] ?? 0 );
		if ( $logo_id > 0 && ! wp_attachment_is_image( $logo_id ) ) {
			$logo_id = 0;
		}
		return array(
			'enabled'       => ! empty( $value['enabled'] ),
			'message'       => wp_kses_post( (string) ( $value['message'] ?? '' ) ),
			'page_title'    => sanitize_text_field( (string) ( $value['page_title'] ?? '' ) ),
			'whitelist_ips' => sanitize_textarea_field( (string) ( $value['whitelist_ips'] ?? '' ) ),
			'logo_id'       => $logo_id,
		);
	}

	/**
	 * @param mixed $value Raw hidden profiles runtime flags.
	 * @return array|WP_Error
	 */
	private function sanitize_hidden_profiles_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_hidden_profiles', __( 'Hidden profiles section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$keys = array( 'disable_emojis', 'disable_embeds', 'disable_xmlrpc', 'disable_feeds', 'close_comments' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = ! empty( $value[ $key ] );
		}
		return $out;
	}

	/**
	 * @param mixed $value Raw alert settings.
	 * @return array|WP_Error
	 */
	private function sanitize_alert_settings_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_alerts', __( 'Health alerts section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$email = sanitize_email( (string) ( $value['email'] ?? get_option( 'admin_email' ) ) );
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email' );
		}
		return array(
			'enabled'             => ! empty( $value['enabled'] ),
			'email'               => $email,
			'not_found_threshold' => max( 1, min( 10000, absint( $value['not_found_threshold'] ?? 25 ) ) ),
		);
	}

	/**
	 * @param mixed $value Raw health suppress map.
	 * @return array|WP_Error
	 */
	private function sanitize_health_suppress_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_health_suppress', __( 'Health suppress section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$out = array();
		foreach ( $value as $key => $on ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = ! empty( $on );
		}
		return $out;
	}

	/**
	 * @param mixed $value Raw login protect settings.
	 * @return array|WP_Error
	 */
	private function sanitize_login_protect_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_login_protect', __( 'Login protection section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$roles = array();
		if ( ! empty( $value['email_2fa_roles'] ) && is_array( $value['email_2fa_roles'] ) ) {
			foreach ( $value['email_2fa_roles'] as $role ) {
				$role = sanitize_key( (string) $role );
				if ( '' !== $role ) {
					$roles[] = $role;
				}
			}
		}

		return array(
			// Never import a custom login URL enabled — prevents accidental admin lockout.
			'custom_url'                => false,
			'login_slug'                => sanitize_title_with_dashes( (string) ( $value['login_slug'] ?? '' ) ),
			'brute_force'               => ! empty( $value['brute_force'] ),
			'block_forbidden_usernames' => ! empty( $value['block_forbidden_usernames'] ),
			'max_attempts'              => max( 1, min( 50, absint( $value['max_attempts'] ?? 5 ) ) ),
			'lockout_duration'          => max( 1, min( 1440, absint( $value['lockout_duration'] ?? 30 ) ) ),
			'lockout_window'            => max( 1, min( 60, absint( $value['lockout_window'] ?? 5 ) ) ),
			'whitelist_ips'             => sanitize_textarea_field( (string) ( $value['whitelist_ips'] ?? '' ) ),
			'notify_email'              => ! empty( $value['notify_email'] ),
			'notify_address'            => sanitize_email( (string) ( $value['notify_address'] ?? get_option( 'admin_email' ) ) ),
			'login_maintenance'         => ! empty( $value['login_maintenance'] ),
			'login_maintenance_ips'     => sanitize_textarea_field( (string) ( $value['login_maintenance_ips'] ?? '' ) ),
			'email_2fa'                 => ! empty( $value['email_2fa'] ),
			'email_2fa_roles'           => array_values( array_unique( $roles ) ),
			'role_whitelist_ips'        => sanitize_textarea_field( (string) ( $value['role_whitelist_ips'] ?? '' ) ),
			'notify_mass_threshold'     => max( 0, min( 100, absint( $value['notify_mass_threshold'] ?? 0 ) ) ),
			'notify_mass_window'        => max( 5, min( 1440, absint( $value['notify_mass_window'] ?? 60 ) ) ),
		);
	}

	/**
	 * @param mixed $value Raw lockout log.
	 * @return array|WP_Error
	 */
	private function sanitize_lockout_log_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_lockouts', __( 'Login lockout log must be an array.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( count( $value ) > 200 ) {
			return new WP_Error( 'invalid_lockouts', __( 'Login lockout log exceeds the maximum allowed entries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$out = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = array(
				'ip'        => sanitize_text_field( (string) ( $entry['ip'] ?? '' ) ),
				'username'  => sanitize_user( (string) ( $entry['username'] ?? '' ), true ),
				'count'     => absint( $entry['count'] ?? 0 ),
				'locked_at' => absint( $entry['locked_at'] ?? 0 ),
				'until'     => absint( $entry['until'] ?? 0 ),
				'active'    => ! empty( $entry['active'] ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $value Raw attempt counters.
	 * @return array|WP_Error
	 */
	private function sanitize_attempts_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_attempts', __( 'Login attempt counters must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( count( $value ) > 500 ) {
			return new WP_Error( 'invalid_attempts', __( 'Login attempt counters exceed the maximum allowed entries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$out = array();
		foreach ( $value as $ip => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ip = sanitize_text_field( (string) $ip );
			if ( '' === $ip ) {
				continue;
			}
			$out[ $ip ] = array(
				'count' => absint( $entry['count'] ?? 0 ),
				'first' => absint( $entry['first'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $value Raw slow query settings.
	 * @return array|WP_Error
	 */
	private function sanitize_slow_queries_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_slow_queries', __( 'Slow query settings must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$patterns = array();
		$raw_p    = $value['ignore_patterns'] ?? array();
		if ( is_string( $raw_p ) ) {
			$raw_p = preg_split( '/\r\n|\r|\n/', $raw_p ) ?: array();
		}
		if ( is_array( $raw_p ) ) {
			foreach ( $raw_p as $line ) {
				$line = trim( (string) $line );
				$line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line );
				$line = is_string( $line ) ? $line : '';
				if ( '' !== $line && mb_strlen( $line ) <= 2000 ) {
					$patterns[] = $line;
				}
			}
		}
		$patterns = array_values( array_unique( array_slice( $patterns, 0, 50 ) ) );

		$show_admin_bar = array_key_exists( 'show_admin_bar', $value )
			? ! empty( $value['show_admin_bar'] )
			: true;

		return array(
			'enabled'         => ! empty( $value['enabled'] ),
			'threshold_ms'    => max( 1, min( 10000, absint( $value['threshold_ms'] ?? 100 ) ) ),
			'max_entries'     => max( 50, min( 2000, absint( $value['max_entries'] ?? 500 ) ) ),
			'exclude_ajax'    => ! empty( $value['exclude_ajax'] ),
			'exclude_cron'    => array_key_exists( 'exclude_cron', $value ) ? ! empty( $value['exclude_cron'] ) : true,
			'show_admin_bar'   => $show_admin_bar,
			'ignore_patterns' => $patterns,
		);
	}

	/**
	 * @param mixed $value Raw custom 404 settings.
	 * @return array|WP_Error
	 */
	private function sanitize_custom_404_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_custom_404', __( 'Custom 404 section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$page_id = absint( $value['page_id'] ?? 0 );
		if ( $page_id > 0 && ( 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) ) {
			$page_id = 0;
		}
		return array(
			'page_id'           => $page_id,
			'hide_from_admin'   => ! empty( $value['hide_from_admin'] ),
			'hide_from_search'  => array_key_exists( 'hide_from_search', $value ) ? ! empty( $value['hide_from_search'] ) : true,
			'force_direct_404'  => array_key_exists( 'force_direct_404', $value ) ? ! empty( $value['force_direct_404'] ) : true,
			'send_410'          => ! empty( $value['send_410'] ),
			'disable_url_guess' => ! empty( $value['disable_url_guess'] ),
		);
	}

	/**
	 * @param mixed $value Raw admin menu settings.
	 * @return array|WP_Error
	 */
	private function sanitize_admin_menu_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_admin_menu', __( 'Admin menu section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$hidden = array();
		if ( ! empty( $value['hidden'] ) && is_array( $value['hidden'] ) ) {
			foreach ( $value['hidden'] as $id ) {
				$id = sanitize_text_field( (string) $id );
				if ( '' !== $id ) {
					$hidden[] = $id;
				}
			}
		}

		$labels = array();
		if ( ! empty( $value['labels'] ) && is_array( $value['labels'] ) ) {
			foreach ( $value['labels'] as $id => $label ) {
				$id = sanitize_text_field( (string) $id );
				if ( '' === $id ) {
					continue;
				}
				$labels[ $id ] = sanitize_text_field( (string) $label );
			}
		}

		$top_order = array();
		if ( ! empty( $value['top_order'] ) && is_array( $value['top_order'] ) ) {
			foreach ( $value['top_order'] as $id ) {
				$id = sanitize_text_field( (string) $id );
				if ( '' !== $id ) {
					$top_order[] = $id;
				}
			}
		}

		$sub_order = array();
		if ( ! empty( $value['sub_order'] ) && is_array( $value['sub_order'] ) ) {
			foreach ( $value['sub_order'] as $parent => $children ) {
				$parent = sanitize_text_field( (string) $parent );
				if ( '' === $parent || ! is_array( $children ) ) {
					continue;
				}
				$sub_order[ $parent ] = array();
				foreach ( $children as $child ) {
					$child = sanitize_text_field( (string) $child );
					if ( '' !== $child ) {
						$sub_order[ $parent ][] = $child;
					}
				}
			}
		}

		$relocations = array();
		if ( ! empty( $value['relocations'] ) && is_array( $value['relocations'] ) ) {
			foreach ( $value['relocations'] as $from => $to ) {
				$from = sanitize_text_field( (string) $from );
				$to   = sanitize_text_field( (string) $to );
				if ( '' !== $from && '' !== $to ) {
					$relocations[ $from ] = $to;
				}
			}
		}

		return array(
			'hidden'             => array_values( array_unique( $hidden ) ),
			'labels'             => $labels,
			'top_order'          => array_values( array_unique( $top_order ) ),
			'sub_order'          => $sub_order,
			'sub_order_ids'      => is_array( $value['sub_order_ids'] ?? null ) ? $this->sanitize_string_keyed_lists( $value['sub_order_ids'] ) : array(),
			'sub_order_live'     => is_array( $value['sub_order_live'] ?? null ) ? $this->sanitize_string_keyed_lists( $value['sub_order_live'] ) : array(),
			'sub_order_resolved' => is_array( $value['sub_order_resolved'] ?? null ) ? $this->sanitize_string_keyed_lists( $value['sub_order_resolved'] ) : array(),
			'relocations'        => $relocations,
			'nested_tops'        => $this->sanitize_string_map( $value['nested_tops'] ?? array() ),
		);
	}

	/**
	 * Sanitize an associative string => string map (item id => parent slug).
	 *
	 * @param mixed $value Raw map.
	 * @return array<string, string>
	 */
	private function sanitize_string_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$key  = sanitize_text_field( (string) $key );
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $key && '' !== $item ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $value Raw keyed lists.
	 * @return array<string, array<int, string>>
	 */
	private function sanitize_string_keyed_lists( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $list ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key || ! is_array( $list ) ) {
				continue;
			}
			$out[ $key ] = $this->sanitize_string_list_flat( $list );
		}
		return $out;
	}

	/**
	 * @param mixed $value Raw list.
	 * @return string[]
	 */
	private function sanitize_string_list_flat( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $value Raw admin menu manifest.
	 * @return array|WP_Error
	 */
	private function sanitize_admin_menu_manifest_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_manifest', __( 'Admin menu manifest must be an object or array.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		// Manifest is large/opaque; keep structure but deep-sanitize scalars.
		return map_deep(
			$value,
			static function ( $item ) {
				if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
					return $item;
				}
				if ( is_string( $item ) ) {
					return sanitize_text_field( $item );
				}
				return '';
			}
		);
	}

	/**
	 * @param mixed $value Raw staging settings.
	 * @return array|WP_Error
	 */
	private function sanitize_staging_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_staging', __( 'Staging Mode section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( class_exists( 'TSOSK_Mod_Staging' ) ) {
			return TSOSK_Mod_Staging::sanitize_settings( $value );
		}
		return array(
			'show_badge'       => ! empty( $value['show_badge'] ),
			'hide_from_search' => ! empty( $value['hide_from_search'] ),
			'block_mail'       => ! empty( $value['block_mail'] ),
			'log_mail'         => ! empty( $value['log_mail'] ),
			'pause_cron'       => ! empty( $value['pause_cron'] ),
		);
	}

	/**
	 * @param mixed $value Raw comment antispam settings.
	 * @return array|WP_Error
	 */
	private function sanitize_comment_antispam_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_cas', __( 'Comment Anti-Spam section must be an object.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$defaults = class_exists( 'TSOSK_Mod_Comment_Antispam' )
			? TSOSK_Mod_Comment_Antispam::get_defaults()
			: array();
		$bool_keys = array(
			'enabled',
			'protect_comments',
			'protect_contact_forms',
			'honeypot',
			'time_trap',
			'rate_limit',
			'block_disposable_email',
			'duplicate_check',
			'block_cyrillic',
			'stopforumspam_enabled',
			'abuseipdb_enabled',
			'honeypot_httpbl_enabled',
			'skip_logged_in',
			'log_blocks',
		);
		$out = $defaults;
		foreach ( $bool_keys as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$out[ $key ] = ! empty( $value[ $key ] );
			}
		}
		$out['min_submit_seconds']     = max( 0, min( 600, absint( $value['min_submit_seconds'] ?? ( $defaults['min_submit_seconds'] ?? 3 ) ) ) );
		$out['max_submit_seconds']     = max( 60, min( 86400, absint( $value['max_submit_seconds'] ?? ( $defaults['max_submit_seconds'] ?? 7200 ) ) ) );
		$out['rate_limit_count']       = max( 1, min( 100, absint( $value['rate_limit_count'] ?? ( $defaults['rate_limit_count'] ?? 3 ) ) ) );
		$out['rate_limit_window']      = max( 1, min( 1440, absint( $value['rate_limit_window'] ?? ( $defaults['rate_limit_window'] ?? 60 ) ) ) );
		$out['max_links']              = max( 0, min( 50, absint( $value['max_links'] ?? ( $defaults['max_links'] ?? 2 ) ) ) );
		$out['duplicate_window']       = max( 1, min( 1440, absint( $value['duplicate_window'] ?? ( $defaults['duplicate_window'] ?? 60 ) ) ) );
		$out['sfs_min_confidence']     = max( 0, min( 100, absint( $value['sfs_min_confidence'] ?? ( $defaults['sfs_min_confidence'] ?? 50 ) ) ) );
		$out['abuseipdb_min_score']    = max( 0, min( 100, absint( $value['abuseipdb_min_score'] ?? ( $defaults['abuseipdb_min_score'] ?? 75 ) ) ) );
		$out['abuseipdb_max_age_days'] = max( 1, min( 365, absint( $value['abuseipdb_max_age_days'] ?? ( $defaults['abuseipdb_max_age_days'] ?? 30 ) ) ) );
		$out['honeypot_min_threat']    = max( 0, min( 100, absint( $value['honeypot_min_threat'] ?? ( $defaults['honeypot_min_threat'] ?? 25 ) ) ) );
		$out['block_keywords']         = sanitize_textarea_field( (string) ( $value['block_keywords'] ?? '' ) );
		$out['block_urls']             = sanitize_textarea_field( (string) ( $value['block_urls'] ?? '' ) );
		$out['custom_disposable_domains'] = sanitize_textarea_field( (string) ( $value['custom_disposable_domains'] ?? '' ) );
		$out['whitelist_ips']          = sanitize_textarea_field( (string) ( $value['whitelist_ips'] ?? '' ) );
		$out['cleantalk_key']          = sanitize_text_field( (string) ( $value['cleantalk_key'] ?? '' ) );
		$out['abuseipdb_key']          = sanitize_text_field( (string) ( $value['abuseipdb_key'] ?? '' ) );
		$out['honeypot_access_key']    = sanitize_text_field( (string) ( $value['honeypot_access_key'] ?? '' ) );
		$cloud = sanitize_key( (string) ( $value['cloud_mode'] ?? 'off' ) );
		$out['cloud_mode'] = in_array( $cloud, array( 'off', 'cleantalk' ), true ) ? $cloud : 'off';
		$action = sanitize_key( (string) ( $value['spam_action'] ?? 'spam' ) );
		$out['spam_action'] = in_array( $action, array( 'spam', 'trash', 'reject' ), true ) ? $action : 'spam';
		return $out;
	}

	/**
	 * @param mixed $value   Raw list.
	 * @param int   $max     Max items.
	 * @return string[]|WP_Error
	 */
	private function sanitize_string_list_import( $value, int $max ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_list', __( 'Expected a list of strings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( count( $value ) > $max ) {
			return new WP_Error(
				'invalid_list',
				sprintf(
					/* translators: %d: maximum items */
					__( 'List exceeds the maximum of %d items.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$max
				)
			);
		}

		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Comma-separated human labels for snapshot section ids.
	 *
	 * @param string[] $section_ids Section ids.
	 * @return string
	 */
	private function format_section_list( array $section_ids ): string {
		$labels = self::get_section_labels();
		$names  = array();

		foreach ( $section_ids as $section_id ) {
			$section_id = sanitize_key( (string) $section_id );
			$names[]    = $labels[ $section_id ] ?? $section_id;
		}

		return implode( ', ', $names );
	}

	/**
	 * Current site metadata for import comparison (read-only).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_current_environment(): array {
		return array(
			'site_url'       => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'locale'         => get_locale(),
			'plugin'         => defined( 'TSOSK_VERSION' ) ? TSOSK_VERSION : '',
			'schema_version' => (string) self::SCHEMA_VERSION,
		);
	}

	/**
	 * Whether each export section has stored data on this site.
	 *
	 * @return array<string, string>
	 */
	public static function get_section_status_summary(): array {
		$map = self::get_export_map();
		$out = array();

		foreach ( $map as $section => $option ) {
			$value = get_option( $option, self::MISSING );
			if ( self::MISSING === $value ) {
				$out[ $section ] = 'missing';
			} elseif ( is_array( $value ) ) {
				$out[ $section ] = sprintf(
					/* translators: %d: number of array items */
					__( 'array (%d items)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $value )
				);
			} elseif ( is_string( $value ) ) {
				$out[ $section ] = size_format( strlen( $value ), 1 );
			} else {
				$out[ $section ] = __( 'set', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
		}

		return $out;
	}

	/**
	 * Render admin UI.
	 */
	public function render(): void {
		$export_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=tsosk_snapshot_export' ),
			'tsosk_snapshot_export'
		);
		$import_nonce = wp_create_nonce( 'tsosk_snapshot_import_nonce' );
		$labels       = self::get_section_labels();
		$operational  = self::get_operational_sections();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Save and restore selected TSO Swiss Knife settings as JSON for staging, backups, or migrations. Includes plugin options such as Redirects, Staging Mode switches, Slow Query Monitor, Comment Anti-Spam, and Login Protect. Does not include Debug/Security JSON under uploads, sandbox sessions, the Staging mail log, or the Slow Query log.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-warn">
			<?php esc_html_e( 'Import overwrites existing settings for the selected sections. Always export a backup first and test on staging. Cross-site page/media IDs are validated and reset if missing on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<div class="tsosk-notice">
			<?php
			$config_dir = function_exists( 'tsosk_get_uploads_subdir' ) ? tsosk_get_uploads_subdir( 'config' ) : '';
			echo esc_html__( 'This snapshot does not include Debug Mode, Security, or Hidden Profiles JSON flags stored under the plugin uploads config folder. Copy that folder separately if you need the same constants on another site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			if ( '' !== $config_dir ) {
				echo ' <code>' . esc_html( $config_dir ) . '</code>';
			}
			?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Export snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" id="tsosk-snapshot-export-form">
				<?php wp_nonce_field( 'tsosk_snapshot_export' ); ?>
				<input type="hidden" name="tsosk_snapshot_export" value="1">
				<p class="description"><?php esc_html_e( 'Select sections to include in the JSON file. Operational logs are unchecked by default.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				<ul class="tsosk-snapshot-section-list">
					<?php foreach ( $labels as $id => $label ) : ?>
					<li>
						<label>
							<input type="checkbox" name="sections[]" value="<?php echo esc_attr( $id ); ?>"
								<?php checked( ! in_array( $id, $operational, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					</li>
					<?php endforeach; ?>
				</ul>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download JSON snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			</form>
		</div>

		<div class="tsosk-card tsosk-snapshot-import-card">
			<h3><?php esc_html_e( 'Import snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste JSON from a previous export or upload a .json file. You can choose which sections to restore. Null sections reset the target option to defaults.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-snapshot-import-file">
				<input type="file" id="tsosk-snapshot-file" class="tsosk-snapshot-file-input" accept=".json,application/json" tabindex="-1">
				<button type="button" class="button" id="tsosk-snapshot-file-btn" aria-controls="tsosk-snapshot-file">
					<?php esc_html_e( 'Choose a file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span id="tsosk-snapshot-file-name" class="tsosk-snapshot-file-name">
					<?php esc_html_e( 'No file chosen', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</div>
			<label for="tsosk-snapshot-json" class="tsosk-snapshot-json-label"><strong><?php esc_html_e( 'Or paste JSON here', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
			<textarea id="tsosk-snapshot-json" rows="12" class="tsosk-snapshot-json large-text code"
			          placeholder="<?php esc_attr_e( '{ "format": "tsosk-site-snapshot", ... }', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"></textarea>
			<div id="tsosk-snapshot-import-sections" class="tsosk-card tsosk-snapshot-import-sections">
				<p class="description tsosk-snapshot-import-sections-desc">
					<strong><?php esc_html_e( 'Sections in this file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
					<?php esc_html_e( 'Uncheck any section you do not want to overwrite.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<ul id="tsosk-snapshot-import-list" class="tsosk-snapshot-section-list"></ul>
			</div>
			<div id="tsosk-snapshot-env-diff" class="tsosk-card tsosk-snapshot-env-diff" hidden>
				<h4><?php esc_html_e( 'Environment comparison', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Compares snapshot metadata with this site before import. Differences do not block import but help avoid surprises on staging or production.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-snapshot-env-diff-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Field', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'In snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'This site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
			<p class="tsosk-snapshot-import-actions">
				<button type="button" class="button button-primary" id="tsosk-snapshot-import"
				        data-nonce="<?php echo esc_attr( $import_nonce ); ?>">
					<?php esc_html_e( 'Import snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-snapshot-msg"></span>
			</p>
		</div>
		<?php
	}
}
