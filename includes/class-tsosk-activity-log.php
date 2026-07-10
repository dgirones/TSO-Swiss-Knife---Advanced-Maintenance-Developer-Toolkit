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
			'options-editor'    => __( 'Options Editor', 'tso-swiss-knife' ),
			'meta-editor'       => __( 'Meta Editor', 'tso-swiss-knife' ),
			'search-replace'    => __( 'Search & Replace', 'tso-swiss-knife' ),
			'admin-menu'        => __( 'Reorder & Hide Sidebar', 'tso-swiss-knife' ),
			'maintenance'       => __( 'Maintenance Mode', 'tso-swiss-knife' ),
			'hidden-profiles'   => __( 'Hidden WordPress Profiles', 'tso-swiss-knife' ),
			'redirects'         => __( 'Redirects', 'tso-swiss-knife' ),
			'custom-404'        => __( 'Custom 404 Page', 'tso-swiss-knife' ),
			'update-manager'    => __( 'Update Manager', 'tso-swiss-knife' ),
			'heartbeat'         => __( 'Heartbeat', 'tso-swiss-knife' ),
			'rest-api'          => __( 'REST API', 'tso-swiss-knife' ),
			'security'          => __( 'Security Review', 'tso-swiss-knife' ),
			'debug'             => __( 'Debug Mode', 'tso-swiss-knife' ),
			'login-protect'     => __( 'Login Protection', 'tso-swiss-knife' ),
			'comment-antispam'  => __( 'Comment Anti-Spam', 'tso-swiss-knife' ),
			'server-files'      => __( 'Server Files', 'tso-swiss-knife' ),
			'slow-queries'      => __( 'Slow Queries', 'tso-swiss-knife' ),
			'sandbox'           => __( 'Plugin Sandbox', 'tso-swiss-knife' ),
			'cron'              => __( 'Cron Manager', 'tso-swiss-knife' ),
			'database'          => __( 'TSO Options & Tables Cleaner', 'tso-swiss-knife' ),
			'roles'             => __( 'Roles & Capabilities', 'tso-swiss-knife' ),
			'slug-manager'      => __( 'Slug Manager', 'tso-swiss-knife' ),
			'file-integrity'    => __( 'File Integrity', 'tso-swiss-knife' ),
			'rewrite'           => __( 'Rewrite Rules', 'tso-swiss-knife' ),
			'object-cache'      => __( 'Object Cache', 'tso-swiss-knife' ),
			'transients'        => __( 'Transients', 'tso-swiss-knife' ),
			'users'             => __( 'Users & Sessions', 'tso-swiss-knife' ),
			'site-snapshot'     => __( 'Export/Import TSO Configuration', 'tso-swiss-knife' ),
			'action-scheduler'  => __( 'Action Scheduler', 'tso-swiss-knife' ),
			'health'            => __( 'Health & Alerts', 'tso-swiss-knife' ),
			'content-audit'     => __( 'Content Audit', 'tso-swiss-knife' ),
			'media-footprint'   => __( 'Uploads Disk Footprint', 'tso-swiss-knife' ),
			'image-sizes-audit' => __( 'Image Sizes Audit', 'tso-swiss-knife' ),
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
			'add'     => __( 'Add', 'tso-swiss-knife' ),
			'update'  => __( 'Update', 'tso-swiss-knife' ),
			'delete'  => __( 'Delete', 'tso-swiss-knife' ),
			'save'    => __( 'Save', 'tso-swiss-knife' ),
			'reset'   => __( 'Reset', 'tso-swiss-knife' ),
			'enable'  => __( 'Enable', 'tso-swiss-knife' ),
			'disable' => __( 'Disable', 'tso-swiss-knife' ),
			'execute' => __( 'Execute', 'tso-swiss-knife' ),
			'toggle'  => __( 'Toggle', 'tso-swiss-knife' ),
			'run'     => __( 'Run', 'tso-swiss-knife' ),
			'flush'   => __( 'Flush', 'tso-swiss-knife' ),
			'import'  => __( 'Import', 'tso-swiss-knife' ),
			'export'  => __( 'Export', 'tso-swiss-knife' ),
			'scan'    => __( 'Scan', 'tso-swiss-knife' ),
			'purge'   => __( 'Purge', 'tso-swiss-knife' ),
			'cancel'  => __( 'Cancel', 'tso-swiss-knife' ),
		);

		$action = sanitize_key( $action );

		return $labels[ $action ] ?? ucfirst( $action );
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
					__( 'Removed broken shortcode [%1$s] from post #%2$d.', 'tso-swiss-knife' ),
					$m[1],
					(int) $m[2]
				);
			},
			'/^Bulk slug fix: (\d+) updated, (\d+) skipped\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: fixed count, 2: skipped count */
					__( 'Bulk slug fix: %1$d updated, %2$d skipped.', 'tso-swiss-knife' ),
					(int) $m[1],
					(int) $m[2]
				);
			},
			'/^Slug renamed: (.+) → (.+)\.$/u' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: old slug, 2: new slug */
					__( 'Slug renamed: %1$s → %2$s.', 'tso-swiss-knife' ),
					$m[1],
					$m[2]
				);
			},
			'/^Update Manager settings saved \(preset: (.+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: preset slug */
					__( 'Update Manager settings saved (preset: %s).', 'tso-swiss-knife' ),
					$m[1]
				);
			},
			'/^Site Health suppression settings saved\.$/' => static function (): string {
				return __( 'Site Health suppression settings saved.', 'tso-swiss-knife' );
			},
			'/^Debug log shrunk to last 500 lines \(archive: (.+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: archive filename */
					__( 'Debug log shrunk to last 500 lines (archive: %s).', 'tso-swiss-knife' ),
					$m[1]
				);
			},
			'/^Expired transients purged: (\d+)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %d: number of transients */
					__( 'Expired transients purged: %d.', 'tso-swiss-knife' ),
					(int) $m[1]
				);
			},
			'/^File added to ignore list\.$/' => static function (): string {
				return __( 'File added to ignore list.', 'tso-swiss-knife' );
			},
			'/^File removed from ignore list\.$/' => static function (): string {
				return __( 'File removed from ignore list.', 'tso-swiss-knife' );
			},
			'/^File integrity scan completed \((\d+) issue\(s\)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %d: number of issues */
					__( 'File integrity scan completed (%d issue(s)).', 'tso-swiss-knife' ),
					(int) $m[1]
				);
			},
			'/^Manual update run finished \(plugins pending: (\d+) → (\d+)\)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: plugins before, 2: plugins after */
					__( 'Manual update run finished (plugins pending: %1$d → %2$d).', 'tso-swiss-knife' ),
					(int) $m[1],
					(int) $m[2]
				);
			},
			'/^Option (.+): (.+)$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: action, 2: option name */
					__( 'Option %1$s: %2$s', 'tso-swiss-knife' ),
					$m[1],
					$m[2]
				);
			},
			'/^Database replace: "(.+)" → "(.+)"$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: 1: search string, 2: replace string */
					__( 'Database replace: "%1$s" → "%2$s"', 'tso-swiss-knife' ),
					$m[1],
					$m[2]
				);
			},
			'/^Cron event executed: (.+)\.$/' => static function ( array $m ): string {
				return sprintf(
					/* translators: %s: cron hook name */
					__( 'Cron event executed: %s.', 'tso-swiss-knife' ),
					$m[1]
				);
			},
			'/^Activity history cleared\.$/' => static function (): string {
				return __( 'Activity history cleared.', 'tso-swiss-knife' );
			},
		);

		foreach ( $patterns as $pattern => $callback ) {
			if ( preg_match( $pattern, $summary, $matches ) ) {
				return $callback( $matches );
			}
		}

		// Module-specific fallbacks using details when available.
		if ( 'update-manager' === $module && 'save' === $action && isset( $details['preset'] ) ) {
			return sprintf(
				/* translators: %s: preset slug */
				__( 'Update Manager settings saved (preset: %s).', 'tso-swiss-knife' ),
				(string) $details['preset']
			);
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
					__( 'Option %1$s: %2$s', 'tso-swiss-knife' ),
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
					__( 'Database replace: "%1$s" → "%2$s"', 'tso-swiss-knife' ),
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
