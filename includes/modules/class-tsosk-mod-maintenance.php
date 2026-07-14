<?php
/**
 * TSO Swiss Knife – Module: Maintenance Mode.
 *
 * Creates/removes the WordPress .maintenance file to show a custom
 * maintenance page to all non-admin visitors.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Maintenance {

	private static $instance = null;

	const OPTION = 'tsosk_maintenance';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_maintenance_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_tsosk_maintenance_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_maintenance_preview', array( $this, 'ajax_preview' ) );
	}
	/**
	 * Called early: applies maintenance mode for front-end requests.
	 */
	public function init(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'show_maintenance_page' ), 1 );
	}

	/**
	 * Show maintenance page to non-admins on the frontend.
	 */
	public function show_maintenance_page(): void {
		if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			return;
		}

		$settings = $this->get_settings();

		// Check whitelisted IPs.
		$client_ip = $this->get_client_ip();
		if ( $client_ip && ! empty( $settings['whitelist_ips'] ) ) {
			$whitelist = array_filter( array_map( 'trim', explode( "\n", $settings['whitelist_ips'] ) ) );
			if ( in_array( $client_ip, $whitelist, true ) ) {
				return;
			}
		}

		$message = $settings['message'] ?: $this->get_default_message();

		$this->render_maintenance_html( $message, false, (int) $settings['logo_id'], $settings['page_title'] ?? '' );
	}

	/**
	 * Output the maintenance page HTML and exit.
	 *
	 * @param string $message    Visitor message (empty uses default).
	 * @param bool   $is_preview True when previewing without enabling maintenance.
	 * @param int    $logo_id    Attachment ID for custom logo (0 = default icon).
	 * @param string $page_title Optional heading override (empty = site name).
	 */
	private function render_maintenance_html( string $message, bool $is_preview = false, int $logo_id = 0, string $page_title = '' ): void {
		if ( '' === trim( $message ) ) {
			$message = $this->get_default_message();
		}

		$logo_url = $this->get_logo_url( $logo_id );
		$heading  = '' !== trim( $page_title ) ? $page_title : get_bloginfo( 'name' );
		$body_cls = 'tsosk-maint' . ( $is_preview ? ' tsosk-maint-preview' : '' );
		$css      = TSOSK_Support::read_asset_css( 'assets/css/tsosk-maintenance.css' );

		http_response_code( 503 );
		header( 'Retry-After: 3600' );
		header( 'Content-Type: text/html; charset=utf-8' );
		nocache_headers();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> – <?php esc_html_e( 'Maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></title>
<style id="tsosk-maint-style">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static plugin CSS asset.
echo $css;
?>
</style>
</head>
<body class="<?php echo esc_attr( $body_cls ); ?>">
<?php if ( $is_preview ) : ?>
<div class="tsosk-maint-preview-bar"><?php esc_html_e( 'Preview only — maintenance mode is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></div>
<?php endif; ?>
<div class="tsosk-maint-box">
	<?php if ( $logo_url ) : ?>
		<img class="tsosk-maint-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
	<?php else : ?>
		<div class="tsosk-maint-icon" aria-hidden="true">🔧</div>
	<?php endif; ?>
	<h1><?php echo esc_html( $heading ); ?></h1>
	<p><?php echo wp_kses_post( nl2br( esc_html( $message ) ) ); ?></p>
</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Default visitor message.
	 */
	private function get_default_message(): string {
		return __( 'We are performing scheduled maintenance. We will be back online shortly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @return array{enabled:bool,message:string,page_title:string,whitelist_ips:string,logo_id:int}
	 */
	private function get_settings(): array {
		$defaults = array(
			'enabled'       => false,
			'message'       => '',
			'page_title'    => '',
			'whitelist_ips' => '',
			'logo_id'       => 0,
		);
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
		$settings['logo_id'] = absint( $settings['logo_id'] );

		return $settings;
	}

	/**
	 * Resolve a valid logo image URL from an attachment ID.
	 *
	 * @param int $logo_id Attachment post ID.
	 */
	private function get_logo_url( int $logo_id ): string {
		if ( $logo_id <= 0 || ! wp_attachment_is_image( $logo_id ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $logo_id, 'large' );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
		}
		return $url ? $url : '';
	}

	/**
	 * Sanitize logo attachment ID from request.
	 *
	 * @param int $logo_id Raw attachment ID.
	 */
	private function sanitize_logo_id( int $logo_id ): int {
		$logo_id = absint( $logo_id );
		if ( $logo_id <= 0 ) {
			return 0;
		}
		if ( ! wp_attachment_is_image( $logo_id ) ) {
			return 0;
		}
		return $logo_id;
	}

	/**
	 * Returns the visitor's IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		// Use REMOTE_ADDR only (most reliable; proxy headers can be spoofed).
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	public function ajax_toggle(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$settings            = $this->get_settings();
		$settings['enabled'] = ! $settings['enabled'];
		update_option( self::OPTION, $settings, false );

		TSOSK_Activity_Log::log(
			'maintenance',
			$settings['enabled'] ? 'enable' : 'disable',
			$settings['enabled']
				? __( 'Maintenance mode enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Maintenance mode disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success(
			array(
				'enabled' => $settings['enabled'],
				'message' => $settings['enabled']
					? __( 'Maintenance mode enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Maintenance mode disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			)
		);
	}

	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$settings                  = $this->get_settings();
		$settings['message']       = isset( $_POST['message'] )
			? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$settings['page_title']    = isset( $_POST['page_title'] )
			? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '';
		$settings['whitelist_ips'] = isset( $_POST['whitelist_ips'] )
			? sanitize_textarea_field( wp_unslash( $_POST['whitelist_ips'] ) ) : '';
		$settings['logo_id']       = $this->sanitize_logo_id(
			isset( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0
		);

		update_option( self::OPTION, $settings, false );

		TSOSK_Activity_Log::log(
			'maintenance',
			'save',
			__( 'Maintenance mode settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success( __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: preview maintenance page in a new tab (does not enable maintenance).
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), '', array( 'response' => 403 ) );
		}

		$message = isset( $_POST['message'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) )
			: '';
		$logo_id = $this->sanitize_logo_id(
			isset( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0
		);
		$page_title = isset( $_POST['page_title'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['page_title'] ) )
			: '';

		$this->render_maintenance_html( $message, true, $logo_id, $page_title );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_maintenance_nonce' );
		$settings = $this->get_settings();
		$enabled  = (bool) $settings['enabled'];
		$logo_id  = (int) $settings['logo_id'];
		$logo_url = $this->get_logo_url( $logo_id );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Put the site in maintenance mode. Logged-in administrators always see the site normally. All other visitors get a 503 page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Maintenance Mode Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div class="tsosk-maintenance-toggle" style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
				<span id="tsosk-maintenance-status" class="tsosk-badge <?php echo $enabled ? 'tsosk-badge-warn' : 'tsosk-badge-ok'; ?>">
					<?php echo $enabled ? esc_html__( '⚠ MAINTENANCE MODE ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
				<button class="button button-<?php echo $enabled ? 'primary' : 'secondary'; ?>" id="tsosk-maintenance-toggle"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php echo $enabled ? esc_html__( 'Disable Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Enable Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-maintenance-toggle-msg"></span>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Maintenance Page Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div class="tsosk-field-row">
				<label><strong><?php esc_html_e( 'Logo (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<div class="tsosk-maint-logo-picker">
					<input type="hidden" id="tsosk-m-logo-id" value="<?php echo esc_attr( (string) $logo_id ); ?>">
					<div id="tsosk-m-logo-preview" class="tsosk-maint-logo-preview<?php echo $logo_url ? ' has-logo' : ''; ?>">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
						<?php endif; ?>
					</div>
					<p class="tsosk-maint-logo-actions">
						<button type="button" class="button" id="tsosk-m-logo-select">
							<?php esc_html_e( 'Select logo', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
						<button type="button" class="button" id="tsosk-m-logo-remove"
						        <?php disabled( ! $logo_id ); ?>>
							<?php esc_html_e( 'Remove logo', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
					</p>
				</div>
				<p class="description">
					<?php esc_html_e( 'Shown above the site name on the maintenance page. Leave empty to use the default wrench icon.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Page heading', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-m-page-title" class="regular-text" style="width:100%;max-width:480px;"
				       value="<?php echo esc_attr( $settings['page_title'] ?? '' ); ?>"
				       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<p class="description">
					<?php esc_html_e( 'Large title shown on the maintenance page (e.g. your brand name). Leave empty to use the site name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Message to Visitors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<textarea name="tsosk_m_message" id="tsosk-m-message" rows="4" style="width:100%;"><?php
					echo esc_textarea( $settings['message'] );
				?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: %s: default maintenance message */
						esc_html__( 'Leave empty for the default: “%s”', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( $this->get_default_message() )
					);
					?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Whitelisted IP Addresses (one per line)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<textarea name="tsosk_m_ips" id="tsosk-m-ips" rows="4" style="width:100%;font-family:monospace;"><?php
					echo esc_textarea( $settings['whitelist_ips'] );
				?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: %s: current IP address */
						esc_html__( 'Your current IP: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'<code>' . esc_html( $this->get_client_ip() ) . '</code>'
					);
					?>
				</p>
			</div>

			<button class="button button-primary" id="tsosk-maintenance-save"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:12px;">
				<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button type="button" class="button" id="tsosk-maintenance-preview"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:12px;">
				<?php esc_html_e( 'Preview maintenance page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Preview opens in a new tab using the settings above (even if you have not saved yet). Maintenance mode stays off.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<span class="tsosk-ajax-msg" id="tsosk-maintenance-save-msg"></span>
		</div>
		<?php
	}
}
