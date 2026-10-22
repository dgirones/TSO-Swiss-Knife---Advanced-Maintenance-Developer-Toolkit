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
	private const CACHE_PREFIX = 'tsosk_dashboard_scan2_';

	/** Cache lifetime in seconds. */
	private const CACHE_TTL = 600;

	/** Option that keeps the score of recent scans (trend line). */
	private const HISTORY_OPTION = 'tsosk_dashboard_history';

	/** Number of scans kept in the history. */
	private const HISTORY_MAX = 30;

	/** Nonce action for the manual refresh link. */
	private const REFRESH_NONCE = 'tsosk_dashboard_refresh';

	/**
	 * Singleton instance.
	 *
	 * @var TSOSK_Mod_Dashboard|null
	 */
	private static $instance = null;

	/**
	 * Cached TSO Link Inspector state for the current request (avoids repeating its two queries).
	 *
	 * @var array<string, mixed>|null
	 */
	private $link_state = null;

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
	 * Drop the cached scan (every language), e.g. after updates were installed.
	 */
	public static function bust_scan_cache(): void {
		$locales = array_unique( array_merge( array( get_locale(), 'en_US' ), function_exists( 'get_available_languages' ) ? get_available_languages() : array() ) );
		foreach ( $locales as $locale ) {
			delete_transient( self::CACHE_PREFIX . sanitize_key( (string) $locale ) );
		}
	}

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
	 * @return array{label: string, status: string, details: string, tab: string, anchor: string, url: string}
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
			'url'     => '',
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
		if ( ! empty( $check['url'] ) ) {
			return (string) $check['url'];
		}
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

		$checks = array_merge( $checks, $this->get_extra_checks() );

		$scan = array(
			'checks' => $checks,
			'recs'   => $this->get_recommendations(),
			'time'   => time(),
		);
		set_transient( $key, $scan, self::CACHE_TTL );
		$this->record_history( $this->compute_score( $checks )['score'] );

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
	 * Pending updates, with the same numbers as the "Install pending updates" button.
	 *
	 * Language packs are counted too: before, the tile ignored them and showed 0 while the button said 2.
	 *
	 * @return array{core: int, plugins: int, themes: int, translations: int, checked: bool, restricted: bool}
	 */
	private function get_pending_updates(): array {
		// While the Update Manager restricts update checks, WordPress hands back an empty "nothing to update" answer: that is not a real zero.
		$restricted   = class_exists( 'TSOSK_Mod_Update_Manager' ) && TSOSK_Mod_Update_Manager::is_restricting_updates();
		$plugins_data = get_site_transient( 'update_plugins' );
		// False when WordPress has never stored an update check (or checks are disabled): "0" would then be misleading.
		$checked = ! $restricted && is_object( $plugins_data ) && ! empty( $plugins_data->last_checked );

		if ( class_exists( 'TSOSK_Mod_Update_Manager' ) ) {
			$counts = TSOSK_Mod_Update_Manager::get_instance()->get_pending_counts();

			return array(
				'checked'      => $checked,
				'restricted'   => $restricted,
				'core'         => (int) $counts['core'],
				'plugins'      => (int) $counts['plugins'],
				'themes'       => (int) $counts['themes'],
				'translations' => (int) $counts['translations'],
			);
		}

		// Fallback when the Update Manager is not loaded: read WordPress' own transients (no remote call).
		$core      = 0;
		$core_data = get_site_transient( 'update_core' );
		if ( is_object( $core_data ) && ! empty( $core_data->updates ) && is_array( $core_data->updates ) ) {
			$first = reset( $core_data->updates );
			if ( is_object( $first ) && isset( $first->response ) && 'upgrade' === $first->response ) {
				$core = 1;
			}
		}
		$themes_data = get_site_transient( 'update_themes' );
		$plugin_resp = ( is_object( $plugins_data ) && ! empty( $plugins_data->response ) && is_array( $plugins_data->response ) ) ? $plugins_data->response : array();
		if ( ! empty( $plugin_resp ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				tsosk_require_wp_admin( 'includes/plugin.php' );
			}
			$plugin_resp = function_exists( 'get_plugins' ) ? array_intersect_key( $plugin_resp, get_plugins() ) : $plugin_resp;
		}

		return array(
			'checked'      => $checked,
			'restricted'   => $restricted,
			'core'         => $core,
			'plugins'      => count( $plugin_resp ),
			'themes'       => ( is_object( $themes_data ) && ! empty( $themes_data->response ) && is_array( $themes_data->response ) ) ? count( $themes_data->response ) : 0,
			'translations' => 0,
		);
	}

	/**
	 * The "updates pending" row of "Needs attention": live data, never from the cached scan.
	 *
	 * @param array{core: int, plugins: int, themes: int, translations: int, checked: bool, restricted: bool} $updates Pending updates.
	 * @return array{total: int, installable: bool}|null Null when nothing is pending (or the count cannot be trusted).
	 */
	private function get_update_attention( array $updates ): ?array {
		$total = $updates['core'] + $updates['plugins'] + $updates['themes'] + $updates['translations'];
		if ( ! $updates['checked'] || $total < 1 ) {
			return null;
		}

		$installable = false;
		if ( class_exists( 'TSOSK_Mod_Update_Manager' ) && current_user_can( 'update_plugins' ) && current_user_can( 'update_themes' ) ) {
			$counts      = TSOSK_Mod_Update_Manager::get_instance()->get_installable_counts();
			$installable = $counts['total'] > 0 && '' === $counts['blocker'];
		}

		return array(
			'total'       => $total,
			'installable' => $installable,
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

		$updates    = $this->get_pending_updates();
		$update_row = $this->get_update_attention( $updates );

		$issues = array_values(
			array_filter(
				$scan['checks'],
				static function ( array $check ): bool {
					return 'warn' === $check['status'];
				}
			)
		);

		if ( null !== $update_row ) {
			// The live "updates pending" row replaces the cached plugin/theme update checks: same facts, but those can be stale.
			$issues = array_values(
				array_filter(
					$issues,
					static function ( array $check ): bool {
						return ! ( 'update-manager' === $check['tab'] && in_array( $check['anchor'], array( 'tsosk-um-plugins', 'tsosk-um-status' ), true ) );
					}
				)
			);
		}
		// What the list shows is what the hero counts.
		$review_count = count( $issues ) + ( null !== $update_row ? 1 : 0 );

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
							$review_count
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
				<?php $this->render_trend(); ?>
			</div>
		</div>

		<?php $this->render_vitals( $updates ); ?>
		<?php $this->render_tiles( $this->get_usage_tiles() ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Needs attention', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( empty( $issues ) && null === $update_row ) : ?>
				<p class="tsosk-dash-empty"><?php esc_html_e( 'Nothing to review right now.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="tsosk-dash-issues">
					<?php if ( null !== $update_row ) : ?>
						<li class="tsosk-dash-issue-updates">
							<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<span class="tsosk-dash-issue-body">
								<strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of pending updates */
											_n( '%d update pending', '%d updates pending', $update_row['total'], 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$update_row['total']
										)
									);
									?>
								</strong>
								<span class="tsosk-dash-issue-details"><?php echo esc_html( $this->updates_breakdown( $updates ) ); ?></span>
							</span>
							<span class="tsosk-dash-issue-action">
								<?php if ( $update_row['installable'] ) : ?>
									<?php TSOSK_Mod_Update_Manager::get_instance()->render_install_all_button( true ); ?>
								<?php else : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->tile_url( 'update-manager', 'TSOSK_Mod_Update_Manager' ) ); ?>"><?php esc_html_e( 'Open tool', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
								<?php endif; ?>
							</span>
						</li>
					<?php endif; ?>
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

		<?php $this->render_recommendations( (array) ( $scan['recs'] ?? array() ) ); ?>
		<?php $this->render_usage( $base ); ?>
		<?php $this->render_shortcuts( $base ); ?>
		<?php $this->render_recent_activity( $base ); ?>
		<?php
	}

	/**
	 * State of TSO Link Inspector: missing, inactive or active (with broken-link counts).
	 *
	 * @return array{status: string, broken: int, total: int, has_data: bool}
	 */
	private function link_inspector_state(): array {
		global $wpdb;

		if ( is_array( $this->link_state ) ) {
			return $this->link_state;
		}

		$state = array(
			'status'   => 'missing',
			'broken'   => 0,
			'total'    => 0,
			'has_data' => false,
		);

		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}

		$file = 'tso-link-inspector/tso-link-inspector.php';
		if ( is_plugin_active( $file ) ) {
			$state['status'] = 'active';
			$table           = $wpdb->prefix . 'tso_link_inspector';
			$table_sql       = esc_sql( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
				$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(CASE WHEN last_checked IS NOT NULL AND is_broken = 1 AND user_verified = 0 THEN 1 ELSE 0 END) AS broken FROM {$table_sql}", ARRAY_A );
				if ( is_array( $row ) ) {
					$state['total']    = (int) $row['total'];
					$state['broken']   = (int) $row['broken'];
					$state['has_data'] = $state['total'] > 0;
				}
			}
		} elseif ( array_key_exists( $file, get_plugins() ) ) {
			$state['status'] = 'inactive';
		}

		$this->link_state = $state;

		return $state;
	}

	/**
	 * Extra checks for the Overview score, built from data the site already has.
	 *
	 * @return array<int, array{label: string, status: string, details: string, tab: string, anchor: string, url: string}>
	 */
	private function get_extra_checks(): array {
		global $wpdb;

		$checks = array();
		$make   = static function ( string $label, string $status, string $details, string $tab, string $url = '' ): array {
			return array(
				'label'   => $label,
				'status'  => $status,
				'details' => $details,
				'tab'     => $tab,
				'anchor'  => '',
				'url'     => $url,
			);
		};

		// 1. Broken links (only when TSO Link Inspector is active and has scanned).
		$links = $this->link_inspector_state();
		if ( 'active' === $links['status'] && $links['has_data'] ) {
			$checks[] = $make(
				__( 'Broken links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$links['broken'] > 0 ? 'warn' : 'ok',
				$links['broken'] > 0
					? sprintf(
						/* translators: 1: broken links, 2: tracked links */
						__( '%1$d broken links found among %2$d tracked links (TSO Link Inspector).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$links['broken'],
						$links['total']
					)
					: sprintf(
						/* translators: %d: tracked links */
						__( 'No broken links found among %d tracked links (TSO Link Inspector).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$links['total']
					),
				'dashboard',
				admin_url( 'tools.php?page=tso-link-inspector' )
			);
		}

		// 2. PHP version support window.
		$eol    = TSOSK_Support::PHP_EOL_DATES;
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$label  = __( 'PHP version support', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( isset( $eol[ $branch ] ) ) {
			$end_ts   = (int) strtotime( $eol[ $branch ] . ' 23:59:59 UTC' );
			$end_text = wp_date( get_option( 'date_format' ), $end_ts );
			if ( time() > $end_ts ) {
				$checks[] = $make(
					$label,
					'warn',
					sprintf(
						/* translators: 1: PHP branch, 2: date */
						__( 'PHP %1$s stopped receiving security fixes on %2$s. Ask your host to switch to a newer PHP version.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$branch,
						$end_text
					),
					'runtime-stack'
				);
			} elseif ( ( $end_ts - time() ) < 120 * DAY_IN_SECONDS ) {
				$checks[] = $make(
					$label,
					'warn',
					sprintf(
						/* translators: 1: PHP branch, 2: date */
						__( 'PHP %1$s stops receiving security fixes on %2$s. Plan an upgrade soon.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$branch,
						$end_text
					),
					'runtime-stack'
				);
			} else {
				$checks[] = $make(
					$label,
					'ok',
					sprintf(
						/* translators: 1: PHP branch, 2: date */
						__( 'PHP %1$s is supported until %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$branch,
						$end_text
					),
					'runtime-stack'
				);
			}
		} elseif ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$checks[] = $make(
				$label,
				'warn',
				sprintf(
					/* translators: %s: PHP version */
					__( 'PHP %s is outdated and no longer supported. Ask your host to upgrade.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					PHP_VERSION
				),
				'runtime-stack'
			);
		}

		// 3. Inactive plugins.
		if ( function_exists( 'get_plugins' ) ) {
			$inactive = array();
			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				if ( ! is_plugin_active( $plugin_file ) ) {
					$inactive[] = (string) ( $plugin_data['Name'] ?? $plugin_file );
				}
			}
			$count    = count( $inactive );
			$checks[] = $make(
				__( 'Inactive plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count >= 3 ? 'warn' : 'ok',
				$count >= 3
					? sprintf(
						/* translators: 1: number of inactive plugins, 2: list of plugin names */
						__( '%1$d inactive plugins (%2$s). Delete the ones you do not use: they can still be a security risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$count,
						implode( ', ', array_slice( $inactive, 0, 5 ) ) . ( $count > 5 ? '…' : '' )
					)
					: __( 'No more than two inactive plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'update-manager',
				admin_url( 'plugins.php?plugin_status=inactive' )
			);
		}

		// 4. Expired transients still stored.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%', // Same set as the Transients tool: normal and site transients.
				time()
			)
		);
		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$expired += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s AND CAST(meta_value AS UNSIGNED) < %d",
					(int) $wpdb->siteid,
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					time()
				)
			);
		}
		$checks[] = $make(
			__( 'Expired transients', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$expired >= 100 ? 'warn' : 'ok',
			$expired >= 100
				? sprintf(
					/* translators: %d: number of expired transients */
					__( '%d expired transients are still stored in the database.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$expired
				)
				: __( 'No significant amount of expired transients.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'transients'
		);

		// 5. Compressed files uploaded to the Media Library with no parent post.
		$archives = array( 'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/vnd.rar', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip', 'application/x-gzip', 'application/x-bzip2', 'application/x-xz' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are generated from a fixed list.
		$zips     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0 AND post_mime_type IN (" . implode( ',', array_fill( 0, count( $archives ), '%s' ) ) . ')', ...$archives ) );
		$checks[] = $make(
			__( 'Compressed files in the Media Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$zips > 0 ? 'warn' : 'ok',
			$zips > 0
				? sprintf(
					/* translators: %d: number of compressed files */
					__( '%d compressed files (zip, rar…) without a parent post. They are often plugin or theme packages that can be deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$zips
				)
				: __( 'No compressed files without a parent post.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'media-cleaner'
		);

		return $checks;
	}

	/**
	 * Recommendations: useful tools or actions the site does not have yet (never scored).
	 *
	 * @return array<int, array{title: string, details: string, buttons: array<int, array{label: string, url: string, external: bool}>}>
	 */
	private function get_recommendations(): array {
		$recs  = array();
		$state = $this->link_inspector_state();
		$org   = 'https://wordpress.org/plugins/tso-link-inspector/';

		if ( 'missing' === $state['status'] ) {
			$buttons = array();
			if ( current_user_can( 'install_plugins' ) ) {
				$buttons[] = array(
					'label'    => __( 'Install TSO Link Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'url'      => admin_url( 'plugin-install.php?s=' . rawurlencode( 'TSO Link Inspector' ) . '&tab=search&type=term' ),
					'external' => false,
				);
			}
			$buttons[] = array(
				'label'    => __( 'View on WordPress.org', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'url'      => $org,
				'external' => true,
			);
			$recs[]    = array(
				'title'   => __( 'Detect broken links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'details' => __( 'Broken links hurt SEO and visitors. TSO Link Inspector scans posts, pages, menus and comments, checks each URL and lets you fix links from one screen. It is not installed on this site, so broken links are not being monitored.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'buttons' => $buttons,
			);
		} elseif ( 'inactive' === $state['status'] ) {
			$recs[] = array(
				'title'   => __( 'Detect broken links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'details' => __( 'TSO Link Inspector is installed but inactive. Activate it to scan this site for broken links.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'buttons' => array(
					array(
						'label'    => __( 'Go to Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'url'      => admin_url( 'plugins.php?plugin_status=inactive' ),
						'external' => false,
					),
				),
			);
		} elseif ( ! $state['has_data'] ) {
			$recs[] = array(
				'title'   => __( 'Detect broken links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'details' => __( 'TSO Link Inspector is active but has not scanned the site yet. Run the first scan to see broken links here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'buttons' => array(
					array(
						'label'    => __( 'Open TSO Link Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'url'      => admin_url( 'tools.php?page=tso-link-inspector' ),
						'external' => false,
					),
				),
			);
		}

		return $recs;
	}

	/**
	 * Score history (last scans) for the trend line.
	 *
	 * @return array<int, array{time: int, score: int}>
	 */
	private function get_history(): array {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		$out = array();
		foreach ( $history as $row ) {
			if ( is_array( $row ) && isset( $row['time'], $row['score'] ) ) {
				$out[] = array(
					'time'  => (int) $row['time'],
					'score' => max( 0, min( 100, (int) $row['score'] ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Remember the score of a fresh scan (skips an identical score recorded less than an hour ago).
	 *
	 * @param int $score Score 0-100.
	 */
	private function record_history( int $score ): void {
		$history = $this->get_history();
		$last    = end( $history );
		if ( is_array( $last ) && $last['score'] === $score && ( time() - $last['time'] ) < HOUR_IN_SECONDS ) {
			return;
		}
		$history[] = array(
			'time'  => time(),
			'score' => $score,
		);
		update_option( self::HISTORY_OPTION, array_slice( $history, -self::HISTORY_MAX ), false );
	}

	/**
	 * Trend line: change since the previous scan plus a small sparkline.
	 */
	private function render_trend(): void {
		$history = $this->get_history();
		$n       = count( $history );
		if ( $n < 2 ) {
			echo '<p class="tsosk-dash-meta">' . esc_html__( 'The score trend appears after the second scan.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
			return;
		}

		$delta = $history[ $n - 1 ]['score'] - $history[ $n - 2 ]['score'];
		if ( 0 === $delta ) {
			$text = __( 'Same score as the previous scan.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			$text = sprintf(
				/* translators: %s: signed score change, e.g. +3 or -5 */
				__( '%s points since the previous scan.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				( $delta > 0 ? '+' : '' ) . $delta
			);
		}

		$width  = 160;
		$height = 36;
		$points = array();
		foreach ( $history as $i => $row ) {
			$x        = round( $i * ( $width / ( $n - 1 ) ), 1 );
			$y        = round( 2 + ( $height - 4 ) * ( 1 - $row['score'] / 100 ), 1 );
			$points[] = $x . ',' . $y;
		}
		?>
		<p class="tsosk-dash-meta tsosk-dash-trend">
			<svg viewBox="0 0 <?php echo esc_attr( (string) $width ); ?> <?php echo esc_attr( (string) $height ); ?>" width="<?php echo esc_attr( (string) $width ); ?>" height="<?php echo esc_attr( (string) $height ); ?>" role="img" aria-label="<?php esc_attr_e( 'Score trend', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>" focusable="false">
				<polyline points="<?php echo esc_attr( implode( ' ', $points ) ); ?>"></polyline>
			</svg>
			<span><?php echo esc_html( $text ); ?></span>
		</p>
		<?php
	}

	/**
	 * Cheap numeric facts for the second row of tiles.
	 *
	 * @return array<int, array{label: string, value: string, sub: string, warn?: bool, url?: string}>
	 */
	private function get_usage_tiles(): array {
		global $wpdb;

		$since = wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$tiles = array();

		// Visits and downloads (View Counter tables); silent when they do not exist.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$visits = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type IN ('post','page') AND view_date >= %s", $since ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$downs     = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type = 'download' AND view_date >= %s", $since ) );
		$vc_failed = '' !== (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( ! $vc_failed ) {
			$vc_settings   = class_exists( 'TSOSK_Mod_View_Counter' ) ? TSOSK_Mod_View_Counter::get_instance()->get_settings() : array();
			$counts_visits = empty( $vc_settings ) || ! empty( $vc_settings['post_types'] );
			$counts_downs  = empty( $vc_settings ) || ! empty( $vc_settings['track_downloads'] );
			$tiles[]       = array(
				'label' => __( 'Visits (7 days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => $counts_visits ? number_format_i18n( (int) $visits ) : '—',
				'sub'   => $counts_visits ? __( 'Posts and pages', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Counting is off in the View Counter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'url'   => $this->tile_url( 'view-counter', 'TSOSK_Mod_View_Counter' ),
			);
			$tiles[]       = array(
				'label' => __( 'File downloads (7 days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => $counts_downs ? number_format_i18n( (int) $downs ) : '—',
				'sub'   => $counts_downs ? __( 'Counted by the View Counter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Counting is off in the View Counter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'url'   => $this->tile_url( 'view-counter', 'TSOSK_Mod_View_Counter' ),
			);
		}

		// Database size (cached for an hour: information_schema can be slow on big sites).
		$db = get_transient( 'tsosk_dash_dbsize' );
		if ( ! is_array( $db ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT COUNT(*) AS tables_count, SUM(data_length + index_length) AS bytes FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
					DB_NAME,
					$wpdb->esc_like( is_multisite() ? $wpdb->base_prefix : $wpdb->prefix ) . '%'
				),
				ARRAY_A
			);
			$db  = array(
				'tables' => is_array( $row ) ? (int) $row['tables_count'] : 0,
				'bytes'  => is_array( $row ) ? (int) $row['bytes'] : 0,
			);
			set_transient( 'tsosk_dash_dbsize', $db, HOUR_IN_SECONDS );
		}
		if ( $db['bytes'] > 0 ) {
			$tiles[] = array(
				'label' => __( 'Database size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => size_format( $db['bytes'], 1 ),
				'sub'   => sprintf(
					/* translators: %d: number of tables */
					__( '%d tables', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$db['tables']
				),
				'url'   => $this->tile_url( 'database', 'TSOSK_Mod_Database' ),
			);
		}

		// Autoloaded options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$auto = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')", ARRAY_A );
		if ( is_array( $auto ) ) {
			$tiles[] = array(
				'label' => __( 'Autoloaded options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => size_format( (int) $auto['bytes'], 1 ),
				'sub'   => sprintf(
					/* translators: %d: number of options */
					__( '%d options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $auto['n']
				),
				'warn'  => (int) $auto['bytes'] > 1000000,
				'url'   => $this->tile_url( 'health', 'TSOSK_Mod_Health' ),
			);
		}

		// Uploads folder (from the last Media Footprint scan, never scanned here).
		$footprint = class_exists( 'TSOSK_Uploads_Scanner' ) ? get_transient( TSOSK_Uploads_Scanner::TRANSIENT_FOOTPRINT ) : false;
		$tiles[]   = array(
			'label' => __( 'Uploads folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'value' => is_array( $footprint ) && isset( $footprint['total_bytes'] ) ? size_format( (int) $footprint['total_bytes'], 1 ) : '—',
			'sub'   => is_array( $footprint ) ? '' : __( 'Run the Media Footprint scan', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'url'   => $this->tile_url( 'media-footprint', 'TSOSK_Mod_Media_Footprint' ),
		);

		// debug.log: same files the Debug tab lists (the active log and wp-content/debug.log), not only the active path.
		$logs     = TSOSK_Support::get_debug_log_files();
		$readable = array_values(
			array_filter(
				$logs,
				static function ( array $log ): bool {
					return $log['readable'];
				}
			)
		);
		$largest  = array();
		foreach ( $readable as $log ) {
			if ( empty( $largest ) || $log['size'] > $largest['size'] ) {
				$largest = $log;
			}
		}
		if ( ! empty( $largest ) ) {
			$log_value = size_format( $largest['size'], 1 );
			// Name the file only when it is not the usual debug.log, or when there are several.
			$log_sub = ( 'debug.log' !== wp_basename( $largest['path'] ) || count( $readable ) > 1 )
				? wp_basename( $largest['path'] ) . ( count( $readable ) > 1 ? ' +' . ( count( $readable ) - 1 ) : '' )
				: '';
		} else {
			$log_value = '—';
			$log_sub   = empty( $logs ) ? __( 'No debug.log file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Not readable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		$tiles[] = array(
			'label' => 'debug.log',
			'value' => $log_value,
			'sub'   => $log_sub,
			'warn'  => ! empty( $largest ) && $largest['size'] > TSOSK_Support::DEBUG_LOG_WARN_BYTES,
			'url'   => $this->tile_url( 'debug', 'TSOSK_Mod_Debug' ),
		);

		// Users.
		$user_query = new WP_User_Query(
			array(
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			)
		);
		$tiles[]    = array(
			'label' => __( 'Users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'value' => number_format_i18n( (int) $user_query->get_total() ),
			'sub'   => '',
			'url'   => $this->tile_url( 'users', 'TSOSK_Mod_Users' ),
		);

		// Cron events and how many hooks have no registered callback.
		$events     = 0;
		$no_handler = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) $hooks as $hook => $instances ) {
				$events += is_array( $instances ) ? count( $instances ) : 0;
				if ( ! has_action( (string) $hook ) ) {
					$no_handler[ (string) $hook ] = true;
				}
			}
		}
		$tiles[] = array(
			'label' => __( 'Cron events', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'value' => number_format_i18n( $events ),
			'sub'   => empty( $no_handler )
				? __( 'All have a callback', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: sprintf(
					/* translators: %d: number of cron hooks without callback */
					__( '%d hooks without callback', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $no_handler )
				),
			'warn'  => ! empty( $no_handler ),
			'url'   => $this->tile_url( 'cron', 'TSOSK_Mod_Cron' ),
		);

		return $tiles;
	}

	/**
	 * Admin URL of a tool tab for a clickable tile (empty when that tool is not loaded).
	 *
	 * @param string $slug  Tab slug.
	 * @param string $module_class Module class that must exist for the tab to be available.
	 * @return string
	 */
	private function tile_url( string $slug, string $module_class ): string {
		return class_exists( $module_class ) ? add_query_arg( 'tab', $slug, $this->base_url() ) : '';
	}

	/**
	 * Tiles grid (shared markup). A tile with a URL is a link to the tool behind the number.
	 *
	 * @param array<int, array{label: string, value: string, sub: string, warn?: bool, url?: string}> $tiles Tiles.
	 */
	private function render_tiles( array $tiles ): void {
		?>
		<div class="tsosk-dash-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<?php
				$url   = isset( $tile['url'] ) ? (string) $tile['url'] : '';
				$class = 'tsosk-dash-tile' . ( ! empty( $tile['warn'] ) ? ' is-warn' : '' );
				$tag   = '' !== $url ? 'a' : 'div';
				?>
				<<?php echo esc_html( $tag ); ?> class="<?php echo esc_attr( $class ); ?>"<?php echo '' !== $url ? ' href="' . esc_url( $url ) . '"' : ''; ?>>
					<span class="tsosk-dash-tile-label"><?php echo esc_html( $tile['label'] ); ?></span>
					<span class="tsosk-dash-tile-value"><?php echo esc_html( $tile['value'] ); ?></span>
					<?php if ( '' !== $tile['sub'] ) : ?>
						<span class="tsosk-dash-tile-sub"><?php echo esc_html( $tile['sub'] ); ?></span>
					<?php endif; ?>
				</<?php echo esc_html( $tag ); ?>>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Recommendations card (tools the site could add; never part of the score).
	 *
	 * @param array<int, array{title: string, details: string, buttons: array<int, array{label: string, url: string, external: bool}>}> $recs Recommendations.
	 */
	private function render_recommendations( array $recs ): void {
		if ( empty( $recs ) ) {
			return;
		}
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recommendations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<ul class="tsosk-dash-issues">
				<?php foreach ( $recs as $rec ) : ?>
					<li>
						<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Tip', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<span class="tsosk-dash-issue-body">
							<strong><?php echo esc_html( $rec['title'] ); ?></strong>
							<span class="tsosk-dash-issue-details"><?php echo esc_html( $rec['details'] ); ?></span>
						</span>
						<span>
							<?php foreach ( $rec['buttons'] as $button ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $button['url'] ); ?>"<?php echo ! empty( $button['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $button['label'] ); ?></a>
							<?php endforeach; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Site usage card: most viewed pages, most downloaded files, most frequent 404s (last 7 days).
	 *
	 * @param string $base Plugin admin URL.
	 */
	private function render_usage( string $base ): void {
		$posts = array();
		$downs = array();
		if ( class_exists( 'TSOSK_Mod_View_Counter' ) ) {
			global $wpdb;
			$suppress = $wpdb->suppress_errors( true );
			$vc       = TSOSK_Mod_View_Counter::get_instance();
			$posts    = $vc->get_top_posts( 7, 3 );
			$downs    = $vc->get_top_links( 7, 3, 'download' );
			$wpdb->suppress_errors( $suppress );
		}

		$not_found = array();
		$log       = get_option( 'tsosk_404_log', array() );
		if ( is_array( $log ) ) {
			$rows = array_filter(
				$log,
				static function ( $row ): bool {
					return is_array( $row ) && isset( $row['path'] ) && empty( $row['bot_suspect'] );
				}
			);
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return (int) ( $b['hits'] ?? 0 ) <=> (int) ( $a['hits'] ?? 0 );
				}
			);
			$not_found = array_slice( $rows, 0, 3 );
		}
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Site usage (last 7 days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-vc-top-wrap">
				<div class="tsosk-vc-col">
					<h4><?php esc_html_e( 'Most viewed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<?php if ( empty( $posts ) ) : ?>
						<p class="tsosk-dash-empty"><?php esc_html_e( 'No data yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					<?php else : ?>
						<ul class="tsosk-dash-activity">
							<?php foreach ( $posts as $row ) : ?>
								<li><span><?php echo esc_html( $row['title'] ); ?></span> <strong><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></strong></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<div class="tsosk-vc-col">
					<h4><?php esc_html_e( 'Most downloaded files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<?php if ( empty( $downs ) ) : ?>
						<p class="tsosk-dash-empty"><?php esc_html_e( 'No data yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					<?php else : ?>
						<ul class="tsosk-dash-activity">
							<?php foreach ( $downs as $row ) : ?>
								<li><span><?php echo esc_html( wp_basename( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) ); ?></span> <strong><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></strong></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<div class="tsosk-vc-col">
					<h4><?php esc_html_e( 'Most frequent 404s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<?php if ( empty( $not_found ) ) : ?>
						<p class="tsosk-dash-empty"><?php esc_html_e( 'No data yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					<?php else : ?>
						<ul class="tsosk-dash-activity">
							<?php foreach ( $not_found as $row ) : ?>
								<li><span class="tsosk-code"><?php echo esc_html( (string) $row['path'] ); ?></span> <strong><?php echo esc_html( number_format_i18n( (int) ( $row['hits'] ?? 0 ) ) ); ?></strong></li>
							<?php endforeach; ?>
						</ul>
						<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'redirects', $base ) ); ?>"><?php esc_html_e( 'Open the 404 monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * "Core 0 · Plugins 0 · Themes 0 · Translations 2": same wording in the tile and in the "Needs attention" row.
	 *
	 * @param array{core: int, plugins: int, themes: int, translations: int} $updates Pending updates.
	 */
	private function updates_breakdown( array $updates ): string {
		return sprintf(
			/* translators: 1: core updates, 2: plugin updates, 3: theme updates, 4: language pack updates */
			__( 'Core %1$d · Plugins %2$d · Themes %3$d · Translations %4$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$updates['core'],
			$updates['plugins'],
			$updates['themes'],
			$updates['translations']
		);
	}

	/**
	 * Vitals tiles (cheap, local data only).
	 *
	 * @param array{core: int, plugins: int, themes: int, translations: int, checked: bool, restricted: bool} $updates Pending updates.
	 */
	private function render_vitals( array $updates ): void {
		$total  = $updates['core'] + $updates['plugins'] + $updates['themes'] + $updates['translations'];
		$active = TSOSK_Support::get_active_plugin_files();
		$active = is_array( $active ) ? count( $active ) : 0;

		$tiles = array(
			array(
				'label' => __( 'WordPress', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => get_bloginfo( 'version' ),
				'sub'   => wp_get_environment_type(),
				'url'   => $this->tile_url( 'update-manager', 'TSOSK_Mod_Update_Manager' ),
			),
			array(
				'label' => __( 'PHP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => PHP_VERSION,
				'sub'   => sprintf(
					/* translators: %s: PHP memory limit, e.g. 256M */
					__( 'Memory limit %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					// -1 means "no limit", not a literal value.
					'-1' === (string) ini_get( 'memory_limit' )
						? sprintf( __( '%s (no limit)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), '-1' )
						: (string) ini_get( 'memory_limit' )
				),
				'url'   => $this->tile_url( 'runtime-stack', 'TSOSK_Mod_Runtime_Stack' ),
			),
			array(
				'label' => __( 'Active plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => (string) $active,
				'sub'   => '',
				'url'   => admin_url( 'plugins.php?plugin_status=active' ),
			),
			array(
				'label' => __( 'Pending updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => $updates['checked'] ? (string) $total : '—',
				'sub'   => $updates['checked']
					? $this->updates_breakdown( $updates )
					: ( ! empty( $updates['restricted'] )
						? __( 'Update checks are restricted in the Update Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						: __( 'No update check has run yet', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
				'warn'  => $updates['checked'] && $total > 0,
				'url'   => $this->tile_url( 'update-manager', 'TSOSK_Mod_Update_Manager' ),
			),
		);
		$this->render_tiles( $tiles );
	}

	/**
	 * Shortcut buttons to frequently used tools.
	 *
	 * @param string $base Plugin admin URL.
	 */
	private function render_shortcuts( string $base ): void {
		$links     = array(
			array( 'health', 'TSOSK_Mod_Health', __( 'Health Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-chart-area' ),
			array( 'security', 'TSOSK_Mod_Security', __( 'Security Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-shield-alt' ),
			array( 'debug', 'TSOSK_Mod_Debug', __( 'Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-admin-generic' ),
			array( 'cron', 'TSOSK_Mod_Cron', __( 'Cron Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-clock' ),
			array( 'transients', 'TSOSK_Mod_Transients', __( 'Transients', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-database-remove' ),
			array( 'options-editor', 'TSOSK_Mod_Options_Editor', __( 'Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-edit' ),
			array( 'maintenance', 'TSOSK_Mod_Maintenance', __( 'Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-visibility' ),
			array( 'sandbox', 'TSOSK_Mod_Sandbox', __( 'Plugin Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'dashicons-shield' ),
		);
		$favorites = class_exists( 'TSOSK_Admin' ) ? TSOSK_Admin::get_instance()->get_favorite_shortcuts() : array();
		if ( ! empty( $favorites ) ) {
			$links = $favorites;
		}
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Shortcuts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( ! empty( $favorites ) ) : ?>
				<p class="description"><?php esc_html_e( 'Shortcuts follow the tools in your Favorites bar.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php endif; ?>
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
