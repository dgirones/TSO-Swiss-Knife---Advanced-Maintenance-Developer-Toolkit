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

	/** @var TSOSK_Mod_Search_Replace|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_sr_get_tables', array( $this, 'ajax_get_tables' ) );
		add_action( 'wp_ajax_tsosk_sr_preview',    array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_tsosk_sr_execute',    array( $this, 'ajax_execute' ) );
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
			$name     = (string) $row['name'];
			$bytes    = (int) $row['total_bytes'];
			$size_str = $bytes > 1048576
				? round( $bytes / 1048576, 1 ) . ' MB'
				: ( $bytes > 1024 ? round( $bytes / 1024, 1 ) . ' KB' : $bytes . ' B' );

			$tables[] = array(
				'name'  => $name,
				'rows'  => (int) $row['table_rows'],
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
			$base_type = strtolower( (string) ( $col['Type'] ?? '' ) );
			if ( in_array( $base_type, $text_types, true ) ) {
				$field = (string) ( $col['Field'] ?? '' );
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
	 * @param bool   $case        True = case-sensitive.
	 * @param bool   $is_regex    True = treat $search as a regex pattern.
	 * @return string Modified value.
	 */
	private function safe_replace( string $search, string $replace_str, string $subject, bool $case, bool $is_regex ): string {
		if ( '' === $search || '' === $subject ) {
			return $subject;
		}

		// Fast path: not serialized — plain replace.
		if ( ! $this->looks_serialized( $subject ) ) {
			return $this->do_replace( $search, $replace_str, $subject, $case, $is_regex );
		}

		$data = @unserialize( $subject, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false !== $data || 'b:0;' === $subject ) {
			$replaced = $this->replace_in_data( $data, $search, $replace_str, $case, $is_regex );
			return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		// Fallback: replace inside serialized string segments and fix byte lengths.
		$result = preg_replace_callback(
			'/s:(\d+):"((?:[^"\\\\]|\\\\.)*)";/s',
			function ( array $m ) use ( $search, $replace_str, $case, $is_regex ): string {
				$inner    = stripcslashes( $m[2] );
				$replaced = $this->do_replace( $search, $replace_str, $inner, $case, $is_regex );
				$escaped  = addcslashes( $replaced, "\\\"\0" );
				return 's:' . strlen( $escaped ) . ':"' . $escaped . '";';
			},
			$subject
		);

		if ( null === $result ) {
			return $subject;
		}

		$test = @unserialize( $result, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return ( false !== $test || 'b:0;' === $result ) ? $result : $subject;
	}

	/**
	 * Recursively replace strings inside serialized PHP data structures.
	 *
	 * @param mixed  $data        Decoded value.
	 * @param string $search      Search term.
	 * @param string $replace_str Replacement.
	 * @param bool   $case        Case-sensitive.
	 * @param bool   $is_regex    Regex mode.
	 * @return mixed
	 */
	private function replace_in_data( $data, string $search, string $replace_str, bool $case, bool $is_regex ) {
		if ( is_string( $data ) ) {
			return $this->do_replace( $search, $replace_str, $data, $case, $is_regex );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_in_data( $value, $search, $replace_str, $case, $is_regex );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = $this->replace_in_data( $value, $search, $replace_str, $case, $is_regex );
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
	 * @param bool   $case    True = case-sensitive.
	 * @param bool   $is_regex True = $search is a regex.
	 * @return string
	 */
	private function do_replace( string $search, string $replace, string $subject, bool $case, bool $is_regex ): string {
		if ( $is_regex ) {
			$flags    = $case ? '' : 'i';
			$replaced = preg_replace( '/' . $search . '/' . $flags . 'u', $replace, $subject );
			return null !== $replaced ? $replaced : $subject;
		}
		if ( $case ) {
			return str_replace( $search, $replace, $subject );
		}
		return str_ireplace( $search, $replace, $subject );
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
	 * @param bool   $case    True = case-sensitive.
	 * @param bool   $is_regex True = regex.
	 * @return bool
	 */
	private function value_contains( string $search, string $subject, bool $case, bool $is_regex ): bool {
		if ( $is_regex ) {
			$flags = $case ? '' : 'i';
			return (bool) preg_match( '/' . $search . '/' . $flags . 'u', $subject );
		}
		if ( $case ) {
			return false !== strpos( $subject, $search );
		}
		return false !== stripos( $subject, $search );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/** AJAX: return the list of tables with sizes. */
	public function ajax_get_tables(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		wp_send_json_success( $this->get_all_tables() );
	}

	/** AJAX: dry-run preview — returns matches without modifying the DB. */
	public function ajax_preview(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$params = $this->parse_request_params();
		if ( is_wp_error( $params ) ) {
			wp_send_json_error( $params->get_error_message() );
		}

		global $wpdb;
		$results    = array();
		$total_rows = 0;

		foreach ( $params['tables'] as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$cols    = $this->get_text_columns( $table );
			$pk      = $this->get_primary_key( $table );
			if ( empty( $cols ) ) {
				continue;
			}
			$matches = $this->find_matches( $table, $cols, $pk, $params, true );
			if ( ! empty( $matches ) ) {
				$results[ $table ] = $matches;
				$total_rows       += count( $matches );
			}
		}

		wp_send_json_success( array(
			'results'    => $results,
			'total_rows' => $total_rows,
			'tables'     => array_keys( $results ),
			'approval'   => $this->store_preview_approval( $params ),
		) );
	}

	/** AJAX: execute the search-replace and write to DB. */
	public function ajax_execute(): void {
		check_ajax_referer( 'tsosk_sr_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$params = $this->parse_request_params();
		if ( is_wp_error( $params ) ) {
			wp_send_json_error( $params->get_error_message() );
		}

		if ( ! $this->is_preview_approved( $params ) ) {
			wp_send_json_error( __( 'Run Preview again before executing. The search settings changed or the preview expired.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		$stats = array( 'tables' => 0, 'rows' => 0, 'cells' => 0, 'errors' => array() );

		foreach ( $params['tables'] as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$cols = $this->get_text_columns( $table );
			$pk   = $this->get_primary_key( $table );
			if ( empty( $cols ) || '' === $pk ) {
				continue;
			}
			$table_stats = $this->replace_in_table( $table, $cols, $pk, $params );
			if ( $table_stats['rows'] > 0 ) {
				$stats['tables']++;
				$stats['rows']  += $table_stats['rows'];
				$stats['cells'] += $table_stats['cells'];
			}
			if ( ! empty( $table_stats['errors'] ) ) {
				$stats['errors'] = array_merge( $stats['errors'], $table_stats['errors'] );
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
				'search'  => $params['search'],
				'replace' => $params['replace'],
				'tables'  => count( $params['tables'] ),
				'rows'    => $stats['rows'],
				'cells'   => $stats['cells'],
			)
		);

		wp_send_json_success( array(
			'tables' => $stats['tables'],
			'rows'   => $stats['rows'],
			'cells'  => $stats['cells'],
			'errors' => $stats['errors'],
			'message' => sprintf(
				/* translators: 1: tables, 2: rows, 3: cells */
				__( 'Done. Replaced in %1$d table(s), %2$d row(s), %3$d cell(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$stats['tables'],
				$stats['rows'],
				$stats['cells']
			),
		) );
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

		$safe_table = $this->sql_ident( $table );
		$safe_pk    = $this->sql_ident( $pk );
		$safe_cols  = $this->sql_ident_list( $cols );
		if ( '' === $safe_table || '' === $safe_pk || '' === $safe_cols ) {
			return array();
		}

		$limit = $preview ? self::PREVIEW_LIMIT : 10000;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident(); values use prepare placeholders.
		if ( $is_regex ) {
			$sql  = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' LIMIT %d';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
		} else {
			$like_parts = array();
			foreach ( $cols as $col ) {
				$col_sql = $this->sql_ident( (string) $col );
				if ( '' === $col_sql ) {
					return array();
				}
				$like_parts[] = $col_sql . ' LIKE %s';
			}
			$like_term = '%' . $wpdb->esc_like( $search ) . '%';
			$where     = '(' . implode( ' OR ', $like_parts ) . ')';
			$sql       = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' WHERE ' . $where . ' LIMIT %d';
			$args      = array_merge( array_fill( 0, count( $cols ), $like_term ), array( $limit ) );
			$rows      = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		}
		// phpcs:enable

		$matches = array();
		foreach ( (array) $rows as $row ) {
			$pk_val  = $row[ $pk ] ?? null;
			$changes = array();
			foreach ( $cols as $col ) {
				$orig = (string) ( $row[ $col ] ?? '' );
				if ( '' === $orig || ! $this->value_contains( $search, $orig, $case, $is_regex ) ) {
					continue;
				}
				$after = $this->safe_replace( $search, $replace, $orig, $case, $is_regex );
				if ( $after !== $orig ) {
					$changes[ $col ] = array(
						'before' => $preview ? mb_substr( $orig, 0, 300 ) : '',
						'after'  => $preview ? mb_substr( $after, 0, 300 ) : '',
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
	 * @param string $table  Table name.
	 * @param array  $cols   Text columns.
	 * @param string $pk     Primary key column.
	 * @param array  $params Parameters.
	 * @return array{rows:int,cells:int,errors:array}
	 */
	private function replace_in_table( string $table, array $cols, string $pk, array $params ): array {
		global $wpdb;
		$search   = $params['search'];
		$replace  = $params['replace'];
		$case     = $params['case_sensitive'];
		$is_regex = $params['is_regex'];

		$safe_table = $this->sql_ident( $table );
		$safe_pk    = $this->sql_ident( $pk );
		$safe_cols  = $this->sql_ident_list( $cols );
		if ( '' === $safe_table || '' === $safe_pk || '' === $safe_cols ) {
			return array( 'rows' => 0, 'cells' => 0, 'errors' => array( __( 'Invalid table or column name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) );
		}

		$stats = array( 'rows' => 0, 'cells' => 0, 'errors' => array() );

		// Non-regex: always fetch from the start of the matching set — updated rows drop out of LIKE.
		// Regex: scan the full table once with OFFSET (each row is visited exactly once).
		if ( $is_regex ) {
			$offset = 0;
			$batch  = 500;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident().
			do {
				$sql  = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' LIMIT %d OFFSET %d';
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $batch, $offset ), ARRAY_A );

				if ( ! $rows ) {
					break;
				}

				$this->apply_row_updates( $table, $cols, $pk, $rows, $search, $replace, $case, $is_regex, $stats );
				$offset += $batch;
			} while ( count( $rows ) === $batch );
			// phpcs:enable

			return $stats;
		}

		$batch = 500;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers validated via sql_ident(); LIKE values use prepare placeholders.
		do {
			$like_parts = array();
			foreach ( $cols as $col ) {
				$col_sql = $this->sql_ident( (string) $col );
				if ( '' === $col_sql ) {
					$stats['errors'][] = __( 'Invalid column name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					return $stats;
				}
				$like_parts[] = $col_sql . ' LIKE %s';
			}
			$like_term = '%' . $wpdb->esc_like( $search ) . '%';
			$where     = '(' . implode( ' OR ', $like_parts ) . ')';
			$sql       = 'SELECT ' . $safe_pk . ', ' . $safe_cols . ' FROM ' . $safe_table . ' WHERE ' . $where . ' LIMIT %d';
			$args      = array_merge( array_fill( 0, count( $cols ), $like_term ), array( $batch ) );
			$rows      = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			// phpcs:enable

			if ( ! $rows ) {
				break;
			}

			$this->apply_row_updates( $table, $cols, $pk, $rows, $search, $replace, $case, $is_regex, $stats );
		} while ( count( $rows ) === $batch );

		return $stats;
	}

	/**
	 * Apply search-replace updates to a batch of rows.
	 *
	 * @param string               $table    Table name.
	 * @param array                $cols     Text columns.
	 * @param string               $pk       Primary key column.
	 * @param array                $rows     Fetched rows.
	 * @param string               $search   Search term.
	 * @param string               $replace  Replacement.
	 * @param bool                 $case     Case-sensitive.
	 * @param bool                 $is_regex Regex mode.
	 * @param array<string,mixed>  $stats    Stats array passed by reference.
	 */
	private function apply_row_updates( string $table, array $cols, string $pk, array $rows, string $search, string $replace, bool $case, bool $is_regex, array &$stats ): void {
		global $wpdb;

		foreach ( $rows as $row ) {
			$pk_val = $row[ $pk ] ?? null;
			if ( null === $pk_val ) {
				continue;
			}
			$updates = array();
			$formats = array();

			foreach ( $cols as $col ) {
				$orig = (string) ( $row[ $col ] ?? '' );
				if ( '' === $orig || ! $this->value_contains( $search, $orig, $case, $is_regex ) ) {
					continue;
				}
				$new = $this->safe_replace( $search, $replace, $orig, $case, $is_regex );
				if ( $new !== $orig ) {
					$updates[ $col ] = $new;
					$formats[]       = '%s';
					$stats['cells']++;
				}
			}

			if ( ! empty( $updates ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update( $table, $updates, array( $pk => $pk_val ), $formats, array( '%s' ) );
				if ( false === $result ) {
					$stats['errors'][] = "Table {$table} PK {$pk_val}: " . $wpdb->last_error;
				} else {
					$stats['rows']++;
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
		foreach ( $cols as $col ) {
			if ( 'PRI' === ( $col['Key'] ?? '' ) ) {
				$field = (string) ( $col['Field'] ?? '' );
				return ( '' !== $this->sql_ident( $field ) ) ? $field : '';
			}
		}
		$field = (string) ( $cols[0]['Field'] ?? '' );
		return ( '' !== $this->sql_ident( $field ) ) ? $field : '';
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
		$search    = isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw search term required
		$replace   = isset( $_POST['replace'] ) ? wp_unslash( $_POST['replace'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw replace term required
		$case      = ! empty( $_POST['case_sensitive'] );
		$is_regex  = ! empty( $_POST['is_regex'] );
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

		if ( $is_regex ) {
			// Validate regex syntax.
			if ( false === @preg_match( '/' . $search . '/u', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'invalid_regex', __( 'The search pattern is not a valid regular expression.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
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
			'tables'         => $valid_tables,
		);
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Render intro guide for the Search & Replace tab.
	 */
	private function render_guide(): void {
		?>
		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'What is this tool for?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
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
				<?php esc_html_e( 'The exact text you want to find. It is matched inside table cells (post content, options, meta, etc.).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
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

	public function render(): void {
		global $wpdb;
		$nonce  = wp_create_nonce( 'tsosk_sr_nonce' );
		$tables = $this->get_all_tables();
		?>

		<p class="tsosk-desc">
			<?php esc_html_e( 'Bulk find-and-replace inside your WordPress database — with a preview step so you see changes before they are written.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php $this->render_guide(); ?>

		<div class="tsosk-notice tsosk-notice-warn" style="border-left-color:#d63638;">
			<strong><?php esc_html_e( '⚠ Always make a full database backup before running a replace operation.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php esc_html_e( 'A wrong search term can alter hundreds of rows at once. Preview is mandatory — Execute only appears after a successful preview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>

		<?php /* ── Search form ── */ ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Search & Replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<table class="tsosk-kv-table tsosk-sr-form-table">
				<tr>
					<th style="width:180px;"><?php esc_html_e( 'Search for', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-sr-search"
						       style="width:100%;" autocomplete="off" spellcheck="false"
						       placeholder="<?php esc_attr_e( 'Text or regex pattern to find…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Replace with', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-sr-replace"
						       style="width:100%;" autocomplete="off" spellcheck="false"
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
					</td>
				</tr>
			</table>

			<?php $this->render_field_help(); ?>

			<?php /* ── Table selection ── */ ?>
			<div class="tsosk-sr-tables-block">
				<label class="tsosk-sr-tables-label">
					<?php esc_html_e( 'Tables to search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-sr-tables-desc">
					<?php esc_html_e( 'Tick the tables where the text might live. Unsure? Start with “Select WordPress tables”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
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
			</div>

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
		</div>

		<?php /* ── Preview results ── */ ?>
		<div id="tsosk-sr-preview-wrap" style="display:none;" class="tsosk-card">
			<h3><?php esc_html_e( 'Preview — Changes to be made', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-notice tsosk-notice-warn" id="tsosk-sr-preview-notice"></div>
			<div id="tsosk-sr-preview-body"></div>
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
