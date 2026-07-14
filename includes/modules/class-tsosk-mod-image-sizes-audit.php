<?php
/**
 * TSO Swiss Knife – Module: Image Sizes Audit.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Image_Sizes_Audit
 */
class TSOSK_Mod_Image_Sizes_Audit {

	/** Option key for disabled image size names (shared with WP Internals). */
	public const OPTION_DISABLED_SIZES = 'tsosk_disabled_image_sizes';

	/** @var TSOSK_Mod_Image_Sizes_Audit|null */
	private static $instance = null;

	/**
	 * @return TSOSK_Mod_Image_Sizes_Audit
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_image_sizes_audit_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_save', array( $this, 'ajax_save_disabled' ) );
	}

	/**
	 * Register runtime filter for disabled sizes.
	 */
	public function init(): void {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_disabled_image_sizes' ), 99 );
	}

	/**
	 * Prevent WordPress from generating disabled intermediate sizes on new uploads.
	 *
	 * @param array<string, array> $sizes Registered sizes.
	 * @return array<string, array>
	 */
	public function filter_disabled_image_sizes( array $sizes ): array {
		$disabled = $this->get_disabled_sizes();
		foreach ( $disabled as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}

	/**
	 * @return string[]
	 */
	private function get_disabled_sizes(): array {
		$stored = get_option( self::OPTION_DISABLED_SIZES, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'sanitize_key', $stored ) ) ) : array();
	}

	/**
	 * AJAX: run image sizes audit scan.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = TSOSK_Uploads_Scanner::scan_image_sizes();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		set_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES, $result, TSOSK_Uploads_Scanner::CACHE_TTL );

		wp_send_json_success(
			array(
				'message' => __( 'Image sizes audit completed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_audit_html( $result, $this->get_disabled_sizes() ),
			)
		);
	}

	/**
	 * AJAX: save disabled image sizes for new uploads.
	 */
	public function ajax_save_disabled(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$disabled = array();
		if ( isset( $_POST['disabled'] ) && is_array( $_POST['disabled'] ) ) {
			foreach ( wp_unslash( $_POST['disabled'] ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$disabled[] = $name;
				}
			}
		}

		$disabled = array_values( array_unique( $disabled ) );
		update_option( self::OPTION_DISABLED_SIZES, $disabled, false );

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'save',
			__( 'Image size generation settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success( __( 'Image size settings saved. New uploads will skip disabled sizes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Render module UI.
	 */
	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$disabled = $this->get_disabled_sizes();
		$audit    = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		if ( ! is_array( $audit ) ) {
			$audit = null;
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Audit how much disk space each registered image size uses, estimate recoverable space from thumbnails, and disable sizes you do not need on new uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="tsosk-image-sizes-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Run image sizes audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-image-sizes-scan-msg"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Counts are based on attachment metadata and a disk scan for unmatched derivative files. Disabling a size only affects new uploads — existing files are not deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-footprint' ) ); ?>">
				<?php esc_html_e( 'Open Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<div id="tsosk-image-sizes-results">
			<?php
			if ( is_array( $audit ) ) {
				echo $this->render_audit_html( $audit, $disabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$this->render_sizes_table_without_audit( $disabled, $nonce );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Registered sizes table when no audit has run yet.
	 *
	 * @param string[] $disabled Disabled size names.
	 * @param string   $nonce    AJAX nonce.
	 */
	private function render_sizes_table_without_audit( array $disabled, string $nonce ): void {
		$registered = function_exists( 'wp_get_registered_image_subsizes' )
			? wp_get_registered_image_subsizes()
			: array();
		?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'Run the audit to see file counts and disk usage per size.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php
		$this->render_sizes_controls( $registered, array(), $disabled, $nonce );
	}

	/**
	 * @param array<string, mixed> $audit    Audit data.
	 * @param string[]             $disabled Disabled size names.
	 * @return string
	 */
	private function render_audit_html( array $audit, array $disabled ): string {
		$nonce      = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$registered = is_array( $audit['registered'] ?? null ) ? $audit['registered'] : array();
		$by_size    = is_array( $audit['by_size'] ?? null ) ? $audit['by_size'] : array();
		$full       = is_array( $audit['full'] ?? null ) ? $audit['full'] : array();
		$unmatched  = is_array( $audit['unmatched_derivatives'] ?? null ) ? $audit['unmatched_derivatives'] : array();

		ob_start();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Audit summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="widefat tsosk-kv-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attachments with metadata', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) ( $audit['attachments_scanned'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Full-size files on disk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: file count, 2: formatted size */
								esc_html__( '%1$s files — %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								esc_html( number_format_i18n( (int) ( $full['files'] ?? 0 ) ) ),
								esc_html( size_format( (int) ( $full['bytes'] ?? 0 ), 2 ) )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Unmatched derivatives', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: file count, 2: formatted size */
								esc_html__( '%1$s files — %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								esc_html( number_format_i18n( (int) ( $unmatched['files'] ?? 0 ) ) ),
								esc_html( size_format( (int) ( $unmatched['bytes'] ?? 0 ), 2 ) )
							);
							?>
						</td>
					</tr>
					<?php if ( ! empty( $audit['scanned_at'] ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $audit['scanned_at'] ) ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		$this->render_sizes_controls( $registered, $by_size, $disabled, $nonce );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, array> $registered Registered subsizes.
	 * @param array<string, array> $by_size    Audit stats per size.
	 * @param string[]             $disabled Disabled names.
	 * @param string               $nonce    Nonce.
	 */
	private function render_sizes_controls( array $registered, array $by_size, array $disabled, string $nonce ): void {
		$core_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large' );
		?>
		<div class="tsosk-card">
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<?php esc_html_e( 'Risks when disabling sizes: broken images in old content, missing srcset variants, and layout issues in themes or page builders that expect specific dimensions. Test on staging first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<h3><?php esc_html_e( 'Registered image sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-image-sizes-table">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'On', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Dimensions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Disk usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'If disabled (new uploads)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $registered as $name => $size ) : ?>
						<?php
						$is_core    = in_array( $name, $core_sizes, true );
						$is_enabled = ! in_array( $name, $disabled, true );
						$stats      = $by_size[ $name ] ?? array( 'files' => 0, 'bytes' => 0 );
						$width      = (int) ( $size['width'] ?? 0 );
						$height     = (int) ( $size['height'] ?? 0 );
						$crop       = ! empty( $size['crop'] );
						?>
						<tr>
							<td>
								<input type="checkbox" class="tsosk-img-audit-size-toggle"
								       data-name="<?php echo esc_attr( $name ); ?>"
								       <?php checked( $is_enabled ); ?>
								       <?php disabled( $is_core ); ?>>
							</td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<?php if ( $is_core ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: width, 2: height, 3: crop yes/no */
										__( '%1$d × %2$d — crop: %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										$width,
										$height,
										$crop ? __( 'yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'no', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
									)
								);
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $stats['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td>
								<?php if ( $is_core ) : ?>
									<?php esc_html_e( 'Always generated', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php elseif ( ! $is_enabled ) : ?>
									<?php esc_html_e( 'Skipped on new uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php else : ?>
									<?php
									printf(
										/* translators: %s: formatted disk size */
										esc_html__( 'Could save ~%s on future uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) )
									);
									?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-image-sizes-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save image size settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-image-sizes-save-msg"></span>
			</p>
		</div>
		<?php
	}
}
