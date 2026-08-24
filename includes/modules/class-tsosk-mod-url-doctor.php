<?php
/**
 * TSO Swiss Knife – Module: Site URL and HTTPS doctor.
 *
 * Read-only checks plus an optional loopback request to this site's own home URL.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Url_Doctor
 */
class TSOSK_Mod_Url_Doctor {

	/** Transient for the last probe. */
	public const TRANSIENT = 'tsosk_url_doctor_probe';

	/** @var TSOSK_Mod_Url_Doctor|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_url_doctor_probe', array( $this, 'ajax_probe' ) );
		add_action( 'wp_ajax_tsosk_url_doctor_leftovers', array( $this, 'ajax_leftovers' ) );
	}

	/**
	 * Split a URL into comparable parts.
	 *
	 * @param string $url Absolute URL.
	 * @return array{scheme:string,host:string,host_nowww:string,is_www:bool,path:string,valid:bool}
	 */
	public static function parse_url_parts( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array(
				'scheme'     => '',
				'host'       => '',
				'host_nowww' => '',
				'is_www'     => false,
				'path'       => '/',
				'valid'      => false,
			);
		}

		$host  = strtolower( (string) $parts['host'] );
		$is_www = 0 === strpos( $host, 'www.' );
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}

		return array(
			'scheme'     => strtolower( (string) ( $parts['scheme'] ?? '' ) ),
			'host'       => $host,
			'host_nowww' => $is_www ? substr( $host, 4 ) : $host,
			'is_www'     => $is_www,
			'path'       => $path,
			'valid'      => true,
		);
	}

	/**
	 * Resolve a Location header (absolute, protocol-relative, or relative) against a base URL.
	 *
	 * @param string $base     Requested URL.
	 * @param string $location Location header value.
	 */
	public static function resolve_url( string $base, string $location ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return $base;
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		if ( 0 === strpos( $location, '//' ) ) {
			$base_p = wp_parse_url( $base );
			$scheme = ( is_array( $base_p ) && ! empty( $base_p['scheme'] ) ) ? (string) $base_p['scheme'] : 'https';
			return $scheme . ':' . $location;
		}

		$base_p = wp_parse_url( $base );
		if ( ! is_array( $base_p ) || empty( $base_p['host'] ) ) {
			return $base;
		}
		$scheme = ! empty( $base_p['scheme'] ) ? (string) $base_p['scheme'] : 'https';
		$origin = $scheme . '://' . $base_p['host'];
		if ( ! empty( $base_p['port'] ) ) {
			$origin .= ':' . $base_p['port'];
		}
		if ( isset( $location[0] ) && '/' === $location[0] ) {
			return $origin . $location;
		}
		$path = isset( $base_p['path'] ) && '' !== $base_p['path'] ? (string) $base_p['path'] : '/';
		if ( ! isset( $path[0] ) || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$dir = preg_replace( '#/[^/]*$#', '/', $path );
		if ( ! is_string( $dir ) || '' === $dir ) {
			$dir = '/';
		}
		return $origin . $dir . $location;
	}

	/**
	 * Location header from a wp_remote_* response array.
	 *
	 * @param array<string, mixed> $resp HTTP API response.
	 */
	public static function header_location_from_response( array $resp ): string {
		if ( ! isset( $resp['headers'] ) ) {
			return '';
		}
		$headers = $resp['headers'];
		$loc     = '';
		if ( is_object( $headers ) ) {
			if ( method_exists( $headers, 'offsetGet' ) ) {
				$loc = $headers->offsetGet( 'location' );
			} elseif ( isset( $headers['location'] ) ) {
				$loc = $headers['location'];
			}
		} elseif ( is_array( $headers ) && isset( $headers['location'] ) ) {
			$loc = $headers['location'];
		}
		if ( is_array( $loc ) ) {
			$end = end( $loc );
			$loc = false === $end ? '' : $end;
		}
		return is_string( $loc ) ? trim( $loc ) : '';
	}

	/**
	 * Final URL after redirects. wp_remote_retrieve_header( 'location' ) is empty
	 * once WordPress has already followed the chain.
	 *
	 * @param array<string, mixed>|mixed $resp          HTTP API response.
	 * @param string                     $requested_url Original request URL.
	 */
	public static function effective_url_from_response( $resp, string $requested_url ): string {
		if ( ! is_array( $resp ) ) {
			return $requested_url;
		}

		if ( isset( $resp['http_response'] ) && is_object( $resp['http_response'] ) ) {
			$http = $resp['http_response'];
			if ( method_exists( $http, 'get_response_object' ) ) {
				$obj = $http->get_response_object();
				if ( is_object( $obj ) && isset( $obj->url ) && is_string( $obj->url ) && '' !== $obj->url ) {
					return $obj->url;
				}
			}
		}

		$location = self::header_location_from_response( $resp );
		if ( '' === $location ) {
			return $requested_url;
		}
		return self::resolve_url( $requested_url, $location );
	}

	/**
	 * Whether a transport error looks like a TLS/certificate failure (local/self-signed).
	 */
	public static function is_transport_ssl_error( string $message ): bool {
		$hay = strtolower( $message );
		foreach ( array( 'ssl', 'certificate', 'curl error 60', 'curl error 51', 'curl error 77' ) as $needle ) {
			if ( false !== strpos( $hay, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build diagnostic rows from the two WordPress addresses.
	 *
	 * @param string               $home    Home URL.
	 * @param string               $site    Site URL.
	 * @param bool                 $is_ssl  Whether the current request is HTTPS.
	 * @param array<string, mixed> $extra   Extra flags (force_ssl_admin, wp_home_const, wp_siteurl_const).
	 * @return array<int, array{status:string,label:string,detail:string,hint:string}>
	 */
	public static function diagnose_urls( string $home, string $site, bool $is_ssl, array $extra = array() ): array {
		$rows      = array();
		$home_p    = self::parse_url_parts( $home );
		$site_p    = self::parse_url_parts( $site );
		$force_ssl = ! empty( $extra['force_ssl_admin'] );

		if ( ! $home_p['valid'] || ! $site_p['valid'] ) {
			$rows[] = array(
				'status' => 'warn',
				'label'  => __( 'Saved addresses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'detail' => __( 'WordPress does not have two valid web addresses stored.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'hint'   => __( 'Open Settings → General and check WordPress Address and Site Address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
			return $rows;
		}

		$same_host = $home_p['host'] === $site_p['host'];
		$rows[]    = array(
			'status' => $same_host ? 'ok' : 'warn',
			'label'  => __( 'Public site vs admin address', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $same_host
				? __( 'Both addresses use the same domain. That is what you usually want.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: sprintf(
					/* translators: 1: home host, 2: site host */
					__( 'The public site is %1$s but the admin address is %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$home_p['host'],
					$site_p['host']
				),
			'hint'   => $same_host
				? ''
				: __( 'After a move, these two often disagree. Search & Replace can fix stored links once you know the correct domain.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$https_home = 'https' === $home_p['scheme'];
		$https_site = 'https' === $site_p['scheme'];
		$https_ok   = $https_home && $https_site;
		$rows[]     = array(
			'status' => $https_ok ? 'ok' : 'warn',
			'label'  => __( 'Padlock (HTTPS)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $https_ok
				? __( 'Both saved addresses start with https.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'At least one saved address still starts with http. Browsers will warn visitors or mix secure and insecure files.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hint'   => $https_ok
				? ''
				: __( 'Use a certificate on the server, then update both addresses to https (or run Search & Replace on a backup first).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$ssl_match = ( $https_home === $is_ssl ) || ( $is_ssl && $https_home );
		if ( $is_ssl && ! $https_home ) {
			$ssl_match = false;
		}
		if ( ! $is_ssl && $https_home ) {
			$ssl_match = false;
		}
		$rows[] = array(
			'status' => $ssl_match ? 'ok' : 'warn',
			'label'  => __( 'This visit vs saved address', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $ssl_match
				? __( 'The way you opened this page matches the saved public address (http or https).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'You opened this page with a different padlock setting than the address WordPress has saved. Logins and cookies often break in that case.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hint'   => $ssl_match
				? ''
				: __( 'Always open the admin using the same https/www form as Settings → General.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$www_same = $home_p['is_www'] === $site_p['is_www'];
		$rows[]   = array(
			'status' => $www_same ? 'ok' : 'warn',
			'label'  => __( 'www vs without www', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $www_same
				? (
					$home_p['is_www']
						? __( 'Both addresses use www.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
						: __( 'Both addresses skip www.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				)
				: __( 'One address uses www and the other does not. That usually causes extra redirects or mixed cookies.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hint'   => $www_same
				? ''
				: __( 'Pick one form (with or without www) and keep it everywhere, including Search & Replace if needed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);

		$path_note = ( $home_p['path'] !== $site_p['path'] );
		$rows[]    = array(
			'status' => 'info',
			'label'  => __( 'Folder in the address', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $path_note
				? sprintf(
					/* translators: 1: home path, 2: site path */
					__( 'Public path: %1$s. WordPress path: %2$s. This is normal when WordPress lives in a subfolder.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$home_p['path'],
					$site_p['path']
				)
				: sprintf(
					/* translators: %s: URL path */
					__( 'Both addresses use the path %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$home_p['path']
				),
			'hint'   => '',
		);

		$rows[] = array(
			'status' => $force_ssl ? 'ok' : 'info',
			'label'  => __( 'Force HTTPS for wp-admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'detail' => $force_ssl
				? __( 'FORCE_SSL_ADMIN is on. The dashboard should only load over https.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'FORCE_SSL_ADMIN is not on. That is fine if the whole site already uses https.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'hint'   => '',
		);

		$home_const = isset( $extra['wp_home_const'] ) ? (string) $extra['wp_home_const'] : '';
		$site_const = isset( $extra['wp_siteurl_const'] ) ? (string) $extra['wp_siteurl_const'] : '';
		if ( '' !== $home_const || '' !== $site_const ) {
			$rows[] = array(
				'status' => 'info',
				'label'  => __( 'Locked in wp-config.php', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'detail' => __( 'WP_HOME and/or WP_SITEURL are defined in wp-config.php, so Settings → General cannot change those addresses. This plugin never edits that file.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'hint'   => __( 'If the constants are wrong after a move, edit wp-config.php on the server (or ask hosting).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}

		return $rows;
	}

	/**
	 * HTTP leftover of the current home URL (same host, http instead of https).
	 *
	 * @param string $home Home URL.
	 * @return array{search:string,replace:string}|null
	 */
	public static function http_https_pair( string $home ): ?array {
		$home = untrailingslashit( trim( $home ) );
		if ( '' === $home ) {
			return null;
		}
		$parts = wp_parse_url( $home );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return null;
		}
		$https = set_url_scheme( $home, 'https' );
		$http  = set_url_scheme( $home, 'http' );
		if ( $http === $https ) {
			return null;
		}
		return array(
			'search'  => $http,
			'replace' => $https,
		);
	}

	/**
	 * Build a Search & Replace admin URL with search/replace prefilled (preview still required).
	 *
	 * @param string $search  Find text.
	 * @param string $replace Replace text.
	 */
	public static function search_replace_prefill_url( string $search, string $replace ): string {
		return add_query_arg(
			array(
				'page'             => 'tso-swiss-knife',
				'tab'              => 'search-replace',
				'tsosk_sr_search'  => $search,
				'tsosk_sr_replace' => $replace,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Count content rows that still contain a needle (posts, postmeta, options, comments).
	 *
	 * @param string $needle Plain substring (not regex).
	 * @return array{posts:int,postmeta:int,options:int,comments:int,total:int}
	 */
	public static function count_leftover_rows( string $needle ): array {
		$empty = array(
			'posts'    => 0,
			'postmeta' => 0,
			'options'  => 0,
			'comments' => 0,
			'total'    => 0,
		);
		$needle = trim( $needle );
		if ( '' === $needle ) {
			return $empty;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $empty;
		}

		$like = '%' . $wpdb->esc_like( $needle ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin diagnostic COUNT, no user-built SQL identifiers.
		$posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','inherit') AND post_content LIKE %s",
				$like
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				$like
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				$like
			)
		);
		$comments = 0;
		if ( isset( $wpdb->comments ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$comments = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content LIKE %s",
					$like
				)
			);
		}

		$total = $posts + $meta + $options + $comments;
		return array(
			'posts'    => $posts,
			'postmeta' => $meta,
			'options'  => $options,
			'comments' => $comments,
			'total'    => $total,
		);
	}

	/**
	 * AJAX: loopback GET of this site's home URL only.
	 */
	public function ajax_probe(): void {
		check_ajax_referer( 'tsosk_url_doctor_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$result = $this->run_probe();
		set_transient( self::TRANSIENT, $result, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: count leftover http:// copies of the current home URL in content tables.
	 */
	public function ajax_leftovers(): void {
		check_ajax_referer( 'tsosk_url_doctor_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$pair = self::http_https_pair( home_url() );
		if ( null === $pair ) {
			wp_send_json_success(
				array(
					'skip'    => true,
					'message' => __( 'The public address is not https, so there is no http leftover of the same host to count.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				)
			);
		}

		$counts = self::count_leftover_rows( $pair['search'] );
		$url    = self::search_replace_prefill_url( $pair['search'], $pair['replace'] );
		$message = sprintf(
			/* translators: 1: number of rows, 2: http URL, 3: https URL */
			__( '%1$d content rows still contain %2$s. Search & Replace can preview changing them to %3$s (nothing is written until you confirm).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$counts['total'],
			$pair['search'],
			$pair['replace']
		);
		$counts_label = sprintf(
			/* translators: 1: posts, 2: postmeta, 3: options, 4: comments */
			__( 'Posts: %1$d. Post meta: %2$d. Options: %3$d. Comments: %4$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			$counts['posts'],
			$counts['postmeta'],
			$counts['options'],
			$counts['comments']
		);

		wp_send_json_success(
			array(
				'skip'          => false,
				'message'       => $message,
				'counts'        => $counts,
				'counts_label'  => $counts_label,
				'search'        => $pair['search'],
				'replace'       => $pair['replace'],
				'replace_url'   => $url,
				'replace_label' => __( 'Open Search & Replace with these values (preview first)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			)
		);
	}

	/**
	 * @return array{ok:bool,status:int,final_url:string,message:string,ssl_relaxed:bool}
	 */
	private function run_probe(): array {
		$home = home_url( '/' );
		// Do not follow redirects: an intermediate hop could leave the site (CDN, old domain).
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'TSO-Swiss-Knife-URL-Doctor/' . TSOSK_VERSION,
		);

		$resp        = wp_remote_get( $home, $args );
		$ssl_relaxed = false;

		if ( is_wp_error( $resp ) && self::is_transport_ssl_error( $resp->get_error_message() ) ) {
			$args['sslverify'] = false;
			$resp              = wp_remote_get( $home, $args );
			$ssl_relaxed       = true;
		}

		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'          => false,
				'status'      => 0,
				'final_url'   => $home,
				'message'     => $resp->get_error_message(),
				'ssl_relaxed' => false,
			);
		}

		$code      = (int) wp_remote_retrieve_response_code( $resp );
		$final_url = self::effective_url_from_response( $resp, $home );

		$home_p  = self::parse_url_parts( $home );
		$final_p = self::parse_url_parts( $final_url );
		$host_ok = $final_p['valid'] && $final_p['host_nowww'] === $home_p['host_nowww'];

		if ( $code >= 300 && $code < 400 ) {
			$message = $host_ok
				? sprintf(
					/* translators: 1: HTTP status, 2: redirect target URL */
					__( 'This site answered with redirect status %1$d to %2$s (redirects are not followed by this check).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$code,
					$final_url
				)
				: sprintf(
					/* translators: 1: HTTP status, 2: redirect target host */
					__( 'This site answered with redirect status %1$d toward a different domain (%2$s). Check CDN or leftover URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$code,
					$final_p['valid'] ? $final_p['host'] : $final_url
				);
		} elseif ( $code >= 200 && $code < 300 && $host_ok ) {
			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'This site answered with status %d. The public address looks reachable from the server.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$code
			);
		} elseif ( $final_p['valid'] && ! $host_ok ) {
			$message = sprintf(
				/* translators: %s: final URL host */
				__( 'The request ended on a different domain (%s). Check redirects, CDN, or a leftover old address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$final_p['host']
			);
		} else {
			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'The public address answered with status %d. That is not a normal success page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$code
			);
		}

		if ( $ssl_relaxed ) {
			$message .= ' ' . __( 'The first request failed a certificate check; the retry skipped TLS verification (typical on local or self-signed sites).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		return array(
			'ok'          => ( $code >= 200 && $code < 300 && $host_ok ),
			'status'      => $code,
			'final_url'   => esc_url_raw( $final_url ),
			'message'     => $message,
			'ssl_relaxed' => $ssl_relaxed,
		);
	}

	public function render(): void {
		$nonce = wp_create_nonce( 'tsosk_url_doctor_nonce' );
		$home  = home_url();
		$site  = site_url();
		$rows  = self::diagnose_urls(
			$home,
			$site,
			is_ssl(),
			array(
				'force_ssl_admin'  => function_exists( 'force_ssl_admin' ) && force_ssl_admin(),
				'wp_home_const'    => defined( 'WP_HOME' ) ? (string) WP_HOME : '',
				'wp_siteurl_const' => defined( 'WP_SITEURL' ) ? (string) WP_SITEURL : '',
			)
		);
		$probe = get_transient( self::TRANSIENT );
		if ( ! is_array( $probe ) ) {
			$probe = null;
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'This screen explains whether the two addresses WordPress has saved still match how you open the site (https, www, folder). It does not change the database. Use Search & Replace only after you know the correct address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Addresses WordPress has saved', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="tsosk-kv-table">
				<tr>
					<th><?php esc_html_e( 'Public site (Home)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $home ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'WordPress address (Site URL)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><code><?php echo esc_html( $site ); ?></code></td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'What we found', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<table class="widefat tsosk-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Result', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'What it means', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<span class="tsosk-badge <?php echo esc_attr( $this->badge_class( $row['status'] ) ); ?>">
									<?php echo esc_html( $this->badge_label( $row['status'] ) ); ?>
								</span>
								<strong style="margin-left:6px;"><?php echo esc_html( $row['label'] ); ?></strong>
							</td>
							<td><?php echo esc_html( $row['detail'] ); ?></td>
							<td><?php echo esc_html( $row['hint'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Can the server reach its own public page?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Optional. Only runs when you click the button. It asks this site’s own home address from the server (the same idea as Site Health). No other website is contacted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="tsosk-url-doctor-probe" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Check this site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-url-doctor-msg"></span>
			</p>
			<div id="tsosk-url-doctor-probe-result">
				<?php if ( is_array( $probe ) && isset( $probe['message'] ) ) : ?>
					<p>
						<span class="tsosk-badge <?php echo ! empty( $probe['ok'] ) ? 'tsosk-badge-ok' : 'tsosk-badge-warn'; ?>">
							<?php echo ! empty( $probe['ok'] ) ? esc_html__( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Needs attention', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</span>
						<?php echo esc_html( (string) $probe['message'] ); ?>
						<?php if ( ! empty( $probe['final_url'] ) ) : ?>
							<br><code><?php echo esc_html( (string) $probe['final_url'] ); ?></code>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Leftover http addresses in the database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Optional. Counts posts, post meta, options, and comments that still contain the http version of this site’s public address. It does not change anything. Use Search & Replace afterwards — always preview first.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="tsosk-url-doctor-leftovers" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Count leftover http addresses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-url-doctor-leftovers-msg"></span>
			</p>
			<div id="tsosk-url-doctor-leftovers-result"></div>
		</div>
		<?php
	}

	/**
	 * @param string $status ok|warn|info.
	 */
	private function badge_class( string $status ): string {
		if ( 'ok' === $status ) {
			return 'tsosk-badge-ok';
		}
		if ( 'warn' === $status ) {
			return 'tsosk-badge-warn';
		}
		return 'tsosk-badge-info';
	}

	/**
	 * @param string $status ok|warn|info.
	 */
	private function badge_label( string $status ): string {
		if ( 'ok' === $status ) {
			return __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( 'warn' === $status ) {
			return __( 'Check', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'Info', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}
}
