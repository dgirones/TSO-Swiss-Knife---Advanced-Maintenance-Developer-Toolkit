<?php
/**
 * TSO Swiss Knife – Module: Rewrite Rules Flush.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Rewrite {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_flush_rewrite', array( $this, 'ajax_flush' ) );
	}

	public function ajax_flush(): void {
		check_ajax_referer( 'tsosk_rewrite_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$hard = ! empty( $_POST['hard'] );
		flush_rewrite_rules( $hard );
		TSOSK_Activity_Log::log(
			'rewrite',
			'flush',
			$hard
				? __( 'Rewrite rules hard-flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Rewrite rules soft-flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
		wp_send_json_success( __( 'Rewrite rules flushed successfully.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		global $wp_rewrite;

		$nonce = wp_create_nonce( 'tsosk_rewrite_nonce' );
		$rules = get_option( 'rewrite_rules', array() );
		$route_url = isset( $_GET['tsosk_route_url'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_route_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic form.
		$route = '' !== $route_url ? $this->get_route_diagnostics( $route_url, is_array( $rules ) ? $rules : array() ) : array();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Flush and inspect WordPress rewrite rules. A "hard flush" also regenerates the .htaccess / web.config file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'When and how to flush rewrite rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
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
		</div>

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
			<?php if ( empty( $rules ) ) : ?>
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
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/' . ltrim( $url, '/' );
		}

		$path = '/' . ltrim( $path, '/' );
		$request = ltrim( $path, '/' );
		$post_id = url_to_postid( home_url( $path ) );
		$matched_regex = __( 'No rewrite rule matched.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$matched_query = __( 'Unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

		foreach ( $rules as $regex => $query ) {
			if ( preg_match( '#^' . str_replace( '#', '\#', (string) $regex ) . '#', $request, $matches ) ) {
				$matched_regex = (string) $regex;
				$matched_query = (string) $query;
				if ( ! empty( $matches ) ) {
					foreach ( $matches as $index => $match ) {
						$matched_query = str_replace( '$matches[' . $index . ']', $match, $matched_query );
					}
				}
				break;
			}
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

