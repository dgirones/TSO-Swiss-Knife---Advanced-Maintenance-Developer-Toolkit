<?php
/**
 * TSO Swiss Knife – Module: Plugin & Theme Leftovers.
 *
 * Finds rows in the database that point to plugin or theme folders (wp-content/plugins/NAME/, wp-content/themes/NAME/)
 * that are no longer installed, and lets the administrator decide, group by group, what to delete.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Leftovers
 */
class TSOSK_Mod_Leftovers {

	/** Rows read per request while scanning. */
	private const BATCH = 2000;

	/** Values longer than this are cut when scanned; such rows are never offered for deletion. */
	private const VALUE_CAP = 100000;

	/** Rows with a value larger than this probably hold much more than the stale path (widgets, big settings): never offered for deletion. */
	private const ROW_CAP = 20000;

	/** Most rows one delete request will remove. */
	private const DELETE_CAP = 5000;

	/** Non-autoloaded option that held the single backup of earlier versions (moved into the list on first use). */
	private const BACKUP_OPTION = 'tsosk_lo_last_backup';

	/** Non-autoloaded option holding the backups of the latest deletions, newest first. */
	private const BACKUPS_OPTION = 'tsosk_lo_backups';

	/** Most backups kept. */
	private const MAX_BACKUPS = 10;

	/** Largest size (bytes) of all backups together; the oldest are dropped first. */
	private const BACKUPS_CAP_BYTES = 6000000;

	/** Most rows listed per backup in the panel. */
	private const SHOWN_ROWS = 300;

	/** Largest backup (bytes of values) the module will keep. */
	private const BACKUP_CAP_BYTES = 2000000;

	/** Most PHP files read by the "is this key used?" check. */
	private const USAGE_FILE_CAP = 40000;

	/** Seconds the "is this key used?" check may run. */
	private const USAGE_TIME_CAP = 40;

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Leftovers|null
	 */
	private static $instance = null;

	/**
	 * Host names of this site, filled on first use.
	 *
	 * @var string[]|null
	 */
	private $hosts = null;

	/**
	 * Installed slugs, filled on first use.
	 *
	 * @var array<string,string[]>|null
	 */
	private $installed = null;

	/**
	 * Get instance.
	 *
	 * @return TSOSK_Mod_Leftovers
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
		add_action( 'wp_ajax_tsosk_lo_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_tsosk_lo_peek', array( $this, 'ajax_peek' ) );
		add_action( 'wp_ajax_tsosk_lo_usage', array( $this, 'ajax_usage' ) );
		add_action( 'wp_ajax_tsosk_lo_force_delete', array( $this, 'ajax_force_delete' ) );
		add_action( 'wp_ajax_tsosk_lo_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_tsosk_lo_restore', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_tsosk_lo_backup_delete', array( $this, 'ajax_backup_delete' ) );
		add_action( 'admin_post_tsosk_lo_download_backup', array( $this, 'download_backup' ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Table, id column, key column and value column of a source that can hold leftovers.
	 *
	 * @param string $source Source slug.
	 * @return array{0:string,1:string,2:string,3:string}|null
	 */
	private function source_def( string $source ): ?array {
		global $wpdb;
		$map = array(
			'options'     => array( $wpdb->options, 'option_id', 'option_name', 'option_value' ),
			'postmeta'    => array( $wpdb->postmeta, 'meta_id', 'meta_key', 'meta_value' ),
			'usermeta'    => array( $wpdb->usermeta, 'umeta_id', 'meta_key', 'meta_value' ),
			'termmeta'    => array( $wpdb->termmeta, 'meta_id', 'meta_key', 'meta_value' ),
			'commentmeta' => array( $wpdb->commentmeta, 'meta_id', 'meta_key', 'meta_value' ),
		);
		return $map[ $source ] ?? null;
	}

	/**
	 * Folder names that hold plugins and themes ("wp-content" unless the site moved it).
	 *
	 * @return string[]
	 */
	private function content_dirs(): array {
		$dirs = array( 'wp-content' );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$name = basename( (string) WP_CONTENT_DIR );
			if ( '' !== $name && ! in_array( $name, $dirs, true ) ) {
				$dirs[] = $name;
			}
		}
		return $dirs;
	}

	/**
	 * Regular expression that finds ".../wp-content/plugins/NAME" and ".../wp-content/themes/NAME".
	 *
	 * @return string
	 */
	private function path_regex(): string {
		$dirs = array_map(
			static function ( string $d ): string {
				return preg_quote( $d, '#' );
			},
			$this->content_dirs()
		);
		// Group 1: host of an absolute URL in front of the path (empty for relative or server paths). 2: plugins|themes. 3: folder name.
		return '#(?:(?:https?:)?//([A-Za-z0-9.-]+)(?::\d+)?/(?:[A-Za-z0-9._~%-]+/){0,3})?(?:' . implode( '|', $dirs ) . ')/(plugins|themes)/([A-Za-z0-9._-]+)#i';
	}

	/**
	 * Plugin and theme folders that exist on this site.
	 *
	 * @param string $type plugin|theme.
	 * @param string $slug Folder name in lowercase.
	 * @return bool
	 */
	private function is_installed( string $type, string $slug ): bool {
		if ( null === $this->installed ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
			$plugins = array();
			$files   = array_merge( array_keys( get_plugins() ), array_keys( get_mu_plugins() ) );
			foreach ( $files as $file ) {
				$dir       = dirname( (string) $file );
				$plugins[] = strtolower( '.' === $dir ? (string) preg_replace( '~\.php$~', '', (string) $file ) : $dir );
			}
			$themes          = array_map( 'strtolower', array_keys( wp_get_themes() ) );
			$this->installed = array(
				'plugin' => array_values( array_unique( $plugins ) ),
				'theme'  => array_values( array_unique( $themes ) ),
			);
		}
		if ( in_array( $slug, $this->installed[ $type ] ?? array(), true ) ) {
			return true;
		}
		// A folder that is on disk counts as installed even when WordPress cannot read its header.
		$base = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();
		return '' !== $slug && is_dir( $base . '/' . $slug );
	}

	/**
	 * Whether a "type:slug" key points to something installed.
	 *
	 * @param string $key Key such as "plugin:akismet".
	 * @return bool
	 */
	private function is_installed_key( string $key ): bool {
		$parts = explode( ':', $key, 2 );
		return 2 === count( $parts ) && $this->is_installed( $parts[0], $parts[1] );
	}

	/**
	 * Host names that belong to this site (with and without "www.").
	 *
	 * @return string[]
	 */
	private function own_hosts(): array {
		if ( null === $this->hosts ) {
			$hosts = array();
			foreach ( array( home_url(), site_url(), network_home_url() ) as $url ) {
				$host = wp_parse_url( (string) $url, PHP_URL_HOST );
				if ( is_string( $host ) && '' !== $host ) {
					$host    = strtolower( $host );
					$hosts[] = $host;
					$hosts[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
				}
			}
			$this->hosts = array_values( array_unique( $hosts ) );
		}
		return $this->hosts;
	}

	/**
	 * Plugin/theme folders named inside a value.
	 *
	 * @param string $value Raw database value (may be serialized or JSON with escaped slashes).
	 * @return array<string,string> "type:slug" => example path.
	 */
	private function find_refs( string $value ): array {
		$norm = str_replace( array( '\\\\/', '\\/' ), '/', $value );
		if ( ! preg_match_all( $this->path_regex(), $norm, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $hits as $hit ) {
			// A path on somebody else's site (a link to another domain) says nothing about this site's plugins.
			if ( '' !== (string) $hit[1][0] && ! in_array( strtolower( (string) $hit[1][0] ), $this->own_hosts(), true ) ) {
				continue;
			}
			$type = 'plugins' === strtolower( $hit[2][0] ) ? 'plugin' : 'theme';
			$slug = trim( (string) preg_replace( '~\.php$~', '', strtolower( $hit[3][0] ) ), '.' );
			if ( '' === $slug ) {
				continue;
			}
			$key = $type . ':' . $slug;
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$chunk       = substr( $norm, (int) $hit[0][1], 140 );
			$parts       = preg_split( '~[\s"\'<>\\\\;,)(]~', $chunk );
			$out[ $key ] = is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : (string) $hit[0][0];
		}
		return $out;
	}

	/**
	 * Temporary caches (transients): WordPress and plugins rebuild them when they are missing, so a cache that lists
	 * a removed plugin next to installed ones can be deleted without losing anything.
	 *
	 * @param string $source Source slug.
	 * @param string $key    Option name or meta key.
	 * @return bool
	 */
	private function is_cache_key( string $source, string $key ): bool {
		if ( 'options' !== $source ) {
			return false;
		}
		$low = strtolower( $key );
		return 0 === strpos( $low, '_transient_' ) || 0 === strpos( $low, '_site_transient_' );
	}

	/**
	 * Keys that are never offered for deletion.
	 *
	 * @param string $source Source slug.
	 * @param string $key    Option name or meta key.
	 * @return bool
	 */
	private function is_protected_key( string $source, string $key ): bool {
		$low = strtolower( $key );
		if ( 'options' === $source ) {
			$fixed = array( 'siteurl', 'home', 'blogname', 'template', 'stylesheet', 'current_theme', 'active_plugins', 'active_sitewide_plugins', 'uninstall_plugins', 'sidebars_widgets', 'wp_user_roles', 'cron', 'upload_path', 'upload_url_path', 'recently_activated', 'stylesheet_root', 'template_root', 'fresh_site', 'permalink_structure', 'rewrite_rules' );
			if ( in_array( $low, $fixed, true ) ) {
				return true;
			}
			// Widgets, menus and core settings hold a lot more than one stale path: they are never deleted from here.
			foreach ( array( 'tsosk_', 'widget_', 'nav_menu', 'sidebars_', 'wp_', 'user_roles' ) as $prefix ) {
				if ( 0 === strpos( $low, $prefix ) ) {
					return true;
				}
			}
			return $this->has_secret_fragment( $low );
		}
		if ( 0 === strpos( $low, '_wp_' ) || in_array( $low, array( 'session_tokens', 'capabilities', 'user_level', 'wp_capabilities', 'wp_user_level' ), true ) ) {
			return true;
		}
		return $this->has_secret_fragment( $low );
	}

	/**
	 * Plain-language reason why a protected key is kept.
	 *
	 * @param string $source Source slug.
	 * @param string $key    Option name or meta key.
	 * @return string
	 */
	private function protected_why( string $source, string $key ): string {
		$low = strtolower( $key );
		if ( 'options' === $source ) {
			if ( 'uninstall_plugins' === $low ) {
				return __( 'WordPress core setting that holds the uninstall routines of every plugin that registered one, not only this one. Deleting the whole row would lose the others, and this tool cannot remove just this plugin\'s entry, so it is kept. It is small and harmless to leave; the Options editor protects it too.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( 0 === strpos( $low, 'tsosk_' ) ) {
				return __( 'Setting of this tool itself, so it is never deleted from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			foreach ( array( 'widget_', 'nav_menu', 'sidebars_' ) as $prefix ) {
				if ( 0 === strpos( $low, $prefix ) ) {
					return __( 'Widgets and menus keep the settings of many items in a single row, so deleting it would remove all of them, not only the one that mentions this plugin or theme.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				}
			}
		}
		if ( $this->has_secret_fragment( $low ) ) {
			return __( 'The name suggests a licence, key, token, password or session. It is kept so that credentials are not lost by accident.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'WordPress core data that affects the whole site (plugins, theme, addresses, cron, roles or permalinks), not only this plugin or theme. It is never deleted from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Whether a key name suggests credentials, tokens or sessions.
	 *
	 * @param string $low Key in lowercase.
	 * @return bool
	 */
	private function has_secret_fragment( string $low ): bool {
		foreach ( array( 'license', 'api_key', 'api_secret', 'secret', 'token', 'password', 'two_factor', 'session' ) as $fragment ) {
			if ( false !== strpos( $low, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * LIKE pattern that selects rows mentioning a content folder.
	 *
	 * @return string[] Patterns (one per folder name).
	 */
	private function like_patterns(): array {
		global $wpdb;
		return array_map(
			static function ( string $d ) use ( $wpdb ): string {
				return '%' . $wpdb->esc_like( $d ) . '%';
			},
			$this->content_dirs()
		);
	}

	/**
	 * Common permission and nonce check for the AJAX handlers.
	 */
	private function ensure_allowed(): void {
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		// A broken pattern would make every scan look clean: check it before trusting any "nothing found".
		$content = $this->content_dirs()[0];
		if ( 1 !== preg_match( $this->path_regex(), 'https://' . ( $this->own_hosts()[0] ?? 'example.com' ) . '/' . $content . '/plugins/self-test/file.php' ) || 1 !== preg_match( $this->path_regex(), '/' . $content . '/themes/self-test/' ) ) {
			wp_send_json_error( __( 'The search pattern could not be compiled on this server, so the scan was stopped.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
	}

	/**
	 * Raise the PHP time limit for one request, when the host allows it.
	 */
	private function more_time(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scanning a large table may need more than the default limit.
		}
	}

	// ── Scan ──────────────────────────────────────────────────────────────────

	/**
	 * AJAX: scan one batch of one source.
	 *
	 * Request: source (options|postmeta|usermeta|termmeta|commentmeta|posts), after (last id already read).
	 * Answer: groups found in this batch, the id to continue from (0 when the source is finished).
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();
		$this->more_time();

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$after  = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;

		if ( 'posts' === $source ) {
			wp_send_json_success( $this->scan_posts( $after ) );
		}
		$def = $this->source_def( $source );
		if ( null === $def ) {
			wp_send_json_error( __( 'Unknown source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		wp_send_json_success( $this->scan_source( $source, $def, $after ) );
	}

	/**
	 * Scan a batch of an options or meta table.
	 *
	 * @param string $source Source slug.
	 * @param array  $def    Table definition.
	 * @param int    $after  Last id already read.
	 * @return array{groups:array,next:int,scanned:int}
	 */
	private function scan_source( string $source, array $def, int $after ): array {
		global $wpdb;
		list( $table, $id_col, $key_col, $val_col ) = $def;
		$patterns                                   = $this->like_patterns();
		$like_sql                                   = implode( ' OR ', array_fill( 0, count( $patterns ), $val_col . ' LIKE %s' ) );
		$cap                                        = self::VALUE_CAP;
		$args                                       = array_merge( array( $after ), $patterns, array( self::BATCH ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from source_def(); every value uses a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$id_col} AS id, {$key_col} AS k, IF( LENGTH( {$val_col} ) > {$cap}, LEFT( {$val_col}, {$cap} ), {$val_col} ) AS v, LENGTH( {$val_col} ) AS len FROM {$table} WHERE {$id_col} > %d AND ( {$like_sql} ) ORDER BY {$id_col} ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$groups = array();
		$last   = 0;
		foreach ( (array) $rows as $row ) {
			$last = (int) $row['id'];
			$key  = (string) $row['k'];
			// This plugin's own settings (redirect rules, backups, caches) were created on purpose, so they are never listed.
			if ( 'options' === $source && 0 === strpos( $key, 'tsosk_' ) ) {
				continue;
			}
			// WordPress core's own list of the last files opened in the plugin and theme editors: it mixes paths of several
			// plugins and themes, WordPress trims it by itself, and deleting it would only hide files from that editor.
			if ( 'options' === $source && 'recently_edited' === $key ) {
				continue;
			}
			$refs = $this->find_refs( (string) $row['v'] );
			if ( empty( $refs ) ) {
				continue;
			}
			$missing   = array();
			$has_known = false;
			foreach ( $refs as $slug_key => $sample ) {
				if ( $this->is_installed_key( $slug_key ) ) {
					$has_known = true;
				} else {
					$missing[ $slug_key ] = $sample;
				}
			}
			if ( empty( $missing ) ) {
				continue;
			}
			$mixed     = (bool) $has_known && ! $this->is_cache_key( $source, $key );
			$large     = (int) $row['len'] > self::ROW_CAP && ! $this->is_cache_key( $source, $key );
			$protected = $this->is_protected_key( $source, $key );
			foreach ( $missing as $slug_key => $sample ) {
				$gk = $source . "\x01" . $key . "\x01" . $slug_key;
				if ( ! isset( $groups[ $gk ] ) ) {
					$groups[ $gk ] = array(
						'source'    => $source,
						'key'       => $key,
						'slug_key'  => $slug_key,
						'rows'      => 0,
						'deletable' => 0,
						'mixed'     => 0,
						'large'     => 0,
						'protected' => $protected,
						'sample'    => $sample,
					);
				}
				++$groups[ $gk ]['rows'];
				if ( $protected ) {
					++$groups[ $gk ]['mixed'];
				} elseif ( $mixed ) {
					++$groups[ $gk ]['mixed'];
				} elseif ( $large ) {
					++$groups[ $gk ]['large'];
				} else {
					++$groups[ $gk ]['deletable'];
				}
			}
		}

		if ( 'options' === $source && 0 === $after ) {
			foreach ( $this->orphan_theme_mods() as $gk => $group ) {
				$groups[ $gk ] = $group;
			}
		}

		return array(
			'groups'  => array_values( $groups ),
			'next'    => is_array( $rows ) && count( $rows ) >= self::BATCH ? $last : 0,
			'scanned' => is_array( $rows ) ? count( $rows ) : 0,
		);
	}

	/**
	 * Options "theme_mods_NAME" of themes that are not installed any more (they hold no path, only the theme name).
	 *
	 * @return array<string,array>
	 */
	private function orphan_theme_mods(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Names only; nothing user supplied.
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'theme\\_mods\\_%'" );
		$out   = array();
		foreach ( (array) $names as $name ) {
			$slug = strtolower( substr( (string) $name, strlen( 'theme_mods_' ) ) );
			if ( '' === $slug || ! preg_match( '~^[a-z0-9._-]+$~', $slug ) || $this->is_installed( 'theme', $slug ) ) {
				continue;
			}
			$slug_key   = 'theme:' . $slug;
			$gk         = 'options' . "\x01" . $name . "\x01" . $slug_key;
			$out[ $gk ] = array(
				'source'    => 'options',
				'key'       => (string) $name,
				'slug_key'  => $slug_key,
				'rows'      => 1,
				'deletable' => 1,
				'mixed'     => 0,
				'large'     => 0,
				'protected' => false,
				'sample'    => '',
			);
		}
		return $out;
	}

	/**
	 * Scan a batch of post content (report only: posts are never deleted from here).
	 *
	 * @param int $after Last post ID already read.
	 * @return array{groups:array,next:int,scanned:int}
	 */
	private function scan_posts( int $after ): array {
		global $wpdb;
		$patterns = $this->like_patterns();
		$like_sql = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
		$args     = array_merge( array( $after ), $patterns, array( self::BATCH ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The only interpolated text is a list of "post_content LIKE %s" placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_title AS title, IF( LENGTH( post_content ) > 200000, LEFT( post_content, 200000 ), post_content ) AS v FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ( 'revision', 'auto-draft' ) AND ( {$like_sql} ) ORDER BY ID ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$groups = array();
		$last   = 0;
		foreach ( (array) $rows as $row ) {
			$last = (int) $row['id'];
			foreach ( $this->find_refs( (string) $row['v'] ) as $slug_key => $sample ) {
				if ( $this->is_installed_key( $slug_key ) ) {
					continue;
				}
				$gk = 'posts' . "\x01" . '' . "\x01" . $slug_key;
				if ( ! isset( $groups[ $gk ] ) ) {
					$groups[ $gk ] = array(
						'source'    => 'posts',
						'key'       => '',
						'slug_key'  => $slug_key,
						'rows'      => 0,
						'deletable' => 0,
						'mixed'     => 0,
						'large'     => 0,
						'protected' => true,
						'sample'    => $sample,
						'links'     => array(),
					);
				}
				++$groups[ $gk ]['rows'];
				if ( count( $groups[ $gk ]['links'] ) < 5 ) {
					$groups[ $gk ]['links'][] = array(
						'id'    => (int) $row['id'],
						'title' => wp_html_excerpt( wp_strip_all_tags( (string) $row['title'] ), 60, '…' ),
						'url'   => esc_url_raw( admin_url( 'post.php?post=' . (int) $row['id'] . '&action=edit' ) ),
					);
				}
			}
		}

		return array(
			'groups'  => array_values( $groups ),
			'next'    => is_array( $rows ) && count( $rows ) >= self::BATCH ? $last : 0,
			'scanned' => is_array( $rows ) ? count( $rows ) : 0,
		);
	}

	// ── Peek / delete / restore ──────────────────────────────────────────────

	/**
	 * AJAX: show a few of the rows of one group (only the text around the stale path), so the decision is informed.
	 *
	 * Request: source, key, slug_key.
	 */
	public function ajax_peek(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();

		$source   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$key      = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used as a bound query value after the length and character checks below.
		$slug_key = isset( $_POST['slug_key'] ) ? strtolower( preg_replace( '~[^A-Za-z0-9:._-]~', '', wp_unslash( $_POST['slug_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to a safe character set here.
		$def      = $this->source_def( $source );
		if ( null === $def || '' === $key || strlen( $key ) > 255 || preg_match( '~[\x00-\x1f]~', $key ) || ! preg_match( '~^(plugin|theme):[a-z0-9._-]+$~', $slug_key ) ) {
			wp_send_json_error( __( 'Unknown source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		list( $table, $id_col, $key_col, $val_col ) = $def;
		$cap                                        = self::VALUE_CAP;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from source_def(); every value uses a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$id_col} AS id, IF( LENGTH( {$val_col} ) > {$cap}, LEFT( {$val_col}, {$cap} ), {$val_col} ) AS v, LENGTH( {$val_col} ) AS len FROM {$table} WHERE {$key_col} = %s ORDER BY {$id_col} ASC LIMIT 200",
				$key
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$refs = $this->find_refs( (string) $row['v'] );
			if ( ! isset( $refs[ $slug_key ] ) ) {
				continue;
			}
			$large     = (int) $row['len'] > self::ROW_CAP && ! $this->is_cache_key( $source, $key );
			$mixed     = false;
			$installed = array();
			foreach ( array_keys( $refs ) as $other ) {
				if ( $this->is_installed_key( (string) $other ) && ! $this->is_cache_key( $source, $key ) ) {
					$mixed       = true;
					$installed[] = (string) $other;
				}
			}
			$is_prot = $this->is_protected_key( $source, $key );
			$reason  = '';
			if ( $is_prot ) {
				$reason = 'protected';
			} elseif ( $mixed ) {
				$reason = 'installed';
			} elseif ( $large ) {
				$reason = 'large';
			}
			$norm  = str_replace( array( '\\\\/', '\\/' ), '/', (string) $row['v'] );
			$pos   = stripos( $norm, $refs[ $slug_key ] );
			$from  = max( 0, (int) $pos - 80 );
			$text  = substr( $norm, $from, strlen( $refs[ $slug_key ] ) + 160 );
			$out[] = array(
				'id'        => (int) $row['id'],
				'size'      => (int) $row['len'],
				'deletable' => '' === $reason,
				'reason'    => $reason,
				'why'       => $is_prot ? $this->protected_why( $source, $key ) : '',
				'installed' => array_slice( $installed, 0, 3 ),
				'excerpt'   => ( $from > 0 ? '…' : '' ) . wp_strip_all_tags( $text ) . ( strlen( $norm ) > $from + strlen( $text ) ? '…' : '' ),
			);
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		wp_send_json_success( array( 'rows' => $out ) );
	}

	/**
	 * AJAX: delete the rows of the selected groups (with a backup of what was removed).
	 *
	 * Request: items = JSON list of { source, key, slug_key }.
	 */
	public function ajax_delete(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();
		$this->more_time();

		// JSON list: it is decoded first and every field is validated below (sanitize_textarea_field would alter keys that contain "%xx").
		$raw   = isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and validated field by field below.
		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) || empty( $items ) || count( $items ) > 300 ) {
			wp_send_json_error( __( 'Nothing selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$to_delete = array(); // source => id => true.
		$skipped   = 0;
		$n_prot    = 0;
		$n_inst    = 0;
		$n_large   = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$source   = isset( $item['source'] ) ? sanitize_key( (string) $item['source'] ) : '';
			$key      = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '';
			$slug_key = isset( $item['slug_key'] ) ? strtolower( preg_replace( '~[^A-Za-z0-9:._-]~', '', (string) $item['slug_key'] ) ) : '';
			$def      = $this->source_def( $source );
			if ( null === $def || '' === $key || strlen( $key ) > 255 || preg_match( '~[\x00-\x1f]~', $key ) || ! preg_match( '~^(plugin|theme):[a-z0-9._-]+$~', $slug_key ) ) {
				continue;
			}
			if ( $this->is_installed_key( $slug_key ) ) {
				++$skipped;
				++$n_inst;
				continue;
			}
			if ( $this->is_protected_key( $source, $key ) ) {
				++$skipped;
				++$n_prot;
				continue;
			}
			$stats = array();
			foreach ( $this->collect_rows( $source, $def, $key, $slug_key, $stats ) as $id ) {
				$to_delete[ $source ][ $id ] = true;
			}
			$n_large += (int) ( $stats['large'] ?? 0 );
			$n_inst  += (int) ( $stats['installed'] ?? 0 );
		}

		$total = 0;
		foreach ( $to_delete as $ids ) {
			$total += count( $ids );
		}
		if ( 0 === $total ) {
			$why = array();
			if ( $n_inst > 0 ) {
				$why[] = __( 'Refused: the selection refers to an installed plugin or theme, or the rows also mention one.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( $n_prot > 0 ) {
				$why[] = __( 'Refused: the selection is a protected entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( $n_large > 0 ) {
				$why[] = __( 'Refused: the rows are too large to be safely removed here (they probably hold other data too).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			if ( empty( $why ) ) {
				$why[] = __( 'Nothing to delete: the rows may have been removed already.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			}
			wp_send_json_error( implode( ' ', $why ) );
		}
		if ( $total > self::DELETE_CAP ) {
			wp_send_json_error( __( 'Too many rows in one go. Select fewer groups and try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$backup = $this->build_backup( $to_delete );
		if ( null === $backup ) {
			wp_send_json_error( __( 'These rows are too large to keep a backup of them, so nothing was deleted. Select fewer groups.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! $this->store_backup( $backup ) ) {
			wp_send_json_error( __( 'The backup could not be saved, so nothing was deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		$deleted = 0;
		foreach ( $to_delete as $source => $ids ) {
			$def = $this->source_def( (string) $source );
			if ( null === $def ) {
				continue;
			}
			list( $table, $id_col ) = $def;
			foreach ( array_chunk( array_map( 'absint', array_keys( $ids ) ), 500 ) as $chunk ) {
				$in = implode( ',', $chunk );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def(); the list holds integers only (absint).
				$deleted += (int) $wpdb->query( "DELETE FROM {$table} WHERE {$id_col} IN ({$in})" );
			}
		}
		wp_cache_flush();

		TSOSK_Activity_Log::log(
			'leftovers',
			'delete',
			sprintf(
				/* translators: %d: number of database rows */
				__( 'Plugin/theme leftovers deleted: %d row(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$deleted
			),
			array(
				'rows'    => $deleted,
				'groups'  => count( $items ),
				'skipped' => $skipped,
			)
		);

		wp_send_json_success(
			array(
				'deleted' => $deleted,
				'skipped' => $skipped,
				'message' => sprintf(
					/* translators: %d: number of database rows */
					_n( 'Deleted %d row. You can undo it from the backup panel.', 'Deleted %d rows. You can undo it from the backup panel.', $deleted, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$deleted
				),
			)
		);
	}

	/**
	 * Name stem of a key that code may build at run time (for example "wpseo-gsc-issues-" . $type).
	 *
	 * @param string $key Option name.
	 * @return string Empty when the key has no useful stem.
	 */
	private function key_stem( string $key ): string {
		$len = strlen( $key );
		for ( $i = 12; $i < $len; $i++ ) {
			if ( '-' === $key[ $i ] || '_' === $key[ $i ] ) {
				return substr( $key, 0, $i );
			}
		}
		return '';
	}

	/**
	 * Look for a key in the PHP code of this site's plugins, themes and WordPress itself.
	 * If nothing installed mentions it, nothing installed can be reading it.
	 *
	 * @param string $key Option name.
	 * @return array{exact:string[],prefix:string[],scanned:int,truncated:bool,stem:string}
	 */
	private function find_key_usage( string $key ): array {
		$stem   = $this->key_stem( $key );
		$result = array(
			'exact'     => array(),
			'prefix'    => array(),
			'scanned'   => 0,
			'truncated' => false,
			'stem'      => $stem,
		);
		$roots  = array(
			'plugins'     => dirname( rtrim( wp_normalize_path( TSOSK_PATH ), '/' ) ),
			'themes'      => get_theme_root(),
			'wp-includes' => ABSPATH . WPINC,
			'wp-admin'    => ABSPATH . 'wp-admin',
		);
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$roots['mu-plugins'] = WPMU_PLUGIN_DIR;
		}
		$own     = wp_normalize_path( TSOSK_PATH );
		$started = microtime( true );
		$needle  = '' !== $stem ? $stem : $key;

		foreach ( $roots as $label => $root ) {
			$root = wp_normalize_path( (string) $root );
			if ( '' === $root || ! is_dir( $root ) ) {
				continue;
			}
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
			} catch ( Exception $e ) {
				continue;
			}
			foreach ( $iterator as $file ) {
				if ( microtime( true ) - $started > self::USAGE_TIME_CAP || $result['scanned'] >= self::USAGE_FILE_CAP ) {
					$result['truncated'] = true;
					break 2;
				}
				if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$path = wp_normalize_path( $file->getPathname() );
				$size = (int) $file->getSize();
				if ( 0 === strpos( $path, $own ) || $size < 1 || $size > 3000000 ) {
					continue;
				}
				++$result['scanned'];
				$text = (string) $file->openFile( 'r' )->fread( $size );
				if ( false === strpos( $text, $needle ) ) {
					continue;
				}
				$shown = $label . '/' . ltrim( substr( $path, strlen( $root ) ), '/' );
				if ( false !== strpos( $text, $key ) ) {
					if ( count( $result['exact'] ) < 5 ) {
						$result['exact'][] = $shown;
					}
				} elseif ( count( $result['prefix'] ) < 5 ) {
					$result['prefix'][] = $shown;
				}
			}
		}
		return $result;
	}

	/**
	 * AJAX: does any installed code mention this key?
	 */
	public function ajax_usage(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();
		$this->more_time();

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$key    = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only searched for as plain text after the length and character checks below.
		if ( null === $this->source_def( $source ) || '' === $key || strlen( $key ) > 255 || preg_match( '~[\x00-\x1f]~', $key ) ) {
			wp_send_json_error( __( 'Unknown source.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		wp_send_json_success( $this->find_key_usage( $key ) );
	}

	/**
	 * Status codes of the home page and admin-ajax, used to notice a broken site after a delete.
	 *
	 * @return int[] Status codes; empty when the server could not call itself.
	 */
	private function site_health_codes(): array {
		$urls  = array(
			add_query_arg( 'tsosk_lo_check', (string) time(), home_url( '/' ) ),
			add_query_arg( 'action', 'tsosk_lo_check', admin_url( 'admin-ajax.php' ) ),
		);
		$codes = array();
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 20,
					'user-agent' => 'TSO-Swiss-Knife-Leftovers/' . TSOSK_VERSION,
				)
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$codes[] = (int) wp_remote_retrieve_response_code( $response );
		}
		return $codes;
	}

	/**
	 * Whether a set of status codes shows a server error.
	 *
	 * @param int[] $codes Status codes.
	 */
	private function codes_have_error( array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( $code >= 500 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * AJAX: delete one large option that no installed code mentions, with a backup and a site check
	 * (the row is put back automatically if the site starts returning a server error).
	 */
	public function ajax_force_delete(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();
		$this->more_time();

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$key    = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used as a bound query value after the length and character checks below.
		$def    = $this->source_def( $source );
		if ( 'options' !== $source || null === $def || '' === $key || strlen( $key ) > 255 || preg_match( '~[\x00-\x1f]~', $key ) ) {
			wp_send_json_error( __( 'Only large settings stored in the options table can be deleted this way.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $this->is_protected_key( $source, $key ) ) {
			wp_send_json_error( __( 'Refused: the selection is a protected entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$usage = $this->find_key_usage( $key );
		if ( ! empty( $usage['exact'] ) || ! empty( $usage['prefix'] ) ) {
			wp_send_json_error( __( 'Refused: installed code mentions this key, so something may still read it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $usage['truncated'] ) {
			wp_send_json_error( __( 'Refused: the check of the installed code could not finish, so there is not enough evidence.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		global $wpdb;
		list( $table, $id_col, $key_col ) = $def;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def().
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT {$id_col} FROM {$table} WHERE {$key_col} = %s", $key ) );
		if ( $id < 1 ) {
			wp_send_json_error( __( 'Nothing to delete: the rows may have been removed already.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$to_delete = array( $source => array( $id => true ) );
		$backup    = $this->build_backup( $to_delete );
		if ( null === $backup ) {
			wp_send_json_error( __( 'These rows are too large to keep a backup of them, so nothing was deleted. Select fewer groups.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$backup_id = $this->store_backup( $backup );
		if ( '' === $backup_id ) {
			wp_send_json_error( __( 'The backup could not be saved, so nothing was deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$before = $this->site_health_codes();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def(); the id is an integer.
		$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE {$id_col} = " . absint( $id ) );
		wp_cache_flush();
		$after = $this->site_health_codes();

		if ( ! empty( $before ) && ! $this->codes_have_error( $before ) && $this->codes_have_error( $after ) ) {
			foreach ( $backup['rows'] as $saved_row ) {
				if ( isset( $saved_row['row'] ) && is_array( $saved_row['row'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Putting back the exact row that was just deleted.
					$wpdb->insert( $table, $saved_row['row'] );
				}
			}
			wp_cache_flush();
			$this->drop_backup( $backup_id );
			TSOSK_Activity_Log::log( 'leftovers', 'delete', __( 'Large option deleted and restored automatically: the site returned an error.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), array( 'key' => $key ) );
			wp_send_json_error( __( 'The site returned a server error after deleting, so the row was restored automatically. Nothing was lost.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		TSOSK_Activity_Log::log(
			'leftovers',
			'delete',
			sprintf(
				/* translators: %d: number of database rows */
				__( 'Plugin/theme leftovers deleted: %d row(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$deleted
			),
			array(
				'rows'  => $deleted,
				'large' => true,
			)
		);

		$check = empty( $after )
			? __( 'could not be verified (the server could not call itself)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: __( 'the home page and admin responded correctly', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		wp_send_json_success(
			array(
				'deleted' => $deleted,
				'key'     => $key,
				'message' => sprintf(
					/* translators: %s: result of the site check */
					__( 'Deleted. Site check after deleting: %s. You can undo it from the backup panel.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$check
				),
			)
		);
	}

	/**
	 * Ids of the rows of one group that are safe to delete (they only mention things that are not installed).
	 *
	 * @param string $source   Source slug.
	 * @param array  $def      Table definition.
	 * @param string $key      Option name or meta key.
	 * @param string $slug_key "type:slug".
	 * @param array  $stats    Filled with counters (large, installed, found).
	 * @return int[]
	 */
	private function collect_rows( string $source, array $def, string $key, string $slug_key, array &$stats = array() ): array {
		$stats = array(
			'large'     => 0,
			'installed' => 0,
			'found'     => 0,
		);
		global $wpdb;
		list( $table, $id_col, $key_col, $val_col ) = $def;

		// An orphan "theme_mods_NAME" option holds no path: it is identified by its name.
		if ( 'options' === $source && 0 === strpos( $key, 'theme_mods_' ) && 'theme:' . strtolower( substr( $key, strlen( 'theme_mods_' ) ) ) === $slug_key ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def().
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT {$id_col} FROM {$table} WHERE {$key_col} = %s", $key ) );
			return $id ? array( (int) $id ) : array();
		}

		$is_cache = $this->is_cache_key( $source, $key );
		$cap      = self::VALUE_CAP;
		$ids      = array();
		$after    = 0;
		$more     = true;
		$found    = 0;
		while ( $more && $found <= self::DELETE_CAP ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column names come from source_def(); every value uses a placeholder.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$id_col} AS id, IF( LENGTH( {$val_col} ) > {$cap}, LEFT( {$val_col}, {$cap} ), {$val_col} ) AS v, LENGTH( {$val_col} ) AS len FROM {$table} WHERE {$key_col} = %s AND {$id_col} > %d ORDER BY {$id_col} ASC LIMIT %d",
					$key,
					$after,
					self::BATCH
				),
				ARRAY_A
			);
			// phpcs:enable
			foreach ( (array) $rows as $row ) {
				$after = (int) $row['id'];
				$refs  = $this->find_refs( (string) $row['v'] );
				if ( ! isset( $refs[ $slug_key ] ) ) {
					continue;
				}
				++$stats['found'];
				if ( (int) $row['len'] > self::ROW_CAP && ! $is_cache ) {
					++$stats['large'];
					continue;
				}
				$only_missing = true;
				foreach ( array_keys( $refs ) as $other ) {
					if ( $this->is_installed_key( (string) $other ) && ! $is_cache ) {
						$only_missing = false;
						++$stats['installed'];
						break;
					}
				}
				if ( $only_missing ) {
					$ids[] = (int) $row['id'];
					++$found;
				}
			}
			$more = is_array( $rows ) && count( $rows ) >= self::BATCH;
		}

		return $ids;
	}

	/**
	 * Copy the rows about to be deleted, in a form that restore can put back.
	 *
	 * @param array<string,array<int,bool>> $to_delete Row ids by source.
	 * @return array|null Null when the backup would be too large.
	 */
	private function build_backup( array $to_delete ): ?array {
		global $wpdb;
		$backup = array(
			'time' => time(),
			'user' => get_current_user_id(),
			'rows' => array(),
		);
		$bytes  = 0;
		foreach ( $to_delete as $source => $ids ) {
			$def = $this->source_def( (string) $source );
			if ( null === $def ) {
				continue;
			}
			list( $table, $id_col ) = $def;
			foreach ( array_chunk( array_map( 'absint', array_keys( $ids ) ), 200 ) as $chunk ) {
				$in = implode( ',', $chunk );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def(); the list holds integers only (absint).
				$found = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$id_col} IN ({$in})", ARRAY_A );
				foreach ( (array) $found as $row ) {
					foreach ( $row as $value ) {
						$bytes += strlen( (string) $value );
					}
					if ( $bytes > self::BACKUP_CAP_BYTES ) {
						return null;
					}
					$backup['rows'][] = array(
						'source' => (string) $source,
						'row'    => $row,
					);
				}
			}
		}
		return $backup;
	}

	/**
	 * Backups kept, newest first. The single backup of earlier versions is moved into the list.
	 *
	 * @return array<int,array>
	 */
	private function get_backups(): array {
		$all = get_option( self::BACKUPS_OPTION );
		if ( ! is_array( $all ) ) {
			$all    = array();
			$legacy = get_option( self::BACKUP_OPTION );
			if ( is_array( $legacy ) && ! empty( $legacy['rows'] ) ) {
				$legacy['id'] = 'b' . (int) ( $legacy['time'] ?? 0 );
				$all[]        = $legacy;
				update_option( self::BACKUPS_OPTION, $all, false );
				delete_option( self::BACKUP_OPTION );
			}
		}
		$out = array();
		foreach ( $all as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) && ! empty( $entry['rows'] ) && is_array( $entry['rows'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Position of a backup in the list, or -1.
	 *
	 * @param array<int,array> $all Backups.
	 * @param string           $id  Backup id.
	 * @return int
	 */
	private function find_backup( array $all, string $id ): int {
		foreach ( $all as $pos => $entry ) {
			if ( '' !== $id && (string) ( $entry['id'] ?? '' ) === $id ) {
				return (int) $pos;
			}
		}
		return -1;
	}

	/**
	 * Add a backup to the list (newest first), dropping the oldest ones over the limits, and check it was saved.
	 *
	 * @param array $backup Backup from build_backup().
	 * @return string Backup id, or an empty string when it could not be saved.
	 */
	private function store_backup( array $backup ): string {
		$backup['id'] = 'b' . (int) $backup['time'] . strtolower( wp_generate_password( 4, false, false ) );
		$all          = $this->get_backups();
		array_unshift( $all, $backup );
		$all = array_slice( $all, 0, self::MAX_BACKUPS );
		for ( $n = count( $all ); $n > 1; $n-- ) {
			if ( strlen( maybe_serialize( $all ) ) <= self::BACKUPS_CAP_BYTES ) {
				break;
			}
			array_pop( $all );
		}
		update_option( self::BACKUPS_OPTION, array_values( $all ), false );
		$saved = $this->get_backups();
		return $this->find_backup( $saved, $backup['id'] ) >= 0 ? $backup['id'] : '';
	}

	/**
	 * Remove one backup from the list (used when its rows were put back automatically).
	 *
	 * @param string $id Backup id.
	 */
	private function drop_backup( string $id ): void {
		$all = $this->get_backups();
		$pos = $this->find_backup( $all, $id );
		if ( $pos >= 0 ) {
			unset( $all[ $pos ] );
			update_option( self::BACKUPS_OPTION, array_values( $all ), false );
		}
	}

	/**
	 * Backup id sent by the browser, reduced to letters and digits.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function clean_backup_id( string $raw ): string {
		return substr( (string) preg_replace( '~[^A-Za-z0-9]~', '', $raw ), 0, 40 );
	}

	/**
	 * AJAX: put back the rows of one backup (all of them, or only the ones picked).
	 */
	public function ajax_restore(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();

		$id  = isset( $_POST['id'] ) ? $this->clean_backup_id( (string) wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to letters and digits by clean_backup_id().
		$raw = isset( $_POST['rows'] ) ? (string) wp_unslash( $_POST['rows'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and reduced to integers below.
		$all = $this->get_backups();
		$pos = $this->find_backup( $all, $id );
		if ( $pos < 0 ) {
			wp_send_json_error( __( 'No backup available to restore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$wanted = null;
		if ( '' !== $raw ) {
			$pick = json_decode( $raw, true );
			if ( ! is_array( $pick ) || empty( $pick ) ) {
				wp_send_json_error( __( 'Tick at least one row first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
			$wanted = array_flip( array_map( 'absint', $pick ) );
		}

		global $wpdb;
		$restored = 0;
		$skipped  = 0;
		$left     = array();
		foreach ( $all[ $pos ]['rows'] as $i => $entry ) {
			if ( null !== $wanted && ! isset( $wanted[ $i ] ) ) {
				$left[] = $entry;
				continue;
			}
			$def = $this->source_def( (string) ( $entry['source'] ?? '' ) );
			if ( null === $def || ! is_array( $entry['row'] ?? null ) ) {
				continue;
			}
			list( $table, $id_col, $key_col ) = $def;
			$row                              = $entry['row'];
			$row_id                           = isset( $row[ $id_col ] ) ? absint( $row[ $id_col ] ) : 0;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column come from source_def(); values use placeholders.
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$id_col} = %d", $row_id ) );
			if ( ! $exists && 'options' === $entry['source'] ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$key_col} = %s", (string) ( $row[ $key_col ] ?? '' ) ) );
			}
			// phpcs:enable
			if ( $exists ) {
				++$skipped;
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoring the exact rows that were read from this same table.
			if ( false !== $wpdb->insert( $table, $row ) ) {
				++$restored;
			} else {
				$left[] = $entry;
			}
		}
		if ( empty( $left ) ) {
			unset( $all[ $pos ] );
		} else {
			$all[ $pos ]['rows'] = $left;
		}
		update_option( self::BACKUPS_OPTION, array_values( $all ), false );
		wp_cache_flush();

		TSOSK_Activity_Log::log(
			'leftovers',
			'restore',
			sprintf(
				/* translators: %d: number of database rows */
				__( 'Plugin/theme leftovers restored: %d row(s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$restored
			),
			array(
				'rows'    => $restored,
				'skipped' => $skipped,
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: restored rows, 2: rows skipped because they already exist */
					__( 'Restored %1$d row(s). Skipped %2$d that already exist.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$restored,
					$skipped
				),
			)
		);
	}

	/**
	 * AJAX: delete one backup, or all of them.
	 */
	public function ajax_backup_delete(): void {
		check_ajax_referer( 'tsosk_lo_nonce', 'nonce' );
		$this->ensure_allowed();

		$id  = isset( $_POST['id'] ) ? $this->clean_backup_id( (string) wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to letters and digits by clean_backup_id().
		$all = $this->get_backups();
		if ( isset( $_POST['all'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['all'] ) ) ) {
			$count = count( $all );
			$all   = array();
		} else {
			$pos = $this->find_backup( $all, $id );
			if ( $pos < 0 ) {
				wp_send_json_error( __( 'No backup available to restore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
			unset( $all[ $pos ] );
			$count = 1;
		}
		update_option( self::BACKUPS_OPTION, array_values( $all ), false );

		TSOSK_Activity_Log::log(
			'leftovers',
			'delete',
			sprintf(
				/* translators: %d: number of backups */
				__( 'Plugin/theme leftovers backups deleted: %d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$count
			),
			array( 'backups' => $count )
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of backups */
					_n( '%d backup deleted.', '%d backups deleted.', $count, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$count
				),
			)
		);
	}

	/**
	 * Download one backup as a JSON file.
	 */
	public function download_backup(): void {
		if ( ! TSOSK_Support::can_manage_sensitive() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_lo_download_backup' );

		$id  = isset( $_GET['id'] ) ? $this->clean_backup_id( (string) wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to letters and digits by clean_backup_id(); the nonce was checked above.
		$all = $this->get_backups();
		$pos = $this->find_backup( $all, $id );
		if ( $pos < 0 ) {
			wp_die( esc_html__( 'No backup available.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$backup = $all[ $pos ];

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-leftovers-backup-' . gmdate( 'Y-m-d-His', (int) ( $backup['time'] ?? 0 ) ) . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body.
		echo wp_json_encode( $backup, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Label, owner id and size of a backed-up row.
	 *
	 * @param array $entry Backup entry (source and row).
	 * @return array{source:string,key:string,owner:string,bytes:int}
	 */
	private function backup_row_info( array $entry ): array {
		$source = (string) ( $entry['source'] ?? '' );
		$row    = is_array( $entry['row'] ?? null ) ? $entry['row'] : array();
		$def    = $this->source_def( $source );
		$key    = null !== $def ? (string) ( $row[ $def[2] ] ?? '' ) : '';
		$owner  = '';
		foreach ( array( 'post_id', 'user_id', 'term_id', 'comment_id' ) as $col ) {
			if ( isset( $row[ $col ] ) ) {
				$owner = (string) $row[ $col ];
			}
		}
		$bytes = 0;
		foreach ( $row as $value ) {
			$bytes += strlen( (string) $value );
		}
		return array(
			'source' => $source,
			'key'    => $key,
			'owner'  => $owner,
			'bytes'  => $bytes,
		);
	}

	/**
	 * Panel with the backups kept: restore a whole batch or picked rows, download, delete.
	 */
	private function render_backups_box(): void {
		$backups = $this->get_backups();
		if ( empty( $backups ) ) {
			return;
		}
		$labels = array(
			'options'     => __( 'Options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'postmeta'    => __( 'Post meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usermeta'    => __( 'User meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'termmeta'    => __( 'Term meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'commentmeta' => __( 'Comment meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		?>
		<div class="tsosk-card" id="tsosk-lo-backup">
			<h3><?php esc_html_e( 'Automatic backups', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="tsosk-desc"><?php esc_html_e( 'The latest deletions are kept here (up to 10, within a size limit; the oldest are dropped first). Restore a whole batch or only the rows you tick, download it as JSON, or delete it once you are sure the site works fine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p>
				<button type="button" class="button" id="tsosk-lo-bk-delall"><?php esc_html_e( 'Delete all backups', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-lo-restore-msg" role="status"></span>
			</p>
			<?php
			foreach ( $backups as $backup ) :
				$rows  = $backup['rows'];
				$infos = array();
				$bytes = 0;
				foreach ( $rows as $i => $entry ) {
					$info   = $this->backup_row_info( (array) $entry );
					$bytes += $info['bytes'];
					if ( count( $infos ) < self::SHOWN_ROWS ) {
						$infos[ $i ] = $info;
					}
				}
				$user = get_userdata( (int) ( $backup['user'] ?? 0 ) );
				$url  = wp_nonce_url( admin_url( 'admin-post.php?action=tsosk_lo_download_backup&id=' . rawurlencode( (string) $backup['id'] ) ), 'tsosk_lo_download_backup' );
				?>
				<details class="tsosk-lo-bk" data-id="<?php echo esc_attr( (string) $backup['id'] ); ?>">
					<summary>
						<strong>
							<?php
							printf(
								/* translators: 1: date and time, 2: number of rows, 3: size */
								esc_html__( '%1$s: %2$d row(s), %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								esc_html( wp_date( 'Y-m-d H:i', (int) ( $backup['time'] ?? 0 ) ) ),
								count( $rows ),
								esc_html( size_format( $bytes ) )
							);
							?>
						</strong>
						<?php if ( $user ) : ?>
							<span class="tsosk-lo-bk-user">
								<?php
								printf(
									/* translators: %s: user name */
									esc_html__( 'Deleted by %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									esc_html( $user->display_name )
								);
								?>
							</span>
						<?php endif; ?>
					</summary>
					<p class="tsosk-lo-bk-actions">
						<button type="button" class="button button-primary tsosk-lo-bk-restore-all"><?php esc_html_e( 'Restore whole batch', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
						<button type="button" class="button tsosk-lo-bk-restore-sel"><?php esc_html_e( 'Restore ticked rows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
						<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Download JSON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
						<button type="button" class="button tsosk-lo-bk-del"><?php esc_html_e( 'Delete this backup', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
					</p>
					<div class="tsosk-lo-scroll">
						<table class="widefat striped">
							<thead>
								<tr>
									<th class="check-column"><input type="checkbox" class="tsosk-lo-bk-pickall" aria-label="<?php esc_attr_e( 'Select all', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>" /></th>
									<th><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Owner ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $infos as $i => $info ) : ?>
									<tr>
										<th class="check-column"><input type="checkbox" class="tsosk-lo-bk-pick" value="<?php echo esc_attr( (string) $i ); ?>" /></th>
										<td><?php echo esc_html( $labels[ $info['source'] ] ?? $info['source'] ); ?></td>
										<td><code><?php echo esc_html( $info['key'] ); ?></code></td>
										<td><?php echo esc_html( $info['owner'] ); ?></td>
										<td><?php echo esc_html( size_format( $info['bytes'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php if ( count( $rows ) > count( $infos ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of rows not listed */
								esc_html__( '%d more row(s) are not listed; they are restored with "Restore whole batch" and included in the JSON.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
								count( $rows ) - count( $infos )
							);
							?>
						</p>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Texts used by the browser script.
	 *
	 * @return array<string,string>
	 */
	private function js_strings(): array {
		return array(
			'scanning'            => __( 'Scanning…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'scan_done'           => __( 'Scan finished.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'nothing'             => __( 'Nothing found: the database has no references to plugins or themes that are not installed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'error'               => __( 'The request failed. Try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'plugin'              => __( 'Plugin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'theme'               => __( 'Theme', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'not_installed'       => __( 'not installed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'where'               => __( 'Where', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'name'                => __( 'Name / key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'rows'                => __( 'Rows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'example'             => __( 'Example', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_options'         => __( 'Options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_postmeta'        => __( 'Post meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_usermeta'        => __( 'User meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_termmeta'        => __( 'Term meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_commentmeta'     => __( 'Comment meta', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'src_posts'           => __( 'Post content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'deleted_badge'       => __( 'Deleted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usage_btn'           => __( 'Check usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usage_running'       => __( 'Checking the installed code…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			/* translators: %d: number of PHP files checked */
			'usage_none'          => __( 'No installed plugin, theme or WordPress file mentions this key (%d files checked). Nothing seems to read it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usage_found'         => __( 'Installed code mentions this key, so it may still be in use: ', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usage_prefix'        => __( 'Installed code uses a similar name, so it may still be in use: ', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'usage_trunc'         => __( 'The check could not finish (too many files). There is not enough evidence to delete it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'force_btn'           => __( 'Delete anyway (backup and site check)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'confirm_force'       => __( 'Delete this row? A backup is kept and the site is checked afterwards; if it returns a server error the row is restored automatically.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'pick_hint'           => __( 'Tick the rows you want to delete to enable the button.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'section_del'         => __( 'Can be deleted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'section_del_desc'    => __( 'These items are not installed anymore and nothing installed refers to them. Tick what you want to delete; a backup is kept so you can undo it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'section_info'        => __( 'Information only (not deleted in bulk)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'section_info_desc'   => __( 'Protected, too large or shared with something installed. They are not deleted in bulk. Protected entries are never deleted. Large options can be deleted one by one with "Delete anyway", only if no installed code mentions them; a backup is kept and the site is checked afterwards.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge_protected'     => __( 'Protected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge_large'         => __( 'Too large', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'badge_installed'     => __( 'Shared', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'large_note'          => __( 'too large to delete here (they probably hold other data too)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_installed'      => __( 'kept: it also mentions an installed item', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_protected'      => __( 'kept: protected entry', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_large'          => __( 'kept: too large, it probably holds other data too', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'mixed_note'          => __( 'also mention installed items (not deletable here)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'protected_note'      => __( 'protected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'posts_note'          => __( 'Posts are only listed. Edit them, or use Search & Replace.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'view'                => __( 'View', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hide'                => __( 'Hide', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_deletable'      => __( 'can be deleted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_kept'           => __( 'kept: it also mentions installed items, is protected or is too large', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'peek_none'           => __( 'No rows to show.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'select_all'          => __( 'Select all deletable rows of this item', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_btn'          => __( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			/* translators: %d: number of rows */
			'selected'            => __( '%d rows selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			/* translators: %d: number of rows */
			'confirm_delete'      => __( 'Delete %d row(s) from the database? A backup of them is kept so you can undo it. Click OK to proceed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'confirm_restore'     => __( 'Restore all the rows of this backup? Rows that exist again are skipped.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'confirm_restore_sel' => __( 'Restore the ticked rows? Rows that exist again are skipped.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'confirm_bk_del'      => __( 'Delete this backup? You will not be able to restore its rows afterwards.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'confirm_bk_delall'   => __( 'Delete all backups? You will not be able to restore any of these rows afterwards.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'bk_none'             => __( 'Tick at least one row first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			/* translators: %1$d: number of plugin and theme folders */
			'summary'             => __( '%1$d plugin/theme folder(s) found in the database that are not installed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_lo_nonce' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Finds data in the database that points to plugins or themes you no longer have installed, and lets you choose what to delete. Nothing is deleted without your decision.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-warn" style="border-left-color:#d63638;">
			<strong><?php esc_html_e( 'How it works', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
			<?php esc_html_e( 'The scan looks for paths such as wp-content/plugins/NAME/ or wp-content/themes/NAME/ in options, post meta, user meta, term meta, comment meta and post content, and compares NAME with the plugins and themes installed now. Only rows that mention nothing but missing items can be deleted. Posts are only listed. Review each group before deleting: a plugin you reinstall later will start from scratch.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>

		<div class="tsosk-card" id="tsosk-lo-app"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-strings="<?php echo esc_attr( (string) wp_json_encode( $this->js_strings() ) ); ?>">
			<h3><?php esc_html_e( 'Scan the database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p>
				<button type="button" class="button button-primary" id="tsosk-lo-scan"><?php esc_html_e( 'Scan now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-lo-status" role="status"></span>
			</p>
			<div id="tsosk-lo-results"></div>
			<div id="tsosk-lo-actions" style="display:none;">
				<button type="button" class="button button-primary" id="tsosk-lo-delete" disabled><?php esc_html_e( 'Delete selected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-lo-selected"></span>
			</div>
		</div>

		<?php $this->render_backups_box(); ?>
		<?php
	}
}
