<?php
/**
 * TSO Swiss Knife – Module: Plugin Footprint.
 *
 * Shows each plugin as a card with its option count.
 * Removed noisy Cron/Shortcodes/REST columns.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TSOSK_Mod_Footprint {

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function render(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$active  = array_fill_keys( (array) get_option( 'active_plugins', array() ), true );

		$active_rows   = array();
		$inactive_rows = array();

		foreach ( $plugins as $file => $plugin ) {
			$slug    = $this->plugin_slug( $file );
			$prefixes = $this->option_prefixes_for_plugin( $file, $slug, $plugin );
			$options = $this->count_options_by_prefixes( $prefixes );
			$row     = array(
				'name'     => $plugin['Name']        ?? $file,
				'version'  => $plugin['Version']     ?? '',
				'author'   => $plugin['Author']      ?? '',
				'file'     => $file,
				'slug'     => $slug,
				'prefixes' => $prefixes,
				'active'   => isset( $active[ $file ] ),
				'options'  => $options,
			);
			if ( $row['active'] ) {
				$active_rows[] = $row;
			} else {
				$inactive_rows[] = $row;
			}
		}

		// Sort by options descending so heaviest plugins appear first.
		usort( $active_rows,   fn( $a, $b ) => $b['options'] - $a['options'] );
		usort( $inactive_rows, fn( $a, $b ) => $b['options'] - $a['options'] );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Shows how many wp_options rows each plugin has created. This is a quick way to spot plugins that store a lot of data in the database. Matching is heuristic based on plugin slug.', 'tso-swiss-knife' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=options-editor' ) ); ?>">
				<?php esc_html_e( 'Open Options Editor', 'tso-swiss-knife' ); ?>
			</a>
		</p>

		<?php /* ── Active plugins ── */ ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Active Plugins', 'tso-swiss-knife' ); ?>
				<span class="tsosk-badge tsosk-badge-ok" style="margin-left:8px;font-size:12px;"><?php echo esc_html( (string) count( $active_rows ) ); ?></span>
			</h3>
			<div class="tsosk-fp-grid">
				<?php foreach ( $active_rows as $row ) : ?>
				<div class="tsosk-fp-card">
					<div class="tsosk-fp-card-name">
						<strong><?php echo esc_html( $row['name'] ); ?></strong>
						<?php if ( $row['version'] ) : ?>
						<span class="tsosk-fp-version">v<?php echo esc_html( $row['version'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tsosk-fp-card-meta">
						<span class="tsosk-fp-options <?php echo $row['options'] > 20 ? 'tsosk-fp-heavy' : ( $row['options'] > 5 ? 'tsosk-fp-medium' : '' ); ?>">
							<?php
							printf(
								/* translators: %d: number of options */
								esc_html__( '%d options', 'tso-swiss-knife' ),
								(int) $row['options']
							);
							?>
						</span>
						<?php if ( $row['options'] > 0 && class_exists( 'TSOSK_Mod_Options_Editor' ) ) : ?>
						<a href="<?php echo esc_url( TSOSK_Mod_Options_Editor::get_admin_url_with_search( $this->best_search_prefix( $row['prefixes'] ) ) ); ?>"
						   class="button button-small tsosk-fp-oe-link">
							<?php esc_html_e( 'View in Options Editor', 'tso-swiss-knife' ); ?>
						</a>
						<?php endif; ?>
						<code class="tsosk-fp-slug"><?php echo esc_html( dirname( $row['file'] ) ); ?></code>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php /* ── Inactive plugins ── */ ?>
		<?php if ( ! empty( $inactive_rows ) ) : ?>
		<div class="tsosk-card">
			<h3>
				<?php esc_html_e( 'Inactive Plugins', 'tso-swiss-knife' ); ?>
				<span class="tsosk-badge" style="margin-left:8px;font-size:12px;"><?php echo esc_html( (string) count( $inactive_rows ) ); ?></span>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Inactive plugins may still leave options in the database. If an inactive plugin has many options you may want to uninstall it properly (Delete in the Plugins list) rather than just deactivating it.', 'tso-swiss-knife' ); ?>
			</p>
			<div class="tsosk-fp-grid">
				<?php foreach ( $inactive_rows as $row ) : ?>
				<div class="tsosk-fp-card tsosk-fp-card-inactive">
					<div class="tsosk-fp-card-name">
						<strong><?php echo esc_html( $row['name'] ); ?></strong>
						<?php if ( $row['version'] ) : ?>
						<span class="tsosk-fp-version">v<?php echo esc_html( $row['version'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tsosk-fp-card-meta">
						<?php if ( $row['options'] > 0 ) : ?>
						<span class="tsosk-fp-options tsosk-fp-warn">
							<?php
							/* translators: %d: number of options remaining */
							printf( esc_html__( '%d options remaining', 'tso-swiss-knife' ), (int) $row['options'] );
							?>
						</span>
						<?php if ( class_exists( 'TSOSK_Mod_Options_Editor' ) ) : ?>
						<a href="<?php echo esc_url( TSOSK_Mod_Options_Editor::get_admin_url_with_search( $this->best_search_prefix( $row['prefixes'] ) ) ); ?>"
						   class="button button-small tsosk-fp-oe-link">
							<?php esc_html_e( 'View in Options Editor', 'tso-swiss-knife' ); ?>
						</a>
						<?php endif; ?>
						<?php else : ?>
						<span class="tsosk-fp-options"><?php esc_html_e( '0 options', 'tso-swiss-knife' ); ?></span>
						<?php endif; ?>
						<code class="tsosk-fp-slug"><?php echo esc_html( dirname( $row['file'] ) ); ?></code>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php
	}

	private function plugin_slug( string $file ): string {
		$parts = explode( '/', $file );
		$slug  = count( $parts ) > 1 ? $parts[0] : preg_replace( '/\.php$/', '', $file );
		return sanitize_key( str_replace( '-', '_', (string) $slug ) );
	}

	/**
	 * Build option-name prefixes for a plugin (folder slug, text domain, source scan).
	 *
	 * @param string               $file   Plugin basename path.
	 * @param string               $slug   Sanitized slug.
	 * @param array<string,string> $plugin Plugin header data.
	 * @return string[]
	 */
	private function option_prefixes_for_plugin( string $file, string $slug, array $plugin = array() ): array {
		$prefixes = array();
		$parts    = explode( '/', $file );
		if ( count( $parts ) > 1 && '.' !== $parts[0] ) {
			$folder     = $parts[0];
			$folder_key = sanitize_key( $folder );
			$prefixes[] = $folder_key;
			$prefixes[] = str_replace( '-', '_', $folder_key );
		}
		$prefixes[] = $slug;
		$prefixes[] = str_replace( '_', '-', $slug );

		if ( ! empty( $plugin['TextDomain'] ) ) {
			$td = sanitize_key( (string) $plugin['TextDomain'] );
			$prefixes[] = $td;
			$prefixes[] = str_replace( '-', '_', $td );
		}

		$base = preg_replace( '/\.php$/', '', basename( $file ) );
		if ( is_string( $base ) && strlen( $base ) > 2 && 'index' !== $base ) {
			$prefixes[] = sanitize_key( $base );
		}

		$prefixes = array_merge( $prefixes, $this->known_prefixes_for_folder( $parts[0] ?? $slug ) );
		$prefixes = array_merge( $prefixes, $this->scan_plugin_option_prefixes( $file ) );

		return array_values( array_unique( array_filter( $prefixes ) ) );
	}

	/**
	 * Known option prefixes for popular plugins whose folder slug differs from DB keys.
	 *
	 * @param string $folder Plugin directory name.
	 * @return string[]
	 */
	private function known_prefixes_for_folder( string $folder ): array {
		$map = array(
			'better-wp-security'         => array( 'itsec', 'ithemes', 'itsec_' ),
			'wordpress-seo'              => array( 'wpseo', 'yoast', 'wpseo_' ),
			'seo-by-rank-math'           => array( 'rank_math', 'rank_math_' ),
			'kadence-blocks'             => array( 'kadence', 'kb', 'stellarwp' ),
			'woocommerce'                => array( 'woocommerce', 'wc', 'wc_' ),
			'elementor'                  => array( 'elementor', '_elementor' ),
			'advanced-custom-fields'     => array( 'acf', 'acf_' ),
			'advanced-custom-fields-pro' => array( 'acf', 'acf_' ),
			'wordfence'                  => array( 'wordfence', 'wfls', 'wf' ),
			'wp-mail-smtp'               => array( 'wp_mail_smtp', 'wpms' ),
			'updraftplus'                => array( 'updraft', 'updraftplus' ),
			'redirection'                => array( 'redirection', 'redirection_' ),
			'jetpack'                    => array( 'jetpack', 'jetpack_' ),
			'gravityforms'               => array( 'gravityforms', 'gf', 'rgform' ),
			'wpforms-lite'               => array( 'wpforms', 'wpforms_' ),
			'wpforms'                    => array( 'wpforms', 'wpforms_' ),
			'litespeed-cache'            => array( 'litespeed', 'litespeed_' ),
			'wp-rocket'                  => array( 'wp_rocket', 'rocket' ),
			'sg-cachepress'              => array( 'sg', 'siteground' ),
			'fluentform'                 => array( 'fluentform', '_fluentform' ),
			'fluent-crm'                 => array( 'fluentcrm', '_fluentcrm' ),
		);

		return $map[ $folder ] ?? array();
	}

	/**
	 * Scan plugin PHP files for option key prefixes used in get/update_option calls.
	 *
	 * @param string $file Plugin basename path.
	 * @return string[]
	 */
	private function scan_plugin_option_prefixes( string $file ): array {
		$prefixes = array();
		$paths    = array( WP_PLUGIN_DIR . '/' . $file );
		$dir      = dirname( WP_PLUGIN_DIR . '/' . $file );
		if ( is_dir( $dir ) ) {
			$main = basename( $file );
			foreach ( glob( $dir . '/*.php' ) ?: array() as $php_file ) {
				if ( basename( $php_file ) !== $main ) {
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

		$seen_opts = array();
		foreach ( array_slice( array_unique( $paths ), 0, 20 ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = (string) file_get_contents( $path, false, null, 0, 262144 );
			if ( preg_match_all( "/(?:get_option|update_option|add_option|delete_option)\s*\(\s*['\"]([a-zA-Z0-9_\-]{2,})['\"]/", $content, $matches ) ) {
				foreach ( $matches[1] as $option_name ) {
					if ( isset( $seen_opts[ $option_name ] ) ) {
						continue;
					}
					$seen_opts[ $option_name ] = true;
					$prefixes[] = strtolower( $option_name );
					if ( preg_match( '/^([a-z][a-z0-9_]*)_/i', $option_name, $pm ) ) {
						$prefixes[] = strtolower( $pm[1] );
					}
				}
			}
			if ( preg_match( '/\btsosk_/i', $content ) ) {
				$prefixes[] = 'tsosk';
			}
			if ( preg_match( '/\btso_/i', $content ) ) {
				$prefixes[] = 'tso';
			}
		}

		return $prefixes;
	}

	/**
	 * Count distinct wp_options rows matching any prefix.
	 *
	 * @param string[] $prefixes Option name prefixes.
	 * @return int
	 */
	private function count_options_by_prefixes( array $prefixes ): int {
		global $wpdb;
		$conditions = array();
		$values     = array();
		foreach ( $prefixes as $prefix ) {
			$prefix = sanitize_key( str_replace( '-', '_', $prefix ) );
			if ( strlen( $prefix ) < 2 ) {
				continue;
			}
			$conditions[] = 'option_name = %s';
			$values[]     = $prefix;
			foreach ( array( '_', '-' ) as $sep ) {
				$like         = $wpdb->esc_like( $prefix ) . $sep . '%';
				$conditions[] = 'option_name LIKE %s';
				$values[]     = $like;
			}
			$like         = $wpdb->esc_like( $prefix ) . '%';
			$conditions[] = 'option_name LIKE %s';
			$values[]     = $like;
		}
		if ( empty( $conditions ) ) {
			return 0;
		}
		$sql = 'SELECT COUNT(DISTINCT option_name) FROM ' . $wpdb->options . ' WHERE ' . implode( ' OR ', $conditions );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static LIKE clauses; values bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );
	}

	/**
	 * Best prefix for Options Editor search link.
	 *
	 * @param string[] $prefixes Detected prefixes.
	 * @return string
	 */
	private function best_search_prefix( array $prefixes ): string {
		if ( empty( $prefixes ) ) {
			return '';
		}

		$best_prefix = '';
		$best_count  = 0;

		foreach ( array_unique( $prefixes ) as $prefix ) {
			$prefix = sanitize_key( str_replace( '-', '_', (string) $prefix ) );
			if ( strlen( $prefix ) < 2 ) {
				continue;
			}
			$count = $this->count_options_by_prefixes( array( $prefix ) );
			if ( $count > $best_count ) {
				$best_count  = $count;
				$best_prefix = $prefix;
			}
		}

		if ( '' === $best_prefix ) {
			usort(
				$prefixes,
				static function ( string $a, string $b ): int {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			$best_prefix = sanitize_key( str_replace( '-', '_', (string) $prefixes[0] ) );
		}

		return $best_prefix;
	}
}
