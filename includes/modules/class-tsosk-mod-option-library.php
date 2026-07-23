<?php
/**
 * TSO Swiss Knife – Module: Plugin Option Library.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Option_Library
 */
class TSOSK_Mod_Option_Library {

	/** @var TSOSK_Mod_Option_Library|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_ol_preview', array( $this, 'ajax_preview' ) );
	}

	public function ajax_preview(): void {
		check_ajax_referer( 'tsosk_ol_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $name || ! $this->is_listed_option( $name ) ) {
			wp_send_json_error( __( 'Unknown or undocumented option.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$protected = TSOSK_Mod_Options_Editor::is_protected_option_name( $name );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( __( 'Option not found in the database.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$value   = (string) $row['option_value'];
		$preview = $this->format_preview_value( $value );

		wp_send_json_success(
			array(
				'name'      => $name,
				'exists'    => true,
				'autoload'  => (string) $row['autoload'],
				'size'      => strlen( $value ),
				'preview'   => $preview,
				'protected' => $protected,
				'edit_url'  => TSOSK_Mod_Options_Editor::get_admin_url_with_search( $name, $protected, true ),
			)
		);
	}

	/**
	 * Build a safe text preview for AJAX (serialized values shown as JSON).
	 *
	 * @param string $value Raw option_value.
	 * @return string
	 */
	private function format_preview_value( string $value ): string {
		if ( is_serialized( $value ) ) {
			$unpacked = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( false !== $unpacked || 'b:0;' === $value ) {
				$json = wp_json_encode( $unpacked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( is_string( $json ) ) {
					return strlen( $json ) > 4000 ? substr( $json, 0, 4000 ) . '…' : $json;
				}
			}
		}

		if ( strlen( $value ) > 4000 ) {
			return substr( $value, 0, 4000 ) . '…';
		}

		return $value;
	}

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_listed_option( string $name ): bool {
		foreach ( TSOSK_Option_Library::get_registry() as $library ) {
			foreach ( $library['options'] as $option ) {
				if ( $option['name'] === $name ) {
					return true;
				}
			}
		}
		return false;
	}

	public function render(): void {
		$nonce     = wp_create_nonce( 'tsosk_ol_nonce' );
		$libraries = TSOSK_Option_Library::get_active_libraries();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Documented wp_options for WordPress core and popular plugins detected on this site. Use Options Editor to change values; previews here are read-only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card tsosk-ol-preview-panel" id="tsosk-ol-preview-box" style="display:none;">
			<h3><?php esc_html_e( 'Option preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description" id="tsosk-ol-preview-hint">
				<?php esc_html_e( 'Click Preview on any option below to load its value here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p><code id="tsosk-ol-preview-name"></code> — <span id="tsosk-ol-preview-meta"></span></p>
			<pre id="tsosk-ol-preview-value" class="tsosk-ol-preview-value"></pre>
			<p>
				<a id="tsosk-ol-preview-edit" class="button"><?php esc_html_e( 'Edit in Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			</p>
		</div>
		<p class="tsosk-ajax-msg" id="tsosk-ol-preview-msg" style="display:none;margin:0 0 12px;"></p>

		<?php if ( empty( $libraries ) ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'No preset libraries matched the active plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php else : ?>
			<?php foreach ( $libraries as $lib_id => $library ) : ?>
			<div class="tsosk-card tsosk-ol-library" data-library="<?php echo esc_attr( $lib_id ); ?>">
				<h3>
					<span class="dashicons <?php echo esc_attr( $library['icon'] ); ?>"></span>
					<?php echo esc_html( $library['label'] ); ?>
				</h3>
				<?php if ( ! empty( $library['prefix'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: option name prefix */
						esc_html__( 'Common prefix: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'<code>' . esc_html( $library['prefix'] ) . '</code>'
					);
					?>
					<?php if ( class_exists( 'TSOSK_Mod_Options_Editor' ) ) : ?>
					— <a href="<?php echo esc_url( TSOSK_Mod_Options_Editor::get_admin_url_with_search( $library['prefix'] ) ); ?>">
						<?php esc_html_e( 'Browse all matching options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</a>
					<?php endif; ?>
				</p>
				<?php endif; ?>
				<table class="widefat tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Description', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $library['options'] as $option ) : ?>
							<?php
							$exists    = TSOSK_Option_Library::option_exists( $option['name'] );
							$protected = TSOSK_Mod_Options_Editor::is_protected_option_name( $option['name'] );
							$readonly  = ! empty( $option['readonly'] ) || $protected;
							$caution   = ! empty( $option['caution'] ) && ! $protected;
							$edit_url  = TSOSK_Mod_Options_Editor::get_admin_url_with_search( $option['name'], $protected, true );
							?>
						<tr class="tsosk-ol-option-row" data-option-name="<?php echo esc_attr( $option['name'] ); ?>">
							<td><code><?php echo esc_html( $option['name'] ); ?></code></td>
							<td><?php echo esc_html( $option['desc'] ); ?></td>
							<td>
								<?php if ( $exists ) : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'In database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php else : ?>
									<span class="tsosk-badge"><?php esc_html_e( 'Not set', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php if ( $protected ) : ?>
									<span class="tsosk-badge tsosk-badge-warn" style="margin-left:4px;"><?php esc_html_e( 'Protected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php elseif ( $caution ) : ?>
									<span class="tsosk-badge tsosk-badge-warn" style="margin-left:4px;"><?php esc_html_e( 'Caution', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php if ( $readonly ) : ?>
									<span class="tsosk-badge tsosk-badge-info" style="margin-left:4px;"><?php esc_html_e( 'Read-only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $exists ) : ?>
								<button type="button" class="button button-small tsosk-ol-preview"
								        data-name="<?php echo esc_attr( $option['name'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
									<?php esc_html_e( 'Open in Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
}
