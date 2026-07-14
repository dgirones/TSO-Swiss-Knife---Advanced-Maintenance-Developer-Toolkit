<?php
/**
 * TSO Swiss Knife – Module: Custom 404 Page.
 *
 * Serves a selected WordPress page on 404 errors with a real HTTP 404/410
 * response (no redirect), similar to the 404page plugin.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Custom_404
 */
class TSOSK_Mod_Custom_404 {

	/** Plugin option key. */
	private const OPTION = 'tsosk_custom_404';

	/** @var TSOSK_Mod_Custom_404|null */
	private static $instance = null;

	/** @var string URL that triggered the 404 response. */
	private static $failed_url = '';

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_Custom_404
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_custom_404_save', array( $this, 'ajax_save' ) );
	}

	/**
	 * Register frontend hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'init', array( $this, 'maybe_define_constant' ), 20 );
		add_filter( 'redirect_canonical', array( $this, 'maybe_disable_url_guess' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'hide_from_search' ) );
		add_action( 'pre_get_posts', array( $this, 'hide_from_pages_list' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_from_sitemap' ) );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( $this, 'exclude_from_yoast_sitemap' ) );
		add_filter( 'jetpack_sitemap_skip_post', array( $this, 'exclude_from_jetpack_sitemap' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'display_post_state' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_force_404_on_direct_access' ), 5 );
		add_filter( 'template_include', array( $this, 'filter_template_include' ), 99 );
		add_filter( 'body_class', array( $this, 'add_body_classes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_admin_preview' ), 1 );
	}

	/**
	 * Define TSOSK_404 when a valid custom page is configured.
	 */
	public function maybe_define_constant(): void {
		if ( $this->is_active() && ! defined( 'TSOSK_404' ) ) {
			define( 'TSOSK_404', true );
		}
	}

	/**
	 * Whether a published custom 404 page is configured.
	 */
	public function is_active(): bool {
		$page_id = $this->get_page_id();
		return $page_id > 0 && 'publish' === get_post_status( $page_id );
	}

	/**
	 * Selected 404 page ID.
	 */
	public function get_page_id(): int {
		$settings = $this->get_settings();
		return absint( $settings['page_id'] ?? 0 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$defaults = array(
			'page_id'              => 0,
			'hide_from_admin'      => false,
			'hide_from_search'     => true,
			'force_direct_404'     => true,
			'send_410'             => false,
			'disable_url_guess'    => false,
		);
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Capture the URL that caused the 404.
	 */
	private function capture_failed_url(): void {
		if ( '' !== self::$failed_url ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( is_string( $request_uri ) && '' !== $request_uri ) {
			self::$failed_url = home_url( $request_uri );
			return;
		}

		self::$failed_url = home_url( '/' );
	}

	/**
	 * Get the URL that caused the current 404.
	 *
	 * @param string $type full|page|domainpath.
	 */
	public static function get_the_url( string $type = 'full' ): string {
		if ( '' === self::$failed_url && class_exists( __CLASS__ ) ) {
			self::get_instance()->capture_failed_url();
		}

		$url = self::$failed_url;
		if ( '' === $url ) {
			return '';
		}

		$type = strtolower( sanitize_key( $type ) );
		if ( 'page' === $type ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return ltrim( $path, '/' );
		}
		if ( 'domainpath' === $type ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			return $host . $path;
		}

		return esc_url( $url );
	}

	/**
	 * Disable WordPress URL guessing on 404 when enabled.
	 *
	 * @param string|false $redirect Redirect URL.
	 * @param string       $request  Requested URL.
	 * @return string|false
	 */
	public function maybe_disable_url_guess( $redirect, string $request ) {
		unset( $request );
		$settings = $this->get_settings();
		if ( ! empty( $settings['disable_url_guess'] ) && is_404() ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Hide the 404 page from front-end search results.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function hide_from_search( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['hide_from_search'] ) ) {
			return;
		}

		$page_id = $this->get_page_id();
		if ( $page_id <= 0 ) {
			return;
		}

		$not_in = $query->get( 'post__not_in' );
		if ( ! is_array( $not_in ) ) {
			$not_in = array();
		}
		$not_in[] = $page_id;
		$query->set( 'post__not_in', array_values( array_unique( array_map( 'absint', $not_in ) ) ) );
	}

	/**
	 * Optionally hide the 404 page from the Pages admin list.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function hide_from_pages_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-page' !== $screen->id ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['hide_from_admin'] ) ) {
			return;
		}

		$page_id = $this->get_page_id();
		if ( $page_id <= 0 ) {
			return;
		}

		$not_in = $query->get( 'post__not_in' );
		if ( ! is_array( $not_in ) ) {
			$not_in = array();
		}
		$not_in[] = $page_id;
		$query->set( 'post__not_in', array_values( array_unique( array_map( 'absint', $not_in ) ) ) );
	}

	/**
	 * Exclude the 404 page from core sitemaps.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function exclude_from_sitemap( array $args ): array {
		$page_id = $this->get_page_id();
		if ( $page_id <= 0 ) {
			return $args;
		}

		if ( ! isset( $args['post__not_in'] ) || ! is_array( $args['post__not_in'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single 404 page ID excluded from core sitemap.
			$args['post__not_in'] = array();
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single 404 page ID excluded from core sitemap.
		$args['post__not_in'][] = $page_id;
		return $args;
	}

	/**
	 * Exclude from Yoast SEO sitemap when present.
	 *
	 * @param array<int> $excluded Post IDs.
	 * @return array<int>
	 */
	public function exclude_from_yoast_sitemap( array $excluded ): array {
		$page_id = $this->get_page_id();
		if ( $page_id > 0 ) {
			$excluded[] = $page_id;
		}
		return $excluded;
	}

	/**
	 * Exclude from Jetpack sitemap when present.
	 *
	 * @param bool    $skip Skip flag.
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public function exclude_from_jetpack_sitemap( bool $skip, WP_Post $post ): bool {
		if ( (int) $post->ID === $this->get_page_id() ) {
			return true;
		}
		return $skip;
	}

	/**
	 * Show a state label on the Pages list.
	 *
	 * @param array<string, string> $states States.
	 * @param WP_Post               $post   Post object.
	 * @return array<string, string>
	 */
	public function display_post_state( array $states, WP_Post $post ): array {
		if ( (int) $post->ID === $this->get_page_id() ) {
			$states['tsosk_404'] = __( '404 Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return $states;
	}

	/**
	 * When the 404 page is opened directly, force a 404 response.
	 */
	public function maybe_force_404_on_direct_access(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$page_id = $this->get_page_id();
		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['force_direct_404'] ) ) {
			return;
		}

		$this->capture_failed_url();
		global $wp_query;
		$wp_query->set_404();
	}

	/**
	 * Configure the main query to serve a page as a 404/410 response.
	 *
	 * @param WP_Post $post        Page post object.
	 * @param int     $status_code HTTP status code.
	 */
	private function setup_404_page_query( WP_Post $post, int $status_code = 404 ): void {
		status_header( $status_code );
		nocache_headers();

		global $wp_query;
		$page_id = (int) $post->ID;

		$wp_query->posts             = array( $post );
		$wp_query->post              = $post;
		$wp_query->post_count        = 1;
		$wp_query->found_posts       = 1;
		$wp_query->max_num_pages     = 1;
		$wp_query->is_404            = true;
		$wp_query->is_page           = true;
		$wp_query->is_singular       = true;
		$wp_query->is_single         = false;
		$wp_query->is_home           = false;
		$wp_query->is_archive        = false;
		$wp_query->is_category       = false;
		$wp_query->is_tag            = false;
		$wp_query->queried_object    = $post;
		$wp_query->queried_object_id = $page_id;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		/** Fires after a custom 404 page is loaded. Do not generate output. */
		do_action( 'tsosk_custom_404_after' );
	}

	/**
	 * Resolve the theme template for a 404 page.
	 *
	 * @param int    $page_id         Page ID.
	 * @param string $fallback_template Fallback template path.
	 * @return string
	 */
	private function locate_404_page_template( int $page_id, string $fallback_template = '' ): string {
		$page_template = get_page_template_slug( $page_id );
		if ( $page_template ) {
			$located = locate_template( $page_template );
			if ( $located ) {
				return $located;
			}
		}

		$page_tpl = get_query_template( 'page' );
		if ( $page_tpl ) {
			return $page_tpl;
		}

		$singular = get_query_template( 'singular' );
		return $singular ?: $fallback_template;
	}

	/**
	 * Admin-only preview: render the selected page as a 404 without saving settings.
	 */
	public function maybe_render_admin_preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( empty( $_GET['tsosk_404_preview'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), esc_html__( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['preview_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['preview_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tsosk_custom_404_preview' ) ) {
			wp_die( esc_html__( 'Invalid preview link.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), esc_html__( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'response' => 403 ) );
		}

		$page_id = isset( $_GET['page_id'] ) ? absint( wp_unslash( $_GET['page_id'] ) ) : 0;
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
			wp_die( esc_html__( 'Select a valid published page to preview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), esc_html__( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'response' => 400 ) );
		}

		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			wp_die( esc_html__( 'Page not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), esc_html__( 'Preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'response' => 404 ) );
		}

		self::$failed_url = home_url( '/tsosk-404-preview-example/' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin preview only.
		$status_code = ! empty( $_GET['send_410'] ) ? 410 : 404;

		$this->setup_404_page_query( $post, $status_code );
		$template = $this->locate_404_page_template( $page_id );
		if ( $template && is_readable( $template ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Theme template path from locate_template().
			include $template;
		}
		exit;
	}

	/**
	 * Replace the theme 404 template with the selected page (no redirect).
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function filter_template_include( string $template ): string {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $template;
		}

		if ( ! is_404() || ! $this->is_active() ) {
			return $template;
		}

		$page_id = $this->get_page_id();
		$post    = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			return $template;
		}

		$this->capture_failed_url();

		$settings = $this->get_settings();
		$code     = ! empty( $settings['send_410'] ) ? 410 : 404;

		$this->setup_404_page_query( $post, $code );

		return $this->locate_404_page_template( $page_id, $template );
	}

	/**
	 * Add error404 body class on custom 404 responses.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function add_body_classes( array $classes ): array {
		if ( is_404() && $this->is_active() ) {
			if ( ! in_array( 'error404', $classes, true ) ) {
				$classes[] = 'error404';
			}
			$classes[] = 'tsosk-custom-404';
		}
		return $classes;
	}

	/**
	 * Register the classic shortcode.
	 */
	public function register_shortcode(): void {
		add_shortcode( 'tsosk_404_url', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode output for the failed URL.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'display' => 'full',
			),
			$atts,
			'tsosk_404_url'
		);

		if ( ! is_404() && ! is_page( $this->get_page_id() ) ) {
			return '';
		}

		return esc_html( self::get_the_url( (string) $atts['display'] ) );
	}

	/**
	 * Register the Gutenberg block.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'tsosk-block-404-url',
			TSOSK_URL . 'assets/js/tsosk-block-404-url.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			TSOSK_VERSION,
			true
		);

		register_block_type(
			'tsosk/404-url',
			array(
				'editor_script'   => 'tsosk-block-404-url',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'display' => array(
						'type'    => 'string',
						'default' => 'full',
					),
				),
			)
		);
	}

	/**
	 * Server-side block render.
	 *
	 * @param array<string, string> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes ): string {
		$display = isset( $attributes['display'] ) ? (string) $attributes['display'] : 'full';
		return $this->render_shortcode( array( 'display' => $display ) );
	}

	/**
	 * AJAX: save settings.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_custom_404_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
		if ( $page_id > 0 && 'page' !== get_post_type( $page_id ) ) {
			wp_send_json_error( __( 'Select a valid page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $page_id > 0 && 'publish' !== get_post_status( $page_id ) ) {
			wp_send_json_error( __( 'The selected page must be published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$settings = array(
			'page_id'           => $page_id,
			'hide_from_admin'   => ! empty( $_POST['hide_from_admin'] ),
			'hide_from_search'  => ! empty( $_POST['hide_from_search'] ),
			'force_direct_404'  => ! empty( $_POST['force_direct_404'] ),
			'send_410'          => ! empty( $_POST['send_410'] ),
			'disable_url_guess' => ! empty( $_POST['disable_url_guess'] ),
		);

		update_option( self::OPTION, $settings, false );
		TSOSK_Activity_Log::log(
			'custom-404',
			'save',
			$page_id > 0
				? sprintf(
					/* translators: %d: page ID */
					__( 'Custom 404 page set (page ID %d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$page_id
				)
				: __( 'Custom 404 page cleared (theme default).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
		wp_send_json_success(
			array(
				'message' => __( 'Custom 404 settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'active'  => $page_id > 0 && 'publish' === get_post_status( $page_id ),
			)
		);
	}

	/**
	 * Render admin tab.
	 */
	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_custom_404_nonce' );
		$settings = $this->get_settings();
		$page_id  = absint( $settings['page_id'] );
		$active   = $this->is_active();
		$edit_url = $page_id ? get_edit_post_link( $page_id, 'raw' ) : '';
		$view_url = $page_id ? get_permalink( $page_id ) : '';
		$test_url       = home_url( '/tsosk-404-preview-' . wp_generate_password( 8, false, false ) . '/' );
		$preview_nonce  = wp_create_nonce( 'tsosk_custom_404_preview' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Create a custom 404 page like any normal page, then select it here. Visitors see your page content while the server still returns a real 404 code (no redirect). Search engines are told the URL does not exist.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '404 Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<p>
				<span class="tsosk-badge <?php echo $active ? 'tsosk-badge-ok' : 'tsosk-badge-info'; ?>" id="tsosk-custom-404-status">
					<?php
					echo $active
						? esc_html__( 'Custom 404 active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						: esc_html__( 'Using theme default 404', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					?>
				</span>
			</p>

			<div class="tsosk-field-row">
				<label for="tsosk-custom-404-page"><strong><?php esc_html_e( 'Select page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'tsosk_custom_404_page',
						'id'                => 'tsosk-custom-404-page',
						'selected'          => (int) $page_id,
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_dropdown_pages().
						'show_option_none'  => __( '— None (theme default) —', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'option_none_value' => '0',
						'post_status'       => array( 'publish' ),
					)
				);
				?>
			</div>

			<p class="description">
				<?php esc_html_e( 'Create the page under Pages → Add New, then pick it here. Permalinks must not be set to “Plain”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<?php if ( $page_id && $edit_url ) : ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $edit_url ); ?>">
					<?php esc_html_e( 'Edit page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
				<?php if ( $view_url ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
				<?php endif; ?>
				<a class="button button-secondary" href="<?php echo esc_url( $test_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Test 404 response', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
			</p>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Each option below is optional. The short explanations tell you what changes on your site when you tick the box.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<div class="tsosk-check-option">
				<label class="tsosk-check-row">
					<input type="checkbox" id="tsosk-custom-404-hide-search" <?php checked( ! empty( $settings['hide_from_search'] ) ); ?>>
					<?php esc_html_e( 'Hide 404 page from front-end search results', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-check-option-desc">
					<?php esc_html_e( 'When a visitor searches your site, this page will not appear in the results. That way people only see it when they hit a broken link, not when they look for normal content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-check-option">
				<label class="tsosk-check-row">
					<input type="checkbox" id="tsosk-custom-404-hide-admin" <?php checked( ! empty( $settings['hide_from_admin'] ) ); ?>>
					<?php esc_html_e( 'Hide 404 page from the Pages list in admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-check-option-desc">
					<?php esc_html_e( 'The page still exists and works as your 404, but it disappears from Pages → All Pages so you are less likely to delete or edit it by mistake.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-check-option">
				<label class="tsosk-check-row">
					<input type="checkbox" id="tsosk-custom-404-force-direct" <?php checked( ! empty( $settings['force_direct_404'] ) ); ?>>
					<?php esc_html_e( 'Return 404 when the 404 page URL is opened directly', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-check-option-desc">
					<?php esc_html_e( 'If someone types the 404 page address in the browser (instead of landing on it from a bad link), they still get a “not found” response. Recommended: leave this on.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-check-option">
				<label class="tsosk-check-row">
					<input type="checkbox" id="tsosk-custom-404-disable-guess" <?php checked( ! empty( $settings['disable_url_guess'] ) ); ?>>
					<?php esc_html_e( 'Disable WordPress URL autocorrection guessing on 404', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-check-option-desc">
					<?php esc_html_e( 'WordPress can redirect a wrong URL to a similar page it thinks you meant. Enable this to turn off that guess and always show your custom 404 page instead.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-check-option">
				<label class="tsosk-check-row">
					<input type="checkbox" id="tsosk-custom-404-send-410" <?php checked( ! empty( $settings['send_410'] ) ); ?>>
					<?php esc_html_e( 'Send HTTP 410 (Gone) instead of 404', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description tsosk-check-option-desc">
					<?php esc_html_e( 'Your custom 404 page still appears exactly the same for visitors. Only the hidden HTTP code changes: 410 instead of 404. Use 410 when you removed content on purpose and want search engines to drop those URLs faster. For normal broken links, leave this off and keep 404.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Show the failed URL on the page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Add one of these inside your 404 page content to display the URL that caused the error.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<ol class="description" style="margin:0 0 12px 18px;">
				<li><?php esc_html_e( 'Open Pages and edit your selected 404 page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'In the block editor: click + → search “URL causing 404 error” (TSO Swiss Knife) and insert the block where you want the link shown.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Classic editor or a text/HTML block: paste a shortcode from the table below, e.g. [tsosk_404_url].', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Save the page. On a real 404, visitors see the broken URL they requested.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
			</ol>
			<table class="widefat tsosk-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Example output', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Full URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
						<td><code>[tsosk_404_url]</code> <?php esc_html_e( 'or', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> <code>[tsosk_404_url display="full"]</code></td>
						<td><code>https://example.com/missing?x=1</code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Path only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
						<td><code>[tsosk_404_url display="page"]</code></td>
						<td><code>missing/page</code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Domain + path', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td>
						<td><code>[tsosk_404_url display="domainpath"]</code></td>
						<td><code>example.com/missing/page</code></td>
					</tr>
				</tbody>
			</table>
			<p class="description" style="margin-top:10px;">
				<?php esc_html_e( 'Block editor: insert the “URL causing 404 error” block (TSO Swiss Knife).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>

		<div style="display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap;">
			<button type="button" class="button button-primary" id="tsosk-custom-404-save"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button type="button" class="button" id="tsosk-custom-404-preview"
			        data-preview-nonce="<?php echo esc_attr( $preview_nonce ); ?>"
			        data-home-url="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Preview 404 page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-custom-404-msg"></span>
		</div>
		<?php
	}
}

/**
 * Whether a custom 404 page is active.
 */
function tsosk_404_is_active(): bool {
	return class_exists( 'TSOSK_Mod_Custom_404' ) && TSOSK_Mod_Custom_404::get_instance()->is_active();
}

/**
 * Get the configured custom 404 page ID.
 */
function tsosk_404_get_page_id(): int {
	if ( ! class_exists( 'TSOSK_Mod_Custom_404' ) ) {
		return 0;
	}
	return TSOSK_Mod_Custom_404::get_instance()->get_page_id();
}

/**
 * Get the URL that caused the current 404 error.
 *
 * @param string $type full|page|domainpath.
 */
function tsosk_404_get_the_url( string $type = 'full' ): string {
	return TSOSK_Mod_Custom_404::get_the_url( $type );
}
