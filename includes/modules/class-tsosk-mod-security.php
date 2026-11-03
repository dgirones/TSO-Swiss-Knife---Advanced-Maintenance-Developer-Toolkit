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

	/**
	 * Mu file.
	 *
	 * @deprecated Legacy PHP filename — migrated to JSON on read.
	 */
	private const MU_FILE = 'tsosk-security-flags.php';

	/** Own option for the new-admin/role-escalation alert settings (never shared with tsosk_alert_settings — Health's save handler rewrites that option wholesale and would silently wipe unrelated keys). */
	private const ALERTS_OPTION = 'tsosk_security_alerts';

	/** Option storing the last "PHP execution in uploads" test result. */
	private const UPLOADS_TEST_OPTION = 'tsosk_security_uploads_php_test';

	/** Marker wrapping our rule block inside wp-content/uploads/.htaccess. */
	private const UPLOADS_HTACCESS_MARKER = 'TSO Swiss Knife — block PHP execution in uploads';

	/** Option storing reversible Apache hardening toggles for the WP .htaccess. */
	private const SERVER_RULES_OPTION = 'tsosk_security_server_rules';

	/** Marker name passed to insert_with_markers() for the root .htaccess hardening block. */
	private const HTACCESS_MARKER = 'TSO Swiss Knife Security';

	/** Marker for wp-content/cache/.htaccess PHP block. */
	private const CACHE_HTACCESS_MARKER = 'TSO Swiss Knife — block PHP execution in cache';

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Security|null
	 */
	private static $instance = null;

	/**
	 * Per-request cache of the Application Passwords audit (the Security page asks for it several times).
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $app_passwords_cache = null;

	/**
	 * Per-request cache of administrators sharing a creation timestamp.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $cloned_admins_cache = null;

	/**
	 * Get instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'wp_ajax_tsosk_security_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_security_save_alerts', array( $this, 'ajax_save_alerts' ) );
		add_action( 'wp_ajax_tsosk_security_save_server_rules', array( $this, 'ajax_save_server_rules' ) );
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
		// Commented-out rules do nothing, so they must not count as protection.
		$content = (string) preg_replace( '/^\s*#.*$/m', '', $content );
		// Common third-party equivalents (Wordfence, iThemes/Solid Security, manual rules).
		// A deny rule only counts when it is tied to PHP files (a "Require all denied" for debug.log or images does not block PHP).
		return (bool) preg_match( '/(php_flag\s+engine\s+off|RemoveHandler\s+\.php|<Files(?:Match)?\s[^>]*php[^>]*>[^<]*?(?:Require\s+all\s+denied|Deny\s+from\s+all|SetHandler\s+none))/is', $content );
	}

	/**
	 * Check whether PHP execution inside wp-content/uploads is blocked, without writing any
	 * executable file: a server-side rule is looked for in uploads/.htaccess (or the equivalent
	 * third-party rules). A live execution probe is deliberately not performed because it would
	 * mean writing a .php file into a web-accessible folder.
	 *
	 * @return array{status:string,message:string}
	 */
	private function run_uploads_php_test(): array {
		if ( '' === $this->get_uploads_basedir() ) {
			return array(
				'status'  => 'unknown',
				'message' => __( 'The uploads folder could not be located to run this check.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		if ( $this->uploads_htaccess_has_protection() ) {
			global $is_nginx, $is_IIS, $is_iis7;
			if ( ! empty( $is_nginx ) || ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) {
				return array(
					'status'  => 'unknown',
					'message' => __( 'A PHP-blocking rule exists in wp-content/uploads/.htaccess, but this server runs Nginx or IIS, which ignore .htaccess files. Add the equivalent rule to the server configuration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
			}
			return array(
				'status'  => 'blocked',
				'message' => __( 'A rule that blocks PHP execution inside wp-content/uploads was found in its .htaccess file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		return array(
			'status'  => 'unknown',
			'message' => __( 'No rule blocking PHP execution inside wp-content/uploads was found in its .htaccess file. Use "Enable protection" below (Apache/LiteSpeed). On Nginx or IIS, add the equivalent rule to the server configuration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/** AJAX: run the uploads PHP-execution probe and store the result. */
	public function ajax_test_uploads_php(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result               = $this->run_uploads_php_test();
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

		$path     = $this->uploads_htaccess_path();
		$existing = ( '' !== $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$block = '# BEGIN ' . self::UPLOADS_HTACCESS_MARKER . "\n"
			. "<IfModule mod_php.c>\n"
			. "  php_flag engine off\n"
			. "</IfModule>\n"
			. "<IfModule mod_php7.c>\n"
			. "  php_flag engine off\n"
			. "</IfModule>\n"
			. "<IfModule mod_php8.c>\n"
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
			. '# END ' . self::UPLOADS_HTACCESS_MARKER . "\n";

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
		$v = is_array( $v ) && isset( $v['status'] ) ? $v : null;
		// The check is only a file read, so never show a stale answer: if the stored "blocked" no longer matches
		// the .htaccess (rule removed later), or a rule exists that was never tested, report what the file says now.
		$protected_now = $this->uploads_htaccess_has_protection();
		if ( ( null !== $v && 'blocked' === $v['status'] && ! $protected_now ) || ( null === $v && $protected_now ) ) {
			$v               = $this->run_uploads_php_test();
			$v['checked_at'] = time();
		}
		return $v;
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
		if ( null === $this->cloned_admins_cache ) {
			$this->cloned_admins_cache = $this->query_admins_with_cloned_registration_date();
		}
		return $this->cloned_admins_cache;
	}

	/**
	 * Finds administrators sharing a creation timestamp with another user (uncached).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function query_admins_with_cloned_registration_date(): array {
		// Only the (few) administrators are loaded; each one is compared with a COUNT, so a site with
		// tens of thousands of members never has to load every user into memory.
		global $wpdb;
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login', 'user_registered' ),
			)
		);

		$out = array();
		foreach ( $admins as $u ) {
			$ts = (string) $u->user_registered;
			if ( '' === $ts || '0000-00-00 00:00:00' === $ts ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered = %s AND ID <> %d", $ts, (int) $u->ID ) );
			if ( $others < 1 ) {
				continue;
			}
			$out[] = array(
				'user_id'         => (int) $u->ID,
				'user_login'      => (string) $u->user_login,
				'user_registered' => $ts,
				'shared_with'     => $others,
			);
		}
		return $out;
	}

	// ── New-admin / role-escalation alerts ───────────────────────────────────

	/**
	 * Get alert settings.
	 *
	 * @return array{enabled:bool,email:string}
	 */
	private function get_alert_settings(): array {
		$defaults = array(
			'enabled' => true,
			'email'   => get_option( 'admin_email' ),
		);
		$stored   = get_option( self::ALERTS_OPTION, array() );
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
		return TSOSK_Support::get_client_ip();
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
		TSOSK_Email_Template::send_message( $settings['email'], $subject, $message );
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

		// wp_insert_user() assigns the role of a brand-new account through set_role() just before it fires
		// user_register, which already sends its own "new administrator" alert: skip it to avoid a duplicate.
		if ( empty( $old_roles ) && $user->user_registered && strtotime( $user->user_registered . ' UTC' ) >= time() - MINUTE_IN_SECONDS ) {
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
		if ( null === $this->app_passwords_cache ) {
			$this->app_passwords_cache = $this->query_all_application_passwords();
		}
		return $this->app_passwords_cache;
	}

	/**
	 * Reads every Application Password from the database (uncached).
	 *
	 * @return array<int, array{user_id:int,user_login:string,uuid:string,name:string,created:int,last_used:int,last_ip:string}>
	 */
	private function query_all_application_passwords(): array {
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
		$out    = array();
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
		$current = $this->get_mu_settings();
		$allowed = array( 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN' );
		foreach ( $allowed as $c ) {
			// JS sends the key as 'tsosk_security_' . lowercase_constant_name.
			$post_key = 'tsosk_security_' . strtolower( $c );
			if ( ! isset( $_POST[ $post_key ] ) ) {
				// Not sent: the toggle is locked by wp-config.php, so keep whatever was stored.
				$flags[ $c ] = ! empty( $current[ $c ] );
				continue;
			}
			$flags[ $c ] = ! empty( $_POST[ $post_key ] );
		}

		// Forcing HTTPS on a request that is not HTTPS would lock the admin out of the site.
		if ( ! empty( $flags['FORCE_SSL_ADMIN'] ) && empty( $current['FORCE_SSL_ADMIN'] ) && ! is_ssl() ) {
			wp_send_json_error( __( 'FORCE_SSL_ADMIN was not enabled: you are not on HTTPS right now, so forcing it could lock you out of the admin. Open this page over https:// and try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = $this->write_mu_plugin( $flags );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$profiles = get_option( 'tsosk_hidden_profiles', array() );
		if ( ! is_array( $profiles ) ) {
			$profiles = array();
		}
		$profiles['disable_xmlrpc']    = ! empty( $_POST['tsosk_security_disable_xmlrpc'] );
		$profiles['disable_feeds']     = ! empty( $_POST['tsosk_security_disable_feeds'] );
		$profiles['disable_pingbacks'] = ! empty( $_POST['tsosk_security_disable_pingbacks'] );
		$profiles['block_author_enum'] = ! empty( $_POST['tsosk_security_block_author_enum'] );
		update_option( 'tsosk_hidden_profiles', $profiles, true ); // Read on every request.

		$summary_bits = array();
		foreach ( array(
			'DISALLOW_FILE_EDIT' => 'DISALLOW_FILE_EDIT',
			'DISALLOW_FILE_MODS' => 'DISALLOW_FILE_MODS',
			'FORCE_SSL_ADMIN'    => 'FORCE_SSL_ADMIN',
		) as $const => $label ) {
			if ( ! empty( $flags[ $const ] ) ) {
				$summary_bits[] = $label;
			}
		}
		foreach ( array(
			'disable_xmlrpc'    => __( 'Disable XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disable_feeds'     => __( 'Disable RSS feeds', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disable_pingbacks' => __( 'Turn off pingbacks / trackbacks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'block_author_enum' => __( 'Block author scans', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		) as $flag => $label ) {
			if ( ! empty( $profiles[ $flag ] ) ) {
				$summary_bits[] = $label;
			}
		}
		TSOSK_Activity_Log::log(
			'security',
			'save',
			empty( $summary_bits )
				? __( 'Security hardening saved (all WordPress-layer toggles off).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: sprintf(
					/* translators: %s: comma-separated list of enabled toggles */
					__( 'Security hardening saved. On: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $summary_bits )
				)
		);

		// Overview caches Health/Security checks for a few minutes; drop it so the next visit reflects these toggles.
		if ( class_exists( 'TSOSK_Mod_Dashboard' ) ) {
			TSOSK_Mod_Dashboard::bust_scan_cache();
		}

		wp_send_json_success( __( 'Security settings saved. Reload the page to see the new values.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: save reversible Apache server-rule toggles (root .htaccess + cache PHP block).
	 */
	public function ajax_save_server_rules(): void {
		check_ajax_referer( 'tsosk_security_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$force           = ! empty( $_POST['tsosk_security_force_server_rules'] );
		$active_security = $this->get_active_security_plugins();
		if ( ! empty( $active_security ) && ! $force ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'A security plugin is active (%s). Server rules stay read-only unless you confirm the override.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $active_security )
				)
			);
		}

		$rules = array(
			'block_indexes'   => ! empty( $_POST['tsosk_security_block_indexes'] ),
			'deny_wp_config'  => ! empty( $_POST['tsosk_security_deny_wp_config'] ),
			'deny_htaccess'   => ! empty( $_POST['tsosk_security_deny_htaccess'] ),
			'deny_sensitive'  => ! empty( $_POST['tsosk_security_deny_sensitive'] ),
			'block_php_cache' => ! empty( $_POST['tsosk_security_block_php_cache'] ),
		);

		$result = $this->apply_server_rules( $rules );
		if ( is_wp_error( $result ) ) {
			// The cache rule is applied after the .htaccess block: when only it failed, the other rules are already on disk,
			// so store them as they are (cache rule off) instead of leaving the panel out of sync with the files.
			if ( in_array( $result->get_error_code(), array( 'no_cache_dir', 'cache_not_writable', 'cache_htaccess' ), true ) ) {
				$rules['block_php_cache'] = false;
				update_option( self::SERVER_RULES_OPTION, $rules, false );
				if ( class_exists( 'TSOSK_Mod_Dashboard' ) ) {
					TSOSK_Mod_Dashboard::bust_scan_cache();
				}
				wp_send_json_error(
					sprintf(
						/* translators: %s: reason the cache rule could not be written */
						__( 'The other rules were saved, but: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$result->get_error_message()
					)
				);
			}
			wp_send_json_error( $result->get_error_message() );
		}

		update_option( self::SERVER_RULES_OPTION, $rules, false );

		$rule_labels = array(
			'block_indexes'   => __( 'Block directory browsing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'deny_wp_config'  => __( 'Block HTTP access to wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'deny_htaccess'   => __( 'Block access to .htaccess and .htpasswd', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'deny_sensitive'  => __( 'Block access to sensitive files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'block_php_cache' => __( 'Disable PHP execution in cache directories', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		$on_rules    = array();
		foreach ( $rule_labels as $key => $label ) {
			if ( ! empty( $rules[ $key ] ) ) {
				$on_rules[] = $label;
			}
		}
		TSOSK_Activity_Log::log(
			'security',
			'server-rules',
			empty( $on_rules )
				? __( 'Security server rules cleared (TSO .htaccess block removed or empty).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: sprintf(
					/* translators: %s: comma-separated list of enabled server rules */
					__( 'Security server rules saved. On: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $on_rules )
				)
		);

		if ( class_exists( 'TSOSK_Mod_Dashboard' ) ) {
			TSOSK_Mod_Dashboard::bust_scan_cache();
		}

		wp_send_json_success( __( 'Server rules saved. Reload the page if the site behaves unexpectedly and use Server Files to review .htaccess.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Remote-access flags stored in tsosk_hidden_profiles.
	 *
	 * @return array{disable_xmlrpc:bool,disable_feeds:bool,disable_pingbacks:bool,block_author_enum:bool}
	 */
	private function get_remote_access_flags(): array {
		$stored = get_option( 'tsosk_hidden_profiles', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array(
			'disable_xmlrpc'    => ! empty( $stored['disable_xmlrpc'] ),
			'disable_feeds'     => ! empty( $stored['disable_feeds'] ),
			'disable_pingbacks' => ! empty( $stored['disable_pingbacks'] ),
			'block_author_enum' => ! empty( $stored['block_author_enum'] ),
		);
	}

	/**
	 * Stored Apache hardening toggles.
	 *
	 * @return array{block_indexes:bool,deny_wp_config:bool,deny_htaccess:bool,deny_sensitive:bool,block_php_cache:bool}
	 */
	private function get_server_rules(): array {
		$defaults = array(
			'block_indexes'   => false,
			'deny_wp_config'  => false,
			'deny_htaccess'   => false,
			'deny_sensitive'  => false,
			'block_php_cache' => false,
		);
		$stored   = get_option( self::SERVER_RULES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		foreach ( $defaults as $key => $val ) {
			$defaults[ $key ] = ! empty( $stored[ $key ] );
		}
		return $defaults;
	}

	/**
	 * Active security-suite plugins that commonly write overlapping .htaccess rules.
	 *
	 * @return string[] Display names.
	 */
	private function get_active_security_plugins(): array {
		$known = array(
			'better-wp-security/better-wp-security.php' => 'Kadence Security',
			'wordfence/wordfence.php'                   => 'Wordfence',
			'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
			'really-simple-ssl/rlrsssl-really-simple-ssl.php' => 'Really Simple SSL',
			'sucuri-scanner/sucuri.php'                 => 'Sucuri Security',
			'wp-simple-firewall/icwp-wpsf.php'          => 'Shield Security',
			'defender-security/wp-defender.php'         => 'Defender',
			'malcare-security/malcare.php'              => 'MalCare',
			'jetpack/jetpack.php'                       => '', // Only when Protect module is on; skip bare Jetpack.
		);
		$known = (array) apply_filters( 'tsosk_security_suite_plugins', $known );

		if ( ! function_exists( 'is_plugin_active' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}

		$names = array();
		foreach ( $known as $basename => $label ) {
			if ( '' === $label ) {
				continue;
			}
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $basename ) ) {
				$names[] = (string) $label;
			}
		}
		return $names;
	}

	/**
	 * Apply imported server-rule flags (option already saved by the caller).
	 *
	 * @param array<string,bool> $rules Rule flags.
	 * @return true|WP_Error
	 */
	public function apply_imported_server_rules( array $rules ) {
		return $this->apply_server_rules( $rules );
	}

	/**
	 * Build and write the marked .htaccess blocks for the selected rules.
	 *
	 * @param array<string,bool> $rules Rule flags.
	 * @return true|WP_Error
	 */
	private function apply_server_rules( array $rules ) {
		$htaccess = tsosk_join_wp_root( '.htaccess' );
		$lines    = $this->build_htaccess_rule_lines( $rules );

		if ( ! function_exists( 'insert_with_markers' ) ) {
			tsosk_require_wp_admin( 'includes/misc.php' );
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			return new WP_Error( 'no_markers', __( 'Could not load WordPress insert_with_markers().', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Nginx/IIS ignore .htaccess: writing rules there would only look protective. Turning rules off stays allowed.
		global $is_nginx, $is_IIS, $is_iis7;
		if ( ( ! empty( $lines ) || ! empty( $rules['block_php_cache'] ) ) && ( ! empty( $is_nginx ) || ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) ) {
			return new WP_Error( 'not_apache', __( 'These rules only work on Apache or LiteSpeed. This server runs Nginx or IIS, which ignore .htaccess files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! empty( $lines ) || $this->htaccess_has_tso_marker( $htaccess ) ) {
			// Keep the previous file: a rule the host does not allow (for example Options -Indexes) makes Apache answer 500 for the whole site.
			$existed = file_exists( $htaccess );
			$backup  = ( $existed && is_readable( $htaccess ) ) ? file_get_contents( $htaccess ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file.
			$was_ok  = ! empty( $lines ) ? $this->site_responds_ok() : true;

			if ( ! $existed && ! empty( $lines ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Creating empty .htaccess before marker write at WP root.
				if ( false === file_put_contents( $htaccess, '', LOCK_EX ) ) {
					return new WP_Error( 'htaccess_create', __( 'Could not create .htaccess at the WordPress root.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
				}
			}
			if ( file_exists( $htaccess ) || ! empty( $lines ) ) {
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Server config at WP root; same pattern as Server Files.
				$ok = insert_with_markers( $htaccess, self::HTACCESS_MARKER, $lines );
				if ( ! $ok ) {
					return new WP_Error( 'htaccess_write', __( 'Could not update the TSO block in .htaccess. Check file permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
				}
			}

			// If the site was fine before and answers with a server error now, put the previous file back.
			if ( ! empty( $lines ) && $was_ok && ! $this->site_responds_ok() ) {
				if ( false !== $backup ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Restoring the .htaccess backed up above.
					file_put_contents( $htaccess, $backup, LOCK_EX );
				} elseif ( ! $existed ) {
					wp_delete_file( $htaccess );
				}
				return new WP_Error( 'htaccess_500', __( 'The site returned a server error after the change, so the previous .htaccess was restored. Your host probably does not allow one of these rules (for example Options -Indexes).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$cache_result = $this->apply_cache_php_block( ! empty( $rules['block_php_cache'] ) );
		if ( is_wp_error( $cache_result ) ) {
			return $cache_result;
		}

		return true;
	}

	/**
	 * Whether the home page answers without a server error (5xx). A failed request is not proof of an error, so it counts as fine.
	 *
	 * @return bool
	 */
	private function site_responds_ok(): bool {
		$response = wp_remote_get(
			add_query_arg( 'tsosk_probe', (string) time(), home_url( '/' ) ),
			array(
				'timeout'     => 8,
				'redirection' => 3,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return true;
		}
		return (int) wp_remote_retrieve_response_code( $response ) < 500;
	}

	/**
	 * Whether the WP-root .htaccess already contains our marker block.
	 *
	 * @param string $path .htaccess path.
	 * @return bool
	 */
	private function htaccess_has_tso_marker( string $path ): bool {
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file.
		$src = (string) file_get_contents( $path );
		return false !== strpos( $src, '# BEGIN ' . self::HTACCESS_MARKER );
	}

	/**
	 * Lines for the marked root .htaccess hardening block.
	 *
	 * @param array<string,bool> $rules Rule flags.
	 * @return string[]
	 */
	private function build_htaccess_rule_lines( array $rules ): array {
		$lines = array();
		if ( ! empty( $rules['block_indexes'] ) ) {
			$lines[] = 'Options -Indexes';
		}
		if ( ! empty( $rules['deny_wp_config'] ) ) {
			$lines[] = '<Files wp-config.php>';
			$lines[] = "\t<IfModule mod_authz_core.c>";
			$lines[] = "\t\tRequire all denied";
			$lines[] = "\t</IfModule>";
			$lines[] = "\t<IfModule !mod_authz_core.c>";
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tDeny from all";
			$lines[] = "\t</IfModule>";
			$lines[] = '</Files>';
		}
		if ( ! empty( $rules['deny_htaccess'] ) ) {
			$lines[] = '<FilesMatch "^\\.ht">';
			$lines[] = "\t<IfModule mod_authz_core.c>";
			$lines[] = "\t\tRequire all denied";
			$lines[] = "\t</IfModule>";
			$lines[] = "\t<IfModule !mod_authz_core.c>";
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tDeny from all";
			$lines[] = "\t</IfModule>";
			$lines[] = '</FilesMatch>';
		}
		if ( ! empty( $rules['deny_sensitive'] ) ) {
			$lines[] = '<FilesMatch "(?i)^(.*\\.(sql|log|bak|old|orig|dist)|readme\\.html|license\\.txt|wp-config-sample\\.php)$">';
			$lines[] = "\t<IfModule mod_authz_core.c>";
			$lines[] = "\t\tRequire all denied";
			$lines[] = "\t</IfModule>";
			$lines[] = "\t<IfModule !mod_authz_core.c>";
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tDeny from all";
			$lines[] = "\t</IfModule>";
			$lines[] = '</FilesMatch>';
		}
		return $lines;
	}

	/**
	 * Write or clear the PHP-deny block in wp-content/cache/.htaccess.
	 *
	 * @param bool $enable Whether to enable the block.
	 * @return true|WP_Error
	 */
	private function apply_cache_php_block( bool $enable ) {
		$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache';
		if ( ! $enable ) {
			$path = trailingslashit( $cache_dir ) . '.htaccess';
			if ( is_readable( $path ) ) {
				if ( ! function_exists( 'insert_with_markers' ) ) {
					tsosk_require_wp_admin( 'includes/misc.php' );
				}
				if ( function_exists( 'insert_with_markers' ) ) {
					// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Clearing our marker inside wp-content/cache only.
					insert_with_markers( $path, self::CACHE_HTACCESS_MARKER, array() );
				}
			}
			return true;
		}

		if ( ! is_dir( $cache_dir ) ) {
			return new WP_Error(
				'no_cache_dir',
				__( 'wp-content/cache does not exist yet. Create it (or let a cache plugin create it) before enabling this rule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}
		if ( ! wp_is_writable( $cache_dir ) ) {
			return new WP_Error( 'cache_not_writable', __( 'wp-content/cache is not writable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$path = trailingslashit( $cache_dir ) . '.htaccess';
		if ( ! function_exists( 'insert_with_markers' ) ) {
			tsosk_require_wp_admin( 'includes/misc.php' );
		}
		$lines = array(
			'<FilesMatch "\\.php$">',
			"\t<IfModule mod_authz_core.c>",
			"\t\tRequire all denied",
			"\t</IfModule>",
			"\t<IfModule !mod_authz_core.c>",
			"\t\tOrder allow,deny",
			"\t\tDeny from all",
			"\t</IfModule>",
			'</FilesMatch>',
		);
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Hardening wp-content/cache only.
		$ok = insert_with_markers( $path, self::CACHE_HTACCESS_MARKER, $lines );
		if ( ! $ok ) {
			return new WP_Error( 'cache_htaccess', __( 'Could not write the PHP block into wp-content/cache/.htaccess.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		return true;
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
			&& (bool) constant( 'FORCE_SSL_ADMIN' );

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

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce             = wp_create_nonce( 'tsosk_security_nonce' );
		$checks            = $this->get_checks();
		$mu                = $this->get_mu_settings();
		$remote            = $this->get_remote_access_flags();
		$server_rules      = $this->get_server_rules();
		$security_plugins  = $this->get_active_security_plugins();
		$server_locked     = ! empty( $security_plugins );
		$alerts            = $this->get_alert_settings();
		$app_passwords     = $this->get_all_application_passwords();
		$uploads_test      = $this->get_uploads_test_result();
		$uploads_protected = $this->uploads_htaccess_has_protection();
		$cloned_admins     = $this->get_admins_with_cloned_registration_date();
		$mu_exists         = TSOSK_Config_Storage::json_exists( TSOSK_Config_Storage::SECURITY_JSON );
		$legacy_exists     = file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE );
		$login_url         = $this->tab_url( 'login-protect' );
		$users_url         = $this->tab_url( 'users' );
		$cas_url           = $this->tab_url( 'comment-antispam' );
		$server_files_url  = $this->tab_url( 'server-files' );

		$toggles = array(
			\x27DISALLOW_FILE_EDIT\x27 => array(
				'label' => 'DISALLOW_FILE_EDIT',
				'desc'  => __( 'Hides the built-in plugin and theme code editors from the WordPress admin (Appearance › Editor, Plugins › Editor). Strongly recommended on production sites to prevent accidental or malicious code injection via the dashboard.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Recommended: ON on all production sites.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'disallow_file_edit',
			),
			\x27DISALLOW_FILE_MODS\x27 => array(
				'label' => 'DISALLOW_FILE_MODS',
				'desc'  => __( 'Prevents any plugin or theme from being installed, updated or deleted from the WordPress admin. Also disables the WordPress auto-updater. Use on hardened servers where all deploys are done via version control. Note: this also blocks WordPress core auto-updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Optional. Only use if you manage updates via CI/CD or WP-CLI. Do not use if you rely on the admin for updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'disallow_file_mods',
			),
			'FORCE_SSL_ADMIN'          => array(
				'label' => 'FORCE_SSL_ADMIN',
				'desc'  => __( 'Forces the WordPress login page and admin area to always use HTTPS, even if the front end runs on HTTP. Only enable this if you have a valid SSL certificate. If you do not have SSL, this will lock you out of the admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'rec'   => __( 'Recommended: ON if you have SSL. Never enable without a working SSL certificate.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'key'   => 'force_ssl_admin',
			),
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Read-only security review plus safe toggles for WordPress hardening. Constants save as JSON under the plugin uploads folder. Apache server rules use a reversible marked block in .htaccess. This does not replace a firewall or malware scanner.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( $server_locked ) : ?>
		<div class="tsosk-notice tsosk-notice-warn" id="tsosk-security-suite-notice">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'A security plugin is active (%s). TSO keeps Apache server-rule writers soft-disabled to avoid overlapping .htaccess changes. You can still use WordPress-layer toggles below. Override only if you understand the risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $security_plugins )
				)
			);
			?>
		</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper tsosk-sec-tabs" role="tablist">
			<a href="#tsosk-sec-panel-overview" class="nav-tab tsosk-sec-tab nav-tab-active" data-tab="overview" role="tab" aria-selected="true"><?php esc_html_e( 'Overview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-sec-panel-hardening" class="nav-tab tsosk-sec-tab" data-tab="hardening" role="tab" aria-selected="false"><?php esc_html_e( 'Hardening WP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-sec-panel-server" class="nav-tab tsosk-sec-tab" data-tab="server" role="tab" aria-selected="false"><?php esc_html_e( 'Server rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-sec-panel-accounts" class="nav-tab tsosk-sec-tab" data-tab="accounts" role="tab" aria-selected="false"><?php esc_html_e( 'Accounts & keys', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-sec-panel-alerts" class="nav-tab tsosk-sec-tab" data-tab="alerts" role="tab" aria-selected="false"><?php esc_html_e( 'Alerts & App passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
		</h2>

		<div class="tsosk-sec-panel" id="tsosk-sec-panel-overview" role="tabpanel">
			<div class="tsosk-card" id="tsosk-security-checks">
				<h3><?php esc_html_e( 'Security Checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Read-only overview of common security settings. Use the other tabs to change toggles or server rules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				<div class="tsosk-table-wrap">
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
			</div>

			<div class="tsosk-card">
				<h3><?php esc_html_e( 'Related tools', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<p class="description">
					<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Login protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $cas_url ); ?>"><?php esc_html_e( 'Comment Anti-Spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $server_files_url ); ?>"><?php esc_html_e( 'Server Files (.htaccess / robots)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $users_url ); ?>"><?php esc_html_e( 'Users & Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Bot / WAF-style protection belongs in a dedicated security plugin (Kadence Security, Wordfence, …). TSO links to login rate limits and comment spam tools instead of duplicating a firewall.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>
		</div>

		<div class="tsosk-sec-panel" id="tsosk-sec-panel-hardening" role="tabpanel" hidden>
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
					<input type="checkbox" name="tsosk_security_disable_pingbacks" id="tsosk-sec-disable-pingbacks" value="1" <?php checked( $remote['disable_pingbacks'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Turn off pingbacks / trackbacks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Removes pingback XML-RPC methods and the X-Pingback header while leaving the rest of XML-RPC available for apps that need it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" name="tsosk_security_disable_feeds" id="tsosk-sec-disable-feeds" value="1" <?php checked( $remote['disable_feeds'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Disable RSS feeds', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'On: /feed/ and comment feeds redirect to the home page, so scrapers and feed readers cannot pull your posts that way. Off: keep normal RSS/Atom feeds (Feedly, aggregators, or email tools that subscribe to the site).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" name="tsosk_security_block_author_enum" id="tsosk-sec-block-author-enum" value="1" <?php checked( $remote['block_author_enum'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Block author scans', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Redirects public ?author=N probes and author archives for guests, and hides the users REST collection from anonymous callers. Disable if you rely on public author archives or membership directories that list users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<p class="description" style="margin-top:12px;">
					<?php esc_html_e( 'REST API access and namespace controls are configured in the REST API tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<a href="<?php echo esc_url( $this->tab_url( 'rest-api' ) ); ?>"><?php esc_html_e( 'Open REST API settings →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Login protection →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</p>
			</div>

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
		</div>

		<div class="tsosk-sec-panel" id="tsosk-sec-panel-server" role="tabpanel" hidden>
			<div class="tsosk-card" id="tsosk-security-uploads-php">
				<h3><?php esc_html_e( 'PHP Execution in Uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<p class="description"><?php esc_html_e( 'The single control that most often stops an arbitrary-file-upload attack from becoming a full takeover: even if a vulnerable plugin lets an attacker write a PHP file into wp-content/uploads, the file can never run if the server refuses to execute PHP there. This check only reads the rules in uploads/.htaccess; it does not write or run any file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

				<?php if ( null === $uploads_test ) : ?>
					<div class="tsosk-notice tsosk-notice-info" id="tsosk-sec-uploads-status">
						<?php esc_html_e( 'Not tested yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</div>
				<?php else : ?>
					<?php
					$status_class = 'executed' === $uploads_test['status'] ? 'tsosk-notice-warn' : ( 'blocked' === $uploads_test['status'] ? 'tsosk-notice-info' : 'tsosk-notice-info' );
					?>
					<div class="tsosk-notice <?php echo esc_attr( $status_class ); ?>" id="tsosk-sec-uploads-status">
						<?php
						if ( 'blocked' === $uploads_test['status'] ) :
							?>
							✓
							<?php
elseif ( 'executed' === $uploads_test['status'] ) :
	?>
							⚠ <?php endif; ?>
						<?php echo esc_html( $uploads_test['message'] ); ?>
						<?php if ( ! empty( $uploads_test['checked_at'] ) ) : ?>
							<br><span style="font-size:11px;color:#666;">
							<?php
							printf(
								/* translators: 1: date, 2: time */
								esc_html__( 'Last tested: %1$s at %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								esc_html( wp_date( 'Y-m-d', (int) $uploads_test['checked_at'] ) ),
								esc_html( wp_date( 'H:i', (int) $uploads_test['checked_at'] ) )
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

			<div class="tsosk-card" id="tsosk-security-server-rules">
				<h3><?php esc_html_e( 'Apache / LiteSpeed hardening', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Reversible rules written into a marked block in the WordPress-root .htaccess (and optionally wp-content/cache/.htaccess). Nginx/IIS ignore these files — use your host panel instead. Do not enable “forbid PHP in wp-includes”: WordPress must execute PHP there.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

				<label class="tsosk-toggle-row">
					<input type="checkbox" id="tsosk-sec-block-indexes" value="1" <?php checked( $server_rules['block_indexes'] ); ?> <?php disabled( $server_locked ); ?>>
					<span>
						<strong><?php esc_html_e( 'Block directory browsing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Adds Options -Indexes so visitors cannot list folder contents.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" id="tsosk-sec-deny-wp-config" value="1" <?php checked( $server_rules['deny_wp_config'] ); ?> <?php disabled( $server_locked ); ?>>
					<span>
						<strong><?php esc_html_e( 'Block HTTP access to wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Denies web requests to wp-config.php (PHP still reads the file normally).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" id="tsosk-sec-deny-htaccess" value="1" <?php checked( $server_rules['deny_htaccess'] ); ?> <?php disabled( $server_locked ); ?>>
					<span>
						<strong><?php esc_html_e( 'Block access to .htaccess and .htpasswd', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Denies HTTP download of .ht* files in this directory tree where the rule applies.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" id="tsosk-sec-deny-sensitive" value="1" <?php checked( $server_rules['deny_sensitive'] ); ?> <?php disabled( $server_locked ); ?>>
					<span>
						<strong><?php esc_html_e( 'Block access to sensitive files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Denies common leak targets: *.sql, *.log, *.bak, readme.html, license.txt, wp-config-sample.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<label class="tsosk-toggle-row">
					<input type="checkbox" id="tsosk-sec-block-php-cache" value="1" <?php checked( $server_rules['block_php_cache'] ); ?> <?php disabled( $server_locked ); ?>>
					<span>
						<strong><?php esc_html_e( 'Disable PHP execution in cache directories', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Writes a PHP deny rule into wp-content/cache/.htaccess when that folder exists.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>

				<?php if ( $server_locked ) : ?>
				<label class="tsosk-toggle-row" style="margin-top:12px;">
					<input type="checkbox" id="tsosk-sec-force-server-rules" value="1">
					<span>
						<strong><?php esc_html_e( 'Override: write TSO server rules anyway', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<span class="tsosk-hint"><?php esc_html_e( 'Only if you accept possible overlap with your security plugin’s .htaccess rules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</span>
				</label>
				<?php endif; ?>

				<p style="margin-top:12px;">
					<button class="button button-primary" id="tsosk-security-save-server-rules"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
							data-locked="<?php echo $server_locked ? '1' : '0'; ?>"
							data-save-label="<?php echo esc_attr__( 'Save Server Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
						<?php esc_html_e( 'Save Server Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-security-server-rules-msg"></span>
				</p>
				<p class="description">
					<a href="<?php echo esc_url( $server_files_url ); ?>"><?php esc_html_e( 'Open Server Files to review the full .htaccess →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</p>
			</div>
		</div>

		<div class="tsosk-sec-panel" id="tsosk-sec-panel-accounts" role="tabpanel" hidden>
			<div class="tsosk-card" id="tsosk-security-accounts-keys">
				<h3><?php esc_html_e( 'Accounts & keys', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<p class="description"><?php esc_html_e( 'These items are audited here but never changed automatically — renaming the admin user or the database table prefix can lock you out or break the site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

				<?php
				$admin_user    = get_user_by( 'login', 'admin' );
				$table_prefix  = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : 'wp_';
				$default_salts = array( 'put your unique phrase here', 'put your unique phrase here' );
				$salt_keys     = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
				$weak_salts    = 0;
				$missing_salts = 0;
				foreach ( $salt_keys as $sk ) {
					if ( ! defined( $sk ) ) {
						++$missing_salts;
						continue;
					}
					$val = (string) constant( $sk );
					if ( '' === $val || in_array( $val, $default_salts, true ) || strlen( $val ) < 32 ) {
						++$weak_salts;
					}
				}
				?>

				<table class="widefat tsosk-table">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Default administrator username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></td>
							<td>
								<?php if ( $admin_user ) : ?>
									<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php esc_html_e( 'A user named “admin” exists. Create a new administrator with a unique login, then delete or demote “admin”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									<a href="<?php echo esc_url( $users_url ); ?>"><?php esc_html_e( 'Open Users & Sessions →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php esc_html_e( 'No user named admin found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Database table prefix', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></td>
							<td>
								<?php if ( 'wp_' === $table_prefix ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: table prefix */
											__( 'Prefix is “%s” (WordPress default). Changing it requires a careful database migration — do it with a dedicated tool or host backup, never from this panel.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$table_prefix
										)
									);
									?>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: table prefix */
											__( 'Prefix is “%s” (not the default wp_).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$table_prefix
										)
									);
									?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Security keys & salts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></td>
							<td>
								<?php if ( $missing_salts > 0 || $weak_salts > 0 ) : ?>
									<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: missing count, 2: weak count */
											__( '%1$d key(s) missing and %2$d look weak or still use the WordPress placeholder. Generate new keys and paste them into wp-config.php (TSO never writes that file).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$missing_salts,
											$weak_salts
										)
									);
									?>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php esc_html_e( 'All eight AUTH/SECURE/LOGGED_IN/NONCE keys and salts are defined with non-placeholder values.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php endif; ?>
								<p class="description" style="margin:8px 0 4px;">
									<?php esc_html_e( 'The generator link opens a plain-text page of PHP define() lines (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY and the matching *_SALT values). Copy that whole block into wp-config.php, replacing the old keys/salts (or the “put your unique phrase here” placeholders). TSO never writes wp-config.php — use FTP/SFTP or your host file manager. After you save, existing cookies become invalid and everyone (including you) must log in again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</p>
								<ol class="description" style="margin:0 0 8px 1.25em;list-style:decimal;">
									<li><?php esc_html_e( 'Open the generator and select all of the define(…) lines.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
									<li><?php esc_html_e( 'Open wp-config.php on the server and find the existing keys/salts block.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
									<li><?php esc_html_e( 'Replace that block with the new lines, save the file, then log in again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
								</ol>
								<a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the official WordPress salt generator →', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="tsosk-sec-panel" id="tsosk-sec-panel-alerts" role="tabpanel" hidden>
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
					<div class="tsosk-table-wrap">
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
					</div>
					<p style="margin-top:12px;">
						<button class="button" id="tsosk-security-revoke-all-app-passwords" data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Revoke All Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
						<span class="tsosk-ajax-msg" id="tsosk-security-app-passwords-msg"></span>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $cloned_admins ) ) : ?>
			<div class="tsosk-card" id="tsosk-security-shared-admins">
				<h3><?php esc_html_e( 'Administrators with a Shared Creation Date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<div class="tsosk-notice tsosk-notice-warn">
					<?php esc_html_e( 'Some malware hides a rogue administrator account by copying another existing user\'s exact registration timestamp, so "sort users by newest" never reveals it. These administrator accounts share their creation timestamp, down to the second, with at least one other user on this site — review each one.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
				<div class="tsosk-table-wrap">
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
			</div>
			<?php endif; ?>
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
		// Each entry: check label => [ tab, anchor, stable ignore id ].
		$targets = array(
			__( 'File editor disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-constants', 'file_editor' ),
			__( 'Force SSL admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )          => array( 'security', 'tsosk-security-constants', 'force_ssl_admin' ),
			__( 'XML-RPC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )                  => array( 'security', 'tsosk-security-remote-access', 'xmlrpc' ),
			__( 'Author enumeration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )       => array( 'security', 'tsosk-security-remote-access', 'author_enum' ),
			__( 'Default admin username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )   => array( 'security', 'tsosk-security-accounts-keys', 'default_admin_user' ),
			__( 'Security keys & salts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-accounts-keys', 'salts' ),
			__( 'Plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )           => array( 'update-manager', 'tsosk-um-plugins', 'plugin_updates' ),
			__( 'Theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )            => array( 'update-manager', 'tsosk-um-status', 'theme_updates' ),
			__( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-app-passwords', 'app_passwords' ),
			__( 'Recent administrator accounts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'users', 'tsosk-users-summary', 'recent_admins' ),
			__( 'Administrator accounts with a shared creation date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-shared-admins', 'shared_admin_dates' ),
			__( 'PHP execution in uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-uploads-php', 'uploads_php' ),
			__( '.htaccess permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )    => array( 'security', 'tsosk-security-checks', 'htaccess_perms' ),
			__( 'wp-config.php permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => array( 'security', 'tsosk-security-checks', 'wpconfig_perms' ),
		);
		foreach ( $checks as $i => $check ) {
			$label = (string) ( $check['label'] ?? '' );
			if ( isset( $targets[ $label ] ) ) {
				$checks[ $i ]['tab']    = $targets[ $label ][0];
				$checks[ $i ]['anchor'] = $targets[ $label ][1];
				$checks[ $i ]['id']     = $targets[ $label ][2];
			}
		}
		return $checks;
	}

	/** Build security checks. */
	private function get_checks(): array {
		$admin_user = get_user_by( 'login', 'admin' );
		$wp_config  = $this->find_wp_config();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- checking WP core filter
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true );
		$plugin_updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$theme_updates  = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();
		// While the Update Manager restricts update checks the lists above are always empty, which is not a real "no updates".
		$updates_restricted = class_exists( 'TSOSK_Mod_Update_Manager' ) && TSOSK_Mod_Update_Manager::is_restricting_updates();
		$app_passwords      = $this->get_all_application_passwords();
		$recent_admins      = $this->get_recent_admin_registrations( 7 );
		$uploads_test       = $this->get_uploads_test_result();
		$cloned_admins      = $this->get_admins_with_cloned_registration_date();

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

		$plugin_updates_check = $updates_restricted
			? array(
				'label'   => __( 'Plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => __( 'Update checks are restricted in the Update Manager, so pending updates cannot be detected here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			)
			: $this->check_item( __( 'Plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $plugin_updates ), __( 'No plugin updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), sprintf( /* translators: %d: number of plugin updates */ __( '%d plugin updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $plugin_updates ) ) );

		$theme_updates_check = $updates_restricted
			? array(
				'label'   => __( 'Theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => __( 'Update checks are restricted in the Update Manager, so pending updates cannot be detected here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			)
			: $this->check_item( __( 'Theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $theme_updates ), __( 'No theme updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), sprintf( /* translators: %d: number of theme updates */ __( '%d theme updates detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $theme_updates ) ) );

		// Live constant when already defined; otherwise the JSON flag (so Overview matches a save before the next full bootstrap).
		$stored_flags = TSOSK_Config_Storage::get_security_flags()['constants'];
		$file_edit_ok = defined( 'DISALLOW_FILE_EDIT' )
			? (bool) DISALLOW_FILE_EDIT
			: ! empty( $stored_flags['DISALLOW_FILE_EDIT'] );
		$force_ssl_ok = is_ssl()
			|| ( defined( 'FORCE_SSL_ADMIN' ) ? (bool) constant( 'FORCE_SSL_ADMIN' ) : ! empty( $stored_flags['FORCE_SSL_ADMIN'] ) );

		$remote_flags   = $this->get_remote_access_flags();
		$author_enum_ok = ! empty( $remote_flags['block_author_enum'] );
		$salt_keys      = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$salts_ok       = true;
		foreach ( $salt_keys as $sk ) {
			if ( ! defined( $sk ) ) {
				$salts_ok = false;
				break;
			}
			$val = (string) constant( $sk );
			if ( '' === $val || 'put your unique phrase here' === $val || strlen( $val ) < 32 ) {
				$salts_ok = false;
				break;
			}
		}

		return array(
			$this->check_item( __( 'File editor disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $file_edit_ok, __( 'DISALLOW_FILE_EDIT is enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'Enable DISALLOW_FILE_EDIT to hide plugin/theme editors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$this->check_item( __( 'Force SSL admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $force_ssl_ok, __( 'Admin is using SSL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'Admin may not be forced through SSL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$xmlrpc_check,
			$this->check_item( __( 'Author enumeration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $author_enum_ok, __( 'Public author probes and anonymous users REST listing are blocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'Author scans are not blocked. Enable “Block author scans” under Hardening WP if you do not need public author archives.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'info' ),
			$this->check_item( __( 'Default admin username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), ! $admin_user, __( 'No user named admin found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'A user named admin exists. Rename it to a non-obvious username.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$this->check_item( __( 'Security keys & salts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $salts_ok, __( 'Authentication keys and salts look unique.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), __( 'One or more security keys/salts are missing, short, or still use the WordPress placeholder. Update them in wp-config.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			$plugin_updates_check,
			$theme_updates_check,
			$this->check_item( __( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $app_passwords ), __( 'No Application Passwords are active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), sprintf( /* translators: %d: number of active Application Passwords */ __( '%d Application Password(s) active — review them below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $app_passwords ) ), 'info' ),
			$this->check_item( __( 'Recent administrator accounts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $recent_admins ), __( 'No new administrators in the last 7 days.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), sprintf( /* translators: %d: number of administrators created in the last 7 days */ __( '%d administrator account(s) created in the last 7 days — make sure you recognize them.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $recent_admins ) ) ),
			$this->check_item( __( 'Administrator accounts with a shared creation date', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), empty( $cloned_admins ), __( 'No administrator shares its exact creation timestamp with another user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), sprintf( /* translators: %d: number of administrators sharing a registration timestamp with another user */ __( '%d administrator account(s) share their exact creation timestamp with another user — a known trick to hide a rogue admin from "sort by newest". Review them below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), count( $cloned_admins ) ) ),
			$uploads_check,
			$this->file_permission_check( __( '.htaccess permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), tsosk_join_wp_root( '.htaccess' ), 'htaccess' ),
			$this->file_permission_check( __( 'wp-config.php permissions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $wp_config ? $wp_config : tsosk_join_wp_root( 'wp-config.php' ), 'wp-config' ),
		);
	}

	/**
	 * Check item.
	 *
	 * @param string $label Label.
	 * @param bool   $ok Ok.
	 * @param string $ok_details Ok details.
	 * @param string $warn_details Warn details.
	 * @param string $warn_badge Warn badge.
	 * @return array
	 */
	private function check_item( string $label, bool $ok, string $ok_details, string $warn_details, string $warn_badge = 'warn' ): array {
		return array(
			'label'   => $label,
			'status'  => $ok ? __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $ok ? 'tsosk-badge-ok' : 'tsosk-badge-' . $warn_badge,
			'details' => $ok ? $ok_details : $warn_details,
		);
	}

	/**
	 * File permission check.
	 *
	 * Flags only real exposure: group/world-writable files, and a world-readable wp-config.php
	 * (advisory). A file that PHP itself can write (e.g. 0644 owned by the PHP user) is normal on
	 * most hosts and is not reported as a problem.
	 *
	 * @param string $label Label.
	 * @param string $path  Path.
	 * @param string $kind  'htaccess' or 'wp-config'.
	 * @return array
	 */
	private function file_permission_check( string $label, string $path, string $kind = 'htaccess' ): array {
		if ( ! $path || ! file_exists( $path ) ) {
			return array(
				'label'   => $label,
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => __( 'File not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		$mode        = fileperms( $path ) & 0777;
		$perms       = substr( sprintf( '%o', $mode ), -4 );
		$perms       = str_pad( $perms, 4, '0', STR_PAD_LEFT );
		$loose_write = (bool) ( $mode & 0022 );
		$world_read  = (bool) ( $mode & 0004 );
		$is_config   = ( 'wp-config' === $kind );
		$prefix      = sprintf( /* translators: %s: file permission octal */ __( 'Permissions: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $perms );

		if ( $loose_write ) {
			$text = $is_config
				? __( 'Other users or accounts on the server can modify this file. Whoever edits it can change the database connection or inject code into the whole site. Recommended: 0640 (or 0600 if PHP runs as the file owner). Never 0666 or 0777. Nothing in WordPress needs to write to this file, so tightening it does not block updates or plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Other users or accounts on the server can modify this file. Whoever edits it can add redirects or rewrite rules to your site. Recommended: 0644. Do not go stricter than 0444: WordPress and some plugins (permalinks, cache, security) need to write to it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return array(
				'label'   => $label,
				'status'  => __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-warn',
				'details' => $prefix . ' ' . $text,
			);
		}

		if ( $is_config && $world_read ) {
			return array(
				'label'   => $label,
				'status'  => __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-info',
				'details' => $prefix . ' ' . __( 'Optional hardening: the file is readable by every account on the server, which on shared hosting can expose the database password. Recommended: 0640 or 0600. WordPress, updates and plugins keep working because none of them write to wp-config.php. After changing it, reload the site: if you see a blank page or a database connection error, set it back to 0644 (PHP runs as a different user than the file owner).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		$text = $is_config
			? __( 'Good: only the owner and group can read it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: __( 'Good: this is the recommended value. Only the owner can modify it, and WordPress can still update permalink rules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( ! $is_config && ! wp_is_writable( $path ) ) {
			$text = __( 'The file is read-only: it is safe, but WordPress and some plugins cannot save permalink or cache rules into it. Use 0644 if you need that.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return array(
			'label'   => $label,
			'status'  => __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => 'tsosk-badge-ok',
			'details' => $prefix . ' ' . $text,
		);
	}

	/**
	 * Find wp config.
	 *
	 * @return string
	 */
	private function find_wp_config(): string {
		return tsosk_locate_wp_config_path();
	}
}
