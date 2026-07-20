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
	'tsosk_fi_last_results',
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
	'tsosk_login_history',
	'tsosk_disabled_image_sizes',
	'tsosk_health_suppress',
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
delete_transient( 'tsosk_media_full_review_v1' );
delete_transient( 'tsosk_media_full_review_state' );

// ── User meta ──────────────────────────────────────────────────────────────

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
		'tsosk_sandbox_plugins',
		'tsosk_sandbox_token',
		'tsosk_admin_language',
		'tsosk_sidebar_order',
		'tsosk_sidebar_tab_groups',
		'tsosk_sidebar_hidden',
		'tsosk_sidebar_favorites',
		'tsosk_force_password_change',
		'tsosk_last_login'
	)
);

// ── Uploads data (plugin slug folder + legacy short folders) ───────────────

/**
 * Recursively delete a directory under uploads (files then empty dirs).
 *
 * @param string $dir Absolute directory path.
 */
$tsosk_delete_uploads_tree = static function ( string $dir ) use ( &$tsosk_delete_uploads_tree ): void {
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return;
	}
	$entries = scandir( $dir );
	if ( ! is_array( $entries ) ) {
		return;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$item = trailingslashit( $dir ) . $entry;
		if ( is_dir( $item ) ) {
			$tsosk_delete_uploads_tree( $item );
		} elseif ( is_file( $item ) ) {
			wp_delete_file( $item );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $dir );
};

$tsosk_upload_targets = array();
if ( function_exists( 'wp_upload_dir' ) ) {
	$tsosk_uploads = wp_upload_dir( null, false );
	if ( ! empty( $tsosk_uploads['basedir'] ) && empty( $tsosk_uploads['error'] ) ) {
		$tsosk_base = trailingslashit( wp_normalize_path( (string) $tsosk_uploads['basedir'] ) );
		$tsosk_upload_targets[] = $tsosk_base . 'tso-swiss-knife-advanced-maintenance-developer-toolkit';
		$tsosk_upload_targets[] = $tsosk_base . 'tsosk-config';
		$tsosk_upload_targets[] = $tsosk_base . 'tsosk-logs';
		$tsosk_upload_targets[] = $tsosk_base . 'tsosk-l10n';
	}
}

foreach ( array_unique( $tsosk_upload_targets ) as $tsosk_upload_dir ) {
	$tsosk_delete_uploads_tree( $tsosk_upload_dir );
}

// Remove login-protection rewrite rules from the stored rules option.
flush_rewrite_rules( false );

// ── Legacy MU-plugin files (migration cleanup) ─────────────────────────────

$tsosk_legacy_mu_files = array();
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$tsosk_legacy_mu_files = array(
		trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-debug-flags.php',
		trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-security-flags.php',
		trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-profiles-flags.php',
		trailingslashit( WPMU_PLUGIN_DIR ) . 'tsosk-sandbox-loader.php',
	);
}

foreach ( $tsosk_legacy_mu_files as $tsosk_mu_file ) {
	if ( file_exists( $tsosk_mu_file ) ) {
		wp_delete_file( $tsosk_mu_file );
	}
}
