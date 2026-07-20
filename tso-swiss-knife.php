<?php
/**
 * Plugin Name: TSO Swiss Knife – Advanced Maintenance & Developer Toolkit
 * Description: Complete maintenance and developer toolkit: cron manager, debug mode, transients, database tools, hooks inspector, maintenance mode, plugin sandbox and more.
 * Version:     1.0.0
 * Author:      Tu Soporte Online
 * Author URI:  https://www.tusoporteonline.es/
 * Text Domain: tso-swiss-knife-advanced-maintenance-developer-toolkit
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 8.0
 * Tested up to: 7.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'TSOSK_VERSION',  '1.0.0' );
define( 'TSOSK_FILE',     __FILE__ );
define( 'TSOSK_PATH',     plugin_dir_path( __FILE__ ) );
define( 'TSOSK_URL',      plugin_dir_url( __FILE__ ) );
define( 'TSOSK_BASENAME', plugin_basename( __FILE__ ) );
define( 'TSOSK_TEXT_DOMAIN', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
// Writable data lives under uploads/{plugin-slug}/ (WordPress.org guideline).
define( 'TSOSK_UPLOADS_SLUG', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );

/**
 * Absolute path to the WordPress plugins directory (derived from this plugin's __FILE__).
 *
 * @return string Trailing-slash path.
 */
function tsosk_get_plugins_dir(): string {
	return trailingslashit( wp_normalize_path( dirname( TSOSK_PATH ) ) );
}

/**
 * Absolute path to a plugin file under wp-content/plugins.
 *
 * @param string $plugin_file Relative basename path (e.g. akismet/akismet.php).
 * @return string Absolute path (may not exist).
 */
function tsosk_get_plugin_file_path( string $plugin_file ): string {
	$plugin_file = ltrim( str_replace( '\\', '/', $plugin_file ), '/' );
	if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) {
		return '';
	}
	return tsosk_get_plugins_dir() . $plugin_file;
}

/**
 * Absolute path to this plugin's uploads root (basedir/slug).
 *
 * @return string Empty when uploads are unavailable.
 */
function tsosk_get_uploads_root_dir(): string {
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return '';
	}
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
		return '';
	}
	return trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . TSOSK_UPLOADS_SLUG;
}

/**
 * Absolute path to a subdirectory under the plugin uploads root.
 *
 * @param string $subdir Relative subdirectory (e.g. config, logs).
 * @return string Empty when uploads are unavailable.
 */
function tsosk_get_uploads_subdir( string $subdir ): string {
	$root = tsosk_get_uploads_root_dir();
	if ( '' === $root ) {
		return '';
	}
	$subdir = trim( str_replace( '\\', '/', $subdir ), '/' );
	if ( '' === $subdir || false !== strpos( $subdir, '..' ) ) {
		return $root;
	}
	return trailingslashit( $root ) . $subdir;
}

define( 'TSOSK_CONFIG_DIR', tsosk_get_uploads_subdir( 'config' ) );

// ── Autoload includes ─────────────────────────────────────────────────────────
$tsosk_includes = array(
	'includes/class-tsosk-config-storage',
	'includes/class-tsosk-i18n',
	'includes/class-tsosk-support',
	'includes/class-tsosk-sandbox-mu',
	'includes/class-tsosk-option-library',
	'includes/class-tsosk-site-status',
	'includes/class-tsosk-activity-log',
	'includes/class-tsosk-uploads-scanner',
	'includes/class-tsosk-admin',
	'includes/modules/class-tsosk-mod-cron',
	'includes/modules/class-tsosk-mod-debug',
	'includes/modules/class-tsosk-mod-options',
	'includes/modules/class-tsosk-mod-transients',
	'includes/modules/class-tsosk-mod-hidden-profiles',
	'includes/modules/class-tsosk-mod-constants',
	'includes/modules/class-tsosk-mod-internals',
	'includes/modules/class-tsosk-mod-rest-api',
	'includes/modules/class-tsosk-mod-heartbeat',
	'includes/modules/class-tsosk-mod-database',
	'includes/modules/class-tsosk-mod-hooks',
	'includes/modules/class-tsosk-mod-rewrite',
	'includes/modules/class-tsosk-mod-object-cache',
	'includes/modules/class-tsosk-mod-maintenance',
	'includes/modules/class-tsosk-mod-sandbox',
	'includes/modules/class-tsosk-mod-users',
	'includes/modules/class-tsosk-mod-media-cleaner',
	'includes/modules/class-tsosk-mod-media-footprint',
	'includes/modules/class-tsosk-mod-image-sizes-audit',
	'includes/modules/class-tsosk-mod-security',
	'includes/modules/class-tsosk-mod-email',
	'includes/modules/class-tsosk-mod-footprint',
	'includes/modules/class-tsosk-mod-content-audit',
	'includes/modules/class-tsosk-mod-server-files',
	'includes/modules/class-tsosk-mod-health',
	'includes/modules/class-tsosk-mod-file-integrity',
	'includes/modules/class-tsosk-mod-login-protect',
	'includes/modules/class-tsosk-mod-comment-antispam',
	'includes/modules/class-tsosk-mod-meta-editor',
	'includes/modules/class-tsosk-mod-option-library',
	'includes/modules/class-tsosk-mod-site-snapshot',
	'includes/modules/class-tsosk-mod-action-scheduler',
	'includes/modules/class-tsosk-mod-roles',
	'includes/modules/class-tsosk-mod-options-editor',
	'includes/modules/class-tsosk-mod-redirects',
	'includes/modules/class-tsosk-mod-custom-404',
	'includes/modules/class-tsosk-mod-slug-manager',
	'includes/modules/class-tsosk-mod-slow-queries',
	'includes/modules/class-tsosk-mod-search-replace',
	'includes/modules/class-tsosk-mod-update-manager',
	'includes/modules/class-tsosk-mod-admin-menu',
	'includes/modules/class-tsosk-mod-history',
);

foreach ( $tsosk_includes as $tsosk_file ) {
	require_once TSOSK_PATH . $tsosk_file . '.php';
}
unset( $tsosk_includes, $tsosk_file );

// ── Early-load config overrides (JSON in uploads/{plugin-slug}/config) ────────
if ( class_exists( 'TSOSK_Config_Storage' ) ) {
	TSOSK_Config_Storage::apply_early_constants();
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'tsosk_load_textdomain', 0 );
add_action( 'plugins_loaded', 'tsosk_bootstrap_sandbox', 0 );
add_action( 'plugins_loaded', 'tsosk_init' );

/**
 * Load bundled translations (plugin list headers, admin UI, etc.).
 *
 * Uses load_textdomain() only — not load_plugin_textdomain() (discouraged on WordPress.org).
 */
function tsosk_load_textdomain(): void {
	if ( is_textdomain_loaded( TSOSK_TEXT_DOMAIN ) || ! class_exists( 'TSOSK_I18n' ) ) {
		return;
	}

	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	$suffix = TSOSK_I18n::locale_file_suffix( (string) $locale );
	if ( '' === $suffix ) {
		return;
	}

	$mofile = TSOSK_PATH . 'languages/' . TSOSK_TEXT_DOMAIN . '-' . $suffix . '.mo';
	if ( is_readable( $mofile ) ) {
		load_textdomain( TSOSK_TEXT_DOMAIN, $mofile, $locale );
	}
}

/**
 * Fallback sandbox filters when the MU loader is not installed.
 */
function tsosk_bootstrap_sandbox(): void {
	if ( class_exists( 'TSOSK_Mod_Sandbox' ) ) {
		TSOSK_Mod_Sandbox::get_instance()->init();
	}
}

/**
 * Initialise all plugin modules.
 * Modules that need early hooks (heartbeat, REST, maintenance) init first.
 */
function tsosk_init() {
	TSOSK_I18n::get_instance()->init();

	if ( class_exists( 'TSOSK_Config_Storage' ) ) {
		TSOSK_Config_Storage::apply_runtime_hooks();
	}

	$tsosk_runtime_modules = array(
		'TSOSK_Mod_Hidden_Profiles',
		'TSOSK_Mod_Heartbeat',
		'TSOSK_Mod_Internals',
		'TSOSK_Mod_Image_Sizes_Audit',
		'TSOSK_Mod_Update_Manager',
		'TSOSK_Mod_Rest_Api',
		'TSOSK_Mod_Maintenance',
		'TSOSK_Mod_Debug',
		'TSOSK_Mod_Redirects',
		'TSOSK_Mod_Custom_404',
		'TSOSK_Mod_Login_Protect',
		'TSOSK_Mod_Comment_Antispam',
		'TSOSK_Mod_Users',
		'TSOSK_Mod_Admin_Menu',
		'TSOSK_Mod_Slow_Queries',
	);

	foreach ( $tsosk_runtime_modules as $tsosk_class ) {
		if ( class_exists( $tsosk_class ) && method_exists( $tsosk_class, 'get_instance' ) ) {
			$tsosk_module = call_user_func( array( $tsosk_class, 'get_instance' ) );
			if ( method_exists( $tsosk_module, 'init' ) ) {
				$tsosk_module->init();
			}
		}
	}

	if ( is_admin() ) {
		tsosk_init_admin_modules();
		TSOSK_Admin::get_instance()->init();
	}
}

/**
 * Instantiate admin modules so their AJAX hooks are registered on admin-ajax.php.
 */
function tsosk_init_admin_modules() {
	$tsosk_admin_modules = array(
		'TSOSK_Mod_Cron',
		'TSOSK_Mod_Hidden_Profiles',
		'TSOSK_Mod_Debug',
		'TSOSK_Mod_Options',
		'TSOSK_Mod_Transients',
		'TSOSK_Mod_Constants',
		'TSOSK_Mod_Internals',
		'TSOSK_Mod_Rest_Api',
		'TSOSK_Mod_Heartbeat',
		'TSOSK_Mod_Update_Manager',
		'TSOSK_Mod_Database',
		'TSOSK_Mod_Hooks',
		'TSOSK_Mod_Rewrite',
		'TSOSK_Mod_Maintenance',
		'TSOSK_Mod_Sandbox',
		'TSOSK_Mod_Users',
		'TSOSK_Mod_Media_Cleaner',
		'TSOSK_Mod_Media_Footprint',
		'TSOSK_Mod_Image_Sizes_Audit',
		'TSOSK_Mod_Security',
		'TSOSK_Mod_Email',
		'TSOSK_Mod_Footprint',
		'TSOSK_Mod_Content_Audit',
		'TSOSK_Mod_Server_Files',
		'TSOSK_Mod_Health',
		'TSOSK_Mod_File_Integrity',
		'TSOSK_Mod_Login_Protect',
		'TSOSK_Mod_Comment_Antispam',
		'TSOSK_Mod_Slow_Queries',
		'TSOSK_Mod_Search_Replace',
		'TSOSK_Mod_Meta_Editor',
		'TSOSK_Mod_Option_Library',
		'TSOSK_Mod_Site_Snapshot',
		'TSOSK_Mod_Action_Scheduler',
		'TSOSK_Mod_Roles',
		'TSOSK_Mod_Options_Editor',
		'TSOSK_Mod_Redirects',
		'TSOSK_Mod_Custom_404',
		'TSOSK_Mod_Slug_Manager',
		'TSOSK_Mod_Admin_Menu',
		'TSOSK_Mod_History',
	);

	foreach ( $tsosk_admin_modules as $tsosk_class ) {
		if ( class_exists( $tsosk_class ) && method_exists( $tsosk_class, 'get_instance' ) ) {
			call_user_func( array( $tsosk_class, 'get_instance' ) );
		}
	}
}

// ── Activation hook ───────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'tsosk_activate' );

/**
 * Plugin activation: store version and timestamp.
 */
function tsosk_activate() {
	update_option( 'tsosk_activated', time(), false );
	update_option( 'tsosk_version', TSOSK_VERSION, false );

	$login_protect = get_option( 'tsosk_login_protect', array() );
	if ( is_array( $login_protect ) && ! empty( $login_protect['custom_url'] ) && ! empty( $login_protect['login_slug'] ) ) {
		flush_rewrite_rules( false );
	}
}

// ── Deactivation hook ─────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'tsosk_deactivate' );

/**
 * Plugin deactivation: remove maintenance file if we created it.
 */
function tsosk_deactivate() {
	if ( class_exists( 'TSOSK_Sandbox_Mu' ) ) {
		TSOSK_Sandbox_Mu::purge_all();
	}

	$maintenance_file = ABSPATH . '.maintenance';
	if ( file_exists( $maintenance_file ) ) {
		// Only remove if tagged as ours.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read before WP HTTP API is relevant.
		$content = file_get_contents( $maintenance_file );
		if ( $content && strpos( $content, 'tsosk' ) !== false ) {
			wp_delete_file( $maintenance_file );
		}
	}
}
