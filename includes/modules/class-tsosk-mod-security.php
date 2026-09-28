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

	/** JSON config filename (stored under uploads/{plugin-slug}/config). */
	private const CONFIG_FILE = 'tsosk-security-flags.json';

	/** @deprecated Legacy PHP filename — migrated to JSON on read. */
	private const MU_FILE = 'tsosk-security-flags.php';

	/** Own option for the new-admin/role-escalation alert settings (never shared with tsosk_alert_settings — Health's save handler rewrites that option wholesale and would silently wipe unrelated keys). */
	private const ALERTS_OPTION = 'tsosk_security_alerts';

	/** Option storing the last "PHP execution in uploads" test result. */
	private const UPLOADS_TEST_OPTION = 'tsosk_security_uploads_php_test';

	/** Marker wrapping our rule block inside wp-content/uploads/.htaccess. */
	private const UPLOADS_HTACCESS_MARKER = 'TSO Swiss Knife — block PHP execution in uploads';

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
		add_action( 'wp_ajax_tsosk_security_save_alerts', array( $this, 'ajax_save_alerts' ) );
		add_action( 'wp_ajax_tsosk_security_revoke_app_password', array( $this, 'ajax_revoke_app_password' ) );
		add_action( 'wp_ajax_tsosk_security_revoke_all_app_passwords', array( $this, 'ajax_revoke_all_app_passwords' ) );
		add_action( 'wp_ajax_tsosk_security_test_uploads_php', array( $this, 'ajax_test_uploads_php' ) );
		add_action( 'wp_ajax_tsosk_security_protect_uploads', array( $this, 'ajax_protect_uploads' ) );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );
		// WP_User::add_role() (e.g. $user->add_role('administrator')) fires a
		// different hook than set_role()/the classic "change role" dropdown —
		// without also listening here, that escalation path bypasses this
		// alert entirely.
		add_action( 'add_user_role', array( $this, 'on_add_user_role' ), 10, 2 );
	}

	// ── PHP execution in uploads (arbitrary-file-upload exploit mitigation) ──

	/**
	 * Absolute path to the uploads base directory.
	 *
	 * @return string
	 */
	private function get_uploads_basedir(): string {
		$dirs = wp_upload_dir();
		return is_array( $dirs ) && empty( $dirs['error'] ) ? (string) $dirs['basedir'] : '';
	}

	/**
	 * Path to wp-content/uploads/.htaccess.
	 *
	 * @return string
	 */
	private function uploads_htaccess_path(): string {
		$dir = $this->get_uploads_basedir();
		return '' === $dir ? '' : trailingslashit( $dir ) . '.htaccess';
	}

	/**
	 * Whether wp-content/uploads/.htaccess already contains our protection block
	 * (or an equivalent third-party rule denying PHP execution).
	 *
	 * @return bool
	 */
	private function uploads_htaccess_has_protection(): bool {
		$path = $this->uploads_htaccess_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = (string) file_get_contents( $path );
		if ( false !== strpos( $content, self::UPLOADS_HTACCESS_MARKER ) ) {
			return true;
		}
		// Common third-party equivalents (Wordfence, iThemes/Solid Security, manual rules).
		return (bool) preg_match( '/(php_flag\s+engine\s+off|<FilesMatch[^>]*\\\\\.php|Require all denied)/i', $content );
	}

	/**
	 * Live-probe whether the server executes PHP files placed inside
	 * wp-content/uploads. Writes a tiny, uniquely-named PHP file, requests it
	 * over HTTP, then deletes it — the same test recommended after any
	 * arbitrary-file-upload incident.
	 *
	 * @return array{status:string,message:string}
	 */
	private function run_uploads_php_test(): array {
		$dir = $this->get_uploads_basedir();
		if ( '' === $dir || ! wp_is_writable( $dir ) ) {
			return array(
				'status'  => 'unknown',
				'message' => __( 'Could not write a test file to the uploads folder to run this check.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		$marker   = 'TSOSK_PHP_EXEC_TEST_' . wp_generate_password( 12, false, false );
		$filename = 'tsosk-phptest-' . wp_generate_password( 10, false, false ) . '.php';
		$abs_path = trailingslashit( $dir ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $abs_path, '<?php echo ' . "'" . $marker . "'" . '; ?>' );
		if ( false === $written ) {
			return array(
				'status'  => 'unknown',
				'message' => __( 'Could not write a test file to the uploads folder to run this check.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		$dirs = wp_upload_dir();
		$url  = ( is_array( $dirs ) ? (string) $dirs['baseurl'] : '' ) . '/' . $filename;

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 12,
				'sslverify' => false,
			)
		);

		wp_delete_file( $abs_path );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'unknown',
				/* translators: %s: error message */
				'message' => sprintf( __( 'Could not reach the test file over HTTP to verify: %s. Test manually if in doubt.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( false !== strpos( $body, $marker ) ) {
			return array(
				'status'  => 'executed',
				'message' => __( 'PHP files placed inside wp-content/uploads are executed by your server. If any plugin ever allows an unvalidated file upload there, an uploaded PHP file could run immediately. Use "Enable protection" below to block this.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		if ( 403 === $code || 404 === $code ) {
			return array(
				'status'  => 'blocked',
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'PHP execution inside wp-content/uploads is blocked (HTTP %d). Even if a malicious file were ever uploaded there, it could not run.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $code ),
			);
		}

		return array(
			'status'  => 'blocked',
			'message' => __( 'The test file did not execute (its PHP source was not run). PHP execution inside wp-content/uploads appears to be blocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/** AJAX: run the uploads PHP-execution probe and store the result. */
	public function ajax_test_uploads_php(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result              = $this->run_uploads_php_test();
		$result['checked_at'] = time();
		update_option( self::UPLOADS_TEST_OPTION, $result, false );

		TSOSK_Activity_Log::log(
			'security',
			'uploads-php-test',
			sprintf(
				/* translators: %s: test result status */
				__( 'Tested whether PHP executes inside wp-content/uploads (result: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$result['status']
			)
		);

		wp_send_json_success( $result );
	}

	/**
	 * Write the protection block into wp-content/uploads/.htaccess (Apache/LiteSpeed only).
	 *
	 * @return true|WP_Error
	 */
	private function write_uploads_htaccess() {
		// Idempotency guard: never append a second copy of the block (or stack
		// on top of an existing equivalent rule) if this runs more than once —
		// e.g. a stale page, a repeated click, or a future automated caller.
		if ( $this->uploads_htaccess_has_protection() ) {
			return true;
		}

		$dir = $this->get_uploads_basedir();
		if ( '' === $dir ) {
			return new WP_Error( 'no_uploads_dir', __( 'Could not locate the uploads folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'not_writable', __( 'The uploads folder is not writable by PHP. Add the rule manually via FTP/SFTP.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$path    = $this->uploads_htaccess_path();
		$existing = ( '' !== $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$block = "# BEGIN " . self::UPLOADS_HTACCESS_MARKER . "\n"
			. "<IfModule mod_php.c>\n"
			. "  php_flag engine off\n"
			. "</IfModule>\n"
			. "<IfModule mod_php7.c>\n"
			. "  php_flag engine off\n"
			. "</IfModule>\n"
			. "<FilesMatch \"\\.(?i:php|php3|php4|php5|php7|phtml|phar|pht)$\">\n"
			. "  <IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "  </IfModule>\n"
			. "  <IfModule !mod_authz_core.c>\n"
			. "    Order allow,deny\n"
			. "    Deny from all\n"
			. "  </IfModule>\n"
			. "</FilesMatch>\n"
			. "# END " . self::UPLOADS_HTACCESS_MARKER . "\n";

		$new_content = rtrim( $existing ) . ( '' !== trim( $existing ) ? "\n\n" : '' ) . $block;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- .htaccess is a server config file; wp_upload_dir() is the correct location for this specific rule (it protects that folder).
		$result = file_put_contents( $path, $new_content );
		if ( false === $result ) {
			return new WP_Error( 'write_failed', __( 'Could not write wp-content/uploads/.htaccess. Check folder permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		return true;
	}

	/** AJAX: write the uploads protection .htaccess block. */
	public function ajax_protect_uploads(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = $this->write_uploads_htaccess();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log( 'security', 'uploads-protected', __( 'Wrote PHP-execution protection into wp-content/uploads/.htaccess.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		// Refresh the cached test result so the UI reflects the change immediately.
		delete_option( self::UPLOADS_TEST_OPTION );

		wp_send_json_success( __( 'Protection added. Re-test to confirm your server (Apache/LiteSpeed) applies it — Nginx requires a manual server-block rule instead.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Last stored uploads PHP-execution test result.
	 *
	 * @return array{status:string,message:string,checked_at:int}|null
	 */
	private function get_uploads_test_result(): ?array {
		$v = get_option( self::UPLOADS_TEST_OPTION, null );
		return is_array( $v ) && isset( $v['status'] ) ? $v : null;
	}

	// ── Backdated / cloned-timestamp admin detection ─────────────────────────

	/**
	 * Administrator accounts that share an identical (to the second)
	 * user_registered timestamp with another user on the site — the exact
	 * trick some malware uses to hide a rogue admin from "sort by newest".
	 *
	 * @return array<int,array{user_id:int,user_login:string,user_registered:string,shared_with:int}>
	 */
	private function get_admins_with_cloned_registration_date(): array {
		$all_users = get_users( array( 'fields' => array( 'ID', 'user_login', 'user_registered', 'user_email' ) ) );

		$by_timestamp = array();
		foreach ( $all_users as $u ) {
			$ts = (string) $u->user_registered;
			if ( '' === $ts || '0000-00-00 00:00:00' === $ts ) {
				continue;
			}
			$by_timestamp[ $ts ][] = $u;
		}

		$admin_ids = array_map( 'absint', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );

		$out = array();
		foreach ( $by_timestamp as $ts => $users ) {
			if ( count( $users ) < 2 ) {
				continue;
			}
			foreach ( $users as $u ) {
				if ( ! in_array( (int) $u->ID, $admin_ids, true ) ) {
					continue;
				}
				$out[] = array(
					'user_id'         => (int) $u->ID,
					'user_login'      => (string) $u->user_login,
					'user_registered' => $ts,
					'shared_with'     => count( $users ) - 1,
				);
			}
		}
		return $out;
	}

	// ── New-admin / role-escalation alerts ───────────────────────────────────

	/**
	 * @return array{enabled:bool,email:string}
	 */
	private function get_alert_settings(): array {
		$defaults = array(
			'enabled' => true,
			'email'   => get_option( 'admin_email' ),
		);
		$stored = get_option( self::ALERTS_OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/** AJAX: save the new-admin/role-escalation alert settings. */
	public function ajax_save_alerts(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : get_option( 'admin_email' );
		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( __( 'Enter a valid email address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		update_option(
			self::ALERTS_OPTION,
			array(
				'enabled' => ! empty( $_POST['enabled'] ),
				'email'   => $email,
			),
			false
		);

		TSOSK_Activity_Log::log( 'security', 'save', __( 'Security alert settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Best-effort visitor IP (own copy — every module that needs one keeps
	 * its own, matching this plugin's existing decentralized pattern).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return preg_match( '/^[0-9a-fA-F.:]+$/', $ip ) ? $ip : '';
	}

	/**
	 * Identify how the current request reached WordPress, so an alert can
	 * tell a normal wp-admin form submission apart from a silent background
	 * request (REST/AJAX/XML-RPC) — exactly the pattern behind a browser
	 * extension or stored-XSS attack creating a hidden admin account.
	 *
	 * @return string
	 */
	private function detect_request_origin(): string {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return __( 'REST API request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return __( 'XML-RPC request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return __( 'background AJAX request (admin-ajax.php)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( is_admin() ) {
			return __( 'normal wp-admin form', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'front-end request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Send the alert email if the feature is enabled and an address is set.
	 *
	 * @param string $subject Email subject.
	 * @param string $message Email body (plain text).
	 */
	private function maybe_send_alert( string $subject, string $message ): void {
		$settings = $this->get_alert_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['email'] ) || ! is_email( $settings['email'] ) ) {
			return;
		}
		wp_mail( $settings['email'], $subject, $message );
	}

	/**
	 * Log every new user, and alert immediately when the new account is (or
	 * includes) Administrator — the exact moment that matters in a hidden
	 * rogue-admin attack.
	 *
	 * @param int $user_id Newly created user id.
	 */
	public function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$ip     = $this->get_client_ip();
		$origin = $this->detect_request_origin();
		$roles  = implode( ', ', (array) $user->roles );

		TSOSK_Activity_Log::log(
			'security',
			'user-created',
			sprintf(
				/* translators: 1: username, 2: role list, 3: IP address, 4: how the request reached WordPress */
				__( 'New user "%1$s" created (role: %2$s) from IP %3$s via %4$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user->user_login,
				'' !== $roles ? $roles : __( 'none', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$origin
			)
		);

		if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return;
		}

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] New administrator account created', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$message = sprintf(
			/* translators: 1: username, 2: email, 3: IP address, 4: user agent, 5: request origin */
			__( "A new Administrator account was just created on your site.\n\nUsername: %1\$s\nEmail: %2\$s\nIP address: %3\$s\nBrowser: %4\$s\nCreated via: %5\$s\n\nIf you did not do this yourself, change your WordPress and hosting passwords immediately and review Tools > TSO Swiss Knife > Security.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$user->user_login,
			$user->user_email,
			'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$origin
		);
		$this->maybe_send_alert( $subject, $message );
	}

	/**
	 * Alert on a genuine promotion to Administrator (fires for both the
	 * classic "change role" dropdown and any code path that assigns the
	 * whole role set — e.g. a hidden background request).
	 *
	 * @param int      $user_id  User id.
	 * @param string   $role     New (whole) role.
	 * @param string[] $old_roles Previous roles.
	 */
	public function on_set_user_role( int $user_id, string $role, array $old_roles ): void {
		if ( 'administrator' !== $role || in_array( 'administrator', $old_roles, true ) ) {
			return; // Not a new promotion to Administrator.
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$ip     = $this->get_client_ip();
		$origin = $this->detect_request_origin();

		TSOSK_Activity_Log::log(
			'security',
			'role-escalation',
			sprintf(
				/* translators: 1: username, 2: IP address, 3: how the request reached WordPress */
				__( 'User "%1$s" was promoted to Administrator from IP %2$s via %3$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user->user_login,
				'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$origin
			)
		);

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] A user was promoted to Administrator', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$message = sprintf(
			/* translators: 1: username, 2: email, 3: IP address, 4: request origin */
			__( "The user \"%1\$s\" (%2\$s) was just promoted to Administrator.\n\nIP address: %3\$s\nDone via: %4\$s\n\nIf you did not do this yourself, change your WordPress and hosting passwords immediately and review Tools > TSO Swiss Knife > Security.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$user->user_login,
			$user->user_email,
			'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$origin
		);
		$this->maybe_send_alert( $subject, $message );
	}

	/**
	 * Alert on a promotion to Administrator via WP_User::add_role() — a
	 * separate code path from set_role()/on_set_user_role() above (e.g. a
	 * compromised plugin calling $user->add_role('administrator') directly).
	 *
	 * WordPress core only fires 'add_user_role' when the role is genuinely
	 * new for that user (WP_User::add_role() returns early without firing it
	 * if the role was already present), so no "was it already there" check
	 * is needed here.
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Role that was added.
	 */
	public function on_add_user_role( int $user_id, string $role ): void {
		if ( 'administrator' !== $role ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$ip     = $this->get_client_ip();
		$origin = $this->detect_request_origin();

		TSOSK_Activity_Log::log(
			'security',
			'role-escalation',
			sprintf(
				/* translators: 1: username, 2: IP address, 3: how the request reached WordPress */
				__( 'User "%1$s" was promoted to Administrator from IP %2$s via %3$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user->user_login,
				'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$origin
			)
		);

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] A user was promoted to Administrator', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$message = sprintf(
			/* translators: 1: username, 2: email, 3: IP address, 4: request origin */
			__( "The user \"%1\$s\" (%2\$s) was just promoted to Administrator.\n\nIP address: %3\$s\nDone via: %4\$s\n\nIf you did not do this yourself, change your WordPress and hosting passwords immediately and review Tools > TSO Swiss Knife > Security.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$user->user_login,
			$user->user_email,
			'' !== $ip ? $ip : __( 'unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$origin
		);
		$this->maybe_send_alert( $subject, $message );
	}

	// ── Application Passwords audit ──────────────────────────────────────────

	/**
	 * Every Application Password on the site, across all users.
	 *
	 * @return array<int, array{user_id:int,user_login:string,uuid:string,name:string,created:int,last_used:int,last_ip:string}>
	 */
	private function get_all_application_passwords(): array {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_application_passwords'",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}
			$passwords = maybe_unserialize( $row['meta_value'] ?? '' );
			if ( ! is_array( $passwords ) || empty( $passwords ) ) {
				continue;
			}
			$user = get_userdata( $user_id );
			foreach ( $passwords as $app ) {
				if ( ! is_array( $app ) || empty( $app['uuid'] ) ) {
					continue;
				}
				$out[] = array(
					'user_id'    => $user_id,
					'user_login' => $user ? $user->user_login : ( '#' . $user_id ),
					'uuid'       => (string) $app['uuid'],
					'name'       => (string) ( $app['name'] ?? '' ),
					'created'    => absint( $app['created'] ?? 0 ),
					'last_used'  => absint( $app['last_used'] ?? 0 ),
					'last_ip'    => (string) ( $app['last_ip'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** AJAX: revoke a single Application Password. */
	public function ajax_revoke_app_password(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( __( 'Application Passwords are not available on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( $user_id <= 0 || '' === $uuid ) {
			wp_send_json_error( __( 'Invalid request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $result ) || ! $result ) {
			wp_send_json_error( __( 'Could not revoke that Application Password.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		TSOSK_Activity_Log::log(
			'security',
			'app-password-revoked',
			sprintf(
				/* translators: %d: user id */
				__( 'Application Password revoked for user #%d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user_id
			)
		);
		wp_send_json_success( __( 'Application Password revoked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: revoke every Application Password, for every user, site-wide. */
	public function ajax_revoke_all_app_passwords(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( __( 'Application Passwords are not available on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$user_ids = array();
		foreach ( $this->get_all_application_passwords() as $app ) {
			$user_ids[ $app['user_id'] ] = true;
		}

		$count = 0;
		foreach ( array_keys( $user_ids ) as $uid ) {
			$result = WP_Application_Passwords::delete_all_application_passwords( $uid );
			if ( ! is_wp_error( $result ) ) {
				$count += (int) $result;
			}
		}

		TSOSK_Activity_Log::log(
			'security',
			'app-password-revoked-all',
			sprintf(
				/* translators: %d: number of revoked application passwords */
				__( 'Revoked %d Application Password(s) across all users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			)
		);
		wp_send_json_success(
			sprintf(
				/* translators: %d: number of revoked application passwords */
				__( '%d Application Password(s) revoked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			)
		);
	}

	/**
	 * Administrator accounts registered in the last N days.
	 *
	 * @param int $days Window size.
	 * @return int[] User ids.
	 */
	private function get_recent_admin_registrations( int $days ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$users  = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_registered' ),
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			if ( $u->user_registered >= $cutoff ) {
				$out[] = (int) $u->ID;
			}
		}
		return $out;
	}

	/** AJAX: save toggleable constants. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
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

		TSOSK_Activity_Log::log( 'security', 'save', __( 'Security settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Security settings saved. Reload the page to see the new values.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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
		$needs_ssl_override = empty( $flags['FORCE_SSL_ADMIN'] )
			&& $this->is_defined_in_wp_config( 'FORCE_SSL_ADMIN' )
			&& defined( 'FORCE_SSL_ADMIN' )
			&& FORCE_SSL_ADMIN;

		return TSOSK_Config_Storage::save_security_flags( $flags, $needs_ssl_override );
	}

	/**
	 * Read current values of the managed constants from JSON config.
	 *
	 * @return array<string,bool>
	 */
	private function get_mu_settings(): array {
		return TSOSK_Config_Storage::get_security_flags()['constants'];
	}

	/**
	 * Whether FORCE_SSL_ADMIN is turned off via TSO filter override.
	 *
	 * @return bool
	 */
	private function is_force_ssl_overridden_off(): bool {
		return TSOSK_Config_Storage::get_security_flags()['force_ssl_admin_override_off'];
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
		$nonce         = wp_create_nonce( 'tsosk_security_nonce' );
		$checks        = $this->get_checks();
		$mu            = $this->get_mu_settings();
		$remote        = $this->get_remote_access_flags();
		$alerts        = $this->get_alert_settings();
		$app_passwords = $this->get_all_application_passwords();
		$uploads_test  = $this->get_uploads_test_result();
		$uploads_protected = $this->uploads_htaccess_has_protection();
		$cloned_admins = $this->get_admins_with_cloned_registration_date();
		$mu_exists     = TSOSK_Config_Storage::json_exists( TSOSK_Config_Storage::SECURITY_JSON );
		$legacy_exists = file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE );

		$toggles = array(
			'DISALLOW_FILE_EDIT' => array(
				'label' => 'DISALLOW_FILE_EDIT',
				'desc'  => __( 'Hides the built-in plugin and theme code editors from the WordPress admin (Appearance › Editor, Plugins › Editor). Strongly recommended on production sites to prevent accidental or malicious code injection via the dashboard.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Recommended: ON on all production sites.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'disallow_file_edit',
			),
			'DISALLOW_FILE_MODS' => array(
				'label' => 'DISALLOW_FILE_MODS',
				'desc'  => __( 'Prevents any plugin or theme from being installed, updated or deleted from the WordPress admin. Also disables the WordPress auto-updater. Use on hardened servers where all deploys are done via version control. Note: this also blocks WordPress core auto-updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Optional. Only use if you manage updates via CI/CD or WP-CLI. Do not use if you rely on the admin for updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'disallow_file_mods',
			),
			'FORCE_SSL_ADMIN'    => array(
				'label' => 'FORCE_SSL_ADMIN',
				'desc'  => __( 'Forces the WordPress login page and admin area to always use HTTPS, even if the front end runs on HTTP. Only enable this if you have a valid SSL certificate. If you do not have SSL, this will lock you out of the admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Recommended: ON if you have SSL. Never enable without a working SSL certificate.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'force_ssl_admin',
			),
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Read-only security review plus safe toggles for WordPress hardening constants. Toggles save JSON config under the plugin uploads folder that is loaded at plugin init time. Constants already defined in wp-config.php take precedence.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card" id="tsosk-security-remote-access">
			<h3><?php esc_html_e( 'Remote access', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Reduce common attack vectors from anonymous visitors and remote clients.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_security_disable_xmlrpc" id="tsosk-sec-disable-xmlrpc" value="1" <?php checked( $remote['disable_xmlrpc'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Disable XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Blocks pingbacks and remote publishing via xmlrpc.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="tsosk-hint"><?php esc_html_e( 'Some plugins and apps need XML-RPC to work: Jetpack, the WordPress mobile apps, and remote-management tools such as MainWP, ManageWP or InfiniteWP. Check before disabling.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php $xmlrpc_dependents = $this->get_xmlrpc_dependent_plugins(); ?>
					<?php if ( ! empty( $xmlrpc_dependents ) ) : ?>
						<span class="tsosk-hint"><strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: comma-separated plugin names */
									__( 'Needed by: %s. Do not disable it while these plugins are active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									implode( ', ', $xmlrpc_dependents )
								)
							);
							?>
						</strong></span>
					<?php endif; ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_security_disable_feeds" id="tsosk-sec-disable-feeds" value="1" <?php checked( $remote['disable_feeds'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Disable RSS feeds', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Redirects feed URLs; reduces discovery surface.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
			<p class="description" style="margin-top:12px;">
				<?php esc_html_e( 'REST API access and namespace controls are configured in the REST API tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<a href="<?php echo esc_url( $this->tab_url( 'rest-api' ) ); ?>"><?php esc_html_e( 'Open REST API settings →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $this->tab_url( 'login-protect' ) ); ?>"><?php esc_html_e( 'Login protection →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'User & Admin Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Get an email the moment a new user is created or an existing user is promoted to Administrator — the exact moment a rogue-admin attack happens. The alert also records how the request reached WordPress (normal admin form, or a silent background request), which is often the clearest sign something is wrong.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_security_alerts_enabled" id="tsosk-sec-alerts-enabled" value="1" <?php checked( $alerts['enabled'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Email me on new administrators', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Covers both a brand-new account created as Administrator and any existing user promoted to Administrator.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
			<div class="tsosk-field-row">
				<label for="tsosk-sec-alerts-email"><?php esc_html_e( 'Notification email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<input type="email" id="tsosk-sec-alerts-email" value="<?php echo esc_attr( $alerts['email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
			</div>
			<p style="margin-top:12px;">
				<button class="button button-primary" id="tsosk-security-save-alerts"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"
				        data-save-label="<?php echo esc_attr__( 'Save Alert Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					<?php esc_html_e( 'Save Alert Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-security-alerts-msg"></span>
			</p>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'A compromised browser extension can create a hidden admin user or issue other authenticated requests using your own logged-in session — without touching your server or triggering a firewall rule, because the request looks like it came from you. No WordPress plugin can inspect or block what runs inside your browser. If you ever see an account, Application Password, or Search Console user you do not recognize, the safest first steps are: remove any suspicious browser extensions, scan your computer for malware, then rotate your WordPress, hosting and email passwords from a clean device or profile.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</div>

		<div class="tsosk-card" id="tsosk-security-app-passwords">
			<h3><?php esc_html_e( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Application Passwords let a script or app authenticate as a user via the REST API without a normal login — and, unlike a hidden user account, they survive deletion of the account that created them if not revoked directly. Review the list below and revoke anything you do not recognize.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( ! class_exists( 'WP_Application_Passwords' ) ) : ?>
				<div class="tsosk-notice tsosk-notice-info">
					<?php esc_html_e( 'Application Passwords are not available on this site (requires WordPress 5.6+ and HTTPS, or the feature has been disabled).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
			<?php elseif ( empty( $app_passwords ) ) : ?>
				<div class="tsosk-notice tsosk-notice-info">
					<?php esc_html_e( 'No Application Passwords are currently active on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Created', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $app_passwords as $app ) : ?>
						<tr>
							<td><?php echo esc_html( $app['user_login'] ); ?></td>
							<td><?php echo esc_html( '' !== $app['name'] ? $app['name'] : __( '(unnamed)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td><?php echo esc_html( $app['created'] ? date_i18n( get_option( 'date_format' ), $app['created'] ) : __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td><?php echo esc_html( $app['last_used'] ? date_i18n( get_option( 'date_format' ), $app['last_used'] ) : __( 'Never', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td><?php echo esc_html( '' !== $app['last_ip'] ? $app['last_ip'] : '—' ); ?></td>
							<td>
								<button type="button" class="button tsosk-security-revoke-app-password"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>"
								        data-user-id="<?php echo esc_attr( $app['user_id'] ); ?>"
								        data-uuid="<?php echo esc_attr( $app['uuid'] ); ?>">
									<?php esc_html_e( 'Revoke', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top:12px;">
					<button class="button" id="tsosk-security-revoke-all-app-passwords" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Revoke All Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-security-app-passwords-msg"></span>
				</p>
			<?php endif; ?>
		</div>

		<div class="tsosk-card" id="tsosk-security-uploads-php">
			<h3><?php esc_html_e( 'PHP Execution in Uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'The single control that most often stops an arbitrary-file-upload attack from becoming a full takeover: even if a vulnerable plugin lets an attacker write a PHP file into wp-content/uploads, the file can never run if the server refuses to execute PHP there. This test writes a harmless temporary PHP file, requests it over HTTP, then deletes it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<?php if ( null === $uploads_test ) : ?>
				<div class="tsosk-notice tsosk-notice-info" id="tsosk-sec-uploads-status">
					<?php esc_html_e( 'Not tested yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
			<?php else : ?>
				<?php
				$status_class = 'executed' === $uploads_test['status'] ? 'tsosk-notice-warn' : ( 'blocked' === $uploads_test['status'] ? 'tsosk-notice-info' : 'tsosk-notice-info' );
				?>
				<div class="tsosk-notice <?php echo esc_attr( $status_class ); ?>" id="tsosk-sec-uploads-status">
					<?php if ( 'blocked' === $uploads_test['status'] ) : ?>✓ <?php elseif ( 'executed' === $uploads_test['status'] ) : ?>⚠ <?php endif; ?>
					<?php echo esc_html( $uploads_test['message'] ); ?>
					<?php if ( ! empty( $uploads_test['checked_at'] ) ) : ?>
						<br><span style="font-size:11px;color:#666;">
						<?php
						printf(
							/* translators: 1: date, 2: time */
							esc_html__( 'Last tested: %1$s at %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							esc_html( gmdate( 'Y-m-d', (int) $uploads_test['checked_at'] ) ),
							esc_html( gmdate( 'H:i', (int) $uploads_test['checked_at'] ) )
						);
						?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $uploads_protected ) : ?>
				<div class="tsosk-notice tsosk-notice-info">
					<?php esc_html_e( 'A PHP-execution block was found in wp-content/uploads/.htaccess (added by this plugin or already present).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
			<?php endif; ?>

			<p style="margin-top:12px;">
				<button class="button button-primary" id="tsosk-security-test-uploads-php" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Test Now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<?php if ( ! $uploads_protected ) : ?>
				<button class="button" id="tsosk-security-protect-uploads" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Enable Protection (Apache/LiteSpeed)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<?php endif; ?>
				<span class="tsosk-ajax-msg" id="tsosk-security-uploads-msg"></span>
			</p>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'This writes a rule into wp-content/uploads/.htaccess. It only works on Apache or LiteSpeed. On Nginx, ask your host to add: location ~* /wp-content/uploads/.*\.php$ { deny all; }', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>

		<?php if ( ! empty( $cloned_admins ) ) : ?>
		<div class="tsosk-card" id="tsosk-security-shared-admins">
			<h3><?php esc_html_e( 'Administrators with a Shared Creation Date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php esc_html_e( 'Some malware hides a rogue administrator account by copying another existing user\'s exact registration timestamp, so "sort users by newest" never reveals it. These administrator accounts share their creation timestamp, down to the second, with at least one other user on this site — review each one.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Registration date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Shared with', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $cloned_admins as $ca ) : ?>
					<tr>
						<td><?php echo esc_html( $ca['user_login'] ); ?></td>
						<td class="tsosk-code"><?php echo esc_html( $ca['user_registered'] ); ?></td>
						<td>
							<?php
							printf(
								/* translators: %d: number of other users sharing this timestamp */
								esc_html__( '%d other user(s)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $ca['shared_with']
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<div class="tsosk-card" id="tsosk-security-constants">
			<h3><?php esc_html_e( 'Toggleable Security Constants', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( ! empty( $legacy_exists ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: legacy file path */
						__( 'Legacy file found in mu-plugins: %s — save settings once to migrate it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
						'<code>' . esc_html( trailingslashit( TSOSK_CONFIG_DIR ) . TSOSK_Config_Storage::SECURITY_JSON ) . '</code>'
					),
					array( 'code' => array() )
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
									<?php esc_html_e( 'Currently ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</span>
							<?php else : ?>
								<span class="tsosk-badge" style="margin-left:6px;">
									<?php esc_html_e( 'Currently OFF', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $wp_config_locked ) : ?>
								<span class="tsosk-badge tsosk-badge-info" style="margin-left:4px;" title="<?php esc_attr_e( 'Defined in wp-config.php — edit that file to change or remove it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<?php esc_html_e( 'wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</span>
							<?php elseif ( $mu_on ) : ?>
								<span class="tsosk-badge tsosk-badge-info" style="margin-left:4px;">
									<?php esc_html_e( 'TSO config', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<p class="description tsosk-security-toggle-desc"><?php echo esc_html( $toggle['desc'] ); ?></p>
						<p class="description tsosk-security-toggle-rec"><?php echo esc_html( $toggle['rec'] ); ?></p>
						<?php if ( $wp_config_locked && 'FORCE_SSL_ADMIN' === $const ) : ?>
						<p class="description tsosk-security-toggle-wpconfig">
							<?php esc_html_e( 'Defined in wp-config.php. Uncheck here to disable SSL admin via a TSO override filter (saved on this panel). To remove the wp-config line entirely, edit that file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
						<?php elseif ( $wp_config_locked ) : ?>
						<p class="description tsosk-security-toggle-wpconfig">
							<?php esc_html_e( 'This constant is set in wp-config.php. Remove or change that line there to manage it from this panel.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
			        data-save-label="<?php echo esc_attr__( 'Save Security Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				<?php esc_html_e( 'Save Security Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-security-msg"></span>
		</p>

		<div class="tsosk-card" id="tsosk-security-checks">
			<h3><?php esc_html_e( 'Security Checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Read-only overview of common security settings. This does not replace a firewall or security scanner.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Details', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
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

	/**
	 * Names of active plugins known to need XML-RPC (Jetpack, remote-management tools).
	 *
	 * Filterable through `tsosk_xmlrpc_dependent_plugins` (plugin basename => display name).
	 *
	 * @return string[] Display names of the active plugins that rely on XML-RPC.
	 */
	private function get_xmlrpc_dependent_plugins(): array {
		$known = array(
			'jetpack/jetpack.php'           => 'Jetpack',
			'mainwp-child/mainwp-child.php' => 'MainWP Child',
			'worker/init.php'               => 'ManageWP Worker',
			'iwp-client/init.php'           => 'InfiniteWP Client',
		);
		$known = (array) apply_filters( 'tsosk_xmlrpc_dependent_plugins', $known );

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$names = array();
		foreach ( $known as $basename => $name ) {
			if ( in_array( $basename, $active, true ) ) {
				$names[] = (string) $name;
			}
		}
		return $names;
	}

	/**
	 * Security checks for the Overview dashboard, with a target tab per check.
	 *
	 * @return array
	 */
	public function get_dashboard_checks(): array {
		$checks = $this->get_checks();
		// Each entry: check label => [ tab, anchor id in that tab (optional) ].
		$targets = array(
			__( 'File editor disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-constants' ),
			__( 'Force SSL admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )          => array( 'security', 'tsosk-security-constants' ),
			__( 'XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )                  => array( 'security', 'tsosk-security-remote-access' ),
			__( 'Default admin username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )   => array( 'security', 'tsosk-security-checks' ),
			__( 'Plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )           => array( 'update-manager', 'tsosk-um-plugins' ),
			__( 'Theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )            => array( 'update-manager', 'tsosk-um-status' ),
			__( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-app-passwords' ),
			__( 'Recent administrator accounts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'users', 'tsosk-users-summary' ),
			__( 'Administrator accounts with a shared creation date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-shared-admins' ),
			__( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-uploads-php' ),
			__( '.htaccess permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-checks' ),
			__( 'wp-config.php permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-checks' ),
		);
		foreach ( $checks as $i => $check ) {
			$label = (string) ( $check['label'] ?? '' );
			if ( isset( $targets[ $label ] ) ) {
				$checks[ $i ]['tab']    = $targets[ $label ][0];
				$checks[ $i ]['anchor'] = $targets[ $label ][1];
			}
		}
		return $checks;
	}

	/** Build security checks. */
	private function get_checks(): array {
		$admin_user     = get_user_by( 'login', 'admin' );
		$wp_config      = $this->find_wp_config();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- checking WP core filter
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true );
		$plugin_updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$theme_updates  = function_exists( 'get_theme_updates' )  ? get_theme_updates()  : array();
		$app_passwords  = $this->get_all_application_passwords();
		$recent_admins  = $this->get_recent_admin_registrations( 7 );
		$uploads_test   = $this->get_uploads_test_result();
		$cloned_admins  = $this->get_admins_with_cloned_registration_date();

		$uploads_check = null;
		if ( null === $uploads_test ) {
			$uploads_check = array(
				'label'   => __( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Not tested', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => __( 'Not tested yet — run the test below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		} elseif ( 'executed' === $uploads_test['status'] ) {
			$uploads_check = array(
				'label'   => __( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-warn',
				'details' => (string) $uploads_test['message'],
			);
		} elseif ( 'blocked' === $uploads_test['status'] ) {
			$uploads_check = array(
				'label'   => __( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-ok',
				'details' => (string) $uploads_test['message'],
			);
		} else {
			$uploads_check = array(
				'label'   => __( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => (string) $uploads_test['message'],
			);
		}

		$xmlrpc_needed = $xmlrpc_enabled ? $this->get_xmlrpc_dependent_plugins() : array();
		if ( ! empty( $xmlrpc_needed ) ) {
			$xmlrpc_check = array(
				'label'   => __( 'XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'XML-RPC is enabled and these active plugins rely on it: %s. Keep it on while you use them; otherwise disable it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $xmlrpc_needed )
				),
			);
		} else {
			$xmlrpc_check = $this->check_item( __( 'XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), ! $xmlrpc_enabled, __( 'XML-RPC is disabled by filters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'XML-RPC appears enabled. It can be a brute-force vector if not needed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'warn' );
		}

		return array(
			$this->check_item( __( 'File editor disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),     defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,   __( 'DISALLOW_FILE_EDIT is enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                               __( 'Enable DISALLOW_FILE_EDIT to hide plugin/theme editors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$this->check_item( __( 'Force SSL admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),          is_ssl() || ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ), __( 'Admin is using SSL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                               __( 'Admin may not be forced through SSL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$xmlrpc_check,
			$this->check_item( __( 'Default admin username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),   ! $admin_user,                                              __( 'No user named admin found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                               __( 'A user named admin exists. Rename it to a non-obvious username.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$this->check_item( __( 'Plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),           empty( $plugin_updates ),                                   __( 'No plugin updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                              sprintf( /* translators: %d: number of plugin updates */ __( '%d plugin updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $plugin_updates ) ) ),
			$this->check_item( __( 'Theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),            empty( $theme_updates ),                                    __( 'No theme updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                               sprintf( /* translators: %d: number of theme updates */ __( '%d theme updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $theme_updates ) ) ),
			$this->check_item( __( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),    empty( $app_passwords ),                                    __( 'No Application Passwords are active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                     sprintf( /* translators: %d: number of active Application Passwords */ __( '%d Application Password(s) active — review them below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $app_passwords ) ), 'info' ),
			$this->check_item( __( 'Recent administrator accounts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $recent_admins ),                              __( 'No new administrators in the last 7 days.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                sprintf( /* translators: %d: number of administrators created in the last 7 days */ __( '%d administrator account(s) created in the last 7 days — make sure you recognize them.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $recent_admins ) ) ),
			$this->check_item( __( 'Administrator accounts with a shared creation date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $cloned_admins ),         __( 'No administrator shares its exact creation timestamp with another user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                sprintf( /* translators: %d: number of administrators sharing a registration timestamp with another user */ __( '%d administrator account(s) share their exact creation timestamp with another user — a known trick to hide a rogue admin from "sort by newest". Review them below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $cloned_admins ) ) ),
			$uploads_check,
			$this->file_permission_check( __( '.htaccess permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), tsosk_join_wp_root( '.htaccess' ) ),
			$this->file_permission_check( __( 'wp-config.php permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $wp_config ?: tsosk_join_wp_root( 'wp-config.php' ) ),
		);
	}

	private function check_item( string $label, bool $ok, string $ok_details, string $warn_details, string $warn_badge = 'warn' ): array {
		return array( 'label' => $label, 'status' => $ok ? __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $ok ? 'tsosk-badge-ok' : 'tsosk-badge-' . $warn_badge, 'details' => $ok ? $ok_details : $warn_details );
	}

	private function file_permission_check( string $label, string $path ): array {
		if ( ! $path || ! file_exists( $path ) ) {
			return array( 'label' => $label, 'status' => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => 'tsosk-badge-info', 'details' => __( 'File not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$perms       = substr( sprintf( '%o', fileperms( $path ) ), -4 );
		$is_writable = wp_is_writable( $path );
		return array( 'label' => $label, 'status' => $is_writable ? __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $is_writable ? 'tsosk-badge-warn' : 'tsosk-badge-ok', 'details' => sprintf( /* translators: %s: file permission octal */ __( 'Permissions: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $perms ) );
	}

	private function find_wp_config(): string {
		return tsosk_locate_wp_config_path();
	}
}
