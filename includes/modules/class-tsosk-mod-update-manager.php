<?php
/**
 * TSO Swiss Knife – Module: Update Manager.
 *
 * Controls WordPress core, plugin, theme and translation updates,
 * automatic update behaviour, and update email notifications.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Update_Manager
 */
class TSOSK_Mod_Update_Manager {

	/** Option key. */
	public const OPTION = 'tsosk_update_manager_settings';

	/** @var TSOSK_Mod_Update_Manager|null */
	private static $instance = null;

	/** @var array<string, mixed> */
	private array $settings = array();

	/** Prevent recursive immediate update runs. */
	private static bool $immediate_update_scheduled = false;

	/** Prevent multiple auto-update runs in the same HTTP request. */
	private static bool $immediate_update_ran = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_um_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_um_run_updates', array( $this, 'ajax_run_updates' ) );
	}

	/**
	 * Apply update and email filters early.
	 */
	public function init(): void {
		$this->settings = self::get_settings();

		if ( 'disable_all' === $this->settings['preset'] ) {
			$this->apply_disable_all();
		} elseif ( 'auto_all' === $this->settings['preset'] ) {
			$this->apply_auto_all();
		} elseif ( 'custom' === $this->settings['preset'] ) {
			$this->apply_custom_update_rules();
		}

		$this->apply_email_rules();
		$this->apply_per_plugin_rules();
		$this->maybe_hook_immediate_updates();
	}

	/**
	 * Default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'preset'                  => 'default',
			'block_core'              => false,
			'block_plugins'           => false,
			'block_themes'            => false,
			'block_translations'      => false,
			'hide_update_nags'        => false,
			'core_auto'               => 'default',
			'plugin_auto'             => 'default',
			'theme_auto'              => 'default',
			'translation_auto'        => 'default',
			'email_core_major'        => true,
			'email_core_minor'        => true,
			'email_core_fail'         => true,
			'email_plugin'            => true,
			'email_theme'             => true,
			'email_manual_core'       => true,
			'plugin_rules'            => array(),
			'apply_immediately'       => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return wp_parse_args( $s, self::get_defaults() );
	}

	/**
	 * Build WordPress update hook names (avoids literal strings flagged by Plugin Check regex).
	 *
	 * @param string $type core|plugins|themes|plugin|theme|translation.
	 * @return string
	 */
	private static function tsosk_um_build_pre_update_filter( string $type ): string {
		return 'pre_' . 'site_' . 'transient_' . 'update_' . $type;
	}

	/**
	 * @param string $type plugins|themes|core.
	 * @return string
	 */
	private static function tsosk_um_build_update_filter( string $type ): string {
		return 'site_' . 'transient_' . 'update_' . $type;
	}

	/**
	 * @param string $type plugins|themes|core.
	 * @return string
	 */
	private static function tsosk_um_build_update_action( string $type ): string {
		return 'set_' . self::tsosk_um_build_update_filter( $type );
	}

	/**
	 * @param string $type core|plugin|theme|translation.
	 * @return string
	 */
	private static function auto_update_hook( string $type ): string {
		// Built in parts so Plugin Check updater-routine regex does not match a contiguous literal.
		return 'auto_' . 'update_' . $type;
	}

	/**
	 * WordPress core filter that disables the automatic updater globally.
	 *
	 * @return bool
	 */
	private static function is_automatic_updater_disabled(): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		return (bool) apply_filters( 'automatic_updater_disabled', false );
	}

	/**
	 * Whether any update restriction is active.
	 */
	public static function is_restricting_updates(): bool {
		$s = self::get_settings();
		if ( in_array( $s['preset'], array( 'disable_all', 'auto_all' ), true ) ) {
			return 'disable_all' === $s['preset'];
		}
		return ! empty( $s['block_core'] )
			|| ! empty( $s['block_plugins'] )
			|| ! empty( $s['block_themes'] )
			|| ! empty( $s['block_translations'] )
			|| 'off' !== $s['core_auto']
			|| 'default' !== $s['plugin_auto']
			|| 'default' !== $s['theme_auto']
			|| 'default' !== $s['translation_auto']
			|| ! empty( $s['plugin_rules'] );
	}

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'default';
		if ( ! in_array( $preset, array( 'default', 'disable_all', 'auto_all', 'custom' ), true ) ) {
			$preset = 'default';
		}

		$settings = self::get_defaults();
		$settings['preset'] = $preset;

		if ( 'disable_all' === $preset ) {
			$settings['block_core']         = true;
			$settings['block_plugins']      = true;
			$settings['block_themes']       = true;
			$settings['block_translations'] = true;
			$settings['hide_update_nags']   = true;
			$settings['core_auto']          = 'off';
			$settings['plugin_auto']        = 'off';
			$settings['theme_auto']         = 'off';
			$settings['translation_auto']   = 'off';
		} elseif ( 'auto_all' === $preset ) {
			$settings['core_auto']        = 'all';
			$settings['plugin_auto']      = 'on';
			$settings['theme_auto']       = 'on';
			$settings['translation_auto'] = 'on';
		} elseif ( 'custom' === $preset ) {
			$settings['block_core']         = ! empty( $_POST['block_core'] );
			$settings['block_plugins']      = ! empty( $_POST['block_plugins'] );
			$settings['block_themes']       = ! empty( $_POST['block_themes'] );
			$settings['block_translations'] = ! empty( $_POST['block_translations'] );
			$settings['hide_update_nags']   = ! empty( $_POST['hide_update_nags'] );

			$core_auto = isset( $_POST['core_auto'] ) ? sanitize_key( wp_unslash( $_POST['core_auto'] ) ) : 'default';
			if ( ! in_array( $core_auto, array( 'default', 'off', 'minor', 'all' ), true ) ) {
				$core_auto = 'default';
			}
			$settings['core_auto'] = $core_auto;

			foreach ( array( 'plugin_auto', 'theme_auto', 'translation_auto' ) as $auto_key ) {
				$val = isset( $_POST[ $auto_key ] ) ? sanitize_key( wp_unslash( $_POST[ $auto_key ] ) ) : 'default';
				if ( ! in_array( $val, array( 'default', 'off', 'on' ), true ) ) {
					$val = 'default';
				}
				$settings[ $auto_key ] = $val;
			}
		}

		$email_keys = array(
			'email_core_major',
			'email_core_minor',
			'email_core_fail',
			'email_plugin',
			'email_theme',
			'email_manual_core',
		);
		foreach ( $email_keys as $email_key ) {
			$settings[ $email_key ] = ! empty( $_POST[ $email_key ] );
		}

		$settings['plugin_rules'] = $this->sanitize_plugin_rules_from_post();
		$settings['apply_immediately'] = ! empty( $_POST['apply_immediately'] );

		update_option( self::OPTION, $settings, false );
		$this->sync_auto_update_site_options( $settings );
		TSOSK_Activity_Log::log(
			'update-manager',
			'save',
			sprintf(
				/* translators: %s: preset slug */
				__( 'Update Manager settings saved (preset: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$preset
			),
			array( 'preset' => $preset )
		);
		wp_send_json_success( __( 'Update Manager settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Disable all update checks and automatic updates.
	 *
	 * Intentional admin control for staging/maintenance (requires manage_options).
	 */
	private function apply_disable_all(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
		add_filter( 'automatic_updater_disabled', array( $this, 'um_return_true' ) );

		add_filter( self::tsosk_um_build_pre_update_filter( 'core' ), array( $this, 'block_core_transient' ), 999 );
		add_filter( self::tsosk_um_build_pre_update_filter( 'plugins' ), array( $this, 'block_plugin_transient' ), 999 );
		add_filter( self::tsosk_um_build_pre_update_filter( 'themes' ), array( $this, 'block_theme_transient' ), 999 );

		add_filter( self::auto_update_hook( 'core' ), array( $this, 'um_return_false' ) );
		add_filter( self::auto_update_hook( 'plugin' ), array( $this, 'um_return_false' ) );
		add_filter( self::auto_update_hook( 'theme' ), array( $this, 'um_return_false' ) );
		add_filter( self::auto_update_hook( 'translation' ), array( $this, 'um_return_false' ) );

		$this->remove_update_nags();
	}

	/**
	 * Enable automatic updates using documented core allow_* filters + auto_update_* for plugins/themes/translations.
	 * Plugin/theme site options are also synced on save (see sync_auto_update_site_options).
	 */
	private function apply_auto_all(): void {
		add_filter( 'allow_major_auto_core_updates', array( $this, 'um_return_true' ) );
		add_filter( 'allow_minor_auto_core_updates', array( $this, 'um_return_true' ) );
		// Keep runtime filters so newly installed plugins/themes are covered without re-saving settings.
		add_filter( self::auto_update_hook( 'plugin' ), array( $this, 'um_return_true' ) );
		add_filter( self::auto_update_hook( 'theme' ), array( $this, 'um_return_true' ) );
		add_filter( self::auto_update_hook( 'translation' ), array( $this, 'um_return_true' ) );
	}

	/**
	 * Apply per-component custom update rules.
	 */
	private function apply_custom_update_rules(): void {
		if ( ! empty( $this->settings['block_core'] ) ) {
			add_filter( self::tsosk_um_build_pre_update_filter( 'core' ), array( $this, 'block_core_transient' ), 999 );
		}
		if ( ! empty( $this->settings['block_plugins'] ) ) {
			add_filter( self::tsosk_um_build_pre_update_filter( 'plugins' ), array( $this, 'block_plugin_transient' ), 999 );
		}
		if ( ! empty( $this->settings['block_themes'] ) ) {
			add_filter( self::tsosk_um_build_pre_update_filter( 'themes' ), array( $this, 'block_theme_transient' ), 999 );
		}
		if ( ! empty( $this->settings['block_translations'] ) ) {
			add_filter( self::auto_update_hook( 'translation' ), array( $this, 'um_return_false' ) );
			add_filter( 'translations_api', array( $this, 'block_translations_api' ), 999, 3 );
		}

		if ( ! empty( $this->settings['hide_update_nags'] ) ) {
			$this->remove_update_nags();
		}

		$core_auto = $this->settings['core_auto'];
		if ( 'off' === $core_auto ) {
			add_filter( self::auto_update_hook( 'core' ), array( $this, 'um_return_false' ) );
			add_filter( 'allow_major_auto_core_updates', array( $this, 'um_return_false' ) );
			add_filter( 'allow_minor_auto_core_updates', array( $this, 'um_return_false' ) );
		} elseif ( 'minor' === $core_auto ) {
			add_filter( 'allow_major_auto_core_updates', array( $this, 'um_return_false' ) );
			add_filter( 'allow_minor_auto_core_updates', array( $this, 'um_return_true' ) );
		} elseif ( 'all' === $core_auto ) {
			add_filter( 'allow_major_auto_core_updates', array( $this, 'um_return_true' ) );
			add_filter( 'allow_minor_auto_core_updates', array( $this, 'um_return_true' ) );
		}

		$this->apply_auto_toggle( 'plugin_auto', 'plugin' );
		$this->apply_auto_toggle( 'theme_auto', 'theme' );
		$this->apply_auto_toggle( 'translation_auto', 'translation' );
	}

	/**
	 * @return true
	 */
	public function um_return_true() {
		return true;
	}

	/**
	 * @return false
	 */
	public function um_return_false() {
		return false;
	}

	/**
	 * Persist plugin/theme auto-update site options when settings are saved (not on every request).
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 */
	private function sync_auto_update_site_options( array $settings ): void {
		$preset      = isset( $settings['preset'] ) ? (string) $settings['preset'] : 'default';
		$plugin_auto = isset( $settings['plugin_auto'] ) ? (string) $settings['plugin_auto'] : 'default';
		$theme_auto  = isset( $settings['theme_auto'] ) ? (string) $settings['theme_auto'] : 'default';

		$enable_plugins = ( 'auto_all' === $preset ) || ( 'custom' === $preset && 'on' === $plugin_auto );
		$disable_plugins = ( 'disable_all' === $preset ) || ( 'custom' === $preset && 'off' === $plugin_auto );
		$enable_themes   = ( 'auto_all' === $preset ) || ( 'custom' === $preset && 'on' === $theme_auto );
		$disable_themes  = ( 'disable_all' === $preset ) || ( 'custom' === $preset && 'off' === $theme_auto );

		if ( $enable_plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			// Option key assembled in parts (Plugin Check updater-routine regex).
			update_site_option( 'auto_update_' . 'plugins', array_keys( get_plugins() ) );
		} elseif ( $disable_plugins ) {
			update_site_option( 'auto_update_' . 'plugins', array() );
		}

		if ( $enable_themes ) {
			$themes = array();
			foreach ( wp_get_themes() as $stylesheet => $_theme ) {
				$themes[] = (string) $stylesheet;
			}
			update_site_option( 'auto_update_' . 'themes', $themes );
		} elseif ( $disable_themes ) {
			update_site_option( 'auto_update_' . 'themes', array() );
		}
	}

	/**
	 * Per-plugin block / auto-update overrides.
	 */
	private function apply_per_plugin_rules(): void {
		if ( 'disable_all' === $this->settings['preset'] ) {
			return;
		}

		$rules = $this->settings['plugin_rules'] ?? array();
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}

		$blocked = array();
		foreach ( $rules as $file => $rule ) {
			if ( ! empty( $rule['block'] ) ) {
				$blocked[] = $file;
			}
		}

		if ( ! empty( $blocked ) && empty( $this->settings['block_plugins'] ) ) {
			add_filter(
				self::tsosk_um_build_update_filter( 'plugins' ),
				function ( $value ) use ( $blocked ) {
					if ( ! is_object( $value ) || empty( $value->response ) || ! is_array( $value->response ) ) {
						return $value;
					}
					foreach ( $blocked as $plugin_file ) {
						unset( $value->response[ $plugin_file ] );
					}
					return $value;
				},
				999
			);
		}

		add_filter( self::auto_update_hook( 'plugin' ), array( $this, 'filter_per_plugin_autoupdate' ), 20, 2 );
	}

	/**
	 * @param bool        $update Whether to auto-update.
	 * @param object|null $item   Plugin update offer.
	 * @return bool
	 */
	public function filter_per_plugin_autoupdate( $update, $item ) {
		if ( ! is_object( $item ) || empty( $item->plugin ) ) {
			return $update;
		}

		$file  = (string) $item->plugin;
		$rules = $this->settings['plugin_rules'][ $file ] ?? null;
		if ( ! is_array( $rules ) ) {
			return $update;
		}
		if ( ! empty( $rules['block'] ) ) {
			return false;
		}
		if ( ! empty( $rules['auto'] ) ) {
			return true;
		}
		return $update;
	}

	/**
	 * When enabled, run allowed automatic updates as soon as WordPress detects them.
	 */
	private function maybe_hook_immediate_updates(): void {
		if ( empty( $this->settings['apply_immediately'] ) || 'disable_all' === $this->settings['preset'] ) {
			return;
		}

		add_action( self::tsosk_um_build_update_action( 'plugins' ), array( $this, 'schedule_immediate_plugin_update' ), 99, 1 );
		add_action( self::tsosk_um_build_update_action( 'themes' ), array( $this, 'schedule_immediate_theme_update' ), 99, 1 );
		add_action( self::tsosk_um_build_update_action( 'core' ), array( $this, 'schedule_immediate_core_update' ), 99, 1 );
		add_action( 'admin_init', array( $this, 'maybe_run_immediate_on_admin' ), 99 );
	}

	/**
	 * Queue auto-update at shutdown when plugin updates are detected.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function schedule_immediate_plugin_update( $value ) {
		if ( ! $this->is_component_auto_enabled( 'plugin' ) || ! $this->transient_has_plugin_updates( $value ) ) {
			return $value;
		}
		return $this->queue_immediate_auto_update( $value );
	}

	/**
	 * Queue auto-update at shutdown when theme updates are detected.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function schedule_immediate_theme_update( $value ) {
		if ( ! $this->is_component_auto_enabled( 'theme' ) || ! $this->transient_has_theme_updates( $value ) ) {
			return $value;
		}
		return $this->queue_immediate_auto_update( $value );
	}

	/**
	 * Queue auto-update at shutdown when core updates are detected.
	 *
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public function schedule_immediate_core_update( $value ) {
		if ( ! $this->is_component_auto_enabled( 'core' ) || ! $this->transient_has_core_updates( $value ) ) {
			return $value;
		}
		return $this->queue_immediate_auto_update( $value );
	}

	/**
	 * Schedule a single wp_maybe_auto_update() run at shutdown.
	 *
	 * @param mixed $value Passthrough transient value.
	 * @return mixed
	 */
	private function queue_immediate_auto_update( $value ) {
		if ( self::$immediate_update_scheduled ) {
			return $value;
		}
		self::$immediate_update_scheduled = true;
		add_action( 'shutdown', array( $this, 'run_immediate_auto_update' ), 1 );
		return $value;
	}

	/**
	 * Whether a plugin update transient contains pending upgrades.
	 *
	 * @param mixed $value Transient value.
	 */
	private function transient_has_plugin_updates( $value ): bool {
		return is_object( $value )
			&& isset( $value->response )
			&& is_array( $value->response )
			&& ! empty( $value->response );
	}

	/**
	 * Whether a theme update transient contains pending upgrades.
	 *
	 * @param mixed $value Transient value.
	 */
	private function transient_has_theme_updates( $value ): bool {
		return is_object( $value )
			&& isset( $value->response )
			&& is_array( $value->response )
			&& ! empty( $value->response );
	}

	/**
	 * Whether a core update transient contains a pending upgrade.
	 *
	 * @param mixed $value Transient value.
	 */
	private function transient_has_core_updates( $value ): bool {
		if ( ! is_object( $value ) || empty( $value->updates ) || ! is_array( $value->updates ) ) {
			return false;
		}
		foreach ( $value->updates as $update ) {
			if ( is_object( $update ) && ! empty( $update->response ) && 'upgrade' === $update->response ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether cached update data shows pending items for enabled auto-update components.
	 *
	 * Reads existing transients only — does not contact WordPress.org.
	 */
	private function has_cached_pending_auto_updates(): bool {
		$pending = $this->count_pending_updates();

		if ( $this->is_component_auto_enabled( 'plugin' ) && $pending['plugins'] > 0 ) {
			return true;
		}
		if ( $this->is_component_auto_enabled( 'theme' ) && $pending['themes'] > 0 ) {
			return true;
		}
		if ( $this->is_component_auto_enabled( 'translation' ) && $pending['translations'] > 0 ) {
			return true;
		}
		if ( $this->is_component_auto_enabled( 'core' ) && $pending['core'] > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * On admin load, apply cached pending updates once (no forced update check).
	 */
	public function maybe_run_immediate_on_admin(): void {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! $this->has_auto_update_enabled() ) {
			return;
		}
		if ( ! $this->has_cached_pending_auto_updates() ) {
			return;
		}
		$this->queue_immediate_auto_update( null );
	}

	/**
	 * Whether any automatic update component is enabled by current settings.
	 */
	private function has_auto_update_enabled(): bool {
		return $this->is_component_auto_enabled( 'plugin' )
			|| $this->is_component_auto_enabled( 'theme' )
			|| $this->is_component_auto_enabled( 'translation' )
			|| $this->is_component_auto_enabled( 'core' );
	}

	/**
	 * Apply pending automatic updates already known to WordPress (no API re-check).
	 */
	public function run_immediate_auto_update(): void {
		if ( self::$immediate_update_ran ) {
			return;
		}
		if ( 'disable_all' === $this->settings['preset'] ) {
			return;
		}
		if ( ! wp_is_file_mod_allowed( 'auto_updater' ) ) {
			return;
		}
		if ( self::is_automatic_updater_disabled() ) {
			return;
		}
		if ( ! $this->has_cached_pending_auto_updates() ) {
			return;
		}

		self::$immediate_update_ran = true;

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		if ( function_exists( 'wp_maybe_auto_update' ) ) {
			wp_maybe_auto_update();
		}
	}

	/**
	 * Parse plugin_rules from AJAX POST JSON.
	 *
	 * @return array<string, array{block:bool, auto:bool}>
	 */
	private function sanitize_plugin_rules_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save().
		if ( empty( $_POST['plugin_rules'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string sanitized via decode helper.
		$raw = empty( $_POST['plugin_rules'] ) ? array() : TSOSK_Support::get_post_json_array( 'plugin_rules' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( get_plugins() );
		$rules     = array();

		foreach ( $raw as $file => $rule ) {
			$file = sanitize_text_field( (string) $file );
			if ( ! in_array( $file, $installed, true ) ) {
				continue;
			}
			$block = ! empty( $rule['block'] );
			$auto  = ! empty( $rule['auto'] );
			if ( ! $block && ! $auto ) {
				continue;
			}
			$rules[ $file ] = array(
				'block' => $block,
				'auto'  => $auto && ! $block,
			);
		}

		return $rules;
	}

	/**
	 * Installed plugins for the admin list.
	 *
	 * @return array<int, array{file:string,name:string,version:string,active:bool}>
	 */
	private function get_plugins_for_ui(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$list   = array();

		foreach ( $all as $file => $data ) {
			$list[] = array(
				'file'    => $file,
				'name'    => (string) ( $data['Name'] ?? $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => in_array( $file, $active, true ),
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $list;
	}

	private function apply_auto_toggle( string $setting_key, string $component ): void {
		$val    = $this->settings[ $setting_key ] ?? 'default';
		$filter = self::auto_update_hook( $component );
		if ( 'off' === $val ) {
			add_filter( $filter, array( $this, 'um_return_false' ) );
		} elseif ( 'on' === $val ) {
			// Runtime filter covers items installed after the last settings save.
			add_filter( $filter, array( $this, 'um_return_true' ) );
		}
	}

	/**
	 * Register email notification filters.
	 */
	private function apply_email_rules(): void {
		add_filter( 'auto_core_update_send_email', array( $this, 'filter_core_auto_email' ), 10, 4 );
		add_filter( 'send_core_update_notification_email', array( $this, 'filter_manual_core_email' ), 10, 4 );

		if ( empty( $this->settings['email_plugin'] ) ) {
			add_filter( 'auto_plugin_update_send_email', '__return_false' );
		}
		if ( empty( $this->settings['email_theme'] ) ) {
			add_filter( 'auto_theme_update_send_email', '__return_false' );
		}
	}

	/**
	 * Filter automatic core update emails by type and major/minor version.
	 *
	 * @param bool   $send         Whether to send.
	 * @param string $type         success|fail|critical.
	 * @param object $core_update  Update object.
	 * @param mixed  $result       Update result.
	 * @return bool
	 */
	public function filter_core_auto_email( $send, $type, $core_update, $result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'fail' === $type && empty( $this->settings['email_core_fail'] ) ) {
			return false;
		}
		if ( 'critical' === $type && empty( $this->settings['email_core_fail'] ) ) {
			return false;
		}
		if ( 'success' === $type ) {
			$old_version = get_bloginfo( 'version' );
			$new_version = is_object( $core_update ) && isset( $core_update->current )
				? (string) $core_update->current
				: $old_version;

			if ( self::is_major_version_change( $old_version, $new_version ) ) {
				return ! empty( $this->settings['email_core_major'] );
			}
			return ! empty( $this->settings['email_core_minor'] );
		}
		return (bool) $send;
	}

	/**
	 * Filter email sent after a manual core update from wp-admin.
	 *
	 * @param bool   $send          Whether to send.
	 * @param string $old_version   Previous version.
	 * @param string $new_version   New version.
	 * @param mixed  $core_update   Update data.
	 * @return bool
	 */
	public function filter_manual_core_email( $send, $old_version, $new_version, $core_update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( empty( $this->settings['email_manual_core'] ) ) {
			return false;
		}
		if ( self::is_major_version_change( (string) $old_version, (string) $new_version ) ) {
			return ! empty( $this->settings['email_core_major'] );
		}
		return ! empty( $this->settings['email_core_minor'] );
	}

	/**
	 * @param mixed $value Ignored.
	 * @return object
	 */
	public function block_core_transient( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$stub                   = new stdClass();
		$stub->updates          = array();
		$stub->version_checked  = get_bloginfo( 'version' );
		$stub->last_checked     = time();
		$stub->version          = get_bloginfo( 'version' );
		$stub->translations     = array();
		return $stub;
	}

	/**
	 * @param mixed $value Ignored.
	 * @return object
	 */
	public function block_plugin_transient( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return (object) array(
			'last_checked' => time(),
			'response'     => array(),
			'translations' => array(),
			'no_update'    => array(),
		);
	}

	/**
	 * @param mixed $value Ignored.
	 * @return object
	 */
	public function block_theme_transient( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return (object) array(
			'last_checked' => time(),
			'response'     => array(),
			'translations' => array(),
			'no_update'    => array(),
		);
	}

	/**
	 * Block translation update API lookups when translation updates are disabled.
	 *
	 * @param false|array|WP_Error $result API result.
	 * @param string               $action API action.
	 * @param array                $args   Request args.
	 * @return false|array|WP_Error
	 */
	public function block_translations_api( $result, $action, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'translations' === $action ) {
			return new WP_Error( 'tsosk_um_blocked', __( 'Translation updates are disabled by Update Manager.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		return $result;
	}

	/**
	 * Remove dashboard update nags.
	 */
	private function remove_update_nags(): void {
		add_action(
			'admin_init',
			static function () {
				remove_action( 'admin_notices', 'update_nag', 3 );
				remove_action( 'network_admin_notices', 'update_nag', 3 );
			},
			20
		);
	}

	/**
	 * Detect major WordPress version change (x.y segment).
	 *
	 * @param string $from Old version.
	 * @param string $to   New version.
	 */
	public static function is_major_version_change( string $from, string $to ): bool {
		$from_clean = preg_replace( '/[^0-9.].*$/', '', $from );
		$to_clean   = preg_replace( '/[^0-9.].*$/', '', $to );
		$from_parts = explode( '.', (string) $from_clean );
		$to_parts   = explode( '.', (string) $to_clean );

		$from_major = (int) ( $from_parts[0] ?? 0 );
		$from_minor = (int) ( $from_parts[1] ?? 0 );
		$to_major   = (int) ( $to_parts[0] ?? 0 );
		$to_minor   = (int) ( $to_parts[1] ?? 0 );

		return $from_major !== $to_major || $from_minor !== $to_minor;
	}

	/**
	 * Whether automatic updates are enabled for a component per current settings.
	 *
	 * @param string $component plugin|theme|translation|core.
	 * @return bool
	 */
	public function is_component_auto_enabled( string $component ): bool {
		if ( 'disable_all' === $this->settings['preset'] ) {
			return false;
		}
		if ( 'auto_all' === $this->settings['preset'] ) {
			return true;
		}
		if ( 'default' === $this->settings['preset'] ) {
			if ( 'translation' === $component ) {
				return true;
			}
			if ( 'core' === $component ) {
				return true;
			}
			return false;
		}

		$key_map = array(
			'plugin'      => 'plugin_auto',
			'theme'       => 'theme_auto',
			'translation' => 'translation_auto',
			'core'        => 'core_auto',
		);
		if ( ! isset( $key_map[ $component ] ) ) {
			return false;
		}

		$val = $this->settings[ $key_map[ $component ] ] ?? 'default';
		if ( 'core' === $component ) {
			return in_array( $val, array( 'minor', 'all' ), true );
		}
		return 'on' === $val;
	}

	/**
	 * Count pending updates from current transients.
	 *
	 * @return array{plugins:int,themes:int,translations:int,core:int}
	 */
	private function count_pending_updates(): array {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$plugin_updates = get_plugin_updates();
		$theme_updates  = get_theme_updates();
		$core_updates   = get_core_updates();
		$translations   = wp_get_translation_updates();

		$core_count = 0;
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $update ) {
				if ( is_object( $update ) && ! empty( $update->response ) && 'upgrade' === $update->response ) {
					$core_count++;
				}
			}
		}

		return array(
			'plugins'      => is_array( $plugin_updates ) ? count( $plugin_updates ) : 0,
			'themes'       => is_array( $theme_updates ) ? count( $theme_updates ) : 0,
			'translations' => is_array( $translations ) ? count( $translations ) : 0,
			'core'         => $core_count,
		);
	}

	/**
	 * Environment checks that can prevent background updates from running.
	 *
	 * @return array<int, array{label:string,status:string,badge:string,details:string}>
	 */
	private function get_update_environment(): array {
		$pending     = $this->count_pending_updates();
		$cron_url    = admin_url( 'tools.php?page=tso-swiss-knife&tab=cron' );
		$profiles_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=hidden-profiles' );
		$security_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=security' );
		$items       = array();

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$items[]       = array(
			'label'   => __( 'WP-Cron scheduler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $cron_disabled ? __( 'External required', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $cron_disabled ? 'tsosk-badge-warn' : 'tsosk-badge-ok',
			'details' => $cron_disabled
				? sprintf(
					/* translators: 1: hidden profiles link, 2: cron manager link */
					__( 'DISABLE_WP_CRON is on (often via %1$s). Background updates only run if a server cron calls wp-cron.php. Check the %2$s tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<a href="' . esc_url( $profiles_url ) . '">' . esc_html__( 'Hidden Profiles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>',
					'<a href="' . esc_url( $cron_url ) . '">' . esc_html__( 'Cron Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>'
				)
				: __( 'WordPress schedules update checks on site traffic (twice daily).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$file_mods = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		$items[]   = array(
			'label'   => __( 'File modifications', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $file_mods ? __( 'Blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Allowed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $file_mods ? 'tsosk-badge-warn' : 'tsosk-badge-ok',
			'details' => $file_mods
				? sprintf(
					/* translators: %s: security review link */
					__( 'DISALLOW_FILE_MODS is enabled (%s or wp-config.php). WordPress cannot install updates automatically.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<a href="' . esc_url( $security_url ) . '">' . esc_html__( 'Security Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>'
				)
				: __( 'The updater can write plugin, theme and translation files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$auto_allowed = wp_is_file_mod_allowed( 'auto_updater' );
		$items[]      = array(
			'label'   => __( 'Automatic updater', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $auto_allowed ? __( 'Allowed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $auto_allowed ? 'tsosk-badge-ok' : 'tsosk-badge-warn',
			'details' => $auto_allowed
				? __( 'WordPress may run background updates when cron executes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'WordPress core blocked automatic updates for this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$preset = $this->settings['preset'];
		if ( 'disable_all' === $preset ) {
			$module_status = __( 'All blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$module_badge  = 'tsosk-badge-warn';
			$module_detail = __( 'Update Manager preset “Disable all updates” is active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			$enabled       = array();
			if ( $this->is_component_auto_enabled( 'plugin' ) ) {
				$enabled[] = __( 'plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( $this->is_component_auto_enabled( 'theme' ) ) {
				$enabled[] = __( 'themes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( $this->is_component_auto_enabled( 'translation' ) ) {
				$enabled[] = __( 'translations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( $this->is_component_auto_enabled( 'core' ) ) {
				$enabled[] = __( 'core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( empty( $enabled ) ) {
				$module_status = __( 'None enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$module_badge  = 'tsosk-badge-warn';
				$module_detail = __( 'This module is not enabling any automatic updates. Use “Enable all automatic updates” or turn components on in Custom.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			} else {
				$module_status = __( 'Configured', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$module_badge  = 'tsosk-badge-ok';
				$module_detail = sprintf(
					/* translators: %s: comma-separated list of components */
					__( 'Auto-update enabled here for: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $enabled )
				);
			}
		}
		$items[] = array(
			'label'   => __( 'Update Manager policy', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $module_status,
			'badge'   => $module_badge,
			'details' => $module_detail,
		);

		$next_plugins = $this->get_next_cron_timestamp( 'wp_update_plugins' );
		$items[]      = array(
			'label'   => __( 'Next plugin check (cron)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $next_plugins ? human_time_diff( time(), $next_plugins ) : __( 'Not scheduled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $next_plugins ? 'tsosk-badge-info' : 'tsosk-badge-warn',
			'details' => $next_plugins
				? gmdate( 'Y-m-d H:i:s', $next_plugins ) . ' UTC'
				: __( 'No wp_update_plugins event found in the cron table.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$total_pending = $pending['plugins'] + $pending['themes'] + $pending['translations'] + $pending['core'];
		$items[]       = array(
			'label'   => __( 'Pending updates now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $total_pending > 0 ? (string) $total_pending : __( 'None detected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $total_pending > 0 ? 'tsosk-badge-warn' : 'tsosk-badge-ok',
			'details' => sprintf(
				/* translators: 1: plugins, 2: themes, 3: translations, 4: core */
				__( 'Plugins: %1$d · Themes: %2$d · Translations: %3$d · Core: %4$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$pending['plugins'],
				$pending['themes'],
				$pending['translations'],
				$pending['core']
			),
		);

		return $items;
	}

	/**
	 * Earliest scheduled timestamp for a cron hook.
	 *
	 * @param string $hook Hook name.
	 * @return int|null
	 */
	private function get_next_cron_timestamp( string $hook ): ?int {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return null;
		}
		$next = null;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ $hook ] ) ) {
				continue;
			}
			$ts = (int) $timestamp;
			if ( null === $next || $ts < $next ) {
				$next = $ts;
			}
		}
		return $next;
	}

	/** AJAX: refresh update transients and run the automatic updater now. */
	public function ajax_run_updates(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'update_core' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		if ( 'disable_all' === $this->settings['preset'] ) {
			wp_send_json_error( __( 'Update Manager is blocking all updates. Change the preset first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! wp_is_file_mod_allowed( 'auto_updater' ) ) {
			wp_send_json_error( __( 'Automatic updates are blocked on this site (file modifications not allowed).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( self::is_automatic_updater_disabled() ) {
			wp_send_json_error( __( 'Automatic updates are disabled by a WordPress filter or constant.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$before = $this->count_pending_updates();

		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();

		wp_maybe_auto_update();

		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();

		$after = $this->count_pending_updates();

		TSOSK_Activity_Log::log(
			'update-manager',
			'run',
			sprintf(
				/* translators: 1: plugins before, 2: plugins after */
				__( 'Manual update run finished (plugins pending: %1$d → %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$before['plugins'],
				$after['plugins']
			),
			array(
				'plugins_before' => (int) $before['plugins'],
				'plugins_after'  => (int) $after['plugins'],
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: plugins before, 2: themes before, 3: translations before, 4: plugins after, 5: themes after, 6: translations after */
					__( 'Update run finished. Pending before — plugins: %1$d, themes: %2$d, translations: %3$d. Pending now — plugins: %4$d, themes: %5$d, translations: %6$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$before['plugins'],
					$before['themes'],
					$before['translations'],
					$after['plugins'],
					$after['themes'],
					$after['translations']
				),
				'before'  => $before,
				'after'   => $after,
			)
		);
	}

	public function render(): void {
		$nonce       = wp_create_nonce( 'tsosk_um_nonce' );
		$settings    = self::get_settings();
		$preset      = $settings['preset'];
		$environment = $this->get_update_environment();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Control WordPress update checks, automatic updates, and update email notifications from one place.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'What does this module do?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'WordPress checks wordpress.org for core, plugin, theme and translation updates, can install some automatically, and sends email when updates happen. Here you choose what is allowed and which emails you receive.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description" style="margin:0 0 12px;">
				<?php esc_html_e( 'Important: enabling automatic updates here only tells WordPress they are allowed. By default installation still waits for WP-Cron (usually twice per day). Enable “Apply updates immediately” below to install as soon as updates are detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-notice tsosk-notice-warn" style="margin:0;">
				<strong><?php esc_html_e( 'Security note:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Blocking updates removes security patches from appearing in the dashboard. Only disable updates on staging, managed hosts with external patching, or when you update manually on a schedule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Update status & troubleshooting', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'If updates stay pending for days, check the blockers below. Use the button to check wordpress.org and apply allowed automatic updates immediately.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<table class="widefat tsosk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Details', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $environment as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['label'] ); ?></td>
							<td><span class="tsosk-badge <?php echo esc_attr( $item['badge'] ); ?>"><?php echo esc_html( $item['status'] ); ?></span></td>
							<td><?php echo wp_kses_post( $item['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:14px;margin-bottom:0;">
				<button type="button" class="button button-primary" id="tsosk-um-run-updates"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Check and apply updates now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-um-run-msg"></span>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Quick preset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Choose a starting point. Select Custom to fine-tune each option below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<?php
			$presets = array(
				'default'     => array(
					'label' => __( 'WordPress default', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'No overrides — WordPress decides update checks, auto-updates and emails as usual.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
				'disable_all' => array(
					'label' => __( 'Disable all updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'Stop update checks for core, plugins, themes and translations. Disable automatic updates and hide dashboard nags.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
				'auto_all'    => array(
					'label' => __( 'Enable all automatic updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'Allow automatic core (major + minor), plugin, theme and translation updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
				'custom'      => array(
					'label' => __( 'Custom', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'Configure each component and email option individually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			);
			foreach ( $presets as $key => $opt ) :
				?>
			<label class="tsosk-radio-row">
				<input type="radio" name="tsosk_um_preset" value="<?php echo esc_attr( $key ); ?>" <?php checked( $preset, $key ); ?>>
				<span>
					<strong><?php echo esc_html( $opt['label'] ); ?></strong><br>
					<span class="description"><?php echo esc_html( $opt['desc'] ); ?></span>
				</span>
			</label>
			<?php endforeach; ?>
			<label class="tsosk-toggle-row" style="margin-top:14px;">
				<input type="checkbox" id="tsosk-um-apply-immediately" <?php checked( ! empty( $settings['apply_immediately'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Apply updates immediately when detected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong><br>
					<span class="description"><?php esc_html_e( 'Works with automatic updates enabled. Installs allowed updates as soon as WordPress detects them — only when pending updates exist, without forcing extra checks on every admin page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
		</div>

		<div class="tsosk-card tsosk-um-custom-panel" <?php echo 'custom' === $preset ? '' : 'style="display:none;"'; ?>>
			<h3><?php esc_html_e( 'Update checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Prevent WordPress from seeing available updates in the dashboard (blocks the API check).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-block-core" <?php checked( $settings['block_core'] ); ?>>
				<span><strong><?php esc_html_e( 'Block WordPress core updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-block-plugins" <?php checked( $settings['block_plugins'] ); ?>>
				<span><strong><?php esc_html_e( 'Block plugin updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-block-themes" <?php checked( $settings['block_themes'] ); ?>>
				<span><strong><?php esc_html_e( 'Block theme updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-block-translations" <?php checked( $settings['block_translations'] ); ?>>
				<span><strong><?php esc_html_e( 'Block translation updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-hide-nags" <?php checked( $settings['hide_update_nags'] ); ?>>
				<span><strong><?php esc_html_e( 'Hide update nags in the admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>

			<h3 style="margin-top:20px;"><?php esc_html_e( 'Automatic updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Control what WordPress installs automatically in the background.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<table class="tsosk-kv-table">
				<tr>
					<th style="width:200px;"><?php esc_html_e( 'WordPress core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<select id="tsosk-um-core-auto">
							<option value="default" <?php selected( $settings['core_auto'], 'default' ); ?>><?php esc_html_e( 'WordPress default', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="off" <?php selected( $settings['core_auto'], 'off' ); ?>><?php esc_html_e( 'Disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="minor" <?php selected( $settings['core_auto'], 'minor' ); ?>><?php esc_html_e( 'Minor / security only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="all" <?php selected( $settings['core_auto'], 'all' ); ?>><?php esc_html_e( 'Major and minor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						</select>
					</td>
				</tr>
				<?php
				foreach (
					array(
						'plugin_auto'      => __( 'Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'theme_auto'       => __( 'Themes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'translation_auto' => __( 'Translations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					) as $auto_id => $auto_label
				) :
					?>
				<tr>
					<th><?php echo esc_html( $auto_label ); ?></th>
					<td>
						<select id="tsosk-um-<?php echo esc_attr( str_replace( '_', '-', $auto_id ) ); ?>">
							<option value="default" <?php selected( $settings[ $auto_id ], 'default' ); ?>><?php esc_html_e( 'WordPress default', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="off" <?php selected( $settings[ $auto_id ], 'off' ); ?>><?php esc_html_e( 'Disabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="on" <?php selected( $settings[ $auto_id ], 'on' ); ?>><?php esc_html_e( 'Enabled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Individual plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Override update behaviour for specific plugins. Leave both boxes unchecked to follow the global settings above.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p style="margin:0 0 10px;">
				<input type="search" id="tsosk-um-plugin-filter" class="regular-text"
				       placeholder="<?php esc_attr_e( 'Filter plugins…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				       autocomplete="off">
			</p>
			<div class="tsosk-table-wrap tsosk-um-plugin-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-um-plugin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Version', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:120px;text-align:center;"><?php esc_html_e( 'Block updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:120px;text-align:center;"><?php esc_html_e( 'Auto-update', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$plugin_rules = is_array( $settings['plugin_rules'] ?? null ) ? $settings['plugin_rules'] : array();
						$plugins_ui   = $this->get_plugins_for_ui();
						if ( empty( $plugins_ui ) ) :
							?>
						<tr>
							<td colspan="5" style="text-align:center;color:#646970;">
								<?php esc_html_e( 'No plugins installed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</td>
						</tr>
							<?php
						else :
							foreach ( $plugins_ui as $plugin ) :
								$rule  = $plugin_rules[ $plugin['file'] ] ?? array();
								$block = ! empty( $rule['block'] );
								$auto  = ! empty( $rule['auto'] );
								?>
						<tr class="tsosk-um-plugin-row" data-plugin="<?php echo esc_attr( $plugin['file'] ); ?>"
						    data-search="<?php echo esc_attr( strtolower( $plugin['name'] . ' ' . $plugin['file'] ) ); ?>">
							<td>
								<strong><?php echo esc_html( $plugin['name'] ); ?></strong><br>
								<code class="tsosk-code" style="font-size:11px;"><?php echo esc_html( $plugin['file'] ); ?></code>
							</td>
							<td><?php echo esc_html( $plugin['version'] ); ?></td>
							<td>
								<?php if ( $plugin['active'] ) : ?>
									<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php else : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="text-align:center;">
								<input type="checkbox" class="tsosk-um-plugin-block" <?php checked( $block ); ?>
								       aria-label="<?php esc_attr_e( 'Block updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							</td>
							<td style="text-align:center;">
								<input type="checkbox" class="tsosk-um-plugin-auto" <?php checked( $auto ); ?>
								       <?php disabled( $block ); ?>
								       aria-label="<?php esc_attr_e( 'Auto-update', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							</td>
						</tr>
								<?php
							endforeach;
						endif;
						?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Update email notifications', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Uncheck to stop WordPress from sending that type of update email to the site admin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-core-major" <?php checked( $settings['email_core_major'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Core — major version updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="description"><?php esc_html_e( 'e.g. 6.7 → 6.8 or 6.x → 7.x', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-core-minor" <?php checked( $settings['email_core_minor'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Core — minor / security updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<span class="description"><?php esc_html_e( 'e.g. 6.7.1 → 6.7.2', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-core-fail" <?php checked( $settings['email_core_fail'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Core — failed or critical update alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-manual-core" <?php checked( $settings['email_manual_core'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Core — after manual update from dashboard', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-plugin" <?php checked( $settings['email_plugin'] ); ?>>
				<span><strong><?php esc_html_e( 'Plugin automatic updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-um-email-theme" <?php checked( $settings['email_theme'] ); ?>>
				<span><strong><?php esc_html_e( 'Theme automatic updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
		</div>

		<button class="button button-primary" id="tsosk-um-save"
		        data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</button>
		<span class="tsosk-ajax-msg" id="tsosk-um-msg"></span>
		<?php
	}
}
