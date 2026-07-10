<?php
/**
 * Uninstall TSO Swiss Knife
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes plugin-owned options, transients, user meta, and config files.
 *
 * @package TSO_Swiss_Knife
 * @since   1.0.0
 */

// Bail if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Options ────────────────────────────────────────────────────────────────

$tsosk_options = array(
	'tsosk_activated',
	'tsosk_version',
	'tsosk_maintenance',
	'tsosk_heartbeat_settings',
	'tsosk_update_manager_settings',
	'tsosk_rest_settings',
	'tsosk_redirect_rules',
	'tsosk_custom_404',
	'tsosk_404_log',
	'tsosk_404_alert_last_sent',
	'tsosk_alert_settings',
	'tsosk_email_diagnostics',
	'tsosk_login_protect',
	'tsosk_login_lockouts',
	'tsosk_login_attempts',
	'tsosk_comment_antispam',
	'tsosk_cas_log',
	'tsosk_cas_rate',
	'tsosk_cas_form_dup',
	'tsosk_fi_ignored',
	'tsosk_sr_history',
	'tsosk_oe_history',
	'tsosk_activity_log',
	'tsosk_activity_log_migrated',
	'tsosk_slow_query_settings',
	'tsosk_slow_query_log',
	'tsosk_sandbox_sessions',
	'tsosk_hidden_profiles',
	'tsosk_admin_menu_settings',
	'tsosk_admin_menu_manifest',
);

foreach ( $tsosk_options as $tsosk_option ) {
	delete_option( $tsosk_option );
}

// ── Transients ─────────────────────────────────────────────────────────────

$tsosk_transients = array(
	'tsosk_fi_results',
	'tsosk_fi_checksums',
);

foreach ( $tsosk_transients as $tsosk_transient ) {
	delete_transient( $tsosk_transient );
}

delete_transient( 'tsosk_media_footprint_v1' );
delete_transient( 'tsosk_image_sizes_audit_v1' );

// ── User meta ──────────────────────────────────────────────────────────────

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s)",
		'tsosk_sandbox_plugins',
		'tsosk_sandbox_token',
		'tsosk_admin_language',
		'tsosk_sidebar_order',
		'tsosk_sidebar_tab_groups',
		'tsosk_sidebar_hidden',
		'tsosk_sidebar_favorites'
	)
);

// ── Config files (uploads/tsosk-config) ────────────────────────────────────

$tsosk_config_dir = WP_CONTENT_DIR . '/uploads/tsosk-config';
$tsosk_config_files = array(
	'tsosk-debug-flags.php',
	'tsosk-security-flags.php',
	'tsosk-profiles-flags.php',
);

$tsosk_config_guard_files = array( '.htaccess', 'index.php' );

foreach ( $tsosk_config_files as $tsosk_config_file ) {
	$tsosk_path = $tsosk_config_dir . '/' . $tsosk_config_file;
	if ( file_exists( $tsosk_path ) ) {
		wp_delete_file( $tsosk_path );
	}
}

foreach ( $tsosk_config_guard_files as $tsosk_guard_file ) {
	$tsosk_path = $tsosk_config_dir . '/' . $tsosk_guard_file;
	if ( file_exists( $tsosk_path ) ) {
		wp_delete_file( $tsosk_path );
	}
}

if ( is_dir( $tsosk_config_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $tsosk_config_dir );
}

// ── Compiled translation cache (uploads/tsosk-l10n) ───────────────────────

$tsosk_l10n_dir = WP_CONTENT_DIR . '/uploads/tsosk-l10n';
if ( is_dir( $tsosk_l10n_dir ) ) {
	$tsosk_l10n_files = glob( $tsosk_l10n_dir . '/*.mo' );
	if ( is_array( $tsosk_l10n_files ) ) {
		foreach ( $tsosk_l10n_files as $tsosk_l10n_file ) {
			if ( is_file( $tsosk_l10n_file ) ) {
				wp_delete_file( $tsosk_l10n_file );
			}
		}
	}
	foreach ( array( '.htaccess', 'index.php' ) as $tsosk_l10n_guard ) {
		$tsosk_l10n_guard_path = $tsosk_l10n_dir . '/' . $tsosk_l10n_guard;
		if ( file_exists( $tsosk_l10n_guard_path ) ) {
			wp_delete_file( $tsosk_l10n_guard_path );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $tsosk_l10n_dir );
}

// Remove login-protection rewrite rules from the stored rules option.
flush_rewrite_rules( false );

// ── Legacy MU-plugin files (migration cleanup) ─────────────────────────────

$tsosk_legacy_mu_files = array(
	trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-debug-flags.php',
	trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-security-flags.php',
	trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-sandbox-loader.php',
);

foreach ( $tsosk_legacy_mu_files as $tsosk_mu_file ) {
	if ( file_exists( $tsosk_mu_file ) ) {
		wp_delete_file( $tsosk_mu_file );
	}
}
