<?php
/**
 * TSO Swiss Knife – Module: Heartbeat Controls.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Heartbeat
 */
class TSOSK_Mod_Heartbeat {

	/** @var TSOSK_Mod_Heartbeat|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_heartbeat_save', array( $this, 'ajax_save' ) );
	}

	/**
	 * Apply settings on plugins_loaded so hooks fire early enough.
	 */
	public function init(): void {
		$settings = $this->get_settings();
		$mode     = $settings['mode'];
		$interval = (int) $settings['interval'];

		if ( 'disable_all' === $mode ) {
			// init fires before wp_enqueue_scripts — safe to deregister here.
			add_action( 'init', static function () {
				wp_deregister_script( 'heartbeat' );
			} );
		} elseif ( 'disable_frontend' === $mode ) {
			// Must hook on wp_enqueue_scripts (priority 999) — not heartbeat_settings
			// which fires AFTER scripts are already enqueued.
			add_action( 'wp_enqueue_scripts', static function () {
				wp_deregister_script( 'heartbeat' );
			}, 999 );
		} elseif ( 'disable_post' === $mode ) {
			// Keep heartbeat ONLY on post-edit screens; remove everywhere else.
			add_action( 'wp_enqueue_scripts', static function () {
				wp_deregister_script( 'heartbeat' );
			}, 999 );
			add_action( 'admin_enqueue_scripts', static function () {
				global $pagenow;
				if ( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow ) {
					wp_deregister_script( 'heartbeat' );
				}
			}, 999 );
		}

		if ( $interval > 0 && 'disable_all' !== $mode ) {
			add_filter( 'heartbeat_settings', static function ( $s ) use ( $interval ) {
				$s['interval'] = $interval;
				return $s;
			} );
		}
	}

	/** AJAX: save heartbeat settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_heartbeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$valid_modes = array( 'default', 'disable_frontend', 'disable_post', 'disable_all' );
		$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'default';
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			$mode = 'default';
		}
		$interval = isset( $_POST['interval'] ) ? max( 0, absint( wp_unslash( $_POST['interval'] ) ) ) : 0;
		if ( $interval > 0 && ( $interval < 15 || $interval > 300 ) ) {
			$interval = 0;
		}

		update_option( 'tsosk_heartbeat_settings', array( 'mode' => $mode, 'interval' => $interval ), false );
		TSOSK_Activity_Log::log(
			'heartbeat',
			'save',
			sprintf(
				/* translators: %s: heartbeat mode */
				__( 'Heartbeat settings saved (mode: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$mode
			),
			array( 'mode' => $mode )
		);
		wp_send_json_success( __( 'Heartbeat settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_heartbeat_nonce' );
		$settings = $this->get_settings();
		$mode     = $settings['mode'];
		$interval = (int) $settings['interval'];

		$modes = array(
			'default'          => array(
				'label' => __( 'Default (WordPress standard)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'  => __( 'Heartbeat runs everywhere at the default interval (15–60 s). Auto-save, lock detection and login checks work normally. Recommended for most sites.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge' => 'tsosk-badge-ok',
				'rec'   => __( 'Recommended for production sites.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'disable_frontend' => array(
				'label' => __( 'Disable on frontend only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'  => __( 'Stops the Heartbeat from running on public pages. Admin and editor still work normally. Reduces server load if you have many anonymous visitors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge' => 'tsosk-badge-info',
				'rec'   => __( 'Good option for high-traffic sites that do not need real-time front-end features.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'disable_post'     => array(
				'label' => __( 'Disable everywhere except the post editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'  => __( 'Heartbeat only runs in the post/page editor to preserve auto-save and lock detection. Disabled on all other admin pages and the front end.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge' => 'tsosk-badge-info',
				'rec'   => __( 'Good balance: keeps editor features, removes unnecessary polling elsewhere.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'disable_all'      => array(
				'label' => __( 'Disable completely (⚠ disables auto-save)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'desc'  => __( 'Stops the Heartbeat script everywhere, including the editor. Auto-save, post lock detection and login-expiry warnings stop working. Use only on staging or maintenance environments, or if you have an alternative auto-save solution.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge' => 'tsosk-badge-warn',
				'rec'   => __( 'Not recommended for production. Disables auto-save completely.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
		);
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'WordPress Heartbeat polls the server periodically for auto-save, post lock detection and login expiration. On busy sites this can increase server load. Adjust here without editing any file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Heartbeat Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-heartbeat-options">
				<?php foreach ( $modes as $key => $opt ) : ?>
				<label class="tsosk-heartbeat-option <?php echo $mode === $key ? 'is-selected' : ''; ?>">
					<div class="tsosk-heartbeat-option-header">
						<input type="radio" name="tsosk_heartbeat_mode" value="<?php echo esc_attr( $key ); ?>"
						       <?php checked( $mode, $key ); ?>>
						<strong><?php echo esc_html( $opt['label'] ); ?></strong>
						<span class="tsosk-badge <?php echo esc_attr( $opt['badge'] ); ?>" style="margin-left:8px;">
							<?php echo esc_html( 'tsosk-badge-ok' === $opt['badge'] ? __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : ( 'tsosk-badge-warn' === $opt['badge'] ? __( 'Caution', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) ); ?>
						</span>
					</div>
					<p class="description" style="margin:4px 0 4px 20px;"><?php echo esc_html( $opt['desc'] ); ?></p>
					<p class="description tsosk-rec" style="margin:0 0 0 20px;color:#2271b1;font-style:italic;">
						<?php echo esc_html( $opt['rec'] ); ?>
					</p>
				</label>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Custom Interval (seconds)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Override how often the Heartbeat polls. 0 = use the WordPress default (15 s). Range: 15–300 s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?><br>
				<strong><?php esc_html_e( 'Recommended:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( '60 s on shared hosting or low-traffic sites. 15–30 s only if real-time collaboration is needed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<input type="number" id="tsosk-heartbeat-interval" min="0" max="300" step="5"
			       value="<?php echo esc_attr( (string) $interval ); ?>" style="width:100px;">
			<span class="description">&nbsp;<?php esc_html_e( '0 = WordPress default (15 s)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
		</div>

		<button class="button button-primary" id="tsosk-heartbeat-save"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:4px;">
			<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</button>
		<span class="tsosk-ajax-msg" id="tsosk-heartbeat-msg"></span>
		<?php
	}

	/**
	 * Get saved settings with defaults.
	 *
	 * @return array{mode:string,interval:int}
	 */
	private function get_settings(): array {
		$s = get_option( 'tsosk_heartbeat_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array(
			'mode'     => in_array( $s['mode'] ?? '', array( 'default', 'disable_frontend', 'disable_post', 'disable_all' ), true ) ? $s['mode'] : 'default',
			'interval' => max( 0, absint( $s['interval'] ?? 0 ) ),
		);
	}
}
