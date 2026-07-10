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
	}

	/**
	 * AJAX: refresh uploads footprint scan.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_media_footprint_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$result = TSOSK_Uploads_Scanner::scan_footprint();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		set_transient( TSOSK_Uploads_Scanner::TRANSIENT_FOOTPRINT, $result, TSOSK_Uploads_Scanner::CACHE_TTL );

		wp_send_json_success(
			array(
				'message' => __( 'Uploads scan completed.', 'tso-swiss-knife' ),
				'html'    => $this->render_stats_html( $result ),
			)
		);
	}

	/**
	 * Render module UI.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_media_footprint_nonce' );
		$stats = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_FOOTPRINT );
		if ( ! is_array( $stats ) ) {
			$stats = null;
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'See how much disk space wp-content/uploads uses: totals by month and file type, largest files, and how much space thumbnails take compared to originals.', 'tso-swiss-knife' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="tsosk-media-footprint-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Scan uploads folder', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-media-footprint-msg"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Scan results are cached for 10 minutes. Large sites may hit the file limit — review totals before deleting anything.', 'tso-swiss-knife' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-cleaner' ) ); ?>">
				<?php esc_html_e( 'Open Media Cleaner', 'tso-swiss-knife' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=image-sizes-audit' ) ); ?>">
				<?php esc_html_e( 'Open Image Sizes Audit', 'tso-swiss-knife' ); ?>
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
		<?php
	}

	/**
	 * @return void
	 */
	private function render_empty_state(): void {
		?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'No scan data yet. Click “Scan uploads folder” to analyze disk usage.', 'tso-swiss-knife' ); ?>
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
			<h3><?php esc_html_e( 'Summary', 'tso-swiss-knife' ); ?></h3>
			<table class="widefat tsosk-kv-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Files scanned', 'tso-swiss-knife' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $scanned ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total disk usage', 'tso-swiss-knife' ); ?></th>
						<td><strong><?php echo esc_html( size_format( $total, 2 ) ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Original media (est.)', 'tso-swiss-knife' ); ?></th>
						<td><?php echo esc_html( size_format( $original, 2 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Thumbnails & derivatives', 'tso-swiss-knife' ); ?></th>
						<td><?php echo esc_html( size_format( $derivative, 2 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other files', 'tso-swiss-knife' ); ?></th>
						<td><?php echo esc_html( size_format( $other, 2 ) ); ?></td>
					</tr>
					<?php if ( $scanned_at > 0 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last scan', 'tso-swiss-knife' ); ?></th>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $scanned_at ) ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $stats['truncated'] ) ) : ?>
			<p class="tsosk-notice tsosk-notice-warn" style="margin-top:12px;">
				<?php esc_html_e( 'Scan stopped at the file safety limit. Totals are partial — use for guidance only.', 'tso-swiss-knife' ); ?>
			</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $stats['by_month'] ) && is_array( $stats['by_month'] ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Usage by month', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Month', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
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
			<h3><?php esc_html_e( 'Usage by file type', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Extension', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
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
			<h3><?php esc_html_e( 'Largest files (top 20)', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Path', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Type', 'tso-swiss-knife' ); ?></th>
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
									? esc_html__( 'Derivative', 'tso-swiss-knife' )
									: esc_html__( 'Original / other', 'tso-swiss-knife' );
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
}
