<?php
/**
 * TSO Swiss Knife – Module: Rewrite Rules Flush.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite module.
 */
class TSOSK_Mod_Rewrite {

	/**
	 * Singleton instance.
	 *
	 * @var TSOSK_Mod_Rewrite|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		add_action( 'wp_ajax_tsosk_flush_rewrite', array( $this, 'ajax_flush' ) );
	}

	/**
	 * AJAX: flush.
	 */
	public function ajax_flush(): void {
		check_ajax_referer( 'tsosk_rewrite_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$hard = ! empty( $_POST['hard'] );

		// save_mod_rewrite_rules() / got_mod_rewrite() live in wp-admin/includes/misc.php.
		tsosk_require_wp_admin( 'includes/misc.php' );
		tsosk_require_wp_admin( 'includes/file.php' );

		flush_rewrite_rules( $hard );
		TSOSK_Activity_Log::log(
			'rewrite',
			'flush',
			$hard
				? __( 'Rewrite rules hard-flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Rewrite rules soft-flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
		wp_send_json_success( $this->describe_flush_result( $hard ) );
	}

	/**
	 * Build an honest result message for a flush.
	 *
	 * flush_rewrite_rules( true ) silently skips the server file when .htaccess is not writable,
	 * on multisite, or when the server is not Apache-compatible, so "success" alone is misleading.
	 *
	 * @param bool $hard Whether a hard flush was requested.
	 * @return array{message:string,warning:bool}
	 */
	private function describe_flush_result( bool $hard ): array {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return array(
				'message' => __( 'Flushed, but plain permalinks are active, so WordPress stores no rewrite rules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'warning' => false,
			);
		}

		$rules   = get_option( 'rewrite_rules', array() );
		$message = sprintf(
			/* translators: %d: number of rewrite rules now stored */
			__( 'Rewrite rules flushed. Rules now stored: %d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			is_array( $rules ) ? count( $rules ) : 0
		);

		if ( ! $hard ) {
			return array(
				'message' => $message,
				'warning' => false,
			);
		}

		if ( is_multisite() ) {
			return array(
				'message' => $message . ' ' . __( 'On multisite WordPress does not rewrite .htaccess automatically.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'warning' => true,
			);
		}

		if ( function_exists( 'iis7_supports_permalinks' ) && iis7_supports_permalinks() ) {
			// IIS: web.config is handled by core; nothing reliable to verify here.
			return array(
				'message' => $message,
				'warning' => false,
			);
		}

		if ( ! function_exists( 'got_mod_rewrite' ) || ! got_mod_rewrite() ) {
			return array(
				'message' => $message . ' ' . __( 'This server does not use .htaccess (for example nginx), so only the database rules were refreshed. Update the server configuration manually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'warning' => true,
			);
		}

		$home_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$htaccess  = trailingslashit( $home_path ) . '.htaccess';
		$writable  = file_exists( $htaccess ) ? wp_is_writable( $htaccess ) : wp_is_writable( dirname( $htaccess ) );

		if ( ! $writable ) {
			return array(
				'message' => $message . ' ' . __( 'WARNING: .htaccess is not writable, so it was NOT updated. Fix its permissions or edit it manually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'warning' => true,
			);
		}

		return array(
			'message' => $message . ' ' . __( 'The .htaccess file was updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'warning' => false,
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		global $wp_rewrite;

		$nonce = wp_create_nonce( 'tsosk_rewrite_nonce' );
		$rules = get_option( 'rewrite_rules', array() );
		if ( ! is_array( $rules ) ) {
			// Plain permalinks store an empty string here.
			$rules = array();
		}
		$route_url = isset( $_GET['tsosk_route_url'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_route_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic form.
		$route     = '' !== $route_url ? $this->get_route_diagnostics( $route_url, $rules ) : array();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Flush and inspect WordPress rewrite rules. A "hard flush" also regenerates the .htaccess / web.config file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<details class="tsosk-guide-card tsosk-guide-collapse">
			<summary class="tsosk-guide-title"><?php esc_html_e( 'When and how to flush rewrite rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'WordPress stores URL routing rules in the database. Plugins and custom post types register rules when they load; those rules must be rebuilt after permalink or routing changes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-guide-grid">
				<div class="tsosk-guide-block">
					<strong><?php esc_html_e( 'Soft flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<p class="description" style="margin:6px 0 0;">
						<?php esc_html_e( 'Regenerates rules in the database only. Safer and usually enough after activating a plugin, changing slug settings, or fixing 404s on custom post types.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</p>
				</div>
				<div class="tsosk-guide-block">
					<strong><?php esc_html_e( 'Hard flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<p class="description" style="margin:6px 0 0;">
						<?php esc_html_e( 'Also rewrites .htaccess (Apache) or web.config (IIS). Use when soft flush does not fix front-end 404s, or after moving the site to a subdirectory. On nginx, server config must still be updated manually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</p>
				</div>
			</div>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-top:12px;margin-bottom:0;">
				<?php esc_html_e( 'Avoid flushing on every page load — it is expensive. Only flush when something actually changed. A hard flush can overwrite custom server rules if WordPress does not detect them; back up .htaccess first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</details>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Flush Rewrite Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Soft flush: regenerates rules in the database. Hard flush: also updates the server rewrite configuration file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<button class="button button-primary" id="tsosk-flush-soft"
					data-nonce="<?php echo esc_attr( $nonce ); ?>" data-hard="0">
				<?php esc_html_e( 'Soft Flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button class="button button-secondary" id="tsosk-flush-hard"
					data-nonce="<?php echo esc_attr( $nonce ); ?>" data-hard="1" style="margin-left:6px;">
				<?php esc_html_e( 'Hard Flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-rewrite-msg"></span>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Permalink & Routing Simulator', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Enter a public URL or path to see hidden routing details: matching rewrite rule, resolved post ID and query target.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="tso-swiss-knife">
				<input type="hidden" name="tab" value="rewrite">
				<input type="text" class="regular-text" name="tsosk_route_url" value="<?php echo esc_attr( $route_url ); ?>" placeholder="/sample-page/">
				<button class="button button-secondary" type="submit"><?php esc_html_e( 'Simulate Route', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			</form>
			<?php if ( ! empty( $route ) ) : ?>
				<table class="tsosk-kv-table" style="margin-top:12px;">
					<?php foreach ( $route as $label => $value ) : ?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td><code><?php echo esc_html( $value ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3>
				<?php
				printf(
					/* translators: %d: number of rewrite rules */
					esc_html__( 'Current Rewrite Rules (%d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					count( $rules )
				);
				?>
			</h3>
			<?php if ( empty( $rules ) && '' === (string) get_option( 'permalink_structure' ) ) : ?>
				<p><?php esc_html_e( 'Plain permalinks are active, so WordPress stores no rewrite rules. Choose another structure in Settings → Permalinks to use them.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php elseif ( empty( $rules ) ) : ?>
				<p><?php esc_html_e( 'No rewrite rules stored. Have you flushed permalinks?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
			<input type="text" id="tsosk-rewrite-search"
					placeholder="<?php esc_attr_e( 'Filter rules…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
					style="width:280px;margin-bottom:10px;">
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-rewrite-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Regex', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $regex => $redirect ) : ?>
						<tr>
							<td class="tsosk-code"><?php echo esc_html( $regex ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $redirect ); ?></td>
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
	 * Inspect how a URL path maps into WordPress routing data.
	 *
	 * @param string $url   URL or path to inspect.
	 * @param array  $rules Current rewrite rules.
	 * @return array<string,string>
	 */
	private function get_route_diagnostics( string $url, array $rules ): array {
		$parts = wp_parse_url( $url );
		$path  = ( is_array( $parts ) && isset( $parts['path'] ) ) ? (string) $parts['path'] : '';
		$query = ( is_array( $parts ) && isset( $parts['query'] ) ) ? (string) $parts['query'] : '';

		// A bare domain or "?p=1" has no path: that is the home page, not "/https://domain".
		$path = '/' . ltrim( $path, '/' );

		// Rules are relative to the site address. On subdirectory installs (e.g. /blog2/) strip that
		// prefix, otherwise a pasted full URL never matches and home_url() would double the folder.
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path ) {
			$home_prefix = untrailingslashit( $home_path );
			if ( 0 === strpos( trailingslashit( $path ), trailingslashit( $home_prefix ) ) ) {
				$path = '/' . ltrim( substr( $path, strlen( $home_prefix ) ), '/' );
			}
		}

		// WordPress trims the slashes at both ends before matching.
		$request       = trim( $path, '/' );
		$lookup_url    = home_url( $path ) . ( '' !== $query ? '?' . $query : '' );
		$post_id       = url_to_postid( $lookup_url );
		$matched_regex = __( 'No rewrite rule matched.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$matched_query = __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		// An empty request can only match the "$" rule (same as WP::parse_request()).
		$candidates = ( '' === $request )
			? ( isset( $rules['$'] ) ? array( '$' => $rules['$'] ) : array() )
			: $rules;

		foreach ( $candidates as $regex => $rule_query ) {
			// Escape only the "#" delimiter characters that are not already escaped.
			$pattern = '#^' . preg_replace( '/(?<!\\\\)#/', '\\#', (string) $regex ) . '#';
			$matches = array();
			if ( '' === $request ) {
				$matches = array( '' );
			} elseif ( ! preg_match( $pattern, $request, $matches ) && ! preg_match( $pattern, urldecode( $request ), $matches ) ) {
				continue;
			}

			$matched_regex = (string) $regex;
			// Same substitution as core: values are URL-encoded and unmatched groups become empty.
			$matched_query = (string) preg_replace_callback(
				'/\$matches\[([1-9][0-9]*)\]/',
				static function ( array $ref ) use ( $matches ): string {
					$index = (int) $ref[1];
					return isset( $matches[ $index ] ) ? urlencode( (string) $matches[ $index ] ) : '';
				},
				(string) $rule_query
			);
			break;
		}

		return array(
			__( 'Input', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )           => $url,
			__( 'Normalized Path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => $path,
			__( 'Resolved Post ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) => $post_id ? (string) $post_id : __( 'None', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'Matched Regex', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )  => $matched_regex,
			__( 'Matched Query', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )  => $matched_query,
		);
	}
}

