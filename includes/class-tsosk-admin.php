<?php
/**
 * TSO Swiss Knife – Admin: vertical-tab page and asset enqueue.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Admin
 *
 * Registers the Tools › TSO Swiss Knife admin page, enqueues assets,
 * renders the vertical-tab shell and dispatches rendering to modules.
 */
class TSOSK_Admin {

	/** @var TSOSK_Admin|null Singleton instance. */
	private static $instance = null;

	/** User-meta key for custom sidebar tab order. */
	private const ORDER_META_KEY    = 'tsosk_sidebar_order';

	/** User-meta key for hidden sidebar tabs. */
	private const HIDDEN_META_KEY   = 'tsosk_sidebar_hidden';

	/** User-meta key for favorites bar order (slug list). */
	private const FAVORITES_META_KEY = 'tsosk_sidebar_favorites';

	/** User-meta key for user-assigned sidebar tab groups (slug => group id). */
	private const GROUPS_META_KEY   = 'tsosk_sidebar_tab_groups';

	/** @var string Current active tab slug. */
	private $current_tab = 'hidden-profiles';

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Admin
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @codeCoverageIgnore */
	private function __construct() {}

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'handle_language_switch' ) );
		add_action( 'admin_init', array( $this, 'redirect_tools_page_url' ) );
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'wp_ajax_tsosk_save_sidebar_order',  array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_tsosk_reset_sidebar_order', array( $this, 'ajax_reset_order' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . TSOSK_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Persist plugin language preference for the current admin user.
	 */
	public function handle_language_switch(): void {
		if ( empty( $_GET['page'] ) || 'tso-swiss-knife' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
			return;
		}
		if ( empty( $_GET['tsosk_lang'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'tsosk_language_switch' );

		$lang = sanitize_key( wp_unslash( $_GET['tsosk_lang'] ) );
		if ( isset( TSOSK_I18n::get_languages()[ $lang ] ) ) {
			update_user_meta( get_current_user_id(), TSOSK_I18n::LANGUAGE_META_KEY, $lang );
		}

		$redirect = remove_query_arg( array( 'tsosk_lang', '_wpnonce' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Redirect legacy admin.php?page=tso-swiss-knife URLs to the Tools submenu location.
	 */
	public function redirect_tools_page_url(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'admin.php' !== $pagenow || empty( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only legacy page slug redirect.
		$page = sanitize_key( wp_unslash( (string) $_GET['page'] ) );
		if ( 'tso-swiss-knife' !== $page ) {
			return;
		}

		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only legacy URL redirect; values sanitized below.
		foreach ( $_GET as $key => $value ) {
			$key = sanitize_key( wp_unslash( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$args[ $key ] = map_deep( wp_unslash( $value ), 'sanitize_text_field' );
			} else {
				$args[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		$target = add_query_arg( $args, admin_url( 'tools.php' ) );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Register the admin menu page under Tools.
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'TSO Swiss Knife', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'TSO Swiss Knife', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'manage_options',
			'tso-swiss-knife',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JS only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_tso-swiss-knife' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'tsosk-admin',
			TSOSK_URL . 'assets/css/tsosk-admin.css',
			array(),
			TSOSK_VERSION . '.' . (string) filemtime( TSOSK_PATH . 'assets/css/tsosk-admin.css' )
		);
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_media();
		wp_enqueue_script(
			'tsosk-admin',
			TSOSK_URL . 'assets/js/tsosk-admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			TSOSK_VERSION . '.' . (string) filemtime( TSOSK_PATH . 'assets/js/tsosk-admin.js' ),
			true
		);
		$tabs_for_js = $this->apply_saved_order( $this->get_tabs() );
		$base_url_js = admin_url( 'tools.php?page=tso-swiss-knife' );

		wp_localize_script(
			'tsosk-admin',
			'tsosk',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'admin_menu_url'      => admin_url( 'tools.php?page=tso-swiss-knife&tab=admin-menu' ),
				'nonce'               => wp_create_nonce( 'tsosk_global_nonce' ),
				'sidebar_groups'      => $this->get_sidebar_group_labels(),
				'sidebar_group_order' => array_keys( $this->get_tab_group_labels() ),
				'tab_search'          => TSOSK_Site_Status::build_search_index(
					$tabs_for_js,
					$this->get_tab_group_labels(),
					$base_url_js
				),
				'dev_mode_active'     => TSOSK_Site_Status::is_developer_mode_active(),
				'debug_nonce'         => wp_create_nonce( 'tsosk_debug_nonce' ),
				'snapshot_section_labels' => class_exists( 'TSOSK_Mod_Site_Snapshot' )
					? TSOSK_Mod_Site_Snapshot::get_section_labels()
					: array(),
				'i18n'            => array(
					'confirm_delete'  => __( 'Are you sure you want to delete this item?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'confirm_purge'   => __( 'Purge all items?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'running'         => __( 'Running…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'done'            => __( 'Done!', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'error'           => __( 'Error. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'confirm_sandbox' => __( 'This will reload the page with the selected plugins. Continue?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'clear_pause'     => __( 'Clear pause', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'save_redirect'   => __( 'Save Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'update_redirect' => __( 'Update Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'debug_log_ready' => __( 'WP_DEBUG and WP_DEBUG_LOG are selected. Save settings to activate debug.log.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'run'             => __( 'Run', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'edit'            => __( 'Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'save_schedule'   => __( 'Save schedule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'cron_rescheduled'=> __( 'Event rescheduled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'purge_expired'   => __( 'Purge Expired', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'purge_all'       => __( 'Purge All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'soft_flush'      => __( 'Soft Flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'hard_flush'      => __( 'Hard Flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'flush_cache'     => __( 'Flush Object Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'on'              => __( 'ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'off'             => __( 'OFF', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'enable'          => __( 'Enable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'disable'         => __( 'Disable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'apply_sandbox'   => __( 'Apply Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'reset_sandbox'   => __( 'Reset Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'errors'          => __( 'Errors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'clear_404_log'   => __( 'Clear 404 Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'save_alerts'     => __( 'Save Alert Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'close_sessions'  => __( 'Close Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'close_all_sessions' => __( 'Close all sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'force_pwd_confirm' => __( 'Force this user to change their password on next login and close all sessions?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'users_select_one'  => __( 'Select at least one user.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'users_bulk_confirm'=> __( 'Apply bulk action to selected inactive users?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'dangerous_cap'     => __( 'High risk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'roles_only_a'      => __( 'Only in role A', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'roles_only_b'      => __( 'Only in role B', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'roles_both'        => __( 'In both roles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'roles_admin_readonly' => __( 'Administrator capabilities loaded (read-only).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'regenerate'           => __( 'Regenerate Thumbnails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'media_full_review'    => __( 'Run full media review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'media_full_review_starting' => __( 'Starting full media review…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'media_footprint_scan' => __( 'Scan uploads folder', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'image_sizes_scan'     => __( 'Run image sizes audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'image_sizes_save'     => __( 'Save image size settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'send_test'       => __( 'Send Test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_connecting'   => __( 'Connecting to WordPress.org API…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_clean'        => __( 'All core files are intact.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_issues'       => __( 'issue(s) found — review the results below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_no_html'      => __( 'Scan complete.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_ignore'       => __( 'Ignore', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_remove'       => __( 'Remove', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'fi_timeout'      => __( 'Scan timed out. The server took too long to respond. Try Force Re-scan or check PHP max_execution_time.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_unlock'       => __( 'Unlock', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_reset'        => __( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_unlock_all'   => __( 'Unlock All', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_unlock_all_confirm' => __( 'Unlock all currently locked IP addresses?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_clear_log_confirm'  => __( 'Clear the entire lockout log? This cannot be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_clear_log'    => __( 'Clear Log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'lp_generate'     => __( 'Generate', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_edit'         => __( 'Edit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_save'         => __( 'Save Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_delete'       => __( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_add'          => __( 'Add Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_protected'    => __( 'Protected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_view'         => __( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_show_protected' => __( 'Show protected options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_hide_protected' => __( 'Hide protected options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_add_hint_text'  => __( 'Plain text value.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_add_hint_integer' => __( 'Whole number only, for example: 0 or 42.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_add_hint_json'  => __( 'Valid JSON object or array.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_add_hint_serialized' => __( 'Valid PHP serialized string.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_raw'          => __( 'Show raw value', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_pretty'       => __( 'Show formatted value', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_confirm_del'  => __( 'Delete this option permanently? This cannot be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_no_results'   => __( 'No options found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_type_ser'     => __( 'Serialized PHP — edit only if you know PHP serialization syntax.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_type_json'    => __( 'JSON — must remain valid JSON after editing.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_page'         => __( 'Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_of'           => __( 'of', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'oe_options'      => __( 'options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_rename'       => __( 'Rename', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_save_slug'    => __( 'Save New Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_no_posts'     => __( 'No posts found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_chars'        => __( 'characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_fix'     => __( 'Bulk Fix Selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_preview_title' => __( 'Bulk slug fix preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_confirm_btn' => __( 'Confirm changes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_col_skipped' => __( 'Skipped', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_col_reason'  => __( 'Reason', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_confirm' => __( 'Truncate the selected slugs and create redirects where enabled? This changes permalinks immediately.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_col_post' => __( 'Post', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_col_before' => __( 'Before', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_bulk_col_after' => __( 'After', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_total_items'  => __( 'items', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'ca_remove_sc_confirm'  => __( 'Remove this broken shortcode from the post content?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'ca_remove_all_confirm' => __( 'Remove all broken shortcodes from this post?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'asched_cancel_confirm' => __( 'Cancel this scheduled action?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sm_redirect_note' => __( 'Redirect will be added to TSO Swiss Knife Redirects automatically.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_clear_confirm' => __( 'Clear the entire slow query log? This cannot be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_delete_confirm'=> __( 'Delete this entry?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_ignore_confirm'=> __( 'Ignore this SQL pattern? Matching queries will stop being logged.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_no_results'    => __( 'No slow queries match your search.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_slow'          => __( 'slow', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_page'          => __( 'Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sq_of'            => __( 'of', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_preview_none'  => __( 'No matches found for the search term in the selected tables.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_confirm_exec'  => __( 'This will modify the database. Have you made a backup? Click OK to proceed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_no_tables'     => __( 'Select at least one table.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_empty_search'  => __( 'Enter a search term.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_stale_preview' => __( 'Settings changed after preview. Run Preview again before executing.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_preview_btn'   => __( 'Preview changes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_tables'        => __( 'tables', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_confirm_hint'  => __( 'Review below and click "Execute replace" to commit changes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'snapshot_confirm_import' => __( 'Import will overwrite settings for the sections in this snapshot. Continue?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'snapshot_no_sections'    => __( 'Select at least one section to export.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_more'          => __( 'more rows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_pk'            => __( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_execute_btn'   => __( 'Execute replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'history_clear'    => __( 'Clear all activity history? This cannot be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'delete'           => __( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'loading'          => __( 'Loading…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'no_title'         => __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_rows'          => __( 'rows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sr_matches'       => __( 'matches', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'previous'        => __( 'Previous', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'next'            => __( 'Next', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'save'            => __( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_save'    => __( 'Save', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_hide'    => __( 'Hide', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_show'    => __( 'Show', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_reset'   => __( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_cancel'  => __( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					/* translators: %s: sidebar tab label. */
					'sidebar_drop_before' => __( 'Drop above: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					/* translators: %s: sidebar tab label. */
					'sidebar_drop_after'  => __( 'Drop below: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'sidebar_drop_end'    => __( 'Drop at end of list', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'favorites_remove'    => __( 'Remove from favorites', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'favorites_add'       => __( 'Add to favorites', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'cancel'              => __( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'no_matches'          => __( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'search_placeholder' => __( 'Search… (Ctrl+K)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'search_no_results'  => __( 'No matching tools.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'search_hint'        => __( 'Type to find a tab, then press Enter or click a result.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'site_status_label'  => __( 'Site status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'dev_mode_enable'    => __( 'Enable dev mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'dev_mode_disable'   => __( 'Disable dev mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'custom_404_active'  => __( 'Custom 404 active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'custom_404_default' => __( 'Using theme default 404', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'custom_404_select_preview' => __( 'Select a published page to preview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'am_reset_confirm'   => __( 'Reset the sidebar menu to WordPress defaults?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'maint_select_logo'  => __( 'Select logo', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'maint_use_logo'     => __( 'Use this image', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'ol_preview_loaded'   => __( 'Preview loaded.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'expired'         => __( 'Expired', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					/* translators: %s: log file label shown in the confirmation dialog. */
					'debug_empty_log_confirm' => __( 'Empty "%s"? All log entries will be deleted but the file will remain on disk. This cannot be undone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					/* translators: %s: log file label shown in the confirmation dialog. */
					'debug_shrink_log_confirm' => __( 'Keep only the last 500 lines of %s? Older lines will be archived.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'refresh_log'              => __( 'Refresh', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			)
		);
	}

	/**
	 * Add "Settings" link on the plugins list page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'tools.php?page=tso-swiss-knife' ) ) . '">'
			. esc_html__( 'Open', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>'
		);
		return $links;
	}

	// ── Sidebar order ────────────────────────────────────────────────────────

	/**
	 * Return tabs sorted by the current user's saved order.
	 * Unknown slugs (new modules added later) are appended at the end.
	 *
	 * @param array<string,array> $tabs Raw tab registry.
	 * @return array<string,array> Ordered tabs.
	 */
	private function apply_saved_order( array $tabs ): array {
		$uid    = get_current_user_id();
		$order  = get_user_meta( $uid, self::ORDER_META_KEY, true );
		$hidden = get_user_meta( $uid, self::HIDDEN_META_KEY, true );

		if ( ! is_array( $order ) || empty( $order ) ) {
			return $tabs;
		}

		$order  = $this->sanitize_sidebar_order( $order, $tabs );
		$hidden = is_array( $hidden ) ? $hidden : array();
		$groups = get_user_meta( $uid, self::GROUPS_META_KEY, true );
		$groups = is_array( $groups ) ? $groups : array();
		$groups = $this->sanitize_sidebar_groups( $groups, $tabs );

		// Re-sort tabs according to saved order.
		$sorted   = array();
		$unsorted = $tabs;

		foreach ( $order as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( isset( $tabs[ $slug ] ) ) {
				$sorted[ $slug ] = $tabs[ $slug ];
				if ( isset( $groups[ $slug ] ) ) {
					$sorted[ $slug ]['group'] = $groups[ $slug ];
				}
				unset( $unsorted[ $slug ] );
			}
		}

		// Append any tabs not in the saved order (new modules added after last save).
		foreach ( $unsorted as $slug => $tab ) {
			$sorted[ $slug ] = $tab;
		}

		// Apply hidden flag (hidden tabs still exist in the registry but are
		// filtered from the sidebar nav and treated as invisible; the tab is
		// still accessible by direct URL so nothing ever breaks).
		foreach ( $hidden as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( isset( $sorted[ $slug ] ) ) {
				$sorted[ $slug ]['hidden'] = true;
			}
		}

		return $sorted;
	}

	/**
	 * Sanitize flat sidebar order (preserve user order, dedupe, append new tabs).
	 *
	 * @param array<int, string>   $order Slugs from saved or posted order.
	 * @param array<string, array> $tabs  Tab registry.
	 * @return array<int, string>
	 */
	private function sanitize_sidebar_order( array $order, array $tabs ): array {
		$sanitized = array();
		$seen      = array();

		foreach ( $order as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! $slug || isset( $seen[ $slug ] ) || ! isset( $tabs[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$sanitized[]   = $slug;
		}

		foreach ( array_keys( $tabs ) as $slug ) {
			if ( ! isset( $seen[ $slug ] ) ) {
				$sanitized[] = $slug;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize slug => group map from user layout.
	 *
	 * @param array<string, string> $groups Slug => group id.
	 * @param array<string, array>  $tabs   Tab registry.
	 * @return array<string, string>
	 */
	private function sanitize_sidebar_groups( array $groups, array $tabs ): array {
		$labels     = $this->get_tab_group_labels();
		$sanitized  = array();

		foreach ( $groups as $slug => $group_id ) {
			$slug     = sanitize_key( (string) $slug );
			$group_id = sanitize_key( (string) $group_id );
			if ( $slug && isset( $tabs[ $slug ] ) && isset( $labels[ $group_id ] ) ) {
				$sanitized[ $slug ] = $group_id;
			}
		}

		return $sanitized;
	}

	/**
	 * Parse slug => group map from AJAX (JSON).
	 *
	 * @return array<string, string>
	 */
	private function parse_sidebar_groups_from_request(): array {
		$groups = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save_order().
		if ( isset( $_POST['groups_json'] ) ) {
			$decoded = TSOSK_Support::get_post_json_array( 'groups_json' );
			foreach ( $decoded as $slug => $group ) {
				$slug  = sanitize_key( (string) $slug );
				$group = sanitize_key( (string) $group );
				if ( '' !== $slug && '' !== $group ) {
					$groups[ $slug ] = $group;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $groups;
	}

	/**
	 * Parse slug list from AJAX (JSON or legacy order[] array).
	 *
	 * @return array<int, string>
	 */
	private function parse_sidebar_order_from_request(): array {
		$order = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save_order().
		if ( isset( $_POST['order_json'] ) ) {
			$order = TSOSK_Support::get_post_json_array( 'order_json' );
		} elseif ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) ) {
			$order = map_deep( wp_unslash( $_POST['order'] ), 'sanitize_key' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->normalize_favorites_list( is_array( $order ) ? $order : array() );
	}

	/**
	 * Parse hidden slug list from AJAX (JSON or legacy hidden[] array).
	 *
	 * @return array<int, string>
	 */
	private function parse_sidebar_hidden_from_request(): array {
		$hidden = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save_order().
		if ( isset( $_POST['hidden_json'] ) ) {
			$hidden = TSOSK_Support::get_post_json_array( 'hidden_json' );
		} elseif ( isset( $_POST['hidden'] ) && is_array( $_POST['hidden'] ) ) {
			$hidden = map_deep( wp_unslash( $_POST['hidden'] ), 'sanitize_key' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->normalize_favorites_list( is_array( $hidden ) ? $hidden : array() );
	}

	/**
	 * Parse favorites slug list from AJAX (JSON or legacy array).
	 *
	 * @return array<int, string>
	 */
	private function parse_sidebar_favorites_from_request(): array {
		$favorites = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in ajax_save_order().
		if ( isset( $_POST['favorites_json'] ) ) {
			$favorites = TSOSK_Support::get_post_json_array( 'favorites_json' );
		} elseif ( isset( $_POST['favorites'] ) && is_array( $_POST['favorites'] ) ) {
			$favorites = map_deep( wp_unslash( $_POST['favorites'] ), 'sanitize_key' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->normalize_favorites_list( is_array( $favorites ) ? $favorites : array() );
	}

	/**
	 * Deduplicate favorites while preserving order.
	 *
	 * @param array<int, string> $favorites Slug list.
	 * @return array<int, string>
	 */
	private function normalize_favorites_list( array $favorites ): array {
		$seen    = array();
		$cleaned = array();

		foreach ( $favorites as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( ! $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$cleaned[]     = $slug;
		}

		return $cleaned;
	}

	/**
	 * Saved favorites for the current user (valid slugs only).
	 *
	 * @param array<string, array> $tabs Tab registry.
	 * @return array<int, string>
	 */
	private function get_user_favorites( array $tabs ): array {
		$favorites = get_user_meta( get_current_user_id(), self::FAVORITES_META_KEY, true );
		if ( false === $favorites || '' === $favorites ) {
			return isset( $tabs['history'] ) ? array( 'history' ) : array();
		}
		if ( ! is_array( $favorites ) || empty( $favorites ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->normalize_favorites_list( $favorites ) as $slug ) {
			if ( isset( $tabs[ $slug ] ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * AJAX: save sidebar order and hidden-tabs list for the current user.
	 */
	public function ajax_save_order(): void {
		check_ajax_referer( 'tsosk_sidebar_order_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$uid      = get_current_user_id();
		$raw_tabs = $this->get_tabs(); // All valid slugs.

		// Validate and sanitize incoming order array.
		$order = array();
		foreach ( $this->parse_sidebar_order_from_request() as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug && array_key_exists( $slug, $raw_tabs ) ) {
				$order[] = $slug;
			}
		}
		$order = $this->sanitize_sidebar_order( $order, $raw_tabs );

		$groups = $this->sanitize_sidebar_groups(
			$this->parse_sidebar_groups_from_request(),
			$raw_tabs
		);

		// Validate hidden list.
		$hidden = array();
		foreach ( $this->parse_sidebar_hidden_from_request() as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug && array_key_exists( $slug, $raw_tabs ) ) {
				$hidden[] = $slug;
			}
		}

		$favorites = array();
		foreach ( $this->parse_sidebar_favorites_from_request() as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug && array_key_exists( $slug, $raw_tabs ) ) {
				$favorites[] = $slug;
			}
		}
		$favorites = $this->normalize_favorites_list( $favorites );

		update_user_meta( $uid, self::ORDER_META_KEY, $order );
		update_user_meta( $uid, self::GROUPS_META_KEY, $groups );
		update_user_meta( $uid, self::HIDDEN_META_KEY, $hidden );
		update_user_meta( $uid, self::FAVORITES_META_KEY, $favorites );

		wp_send_json_success( __( 'Sidebar layout saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: reset sidebar to default order for the current user.
	 */
	public function ajax_reset_order(): void {
		check_ajax_referer( 'tsosk_sidebar_order_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$uid = get_current_user_id();
		delete_user_meta( $uid, self::ORDER_META_KEY );
		delete_user_meta( $uid, self::GROUPS_META_KEY );
		delete_user_meta( $uid, self::HIDDEN_META_KEY );
		delete_user_meta( $uid, self::FAVORITES_META_KEY );
		wp_send_json_success( __( 'Sidebar reset to default order.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	// ── Tab registry ──────────────────────────────────────────────────────────

	/**
	 * Sidebar group labels for JS (organise mode badges).
	 *
	 * @return array<string, string>
	 */
	public function get_sidebar_group_labels(): array {
		return $this->get_tab_group_labels();
	}

	/**
	 * Sidebar group labels (order preserved).
	 *
	 * @return array<string, string> Group id => label.
	 */
	private function get_tab_group_labels(): array {
		return array(
			'profiles'    => __( 'Profiles & config', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'performance' => __( 'Performance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'database'    => __( 'Database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'security'    => __( 'Security', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'content'     => __( 'Content & URLs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'development' => __( 'Development', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'site'        => __( 'Site & tools', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Map tab slug to sidebar group id.
	 *
	 * @return array<string, string>
	 */
	private function get_tab_group_map(): array {
		return array(
			'hidden-profiles' => 'profiles',
			'constants'       => 'profiles',
			'internals'       => 'profiles',
			'cron'              => 'performance',
			'action-scheduler'  => 'performance',
			'heartbeat'       => 'performance',
			'update-manager'  => 'site',
			'slow-queries'    => 'performance',
			'transients'      => 'performance',
			'database'        => 'database',
			'search-replace'  => 'database',
			'options'         => 'database',
			'options-editor'  => 'database',
			'meta-editor'     => 'database',
			'option-library'  => 'database',
			'site-snapshot'   => 'database',
			'security'        => 'security',
			'login-protect'   => 'security',
			'comment-antispam'=> 'security',
			'rest-api'        => 'security',
			'file-integrity'  => 'security',
			'redirects'       => 'content',
			'custom-404'      => 'content',
			'slug-manager'    => 'content',
			'content-audit'   => 'content',
			'media-cleaner'   => 'content',
			'media-footprint' => 'content',
			'image-sizes-audit' => 'content',
			'debug'           => 'development',
			'sandbox'         => 'development',
			'hooks'           => 'development',
			'rewrite'         => 'development',
			'server-files'    => 'development',
			'maintenance'     => 'site',
			'users'           => 'site',
			'roles'           => 'site',
			'email'           => 'site',
			'footprint'       => 'site',
			'health'          => 'site',
			'admin-menu'      => 'site',
			'history'         => 'site',
		);
	}

	/**
	 * Group ordered tabs for sidebar rendering.
	 *
	 * @param array<string, array> $tabs Ordered tabs from apply_saved_order().
	 * @return array<string, array<string, array>> Group id => slug => tab.
	 */
	private function group_tabs_for_sidebar( array $tabs ): array {
		$labels  = $this->get_tab_group_labels();
		$grouped = array();
		foreach ( array_keys( $labels ) as $group_id ) {
			$grouped[ $group_id ] = array();
		}
		foreach ( $tabs as $slug => $tab ) {
			$group_id = $tab['group'] ?? 'site';
			if ( ! isset( $grouped[ $group_id ] ) ) {
				$grouped[ $group_id ] = array();
			}
			$grouped[ $group_id ][ $slug ] = $tab;
		}
		return array_filter(
			$grouped,
			static function ( array $group_tabs ): bool {
				return ! empty( $group_tabs );
			}
		);
	}

	/**
	 * Returns the full list of tabs: slug => [ label, icon, module_class, group ].
	 *
	 * @return array<string, array{label: string, icon: string, class: string, group: string}>
	 */
	private function get_tabs(): array {
		$group_map = $this->get_tab_group_map();
		$tabs      = array(
			'history' => array(
				'label' => __( 'Activity History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-backup',
				'class' => 'TSOSK_Mod_History',
			),
			'hidden-profiles' => array(
				'label' => __( 'Hidden WordPress Profiles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-settings',
				'class' => 'TSOSK_Mod_Hidden_Profiles',
			),
			'cron'       => array(
				'label' => __( 'Cron Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-clock',
				'class' => 'TSOSK_Mod_Cron',
			),
			'action-scheduler' => array(
				'label' => __( 'Action Scheduler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-list-view',
				'class' => 'TSOSK_Mod_Action_Scheduler',
			),
			'debug'      => array(
				'label' => __( 'Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-generic',
				'class' => 'TSOSK_Mod_Debug',
			),
			'options-editor' => array(
				'label' => __( 'Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-edit',
				'class' => 'TSOSK_Mod_Options_Editor',
			),
			'meta-editor' => array(
				'label' => __( 'Meta Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-id',
				'class' => 'TSOSK_Mod_Meta_Editor',
			),
			'option-library' => array(
				'label' => __( 'Option Library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-book',
				'class' => 'TSOSK_Mod_Option_Library',
			),
			'site-snapshot' => array(
				'label' => __( 'Export/Import TSO Configuration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-backup',
				'class' => 'TSOSK_Mod_Site_Snapshot',
			),
			'options'    => array(
				'label' => __( 'TSO Link Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-links',
				'class' => 'TSOSK_Mod_Options',
			),
			'transients' => array(
				'label' => __( 'Transients', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-database-remove',
				'class' => 'TSOSK_Mod_Transients',
			),
			'constants'  => array(
				'label' => __( 'WP Constants', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-editor-code',
				'class' => 'TSOSK_Mod_Constants',
			),
			'internals'  => array(
				'label' => __( 'WP Internals', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-tools',
				'class' => 'TSOSK_Mod_Internals',
			),
			'rest-api'   => array(
				'label' => __( 'REST API', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-networking',
				'class' => 'TSOSK_Mod_Rest_Api',
			),
			'heartbeat'  => array(
				'label' => __( 'Heartbeat', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-heart',
				'class' => 'TSOSK_Mod_Heartbeat',
			),
			'update-manager' => array(
				'label' => __( 'Update Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-update',
				'class' => 'TSOSK_Mod_Update_Manager',
			),
			'database'   => array(
				'label' => __( 'TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-database',
				'class' => 'TSOSK_Mod_Database',
			),
			'slow-queries' => array(
				'label' => __( 'Slow Query Monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-clock',
				'class' => 'TSOSK_Mod_Slow_Queries',
			),
			'search-replace' => array(
				'label' => __( 'Search & Replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-search',
				'class' => 'TSOSK_Mod_Search_Replace',
			),
			'hooks'      => array(
				'label' => __( 'Hooks Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-filter',
				'class' => 'TSOSK_Mod_Hooks',
			),
			'rewrite'    => array(
				'label' => __( 'Rewrite Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-links',
				'class' => 'TSOSK_Mod_Rewrite',
			),
			'server-files' => array(
				'label' => __( 'Server Files Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-media-code',
				'class' => 'TSOSK_Mod_Server_Files',
			),
			'redirects'  => array(
				'label' => __( 'Redirects', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-randomize',
				'class' => 'TSOSK_Mod_Redirects',
			),
			'custom-404' => array(
				'label' => __( 'Custom 404 Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-warning',
				'class' => 'TSOSK_Mod_Custom_404',
			),
			'slug-manager' => array(
				'label' => __( 'Slug Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-links',
				'class' => 'TSOSK_Mod_Slug_Manager',
			),
			'health'     => array(
				'label' => __( 'Health Report', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-chart-area',
				'class' => 'TSOSK_Mod_Health',
			),
			'admin-menu' => array(
				'label' => __( 'Reorder & Hide Sidebar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-menu',
				'class' => 'TSOSK_Mod_Admin_Menu',
			),
			'users'      => array(
				'label' => __( 'Users & Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-groups',
				'class' => 'TSOSK_Mod_Users',
			),
			'roles'      => array(
				'label' => __( 'Roles & Capabilities', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-admin-users',
				'class' => 'TSOSK_Mod_Roles',
			),
			'media-cleaner' => array(
				'label' => __( 'Media Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-format-gallery',
				'class' => 'TSOSK_Mod_Media_Cleaner',
			),
			'media-footprint' => array(
				'label' => __( 'Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-chart-pie',
				'class' => 'TSOSK_Mod_Media_Footprint',
			),
			'image-sizes-audit' => array(
				'label' => __( 'Image Sizes Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-image-crop',
				'class' => 'TSOSK_Mod_Image_Sizes_Audit',
			),
			'security'   => array(
				'label' => __( 'Security Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-shield-alt',
				'class' => 'TSOSK_Mod_Security',
			),
			'file-integrity' => array(
				'label' => __( 'Core File Integrity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-media-code',
				'class' => 'TSOSK_Mod_File_Integrity',
			),
			'login-protect' => array(
				'label' => __( 'Login Protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-lock',
				'class' => 'TSOSK_Mod_Login_Protect',
			),
			'comment-antispam' => array(
				'label' => __( 'Comment Anti-Spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-format-chat',
				'class' => 'TSOSK_Mod_Comment_Antispam',
			),
			'email'      => array(
				'label' => __( 'Email Diagnostics', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-email-alt',
				'class' => 'TSOSK_Mod_Email',
			),
			'footprint'  => array(
				'label' => __( 'Plugin Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-screenoptions',
				'class' => 'TSOSK_Mod_Footprint',
			),
			'content-audit' => array(
				'label' => __( 'Content Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-media-document',
				'class' => 'TSOSK_Mod_Content_Audit',
			),
			'maintenance' => array(
				'label' => __( 'Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-visibility',
				'class' => 'TSOSK_Mod_Maintenance',
			),
			'sandbox'    => array(
				'label' => __( 'Plugin Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'  => 'dashicons-shield',
				'class' => 'TSOSK_Mod_Sandbox',
			),
		);

		foreach ( $tabs as $slug => $tab ) {
			$tabs[ $slug ]['group'] = $group_map[ $slug ] ?? 'site';
		}

		return $tabs;
	}

	/**
	 * Output one sidebar nav row (main list or favorites bar).
	 *
	 * @param string               $slug       Tab slug.
	 * @param array<string, mixed> $tab        Tab data.
	 * @param string               $base_url   Admin page URL.
	 * @param string               $current    Active tab slug.
	 * @param bool                 $favorites  True when rendering the favorites bar.
	 * @param bool                 $is_hidden  Hidden in main sidebar.
	 */
	private function render_sidebar_nav_row( string $slug, array $tab, string $base_url, string $current, bool $favorites = false, bool $is_hidden = false ): void {
		$row_class = 'tsosk-nav-row';
		if ( $favorites ) {
			$row_class .= ' tsosk-fav-row';
		} elseif ( $is_hidden ) {
			$row_class .= ' tsosk-nav-hidden';
		}
		$group_id = $tab['group'] ?? 'site';
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>"
		     data-slug="<?php echo esc_attr( $slug ); ?>"
		     data-group="<?php echo esc_attr( $group_id ); ?>">
			<span class="tsosk-drag-handle dashicons dashicons-menu" aria-hidden="true"></span>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
			   class="<?php echo esc_attr( 'tsosk-nav-item' . ( $current === $slug ? ' is-active' : '' ) ); ?>"
			   aria-current="<?php echo esc_attr( $current === $slug ? 'page' : 'false' ); ?>">
				<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
				<span class="tsosk-nav-label"><?php echo esc_html( $tab['label'] ); ?></span>
			</a>
			<?php if ( $favorites ) : ?>
			<button type="button" class="tsosk-remove-fav"
			        data-slug="<?php echo esc_attr( $slug ); ?>"
			        title="<?php esc_attr_e( 'Remove from favorites', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">✕</button>
			<?php else : ?>
			<button type="button" class="tsosk-hide-tab"
			        data-slug="<?php echo esc_attr( $slug ); ?>"
			        title="<?php echo $is_hidden ? esc_attr__( 'Show', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_attr__( 'Hide', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
				<?php echo $is_hidden ? '＋' : '✕'; ?>
			</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Global tab search input.
	 */
	private function render_global_search(): void {
		?>
		<div class="tsosk-global-search" id="tsosk-global-search">
			<label class="screen-reader-text" for="tsosk-global-search-input">
				<?php esc_html_e( 'Search tools', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</label>
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<input type="search" id="tsosk-global-search-input" class="tsosk-global-search-input"
			       autocomplete="off" spellcheck="false"
			       title="<?php esc_attr_e( 'Search tools (Ctrl+K)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
			       placeholder="<?php esc_attr_e( 'Search… (Ctrl+K)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
			<ul class="tsosk-global-search-results" id="tsosk-global-search-results" hidden></ul>
		</div>
		<?php
	}

	/**
	 * Active site flags (debug, maintenance, sandbox, etc.).
	 *
	 * @param string $base_url     Plugin admin URL.
	 * @param string $current_tab  Active tab slug.
	 */
	private function render_site_status_bar( string $base_url, string $current_tab = '' ): void {
		$badges = TSOSK_Site_Status::get_active_badges( $base_url );
		?>
		<div class="tsosk-site-status-bar" id="tsosk-site-status-bar">
			<span class="tsosk-site-status-label"><?php esc_html_e( 'Site status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</span>
			<?php if ( empty( $badges ) ) : ?>
				<span class="tsosk-site-status-ok"><?php esc_html_e( 'No special modes active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			<?php else : ?>
				<?php foreach ( $badges as $badge ) : ?>
					<a href="<?php echo esc_url( $badge['tab'] ); ?>"
					   class="tsosk-site-status-badge tsosk-site-status-<?php echo esc_attr( $badge['type'] ); ?>">
						<?php echo esc_html( $badge['label'] ); ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( class_exists( 'TSOSK_Mod_Debug' ) ) : ?>
				<span class="tsosk-site-status-actions">
					<?php if ( TSOSK_Site_Status::is_developer_mode_active() ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', 'debug', $base_url ) ); ?>" class="tsosk-site-status-badge tsosk-site-status-warn">
							<?php esc_html_e( 'Developer mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</a>
					<?php elseif ( 'debug' === $current_tab ) : ?>
						<button type="button" class="button button-small" id="tsosk-header-dev-mode-on"
						        title="<?php esc_attr_e( 'Enable WP_DEBUG, debug.log and SAVEQUERIES', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<?php esc_html_e( 'Enable dev mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Page render ───────────────────────────────────────────────────────────

	/**
	 * Render the full admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$tabs_raw = $this->get_tabs();
		$tabs     = $this->apply_saved_order( $tabs_raw );

		// Determine active tab from query param, default to Hidden Profiles.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'hidden-profiles'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab navigation.
		if ( ! array_key_exists( $current, $tabs ) ) {
			$current = 'hidden-profiles';
		}

		$grouped_tabs     = $this->group_tabs_for_sidebar( $tabs );
		$group_labels     = $this->get_tab_group_labels();
		$favorite_slugs   = $this->get_user_favorites( $tabs );
		$this->current_tab = $current;

		$base_url = admin_url( 'tools.php?page=tso-swiss-knife' );
		?>
		<div class="wrap tsosk-wrap">
			<h1 class="tsosk-page-title">
				<span class="dashicons dashicons-hammer"></span>
				<?php esc_html_e( 'TSO Swiss Knife', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-version">v<?php echo esc_html( TSOSK_VERSION ); ?></span>
				<div class="tsosk-page-title-meta">
					<?php $this->render_global_search(); ?>
					<?php TSOSK_Support::render_donate_button(); ?>
					<?php $this->render_language_switcher( $base_url, $current ); ?>
				</div>
			</h1>

			<?php $this->render_site_status_bar( $base_url, $current ); ?>

			<div class="tsosk-chrome">
			<button type="button" class="button tsosk-mobile-nav-toggle" id="tsosk-mobile-nav-toggle"
			        aria-expanded="false" aria-controls="tsosk-sidebars">
				<span class="dashicons dashicons-menu" aria-hidden="true"></span>
				<?php esc_html_e( 'Tools menu', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>

			<!-- ── Favorites bar ── -->
			<?php $order_nonce = wp_create_nonce( 'tsosk_sidebar_order_nonce' ); ?>
			<nav class="tsosk-sidebar tsosk-sidebar-favorites" aria-label="<?php esc_attr_e( 'Favorite tools', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
			     id="tsosk-sidebar-favorites"
			     data-nonce="<?php echo esc_attr( $order_nonce ); ?>">
				<h2 class="tsosk-favorites-title">
					<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
					<?php esc_html_e( 'Favorites', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</h2>
				<p class="tsosk-favorites-hint" id="tsosk-favorites-hint">
					<?php esc_html_e( 'Use Organise on the tools list, then drag tabs here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
				<div id="tsosk-favorites-list" class="<?php echo empty( $favorite_slugs ) ? 'is-empty' : ''; ?>">
					<?php foreach ( $favorite_slugs as $slug ) : ?>
						<?php $this->render_sidebar_nav_row( $slug, $tabs[ $slug ], $base_url, $current, true ); ?>
					<?php endforeach; ?>
				</div>
			</nav>

			<div class="tsosk-layout">
				<div class="tsosk-sidebars" id="tsosk-sidebars">
				<!-- ── Main tools sidebar (left) ── -->
				<nav class="tsosk-sidebar tsosk-sidebar-tools" aria-label="<?php esc_attr_e( 'Plugin tools', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
				     id="tsosk-sidebar-nav"
				     data-nonce="<?php echo esc_attr( $order_nonce ); ?>">

					<!-- ── Organise button (top of sidebar) ── -->
					<div class="tsosk-sidebar-actions" id="tsosk-sidebar-actions">
						<button type="button" id="tsosk-sidebar-organise"
						        class="tsosk-sidebar-action-btn tsosk-sidebar-organise-btn"
						        title="<?php esc_attr_e( 'Organise sidebar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
							<span class="dashicons dashicons-menu" aria-hidden="true"></span>
							<span class="tsosk-nav-label"><?php esc_html_e( 'Organise', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						</button>
					</div>

					<!-- ── Edit-mode toolbar (hidden until Organise clicked) ── -->
					<div class="tsosk-sidebar-edit-bar" id="tsosk-sidebar-edit-bar" style="display:none;">
						<span class="tsosk-sidebar-edit-hint" id="tsosk-sidebar-edit-hint">
							<?php esc_html_e( 'Drag tabs to reorder and move them between categories, drag into Favorites, and reorder favorites. Click ✕ on a tool to hide it or on a favorite to remove it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
						<div class="tsosk-sidebar-edit-btns">
							<button type="button" id="tsosk-sidebar-save" class="tsosk-sidebar-save-btn">
								<?php esc_html_e( 'Save', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
							<button type="button" id="tsosk-sidebar-reset" class="tsosk-sidebar-reset-btn">
								<?php esc_html_e( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
							<button type="button" id="tsosk-sidebar-cancel" class="tsosk-sidebar-cancel-btn">
								<?php esc_html_e( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</button>
						</div>
					</div>

					<!-- ── Nav items (grouped) ── -->
					<div id="tsosk-nav-list">
					<?php foreach ( $grouped_tabs as $group_id => $group_tabs ) : ?>
						<?php if ( ! empty( $group_labels[ $group_id ] ) ) : ?>
						<div class="tsosk-nav-group-label" aria-hidden="true">
							<?php echo esc_html( $group_labels[ $group_id ] ); ?>
						</div>
						<?php endif; ?>
						<?php foreach ( $group_tabs as $slug => $tab ) : ?>
							<?php
							$this->render_sidebar_nav_row(
								$slug,
								$tab,
								$base_url,
								$current,
								false,
								! empty( $tab['hidden'] )
							);
							?>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</div>

				</nav>
				</div><!-- .tsosk-sidebars -->

				<!-- ── Tab content area ── -->
				<div class="tsosk-content" role="main">
					<div class="tsosk-content-inner">
					<div class="tsosk-tab-header">
						<h2>
							<span class="dashicons <?php echo esc_attr( $tabs[ $current ]['icon'] ); ?>"></span>
							<?php echo esc_html( $tabs[ $current ]['label'] ); ?>
						</h2>
					</div>
					<div class="tsosk-tab-body">
						<?php
						$class = $tabs[ $current ]['class'];
						if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
							call_user_func( array( $class, 'get_instance' ) )->render();
						} else {
							echo '<p>' . esc_html__( 'Module not available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</p>';
						}
						?>
					</div>
					</div>
				</div>

			</div><!-- .tsosk-layout -->
			</div><!-- .tsosk-chrome -->
		</div><!-- .tsosk-wrap -->
		<?php
	}

	/**
	 * Render CAT/ES/ENG language switcher.
	 *
	 * @param string $base_url Admin base URL.
	 * @param string $current  Current tab.
	 */
	private function render_language_switcher( string $base_url, string $current ): void {
		$current_language = TSOSK_I18n::get_admin_language_key();
		$languages        = TSOSK_I18n::get_languages();
		?>
		<span class="tsosk-language-switcher" aria-label="<?php esc_attr_e( 'Plugin language', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
			<?php foreach ( $languages as $key => $language ) : ?>
				<?php
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'tab'        => $current,
							'tsosk_lang' => $key,
						),
						$base_url
					),
					'tsosk_language_switch'
				);
				?>
				<a class="<?php echo esc_attr( $current_language === $key ? 'is-active' : '' ); ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $language['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</span>
		<?php
	}
}
