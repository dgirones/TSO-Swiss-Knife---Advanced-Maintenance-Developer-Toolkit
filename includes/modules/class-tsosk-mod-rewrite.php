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


// ═══════════════════════════════════════════════════════════════════════════════
// Object Cache Tools
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * TSO Swiss Knife – Module: Object Cache Tools.
 *
 * @package TSO_Swiss_Knife
 */
class TSOSK_Mod_Object_Cache {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_object_cache_flush', array( $this, 'ajax_flush' ) );
	}

	public function ajax_flush(): void {
		check_ajax_referer( 'tsosk_oc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		// Detect page cache plugins that need their own purge mechanism.
		$page_cache = $this->detect_page_cache_plugin();
		if ( $page_cache ) {
			// Try to flush via the plugin's own API if available.
			$this->try_flush_page_cache( $page_cache );
		}

		wp_cache_flush();
		TSOSK_Activity_Log::log( 'object-cache', 'flush', __( 'Object cache flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Object cache flushed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	public function render(): void {
		global $wp_object_cache;

		$nonce        = wp_create_nonce( 'tsosk_oc_nonce' );
		$is_external  = wp_using_ext_object_cache();
		$cache_type   = get_class( $wp_object_cache );
		$page_cache   = $this->detect_page_cache_plugin();
		$all_detected = $this->detect_all_cache_plugins();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect the WordPress object cache and detect active caching plugins. The object cache stores frequently used database query results in memory.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( ! empty( $all_detected ) ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<strong><?php esc_html_e( 'Detected cache plugins:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<ul style="margin:6px 0 0 16px;list-style:disc;">
				<?php foreach ( $all_detected as $cp ) : ?>
				<li>
					<strong><?php echo esc_html( $cp['name'] ); ?></strong> — <?php echo esc_html( $cp['note'] ); ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<p style="margin-top:8px;">
				<?php esc_html_e( '⚠ Flushing the object cache from here clears WordPress in-memory data. Page HTML cache managed by LiteSpeed Cache, WP Rocket, W3 Total Cache or similar plugins has its OWN flush mechanism inside those plugins. The Flush button below only clears the WordPress object cache (Redis, Memcached, or in-memory).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Cache Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'Object Cache Driver', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $cache_type ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'WP_CACHE Constant', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php if ( defined( 'WP_CACHE' ) && WP_CACHE ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'true', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php else : ?>
							<span class="tsosk-badge"><?php esc_html_e( 'false', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Persistent Object Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php if ( $is_external ) : ?>
							<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Yes – External (Redis/Memcached)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php else : ?>
							<span class="tsosk-badge"><?php esc_html_e( 'No – In-memory only (lost on each request)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( method_exists( $wp_object_cache, 'stats' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Cache Stats', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<?php
						ob_start();
						$wp_object_cache->stats();
						$stats = ob_get_clean();
						echo wp_kses( $stats, array( 'p' => array(), 'ul' => array(), 'li' => array(), 'strong' => array() ) );
						?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Flush Object Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'This flushes the WordPress object cache (Redis, Memcached or in-memory). It does NOT flush HTML page cache from LiteSpeed Cache, WP Rocket, W3 Total Cache or similar plugins — use those plugins\' own flush buttons for that.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<button class="button button-primary" id="tsosk-oc-flush" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Flush Object Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-oc-msg"></span>
		</div>
		<?php
	}

	/**
	 * Detect ALL active cache plugins for informational display.
	 *
	 * @return array<int,array{name:string,note:string}>
	 */
	private function detect_all_cache_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = get_plugins();

		$known = array(
			'litespeed-cache'     => array( 'name' => 'LiteSpeed Cache',       'note' => __('Page HTML cache + object cache. Use LiteSpeed Cache › Manage Cache to flush HTML. This button flushes LSCache object cache.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'wp-rocket'          => array( 'name' => 'WP Rocket',              'note' => __('Page HTML cache. Use WP Rocket › Clear Cache for HTML. This button has no effect on WP Rocket HTML cache.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'w3-total-cache'     => array( 'name' => 'W3 Total Cache',         'note' => __('Multi-layer cache including page, object and browser. Use W3TC Performance › Empty All Caches to flush HTML.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'wp-super-cache'     => array( 'name' => 'WP Super Cache',         'note' => __('Static HTML page cache. Use Delete Cache in the WP Super Cache settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'wp-fastest-cache'   => array( 'name' => 'WP Fastest Cache',       'note' => __('Static HTML cache. Use the Flush icon in the admin bar or WP Fastest Cache settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'hummingbird-performance' => array( 'name' => 'Hummingbird',      'note' => __('Page cache and asset optimization. Use Hummingbird › Caching to flush.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'cache-enabler'      => array( 'name' => 'Cache Enabler',          'note' => __('Static HTML page cache. Use the Clear Cache button in Cache Enabler settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'sg-cachepress'      => array( 'name' => 'SiteGround Optimizer',   'note' => __('SiteGround dynamic cache. Use SG Optimizer settings to flush.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'kinsta-mu-plugins'  => array( 'name' => 'Kinsta Cache',           'note' => __('Kinsta server-side cache. Use the Kinsta panel or WP-CLI to flush.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'redis-cache'        => array( 'name' => 'Redis Object Cache',      'note' => __('Redis-backed WordPress object cache. The Flush button above will flush this.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'wp-redis'           => array( 'name' => 'WP Redis',               'note' => __('Redis-backed WordPress object cache. The Flush button above will flush this.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'memcached'          => array( 'name' => 'Memcached Object Cache',  'note' => __('Memcached object cache. The Flush button above will flush this.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'wpboost'            => array( 'name' => 'WP Boost / Speed Optimizer', 'note' => __('Page and CSS/JS optimization cache. Use WP Boost settings to clear.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
			'autoptimize'        => array( 'name' => 'Autoptimize',             'note' => __('CSS/JS/HTML minification cache. Use Autoptimize › Delete Cache to clear.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit') ),
		);

		$found = array();
		foreach ( $active as $plugin_file ) {
			$slug  = explode( '/', $plugin_file )[0];
			$pname = strtolower( $plugins[ $plugin_file ]['Name'] ?? '' );
			foreach ( $known as $pattern => $info ) {
				if ( false !== strpos( strtolower( $slug ), $pattern ) || false !== strpos( $pname, $pattern ) ) {
					$found[] = $info;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Detect primary page-cache plugin for flush integration.
	 *
	 * @return string|null Plugin slug or null.
	 */
	private function detect_page_cache_plugin(): ?string {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $f ) {
			$slug = explode( '/', $f )[0];
			foreach ( array( 'litespeed-cache', 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache' ) as $known ) {
				if ( false !== strpos( strtolower( $slug ), $known ) ) {
					return $known;
				}
			}
		}
		return null;
	}

	/**
	 * Attempt to flush page cache via plugin's own API.
	 *
	 * @param string $plugin Plugin slug.
	 */
	private function try_flush_page_cache( string $plugin ): void {
		switch ( $plugin ) {
			case 'litespeed-cache':
				if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
					do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				}
				break;
			case 'wp-rocket':
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
				break;
			case 'w3-total-cache':
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
				}
				break;
			case 'wp-super-cache':
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				}
				break;
		}
	}
}



