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

	/** Maximum slow queries stored per request batch (bounds option size). */
	private const MAX_QUERIES_PER_BATCH = 100;

	/** Transient key for log write lock. */
	private const LOG_LOCK_TRANSIENT = 'tsosk_sq_log_lock';

	/** @var TSOSK_Mod_Slow_Queries|null */
	private static $instance = null;

	/**
	 * Per-query raw backtraces captured this request (only populated when
	 * the "capture origin" setting is on), keyed by $wpdb->queries index.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private $query_backtraces = array();

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

		// Opt-in: record a lightweight backtrace per query so slow ones can be
		// attributed to a plugin, theme, or WordPress core.
		if ( $this->is_monitoring_active() && ! empty( $this->get_settings()['capture_origin'] ) ) {
			add_filter( 'query', array( $this, 'capture_query_backtrace' ), 0 );
		}

		// Persist exact duplicate SQL from the last admin request (for the live viewer).
		add_action( 'shutdown', array( $this, 'capture_duplicate_snapshot' ), 998 );

		// AJAX handlers.
		add_action( 'wp_ajax_tsosk_sq_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_tsosk_sq_clear_log',     array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_sq_delete_entry',  array( $this, 'ajax_delete_entry' ) );
		add_action( 'wp_ajax_tsosk_sq_get_log',       array( $this, 'ajax_get_log' ) );
		add_action( 'wp_ajax_tsosk_sq_ignore_pattern', array( $this, 'ajax_ignore_pattern' ) );
		add_action( 'admin_post_tsosk_sq_export', array( $this, 'handle_export' ) );

		$settings = $this->get_settings();
		if ( ! empty( $settings['show_admin_bar'] ) ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 999 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
		}
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	/**
	 * Return settings with safe defaults.
	 *
	 * @return array{enabled:bool,threshold_ms:int,max_entries:int,exclude_ajax:bool,exclude_cron:bool,show_admin_bar:bool,capture_origin:bool,ignore_patterns:array<int,string>}
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

		// Default admin bar on when the key was never saved (existing installs).
		$show_admin_bar = array_key_exists( 'show_admin_bar', $s )
			? (bool) $s['show_admin_bar']
			: true;

		return array(
			'enabled'         => (bool) ( $s['enabled'] ?? false ),
			'threshold_ms'    => max( 1, min( 10000, (int) ( $s['threshold_ms'] ?? self::DEFAULT_THRESHOLD_MS ) ) ),
			'max_entries'     => max( 50, min( 2000, (int) ( $s['max_entries'] ?? self::MAX_ENTRIES ) ) ),
			'exclude_ajax'    => (bool) ( $s['exclude_ajax'] ?? false ),
			'exclude_cron'    => (bool) ( $s['exclude_cron'] ?? true ),
			'show_admin_bar'  => $show_admin_bar,
			'capture_origin'  => (bool) ( $s['capture_origin'] ?? false ),
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
		foreach ( $wpdb->queries as $qi => $q ) {
			$time = (float) ( $q[1] ?? 0 );
			if ( $time < $threshold_sec ) {
				continue;
			}
			$caller = (string) ( $q[2] ?? '' );
			// Queries fired while rendering the admin bar are not the site's own load.
			if ( $this->caller_is_admin_bar( $caller ) ) {
				continue;
			}
			$sql = preg_replace( '/\s+/', ' ', trim( (string) $q[0] ) );
			if ( ! is_string( $sql ) || '' === $sql ) {
				continue;
			}
			if ( $this->is_ignored_sql( $sql, $patterns ) ) {
				continue;
			}
			// Strip internal wpdb frames from caller.
			$frames = array_filter(
				array_map( 'trim', explode( ',', $caller ) ),
				static function ( string $f ): bool {
					return '' !== $f
						&& 0 !== strpos( $f, 'wpdb->' )
						&& 0 !== strpos( $f, 'require(' );
				}
			);
			$origin = '';
			if ( ! empty( $s['capture_origin'] ) && isset( $this->query_backtraces[ $qi ] ) ) {
				$origin = $this->resolve_query_origin( $this->query_backtraces[ $qi ] );
			}
			$slow[] = array(
				'sql'         => $this->redact_sql_for_storage( $sql ),
				'fingerprint' => $this->fingerprint_sql( $sql ),
				'time'        => round( $time * 1000, 3 ), // ms
				'caller'      => implode( ' → ', array_slice( array_values( $frames ), -3 ) ),
				'origin'      => $origin,
			);
			if ( count( $slow ) >= self::MAX_QUERIES_PER_BATCH ) {
				break;
			}
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
			$request_url = TSOSK_Support::get_request_uri_for_display();
		}

		// Total page load time.
		$load_time = defined( 'WP_START_TIMESTAMP' ) ? round( ( microtime( true ) - WP_START_TIMESTAMP ) * 1000, 1 ) : 0;

		$batch = array(
			'id'         => $this->generate_batch_id(),
			'ts'         => time(),
			'url'        => $request_url,
			'load_ms'    => $load_time,
			'slow_count' => count( $slow ),
			'queries'    => $slow,
		);

		$this->mutate_log(
			static function ( array $log ) use ( $batch, $s ): array {
				$log[] = $batch;
				$max   = $s['max_entries'];
				if ( count( $log ) > $max ) {
					$log = array_slice( $log, -$max );
				}
				return $log;
			}
		);
	}

	// ── Data helpers ─────────────────────────────────────────────────────────

	/**
	 * Get the full log array (normalized).
	 *
	 * @return array<int,array{id:string,ts:int,url:string,load_ms:float,slow_count:int,queries:array}>
	 */
	private function get_log(): array {
		$v = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $v ) ) {
			return array();
		}
		$out = array();
		foreach ( $v as $batch ) {
			$normalized = $this->normalize_batch( $batch );
			if ( null !== $normalized ) {
				$out[] = $normalized;
			}
		}
		return $out;
	}

	/**
	 * Normalise one log batch; skip corrupt rows.
	 *
	 * @param mixed $batch Raw batch.
	 * @return array{id:string,ts:int,url:string,load_ms:float,slow_count:int,queries:array}|null
	 */
	private function normalize_batch( $batch ): ?array {
		if ( ! is_array( $batch ) ) {
			return null;
		}
		$queries_in = $batch['queries'] ?? array();
		if ( ! is_array( $queries_in ) ) {
			$queries_in = array();
		}
		$queries = array();
		foreach ( $queries_in as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$sql = isset( $q['sql'] ) ? (string) $q['sql'] : '';
			if ( '' === $sql ) {
				continue;
			}
			$fp = isset( $q['fingerprint'] ) && is_string( $q['fingerprint'] ) && '' !== $q['fingerprint']
				? $q['fingerprint']
				: $this->fingerprint_sql( $sql );
			$queries[] = array(
				'sql'         => $sql,
				'fingerprint' => $fp,
				'time'        => (float) ( $q['time'] ?? 0 ),
				'caller'      => (string) ( $q['caller'] ?? '' ),
			);
		}
		$id = isset( $batch['id'] ) ? (string) $batch['id'] : '';
		if ( '' === $id ) {
			$id = 'legacy_' . md5(
				wp_json_encode(
					array(
						(int) ( $batch['ts'] ?? 0 ),
						(string) ( $batch['url'] ?? '' ),
						$queries,
					)
				)
			);
		}
		return array(
			'id'         => $id,
			'ts'         => (int) ( $batch['ts'] ?? 0 ),
			'url'        => (string) ( $batch['url'] ?? '' ),
			'load_ms'    => (float) ( $batch['load_ms'] ?? 0 ),
			'slow_count' => isset( $batch['slow_count'] ) ? (int) $batch['slow_count'] : count( $queries ),
			'queries'    => $queries,
		);
	}

	/**
	 * Generate a unique batch id.
	 */
	private function generate_batch_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return uniqid( 'tsosk_sq_', true );
	}

	/**
	 * Redact common sensitive literals before persisting SQL.
	 *
	 * @param string $sql Normalised SQL.
	 * @return string
	 */
	private function redact_sql_for_storage( string $sql ): string {
		$redacted = preg_replace(
			'/\b(password|passwd|pwd|user_pass|token|secret|api[_-]?key|auth)\s*=\s*(?:\'[^\']*\'|"[^"]*"|\S+)/i',
			'$1=?',
			$sql
		);
		if ( ! is_string( $redacted ) ) {
			$redacted = $sql;
		}
		// Long quoted strings (likely emails, tokens, serialized blobs).
		$redacted = preg_replace_callback(
			"/'([^']{80,})'/",
			static function ( array $m ): string {
				return "'[redacted " . strlen( $m[1] ) . " chars]'";
			},
			$redacted
		);
		return is_string( $redacted ) ? $redacted : $sql;
	}

	/**
	 * Mutate the log under a short lock to reduce lost updates.
	 *
	 * @param callable(array):(?array) $callback Receives current log; return array to save, null to delete option.
	 */
	private function mutate_log( callable $callback ): void {
		$lock_key = self::LOG_LOCK_TRANSIENT;
		$wait     = 0;
		while ( $wait < 25 && false !== get_transient( $lock_key ) ) {
			usleep( 40000 );
			++$wait;
		}
		set_transient( $lock_key, 1, 15 );

		try {
			$log    = $this->get_log();
			$result = $callback( $log );
			if ( null === $result ) {
				delete_option( self::LOG_OPTION );
			} else {
				update_option( self::LOG_OPTION, array_values( $result ), false );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Prefix CSV cells that Excel may treat as formulas.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function csv_safe_cell( $value ): string {
		$s = (string) $value;
		if ( '' !== $s && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $s;
		}
		return $s;
	}

	/**
	 * Normalize SQL for exact duplicate detection (align with Query Monitor).
	 *
	 * Collapses whitespace, strips trailing SQL block comments, and removes
	 * wpdb placeholder-escape tokens so identical statements compare equal.
	 *
	 * @param string $sql Raw SQL from $wpdb->queries.
	 * @return string
	 */
	private function normalize_sql_dupe_key( string $sql ): string {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
			$sql = (string) $wpdb->remove_placeholder_escape( $sql );
		}
		$stripped = preg_replace( '#/\*.*?\*/\s*$#s', '', $sql );
		if ( is_string( $stripped ) ) {
			$sql = $stripped;
		}
		$normalized = preg_replace( '/\s+/', ' ', trim( $sql ) );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Transient key for the current admin's last duplicate-query snapshot.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function last_dupes_transient_key( int $user_id ): string {
		return 'tsosk_sq_lastdupes_' . max( 0, $user_id );
	}

	/**
	 * Build a duplicate map from $wpdb->queries (exact SQL keys → counts).
	 *
	 * @param array<int, mixed> $queries           Query rows.
	 * @param bool              $exclude_admin_bar Skip queries triggered while rendering the admin bar (Query Monitor does the same).
	 * @return array{map: array<string, int>, callers: array<string, string>, query_count: int}
	 */
	private function build_exact_dupe_map( array $queries, bool $exclude_admin_bar = true ): array {
		$map         = array();
		$callers     = array();
		$query_count = 0;
		foreach ( $queries as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$stack = (string) ( $q[2] ?? '' );
			if ( $exclude_admin_bar && $this->caller_is_admin_bar( $stack ) ) {
				continue;
			}
			$sql = $this->normalize_sql_dupe_key( (string) ( $q[0] ?? '' ) );
			if ( '' === $sql ) {
				continue;
			}
			++$query_count;
			$map[ $sql ] = ( $map[ $sql ] ?? 0 ) + 1;
			if ( ! isset( $callers[ $sql ] ) ) {
				$callers[ $sql ] = $stack;
			}
		}
		return array(
			'map'         => $map,
			'callers'     => $callers,
			'query_count' => $query_count,
		);
	}

	/**
	 * Whether a SAVEQUERIES caller stack is from admin-bar rendering.
	 *
	 * @param string $stack Comma-separated caller list from $wpdb->queries[][2].
	 * @return bool
	 */
	private function caller_is_admin_bar( string $stack ): bool {
		if ( '' === $stack ) {
			return false;
		}
		return false !== stripos( $stack, 'wp_admin_bar' )
			|| false !== stripos( $stack, 'WP_Admin_Bar' )
			|| false !== stripos( $stack, 'admin_bar_menu' );
	}

	// ── Origin detection (opt-in) ────────────────────────────────────────────

	/**
	 * 'query' filter callback: record a lightweight backtrace for each query
	 * so capture_slow_queries() can later resolve which plugin, theme, or
	 * WordPress core file it came from. Only registered while the "capture
	 * origin" setting is on, since a backtrace per query has real overhead.
	 *
	 * @param string $query SQL, returned unmodified.
	 * @return string
	 */
	public function capture_query_backtrace( $query ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- opt-in diagnostic capture (admin-gated setting), not left on by default.
		$this->query_backtraces[] = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );
		return $query;
	}

	/**
	 * Resolve which plugin, theme, or WordPress core area a captured
	 * backtrace originated from.
	 *
	 * @param array<int,array<string,mixed>> $frames debug_backtrace() frames.
	 * @return string 'plugin:<slug>' | 'theme:<slug>' | 'core' | 'unknown'
	 */
	private function resolve_query_origin( array $frames ): string {
		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$core_dir   = wp_normalize_path( ABSPATH );

		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			if ( 0 === strpos( $file, $plugin_dir ) ) {
				$slug = strtok( substr( $file, strlen( $plugin_dir ) ), '/' );
				return 'plugin:' . ( is_string( $slug ) && '' !== $slug ? $slug : 'unknown' );
			}
			if ( 0 === strpos( $file, $theme_root ) ) {
				$slug = strtok( substr( $file, strlen( $theme_root ) ), '/' );
				return 'theme:' . ( is_string( $slug ) && '' !== $slug ? $slug : 'unknown' );
			}
			if ( 0 === strpos( $file, $core_dir ) ) {
				return 'core';
			}
		}

		return 'unknown';
	}

	/**
	 * Human-readable label for a resolved query origin.
	 *
	 * @param string $origin Value from resolve_query_origin().
	 * @return string
	 */
	private function format_origin_label( string $origin ): string {
		if ( 0 === strpos( $origin, 'plugin:' ) ) {
			return sprintf(
				/* translators: %s: plugin folder slug */
				__( 'Plugin: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				substr( $origin, 7 )
			);
		}
		if ( 0 === strpos( $origin, 'theme:' ) ) {
			return sprintf(
				/* translators: %s: theme folder slug */
				__( 'Theme: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				substr( $origin, 6 )
			);
		}
		if ( 'core' === $origin ) {
			return __( 'WordPress core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'Unknown origin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	// ── N+1 detection ─────────────────────────────────────────────────────────

	/**
	 * Detect repeated identical-fingerprint queries within one request batch —
	 * a common N+1 symptom (the same query pattern fired many times in a row).
	 *
	 * @param array<int,array<string,mixed>> $queries Batch queries.
	 * @return array<int,array{fingerprint:string,sql:string,count:int,total_ms:float}>
	 */
	private function detect_nplus1( array $queries ): array {
		$groups = array();
		foreach ( $queries as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$fp = isset( $q['fingerprint'] ) && is_string( $q['fingerprint'] ) && '' !== $q['fingerprint']
				? $q['fingerprint']
				: (string) ( $q['sql'] ?? '' );
			if ( '' === $fp ) {
				continue;
			}
			$key  = md5( $fp );
			$prev = $groups[ $key ] ?? array(
				'fingerprint' => $fp,
				'sql'         => (string) ( $q['sql'] ?? '' ),
				'count'       => 0,
				'total_ms'    => 0.0,
			);
			++$prev['count'];
			$prev['total_ms'] += (float) ( $q['time'] ?? 0 );
			$groups[ $key ]    = $prev;
		}

		$suspects = array_values(
			array_filter(
				$groups,
				static function ( array $g ): bool {
					return $g['count'] >= 3;
				}
			)
		);

		usort(
			$suspects,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

		return $suspects;
	}

	// ── Index suggestions ────────────────────────────────────────────────────

	/**
	 * Best-effort "consider an index" hint for a slow query, based on the
	 * first table plus the column used in its WHERE/ORDER BY clause. Read-only
	 * (SHOW TABLES / SHOW INDEX), results cached briefly per table. Returns
	 * null when the query shape isn't recognised or a matching index exists.
	 *
	 * @param string $sql Stored SQL (identifiers intact; literal values may be redacted).
	 * @return string|null
	 */
	private function suggest_index_for_sql( string $sql ): ?string {
		if ( ! preg_match( '/\bFROM\s+`?(\w+)`?/i', $sql, $tm ) ) {
			return null;
		}
		$table = $tm[1];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return null;
		}

		$column = '';
		if ( preg_match( '/\bWHERE\s+`?(\w+)`?\.?`?(\w*)`?\s*[=<>]/i', $sql, $wm ) ) {
			$column = '' !== $wm[2] ? $wm[2] : $wm[1];
		} elseif ( preg_match( '/\bORDER\s+BY\s+`?(\w+)`?\.?`?(\w*)`?/i', $sql, $om ) ) {
			$column = '' !== $om[2] ? $om[2] : $om[1];
		}
		if ( '' === $column || ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
			return null;
		}
		// Reject purely numeric matches: WordPress core generates boilerplate like
		// "WHERE 1=1 AND (...)" (WP_Query/WP_Meta_Query/WP_Tax_Query) and "ORDER BY 1";
		// a column name is never just digits, so this is never a real match.
		if ( preg_match( '/^\d+$/', $column ) ) {
			return null;
		}

		global $wpdb;
		$cache_key = 'tsosk_sq_idx_' . md5( $table );
		$indexed   = get_transient( $cache_key );
		if ( ! is_array( $indexed ) ) {
			$indexed = array();
			$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only existence check, prepared.
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name validated as [A-Za-z0-9_]+ above; read-only SHOW INDEX.
				$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( isset( $row['Column_name'] ) ) {
							$indexed[ strtolower( (string) $row['Column_name'] ) ] = true;
						}
					}
				}
			}
			set_transient( $cache_key, $indexed, HOUR_IN_SECONDS );
		}

		if ( isset( $indexed[ strtolower( $column ) ] ) ) {
			return null;
		}

		return sprintf(
			/* translators: 1: column name, 2: table name */
			__( 'Consider an index on %1$s (table %2$s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'`' . $column . '`',
			'`' . $table . '`'
		);
	}

	/**
	 * Persist a duplicate-query snapshot for the Slow Query Monitor tab.
	 *
	 * @param array<int, mixed> $queries Raw $wpdb->queries.
	 * @return bool True when a snapshot with at least one duplicate was stored.
	 */
	private function store_duplicate_snapshot_from_queries( array $queries ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$built = $this->build_exact_dupe_map( $queries, true );
		$dupes = array_filter( $built['map'], static fn( $n ) => (int) $n > 1 );
		if ( empty( $dupes ) ) {
			return false;
		}

		arsort( $dupes, SORT_NUMERIC );
		$list = array();
		foreach ( $dupes as $sql => $count ) {
			$list[] = array(
				'sql'    => mb_substr( (string) $sql, 0, 2000 ),
				'count'  => (int) $count,
				'caller' => mb_substr( (string) ( $built['callers'][ $sql ] ?? '' ), 0, 500 ),
			);
			if ( count( $list ) >= 25 ) {
				break;
			}
		}

		$url = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$url = TSOSK_Support::get_request_uri_for_display();
		}

		set_transient(
			$this->last_dupes_transient_key( (int) get_current_user_id() ),
			array(
				'ts'          => time(),
				'url'         => $url,
				'query_count' => (int) $built['query_count'],
				'dupes'       => $list,
			),
			12 * HOUR_IN_SECONDS
		);
		return true;
	}

	/**
	 * Whether the current request is the Slow Query Monitor admin tab.
	 *
	 * @return bool
	 */
	private function is_viewing_slow_queries_tab(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'tso-swiss-knife' !== $page ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab detection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return 'slow-queries' === $tab;
	}

	/**
	 * On shutdown: store exact duplicate queries for the next Slow Query Monitor view.
	 *
	 * The live table only lists queries from the Monitor page itself. Opening it from
	 * the admin bar is a new request — without this snapshot the previous page's
	 * duplicates would appear to "disappear".
	 */
	public function capture_duplicate_snapshot(): void {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || ! is_array( $wpdb->queries ) ) {
			return;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		// Do not replace the previous page's snapshot while browsing this tab.
		if ( $this->is_viewing_slow_queries_tab() ) {
			return;
		}

		$this->store_duplicate_snapshot_from_queries( $wpdb->queries );
	}

	/**
	 * Read the last duplicate snapshot for the current admin (if any).
	 *
	 * @return array{ts:int,url:string,query_count:int,dupes:array<int,array{sql:string,count:int,caller:string}>}|null
	 */
	private function get_last_dupes_snapshot(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$raw = get_transient( $this->last_dupes_transient_key( (int) get_current_user_id() ) );
		if ( ! is_array( $raw ) || empty( $raw['dupes'] ) || ! is_array( $raw['dupes'] ) ) {
			return null;
		}
		$dupes = array();
		foreach ( $raw['dupes'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sql = isset( $row['sql'] ) ? (string) $row['sql'] : '';
			if ( '' === $sql ) {
				continue;
			}
			$dupes[] = array(
				'sql'    => $sql,
				'count'  => max( 2, absint( $row['count'] ?? 2 ) ),
				'caller' => isset( $row['caller'] ) ? (string) $row['caller'] : '',
			);
		}
		if ( empty( $dupes ) ) {
			return null;
		}
		return array(
			'ts'          => absint( $raw['ts'] ?? 0 ),
			'url'         => isset( $raw['url'] ) ? (string) $raw['url'] : '',
			'query_count' => absint( $raw['query_count'] ?? 0 ),
			'dupes'       => $dupes,
		);
	}

	/**
	 * Render the saved duplicate-query panel (from a previous request).
	 */
	private function render_last_dupes_panel(): void {
		$last_dupes = $this->get_last_dupes_snapshot();
		if ( ! $last_dupes ) {
			return;
		}
		?>
		<div class="tsosk-card" id="tsosk-sq-last-dupes">
			<h3>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'Duplicates from your last page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Exact duplicate SQL from the previous request (same list the admin bar warned about). Saved when that page finished loading. The live table further down only shows queries for this Slow Query Monitor page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p>
				<?php
				$when = $last_dupes['ts']
					? human_time_diff( $last_dupes['ts'], time() ) . ' ' . __( 'ago', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: '—';
				printf(
					/* translators: 1: query count, 2: duplicate pattern count, 3: URL path, 4: relative time */
					esc_html__( '%1$d queries · %2$d duplicate patterns · %3$s · %4$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $last_dupes['query_count'],
					(int) count( $last_dupes['dupes'] ),
					esc_html( $last_dupes['url'] ? $last_dupes['url'] : '—' ),
					esc_html( $when )
				);
				?>
			</p>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead><tr>
					<th style="width:70px;"><?php esc_html_e( 'Times', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Called by', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_dupes['dupes'] as $row ) : ?>
					<tr>
						<td class="tsosk-sq-cell-mono">
							<span class="tsosk-badge tsosk-badge-warn">
								<?php
								printf(
									/* translators: %d: number of times this query ran */
									esc_html__( '×%d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									(int) $row['count']
								);
								?>
							</span>
						</td>
						<td class="tsosk-sq-cell-sql">
							<code class="tsosk-sq-sql-code"><?php echo esc_html( $row['sql'] ); ?></code>
						</td>
						<td class="tsosk-sq-cell-caller">
							<?php
							$frames = array_filter(
								array_map( 'trim', explode( ',', (string) $row['caller'] ) ),
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
		</div>
		<?php
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
	 * @return array{total_slow:int,total_batches:int,slowest_ms:float,slowest_sql:string,top_callers:array,top_sqls:array,top_origins:array}
	 */
	private function compute_stats( array $log ): array {
		$total_slow    = 0;
		$slowest_ms    = 0.0;
		$slowest_sql   = '';
		$caller_counts = array();
		$sql_counts    = array();
		$origin_counts = array();
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

				$origin = (string) ( $q['origin'] ?? '' );
				if ( '' !== $origin ) {
					$oprev = $origin_counts[ $origin ] ?? array(
						'count'    => 0,
						'total_ms' => 0.0,
					);
					$oprev['count']++;
					$oprev['total_ms']       += $t;
					$origin_counts[ $origin ] = $oprev;
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
		uasort(
			$origin_counts,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

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
			'top_origins'   => array_slice( $origin_counts, 0, 8, true ),
		);
	}

	/**
	 * Build a "label … value" row for the admin-bar submenu.
	 *
	 * @param string $label Row label (plain text).
	 * @param string $value Row value (plain text).
	 * @param string $tone  Optional tone: '', 'warn' or 'bad'.
	 * @param string $code  Optional SQL/code line shown under the row (plain text).
	 * @return string HTML (already escaped).
	 */
	private function admin_bar_row( string $label, string $value, string $tone = '', string $code = '' ): string {
		$value_class = 'tsosk-sq-v' . ( '' !== $tone ? ' tsosk-sq-v--' . sanitize_html_class( $tone ) : '' );
		$html        = '<span class="tsosk-sq-kvline"><span class="tsosk-sq-k">' . esc_html( $label ) . '</span>'
			. '<span class="' . esc_attr( $value_class ) . '">' . esc_html( $value ) . '</span></span>';
		if ( '' !== $code ) {
			$html .= '<span class="tsosk-sq-code">' . esc_html( $code ) . '</span>';
		}
		return $html;
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

		$settings    = $this->get_settings();
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		if ( ! $savequeries && ! $settings['enabled'] ) {
			return;
		}

		$live = $this->get_current_request_query_stats();
		if (
			$live
			&& (int) $live['dupe_patterns'] > 0
			&& $savequeries
			&& ! $this->is_viewing_slow_queries_tab()
			&& is_array( $GLOBALS['wpdb']->queries ?? null )
		) {
			// Persist early so the Monitor tab can show these SQL statements after navigation.
			$this->store_duplicate_snapshot_from_queries( $GLOBALS['wpdb']->queries );
		}
		$log       = $this->get_log();
		$log_stats = array() !== $log ? $this->compute_stats( $log ) : array(
			'total_slow'    => 0,
			'total_batches' => 0,
			'slowest_ms'    => 0.0,
			'slowest_sql'   => '',
			'top_callers'   => array(),
			'top_sqls'      => array(),
			'top_origins'   => array(),
		);
		$tab_url   = admin_url( 'tools.php?page=tso-swiss-knife&tab=slow-queries' );
		$debug_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=debug' );

		$load_s  = $live ? round( $live['load_ms'] / 1000, 2 ) : 0;
		$q_count = $live ? (int) $live['query_count'] : 0;
		$d_count = $live ? (int) $live['dupe_patterns'] : 0;
		$s_count = $live ? (int) $live['slow_count'] : 0;

		$severity     = 'ok';
		$root_classes = array( 'tsosk-sq-admin-bar-root' );
		if ( $d_count > 0 && $s_count > 0 ) {
			$severity       = 'alert';
			$root_classes[] = 'tsosk-sq-ab-alert';
		} elseif ( $d_count > 0 || $s_count > 0 ) {
			$severity       = 'warn';
			$root_classes[] = 'tsosk-sq-ab-warn';
		}

		if ( $savequeries ) {
			$title = '<span class="tsosk-sq-dot tsosk-sq-dot--' . esc_attr( $severity ) . '" aria-hidden="true"></span>'
				. '<span class="tsosk-sq-load">'
				/* translators: %s: page load time in seconds */
				. esc_html( sprintf( __( '%s s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $load_s, 2 ) ) )
				. '</span>'
				. '<span class="tsosk-sq-pill">'
				/* translators: %d: number of database queries */
				. esc_html( sprintf( __( '%d Q', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $q_count ) )
				. '</span>';
			if ( $d_count > 0 ) {
				$title .= '<span class="tsosk-sq-pill tsosk-sq-pill--bad">'
					/* translators: %d: number of duplicated query patterns */
					. esc_html( sprintf( _n( '%d dup', '%d dups', $d_count, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $d_count ) )
					. '</span>';
			}
			if ( $s_count > 0 ) {
				$title .= '<span class="tsosk-sq-pill tsosk-sq-pill--warn">'
					/* translators: %d: number of slow queries */
					. esc_html( sprintf( __( '%d slow', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $s_count ) )
					. '</span>';
			}
			$tooltip = sprintf(
				/* translators: 1: page load seconds, 2: query count, 3: duplicate patterns, 4: slow queries */
				__( 'Slow Query Monitor — this request: %1$s s load, %2$d queries, %3$d duplicate patterns, %4$d slow queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( $load_s, 2 ),
				$q_count,
				$d_count,
				$s_count
			);
		} else {
			$title   = '<span class="tsosk-sq-dot tsosk-sq-dot--off" aria-hidden="true"></span>'
				. '<span class="tsosk-sq-load">' . esc_html__( 'Slow queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';
			$tooltip = __( 'Slow Query Monitor — SAVEQUERIES is off.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'tsosk-slow-queries',
				'title' => $title, // Built above from escaped parts.
				'href'  => $tab_url,
				'meta'  => array(
					'class' => implode( ' ', $root_classes ),
					'title' => $tooltip,
				),
			)
		);

		if ( $live ) {
			// Section: this request.
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-h-request',
					'title'  => esc_html__( 'This request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'meta'   => array( 'class' => 'tsosk-sq-ab-head' ),
				)
			);
			$rows = array(
				'tsosk-sq-ab-load'    => array( __( 'Page time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $live['load_ms'], 0 ) . ' ms', '' ),
				'tsosk-sq-ab-dbtime'  => array( __( 'Database time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $live['query_time_ms'], 0 ) . ' ms', '' ),
				'tsosk-sq-ab-memory'  => array( __( 'Peak memory', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $live['memory_mb'], 1 ) . ' MB', '' ),
				'tsosk-sq-ab-queries' => array( __( 'Queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $q_count ), '' ),
				'tsosk-sq-ab-slow'    => array(
					/* translators: %d: slow threshold in milliseconds */
					sprintf( __( 'Slow (≥ %d ms)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $settings['threshold_ms'] ),
					number_format_i18n( $s_count ),
					$s_count > 0 ? 'warn' : '',
				),
				'tsosk-sq-ab-dupcnt'  => array( __( 'Duplicate patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $d_count ), $d_count > 0 ? 'bad' : '' ),
			);
			foreach ( $rows as $row_id => $row ) {
				$node = array(
					'parent' => 'tsosk-slow-queries',
					'id'     => $row_id,
					'title'  => $this->admin_bar_row( $row[0], $row[1], $row[2] ), // Escaped inside admin_bar_row().
					'meta'   => array( 'class' => 'tsosk-sq-ab-kv' ),
				);
				if ( 'tsosk-sq-ab-queries' === $row_id || 'tsosk-sq-ab-dupcnt' === $row_id ) {
					$node['href'] = $tab_url . '#tsosk-sq-last-dupes';
				} elseif ( 'tsosk-sq-ab-slow' === $row_id ) {
					$node['href'] = $tab_url . '#tsosk-sq-live-viewer';
				}
				$wp_admin_bar->add_node( $node );
			}

			// Section: worst queries.
			$has_dupe    = $d_count > 0 && '' !== $live['top_dupe_sql'];
			$has_slowest = $live['slowest_ms'] > 0;
			if ( $has_dupe || $has_slowest ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-h-worst',
						'title'  => esc_html__( 'Worst queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'meta'   => array( 'class' => 'tsosk-sq-ab-head' ),
					)
				);
			}
			if ( $has_dupe ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-dupes',
						'title'  => $this->admin_bar_row(
							/* translators: %d: times the same query ran */
							sprintf( __( 'Duplicated %d×', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $live['top_dupe_count'] ),
							__( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'bad',
							$this->admin_bar_excerpt( $this->fingerprint_sql( $live['top_dupe_sql'] ), 64 )
						),
						'href'   => $tab_url . '#tsosk-sq-last-dupes',
						'meta'   => array(
							'class' => 'tsosk-sq-ab-kv tsosk-sq-ab-item-warn',
							'title' => $live['top_dupe_sql'],
						),
					)
				);
			}
			if ( $has_slowest ) {
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-slowest',
						'title'  => $this->admin_bar_row(
							/* translators: %s: milliseconds */
							sprintf( __( 'Slowest · %s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $live['slowest_ms'], 0 ) ),
							__( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'warn',
							$this->admin_bar_excerpt( $this->fingerprint_sql( $live['slowest_sql'] ), 64 )
						),
						'href'   => $tab_url . '#tsosk-sq-live-viewer',
						'meta'   => array(
							'class' => 'tsosk-sq-ab-kv',
							'title' => $live['slowest_sql'],
						),
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

		// Section: persistent log.
		if ( $log_stats['total_slow'] > 0 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-h-log',
					'title'  => esc_html__( 'Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'meta'   => array( 'class' => 'tsosk-sq-ab-head' ),
				)
			);
			$wp_admin_bar->add_node(
				array(
					'parent' => 'tsosk-slow-queries',
					'id'     => 'tsosk-sq-ab-log',
					'title'  => $this->admin_bar_row(
						sprintf(
							/* translators: %d: logged slow queries */
							_n( '%d slow query logged', '%d slow queries logged', (int) $log_stats['total_slow'], 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							(int) $log_stats['total_slow']
						),
						sprintf(
							/* translators: %d: number of requests */
							_n( '%d request', '%d requests', (int) $log_stats['total_batches'], 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							(int) $log_stats['total_batches']
						)
					),
					'href'   => $tab_url . '#tsosk-sq-log',
					'meta'   => array( 'class' => 'tsosk-sq-ab-kv' ),
				)
			);

			if ( ! empty( $log_stats['top_sqls'][0] ) ) {
				$top = $log_stats['top_sqls'][0];
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-top',
						'title'  => $this->admin_bar_row(
							sprintf(
								/* translators: 1: hit count, 2: max ms */
								__( 'Top pattern · %1$d× · max %2$s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $top['count'],
								number_format_i18n( (float) $top['max'], 0 )
							),
							__( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'',
							$this->admin_bar_excerpt( (string) $top['fingerprint'], 64 )
						),
						'href'   => $tab_url . '#tsosk-sq-patterns',
						'meta'   => array(
							'class' => 'tsosk-sq-ab-kv',
							'title' => (string) $top['fingerprint'],
						),
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
				'meta'   => array( 'class' => 'tsosk-sq-ab-link' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'tsosk-slow-queries',
				'id'     => 'tsosk-sq-ab-debug',
				'title'  => esc_html__( 'Open Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'href'   => $debug_url,
				'meta'   => array( 'class' => 'tsosk-sq-ab-link' ),
			)
		);
	}

	/**
	 * Enqueue admin-bar styles (dot, pills, sectioned submenu, mobile).
	 */
	public function enqueue_admin_bar_styles(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings    = $this->get_settings();
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		if ( ! $savequeries && ! $settings['enabled'] ) {
			return;
		}
		$css = '#wpadminbar #wp-admin-bar-tsosk-slow-queries-default{min-width:300px;max-width:min(92vw,440px)}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries>.ab-item{display:flex!important;align-items:center;font-weight:600}'
			. '#wpadminbar .tsosk-sq-dot{display:inline-block;width:8px;height:8px;margin-right:6px;border-radius:50%;background:#00a32a}'
			. '#wpadminbar .tsosk-sq-dot--warn{background:#dba617}#wpadminbar .tsosk-sq-dot--alert{background:#d63638}#wpadminbar .tsosk-sq-dot--off{background:#8c8f94}'
			. '#wpadminbar .tsosk-sq-pill{display:inline-block;margin-left:6px;padding:0 6px;border-radius:9px;background:#3c434a;color:#fff;font-size:11px;line-height:16px;font-weight:600}'
			. '#wpadminbar .tsosk-sq-pill--bad{background:#d63638}#wpadminbar .tsosk-sq-pill--warn{background:#dba617;color:#1d2327}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-submenu .ab-item{'
			. 'white-space:normal!important;word-break:break-word;overflow-wrap:anywhere;height:auto!important;line-height:1.4;font-size:12px;padding-top:6px!important;padding-bottom:6px!important}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-submenu>li{height:auto}'
			. '#wpadminbar .tsosk-sq-ab-head>.ab-item{background:rgba(0,0,0,.18)!important;color:#a7aaad!important;font-size:10px!important;letter-spacing:.06em;text-transform:uppercase;cursor:default;padding-top:4px!important;padding-bottom:4px!important}'
			. '#wpadminbar .tsosk-sq-kvline{display:flex;justify-content:space-between;gap:16px}'
			. '#wpadminbar .tsosk-sq-v{font-weight:600}#wpadminbar .tsosk-sq-v--warn{color:#f0c33c}#wpadminbar .tsosk-sq-v--bad{color:#f86368}'
			. '#wpadminbar .tsosk-sq-code{display:block;margin-top:2px;font-family:Consolas,Monaco,monospace;font-size:11px;color:#a7aaad}'
			. '#wpadminbar .tsosk-sq-ab-link>.ab-item{color:#72aee6!important}'
			. '@media screen and (max-width:782px){#wpadminbar #wp-admin-bar-tsosk-slow-queries{display:block!important}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries>.ab-item{height:46px;padding:0 8px;font-size:12px}}';
		wp_register_style( 'tsosk-sq-admin-bar', false, array(), TSOSK_VERSION );
		wp_enqueue_style( 'tsosk-sq-admin-bar' );
		wp_add_inline_style( 'tsosk-sq-admin-bar', $css );
	}

	/**
	 * Collect query stats for the current request.
	 *
	 * @return array{load_ms:float,memory_mb:float,query_count:int,query_time_ms:float,slow_count:int,slowest_ms:float,slowest_sql:string,dupe_patterns:int,dupe_extra:int,top_dupe_count:int,top_dupe_sql:string}|null
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
		$built         = $this->build_exact_dupe_map( $wpdb->queries );
		$sql_map       = $built['map'];

		$query_count   = 0;

		foreach ( $wpdb->queries as $q ) {
			$time  = (float) ( $q[1] ?? 0 );
			$stack = (string) ( $q[2] ?? '' );
			// Same rule as the duplicate map and the slow-query log: ignore admin-bar rendering.
			if ( $this->caller_is_admin_bar( $stack ) ) {
				continue;
			}
			++$query_count;
			$query_time_ms += $time * 1000;
			$sql            = $this->normalize_sql_dupe_key( (string) ( $q[0] ?? '' ) );
			if ( $time >= $threshold_sec ) {
				++$slow_count;
				$t_ms = $time * 1000;
				if ( $t_ms > $slowest_ms ) {
					$slowest_ms  = $t_ms;
					$slowest_sql = $sql;
				}
			}
		}

		$dupes          = array_filter( $sql_map, static fn( $n ) => $n > 1 );
		$dupe_patterns  = count( $dupes );
		$dupe_extra     = $dupe_patterns > 0 ? ( array_sum( $dupes ) - $dupe_patterns ) : 0;
		$top_dupe_count = 0;
		$top_dupe_sql   = '';
		foreach ( $dupes as $sql => $n ) {
			if ( (int) $n > $top_dupe_count ) {
				$top_dupe_count = (int) $n;
				$top_dupe_sql   = (string) $sql;
			}
		}

		$load_ms = defined( 'WP_START_TIMESTAMP' ) ? ( microtime( true ) - WP_START_TIMESTAMP ) * 1000 : 0;

		return array(
			'load_ms'        => round( $load_ms, 1 ),
			'memory_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'query_count'    => $query_count,
			'query_time_ms'  => round( $query_time_ms, 1 ),
			'slow_count'     => $slow_count,
			'slowest_ms'     => round( $slowest_ms, 2 ),
			'slowest_sql'    => $slowest_sql,
			'dupe_patterns'  => $dupe_patterns,
			'dupe_extra'     => $dupe_extra,
			'top_dupe_count' => $top_dupe_count,
			'top_dupe_sql'   => $top_dupe_sql,
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
			'show_admin_bar'  => ! empty( $_POST['show_admin_bar'] ),
			'capture_origin'  => ! empty( $_POST['capture_origin'] ),
			'ignore_patterns' => $ignore_patterns,
		);

		update_option( self::SETTINGS_OPTION, $new, false );

		$warn_savequeries = false;
		$message          = __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		if ( $new['enabled'] ) {
			$savequeries_on = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
			if ( ! $savequeries_on ) {
				if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
					$message          = __( 'Settings saved. SAVEQUERIES is defined as false (usually in wp-config.php) and cannot be overridden — change or remove that define, then reload.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					$warn_savequeries = true;
				} elseif ( class_exists( 'TSOSK_Mod_Debug' ) ) {
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
		} elseif ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$message          = __( 'Logging disabled. SAVEQUERIES is still active (Debug Mode or wp-config) — disable it there if you want to stop collecting queries on every request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$warn_savequeries = true;
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
		if ( count( $patterns ) > 50 ) {
			wp_send_json_error( __( 'Ignore list is full (maximum 50 patterns). Remove one before adding another.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
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
						$this->csv_safe_cell( $ts ),
						$this->csv_safe_cell( $url ),
						$load,
						(float) ( $q['time'] ?? 0 ),
						$this->csv_safe_cell( $fp ),
						$this->csv_safe_cell( $sql ),
						$this->csv_safe_cell( (string) ( $q['caller'] ?? '' ) ),
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
		$this->mutate_log(
			static function (): ?array {
				return null;
			}
		);
		TSOSK_Activity_Log::log( 'slow-queries', 'delete', __( 'Slow query log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: delete one batch entry from the log. */
	public function ajax_delete_entry(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$deleted = false;
		$this->mutate_log(
			function ( array $log ) use ( $id, &$deleted ): array {
				$next = array();
				foreach ( $log as $batch ) {
					if ( isset( $batch['id'] ) && (string) $batch['id'] === $id ) {
						$deleted = true;
						continue;
					}
					$next[] = $batch;
				}
				return $next;
			}
		);

		if ( ! $deleted ) {
			wp_send_json_error( __( 'Entry not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		wp_send_json_success( __( 'Entry deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: get log page (for JS pagination). */
	public function ajax_get_log(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$page     = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) );
		$per_page = 20;
		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$log = array_reverse( $this->get_log() ); // newest first for display.

		// Filter by search.
		if ( $search ) {
			$lc  = strtolower( $search );
			$log = array_values(
				array_filter(
					$log,
					static function ( array $batch ) use ( $lc ): bool {
						if ( false !== strpos( strtolower( (string) ( $batch['url'] ?? '' ) ), $lc ) ) {
							return true;
						}
						foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
							if ( ! is_array( $q ) ) {
								continue;
							}
							if ( false !== strpos( strtolower( (string) ( $q['sql'] ?? '' ) ), $lc ) ) {
								return true;
							}
							if ( false !== strpos( strtolower( (string) ( $q['caller'] ?? '' ) ), $lc ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		$total = count( $log );
		// Clamp to the last page so an out-of-range page number cannot overflow the offset.
		$page  = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		$items = array_slice( $log, ( $page - 1 ) * $per_page, $per_page );

		$items_with_id = array();
		foreach ( $items as $batch ) {
			$items_with_id[] = array(
				'id'         => (string) ( $batch['id'] ?? '' ),
				'ts'         => (int) ( $batch['ts'] ?? 0 ),
				'url'        => (string) ( $batch['url'] ?? '' ),
				'load_ms'    => (float) ( $batch['load_ms'] ?? 0 ),
				'slow_count' => (int) ( $batch['slow_count'] ?? 0 ),
				'queries'    => (array) ( $batch['queries'] ?? array() ),
			);
		}

		wp_send_json_success(
			array(
				'items'       => $items_with_id,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
			)
		);
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

		<?php $this->render_last_dupes_panel(); ?>

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
							<?php esc_html_e( 'request batches (not individual queries). Oldest batches are removed when the limit is reached. Each batch stores up to 100 slow queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
					<th><?php esc_html_e( 'Admin bar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-show-admin-bar" value="1"
							       <?php checked( ! empty( $s['show_admin_bar'] ) ); ?>>
							<?php esc_html_e( 'Show Slow Query Monitor in the admin bar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<p class="description" style="margin-top:4px;">
							<?php esc_html_e( 'Displays request time, query count, and shortcuts in the WordPress toolbar (admin and front when the bar is visible).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Origin detection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-capture-origin" value="1"
							       <?php checked( ! empty( $s['capture_origin'] ) ); ?>>
							<?php esc_html_e( 'Record which plugin, theme, or WordPress core each slow query came from', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<p class="description" style="margin-top:4px;">
							<?php esc_html_e( 'Adds a small backtrace per query while this is on — leave it off unless you are actively investigating.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
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
						<th style="width:220px;"><?php esc_html_e( 'Index hint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
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
						<td style="font-size:11px;color:#6b7280;">
							<?php
							$idx_hint = $this->suggest_index_for_sql( (string) $entry['sql'] );
							echo $idx_hint ? esc_html( $idx_hint ) : '—';
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

		<?php /* ── By origin ── */ ?>
		<?php if ( ! empty( $stats['top_origins'] ) ) : ?>
		<div class="tsosk-card" id="tsosk-sq-origins">
			<h3><?php esc_html_e( 'Slow Queries by Origin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Which plugin, theme, or WordPress core the slow queries came from (only counted while origin detection is on).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'Origin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Count', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th style="width:15%;"><?php esc_html_e( 'Total time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $stats['top_origins'] as $origin_key => $o ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_origin_label( (string) $origin_key ) ); ?></td>
						<td><?php echo esc_html( (string) $o['count'] ); ?></td>
						<td><?php echo esc_html( number_format( (float) $o['total_ms'], 1 ) ); ?> ms</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
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
				<div id="tsosk-sq-pagination-top" class="tsosk-oe-pagination">
					<?php $this->render_log_pagination( 1, max( 1, (int) ceil( count( $log ) / 20 ) ) ); ?>
				</div>
				<div id="tsosk-sq-log-body">
					<?php $this->render_log_batches( $log, $nonce, $s['threshold_ms'] ); ?>
				</div>
				<div id="tsosk-sq-pagination-bot" class="tsosk-oe-pagination">
					<?php $this->render_log_pagination( 1, max( 1, (int) ceil( count( $log ) / 20 ) ) ); ?>
				</div>
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
		$threshold_ms = (int) $this->get_settings()['threshold_ms'];
		$built      = $this->build_exact_dupe_map( $sq_queries );
		$sq_sql_map = $built['map'];
		// Exact SQL duplicates (same idea as Query Monitor) — not fingerprints.
		$sq_dupes = array_filter( $sq_sql_map, static fn( $n ) => $n > 1 );
		$sq_dupe_rows = 0;
		foreach ( $sq_dupes as $n ) {
			$sq_dupe_rows += (int) $n;
		}
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
				<?php esc_html_e( 'Live list of database queries executed while loading this Slow Query Monitor page only. Duplicate detection ignores queries triggered by the admin bar (same approach as Query Monitor). For duplicates from another page, see the panel at the top of this tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
				<div class="tsosk-sq-stat">
					<span class="tsosk-sq-stat-val <?php echo ! empty( $sq_dupes ) ? 'tsosk-sq-warn' : ''; ?>">
						<?php echo esc_html( (string) count( $sq_dupes ) ); ?>
					</span>
					<span class="tsosk-sq-stat-lbl"><?php esc_html_e( 'Duplicate DB queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $sq_dupes ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ Duplicate queries detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php
				printf(
					/* translators: 1: distinct identical SQL count, 2: total executions of those queries */
					esc_html__( '%1$d identical SQL statements run more than once (%2$d executions in total). Same criterion as Query Monitor: exact SQL text, not fingerprints. Often caused by get_option()/get_post_meta() in a loop without caching.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) count( $sq_dupes ),
					(int) $sq_dupe_rows
				);
				?>
			</div>
			<?php endif; ?>

			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
				<input type="text" id="tsosk-sq-filter"
				       placeholder="<?php esc_attr_e( 'Filter queries…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				       style="min-width:220px;" autocomplete="off">
				<label class="tsosk-sq-filter-label">
					<input type="checkbox" id="tsosk-sq-dupes-only">
					<?php esc_html_e( 'Show duplicates only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<label class="tsosk-sq-filter-label">
					<input type="checkbox" id="tsosk-sq-slow-only" data-threshold="<?php echo esc_attr( (string) $threshold_ms ); ?>">
					<?php
					printf(
						/* translators: %d: threshold in milliseconds */
						esc_html__( 'Show slow only (≥ %d ms)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $threshold_ms
					);
					?>
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
					$sql_clean = $this->normalize_sql_dupe_key( $sql_raw );
					$is_slow   = $time_ms >= $threshold_ms;
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
					<td class="tsosk-sq-cell-muted"><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
					<td class="tsosk-sq-cell-mono">
						<span class="<?php echo esc_attr( $is_slow ? 'tsosk-sq-warn' : ( $time_ms > 2 ? 'tsosk-sq-amber' : 'tsosk-sq-ok' ) ); ?>">
							<?php echo esc_html( number_format( $time_ms, 3 ) ); ?> ms
						</span>
					</td>
					<td class="tsosk-sq-cell-sql">
						<?php if ( $is_dupe ) : ?>
						<span class="tsosk-badge tsosk-badge-warn tsosk-sq-dupe-badge">
							<?php
							printf(
								/* translators: %d: number of times this query ran */
								esc_html__( '×%d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $sq_sql_map[ $sql_clean ]
							);
							?>
						</span>
						<?php endif; ?>
						<span class="tsosk-badge tsosk-sq-kw-badge" style="background:<?php echo esc_attr( $kw_c ); ?>20;color:<?php echo esc_attr( $kw_c ); ?>;">
							<?php echo esc_html( $kw ); ?>
						</span>
						<code class="tsosk-sq-sql-code">
							<?php echo esc_html( mb_substr( $sql_clean, strlen( $kw ) + 1 ) ); ?>
						</code>
					</td>
					<td class="tsosk-sq-cell-caller">
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
		foreach ( $slice as $batch ) {
			$this->render_single_batch( $batch, $nonce, $threshold_ms );
		}
		echo '</div>';
	}

	/**
	 * Render log pagination controls.
	 *
	 * @param int $page         Current page (1-based).
	 * @param int $total_pages  Total pages.
	 */
	private function render_log_pagination( int $page, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}
		printf(
			'<span class="tsosk-sq-page-meta">%s</span> ',
			esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages */
					__( 'Page %1$d of %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$page,
					$total_pages
				)
			)
		);
		if ( $page > 1 ) {
			printf(
				'<button type="button" class="tsosk-oe-page-btn tsosk-sq-page-btn" data-page="%d">&larr;</button>',
				(int) ( $page - 1 )
			);
		}
		$start = max( 1, $page - 2 );
		$end   = min( $total_pages, $page + 2 );
		for ( $p = $start; $p <= $end; $p++ ) {
			printf(
				'<button type="button" class="tsosk-oe-page-btn tsosk-sq-page-btn%s" data-page="%d">%d</button>',
				$p === $page ? ' is-current' : '',
				(int) $p,
				(int) $p
			);
		}
		if ( $page < $total_pages ) {
			printf(
				'<button type="button" class="tsosk-oe-page-btn tsosk-sq-page-btn" data-page="%d">&rarr;</button>',
				(int) ( $page + 1 )
			);
		}
	}

	/**
	 * Render a single request batch.
	 *
	 * @param array  $batch         Batch data.
	 * @param string $nonce         WP nonce.
	 * @param int    $threshold_ms  Threshold.
	 */
	private function render_single_batch( array $batch, string $nonce, int $threshold_ms ): void {
		$batch_id   = (string) ( $batch['id'] ?? '' );
		$ts         = (int) ( $batch['ts'] ?? 0 );
		$url        = (string) ( $batch['url'] ?? '' );
		$load       = (float) ( $batch['load_ms'] ?? 0 );
		$slow_count = (int) ( $batch['slow_count'] ?? 0 );
		$queries    = (array) ( $batch['queries'] ?? array() );
		$times      = array_map(
			static function ( $q ) {
				return is_array( $q ) ? (float) ( $q['time'] ?? 0 ) : 0.0;
			},
			$queries
		);
		$max_time = $times ? max( $times ) : 0;
		$nplus1   = $this->detect_nplus1( $queries );
		?>
		<div class="tsosk-sq-batch" id="tsosk-sq-batch-<?php echo esc_attr( $batch_id ); ?>" data-id="<?php echo esc_attr( $batch_id ); ?>">
			<div class="tsosk-sq-batch-header" data-id="<?php echo esc_attr( $batch_id ); ?>">
				<span class="tsosk-badge tsosk-badge-<?php echo $slow_count > 5 ? 'warn' : 'info'; ?> tsosk-sq-batch-badge">
					<?php
					printf(
						/* translators: %d: number of slow queries */
						esc_html__( '%d slow', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $slow_count
					);
					?>
				</span>
				<span class="tsosk-sq-batch-url"><?php echo esc_html( $url ?: '—' ); ?></span>
				<span class="tsosk-sq-batch-meta">
					<?php echo esc_html( gmdate( 'Y-m-d H:i', $ts ) ); ?> UTC
				</span>
				<?php if ( $load > 0 ) : ?>
				<span class="tsosk-sq-batch-meta tsosk-sq-batch-meta-muted">
					<?php
					printf(
						/* translators: %s: page load time */
						esc_html__( 'Page: %s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format( $load, 1 ) )
					);
					?>
				</span>
				<?php endif; ?>
				<span class="tsosk-sq-batch-worst <?php echo esc_attr( $max_time > 500 ? 'tsosk-sq-warn' : ( $max_time > 200 ? 'tsosk-sq-amber' : '' ) ); ?>">
					<?php
					printf(
						/* translators: %s: milliseconds */
						esc_html__( 'Worst: %s ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format( $max_time, 2 ) )
					);
					?>
				</span>
				<button type="button" class="button button-small tsosk-sq-delete-batch tsosk-sq-delete-batch-btn"
				        data-id="<?php echo esc_attr( $batch_id ); ?>"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-sq-toggle-icon">▼</span>
			</div>
			<div class="tsosk-sq-batch-body">
				<?php if ( ! empty( $nplus1 ) ) : ?>
				<div class="tsosk-notice tsosk-notice-warn tsosk-sq-nplus1-notice">
					<?php foreach ( $nplus1 as $group ) : ?>
					<p style="margin:4px 0;">
						<?php
						printf(
							/* translators: 1: repeat count, 2: total time in ms, 3: SQL pattern excerpt */
							esc_html__( 'Possible N+1: this pattern repeated %1$d times (%2$s ms total) — %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							(int) $group['count'],
							esc_html( number_format( (float) $group['total_ms'], 1 ) ),
							esc_html( mb_substr( (string) $group['sql'], 0, 140 ) )
						);
						?>
					</p>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php
				foreach ( $queries as $qi => $q ) :
					if ( ! is_array( $q ) ) {
						continue;
					}
					$t   = (float) ( $q['time'] ?? 0 );
					$sql = (string) ( $q['sql'] ?? '' );
					$cal = (string) ( $q['caller'] ?? '' );
					$kw  = strtoupper( strtok( $sql, ' ' ) );
					$kw_colors = array(
						'SELECT' => '#2271b1',
						'INSERT' => '#16a34a',
						'UPDATE' => '#d97706',
						'DELETE' => '#d63638',
						'CREATE' => '#7c3aed',
						'DROP'   => '#d63638',
					);
					$kw_c = $kw_colors[ $kw ] ?? '#374151';
					?>
				<div class="tsosk-sq-query-row">
					<div class="tsosk-sq-query-meta">
						<span class="tsosk-sq-query-time <?php echo esc_attr( $t > 500 ? 'tsosk-sq-warn' : ( $t > 200 ? 'tsosk-sq-amber' : '' ) ); ?>">
							<?php echo esc_html( number_format( $t, 3 ) ); ?> ms
						</span>
						<span class="tsosk-badge tsosk-sq-kw-badge" style="background:<?php echo esc_attr( $kw_c ); ?>20;color:<?php echo esc_attr( $kw_c ); ?>;">
							<?php echo esc_html( $kw ); ?>
						</span>
						<span class="tsosk-sq-query-num">#<?php echo esc_html( (string) ( $qi + 1 ) ); ?></span>
						<?php if ( ! empty( $q['origin'] ) ) : ?>
						<span class="tsosk-badge tsosk-sq-origin-badge"><?php echo esc_html( $this->format_origin_label( (string) $q['origin'] ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tsosk-sq-query-sql"><?php echo esc_html( $sql ); ?></div>
					<?php if ( $cal ) : ?>
					<div class="tsosk-sq-query-caller">
						<span class="tsosk-sq-caller-arrow">↳</span> <?php echo esc_html( $cal ); ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
