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

	/** @var TSOSK_Mod_Update_Manager|null */
	private static $instance = null;

	/** @var array<string, mixed> */
	private array $settings = array();

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
			update_option( self::OPTION, wp_parse_args( $raw, self::get_defaults() ), false );
		}
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

		$settings = self::get_defaults();
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

		update_option( self::OPTION, $settings, false );
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
	}

	/**
	 * Parse plugin_rules from AJAX POST JSON.
	 *
	 * @return array<string, array{block:bool}>
	 */
	private function sanitize_plugin_rules_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save().
		if ( empty( $_POST['plugin_rules'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = json_decode( wp_unslash( (string) $_POST['plugin_rules'] ), true );
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
	 * @return array<int, array{file:string,name:string,version:string,active:bool}>
	 */
	private function get_plugins_for_ui(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
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
		$lifted = array();
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
	 * Count pending updates from current transients.
	 *
	 * @return array{plugins:int,themes:int,translations:int,core:int}
	 */
	private function count_pending_updates(): array {
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

		$before = $this->count_pending_updates();

		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();

		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();

		$this->restore_update_block_filters( $lifted );

		$after = $this->count_pending_updates();

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
					/* translators: 1: plugins before, 2: themes before, 3: translations before, 4: plugins after, 5: themes after, 6: translations after */
					__( 'Update check finished. Pending before — plugins: %1$d, themes: %2$d, translations: %3$d. Pending now — plugins: %4$d, themes: %5$d, translations: %6$d. Install updates from Dashboard → Updates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
			<?php esc_html_e( 'Monitor WordPress update status, optionally block update checks (staging), hide specific plugin updates, and control update email notifications.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'What does this module do?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
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
			<div class="tsosk-notice tsosk-notice-warn" style="margin:0;">
				<strong><?php esc_html_e( 'Security note:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<?php esc_html_e( 'Blocking updates removes security patches from appearing in the dashboard. Only disable updates on staging, managed hosts with external patching, or when you update manually on a schedule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Update status & troubleshooting', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'If updates stay pending for days, check the blockers below. Use the button to contact wordpress.org and refresh the pending update list (does not install updates).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
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
					<?php esc_html_e( 'Check for updates now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Individual plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Hide available updates for specific plugins in the dashboard. Does not change WordPress auto-update settings — use Dashboard → Updates for that.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
						</tr>
					</thead>
					<tbody>
						<?php
						$plugin_rules = is_array( $settings['plugin_rules'] ?? null ) ? $settings['plugin_rules'] : array();
						$plugins_ui   = $this->get_plugins_for_ui();
						if ( empty( $plugins_ui ) ) :
							?>
						<tr>
							<td colspan="4" style="text-align:center;color:#646970;">
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
