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

	/** Option name (wp_options key) for the slow-query log. */
	private const LOG_OPTION = 'tsosk_slow_query_log';

	/** Option name (wp_options key) for module settings. */
	private const SETTINGS_OPTION = 'tsosk_slow_query_settings';

	/** Maximum entries kept in the persistent log. */
	private const MAX_ENTRIES = 500;

	/** Default slow-query threshold in milliseconds. */
	private const DEFAULT_THRESHOLD_MS = 100;

	/** Maximum slow queries stored per request batch (bounds option size). */
	private const MAX_QUERIES_PER_BATCH = 100;

	/** Transient key for log write lock. */
	private const LOG_LOCK_TRANSIENT = 'tsosk_sq_log_lock';

	/** User meta: Slow Query Monitor settings panel has been seen (collapse after first visit when enabled). */
	private const SETTINGS_SEEN_META = 'tsosk_sq_settings_seen';

	/** Collapse SQL / fingerprints longer than this (characters, after whitespace normalize). */
	private const SQL_FOLD_CHARS = 90;

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Slow_Queries|null
	 */
	private static $instance = null;

	/**
	 * Per-query raw backtraces captured this request (only populated when
	 * the "capture origin" setting is on), keyed by $wpdb->queries index.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private $query_backtraces = array();

	/**
	 * Get instance.
	 *
	 * @return TSOSK_Mod_Slow_Queries
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
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
		add_action( 'wp_ajax_tsosk_sq_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_sq_delete_entry', array( $this, 'ajax_delete_entry' ) );
		add_action( 'wp_ajax_tsosk_sq_get_log', array( $this, 'ajax_get_log' ) );
		add_action( 'wp_ajax_tsosk_sq_ignore_pattern', array( $this, 'ajax_ignore_pattern' ) );
		add_action( 'admin_post_tsosk_sq_export', array( $this, 'handle_export' ) );

		$settings = $this->get_settings();
		if ( ! empty( $settings['show_admin_bar'] ) ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 999 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
			// The bar is drawn early (top of wp-admin), so refresh its numbers once the page is almost done.
			add_action( 'wp_footer', array( $this, 'enqueue_admin_bar_refresh' ), 15 );
			add_action( 'admin_footer', array( $this, 'enqueue_admin_bar_refresh' ), 15 );
		}
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	/**
	 * Return settings with safe defaults.
	 *
	 * @return array{enabled:bool,threshold_ms:int,max_entries:int,exclude_ajax:bool,exclude_cron:bool,show_admin_bar:bool,capture_origin:bool,copy_plugins:array<int,string>,ignore_patterns:array<int,string>}
	 */
	private function get_settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$patterns = $s['ignore_patterns'] ?? array();
		if ( is_string( $patterns ) ) {
			$patterns = preg_split( '/\r\n|\r|\n/', $patterns );
			if ( false === $patterns ) {
				$patterns = array();
			}
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

		// Copy-to-AI plugin filter: never saved → folders starting with tso- (if any); else saved list (empty = all).
		if ( array_key_exists( 'copy_plugins', $s ) ) {
			$copy_plugins = $this->sanitize_copy_plugins( $s['copy_plugins'] );
		} else {
			$copy_plugins = $this->default_copy_plugin_slugs();
		}

		return array(
			'enabled'         => (bool) ( $s['enabled'] ?? false ),
			'threshold_ms'    => max( 1, min( 10000, (int) ( $s['threshold_ms'] ?? self::DEFAULT_THRESHOLD_MS ) ) ),
			'max_entries'     => max( 50, min( 2000, (int) ( $s['max_entries'] ?? self::MAX_ENTRIES ) ) ),
			'exclude_ajax'    => (bool) ( $s['exclude_ajax'] ?? false ),
			'exclude_cron'    => (bool) ( $s['exclude_cron'] ?? true ),
			'show_admin_bar'  => $show_admin_bar,
			'capture_origin'  => (bool) ( $s['capture_origin'] ?? false ),
			'copy_plugins'    => $copy_plugins,
			'ignore_patterns' => $patterns,
		);
	}

	/**
	 * Sanitize a list of plugin folder slugs for the Copy to AI filter.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<int, string>
	 */
	private function sanitize_copy_plugins( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && strlen( $slug ) <= 100 ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( array_slice( $out, 0, 80 ) ) );
	}

	/**
	 * Default Copy to AI filter: installed plugin folders that start with "tso-".
	 *
	 * @return array<int, string>
	 */
	private function default_copy_plugin_slugs(): array {
		$out = array();
		foreach ( array_keys( $this->get_plugins_for_copy_filter() ) as $slug ) {
			if ( str_starts_with( (string) $slug, 'tso-' ) ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/**
	 * Active + inactive plugins keyed by folder slug (for the Copy to AI checklist).
	 *
	 * @return array<string, string> slug => plugin name
	 */
	private function get_plugins_for_copy_filter(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( (string) $file );
			if ( '.' === $slug || '' === $slug ) {
				$slug = sanitize_key( (string) pathinfo( (string) $file, PATHINFO_FILENAME ) );
			} else {
				$slug = sanitize_key( $slug );
			}
			if ( '' === $slug ) {
				continue;
			}
			$name         = isset( $data['Name'] ) ? (string) $data['Name'] : $slug;
			$out[ $slug ] = $name;
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Whether a duplicate row touches any of the selected plugin folders.
	 *
	 * @param array{components?:array<string,int>} $row   Detail row.
	 * @param array<int, string>                   $slugs Selected plugin folder slugs.
	 * @return bool
	 */
	private function duplicate_row_matches_plugins( array $row, array $slugs ): bool {
		if ( array() === $slugs ) {
			return true;
		}
		$want = array_fill_keys( $slugs, true );
		foreach ( array_keys( (array) ( $row['components'] ?? array() ) ) as $origin ) {
			$origin = (string) $origin;
			if ( 0 === strpos( $origin, 'plugin:' ) ) {
				$slug = sanitize_key( substr( $origin, 7 ) );
				if ( '' !== $slug && isset( $want[ $slug ] ) ) {
					return true;
				}
			}
			if ( 0 === strpos( $origin, 'mu-plugin:' ) ) {
				$slug = sanitize_key( substr( $origin, 10 ) );
				if ( '' !== $slug && isset( $want[ $slug ] ) ) {
					return true;
				}
			}
		}
		return false;
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

	/**
	 * Whether the Monitor Settings &lt;details&gt; should start open.
	 * Open on first visit per user, or whenever the monitor has never been enabled.
	 *
	 * @param bool $enabled Current enabled flag from settings.
	 * @return bool
	 */
	private function should_open_settings_panel( bool $enabled ): bool {
		if ( ! $enabled ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return true;
		}
		$seen = (bool) get_user_meta( $user_id, self::SETTINGS_SEEN_META, true );
		if ( ! $seen ) {
			update_user_meta( $user_id, self::SETTINGS_SEEN_META, '1' );
			return true;
		}
		return false;
	}

	/**
	 * Mark Monitor Settings as seen for the current user (after a successful save with monitor on).
	 */
	private function mark_settings_seen(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::SETTINGS_SEEN_META, '1' );
		}
	}

	/**
	 * Echo SQL (or fingerprint) collapsed; expand/collapse via button (reliable inside tables).
	 *
	 * @param string $sql     SQL or fingerprint text.
	 * @param int    $preview Max characters shown before expand (0 = use default).
	 */
	private function render_collapsible_sql( string $sql, int $preview = 0 ): void {
		$sql = trim( $sql );
		if ( '' === $sql ) {
			echo '<code class="tsosk-sq-sql-code">—</code>';
			return;
		}
		// Collapse whitespace for list/table display (keeps full original for expand).
		$flat = preg_replace( '/\s+/u', ' ', $sql );
		if ( ! is_string( $flat ) || '' === $flat ) {
			$flat = $sql;
		}
		$limit = $preview > 0 ? $preview : self::SQL_FOLD_CHARS;
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $flat ) : strlen( $flat );
		if ( $len <= $limit ) {
			echo '<code class="tsosk-sq-sql-code">' . esc_html( $flat ) . '</code>';
			return;
		}
		$short = function_exists( 'mb_substr' ) ? mb_substr( $flat, 0, $limit ) : substr( $flat, 0, $limit );
		?>
		<div class="tsosk-sq-sql-fold">
			<code class="tsosk-sq-sql-code tsosk-sq-sql-preview"><?php echo esc_html( $short ); ?>…</code>
			<button type="button" class="button-link tsosk-sq-sql-fold-btn" aria-expanded="false">
				<span class="tsosk-sq-sql-fold-show"><?php esc_html_e( 'Show full SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<span class="tsosk-sq-sql-fold-hide" hidden><?php esc_html_e( 'Hide SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</button>
			<code class="tsosk-sq-sql-code tsosk-sq-sql-code--full" hidden><?php echo esc_html( $flat ); ?></code>
		</div>
		<?php
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
		$is_ajax = wp_doing_ajax();
		$is_cron = wp_doing_cron();

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
				'time'        => round( $time * 1000, 3 ), // ms.
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
			$fp        = isset( $q['fingerprint'] ) && is_string( $q['fingerprint'] ) && '' !== $q['fingerprint']
				? $q['fingerprint']
				: $this->fingerprint_sql( $sql );
			$queries[] = array(
				'sql'         => $sql,
				'fingerprint' => $fp,
				'time'        => (float) ( $q['time'] ?? 0 ),
				'caller'      => (string) ( $q['caller'] ?? '' ),
				'origin'      => isset( $q['origin'] ) && is_string( $q['origin'] ) ? $q['origin'] : '',
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
	 * @param callable $callback Receives current log; return array to save, null to delete option.
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
	 * @return array{
	 *   map: array<string, int>,
	 *   callers: array<string, string>,
	 *   origins: array<string, string>,
	 *   query_count: int,
	 *   details: array<string, array{count:int,time_ms:float,callers:array<string,int>,components:array<string,int>,troublemakers:array<string,int>}>
	 * }
	 */
	private function build_exact_dupe_map( array $queries, bool $exclude_admin_bar = true ): array {
		$map         = array();
		$callers     = array();
		$details     = array();
		$query_count = 0;

		foreach ( $queries as $qi => $q ) {
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
			if ( ! isset( $details[ $sql ] ) ) {
				$details[ $sql ] = array(
					'count'         => 0,
					'time_ms'       => 0.0,
					'callers'       => array(),
					'components'    => array(),
					'troublemakers' => array(),
				);
			}
			++$details[ $sql ]['count'];
			$details[ $sql ]['time_ms'] += (float) ( $q[1] ?? 0 ) * 1000;

			$caller = $this->primary_caller_from_stack( $stack );
			if ( '' !== $caller ) {
				$details[ $sql ]['callers'][ $caller ] = ( $details[ $sql ]['callers'][ $caller ] ?? 0 ) + 1;
			}

			$frames = $this->query_backtraces[ (int) $qi ] ?? array();
			$origin = is_array( $frames ) && array() !== $frames
				? $this->resolve_query_origin( $frames )
				: $this->origin_for_query_index( (int) $qi );
			if ( '' !== $origin ) {
				$details[ $sql ]['components'][ $origin ] = ( $details[ $sql ]['components'][ $origin ] ?? 0 ) + 1;
			}

			$trouble = is_array( $frames ) ? $this->troublemaker_from_frames( $frames ) : '';
			if ( '' !== $trouble ) {
				$details[ $sql ]['troublemakers'][ $trouble ] = ( $details[ $sql ]['troublemakers'][ $trouble ] ?? 0 ) + 1;
			}
		}

		$origins = array();
		foreach ( $details as $sql => $row ) {
			arsort( $row['callers'], SORT_NUMERIC );
			arsort( $row['components'], SORT_NUMERIC );
			arsort( $row['troublemakers'], SORT_NUMERIC );
			$details[ $sql ] = $row;
			if ( ! empty( $row['components'] ) ) {
				$origins[ (string) $sql ] = (string) array_key_first( $row['components'] );
			}
		}

		return array(
			'map'         => $map,
			'callers'     => $callers,
			'origins'     => $origins,
			'query_count' => $query_count,
			'details'     => $details,
		);
	}

	/**
	 * Primary callable from a SAVEQUERIES stack (Query Monitor "Callers" column).
	 *
	 * @param string $stack Comma-separated caller list.
	 * @return string
	 */
	private function primary_caller_from_stack( string $stack ): string {
		$skip   = array(
			'wpdb->query',
			'wpdb->get_results',
			'wpdb->get_var',
			'wpdb->get_row',
			'wpdb->get_col',
			'wpdb->prepare',
			'QM_DB->query',
			'require',
			'require_once',
			'include',
			'include_once',
		);
		$frames = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $stack ) ),
				static function ( string $f ) use ( $skip ): bool {
					if ( '' === $f ) {
						return false;
					}
					foreach ( $skip as $s ) {
						if ( 0 === stripos( $f, $s ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		if ( array() === $frames ) {
			return '';
		}
		// Nearest non-wpdb frame to the query (last in WP's SAVEQUERIES list).
		$pick = (string) end( $frames );
		return preg_match( '/\)$/', $pick ) ? $pick : $pick . '()';
	}

	/**
	 * Deeper plugin/theme method that likely triggered the query (QM "Potential Troublemakers").
	 *
	 * @param array<int,array<string,mixed>> $frames debug_backtrace() frames.
	 * @return string
	 */
	private function troublemaker_from_frames( array $frames ): string {
		$plugin_dir = trailingslashit( wp_normalize_path( tsosk_get_plugins_dir() ) );
		$mu_dir     = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) : '';
		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$pass       = array( 'query-monitor', TSOSK_UPLOADS_SLUG );
		$skip_fn    = array( 'apply_filters', 'do_action', 'call_user_func', 'call_user_func_array', 'ReflectionMethod' );

		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			$in_plugin = 0 === strpos( $file, $plugin_dir );
			$in_mu     = '' !== $mu_dir && 0 === strpos( $file, $mu_dir );
			$in_theme  = 0 === strpos( $file, $theme_root );
			if ( ! $in_plugin && ! $in_mu && ! $in_theme ) {
				continue;
			}
			if ( $in_plugin ) {
				$slug = strtok( substr( $file, strlen( $plugin_dir ) ), '/' );
				if ( is_string( $slug ) && in_array( $slug, $pass, true ) ) {
					continue;
				}
			}
			$class = isset( $frame['class'] ) ? (string) $frame['class'] : '';
			$type  = isset( $frame['type'] ) ? (string) $frame['type'] : '->';
			$fn    = isset( $frame['function'] ) ? (string) $frame['function'] : '';
			if ( '' === $fn || in_array( $fn, $skip_fn, true ) ) {
				continue;
			}
			if ( '' !== $class ) {
				return $class . $type . $fn . '()';
			}
			return $fn . '()';
		}
		return '';
	}

	/**
	 * JSON payload for selectable Copy-to-AI (per-row checkboxes in the UI).
	 *
	 * @param array<string, array{count:int,time_ms:float,callers?:array<string,int>,components?:array<string,int>,troublemakers?:array<string,int>}> $details Duplicate details keyed by SQL.
	 * @param array{url?:string,query_count?:int,detect?:bool}                                                                                        $meta    Report header fields.
	 * @param int                                                                                                                                     $max     Max patterns to include.
	 * @return array{meta:array{url:string,query_count:int,detect:bool},rows:array<int,array<string,mixed>>}
	 */
	private function build_dupe_report_payload( array $details, array $meta = array(), int $max = 15 ): array {
		$uasort_details = $details;
		uasort(
			$uasort_details,
			static function ( $a, $b ) {
				$c = ( (int) ( $b['count'] ?? 0 ) ) <=> ( (int) ( $a['count'] ?? 0 ) );
				return 0 !== $c ? $c : ( ( (float) ( $b['time_ms'] ?? 0 ) ) <=> ( (float) ( $a['time_ms'] ?? 0 ) ) );
			}
		);

		$rows = array();
		$i    = 0;
		foreach ( $uasort_details as $sql => $row ) {
			if ( (int) ( $row['count'] ?? 0 ) < 2 ) {
				continue;
			}
			++$i;
			if ( $i > $max ) {
				break;
			}
			$components = array();
			foreach ( (array) ( $row['components'] ?? array() ) as $ok => $n ) {
				$components[ (string) $ok ] = (int) $n;
			}
			$callers = array();
			foreach ( (array) ( $row['callers'] ?? array() ) as $name => $n ) {
				$callers[ (string) $name ] = (int) $n;
			}
			$troubles = array();
			foreach ( (array) ( $row['troublemakers'] ?? array() ) as $name => $n ) {
				$troubles[ (string) $name ] = (int) $n;
			}
			$rows[] = array(
				'id'            => 'd' . (string) ( $i - 1 ),
				'sql'           => $this->redact_sql_for_storage( (string) $sql ),
				'count'         => (int) $row['count'],
				'time_ms'       => (float) $row['time_ms'],
				'callers'       => $callers,
				'components'    => $components,
				'troublemakers' => $troubles,
			);
		}

		return array(
			'meta' => array(
				'url'         => (string) ( $meta['url'] ?? '' ),
				'query_count' => (int) ( $meta['query_count'] ?? 0 ),
				'detect'      => ! empty( $meta['detect'] ),
			),
			'rows' => $rows,
		);
	}

	/**
	 * Origin (plugin / theme / core) of one query of this request, or '' when unknown.
	 *
	 * Needs the "Origin detection" setting: without it no backtraces are recorded.
	 *
	 * @param int $index Index in $wpdb->queries.
	 * @return string Value from resolve_query_origin() or ''.
	 */
	private function origin_for_query_index( int $index ): string {
		if ( ! isset( $this->query_backtraces[ $index ] ) ) {
			return '';
		}
		return $this->resolve_query_origin( $this->query_backtraces[ $index ] );
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
	 * @return string 'plugin:<slug>' | 'mu-plugin:<slug>' | 'theme:<slug>' | 'core' | 'unknown'
	 */
	private function resolve_query_origin( array $frames ): string {
		$plugin_dir = trailingslashit( wp_normalize_path( tsosk_get_plugins_dir() ) );
		$mu_dir     = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) : '';
		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$core_dirs  = array(
			trailingslashit( wp_normalize_path( ABSPATH . WPINC ) ),
			trailingslashit( wp_normalize_path( ABSPATH . 'wp-admin' ) ),
		);
		// Plugins that only wrap or record queries never "cause" them: skip to the real caller.
		$pass_through = array( 'query-monitor', TSOSK_UPLOADS_SLUG );
		$saw_core     = false;

		// Frames run from the query outwards, so the first plugin/theme frame is the real caller.
		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			if ( 0 === strpos( $file, $plugin_dir ) ) {
				$slug = strtok( substr( $file, strlen( $plugin_dir ) ), '/' );
				$slug = is_string( $slug ) && '' !== $slug ? $slug : 'unknown';
				if ( in_array( $slug, $pass_through, true ) ) {
					continue;
				}
				return 'plugin:' . $slug;
			}
			if ( '' !== $mu_dir && 0 === strpos( $file, $mu_dir ) ) {
				$slug = strtok( substr( $file, strlen( $mu_dir ) ), '/' );
				return 'mu-plugin:' . ( is_string( $slug ) && '' !== $slug ? $slug : 'unknown' );
			}
			if ( 0 === strpos( $file, $theme_root ) ) {
				$slug = strtok( substr( $file, strlen( $theme_root ) ), '/' );
				return 'theme:' . ( is_string( $slug ) && '' !== $slug ? $slug : 'unknown' );
			}
			foreach ( $core_dirs as $core_dir ) {
				if ( 0 === strpos( $file, $core_dir ) ) {
					$saw_core = true;
					break;
				}
			}
		}

		return $saw_core ? 'core' : 'unknown';
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
		if ( 0 === strpos( $origin, 'mu-plugin:' ) ) {
			return sprintf(
				/* translators: %s: must-use plugin file or folder name */
				__( 'MU-plugin: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				substr( $origin, 10 )
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
	 * @return bool True when a snapshot was stored.
	 */
	private function store_duplicate_snapshot_from_queries( array $queries ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$built   = $this->build_exact_dupe_map( $queries, true );
		$details = $built['details'];
		uasort(
			$details,
			static function ( $a, $b ) {
				$c = ( (int) $b['count'] ) <=> ( (int) $a['count'] );
				return 0 !== $c ? $c : ( ( (float) $b['time_ms'] ) <=> ( (float) $a['time_ms'] ) );
			}
		);
		$list = array();
		foreach ( $details as $sql => $row ) {
			if ( (int) $row['count'] < 2 ) {
				continue;
			}
			$list[] = array(
				'sql'           => mb_substr( $this->redact_sql_for_storage( (string) $sql ), 0, 2000 ),
				'count'         => (int) $row['count'],
				'time_ms'       => round( (float) $row['time_ms'], 3 ),
				'caller'        => mb_substr( (string) ( $built['callers'][ $sql ] ?? '' ), 0, 500 ),
				'origin'        => (string) ( $built['origins'][ $sql ] ?? '' ),
				'callers'       => array_slice( $row['callers'], 0, 8, true ),
				'components'    => array_slice( $row['components'], 0, 8, true ),
				'troublemakers' => array_slice( $row['troublemakers'], 0, 8, true ),
			);
			if ( count( $list ) >= 25 ) {
				break;
			}
		}

		// Slowest queries of the same request (the bar's "Slow" and "Slowest" rows link here).
		$threshold_sec = $this->get_settings()['threshold_ms'] / 1000.0;
		$slow_all      = array();
		foreach ( $queries as $qi => $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$time   = (float) ( $q[1] ?? 0 );
			$caller = (string) ( $q[2] ?? '' );
			if ( $time < $threshold_sec || $this->caller_is_admin_bar( $caller ) ) {
				continue;
			}
			$slow_all[] = array(
				'sql'    => mb_substr( $this->redact_sql_for_storage( $this->normalize_sql_dupe_key( (string) ( $q[0] ?? '' ) ) ), 0, 2000 ),
				'ms'     => round( $time * 1000, 3 ),
				'caller' => mb_substr( $caller, 0, 500 ),
				'origin' => $this->origin_for_query_index( (int) $qi ),
			);
		}
		usort( $slow_all, static fn( $a, $b ) => $b['ms'] <=> $a['ms'] );
		$slow = array_slice( $slow_all, 0, 25 );

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
				'slow_total'  => count( $slow_all ),
				'dupes'       => $list,
				'slow'        => $slow,
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
		if ( wp_doing_ajax() || wp_doing_cron() ) {
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
	 * @return array{ts:int,url:string,query_count:int,slow_total:int,dupes:array<int,array{sql:string,count:int,time_ms:float,caller:string,origin:string,callers:array<string,int>,components:array<string,int>,troublemakers:array<string,int>}>,slow:array<int,array{sql:string,ms:float,caller:string,origin:string}>}|null
	 */
	private function get_last_dupes_snapshot(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$raw = get_transient( $this->last_dupes_transient_key( (int) get_current_user_id() ) );
		if ( ! is_array( $raw ) || ! isset( $raw['query_count'] ) ) {
			return null;
		}
		$dupes = array();
		foreach ( (array) ( $raw['dupes'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sql = isset( $row['sql'] ) ? (string) $row['sql'] : '';
			if ( '' === $sql ) {
				continue;
			}
			$dupes[] = array(
				'sql'           => $sql,
				'count'         => max( 2, absint( $row['count'] ?? 2 ) ),
				'time_ms'       => (float) ( $row['time_ms'] ?? 0 ),
				'caller'        => isset( $row['caller'] ) ? (string) $row['caller'] : '',
				'origin'        => isset( $row['origin'] ) ? (string) $row['origin'] : '',
				'callers'       => $this->sanitize_count_map( $row['callers'] ?? array() ),
				'components'    => $this->sanitize_count_map( $row['components'] ?? array() ),
				'troublemakers' => $this->sanitize_count_map( $row['troublemakers'] ?? array() ),
			);
		}
		$slow = array();
		foreach ( (array) ( $raw['slow'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sql = isset( $row['sql'] ) ? (string) $row['sql'] : '';
			if ( '' === $sql ) {
				continue;
			}
			$slow[] = array(
				'sql'    => $sql,
				'ms'     => (float) ( $row['ms'] ?? 0 ),
				'caller' => isset( $row['caller'] ) ? (string) $row['caller'] : '',
				'origin' => isset( $row['origin'] ) ? (string) $row['origin'] : '',
			);
		}
		return array(
			'ts'          => absint( $raw['ts'] ?? 0 ),
			'url'         => isset( $raw['url'] ) ? (string) $raw['url'] : '',
			'query_count' => absint( $raw['query_count'] ?? 0 ),
			'slow_total'  => absint( $raw['slow_total'] ?? count( $slow ) ),
			'dupes'       => $dupes,
			'slow'        => $slow,
		);
	}

	/**
	 * Sanitize a name→count map from a stored snapshot.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string, int>
	 */
	private function sanitize_count_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $name => $n ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$out[ $name ] = absint( $n );
		}
		arsort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Echo a compact list of "name ×n" lines (callers / components / troublemakers).
	 *
	 * @param array<string, int> $map        Name → count.
	 * @param bool               $as_origin  Format keys with format_origin_label().
	 */
	private function render_count_map( array $map, bool $as_origin = false ): void {
		if ( empty( $map ) ) {
			echo '—';
			return;
		}
		$bits = array();
		foreach ( array_slice( $map, 0, 6, true ) as $name => $n ) {
			$label  = $as_origin ? $this->format_origin_label( (string) $name ) : (string) $name;
			$bits[] = esc_html( $label ) . ' <span class="tsosk-sq-x">×' . esc_html( (string) (int) $n ) . '</span>';
		}
		echo wp_kses(
			implode( '<br>', $bits ),
			array(
				'br'   => array(),
				'span' => array( 'class' => true ),
			)
		);
	}

	/**
	 * Trim a SAVEQUERIES caller stack to the last three meaningful frames.
	 *
	 * @param string $caller Comma-separated caller list.
	 * @return string
	 */
	private function format_caller_frames( string $caller ): string {
		$frames = array_filter(
			array_map( 'trim', explode( ',', $caller ) ),
			static fn( $f ) => '' !== $f && ! in_array( $f, array( 'wpdb->query', 'wpdb->get_results', 'wpdb->get_var', 'wpdb->get_row', 'wpdb->prepare' ), true )
		);
		return implode( ' → ', array_slice( array_values( $frames ), -3 ) );
	}

	/**
	 * Echo an "origin" badge, or a hint when origin detection is off.
	 *
	 * @param string $origin Value from resolve_query_origin() or ''.
	 * @param bool   $detect Whether origin detection is enabled.
	 */
	private function render_origin_badge( string $origin, bool $detect ): void {
		if ( '' !== $origin ) {
			echo '<span class="tsosk-badge tsosk-sq-origin-badge">' . esc_html( $this->format_origin_label( $origin ) ) . '</span> ';
			return;
		}
		if ( ! $detect ) {
			return;
		}
		echo '<span class="tsosk-badge tsosk-sq-origin-badge">' . esc_html__( 'Unknown origin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span> ';
	}

	/**
	 * Render the saved duplicate-query panel (from a previous request).
	 */
	private function render_last_dupes_panel(): void {
		$last   = $this->get_last_dupes_snapshot();
		$s      = $this->get_settings();
		$detect = ! empty( $s['capture_origin'] );
		if ( ! $last ) {
			return;
		}
		$when = $last['ts']
			? human_time_diff( $last['ts'], time() ) . ' ' . __( 'ago', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: '—';

		// Only duplicates attributed to plugins selected in Monitor Settings → Copy to AI.
		$copy_plugins   = $s['copy_plugins'];
		$report_details = array();
		foreach ( (array) $last['dupes'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$detail = array(
				'count'         => (int) ( $row['count'] ?? 0 ),
				'time_ms'       => (float) ( $row['time_ms'] ?? 0 ),
				'callers'       => (array) ( $row['callers'] ?? array() ),
				'components'    => (array) ( $row['components'] ?? array() ),
				'troublemakers' => (array) ( $row['troublemakers'] ?? array() ),
			);
			if ( ! $this->duplicate_row_matches_plugins( $detail, $copy_plugins ) ) {
				continue;
			}
			$report_details[ (string) $row['sql'] ] = $detail;
		}
		$last_payload   = $this->build_dupe_report_payload(
			$report_details,
			array(
				'url'         => (string) $last['url'],
				'query_count' => (int) $last['query_count'],
				'detect'      => $detect,
			),
			40
		);
		$filtered_count = count( $last_payload['rows'] );
		$raw_dupe_count = count( (array) $last['dupes'] );
		?>
		<div class="tsosk-card" id="tsosk-sq-last-request">
			<h3>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'Duplicate queries from plugins selected in settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Exact duplicates from the last page you opened, limited to plugins chosen under Monitor Settings → Copy to AI (e.g. tso-*). All duplicates on this admin page appear in the live table below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p>
				<?php
				if ( array() !== $copy_plugins && $raw_dupe_count > $filtered_count ) {
					printf(
						/* translators: 1: query count, 2: duplicates matching selected plugins, 3: total duplicate patterns on that page, 4: slow query count, 5: URL path, 6: relative time */
						esc_html__( '%1$d queries on last page · Selected-plugin duplicates: %2$d (of %3$d on that page) · %4$d slow · %5$s · %6$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $last['query_count'],
						(int) $filtered_count,
						(int) $raw_dupe_count,
						(int) $last['slow_total'],
						esc_html( $last['url'] ? $last['url'] : '—' ),
						esc_html( $when )
					);
				} else {
					printf(
						/* translators: 1: query count, 2: selected-plugin duplicate count, 3: slow query count, 4: URL path, 5: relative time */
						esc_html__( '%1$d queries on last page · Selected-plugin duplicates: %2$d · %3$d slow · %4$s · %5$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $last['query_count'],
						(int) $filtered_count,
						(int) $last['slow_total'],
						esc_html( $last['url'] ? $last['url'] : '—' ),
						esc_html( $when )
					);
				}
				?>
			</p>
			<?php if ( ! $detect && ( ! empty( $last['dupes'] ) || ! empty( $last['slow'] ) ) ) : ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'To see which plugin or theme runs each query, turn on "Origin detection" in Monitor Settings below and visit the page again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php endif; ?>

			<div class="tsosk-sq-section" id="tsosk-sq-last-dupes">
				<div class="tsosk-sq-section-bar">
					<h4><?php esc_html_e( 'Duplicate queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<?php if ( $filtered_count > 0 ) : ?>
					<div class="tsosk-sq-section-actions">
						<button type="button" class="button tsosk-sq-copy-report"
								data-dupe-panel="last"
								data-msg="tsosk-sq-last-dupe-copy-msg">
							<?php esc_html_e( 'Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
						<span class="tsosk-ajax-msg" id="tsosk-sq-last-dupe-copy-msg" aria-live="polite"></span>
					</div>
					<script type="application/json" id="tsosk-sq-last-dupe-json"><?php echo wp_json_encode( $last_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
					<?php endif; ?>
				</div>
			<?php if ( 0 === $filtered_count ) : ?>
				<p class="description">
					<?php
					if ( array() === $copy_plugins ) {
						esc_html_e( 'No exact duplicate queries on that page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					} elseif ( ! $detect ) {
						esc_html_e( 'Turn on Origin detection in Monitor Settings, save, then visit another page — otherwise this panel cannot attribute duplicates to your selected plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					} elseif ( ! empty( $last['dupes'] ) ) {
						esc_html_e( 'No duplicates from the plugins selected in Monitor Settings → Copy to AI. Other plugins’ duplicates (if any) appear in the live table below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					} else {
						esc_html_e( 'No exact duplicate queries on that page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					}
					?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Tick the rows to include, then Copy to AI.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-sq-dupe-table" id="tsosk-sq-last-dupe-table">
					<thead><tr>
						<th class="tsosk-sq-col-pick" scope="col">
							<input type="checkbox" class="tsosk-sq-dupe-pick-all" checked
									title="<?php esc_attr_e( 'Select all for Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
									aria-label="<?php esc_attr_e( 'Select all for Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
						</th>
						<th class="tsosk-sq-col-times"><?php esc_html_e( 'Times', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-time"><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-sql"><?php esc_html_e( 'SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-side"><?php esc_html_e( 'Callers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-side"><?php esc_html_e( 'Components', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-side"><?php esc_html_e( 'Potential troublemakers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $last_payload['rows'] as $prow ) : ?>
						<tr class="tsosk-sq-dupe-row" data-dupe-id="<?php echo esc_attr( (string) $prow['id'] ); ?>">
							<td class="tsosk-sq-col-pick">
								<input type="checkbox" class="tsosk-sq-dupe-pick" value="<?php echo esc_attr( (string) $prow['id'] ); ?>" checked
										aria-label="<?php esc_attr_e( 'Include this duplicate in Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							</td>
							<td class="tsosk-sq-cell-mono">
								<span class="tsosk-badge tsosk-badge-warn">
									<?php
									printf(
										/* translators: %d: number of times this query ran */
										esc_html__( '×%d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										(int) $prow['count']
									);
									?>
								</span>
							</td>
							<td class="tsosk-sq-cell-mono">
								<?php echo esc_html( number_format_i18n( (float) $prow['time_ms'], 2 ) ); ?> ms
							</td>
							<td class="tsosk-sq-cell-sql">
								<?php $this->render_collapsible_sql( (string) $prow['sql'] ); ?>
							</td>
							<td class="tsosk-sq-cell-caller">
								<?php $this->render_count_map( (array) $prow['callers'] ); ?>
							</td>
							<td class="tsosk-sq-cell-caller">
								<?php $this->render_count_map( (array) $prow['components'], true ); ?>
							</td>
							<td class="tsosk-sq-cell-caller">
								<?php $this->render_count_map( (array) $prow['troublemakers'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
			</div>

			<h4 id="tsosk-sq-last-slow"><?php esc_html_e( 'Slowest queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
			<?php if ( empty( $last['slow'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: slow threshold in milliseconds */
					esc_html__( 'No query reached the slow threshold (%d ms) on that page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $this->get_settings()['threshold_ms']
				);
				?>
			</p>
			<?php else : ?>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead><tr>
					<th style="width:90px;"><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Called by', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last['slow'] as $row ) : ?>
					<tr>
						<td class="tsosk-sq-cell-mono">
							<span class="tsosk-sq-warn"><?php echo esc_html( number_format_i18n( (float) $row['ms'], 2 ) ); ?> ms</span>
						</td>
						<td class="tsosk-sq-cell-sql">
							<?php $this->render_collapsible_sql( (string) $row['sql'] ); ?>
						</td>
						<td class="tsosk-sq-cell-caller">
							<?php $this->render_origin_badge( (string) $row['origin'], $detect ); ?>
							<?php echo esc_html( $this->format_caller_frames( (string) $row['caller'] ) ); ?>
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
	 * @param string             $sql      Raw SQL.
	 * @param array<int, string> $patterns Ignore patterns.
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

				++$total_slow;
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
				++$prev['count'];
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
					++$oprev['count'];
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
	 * @param string $key   Optional key so the footer script can refresh the value.
	 * @return string HTML (already escaped).
	 */
	private function admin_bar_row( string $label, string $value, string $tone = '', string $code = '', string $key = '' ): string {
		$value_class = 'tsosk-sq-v' . ( '' !== $tone ? ' tsosk-sq-v--' . sanitize_html_class( $tone ) : '' );
		$key_attr    = '' !== $key ? ' data-tsosk-sq="' . esc_attr( $key ) . '"' : '';
		$html        = '<span class="tsosk-sq-kvline"><span class="tsosk-sq-k">' . esc_html( $label ) . '</span>'
			. '<span class="' . esc_attr( $value_class ) . '"' . $key_attr . '>' . esc_html( $value ) . '</span></span>';
		if ( '' !== $code ) {
			$html .= '<span class="tsosk-sq-code">' . esc_html( $code ) . '</span>';
		}
		return $html;
	}

	/**
	 * Translated texts for the toolbar item (used for the first render and for the footer refresh).
	 *
	 * @param array<string,mixed> $live Stats from get_current_request_query_stats().
	 * @return array{severity:string,tooltip:string,text:array<string,string>,tone:array<string,string>}
	 */
	private function admin_bar_texts( array $live ): array {
		$load_s  = round( (float) $live['load_ms'] / 1000, 2 );
		$q_count = (int) $live['query_count'];
		$d_count = (int) $live['dupe_patterns'];
		$s_count = (int) $live['slow_count'];

		$severity = 'ok';
		if ( $d_count > 0 && $s_count > 0 ) {
			$severity = 'alert';
		} elseif ( $d_count > 0 || $s_count > 0 ) {
			$severity = 'warn';
		}

		return array(
			'severity' => $severity,
			'tooltip'  => sprintf(
				/* translators: 1: page load seconds, 2: query count, 3: duplicate patterns, 4: slow queries */
				__( 'Slow Query Monitor — this request: %1$s s load, %2$d queries, %3$d duplicate patterns, %4$d slow queries.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( $load_s, 2 ),
				$q_count,
				$d_count,
				$s_count
			),
			'text'     => array(
				/* translators: %s: page load time in seconds */
				'load'         => sprintf( __( '%s s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), number_format_i18n( $load_s, 2 ) ),
				/* translators: %d: number of database queries */
				'queries_pill' => sprintf( __( '%d Q', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $q_count ),
				/* translators: %d: number of duplicated query patterns */
				'dups_pill'    => $d_count > 0 ? sprintf( _n( '%d dup', '%d dups', $d_count, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $d_count ) : '',
				/* translators: %d: number of slow queries */
				'slow_pill'    => $s_count > 0 ? sprintf( __( '%d slow', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $s_count ) : '',
				'page_ms'      => number_format_i18n( (float) $live['load_ms'], 0 ) . ' ms',
				'db_ms'        => number_format_i18n( (float) $live['query_time_ms'], 0 ) . ' ms',
				'memory'       => number_format_i18n( (float) $live['memory_mb'], 1 ) . ' MB',
				'queries'      => number_format_i18n( $q_count ),
				'slow'         => number_format_i18n( $s_count ),
				'dups'         => number_format_i18n( $d_count ),
			),
			'tone'     => array(
				'slow' => $s_count > 0 ? 'warn' : '',
				'dups' => $d_count > 0 ? 'bad' : '',
			),
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

		$settings    = $this->get_settings();
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		if ( ! $savequeries && ! $settings['enabled'] ) {
			return;
		}

		// The bundled catalog is normally loaded on the plugin screen only.
		TSOSK_I18n::get_instance()->load_translations_for_admin_bar();

		$live      = $this->get_current_request_query_stats();
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

		// On the Monitor tab the bar describes the live table; elsewhere it describes the saved "last page" panel.
		$on_tab       = $this->is_viewing_slow_queries_tab();
		$a_queries    = $on_tab ? '#tsosk-sq-live-viewer' : '#tsosk-sq-last-request';
		$a_dupes      = $on_tab ? '#tsosk-sq-live-viewer' : '#tsosk-sq-last-dupes';
		$a_slow       = $on_tab ? '#tsosk-sq-live-viewer' : '#tsosk-sq-last-slow';
		$zero_live    = array(
			'load_ms'       => 0,
			'memory_mb'     => 0,
			'query_count'   => 0,
			'query_time_ms' => 0,
			'slow_count'    => 0,
			'dupe_patterns' => 0,
		);
		$texts        = $this->admin_bar_texts( $live ? $live : $zero_live );
		$tx           = $texts['text'];
		$d_count      = $live ? (int) $live['dupe_patterns'] : 0;
		$s_count      = $live ? (int) $live['slow_count'] : 0;
		$root_classes = array( 'tsosk-sq-admin-bar-root' );
		if ( 'alert' === $texts['severity'] ) {
			$root_classes[] = 'tsosk-sq-ab-alert';
		} elseif ( 'warn' === $texts['severity'] ) {
			$root_classes[] = 'tsosk-sq-ab-warn';
		}

		if ( $savequeries ) {
			$title   = '<span class="tsosk-sq-dot tsosk-sq-dot--' . esc_attr( $texts['severity'] ) . '" aria-hidden="true"></span>'
				. '<span class="tsosk-sq-load" data-tsosk-sq="load">' . esc_html( $tx['load'] ) . '</span>'
				. '<span class="tsosk-sq-pill" data-tsosk-sq="queries_pill">' . esc_html( $tx['queries_pill'] ) . '</span>'
				. '<span class="tsosk-sq-pill tsosk-sq-pill--bad" data-tsosk-sq="dups_pill"' . ( '' === $tx['dups_pill'] ? ' hidden' : '' ) . '>' . esc_html( $tx['dups_pill'] ) . '</span>'
				. '<span class="tsosk-sq-pill tsosk-sq-pill--warn" data-tsosk-sq="slow_pill"' . ( '' === $tx['slow_pill'] ? ' hidden' : '' ) . '>' . esc_html( $tx['slow_pill'] ) . '</span>';
			$tooltip = $texts['tooltip'];
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
				'tsosk-sq-ab-load'    => array( __( 'Page time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tx['page_ms'], '', 'page_ms' ),
				'tsosk-sq-ab-dbtime'  => array( __( 'Database time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tx['db_ms'], '', 'db_ms' ),
				'tsosk-sq-ab-memory'  => array( __( 'Peak memory', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tx['memory'], '', 'memory' ),
				'tsosk-sq-ab-queries' => array( __( 'Queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tx['queries'], '', 'queries' ),
				'tsosk-sq-ab-slow'    => array(
					/* translators: %d: slow threshold in milliseconds */
					sprintf( __( 'Slow (≥ %d ms)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $settings['threshold_ms'] ),
					$tx['slow'],
					$texts['tone']['slow'],
					'slow',
				),
				'tsosk-sq-ab-dupcnt'  => array( __( 'Duplicate patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tx['dups'], $texts['tone']['dups'], 'dups' ),
			);
			foreach ( $rows as $row_id => $row ) {
				$node = array(
					'parent' => 'tsosk-slow-queries',
					'id'     => $row_id,
					'title'  => $this->admin_bar_row( $row[0], $row[1], $row[2], '', $row[3] ), // Escaped inside admin_bar_row().
					'meta'   => array( 'class' => 'tsosk-sq-ab-kv' ),
				);
				if ( 'tsosk-sq-ab-queries' === $row_id ) {
					$node['href'] = $tab_url . $a_queries;
				} elseif ( 'tsosk-sq-ab-dupcnt' === $row_id ) {
					$node['href'] = $tab_url . $a_dupes;
				} elseif ( 'tsosk-sq-ab-slow' === $row_id ) {
					$node['href'] = $tab_url . $a_slow;
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
				$origin_prefix = '' !== $live['top_dupe_origin'] ? $this->format_origin_label( $live['top_dupe_origin'] ) . ' · ' : '';
				$wp_admin_bar->add_node(
					array(
						'parent' => 'tsosk-slow-queries',
						'id'     => 'tsosk-sq-ab-dupes',
						'title'  => $this->admin_bar_row(
							/* translators: %d: times the same query ran */
							sprintf( __( 'Duplicated %d×', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $live['top_dupe_count'] ),
							__( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'bad',
							$origin_prefix . $this->admin_bar_excerpt( $this->fingerprint_sql( $live['top_dupe_sql'] ), 64 )
						),
						'href'   => $tab_url . $a_dupes,
						'meta'   => array(
							'class' => 'tsosk-sq-ab-kv tsosk-sq-ab-item-warn',
							'title' => $live['top_dupe_sql'],
						),
					)
				);

				// Who causes the duplicates (needs "Origin detection").
				if ( ! empty( $live['dupe_origins'] ) ) {
					$wp_admin_bar->add_node(
						array(
							'parent' => 'tsosk-slow-queries',
							'id'     => 'tsosk-sq-ab-h-dorigin',
							'title'  => esc_html__( 'Duplicates by origin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'meta'   => array( 'class' => 'tsosk-sq-ab-head' ),
						)
					);
					$o_i = 0;
					foreach ( array_slice( $live['dupe_origins'], 0, 3, true ) as $o_key => $o_runs ) {
						$wp_admin_bar->add_node(
							array(
								'parent' => 'tsosk-slow-queries',
								'id'     => 'tsosk-sq-ab-dorigin-' . ( ++$o_i ),
								'title'  => $this->admin_bar_row(
									$this->format_origin_label( (string) $o_key ),
									/* translators: %d: number of query executions */
									sprintf( _n( '%d run', '%d runs', (int) $o_runs, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $o_runs )
								),
								'href'   => $tab_url . $a_dupes,
								'meta'   => array( 'class' => 'tsosk-sq-ab-kv' ),
							)
						);
					}
				} elseif ( empty( $settings['capture_origin'] ) ) {
					$wp_admin_bar->add_node(
						array(
							'parent' => 'tsosk-slow-queries',
							'id'     => 'tsosk-sq-ab-origin-hint',
							'title'  => esc_html__( 'Which plugin causes them? Turn on Origin detection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							'href'   => $tab_url . '#tsosk-sq-settings',
							'meta'   => array( 'class' => 'tsosk-sq-ab-link' ),
						)
					);
				}
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
						'href'   => $tab_url . $a_slow,
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
	 * Footer: refresh the toolbar numbers with the whole page's queries.
	 *
	 * In wp-admin the bar is printed at the very top, before most queries run,
	 * so its first numbers undercount (Query Monitor updates its bar the same way).
	 */
	public function enqueue_admin_bar_refresh(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$live = $this->get_current_request_query_stats();
		if ( ! $live ) {
			return;
		}

		TSOSK_I18n::get_instance()->load_translations_for_admin_bar();
		$texts = $this->admin_bar_texts( $live );

		wp_register_script( 'tsosk-sq-admin-bar', TSOSK_URL . 'assets/js/tsosk-sq-admin-bar.js', array(), TSOSK_VERSION, true );
		wp_add_inline_script(
			'tsosk-sq-admin-bar',
			'window.tsoskSqLive = ' . wp_json_encode( $texts ) . ';',
			'before'
		);
		wp_enqueue_script( 'tsosk-sq-admin-bar' );
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
			. '#wpadminbar .tsosk-sq-pill[hidden]{display:none!important}'
			. '#wpadminbar .tsosk-sq-pill--bad{background:#d63638}#wpadminbar .tsosk-sq-pill--warn{background:#dba617;color:#1d2327}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-submenu .ab-item{'
			. 'white-space:normal!important;word-break:break-word;overflow-wrap:anywhere;height:auto!important;line-height:1.4;font-size:12px;padding-top:6px!important;padding-bottom:6px!important}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries .ab-submenu>li{height:auto}'
			. '#wpadminbar .tsosk-sq-ab-head>.ab-item{background:rgba(0,0,0,.18)!important;color:#a7aaad!important;font-size:10px!important;letter-spacing:.06em;text-transform:uppercase;cursor:default;padding-top:4px!important;padding-bottom:4px!important}'
			. '#wpadminbar .tsosk-sq-kvline{display:flex;justify-content:space-between;gap:16px}'
			. '#wpadminbar .tsosk-sq-v{font-weight:600}#wpadminbar .tsosk-sq-v--warn{color:#f0c33c}#wpadminbar .tsosk-sq-v--bad{color:#f86368}'
			. '#wpadminbar .tsosk-sq-code{display:block;margin-top:2px;font-family:Consolas,Monaco,monospace;font-size:11px;color:#a7aaad}'
			. '#wpadminbar .tsosk-sq-ab-link>.ab-item{color:#72aee6!important}'
			. '@media screen and (max-width:782px){#wpadminbar #wp-admin-bar-tsosk-slow-queries{display:block!important;width:46px;max-width:46px;overflow:hidden}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries>.ab-item{height:46px;width:46px;padding:0;justify-content:center;font-size:12px}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries>.ab-item .tsosk-sq-load,'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries>.ab-item .tsosk-sq-pill{display:none!important}'
			. '#wpadminbar #wp-admin-bar-tsosk-slow-queries .tsosk-sq-dot{width:14px;height:14px;margin:0}'
			. '#wpadminbar #wp-admin-bar-sweeppress-menu,#wpadminbar #wp-admin-bar-itsec_admin_bar_menu{display:none!important}}';
		wp_register_style( 'tsosk-sq-admin-bar', false, array(), TSOSK_VERSION );
		wp_enqueue_style( 'tsosk-sq-admin-bar' );
		wp_add_inline_style( 'tsosk-sq-admin-bar', $css );
	}

	/**
	 * Collect query stats for the current request.
	 *
	 * @return array{load_ms:float,memory_mb:float,query_count:int,query_time_ms:float,slow_count:int,slowest_ms:float,slowest_sql:string,dupe_patterns:int,dupe_extra:int,top_dupe_count:int,top_dupe_sql:string,top_dupe_origin:string,dupe_origins:array<string,int>}|null
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

		$query_count = 0;

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
		$dupe_origins   = array();
		foreach ( $dupes as $sql => $n ) {
			if ( (int) $n > $top_dupe_count ) {
				$top_dupe_count = (int) $n;
				$top_dupe_sql   = (string) $sql;
			}
			$origin = (string) ( $built['origins'][ $sql ] ?? '' );
			if ( '' !== $origin ) {
				$dupe_origins[ $origin ] = ( $dupe_origins[ $origin ] ?? 0 ) + (int) $n;
			}
		}
		arsort( $dupe_origins, SORT_NUMERIC );

		$load_ms = defined( 'WP_START_TIMESTAMP' ) ? ( microtime( true ) - WP_START_TIMESTAMP ) * 1000 : 0;

		return array(
			'load_ms'         => round( $load_ms, 1 ),
			'memory_mb'       => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'query_count'     => $query_count,
			'query_time_ms'   => round( $query_time_ms, 1 ),
			'slow_count'      => $slow_count,
			'slowest_ms'      => round( $slowest_ms, 2 ),
			'slowest_sql'     => $slowest_sql,
			'dupe_patterns'   => $dupe_patterns,
			'dupe_extra'      => $dupe_extra,
			'top_dupe_count'  => $top_dupe_count,
			'top_dupe_sql'    => $top_dupe_sql,
			'top_dupe_origin' => (string) ( $built['origins'][ $top_dupe_sql ] ?? '' ),
			'dupe_origins'    => $dupe_origins,
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

		$raw_ignore   = isset( $_POST['ignore_patterns'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ignore_patterns'] ) )
			: '';
		$ignore_lines = preg_split( '/\r\n|\r|\n/', $raw_ignore );
		if ( false === $ignore_lines ) {
			$ignore_lines = array();
		}
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
			'copy_plugins'    => $this->sanitize_copy_plugins( wp_unslash( $_POST['copy_plugins'] ?? array() ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_copy_plugins().
			'ignore_patterns' => $ignore_patterns,
		);

		update_option( self::SETTINGS_OPTION, $new, true ); // Read on every request — must autoload.
		if ( $new['enabled'] ) {
			$this->mark_settings_seen();
		}

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

		wp_send_json_success(
			array(
				'message'          => $message,
				'warn_savequeries' => $warn_savequeries,
			)
		);
	}

	/**
	 * AJAX: add one ignore pattern (from Top Slow Queries).
	 */
	public function ajax_ignore_pattern(): void {
		check_ajax_referer( 'tsosk_sq_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$pattern    = isset( $_POST['pattern'] )
			? sanitize_textarea_field( wp_unslash( $_POST['pattern'] ) )
			: '';
		$normalized = preg_replace( '/\s+/', ' ', $pattern );
		$pattern    = is_string( $normalized ) ? trim( $normalized ) : trim( $pattern );
		if ( '' === $pattern || mb_strlen( $pattern ) > 2000 ) {
			wp_send_json_error( __( 'Invalid ignore pattern.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Avoid sanitize_text_field() here — it can alter SQL fingerprints (?, %, etc.).
		// Merge into the raw option only — do not rewrite the whole settings blob via get_settings() defaults.
		$raw = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$patterns = $this->get_settings()['ignore_patterns'];
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
		$raw['ignore_patterns'] = $patterns;
		update_option( self::SETTINGS_OPTION, $raw, true ); // Read on every request — must autoload.

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

		$log   = $this->get_log();
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

		// Drop any output buffering so the download is not truncated or mixed with notices.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
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
		fputcsv( $out, array( 'timestamp_utc', 'url', 'load_ms', 'query_ms', 'fingerprint', 'sql', 'caller' ), ',', '"', '' );
		foreach ( $log as $batch ) {
			$ts   = isset( $batch['ts'] ) ? gmdate( 'c', (int) $batch['ts'] ) : '';
			$url  = (string) ( $batch['url'] ?? '' );
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
					),
					',',
					'"',
					''
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
			$batch_queries = array();
			foreach ( (array) ( $batch['queries'] ?? array() ) as $q ) {
				if ( is_array( $q ) ) {
					$q['origin_label'] = ! empty( $q['origin'] ) ? $this->format_origin_label( (string) $q['origin'] ) : '';
					$batch_queries[]   = $q;
				}
			}
			$items_with_id[] = array(
				'id'         => (string) ( $batch['id'] ?? '' ),
				'ts'         => (int) ( $batch['ts'] ?? 0 ),
				'url'        => (string) ( $batch['url'] ?? '' ),
				'load_ms'    => (float) ( $batch['load_ms'] ?? 0 ),
				'slow_count' => (int) ( $batch['slow_count'] ?? 0 ),
				'queries'    => $batch_queries,
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

	/**
	 * Render.
	 */
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

		<?php /* ── Settings card (collapsed when the monitor is already running) ── */ ?>
		<details class="tsosk-card tsosk-sq-settings-details" id="tsosk-sq-settings" <?php echo $this->should_open_settings_panel( (bool) $s['enabled'] ) ? 'open' : ''; ?>>
			<summary class="tsosk-sq-settings-summary">
				<span class="tsosk-sq-settings-summary-title"><?php esc_html_e( 'Monitor Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<span class="tsosk-sq-settings-summary-meta">
					<?php
					echo $s['enabled']
						? esc_html(
							sprintf(
								/* translators: %d: threshold in milliseconds */
								__( 'On · ≥ %d ms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $s['threshold_ms']
							)
						)
						: esc_html__( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					?>
				</span>
			</summary>
			<table class="tsosk-kv-table tsosk-sq-settings-table">
				<tr>
					<th><?php esc_html_e( 'Enable monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-enabled" value="1" <?php checked( $s['enabled'] ); ?>>
							<?php esc_html_e( 'Capture slow queries on every request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Slow threshold', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td class="tsosk-sq-settings-inline">
						<input type="number" id="tsosk-sq-threshold"
								value="<?php echo esc_attr( (string) $s['threshold_ms'] ); ?>"
								min="1" max="10000" step="1">
						<span class="description"><?php esc_html_e( 'ms (recommended: 100)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Max log entries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td class="tsosk-sq-settings-inline">
						<input type="number" id="tsosk-sq-max-entries"
								value="<?php echo esc_attr( (string) $s['max_entries'] ); ?>"
								min="50" max="2000" step="50">
						<span class="description"><?php esc_html_e( 'request batches', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Exclude', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td class="tsosk-sq-settings-checks">
						<label>
							<input type="checkbox" id="tsosk-sq-exclude-ajax" value="1" <?php checked( $s['exclude_ajax'] ); ?>>
							<?php esc_html_e( 'AJAX', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<label>
							<input type="checkbox" id="tsosk-sq-exclude-cron" value="1" <?php checked( $s['exclude_cron'] ); ?>>
							<?php esc_html_e( 'WP-Cron', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Admin bar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-show-admin-bar" value="1" <?php checked( ! empty( $s['show_admin_bar'] ) ); ?>>
							<?php esc_html_e( 'Show in the toolbar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Origin detection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sq-capture-origin" value="1" <?php checked( ! empty( $s['capture_origin'] ) ); ?>>
							<?php esc_html_e( 'Attribute queries to plugin / theme / core (extra overhead)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Copy to AI — plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<p class="description" style="margin:0 0 6px;">
							<?php esc_html_e( 'Filters the “Duplicate queries from plugins selected in settings” panel (and its Copy to AI). The live table below still lists every duplicate on this admin page. None selected = all. Defaults to tso-* until you save. Needs Origin detection.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</p>
						<p class="tsosk-sq-copy-plugins-toolbar">
							<button type="button" class="button button-small" id="tsosk-sq-copy-plugins-tso"><?php esc_html_e( 'Select tso-*', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
							<button type="button" class="button button-small" id="tsosk-sq-copy-plugins-none"><?php esc_html_e( 'Clear (all plugins)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
						</p>
						<div class="tsosk-sq-copy-plugins" id="tsosk-sq-copy-plugins">
							<?php
							$plugin_choices = $this->get_plugins_for_copy_filter();
							$selected       = array_fill_keys( $s['copy_plugins'], true );
							if ( empty( $plugin_choices ) ) :
								?>
							<p class="description"><?php esc_html_e( 'No plugins found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
							<?php else : ?>
								<?php foreach ( $plugin_choices as $slug => $name ) : ?>
							<label class="tsosk-sq-copy-plugin-label">
								<input type="checkbox" class="tsosk-sq-copy-plugin" value="<?php echo esc_attr( $slug ); ?>" <?php checked( isset( $selected[ $slug ] ) ); ?>>
								<code><?php echo esc_html( $slug ); ?></code>
								<span><?php echo esc_html( $name ); ?></span>
							</label>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Ignore patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-sq-ignore-patterns" rows="3" class="tsosk-sq-ignore-patterns"
									placeholder="<?php esc_attr_e( 'One pattern per line. Example: action_scheduler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_textarea( implode( "\n", $s['ignore_patterns'] ) ); ?></textarea>
					</td>
				</tr>
			</table>
			<p class="tsosk-sq-settings-save">
				<button class="button button-primary" id="tsosk-sq-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sq-settings-msg"></span>
			</p>
		</details>

		<?php /* ── Stats cards ── */ ?>
		<?php if ( ! empty( $log ) ) : ?>
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
			<?php
			$stat_cards = array(
				array(
					'val'  => $stats['total_slow'],
					'lbl'  => __( 'Slow queries logged', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn' => $stats['total_slow'] > 50,
				),
				array(
					'val'  => $stats['total_batches'],
					'lbl'  => __( 'Requests captured', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn' => false,
				),
				array(
					'val'  => number_format( $stats['slowest_ms'], 2 ) . ' ms',
					'lbl'  => __( 'Slowest query', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'warn' => $stats['slowest_ms'] > 500,
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
				<?php esc_html_e( 'Queries grouped by SQL fingerprint (literals replaced with ?). Ignore stops logging a noisy pattern.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Index hint: a best-effort suggestion when a WHERE/ORDER BY column has no matching index on that table (— means none / already indexed / shape not recognised). It never creates the index for you.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-sq-patterns-table">
					<thead><tr>
						<th class="tsosk-sq-col-count"><?php esc_html_e( 'Count', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-ms"><?php esc_html_e( 'Max', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-ms"><?php esc_html_e( 'Avg', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Fingerprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-hint"><?php esc_html_e( 'Index hint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-sq-col-action"></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $stats['top_sqls'] as $entry ) : ?>
						<?php
						$fp_display = (string) ( $entry['fingerprint'] ?? $entry['sql'] );
						$idx_hint   = $this->suggest_index_for_sql( (string) $entry['sql'] );
						?>
					<tr>
						<td class="tsosk-sq-col-count">
							<span class="tsosk-badge tsosk-badge-<?php echo $entry['count'] > 5 ? 'warn' : 'info'; ?>">
								×<?php echo esc_html( (string) $entry['count'] ); ?>
							</span>
						</td>
						<td class="tsosk-sq-col-ms <?php echo $entry['max'] > 500 ? 'tsosk-sq-warn' : ( $entry['max'] > 200 ? 'tsosk-sq-amber' : '' ); ?>">
							<?php echo esc_html( number_format( (float) $entry['max'], 2 ) ); ?> ms
						</td>
						<td class="tsosk-sq-col-ms">
							<?php echo esc_html( number_format( (float) ( $entry['avg'] ?? 0 ), 2 ) ); ?> ms
						</td>
						<td class="tsosk-sq-col-fp">
							<?php $this->render_collapsible_sql( $fp_display, 100 ); ?>
						</td>
						<td class="tsosk-sq-col-hint"><?php echo $idx_hint ? esc_html( $idx_hint ) : '—'; ?></td>
						<td class="tsosk-sq-col-action">
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

		$sq_enabled   = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$sq_queries   = $sq_enabled && is_array( $wpdb->queries ) ? $wpdb->queries : array();
		$sq_count     = count( $sq_queries );
		$sq_total     = $sq_enabled ? array_sum( array_column( $sq_queries, 1 ) ) : 0;
		$sq_max       = $sq_count ? max( array_column( $sq_queries, 1 ) ) : 0;
		$threshold_ms = (int) $this->get_settings()['threshold_ms'];
		$built        = $this->build_exact_dupe_map( $sq_queries );
		$sq_sql_map   = $built['map'];
		// Exact SQL duplicates (same idea as Query Monitor) — not fingerprints.
		$sq_dupes        = array_filter( $sq_sql_map, static fn( $n ) => $n > 1 );
		$sq_dupe_rows    = 0;
		$sq_detect       = ! empty( $this->get_settings()['capture_origin'] );
		$sq_dupe_origins = array();
		foreach ( $sq_dupes as $sql_key => $n ) {
			$sq_dupe_rows += (int) $n;
			$o_key         = (string) ( $built['origins'][ $sql_key ] ?? '' );
			if ( '' !== $o_key ) {
				$sq_dupe_origins[ $o_key ] = ( $sq_dupe_origins[ $o_key ] ?? 0 ) + (int) $n;
			}
		}
		arsort( $sq_dupe_origins, SORT_NUMERIC );
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
					<?php
					$live_url = '';
					if ( isset( $_SERVER['REQUEST_URI'] ) ) {
						$live_url = TSOSK_Support::get_request_uri_for_display();
					}
					$live_dupe_total = 0;
					foreach ( $built['details'] as $live_row ) {
						if ( (int) ( $live_row['count'] ?? 0 ) >= 2 ) {
							++$live_dupe_total;
						}
					}
					$live_payload = $this->build_dupe_report_payload(
						$built['details'],
						array(
							'url'         => $live_url,
							'query_count' => (int) $built['query_count'],
							'detect'      => $sq_detect,
						),
						40
					);
					$live_shown   = count( $live_payload['rows'] );
					?>
			<div class="tsosk-notice tsosk-notice-warn tsosk-sq-dupe-banner" id="tsosk-sq-live-dupe-panel">
				<div class="tsosk-sq-dupe-banner-actions">
					<button type="button" class="button tsosk-sq-copy-report"
							data-dupe-panel="live"
							data-msg="tsosk-sq-live-dupe-copy-msg">
						<?php esc_html_e( 'Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-sq-live-dupe-copy-msg" aria-live="polite"></span>
				</div>
				<div class="tsosk-sq-dupe-banner-body">
					<p class="tsosk-sq-dupe-banner-lead">
						<strong><?php esc_html_e( '⚠ Duplicate queries detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<?php
						printf(
						/* translators: 1: distinct identical SQL count, 2: total executions of those queries */
							esc_html__( '%1$d identical SQL statements run more than once (%2$d executions in total). Same criterion as Query Monitor: exact SQL text, not fingerprints. Often caused by get_option()/get_post_meta() in a loop without caching.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							(int) count( $sq_dupes ),
							(int) $sq_dupe_rows
						);
						?>
					</p>
					<?php if ( ! empty( $sq_dupe_origins ) ) : ?>
					<p class="tsosk-sq-dupe-banner-meta">
						<strong><?php esc_html_e( 'Duplicates by origin:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<?php
						$origin_bits = array();
						foreach ( array_slice( $sq_dupe_origins, 0, 5, true ) as $o_key => $o_runs ) {
							$origin_bits[] = $this->format_origin_label( (string) $o_key ) . ' (' . (int) $o_runs . ')';
						}
						echo esc_html( implode( ' · ', $origin_bits ) );
						?>
					</p>
					<?php elseif ( ! $sq_detect ) : ?>
					<p class="tsosk-sq-dupe-banner-meta">
						<?php esc_html_e( 'Turn on "Origin detection" in Monitor Settings to see which plugin or theme causes them.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</p>
					<?php endif; ?>
					<p class="description tsosk-sq-dupe-origin-hint">
						<?php esc_html_e( 'Tick the rows below to choose what Copy to AI includes. This list shows every duplicate on this page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						<?php if ( $live_dupe_total > $live_shown ) : ?>
							<?php
							echo ' ';
							printf(
								/* translators: 1: rows shown in the table, 2: total duplicate patterns */
								esc_html__( 'Showing the top %1$d of %2$d patterns (Copy to AI uses the ticked rows below).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $live_shown,
								(int) $live_dupe_total
							);
							?>
						<?php endif; ?>
					</p>
				</div>
				<script type="application/json" id="tsosk-sq-live-dupe-json"><?php echo wp_json_encode( $live_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
			</div>
			<div class="tsosk-table-wrap" style="margin-bottom:14px;">
			<table class="widefat tsosk-table tsosk-sq-dupe-table" id="tsosk-sq-live-dupe-table">
				<thead><tr>
					<th class="tsosk-sq-col-pick" scope="col">
						<input type="checkbox" class="tsosk-sq-dupe-pick-all" checked
								title="<?php esc_attr_e( 'Select all for Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
								aria-label="<?php esc_attr_e( 'Select all for Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					</th>
					<th class="tsosk-sq-col-times"><?php esc_html_e( 'Times', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sq-col-time"><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sq-col-sql"><?php esc_html_e( 'SQL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sq-col-side"><?php esc_html_e( 'Callers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sq-col-side"><?php esc_html_e( 'Components', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sq-col-side"><?php esc_html_e( 'Potential troublemakers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $live_payload['rows'] as $prow ) : ?>
					<tr class="tsosk-sq-dupe-row" data-dupe-id="<?php echo esc_attr( (string) $prow['id'] ); ?>">
						<td class="tsosk-sq-col-pick">
							<input type="checkbox" class="tsosk-sq-dupe-pick" value="<?php echo esc_attr( (string) $prow['id'] ); ?>" checked
									aria-label="<?php esc_attr_e( 'Include this duplicate in Copy to AI', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
						</td>
						<td class="tsosk-sq-cell-mono">
							<span class="tsosk-badge tsosk-badge-warn">
								<?php
								printf(
									/* translators: %d: number of times this query ran */
									esc_html__( '×%d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									(int) $prow['count']
								);
								?>
							</span>
						</td>
						<td class="tsosk-sq-cell-mono"><?php echo esc_html( number_format_i18n( (float) $prow['time_ms'], 2 ) ); ?> ms</td>
						<td class="tsosk-sq-cell-sql"><?php $this->render_collapsible_sql( (string) $prow['sql'] ); ?></td>
						<td class="tsosk-sq-cell-caller"><?php $this->render_count_map( (array) $prow['callers'] ); ?></td>
						<td class="tsosk-sq-cell-caller"><?php $this->render_count_map( (array) $prow['components'], true ); ?></td>
						<td class="tsosk-sq-cell-caller"><?php $this->render_count_map( (array) $prow['troublemakers'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
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
				uasort( $sorted, static fn( $a, $b ) => $b[1] <=> $a[1] ); // Keep keys: they match the recorded backtraces.
				$i = -1;
				foreach ( $sorted as $qi => $q ) :
					++$i;
					$origin    = $this->origin_for_query_index( (int) $qi );
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
					$kw_c      = $kw_color[ $kw ] ?? '#374151';
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
						<?php
						$rest = ltrim( mb_substr( $sql_clean, strlen( $kw ) ) );
						$this->render_collapsible_sql( $rest );
						?>
					</td>
					<td class="tsosk-sq-cell-caller">
						<?php
						$this->render_origin_badge( $origin, $sq_detect );
						echo esc_html( $this->format_caller_frames( $caller ) );
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
	private function render_single_batch( array $batch, string $nonce, int $threshold_ms ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for callers that pass the active threshold.
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
		$max_time   = $times ? max( $times ) : 0;
		$nplus1     = $this->detect_nplus1( $queries );
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
				<span class="tsosk-sq-batch-url"><?php echo esc_html( $url ? $url : '—' ); ?></span>
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
					$t         = (float) ( $q['time'] ?? 0 );
					$sql       = (string) ( $q['sql'] ?? '' );
					$cal       = (string) ( $q['caller'] ?? '' );
					$kw        = strtoupper( strtok( $sql, ' ' ) );
					$kw_colors = array(
						'SELECT' => '#2271b1',
						'INSERT' => '#16a34a',
						'UPDATE' => '#d97706',
						'DELETE' => '#d63638',
						'CREATE' => '#7c3aed',
						'DROP'   => '#d63638',
					);
					$kw_c      = $kw_colors[ $kw ] ?? '#374151';
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
					<div class="tsosk-sq-query-sql"><?php $this->render_collapsible_sql( $sql ); ?></div>
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
