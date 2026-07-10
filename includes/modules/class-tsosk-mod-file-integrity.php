<?php
/**
 * TSO Swiss Knife – Module: Core File Integrity.
 *
 * Compares WordPress core files against the official MD5 checksums
 * published by the WordPress.org API. Detects modified, missing and
 * unexpected files in wp-admin/, wp-includes/ and the root directory.
 *
 * Uses a 24-hour transient cache to avoid hammering the remote API.
 * The scan itself runs server-side via AJAX so large installs never
 * time out the page load.
 *
 * @package TSO_Swiss_Knife
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_File_Integrity
 */
class TSOSK_Mod_File_Integrity {

	/** Transient key for cached scan results. */
	private const TRANSIENT_RESULTS  = 'tsosk_fi_results';

	/** Transient key for cached checksums from WordPress.org API. */
	private const TRANSIENT_CHECKSUMS = 'tsosk_fi_checksums';

	/** Option key for user-ignored files. */
	private const OPTION_IGNORED = 'tsosk_fi_ignored';

	/** Option key for last scan results (persists across transient eviction). */
	private const OPTION_RESULTS = 'tsosk_fi_last_results';

	/** WordPress.org checksums API endpoint. */
	private const API_URL = 'https://api.wordpress.org/core/checksums/1.0/';

	/** @var TSOSK_Mod_File_Integrity|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_File_Integrity
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_fi_scan',           array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_tsosk_fi_ignore',         array( $this, 'ajax_ignore' ) );
		add_action( 'wp_ajax_tsosk_fi_unignore',       array( $this, 'ajax_unignore' ) );
		add_action( 'wp_ajax_tsosk_fi_clear_cache',    array( $this, 'ajax_clear_cache' ) );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX: run (or return cached) file integrity scan.
	 *
	 * Accepts optional POST param `force` (1) to bypass the cache.
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'tsosk_fi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$force = ! empty( $_POST['force'] );

		if ( ! $force ) {
			$cached = $this->get_stored_results();
			if ( is_array( $cached ) ) {
				$cached['from_cache'] = true;
				$cached['html']       = $this->render_results( $cached, $this->get_ignored(), wp_create_nonce( 'tsosk_fi_nonce' ), false );
				wp_send_json_success( $cached );
			}
		}

		$result = $this->run_scan();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$result['html'] = $this->render_results( $result, $this->get_ignored(), wp_create_nonce( 'tsosk_fi_nonce' ), false );
		$n_issues         = count( $result['modified'] ?? array() ) + count( $result['missing'] ?? array() ) + count( $result['added'] ?? array() );
		TSOSK_Activity_Log::log(
			'file-integrity',
			'scan',
			sprintf(
				/* translators: %d: number of issues found */
				__( 'File integrity scan completed (%d issue(s)).', 'tso-swiss-knife' ),
				$n_issues
			)
		);
		wp_send_json_success( $result );
	}

	/**
	 * Whether a relative path came from the latest stored scan results.
	 *
	 * @param string $file Relative file path.
	 * @return bool
	 */
	private function is_scannable_ignore_path( string $file ): bool {
		$file = ltrim( str_replace( '\\', '/', $file ), '/' );
		if ( '' === $file || str_contains( $file, '..' ) || str_starts_with( $file, '/' ) ) {
			return false;
		}

		$cached = $this->get_stored_results();
		if ( ! is_array( $cached ) ) {
			return false;
		}

		$known = array();
		foreach ( array( 'modified', 'missing', 'added' ) as $bucket ) {
			foreach ( $cached[ $bucket ] ?? array() as $row ) {
				if ( ! empty( $row['file'] ) ) {
					$known[] = (string) $row['file'];
				}
			}
		}

		return in_array( $file, $known, true );
	}

	/**
	 * AJAX: add a file path to the ignored list.
	 */
	public function ajax_ignore(): void {
		check_ajax_referer( 'tsosk_fi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' === $file ) {
			wp_send_json_error( __( 'No file specified.', 'tso-swiss-knife' ) );
		}
		if ( ! $this->is_scannable_ignore_path( $file ) ) {
			wp_send_json_error( __( 'Invalid file path. Only files from the latest scan can be ignored.', 'tso-swiss-knife' ) );
		}

		$ignored   = $this->get_ignored();
		$ignored[] = $file;
		update_option( self::OPTION_IGNORED, array_values( array_unique( $ignored ) ), false );

		// Invalidate cached results so the ignored file disappears on next load.
		delete_transient( self::TRANSIENT_RESULTS );

		TSOSK_Activity_Log::log(
			'file-integrity',
			'update',
			__( 'File added to ignore list.', 'tso-swiss-knife' ),
			array( 'file' => $file )
		);

		wp_send_json_success( __( 'File ignored. Re-scan to update results.', 'tso-swiss-knife' ) );
	}

	/**
	 * AJAX: remove a file from the ignored list.
	 */
	public function ajax_unignore(): void {
		check_ajax_referer( 'tsosk_fi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$file    = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$ignored = $this->get_ignored();
		$ignored = array_values( array_filter( $ignored, static fn( $f ) => $f !== $file ) );
		update_option( self::OPTION_IGNORED, $ignored, false );
		delete_transient( self::TRANSIENT_RESULTS );

		TSOSK_Activity_Log::log(
			'file-integrity',
			'delete',
			__( 'File removed from ignore list.', 'tso-swiss-knife' ),
			array( 'file' => $file )
		);

		wp_send_json_success( __( 'File removed from ignore list.', 'tso-swiss-knife' ) );
	}

	/**
	 * AJAX: clear cached checksums and results, force fresh API fetch on next scan.
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'tsosk_fi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		delete_transient( self::TRANSIENT_RESULTS );
		delete_transient( self::TRANSIENT_CHECKSUMS );
		delete_option( self::OPTION_RESULTS );
		TSOSK_Activity_Log::log( 'file-integrity', 'delete', __( 'File integrity scan cache cleared.', 'tso-swiss-knife' ) );
		wp_send_json_success( __( 'Cache cleared. Next scan will fetch fresh checksums from WordPress.org.', 'tso-swiss-knife' ) );
	}

	// ── Scan logic ────────────────────────────────────────────────────────────

	/**
	 * Execute the full scan and return a results array.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function run_scan() {
		// Allow up to 2 minutes for large installs; wp-cron and CLI are not affected.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- required to prevent timeout on large installs; wp-cron and CLI contexts are not affected.
		@set_time_limit( 120 );

		$checksums = $this->fetch_checksums();
		if ( is_wp_error( $checksums ) ) {
			return $checksums;
		}

		$ignored  = $this->get_ignored();
		$abspath  = wp_normalize_path( ABSPATH );
		$modified = array();
		$missing  = array();
		$added    = array();

		// ── Check every file listed in the official checksums ──────────────
		foreach ( $checksums as $rel_path => $expected_md5 ) {
			// Skip non-PHP root files that differ by installation (e.g. wp-config-sample.php is OK to skip).
			if ( in_array( $rel_path, array( 'wp-config-sample.php', 'php.ini' ), true ) ) {
				continue;
			}

			if ( in_array( $rel_path, $ignored, true ) ) {
				continue;
			}

			$abs = $abspath . $rel_path;

			if ( ! file_exists( $abs ) ) {
				$missing[] = array(
					'file'     => $rel_path,
					'expected' => $expected_md5,
					'actual'   => '',
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$actual_md5 = md5_file( $abs );
			if ( false !== $actual_md5 && strtolower( $actual_md5 ) !== strtolower( $expected_md5 ) ) {
				$modified[] = array(
					'file'     => $rel_path,
					'expected' => $expected_md5,
					'actual'   => $actual_md5,
					'size'     => (int) filesize( $abs ),
					'mtime'    => (int) filemtime( $abs ),
				);
			}
		}

		// ── Detect unexpected files in wp-admin/ and wp-includes/ ──────────
		// (files that exist on disk but are NOT in the official checksums)
		$scan_dirs = array( 'wp-admin', 'wp-includes' );
		foreach ( $scan_dirs as $dir ) {
			$full_dir = $abspath . $dir;
			if ( ! is_dir( $full_dir ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $full_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $file_info ) {
				try {
					if ( ! $file_info->isFile() ) {
						continue;
					}
				} catch ( RuntimeException $e ) {
					continue; // Unreadable entry — skip.
				}
				// Safety cap: never scan more than 2000 unexpected files per directory.
				if ( count( $added ) >= 2000 ) {
					break;
				}
				$abs_path = wp_normalize_path( (string) $file_info->getRealPath() );
				$rel      = ltrim( str_replace( $abspath, '', $abs_path ), '/' );

				if ( in_array( $rel, $ignored, true ) ) {
					continue;
				}
				// If this file is NOT in the official checksums, it's unexpected.
				if ( ! array_key_exists( $rel, $checksums ) ) {
					$added[] = array(
						'file'  => $rel,
						'size'  => (int) $file_info->getSize(),
						'mtime' => (int) $file_info->getMTime(),
					);
				}
			}
		}

		$result = array(
			'modified'   => $modified,
			'missing'    => $missing,
			'added'      => $added,
			'total'      => count( $checksums ),
			'scanned_at' => time(),
			'wp_version' => get_bloginfo( 'version' ),
			'from_cache' => false,
		);

		$this->store_results( $result );

		return $result;
	}

	/**
	 * Read cached scan results (transient first, then persistent option).
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_stored_results(): ?array {
		$cached = get_transient( self::TRANSIENT_RESULTS );
		if ( is_array( $cached ) && ! empty( $cached['scanned_at'] ) ) {
			return $cached;
		}
		$stored = get_option( self::OPTION_RESULTS, array() );
		if ( is_array( $stored ) && ! empty( $stored['scanned_at'] ) ) {
			set_transient( self::TRANSIENT_RESULTS, $stored, DAY_IN_SECONDS );
			return $stored;
		}
		return null;
	}

	/**
	 * Persist scan results in transient and option.
	 *
	 * @param array<string,mixed> $result Scan data.
	 */
	private function store_results( array $result ): void {
		set_transient( self::TRANSIENT_RESULTS, $result, DAY_IN_SECONDS );
		update_option( self::OPTION_RESULTS, $result, false );
	}

	/**
	 * Fetch and cache official WordPress core checksums from WordPress.org API.
	 *
	 * @return array<string,string>|WP_Error Map of relative-path => MD5 hash.
	 */
	private function fetch_checksums() {
		$cached = get_transient( self::TRANSIENT_CHECKSUMS );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$version = get_bloginfo( 'version' );
		$locale  = get_locale();

		$url      = add_query_arg( array( 'version' => $version, 'locale' => $locale ), self::API_URL );
		$response = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => true ) );

		if ( is_wp_error( $response ) ) {
			// Retry with en_US if locale-specific request failed.
			if ( 'en_US' !== $locale ) {
				$url_en   = add_query_arg( array( 'version' => $version, 'locale' => 'en_US' ), self::API_URL );
				$response = wp_remote_get( $url_en, array( 'timeout' => 30, 'sslverify' => true ) );
			}
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'api_error',
					/* translators: %s: error message */
					sprintf( __( 'Could not reach WordPress.org checksums API: %s', 'tso-swiss-knife' ), $response->get_error_message() )
				);
			}
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'WordPress.org API returned HTTP %d.', 'tso-swiss-knife' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			return new WP_Error( 'api_error', __( 'Invalid response from WordPress.org API.', 'tso-swiss-knife' ) );
		}

		$checksums = $data['checksums'];
		set_transient( self::TRANSIENT_CHECKSUMS, $checksums, DAY_IN_SECONDS );

		return $checksums;
	}

	/**
	 * Get the list of user-ignored relative file paths.
	 *
	 * @return string[]
	 */
	private function get_ignored(): array {
		$v = get_option( self::OPTION_IGNORED, array() );
		return is_array( $v ) ? $v : array();
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the module tab content.
	 */
	public function render(): void {
		$nonce   = wp_create_nonce( 'tsosk_fi_nonce' );
		$ignored = $this->get_ignored();

		// Check for a cached scan so we can display it immediately.
		$cached    = $this->get_stored_results();
		$has_cache = is_array( $cached );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Read-only security check: compares WordPress core files (wp-admin/, wp-includes/, root PHP) against official MD5 checksums from WordPress.org. It reports differences — it never installs, deletes, restores or downloads any file on your server.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-guide-card">
			<h3 class="tsosk-guide-title"><?php esc_html_e( 'What this tool does and does NOT do', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-guide-grid">
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'It does', 'tso-swiss-knife' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Fetch checksums from WordPress.org (cached 24 h).', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Compare hashes and list modified, missing or unexpected core files.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Let you ignore known false positives.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Remember the last scan when you leave and return to this tab.', 'tso-swiss-knife' ); ?></li>
					</ul>
				</div>
				<div class="tsosk-guide-block">
					<h4><?php esc_html_e( 'It does NOT', 'tso-swiss-knife' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Replace a dedicated malware scanner — use a security plugin for deep malware analysis.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Scan, install or remove themes in wp-content/themes/.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Scan plugins, uploads or any wp-content/ folder.', 'tso-swiss-knife' ); ?></li>
						<li><?php esc_html_e( 'Automatically restore or download files — you must fix issues manually.', 'tso-swiss-knife' ); ?></li>
					</ul>
				</div>
			</div>
		</div>

		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'If you suddenly see many default WordPress themes (Twenty Twenty-*, etc.), this tool did not install them. They usually come from a WordPress core update (the release ZIP bundles default themes), a hosting installer, a staging sync, or another plugin — not from this scan.', 'tso-swiss-knife' ); ?>
		</div>

		<?php
		// Show WP version being checked.
		$wp_ver        = get_bloginfo( 'version' );
		$is_prerelease = (bool) preg_match( '/-(?:alpha|beta|RC|dev)/i', $wp_ver );
		?>
		<?php if ( $is_prerelease ) : ?>
		<div class="tsosk-notice tsosk-notice-warn">
			<?php
			printf(
				/* translators: %s: WordPress version */
				esc_html__( 'You are running a pre-release version of WordPress (%s). The WordPress.org API may not have checksums for this version yet, which will cause the scan to fail. This is expected behaviour on alpha/beta/RC builds.', 'tso-swiss-knife' ),
				esc_html( $wp_ver )
			);
			?>
		</div>
		<?php endif; ?>
		<div class="tsosk-toolbar" style="gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
			<span class="tsosk-badge tsosk-badge-info">
				<?php
				printf(
					/* translators: %s: WordPress version number */
					esc_html__( 'WordPress %s', 'tso-swiss-knife' ),
					esc_html( $wp_ver )
				);
				?>
			</span>
			<button class="button button-primary" id="tsosk-fi-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>"
			        data-force="0">
				<?php esc_html_e( 'Scan Core Files', 'tso-swiss-knife' ); ?>
			</button>
			<button class="button" id="tsosk-fi-force-scan"
			        data-nonce="<?php echo esc_attr( $nonce ); ?>"
			        data-force="1"
			        title="<?php esc_attr_e( 'Force fresh scan — ignores the 24-hour cache', 'tso-swiss-knife' ); ?>">
				<?php esc_html_e( 'Force Re-scan', 'tso-swiss-knife' ); ?>
			</button>
			<span class="tsosk-ajax-msg" id="tsosk-fi-msg"></span>
		</div>

		<!-- Progress bar (hidden until scan starts) -->
		<div id="tsosk-fi-progress" style="display:none;margin-bottom:16px;">
			<div style="background:var(--wp-admin-theme-color,#2271b1);height:4px;border-radius:2px;
			            width:0;transition:width .3s;" id="tsosk-fi-bar"></div>
			<p style="font-size:12px;color:#666;margin:4px 0 0;" id="tsosk-fi-progress-label">
				<?php esc_html_e( 'Connecting to WordPress.org API…', 'tso-swiss-knife' ); ?>
			</p>
		</div>

		<!-- Results area -->
		<div id="tsosk-fi-results">
			<?php if ( $has_cache ) : ?>
				<?php $this->render_results( $cached, $ignored, $nonce, true ); ?>
			<?php else : ?>
				<div class="tsosk-notice tsosk-notice-info" id="tsosk-fi-placeholder">
					<?php esc_html_e( 'No scan has been run yet. Click «Scan Core Files» to begin.', 'tso-swiss-knife' ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $ignored ) ) : ?>
		<div class="tsosk-card" id="tsosk-fi-ignored-card" style="margin-top:20px;">
			<h3><?php esc_html_e( 'Ignored Files', 'tso-swiss-knife' ); ?> (<?php echo esc_html( (string) count( $ignored ) ); ?>)</h3>
			<p class="description">
				<?php esc_html_e( 'These files are excluded from scan results. Remove them from this list to include them again.', 'tso-swiss-knife' ); ?>
			</p>
			<table class="widefat tsosk-table" id="tsosk-fi-ignored-table">
				<thead><tr>
					<th><?php esc_html_e( 'File', 'tso-swiss-knife' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $ignored as $ign ) : ?>
					<tr data-file="<?php echo esc_attr( $ign ); ?>">
						<td class="tsosk-code"><?php echo esc_html( $ign ); ?></td>
						<td>
							<button class="button button-small tsosk-fi-unignore"
							        data-file="<?php echo esc_attr( $ign ); ?>"
							        data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Remove', 'tso-swiss-knife' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render scan results HTML (used both for initial server-side render and AJAX response).
	 *
	 * @param array    $data    Scan result array.
	 * @param string[] $ignored Ignored files list.
	 * @param string   $nonce   Security nonce.
	 * @param bool     $echo    Whether to echo (true) or return (false).
	 * @return string HTML if $echo is false.
	 */
	public function render_results( array $data, array $ignored, string $nonce, bool $echo = false ): string {
		$modified = $data['modified'] ?? array();
		$missing  = $data['missing']  ?? array();
		$added    = $data['added']    ?? array();
		$total    = (int) ( $data['total']      ?? 0 );
		$scanned  = (int) ( $data['scanned_at'] ?? 0 );
		$from_c   = ! empty( $data['from_cache'] );
		$wp_ver   = sanitize_text_field( $data['wp_version'] ?? '' );

		$n_issues = count( $modified ) + count( $missing ) + count( $added );

		ob_start();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Scan Summary', 'tso-swiss-knife' ); ?></h3>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:4px;">
				<span>
					<?php
					printf(
						/* translators: %d: total files checked */
						esc_html__( '%d core files checked', 'tso-swiss-knife' ),
						(int) $total
					);
					?>
				</span>
				<?php if ( $scanned ) : ?>
				<span style="color:#666;font-size:12px;">
					<?php
					printf(
						/* translators: 1: date, 2: time */
						esc_html__( 'Last scan: %1$s at %2$s', 'tso-swiss-knife' ),
						esc_html( gmdate( 'Y-m-d', $scanned ) ),
						esc_html( gmdate( 'H:i', $scanned ) )
					);
					?>
					<?php if ( $from_c ) : ?>
					<span class="tsosk-badge tsosk-badge-info" style="font-size:11px;margin-left:6px;">
						<?php esc_html_e( 'Cached', 'tso-swiss-knife' ); ?>
					</span>
					<?php endif; ?>
				</span>
				<?php endif; ?>
				<?php if ( $wp_ver ) : ?>
				<span class="tsosk-badge tsosk-badge-core">WordPress <?php echo esc_html( $wp_ver ); ?></span>
				<?php endif; ?>
			</div>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
				<?php
				$status_badge = $n_issues === 0 ? 'tsosk-badge-ok' : 'tsosk-badge-warn';
				$status_label = $n_issues === 0
					? __( 'All files match — no issues detected', 'tso-swiss-knife' )
					: sprintf(
						/* translators: %d: number of issues */
						__( '%d issue(s) found', 'tso-swiss-knife' ),
						$n_issues
					);
				?>
				<span class="tsosk-badge <?php echo esc_attr( $status_badge ); ?>" style="font-size:13px;padding:4px 12px;">
					<?php echo esc_html( $status_label ); ?>
				</span>
				<?php if ( count( $modified ) > 0 ) : ?>
				<span class="tsosk-badge tsosk-badge-warn">
					<?php
					printf(
						/* translators: %d: count */
						esc_html__( '%d modified', 'tso-swiss-knife' ),
						count( $modified )
					);
					?>
				</span>
				<?php endif; ?>
				<?php if ( count( $missing ) > 0 ) : ?>
				<span class="tsosk-badge" style="background:#fcebeb;color:#a32d2d;">
					<?php
					printf(
						/* translators: %d: count */
						esc_html__( '%d missing', 'tso-swiss-knife' ),
						count( $missing )
					);
					?>
				</span>
				<?php endif; ?>
				<?php if ( count( $added ) > 0 ) : ?>
				<span class="tsosk-badge" style="background:#eeedfe;color:#3c3489;">
					<?php
					printf(
						/* translators: %d: count */
						esc_html__( '%d unexpected', 'tso-swiss-knife' ),
						count( $added )
					);
					?>
				</span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $n_issues === 0 ) : ?>
		<div class="tsosk-notice tsosk-notice-info">
			<strong>✓ <?php esc_html_e( 'All WordPress core files are intact.', 'tso-swiss-knife' ); ?></strong>
			<?php esc_html_e( 'No modified, missing or unexpected files were found in wp-admin/ or wp-includes/.', 'tso-swiss-knife' ); ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $modified ) ) : ?>
		<div class="tsosk-card">
			<h3 style="color:#b45309;">
				⚠ <?php esc_html_e( 'Modified Files', 'tso-swiss-knife' ); ?> (<?php echo esc_html( (string) count( $modified ) ); ?>)
			</h3>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php esc_html_e( 'These files exist on your server but their MD5 hash does not match the official WordPress release. This could mean the file was legitimately edited (e.g. by a plugin installer), infected by malware, or corrupted. Review each file before taking action.', 'tso-swiss-knife' ); ?>
				<br><strong><?php esc_html_e( 'To restore:', 'tso-swiss-knife' ); ?></strong>
				<?php esc_html_e( 'Download the same WordPress version from wordpress.org/download/releases/ and replace the affected files via FTP/SFTP or the Hosting file manager.', 'tso-swiss-knife' ); ?>
			</div>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Expected MD5', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Actual MD5', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $modified as $f ) : ?>
						<tr class="tsosk-row-warn">
							<td class="tsosk-code" style="word-break:break-all;"><?php echo esc_html( $f['file'] ); ?></td>
							<td><?php echo esc_html( size_format( (int) $f['size'] ) ); ?></td>
							<td style="white-space:nowrap;"><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $f['mtime'] ) ); ?></td>
							<td class="tsosk-code" style="font-size:11px;color:#666;"><?php echo esc_html( substr( (string) $f['expected'], 0, 12 ) . '…' ); ?></td>
							<td class="tsosk-code" style="font-size:11px;color:#b45309;"><?php echo esc_html( substr( (string) $f['actual'], 0, 12 ) . '…' ); ?></td>
							<td>
								<button class="button button-small tsosk-fi-ignore"
								        data-file="<?php echo esc_attr( $f['file'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Ignore', 'tso-swiss-knife' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $missing ) ) : ?>
		<div class="tsosk-card">
			<h3 style="color:#a32d2d;">
				✕ <?php esc_html_e( 'Missing Files', 'tso-swiss-knife' ); ?> (<?php echo esc_html( (string) count( $missing ) ); ?>)
			</h3>
			<div class="tsosk-notice tsosk-notice-warn">
				<?php esc_html_e( 'These files are listed in the official WordPress core checksums but were not found on your server. Only worry if the path is inside wp-admin/ or wp-includes/ and the site shows errors. This scan never downloads or restores missing files.', 'tso-swiss-knife' ); ?>
			</div>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Expected MD5', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $missing as $f ) : ?>
						<tr>
							<td class="tsosk-code" style="word-break:break-all;"><?php echo esc_html( $f['file'] ); ?></td>
							<td class="tsosk-code" style="font-size:11px;color:#666;"><?php echo esc_html( substr( (string) $f['expected'], 0, 12 ) . '…' ); ?></td>
							<td>
								<button class="button button-small tsosk-fi-ignore"
								        data-file="<?php echo esc_attr( $f['file'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Ignore', 'tso-swiss-knife' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $added ) ) : ?>
		<div class="tsosk-card">
			<h3 style="color:#534ab7;">
				+ <?php esc_html_e( 'Unexpected Files', 'tso-swiss-knife' ); ?> (<?php echo esc_html( (string) count( $added ) ); ?>)
			</h3>
			<div class="tsosk-notice tsosk-notice-info">
				<?php esc_html_e( 'These files exist inside wp-admin/ or wp-includes/ but are not listed in the official WordPress release checksums. This is usually harmless — some plugins and server environments add helper files to these directories. Only investigate files you do not recognise and that were added at an unexpected time.', 'tso-swiss-knife' ); ?>
			</div>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Size', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'tso-swiss-knife' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $added as $f ) : ?>
						<tr>
							<td class="tsosk-code" style="word-break:break-all;"><?php echo esc_html( $f['file'] ); ?></td>
							<td><?php echo esc_html( size_format( (int) $f['size'] ) ); ?></td>
							<td style="white-space:nowrap;"><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $f['mtime'] ) ); ?></td>
							<td>
								<button class="button button-small tsosk-fi-ignore"
								        data-file="<?php echo esc_attr( $f['file'] ); ?>"
								        data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Ignore', 'tso-swiss-knife' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>
		<?php
		$html = (string) ob_get_clean();
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
		}
		return $html;
	}
}
