<?php
/**
 * TSO Swiss Knife – Module: Plugin Footprint.
 *
 * Shows each plugin as a card with its option count.
 * Option→plugin matching uses exclusive longest-prefix assignment
 * so short/ambiguous prefixes cannot steal unrelated keys.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Footprint
 */
class TSOSK_Mod_Footprint {

	/** Minimum length for a LIKE prefix (without forcing a separator). */
	private const MIN_PREFIX_LEN = 4;

	/** Stems never used as option prefixes (too generic / shared). */
	private const PREFIX_DENYLIST = array(
		'wp',
		'wordpress',
		'the',
		'and',
		'for',
		'get',
		'set',
		'add',
		'del',
		'admin',
		'site',
		'user',
		'post',
		'page',
		'core',
		'data',
		'meta',
		'option',
		'settings',
		'config',
		'cache',
		'mail',
		'form',
		'block',
		'widget',
		'theme',
		'plugin',
		'index',
		'main',
		'init',
		'load',
		'tso',
		'tsosk',
		'wc',
		'kb',
		'sg',
		'gf',
		'wf',
		'rg',
		'it',
		'my',
		'app',
		'api',
		'db',
		'id',
		'url',
		'css',
		'js',
	);

	/** @var TSOSK_Mod_Footprint|null */
	private static $instance = null;

	/** @var string[]|null */
	private $all_option_names = null;

	/**
	 * @return TSOSK_Mod_Footprint
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render plugin footprint cards.
	 */
	public function render(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		$plugins = get_plugins();
		$active  = array_fill_keys( (array) get_option( 'active_plugins', array() ), true );

		$candidates = array();
		foreach ( $plugins as $file => $plugin ) {
			$slug    = $this->plugin_slug( $file );
			$matchers = $this->matchers_for_plugin( $file, $slug, $plugin );
			$candidates[ $file ] = array(
				'name'     => $plugin['Name'] ?? $file,
				'version'  => $plugin['Version'] ?? '',
				'author'   => $plugin['Author'] ?? '',
				'file'     => $file,
				'slug'     => $slug,
				'matchers' => $matchers,
				'active'   => isset( $active[ $file ] ),
			);
		}

		$assigned = $this->assign_options_exclusively( $candidates );

		$active_rows   = array();
		$inactive_rows = array();

		foreach ( $candidates as $file => $row ) {
			$row['options']  = isset( $assigned[ $file ] ) ? count( $assigned[ $file ] ) : 0;
			$row['prefixes'] = $this->display_prefixes( $row['matchers'] );
			$row['samples']  = isset( $assigned[ $file ] ) ? array_slice( $assigned[ $file ], 0, 8 ) : array();
			if ( $row['active'] ) {
				$active_rows[] = $row;
			} else {
				$inactive_rows[] = $row;
			}
		}

		usort( $active_rows, static fn( $a, $b ) => $b['options'] - $a['options'] );
		usort( $inactive_rows, static fn( $a, $b ) => $b['options'] - $a['options'] );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Shows how many wp_options rows are attributed to each plugin. Each option key is assigned to at most one plugin (longest matching prefix / exact name from plugin source). Short generic prefixes are ignored so keys are not mis-attributed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=options-editor' ) ); ?>">
				<?php esc_html_e( 'Open Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<?php $this->render_plugin_grid( __( 'Active Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $active_rows, true ); ?>

		<?php if ( ! empty( $inactive_rows ) ) : ?>
			<?php $this->render_plugin_grid( __( 'Inactive Plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $inactive_rows, false ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param string               $title Section title.
	 * @param array<int,array>     $rows  Plugin rows.
	 * @param bool                 $active Whether these are active plugins.
	 */
	private function render_plugin_grid( string $title, array $rows, bool $active ): void {
		?>
		<div class="tsosk-card">
			<h3>
				<?php echo esc_html( $title ); ?>
				<span class="tsosk-badge <?php echo $active ? 'tsosk-badge-ok' : ''; ?>" style="margin-left:8px;font-size:12px;"><?php echo esc_html( (string) count( $rows ) ); ?></span>
			</h3>
			<?php if ( ! $active ) : ?>
			<p class="description">
				<?php esc_html_e( 'Inactive plugins may still leave options in the database. If an inactive plugin has many options you may want to uninstall it properly (Delete in the Plugins list) rather than just deactivating it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php endif; ?>
			<div class="tsosk-fp-grid">
				<?php foreach ( $rows as $row ) : ?>
				<div class="tsosk-fp-card <?php echo $active ? '' : 'tsosk-fp-card-inactive'; ?>">
					<div class="tsosk-fp-card-name">
						<strong><?php echo esc_html( $row['name'] ); ?></strong>
						<?php if ( $row['version'] ) : ?>
						<span class="tsosk-fp-version">v<?php echo esc_html( $row['version'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tsosk-fp-card-meta">
						<?php if ( $row['options'] > 0 ) : ?>
						<span class="tsosk-fp-options <?php echo ! $active ? 'tsosk-fp-warn' : ( $row['options'] > 20 ? 'tsosk-fp-heavy' : ( $row['options'] > 5 ? 'tsosk-fp-medium' : '' ) ); ?>">
							<?php
							if ( $active ) {
								printf(
									/* translators: %d: number of options */
									esc_html__( '%d options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									(int) $row['options']
								);
							} else {
								printf(
									/* translators: %d: number of options remaining */
									esc_html__( '%d options remaining', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
									(int) $row['options']
								);
							}
							?>
						</span>
						<?php if ( class_exists( 'TSOSK_Mod_Options_Editor' ) ) : ?>
						<a href="<?php echo esc_url( TSOSK_Mod_Options_Editor::get_admin_url_with_search( $this->best_search_prefix( $row['matchers'], $row['samples'] ) ) ); ?>"
						   class="button button-small tsosk-fp-oe-link">
							<?php esc_html_e( 'View in Options Editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</a>
						<?php endif; ?>
						<?php else : ?>
						<span class="tsosk-fp-options"><?php esc_html_e( '0 options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
						<?php endif; ?>
						<code class="tsosk-fp-slug"><?php echo esc_html( dirname( $row['file'] ) ); ?></code>
					</div>
					<?php if ( ! empty( $row['prefixes'] ) ) : ?>
					<p class="description" style="margin:8px 0 0;word-break:break-word;">
						<strong><?php esc_html_e( 'Matched by:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<code><?php echo esc_html( implode( ', ', array_slice( $row['prefixes'], 0, 6 ) ) ); ?></code>
					</p>
					<?php endif; ?>
					<?php if ( ! empty( $row['samples'] ) ) : ?>
					<p class="description" style="margin:4px 0 0;word-break:break-word;">
						<strong><?php esc_html_e( 'Example keys:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<code><?php echo esc_html( implode( ', ', $row['samples'] ) ); ?></code>
					</p>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $file Plugin basename path.
	 * @return string
	 */
	private function plugin_slug( string $file ): string {
		$parts = explode( '/', $file );
		$slug  = count( $parts ) > 1 ? $parts[0] : preg_replace( '/\.php$/', '', $file );
		return sanitize_key( str_replace( '-', '_', (string) $slug ) );
	}

	/**
	 * Build matchers for a plugin: exact option names + strict prefixes.
	 *
	 * @param string               $file   Plugin basename path.
	 * @param string               $slug   Sanitized slug.
	 * @param array<string,string> $plugin Plugin header data.
	 * @return array{exact:string[],prefixes:string[]}
	 */
	private function matchers_for_plugin( string $file, string $slug, array $plugin = array() ): array {
		$exact    = array();
		$prefixes = array();
		$parts    = explode( '/', $file );
		$folder   = ( count( $parts ) > 1 && '.' !== $parts[0] ) ? $parts[0] : '';

		if ( '' !== $folder ) {
			$this->add_prefix_candidate( $prefixes, $folder );
			$this->add_prefix_candidate( $prefixes, str_replace( '-', '_', $folder ) );
		}

		$this->add_prefix_candidate( $prefixes, $slug );
		$this->add_prefix_candidate( $prefixes, str_replace( '_', '-', $slug ) );

		if ( ! empty( $plugin['TextDomain'] ) ) {
			$td = (string) $plugin['TextDomain'];
			$this->add_prefix_candidate( $prefixes, $td );
			$this->add_prefix_candidate( $prefixes, str_replace( '-', '_', $td ) );
		}

		foreach ( $this->known_prefixes_for_folder( $folder !== '' ? $folder : $slug ) as $known ) {
			$this->add_prefix_candidate( $prefixes, $known, true );
		}

		$scanned = $this->scan_plugin_option_keys( $file );
		foreach ( $scanned['exact'] as $name ) {
			$exact[] = $name;
		}
		foreach ( $scanned['prefixes'] as $prefix ) {
			$this->add_prefix_candidate( $prefixes, $prefix );
		}

		$exact    = array_values( array_unique( array_filter( $exact ) ) );
		$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );

		// Prefer longer prefixes first for display / search.
		usort(
			$prefixes,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return array(
			'exact'    => $exact,
			'prefixes' => $prefixes,
		);
	}

	/**
	 * Add a prefix if it is specific enough.
	 *
	 * @param string[] $prefixes Prefix list (by ref).
	 * @param string   $raw      Raw candidate.
	 * @param bool     $known    From known-plugin map (allows slightly shorter with separator).
	 */
	private function add_prefix_candidate( array &$prefixes, string $raw, bool $known = false ): void {
		$raw = strtolower( trim( $raw ) );
		$raw = preg_replace( '/[^a-z0-9_\-]/', '', $raw );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}

		// Normalize trailing separators for storage (matching adds them).
		$core = rtrim( $raw, '_-' );
		if ( '' === $core ) {
			return;
		}

		if ( ! $known && in_array( $core, self::PREFIX_DENYLIST, true ) ) {
			return;
		}

		// Known short stems (wc, gf, …) are OK because matching always requires _ or - after the stem.
		$min = self::MIN_PREFIX_LEN;
		if ( $known ) {
			$min = 2;
		}
		if ( strlen( $core ) < $min ) {
			return;
		}

		$prefixes[] = $core;
	}

	/**
	 * Known option prefixes for popular plugins (folder slug ≠ DB keys).
	 * Prefer specific stems; short ones like wc/gf only work with _ / - separator later.
	 *
	 * @param string $folder Plugin directory name.
	 * @return string[]
	 */
	private function known_prefixes_for_folder( string $folder ): array {
		$map = array(
			'better-wp-security'         => array( 'itsec', 'itsec_', 'ithemes-security' ),
			'wordpress-seo'              => array( 'wpseo', 'yoast_migrations', 'yoast_indexation' ),
			'seo-by-rank-math'           => array( 'rank_math' ),
			'kadence-blocks'             => array( 'kadence_blocks', 'kadence_blocks_' ),
			'woocommerce'                => array( 'woocommerce', 'wc_' ),
			'elementor'                  => array( 'elementor', '_elementor' ),
			'advanced-custom-fields'     => array( 'acf_' ),
			'advanced-custom-fields-pro' => array( 'acf_' ),
			'wordfence'                  => array( 'wordfence', 'wfls_', 'wf_' ),
			'wp-mail-smtp'               => array( 'wp_mail_smtp' ),
			'updraftplus'                => array( 'updraft_', 'updraftplus' ),
			'redirection'                => array( 'redirection_' ),
			'jetpack'                    => array( 'jetpack' ),
			'gravityforms'               => array( 'gf_', 'gravityforms', 'rg_' ),
			'wpforms-lite'               => array( 'wpforms' ),
			'wpforms'                    => array( 'wpforms' ),
			'litespeed-cache'            => array( 'litespeed' ),
			'wp-rocket'                  => array( 'wp_rocket', 'rocket_' ),
			'sg-cachepress'              => array( 'siteground', 'sg_cachepress' ),
			'fluentform'                 => array( 'fluentform', '_fluentform' ),
			'fluent-crm'                 => array( 'fluentcrm', '_fluentcrm' ),
			'contact-form-7'             => array( 'wpcf7' ),
			'all-in-one-wp-migration'    => array( 'ai1wm' ),
			'duplicate-post'             => array( 'duplicate_post' ),
			'classic-editor'             => array( 'classic-editor' ),
			'tso-swiss-knife-advanced-maintenance-developer-toolkit' => array( 'tsosk_', 'tso_swiss_knife_' ),
			'tso-options-tables-cleaner' => array( 'tsootc_', 'tso_options_tables_cleaner_' ),
			'tso-image-master'           => array( 'tsoim_', 'tso_image_master_' ),
			'tso-link-inspector'         => array( 'tsoliin_', 'tso_link_inspector_' ),
		);

		return $map[ $folder ] ?? array();
	}

	/**
	 * Scan plugin PHP for literal option names used in *option() calls.
	 *
	 * @param string $file Plugin basename path.
	 * @return array{exact:string[],prefixes:string[]}
	 */
	private function scan_plugin_option_keys( string $file ): array {
		$exact    = array();
		$prefixes = array();
		$paths    = array();
		$main     = tsosk_get_plugin_file_path( $file );
		if ( '' !== $main ) {
			$paths[] = $main;
		}
		$dir = '' !== $main ? dirname( $main ) : '';
		if ( '' !== $dir && is_dir( $dir ) ) {
			$main_base = basename( $file );
			foreach ( glob( $dir . '/*.php' ) ?: array() as $php_file ) {
				if ( basename( $php_file ) !== $main_base ) {
					$paths[] = $php_file;
				}
			}
			foreach ( glob( $dir . '/includes/*.php' ) ?: array() as $php_file ) {
				$paths[] = $php_file;
			}
			foreach ( array( 'src', 'admin', 'classes', 'inc' ) as $sub ) {
				foreach ( glob( $dir . '/' . $sub . '/*.php' ) ?: array() as $php_file ) {
					$paths[] = $php_file;
				}
			}
		}

		foreach ( array_slice( array_unique( $paths ), 0, 25 ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = (string) file_get_contents( $path, false, null, 0, 262144 );
			if ( ! preg_match_all( "/(?:get_option|update_option|add_option|delete_option)\s*\(\s*['\"]([a-zA-Z0-9_\-]{3,80})['\"]/", $content, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $option_name ) {
				$name = strtolower( (string) $option_name );
				if ( in_array( $name, self::PREFIX_DENYLIST, true ) ) {
					continue;
				}
				$exact[] = $name;

				// Derive a stem only when long enough and followed by a separator in the original name.
				if ( preg_match( '/^([a-z][a-z0-9]{2,})[_-]/i', $name, $pm ) ) {
					$stem = strtolower( $pm[1] );
					if ( ! in_array( $stem, self::PREFIX_DENYLIST, true ) && strlen( $stem ) >= self::MIN_PREFIX_LEN ) {
						$prefixes[] = $stem;
					}
				}
			}
		}

		return array(
			'exact'    => array_values( array_unique( $exact ) ),
			'prefixes' => array_values( array_unique( $prefixes ) ),
		);
	}

	/**
	 * Load all option names once (no values).
	 *
	 * @return string[]
	 */
	private function get_all_option_names(): array {
		if ( null !== $this->all_option_names ) {
			return $this->all_option_names;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
		$this->all_option_names = array_map( 'strval', (array) $names );
		return $this->all_option_names;
	}

	/**
	 * Assign each option name to at most one plugin (longest matcher wins).
	 *
	 * @param array<string,array{matchers:array{exact:string[],prefixes:string[]}}> $candidates Plugin candidates keyed by file.
	 * @return array<string,string[]> option names per plugin file.
	 */
	private function assign_options_exclusively( array $candidates ): array {
		$claims = array(); // option_name => [file, score]

		foreach ( $candidates as $file => $row ) {
			$exact    = $row['matchers']['exact'] ?? array();
			$prefixes = $row['matchers']['prefixes'] ?? array();

			$exact_map = array_fill_keys( $exact, true );

			foreach ( $this->get_all_option_names() as $option_name ) {
				$score = $this->match_score( $option_name, $exact_map, $prefixes );
				if ( $score <= 0 ) {
					continue;
				}
				if ( ! isset( $claims[ $option_name ] ) || $score > $claims[ $option_name ]['score'] ) {
					$claims[ $option_name ] = array(
						'file'  => $file,
						'score' => $score,
					);
				}
			}
		}

		$assigned = array();
		foreach ( $claims as $option_name => $claim ) {
			$file = $claim['file'];
			if ( ! isset( $assigned[ $file ] ) ) {
				$assigned[ $file ] = array();
			}
			$assigned[ $file ][] = $option_name;
		}

		foreach ( $assigned as $file => $names ) {
			sort( $names );
			$assigned[ $file ] = $names;
		}

		return $assigned;
	}

	/**
	 * Score how well an option name matches a plugin's matchers.
	 * Higher is better. Exact name > longer prefix.
	 *
	 * @param string         $option_name Option name.
	 * @param array<string,bool> $exact_map Exact names.
	 * @param string[]       $prefixes    Prefix cores.
	 * @return int
	 */
	private function match_score( string $option_name, array $exact_map, array $prefixes ): int {
		$option_name = strtolower( $option_name );

		if ( isset( $exact_map[ $option_name ] ) ) {
			return 1000 + strlen( $option_name );
		}

		$best = 0;
		foreach ( $prefixes as $prefix ) {
			$prefix = strtolower( rtrim( $prefix, '_-' ) );
			if ( '' === $prefix ) {
				continue;
			}

			// Exact equal to prefix.
			if ( $option_name === $prefix ) {
				$best = max( $best, 500 + strlen( $prefix ) );
				continue;
			}

			// Must continue with separator — never bare "wc%" / "tso%".
			$plen = strlen( $prefix );
			if ( strlen( $option_name ) <= $plen ) {
				continue;
			}
			if ( 0 !== strpos( $option_name, $prefix ) ) {
				continue;
			}
			$next = $option_name[ $plen ];
			if ( '_' !== $next && '-' !== $next ) {
				continue;
			}
			$best = max( $best, 100 + $plen );
		}

		return $best;
	}

	/**
	 * @param array{exact?:string[],prefixes?:string[]} $matchers Matchers.
	 * @return string[]
	 */
	private function display_prefixes( array $matchers ): array {
		$list = array_merge( $matchers['prefixes'] ?? array(), array_slice( $matchers['exact'] ?? array(), 0, 5 ) );
		$list = array_values( array_unique( $list ) );
		usort(
			$list,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return $list;
	}

	/**
	 * Best search string for Options Editor.
	 *
	 * @param array{exact?:string[],prefixes?:string[]} $matchers Matchers.
	 * @param string[]                                  $samples  Assigned sample keys.
	 * @return string
	 */
	private function best_search_prefix( array $matchers, array $samples = array() ): string {
		if ( ! empty( $samples ) ) {
			// Common stem of assigned keys.
			$first = (string) $samples[0];
			if ( preg_match( '/^([a-z0-9]+[_-])/i', $first, $m ) ) {
				return rtrim( $m[1], '_-' );
			}
			return $first;
		}
		$prefixes = $matchers['prefixes'] ?? array();
		if ( ! empty( $prefixes ) ) {
			return (string) $prefixes[0];
		}
		$exact = $matchers['exact'] ?? array();
		return ! empty( $exact ) ? (string) $exact[0] : '';
	}
}
