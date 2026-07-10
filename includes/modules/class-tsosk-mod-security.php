<?php
/**
 * TSO Swiss Knife – Module: Security Review.
 *
 * Read-only checks plus MU-plugin toggles for safe wp-config constants.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Security
 */
class TSOSK_Mod_Security {

	/** MU-plugin file managed by this module. */
	private const MU_FILE = 'tsosk-security-flags.php';

	/** @var TSOSK_Mod_Security|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_security_save', array( $this, 'ajax_save' ) );
	}

	/** AJAX: save toggleable constants. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$flags   = array();
		$allowed = array( 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN' );
		foreach ( $allowed as $c ) {
			// JS sends the key as 'tsosk_security_' . lowercase_constant_name.
			$post_key     = 'tsosk_security_' . strtolower( $c );
			$flags[ $c ]  = ! empty( $_POST[ $post_key ] );
		}

		$result = $this->write_mu_plugin( $flags );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$profiles = get_option( 'tsosk_hidden_profiles', array() );
		if ( ! is_array( $profiles ) ) {
			$profiles = array();
		}
		$profiles['disable_xmlrpc'] = ! empty( $_POST['tsosk_security_disable_xmlrpc'] );
		$profiles['disable_feeds']  = ! empty( $_POST['tsosk_security_disable_feeds'] );
		update_option( 'tsosk_hidden_profiles', $profiles, false );

		TSOSK_Activity_Log::log( 'security', 'save', __( 'Security settings saved.', 'tso-swiss-knife' ) );

		wp_send_json_success( __( 'Security settings saved. Reload the page to see the new values.', 'tso-swiss-knife' ) );
	}

	/**
	 * Remote-access flags stored in tsosk_hidden_profiles.
	 *
	 * @return array{disable_xmlrpc:bool,disable_feeds:bool}
	 */
	private function get_remote_access_flags(): array {
		$stored = get_option( 'tsosk_hidden_profiles', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array(
			'disable_xmlrpc' => ! empty( $stored['disable_xmlrpc'] ),
			'disable_feeds'  => ! empty( $stored['disable_feeds'] ),
		);
	}

	/**
	 * Admin URL for a related tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg( 'tab', $tab, admin_url( 'tools.php?page=tso-swiss-knife' ) );
	}

	/**
	 * Write or remove the MU-plugin.
	 *
	 * @param array $flags Constant => bool.
	 * @return true|WP_Error
	 */
	private function write_mu_plugin( array $flags ) {
		$dir  = TSOSK_CONFIG_DIR;
		$path = trailingslashit( $dir ) . self::MU_FILE;

		$needs_ssl_override = empty( $flags['FORCE_SSL_ADMIN'] )
			&& $this->is_defined_in_wp_config( 'FORCE_SSL_ADMIN' )
			&& defined( 'FORCE_SSL_ADMIN' )
			&& FORCE_SSL_ADMIN;

		$any_on = in_array( true, array_values( $flags ), true ) || $needs_ssl_override;

		if ( ! $any_on ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			$legacy = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
			if ( file_exists( $legacy ) ) {
				wp_delete_file( $legacy );
			}
			return true;
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$this->protect_config_dir( $dir );
		}
		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'not_writable', __( 'The config directory is not writable.', 'tso-swiss-knife' ) );
		}
		$legacy = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
		if ( file_exists( $legacy ) ) {
			wp_delete_file( $legacy );
		}

		$content  = "<?php\n";
		$content .= "/**\n * TSO Swiss Knife – Security Flags (auto-generated).\n * tsosk\n */\n";
		foreach ( $flags as $constant => $enabled ) {
			if ( $enabled ) {
				$content .= "if ( ! defined( '" . $constant . "' ) ) { define( '" . $constant . "', true ); }\n";
			}
		}
		if ( $needs_ssl_override ) {
			$content .= "add_filter( 'force_ssl_admin', '__return_false', 99 );\n";
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'write_failed', __( 'Could not write the MU-plugin file.', 'tso-swiss-knife' ) );
		}
		return true;
	}

	/**
	 * Read current values of the managed constants from the MU-plugin.
	 *
	 * @return array<string,bool>
	 */
	private function get_mu_settings(): array {
		$path     = trailingslashit( TSOSK_CONFIG_DIR ) . self::MU_FILE;
		$defaults = array( 'DISALLOW_FILE_EDIT' => false, 'DISALLOW_FILE_MODS' => false, 'FORCE_SSL_ADMIN' => false );
		if ( ! file_exists( $path ) ) {
			return $defaults;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $path );
		$out = array();
		foreach ( $defaults as $c => $d ) {
			if ( 'FORCE_SSL_ADMIN' === $c && preg_match( "/add_filter\s*\(\s*'force_ssl_admin'/", $src ) ) {
				$out[ $c ] = false;
			} else {
				$out[ $c ] = (bool) preg_match( "/define\(\s*'" . preg_quote( $c, '/' ) . "'\s*,\s*true\s*\)/", $src );
			}
		}
		return $out;
	}

	/**
	 * Whether FORCE_SSL_ADMIN is turned off via TSO filter override.
	 *
	 * @return bool
	 */
	private function is_force_ssl_overridden_off(): bool {
		$path = trailingslashit( TSOSK_CONFIG_DIR ) . self::MU_FILE;
		if ( ! file_exists( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $path );
		return (bool) preg_match( "/add_filter\s*\(\s*'force_ssl_admin'/", $src );
	}

	/**
	 * Whether a constant is defined directly in wp-config.php (not via TSO config).
	 *
	 * @param string $constant Constant name.
	 * @return bool
	 */
	private function is_defined_in_wp_config( string $constant ): bool {
		if ( ! defined( $constant ) ) {
			return false;
		}
		$wp_config = $this->find_wp_config();
		if ( ! $wp_config || ! is_readable( $wp_config ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $wp_config );
		return (bool) preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]/i', $src );
	}

	/**
	 * Effective boolean value of a managed constant.
	 *
	 * @param string $constant Constant name.
	 * @return bool
	 */
	private function constant_is_enabled( string $constant ): bool {
		return defined( $constant ) && (bool) constant( $constant );
	}

	public function render(): void {
		$nonce     = wp_create_nonce( 'tsosk_security_nonce' );
		$checks    = $this->get_checks();
		$mu        = $this->get_mu_settings();
		$remote    = $this->get_remote_access_flags();
		$mu_exists     = file_exists( trailingslashit( TSOSK_CONFIG_DIR ) . self::MU_FILE );
		$legacy_exists = file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE );

		$toggles = array(
			'DISALLOW_FILE_EDIT' => array(
				'label' => 'DISALLOW_FILE_EDIT',
				'desc'  => __( 'Hides the built-in plugin and theme code editors from the WordPress admin (Appearance › Editor, Plugins › Editor). Strongly recommended on production sites to prevent accidental or malicious code injection via the dashboard.', 'tso-swiss-knife' ),
				'rec'   => __( 'Recommended: ON on all production sites.', 'tso-swiss-knife' ),
				'key'   => 'disallow_file_edit',
			),
			'DISALLOW_FILE_MODS' => array(
				'label' => 'DISALLOW_FILE_MODS',
				'desc'  => __( 'Prevents any plugin or theme from being installed, updated or deleted from the WordPress admin. Also disables the WordPress auto-updater. Use on hardened servers where all deploys are done via version control. Note: this also blocks WordPress core auto-updates.', 'tso-swiss-knife' ),
				'rec'   => __( 'Optional. Only use if you manage updates via CI/CD or WP-CLI. Do not use if you rely on the admin for updates.', 'tso-swiss-knife' ),
				'key'   => 'disallow_file_mods',
			),
			'FORCE_SSL_ADMIN'    => array(
				'label' => 'FORCE_SSL_ADMIN',
				'desc'  => __( 'Forces the WordPress login page and admin area to always use HTTPS, even if the front end runs on HTTP. Only enable this if you have a valid SSL certificate. If you do not have SSL, this will lock you out of the admin.', 'tso-swiss-knife' ),
				'rec'   => __( 'Recommended: ON if you have SSL. Never enable without a working SSL certificate.', 'tso-swiss-knife' ),
				'key'   => 'force_ssl_admin',
			),
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Read-only security review plus safe toggles for WordPress hardening constants. Toggles write a config file to wp-content/uploads/tsosk-config/ that is loaded at plugin init time. Constants already defined in wp-config.php take precedence.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Remote access', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Reduce common attack vectors from anonymous visitors and remote clients.', 'tso-swiss-knife' ); ?></p>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_security_disable_xmlrpc" id="tsosk-sec-disable-xmlrpc" value="1" <?php checked( $remote['disable_xmlrpc'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Disable XML-RPC', 'tso-swiss-knife' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Blocks pingbacks and remote publishing via xmlrpc.php.', 'tso-swiss-knife' ); ?></span>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_security_disable_feeds" id="tsosk-sec-disable-feeds" value="1" <?php checked( $remote['disable_feeds'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Disable RSS feeds', 'tso-swiss-knife' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Redirects feed URLs; reduces discovery surface.', 'tso-swiss-knife' ); ?></span>
				</span>
			</label>
			<p class="description" style="margin-top:12px;">
				<?php esc_html_e( 'REST API access and namespace controls are configured in the REST API tab.', 'tso-swiss-knife' ); ?>
				<a href="<?php echo esc_url( $this->tab_url( 'rest-api' ) ); ?>"><?php esc_html_e( 'Open REST API settings →', 'tso-swiss-knife' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $this->tab_url( 'login-protect' ) ); ?>"><?php esc_html_e( 'Login protection →', 'tso-swiss-knife' ); ?></a>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Toggleable Security Constants', 'tso-swiss-knife' ); ?></h3>
			<?php if ( ! empty( $legacy_exists ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php
				/* translators: %s: legacy file path */
				printf( esc_html__( 'Legacy file found in mu-plugins: %s — save settings once to migrate it.', 'tso-swiss-knife' ), '<code>' . esc_html( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE ) . '</code>' );
				?>
			</div>
		<?php endif; ?>
		<?php if ( $mu_exists ) : ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php
				printf(
					/* translators: %s: file path */
					esc_html__( 'Active config file: %s', 'tso-swiss-knife' ),
					'<code>' . esc_html( trailingslashit( TSOSK_CONFIG_DIR ) . self::MU_FILE ) . '</code>'
				);
				?>
			</div>
			<?php endif; ?>
			<div class="tsosk-security-toggles">
				<?php foreach ( $toggles as $const => $toggle ) : ?>
				<?php
				$wp_config_locked = $this->is_defined_in_wp_config( $const );
				$is_on            = $this->constant_is_enabled( $const );
				if ( 'FORCE_SSL_ADMIN' === $const && $this->is_force_ssl_overridden_off() ) {
					$is_on = false;
				}
				$mu_on            = ! empty( $mu[ $const ] );
				$checkbox_locked  = $wp_config_locked && 'FORCE_SSL_ADMIN' !== $const;
				$checkbox_checked = $is_on;
				?>
				<div class="tsosk-security-toggle-row">
					<label class="tsosk-heartbeat-option">
						<div class="tsosk-heartbeat-option-header">
							<input type="checkbox"
							       name="tsosk_security_<?php echo esc_attr( $toggle['key'] ); ?>"
							       id="tsosk-sec-<?php echo esc_attr( $toggle['key'] ); ?>"
							       data-const="<?php echo esc_attr( $toggle['key'] ); ?>"
							       <?php checked( $checkbox_checked ); ?>
							       <?php disabled( $checkbox_locked ); ?>>
							<code><strong><?php echo esc_html( $toggle['label'] ); ?></strong></code>
							<?php if ( $is_on ) : ?>
								<span class="tsosk-badge tsosk-badge-ok" style="margin-left:6px;">
									<?php esc_html_e( 'Currently ON', 'tso-swiss-knife' ); ?>
								</span>
							<?php else : ?>
								<span class="tsosk-badge" style="margin-left:6px;">
									<?php esc_html_e( 'Currently OFF', 'tso-swiss-knife' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $wp_config_locked ) : ?>
								<span class="tsosk-badge tsosk-badge-info" style="margin-left:4px;" title="<?php esc_attr_e( 'Defined in wp-config.php — edit that file to change or remove it.', 'tso-swiss-knife' ); ?>">
									<?php esc_html_e( 'wp-config.php', 'tso-swiss-knife' ); ?>
								</span>
							<?php elseif ( $mu_on ) : ?>
								<span class="tsosk-badge tsosk-badge-info" style="margin-left:4px;">
									<?php esc_html_e( 'TSO config', 'tso-swiss-knife' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<p class="description tsosk-security-toggle-desc"><?php echo esc_html( $toggle['desc'] ); ?></p>
						<p class="description tsosk-security-toggle-rec"><?php echo esc_html( $toggle['rec'] ); ?></p>
						<?php if ( $wp_config_locked && 'FORCE_SSL_ADMIN' === $const ) : ?>
						<p class="description tsosk-security-toggle-wpconfig">
							<?php esc_html_e( 'Defined in wp-config.php. Uncheck here to disable SSL admin via a TSO override filter (saved on this panel). To remove the wp-config line entirely, edit that file.', 'tso-swiss-knife' ); ?>
						</p>
						<?php elseif ( $wp_config_locked ) : ?>
						<p class="description tsosk-security-toggle-wpconfig">
							<?php esc_html_e( 'This constant is set in wp-config.php. Remove or change that line there to manage it from this panel.', 'tso-swiss-knife' ); ?>
						</p>
						<?php endif; ?>
					</label>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<p style="margin-top:12px;">
			<button class="button button-primary" id="tsosk-security-save"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>"
			        data-save-label="<?php echo esc_attr__( 'Save Security Settings', 'tso-swiss-knife' ); ?>">
				<?php esc_html_e( 'Save Security Settings', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-security-msg"></span>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Security Checks', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Read-only overview of common security settings. This does not replace a firewall or security scanner.', 'tso-swiss-knife' ); ?></p>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Check', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Details', 'tso-swiss-knife' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td><span class="tsosk-badge <?php echo esc_attr( $check['badge'] ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td>
						<td><?php echo esc_html( $check['details'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Build security checks. */
	private function get_checks(): array {
		$admin_user     = get_user_by( 'login', 'admin' );
		$wp_config      = $this->find_wp_config();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- checking WP core filter
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true );
		$plugin_updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$theme_updates  = function_exists( 'get_theme_updates' )  ? get_theme_updates()  : array();

		return array(
			$this->check_item( __( 'File editor disabled', 'tso-swiss-knife' ),     defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,   __( 'DISALLOW_FILE_EDIT is enabled.', 'tso-swiss-knife' ),                                                               __( 'Enable DISALLOW_FILE_EDIT to hide plugin/theme editors.', 'tso-swiss-knife' ) ),
			$this->check_item( __( 'Force SSL admin', 'tso-swiss-knife' ),          is_ssl() || ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ), __( 'Admin is using SSL.', 'tso-swiss-knife' ),                                                               __( 'Admin may not be forced through SSL.', 'tso-swiss-knife' ) ),
			$this->check_item( __( 'XML-RPC', 'tso-swiss-knife' ),                  ! $xmlrpc_enabled,                                           __( 'XML-RPC is disabled by filters.', 'tso-swiss-knife' ),                                                      __( 'XML-RPC appears enabled. It can be a brute-force vector if not needed.', 'tso-swiss-knife' ), 'warn' ),
			$this->check_item( __( 'Default admin username', 'tso-swiss-knife' ),   ! $admin_user,                                              __( 'No user named admin found.', 'tso-swiss-knife' ),                                                               __( 'A user named admin exists. Rename it to a non-obvious username.', 'tso-swiss-knife' ) ),
			$this->check_item( __( 'Plugin updates', 'tso-swiss-knife' ),           empty( $plugin_updates ),                                   __( 'No plugin updates detected.', 'tso-swiss-knife' ),                                                              sprintf( /* translators: %d: number of plugin updates */ __( '%d plugin updates detected.', 'tso-swiss-knife' ), count( $plugin_updates ) ) ),
			$this->check_item( __( 'Theme updates', 'tso-swiss-knife' ),            empty( $theme_updates ),                                    __( 'No theme updates detected.', 'tso-swiss-knife' ),                                                               sprintf( /* translators: %d: number of theme updates */ __( '%d theme updates detected.', 'tso-swiss-knife' ), count( $theme_updates ) ) ),
			$this->file_permission_check( __( '.htaccess permissions', 'tso-swiss-knife' ), ABSPATH . '.htaccess' ),
			$this->file_permission_check( __( 'wp-config.php permissions', 'tso-swiss-knife' ), $wp_config ?: ABSPATH . 'wp-config.php' ),
		);
	}

	private function check_item( string $label, bool $ok, string $ok_details, string $warn_details, string $warn_badge = 'warn' ): array {
		return array( 'label' => $label, 'status' => $ok ? __( 'OK', 'tso-swiss-knife' ) : __( 'Review', 'tso-swiss-knife' ), 'badge' => $ok ? 'tsosk-badge-ok' : 'tsosk-badge-' . $warn_badge, 'details' => $ok ? $ok_details : $warn_details );
	}

	private function file_permission_check( string $label, string $path ): array {
		if ( ! $path || ! file_exists( $path ) ) {
			return array( 'label' => $label, 'status' => __( 'Info', 'tso-swiss-knife' ), 'badge' => 'tsosk-badge-info', 'details' => __( 'File not found.', 'tso-swiss-knife' ) );
		}
		$perms       = substr( sprintf( '%o', fileperms( $path ) ), -4 );
		$is_writable = wp_is_writable( $path );
		return array( 'label' => $label, 'status' => $is_writable ? __( 'Review', 'tso-swiss-knife' ) : __( 'OK', 'tso-swiss-knife' ), 'badge' => $is_writable ? 'tsosk-badge-warn' : 'tsosk-badge-ok', 'details' => sprintf( /* translators: %s: file permission octal */ __( 'Permissions: %s.', 'tso-swiss-knife' ), $perms ) );
	}

	private function find_wp_config(): string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( untrailingslashit( ABSPATH ) ) . '/wp-config.php';
		return file_exists( $parent ) ? $parent : '';
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
}
