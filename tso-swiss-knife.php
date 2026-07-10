<?php
/**
 * Plugin Name: TSO Swiss Knife – Advanced Maintenance & Developer Toolkit
 * Description: Complete maintenance and developer toolkit: cron manager, debug mode, transients, database tools, hooks inspector, maintenance mode, plugin sandbox and more.
 * Version:     1.0.0
 * Author:      Tu Soporte Online
 * Author URI:  https://www.tusoporteonline.es/
 * Text Domain: tso-swiss-knife
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
// Config directory inside wp-content/uploads (WP.org compliant location for writable files).
define( 'TSOSK_CONFIG_DIR', WP_CONTENT_DIR . '/uploads/tsosk-config' );

// ── Early-load config overrides ───────────────────────────────────────────────
// These files define constants (WP_DEBUG, DISALLOW_FILE_EDIT, etc.) that must be
// loaded before plugins_loaded to have any effect. They are stored in uploads per
// WordPress.org plugin guidelines (no writing outside uploads is allowed).
foreach ( array( 'tsosk-debug-flags.php', 'tsosk-security-flags.php', 'tsosk-profiles-flags.php' ) as $tsosk_early_f ) {
	$tsosk_early_p = TSOSK_CONFIG_DIR . '/' . $tsosk_early_f;
	if ( file_exists( $tsosk_early_p ) ) {
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingNonPHPFile.IncludingNonPHPFile
		require_once $tsosk_early_p;
	}
}
unset( $tsosk_early_f, $tsosk_early_p );

// ── Autoload includes ─────────────────────────────────────────────────────────
$tsosk_includes = array(
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

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'tsosk_bootstrap_sandbox', 0 );
add_action( 'plugins_loaded', 'tsosk_init' );

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
