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
	 * @return array{blog_public:bool,debug_enabled:bool}
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
		$checks    = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();
		$css_url   = TSOSK_URL . 'assets/css/tsosk-health-report.css';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'TSO Health Report — %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $site_name ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
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
		$checks = $this->get_checks();
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
				<table class="widefat tsosk-table">
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
								<td><?php echo esc_html( $check['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Email Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Alerts are intentionally small and actionable. The current alert sends an email when the 404 monitor exceeds the configured hourly threshold.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
				<label for="tsosk-alerts-404-threshold"><strong><?php esc_html_e( '404 hourly threshold', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="number" id="tsosk-alerts-404-threshold" min="1" value="<?php echo esc_attr( (string) $settings['not_found_threshold'] ); ?>">
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
			<button class="button button-primary" id="tsosk-health-save-suppress" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:10px;">
				<?php esc_html_e( 'Save suppression settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-health-suppress-msg"></span>
		</div>
		<?php
	}

	/**
	 * Get alert settings.
	 *
	 * @return array{enabled:bool,email:string,not_found_threshold:int}
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
	 * @return array<int, array{label:string,status:string,details:string}>
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
		$checks[] = array(
			'label'   => __( 'HTTPS', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => is_ssl() || 0 === strpos( home_url(), 'https://' ) ? 'ok' : 'warn',
			'details' => home_url(),
		);
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

		return $checks;
	}

	/**
	 * Check overdue cron events.
	 *
	 * @return array{label:string,status:string,details:string}
	 */
	private function cron_check(): array {
		$cron = _get_cron_array();
		$overdue = 0;
		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $events ) {
				if ( absint( $timestamp ) < time() - HOUR_IN_SECONDS && ! empty( $events ) ) {
					$overdue += count( $events );
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
	 * @return array{label:string,status:string,details:string}
	 */
	private function debug_log_check(): array {
		$path = WP_CONTENT_DIR . '/debug.log';
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
	 * @return array{label:string,status:string,details:string}
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
	 * @return array{label:string,status:string,details:string}
	 */
	private function autoload_check(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" );

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
