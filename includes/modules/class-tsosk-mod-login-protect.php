<?php
/**
 * TSO Swiss Knife – Module: Login Protection.
 *
 * Provides three independent hardening layers for wp-login.php:
 *
 *  1. Custom login URL — serves wp-login.php only on a secret slug;
 *     any direct request to /wp-login.php or /wp-admin/ while logged out
 *     gets a 404 instead of the login form.
 *
 *  2. Brute-force lockout — counts consecutive failed login attempts per IP
 *     and blocks further attempts for a configurable duration.
 *
 *  2b. Forbidden username block — instantly locks out IPs that try common
 *      bot usernames (admin, administrador, administrator).
 *
 *  3. Whitelist bypass — one or more trusted IPs always pass through,
 *     regardless of the custom URL or lockout state.
 *
 * All state is stored in wp_options (no extra DB tables).
 * The custom URL rewrite is implemented via a query-var + template_redirect
 * hook so it works on any server (Apache, Nginx, IIS) without touching
 * .htaccess or wp-config.php.
 *
 * @package TSO_Swiss_Knife
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Login_Protect
 */
class TSOSK_Mod_Login_Protect {

	/** Settings option key. */
	private const OPTION_SETTINGS = 'tsosk_login_protect';

	/** Lockout log option key (array of lockout entries). */
	private const OPTION_LOCKOUTS = 'tsosk_login_lockouts';

	/** Attempt counter option key (array: ip => ['count'=>N,'first'=>timestamp]). */
	private const OPTION_ATTEMPTS = 'tsosk_login_attempts';

	/** Query var name used for the custom login URL detection. */
	private const QUERY_VAR = 'tsosk_login_key';

	/** Maximum lockout log entries kept in the option. */
	private const MAX_LOG = 200;

	/** Usernames commonly targeted by bots (case-insensitive). */
	private const FORBIDDEN_USERNAMES = array( 'admin', 'administrador', 'administrator' );

	/** @var TSOSK_Mod_Login_Protect|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin AJAX handlers.
		add_action( 'wp_ajax_tsosk_lp_save',          array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_lp_unlock',         array( $this, 'ajax_unlock' ) );
		add_action( 'wp_ajax_tsosk_lp_unlock_all',     array( $this, 'ajax_unlock_all' ) );
		add_action( 'wp_ajax_tsosk_lp_clear_log',      array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_lp_generate_slug',  array( $this, 'ajax_generate_slug' ) );
	}

	// ── Early init (called from tsosk_init) ──────────────────────────────────

	/**
	 * Apply login protection hooks. Runs on plugins_loaded.
	 */
	public function init(): void {
		$s = $this->get_settings();

		$lockout_enforcement = ( $s['brute_force'] && $s['max_attempts'] > 0 ) || $s['block_forbidden_usernames'];

		// ── Brute-force protection ────────────────────────────────────────
		if ( $lockout_enforcement ) {
			add_filter( 'authenticate', array( $this, 'check_lockout_authenticate' ), 1, 3 );
		}

		if ( $s['brute_force'] && $s['max_attempts'] > 0 ) {
			add_filter( 'wp_authenticate_user', array( $this, 'check_lockout' ), 1, 1 );
			add_action( 'wp_login_failed', array( $this, 'record_failed_attempt' ), 10, 2 );
			add_action( 'wp_login', array( $this, 'reset_attempts' ), 10, 1 );
		}

		if ( $s['block_forbidden_usernames'] ) {
			add_filter( 'authenticate', array( $this, 'block_forbidden_username' ), 19, 3 );
		}

		// Login maintenance mode (trusted IPs only).
		if ( ! empty( $s['login_maintenance'] ) ) {
			add_action( 'login_init', array( $this, 'enforce_login_maintenance' ), 0 );
		}

		// Email verification code for selected roles.
		if ( ! empty( $s['email_2fa'] ) && ! empty( $s['email_2fa_roles'] ) ) {
			add_filter( 'wp_authenticate_user', array( $this, 'verify_email_2fa' ), 50, 1 );
			add_action( 'login_form', array( $this, 'render_2fa_field' ) );
			add_filter( 'login_errors', array( $this, 'filter_2fa_login_errors' ) );
		}

		// Per-role IP restrictions after password is valid.
		if ( '' !== trim( $s['role_whitelist_ips'] ) ) {
			add_filter( 'wp_authenticate_user', array( $this, 'check_role_ip_whitelist' ), 40, 1 );
		}

		// ── Custom login URL ──────────────────────────────────────────────
		if ( $s['custom_url'] && $s['login_slug'] ) {
			// Register query var so WP doesn't strip it.
			add_filter( 'query_vars', array( $this, 'add_query_var' ) );
			// Rewrite: /{slug}/ → index.php?tsosk_login_key={slug}
			add_action( 'init', array( $this, 'add_rewrite_rule' ) );
			// Intercept the request.
			add_action( 'template_redirect', array( $this, 'handle_custom_login_url' ), 1 );
			// Block direct wp-login.php access (returns 404).
			add_action( 'login_init', array( $this, 'block_direct_login_access' ), 1 );
			// Rewrite generated login links so forms and emails use the secret slug.
			add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
			add_filter( 'site_url', array( $this, 'filter_site_url_for_login' ), 10, 4 );
			add_filter( 'network_site_url', array( $this, 'filter_site_url_for_login' ), 10, 4 );
		}
	}

	// ── Custom URL hooks ─────────────────────────────────────────────────────

	/**
	 * Register our query var with WordPress.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Add rewrite rule for the custom login slug.
	 * Fires on init – flush_rewrite_rules() is called when slug changes.
	 */
	public function add_rewrite_rule(): void {
		$slug = $this->get_settings()['login_slug'];
		if ( $slug ) {
			add_rewrite_rule(
				'^' . preg_quote( $slug, '#' ) . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . $slug,
				'top'
			);
		}
	}

	/**
	 * When the custom slug is detected, load wp-login.php.
	 * On any other front-end page, if wp-login.php would have been reached
	 * via the normal URL, redirect to 404.
	 */
	public function handle_custom_login_url(): void {
		$detected = get_query_var( self::QUERY_VAR );
		$slug     = $this->get_settings()['login_slug'];

		if ( $detected && $detected === $slug ) {
			// Correct secret slug — serve the login page.
			// Prevent browsers and proxies from caching the login page.
			nocache_headers();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	/**
	 * Block direct wp-login.php requests for IPs not in the whitelist.
	 * Fires on login_init (inside wp-login.php bootstrap).
	 */
	public function block_direct_login_access(): void {
		$s = $this->get_settings();

		// Always allow whitelisted IPs.
		if ( $this->is_ip_whitelisted( $this->get_client_ip(), $s['whitelist_ips'] ) ) {
			return;
		}

		// Allow login POST only when custom URL is off, or when the request uses the secret slug.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			if ( ! $s['custom_url'] || $this->request_uses_login_slug( $s['login_slug'] ) ) {
				return;
			}
		}

		// Allow if the request arrived via the secret slug.
		if ( $this->request_uses_login_slug( $s['login_slug'] ) ) {
			return;
		}

		// Block: return a clean 404.
		wp_die(
			esc_html__( 'Page not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			esc_html__( 'Not Found', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array( 'response' => 404 )
		);
	}

	/**
	 * Replace wp-login.php URLs with the custom login slug.
	 *
	 * @param string $login_url  Default login URL.
	 * @param string $redirect   Redirect target.
	 * @param bool   $force_reauth Force re-authentication.
	 * @return string
	 */
	public function filter_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		$s = $this->get_settings();
		if ( ! $s['custom_url'] || ! $s['login_slug'] ) {
			return $login_url;
		}

		$url = home_url( '/' . $s['login_slug'] . '/' );
		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}

		return $url;
	}

	/**
	 * Rewrite wp-login.php paths inside site_url / network_site_url.
	 *
	 * @param string      $url     Generated URL.
	 * @param string      $path    Requested path.
	 * @param string|null $scheme  URL scheme.
	 * @param int|null    $blog_id Blog ID.
	 * @return string
	 */
	public function filter_site_url_for_login( string $url, string $path, $scheme, $blog_id ): string {
		unset( $blog_id );

		$s = $this->get_settings();
		if ( ! $s['custom_url'] || ! $s['login_slug'] || false === strpos( $path, 'wp-login.php' ) ) {
			return $url;
		}

		$custom = home_url( '/' . $s['login_slug'] . '/', $scheme );
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $args );
			if ( is_array( $args ) && ! empty( $args ) ) {
				$custom = add_query_arg( $args, $custom );
			}
		}

		return $custom;
	}

	/**
	 * Whether the current request path matches the custom login slug.
	 *
	 * @param string $slug Login slug.
	 * @return bool
	 */
	private function request_uses_login_slug( string $slug ): bool {
		$slug = trim( $slug, '/' );
		if ( '' === $slug ) {
			return false;
		}

		return $this->get_request_path() === $slug;
	}

	/**
	 * Get the current request path relative to the site home path.
	 *
	 * @return string
	 */
	private function get_request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );
		if ( '' !== $home_path && '/' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		return trim( $path, '/' );
	}

	// ── Brute-force hooks ────────────────────────────────────────────────────

	/**
	 * Fires on wp_authenticate_user — return WP_Error if IP is locked out.
	 *
	 * @param WP_User|WP_Error $user User object or error.
	 * @return WP_User|WP_Error
	 */
	public function check_lockout( $user ) {
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$error = $this->get_lockout_error_for_ip( $this->get_client_ip() );
		return $error ?? $user;
	}

	/**
	 * Enforce active IP lockouts before username/password authentication runs.
	 *
	 * @param WP_User|WP_Error|null $user     User or error from a prior authenticate filter.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function check_lockout_authenticate( $user, string $username, string $password ) {
		unset( $username, $password );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$error = $this->get_lockout_error_for_ip( $this->get_client_ip() );
		return $error ?? $user;
	}

	/**
	 * Build a lockout WP_Error for an IP, or null when the IP is not locked out.
	 *
	 * @param string $ip Client IP.
	 * @return WP_Error|null
	 */
	private function get_lockout_error_for_ip( string $ip ): ?WP_Error {
		$s = $this->get_settings();

		if ( $this->is_ip_whitelisted( $ip, $s['whitelist_ips'] ) ) {
			return null;
		}

		$lockout = $this->get_active_lockout( $ip );
		if ( ! $lockout ) {
			return null;
		}

		$remaining = (int) $lockout['until'] - time();
		$minutes   = (int) ceil( $remaining / 60 );

		return new WP_Error(
			'tsosk_locked_out',
			sprintf(
				/* translators: 1: IP address, 2: minutes remaining */
				__( 'Too many failed login attempts from %1$s. Please try again in %2$d minute(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				esc_html( $ip ),
				$minutes
			)
		);
	}

	/**
	 * Block login attempts that use common bot usernames and lock the IP immediately.
	 *
	 * @param WP_User|WP_Error|null $user     User or error from a prior authenticate filter.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function block_forbidden_username( $user, string $username, string $password ) {
		unset( $password );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! $this->is_forbidden_username( $username ) ) {
			return $user;
		}

		$s  = $this->get_settings();
		$ip = $this->get_client_ip();

		if ( $this->is_ip_whitelisted( $ip, $s['whitelist_ips'] ) ) {
			return $user;
		}

		$this->apply_ip_lockout( $ip, $username, 1 );

		return new WP_Error(
			'tsosk_forbidden_username',
			__( 'Too many failed login attempts. Please try again later.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * Whether the submitted username matches a forbidden bot target.
	 *
	 * @param string $username Raw username from the login form.
	 * @return bool
	 */
	private function is_forbidden_username( string $username ): bool {
		$normalized = strtolower( trim( $username ) );
		return '' !== $normalized && in_array( $normalized, self::FORBIDDEN_USERNAMES, true );
	}

	/**
	 * Fires on wp_login_failed — increment counter, lock if threshold reached.
	 *
	 * @param string        $username Username attempted.
	 * @param WP_Error|null $error    Error object when available.
	 */
	public function record_failed_attempt( string $username, $error = null ): void {
		if ( $error instanceof WP_Error ) {
			$skip = array( 'tsosk_2fa_required', 'tsosk_role_ip_denied', 'tsosk_locked_out', 'tsosk_forbidden_username' );
			if ( in_array( $error->get_error_code(), $skip, true ) ) {
				return;
			}
		}
		if ( $this->is_forbidden_username( $username ) ) {
			return;
		}

		$s   = $this->get_settings();
		$ip  = $this->get_client_ip();

		if ( $this->is_ip_whitelisted( $ip, $s['whitelist_ips'] ) ) {
			return;
		}

		$attempts = $this->get_all_attempts();
		$now      = time();
		$window   = (int) $s['lockout_window'] * 60; // window in seconds.

		if ( isset( $attempts[ $ip ] ) ) {
			$entry = $attempts[ $ip ];
			// Reset counter if the window has expired.
			if ( $now - (int) $entry['first'] > $window ) {
				$entry = array( 'count' => 0, 'first' => $now );
			}
			$entry['count']++;
		} else {
			$entry = array( 'count' => 1, 'first' => $now );
		}

		$attempts[ $ip ] = $entry;

		// Lock out if over threshold.
		if ( $entry['count'] >= (int) $s['max_attempts'] ) {
			$this->apply_ip_lockout( $ip, $username, (int) $entry['count'] );
			unset( $attempts[ $ip ] );
		}

		update_option( self::OPTION_ATTEMPTS, $attempts, false );
	}

	/**
	 * Block login page during maintenance except for trusted IPs.
	 */
	public function enforce_login_maintenance(): void {
		$s  = $this->get_settings();
		$ip = $this->get_client_ip();

		if ( $this->is_ip_whitelisted( $ip, $s['whitelist_ips'] ) ) {
			return;
		}
		if ( $this->is_ip_whitelisted( $ip, $s['login_maintenance_ips'] ) ) {
			return;
		}

		wp_die(
			esc_html__( 'Login is temporarily restricted to trusted IP addresses.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			esc_html__( 'Login maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array( 'response' => 503 )
		);
	}

	/**
	 * Require email verification code for configured roles.
	 *
	 * @param WP_User|WP_Error $user User or error.
	 * @return WP_User|WP_Error
	 */
	public function verify_email_2fa( $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		$s = $this->get_settings();
		if ( empty( $s['email_2fa'] ) || empty( $s['email_2fa_roles'] ) ) {
			return $user;
		}

		$matched = array_intersect( $user->roles, $s['email_2fa_roles'] );
		if ( empty( $matched ) ) {
			return $user;
		}

		$submitted = isset( $_POST['tsosk_2fa_code'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['tsosk_2fa_code'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$key       = 'tsosk_2fa_' . $user->ID;
		$stored    = get_transient( $key );

		if ( '' === $submitted ) {
			if ( ! $stored ) {
				$code = (string) random_int( 100000, 999999 );
				set_transient( $key, $code, 10 * MINUTE_IN_SECONDS );
				wp_mail(
					$user->user_email,
					sprintf(
						/* translators: %s: site name */
						__( '[%s] Login verification code', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						get_bloginfo( 'name' )
					),
					sprintf(
						/* translators: 1: code, 2: minutes */
						__( "Your login verification code is: %1\$s\n\nIt expires in %2\$d minutes.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$code,
						10
					)
				);
			}
			return new WP_Error(
				'tsosk_2fa_required',
				__( 'A verification code was sent to your email. Enter it below and try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		if ( ! is_string( $stored ) || ! hash_equals( $stored, $submitted ) ) {
			return new WP_Error(
				'tsosk_2fa_invalid',
				__( 'Invalid or expired verification code.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		delete_transient( $key );
		return $user;
	}

	/**
	 * Show verification code field on the login form.
	 */
	public function render_2fa_field(): void {
		echo '<p><label for="tsosk_2fa_code">' . esc_html__( 'Email verification code', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '<br>';
		echo '<input type="text" name="tsosk_2fa_code" id="tsosk_2fa_code" class="input" value="" size="8" autocomplete="one-time-code"></label></p>';
	}

	/**
	 * Keep 2FA prompts visible after failed login.
	 *
	 * @param string $errors Login error HTML.
	 * @return string
	 */
	public function filter_2fa_login_errors( string $errors ): string {
		if ( isset( $_GET['tsosk_2fa'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$errors .= '<p>' . esc_html__( 'Enter the verification code sent to your email.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
		}
		return $errors;
	}

	/**
	 * Enforce per-role IP whitelist after credentials are valid.
	 *
	 * @param WP_User|WP_Error $user User or error.
	 * @return WP_User|WP_Error
	 */
	public function check_role_ip_whitelist( $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		$s  = $this->get_settings();
		$ip = $this->get_client_ip();
		if ( $this->is_ip_whitelisted( $ip, $s['whitelist_ips'] ) ) {
			return $user;
		}

		$rules = $this->parse_role_ip_rules( $s['role_whitelist_ips'] );
		$roles_with_rules = array_values( array_intersect( $user->roles, array_keys( $rules ) ) );
		if ( empty( $roles_with_rules ) ) {
			return $user;
		}
		foreach ( $roles_with_rules as $role ) {
			if ( in_array( $ip, $rules[ $role ], true ) ) {
				return $user;
			}
		}

		return new WP_Error(
			'tsosk_role_ip_denied',
			__( 'Login from this IP address is not allowed for your role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * Parse role:ip1,ip2 lines into an array.
	 *
	 * @param string $raw Raw textarea.
	 * @return array<string,string[]>
	 */
	private function parse_role_ip_rules( string $raw ): array {
		$out   = array();
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $role, $ips ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$role = sanitize_key( $role );
			if ( '' === $role ) {
				continue;
			}
			$ip_list = array_filter(
				array_map(
					static function ( $ip ) {
						$ip = trim( (string) $ip );
						return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
					},
					explode( ',', $ips )
				)
			);
			if ( ! empty( $ip_list ) ) {
				$out[ $role ] = array_values( array_unique( $ip_list ) );
			}
		}
		return $out;
	}

	/**
	 * Lock an IP for the configured duration and optionally notify the admin.
	 *
	 * @param string $ip       Client IP.
	 * @param string $username Username attempted.
	 * @param int    $count    Failed attempts recorded for this lockout.
	 */
	private function apply_ip_lockout( string $ip, string $username, int $count ): void {
		$s        = $this->get_settings();
		$now      = time();
		$duration = (int) $s['lockout_duration'] * 60;
		$lockouts = $this->get_lockout_log();

		// Deactivate older active entries for the same IP so unlock works reliably.
		foreach ( $lockouts as &$entry ) {
			if ( (string) ( $entry['ip'] ?? '' ) === $ip && ! empty( $entry['active'] ) ) {
				$entry['active'] = false;
			}
		}
		unset( $entry );

		$lockouts[] = array(
			'ip'        => $ip,
			'username'  => $username,
			'count'     => $count,
			'locked_at' => $now,
			'until'     => $now + $duration,
			'active'    => true,
		);

		if ( count( $lockouts ) > self::MAX_LOG ) {
			$lockouts = array_slice( $lockouts, - self::MAX_LOG );
		}

		update_option( self::OPTION_LOCKOUTS, $lockouts, false );

		if ( ! empty( $s['notify_email'] ) ) {
			$this->send_lockout_notification( $ip, $username, $count );
		}

		$this->maybe_send_mass_lockout_alert();
	}

	/**
	 * Send a summary email when many lockouts occur in a short window.
	 */
	private function maybe_send_mass_lockout_alert(): void {
		$s = $this->get_settings();
		if ( empty( $s['notify_email'] ) || $s['notify_mass_threshold'] < 2 ) {
			return;
		}

		$window  = (int) $s['notify_mass_window'] * MINUTE_IN_SECONDS;
		$cutoff  = time() - $window;
		$recent  = 0;
		foreach ( $this->get_lockout_log() as $entry ) {
			if ( empty( $entry['active'] ) ) {
				continue;
			}
			if ( (int) ( $entry['locked_at'] ?? 0 ) >= $cutoff ) {
				$recent++;
			}
		}
		if ( $recent < (int) $s['notify_mass_threshold'] ) {
			return;
		}

		$flag_key = 'tsosk_mass_lockout_' . gmdate( 'YmdHi' );
		if ( get_transient( $flag_key ) ) {
			return;
		}
		set_transient( $flag_key, 1, $window );

		$to = $s['notify_address'] ?: get_option( 'admin_email' );
		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] Mass login lockout alert', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: count, 2: minutes */
				__( "%1\$d IP lockouts occurred in the last %2\$d minutes. Review Login Protection in TSO Swiss Knife.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$recent,
				(int) $s['notify_mass_window']
			)
		);
	}

	/**
	 * Fires on wp_login (successful) — clear the attempt counter for this IP.
	 *
	 * @param string $user_login Username.
	 */
	public function reset_attempts( string $user_login ): void {
		$ip       = $this->get_client_ip();
		$attempts = $this->get_all_attempts();
		if ( isset( $attempts[ $ip ] ) ) {
			unset( $attempts[ $ip ] );
			update_option( self::OPTION_ATTEMPTS, $attempts, false );
		}
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_lp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$old_settings = $this->get_settings();

		$email_2fa_roles = array();
		if ( isset( $_POST['email_2fa_roles'] ) && is_array( $_POST['email_2fa_roles'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Role slugs are validated in sanitize_role_list().
			$email_2fa_roles = wp_unslash( $_POST['email_2fa_roles'] );
		}

		$new = array(
			'custom_url'       => ! empty( $_POST['custom_url'] ),
			'login_slug'       => isset( $_POST['login_slug'] )
				? sanitize_title_with_dashes( sanitize_text_field( wp_unslash( $_POST['login_slug'] ) ) )
				: '',
			'brute_force'              => ! empty( $_POST['brute_force'] ),
			'block_forbidden_usernames'=> ! empty( $_POST['block_forbidden_usernames'] ),
			'max_attempts'     => isset( $_POST['max_attempts'] )
				? max( 1, min( 50, absint( wp_unslash( $_POST['max_attempts'] ) ) ) )
				: 5,
			'lockout_duration' => isset( $_POST['lockout_duration'] )
				? max( 1, min( 1440, absint( wp_unslash( $_POST['lockout_duration'] ) ) ) )
				: 30,
			'lockout_window'   => isset( $_POST['lockout_window'] )
				? max( 1, min( 60, absint( wp_unslash( $_POST['lockout_window'] ) ) ) )
				: 5,
			'whitelist_ips'    => isset( $_POST['whitelist_ips'] )
				? $this->sanitize_whitelist_ips( sanitize_textarea_field( wp_unslash( $_POST['whitelist_ips'] ) ) )
				: '',
			'notify_email'     => ! empty( $_POST['notify_email'] ),
			'notify_address'   => isset( $_POST['notify_address'] )
				? sanitize_email( wp_unslash( $_POST['notify_address'] ) )
				: get_option( 'admin_email' ),
			'login_maintenance'      => ! empty( $_POST['login_maintenance'] ),
			'login_maintenance_ips'  => isset( $_POST['login_maintenance_ips'] )
				? $this->sanitize_whitelist_ips( sanitize_textarea_field( wp_unslash( $_POST['login_maintenance_ips'] ) ) )
				: '',
			'email_2fa'              => ! empty( $_POST['email_2fa'] ),
			'email_2fa_roles'        => $this->sanitize_role_list( $email_2fa_roles ),
			'role_whitelist_ips'     => isset( $_POST['role_whitelist_ips'] )
				? sanitize_textarea_field( wp_unslash( $_POST['role_whitelist_ips'] ) )
				: '',
			'notify_mass_threshold'  => isset( $_POST['notify_mass_threshold'] )
				? max( 0, min( 100, absint( wp_unslash( $_POST['notify_mass_threshold'] ) ) ) )
				: 0,
			'notify_mass_window'     => isset( $_POST['notify_mass_window'] )
				? max( 5, min( 1440, absint( wp_unslash( $_POST['notify_mass_window'] ) ) ) )
				: 60,
		);

		// Require a non-empty slug when custom URL is enabled.
		if ( $new['custom_url'] && empty( $new['login_slug'] ) ) {
			wp_send_json_error( __( 'Please enter a custom login slug before enabling the custom URL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Prevent slug collisions with existing WP slugs.
		$reserved = array( 'wp-admin', 'wp-login', 'login', 'admin', 'wp-content', 'wp-includes', '' );
		if ( $new['custom_url'] && in_array( $new['login_slug'], $reserved, true ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: reserved slug */
					__( '"%s" is a reserved slug and cannot be used as the login URL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$new['login_slug']
				)
			);
		}

		update_option( self::OPTION_SETTINGS, $new, false );

		// Flush rewrite rules if slug changed.
		if ( $old_settings['login_slug'] !== $new['login_slug'] || $old_settings['custom_url'] !== $new['custom_url'] ) {
			flush_rewrite_rules( false );
		}

		TSOSK_Activity_Log::log( 'login-protect', 'save', __( 'Login protection settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Login protection settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: unlock a specific IP. */
	public function ajax_unlock(): void {
		check_ajax_referer( 'tsosk_lp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$ip    = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$index = isset( $_POST['idx'] ) ? absint( wp_unslash( $_POST['idx'] ) ) : -1;
		$reset_attempts_only = ! empty( $_POST['reset_attempts_only'] );

		if ( $reset_attempts_only ) {
			if ( '' === $ip ) {
				wp_send_json_error( __( 'Invalid IP address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
			$attempts = $this->get_all_attempts();
			if ( isset( $attempts[ $ip ] ) ) {
				unset( $attempts[ $ip ] );
				update_option( self::OPTION_ATTEMPTS, $attempts, false );
			}
			TSOSK_Activity_Log::log(
				'login-protect',
				'reset',
				__( 'Login attempt counter cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				array( 'ip' => $ip )
			);
			wp_send_json_success( __( 'Attempt counter cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( '' === $ip ) {
			wp_send_json_error( __( 'Invalid IP address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$lockouts = $this->get_lockout_log();
		if ( $index >= 0 && isset( $lockouts[ $index ] ) ) {
			$lockouts[ $index ]['active'] = false;
			update_option( self::OPTION_LOCKOUTS, $lockouts, false );
		} else {
			// Fallback: deactivate every active lockout for this IP.
			foreach ( $lockouts as &$entry ) {
				if ( (string) ( $entry['ip'] ?? '' ) === $ip && ! empty( $entry['active'] ) ) {
					$entry['active'] = false;
				}
			}
			unset( $entry );
			update_option( self::OPTION_LOCKOUTS, $lockouts, false );
		}

		$attempts = $this->get_all_attempts();
		if ( $ip && isset( $attempts[ $ip ] ) ) {
			unset( $attempts[ $ip ] );
			update_option( self::OPTION_ATTEMPTS, $attempts, false );
		}

		TSOSK_Activity_Log::log(
			'login-protect',
			'reset',
			__( 'IP unlocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array( 'ip' => $ip )
		);

		wp_send_json_success( __( 'IP unlocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: unlock all currently locked IPs. */
	public function ajax_unlock_all(): void {
		check_ajax_referer( 'tsosk_lp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$lockouts = $this->get_lockout_log();
		foreach ( $lockouts as &$l ) {
			$l['active'] = false;
		}
		unset( $l );
		update_option( self::OPTION_LOCKOUTS, $lockouts, false );
		delete_option( self::OPTION_ATTEMPTS );

		TSOSK_Activity_Log::log( 'login-protect', 'reset', __( 'All locked IPs unlocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'All IPs unlocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: clear the full lockout log. */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_lp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		delete_option( self::OPTION_LOCKOUTS );
		delete_option( self::OPTION_ATTEMPTS );

		TSOSK_Activity_Log::log( 'login-protect', 'delete', __( 'Login lockout log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Lockout log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: generate a random secure slug suggestion. */
	public function ajax_generate_slug(): void {
		check_ajax_referer( 'tsosk_lp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		wp_send_json_success( array( 'slug' => $this->random_slug() ) );
	}

	// ── Data helpers ─────────────────────────────────────────────────────────

	/**
	 * Get settings with safe defaults.
	 *
	 * @return array{custom_url:bool,login_slug:string,brute_force:bool,block_forbidden_usernames:bool,max_attempts:int,lockout_duration:int,lockout_window:int,whitelist_ips:string,notify_email:bool,notify_address:string}
	 */
	private function get_settings(): array {
		$s = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array(
			'custom_url'       => (bool) ( $s['custom_url']       ?? false ),
			'login_slug'       => sanitize_title_with_dashes( (string) ( $s['login_slug'] ?? '' ) ),
			'brute_force'               => (bool) ( $s['brute_force']               ?? false ),
			'block_forbidden_usernames' => (bool) ( $s['block_forbidden_usernames'] ?? false ),
			'max_attempts'     => max( 1, min( 50, (int) ( $s['max_attempts']     ?? 5  ) ) ),
			'lockout_duration' => max( 1, min( 1440, (int) ( $s['lockout_duration'] ?? 30 ) ) ),
			'lockout_window'   => max( 1, min( 60,   (int) ( $s['lockout_window']   ?? 5  ) ) ),
			'whitelist_ips'    => (string) ( $s['whitelist_ips']    ?? '' ),
			'notify_email'     => (bool) ( $s['notify_email']     ?? false ),
			'notify_address'   => sanitize_email( (string) ( $s['notify_address'] ?? get_option( 'admin_email', '' ) ) ),
			'login_maintenance'     => (bool) ( $s['login_maintenance'] ?? false ),
			'login_maintenance_ips' => (string) ( $s['login_maintenance_ips'] ?? '' ),
			'email_2fa'             => (bool) ( $s['email_2fa'] ?? false ),
			'email_2fa_roles'       => is_array( $s['email_2fa_roles'] ?? null ) ? array_map( 'sanitize_key', $s['email_2fa_roles'] ) : array(),
			'role_whitelist_ips'    => (string) ( $s['role_whitelist_ips'] ?? '' ),
			'notify_mass_threshold' => max( 0, min( 100, (int) ( $s['notify_mass_threshold'] ?? 0 ) ) ),
			'notify_mass_window'    => max( 5, min( 1440, (int) ( $s['notify_mass_window'] ?? 60 ) ) ),
		);
	}

	/**
	 * Sanitize a list of role slugs from POST.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return string[]
	 */
	private function sanitize_role_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$roles = array_keys( wp_roles()->roles );
		$out   = array();
		foreach ( $raw as $role ) {
			$role = sanitize_key( (string) $role );
			if ( in_array( $role, $roles, true ) ) {
				$out[] = $role;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Get the full lockout log array.
	 *
	 * @return array<int,array{ip:string,username:string,count:int,locked_at:int,until:int,active:bool}>
	 */
	private function get_lockout_log(): array {
		$v = get_option( self::OPTION_LOCKOUTS, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Get all attempt counters.
	 *
	 * @return array<string,array{count:int,first:int}>
	 */
	private function get_all_attempts(): array {
		$v = get_option( self::OPTION_ATTEMPTS, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Get an active lockout entry for an IP, or null if none.
	 *
	 * @param string $ip IP address.
	 * @return array{ip:string,until:int}|null
	 */
	private function get_active_lockout( string $ip ): ?array {
		$now      = time();
		$lockouts = $this->get_lockout_log();
		foreach ( array_reverse( $lockouts ) as $l ) {
			if ( (string) $l['ip'] === $ip && ! empty( $l['active'] ) && (int) $l['until'] > $now ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * Check if an IP is in the whitelist.
	 *
	 * @param string $ip            Client IP.
	 * @param string $whitelist_raw Raw newline-separated list.
	 * @return bool
	 */
	private function is_ip_whitelisted( string $ip, string $whitelist_raw ): bool {
		if ( '' === $ip || '' === $whitelist_raw ) {
			return false;
		}
		$list = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $whitelist_raw ) ) ) );
		return in_array( $ip, $list, true );
	}

	/**
	 * Normalize and validate whitelist IP lines before saving.
	 *
	 * @param string $whitelist_raw Raw textarea value.
	 * @return string
	 */
	private function sanitize_whitelist_ips( string $whitelist_raw ): string {
		$lines  = preg_split( '/\r\n|\r|\n/', $whitelist_raw ) ?: array();
		$valid  = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$valid[] = $line;
			}
		}

		return implode( "\n", array_unique( $valid ) );
	}

	/**
	 * Get the real client IP (REMOTE_ADDR only; proxy headers are not trusted by default).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Generate a random URL-safe slug.
	 *
	 * @return string
	 */
	private function random_slug(): string {
		$words  = array( 'enter', 'access', 'portal', 'door', 'gate', 'signin', 'key', 'secure', 'vault', 'pass' );
		$word   = $words[ array_rand( $words ) ];
		$suffix = wp_rand( 100, 9999 );
		return $word . '-' . $suffix;
	}

	/**
	 * Send lockout notification email to the admin.
	 *
	 * @param string $ip       Locked-out IP.
	 * @param string $username Username that was attempted.
	 * @param int    $count    Number of failed attempts.
	 */
	private function send_lockout_notification( string $ip, string $username, int $count ): void {
		$s       = $this->get_settings();
		$to      = $s['notify_address'] ?: get_option( 'admin_email' );
		$site    = get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: 1: site name, 2: IP address */
			__( '[%1$s] Login lockout: %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$site,
			$ip
		);
		$body = sprintf(
			/* translators: 1: IP, 2: username, 3: attempt count, 4: site URL */
			__( "IP address %1\$s has been locked out after %3\$d failed login attempts for username \"%2\$s\".\n\nSite: %4\$s\n\nYou can unlock this IP from TSO Swiss Knife › Login Protection.", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$ip,
			$username,
			$count,
			home_url()
		);
		wp_mail( $to, $subject, $body );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the module tab.
	 */
	public function render(): void {
		$s       = $this->get_settings();
		$nonce   = wp_create_nonce( 'tsosk_lp_nonce' );
		$my_ip   = $this->get_client_ip();
		$lockouts = $this->get_lockout_log();
		$attempts = $this->get_all_attempts();
		$now      = time();

		// Separate active/expired lockouts for display.
		$active_lockouts  = array_values( array_filter( $lockouts, static fn( $l ) => ! empty( $l['active'] ) && (int) $l['until'] > time() ) );
		$expired_lockouts = array_values( array_filter( $lockouts, static fn( $l ) => empty( $l['active'] ) || (int) $l['until'] <= time() ) );

		$login_url = $s['custom_url'] && $s['login_slug']
			? home_url( '/' . $s['login_slug'] . '/' )
			: wp_login_url();

		$remote_flags   = get_option( 'tsosk_hidden_profiles', array() );
		$xmlrpc_off     = is_array( $remote_flags ) && ! empty( $remote_flags['disable_xmlrpc'] );
		$security_url   = add_query_arg(
			array( 'page' => 'tso-swiss-knife', 'tab' => 'security' ),
			admin_url( 'tools.php' )
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Protect the WordPress login page against brute-force attacks and unwanted access. Each feature can be enabled and configured independently.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( ! empty( $active_lockouts ) ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<strong>
				<?php
				printf(
					/* translators: %d: number of locked out IPs */
					esc_html__( '⚠ %d IP address(es) are currently locked out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $active_lockouts )
				);
				?>
			</strong>
			<?php esc_html_e( 'See the Lockout Log section below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php endif; ?>

		<form id="tsosk-lp-form" autocomplete="off">

		<?php /* ── Section 1: Custom Login URL ── */ ?>
		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
				<?php esc_html_e( 'Custom Login URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge <?php echo $s['custom_url'] ? 'tsosk-badge-ok' : ''; ?>"
				      style="margin-left:10px;font-size:12px;">
					<?php echo $s['custom_url'] ? esc_html__( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</h3>

			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Changes the login page URL from the default /wp-login.php to a secret address only you know. Direct requests to /wp-login.php or /wp-admin/ while logged out will return a 404 instead of the login form, making the page invisible to bots.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<br><strong><?php esc_html_e( 'Important:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Save the new URL before enabling — if you lose it, you can disable this feature from the database or directly from this panel via a whitelisted IP.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>

			<?php if ( $s['custom_url'] && ! $xmlrpc_off ) : ?>
			<div class="tsosk-notice tsosk-notice-warn">
				<strong><?php esc_html_e( 'XML-RPC is still enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php
				printf(
					wp_kses(
						/* translators: %s: Security tab URL */
						__( 'The custom login URL does not block authentication via <code>xmlrpc.php</code>. Disable XML-RPC in the <a href="%s">Security</a> tab for stronger protection.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						array(
							'a'    => array( 'href' => array() ),
							'code' => array(),
						)
					),
					esc_url( $security_url )
				);
				?>
			</div>
			<?php endif; ?>

			<table class="tsosk-kv-table" style="width:100%;max-width:600px;">
				<tr>
					<th style="width:200px;"><?php esc_html_e( 'Enable custom login URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="custom_url" id="tsosk-lp-custom-url"
							       value="1" <?php checked( $s['custom_url'] ); ?>>
							<?php esc_html_e( 'Hide /wp-login.php and use the slug below instead', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Login slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
							<code style="color:#666;font-size:12px;"><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
							<input type="text" name="login_slug" id="tsosk-lp-slug"
							       value="<?php echo esc_attr( $s['login_slug'] ); ?>"
							       placeholder="<?php esc_attr_e( 'e.g. enter-4892', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
							       style="width:180px;" autocomplete="off" spellcheck="false">
							<code style="color:#666;font-size:12px;">/</code>
							<button type="button" class="button button-small" id="tsosk-lp-generate">
								<?php esc_html_e( 'Generate', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
						</div>
						<?php if ( $s['custom_url'] && $s['login_slug'] ) : ?>
						<p class="description" style="margin-top:6px;">
							<?php esc_html_e( 'Current login URL:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							<a href="<?php echo esc_url( $login_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $login_url ); ?>
							</a>
						</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php /* ── Section 2: Brute-force protection ── */ ?>
		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<?php esc_html_e( 'Brute-Force Protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge <?php echo $s['brute_force'] ? 'tsosk-badge-ok' : ''; ?>"
				      style="margin-left:10px;font-size:12px;">
					<?php echo $s['brute_force'] ? esc_html__( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</h3>

			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Counts consecutive failed login attempts per IP address. Once the threshold is reached, further login attempts from that IP are blocked for the configured duration. Successful logins reset the counter.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>

			<table class="tsosk-kv-table" style="width:100%;max-width:600px;">
				<tr>
					<th style="width:200px;"><?php esc_html_e( 'Enable brute-force protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="brute_force" id="tsosk-lp-brute"
							       value="1" <?php checked( $s['brute_force'] ); ?>>
							<?php esc_html_e( 'Block IPs after repeated failed logins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Block forbidden usernames', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="block_forbidden_usernames" id="tsosk-lp-block-forbidden"
							       value="1" <?php checked( $s['block_forbidden_usernames'] ); ?>>
							<?php esc_html_e( 'Instantly lock out IPs that try to log in with admin, administrador or administrator', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<p class="description" style="margin-top:6px;">
							<?php esc_html_e( 'Uses the lockout duration below. Whitelisted IPs are exempt. Works even when brute-force counting is disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Max attempts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" name="max_attempts" id="tsosk-lp-max"
						       value="<?php echo esc_attr( (string) $s['max_attempts'] ); ?>"
						       min="1" max="50" step="1" style="width:80px;">
						<span class="description">
							<?php esc_html_e( 'failed attempts before lockout (recommended: 5)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Attempt window', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" name="lockout_window" id="tsosk-lp-window"
						       value="<?php echo esc_attr( (string) $s['lockout_window'] ); ?>"
						       min="1" max="60" step="1" style="width:80px;">
						<span class="description">
							<?php esc_html_e( 'minutes in which the attempts are counted (recommended: 5)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Lockout duration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" name="lockout_duration" id="tsosk-lp-duration"
						       value="<?php echo esc_attr( (string) $s['lockout_duration'] ); ?>"
						       min="1" max="1440" step="1" style="width:80px;">
						<span class="description">
							<?php esc_html_e( 'minutes the IP is blocked (recommended: 30)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email notification', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="notify_email" id="tsosk-lp-notify"
							       value="1" <?php checked( $s['notify_email'] ); ?>>
							<?php esc_html_e( 'Send email when an IP is locked out', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<br>
						<input type="email" name="notify_address" id="tsosk-lp-notify-addr"
						       value="<?php echo esc_attr( $s['notify_address'] ); ?>"
						       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
						       style="margin-top:6px;width:280px;">
					</td>
				</tr>
			</table>
		</div>

		<?php /* ── Section 3: IP Whitelist ── */ ?>
		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'IP Whitelist', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</h3>

			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Whitelisted IPs always have access to the login page regardless of the custom URL setting, and are never locked out by the brute-force protection. Add your office and home IPs here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>

			<table class="tsosk-kv-table" style="width:100%;max-width:600px;">
				<tr>
					<th style="width:200px;vertical-align:top;padding-top:10px;">
						<?php esc_html_e( 'Whitelisted IPs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</th>
					<td>
						<textarea name="whitelist_ips" id="tsosk-lp-whitelist" rows="4"
						          style="width:100%;font-family:monospace;font-size:13px;"
						          placeholder="<?php esc_attr_e( 'One IP per line, e.g.:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>&#10;192.168.1.10&#10;85.125.44.72"
						><?php echo esc_textarea( $s['whitelist_ips'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Your current IP:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							<code><?php echo esc_html( $my_ip ); ?></code>
							<?php if ( ! $this->is_ip_whitelisted( $my_ip, $s['whitelist_ips'] ) ) : ?>
							<button type="button" class="button button-small" id="tsosk-lp-add-my-ip"
							        data-ip="<?php echo esc_attr( $my_ip ); ?>">
								<?php esc_html_e( 'Add my IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
							<?php else : ?>
							<span class="tsosk-badge tsosk-badge-ok" style="font-size:11px;">
								<?php esc_html_e( 'Whitelisted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</span>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<?php /* ── Section 4: Login maintenance & advanced ── */ ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Login Maintenance & Advanced', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table" style="width:100%;max-width:700px;">
				<tr>
					<th style="width:200px;vertical-align:top;"><?php esc_html_e( 'Login maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="login_maintenance" id="tsosk-lp-maintenance" value="1" <?php checked( $s['login_maintenance'] ); ?>>
							<?php esc_html_e( 'Block login page except for trusted IPs below', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<textarea name="login_maintenance_ips" id="tsosk-lp-maintenance-ips" rows="3"
						          style="width:100%;margin-top:8px;font-family:monospace;font-size:13px;"
						          placeholder="<?php esc_attr_e( 'One IP per line allowed during maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_textarea( $s['login_maintenance_ips'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Email verification (2FA)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="email_2fa" id="tsosk-lp-2fa" value="1" <?php checked( $s['email_2fa'] ); ?>>
							<?php esc_html_e( 'Send a one-time code by email after password validation', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Apply to roles:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php foreach ( wp_roles()->roles as $role_id => $role ) : ?>
						<label style="display:inline-block;margin-right:12px;margin-bottom:4px;">
							<input type="checkbox" name="email_2fa_roles[]" value="<?php echo esc_attr( $role_id ); ?>"
							       <?php checked( in_array( $role_id, $s['email_2fa_roles'], true ) ); ?>>
							<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'IP whitelist by role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea name="role_whitelist_ips" id="tsosk-lp-role-ips" rows="4"
						          style="width:100%;font-family:monospace;font-size:13px;"
						          placeholder="administrator: 192.168.1.10, 85.125.44.72"><?php echo esc_textarea( $s['role_whitelist_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One role per line: role_slug: ip1, ip2. Global whitelist IPs always bypass this rule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Mass lockout alert', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" name="notify_mass_threshold" id="tsosk-lp-mass-threshold" min="0" max="100" step="1"
						       value="<?php echo esc_attr( (string) $s['notify_mass_threshold'] ); ?>" style="width:70px;">
						<span class="description"><?php esc_html_e( 'lockouts in', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<input type="number" name="notify_mass_window" id="tsosk-lp-mass-window" min="5" max="1440" step="1"
						       value="<?php echo esc_attr( (string) $s['notify_mass_window'] ); ?>" style="width:70px;">
						<span class="description"><?php esc_html_e( 'minutes (0 = disabled)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<?php /* ── Save button ── */ ?>
		<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;">
			<button type="button" class="button button-primary" id="tsosk-lp-save"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-lp-msg"></span>
		</div>

		</form>

		<?php /* ── Active attempt counters ── */ ?>
		<?php if ( ! empty( $attempts ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Live Attempt Counters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'IPs that have failed login attempts within the active window but have not yet been locked out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'IP Address', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Failed Attempts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'First Attempt', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $attempts as $ip => $entry ) : ?>
					<?php
					$is_mine = ( (string) $ip === $my_ip );
					?>
					<tr class="<?php echo $is_mine ? 'tsosk-row-warn' : ''; ?>">
						<td class="tsosk-code">
							<?php echo esc_html( (string) $ip ); ?>
							<?php if ( $is_mine ) : ?>
								<span class="tsosk-badge tsosk-badge-warn" style="font-size:11px;"><?php esc_html_e( 'Your IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span style="font-weight:600;color:#b45309;">
								<?php echo esc_html( (string) (int) $entry['count'] ); ?>
							</span> / <?php echo esc_html( (string) $s['max_attempts'] ); ?>
						</td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['first'] ) ); ?> UTC</td>
						<td>
							<button class="button button-small tsosk-lp-reset-counter"
							        data-ip="<?php echo esc_attr( (string) $ip ); ?>"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<?php /* ── Lockout log ── */ ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Lockout Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $lockouts ) ); ?> / <?php echo esc_html( (string) self::MAX_LOG ); ?>
				</span>
			</h3>

			<?php if ( empty( $lockouts ) ) : ?>
			<p style="color:#646970;"><?php esc_html_e( 'No lockouts have been recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>

			<div class="tsosk-toolbar" style="gap:8px;margin-bottom:12px;">
				<?php if ( ! empty( $active_lockouts ) ) : ?>
				<button class="button" id="tsosk-lp-unlock-all"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Unlock All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<?php endif; ?>
				<button class="button button-link-delete" id="tsosk-lp-clear-log"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-lp-log-msg"></span>
			</div>

			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-lp-log-table">
					<thead><tr>
						<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Locked At', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Unlocks At', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					// Show most recent first.
					$log_display = array_reverse( array_values( $lockouts ) );
					foreach ( $log_display as $display_idx => $l ) :
						// Map back to original index for ajax_unlock.
						$orig_idx     = count( $lockouts ) - 1 - $display_idx;
						$is_active    = ! empty( $l['active'] ) && (int) $l['until'] > $now;
						$is_mine      = ( (string) $l['ip'] === $my_ip );
					?>
					<tr class="<?php echo $is_active ? 'tsosk-row-warn' : ''; ?>">
						<td>
							<?php if ( $is_active ) : ?>
								<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Locked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php else : ?>
								<span class="tsosk-badge"><?php esc_html_e( 'Expired', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="tsosk-code">
							<?php echo esc_html( (string) $l['ip'] ); ?>
							<?php if ( $is_mine ) : ?>
								<span class="tsosk-badge tsosk-badge-warn" style="font-size:11px;"><?php esc_html_e( 'Your IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="tsosk-code"><?php echo esc_html( (string) ( $l['username'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $l['count'] ?? 0 ) ); ?></td>
						<td style="white-space:nowrap;"><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $l['locked_at'] ) ); ?> UTC</td>
						<td style="white-space:nowrap;">
							<?php if ( $is_active ) : ?>
								<span style="color:#b45309;">
									<?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $l['until'] ) ); ?> UTC
								</span>
								<br><small style="color:#666;">
									(<?php
									$rem = (int) $l['until'] - $now;
									printf(
										/* translators: %s: human-readable time */
										esc_html__( '%s left', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										esc_html( human_time_diff( $now, (int) $l['until'] ) )
									);
									?>)
								</small>
							<?php else : ?>
								<span style="color:#646970;"><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $l['until'] ) ); ?> UTC</span>
							<?php endif; ?>
						</td>
						<td class="tsosk-actions">
							<?php if ( $is_active ) : ?>
							<button class="button button-small tsosk-lp-unlock"
							        data-ip="<?php echo esc_attr( (string) $l['ip'] ); ?>"
							        data-idx="<?php echo esc_attr( (string) $orig_idx ); ?>"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Unlock', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
							<?php else : ?>
							<span style="color:#ccc;">—</span>
							<?php endif; ?>
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
