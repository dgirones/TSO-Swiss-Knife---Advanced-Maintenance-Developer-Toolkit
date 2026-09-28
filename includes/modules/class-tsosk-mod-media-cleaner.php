<?php
/**
 * TSO Swiss Knife – Module: Media Cleaner.
 *
 * Diagnoses three kinds of Media Library problems, in batches so large libraries do not time out:
 *  1. Library items whose file is missing, empty, stored with a wrong path (full URL, old absolute
 *     path) or whose generated sizes point to files that are gone.
 *  2. Media that is not attached to any post (including media whose parent post was deleted).
 *  3. Files under wp-content/uploads that no Media Library item references, leaving out thumbnails,
 *     scaled/edited originals, PDF previews, WebP/AVIF copies and plugin folders that belong to a
 *     known attachment or are not media at all.
 *
 * Nothing is ever deleted or moved here: every list is read-only.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Media_Cleaner
 */
class TSOSK_Mod_Media_Cleaner {

	/** Transient: last completed full review results. */
	private const TRANSIENT_FULL_RESULTS = 'tsosk_media_full_review_v2';

	/** Transient: in-progress chunked scan state. */
	private const TRANSIENT_FULL_STATE = 'tsosk_media_full_review_state';

	/** Attachments inspected per AJAX chunk. */
	private const CHUNK_ATTACHMENTS = 80;

	/** Upload files inspected per AJAX chunk. */
	private const CHUNK_FILES = 500;

	/** Rows kept in the stored results (counters keep counting past these caps). */
	private const MAX_MISSING_ROWS = 2000;
	private const MAX_ORPHAN_ROWS  = 5000;

	/** Rows rendered per group. */
	private const SHOW_ORPHAN_MEDIA = 500;
	private const SHOW_ORPHAN_OTHER = 200;

	/** Unattached media per page. */
	private const UNATTACHED_PER_PAGE = 30;

	/** Cached "missing" rows re-checked on page load, so fixed items disappear. */
	private const REVALIDATE_LIMIT = 300;

	/** Extensions treated as media when a file is not referenced (everything else is "other files"). */
	private const MEDIA_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tif', 'tiff', 'heic', 'heif',
		'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
		'mp4', 'm4v', 'mov', 'avi', 'webm', 'mkv', 'mp3', 'wav', 'ogg', 'm4a', 'flac',
	);

	/** Top-level uploads folders that plugins own; they are not walked (filterable). */
	private const PLUGIN_FOLDERS = array(
		'cache', 'fonts', 'custom-fonts', 'elementor', 'wc-logs', 'woocommerce_uploads', 'wpforms', 'smush',
		'ai1wm-backups', 'updraft', 'litespeed', 'sucuri', 'revslider', 'wpallimport', 'wpcf7_uploads',
		'gravity_forms', 'bb-plugin', 'backupbuddy_backups', 'pb_backupbuddy', 'wp-rocket', 'wpo-plugins-tmp',
		'w3tc', 'breeze', 'autoptimize', 'et_temp', 'siteground-optimizer-assets',
	);

	/** @var TSOSK_Mod_Media_Cleaner|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_media_regenerate', array( $this, 'ajax_regenerate' ) );
		add_action( 'wp_ajax_tsosk_media_full_review', array( $this, 'ajax_full_review' ) );
	}

	/**
	 * AJAX: regenerate attachment metadata.
	 */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'tsosk_media_nonce', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( __( 'This item is not a Media Library attachment.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( __( 'Attachment file not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		tsosk_require_wp_admin( 'includes/image.php' );
		$previous = wp_get_attachment_metadata( $attachment_id );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( empty( $metadata ) || is_wp_error( $metadata ) ) {
			wp_send_json_error( __( 'Could not regenerate attachment metadata.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Core only records the pre-scaling original on upload; keep it so the file stays referenced.
		if ( is_array( $previous ) && ! empty( $previous['original_image'] ) && empty( $metadata['original_image'] ) ) {
			$metadata['original_image'] = $previous['original_image'];
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
		wp_send_json_success( __( 'Attachment thumbnails regenerated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: run/continue a full media review (all attachments + all uploads files).
	 */
	public function ajax_full_review(): void {
		check_ajax_referer( 'tsosk_media_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$start = ! empty( $_POST['start'] );
		if ( $start ) {
			$state = $this->create_full_review_state();
			if ( is_wp_error( $state ) ) {
				wp_send_json_error( $state->get_error_message() );
			}
		} else {
			$state = get_transient( self::TRANSIENT_FULL_STATE );
			if ( ! is_array( $state ) ) {
				wp_send_json_error( __( 'No scan in progress. Start a full review again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$state = $this->process_full_review_chunk( $state );
		if ( is_wp_error( $state ) ) {
			delete_transient( self::TRANSIENT_FULL_STATE );
			wp_send_json_error( $state->get_error_message() );
		}

		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			$results = $this->build_results( $state );
			set_transient( self::TRANSIENT_FULL_RESULTS, $results, DAY_IN_SECONDS );
			delete_transient( self::TRANSIENT_FULL_STATE );

			$message = __( 'Full media review completed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			if ( ! empty( $results['unreadable'] ) ) {
				$message .= ' ' . __( 'Some folders could not be read, so the list may be incomplete (see the notice below).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}

			wp_send_json_success(
				array(
					'done'     => true,
					'message'  => $message,
					'progress' => $this->format_full_review_progress( $state ),
					'html'     => array(
						'summary'    => $this->render_summary_html( $results, $this->get_unattached_page( 1 )['total'], (int) $results['missing_total'] ),
						'missing'    => $this->render_missing_table_html( $this->hydrate_missing_rows( $results['missing'] ), $results ),
						'duplicates' => $this->render_duplicates_table_html( $results['duplicates'] ),
						'orphans'    => $this->render_orphans_table_html( $results ),
					),
				)
			);
		}

		set_transient( self::TRANSIENT_FULL_STATE, $state, HOUR_IN_SECONDS );
		wp_send_json_success(
			array(
				'done'     => false,
				'progress' => $this->format_full_review_progress( $state ),
			)
		);
	}

	/**
	 * Final results structure stored for the page (rows are capped, counters are not).
	 *
	 * @param array<string,mixed> $state Finished scan state.
	 * @return array<string,mixed>
	 */
	private function build_results( array $state ): array {
		$orphans = is_array( $state['orphans'] ?? null ) ? $state['orphans'] : array();
		usort(
			$orphans,
			static function ( array $a, array $b ): int {
				return (int) ( $b['size'] ?? 0 ) <=> (int) ( $a['size'] ?? 0 );
			}
		);

		return array(
			'missing'             => $state['missing'] ?? array(),
			'missing_total'       => (int) ( $state['missing_total'] ?? 0 ),
			'missing_capped'      => (int) ( $state['missing_total'] ?? 0 ) > count( (array) ( $state['missing'] ?? array() ) ),
			'problem_counts'      => $state['problem_counts'] ?? array(),
			'duplicates'          => $state['duplicates'] ?? array(),
			'remote'              => (int) ( $state['remote'] ?? 0 ),
			'orphans'             => $orphans,
			'orphans_total'       => (int) ( $state['orphans_total'] ?? 0 ),
			'orphans_bytes'       => (int) ( $state['orphans_bytes'] ?? 0 ),
			'kind_counts'         => $state['kind_counts'] ?? array(),
			'kind_bytes'          => $state['kind_bytes'] ?? array(),
			'related_skipped'     => (int) ( $state['related_skipped'] ?? 0 ),
			'skipped_folders'     => $state['skipped_folders'] ?? array(),
			'unreadable'          => $state['unreadable'] ?? array(),
			'skipped_links'       => (int) ( $state['skipped_links'] ?? 0 ),
			'attachments_total'   => (int) ( $state['attachment_total'] ?? 0 ),
			'attachments_checked' => (int) ( $state['attachment_checked'] ?? 0 ),
			'files_checked'       => (int) ( $state['files_checked'] ?? 0 ),
			'scanned_at'          => time(),
			'complete'            => true,
		);
	}

	/**
	 * Render media diagnostics.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_media_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
		$page       = isset( $_GET['mc_page'] ) ? max( 1, absint( wp_unslash( $_GET['mc_page'] ) ) ) : 1;
		$unattached = $this->get_unattached_page( $page );

		$uploads  = wp_upload_dir();
		$basedir  = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) : '';
		$cached   = get_transient( self::TRANSIENT_FULL_RESULTS );
		$has_full = is_array( $cached ) && ! empty( $cached['complete'] );
		$missing  = $has_full ? $this->hydrate_missing_rows( $cached['missing'] ?? array(), true, $basedir ) : array();

		$state      = get_transient( self::TRANSIENT_FULL_STATE );
		$can_resume = is_array( $state ) && in_array( (string) ( $state['phase'] ?? '' ), array( 'attachments', 'orphans' ), true );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Finds Media Library items whose file is missing, empty or stored with a wrong path, media not attached to any post, and files in wp-content/uploads that no Media Library item references. It only reports: nothing is deleted or moved from this screen.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-toolbar" style="gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
			<button type="button" class="button button-primary" id="tsosk-media-full-review"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Run full media review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-media-full-review-msg"></span>
		</div>
		<p class="description" id="tsosk-media-full-review-progress" style="margin-top:0;">
			<?php
			if ( $has_full ) {
				printf(
					/* translators: 1: attachments checked, 2: upload files checked, 3: date */
					esc_html__( 'Last full review: %1$s Media Library items and %2$s uploads files checked on %3$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( number_format_i18n( (int) ( $cached['attachments_checked'] ?? 0 ) ) ),
					esc_html( number_format_i18n( (int) ( $cached['files_checked'] ?? 0 ) ) ),
					esc_html( gmdate( 'Y-m-d H:i', (int) ( $cached['scanned_at'] ?? 0 ) ) . ' UTC' )
				);
			} else {
				esc_html_e( 'No full review yet. It checks every Media Library item and every eligible file under wp-content/uploads/, in batches, until finished.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			?>
		</p>

		<?php if ( $can_resume ) : ?>
		<div class="tsosk-notice tsosk-notice-warn" id="tsosk-media-resume-note">
			<?php echo esc_html( $this->format_full_review_progress( $state ) ); ?>
			<?php esc_html_e( 'A review was interrupted before it finished.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			<button type="button" class="button button-small" id="tsosk-media-full-review-resume" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Resume', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-footprint' ) ); ?>">
				<?php esc_html_e( 'Open Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=image-sizes-audit' ) ); ?>">
				<?php esc_html_e( 'Open Image Sizes Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<div id="tsosk-media-summary-wrap">
			<?php
			if ( $has_full ) {
				$missing_count = ! empty( $cached['missing_capped'] ) ? (int) ( $cached['missing_total'] ?? 0 ) : count( $missing );
				echo $this->render_summary_html( $cached, $unattached['total'], $missing_count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			}
			?>
		</div>

		<div id="tsosk-media-missing-wrap">
			<?php
			if ( $has_full ) {
				$meta                   = $cached;
				$meta['missing_total']  = ! empty( $cached['missing_capped'] ) ? (int) ( $cached['missing_total'] ?? 0 ) : count( $missing );
				echo $this->render_missing_table_html( $missing, $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			} else {
				?>
				<div class="tsosk-card">
					<h3><?php esc_html_e( 'Media Library items with missing or broken files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Run a full media review to inspect every attachment in the Media Library.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				</div>
				<?php
			}
			?>
		</div>

		<div id="tsosk-media-duplicates-wrap">
			<?php
			if ( $has_full ) {
				echo $this->render_duplicates_table_html( (array) ( $cached['duplicates'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			}
			?>
		</div>

		<?php $this->render_unattached_card( $unattached, $nonce, $page ); ?>

		<div id="tsosk-media-orphans-wrap">
			<?php
			if ( $has_full ) {
				echo $this->render_orphans_table_html( $cached ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			} else {
				?>
				<div class="tsosk-card">
					<h3><?php esc_html_e( 'Uploads Files Not Referenced in Database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Run a full media review to walk the whole uploads folder. Thumbnails, scaled or edited originals, PDF previews, WebP/AVIF copies and plugin folders are recognised and left out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Create initial state for a full review.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function create_full_review_state() {
		global $wpdb;

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}
		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Every attachment status counts (inherit, private, trash…); only auto-drafts are placeholders.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'auto-draft'"
		);

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

		return array(
			'phase'              => 'attachments',
			'attachment_total'   => $count,
			'attachment_last_id' => 0,
			'attachment_checked' => 0,
			'missing'            => array(),
			'missing_total'      => 0,
			'problem_counts'     => array(),
			'duplicates'         => $this->find_duplicate_references(),
			'remote'             => 0,
			'base'               => $base,
			'queue'              => array( $base ),
			'pending_files'      => array(),
			'files_checked'      => 0,
			'orphans'            => array(),
			'orphans_total'      => 0,
			'orphans_bytes'      => 0,
			'kind_counts'        => array(),
			'kind_bytes'         => array(),
			'related_skipped'    => 0,
			'skipped_folders'    => array(),
			'unreadable'         => array(),
			'skipped_links'      => 0,
		);
	}

	/**
	 * Library items that share one stored file. Deleting one of them from the Media Library would
	 * delete the file the other still uses.
	 *
	 * @return array<int,array{path:string,ids:int[]}>
	 */
	private function find_duplicate_references(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			"SELECT pm.meta_value AS path, GROUP_CONCAT(pm.post_id ORDER BY pm.post_id SEPARATOR ',') AS ids, COUNT(*) AS c
			   FROM {$wpdb->postmeta} pm
			  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_wp_attached_file'
			    AND pm.meta_value <> ''
			    AND p.post_type = 'attachment'
			    AND p.post_status <> 'auto-draft'
			  GROUP BY pm.meta_value
			 HAVING c > 1
			  ORDER BY c DESC, pm.meta_value ASC
			  LIMIT 200"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $row->ids ) ) ) );
			if ( count( $ids ) > 1 ) {
				$out[] = array(
					'path' => (string) $row->path,
					'ids'  => $ids,
				);
			}
		}
		return $out;
	}

	/**
	 * Process one chunk of the full review.
	 *
	 * @param array<string,mixed> $state Scan state.
	 * @return array<string,mixed>|WP_Error
	 */
	private function process_full_review_chunk( array $state ) {
		global $wpdb;

		$phase = (string) ( $state['phase'] ?? '' );

		if ( 'attachments' === $phase ) {
			$last_id = (int) ( $state['attachment_last_id'] ?? 0 );
			$basedir = (string) ( $state['base'] ?? '' );

			// Keyset pagination by ID: robust when items are added or deleted while the scan runs, and
			// immune to language/query filters that would hide attachments from get_posts().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					  WHERE post_type = 'attachment' AND post_status <> 'auto-draft' AND ID > %d
					  ORDER BY ID ASC LIMIT %d",
					$last_id,
					self::CHUNK_ATTACHMENTS
				)
			);

			foreach ( (array) $ids as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				$last_id       = $attachment_id;
				$info          = $this->inspect_attachment_file( $attachment_id, $basedir );
				++$state['attachment_checked'];

				if ( 'remote' === $info['problem'] ) {
					// Stored on remote storage (offload plugin): a missing local copy is expected.
					++$state['remote'];
					continue;
				}
				if ( 'ok' === $info['problem'] && $info['exists'] ) {
					continue;
				}

				++$state['missing_total'];
				$state['problem_counts'][ $info['problem'] ] = 1 + (int) ( $state['problem_counts'][ $info['problem'] ] ?? 0 );
				if ( count( $state['missing'] ) < self::MAX_MISSING_ROWS ) {
					$state['missing'][] = array(
						'id'   => $attachment_id,
						'info' => $info,
					);
				}
			}

			$state['attachment_last_id'] = $last_id;
			if ( count( (array) $ids ) < self::CHUNK_ATTACHMENTS ) {
				$state['phase'] = 'orphans';
			}
			return $state;
		}

		if ( 'orphans' === $phase ) {
			$base    = (string) ( $state['base'] ?? '' );
			$queue   = is_array( $state['queue'] ?? null ) ? $state['queue'] : array();
			$pending = is_array( $state['pending_files'] ?? null ) ? $state['pending_files'] : array();
			$budget  = self::CHUNK_FILES;

			// Rebuilt per request instead of stored in the transient: a large library would otherwise
			// make every chunk write megabytes to the options table.
			$maps = $this->build_known_maps( $base );

			while ( $budget > 0 && ( ! empty( $pending ) || ! empty( $queue ) ) ) {
				if ( ! empty( $pending ) ) {
					$path = (string) array_shift( $pending );
					if ( '' !== $path && is_file( $path ) && str_starts_with( wp_normalize_path( $path ), $base ) ) {
						$this->record_upload_file( $path, $base, $maps, $state );
						--$budget;
					}
					continue;
				}

				$dir = (string) array_shift( $queue );
				if ( '' === $dir || ! is_dir( $dir ) || ! str_starts_with( wp_normalize_path( $dir ), $base ) ) {
					continue;
				}

				$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! $handle ) {
					if ( count( $state['unreadable'] ) < 20 ) {
						$state['unreadable'][] = ltrim( str_replace( $base, '', wp_normalize_path( $dir ) ), '/' ) ?: '/';
					}
					continue;
				}

				$is_root = ( wp_normalize_path( trailingslashit( $dir ) ) === $base );
				$subdirs = array();
				$files   = array();
				while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = wp_normalize_path( trailingslashit( $dir ) . $entry );
					if ( ! str_starts_with( $path, $base ) ) {
						continue;
					}
					if ( is_link( $path ) && is_dir( $path ) ) {
						// A symlinked folder can loop back into uploads or leave it: never follow it.
						++$state['skipped_links'];
						continue;
					}
					if ( is_dir( $path ) ) {
						if ( TSOSK_Uploads_Scanner::should_skip_upload_dir( $entry ) ) {
							continue;
						}
						if ( $is_root && $this->is_plugin_upload_folder( $entry ) ) {
							if ( count( $state['skipped_folders'] ) < 30 && ! in_array( $entry, $state['skipped_folders'], true ) ) {
								$state['skipped_folders'][] = $entry;
							}
							continue;
						}
						$subdirs[] = $path;
					} elseif ( is_file( $path ) ) {
						$files[] = $path;
					}
				}
				closedir( $handle );

				foreach ( $subdirs as $subdir ) {
					$queue[] = $subdir;
				}
				foreach ( $files as $file_path ) {
					if ( $budget <= 0 ) {
						$pending[] = $file_path;
						continue;
					}
					$this->record_upload_file( $file_path, $base, $maps, $state );
					--$budget;
				}
			}

			$state['queue']         = $queue;
			$state['pending_files'] = $pending;

			if ( empty( $queue ) && empty( $pending ) ) {
				$state['phase'] = 'done';
			}
			return $state;
		}

		$state['phase'] = 'done';
		return $state;
	}

	/**
	 * Classify one uploads file and update the scan counters.
	 *
	 * @param string              $path  Absolute normalized path.
	 * @param string              $base  Uploads base dir with trailing slash.
	 * @param array               $maps  Known and related path maps.
	 * @param array<string,mixed> $state Scan state (by reference).
	 */
	private function record_upload_file( string $path, string $base, array $maps, array &$state ): void {
		++$state['files_checked'];
		$relative = ltrim( str_replace( $base, '', wp_normalize_path( $path ) ), '/' );
		$result   = $this->classify_upload_file( $relative, $maps );

		if ( 'related' === $result['status'] ) {
			++$state['related_skipped'];
			return;
		}
		if ( 'orphan' !== $result['status'] ) {
			return;
		}

		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$kind = (string) $result['kind'];
		++$state['orphans_total'];
		$state['orphans_bytes'] += $size;
		$state['kind_counts'][ $kind ] = 1 + (int) ( $state['kind_counts'][ $kind ] ?? 0 );
		$state['kind_bytes'][ $kind ]  = $size + (int) ( $state['kind_bytes'][ $kind ] ?? 0 );

		if ( count( $state['orphans'] ) < self::MAX_ORPHAN_ROWS ) {
			$state['orphans'][] = array(
				'relative' => $relative,
				'size'     => $size,
				'kind'     => $kind,
				'note'     => (string) $result['note'],
			);
		}
	}

	/**
	 * Decide whether an uploads file is referenced, derived from a referenced file, or unreferenced.
	 *
	 * @param string $relative Path relative to uploads.
	 * @param array  $maps     {known: array<string,bool>, related: array<string,bool>}.
	 * @return array{status:string,kind:string,note:string} status: known|related|protected|orphan.
	 */
	private function classify_upload_file( string $relative, array $maps ): array {
		$none = array(
			'status' => 'known',
			'kind'   => '',
			'note'   => '',
		);

		if ( TSOSK_Uploads_Scanner::is_protected_upload_relative_path( $relative ) || TSOSK_Uploads_Scanner::is_guard_upload_file( $relative ) ) {
			$none['status'] = 'protected';
			return $none;
		}

		$known   = (array) ( $maps['known'] ?? array() );
		$related = (array) ( $maps['related'] ?? array() );
		if ( isset( $known[ $relative ] ) ) {
			return $none;
		}
		$decoded = rawurldecode( $relative );
		if ( $decoded !== $relative && isset( $known[ $decoded ] ) ) {
			return $none;
		}
		if ( isset( $related[ $relative ] ) ) {
			$none['status'] = 'related';
			return $none;
		}

		$dir        = dirname( $relative );
		$dir        = ( '.' === $dir ) ? '' : $dir . '/';
		$file       = basename( $relative );
		$candidates = array();
		$is_thumb   = false;
		$images     = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

		// image.jpg.webp / image.jpg.avif (appended by optimisation plugins).
		if ( preg_match( '/^(.+\.[A-Za-z0-9]{2,5})\.(?:webp|avif)$/i', $file, $m ) ) {
			$candidates[] = $dir . $m[1];
		}
		// Generated sizes: name-300x200.ext (also for other formats and for PDF previews).
		if ( preg_match( '/^(.+)-\d+x\d+\.([A-Za-z0-9]+)$/', $file, $m ) ) {
			$is_thumb     = true;
			$candidates[] = $dir . $m[1] . '.' . $m[2];
			if ( preg_match( '/^(.+)-pdf$/i', $m[1], $p ) ) {
				$candidates[] = $dir . $p[1] . '.pdf';
			}
			foreach ( $images as $alt ) {
				$candidates[] = $dir . $m[1] . '.' . $alt;
			}
		}
		// PDF preview: name-pdf.jpg.
		if ( preg_match( '/^(.+)-pdf\.(?:jpe?g|png)$/i', $file, $m ) ) {
			$candidates[] = $dir . $m[1] . '.pdf';
		}
		// name.webp / name.avif next to a referenced name.jpg|png|gif.
		if ( preg_match( '/^(.+)\.(?:webp|avif)$/i', $file, $m ) ) {
			foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $alt ) {
				$candidates[] = $dir . $m[1] . '.' . $alt;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $known[ $candidate ] ) || isset( $related[ $candidate ] ) ) {
				$none['status'] = 'related';
				return $none;
			}
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return array(
			'status' => 'orphan',
			'kind'   => in_array( $ext, self::MEDIA_EXTENSIONS, true ) ? 'media' : 'other',
			'note'   => $is_thumb ? 'thumbnail_no_parent' : '',
		);
	}

	/**
	 * Whether a top-level uploads folder belongs to a plugin (its files are not Media Library media).
	 *
	 * @param string $name Folder name.
	 * @return bool
	 */
	private function is_plugin_upload_folder( string $name ): bool {
		$name  = strtolower( $name );
		$names = (array) apply_filters( 'tsosk_media_plugin_upload_folders', self::PLUGIN_FOLDERS );
		if ( in_array( $name, array_map( 'strtolower', $names ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^(?:backwpup|backupwordpress|wpo-|w3tc)/', $name );
	}

	/**
	 * Build the maps used to recognise referenced files.
	 *
	 *  known   – every path a Media Library item points to (URL and old absolute paths normalised).
	 *  related – originals kept beside a scaled ("-scaled") or edited ("-e123…", "-rotated") item.
	 *
	 * @param string $basedir Uploads basedir with trailing slash.
	 * @return array{known:array<string,bool>,related:array<string,bool>}
	 */
	private function build_known_maps( string $basedir ): array {
		global $wpdb;

		// Only meta that belongs to a real attachment counts: a stray postmeta row must not hide a file.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raws = (array) $wpdb->get_col(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'"
		);

		$known   = array();
		$related = array();
		foreach ( $raws as $raw ) {
			$raw = (string) $raw;
			$rel = $this->normalize_attached_file_relative( $raw, $basedir );
			if ( '' === $rel ) {
				$rel = $this->salvage_absolute_relative( $raw, $basedir );
			}
			if ( '' === $rel ) {
				continue;
			}
			$known[ $rel ] = true;

			$current = $rel;
			for ( $i = 0; $i < 4; $i++ ) {
				if ( ! preg_match( '/^(.+)-(?:scaled|rotated|e\d{10,})(\.[A-Za-z0-9]+)$/', $current, $m ) ) {
					break;
				}
				$current             = $m[1] . $m[2];
				$related[ $current ] = true;
			}
		}

		return array(
			'known'   => $known,
			'related' => $related,
		);
	}

	/**
	 * Progress label for the UI.
	 *
	 * @param array<string,mixed> $state Scan state.
	 * @return string
	 */
	private function format_full_review_progress( array $state ): string {
		$phase = (string) ( $state['phase'] ?? '' );
		if ( 'attachments' === $phase ) {
			return sprintf(
				/* translators: 1: checked attachments, 2: total attachments */
				__( 'Checking Media Library items… %1$s / %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( (int) ( $state['attachment_checked'] ?? 0 ) ),
				number_format_i18n( (int) ( $state['attachment_total'] ?? 0 ) )
			);
		}
		if ( 'orphans' === $phase ) {
			return sprintf(
				/* translators: %s: number of upload files checked */
				__( 'Scanning uploads folder… %s files checked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( (int) ( $state['files_checked'] ?? 0 ) )
			);
		}
		return sprintf(
			/* translators: 1: attachments checked, 2: upload files checked */
			__( 'Done. Media Library: %1$s · Uploads files: %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			number_format_i18n( (int) ( $state['attachment_checked'] ?? 0 ) ),
			number_format_i18n( (int) ( $state['files_checked'] ?? 0 ) )
		);
	}

	/**
	 * Turn stored missing rows (IDs) into renderable rows with WP_Post objects.
	 *
	 * When $revalidate is set, rows are inspected again so items fixed since the scan disappear.
	 *
	 * @param array<int,array{id:int,info:array}> $stored     Stored rows.
	 * @param bool                                $revalidate Re-check the files now.
	 * @param string                              $basedir    Uploads basedir with trailing slash.
	 * @return array<int,array{attachment:WP_Post,info:array}>
	 */
	private function hydrate_missing_rows( array $stored, bool $revalidate = false, string $basedir = '' ): array {
		$rows    = array();
		$checked = 0;
		foreach ( $stored as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$info = is_array( $row['info'] ?? null ) ? $row['info'] : array();

			if ( $revalidate && $checked < self::REVALIDATE_LIMIT ) {
				++$checked;
				$fresh = $this->inspect_attachment_file( $id, $basedir );
				if ( 'remote' === $fresh['problem'] || ( 'ok' === $fresh['problem'] && $fresh['exists'] ) ) {
					continue;
				}
				$info = $fresh;
			}

			$rows[] = array(
				'attachment' => $post,
				'info'       => $info,
			);
		}
		return $rows;
	}

	/**
	 * Inspect how WordPress stores and resolves an attachment file path.
	 *
	 * Problems: empty, url_in_meta, absolute_path, invalid, missing_file, empty_file, missing_sizes,
	 * remote (file lives on offloaded storage) or ok.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $basedir       Uploads basedir with trailing slash ('' to look it up).
	 * @return array<string,mixed>
	 */
	private function inspect_attachment_file( int $attachment_id, string $basedir = '' ): array {
		if ( '' === $basedir ) {
			$uploads = wp_upload_dir();
			$basedir = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) : '';
		}

		$raw      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$resolved = (string) ( get_attached_file( $attachment_id ) ?: '' );
		$remote   = $this->is_remote_path( $resolved );
		$relative = $this->normalize_attached_file_relative( $raw, $basedir );
		$problem  = 'ok';

		if ( '' === $raw ) {
			$problem = 'empty';
		} elseif ( $this->is_url_path( $raw ) ) {
			$problem = 'url_in_meta';
		} elseif ( '' === $relative ) {
			$salvaged = $this->salvage_absolute_relative( $raw, $basedir );
			if ( '' !== $salvaged ) {
				$relative = $salvaged;
				$problem  = 'absolute_path';
			} else {
				$problem = 'invalid';
			}
		}

		$exists_rel = ( '' !== $relative && '' !== $basedir && file_exists( $basedir . ltrim( $relative, '/' ) ) );
		$exists_res = ( '' !== $resolved && ! $remote && file_exists( $resolved ) );
		$exists     = $exists_rel || $exists_res;

		if ( $exists_res && in_array( $problem, array( 'absolute_path', 'invalid' ), true ) ) {
			// The stored absolute path is valid on this server, so WordPress can load the file.
			$problem = 'ok';
		}
		if ( 'ok' === $problem && ! $exists ) {
			$problem = $remote ? 'remote' : 'missing_file';
		}

		$missing_sizes = array();
		if ( 'ok' === $problem && $exists ) {
			$main = $exists_rel ? $basedir . ltrim( $relative, '/' ) : $resolved;
			if ( is_file( $main ) && 0 === (int) @filesize( $main ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$problem = 'empty_file';
			} else {
				$missing_sizes = $this->find_missing_sizes( $attachment_id, $main );
				if ( ! empty( $missing_sizes ) ) {
					$problem = 'missing_sizes';
				}
			}
		}

		return array(
			'raw'                => $raw,
			'resolved'           => $resolved,
			'relative'           => $relative,
			'exists'             => $exists,
			'problem'            => $problem,
			'missing_sizes'      => array_slice( $missing_sizes, 0, 6 ),
			'missing_sizes_total' => count( $missing_sizes ),
		);
	}

	/**
	 * Generated sizes (and the pre-scaling original) that metadata lists but the disk no longer has.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $main_file     Absolute path of the main file.
	 * @return string[] "size (file)" labels.
	 */
	private function find_missing_sizes( int $attachment_id, string $main_file ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$dir     = trailingslashit( dirname( $main_file ) );
		$missing = array();
		$seen    = array();
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				$file = is_array( $size ) ? (string) ( $size['file'] ?? '' ) : '';
				if ( '' === $file || isset( $seen[ $file ] ) ) {
					continue;
				}
				$seen[ $file ] = true;
				if ( ! file_exists( $dir . $file ) ) {
					$missing[] = $name . ' (' . $file . ')';
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) && ! file_exists( $dir . $meta['original_image'] ) ) {
			$missing[] = 'original_image (' . $meta['original_image'] . ')';
		}
		return $missing;
	}

	/**
	 * Turn stored _wp_attached_file into a relative uploads path when possible.
	 *
	 * @param string $raw     Raw meta value.
	 * @param string $basedir Uploads basedir with trailing slash.
	 * @return string
	 */
	private function normalize_attached_file_relative( string $raw, string $basedir ): string {
		$raw = trim( str_replace( '\\', '/', $raw ) );
		if ( '' === $raw ) {
			return '';
		}

		if ( $this->is_url_path( $raw ) ) {
			$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
			$path = str_replace( '\\', '/', $path );
			if ( preg_match( '#/wp-content/uploads/(.+)$#i', $path, $m ) ) {
				return ltrim( $m[1], '/' );
			}
			return ltrim( $path, '/' );
		}

		if ( '' !== $basedir && 0 === strpos( wp_normalize_path( $raw ), $basedir ) ) {
			return ltrim( substr( wp_normalize_path( $raw ), strlen( $basedir ) ), '/' );
		}

		// Already relative (WordPress normal case), or absolute outside uploads.
		if ( ! preg_match( '#^[a-zA-Z]:/|^/#', $raw ) ) {
			return ltrim( $raw, '/' );
		}

		return '';
	}

	/**
	 * Relative uploads path hidden inside an absolute path from another server (site migration).
	 *
	 * @param string $raw     Raw meta value.
	 * @param string $basedir Uploads basedir with trailing slash.
	 * @return string '' when the path does not contain wp-content/uploads.
	 */
	private function salvage_absolute_relative( string $raw, string $basedir ): string {
		$raw = str_replace( '\\', '/', trim( $raw ) );
		if ( ! preg_match( '#/wp-content/uploads/(.+)$#i', $raw, $m ) ) {
			return '';
		}
		$relative = ltrim( $m[1], '/' );
		// Subsites already have /sites/N in their basedir.
		if ( preg_match( '#/sites/\d+/$#', $basedir ) ) {
			$relative = (string) preg_replace( '#^sites/\d+/#', '', $relative );
		}
		return $relative;
	}

	/**
	 * Whether a resolved path points to remote storage (stream wrapper such as s3://, gs://).
	 *
	 * @param string $path Resolved path.
	 * @return bool
	 */
	private function is_remote_path( string $path ): bool {
		return (bool) preg_match( '#^(?!file://)[a-z][a-z0-9+.\-]*://#i', $path );
	}

	/**
	 * Whether a stored path looks like an absolute URL.
	 *
	 * @param string $path Path or URL.
	 * @return bool
	 */
	private function is_url_path( string $path ): bool {
		return (bool) preg_match( '#^https?://#i', $path );
	}

	/**
	 * Problem severity: error (WordPress cannot serve the file) or warn (works, but fragile or partial).
	 *
	 * @param array<string,mixed> $info Inspect result.
	 * @return string
	 */
	private function problem_severity( array $info ): string {
		switch ( (string) ( $info['problem'] ?? '' ) ) {
			case 'url_in_meta':
			case 'absolute_path':
				// WordPress builds a wrong path from these even when the file exists elsewhere on disk.
				return 'error';
			case 'missing_sizes':
				return 'warn';
			default:
				return 'error';
		}
	}

	/**
	 * Short label for a problem type.
	 *
	 * @param string $problem Problem code.
	 * @return string
	 */
	private function problem_label( string $problem ): string {
		switch ( $problem ) {
			case 'url_in_meta':
				return __( 'Full URL stored', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'absolute_path':
				return __( 'Old absolute path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'empty':
				return __( 'No path stored', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'invalid':
				return __( 'Invalid path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'empty_file':
				return __( 'Empty file (0 bytes)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'missing_sizes':
				return __( 'Missing sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'missing_file':
			default:
				return __( 'File missing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
	}

	/**
	 * Human-readable explanation for a missing/broken attachment row.
	 *
	 * @param array<string,mixed> $info File info.
	 * @return string
	 */
	private function describe_attachment_problem( array $info ): string {
		$exists = ! empty( $info['exists'] );
		switch ( (string) ( $info['problem'] ?? '' ) ) {
			case 'url_in_meta':
				return $exists
					? __( 'A full URL was stored instead of a relative path, so WordPress cannot find the file. The file exists in uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'A full URL was stored instead of a relative path, and the file was not found on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'absolute_path':
				return $exists
					? __( 'The stored path is absolute and comes from another server. The file exists in the current uploads folder, so only the database path needs fixing.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'The stored path is absolute and comes from another server, and the file was not found in the current uploads folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'empty':
				return __( 'No file path is stored in the database for this Media Library item.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'invalid':
				return __( 'The stored file path could not be interpreted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'empty_file':
				return __( 'The file exists but has 0 bytes, so it is corrupt or an interrupted upload.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'missing_sizes':
				return __( 'The original file exists, but generated sizes listed in the database are gone, so some image variants return 404. Regenerating thumbnails fixes it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'missing_file':
			default:
				return __( 'The Media Library entry exists in the database, but the file is not on the server at the expected path.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
	}

	/**
	 * Summary cards and scan notices.
	 *
	 * @param array<string,mixed> $results         Full review results.
	 * @param int                 $unattached_total Unattached media count.
	 * @param int                 $missing_count   Missing/broken items (after revalidation).
	 * @return string
	 */
	private function render_summary_html( array $results, int $unattached_total, int $missing_count ): string {
		$kind_counts = is_array( $results['kind_counts'] ?? null ) ? $results['kind_counts'] : array();
		$kind_bytes  = is_array( $results['kind_bytes'] ?? null ) ? $results['kind_bytes'] : array();
		$media_files = (int) ( $kind_counts['media'] ?? 0 );
		$other_files = (int) ( $kind_counts['other'] ?? 0 );
		$duplicates  = count( (array) ( $results['duplicates'] ?? array() ) );
		$remote      = (int) ( $results['remote'] ?? 0 );
		$skipped     = (array) ( $results['skipped_folders'] ?? array() );
		$unreadable  = (array) ( $results['unreadable'] ?? array() );
		$related     = (int) ( $results['related_skipped'] ?? 0 );
		$links       = (int) ( $results['skipped_links'] ?? 0 );

		$cards = array(
			array( __( 'Missing or broken items', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $missing_count, $missing_count > 0 ? 'warn' : 'ok', '' ),
			array( __( 'Shared file references', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $duplicates, $duplicates > 0 ? 'warn' : 'ok', '' ),
			array( __( 'Unattached media', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $unattached_total, 'info', '' ),
			array( __( 'Unreferenced media files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $media_files, $media_files > 0 ? 'warn' : 'ok', $media_files > 0 ? size_format( (int) ( $kind_bytes['media'] ?? 0 ), 1 ) : '' ),
			array( __( 'Other unreferenced files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $other_files, 'info', $other_files > 0 ? size_format( (int) ( $kind_bytes['other'] ?? 0 ), 1 ) : '' ),
		);

		ob_start();
		?>
		<div class="tsosk-media-stats">
			<?php foreach ( $cards as $card ) : ?>
				<div class="tsosk-media-stat tsosk-media-stat-<?php echo esc_attr( $card[2] ); ?>">
					<strong><?php echo esc_html( number_format_i18n( (int) $card[1] ) ); ?></strong>
					<span><?php echo esc_html( $card[0] ); ?></span>
					<?php if ( '' !== $card[3] ) : ?>
						<em><?php echo esc_html( $card[3] ); ?></em>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( $remote > 0 ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of items */
					esc_html__( '%s item(s) live on remote storage (an offload plugin) and were not checked on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( number_format_i18n( $remote ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $unreadable ) ) : ?>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php
				printf(
					/* translators: %s: comma separated folder list */
					esc_html__( 'These folders could not be read, so their files were not checked: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( implode( ', ', array_map( 'strval', $unreadable ) ) )
				);
				?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $skipped ) || $links > 0 || $related > 0 ) : ?>
			<p class="description">
				<?php
				if ( ! empty( $skipped ) ) {
					printf(
						/* translators: %s: comma separated folder list */
						esc_html__( 'Plugin folders left out: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( implode( ', ', array_map( 'strval', $skipped ) ) )
					);
					echo ' ';
				}
				if ( $related > 0 ) {
					printf(
						/* translators: %s: number of files */
						esc_html__( '%s file(s) were recognised as sizes, previews or copies of a Media Library item.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format_i18n( $related ) )
					);
					echo ' ';
				}
				if ( $links > 0 ) {
					printf(
						/* translators: %s: number of symlinked folders */
						esc_html__( '%s symlinked folder(s) were not followed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format_i18n( $links ) )
					);
				}
				?>
			</p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the missing / broken attachment diagnostics table.
	 *
	 * @param array<int,array{attachment:WP_Post,info:array}> $rows    Rows.
	 * @param array<string,mixed>                             $results Full review results (counters).
	 * @return string
	 */
	private function render_missing_table_html( array $rows, array $results = array() ): string {
		$total  = isset( $results['missing_total'] ) ? (int) $results['missing_total'] : count( $rows );
		$counts = array();
		foreach ( $rows as $row ) {
			$code            = (string) ( $row['info']['problem'] ?? 'missing_file' );
			$counts[ $code ] = 1 + (int) ( $counts[ $code ] ?? 0 );
		}
		$nonce = wp_create_nonce( 'tsosk_media_nonce' );

		ob_start();
		?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Media Library items with missing or broken files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				(<?php echo esc_html( number_format_i18n( $total ) ); ?>)
			</h3>
			<p class="description">
				<?php esc_html_e( 'These are attachment records in the WordPress database. WordPress expects each one to point to a file under wp-content/uploads/. Red means the file cannot be served; amber means it works but some generated sizes are gone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( $total > count( $rows ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: rows shown, 2: total */
						esc_html__( 'Showing the first %1$s of %2$s. Fix these and run the review again to see the rest.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( number_format_i18n( count( $rows ) ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					$parts = array();
					foreach ( $counts as $code => $n ) {
						$parts[] = $this->problem_label( (string) $code ) . ': ' . number_format_i18n( $n );
					}
					echo esc_html( implode( ' · ', $parts ) );
					?>
				</p>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-media-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Stored path / expected file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Problem', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$item     = $row['attachment'];
						$info     = $row['info'];
						$edit     = get_edit_post_link( $item->ID, 'raw' );
						$upload   = admin_url( 'upload.php?item=' . (int) $item->ID );
						$severity = $this->problem_severity( $info );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( (string) $item->ID ); ?></td>
							<td data-label="<?php esc_attr_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php echo esc_html( get_the_title( $item ) ); ?>
								<?php if ( 'trash' === $item->post_status ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'In trash', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'Stored path / expected file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php if ( ! empty( $info['raw'] ) ) : ?>
									<div>
										<strong><?php esc_html_e( 'In database:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
										<?php echo esc_html( (string) $info['raw'] ); ?>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $info['relative'] ) ) : ?>
									<div>
										<strong><?php esc_html_e( 'Expected under uploads:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
										<?php echo esc_html( (string) $info['relative'] ); ?>
										<?php if ( ! empty( $info['exists'] ) ) : ?>
											<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'File found on disk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php else : ?>
											<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'File not found on disk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php endif; ?>
									</div>
								<?php elseif ( ! empty( $info['resolved'] ) ) : ?>
									<div>
										<strong><?php esc_html_e( 'WordPress resolved path:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
										<?php echo esc_html( (string) $info['resolved'] ); ?>
									</div>
								<?php elseif ( empty( $info['raw'] ) ) : ?>
									<?php esc_html_e( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $info['missing_sizes'] ) ) : ?>
									<div>
										<strong><?php esc_html_e( 'Missing on disk:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
										<?php echo esc_html( implode( ', ', array_map( 'strval', $info['missing_sizes'] ) ) ); ?>
										<?php if ( (int) ( $info['missing_sizes_total'] ?? 0 ) > count( $info['missing_sizes'] ) ) : ?>
											…
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Problem', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<span class="tsosk-badge tsosk-badge-<?php echo 'warn' === $severity ? 'info' : 'warn'; ?>">
									<?php echo esc_html( $this->problem_label( (string) ( $info['problem'] ?? '' ) ) ); ?>
								</span>
								<div><?php echo esc_html( $this->describe_attachment_problem( $info ) ); ?></div>
							</td>
							<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php if ( 'missing_sizes' === ( $info['problem'] ?? '' ) ) : ?>
									<button class="button button-small tsosk-media-regenerate" data-attachment-id="<?php echo esc_attr( (string) $item->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Regenerate Thumbnails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
									<span class="tsosk-ajax-msg"></span>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( $edit ? $edit : $upload ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Open in Media Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Library items that share one stored file.
	 *
	 * @param array<int,array{path:string,ids:int[]}> $duplicates Duplicate references.
	 * @return string
	 */
	private function render_duplicates_table_html( array $duplicates ): string {
		if ( empty( $duplicates ) ) {
			return '';
		}
		ob_start();
		?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Media Library items sharing the same file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				(<?php echo esc_html( number_format_i18n( count( $duplicates ) ) ); ?>)
			</h3>
			<p class="description">
				<?php esc_html_e( 'Several Media Library items point to one file. Deleting any of them from the Media Library deletes the file, and the others break. Keep one item per file before cleaning up.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table tsosk-media-table">
				<thead><tr>
					<th><?php esc_html_e( 'Stored file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Items', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $duplicates as $dup ) : ?>
					<tr>
						<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'Stored file', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( (string) ( $dup['path'] ?? '' ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Items', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<?php foreach ( (array) ( $dup['ids'] ?? array() ) as $dup_id ) : ?>
								<?php $link = get_edit_post_link( (int) $dup_id, 'raw' ); ?>
								<a href="<?php echo esc_url( $link ? $link : admin_url( 'upload.php?item=' . (int) $dup_id ) ); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html( (string) (int) $dup_id ); ?></a>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render orphan uploads results from a completed full review.
	 *
	 * @param array<string,mixed> $results Full review results.
	 * @return string
	 */
	private function render_orphans_table_html( array $results ): string {
		$files       = is_array( $results['orphans'] ?? null ) ? $results['orphans'] : array();
		$checked     = (int) ( $results['files_checked'] ?? 0 );
		$kind_counts = is_array( $results['kind_counts'] ?? null ) ? $results['kind_counts'] : array();
		$kind_bytes  = is_array( $results['kind_bytes'] ?? null ) ? $results['kind_bytes'] : array();
		$groups      = array(
			'media' => array(
				'title' => __( 'Unreferenced media files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'help'  => __( 'Images, documents, audio and video in uploads that no Media Library item points to. Check them before removing anything: some themes and page builders link to uploads directly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'limit' => self::SHOW_ORPHAN_MEDIA,
			),
			'other' => array(
				'title' => __( 'Other unreferenced files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'help'  => __( 'Files that are not media (text, code, archives…). Usually left by plugins or manual uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'limit' => self::SHOW_ORPHAN_OTHER,
			),
		);

		ob_start();
		?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Uploads Files Not Referenced in Database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				(<?php echo esc_html( number_format_i18n( (int) ( $results['orphans_total'] ?? count( $files ) ) ) ); ?>
				<?php if ( ! empty( $results['orphans_bytes'] ) ) : ?>
					· <?php echo esc_html( size_format( (int) $results['orphans_bytes'], 1 ) ); ?>
				<?php endif; ?>)
			</h3>
			<p class="description">
				<?php esc_html_e( 'Full walk of wp-content/uploads. Sizes (-300x200), scaled or edited originals, PDF previews, WebP/AVIF copies, plugin folders and guard files are recognised and left out. Thumbnails whose original is gone are reported.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of files checked */
					esc_html__( 'Uploads files checked in the last full review: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( number_format_i18n( $checked ) )
				);
				?>
			</p>
			<?php if ( empty( $files ) ) : ?>
				<p><?php esc_html_e( 'No unreferenced files found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php foreach ( $groups as $kind => $group ) : ?>
			<?php
			$rows = array_values(
				array_filter(
					$files,
					static function ( array $file ) use ( $kind ): bool {
						return ( $file['kind'] ?? 'media' ) === $kind;
					}
				)
			);
			$total = (int) ( $kind_counts[ $kind ] ?? count( $rows ) );
			if ( 0 === $total ) {
				continue;
			}
			$shown = array_slice( $rows, 0, (int) $group['limit'] );
			?>
			<div class="tsosk-card">
				<h3>
					<?php echo esc_html( $group['title'] ); ?>
					(<?php echo esc_html( number_format_i18n( $total ) ); ?> · <?php echo esc_html( size_format( (int) ( $kind_bytes[ $kind ] ?? 0 ), 1 ) ); ?>)
				</h3>
				<p class="description"><?php echo esc_html( $group['help'] ); ?></p>
				<?php if ( $total > count( $shown ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: rows shown, 2: total */
							esc_html__( 'Showing the %1$s largest of %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							esc_html( number_format_i18n( count( $shown ) ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</p>
				<?php endif; ?>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-media-table">
					<thead><tr>
						<th><?php esc_html_e( 'Relative File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $shown as $file ) : ?>
						<tr>
							<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'Relative File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php echo esc_html( (string) ( $file['relative'] ?? '' ) ); ?>
								<?php if ( 'thumbnail_no_parent' === ( $file['note'] ?? '' ) ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Size without its original', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( size_format( (int) ( $file['size'] ?? 0 ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
		<?php endforeach; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One page of unattached media (no parent, or a parent that no longer exists).
	 *
	 * @param int $page Page number (1-based).
	 * @return array{items:WP_Post[],total:int,featured:array<int,bool>}
	 */
	private function get_unattached_page( int $page ): array {
		global $wpdb;

		$where = "p.post_type = 'attachment' AND p.post_status <> 'auto-draft'
			AND ( p.post_parent = 0 OR NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} par WHERE par.ID = p.post_parent ) )";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where only contains the static table name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" );
		$max   = max( 1, (int) ceil( $total / self::UNATTACHED_PER_PAGE ) );
		$page  = min( $page, $max );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				self::UNATTACHED_PER_PAGE,
				( $page - 1 ) * self::UNATTACHED_PER_PAGE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof WP_Post ) {
				$items[] = $post;
			}
		}

		// "Unattached" is not "unused": a featured image has no parent yet is in use.
		$featured = array();
		if ( ! empty( $items ) ) {
			$item_ids = array_map( 'intval', wp_list_pluck( $items, 'ID' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$used = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
					  WHERE meta_key = '_thumbnail_id' AND meta_value IN (" . implode( ',', array_fill( 0, count( $item_ids ), '%s' ) ) . ')',
					...array_map( 'strval', $item_ids )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			foreach ( $used as $used_id ) {
				$featured[ (int) $used_id ] = true;
			}
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'featured' => $featured,
		);
	}

	/**
	 * Whether thumbnail regeneration applies to this attachment.
	 *
	 * @param WP_Post      $attachment Attachment post.
	 * @param string|false $file       Attached file path.
	 * @return bool
	 */
	private function can_regenerate_thumbnails( WP_Post $attachment, $file ): bool {
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		if ( function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $attachment ) ) {
			return true;
		}

		$mime = get_post_mime_type( $attachment );
		if ( is_string( $mime ) && str_starts_with( $mime, 'image/' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Render the unattached media card with pagination.
	 *
	 * @param array{items:WP_Post[],total:int,featured:array<int,bool>} $data  Page data.
	 * @param string                                                    $nonce Nonce.
	 * @param int                                                       $page  Current page.
	 */
	private function render_unattached_card( array $data, string $nonce, int $page ): void {
		$total = (int) $data['total'];
		$pages = max( 1, (int) ceil( $total / self::UNATTACHED_PER_PAGE ) );
		$page  = min( $page, $pages );
		$base  = admin_url( 'tools.php?page=tso-swiss-knife&tab=media-cleaner' );
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Unattached Media', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> (<?php echo esc_html( number_format_i18n( $total ) ); ?>)</h3>
			<p class="description">
				<?php esc_html_e( 'Media Library items with no parent post, or whose parent post was deleted. Unattached does not mean unused: featured images, widgets, page builders and custom fields often use media that is not attached. Regenerate thumbnails only applies to image attachments.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $data['items'] ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-media-table">
					<thead><tr>
						<th><?php esc_html_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $data['items'] as $item ) : ?>
						<?php
						$file = get_attached_file( $item->ID );
						$edit = get_edit_post_link( $item->ID, 'raw' );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( (string) $item->ID ); ?></td>
							<td data-label="<?php esc_attr_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php echo esc_html( get_the_title( $item ) ); ?>
								<?php if ( ! empty( $data['featured'][ (int) $item->ID ] ) ) : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'In use: featured image', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php if ( (int) $item->post_parent > 0 ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Parent post deleted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $file ? $file : __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php if ( $this->can_regenerate_thumbnails( $item, $file ) ) : ?>
									<button class="button button-small tsosk-media-regenerate" data-attachment-id="<?php echo esc_attr( (string) $item->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Regenerate Thumbnails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
									<span class="tsosk-ajax-msg"></span>
								<?php elseif ( $file && file_exists( $file ) ) : ?>
									<span class="tsosk-badge"><?php esc_html_e( 'No thumbnails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php if ( $edit ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Open in Media Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php if ( $pages > 1 ) : ?>
					<p class="tsosk-media-pager">
						<?php if ( $page > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'mc_page', $page - 1, $base ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
						<?php endif; ?>
						<span class="description">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages */
								esc_html__( 'Page %1$d of %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								(int) $page,
								(int) $pages
							);
							?>
						</span>
						<?php if ( $page < $pages ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'mc_page', $page + 1, $base ) ); ?>"><?php esc_html_e( 'Next', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> &rarr;</a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
