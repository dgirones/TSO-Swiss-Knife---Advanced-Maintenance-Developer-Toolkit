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
 *  – SQL fingerprint grouping (Top Slow Query Patterns).
 *  – Ignore patterns (substring / fingerprint) to skip noisy queries.
 *  – Export log as CSV or JSON.
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
		add_action( 'wp_ajax_tsosk_sq_ignore_pattern', array( $this, 'ajax_ignore_pattern' ) );
		add_action( 'admin_post_tsosk_sq_export', array( $this, 'handle_export' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	/**
	 * Return settings with safe defaults.
	 *
	 * @return array{enabled:bool,threshold_ms:int,max_entries:int,exclude_ajax:bool,exclude_cron:bool,ignore_patterns:array<int,string>}
	 */
	private function get_settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$patterns = $s['ignore_patterns'] ?? array();
		if ( is_string( $patterns ) ) {
			$patterns = preg_split( '/\r\n|\r|\n/', $patterns ) ?: array();
		}
		if ( ! is_array( $patterns ) ) {
			$patterns = array();
		}
		$patterns = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $p ): string {
							// Keep fingerprints intact (?, %, underscores). Only trim length/control chars.
							$p = trim( (string) $p );
							$p = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $p );
							return is_string( $p ) ? $p : '';
						},
						$patterns
					),
					static function ( string $p ): bool {
						return '' !== $p;
					}
				)
			)
		);
		if ( count( $patterns ) > 50 ) {
			$patterns = array_slice( $patterns, 0, 50 );
		}

		return array(
			'enabled'         => (bool) ( $s['enabled'] ?? false ),
			'threshold_ms'    => max( 1, min( 10000, (int) ( $s['threshold_ms'] ?? self::DEFAULT_THRESHOLD_MS ) ) ),
			'max_entries'     => max( 50, min( 2000, (int) ( $s['max_entries'] ?? self::MAX_ENTRIES ) ) ),
			'exclude_ajax'    => (bool) ( $s['exclude_ajax'] ?? false ),
			'exclude_cron'    => (bool) ( $s['exclude_cron'] ?? true ),
			'ignore_patterns' => $patterns,
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
		$slow     = array();
		$patterns = $s['ignore_patterns'];
		foreach ( $wpdb->queries as $q ) {
			$time = (float) ( $q[1] ?? 0 );
			if ( $time < $threshold_sec ) {
				continue;
			}
			$sql = preg_replace( '/\s+/', ' ', trim( (string) $q[0] ) );
			if ( ! is_string( $sql ) || '' === $sql ) {
				continue;
			}
			if ( $this->is_ignored_sql( $sql, $patterns ) ) {
				continue;
			}
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
				'sql'         => $sql,
				'fingerprint' => $this->fingerprint_sql( $sql ),
				'time'        => round( $time * 1000, 3 ), // ms
				'caller'      => implode( ' → ', array_slice( array_values( $frames ), -3 ) ),
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
	 * Normalise SQL into a fingerprint pattern (literals → ?).
	 *
	 * @param string $sql Raw SQL.
	 * @return string
	 */
	private function fingerprint_sql( string $sql ): string {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( ! is_string( $sql ) || '' === $sql ) {
			return '';
		}
		// Quoted string literals.
		$sql = preg_replace( "/'(?:\\\\'|[^'])*'/", '?', $sql );
		$sql = preg_replace( '/"(?:\\\\"|[^"])*"/', '?', $sql );
		if ( ! is_string( $sql ) ) {
			return '';
		}
		// Numeric literals.
		$sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		if ( ! is_string( $sql ) ) {
			return '';
		}
		// Collapse long IN (?, ?, ?) lists.
		$sql = preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql );
		return is_string( $sql ) ? $sql : '';
	}

	/**
	 * Whether a SQL string matches any ignore pattern (substring or fingerprint).
	 *
	 * @param string               $sql      Raw SQL.
	 * @param array<int, string>   $patterns Ignore patterns.
	 * @return bool
	 */
	private function is_ignored_sql( string $sql, array $patterns ): bool {
		if ( empty( $patterns ) ) {
			return false;
		}
		$fp    = $this->fingerprint_sql( $sql );
		$sql_l = strtolower( $sql );
		$fp_l  = strtolower( $fp );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			$pl = strtolower( $pattern );
			if ( $fp_l === $pl || false !== strpos( $sql_l, $pl ) || false !== strpos( $fp_l, $pl ) ) {
				return true;
			}
		}
		return false;
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
		$patterns      = $this->get_settings()['ignore_patterns'];

		foreach ( $log as $batch ) {
			foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
				$sql = (string) ( $q['sql'] ?? '' );
				if ( '' === $sql ) {
					continue;
				}
				// Keep ignored historical rows out of Top Patterns (they stay in the raw log until cleared).
				if ( $this->is_ignored_sql( $sql, $patterns ) ) {
					continue;
				}

				$total_slow++;
				$t   = (float) ( $q['time'] ?? 0 );
				$cal = (string) ( $q['caller'] ?? '' );
				$fp  = isset( $q['fingerprint'] ) && is_string( $q['fingerprint'] ) && '' !== $q['fingerprint']
					? $q['fingerprint']
					: $this->fingerprint_sql( $sql );

				if ( $t > $slowest_ms ) {
					$slowest_ms  = $t;
					$slowest_sql = $sql;
				}

				$key  = md5( $fp );
				$prev = $sql_counts[ $key ] ?? array(
					'count'       => 0,
					'total_ms'    => 0.0,
					'max'         => 0.0,
					'sql'         => $sql,
					'fingerprint' => $fp,
				);
				$prev['count']++;
				$prev['total_ms']   += $t;
				$prev['max']         = max( (float) $prev['max'], $t );
				$prev['fingerprint'] = $fp;
				if ( mb_strlen( $sql ) < mb_strlen( (string) $prev['sql'] ) ) {
					$prev['sql'] = $sql;
				}
				$sql_counts[ $key ] = $prev;

				if ( $cal ) {
					$caller_counts[ $cal ] = ( $caller_counts[ $cal ] ?? 0 ) + 1;
				}
			}
		}

		uasort(
			$sql_counts,
			static function ( array $a, array $b ): int {
				if ( $a['count'] === $b['count'] ) {
					return $b['max'] <=> $a['max'];
				}
				return $b['count'] <=> $a['count'];
			}
		);
		arsort( $caller_counts );

		$top = array();
		foreach ( array_slice( $sql_counts, 0, 10, true ) as $entry ) {
			$entry['avg'] = $entry['count'] > 0 ? round( $entry['total_ms'] / $entry['count'], 2 ) : 0.0;
			$top[]        = $entry;
		}

		return array(
			'total_slow'    => $total_slow,
			'total_batches' => count( $log ),
			'slowest_ms'    => $slowest_ms,
			'slowest_sql'   => $slowest_sql,
			'top_callers'   => array_slice( $caller_counts, 0, 5, true ),
			'top_sqls'      => $top,
		);
	}

	/**
	 * Admin bar menu with live request metrics (Query Monitor style).
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function admin_bar_menu( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->get_settings();
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		if ( ! $savequeries && ! $settings['enabled'] ) {
			return;
		}

		$live       = $this->get_current_request_query_stats();
		$log_stats  = $this->compute_stats( $this->get_log() );
		$tab_url    = admin_url( 'tools.php?page=tso-swiss-knife&tab=slow-queries' );
		$debug_url  = admin_url( 'tools.php?page=tso-swiss-knife&tab=debug' );

		$load_s  = $live ? round( $live['load_ms'] / 1000, 2 ) : 0;
		$q_count = $live ? $live['query_count'] : 0;
		$title   = $savequeries
			? sprintf(
				/* translators: 1: page load seconds, 2: query count */
				__( '%1$ss · %2$dQ', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( $load_s, 2 ),
				$q_count
			)
			: __( 'Slow queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'tsosk-slow-queries',
				'title' => esc_html( $title ),
				'href'  => $tab_url,
				'meta'  => array(
					'class' => 'tsosk-sq-admin-bar-root',
				),
			)
		);

		if ( $live ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-overview',
					'title'  => esc_html(
						sprintf(
							/* translators: 1: load ms, 2: memory MB, 3: query time ms */
							__( 'Page %1$s ms · Memory %2$s MB · DB %3$s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							number_format_i18n( $live['load_ms'], 1 ),
							number_format_i18n( $live['memory_mb'], 1 ),
							number_format_i18n( $live['query_time_ms'], 1 )
						)
					),
				)
			);

			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-queries',
					'title'  => esc_html(
						sprintf(
							/* translators: 1: query count, 2: slow count, 3: threshold ms */
							__( 'This request: %1$d queries (%2$d slow ≥ %3$d ms)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$live['query_count'],
							$live['slow_count'],
							$settings['threshold_ms']
						)
					),
					'href'   => $tab_url . '#tsosk-sq-live-viewer',
				)
			);

			if ( $live['slowest_ms'] > 0 ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-slowest',
						'title'  => esc_html(
							sprintf(
								/* translators: 1: milliseconds, 2: SQL excerpt */
								__( 'Slowest: %1$s ms — %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								number_format_i18n( $live['slowest_ms'], 2 ),
								$this->admin_bar_excerpt( $live['slowest_sql'], 72 )
							)
						),
						'href'   => $tab_url . '#tsosk-sq-live-viewer',
					)
				);
			}
		} elseif ( ! $savequeries ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-enable',
					'title'  => esc_html__( 'SAVEQUERIES is off — enable monitoring on the Slow Query tab', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'href'   => $tab_url,
				)
			);
		}

		if ( $log_stats['total_slow'] > 0 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-log',
					'title'  => esc_html(
						sprintf(
							/* translators: 1: slow query count, 2: batch count */
							__( 'Logged slow queries: %1$d (%2$d requests)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$log_stats['total_slow'],
							$log_stats['total_batches']
						)
					),
					'href'   => $tab_url . '#tsosk-sq-log',
				)
			);

			if ( ! empty( $log_stats['top_sqls'][0] ) ) {
				$top = $log_stats['top_sqls'][0];
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-top',
						'title'  => esc_html(
							sprintf(
								/* translators: 1: hit count, 2: max ms, 3: SQL excerpt */
								__( 'Top pattern: %1$d× (max %2$s ms) — %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $top['count'],
								number_format_i18n( (float) $top['max'], 1 ),
								$this->admin_bar_excerpt( (string) $top['fingerprint'], 60 )
							)
						),
						'href'   => $tab_url . '#tsosk-sq-patterns',
					)
				);
			}
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'tsosk-slow-queries',
				'id'     => 'tsosk-sq-ab-open',
				'title'  => esc_html__( 'Open Slow Query Monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'href'   => $tab_url,
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'tsosk-slow-queries',
				'id'     => 'tsosk-sq-ab-debug',
				'title'  => esc_html__( 'Open Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'href'   => $debug_url,
			)
		);
	}

	/**
	 * Enqueue minimal admin-bar submenu styles.
	 */
	public function enqueue_admin_bar_styles(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$css = '#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-submenu { min-width: 320px; max-width: min(92vw, 560px); }
#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-item { white-space: nowrap; }
#wpadminbar .tsosk-sq-admin-bar-root > .ab-item { font-weight: 600; }';
		wp_register_style( 'tsosk-sq-admin-bar', false, array(), TSOSK_VERSION );
		wp_enqueue_style( 'tsosk-sq-admin-bar' );
		wp_add_inline_style( 'tsosk-sq-admin-bar', $css );
	}

	/**
	 * Collect query stats for the current request.
	 *
	 * @return array{load_ms:float,memory_mb:float,query_count:int,query_time_ms:float,slow_count:int,slowest_ms:float,slowest_sql:string}|null
	 */
	private function get_current_request_query_stats(): ?array {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! is_array( $wpdb->queries ) ) {
			return null;
		}

		$threshold_sec = $this->get_settings()['threshold_ms'] / 1000.0;
		$query_time_ms = 0.0;
		$slow_count    = 0;
		$slowest_ms    = 0.0;
		$slowest_sql   = '';

		foreach ( $wpdb->queries as $q ) {
			$time = (float) ( $q[1] ?? 0 );
			$query_time_ms += $time * 1000;
			if ( $time >= $threshold_sec ) {
				++$slow_count;
				$t_ms = $time * 1000;
				if ( $t_ms > $slowest_ms ) {
					$slowest_ms  = $t_ms;
					$slowest_sql = (string) ( $q[0] ?? '' );
				}
			}
		}

		$load_ms = defined( 'WP_START_TIMESTAMP' ) ? ( microtime( true ) - WP_START_TIMESTAMP ) * 1000 : 0;

		return array(
			'load_ms'        => round( $load_ms, 1 ),
			'memory_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'query_count'    => count( $wpdb->queries ),
			'query_time_ms'  => round( $query_time_ms, 1 ),
			'slow_count'     => $slow_count,
			'slowest_ms'     => round( $slowest_ms, 2 ),
			'slowest_sql'    => preg_replace( '/\s+/', ' ', trim( $slowest_sql ) ),
		);
	}

	/**
	 * Shorten SQL/fingerprint text for admin-bar menu rows.
	 *
	 * @param string $text   Source text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function admin_bar_excerpt( string $text, int $length = 60 ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( ! is_string( $text ) || '' === $text ) {
			return '—';
		}
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return mb_substr( $text, 0, $length - 1 ) . '…';
	}

	// ── AJAX ─────────────────────────────────────────────────────────────────

	/** AJAX: save settings. */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$raw_ignore = isset( $_POST['ignore_patterns'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ignore_patterns'] ) )
			: '';
		$ignore_lines = preg_split( '/\r\n|\r|\n/', $raw_ignore ) ?: array();
		$ignore_patterns = array();
		foreach ( $ignore_lines as $line ) {
			$line = trim( (string) $line );
			$line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line );
			$line = is_string( $line ) ? $line : '';
			if ( '' !== $line && mb_strlen( $line ) <= 2000 ) {
				$ignore_patterns[] = $line;
			}
		}
		$ignore_patterns = array_values( array_unique( array_slice( $ignore_patterns, 0, 50 ) ) );

		$new = array(
			'enabled'         => ! empty( $_POST['enabled'] ),
			'threshold_ms'    => max( 1, min( 10000, absint( wp_unslash( $_POST['threshold_ms'] ?? 100 ) ) ) ),
			'max_entries'     => max( 50, min( 2000, absint( wp_unslash( $_POST['max_entries'] ?? 500 ) ) ) ),
			'exclude_ajax'    => ! empty( $_POST['exclude_ajax'] ),
			'exclude_cron'    => ! empty( $_POST['exclude_cron'] ),
			'ignore_patterns' => $ignore_patterns,
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

	/**
	 * AJAX: add one ignore pattern (from Top Slow Queries).
	 */
	public function ajax_ignore_pattern(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$pattern = isset( $_POST['pattern'] )
			? sanitize_textarea_field( wp_unslash( $_POST['pattern'] ) )
			: '';
		$normalized = preg_replace( '/\s+/', ' ', $pattern );
		$pattern    = is_string( $normalized ) ? trim( $normalized ) : trim( $pattern );
		if ( '' === $pattern || mb_strlen( $pattern ) > 2000 ) {
			wp_send_json_error( __( 'Invalid ignore pattern.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Avoid sanitize_text_field() here — it can alter SQL fingerprints (?, %, etc.).
		$s        = $this->get_settings();
		$patterns = $s['ignore_patterns'];
		foreach ( $patterns as $existing ) {
			if ( 0 === strcasecmp( (string) $existing, $pattern ) ) {
				wp_send_json_success(
					array(
						'message'  => __( 'Pattern ignored. Matching queries will no longer be logged. Save settings or reload to refresh the ignore list field.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'patterns' => $patterns,
					)
				);
			}
		}
		$patterns[] = $pattern;
		$patterns   = array_slice( $patterns, 0, 50 );
		$s['ignore_patterns'] = $patterns;
		update_option( self::SETTINGS_OPTION, $s, false );

		TSOSK_Activity_Log::log( 'slow-queries', 'save', __( 'Slow query ignore pattern added.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Pattern ignored. Matching queries will no longer be logged. Save settings or reload to refresh the ignore list field.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'patterns' => $patterns,
			)
		);
	}

	/**
	 * Download slow query log as CSV or JSON.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		check_admin_referer( 'tsosk_sq_export' );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			$format = 'csv';
		}

		$log  = $this->get_log();
		$stamp = gmdate( 'Y-m-d-His' );

		if ( 'json' === $format ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tsosk-slow-queries-' . $stamp . '.json"' );
			echo wp_json_encode(
				array(
					'exported_at' => gmdate( 'c' ),
					'site'        => home_url( '/' ),
					'batches'     => $log,
					'patterns'    => $this->compute_stats( $log )['top_sqls'],
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-slow-queries-' . $stamp . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output stream for download.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open export stream.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM for Excel.
		fputcsv( $out, array( 'timestamp_utc', 'url', 'load_ms', 'query_ms', 'fingerprint', 'sql', 'caller' ) );
		foreach ( $log as $batch ) {
			$ts  = isset( $batch['ts'] ) ? gmdate( 'c', (int) $batch['ts'] ) : '';
			$url = (string) ( $batch['url'] ?? '' );
			$load = (float) ( $batch['load_ms'] ?? 0 );
			foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
				$sql = (string) ( $q['sql'] ?? '' );
				$fp  = isset( $q['fingerprint'] ) ? (string) $q['fingerprint'] : $this->fingerprint_sql( $sql );
				fputcsv(
					$out,
					array(
						$ts,
						$url,
						$load,
						(float) ( $q['time'] ?? 0 ),
						$fp,
						$sql,
						(string) ( $q['caller'] ?? '' ),
					)
				);
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
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
		if ( ! isset( $_POST['idx'] ) ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$idx = absint( wp_unslash( $_POST['idx'] ) );
		$log = $this->get_log();
		if ( ! array_key_exists( $idx, $log ) ) {
			wp_send_json_error( __( 'Entry not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		array_splice( $log, $idx, 1 );
		update_option( self::LOG_OPTION, $log, false );
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

		$chrono = $this->get_log(); // chronological; delete uses these indexes.
		$log    = array_reverse( $chrono ); // newest first for display.

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

		// Map each displayed batch to its chronological index for delete.
		$items_with_idx = array();
		foreach ( $items as $batch ) {
			$orig_idx = array_search( $batch, $chrono, true );
			if ( false === $orig_idx ) {
				continue;
			}
			$items_with_idx[] = array(
				'idx'        => (int) $orig_idx,
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
			<?php esc_html_e( 'The monitor is enabled but cannot capture queries because SAVEQUERIES is false. Enable Developer mode in Debug Mode, or set SAVEQUERIES in wp-config.php manually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
				<tr>
					<th><?php esc_html_e( 'Ignore patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-sq-ignore-patterns" rows="4" style="width:100%;max-width:520px;font-family:monospace;font-size:12px;"
						          placeholder="<?php esc_attr_e( 'One pattern per line (substring or fingerprint). Example: action_scheduler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_textarea( implode( "\n", $s['ignore_patterns'] ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Matching SQL (case-insensitive substring or fingerprint) is not logged. Max 50 lines.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
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
		<div class="tsosk-card" id="tsosk-sq-patterns">
			<h3><?php esc_html_e( 'Top Slow Query Patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Queries grouped by SQL fingerprint (string/number literals replaced with ?). Use Ignore to stop logging a known noisy pattern.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th style="width:7%;"><?php esc_html_e( 'Count', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Max', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Avg', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Fingerprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:90px;"></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $stats['top_sqls'] as $entry ) : ?>
					<?php
					$fp_display = (string) ( $entry['fingerprint'] ?? $entry['sql'] );
					?>
					<tr>
						<td>
							<span class="tsosk-badge tsosk-badge-<?php echo $entry['count'] > 5 ? 'warn' : 'info'; ?>"
							      style="font-size:11px;">
								×<?php echo esc_html( (string) $entry['count'] ); ?>
							</span>
						</td>
						<td style="font-family:monospace;font-size:12px;">
							<span style="color:<?php echo $entry['max'] > 500 ? '#d63638' : ( $entry['max'] > 200 ? '#d97706' : '#374151' ); ?>;font-weight:600;">
								<?php echo esc_html( number_format( (float) $entry['max'], 2 ) ); ?> ms
							</span>
						</td>
						<td style="font-family:monospace;font-size:12px;">
							<?php echo esc_html( number_format( (float) ( $entry['avg'] ?? 0 ), 2 ) ); ?> ms
						</td>
						<td class="tsosk-code" style="font-size:11px;word-break:break-all;color:#1d2327;">
							<?php
							$sql_short = mb_substr( $fp_display, 0, 220 );
							echo esc_html( $sql_short );
							if ( mb_strlen( $fp_display ) > 220 ) {
								echo ' …';
							}
							?>
						</td>
						<td>
							<button type="button" class="button button-small tsosk-sq-ignore-pattern"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>"
							        data-pattern="<?php echo esc_attr( $fp_display ); ?>">
								<?php esc_html_e( 'Ignore', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<span class="tsosk-ajax-msg" id="tsosk-sq-pattern-msg"></span>
		</div>
		<?php endif; ?>

		<?php /* ── Log table ── */ ?>
		<div class="tsosk-card" id="tsosk-sq-log">
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
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tsosk_sq_export&format=csv' ), 'tsosk_sq_export' ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tsosk_sq_export&format=json' ), 'tsosk_sq_export' ) ); ?>">
					<?php esc_html_e( 'Export JSON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
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

		<?php $this->render_savequeries_viewer(); ?>

		<?php
	}

	/**
	 * Live SAVEQUERIES table for the current admin page load.
	 */
	private function render_savequeries_viewer(): void {
		global $wpdb;

		$sq_enabled = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$sq_queries = $sq_enabled && is_array( $wpdb->queries ) ? $wpdb->queries : array();
		$sq_count   = count( $sq_queries );
		$sq_total   = $sq_enabled ? array_sum( array_column( $sq_queries, 1 ) ) : 0;
		$sq_max     = $sq_count ? max( array_column( $sq_queries, 1 ) ) : 0;
		$sq_sql_map = array();
		foreach ( $sq_queries as $q ) {
			$sql = preg_replace( '/\s+/', ' ', trim( (string) $q[0] ) );
			if ( ! is_string( $sql ) || '' === $sql ) {
				continue;
			}
			$sq_sql_map[ $sql ] = ( $sq_sql_map[ $sql ] ?? 0 ) + 1;
		}
		$sq_dupes = array_filter( $sq_sql_map, static fn( $n ) => $n > 1 );
		?>
		<div class="tsosk-card" id="tsosk-sq-live-viewer">
			<h3>
				<span class="dashicons dashicons-database" aria-hidden="true"></span>
				<?php esc_html_e( 'Database Queries (SAVEQUERIES)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<?php if ( $sq_enabled && $sq_count ) : ?>
				<span class="tsosk-badge <?php echo $sq_count > 100 ? 'tsosk-badge-warn' : 'tsosk-badge-info'; ?>"
				      style="margin-left:8px;font-size:12px;">
					<?php
					printf(
						/* translators: %d: number of queries on this page load */
						esc_html__( '%d queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $sq_count
					);
					?>
				</span>
				<?php endif; ?>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Live list of database queries executed while loading this Slow Query Monitor page. Enable the monitor above (it can turn SAVEQUERIES on for you) or use Developer mode in Debug Mode, then reload.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<?php if ( ! $sq_enabled ) : ?>
			<div class="tsosk-notice tsosk-notice-info">
				<strong><?php esc_html_e( 'SAVEQUERIES is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Enable the monitor and save settings, or turn on Developer mode in Debug Mode, then reload this page. Only use SAVEQUERIES while debugging — it has a memory overhead on every request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php elseif ( ! $sq_count ) : ?>
			<p class="description">
				<?php esc_html_e( 'SAVEQUERIES is active but no queries were recorded yet. Reload the page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php else : ?>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_count > 100 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( (string) $sq_count ); ?>
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Total queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_total > 0.5 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( number_format( $sq_total * 1000, 2 ) ); ?> ms
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Total time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo $sq_max > 0.1 ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( number_format( $sq_max * 1000, 2 ) ); ?> ms
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Slowest query', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<?php if ( ! empty( $sq_dupes ) ) : ?>
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val tsosk-sq-warn">
						<?php echo esc_html( (string) count( $sq_dupes ) ); ?>
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Duplicate queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $sq_dupes ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ Duplicate queries detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of distinct duplicate queries */
					esc_html__( '%d distinct queries are executed more than once. This often indicates a plugin calling get_option(), get_post_meta() or similar in a loop without caching.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) count( $sq_dupes )
				);
				?>
			</div>
			<?php endif; ?>

			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
				<input type="text" id="tsosk-sq-filter"
				       placeholder="<?php esc_attr_e( 'Filter queries…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				       style="min-width:220px;" autocomplete="off">
				<label style="font-size:13px;">
					<input type="checkbox" id="tsosk-sq-dupes-only">
					<?php esc_html_e( 'Show duplicates only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<label style="font-size:13px;">
					<input type="checkbox" id="tsosk-sq-slow-only">
					<?php esc_html_e( 'Show slow only (>5 ms)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<span id="tsosk-sq-count-shown" style="font-size:12px;color:#646970;"></span>
			</div>

			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-sq-table">
				<thead><tr>
					<th style="width:44px;">#</th>
					<th style="width:70px;"><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Called by', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$sorted = $sq_queries;
				usort( $sorted, static fn( $a, $b ) => $b[1] <=> $a[1] );
				foreach ( $sorted as $i => $q ) :
					$sql_raw   = (string) $q[0];
					$time_ms   = (float) $q[1] * 1000;
					$caller    = (string) ( $q[2] ?? '' );
					$sql_clean = preg_replace( '/\s+/', ' ', trim( $sql_raw ) );
					$is_slow   = $time_ms > 5;
					$is_dupe   = ( $sq_sql_map[ $sql_clean ] ?? 0 ) > 1;
					$kw        = strtoupper( strtok( $sql_clean, ' ' ) );
					$kw_color  = array(
						'SELECT' => '#2271b1',
						'INSERT' => '#16a34a',
						'UPDATE' => '#d97706',
						'DELETE' => '#d63638',
						'CREATE' => '#7c3aed',
						'DROP'   => '#d63638',
						'ALTER'  => '#7c3aed',
						'SHOW'   => '#646970',
					);
					$kw_c = $kw_color[ $kw ] ?? '#374151';
					?>
				<tr class="tsosk-sq-row<?php echo $is_slow ? ' tsosk-sq-slow' : ''; ?><?php echo $is_dupe ? ' tsosk-sq-dupe' : ''; ?>"
				    data-sql="<?php echo esc_attr( strtolower( $sql_clean ) ); ?>"
				    data-dupe="<?php echo $is_dupe ? '1' : '0'; ?>"
				    data-slow="<?php echo $is_slow ? '1' : '0'; ?>">
					<td style="color:#646970;font-size:12px;"><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
					<td style="font-family:monospace;font-size:12px;">
						<span style="color:<?php echo esc_attr( $is_slow ? '#d63638' : ( $time_ms > 2 ? '#d97706' : '#16a34a' ) ); ?>;font-weight:600;">
							<?php echo esc_html( number_format( $time_ms, 3 ) ); ?> ms
						</span>
					</td>
					<td style="word-break:break-word;">
						<?php if ( $is_dupe ) : ?>
						<span class="tsosk-badge tsosk-badge-warn" style="font-size:10px;margin-right:4px;">
							<?php
							printf(
								/* translators: %d: number of times query ran */
								esc_html__( '×%d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $sq_sql_map[ $sql_clean ]
							);
							?>
						</span>
						<?php endif; ?>
						<span class="tsosk-badge" style="background:<?php echo esc_attr( $kw_c ); ?>20;color:<?php echo esc_attr( $kw_c ); ?>;font-size:10px;margin-right:4px;">
							<?php echo esc_html( $kw ); ?>
						</span>
						<code style="font-size:11px;color:#1d2327;background:none;word-break:break-all;">
							<?php echo esc_html( mb_substr( $sql_clean, strlen( $kw ) + 1 ) ); ?>
						</code>
					</td>
					<td style="font-size:11px;color:#646970;word-break:break-word;">
						<?php
						$frames = array_filter(
							array_map( 'trim', explode( ',', $caller ) ),
							static fn( $f ) => '' !== $f && ! in_array( $f, array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_var', 'wpdb->get_row', 'wpdb->prepare' ), true )
						);
						$frames = array_slice( array_values( $frames ), -3 );
						echo esc_html( implode( ' → ', $frames ) );
						?>
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
