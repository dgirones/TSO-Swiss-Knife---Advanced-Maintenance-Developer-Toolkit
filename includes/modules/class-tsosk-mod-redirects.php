<?php
/**
 * TSO Swiss Knife – Module: Redirects.
 *
 * Adds reviewed, option-backed redirects without editing .htaccess.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Redirects
 */
class TSOSK_Mod_Redirects {

	/** Plugin option storing redirect rules. */
	private const OPTION = 'tsosk_redirect_rules';

	/** Plugin option storing recent 404 hits. */
	private const LOG_OPTION = 'tsosk_404_log';

	/** @var TSOSK_Mod_Redirects|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_Redirects
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_redirect_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_redirect_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_tsosk_redirect_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_tsosk_404_clear', array( $this, 'ajax_clear_404_log' ) );
	}

	/**
	 * Register frontend redirect handling.
	 */
	public function init(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Apply the first matching enabled redirect rule.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$request_path = $this->normalize_path( rawurldecode( $request_path ) );
		if ( '' === $request_path ) {
			return;
		}

		$rules = $this->get_rules();
		foreach ( $rules as $id => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			$target = $this->resolve_target( $rule, $request_path );
			if ( false === $target ) {
				continue;
			}

			if ( in_array( absint( $rule['status'] ), array( 410, 451 ), true ) ) {
				$rules[ $id ]['hits']     = absint( $rule['hits'] ) + 1;
				$rules[ $id ]['last_hit'] = time();
				update_option( self::OPTION, $rules, false );

				status_header( absint( $rule['status'] ) );
				nocache_headers();
				wp_die(
					esc_html( 410 === absint( $rule['status'] ) ? __( 'This content is no longer available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'This content is unavailable for legal reasons.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					esc_html( 410 === absint( $rule['status'] ) ? __( 'Gone', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Unavailable For Legal Reasons', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					array( 'response' => absint( $rule['status'] ) )
				);
			}

			$target_url = $this->target_to_url( $target );
			if ( '' === $target_url || $this->is_loop( $rule['source'], $target_url ) ) {
				continue;
			}

			$rules[ $id ]['hits']     = absint( $rule['hits'] ) + 1;
			$rules[ $id ]['last_hit'] = time();
			update_option( self::OPTION, $rules, false );

			wp_safe_redirect( $target_url, absint( $rule['status'] ) );
			exit;
		}

		if ( is_404() ) {
			$this->record_404( $request_uri, $request_path );
		}
	}

	/**
	 * AJAX: create or update a redirect.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$id      = isset( $_POST['redirect_id'] ) ? sanitize_key( wp_unslash( $_POST['redirect_id'] ) ) : '';
		$source  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$status  = isset( $_POST['status'] ) ? absint( wp_unslash( $_POST['status'] ) ) : 301;
		$enabled = ! empty( $_POST['enabled'] );
		$match_type = isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'exact';

		if ( ! in_array( $match_type, array( 'exact', 'wildcard', 'regex' ), true ) ) {
			$match_type = 'exact';
		}

		$source = 'regex' === $match_type ? trim( $source ) : $this->normalize_path( $source );
		if ( '' === $source ) {
			wp_send_json_error( __( 'Enter a valid source path such as /old-page/.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'regex' === $match_type && false === @preg_match( '#' . str_replace( '#', '\#', $source ) . '#', '/' ) ) {
			wp_send_json_error( __( 'Enter a valid regular expression source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! in_array( $status, $this->allowed_statuses(), true ) ) {
			wp_send_json_error( __( 'Invalid redirect status.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$target_url = in_array( $status, array( 410, 451 ), true ) ? '' : $this->target_to_url( $target );
		if ( ! in_array( $status, array( 410, 451 ), true ) && '' === $target_url ) {
			wp_send_json_error( __( 'Enter a valid target URL or site path.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( '' !== $target_url && 'regex' !== $match_type && $this->is_loop( $source, $target_url ) ) {
			wp_send_json_error( __( 'The target points back to the source and would create a loop.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$rules = $this->get_rules();
		if ( '' === $id || ! isset( $rules[ $id ] ) ) {
			$id = $this->new_rule_id();
		}

		$created = isset( $rules[ $id ]['created'] ) ? absint( $rules[ $id ]['created'] ) : time();
		$hits    = isset( $rules[ $id ]['hits'] ) ? absint( $rules[ $id ]['hits'] ) : 0;
		$last_hit = isset( $rules[ $id ]['last_hit'] ) ? absint( $rules[ $id ]['last_hit'] ) : 0;

		$rules[ $id ] = array(
			'id'       => $id,
			'source'   => $source,
			'target'   => $target,
			'match_type' => $match_type,
			'status'   => $status,
			'enabled'  => $enabled,
			'hits'     => $hits,
			'last_hit' => $last_hit,
			'created'  => $created,
		);

		update_option( self::OPTION, $rules, false );
		TSOSK_Activity_Log::log(
			'redirects',
			'save',
			sprintf(
				/* translators: 1: source path, 2: HTTP status code */
				__( 'Redirect saved: %1$s (%2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$source,
				$status
			),
			array( 'source' => $source )
		);
		wp_send_json_success( array( 'message' => __( 'Redirect saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) );
	}

	/**
	 * AJAX: delete a redirect.
	 */
	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$id    = isset( $_POST['redirect_id'] ) ? sanitize_key( wp_unslash( $_POST['redirect_id'] ) ) : '';
		$rules = $this->get_rules();
		if ( '' === $id || ! isset( $rules[ $id ] ) ) {
			wp_send_json_error( __( 'Redirect not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$source = (string) ( $rules[ $id ]['source'] ?? $id );
		unset( $rules[ $id ] );
		update_option( self::OPTION, $rules, false );
		TSOSK_Activity_Log::log(
			'redirects',
			'delete',
			__( 'Redirect deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array( 'source' => $source )
		);
		wp_send_json_success( array( 'message' => __( 'Redirect deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) );
	}

	/**
	 * AJAX: toggle a redirect.
	 */
	public function ajax_toggle(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$id    = isset( $_POST['redirect_id'] ) ? sanitize_key( wp_unslash( $_POST['redirect_id'] ) ) : '';
		$rules = $this->get_rules();
		if ( '' === $id || ! isset( $rules[ $id ] ) ) {
			wp_send_json_error( __( 'Redirect not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$rules[ $id ]['enabled'] = empty( $rules[ $id ]['enabled'] );
		$enabled                 = ! empty( $rules[ $id ]['enabled'] );
		update_option( self::OPTION, $rules, false );
		TSOSK_Activity_Log::log(
			'redirects',
			'toggle',
			$enabled
				? __( 'Redirect enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Redirect disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			array( 'source' => (string) ( $rules[ $id ]['source'] ?? '' ) )
		);
		wp_send_json_success( array( 'message' => __( 'Redirect updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) );
	}

	/**
	 * AJAX: clear 404 log.
	 */
	public function ajax_clear_404_log(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		delete_option( self::LOG_OPTION );
		TSOSK_Activity_Log::log( 'redirects', 'delete', __( '404 log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( array( 'message' => __( '404 log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) );
	}

	/**
	 * Render the Redirects tab.
	 */
	public function render(): void {
		$nonce   = wp_create_nonce( 'tsosk_redirects_nonce' );
		$rules   = $this->get_rules();
		$reviews = $this->review_rules( $rules );
		$not_found_log = $this->get_404_log();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Create and review safe WordPress-level redirects. Rules are stored in a prefixed option and are applied before the theme renders.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'Why use redirects?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Redirects tell browsers and search engines that a URL has moved. They preserve SEO value when you rename a post, merge content, or change permalink structure. A 301 permanent redirect passes most ranking signals to the new URL; 302/307 are for temporary moves. Use 410 when content is permanently removed. Google follows redirects when crawling — broken old URLs without redirects become 404s and may lose traffic.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Add or Edit Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<input type="hidden" id="tsosk-redirect-id" value="">
			<div class="tsosk-field-row">
				<label for="tsosk-redirect-source"><strong><?php esc_html_e( 'Source Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-redirect-source" class="regular-text" placeholder="/old-page/">
				<p class="description"><?php esc_html_e( 'Use a site-relative path only. Example: /old-page/.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>
			<div class="tsosk-field-row">
				<label for="tsosk-redirect-match-type"><strong><?php esc_html_e( 'Match Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<select id="tsosk-redirect-match-type">
					<option value="exact"><?php esc_html_e( 'Exact path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="wildcard"><?php esc_html_e( 'Wildcard (*)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="regex"><?php esc_html_e( 'Regular expression', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Wildcard captures can be used in the target as $1, $2, etc. Regex rules should not include delimiters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>
			<div class="tsosk-field-row">
				<label for="tsosk-redirect-target"><strong><?php esc_html_e( 'Target URL or Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-redirect-target" class="regular-text" placeholder="/new-page/">
				<p class="description"><?php esc_html_e( 'Use a site path or a URL allowed by WordPress safe redirects. Leave empty for 410 or 451 responses.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>
			<div class="tsosk-field-row">
				<label for="tsosk-redirect-status"><strong><?php esc_html_e( 'Status Code', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<select id="tsosk-redirect-status">
					<option value="301"><?php esc_html_e( '301 Permanent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="302"><?php esc_html_e( '302 Temporary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="307"><?php esc_html_e( '307 Temporary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="308"><?php esc_html_e( '308 Permanent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="410"><?php esc_html_e( '410 Gone', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="451"><?php esc_html_e( '451 Legal Reasons', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				</select>
			</div>
			<label class="tsosk-radio-row">
				<input type="checkbox" id="tsosk-redirect-enabled" checked>
				<?php esc_html_e( 'Enable this redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</label>
			<button class="button button-primary" id="tsosk-redirect-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button class="button button-secondary" id="tsosk-redirect-reset-form" type="button">
				<?php esc_html_e( 'Clear Form', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-redirect-msg"></span>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Redirect Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( empty( $reviews ) ) : ?>
				<p><?php esc_html_e( 'No redirect issues detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="tsosk-review-list">
					<?php foreach ( $reviews as $review ) : ?>
						<li>
							<span class="tsosk-badge <?php echo esc_attr( $this->badge_class( $review['type'] ) ); ?>">
								<?php echo esc_html( strtoupper( $review['type'] ) ); ?>
							</span>
							<?php echo esc_html( $review['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '404 Monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Recent 404 visits are captured so you can create redirects from missing URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( empty( $not_found_log ) ) : ?>
				<p><?php esc_html_e( 'No 404 visits recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<button class="button button-secondary" id="tsosk-404-clear" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear 404 Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-404-msg"></span>
				<p class="description" style="margin-top:10px;">
					<?php esc_html_e( 'Visits counts how many times each missing URL was requested. Referrer shows the previous page (HTTP Referer) when the browser sent it — direct visits, bots and bookmarks usually leave it empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<div class="tsosk-table-wrap tsosk-404-table-wrap" style="margin-top:12px;">
					<table class="widefat tsosk-table" id="tsosk-404-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Last visit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Referrer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $not_found_log as $item ) : ?>
							<?php $path_url = $this->rule_value_to_url( (string) $item['path'], 'exact', true ); ?>
							<tr>
								<td class="tsosk-code tsosk-redirect-url-col" data-label="<?php esc_attr_e( 'URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo wp_kses_post( $this->render_rule_url_cell( (string) $item['path'], $path_url ) ); ?></td>
								<td class="tsosk-404-col-visits" data-label="<?php esc_attr_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( number_format_i18n( absint( $item['hits'] ) ) ); ?></td>
								<td class="tsosk-404-col-date" data-label="<?php esc_attr_e( 'Last visit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $item['last_hit'] ) ) ); ?></td>
								<td class="tsosk-code tsosk-redirect-url-col" data-label="<?php esc_attr_e( 'Referrer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<?php
									$referrer = (string) ( $item['referrer'] ?? '' );
									if ( '' === $referrer ) {
										echo esc_html( '—' );
									} else {
										echo wp_kses_post( $this->render_rule_url_cell( $referrer, $referrer ) );
									}
									?>
								</td>
								<td data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<button class="button button-small tsosk-404-create-redirect" data-source="<?php echo esc_attr( $item['path'] ); ?>">
										<?php esc_html_e( 'Create Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3>
				<?php
				printf(
					/* translators: %d: number of redirects */
					esc_html__( 'Redirect Rules (%d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $rules )
				);
				?>
			</h3>
			<?php if ( empty( $rules ) ) : ?>
				<p><?php esc_html_e( 'No redirects have been created yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap tsosk-redirects-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-redirects-table">
						<colgroup>
							<col class="tsosk-redirect-col-source">
							<col class="tsosk-redirect-col-match">
							<col class="tsosk-redirect-col-target">
							<col class="tsosk-redirect-col-code">
							<col class="tsosk-redirect-col-active">
							<col class="tsosk-redirect-col-visits">
							<col class="tsosk-redirect-col-actions">
						</colgroup>
						<thead>
							<tr>
								<th class="tsosk-redirect-url-col"><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Match', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-redirect-url-col"><?php esc_html_e( 'Target', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'HTTP code', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rules as $rule ) : ?>
								<?php
								$source_url = $this->rule_value_to_url( (string) $rule['source'], (string) $rule['match_type'], true );
								$target_url = $this->rule_value_to_url( (string) $rule['target'], (string) $rule['match_type'], false );
								?>
								<tr>
									<td class="tsosk-code tsosk-redirect-url-col" data-label="<?php esc_attr_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo wp_kses_post( $this->render_rule_url_cell( (string) $rule['source'], $source_url ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Match', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $this->match_type_label( $rule['match_type'] ) ); ?></td>
									<td class="tsosk-code tsosk-redirect-url-col" data-label="<?php esc_attr_e( 'Target', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo wp_kses_post( $this->render_rule_url_cell( (string) $rule['target'], $target_url ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'HTTP code', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( (string) $rule['status'] ); ?></td>
									<td data-label="<?php esc_attr_e( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
										<?php if ( $rule['enabled'] ) : ?>
											<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php else : ?>
											<span class="tsosk-badge"><?php esc_html_e( 'Disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
										<?php echo esc_html( number_format_i18n( absint( $rule['hits'] ) ) ); ?>
										<?php if ( ! empty( $rule['last_hit'] ) ) : ?>
											<br><small class="description"><?php echo esc_html( sprintf(
												/* translators: %s: formatted date */
												__( 'Last visit: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
												date_i18n( get_option( 'date_format' ), absint( $rule['last_hit'] ) )
											) ); ?></small>
										<?php endif; ?>
									</td>
									<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
										<button class="button button-small tsosk-redirect-edit"
										        data-id="<?php echo esc_attr( $rule['id'] ); ?>"
										        data-source="<?php echo esc_attr( $rule['source'] ); ?>"
										        data-target="<?php echo esc_attr( $rule['target'] ); ?>"
										        data-match-type="<?php echo esc_attr( $rule['match_type'] ); ?>"
										        data-status="<?php echo esc_attr( (string) $rule['status'] ); ?>"
										        data-enabled="<?php echo esc_attr( $rule['enabled'] ? '1' : '0' ); ?>">
											<?php esc_html_e( 'Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
										<button class="button button-small tsosk-redirect-toggle"
										        data-id="<?php echo esc_attr( $rule['id'] ); ?>"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>">
											<?php echo $rule['enabled'] ? esc_html__( 'Disable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Enable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
										<button class="button button-small button-link-delete tsosk-redirect-delete"
										        data-id="<?php echo esc_attr( $rule['id'] ); ?>"
										        data-nonce="<?php echo esc_attr( $nonce ); ?>">
											<?php esc_html_e( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get sanitized redirect rules.
	 *
	 * @return array<string, array>
	 */
	private function get_rules(): array {
		$rules = get_option( self::OPTION, array() );
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$out = array();
		foreach ( $rules as $id => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$id = sanitize_key( $rule['id'] ?? $id );
			if ( '' === $id ) {
				continue;
			}

			$status = absint( $rule['status'] ?? 301 );
			if ( ! in_array( $status, $this->allowed_statuses(), true ) ) {
				$status = 301;
			}

			$raw_match_type = (string) ( $rule['match_type'] ?? 'exact' );
			$match_type = in_array( $raw_match_type, array( 'exact', 'wildcard', 'regex' ), true ) ? $raw_match_type : 'exact';

			$out[ $id ] = array(
				'id'       => $id,
				'source'   => 'regex' === $match_type ? sanitize_text_field( (string) ( $rule['source'] ?? '' ) ) : $this->normalize_path( (string) ( $rule['source'] ?? '' ) ),
				'target'   => sanitize_text_field( (string) ( $rule['target'] ?? '' ) ),
				'match_type' => $match_type,
				'status'   => $status,
				'enabled'  => ! empty( $rule['enabled'] ),
				'hits'     => absint( $rule['hits'] ?? 0 ),
				'last_hit' => absint( $rule['last_hit'] ?? 0 ),
				'created'  => absint( $rule['created'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Review redirects for common problems.
	 *
	 * @param array $rules Redirect rules.
	 * @return array<int, array{type:string,message:string}>
	 */
	private function review_rules( array $rules ): array {
		$reviews = array();
		$sources = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				$reviews[] = array(
					'type'    => 'info',
					'message' => sprintf(
						/* translators: %s: source path */
						__( 'Redirect for %s is disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$rule['source']
					),
				);
			}

			$source_key = $rule['match_type'] . ':' . $rule['source'];
			if ( isset( $sources[ $source_key ] ) ) {
				$reviews[] = array(
					'type'    => 'warn',
					'message' => sprintf(
						/* translators: %s: source path */
						__( 'Duplicate source path detected: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$rule['source']
					),
				);
			}
			$sources[ $source_key ] = true;

			if ( in_array( absint( $rule['status'] ), array( 410, 451 ), true ) ) {
				continue;
			}

			$target_url = $this->target_to_url( $rule['target'] );
			if ( '' === $target_url ) {
				$reviews[] = array(
					'type'    => 'warn',
					'message' => sprintf(
						/* translators: %s: source path */
						__( 'Redirect for %s has an invalid or unsafe target.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$rule['source']
					),
				);
				continue;
			}

			if ( 'regex' !== $rule['match_type'] && $this->is_loop( $rule['source'], $target_url ) ) {
				$reviews[] = array(
					'type'    => 'warn',
					'message' => sprintf(
						/* translators: %s: source path */
						__( 'Redirect for %s points back to itself.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$rule['source']
					),
				);
			}

			if ( 'regex' !== $rule['match_type'] && url_to_postid( home_url( $rule['source'] ) ) ) {
				$reviews[] = array(
					'type'    => 'info',
					'message' => sprintf(
						/* translators: %s: source path */
						__( 'Source path %s appears to resolve to existing WordPress content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$rule['source']
					),
				);
			}
		}

		return $reviews;
	}

	/**
	 * Return allowed redirect status codes.
	 *
	 * @return array<int>
	 */
	private function allowed_statuses(): array {
		return array( 301, 302, 307, 308, 410, 451 );
	}

	/**
	 * Resolve a redirect target for the current request.
	 *
	 * @param array  $rule         Redirect rule.
	 * @param string $request_path Request path.
	 * @return string|false
	 */
	private function resolve_target( array $rule, string $request_path ) {
		$match_type = $rule['match_type'] ?? 'exact';
		$matches = array();

		if ( 'exact' === $match_type && ! $this->paths_match( $rule['source'], $request_path ) ) {
			return false;
		}

		if ( 'wildcard' === $match_type ) {
			$pattern = '#^' . str_replace( '\*', '([^/]+)', preg_quote( $this->path_key( $rule['source'] ), '#' ) ) . '$#';
			if ( ! preg_match( $pattern, $this->path_key( $request_path ), $matches ) ) {
				return false;
			}
		}

		if ( 'regex' === $match_type ) {
			$pattern = '#' . str_replace( '#', '\#', $rule['source'] ) . '#';
			if ( false === @preg_match( $pattern, $request_path, $matches ) || ! preg_match( $pattern, $request_path, $matches ) ) {
				return false;
			}
		}

		$target = (string) $rule['target'];
		foreach ( $matches as $index => $match ) {
			if ( 0 === $index ) {
				continue;
			}
			$target = str_replace( '$' . $index, rawurlencode( $match ), $target );
		}

		return $target;
	}

	/**
	 * Store a recent 404 hit, merging repeats by path.
	 *
	 * @param string $request_uri  Raw request URI.
	 * @param string $request_path Normalized path.
	 */
	private function record_404( string $request_uri, string $request_path ): void {
		$logs = $this->get_404_log();
		$key = md5( $request_path );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( isset( $logs[ $key ] ) ) {
			$logs[ $key ]['hits'] = absint( $logs[ $key ]['hits'] ) + 1;
			$logs[ $key ]['last_hit'] = time();
			$logs[ $key ]['referrer'] = $referrer;
			$logs[ $key ]['user_agent'] = $user_agent;
		} else {
			$logs[ $key ] = array(
				'path'       => $request_path,
				'uri'        => sanitize_text_field( $request_uri ),
				'hits'       => 1,
				'first_hit'  => time(),
				'last_hit'   => time(),
				'referrer'   => $referrer,
				'user_agent' => $user_agent,
			);
		}

		uasort(
			$logs,
			static function ( array $a, array $b ): int {
				return absint( $b['last_hit'] ) <=> absint( $a['last_hit'] );
			}
		);

		$logs = array_slice( $logs, 0, 200, true );
		update_option( self::LOG_OPTION, $logs, false );

		$this->maybe_send_404_alert( $logs );
	}

	/**
	 * Get the recent 404 log.
	 *
	 * @return array<string, array>
	 */
	private function get_404_log(): array {
		$logs = get_option( self::LOG_OPTION, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Send a threshold alert if configured.
	 *
	 * @param array $logs Current 404 logs.
	 */
	private function maybe_send_404_alert( array $logs ): void {
		$settings = get_option( 'tsosk_alert_settings', array() );
		if ( empty( $settings['enabled'] ) || empty( $settings['email'] ) ) {
			return;
		}

		$threshold = max( 1, absint( $settings['not_found_threshold'] ?? 25 ) );
		$since = time() - HOUR_IN_SECONDS;
		$count = 0;
		foreach ( $logs as $item ) {
			if ( absint( $item['last_hit'] ) >= $since ) {
				$count += absint( $item['hits'] );
			}
		}

		$last_sent = absint( get_option( 'tsosk_404_alert_last_sent', 0 ) );
		if ( $count < $threshold || $last_sent > $since ) {
			return;
		}

		update_option( 'tsosk_404_alert_last_sent', time(), false );
		wp_mail(
			sanitize_email( $settings['email'] ),
			sprintf(
				/* translators: %s: site name */
				__( '[%s] 404 alert threshold reached', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: %d: number of 404 hits */
				__( 'The site recorded %d recent 404 hits. Review the Redirects tab to create redirects.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			)
		);
	}

	/**
	 * Build a browsable URL for a redirect source or target value.
	 *
	 * @param string $value      Stored path or URL.
	 * @param string $match_type Rule match type.
	 * @param bool   $is_source  Whether the value is a source path.
	 * @return string
	 */
	private function rule_value_to_url( string $value, string $match_type, bool $is_source = false ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( $is_source && 'regex' === $match_type ) {
			return '';
		}

		if ( preg_match( '/\$\d/', $value ) ) {
			return '';
		}

		if ( $is_source && 'wildcard' === $match_type && str_contains( $value, '*' ) ) {
			$value = (string) preg_replace( '/\*+.*$/', '', $value );
			if ( '' === $value || '/' === $value ) {
				return '';
			}
		}

		return $this->target_to_url( $value );
	}

	/**
	 * Render a URL/path table cell as a link when a safe URL is available.
	 *
	 * @param string $display Visible path or URL.
	 * @param string $url     Browsable URL.
	 * @return string
	 */
	private function render_rule_url_cell( string $display, string $url ): string {
		if ( '' === $url ) {
			return '<span class="tsosk-redirect-url-text">' . esc_html( $display ) . '</span>';
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="tsosk-redirect-url-link" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $display ),
			esc_html( $display )
		);
	}

	/**
	 * Human label for a match type.
	 *
	 * @param string $type Match type.
	 * @return string
	 */
	private function match_type_label( string $type ): string {
		if ( 'wildcard' === $type ) {
			return __( 'Wildcard', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( 'regex' === $type ) {
			return __( 'Regex', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'Exact', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Normalize a site-relative path.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path || preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $path ) ) {
			return '';
		}

		$parts = wp_parse_url( $path );
		if ( false === $parts ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
		return sanitize_text_field( $path );
	}

	/**
	 * Convert a stored target into a safe URL.
	 *
	 * @param string $target Raw target.
	 * @return string
	 */
	private function target_to_url( string $target ): string {
		$target = trim( $target );
		if ( '' === $target ) {
			return '';
		}

		if ( 0 !== strpos( $target, '/' ) && ! preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $target ) ) {
			$target = '/' . ltrim( $target, '/' );
		}

		if ( 0 === strpos( $target, '/' ) ) {
			$target = home_url( $target );
		}

		$target = esc_url_raw( $target );
		if ( '' === $target ) {
			return '';
		}

		$validated = wp_validate_redirect( $target, '' );
		return '' === $validated ? '' : $validated;
	}

	/**
	 * Compare two site paths, ignoring a trailing slash.
	 *
	 * @param string $source Source path.
	 * @param string $request Request path.
	 * @return bool
	 */
	private function paths_match( string $source, string $request ): bool {
		return $this->path_key( $source ) === $this->path_key( $request );
	}

	/**
	 * Detect a self-redirect loop.
	 *
	 * @param string $source     Source path.
	 * @param string $target_url Target URL.
	 * @return bool
	 */
	private function is_loop( string $source, string $target_url ): bool {
		$target_path = (string) wp_parse_url( $target_url, PHP_URL_PATH );
		return $this->path_key( $source ) === $this->path_key( $target_path );
	}

	/**
	 * Build a normalized comparison key for a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function path_key( string $path ): string {
		$path = $this->normalize_path( $path );
		if ( '/' === $path ) {
			return '/';
		}
		return untrailingslashit( $path );
	}

	/**
	 * Generate a prefixed rule ID.
	 *
	 * @return string
	 */
	private function new_rule_id(): string {
		return sanitize_key( 'tsosk_' . wp_generate_password( 12, false, false ) );
	}

	/**
	 * Convert review type to badge class.
	 *
	 * @param string $type Review type.
	 * @return string
	 */
	private function badge_class( string $type ): string {
		if ( 'warn' === $type ) {
			return 'tsosk-badge-warn';
		}
		if ( 'ok' === $type ) {
			return 'tsosk-badge-ok';
		}
		return 'tsosk-badge-info';
	}
}
