<?php
/**
 * TSO Swiss Knife – Module: Health Report and Alerts.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Health
 */
class TSOSK_Mod_Health {

	/** Plugin option storing alert settings. */
	private const OPTION = 'tsosk_alert_settings';

	/** @var TSOSK_Mod_Health|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_Health
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_health_save_alerts', array( $this, 'ajax_save_alerts' ) );
		add_action( 'wp_ajax_tsosk_health_save_suppress', array( $this, 'ajax_save_suppress' ) );
		add_action( 'admin_post_tsosk_health_download_report', array( $this, 'download_report' ) );
		add_filter( 'site_status_tests', array( $this, 'filter_site_health_tests' ) );
	}

	/**
	 * AJAX: save alert settings.
	 */
	public function ajax_save_alerts(): void {
		check_ajax_referer( 'tsosk_health_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$settings = array(
			'enabled'             => ! empty( $_POST['enabled'] ),
			'email'               => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : get_option( 'admin_email' ),
			'not_found_threshold' => isset( $_POST['not_found_threshold'] ) ? max( 1, absint( wp_unslash( $_POST['not_found_threshold'] ) ) ) : 25,
		);

		if ( '' === $settings['email'] || ! is_email( $settings['email'] ) ) {
			wp_send_json_error( __( 'Enter a valid email address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		update_option( self::OPTION, $settings, false );
		TSOSK_Activity_Log::log( 'health', 'save', __( 'Health alert settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Alert settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: save Site Health notice suppression (staging sites).
	 */
	public function ajax_save_suppress(): void {
		check_ajax_referer( 'tsosk_health_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$suppress = array(
			'blog_public'   => ! empty( $_POST['suppress_blog_public'] ),
			'debug_enabled' => ! empty( $_POST['suppress_debug_enabled'] ),
		);
		update_option( 'tsosk_health_suppress', $suppress, false );
		TSOSK_Activity_Log::log( 'health', 'save', __( 'Site Health suppression settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Settings saved. Refresh Site Health to see changes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Remove selected tests from Tools › Site Health (WordPress core screen).
	 *
	 * @param array<string,array> $tests Site health tests.
	 * @return array<string,array>
	 */
	public function filter_site_health_tests( array $tests ): array {
		$suppress = get_option( 'tsosk_health_suppress', array() );
		if ( ! is_array( $suppress ) ) {
			return $tests;
		}
		if ( ! empty( $suppress['blog_public'] ) && isset( $tests['direct']['blog_public'] ) ) {
			unset( $tests['direct']['blog_public'] );
		}
		if ( ! empty( $suppress['debug_enabled'] ) && isset( $tests['direct']['debug_enabled'] ) ) {
			unset( $tests['direct']['debug_enabled'] );
		}
		return $tests;
	}

	/**
	 * @return array
	 */
	private function get_suppress_settings(): array {
		$defaults = array(
			'blog_public'   => false,
			'debug_enabled' => false,
		);
		$stored = get_option( 'tsosk_health_suppress', array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * Build a standalone HTML health report for download.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return string
	 */
	private function build_report_html( array $report ): string {
		$site_name = isset( $report['site']['name'] ) ? (string) $report['site']['name'] : '';
		$site_url  = isset( $report['site']['url'] ) ? (string) $report['site']['url'] : '';
		$generated = isset( $report['generated_at'] ) ? (string) $report['generated_at'] : '';
		$checks = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();

		wp_register_style(
			'tsosk-health-report',
			TSOSK_URL . 'assets/css/tsosk-health-report.css',
			array(),
			TSOSK_VERSION
		);
		wp_enqueue_style( 'tsosk-health-report' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'TSO Health Report — %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $site_name ) ); ?></title>
	<?php wp_print_styles( 'tsosk-health-report' ); ?>
</head>
<body class="tsosk-health-report">
	<h1><?php esc_html_e( 'TSO Swiss Knife — Health Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h1>
	<p><strong><?php esc_html_e( 'Site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong> <?php echo esc_html( $site_name ); ?>
		(<a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html( $site_url ); ?></a>)</p>
	<p><strong><?php esc_html_e( 'Generated', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong> <?php echo esc_html( $generated ); ?></p>
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<th><?php esc_html_e( 'Details', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $checks as $check ) : ?>
			<?php
			$status = isset( $check['status'] ) ? (string) $check['status'] : '';
			$cls    = 'status-' . sanitize_html_class( $status );
			?>
			<tr>
				<td><?php echo esc_html( $check['label'] ?? '' ); ?></td>
				<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( strtoupper( $status ) ); ?></td>
				<td><?php echo esc_html( $check['details'] ?? '' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Download the current health report as JSON or HTML.
	 */
	public function download_report(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_health_download_report' );

		$format = isset( $_GET['format'] ) && 'html' === sanitize_key( wp_unslash( $_GET['format'] ) ) ? 'html' : 'json';
		$report = array(
			'generated_at' => gmdate( 'c' ),
			'locale'       => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
			'site'         => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url(),
			),
			'checks'       => $this->get_checks(),
		);

		nocache_headers();
		if ( 'html' === $format ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tsosk-health-report.html"' );
			echo $this->build_report_html( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-health-report.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Render the Health Report tab.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_health_nonce' );
		$settings = $this->get_settings();
		$suppress = $this->get_suppress_settings();
		$checks              = $this->get_checks();
		$top_autoload        = $this->get_top_autoload_options();
		$download_base = add_query_arg( 'action', 'tsosk_health_download_report', admin_url( 'admin-post.php' ) );
		$download_url  = wp_nonce_url( $download_base, 'tsosk_health_download_report' );
		$download_html = wp_nonce_url(
			add_query_arg( 'format', 'html', $download_base ),
			'tsosk_health_download_report'
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'A compact health report with checks that help detect risky settings, noisy logs, broken links and maintenance issues.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Health Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $download_url ); ?>">
					<?php esc_html_e( 'Download JSON Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $download_html ); ?>">
					<?php esc_html_e( 'Download HTML Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-health-checks-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Details', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $checks as $check ) : ?>
							<tr>
								<td><?php echo esc_html( $check['label'] ); ?></td>
								<td>
									<span class="tsosk-badge <?php echo esc_attr( $this->badge_class( $check['status'] ) ); ?>">
										<?php echo esc_html( strtoupper( $check['status'] ) ); ?>
									</span>
								</td>
								<td class="tsosk-health-details"><?php echo esc_html( $check['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Top autoloaded options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Largest wp_options rows loaded on every request (autoload = yes). Review heavy options in Options Editor if total autoload size is high.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $top_autoload ) ) : ?>
				<p><?php esc_html_e( 'No autoloaded options found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Option name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $top_autoload as $row ) : ?>
								<tr>
									<td class="tsosk-code"><?php echo esc_html( $row['option_name'] ); ?></td>
									<td><?php echo esc_html( size_format( absint( $row['size_bytes'] ), 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Email Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Optional email when the Redirects 404 monitor sees too much traffic to missing pages. Useful to catch broken links or bots scanning your site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<label class="tsosk-radio-row">
				<input type="checkbox" id="tsosk-alerts-enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
				<?php esc_html_e( 'Enable email alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</label>
			<div class="tsosk-field-row">
				<label for="tsosk-alerts-email"><strong><?php esc_html_e( 'Alert Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="email" id="tsosk-alerts-email" class="regular-text" value="<?php echo esc_attr( $settings['email'] ); ?>">
			</div>
			<div class="tsosk-field-row">
				<label for="tsosk-alerts-404-threshold"><strong><?php esc_html_e( 'Max 404 visits per hour', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="number" id="tsosk-alerts-404-threshold" min="1" value="<?php echo esc_attr( (string) $settings['not_found_threshold'] ); ?>">
				<p class="description tsosk-field-desc-tight">
					<?php
					printf(
						/* translators: 1: example threshold value from settings, 2: same threshold (minimum visits in one hour) */
						esc_html__( 'Example: with %1$d, you get one email if the site records %2$d or more Not Found (404) visits within the same clock hour. Each visit counts once (not the lifetime total of a URL). At most one alert email is sent per hour.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $settings['not_found_threshold'],
						(int) $settings['not_found_threshold']
					);
					?>
				</p>
			</div>
			<button class="button button-primary" id="tsosk-health-save-alerts" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save Alert Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-health-msg"></span>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Hide WordPress Site Health notices', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'On staging or private test sites, WordPress may show heavy warnings on Tools › Site Health about search-engine visibility or debug.log being public. Enable the options below to hide those specific tests. This does not change your real settings — it only removes the notices from Site Health.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<label class="tsosk-radio-row">
				<input type="checkbox" id="tsosk-health-suppress-blog-public" <?php checked( ! empty( $suppress['blog_public'] ) ); ?>>
				<?php esc_html_e( 'Hide “site is not visible to search engines” test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</label>
			<label class="tsosk-radio-row">
				<input type="checkbox" id="tsosk-health-suppress-debug" <?php checked( ! empty( $suppress['debug_enabled'] ) ); ?>>
				<?php esc_html_e( 'Hide “debug.log may be publicly accessible” test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</label>
			<button class="button button-primary tsosk-btn-mt" id="tsosk-health-save-suppress" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save suppression settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-health-suppress-msg"></span>
		</div>
		<?php
	}

	/**
	 * Get alert settings.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$settings = get_option( self::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'enabled'             => ! empty( $settings['enabled'] ),
			'email'               => sanitize_email( $settings['email'] ?? get_option( 'admin_email' ) ),
			'not_found_threshold' => max( 1, absint( $settings['not_found_threshold'] ?? 25 ) ),
		);
	}

	/**
	 * Build health checks.
	 *
	 * @return array
	 */
	private function get_checks(): array {
		$checks = array();
		$checks[] = array(
			'label'   => __( 'WordPress version', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => is_wp_version_compatible( '6.0' ) ? 'ok' : 'warn',
			'details' => get_bloginfo( 'version' ),
		);
		$checks[] = array(
			'label'   => __( 'PHP version', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'warn',
			'details' => PHP_VERSION,
		);
		$checks[] = $this->site_urls_check();
		$checks[] = array(
			'label'   => __( 'WP_DEBUG_DISPLAY', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'warn' : 'ok',
			'details' => defined( 'WP_DEBUG_DISPLAY' ) ? ( WP_DEBUG_DISPLAY ? __( 'Enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) : __( 'Not defined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		$checks[] = array(
			'label'   => __( 'Object cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => wp_using_ext_object_cache() ? 'ok' : 'info',
			'details' => wp_using_ext_object_cache() ? __( 'Persistent object cache detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'No persistent object cache detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		$checks[] = $this->cron_check();
		$checks[] = $this->debug_log_check();
		$checks[] = $this->not_found_check();
		$checks[] = $this->autoload_check();
		$checks[] = $this->security_headers_check();

		return $checks;
	}

	/**
	 * Compare home and site URL scheme/host consistency.
	 *
	 * @return array
	 */
	private function site_urls_check(): array {
		$home = (string) home_url();
		$site = (string) site_url();
		$home_parts = wp_parse_url( $home );
		$site_parts = wp_parse_url( $site );

		$home_scheme = isset( $home_parts['scheme'] ) ? strtolower( (string) $home_parts['scheme'] ) : '';
		$site_scheme = isset( $site_parts['scheme'] ) ? strtolower( (string) $site_parts['scheme'] ) : '';
		$home_host   = isset( $home_parts['host'] ) ? strtolower( (string) $home_parts['host'] ) : '';
		$site_host   = isset( $site_parts['host'] ) ? strtolower( (string) $site_parts['host'] ) : '';

		$status  = 'ok';
		$details = sprintf(
			/* translators: 1: home URL line, 2: site URL line */
			"%1\$s\n%2\$s",
			sprintf(
				/* translators: %s: home URL */
				__( 'Home: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$home
			),
			sprintf(
				/* translators: %s: site URL */
				__( 'Site: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$site
			)
		);

		if ( 'https' !== $home_scheme || 'https' !== $site_scheme ) {
			$status = 'warn';
		}
		if ( $home_host !== $site_host ) {
			$status = 'warn';
			$details .= "\n" . __( 'Home and Site URL hosts differ.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} elseif ( $home_scheme !== $site_scheme ) {
			$details .= "\n" . __( 'Home and Site URL schemes differ.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return array(
			'label'   => __( 'Site URL consistency', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $status,
			'details' => $details,
		);
	}

	/**
	 * Probe front page response headers (read-only).
	 *
	 * Cache stores raw header names only so translations stay in the active plugin language.
	 *
	 * @return array
	 */
	private function security_headers_check(): array {
		$locale    = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$cache_key = 'tsosk_health_security_headers_v2_' . sanitize_key( (string) $locale );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && array_key_exists( 'present', $cached ) ) {
			$error = '';
			if ( ! empty( $cached['error_code'] ) && is_string( $cached['error_code'] ) ) {
				$error = sprintf(
					/* translators: %s: WP_Error code from the HTTP API */
					__( 'Could not probe the home page (%s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					sanitize_key( $cached['error_code'] )
				);
			}
			return $this->format_security_headers_result(
				is_array( $cached['present'] ) ? $cached['present'] : array(),
				$error
			);
		}

		$url  = home_url( '/' );
		$resp = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $resp ) ) {
			$code    = $resp->get_error_code();
			$payload = array(
				'present'    => array(),
				'error_code' => is_string( $code ) ? $code : 'http_request_failed',
			);
			set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
			return $this->format_security_headers_result(
				array(),
				sprintf(
					/* translators: %s: WP_Error code from the HTTP API */
					__( 'Could not probe the home page (%s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					sanitize_key( (string) $payload['error_code'] )
				)
			);
		}

		$present = array();
		$names   = array(
			'x-content-type-options',
			'x-frame-options',
			'strict-transport-security',
			'referrer-policy',
		);

		foreach ( $names as $name ) {
			$value = wp_remote_retrieve_header( $resp, $name );
			if ( '' !== $value ) {
				$present[] = $name;
			}
		}

		$payload = array(
			'present'    => $present,
			'error_code' => '',
		);
		set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );

		return $this->format_security_headers_result( $present, '' );
	}

	/**
	 * Build the translated security-headers check row from cached probe data.
	 *
	 * @param string[] $present Header names found.
	 * @param string   $error   Transport error message, if any.
	 * @return array{label:string,status:string,details:string}
	 */
	private function format_security_headers_result( array $present, string $error ): array {
		$label = __( 'Security headers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( '' !== $error ) {
			return array(
				'label'   => $label,
				'status'  => 'info',
				'details' => $error,
			);
		}

		$count  = count( $present );
		$status = $count >= 2 ? 'ok' : ( $count > 0 ? 'info' : 'warn' );

		return array(
			'label'   => $label,
			'status'  => $status,
			'details' => $count
				? sprintf(
					/* translators: %s: comma-separated header names */
					__( 'Present: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $present )
				)
				: __( 'No common security headers detected on the home page response.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Largest autoloaded options by serialized size.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array{option_name:string,size_bytes:int}>
	 */
	private function get_top_autoload_options( int $limit = 15 ): array {
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size_bytes FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto') ORDER BY size_bytes DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) ) {
				continue;
			}
			$out[] = array(
				'option_name' => (string) $row['option_name'],
				'size_bytes'  => absint( $row['size_bytes'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Check overdue cron events.
	 *
	 * @return array
	 */
	private function cron_check(): array {
		$cron    = _get_cron_array();
		$overdue = 0;
		$cutoff  = time() - HOUR_IN_SECONDS;
		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $hooks ) {
				if ( absint( $timestamp ) >= $cutoff || ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $events_by_sig ) {
					if ( is_array( $events_by_sig ) ) {
						$overdue += count( $events_by_sig );
					}
				}
			}
		}

		return array(
			'label'   => __( 'Overdue cron events', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $overdue > 0 ? 'warn' : 'ok',
			'details' => sprintf(
				/* translators: %d: overdue events */
				__( '%d overdue events.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$overdue
			),
		);
	}

	/**
	 * Check debug log size.
	 *
	 * @return array
	 */
	private function debug_log_check(): array {
		$path = trailingslashit( wp_normalize_path( (string) WP_CONTENT_DIR ) ) . 'debug.log';
		$size = file_exists( $path ) ? (int) filesize( $path ) : 0;

		return array(
			'label'   => __( 'debug.log size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $size > 5 * MB_IN_BYTES ? 'warn' : 'ok',
			'details' => file_exists( $path ) ? size_format( $size, 2 ) : __( 'Not found', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Check recent 404 activity.
	 *
	 * @return array
	 */
	private function not_found_check(): array {
		$logs = get_option( 'tsosk_404_log', array() );
		$count = is_array( $logs ) ? count( $logs ) : 0;

		return array(
			'label'   => __( '404 monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $count > 25 ? 'warn' : ( $count > 0 ? 'info' : 'ok' ),
			'details' => sprintf(
				/* translators: %d: recorded 404 URLs */
				__( '%d recorded missing URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			),
		);
	}

	/**
	 * Check autoloaded options size.
	 *
	 * @return array
	 */
	private function autoload_check(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto')" );

		return array(
			'label'   => __( 'Autoloaded options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $size > 2 * MB_IN_BYTES ? 'warn' : 'ok',
			'details' => size_format( $size, 2 ),
		);
	}

	/**
	 * Convert status to badge class.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function badge_class( string $status ): string {
		if ( 'warn' === $status ) {
			return 'tsosk-badge-warn';
		}
		if ( 'ok' === $status ) {
			return 'tsosk-badge-ok';
		}
		return 'tsosk-badge-info';
	}
}
