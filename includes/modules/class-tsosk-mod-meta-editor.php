<?php
/**
 * TSO Swiss Knife – Module: Post & User Meta Editor.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Meta_Editor
 */
class TSOSK_Mod_Meta_Editor {

	private const PER_PAGE = 80;

	/** @var TSOSK_Mod_Meta_Editor|null */
	private static $instance = null;

	/**
	 * @return TSOSK_Mod_Meta_Editor
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_me_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_tsosk_me_get', array( $this, 'ajax_get' ) );
		add_action( 'wp_ajax_tsosk_me_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_me_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_tsosk_me_add', array( $this, 'ajax_add' ) );
	}

	/**
	 * Meta keys that must not be edited or deleted.
	 *
	 * @param string $context post|user.
	 * @return string[]
	 */
	public static function get_protected_keys( string $context ): array {
		$post_keys = array(
			'_edit_lock',
			'_edit_last',
		);
		$user_keys = array(
			'session_tokens',
			'capabilities',
			'user_level',
			'wp_capabilities',
			'wp_user_level',
			'dashboard_quick_press_last_post_id',
		);
		return 'user' === $context ? $user_keys : $post_keys;
	}

	/**
	 * @param string $key     Meta key.
	 * @param string $context post|user.
	 * @return bool
	 */
	public static function is_protected_key( string $key, string $context ): bool {
		if ( in_array( $key, self::get_protected_keys( $context ), true ) ) {
			return true;
		}
		if ( 'user' === $context ) {
			global $wpdb;
			$prefix = $wpdb->get_blog_prefix();
			if ( $key === $prefix . 'capabilities' || $key === $prefix . 'user_level' ) {
				return true;
			}
			if ( preg_match( '/_(capabilities|user_level)$/', $key ) ) {
				return true;
			}
		}
		$blocked = array( 'license', 'api_key', 'api_secret', 'secret_key', 'private_key', 'password' );
		$lower   = strtolower( $key );
		foreach ( $blocked as $fragment ) {
			if ( str_contains( $lower, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string post|user
	 */
	private function sanitize_context(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in ajax_search() before this helper runs.
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'post';
		return 'user' === $context ? 'user' : 'post';
	}

	public function ajax_search(): void {
		check_ajax_referer( 'tsosk_me_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		global $wpdb;

		$context   = $this->sanitize_context();
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$object_id = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0;
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$offset    = ( $page - 1 ) * self::PER_PAGE;

		if ( 'user' === $context ) {
			$table      = $wpdb->usermeta;
			$id_col     = 'user_id';
			$meta_id    = 'umeta_id';
		} else {
			$table      = $wpdb->postmeta;
			$id_col     = 'post_id';
			$meta_id    = 'meta_id';
		}

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $object_id > 0 ) {
			$where   .= " AND {$id_col} = %d";
			$params[] = $object_id;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (meta_key LIKE %s OR meta_value LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column names are internal; values use prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		if ( $params ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT {$meta_id} AS meta_id, {$id_col} AS object_id, meta_key, meta_value
			FROM {$table} {$where}
			ORDER BY {$meta_id} DESC
			LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$items = array();
		foreach ( (array) $rows as $row ) {
			$key       = (string) $row['meta_key'];
			$value     = (string) $row['meta_value'];
			$protected = self::is_protected_key( $key, $context );
			$items[]   = array(
				'meta_id'   => (int) $row['meta_id'],
				'object_id' => (int) $row['object_id'],
				'key'       => $key,
				'size'      => strlen( $value ),
				'preview'   => $this->preview( $value ),
				'protected' => $protected,
			);
		}

		wp_send_json_success(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => self::PER_PAGE,
				'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			)
		);
	}

	public function ajax_get(): void {
		check_ajax_referer( 'tsosk_me_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		global $wpdb;
		$context = $this->sanitize_context();
		$meta_id = absint( $_POST['meta_id'] ?? 0 );
		if ( ! $meta_id ) {
			wp_send_json_error( __( 'Invalid meta ID.', 'tso-swiss-knife' ) );
		}

		if ( 'user' === $context ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT umeta_id AS meta_id, user_id AS object_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE umeta_id = %d",
					$meta_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT meta_id, post_id AS object_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d",
					$meta_id
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			wp_send_json_error( __( 'Meta row not found.', 'tso-swiss-knife' ) );
		}

		$key = (string) $row['meta_key'];
		wp_send_json_success(
			array(
				'meta_id'   => (int) $row['meta_id'],
				'object_id' => (int) $row['object_id'],
				'key'       => $key,
				'value'     => (string) $row['meta_value'],
				'protected' => self::is_protected_key( $key, $context ),
			)
		);
	}

	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_me_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$context = $this->sanitize_context();
		$meta_id = absint( $_POST['meta_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- meta values may be serialized.
		$value   = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		if ( ! $meta_id ) {
			wp_send_json_error( __( 'Invalid meta ID.', 'tso-swiss-knife' ) );
		}

		global $wpdb;
		if ( 'user' === $context ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_key FROM {$wpdb->usermeta} WHERE umeta_id = %d",
					$meta_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id = %d",
					$meta_id
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			wp_send_json_error( __( 'Meta row not found.', 'tso-swiss-knife' ) );
		}

		$key = (string) $row['meta_key'];
		if ( self::is_protected_key( $key, $context ) ) {
			wp_send_json_error( __( 'This meta key is protected and cannot be edited.', 'tso-swiss-knife' ) );
		}

		if ( 'user' === $context ) {
			update_user_meta( (int) $row['user_id'], $key, $value );
		} else {
			update_post_meta( (int) $row['post_id'], $key, $value );
		}

		TSOSK_Activity_Log::log(
			'meta-editor',
			'update',
			sprintf(
				/* translators: %s: meta key */
				__( 'Meta updated: %s.', 'tso-swiss-knife' ),
				$key
			),
			array( 'key' => $key )
		);

		wp_send_json_success( __( 'Meta value saved.', 'tso-swiss-knife' ) );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_me_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$context = $this->sanitize_context();
		$meta_id = absint( $_POST['meta_id'] ?? 0 );
		if ( ! $meta_id ) {
			wp_send_json_error( __( 'Invalid meta ID.', 'tso-swiss-knife' ) );
		}

		global $wpdb;
		if ( 'user' === $context ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$key = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_key FROM {$wpdb->usermeta} WHERE umeta_id = %d",
					$meta_id
				)
			);
			if ( ! $key || self::is_protected_key( (string) $key, $context ) ) {
				wp_send_json_error( __( 'This meta key is protected and cannot be deleted.', 'tso-swiss-knife' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $meta_id ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$key = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_id = %d",
					$meta_id
				)
			);
			if ( ! $key || self::is_protected_key( (string) $key, $context ) ) {
				wp_send_json_error( __( 'This meta key is protected and cannot be deleted.', 'tso-swiss-knife' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $meta_id ), array( '%d' ) );
		}

		TSOSK_Activity_Log::log(
			'meta-editor',
			'delete',
			sprintf(
				/* translators: %s: meta key */
				__( 'Meta deleted: %s.', 'tso-swiss-knife' ),
				(string) $key
			),
			array( 'key' => (string) $key )
		);

		wp_send_json_success( __( 'Meta row deleted.', 'tso-swiss-knife' ) );
	}

	public function ajax_add(): void {
		check_ajax_referer( 'tsosk_me_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$context   = $this->sanitize_context();
		$object_id = absint( $_POST['object_id'] ?? 0 );
		$key       = isset( $_POST['meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_key'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value     = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		if ( ! $object_id || ! $key ) {
			wp_send_json_error( __( 'Object ID and meta key are required.', 'tso-swiss-knife' ) );
		}
		if ( self::is_protected_key( $key, $context ) ) {
			wp_send_json_error( __( 'This meta key cannot be added (protected pattern).', 'tso-swiss-knife' ) );
		}

		if ( 'user' === $context ) {
			if ( ! get_userdata( $object_id ) ) {
				wp_send_json_error( __( 'User not found.', 'tso-swiss-knife' ) );
			}
			add_user_meta( $object_id, $key, $value, false );
		} else {
			if ( ! get_post( $object_id ) ) {
				wp_send_json_error( __( 'Post not found.', 'tso-swiss-knife' ) );
			}
			add_post_meta( $object_id, $key, $value, false );
		}

		TSOSK_Activity_Log::log(
			'meta-editor',
			'add',
			sprintf(
				/* translators: %s: meta key */
				__( 'Meta added: %s.', 'tso-swiss-knife' ),
				$key
			),
			array( 'key' => $key )
		);

		wp_send_json_success( __( 'Meta row added.', 'tso-swiss-knife' ) );
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	private function preview( string $value ): string {
		if ( strlen( $value ) > 120 ) {
			return substr( $value, 0, 120 ) . '…';
		}
		return $value;
	}

	/**
	 * Render intro guide for the Meta Editor tab.
	 */
	private function render_guide(): void {
		?>
		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'What is meta data?', 'tso-swiss-knife' ); ?></h3>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'Meta is extra information stored in the database for each post or user — not visible in the normal editor. Plugins and themes use it for settings, layout data, SEO fields, featured images, and much more.', 'tso-swiss-knife' ); ?>
			</p>

			<div class="tsosk-guide-grid">
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'Post meta vs user meta', 'tso-swiss-knife' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Post meta — attached to a post, page, or custom post type (ID = post ID).', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'User meta — attached to a WordPress user account (ID = user ID).', 'tso-swiss-knife' ); ?></li>
					</ul>
				</div>
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'When to use this tool', 'tso-swiss-knife' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Inspect what a plugin stored for a specific post or user.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Fix a wrong value (e.g. featured image ID) when you know exactly what to change.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Remove leftover meta from an uninstalled plugin.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Debug issues with page builders (Elementor), ACF, or SEO plugins.', 'tso-swiss-knife' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="tsosk-guide-block">
				<h4><?php esc_html_e( 'How to use it (recommended order)', 'tso-swiss-knife' ); ?></h4>
				<ol class="tsosk-guide-steps">
					<li><?php esc_html_e( 'Choose Post meta or User meta.', 'tso-swiss-knife' ); ?></li>
					<li><?php esc_html_e( 'Optional: enter a Post/User ID to limit results to one item.', 'tso-swiss-knife' ); ?></li>
					<li><?php esc_html_e( 'Optional: filter by meta key or part of the value.', 'tso-swiss-knife' ); ?></li>
					<li><?php esc_html_e( 'Click Search, then Edit on a row to view or change the full value.', 'tso-swiss-knife' ); ?></li>
					<li><?php esc_html_e( 'Use Add meta row only when you know the exact key and value a plugin expects.', 'tso-swiss-knife' ); ?></li>
				</ol>
			</div>

			<div class="tsosk-me-examples">
				<strong><?php esc_html_e( 'Common meta keys (examples)', 'tso-swiss-knife' ); ?></strong>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Key', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Used for', 'tso-swiss-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>_thumbnail_id</code></td>
							<td><?php esc_html_e( 'Featured image — value is the attachment post ID.', 'tso-swiss-knife' ); ?></td>
						</tr>
						<tr>
							<td><code>_elementor_data</code></td>
							<td><?php esc_html_e( 'Elementor page layout (serialized JSON — edit with extreme care).', 'tso-swiss-knife' ); ?></td>
						</tr>
						<tr>
							<td><code>_yoast_wpseo_title</code></td>
							<td><?php esc_html_e( 'Yoast SEO custom title for the post.', 'tso-swiss-knife' ); ?></td>
						</tr>
						<tr>
							<td><code>nickname</code></td>
							<td><?php esc_html_e( 'User display nickname (user meta).', 'tso-swiss-knife' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render field help for search controls.
	 */
	private function render_field_help(): void {
		?>
		<div class="tsosk-field-help-list">
			<p><strong><?php esc_html_e( 'Post meta / User meta', 'tso-swiss-knife' ); ?>:</strong>
				<?php esc_html_e( 'Which table to search — postmeta for content, usermeta for accounts.', 'tso-swiss-knife' ); ?></p>
			<p><strong><?php esc_html_e( 'Post/User ID', 'tso-swiss-knife' ); ?>:</strong>
				<?php esc_html_e( 'Leave empty to search across the whole site, or enter one ID to see only that post or user.', 'tso-swiss-knife' ); ?></p>
			<p><strong><?php esc_html_e( 'Filter', 'tso-swiss-knife' ); ?>:</strong>
				<?php esc_html_e( 'Search in meta key names and values — e.g. “elementor” or “_thumbnail”.', 'tso-swiss-knife' ); ?></p>
			<p><strong><?php esc_html_e( 'Protected keys', 'tso-swiss-knife' ); ?>:</strong>
				<?php esc_html_e( 'Session tokens, capabilities, and keys containing “license” or “api_key” cannot be edited — this prevents breaking logins or licenses.', 'tso-swiss-knife' ); ?></p>
		</div>
		<?php
	}

	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_me_nonce' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'View and edit hidden post and user data (meta) stored in the database — useful for debugging plugins and fixing specific values.', 'tso-swiss-knife' ); ?>
		</p>

		<?php $this->render_guide(); ?>

		<div class="tsosk-notice tsosk-notice-warn" style="border-left-color:#d63638;">
			<strong><?php esc_html_e( 'Advanced tool — proceed with caution.', 'tso-swiss-knife' ); ?></strong>
			<?php esc_html_e( 'Wrong values can break a page, user account, or page builder layout. Make a backup before editing. Prefer each plugin’s own settings screen when possible.', 'tso-swiss-knife' ); ?>
		</div>

		<div class="tsosk-card tsosk-me-search-card">
			<h3><?php esc_html_e( 'Search meta rows', 'tso-swiss-knife' ); ?></h3>

			<div class="tsosk-me-toolbar">
				<div class="tsosk-me-field">
					<label for="tsosk-me-context"><?php esc_html_e( 'Type', 'tso-swiss-knife' ); ?></label>
					<select id="tsosk-me-context">
						<option value="post"><?php esc_html_e( 'Post meta', 'tso-swiss-knife' ); ?></option>
						<option value="user"><?php esc_html_e( 'User meta', 'tso-swiss-knife' ); ?></option>
					</select>
				</div>
				<div class="tsosk-me-field">
					<label for="tsosk-me-object-id"><?php esc_html_e( 'Post/User ID', 'tso-swiss-knife' ); ?></label>
					<input type="number" id="tsosk-me-object-id" min="0" step="1"
					       placeholder="<?php esc_attr_e( 'All (optional)', 'tso-swiss-knife' ); ?>"
					       style="width:140px;">
					<span class="tsosk-me-field-hint"><?php esc_html_e( 'One post or user only', 'tso-swiss-knife' ); ?></span>
				</div>
				<div class="tsosk-me-field" style="flex:1;min-width:200px;">
					<label for="tsosk-me-search"><?php esc_html_e( 'Filter', 'tso-swiss-knife' ); ?></label>
					<input type="text" id="tsosk-me-search"
					       placeholder="<?php esc_attr_e( 'Key or value contains…', 'tso-swiss-knife' ); ?>"
					       style="width:100%;" autocomplete="off">
				</div>
				<div class="tsosk-me-field" style="align-self:flex-end;">
					<button type="button" class="button button-primary" id="tsosk-me-search-btn"
					        data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Search', 'tso-swiss-knife' ); ?>
					</button>
				</div>
				<span class="tsosk-ajax-msg" id="tsosk-me-search-msg"></span>
			</div>

			<?php $this->render_field_help(); ?>
		</div>

		<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-me-table">
				<thead>
					<tr>
						<th style="width:8%;"><?php esc_html_e( 'Meta ID', 'tso-swiss-knife' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Post/User ID', 'tso-swiss-knife' ); ?></th>
						<th style="width:28%;"><?php esc_html_e( 'Meta key', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Value preview', 'tso-swiss-knife' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?></th>
					</tr>
				</thead>
				<tbody id="tsosk-me-tbody">
					<tr><td colspan="5" style="text-align:center;color:#646970;"><?php esc_html_e( 'Use Search above to load meta rows from the database.', 'tso-swiss-knife' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div id="tsosk-me-pagination" class="tsosk-oe-pagination"></div>

		<div class="tsosk-card" id="tsosk-me-editor" style="display:none;margin-top:16px;">
			<h3><?php esc_html_e( 'Edit meta value', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'You are editing the raw value stored in the database. Save only if you are sure the new value is correct.', 'tso-swiss-knife' ); ?>
			</p>
			<p><code id="tsosk-me-edit-key"></code> — <?php esc_html_e( 'Post/User ID', 'tso-swiss-knife' ); ?>: <strong id="tsosk-me-edit-object"></strong></p>
			<textarea id="tsosk-me-edit-value" rows="8" style="width:100%;font-family:monospace;"></textarea>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-me-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Save meta', 'tso-swiss-knife' ); ?></button>
				<button type="button" class="button" id="tsosk-me-delete"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Delete row', 'tso-swiss-knife' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-me-edit-msg"></span>
			</p>
		</div>

		<div class="tsosk-card" style="margin-top:16px;">
			<h3><?php esc_html_e( 'Add meta row', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Creates a new meta entry for a post or user. The key must match what the plugin or theme expects.', 'tso-swiss-knife' ); ?>
			</p>
			<div class="tsosk-toolbar" style="flex-wrap:wrap;gap:8px;">
				<input type="number" id="tsosk-me-add-object" min="1" step="1"
				       placeholder="<?php esc_attr_e( 'Post/User ID', 'tso-swiss-knife' ); ?>" style="width:120px;">
				<input type="text" id="tsosk-me-add-key"
				       placeholder="<?php esc_attr_e( 'Meta key', 'tso-swiss-knife' ); ?>" style="min-width:180px;">
				<input type="text" id="tsosk-me-add-value"
				       placeholder="<?php esc_attr_e( 'Value', 'tso-swiss-knife' ); ?>" style="min-width:220px;">
				<button type="button" class="button" id="tsosk-me-add-btn"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Add meta', 'tso-swiss-knife' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-me-add-msg"></span>
			</div>
		</div>
		<?php
	}
}
