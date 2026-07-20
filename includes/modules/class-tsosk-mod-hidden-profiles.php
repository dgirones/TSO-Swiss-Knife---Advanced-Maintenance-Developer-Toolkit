<?php
/**
 * TSO Swiss Knife – Module: Hidden WordPress Profiles.
 *
 * Safe toggles for common WordPress constants (early config file) and runtime filters.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Hidden_Profiles
 */
class TSOSK_Mod_Hidden_Profiles {

	/** JSON config filename (stored under uploads/{plugin-slug}/config). */
	private const CONFIG_FILE = 'tsosk-profiles-flags.json';

	/** @deprecated Legacy PHP filename — migrated to JSON on read. */
	private const LEGACY_CONFIG_FILE = 'tsosk-profiles-flags.php';

	/** Runtime toggles stored in wp_options. */
	private const OPTION_KEY = 'tsosk_hidden_profiles';

	/** @var TSOSK_Mod_Hidden_Profiles|null */
	private static $instance = null;

	/** @var bool Whether runtime hooks were registered. */
	private static $runtime_booted = false;

	/**
	 * @return TSOSK_Mod_Hidden_Profiles
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_hidden_profiles_save', array( $this, 'ajax_save' ) );
	}

	/**
	 * Apply runtime filters (emojis, XML-RPC, etc.) on the front end and admin.
	 */
	public function init(): void {
		self::boot_runtime_hooks();
	}

	/**
	 * Register runtime hooks once per request.
	 */
	public static function boot_runtime_hooks(): void {
		if ( self::$runtime_booted ) {
			return;
		}
		self::$runtime_booted = true;

		$flags = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $flags ) ) {
			return;
		}

		if ( ! empty( $flags['disable_emojis'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'tiny_mce_plugins', array( __CLASS__, 'disable_emojis_tinymce' ) );
			add_filter( 'wp_resource_hints', array( __CLASS__, 'disable_emojis_dns_prefetch' ), 10, 2 );
		}

		if ( ! empty( $flags['disable_embeds'] ) ) {
			remove_action( 'rest_api_init', 'wp_oembed_register_route' );
			remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_filter( 'embed_oembed_discover', '__return_false' );
			add_filter( 'tiny_mce_plugins', array( __CLASS__, 'disable_embeds_tinymce' ) );
			add_filter( 'rewrite_rules_array', array( __CLASS__, 'disable_embeds_rewrites' ) );
		}

		if ( ! empty( $flags['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}

		if ( ! empty( $flags['disable_feeds'] ) ) {
			add_action( 'do_feed', array( __CLASS__, 'disable_feeds_redirect' ), 1 );
			add_action( 'do_feed_rdf', array( __CLASS__, 'disable_feeds_redirect' ), 1 );
			add_action( 'do_feed_rss', array( __CLASS__, 'disable_feeds_redirect' ), 1 );
			add_action( 'do_feed_rss2', array( __CLASS__, 'disable_feeds_redirect' ), 1 );
			add_action( 'do_feed_atom', array( __CLASS__, 'disable_feeds_redirect' ), 1 );
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( ! empty( $flags['close_comments'] ) ) {
			add_filter( 'pre_option_default_comment_status', array( __CLASS__, 'filter_closed_comment_status' ) );
			add_filter( 'pre_option_default_ping_status', array( __CLASS__, 'filter_closed_comment_status' ) );
		}
	}

	/**
	 * @param array $plugins TinyMCE plugins.
	 * @return array
	 */
	public static function disable_emojis_tinymce( array $plugins ): array {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return $plugins;
	}

	/**
	 * @param array  $urls          URLs.
	 * @param string $relation_type Relation type.
	 * @return array
	 */
	public static function disable_emojis_dns_prefetch( array $urls, string $relation_type ): array {
		if ( 'dns-prefetch' === $relation_type ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter hook.
			$emoji_svg = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );
			foreach ( $urls as $key => $url ) {
				if ( is_string( $url ) && str_contains( $url, $emoji_svg ) ) {
					unset( $urls[ $key ] );
				}
			}
		}
		return $urls;
	}

	/**
	 * @param array $plugins TinyMCE plugins.
	 * @return array
	 */
	public static function disable_embeds_tinymce( array $plugins ): array {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpembed' ) );
		}
		return $plugins;
	}

	/**
	 * @param array $rules Rewrite rules.
	 * @return array
	 */
	public static function disable_embeds_rewrites( array $rules ): array {
		foreach ( $rules as $rule => $rewrite ) {
			if ( false !== strpos( $rewrite, 'embed=true' ) ) {
				unset( $rules[ $rule ] );
			}
		}
		return $rules;
	}

	/**
	 * Redirect feed requests to the home page.
	 */
	public static function disable_feeds_redirect(): void {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * @return string
	 */
	public static function filter_closed_comment_status(): string {
		return 'closed';
	}

	/**
	 * AJAX: save profile settings.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_hidden_profiles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$old_constants = $this->get_saved_constants();
		$old_runtime   = $this->get_saved_runtime();
		$constants     = $this->parse_constants_from_post();
		$runtime       = $this->parse_runtime_from_post();
		$existing      = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$runtime = array_merge( $existing, $runtime );

		$file_result = $this->write_config_file( $constants );
		if ( is_wp_error( $file_result ) ) {
			wp_send_json_error( $file_result->get_error_message() );
		}

		update_option( self::OPTION_KEY, $runtime, false );

		TSOSK_Activity_Log::log(
			'hidden-profiles',
			'save',
			$this->build_save_activity_message( $old_constants, $constants, $old_runtime, $runtime )
		);

		wp_send_json_success(
			__( 'Hidden WordPress profiles saved. Reload the page if constant values do not update immediately.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);
	}

	/**
	 * Human-readable labels for runtime toggles (activity log and UI).
	 *
	 * @return array<string, string>
	 */
	private function get_runtime_toggle_labels(): array {
		return array(
			'disable_emojis' => __( 'Disable emojis', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disable_embeds' => __( 'Disable oEmbed / embeds', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'close_comments' => __( 'Close new comments & pings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Build a detailed activity-log message from before/after profile settings.
	 *
	 * @param array<string, mixed> $old_constants Previous constants from config file.
	 * @param array<string, mixed> $new_constants New constants from the save request.
	 * @param array<string, bool>  $old_runtime   Previous runtime toggles.
	 * @param array<string, bool>  $new_runtime   New runtime toggles.
	 * @return string
	 */
	private function build_save_activity_message(
		array $old_constants,
		array $new_constants,
		array $old_runtime,
		array $new_runtime
	): string {
		$changes = array();

		foreach ( array( 'DISABLE_WP_CRON', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS' ) as $constant ) {
			$was_on = ! empty( $old_constants[ $constant ] );
			$is_on  = ! empty( $new_constants[ $constant ] );
			if ( $was_on === $is_on ) {
				continue;
			}
			$changes[] = sprintf(
				/* translators: 1: constant name, 2: enabled or disabled */
				__( '%1$s %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$constant,
				$is_on ? __( 'enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		foreach ( array( 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS' ) as $constant ) {
			$old_value = $old_constants[ $constant ] ?? null;
			$new_value = $new_constants[ $constant ] ?? null;
			$was_on    = null !== $old_value && false !== $old_value;
			$is_on     = null !== $new_value && false !== $new_value;
			$old_int   = $was_on ? (int) $old_value : null;
			$new_int   = $is_on ? (int) $new_value : null;

			if ( $was_on === $is_on && $old_int === $new_int ) {
				continue;
			}

			if ( ! $was_on && $is_on ) {
				$changes[] = sprintf(
					/* translators: 1: constant name, 2: numeric value */
					__( '%1$s enabled (%2$d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$constant,
					$new_int
				);
			} elseif ( $was_on && ! $is_on ) {
				$changes[] = sprintf(
					/* translators: %s: constant name */
					__( '%s disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$constant
				);
			} else {
				$changes[] = sprintf(
					/* translators: 1: constant name, 2: old value, 3: new value */
					__( '%1$s changed from %2$d to %3$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$constant,
					$old_int,
					$new_int
				);
			}
		}

		foreach ( $this->get_runtime_toggle_labels() as $key => $label ) {
			$was_on = ! empty( $old_runtime[ $key ] );
			$is_on  = ! empty( $new_runtime[ $key ] );
			if ( $was_on === $is_on ) {
				continue;
			}
			$changes[] = sprintf(
				/* translators: 1: option label, 2: enabled or disabled */
				__( '%1$s %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$label,
				$is_on ? __( 'enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		if ( empty( $changes ) ) {
			return __( 'Hidden WordPress profiles settings saved (no changes).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return implode( '; ', $changes ) . '.';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_constants_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save().
		$revisions_count = isset( $_POST['tsosk_hp_revisions_count'] )
			? max( 0, min( 100, absint( wp_unslash( $_POST['tsosk_hp_revisions_count'] ) ) ) )
			: 5;
		$autosave_secs   = isset( $_POST['tsosk_hp_autosave_seconds'] )
			? max( 60, min( 3600, absint( wp_unslash( $_POST['tsosk_hp_autosave_seconds'] ) ) ) )
			: 300;
		$trash_days      = isset( $_POST['tsosk_hp_trash_days'] )
			? max( 0, min( 365, absint( wp_unslash( $_POST['tsosk_hp_trash_days'] ) ) ) )
			: 7;

		$constants = array(
			'DISABLE_WP_CRON'     => ! empty( $_POST['tsosk_hp_disable_wp_cron'] ),
			'CONCATENATE_SCRIPTS' => ! empty( $_POST['tsosk_hp_concatenate_scripts'] ),
			'COMPRESS_SCRIPTS'    => ! empty( $_POST['tsosk_hp_compress_scripts'] ),
			'WP_POST_REVISIONS'   => ! empty( $_POST['tsosk_hp_limit_revisions'] ) ? $revisions_count : null,
			'AUTOSAVE_INTERVAL'   => ! empty( $_POST['tsosk_hp_slow_autosave'] ) ? $autosave_secs : null,
			'EMPTY_TRASH_DAYS'    => ! empty( $_POST['tsosk_hp_empty_trash'] ) ? $trash_days : null,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $constants;
	}

	/**
	 * @return array<string, bool>
	 */
	private function parse_runtime_from_post(): array {
		$keys = array(
			'disable_emojis',
			'disable_embeds',
			'close_comments',
		);
		$out  = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save().
		foreach ( $keys as $key ) {
			$out[ $key ] = ! empty( $_POST[ 'tsosk_hp_rt_' . $key ] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $out;
	}

	/**
	 * @param array<string, mixed> $constants Constant => bool|int|null.
	 * @return true|WP_Error
	 */
	private function write_config_file( array $constants ) {
		return TSOSK_Config_Storage::save_profiles_constants( $constants );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_saved_constants(): array {
		return TSOSK_Config_Storage::get_profiles_constants();
	}

	/**
	 * @return array<string, bool>
	 */
	private function get_saved_runtime(): array {
		$defaults = array(
			'disable_emojis'  => false,
			'disable_embeds'  => false,
			'disable_xmlrpc'  => false,
			'disable_feeds'   => false,
			'close_comments'  => false,
		);
		$stored   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		foreach ( $defaults as $key => $val ) {
			$defaults[ $key ] = ! empty( $stored[ $key ] );
		}
		return $defaults;
	}

	/**
	 * Whether a constant is locked by wp-config.php (already defined before our file).
	 *
	 * @param string $constant Constant name.
	 * @return bool
	 */
	private function constant_locked_in_wpconfig( string $constant ): bool {
		if ( ! defined( $constant ) ) {
			return false;
		}
		return ! TSOSK_Config_Storage::constant_defined_in_tsosk_config( $constant );
	}

	/**
	 * Render admin UI.
	 */
	public function render(): void {
		$nonce     = wp_create_nonce( 'tsosk_hidden_profiles_nonce' );
		$constants = $this->get_saved_constants();
		$runtime   = $this->get_saved_runtime();
		$config_ok     = TSOSK_Config_Storage::json_exists( TSOSK_Config_Storage::PROFILES_JSON );
		$legacy_exists = file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . TSOSK_Config_Storage::LEGACY_PROFILES );
		$constants_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=constants' );
		?>
		<div id="tsosk-hp-panel">
		<p class="tsosk-desc">
			<?php esc_html_e( 'Activate safe WordPress performance and privacy tweaks in one place. Constants are saved as JSON under the plugin uploads folder and loaded before plugins. Runtime filters apply on the next page load without editing wp-config.php.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-info">
			<?php
			printf(
				/* translators: 1: constants tab link open, 2: link close */
				esc_html__( 'For a full read-only list of defined constants, open %1$sWP Constants%2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'<a href="' . esc_url( $constants_url ) . '">',
				'</a>'
			);
			?>
		</div>

		<?php if ( $config_ok ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php
			printf(
				/* translators: %s: file path */
				esc_html__( 'Active profiles config: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'<code>' . esc_html( trailingslashit( TSOSK_CONFIG_DIR ) . TSOSK_Config_Storage::PROFILES_JSON ) . '</code>'
			);
			?>
		</div>
		<?php endif; ?>

		<?php if ( $legacy_exists ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php
			printf(
				/* translators: %s: legacy file path */
				esc_html__( 'Legacy file found in mu-plugins: %s — save settings once to migrate it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'<code>' . esc_html( trailingslashit( WPMU_PLUGIN_DIR ) . TSOSK_Config_Storage::LEGACY_PROFILES ) . '</code>'
			);
			?>
		</div>
		<?php endif; ?>

		<div class="tsosk-card tsosk-hp-presets">
			<h3><?php esc_html_e( 'Quick presets', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description tsosk-hp-section-desc"><?php esc_html_e( 'Check the boxes below, then click Save. Presets only select options — nothing is applied until you save.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-hp-preset-btns">
				<button type="button" class="button" data-tsosk-hp-preset="performance"><?php esc_html_e( 'Performance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button" data-tsosk-hp-preset="content"><?php esc_html_e( 'Content & trash', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button" data-tsosk-hp-preset="privacy"><?php esc_html_e( 'Privacy & surface', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<button type="button" class="button" data-tsosk-hp-preset="clear"><?php esc_html_e( 'Clear selection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Constants (uploads config)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description tsosk-hp-section-desc"><?php esc_html_e( 'Requires a full page reload (or new request) after saving for constant values to take effect. Values already set in wp-config.php cannot be overridden here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<div class="tsosk-hp-legend">
				<p><strong><?php esc_html_e( 'How to read each row', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></p>
				<ul class="tsosk-hp-legend-list">
					<li><?php esc_html_e( 'Tick the box to enable that constant in the plugin config file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					<li><?php esc_html_e( 'For numbers: the input box is the new value that will be saved when you click Save.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					<li><?php esc_html_e( 'The green badge is the value active on your site right now (from wp-config.php or WordPress default) — not the value in the input box.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
					<li><?php esc_html_e( 'The blue “wp-config.php” badge means that constant is locked in wp-config.php and cannot be changed from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				</ul>
			</div>
			<?php
			$this->render_bool_toggle(
				'disable_wp_cron',
				'DISABLE_WP_CRON',
				__( 'Disable WordPress pseudo-cron on page visits. Use a real server cron calling wp-cron.php instead.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$constants['DISABLE_WP_CRON'],
				$this->constant_locked_in_wpconfig( 'DISABLE_WP_CRON' )
			);
			$this->render_bool_toggle(
				'concatenate_scripts',
				'CONCATENATE_SCRIPTS',
				__( 'Concatenate admin scripts (legacy optimization; may conflict with some plugins).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$constants['CONCATENATE_SCRIPTS'],
				$this->constant_locked_in_wpconfig( 'CONCATENATE_SCRIPTS' )
			);
			$this->render_bool_toggle(
				'compress_scripts',
				'COMPRESS_SCRIPTS',
				__( 'Compress admin scripts (deprecated in modern WordPress; use only if you know you need it).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$constants['COMPRESS_SCRIPTS'],
				$this->constant_locked_in_wpconfig( 'COMPRESS_SCRIPTS' )
			);

			$this->render_numeric_toggle(
				'limit_revisions',
				'WP_POST_REVISIONS',
				'revisions_count',
				__( 'Limit post revisions stored in the database.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				null !== $constants['WP_POST_REVISIONS'],
				$constants['WP_POST_REVISIONS'] ?? 5,
				$this->constant_locked_in_wpconfig( 'WP_POST_REVISIONS' )
			);

			$this->render_numeric_toggle(
				'slow_autosave',
				'AUTOSAVE_INTERVAL',
				'autosave_seconds',
				__( 'Increase autosave interval (seconds) to reduce editor autosave load.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				null !== $constants['AUTOSAVE_INTERVAL'],
				$constants['AUTOSAVE_INTERVAL'] ?? 300,
				$this->constant_locked_in_wpconfig( 'AUTOSAVE_INTERVAL' )
			);

			$this->render_numeric_toggle(
				'empty_trash',
				'EMPTY_TRASH_DAYS',
				'trash_days',
				__( 'Automatically empty trash after N days (0 = disable trash expiry).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				null !== $constants['EMPTY_TRASH_DAYS'],
				$constants['EMPTY_TRASH_DAYS'] ?? 7,
				$this->constant_locked_in_wpconfig( 'EMPTY_TRASH_DAYS' )
			);
			?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Runtime filters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description tsosk-hp-section-desc"><?php esc_html_e( 'Applied via WordPress hooks on the next request after saving. No server reload required. XML-RPC and RSS feeds are configured under Security Review; REST API under REST API.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<?php
			$runtime_descs = array(
				'disable_emojis' => __( 'Removes emoji scripts and styles from the front end and admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'disable_embeds' => __( 'Stops WordPress from embedding external content and related discovery tags.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'close_comments' => __( 'Sets default comment and ping status to closed for new content (does not change existing posts).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
			$runtime_toggles = array();
			foreach ( $this->get_runtime_toggle_labels() as $key => $label ) {
				$runtime_toggles[ $key ] = array(
					'label' => $label,
					'desc'  => $runtime_descs[ $key ] ?? '',
				);
			}
			foreach ( $runtime_toggles as $key => $toggle ) :
				?>
				<div class="tsosk-hp-field">
					<label class="tsosk-hp-field-label">
						<div class="tsosk-hp-field-header">
							<input type="checkbox"
							       name="tsosk_hp_rt_<?php echo esc_attr( $key ); ?>"
							       id="tsosk-hp-rt-<?php echo esc_attr( $key ); ?>"
							       data-hp-field="rt_<?php echo esc_attr( $key ); ?>"
							       <?php checked( $runtime[ $key ] ); ?>>
							<strong><?php echo esc_html( $toggle['label'] ); ?></strong>
							<?php if ( $runtime[ $key ] ) : ?>
								<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="description tsosk-hp-field-desc"><?php echo esc_html( $toggle['desc'] ); ?></p>
					</label>
				</div>
				<?php
			endforeach;
			?>
		</div>

		<p>
			<button type="button" class="button button-primary" id="tsosk-hp-save"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>"
			        data-save-label="<?php esc_attr_e( 'Save profiles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				<?php esc_html_e( 'Save profiles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-hp-msg"></span>
		</p>
		</div><!-- #tsosk-hp-panel -->
		<?php
	}

	/**
	 * @param string $field_id   Field id suffix.
	 * @param string $constant   Constant name for display.
	 * @param string $desc       Description.
	 * @param bool   $checked    Saved state.
	 * @param bool   $locked     Locked by wp-config.
	 */
	private function render_bool_toggle( string $field_id, string $constant, string $desc, bool $checked, bool $locked ): void {
		?>
		<div class="tsosk-hp-field">
			<label class="tsosk-hp-field-label">
				<div class="tsosk-hp-field-header">
					<input type="checkbox"
					       name="tsosk_hp_<?php echo esc_attr( $field_id ); ?>"
					       id="tsosk-hp-<?php echo esc_attr( $field_id ); ?>"
					       data-hp-field="<?php echo esc_attr( $field_id ); ?>"
					       <?php checked( $checked ); ?>
					       <?php disabled( $locked ); ?>>
					<code><strong><?php echo esc_html( $constant ); ?></strong></code>
					<?php if ( defined( $constant ) && constant( $constant ) ) : ?>
						<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php else : ?>
						<span class="tsosk-badge"><?php esc_html_e( 'OFF', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php endif; ?>
					<?php if ( $locked ) : ?>
						<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="description tsosk-hp-field-desc"><?php echo esc_html( $desc ); ?></p>
			</label>
		</div>
		<?php
	}

	/**
	 * @param string $field_id     Checkbox field id.
	 * @param string $constant     Constant name.
	 * @param string $number_field Number input field id.
	 * @param string $desc         Description.
	 * @param bool   $checked      Enable numeric define.
	 * @param int    $number       Saved number.
	 * @param bool   $locked       Locked by wp-config.
	 */
	private function render_numeric_toggle( string $field_id, string $constant, string $number_field, string $desc, bool $checked, int $number, bool $locked ): void {
		$live_value = defined( $constant ) ? constant( $constant ) : null;
		?>
		<div class="tsosk-hp-field">
			<label class="tsosk-hp-field-label">
				<div class="tsosk-hp-field-header">
					<input type="checkbox"
					       name="tsosk_hp_<?php echo esc_attr( $field_id ); ?>"
					       id="tsosk-hp-<?php echo esc_attr( $field_id ); ?>"
					       data-hp-field="<?php echo esc_attr( $field_id ); ?>"
					       <?php checked( $checked ); ?>
					       <?php disabled( $locked ); ?>>
					<code><strong><?php echo esc_html( $constant ); ?></strong></code>
					<span class="tsosk-hp-input-wrap">
						<span class="tsosk-hp-mini-label"><?php esc_html_e( 'New value if saved', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<input type="number"
						       name="tsosk_hp_<?php echo esc_attr( $number_field ); ?>"
						       id="tsosk-hp-<?php echo esc_attr( $number_field ); ?>"
						       data-hp-field="<?php echo esc_attr( $number_field ); ?>"
						       value="<?php echo esc_attr( (string) $number ); ?>"
						       min="0"
						       max="9999"
						       class="tsosk-hp-number-input"
						       <?php disabled( $locked ); ?>>
					</span>
					<?php if ( null !== $live_value && '' !== (string) $live_value ) : ?>
						<span class="tsosk-hp-live-value">
							<span class="tsosk-hp-mini-label"><?php esc_html_e( 'Current on site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
							<span class="tsosk-badge tsosk-badge-ok" title="<?php esc_attr_e( 'Value active right now on this site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<?php echo esc_html( is_bool( $live_value ) ? ( $live_value ? 'true' : 'false' ) : (string) $live_value ); ?>
							</span>
						</span>
					<?php endif; ?>
					<?php if ( $locked ) : ?>
						<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="description tsosk-hp-field-desc"><?php echo esc_html( $desc ); ?></p>
			</label>
		</div>
		<?php
	}
}
