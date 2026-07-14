<?php
/**
 * TSO Swiss Knife – Module: Recovery Mode.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Recovery {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_recovery_resume', array( $this, 'ajax_resume' ) );
	}

	/**
	 * AJAX: remove a paused plugin/theme from WordPress recovery storage.
	 */
	public function ajax_resume(): void {
		check_ajax_referer( 'tsosk_recovery_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$extension = isset( $_POST['extension'] ) ? sanitize_text_field( wp_unslash( $_POST['extension'] ) ) : '';

		if ( '' === $extension || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			wp_send_json_error( __( 'Invalid recovery item.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$storage = 'plugin' === $type && function_exists( 'wp_paused_plugins' ) ? wp_paused_plugins() : null;
		$storage = 'theme' === $type && function_exists( 'wp_paused_themes' ) ? wp_paused_themes() : $storage;

		if ( ! is_object( $storage ) || ! method_exists( $storage, 'delete' ) ) {
			wp_send_json_error( __( 'Recovery storage is not available on this WordPress version.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$storage->delete( $extension );
		wp_send_json_success( __( 'The paused extension entry was cleared. Reload and test carefully.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		$nonce         = wp_create_nonce( 'tsosk_recovery_nonce' );
		$recovery_url  = function_exists( 'wp_generate_recovery_mode_url' )
			? wp_generate_recovery_mode_url()
			: false;
		$is_active     = function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode();
		$fatal_handler = function_exists( 'wp_get_fatal_error_handler' ) ? wp_get_fatal_error_handler() : null;
		$handler_label = is_object( $fatal_handler ) ? get_class( $fatal_handler ) : __( 'Not available', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'WordPress Recovery Mode allows an admin to log in and deactivate plugins or themes that cause a fatal error.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'What you can do here', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Recovery Mode is a WordPress safety feature. It is not a permanent on/off switch: WordPress enters it when a fatal error is detected, or when you open a valid recovery URL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Generate and open a one-time recovery URL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'See plugins or themes that WordPress has paused after a fatal error.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Clear a paused item after you have fixed the error, so WordPress can try loading it again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
			</ul>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recovery Mode Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'Currently Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><?php echo $is_active
						? '<span class="tsosk-badge tsosk-badge-warn">' . esc_html__( 'YES – Recovery Mode is active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>'
						: '<span class="tsosk-badge tsosk-badge-ok">'  . esc_html__( 'No', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Recovery Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Fatal Error Handler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $handler_label ); ?></code></td>
				</tr>
			</table>
		</div>

		<?php if ( $recovery_url && ! is_wp_error( $recovery_url ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recovery Mode URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Use this URL to enter recovery mode. It is single-use and expires after 1 hour.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<input type="text" class="large-text" readonly
			       value="<?php echo esc_url( $recovery_url ); ?>"
			       onclick="this.select();">
			<p><a href="<?php echo esc_url( $recovery_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Enter Recovery Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a></p>
		</div>
		<?php elseif ( is_wp_error( $recovery_url ) ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php echo esc_html( $recovery_url->get_error_message() ); ?>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Error Protection Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php
			$paused_plugins = function_exists( 'wp_paused_plugins' ) ? wp_paused_plugins()->get_all() : array();
			$paused_themes  = function_exists( 'wp_paused_themes' ) ? wp_paused_themes()->get_all() : array();
			if ( empty( $paused_plugins ) && empty( $paused_themes ) ) {
				echo '<p>' . esc_html__( 'No plugins or themes have been auto-paused by WordPress.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
			} else {
				if ( ! empty( $paused_plugins ) ) {
					echo '<h4>' . esc_html__( 'Paused Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</h4><ul class="tsosk-recovery-list">';
					foreach ( $paused_plugins as $plugin => $data ) {
						echo '<li><strong>' . esc_html( $plugin ) . '</strong>: '
						     . esc_html( $data['message'] ?? __( 'Unknown error', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) )
						     . ' <button class="button button-small tsosk-recovery-resume" data-type="plugin" data-extension="' . esc_attr( $plugin ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
						     . esc_html__( 'Clear pause', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						     . '</button></li>';
					}
					echo '</ul>';
				}
				if ( ! empty( $paused_themes ) ) {
					echo '<h4>' . esc_html__( 'Paused Themes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</h4><ul class="tsosk-recovery-list">';
					foreach ( $paused_themes as $theme => $data ) {
						echo '<li><strong>' . esc_html( $theme ) . '</strong>: '
						     . esc_html( $data['message'] ?? __( 'Unknown error', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) )
						     . ' <button class="button button-small tsosk-recovery-resume" data-type="theme" data-extension="' . esc_attr( $theme ) . '" data-nonce="' . esc_attr( $nonce ) . '">'
						     . esc_html__( 'Clear pause', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						     . '</button></li>';
					}
					echo '</ul>';
				}
			}
			?>
			<span class="tsosk-ajax-msg" id="tsosk-recovery-msg"></span>
		</div>
		<?php
	}
}
