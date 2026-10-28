<?php
/**
 * TSO Swiss Knife – Module: Search & Replace in Database.
 *
 * Performs safe search-and-replace operations across WordPress database tables.
 *
 * Key safety features:
 *  – Dry-run preview: shows exactly what would change before committing.
 *  – Serialized PHP aware: after replacing, recalculates string lengths in
 *    serialized data so it remains valid (handles nested objects/arrays).
 *  – Table + column whitelist: only operates on TEXT/BLOB columns; skips
 *    primary keys and numeric columns.
 *  – Row-level diff: highlights changed fragments in the preview.
 *  – Operations logged in the central Activity History tab.
 *  – Regex support (optional, only for advanced users).
 *  – Backup reminder cannot be dismissed — always shown.
 *
 * @package TSO_Swiss_Knife
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Search_Replace
 */
class TSOSK_Mod_Search_Replace {

	/** Max rows previewed per table in dry-run. */
	private const PREVIEW_LIMIT = 50;

	/** Non-autoloaded option holding a full snapshot of the last Execute run. */
	private const BACKUP_OPTION = 'tsosk_sr_last_backup';

	/** Max number of changed cells kept in the automatic backup snapshot. */
	private const BACKUP_CELL_CAP = 5000;

	/**
	 * Cells that matched but were left untouched (PHP objects / unreadable serialized data) in this request.
	 *
	 * @var int
	 */
	private $skipped_cells = 0;

	/**
	 * Table and primary key of the row being processed, so a skipped cell can be reported.
	 *
	 * @var array{0:string,1:mixed}|null
	 */
	private $current_cell = null;

	/**
	 * Table + primary key of cells left untouched in this request (first few only).
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private $skipped_where = array();

	/**
	 * Tables whose preview was cut short (row cap / scan cap reached), keyed by table name.
	 *
	 * @var array<string, bool>
	 */
	private $truncated_tables = array();

	/**
	 * Cached option_id values of this plugin's own bookkeeping rows (backup, logs).
	 *
	 * @var int[]|null
	 */
	private $protected_row_ids = null;

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Search_Replace|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
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
	 * Construct.
	 */
	private function __construct() {
		add_action( 'wp_ajax_tsosk_sr_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_tsosk_sr_execute', array( $this, 'ajax_execute' ) );
		add_action( 'wp_ajax_tsosk_sr_restore_backup', array( $this, 'ajax_restore_backup' ) );
		add_action( 'admin_post_tsosk_sr_download_backup', array( $this, 'download_backup' ) );
	}

	// ── Table / column discovery ──────────────────────────────────────────────

	/**
	 * Quote a SQL identifier after strict validation (letters, digits, underscore).
	 *
	 * @param string $name Table or column name.
	 * @return string Backtick-quoted identifier, or empty when invalid.
	 */
	private function sql_ident( string $name ): string {
		// Allow leading digits (some $wpdb->prefix values start with numbers).
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			return '';
		}
		return '`' . $name . '`';
	}

	/**
	 * Quote a list of SQL identifiers.
	 *
	 * @param string[] $names Column names.
	 * @return string Comma-separated quoted list, or empty when any name is invalid.
	 */
	private function sql_ident_list( array $names ): string {
		$parts = array();
		foreach ( $names as $name ) {
			$quoted = $this->sql_ident( (string) $name );
			if ( '' === $quoted ) {
				return '';
			}
			$parts[] = $quoted;
		}
		return implode( ', ', $parts );
	}

	/**
	 * List all database tables in the current DB.
	 *
	 * @return array<int,array{name:string,rows:int,size:string,is_wp:bool}>
	 */
	private function get_all_tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS table_rows,
				        (DATA_LENGTH + INDEX_LENGTH) AS total_bytes
				   FROM information_schema.TABLES
				  WHERE TABLE_SCHEMA = %s
				  ORDER BY TABLE_NAME ASC',
				DB_NAME
			),
			ARRAY_A
		);

		$tables = array();
		foreach ( (array) $results as $row ) {
			// Some drivers return the column aliases in upper case (or the raw column names).
			$row  = array_change_key_case( (array) $row, CASE_LOWER );
			$name = (string) ( $row['name'] ?? $row['table_name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$bytes    = (int) ( $row['total_bytes'] ?? 0 );
			$size_str = $bytes > 1048576
				? round( $bytes / 1048576, 1 ) . ' MB'
				: ( $bytes > 1024 ? round( $bytes / 1024, 1 ) . ' KB' : $bytes . ' B' );

			$tables[] = array(
				'name'  => $name,
				'rows'  => (int) ( $row['table_rows'] ?? 0 ),
				'size'  => $size_str,
				'is_wp' => ( 0 === strpos( $name, $wpdb->prefix ) ),
			);
		}
		return $tables;
	}

	/**
	 * Get TEXT/BLOB columns for a table (safe to search/replace).
	 *
	 * Returns an array of column names that can hold string data.
	 * Skips INT/BIGINT/FLOAT/TIMESTAMP/DATE columns.
	 *
	 * @param string $table Validated table name.
	 * @return string[]
	 */
	private function get_text_columns( string $table ): array {
		global $wpdb;

		if ( '' === $this->sql_ident( $table ) || ! $this->table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME AS Field, DATA_TYPE AS Type
				   FROM information_schema.COLUMNS
				  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			),
			ARRAY_A
		);
		if ( ! is_array( $cols ) ) {
			return array();
		}
		$text_types = array( 'text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char', 'blob', 'tinyblob', 'mediumblob', 'longblob' );
		$result     = array();
		foreach ( $cols as $col ) {
			$col       = array_change_key_case( (array) $col, CASE_LOWER );
			$base_type = strtolower( (string) ( $col['type'] ?? $col['data_type'] ?? '' ) );
			if ( in_array( $base_type, $text_types, true ) ) {
				$field = (string) ( $col['field'] ?? $col['column_name'] ?? '' );
				if ( '' !== $this->sql_ident( $field ) ) {
					$result[] = $field;
				}
			}
		}
		return $result;
	}

	/**
	 * Validate that a table name exists in the current DB.
	 *
	 * @param string $table Table name to validate.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		if ( 0 !== strpos( $table, $wpdb->prefix ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
		return $count > 0;
	}

	// ── Serialized-safe replace ───────────────────────────────────────────────

	/**
	 * Replace a string inside a value that may contain serialized PHP data.
	 *
	 * Uses a regex-based approach that:
	 *  1. Detects serialized strings via the `s:N:"..."` pattern.
	 *  2. Performs the text replacement inside each string segment.
	 *  3. Recalculates the byte length of each string after replacement.
	 *
	 * Non-serialized values fall through to a plain str_replace / preg_replace.
	 *
	 * @param string $search      Search term (plain text or regex pattern).
	 * @param string $replace_str Replacement string.
	 * @param string $subject     The raw DB cell value.
	 * @param bool   $case_sensitive        True = case-sensitive.
	 * @param bool   $is_regex    True = treat $search as a regex pattern.
	 * @return string Modified value.
	 */
	private function safe_replace( string $search, string $replace_str, string $subject, bool $case_sensitive, bool $is_regex ): string {
		if ( '' === $search || '' === $subject ) {
			return $subject;
		}

		// Fast path: not serialized — plain replace.
		if ( ! $this->looks_serialized( $subject ) ) {
			return $this->do_replace( $search, $replace_str, $subject, $case_sensitive, $is_regex );
		}

		$data = @unserialize( $subject, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $data || 'b:0;' === $subject ) {
			// allowed_classes => false already turned any serialized PHP object
			// into a __PHP_Incomplete_Class stand-in at unserialize time. Its
			// original class identity/property mangling is gone the moment that
			// happened — re-serializing it would write back a corrupted blob,
			// not a preserved one, even if we never touch its properties. Treat
			// it exactly like an opaque blob we can't safely round-trip.
			if ( $this->contains_incomplete_class( $data ) ) {
				$this->note_skipped_cell();
				return $subject;
			}
			$replaced = $this->replace_in_data( $data, $search, $replace_str, $case_sensitive, $is_regex );
			return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		// Do not rewrite opaque serialized blobs with string heuristics — lengths break easily.
		$this->note_skipped_cell();
		return $subject;
	}

	/**
	 * Count a cell left unchanged and remember where it is.
	 */
	private function note_skipped_cell(): void {
		++$this->skipped_cells;
		if ( null !== $this->current_cell && count( $this->skipped_where ) < 10 && ! in_array( $this->current_cell, $this->skipped_where, true ) ) {
			$this->skipped_where[] = $this->current_cell;
		}
	}

	/**
	 * Readable places of the cells left unchanged (option name for wp_options, table #id otherwise).
	 *
	 * @return string[]
	 */
	private function skipped_locations(): array {
		global $wpdb;
		$out = array();
		foreach ( $this->skipped_where as $where ) {
			$label = $where[0] . ' #' . $where[1];
			if ( $where[0] === $wpdb->options ) {
				$name = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", (int) $where[1] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name; at most ten lookups.
				if ( is_string( $name ) && '' !== $name ) {
					$label = $where[0] . ' → ' . $name;
				}
			}
			$out[] = $label;
		}
		return $out;
	}

	/**
	 * Whether a decoded serialized value contains a __PHP_Incomplete_Class
	 * anywhere in its structure (i.e. it held a serialized PHP object before
	 * being unserialized with allowed_classes => false).
	 *
	 * @param mixed $data Decoded value.
	 * @return bool
	 */
	private function contains_incomplete_class( $data ): bool {
		if ( $data instanceof __PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $value ) {
				if ( $this->contains_incomplete_class( $value ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $value ) {
				if ( $this->contains_incomplete_class( $value ) ) {
					return true;
				}
			}
			return false;
		}
		return false;
	}

	/**
	 * Recursively replace strings inside serialized PHP data structures.
	 *
	 * @param mixed  $data        Decoded value.
	 * @param string $search      Search term.
	 * @param string $replace_str Replacement.
	 * @param bool   $case_sensitive        Case-sensitive.
	 * @param bool   $is_regex    Regex mode.
	 * @return mixed
	 */
	private function replace_in_data( $data, string $search, string $replace_str, bool $case_sensitive, bool $is_regex ) {
		if ( is_string( $data ) ) {
			// A string that is itself serialized data (common in plugin options): go through the same safe path, or its inner lengths break.
			if ( $this->looks_serialized( $data ) ) {
				$inner = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ( false !== $inner || 'b:0;' === $data ) && ! $this->contains_incomplete_class( $inner ) ) {
					return serialize( $this->replace_in_data( $inner, $search, $replace_str, $case_sensitive, $is_regex ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
			}
			return $this->do_replace( $search, $replace_str, $data, $case_sensitive, $is_regex );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_in_data( $value, $search, $replace_str, $case_sensitive, $is_regex );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = $this->replace_in_data( $value, $search, $replace_str, $case_sensitive, $is_regex );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Perform the actual string substitution (case/regex aware).
	 *
	 * @param string $search  Search string or regex.
	 * @param string $replace Replacement string.
	 * @param string $subject Target string.
	 * @param bool   $case_sensitive    True = case-sensitive.
	 * @param bool   $is_regex True = $search is a regex.
	 * @return string
	 */
	private function do_replace( string $search, string $replace, string $subject, bool $case_sensitive, bool $is_regex ): string {
		if ( $is_regex ) {
			$pattern = $this->build_regex_pattern( $search, $case_sensitive );
			if ( '' === $pattern ) {
				return $subject;
			}
			// Treat replacement as literal text (no $n / \n backreferences).
			$literal  = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $replace );
			$replaced = preg_replace( $pattern, $literal, $subject );
			if ( null === $replaced || PREG_NO_ERROR !== preg_last_error() ) {
				return $subject;
			}
			return $replaced;
		}
		$variant = $this->json_variant( $search );
		if ( $case_sensitive ) {
			$subject = str_replace( $search, $replace, $subject );
			if ( '' !== $variant ) {
				$subject = str_replace( $variant, $this->json_variant( $replace, true ), $subject );
			}
			return $subject;
		}
		$subject = str_ireplace( $search, $replace, $subject );
		if ( '' !== $variant ) {
			$subject = str_ireplace( $variant, $this->json_variant( $replace, true ), $subject );
		}
		return $subject;
	}

	/**
	 * JSON-escaped form of a plain search/replace text ("/" stored as "\/"), as found in
	 * Elementor, block and other JSON data. Empty when the text has no slash.
	 *
	 * @param string $text  Plain text.
	 * @param bool   $force Return the escaped text even when identical (for the replacement side).
	 * @return string
	 */
	private function json_variant( string $text, bool $force = false ): string {
		if ( false === strpos( $text, '/' ) ) {
			return $force ? $text : '';
		}
		return str_replace( '/', '\\/', $text );
	}

	/**
	 * Build a delimited regex pattern, or empty string when unsafe/invalid.
	 *
	 * @param string $search Pattern without delimiters.
	 * @param bool   $case_sensitive   Case-sensitive when true.
	 * @return string
	 */
	private function build_regex_pattern( string $search, bool $case_sensitive ): string {
		if ( ! $this->is_safe_regex_pattern( $search ) ) {
			return '';
		}
		$flags = $case_sensitive ? '' : 'i';
		return '#' . preg_replace( '/(?<!\\\\)#/', '\\#', $search ) . '#' . $flags . 'u';
	}

	/**
	 * Whether a user regex is compilable and not an obvious ReDoS pattern.
	 *
	 * @param string $source Pattern without delimiters.
	 * @return bool
	 */
	private function is_safe_regex_pattern( string $source ): bool {
		$source = (string) $source;
		if ( '' === $source || strlen( $source ) > 500 ) {
			return false;
		}
		if ( preg_match( '/\([^()]*[+*][^()]*\)[+*]/', $source ) ) {
			return false;
		}
		$pattern = '#' . preg_replace( '/(?<!\\\\)#/', '\\#', $source ) . '#u';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile-check only.
		return false !== @preg_match( $pattern, '' );
	}

	/**
	 * Quick heuristic to detect serialized PHP strings.
	 *
	 * @param string $v Value to check.
	 * @return bool
	 */
	private function looks_serialized( string $v ): bool {
		if ( '' === $v ) {
			return false;
		}
		return (bool) preg_match( '/^(a:\d+:|s:\d+:"|i:\d+;|b:[01];|O:\d+:|C:\d+:|N;|d:)/s', $v );
	}

	/**
	 * Check if a value contains the search term.
	 *
	 * @param string $search  Search term.
	 * @param string $subject Value to search.
	 * @param bool   $case_sensitive    True = case-sensitive.
	 * @param bool   $is_regex True = regex.
	 * @return bool
	 */
	private function value_contains( string $search, string $subject, bool $case_sensitive, bool $is_regex ): bool {
		if ( $is_regex ) {
			$pattern = $this->build_regex_pattern( $search, $case_sensitive );
			if ( '' === $pattern ) {
				return false;
			}
			return 1 === preg_match( $pattern, $subject );
		}
		$variant = $this->json_variant( $search );
		if ( $case_sensitive ) {
			return false !== strpos( $subject, $search ) || ( '' !== $variant && false !== strpos( $subject, $variant ) );
		}
		return false !== stripos( $subject, $search ) || ( '' !== $variant && false !== stripos( $subject, $variant ) );
	}

	/**
	 * Build the "(col LIKE %s OR ...)" clause for a plain-text search, including the JSON-escaped variant.
	 *
	 * @param string[] $cols   Text columns.
	 * @param string   $search Plain search text.
	 * @param array    $args   prepare() arguments, appended to.
	 * @return string Clause, or empty string when a column name is invalid.
	 */
	private function build_like_clause( array $cols, string $search, array &$args ): string {
		global $wpdb;
		$terms   = array( '%' . $wpdb->esc_like( $search ) . '%' );
		$variant = $this->json_variant( $search );
		if ( '' !== $variant ) {
			$terms[] = '%' . $wpdb->esc_like( $variant ) . '%';
		}
		$parts = array();
		foreach ( $cols as $col ) {
			$col_sql = $this->sql_ident( (string) $col );
			if ( '' === $col_sql ) {
				return '';
			}
			foreach ( $terms as $term ) {
				$parts[] = $col_sql . ' LIKE %s';
				$args[]  = $term;
			}
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/** AJAX: dry-run preview — returns matches without modifying the DB. */
	public function ajax_preview(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$params = $this->parse_request_params();
		if ( is_wp_error( $params ) ) {
			wp_send_json_error( $params->get_error_message() );
		}

		global $wpdb;
		$results        = array();
		$total_rows     = 0;
		$skipped_tables = array();

		foreach ( $params['tables'] as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$cols = $this->get_text_columns( $table );
			$pk   = $this->get_primary_key( $table );
			if ( empty( $cols ) ) {
				continue;
			}
			if ( '' === $pk ) {
				// Execute skips these tables too; tell the user now instead of silently ignoring them.
				$skipped_tables[] = $table;
				continue;
			}
			$matches = $this->find_matches( $table, $cols, $pk, $params, true );
			if ( ! empty( $matches ) ) {
				$results[ $table ] = $matches;
				$total_rows       += count( $matches );
			}
		}

		wp_send_json_success(
			array(
				'results'        => $results,
				'total_rows'     => $total_rows,
				'tables'         => array_keys( $results ),
				'approval'       => $this->store_preview_approval( $params ),
				'truncated'      => array_keys( $this->truncated_tables ),
				'skipped_tables' => $skipped_tables,
				'skipped_cells'  => $this->skipped_cells,
				'skipped_where'  => $this->skipped_locations(),
				'has_backup'     => is_array( get_option( self::BACKUP_OPTION ) ),
				'content_tables' => $this->get_content_tables(),
			)
		);
	}

	/**
	 * Preview snippet centred on the first match, so the text shown is the part that actually changes
	 * (a long cell used to be cut at its start, which hid the match).
	 *
	 * @param string $orig     Original cell value.
	 * @param string $after    Value after the replacement.
	 * @param bool   $before   True to return the original side, false for the replaced side.
	 * @param array  $params   Request params (search, case_sensitive, is_regex).
	 */
	private function preview_excerpt( string $orig, string $after, bool $before, array $params ): string {
		$search = (string) $params['search'];
		$pos    = false;
		if ( ! empty( $params['is_regex'] ) ) {
			$pattern = $this->build_regex_pattern( $search, (bool) $params['case_sensitive'] );
			if ( '' !== $pattern && 1 === preg_match( $pattern, $orig, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos = (int) $m[0][1];
			}
		} else {
			$needles = array_filter( array( $search, $this->json_variant( $search ) ), 'strlen' );
			foreach ( $needles as $needle ) {
				$found = $params['case_sensitive'] ? strpos( $orig, $needle ) : stripos( $orig, $needle );
				if ( false !== $found && ( false === $pos || $found < $pos ) ) {
					$pos = $found;
				}
			}
		}
		if ( false === $pos ) {
			// Fallback: first byte that differs.
			$n   = min( strlen( $orig ), strlen( $after ) );
			$pos = 0;
			while ( $pos < $n && $orig[ $pos ] === $after[ $pos ] ) {
				++$pos;
			}
		}
		$start = max( 0, mb_strlen( mb_strcut( $orig, 0, $pos ) ) - 60 );
		$out   = mb_substr( $before ? $orig : $after, $start, 300 );
		return ( $start > 0 ? '…' : '' ) . $out;
	}

	/**
	 * True for the rows this plugin keeps for its own bookkeeping: the automatic backup of this tool,
	 * the activity log (audit trail), the slow-query log and the history tables of TSO plugins. They are
	 * never searched or rewritten: the backup holds the values needed to restore and the logs only record
	 * what happened.
	 *
	 * @param string $table  Table name.
	 * @param mixed  $pk_val Row primary key value.
	 */
	private function is_own_backup_row( string $table, $pk_val ): bool {
		global $wpdb;
		if ( $this->is_history_table( $table ) ) {
			return true;
		}
		if ( $table !== $wpdb->options ) {
			return false;
		}
		if ( null === $this->protected_row_ids ) {
			$names                   = array( self::BACKUP_OPTION, 'tsosk_activity_log', 'tsosk_slow_query_log' );
			$ids                     = $wpdb->get_col( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s)", $names[0], $names[1], $names[2] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table name; one cheap lookup per request.
			$this->protected_row_ids = array_map( 'intval', (array) $ids );
		}
		return in_array( (int) $pk_val, $this->protected_row_ids, true );
	}

	/**
	 * Columns to search in a table. The posts GUID column is left out unless the user asks for it:
	 * WordPress treats a GUID as the permanent identifier of a post and recommends never changing it.
	 *
	 * @param string   $table  Table name.
	 * @param string[] $cols   Candidate columns.
	 * @param array    $params Request params.
	 * @return string[]
	 */
	private function filter_columns( string $table, array $cols, array $params ): array {
		global $wpdb;
		if ( $table !== $wpdb->posts || ! empty( $params['include_guid'] ) ) {
			return $cols;
		}
		return array_values( array_diff( $cols, array( 'guid' ) ) );
	}

	/**
	 * True for the history or log tables of TSO plugins (for example the Link Inspector change history).
	 * They only record what was changed before (old and new values), so they are never searched or rewritten.
	 *
	 * @param string $table Table name.
	 */
	private function is_history_table( string $table ): bool {
		global $wpdb;
		$base = 0 === strpos( $table, $wpdb->prefix ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;
		return (bool) preg_match( '/^tso_[a-z0-9_]*_(?:history|log|logs)$/', $base );
	}

	/**
	 * Core tables that hold visible site content (used to tell content matches from internal data).
	 *
	 * @return string[]
	 */
	private function get_content_tables(): array {
		global $wpdb;
		return array_values(
			array_filter(
				array(
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->comments,
					$wpdb->commentmeta,
					$wpdb->terms,
					$wpdb->term_taxonomy,
					$wpdb->termmeta,
					$wpdb->links,
				)
			)
		);
	}

	/** AJAX: execute the search-replace and write to DB. */
	public function ajax_execute(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$params = $this->parse_request_params();
		if ( is_wp_error( $params ) ) {
			wp_send_json_error( $params->get_error_message() );
		}

		if ( ! $this->is_preview_approved( $params ) ) {
			wp_send_json_error( __( 'Run Preview again before executing. The search settings changed or the preview expired.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// If PHP dies half way (time or memory limit, fatal error) the browser only sees an empty answer: keep the reason for the notice on this tab.
		$finished = false;
		register_shutdown_function(
			static function () use ( &$finished ): void {
				if ( $finished ) {
					return;
				}
				$last = error_get_last();
				update_option(
					'tsosk_sr_last_failure',
					array(
						'time'    => time(),
						'message' => $last ? sprintf( '%1$s (%2$s:%3$d)', (string) $last['message'], basename( (string) $last['file'] ), (int) $last['line'] ) : '',
					),
					false
				);
			}
		);

		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large tables may need more than the default limit.
		}
		$stats            = array(
			'tables' => 0,
			'rows'   => 0,
			'cells'  => 0,
			'errors' => array(),
		);
		$backup_cells     = array();
		$backup_truncated = false;
		$backup_id        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'tsosksr', true ) );
		$url_before       = array( (string) get_option( 'siteurl' ), (string) get_option( 'home' ) );

		foreach ( $params['tables'] as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$cols = $this->get_text_columns( $table );
			$pk   = $this->get_primary_key( $table );
			if ( empty( $cols ) ) {
				continue;
			}
			if ( '' === $pk ) {
				$stats['errors'][] = sprintf(
					/* translators: %s: database table name */
					__( 'Skipped table %s: no single-column primary key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$table
				);
				continue;
			}
			$table_stats = $this->replace_in_table( $table, $cols, $pk, $params, $backup_cells, $backup_truncated );
			if ( $table_stats['rows'] > 0 ) {
				++$stats['tables'];
				$stats['rows']  += $table_stats['rows'];
				$stats['cells'] += $table_stats['cells'];
				// Persist the snapshot after every changed table so a timeout or fatal later in the run still leaves an undo point.
				$this->save_backup( $backup_id, $params, $stats, $backup_cells, $backup_truncated );
			}
			if ( ! empty( $table_stats['errors'] ) ) {
				$stats['errors'] = array_merge( $stats['errors'], $table_stats['errors'] );
			}
		}

		// Snapshot the original + new value of every changed cell (capped) so the
		// whole operation can be undone from the "Automatic backup" panel, even
		// though every match already went through the mandatory Preview step.
		$backup_created = ! empty( $backup_cells ) && $this->save_backup( $backup_id, $params, $stats, $backup_cells, $backup_truncated );
		if ( ! empty( $backup_cells ) && ! $backup_created ) {
			$stats['errors'][] = __( 'The automatic backup could not be saved (it was too large for the database), so this replace cannot be undone from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		// The rows were changed directly in the database: drop cached options/meta so the site sees the new values.
		if ( $stats['rows'] > 0 ) {
			wp_cache_flush();
		}

		$url_notice = '';
		if ( $stats['rows'] > 0 ) {
			$url_after = array( (string) get_option( 'siteurl' ), (string) get_option( 'home' ) );
			if ( $url_after !== $url_before ) {
				$url_notice        = __( 'Warning: the Site URL / Home URL stored in the database changed. If the site stops loading, restore the previous values (wp-config.php WP_HOME / WP_SITEURL) or use the backup restore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$stats['errors'][] = $url_notice;
			}
		}

		// Record in central activity log.
		$this->clear_preview_approval();
		TSOSK_Activity_Log::log(
			'search-replace',
			'execute',
			sprintf(
				/* translators: 1: search string, 2: replace string */
				__( 'Database replace: "%1$s" → "%2$s"', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$params['search'],
				$params['replace']
			),
			array(
				'search'           => $params['search'],
				'replace'          => $params['replace'],
				'tables'           => count( $params['tables'] ),
				'rows'             => $stats['rows'],
				'cells'            => $stats['cells'],
				'backup'           => $backup_created,
				'backup_truncated' => $backup_truncated,
			)
		);

		$finished = true;
		delete_option( 'tsosk_sr_last_failure' );

		wp_send_json_success(
			array(
				'tables'           => $stats['tables'],
				'rows'             => $stats['rows'],
				'cells'            => $stats['cells'],
				'errors'           => $stats['errors'],
				'skipped_cells'    => $this->skipped_cells,
				'skipped_where'    => $this->skipped_locations(),
				'backup'           => $backup_created,
				'backup_truncated' => $backup_truncated,
				'message'          => sprintf(
				/* translators: 1: tables, 2: rows, 3: cells */
					__( 'Done. Replaced in %1$d table(s), %2$d row(s), %3$d cell(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$stats['tables'],
					$stats['rows'],
					$stats['cells']
				),
			)
		);
	}

	/**
	 * Store (or refresh) the automatic backup snapshot and confirm it really persisted.
	 *
	 * @param string $backup_id  Backup ID, stable for the whole run.
	 * @param array  $params     Request params.
	 * @param array  $stats      Running stats.
	 * @param array  $cells      Changed cells (before/after).
	 * @param bool   $truncated  Whether the snapshot hit the size cap.
	 * @return bool
	 */
	private function save_backup( string $backup_id, array $params, array $stats, array $cells, bool $truncated ): bool {
		$current_user = wp_get_current_user();
		update_option(
			self::BACKUP_OPTION,
			array(
				'id'             => $backup_id,
				'time'           => time(),
				'user'           => sanitize_user( (string) $current_user->user_login, true ),
				'search'         => $params['search'],
				'replace'        => $params['replace'],
				'case_sensitive' => (bool) $params['case_sensitive'],
				'is_regex'       => (bool) $params['is_regex'],
				'rows'           => $stats['rows'],
				'cells'          => count( $cells ),
				'cells_total'    => $stats['cells'],
				'truncated'      => $truncated,
				'cells_data'     => $cells,
			),
			false
		);
		$saved = get_option( self::BACKUP_OPTION );
		return is_array( $saved ) && ( $saved['id'] ?? '' ) === $backup_id && ( (int) ( $saved['cells'] ?? 0 ) ) === count( $cells );
	}

	/**
	 * AJAX: restore the automatic backup of the last Execute run.
	 *
	 * Re-applies each cell's pre-replace value, but only where the cell still
	 * holds the value the replace wrote (guards against clobbering an edit
	 * made by someone else after the search/replace ran). The backup is
	 * consumed (deleted) once a restore has been attempted.
	 */
	public function ajax_restore_backup(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$backup = get_option( self::BACKUP_OPTION );
		if ( ! is_array( $backup ) || empty( $backup['cells_data'] ) || ! is_array( $backup['cells_data'] ) ) {
			wp_send_json_error( __( 'No backup available to restore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		$restored      = 0;
		$skipped       = 0;
		$errors        = array();
		$restored_rows = array();
		$pk_cache      = array();
		$failed_cells  = array();

		foreach ( $backup['cells_data'] as $cell ) {
			$table  = sanitize_text_field( (string) ( $cell['table'] ?? '' ) );
			$col    = sanitize_text_field( (string) ( $cell['col'] ?? '' ) );
			$pk_val = $cell['pk'] ?? null;
			$before = (string) ( $cell['before'] ?? '' );
			$after  = (string) ( $cell['after'] ?? '' );

			if ( '' === $table || '' === $col || null === $pk_val || ! $this->table_exists( $table ) ) {
				++$skipped;
				continue;
			}

			if ( ! array_key_exists( $table, $pk_cache ) ) {
				$pk_cache[ $table ] = $this->get_primary_key( $table );
			}
			$pk = $pk_cache[ $table ];

			$safe_table = $this->sql_ident( $table );
			$safe_col   = $this->sql_ident( $col );
			$safe_pk    = $this->sql_ident( $pk );
			if ( '' === $pk || '' === $safe_table || '' === $safe_col || '' === $safe_pk ) {
				++$skipped;
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident(); value uses a prepare placeholder.
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT {$safe_col} FROM {$safe_table} WHERE {$safe_pk} = %s", $pk_val ) );
			if ( null === $current || (string) $current !== $after ) {
				// Row gone, or changed by something else since the replace ran — never clobber it.
				++$skipped;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, array( $col => $before ), array( $pk => $pk_val ), array( '%s' ), array( '%s' ) );
			if ( false === $result ) {
				$errors[]       = "Table {$table} PK {$pk_val}: " . $wpdb->last_error;
				$failed_cells[] = $cell;
				continue;
			}
			++$restored;
			$restored_rows[ $table . '|' . $pk_val ] = true;
		}

		// Consumed once restored. Cells whose write failed stay in the backup so the restore can be retried.
		if ( empty( $failed_cells ) ) {
			delete_option( self::BACKUP_OPTION );
		} else {
			$backup['cells_data'] = $failed_cells;
			$backup['cells']      = count( $failed_cells );
			update_option( self::BACKUP_OPTION, $backup, false );
		}
		if ( $restored > 0 ) {
			wp_cache_flush();
		}

		TSOSK_Activity_Log::log(
			'search-replace',
			'restore',
			sprintf(
				/* translators: 1: search string, 2: replace string, 3: restored cells, 4: skipped cells */
				__( 'Backup restored: "%1$s" → "%2$s" undone (%3$d cell(s) restored, %4$d skipped).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$backup['search'] ?? '',
				$backup['replace'] ?? '',
				$restored,
				$skipped
			),
			array(
				'search'         => $backup['search'] ?? '',
				'replace'        => $backup['replace'] ?? '',
				'cells_restored' => $restored,
				'cells_skipped'  => $skipped,
				'rows_restored'  => count( $restored_rows ),
			)
		);

		wp_send_json_success(
			array(
				'restored' => $restored,
				'skipped'  => $skipped,
				'errors'   => $errors,
				'message'  => sprintf(
					/* translators: 1: restored cells, 2: skipped cells, 3: restored rows */
					__( 'Restore complete: %1$d cell(s) restored, %2$d skipped (changed since), across %3$d row(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$restored,
					$skipped,
					count( $restored_rows )
				),
			)
		);
	}

	/**
	 * Download the current automatic backup as a JSON file.
	 */
	public function download_backup(): void {
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_sr_download_backup' );

		$backup = get_option( self::BACKUP_OPTION );
		if ( ! is_array( $backup ) || empty( $backup['cells_data'] ) ) {
			wp_die( esc_html__( 'No backup available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-search-replace-backup-' . gmdate( 'Y-m-d-His', (int) $backup['time'] ) . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body.
		echo wp_json_encode( $backup, JSON_PRETTY_PRINT );
		exit;
	}

	// ── Core search/replace logic ─────────────────────────────────────────────

	/**
	 * Find rows in a table that contain the search term.
	 *
	 * @param string $table    Table name.
	 * @param array  $cols     Text columns.
	 * @param string $pk       Primary key column name.
	 * @param array  $params   Operation parameters.
	 * @param bool   $preview  If true, limit results and include diff snippets.
	 * @return array Matched rows with before/after diffs.
	 */
	private function find_matches( string $table, array $cols, string $pk, array $params, bool $preview ): array {
		global $wpdb;
		$search   = $params['search'];
		$replace  = $params['replace'];
		$case     = $params['case_sensitive'];
		$is_regex = $params['is_regex'];
		$cols     = $this->filter_columns( $table, $cols, $params );

		$safe_table = $this->sql_ident( $table );
		$safe_pk    = $this->sql_ident( $pk );
		$safe_cols  = $this->sql_ident_list( $cols );
		if ( '' === $safe_table || '' === $safe_pk || '' === $safe_cols ) {
			return array();
		}

		$limit = $preview ? self::PREVIEW_LIMIT : 10000;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident(); values use prepare placeholders.
		if ( $is_regex ) {
			// Regex can't be pushed into SQL, so every row has to be paged
			// through and checked in PHP — exactly what replace_in_table()
			// does for Execute. Previewing a single arbitrary LIMIT-50 window
			// (the old behaviour) could report "0 matches" while Execute went
			// on to scan and modify the entire table. Page through the table
			// in the same ascending-PK order Execute uses, stopping once
			// enough sample matches are collected. Preview additionally caps
			// the total rows scanned so a huge table can't stall the AJAX
			// request; Execute (replace_in_table) has no such cap.
			$matches  = array();
			$offset   = 0;
			$batch    = 500;
			$scan_cap = $preview ? 20000 : PHP_INT_MAX;
			$scanned  = 0;

			do {
				$sql     = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' ORDER BY ' . $safe_pk . ' ASC LIMIT %d OFFSET %d';
				$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $batch, $offset ), ARRAY_A );
				$fetched = is_array( $rows ) ? count( $rows ) : 0;
				if ( ! $rows ) {
					break;
				}
				$scanned += count( $rows );

				foreach ( $rows as $row ) {
					$pk_val = $row[ $pk ] ?? null;
					if ( $this->is_own_backup_row( $table, $pk_val ) ) {
						continue;
					}
					$changes = array();
					foreach ( $cols as $col ) {
						$orig = (string) ( $row[ $col ] ?? '' );
						if ( '' === $orig || ! $this->value_contains( $search, $orig, $case, $is_regex ) ) {
							continue;
						}
						$this->current_cell = array( $table, $pk_val );
						$after              = $this->safe_replace( $search, $replace, $orig, $case, $is_regex );
						if ( $after !== $orig ) {
							$changes[ $col ] = array(
								'before' => $preview ? $this->preview_excerpt( $orig, $after, true, $params ) : '',
								'after'  => $preview ? $this->preview_excerpt( $orig, $after, false, $params ) : '',
							);
						}
					}
					if ( ! empty( $changes ) ) {
						$matches[] = array(
							'pk'      => $pk_val,
							'changes' => $changes,
						);
						if ( $preview && count( $matches ) >= $limit ) {
							$this->truncated_tables[ $table ] = true;
							return $matches;
						}
					}
				}

				$offset += $batch;
			} while ( $fetched === $batch && $scanned < $scan_cap );

			if ( $preview && count( $rows ) === $batch && $scanned >= $scan_cap ) {
				$this->truncated_tables[ $table ] = true;
			}

			return $matches;
		}

		$args  = array();
		$where = $this->build_like_clause( $cols, $search, $args );
		if ( '' === $where ) {
			return array();
		}
		$sql    = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' WHERE ' . $where . ' ORDER BY ' . $safe_pk . ' ASC LIMIT %d';
		$args[] = $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		// phpcs:enable

		if ( $preview && is_array( $rows ) && count( $rows ) >= $limit ) {
			$this->truncated_tables[ $table ] = true;
		}

		$matches = array();
		foreach ( (array) $rows as $row ) {
			$pk_val = $row[ $pk ] ?? null;
			if ( $this->is_own_backup_row( $table, $pk_val ) ) {
				continue;
			}
			$changes = array();
			foreach ( $cols as $col ) {
				$orig = (string) ( $row[ $col ] ?? '' );
				if ( '' === $orig || ! $this->value_contains( $search, $orig, $case, $is_regex ) ) {
					continue;
				}
				$this->current_cell = array( $table, $pk_val );
						$after      = $this->safe_replace( $search, $replace, $orig, $case, $is_regex );
				if ( $after !== $orig ) {
					$changes[ $col ] = array(
						'before' => $preview ? $this->preview_excerpt( $orig, $after, true, $params ) : '',
						'after'  => $preview ? $this->preview_excerpt( $orig, $after, false, $params ) : '',
					);
				}
			}
			if ( ! empty( $changes ) ) {
				$matches[] = array(
					'pk'      => $pk_val,
					'changes' => $changes,
				);
			}
		}
		return $matches;
	}

	/**
	 * Execute replace on one table.
	 *
	 * @param string $table            Table name.
	 * @param array  $cols             Text columns.
	 * @param string $pk               Primary key column.
	 * @param array  $params           Parameters.
	 * @param array  $backup_cells     Backup snapshot accumulator (by reference, capped).
	 * @param bool   $backup_truncated Set true once the backup cap is reached (by reference).
	 * @return array{rows:int,cells:int,errors:array}
	 */
	private function replace_in_table( string $table, array $cols, string $pk, array $params, array &$backup_cells, bool &$backup_truncated ): array {
		global $wpdb;
		$search   = $params['search'];
		$replace  = $params['replace'];
		$case     = $params['case_sensitive'];
		$is_regex = $params['is_regex'];
		$cols     = $this->filter_columns( $table, $cols, $params );

		$safe_table = $this->sql_ident( $table );
		$safe_pk    = $this->sql_ident( $pk );
		$safe_cols  = $this->sql_ident_list( $cols );
		if ( '' === $safe_table || '' === $safe_pk || '' === $safe_cols ) {
			return array(
				'rows'   => 0,
				'cells'  => 0,
				'errors' => array( __( 'Invalid table or column name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			);
		}

		$stats = array(
			'rows'   => 0,
			'cells'  => 0,
			'errors' => array(),
		);

		// Non-regex: always fetch from the start of the matching set — updated rows drop out of LIKE.
		// Regex: scan the full table once with OFFSET (each row is visited exactly once).
		if ( $is_regex ) {
			$offset = 0;
			$batch  = 500;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident().
			do {
				$sql     = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' ORDER BY ' . $safe_pk . ' ASC LIMIT %d OFFSET %d';
				$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $batch, $offset ), ARRAY_A );
				$fetched = is_array( $rows ) ? count( $rows ) : 0;

				if ( ! $rows ) {
					break;
				}

				$this->apply_row_updates( $table, $cols, $pk, $rows, $search, $replace, $case, $is_regex, $stats, $backup_cells, $backup_truncated );
				$offset += $batch;
			} while ( $fetched === $batch );
			// phpcs:enable

			return $stats;
		}

		$batch   = 500;
		$last_pk = null;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident(); LIKE/PK values use prepare placeholders.
		do {
			$args  = array();
			$where = $this->build_like_clause( $cols, $search, $args );
			if ( '' === $where ) {
				$stats['errors'][] = __( 'Invalid column name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				return $stats;
			}

			// Keyset pagination (PK > last seen), not a plain re-query from the
			// start: re-querying "WHERE ... LIKE ..." with no lower bound
			// assumes updated rows always drop out of the LIKE match — false
			// whenever the replacement text still contains the search text
			// (e.g. search "example.com" → replace "www.example.com"). That
			// assumption previously caused the same batch to be re-matched and
			// re-replaced forever. Requiring PK > last_pk guarantees each row
			// is visited at most once, regardless of what the update does to
			// its own LIKE-match status.
			if ( null !== $last_pk ) {
				$where .= ' AND ' . $safe_pk . ' > %s';
				$args[] = (string) $last_pk;
			}

			$sql     = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' WHERE ' . $where . ' ORDER BY ' . $safe_pk . ' ASC LIMIT %d';
			$args[]  = $batch;
			$rows    = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			$fetched = is_array( $rows ) ? count( $rows ) : 0;
			// phpcs:enable

			if ( ! $rows ) {
				break;
			}

			$this->apply_row_updates( $table, $cols, $pk, $rows, $search, $replace, $case, $is_regex, $stats, $backup_cells, $backup_truncated );

			$last_row = end( $rows );
			$last_pk  = $last_row[ $pk ] ?? null;
			if ( null === $last_pk ) {
				break;
			}
		} while ( $fetched === $batch );

		return $stats;
	}

	/**
	 * Apply search-replace updates to a batch of rows.
	 *
	 * @param string              $table            Table name.
	 * @param array               $cols             Text columns.
	 * @param string              $pk               Primary key column.
	 * @param array               $rows             Fetched rows.
	 * @param string              $search           Search term.
	 * @param string              $replace          Replacement.
	 * @param bool                $case_sensitive             Case-sensitive.
	 * @param bool                $is_regex         Regex mode.
	 * @param array<string,mixed> $stats            Stats array passed by reference.
	 * @param array               $backup_cells     Backup snapshot accumulator (by reference, capped).
	 * @param bool                $backup_truncated Set true once the backup cap is reached (by reference).
	 */
	private function apply_row_updates( string $table, array $cols, string $pk, array $rows, string $search, string $replace, bool $case_sensitive, bool $is_regex, array &$stats, array &$backup_cells, bool &$backup_truncated ): void {
		global $wpdb;

		foreach ( $rows as $row ) {
			$pk_val = $row[ $pk ] ?? null;
			if ( null === $pk_val || $this->is_own_backup_row( $table, $pk_val ) ) {
				continue;
			}
			$updates = array();
			$formats = array();

			foreach ( $cols as $col ) {
				$orig = (string) ( $row[ $col ] ?? '' );
				if ( '' === $orig || ! $this->value_contains( $search, $orig, $case_sensitive, $is_regex ) ) {
					continue;
				}
				$this->current_cell = array( $table, $pk_val );
				$new                = $this->safe_replace( $search, $replace, $orig, $case_sensitive, $is_regex );
				if ( $new !== $orig ) {
					$updates[ $col ] = $new;
					$formats[]       = '%s';
					++$stats['cells'];

					if ( count( $backup_cells ) < self::BACKUP_CELL_CAP ) {
						$backup_cells[] = array(
							'table'  => $table,
							'pk'     => $pk_val,
							'col'    => $col,
							'before' => $orig,
							'after'  => $new,
						);
					} else {
						$backup_truncated = true;
					}
				}
			}

			if ( ! empty( $updates ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update( $table, $updates, array( $pk => $pk_val ), $formats, array( '%s' ) );
				if ( false === $result ) {
					$stats['errors'][] = "Table {$table} PK {$pk_val}: " . $wpdb->last_error;
				} else {
					++$stats['rows'];
				}
			}
		}
	}

	/**
	 * Build a stable hash for preview/execute parameter matching.
	 *
	 * @param array $params Parsed request parameters.
	 * @return string
	 */
	private function get_params_hash( array $params ): string {
		$tables = $params['tables'];
		sort( $tables );

		return wp_hash(
			wp_json_encode(
				array(
					'search'         => $params['search'],
					'replace'        => $params['replace'],
					'case_sensitive' => (bool) $params['case_sensitive'],
					'is_regex'       => (bool) $params['is_regex'],
					'include_guid'   => ! empty( $params['include_guid'] ),
					'tables'         => $tables,
				)
			)
		);
	}

	/**
	 * Transient key for the current user's approved preview.
	 *
	 * @return string
	 */
	private function get_preview_approval_key(): string {
		return 'tsosk_sr_approval_' . get_current_user_id();
	}

	/**
	 * Store preview approval for execute validation.
	 *
	 * @param array $params Parsed request parameters.
	 * @return string Approval token returned to the client.
	 */
	private function store_preview_approval( array $params ): string {
		$hash = $this->get_params_hash( $params );
		set_transient( $this->get_preview_approval_key(), $hash, 10 * MINUTE_IN_SECONDS );
		return $hash;
	}

	/**
	 * Whether execute params match the last preview for this user.
	 *
	 * @param array $params Parsed request parameters.
	 * @return bool
	 */
	private function is_preview_approved( array $params ): bool {
		$stored = get_transient( $this->get_preview_approval_key() );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $this->get_params_hash( $params ) );
	}

	/**
	 * Clear stored preview approval after a successful execute.
	 */
	private function clear_preview_approval(): void {
		delete_transient( $this->get_preview_approval_key() );
	}

	/**
	 * Get the primary key column name for a table.
	 *
	 * @param string $table Table name.
	 * @return string Column name or empty string if not found.
	 */
	private function get_primary_key( string $table ): string {
		global $wpdb;

		if ( '' === $this->sql_ident( $table ) || ! $this->table_exists( $table ) ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME AS Field, COLUMN_KEY AS `Key`
				   FROM information_schema.COLUMNS
				  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				  ORDER BY ORDINAL_POSITION ASC',
				DB_NAME,
				$table
			),
			ARRAY_A
		);
		if ( ! is_array( $cols ) || array() === $cols ) {
			return '';
		}
		$primary = array();
		foreach ( $cols as $col ) {
			$col = array_change_key_case( (array) $col, CASE_LOWER );
			if ( 'PRI' !== strtoupper( (string) ( $col['key'] ?? $col['column_key'] ?? '' ) ) ) {
				continue;
			}
			$field = (string) ( $col['field'] ?? $col['column_name'] ?? '' );
			if ( '' !== $this->sql_ident( $field ) ) {
				$primary[] = $field;
			}
		}
		// Only a single-column primary key is safe for row updates (no first-column fallback).
		return 1 === count( $primary ) ? $primary[0] : '';
	}

	// ── Request parsing ───────────────────────────────────────────────────────

	/**
	 * Parse and validate request parameters for preview/execute.
	 *
	 * @return array|WP_Error
	 */
	private function parse_request_params() {
		// Nonce verified in ajax_preview() / ajax_execute() before this runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$search    = isset( $_POST['search'] ) && is_string( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw search term required
		$replace   = isset( $_POST['replace'] ) && is_string( $_POST['replace'] ) ? wp_unslash( $_POST['replace'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw replace term required
		$case      = ! empty( $_POST['case_sensitive'] );
		$is_regex  = ! empty( $_POST['is_regex'] );
		$guid      = ! empty( $_POST['include_guid'] );
		$tables_in = isset( $_POST['tables'] ) ? map_deep( wp_unslash( (array) $_POST['tables'] ), 'sanitize_text_field' ) : array();
		// phpcs:enable

		$search  = (string) $search;
		$replace = (string) $replace;

		if ( strlen( $search ) > 5000 || strlen( $replace ) > 5000 ) {
			return new WP_Error( 'too_long', __( 'Search or replace text is too long.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( '' === $search ) {
			return new WP_Error( 'empty_search', __( 'Search term cannot be empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( $is_regex && ! $this->is_safe_regex_pattern( $search ) ) {
			return new WP_Error( 'invalid_regex', __( 'The search pattern is not a valid regular expression.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Validate tables: sanitize and confirm each exists.
		$valid_tables = array();
		foreach ( $tables_in as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( $t && $this->table_exists( $t ) ) {
				$valid_tables[] = $t;
			}
		}
		if ( empty( $valid_tables ) ) {
			return new WP_Error( 'no_tables', __( 'No tables selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return array(
			'search'         => $search,
			'replace'        => $replace,
			'case_sensitive' => $case,
			'is_regex'       => $is_regex,
			'include_guid'   => $guid,
			'tables'         => $valid_tables,
		);
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render intro guide for the Search & Replace tab.
	 */
	private function render_guide(): void {
		?>
		<details class="tsosk-guide-card tsosk-guide-collapse">
			<summary class="tsosk-guide-title"><?php esc_html_e( 'What is this tool for?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'It finds a piece of text inside your database and replaces it with another — across posts, options, meta, and other tables. Think of it as “Find & Replace” in a text editor, but for the whole WordPress database.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<div class="tsosk-guide-grid">
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'Typical uses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Change the site URL after a migration (old domain → new domain).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Switch http:// to https:// in stored content or options.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Fix a typo or wrong path that appears in many posts or plugin settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					</ul>
				</div>
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'How to use it (recommended order)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<ol class="tsosk-guide-steps">
						<li><?php esc_html_e( 'Make a full database backup.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Type the exact text to find and what to replace it with.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Choose which tables to scan (WordPress tables is enough for most URL changes).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Click Preview — review every change before touching the database.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Only then click Execute replace.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					</ol>
				</div>
			</div>

			<div class="tsosk-guide-example">
				<strong><?php esc_html_e( 'Example — change domain after moving the site:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<p>
					<?php esc_html_e( 'Search for:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<code>https://old-site.com</code>
					<?php esc_html_e( 'Replace with:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<code>https://new-site.com</code>
				</p>
			</div>
		</details>
		<?php
	}

	/**
	 * Render the "Automatic backup" panel: status of the last Execute run's
	 * snapshot, with Restore and Download actions when one is available.
	 *
	 * @param string $nonce Shared tsosk_sr_nonce for the AJAX actions.
	 */
	private function render_backup_panel( string $nonce ): void {
		$backup     = get_option( self::BACKUP_OPTION );
		$has_backup = is_array( $backup ) && ! empty( $backup['cells_data'] );
		?>
		<div class="tsosk-card" id="tsosk-sr-backup-card">
			<h3><?php esc_html_e( 'Automatic backup', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<?php if ( ! $has_backup ) : ?>
				<p class="description">
					<?php esc_html_e( 'No automatic backup yet. One is created automatically the next time you run Execute replace, so that operation can be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: date/time, 2: search term, 3: replace term */
						esc_html__( 'Backup from %1$s: "%2$s" → "%3$s".', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( wp_date( 'Y-m-d H:i', (int) $backup['time'] ) ),
						esc_html( (string) $backup['search'] ),
						esc_html( (string) $backup['replace'] )
					);
					?>
					<br>
					<?php
					printf(
						/* translators: 1: number of cells, 2: number of rows */
						esc_html__( '%1$d cell(s) captured across %2$d row(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) $backup['cells'],
						(int) $backup['rows']
					);
					?>
				</p>
				<?php if ( ! empty( $backup['truncated'] ) ) : ?>
				<div class="tsosk-notice tsosk-notice-warn">
					<?php
					printf(
						/* translators: %d: max cells kept in the backup */
						esc_html__( 'Only the first %d changed cells were captured for this backup (the operation affected more). Restore will still work for those — for very large operations, also keep a full manual database backup.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(int) self::BACKUP_CELL_CAP
					);
					?>
				</div>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-primary" id="tsosk-sr-backup-restore-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Restore backup', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tsosk_sr_download_backup' ), 'tsosk_sr_download_backup' ) ); ?>">
						<?php esc_html_e( 'Download backup (JSON)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</a>
					<span class="tsosk-ajax-msg" id="tsosk-sr-backup-msg"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render field help under search form controls.
	 */
	private function render_field_help(): void {
		?>
		<div class="tsosk-field-help-list">
			<p><strong><?php esc_html_e( 'Search for', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php esc_html_e( 'The exact text you want to find. It is matched inside table cells (post content, options, meta, etc.). URLs are also replaced where they are stored in JSON with escaped slashes (https:\\/\\/…), as in Elementor or block data.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p><strong><?php esc_html_e( 'Replace with', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php esc_html_e( 'The new text. Leave empty to delete the matched text.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p><strong><?php esc_html_e( 'Case-sensitive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php esc_html_e( 'When off, “Site” and “site” are treated the same. Turn on only if capital letters matter.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p><strong><?php esc_html_e( 'Regular expression', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php esc_html_e( 'Advanced mode for patterns (not plain text). Only enable if you know regex — wrong patterns can change too much.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p><strong><?php esc_html_e( 'Tables to search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php esc_html_e( 'Which database tables to scan. For URL or content fixes, selecting WordPress tables (posts, postmeta, options…) is usually enough.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render.
	 */
	public function render(): void {
		global $wpdb;
		$nonce           = wp_create_nonce( 'tsosk_sr_nonce' );
		$tables          = $this->get_all_tables();
		$prefill_search  = TSOSK_Support::get_query_scalar( 'tsosk_sr_search' );
		$prefill_replace = TSOSK_Support::get_query_scalar( 'tsosk_sr_replace' );
		?>

		<p class="tsosk-desc">
			<?php esc_html_e( 'Bulk find-and-replace inside your WordPress database — with a preview step so you see changes before they are written.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php
		$failure = get_option( 'tsosk_sr_last_failure' );
		if ( is_array( $failure ) && ! empty( $failure['time'] ) ) :
			?>
			<div class="tsosk-notice tsosk-notice-warn" style="border-left-color:#d63638;">
				<strong><?php esc_html_e( 'The last replace did not finish.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php
				printf(
					/* translators: 1: date and time, 2: error reported by PHP (may be empty) */
					esc_html__( 'The server stopped the request at %1$s. %2$s Some tables may have been changed before it stopped: run Preview again to see what is left, or restore the automatic backup.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( wp_date( 'Y-m-d H:i', (int) $failure['time'] ) ),
					esc_html( (string) ( $failure['message'] ?? '' ) )
				);
				?>
			</div>
		<?php endif; ?>

		<?php $this->render_guide(); ?>

		<div class="tsosk-notice tsosk-notice-warn" style="border-left-color:#d63638;">
			<strong><?php esc_html_e( '⚠ Always make a full database backup before running a replace operation.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php esc_html_e( 'A wrong search term can alter hundreds of rows at once. Preview is mandatory — Execute only appears after a successful preview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>

		<?php if ( '' !== $prefill_search ) : ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Search and replace were filled from URL & HTTPS Doctor. Preview is still required before anything is written.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		<?php endif; ?>

		<?php $this->render_backup_panel( $nonce ); ?>

		<?php /* ── Search form ── */ ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Search & Replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<table class="tsosk-kv-table tsosk-sr-form-table">
				<tr>
					<th style="width:180px;"><?php esc_html_e( 'Search for', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-sr-search"
								style="width:100%;" autocomplete="off" spellcheck="false"
								value="<?php echo esc_attr( $prefill_search ); ?>"
								placeholder="<?php esc_attr_e( 'Text or regex pattern to find…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Replace with', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-sr-replace"
								style="width:100%;" autocomplete="off" spellcheck="false"
								value="<?php echo esc_attr( $prefill_replace ); ?>"
								placeholder="<?php esc_attr_e( 'Replacement text (leave empty to delete matches)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label style="margin-right:20px;">
							<input type="checkbox" id="tsosk-sr-case">
							<?php esc_html_e( 'Case-sensitive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<label>
							<input type="checkbox" id="tsosk-sr-regex">
							<?php esc_html_e( 'Regular expression', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							<span class="description" style="font-size:11px;">
								(<?php esc_html_e( 'advanced — test carefully', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>)
							</span>
						</label>
						<label style="display:block;margin-top:8px;">
							<input type="checkbox" id="tsosk-sr-guid">
							<?php esc_html_e( 'Include post GUIDs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							<span class="description" style="font-size:11px;">
								(<?php esc_html_e( 'not recommended: a GUID is the permanent identifier of a post', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>)
							</span>
						</label>
					</td>
				</tr>
			</table>

			<?php /* ── Table selection (collapsed: the defaults suit most searches) ── */ ?>
			<details class="tsosk-sr-tables-block">
				<summary class="tsosk-sr-tables-summary">
					<span class="tsosk-sr-tables-label"><?php esc_html_e( 'Tables to search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="description" id="tsosk-sr-table-count"
							data-template="<?php echo esc_attr( __( '{shown} of {total} tables shown · {selected} selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?>"
							data-empty="<?php echo esc_attr( __( 'No tables match your search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?>"></span>
				</summary>
				<p class="description tsosk-sr-tables-desc">
					<?php esc_html_e( 'Tick the tables where the text might live. Unsure? Start with “Select WordPress tables”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<div class="tsosk-sr-table-filter">
					<label class="screen-reader-text" for="tsosk-sr-table-filter"><?php esc_html_e( 'Filter tables', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
					<input type="search" id="tsosk-sr-table-filter" class="regular-text" autocomplete="off"
							placeholder="<?php esc_attr_e( 'Type to filter tables (e.g. postmeta, options, woocommerce)…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				</div>
				<div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
					<button type="button" class="button button-small" id="tsosk-sr-select-wp">
						<?php esc_html_e( 'Select WordPress tables', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<button type="button" class="button button-small" id="tsosk-sr-select-all">
						<?php esc_html_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<button type="button" class="button button-small" id="tsosk-sr-select-none">
						<?php esc_html_e( 'Deselect all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
				</div>
				<div id="tsosk-sr-table-list"
					style="max-height:200px;overflow-y:auto;border:1px solid #c3c4c7;border-radius:4px;padding:6px 10px;background:#fff;columns:2 250px;gap:16px;">
					<?php foreach ( $tables as $tbl ) : ?>
					<label style="display:block;white-space:nowrap;font-size:13px;padding:2px 0;">
						<input type="checkbox" class="tsosk-sr-table-cb"
								value="<?php echo esc_attr( $tbl['name'] ); ?>"
								data-is-wp="<?php echo $tbl['is_wp'] ? '1' : '0'; ?>"
								<?php checked( $tbl['is_wp'] ); ?>>
						<span class="tsosk-code" style="font-size:12px;"><?php echo esc_html( $tbl['name'] ); ?></span>
						<span style="color:#8c8f94;font-size:11px;">(<?php echo esc_html( $tbl['size'] ); ?>)</span>
					</label>
					<?php endforeach; ?>
				</div>
			</details>

			<?php /* ── Action buttons ── */ ?>
			<div class="tsosk-sr-actions">
				<button class="button" id="tsosk-sr-preview-btn"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
					🔍 <?php esc_html_e( 'Preview changes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="description tsosk-sr-action-hint"><?php esc_html_e( 'Step 1 — shows what would change, without writing anything.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<button class="button button-primary" id="tsosk-sr-execute-btn"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						style="display:none;">
					⚡ <?php esc_html_e( 'Execute replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="description tsosk-sr-action-hint tsosk-sr-execute-hint" style="display:none;"><?php esc_html_e( 'Step 2 — applies the replace to the database. Only available after preview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<button class="button" id="tsosk-sr-cancel-btn" style="display:none;">
					<?php esc_html_e( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sr-msg"></span>
			</div>

			<?php /* ── Preview results ── */ ?>
			<div id="tsosk-sr-preview-wrap" style="display:none;" class="tsosk-sr-preview-inner">
				<h3><?php esc_html_e( 'Preview — Changes to be made', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<div class="tsosk-notice tsosk-notice-warn" id="tsosk-sr-preview-notice"></div>
				<div id="tsosk-sr-preview-body"></div>
			</div>

			<?php $this->render_field_help(); ?>
		</div>

		<p class="description" style="margin-top:16px;">
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Activity History tab */
					__( 'All replace operations are logged in the %s tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					array(
						'a' => array( 'href' => array() ),
					)
				),
				'<a href="' . esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=history' ) ) . '">' . esc_html__( 'Activity History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>'
			);
			?>
		</p>
		<?php
	}
}
