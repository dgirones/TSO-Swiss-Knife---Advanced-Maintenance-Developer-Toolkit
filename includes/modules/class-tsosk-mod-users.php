<?php
/**
 * TSO Swiss Knife – Module: Users and Sessions.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Users
 */
class TSOSK_Mod_Users {

	/** Login history option (newest first). */
	private const OPTION_LOGIN_HISTORY = 'tsosk_login_history';

	/** User meta: force password change on next login. */
	public const META_FORCE_PASSWORD = 'tsosk_force_password_change';

	/** User meta: last successful login timestamp. */
	public const META_LAST_LOGIN = 'tsosk_last_login';

	/** Max login history entries. */
	private const MAX_HISTORY = 300;

	/** Users returned by the all-users list and live search. */
	private const USER_LIST_LIMIT = 50;

	/** Max users a "select all matching" role change can reach. */
	private const BULK_RESULT_LIMIT = 2000;

	/** Seconds a role change can be undone. */
	private const UNDO_TTL = 900;

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Users|null
	 */
	private static $instance = null;

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
		add_action( 'wp_ajax_tsosk_user_destroy_sessions', array( $this, 'ajax_destroy_sessions' ) );
		add_action( 'wp_ajax_tsosk_user_destroy_one_session', array( $this, 'ajax_destroy_one_session' ) );
		add_action( 'wp_ajax_tsosk_user_bulk_inactive', array( $this, 'ajax_bulk_inactive' ) );
		add_action( 'wp_ajax_tsosk_user_set_role', array( $this, 'ajax_set_role' ) );
		add_action( 'wp_ajax_tsosk_user_undo_role', array( $this, 'ajax_undo_role' ) );
		add_action( 'wp_ajax_tsosk_users_search', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_tsosk_users_sessions', array( $this, 'ajax_sessions' ) );
		add_action( 'wp_ajax_tsosk_users_inactive', array( $this, 'ajax_inactive_list' ) );
		add_action( 'wp_ajax_tsosk_user_force_password', array( $this, 'ajax_force_password' ) );
		add_action( 'wp_ajax_tsosk_users_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	/**
	 * Runtime hooks: login history, last login, forced password change.
	 */
	public function init(): void {
		add_action( 'wp_login', array( $this, 'on_login_success' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 2 );
		add_action( 'password_reset', array( $this, 'on_password_reset' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'on_profile_password_update' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_force_password_change' ) );
		add_action( 'template_redirect', array( $this, 'maybe_force_password_front' ) );
		add_filter( 'login_redirect', array( $this, 'force_password_login_redirect' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'force_password_notice' ) );
	}

	/**
	 * Show notice when the current user must change their password.
	 */
	public function force_password_notice(): void {
		if ( ! is_user_logged_in() || ! get_user_meta( get_current_user_id(), self::META_FORCE_PASSWORD, true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Your administrator requires you to change your password. Update it on your profile page below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		echo '</p></div>';
	}

	/**
	 * On login success.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public function on_login_success( string $user_login, WP_User $user ): void {
		unset( $user_login );
		update_user_meta( $user->ID, self::META_LAST_LOGIN, time() );
		$this->append_login_history(
			array(
				'time'     => time(),
				'user_id'  => $user->ID,
				'username' => $user->user_login,
				'ip'       => $this->get_client_ip(),
				'status'   => 'success',
			)
		);
	}

	/**
	 * On login failed.
	 *
	 * @param string   $username Username attempted.
	 * @param WP_Error $error    Error object.
	 */
	public function on_login_failed( string $username, WP_Error $error ): void {
		$skip = array( 'tsosk_2fa_required', 'tsosk_2fa_invalid', 'tsosk_2fa_max_attempts', 'tsosk_role_ip_denied', 'tsosk_locked_out' );
		if ( in_array( $error->get_error_code(), $skip, true ) ) {
			return;
		}
		$this->append_login_history(
			array(
				'time'     => time(),
				'user_id'  => 0,
				'username' => sanitize_user( $username, true ),
				'ip'       => $this->get_client_ip(),
				'status'   => 'failed',
			)
		);
	}

	/**
	 * Clear force-password flag after reset.
	 *
	 * @param WP_User $user User.
	 * @param string  $pass New password.
	 */
	public function on_password_reset( WP_User $user, string $pass ): void {
		unset( $pass );
		delete_user_meta( $user->ID, self::META_FORCE_PASSWORD );
	}

	/**
	 * Clear force-password flag when user saves a new password on profile.
	 *
	 * @param int     $user_id User ID.
	 * @param WP_User $old     Previous user data.
	 */
	public function on_profile_password_update( int $user_id, WP_User $old ): void {
		unset( $old );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core profile form.
		if ( ! empty( $_POST['pass1'] ) && ! empty( $_POST['pass2'] ) ) {
			delete_user_meta( $user_id, self::META_FORCE_PASSWORD );
		}
	}

	/**
	 * Redirect users who must change their password.
	 */
	public function maybe_force_password_change(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::META_FORCE_PASSWORD, true ) ) {
			global $pagenow;
			if ( 'profile.php' !== $pagenow ) {
				wp_safe_redirect( add_query_arg( 'tsosk_force_pwd', '1', admin_url( 'profile.php' ) ) );
				exit;
			}
		}
	}

	/**
	 * Force password change for front-end sessions (subscribers never hit admin_init).
	 */
	public function maybe_force_password_front(): void {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}
		if ( ! get_user_meta( get_current_user_id(), self::META_FORCE_PASSWORD, true ) ) {
			return;
		}
		// Accounts that cannot use wp-admin (customers, members) are often bounced back to the front end: redirecting them would loop.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'tsosk_force_pwd', '1', admin_url( 'profile.php' ) ) );
		exit;
	}

	/**
	 * Send forced-password users to their profile after login.
	 *
	 * @param string           $redirect_to           Default redirect.
	 * @param string           $requested_redirect_to Requested redirect.
	 * @param WP_User|WP_Error $user                  Authenticated user.
	 * @return string
	 */
	public function force_password_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		unset( $requested_redirect_to );
		if ( $user instanceof WP_User && get_user_meta( $user->ID, self::META_FORCE_PASSWORD, true ) ) {
			return add_query_arg( 'tsosk_force_pwd', '1', admin_url( 'profile.php' ) );
		}
		return $redirect_to;
	}

	/**
	 * Append login history.
	 *
	 * @param array<string,mixed> $entry History row.
	 */
	private function append_login_history( array $entry ): void {
		$history = get_option( self::OPTION_LOGIN_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift( $history, $entry );
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, 0, self::MAX_HISTORY );
		}
		update_option( self::OPTION_LOGIN_HISTORY, $history, false );
	}

	/**
	 * Get login history.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_login_history(): array {
		$history = get_option( self::OPTION_LOGIN_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get client ip.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return TSOSK_Support::get_client_ip();
	}

	/**
	 * Users inactive by last login (or registration if never logged in).
	 *
	 * @param int $days Inactivity threshold.
	 * @param int $limit Max users.
	 * @return WP_User[]
	 */
	private function get_inactive_users( int $days = 90, int $limit = 100 ): array {
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only inactive user report.
		$users = get_users(
			array(
				'number'     => 500,
				'orderby'    => 'registered',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'user_login', 'user_email', 'user_registered' ),
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_LAST_LOGIN,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_LAST_LOGIN,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		if ( $users ) {
			update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );
		}
		$out = array();
		foreach ( $users as $user ) {
			$last = $this->get_last_activity( (int) $user->ID, (string) $user->user_registered );
			if ( $last < $cutoff ) {
				$out[] = $user;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * AJAX: destroy sessions.
	 */
	public function ajax_destroy_sessions(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( get_current_user_id() === $user_id ) {
			wp_send_json_error( __( 'You cannot close your own sessions from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		TSOSK_Activity_Log::log(
			'users',
			'delete',
			sprintf(
				/* translators: %d: user ID */
				__( 'Sessions closed for user #%d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user_id
			)
		);
		wp_send_json_success( __( 'User sessions closed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: destroy one session.
	 */
	public function ajax_destroy_one_session(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! $user_id || '' === $token || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_error( __( 'Invalid parameters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( get_current_user_id() === $user_id && $this->is_current_session_verifier( $token ) ) {
			wp_send_json_error( __( 'You cannot close the session you are using right now.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		// The table lists sessions by their stored verifier (the hash WordPress keeps), not by the raw cookie token, so
		// WP_Session_Tokens::verify()/destroy() (which hash their input again) can never find them: remove the entry directly.
		$manager  = WP_Session_Tokens::get_instance( $user_id );
		$sessions = $manager->get_all();
		if ( ! isset( $sessions[ $token ] ) ) {
			wp_send_json_error( __( 'Session not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $manager instanceof WP_User_Meta_Session_Tokens ) {
			unset( $sessions[ $token ] );
			if ( empty( $sessions ) ) {
				delete_user_meta( $user_id, 'session_tokens' );
			} else {
				update_user_meta( $user_id, 'session_tokens', $sessions );
			}
		} else {
			wp_send_json_error( __( 'This site uses a custom session storage that does not allow closing a single session. Use "Close all sessions".', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		TSOSK_Activity_Log::log(
			'users',
			'delete',
			sprintf(
				/* translators: %d: user ID */
				__( 'A session was closed for user #%d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user_id
			)
		);
		wp_send_json_success( __( 'Session closed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: bulk inactive.
	 */
	public function ajax_bulk_inactive(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$action   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$days     = $this->normalize_inactive_days( isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 90 );
		$reassign = isset( $_POST['reassign_to'] ) ? absint( wp_unslash( $_POST['reassign_to'] ) ) : 0;
		$ids      = array();
		if ( isset( $_POST['user_ids'] ) && is_array( $_POST['user_ids'] ) ) {
			foreach ( wp_unslash( $_POST['user_ids'] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$ids[] = absint( $id );
			}
		}
		$ids = array_filter( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'Select at least one user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! in_array( $action, array( 'subscriber', 'delete' ), true ) ) {
			wp_send_json_error( __( 'Invalid bulk action.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'delete' === $action && $reassign && ( in_array( $reassign, $ids, true ) || ! get_user_by( 'id', $reassign ) ) ) {
			wp_send_json_error( __( 'Choose a valid user to receive the content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$count             = 0;
		$kept_with_content = 0;
		foreach ( $ids as $user_id ) {
			if ( get_current_user_id() === $user_id ) {
				continue;
			}
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}
			if ( ( 'delete' === $action && ! current_user_can( 'delete_user', $user_id ) ) || ( 'subscriber' === $action && ! current_user_can( 'promote_user', $user_id ) ) ) {
				continue;
			}
			$last = $this->get_last_activity( $user_id, (string) $user->user_registered );
			if ( $last >= time() - ( $days * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( 'delete' === $action ) {
				if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
					continue;
				}
				// Without a recipient, wp_delete_user() also removes the account's posts.
				if ( $this->user_owns_content( $user_id ) ) {
					if ( ! $reassign ) {
						++$kept_with_content;
						continue;
					}
					if ( wp_delete_user( $user_id, $reassign ) ) {
						++$count;
					}
					continue;
				}
				if ( wp_delete_user( $user_id ) ) {
					++$count;
				}
			} else {
				if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
					continue;
				}
				$user->set_role( 'subscriber' );
				++$count;
			}
		}

		TSOSK_Activity_Log::log(
			'users',
			'bulk',
			sprintf(
				/* translators: 1: action, 2: count */
				__( 'Bulk inactive user action (%1$s): %2$d users affected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$action,
				$count
			)
		);
		$message = sprintf(
			/* translators: %d: number of users */
			__( '%d users processed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$count
		);
		if ( $kept_with_content > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of users */
				__( '%d users were not deleted because they own posts or other content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$kept_with_content
			);
		}
		wp_send_json_success( $message );
	}

	/**
	 * Timestamp of the last known activity for a user.
	 *
	 * Without a recorded login (the login meta only exists since this plugin tracked it)
	 * the later of registration and plugin activation is used, so existing accounts are
	 * not reported as inactive just because tracking started recently.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $registered user_registered value.
	 * @return int
	 */
	private function get_last_activity( int $user_id, string $registered ): int {
		$last = (int) get_user_meta( $user_id, self::META_LAST_LOGIN, true );
		if ( $last ) {
			return $last;
		}
		$last      = (int) strtotime( $registered );
		$activated = (int) get_option( 'tsosk_activated', 0 );
		return max( $last, $activated );
	}

	/**
	 * Whether the user is the author of any post, page or custom content.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function user_owns_content( int $user_id ): bool {
		$types = get_post_types( array(), 'names' );
		unset( $types['revision'], $types['nav_menu_item'], $types['customize_changeset'], $types['oembed_cache'] );
		if ( count_user_posts( $user_id, array_values( $types ), false ) > 0 ) {
			return true;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_status NOT IN ('auto-draft','inherit')", $user_id ) ) > 0;
	}

	/**
	 * AJAX: force password.
	 */
	public function ajax_force_password(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user instanceof WP_User ) {
			wp_send_json_error( __( 'Invalid user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( get_current_user_id() === $user_id ) {
			wp_send_json_error( __( 'You cannot force a password change on your own account from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		update_user_meta( $user_id, self::META_FORCE_PASSWORD, '1' );
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		TSOSK_Activity_Log::log(
			'users',
			'update',
			sprintf(
				/* translators: 1: username, 2: user ID */
				__( 'Password change forced for %1$s (#%2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user->user_login,
				$user_id
			)
		);
		wp_send_json_success(
			sprintf(
				/* translators: %s: username */
				__( '%s must change their password on next login.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user->user_login
			)
		);
	}

	/**
	 * AJAX: clear history.
	 */
	public function ajax_clear_history(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		delete_option( self::OPTION_LOGIN_HISTORY );
		TSOSK_Activity_Log::log( 'users', 'delete', __( 'Login history cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Login history cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: replace the role of the selected users.
	 */
	public function ajax_set_role(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'promote_users' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role    = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'all';
		$days    = $this->normalize_inactive_days( isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 90 );
		$roles   = $this->assignable_roles();
		if ( ! isset( $roles[ $role ] ) ) {
			wp_send_json_error( __( 'Invalid role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! in_array( $context, array( 'all', 'inactive' ), true ) ) {
			$context = 'all';
		}

		$ids = array();
		if ( isset( $_POST['user_ids'] ) && is_array( $_POST['user_ids'] ) ) {
			foreach ( wp_unslash( $_POST['user_ids'] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$ids[] = absint( $id );
			}
		}
		// "Select all matching users": resolve the IDs on the server from the active search and role filter.
		if ( 'all' === $context && ! empty( $_POST['all_results'] ) ) {
			$filter_search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
			$filter_role   = isset( $_POST['filter_role'] ) ? sanitize_key( wp_unslash( $_POST['filter_role'] ) ) : '';
			if ( '' !== $filter_role && ! $this->is_known_role( $filter_role ) ) {
				wp_send_json_error( __( 'Invalid role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
			$ids = $this->directory_matching_ids( $filter_search, $filter_role );
		}
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'Select at least one user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$undo          = array();
		$count         = 0;
		$skipped_self  = 0;
		$skipped_last  = 0;
		$skipped_admin = 0;
		$admins_left   = $this->administrator_count();
		foreach ( $ids as $user_id ) {
			if ( get_current_user_id() === $user_id ) {
				++$skipped_self;
				continue;
			}
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof WP_User || ! current_user_can( 'promote_user', $user_id ) ) {
				continue;
			}
			$is_admin      = in_array( 'administrator', (array) $user->roles, true );
			$demote        = $is_admin && 'administrator' !== $role;
			$network_super = is_multisite() && is_super_admin( $user_id );
			if ( 'inactive' === $context ) {
				if ( $is_admin || $network_super || user_can( $user_id, 'manage_options' ) ) {
					++$skipped_admin;
					continue;
				}
				$last = $this->get_last_activity( $user_id, (string) $user->user_registered );
				if ( $last >= time() - ( $days * DAY_IN_SECONDS ) ) {
					continue;
				}
			} elseif ( $network_super && 'administrator' !== $role ) {
				++$skipped_admin;
				continue;
			}
			if ( $demote && $admins_left <= 1 ) {
				++$skipped_last;
				continue;
			}
			$undo[ $user_id ] = array_values( array_map( 'sanitize_key', (array) $user->roles ) );
			$user->set_role( $role );
			if ( $demote ) {
				--$admins_left;
			}
			++$count;
		}
		if ( $undo ) {
			set_transient( 'tsosk_users_undo_' . get_current_user_id(), $undo, self::UNDO_TTL );
		} else {
			delete_transient( 'tsosk_users_undo_' . get_current_user_id() );
		}

		TSOSK_Activity_Log::log(
			'users',
			'update',
			sprintf(
				/* translators: 1: role slug, 2: number of users */
				__( 'Role set to %1$s for %2$d users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$role,
				$count
			)
		);

		if ( 0 === $count ) {
			$message = __( 'No users were changed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			$message = sprintf(
				/* translators: 1: number of users, 2: role name */
				__( '%1$d users now have the “%2$s” role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count,
				$roles[ $role ]
			);
		}
		if ( $skipped_last > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of users */
				__( '%d users were not changed because they are the last administrator.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$skipped_last
			);
		}
		if ( $skipped_admin > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of users */
				__( '%d administrators were left unchanged.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$skipped_admin
			);
		}
		if ( $skipped_self > 0 ) {
			$message .= ' ' . __( 'Your own account was not changed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'counts'  => $this->role_counts_payload(),
				'undo'    => ! empty( $undo ),
			)
		);
	}

	/**
	 * Fresh per-role totals so the summary cards stay in sync without a page reload.
	 *
	 * @return array<string,int>
	 */
	private function role_counts_payload(): array {
		$fresh  = count_users();
		$counts = array();
		if ( isset( $fresh['avail_roles'] ) && is_array( $fresh['avail_roles'] ) ) {
			foreach ( $fresh['avail_roles'] as $slug => $total ) {
				$counts[ sanitize_key( (string) $slug ) ] = (int) $total;
			}
		}
		foreach ( array_keys( $this->role_labels() ) as $slug ) {
			if ( ! isset( $counts[ $slug ] ) ) {
				$counts[ $slug ] = 0;
			}
		}
		return $counts;
	}

	/**
	 * AJAX: restore the roles replaced by the latest bulk role change.
	 */
	public function ajax_undo_role(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'promote_users' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$key  = 'tsosk_users_undo_' . get_current_user_id();
		$undo = get_transient( $key );
		if ( ! is_array( $undo ) || empty( $undo ) ) {
			wp_send_json_error( __( 'There is nothing to undo.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		delete_transient( $key );

		$restored = 0;
		foreach ( $undo as $user_id => $previous ) {
			$user_id = absint( $user_id );
			$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
			if ( ! $user instanceof WP_User || get_current_user_id() === $user_id || ! current_user_can( 'promote_user', $user_id ) ) {
				continue;
			}
			$slugs = array();
			foreach ( (array) $previous as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug && $this->is_known_role( $slug ) ) {
					$slugs[] = $slug;
				}
			}
			$user->set_role( (string) array_shift( $slugs ) );
			foreach ( $slugs as $slug ) {
				$user->add_role( $slug );
			}
			++$restored;
		}

		TSOSK_Activity_Log::log(
			'users',
			'update',
			sprintf(
				/* translators: %d: number of users */
				__( 'Role changes undone for %d users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$restored
			)
		);
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of users */
					__( 'Role changes undone for %d users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$restored
				),
				'counts'  => $this->role_counts_payload(),
				'undo'    => false,
			)
		);
	}

	/**
	 * AJAX: live user search for the All users tab.
	 */
	public function ajax_search_users(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$role   = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		if ( '' !== $role && ! $this->is_known_role( $role ) ) {
			wp_send_json_error( __( 'Invalid role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$users    = $this->query_directory_users( $search, $role, $offset, self::USER_LIST_LIMIT + 1 );
		$has_more = count( $users ) > self::USER_LIST_LIMIT;
		if ( $has_more ) {
			$users = array_slice( $users, 0, self::USER_LIST_LIMIT );
		}
		$total = '' === $search && '' === $role ? $this->total_user_count() : 0;
		$shown = $offset + count( $users );
		wp_send_json_success(
			array(
				'html'     => ( $offset > 0 && empty( $users ) ) ? '' : $this->render_directory_rows( $users, wp_create_nonce( 'tsosk_users_nonce' ) ),
				'message'  => $this->directory_status_message( $search, $role, $shown, $has_more, $total ),
				'has_more' => $has_more,
				'total'    => 0 === $offset ? $this->directory_matching_total( $search, $role ) : null,
			)
		);
	}

	/**
	 * AJAX: session blocks for one role, or every role.
	 */
	public function ajax_sessions(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'administrator';
		if ( '' !== $role && ! $this->is_known_role( $role ) ) {
			wp_send_json_error( __( 'Invalid role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$users = $this->query_session_users( $role );
		ob_start();
		$this->render_session_list( $users, wp_create_nonce( 'tsosk_users_nonce' ) );
		$html = (string) ob_get_clean();
		wp_send_json_success(
			array(
				'html'    => $html,
				'message' => $this->session_list_message( count( $users ) ),
			)
		);
	}

	/**
	 * AJAX: rebuild the inactive-user list for a chosen threshold.
	 */
	public function ajax_inactive_list(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$days  = $this->normalize_inactive_days( isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 90 );
		$users = $this->get_inactive_users( $days, 100 );
		$count = count( $users );
		ob_start();
		$this->render_inactive_body( $users, wp_create_nonce( 'tsosk_users_nonce' ) );
		wp_send_json_success(
			array(
				'html'        => (string) ob_get_clean(),
				'count_label' => (string) $count . ( $count >= 100 ? '+' : '' ),
				'label'       => sprintf(
					/* translators: %d: number of days */
					__( 'Inactive (%d+ days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$days
				),
			)
		);
	}

	/**
	 * Roles the current admin is allowed to assign.
	 *
	 * @return array<string,string> Role slug => translated label.
	 */
	private function assignable_roles(): array {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable = get_editable_roles();
		$out      = array();
		foreach ( $editable as $slug => $role ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = isset( $role['name'] ) ? translate_user_role( (string) $role['name'] ) : $slug;
		}
		return $out;
	}

	/**
	 * Number of administrator accounts.
	 *
	 * @return int
	 */
	private function administrator_count(): int {
		$counts = count_users();
		return (int) ( $counts['avail_roles']['administrator'] ?? 0 );
	}

	/**
	 * Total users on the site.
	 *
	 * @return int
	 */
	private function total_user_count(): int {
		$counts = count_users();
		return (int) ( $counts['total_users'] ?? 0 );
	}

	/**
	 * Verifier of the session this request is using.
	 *
	 * The table keys are the stored hashes. Comparing the session returned by
	 * the public API avoids depending on which hash WordPress used.
	 *
	 * @return string Empty when the current session cannot be identified.
	 */
	private function current_session_verifier(): string {
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return '';
		}
		$manager = WP_Session_Tokens::get_instance( get_current_user_id() );
		$current = $manager->get( $token );
		if ( ! is_array( $current ) ) {
			return '';
		}
		foreach ( $manager->get_all() as $verifier => $session ) {
			if ( is_array( $session ) && $session === $current ) {
				return (string) $verifier;
			}
		}
		return '';
	}

	/**
	 * Whether a stored verifier is the session open in this browser.
	 *
	 * An unidentified current session is treated as current, so this screen
	 * cannot close it by mistake.
	 *
	 * @param string $verifier Stored session verifier.
	 * @return bool
	 */
	private function is_current_session_verifier( string $verifier ): bool {
		if ( '' === $verifier ) {
			return true;
		}
		$current = $this->current_session_verifier();
		if ( '' === $current ) {
			return true;
		}
		return hash_equals( $current, $verifier );
	}

	/**
	 * Inactive thresholds the screen offers.
	 *
	 * @param int $days Requested days.
	 * @return int
	 */
	private function normalize_inactive_days( int $days ): int {
		return in_array( $days, array( 30, 60, 90, 180, 365 ), true ) ? $days : 90;
	}

	/**
	 * Whether the slug is a role on this site.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	private function is_known_role( string $role ): bool {
		$role = sanitize_key( $role );
		if ( '' === $role ) {
			return false;
		}
		$roles = wp_roles();
		return isset( $roles->roles[ $role ] );
	}

	/**
	 * Every role on the site, for filters.
	 *
	 * @return array<string,string>
	 */
	private function role_labels(): array {
		$roles = wp_roles();
		$out   = array();
		foreach ( $roles->role_names as $slug => $name ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = translate_user_role( $name );
		}
		return $out;
	}

	/**
	 * Role filter used by Sessions and All users.
	 *
	 * @param string $id       Select element id.
	 * @param string $selected Selected role slug. Empty means all roles.
	 * @param string $nonce    Nonce stored on the select.
	 */
	private function render_role_filter( string $id, string $selected, string $nonce ): void {
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
			<?php esc_html_e( 'Filter by role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</label>
		<select id="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<option value=""><?php esc_html_e( 'All roles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
			<?php foreach ( $this->role_labels() as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Accounts that can receive posts when another user is deleted.
	 *
	 * @return array<int,object>
	 */
	private function content_recipients(): array {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 200,
				'orderby'    => 'login',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'user_login', 'display_name' ),
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/**
	 * Users whose sessions are listed.
	 *
	 * @param string $role Role slug, or empty for every role.
	 * @return array<int,object>
	 */
	private function query_session_users( string $role ): array {
		$args = array(
			'number'  => self::USER_LIST_LIMIT,
			'orderby' => 'login',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'user_login', 'user_email' ),
		);
		if ( '' !== $role && $this->is_known_role( $role ) ) {
			$args['role'] = $role;
		}
		$users = get_users( $args );
		if ( ! is_array( $users ) ) {
			return array();
		}
		if ( $users ) {
			update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );
		}
		return $users;
	}

	/**
	 * Note under the session role filter.
	 *
	 * @param int $found Accounts returned.
	 * @return string
	 */
	private function session_list_message( int $found ): string {
		if ( $found >= self::USER_LIST_LIMIT ) {
			return sprintf(
				/* translators: %d: number of accounts shown */
				__( 'Showing the first %d accounts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$found
			);
		}
		return sprintf(
			/* translators: %d: number of accounts */
			_n( '%d account.', '%d accounts.', $found, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$found
		);
	}

	/**
	 * Search and role filters shared by the All users queries.
	 *
	 * @param string $search Login, name or email fragment.
	 * @param string $role   Role slug, or empty for every role.
	 * @return array<string,mixed>
	 */
	private function directory_filter_args( string $search, string $role ): array {
		$args = array();
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}
		if ( '' !== $role && $this->is_known_role( $role ) ) {
			$args['role'] = $role;
		}
		return $args;
	}

	/**
	 * IDs of every user matching the All users filters (capped).
	 *
	 * @param string $search Login, name or email fragment.
	 * @param string $role   Role slug, or empty for every role.
	 * @return int[]
	 */
	private function directory_matching_ids( string $search, string $role ): array {
		$ids = get_users(
			array_merge(
				array(
					'number' => self::BULK_RESULT_LIMIT,
					'fields' => 'ID',
				),
				$this->directory_filter_args( $search, $role )
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Number of users matching the All users filters.
	 *
	 * @param string $search Login, name or email fragment.
	 * @param string $role   Role slug, or empty for every role.
	 * @return int
	 */
	private function directory_matching_total( string $search, string $role ): int {
		$query = new WP_User_Query(
			array_merge(
				array(
					'number'      => 1,
					'fields'      => 'ID',
					'count_total' => true,
				),
				$this->directory_filter_args( $search, $role )
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Active sessions of an account (0 when the session store cannot be read).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function session_count( int $user_id ): int {
		$manager = WP_Session_Tokens::get_instance( $user_id );
		return count( $manager->get_all() );
	}

	/**
	 * Users for the All users tab.
	 *
	 * @param string $search Login, name or email fragment. Empty lists the most recent accounts.
	 * @param string $role   Role slug, or empty for every role.
	 * @param int    $offset Rows to skip.
	 * @param int    $limit  Rows to fetch.
	 * @return WP_User[]
	 */
	private function query_directory_users( string $search, string $role = '', int $offset = 0, int $limit = self::USER_LIST_LIMIT ): array {
		// The ID tiebreaker keeps "Show more" stable when several accounts share the same registration time.
		$args  = array(
			'number'  => max( 1, $limit ),
			'offset'  => max( 0, min( 5000, $offset ) ),
			'orderby' => '' === $search
				? array(
					'registered' => 'DESC',
					'ID'         => 'DESC',
				)
				: array(
					'login' => 'ASC',
					'ID'    => 'ASC',
				),
		);
		$args  = array_merge( $args, $this->directory_filter_args( $search, $role ) );
		$users = get_users( $args );
		if ( ! is_array( $users ) ) {
			return array();
		}
		$ids = array();
		foreach ( $users as $user ) {
			if ( $user instanceof WP_User ) {
				$ids[] = (int) $user->ID;
			}
		}
		if ( $ids ) {
			update_meta_cache( 'user', $ids );
		}
		return $users;
	}

	/**
	 * Status line under the user search box.
	 *
	 * @param string $search     Search text.
	 * @param string $role       Role filter.
	 * @param int    $shown      Rows currently listed.
	 * @param bool   $has_more   Whether more rows exist after the ones listed.
	 * @param int    $total      Site user count, used when no filter is active.
	 * @return string
	 */
	private function directory_status_message( string $search, string $role, int $shown, bool $has_more, int $total ): string {
		if ( 0 === $shown ) {
			return __( 'No users match this search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( $has_more ) {
			return sprintf(
				/* translators: %d: number of users shown */
				__( 'Showing %d users. Use Show more or refine the search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$shown
			);
		}
		if ( '' === $search && '' === $role && $total > $shown ) {
			return sprintf(
				/* translators: %d: number of users shown */
				__( 'Showing the %d most recent users. Type to narrow the list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$shown
			);
		}
		if ( '' === $search && '' === $role ) {
			return sprintf(
				/* translators: %d: number of users */
				__( 'Showing all %d users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$shown
			);
		}
		return sprintf(
			/* translators: %d: number of matching users */
			_n( '%d user matches.', '%d users match.', $shown, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$shown
		);
	}

	/**
	 * HTML rows for the All users table.
	 *
	 * @param WP_User[] $users Users.
	 * @param string    $nonce Nonce for the row actions.
	 * @return string
	 */
	private function render_directory_rows( array $users, string $nonce ): string {
		if ( empty( $users ) ) {
			return '<tr class="tsosk-users-empty"><td colspan="7">' . esc_html__( 'No users match this search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</td></tr>';
		}
		$roles            = $this->assignable_roles();
		$wp_roles         = wp_roles();
		$current          = get_current_user_id();
		$date             = (string) get_option( 'date_format' );
		$label_select     = __( 'Select', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_user       = __( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_email      = __( 'Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_role       = __( 'Role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_registered = __( 'Registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_last       = __( 'Last login', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_actions    = __( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$html             = '';
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$is_self    = $current === (int) $user->ID;
			$names      = array();
			$role_slugs = array();
			foreach ( (array) $user->roles as $slug ) {
				$slug         = sanitize_key( (string) $slug );
				$role_slugs[] = $slug;
				if ( isset( $roles[ $slug ] ) ) {
					$names[] = $roles[ $slug ];
				} elseif ( isset( $wp_roles->role_names[ $slug ] ) ) {
					$names[] = translate_user_role( $wp_roles->role_names[ $slug ] );
				} else {
					$names[] = $slug;
				}
			}
			$last      = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
			$role_text = $names ? implode( ', ', $names ) : '';
			$html     .= '<tr data-login="' . esc_attr( $user->user_login ) . '" data-email="' . esc_attr( $user->user_email ) . '" data-role="' . esc_attr( $role_text ) . '" data-registered="' . esc_attr( (string) (int) strtotime( $user->user_registered ) ) . '" data-last="' . esc_attr( (string) $last ) . '">';
			$html     .= '<td class="tsosk-users-col-check" data-label="' . esc_attr( $label_select ) . '"><input type="checkbox" class="tsosk-users-cb" value="' . esc_attr( (string) $user->ID ) . '" data-roles="' . esc_attr( implode( ',', $role_slugs ) ) . '"' . disabled( $is_self, true, false ) . '></td>';
			$html     .= '<td class="tsosk-users-col-user" data-label="' . esc_attr( $label_user ) . '">';
			if ( $user->display_name && $user->display_name !== $user->user_login ) {
				$html .= '<span class="tsosk-users-dn">' . esc_html( $user->display_name ) . '</span>';
			}
			$html .= '<code>' . esc_html( $user->user_login ) . '</code>';
			if ( $is_self ) {
				$html .= ' <span class="tsosk-badge tsosk-badge-info">' . esc_html__( 'You (this account)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';
			}
			$html      .= '</td>';
			$email_html = esc_html( $user->user_email );
			$at_pos     = strpos( $user->user_email, '@' );
			if ( false !== $at_pos ) {
				$email_html = esc_html( substr( $user->user_email, 0, $at_pos + 1 ) ) . '<wbr>' . esc_html( substr( $user->user_email, $at_pos + 1 ) );
			}
			$html    .= '<td class="tsosk-users-col-email" data-label="' . esc_attr( $label_email ) . '">' . $email_html . '</td>';
			$html    .= '<td class="tsosk-users-col-role tsosk-users-role-cell" data-label="' . esc_attr( $label_role ) . '">' . esc_html( '' !== $role_text ? $role_text : '—' ) . '</td>';
			$html    .= '<td class="tsosk-users-col-registered" data-label="' . esc_attr( $label_registered ) . '">' . esc_html( date_i18n( $date, (int) strtotime( $user->user_registered ) ) ) . '</td>';
			$sessions = $this->session_count( (int) $user->ID );
			$html    .= '<td class="tsosk-users-col-last" data-label="' . esc_attr( $label_last ) . '">' . ( $last ? esc_html( date_i18n( $date, $last ) ) : esc_html__( 'Never', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			if ( $sessions > 0 ) {
				$html .= '<span class="tsosk-users-sess-badge">' . sprintf(
					/* translators: %d: number of active sessions */
					esc_html( _n( '%d session', '%d sessions', $sessions, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					(int) $sessions
				) . '</span>';
			}
			$html .= '</td>';
			$html .= '<td class="tsosk-users-col-actions tsosk-users-row-actions" data-label="' . esc_attr( $label_actions ) . '">';
			if ( ! $is_self ) {
				$html .= '<button type="button" class="button button-small tsosk-user-close-sessions" data-user-id="' . esc_attr( (string) $user->ID ) . '" data-user-login="' . esc_attr( $user->user_login ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
				$html .= sprintf(
					/* translators: %s: username */
					esc_html__( 'Close all sessions for %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( $user->user_login )
				);
				$html .= '</button>';
				$html .= '<button type="button" class="button button-small tsosk-user-force-pwd" data-user-id="' . esc_attr( (string) $user->ID ) . '" data-user-login="' . esc_attr( $user->user_login ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
				$html .= sprintf(
					/* translators: %s: username */
					esc_html__( 'Force password change for %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( $user->user_login )
				);
				$html .= '</button>';
				$html .= '<span class="tsosk-ajax-msg"></span>';
			}
			$html .= '</td>';
			$html .= '</tr>';
		}
		if ( '' === $html ) {
			return '<tr class="tsosk-users-empty"><td colspan="7">' . esc_html__( 'No users match this search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</td></tr>';
		}
		return $html;
	}

	/**
	 * Role dropdown and apply button shared by the user tables.
	 *
	 * @param string $context all|inactive.
	 * @param string $nonce   Nonce.
	 */
	private function render_role_controls( string $context, string $nonce ): void {
		$roles = $this->assignable_roles();
		?>
		<label class="screen-reader-text" for="tsosk-users-role-<?php echo esc_attr( $context ); ?>">
			<?php esc_html_e( 'Change selected to…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</label>
		<select id="tsosk-users-role-<?php echo esc_attr( $context ); ?>" class="tsosk-users-role-select">
			<option value=""><?php esc_html_e( 'Change selected to…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
			<?php foreach ( $roles as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="button tsosk-users-apply-role" data-context="<?php echo esc_attr( $context ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Apply role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</button>
		<?php
	}

	/**
	 * Role counts that share the summary row. Administrators stay on their own card.
	 *
	 * @param array<string,mixed> $user_counts Result of count_users().
	 */
	private function render_role_summary( array $user_counts ): void {
		$labels = $this->role_labels();
		$avail  = isset( $user_counts['avail_roles'] ) && is_array( $user_counts['avail_roles'] ) ? $user_counts['avail_roles'] : array();
		$rows   = array();
		foreach ( $labels as $slug => $label ) {
			if ( 'administrator' === $slug ) {
				unset( $avail[ $slug ] );
				continue;
			}
			$rows[] = array(
				'slug'  => (string) $slug,
				'label' => $label,
				'count' => (int) ( $avail[ $slug ] ?? 0 ),
			);
			unset( $avail[ $slug ] );
		}
		foreach ( $avail as $slug => $count ) {
			$count = (int) $count;
			if ( $count < 1 ) {
				continue;
			}
			$rows[] = array(
				'slug'  => (string) $slug,
				'label' => 'none' === (string) $slug
					? __( 'No role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: (string) $slug,
				'count' => $count,
			);
		}
		if ( ! $rows ) {
			return;
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] <=> $a['count'];
				}
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);
		foreach ( $rows as $row ) {
			echo '<div class="tsosk-media-stat tsosk-media-stat-info">';
			echo '<strong id="tsosk-users-count-' . esc_attr( sanitize_key( (string) $row['slug'] ) ) . '">' . esc_html( (string) $row['count'] ) . '</strong>';
			echo '<span>' . esc_html( (string) $row['label'] ) . '</span>';
			echo '</div>';
		}
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce          = wp_create_nonce( 'tsosk_users_nonce' );
		$inactive_days  = 90;
		$session_users  = $this->query_session_users( 'administrator' );
		$inactive_users = $this->get_inactive_users( $inactive_days, 100 );
		$history_all    = $this->get_login_history();
		$directory      = $this->query_directory_users( '', '', 0, self::USER_LIST_LIMIT + 1 );
		$directory_more = count( $directory ) > self::USER_LIST_LIMIT;
		if ( $directory_more ) {
			$directory = array_slice( $directory, 0, self::USER_LIST_LIMIT );
		}
		$user_counts    = count_users();
		$total_users    = (int) ( $user_counts['total_users'] ?? 0 );
		$admin_total    = (int) ( $user_counts['avail_roles']['administrator'] ?? 0 );
		$inactive_count = count( $inactive_users );
		$inactive_label = (string) $inactive_count . ( $inactive_count >= 100 ? '+' : '' );
		$summary        = array(
			array( __( 'Total Users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (string) $total_users, 'info', '', '' ),
			array( __( 'Administrators', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (string) $admin_total, 'info', 'tsosk-users-count-administrator', '' ),
			array(
				sprintf(
					/* translators: %d: number of days */
					__( 'Inactive (%d+ days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$inactive_days
				),
				$inactive_label,
				$inactive_count > 0 ? 'warn' : 'ok',
				'tsosk-users-inactive-count',
				'tsosk-users-inactive-label',
			),
			array( __( 'Login history entries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (string) count( $history_all ), 'info', '', '' ),
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect sessions, login history, inactive accounts and manage user security actions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div id="tsosk-users-summary">
			<h3 class="screen-reader-text"><?php esc_html_e( 'User Summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-media-stats">
				<?php foreach ( $summary as $index => $card ) : ?>
					<div class="tsosk-media-stat tsosk-media-stat-<?php echo esc_attr( $card[2] ); ?>">
						<strong<?php echo '' !== $card[3] ? ' id="' . esc_attr( $card[3] ) . '"' : ''; ?>><?php echo esc_html( $card[1] ); ?></strong>
						<span<?php echo '' !== $card[4] ? ' id="' . esc_attr( $card[4] ) . '"' : ''; ?>><?php echo esc_html( $card[0] ); ?></span>
					</div>
					<?php
					if ( 1 === $index ) {
						// Administrators first, then the other roles, then inactive and login history.
						$this->render_role_summary( $user_counts );
					}
					?>
				<?php endforeach; ?>
			</div>
		</div>

		<h2 class="nav-tab-wrapper tsosk-users-tabs" role="tablist">
			<a href="#tsosk-users-panel-sessions" class="nav-tab tsosk-users-tab nav-tab-active" data-tab="sessions" role="tab" aria-selected="true"><?php esc_html_e( 'Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-users-panel-all" class="nav-tab tsosk-users-tab" data-tab="all" role="tab" aria-selected="false"><?php esc_html_e( 'All users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-users-panel-inactive" class="nav-tab tsosk-users-tab" data-tab="inactive" role="tab" aria-selected="false"><?php esc_html_e( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-users-panel-history" class="nav-tab tsosk-users-tab" data-tab="history" role="tab" aria-selected="false"><?php esc_html_e( 'Login History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
		</h2>

		<div class="tsosk-users-panel" id="tsosk-users-panel-sessions" role="tabpanel">
			<?php $this->render_sessions_card( $session_users, $nonce ); ?>
		</div>
		<div class="tsosk-users-panel" id="tsosk-users-panel-all" role="tabpanel" hidden>
			<?php $this->render_directory_card( $directory, $total_users, $nonce, $directory_more ); ?>
		</div>
		<div class="tsosk-users-panel" id="tsosk-users-panel-inactive" role="tabpanel" hidden>
			<?php $this->render_inactive_card( $inactive_users, $nonce ); ?>
		</div>
		<div class="tsosk-users-panel" id="tsosk-users-panel-history" role="tabpanel" hidden>
			<?php $this->render_history_card( $history_all, $nonce ); ?>
		</div>
		<?php
	}

	/**
	 * Render sessions card.
	 *
	 * @param WP_User[] $users Users.
	 * @param string    $nonce Nonce.
	 */
	private function render_sessions_card( array $users, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Open sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Session tokens stored by WordPress. The same account can have several open sessions at once (different browser, device, IP or “Remember me” login). That is normal — it is not a duplicate bug.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-toolbar tsosk-users-directory-toolbar">
				<?php $this->render_role_filter( 'tsosk-users-sessions-role', 'administrator', $nonce ); ?>
			</div>
			<p class="description" id="tsosk-users-sessions-note"><?php echo esc_html( $this->session_list_message( count( $users ) ) ); ?></p>
			<div id="tsosk-users-sessions-list">
				<?php $this->render_session_list( $users, $nonce ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Session blocks for the accounts in the list.
	 *
	 * @param array<int,object> $users Accounts.
	 * @param string            $nonce Nonce.
	 */
	private function render_session_list( array $users, string $nonce ): void {
		$current_user_id  = get_current_user_id();
		$current_verifier = $this->current_session_verifier();
		$datetime         = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$label_ip         = __( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_login      = __( 'Login time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_expires    = __( 'Expires', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'No users found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
			return;
		}
		echo '<div class="tsosk-us-grid">';
		foreach ( $users as $user ) {
			$sessions  = WP_Session_Tokens::get_instance( $user->ID )->get_all();
			$last      = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
			$is_self   = ( $current_user_id === (int) $user->ID );
			$session_n = count( $sessions );
			$initials  = strtoupper( (string) mb_substr( (string) preg_replace( '/[^\p{L}\p{N}]/u', '', (string) $user->user_login ), 0, 2 ) );
			?>
			<article class="tsosk-us-card<?php echo $session_n ? ' has-sessions' : ''; ?><?php echo $is_self ? ' is-self' : ''; ?>">
				<header class="tsosk-us-head">
					<span class="tsosk-us-avatar" aria-hidden="true"><?php echo esc_html( '' !== $initials ? $initials : '?' ); ?></span>
					<div class="tsosk-us-id">
						<div class="tsosk-us-name">
							<strong><?php echo esc_html( $user->user_login ); ?></strong>
							<?php if ( $is_self ) : ?>
								<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'You (this account)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $user->user_email ) : ?>
							<span class="tsosk-us-email"><?php echo esc_html( $user->user_email ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tsosk-us-chips">
						<span class="tsosk-us-chip<?php echo $session_n ? ' is-on' : ''; ?>">
							<?php
							printf(
								/* translators: %d: number of active sessions */
								esc_html( _n( '%d active session', '%d active sessions', $session_n, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
								(int) $session_n
							);
							?>
						</span>
						<?php if ( $last ) : ?>
							<span class="tsosk-us-chip"><?php esc_html_e( 'Last login:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <?php echo esc_html( date_i18n( $datetime, $last ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( ! $is_self ) : ?>
						<div class="tsosk-us-actions">
							<button type="button" class="button button-small tsosk-user-close-sessions"
									data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
									data-user-login="<?php echo esc_attr( $user->user_login ); ?>"
									data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php
								printf(
									/* translators: %s: username */
									esc_html__( 'Close all sessions for %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									esc_html( $user->user_login )
								);
								?>
							</button>
							<button type="button" class="button button-small tsosk-user-force-pwd"
									data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
									data-user-login="<?php echo esc_attr( $user->user_login ); ?>"
									data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php
								printf(
									/* translators: %s: username */
									esc_html__( 'Force password change for %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									esc_html( $user->user_login )
								);
								?>
							</button>
							<span class="tsosk-ajax-msg"></span>
						</div>
					<?php endif; ?>
				</header>
				<?php if ( $is_self && $session_n ) : ?>
					<p class="tsosk-us-note">
						<?php esc_html_e( 'You can close your other sessions one by one. The session you are using now stays open, so you are not locked out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $session_n ) : ?>
					<ul class="tsosk-us-sessions">
						<?php foreach ( $sessions as $token => $session ) : ?>
							<?php
							$verifier   = (string) $token;
							$is_current = $is_self && '' !== $current_verifier && hash_equals( $current_verifier, $verifier );
							$protected  = $is_self && ( $is_current || '' === $current_verifier );
							$ua         = (string) ( $session['ua'] ?? '' );
							$is_mobile  = (bool) preg_match( '/Mobile|Android|iPhone|iPad/i', $ua );
							?>
							<li class="tsosk-us-session tsosk-users-session-row<?php echo $is_current ? ' is-current' : ''; ?>">
								<span class="dashicons <?php echo $is_mobile ? 'dashicons-smartphone' : 'dashicons-desktop'; ?>" aria-hidden="true"></span>
								<div class="tsosk-us-session-main">
									<span class="tsosk-us-session-ip"><?php echo esc_html( (string) ( $session['ip'] ?? '—' ) ); ?></span>
									<span class="tsosk-us-session-ua"><?php echo esc_html( wp_trim_words( $ua, 14, '…' ) ); ?></span>
								</div>
								<dl class="tsosk-us-session-meta">
									<div>
										<dt><?php echo esc_html( $label_login ); ?></dt>
										<dd><?php echo esc_html( isset( $session['login'] ) ? date_i18n( $datetime, (int) $session['login'] ) : '—' ); ?></dd>
									</div>
									<div>
										<dt><?php echo esc_html( $label_expires ); ?></dt>
										<dd><?php echo esc_html( isset( $session['expiration'] ) ? date_i18n( (string) get_option( 'date_format' ), (int) $session['expiration'] ) : '—' ); ?></dd>
									</div>
								</dl>
								<div class="tsosk-us-session-action">
									<?php if ( $protected ) : ?>
										<?php if ( $is_current ) : ?>
											<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'This session', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php else : ?>
											<span class="tsosk-badge"><?php esc_html_e( 'Own session', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<button type="button" class="button button-small button-link-delete tsosk-user-close-one-session"
												data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
												data-user-login="<?php echo esc_attr( $user->user_login ); ?>"
												data-token="<?php echo esc_attr( $verifier ); ?>"
												data-nonce="<?php echo esc_attr( $nonce ); ?>">
											<?php esc_html_e( 'Close session', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</article>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Render the All users card.
	 *
	 * @param WP_User[] $users Users.
	 * @param int       $total Site user count.
	 * @param string    $nonce Nonce.
	 * @param bool      $has_more Whether more users exist after the ones listed.
	 */
	private function render_directory_card( array $users, int $total, string $nonce, bool $has_more = false ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'All users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Choose a role, then apply it to the selected users. This replaces their current role. Your own account is skipped, and the last administrator cannot be demoted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-toolbar tsosk-users-directory-toolbar tsosk-users-directory-actions">
				<?php $this->render_role_filter( 'tsosk-users-role-filter', '', $nonce ); ?>
				<?php $this->render_role_controls( 'all', $nonce ); ?>
				<span class="tsosk-ajax-msg" id="tsosk-users-all-msg"></span>
			</div>
			<div class="tsosk-users-directory-search">
				<label class="screen-reader-text" for="tsosk-users-search"><?php esc_html_e( 'Search by name, username or email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<input type="search" id="tsosk-users-search" class="regular-text" autocomplete="off"
					placeholder="<?php esc_attr_e( 'Search by name, username or email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>" data-total="<?php echo esc_attr( (string) $total ); ?>">
			</div>
			<p class="tsosk-users-select-note" id="tsosk-users-select-note" hidden></p>
			<p class="description" id="tsosk-users-search-status" aria-live="polite"><?php echo esc_html( $this->directory_status_message( '', '', count( $users ), $has_more, $total ) ); ?></p>
			<div class="tsosk-table-wrap tsosk-users-directory-wrap">
				<table class="widefat tsosk-table tsosk-users-table tsosk-users-directory-table" id="tsosk-users-all-table">
					<thead><tr>
						<th class="tsosk-users-col-check" scope="col"><span class="tsosk-users-col-select tsosk-users-col-select-static tsosk-users-col-select-check"><input type="checkbox" class="tsosk-users-select-all" aria-label="<?php esc_attr_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"></span></th>
						<th class="tsosk-users-col-user" scope="col" aria-sort="none"><button type="button" class="tsosk-users-col-select" data-sort="login"><span class="tsosk-users-col-label"><?php esc_html_e( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span><span class="tsosk-sort-ind" aria-hidden="true"></span></button></th>
						<th class="tsosk-users-col-email" scope="col" aria-sort="none"><button type="button" class="tsosk-users-col-select" data-sort="email"><span class="tsosk-users-col-label"><?php esc_html_e( 'Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span><span class="tsosk-sort-ind" aria-hidden="true"></span></button></th>
						<th class="tsosk-users-col-role" scope="col" aria-sort="none"><button type="button" class="tsosk-users-col-select" data-sort="role"><span class="tsosk-users-col-label"><?php esc_html_e( 'Role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span><span class="tsosk-sort-ind" aria-hidden="true"></span></button></th>
						<th class="tsosk-users-col-registered" scope="col" aria-sort="none"><button type="button" class="tsosk-users-col-select" data-sort="registered"><span class="tsosk-users-col-label"><?php esc_html_e( 'Registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span><span class="tsosk-sort-ind" aria-hidden="true"></span></button></th>
						<th class="tsosk-users-col-last" scope="col" aria-sort="none"><button type="button" class="tsosk-users-col-select" data-sort="last"><span class="tsosk-users-col-label"><?php esc_html_e( 'Last login', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span><span class="tsosk-sort-ind" aria-hidden="true"></span></button></th>
						<th class="tsosk-users-col-actions" scope="col"><span class="tsosk-users-col-select tsosk-users-col-select-static"><span class="tsosk-users-col-label"><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span></span></th>
					</tr></thead>
					<tbody id="tsosk-users-all-body">
						<?php echo $this->render_directory_rows( $users, $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_directory_rows(). ?>
					</tbody>
				</table>
			</div>
			<p class="tsosk-users-more-wrap">
				<button type="button" class="button" id="tsosk-users-more" data-nonce="<?php echo esc_attr( $nonce ); ?>" <?php echo $has_more ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Show more', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render inactive card.
	 *
	 * @param WP_User[] $users Inactive users.
	 * @param string    $nonce Nonce.
	 */
	private function render_inactive_card( array $users, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Inactive users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Based on last successful login (or registration date if never logged in). Administrators are never deleted or demoted from this list. If an account owns posts, choose who should receive them before deleting; otherwise that account is kept.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p class="tsosk-users-days-row">
				<label for="tsosk-users-inactive-days"><?php esc_html_e( 'Inactive for', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<select id="tsosk-users-inactive-days" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php foreach ( array( 30, 60, 90, 180, 365 ) as $days ) : ?>
						<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( 90, $days ); ?>>
							<?php
							printf(
								/* translators: %d: number of days */
								esc_html__( '%d days', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $days
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<div id="tsosk-users-inactive-body">
				<?php $this->render_inactive_body( $users, $nonce ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Toolbar and table for the inactive list.
	 *
	 * @param array<int,object> $users Inactive users.
	 * @param string            $nonce Nonce.
	 */
	private function render_inactive_body( array $users, string $nonce ): void {
		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'No inactive users found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
			return;
		}
		$ids = array();
		foreach ( $users as $user ) {
			$ids[] = (int) $user->ID;
		}
		update_meta_cache( 'user', $ids );
		global $wpdb;
		$cap_key      = $wpdb->get_blog_prefix() . 'capabilities';
		$label_select = __( 'Select', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_user   = __( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_email  = __( 'Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$label_last   = __( 'Last activity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		?>
		<div class="tsosk-toolbar tsosk-users-directory-toolbar">
			<input type="hidden" id="tsosk-users-bulk-nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<?php $this->render_role_controls( 'inactive', $nonce ); ?>
			<label class="screen-reader-text" for="tsosk-users-reassign"><?php esc_html_e( 'Assign content to…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
			<select id="tsosk-users-reassign">
				<option value=""><?php esc_html_e( 'Assign content to…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				<?php foreach ( $this->content_recipients() as $recipient ) : ?>
					<option value="<?php echo esc_attr( (string) $recipient->ID ); ?>">
						<?php
						echo esc_html(
							( $recipient->display_name && $recipient->display_name !== $recipient->user_login )
								? $recipient->display_name . ' (' . $recipient->user_login . ')'
								: $recipient->user_login
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button button-link-delete" id="tsosk-users-bulk-delete" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-users-bulk-msg"></span>
		</div>
		<div class="tsosk-table-wrap">
		<table class="widefat tsosk-table tsosk-users-table tsosk-users-stack-table" id="tsosk-users-inactive-table">
			<thead><tr>
				<th scope="col"><input type="checkbox" class="tsosk-users-select-all" aria-label="<?php esc_attr_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"></th>
				<th scope="col"><?php esc_html_e( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last activity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$last = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
					if ( ! $last ) {
						$last = strtotime( (string) $user->user_registered );
					}
					$caps  = get_user_meta( $user->ID, $cap_key, true );
					$slugs = array();
					if ( is_array( $caps ) ) {
						foreach ( $caps as $slug => $granted ) {
							if ( $granted ) {
								$slugs[] = sanitize_key( (string) $slug );
							}
						}
					}
					?>
					<tr>
						<td data-label="<?php echo esc_attr( $label_select ); ?>"><input type="checkbox" class="tsosk-users-cb" value="<?php echo esc_attr( (string) $user->ID ); ?>" data-roles="<?php echo esc_attr( implode( ',', $slugs ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( $label_user ); ?>"><code><?php echo esc_html( $user->user_login ); ?></code></td>
						<td data-label="<?php echo esc_attr( $label_email ); ?>"><?php echo esc_html( $user->user_email ); ?></td>
						<td data-label="<?php echo esc_attr( $label_last ); ?>"><?php echo esc_html( date_i18n( (string) get_option( 'date_format' ), (int) $last ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Render history card.
	 *
	 * @param array<int,array<string,mixed>> $history Login history rows.
	 * @param string                         $nonce   Nonce.
	 */
	private function render_history_card( array $history, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Login History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-toolbar tsosk-users-directory-toolbar">
				<label class="screen-reader-text" for="tsosk-users-history-search"><?php esc_html_e( 'Search username or IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<input type="search" id="tsosk-users-history-search" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Search username or IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				<label class="screen-reader-text" for="tsosk-users-history-status"><?php esc_html_e( 'All statuses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<select id="tsosk-users-history-status">
					<option value=""><?php esc_html_e( 'All statuses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				</select>
				<button type="button" class="button button-link-delete" id="tsosk-users-clear-history" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear history', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-users-history-msg"></span>
			</div>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No login events recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-users-table tsosk-users-stack-table" id="tsosk-users-history-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php
						$label_time   = __( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						$label_user   = __( 'Username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						$label_ip     = __( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						$label_status = __( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						foreach ( $history as $row ) :
							?>
						<tr class="tsosk-users-history-row" data-status="<?php echo esc_attr( 'success' === ( $row['status'] ?? '' ) ? 'success' : 'failed' ); ?>">
							<td data-label="<?php echo esc_attr( $label_time ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $row['time'] ?? 0 ) ) ); ?></td>
							<td class="tsosk-code" data-label="<?php echo esc_attr( $label_user ); ?>"><?php echo esc_html( (string) ( $row['username'] ?? '' ) ); ?></td>
							<td class="tsosk-code" data-label="<?php echo esc_attr( $label_ip ); ?>"><?php echo esc_html( (string) ( $row['ip'] ?? '' ) ); ?></td>
							<td data-label="<?php echo esc_attr( $label_status ); ?>">
								<?php if ( 'success' === ( $row['status'] ?? '' ) ) : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Success', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Failed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
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