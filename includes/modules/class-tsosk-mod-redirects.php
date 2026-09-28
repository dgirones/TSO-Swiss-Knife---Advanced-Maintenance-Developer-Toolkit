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
		add_action( 'wp_ajax_tsosk_redirect_delete_selected', array( $this, 'ajax_delete_selected' ) );
		add_action( 'wp_ajax_tsosk_redirect_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_tsosk_404_clear', array( $this, 'ajax_clear_404_log' ) );
		add_action( 'wp_ajax_tsosk_404_delete_selected', array( $this, 'ajax_delete_selected_404' ) );
		add_action( 'wp_ajax_tsosk_404_bulk_rules', array( $this, 'ajax_bulk_rules' ) );
		add_action( 'wp_ajax_tsosk_404_prefix_gone', array( $this, 'ajax_prefix_gone' ) );
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

		$request_uri  = TSOSK_Support::get_request_uri_for_display();
		$request_path = $this->normalize_path( TSOSK_Support::get_request_path_decoded() );
		$request_path = $this->strip_home_subdirectory( $request_path );
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
				$this->bump_rule_hits( $id, $rules );

				status_header( absint( $rule['status'] ) );
				nocache_headers();
				wp_die(
					esc_html( 410 === absint( $rule['status'] ) ? __( 'This content is no longer available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'This content is unavailable for legal reasons.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					esc_html( 410 === absint( $rule['status'] ) ? __( 'Gone', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Unavailable For Legal Reasons', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
					array( 'response' => absint( $rule['status'] ) )
				);
			}

			$target_url = $this->target_to_url( $target );
			// Compare against the concrete path that was actually requested
			// (and matched this rule), not the raw stored pattern: a wildcard
			// or regex source (e.g. "/blog/*") never textually equals a
			// resolved path, so comparing the pattern itself would silently
			// never catch a wildcard/regex self-redirect loop.
			if ( '' === $target_url || $this->is_loop( $request_path, $target_url ) ) {
				continue;
			}

			$this->bump_rule_hits( $id, $rules );

			$this->do_redirect( $target_url, absint( $rule['status'] ) );
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
		$validated = $this->sanitize_rule_for_storage(
			array(
				'id'         => $id,
				'source'     => $source,
				'target'     => $target,
				'match_type' => $match_type,
				'status'     => $status,
				'enabled'    => $enabled,
			)
		);
		if ( is_wp_error( $validated ) ) {
			wp_send_json_error( $validated->get_error_message() );
		}

		$source     = $validated['source'];
		$target     = $validated['target'];
		$match_type = $validated['match_type'];
		$status     = $validated['status'];
		$enabled    = $validated['enabled'];
		$target_url = $validated['target_url'];

		if ( '' !== $target_url && 'regex' !== $match_type ) {
			// target_url was already built by substituting any "$1"/"$2"/...
			// in the target with the literal sample "x" (see
			// sanitize_rule_for_storage()); mirror that for a wildcard source
			// so "/blog/*" becomes the comparable sample path "/blog/x" —
			// otherwise the literal "*" in the pattern could never match a
			// resolved path and this check would silently never fire for any
			// wildcard rule, including an exact passthrough loop.
			$sample_source = 'wildcard' === $match_type ? str_replace( '*', 'x', $source ) : $source;
			if ( $this->is_loop( $sample_source, $target_url ) ) {
				wp_send_json_error( __( 'The target points back to the source and would create a loop.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$rules = $this->get_rules();

		// Reject an exact duplicate: same match type + same source already saved under a
		// different rule. Editing the rule itself (same id) is not a duplicate of itself.
		foreach ( $rules as $existing_id => $existing_rule ) {
			if ( $existing_id === $id ) {
				continue;
			}
			if ( ( $existing_rule['match_type'] ?? 'exact' ) === $match_type && ( $existing_rule['source'] ?? '' ) === $source ) {
				wp_send_json_error(
					array(
						'code'    => 'duplicate',
						'message' => sprintf(
							/* translators: %s: source path */
							__( 'A redirect for %s already exists. Edit that rule instead of creating a duplicate.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$source
						),
					)
				);
			}
		}

		if ( '' === $id || ! isset( $rules[ $id ] ) ) {
			$id = $this->new_rule_id();
		}

		$created = isset( $rules[ $id ]['created'] ) ? absint( $rules[ $id ]['created'] ) : time();
		$hits    = isset( $rules[ $id ]['hits'] ) ? absint( $rules[ $id ]['hits'] ) : 0;
		$last_hit = isset( $rules[ $id ]['last_hit'] ) ? absint( $rules[ $id ]['last_hit'] ) : 0;

		$rules[ $id ] = array(
			'id'         => $id,
			'source'     => $source,
			'target'     => $target,
			'match_type' => $match_type,
			'status'     => $status,
			'enabled'    => $enabled,
			'hits'       => $hits,
			'last_hit'   => $last_hit,
			'created'    => $created,
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
	 * AJAX: delete several redirect rules at once.
	 */
	public function ajax_delete_selected(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$raw_ids = ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) )
			? array_map( 'sanitize_key', wp_unslash( $_POST['ids'] ) )
			: array();
		$ids     = array_slice( array_unique( array_filter( $raw_ids ) ), 0, 1000 );
		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No redirects selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$rules   = $this->get_rules();
		$before  = count( $rules );
		foreach ( $ids as $id ) {
			unset( $rules[ $id ] );
		}
		$removed = $before - count( $rules );
		if ( $removed > 0 ) {
			update_option( self::OPTION, $rules, false );
		}

		TSOSK_Activity_Log::log(
			'redirects',
			'delete',
			sprintf(
				/* translators: %d: number of deleted redirect rules */
				_n( '%d redirect deleted.', '%d redirects deleted.', $removed, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$removed
			)
		);
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of deleted redirect rules */
					_n( '%d redirect deleted.', '%d redirects deleted.', $removed, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$removed
				),
				'removed' => $removed,
			)
		);
	}

	/**
	 * Whether a first path segment belongs to WordPress itself and must never get a prefix rule.
	 *
	 * @param string $segment First path segment, lower-cased or not.
	 * @return bool
	 */
	private function is_reserved_prefix( string $segment ): bool {
		return in_array( strtolower( $segment ), array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json' ), true );
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
	 * AJAX: delete selected 404 log entries by path, without clearing the whole log.
	 */
	public function ajax_delete_selected_404(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$raw_paths = ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['paths'] ) )
			: array();
		$paths     = array();
		foreach ( $raw_paths as $raw_path ) {
			$path = (string) $raw_path;
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		// The log itself never holds more than 200 rows (see record_404()), so no
		// legitimate selection from the table can exceed that.
		$paths = array_slice( array_unique( $paths ), 0, 200 );

		if ( empty( $paths ) ) {
			wp_send_json_error( __( 'No URLs selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$logs   = $this->get_404_log();
		$before = count( $logs );
		foreach ( $paths as $path ) {
			unset( $logs[ md5( $path ) ] );
		}
		$removed = $before - count( $logs );

		update_option( self::LOG_OPTION, $logs, false );
		TSOSK_Activity_Log::log(
			'redirects',
			'delete',
			sprintf(
				/* translators: %d: number of removed 404 log entries */
				_n( '%d 404 log entry removed.', '%d 404 log entries removed.', $removed, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$removed
			)
		);
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of removed 404 log entries */
					_n( '%d entry removed.', '%d entries removed.', $removed, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$removed
				),
				'removed' => $removed,
			)
		);
	}

	/**
	 * Whether an enabled rule already answers this request path.
	 *
	 * @param array<string,array> $rules Redirect rules.
	 * @param string              $path  Request path as stored in the 404 log.
	 * @return bool
	 */
	private function is_path_covered( array $rules, string $path ): bool {
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['enabled'] ) && $this->rule_source_matches_path( $rule, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate and append a new enabled rule to the given map (not persisted here).
	 *
	 * @param array<string,array> $rules      Rules map, by reference.
	 * @param string              $source     Source path or regex.
	 * @param string              $target     Target (empty for 410).
	 * @param string              $match_type exact|wildcard|regex.
	 * @param int                 $status     HTTP status.
	 * @return string|WP_Error New rule id, or an error (code "duplicate" when the source already exists).
	 */
	private function append_rule( array &$rules, string $source, string $target, string $match_type, int $status ) {
		$validated = $this->sanitize_rule_for_storage(
			array(
				'id'         => '',
				'source'     => $source,
				'target'     => $target,
				'match_type' => $match_type,
				'status'     => $status,
				'enabled'    => true,
			)
		);
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		foreach ( $rules as $existing_rule ) {
			if ( ( $existing_rule['match_type'] ?? 'exact' ) === $validated['match_type'] && ( $existing_rule['source'] ?? '' ) === $validated['source'] ) {
				return new WP_Error(
					'duplicate',
					sprintf(
						/* translators: %s: source path */
						__( 'A redirect for %s already exists. Edit that rule instead of creating a duplicate.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$validated['source']
					)
				);
			}
		}

		if ( '' !== $validated['target_url'] && 'regex' !== $validated['match_type'] ) {
			$sample_source = 'wildcard' === $validated['match_type'] ? str_replace( '*', 'x', $validated['source'] ) : $validated['source'];
			if ( $this->is_loop( $sample_source, $validated['target_url'] ) ) {
				return new WP_Error( 'loop', __( 'The target points back to the source and would create a loop.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		$id           = $this->new_rule_id();
		$rules[ $id ] = array(
			'id'         => $id,
			'source'     => $validated['source'],
			'target'     => $validated['target'],
			'match_type' => $validated['match_type'],
			'status'     => $validated['status'],
			'enabled'    => true,
			'hits'       => 0,
			'last_hit'   => 0,
			'created'    => time(),
		);
		return $id;
	}

	/**
	 * AJAX: create one exact rule per selected 404 path (410, or 301 to a single target).
	 *
	 * URLs already covered by an enabled rule, or that already have a rule with the
	 * same source, are skipped instead of aborting the whole batch.
	 */
	public function ajax_bulk_rules(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'gone', 'redirect' ), true ) ) {
			wp_send_json_error( __( 'Invalid request.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$status = 'gone' === $mode ? 410 : 301;
		$target = ( 'redirect' === $mode && isset( $_POST['target'] ) ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';

		$raw_paths = ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['paths'] ) )
			: array();
		$paths     = array();
		foreach ( $raw_paths as $raw_path ) {
			$path = (string) $raw_path;
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		// The 404 log never holds more than 200 rows, so no legitimate selection exceeds that.
		$paths = array_slice( array_unique( $paths ), 0, 200 );
		if ( empty( $paths ) ) {
			wp_send_json_error( __( 'No URLs selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$rules       = $this->get_rules();
		$created     = 0;
		$skipped     = 0;
		$failed      = 0;
		$first_error = '';
		foreach ( $paths as $path ) {
			if ( $this->is_path_covered( $rules, $path ) ) {
				++$skipped;
				continue;
			}
			$result = $this->append_rule( $rules, $path, $target, 'exact', $status );
			if ( is_wp_error( $result ) ) {
				if ( 'duplicate' === $result->get_error_code() ) {
					++$skipped;
				} else {
					++$failed;
					if ( '' === $first_error ) {
						$first_error = $result->get_error_message();
					}
				}
				continue;
			}
			++$created;
		}

		if ( 0 === $created && $failed > 0 ) {
			wp_send_json_error( $first_error );
		}

		if ( $created > 0 ) {
			update_option( self::OPTION, $rules, false );
			TSOSK_Activity_Log::log(
				'redirects',
				'save',
				sprintf(
					/* translators: 1: number of rules created, 2: HTTP status code */
					__( 'Bulk redirect rules created from the 404 monitor: %1$d (%2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$created,
					$status
				)
			);
		}

		wp_send_json_success(
			array(
				'created' => $created,
				'skipped' => $skipped,
				'failed'  => $failed,
				'message' => sprintf(
					/* translators: 1: number of rules created, 2: number of URLs skipped because a rule already covers them, 3: number of URLs that could not be processed */
					__( 'Created %1$d rule(s). Skipped %2$d already covered. Failed: %3$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$created,
					$skipped,
					$failed
				),
			)
		);
	}

	/**
	 * AJAX: create a single 410 rule that answers Gone for everything under a first path segment.
	 */
	public function ajax_prefix_gone(): void {
		check_ajax_referer( 'tsosk_redirects_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$prefix = isset( $_POST['prefix'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['prefix'] ) ), '/' ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $prefix ) || $this->is_reserved_prefix( $prefix ) ) {
			wp_send_json_error( __( 'Invalid prefix.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// A real page living at this slug means the prefix is not dead content.
		if ( get_page_by_path( $prefix, OBJECT, 'page' ) instanceof WP_Post ) {
			wp_send_json_error( __( 'A page with this slug exists, so a 410 for the whole prefix could hide real content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$rules  = $this->get_rules();
		$source = '^/' . preg_quote( $prefix, '#' ) . '(/|$)';
		$result = $this->append_rule( $rules, $source, '', 'regex', 410 );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		update_option( self::OPTION, $rules, false );
		TSOSK_Activity_Log::log(
			'redirects',
			'save',
			sprintf(
				/* translators: %s: URL prefix such as /en/ */
				__( '410 rule created for everything under %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'/' . $prefix . '/'
			),
			array( 'source' => $source )
		);
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: URL prefix such as /en/ */
					__( '410 rule created for everything under %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'/' . $prefix . '/'
				),
			)
		);
	}

	/**
	 * Group uncovered 404 paths by first path segment (needs 2+ URLs under it).
	 *
	 * @param array<string,array> $log   404 log rows.
	 * @param array<string,array> $rules Redirect rules.
	 * @return array<int,array{prefix:string,urls:int,hits:int,examples:array<int,string>}>
	 */
	private function get_prefix_suggestions( array $log, array $rules ): array {
		$groups = array();
		foreach ( $log as $item ) {
			$path = (string) ( $item['path'] ?? '' );
			if ( '' === $path || $this->is_path_covered( $rules, $path ) ) {
				continue;
			}
			$trimmed = trim( $path, '/' );
			if ( false === strpos( $trimmed, '/' ) ) {
				continue;
			}
			$segment = (string) strstr( $trimmed, '/', true );
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $segment ) || $this->is_reserved_prefix( $segment ) ) {
				continue;
			}
			if ( ! isset( $groups[ $segment ] ) ) {
				$groups[ $segment ] = array(
					'prefix'   => $segment,
					'urls'     => 0,
					'hits'     => 0,
					'examples' => array(),
				);
			}
			++$groups[ $segment ]['urls'];
			$groups[ $segment ]['hits'] += absint( $item['hits'] ?? 0 );
			if ( count( $groups[ $segment ]['examples'] ) < 3 ) {
				$groups[ $segment ]['examples'][] = $path;
			}
		}

		$groups = array_filter(
			$groups,
			static function ( array $group ): bool {
				return $group['urls'] >= 2;
			}
		);
		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return $b['urls'] <=> $a['urls'];
			}
		);
		return array_slice( $groups, 0, 8 );
	}

	/**
	 * Render the Redirects tab.
	 */
	public function render(): void {
		$nonce   = wp_create_nonce( 'tsosk_redirects_nonce' );
		$rules   = $this->get_rules();
		$reviews = $this->review_rules( $rules );
		$not_found_log = $this->get_404_log();
		$suggestions   = $this->get_prefix_suggestions( $not_found_log, $rules );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Create and review safe WordPress-level redirects. Rules are stored in a prefixed option and are applied before the theme renders.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<details class="tsosk-guide-card tsosk-guide-collapse">
			<summary class="tsosk-guide-title"><?php esc_html_e( 'Why use redirects?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="description tsosk-desc-flush">
				<?php esc_html_e( 'Redirects tell browsers and search engines that a URL has moved. They preserve SEO value when you rename a post, merge content, or change permalink structure. A 301 permanent redirect passes most ranking signals to the new URL; 302/307 are for temporary moves. Use 410 when content is permanently removed. Google follows redirects when crawling — broken old URLs without redirects become 404s and may lose traffic.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</details>

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
			<button class="button button-secondary" id="tsosk-redirect-skip" type="button" hidden>
				<?php esc_html_e( 'Skip this one', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button class="button button-secondary" id="tsosk-redirect-cancel-queue" type="button" hidden>
				<?php esc_html_e( 'Cancel queue', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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

		<div class="tsosk-card" id="tsosk-404-monitor">
			<h3><?php esc_html_e( '404 Monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Recent 404 visits are captured so you can create redirects from missing URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( empty( $not_found_log ) ) : ?>
				<p><?php esc_html_e( 'No 404 visits recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<button class="button button-secondary" id="tsosk-404-clear" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear 404 Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button button-secondary" id="tsosk-404-prefill-selected" type="button">
					<?php esc_html_e( 'Prefill redirect form (selected)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<button class="button button-secondary" id="tsosk-404-delete-selected" type="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-404-msg"></span>
				<div class="tsosk-404-bulk-bar">
					<button class="button button-secondary" id="tsosk-404-bulk-gone" type="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Mark selected as 410 (Gone)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<input type="text" id="tsosk-404-bulk-target" class="regular-text" placeholder="/new-page/" aria-label="<?php esc_attr_e( 'Target URL or Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					<button class="button button-secondary" id="tsosk-404-bulk-redirect" type="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Redirect selected to this target (301)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
				</div>
				<?php if ( ! empty( $suggestions ) ) : ?>
					<div class="tsosk-404-suggestions">
						<h4><?php esc_html_e( 'Suggested patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Missing URLs that share the same first path segment. One rule can answer 410 (Gone) for the whole prefix, so you do not need to add them one by one.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<div class="tsosk-table-wrap">
							<table class="widefat tsosk-table" id="tsosk-404-suggestions-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Prefix', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
										<th><?php esc_html_e( 'Missing URLs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
										<th><?php esc_html_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
										<th><?php esc_html_e( 'Examples', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
										<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $suggestions as $suggestion ) : ?>
										<tr>
											<td class="tsosk-code"><?php echo esc_html( '/' . $suggestion['prefix'] . '/' ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $suggestion['urls'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $suggestion['hits'] ) ); ?></td>
											<td class="tsosk-code"><?php echo esc_html( implode( ', ', $suggestion['examples'] ) ); ?></td>
											<td>
												<button class="button button-small tsosk-404-prefix-gone" type="button" data-prefix="<?php echo esc_attr( $suggestion['prefix'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
													<?php esc_html_e( 'Create 410 rule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
												</button>
												<button class="button button-small tsosk-404-prefix-prefill" type="button" data-prefix="<?php echo esc_attr( $suggestion['prefix'] ); ?>">
													<?php esc_html_e( 'Prefill form', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>
				<p class="description tsosk-desc-spaced">
					<?php esc_html_e( 'Visits counts how many times each missing URL was requested. Referrer keeps the last known previous page (HTTP Referer). If none was ever sent, Direct / unknown is shown, plus the last User-Agent when available (direct visits, bots and bookmarks often send none). IP shows the last known requester address; Possible bot is flagged when the same IP requested several different missing URLs within about a minute (User-Agent strings are easily faked, so this is based on request behavior instead).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<div class="tsosk-table-wrap tsosk-404-table-wrap tsosk-404-table-wrap-spaced">
					<table class="widefat tsosk-table" id="tsosk-404-table">
						<thead>
							<tr>
								<th class="tsosk-404-col-select">
									<label class="screen-reader-text" for="tsosk-404-select-all"><?php esc_html_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
									<input type="checkbox" id="tsosk-404-select-all" aria-label="<?php esc_attr_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								</th>
								<th class="tsosk-404-col-url"><?php esc_html_e( 'URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-404-col-visits"><?php esc_html_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-404-col-date"><?php esc_html_e( 'Last visit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-404-col-ref"><?php esc_html_e( 'Referrer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-404-col-ip"><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th class="tsosk-404-col-action"><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $not_found_log as $item ) : ?>
								<?php
								$path_url        = $this->rule_value_to_url( (string) $item['path'], 'exact', true );
								$already_covered = $this->is_path_covered( $rules, (string) $item['path'] );
								?>
							<tr>
								<td class="tsosk-404-col-select" data-label="<?php esc_attr_e( 'Select', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<input type="checkbox" class="tsosk-404-select" value="1" data-source="<?php echo esc_attr( $item['path'] ); ?>"<?php if ( $already_covered ) : ?> data-covered="1"<?php endif; ?> aria-label="<?php echo esc_attr( $item['path'] ); ?>">
								</td>
								<td class="tsosk-code tsosk-redirect-url-col" data-label="<?php esc_attr_e( 'URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<?php echo wp_kses_post( $this->render_rule_url_cell( (string) $item['path'], $path_url ) ); ?>
									<?php if ( $already_covered ) : ?>
										<span class="tsosk-badge tsosk-badge-ok" title="<?php esc_attr_e( 'An active redirect rule already matches this exact path. If it still shows up here, the rule is not actually catching this request — check its match type and target.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php esc_html_e( 'Already covered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="tsosk-404-col-visits" data-label="<?php esc_attr_e( 'Visits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( number_format_i18n( absint( $item['hits'] ) ) ); ?></td>
								<td class="tsosk-404-col-date" data-label="<?php esc_attr_e( 'Last visit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $item['last_hit'] ) ) ); ?></td>
								<td class="tsosk-redirect-url-col tsosk-404-col-ref" data-label="<?php esc_attr_e( 'Referrer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<?php
									echo wp_kses(
										$this->render_404_referrer_cell( $item ),
										array(
											'a'    => array(
												'href'   => true,
												'target' => true,
												'rel'    => true,
												'class'  => true,
												'title'  => true,
											),
											'span' => array(
												'class' => true,
												'title' => true,
											),
											'br'   => array(),
										)
									);
									?>
								</td>
								<td class="tsosk-404-col-ip" data-label="<?php esc_attr_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
									<?php
									echo wp_kses(
										$this->render_404_ip_cell( $item ),
										array(
											'span' => array(
												'class' => true,
												'title' => true,
											),
										)
									);
									?>
								</td>
								<td class="tsosk-404-col-action" data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
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
				<div class="tsosk-redirect-bulk-bar">
					<button class="button button-secondary" id="tsosk-redirect-delete-selected" type="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-redirect-bulk-msg"></span>
				</div>
				<div class="tsosk-table-wrap tsosk-redirects-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-redirects-table">
						<colgroup>
							<col class="tsosk-redirect-col-select">
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
								<th class="tsosk-redirect-col-select">
									<label class="screen-reader-text" for="tsosk-redirect-select-all"><?php esc_html_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
									<input type="checkbox" id="tsosk-redirect-select-all">
								</th>
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
									<td class="tsosk-redirect-col-select" data-label="<?php esc_attr_e( 'Select', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
										<input type="checkbox" class="tsosk-redirect-select" data-id="<?php echo esc_attr( $rule['id'] ); ?>" aria-label="<?php echo esc_attr( (string) $rule['source'] ); ?>">
									</td>
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

			$source = 'regex' === $match_type
				? sanitize_text_field( (string) ( $rule['source'] ?? '' ) )
				: $this->normalize_path( (string) ( $rule['source'] ?? '' ) );
			if ( '' === $source ) {
				continue;
			}
			if ( 'regex' === $match_type && ! $this->is_safe_regex_source( $source ) ) {
				continue;
			}

			$out[ $id ] = array(
				'id'         => $id,
				'source'     => $source,
				'target'     => sanitize_text_field( (string) ( $rule['target'] ?? '' ) ),
				'match_type' => $match_type,
				'status'     => $status,
				'enabled'    => ! empty( $rule['enabled'] ),
				'hits'       => absint( $rule['hits'] ?? 0 ),
				'last_hit'   => absint( $rule['last_hit'] ?? 0 ),
				'created'    => absint( $rule['created'] ?? 0 ),
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

			// Substitute "$1"/"$2"/... with the same literal sample ("x") used
			// when the rule was saved (sanitize_rule_for_storage()), so a
			// wildcard target like "/blog/$1" resolves to a comparable
			// concrete-looking path instead of the literal placeholder text.
			$target_url = $this->target_to_url( preg_replace( '/\$\d+/', 'x', (string) $rule['target'] ) );
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

			// Mirror the same "*" → "x" sample substitution used at save time
			// (ajax_save()) so a wildcard passthrough loop is still detected
			// here — the literal "*" in a wildcard source never equals a
			// resolved path, so comparing the raw pattern would silently miss it.
			$loop_check_source = 'wildcard' === $rule['match_type'] ? str_replace( '*', 'x', (string) $rule['source'] ) : $rule['source'];
			if ( 'regex' !== $rule['match_type'] && $this->is_loop( $loop_check_source, $target_url ) ) {
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

		// Flag rules that can never actually run because an earlier, broader wildcard/regex
		// rule already matches the same source path (rules are evaluated in this same order;
		// the first match wins — see maybe_redirect()). Two "exact" rules never overlap since
		// the duplicate-source check in ajax_save() already keeps their sources unique.
		$active     = array_filter(
			$rules,
			static function ( array $rule ): bool {
				return ! empty( $rule['enabled'] );
			}
		);
		$active_ids = array_keys( $active );
		foreach ( $active_ids as $i => $rule_id ) {
			$rule = $active[ $rule_id ];
			for ( $j = 0; $j < $i; $j++ ) {
				$other = $active[ $active_ids[ $j ] ];
				if ( 'exact' === $other['match_type'] ) {
					continue;
				}
				if ( $this->rule_source_matches_path( $other, (string) $rule['source'] ) ) {
					$reviews[] = array(
						'type'    => 'warn',
						'message' => sprintf(
							/* translators: 1: source path of the shadowed rule, 2: source pattern of the rule above it that already matches it */
							__( 'Redirect for %1$s is already covered by the %2$s rule above it and will never run.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$rule['source'],
							$other['source']
						),
					);
					break;
				}
			}
		}

		return $reviews;
	}

	/**
	 * Whether a rule's source pattern matches a given path, independent of its target.
	 * Used only to detect when one rule's source range already covers another rule's
	 * source path, so the later rule would never actually be reached (see review_rules()).
	 *
	 * @param array<string,mixed> $rule Redirect rule (needs 'match_type' and 'source').
	 * @param string              $path Path to test against the rule's source pattern.
	 * @return bool
	 */
	private function rule_source_matches_path( array $rule, string $path ): bool {
		$match_type = $rule['match_type'] ?? 'exact';
		$source     = (string) ( $rule['source'] ?? '' );

		if ( 'exact' === $match_type ) {
			return $this->paths_match( $source, $path );
		}

		if ( 'wildcard' === $match_type ) {
			$pattern = '#^' . str_replace( '\*', '([^/]+)', preg_quote( $this->path_key( $source ), '#' ) ) . '$#';
			return 1 === preg_match( $pattern, $this->path_key( $path ) );
		}

		if ( 'regex' === $match_type ) {
			if ( ! $this->is_safe_regex_source( $source ) ) {
				return false;
			}
			$pattern = '#' . str_replace( '#', '\#', $source ) . '#';
			return 1 === @preg_match( $pattern, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return false;
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
			if ( ! $this->is_safe_regex_source( (string) $rule['source'] ) ) {
				return false;
			}
			$pattern = '#' . str_replace( '#', '\#', $rule['source'] ) . '#';
			// Single match — @ suppresses compile warnings already validated at save time.
			if ( 1 !== @preg_match( $pattern, $request_path, $matches ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
		}

		$target = (string) $rule['target'];
		// Captures must not control the host of absolute redirect targets.
		if ( preg_match( '~^https?://([^/?#]+)~i', $target, $host_m ) && preg_match( '/\$\d+/', $host_m[1] ) ) {
			return false;
		}

		$expected_host = '';
		if ( preg_match( '#^https?://#i', $target ) ) {
			$template_url = $this->target_to_url( preg_replace( '/\$\d+/', 'x', $target ) );
			$expected_host = strtolower( (string) wp_parse_url( $template_url, PHP_URL_HOST ) );
		}

		foreach ( $matches as $index => $match ) {
			if ( 0 === $index ) {
				continue;
			}
			$target = str_replace( '$' . $index, rawurlencode( (string) $match ), $target );
		}

		if ( '' !== $expected_host ) {
			$final_url  = $this->target_to_url( $target );
			$final_host = strtolower( (string) wp_parse_url( $final_url, PHP_URL_HOST ) );
			if ( '' === $final_url || $final_host !== $expected_host ) {
				return false;
			}
		}

		return $target;
	}

	/**
	 * Increment redirect hit counters with write throttling.
	 *
	 * @param string              $id    Rule id.
	 * @param array<string,array> $rules Rules map (by reference for in-request accuracy).
	 */
	private function bump_rule_hits( string $id, array &$rules ): void {
		if ( ! isset( $rules[ $id ] ) ) {
			return;
		}

		$now = time();
		$rules[ $id ]['hits']     = absint( $rules[ $id ]['hits'] ) + 1;
		$rules[ $id ]['last_hit'] = $now;

		$gate_key  = 'tsosk_rd_hit_gate_' . $id;
		$delta_key = 'tsosk_rd_hit_delta_' . $id;
		if ( false !== get_transient( $gate_key ) ) {
			set_transient( $delta_key, absint( get_transient( $delta_key ) ) + 1, HOUR_IN_SECONDS );
			return;
		}

		set_transient( $gate_key, 1, 15 );
		$delta = absint( get_transient( $delta_key ) );
		delete_transient( $delta_key );

		// Re-read before write to reduce lost updates under concurrent hits.
		$fresh = $this->get_rules();
		if ( ! isset( $fresh[ $id ] ) ) {
			return;
		}
		$fresh[ $id ]['hits']     = absint( $fresh[ $id ]['hits'] ) + 1 + $delta;
		$fresh[ $id ]['last_hit'] = $now;
		$rules                    = $fresh;
		update_option( self::OPTION, $fresh, false );
	}

	/**
	 * Get the real client IP (REMOTE_ADDR only; proxy headers are not trusted by default).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Heuristic bot flag: the same IP hitting several distinct missing URLs in a short
	 * window looks like an automated scanner, whatever User-Agent it sends (UA strings
	 * are trivially spoofed, so string matching alone is not reliable).
	 *
	 * @param string $ip        Client IP for this request.
	 * @param string $path_key  Normalized 404 path being requested.
	 * @return bool
	 */
	private function is_bot_suspect_ip( string $ip, string $path_key ): bool {
		if ( '' === $ip ) {
			return false;
		}

		$window    = 60; // Seconds.
		$threshold = 5;  // Distinct 404 paths from the same IP within the window.

		$transient_key = 'tsosk_404_ip_act_' . md5( $ip );
		$activity      = get_transient( $transient_key );
		$activity      = is_array( $activity ) ? $activity : array();

		$now = time();
		// Prune entries outside the window; keys are the distinct paths seen.
		$activity = array_filter(
			$activity,
			static function ( $timestamp ) use ( $now, $window ) {
				return is_int( $timestamp ) && ( $now - $timestamp ) < $window;
			}
		);

		$activity[ md5( $path_key ) ] = $now;
		set_transient( $transient_key, $activity, $window );

		return count( $activity ) >= $threshold;
	}

	/**
	 * Store a recent 404 hit, merging repeats by path.
	 *
	 * @param string $request_uri  Raw request URI.
	 * @param string $request_path Normalized path.
	 */
	private function record_404( string $request_uri, string $request_path ): void {
		$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ip         = $this->get_client_ip();
		// Tracked on every request (cheap transient op) regardless of the option-write gate below,
		// so bursts are still caught even while writes are throttled.
		$bot_suspect = $this->is_bot_suspect_ip( $ip, $request_path );

		// Cap option writes under bot storms (sample ≈ every 5 seconds).
		if ( false !== get_transient( 'tsosk_404_write_gate' ) ) {
			// Still enrich an existing row with referrer/UA/IP when the write path is gated.
			if ( '' !== $referrer || '' !== $user_agent || '' !== $ip || $bot_suspect ) {
				$logs = $this->get_404_log();
				$key  = md5( $request_path );
				if ( isset( $logs[ $key ] ) ) {
					$changed = false;
					if ( '' !== $referrer && '' === (string) ( $logs[ $key ]['referrer'] ?? '' ) ) {
						$logs[ $key ]['referrer'] = $referrer;
						$changed                  = true;
					}
					if ( '' !== $user_agent ) {
						$logs[ $key ]['user_agent'] = $user_agent;
						$changed                    = true;
					}
					if ( '' !== $ip ) {
						$logs[ $key ]['ip'] = $ip;
						$changed            = true;
					}
					// Once flagged, keep flagged; never unflag a row that already looked like a bot.
					if ( $bot_suspect && empty( $logs[ $key ]['bot_suspect'] ) ) {
						$logs[ $key ]['bot_suspect'] = true;
						$changed                     = true;
					}
					if ( $changed ) {
						update_option( self::LOG_OPTION, $logs, false );
					}
				}
			}
			$delta_key = 'tsosk_404_hit_delta_' . md5( $request_path );
			set_transient( $delta_key, absint( get_transient( $delta_key ) ) + 1, HOUR_IN_SECONDS );
			// Keep hourly alert totals in sync even when option writes are deferred.
			$this->maybe_send_404_alert( $this->increment_404_hour_counter( 1 ) );
			return;
		}
		set_transient( 'tsosk_404_write_gate', 1, 5 );

		$logs = $this->get_404_log();
		$key  = md5( $request_path );
		$delta_key = 'tsosk_404_hit_delta_' . $key;
		$delta     = absint( get_transient( $delta_key ) );
		delete_transient( $delta_key );
		$hit_increment = 1 + $delta;

		if ( isset( $logs[ $key ] ) ) {
			$logs[ $key ]['hits']     = absint( $logs[ $key ]['hits'] ) + $hit_increment;
			$logs[ $key ]['last_hit'] = time();
			// Keep the last non-empty referrer; empty headers must not wipe a known source.
			if ( '' !== $referrer ) {
				$logs[ $key ]['referrer'] = $referrer;
			}
			if ( '' !== $user_agent ) {
				$logs[ $key ]['user_agent'] = $user_agent;
			}
			// Keep the most recent IP; unlike referrer/UA this is expected to change hit to hit.
			if ( '' !== $ip ) {
				$logs[ $key ]['ip'] = $ip;
			}
			// Once flagged, keep flagged; never unflag a row that already looked like a bot.
			if ( $bot_suspect ) {
				$logs[ $key ]['bot_suspect'] = true;
			}
		} else {
			$logs[ $key ] = array(
				'path'        => $request_path,
				'uri'         => sanitize_text_field( $request_uri ),
				'hits'        => $hit_increment,
				'first_hit'   => time(),
				'last_hit'    => time(),
				'referrer'    => $referrer,
				'user_agent'  => $user_agent,
				'ip'          => $ip,
				'bot_suspect' => $bot_suspect,
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

		$this->maybe_send_404_alert( $this->increment_404_hour_counter( $hit_increment ) );
	}

	/**
	 * Count a 404 hit for the current UTC clock hour.
	 *
	 * @param int $amount Hits to add (includes batched gated hits).
	 * @return int Hits in this hour after increment.
	 */
	private function increment_404_hour_counter( int $amount = 1 ): int {
		$amount = max( 1, $amount );
		$key    = 'tsosk_404_hits_' . gmdate( 'YmdH' );
		$count  = absint( get_transient( $key ) ) + $amount;
		set_transient( $key, $count, 2 * HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Get the recent 404 log.
	 *
	 * @return array<string, array>
	 */
	private function get_404_log(): array {
		$logs = get_option( self::LOG_OPTION, array() );
		// Keep only well-formed rows so one corrupted entry cannot break the monitor.
		return is_array( $logs ) ? array_filter( $logs, static function ( $row ) {
			return is_array( $row ) && isset( $row['path'] );
		} ) : array();
	}

	/**
	 * Send one email when hourly 404 visits reach the configured threshold.
	 *
	 * @param int $hour_hits Hits recorded in the current clock hour.
	 */
	private function maybe_send_404_alert( int $hour_hits ): void {
		$settings = get_option( 'tsosk_alert_settings', array() );
		if ( empty( $settings['enabled'] ) || empty( $settings['email'] ) ) {
			return;
		}

		$threshold = max( 1, absint( $settings['not_found_threshold'] ?? 25 ) );
		$last_sent = absint( get_option( 'tsosk_404_alert_last_sent', 0 ) );
		// Cooldown uses the same UTC clock-hour bucket as the hit counter.
		$same_clock_hour = ( gmdate( 'YmdH', $last_sent ) === gmdate( 'YmdH' ) );
		if ( $hour_hits < $threshold || ( $last_sent > 0 && $same_clock_hour ) ) {
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
				/* translators: 1: number of 404 hits this hour, 2: configured threshold */
				__( 'The site recorded %1$d Not Found (404) visits in the last hour (threshold: %2$d). Review the Redirects tab to create redirects for broken URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$hour_hits,
				$threshold
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
	 * Render the 404 monitor Referrer cell (last known URL, or Direct / unknown + UA hint).
	 *
	 * @param array<string, mixed> $item Log row.
	 * @return string
	 */
	private function render_404_referrer_cell( array $item ): string {
		$referrer   = isset( $item['referrer'] ) ? (string) $item['referrer'] : '';
		$user_agent = isset( $item['user_agent'] ) ? (string) $item['user_agent'] : '';

		if ( '' !== $referrer ) {
			return $this->render_rule_url_cell( $referrer, $referrer );
		}

		$html = '<span class="tsosk-redirect-url-text tsosk-404-direct">' . esc_html__( 'Direct / unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';

		if ( '' !== $user_agent ) {
			$short = ( function_exists( 'mb_strlen' ) && mb_strlen( $user_agent ) > 72 )
				? mb_substr( $user_agent, 0, 69 ) . '…'
				: ( ( strlen( $user_agent ) > 72 ) ? substr( $user_agent, 0, 69 ) . '…' : $user_agent );
			$html .= '<span class="tsosk-404-ua" title="' . esc_attr( $user_agent ) . '">' . esc_html( $short ) . '</span>';
		}

		return $html;
	}

	/**
	 * Render the 404 monitor IP cell: the last known IP, plus a "possible bot" badge
	 * when the same IP recently hit several distinct missing URLs in a short window.
	 *
	 * @param array<string, mixed> $item Log row.
	 * @return string
	 */
	private function render_404_ip_cell( array $item ): string {
		$ip          = isset( $item['ip'] ) ? (string) $item['ip'] : '';
		$bot_suspect = ! empty( $item['bot_suspect'] );

		$html = '' !== $ip
			? '<span class="tsosk-redirect-url-text tsosk-404-ip">' . esc_html( $ip ) . '</span>'
			: '<span class="tsosk-redirect-url-text tsosk-404-direct">' . esc_html__( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';

		if ( $bot_suspect ) {
			$html .= ' <span class="tsosk-badge tsosk-badge-warn" title="' . esc_attr__( 'Same IP requested several different missing URLs within a short window.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '">' . esc_html__( 'Possible bot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';
		}

		return $html;
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
	 * Strip the home subdirectory so rules use site-relative paths (e.g. /old-page/).
	 *
	 * @param string $path Normalized absolute request path.
	 * @return string
	 */
	private function strip_home_subdirectory( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = $this->normalize_path( $home_path );
		if ( '' === $home_path || '/' === $home_path ) {
			return $path;
		}
		$prefix = untrailingslashit( $home_path );

		// Defensive: strip the home subdirectory repeatedly, in case a rule was
		// hand-typed (or migrated) with it duplicated, e.g. /blog/blog/old-page/.
		$guard = 0;
		while ( $guard < 5 ) {
			if ( $path === $home_path ) {
				return '/';
			}
			if ( ! str_starts_with( $path, $prefix . '/' ) ) {
				break;
			}
			$stripped = substr( $path, strlen( $prefix ) );
			$path     = '' === $stripped ? '/' : $this->normalize_path( $stripped );
			++$guard;
		}
		return $path;
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
	 * Validate and normalize a redirect rule for storage / import.
	 *
	 * @param array<string,mixed> $rule Raw rule fields.
	 * @return array<string,mixed>|WP_Error Normalized rule (+ target_url) or error.
	 */
	public function sanitize_rule_for_storage( array $rule ) {
		$match_type = sanitize_key( (string) ( $rule['match_type'] ?? 'exact' ) );
		if ( ! in_array( $match_type, array( 'exact', 'wildcard', 'regex' ), true ) ) {
			$match_type = 'exact';
		}

		$source = isset( $rule['source'] ) ? (string) $rule['source'] : '';
		$source = 'regex' === $match_type
			? trim( $source )
			: $this->strip_home_subdirectory( $this->normalize_path( sanitize_text_field( $source ) ) );
		if ( '' === $source ) {
			return new WP_Error( 'empty_source', __( 'Enter a valid source path such as /old-page/.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'regex' === $match_type && ! $this->is_safe_regex_source( $source ) ) {
			return new WP_Error( 'bad_regex', __( 'Enter a valid regular expression source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$status = absint( $rule['status'] ?? 301 );
		if ( ! in_array( $status, $this->allowed_statuses(), true ) ) {
			return new WP_Error( 'bad_status', __( 'Invalid redirect status.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$target = sanitize_text_field( (string) ( $rule['target'] ?? '' ) );
		if ( preg_match( '~^https?://([^/?#]+)~i', $target, $host_m ) && preg_match( '/\$\d+/', $host_m[1] ) ) {
			return new WP_Error( 'bad_target', __( 'Capture tokens ($1, $2, …) are not allowed in the host of an absolute URL.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$target_url = in_array( $status, array( 410, 451 ), true ) ? '' : $this->target_to_url( preg_replace( '/\$\d+/', 'x', $target ) );
		if ( ! in_array( $status, array( 410, 451 ), true ) && '' === $target_url ) {
			return new WP_Error( 'bad_target', __( 'Enter a valid target URL or site path.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return array(
			'id'         => sanitize_key( (string) ( $rule['id'] ?? '' ) ),
			'source'     => $source,
			'target'     => $target,
			'match_type' => $match_type,
			'status'     => $status,
			'enabled'    => ! empty( $rule['enabled'] ),
			'hits'       => absint( $rule['hits'] ?? 0 ),
			'last_hit'   => absint( $rule['last_hit'] ?? 0 ),
			'created'    => absint( $rule['created'] ?? time() ),
			'target_url' => $target_url,
		);
	}

	/**
	 * Whether a regex source is compilable and not an obvious ReDoS pattern.
	 *
	 * @param string $source Pattern without delimiters.
	 * @return bool
	 */
	private function is_safe_regex_source( string $source ): bool {
		$source = trim( $source );
		if ( '' === $source || strlen( $source ) > 300 ) {
			return false;
		}
		// Nested quantifiers like (a+)+ / (a*)* are classic catastrophic backtracking.
		if ( preg_match( '/\([^()]*[+*][^()]*\)[+*]/', $source ) ) {
			return false;
		}
		$pattern = '#' . str_replace( '#', '\#', $source ) . '#';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Compile-check only.
		return false !== @preg_match( $pattern, '/' );
	}

	/**
	 * Redirect to an on-site or allowed absolute http(s) URL.
	 *
	 * @param string $url    Destination.
	 * @param int    $status HTTP status.
	 */
	private function do_redirect( string $url, int $status ): void {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $url_host && '' !== $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentional external redirect after admin validation.
			wp_redirect( $url, $status );
			return;
		}
		wp_safe_redirect( $url, $status );
	}

	/**
	 * Convert a stored target into a safe URL (site path or absolute http/https).
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

		$scheme = strtolower( (string) wp_parse_url( $target, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = (string) wp_parse_url( $target, PHP_URL_HOST );
		// Same host: prefer WordPress allowlist validation.
		if ( '' !== $url_host && '' !== $home_host && strtolower( $url_host ) === strtolower( $home_host ) ) {
			$validated = wp_validate_redirect( $target, '' );
			return '' === $validated ? '' : $validated;
		}

		// External absolute URL (admin-configured only).
		return $target;
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
		$target_path = $this->strip_home_subdirectory( $this->normalize_path( $target_path ) );
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
