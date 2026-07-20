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

	/** @var TSOSK_Mod_Users|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_user_destroy_sessions',   array( $this, 'ajax_destroy_sessions' ) );
		add_action( 'wp_ajax_tsosk_user_destroy_one_session', array( $this, 'ajax_destroy_one_session' ) );
		add_action( 'wp_ajax_tsosk_user_bulk_inactive',       array( $this, 'ajax_bulk_inactive' ) );
		add_action( 'wp_ajax_tsosk_user_force_password',      array( $this, 'ajax_force_password' ) );
		add_action( 'wp_ajax_tsosk_users_clear_history',      array( $this, 'ajax_clear_history' ) );
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
	 * @param string   $username Username attempted.
	 * @param WP_Error $error    Error object.
	 */
	public function on_login_failed( string $username, WP_Error $error ): void {
		$skip = array( 'tsosk_2fa_required', 'tsosk_2fa_invalid', 'tsosk_role_ip_denied', 'tsosk_locked_out' );
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
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
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
	 * @return array<int,array<string,mixed>>
	 */
	private function get_login_history(): array {
		$history = get_option( self::OPTION_LOGIN_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * @return string
	 */
	private function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
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
		$users  = get_users(
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
		$out = array();
		foreach ( $users as $user ) {
			$last = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
			if ( ! $last ) {
				$last = strtotime( $user->user_registered );
			}
			if ( $last < $cutoff ) {
				$out[] = $user;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

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
		if ( get_current_user_id() === $user_id ) {
			wp_send_json_error( __( 'You cannot close your own sessions from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		if ( ! $manager->verify( $token ) ) {
			wp_send_json_error( __( 'Session not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$manager->destroy( $token );
		wp_send_json_success( __( 'Session closed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function ajax_bulk_inactive(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$days   = isset( $_POST['days'] ) ? max( 30, min( 365, absint( wp_unslash( $_POST['days'] ) ) ) ) : 90;
		$ids    = array();
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

		$count = 0;
		foreach ( $ids as $user_id ) {
			if ( get_current_user_id() === $user_id ) {
				continue;
			}
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}
			$last = (int) get_user_meta( $user_id, self::META_LAST_LOGIN, true );
			if ( ! $last ) {
				$last = strtotime( $user->user_registered );
			}
			if ( $last >= time() - ( $days * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( 'delete' === $action ) {
				if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
					continue;
				}
				if ( wp_delete_user( $user_id ) ) {
					$count++;
				}
			} else {
				if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
					continue;
				}
				$user->set_role( 'subscriber' );
				$count++;
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
		wp_send_json_success(
			sprintf(
				/* translators: %d: number of users */
				__( '%d users processed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			)
		);
	}

	public function ajax_force_password(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_error( __( 'Invalid user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( get_current_user_id() === $user_id ) {
			wp_send_json_error( __( 'You cannot force a password change on your own account from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
			wp_send_json_error( __( 'Administrators cannot be forced to change their password from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		update_user_meta( $user_id, self::META_FORCE_PASSWORD, '1' );
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		TSOSK_Activity_Log::log(
			'users',
			'update',
			sprintf(
				/* translators: %d: user ID */
				__( 'Password change forced for user #%d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user_id
			)
		);
		wp_send_json_success( __( 'User must change password on next login.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function ajax_clear_history(): void {
		check_ajax_referer( 'tsosk_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		delete_option( self::OPTION_LOGIN_HISTORY );
		wp_send_json_success( __( 'Login history cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		$nonce           = wp_create_nonce( 'tsosk_users_nonce' );
		$admin_users     = get_users(
			array(
				'role'   => 'administrator',
				'number' => 50,
				'fields' => array( 'ID', 'user_login', 'user_email', 'user_registered' ),
			)
		);
		$inactive_users  = $this->get_inactive_users( 90, 100 );
		$login_history   = array_slice( $this->get_login_history(), 0, 50 );
		$total_users     = count_users()['total_users'] ?? 0;
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect sessions, login history, inactive accounts and manage user security actions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'User Summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr><th><?php esc_html_e( 'Total Users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) $total_users ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Administrators', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) count( $admin_users ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Inactive (90+ days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) count( $inactive_users ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Login history entries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) count( $this->get_login_history() ) ); ?></code></td></tr>
			</table>
		</div>

		<?php $this->render_sessions_card( $admin_users, $nonce ); ?>
		<?php $this->render_inactive_card( $inactive_users, $nonce ); ?>
		<?php $this->render_history_card( $login_history, $nonce ); ?>
		<?php
	}

	/**
	 * @param WP_User[] $users Users.
	 * @param string    $nonce Nonce.
	 */
	private function render_sessions_card( array $users, string $nonce ): void {
		$current_user_id = get_current_user_id();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Administrator Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Session tokens stored by WordPress. The same administrator can have several open sessions at once (different browser, device, IP or “Remember me” login). That is normal — it is not a duplicate bug.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $users ) ) : ?>
				<p><?php esc_html_e( 'No users found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$sessions  = WP_Session_Tokens::get_instance( $user->ID )->get_all();
					$last      = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
					$is_self   = ( $current_user_id === (int) $user->ID );
					$session_n = count( $sessions );
					?>
					<div class="tsosk-users-session-block" style="margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #dcdcde;">
						<p style="margin:0 0 8px;">
							<strong><code><?php echo esc_html( $user->user_login ); ?></code></strong>
							<span class="description">
								—
								<?php
								printf(
									/* translators: %d: number of active sessions */
									esc_html( _n( '%d active session', '%d active sessions', $session_n, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
									(int) $session_n
								);
								?>
							</span>
							<?php if ( $last ) : ?>
								<span class="description"> — <?php esc_html_e( 'Last login:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ); ?></span>
							<?php endif; ?>
							<?php if ( $is_self ) : ?>
								<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;"><?php esc_html_e( 'You (this account)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php else : ?>
								<button type="button" class="button button-small tsosk-user-close-sessions" style="margin-left:8px;"
								        data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Close all sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<button type="button" class="button button-small tsosk-user-force-pwd"
								        data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Force password change', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
							<?php endif; ?>
							<span class="tsosk-ajax-msg"></span>
						</p>
						<?php if ( $is_self ) : ?>
							<p class="description">
								<?php esc_html_e( 'For safety, you cannot close your own sessions from this screen (that could lock you out). Use another admin account to close them, or log out elsewhere / clear cookies on those devices.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</p>
						<?php endif; ?>
						<?php if ( empty( $sessions ) ) : ?>
							<p class="description"><?php esc_html_e( 'No active sessions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php else : ?>
							<table class="widefat tsosk-table">
								<thead><tr>
									<th><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'User agent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Login time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Expires', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( $sessions as $token => $session ) : ?>
									<tr>
										<td class="tsosk-code"><?php echo esc_html( (string) ( $session['ip'] ?? '—' ) ); ?></td>
										<td class="description" style="max-width:220px;word-break:break-word;"><?php echo esc_html( wp_trim_words( (string) ( $session['ua'] ?? '' ), 12, '…' ) ); ?></td>
										<td><?php echo esc_html( isset( $session['login'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $session['login'] ) : '—' ); ?></td>
										<td><?php echo esc_html( isset( $session['expiration'] ) ? date_i18n( get_option( 'date_format' ), (int) $session['expiration'] ) : '—' ); ?></td>
										<td>
											<?php if ( ! $is_self ) : ?>
											<button type="button" class="button button-small button-link-delete tsosk-user-close-one-session"
											        data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
											        data-token="<?php echo esc_attr( (string) $token ); ?>"
											        data-nonce="<?php echo esc_attr( $nonce ); ?>">
												<?php esc_html_e( 'Close', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
											</button>
											<?php else : ?>
												<span class="description"><?php esc_html_e( 'Own session', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param WP_User[] $users Inactive users.
	 * @param string    $nonce Nonce.
	 */
	private function render_inactive_card( array $users, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Inactive Users (90+ days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Based on last successful login (or registration date if never logged in). Administrators are never deleted in bulk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( empty( $users ) ) : ?>
				<p><?php esc_html_e( 'No inactive users found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-toolbar">
					<input type="hidden" id="tsosk-users-bulk-nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<button type="button" class="button" id="tsosk-users-bulk-subscriber">
						<?php esc_html_e( 'Set selected to Subscriber', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<button type="button" class="button button-link-delete" id="tsosk-users-bulk-delete">
						<?php esc_html_e( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-users-bulk-msg"></span>
				</div>
				<table class="widefat tsosk-table" id="tsosk-users-inactive-table">
					<thead><tr>
						<th><input type="checkbox" id="tsosk-users-select-all"></th>
						<th><?php esc_html_e( 'User', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Last activity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$last = (int) get_user_meta( $user->ID, self::META_LAST_LOGIN, true );
							if ( ! $last ) {
								$last = strtotime( $user->user_registered );
							}
							?>
							<tr>
								<td><input type="checkbox" class="tsosk-users-inactive-cb" value="<?php echo esc_attr( (string) $user->ID ); ?>"></td>
								<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
								<td><?php echo esc_html( $user->user_email ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), $last ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $history Login history rows.
	 * @param string                         $nonce   Nonce.
	 */
	private function render_history_card( array $history, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Login History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-toolbar">
				<button type="button" class="button button-link-delete" id="tsosk-users-clear-history" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear history', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-users-history-msg"></span>
			</div>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No login events recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Username', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $row['time'] ?? 0 ) ) ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( (string) ( $row['username'] ?? '' ) ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( (string) ( $row['ip'] ?? '' ) ); ?></td>
							<td>
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
			<?php endif; ?>
		</div>
		<?php
	}
}
