<?php
/**
 * TSO Swiss Knife – Module: Email Diagnostics.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Email
 */
class TSOSK_Mod_Email {

	/** Plugin option storing last email diagnostics. */
	private const OPTION = 'tsosk_email_diagnostics';

	/** @var TSOSK_Mod_Email|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_email_send_test', array( $this, 'ajax_send_test' ) );
	}

	/**
	 * AJAX: send test email.
	 */
	public function ajax_send_test(): void {
		check_ajax_referer( 'tsosk_email_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Enter a valid email address.', 'tso-swiss-knife' ) );
		}

		$error = null;
		add_action(
			'wp_mail_failed',
			static function ( WP_Error $wp_error ) use ( &$error ): void {
				$error = $wp_error;
			}
		);

		$sent = wp_mail(
			$email,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] TSO Swiss Knife email test', 'tso-swiss-knife' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			__( 'This is a test email sent from TSO Swiss Knife Email Diagnostics.', 'tso-swiss-knife' )
		);

		$result = array(
			'email'   => $email,
			'sent'    => (bool) $sent,
			'time'    => time(),
			'message' => $error instanceof WP_Error ? $error->get_error_message() : ( $sent ? __( 'Test email sent.', 'tso-swiss-knife' ) : __( 'wp_mail returned false.', 'tso-swiss-knife' ) ),
		);
		update_option( self::OPTION, $result, false );

		if ( ! $sent ) {
			wp_send_json_error( $result['message'] );
		}
		wp_send_json_success( $result['message'] );
	}

	/**
	 * Render email diagnostics.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_email_nonce' );
		$last = get_option( self::OPTION, array() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inspecting WordPress core mail filter output.
		$from_email = apply_filters( 'wp_mail_from', 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inspecting WordPress core mail filter output.
		$from_name = apply_filters( 'wp_mail_from_name', 'WordPress' );
		$smtp_plugins = $this->detect_smtp_plugins();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect and test hidden WordPress email behavior: wp_mail sender, SMTP plugins and delivery result.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Mail Configuration', 'tso-swiss-knife' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr><th><?php esc_html_e( 'wp_mail From Email', 'tso-swiss-knife' ); ?></th><td><code><?php echo esc_html( $from_email ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'wp_mail From Name', 'tso-swiss-knife' ); ?></th><td><code><?php echo esc_html( $from_name ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Detected SMTP plugins', 'tso-swiss-knife' ); ?></th><td><code><?php echo esc_html( empty( $smtp_plugins ) ? __( 'None detected', 'tso-swiss-knife' ) : implode( ', ', $smtp_plugins ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'PHP mail function', 'tso-swiss-knife' ); ?></th><td><code><?php echo esc_html( function_exists( 'mail' ) ? __( 'Available', 'tso-swiss-knife' ) : __( 'Disabled', 'tso-swiss-knife' ) ); ?></code></td></tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Send Test Email', 'tso-swiss-knife' ); ?></h3>
			<input type="email" id="tsosk-email-test-address" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
			<button class="button button-primary" id="tsosk-email-send-test" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Send Test', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-email-msg"></span>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Last Test Result', 'tso-swiss-knife' ); ?></h3>
			<?php if ( empty( $last ) || ! is_array( $last ) ) : ?>
				<p><?php esc_html_e( 'No test email has been sent yet.', 'tso-swiss-knife' ); ?></p>
			<?php else : ?>
				<table class="tsosk-kv-table">
					<tr><th><?php esc_html_e( 'Email', 'tso-swiss-knife' ); ?></th><td><code><?php echo esc_html( $last['email'] ?? '' ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'tso-swiss-knife' ); ?></th><td><?php echo ! empty( $last['sent'] ) ? esc_html__( 'Sent', 'tso-swiss-knife' ) : esc_html__( 'Failed', 'tso-swiss-knife' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Message', 'tso-swiss-knife' ); ?></th><td><?php echo esc_html( $last['message'] ?? '' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Time', 'tso-swiss-knife' ); ?></th><td><?php echo ! empty( $last['time'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $last['time'] ) ) ) : ''; ?></td></tr>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Detect active SMTP plugins by folder/header heuristics.
	 *
	 * @return array<int,string>
	 */
	private function detect_smtp_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		$plugins = get_plugins();
		$out = array();
		foreach ( $active as $plugin_file ) {
			$name = $plugins[ $plugin_file ]['Name'] ?? $plugin_file;
			if ( preg_match( '/smtp|mailgun|sendgrid|postmark|mailpoet|brevo|ses/i', $name . ' ' . $plugin_file ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}
}
