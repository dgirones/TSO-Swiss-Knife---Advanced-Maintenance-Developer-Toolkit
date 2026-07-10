<?php
/**
 * TSO Swiss Knife – Module: WP Constants (read-only viewer).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Constants {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function render(): void {
		$system_info = $this->get_system_info();
		$environment_overrides = $this->get_environment_overrides();

		// Groups of WP constants to display with descriptions.
		$groups = array(
			__( 'Paths & URLs', 'tso-swiss-knife' ) => array(
				'ABSPATH', 'WPINC', 'WP_CONTENT_DIR', 'WP_CONTENT_URL',
				'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL',
				'TEMPLATEPATH', 'STYLESHEETPATH',
			),
			__( 'Database', 'tso-swiss-knife' ) => array(
				'DB_NAME', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE',
			),
			__( 'Debug', 'tso-swiss-knife' ) => array(
				'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES',
			),
			__( 'Performance', 'tso-swiss-knife' ) => array(
				'WP_CACHE', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS', 'COMPRESS_CSS',
				'ENFORCE_GZIP',
			),
			__( 'Security', 'tso-swiss-knife' ) => array(
				'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN',
				'COOKIEPATH', 'SITECOOKIEPATH',
			),
			__( 'Memory', 'tso-swiss-knife' ) => array(
				'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT',
			),
			__( 'Autosave & Revisions', 'tso-swiss-knife' ) => array(
				'AUTOSAVE_INTERVAL', 'WP_POST_REVISIONS', 'EMPTY_TRASH_DAYS',
			),
			__( 'Multisite', 'tso-swiss-knife' ) => array(
				'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'PATH_CURRENT_SITE',
				'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE',
			),
			__( 'Cron', 'tso-swiss-knife' ) => array(
				'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT',
			),
		);
		$profiles_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=hidden-profiles' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Read-only view of the most relevant WordPress constants. Sensitive values (DB_USER, DB_PASSWORD, auth keys) are intentionally omitted.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-info">
			<?php
			printf(
				/* translators: 1: link open, 2: link close */
				esc_html__( 'To toggle common performance and privacy constants safely, use %1$sHidden WordPress Profiles%2$s.', 'tso-swiss-knife' ),
				'<a href="' . esc_url( $profiles_url ) . '">',
				'</a>'
			);
			?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Useful System Information', 'tso-swiss-knife' ); ?></h3>
			<table class="tsosk-kv-table tsosk-constants-table">
				<?php foreach ( $system_info as $label => $value ) : ?>
					<tr class="tsosk-constant-row">
						<th><?php echo esc_html( $label ); ?></th>
						<td><code><?php echo esc_html( $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Environment Overrides', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Hidden constants that change WordPress behavior for updates, file editing, trash, revisions, autosaves, SSL and environments.', 'tso-swiss-knife' ); ?></p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Constant', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Value', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Impact', 'tso-swiss-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $environment_overrides as $item ) : ?>
							<tr>
								<td><code><?php echo esc_html( $item['constant'] ); ?></code></td>
								<td>
									<code><?php echo esc_html( $item['value'] ); ?></code>
									<?php if ( ! empty( $item['source'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $item['source'] ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $item['impact'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<input type="text" id="tsosk-constants-search" placeholder="<?php esc_attr_e( 'Filter constants…', 'tso-swiss-knife' ); ?>"
		       style="width:260px;margin-bottom:16px;">

		<?php foreach ( $groups as $group_label => $constants ) :
			$has_any = false;
			foreach ( $constants as $const ) {
				if ( defined( $const ) ) { $has_any = true; break; }
			}
			if ( ! $has_any ) continue;
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $group_label ); ?></h3>
			<table class="tsosk-kv-table tsosk-constants-table">
				<?php foreach ( $constants as $const ) : ?>
					<?php if ( ! defined( $const ) ) continue; ?>
					<?php
					$val = constant( $const );
					if ( is_bool( $val ) ) {
						$display = $val ? 'true' : 'false';
					} elseif ( is_null( $val ) ) {
						$display = 'null';
					} elseif ( is_int( $val ) || is_float( $val ) ) {
						$display = (string) $val;
					} elseif ( is_string( $val ) ) {
						$display = $val;
					} else {
						$display = wp_json_encode( $val );
					}
					?>
					<tr class="tsosk-constant-row">
						<th><code><?php echo esc_html( $const ); ?></code></th>
						<td><code><?php echo esc_html( $display ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Collect useful non-secret environment data.
	 *
	 * @return array<string, string>
	 */
	private function get_system_info(): array {
		global $wpdb;

		$theme = wp_get_theme();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$sitewide_plugins = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: __( 'Unknown', 'tso-swiss-knife' );

		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) )
			: __( 'Unknown', 'tso-swiss-knife' );

		return array(
			__( 'WordPress Version', 'tso-swiss-knife' )        => get_bloginfo( 'version' ),
			__( 'WordPress Locale', 'tso-swiss-knife' )         => determine_locale(),
			__( 'Site URL', 'tso-swiss-knife' )                 => site_url(),
			__( 'Home URL', 'tso-swiss-knife' )                 => home_url(),
			__( 'Multisite', 'tso-swiss-knife' )                => is_multisite() ? __( 'Yes', 'tso-swiss-knife' ) : __( 'No', 'tso-swiss-knife' ),
			__( 'Active Theme', 'tso-swiss-knife' )             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			__( 'Active Plugins', 'tso-swiss-knife' )           => (string) count( $active_plugins ),
			__( 'Network Active Plugins', 'tso-swiss-knife' )   => (string) count( $sitewide_plugins ),
			__( 'PHP Version', 'tso-swiss-knife' )              => PHP_VERSION,
			__( 'PHP SAPI', 'tso-swiss-knife' )                 => PHP_SAPI,
			__( 'PHP Memory Limit', 'tso-swiss-knife' )         => (string) ini_get( 'memory_limit' ),
			__( 'PHP Max Execution Time', 'tso-swiss-knife' )   => (string) ini_get( 'max_execution_time' ) . 's',
			__( 'PHP Upload Max Filesize', 'tso-swiss-knife' )  => (string) ini_get( 'upload_max_filesize' ),
			__( 'PHP Post Max Size', 'tso-swiss-knife' )        => (string) ini_get( 'post_max_size' ),
			__( 'PHP Max Input Vars', 'tso-swiss-knife' )       => (string) ini_get( 'max_input_vars' ),
			__( 'Database Version', 'tso-swiss-knife' )         => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : __( 'Unknown', 'tso-swiss-knife' ),
			__( 'Database Charset', 'tso-swiss-knife' )         => defined( 'DB_CHARSET' ) ? DB_CHARSET : __( 'Unknown', 'tso-swiss-knife' ),
			__( 'Table Prefix', 'tso-swiss-knife' )             => $wpdb->prefix,
			__( 'Server Software', 'tso-swiss-knife' )          => $server_software,
			__( 'Operating System', 'tso-swiss-knife' )         => PHP_OS_FAMILY,
			__( 'Document Root', 'tso-swiss-knife' )            => $document_root,
			__( 'ABSPATH', 'tso-swiss-knife' )                  => ABSPATH,
			__( 'WP Content Directory', 'tso-swiss-knife' )     => WP_CONTENT_DIR,
			__( 'Timezone', 'tso-swiss-knife' )                 => wp_timezone_string(),
			__( 'Current Admin Time', 'tso-swiss-knife' )       => date_i18n( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Return important environment override constants and their impact.
	 *
	 * @return array<int,array{constant:string,value:string,impact:string}>
	 */
	private function get_environment_overrides(): array {
		$constants = array(
			'WP_ENVIRONMENT_TYPE'        => __( 'Defines whether the site runs as production, staging, development or local.', 'tso-swiss-knife' ),
			'WP_MEMORY_LIMIT'            => __( 'Controls normal WordPress memory limit.', 'tso-swiss-knife' ),
			'WP_MAX_MEMORY_LIMIT'        => __( 'Controls elevated admin/image processing memory limit.', 'tso-swiss-knife' ),
			'AUTOMATIC_UPDATER_DISABLED' => __( 'Disables automatic background updates when true.', 'tso-swiss-knife' ),
			'DISALLOW_FILE_MODS'         => __( 'Disables plugin/theme installation, updates and file modifications.', 'tso-swiss-knife' ),
			'DISALLOW_FILE_EDIT'         => __( 'Disables the built-in plugin and theme editors.', 'tso-swiss-knife' ),
			'WP_AUTO_UPDATE_CORE'        => __( 'Controls automatic WordPress core updates.', 'tso-swiss-knife' ),
			'EMPTY_TRASH_DAYS'           => __( 'Controls how long trashed content is kept.', 'tso-swiss-knife' ),
			'WP_POST_REVISIONS'          => __( 'Controls how many post revisions WordPress keeps.', 'tso-swiss-knife' ),
			'AUTOSAVE_INTERVAL'          => __( 'Controls editor autosave frequency in seconds.', 'tso-swiss-knife' ),
			'FORCE_SSL_ADMIN'            => __( 'Forces SSL for admin screens and logins.', 'tso-swiss-knife' ),
			'ALTERNATE_WP_CRON'          => __( 'Changes how WordPress spawns cron requests.', 'tso-swiss-knife' ),
			'DISABLE_WP_CRON'            => __( 'Disables traffic-based WP-Cron and expects a real server cron.', 'tso-swiss-knife' ),
			'WP_DEBUG'                   => __( 'Enables WordPress debug mode.', 'tso-swiss-knife' ),
			'WP_DEBUG_LOG'               => __( 'Writes debug messages to debug.log.', 'tso-swiss-knife' ),
			'WP_DEBUG_DISPLAY'           => __( 'Shows debug messages on screen.', 'tso-swiss-knife' ),
		);

		$out = array();
		foreach ( $constants as $constant => $impact ) {
			$resolved = $this->resolve_environment_constant( $constant );
			$out[]    = array(
				'constant' => $constant,
				'value'    => $resolved['value'],
				'source'   => $resolved['source'],
				'impact'   => $impact,
			);
		}

		return $out;
	}

	/**
	 * Resolve display value and source for an environment constant.
	 *
	 * @param string $constant Constant name.
	 * @return array{value:string,source:string}
	 */
	private function resolve_environment_constant( string $constant ): array {
		if ( defined( $constant ) ) {
			return array(
				'value'  => $this->format_constant_value( constant( $constant ) ),
				'source' => $this->detect_constant_source( $constant ),
			);
		}

		$wp_config = $this->read_constant_from_wp_config( $constant );
		if ( null !== $wp_config ) {
			return array(
				'value'  => $wp_config,
				'source' => __( 'Set in wp-config.php', 'tso-swiss-knife' ),
			);
		}

		$tsosk = $this->read_constant_from_tsosk_flags( $constant );
		if ( null !== $tsosk ) {
			return array(
				'value'  => $tsosk,
				'source' => __( 'Set via TSO Swiss Knife flags file', 'tso-swiss-knife' ),
			);
		}

		switch ( $constant ) {
			case 'WP_ENVIRONMENT_TYPE':
				return array(
					'value'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
					'source' => __( 'WordPress runtime default', 'tso-swiss-knife' ),
				);
			case 'FORCE_SSL_ADMIN':
				return array(
					'value'  => ( function_exists( 'force_ssl_admin' ) && force_ssl_admin() ) ? 'true' : 'false',
					'source' => __( 'Effective value (filter or core)', 'tso-swiss-knife' ),
				);
			case 'WP_MEMORY_LIMIT':
				return array(
					'value'  => ini_get( 'memory_limit' ) ?: '40M',
					'source' => __( 'PHP ini / WordPress fallback', 'tso-swiss-knife' ),
				);
			case 'WP_MAX_MEMORY_LIMIT':
				return array(
					'value'  => '512M',
					'source' => __( 'WordPress default when undefined', 'tso-swiss-knife' ),
				);
			case 'EMPTY_TRASH_DAYS':
				return array(
					'value'  => '30',
					'source' => __( 'WordPress default when undefined', 'tso-swiss-knife' ),
				);
			case 'WP_POST_REVISIONS':
				return array(
					'value'  => 'true',
					'source' => __( 'WordPress default when undefined', 'tso-swiss-knife' ),
				);
			case 'AUTOSAVE_INTERVAL':
				return array(
					'value'  => '60',
					'source' => __( 'WordPress default when undefined', 'tso-swiss-knife' ),
				);
		}

		return array(
			'value'  => __( 'Not defined', 'tso-swiss-knife' ),
			'source' => '',
		);
	}

	/**
	 * @param mixed $value Constant value.
	 * @return string
	 */
	private function format_constant_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}
		return (string) wp_json_encode( $value );
	}

	/**
	 * Detect where a defined constant was set from.
	 *
	 * @param string $constant Constant name.
	 * @return string
	 */
	private function detect_constant_source( string $constant ): string {
		$wp_config = $this->find_wp_config_path();
		if ( $wp_config && is_readable( $wp_config ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = (string) file_get_contents( $wp_config );
			if ( preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]/i', $src ) ) {
				return __( 'Defined in wp-config.php', 'tso-swiss-knife' );
			}
		}
		foreach ( array( 'tsosk-debug-flags.php', 'tsosk-security-flags.php', 'tsosk-profiles-flags.php' ) as $file ) {
			$path = TSOSK_CONFIG_DIR . '/' . $file;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = (string) file_get_contents( $path );
			if ( preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]/i', $src ) ) {
				return __( 'Defined in TSO flags file', 'tso-swiss-knife' );
			}
		}
		return __( 'Defined at runtime', 'tso-swiss-knife' );
	}

	/**
	 * @return string Absolute wp-config.php path or empty.
	 */
	private function find_wp_config_path(): string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH );
		if ( file_exists( $parent . '/wp-config.php' ) ) {
			return $parent . '/wp-config.php';
		}
		return '';
	}

	/**
	 * Parse wp-config.php for a constant value even if PHP has not defined it yet.
	 *
	 * @param string $constant Constant name.
	 * @return string|null
	 */
	private function read_constant_from_wp_config( string $constant ): ?string {
		$path = $this->find_wp_config_path();
		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $path );
		if ( preg_match(
			'/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*([^)]+)\)/i',
			$src,
			$m
		) ) {
			return trim( $m[1], " \t\n\r\0\x0B'\"" );
		}
		return null;
	}

	/**
	 * Read constant from TSO uploads flag files.
	 *
	 * @param string $constant Constant name.
	 * @return string|null
	 */
	private function read_constant_from_tsosk_flags( string $constant ): ?string {
		foreach ( array( 'tsosk-debug-flags.php', 'tsosk-security-flags.php', 'tsosk-profiles-flags.php' ) as $file ) {
			$path = TSOSK_CONFIG_DIR . '/' . $file;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = (string) file_get_contents( $path );
			if ( preg_match(
				'/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*([^)]+)\)/i',
				$src,
				$m
			) ) {
				return trim( $m[1], " \t\n\r\0\x0B'\"" );
			}
			if ( 'FORCE_SSL_ADMIN' === $constant && preg_match( "/add_filter\s*\(\s*'force_ssl_admin'/", $src ) ) {
				return 'false';
			}
		}
		return null;
	}
}
