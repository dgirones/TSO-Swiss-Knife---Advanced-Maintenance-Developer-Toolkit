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

	/** Max files to walk per scan (safety cap; the footprint scan runs in short batches, so this can be high). */
	public const MAX_FILES = 500000;

	/** Seconds of work per footprint batch (each batch is one AJAX request). */
	public const FOOTPRINT_BATCH_SECONDS = 8.0;

	/** Transient keys (prefixed by WordPress). */
	public const TRANSIENT_FOOTPRINT       = 'tsosk_media_footprint_v2';
	public const TRANSIENT_FOOTPRINT_STATE = 'tsosk_media_footprint_state';

	/** How long the last footprint result is kept (seconds). */
	public const FOOTPRINT_TTL = DAY_IN_SECONDS;

	/** Cron hook that permanently deletes quarantined folders once their window is over. */
	public const CRON_QUARANTINE_PURGE = 'tsosk_media_quarantine_purge';
	public const TRANSIENT_SIZES     = 'tsosk_image_sizes_audit_v1';
	public const TRANSIENT_HYGIENE   = 'tsosk_uploads_hygiene_v1';

	/** Cache lifetime in seconds. */
	public const CACHE_TTL = 600;

	/** Option storing quarantine entries metadata (moved-but-not-yet-purged hygiene folders). */
	public const OPTION_QUARANTINE = 'tsosk_media_quarantine';

	/** Days a quarantined folder is kept before it becomes eligible for automatic purge. */
	public const QUARANTINE_DAYS = 30;

	/** Option storing quarantined image-size derivative files (moved-but-not-yet-purged). */
	public const OPTION_SIZES_QUARANTINE = 'tsosk_image_sizes_quarantine';

	/** Cron hook that permanently deletes quarantined image-size files once their window is over. */
	public const CRON_SIZES_QUARANTINE_PURGE = 'tsosk_image_sizes_quarantine_purge';

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
	 * Scan uploads for disk footprint statistics in one go (kept for callers that need a single call).
	 *
	 * The admin screen uses footprint_start() / footprint_step() / footprint_finish() so a large
	 * library never depends on one request finishing in time.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_footprint(): array|WP_Error {
		$state = self::footprint_start();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$guard = 0;
		while ( empty( $state['done'] ) && $guard++ < 10000 ) {
			$state = self::footprint_step( $state, 5.0 );
		}
		return self::footprint_finish( $state );
	}

	/**
	 * Create the state for a batched footprint scan.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function footprint_start(): array|WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}

		$base = wp_normalize_path( trailingslashit( (string) $uploads['basedir'] ) );
		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return array(
			'base'       => $base,
			'queue'      => array( $base ),
			'done'       => false,
			'files'      => 0,
			'dirs'       => 0,
			'total'      => 0,
			'original'   => 0,
			'derivative' => 0,
			'other'      => 0,
			'by_month'   => array(),
			'by_top'     => array(),
			'by_ext'     => array(),
			'largest'    => array(),
			'unreadable' => array(),
			'links'      => 0,
			'truncated'  => false,
			'started_at' => time(),
		);
	}

	/**
	 * Walk directories until the time budget is used up.
	 *
	 * Everything under uploads is counted, including plugin folders and the quarantine: this screen
	 * reports real disk usage. Symlinks are never followed (they can loop or leave uploads).
	 *
	 * @param array<string, mixed> $state   State from footprint_start().
	 * @param float                $seconds Time budget for this batch.
	 * @return array<string, mixed>
	 */
	public static function footprint_step( array $state, float $seconds = self::FOOTPRINT_BATCH_SECONDS ): array {
		$base     = (string) $state['base'];
		$queue    = (array) $state['queue'];
		$deadline = microtime( true ) + max( 0.0, $seconds );
		$slug     = defined( 'TSOSK_UPLOADS_SLUG' ) ? (string) TSOSK_UPLOADS_SLUG : '';
		$visited  = 0;

		while ( ! empty( $queue ) ) {
			// Always finish at least one folder per batch so a tiny budget can never stall the scan.
			if ( $visited > 0 && microtime( true ) >= $deadline ) {
				break;
			}
			if ( (int) $state['files'] >= self::MAX_FILES ) {
				$state['truncated'] = true;
				$queue              = array();
				break;
			}

			$dir = (string) array_shift( $queue );
			++$visited;
			if ( ! self::is_safe_scan_path( $dir, $base ) ) {
				continue;
			}
			$handle = is_readable( $dir ) ? @opendir( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				if ( count( $state['unreadable'] ) < 20 ) {
					$state['unreadable'][] = ltrim( str_replace( $base, '', wp_normalize_path( $dir ) ), '/' ) ?: '/';
				}
				continue;
			}
			++$state['dirs'];

			$files    = array();
			$siblings = array();
			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = wp_normalize_path( trailingslashit( $dir ) . $entry );
				if ( is_link( $path ) ) {
					++$state['links'];
					continue;
				}
				if ( is_dir( $path ) ) {
					$queue[] = $path;
					continue;
				}
				if ( is_file( $path ) ) {
					$files[ $entry ]    = $path;
					$siblings[ $entry ] = true;
				}
			}
			closedir( $handle );

			foreach ( $files as $name => $path ) {
				if ( (int) $state['files'] >= self::MAX_FILES ) {
					$state['truncated'] = true;
					$queue              = array();
					break;
				}
				$name     = (string) $name;
				$size     = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$relative = ltrim( str_replace( $base, '', $path ), '/' );
				$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				$kind     = self::classify_footprint_file( $name, $siblings );

				++$state['files'];
				$state['total'] += $size;
				$state[ $kind ] += $size;

				// Folder: first path segment; the plugin quarantine is reported apart because it still uses disk.
				$top = ( false === strpos( $relative, '/' ) ) ? '' : (string) strstr( $relative, '/', true );
				if ( '' !== $slug && 0 === strpos( $relative, $slug . '/quarantine/' ) ) {
					$top = $slug . '/quarantine';
				}
				$state['by_top'][ $top ]['bytes'] = $size + (int) ( $state['by_top'][ $top ]['bytes'] ?? 0 );
				$state['by_top'][ $top ]['files'] = 1 + (int) ( $state['by_top'][ $top ]['files'] ?? 0 );

				$month = self::period_key_from_relative( $relative );
				if ( '' === $month ) {
					$month = '_other';
				}
				$state['by_month'][ $month ]['bytes'] = $size + (int) ( $state['by_month'][ $month ]['bytes'] ?? 0 );
				$state['by_month'][ $month ]['files'] = 1 + (int) ( $state['by_month'][ $month ]['files'] ?? 0 );

				if ( '' !== $ext ) {
					$state['by_ext'][ $ext ]['bytes'] = $size + (int) ( $state['by_ext'][ $ext ]['bytes'] ?? 0 );
					$state['by_ext'][ $ext ]['files'] = 1 + (int) ( $state['by_ext'][ $ext ]['files'] ?? 0 );
				}

				self::track_largest_file(
					$state['largest'],
					array(
						'relative'      => $relative,
						'size'          => $size,
						'is_derivative' => 'derivative' === $kind,
					)
				);
			}
		}

		$state['queue'] = $queue;
		if ( empty( $queue ) ) {
			$state['done'] = true;
		}
		return $state;
	}

	/**
	 * Turn a finished scan state into the result stored and rendered by the screen.
	 *
	 * @param array<string, mixed> $state Finished state.
	 * @return array<string, mixed>
	 */
	public static function footprint_finish( array $state ): array {
		$slug   = defined( 'TSOSK_UPLOADS_SLUG' ) ? (string) TSOSK_UPLOADS_SLUG : '';
		$by_top = array();
		foreach ( (array) $state['by_top'] as $name => $row ) {
			$name     = (string) $name;
			$by_top[] = array(
				'name'  => $name,
				'kind'  => self::footprint_folder_kind( $name, $slug ),
				'bytes' => (int) $row['bytes'],
				'files' => (int) $row['files'],
			);
		}
		usort(
			$by_top,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$largest = (array) $state['largest'];
		usort(
			$largest,
			static function ( array $a, array $b ): int {
				return $b['size'] <=> $a['size'];
			}
		);
		$largest = self::attach_library_items( $largest );

		$by_month = (array) $state['by_month'];
		krsort( $by_month );
		if ( isset( $by_month['_other'] ) ) {
			$other = $by_month['_other'];
			unset( $by_month['_other'] );
			$by_month['_other'] = $other;
		}

		$by_ext = (array) $state['by_ext'];
		uasort(
			$by_ext,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$quarantine_bytes = 0;
		foreach ( $by_top as $row ) {
			if ( 'quarantine' === $row['kind'] ) {
				$quarantine_bytes += $row['bytes'];
			}
		}

		return array(
			'scanned_files'    => (int) $state['files'],
			'scanned_dirs'     => (int) $state['dirs'],
			'total_bytes'      => (int) $state['total'],
			'original_bytes'   => (int) $state['original'],
			'derivative_bytes' => (int) $state['derivative'],
			'other_bytes'      => (int) $state['other'],
			'by_month'         => $by_month,
			'by_extension'     => $by_ext,
			'by_top'           => $by_top,
			'largest'          => $largest,
			'quarantine_bytes' => $quarantine_bytes,
			'unreadable'       => (array) $state['unreadable'],
			'skipped_links'    => (int) $state['links'],
			'base_dir'         => (string) $state['base'],
			'scanned_at'       => time(),
			'duration'         => max( 0, time() - (int) $state['started_at'] ),
			'truncated'        => ! empty( $state['truncated'] ),
		);
	}

	/**
	 * Group a top-level uploads folder: media (year folders, multisite sites), plugin data, quarantine or other.
	 *
	 * @param string $name Folder name ('' for files directly in uploads).
	 * @param string $slug This plugin's uploads folder name.
	 * @return string media|plugin|quarantine|root|other
	 */
	public static function footprint_folder_kind( string $name, string $slug = '' ): string {
		if ( '' === $name ) {
			return 'root';
		}
		if ( '' !== $slug && $name === $slug . '/quarantine' ) {
			return 'quarantine';
		}
		if ( preg_match( '/^\d{4}$/', $name ) || 'sites' === $name ) {
			return 'media';
		}
		$first = strstr( $name, '/', true );
		$first = false === $first ? $name : $first;
		if ( in_array( $first, self::get_protected_upload_prefixes(), true ) || preg_match( '/^(tso-|tsosk-)/', $first ) ) {
			return 'plugin';
		}
		if ( in_array( strtolower( $first ), array( 'cache', 'elementor', 'wc-logs', 'woocommerce_uploads', 'fonts', 'custom-fonts', 'ai1wm-backups', 'updraft', 'litespeed', 'wpforms', 'smush', 'revslider', 'wflogs', 'wpallimport', 'gravity_forms', 'et_temp', 'sucuri', 'w3tc', 'breeze', 'autoptimize', 'wpcf7_uploads' ), true ) ) {
			return 'plugin';
		}
		return 'other';
	}

	/**
	 * Classify a file as an original, a derivative of a sibling file, or another kind of file.
	 *
	 * A "-300x200" name only counts as a derivative when the file it was cut from sits next to it, so
	 * originals that simply have dimensions in their name are no longer counted as thumbnails. Also
	 * recognised: WebP/AVIF copies (photo.jpg.webp, photo.webp beside photo.jpg) and PDF previews.
	 *
	 * @param string              $name     File basename.
	 * @param array<string,bool>  $siblings Names of the files in the same folder.
	 * @return string original|derivative|other
	 */
	public static function classify_footprint_file( string $name, array $siblings ): string {
		$images = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

		if ( preg_match( '/^(.+\.[A-Za-z0-9]{2,5})\.(?:webp|avif)$/i', $name, $m ) && isset( $siblings[ $m[1] ] ) ) {
			return 'derivative';
		}
		if ( preg_match( '/^(.+)\.(?:webp|avif)$/i', $name, $m ) ) {
			foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $alt ) {
				if ( isset( $siblings[ $m[1] . '.' . $alt ] ) ) {
					return 'derivative';
				}
			}
		}
		if ( preg_match( '/^(.+)-\d+x\d+\.([A-Za-z0-9]+)$/', $name, $m ) ) {
			$candidates = array( $m[1] . '.' . $m[2] );
			foreach ( $images as $alt ) {
				$candidates[] = $m[1] . '.' . $alt;
			}
			if ( preg_match( '/^(.+)-pdf$/i', $m[1], $p ) ) {
				$candidates[] = $p[1] . '.pdf';
			}
			foreach ( $candidates as $candidate ) {
				if ( isset( $siblings[ $candidate ] ) ) {
					return 'derivative';
				}
			}
		}
		if ( preg_match( '/^(.+)-pdf\.(?:jpe?g|png)$/i', $name, $m ) && isset( $siblings[ $m[1] . '.pdf' ] ) ) {
			return 'derivative';
		}

		return self::is_likely_original_media( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) ? 'original' : 'other';
	}

	/**
	 * Add the Media Library item (if any) that owns each file in the largest-files list.
	 *
	 * @param array<int,array<string,mixed>> $rows Largest files.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attach_library_items( array $rows ): array {
		global $wpdb;

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['attachment_id'] = 0;
			if ( ! empty( $row['is_derivative'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					(string) $row['relative']
				)
			);
			$rows[ $i ]['attachment_id'] = $id;
		}
		return $rows;
	}

	/**
	 * Audit registered image sizes against attachment metadata and disk files.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_image_sizes(): array|WP_Error {
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

		// Seeded from currently-registered sizes, but every size name actually found while
		// scanning attachment metadata gets its own row too — including sizes no theme or
		// plugin registers any more (an old theme's thumbnails, a deactivated plugin's crops).
		// Those still take up real disk space, so they should not be silently lumped into
		// "unmatched derivatives".
		$size_stats = array();
		$size_meta  = array();
		foreach ( $registered as $size_name => $size ) {
			$size_stats[ $size_name ] = array(
				'files' => 0,
				'bytes' => 0,
			);
			$size_meta[ $size_name ]  = array(
				'width'      => (int) ( $size['width'] ?? 0 ),
				'height'     => (int) ( $size['height'] ?? 0 ),
				'crop'       => ! empty( $size['crop'] ),
				'registered' => true,
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
					$norm                 = wp_normalize_path( $attached );
					$known_files[ $norm ] = true;
					++$full_stats['files'];
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
					$file_size                 = (int) filesize( $file_path );
					$key                       = sanitize_key( (string) $size_name );

					if ( ! isset( $size_stats[ $key ] ) ) {
						$size_stats[ $key ] = array(
							'files' => 0,
							'bytes' => 0,
						);
						$size_meta[ $key ]  = array(
							'width'      => (int) ( $size_data['width'] ?? 0 ),
							'height'     => (int) ( $size_data['height'] ?? 0 ),
							'crop'       => null, // Not stored per-attachment; only known at registration time.
							'registered' => false,
						);
					}

					++$size_stats[ $key ]['files'];
					$size_stats[ $key ]['bytes'] += $file_size;
				}
			}
		}

		// Dimension → size-name lookup for the disk walk below, built from every size seen
		// above (registered or not) so an orphaned file from a since-unregistered size can
		// still be attributed by its filename dimensions, not dumped into "unmatched".
		$dim_map = array();
		foreach ( $size_meta as $name => $meta ) {
			if ( $meta['width'] > 0 && $meta['height'] > 0 && ! isset( $dim_map[ $meta['width'] . 'x' . $meta['height'] ] ) ) {
				$dim_map[ $meta['width'] . 'x' . $meta['height'] ] = $name;
			}
		}
		$queue  = array( $base );
		$walked = 0;

		while ( $queue && $walked < self::MAX_FILES ) {
			$dir = array_shift( $queue );
			if ( ! self::is_safe_scan_path( $dir, $base ) ) {
				continue;
			}

			if ( ! is_readable( $dir ) ) {
				continue;
			}
			$handle = opendir( $dir );
			if ( ! $handle ) {
				continue;
			}

			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
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
			'registered'            => $registered,
			'size_meta'             => $size_meta,
			'attachments_scanned'   => $attachments,
			'full'                  => $full_stats,
			'by_size'               => $size_stats,
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
	 * Period a file belongs to: YYYY/MM, or YYYY when the site does not organise uploads by month.
	 *
	 * @param string $relative Path relative to uploads root.
	 * @return string '' when the file is not inside a year folder.
	 */
	private static function period_key_from_relative( string $relative ): string {
		if ( preg_match( '#^(\d{4}/\d{2})/#', $relative, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#^(\d{4})/#', $relative, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Extract the YYYY/MM month key from a relative uploads path, if present.
	 *
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
	 * Determine whether a filename looks like a generated image-size derivative (e.g. -150x150).
	 *
	 * @param string $filename File basename.
	 * @return bool
	 */
	public static function is_derivative_filename( string $filename ): bool {
		return (bool) preg_match( '/-\d+x\d+\.[^.]+$/i', $filename );
	}

	/**
	 * Determine whether a file extension is a likely original media type.
	 *
	 * @param string $extension Lowercase extension.
	 * @return bool
	 */
	private static function is_likely_original_media( string $extension ): bool {
		return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'pdf', 'mp4', 'webm', 'mp3', 'wav', 'ogg' ), true );
	}

	/**
	 * Determine whether a path is safely contained within the allowed scan base.
	 *
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
	 * Determine whether a directory should be skipped during the uploads scan.
	 *
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
	public static function scan_hygiene(): array|WP_Error {
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
				if ( is_link( $path ) ) {
					// A symlink can point anywhere: list it, never offer to move or delete it.
					$items[] = self::build_hygiene_item(
						$path,
						(string) $entry,
						'uploads',
						array(
							'size'   => 0,
							'files'  => 0,
							'capped' => false,
						),
						'review',
						__( 'Symbolic link. It is not followed or measured, and it cannot be removed from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						false
					);
					continue;
				}
				$item = self::classify_uploads_top_folder( (string) $entry, $path );
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
				$order = array(
					'safe'   => 0,
					'review' => 1,
					'keep'   => 2,
				);
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
	 * Lazily initialise WP_Filesystem for quarantine move/restore operations.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function init_quarantine_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) && function_exists( 'tsosk_require_wp_admin' ) ) {
			tsosk_require_wp_admin( 'includes/file.php' );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return null;
		}

		ob_start();
		$ready = WP_Filesystem();
		ob_end_clean();

		return ( $ready && $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
	}

	/**
	 * Move a folder previously returned by scan_hygiene() into quarantine instead of deleting it immediately.
	 *
	 * @param string               $folder_id Folder id from scan results.
	 * @param array<string, mixed> $scan      Cached scan payload.
	 * @return true|WP_Error
	 */
	public static function quarantine_hygiene_folder( string $folder_id, array $scan ) {
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

		$quarantine_base = function_exists( 'tsosk_get_uploads_subdir' ) ? tsosk_get_uploads_subdir( 'quarantine' ) : '';
		if ( '' === $quarantine_base ) {
			return new WP_Error( 'quarantine_unavailable', __( 'The quarantine folder is unavailable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! is_dir( $quarantine_base ) ) {
			wp_mkdir_p( $quarantine_base );
		}
		self::protect_quarantine_dir( $quarantine_base );

		$entry_id      = wp_generate_uuid4();
		$dest_basename = $entry_id . '-' . sanitize_file_name( basename( $path ) );
		$dest          = wp_normalize_path( trailingslashit( $quarantine_base ) . $dest_basename );

		$fs = self::init_quarantine_filesystem();
		if ( ! $fs || ! $fs->move( $path, $dest ) ) {
			return new WP_Error( 'quarantine_failed', __( 'Could not move the folder to quarantine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries              = self::get_quarantine_entries();
		$entries[ $entry_id ] = array(
			'id'              => $entry_id,
			'original_path'   => $path,
			'quarantine_path' => $dest,
			'label'           => (string) ( $target['relative'] ?? basename( $path ) ),
			'size'            => (int) ( $target['size'] ?? 0 ),
			'files'           => (int) ( $target['files'] ?? 0 ),
			'quarantined_at'  => time(),
		);
		update_option( self::OPTION_QUARANTINE, $entries, false );
		self::schedule_quarantine_purge();

		return true;
	}

	/**
	 * Schedule a one-off cron event for the earliest quarantine expiry, so the promised automatic
	 * deletion also happens when nobody opens this screen. No-op when nothing is quarantined.
	 */
	public static function schedule_quarantine_purge(): void {
		$entries = self::get_quarantine_entries();
		if ( empty( $entries ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$oldest = 0;
		foreach ( $entries as $entry ) {
			$at = (int) ( $entry['quarantined_at'] ?? 0 );
			if ( $at > 0 && ( 0 === $oldest || $at < $oldest ) ) {
				$oldest = $at;
			}
		}
		if ( 0 === $oldest ) {
			return;
		}

		$due  = max( time() + HOUR_IN_SECONDS, $oldest + ( self::QUARANTINE_DAYS * DAY_IN_SECONDS ) + 300 );
		$next = wp_next_scheduled( self::CRON_QUARANTINE_PURGE );
		if ( false !== $next && (int) $next <= $due ) {
			return;
		}
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::CRON_QUARANTINE_PURGE );
		}
		wp_schedule_single_event( $due, self::CRON_QUARANTINE_PURGE );
	}

	/**
	 * All current quarantine entries, keyed by entry id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_quarantine_entries(): array {
		$entries = get_option( self::OPTION_QUARANTINE, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$clean = array();
		foreach ( $entries as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id           = sanitize_key( (string) $id );
			$entry['id']  = $id;
			$clean[ $id ] = $entry;
		}
		return $clean;
	}

	/**
	 * Aggregate stats over all quarantine entries.
	 *
	 * @return array{count: int, size: int}
	 */
	public static function get_quarantine_summary(): array {
		$entries = self::get_quarantine_entries();
		$size    = 0;
		foreach ( $entries as $entry ) {
			$size += (int) ( $entry['size'] ?? 0 );
		}
		return array(
			'count' => count( $entries ),
			'size'  => $size,
		);
	}

	/**
	 * Move a quarantined folder back to its original location.
	 *
	 * @param string $entry_id Quarantine entry id.
	 * @return true|WP_Error
	 */
	public static function restore_quarantine_entry( string $entry_id ) {
		$entry_id = sanitize_key( $entry_id );
		$entries  = self::get_quarantine_entries();
		if ( '' === $entry_id || ! isset( $entries[ $entry_id ] ) ) {
			return new WP_Error( 'invalid_entry', __( 'Unknown quarantine entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entry = $entries[ $entry_id ];
		$src   = wp_normalize_path( (string) ( $entry['quarantine_path'] ?? '' ) );
		$dest  = wp_normalize_path( (string) ( $entry['original_path'] ?? '' ) );

		if ( '' === $src || ! self::is_within_quarantine_dir( $src ) || ! is_dir( $src ) ) {
			unset( $entries[ $entry_id ] );
			update_option( self::OPTION_QUARANTINE, $entries, false );
			return new WP_Error( 'missing', __( 'The quarantined folder no longer exists.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( '' === $dest ) {
			return new WP_Error( 'invalid_entry', __( 'The original location is unknown.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// The stored location must still be one hygiene is allowed to write to (the option could have been edited).
		if ( ! self::is_allowed_hygiene_location( $dest ) ) {
			return new WP_Error( 'invalid_path', __( 'The original location is outside the allowed folders, so it cannot be restored from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( is_dir( $dest ) || file_exists( $dest ) ) {
			return new WP_Error( 'destination_exists', __( 'A folder already exists at the original location — restore manually.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		if ( ! is_dir( dirname( $dest ) ) ) {
			wp_mkdir_p( dirname( $dest ) );
		}

		$fs = self::init_quarantine_filesystem();
		if ( ! $fs || ! $fs->move( $src, $dest ) ) {
			return new WP_Error( 'restore_failed', __( 'Could not restore the folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		unset( $entries[ $entry_id ] );
		update_option( self::OPTION_QUARANTINE, $entries, false );

		return true;
	}

	/**
	 * Permanently delete one quarantined folder right now.
	 *
	 * The entry is only forgotten once the folder is really gone; if deletion fails it stays listed so the
	 * files are not left behind on disk with no way to find them again.
	 *
	 * @param string $entry_id Quarantine entry id.
	 * @return true|WP_Error
	 */
	public static function purge_quarantine_entry( string $entry_id ) {
		$entry_id = sanitize_key( $entry_id );
		$entries  = self::get_quarantine_entries();
		if ( '' === $entry_id || ! isset( $entries[ $entry_id ] ) ) {
			return new WP_Error( 'invalid_entry', __( 'Unknown quarantine entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entry = $entries[ $entry_id ];
		$path  = wp_normalize_path( (string) ( $entry['quarantine_path'] ?? '' ) );

		if ( '' !== $path && self::is_within_quarantine_dir( $path ) && ( is_dir( $path ) || is_link( $path ) ) ) {
			if ( ! self::remove_directory_tree( $path ) || is_dir( $path ) ) {
				return new WP_Error( 'delete_failed', __( 'Could not delete the quarantined folder. It stays listed so you can try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		unset( $entries[ $entry_id ] );
		update_option( self::OPTION_QUARANTINE, $entries, false );

		return true;
	}

	/**
	 * Purge quarantine entries older than QUARANTINE_DAYS. Safe to call opportunistically.
	 *
	 * @return int Number of entries purged.
	 */
	public static function purge_expired_quarantine(): int {
		$entries = self::get_quarantine_entries();
		if ( empty( $entries ) ) {
			return 0;
		}

		$cutoff = time() - ( self::QUARANTINE_DAYS * DAY_IN_SECONDS );
		$purged = 0;

		foreach ( $entries as $id => $entry ) {
			$quarantined_at = (int) ( $entry['quarantined_at'] ?? 0 );
			if ( $quarantined_at > 0 && $quarantined_at <= $cutoff && true === self::purge_quarantine_entry( $id ) ) {
				++$purged;
			}
		}

		self::schedule_quarantine_purge();

		return $purged;
	}

	/**
	 * Whether a path is inside this plugin's own quarantine folder (write-safety guard).
	 *
	 * @param string $path Absolute path.
	 */
	private static function is_within_quarantine_dir( string $path ): bool {
		$base = function_exists( 'tsosk_get_uploads_subdir' ) ? tsosk_get_uploads_subdir( 'quarantine' ) : '';
		if ( '' === $base ) {
			return false;
		}
		$base = wp_normalize_path( trailingslashit( $base ) );
		$path = wp_normalize_path( $path );
		return str_starts_with( $path, $base );
	}

	/**
	 * Deny direct web access to the quarantine folder (.htaccess + blank index).
	 *
	 * @param string $dir Absolute quarantine directory path.
	 */
	private static function protect_quarantine_dir( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		$rules    = "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, $rules );

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, '' );
		}
	}

	/**
	 * Collect extra known cache/legacy folders (e.g. .tmb) outside the uploads directory.
	 *
	 * @return array<int, array{path: string, relative: string, scope: string}>
	 */
	private static function get_extra_hygiene_paths(): array {
		$paths   = array();
		$roots   = array();
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
	 * Classify a top-level uploads folder for the hygiene report.
	 *
	 * @param string $name Top-level uploads folder name.
	 * @param string $path Absolute path.
	 * @return array<string, mixed>|null
	 */
	private static function classify_uploads_top_folder( string $name, string $path ): ?array {
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

		if ( 'sites' === $name && is_multisite() ) {
			return self::build_hygiene_item(
				$path,
				$name,
				'uploads',
				$stats,
				'keep',
				__( 'Multisite: this folder holds the media of the other sites in the network. Do not delete.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				false
			);
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
	 * Classify a known cache folder (e.g. elFinder .tmb) for the hygiene report.
	 *
	 * @param string $relative Display path.
	 * @param string $path     Absolute path.
	 * @param string $scope    uploads|wp_root|wp_subdir|wp_content.
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
	 * Build a single hygiene report item array.
	 *
	 * @param string                    $path       Absolute path.
	 * @param string                    $relative   Relative label.
	 * @param string                    $scope      Scope id.
	 * @param array{size:int,files:int} $stats Directory stats.
	 * @param string                    $confidence safe|review|keep.
	 * @param string                    $reason     Human reason.
	 * @param bool                      $deletable  Whether delete is offered.
	 * @return array<string, mixed>
	 */
	private static function build_hygiene_item( string $path, string $relative, string $scope, array $stats, string $confidence, string $reason, bool $deletable ): array {
		return array(
			'path'       => wp_normalize_path( $path ),
			'relative'   => $relative,
			'scope'      => $scope,
			'size'       => (int) ( $stats['size'] ?? 0 ),
			'files'      => (int) ( $stats['files'] ?? 0 ),
			'capped'     => ! empty( $stats['capped'] ),
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
	 * Determine whether the legacy Swiss Knife logs folder can be safely removed.
	 *
	 * @param string $legacy_logs_path Absolute legacy logs directory.
	 * @return bool
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
	 * Determine whether a top-level uploads folder name matches an installed plugin.
	 *
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
	 * Recursively measure a directory's total size and file count (symlinks are not followed).
	 *
	 * @param string $path Directory path.
	 * @return array{size: int, files: int, capped: bool} capped is true when the walk stopped at the file limit.
	 */
	private static function measure_directory( string $path ): array {
		$size   = 0;
		$files  = 0;
		$queue  = array( wp_normalize_path( $path ) );
		$cap    = 10000;
		$capped = false;

		while ( $queue ) {
			if ( $files >= $cap ) {
				$capped = true;
				break;
			}
			$dir = array_shift( $queue );
			if ( ! is_readable( $dir ) ) {
				continue;
			}
			$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $handle ) {
				continue;
			}
			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$item = wp_normalize_path( trailingslashit( $dir ) . $entry );
				if ( is_link( $item ) ) {
					continue;
				}
				if ( is_dir( $item ) ) {
					$queue[] = $item;
					continue;
				}
				if ( is_file( $item ) ) {
					++$files;
					$size += (int) @filesize( $item ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			closedir( $handle );
		}

		return array(
			'size'   => $size,
			'files'  => $files,
			'capped' => $capped,
		);
	}

	/**
	 * Determine whether a path is one of the specific folders hygiene delete/quarantine is allowed to touch.
	 *
	 * @param string $path Absolute normalized path.
	 * @return bool
	 */
	private static function is_allowed_hygiene_delete_path( string $path ): bool {
		$path = wp_normalize_path( $path );
		return '' !== $path && is_dir( $path ) && self::is_allowed_hygiene_location( $path );
	}

	/**
	 * Whether a path (which may not exist yet) is inside the locations hygiene may touch.
	 *
	 * The caller passes real paths, so the uploads base is compared both as WordPress reports it and
	 * resolved: on hosts where wp-content/uploads is a symlink the two differ.
	 *
	 * @param string $path Absolute normalized path.
	 * @return bool
	 */
	private static function is_allowed_hygiene_location( string $path ): bool {
		$path = wp_normalize_path( $path );
		if ( '' === $path ) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$bases = array( wp_normalize_path( trailingslashit( (string) $uploads['basedir'] ) ) );
			$real  = realpath( (string) $uploads['basedir'] );
			if ( false !== $real ) {
				$bases[] = wp_normalize_path( trailingslashit( $real ) );
			}
			foreach ( array_unique( $bases ) as $base ) {
				if ( str_starts_with( $path, $base ) ) {
					$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
					$top      = explode( '/', $relative )[0] ?? '';
					if ( in_array( $top, array( 'tsosk-config', 'tsosk-l10n', 'tsosk-logs', '.tmb' ), true ) ) {
						return true;
					}
				}
			}
		}

		if ( basename( $path ) === '.tmb' ) {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$content_dir  = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
				$content_real = realpath( WP_CONTENT_DIR );
				if ( dirname( $path ) === $content_dir || ( false !== $content_real && dirname( $path ) === wp_normalize_path( $content_real ) ) ) {
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
	 * Recursively delete a directory tree, restricted to the given root.
	 *
	 * @param string $dir        Absolute directory path.
	 * @param string $root_real  Resolved root path (internal recursion guard).
	 * @return bool
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

		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$fs = self::init_quarantine_filesystem();
		return $fs ? $fs->rmdir( $dir ) : false;
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

	/**
	 * Move existing derivative files for the given registered image sizes into quarantine,
	 * instead of deleting them outright — so the admin can verify the site is fine and
	 * either restore or permanently delete them afterwards.
	 *
	 * Two passes, mirroring scan_image_sizes():
	 *  1. Attachments whose `_wp_attachment_metadata['sizes']` references one of the
	 *     requested sizes: the file is moved (unless another size we are keeping shares
	 *     the exact same file — WordPress dedupes identical-dimension crops) and the size
	 *     entry is removed from the attachment metadata so WordPress stops treating it as
	 *     available. The removed entry (post id, size name, original size data) is kept
	 *     so restore can put it back exactly as it was.
	 *  2. Orphaned derivative files on disk that match one of the requested sizes'
	 *     dimensions but are not referenced by any attachment metadata.
	 *
	 * Callers are responsible for restricting $size_names to sizes the admin has
	 * already disabled for new uploads — this method does not re-check that.
	 *
	 * @param string[] $size_names Sanitized registered size names to quarantine.
	 * @return array{entry_id:string, files:int, bytes:int, updated_posts:int}|WP_Error
	 */
	public static function quarantine_image_size_files( array $size_names ): array|WP_Error {
		global $wpdb;

		$size_names = array_values( array_unique( array_filter( array_map( 'sanitize_key', $size_names ) ) ) );
		if ( empty( $size_names ) ) {
			return new WP_Error( 'tsosk_no_sizes', __( 'No image sizes selected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'tsosk_uploads', (string) $uploads['error'] );
		}
		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		if ( ! is_dir( $base ) ) {
			return new WP_Error( 'tsosk_uploads', __( 'Uploads directory was not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$quarantine_base = function_exists( 'tsosk_get_uploads_subdir' ) ? tsosk_get_uploads_subdir( 'quarantine' ) : '';
		if ( '' === $quarantine_base ) {
			return new WP_Error( 'quarantine_unavailable', __( 'The quarantine folder is unavailable.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! is_dir( $quarantine_base ) ) {
			wp_mkdir_p( $quarantine_base );
		}
		self::protect_quarantine_dir( $quarantine_base );

		$entry_id = wp_generate_uuid4();
		$dest_dir = wp_normalize_path( trailingslashit( $quarantine_base ) . 'image-sizes/' . $entry_id );
		if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'quarantine_failed', __( 'Could not create the quarantine folder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$fs = self::init_quarantine_filesystem();
		if ( ! $fs ) {
			return new WP_Error( 'quarantine_failed', __( 'Could not access the filesystem to move files to quarantine.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$size_set      = array_flip( $size_names );
		$items         = array();
		$moved_files   = 0;
		$moved_bytes   = 0;
		$updated_posts = 0;
		$seq           = 0;
		$captured_dims = array(); // Dimensions learned from metadata, for sizes no theme/plugin registers any more.

		// Pass 1: attachments that reference one of the requested sizes in their metadata.
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
				if ( ! $post_id || ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
					continue;
				}

				$attached = get_attached_file( $post_id );
				$dir      = $attached ? wp_normalize_path( trailingslashit( dirname( $attached ) ) ) : '';
				if ( ! $dir ) {
					continue;
				}

				$changed = false;

				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					$key = sanitize_key( (string) $size_name );
					if ( ! isset( $size_set[ $key ] ) || ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
						continue;
					}

					if ( ! isset( $captured_dims[ $key ] ) && ! empty( $size_data['width'] ) && ! empty( $size_data['height'] ) ) {
						$captured_dims[ $key ] = array(
							'width'  => (int) $size_data['width'],
							'height' => (int) $size_data['height'],
						);
					}

					$file_path = wp_normalize_path( $dir . $size_data['file'] );
					$real      = realpath( $file_path );

					// Scope guard: only ever touch files that actually resolve inside the uploads dir.
					if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), $base ) ) {
						unset( $metadata['sizes'][ $size_name ] );
						$changed = true;
						continue;
					}

					// Don't remove the physical file if a size we are KEEPING points at the
					// same filename (WordPress dedupes identical width/height/crop sizes).
					$shared = false;
					foreach ( $metadata['sizes'] as $other_name => $other_data ) {
						$other_key = sanitize_key( (string) $other_name );
						if ( $other_key === $key || isset( $size_set[ $other_key ] ) ) {
							continue;
						}
						if ( is_array( $other_data ) && ! empty( $other_data['file'] ) && $other_data['file'] === $size_data['file'] ) {
							$shared = true;
							break;
						}
					}

					if ( ! $shared && file_exists( $file_path ) && self::is_derivative_filename( basename( $file_path ) ) ) {
						++$seq;
						$bytes = (int) filesize( $file_path );
						$qpath = wp_normalize_path( trailingslashit( $dest_dir ) . $seq . '-' . sanitize_file_name( basename( $file_path ) ) );
						if ( $fs->move( $file_path, $qpath ) ) {
							$items[]      = array(
								'post_id'         => $post_id,
								'size_name'       => $key,
								'size_data'       => $size_data,
								'original_path'   => $file_path,
								'quarantine_path' => $qpath,
								'bytes'           => $bytes,
							);
							$moved_bytes += $bytes;
							++$moved_files;
						}
					}

					unset( $metadata['sizes'][ $size_name ] );
					$changed = true;
				}

				if ( $changed ) {
					wp_update_attachment_metadata( $post_id, $metadata );
					++$updated_posts;
				}
			}
		}

		// Pass 2: orphaned derivative files on disk (no attachment metadata reference)
		// whose dimensions match one of the requested sizes. Dimensions come from the
		// current registration when the size is still registered, and otherwise from
		// what was learned about it in pass 1 above (a size nothing registers any more
		// has no other source of width/height).
		$registered  = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$target_dims = array();
		foreach ( $size_names as $name ) {
			if ( isset( $registered[ $name ]['width'], $registered[ $name ]['height'] ) ) {
				$width  = (int) $registered[ $name ]['width'];
				$height = (int) $registered[ $name ]['height'];
			} elseif ( isset( $captured_dims[ $name ] ) ) {
				$width  = $captured_dims[ $name ]['width'];
				$height = $captured_dims[ $name ]['height'];
			} else {
				continue;
			}
			if ( $width > 0 && $height > 0 ) {
				$target_dims[ $width . 'x' . $height ] = $name;
			}
		}

		if ( $target_dims ) {
			$known_files = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows2 = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
					'_wp_attachment_metadata'
				),
				ARRAY_A
			);
			if ( is_array( $rows2 ) ) {
				foreach ( $rows2 as $row ) {
					$post_id  = absint( $row['post_id'] ?? 0 );
					$metadata = maybe_unserialize( $row['meta_value'] ?? '' );
					if ( ! $post_id || ! is_array( $metadata ) ) {
						continue;
					}
					$attached = get_attached_file( $post_id );
					if ( $attached && file_exists( $attached ) ) {
						$known_files[ wp_normalize_path( $attached ) ] = true;
					}
					if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) || ! $attached ) {
						continue;
					}
					$dir = wp_normalize_path( trailingslashit( dirname( $attached ) ) );
					foreach ( $metadata['sizes'] as $size_data ) {
						if ( is_array( $size_data ) && ! empty( $size_data['file'] ) ) {
							$known_files[ wp_normalize_path( $dir . $size_data['file'] ) ] = true;
						}
					}
				}
			}

			$queue  = array( $base );
			$walked = 0;
			while ( $queue && $walked < self::MAX_FILES ) {
				$dir = array_shift( $queue );
				if ( ! self::is_safe_scan_path( $dir, $base ) || ! is_readable( $dir ) ) {
					continue;
				}
				$handle = opendir( $dir );
				if ( ! $handle ) {
					continue;
				}
				for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = wp_normalize_path( trailingslashit( $dir ) . $entry );
					if ( is_dir( $path ) ) {
						if ( ! self::should_skip_dir( $path, $base ) ) {
							$queue[] = $path;
						}
						continue;
					}
					if ( ! is_file( $path ) || ! self::is_derivative_filename( $entry ) ) {
						continue;
					}
					++$walked;
					if ( isset( $known_files[ $path ] ) ) {
						continue;
					}
					if ( ! preg_match( '/-(\d+)x(\d+)\.[^.]+$/i', $entry, $matches ) ) {
						continue;
					}
					$dim_key = $matches[1] . 'x' . $matches[2];
					if ( ! isset( $target_dims[ $dim_key ] ) ) {
						continue;
					}
					$real = realpath( $path );
					if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), $base ) ) {
						continue;
					}
					++$seq;
					$bytes = (int) filesize( $path );
					$qpath = wp_normalize_path( trailingslashit( $dest_dir ) . $seq . '-' . sanitize_file_name( $entry ) );
					if ( $fs->move( $path, $qpath ) ) {
						$items[]      = array(
							'post_id'         => 0,
							'size_name'       => $target_dims[ $dim_key ] ?? '',
							'size_data'       => null,
							'original_path'   => $path,
							'quarantine_path' => $qpath,
							'bytes'           => $bytes,
						);
						$moved_bytes += $bytes;
						++$moved_files;
					}
				}
				closedir( $handle );
			}
		}

		if ( empty( $items ) ) {
			// Nothing was moved; remove the now-empty per-entry quarantine folder.
			if ( is_dir( $dest_dir ) ) {
				self::remove_directory_tree( $dest_dir );
			}
			return new WP_Error( 'tsosk_nothing_moved', __( 'No existing files were found for the selected sizes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entries              = self::get_sizes_quarantine_entries();
		$entries[ $entry_id ] = array(
			'id'             => $entry_id,
			'sizes'          => $size_names,
			'items'          => $items,
			'files'          => $moved_files,
			'bytes'          => $moved_bytes,
			'quarantined_at' => time(),
		);
		update_option( self::OPTION_SIZES_QUARANTINE, $entries, false );
		self::schedule_sizes_quarantine_purge();

		return array(
			'entry_id'      => $entry_id,
			'files'         => $moved_files,
			'bytes'         => $moved_bytes,
			'updated_posts' => $updated_posts,
		);
	}

	/**
	 * All current image-size quarantine entries, keyed by entry id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_sizes_quarantine_entries(): array {
		$entries = get_option( self::OPTION_SIZES_QUARANTINE, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$clean = array();
		foreach ( $entries as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id           = sanitize_key( (string) $id );
			$entry['id']  = $id;
			$clean[ $id ] = $entry;
		}
		return $clean;
	}

	/**
	 * Aggregate stats over all image-size quarantine entries.
	 *
	 * @return array{count: int, size: int}
	 */
	public static function get_sizes_quarantine_summary(): array {
		$entries = self::get_sizes_quarantine_entries();
		$size    = 0;
		foreach ( $entries as $entry ) {
			$size += (int) ( $entry['bytes'] ?? 0 );
		}
		return array(
			'count' => count( $entries ),
			'size'  => $size,
		);
	}

	/**
	 * Move a quarantined batch of image-size files back to their original locations and
	 * restore the removed entries in each attachment's `_wp_attachment_metadata`.
	 *
	 * Partial success is possible (e.g. a thumbnail was regenerated in the meantime and
	 * now occupies the original spot): whatever could not be restored stays quarantined
	 * rather than being silently dropped or overwriting a newer file.
	 *
	 * @param string $entry_id Quarantine entry id.
	 * @return array{restored:int, remaining:int}|WP_Error
	 */
	public static function restore_sizes_quarantine_entry( string $entry_id ): array|WP_Error {
		$entry_id = sanitize_key( $entry_id );
		$entries  = self::get_sizes_quarantine_entries();
		if ( '' === $entry_id || ! isset( $entries[ $entry_id ] ) ) {
			return new WP_Error( 'invalid_entry', __( 'Unknown quarantine entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entry = $entries[ $entry_id ];
		$items = is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
		if ( empty( $items ) ) {
			unset( $entries[ $entry_id ] );
			update_option( self::OPTION_SIZES_QUARANTINE, $entries, false );
			return new WP_Error( 'missing', __( 'This quarantine entry has no files left.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$uploads = wp_upload_dir();
		$base    = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) : '';

		$fs = self::init_quarantine_filesystem();
		if ( ! $fs || '' === $base ) {
			return new WP_Error( 'restore_failed', __( 'Could not access the filesystem to restore files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$remaining      = array();
		$restored_posts = array();
		$restored       = 0;

		foreach ( $items as $item ) {
			$src  = wp_normalize_path( (string) ( $item['quarantine_path'] ?? '' ) );
			$dest = wp_normalize_path( (string) ( $item['original_path'] ?? '' ) );

			if ( '' === $src || ! self::is_within_quarantine_dir( $src ) || ! file_exists( $src ) ) {
				continue; // Already gone — nothing left to restore, drop this item.
			}

			if ( '' === $dest || 0 !== strpos( $dest, $base ) ) {
				$remaining[] = $item; // Cannot safely restore outside uploads; keep it listed.
				continue;
			}

			if ( file_exists( $dest ) ) {
				// Something already occupies the original spot (e.g. thumbnails were
				// regenerated since) — never overwrite it.
				$remaining[] = $item;
				continue;
			}

			if ( ! is_dir( dirname( $dest ) ) ) {
				wp_mkdir_p( dirname( $dest ) );
			}

			if ( ! $fs->move( $src, $dest ) ) {
				$remaining[] = $item;
				continue;
			}

			$post_id   = absint( $item['post_id'] ?? 0 );
			$size_name = sanitize_key( (string) ( $item['size_name'] ?? '' ) );
			$size_data = is_array( $item['size_data'] ?? null ) ? $item['size_data'] : null;

			if ( $post_id && '' !== $size_name && $size_data ) {
				$restored_posts[ $post_id ][ $size_name ] = $size_data;
			}

			++$restored;
		}

		foreach ( $restored_posts as $post_id => $sizes_to_restore ) {
			$metadata = wp_get_attachment_metadata( $post_id );
			if ( ! is_array( $metadata ) ) {
				continue;
			}
			if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
				$metadata['sizes'] = array();
			}
			foreach ( $sizes_to_restore as $size_name => $size_data ) {
				$metadata['sizes'][ $size_name ] = $size_data;
			}
			wp_update_attachment_metadata( $post_id, $metadata );
		}

		if ( empty( $remaining ) ) {
			unset( $entries[ $entry_id ] );
		} else {
			$bytes = 0;
			foreach ( $remaining as $item ) {
				$bytes += (int) ( $item['bytes'] ?? 0 );
			}
			$entries[ $entry_id ]['items'] = $remaining;
			$entries[ $entry_id ]['files'] = count( $remaining );
			$entries[ $entry_id ]['bytes'] = $bytes;
		}
		update_option( self::OPTION_SIZES_QUARANTINE, $entries, false );

		if ( 0 === $restored ) {
			return new WP_Error( 'restore_failed', __( 'Could not restore any files — their original locations already have a file, or are no longer valid.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return array(
			'restored'  => $restored,
			'remaining' => count( $remaining ),
		);
	}

	/**
	 * Permanently delete one quarantined image-size batch right now.
	 *
	 * @param string $entry_id Quarantine entry id.
	 * @return true|WP_Error
	 */
	public static function purge_sizes_quarantine_entry( string $entry_id ) {
		$entry_id = sanitize_key( $entry_id );
		$entries  = self::get_sizes_quarantine_entries();
		if ( '' === $entry_id || ! isset( $entries[ $entry_id ] ) ) {
			return new WP_Error( 'invalid_entry', __( 'Unknown quarantine entry.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$entry = $entries[ $entry_id ];
		$items = is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
		$first = '';

		$remaining = array();
		foreach ( $items as $item ) {
			$path = wp_normalize_path( (string) ( $item['quarantine_path'] ?? '' ) );
			if ( '' === $first && '' !== $path ) {
				$first = $path;
			}
			if ( '' !== $path && self::is_within_quarantine_dir( $path ) && ( is_file( $path ) || is_link( $path ) ) ) {
				if ( ! self::delete_path_entry( $path ) ) {
					$remaining[] = $item;
				}
			}
		}

		if ( empty( $remaining ) ) {
			// Best effort: clean up the now-empty per-entry quarantine subfolder.
			$dir = '' !== $first ? dirname( $first ) : '';
			if ( '' !== $dir && self::is_within_quarantine_dir( $dir ) && is_dir( $dir ) ) {
				self::remove_directory_tree( $dir );
			}
			unset( $entries[ $entry_id ] );
		} else {
			$entries[ $entry_id ]['items'] = $remaining;
			$entries[ $entry_id ]['files'] = count( $remaining );
		}
		update_option( self::OPTION_SIZES_QUARANTINE, $entries, false );

		if ( ! empty( $remaining ) ) {
			return new WP_Error( 'delete_failed', __( 'Some quarantined files could not be deleted. They stay listed so you can try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		return true;
	}

	/**
	 * Purge image-size quarantine entries older than QUARANTINE_DAYS. Safe to call opportunistically.
	 *
	 * @return int Number of entries purged.
	 */
	public static function purge_expired_sizes_quarantine(): int {
		$entries = self::get_sizes_quarantine_entries();
		if ( empty( $entries ) ) {
			return 0;
		}

		$cutoff = time() - ( self::QUARANTINE_DAYS * DAY_IN_SECONDS );
		$purged = 0;

		foreach ( $entries as $id => $entry ) {
			$quarantined_at = (int) ( $entry['quarantined_at'] ?? 0 );
			if ( $quarantined_at > 0 && $quarantined_at <= $cutoff && true === self::purge_sizes_quarantine_entry( $id ) ) {
				++$purged;
			}
		}

		self::schedule_sizes_quarantine_purge();

		return $purged;
	}

	/**
	 * Schedule a one-off cron event for the earliest image-size quarantine expiry, so the
	 * promised automatic deletion also happens when nobody opens this screen.
	 */
	public static function schedule_sizes_quarantine_purge(): void {
		$entries = self::get_sizes_quarantine_entries();
		if ( empty( $entries ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$oldest = 0;
		foreach ( $entries as $entry ) {
			$at = (int) ( $entry['quarantined_at'] ?? 0 );
			if ( $at > 0 && ( 0 === $oldest || $at < $oldest ) ) {
				$oldest = $at;
			}
		}
		if ( 0 === $oldest ) {
			return;
		}

		$due  = max( time() + HOUR_IN_SECONDS, $oldest + ( self::QUARANTINE_DAYS * DAY_IN_SECONDS ) + 300 );
		$next = wp_next_scheduled( self::CRON_SIZES_QUARANTINE_PURGE );
		if ( false !== $next && (int) $next <= $due ) {
			return;
		}
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::CRON_SIZES_QUARANTINE_PURGE );
		}
		wp_schedule_single_event( $due, self::CRON_SIZES_QUARANTINE_PURGE );
	}
}
