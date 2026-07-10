<?php
/**
 * TSO Swiss Knife – Module: Transients Manager.
 *
 * Lists, searches and deletes WordPress transients.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Transients {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_transient_delete',     array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_tsosk_transients_purge_exp', array( $this, 'ajax_purge_expired' ) );
		add_action( 'wp_ajax_tsosk_transients_purge_all', array( $this, 'ajax_purge_all' ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Fetch transients from wp_options (non-external object-cache sites).
	 *
	 * @param string $search  Optional search string.
	 * @param string $filter  'all' | 'expired' | 'active'.
	 * @param int    $limit   Max rows to return.
	 * @return array
	 */
	private function get_transients( string $search = '', string $filter = 'all', int $limit = 200 ): array {
		global $wpdb;

		if ( '' !== $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value
					 FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					   AND option_name NOT LIKE %s
					   AND option_name LIKE %s
					 ORDER BY option_name
					 LIMIT %d",
					'_transient_%',
					'_transient_timeout_%',
					'%' . $wpdb->esc_like( '_transient_' . $search ) . '%',
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value
					 FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					   AND option_name NOT LIKE %s
					 ORDER BY option_name
					 LIMIT %d",
					'_transient_%',
					'_transient_timeout_%',
					$limit
				),
				ARRAY_A
			);
		}

		if ( ! $rows ) {
			return array();
		}

		$now  = time();
		$out  = array();

		foreach ( $rows as $row ) {
			$key     = substr( $row['option_name'], strlen( '_transient_' ) );
			$timeout = (int) get_option( '_transient_timeout_' . $key, 0 );
			$expired = $timeout > 0 && $timeout < $now;

			if ( 'expired' === $filter && ! $expired ) {
				continue;
			}
			if ( 'active' === $filter && $expired ) {
				continue;
			}

			$out[] = array(
				'key'     => $key,
				'size'    => strlen( $row['option_value'] ),
				'timeout' => $timeout,
				'expired' => $expired,
			);
		}
		return $out;
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_transients_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! $key ) {
			wp_send_json_error( __( 'Invalid key.', 'tso-swiss-knife' ) );
		}
		delete_transient( $key );
		TSOSK_Activity_Log::log(
			'transients',
			'delete',
			sprintf(
				/* translators: %s: transient key */
				__( 'Transient deleted: %s.', 'tso-swiss-knife' ),
				$key
			),
			array( 'key' => $key )
		);
		wp_send_json_success( __( 'Transient deleted.', 'tso-swiss-knife' ) );
	}

	public function ajax_purge_expired(): void {
		check_ajax_referer( 'tsosk_transients_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		global $wpdb;
		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				'_transient_timeout_%',
				$now
			)
		);

		$count = 0;
		foreach ( $expired_keys as $timeout_key ) {
			$key = substr( $timeout_key, strlen( '_transient_timeout_' ) );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}

		TSOSK_Activity_Log::log(
			'transients',
			'purge',
			sprintf(
				/* translators: %d: number of transients */
				__( 'Expired transients purged: %d.', 'tso-swiss-knife' ),
				$count
			)
		);

		wp_send_json_success(
			sprintf(
				/* translators: %d: number of deleted transients */
				__( '%d expired transients deleted.', 'tso-swiss-knife' ),
				$count
			)
		);
	}

	public function ajax_purge_all(): void {
		check_ajax_referer( 'tsosk_transients_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				'_transient_%',
				'_transient_timeout_%'
			)
		);
		$count = 0;
		foreach ( $all_keys as $option_name ) {
			$key = substr( $option_name, strlen( '_transient_' ) );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}
		TSOSK_Activity_Log::log(
			'transients',
			'purge',
			sprintf(
				/* translators: %d: number of transients */
				__( 'All transients purged: %d.', 'tso-swiss-knife' ),
				$count
			)
		);
		wp_send_json_success(
			sprintf(
				/* translators: %d: number of deleted transients */
				__( '%d transients deleted.', 'tso-swiss-knife' ),
				$count
			)
		);
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		$nonce  = wp_create_nonce( 'tsosk_transients_nonce' );
		$search = isset( $_GET['tsosk_ts_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_ts_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['tsosk_ts_filter'] ) ? sanitize_key( wp_unslash( $_GET['tsosk_ts_filter'] ) ) : 'all';     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $filter, array( 'all', 'expired', 'active' ), true ) ) {
			$filter = 'all';
		}

		$items = $this->get_transients( $search, $filter );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Manage WordPress transients stored in the options table. This view works on sites without an external object cache.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-toolbar">
			<form method="get" action="" class="tsosk-transients-filter-form">
				<input type="hidden" name="page" value="tso-swiss-knife">
				<input type="hidden" name="tab"  value="transients">
				<input type="text" id="tsosk-transients-search" name="tsosk_ts_search" value="<?php echo esc_attr( $search ); ?>"
				       placeholder="<?php esc_attr_e( 'Search transients…', 'tso-swiss-knife' ); ?>"
				       style="width:220px;">
				<select name="tsosk_ts_filter" id="tsosk-transients-filter-select" style="display:none;">
					<option value="all"     <?php selected( $filter, 'all' ); ?>><?php esc_html_e( 'All', 'tso-swiss-knife' ); ?></option>
					<option value="expired" <?php selected( $filter, 'expired' ); ?>><?php esc_html_e( 'Expired', 'tso-swiss-knife' ); ?></option>
					<option value="active"  <?php selected( $filter, 'active' ); ?>><?php esc_html_e( 'Active', 'tso-swiss-knife' ); ?></option>
				</select>
				<?php submit_button( __( 'Filter', 'tso-swiss-knife' ), 'secondary', '', false ); ?>
			</form>

			<span class="tsosk-filter-pills" role="group" aria-label="<?php esc_attr_e( 'Status filter', 'tso-swiss-knife' ); ?>" data-active-filter="<?php echo esc_attr( $filter ); ?>">
				<button type="button" class="button button-small tsosk-transients-filter tsosk-filter-active" data-filter="all"><?php esc_html_e( 'All', 'tso-swiss-knife' ); ?></button>
				<button type="button" class="button button-small tsosk-transients-filter" data-filter="active"><?php esc_html_e( 'Active', 'tso-swiss-knife' ); ?></button>
				<button type="button" class="button button-small tsosk-transients-filter" data-filter="expired"><?php esc_html_e( 'Expired', 'tso-swiss-knife' ); ?></button>
			</span>

			<button class="button button-secondary" id="tsosk-purge-expired" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				🧹 <?php esc_html_e( 'Purge Expired', 'tso-swiss-knife' ); ?>
			</button>
			<button class="button button-link-delete" id="tsosk-purge-all" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Delete All', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-transient-bulk-msg"></span>
		</div>

		<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table" id="tsosk-transients-table">
				<thead>
					<tr>
						<th class="tsosk-sortable" data-sort="key" scope="col"><?php esc_html_e( 'Key', 'tso-swiss-knife' ); ?></th>
						<th class="tsosk-sortable" data-sort="size" scope="col"><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
						<th class="tsosk-sortable" data-sort="timeout" scope="col"><?php esc_html_e( 'Expires', 'tso-swiss-knife' ); ?></th>
						<th class="tsosk-sortable" data-sort="status" scope="col"><?php esc_html_e( 'Status', 'tso-swiss-knife' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'tso-swiss-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No transients found.', 'tso-swiss-knife' ); ?></td></tr>
					<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
					<tr id="tsosk-tr-<?php echo esc_attr( md5( $item['key'] ) ); ?>"
					    class="<?php echo $item['expired'] ? 'tsosk-transient-expired' : ''; ?>"
					    data-key="<?php echo esc_attr( $item['key'] ); ?>"
					    data-size="<?php echo esc_attr( (string) $item['size'] ); ?>"
					    data-timeout="<?php echo esc_attr( (string) $item['timeout'] ); ?>"
					    data-status="<?php echo esc_attr( $item['expired'] ? 'expired' : 'active' ); ?>">
						<td class="tsosk-code"><?php echo esc_html( $item['key'] ); ?></td>
						<td><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td>
						<td>
							<?php
							if ( ! $item['timeout'] ) {
								esc_html_e( 'No expiry', 'tso-swiss-knife' );
							} elseif ( $item['expired'] ) {
								echo '<span title="' . esc_attr( gmdate( 'Y-m-d H:i:s', $item['timeout'] ) ) . ' UTC">'
								     . esc_html( human_time_diff( $item['timeout'] ) ) . ' ' . esc_html__( 'ago', 'tso-swiss-knife' ) . '</span>';
							} else {
								echo '<span title="' . esc_attr( gmdate( 'Y-m-d H:i:s', $item['timeout'] ) ) . ' UTC">'
								     . esc_html__( 'In', 'tso-swiss-knife' ) . ' ' . esc_html( human_time_diff( $item['timeout'] ) ) . '</span>';
							}
							?>
						</td>
						<td>
							<?php if ( $item['expired'] ) : ?>
								<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Expired', 'tso-swiss-knife' ); ?></span>
							<?php else : ?>
								<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Active', 'tso-swiss-knife' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<button class="button button-small button-link-delete tsosk-transient-delete"
							        data-key="<?php echo esc_attr( $item['key'] ); ?>"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Delete', 'tso-swiss-knife' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
