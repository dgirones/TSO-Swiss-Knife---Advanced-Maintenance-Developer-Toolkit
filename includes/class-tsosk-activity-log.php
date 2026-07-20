<?php
/**
 * TSO Swiss Knife – Central activity log for plugin changes.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Activity_Log
 */
class TSOSK_Activity_Log {

	public const OPTION          = 'tsosk_activity_log';
	public const MIGRATED_OPTION = 'tsosk_activity_log_migrated';
	public const SETTINGS_OPTION = 'tsosk_activity_log_settings';
	public const LIMIT           = 150;

	/** Max characters stored per detail string value. */
	public const DETAIL_TRUNCATE = 200;

	/**
	 * @return array{modules: string[], limit: int}
	 */
	public static function get_settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$defaults = array(
			'modules' => array(),
			'limit'   => self::LIMIT,
		);
		$s = wp_parse_args( $s, $defaults );
		if ( ! is_array( $s['modules'] ) ) {
			$s['modules'] = array();
		}
		$s['limit'] = max( 25, min( 500, absint( $s['limit'] ) ) );
		return $s;
	}

	/**
	 * Whether logging is enabled for a module slug.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function is_module_enabled( string $module ): bool {
		$settings = self::get_settings();
		$enabled  = array_map( 'sanitize_key', $settings['modules'] );
		if ( empty( $enabled ) ) {
			return true;
		}
		return in_array( sanitize_key( $module ), $enabled, true );
	}

	/**
	 * @return string[] Module slugs available for logging preferences.
	 */
	public static function get_configurable_modules(): array {
		return array(
			'options-editor',
			'meta-editor',
			'search-replace',
			'admin-menu',
			'maintenance',
			'hidden-profiles',
			'redirects',
			'custom-404',
			'update-manager',
			'heartbeat',
			'rest-api',
			'security',
			'debug',
			'login-protect',
			'comment-antispam',
			'server-files',
			'slow-queries',
			'sandbox',
			'cron',
			'database',
			'roles',
			'slug-manager',
			'file-integrity',
			'rewrite',
			'transients',
			'users',
			'site-snapshot',
			'action-scheduler',
			'health',
			'content-audit',
			'media-footprint',
			'image-sizes-audit',
		);
	}

	/**
	 * Save activity log preferences.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 */
	public static function save_settings( array $settings ): void {
		$modules = array();
		if ( ! empty( $settings['modules'] ) && is_array( $settings['modules'] ) ) {
			$allowed = self::get_configurable_modules();
			foreach ( $settings['modules'] as $module ) {
				$module = sanitize_key( (string) $module );
				if ( in_array( $module, $allowed, true ) ) {
					$modules[] = $module;
				}
			}
		}
		update_option(
			self::SETTINGS_OPTION,
			array(
				'modules' => array_values( array_unique( $modules ) ),
				'limit'   => max( 25, min( 500, absint( $settings['limit'] ?? self::LIMIT ) ) ),
			),
			false
		);
	}

	/**
	 * Current retention limit for stored entries.
	 */
	public static function get_limit(): int {
		return (int) self::get_settings()['limit'];
	}

	/**
	 * Record a plugin activity entry.
	 *
	 * @param string               $module  Module slug (e.g. options-editor).
	 * @param string               $action  Action key (update, delete, save…).
	 * @param string               $summary Short human-readable description.
	 * @param array<string, mixed> $details Optional structured details.
	 */
	public static function log( string $module, string $action, string $summary, array $details = array() ): void {
		if ( ! self::is_module_enabled( $module ) ) {
			return;
		}

		$user = wp_get_current_user();

		$entry = array(
			'module'  => sanitize_key( $module ),
			'action'  => sanitize_key( $action ),
			'summary' => sanitize_text_field( $summary ),
			'details' => self::sanitize_details( $details ),
			'time'    => time(),
			'user'    => $user->exists() ? sanitize_user( $user->user_login, true ) : 'system',
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $entry;
		$limit = self::get_limit();
		if ( count( $log ) > $limit ) {
			$log = array_slice( $log, - $limit );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries(): array {
		self::maybe_migrate_legacy();

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		usort(
			$log,
			static function ( $a, $b ) {
				return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
			}
		);

		return $log;
	}

	/**
	 * Clear the central activity log.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Human-readable module label.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	public static function module_label( string $module ): string {
		$labels = array(
			'options-editor'    => __( 'Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'meta-editor'       => __( 'Meta Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'search-replace'    => __( 'Search & Replace', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'admin-menu'        => __( 'Reorder & Hide Sidebar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'maintenance'       => __( 'Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hidden-profiles'   => __( 'Hidden WordPress Profiles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'redirects'         => __( 'Redirects', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'custom-404'        => __( 'Custom 404 Page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update-manager'    => __( 'Update Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'heartbeat'         => __( 'Heartbeat', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'rest-api'          => __( 'REST API', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'security'          => __( 'Security Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'debug'             => __( 'Debug Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'login-protect'     => __( 'Login Protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'comment-antispam'  => __( 'Comment Anti-Spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'server-files'      => __( 'Server Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'slow-queries'      => __( 'Slow Queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'sandbox'           => __( 'Plugin Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'cron'              => __( 'Cron Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'database'          => __( 'TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'roles'             => __( 'Roles & Capabilities', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'slug-manager'      => __( 'Slug Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'file-integrity'    => __( 'File Integrity', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'rewrite'           => __( 'Rewrite Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'object-cache'      => __( 'Object Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'transients'        => __( 'Transients', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'users'             => __( 'Users & Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'site-snapshot'     => __( 'Export/Import TSO Configuration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'action-scheduler'  => __( 'Action Scheduler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'health'            => __( 'Health & Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'content-audit'     => __( 'Content Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'media-footprint'   => __( 'Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'image-sizes-audit' => __( 'Image Sizes Audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$module = sanitize_key( $module );

		return $labels[ $module ] ?? ucwords( str_replace( '-', ' ', $module ) );
	}

	/**
	 * Human-readable action label.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	public static function action_label( string $action ): string {
		$labels = array(
			'add'     => __( 'Add', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update'  => __( 'Update', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete'  => __( 'Delete', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'save'    => __( 'Save', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'reset'   => __( 'Reset', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'enable'  => __( 'Enable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disable' => __( 'Disable', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'execute' => __( 'Execute', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'toggle'  => __( 'Toggle', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'run'     => __( 'Run', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'flush'   => __( 'Flush', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'import'  => __( 'Import', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'export'  => __( 'Export', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'scan'    => __( 'Scan', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'purge'   => __( 'Purge', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'cancel'  => __( 'Cancel', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'clear'   => __( 'Clear', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'bulk'    => __( 'Bulk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'clone'   => __( 'Clone', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$action = sanitize_key( $action );

		return $labels[ $action ] ?? ucfirst( $action );
	}

	/**
	 * Human-readable Update Manager preset label (includes retired slugs).
	 *
	 * @param string $preset Stored preset slug.
	 * @return string
	 */
	public static function update_manager_preset_label( string $preset ): string {
		$preset = sanitize_key( $preset );
		$labels = array(
			'default'     => __( 'WordPress default', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disable_all' => __( 'Disable all updates', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'custom'      => __( 'Custom', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'auto_all'    => __( 'WordPress default', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		return $labels[ $preset ] ?? $preset;
	}

	/**
	 * Translate a stored log summary (often saved in English) for display.
	 *
	 * @param string               $module  Module slug.
	 * @param string               $action  Action key.
	 * @param string               $summary Stored summary text.
	 * @param array<string, mixed> $details Structured details.
	 * @return string
	 */
	public static function translate_summary( string $module, string $action, string $summary, array $details = array() ): string {
		$summary = trim( $summary );
		if ( '' === $summary ) {
			return '';
		}

		$patterns = array(
			'/^Removed broken shortcode \[(.+?)\] from post #(\d+)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: shortcode tag, 2: post ID */
					__( 'Removed broken shortcode [%1$s] from post #%2$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1],
					(int) $m[2]
				);
			},
			'/^Bulk slug fix: (\d+) updated, (\d+) skipped\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: fixed count, 2: skipped count */
					__( 'Bulk slug fix: %1$d updated, %2$d skipped.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $m[1],
					(int) $m[2]
				);
			},
			'/^Slug renamed: (.+) → (.+)\.$/u' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: old slug, 2: new slug */
					__( 'Slug renamed: %1$s → %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1],
					$m[2]
				);
			},
			'/^Update Manager settings saved \(preset: (.+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: preset label */
					__( 'Update Manager settings saved (preset: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					self::update_manager_preset_label( $m[1] )
				);
			},
			'/^Site Health suppression settings saved\.$/' => static function (): string {
				return __( 'Site Health suppression settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			},
			'/^Debug log shrunk to last 500 lines \(archive: (.+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: archive filename */
					__( 'Debug log shrunk to last 500 lines (archive: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1]
				);
			},
			'/^Expired transients purged: (\d+)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %d: number of transients */
					__( 'Expired transients purged: %d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $m[1]
				);
			},
			'/^File added to ignore list\.$/' => static function (): string {
				return __( 'File added to ignore list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			},
			'/^File removed from ignore list\.$/' => static function (): string {
				return __( 'File removed from ignore list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			},
			'/^File integrity scan completed \((\d+) issue\(s\)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %d: number of issues */
					__( 'File integrity scan completed (%d issue(s)).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $m[1]
				);
			},
			'/^Manual update check finished \(plugins pending: (\d+) → (\d+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: plugins before, 2: plugins after */
					__( 'Manual update check finished (plugins pending: %1$d → %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $m[1],
					(int) $m[2]
				);
			},
			'/^Manual update run finished \(plugins pending: (\d+) → (\d+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: plugins before, 2: plugins after */
					__( 'Manual update check finished (plugins pending: %1$d → %2$d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $m[1],
					(int) $m[2]
				);
			},
			'/^Option (.+): (.+)$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: action, 2: option name */
					__( 'Option %1$s: %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1],
					$m[2]
				);
			},
			'/^Database replace: "(.+)" → "(.+)"$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: search string, 2: replace string */
					__( 'Database replace: "%1$s" → "%2$s"', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1],
					$m[2]
				);
			},
			'/^Cron event executed: (.+)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: cron hook name */
					__( 'Cron event executed: %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$m[1]
				);
			},
			'/^Activity history cleared\.$/' => static function (): string {
				return __( 'Activity history cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			},
		);

		foreach ( $patterns as $pattern => $callback ) {
			if ( preg_match( $pattern, $summary, $matches ) ) {
				return $callback( $matches );
			}
		}

		return $summary;
	}

	/**
	 * Import legacy per-module history options once.
	 */
	public static function maybe_migrate_legacy(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$oe = get_option( 'tsosk_oe_history', array() );
		if ( is_array( $oe ) ) {
			foreach ( $oe as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$entries[] = self::entry_from_options_editor( $row );
			}
		}

		$sr = get_option( 'tsosk_sr_history', array() );
		if ( is_array( $sr ) ) {
			foreach ( $sr as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$entries[] = self::entry_from_search_replace( $row );
			}
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return (int) ( $a['time'] ?? 0 ) <=> (int) ( $b['time'] ?? 0 );
			}
		);
		if ( count( $entries ) > self::get_limit() ) {
			$entries = array_slice( $entries, - self::get_limit() );
		}

		update_option( self::OPTION, $entries, false );
		update_option( self::MIGRATED_OPTION, 1, false );

		// Legacy per-module history options are no longer written; remove after import.
		delete_option( 'tsosk_oe_history' );
		delete_option( 'tsosk_sr_history' );
	}

	/**
	 * Sanitize structured detail values before storage.
	 *
	 * @param array<string, mixed> $details Raw details.
	 * @return array<string, mixed>
	 */
	private static function sanitize_details( array $details ): array {
		$sanitized = array();

		foreach ( $details as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_int( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			if ( is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$sanitized[ $key ] = self::truncate_text( sanitize_text_field( $value ) );
			}
		}

		return $sanitized;
	}

	/**
	 * Truncate long text for log storage.
	 *
	 * @param string $text  Input text.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	public static function truncate_text( string $text, int $limit = self::DETAIL_TRUNCATE ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		return mb_substr( $text, 0, $limit ) . '…';
	}

	/**
	 * @param array<string, mixed> $row Legacy OE row.
	 * @return array<string, mixed>
	 */
	private static function entry_from_options_editor( array $row ): array {
		$name   = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		$action = sanitize_key( (string) ( $row['action'] ?? 'update' ) );

		return array(
			'module'  => 'options-editor',
			'action'  => $action,
			'summary' => sanitize_text_field(
				sprintf(
					/* translators: 1: action, 2: option name */
					__( 'Option %1$s: %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					self::action_label( $action ),
					$name
				)
			),
			'details' => self::sanitize_details(
				array(
					'name' => $name,
					'old'  => (string) ( $row['old'] ?? '' ),
					'new'  => (string) ( $row['new'] ?? '' ),
				)
			),
			'time'    => (int) ( $row['time'] ?? 0 ),
			'user'    => sanitize_user( (string) ( $row['user'] ?? '' ), true ),
		);
	}

	/**
	 * @param array<string, mixed> $row Legacy SR row.
	 * @return array<string, mixed>
	 */
	private static function entry_from_search_replace( array $row ): array {
		$search  = self::truncate_text( sanitize_text_field( (string) ( $row['search'] ?? '' ) ) );
		$replace = self::truncate_text( sanitize_text_field( (string) ( $row['replace'] ?? '' ) ) );

		return array(
			'module'  => 'search-replace',
			'action'  => 'execute',
			'summary' => sanitize_text_field(
				sprintf(
					/* translators: 1: search string, 2: replace string */
					__( 'Database replace: "%1$s" → "%2$s"', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$search,
					$replace
				)
			),
			'details' => self::sanitize_details(
				array(
					'search'  => $search,
					'replace' => $replace,
					'tables'  => (int) ( $row['tables'] ?? 0 ),
					'rows'    => (int) ( $row['rows'] ?? 0 ),
					'cells'   => (int) ( $row['cells'] ?? 0 ),
				)
			),
			'time'    => (int) ( $row['ts'] ?? 0 ),
			'user'    => sanitize_user( (string) ( $row['user'] ?? '' ), true ),
		);
	}
}
