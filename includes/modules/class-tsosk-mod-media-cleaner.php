<?php
/**
 * TSO Swiss Knife – Module: Media Cleaner.
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
	private const TRANSIENT_FULL_RESULTS = 'tsosk_media_full_review_v1';

	/** Transient: in-progress chunked scan state. */
	private const TRANSIENT_FULL_STATE = 'tsosk_media_full_review_state';

	/** Attachments inspected per AJAX chunk. */
	private const CHUNK_ATTACHMENTS = 80;

	/** Upload files inspected per AJAX chunk. */
	private const CHUNK_FILES = 500;

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
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( __( 'Attachment file not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		tsosk_require_wp_admin( 'includes/image.php' );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( empty( $metadata ) || is_wp_error( $metadata ) ) {
			wp_send_json_error( __( 'Could not regenerate attachment metadata.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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

		$done = ( 'done' === ( $state['phase'] ?? '' ) );
		if ( $done ) {
			$results = array(
				'missing'             => $state['missing'] ?? array(),
				'orphans'             => $state['orphans'] ?? array(),
				'attachments_total'   => (int) ( $state['attachment_total'] ?? 0 ),
				'attachments_checked' => (int) ( $state['attachment_checked'] ?? 0 ),
				'files_checked'       => (int) ( $state['files_checked'] ?? 0 ),
				'scanned_at'          => time(),
				'complete'            => true,
			);
			set_transient( self::TRANSIENT_FULL_RESULTS, $results, DAY_IN_SECONDS );
			delete_transient( self::TRANSIENT_FULL_STATE );

			wp_send_json_success(
				array(
					'done'     => true,
					'message'  => __( 'Full media review completed. Every Media Library item and every eligible uploads file was checked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'progress' => $this->format_full_review_progress( $state ),
					'html'     => array(
						'missing' => $this->render_missing_table_html( $this->hydrate_missing_rows( $results['missing'] ) ),
						'orphans' => $this->render_orphans_table_html( $results ),
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
	 * Render media diagnostics.
	 */
	public function render(): void {
		$nonce      = wp_create_nonce( 'tsosk_media_nonce' );
		$unattached = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => 0,
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);

		$cached  = get_transient( self::TRANSIENT_FULL_RESULTS );
		$has_full = is_array( $cached ) && ! empty( $cached['complete'] );
		$missing  = $has_full ? $this->hydrate_missing_rows( $cached['missing'] ?? array() ) : array();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Diagnoses Media Library problems: attachment records in the database whose file is missing or whose path is stored incorrectly, media not attached to any post, and files in uploads that WordPress does not know about.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
				esc_html_e( 'No full review yet. Run a full review to check every Media Library item and every eligible file under wp-content/uploads/ (in batches, until finished).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			?>
		</p>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-footprint' ) ); ?>">
				<?php esc_html_e( 'Open Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=image-sizes-audit' ) ); ?>">
				<?php esc_html_e( 'Open Image Sizes Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<div id="tsosk-media-missing-wrap">
			<?php
			if ( $has_full ) {
				echo $this->render_missing_table_html( $missing ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
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

		<?php $this->render_attachment_table( __( 'Unattached Media', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $unattached, $nonce, false, 'unattached' ); ?>

		<div id="tsosk-media-orphans-wrap">
			<?php
			if ( $has_full ) {
				echo $this->render_orphans_table_html( $cached ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			} else {
				?>
				<div class="tsosk-card">
					<h3><?php esc_html_e( 'Uploads Files Not Referenced in Database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Run a full media review to walk the entire uploads folder (excluding protected plugin folders and thumbnail variants).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
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
		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

		return array(
			'phase'               => 'attachments',
			'attachment_total'    => $count,
			'attachment_offset'   => 0,
			'attachment_checked'  => 0,
			'missing'             => array(),
			'path_owners'         => array(),
			'known_map'           => $this->build_known_attached_file_map( $base ),
			'base'                => $base,
			'queue'               => array( $base ),
			'pending_files'       => array(),
			'files_checked'       => 0,
			'orphans'             => array(),
		);
	}

	/**
	 * Process one chunk of the full review.
	 *
	 * @param array<string,mixed> $state Scan state.
	 * @return array<string,mixed>|WP_Error
	 */
	private function process_full_review_chunk( array $state ) {
		$phase = (string) ( $state['phase'] ?? '' );

		if ( 'attachments' === $phase ) {
			$offset  = (int) ( $state['attachment_offset'] ?? 0 );
			$total   = (int) ( $state['attachment_total'] ?? 0 );
			$owners  = is_array( $state['path_owners'] ?? null ) ? $state['path_owners'] : array();
			$missing = is_array( $state['missing'] ?? null ) ? $state['missing'] : array();

			$ids = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => self::CHUNK_ATTACHMENTS,
					'offset'                 => $offset,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $ids as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				$info          = $this->inspect_attachment_file( $attachment_id );
				++$state['attachment_checked'];

				if ( $info['exists'] && 'ok' === $info['problem'] ) {
					continue;
				}

				$key = '' !== $info['relative'] ? $info['relative'] : $info['raw'];
				if ( '' !== $key ) {
					if ( isset( $owners[ $key ] ) ) {
						$info['duplicate_of'] = (int) $owners[ $key ];
					} else {
						$owners[ $key ] = $attachment_id;
					}
				}

				$missing[] = array(
					'id'   => $attachment_id,
					'info' => $info,
				);
			}

			$state['path_owners']       = $owners;
			$state['missing']           = $missing;
			$state['attachment_offset'] = $offset + count( (array) $ids );

			if ( empty( $ids ) || $state['attachment_offset'] >= $total ) {
				$state['phase'] = 'orphans';
			}

			return $state;
		}

		if ( 'orphans' === $phase ) {
			$base      = (string) ( $state['base'] ?? '' );
			$queue     = is_array( $state['queue'] ?? null ) ? $state['queue'] : array();
			$pending   = is_array( $state['pending_files'] ?? null ) ? $state['pending_files'] : array();
			$known_map = is_array( $state['known_map'] ?? null ) ? $state['known_map'] : array();
			$orphans   = is_array( $state['orphans'] ?? null ) ? $state['orphans'] : array();
			$checked   = (int) ( $state['files_checked'] ?? 0 );
			$budget    = self::CHUNK_FILES;

			while ( $budget > 0 && ( ! empty( $pending ) || ! empty( $queue ) ) ) {
				if ( ! empty( $pending ) ) {
					$path = (string) array_shift( $pending );
					if ( '' === $path || ! is_file( $path ) || ! str_starts_with( wp_normalize_path( $path ), $base ) ) {
						continue;
					}
					++$checked;
					--$budget;
					$relative = ltrim( str_replace( $base, '', wp_normalize_path( $path ) ), '/' );
					if (
						! TSOSK_Uploads_Scanner::is_protected_upload_relative_path( $relative )
						&& ! TSOSK_Uploads_Scanner::is_guard_upload_file( $relative )
						&& ! preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp)$/i', $relative )
						&& ! isset( $known_map[ $relative ] )
					) {
						$orphans[] = array(
							'relative' => $relative,
							'size'     => (int) filesize( $path ),
						);
					}
					continue;
				}

				$dir = (string) array_shift( $queue );
				if ( '' === $dir || ! is_dir( $dir ) || ! str_starts_with( wp_normalize_path( $dir ), $base ) ) {
					continue;
				}

				$handle = @opendir( $dir );
				if ( ! $handle ) {
					continue;
				}

				$subdirs = array();
				$files   = array();
				while ( false !== ( $entry = readdir( $handle ) ) ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = wp_normalize_path( trailingslashit( $dir ) . $entry );
					if ( ! str_starts_with( $path, $base ) ) {
						continue;
					}
					if ( is_dir( $path ) ) {
						if ( ! TSOSK_Uploads_Scanner::should_skip_upload_dir( $entry ) ) {
							$subdirs[] = $path;
						}
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
					++$checked;
					--$budget;
					$relative = ltrim( str_replace( $base, '', $file_path ), '/' );
					if (
						TSOSK_Uploads_Scanner::is_protected_upload_relative_path( $relative )
						|| TSOSK_Uploads_Scanner::is_guard_upload_file( $relative )
						|| preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp)$/i', $relative )
					) {
						continue;
					}
					if ( ! isset( $known_map[ $relative ] ) ) {
						$orphans[] = array(
							'relative' => $relative,
							'size'     => (int) filesize( $file_path ),
						);
					}
				}
			}

			$state['queue']         = $queue;
			$state['pending_files'] = $pending;
			$state['orphans']       = $orphans;
			$state['files_checked'] = $checked;

			if ( empty( $queue ) && empty( $pending ) ) {
				$state['phase'] = 'done';
			}

			return $state;
		}

		$state['phase'] = 'done';
		return $state;
	}

	/**
	 * Build map of known Media Library relative paths (normalizes URL meta).
	 *
	 * @param string $basedir Uploads basedir with trailing slash.
	 * @return array<string,bool>
	 */
	private function build_known_attached_file_map( string $basedir ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$known = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attached_file' ) );
		$map   = array();

		foreach ( (array) $known as $raw ) {
			$raw = (string) $raw;
			$rel = $this->normalize_attached_file_relative( $raw, $basedir );
			if ( '' !== $rel ) {
				$map[ $rel ] = true;
			}
			$norm = wp_normalize_path( $raw );
			if ( '' !== $norm ) {
				$map[ $norm ] = true;
			}
		}

		return $map;
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
	 * @param array<int,array{id:int,info:array}> $stored Stored rows.
	 * @return array<int,array{attachment:WP_Post,info:array}>
	 */
	private function hydrate_missing_rows( array $stored ): array {
		$rows = array();
		foreach ( $stored as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$rows[] = array(
				'attachment' => $post,
				'info'       => is_array( $row['info'] ?? null ) ? $row['info'] : array(),
			);
		}
		return $rows;
	}

	/**
	 * Inspect how WordPress stores and resolves an attachment file path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{raw:string,resolved:string,relative:string,exists:bool,problem:string,duplicate_of:int}
	 */
	private function inspect_attachment_file( int $attachment_id ): array {
		$raw      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$resolved = (string) ( get_attached_file( $attachment_id ) ?: '' );
		$uploads  = wp_upload_dir();
		$basedir  = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) : '';

		$relative = $this->normalize_attached_file_relative( $raw, $basedir );
		$exists   = false;
		$problem  = 'ok';

		if ( '' === $raw ) {
			$problem = 'empty';
		} elseif ( $this->is_url_path( $raw ) ) {
			$problem = 'url_in_meta';
		} elseif ( '' === $relative ) {
			$problem = 'invalid';
		}

		$check_path = '';
		if ( '' !== $relative && '' !== $basedir ) {
			$check_path = $basedir . ltrim( $relative, '/' );
			$exists     = file_exists( $check_path );
		}
		if ( ! $exists && '' !== $resolved ) {
			$exists = file_exists( $resolved );
		}

		if ( $exists && 'url_in_meta' === $problem ) {
			// File is on disk, but the database path is wrong (full URL stored).
			$problem = 'url_in_meta';
		} elseif ( ! $exists && 'ok' === $problem ) {
			$problem = 'missing_file';
		}

		return array(
			'raw'          => $raw,
			'resolved'     => $resolved,
			'relative'     => $relative,
			'exists'       => $exists,
			'problem'      => $problem,
			'duplicate_of' => 0,
		);
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
	 * Whether a stored path looks like an absolute URL.
	 *
	 * @param string $path Path or URL.
	 * @return bool
	 */
	private function is_url_path( string $path ): bool {
		return (bool) preg_match( '#^https?://#i', $path );
	}

	/**
	 * Human-readable problem label for a missing/broken attachment row.
	 *
	 * @param array{raw:string,resolved:string,relative:string,exists:bool,problem:string,duplicate_of:int} $info File info.
	 * @return string
	 */
	private function describe_attachment_problem( array $info ): string {
		switch ( $info['problem'] ) {
			case 'url_in_meta':
				if ( $info['exists'] ) {
					return __( 'Broken database path: a full URL was stored instead of a relative file path. The file itself seems to exist on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				}
				return __( 'Broken database path: a full URL was stored instead of a relative file path, and the file was not found on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'empty':
				return __( 'No file path is stored in the database for this Media Library item.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'invalid':
				return __( 'The stored file path could not be interpreted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'missing_file':
			default:
				return __( 'The Media Library entry exists in the database, but the file is not on the server at the expected path.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
	}

	/**
	 * Render the missing / broken attachment diagnostics table.
	 *
	 * @param array<int,array{attachment:WP_Post,info:array{raw:string,resolved:string,relative:string,exists:bool,problem:string,duplicate_of:int}}> $rows Rows.
	 * @return string
	 */
	private function render_missing_table_html( array $rows ): string {
		ob_start();
		?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Media Library items with missing or broken files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				(<?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?>)
			</h3>
			<p class="description">
				<?php esc_html_e( 'These are attachment records in the WordPress database (Media Library), not random files on disk. WordPress expects each one to point to a file under wp-content/uploads/. If the stored path is a full URL (common after migrations), the path shown by WordPress looks duplicated and wrong — that is a database problem, not a real folder named “http:”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
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
						$item   = $row['attachment'];
						$info   = $row['info'];
						$edit   = get_edit_post_link( $item->ID, 'raw' );
						$upload = admin_url( 'upload.php?item=' . (int) $item->ID );
						?>
						<tr>
							<td><?php echo esc_html( (string) $item->ID ); ?></td>
							<td><?php echo esc_html( get_the_title( $item ) ); ?></td>
							<td class="tsosk-code" style="word-break:break-all;">
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
								<?php else : ?>
									<?php esc_html_e( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $info['duplicate_of'] ) ) : ?>
									<div class="description">
										<?php
										printf(
											/* translators: %d: other attachment ID sharing the same path */
											esc_html__( 'Same stored path as attachment #%d (duplicate reference).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											(int) $info['duplicate_of']
										);
										?>
									</div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $this->describe_attachment_problem( $info ) ); ?></td>
							<td>
								<?php if ( $edit ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Open in Media Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</a>
								<?php else : ?>
									<a class="button button-small" href="<?php echo esc_url( $upload ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Open in Media Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
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
		$files   = is_array( $results['orphans'] ?? null ) ? $results['orphans'] : array();
		$checked = (int) ( $results['files_checked'] ?? 0 );
		ob_start();
		?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Uploads Files Not Referenced in Database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				(<?php echo esc_html( number_format_i18n( count( $files ) ) ); ?>)
			</h3>
			<p class="description">
				<?php esc_html_e( 'Complete walk of wp-content/uploads. Skips thumbnail variants (-WIDTHxHEIGHT), protected plugin folders (TSO backups/cache/config), and guard files (index.php, .htaccess).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
				<p><?php esc_html_e( 'No unreferenced files found. The full uploads walk completed with no orphans outside the excluded folders.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr><th><?php esc_html_e( 'Relative File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $files as $file ) : ?>
						<tr>
							<td class="tsosk-code"><?php echo esc_html( (string) ( $file['relative'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $file['size'] ?? 0 ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
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
	 * Render attachment table.
	 *
	 * @param string $title   Card title.
	 * @param array  $items   Attachments.
	 * @param string $nonce   Nonce.
	 * @param bool   $missing Whether these files are missing.
	 * @param string $context Table context: missing, unattached.
	 */
	private function render_attachment_table( string $title, array $items, string $nonce, bool $missing, string $context = 'missing' ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( count( $items ) ) ); ?>)</h3>
			<?php if ( 'unattached' === $context ) : ?>
				<p class="description">
					<?php esc_html_e( 'These files are in the Media Library but not attached to any post or page. Regenerate thumbnails only applies to image attachments.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php endif; ?>
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr><th><?php esc_html_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'File', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$file = get_attached_file( $item->ID );
						$edit = get_edit_post_link( $item->ID, 'raw' );
						?>
						<tr>
							<td><?php echo esc_html( (string) $item->ID ); ?></td>
							<td><?php echo esc_html( get_the_title( $item ) ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $file ?: __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></td>
							<td>
								<?php if ( ! $missing && $this->can_regenerate_thumbnails( $item, $file ) ) : ?>
									<button class="button button-small tsosk-media-regenerate" data-attachment-id="<?php echo esc_attr( (string) $item->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Regenerate Thumbnails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
									<span class="tsosk-ajax-msg"></span>
								<?php elseif ( ! $missing && $file && file_exists( $file ) ) : ?>
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
			<?php endif; ?>
		</div>
		<?php
	}
}
