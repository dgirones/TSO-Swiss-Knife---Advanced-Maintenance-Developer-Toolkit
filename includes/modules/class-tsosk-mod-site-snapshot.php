<?php
/**
 * TSO Swiss Knife – Module: Site Snapshot.
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

	/** @var TSOSK_Mod_Site_Snapshot|null */
	private static $instance = null;

	/**
	 * Exportable TSO Swiss Knife options (section id => option name).
	 *
	 * @return array<string, string>
	 */
	public static function get_export_map(): array {
		return array(
			'redirects'         => 'tsosk_redirect_rules',
			'heartbeat'         => 'tsosk_heartbeat_settings',
			'update_manager'    => 'tsosk_update_manager_settings',
			'rest_api'          => 'tsosk_rest_settings',
			'maintenance'       => 'tsosk_maintenance',
			'hidden_profiles'   => 'tsosk_hidden_profiles',
			'alert_settings'    => 'tsosk_alert_settings',
			'health_suppress'   => 'tsosk_health_suppress',
			'login_protect'     => 'tsosk_login_protect',
			'login_lockouts'    => 'tsosk_login_lockouts',
			'login_attempts'    => 'tsosk_login_attempts',
			'slow_queries'      => 'tsosk_slow_query_settings',
			'custom_404'        => 'tsosk_custom_404',
			'admin_menu'        => 'tsosk_admin_menu_settings',
			'admin_menu_manifest' => 'tsosk_admin_menu_manifest',
			'disabled_image_sizes' => 'tsosk_disabled_image_sizes',
			'fi_ignored'        => 'tsosk_fi_ignored',
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
			'redirects'            => __( 'Redirects', 'tso-swiss-knife' ),
			'heartbeat'            => __( 'Heartbeat settings', 'tso-swiss-knife' ),
			'update_manager'       => __( 'Update Manager settings', 'tso-swiss-knife' ),
			'rest_api'             => __( 'REST API settings', 'tso-swiss-knife' ),
			'maintenance'          => __( 'Maintenance mode', 'tso-swiss-knife' ),
			'hidden_profiles'      => __( 'Hidden WordPress profiles', 'tso-swiss-knife' ),
			'alert_settings'       => __( 'Health email alerts', 'tso-swiss-knife' ),
			'health_suppress'      => __( 'Site Health notice suppression', 'tso-swiss-knife' ),
			'login_protect'        => __( 'Login protection', 'tso-swiss-knife' ),
			'login_lockouts'       => __( 'Login lockout log', 'tso-swiss-knife' ),
			'login_attempts'       => __( 'Login attempt counters', 'tso-swiss-knife' ),
			'slow_queries'         => __( 'Slow query monitor', 'tso-swiss-knife' ),
			'custom_404'           => __( 'Custom 404 page', 'tso-swiss-knife' ),
			'admin_menu'           => __( 'Admin menu customizer', 'tso-swiss-knife' ),
			'admin_menu_manifest'  => __( 'Admin menu manifest', 'tso-swiss-knife' ),
			'disabled_image_sizes' => __( 'Disabled image sizes', 'tso-swiss-knife' ),
			'fi_ignored'           => __( 'File integrity ignored files', 'tso-swiss-knife' ),
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
			$value  = get_option( $option, null );
			if ( null !== $value ) {
				$payload[ $section ] = $value;
			}
		}

		return array(
			'format'      => 'tsosk-site-snapshot',
			'version'     => 2,
			'plugin'      => 'tso-swiss-knife',
			'exported_at' => gmdate( 'c' ),
			'site_url'    => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'sections'    => $payload,
		);
	}

	/**
	 * Download JSON export.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife' ) );
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
				esc_html__( 'Select at least one section to export.', 'tso-swiss-knife' ),
				esc_html__( 'Export/Import TSO Configuration', 'tso-swiss-knife' ),
				array( 'response' => 400, 'back_link' => true )
			);
		}
		if ( empty( $sections ) ) {
			$sections = array_keys( self::get_export_map() );
		}

		$data = $this->build_snapshot( $sections );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Could not encode snapshot.', 'tso-swiss-knife' ) );
		}

		$exported_sections = array_keys( $data['sections'] ?? array() );
		if ( ! empty( $exported_sections ) ) {
			TSOSK_Activity_Log::log(
				'site-snapshot',
				'export',
				sprintf(
					/* translators: %s: comma-separated section labels */
					__( 'Site snapshot exported: %s.', 'tso-swiss-knife' ),
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

	public function ajax_import(): void {
		check_ajax_referer( 'tsosk_snapshot_import_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON file body.
		$raw = isset( $_POST['snapshot'] ) ? wp_unslash( $_POST['snapshot'] ) : '';
		if ( '' === $raw ) {
			wp_send_json_error( __( 'No snapshot data received.', 'tso-swiss-knife' ) );
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			wp_send_json_error( __( 'The snapshot JSON could not be parsed.', 'tso-swiss-knife' ) );
		}
		if ( ( $data['format'] ?? '' ) !== 'tsosk-site-snapshot' ) {
			wp_send_json_error( __( 'Invalid snapshot file format.', 'tso-swiss-knife' ) );
		}

		$sections = $data['sections'] ?? array();
		if ( ! is_array( $sections ) || empty( $sections ) ) {
			wp_send_json_error( __( 'Snapshot contains no sections to import.', 'tso-swiss-knife' ) );
		}

		$sections_filter = array();
		if ( isset( $_POST['import_sections'] ) && is_array( $_POST['import_sections'] ) ) {
			foreach ( wp_unslash( $_POST['import_sections'] ) as $section ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$sections_filter[] = sanitize_key( (string) $section );
			}
		}

		$map     = self::get_export_map();
		$imported = array();
		$skipped  = array();

		foreach ( $sections as $section => $value ) {
			$section = sanitize_key( (string) $section );
			if ( ! isset( $map[ $section ] ) ) {
				continue;
			}
			if ( ! empty( $sections_filter ) && ! in_array( $section, $sections_filter, true ) ) {
				continue;
			}

			$validated = $this->validate_section_value( $section, $value );
			if ( is_wp_error( $validated ) ) {
				$skipped[] = $section . ': ' . $validated->get_error_message();
				continue;
			}

			update_option( $map[ $section ], $validated, false );
			$imported[] = $section;
		}

		if ( in_array( 'login_protect', $imported, true ) ) {
			flush_rewrite_rules( false );
		}

		if ( empty( $imported ) ) {
			$message = ! empty( $skipped )
				? implode( ' ', $skipped )
				: __( 'No recognised sections were imported.', 'tso-swiss-knife' );
			wp_send_json_error( $message );
		}

		TSOSK_Activity_Log::log(
			'site-snapshot',
			'import',
			sprintf(
				/* translators: %s: comma-separated section labels */
				__( 'Site snapshot imported: %s.', 'tso-swiss-knife' ),
				$this->format_section_list( $imported )
			)
		);

		$response = sprintf(
			/* translators: %s: comma-separated section labels */
			__( 'Imported sections: %s. Reload affected tabs to verify settings.', 'tso-swiss-knife' ),
			$this->format_section_list( $imported )
		);
		if ( ! empty( $skipped ) ) {
			$response .= ' ' . sprintf(
				/* translators: %s: skipped section errors */
				__( 'Skipped invalid sections: %s', 'tso-swiss-knife' ),
				implode( '; ', $skipped )
			);
		}

		wp_send_json_success( $response );
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
			case 'login_protect':
				return $this->sanitize_login_protect_import( $value );
			case 'login_lockouts':
				return $this->sanitize_lockout_log_import( $value );
			case 'login_attempts':
				return $this->sanitize_attempts_import( $value );
			case 'fi_ignored':
			case 'disabled_image_sizes':
				return $this->sanitize_string_list_import( $value, 'fi_ignored' === $section ? 500 : 50 );
			default:
				if ( ! is_array( $value ) ) {
					return new WP_Error(
						'invalid_section',
						sprintf(
							/* translators: %s: section id */
							__( 'Section "%s" must be an object.', 'tso-swiss-knife' ),
							$section
						)
					);
				}
				return $value;
		}
	}

	/**
	 * @param mixed $value Raw redirects option.
	 * @return array|WP_Error
	 */
	private function sanitize_redirects_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_redirects', __( 'Redirects section must be an object.', 'tso-swiss-knife' ) );
		}

		$out = array();
		foreach ( $value as $id => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $rule['id'] ?? $id ) );
			if ( '' === $id ) {
				continue;
			}
			$status = absint( $rule['status'] ?? 301 );
			if ( ! in_array( $status, array( 301, 302, 307, 308, 410, 451 ), true ) ) {
				$status = 301;
			}
			$match_type = sanitize_key( (string) ( $rule['match_type'] ?? 'exact' ) );
			if ( ! in_array( $match_type, array( 'exact', 'wildcard', 'regex' ), true ) ) {
				$match_type = 'exact';
			}
			$out[ $id ] = array(
				'id'         => $id,
				'source'     => sanitize_text_field( (string) ( $rule['source'] ?? '' ) ),
				'target'     => sanitize_text_field( (string) ( $rule['target'] ?? '' ) ),
				'match_type' => $match_type,
				'status'     => $status,
				'enabled'    => ! empty( $rule['enabled'] ),
				'hits'       => absint( $rule['hits'] ?? 0 ),
				'last_hit'   => absint( $rule['last_hit'] ?? 0 ),
				'created'    => absint( $rule['created'] ?? time() ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $value Raw login protect settings.
	 * @return array|WP_Error
	 */
	private function sanitize_login_protect_import( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_login_protect', __( 'Login protection section must be an object.', 'tso-swiss-knife' ) );
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
			'custom_url'                => ! empty( $value['custom_url'] ),
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
			return new WP_Error( 'invalid_lockouts', __( 'Login lockout log must be an array.', 'tso-swiss-knife' ) );
		}
		if ( count( $value ) > 200 ) {
			return new WP_Error( 'invalid_lockouts', __( 'Login lockout log exceeds the maximum allowed entries.', 'tso-swiss-knife' ) );
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
			return new WP_Error( 'invalid_attempts', __( 'Login attempt counters must be an object.', 'tso-swiss-knife' ) );
		}
		if ( count( $value ) > 500 ) {
			return new WP_Error( 'invalid_attempts', __( 'Login attempt counters exceed the maximum allowed entries.', 'tso-swiss-knife' ) );
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
	 * @param mixed $value   Raw list.
	 * @param int   $max     Max items.
	 * @return string[]|WP_Error
	 */
	private function sanitize_string_list_import( $value, int $max ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'invalid_list', __( 'Expected a list of strings.', 'tso-swiss-knife' ) );
		}
		if ( count( $value ) > $max ) {
			return new WP_Error(
				'invalid_list',
				sprintf(
					/* translators: %d: maximum items */
					__( 'List exceeds the maximum of %d items.', 'tso-swiss-knife' ),
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

	public function render(): void {
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=tsosk_snapshot_export' ),
			'tsosk_snapshot_export'
		);
		$import_nonce = wp_create_nonce( 'tsosk_snapshot_import_nonce' );
		$labels       = self::get_section_labels();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Save and restore site snapshots of selected TSO Swiss Knife settings as JSON for staging, backups, or migrations. Only plugin-owned settings are included — never wp-config or license keys.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-warn">
			<?php esc_html_e( 'Import overwrites existing settings for the selected sections. Always export a backup first and test on staging.', 'tso-swiss-knife' ); ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Export snapshot', 'tso-swiss-knife' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" id="tsosk-snapshot-export-form">
				<?php wp_nonce_field( 'tsosk_snapshot_export' ); ?>
				<input type="hidden" name="tsosk_snapshot_export" value="1">
				<p class="description"><?php esc_html_e( 'Select sections to include in the JSON file:', 'tso-swiss-knife' ); ?></p>
				<ul style="list-style:none;margin:12px 0;padding:0;">
					<?php foreach ( $labels as $id => $label ) : ?>
					<li style="margin-bottom:6px;">
						<label>
							<input type="checkbox" name="sections[]" value="<?php echo esc_attr( $id ); ?>" checked>
							<?php echo esc_html( $label ); ?>
						</label>
					</li>
					<?php endforeach; ?>
				</ul>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Download JSON snapshot', 'tso-swiss-knife' ); ?></button>
			</form>
		</div>

		<div class="tsosk-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Import snapshot', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Paste JSON from a previous export or upload a .json file. You can choose which sections to restore.', 'tso-swiss-knife' ); ?></p>
			<p>
				<input type="file" id="tsosk-snapshot-file" accept=".json,application/json">
			</p>
			<textarea id="tsosk-snapshot-json" rows="10" style="width:100%;font-family:monospace;"
			          placeholder="<?php esc_attr_e( '{ "format": "tsosk-site-snapshot", ... }', 'tso-swiss-knife' ); ?>"></textarea>
			<div id="tsosk-snapshot-import-sections" class="tsosk-card" style="margin-top:12px;display:none;padding:12px;">
				<p class="description" style="margin-top:0;">
					<strong><?php esc_html_e( 'Sections in this file', 'tso-swiss-knife' ); ?>:</strong>
					<?php esc_html_e( 'Uncheck any section you do not want to overwrite.', 'tso-swiss-knife' ); ?>
				</p>
				<ul id="tsosk-snapshot-import-list" style="list-style:none;margin:8px 0;padding:0;"></ul>
			</div>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-snapshot-import"
				        data-nonce="<?php echo esc_attr( $import_nonce ); ?>">
					<?php esc_html_e( 'Import snapshot', 'tso-swiss-knife' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-snapshot-msg"></span>
			</p>
		</div>
		<script type="application/json" id="tsosk-snapshot-labels"><?php echo wp_json_encode( $labels ); ?></script>
		<?php
	}
}
