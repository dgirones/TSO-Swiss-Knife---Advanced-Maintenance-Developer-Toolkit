<?php
/**
 * TSO Swiss Knife – Module: View Counter.
 *
 * Cache-safe visit counter for posts, pages and outbound link clicks.
 * Counting happens via a small front-end beacon (fetch/sendBeacon) so it
 * keeps working even when the page itself is served from a full-page cache
 * (LiteSpeed, etc.) — a PHP hook on template_redirect would never run on a
 * cached hit. De-duplication uses a short-lived server-side fingerprint
 * (hashed IP + user agent + day), never a cookie, so the plugin never adds
 * anything for a site's cookie/consent notice to disclose.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_View_Counter
 */
class TSOSK_Mod_View_Counter {

	const OPTION_SETTINGS   = 'tsosk_view_counter_settings';
	const OPTION_DB_VERSION = 'tsosk_view_counter_db_version';
	const DB_VERSION        = '1';
	const OPTION_LAST_IMPORT = 'tsosk_view_counter_last_import';
	/** Date bucket for imported lifetime totals whose real dates are unknown (counts for "All time" only). */
	const LEGACY_DATE       = '2000-01-01';
	const DEDUP_WINDOW      = 30 * MINUTE_IN_SECONDS;
	const NONCE_ACTION      = 'tsosk_vc_nonce';
	const EXPORT_ACTION     = 'tsosk_vc_export';
	const CRON_HOOK         = 'tsosk_vc_weekly_email';
	const CRON_SCHEDULE     = 'tsosk_weekly';
	const TOP_LIMIT         = 10;

	/**
	 * Max distinct outbound-link URLs tracked. The public, unauthenticated
	 * beacon (REST + admin-ajax nopriv) could otherwise be hit repeatedly
	 * with a different URL each time to grow this table without bound —
	 * a real site has a finite, generally small number of actual outbound
	 * links, so once this cap is reached, hits for URLs not already known
	 * are simply not recorded (existing tracked links are unaffected).
	 */
	const MAX_TRACKED_LINKS = 5000;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'wp_ajax_tsosk_vc_hit', array( $this, 'ajax_hit' ) );
		add_action( 'wp_ajax_nopriv_tsosk_vc_hit', array( $this, 'ajax_hit' ) );
		add_action( 'wp_ajax_tsosk_vc_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_tsosk_vc_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_tsosk_vc_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_tsosk_vc_top_content', array( $this, 'ajax_top_content' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- fixed weekly interval for the optional summary email.
		add_action( self::CRON_HOOK, array( $this, 'send_weekly_email' ) );
	}

	/**
	 * Register a "weekly" WP-Cron interval (WordPress core only ships hourly,
	 * twicedaily and daily).
	 *
	 * @param array<string, array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int,display:string}>
	 */
	public function add_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly (TSO View Counter)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule/unschedule the weekly summary email to match the current setting.
	 *
	 * @param array $settings Current (already-saved) settings.
	 */
	private function sync_weekly_email_schedule( array $settings ): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( ! empty( $settings['email_weekly_summary'] ) ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled email (called on plugin deactivation).
	 */
	public static function unschedule_weekly_email(): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * Front-end + list-table hooks. Only called once, from tsosk_init() (see
	 * main plugin file), so it must be safe to run on every request.
	 */
	public function init(): void {
		$settings = $this->get_settings();

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_beacon' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
		// Also fires on admin-ajax.php, so tables exist before the first beacon hit even when the plugin was updated without being re-activated.
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy_wrapped_tables' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_delete_temporary_diagnostic_option' ) );

		foreach ( $this->get_trackable_post_types( $settings ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_views_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_views_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'make_views_column_sortable' ) );
		}
		add_action( 'pre_get_posts', array( $this, 'maybe_sort_by_views' ) );

		if ( ! empty( $settings['track_outbound_links'] ) ) {
			add_filter( 'the_content', array( $this, 'mark_outbound_links' ), 20 );
		}
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	/**
	 * Default settings.
	 *
	 * @return array{post_types: string[], exclude_logged_in: bool, track_outbound_links: bool}
	 */
	private function get_default_settings(): array {
		return array(
			'post_types'           => array( 'post', 'page' ),
			'exclude_logged_in'    => true,
			'track_outbound_links' => true,
			'email_weekly_summary' => false,
			'email_period_days'    => 7,
			'email_recipient'      => get_option( 'admin_email' ),
		);
	}

	/**
	 * Restrict the summary-email window to the allowed choices (7, 15 or 30 days).
	 *
	 * @param int $days Requested days.
	 * @return int
	 */
	private function normalize_email_period( int $days ): int {
		return in_array( $days, array( 7, 15, 30 ), true ) ? $days : 7;
	}

	/**
	 * Current settings (merged with defaults). Read on (almost) every
	 * front-end request, so it must stay autoloaded.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}

		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->settings_cache = array_merge( $this->get_default_settings(), $stored );
		return $this->settings_cache;
	}

	/**
	 * Post types this module is configured to track.
	 *
	 * @param array|null $settings Optional pre-fetched settings.
	 * @return string[]
	 */
	private function get_trackable_post_types( ?array $settings = null ): array {
		$settings = $settings ?? $this->get_settings();
		$types    = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : array();
		$types    = array_values( array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' ) );
		return $types;
	}

	// ── DB table (created lazily, versioned like a normal WP schema bump) ─────

	/**
	 * Create/upgrade the plugin's own tables when the stored schema version
	 * is behind DB_VERSION. Safe to call on every admin_init — dbDelta() is a
	 * no-op when the table already matches.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * One-time cleanup of the temporary "last run" diagnostic option used
	 * while tracking down the legacy-table issue; no longer written, so any
	 * leftover copy on an already-updated site is just removed once.
	 */
	public static function maybe_delete_temporary_diagnostic_option(): void {
		if ( get_option( 'tsosk_vc_legacy_cleanup_last_run', false ) ) {
			delete_option( 'tsosk_vc_legacy_cleanup_last_run' );
		}
	}

	/**
	 * Self-heal from stale-opcode duplicate tables.
	 *
	 * On some hosts a plain FTP file overwrite does not invalidate PHP's
	 * opcode cache the way WordPress's own updater does (wp_opcache_invalidate()
	 * runs only for updates applied through the WP upgrader). If that happens
	 * right after an older copy of this plugin's files is uploaded, PHP can
	 * keep executing the old bytecode for a while — including an old, now
	 * removed table-naming scheme that wrapped these two tables' names with a
	 * "plugins" / "pluginspc_" prefix segment (e.g. "{$wpdb->prefix}pluginstsosk_views").
	 * Any such leftover table accumulates real, live data of its own instead
	 * of being a harmless empty residue, splitting the site's view counts
	 * across two physical tables.
	 *
	 * Cheap to call on every admin_init: a couple of SHOW TABLES LIKE lookups,
	 * and it only does real work when a legacy-named table is actually found —
	 * merges its rows into the canonical table (summing view counts, keeping
	 * link URLs deduplicated) and then drops it.
	 */
	public static function maybe_migrate_legacy_wrapped_tables(): array {
		global $wpdb;

		$prefix       = (string) $wpdb->prefix;
		$views_table  = self::views_table();
		$links_table  = self::links_table();
		$errors       = array();

		$legacy_links_tables = self::find_legacy_wrapped_tables( $prefix, 'tsosk_view_links' );
		foreach ( $legacy_links_tables as $legacy_links_table ) {
			$error = self::migrate_legacy_links_table( $legacy_links_table, $links_table );
			if ( '' !== $error ) {
				$errors[ $legacy_links_table ] = $error;
			}
		}

		$legacy_views_tables = self::find_legacy_wrapped_tables( $prefix, 'tsosk_views' );
		foreach ( $legacy_views_tables as $legacy_views_table ) {
			$error = self::migrate_legacy_views_table( $legacy_views_table, $views_table, $links_table, $legacy_links_tables );
			if ( '' !== $error ) {
				$errors[ $legacy_views_table ] = $error;
			}
		}

		if ( ! empty( $errors ) ) {
			update_option( 'tsosk_vc_legacy_cleanup_errors', $errors, false );
		} elseif ( get_option( 'tsosk_vc_legacy_cleanup_errors', false ) ) {
			delete_option( 'tsosk_vc_legacy_cleanup_errors' );
		}

		return $errors;
	}

	/**
	 * Whether a table currently exists (exact name match, not a LIKE-pattern
	 * lookup by the caller — the wildcard behaviour of SHOW TABLES LIKE only
	 * matters when the pattern itself contains %/_ chosen on purpose).
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name checked against a fixed candidate list, not user input.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $exists === $table;
	}

	/**
	 * Find leftover tables matching the old "{prefix}pc_{suffix}" naming
	 * scheme for a given canonical table suffix, excluding the canonical
	 * table itself.
	 *
	 * An earlier version of this check also looked for a
	 * "{prefix}plugins{suffix}" candidate, on the assumption that an old,
	 * removed code path had once wrapped the prefix with a literal "plugins"
	 * segment. That assumption was wrong and dangerous: on a site whose real
	 * $wpdb->prefix already legitimately contains "plugins" (e.g. WordPress
	 * installed under a /plugins/ subfolder with prefix "wptm_plugins"), that
	 * candidate could coincide with, or come close to, the *canonical* table
	 * itself rather than an actual leftover — this function must never treat
	 * the live, in-use table as a leftover to merge-and-drop. The only naming
	 * defect actually observed in the wild is a literal "pc_" segment spliced
	 * in right after the real prefix, so that's the only pattern checked now.
	 *
	 * @param string $prefix Site DB table prefix.
	 * @param string $suffix Canonical table suffix, e.g. "tsosk_views".
	 * @return string[] Matching table names that currently exist.
	 */
	private static function find_legacy_wrapped_tables( string $prefix, string $suffix ): array {
		global $wpdb;

		$canonical  = $prefix . $suffix;
		$candidates = array_unique(
			array(
				$prefix . 'pc_' . $suffix,
			)
		);

		$found = array();
		foreach ( $candidates as $candidate ) {
			if ( $candidate === $canonical ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name checked against a fixed candidate list, not user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $candidate ) );
			if ( $exists === $candidate ) {
				$found[] = $candidate;
			}
		}

		return $found;
	}

	/**
	 * Merge a legacy outbound-link table into the canonical one, then drop it.
	 *
	 * @param string $legacy_table    Existing legacy table name.
	 * @param string $canonical_table Canonical links table name.
	 * @return string Empty string on success (table gone), otherwise a description of why it's still there.
	 */
	private static function migrate_legacy_links_table( string $legacy_table, string $canonical_table ): string {
		global $wpdb;

		// A failed INSERT...SELECT (collation mismatch between the legacy and
		// canonical table, a stray oversize URL, etc.) must not leave the
		// leftover table behind forever — merging is best-effort, but removing
		// the junk table itself isn't optional, so this never returns early;
		// it always falls through to the DROP below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names built from fixed candidates, verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
		$wpdb->query( "INSERT IGNORE INTO {$canonical_table} (url, created) SELECT url, created FROM {$legacy_table}" );
		$insert_error = (string) $wpdb->last_error;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
		$wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" );
		$drop_error = (string) $wpdb->last_error;

		if ( self::table_exists( $legacy_table ) ) {
			if ( '' !== $drop_error ) {
				return $drop_error;
			}
			if ( '' !== $insert_error ) {
				return $insert_error;
			}
			return 'DROP TABLE IF EXISTS reported no error but the table is still there (insufficient DB privilege on some hosts silently no-ops instead of erroring).';
		}

		return '';
	}

	/**
	 * Merge a legacy views table into the canonical one, then drop it.
	 *
	 * Rows are summed per (object_type, object_id, view_date) rather than
	 * overwritten, since both the legacy and canonical table may have kept
	 * counting independently. object_type = 'link' rows need their object_id
	 * remapped from the legacy link table's row ids to the canonical link
	 * table's row ids (matched by URL) before they can be merged safely.
	 *
	 * @param string   $legacy_table         Existing legacy views table name.
	 * @param string   $canonical_table      Canonical views table name.
	 * @param string   $canonical_links_table Canonical links table name.
	 * @param string[] $legacy_links_tables   Legacy links table names found this run.
	 * @return string Empty string on success (table gone), otherwise a description of why it's still there.
	 */
	private static function migrate_legacy_views_table( string $legacy_table, string $canonical_table, string $canonical_links_table, array $legacy_links_tables ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names built from fixed candidates, verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
		$rows = $wpdb->get_results( "SELECT object_type, object_id, view_date, count FROM {$legacy_table}", ARRAY_A );
		// A failed SELECT (e.g. an even older schema with different column
		// names) must not leave the leftover table behind forever — merging
		// its rows is best-effort, but removing the junk table itself isn't
		// optional, so fall through to the DROP below with nothing to merge.
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Legacy link id → url, from whichever legacy links table was found alongside this one.
		$legacy_id_to_url = array();
		foreach ( $legacy_links_tables as $legacy_links_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
			$pairs = $wpdb->get_results( "SELECT id, url FROM {$legacy_links_table}", ARRAY_A );
			if ( is_array( $pairs ) ) {
				foreach ( $pairs as $pair ) {
					$legacy_id_to_url[ (int) $pair['id'] ] = (string) $pair['url'];
				}
			}
		}

		foreach ( $rows as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$view_date   = (string) $row['view_date'];
			$count       = (int) $row['count'];

			if ( 'link' === $object_type ) {
				$url = $legacy_id_to_url[ $object_id ] ?? '';
				if ( '' === $url ) {
					continue; // Can't remap without the URL — skip rather than merge into the wrong link.
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
				$new_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$canonical_links_table} WHERE url = %s", $url ) );
				if ( $new_id <= 0 ) {
					continue;
				}
				$object_id = $new_id;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$canonical_table} (object_type, object_id, view_date, count) VALUES (%s, %d, %s, %d) ON DUPLICATE KEY UPDATE count = count + VALUES(count)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first, can't be bound as %s (identifier, not a value).
					$object_type,
					$object_id,
					$view_date,
					$count
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name verified with SHOW TABLES first; no user input, can't be bound as %s (identifier, not a value).
		$wpdb->query( "DROP TABLE IF EXISTS {$legacy_table}" );
		$drop_error = (string) $wpdb->last_error;

		if ( self::table_exists( $legacy_table ) ) {
			if ( '' !== $drop_error ) {
				return $drop_error;
			}
			return 'DROP TABLE IF EXISTS reported no error but the table is still there (insufficient DB privilege on some hosts silently no-ops instead of erroring).';
		}

		return '';
	}

	/**
	 * (Re)create the views + link-lookup tables via dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			tsosk_require_wp_admin( 'includes/upgrade.php' );
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		$views_table     = self::views_table();
		$links_table     = self::links_table();

		$sql = "CREATE TABLE {$wpdb->prefix}tsosk_views (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(10) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			view_date DATE NOT NULL,
			count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY tsosk_vc_uniq (object_type, object_id, view_date),
			KEY tsosk_vc_type_id (object_type, object_id),
			KEY tsosk_vc_date (view_date)
		) {$charset_collate};

		CREATE TABLE {$wpdb->prefix}tsosk_view_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(767) NOT NULL,
			created BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY tsosk_vc_url (url)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Views table name.
	 *
	 * @return string Views table name (with prefix).
	 */
	private static function views_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tsosk_views';
	}

	/**
	 * Outbound-link lookup table name.
	 *
	 * @return string Outbound-link lookup table name (with prefix).
	 */
	private static function links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tsosk_view_links';
	}

	/**
	 * Drop both tables (called from uninstall.php only).
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tsosk_views" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tsosk_view_links" );
	}

	// ── Counting ──────────────────────────────────────────────────────────────

	/**
	 * Find or create the numeric id used to key an outbound URL in tsosk_views.
	 *
	 * @param string $url Absolute URL (already validated by the caller).
	 * @return int 0 on failure.
	 */
	private function get_or_create_link_id( string $url ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tsosk_view_links WHERE url = %s", $url ) );
		if ( $id > 0 ) {
			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tsosk_view_links" );
		if ( $count >= self::MAX_TRACKED_LINKS ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}tsosk_view_links (url, created) VALUES (%s, %d) ON DUPLICATE KEY UPDATE id = id",
				$url,
				time()
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tsosk_view_links WHERE url = %s", $url ) );
	}

	/**
	 * URL for a stored link id (used to render the outbound-links report).
	 *
	 * @param int $id Link row id.
	 * @return string
	 */
	private function get_link_url( int $id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$wpdb->prefix}tsosk_view_links WHERE id = %d", $id ) );
	}

	/**
	 * Whether this visitor already counted for this object today (server-side
	 * fingerprint, no cookie set on the visitor's browser).
	 *
	 * @param string $type      post|page|link.
	 * @param int    $object_id Row id.
	 * @return bool True when this is a fresh hit (and the fingerprint has now been stored).
	 */
	private function claim_hit( string $type, int $object_id ): bool {
		$ip  = $this->get_visitor_ip();
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$key = 'tsosk_vc_' . md5( $ip . '|' . $ua . '|' . $type . '|' . $object_id . '|' . gmdate( 'Y-m-d' ) );

		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::DEDUP_WINDOW );
		return true;
	}

	/**
	 * Best-effort visitor IP (never stored — only hashed into a short-lived
	 * transient key above).
	 *
	 * @return string
	 */
	private function get_visitor_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return preg_match( '/^[0-9a-fA-F.:]+$/', $ip ) ? $ip : '';
	}

	/**
	 * Basic bot/crawler filter for the User-Agent header.
	 *
	 * @return bool
	 */
	private function looks_like_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|pingdom|uptimerobot|semrush|ahrefs|mj12bot|python-requests|curl\//i', $ua );
	}

	/**
	 * Increment today's counter row for an object (insert-or-add-one).
	 *
	 * @param string $type      post|page|link.
	 * @param int    $object_id Row id.
	 */
	private function record_hit( string $type, int $object_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}tsosk_views (object_type, object_id, view_date, count) VALUES (%s, %d, %s, 1)
				 ON DUPLICATE KEY UPDATE count = count + 1",
				$type,
				$object_id,
				current_time( 'Y-m-d' )
			)
		);
	}

	/**
	 * Lifetime total for an object.
	 *
	 * @param string $type      post|page|link.
	 * @param int    $object_id Row id.
	 * @return int
	 */
	public function get_total( string $type, int $object_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type = %s AND object_id = %d",
				$type,
				$object_id
			)
		);
	}

	/**
	 * Total for an object over the last N days (inclusive of today).
	 *
	 * @param string $type      post|page|link.
	 * @param int    $object_id Row id.
	 * @param int    $days      Window size.
	 * @return int
	 */
	public function get_total_last_days( string $type, int $object_id, int $days ): int {
		global $wpdb;
		// Use the site's local "today" (current_time(), gmt=false) — record_hit()
		// buckets hits under current_time('Y-m-d'), i.e. site-local dates. Cutting
		// off against a UTC "now" instead would off-by-one near local midnight
		// on any site whose timezone isn't UTC.
		$since = wp_date( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type = %s AND object_id = %d AND view_date >= %s",
				$type,
				$object_id,
				$since
			)
		);
	}

	/**
	 * Top posts/pages by views over a period (or all-time).
	 *
	 * @param int $days  Window size in days, or 0 for all-time.
	 * @param int $limit Max rows.
	 * @return array<int, array{post_id:int, type:string, title:string, edit_url:string, total:int}>
	 */
	public function get_top_posts( int $days, int $limit = self::TOP_LIMIT ): array {
		global $wpdb;

		if ( $days > 0 ) {
			// Site-local "today" (see get_total_last_days()) — record_hit() stores
			// rows under the site-local date, so the cutoff must use the same basis.
			$since = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, object_type, SUM(count) AS total FROM {$wpdb->prefix}tsosk_views
					 WHERE object_type IN ('post','page') AND view_date >= %s
					 GROUP BY object_id, object_type ORDER BY total DESC LIMIT %d",
					$since,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, object_type, SUM(count) AS total FROM {$wpdb->prefix}tsosk_views
					 WHERE object_type IN ('post','page')
					 GROUP BY object_id, object_type ORDER BY total DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$post_id = absint( $row['object_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$title = get_the_title( $post_id );
			$out[] = array(
				'post_id'  => $post_id,
				'type'     => (string) ( $row['object_type'] ?? '' ),
				'title'    => '' !== $title ? $title : __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
				'total'    => absint( $row['total'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Top outbound links by clicks over a period (or all-time).
	 *
	 * @param int $days  Window size in days, or 0 for all-time.
	 * @param int $limit Max rows.
	 * @return array<int, array{url:string, total:int}>
	 */
	public function get_top_links( int $days, int $limit = self::TOP_LIMIT ): array {
		global $wpdb;

		if ( $days > 0 ) {
			// Site-local "today" — see get_total_last_days().
			$since = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.url AS url, SUM(v.count) AS total FROM {$wpdb->prefix}tsosk_views v
					 INNER JOIN {$wpdb->prefix}tsosk_view_links l ON l.id = v.object_id
					 WHERE v.object_type = 'link' AND v.view_date >= %s
					 GROUP BY l.url ORDER BY total DESC LIMIT %d",
					$since,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.url AS url, SUM(v.count) AS total FROM {$wpdb->prefix}tsosk_views v
					 INNER JOIN {$wpdb->prefix}tsosk_view_links l ON l.id = v.object_id
					 WHERE v.object_type = 'link'
					 GROUP BY l.url ORDER BY total DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$url = (string) ( $row['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'url'   => $url,
				'total' => absint( $row['total'] ?? 0 ),
			);
		}
		return $out;
	}

	// ── REST endpoint (public beacon) ────────────────────────────────────────

	/**
	 * Register the public hit-beacon REST route.
	 */
	public function register_rest_route(): void {
		register_rest_route(
			'tsosk-swiss-knife/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_hit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type' => array( 'required' => true ),
					'id'   => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * REST callback for the beacon. Intentionally open (visitors are
	 * anonymous) — hardened by strict type/whitelist checks instead of a nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_hit( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		$id   = $request->get_param( 'id' );
		// Public endpoint: ignore non-scalar input quietly instead of logging PHP warnings.
		$this->process_hit(
			is_scalar( $type ) ? sanitize_key( (string) $type ) : '',
			is_scalar( $id ) ? (string) $id : ''
		);
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * AJAX fallback for the beacon (admin-ajax.php), used when REST is
	 * disabled/blocked on a site.
	 */
	public function ajax_hit(): void {
		$this->process_hit(
			isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public anonymous counter, see class docblock.
			isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? sanitize_text_field( wp_unslash( ( is_scalar( $_POST['id'] ) ? (string) $_POST['id'] : '' ) ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		wp_send_json_success();
	}

	/**
	 * Shared validation + counting for both the REST and AJAX entry points.
	 *
	 * @param string $type    Raw type param (post|page|link).
	 * @param string $raw_id  Raw id param: a post ID for post/page, an md5
	 *                        of the destination URL for link (matches the
	 *                        data-tsosk-vc-url hash written by the beacon JS).
	 */
	private function process_hit( string $type, string $raw_id ): void {
		if ( $this->looks_like_bot() ) {
			return;
		}

		$settings = $this->get_settings();

		if ( 'link' === $type ) {
			if ( empty( $settings['track_outbound_links'] ) ) {
				return;
			}
			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( ( is_scalar( $_POST['url'] ) ? (string) $_POST['url'] : '' ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $url && isset( $_REQUEST['url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$url = esc_url_raw( wp_unslash( ( is_scalar( $_REQUEST['url'] ) ? (string) $_REQUEST['url'] : '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			if ( '' === $url || strlen( $url ) > 700 ) {
				return;
			}
			$object_id = $this->get_or_create_link_id( $url );
		} elseif ( in_array( $type, array( 'post', 'page' ), true ) ) {
			if ( ! in_array( $type, $this->get_trackable_post_types( $settings ), true ) ) {
				return;
			}
			$object_id = absint( $raw_id );
			if ( $object_id <= 0 || get_post_type( $object_id ) !== $type || 'publish' !== get_post_status( $object_id ) ) {
				return;
			}
			if ( ! empty( $settings['exclude_logged_in'] ) && is_user_logged_in() ) {
				return;
			}
		} else {
			return;
		}

		if ( $object_id <= 0 ) {
			return;
		}

		if ( $this->claim_hit( $type, $object_id ) ) {
			$this->record_hit( $type, $object_id );
		}
	}

	// ── Front-end beacon ──────────────────────────────────────────────────────

	/**
	 * Enqueue the tiny beacon script on singular views of a trackable post type.
	 */
	public function maybe_enqueue_beacon(): void {
		$settings = $this->get_settings();

		$is_singular_trackable = is_singular( $this->get_trackable_post_types( $settings ) );
		$track_links           = ! empty( $settings['track_outbound_links'] );

		if ( ! $is_singular_trackable && ! $track_links ) {
			return;
		}

		wp_enqueue_script(
			'tsosk-view-counter',
			TSOSK_URL . 'assets/js/tsosk-view-counter.js',
			array(),
			TSOSK_VERSION,
			true
		);

		$payload = array(
			'restUrl'    => esc_url_raw( rest_url( 'tsosk-swiss-knife/v1/hit' ) ),
			'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'trackId'    => 0,
			'type'       => '',
			'trackLinks' => $track_links,
		);

		if ( $is_singular_trackable ) {
			$payload['trackId'] = get_the_ID();
			$payload['type']    = (string) get_post_type();
		}

		wp_localize_script( 'tsosk-view-counter', 'tsookViewCounter', $payload );
	}

	/**
	 * Tag outbound (external) links in post content so the beacon script can
	 * count clicks on them. Purely additive — no href/target changes, so
	 * middle-click/open-in-new-tab keeps working exactly as before.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public function mark_outbound_links( string $content ): string {
		if ( is_admin() || is_feed() || '' === trim( $content ) ) {
			return $content;
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return (string) preg_replace_callback(
			'/<a\s+([^>]*?)href=(["\'])(https?:\/\/[^"\']+)\2([^>]*)>/i',
			function ( $matches ) use ( $home_host ) {
				$before = $matches[1];
				$quote  = $matches[2];
				$url    = $matches[3];
				$after  = $matches[4];

				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $host || ! $home_host || strcasecmp( $host, $home_host ) === 0 ) {
					return $matches[0]; // Internal link, or unparsable — leave untouched.
				}
				if ( false !== stripos( $before . $after, 'data-tsosk-vc-url' ) ) {
					return $matches[0]; // Already tagged.
				}

				return sprintf(
					'<a %1$shref=%2$s%3$s%2$s%4$s data-tsosk-vc-url=%2$s%5$s%2$s>',
					$before,
					$quote,
					esc_url( $url ),
					$after,
					esc_attr( $url )
				);
			},
			$content
		);
	}

	// ── Posts/Pages list-table column ────────────────────────────────────────

	/**
	 * Insert a "Views" column (with a Dashicon, matching WordPress' own
	 * icon-only columns like Comments) after the Comments column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_views_column( array $columns ): array {
		$icon  = '<span class="dashicons dashicons-visibility" style="line-height:1.4;" title="'
			. esc_attr__( 'TSO Views', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '"></span>';
		$label = $icon . ' ' . esc_html__( 'TSO Views', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		$new = array();
		foreach ( $columns as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'comments' === $key ) {
				$new['tsosk_views'] = $label;
			}
		}
		if ( ! isset( $new['tsosk_views'] ) ) {
			$new['tsosk_views'] = $label; // No comments column (e.g. Pages without comments support).
		}
		return $new;
	}

	/**
	 * Render the Views column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_views_column( string $column, int $post_id ): void {
		if ( 'tsosk_views' !== $column ) {
			return;
		}
		$total = $this->get_total( get_post_type( $post_id ), $post_id );
		echo esc_html( number_format_i18n( $total ) );
	}

	/**
	 * Register the Views column as sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function make_views_column_sortable( array $columns ): array {
		$columns['tsosk_views'] = 'tsosk_views';
		return $columns;
	}

	/**
	 * Sort the Posts/Pages list by lifetime views when requested.
	 *
	 * @param WP_Query $query Main list-table query.
	 */
	public function maybe_sort_by_views( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'tsosk_views' !== $query->get( 'orderby' ) ) {
			return;
		}

		global $wpdb;
		$views_table = self::views_table();
		$query->set( 'suppress_filters', false );
		add_filter(
			'posts_join',
			function ( $join ) use ( $wpdb, $views_table ) {
				return $join . " LEFT JOIN ( SELECT object_id, SUM(count) AS tsosk_total FROM {$wpdb->prefix}tsosk_views WHERE object_type IN ('post','page') GROUP BY object_id ) tsosk_v ON tsosk_v.object_id = {$wpdb->posts}.ID";
			}
		);
		add_filter(
			'posts_orderby',
			function () use ( $query ) {
				$dir = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
				return "COALESCE(tsosk_v.tsosk_total, 0) {$dir}";
			}
		);
	}

	// ── Admin: settings, import, reset ────────────────────────────────────────

	/**
	 * AJAX: save module settings.
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$posted_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) )
			: array();
		$posted_types = array_values( array_filter( $posted_types, 'post_type_exists' ) );

		$posted_email = isset( $_POST['email_recipient'] ) && is_string( $_POST['email_recipient'] ) ? sanitize_email( wp_unslash( $_POST['email_recipient'] ) ) : '';

		$settings = array(
			'post_types'           => $posted_types,
			'exclude_logged_in'    => ! empty( $_POST['exclude_logged_in'] ),
			'track_outbound_links' => ! empty( $_POST['track_outbound_links'] ),
			'email_weekly_summary' => ! empty( $_POST['email_weekly_summary'] ),
			'email_period_days'    => $this->normalize_email_period( isset( $_POST['email_period_days'] ) ? absint( wp_unslash( $_POST['email_period_days'] ) ) : 7 ),
			'email_recipient'      => is_email( $posted_email ) ? $posted_email : get_option( 'admin_email' ),
		);

		update_option( self::OPTION_SETTINGS, $settings, true ); // Read on every front-end request — must autoload.
		$this->settings_cache = null;
		$this->sync_weekly_email_schedule( $settings );

		if ( class_exists( 'TSOSK_Activity_Log' ) ) {
			TSOSK_Activity_Log::log(
				'view-counter',
				'settings',
				__( 'View Counter settings updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		wp_send_json_success( __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Import lifetime totals from a previously installed views plugin.
	 * Post Views Counter and WP-PostViews only ever expose a lifetime total
	 * per post (no daily breakdown), so their history lands as a single row
	 * dated today — the lifetime total shown in the admin stays correct
	 * either way, but the Top Content report's 7/30/90-day filters will only
	 * start differing from "all time" once real traffic accumulates after
	 * the import. WP Statistics does keep a per-visit timestamp locally, so
	 * that source is imported with its real daily dates preserved.
	 */
	public function ajax_import(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$rows   = array();
		$today  = current_time( 'Y-m-d' );

		global $wpdb;

		$legacy = self::LEGACY_DATE;

		if ( 'post_views_counter' === $source ) {
			// Post Views Counter 1.x keeps its data in {prefix}post_views (type 0 = day, 4 = lifetime total).
			$pvc_table = $wpdb->prefix . 'post_views';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pvc_table ) ) === $pvc_table ) {
				$day_sum = array();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$pvc_days = $wpdb->get_results( "SELECT id AS post_id, period, count FROM {$wpdb->prefix}post_views WHERE type = 0", ARRAY_A );
				foreach ( (array) $pvc_days as $pvc_row ) {
					$period = (string) ( $pvc_row['period'] ?? '' );
					if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $period, $dm ) ) {
						continue;
					}
					$pid = absint( $pvc_row['post_id'] ?? 0 );
					$cnt = absint( $pvc_row['count'] ?? 0 );
					$rows[] = array(
						'post_id'   => $pid,
						'count'     => $cnt,
						'view_date' => $dm[1] . '-' . $dm[2] . '-' . $dm[3],
					);
					$day_sum[ $pid ] = ( $day_sum[ $pid ] ?? 0 ) + $cnt;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$pvc_totals = $wpdb->get_results( "SELECT id AS post_id, count FROM {$wpdb->prefix}post_views WHERE type = 4", ARRAY_A );
				foreach ( (array) $pvc_totals as $pvc_row ) {
					$pid       = absint( $pvc_row['post_id'] ?? 0 );
					$remainder = absint( $pvc_row['count'] ?? 0 ) - ( $day_sum[ $pid ] ?? 0 );
					if ( $remainder > 0 ) {
						$rows[] = array(
							'post_id'   => $pid,
							'count'     => $remainder,
							'view_date' => $legacy,
						);
					}
				}
			}
			if ( empty( $rows ) ) {
				// Older versions stored totals in postmeta 'post_views_count'.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_rows = $wpdb->get_results(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'post_views_count'",
					ARRAY_A
				);
				foreach ( (array) $meta_rows as $meta_row ) {
					$rows[] = array(
						'post_id'   => $meta_row['post_id'] ?? 0,
						'count'     => $meta_row['meta_value'] ?? 0,
						'view_date' => $legacy,
					);
				}
			}
		} elseif ( 'wp_postviews' === $source ) {
			// The old WP-PostViews plugin used postmeta 'views'.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_rows = $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'views'",
				ARRAY_A
			);
			foreach ( (array) $meta_rows as $meta_row ) {
				$rows[] = array(
					'post_id'   => $meta_row['post_id'] ?? 0,
					'count'     => $meta_row['meta_value'] ?? 0,
					'view_date' => $legacy,
				);
			}
		} elseif ( 'wp_statistics' === $source ) {
			$pages_table = $wpdb->prefix . 'statistics_pages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pages_table ) ) === $pages_table ) {
				// statistics_pages holds one row per page and day (uri, date, count, page_id = post ID).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$has_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}statistics_pages" );
				if ( is_array( $has_cols ) && in_array( 'date', $has_cols, true ) && in_array( 'count', $has_cols, true ) ) {
					// Two literal queries (no interpolated column name) depending on whether page_id exists.
					if ( in_array( 'page_id', $has_cols, true ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$stats_rows = $wpdb->get_results( "SELECT page_id AS post_id, uri, date, count FROM {$wpdb->prefix}statistics_pages", ARRAY_A );
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$stats_rows = $wpdb->get_results( "SELECT 0 AS post_id, uri, date, count FROM {$wpdb->prefix}statistics_pages", ARRAY_A );
					}
					$uri_cache  = array();
					foreach ( (array) $stats_rows as $stats_row ) {
						$post_id = absint( $stats_row['post_id'] ?? 0 );
						if ( $post_id <= 0 || ! get_post_type( $post_id ) ) {
							$uri = (string) ( $stats_row['uri'] ?? '' );
							if ( ! isset( $uri_cache[ $uri ] ) ) {
								$uri_cache[ $uri ] = '' !== $uri ? (int) url_to_postid( home_url( $uri ) ) : 0;
							}
							$post_id = $uri_cache[ $uri ];
						}
						if ( $post_id <= 0 ) {
							continue;
						}
						$rows[] = array(
							'post_id'   => $post_id,
							'count'     => $stats_row['count'] ?? 0,
							'view_date' => substr( (string) ( $stats_row['date'] ?? '' ), 0, 10 ),
						);
					}
				}
			}
		} else {
			wp_send_json_error( __( 'Unknown import source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$imported_posts = array();
		$views_dated    = 0;
		$views_legacy   = 0;
		$date_min       = '';
		$date_max       = '';
		foreach ( (array) $rows as $row ) {
			$post_id = absint( $row['post_id'] ?? 0 );
			$count   = absint( $row['count'] ?? 0 );
			if ( $post_id <= 0 || $count <= 0 ) {
				continue;
			}
			$type = get_post_type( $post_id );
			if ( ! in_array( $type, array( 'post', 'page' ), true ) ) {
				continue;
			}
			$view_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $row['view_date'] ?? '' ) ) ? $row['view_date'] : $today;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}tsosk_views (object_type, object_id, view_date, count) VALUES (%s, %d, %s, %d)
					 ON DUPLICATE KEY UPDATE count = count + VALUES(count)",
					$type,
					$post_id,
					$view_date,
					$count
				)
			);
			$imported_posts[ $post_id ] = true;
			if ( $view_date === self::LEGACY_DATE ) {
				$views_legacy += $count;
			} else {
				$views_dated += $count;
				$date_min     = ( '' === $date_min || $view_date < $date_min ) ? $view_date : $date_min;
				$date_max     = ( '' === $date_max || $view_date > $date_max ) ? $view_date : $date_max;
			}
		}
		$imported = count( $imported_posts );

		if ( class_exists( 'TSOSK_Activity_Log' ) ) {
			TSOSK_Activity_Log::log(
				'view-counter',
				'import',
				sprintf(
					/* translators: 1: import source, 2: number of imported posts */
					__( 'View Counter: imported %2$d post(s) from %1$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$source,
					$imported
				)
			);
		}

		$source_labels = array(
			'post_views_counter' => 'Post Views Counter',
			'wp_postviews'       => 'WP-PostViews',
			'wp_statistics'      => 'WP Statistics',
		);
		$source_label  = $source_labels[ $source ] ?? $source;

		if ( 0 === $imported ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: import source name */
					__( 'Nothing imported: no view data from %s was found (or it does not match any post/page on this site).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$source_label
				)
			);
		}

		$message = sprintf(
			/* translators: 1: import source, 2: posts/pages count, 3: total views imported, 4: views with real dates, 5: views without known date */
			__( 'Imported from %1$s: %2$d posts/pages, %3$s views in total (%4$s with real daily dates, %5$s as previous history counted only in "All time").', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$source_label,
			$imported,
			number_format_i18n( $views_dated + $views_legacy ),
			number_format_i18n( $views_dated ),
			number_format_i18n( $views_legacy )
		);
		if ( '' !== $date_min ) {
			$message .= ' ' . sprintf(
				/* translators: 1: first date, 2: last date */
				__( 'Daily data covers %1$s to %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$date_min,
				$date_max
			);
		}
		$message .= ' ' . sprintf(
			/* translators: %s: date/time of the import */
			__( 'Imported on %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);

		update_option( self::OPTION_LAST_IMPORT, $message, false );

		wp_send_json_success( $message );
	}

	/**
	 * Wipe all recorded view/click data (settings are kept).
	 */
	public function ajax_reset(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tsosk_views" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}tsosk_view_links" );

		if ( class_exists( 'TSOSK_Activity_Log' ) ) {
			TSOSK_Activity_Log::log(
				'view-counter',
				'reset',
				__( 'View Counter data reset.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		wp_send_json_success( __( 'All view data has been reset.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	// ── Top content report ───────────────────────────────────────────────────

	/**
	 * Normalize the requested reporting period to a day count (0 = all-time).
	 *
	 * @param string $raw Raw period key from the request.
	 * @return int
	 */
	private function normalize_period( string $raw ): int {
		$allowed = array( '7', '30', '90', 'total' );
		if ( ! in_array( $raw, $allowed, true ) ) {
			$raw = '30';
		}
		return 'total' === $raw ? 0 : (int) $raw;
	}

	/**
	 * AJAX: return the top-10 posts/pages and top-10 outbound links for the
	 * requested period, for the "Top Content" report on the settings tab.
	 */
	public function ajax_top_content(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$days = $this->normalize_period( isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '30' );

		wp_send_json_success(
			array(
				'posts' => $this->get_top_posts( $days ),
				'links' => $this->get_top_links( $days ),
			)
		);
	}

	// ── CSV export ────────────────────────────────────────────────────────────

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
	 * Stream the recorded daily views/clicks as a CSV download.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT v.object_type, v.object_id, v.view_date, v.count, l.url
			 FROM {$wpdb->prefix}tsosk_views v
			 LEFT JOIN {$wpdb->prefix}tsosk_view_links l ON l.id = v.object_id AND v.object_type = 'link'
			 ORDER BY v.view_date DESC, v.object_type, v.object_id",
			ARRAY_A
		);

		// A large export can take a while to stream on slow hosts; don't let
		// the default execution-time limit cut the file off mid-stream.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 0 );
		}
		// Discard any buffering (gzip, output-buffering plugins/hosts) that
		// could otherwise truncate or corrupt a large streamed download.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$stamp = gmdate( 'Y-m-d-His' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-view-counter-' . $stamp . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output stream for download.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open export stream.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM for Excel.
		fprintf( $out, "sep=,\r\n" ); // Tells Excel (any locale) that the delimiter is a comma, otherwise es/ca locales put everything in column A.
		fputcsv( $out, array( 'date', 'type', 'object', 'count' ) );

		$row_num = 0;
		foreach ( (array) $rows as $row ) {
			$type = (string) ( $row['object_type'] ?? '' );
			if ( 'link' === $type ) {
				$object = trim( (string) preg_replace( '/\s+/u', '', (string) ( $row['url'] ?? '' ) ) );
			} else {
				$title  = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( get_the_title( absint( $row['object_id'] ?? 0 ) ) ), ENT_QUOTES, 'UTF-8' ) ) );
				$object = ( '' !== $title ? $title : '#' . absint( $row['object_id'] ?? 0 ) );
			}
			fputcsv(
				$out,
				array(
					$this->csv_safe_cell( (string) ( $row['view_date'] ?? '' ) ),
					$this->csv_safe_cell( $type ),
					$this->csv_safe_cell( $object ),
					absint( $row['count'] ?? 0 ),
				)
			);
			++$row_num;
			if ( 0 === $row_num % 500 ) {
				flush();
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ── Weekly summary email ──────────────────────────────────────────────────

	/**
	 * Send the optional weekly summary email (last 7 days: totals + top 5).
	 * Runs from WP-Cron; silently does nothing if the setting is off (in case
	 * a stray scheduled event ever survives a settings change).
	 */
	public function send_weekly_email(): void {
		$settings = $this->get_settings();
		if ( empty( $settings['email_weekly_summary'] ) ) {
			return;
		}

		$to = ! empty( $settings['email_recipient'] ) ? $settings['email_recipient'] : get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$period       = $this->normalize_email_period( (int) ( $settings['email_period_days'] ?? 7 ) );
		$total_views  = 0;
		$total_clicks = 0;
		foreach ( $this->get_top_posts( $period, 1000 ) as $row ) {
			$total_views += $row['total'];
		}
		foreach ( $this->get_top_links( $period, 1000 ) as $row ) {
			$total_clicks += $row['total'];
		}

		$top_posts = $this->get_top_posts( $period, 5 );
		$top_links = $this->get_top_links( $period, 5 );

		$lines = array();
		/* translators: %s: site name */
		$lines[] = sprintf( __( 'View Counter summary for %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$lines[] = '';
		/* translators: 1: number of days, 2: total views */
		$lines[] = sprintf( __( 'Total post/page views (last %1$d days): %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $period, number_format_i18n( $total_views ) );
		/* translators: 1: number of days, 2: total outbound link clicks */
		$lines[] = sprintf( __( 'Total outbound link clicks (last %1$d days): %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $period, number_format_i18n( $total_clicks ) );
		$lines[] = '';

		if ( ! empty( $top_posts ) ) {
			$lines[] = __( 'Top posts/pages:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			foreach ( $top_posts as $row ) {
				$lines[] = '- ' . $row['title'] . ': ' . number_format_i18n( $row['total'] );
			}
			$lines[] = '';
		}

		if ( ! empty( $top_links ) ) {
			$lines[] = __( 'Top outbound links:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			foreach ( $top_links as $row ) {
				$lines[] = '- ' . $row['url'] . ': ' . number_format_i18n( $row['total'] );
			}
		}

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] View Counter summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	// ── Render (settings tab) ─────────────────────────────────────────────────

	/**
	 * Render the settings tab.
	 */
	public function render(): void {
		self::maybe_upgrade_schema();
		$tsosk_vc_cleanup_errors = self::maybe_migrate_legacy_wrapped_tables();
		if ( empty( $tsosk_vc_cleanup_errors ) ) {
			$tsosk_vc_cleanup_errors = get_option( 'tsosk_vc_legacy_cleanup_errors', array() );
		}
		if ( ! empty( $tsosk_vc_cleanup_errors ) && is_array( $tsosk_vc_cleanup_errors ) ) {
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'TSO Swiss Knife — could not remove the following leftover legacy table(s):', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			echo '</strong></p><ul style="list-style:disc;margin-left:20px;">';
			foreach ( $tsosk_vc_cleanup_errors as $tsosk_vc_bad_table => $tsosk_vc_bad_table_error ) {
				echo '<li><code>' . esc_html( (string) $tsosk_vc_bad_table ) . '</code>: ' . esc_html( (string) $tsosk_vc_bad_table_error ) . '</li>';
			}
			echo '</ul></div>';
		}

		$settings       = $this->get_settings();
		$nonce          = wp_create_nonce( self::NONCE_ACTION );
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );

		global $wpdb;
		$total_views  = (int) $wpdb->get_var( "SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type IN ('post','page')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_clicks = (int) $wpdb->get_var( "SELECT SUM(count) FROM {$wpdb->prefix}tsosk_views WHERE object_type = 'link'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Counts post, page and outbound-link-click visits with a small front-end beacon so counting keeps working even behind a full-page cache. No cookies are used — de-duplication happens with a short-lived, server-side fingerprint only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-stats-row">
			<div class="tsosk-stat-box">
				<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( $total_views ) ); ?></span>
				<span class="tsosk-stat-label"><?php esc_html_e( 'Total post/page views', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</div>
			<div class="tsosk-stat-box">
				<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( $total_clicks ) ); ?></span>
				<span class="tsosk-stat-label"><?php esc_html_e( 'Total outbound link clicks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</div>
		</div>

		<p class="description">
			<?php esc_html_e( 'Only posts and pages are counted. Media files (images, PDFs, downloads) and other attachments are not tracked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<h2><?php esc_html_e( 'Top Content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p>
			<span class="tsosk-filter-pills" role="group" aria-label="<?php esc_attr_e( 'Reporting period', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<button type="button" class="button button-small tsosk-vc-period" data-period="7"><?php esc_html_e( 'Last 7 days', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button button-small tsosk-vc-period tsosk-filter-active" data-period="30"><?php esc_html_e( 'Last 30 days', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button button-small tsosk-vc-period" data-period="90"><?php esc_html_e( 'Last 90 days', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button button-small tsosk-vc-period" data-period="total"><?php esc_html_e( 'All time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			</span>
		</p>
		<div class="tsosk-vc-top-wrap">
			<div class="tsosk-vc-col">
				<h3><?php esc_html_e( 'Most viewed posts/pages', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<table class="widefat striped" id="tsosk-vc-top-posts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th class="tsosk-vc-num-col"><?php esc_html_e( 'Views', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
			<div class="tsosk-vc-col">
				<h3><?php esc_html_e( 'Most clicked outbound links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<table class="widefat striped" id="tsosk-vc-top-links-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th class="tsosk-vc-num-col"><?php esc_html_e( 'Clicks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>

		<h2><?php esc_html_e( 'Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Track', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<td>
					<?php foreach ( $all_post_types as $pt ) : ?>
						<label style="margin-right:14px;">
							<input type="checkbox" class="tsosk-vc-post-type"
									value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, $settings['post_types'], true ) ); ?>>
							<?php echo esc_html( $pt->labels->name ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logged-in users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="tsosk-vc-exclude-logged-in" <?php checked( ! empty( $settings['exclude_logged_in'] ) ); ?>>
						<?php esc_html_e( "Don't count views from logged-in users", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Outbound links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="tsosk-vc-track-links" <?php checked( ! empty( $settings['track_outbound_links'] ) ); ?>>
						<?php esc_html_e( 'Count clicks on external links inside post/page content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Weekly summary email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="tsosk-vc-email-weekly" <?php checked( ! empty( $settings['email_weekly_summary'] ) ); ?>>
						<?php esc_html_e( 'Email a weekly summary (totals + top 5 posts and links) covering the last', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</label>
					<select id="tsosk-vc-email-period">
						<?php foreach ( array( 7, 15, 30 ) as $tsosk_vc_days ) : ?>
							<option value="<?php echo esc_attr( (string) $tsosk_vc_days ); ?>" <?php selected( (int) ( $settings['email_period_days'] ?? 7 ), $tsosk_vc_days ); ?>>
								<?php
								/* translators: %d: number of days */
								echo esc_html( sprintf( _n( '%d day', '%d days', $tsosk_vc_days, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $tsosk_vc_days ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<br>
					<input type="email" id="tsosk-vc-email-recipient" style="margin-top:6px; width:280px;"
							value="<?php echo esc_attr( (string) $settings['email_recipient'] ); ?>"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				</td>
			</tr>
		</table>
		<p>
			<button class="button button-primary" id="tsosk-vc-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-vc-settings-msg"></span>
		</p>

		<h2><?php esc_html_e( 'Import previous counters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Recover totals already collected by another plugin. Totals are added to the counter (Post Views Counter and WP Statistics keep their real daily dates; totals without a known date count for "All time" only, not for the 7/30/90-day filters). Running an import again adds on top, so only import a given source once.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<select id="tsosk-vc-import-source">
				<option value="post_views_counter"><?php esc_html_e( 'Post Views Counter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				<option value="wp_postviews"><?php esc_html_e( 'WP-PostViews', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				<option value="wp_statistics"><?php esc_html_e( 'WP Statistics', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
			</select>
			<button class="button button-secondary" id="tsosk-vc-import" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Import Now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-vc-import-msg"></span>
		</p>
		<?php $tsosk_vc_last_import = get_option( self::OPTION_LAST_IMPORT, '' ); ?>
		<p class="tsosk-vc-import-result" id="tsosk-vc-import-result"<?php echo '' === $tsosk_vc_last_import ? ' hidden' : ''; ?>><?php echo esc_html( is_string( $tsosk_vc_last_import ) ? $tsosk_vc_last_import : '' ); ?></p>

		<h2><?php esc_html_e( 'Export', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION ) ); ?>">
				<?php esc_html_e( 'Download CSV (daily detail)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<h2><?php esc_html_e( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p>
			<button class="button button-link-delete" id="tsosk-vc-reset" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Delete all recorded views and clicks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-vc-reset-msg"></span>
		</p>
		<?php
	}
}
