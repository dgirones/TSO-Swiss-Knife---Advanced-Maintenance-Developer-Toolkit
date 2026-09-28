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

	/** User meta: bisection session state (array) or absent when idle. */
	private const META_BISECT = 'tsosk_sandbox_bisect';

	/** User meta: one-shot flash result shown after bisection finishes. */
	private const META_BISECT_RESULT = 'tsosk_sandbox_bisect_result';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_sandbox_apply', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_tsosk_sandbox_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_tsosk_sandbox_bisect_start', array( $this, 'ajax_bisect_start' ) );
		add_action( 'wp_ajax_tsosk_sandbox_bisect_vote', array( $this, 'ajax_bisect_vote' ) );
		add_action( 'wp_ajax_tsosk_sandbox_bisect_cancel', array( $this, 'ajax_bisect_cancel' ) );
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

	// ── Bisection ─────────────────────────────────────────────────────────────

	/**
	 * Read the current bisection state for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	private function get_bisect_state( int $user_id ): array {
		$state = get_user_meta( $user_id, self::META_BISECT, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the bisection state for a user.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $state   State to store.
	 */
	private function save_bisect_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::META_BISECT, $state );
	}

	/**
	 * Clear the bisection state for a user.
	 *
	 * @param int $user_id User ID.
	 */
	private function clear_bisect_state( int $user_id ): void {
		delete_user_meta( $user_id, self::META_BISECT );
	}

	/**
	 * Split a candidate plugin list in half for the next bisection test.
	 *
	 * @param array<int,string> $candidates Plugin basenames.
	 * @return array{0:array<int,string>,1:array<int,string>} [testing half, remaining half].
	 */
	private function bisect_split( array $candidates ): array {
		$candidates = array_values( $candidates );
		$half       = (int) ceil( count( $candidates ) / 2 );
		return array( array_slice( $candidates, 0, $half ), array_slice( $candidates, $half ) );
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

		// A manual apply supersedes any bisection in progress.
		$this->clear_bisect_state( get_current_user_id() );

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

		$this->clear_bisect_state( get_current_user_id() );
		TSOSK_Sandbox_Mu::end_session( get_current_user_id() );

		TSOSK_Activity_Log::log( 'sandbox', 'disable', __( 'Plugin sandbox exited.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Sandbox exited. Reloading restores the normal plugin set.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: start an automatic bisection of the normally active plugins.
	 */
	public function ajax_bisect_start(): void {
		check_ajax_referer( 'tsosk_sandbox_nonce', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$normal     = (array) get_option( 'active_plugins', array() );
		$candidates = array_values( array_filter( $normal, static fn( $p ) => TSOSK_BASENAME !== $p ) );

		if ( count( $candidates ) < 2 ) {
			wp_send_json_error( __( 'Bisection needs at least 2 other normally active plugins to narrow down.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		list( $testing, $remaining ) = $this->bisect_split( $candidates );

		$result = TSOSK_Sandbox_Mu::start_session( get_current_user_id(), $this->normalize_plugin_list( $testing ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_BISECT_RESULT );
		$this->save_bisect_state(
			$user_id,
			array(
				'candidates' => $candidates,
				'testing'    => $testing,
				'remaining'  => $remaining,
				'step'       => 1,
				'started'    => time(),
			)
		);

		TSOSK_Activity_Log::log(
			'sandbox',
			'bisect-start',
			sprintf(
				/* translators: 1: total candidate plugins, 2: plugins in the first test */
				__( 'Plugin bisection started (%1$d candidates, testing %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				count( $candidates ),
				count( $testing )
			)
		);

		wp_send_json_success(
			__( 'Bisection started. Reloading applies the first test set — go check the site, then come back and answer.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * AJAX: record whether the problem reproduced with the current test set,
	 * and either narrow the candidates further or report the likely culprit.
	 */
	public function ajax_bisect_vote(): void {
		check_ajax_referer( 'tsosk_sandbox_nonce', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = get_current_user_id();
		$state   = $this->get_bisect_state( $user_id );
		if ( empty( $state ) || empty( $state['testing'] ) ) {
			wp_send_json_error( __( 'No bisection is currently in progress.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$reproduced = isset( $_POST['reproduced'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['reproduced'] ) );

		$testing   = (array) $state['testing'];
		$remaining = (array) $state['remaining'];
		$next_pool = array_values( $reproduced ? $testing : $remaining );

		if ( count( $next_pool ) <= 1 ) {
			$culprit = $next_pool[0] ?? '';
			$this->clear_bisect_state( $user_id );

			if ( '' === $culprit ) {
				update_user_meta(
					$user_id,
					self::META_BISECT_RESULT,
					array(
						'culprit' => '',
						'message' => __( 'No plugin could be isolated — the problem did not reproduce with either half. It may not be caused by a single plugin, or may be intermittent. Try the manual selector below instead.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					)
				);

				TSOSK_Activity_Log::log( 'sandbox', 'bisect-result', __( 'Plugin bisection finished without isolating a plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

				wp_send_json_success( __( 'Bisection finished: no single plugin could be isolated. Reloading returns to the manual selector.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}

			$all  = get_plugins();
			$name = isset( $all[ $culprit ]['Name'] ) ? $all[ $culprit ]['Name'] : $culprit;

			// Keep only the isolated plugin active so the admin can confirm it.
			TSOSK_Sandbox_Mu::start_session( $user_id, $this->normalize_plugin_list( array( $culprit ) ) );

			update_user_meta(
				$user_id,
				self::META_BISECT_RESULT,
				array(
					'culprit' => $culprit,
					/* translators: %s: plugin name */
					'message' => sprintf( __( 'Likely culprit: %s. Sandbox now runs with only that plugin active (plus this toolkit) so you can confirm.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $name ),
				)
			);

			TSOSK_Activity_Log::log(
				'sandbox',
				'bisect-result',
				sprintf(
					/* translators: %s: plugin name */
					__( 'Plugin bisection isolated the likely conflicting plugin: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$name
				)
			);

			wp_send_json_success(
				sprintf(
					/* translators: %s: plugin name */
					__( 'Likely culprit: %s. Reloading applies a sandbox with only that plugin active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$name
				)
			);
		}

		list( $testing_next, $remaining_next ) = $this->bisect_split( $next_pool );

		$result = TSOSK_Sandbox_Mu::start_session( $user_id, $this->normalize_plugin_list( $testing_next ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$step = isset( $state['step'] ) ? (int) $state['step'] + 1 : 2;
		$this->save_bisect_state(
			$user_id,
			array(
				'candidates' => $next_pool,
				'testing'    => $testing_next,
				'remaining'  => $remaining_next,
				'step'       => $step,
				'started'    => $state['started'] ?? time(),
			)
		);

		TSOSK_Activity_Log::log(
			'sandbox',
			'bisect-step',
			sprintf(
				/* translators: 1: step number, 2: remaining candidates, 3: plugins in this test */
				__( 'Plugin bisection step %1$d (%2$d candidates left, testing %3$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$step,
				count( $next_pool ),
				count( $testing_next )
			)
		);

		wp_send_json_success(
			__( 'Next test applied. Reload, check the site again, then answer.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * AJAX: cancel an in-progress bisection and exit the sandbox entirely.
	 */
	public function ajax_bisect_cancel(): void {
		check_ajax_referer( 'tsosk_sandbox_nonce', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$user_id = get_current_user_id();
		$this->clear_bisect_state( $user_id );
		delete_user_meta( $user_id, self::META_BISECT_RESULT );
		TSOSK_Sandbox_Mu::end_session( $user_id );

		TSOSK_Activity_Log::log( 'sandbox', 'bisect-cancel', __( 'Plugin bisection cancelled; sandbox exited.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Bisection cancelled and sandbox exited.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
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

		$user_id      = get_current_user_id();
		$bisect_state = $this->get_bisect_state( $user_id );
		$in_bisect    = ! empty( $bisect_state );

		$bisect_result = get_user_meta( $user_id, self::META_BISECT_RESULT, true );
		if ( is_array( $bisect_result ) && ! empty( $bisect_result ) ) {
			delete_user_meta( $user_id, self::META_BISECT_RESULT );
		} else {
			$bisect_result = array();
		}
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

		<?php if ( ! empty( $bisect_result ) && ! empty( $bisect_result['message'] ) ) : ?>
		<div class="tsosk-notice <?php echo esc_attr( ! empty( $bisect_result['culprit'] ) ? 'tsosk-notice-warn' : 'tsosk-notice-info' ); ?>">
			<?php echo esc_html( $bisect_result['message'] ); ?>
		</div>
		<?php endif; ?>

		<?php if ( $in_bisect ) : ?>
		<div class="tsosk-card tsosk-sandbox-bisect-card">
			<h3><?php esc_html_e( 'Plugin bisection in progress', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: 1: step number, 2: number of plugins active in this test, 3: total remaining candidate plugins */
					esc_html__( 'Step %1$d — testing %2$d of %3$d remaining candidate plugin(s) (plus this toolkit). Everything else is switched off for your account only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) ( $bisect_state['step'] ?? 1 ),
					count( (array) ( $bisect_state['testing'] ?? array() ) ),
					count( (array) ( $bisect_state['candidates'] ?? array() ) )
				);
				?>
			</p>
			<details class="tsosk-bisect-details">
				<summary><?php esc_html_e( 'Plugins active in this test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
				<ul class="tsosk-bisect-testing-list">
					<?php foreach ( (array) ( $bisect_state['testing'] ?? array() ) as $file ) : ?>
						<li><?php echo esc_html( isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
			<p><strong><?php esc_html_e( 'Go check the site now. Does the problem still happen with only those plugins active?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></p>
			<div class="tsosk-bisect-actions">
				<button class="button button-primary" id="tsosk-bisect-yes" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Yes, it still happens', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button button-secondary" id="tsosk-bisect-no" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( "No, it's gone", 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button" id="tsosk-bisect-cancel" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Cancel bisection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-bisect-msg"></span>
			</div>
		</div>
		<?php else : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Automatic bisection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Binary-search your normally active plugins to narrow down which one is causing a problem: each round halves the candidates based on whether the issue is still there, so you reach the likely culprit in a handful of steps instead of testing one by one. Assumes a single conflicting plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php
			$bisect_pool = array_values( array_filter( $normal, static fn( $p ) => TSOSK_BASENAME !== $p ) );
			if ( count( $bisect_pool ) < 2 ) :
				?>
				<p class="description"><?php esc_html_e( 'Needs at least 2 other normally active plugins to bisect.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<button class="button button-secondary" id="tsosk-bisect-start" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Start bisection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-bisect-start-msg"></span>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! $in_bisect ) : ?>
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
		<?php endif; ?>

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
