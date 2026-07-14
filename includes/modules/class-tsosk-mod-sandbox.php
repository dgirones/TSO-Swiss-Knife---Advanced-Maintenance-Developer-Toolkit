<?php
/**
 * TSO Swiss Knife – Module: Plugin Conflict Sandbox.
 *
 * Uses a must-use loader (mu-plugin/tsosk-sandbox-loader.php) so WordPress only
 * loads the selected plugins on the next request for the current admin.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Sandbox
 */
class TSOSK_Mod_Sandbox {

	/** @var TSOSK_Mod_Sandbox|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_sandbox_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_tsosk_sandbox_reset', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Fallback filters when the MU loader could not be installed.
	 */
	public function init(): void {
		if ( TSOSK_Sandbox_Mu::is_loader_installed() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$override = get_user_meta( get_current_user_id(), TSOSK_Sandbox_Mu::META_PLUGINS, true );
		if ( is_array( $override ) ) {
			add_filter( 'option_active_plugins', array( $this, 'apply_override' ), 5 );
			add_filter( 'site_option_active_sitewide_plugins', array( $this, 'apply_sitewide_override' ), 5 );
		}
	}

	/**
	 * Returns the sandboxed plugin list for the current user (fallback mode).
	 *
	 * @param array $plugins Original active_plugins.
	 * @return array
	 */
	public function apply_override( array $plugins ): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $plugins;
		}
		$override = get_user_meta( get_current_user_id(), TSOSK_Sandbox_Mu::META_PLUGINS, true );
		if ( ! is_array( $override ) || empty( $override ) ) {
			return $plugins;
		}
		return $this->normalize_plugin_list( $override );
	}

	/**
	 * Keep network-activated plugins aligned with the sandbox list on multisite.
	 *
	 * @param array|false $plugins Sitewide plugins map.
	 * @return array|false
	 */
	public function apply_sitewide_override( $plugins ) {
		if ( ! is_multisite() || ! is_array( $plugins ) || ! current_user_can( 'activate_plugins' ) ) {
			return $plugins;
		}
		$override = get_user_meta( get_current_user_id(), TSOSK_Sandbox_Mu::META_PLUGINS, true );
		if ( ! is_array( $override ) ) {
			return $plugins;
		}
		$allowed = array_fill_keys( $this->normalize_plugin_list( $override ), true );
		return array_intersect_key( $plugins, $allowed );
	}

	/**
	 * Ensure this plugin stays active and paths are valid.
	 *
	 * @param array $plugins Plugin basenames.
	 * @return array
	 */
	private function normalize_plugin_list( array $plugins ): array {
		$all     = array_keys( get_plugins() );
		$plugins = array_values( array_filter( $plugins, static fn( $p ) => in_array( $p, $all, true ) ) );
		if ( ! in_array( TSOSK_BASENAME, $plugins, true ) ) {
			$plugins[] = TSOSK_BASENAME;
		}
		return array_values( array_unique( $plugins ) );
	}

	/**
	 * Whether the current user has an active sandbox session.
	 *
	 * @return bool
	 */
	private function user_has_sandbox(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$plugins = get_user_meta( get_current_user_id(), TSOSK_Sandbox_Mu::META_PLUGINS, true );
		return is_array( $plugins );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * AJAX: apply sandbox plugin set.
	 */
	public function ajax_apply(): void {
		check_ajax_referer( 'tsosk_sandbox_nonce', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$plugins = array();
		if ( isset( $_POST['plugins'] ) && is_array( $_POST['plugins'] ) ) {
			$raw_plugins = wp_unslash( $_POST['plugins'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
			$plugins     = array_map( 'sanitize_text_field', $raw_plugins );
		}

		$plugins = $this->normalize_plugin_list( $plugins );

		if ( count( $plugins ) < 1 ) {
			wp_send_json_error( __( 'Select at least one plugin (this toolkit is always kept active).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = TSOSK_Sandbox_Mu::start_session( get_current_user_id(), $plugins );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'sandbox',
			'enable',
			sprintf(
				/* translators: %d: number of plugins */
				__( 'Plugin sandbox applied (%d plugins).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				count( $plugins )
			)
		);

		wp_send_json_success(
			__( 'Sandbox applied. The page will reload and only your selected plugins will load.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * AJAX: exit sandbox mode.
	 */
	public function ajax_reset(): void {
		check_ajax_referer( 'tsosk_sandbox_nonce', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		TSOSK_Sandbox_Mu::end_session( get_current_user_id() );

		TSOSK_Activity_Log::log( 'sandbox', 'disable', __( 'Plugin sandbox exited.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Sandbox exited. Reloading restores the normal plugin set.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render sandbox UI.
	 */
	public function render(): void {
		$nonce      = wp_create_nonce( 'tsosk_sandbox_nonce' );
		$all        = get_plugins();
		$normal     = (array) get_option( 'active_plugins', array() );
		$in_sandbox = $this->user_has_sandbox();
		$sandboxed  = $in_sandbox
			? get_user_meta( get_current_user_id(), TSOSK_Sandbox_Mu::META_PLUGINS, true )
			: array();
		$active_set = is_array( $sandboxed ) ? $sandboxed : $normal;
		$mu_active  = TSOSK_Sandbox_Mu::is_loader_installed();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Test plugin conflicts with real isolation: a must-use loader makes WordPress load only your selected plugins on the next request. Other visitors are not affected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( $in_sandbox ) : ?>
		<div class="tsosk-notice tsosk-notice-warn" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
			<span>
				<?php esc_html_e( 'Sandbox mode is active for your account.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<?php if ( $mu_active ) : ?>
					<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'MU loader on', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<?php else : ?>
					<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'MU loader missing — limited mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<?php endif; ?>
			</span>
			<button class="button button-secondary" id="tsosk-sandbox-reset" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Exit Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Select Active Plugins for Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div style="margin-bottom:10px;">
				<button type="button" id="tsosk-sb-select-all" class="button button-small">
					<?php esc_html_e( 'Select All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button type="button" id="tsosk-sb-select-active" class="button button-small">
					<?php esc_html_e( 'Select Currently Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button type="button" id="tsosk-sb-select-none" class="button button-small">
					<?php esc_html_e( 'Deselect All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</div>

			<div class="tsosk-plugin-list" id="tsosk-sandbox-list">
				<?php foreach ( $all as $file => $data ) : ?>
				<?php
				$checked   = in_array( $file, $active_set, true );
				$is_normal = in_array( $file, $normal, true );
				?>
				<label class="<?php echo esc_attr( 'tsosk-plugin-row' . ( $is_normal ? ' is-active' : '' ) ); ?>"
				       data-normal="<?php echo esc_attr( $is_normal ? '1' : '0' ); ?>">
					<input type="checkbox" class="tsosk-sandbox-cb"
					       value="<?php echo esc_attr( $file ); ?>"
					       <?php checked( $checked ); ?>>
					<span class="tsosk-plugin-name"><?php echo esc_html( $data['Name'] ); ?></span>
					<span class="tsosk-plugin-ver">v<?php echo esc_html( $data['Version'] ); ?></span>
					<?php if ( $is_normal ) : ?>
					<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Normally Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php endif; ?>
				</label>
				<?php endforeach; ?>
			</div>

			<div style="margin-top:16px;">
				<button class="button button-primary" id="tsosk-sandbox-apply" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Apply Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-sandbox-msg"></span>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'How Sandbox Works', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Select the plugins you want to test.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Click Apply Sandbox — a must-use loader is installed and a secure session cookie is set.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'After reload, WordPress loads only those plugin files for you. Everyone else keeps the normal set.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Click Exit Sandbox when finished. The loader is removed when no sessions remain.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
			</ol>
			<p class="description">
				<strong><?php esc_html_e( 'Note:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'TSO Swiss Knife and other must-use plugins always stay loaded. The server must allow writing to wp-content/mu-plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>
		<?php
	}
}
