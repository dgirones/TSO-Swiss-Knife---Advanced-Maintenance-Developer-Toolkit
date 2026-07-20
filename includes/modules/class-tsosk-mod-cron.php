<?php
/**
 * TSO Swiss Knife – Module: Cron Manager.
 *
 * Lists all WordPress cron events with plugin source detection,
 * allows manual execution and deletion.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Cron
 */
class TSOSK_Mod_Cron {

	/** @var TSOSK_Mod_Cron|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_cron_run',        array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_tsosk_cron_delete',     array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_tsosk_cron_reschedule', array( $this, 'ajax_reschedule' ) );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/** AJAX: manually run a cron event. */
	public function ajax_run(): void {
		check_ajax_referer( 'tsosk_cron_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$hook      = isset( $_POST['hook'] )      ? sanitize_text_field( wp_unslash( $_POST['hook'] ) )      : '';
		$timestamp = isset( $_POST['timestamp'] ) ? absint( wp_unslash( $_POST['timestamp'] ) )               : 0;
		$sig       = isset( $_POST['sig'] )       ? sanitize_text_field( wp_unslash( $_POST['sig'] ) )       : '';

		if ( ! $hook || ! $timestamp ) {
			wp_send_json_error( __( 'Invalid parameters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$crons = _get_cron_array();
		if ( ! $crons || ! isset( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
			wp_send_json_error( __( 'Cron event not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$event = $crons[ $timestamp ][ $hook ][ $sig ];
		$args  = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

		do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		// Mirror wp_cron(): reschedule recurring hooks or remove one-off events.
		if ( ! empty( $event['schedule'] ) ) {
			$rescheduled = wp_reschedule_event( $timestamp, $event['schedule'], $hook, $args );
			if ( false === $rescheduled ) {
				wp_send_json_error( __( 'Event ran but could not be rescheduled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		} else {
			wp_unschedule_event( $timestamp, $hook, $args );
		}

		TSOSK_Activity_Log::log(
			'cron',
			'run',
			sprintf(
				/* translators: %s: cron hook name */
				__( 'Cron event executed: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$hook
			),
			array( 'hook' => $hook )
		);

		wp_send_json_success( __( 'Event executed successfully.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: delete a single cron event. */
	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_cron_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$hook      = isset( $_POST['hook'] )      ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$timestamp = isset( $_POST['timestamp'] ) ? absint( wp_unslash( $_POST['timestamp'] ) )          : 0;
		$sig       = isset( $_POST['sig'] )       ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';

		if ( ! $hook || ! $timestamp ) {
			wp_send_json_error( __( 'Invalid parameters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$crons = _get_cron_array();
		if ( ! $crons || ! isset( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
			wp_send_json_error( __( 'Cron event not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$args = $crons[ $timestamp ][ $hook ][ $sig ]['args'] ?? array();
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		wp_unschedule_event( $timestamp, $hook, $args );
		TSOSK_Activity_Log::log(
			'cron',
			'delete',
			sprintf(
				/* translators: %s: cron hook name */
				__( 'Cron event deleted: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$hook
			),
			array( 'hook' => $hook )
		);
		wp_send_json_success( __( 'Event deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: reschedule a cron event (next run time and/or interval). */
	public function ajax_reschedule(): void {
		check_ajax_referer( 'tsosk_cron_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$hook          = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$old_timestamp = isset( $_POST['timestamp'] ) ? absint( wp_unslash( $_POST['timestamp'] ) ) : 0;
		$sig           = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		$new_timestamp = isset( $_POST['new_timestamp'] ) ? absint( wp_unslash( $_POST['new_timestamp'] ) ) : 0;
		$schedule      = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : '';

		if ( ! $hook || ! $old_timestamp || ! $new_timestamp ) {
			wp_send_json_error( __( 'Invalid parameters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$crons = _get_cron_array();
		if ( ! $crons || ! isset( $crons[ $old_timestamp ][ $hook ][ $sig ] ) ) {
			wp_send_json_error( __( 'Cron event not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$event = $crons[ $old_timestamp ][ $hook ][ $sig ];
		$args  = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

		wp_unschedule_event( $old_timestamp, $hook, $args );

		if ( 'single' === $schedule || '' === $schedule ) {
			$scheduled = wp_schedule_single_event( $new_timestamp, $hook, $args );
		} else {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $schedule ] ) ) {
				wp_send_json_error( __( 'Invalid schedule interval.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
			$scheduled = wp_schedule_event( $new_timestamp, $schedule, $hook, $args );
		}

		if ( false === $scheduled ) {
			wp_send_json_error( __( 'Could not reschedule the event.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		TSOSK_Activity_Log::log(
			'cron',
			'reschedule',
			sprintf(
				/* translators: %s: cron hook name */
				__( 'Cron event rescheduled: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$hook
			),
			array( 'hook' => $hook )
		);

		wp_send_json_success( __( 'Event rescheduled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		$nonce     = wp_create_nonce( 'tsosk_cron_nonce' );
		$crons     = _get_cron_array();
		$now       = time();
		$schedules = wp_get_schedules();

		$events = array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events_by_sig ) {
					foreach ( $events_by_sig as $sig => $data ) {
						$events[] = array(
							'timestamp' => (int) $timestamp,
							'hook'      => $hook,
							'sig'       => $sig,
							'schedule'  => $data['schedule'] ?? false,
							'interval'  => $data['interval']  ?? 0,
							'args'      => $data['args']       ?? array(),
							'source'    => $this->detect_hook_source( (string) $hook ),
						);
					}
				}
			}
		}

		usort( $events, static function ( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		$core_hooks = array(
			'wp_scheduled_delete', 'wp_update_themes', 'wp_update_plugins',
			'wp_version_check', 'wp_scheduled_auto_draft_delete',
			'delete_expired_transients', 'recovery_mode_clean_expired_keys',
		);

		$health = $this->get_cron_health( $events );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Lists all scheduled WordPress cron events. Core events are read-only. Custom events can be run manually or deleted. The Source column shows which plugin or WordPress itself registered the hook.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-toolbar">
			<span class="tsosk-badge tsosk-badge-info">
				<?php
				printf(
					/* translators: %d: number of cron events */
					esc_html__( '%d events scheduled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $events )
				);
				?>
			</span>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'WP-Cron Health', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Details', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $health as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['label'] ); ?></td>
							<td><span class="tsosk-badge <?php echo esc_attr( $item['badge'] ); ?>"><?php echo esc_html( $item['status'] ); ?></span></td>
							<td><?php echo esc_html( $item['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( empty( $events ) ) : ?>
			<p><?php esc_html_e( 'No cron events found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		<?php else : ?>
		<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-cron-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hook', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Next Run', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Interval', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event ) : ?>
						<?php
						$is_core    = in_array( $event['hook'], $core_hooks, true );
						$diff       = $event['timestamp'] - $now;
						$overdue    = $diff < 0;
						$time_label = $overdue
							? sprintf( /* translators: %s: human-readable time difference */ __( 'Overdue by %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), human_time_diff( $event['timestamp'], $now ) )
							: sprintf( /* translators: %s: human-readable time difference */ __( 'In %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), human_time_diff( $event['timestamp'], $now ) );

						$interval_label = $event['schedule']
							? ( $schedules[ $event['schedule'] ]['display'] ?? $event['schedule'] )
							: __( 'Single event', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

						$source = $event['source'];
						?>
						<tr class="<?php echo $overdue ? 'tsosk-row-warn' : ''; ?>">
							<td class="tsosk-code"><?php echo esc_html( $event['hook'] ); ?></td>
							<td>
								<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $event['timestamp'] ) ) . ' UTC'; ?>">
									<?php echo esc_html( $time_label ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $interval_label ); ?></td>
							<td>
								<?php if ( 'wordpress' === $source['type'] ) : ?>
									<span class="tsosk-badge tsosk-badge-core">WordPress</span>
								<?php elseif ( 'plugin' === $source['type'] ) : ?>
									<span class="tsosk-badge tsosk-badge-custom" title="<?php echo esc_attr( $source['file'] ); ?>">
										<?php echo esc_html( $source['name'] ); ?>
									</span>
								<?php elseif ( 'theme' === $source['type'] ) : ?>
									<span class="tsosk-badge tsosk-badge-info" title="<?php echo esc_attr( $source['file'] ); ?>">
										<?php
										printf(
											/* translators: %s: theme name */
											esc_html__( 'Theme: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											esc_html( $source['name'] )
										);
										?>
									</span>
								<?php else : ?>
									<span class="tsosk-badge" title="<?php esc_attr_e( 'Hook not registered on this request — often a deactivated plugin or a callback loaded only during cron.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
										<?php esc_html_e( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td class="tsosk-actions">
								<button class="button button-small tsosk-cron-run"
								        data-hook="<?php echo esc_attr( $event['hook'] ); ?>"
								        data-timestamp="<?php echo esc_attr( (string) $event['timestamp'] ); ?>"
								        data-sig="<?php echo esc_attr( $event['sig'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Run', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<?php if ( ! $is_core ) : ?>
								<button class="button button-small tsosk-cron-edit"
								        data-hook="<?php echo esc_attr( $event['hook'] ); ?>"
								        data-timestamp="<?php echo esc_attr( (string) $event['timestamp'] ); ?>"
								        data-sig="<?php echo esc_attr( $event['sig'] ); ?>"
								        data-schedule="<?php echo esc_attr( $event['schedule'] ? (string) $event['schedule'] : 'single' ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<button class="button button-small button-link-delete tsosk-cron-delete"
								        data-hook="<?php echo esc_attr( $event['hook'] ); ?>"
								        data-timestamp="<?php echo esc_attr( (string) $event['timestamp'] ); ?>"
								        data-sig="<?php echo esc_attr( $event['sig'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<?php endif; ?>
								<span class="tsosk-ajax-msg"></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<div id="tsosk-cron-edit-panel" class="tsosk-card" style="display:none;margin-top:16px;">
			<h3><?php esc_html_e( 'Reschedule cron event', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Change when the event runs next. For recurring events, pick the interval. Useful for orphaned events from deactivated plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<input type="hidden" id="tsosk-cron-edit-hook" value="">
			<input type="hidden" id="tsosk-cron-edit-timestamp" value="">
			<input type="hidden" id="tsosk-cron-edit-sig" value="">
			<input type="hidden" id="tsosk-cron-edit-nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<label for="tsosk-cron-edit-when"><strong><?php esc_html_e( 'Next run (local time)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label><br>
				<input type="datetime-local" id="tsosk-cron-edit-when" class="regular-text">
			</p>
			<p>
				<label for="tsosk-cron-edit-schedule"><strong><?php esc_html_e( 'Interval', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label><br>
				<select id="tsosk-cron-edit-schedule">
					<option value="single"><?php esc_html_e( 'Single event (run once)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<?php foreach ( $schedules as $sched_key => $sched_data ) : ?>
					<option value="<?php echo esc_attr( $sched_key ); ?>"><?php echo esc_html( $sched_data['display'] ?? $sched_key ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<button type="button" class="button button-primary" id="tsosk-cron-edit-save"><?php esc_html_e( 'Save schedule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button button-secondary" id="tsosk-cron-edit-cancel"><?php esc_html_e( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-cron-edit-msg"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Detect which plugin registered a hook by name pattern + callback inspection.
	 *
	 * Strategy:
	 *  1. Check known WP core hook names.
	 *  2. Try to match hook name prefix/slug against active plugin slugs.
	 *  3. Inspect $wp_filter callbacks if the hook is registered.
	 *
	 * @param string $hook Hook name.
	 * @return array{type:string, name:string, file:string}
	 */
	private function detect_hook_source( string $hook ): array {
		global $wp_filter;

		$unknown = array( 'type' => 'unknown', 'name' => '', 'file' => '' );

		// 1. Known WP core hooks.
		if ( $this->is_core_hook( $hook ) ) {
			return array( 'type' => 'wordpress', 'name' => 'WordPress', 'file' => '' );
		}

		$hook_lower = strtolower( $hook );

		// 2. Curated hook → plugin/theme map (WooCommerce, Action Scheduler, security plugins, etc.).
		$known = $this->match_known_hook_source( $hook_lower );
		if ( $known ) {
			return $known;
		}

		// 3. Match hook name against active plugin slugs/prefixes (longest prefix wins).
		$matched = $this->match_hook_to_plugin( $hook_lower );
		if ( $matched ) {
			return $matched;
		}

		$matched = $this->match_hook_to_theme( $hook_lower );
		if ( $matched ) {
			return $matched;
		}

		// 4. Scan installed plugin PHP for this hook name (works when hook is not registered yet).
		$scanned = $this->scan_installed_plugins_for_hook( $hook );
		if ( $scanned ) {
			return $scanned;
		}

		// 5. Inspect $wp_filter callbacks (only works if hook is registered on this request).
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $unknown;
		}

		$wp_hook = $wp_filter[ $hook ];
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$file = $this->callback_to_file( $cb['function'] );
				if ( '' === $file ) {
					continue;
				}
				// Check if it's a WP core file.
				$wp_includes = wp_normalize_path( tsosk_get_wp_includes_dir() );
				$wp_admin    = wp_normalize_path( tsosk_get_wp_admin_dir() );
				$norm        = wp_normalize_path( $file );
				if ( str_starts_with( $norm, $wp_includes ) || str_starts_with( $norm, $wp_admin ) ) {
					return array( 'type' => 'wordpress', 'name' => 'WordPress', 'file' => $file );
				}
				// Try to extract plugin name from path.
				$plugins_dir = tsosk_get_plugins_dir();
				if ( str_starts_with( $norm, $plugins_dir ) ) {
					$rel   = substr( $norm, strlen( $plugins_dir ) );
					$parts = explode( '/', $rel );
					$slug  = $parts[0];
					// Get plugin display name.
					if ( ! function_exists( 'get_plugins' ) ) {
						tsosk_require_wp_admin( 'includes/plugin.php' );
					}
					$plugins = get_plugins();
					$name    = $slug;
					foreach ( $plugins as $pfile => $pdata ) {
						if ( 0 === strpos( $pfile, $slug . '/' ) || $pfile === $slug . '.php' ) {
							$name = $pdata['Name'];
							break;
						}
					}
					return array( 'type' => 'plugin', 'name' => $name, 'file' => $rel );
				}
				// mu-plugins.
				$mu_dir = wp_normalize_path( WPMU_PLUGIN_DIR . '/' );
				if ( str_starts_with( $norm, $mu_dir ) ) {
					return array( 'type' => 'plugin', 'name' => 'MU-Plugin', 'file' => substr( $norm, strlen( $mu_dir ) ) );
				}
			}
		}

		if ( $this->is_core_hook( $hook ) ) {
			return array( 'type' => 'wordpress', 'name' => 'WordPress', 'file' => '' );
		}

		return $unknown;
	}

	/**
	 * Try to match a cron hook name to an active plugin by slug/prefix comparison.
	 *
	 * Builds a map of plugin slug variants (slug, underscore, short prefix) and
	 * checks if the hook name starts with or contains any of them.
	 *
	 * @param string $hook Lowercase hook name.
	 * @return array{type:string,name:string,file:string}|null
	 */
	private function match_hook_to_plugin( string $hook ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$plugins = get_plugins();
		$files   = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);

		$best_match = null;
		$best_len   = 0;

		foreach ( array_unique( $files ) as $plugin_file ) {
			$slug   = explode( '/', $plugin_file )[0];
			$slug_u = str_replace( '-', '_', strtolower( $slug ) );
			$slug_d = str_replace( '_', '-', strtolower( $slug ) );

			$prefixes = array_unique(
				array_filter(
					array(
						$slug_u,
						$slug_d,
						sanitize_key( (string) ( $plugins[ $plugin_file ]['TextDomain'] ?? '' ) ),
					)
				)
			);

			foreach ( $prefixes as $prefix ) {
				if ( strlen( $prefix ) < 3 ) {
					continue;
				}
				if ( ! $this->hook_matches_prefix( $hook, $prefix ) ) {
					continue;
				}
				$len = strlen( $prefix );
				if ( $len > $best_len ) {
					$best_len   = $len;
					$best_match = array(
						'type' => 'plugin',
						'name' => isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ]['Name'] : $slug,
						'file' => $plugin_file,
					);
				}
			}
		}

		return $best_match;
	}

	/**
	 * Whether a hook name matches a plugin/theme prefix.
	 *
	 * @param string $hook   Lowercase hook name.
	 * @param string $prefix Lowercase prefix.
	 * @return bool
	 */
	private function hook_matches_prefix( string $hook, string $prefix ): bool {
		if ( $hook === $prefix ) {
			return true;
		}
		if ( 0 === strpos( $hook, $prefix . '_' ) || 0 === strpos( $hook, $prefix . '-' ) ) {
			return true;
		}
		if ( false !== strpos( $hook, '_' . $prefix . '_' ) || false !== strpos( $hook, '-' . $prefix . '-' ) ) {
			return true;
		}
		return false !== strpos( $hook, '_' . $prefix ) || false !== strpos( $hook, $prefix . '_' );
	}

	/**
	 * Match hooks against a curated map of common plugin/theme cron sources.
	 *
	 * @param string $hook Lowercase hook name.
	 * @return array{type:string,name:string,file:string}|null
	 */
	private function match_known_hook_source( string $hook ): ?array {
		$map = array(
			'action_scheduler'       => array( 'folder' => 'woocommerce', 'name' => 'Action Scheduler' ),
			'action_scheduler_run'   => array( 'folder' => 'woocommerce', 'name' => 'Action Scheduler' ),
			'wc_'                    => array( 'folder' => 'woocommerce', 'name' => 'WooCommerce' ),
			'woocommerce_'           => array( 'folder' => 'woocommerce', 'name' => 'WooCommerce' ),
			'itsec_'                 => array( 'folder' => 'better-wp-security', 'name' => 'Solid Security' ),
			'ithemes_'               => array( 'folder' => 'better-wp-security', 'name' => 'Solid Security' ),
			'wordfence_'             => array( 'folder' => 'wordfence', 'name' => 'Wordfence' ),
			'wfls_'                  => array( 'folder' => 'wordfence', 'name' => 'Wordfence' ),
			'wpseo_'                 => array( 'folder' => 'wordpress-seo', 'name' => 'Yoast SEO' ),
			'yoast_'                 => array( 'folder' => 'wordpress-seo', 'name' => 'Yoast SEO' ),
			'rank_math_'             => array( 'folder' => 'seo-by-rank-math', 'name' => 'Rank Math' ),
			'elementor_'             => array( 'folder' => 'elementor', 'name' => 'Elementor' ),
			'jetpack_'               => array( 'folder' => 'jetpack', 'name' => 'Jetpack' ),
			'akismet_'               => array( 'folder' => 'akismet', 'name' => 'Akismet' ),
			'gravityforms_'          => array( 'folder' => 'gravityforms', 'name' => 'Gravity Forms' ),
			'gf_'                    => array( 'folder' => 'gravityforms', 'name' => 'Gravity Forms' ),
			'wpforms_'               => array( 'folder' => 'wpforms-lite', 'name' => 'WPForms' ),
			'kadence_'               => array( 'folder' => 'kadence-blocks', 'name' => 'Kadence' ),
			'kb_'                    => array( 'folder' => 'kadence-blocks', 'name' => 'Kadence' ),
			'stellarwp_'             => array( 'folder' => 'kadence-blocks', 'name' => 'Kadence' ),
			'redirection_'           => array( 'folder' => 'redirection', 'name' => 'Redirection' ),
			'wp_mail_smtp_'          => array( 'folder' => 'wp-mail-smtp', 'name' => 'WP Mail SMTP' ),
			'wpml_'                  => array( 'folder' => 'sitepress-multilingual-cms', 'name' => 'WPML' ),
			'acf_'                   => array( 'folder' => 'advanced-custom-fields', 'name' => 'ACF' ),
			'updraft_'               => array( 'folder' => 'updraftplus', 'name' => 'UpdraftPlus' ),
			'backwpup_'              => array( 'folder' => 'backwpup', 'name' => 'BackWPup' ),
			'sg_'                    => array( 'folder' => 'sg-cachepress', 'name' => 'SiteGround Optimizer' ),
			'litespeed_'             => array( 'folder' => 'litespeed-cache', 'name' => 'LiteSpeed Cache' ),
			'wp_rocket_'             => array( 'folder' => 'wp-rocket', 'name' => 'WP Rocket' ),
			'w3tc_'                  => array( 'folder' => 'w3-total-cache', 'name' => 'W3 Total Cache' ),
			'edd_'                   => array( 'folder' => 'easy-digital-downloads', 'name' => 'Easy Digital Downloads' ),
			'pmpro_'                 => array( 'folder' => 'paid-memberships-pro', 'name' => 'Paid Memberships Pro' ),
			'fluentform_'            => array( 'folder' => 'fluentform', 'name' => 'Fluent Forms' ),
			'fluentcrm_'             => array( 'folder' => 'fluent-crm', 'name' => 'FluentCRM' ),
		);

		$best_key = '';
		foreach ( array_keys( $map ) as $prefix ) {
			if ( ! $this->hook_matches_prefix( $hook, rtrim( $prefix, '_' ) ) ) {
				continue;
			}
			if ( strlen( $prefix ) > strlen( $best_key ) ) {
				$best_key = $prefix;
			}
		}

		if ( '' === $best_key ) {
			return null;
		}

		return $this->resolve_plugin_folder_source( $map[ $best_key ]['folder'], $map[ $best_key ]['name'] );
	}

	/**
	 * Resolve a plugin folder slug to a display source array.
	 *
	 * @param string $folder Plugin directory name.
	 * @param string $label  Fallback display name.
	 * @return array{type:string,name:string,file:string}|null
	 */
	private function resolve_plugin_folder_source( string $folder, string $label ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			if ( 0 === strpos( $file, $folder . '/' ) || dirname( $file ) === $folder ) {
				return array(
					'type' => 'plugin',
					'name' => (string) ( $data['Name'] ?? $label ),
					'file' => $file,
				);
			}
		}
		return array(
			'type' => 'plugin',
			'name' => $label,
			'file' => $folder,
		);
	}

	/**
	 * Scan installed plugins for a cron hook registration string.
	 *
	 * @param string $hook Hook name.
	 * @return array{type:string,name:string,file:string}|null
	 */
	private function scan_installed_plugins_for_hook( string $hook ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}

		static $cache = array();
		if ( isset( $cache[ $hook ] ) ) {
			return $cache[ $hook ];
		}

		$needle = preg_quote( $hook, '/' );
		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			$plugin_file = tsosk_get_plugin_file_path( $file );
			$dir         = '' !== $plugin_file ? dirname( $plugin_file ) : '';
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}
			$paths = array( $plugin_file );
			foreach ( array( 'includes', 'src', 'admin', 'classes' ) as $sub ) {
				foreach ( glob( $dir . '/' . $sub . '/*.php' ) ?: array() as $php ) {
					$paths[] = $php;
				}
			}
			foreach ( array_slice( array_unique( $paths ), 0, 16 ) as $path ) {
				if ( ! is_readable( $path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = (string) file_get_contents( $path, false, null, 0, 131072 );
				if ( preg_match( "/['\"]{$needle}['\"]/", $content ) ) {
					$cache[ $hook ] = array(
						'type' => 'plugin',
						'name' => (string) ( $data['Name'] ?? dirname( $file ) ),
						'file' => $file,
					);
					return $cache[ $hook ];
				}
			}
		}

		$cache[ $hook ] = null;
		return null;
	}

	/**
	 * Try to match a cron hook name to the active theme.
	 *
	 * @param string $hook Lowercase hook name.
	 * @return array{type:string,name:string,file:string}|null
	 */
	private function match_hook_to_theme( string $hook ): ?array {
		$themes = array( wp_get_theme(), wp_get_theme( get_template() ) );
		foreach ( $themes as $theme ) {
			if ( ! $theme->exists() ) {
				continue;
			}
			$slug     = strtolower( $theme->get_stylesheet() );
			$slug_u   = str_replace( '-', '_', $slug );
			$prefixes = array_unique( array_filter( array( $slug, $slug_u ) ) );
			foreach ( $prefixes as $prefix ) {
				if ( strlen( $prefix ) < 3 ) {
					continue;
				}
				if ( $this->hook_matches_prefix( $hook, $prefix ) ) {
					return array(
						'type' => 'theme',
						'name' => $theme->get( 'Name' ),
						'file' => $theme->get_stylesheet_directory(),
					);
				}
			}
		}

		return null;
	}

	/**
	 * Resolve a callback to a file path using ReflectionFunction/ReflectionMethod.
	 *
	 * @param mixed $callback Callback.
	 * @return string Absolute file path or empty string.
	 */
	private function callback_to_file( $callback ): string {
		try {
			if ( $callback instanceof Closure ) {
				$ref  = new ReflectionFunction( $callback );
				return (string) $ref->getFileName();
			}
			if ( is_array( $callback ) ) {
				$ref = new ReflectionMethod( $callback[0], $callback[1] );
				return (string) $ref->getFileName();
			}
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$ref = new ReflectionFunction( $callback );
				return (string) $ref->getFileName();
			}
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );
				if ( 2 === count( $parts ) && class_exists( $parts[0] ) ) {
					$ref = new ReflectionMethod( $parts[0], $parts[1] );
					return (string) $ref->getFileName();
				}
			}
		} catch ( ReflectionException $e ) {
			// Ignore.
		}
		return '';
	}

	/**
	 * Check if a hook name matches known WP core cron hooks.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private function is_core_hook( string $hook ): bool {
		$core = array(
			'wp_scheduled_delete', 'wp_update_themes', 'wp_update_plugins',
			'wp_version_check', 'wp_scheduled_auto_draft_delete',
			'delete_expired_transients', 'recovery_mode_clean_expired_keys',
			'wp_privacy_delete_old_export_files', 'wp_site_health_scheduled_check',
			'wp_https_detection', 'wp_update_user_counts',
			'wp_update_comment_type', 'wp_delete_temp_updater_backups',
			'wp_scheduled_purge_all_comments', 'wp_scheduled_comment_purge',
			'wp_maybe_auto_update', 'wp_split_shared_term_batch',
			'wp_network_dashboard_setup', 'do_pings', 'publish_future_post',
			'recovery_mode_clean_expired_keys', 'wp_scheduled_auto_draft_delete',
		);
		if ( in_array( $hook, $core, true ) ) {
			return true;
		}
		return 0 === strpos( $hook, 'wp_' ) && (
			false !== strpos( $hook, '_delete' )
			|| false !== strpos( $hook, '_cleanup' )
			|| false !== strpos( $hook, '_purge' )
			|| false !== strpos( $hook, '_check' )
		);
	}

	/** Build cron health diagnostics. */
	private function get_cron_health( array $events ): array {
		$now = time();
		$overdue = 0; $very_frequent = 0; $without_callback = array();
		foreach ( $events as $event ) {
			if ( absint( $event['timestamp'] ) < $now - HOUR_IN_SECONDS ) { $overdue++; }
			if ( ! empty( $event['interval'] ) && absint( $event['interval'] ) < 5 * MINUTE_IN_SECONDS ) { $very_frequent++; }
			if ( ! has_action( (string) $event['hook'] ) ) { $without_callback[] = (string) $event['hook']; }
		}
		$lock = get_transient( 'doing_cron' );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return array(
			array( 'label' => __( 'WP-Cron execution', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'status' => $cron_disabled ? __( 'External', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'WordPress', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $cron_disabled ? 'tsosk-badge-info' : 'tsosk-badge-ok', 'details' => $cron_disabled ? __( 'DISABLE_WP_CRON is enabled. A real server cron should call wp-cron.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'WP-Cron is triggered by site traffic.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			array( 'label' => __( 'Cron lock', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'status' => $lock ? __( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Clear', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $lock ? 'tsosk-badge-info' : 'tsosk-badge-ok', 'details' => $lock ? __( 'A cron process appears to be running.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'No doing_cron lock found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			array( 'label' => __( 'Overdue events', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'status' => $overdue > 0 ? __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $overdue > 0 ? 'tsosk-badge-warn' : 'tsosk-badge-ok', 'details' => sprintf( /* translators: %d: number of overdue events */ __( '%d events are more than one hour overdue.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $overdue ) ),
			array( 'label' => __( 'Very frequent events', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'status' => $very_frequent > 0 ? __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => $very_frequent > 0 ? 'tsosk-badge-warn' : 'tsosk-badge-ok', 'details' => sprintf( /* translators: %d: number of very frequent events */ __( '%d recurring events run more often than every 5 minutes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $very_frequent ) ),
			array( 'label' => __( 'Hooks without callback', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'status' => ! empty( $without_callback ) ? __( 'Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'badge' => ! empty( $without_callback ) ? 'tsosk-badge-warn' : 'tsosk-badge-ok', 'details' => empty( $without_callback ) ? __( 'All scheduled hooks have callbacks loaded.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : implode( ', ', array_slice( array_unique( $without_callback ), 0, 8 ) ) ),
		);
	}
}
