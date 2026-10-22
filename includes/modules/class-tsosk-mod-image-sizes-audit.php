<?php
/**
 * TSO Swiss Knife – Module: Image Sizes Audit.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Image_Sizes_Audit
 */
class TSOSK_Mod_Image_Sizes_Audit {

	/** Option key for disabled image size names. */
	public const OPTION_DISABLED_SIZES = 'tsosk_disabled_image_sizes';

	/** Option key: size names seen registered on front-end requests (they may not exist in wp-admin). */
	public const OPTION_SEEN_SIZES = 'tsosk_seen_image_sizes';

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Image_Sizes_Audit|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return TSOSK_Mod_Image_Sizes_Audit
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
		add_action( 'wp_ajax_tsosk_image_sizes_audit_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_save', array( $this, 'ajax_save_disabled' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_adopt', array( $this, 'ajax_adopt_files' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine', array( $this, 'ajax_quarantine_files' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine_restore', array( $this, 'ajax_quarantine_restore' ) );
		add_action( 'wp_ajax_tsosk_image_sizes_audit_quarantine_purge', array( $this, 'ajax_quarantine_purge' ) );
	}

	/**
	 * Register runtime filter for disabled sizes.
	 */
	public function init(): void {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_disabled_image_sizes' ), 99 );
		add_action( 'wp_loaded', array( $this, 'remember_registered_sizes' ), 99 );

		// Any change to the media library (from any plugin or screen) makes the cached audit stale.
		add_action( 'add_attachment', array( $this, 'invalidate_cached_audit' ) );
		add_action( 'delete_attachment', array( $this, 'invalidate_cached_audit' ) );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'invalidate_cached_audit_on_metadata' ), 99 );
	}

	/**
	 * Drop the cached image sizes audit so the next visit does not show outdated counts.
	 *
	 * Runs at most once per request, even during bulk uploads, deletions or thumbnail regeneration.
	 */
	public function invalidate_cached_audit(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
	}

	/**
	 * Filter callback: invalidate the cached audit when attachment metadata changes.
	 *
	 * @param mixed $data Attachment metadata (returned unchanged).
	 * @return mixed
	 */
	public function invalidate_cached_audit_on_metadata( $data ) {
		$this->invalidate_cached_audit();
		return $data;
	}

	/**
	 * Remember sizes registered on front-end requests. Some themes and plugins only register a size
	 * outside wp-admin, so "not registered" seen from the admin alone does not mean "unused".
	 */
	public function remember_registered_sizes(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return;
		}
		$names = array_keys( wp_get_registered_image_subsizes() );
		$seen  = $this->get_seen_sizes();
		if ( array_diff( $names, $seen ) ) {
			update_option( self::OPTION_SEEN_SIZES, array_slice( array_values( array_unique( array_merge( $seen, $names ) ) ), 0, 200 ), false );
		}
	}

	/**
	 * Get seen sizes.
	 *
	 * @return string[]
	 */
	private function get_seen_sizes(): array {
		$stored = get_option( self::OPTION_SEEN_SIZES, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'sanitize_key', $stored ) ) ) : array();
	}

	/**
	 * Content that still links to files of the given sizes (post content and post meta such as page-builder data).
	 *
	 * @param string[]                  $sizes     Size names.
	 * @param array<string, array>|null $size_meta Width/height per size name from a fresh audit; when null the cached audit is used.
	 * @return array<string, array{posts:int, meta:int, options:int}> Only sizes with at least one reference.
	 */
	private function count_content_references( array $sizes, ?array $size_meta = null ): array {
		global $wpdb;

		$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		if ( null === $size_meta ) {
			$audit     = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
			$size_meta = is_array( $audit ) && is_array( $audit['size_meta'] ?? null ) ? $audit['size_meta'] : array();
		}

		$out = array();
		foreach ( $sizes as $name ) {
			$width  = (int) ( $registered[ $name ]['width'] ?? ( $size_meta[ $name ]['width'] ?? 0 ) );
			$height = (int) ( $registered[ $name ]['height'] ?? ( $size_meta[ $name ]['height'] ?? 0 ) );
			if ( $width <= 0 || $height <= 0 ) {
				continue;
			}
			$like = '%' . $wpdb->esc_like( '-' . $width . 'x' . $height . '.' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft' AND post_content LIKE %s",
					$like
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(meta_id) FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE %s AND meta_value LIKE %s",
					$wpdb->esc_like( '_wp_attach' ) . '%',
					$like
				)
			);
			// Theme mods, widgets and builder settings live in wp_options. Transients and this plugin's own records
			// (which store file paths) are not references.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$options = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(option_id) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( 'tsosk_' ) . '%',
					$like
				)
			);
			if ( $posts + $meta + $options > 0 ) {
				$post_items   = array();
				$meta_items   = array();
				$option_items = array();
				$list_limit   = 10;

				if ( $posts > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft' AND post_content LIKE %s ORDER BY ID DESC LIMIT %d",
							$like,
							$list_limit
						),
						ARRAY_A
					);
					foreach ( is_array( $rows ) ? $rows : array() as $row ) {
						$post_items[] = array(
							'id'    => (int) $row['ID'],
							'title' => (string) $row['post_title'],
							'type'  => (string) $row['post_type'],
						);
					}
				}
				if ( $meta > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT pm.post_id, pm.meta_key, p.post_title FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key NOT LIKE %s AND pm.meta_value LIKE %s ORDER BY pm.meta_id DESC LIMIT %d",
							$wpdb->esc_like( '_wp_attach' ) . '%',
							$like,
							$list_limit
						),
						ARRAY_A
					);
					foreach ( is_array( $rows ) ? $rows : array() as $row ) {
						$meta_items[] = array(
							'id'    => (int) $row['post_id'],
							'title' => (string) ( $row['post_title'] ?? '' ),
							'key'   => (string) $row['meta_key'],
						);
					}
				}
				if ( $options > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$names        = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s ORDER BY option_id DESC LIMIT %d",
							$wpdb->esc_like( '_transient_' ) . '%',
							$wpdb->esc_like( '_site_transient_' ) . '%',
							$wpdb->esc_like( 'tsosk_' ) . '%',
							$like,
							$list_limit
						)
					);
					$option_items = array_map( 'strval', is_array( $names ) ? $names : array() );
				}

				$out[ $name ] = array(
					'posts'        => $posts,
					'meta'         => $meta,
					'options'      => $options,
					'post_items'   => $post_items,
					'meta_items'   => $meta_items,
					'option_items' => $option_items,
				);
			}
		}
		return $out;
	}

	/**
	 * Active code that mentions a size by name (theme, parent theme, active plugins), e.g. in
	 * the_post_thumbnail( 'name' ) or wp_get_attachment_image( $id, 'name' ).
	 *
	 * Only literal quoted names are found: sizes built dynamically ('prefix-' . $x), page-builder
	 * settings, mu-plugins and non-PHP files are not seen. This plugin itself is skipped.
	 *
	 * @param string[] $names Size names to look for.
	 * @return array{hits:array<string, string[]>, locations:array<string, array>, weak:array<string, array>, incomplete:bool} Labels of the theme/plugins that request each size as an image size, where, and the unconfirmed quoted mentions.
	 */
	private function detect_code_usage( array $names ): array {
		$out   = array(
			'hits'       => array(),
			'locations'  => array(),
			'weak'       => array(),
			'incomplete' => false,
		);
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ), 'strlen' ) ) );
		if ( empty( $names ) ) {
			return $out;
		}

		// A quoted name only counts as "requested" when it is used where WordPress expects an image size:
		// as an argument of the image functions, or as the value of a size key. Any other quoted
		// occurrence (an array key such as 'key' => 'featured') is only reported as an unconfirmed mention.
		$image_funcs = 'the_post_thumbnail|get_the_post_thumbnail|get_the_post_thumbnail_url|wp_get_attachment_image|wp_get_attachment_image_src|wp_get_attachment_image_url|wp_get_attachment_image_srcset|wp_get_attachment_image_sizes|wp_get_attachment_thumb_url|image_downsize|image_get_intermediate_size|add_image_size|has_image_size|remove_image_size|set_post_thumbnail_size';
		$strong_res  = array();
		foreach ( $names as $size_name ) {
			$quoted                   = preg_quote( $size_name, '/' );
			$strong_res[ $size_name ] = '/(?:' . $image_funcs . ')\s*\([^;]{0,300}?[\'"]' . $quoted . '[\'"]|[\'"](?:size|image_size|thumbnail_size|thumb_size|img_size)[\'"]\s*=>\s*[\'"]' . $quoted . '[\'"]/is';
		}

		// Directory (or single file) => label shown to the admin.
		$roots = array();
		$roots[ wp_normalize_path( get_stylesheet_directory() ) ] = wp_get_theme()->get( 'Name' );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$parent = wp_get_theme()->parent();
			$roots[ wp_normalize_path( get_template_directory() ) ] = $parent ? $parent->get( 'Name' ) : basename( get_template_directory() );
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$plugins_dir = tsosk_get_plugins_dir();
		$own_path    = wp_normalize_path( untrailingslashit( TSOSK_PATH ) );
		foreach ( array_unique( $active ) as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			$folder      = dirname( $plugin_file );
			$root        = '.' === $folder
				? wp_normalize_path( $plugins_dir . $plugin_file )
				: wp_normalize_path( untrailingslashit( $plugins_dir . $folder ) );
			if ( $root === $own_path || ! file_exists( $root ) ) {
				continue;
			}
			$roots[ $root ] = '.' === $folder ? $plugin_file : $folder;
		}

		$max_files = 4000;
		$max_bytes = 1048576;
		$deadline  = microtime( true ) + 8.0;
		$scanned   = 0;
		$skip_dirs = array( 'node_modules', 'vendor', '.git' );

		foreach ( $roots as $root => $label ) {
			$files = array();
			if ( is_file( $root ) ) {
				$files[] = $root;
			} elseif ( is_dir( $root ) ) {
				try {
					$iterator = new RecursiveIteratorIterator(
						new RecursiveCallbackFilterIterator(
							new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
							static function ( SplFileInfo $current ) use ( $skip_dirs ): bool {
								if ( $current->isLink() ) {
									return false;
								}
								return ! $current->isDir() || ! in_array( $current->getFilename(), $skip_dirs, true );
							}
						)
					);
					foreach ( $iterator as $file ) {
						if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
							$files[] = wp_normalize_path( $file->getPathname() );
						}
					}
				} catch ( Exception $e ) {
					$out['incomplete'] = true;
					continue;
				}
			}

			foreach ( $files as $path ) {
				if ( $scanned >= $max_files || microtime( true ) > $deadline ) {
					$out['incomplete'] = true;
					break 2;
				}
				++$scanned;
				$size = (int) filesize( $path );
				if ( $size <= 0 || $size > $max_bytes ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme/plugin source scan.
				$src = (string) file_get_contents( $path );
				foreach ( $names as $name ) {
					if ( false === strpos( $src, $name ) ) {
						continue;
					}
					$token = false !== strpos( $src, "'" . $name . "'" ) ? "'" . $name . "'" : '"' . $name . '"';
					if ( false === strpos( $src, $token ) ) {
						continue;
					}
					$strong   = 1 === preg_match( $strong_res[ $name ], $src, $match, PREG_OFFSET_CAPTURE );
					$position = $strong ? (int) $match[0][1] : (int) strpos( $src, $token );
					$location = array(
						'label' => $label,
						'file'  => ltrim( str_replace( wp_normalize_path( ABSPATH ), '', $path ), '/' ),
						'line'  => substr_count( substr( $src, 0, $position ), "\n" ) + 1,
					);
					if ( $strong ) {
						if ( ! isset( $out['hits'][ $name ] ) || ! in_array( $label, $out['hits'][ $name ], true ) ) {
							$out['hits'][ $name ][] = $label;
						}
						if ( count( $out['locations'][ $name ] ?? array() ) < 12 ) {
							$out['locations'][ $name ][] = $location;
						}
					} elseif ( count( $out['weak'][ $name ] ?? array() ) < 12 ) {
						$out['weak'][ $name ][] = $location;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Find the currently registered size that most likely replaced an old one after a theme renamed its
	 * sizes (tso-card-thumb → tsothm-card-thumb). A replacement is a registered size with identical dimensions
	 * (preferring one whose name matches the old name without its first word); a similarly named size with
	 * other dimensions is only reported as "different" when nothing has the same dimensions.
	 *
	 * @param string               $name       Old size name.
	 * @param int                  $width      Old width.
	 * @param int                  $height     Old height.
	 * @param array<string, array> $registered Currently registered sizes.
	 * @return array{type:string, target:string, width:int, height:int}|array{} Empty when nothing similar is registered.
	 */
	private function detect_renamed_size( string $name, int $width, int $height, array $registered ): array {
		$has_prefix = (bool) preg_match( '/^[a-z0-9]+[-_](.{4,})$/', $name, $m );
		$tail       = $has_prefix ? str_replace( '_', '-', $m[1] ) : '';

		// Identical dimensions are the strongest signal; a similar name only breaks ties.
		$same_dims = '';
		$tail_dims = '';
		if ( $width > 0 && $height > 0 ) {
			foreach ( $registered as $reg_name => $reg ) {
				$reg_name = (string) $reg_name;
				if ( $reg_name === $name || (int) ( $reg['width'] ?? 0 ) !== $width || (int) ( $reg['height'] ?? 0 ) !== $height ) {
					continue;
				}
				if ( '' === $same_dims ) {
					$same_dims = $reg_name;
				}
				$norm = str_replace( '_', '-', $reg_name );
				if ( $has_prefix && ( $norm === $tail || substr( $norm, -( strlen( $tail ) + 1 ) ) === '-' . $tail ) ) {
					$tail_dims = $reg_name;
					break;
				}
			}
		}
		$target = '' !== $tail_dims ? $tail_dims : $same_dims;
		if ( '' !== $target ) {
			return array(
				'type'   => 'renamed',
				'target' => $target,
				'width'  => $width,
				'height' => $height,
			);
		}

		// No size with the same dimensions: report a similarly named one that differs, if any.
		if ( ! $has_prefix ) {
			return array();
		}
		foreach ( $registered as $reg_name => $reg ) {
			$reg_name = (string) $reg_name;
			$norm     = str_replace( '_', '-', $reg_name );
			if ( $reg_name === $name || ( $norm !== $tail && substr( $norm, -( strlen( $tail ) + 1 ) ) !== '-' . $tail ) ) {
				continue;
			}
			return array(
				'type'   => 'different',
				'target' => $reg_name,
				'width'  => (int) ( $reg['width'] ?? 0 ),
				'height' => (int) ( $reg['height'] ?? 0 ),
			);
		}
		return array();
	}

	/**
	 * Usage signals for every non-core size that has files: content references, code that requests the
	 * size by name (only for sizes no longer registered), and a probable renamed replacement.
	 *
	 * A signal means "detected"; no signal means "no references detected", never "unused".
	 *
	 * @param array<string, mixed> $audit Fresh audit data from the scanner.
	 * @return array{sizes:array<string, array>, code_incomplete:bool, at:int}
	 */
	private function detect_usage( array $audit ): array {
		$registered = is_array( $audit['registered'] ?? null ) ? $audit['registered'] : array();
		$size_meta  = is_array( $audit['size_meta'] ?? null ) ? $audit['size_meta'] : array();
		$by_size    = is_array( $audit['by_size'] ?? null ) ? $audit['by_size'] : array();
		$core_sizes = array( 'thumbnail', 'medium', 'large' );

		$names  = array();
		$legacy = array();
		foreach ( $by_size as $name => $stats ) {
			$name = (string) $name;
			if ( in_array( $name, $core_sizes, true ) || (int) ( $stats['files'] ?? 0 ) <= 0 ) {
				continue;
			}
			$names[] = $name;
			if ( ! isset( $registered[ $name ] ) ) {
				$legacy[] = $name;
			}
		}

		$content = $this->count_content_references( $names, $size_meta );
		$code    = $this->detect_code_usage( $legacy );

		$sizes = array();
		foreach ( $names as $name ) {
			$renamed = array();
			if ( in_array( $name, $legacy, true ) ) {
				$renamed = $this->detect_renamed_size(
					$name,
					(int) ( $size_meta[ $name ]['width'] ?? 0 ),
					(int) ( $size_meta[ $name ]['height'] ?? 0 ),
					$registered
				);
			}
			$sizes[ $name ] = array(
				'content'        => $content[ $name ] ?? array(),
				'code'           => $code['hits'][ $name ] ?? array(),
				'code_locations' => $code['locations'][ $name ] ?? array(),
				'code_weak'      => $code['weak'][ $name ] ?? array(),
				'renamed'        => $renamed,
			);
		}

		return array(
			'sizes'           => $sizes,
			'code_incomplete' => (bool) $code['incomplete'],
			'at'              => time(),
		);
	}

	/**
	 * Run the scanner and attach the usage signals. Shared by every place that refreshes the audit.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function run_audit() {
		$result = TSOSK_Uploads_Scanner::scan_image_sizes();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['usage'] = $this->detect_usage( $result );
		return $result;
	}

	/**
	 * Small "i" button that opens a popover (handled by tsosk-admin.js). Keeps long explanations and
	 * lists out of the table cells.
	 *
	 * Each entry is either a plain-text paragraph, or a list: array{title:string, items:array<int, array{0:string, 1?:string}>, more?:int}
	 * where every item is [label, optional URL].
	 *
	 * @param array<int, string|array<string, mixed>> $entries Popover content (escaped here).
	 * @param bool                                    $warn    Use the warning colour.
	 * @return string Escaped HTML.
	 */
	private function render_info_popover( array $entries, bool $warn = false ): string {
		$body = '';
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$items = is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
				if ( empty( $items ) ) {
					continue;
				}
				if ( ! empty( $entry['title'] ) ) {
					$body .= '<p><strong>' . esc_html( (string) $entry['title'] ) . '</strong></p>';
				}
				$body .= '<ul>';
				foreach ( $items as $item ) {
					$label = (string) ( $item[0] ?? '' );
					$url   = (string) ( $item[1] ?? '' );
					if ( '' === $label ) {
						continue;
					}
					$body .= '<li>' . ( '' !== $url
						? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'
						: esc_html( $label ) ) . '</li>';
				}
				$more = (int) ( $entry['more'] ?? 0 );
				if ( $more > 0 ) {
					$body .= '<li>' . esc_html(
						sprintf(
							/* translators: %d: number of additional entries not listed */
							__( '… and %d more', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							$more
						)
					) . '</li>';
				}
				$body .= '</ul>';
				continue;
			}
			$entry = (string) $entry;
			if ( '' !== $entry ) {
				$body .= '<p>' . esc_html( $entry ) . '</p>';
			}
		}
		if ( '' === $body ) {
			return '';
		}
		$html  = '<span class="tsosk-info">';
		$html .= '<button type="button" class="tsosk-info-btn' . ( $warn ? ' tsosk-info-btn-warn' : '' ) . '" aria-expanded="false" aria-label="' . esc_attr__( 'More information', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '">';
		$html .= '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span></button>';
		$html .= '<span class="tsosk-info-pop" role="tooltip" hidden>' . $body . '</span></span>';
		return $html;
	}

	/**
	 * Sizes WordPress creates by itself (the site icon crops) and that no theme registers on the front end.
	 * They must never be offered for quarantine or turned off.
	 *
	 * @param string $name Size name.
	 * @return bool
	 */
	private static function is_protected_size( string $name ): bool {
		return 0 === strpos( $name, 'site_icon' );
	}

	/**
	 * For each given size, find an active (not turned off) registered size with identical dimensions that
	 * has fewer files. Moving the old files would then remove the only copy of that crop until thumbnails
	 * are regenerated (typical after a theme renames its sizes).
	 *
	 * @param string[]             $names      Size names to check.
	 * @param array<string, array> $registered Registered sizes.
	 * @param array<string, array> $size_meta  Width/height per size name from an audit.
	 * @param array<string, array> $by_size    File counts per size name.
	 * @param string[]             $disabled   Sizes turned off by the admin.
	 * @return array<string, array{target:string, old:int, new:int}>
	 */
	private function find_equivalent_sizes( array $names, array $registered, array $size_meta, array $by_size, array $disabled ): array {
		$out = array();
		foreach ( $names as $name ) {
			$meta   = $registered[ $name ] ?? ( $size_meta[ $name ] ?? array() );
			$width  = (int) ( $meta['width'] ?? 0 );
			$height = (int) ( $meta['height'] ?? 0 );
			if ( $width <= 0 || $height <= 0 ) {
				continue;
			}
			$old = (int) ( $by_size[ $name ]['files'] ?? 0 );
			foreach ( $registered as $other => $reg ) {
				$other = (string) $other;
				if ( $other === $name || in_array( $other, $disabled, true ) ) {
					continue;
				}
				if ( (int) ( $reg['width'] ?? 0 ) !== $width || (int) ( $reg['height'] ?? 0 ) !== $height ) {
					continue;
				}
				$new = (int) ( $by_size[ $other ]['files'] ?? 0 );
				if ( $new < $old ) {
					$out[ $name ] = array(
						'target' => $other,
						'old'    => $old,
						'new'    => $new,
					);
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Prevent WordPress from generating disabled intermediate sizes on new uploads.
	 *
	 * @param array<string, array> $sizes Registered sizes.
	 * @return array<string, array>
	 */
	public function filter_disabled_image_sizes( array $sizes ): array {
		$disabled = $this->get_disabled_sizes();
		foreach ( $disabled as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}

	/**
	 * Get disabled sizes.
	 *
	 * @return string[]
	 */
	private function get_disabled_sizes(): array {
		$stored = get_option( self::OPTION_DISABLED_SIZES, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'sanitize_key', $stored ) ) ) : array();
	}

	/**
	 * AJAX: run image sizes audit scan.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = $this->run_audit();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		set_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES, $result, TSOSK_Uploads_Scanner::SIZES_AUDIT_TTL );

		wp_send_json_success(
			array(
				'message' => __( 'Image sizes audit completed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $this->render_audit_html( $result, $this->get_disabled_sizes() ),
			)
		);
	}

	/**
	 * AJAX: save disabled image sizes for new uploads.
	 */
	public function ajax_save_disabled(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$disabled = array();
		if ( isset( $_POST['disabled'] ) && is_array( $_POST['disabled'] ) ) {
			foreach ( wp_unslash( $_POST['disabled'] ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_scalar( $name ) ) {
					continue;
				}
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$disabled[] = $name;
				}
			}
		}

		// The UI disables the checkbox for these via the "disabled" HTML
		// attribute only — that's a client-side convenience, not a security
		// boundary. Re-validate server-side so a crafted request can't disable
		// generation of the thumbnail sizes WordPress core and most themes
		// depend on being present.
		$core_sizes = array( 'thumbnail', 'medium', 'large' ); // medium_large, 1536x1536 and 2048x2048 are core too, but may be turned off.
		$disabled   = array_values( array_diff( array_unique( $disabled ), $core_sizes ) );
		$disabled   = array_values( array_filter( $disabled, static fn( string $n ): bool => ! self::is_protected_size( $n ) ) );
		update_option( self::OPTION_DISABLED_SIZES, $disabled, false );

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'save',
			__( 'Image size generation settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		// Re-render the results so the "Free up space" list reflects the sizes just turned off,
		// without having to run the audit again.
		$nonce    = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$disabled = $this->get_disabled_sizes();
		$audit    = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		if ( is_array( $audit ) ) {
			$html = $this->render_audit_html( $audit, $disabled );
		} else {
			ob_start();
			$this->render_sizes_table_without_audit( $disabled, $nonce );
			$html = (string) ob_get_clean();
		}

		wp_send_json_success(
			array(
				'message' => __( 'Image size settings saved. New uploads will skip disabled sizes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'html'    => $html,
			)
		);
	}

	/**
	 * AJAX: let a registered size reuse the existing files of an old size with identical dimensions.
	 * Metadata only — no file is touched (see TSOSK_Uploads_Scanner::adopt_size_files()).
	 */
	public function ajax_adopt_files(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$from = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';

		$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$cached     = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		$size_meta  = is_array( $cached ) && is_array( $cached['size_meta'] ?? null ) ? $cached['size_meta'] : array();

		// Server-side checks: the target must be an active registered size, and both must share their dimensions.
		if ( '' === $from || '' === $to || $from === $to || ! isset( $registered[ $to ] ) || in_array( $to, $this->get_disabled_sizes(), true ) ) {
			wp_send_json_error( __( 'The destination size is not an active registered size.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$width  = (int) ( $registered[ $to ]['width'] ?? 0 );
		$height = (int) ( $registered[ $to ]['height'] ?? 0 );
		if ( empty( $size_meta[ $from ] ) ) {
			wp_send_json_error( __( 'Run the image sizes audit first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( (int) $size_meta[ $from ]['width'] !== $width || (int) $size_meta[ $from ]['height'] !== $height ) {
			wp_send_json_error( __( 'The two sizes do not have identical dimensions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = TSOSK_Uploads_Scanner::adopt_size_files( $from, $to, $width, $height );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'adopt',
			sprintf(
				/* translators: 1: destination size, 2: source size, 3: number of images */
				__( 'Size %1$s now reuses the existing files of %2$s (%3$d images).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$to,
				$from,
				(int) $result['adopted']
			)
		);

		// Refresh the cached audit, keeping the usage signals already computed.
		$audit = TSOSK_Uploads_Scanner::scan_image_sizes();
		$html  = '';
		if ( ! is_wp_error( $audit ) ) {
			$audit['usage'] = ( is_array( $cached ) && is_array( $cached['usage'] ?? null ) ) ? $cached['usage'] : $this->detect_usage( $audit );
			set_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES, $audit, TSOSK_Uploads_Scanner::SIZES_AUDIT_TTL );
			$html = $this->render_audit_html( $audit, $this->get_disabled_sizes() );
		} else {
			delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: destination size, 2: number of images, 3: source size */
					__( '%1$s now uses the existing files of %3$s for %2$d image(s). No file was changed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$to,
					(int) $result['adopted'],
					$from
				),
				'html'    => $html,
			)
		);
	}

	/**
	 * AJAX: move existing files for sizes already disabled for new uploads into quarantine.
	 *
	 * Files are not deleted here — they are moved to a quarantine folder so the admin can
	 * verify the site still works and then either restore them or delete them for good.
	 * Eligibility is re-validated server-side: a request can only ever target a currently
	 * registered size the admin has already turned off (the stored disabled-sizes option),
	 * or a size that is not registered by any active theme or plugin at all — a legacy size
	 * left over from an old theme or a deactivated plugin, which is inherently "off" since
	 * nothing generates it for new uploads. An active, still-enabled registered size is
	 * never eligible, regardless of what the client sends.
	 */
	public function ajax_quarantine_files(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$requested = array();
		if ( isset( $_POST['sizes'] ) && is_array( $_POST['sizes'] ) ) {
			foreach ( wp_unslash( $_POST['sizes'] ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_scalar( $name ) ) {
					continue;
				}
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$requested[] = $name;
				}
			}
		}

		$requested        = array_values( array_filter( $requested, static fn( string $n ): bool => ! self::is_protected_size( $n ) && ! in_array( $n, TSOSK_Uploads_Scanner::SETTINGS_SIZES, true ) ) );
		$disabled         = $this->get_disabled_sizes();
		$seen_sizes       = $this->get_seen_sizes();
		$registered_names = function_exists( 'wp_get_registered_image_subsizes' )
			? array_keys( wp_get_registered_image_subsizes() )
			: array();
		$sizes            = array_values(
			array_filter(
				array_unique( $requested ),
				static function ( string $name ) use ( $disabled, $registered_names, $seen_sizes ): bool {
					// A size not registered in wp-admin still counts as "in use" when it was seen registered on the front end.
					return in_array( $name, $disabled, true ) || ( ! in_array( $name, $registered_names, true ) && ! in_array( $name, $seen_sizes, true ) );
				}
			)
		);

		if ( empty( $sizes ) ) {
			wp_send_json_error( __( 'No eligible sizes selected. Only sizes already turned off for new uploads can be quarantined here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Warn before moving files that content still links to, or that active code still asks for by name.
		if ( empty( $_POST['confirm_refs'] ) ) {
			$refs       = $this->count_content_references( $sizes );
			$legacy     = array_values( array_diff( $sizes, $registered_names ) );
			$code       = $this->detect_code_usage( $legacy );
			$ref_lines  = array();
			$code_lines = array();
			foreach ( $refs as $ref_name => $ref ) {
				$ref_lines[] = sprintf(
					/* translators: 1: size name, 2: number of posts/pages, 3: number of post meta values, 4: number of option values */
					__( '%1$s: %2$d post(s)/page(s), %3$d meta value(s), %4$d option(s)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$ref_name,
					(int) $ref['posts'],
					(int) $ref['meta'],
					(int) $ref['options']
				);
			}
			foreach ( $code['hits'] as $code_name => $labels ) {
				$code_lines[] = sprintf(
					/* translators: 1: size name, 2: comma-separated theme/plugin names */
					__( '%1$s: requested by %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$code_name,
					implode( ', ', $labels )
				);
			}
			$equiv_lines = array();
			$cached      = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
			if ( is_array( $cached ) ) {
				$equiv = $this->find_equivalent_sizes(
					$sizes,
					is_array( $cached['registered'] ?? null ) ? $cached['registered'] : array(),
					is_array( $cached['size_meta'] ?? null ) ? $cached['size_meta'] : array(),
					is_array( $cached['by_size'] ?? null ) ? $cached['by_size'] : array(),
					$disabled
				);
				foreach ( $equiv as $equiv_name => $info ) {
					$equiv_lines[] = sprintf(
						/* translators: 1: size to quarantine, 2: active size with the same dimensions, 3: files of the active size, 4: files of the size to quarantine */
						__( '%1$s: same dimensions as the active size %2$s (%3$d file(s) there, %4$d here)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						$equiv_name,
						$info['target'],
						$info['new'],
						$info['old']
					);
				}
			}
			if ( ! empty( $ref_lines ) || ! empty( $code_lines ) || ! empty( $equiv_lines ) ) {
				$message = '';
				if ( ! empty( $equiv_lines ) ) {
					$message .= __( 'An active size with the same dimensions has fewer files. These files are the only copy of that crop until you regenerate thumbnails for the active size:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . "\n\n" . implode( "\n", $equiv_lines ) . "\n\n";
				}
				if ( ! empty( $ref_lines ) ) {
					$message .= __( 'Some content still links directly to files of these sizes. Those images will show as broken until you restore the files:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . "\n\n" . implode( "\n", $ref_lines ) . "\n\n";
				}
				if ( ! empty( $code_lines ) ) {
					$message .= __( 'Active code still asks for these sizes by name. Its thumbnails will break or fall back to another size until you restore the files:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . "\n\n" . implode( "\n", $code_lines ) . "\n\n";
				}
				wp_send_json_success(
					array(
						'needs_confirm' => true,
						'message'       => $message . __( 'Move them to quarantine anyway?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					)
				);
			}
		}

		$result = TSOSK_Uploads_Scanner::quarantine_image_size_files( $sizes );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine',
			sprintf(
				/* translators: 1: comma-separated size names, 2: file count, 3: formatted size */
				__( 'Moved existing files for image size(s) %1$s to quarantine: %2$d files, %3$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes ),
				(int) $result['files'],
				size_format( (int) $result['bytes'], 2 )
			)
		);

		// The counts just changed — refresh the cached audit instead of leaving a stale one.
		// Reuse the usage signals already computed (they do not depend on the files just moved) so this
		// request does not repeat the heavy content/code scan.
		$previous = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		$audit    = TSOSK_Uploads_Scanner::scan_image_sizes();
		$html     = '';
		if ( ! is_wp_error( $audit ) ) {
			$audit['usage'] = ( is_array( $previous ) && is_array( $previous['usage'] ?? null ) ) ? $previous['usage'] : $this->detect_usage( $audit );
			set_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES, $audit, TSOSK_Uploads_Scanner::SIZES_AUDIT_TTL );
			$html = $this->render_audit_html( $audit, $this->get_disabled_sizes() );
		} else {
			delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		}

		wp_send_json_success(
			array(
				'message'         => sprintf(
					/* translators: 1: file count, 2: formatted size */
					__( 'Moved %1$d files (%2$s) to quarantine. They can be restored or permanently deleted below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $result['files'],
					size_format( (int) $result['bytes'], 2 )
				),
				'html'            => $html,
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: restore a quarantined image-size batch to its original files/locations.
	 */
	public function ajax_quarantine_restore(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$sizes   = is_array( $entries[ $entry_id ]['sizes'] ?? null ) ? $entries[ $entry_id ]['sizes'] : array();

		$result = TSOSK_Uploads_Scanner::restore_sizes_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine-restore',
			sprintf(
				/* translators: 1: comma-separated size names, 2: number of files restored */
				__( 'Restored quarantined files for image size(s) %1$s: %2$d files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes ),
				(int) $result['restored']
			)
		);

		delete_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );

		$message = $result['remaining'] > 0
			? sprintf(
				/* translators: 1: number of files restored, 2: number of files still quarantined */
				__( 'Restored %1$d file(s). %2$d file(s) could not be restored (their original spot already has a file) and stay in quarantine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $result['restored'],
				(int) $result['remaining']
			) . ' ' . __( 'Use "Delete now" on this batch to discard what is left.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: sprintf(
				/* translators: %d: number of files restored */
				__( 'Restored %d file(s) to their original location.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $result['restored']
			);

		wp_send_json_success(
			array(
				'message'         => $message,
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * AJAX: permanently delete one quarantined image-size batch now, before its 30-day window ends.
	 */
	public function ajax_quarantine_purge(): void {
		check_ajax_referer( 'tsosk_image_sizes_audit_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
		if ( '' === $entry_id ) {
			wp_send_json_error( __( 'Invalid entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$sizes   = is_array( $entries[ $entry_id ]['sizes'] ?? null ) ? $entries[ $entry_id ]['sizes'] : array();

		$result = TSOSK_Uploads_Scanner::purge_sizes_quarantine_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'image-sizes-audit',
			'quarantine-purge',
			sprintf(
				/* translators: %s: comma-separated size names */
				__( 'Permanently deleted quarantined files for image size(s): %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				implode( ', ', $sizes )
			)
		);

		wp_send_json_success(
			array(
				'message'         => __( 'Quarantined files permanently deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'quarantine_html' => $this->render_sizes_quarantine_html(),
			)
		);
	}

	/**
	 * Render module UI.
	 */
	public function render(): void {
		// Opportunistic sweep so files past their 30-day window are purged even if the
		// cron event was missed (e.g. on a low-traffic site) — cheap when nothing is due.
		TSOSK_Uploads_Scanner::purge_expired_sizes_quarantine();

		$nonce    = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$disabled = $this->get_disabled_sizes();
		$audit    = get_transient( TSOSK_Uploads_Scanner::TRANSIENT_SIZES );
		if ( ! is_array( $audit ) ) {
			$audit = null;
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Audit how much disk space each registered image size uses, estimate recoverable space from thumbnails, and disable sizes you do not need on new uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="tsosk-image-sizes-scan"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Run image sizes audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-image-sizes-scan-msg"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Counts are based on attachment metadata and a disk scan for unmatched derivative files. Disabling a size only affects new uploads — existing files are not deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=media-footprint' ) ); ?>">
				<?php esc_html_e( 'Open Uploads Disk Footprint', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</a>
		</p>

		<div id="tsosk-image-sizes-results">
			<?php
			if ( is_array( $audit ) ) {
				echo $this->render_audit_html( $audit, $disabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$this->render_sizes_table_without_audit( $disabled, $nonce );
			}
			?>
		</div>

		<h3><?php esc_html_e( 'Image sizes quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
		<div id="tsosk-image-sizes-quarantine-results">
			<?php echo $this->render_sizes_quarantine_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
		</div>
		<?php
	}

	/**
	 * Registered sizes table when no audit has run yet.
	 *
	 * @param string[] $disabled Disabled size names.
	 * @param string   $nonce    AJAX nonce.
	 */
	private function render_sizes_table_without_audit( array $disabled, string $nonce ): void {
		$registered = TSOSK_Uploads_Scanner::get_registered_sizes();
		?>
		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'Run the audit to see file counts and disk usage per size.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>
		<?php
		$this->render_sizes_controls( $registered, array(), array(), $disabled, $nonce );
	}

	/**
	 * Render audit html.
	 *
	 * @param array<string, mixed> $audit    Audit data.
	 * @param string[]             $disabled Disabled size names.
	 * @return string
	 */
	private function render_audit_html( array $audit, array $disabled ): string {
		$nonce      = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$registered = is_array( $audit['registered'] ?? null ) ? $audit['registered'] : array();
		$size_meta  = is_array( $audit['size_meta'] ?? null ) ? $audit['size_meta'] : array();
		$by_size    = is_array( $audit['by_size'] ?? null ) ? $audit['by_size'] : array();
		$full       = is_array( $audit['full'] ?? null ) ? $audit['full'] : array();
		$unmatched  = is_array( $audit['unmatched_derivatives'] ?? null ) ? $audit['unmatched_derivatives'] : array();
		$quarantine = TSOSK_Uploads_Scanner::get_sizes_quarantine_summary();

		ob_start();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Audit summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-stats-row tsosk-image-sizes-audit-summary">
				<div class="tsosk-stat-box">
					<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( (int) ( $audit['attachments_scanned'] ?? 0 ) ) ); ?></span>
					<span class="tsosk-stat-label"><?php esc_html_e( 'Attachments with metadata', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<div class="tsosk-stat-box">
					<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( (int) ( $full['files'] ?? 0 ) ) ); ?></span>
					<span class="tsosk-stat-sub"><?php echo esc_html( size_format( (int) ( $full['bytes'] ?? 0 ), 2 ) ); ?></span>
					<span class="tsosk-stat-label"><?php esc_html_e( 'Full-size files on disk', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<div class="tsosk-stat-box">
					<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( (int) ( $unmatched['files'] ?? 0 ) ) ); ?></span>
					<span class="tsosk-stat-sub"><?php echo esc_html( size_format( (int) ( $unmatched['bytes'] ?? 0 ), 2 ) ); ?></span>
					<span class="tsosk-stat-label"><?php esc_html_e( 'Unmatched derivatives', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<div class="tsosk-stat-box">
					<span class="tsosk-stat-num"><?php echo esc_html( number_format_i18n( (int) ( $quarantine['files'] ?? 0 ) ) ); ?></span>
					<span class="tsosk-stat-sub">
						<?php
						printf(
							/* translators: 1: formatted size, 2: number of quarantine batches */
							esc_html__( '%1$s · %2$s batch(es)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
							esc_html( size_format( (int) ( $quarantine['size'] ?? 0 ), 2 ) ),
							esc_html( number_format_i18n( (int) ( $quarantine['count'] ?? 0 ) ) )
						);
						?>
					</span>
					<span class="tsosk-stat-label"><?php esc_html_e( 'Quarantine (still on disk)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<?php if ( ! empty( $audit['scanned_at'] ) ) : ?>
				<div class="tsosk-stat-box">
					<span class="tsosk-stat-num tsosk-stat-num--compact"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $audit['scanned_at'] ) ); ?></span>
					<span class="tsosk-stat-label"><?php esc_html_e( 'Last audit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
				</div>
				<?php endif; ?>
			</div>
			<p class="description tsosk-audit-unmatched-help">
				<?php esc_html_e( 'Unmatched derivatives are generated thumbnail-style files (for example photo-150x150.jpg) found under uploads that are not listed in any attachment metadata — often leftovers from deleted media, manual copies, or plugins. They are not counted in the per-size table below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
		</div>
		<?php
		$usage = is_array( $audit['usage'] ?? null ) ? $audit['usage'] : array();
		$this->render_sizes_controls( $registered, $size_meta, $by_size, $disabled, $nonce, $usage );
		return (string) ob_get_clean();
	}

	/**
	 * Render sizes controls.
	 *
	 * @param array<string, array> $registered Registered subsizes.
	 * @param array<string, array> $size_meta  Width/height/crop/registered flag per size name,
	 *                                          including sizes no longer registered by any
	 *                                          active theme or plugin (legacy sizes) that were
	 *                                          still found in attachment metadata.
	 * @param array<string, array> $by_size    Audit stats per size.
	 * @param string[]             $disabled Disabled names.
	 * @param string               $nonce    Nonce.
	 * @param array<string, mixed> $usage    Usage signals from detect_usage(); empty when the audit has none.
	 */
	private function render_sizes_controls( array $registered, array $size_meta, array $by_size, array $disabled, string $nonce, array $usage = array() ): void {
		// Every size WordPress registers itself gets the core badge; only $locked_core (the ones with fields in
		// Settings > Media) cannot be switched off here.
		$core_sizes  = TSOSK_Uploads_Scanner::CORE_SIZES;
		$locked_core = TSOSK_Uploads_Scanner::SETTINGS_SIZES;
		// Sizes found in the files but not registered. A core size that is turned off and has no files left is not
		// listed: WordPress does not register it, so it is not one of "the registered sizes".
		$legacy_names = array_values(
			array_filter(
				array_diff( array_keys( $by_size ), array_keys( $registered ) ),
				static function ( $name ) use ( $core_sizes, $by_size ): bool {
					return ! in_array( $name, $core_sizes, true ) || (int) ( $by_size[ $name ]['files'] ?? 0 ) > 0;
				}
			)
		);
		$seen_front   = $this->get_seen_sizes();

		// Registered sizes with no files whose old-named files still exist (a theme renamed its sizes).
		$old_names_for = array();
		foreach ( $usage['sizes'] ?? array() as $old_name => $u ) {
			if ( ! empty( $u['renamed']['target'] ) && 'renamed' === $u['renamed']['type'] ) {
				$old_names_for[ $u['renamed']['target'] ][ $old_name ] = (int) ( $by_size[ $old_name ]['files'] ?? 0 );
			}
		}
		?>
		<div class="tsosk-card">
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<?php esc_html_e( 'Risks when disabling sizes: broken images in old content, missing srcset variants, and layout issues in themes or page builders that expect specific dimensions. Test on staging first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<h3><?php esc_html_e( 'Registered image sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-image-sizes-table">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'On', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Dimensions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Disk usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'If disabled (new uploads)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $registered as $name => $size ) : ?>
						<?php
						$is_core    = in_array( $name, $core_sizes, true );
						$is_locked  = in_array( $name, $locked_core, true );
						$is_enabled = ! in_array( $name, $disabled, true );
						$stats      = $by_size[ $name ] ?? array(
							'files' => 0,
							'bytes' => 0,
						);
						$width      = (int) ( $size['width'] ?? 0 );
						$height     = (int) ( $size['height'] ?? 0 );
						$crop       = ! empty( $size['crop'] );
						?>
						<tr>
							<td>
								<input type="checkbox" class="tsosk-img-audit-size-toggle"
										data-name="<?php echo esc_attr( $name ); ?>"
										<?php checked( $is_enabled ); ?>
										<?php disabled( $is_locked ); ?>>
							</td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<?php if ( $is_core ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php
								$tsosk_badge = '';
								$tsosk_paras = array();
								// Why this core size has no field in Settings > Media (only three of the six do).
								$tsosk_info = array();
								if ( 'medium_large' === $name ) {
									$tsosk_info[] = __( 'WordPress registers this size itself (768 px wide by default) but Settings > Media has no field for it. It is only generated while its options medium_large_size_w and medium_large_size_h are not both 0.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								} elseif ( $is_core && ! $is_locked ) {
									$tsosk_info[] = __( 'Added by WordPress itself since version 5.3 (twice medium_large and twice large) so large screens get sharper srcset images. Settings > Media has no field for it; a theme or plugin can remove it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								}
								if ( ! empty( $old_names_for[ $name ] ) && (int) ( $stats['files'] ?? 0 ) < max( $old_names_for[ $name ] ) ) {
									$tsosk_paras[] = sprintf(
										/* translators: %s: comma-separated old size names (same dimensions) whose files still exist */
										__( 'Existing images are still saved under the old name %s (same dimensions). Regenerate thumbnails to fill this size.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										implode( ', ', array_keys( $old_names_for[ $name ] ) )
									);
								}
								?>
								<?php echo $this->render_info_popover( $tsosk_info, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
								<?php echo $this->render_info_popover( $tsosk_paras, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
							</td>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: width, 2: height, 3: crop yes/no */
										__( '%1$d × %2$d — crop: %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										$width,
										$height,
										$crop ? __( 'yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'no', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
									)
								);
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $stats['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td>
								<?php if ( $is_locked ) : ?>
									<?php esc_html_e( 'Always generated. Set 0 × 0 in Settings > Media to turn it off.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php elseif ( ! $is_enabled ) : ?>
									<?php esc_html_e( 'Skipped on new uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								<?php else : ?>
									<?php
									printf(
										/* translators: %s: formatted disk size */
										esc_html__( 'Could save ~%s on future uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) )
									);
									?>
								<?php endif; ?>
								<?php if ( ! $is_core && $is_enabled && ! empty( $old_names_for[ $name ] ) ) : ?>
									<?php foreach ( $old_names_for[ $name ] as $old_name => $old_files ) : ?>
										<?php if ( (int) $old_files > (int) ( $stats['files'] ?? 0 ) ) : ?>
											<br>
											<button type="button" class="button button-small tsosk-img-adopt"
													data-from="<?php echo esc_attr( (string) $old_name ); ?>"
													data-to="<?php echo esc_attr( $name ); ?>"
													data-files="<?php echo esc_attr( (string) (int) $old_files ); ?>"
													data-nonce="<?php echo esc_attr( $nonce ); ?>">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %s: old size name whose files can be reused */
														__( 'Reuse the files of %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
														(string) $old_name
													)
												);
												?>
											</button>
										<?php endif; ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $legacy_names as $name ) : ?>
						<?php
						$stats  = $by_size[ $name ] ?? array(
							'files' => 0,
							'bytes' => 0,
						);
						$meta   = $size_meta[ $name ] ?? array(
							'width'  => 0,
							'height' => 0,
							'crop'   => null,
						);
						$width  = (int) ( $meta['width'] ?? 0 );
						$height = (int) ( $meta['height'] ?? 0 );
						$crop   = $meta['crop'] ?? null;
						?>
						<tr>
							<td>
								<input type="checkbox" class="tsosk-img-audit-size-toggle"
										data-name="<?php echo esc_attr( $name ); ?>"
										<?php checked( ! in_array( $name, $disabled, true ) ); ?>
										<?php disabled( self::is_protected_size( (string) $name ) || in_array( $name, $locked_core, true ) ); ?>>
							</td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'not registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php if ( self::is_protected_size( (string) $name ) ) : ?>
									<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'site icon (protected)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
								<?php endif; ?>
								<?php
								$tsosk_paras = array();
								if ( self::is_protected_size( (string) $name ) ) {
									$tsosk_paras[] = __( 'Created by WordPress for the site icon (favicon): it cannot be turned off or moved to quarantine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								} else {
									$tsosk_paras[] = in_array( $name, $core_sizes, true )
										? __( 'A WordPress core size that is currently off (0 × 0 in Settings > Media) or removed by a theme or plugin: nothing new is generated, these are leftover files from when it was on.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
										: __( 'Not registered by any active theme or plugin — no new files are generated for it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
									if ( in_array( $name, $seen_front, true ) && ! in_array( $name, $disabled, true ) ) {
										$tsosk_paras[] = __( 'It was seen registered on the front end: check that no theme still uses it before moving its files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
									}
									$tsosk_paras[] = in_array( $name, $disabled, true )
										? __( 'Turned off: its existing files are listed in the quarantine section below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
										: __( 'Untick it and save to list its existing files in the quarantine section below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								}
								echo $this->render_info_popover( $tsosk_paras, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
								?>
							</td>
							<td>
								<?php
								if ( null === $crop ) {
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height */
											__( '%1$d × %2$d — crop: unknown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$width,
											$height
										)
									);
								} else {
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height, 3: crop yes/no */
											__( '%1$d × %2$d — crop: %3$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$width,
											$height,
											$crop ? __( 'yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'no', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
										)
									);
								}
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $stats['files'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) ( $stats['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td>
								<?php
								if ( self::is_protected_size( (string) $name ) ) {
									esc_html_e( 'Protected (site icon)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								} elseif ( in_array( $name, $disabled, true ) ) {
									esc_html_e( 'Turned off — see quarantine below', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								} else {
									esc_html_e( 'Untick to quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( ! empty( $legacy_names ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Rows marked "not registered" come from an old theme or a deactivated plugin. WordPress still stores their files even though nothing generates them any more.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php endif; ?>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-image-sizes-save"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save image size settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-image-sizes-save-msg"></span>
			</p>
		</div>
		<?php
		$this->render_quarantine_selector_section( $registered, $size_meta, $legacy_names, $by_size, $disabled, $locked_core, $nonce, $usage );
	}

	/**
	 * Section for moving existing files of sizes that no longer generate new uploads —
	 * either a currently-registered size the admin has turned off above, or a legacy size
	 * that is not registered by any active theme or plugin (so it is inherently "off") —
	 * into quarantine.
	 *
	 * @param array<string, array> $registered   Registered subsizes.
	 * @param array<string, array> $size_meta    Width/height/crop per size name, including
	 *                                            legacy (unregistered) sizes.
	 * @param string[]             $legacy_names Size names found in metadata that are not
	 *                                            currently registered.
	 * @param array<string, array> $by_size      Audit stats per size.
	 * @param string[]             $disabled     Disabled size names (registered sizes only).
	 * @param string[]             $core_sizes   Core size names, never eligible.
	 * @param string               $nonce        Nonce.
	 * @param array<string, mixed> $usage        Usage signals from detect_usage(); empty when unavailable.
	 */
	private function render_quarantine_selector_section( array $registered, array $size_meta, array $legacy_names, array $by_size, array $disabled, array $core_sizes, string $nonce, array $usage = array() ): void {
		$usage_sizes = is_array( $usage['sizes'] ?? null ) ? $usage['sizes'] : array();
		$eligible    = array();
		foreach ( $registered as $name => $size ) {
			if ( in_array( $name, $core_sizes, true ) || self::is_protected_size( (string) $name ) || ! in_array( $name, $disabled, true ) ) {
				continue;
			}
			$stats = $by_size[ $name ] ?? array(
				'files' => 0,
				'bytes' => 0,
			);
			if ( (int) ( $stats['files'] ?? 0 ) <= 0 ) {
				continue;
			}
			$eligible[ $name ] = array(
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'files'  => (int) ( $stats['files'] ?? 0 ),
				'bytes'  => (int) ( $stats['bytes'] ?? 0 ),
				'legacy' => false,
			);
		}
		$seen_sizes = $this->get_seen_sizes();
		foreach ( $legacy_names as $name ) {
			$stats = $by_size[ $name ] ?? array(
				'files' => 0,
				'bytes' => 0,
			);
			// The site icon and the sizes with fields in Settings > Media are never eligible, even when they are off
			// (0 × 0) and WordPress no longer registers them: that is where their leftover files end up.
			if ( self::is_protected_size( (string) $name ) || in_array( $name, $core_sizes, true ) ) {
				continue;
			}
			// A size seen registered on the front end stays hidden here unless the admin turned it off explicitly.
			if ( (int) ( $stats['files'] ?? 0 ) <= 0 || ( in_array( $name, $seen_sizes, true ) && ! in_array( $name, $disabled, true ) ) ) {
				continue;
			}
			$meta              = $size_meta[ $name ] ?? array(
				'width'  => 0,
				'height' => 0,
			);
			$eligible[ $name ] = array(
				'width'  => (int) ( $meta['width'] ?? 0 ),
				'height' => (int) ( $meta['height'] ?? 0 ),
				'files'  => (int) ( $stats['files'] ?? 0 ),
				'bytes'  => (int) ( $stats['bytes'] ?? 0 ),
				'legacy' => true,
			);
		}
		$equiv = $this->find_equivalent_sizes( array_keys( $eligible ), $registered, $size_meta, $by_size, $disabled );
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Free up space from disabled sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<?php esc_html_e( 'Only sizes already turned off above are listed here, and turning a size off never deletes its files by itself — you choose which of those to move now. Files are moved to quarantine, not deleted: you can restore them or check the site is fine first, then delete them for good from the quarantine list below (or leave them — they are auto-deleted after 30 days).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</div>
			<?php if ( empty( $eligible ) ) : ?>
				<p class="description">
					<?php
					if ( empty( $by_size ) ) {
						esc_html_e( 'Run the image sizes audit to list the sizes that still have files on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					} else {
						esc_html_e( 'No disabled size currently has existing files on disk (or the audit has not run yet).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					}
					?>
				</p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table" id="tsosk-image-sizes-delete-table">
						<thead>
							<tr>
								<th style="width:40px;"><?php esc_html_e( 'Move', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Dimensions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Disk usage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Detected references', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $eligible as $name => $row ) : ?>
							<?php
							$signals    = $usage_sizes[ $name ] ?? null;
							$has_signal = is_array( $signals ) && ( ! empty( $signals['content'] ) || ! empty( $signals['code'] ) );
							$precheck   = is_array( $signals ) && ! $has_signal && ! isset( $equiv[ $name ] );
							?>
							<tr>
								<td>
									<input type="checkbox" class="tsosk-img-audit-delete-checkbox"
											data-name="<?php echo esc_attr( $name ); ?>"
											<?php checked( $precheck ); ?>>
								</td>
								<td>
									<code><?php echo esc_html( $name ); ?></code>
									<?php if ( ! empty( $row['legacy'] ) ) : ?>
										<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'not registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: width, 2: height */
											__( '%1$d × %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
											$row['width'],
											$row['height']
										)
									);
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( $row['files'] ) ); ?></td>
								<td><?php echo esc_html( size_format( $row['bytes'], 2 ) ); ?></td>
								<td>
									<?php echo $this->render_usage_signals( $signals ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
									<?php if ( isset( $equiv[ $name ] ) ) : ?>
										<br><span class="tsosk-badge tsosk-badge-warn">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: active size with the same dimensions */
													__( 'Regenerate %s first', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
													$equiv[ $name ]['target']
												)
											);
											?>
										</span>
										<?php
										$tsosk_equiv_note = array(
											sprintf(
												/* translators: 1: active size with the same dimensions, 2: its number of files, 3: files of this size */
												__( 'Same dimensions as the active size %1$s, which has %2$d file(s) against %3$d here. Regenerate thumbnails for %1$s before moving these files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
												$equiv[ $name ]['target'],
												$equiv[ $name ]['new'],
												$equiv[ $name ]['old']
											),
										);
										echo $this->render_info_popover( $tsosk_equiv_note, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
										?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">
					<?php esc_html_e( 'Sizes with no detected references are pre-selected; the others are left unselected. The check looks at post content, post meta and options, and (for sizes no longer registered) at the PHP code of the active theme and plugins. It cannot see external hotlinks, cached pages, page-builder settings stored elsewhere or sizes built dynamically in code — "no references detected" does not mean "not used". The 30-day quarantine is the safety net.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<?php if ( ! empty( $usage['code_incomplete'] ) ) : ?>
						<strong><?php esc_html_e( 'The code scan hit its file or time limit, so it may be incomplete.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<?php endif; ?>
					<?php if ( empty( $usage_sizes ) ) : ?>
						<strong><?php esc_html_e( 'Run the audit again to detect references.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					<?php endif; ?>
				</p>
				<p style="margin-top:10px;">
					<button type="button" class="button button-primary" id="tsosk-image-sizes-quarantine"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Move files for selected sizes to quarantine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</button>
					<span class="tsosk-ajax-msg" id="tsosk-image-sizes-delete-msg"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Popover entries listing where content still links to files of a size (posts, post meta, options).
	 *
	 * @param array<string, mixed> $content Content reference data from count_content_references().
	 * @return array<int, array<string, mixed>>
	 */
	private function content_reference_entries( array $content ): array {
		$entries = array();

		$items = array();
		foreach ( is_array( $content['post_items'] ?? null ) ? $content['post_items'] : array() as $row ) {
			$title   = '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$items[] = array(
				sprintf( '%1$s (#%2$d, %3$s)', $title, (int) ( $row['id'] ?? 0 ), (string) ( $row['type'] ?? '' ) ),
				admin_url( 'post.php?post=' . (int) ( $row['id'] ?? 0 ) . '&action=edit' ),
			);
		}
		$entries[] = array(
			'title' => __( 'Posts and pages with a link to this size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'items' => $items,
			'more'  => max( 0, (int) ( $content['posts'] ?? 0 ) - count( $items ) ),
		);

		$items = array();
		foreach ( is_array( $content['meta_items'] ?? null ) ? $content['meta_items'] : array() as $row ) {
			$title   = '' !== (string) ( $row['title'] ?? '' ) ? (string) $row['title'] : __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$items[] = array(
				sprintf( '%1$s — %2$s (#%3$d)', $title, (string) ( $row['key'] ?? '' ), (int) ( $row['id'] ?? 0 ) ),
				admin_url( 'post.php?post=' . (int) ( $row['id'] ?? 0 ) . '&action=edit' ),
			);
		}
		$entries[] = array(
			'title' => __( 'Post meta (page builders, custom fields) with a link to this size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'items' => $items,
			'more'  => max( 0, (int) ( $content['meta'] ?? 0 ) - count( $items ) ),
		);

		$items = array();
		foreach ( is_array( $content['option_items'] ?? null ) ? $content['option_items'] : array() as $option_name ) {
			$items[] = array( (string) $option_name );
		}
		$entries[] = array(
			'title' => __( 'Options (widgets, theme settings) with a link to this size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'items' => $items,
			'more'  => max( 0, (int) ( $content['options'] ?? 0 ) - count( $items ) ),
		);

		return $entries;
	}

	/**
	 * Popover entries listing the files and lines of the active code that request a size by name.
	 *
	 * @param array<int, array<string, mixed>> $locations Locations from detect_code_usage().
	 * @return array<int, array<string, mixed>>
	 */
	private function code_location_entries( array $locations ): array {
		$items = array();
		foreach ( $locations as $loc ) {
			$items[] = array(
				sprintf( '%1$s: %2$s:%3$d', (string) ( $loc['label'] ?? '' ), (string) ( $loc['file'] ?? '' ), (int) ( $loc['line'] ?? 0 ) ),
			);
		}
		return array(
			array(
				'title' => __( 'Where the code asks for this size (file:line)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'items' => $items,
			),
		);
	}

	/**
	 * Badges describing the usage signals of one size.
	 *
	 * @param array<string, mixed>|null $signals Signals for the size, or null when the audit has none.
	 * @return string Escaped HTML.
	 */
	private function render_usage_signals( ?array $signals ): string {
		if ( null === $signals ) {
			return '<span class="description">' . esc_html__( 'Not checked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>';
		}
		$parts   = array();
		$content = is_array( $signals['content'] ?? null ) ? $signals['content'] : array();
		if ( ! empty( $content ) ) {
			$parts[] = '<span class="tsosk-badge tsosk-badge-warn">' . esc_html(
				sprintf(
					/* translators: 1: number of posts/pages, 2: number of post meta values, 3: number of option values that link to files of this size */
					__( 'Linked in content: %1$d post(s), %2$d meta, %3$d option(s)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) ( $content['posts'] ?? 0 ),
					(int) ( $content['meta'] ?? 0 ),
					(int) ( $content['options'] ?? 0 )
				)
			) . '</span>' . $this->render_info_popover( $this->content_reference_entries( $content ), true );
		}
		$code = is_array( $signals['code'] ?? null ) ? $signals['code'] : array();
		if ( ! empty( $code ) ) {
			$parts[] = '<span class="tsosk-badge tsosk-badge-warn">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated theme/plugin names whose code mentions this size */
					__( 'Requested by code: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', $code )
				)
			) . '</span>' . $this->render_info_popover( $this->code_location_entries( is_array( $signals['code_locations'] ?? null ) ? $signals['code_locations'] : array() ), true );
		}
		$renamed = is_array( $signals['renamed'] ?? null ) ? $signals['renamed'] : array();
		if ( ! empty( $renamed['target'] ) ) {
			if ( 'renamed' === ( $renamed['type'] ?? '' ) ) {
				$parts[] = '<span class="tsosk-badge tsosk-badge-info">' . esc_html(
					sprintf(
						/* translators: %s: name of the registered size with the same dimensions */
						__( 'Probably replaced by %s (same dimensions)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(string) $renamed['target']
					)
				) . '</span>';
			} else {
				$parts[] = '<span class="tsosk-badge tsosk-badge-info">' . esc_html(
					sprintf(
						/* translators: 1: name of the similarly named registered size, 2: its width, 3: its height */
						__( 'No equivalent: %1$s has different dimensions (%2$d × %3$d)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						(string) $renamed['target'],
						(int) ( $renamed['width'] ?? 0 ),
						(int) ( $renamed['height'] ?? 0 )
					)
				) . '</span>';
			}
		}
		$weak = is_array( $signals['code_weak'] ?? null ) ? $signals['code_weak'] : array();
		if ( ! empty( $weak ) ) {
			$parts[] = '<span class="tsosk-badge tsosk-badge-info">' . esc_html__( 'Name found in code (not confirmed)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>' . $this->render_info_popover(
				array(
					__( 'The name appears between quotes in this code, but not as an argument of an image function or as a size value, so it may be something unrelated (for example an array key).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					array(
						'title' => __( 'Where it appears (file:line)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'items' => array_map(
							static function ( $loc ): array {
								return array( sprintf( '%1$s: %2$s:%3$d', (string) ( $loc['label'] ?? '' ), (string) ( $loc['file'] ?? '' ), (int) ( $loc['line'] ?? 0 ) ) );
							},
							$weak
						),
					),
				)
			);
		}
		if ( empty( $content ) && empty( $code ) ) {
			array_unshift( $parts, '<span class="tsosk-badge tsosk-badge-ok">' . esc_html__( 'No references detected', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</span>' );
		}
		return implode( '<br>', $parts );
	}

	/**
	 * List of quarantined image-size batches, with restore / delete-now actions.
	 *
	 * @return string
	 */
	private function render_sizes_quarantine_html(): string {
		$entries = TSOSK_Uploads_Scanner::get_sizes_quarantine_entries();
		$nonce   = wp_create_nonce( 'tsosk_image_sizes_audit_nonce' );
		$summary = TSOSK_Uploads_Scanner::get_sizes_quarantine_summary();

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return (int) ( $b['quarantined_at'] ?? 0 ) <=> (int) ( $a['quarantined_at'] ?? 0 );
			}
		);

		ob_start();
		?>
		<div class="tsosk-card">
			<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'Quarantine is empty.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of batches, 2: total size */
					esc_html__( '%1$s batch(es) in quarantine, %2$s still on disk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( number_format_i18n( (int) $summary['count'] ) ),
					esc_html( size_format( (int) $summary['size'], 2 ) )
				);
				?>
			</p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-image-sizes-quarantine-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Quarantined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Auto-deletes on', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$tsosk_q_at      = (int) ( $entry['quarantined_at'] ?? 0 );
						$tsosk_q_expires = $tsosk_q_at + ( TSOSK_Uploads_Scanner::QUARANTINE_DAYS * DAY_IN_SECONDS );
						$entry_sizes     = is_array( $entry['sizes'] ?? null ) ? $entry['sizes'] : array();
						?>
						<tr id="tsosk-img-quarantine-<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>">
							<td class="tsosk-code" style="word-break:break-all;" data-label="<?php esc_attr_e( 'Sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( implode( ', ', $entry_sizes ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $entry['files'] ?? 0 ) ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Size', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( size_format( (int) ( $entry['bytes'] ?? 0 ), 2 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Quarantined', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d H:i', $tsosk_q_at ) : '—' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Auto-deletes on', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php echo esc_html( $tsosk_q_at > 0 ? wp_date( 'Y-m-d', $tsosk_q_expires ) : '—' ); ?></td>
							<td class="tsosk-actions" data-label="<?php esc_attr_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
								<button type="button" class="button button-small tsosk-img-quarantine-restore"
										data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
										data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Restore', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<button type="button" class="button button-small button-link-delete tsosk-img-quarantine-purge"
										data-entry-id="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>"
										data-nonce="<?php echo esc_attr( $nonce ); ?>"
										data-label="<?php echo esc_attr( implode( ', ', $entry_sizes ) ); ?>">
									<?php esc_html_e( 'Delete now', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
