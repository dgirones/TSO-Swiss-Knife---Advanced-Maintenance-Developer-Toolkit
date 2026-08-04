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

	/** Plugin option storing the latest test result (legacy + quick read). */
	private const OPTION = 'tsosk_email_diagnostics';

	/** Plugin option storing recent test history. */
	private const OPTION_HISTORY = 'tsosk_email_diagnostics_history';

	/** Max stored test results. */
	private const HISTORY_LIMIT = 10;

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
	 * AJAX: send test email (plain or HTML, optional CC) and capture transport details.
	 */
	public function ajax_send_test(): void {
		check_ajax_referer( 'tsosk_email_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Enter a valid email address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$cc_raw = isset( $_POST['cc'] ) ? sanitize_text_field( wp_unslash( $_POST['cc'] ) ) : '';
		$cc     = '';
		if ( '' !== $cc_raw ) {
			$cc = sanitize_email( $cc_raw );
			if ( ! is_email( $cc ) ) {
				wp_send_json_error( __( 'Enter a valid CC email address, or leave CC empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'plain';
		if ( ! in_array( $format, array( 'plain', 'html' ), true ) ) {
			$format = 'plain';
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] TSO Swiss Knife email test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$site_name
		);
		$plain_body = __( 'This is a test email sent from TSO Swiss Knife Email Diagnostics.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$html_body  = '<p>' . esc_html( $plain_body ) . '</p><p><em>' . esc_html__( 'HTML format test.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</em></p>';

		$captured = array(
			'to'        => '',
			'subject'   => '',
			'headers'   => array(),
			'from'      => '',
			'phpmailer' => array(),
		);

		$capture_mail = static function ( $args ) use ( &$captured ) {
			if ( ! is_array( $args ) ) {
				return $args;
			}
			$to = $args['to'] ?? '';
			if ( is_array( $to ) ) {
				$to = implode( ', ', array_map( 'strval', $to ) );
			}
			$captured['to']      = sanitize_text_field( (string) $to );
			$captured['subject'] = sanitize_text_field( (string) ( $args['subject'] ?? '' ) );
			$headers               = $args['headers'] ?? array();
			if ( is_string( $headers ) ) {
				$headers = preg_split( '/\r\n|\r|\n/', $headers ) ?: array();
			}
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_line ) {
					$header_line = sanitize_text_field( (string) $header_line );
					if ( '' === $header_line ) {
						continue;
					}
					$captured['headers'][] = $header_line;
					if ( 0 === stripos( $header_line, 'From:' ) ) {
						$captured['from'] = trim( substr( $header_line, 5 ) );
					}
				}
			}
			return $args;
		};

		$capture_phpmailer = static function ( $phpmailer ) use ( &$captured, $plain_body, $format ): void {
			if ( ! is_object( $phpmailer ) ) {
				return;
			}
			if ( 'html' === $format && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = $plain_body;
			}
			$captured['phpmailer'] = array(
				'mailer'      => sanitize_text_field( (string) ( $phpmailer->Mailer ?? '' ) ),
				'host'        => sanitize_text_field( (string) ( $phpmailer->Host ?? '' ) ),
				'port'        => absint( $phpmailer->Port ?? 0 ),
				'smtp_auth'   => ! empty( $phpmailer->SMTPAuth ),
				'smtp_secure' => sanitize_text_field( (string) ( $phpmailer->SMTPSecure ?? '' ) ),
				'from'        => sanitize_email( (string) ( $phpmailer->From ?? '' ) ),
				'from_name'   => sanitize_text_field( (string) ( $phpmailer->FromName ?? '' ) ),
			);
			if ( '' === $captured['from'] && ! empty( $captured['phpmailer']['from'] ) ) {
				$captured['from'] = (string) $captured['phpmailer']['from'];
			}
		};

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Temporary capture around wp_mail.
		add_filter( 'wp_mail', $capture_mail, 9999 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Temporary capture around wp_mail.
		add_action( 'phpmailer_init', $capture_phpmailer, 9999 );

		$error = null;
		$on_mail_failed = static function ( WP_Error $wp_error ) use ( &$error ): void {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $on_mail_failed );

		$headers = array();
		if ( 'html' === $format ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		if ( '' !== $cc ) {
			$headers[] = 'Cc: ' . $cc;
		}

		$sent = wp_mail(
			$email,
			$subject,
			'html' === $format ? $html_body : $plain_body,
			$headers
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		remove_filter( 'wp_mail', $capture_mail, 9999 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		remove_action( 'phpmailer_init', $capture_phpmailer, 9999 );
		remove_action( 'wp_mail_failed', $on_mail_failed );

		$result = array(
			'email'     => $email,
			'cc'        => $cc,
			'format'    => $format,
			'sent'      => (bool) $sent,
			'time'      => time(),
			'message'   => $error instanceof WP_Error
				? $error->get_error_message()
				: ( $sent
					? __( 'Test email sent.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'wp_mail returned false.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			'to'        => $captured['to'] !== '' ? $captured['to'] : $email,
			'subject'   => $captured['subject'] !== '' ? $captured['subject'] : $subject,
			'from'      => $captured['from'],
			'headers'   => array_values( array_unique( $captured['headers'] ) ),
			'phpmailer' => is_array( $captured['phpmailer'] ) ? $captured['phpmailer'] : array(),
		);

		$this->store_test_result( $result );

		TSOSK_Activity_Log::log(
			'email',
			$sent ? 'send' : 'error',
			$sent
				? __( 'Email diagnostics: test message sent.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Email diagnostics: test message failed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array(
				'email'  => $email,
				'format' => $format,
			)
		);

		$nonce = wp_create_nonce( 'tsosk_email_nonce' );
		$payload = array(
			'message' => $result['message'],
			'html'    => $this->render_results_html( $nonce ),
		);

		if ( ! $sent ) {
			wp_send_json_error( $payload );
		}
		wp_send_json_success( $payload );
	}

	/**
	 * Render email diagnostics.
	 */
	public function render(): void {
		$nonce        = wp_create_nonce( 'tsosk_email_nonce' );
		$from_email   = $this->get_filtered_from_email();
		$from_name    = $this->get_filtered_from_name();
		$smtp_plugins = $this->detect_smtp_plugins();
		$domain_warn  = $this->get_from_domain_warning( $from_email );
		$history      = $this->get_history();
		$last_pm      = array();
		if ( ! empty( $history[0]['phpmailer'] ) && is_array( $history[0]['phpmailer'] ) ) {
			$last_pm = $history[0]['phpmailer'];
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect and test hidden WordPress email behavior: wp_mail sender, SMTP plugins, PHPMailer transport and delivery result.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Mail Configuration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'wp_mail From Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $from_email ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'wp_mail From Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $from_name ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Site domain', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $this->get_site_mail_domain() ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'PHP mail function', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( function_exists( 'mail' ) ? __( 'Available', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></code></td>
				</tr>
				<?php if ( ! empty( $last_pm ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Last PHPMailer transport', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $this->format_phpmailer_summary( $last_pm ) ); ?></code></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( '' !== $domain_warn ) : ?>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-top:12px;">
				<?php echo esc_html( $domain_warn ); ?>
			</div>
			<?php endif; ?>

			<h4 style="margin-top:16px;"><?php esc_html_e( 'Detected SMTP / mail plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
			<?php if ( empty( $smtp_plugins ) ) : ?>
				<p class="description"><?php esc_html_e( 'None detected. WordPress will use PHP mail() or whatever another plugin configures via phpmailer_init.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="tsosk-email-smtp-list">
					<?php foreach ( $smtp_plugins as $plugin ) : ?>
					<li>
						<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
						<?php if ( ! empty( $plugin['settings_url'] ) ) : ?>
							— <a href="<?php echo esc_url( $plugin['settings_url'] ); ?>"><?php esc_html_e( 'Open settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Send Test Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Sends a real message through wp_mail and captures To / Subject / From headers plus PHPMailer host/port (never passwords).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-email-test-fields">
				<div class="tsosk-field-row">
					<label for="tsosk-email-test-address"><strong><?php esc_html_e( 'Recipient (To)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
					<input type="email" id="tsosk-email-test-address" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				</div>
				<div class="tsosk-field-row">
					<label for="tsosk-email-test-cc"><strong><?php esc_html_e( 'CC (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
					<input type="email" id="tsosk-email-test-cc" class="regular-text" value="" placeholder="<?php esc_attr_e( 'optional@example.com', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				</div>
				<div class="tsosk-field-row">
					<strong><?php esc_html_e( 'Format', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<label class="tsosk-radio-row" style="display:inline-flex;margin-right:16px;">
						<input type="radio" name="tsosk_email_format" id="tsosk-email-format-plain" value="plain" checked>
						<?php esc_html_e( 'Plain text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</label>
					<label class="tsosk-radio-row" style="display:inline-flex;">
						<input type="radio" name="tsosk_email_format" id="tsosk-email-format-html" value="html">
						<?php esc_html_e( 'HTML + plain alternative', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</label>
				</div>
			</div>
			<p>
				<button class="button button-primary" id="tsosk-email-send-test" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Send Test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-email-msg"></span>
			</p>
		</div>

		<div id="tsosk-email-results">
			<?php echo $this->render_results_html( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
		<?php
	}

	/**
	 * Render last result, history and copy-report block.
	 *
	 * @param string $nonce AJAX nonce for copy UI.
	 * @return string
	 */
	private function render_results_html( string $nonce ): string {
		$history = $this->get_history();
		$last    = $history[0] ?? $this->get_last_legacy();
		$report  = $this->build_support_report( $last, $history );

		ob_start();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Last Test Result', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( empty( $last ) || ! is_array( $last ) ) : ?>
				<p><?php esc_html_e( 'No test email has been sent yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<?php echo $this->render_result_table( $last ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>

		<?php if ( count( $history ) > 1 ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recent tests', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php
				printf(
					/* translators: %d: history limit */
					esc_html__( 'Keeps the last %d test attempts (newest first).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					absint( self::HISTORY_LIMIT )
				);
				?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-email-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Recipient (To)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Format', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Transport', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo ! empty( $row['time'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $row['time'] ) ) ) : '—'; ?></td>
							<td><code><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( 'html' === ( $row['format'] ?? '' ) ? __( 'HTML', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Plain text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td>
								<span class="tsosk-badge <?php echo ! empty( $row['sent'] ) ? 'tsosk-badge-ok' : 'tsosk-badge-warn'; ?>">
									<?php echo ! empty( $row['sent'] ) ? esc_html__( 'Sent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Failed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</span>
							</td>
							<td><code><?php echo esc_html( $this->format_phpmailer_summary( is_array( $row['phpmailer'] ?? null ) ? $row['phpmailer'] : array() ) ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Support report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Copy this summary when asking for help with email delivery. It never includes SMTP passwords.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<textarea id="tsosk-email-report" class="large-text code tsosk-email-report" rows="12" readonly><?php echo esc_textarea( $report ); ?></textarea>
			<p>
				<button type="button" class="button" id="tsosk-email-copy-report" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Copy report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-email-copy-msg"></span>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $result Test result.
	 * @return string
	 */
	private function render_result_table( array $result ): string {
		$pm = is_array( $result['phpmailer'] ?? null ) ? $result['phpmailer'] : array();
		ob_start();
		?>
		<table class="tsosk-kv-table">
			<tr><th><?php esc_html_e( 'Recipient (To)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) ( $result['to'] ?? $result['email'] ?? '' ) ); ?></code></td></tr>
			<?php if ( ! empty( $result['cc'] ) ) : ?>
			<tr><th><?php esc_html_e( 'CC', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) $result['cc'] ); ?></code></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Subject', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) ( $result['subject'] ?? '' ) ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'From (captured)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( (string) ( $result['from'] ?? ( $pm['from'] ?? '' ) ) ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Format', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><?php echo esc_html( 'html' === ( $result['format'] ?? '' ) ? __( 'HTML + plain alternative', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Plain text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><?php echo ! empty( $result['sent'] ) ? esc_html__( 'Sent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Failed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Message', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><?php echo ! empty( $result['time'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $result['time'] ) ) ) : ''; ?></td></tr>
			<tr><th><?php esc_html_e( 'PHPMailer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><td><code><?php echo esc_html( $this->format_phpmailer_summary( $pm ) ); ?></code></td></tr>
			<?php if ( ! empty( $result['headers'] ) && is_array( $result['headers'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Headers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<td><code class="tsosk-email-headers"><?php echo esc_html( implode( "\n", array_map( 'strval', $result['headers'] ) ) ); ?></code></td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return string
	 */
	private function get_filtered_from_email(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inspecting WordPress core mail filter output.
		return (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host );
	}

	/**
	 * @return string
	 */
	private function get_filtered_from_name(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inspecting WordPress core mail filter output.
		return (string) apply_filters( 'wp_mail_from_name', 'WordPress' );
	}

	/**
	 * @return string
	 */
	private function get_site_mail_domain(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return $this->normalize_mail_domain( $host );
	}

	/**
	 * Warn when From address domain does not match the site domain (SPF/DMARC tip).
	 *
	 * @param string $from_email From address.
	 * @return string Empty when OK.
	 */
	private function get_from_domain_warning( string $from_email ): string {
		$from_email = sanitize_email( $from_email );
		if ( ! is_email( $from_email ) ) {
			return '';
		}
		$parts = explode( '@', $from_email );
		$from_domain = $this->normalize_mail_domain( (string) end( $parts ) );
		$site_domain = $this->get_site_mail_domain();
		if ( '' === $from_domain || '' === $site_domain ) {
			return '';
		}
		if ( $from_domain === $site_domain || $this->domain_is_subdomain_of( $from_domain, $site_domain ) || $this->domain_is_subdomain_of( $site_domain, $from_domain ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: from email domain, 2: site domain */
			__( 'Warning: the From address uses “%1$s” but this site is “%2$s”. Many hosts and spam filters reject or flag mail when SPF/DKIM/DMARC are not aligned. Prefer a From address on your site domain, or send through a verified SMTP provider.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$from_domain,
			$site_domain
		);
	}

	/**
	 * @param string $domain Raw host.
	 * @return string
	 */
	private function normalize_mail_domain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );
		if ( ! is_string( $domain ) ) {
			return '';
		}
		if ( 0 === strpos( $domain, 'www.' ) ) {
			$domain = substr( $domain, 4 );
		}
		return $domain;
	}

	/**
	 * @param string $child  Possible subdomain.
	 * @param string $parent Parent domain.
	 * @return bool
	 */
	private function domain_is_subdomain_of( string $child, string $parent ): bool {
		return $child !== $parent && substr( $child, - ( strlen( $parent ) + 1 ) ) === '.' . $parent;
	}

	/**
	 * Detect active SMTP plugins with optional settings links.
	 *
	 * @return array<int,array{name:string,file:string,settings_url:string}>
	 */
	private function detect_smtp_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = get_plugins();
		$known   = $this->known_smtp_plugin_map();
		$out     = array();
		$seen    = array();

		foreach ( $active as $plugin_file ) {
			$name = (string) ( $plugins[ $plugin_file ]['Name'] ?? $plugin_file );
			$folder = strpos( $plugin_file, '/' ) !== false ? dirname( $plugin_file ) : $plugin_file;
			$haystack = strtolower( $name . ' ' . $plugin_file );

			$matched = false;
			$settings = '';
			foreach ( $known as $slug => $meta ) {
				$needle = strtolower( (string) ( $meta['match'] ?? $slug ) );
				if ( false !== strpos( $haystack, $needle ) || $folder === $slug ) {
					$matched = true;
					if ( ! empty( $meta['page'] ) ) {
						$settings = admin_url( (string) $meta['page'] );
					}
					break;
				}
			}

			if ( ! $matched && preg_match( '/smtp|mailgun|sendgrid|postmark|mailpoet|brevo|ses|phpmailer|sparkpost|mailjet|elastic.?email|amazon.?ses|offload.?ses|fluent.?smtp|post.?smtp|easy.?wp.?smtp|gmail.?smtp|wp.?mail.?smtp/i', $haystack ) ) {
				$matched = true;
			}

			if ( ! $matched ) {
				continue;
			}
			$key = strtolower( $plugin_file );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = array(
				'name'         => $name,
				'file'         => $plugin_file,
				'settings_url' => $settings,
			);
		}

		return $out;
	}

	/**
	 * Known SMTP plugins → admin settings page.
	 *
	 * @return array<string,array{match:string,page:string}>
	 */
	private function known_smtp_plugin_map(): array {
		return array(
			'wp-mail-smtp'           => array( 'match' => 'wp-mail-smtp', 'page' => 'admin.php?page=wp-mail-smtp' ),
			'wp-mail-smtp-pro'       => array( 'match' => 'wp-mail-smtp', 'page' => 'admin.php?page=wp-mail-smtp' ),
			'easy-wp-smtp'           => array( 'match' => 'easy-wp-smtp', 'page' => 'options-general.php?page=easy-wp-smtp' ),
			'fluent-smtp'            => array( 'match' => 'fluent-smtp', 'page' => 'options-general.php?page=fluent-mail' ),
			'post-smtp'              => array( 'match' => 'post-smtp', 'page' => 'admin.php?page=postman_email_log' ),
			'post-smtp-mailer'       => array( 'match' => 'post smtp', 'page' => 'admin.php?page=postman' ),
			'postman-smtp'           => array( 'match' => 'postman', 'page' => 'admin.php?page=postman' ),
			'gmail-smtp'             => array( 'match' => 'gmail-smtp', 'page' => 'options-general.php?page=gmail-smtp-settings' ),
			'smtp-mailer'            => array( 'match' => 'smtp-mailer', 'page' => 'options-general.php?page=smtp-mailer' ),
			'wp-ses'                 => array( 'match' => 'wp-ses', 'page' => 'options-general.php?page=wpses' ),
			'wp-offload-ses'         => array( 'match' => 'offload-ses', 'page' => 'admin.php?page=wp-offload-ses' ),
			'amazon-ses'             => array( 'match' => 'amazon-ses', 'page' => '' ),
			'mailgun'                => array( 'match' => 'mailgun', 'page' => 'options-general.php?page=mailgun' ),
			'sendgrid-email-delivery-simplified' => array( 'match' => 'sendgrid', 'page' => 'settings.php?page=sendgrid-settings' ),
			'postmark-approved-wordpress-plugin' => array( 'match' => 'postmark', 'page' => 'options-general.php?page=postmark_settings' ),
			'mailpoet'               => array( 'match' => 'mailpoet', 'page' => 'admin.php?page=mailpoet-settings#/basics' ),
			'brevo'                  => array( 'match' => 'brevo', 'page' => '' ),
			'mailin'                 => array( 'match' => 'sendinblue', 'page' => 'admin.php?page=sendinblue' ),
			'sparkpost'              => array( 'match' => 'sparkpost', 'page' => '' ),
			'mailjet-for-wordpress'  => array( 'match' => 'mailjet', 'page' => 'admin.php?page=mailjet_settings_page' ),
		);
	}

	/**
	 * @param array<string,mixed> $pm PHPMailer snapshot.
	 * @return string
	 */
	private function format_phpmailer_summary( array $pm ): string {
		if ( empty( $pm ) ) {
			return '—';
		}
		$parts = array();
		if ( ! empty( $pm['mailer'] ) ) {
			$parts[] = 'Mailer=' . $pm['mailer'];
		}
		if ( ! empty( $pm['host'] ) ) {
			$parts[] = 'Host=' . $pm['host'];
		}
		if ( ! empty( $pm['port'] ) ) {
			$parts[] = 'Port=' . (string) absint( $pm['port'] );
		}
		if ( array_key_exists( 'smtp_auth', $pm ) ) {
			$parts[] = 'Auth=' . ( ! empty( $pm['smtp_auth'] ) ? 'yes' : 'no' );
		}
		if ( ! empty( $pm['smtp_secure'] ) ) {
			$parts[] = 'Secure=' . $pm['smtp_secure'];
		}
		if ( ! empty( $pm['from'] ) ) {
			$parts[] = 'From=' . $pm['from'];
		}
		return ! empty( $parts ) ? implode( '; ', $parts ) : '—';
	}

	/**
	 * @param array<string,mixed>      $last    Latest result.
	 * @param array<int,array<string,mixed>> $history History rows.
	 * @return string
	 */
	private function build_support_report( array $last, array $history ): string {
		$lines   = array();
		$lines[] = 'TSO Swiss Knife — Email diagnostics report';
		$lines[] = 'Generated: ' . gmdate( 'c' );
		$lines[] = 'Site: ' . home_url( '/' );
		$lines[] = 'WP: ' . get_bloginfo( 'version' ) . ' | PHP: ' . PHP_VERSION;
		$lines[] = 'From filter: ' . $this->get_filtered_from_email() . ' (' . $this->get_filtered_from_name() . ')';
		$lines[] = 'Site domain: ' . $this->get_site_mail_domain();
		$warn    = $this->get_from_domain_warning( $this->get_filtered_from_email() );
		if ( '' !== $warn ) {
			$lines[] = 'Domain warning: ' . $warn;
		}
		$smtp = $this->detect_smtp_plugins();
		if ( empty( $smtp ) ) {
			$lines[] = 'SMTP plugins: none detected';
		} else {
			$names = array();
			foreach ( $smtp as $p ) {
				$names[] = $p['name'];
			}
			$lines[] = 'SMTP plugins: ' . implode( ', ', $names );
		}
		$lines[] = 'PHP mail(): ' . ( function_exists( 'mail' ) ? 'available' : 'disabled' );
		$lines[] = '';
		if ( empty( $last ) ) {
			$lines[] = 'No test sent yet.';
		} else {
			$lines[] = 'Last test:';
			$lines[] = '- Time: ' . ( ! empty( $last['time'] ) ? gmdate( 'c', absint( $last['time'] ) ) : 'n/a' );
			$lines[] = '- To: ' . (string) ( $last['to'] ?? $last['email'] ?? '' );
			$lines[] = '- CC: ' . (string) ( $last['cc'] ?? '' );
			$lines[] = '- Subject: ' . (string) ( $last['subject'] ?? '' );
			$lines[] = '- From: ' . (string) ( $last['from'] ?? '' );
			$lines[] = '- Format: ' . (string) ( $last['format'] ?? 'plain' );
			$lines[] = '- Sent: ' . ( ! empty( $last['sent'] ) ? 'yes' : 'no' );
			$lines[] = '- Message: ' . (string) ( $last['message'] ?? '' );
			$lines[] = '- PHPMailer: ' . $this->format_phpmailer_summary( is_array( $last['phpmailer'] ?? null ) ? $last['phpmailer'] : array() );
			if ( ! empty( $last['headers'] ) && is_array( $last['headers'] ) ) {
				$lines[] = '- Headers:';
				foreach ( $last['headers'] as $h ) {
					$lines[] = '  ' . (string) $h;
				}
			}
		}
		if ( count( $history ) > 1 ) {
			$lines[] = '';
			$lines[] = 'Recent history:';
			foreach ( array_slice( $history, 0, self::HISTORY_LIMIT ) as $row ) {
				$lines[] = sprintf(
					'- %s | %s | %s | %s | %s',
					! empty( $row['time'] ) ? gmdate( 'c', absint( $row['time'] ) ) : 'n/a',
					(string) ( $row['email'] ?? '' ),
					(string) ( $row['format'] ?? 'plain' ),
					! empty( $row['sent'] ) ? 'sent' : 'failed',
					$this->format_phpmailer_summary( is_array( $row['phpmailer'] ?? null ) ? $row['phpmailer'] : array() )
				);
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,mixed> $result Result row.
	 */
	private function store_test_result( array $result ): void {
		update_option( self::OPTION, $result, false );
		$history = $this->get_history();
		array_unshift( $history, $result );
		$history = array_slice( $history, 0, self::HISTORY_LIMIT );
		update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function get_history(): array {
		$history = get_option( self::OPTION_HISTORY, array() );
		if ( ! is_array( $history ) || empty( $history ) ) {
			$legacy = $this->get_last_legacy();
			return ! empty( $legacy ) ? array( $legacy ) : array();
		}
		$out = array();
		foreach ( $history as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_last_legacy(): array {
		$last = get_option( self::OPTION, array() );
		return is_array( $last ) && ! empty( $last ) ? $last : array();
	}
}
