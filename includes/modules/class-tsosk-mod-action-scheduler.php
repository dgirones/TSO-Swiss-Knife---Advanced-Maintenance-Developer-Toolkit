<?php
/**
 * TSO Swiss Knife – Module: Action Scheduler (WooCommerce / compatible plugins).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Action_Scheduler
 */
class TSOSK_Mod_Action_Scheduler {

	private const PER_PAGE = 25;

	/** @var TSOSK_Mod_Action_Scheduler|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_asched_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_tsosk_asched_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_tsosk_asched_delete', array( $this, 'ajax_delete' ) );
	}

	/**
	 * Whether Action Scheduler is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'as_get_scheduled_actions' )
			|| class_exists( 'ActionScheduler' )
			|| class_exists( 'ActionScheduler_Store' );
	}

	/**
	 * @return string[]
	 */
	private function get_status_choices(): array {
		if ( class_exists( 'ActionScheduler_Store' ) ) {
			return array(
				'pending'   => ActionScheduler_Store::STATUS_PENDING,
				'in-progress' => ActionScheduler_Store::STATUS_RUNNING,
				'failed'    => ActionScheduler_Store::STATUS_FAILED,
				'complete'  => ActionScheduler_Store::STATUS_COMPLETE,
				'canceled'  => ActionScheduler_Store::STATUS_CANCELED,
			);
		}
		return array(
			'pending'     => 'pending',
			'in-progress' => 'in-progress',
			'failed'      => 'failed',
			'complete'    => 'complete',
			'canceled'    => 'canceled',
		);
	}

	/**
	 * @param string $status UI status key.
	 * @return string
	 */
	private function map_status( string $status ): string {
		$map = $this->get_status_choices();
		return $map[ $status ] ?? $map['pending'];
	}

	/**
	 * @param string $status UI status.
	 * @param int    $page   Page number.
	 * @return array{rows: array<int, array>, total: int}
	 */
	private function fetch_actions( string $status, int $page ): array {
		if ( ! self::is_available() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		$args = array(
			'status'   => $this->map_status( $status ),
			'per_page' => self::PER_PAGE,
			'offset'   => ( $page - 1 ) * self::PER_PAGE,
			'orderby'  => 'scheduled_date_gmt',
			'order'    => 'DESC',
		);

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions( $args );
			$total   = 0;
			if ( function_exists( 'as_count_scheduled_actions' ) ) {
				$count_args = $args;
				unset( $count_args['per_page'], $count_args['offset'] );
				$total = (int) as_count_scheduled_actions( $count_args );
			} elseif ( is_array( $actions ) ) {
				$total = count( $actions );
			}
			return array(
				'rows'  => $this->format_action_rows( $actions ),
				'total' => $total,
			);
		}

		return array( 'rows' => array(), 'total' => 0 );
	}

	/**
	 * @param array<int, mixed> $actions Raw actions.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_action_rows( $actions ): array {
		if ( ! is_array( $actions ) ) {
			return array();
		}
		$rows = array();
		foreach ( $actions as $key => $action ) {
			if ( is_numeric( $action ) || ( is_numeric( $key ) && is_numeric( $action ) ) ) {
				$id = (int) ( is_numeric( $action ) ? $action : $key );
				$rows[] = $this->format_action_from_store( $id );
				continue;
			}
			if ( is_object( $action ) && method_exists( $action, 'get_id' ) ) {
				$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
				$rows[]   = array(
					'id'       => $action->get_id(),
					'hook'     => method_exists( $action, 'get_hook' ) ? $action->get_hook() : '',
					'status'   => method_exists( $action, 'get_status' ) ? $action->get_status() : '',
					'group'    => method_exists( $action, 'get_group' ) ? (string) $action->get_group() : '',
					'schedule' => is_object( $schedule ) && method_exists( $schedule, 'get_date' )
						? (string) $schedule->get_date()->format( 'Y-m-d H:i:s' )
						: (string) $schedule,
				);
			} elseif ( is_array( $action ) ) {
				$rows[] = array(
					'id'       => $action['action_id'] ?? $action['ID'] ?? 0,
					'hook'     => $action['hook'] ?? '',
					'status'   => $action['status'] ?? '',
					'group'    => $action['group'] ?? '',
					'schedule' => $action['scheduled_date_gmt'] ?? '',
				);
			}
		}
		return $rows;
	}

	/**
	 * Load a single action row from the Action Scheduler store.
	 *
	 * @param int $id Action ID.
	 * @return array<string, mixed>
	 */
	private function format_action_from_store( int $id ): array {
		$row = array(
			'id'       => $id,
			'hook'     => '',
			'status'   => '',
			'group'    => '',
			'schedule' => '',
		);
		if ( ! $id || ! class_exists( 'ActionScheduler' ) || ! method_exists( 'ActionScheduler', 'store' ) ) {
			return $row;
		}
		try {
			$action = ActionScheduler::store()->fetch_action( $id );
			if ( is_object( $action ) && method_exists( $action, 'get_id' ) ) {
				$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
				$row      = array(
					'id'       => $action->get_id(),
					'hook'     => method_exists( $action, 'get_hook' ) ? $action->get_hook() : '',
					'status'   => method_exists( $action, 'get_status' ) ? $action->get_status() : '',
					'group'    => method_exists( $action, 'get_group' ) ? (string) $action->get_group() : '',
					'schedule' => is_object( $schedule ) && method_exists( $schedule, 'get_date' )
						? (string) $schedule->get_date()->format( 'Y-m-d H:i:s' )
						: (string) $schedule,
				);
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Leave partial row.
		}
		return $row;
	}

	public function ajax_list(): void {
		check_ajax_referer( 'tsosk_asched_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		if ( ! self::is_available() ) {
			wp_send_json_error( __( 'Action Scheduler is not available on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		if ( ! array_key_exists( $status, $this->get_status_choices() ) ) {
			$status = 'pending';
		}
		$page = max( 1, isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1 );
		$data = $this->fetch_actions( $status, $page );

		wp_send_json_success(
			array(
				'rows'  => $data['rows'],
				'total' => $data['total'],
				'page'  => $page,
				'pages' => (int) ceil( $data['total'] / self::PER_PAGE ),
			)
		);
	}

	public function ajax_cancel(): void {
		check_ajax_referer( 'tsosk_asched_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$id = isset( $_POST['action_id'] ) ? absint( wp_unslash( $_POST['action_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid action ID.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
			ActionScheduler::store()->cancel_action( $id );
			TSOSK_Activity_Log::log(
				'action-scheduler',
				'cancel',
				sprintf(
					/* translators: %d: action ID */
					__( 'Action Scheduler action canceled (#%d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$id
				)
			);
			wp_send_json_success( __( 'Action canceled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		wp_send_json_error( __( 'Could not cancel this action.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_asched_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$id = isset( $_POST['action_id'] ) ? absint( wp_unslash( $_POST['action_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid action ID.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
			ActionScheduler::store()->delete_action( $id );
			TSOSK_Activity_Log::log(
				'action-scheduler',
				'delete',
				sprintf(
					/* translators: %d: action ID */
					__( 'Action Scheduler action deleted (#%d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$id
				)
			);
			wp_send_json_success( __( 'Action deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		wp_send_json_error( __( 'Could not delete this action.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		$nonce     = wp_create_nonce( 'tsosk_asched_nonce' );
		$available = self::is_available();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect background jobs queued by WooCommerce and other plugins that use Action Scheduler. Cancel pending or delete failed actions when you know they are safe to remove.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( ! $available ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php esc_html_e( 'Action Scheduler was not detected. Install WooCommerce or a plugin that bundles Action Scheduler to use this tool.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php else : ?>
		<div class="tsosk-toolbar">
			<label>
				<?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<select id="tsosk-asched-status">
					<option value="pending"><?php esc_html_e( 'Pending', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="in-progress"><?php esc_html_e( 'In progress', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="complete"><?php esc_html_e( 'Complete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="canceled"><?php esc_html_e( 'Canceled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				</select>
			</label>
			<button type="button" class="button" id="tsosk-asched-refresh"><?php esc_html_e( 'Refresh', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			<span class="tsosk-ajax-msg" id="tsosk-asched-msg"></span>
		</div>

		<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-asched-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Hook', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Group', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody id="tsosk-asched-tbody">
					<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="tsosk-pagination" id="tsosk-asched-pagination"></div>
		<input type="hidden" id="tsosk-asched-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php endif; ?>
		<?php
	}
}
