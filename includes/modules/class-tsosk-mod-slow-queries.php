<?php
/**
 * TSO Swiss Knife – Module: Slow Query Monitor.
 *
 * Captures database queries that exceed a configurable time threshold and
 * stores a persistent log in wp_options. The monitor hooks into `shutdown`
 * so it captures every page request — admin pages, front-end, REST API,
 * WP-Cron and AJAX — as long as SAVEQUERIES is enabled.
 *
 * Features:
 *  – Configurable threshold (default 100 ms).
 *  – Persistent log (up to 500 entries, stored per-request batch).
 *  – Duplicate detection: marks queries that repeat across requests.
 *  – Per-request grouping: each logged batch carries URL, timestamp, load time.
 *  – Statistics: total slow queries, slowest ever, most frequent.
 *  – Filters: search by SQL text, by caller, by URL.
 *  – Clear log / clear individual entry AJAX actions.
 *  – On/Off toggle that activates SAVEQUERIES automatically via the tsosk
 *    config file so the module actually receives query data.
 *
 * @package TSO_Swiss_Knife
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Slow_Queries
 */
class TSOSK_Mod_Slow_Queries {

	/** wp_options key for the slow-query log. */
	private const LOG_OPTION      = 'tsosk_slow_query_log';

	/** wp_options key for module settings. */
	private const SETTINGS_OPTION = 'tsosk_slow_query_settings';

	/** Maximum entries kept in the persistent log. */
	private const MAX_ENTRIES = 500;

	/** Default slow-query threshold in milliseconds. */
	private const DEFAULT_THRESHOLD_MS = 100;

	/** @var TSOSK_Mod_Slow_Queries|null */
	private static $instance = null;

	/** @return TSOSK_Mod_Slow_Queries */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register shutdown hook only when monitoring is active AND SAVEQUERIES is on.
		if ( $this->is_monitoring_active() ) {
			add_action( 'shutdown', array( $this, 'capture_slow_queries' ), 999 );
		}

		// AJAX handlers.
		add_action( 'wp_ajax_tsosk_sq_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_tsosk_sq_clear_log',     array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_sq_delete_entry',  array( $this, 'ajax_delete_entry' ) );
		add_action( 'wp_ajax_tsosk_sq_get_log',       array( $this, 'ajax_get_log' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	/**
	 * Return settings with safe defaults.
	 *
	 * @return array{enabled:bool,threshold_ms:int,max_entries:int,exclude_ajax:bool,exclude_cron:bool}
	 */
	private function get_settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array(
			'enabled'      => (bool) ( $s['enabled']      ?? false ),
			'threshold_ms' => max( 1, min( 10000, (int) ( $s['threshold_ms'] ?? self::DEFAULT_THRESHOLD_MS ) ) ),
			'max_entries'  => max( 50, min( 2000, (int) ( $s['max_entries']  ?? self::MAX_ENTRIES ) ) ),
			'exclude_ajax' => (bool) ( $s['exclude_ajax'] ?? false ),
			'exclude_cron' => (bool) ( $s['exclude_cron'] ?? true ),
		);
	}

	/**
	 * Check if monitoring is active: enabled in settings AND SAVEQUERIES constant is true.
	 *
	 * @return bool
	 */
	private function is_monitoring_active(): bool {
		$s = $this->get_settings();
		return $s['enabled'] && defined( 'SAVEQUERIES' ) && SAVEQUERIES;
	}

	// ── Shutdown capture ─────────────────────────────────────────────────────

	/**
	 * Called on `shutdown` — scan $wpdb->queries for slow ones and persist them.
	 */
	public function capture_slow_queries(): void {
		global $wpdb;

		if ( ! is_array( $wpdb->queries ) || empty( $wpdb->queries ) ) {
			return;
		}

		$s             = $this->get_settings();
		$threshold_sec = $s['threshold_ms'] / 1000.0;

		// Context detection.
		$is_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_cron = defined( 'DOING_CRON' ) && DOING_CRON;

		if ( $s['exclude_ajax'] && $is_ajax ) {
			return;
		}
		if ( $s['exclude_cron'] && $is_cron ) {
			return;
		}

		// Collect slow queries from this request.
		$slow = array();
		foreach ( $wpdb->queries as $q ) {
			$time = (float) ( $q[1] ?? 0 );
			if ( $time < $threshold_sec ) {
				continue;
			}
			$sql    = preg_replace( '/\s+/', ' ', trim( (string) $q[0] ) );
			$caller = (string) ( $q[2] ?? '' );
			// Strip internal wpdb frames from caller.
			$frames = array_filter(
				array_map( 'trim', explode( ',', $caller ) ),
				static function ( string $f ): bool {
					return '' !== $f
						&& 0 !== strpos( $f, 'wpdb->' )
						&& 0 !== strpos( $f, 'require(' );
				}
			);
			$slow[] = array(
				'sql'    => $sql,
				'time'   => round( $time * 1000, 3 ), // ms
				'caller' => implode( ' → ', array_slice( array_values( $frames ), -3 ) ),
			);
		}

		if ( empty( $slow ) ) {
			return;
		}

		// Determine current URL.
		$request_url = '';
		if ( $is_cron ) {
			$request_url = 'WP-Cron';
		} elseif ( $is_ajax ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action      = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$request_url = 'AJAX: ' . $action;
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_url = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		// Total page load time.
		$load_time = defined( 'WP_START_TIMESTAMP' ) ? round( ( microtime( true ) - WP_START_TIMESTAMP ) * 1000, 1 ) : 0;

		// Append batch to log.
		$log = $this->get_log();
		$log[] = array(
			'ts'        => time(),
			'url'       => $request_url,
			'load_ms'   => $load_time,
			'slow_count'=> count( $slow ),
			'queries'   => $slow,
		);

		// Trim to max.
		$max = $s['max_entries'];
		if ( count( $log ) > $max ) {
			$log = array_slice( $log, - $max );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	// ── Data helpers ─────────────────────────────────────────────────────────

	/**
	 * Get the full log array.
	 *
	 * @return array<int,array{ts:int,url:string,load_ms:float,slow_count:int,queries:array}>
	 */
	private function get_log(): array {
		$v = get_option( self::LOG_OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Compute summary statistics from the log.
	 *
	 * @param array $log Full log.
	 * @return array{total_slow:int,total_batches:int,slowest_ms:float,slowest_sql:string,top_callers:array,top_sqls:array}
	 */
	private function compute_stats( array $log ): array {
		$total_slow    = 0;
		$slowest_ms    = 0.0;
		$slowest_sql   = '';
		$caller_counts = array();
		$sql_counts    = array();

		foreach ( $log as $batch ) {
			foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
				$total_slow++;
				$t   = (float) $q['time'];
				$sql = (string) $q['sql'];
				$cal = (string) ( $q['caller'] ?? '' );

				if ( $t > $slowest_ms ) {
					$slowest_ms  = $t;
					$slowest_sql = $sql;
				}

				$sql_norm = md5( preg_replace( '/\b\d+\b/', '?', $sql ) );
				$sql_counts[ $sql_norm ] = array(
					'count' => ( $sql_counts[ $sql_norm ]['count'] ?? 0 ) + 1,
					'sql'   => $sql,
					'max'   => max( $sql_counts[ $sql_norm ]['max'] ?? 0, $t ),
				);

				if ( $cal ) {
					$caller_counts[ $cal ] = ( $caller_counts[ $cal ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $sql_counts );
		arsort( $caller_counts );

		return array(
			'total_slow'   => $total_slow,
			'total_batches'=> count( $log ),
			'slowest_ms'   => $slowest_ms,
			'slowest_sql'  => $slowest_sql,
			'top_callers'  => array_slice( $caller_counts, 0, 5, true ),
			'top_sqls'     => array_slice( $sql_counts,    0, 5, true ),
		);
	}

	// ── AJAX ─────────────────────────────────────────────────────────────────

	/** AJAX: save settings. */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$new = array(
			'enabled'      => ! empty( $_POST['enabled'] ),
			'threshold_ms' => max( 1, min( 10000, absint( wp_unslash( $_POST['threshold_ms'] ?? 100 ) ) ) ),
			'max_entries'  => max( 50, min( 2000, absint( wp_unslash( $_POST['max_entries']  ?? 500 ) ) ) ),
			'exclude_ajax' => ! empty( $_POST['exclude_ajax'] ),
			'exclude_cron' => ! empty( $_POST['exclude_cron'] ),
		);

		update_option( self::SETTINGS_OPTION, $new, false );

		$warn_savequeries = false;
		$message          = __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		if ( $new['enabled'] && ! ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) ) {
			if ( class_exists( 'TSOSK_Mod_Debug' ) ) {
				$result = TSOSK_Mod_Debug::get_instance()->set_savequeries_flag( true );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}
				$message          = __( 'Settings saved. SAVEQUERIES was enabled in the debug config — reload the page to start capturing queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$warn_savequeries = true;
			} else {
				$message          = __( 'Settings saved. SAVEQUERIES is not active — enable it in Debug Mode so queries are captured.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$warn_savequeries = true;
			}
		}

		TSOSK_Activity_Log::log(
			'slow-queries',
			'save',
			$new['enabled']
				? __( 'Slow query logging enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Slow query logging disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success( array(
			'message'          => $message,
			'warn_savequeries' => $warn_savequeries,
		) );
	}

	/** AJAX: clear the full log. */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		delete_option( self::LOG_OPTION );
		TSOSK_Activity_Log::log( 'slow-queries', 'delete', __( 'Slow query log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: delete one batch entry from the log. */
	public function ajax_delete_entry(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$idx = absint( wp_unslash( $_POST['idx'] ?? -1 ) );
		$log = $this->get_log();
		if ( isset( $log[ $idx ] ) ) {
			array_splice( $log, $idx, 1 );
			update_option( self::LOG_OPTION, $log, false );
		}
		wp_send_json_success( __( 'Entry deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: get log page (for JS pagination). */
	public function ajax_get_log(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$page     = max( 1, absint( wp_unslash( $_POST['page']   ?? 1 ) ) );
		$per_page = 20;
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$log = array_reverse( $this->get_log() ); // newest first

		// Filter by search.
		if ( $search ) {
			$lc = strtolower( $search );
			$log = array_values( array_filter( $log, static function ( array $batch ) use ( $lc ): bool {
				if ( false !== strpos( strtolower( $batch['url'] ?? '' ), $lc ) ) {
					return true;
				}
				foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
					if ( false !== strpos( strtolower( $q['sql'] ), $lc ) ) {
						return true;
					}
					if ( false !== strpos( strtolower( $q['caller'] ?? '' ), $lc ) ) {
						return true;
					}
				}
				return false;
			} ) );
		}

		$total = count( $log );
		$items = array_slice( $log, ( $page - 1 ) * $per_page, $per_page );

		// Re-index for delete operations.
		$full_log = array_reverse( $this->get_log() );
		$items_with_idx = array();
		foreach ( $items as $batch ) {
			$orig_idx = array_search( $batch, $full_log, true );
			$items_with_idx[] = array(
				'idx'        => $orig_idx,
				'ts'         => $batch['ts'],
				'url'        => $batch['url'],
				'load_ms'    => $batch['load_ms'],
				'slow_count' => $batch['slow_count'],
				'queries'    => $batch['queries'],
			);
		}

		wp_send_json_success( array(
			'items'       => $items_with_idx,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		) );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		$s                 = $this->get_settings();
		$nonce             = wp_create_nonce( 'tsosk_sq_nonce' );
		$log               = $this->get_log();
		$stats             = $this->compute_stats( $log );
		$savequeries_on    = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$monitoring_active = $s['enabled'] && $savequeries_on;
		?>

		<p class="tsosk-desc">
			<?php esc_html_e( 'Captures and logs database queries that take longer than a configurable threshold. Helps identify performance bottlenecks caused by slow or repeated queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php /* ── SAVEQUERIES warning ── */ ?>
		<?php if ( $s['enabled'] && ! $savequeries_on ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<strong><?php esc_html_e( '⚠ SAVEQUERIES is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php esc_html_e( 'The monitor is enabled but cannot capture queries because SAVEQUERIES is false. Go to Debug Mode → Constants and add SAVEQUERIES = true to wp-config.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php elseif ( ! $s['enabled'] ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'The monitor is disabled. Enable it below to start capturing slow queries. SAVEQUERIES must also be active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php else : ?>
		<div class="tsosk-notice tsosk-notice-ok" style="border-left-color:#46b450;background:#f0fff0;">
			<strong style="color:#1a5c1a;"><?php esc_html_e( '✓ Monitor active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php
			printf(
				/* translators: %d: threshold in milliseconds */
				esc_html__( 'Capturing queries slower than %d ms on every page request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $s['threshold_ms']
			);
			?>
		</div>
		<?php endif; ?>

		<?php /* ── Settings card ── */ ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Monitor Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table" style="width:100%;max-width:600px;">
				<tr>
					<th style="width:200px;"><?php esc_html_e( 'Enable monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-enabled" value="1"
							       <?php checked( $s['enabled'] ); ?>>
							<?php esc_html_e( 'Capture slow queries on every request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Slow threshold', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-sq-threshold"
						       value="<?php echo esc_attr( (string) $s['threshold_ms'] ); ?>"
						       min="1" max="10000" step="1" style="width:90px;">
						<span class="description">
							<?php esc_html_e( 'ms — queries slower than this are logged (recommended: 100)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Max log entries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-sq-max-entries"
						       value="<?php echo esc_attr( (string) $s['max_entries'] ); ?>"
						       min="50" max="2000" step="50" style="width:90px;">
						<span class="description">
							<?php esc_html_e( 'request batches (oldest are removed when limit is reached)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Exclude', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" id="tsosk-sq-exclude-ajax" value="1"
							       <?php checked( $s['exclude_ajax'] ); ?>>
							<?php esc_html_e( 'AJAX requests', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<label>
							<input type="checkbox" id="tsosk-sq-exclude-cron" value="1"
							       <?php checked( $s['exclude_cron'] ); ?>>
							<?php esc_html_e( 'WP-Cron requests', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
				<button class="button button-primary" id="tsosk-sq-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sq-settings-msg"></span>
			</div>
		</div>

		<?php /* ── Stats cards ── */ ?>
		<?php if ( ! empty( $log ) ) : ?>
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
			<?php
			$stat_cards = array(
				array(
					'val'   => $stats['total_slow'],
					'lbl'   => __( 'Slow queries logged', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn'  => $stats['total_slow'] > 50,
				),
				array(
					'val'   => $stats['total_batches'],
					'lbl'   => __( 'Requests captured', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn'  => false,
				),
				array(
					'val'   => number_format( $stats['slowest_ms'], 2 ) . ' ms',
					'lbl'   => __( 'Slowest query', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn'  => $stats['slowest_ms'] > 500,
				),
			);
			foreach ( $stat_cards as $card ) :
			?>
			<div class="tsosk-sq-stat">
				<span class="tsosk-sq-stat-val <?php echo $card['warn'] ? 'tsosk-sq-warn' : ''; ?>">
					<?php echo esc_html( (string) $card['val'] ); ?>
				</span>
				<span class="tsosk-sq-stat-lbl"><?php echo esc_html( $card['lbl'] ); ?></span>
			</div>
			<?php endforeach; ?>
		</div>

		<?php /* ── Top offenders ── */ ?>
		<?php if ( ! empty( $stats['top_sqls'] ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Top Slow Queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Queries grouped by normalised SQL pattern (numbers replaced with ?). Those appearing most often or taking longest are listed first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th style="width:8%;"><?php esc_html_e( 'Count', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Max time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'SQL pattern', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $stats['top_sqls'] as $entry ) : ?>
					<tr>
						<td>
							<span class="tsosk-badge tsosk-badge-<?php echo $entry['count'] > 5 ? 'warn' : 'info'; ?>"
							      style="font-size:11px;">
								×<?php echo esc_html( (string) $entry['count'] ); ?>
							</span>
						</td>
						<td style="font-family:monospace;font-size:12px;">
							<span style="color:<?php echo $entry['max'] > 500 ? '#d63638' : ( $entry['max'] > 200 ? '#d97706' : '#374151' ); ?>;font-weight:600;">
								<?php echo esc_html( number_format( $entry['max'], 2 ) ); ?> ms
							</span>
						</td>
						<td class="tsosk-code" style="font-size:11px;word-break:break-all;color:#1d2327;">
							<?php
							$sql_short = mb_substr( (string) $entry['sql'], 0, 200 );
							echo esc_html( $sql_short );
							if ( mb_strlen( (string) $entry['sql'] ) > 200 ) {
								echo '<span style="color:#8c8f94;"> …</span>';
							}
							?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<?php /* ── Log table ── */ ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Slow Query Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $log ) ); ?> / <?php echo esc_html( (string) $s['max_entries'] ); ?>
				</span>
			</h3>

			<div class="tsosk-toolbar" style="gap:8px;margin-bottom:12px;">
				<input type="text" id="tsosk-sq-search"
				       placeholder="<?php esc_attr_e( 'Filter by SQL, URL or caller…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				       style="min-width:260px;" autocomplete="off">
				<button class="button" id="tsosk-sq-search-btn"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button button-link-delete" id="tsosk-sq-clear-btn"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sq-log-msg"></span>
			</div>

			<div id="tsosk-sq-log-wrap">
				<div id="tsosk-sq-pagination-top" class="tsosk-oe-pagination"></div>
				<div id="tsosk-sq-log-body">
					<?php $this->render_log_batches( $log, $nonce, $s['threshold_ms'] ); ?>
				</div>
				<div id="tsosk-sq-pagination-bot" class="tsosk-oe-pagination"></div>
			</div>
		</div>
		<?php else : ?>

		<div class="tsosk-card">
			<p style="color:#646970;">
				<?php if ( $monitoring_active ) : ?>
					<?php esc_html_e( 'No slow queries have been recorded yet. The monitor is active — entries will appear here after page requests that contain queries exceeding the threshold.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No data. Enable the monitor and activate SAVEQUERIES to start capturing slow queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<?php endif; ?>
			</p>
		</div>

		<?php endif; ?>

		<style>
		.tsosk-sq-stat {
			display:flex; flex-direction:column; align-items:center;
			background:#f6f7f7; border:1px solid #e2e4e7; border-radius:8px;
			padding:10px 20px; min-width:120px; text-align:center;
		}
		.tsosk-sq-stat-val { font-size:22px; font-weight:700; color:#1d2327; line-height:1.2; }
		.tsosk-sq-stat-lbl { font-size:11px; color:#646970; margin-top:3px; }
		.tsosk-sq-warn     { color:#d63638 !important; }
		.tsosk-sq-batch {
			border:1px solid #e2e4e7; border-radius:6px; margin-bottom:10px; overflow:hidden;
		}
		.tsosk-sq-batch-header {
			display:flex; align-items:center; gap:10px; flex-wrap:wrap;
			padding:8px 12px; background:#f6f7f7; cursor:pointer;
			border-bottom:1px solid #e2e4e7;
		}
		.tsosk-sq-batch-header:hover { background:#eef0f1; }
		.tsosk-sq-batch-url  { font-family:monospace; font-size:12px; flex:1; min-width:0; word-break:break-all; color:#1d2327; }
		.tsosk-sq-batch-body { display:none; padding:0; }
		.tsosk-sq-batch-body.open { display:block; }
		.tsosk-sq-query-row  { padding:8px 12px; border-bottom:1px solid #f0f0f0; }
		.tsosk-sq-query-row:last-child { border-bottom:none; }
		.tsosk-sq-query-sql  {
			font-family:monospace; font-size:11px; color:#1d2327;
			word-break:break-all; white-space:pre-wrap; margin:4px 0;
			background:#f8f9fa; padding:6px 8px; border-radius:3px;
		}
		.tsosk-sq-query-caller { font-size:11px; color:#646970; margin-top:3px; }
		</style>
		<?php
	}

	/**
	 * Render log batches inline (used on initial page load).
	 *
	 * @param array  $log            Full log array (will be sliced to first 20).
	 * @param string $nonce          WP nonce.
	 * @param int    $threshold_ms   Configured threshold.
	 */
	private function render_log_batches( array $log, string $nonce, int $threshold_ms ): void {
		if ( empty( $log ) ) {
			return;
		}

		// Show newest first, paginated 20 per page server-side initially.
		$log_rev  = array_reverse( $log );
		$total    = count( $log_rev );
		$per_page = 20;
		$slice    = array_slice( $log_rev, 0, $per_page );

		echo '<div id="tsosk-sq-batches">';
		foreach ( $slice as $idx_rev => $batch ) {
			$orig_idx  = $total - 1 - $idx_rev;
			$this->render_single_batch( $batch, $orig_idx, $nonce, $threshold_ms );
		}
		echo '</div>';

		// Server-side pagination placeholder (JS will handle AJAX paging).
		if ( $total > $per_page ) {
			echo '<div class="tsosk-oe-pagination" style="margin-top:10px;">';
			printf(
				'<span style="font-size:12px;color:#646970;">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: per page, 2: total */
						__( 'Showing %1$d of %2$d batches. Use search or pagination to view more.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$per_page,
						$total
					)
				)
			);
			echo '</div>';
		}
	}

	/**
	 * Render a single request batch.
	 *
	 * @param array  $batch         Batch data.
	 * @param int    $orig_idx      Original index in the log (for delete).
	 * @param string $nonce         WP nonce.
	 * @param int    $threshold_ms  Threshold.
	 */
	private function render_single_batch( array $batch, int $orig_idx, string $nonce, int $threshold_ms ): void {
		$ts         = (int) $batch['ts'];
		$url        = (string) ( $batch['url'] ?? '' );
		$load       = (float) ( $batch['load_ms'] ?? 0 );
		$slow_count = (int) ( $batch['slow_count'] ?? 0 );
		$queries    = (array) ( $batch['queries'] ?? array() );
		$max_time   = $queries ? max( array_column( $queries, 'time' ) ) : 0;
		?>
		<div class="tsosk-sq-batch" id="tsosk-sq-batch-<?php echo esc_attr( (string) $orig_idx ); ?>">
			<div class="tsosk-sq-batch-header" data-idx="<?php echo esc_attr( (string) $orig_idx ); ?>">
				<span class="tsosk-badge tsosk-badge-<?php echo $slow_count > 5 ? 'warn' : 'info'; ?>"
				      style="font-size:11px;flex-shrink:0;">
					<?php
					printf(
						/* translators: %d: number of slow queries */
						esc_html__( '%d slow', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $slow_count
					);
					?>
				</span>
				<span class="tsosk-sq-batch-url"><?php echo esc_html( $url ?: '—' ); ?></span>
				<span style="font-size:11px;color:#646970;white-space:nowrap;flex-shrink:0;">
					<?php echo esc_html( gmdate( 'Y-m-d H:i', $ts ) ); ?> UTC
				</span>
				<?php if ( $load > 0 ) : ?>
				<span style="font-size:11px;color:#8c8f94;white-space:nowrap;flex-shrink:0;">
					<?php
					printf(
						/* translators: %s: page load time */
						esc_html__( 'Page: %s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format( $load, 1 ) )
					);
					?>
				</span>
				<?php endif; ?>
				<span style="font-size:11px;font-weight:600;color:<?php echo $max_time > 500 ? '#d63638' : ( $max_time > 200 ? '#d97706' : '#374151' ); ?>;white-space:nowrap;flex-shrink:0;">
					<?php
					printf(
						/* translators: %s: milliseconds */
						esc_html__( 'Worst: %s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format( $max_time, 2 ) )
					);
					?>
				</span>
				<button class="button button-small tsosk-sq-delete-batch" style="margin-left:auto;flex-shrink:0;"
				        data-idx="<?php echo esc_attr( (string) $orig_idx ); ?>"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-sq-toggle-icon" style="font-size:12px;flex-shrink:0;color:#646970;">▼</span>
			</div>
			<div class="tsosk-sq-batch-body">
				<?php foreach ( $queries as $qi => $q ) :
					$t   = (float) $q['time'];
					$sql = (string) $q['sql'];
					$cal = (string) ( $q['caller'] ?? '' );
					$kw  = strtoupper( strtok( $sql, ' ' ) );
					$kw_colors = array(
						'SELECT' => '#2271b1', 'INSERT' => '#16a34a', 'UPDATE' => '#d97706',
						'DELETE' => '#d63638', 'CREATE' => '#7c3aed', 'DROP'   => '#d63638',
					);
					$kw_c = $kw_colors[ $kw ] ?? '#374151';
				?>
				<div class="tsosk-sq-query-row">
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
						<span style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $t > 500 ? '#d63638' : ( $t > 200 ? '#d97706' : '#374151' ) ); ?>;">
							<?php echo esc_html( number_format( $t, 3 ) ); ?> ms
						</span>
						<span class="tsosk-badge" style="font-size:10px;background:<?php echo esc_attr( $kw_c ); ?>20;color:<?php echo esc_attr( $kw_c ); ?>;">
							<?php echo esc_html( $kw ); ?>
						</span>
						<span style="font-size:11px;color:#8c8f94;">#<?php echo esc_html( (string) ( $qi + 1 ) ); ?></span>
					</div>
					<div class="tsosk-sq-query-sql"><?php echo esc_html( $sql ); ?></div>
					<?php if ( $cal ) : ?>
					<div class="tsosk-sq-query-caller">
						<span style="color:#8c8f94;">↳</span> <?php echo esc_html( $cal ); ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
