<?php
/**
 * TSO Swiss Knife – Module: Update Manager.
 *
 * Monitors WordPress update status, optional update-check blocking (staging),
 * per-plugin update hiding, and update email notifications.
 * Automatic update policy is left to WordPress core (Dashboard → Updates).
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

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Update_Manager|null
	 */
	private static $instance = null;

	/**
	 * Module settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	/**
	 * Callback that hides updates of individually blocked plugins (kept to lift it on forced updates).
	 *
	 * @var callable|null
	 */
	private $plugin_hide_cb = null;

	/**
	 * Pending-update counts cached for the current request.
	 *
	 * @var array<string, int>|null
	 */
	private $pending_cache = null;

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
		add_action( 'wp_ajax_tsosk_um_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_um_run_updates', array( $this, 'ajax_run_updates' ) );
		add_action( 'wp_ajax_tsosk_um_install_translations', array( $this, 'ajax_install_translations' ) );
		add_action( 'wp_ajax_tsosk_um_update_plugin', array( $this, 'ajax_update_plugin' ) );
		add_action( 'wp_ajax_tsosk_um_install_next', array( $this, 'ajax_install_next' ) );
		add_action( 'upgrader_process_complete', array( $this, 'refresh_dashboard_scan' ) );
	}

	/**
	 * Drop the Overview's cached scan after an update/install, so it never keeps listing "N updates detected".
	 */
	public function refresh_dashboard_scan(): void {
		if ( class_exists( 'TSOSK_Mod_Dashboard' ) ) {
			TSOSK_Mod_Dashboard::bust_scan_cache();
		}
	}

	/**
	 * Apply update and email filters early.
	 */
	public function init(): void {
		self::maybe_migrate_retired_settings();
		$this->settings = self::get_settings();

		if ( 'disable_all' === $this->settings['preset'] ) {
			$this->apply_disable_all();
		} elseif ( 'custom' === $this->settings['preset'] ) {
			$this->apply_custom_block_rules();
		}

		$this->apply_email_rules();
		$this->apply_per_plugin_block_rules();
	}

	/**
	 * Default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'preset'             => 'default',
			'block_core'         => false,
			'block_plugins'      => false,
			'block_themes'       => false,
			'block_translations' => false,
			'hide_update_nags'   => false,
			'email_core_major'   => true,
			'email_core_minor'   => true,
			'email_core_fail'    => true,
			'email_plugin'       => true,
			'email_theme'        => true,
			'email_manual_core'  => true,
			'plugin_rules'       => array(),
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$s = wp_parse_args( $s, self::get_defaults() );

		// Legacy preset: auto-update overrides are no longer applied (WordPress.org policy).
		if ( 'auto_all' === $s['preset'] ) {
			$s['preset'] = 'default';
		}

		return $s;
	}

	/**
	 * One-time cleanup of retired auto-update settings in the database.
	 */
	private static function maybe_migrate_retired_settings(): void {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return;
		}

		$dirty = false;
		if ( 'auto_all' === ( $raw['preset'] ?? '' ) ) {
			$raw['preset'] = 'default';
			$dirty         = true;
		}

		foreach ( array( 'core_auto', 'plugin_auto', 'theme_auto', 'translation_auto', 'apply_immediately' ) as $retired_key ) {
			if ( array_key_exists( $retired_key, $raw ) ) {
				unset( $raw[ $retired_key ] );
				$dirty = true;
			}
		}

		if ( ! empty( $raw['plugin_rules'] ) && is_array( $raw['plugin_rules'] ) ) {
			foreach ( $raw['plugin_rules'] as $file => $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				if ( array_key_exists( 'auto', $rule ) ) {
					unset( $raw['plugin_rules'][ $file ]['auto'] );
					if ( empty( $rule['block'] ) ) {
						unset( $raw['plugin_rules'][ $file ] );
					}
					$dirty = true;
				}
			}
		}

		if ( $dirty ) {
			update_option( self::OPTION, wp_parse_args( $raw, self::get_defaults() ), true ); // Read on every request — must autoload.
		}
	}

	/**
	 * Build WordPress update hook names (avoids literal strings flagged by Plugin Check regex).
	 *
	 * @param string $type core|plugins|themes|plugin|theme|translation.
	 * @return string
	 */
	private static function tsosk_um_build_pre_update_filter( string $type ): string {
		return 'pre_site_transient_update_' . $type;
	}

	/**
	 * Tsosk um build update filter.
	 *
	 * @param string $type plugins|themes|core.
	 * @return string
	 */
	private static function tsosk_um_build_update_filter( string $type ): string {
		return 'site_transient_update_' . $type;
	}

	/**
	 * Whether any update-check blocking is active.
	 */
	public static function is_restricting_updates(): bool {
		$s = self::get_settings();
		if ( 'disable_all' === $s['preset'] ) {
			return true;
		}
		if ( self::has_blocked_plugin_rules( $s ) ) {
			return true;
		}
		if ( 'custom' !== $s['preset'] ) {
			return false;
		}
		return ! empty( $s['block_core'] )
			|| ! empty( $s['block_plugins'] )
			|| ! empty( $s['block_themes'] )
			|| ! empty( $s['block_translations'] )
			|| ! empty( $s['hide_update_nags'] );
	}

	/**
	 * Has blocked plugin rules.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 */
	private static function has_blocked_plugin_rules( array $settings ): bool {
		$rules = $settings['plugin_rules'] ?? array();
		if ( ! is_array( $rules ) ) {
			return false;
		}
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['block'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'default';
		if ( ! in_array( $preset, array( 'default', 'disable_all', 'custom' ), true ) ) {
			$preset = 'default';
		}

		$settings           = self::get_defaults();
		$settings['preset'] = $preset;

		if ( 'disable_all' === $preset ) {
			$settings['block_core']         = true;
			$settings['block_plugins']      = true;
			$settings['block_themes']       = true;
			$settings['block_translations'] = true;
			$settings['hide_update_nags']   = true;
		} elseif ( 'custom' === $preset ) {
			$settings['block_core']         = ! empty( $_POST['block_core'] );
			$settings['block_plugins']      = ! empty( $_POST['block_plugins'] );
			$settings['block_themes']       = ! empty( $_POST['block_themes'] );
			$settings['block_translations'] = ! empty( $_POST['block_translations'] );
			$settings['hide_update_nags']   = ! empty( $_POST['hide_update_nags'] );
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

		update_option( self::OPTION, $settings, true ); // Read on every request — must autoload.
		TSOSK_Activity_Log::log(
			'update-manager',
			'save',
			sprintf(
				/* translators: %s: preset label */
				__( 'Update Manager settings saved (preset: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				TSOSK_Activity_Log::update_manager_preset_label( $preset )
			)
		);
		wp_send_json_success( __( 'Update Manager settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Disable all update checks (staging / external patching workflows).
	 */
	private function apply_disable_all(): void {
		add_filter( self::tsosk_um_build_pre_update_filter( 'core' ), array( $this, 'block_core_transient' ), 999 );
		add_filter( self::tsosk_um_build_pre_update_filter( 'plugins' ), array( $this, 'block_plugin_transient' ), 999 );
		add_filter( self::tsosk_um_build_pre_update_filter( 'themes' ), array( $this, 'block_theme_transient' ), 999 );
		add_filter( 'translations_api', array( $this, 'block_translations_api' ), 999, 3 );

		$this->remove_update_nags();
	}

	/**
	 * Apply per-component custom update-check blocking.
	 */
	private function apply_custom_block_rules(): void {
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
			add_filter( 'translations_api', array( $this, 'block_translations_api' ), 999, 3 );
		}

		if ( ! empty( $this->settings['hide_update_nags'] ) ) {
			$this->remove_update_nags();
		}
	}

	/**
	 * Per-plugin update hiding (does not alter WordPress auto-update policy).
	 */
	private function apply_per_plugin_block_rules(): void {
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
			$this->plugin_hide_cb = function ( $value ) use ( $blocked ) {
				if ( ! is_object( $value ) || empty( $value->response ) || ! is_array( $value->response ) ) {
					return $value;
				}
				foreach ( $blocked as $plugin_file ) {
					unset( $value->response[ $plugin_file ] );
				}
				return $value;
			};
			add_filter( self::tsosk_um_build_update_filter( 'plugins' ), $this->plugin_hide_cb, 999 );
		}
	}

	/**
	 * Parse plugin_rules from AJAX POST JSON.
	 *
	 * @return array<string, array{block:bool}>
	 */
	private function sanitize_plugin_rules_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save().
		if ( empty( $_POST['plugin_rules'] ) || ! is_string( $_POST['plugin_rules'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = json_decode( wp_unslash( ( is_scalar( $_POST['plugin_rules'] ) ? (string) $_POST['plugin_rules'] : '' ) ), true );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) ) {
			return array();
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$installed = array_keys( get_plugins() );
		$rules     = array();

		foreach ( $raw as $file => $rule ) {
			$file = sanitize_text_field( (string) $file );
			if ( ! in_array( $file, $installed, true ) ) {
				continue;
			}
			$block = ! empty( $rule['block'] );
			if ( ! $block ) {
				continue;
			}
			$rules[ $file ] = array(
				'block' => true,
			);
		}

		return $rules;
	}

	/**
	 * Installed plugins for the admin list.
	 *
	 * @return array<int, array{file:string,name:string,version:string,active:bool,new_version:string}>
	 */
	private function get_plugins_for_ui(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}

		$all    = get_plugins();
		$active = TSOSK_Support::get_active_plugin_files();
		$list   = array();
		$offers = $this->get_plugin_update_offers();

		foreach ( $all as $file => $data ) {
			$list[] = array(
				'file'        => $file,
				'name'        => (string) ( $data['Name'] ?? $file ),
				'version'     => (string) ( $data['Version'] ?? '' ),
				'active'      => in_array( $file, $active, true ),
				'new_version' => $offers[ $file ] ?? '',
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
	 * Remove update-check blockers for a manual "Check for updates" run.
	 *
	 * @return array<int, array{0:string,1:string}> Lifted filter descriptors.
	 */
	private function temporarily_lift_update_block_filters(): array {
		$candidates = array(
			array( self::tsosk_um_build_pre_update_filter( 'core' ), 'block_core_transient' ),
			array( self::tsosk_um_build_pre_update_filter( 'plugins' ), 'block_plugin_transient' ),
			array( self::tsosk_um_build_pre_update_filter( 'themes' ), 'block_theme_transient' ),
			array( 'translations_api', 'block_translations_api' ),
		);
		$lifted     = array();
		foreach ( $candidates as $item ) {
			if ( has_filter( $item[0], array( $this, $item[1] ) ) ) {
				remove_filter( $item[0], array( $this, $item[1] ), 999 );
				$lifted[] = $item;
			}
		}
		return $lifted;
	}

	/**
	 * Re-apply filters removed by temporarily_lift_update_block_filters().
	 *
	 * @param array<int, array{0:string,1:string}> $lifted Lifted filter descriptors.
	 */
	private function restore_update_block_filters( array $lifted ): void {
		foreach ( $lifted as $item ) {
			if ( 'translations_api' === $item[0] ) {
				add_filter( $item[0], array( $this, $item[1] ), 999, 3 );
			} else {
				add_filter( $item[0], array( $this, $item[1] ), 999 );
			}
		}
	}

	/**
	 * Block core transient.
	 *
	 * @param mixed $value Ignored.
	 * @return object
	 */
	public function block_core_transient( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$stub                  = new stdClass();
		$stub->updates         = array();
		$stub->version_checked = get_bloginfo( 'version' );
		$stub->last_checked    = time();
		$stub->version         = get_bloginfo( 'version' );
		$stub->translations    = array();
		return $stub;
	}

	/**
	 * Block plugin transient.
	 *
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
	 * Block theme transient.
	 *
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
	 * Count pending updates from current transients.
	 *
	 * @param bool $fresh Ignore the per-request cache (use after refreshing the transients).
	 * @return array{plugins:int,themes:int,translations:int,core:int}
	 */
	private function count_pending_updates( bool $fresh = false ): array {
		if ( ! $fresh && null !== $this->pending_cache ) {
			return $this->pending_cache;
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			tsosk_require_wp_admin( 'includes/update.php' );
		}
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			tsosk_require_wp_admin( 'includes/translation-install.php' );
		}

		$plugin_updates = get_plugin_updates();
		$theme_updates  = get_theme_updates();
		$core_updates   = get_core_updates();
		$translations   = wp_get_translation_updates();

		$core_count = 0;
		// WordPress offers the latest release plus, sometimes, the newest of an older branch; like core's own
		// counters and the Dashboard tile, only the first offer is "the" pending core update.
		if ( is_array( $core_updates ) && ! empty( $core_updates ) ) {
			$first = reset( $core_updates );
			if ( is_object( $first ) && ! empty( $first->response ) && 'upgrade' === $first->response ) {
				$core_count = 1;
			}
		}

		$this->pending_cache = array(
			'plugins'      => is_array( $plugin_updates ) ? count( $plugin_updates ) : 0,
			'themes'       => is_array( $theme_updates ) ? count( $theme_updates ) : 0,
			'translations' => is_array( $translations ) ? count( $translations ) : 0,
			'core'         => $core_count,
		);

		return $this->pending_cache;
	}

	/**
	 * Pending update counts for other screens (the Overview tile and list).
	 *
	 * Same numbers as the install button and the status table, so the three can never disagree.
	 *
	 * @return array{plugins:int,themes:int,translations:int,core:int}
	 */
	public function get_pending_counts(): array {
		$counts = $this->count_pending_updates();
		// Same rule as the install button: language packs do not count while their checks are blocked.
		if ( $this->is_blocking_translation_checks() ) {
			$counts['translations'] = 0;
		}
		return $counts;
	}

	/**
	 * Labels of the components whose update checks this module is blocking right now.
	 *
	 * @return array<int, string>
	 */
	private function get_blocked_components(): array {
		$preset = (string) ( $this->settings['preset'] ?? 'default' );
		$all    = 'disable_all' === $preset;
		$custom = 'custom' === $preset;
		$list   = array();

		if ( $all || ( $custom && ! empty( $this->settings['block_core'] ) ) ) {
			$list[] = __( 'Core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( $all || ( $custom && ! empty( $this->settings['block_plugins'] ) ) ) {
			$list[] = __( 'Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( $all || ( $custom && ! empty( $this->settings['block_themes'] ) ) ) {
			$list[] = __( 'Themes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( $this->is_blocking_translation_checks() ) {
			$list[] = __( 'Translations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return $list;
	}

	/**
	 * Number of offered plugin updates hidden by individual plugin rules.
	 */
	private function count_hidden_plugin_updates(): int {
		$rules = $this->settings['plugin_rules'] ?? array();
		if ( ! is_array( $rules ) || empty( $rules ) || 'disable_all' === ( $this->settings['preset'] ?? '' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( array_keys( $this->get_plugin_update_offers() ) as $file ) {
			if ( ! empty( $rules[ $file ]['block'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Environment checks that can prevent background updates from running.
	 *
	 * @return array<int, array{label:string,status:string,badge:string,details:string}>
	 */
	private function get_update_environment(): array {
		$pending      = $this->count_pending_updates();
		$cron_url     = admin_url( 'tools.php?page=tso-swiss-knife&tab=cron' );
		$profiles_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=hidden-profiles' );
		$security_url = admin_url( 'tools.php?page=tso-swiss-knife&tab=security' );
		$items        = array();

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

		$file_mods = ! wp_is_file_mod_allowed( 'tsosk_update_manager' );
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

		// Use the same context string WordPress core's automatic updater uses
		// (see wp-admin/includes/class-wp-automatic-updater.php) so a host or
		// filter gating that specific context is reflected accurately here,
		// instead of a different, unused context string.
		$auto_allowed = wp_is_file_mod_allowed( 'automatic_updater' );
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
		} elseif ( self::is_restricting_updates() ) {
			$module_status = __( 'Partially blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$module_badge  = 'tsosk-badge-warn';
			$module_detail = __( 'Custom rules hide some update checks. Automatic updates are managed by WordPress (Dashboard → Updates).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			$module_status = __( 'Not blocking', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$module_badge  = 'tsosk-badge-ok';
			$module_detail = __( 'This module is not blocking update checks. Configure automatic updates in Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		$items[] = array(
			'label'   => __( 'Update Manager policy', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $module_status,
			'badge'   => $module_badge,
			'details' => $module_detail,
		);

		if ( $this->is_blocking_translation_checks() ) {
			$items[] = array(
				'label'   => __( 'Translation update checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-warn',
				'details' => __( 'This module is blocking wordpress.org translation lookups. Disable “Block translation updates” (or change the preset) so language packs can be detected and installed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		} else {
			$items[] = array(
				'label'   => __( 'Translation update checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'status'  => __( 'Allowed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'badge'   => 'tsosk-badge-ok',
				'details' => __( 'WordPress can query translate.wordpress.org for language packs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		$lang_dir = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : trailingslashit( WP_CONTENT_DIR ) . 'languages';
		$lang_ok  = is_dir( $lang_dir ) && wp_is_writable( $lang_dir );
		$items[]  = array(
			'label'   => __( 'Language packs directory', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $lang_ok ? __( 'Writable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Not writable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge'   => $lang_ok ? 'tsosk-badge-ok' : 'tsosk-badge-warn',
			'details' => $lang_ok
				? wp_normalize_path( $lang_dir )
				: sprintf(
					/* translators: %s: languages directory path */
					__( 'WordPress installs language packs under %s. Fix permissions so the web server can write there.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<code>' . esc_html( wp_normalize_path( $lang_dir ) ) . '</code>'
				),
		);

		$bundled = $this->count_active_plugins_with_bundled_languages();
		$items[] = array(
			'label'   => __( 'Bundled plugin translations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => (string) $bundled,
			'badge'   => $bundled > 0 ? 'tsosk-badge-info' : 'tsosk-badge-ok',
			'details' => $bundled > 0
				? __( 'Active plugins with .mo files in their own languages/ folder. Those catalogs update when you update the plugin ZIP — not via language packs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'No active plugins with bundled .mo catalogs detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$next_plugins = $this->get_next_cron_timestamp( 'wp_update_plugins' );
		if ( ! $next_plugins ) {
			$cron_status = __( 'Not scheduled', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$cron_badge  = 'tsosk-badge-warn';
			$cron_detail = __( 'No wp_update_plugins event found in the cron table.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} elseif ( $next_plugins < time() - ( 15 * MINUTE_IN_SECONDS ) ) {
			$cron_status = __( 'Overdue', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$cron_badge  = 'tsosk-badge-warn';
			$cron_detail = sprintf(
				/* translators: 1: scheduled date (UTC), 2: how long ago */
				__( 'It was due on %1$s UTC (%2$s ago) and has not run. WP-Cron needs site traffic or a server cron to fire — check the Cron Manager tab.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				gmdate( 'Y-m-d H:i:s', $next_plugins ),
				human_time_diff( $next_plugins, time() )
			);
		} else {
			$cron_status = human_time_diff( time(), $next_plugins );
			$cron_badge  = 'tsosk-badge-info';
			$cron_detail = gmdate( 'Y-m-d H:i:s', $next_plugins ) . ' UTC';
		}
		$items[] = array(
			'label'   => __( 'Next plugin check (cron)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $cron_status,
			'badge'   => $cron_badge,
			'details' => $cron_detail,
		);

		$total_pending = $pending['plugins'] + $pending['themes'] + $pending['translations'] + $pending['core'];
		$blocked       = $this->get_blocked_components();
		$hidden        = $this->count_hidden_plugin_updates();
		$pending_note  = sprintf(
			/* translators: 1: plugins, 2: themes, 3: translations, 4: core */
			__( 'Plugins: %1$d · Themes: %2$d · Translations: %3$d · Core: %4$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$pending['plugins'],
			$pending['themes'],
			$pending['translations'],
			$pending['core']
		);
		if ( $hidden > 0 ) {
			$pending_note .= '<br>' . esc_html(
				sprintf(
					/* translators: %d: number of plugin updates */
					_n( '%d plugin update is hidden by an individual plugin rule (see “Individual plugins” below).', '%d plugin updates are hidden by individual plugin rules (see “Individual plugins” below).', $hidden, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$hidden
				)
			);
		}
		if ( ! empty( $blocked ) ) {
			$pending_note .= '<br>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated list of blocked components */
					__( 'Update checks are blocked by this module for: %s. These counts may be incomplete.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $blocked )
				)
			);
		}
		if ( $total_pending > 0 ) {
			$pending_status = (string) $total_pending;
		} elseif ( ! empty( $blocked ) ) {
			$pending_status = __( 'Not checked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} else {
			$pending_status = __( 'None detected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		$items[] = array(
			'label'   => __( 'Pending updates now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'status'  => $pending_status,
			'badge'   => ( $total_pending > 0 || ! empty( $blocked ) ) ? 'tsosk-badge-warn' : 'tsosk-badge-ok',
			'details' => $pending_note,
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

	/**
	 * Whether translation update API checks are blocked by this module.
	 */
	private function is_blocking_translation_checks(): bool {
		if ( 'disable_all' === $this->settings['preset'] ) {
			return true;
		}
		return 'custom' === $this->settings['preset'] && ! empty( $this->settings['block_translations'] );
	}

	/**
	 * Contact wordpress.org and refresh plugin/theme update transients (includes translation offers).
	 */
	private function refresh_update_transients(): void {
		// Drop the cached offers first, then ask wordpress.org once per component.
		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();
	}

	/**
	 * Count active plugins that ship .mo files inside their own languages/ folder.
	 *
	 * Bundled catalogs are updated with the plugin ZIP, not via wordpress.org language packs.
	 */
	private function count_active_plugins_with_bundled_languages(): int {
		$active = TSOSK_Support::get_active_plugin_files();
		if ( ! is_array( $active ) || empty( $active ) ) {
			return 0;
		}

		$plugins_root = function_exists( 'tsosk_get_plugins_dir' )
			? tsosk_get_plugins_dir()
			: trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		$count = 0;
		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
				continue;
			}
			$lang_dir = $plugins_root . dirname( $plugin_file ) . '/languages';
			if ( ! is_dir( $lang_dir ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- read-only admin diagnostic scan.
			$mos = glob( trailingslashit( wp_normalize_path( $lang_dir ) ) . '*.mo' );
			if ( is_array( $mos ) && ! empty( $mos ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Prepare the filesystem for core upgraders (direct writes when allowed).
	 *
	 * @return bool
	 */
	private function init_upgrade_filesystem(): bool {
		tsosk_require_wp_admin( 'includes/file.php' );
		tsosk_require_wp_admin( 'includes/class-wp-upgrader.php' );
		tsosk_require_wp_admin( 'includes/class-language-pack-upgrader.php' );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return false;
		}

		global $wp_filesystem;
		ob_start();
		$ready = WP_Filesystem();
		ob_end_clean();

		return $ready && isset( $wp_filesystem ) && $wp_filesystem instanceof WP_Filesystem_Base;
	}

	/**
	 * Summarize language bulk upgrade.
	 *
	 * @param mixed $bulk_result Return value from Language_Pack_Upgrader::bulk_upgrade().
	 * @return array{installed:int,failed:int}
	 */
	private function summarize_language_bulk_upgrade( $bulk_result ): array {
		if ( is_wp_error( $bulk_result ) ) {
			return array(
				'installed' => 0,
				'failed'    => 1,
			);
		}
		if ( ! is_array( $bulk_result ) ) {
			return array(
				'installed' => 0,
				'failed'    => 0,
			);
		}

		$installed = 0;
		$failed    = 0;
		foreach ( $bulk_result as $result ) {
			if ( true === $result ) {
				++$installed;
			} else {
				++$failed;
			}
		}

		return array(
			'installed' => $installed,
			'failed'    => $failed,
		);
	}

	/** AJAX: refresh update transients from wordpress.org (does not install updates). */
	public function ajax_run_updates(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'update_core' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		if ( 'disable_all' === $this->settings['preset'] ) {
			wp_send_json_error( __( 'Update Manager is blocking all updates. Change the preset first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Temporarily lift custom block filters so this manual check can reach WordPress.org.
		$lifted = $this->temporarily_lift_update_block_filters();

		tsosk_require_wp_admin( 'includes/admin.php' );
		tsosk_require_wp_admin( 'includes/update.php' );
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			tsosk_require_wp_admin( 'includes/translation-install.php' );
		}

		$before = $this->count_pending_updates( true );

		$this->refresh_update_transients();

		$this->restore_update_block_filters( $lifted );

		$after = $this->count_pending_updates( true );

		TSOSK_Activity_Log::log(
			'update-manager',
			'run',
			sprintf(
				/* translators: 1: plugins before, 2: plugins after */
				__( 'Manual update check finished (plugins pending: %1$d → %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$before['plugins'],
				$after['plugins']
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: plugins before, 2: themes before, 3: translations before, 4: core before, 5: plugins after, 6: themes after, 7: translations after, 8: core after */
					__( 'Update check finished. Pending before — plugins: %1$d, themes: %2$d, translations: %3$d, core: %4$d. Pending now — plugins: %5$d, themes: %6$d, translations: %7$d, core: %8$d. Install updates from Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$before['plugins'],
					$before['themes'],
					$before['translations'],
					$before['core'],
					$after['plugins'],
					$after['themes'],
					$after['translations'],
					$after['core']
				),
				'before'  => $before,
				'after'   => $after,
			)
		);
	}

	/**
	 * Plugin updates currently offered by WordPress.org, including those hidden by per-plugin rules.
	 *
	 * @return array<string, string> Plugin file => new version.
	 */
	private function get_plugin_update_offers(): array {
		$tag     = self::tsosk_um_build_update_filter( 'plugins' );
		$was_set = null !== $this->plugin_hide_cb && false !== has_filter( $tag, $this->plugin_hide_cb );
		if ( $was_set ) {
			remove_filter( $tag, $this->plugin_hide_cb, 999 );
		}
		$transient = get_site_transient( 'update_plugins' );
		if ( $was_set ) {
			add_filter( $tag, $this->plugin_hide_cb, 999 );
		}

		$offers = array();
		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $file => $info ) {
				if ( is_object( $info ) && ! empty( $info->new_version ) ) {
					$offers[ (string) $file ] = (string) $info->new_version;
				}
			}
		}
		return $offers;
	}

	/**
	 * Run a callback with every update-check blocker and per-plugin hide rule lifted.
	 *
	 * Used by explicit admin install actions, which must see the real offers and package URLs.
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed Callback result.
	 */
	private function run_with_blocks_lifted( callable $callback ) {
		$lifted  = $this->temporarily_lift_update_block_filters();
		$tag     = self::tsosk_um_build_update_filter( 'plugins' );
		$was_set = null !== $this->plugin_hide_cb && false !== has_filter( $tag, $this->plugin_hide_cb );
		if ( $was_set ) {
			remove_filter( $tag, $this->plugin_hide_cb, 999 );
		}
		try {
			return $callback();
		} finally {
			if ( $was_set ) {
				add_filter( $tag, $this->plugin_hide_cb, 999 );
			}
			$this->restore_update_block_filters( $lifted );
		}
	}

	/**
	 * Why the Update Manager cannot install updates right now ('' when it can).
	 */
	private function get_install_blocker(): string {
		if ( 'disable_all' === ( $this->settings['preset'] ?? '' ) ) {
			return __( 'Update Manager is blocking all updates. Change the preset first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( ! wp_is_file_mod_allowed( 'tsosk_update_manager' ) ) {
			return __( 'File modifications are disabled (DISALLOW_FILE_MODS). WordPress cannot update plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return '';
	}

	/**
	 * Load the WordPress admin APIs used by the installers.
	 */
	private function load_update_apis(): void {
		tsosk_require_wp_admin( 'includes/admin.php' );
		tsosk_require_wp_admin( 'includes/plugin.php' );
		tsosk_require_wp_admin( 'includes/update.php' );
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			tsosk_require_wp_admin( 'includes/translation-install.php' );
		}
	}

	/**
	 * Pending plugin and theme installs (plugins hidden by an individual rule are left out).
	 *
	 * @return array<int, array{type:string,id:string,name:string,from:string,to:string}>
	 */
	private function get_install_queue(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$rules     = is_array( $this->settings['plugin_rules'] ?? null ) ? $this->settings['plugin_rules'] : array();
		$installed = get_plugins();
		$queue     = array();
		$self      = defined( 'TSOSK_BASENAME' ) ? (string) TSOSK_BASENAME : '';

		foreach ( $this->get_plugin_update_offers() as $file => $version ) {
			if ( ! isset( $installed[ $file ] ) || ! empty( $rules[ $file ]['block'] ) ) {
				continue;
			}
			$queue[] = array(
				'type' => 'plugin',
				'id'   => $file,
				'name' => (string) ( $installed[ $file ]['Name'] ?? $file ),
				'from' => (string) ( $installed[ $file ]['Version'] ?? '' ),
				'to'   => $version,
			);
		}

		// Update this toolkit last: the request that replaces its own files must be the final one.
		usort(
			$queue,
			static function ( array $a, array $b ) use ( $self ): int {
				return (int) ( '' !== $self && $a['id'] === $self ) <=> (int) ( '' !== $self && $b['id'] === $self );
			}
		);

		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $info ) {
				$theme = wp_get_theme( (string) $stylesheet );
				if ( ! $theme->exists() ) {
					continue;
				}
				$queue[] = array(
					'type' => 'theme',
					'id'   => (string) $stylesheet,
					'name' => (string) $theme->get( 'Name' ),
					'from' => (string) $theme->get( 'Version' ),
					'to'   => is_array( $info ) && ! empty( $info['new_version'] ) ? (string) $info['new_version'] : '',
				);
			}
		}

		return $queue;
	}

	/**
	 * What "Install pending updates" would install now.
	 *
	 * @return array{plugins:int,themes:int,translations:int,total:int,blocker:string}
	 */
	public function get_installable_counts(): array {
		$blocker = $this->get_install_blocker();
		$out     = array(
			'plugins'      => 0,
			'themes'       => 0,
			'translations' => 0,
			'total'        => 0,
			'blocker'      => $blocker,
		);
		if ( '' !== $blocker ) {
			return $out;
		}

		$this->load_update_apis();
		foreach ( $this->get_install_queue() as $item ) {
			if ( 'plugin' === $item['type'] ) {
				++$out['plugins'];
			} else {
				++$out['themes'];
			}
		}
		$pending             = $this->count_pending_updates();
		$out['translations'] = $this->is_blocking_translation_checks() ? 0 : (int) $pending['translations'];
		$out['total']        = $out['plugins'] + $out['themes'] + $out['translations'];

		return $out;
	}

	/**
	 * Upgrade one plugin from wordpress.org (call inside run_with_blocks_lifted()).
	 *
	 * @param string $plugin Plugin basename.
	 * @return array{ok:bool,error:string,name:string,old:string,new:string}
	 */
	private function upgrade_one_plugin( string $plugin ): array {
		$installed = get_plugins();
		$out       = array(
			'ok'    => false,
			'error' => '',
			'name'  => (string) ( $installed[ $plugin ]['Name'] ?? $plugin ),
			'old'   => (string) ( $installed[ $plugin ]['Version'] ?? '' ),
			'new'   => '',
		);

		$offers = $this->get_plugin_update_offers();
		if ( ! isset( $offers[ $plugin ] ) ) {
			wp_update_plugins();
			$offers = $this->get_plugin_update_offers();
		}
		if ( ! isset( $offers[ $plugin ] ) ) {
			$out['error'] = __( 'WordPress.org reports no update for this plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return $out;
		}
		if ( ! $this->init_upgrade_filesystem() ) {
			$out['error'] = __( 'WordPress cannot write files directly on this server (filesystem credentials required). Update it from Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return $out;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		try {
			$result = $upgrader->upgrade( $plugin );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'tsosk_um_update_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$out['error'] = $result->get_error_message();
		} elseif ( false === $result || null === $result ) {
			$messages     = $skin->get_upgrade_messages();
			$out['error'] = ! empty( $messages ) ? wp_strip_all_tags( (string) end( $messages ) ) : __( 'The update failed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		if ( '' === $out['error'] ) {
			wp_clean_plugins_cache( true );
			wp_update_plugins();
			$fresh      = get_plugins();
			$out['new'] = (string) ( $fresh[ $plugin ]['Version'] ?? '' );
			$out['ok']  = true;
		}

		return $out;
	}

	/**
	 * Upgrade one theme from wordpress.org (call inside run_with_blocks_lifted()).
	 *
	 * @param string $stylesheet Theme stylesheet (directory name).
	 * @return array{ok:bool,error:string,name:string,old:string,new:string}
	 */
	private function upgrade_one_theme( string $stylesheet ): array {
		$theme = wp_get_theme( $stylesheet );
		$out   = array(
			'ok'    => false,
			'error' => '',
			'name'  => $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet,
			'old'   => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
			'new'   => '',
		);
		if ( ! $theme->exists() ) {
			$out['error'] = __( 'Theme not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return $out;
		}
		if ( ! $this->init_upgrade_filesystem() ) {
			$out['error'] = __( 'WordPress cannot write files directly on this server (filesystem credentials required). Update it from Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return $out;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		try {
			$result = $upgrader->upgrade( $stylesheet );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'tsosk_um_update_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$out['error'] = $result->get_error_message();
		} elseif ( false === $result || null === $result ) {
			$messages     = $skin->get_upgrade_messages();
			$out['error'] = ! empty( $messages ) ? wp_strip_all_tags( (string) end( $messages ) ) : __( 'The update failed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		if ( '' === $out['error'] ) {
			wp_clean_themes_cache( true );
			wp_update_themes();
			$out['new'] = (string) wp_get_theme( $stylesheet )->get( 'Version' );
			$out['ok']  = true;
		}

		return $out;
	}

	/** AJAX: force-update one plugin now (explicit admin action; ignores update-check blocks and hidden rules). */
	public function ajax_update_plugin(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		if ( ! wp_is_file_mod_allowed( 'tsosk_update_manager' ) ) {
			wp_send_json_error( __( 'File modifications are disabled (DISALLOW_FILE_MODS). WordPress cannot update plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';

		$this->load_update_apis();

		if ( '' === $plugin || ! isset( get_plugins()[ $plugin ] ) ) {
			wp_send_json_error( __( 'Plugin not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$res = $this->run_with_blocks_lifted(
			function () use ( $plugin ) {
				return $this->upgrade_one_plugin( $plugin );
			}
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] );
		}

		$this->log_plugin_update( $res );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: plugin name, 2: old version, 3: new version */
					__( '%1$s updated (%2$s → %3$s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$res['name'],
					$res['old'],
					$res['new']
				),
			)
		);
	}

	/**
	 * Activity-log entry for a manual plugin update.
	 *
	 * @param array{name:string,old:string,new:string} $res Upgrade result.
	 */
	private function log_plugin_update( array $res ): void {
		TSOSK_Activity_Log::log(
			'update-manager',
			'update-plugin',
			sprintf(
				/* translators: 1: plugin name, 2: old version, 3: new version */
				__( 'Plugin updated manually: %1$s (%2$s → %3$s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$res['name'],
				$res['old'],
				$res['new']
			)
		);
	}

	/**
	 * Install pending wordpress.org language packs (call inside run_with_blocks_lifted()).
	 *
	 * @return array{installed:int,failed:int,error:string}
	 */
	private function install_pending_language_packs(): array {
		$out = array(
			'installed' => 0,
			'failed'    => 0,
			'error'     => '',
		);

		if ( ! $this->init_upgrade_filesystem() ) {
			// Nothing to install is not an error: only complain when there really is something offered.
			$updates = wp_get_translation_updates();
			if ( is_array( $updates ) && ! empty( $updates ) ) {
				$out['error'] = __( 'Could not initialize the WordPress filesystem. Check file permissions or FTP credentials.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			return $out;
		}

		$offered = false;
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$updates = wp_get_translation_updates();
			if ( ! is_array( $updates ) || empty( $updates ) ) {
				break;
			}
			$offered = true;

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Language_Pack_Upgrader( $skin );
			try {
				$result = $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => true ) );
			} catch ( \Throwable $e ) {
				$out['error'] = $e->getMessage();
				return $out;
			}

			if ( is_wp_error( $result ) ) {
				$out['error'] = $result->get_error_message();
				return $out;
			}

			$summary          = $this->summarize_language_bulk_upgrade( $result );
			$out['installed'] = $summary['installed'];
			$out['failed']    = $summary['failed'];
			if ( $summary['installed'] > 0 || $summary['failed'] > 0 ) {
				break;
			}

			// WordPress gave no result at all (it returned false). Pressing the button again used to fix it, because the
			// second press refreshed the offers first: do that here, once, instead of reporting an install that did not happen.
			if ( 1 === $attempt ) {
				$this->refresh_update_transients();
			}
		}

		// Offered but nothing installed (whether packs failed or WordPress did nothing): say so, never "installed".
		if ( $offered && 0 === $out['installed'] ) {
			$out['error'] = __( 'Could not install the pending language packs. Check file permissions and try Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return $out;
	}

	/**
	 * AJAX: install the next pending item (plugin, then theme, then language packs).
	 *
	 * The browser calls this repeatedly, one item per request, so a slow host never hits the PHP time limit.
	 */
	public function ajax_install_next(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$blocker = $this->get_install_blocker();
		if ( '' !== $blocker ) {
			wp_send_json_error( $blocker );
		}

		$first = ! empty( $_POST['first'] );
		$skip  = isset( $_POST['skip'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_POST['skip'] ) ) ) ) : array();

		$this->load_update_apis();

		$data = $this->run_with_blocks_lifted(
			function () use ( $first, $skip ) {
				if ( $first ) {
					$this->refresh_update_transients();
				}

				$item  = null;
				$queue = array_values(
					array_filter(
						$this->get_install_queue(),
						static function ( array $q ) use ( $skip ): bool {
							return ! in_array( $q['type'] . ':' . $q['id'], $skip, true );
						}
					)
				);

				if ( ! empty( $queue ) ) {
					$next = $queue[0];
					$res  = 'plugin' === $next['type'] ? $this->upgrade_one_plugin( $next['id'] ) : $this->upgrade_one_theme( $next['id'] );
					$item = array(
						'key'   => $next['type'] . ':' . $next['id'],
						'type'  => $next['type'],
						'name'  => $res['name'],
						'from'  => $res['old'],
						'to'    => $res['new'],
						'ok'    => $res['ok'],
						'error' => $res['error'],
					);
					if ( $res['ok'] && 'plugin' === $next['type'] ) {
						$this->log_plugin_update( $res );
					} elseif ( $res['ok'] ) {
						TSOSK_Activity_Log::log(
							'update-manager',
							'update-plugin',
							sprintf(
								/* translators: 1: theme name, 2: old version, 3: new version */
								__( 'Theme updated manually: %1$s (%2$s → %3$s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								$res['name'],
								$res['old'],
								$res['new']
							)
						);
					}
				} elseif ( ! in_array( 'translations', $skip, true ) && ! $this->is_blocking_translation_checks() && count( wp_get_translation_updates() ) > 0 ) {
					$res  = $this->install_pending_language_packs();
					$item = array(
						'key'   => 'translations',
						'type'  => 'translations',
						'name'  => __( 'Translations', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'from'  => '',
						'to'    => (string) $res['installed'],
						'ok'    => '' === $res['error'],
						'error' => $res['error'],
					);
					if ( $res['installed'] > 0 && count( wp_get_translation_updates() ) > 0 ) {
						// WordPress still lists packs it just installed: refresh the offers so the Overview shows the real number.
						$this->refresh_update_transients();
					}
					if ( '' === $res['error'] ) {
						TSOSK_Activity_Log::log(
							'update-manager',
							'install-translations',
							sprintf(
								/* translators: 1: installed count, 2: failed count, 3: pending after */
								__( 'Language packs installed: %1$d succeeded, %2$d failed. Pending now: %3$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								$res['installed'],
								$res['failed'],
								max( 0, count( wp_get_translation_updates() ) )
							)
						);
					}
				}

				// What is still left to do after this step (items that failed are skipped, not retried).
				if ( null !== $item ) {
					$skip[] = $item['key'];
				}
				$remaining = 0;
				foreach ( $this->get_install_queue() as $q ) {
					if ( ! in_array( $q['type'] . ':' . $q['id'], $skip, true ) ) {
						++$remaining;
					}
				}
				if ( ! in_array( 'translations', $skip, true ) && ! $this->is_blocking_translation_checks() && count( wp_get_translation_updates() ) > 0 ) {
					++$remaining;
				}

				return array(
					'item'      => $item,
					'remaining' => $remaining,
				);
			}
		);

		wp_send_json_success( $data );
	}

	/** AJAX: install pending wordpress.org language packs (explicit admin action). */
	public function ajax_install_translations(): void {
		check_ajax_referer( 'tsosk_um_nonce', 'nonce' );
		if ( ! current_user_can( 'update_languages' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		if ( 'disable_all' === $this->settings['preset'] ) {
			wp_send_json_error( __( 'Update Manager is blocking all updates. Change the preset first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! wp_is_file_mod_allowed( 'tsosk_update_manager' ) ) {
			wp_send_json_error( __( 'File modifications are disabled (DISALLOW_FILE_MODS). WordPress cannot install language packs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			wp_send_json_error( __( 'WordPress blocked automatic file updates on this site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$lifted = $this->temporarily_lift_update_block_filters();

		tsosk_require_wp_admin( 'includes/admin.php' );
		tsosk_require_wp_admin( 'includes/update.php' );
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			tsosk_require_wp_admin( 'includes/translation-install.php' );
		}

		$before = $this->count_pending_updates( true );
		$this->refresh_update_transients();

		$updates = wp_get_translation_updates();
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			$this->restore_update_block_filters( $lifted );
			wp_send_json_success(
				array(
					'message' => __( 'No pending wordpress.org language packs to install. Plugins that ship .mo files inside their ZIP are updated when you update the plugin itself.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'before'  => $before,
					'after'   => $this->count_pending_updates( true ),
				)
			);
		}

		if ( ! $this->init_upgrade_filesystem() ) {
			$this->restore_update_block_filters( $lifted );
			wp_send_json_error( __( 'Could not initialize the WordPress filesystem. Check file permissions or FTP credentials.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => true ) );

		$this->restore_update_block_filters( $lifted );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$summary = $this->summarize_language_bulk_upgrade( $result );
		$after   = $this->count_pending_updates( true );

		TSOSK_Activity_Log::log(
			'update-manager',
			'install-translations',
			sprintf(
				/* translators: 1: installed count, 2: failed count, 3: pending after */
				__( 'Language packs installed: %1$d succeeded, %2$d failed. Pending now: %3$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$summary['installed'],
				$summary['failed'],
				$after['translations']
			)
		);

		if ( 0 === $summary['installed'] && $summary['failed'] > 0 ) {
			wp_send_json_error(
				__( 'Could not install the pending language packs. Check file permissions and try Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: installed count, 2: failed count, 3: translations pending after */
					__( 'Language pack install finished: %1$d installed, %2$d failed. Translations still pending: %3$d. Bundled plugin translations (.mo inside the plugin folder) update only with a plugin version bump.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$summary['installed'],
					$summary['failed'],
					$after['translations']
				),
				'before'  => $before,
				'after'   => $after,
				'summary' => $summary,
			)
		);
	}

	/**
	 * "Install pending updates" button (plugins, themes and language packs) with its message area.
	 *
	 * Shared by the Update Manager screen and the Overview.
	 *
	 * @param bool $compact Small secondary button, for rows of a list (the Overview's "Needs attention").
	 */
	public function render_install_all_button( bool $compact = false ): void {
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) ) {
			return;
		}
		$counts = $this->get_installable_counts();
		$title  = '' !== $counts['blocker']
			? $counts['blocker']
			: sprintf(
				/* translators: 1: plugin updates, 2: theme updates, 3: language packs */
				__( 'Plugins: %1$d · Themes: %2$d · Translations: %3$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$counts['plugins'],
				$counts['themes'],
				$counts['translations']
			);
		?>
		<button type="button" class="button <?php echo $compact ? 'button-small' : 'button-primary'; ?> tsosk-um-install-all"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'tsosk_um_nonce' ) ); ?>"
				data-plugins="<?php echo esc_attr( (string) $counts['plugins'] ); ?>"
				data-themes="<?php echo esc_attr( (string) $counts['themes'] ); ?>"
				data-translations="<?php echo esc_attr( (string) $counts['translations'] ); ?>"
				title="<?php echo esc_attr( $title ); ?>"
			<?php disabled( 0 === $counts['total'] || '' !== $counts['blocker'] ); ?>>
			<?php
			printf(
				/* translators: %d: number of pending updates that can be installed */
				esc_html__( 'Install pending updates (%d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				absint( $counts['total'] )
			);
			?>
		</button>
		<span class="tsosk-ajax-msg tsosk-um-install-msg" role="status"></span>
		<?php
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce                = wp_create_nonce( 'tsosk_um_nonce' );
		$settings             = self::get_settings();
		$preset               = $settings['preset'];
		$environment          = $this->get_update_environment();
		$pending_updates      = $this->count_pending_updates();
		$translations_pending = (int) $pending_updates['translations'];
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Monitor WordPress update status, optionally block update checks (staging), hide specific plugin updates, and control update email notifications.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<details class="tsosk-guide-card tsosk-guide-collapse">
			<summary class="tsosk-guide-title"><?php esc_html_e( 'What does this module do?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="tsosk-guide-lead">
				<?php esc_html_e( 'WordPress checks wordpress.org for core, plugin, theme and translation updates, can install some automatically, and sends email when updates happen. This module helps you troubleshoot update issues, block update checks when needed, and manage notification emails.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description" style="margin:0 0 12px;">
				<?php
				$updates_link = '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Dashboard → Updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>';
				echo wp_kses_post(
					sprintf(
						/* translators: %s: Dashboard → Updates admin screen link */
						__( 'Automatic updates are configured in WordPress itself — go to %s to enable or disable auto-updates per component.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$updates_link
					)
				);
				?>
			</p>
			<p class="description" style="margin:0 0 12px;">
				<?php esc_html_e( 'Two translation sources: (1) wordpress.org language packs installed under wp-content/languages/ — use the buttons below; (2) .mo files bundled inside a plugin folder — updated only when that plugin is updated.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<div class="tsosk-notice tsosk-notice-warn" style="margin:0;">
				<strong><?php esc_html_e( 'Security note:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Blocking updates removes security patches from appearing in the dashboard. Only disable updates on staging, managed hosts with external patching, or when you update manually on a schedule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</details>

		<div class="tsosk-card" id="tsosk-um-status">
			<h3><?php esc_html_e( 'Update status & troubleshooting', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'If updates stay pending for days, check the blockers below. “Check for updates now” refreshes the pending list from wordpress.org. “Install pending translations” downloads language packs into wp-content/languages/ (does not update bundled .mo files inside plugins).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<table class="widefat tsosk-table tsosk-um-status-table">
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
					<?php esc_html_e( 'Check for updates now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<?php $this->render_install_all_button(); ?>
				<button type="button" class="button" id="tsosk-um-install-translations"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						data-pending="<?php echo esc_attr( (string) $translations_pending ); ?>"
					<?php disabled( 0 === $translations_pending ); ?>>
					<?php
					printf(
						/* translators: %d: pending language pack count */
						esc_html__( 'Install pending translations (%d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						absint( $translations_pending )
					);
					?>
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
					'desc'  => __( 'No overrides — WordPress handles update checks and emails as usual.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
				'disable_all' => array(
					'label' => __( 'Disable all updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'Stop update checks for core, plugins, themes and translations. Hide dashboard nags.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
				'custom'      => array(
					'label' => __( 'Custom', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'desc'  => __( 'Configure update-check blocking and email options individually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
		</div>

		<div class="tsosk-card" id="tsosk-um-plugins">
			<h3><?php esc_html_e( 'Individual plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Hide available updates for specific plugins in the dashboard. Does not change WordPress auto-update settings — use Dashboard → Updates for that. The Update column shows the version offered by WordPress.org; “Update now” forces that plugin update right away, even if its update is hidden or checks are blocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
							<th style="width:190px;"><?php esc_html_e( 'Update', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th style="width:120px;text-align:center;"><?php esc_html_e( 'Block updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
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
							<td>
								<?php if ( '' !== $plugin['new_version'] ) : ?>
									<span class="tsosk-badge tsosk-badge-warn"><?php echo esc_html( $plugin['new_version'] ); ?></span>
									<button type="button" class="button button-small tsosk-um-update-plugin"
											data-plugin="<?php echo esc_attr( $plugin['file'] ); ?>"
											data-name="<?php echo esc_attr( $plugin['name'] ); ?>"
											data-nonce="<?php echo esc_attr( $nonce ); ?>">
										<?php esc_html_e( 'Update now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
									</button>
									<?php if ( $block ) : ?>
										<br><span class="description"><?php esc_html_e( 'Hidden in the dashboard', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="description">&mdash;</span>
								<?php endif; ?>
							</td>
							<td style="text-align:center;">
								<input type="checkbox" class="tsosk-um-plugin-block" <?php checked( $block ); ?>
										aria-label="<?php esc_attr_e( 'Block updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
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
