<?php
/**
 * TSO Swiss Knife – Module: Activity History.
 *
 * Central log of changes made through the plugin.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_History
 */
class TSOSK_Mod_History {

	/** @var TSOSK_Mod_History|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_history_clear', array( $this, 'ajax_clear' ) );
		add_action( 'wp_ajax_tsosk_history_save', array( $this, 'ajax_save_settings' ) );
	}

	/**
	 * AJAX: clear the central activity log.
	 */
	public function ajax_clear(): void {
		check_ajax_referer( 'tsosk_history_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}
		TSOSK_Activity_Log::clear();
		wp_send_json_success( __( 'Activity history cleared.', 'tso-swiss-knife' ) );
	}

	/**
	 * AJAX: save activity log preferences.
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( 'tsosk_history_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$modules = array();
		if ( isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ) {
			$modules = array_map( 'sanitize_key', wp_unslash( $_POST['modules'] ) );
		}
		$log_all = ! empty( $_POST['log_all'] );
		$limit   = max( 25, min( 500, absint( wp_unslash( $_POST['limit'] ?? TSOSK_Activity_Log::LIMIT ) ) ) );

		TSOSK_Activity_Log::save_settings(
			array(
				'modules' => $log_all ? array() : $modules,
				'limit'   => $limit,
			)
		);

		wp_send_json_success( __( 'Activity log settings saved.', 'tso-swiss-knife' ) );
	}

	/**
	 * Render the Activity History tab.
	 */
	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_history_nonce' );
		$entries  = TSOSK_Activity_Log::get_entries();
		$settings = TSOSK_Activity_Log::get_settings();
		$limit    = TSOSK_Activity_Log::get_limit();
		$enabled_modules = array_map( 'sanitize_key', $settings['modules'] );
		$log_all         = empty( $enabled_modules );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'All important changes made through TSO Swiss Knife are recorded here: options edited, database replacements, maintenance mode, admin menu, and more.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Activity History', 'tso-swiss-knife' ); ?>
				<?php if ( ! empty( $entries ) ) : ?>
				<span class="tsosk-badge tsosk-badge-info tsosk-history-count">
					<?php
					printf(
						/* translators: 1: current count, 2: max entries */
						esc_html__( '%1$d / %2$d', 'tso-swiss-knife' ),
						absint( count( $entries ) ),
						absint( $limit )
					);
					?>
				</span>
				<?php endif; ?>
			</h3>

			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum number of history entries */
					esc_html__( 'Shows the last %d actions across enabled plugin tools.', 'tso-swiss-knife' ),
					absint( $limit )
				);
				?>
			</p>

			<?php if ( empty( $entries ) ) : ?>
			<p class="tsosk-history-empty">
				<?php esc_html_e( 'No changes recorded yet. When you save settings or edit data in any TSO Swiss Knife tool, it will appear here.', 'tso-swiss-knife' ); ?>
			</p>
			<?php else : ?>
			<p class="tsosk-history-actions">
				<button type="button" class="button button-link-delete" id="tsosk-history-clear"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear history', 'tso-swiss-knife' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-history-msg"></span>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-history-table">
					<thead>
						<tr>
							<th class="tsosk-history-col-date"><?php esc_html_e( 'Date', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-history-col-tool"><?php esc_html_e( 'Tool', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-history-col-action"><?php esc_html_e( 'Action', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Summary', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-history-col-user"><?php esc_html_e( 'User', 'tso-swiss-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php echo $this->render_row( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Logging preferences', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Choose which tools write to the history and how many entries to keep. Use “Log all tools” for the default, or uncheck it and select only the modules you want recorded.', 'tso-swiss-knife' ); ?>
			</p>
			<label style="display:block;margin-bottom:12px;">
				<input type="checkbox" id="tsosk-history-log-all" <?php checked( $log_all ); ?>>
				<?php esc_html_e( 'Log all tools (ignore checklist below)', 'tso-swiss-knife' ); ?>
			</label>
			<label style="display:block;margin-bottom:12px;">
				<?php esc_html_e( 'Maximum entries', 'tso-swiss-knife' ); ?>
				<input type="number" id="tsosk-history-limit" min="25" max="500" step="25"
				       value="<?php echo esc_attr( (string) $limit ); ?>" style="width:80px;margin-left:8px;">
			</label>
			<div class="tsosk-history-modules" id="tsosk-history-modules" <?php echo $log_all ? 'style="opacity:.55;"' : ''; ?>>
				<?php
				foreach ( TSOSK_Activity_Log::get_configurable_modules() as $module ) :
					?>
				<label style="font-size:13px;">
					<input type="checkbox" class="tsosk-history-module" name="tsosk_history_modules[]"
					       value="<?php echo esc_attr( $module ); ?>"
					       <?php checked( $log_all || in_array( $module, $enabled_modules, true ) ); ?>>
					<?php echo esc_html( TSOSK_Activity_Log::module_label( $module ) ); ?>
				</label>
				<?php endforeach; ?>
			</div>
			<style>
			.tsosk-history-modules {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
				gap: 6px 16px;
				margin-bottom: 12px;
			}
			</style>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Tip: check only the modules you care about to reduce noise. Save, then uncheck modules you want to exclude — or clear history after changing filters.', 'tso-swiss-knife' ); ?>
			</p>
			<button type="button" class="button button-primary" id="tsosk-history-save-settings"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save logging preferences', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-history-settings-msg"></span>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $entry Log entry.
	 * @return string
	 */
	private function render_row( array $entry ): string {
		$module  = sanitize_key( (string) ( $entry['module'] ?? '' ) );
		$action  = sanitize_key( (string) ( $entry['action'] ?? '' ) );
		$time    = (int) ( $entry['time'] ?? 0 );
		$user    = sanitize_user( (string) ( $entry['user'] ?? '' ), true );
		$summary = (string) ( $entry['summary'] ?? '' );
		$details = is_array( $entry['details'] ?? null ) ? $entry['details'] : array();

		$badge_class = 'delete' === $action ? 'tsosk-badge-warn' : ( 'enable' === $action || 'add' === $action ? 'tsosk-badge-ok' : 'tsosk-badge-info' );

		$detail_html = $this->format_details( $details );

		ob_start();
		?>
		<tr>
			<td class="tsosk-history-date"><?php echo esc_html( wp_date( 'Y-m-d H:i', $time ) ); ?></td>
			<td><?php echo esc_html( TSOSK_Activity_Log::module_label( $module ) ); ?></td>
			<td><span class="tsosk-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( TSOSK_Activity_Log::action_label( $action ) ); ?></span></td>
			<td class="tsosk-history-summary">
				<?php echo esc_html( TSOSK_Activity_Log::translate_summary( $module, $action, $summary, $details ) ); ?>
				<?php if ( '' !== $detail_html ) : ?>
				<span class="tsosk-history-details"><?php echo wp_kses_post( $detail_html ); ?></span>
				<?php endif; ?>
			</td>
			<td class="tsosk-code tsosk-history-user"><?php echo esc_html( $user ); ?></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $details Entry details.
	 * @return string
	 */
	private function format_details( array $details ): string {
		if ( isset( $details['name'] ) ) {
			$old   = (string) ( $details['old'] ?? '' );
			$new   = (string) ( $details['new'] ?? '' );
			$parts = array( '<code>' . esc_html( (string) $details['name'] ) . '</code>' );
			if ( '' !== $old || '' !== $new ) {
				$parts[] = esc_html__( 'Before:', 'tso-swiss-knife' ) . ' <code>' . esc_html( $old ? $old : __( '(empty)', 'tso-swiss-knife' ) ) . '</code>';
				$parts[] = esc_html__( 'After:', 'tso-swiss-knife' ) . ' <code>' . esc_html( $new ? $new : __( '(empty)', 'tso-swiss-knife' ) ) . '</code>';
			}
			return implode( ' · ', $parts );
		}

		if ( isset( $details['rows'] ) ) {
			return sprintf(
				/* translators: 1: tables, 2: rows, 3: cells */
				esc_html__( '%1$d tables, %2$d rows, %3$d cells', 'tso-swiss-knife' ),
				(int) ( $details['tables'] ?? 0 ),
				(int) ( $details['rows'] ?? 0 ),
				(int) ( $details['cells'] ?? 0 )
			);
		}

		if ( isset( $details['source'] ) ) {
			return '<code>' . esc_html( (string) $details['source'] ) . '</code>';
		}

		if ( isset( $details['meta_key'] ) || isset( $details['key'] ) ) {
			return '<code>' . esc_html( (string) ( $details['key'] ?? $details['meta_key'] ) ) . '</code>';
		}

		if ( isset( $details['table'] ) ) {
			return '<code>' . esc_html( (string) $details['table'] ) . '</code>';
		}

		if ( isset( $details['file'] ) ) {
			return '<code>' . esc_html( (string) $details['file'] ) . '</code>';
		}

		if ( isset( $details['hook'] ) ) {
			return '<code>' . esc_html( (string) $details['hook'] ) . '</code>';
		}

		if ( isset( $details['role'] ) && isset( $details['cap'] ) ) {
			return sprintf(
				/* translators: 1: role slug, 2: capability */
				esc_html__( '%1$s → %2$s', 'tso-swiss-knife' ),
				(string) $details['role'],
				(string) $details['cap']
			);
		}

		return '';
	}
}
