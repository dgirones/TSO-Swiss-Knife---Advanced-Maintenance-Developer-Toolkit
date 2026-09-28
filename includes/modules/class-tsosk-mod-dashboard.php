<?php
/**
 * TSO Swiss Knife – Module: Overview dashboard (score, alerts, vitals, recent activity).
 *
 * Read-only. Reuses the Health Report and Security Review checks (without the
 * remote header probe) and caches the result for a few minutes.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Dashboard
 */
class TSOSK_Mod_Dashboard {

	/** Transient prefix for the cached scan (suffixed with the locale). */
	private const CACHE_PREFIX = 'tsosk_dashboard_scan_';

	/** Cache lifetime in seconds. */
	private const CACHE_TTL = 600;

	/** Nonce action for the manual refresh link. */
	private const REFRESH_NONCE = 'tsosk_dashboard_refresh';

	/**
	 * Singleton instance.
	 *
	 * @var TSOSK_Mod_Dashboard|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_Dashboard
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Base URL of the plugin admin page.
	 *
	 * @return string
	 */
	private function base_url(): string {
		return admin_url( 'tools.php?page=tso-swiss-knife' );
	}

	/**
	 * Whether the current request carries a valid manual-refresh nonce.
	 *
	 * @return bool
	 */
	private function is_refresh_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in the same statement.
		if ( empty( $_GET['tsosk_dash_refresh'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value is only passed to wp_verify_nonce().
		$nonce = sanitize_text_field( wp_unslash( ( is_scalar( $_GET['tsosk_dash_refresh'] ) ? (string) $_GET['tsosk_dash_refresh'] : '' ) ) );
		return false !== wp_verify_nonce( $nonce, self::REFRESH_NONCE );
	}

	/**
	 * Normalise a check row from Health / Security into ok|warn|info.
	 *
	 * @param array<string, mixed> $check       Raw check.
	 * @param string               $default_tab Tab to link to when the check has none.
	 * @return array{label: string, status: string, details: string, tab: string, anchor: string}
	 */
	private function normalise_check( array $check, string $default_tab ): array {
		$status = (string) ( $check['status'] ?? 'info' );
		if ( isset( $check['badge'] ) ) {
			$badge = (string) $check['badge'];
			if ( 'tsosk-badge-ok' === $badge ) {
				$status = 'ok';
			} elseif ( 'tsosk-badge-warn' === $badge ) {
				$status = 'warn';
			} else {
				$status = 'info';
			}
		}
		if ( ! in_array( $status, array( 'ok', 'warn', 'info' ), true ) ) {
			$status = 'info';
		}

		return array(
			'label'   => (string) ( $check['label'] ?? '' ),
			'status'  => $status,
			'details' => (string) ( $check['details'] ?? '' ),
			'tab'     => sanitize_key( (string) ( $check['tab'] ?? $default_tab ) ),
			'anchor'  => sanitize_key( (string) ( $check['anchor'] ?? '' ) ),
		);
	}

	/**
	 * Build the URL for a normalised check's "Open tool" link (tab + optional in-page anchor).
	 *
	 * @param array<string, string> $check Normalised check.
	 * @param string                $base  Plugin admin URL.
	 * @return string
	 */
	private function check_url( array $check, string $base ): string {
		$url = add_query_arg( 'tab', $check['tab'], $base );
		if ( '' !== $check['anchor'] ) {
			$url .= '#' . $check['anchor'];
		}
		return $url;
	}

	/**
	 * Run (or read from cache) the combined scan.
	 *
	 * @param bool $force Ignore the cache.
	 * @return array{checks: array<int, array<string, string>>, time: int}
	 */
	private function get_scan( bool $force ): array {
		$key = self::CACHE_PREFIX . sanitize_key( (string) get_locale() );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['checks'], $cached['time'] ) ) {
				return $cached;
			}
		}

		$checks = array();
		if ( class_exists( 'TSOSK_Mod_Health' ) ) {
			foreach ( TSOSK_Mod_Health::get_instance()->get_dashboard_checks() as $check ) {
				$checks[] = $this->normalise_check( (array) $check, 'health' );
			}
		}
		if ( class_exists( 'TSOSK_Mod_Security' ) ) {
			foreach ( TSOSK_Mod_Security::get_instance()->get_dashboard_checks() as $check ) {
				$checks[] = $this->normalise_check( (array) $check, 'security' );
			}
		}

		$scan = array(
			'checks' => $checks,
			'time'   => time(),
		);
		set_transient( $key, $scan, self::CACHE_TTL );

		return $scan;
	}

	/**
	 * Score (0-100) from ok vs warn checks; info rows are informational only.
	 *
	 * @param array<int, array<string, string>> $checks Normalised checks.
	 * @return array{score: int, ok: int, warn: int, info: int}
	 */
	private function compute_score( array $checks ): array {
		$ok   = 0;
		$warn = 0;
		$info = 0;
		foreach ( $checks as $check ) {
			if ( 'ok' === $check['status'] ) {
				++$ok;
			} elseif ( 'warn' === $check['status'] ) {
				++$warn;
			} else {
				++$info;
			}
		}
		$scored = $ok + $warn;
		$score  = $scored > 0 ? (int) round( ( $ok / $scored ) * 100 ) : 100;

		return array(
			'score' => $score,
			'ok'    => $ok,
			'warn'  => $warn,
			'info'  => $info,
		);
	}

	/**
	 * Count pending updates from WordPress' own update transients (no remote call).
	 *
	 * @return array{core: int, plugins: int, themes: int}
	 */
	private function get_pending_updates(): array {
		$core      = 0;
		$core_data = get_site_transient( 'update_core' );
		if ( is_object( $core_data ) && ! empty( $core_data->updates ) && is_array( $core_data->updates ) ) {
			$first = reset( $core_data->updates );
			if ( is_object( $first ) && isset( $first->response ) && 'upgrade' === $first->response ) {
				$core = 1;
			}
		}
		$plugins_data = get_site_transient( 'update_plugins' );
		$themes_data  = get_site_transient( 'update_themes' );

		return array(
			'core'    => $core,
			'plugins' => ( is_object( $plugins_data ) && ! empty( $plugins_data->response ) && is_array( $plugins_data->response ) ) ? count( $plugins_data->response ) : 0,
			'themes'  => ( is_object( $themes_data ) && ! empty( $themes_data->response ) && is_array( $themes_data->response ) ) ? count( $themes_data->response ) : 0,
		);
	}

	/**
	 * Render the dashboard.
	 */
	public function render(): void {
		$force = $this->is_refresh_request();
		$scan  = $this->get_scan( $force );
		$stats = $this->compute_score( $scan['checks'] );
		$base  = $this->base_url();

		$band    = 'ok';
		$verdict = __( 'Looking good', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( $stats['score'] < 70 ) {
			$band    = 'crit';
			$verdict = __( 'Needs attention', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} elseif ( $stats['score'] < 90 ) {
			$band    = 'warn';
			$verdict = __( 'Some things to review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$circ = 2 * M_PI * 54;
		$dash = round( $circ * $stats['score'] / 100, 2 );

		$issues = array_values(
			array_filter(
				$scan['checks'],
				static function ( array $check ): bool {
					return 'warn' === $check['status'];
				}
			)
		);

		$refresh_url = add_query_arg(
			array(
				'tab'                => 'dashboard',
				'tsosk_dash_refresh' => wp_create_nonce( self::REFRESH_NONCE ),
			),
			$base
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'A quick read of this site: an overall score built from the Health Report and Security Review checks, what needs attention, and shortcuts to the most used tools. Nothing here changes your site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-dash-hero tsosk-dash-<?php echo esc_attr( $band ); ?>">
			<div class="tsosk-dash-ring" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: score 0-100 */ __( 'Site score: %d out of 100', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $stats['score'] ) ); ?>">
				<svg viewBox="0 0 120 120" width="120" height="120" aria-hidden="true" focusable="false">
					<circle class="tsosk-dash-ring-bg" cx="60" cy="60" r="54" fill="none" stroke-width="10"></circle>
					<circle class="tsosk-dash-ring-fg" cx="60" cy="60" r="54" fill="none" stroke-width="10" stroke-linecap="round"
					stroke-dasharray="<?php echo esc_attr( $dash . ' ' . round( $circ, 2 ) ); ?>" transform="rotate(-90 60 60)"></circle>
				</svg>
				<span class="tsosk-dash-ring-num"><?php echo esc_html( (string) $stats['score'] ); ?></span>
			</div>
			<div class="tsosk-dash-hero-text">
				<h3><?php echo esc_html( $verdict ); ?></h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: checks passed, 2: checks needing review */
							__( '%1$d checks passed, %2$d need review.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$stats['ok'],
							$stats['warn']
						)
					);
					?>
				</p>
				<p class="tsosk-dash-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: time of the last scan */
							__( 'Last scan: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							wp_date( 'Y-m-d H:i', (int) $scan['time'] )
						)
					);
					?>
					<a class="button button-small" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Scan again', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</p>
			</div>
		</div>

		<?php $this->render_vitals(); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Needs attention', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( empty( $issues ) ) : ?>
				<p class="tsosk-dash-empty"><?php esc_html_e( 'Nothing to review right now.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="tsosk-dash-issues">
					<?php foreach ( $issues as $issue ) : ?>
						<li>
							<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<span class="tsosk-dash-issue-body">
								<strong><?php echo esc_html( $issue['label'] ); ?></strong>
								<span class="tsosk-dash-issue-details"><?php echo esc_html( wp_strip_all_tags( $issue['details'] ) ); ?></span>
							</span>
							<a class="button button-small" href="<?php echo esc_url( $this->check_url( $issue, $base ) ); ?>"><?php esc_html_e( 'Open tool', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php $this->render_shortcuts( $base ); ?>
		<?php $this->render_recent_activity( $base ); ?>
		<?php
		if ( class_exists( 'TSOSK_Mod_Health' ) ) {
			TSOSK_Mod_Health::get_instance()->render_suppress_card();
		}
		?>
		<?php
	}

	/**
	 * Vitals tiles (cheap, local data only).
	 */
	private function render_vitals(): void {
		$updates = $this->get_pending_updates();
		$total   = $updates['core'] + $updates['plugins'] + $updates['themes'];
		$active  = get_option( 'active_plugins', array() );
		$active  = is_array( $active ) ? count( $active ) : 0;

		$tiles = array(
			array(
				'label' => __( 'WordPress', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => get_bloginfo( 'version' ),
				'sub'   => wp_get_environment_type(),
			),
			array(
				'label' => __( 'PHP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => PHP_VERSION,
				'sub'   => sprintf(
					/* translators: %s: PHP memory limit, e.g. 256M */
					__( 'Memory limit %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(string) ini_get( 'memory_limit' )
				),
			),
			array(
				'label' => __( 'Active plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => (string) $active,
				'sub'   => '',
			),
			array(
				'label' => __( 'Pending updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => (string) $total,
				'sub'   => sprintf(
					/* translators: 1: core updates, 2: plugin updates, 3: theme updates */
					__( 'Core %1$d · Plugins %2$d · Themes %3$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$updates['core'],
					$updates['plugins'],
					$updates['themes']
				),
				'warn'  => $total > 0,
			),
		);
		?>
		<div class="tsosk-dash-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="tsosk-dash-tile<?php echo ! empty( $tile['warn'] ) ? ' is-warn' : ''; ?>">
					<span class="tsosk-dash-tile-label"><?php echo esc_html( $tile['label'] ); ?></span>
					<span class="tsosk-dash-tile-value"><?php echo esc_html( $tile['value'] ); ?></span>
					<?php if ( '' !== $tile['sub'] ) : ?>
						<span class="tsosk-dash-tile-sub"><?php echo esc_html( $tile['sub'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Shortcut buttons to frequently used tools.
	 *
	 * @param string $base Plugin admin URL.
	 */
	private function render_shortcuts( string $base ): void {
		$links = array(
			array( 'health', 'TSOSK_Mod_Health', __( 'Health Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-chart-area' ),
			array( 'security', 'TSOSK_Mod_Security', __( 'Security Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-shield-alt' ),
			array( 'debug', 'TSOSK_Mod_Debug', __( 'Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-admin-generic' ),
			array( 'cron', 'TSOSK_Mod_Cron', __( 'Cron Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-clock' ),
			array( 'transients', 'TSOSK_Mod_Transients', __( 'Transients', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-database-remove' ),
			array( 'options-editor', 'TSOSK_Mod_Options_Editor', __( 'Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-edit' ),
			array( 'maintenance', 'TSOSK_Mod_Maintenance', __( 'Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-visibility' ),
			array( 'sandbox', 'TSOSK_Mod_Sandbox', __( 'Plugin Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-shield' ),
		);
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Shortcuts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-dash-shortcuts">
				<?php foreach ( $links as $link ) : ?>
					<?php if ( class_exists( $link[1] ) ) : ?>
						<a class="tsosk-dash-shortcut" href="<?php echo esc_url( add_query_arg( 'tab', $link[0], $base ) ); ?>">
							<span class="dashicons <?php echo esc_attr( $link[3] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $link[2] ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Last five entries from the Activity History.
	 *
	 * @param string $base Plugin admin URL.
	 */
	private function render_recent_activity( string $base ): void {
		if ( ! class_exists( 'TSOSK_Activity_Log' ) ) {
			return;
		}
		$entries = array_slice( TSOSK_Activity_Log::get_entries(), 0, 5 );
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recent activity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( empty( $entries ) ) : ?>
				<p class="tsosk-dash-empty"><?php esc_html_e( 'No activity recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="tsosk-dash-activity">
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$module  = sanitize_key( (string) ( $entry['module'] ?? '' ) );
						$action  = sanitize_key( (string) ( $entry['action'] ?? '' ) );
						$details = is_array( $entry['details'] ?? null ) ? $entry['details'] : array();
						?>
						<li>
							<span class="tsosk-dash-activity-time"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></span>
							<span class="tsosk-badge tsosk-badge-info"><?php echo esc_html( TSOSK_Activity_Log::module_label( $module ) ); ?></span>
							<span><?php echo esc_html( TSOSK_Activity_Log::translate_summary( $module, $action, (string) ( $entry['summary'] ?? '' ), $details ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'history', $base ) ); ?>"><?php esc_html_e( 'View full history', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
