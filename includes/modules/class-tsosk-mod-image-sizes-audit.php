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

	/** Option key for disabled image size names. */
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
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine', array( $this, 'ajax_quarantine_files' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine_restore', array( $this, 'ajax_quarantine_restore' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine_purge', array( $this, 'ajax_quarantine_purge' ) );
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
				if ( ! is_scalar( $name ) ) {
					continue;
				}
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$disabled[] = $name;
				}
			}
		}

		// The UI disables the checkbox for these via the "disabled" HTML
		// attribute only — that's a client-side convenience, not a security
		// boundary. Re-validate server-side so a crafted request can't disable
		// generation of the thumbnail sizes WordPress core and most themes
		// depend on being present.
		$core_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large' );
		$disabled   = array_values( array_diff( array_unique( $disabled ), $core_sizes ) );
		update_option( self::OPTION_DISABLED_SIZES, $disabled, false );

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'save',
			__( 'Image size generation settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success( __( 'Image size settings saved. New uploads will skip disabled sizes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: move existing files for sizes already disabled for new uploads into quarantine.
	 *
	 * Files are not deleted here — they are moved to a quarantine folder so the admin can
	 * verify the site still works and then either restore them or delete them for good.
	 * Eligibility is re-validated server-side: a request can only ever target a currently
	 * registered size the admin has already turned off (the stored disabled-sizes option),
	 * or a size that is not registered by any active theme or plugin at all — a legacy size
	 * left over from an old theme or a deactivated plugin, which is inherently "off" since
	 * nothing generates it for new uploads. An active, still-enabled registered size is
	 * never eligible, regardless of what the client sends.
	 */
	public function ajax_quarantine_files(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$requested = array();
		if ( isset( $_POST['sizes'] ) && is_array( $_POST['sizes'] ) ) {
			foreach ( wp_unslash( $_POST['sizes'] ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_scalar( $name ) ) {
					continue;
				}
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$requested[] = $name;
				}
			}
		}

		$disabled          = $this->get_disabled_sizes();
		$registered_names  = function_exists( 'wp_get_registered_image_subsizes' )
			? array_keys( wp_get_registered_image_subsizes() )
			: array();
		$sizes             = array_values(
			array_filter(
				array_unique( $requested ),
				static function ( string $name ) use ( $disabled, $registered_names ): bool {
					return in_array( $name, $disabled, true ) || ! in_array( $name, $registered_names, true );
				}
			)
		);

		if ( empty( $sizes ) ) {
			wp_send_json_error( __( 'No eligible sizes selected. Only sizes already turned off for new uploads can be quarantined here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = TSOSK_Uploads_Scanner::quarantine_image_size_files( $sizes );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine',
			sprintf(
				/* translators: 1: comma-separated size names, 2: file count, 3: formatted size */
				__( 'Moved existing files for image size(s) %1$s to quarantine: %2$d files, %3$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes ),
				(int) $result['files'],
				size_format( (int) $result['bytes'], 2 )
			)
		);

		// The counts just changed — refresh the cached audit instead of leaving a stale one.
		$audit = TSOSK_Uploads_Scanner::scan_image_sizes();
		$html  = '';
		if ( ! is_wp_error( $audit ) ) {
			set_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES, $audit, TSOSK_Uploads_Scanner::CACHE_TTL );
			$html = $this->render_audit_html( $audit, $this->get_disabled_sizes() );
		} else {
			delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		}

		wp_send_json_success(
			array(
				'message'         => sprintf(
					/* translators: 1: file count, 2: formatted size */
					__( 'Moved %1$d files (%2$s) to quarantine. They can be restored or permanently deleted below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $result['files'],
					size_format( (int) $result['bytes'], 2 )
				),
				'html'            => $html,
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: restore a quarantined image-size batch to its original files/locations.
	 */
	public function ajax_quarantine_restore(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$sizes   = is_array( $entries[ $entry_id ]['sizes'] ?? null ) ? $entries[ $entry_id ]['sizes'] : array();

		$result = TSOSK_Uploads_Scanner::restore_sizes_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine-restore',
			sprintf(
				/* translators: 1: comma-separated size names, 2: number of files restored */
				__( 'Restored quarantined files for image size(s) %1$s: %2$d files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes ),
				(int) $result['restored']
			)
		);

		delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );

		$message = $result['remaining'] > 0
			? sprintf(
				/* translators: 1: number of files restored, 2: number of files still quarantined */
				__( 'Restored %1$d file(s). %2$d file(s) could not be restored (their original spot already has a file) and stay in quarantine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $result['restored'],
				(int) $result['remaining']
			)
			: sprintf(
				/* translators: %d: number of files restored */
				__( 'Restored %d file(s) to their original location.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $result['restored']
			);

		wp_send_json_success(
			array(
				'message'         => $message,
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: permanently delete one quarantined image-size batch now, before its 30-day window ends.
	 */
	public function ajax_quarantine_purge(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$sizes   = is_array( $entries[ $entry_id ]['sizes'] ?? null ) ? $entries[ $entry_id ]['sizes'] : array();

		$result = TSOSK_Uploads_Scanner::purge_sizes_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine-purge',
			sprintf(
				/* translators: %s: comma-separated size names */
				__( 'Permanently deleted quarantined files for image size(s): %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes )
			)
		);

		wp_send_json_success(
			array(
				'message'         => __( 'Quarantined files permanently deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * Render module UI.
	 */
	public function render(): void {
		// Opportunistic sweep so files past their 30-day window are purged even if the
		// cron event was missed (e.g. on a low-traffic site) — cheap when nothing is due.
		TSOSK_Uploads_Scanner::purge_expired_sizes_quarantine();

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

		<h3><?php esc_html_e( 'Image sizes quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
		<div id="tsosk-image-sizes-quarantine-results">
			<?php echo $this->render_sizes_quarantine_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
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
		$this->render_sizes_controls( $registered, array(), array(), $disabled, $nonce );
	}

	/**
	 * @param array<string, mixed> $audit    Audit data.
	 * @param string[]             $disabled Disabled size names.
	 * @return string
	 */
	private function render_audit_html( array $audit, array $disabled ): string {
		$nonce      = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$registered = is_array( $audit['registered'] ?? null ) ? $audit['registered'] : array();
		$size_meta  = is_array( $audit['size_meta'] ?? null ) ? $audit['size_meta'] : array();
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
		$this->render_sizes_controls( $registered, $size_meta, $by_size, $disabled, $nonce );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, array> $registered Registered subsizes.
	 * @param array<string, array> $size_meta  Width/height/crop/registered flag per size name,
	 *                                          including sizes no longer registered by any
	 *                                          active theme or plugin (legacy sizes) that were
	 *                                          still found in attachment metadata.
	 * @param array<string, array> $by_size    Audit stats per size.
	 * @param string[]             $disabled Disabled names.
	 * @param string               $nonce    Nonce.
	 */
	private function render_sizes_controls( array $registered, array $size_meta, array $by_size, array $disabled, string $nonce ): void {
		$core_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large' );
		$legacy_names = array_values( array_diff( array_keys( $by_size ), array_keys( $registered ) ) );
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
					<?php foreach ( $legacy_names as $name ) : ?>
						<?php
						$stats  = $by_size[ $name ] ?? array( 'files' => 0, 'bytes' => 0 );
						$meta   = $size_meta[ $name ] ?? array(
							'width'  => 0,
							'height' => 0,
							'crop'   => null,
						);
						$width  = (int) ( $meta['width'] ?? 0 );
						$height = (int) ( $meta['height'] ?? 0 );
						$crop   = $meta['crop'] ?? null;
						?>
						<tr>
							<td>
								<input type="checkbox" disabled>
							</td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'not registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							</td>
							<td>
								<?php
								if ( null === $crop ) {
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height */
											__( '%1$d × %2$d — crop: unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$width,
											$height
										)
									);
								} else {
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height, 3: crop yes/no */
											__( '%1$d × %2$d — crop: %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$width,
											$height,
											$crop ? __( 'yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'no', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
										)
									);
								}
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $stats['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td>
								<?php esc_html_e( 'Not registered by any active theme or plugin — no new files are generated for it. Existing files can be moved to quarantine below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! empty( $legacy_names ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Rows marked "not registered" come from an old theme or a deactivated plugin. WordPress still stores their files even though nothing generates them any more.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php endif; ?>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-image-sizes-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save image size settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-image-sizes-save-msg"></span>
			</p>
		</div>
		<?php
		$this->render_quarantine_selector_section( $registered, $size_meta, $legacy_names, $by_size, $disabled, $core_sizes, $nonce );
	}

	/**
	 * Section for moving existing files of sizes that no longer generate new uploads —
	 * either a currently-registered size the admin has turned off above, or a legacy size
	 * that is not registered by any active theme or plugin (so it is inherently "off") —
	 * into quarantine.
	 *
	 * @param array<string, array> $registered   Registered subsizes.
	 * @param array<string, array> $size_meta    Width/height/crop per size name, including
	 *                                            legacy (unregistered) sizes.
	 * @param string[]              $legacy_names Size names found in metadata that are not
	 *                                             currently registered.
	 * @param array<string, array> $by_size      Audit stats per size.
	 * @param string[]              $disabled     Disabled size names (registered sizes only).
	 * @param string[]              $core_sizes   Core size names, never eligible.
	 * @param string                $nonce        Nonce.
	 */
	private function render_quarantine_selector_section( array $registered, array $size_meta, array $legacy_names, array $by_size, array $disabled, array $core_sizes, string $nonce ): void {
		$eligible = array();
		foreach ( $registered as $name => $size ) {
			if ( in_array( $name, $core_sizes, true ) || ! in_array( $name, $disabled, true ) ) {
				continue;
			}
			$stats = $by_size[ $name ] ?? array( 'files' => 0, 'bytes' => 0 );
			if ( (int) ( $stats['files'] ?? 0 ) <= 0 ) {
				continue;
			}
			$eligible[ $name ] = array(
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'files'  => (int) ( $stats['files'] ?? 0 ),
				'bytes'  => (int) ( $stats['bytes'] ?? 0 ),
				'legacy' => false,
			);
		}
		foreach ( $legacy_names as $name ) {
			$stats = $by_size[ $name ] ?? array( 'files' => 0, 'bytes' => 0 );
			if ( (int) ( $stats['files'] ?? 0 ) <= 0 ) {
				continue;
			}
			$meta               = $size_meta[ $name ] ?? array(
				'width'  => 0,
				'height' => 0,
			);
			$eligible[ $name ]  = array(
				'width'  => (int) ( $meta['width'] ?? 0 ),
				'height' => (int) ( $meta['height'] ?? 0 ),
				'files'  => (int) ( $stats['files'] ?? 0 ),
				'bytes'  => (int) ( $stats['bytes'] ?? 0 ),
				'legacy' => true,
			);
		}
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Free up space from disabled sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<?php esc_html_e( 'Only sizes already turned off above are listed here, and turning a size off never deletes its files by itself — you choose which of those to move now. Files are moved to quarantine, not deleted: you can restore them or check the site is fine first, then delete them for good from the quarantine list below (or leave them — they are auto-deleted after 30 days).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $eligible ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No disabled size currently has existing files on disk (or the audit has not run yet).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-image-sizes-delete-table">
						<thead>
							<tr>
								<th style="width:40px;"><?php esc_html_e( 'Move', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Dimensions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Disk usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $eligible as $name => $row ) : ?>
							<tr>
								<td>
									<input type="checkbox" class="tsosk-img-audit-delete-checkbox"
									       data-name="<?php echo esc_attr( $name ); ?>">
								</td>
								<td>
									<code><?php echo esc_html( $name ); ?></code>
									<?php if ( ! empty( $row['legacy'] ) ) : ?>
										<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'not registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height */
											__( '%1$d × %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$row['width'],
											$row['height']
										)
									);
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( $row['files'] ) ); ?></td>
								<td><?php echo esc_html( size_format( $row['bytes'], 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p style="margin-top:10px;">
					<button type="button" class="button button-primary" id="tsosk-image-sizes-quarantine"
					        data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Move files for selected sizes to quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-image-sizes-delete-msg"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * List of quarantined image-size batches, with restore / delete-now actions.
	 *
	 * @return string
	 */
	private function render_sizes_quarantine_html(): string {
		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$nonce   = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$summary = TSOSK_Uploads_Scanner::get_sizes_quarantine_summary();

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
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of batches, 2: total size */
					esc_html__( '%1$s batch(es) in quarantine, %2$s still on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( number_format_i18n( (int) $summary['count'] ) ),
					esc_html( size_format( (int) $summary['size'], 2 ) )
				);
				?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-image-sizes-quarantine-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
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
						$entry_sizes     = is_array( $entry['sizes'] ?? null ) ? $entry['sizes'] : array();
						?>
						<tr id="tsosk-img-quarantine-<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>">
							<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'Sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( implode( ', ', $entry_sizes ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $entry['files'] ?? 0 ) ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( size_format( (int) ( $entry['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Quarantined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d H:i', $tsosk_q_at ) : '—' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Auto-deletes on', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d', $tsosk_q_expires ) : '—' ); ?></td>
							<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<button type="button" class="button button-small tsosk-img-quarantine-restore"
								        data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Restore', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<button type="button" class="button button-small button-link-delete tsosk-img-quarantine-purge"
								        data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>"
								        data-label="<?php echo esc_attr( implode( ', ', $entry_sizes ) ); ?>">
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
