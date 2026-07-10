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
	}

	/**
	 * AJAX: regenerate attachment metadata.
	 */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'tsosk_media_nonce', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'tso-swiss-knife' ), 403 );
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( __( 'Attachment file not found.', 'tso-swiss-knife' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( empty( $metadata ) || is_wp_error( $metadata ) ) {
			wp_send_json_error( __( 'Could not regenerate attachment metadata.', 'tso-swiss-knife' ) );
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
		wp_send_json_success( __( 'Attachment thumbnails regenerated.', 'tso-swiss-knife' ) );
	}

	/**
	 * Render media diagnostics.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_media_nonce' );
		$unattached = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => 0,
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);
		$missing = $this->get_missing_attachments();
		$orphan_files = $this->get_orphan_upload_files();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Review media-library mismatches: unattached media, database attachments missing files, files in uploads not referenced by WordPress, and thumbnail metadata.', 'tso-swiss-knife' ); ?>
		</p>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-footprint' ) ); ?>">
				<?php esc_html_e( 'Open Uploads Disk Footprint', 'tso-swiss-knife' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=image-sizes-audit' ) ); ?>">
				<?php esc_html_e( 'Open Image Sizes Audit', 'tso-swiss-knife' ); ?>
			</a>
		</p>

		<?php $this->render_attachment_table( __( 'Missing Attachment Files', 'tso-swiss-knife' ), $missing, $nonce, true ); ?>
		<?php $this->render_attachment_table( __( 'Unattached Media', 'tso-swiss-knife' ), $unattached, $nonce, false, 'unattached' ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Uploads Files Not Referenced in Database', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Limited scan of wp-content/uploads for files not registered as media attachments. Plugin-owned folders (TSO backups, cache, translations, config) are excluded — do not delete those manually.', 'tso-swiss-knife' ); ?>
			</p>
			<?php if ( empty( $orphan_files ) ) : ?>
				<p><?php esc_html_e( 'No unreferenced files found in the limited scan.', 'tso-swiss-knife' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr><th><?php esc_html_e( 'Relative File', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $orphan_files as $file ) : ?>
						<tr><td class="tsosk-code"><?php echo esc_html( $file['relative'] ); ?></td><td><?php echo esc_html( size_format( $file['size'], 2 ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Find attachments with missing files.
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_missing_attachments(): array {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
			)
		);
		$missing = array();
		foreach ( $attachments as $attachment ) {
			$file = get_attached_file( $attachment->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				$missing[] = $attachment;
			}
		}
		return $missing;
	}

	/**
	 * Limited scan for files in uploads not referenced by attachment meta.
	 *
	 * @return array<int,array{relative:string,size:int}>
	 */
	private function get_orphan_upload_files(): array {
		global $wpdb;

		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$known = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attached_file' ) );
		$known_map = array_fill_keys( array_map( 'wp_normalize_path', (array) $known ), true );
		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$queue = array( $base );
		$out = array();
		$visited = 0;

		while ( $queue && $visited < 300 ) {
			$dir = array_shift( $queue );
			$handle = @opendir( $dir );
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) && $visited < 300 ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = wp_normalize_path( trailingslashit( $dir ) . $entry );
				if ( is_dir( $path ) ) {
					if ( TSOSK_Uploads_Scanner::should_skip_upload_dir( $entry ) ) {
						continue;
					}
					$queue[] = $path;
					continue;
				}
				$visited++;
				$relative = ltrim( str_replace( $base, '', $path ), '/' );
				if ( TSOSK_Uploads_Scanner::is_protected_upload_relative_path( $relative ) ) {
					continue;
				}
				if ( TSOSK_Uploads_Scanner::is_guard_upload_file( $relative ) ) {
					continue;
				}
				if ( ! isset( $known_map[ $relative ] ) && ! preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp)$/i', $relative ) ) {
					$out[] = array(
						'relative' => $relative,
						'size'     => (int) filesize( $path ),
					);
				}
			}
			closedir( $handle );
		}

		return array_slice( $out, 0, 50 );
	}

	/**
	 * Whether thumbnail regeneration applies to this attachment.
	 *
	 * @param WP_Post     $attachment Attachment post.
	 * @param string|false $file      Attached file path.
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
	 * @param string $title      Card title.
	 * @param array  $items      Attachments.
	 * @param string $nonce      Nonce.
	 * @param bool   $missing    Whether these files are missing.
	 * @param string $context    Table context: missing, unattached.
	 */
	private function render_attachment_table( string $title, array $items, string $nonce, bool $missing, string $context = 'missing' ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( count( $items ) ) ); ?>)</h3>
			<?php if ( 'unattached' === $context ) : ?>
				<p class="description">
					<?php esc_html_e( 'These files are in the Media Library but not attached to any post or page. Regenerate thumbnails only applies to image attachments.', 'tso-swiss-knife' ); ?>
				</p>
			<?php endif; ?>
			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table">
					<thead><tr><th><?php esc_html_e( 'ID', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'Title', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'File', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'Action', 'tso-swiss-knife' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $file = get_attached_file( $item->ID ); ?>
						<tr>
							<td><?php echo esc_html( (string) $item->ID ); ?></td>
							<td><?php echo esc_html( get_the_title( $item ) ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $file ?: __( 'Unknown', 'tso-swiss-knife' ) ); ?></td>
							<td>
								<?php if ( ! $missing && $this->can_regenerate_thumbnails( $item, $file ) ) : ?>
									<button class="button button-small tsosk-media-regenerate" data-attachment-id="<?php echo esc_attr( (string) $item->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Regenerate Thumbnails', 'tso-swiss-knife' ); ?></button>
									<span class="tsosk-ajax-msg"></span>
								<?php elseif ( ! $missing && $file && file_exists( $file ) ) : ?>
									<span class="tsosk-badge"><?php esc_html_e( 'No thumbnails', 'tso-swiss-knife' ); ?></span>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Review', 'tso-swiss-knife' ); ?></span>
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
