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

	/** Maximum rows shown per audit list (one more is fetched to detect truncation). */
	private const AUDIT_LIMIT = 200;

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
		if ( ! in_array( $post->post_type, $this->get_public_post_types(), true ) ) {
			wp_send_json_error( __( 'This content type cannot be edited here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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

		// Duplicate within the same post type (pages only clash under the same parent).
		if ( $this->slug_exists( $new_slug, (string) $post->post_type, $post_id, (int) $post->post_parent ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: duplicate slug */
					__( 'Slug "%s" is already used by another post of the same type.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$new_slug
				)
			);
		}

		// A post and a top-level page cannot share one URL when permalinks start with the post name.
		$clash = $this->cross_type_conflict( $new_slug, $post );
		if ( '' !== $clash ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: slug, 2: content type that already uses it (post or page) */
					__( 'The URL /%1$s/ is already used by a %2$s, so only one of them could load. Choose a different slug.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$new_slug,
					$clash
				)
			);
		}

		// Redirects only make sense for published content; child pages change URL too.
		$was_public    = 'publish' === $post->post_status;
		$want_redirect = $do_redirect && $was_public && class_exists( 'TSOSK_Mod_Redirects' );
		$old_permalink = (string) get_permalink( $post_id );
		$old_urls      = $want_redirect ? $this->collect_old_urls( $post ) : array();

		$result = wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => $new_slug,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// WordPress may adjust the slug (numeric suffix, reserved words): report what was really saved.
		clean_post_cache( $post_id );
		$saved_slug    = (string) get_post_field( 'post_name', $post_id );
		$new_permalink = (string) get_permalink( $post_id );

		$stats   = $want_redirect ? $this->apply_url_moves( $old_urls ) : array();
		$message = $this->build_rename_message( $stats, $do_redirect, $was_public, count( $old_urls ) );
		if ( $saved_slug !== $new_slug ) {
			$message .= ' ' . sprintf(
				/* translators: %s: slug WordPress actually saved */
				__( 'WordPress adjusted the slug to "%s" to keep it unique.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$saved_slug
			);
		}

		TSOSK_Activity_Log::log(
			'slug-manager',
			'update',
			sprintf(
				/* translators: 1: old slug, 2: new slug */
				__( 'Slug renamed: %1$s → %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$old_slug,
				$saved_slug
			)
		);

		wp_send_json_success(
			array(
				'new_slug'         => $saved_slug,
				'new_permalink'    => $new_permalink,
				'old_permalink'    => $old_permalink,
				'len'              => strlen( $saved_slug ),
				'redirect_created' => ( ( $stats['created'] ?? 0 ) + ( $stats['updated'] ?? 0 ) ) > 0,
				'message'          => $message,
			)
		);
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

		$ids       = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? array_map( 'absint', map_deep( wp_unslash( $_POST['post_ids'] ), 'sanitize_text_field' ) )
			: array();
		$ids       = array_values( array_filter( $ids ) );
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

		$ids         = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? array_map( 'absint', map_deep( wp_unslash( $_POST['post_ids'] ), 'sanitize_text_field' ) )
			: array();
		$ids         = array_values( array_filter( $ids ) );
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
		$totals  = array(
			'created'   => 0,
			'updated'   => 0,
			'exists'    => 0,
			'skipped'   => 0,
			'removed'   => 0,
			'flattened' => 0,
		);

		foreach ( $changes as $change ) {
			$post_id = (int) $change['id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				++$skipped;
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$errors[] = "#{$post_id}: " . __( 'Permission denied.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				continue;
			}

			$was_public    = 'publish' === $post->post_status;
			$want_redirect = $do_redirect && $was_public && class_exists( 'TSOSK_Mod_Redirects' );
			$old_urls      = $want_redirect ? $this->collect_old_urls( $post ) : array();

			$result = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => (string) $change['new_slug'],
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = "#{$post_id}: " . $result->get_error_message();
				continue;
			}

			if ( $want_redirect ) {
				foreach ( $this->apply_url_moves( $old_urls ) as $key => $value ) {
					$totals[ $key ] += $value;
				}
			}

			// Report the slug WordPress really saved (it may add a numeric suffix).
			clean_post_cache( $post_id );
			$change['new_slug'] = (string) get_post_field( 'post_name', $post_id );
			$applied[]          = $change;
			++$fixed;
		}

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
		if ( $do_redirect && class_exists( 'TSOSK_Mod_Redirects' ) ) {
			$summary .= ' ' . sprintf(
				/* translators: 1: redirects created or updated, 2: redirects that already existed */
				__( '301 redirects: %1$d created or updated, %2$d already existed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$totals['created'] + $totals['updated'],
				$totals['exists']
			);
			if ( $totals['flattened'] > 0 ) {
				$summary .= ' ' . sprintf(
					/* translators: %d: number of earlier redirects repointed */
					__( '%d earlier redirect(s) were repointed to avoid redirect chains.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$totals['flattened']
				);
			}
		}
		if ( ! empty( $errors ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: number of errors */
				__( '%d error(s) occurred.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				count( $errors )
			);
		}

		wp_send_json_success(
			array(
				'fixed'    => $fixed,
				'skipped'  => $skipped,
				'errors'   => $errors,
				'changes'  => $applied,
				'message'  => $summary,
				'redirect' => (bool) $do_redirect,
			)
		);
	}

	/**
	 * Compute planned bulk slug changes without writing to the database.
	 *
	 * @param int[] $ids       Post IDs.
	 * @param int   $threshold Character threshold.
	 * @return array{changes: array<int, array>, skipped: array<int, array>}
	 */
	private function compute_bulk_changes( array $ids, int $threshold ): array {
		$changes       = array();
		$skipped       = array();
		$taken         = array();
		$allowed_types = $this->get_public_post_types();

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

			if ( ! in_array( $post->post_type, $allowed_types, true ) || ! in_array( $post->post_status, array( 'publish', 'draft', 'private', 'pending', 'future' ), true ) ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'reason' => __( 'This item is not an editable public post.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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

			if ( '' === $this->truncate_slug( $old_slug, $threshold ) ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'reason' => __( 'Could not shorten this slug.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				continue;
			}

			$new_slug = $this->pick_free_slug( $old_slug, $threshold, $post, $taken );
			if ( '' === $new_slug ) {
				$skipped[] = array(
					'id'     => $post_id,
					'title'  => (string) $post->post_title,
					'reason' => __( 'No free slug was found within the limit.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				continue;
			}

			$taken[ $this->slug_key( $post, $new_slug ) ] = true;
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
	 * Key that identifies a URL slot: type + parent (hierarchical types only) + slug.
	 *
	 * @param WP_Post $post Post the slug is for.
	 * @param string  $slug Candidate slug.
	 * @return string
	 */
	private function slug_key( $post, string $slug ): string {
		$parent = is_post_type_hierarchical( (string) $post->post_type ) ? (int) $post->post_parent : 0;
		return $post->post_type . '|' . $parent . '|' . $slug;
	}

	/**
	 * Shortest free slug: the truncated one, or with a -2..-9 suffix that still fits the limit.
	 *
	 * @param string  $old_slug  Current slug.
	 * @param int     $threshold Maximum length.
	 * @param WP_Post $post      The post.
	 * @param array   $taken     Slug keys already planned in this batch.
	 * @return string Empty string when nothing free was found.
	 */
	private function pick_free_slug( string $old_slug, int $threshold, $post, array $taken ): string {
		$candidates = array( $this->truncate_slug( $old_slug, $threshold ) );
		for ( $n = 2; $n <= 9; $n++ ) {
			$suffix       = '-' . $n;
			$candidates[] = $this->truncate_slug( $old_slug, $threshold - strlen( $suffix ) ) . $suffix;
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate || $candidate === $old_slug || isset( $taken[ $this->slug_key( $post, $candidate ) ] ) ) {
				continue;
			}
			if ( $this->slug_exists( $candidate, (string) $post->post_type, (int) $post->ID, (int) $post->post_parent ) ) {
				continue;
			}
			if ( '' !== $this->cross_type_conflict( $candidate, $post ) ) {
				continue;
			}
			return $candidate;
		}
		return '';
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
			/* translators: 1: number of slugs to change, 2: threshold */
			_n(
				'%1$d slug will be shortened to a maximum of %2$d characters.',
				'%1$d slugs will be shortened to a maximum of %2$d characters.',
				$change_count,
				'tso-swiss-knife-advanced-maintenance-developer-toolkit'
			),
			$change_count,
			$threshold
		);

		if ( $skipped_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of items skipped */
				_n(
					'%d item will be skipped.',
					'%d items will be skipped.',
					$skipped_count,
					'tso-swiss-knife-advanced-maintenance-developer-toolkit'
				),
				$skipped_count
			);
		}

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
				'date'      => substr( (string) get_post_field( 'post_date', (int) $row->ID ), 0, 10 ),
				'path'      => is_post_type_hierarchical( (string) $row->post_type ) ? (string) get_page_uri( (int) $row->ID ) : '',
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
		$all_types     = $this->get_public_post_types();
		$has_redirects = class_exists( 'TSOSK_Mod_Redirects' );

		// Filters live in the URL so the audit tabs, badges and Bulk Fix always agree.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
		$threshold = isset( $_GET['sm_threshold'] ) ? max( 10, min( 200, absint( wp_unslash( $_GET['sm_threshold'] ) ) ) ) : self::DEFAULT_THRESHOLD;
		$sel_type  = isset( $_GET['sm_type'] ) ? sanitize_key( wp_unslash( $_GET['sm_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$sel_type   = in_array( $sel_type, $all_types, true ) ? $sel_type : '';
		$post_types = '' !== $sel_type ? array( $sel_type ) : $all_types;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab
		$active_tab = isset( $_GET['sm_tab'] ) ? sanitize_key( wp_unslash( $_GET['sm_tab'] ) ) : 'long';
		if ( ! in_array( $active_tab, array( 'long', 'duplicates', 'issues', 'search' ), true ) ) {
			$active_tab = 'long';
		}

		$base_url = add_query_arg( array( 'page' => 'tso-swiss-knife', 'tab' => 'slug-manager' ), admin_url( 'tools.php' ) );
		if ( '' !== $sel_type ) {
			$base_url = add_query_arg( 'sm_type', $sel_type, $base_url );
		}
		if ( self::DEFAULT_THRESHOLD !== $threshold ) {
			$base_url = add_query_arg( 'sm_threshold', $threshold, $base_url );
		}

		// ── Pre-load audit data for tab badges and active tab content ──
		$audit_data = $this->get_all_audit_data( $post_types, $threshold );
		?>

		<p class="tsosk-desc">
			<?php esc_html_e( 'Audit and fix post slugs across all public post types. Renaming a slug changes its URL, so a 301 redirect from the old address (and from child pages) is created to keep links and rankings. Rename only when the new slug is clearly better.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
						<?php foreach ( $all_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $sel_type, $pt ); ?>><?php echo esc_html( $pt ); ?></option>
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
				$more  = ( 'search' !== $slug ) && ! empty( $audit_data['more'][ $slug ] );
				?>
				<a href="<?php echo esc_url( add_query_arg( 'sm_tab', $slug, $base_url ) ); ?>"
				   class="button tsosk-oe-tab-btn <?php echo $active_tab === $slug ? 'is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( null !== $count ) : ?>
					<span class="tsosk-badge tsosk-badge-<?php echo $count > 0 ? 'warn' : 'ok'; ?>"
					      style="margin-left:4px;font-size:11px;">
						<?php echo esc_html( (string) $count . ( $more ? '+' : '' ) ); ?>
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
					<?php echo esc_html( (string) count( $audit_data['long'] ) . ( ! empty( $audit_data['more']['long'] ) ? '+' : '' ) ); ?>
				</span>
			</h3>
			<?php if ( ! empty( $audit_data['more']['long'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum number of rows shown */
					esc_html__( 'Showing the first %d results only. Fix these and reload to see the rest.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) self::AUDIT_LIMIT
				);
				?>
			</p>
			<?php endif; ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php
				printf(
					/* translators: %d: threshold */
					esc_html__( 'Posts and pages whose slug exceeds %d characters. Google does not use URL length as a ranking factor, so this is about readability and sharing; the default of 50 is a guideline, not a Google rule. Shorten a slug when it is clearly better (new or low-traffic content) and leave well-ranked URLs alone: every rename needs a redirect and search engines have to reprocess the URL. Bulk Fix truncates at the last word boundary and creates 301 redirects.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
				<?php $this->render_slug_table( $audit_data['long'], $nonce, 'long', true, $threshold ); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ══ TAB: Duplicates ══ */ ?>
		<?php if ( 'duplicates' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Duplicate Slugs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $audit_data['duplicates'] ) . ( ! empty( $audit_data['more']['duplicates'] ) ? '+' : '' ) ); ?>
				</span>
			</h3>
			<?php if ( ! empty( $audit_data['more']['duplicates'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum number of rows shown */
					esc_html__( 'Showing the first %d results only. Fix these and reload to see the rest.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) self::AUDIT_LIMIT
				);
				?>
			</p>
			<?php endif; ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Posts that share the same slug within the same post type (pages only clash under the same parent), and posts that share a slug with a top-level page when permalinks start with the post name, where only one of them can load. WordPress normally prevents this: it usually comes from imports, direct database edits or plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $audit_data['duplicates'] ) ) : ?>
				<p class="tsosk-badge tsosk-badge-ok" style="display:inline-block;">
					<?php esc_html_e( '✓ No duplicate slugs found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<?php $this->render_slug_table( $audit_data['duplicates'], $nonce, 'dup', false, $threshold, true ); ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ══ TAB: Character Issues ══ */ ?>
		<?php if ( 'issues' === $active_tab ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Character Issues', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-info" style="margin-left:8px;font-size:12px;">
					<?php echo esc_html( (string) count( $audit_data['issues'] ) . ( ! empty( $audit_data['more']['issues'] ) ? '+' : '' ) ); ?>
				</span>
			</h3>
			<?php if ( ! empty( $audit_data['more']['issues'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum number of rows shown */
					esc_html__( 'Showing the first %d results only. Fix these and reload to see the rest.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) self::AUDIT_LIMIT
				);
				?>
			</p>
			<?php endif; ?>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'Slugs with uppercase letters, underscores or encoded characters. Paths are case-sensitive, so uppercase can create duplicate URLs, and Google recommends hyphens over underscores. Encoded characters (accents, non-Latin scripts) are valid for Google; change them only if you prefer readable, transliterated slugs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $audit_data['issues'] ) ) : ?>
				<p class="tsosk-badge tsosk-badge-ok" style="display:inline-block;">
					<?php esc_html_e( '✓ No character issues found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<?php $this->render_slug_table( $audit_data['issues'], $nonce, 'iss', false, $threshold, true ); ?>
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
					<table class="widefat tsosk-table tsosk-sm-audit-table" id="tsosk-sm-search-table">
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
			<p class="description" id="tsosk-sm-rename-urls" style="display:none;word-break:break-all;"></p>
		</div>
		<?php
	}

	// ── Audit data loaders ────────────────────────────────────────────────────

	private function get_all_audit_data( array $post_types, int $threshold ): array {
		if ( empty( $post_types ) ) {
			return array(
				'long'       => array(),
				'duplicates' => array(),
				'issues'     => array(),
				'more'       => array(),
			);
		}

		$data = array(
			'long'       => $this->get_long_slugs( $post_types, $threshold ),
			'duplicates' => $this->get_duplicate_slugs( $post_types ),
			'issues'     => $this->get_issue_slugs( $post_types ),
		);

		// Lists are capped: keep the cap visible instead of showing a silently truncated count.
		$more = array();
		foreach ( $data as $key => $rows ) {
			if ( count( $rows ) > self::AUDIT_LIMIT ) {
				$data[ $key ] = array_slice( $rows, 0, self::AUDIT_LIMIT );
				$more[ $key ] = true;
			}
		}
		$data['more'] = $more;
		return $data;
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
		$args = array_merge( $post_types, array( $threshold, self::AUDIT_LIMIT + 1 ) );

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

	private function get_duplicate_slugs( array $post_types ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args         = array_merge( $post_types, array( 1001 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders contains only %s built from count(); all values are passed as variadic ...$args to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_name, p.post_type, p.post_status, p.post_parent
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
				  ORDER BY p.post_name, p.post_type, p.post_parent, p.ID
				  LIMIT %d",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// The same slug under different parents is valid for hierarchical types (/a/contact/ and /b/contact/).
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$parent = is_post_type_hierarchical( (string) $row->post_type ) ? (int) $row->post_parent : 0;
			$groups[ $row->post_type . '|' . $row->post_name . '|' . $parent ][] = $row;
		}
		$dupes = array();
		foreach ( $groups as $group ) {
			if ( count( $group ) > 1 ) {
				foreach ( $group as $row ) {
					$dupes[] = $row;
				}
			}
		}

		$out = $this->hydrate_rows( $dupes );
		foreach ( $out as $i => $row ) {
			$out[ $i ]['reasons'] = array( __( 'Same slug in the same post type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return array_merge( $out, $this->get_cross_type_conflicts( $post_types ) );
	}

	/**
	 * Posts and top-level pages that share one slug (same URL when permalinks start with the post name).
	 *
	 * @param string[] $post_types Allowed types.
	 * @return array
	 */
	private function get_cross_type_conflicts( array $post_types ): array {
		global $wpdb;

		$structure = ltrim( (string) get_option( 'permalink_structure' ), '/' );
		if ( 0 !== strpos( $structure, '%postname%' ) || ! in_array( 'post', $post_types, true ) || ! in_array( 'page', $post_types, true ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pairs = (array) $wpdb->get_results(
			"SELECT po.ID AS post_id, pg.ID AS page_id
			   FROM {$wpdb->posts} po
			  INNER JOIN {$wpdb->posts} pg ON po.post_name = pg.post_name
			  WHERE po.post_type = 'post' AND pg.post_type = 'page' AND pg.post_parent = 0
			    AND po.post_name <> ''
			    AND po.post_status IN ('publish','draft','private','pending','future')
			    AND pg.post_status IN ('publish','draft','private','pending','future')
			  ORDER BY po.post_name
			  LIMIT 101"
		);

		$objects = array();
		foreach ( $pairs as $pair ) {
			foreach ( array( (int) $pair->post_id, (int) $pair->page_id ) as $id ) {
				$post = get_post( $id );
				if ( $post && ! isset( $objects[ $id ] ) ) {
					$objects[ $id ] = (object) array(
						'ID'          => $post->ID,
						'post_title'  => $post->post_title,
						'post_name'   => $post->post_name,
						'post_type'   => $post->post_type,
						'post_status' => $post->post_status,
					);
				}
			}
		}

		$out = $this->hydrate_rows( array_values( $objects ) );
		foreach ( $out as $i => $row ) {
			$out[ $i ]['reasons'] = array( __( 'Same URL as a post/page: only one of them can load', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		return $out;
	}

	private function get_issue_slugs( array $post_types ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args         = array_merge( $post_types, array( self::AUDIT_LIMIT + 1 ) );

		// REGEXP ignores case under the usual _ci collations, so uppercase is matched with a binary comparison.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders contains only %s built from count(); all values are passed as variadic ...$args to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_type, post_status
				   FROM {$wpdb->posts}
				  WHERE post_status IN ('publish','draft','private','pending','future')
				    AND post_type IN ({$placeholders})
				    AND ( post_name REGEXP '[^a-z0-9-]'
				          OR CAST(post_name AS BINARY) <> CAST(LOWER(post_name) AS BINARY) )
				  ORDER BY post_name ASC
				  LIMIT %d",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( $this->hydrate_rows( (array) $rows ) as $row ) {
			$reasons = $this->slug_issue_reasons( $row['slug'] );
			if ( ! empty( $reasons ) ) {
				$row['reasons'] = $reasons;
				$out[]          = $row;
			}
		}
		return $out;
	}

	/**
	 * Human-readable reasons why a slug is flagged.
	 *
	 * @param string $slug Stored post_name.
	 * @return string[]
	 */
	private function slug_issue_reasons( string $slug ): array {
		$reasons = array();
		if ( preg_match( '/[A-Z]/', $slug ) ) {
			$reasons[] = __( 'Uppercase letters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( false !== strpos( $slug, '_' ) ) {
			$reasons[] = __( 'Underscore (hyphens are preferred)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( preg_match( '/%[0-9a-fA-F]{2}/', $slug ) ) {
			$reasons[] = __( 'Encoded characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( '' !== preg_replace( '/%[0-9a-fA-F]{2}|[A-Za-z0-9_-]/', '', $slug ) ) {
			$reasons[] = __( 'Other characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return $reasons;
	}

	private function hydrate_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$type = (string) $row->post_type;
			$out[] = array(
				'id'        => $id,
				'title'     => (string) $row->post_title,
				'slug'      => (string) $row->post_name,
				'type'      => $type,
				'status'    => (string) $row->post_status,
				'permalink' => get_permalink( $id ),
				'edit_link' => get_edit_post_link( $id, 'raw' ),
				'len'       => strlen( (string) $row->post_name ),
				'date'      => substr( (string) get_post_field( 'post_date', $id ), 0, 10 ),
				'path'      => is_post_type_hierarchical( $type ) ? (string) get_page_uri( $id ) : '',
				'reasons'   => array(),
			);
		}
		return $out;
	}

	// ── Render helpers ────────────────────────────────────────────────────────

	/**
	 * Render a slug audit table with optional checkbox and problem columns.
	 *
	 * @param array  $rows        Hydrated post rows.
	 * @param string $nonce       WP nonce value.
	 * @param string $cb_prefix   Checkbox name prefix.
	 * @param bool   $checkboxes  Whether to include checkbox column.
	 * @param int    $threshold   Long-slug threshold used to highlight rows.
	 * @param bool   $show_reason Whether to include the "Problem" column.
	 */
	private function render_slug_table( array $rows, string $nonce, string $cb_prefix, bool $checkboxes, int $threshold = self::DEFAULT_THRESHOLD, bool $show_reason = false ): void {
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
					<?php if ( $show_reason ) : ?>
					<th style="width:18%"><?php esc_html_e( 'Problem', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<?php endif; ?>
					<th style="width:8%"><?php esc_html_e( 'Chars', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr id="tsosk-sm-row-<?php echo esc_attr( (string) $row['id'] ); ?>"
					    class="<?php echo $row['len'] > $threshold ? 'tsosk-row-warn' : ''; ?>">
						<?php if ( $checkboxes ) : ?>
						<td data-label="<?php esc_attr_e( 'Select', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<input type="checkbox" class="tsosk-sm-row-check"
							       name="<?php echo esc_attr( $cb_prefix ); ?>[]"
							       value="<?php echo esc_attr( (string) $row['id'] ); ?>">
						</td>
						<?php endif; ?>
						<td class="tsosk-sm-title-col" data-label="<?php esc_attr_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<strong><?php echo esc_html( $row['title'] ?: '(' . __( 'no title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . ')' ); ?></strong>
							<?php if ( $row['edit_link'] ) : ?>
							<a href="<?php echo esc_url( $row['edit_link'] ); ?>"
							   target="_blank" rel="noopener noreferrer"
							   class="tsosk-sm-edit-link">
								<?php esc_html_e( 'edit ↗', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</a>
							<?php endif; ?>
							<?php if ( '' !== $row['date'] ) : ?>
							<span class="tsosk-sm-meta"><?php echo esc_html( $row['date'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="tsosk-code" style="font-size:12px;" data-label="<?php esc_attr_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $row['type'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<span class="tsosk-badge tsosk-badge-<?php echo 'publish' === $row['status'] ? 'ok' : 'info'; ?>"
							      style="font-size:11px;">
								<?php echo esc_html( $row['status'] ); ?>
							</span>
						</td>
						<td class="tsosk-sm-slug-col tsosk-code" data-label="<?php esc_attr_e( 'Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<a href="<?php echo esc_url( $row['permalink'] ); ?>"
							   target="_blank" rel="noopener noreferrer"
							   title="<?php echo esc_attr( $row['slug'] ); ?>">
								<?php echo esc_html( $row['slug'] ); ?>
							</a>
							<?php if ( '' !== $row['path'] && $row['path'] !== $row['slug'] ) : ?>
							<span class="tsosk-sm-meta">/<?php echo esc_html( $row['path'] ); ?>/</span>
							<?php endif; ?>
						</td>
						<?php if ( $show_reason ) : ?>
						<td data-label="<?php esc_attr_e( 'Problem', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<?php foreach ( $row['reasons'] as $reason ) : ?>
							<span class="tsosk-badge tsosk-badge-warn tsosk-sm-reason"><?php echo esc_html( $reason ); ?></span>
							<?php endforeach; ?>
						</td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Chars', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<span style="font-weight:600;<?php echo $row['len'] > $threshold ? 'color:#b45309;' : ''; ?>">
								<?php echo esc_html( (string) $row['len'] ); ?>
							</span>
						</td>
						<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
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
	 * Sanitize a slug the way WordPress does (accents transliterated, lowercase), with hyphens only.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	private function sanitize_slug( string $raw ): string {
		$slug = sanitize_title( trim( $raw ) );
		$slug = str_replace( '_', '-', $slug );
		$slug = preg_replace( '/-{2,}/', '-', (string) $slug );
		return trim( (string) $slug, '-' );
	}

	/**
	 * Truncate a slug at a word boundary (hyphen) without exceeding $max characters.
	 *
	 * @param string $slug Original slug.
	 * @param int    $max  Maximum character count.
	 * @return string
	 */
	private function truncate_slug( string $slug, int $max ): string {
		if ( $max < 1 ) {
			return '';
		}
		if ( strlen( $slug ) <= $max ) {
			return $slug;
		}
		$truncated = substr( $slug, 0, $max );
		// Cut already lands on a word boundary when the next character is a hyphen.
		if ( '-' !== $slug[ $max ] ) {
			$last_hyphen = strrpos( $truncated, '-' );
			if ( false !== $last_hyphen && $last_hyphen > 5 ) {
				$truncated = substr( $truncated, 0, $last_hyphen );
			}
		}
		return trim( $truncated, '-' );
	}

	/**
	 * Check if a slug already exists for a given post type (excluding $exclude_id).
	 *
	 * Hierarchical types (pages) only clash under the same parent; other types clash site-wide.
	 *
	 * @param string $slug       Slug to check.
	 * @param string $post_type  Post type.
	 * @param int    $exclude_id Post ID to exclude from the check.
	 * @param int    $parent     Parent ID (hierarchical types only).
	 * @return bool
	 */
	private function slug_exists( string $slug, string $post_type, int $exclude_id, int $parent = 0 ): bool {
		global $wpdb;

		if ( is_post_type_hierarchical( $post_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					  WHERE post_name    = %s
					    AND post_type    = %s
					    AND ID          != %d
					    AND post_parent  = %d
					    AND post_status IN ('publish','draft','private','pending','future')",
					$slug, $post_type, $exclude_id, $parent
				)
			);
			return $count > 0;
		}

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
	 * Detect a post/page pair that would share one URL (only when permalinks start with the post name).
	 *
	 * @param string  $slug Candidate slug.
	 * @param WP_Post $post The post being renamed.
	 * @return string 'post' or 'page' when the other type already uses the slug, otherwise ''.
	 */
	private function cross_type_conflict( string $slug, $post ): string {
		global $wpdb;

		$structure = ltrim( (string) get_option( 'permalink_structure' ), '/' );
		if ( 0 !== strpos( $structure, '%postname%' ) || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return '';
		}
		// Only top-level pages share the root URL space with posts.
		if ( 'page' === $post->post_type && 0 !== (int) $post->post_parent ) {
			return '';
		}

		$other = 'post' === $post->post_type ? 'page' : 'post';
		if ( 'page' === $other ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					  WHERE post_name = %s AND post_type = 'page' AND post_parent = 0
					    AND post_status IN ('publish','draft','private','pending','future')",
					$slug
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					  WHERE post_name = %s AND post_type = 'post'
					    AND post_status IN ('publish','draft','private','pending','future')",
					$slug
				)
			);
		}
		return $count > 0 ? $other : '';
	}

	/**
	 * Permalinks of a post and, for hierarchical types, of all its published descendants.
	 *
	 * Renaming a parent page changes every child URL, so they all need a redirect.
	 *
	 * @param WP_Post $post Post about to be renamed.
	 * @return array<int,string> Post ID => current permalink.
	 */
	private function collect_old_urls( $post ): array {
		$urls = array( (int) $post->ID => (string) get_permalink( (int) $post->ID ) );
		if ( ! is_post_type_hierarchical( (string) $post->post_type ) ) {
			return $urls;
		}

		$queue = array( (int) $post->ID );
		$seen  = 0;
		while ( ! empty( $queue ) && $seen < 500 ) {
			$parent   = array_shift( $queue );
			$children = get_posts(
				array(
					'post_type'        => $post->post_type,
					'post_parent'      => $parent,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'numberposts'      => 200,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			);
			foreach ( $children as $child_id ) {
				$urls[ (int) $child_id ] = (string) get_permalink( (int) $child_id );
				$queue[]                 = (int) $child_id;
				++$seen;
			}
		}
		return $urls;
	}

	/**
	 * Create the 301 rules for URLs that moved, keeping the rule set clean.
	 *
	 *  1. Rules whose source is the new, now live URL are removed (they would hijack the page or loop).
	 *  2. Rules that pointed at the old URL are repointed to the new one (no redirect chains).
	 *  3. The old URL gets a 301 to the new one (an existing rule for it is updated, never duplicated).
	 *
	 * @param array<int,string> $old_urls Post ID => permalink before the change.
	 * @return array{created:int,updated:int,exists:int,skipped:int,removed:int,flattened:int}
	 */
	private function apply_url_moves( array $old_urls ): array {
		$stats = array(
			'created'   => 0,
			'updated'   => 0,
			'exists'    => 0,
			'skipped'   => 0,
			'removed'   => 0,
			'flattened' => 0,
		);
		if ( ! class_exists( 'TSOSK_Mod_Redirects' ) || empty( $old_urls ) ) {
			return $stats;
		}

		$rules = get_option( 'tsosk_redirect_rules', array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}
		$changed = false;

		foreach ( $old_urls as $post_id => $old_url ) {
			$new_url  = (string) get_permalink( (int) $post_id );
			$old_path = $this->normalize_redirect_path( (string) wp_parse_url( (string) $old_url, PHP_URL_PATH ) );
			$new_path = $this->normalize_redirect_path( (string) wp_parse_url( $new_url, PHP_URL_PATH ) );
			if ( '' === $new_url || '/' === $old_path || '/' === $new_path || $old_path === $new_path ) {
				++$stats['skipped'];
				continue;
			}

			// 1. Rules that would hijack the new URL.
			foreach ( $rules as $rule_id => $rule ) {
				if ( 'exact' === ( $rule['match_type'] ?? 'exact' ) && $this->normalize_redirect_path( (string) ( $rule['source'] ?? '' ) ) === $new_path ) {
					unset( $rules[ $rule_id ] );
					++$stats['removed'];
					$changed = true;
				}
			}

			// 2. Flatten chains: anything that redirected to the old URL now goes straight to the new one.
			foreach ( $rules as $rule_id => $rule ) {
				$status = (int) ( $rule['status'] ?? 301 );
				$target = (string) ( $rule['target'] ?? '' );
				if ( $status < 300 || $status > 399 || '' === $target || false !== strpos( $target, '?' ) ) {
					continue;
				}
				if ( $this->target_path( $target ) === $old_path ) {
					$rules[ $rule_id ]['target'] = $new_url;
					++$stats['flattened'];
					$changed = true;
				}
			}

			// 3. The rule for the old URL itself.
			$found = false;
			foreach ( $rules as $rule_id => $rule ) {
				if ( 'exact' !== ( $rule['match_type'] ?? 'exact' ) || $this->normalize_redirect_path( (string) ( $rule['source'] ?? '' ) ) !== $old_path ) {
					continue;
				}
				$found  = true;
				$status = (int) ( $rule['status'] ?? 301 );
				if ( ! empty( $rule['enabled'] ) && $status >= 300 && $status <= 399 && $this->target_path( (string) ( $rule['target'] ?? '' ) ) === $new_path ) {
					++$stats['exists'];
				} else {
					$rules[ $rule_id ]['target']  = $new_url;
					$rules[ $rule_id ]['status']  = 301;
					$rules[ $rule_id ]['enabled'] = true;
					++$stats['updated'];
					$changed = true;
				}
				break;
			}

			if ( ! $found ) {
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
				++$stats['created'];
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'tsosk_redirect_rules', $rules, false );
		}
		return $stats;
	}

	/**
	 * Site-relative, normalized path of a redirect target ('' for external or empty targets).
	 *
	 * @param string $target Rule target (path or absolute URL).
	 * @return string
	 */
	private function target_path( string $target ): string {
		$target = trim( $target );
		if ( '' === $target ) {
			return '';
		}
		if ( 0 !== strpos( $target, '/' ) ) {
			$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( '' === $host || $host !== $home ) {
				return '';
			}
		}
		return $this->normalize_redirect_path( (string) wp_parse_url( $target, PHP_URL_PATH ) );
	}

	/**
	 * Message shown after a single rename, describing what happened with the redirect.
	 *
	 * @param array $stats       Result of apply_url_moves() (empty when no redirect was attempted).
	 * @param bool  $do_redirect Whether the user asked for a redirect.
	 * @param bool  $was_public  Whether the post was published before the change.
	 * @param int   $moved_count Number of URLs that moved (post + children).
	 * @return string
	 */
	private function build_rename_message( array $stats, bool $do_redirect, bool $was_public, int $moved_count ): string {
		if ( ! $do_redirect ) {
			return __( 'Slug updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( ! $was_public ) {
			return __( 'Slug updated. No redirect was needed because the post is not published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( ! class_exists( 'TSOSK_Mod_Redirects' ) ) {
			return __( 'Slug updated, but no redirect was created because the Redirects module is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$written = (int) ( $stats['created'] ?? 0 ) + (int) ( $stats['updated'] ?? 0 );
		$exists  = (int) ( $stats['exists'] ?? 0 );
		if ( $written > 0 ) {
			$message = __( 'Slug updated and 301 redirect created.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} elseif ( $exists > 0 ) {
			$message = __( 'Slug updated. A 301 redirect for the old URL already existed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			return __( 'Slug updated, but no redirect was created.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$children = ( $written + $exists ) - 1;
		if ( $moved_count > 1 && $children > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of child pages whose URL also changed */
				_n( '%d child page URL was also redirected.', '%d child page URLs were also redirected.', $children, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$children
			);
		}
		if ( ! empty( $stats['flattened'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of earlier redirects repointed */
				__( '%d earlier redirect(s) were repointed to avoid redirect chains.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $stats['flattened']
			);
		}
		if ( ! empty( $stats['removed'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of redirects removed because they pointed away from the now live URL */
				__( '%d old redirect(s) for the new URL were removed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $stats['removed']
			);
		}
		return $message;
	}


	/**
	 * Normalize a redirect source path for storage and duplicate detection.
	 * Strips the home subdirectory so rules match request paths (e.g. /blog/old/ → /old/).
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private function normalize_redirect_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '/';
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = trim( $home_path );
		if ( '' !== $home_path && '/' !== $home_path ) {
			$prefix = untrailingslashit( $home_path );
			if ( $path === $prefix || $path === $prefix . '/' || $path === $home_path ) {
				$path = '/';
			} elseif ( str_starts_with( $path, $prefix . '/' ) ) {
				$path = substr( $path, strlen( $prefix ) );
			}
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
