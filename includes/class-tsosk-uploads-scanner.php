<?php
/**
 * TSO Swiss Knife – Uploads directory scanner (media footprint & image sizes).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Uploads_Scanner
 */
class TSOSK_Uploads_Scanner {

	/** Max files to walk per scan (safety cap). */
	public const MAX_FILES = 50000;

	/** Transient keys (prefixed by WordPress). */
	public const TRANSIENT_FOOTPRINT = 'tsosk_media_footprint_v1';
	public const TRANSIENT_SIZES     = 'tsosk_image_sizes_audit_v1';
	public const TRANSIENT_HYGIENE   = 'tsosk_uploads_hygiene_v1';

	/** Cache lifetime in seconds. */
	public const CACHE_TTL = 600;

	/** Directory names under uploads to skip (plugin-owned, not media). */
	private const SKIP_DIRS = array(
		'tso-swiss-knife-advanced-maintenance-developer-toolkit',
		'tsosk-config',
		'tsosk-l10n',
		'tsosk-logs',
		'tso-backups',
		'tso-options-tables-cleaner-backups',
		'tso-options-tables-cleaner-options-tab-cache',
	);

	/**
	 * Top-level upload folder name prefixes owned by TSO plugins (never media orphans).
	 *
	 * @return string[]
	 */
	public static function get_protected_upload_prefixes(): array {
		$prefixes = self::SKIP_DIRS;

		/**
		 * Filter protected folder prefixes under wp-content/uploads.
		 *
		 * @param string[] $prefixes Relative folder names or prefix patterns (tsosk-).
		 */
		return array_values( array_unique( (array) apply_filters( 'tsosk_protected_upload_path_prefixes', $prefixes ) ) );
	}

	/**
	 * Whether a relative uploads path belongs to a protected plugin folder.
	 *
	 * @param string $relative Path relative to uploads root.
	 * @return bool
	 */
	public static function is_protected_upload_relative_path( string $relative ): bool {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( '' === $relative ) {
			return true;
		}

		$parts = explode( '/', $relative );
		$top   = $parts[0] ?? '';

		foreach ( self::get_protected_upload_prefixes() as $prefix ) {
			if ( $top === $prefix || str_starts_with( $relative, $prefix . '/' ) ) {
				return true;
			}
		}

		if ( preg_match( '/^(tsosk-|tso-)/', $top ) ) {
			return true;
		}

		return self::is_guard_upload_file( $relative );
	}

	/**
	 * Whether a file is a standard guard file (silence is golden / deny access).
	 *
	 * @param string $relative Path relative to uploads root.
	 * @return bool
	 */
	public static function is_guard_upload_file( string $relative ): bool {
		$basename = basename( wp_normalize_path( $relative ) );
		return in_array( $basename, array( 'index.php', '.htaccess' ), true );
	}

	/**
	 * Whether a directory entry inside uploads should be skipped entirely.
	 *
	 * @param string $dir_name Basename of the directory.
	 * @return bool
	 */
	public static function should_skip_upload_dir( string $dir_name ): bool {
		if ( in_array( $dir_name, self::get_protected_upload_prefixes(), true ) ) {
			return true;
		}

		return (bool) preg_match( '/^(tsosk-|tso-)/', $dir_name );
	}

	/**
	 * Scan uploads for disk footprint statistics.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_footprint(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$stats = array(
			'scanned_files'    => 0,
			'total_bytes'      => 0,
			'original_bytes'   => 0,
			'derivative_bytes' => 0,
			'other_bytes'      => 0,
			'by_month'         => array(),
			'by_extension'     => array(),
			'largest'          => array(),
			'base_dir'         => $base,
			'scanned_at'       => time(),
			'truncated'        => false,
		);

		$largest_heap = array();
		$queue        = array( $base );

		while ( $queue && $stats['scanned_files'] < self::MAX_FILES ) {
			$dir = array_shift( $queue );
			if ( ! self::is_safe_scan_path( $dir, $base ) ) {
				continue;
			}

			$handle = @opendir( $dir );
			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = wp_normalize_path( trailingslashit( $dir ) . $entry );

				if ( is_dir( $path ) ) {
					if ( self::should_skip_dir( $path, $base ) ) {
						continue;
					}
					$queue[] = $path;
					continue;
				}

				if ( ! is_file( $path ) ) {
					continue;
				}

				++$stats['scanned_files'];
				if ( $stats['scanned_files'] > self::MAX_FILES ) {
					$stats['truncated'] = true;
					break 2;
				}

				$size     = (int) filesize( $path );
				$relative = ltrim( str_replace( $base, '', $path ), '/' );
				$ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$month    = self::month_key_from_relative( $relative );
				$is_deriv = self::is_derivative_filename( $entry );

				$stats['total_bytes'] += $size;
				if ( $is_deriv ) {
					$stats['derivative_bytes'] += $size;
				} elseif ( self::is_likely_original_media( $ext ) ) {
					$stats['original_bytes'] += $size;
				} else {
					$stats['other_bytes'] += $size;
				}

				if ( '' !== $month ) {
					if ( ! isset( $stats['by_month'][ $month ] ) ) {
						$stats['by_month'][ $month ] = array(
							'bytes' => 0,
							'files' => 0,
						);
					}
					$stats['by_month'][ $month ]['bytes'] += $size;
					++$stats['by_month'][ $month ]['files'];
				}

				if ( '' !== $ext ) {
					if ( ! isset( $stats['by_extension'][ $ext ] ) ) {
						$stats['by_extension'][ $ext ] = array(
							'bytes' => 0,
							'files' => 0,
						);
					}
					$stats['by_extension'][ $ext ]['bytes'] += $size;
					++$stats['by_extension'][ $ext ]['files'];
				}

				self::track_largest_file( $largest_heap, array(
					'relative'      => $relative,
					'size'          => $size,
					'is_derivative' => $is_deriv,
				) );
			}

			closedir( $handle );
		}

		usort(
			$largest_heap,
			static function ( array $a, array $b ): int {
				return $b['size'] <=> $a['size'];
			}
		);

		$stats['largest'] = $largest_heap;

		krsort( $stats['by_month'] );
		uasort(
			$stats['by_extension'],
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return $stats;
	}

	/**
	 * Audit registered image sizes against attachment metadata and disk files.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_image_sizes(): array {
		global $wpdb;

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$registered = function_exists( 'wp_get_registered_image_subsizes' )
			? wp_get_registered_image_subsizes()
			: array();

		$size_stats = array();
		foreach ( array_keys( $registered ) as $size_name ) {
			$size_stats[ $size_name ] = array(
				'files' => 0,
				'bytes' => 0,
			);
		}

		$full_stats = array(
			'files' => 0,
			'bytes' => 0,
		);

		$unmatched = array(
			'files' => 0,
			'bytes' => 0,
		);

		$known_files = array();
		$attachments = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				'_wp_attachment_metadata'
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$post_id  = absint( $row['post_id'] ?? 0 );
				$metadata = maybe_unserialize( $row['meta_value'] ?? '' );
				if ( ! is_array( $metadata ) || ! $post_id ) {
					continue;
				}

				++$attachments;
				$attached = get_attached_file( $post_id );
				if ( $attached && file_exists( $attached ) ) {
					$norm = wp_normalize_path( $attached );
					$known_files[ $norm ] = true;
					$full_stats['files']++;
					$full_stats['bytes'] += (int) filesize( $attached );
				}

				if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
					continue;
				}

				$dir = $attached ? wp_normalize_path( trailingslashit( dirname( $attached ) ) ) : '';

				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
						continue;
					}

					$file_path = $dir ? wp_normalize_path( $dir . $size_data['file'] ) : '';
					if ( ! $file_path || ! file_exists( $file_path ) ) {
						continue;
					}

					$known_files[ $file_path ] = true;
					$file_size = (int) filesize( $file_path );
					$key       = sanitize_key( (string) $size_name );

					if ( isset( $size_stats[ $key ] ) ) {
						++$size_stats[ $key ]['files'];
						$size_stats[ $key ]['bytes'] += $file_size;
					} else {
						++$unmatched['files'];
						$unmatched['bytes'] += $file_size;
					}
				}
			}
		}

		$dim_map = self::build_dimension_to_size_map( $registered );
		$queue   = array( $base );
		$walked  = 0;

		while ( $queue && $walked < self::MAX_FILES ) {
			$dir = array_shift( $queue );
			if ( ! self::is_safe_scan_path( $dir, $base ) ) {
				continue;
			}

			$handle = @opendir( $dir );
			if ( ! $handle ) {
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = wp_normalize_path( trailingslashit( $dir ) . $entry );

				if ( is_dir( $path ) ) {
					if ( self::should_skip_dir( $path, $base ) ) {
						continue;
					}
					$queue[] = $path;
					continue;
				}

				if ( ! is_file( $path ) || ! self::is_derivative_filename( $entry ) ) {
					continue;
				}

				++$walked;
				if ( isset( $known_files[ $path ] ) ) {
					continue;
				}

				$size = (int) filesize( $path );
				if ( ! preg_match( '/-(\d+)x(\d+)\.[^.]+$/i', $entry, $matches ) ) {
					++$unmatched['files'];
					$unmatched['bytes'] += $size;
					continue;
				}

				$dim_key = $matches[1] . 'x' . $matches[2];
				if ( isset( $dim_map[ $dim_key ] ) ) {
					$size_name = $dim_map[ $dim_key ];
					if ( isset( $size_stats[ $size_name ] ) ) {
						++$size_stats[ $size_name ]['files'];
						$size_stats[ $size_name ]['bytes'] += $size;
					} else {
						++$unmatched['files'];
						$unmatched['bytes'] += $size;
					}
				} else {
					++$unmatched['files'];
					$unmatched['bytes'] += $size;
				}
			}

			closedir( $handle );
		}

		uasort(
			$size_stats,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return array(
			'registered'          => $registered,
			'attachments_scanned' => $attachments,
			'full'                => $full_stats,
			'by_size'             => $size_stats,
			'unmatched_derivatives' => $unmatched,
			'scanned_at'            => time(),
			'truncated'             => $walked >= self::MAX_FILES,
		);
	}

	/**
	 * Keep only the largest files during scan (memory-safe).
	 *
	 * @param array<int, array<string, mixed>> $heap  Current heap.
	 * @param array<string, mixed>             $item  File row.
	 * @param int                              $limit Max entries.
	 */
	private static function track_largest_file( array &$heap, array $item, int $limit = 20 ): void {
		if ( count( $heap ) < $limit ) {
			$heap[] = $item;
			return;
		}

		$min_index = 0;
		foreach ( $heap as $index => $row ) {
			if ( (int) ( $row['size'] ?? 0 ) < (int) ( $heap[ $min_index ]['size'] ?? 0 ) ) {
				$min_index = $index;
			}
		}

		if ( (int) ( $item['size'] ?? 0 ) > (int) ( $heap[ $min_index ]['size'] ?? 0 ) ) {
			$heap[ $min_index ] = $item;
		}
	}

	/**
	 * @param string $relative Path relative to uploads root.
	 * @return string Month key YYYY/MM or empty.
	 */
	private static function month_key_from_relative( string $relative ): string {
		if ( preg_match( '#^(\d{4}/\d{2})/#', $relative, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * @param string $filename File basename.
	 * @return bool
	 */
	public static function is_derivative_filename( string $filename ): bool {
		return (bool) preg_match( '/-\d+x\d+\.[^.]+$/i', $filename );
	}

	/**
	 * @param string $extension Lowercase extension.
	 * @return bool
	 */
	private static function is_likely_original_media( string $extension ): bool {
		return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'pdf', 'mp4', 'webm', 'mp3', 'wav', 'ogg' ), true );
	}

	/**
	 * @param array<string, array{width:int, height:int, crop?:bool}> $registered Registered subsizes.
	 * @return array<string, string> Dimension key => size name.
	 */
	private static function build_dimension_to_size_map( array $registered ): array {
		$map = array();
		foreach ( $registered as $name => $size ) {
			$width  = (int) ( $size['width'] ?? 0 );
			$height = (int) ( $size['height'] ?? 0 );
			if ( $width > 0 && $height > 0 ) {
				$map[ $width . 'x' . $height ] = sanitize_key( (string) $name );
			}
		}
		return $map;
	}

	/**
	 * @param string $path Full path.
	 * @param string $base Uploads base path.
	 * @return bool
	 */
	private static function is_safe_scan_path( string $path, string $base ): bool {
		$path = wp_normalize_path( $path );
		$base = wp_normalize_path( $base );
		return str_starts_with( $path, $base );
	}

	/**
	 * @param string $path Directory path.
	 * @param string $base Uploads base path.
	 * @return bool
	 */
	private static function should_skip_dir( string $path, string $base ): bool {
		$relative = ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( $path ) ), '/' );
		$parts    = explode( '/', $relative );
		$name     = end( $parts );

		return self::should_skip_upload_dir( (string) $name );
	}

	/**
	 * Scan uploads (and a few known cache paths) for folders that may be removable.
	 *
	 * @return array{items: array<int, array<string, mixed>>, scanned_at: int}|WP_Error
	 */
	public static function scan_hygiene(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}

		$uploads_base = wp_normalize_path( trailingslashit( (string) $uploads['basedir'] ) );
		if ( ! is_dir( $uploads_base ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$items = array();

		$entries = scandir( $uploads_base );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $uploads_base . $entry;
				if ( ! is_dir( $path ) ) {
					continue;
				}
				$item = self::classify_uploads_top_folder( (string) $entry, $path, $uploads_base );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}
		}

		foreach ( self::get_extra_hygiene_paths() as $extra ) {
			if ( ! is_dir( $extra['path'] ) ) {
				continue;
			}
			$item = self::classify_known_cache_folder( $extra['relative'], $extra['path'], $extra['scope'] );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$order = array( 'safe' => 0, 'review' => 1, 'keep' => 2 );
				$ca    = $order[ $a['confidence'] ?? 'review' ] ?? 1;
				$cb    = $order[ $b['confidence'] ?? 'review' ] ?? 1;
				if ( $ca !== $cb ) {
					return $ca <=> $cb;
				}
				return (int) ( $b['size'] ?? 0 ) <=> (int) ( $a['size'] ?? 0 );
			}
		);

		foreach ( $items as &$item ) {
			$item['id'] = md5( (string) ( $item['path'] ?? '' ) );
		}
		unset( $item );

		return array(
			'items'      => $items,
			'scanned_at' => time(),
		);
	}

	/**
	 * Delete a folder previously returned by scan_hygiene() when marked deletable.
	 *
	 * @param string               $folder_id Folder id from scan results.
	 * @param array<string, mixed> $scan      Cached scan payload.
	 * @return true|WP_Error
	 */
	public static function delete_hygiene_folder( string $folder_id, array $scan ) {
		$folder_id = sanitize_key( $folder_id );
		if ( '' === $folder_id || empty( $scan['items'] ) || ! is_array( $scan['items'] ) ) {
			return new WP_Error( 'invalid_folder', __( 'Unknown folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$target = null;
		foreach ( $scan['items'] as $item ) {
			if ( ! is_array( $item ) || ( $item['id'] ?? '' ) !== $folder_id ) {
				continue;
			}
			$target = $item;
			break;
		}

		if ( null === $target || empty( $target['deletable'] ) || empty( $target['path'] ) ) {
			return new WP_Error( 'not_deletable', __( 'This folder cannot be deleted from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$path = wp_normalize_path( (string) $target['path'] );
		$real = realpath( $path );
		if ( false === $real ) {
			return new WP_Error( 'missing', __( 'The folder no longer exists.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$path = wp_normalize_path( $real );

		if ( ! self::is_allowed_hygiene_delete_path( $path ) ) {
			return new WP_Error( 'invalid_path', __( 'The folder path is outside the allowed locations.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! is_dir( $path ) ) {
			return new WP_Error( 'missing', __( 'The folder no longer exists.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! self::remove_directory_tree( $path ) ) {
			return new WP_Error( 'delete_failed', __( 'Could not delete the folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return true;
	}

	/**
	 * @return array<int, array{path: string, relative: string, scope: string}>
	 */
	private static function get_extra_hygiene_paths(): array {
		$paths  = array();
		$roots  = array();
		$wp_root = function_exists( 'tsosk_get_wp_root_dir' ) ? tsosk_get_wp_root_dir() : ABSPATH;
		$wp_root = wp_normalize_path( untrailingslashit( (string) $wp_root ) );

		$roots[] = $wp_root;
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
			if ( ! in_array( $content, $roots, true ) ) {
				$roots[] = $content;
			}
		}

		foreach ( $roots as $root ) {
			if ( '' === $root || ! is_dir( $root ) ) {
				continue;
			}
			$tmb = $root . '/.tmb';
			if ( is_dir( $tmb ) ) {
				$scope    = ( $root === $wp_root ) ? 'wp_root' : 'wp_content';
				$relative = ( '.tmb' === basename( $tmb ) ) ? '.tmb' : ltrim( str_replace( $wp_root, '', $tmb ), '/' );
				$paths[]  = array(
					'path'     => $tmb,
					'relative' => $relative,
					'scope'    => $scope,
				);
			}
		}

		if ( is_dir( $wp_root ) ) {
			$entries = scandir( $wp_root );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( in_array( $entry, array( '.', '..', 'wp-admin', 'wp-includes', 'wp-content' ), true ) ) {
						continue;
					}
					$subdir = $wp_root . '/' . $entry;
					if ( ! is_dir( $subdir ) ) {
						continue;
					}
					$tmb = $subdir . '/.tmb';
					if ( is_dir( $tmb ) ) {
						$paths[] = array(
							'path'     => wp_normalize_path( $tmb ),
							'relative' => $entry . '/.tmb',
							'scope'    => 'wp_subdir',
						);
					}
				}
			}
		}

		return $paths;
	}

	/**
	 * @param string $name        Top-level uploads folder name.
	 * @param string $path        Absolute path.
	 * @param string $uploads_base Uploads base path.
	 * @return array<string, mixed>|null
	 */
	private static function classify_uploads_top_folder( string $name, string $path, string $uploads_base ): ?array {
		if ( preg_match( '/^\d{4}$/', $name ) ) {
			return null;
		}

		$stats = self::measure_directory( $path );
		$slug  = defined( 'TSOSK_UPLOADS_SLUG' ) ? TSOSK_UPLOADS_SLUG : 'tso-swiss-knife-advanced-maintenance-developer-toolkit';

		if ( $name === $slug ) {
			return self::build_hygiene_item(
				$path,
				$name,
				'uploads',
				$stats,
				'keep',
				__( 'Active Swiss Knife data folder (config, logs). Do not delete.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				false
			);
		}

		if ( in_array( $name, array( 'tsosk-config', 'tsosk-l10n', 'tsosk-logs' ), true ) ) {
			if ( 'tsosk-config' === $name ) {
				$migrated = self::is_swiss_knife_config_legacy_migrated();
				return self::build_hygiene_item(
					$path,
					$name,
					'uploads',
					$stats,
					$migrated ? 'safe' : 'review',
					$migrated
						? __( 'Legacy Swiss Knife config folder. JSON was migrated to the long slug folder — safe to remove if empty or duplicate.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						: __( 'Legacy Swiss Knife config folder. Confirm the new config folder exists before deleting.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$migrated
				);
			}
			if ( 'tsosk-l10n' === $name ) {
				return self::build_hygiene_item(
					$path,
					$name,
					'uploads',
					$stats,
					'safe',
					__( 'Obsolete language-cache folder from an older Swiss Knife version. Safe to delete — not used by current releases.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					true
				);
			}
			$logs_removable = self::is_swiss_knife_logs_legacy_removable( $path );
			return self::build_hygiene_item(
				$path,
				$name,
				'uploads',
				$stats,
				$logs_removable ? 'safe' : 'review',
				$logs_removable
					? __( 'Legacy Swiss Knife logs folder. Current logs use uploads/{plugin-slug}/logs/ — safe to remove if you no longer need these files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Legacy Swiss Knife logs folder. Review contents before deleting — may contain log files not copied elsewhere.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$logs_removable
			);
		}

		if ( '.tmb' === $name ) {
			return self::classify_known_cache_folder( $name, $path, 'uploads' );
		}

		if ( preg_match( '/^(tso-|tsosk-)/', $name ) ) {
			$plugin_active = self::is_uploads_plugin_folder_active( $name );
			if ( true === $plugin_active ) {
				return self::build_hygiene_item(
					$path,
					$name,
					'uploads',
					$stats,
					'keep',
					__( 'Folder belongs to an active TSO plugin — keep it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					false
				);
			}
			if ( false === $plugin_active ) {
				return self::build_hygiene_item(
					$path,
					$name,
					'uploads',
					$stats,
					'review',
					__( 'Folder matches a TSO plugin slug but that plugin is not active. Review before deleting — may contain backups or exports.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					false
				);
			}
		}

		if ( in_array( $name, array( 'woocommerce_uploads', 'wc-logs', 'elementor', 'wflogs' ), true ) ) {
			return self::build_hygiene_item(
				$path,
				$name,
				'uploads',
				$stats,
				'keep',
				__( 'Known plugin folder — not classified as disposable cache.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				false
			);
		}

		return self::build_hygiene_item(
			$path,
			$name,
			'uploads',
			$stats,
			'review',
			__( 'Custom or third-party folder under uploads. Verify what created it before deleting.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			false
		);
	}

	/**
	 * @param string               $relative Display path.
	 * @param string               $path     Absolute path.
	 * @param string               $scope    uploads|wp_root|wp_subdir|wp_content.
	 * @return array<string, mixed>|null
	 */
	private static function classify_known_cache_folder( string $relative, string $path, string $scope ): ?array {
		$stats = self::measure_directory( $path );
		$name  = basename( wp_normalize_path( $path ) );

		if ( '.tmb' === $name ) {
			return self::build_hygiene_item(
				$path,
				$relative,
				$scope,
				$stats,
				'safe',
				__( 'elFinder / file-manager thumbnail cache (.tmb). Safe to delete — thumbnails regenerate when you browse files in that tool.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				true
			);
		}

		return null;
	}

	/**
	 * @param string               $path       Absolute path.
	 * @param string               $relative   Relative label.
	 * @param string               $scope      Scope id.
	 * @param array{size:int,files:int} $stats Directory stats.
	 * @param string               $confidence safe|review|keep.
	 * @param string               $reason     Human reason.
	 * @param bool                 $deletable  Whether delete is offered.
	 * @return array<string, mixed>
	 */
	private static function build_hygiene_item( string $path, string $relative, string $scope, array $stats, string $confidence, string $reason, bool $deletable ): array {
		return array(
			'path'       => wp_normalize_path( $path ),
			'relative'   => $relative,
			'scope'      => $scope,
			'size'       => (int) ( $stats['size'] ?? 0 ),
			'files'      => (int) ( $stats['files'] ?? 0 ),
			'confidence' => $confidence,
			'reason'     => $reason,
			'deletable'  => $deletable && 'safe' === $confidence,
		);
	}

	/**
	 * Whether tsosk-config legacy data was migrated to the long slug folder.
	 */
	private static function is_swiss_knife_config_legacy_migrated(): bool {
		if ( ! class_exists( 'TSOSK_Config_Storage' ) ) {
			return false;
		}

		$new_dir = TSOSK_Config_Storage::get_dir();
		if ( '' !== $new_dir && is_dir( $new_dir ) ) {
			foreach ( array( TSOSK_Config_Storage::DEBUG_JSON, TSOSK_Config_Storage::SECURITY_JSON, TSOSK_Config_Storage::PROFILES_JSON ) as $json ) {
				if ( is_readable( trailingslashit( $new_dir ) . $json ) ) {
					return true;
				}
			}
		}

		$legacy_dirs = TSOSK_Config_Storage::get_legacy_upload_dirs();
		$legacy_path = $legacy_dirs['config'] ?? '';
		if ( '' !== $legacy_path && is_dir( $legacy_path ) ) {
			foreach ( array( TSOSK_Config_Storage::DEBUG_JSON, TSOSK_Config_Storage::SECURITY_JSON, TSOSK_Config_Storage::PROFILES_JSON ) as $json ) {
				if ( is_readable( trailingslashit( $legacy_path ) . $json ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * @param string $legacy_logs_path Absolute legacy logs directory.
	 */
	private static function is_swiss_knife_logs_legacy_removable( string $legacy_logs_path ): bool {
		if ( ! class_exists( 'TSOSK_Config_Storage' ) ) {
			return false;
		}

		$new_logs = TSOSK_Config_Storage::get_logs_dir();
		if ( '' !== $new_logs && is_dir( $new_logs ) ) {
			return true;
		}

		$stats = self::measure_directory( $legacy_logs_path );
		return 0 === (int) ( $stats['files'] ?? 0 );
	}

	/**
	 * @param string $folder_name Top-level uploads folder name.
	 * @return bool|null True active, false inactive, null not a plugin folder.
	 */
	private static function is_uploads_plugin_folder_active( string $folder_name ): ?bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			return null;
		}

		$found = false;
		foreach ( get_plugins() as $plugin_file => $data ) {
			$dir = dirname( $plugin_file );
			if ( '.' === $dir || $dir !== $folder_name ) {
				continue;
			}
			$found = true;
			return is_plugin_active( $plugin_file );
		}

		return $found ? false : null;
	}

	/**
	 * @param string $path Directory path.
	 * @return array{size: int, files: int}
	 */
	private static function measure_directory( string $path ): array {
		$size  = 0;
		$files = 0;
		$queue = array( wp_normalize_path( $path ) );
		$cap   = 10000;

		while ( $queue && $files < $cap ) {
			$dir = array_shift( $queue );
			$handle = @opendir( $dir );
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$item = wp_normalize_path( trailingslashit( $dir ) . $entry );
				if ( is_dir( $item ) ) {
					$queue[] = $item;
					continue;
				}
				if ( is_file( $item ) ) {
					++$files;
					$size += (int) filesize( $item );
				}
			}
			closedir( $handle );
		}

		return array(
			'size'  => $size,
			'files' => $files,
		);
	}

	/**
	 * @param string $path Absolute normalized path.
	 */
	private static function is_allowed_hygiene_delete_path( string $path ): bool {
		$path = wp_normalize_path( $path );
		if ( '' === $path || ! is_dir( $path ) ) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$base = wp_normalize_path( trailingslashit( (string) $uploads['basedir'] ) );
			if ( str_starts_with( $path, $base ) ) {
				$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
				$top      = explode( '/', $relative )[0] ?? '';
				if ( in_array( $top, array( 'tsosk-config', 'tsosk-l10n', 'tsosk-logs', '.tmb' ), true ) ) {
					return true;
				}
			}
		}

		if ( basename( $path ) === '.tmb' ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
				if ( dirname( $path ) === $content_dir ) {
					return true;
				}
			}

			$wp_root = function_exists( 'tsosk_get_wp_root_dir' ) ? tsosk_get_wp_root_dir() : ABSPATH;
			$wp_root = wp_normalize_path( untrailingslashit( (string) $wp_root ) );
			if ( str_starts_with( $path, $wp_root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $dir        Absolute directory path.
	 * @param string $root_real  Resolved root path (internal recursion guard).
	 */
	private static function remove_directory_tree( string $dir, string $root_real = '' ): bool {
		if ( is_link( $dir ) ) {
			return self::delete_path_entry( $dir );
		}

		if ( '' === $root_real ) {
			$resolved = realpath( $dir );
			if ( false === $resolved ) {
				return false;
			}
			$root_real = wp_normalize_path( $resolved );
		}

		$dir_real = realpath( $dir );
		if ( false === $dir_real ) {
			return false;
		}
		$dir_real = wp_normalize_path( $dir_real );
		if ( ! str_starts_with( $dir_real, $root_real ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$item = trailingslashit( $dir ) . $entry;
			if ( is_link( $item ) ) {
				if ( ! self::delete_path_entry( $item ) ) {
					return false;
				}
				continue;
			}
			if ( is_dir( $item ) ) {
				if ( ! self::remove_directory_tree( $item, $root_real ) ) {
					return false;
				}
				continue;
			}
			if ( is_file( $item ) && ! self::delete_path_entry( $item ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- plugin-owned cache/legacy dirs only.
		return @rmdir( $dir );
	}

	/**
	 * Delete a file or symlink without following directory links.
	 *
	 * @param string $path Absolute path.
	 */
	private static function delete_path_entry( string $path ): bool {
		wp_delete_file( $path );
		return ! is_link( $path ) && ! is_file( $path );
	}
}
