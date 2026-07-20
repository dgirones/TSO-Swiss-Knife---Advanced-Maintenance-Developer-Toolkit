<?php
/**
 * TSO Swiss Knife – Module: Options Editor.
 *
 * Auto-loads all wp_options on open. Sortable columns.
 *
 * @package TSO_Swiss_Knife
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Options_Editor {

	private const HISTORY_OPTION = 'tsosk_oe_history';
	private const PER_PAGE       = 100;

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_oe_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_tsosk_oe_get',    array( $this, 'ajax_get' ) );
		add_action( 'wp_ajax_tsosk_oe_save',   array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_oe_add',    array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_tsosk_oe_delete', array( $this, 'ajax_delete' ) );
	}

	/**
	 * Option names that must never be deleted or edited.
	 *
	 * @return string[]
	 */
	public static function get_protected_option_names(): array {
		return array(
			'siteurl', 'home', 'blogname', 'blogdescription',
			'admin_email', 'active_plugins', 'active_sitewide_plugins',
			'template', 'stylesheet',
			'db_version', 'initial_db_version',
			'wp_user_roles',
			'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
			'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
			self::HISTORY_OPTION,
			TSOSK_Activity_Log::OPTION,
			TSOSK_Activity_Log::MIGRATED_OPTION,
			'tsosk_activated', 'tsosk_version',
		);
	}

	/**
	 * Option names that should not be deleted without manual review.
	 *
	 * @return string[]
	 */
	public static function get_caution_option_names(): array {
		return array(
			'blogpublic', 'default_role', 'permalink_structure',
			'rewrite_rules', 'upload_path', 'upload_url_path',
			'users_can_register', 'default_comment_status',
			'show_on_front', 'page_on_front', 'page_for_posts',
			'cron', 'wp_mail_smtp',
		);
	}

	/**
	 * All option names blocked from deletion in cleanup tools.
	 *
	 * @return string[]
	 */
	public static function get_deletion_blocked_option_names(): array {
		return array_values( array_unique( array_merge(
			self::get_protected_option_names(),
			self::get_caution_option_names()
		) ) );
	}

	/**
	 * Admin URL for Options Editor with a pre-filled name filter.
	 *
	 * @param string $search Option name prefix or fragment.
	 * @return string
	 */
	public static function get_admin_url_with_search( string $search ): string {
		return add_query_arg(
			array(
				'page'      => 'tso-swiss-knife',
				'tab'       => 'options-editor',
				'oe_search' => $search,
			),
			admin_url( 'tools.php' )
		);
	}

	private function is_deletion_blocked( string $name ): bool {
		return in_array( $name, self::get_deletion_blocked_option_names(), true );
	}

	private function is_caution_option( string $name ): bool {
		return in_array( $name, $this->get_caution(), true );
	}

	private function get_protected(): array {
		return self::get_protected_option_names();
	}

	private function get_caution(): array {
		return self::get_caution_option_names();
	}

	/**
	 * Whether an option is a WordPress core protected name.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_protected_core( string $name ): bool {
		return in_array( $name, $this->get_protected(), true );
	}

	/**
	 * Whether an option is a transient stored in wp_options.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_transient_option( string $name ): bool {
		return str_starts_with( $name, '_transient_' )
			|| str_starts_with( $name, '_site_transient_' );
	}

	/**
	 * Whether an option must be hidden from the default browse list.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_hidden_option_name( string $name ): bool {
		return $this->is_protected_core( $name ) || $this->is_transient_option( $name );
	}

	/**
	 * Whitelisted ORDER BY clause for the options list (no user input in SQL).
	 *
	 * @param string $sort_col Column key.
	 * @param string $sort_dir ASC|DESC.
	 * @return string Full ORDER BY … clause.
	 */
	private function get_options_list_order_by_sql( string $sort_col, string $sort_dir ): string {
		$dir = ( 'DESC' === $sort_dir ) ? 'DESC' : 'ASC';

		if ( 'type' === $sort_col ) {
			return "ORDER BY CASE
				WHEN option_value REGEXP '^(a:[0-9]+:|s:[0-9]+:|i:|b:[01];|O:[0-9]+:|N;|d:|C:[0-9]+:)' THEN 'serialized'
				WHEN option_value REGEXP '^[{\\[]' THEN 'json'
				WHEN option_value REGEXP '^[0-9]+\$' THEN 'integer'
				ELSE 'text'
			END {$dir}, option_name ASC";
		}

		if ( 'size' === $sort_col || 'preview' === $sort_col ) {
			return "ORDER BY LENGTH(option_value) {$dir}, option_name ASC";
		}

		if ( 'autoload' === $sort_col ) {
			return "ORDER BY autoload {$dir}, option_name ASC";
		}

		return "ORDER BY option_name {$dir}";
	}

	/**
	 * Append type-filter SQL fragments and prepare args for the options list.
	 *
	 * @param string            $filter_type Filter key.
	 * @param string            $where       WHERE clause (by ref).
	 * @param array<int, mixed> $args        Prepare args (by ref).
	 */
	private function append_options_type_filter_sql( string $filter_type, string &$where, array &$args ): void {
		$serialized = '^(a:[0-9]+:|s:[0-9]+:|i:|b:[01];|O:[0-9]+:|N;|d:|C:[0-9]+:)';
		$json       = '^[{\\[]';
		$integer    = '^[0-9]+$';
		$text_skip  = '^(a:[0-9]+:|s:[0-9]+:|i:|b:[01];|O:[0-9]+:|N;|d:|C:[0-9]+:|[{\\[])';

		if ( 'serialized' === $filter_type ) {
			$where .= ' AND option_value REGEXP %s';
			$args[] = $serialized;
		} elseif ( 'json' === $filter_type ) {
			$where .= ' AND option_value REGEXP %s';
			$args[] = $json;
		} elseif ( 'integer' === $filter_type ) {
			$where .= ' AND option_value REGEXP %s AND LENGTH(option_value) < 20';
			$args[] = $integer;
		} elseif ( 'text' === $filter_type ) {
			$where .= ' AND option_value NOT REGEXP %s AND NOT (option_value REGEXP %s AND LENGTH(option_value) < 20)';
			$args[] = $text_skip;
			$args[] = $integer;
		}
	}

	/**
	 * Append transient / protected-option exclusions with prepare placeholders.
	 *
	 * @param bool              $show_protected Include protected names.
	 * @param string            $where          WHERE clause (by ref).
	 * @param array<int, mixed> $args           Prepare args (by ref).
	 */
	private function append_options_exclusion_sql( bool $show_protected, string &$where, array &$args ): void {
		global $wpdb;

		$where .= ' AND option_name NOT LIKE %s AND option_name NOT LIKE %s';
		$args[] = $wpdb->esc_like( '_transient_' ) . '%';
		$args[] = $wpdb->esc_like( '_site_transient_' ) . '%';

		if ( $show_protected ) {
			return;
		}

		$protected = $this->get_protected();
		if ( array() === $protected ) {
			return;
		}

		$where .= ' AND option_name NOT IN (' . implode( ',', array_fill( 0, count( $protected ), '%s' ) ) . ')';
		foreach ( $protected as $name ) {
			$args[] = $name;
		}
	}

	/**
	 * Check whether an option row exists in wp_options.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function option_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		return null !== $option_id && '' !== $option_id;
	}

	/**
	 * Validate and normalize a new option value for storage.
	 *
	 * @param string $raw        Raw value from the form.
	 * @param string $value_type Value type slug.
	 * @return string|WP_Error
	 */
	private function prepare_new_option_value( string $raw, string $value_type ) {
		$value_type = sanitize_key( $value_type );

		switch ( $value_type ) {
			case 'integer':
				$raw = trim( $raw );
				if ( '' === $raw || ! preg_match( '/^-?\d+$/', $raw ) ) {
					return new WP_Error(
						'invalid_integer',
						__( 'Integer values must be whole numbers (for example: 0, 42, -3).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				return (string) (int) $raw;

			case 'json':
				$raw = trim( $raw );
				if ( '' === $raw ) {
					return new WP_Error(
						'invalid_json',
						__( 'JSON value cannot be empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				json_decode( $raw, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'invalid_json',
						__( 'The value is not valid JSON.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				return $raw;

			case 'serialized':
				$raw = trim( $raw );
				if ( '' === $raw ) {
					return new WP_Error(
						'invalid_serialized',
						__( 'Serialized value cannot be empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				if ( ! $this->looks_serialized( $raw ) ) {
					return new WP_Error(
						'invalid_serialized',
						__( 'The value does not look like valid serialized PHP.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$test = @unserialize( $raw );
				if ( false === $test && $raw !== serialize( false ) ) {
					return new WP_Error(
						'invalid_serialized',
						__( 'The value looks like serialized PHP but is not valid.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					);
				}
				return $raw;

			case 'text':
			default:
				return $raw;
		}
	}

	public function ajax_search(): void {
		check_ajax_referer( 'tsosk_oe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		global $wpdb;

		$search           = isset( $_POST['search'] )        ? sanitize_text_field( wp_unslash( $_POST['search'] ) )        : '';
		$page             = max( 1, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1 );
		$sort_col    = sanitize_key( wp_unslash( $_POST['sort_col']  ?? 'option_name' ) );
		$sort_dir    = strtoupper( sanitize_key( wp_unslash( $_POST['sort_dir']  ?? 'ASC' ) ) ) === 'DESC' ? 'DESC' : 'ASC';
		$filter_type = sanitize_key( wp_unslash( $_POST['filter_type'] ?? '' ) );
		$show_protected = ! empty( $_POST['show_protected'] );
		$offset      = ( $page - 1 ) * self::PER_PAGE;

		$allowed_cols = array( 'option_name', 'autoload', 'size', 'type', 'preview' );
		if ( ! in_array( $sort_col, $allowed_cols, true ) ) {
			$sort_col = 'option_name';
		}

		$caution = $this->get_caution();
		$where   = 'WHERE 1=1';
		$args    = array();

		if ( $search ) {
			$where .= ' AND option_name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$this->append_options_type_filter_sql( $filter_type, $where, $args );
		$this->append_options_exclusion_sql( $show_protected, $where, $args );

		$orderby = $this->get_options_list_order_by_sql( $sort_col, $sort_dir );

		// $where / $orderby: whitelist SQL fragments; values use %s/%d via prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} {$where}", ...$args ) );

		$list_args   = $args;
		$list_args[] = self::PER_PAGE;
		$list_args[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} {$where} {$orderby} LIMIT %d OFFSET %d",
				...$list_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$name         = (string) $row['option_name'];
			$value        = (string) $row['option_value'];
			$type         = $this->detect_type( $value );
			$is_caution = in_array( $name, $caution, true );
			$is_protected = $this->is_protected_core( $name );

			$items[] = array(
				'name'      => $name,
				'type'      => $type,
				'size'      => strlen( $value ),
				'autoload'  => $row['autoload'],
				'preview'   => $this->make_preview( $value, $type ),
				'caution'   => $is_caution,
				'protected' => $is_protected,
			);
		}

		wp_send_json_success( array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => self::PER_PAGE,
			'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
		) );
	}

	public function ajax_get(): void {
		check_ajax_referer( 'tsosk_oe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $name ) {
			wp_send_json_error( __( 'Invalid option name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_transient_option( $name ) ) {
			wp_send_json_error( __( 'Transient options cannot be edited here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( __( 'Option not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$value = (string) $row['option_value'];
		$type  = $this->detect_type( $value );

		wp_send_json_success( array(
			'name'      => $row['option_name'],
			'raw'       => $value,
			'pretty'    => $this->pretty_value( $value, $type ),
			'type'      => $type,
			'autoload'  => $row['autoload'],
			'size'      => strlen( $value ),
			'protected' => $this->is_protected_core( $name ),
			'caution'   => in_array( $name, $this->get_caution(), true ),
		) );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_oe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$raw_val = TSOSK_Support::get_post_scalar( 'value' );
		$autoload = isset( $_POST['autoload'] ) ? sanitize_key( wp_unslash( $_POST['autoload'] ) ) : 'no';
		$autoload = in_array( $autoload, array( 'yes', 'no', 'on', 'off', '1', '0', 'true', 'false' ), true ) ? $autoload : 'no';

		if ( ! $name ) {
			wp_send_json_error( __( 'Invalid option name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_protected_core( $name ) ) {
			wp_send_json_error( __( 'This option is protected and cannot be edited here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_caution_option( $name ) ) {
			wp_send_json_error( __( 'This option is marked as caution and cannot be edited here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->looks_serialized( $raw_val ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$test = @unserialize( $raw_val );
			if ( false === $test && $raw_val !== serialize( false ) ) {
				wp_send_json_error( __( 'The value looks like serialized PHP but is not valid. Please fix the syntax or paste plain text instead.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$old_value = get_option( $name );
		$old_raw   = is_string( $old_value ) ? $old_value : serialize( $old_value );

		update_option( $name, $raw_val, $autoload );
		$this->log_activity( array(
			'action'   => 'update',
			'name'     => $name,
			'old'      => mb_substr( TSOSK_Support::sanitize_stored_scalar( $old_raw ), 0, 200 ),
			'new'      => mb_substr( $raw_val, 0, 200 ),
			'autoload' => $autoload,
			'time'     => time(),
			'user'     => wp_get_current_user()->user_login,
		) );

		wp_send_json_success( __( 'Option saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function ajax_add(): void {
		check_ajax_referer( 'tsosk_oe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$raw_val   = TSOSK_Support::get_post_scalar( 'value' );
		$value_type = sanitize_key( wp_unslash( $_POST['value_type'] ?? 'text' ) );
		$autoload  = isset( $_POST['autoload'] ) ? sanitize_key( wp_unslash( $_POST['autoload'] ) )         : 'no';
		$autoload  = in_array( $autoload, array( 'yes', 'no' ), true ) ? $autoload : 'no';

		if ( ! in_array( $value_type, array( 'text', 'integer', 'json', 'serialized' ), true ) ) {
			$value_type = 'text';
		}

		if ( ! $name || ! preg_match( '/^[a-z0-9_\-]+$/i', $name ) ) {
			wp_send_json_error( __( 'Invalid option name. Use only letters, numbers, hyphens and underscores.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_hidden_option_name( $name ) ) {
			wp_send_json_error( __( 'This option name is reserved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->option_exists( $name ) ) {
			wp_send_json_error( __( 'An option with this name already exists. Use the editor to update it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$prepared_value = $this->prepare_new_option_value( $raw_val, $value_type );
		if ( is_wp_error( $prepared_value ) ) {
			wp_send_json_error( $prepared_value->get_error_message() );
		}

		add_option( $name, $prepared_value, '', $autoload );
		$this->log_activity( array(
			'action'   => 'add',
			'name'     => $name,
			'old'      => '',
			'new'      => mb_substr( $prepared_value, 0, 200 ),
			'autoload' => $autoload,
			'time'     => time(),
			'user'     => wp_get_current_user()->user_login,
		) );

		wp_send_json_success( __( 'Option added.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_oe_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $name ) {
			wp_send_json_error( __( 'Invalid option name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_deletion_blocked( $name ) ) {
			wp_send_json_error( __( 'This option is protected and cannot be deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$old_value = get_option( $name );
		$old_raw   = is_string( $old_value ) ? $old_value : maybe_serialize( $old_value );

		delete_option( $name );
		$this->log_activity( array(
			'action'   => 'delete',
			'name'     => $name,
			'old'      => mb_substr( (string) $old_raw, 0, 200 ),
			'new'      => '',
			'autoload' => '',
			'time'     => time(),
			'user'     => wp_get_current_user()->user_login,
		) );

		wp_send_json_success( __( 'Option deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	private function detect_type( string $value ): string {
		if ( $this->looks_serialized( $value ) ) { return 'serialized'; }
		if ( strlen( $value ) > 1 && in_array( $value[0], array( '{', '[' ), true ) ) {
			if ( null !== json_decode( $value, true ) ) { return 'json'; }
		}
		if ( is_numeric( $value ) && strpos( $value, '.' ) === false ) { return 'integer'; }
		return 'text';
	}

	private function looks_serialized( string $value ): bool {
		if ( '' === $value ) { return false; }
		return (bool) preg_match( '/^(s:\d+:".+";|i:\d+;|b:[01];|a:\d+:{|O:\d+:"|N;|d:[\d.E+-]+;|C:\d+:")/s', $value );
	}

	private function make_preview( string $value, string $type ): string {
		if ( '' === $value ) { return '(empty)'; }
		switch ( $type ) {
			case 'serialized':
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$data = @unserialize( $value );
				if ( is_array( $data ) )  { return '[array, ' . count( $data ) . ' keys]'; }
				if ( is_object( $data ) ) { return '[object: ' . get_class( $data ) . ']'; }
				return mb_substr( $value, 0, 60 );
			case 'json':
				$data = json_decode( $value, true );
				if ( is_array( $data ) ) { return '[JSON, ' . count( $data ) . ' keys]'; }
				return mb_substr( $value, 0, 60 );
			default:
				$preview = mb_substr( $value, 0, 80 );
				return strlen( $value ) > 80 ? $preview . '…' : $preview;
		}
	}

	private function pretty_value( string $value, string $type ): string {
		if ( 'serialized' === $type ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$data = @unserialize( $value );
			if ( false !== $data || $value === serialize( false ) ) {
				return $this->export_var( $data );
			}
		}
		if ( 'json' === $type ) {
			$data = json_decode( $value, true );
			if ( null !== $data ) {
				return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		return $value;
	}

	private function export_var( $var, int $depth = 0 ): string {
		$indent = str_repeat( '  ', $depth );
		if ( is_null( $var ) )   { return 'null'; }
		if ( is_bool( $var ) )   { return $var ? 'true' : 'false'; }
		if ( is_int( $var ) || is_float( $var ) ) { return (string) $var; }
		if ( is_string( $var ) ) { return '"' . addcslashes( $var, '"\\' ) . '"'; }
		if ( is_array( $var ) ) {
			if ( empty( $var ) ) { return '[]'; }
			$is_list = array_keys( $var ) === range( 0, count( $var ) - 1 );
			$items   = array();
			foreach ( $var as $k => $v ) {
				$key_str = $is_list ? '' : $this->export_var( $k ) . ': ';
				$items[] = $indent . '  ' . $key_str . $this->export_var( $v, $depth + 1 );
			}
			return "[\n" . implode( ",\n", $items ) . "\n" . $indent . ']';
		}
		if ( is_object( $var ) ) { return '{object: ' . get_class( $var ) . '}'; }
		return '?';
	}

	private function log_activity( array $entry ): void {
		$name   = (string) ( $entry['name'] ?? '' );
		$action = (string) ( $entry['action'] ?? 'update' );
		TSOSK_Activity_Log::log(
			'options-editor',
			$action,
			sprintf(
				/* translators: 1: action, 2: option name */
				__( 'Option %1$s: %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				TSOSK_Activity_Log::action_label( $action ),
				$name
			),
			array(
				'name' => $name,
				'old'  => (string) ( $entry['old'] ?? '' ),
				'new'  => (string) ( $entry['new'] ?? '' ),
			)
		);
	}

	public function render(): void {
		global $wpdb;

		$nonce         = wp_create_nonce( 'tsosk_oe_nonce' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Browse and edit options stored in wp_options. The list loads automatically in alphabetical order. Protected core options and transients are hidden by default.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-warn">
			<strong><?php esc_html_e( '⚠ Use with caution.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php esc_html_e( 'Editing wp_options directly can break your site if critical values are changed incorrectly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>

		<div class="tsosk-toolbar" style="gap:12px;margin-bottom:16px;">
			<span class="tsosk-badge tsosk-badge-info">
				<?php /* translators: %d: number of options in database */
			printf( esc_html__( '%d options in database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $total_options ); ?>
			</span>
		</div>

		<div class="tsosk-oe-tabs" id="tsosk-oe-tabs">
			<button class="button tsosk-oe-tab-btn is-active" data-tab="list"><?php esc_html_e( 'Browse & Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			<button class="button tsosk-oe-tab-btn" data-tab="add"><?php esc_html_e( 'Add New Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
		</div>

		<?php /* ── Browse & Edit ── */ ?>
		<div class="tsosk-oe-panel" id="tsosk-oe-panel-list">
			<div class="tsosk-card">
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="text" id="tsosk-oe-search"
					       placeholder="<?php esc_attr_e( 'Filter by option name…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
					       style="min-width:220px;" autocomplete="off">
					<select id="tsosk-oe-filter-type" style="min-width:130px;">
						<option value=""><?php esc_html_e( 'All types', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="serialized"><?php esc_html_e( 'Serialized PHP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="json"><?php esc_html_e( 'JSON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="integer"><?php esc_html_e( 'Integer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="text"><?php esc_html_e( 'Text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					</select>
					<button class="button" id="tsosk-oe-do-search" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
					<button type="button" class="button" id="tsosk-oe-toggle-protected" aria-pressed="false"><?php esc_html_e( 'Show protected options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
					<button class="button button-small" id="tsosk-oe-clear-filters" title="<?php esc_attr_e( 'Clear all filters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">✕</button>
					<span class="tsosk-ajax-msg" id="tsosk-oe-search-msg"></span>
				</div>
				<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Options are sorted alphabetically by name. Filter by name or type. Click a column header to sort by size, autoload, or type.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>

			<div id="tsosk-oe-results">
				<div id="tsosk-oe-pagination-top" class="tsosk-oe-pagination"></div>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-oe-table">
						<thead>
							<tr>
								<th class="tsosk-oe-sortable" data-col="option_name" style="width:32%;cursor:pointer;">
									<?php esc_html_e( 'Option Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <span class="tsosk-sort-icon">▲</span>
								</th>
								<th class="tsosk-oe-sortable" data-col="size" style="width:7%;cursor:pointer;">
									<?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <span class="tsosk-sort-icon"></span>
								</th>
								<th class="tsosk-oe-sortable" data-col="autoload" style="width:9%;cursor:pointer;">
									<?php esc_html_e( 'Autoload', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <span class="tsosk-sort-icon"></span>
								</th>
								<th class="tsosk-oe-sortable" data-col="type" style="width:9%;cursor:pointer;"><?php esc_html_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <span class="tsosk-sort-icon"></span></th>
								<th class="tsosk-oe-sortable" data-col="preview" style="cursor:pointer;"><?php esc_html_e( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <span class="tsosk-sort-icon"></span></th>
								<th style="width:110px;"><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody id="tsosk-oe-tbody">
							<tr><td colspan="6" style="text-align:center;padding:16px;color:#666;">
								<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
								<?php esc_html_e( 'Loading options…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</td></tr>
						</tbody>
					</table>
				</div>
				<div id="tsosk-oe-pagination-bottom" class="tsosk-oe-pagination"></div>
			</div>
		</div>

		<?php /* ── Inline editor ── */ ?>
		<div id="tsosk-oe-editor" style="display:none;" class="tsosk-card">
			<h3 id="tsosk-oe-editor-title"><?php esc_html_e( 'Edit Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div id="tsosk-oe-caution-banner" class="tsosk-notice tsosk-notice-warn" style="display:none;">
				<strong><?php esc_html_e( 'Caution:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'This option affects core WordPress behavior. Edit carefully and test after saving.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<div id="tsosk-oe-protected-banner" class="tsosk-notice tsosk-notice-info" style="display:none;">
				<strong><?php esc_html_e( 'Protected option.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'This option is protected. You can view it but not change or delete it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<div id="tsosk-oe-serialized-note" class="tsosk-notice tsosk-notice-info" style="display:none;">
				<strong><?php esc_html_e( 'Serialized PHP detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'The editor always works with the raw serialized string — do not modify unless you know PHP serialization syntax exactly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<div style="margin-top:8px;">
					<button type="button" class="button button-small" id="tsosk-oe-toggle-view"><?php esc_html_e( 'Show raw value', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				</div>
			</div>
			<div id="tsosk-oe-json-note" class="tsosk-notice tsosk-notice-info" style="display:none;">
				<strong><?php esc_html_e( 'JSON detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Any change must remain valid JSON syntax.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<div style="margin-bottom:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<label style="font-size:13px;">
					<?php esc_html_e( 'Option:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<strong id="tsosk-oe-editing-name" class="tsosk-code"></strong>
				</label>
				<label style="font-size:13px;">
					<?php esc_html_e( 'Type:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<span id="tsosk-oe-editing-type" class="tsosk-badge tsosk-badge-info" style="font-size:11px;"></span>
				</label>
				<label style="font-size:13px;">
					<?php esc_html_e( 'Autoload:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<select id="tsosk-oe-editing-autoload" style="margin-left:4px;">
						<option value="yes"><?php esc_html_e( 'yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<option value="no"><?php esc_html_e( 'no', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					</select>
				</label>
			</div>
			<div style="position:relative;">
				<textarea id="tsosk-oe-value"
				          style="width:100%;min-height:200px;font-family:monospace;font-size:12px;line-height:1.5;resize:vertical;tab-size:2;"
				          spellcheck="false"></textarea>
				<div id="tsosk-oe-raw-preview"
				     style="display:none;background:#f6f7f7;border:1px solid #ddd;border-radius:3px;padding:12px;font-family:monospace;font-size:11px;white-space:pre;overflow:auto;max-height:300px;margin-top:8px;color:#1d2327;"></div>
			</div>
			<div style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
				<button class="button button-primary" id="tsosk-oe-save-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Save Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button class="button" id="tsosk-oe-cancel-btn"><?php esc_html_e( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-oe-save-msg"></span>
			</div>
		</div>

		<?php /* ── Add New Option ── */ ?>
		<div class="tsosk-oe-panel" id="tsosk-oe-panel-add" style="display:none;">
			<div class="tsosk-card">
				<h3><?php esc_html_e( 'Add New Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
				<div class="tsosk-notice tsosk-notice-info">
					<?php esc_html_e( 'Adds a new row directly to wp_options. Use only for custom plugin options or development purposes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
				<table class="tsosk-kv-table" style="width:100%;max-width:640px;">
					<tr>
						<th style="width:160px;"><?php esc_html_e( 'Option Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<input type="text" id="tsosk-oe-new-name" placeholder="my_custom_option" style="width:100%;max-width:380px;" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Letters, numbers, hyphens and underscores only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Value type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<select id="tsosk-oe-new-value-type">
								<option value="text"><?php esc_html_e( 'Text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
								<option value="integer"><?php esc_html_e( 'Integer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
								<option value="json"><?php esc_html_e( 'JSON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
								<option value="serialized"><?php esc_html_e( 'Serialized PHP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							</select>
							<p class="description" id="tsosk-oe-new-value-hint"><?php esc_html_e( 'Plain text value.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th style="vertical-align:top;padding-top:10px;"><?php esc_html_e( 'Value', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<textarea id="tsosk-oe-new-value" rows="5" style="width:100%;font-family:monospace;font-size:12px;resize:vertical;" spellcheck="false"></textarea>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Autoload', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<select id="tsosk-oe-new-autoload">
								<option value="no"><?php esc_html_e( 'no (recommended for custom options)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
								<option value="yes"><?php esc_html_e( 'yes (loaded on every request)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
					<button class="button button-primary" id="tsosk-oe-add-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Add Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
					<span class="tsosk-ajax-msg" id="tsosk-oe-add-msg"></span>
				</div>
			</div>
		</div>

		<?php
	}
}
