<?php
/**
 * TSO Swiss Knife – Module: REST API Controls.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Rest_Api
 */
class TSOSK_Mod_Rest_Api {

	/** Option key. */
	private const OPTION = 'tsosk_rest_settings';

	/** Blocked-request log option key. */
	private const OPTION_LOG = 'tsosk_rest_blocked_log';

	/** Maximum log entries kept. */
	private const MAX_LOG = 100;

	/** @var TSOSK_Mod_Rest_Api|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_rest_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_rest_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	/** Apply settings early. */
	public function init(): void {
		$settings = $this->get_settings();
		if ( 'disabled' === $settings['mode'] ) {
			add_filter( 'rest_authentication_errors', static function ( $result ) {
				if ( ! is_user_logged_in() ) {
					self::log_blocked_request( 'rest_disabled', __( 'REST API access restricted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
					return new WP_Error( 'rest_disabled', __( 'REST API access restricted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'status' => 401 ) );
				}
				return $result;
			}, 99 );
		}
		if ( ! empty( $settings['block_user_enumeration'] ) ) {
			add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
				if ( is_user_logged_in() ) {
					return $result;
				}
				$route = ltrim( (string) $request->get_route(), '/' );
				if ( preg_match( '#^wp/v2/users(?:/(\d+|me))?$#', $route ) ) {
					self::log_blocked_request( 'user_enumeration', __( 'User listing via the REST API is disabled on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $route );
					return new WP_Error(
						'rest_user_enumeration_blocked',
						__( 'User listing via the REST API is disabled on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						array( 'status' => 401 )
					);
				}
				return $result;
			}, 9, 3 );
		}

		$disabled_ns = $settings['disabled_namespaces'];
		if ( ! empty( $disabled_ns ) ) {
			add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) use ( $disabled_ns ) {
				$route = ltrim( (string) $request->get_route(), '/' );
				foreach ( $disabled_ns as $ns ) {
					$ns_clean = ltrim( (string) $ns, '/' );
					// Match the namespace itself or a route inside it, on a
					// "/" boundary — a plain strpos() prefix match would also
					// block an unrelated namespace that merely starts with the
					// same characters (e.g. disabling "foo/v1" would also
					// silently block "foo/v10" or "foo/v1-extra").
					if ( $route === $ns_clean || 0 === strpos( $route, $ns_clean . '/' ) ) {
						self::log_blocked_request(
							'namespace_disabled',
							sprintf(
								/* translators: %s: namespace */
								__( 'REST namespace %s is disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								$ns
							),
							$route
						);
						return new WP_Error(
							'rest_namespace_disabled',
							/* translators: %s: namespace */
							sprintf( __( 'REST namespace %s is disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $ns ),
							array( 'status' => 403 )
						);
					}
				}
				return $result;
			}, 10, 3 );
		}
	}

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_rest_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'enabled';
		if ( ! in_array( $mode, array( 'enabled', 'disabled' ), true ) ) {
			$mode = 'enabled';
		}
		$raw_ns = array();
		if ( isset( $_POST['disabled_namespaces'] ) && is_array( $_POST['disabled_namespaces'] ) ) {
			$raw_ns = array_map( 'sanitize_text_field', wp_unslash( $_POST['disabled_namespaces'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$ns = array_values( array_filter( array_map( 'trim', $raw_ns ) ) );
		$block_enum = ! empty( $_POST['block_user_enumeration'] );
		update_option( self::OPTION, array( 'mode' => $mode, 'disabled_namespaces' => $ns, 'block_user_enumeration' => $block_enum ), false );
		TSOSK_Activity_Log::log(
			'rest-api',
			'save',
			sprintf(
				/* translators: 1: REST mode, 2: number of blocked namespaces */
				__( 'REST API settings saved (mode: %1$s, %2$d namespaces blocked).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$mode,
				count( $ns )
			),
			array( 'mode' => $mode )
		);
		wp_send_json_success( __( 'REST API settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: clear the blocked-request log. */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_rest_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		update_option( self::OPTION_LOG, array(), false );
		TSOSK_Activity_Log::log( 'rest-api', 'clear', __( 'REST API blocked-request log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Record a request that was actually blocked, so the admin can review real traffic later.
	 *
	 * Called from static filter callbacks, so this stays static too.
	 *
	 * @param string $reason  Short machine-readable reason key.
	 * @param string $message Human-readable message shown to the caller.
	 * @param string $route   REST route, when known.
	 */
	private static function log_blocked_request( string $reason, string $message, string $route = '' ): void {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'ip'      => self::get_client_ip(),
				'route'   => sanitize_text_field( $route ),
				'reason'  => sanitize_key( $reason ),
				'message' => sanitize_text_field( $message ),
			)
		);

		if ( count( $log ) > self::MAX_LOG ) {
			$log = array_slice( $log, 0, self::MAX_LOG );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_blocked_log(): array {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Get the real client IP (REMOTE_ADDR only; proxy headers are not trusted by default).
	 */
	private static function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Plain-language help for a REST namespace.
	 *
	 * @param string $ns Namespace slug.
	 * @return array{description:string,risk:string,risk_label:string}
	 */
	private function get_namespace_info( string $ns ): array {
		$known = array(
			'wp/v2'              => array(
				'description' => __( 'The main WordPress API: saves posts and pages, loads media, users, categories, etc. The Block Editor cannot work without it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'critical',
				'risk_label'  => __( 'Critical — do not disable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'wp-block-editor/v1' => array(
				'description' => __( 'Endpoints used exclusively by the Block Editor (Gutenberg) while you edit content in wp-admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'critical',
				'risk_label'  => __( 'Critical — do not disable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'wp-site-health/v1'  => array(
				'description' => __( 'Powers Site Health checks under Tools → Site Health. Disabling hides tests and status in the admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'high',
				'risk_label'  => __( 'High risk — admin tools break', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'oembed/1.0'           => array(
				'description' => __( 'Handles embed previews (YouTube, Twitter, etc.) and allows other sites to embed your posts. Rarely needed to block.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'medium',
				'risk_label'  => __( 'Medium risk — embeds may fail', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'wp/v3'              => array(
				'description' => __( 'Newer WordPress REST routes. Future core features may depend on it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'high',
				'risk_label'  => __( 'High risk — future core features', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
			'wp-abilities/v1'    => array(
				'description' => __( 'WordPress Abilities API — used by newer core and plugin integrations. Only block if you know it is unused.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'risk'        => 'medium',
				'risk_label'  => __( 'Medium risk — newer integrations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			),
		);

		if ( isset( $known[ $ns ] ) ) {
			return $known[ $ns ];
		}

		return array(
			'description' => __( 'Endpoints registered by a plugin or theme. Blocking cuts off every request to this API group — the related plugin feature will stop working for everyone, including logged-in admins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'risk'        => 'unknown',
			'risk_label'  => __( 'Test on staging first', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Render guide for namespace blocking section.
	 */
	private function render_namespace_guide(): void {
		?>
		<details class="tsosk-guide-card tsosk-rest-ns-guide tsosk-guide-collapse">
			<summary class="tsosk-guide-title"><?php esc_html_e( 'What are REST namespaces?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'WordPress exposes data through the REST API — a set of URLs like /wp-json/wp/v2/posts that apps, the Block Editor, and plugins use to read or update content. A “namespace” is a group of those URLs (e.g. wp/v2 = posts, pages, media).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<div class="tsosk-guide-grid">
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'What does blocking do?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<p><?php esc_html_e( 'When you tick a namespace below, every request to that API group returns an error — for visitors and for logged-in admins alike. It is a surgical “off switch” for one API group, not the same as the access mode above (which only affects logged-out users).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				</div>
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'Why would you use this?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Advanced hardening: hide a plugin’s public API if you do not need it and it adds attack surface.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Troubleshooting: temporarily block a namespace to see if a problem comes from a specific plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="tsosk-notice tsosk-notice-warn" style="margin:0;">
				<strong><?php esc_html_e( 'Is it dangerous?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Yes, if you block the wrong namespace. Core groups (wp/v2, wp-block-editor) will break the Block Editor immediately. Plugin namespaces will break that plugin’s features. Leave everything unchecked unless you have a specific reason and have tested on staging.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</details>
		<?php
	}

	public function render(): void {
		$nonce       = wp_create_nonce( 'tsosk_rest_nonce' );
		$blocked_log = self::get_blocked_log();
		$settings    = $this->get_settings();
		$mode     = $settings['mode'];
		$dis_ns   = $settings['disabled_namespaces'];
		$block_enum = $settings['block_user_enumeration'];

		// Detect plugins that use the REST API and could break.
		$rest_dependent = $this->detect_rest_dependent_plugins();

		$namespaces = array();
		if ( function_exists( 'rest_get_server' ) ) {
			$server     = rest_get_server();
			$namespaces = method_exists( $server, 'get_namespaces' ) ? $server->get_namespaces() : array();
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Control anonymous access to the WordPress REST API. Read the compatibility warnings below before restricting access.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php if ( ! empty( $rest_dependent ) ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<strong><?php esc_html_e( '⚠ Compatibility Warning', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong><br>
			<?php esc_html_e( 'The following active plugins are known to require the REST API. Disabling or restricting it may break their functionality:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			<ul style="margin:8px 0 0 16px;list-style:disc;">
				<?php foreach ( $rest_dependent as $p ) : ?>
				<li>
					<strong><?php echo esc_html( $p['name'] ); ?></strong>
					<?php if ( ! empty( $p['reason'] ) ) : ?>
						— <?php echo esc_html( $p['reason'] ); ?>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'REST API Access', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div class="tsosk-notice tsosk-notice-info" style="margin-bottom:12px;">
				<?php esc_html_e( 'The WordPress Block Editor (Gutenberg) and many modern plugins rely entirely on the REST API. Disabling it for non-authenticated users prevents those plugins from working on the front end, and may also break the admin dashboard if any plugin makes front-end REST calls while logged out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>

			<label class="tsosk-radio-row">
				<input type="radio" name="tsosk_rest_mode" value="enabled" <?php checked( $mode, 'enabled' ); ?>>
				<span>
					<strong><?php esc_html_e( 'Enabled (default)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong><br>
					<span class="description"><?php esc_html_e( 'The REST API is fully public. All plugins work normally. Recommended for all sites using the Block Editor, Jetpack, WooCommerce, ACF, Yoast or similar.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>

			<label class="tsosk-radio-row" style="margin-top:12px;">
				<input type="radio" name="tsosk_rest_mode" value="disabled" <?php checked( $mode, 'disabled' ); ?>>
				<span>
					<strong><?php esc_html_e( 'Disabled for non-authenticated users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong><br>
					<span class="description"><?php esc_html_e( 'Anonymous REST requests return a 401 error. Logged-in users are not affected. Only use this if you do not have any plugin that needs the REST API for unauthenticated visitors, and you have tested it first in a staging environment.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Block User Enumeration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'The /wp-json/wp/v2/users endpoint lists every username on the site to anyone, logged in or not — it is step one of many automated attacks (guess the admin username, then brute-force or target it). This blocks only that specific endpoint for logged-out requests; the rest of wp/v2 (posts, pages, media, the Block Editor) keeps working normally.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<label class="tsosk-toggle-row">
				<input type="checkbox" name="tsosk_rest_block_user_enum" id="tsosk-rest-block-user-enum" value="1" <?php checked( $block_enum ); ?>>
				<span>
					<strong><?php esc_html_e( 'Block anonymous access to /wp/v2/users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="tsosk-hint"><?php esc_html_e( 'Recommended for most sites. Logged-in users are never affected. Skip this only if a front-end feature on your site (e.g. a public author directory widget) reads this endpoint while logged out.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
		</div>

		<?php if ( ! empty( $namespaces ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Disable Specific Namespaces', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description tsosk-rest-ns-intro">
				<?php esc_html_e( 'Optional advanced control. Tick a namespace only to completely block that API group. Most sites should leave all boxes unchecked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<?php $this->render_namespace_guide(); ?>

			<p class="tsosk-ns-legend">
				<span class="tsosk-badge tsosk-badge-core"><?php esc_html_e( 'WordPress Core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				<?php esc_html_e( '= essential for WordPress; cannot be ticked by default.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge tsosk-badge-warn" style="margin-left:12px;">⚠</span>
				<?php esc_html_e( '= an active plugin on this site needs this namespace.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<div class="tsosk-ns-list" id="tsosk-ns-list">
				<?php foreach ( $namespaces as $ns ) : ?>
				<?php
				$is_core   = in_array( $ns, array( 'wp/v2', 'wp-block-editor/v1', 'wp-site-health/v1', 'oembed/1.0', 'wp/v3' ), true );
				$is_dis    = in_array( $ns, $dis_ns, true );
				$info      = $this->get_namespace_info( $ns );
				$dep_names = array();
				foreach ( $rest_dependent as $p ) {
					if ( ! empty( $p['namespace'] ) && $p['namespace'] === $ns ) {
						$dep_names[] = $p['name'];
					}
				}
				?>
				<div class="tsosk-ns-item">
					<label class="tsosk-ns-item-label">
						<input type="checkbox" class="tsosk-ns-cb" value="<?php echo esc_attr( $ns ); ?>"
						       <?php checked( $is_dis ); ?> <?php disabled( $is_core && ! $is_dis ); ?>>
						<span class="tsosk-ns-item-head">
							<strong><?php esc_html_e( 'Block', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
							<code><?php echo esc_html( $ns ); ?></code>
							<?php if ( $is_core ) : ?>
								<span class="tsosk-badge tsosk-badge-core"><?php esc_html_e( 'WordPress Core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
							<span class="tsosk-badge tsosk-ns-risk tsosk-ns-risk-<?php echo esc_attr( $info['risk'] ); ?>">
								<?php echo esc_html( $info['risk_label'] ); ?>
							</span>
							<?php if ( ! empty( $dep_names ) ) : ?>
								<span class="tsosk-badge tsosk-badge-warn"
								      title="<?php echo esc_attr( implode( ', ', $dep_names ) ); ?>">
									⚠ <?php echo esc_html( implode( ', ', $dep_names ) ); ?>
								</span>
							<?php endif; ?>
						</span>
					</label>
					<p class="tsosk-ns-item-desc"><?php echo esc_html( $info['description'] ); ?></p>
				</div>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Core WordPress namespaces stay unchecked and locked by default so the Block Editor keeps working. Only change this if you are deliberately hardening a site without Gutenberg.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $blocked_log ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recently blocked requests', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Requests actually rejected by the settings above (disabled access, blocked user listing, or a disabled namespace). Nothing is recorded here while everything is left at its default, unrestricted settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-table-wrap">
				<table class="widefat striped tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Route', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $blocked_log, 0, 25 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $entry['ip'] ?? '' ) ); ?></code></td>
							<td><code><?php echo esc_html( '' !== (string) ( $entry['route'] ?? '' ) ? (string) $entry['route'] : '—' ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-rest-clear-log" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

		<button class="button button-primary" id="tsosk-rest-save"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</button>
		<span class="tsosk-ajax-msg" id="tsosk-rest-msg"></span>
		<?php
	}

	/**
	 * Detect active plugins known to depend on the REST API.
	 *
	 * @return array<int,array{name:string,reason:string,namespace:string}>
	 */
	private function detect_rest_dependent_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = get_plugins();

		$known = array(
			'jetpack'              => array( 'name' => 'Jetpack',                   'reason' => __( 'Jetpack relies on the REST API for sync, stats, Publicize and many other features.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),               'namespace' => 'jetpack/v4' ),
			'woocommerce'          => array( 'name' => 'WooCommerce',               'reason' => __( 'WooCommerce uses the REST API for the cart, checkout, store blocks and mobile apps.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),               'namespace' => 'wc/v3' ),
			'yoast-seo'            => array( 'name' => 'Yoast SEO',                 'reason' => __( 'Yoast SEO uses the REST API for the sidebar integration in the Block Editor.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                     'namespace' => 'yoast/v1' ),
			'advanced-custom-fields' => array( 'name' => 'Advanced Custom Fields', 'reason' => __( 'ACF registers REST API endpoints to expose custom fields.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                        'namespace' => '' ),
			'acf'                  => array( 'name' => 'ACF',                       'reason' => __( 'ACF (Pro) uses REST API to expose custom fields.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                  'namespace' => '' ),
			'contact-form-7'       => array( 'name' => 'Contact Form 7',            'reason' => __( 'CF7 uses REST API endpoints for form submissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                                'namespace' => 'contact-form-7/v1' ),
			'elementor'            => array( 'name' => 'Elementor',                 'reason' => __( 'Elementor uses REST API calls in the page builder and for template library.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                      'namespace' => 'elementor/v1' ),
			'gutenberg'            => array( 'name' => 'Gutenberg',                 'reason' => __( 'The Block Editor requires REST API for all edit operations.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                      'namespace' => 'wp/v2' ),
			'wp-rocket'            => array( 'name' => 'WP Rocket',                 'reason' => __( 'WP Rocket uses the REST API for cache invalidation and heartbeat optimisation.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                   'namespace' => '' ),
			'litespeed-cache'      => array( 'name' => 'LiteSpeed Cache',           'reason' => __( 'LiteSpeed Cache uses REST API for QUIC.cloud integration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                       'namespace' => 'litespeed/v1' ),
			'mailchimp-for-wp'     => array( 'name' => 'Mailchimp for WP',          'reason' => __( 'Mailchimp for WP uses REST API for forms and subscribe endpoints.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),                                'namespace' => '' ),
		);

		$found = array();
		foreach ( $active as $plugin_file ) {
			$slug = explode( '/', $plugin_file )[0];
			// Match by exact slug or partial slug.
			foreach ( $known as $pattern => $info ) {
				if ( false !== strpos( strtolower( $slug ), $pattern ) ) {
					$found[ $slug ] = $info;
					break;
				}
			}
			// Also match by plugin name from metadata.
			if ( isset( $plugins[ $plugin_file ] ) ) {
				$pname = strtolower( $plugins[ $plugin_file ]['Name'] ?? '' );
				foreach ( $known as $pattern => $info ) {
					if ( ! isset( $found[ $slug ] ) && false !== strpos( $pname, $pattern ) ) {
						$found[ $slug ] = $info;
					}
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Get saved settings with defaults.
	 *
	 * @return array{mode:string,disabled_namespaces:array}
	 */
	private function get_settings(): array {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array(
			'mode'                    => in_array( $s['mode'] ?? '', array( 'enabled', 'disabled' ), true ) ? $s['mode'] : 'enabled',
			'disabled_namespaces'     => isset( $s['disabled_namespaces'] ) && is_array( $s['disabled_namespaces'] ) ? $s['disabled_namespaces'] : array(),
			'block_user_enumeration'  => ! empty( $s['block_user_enumeration'] ),
		);
	}
}
