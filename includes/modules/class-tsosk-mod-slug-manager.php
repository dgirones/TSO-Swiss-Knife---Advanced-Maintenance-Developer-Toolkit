<?php
/**
 * TSO Swiss Knife – Module: Slug & Permalink Manager.
 *
 * Audits and edits post/page/CPT slugs directly from the admin panel.
 *
 * Features:
 *  – Three audit views: long slugs, duplicate slugs, slugs with special
 *    characters or uppercase letters.
 *  – Inline slug editor: rename any slug without leaving this panel.
 *  – Auto-redirect: when a slug is renamed, a 301 redirect from the old
 *    URL is automatically added to TSO Swiss Knife Redirects so no link
 *    ever breaks. If the Redirects module is not present a warning is shown.
 *  – Post-type filter: scan all public post types or pick one.
 *  – Configurable long-slug threshold (default 50 characters).
 *  – Bulk-fix long slugs: truncates at a word boundary and auto-redirects.
 *
 * All DB access uses $wpdb->prepare(); no direct string interpolation.
 * Outputs are fully escaped with esc_html() / esc_url() / esc_attr().
 *
 * @package TSO_Swiss_Knife
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Slug_Manager
 */
class TSOSK_Mod_Slug_Manager {

	/** Results per page for the audit tables. */
	private const PER_PAGE = 50;

	/** Default long-slug threshold (characters). */
	private const DEFAULT_THRESHOLD = 50;

	/** @var TSOSK_Mod_Slug_Manager|null */
	private static $instance = null;

	/** @return TSOSK_Mod_Slug_Manager */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_sm_rename',        array( $this, 'ajax_rename' ) );
		add_action( 'wp_ajax_tsosk_sm_bulk_preview',  array( $this, 'ajax_bulk_preview' ) );
		add_action( 'wp_ajax_tsosk_sm_bulk_fix',      array( $this, 'ajax_bulk_fix' ) );
		add_action( 'wp_ajax_tsosk_sm_search',       array( $this, 'ajax_search' ) );
	}

	// ── AJAX: rename a single slug ────────────────────────────────────────────

	/**
	 * Rename one post slug and optionally create a 301 redirect for the old URL.
	 */
	public function ajax_rename(): void {
		check_ajax_referer( 'tsosk_sm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$post_id      = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$new_slug_raw = sanitize_text_field( wp_unslash( $_POST['new_slug'] ?? '' ) );
		$do_redirect  = ! empty( $_POST['auto_redirect'] );

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'draft', 'private', 'pending', 'future' ), true ) ) {
			wp_send_json_error( __( 'Post not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You do not have permission to edit this post.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$new_slug = $this->sanitize_slug( $new_slug_raw );
		if ( '' === $new_slug ) {
			wp_send_json_error( __( 'The slug cannot be empty. Use only letters, numbers and hyphens.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$old_slug = $post->post_name;
		if ( $old_slug === $new_slug ) {
			wp_send_json_error( __( 'The new slug is identical to the current one.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Check for duplicates within the same post type.
		$duplicate = $this->slug_exists( $new_slug, (string) $post->post_type, $post_id );
		if ( $duplicate ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: duplicate slug */
					__( 'Slug "%s" is already used by another post of the same type.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$new_slug
				)
			);
		}

		// Store old permalink for redirect BEFORE the update.
		$old_permalink = $do_redirect ? get_permalink( $post_id ) : '';

		// Update the post slug.
		$result = wp_update_post( array(
			'ID'        => $post_id,
			'post_name' => $new_slug,
		), true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$new_permalink = get_permalink( $post_id );

		// Create automatic 301 redirect from old URL if requested.
		$redirect_created = false;
		if ( $do_redirect && $old_permalink && class_exists( 'TSOSK_Mod_Redirects' ) ) {
			$old_path = wp_parse_url( $old_permalink, PHP_URL_PATH );
			if ( $old_path ) {
				$redirect_created = $this->add_redirect( (string) $old_path, (string) $new_permalink );
			}
		}

		$response = array(
			'new_slug'         => $new_slug,
			'new_permalink'    => $new_permalink,
			'redirect_created' => $redirect_created,
			'message'          => $redirect_created
				? __( 'Slug updated and 301 redirect created.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Slug updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		TSOSK_Activity_Log::log(
			'slug-manager',
			'update',
			sprintf(
				/* translators: 1: old slug, 2: new slug */
				__( 'Slug renamed: %1$s → %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$old_slug,
				$new_slug
			)
		);

		wp_send_json_success( $response );
	}

	// ── AJAX: bulk-fix preview ────────────────────────────────────────────────

	/**
	 * Preview bulk slug changes without applying them.
	 */
	public function ajax_bulk_preview(): void {
		check_ajax_referer( 'tsosk_sm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$ids       = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
		$threshold = max( 10, min( 200, isset( $_POST['threshold'] ) ? absint( wp_unslash( $_POST['threshold'] ) ) : self::DEFAULT_THRESHOLD ) );
		$do_redirect = ! empty( $_POST['auto_redirect'] );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No posts selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$preview = $this->compute_bulk_changes( $ids, $threshold );

		wp_send_json_success(
			array(
				'changes'  => $preview['changes'],
				'skipped'  => $preview['skipped'],
				'threshold'=> $threshold,
				'redirect' => $do_redirect && class_exists( 'TSOSK_Mod_Redirects' ),
				'message'  => $this->build_bulk_preview_message( $preview, $threshold, $do_redirect ),
			)
		);
	}

	// ── AJAX: bulk-fix long slugs ─────────────────────────────────────────────

	/**
	 * Truncate a list of post slugs to the threshold and auto-redirect old URLs.
	 */
	public function ajax_bulk_fix(): void {
		check_ajax_referer( 'tsosk_sm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$ids         = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
		$threshold   = max( 10, min( 200, isset( $_POST['threshold'] ) ? absint( wp_unslash( $_POST['threshold'] ) ) : self::DEFAULT_THRESHOLD ) );
		$do_redirect = ! empty( $_POST['auto_redirect'] );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No posts selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$preview = $this->compute_bulk_changes( $ids, $threshold );
		$changes = $preview['changes'];
		$skipped = count( $preview['skipped'] );
		$errors  = array();
		$fixed   = 0;
		$applied = array();

		foreach ( $changes as $change ) {
			$post_id  = (int) $change['id'];
			$post     = get_post( $post_id );
			if ( ! $post ) {
				$skipped++;
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$errors[] = "#{$post_id}: " . __( 'Permission denied.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				continue;
			}

			$old_slug = (string) $change['old_slug'];
			$new_slug = (string) $change['new_slug'];
			$old_permalink = $do_redirect ? get_permalink( $post_id ) : '';

			$result = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $new_slug,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$errors[] = "#{$post_id}: " . $result->get_error_message();
				continue;
			}

			if ( $do_redirect && $old_permalink && class_exists( 'TSOSK_Mod_Redirects' ) ) {
				$old_path = wp_parse_url( $old_permalink, PHP_URL_PATH );
				if ( $old_path ) {
					$this->add_redirect( (string) $old_path, (string) get_permalink( $post_id ) );
				}
			}

			$applied[] = $change;
			$fixed++;
		}

		$redirect_note = $do_redirect && class_exists( 'TSOSK_Mod_Redirects' )
			? __( '301 redirects were created for updated slugs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: '';

		TSOSK_Activity_Log::log(
			'slug-manager',
			'update',
			sprintf(
				/* translators: 1: fixed count, 2: skipped count */
				__( 'Bulk slug fix: %1$d updated, %2$d skipped.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$fixed,
				$skipped
			)
		);

		$summary = sprintf(
			/* translators: 1: fixed count, 2: skipped count, 3: threshold */
			__( '%1$d slug(s) shortened to max %3$d characters. %2$d item(s) were skipped (already short enough or could not be changed).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$fixed,
			$skipped,
			$threshold
		);
		if ( $redirect_note ) {
			$summary .= ' ' . $redirect_note;
		}
		if ( ! empty( $errors ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: number of errors */
				__( '%d error(s) occurred.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				count( $errors )
			);
		}

		wp_send_json_success( array(
			'fixed'    => $fixed,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'changes'  => $applied,
			'message'  => $summary,
			'redirect' => (bool) $do_redirect,
		) );
	}

	/**
	 * Compute planned bulk slug changes without writing to the database.
	 *
	 * @param int[] $ids       Post IDs.
	 * @param int   $threshold Character threshold.
	 * @return array{changes: array<int, array>, skipped: array<int, array>}
	 */
	private function compute_bulk_changes( array $ids, int $threshold ): array {
		$changes = array();
		$skipped = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => '#' . $post_id,
					'reason' => __( 'Post not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				continue;
			}

			$old_slug = (string) $post->post_name;
			if ( strlen( $old_slug ) <= $threshold ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'reason' => __( 'Already within the character limit.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				continue;
			}

			$new_slug = $this->truncate_slug( $old_slug, $threshold );
			if ( '' === $new_slug || $new_slug === $old_slug ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'reason' => __( 'Could not shorten this slug.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				continue;
			}

			if ( $this->slug_exists( $new_slug, (string) $post->post_type, $post_id ) ) {
				$new_slug = $new_slug . '-' . $post_id;
			}

			$changes[] = array(
				'id'       => $post_id,
				'title'    => (string) $post->post_title,
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
			);
		}

		return array(
			'changes' => $changes,
			'skipped' => $skipped,
		);
	}

	/**
	 * Build preview message for bulk slug fix.
	 *
	 * @param array{changes:array,skipped:array} $preview     Preview data.
	 * @param int                                $threshold   Character limit.
	 * @param bool                               $do_redirect Whether redirects are enabled.
	 * @return string
	 */
	private function build_bulk_preview_message( array $preview, int $threshold, bool $do_redirect ): string {
		$change_count  = count( $preview['changes'] );
		$skipped_count = count( $preview['skipped'] );

		if ( 0 === $change_count ) {
			return __( 'No slugs would change with the current selection and threshold.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$message = sprintf(
			/* translators: 1: number of slugs to change, 2: threshold, 3: skipped count */
			_n(
				'%1$d slug will be shortened to a maximum of %2$d characters. %3$d item will be skipped.',
				'%1$d slugs will be shortened to a maximum of %2$d characters. %3$d items will be skipped.',
				$change_count,
				'tso-swiss-knife-advanced-maintenance-developer-toolkit'
			),
			$change_count,
			$threshold,
			$skipped_count
		);

		if ( $do_redirect && class_exists( 'TSOSK_Mod_Redirects' ) ) {
			$message .= ' ' . __( '301 redirects will be created from the old URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return $message;
	}

	// ── AJAX: search posts by slug ────────────────────────────────────────────

	/**
	 * Return posts matching a slug search term (for the search box).
	 */
	public function ajax_search(): void {
		check_ajax_referer( 'tsosk_sm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		global $wpdb;

		$q         = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );
		$page      = max( 1, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1 );
		$offset    = ( $page - 1 ) * self::PER_PAGE;

		$allowed_types = $this->get_public_post_types();
		$like          = $q ? ( '%' . $wpdb->esc_like( $q ) . '%' ) : '';

		if ( $post_type && in_array( $post_type, $allowed_types, true ) ) {
			if ( $q ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_name LIKE %s
						    AND post_type = %s",
						$like,
						$post_type
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_name, post_type, post_status
						   FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_name LIKE %s
						    AND post_type = %s
						  ORDER BY post_name ASC
						  LIMIT %d OFFSET %d",
						$like,
						$post_type,
						self::PER_PAGE,
						$offset
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_type = %s",
						$post_type
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_name, post_type, post_status
						   FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_type = %s
						  ORDER BY post_name ASC
						  LIMIT %d OFFSET %d",
						$post_type,
						self::PER_PAGE,
						$offset
					)
				);
			}
		} else {
			if ( array() === $allowed_types ) {
				wp_send_json_success(
					array(
						'items'       => array(),
						'total'       => 0,
						'page'        => $page,
						'total_pages' => 1,
					)
				);
			}

			// Placeholders only (%s); values bound via prepare(). Imploded inline to avoid DirectDB temp vars.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $q ) {
				$count_args = array_merge( array( $like ), $allowed_types );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_name LIKE %s
						    AND post_type IN (" . implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) ) . ')',
						...$count_args
					)
				);
				$list_args = array_merge( array( $like ), $allowed_types, array( self::PER_PAGE, $offset ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_name, post_type, post_status
						   FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_name LIKE %s
						    AND post_type IN (" . implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) ) . ')
						  ORDER BY post_name ASC
						  LIMIT %d OFFSET %d',
						...$list_args
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_type IN (" . implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) ) . ')',
						...$allowed_types
					)
				);
				$list_args = array_merge( $allowed_types, array( self::PER_PAGE, $offset ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_name, post_type, post_status
						   FROM {$wpdb->posts}
						  WHERE post_status IN ('publish','draft','private','pending','future')
						    AND post_type IN (" . implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) ) . ')
						  ORDER BY post_name ASC
						  LIMIT %d OFFSET %d',
						...$list_args
					)
				);
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'        => (int) $row->ID,
				'title'     => (string) $row->post_title,
				'slug'      => (string) $row->post_name,
				'type'      => (string) $row->post_type,
				'status'    => (string) $row->post_status,
				'permalink' => get_permalink( (int) $row->ID ),
				'edit_link' => get_edit_post_link( (int) $row->ID, 'raw' ),
				'len'       => strlen( (string) $row->post_name ),
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

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		$nonce         = wp_create_nonce( 'tsosk_sm_nonce' );
		$post_types    = $this->get_public_post_types();
		$threshold     = self::DEFAULT_THRESHOLD;
		$has_redirects = class_exists( 'TSOSK_Mod_Redirects' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab
		$active_tab = isset( $_GET['sm_tab'] ) ? sanitize_key( wp_unslash( $_GET['sm_tab'] ) ) : 'long';
		if ( ! in_array( $active_tab, array( 'long', 'duplicates', 'issues', 'search' ), true ) ) {
			$active_tab = 'long';
		}

		$base_url = add_query_arg( array( 'page' => 'tso-swiss-knife', 'tab' => 'slug-manager' ), admin_url( 'tools.php' ) );

		// ── Pre-load audit data for tab badges and active tab content ──
		$audit_data = $this->get_all_audit_data( $post_types, $threshold );
		?>

		<p class="tsosk-desc">
			<?php esc_html_e( 'Audit and fix post slugs across all public post types. Rename any slug inline with an optional automatic 301 redirect — no broken links. Long slugs hurt SEO; duplicates confuse crawlers; special characters and uppercase letters cause encoding issues.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( ! $has_redirects ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php esc_html_e( '⚠ The Redirects module is not active. Automatic 301 redirects will not be created when you rename slugs. Enable the Redirects module to avoid broken links.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php endif; ?>

		<?php /* ── Toolbar: post-type filter + threshold ── */ ?>
		<div class="tsosk-card" style="padding:12px 16px;">
			<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;">
					<span><?php esc_html_e( 'Post type:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<select id="tsosk-sm-post-type" style="min-width:140px;">
						<option value=""><?php esc_html_e( 'All public types', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						<?php foreach ( $post_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $pt ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;">
					<span><?php esc_html_e( 'Long slug threshold:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<input type="number" id="tsosk-sm-threshold"
					       value="<?php echo esc_attr( (string) $threshold ); ?>"
					       min="10" max="200" step="5" style="width:70px;">
					<span class="description"><?php esc_html_e( 'characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</label>
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;">
					<input type="checkbox" id="tsosk-sm-auto-redirect"
					       <?php checked( $has_redirects ); ?>
					       <?php disabled( ! $has_redirects ); ?>>
					<span>
						<?php esc_html_e( 'Auto-create 301 redirect on rename', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						<?php if ( ! $has_redirects ) : ?>
						<em class="description">(<?php esc_html_e( 'Redirects module required', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>)</em>
						<?php endif; ?>
					</span>
				</label>
			</div>
		</div>

		<?php /* ── Inner tab navigation ── */ ?>
		<div class="tsosk-oe-tabs" style="margin-top:4px;">
			<?php
			$tabs = array(
				'long'       => __( 'Long Slugs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'duplicates' => __( 'Duplicates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'issues'     => __( 'Character Issues', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'search'     => __( 'Search & Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
			foreach ( $tabs as $slug => $label ) :
				$count = ( 'search' !== $slug ) ? count( $audit_data[ $slug ] ?? array() ) : null;
				?>
				<a href="<?php echo esc_url( add_query_arg( 'sm_tab', $slug, $base_url ) ); ?>"
				   class="button tsosk-oe-tab-btn <?php echo $active_tab === $slug ? 'is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( null !== $count ) : ?>
					<span class="tsosk-badge tsosk-badge-<?php echo $count > 0 ? 'warn' : 'ok'; ?>"
					      style="margin-left:4px;font-size:11px;">
						<?php echo esc_html( (string) $count ); ?>
					</span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<div id="tsosk-sm-msg-global" style="display:none;margin:8px 0;" class="tsosk-ajax-msg"></div>

		<?php /* ══ TAB: Long Slugs ══ */ ?>
		<?php if ( 'long' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Long Slugs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $audit_data['long'] ) ); ?>
				</span>
			</h3>
			<div class="tsosk-notice tsosk-notice-info">
				<?php
				printf(
					/* translators: %d: threshold */
					esc_html__( 'Posts and pages whose slug exceeds %d characters. Long slugs are harder for users to share and may negatively affect SEO click-through rates. Recommended maximum: 50 characters. The Bulk Fix button truncates all selected slugs at the last word boundary and creates automatic 301 redirects.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $threshold
				);
				?>
			</div>
			<?php if ( empty( $audit_data['long'] ) ) : ?>
				<p class="tsosk-badge tsosk-badge-ok" style="display:inline-block;">
					<?php esc_html_e( '✓ No long slugs found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
					<button class="button button-small" id="tsosk-sm-select-all-long">
						<?php esc_html_e( 'Select All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<button class="button button-small" id="tsosk-sm-deselect-all-long">
						<?php esc_html_e( 'Deselect All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<button class="button button-primary button-small" id="tsosk-sm-bulk-fix"
					        data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Bulk Fix Selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-sm-bulk-msg"></span>
				</div>
				<div id="tsosk-sm-bulk-summary" class="tsosk-notice tsosk-notice-info" style="display:none;margin-bottom:12px;"></div>
				<?php $this->render_slug_table( $audit_data['long'], $nonce, 'long', true ); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ══ TAB: Duplicates ══ */ ?>
		<?php if ( 'duplicates' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Duplicate Slugs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $audit_data['duplicates'] ) ); ?>
				</span>
			</h3>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Posts that share the same slug within the same post type. WordPress appends a numeric suffix (-2, -3…) at runtime to avoid collisions, but the stored slug stays as-is. Duplicate slugs confuse search engines and can cause canonical URL issues.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $audit_data['duplicates'] ) ) : ?>
				<p class="tsosk-badge tsosk-badge-ok" style="display:inline-block;">
					<?php esc_html_e( '✓ No duplicate slugs found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<?php $this->render_slug_table( $audit_data['duplicates'], $nonce, 'dup', false ); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ══ TAB: Character Issues ══ */ ?>
		<?php if ( 'issues' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Character Issues', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $audit_data['issues'] ) ); ?>
				</span>
			</h3>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Slugs containing uppercase letters, spaces, accented characters, or other non-ASCII characters. These can cause URL encoding issues, browser inconsistencies and problems with caching layers. A clean slug uses only lowercase a–z, digits 0–9 and hyphens.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $audit_data['issues'] ) ) : ?>
				<p class="tsosk-badge tsosk-badge-ok" style="display:inline-block;">
					<?php esc_html_e( '✓ No character issues found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<?php $this->render_slug_table( $audit_data['issues'], $nonce, 'iss', false ); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ══ TAB: Search & Edit ══ */ ?>
		<?php if ( 'search' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Search & Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Find any post or page by its slug and rename it inline. Leave the search box empty and click Load to browse all content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
				<input type="text" id="tsosk-sm-search-input"
				       placeholder="<?php esc_attr_e( 'Search slug…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				       style="width:260px;" autocomplete="off">
				<button class="button" id="tsosk-sm-search-btn"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Search', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button" id="tsosk-sm-load-all"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Load All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sm-search-msg"></span>
			</div>
			<div id="tsosk-sm-search-results" style="display:none;">
				<div id="tsosk-sm-pagination-top" class="tsosk-oe-pagination"></div>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table">
						<thead><tr>
							<th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:12%"><?php esc_html_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:10%"><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th class="tsosk-sm-slug-col"><?php esc_html_e( 'Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Chars', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:130px"><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr></thead>
						<tbody id="tsosk-sm-search-tbody"></tbody>
					</table>
				</div>
				<div id="tsosk-sm-pagination-bottom" class="tsosk-oe-pagination"></div>
			</div>
			<div id="tsosk-sm-search-placeholder" style="color:#646970;">
				<?php esc_html_e( 'Type a slug fragment or click Load All.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</div>
		<?php endif; ?>

		<?php /* ── Inline rename panel (shared by all tabs) ── */ ?>
		<div id="tsosk-sm-rename-panel" style="display:none;" class="tsosk-card">
			<h3><?php esc_html_e( 'Rename Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<input type="hidden" id="tsosk-sm-rename-post-id">
			<table class="tsosk-kv-table" style="width:100%;max-width:540px;">
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'Post', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<div id="tsosk-sm-rename-title" class="tsosk-sm-rename-title"></div>
						<a id="tsosk-sm-rename-edit-link" href="#" target="_blank" rel="noopener noreferrer"
						   class="button button-small" style="margin-top:6px;">
							<?php esc_html_e( 'Edit post ↗', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</a>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Current slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code id="tsosk-sm-rename-current" style="word-break:break-all;"></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Current length', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><span id="tsosk-sm-rename-len"></span> <?php esc_html_e( 'characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'New slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-sm-rename-new"
						       style="width:100%;max-width:360px;font-family:monospace;"
						       autocomplete="off" spellcheck="false">
						<p class="description" id="tsosk-sm-rename-new-len" style="margin-top:3px;font-size:11px;color:#646970;"></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto-redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="tsosk-sm-rename-redirect"
							       <?php checked( $has_redirects ); ?>
							       <?php disabled( ! $has_redirects ); ?>>
							<?php esc_html_e( 'Create 301 redirect from old URL to new URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</label>
						<?php if ( $has_redirects ) : ?>
						<p class="description"><?php esc_html_e( 'Redirect will be added to TSO Swiss Knife Redirects automatically.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php else : ?>
						<p class="description"><?php esc_html_e( 'Redirects module is not active. Enable it to use this feature.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
				<button class="button button-primary" id="tsosk-sm-rename-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save New Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button" id="tsosk-sm-rename-cancel">
					<?php esc_html_e( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sm-rename-msg"></span>
			</div>
		</div>
		<?php
	}

	// ── Audit data loaders ────────────────────────────────────────────────────

	/**
	 * Load audit data for all tabs (badges + active table).
	 *
	 * @param string[] $post_types Allowed post types.
	 * @param int      $threshold  Long-slug character threshold.
	 * @return array{long:array,duplicates:array,issues:array}
	 */
	private function get_all_audit_data( array $post_types, int $threshold ): array {
		return array(
			'long'       => $this->get_long_slugs( $post_types, $threshold ),
			'duplicates' => $this->get_duplicate_slugs( $post_types ),
			'issues'     => $this->get_issue_slugs( $post_types ),
		);
	}

	/**
	 * Get posts with slugs longer than $threshold characters.
	 *
	 * @param string[] $post_types Allowed types.
	 * @param int      $threshold  Character threshold.
	 * @return array
	 */
	private function get_long_slugs( array $post_types, int $threshold ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args = array_merge( $post_types, array( $threshold, 200 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_type, post_status
				   FROM {$wpdb->posts}
				  WHERE post_status IN ('publish','draft','private','pending','future')
				    AND post_type IN ({$placeholders})
				    AND CHAR_LENGTH(post_name) > %d
				  ORDER BY CHAR_LENGTH(post_name) DESC
				  LIMIT %d",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		return $this->hydrate_rows( (array) $rows );
	}

	/**
	 * Get posts that share the same post_name within the same post_type.
	 *
	 * @param string[] $post_types Allowed types.
	 * @return array
	 */
	private function get_duplicate_slugs( array $post_types ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args = array_merge( $post_types, array( 200 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders contains only %s built from count(); all values are passed as variadic ...$args to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_name, p.post_type, p.post_status
				   FROM {$wpdb->posts} p
				  INNER JOIN (
				      SELECT post_name, post_type, COUNT(*) AS cnt
				        FROM {$wpdb->posts}
				       WHERE post_status IN ('publish','draft','private','pending','future')
				         AND post_type IN ({$placeholders})
				         AND post_name <> ''
				       GROUP BY post_name, post_type
				      HAVING cnt > 1
				  ) AS dup ON p.post_name = dup.post_name AND p.post_type = dup.post_type
				  WHERE p.post_status IN ('publish','draft','private','pending','future')
				  ORDER BY p.post_name, p.post_type, p.ID
				  LIMIT %d",
				...$args
			)
		);

		return $this->hydrate_rows( (array) $rows );
	}

	/**
	 * Get posts with slugs containing non-ASCII, uppercase or spaces.
	 *
	 * @param string[] $post_types Allowed types.
	 * @return array
	 */
	private function get_issue_slugs( array $post_types ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args = array_merge( $post_types, array( 200 ) );

		// Match slugs that are NOT all-lowercase a-z, 0-9, hyphens.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders contains only %s built from count(); all values are passed as variadic ...$args to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_type, post_status
				   FROM {$wpdb->posts}
				  WHERE post_status IN ('publish','draft','private','pending','future')
				    AND post_type IN ({$placeholders})
				    AND post_name REGEXP '[^a-z0-9\-]'
				  ORDER BY post_name ASC
				  LIMIT %d",
				...$args
			)
		);

		return $this->hydrate_rows( (array) $rows );
	}

	/**
	 * Convert raw DB rows to a normalized array.
	 *
	 * @param object[] $rows Raw WP DB rows.
	 * @return array
	 */
	private function hydrate_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'        => (int) $row->ID,
				'title'     => (string) $row->post_title,
				'slug'      => (string) $row->post_name,
				'type'      => (string) $row->post_type,
				'status'    => (string) $row->post_status,
				'permalink' => get_permalink( (int) $row->ID ),
				'edit_link' => get_edit_post_link( (int) $row->ID, 'raw' ),
				'len'       => strlen( (string) $row->post_name ),
			);
		}
		return $out;
	}

	// ── Render helpers ────────────────────────────────────────────────────────

	/**
	 * Render a slug audit table with optional checkbox column.
	 *
	 * @param array  $rows       Hydrated post rows.
	 * @param string $nonce      WP nonce value.
	 * @param string $cb_prefix  Checkbox name prefix.
	 * @param bool   $checkboxes Whether to include checkbox column.
	 */
	private function render_slug_table( array $rows, string $nonce, string $cb_prefix, bool $checkboxes ): void {
		?>
		<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table tsosk-sm-audit-table">
				<thead><tr>
					<?php if ( $checkboxes ) : ?>
					<th style="width:36px;"><input type="checkbox" class="tsosk-sm-check-all"
					       data-prefix="<?php echo esc_attr( $cb_prefix ); ?>"></th>
					<?php endif; ?>
					<th class="tsosk-sm-title-col"><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:8%"><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th class="tsosk-sm-slug-col"><?php esc_html_e( 'Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:8%"><?php esc_html_e( 'Chars', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr id="tsosk-sm-row-<?php echo esc_attr( (string) $row['id'] ); ?>"
					    class="<?php echo $row['len'] > self::DEFAULT_THRESHOLD ? 'tsosk-row-warn' : ''; ?>">
						<?php if ( $checkboxes ) : ?>
						<td>
							<input type="checkbox" class="tsosk-sm-row-check"
							       name="<?php echo esc_attr( $cb_prefix ); ?>[]"
							       value="<?php echo esc_attr( (string) $row['id'] ); ?>">
						</td>
						<?php endif; ?>
						<td class="tsosk-sm-title-col">
							<strong><?php echo esc_html( $row['title'] ?: '(' . __( 'no title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . ')' ); ?></strong>
							<?php if ( $row['edit_link'] ) : ?>
							<a href="<?php echo esc_url( $row['edit_link'] ); ?>"
							   target="_blank" rel="noopener noreferrer"
							   class="tsosk-sm-edit-link">
								<?php esc_html_e( 'edit ↗', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</a>
							<?php endif; ?>
						</td>
						<td class="tsosk-code" style="font-size:12px;"><?php echo esc_html( $row['type'] ); ?></td>
						<td>
							<span class="tsosk-badge tsosk-badge-<?php echo 'publish' === $row['status'] ? 'ok' : 'info'; ?>"
							      style="font-size:11px;">
								<?php echo esc_html( $row['status'] ); ?>
							</span>
						</td>
						<td class="tsosk-sm-slug-col tsosk-code">
							<a href="<?php echo esc_url( $row['permalink'] ); ?>"
							   target="_blank" rel="noopener noreferrer"
							   title="<?php echo esc_attr( $row['slug'] ); ?>">
								<?php echo esc_html( $row['slug'] ); ?>
							</a>
						</td>
						<td>
							<span style="font-weight:600;<?php echo $row['len'] > self::DEFAULT_THRESHOLD ? 'color:#b45309;' : ''; ?>">
								<?php echo esc_html( (string) $row['len'] ); ?>
							</span>
						</td>
						<td class="tsosk-actions">
							<button class="button button-small tsosk-sm-edit-btn"
							        data-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
							        data-title="<?php echo esc_attr( $row['title'] ); ?>"
							        data-slug="<?php echo esc_attr( $row['slug'] ); ?>"
							        data-len="<?php echo esc_attr( (string) $row['len'] ); ?>"
							        data-edit-link="<?php echo esc_attr( $row['edit_link'] ); ?>"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Rename', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Utility helpers ───────────────────────────────────────────────────────

	/**
	 * Sanitize a slug: lowercase, replace spaces with hyphens, strip non-ASCII.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	private function sanitize_slug( string $raw ): string {
		$slug = mb_strtolower( trim( $raw ) );
		$slug = str_replace( array( ' ', '_' ), '-', $slug );
		$slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );
		$slug = preg_replace( '/-{2,}/', '-', $slug );
		$slug = trim( $slug, '-' );
		return $slug;
	}

	/**
	 * Truncate a slug at the last hyphen before $max characters.
	 *
	 * @param string $slug      Original slug.
	 * @param int    $max       Maximum character count.
	 * @return string
	 */
	private function truncate_slug( string $slug, int $max ): string {
		if ( strlen( $slug ) <= $max ) {
			return $slug;
		}
		$truncated = substr( $slug, 0, $max );
		$last_hyphen = strrpos( $truncated, '-' );
		if ( $last_hyphen && $last_hyphen > 5 ) {
			$truncated = substr( $truncated, 0, $last_hyphen );
		}
		return trim( $truncated, '-' );
	}

	/**
	 * Check if a slug already exists for a given post type (excluding $exclude_id).
	 *
	 * @param string $slug       Slug to check.
	 * @param string $post_type  Post type.
	 * @param int    $exclude_id Post ID to exclude from the check.
	 * @return bool
	 */
	private function slug_exists( string $slug, string $post_type, int $exclude_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				  WHERE post_name    = %s
				    AND post_type    = %s
				    AND ID          != %d
				    AND post_status IN ('publish','draft','private','pending','future')",
				$slug, $post_type, $exclude_id
			)
		);
		return $count > 0;
	}

	/**
	 * Programmatically add a 301 redirect to TSO Swiss Knife Redirects.
	 *
	 * @param string $old_path    Source path (e.g. /old-slug/).
	 * @param string $new_url     Target URL.
	 * @return bool True if redirect was created.
	 */
	private function add_redirect( string $old_path, string $new_url ): bool {
		if ( ! class_exists( 'TSOSK_Mod_Redirects' ) ) {
			return false;
		}

		$rules = get_option( 'tsosk_redirect_rules', array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$old_key = $this->normalize_redirect_path( $old_path );
		foreach ( $rules as $rule ) {
			if ( empty( $rule['source'] ) ) {
				continue;
			}
			if ( $this->normalize_redirect_path( (string) $rule['source'] ) === $old_key ) {
				return false;
			}
		}

		$id           = 'tsosk_' . substr( md5( uniqid( 'sm_', true ) ), 0, 12 );
		$rules[ $id ] = array(
			'id'         => $id,
			'source'     => $old_path,
			'target'     => $new_url,
			'match_type' => 'exact',
			'status'     => 301,
			'enabled'    => true,
			'hits'       => 0,
			'last_hit'   => 0,
			'created'    => time(),
		);

		update_option( 'tsosk_redirect_rules', $rules, false );
		return true;
	}

	/**
	 * Normalize a redirect source path for duplicate detection.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private function normalize_redirect_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$path = untrailingslashit( $path );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Return an array of all public post type slugs (excluding 'attachment').
	 *
	 * @return string[]
	 */
	private function get_public_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}
}
