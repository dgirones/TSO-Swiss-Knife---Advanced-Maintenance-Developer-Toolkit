<?php
/**
 * TSO Swiss Knife – Module: Uploads Disk Footprint.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Media_Footprint
 */
class TSOSK_Mod_Media_Footprint {

	/** @var TSOSK_Mod_Media_Footprint|null */
	private static $instance = null;

	/**
	 * @return TSOSK_Mod_Media_Footprint
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_media_footprint_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_tsosk_media_hygiene_scan', array( $this, 'ajax_hygiene_scan' ) );
		add_action( 'wp_ajax_tsosk_media_hygiene_delete', array( $this, 'ajax_hygiene_delete' ) );
		add_action( 'wp_ajax_tsosk_media_quarantine_restore', array( $this, 'ajax_quarantine_restore' ) );
		add_action( 'wp_ajax_tsosk_media_quarantine_purge', array( $this, 'ajax_quarantine_purge' ) );
	}

	/**
	 * AJAX: refresh uploads footprint scan.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = TSOSK_Uploads_Scanner::scan_footprint();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		set_transient( TSOSK_Uploads_Scanner::TRANSIENT_FOOTPRINT, $result, TSOSK_Uploads_Scanner::CACHE_TTL );

		wp_send_json_success(
			array(
				'message' => __( 'Uploads scan completed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_stats_html( $result ),
			)
		);
	}

	/**
	 * AJAX: scan uploads for removable folders.
	 */
	public function ajax_hygiene_scan(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = TSOSK_Uploads_Scanner::scan_hygiene();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		set_transient( TSOSK_Uploads_Scanner::TRANSIENT_HYGIENE, $result, TSOSK_Uploads_Scanner::CACHE_TTL );

		wp_send_json_success(
			array(
				'message' => __( 'Folder hygiene scan completed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_hygiene_html( $result ),
			)
		);
	}

	/**
	 * AJAX: move one allowlisted hygiene folder into quarantine (recoverable for 30 days).
	 */
	public function ajax_hygiene_delete(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$folder_id = isset( $_POST['folder_id'] ) ? sanitize_key( wp_unslash( $_POST['folder_id'] ) ) : '';
		if ( '' === $folder_id ) {
			wp_send_json_error( __( 'Invalid folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$scan = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_HYGIENE );
		if ( ! is_array( $scan ) ) {
			wp_send_json_error( __( 'Scan data expired. Run the hygiene scan again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$relative = '';
		foreach ( $scan['items'] as $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? '' ) === $folder_id ) {
				$relative = (string) ( $item['relative'] ?? '' );
				break;
			}
		}

		$result = TSOSK_Uploads_Scanner::quarantine_hygiene_folder( $folder_id, $scan );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'media-footprint',
			'quarantine',
			sprintf(
				/* translators: %s: folder path */
				__( 'Removable folder moved to quarantine: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$relative
			),
			array( 'folder_id' => $folder_id )
		);

		$scan = TSOSK_Uploads_Scanner::scan_hygiene();
		if ( ! is_wp_error( $scan ) ) {
			set_transient( TSOSK_Uploads_Scanner::TRANSIENT_HYGIENE, $scan, TSOSK_Uploads_Scanner::CACHE_TTL );
		}

		wp_send_json_success(
			array(
				'message'         => __( 'Folder moved to quarantine. It can be restored for 30 days.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'            => is_wp_error( $scan ) ? '' : $this->render_hygiene_html( $scan ),
				'quarantine_html' => $this->render_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: restore a quarantined folder to its original location.
	 */
	public function ajax_quarantine_restore(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_quarantine_entries();
		$label   = (string) ( $entries[ $entry_id ]['label'] ?? '' );

		$result = TSOSK_Uploads_Scanner::restore_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'media-footprint',
			'quarantine-restore',
			sprintf(
				/* translators: %s: folder path */
				__( 'Quarantined folder restored: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$label
			),
			array( 'entry_id' => $entry_id )
		);

		wp_send_json_success(
			array(
				'message' => __( 'Folder restored to its original location.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: permanently delete one quarantined folder now, before its 30-day window ends.
	 */
	public function ajax_quarantine_purge(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_quarantine_entries();
		$label   = (string) ( $entries[ $entry_id ]['label'] ?? '' );

		$result = TSOSK_Uploads_Scanner::purge_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'media-footprint',
			'quarantine-purge',
			sprintf(
				/* translators: %s: folder path */
				__( 'Quarantined folder permanently deleted: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$label
			),
			array( 'entry_id' => $entry_id )
		);

		wp_send_json_success(
			array(
				'message' => __( 'Folder permanently deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_quarantine_html(),
			)
		);
	}

	/**
	 * Render module UI.
	 */
	public function render(): void {
		TSOSK_Uploads_Scanner::purge_expired_quarantine();

		$nonce   = wp_create_nonce( 'tsosk_media_footprint_nonce' );
		$stats   = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_FOOTPRINT );
		$hygiene = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_HYGIENE );
		if ( ! is_array( $stats ) ) {
			$stats = null;
		}
		if ( ! is_array( $hygiene ) ) {
			$hygiene = null;
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'See how much disk space wp-content/uploads uses: totals by month and file type, largest files, and how much space thumbnails take compared to originals.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="tsosk-media-footprint-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Scan uploads folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-media-footprint-msg"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Scan results are cached for 10 minutes. Large sites may hit the file limit — review totals before deleting anything.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-cleaner' ) ); ?>">
				<?php esc_html_e( 'Open Media Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=image-sizes-audit' ) ); ?>">
				<?php esc_html_e( 'Open Image Sizes Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<div id="tsosk-media-footprint-results">
			<?php
			if ( is_array( $stats ) ) {
				echo $this->render_stats_html( $stats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			} else {
				$this->render_empty_state();
			}
			?>
		</div>

		<hr style="margin:28px 0;">

		<h2><?php esc_html_e( 'Folder hygiene', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Scan uploads and known cache folders (legacy TSO paths, .tmb thumbnails). Only items marked Safe can be deleted from here — others are recommendations only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<button type="button" class="button button-secondary" id="tsosk-media-hygiene-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Scan removable folders', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-media-hygiene-msg"></span>
		</p>
		<div id="tsosk-media-hygiene-results">
			<?php
			if ( is_array( $hygiene ) ) {
				echo $this->render_hygiene_html( $hygiene ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
			} else {
				?>
				<div class="tsosk-notice tsosk-notice-info">
					<?php esc_html_e( 'No hygiene scan yet. Click “Scan removable folders” to classify uploads subfolders and .tmb caches.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</div>
				<?php
			}
			?>
		</div>

		<hr style="margin:28px 0;">

		<h2><?php esc_html_e( 'Quarantine (recoverable trash)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Folders removed via Folder hygiene above are moved here first and kept for 30 days before automatic permanent deletion, so you can restore them if something breaks.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<div id="tsosk-media-quarantine-results">
			<?php echo $this->render_quarantine_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_empty_state(): void {
		?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'No scan data yet. Click “Scan uploads folder” to analyze disk usage.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $stats Scan results.
	 * @return string
	 */
	private function render_stats_html( array $stats ): string {
		ob_start();

		$total      = (int) ( $stats['total_bytes'] ?? 0 );
		$original   = (int) ( $stats['original_bytes'] ?? 0 );
		$derivative = (int) ( $stats['derivative_bytes'] ?? 0 );
		$other      = (int) ( $stats['other_bytes'] ?? 0 );
		$scanned    = (int) ( $stats['scanned_files'] ?? 0 );
		$scanned_at = (int) ( $stats['scanned_at'] ?? 0 );
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="widefat tsosk-kv-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Files scanned', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $scanned ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total disk usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><strong><?php echo esc_html( size_format( $total, 2 ) ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Original media (est.)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( size_format( $original, 2 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Thumbnails & derivatives', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( size_format( $derivative, 2 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( size_format( $other, 2 ) ); ?></td>
					</tr>
					<?php if ( $scanned_at > 0 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last scan', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $scanned_at ) ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $stats['truncated'] ) ) : ?>
			<p class="tsosk-notice tsosk-notice-warn" style="margin-top:12px;">
				<?php esc_html_e( 'Scan stopped at the file safety limit. Totals are partial — use for guidance only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $stats['by_month'] ) && is_array( $stats['by_month'] ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Usage by month', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Month', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $stats['by_month'], 0, 24, true ) as $month => $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $month ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $row['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $row['bytes'] ?? 0 ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $stats['by_extension'] ) && is_array( $stats['by_extension'] ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Space by file type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php echo $this->render_treemap_html( $stats['by_extension'], $total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Usage by file type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Extension', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $stats['by_extension'], 0, 20, true ) as $ext => $row ) : ?>
						<tr>
							<td><code>.<?php echo esc_html( (string) $ext ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $row['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $row['bytes'] ?? 0 ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $stats['largest'] ) && is_array( $stats['largest'] ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Largest files (top 20)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $stats['largest'] as $file ) : ?>
						<tr>
							<td class="tsosk-code"><?php echo esc_html( (string) ( $file['relative'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $file['size'] ?? 0 ), 2 ) ); ?></td>
							<td>
								<?php
								echo ! empty( $file['is_derivative'] )
									? esc_html__( 'Derivative', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
									: esc_html__( 'Original / other', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Pure CSS proportional-width "treemap" of disk usage by file extension.
	 *
	 * @param array<string, array{files: int, bytes: int}> $by_extension Extension => stats.
	 * @param int                                           $total        Total bytes (for percentages).
	 * @return string
	 */
	private function render_treemap_html( array $by_extension, int $total ): string {
		if ( $total <= 0 ) {
			return '';
		}

		$palette = array( '#2271b1', '#72aee6', '#00a32a', '#dba617', '#d63638', '#8c8f94', '#a7aaad', '#3582c4' );

		uasort(
			$by_extension,
			static function ( array $a, array $b ): int {
				return (int) ( $b['bytes'] ?? 0 ) <=> (int) ( $a['bytes'] ?? 0 );
			}
		);
		$top   = array_slice( $by_extension, 0, 10, true );
		$shown = 0;
		foreach ( $top as $row ) {
			$shown += (int) ( $row['bytes'] ?? 0 );
		}
		$other = max( 0, $total - $shown );

		ob_start();
		?>
		<div class="tsosk-treemap" role="img" aria-label="<?php esc_attr_e( 'Proportional disk usage by file type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
			<?php
			$tsosk_tm_index = 0;
			foreach ( $top as $tsosk_tm_ext => $tsosk_tm_row ) :
				$tsosk_tm_bytes = (int) ( $tsosk_tm_row['bytes'] ?? 0 );
				if ( $tsosk_tm_bytes <= 0 ) {
					continue;
				}
				$tsosk_tm_pct   = round( ( $tsosk_tm_bytes / $total ) * 100, 1 );
				$tsosk_tm_color = $palette[ $tsosk_tm_index % count( $palette ) ];
				++$tsosk_tm_index;
				?>
				<div class="tsosk-treemap-block"
				     style="width:<?php echo esc_attr( (string) $tsosk_tm_pct ); ?>%;background:<?php echo esc_attr( $tsosk_tm_color ); ?>;"
				     title="<?php echo esc_attr( '.' . $tsosk_tm_ext . ' — ' . size_format( $tsosk_tm_bytes, 2 ) . ' (' . $tsosk_tm_pct . '%)' ); ?>">
					<?php if ( $tsosk_tm_pct >= 6 ) : ?>
					<span class="tsosk-treemap-label">.<?php echo esc_html( (string) $tsosk_tm_ext ); ?> <?php echo esc_html( $tsosk_tm_pct . '%' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( $other > 0 ) : ?>
				<?php $tsosk_tm_other_pct = round( ( $other / $total ) * 100, 1 ); ?>
				<div class="tsosk-treemap-block tsosk-treemap-other"
				     style="width:<?php echo esc_attr( (string) $tsosk_tm_other_pct ); ?>%;"
				     title="<?php echo esc_attr( __( 'Other file types', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . ' — ' . size_format( $other, 2 ) . ' (' . $tsosk_tm_other_pct . '%)' ); ?>">
					<?php if ( $tsosk_tm_other_pct >= 6 ) : ?>
					<span class="tsosk-treemap-label"><?php esc_html_e( 'Other', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <?php echo esc_html( $tsosk_tm_other_pct . '%' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<p class="description"><?php esc_html_e( 'Each block is proportional to the disk space that file type uses. Hover a block for the exact size.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $scan Hygiene scan results.
	 * @return string
	 */
	private function render_hygiene_html( array $scan ): string {
		$items      = is_array( $scan['items'] ?? null ) ? $scan['items'] : array();
		$scanned_at = (int) ( $scan['scanned_at'] ?? 0 );
		$nonce      = wp_create_nonce( 'tsosk_media_footprint_nonce' );

		ob_start();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Removable folders', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( $scanned_at > 0 ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: localized datetime */
					esc_html__( 'Last scan: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( wp_date( 'Y-m-d H:i', $scanned_at ) )
				);
				?>
			</p>
			<?php endif; ?>

			<?php
			$tsosk_hygiene_deletable_total = 0;
			foreach ( $items as $tsosk_hygiene_item ) {
				if ( ! empty( $tsosk_hygiene_item['deletable'] ) ) {
					$tsosk_hygiene_deletable_total += (int) ( $tsosk_hygiene_item['size'] ?? 0 );
				}
			}
			?>
			<?php if ( $tsosk_hygiene_deletable_total > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: formatted size */
					esc_html__( 'Space that would be freed by moving all Safe folders to quarantine: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<strong>' . esc_html( size_format( $tsosk_hygiene_deletable_total, 2 ) ) . '</strong>'
				);
				?>
			</p>
			<?php endif; ?>

			<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'No classified folders were found under uploads or known cache paths.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-media-hygiene-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Confidence', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$confidence = (string) ( $item['confidence'] ?? 'review' );
						$badge      = 'tsosk-badge-info';
						$label      = __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						if ( 'safe' === $confidence ) {
							$badge = 'tsosk-badge-ok';
							$label = __( 'Safe', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						} elseif ( 'keep' === $confidence ) {
							$badge = 'tsosk-badge-warn';
							$label = __( 'Keep', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						}
						?>
						<tr id="tsosk-hygiene-<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>">
							<td class="tsosk-code"><?php echo esc_html( (string) ( $item['relative'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $item['size'] ?? 0 ), 2 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $item['files'] ?? 0 ) ) ); ?></td>
							<td><span class="tsosk-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
							<td><?php echo esc_html( (string) ( $item['reason'] ?? '' ) ); ?></td>
							<td>
								<?php if ( ! empty( $item['deletable'] ) ) : ?>
								<button type="button" class="button button-small button-link-delete tsosk-media-hygiene-delete"
								        data-folder-id="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>"
								        data-label="<?php echo esc_attr( (string) ( $item['relative'] ?? '' ) ); ?>">
									<?php esc_html_e( 'Move to quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<?php else : ?>
								<span class="description"><?php esc_html_e( 'Manual only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
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
	 * @return string
	 */
	private function render_quarantine_html(): string {
		$entries = TSOSK_Uploads_Scanner::get_quarantine_entries();
		$nonce   = wp_create_nonce( 'tsosk_media_footprint_nonce' );

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return (int) ( $b['quarantined_at'] ?? 0 ) <=> (int) ( $a['quarantined_at'] ?? 0 );
			}
		);

		ob_start();
		?>
		<div class="tsosk-card">
			<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'Quarantine is empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-media-quarantine-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Quarantined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Auto-deletes on', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$tsosk_q_at      = (int) ( $entry['quarantined_at'] ?? 0 );
						$tsosk_q_expires = $tsosk_q_at + ( TSOSK_Uploads_Scanner::QUARANTINE_DAYS * DAY_IN_SECONDS );
						?>
						<tr id="tsosk-quarantine-<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>">
							<td class="tsosk-code"><?php echo esc_html( (string) ( $entry['label'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $entry['size'] ?? 0 ), 2 ) ); ?></td>
							<td><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d H:i', $tsosk_q_at ) : '—' ); ?></td>
							<td><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d', $tsosk_q_expires ) : '—' ); ?></td>
							<td>
								<button type="button" class="button button-small tsosk-media-quarantine-restore"
								        data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Restore', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<button type="button" class="button button-small button-link-delete tsosk-media-quarantine-purge"
								        data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>"
								        data-label="<?php echo esc_attr( (string) ( $entry['label'] ?? '' ) ); ?>">
									<?php esc_html_e( 'Delete now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
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
}
