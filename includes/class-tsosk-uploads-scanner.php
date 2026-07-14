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

	/** Cache lifetime in seconds. */
	public const CACHE_TTL = 600;

	/** Directory names under uploads to skip (plugin-owned, not media). */
	private const SKIP_DIRS = array(
		'tsosk-config',
		'tsosk-l10n',
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
}
