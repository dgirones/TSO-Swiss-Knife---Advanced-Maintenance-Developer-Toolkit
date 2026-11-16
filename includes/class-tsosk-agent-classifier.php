<?php
/**
 * TSO Swiss Knife – Request origin helper for the 404 Monitor.
 *
 * Classifies the User-Agent of a request (search engine, SEO crawler, AI crawler, script, browser…),
 * tells where a request came from (this site, an external site, a search engine, a hotlink) and can
 * confirm that an IP really belongs to the crawler it claims to be (reverse + forward DNS).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Agent_Classifier
 */
class TSOSK_Agent_Classifier {

	/** Kinds that count as automated traffic (not a person following a broken link). */
	public const BOT_KINDS = array( 'search', 'seo', 'ai', 'social', 'tool', 'headless', 'bot' );

	/** Reverse-DNS suffix of Google's user-triggered fetchers; "google" is prepended where it is used (a hostname check, not a loaded resource). */
	private const GOOGLE_FETCH_SUFFIX = 'usercontent.com';

	/**
	 * Known crawlers and tools: pattern, label, kind and (optional) host domains a genuine request resolves to.
	 *
	 * @return array<int, array{0:string,1:string,2:string,3:array<int,string>}>
	 */
	private static function signatures(): array {
		return array(
			// Search engines (verifiable by reverse DNS).
			// Host lists are reverse-DNS suffixes only: nothing is loaded from them.
			array( '/googlebot|google-inspectiontool|googleother|storebot-google|adsbot-google|mediapartners-google|apis-google/i', 'Googlebot', 'search', array( 'googlebot.com', 'google.com', 'google' . self::GOOGLE_FETCH_SUFFIX ) ),
			array( '/bingbot|bingpreview|msnbot/i', 'Bingbot', 'search', array( 'search.msn.com' ) ),
			array( '/slurp/i', 'Yahoo Slurp', 'search', array( 'crawl.yahoo.net' ) ),
			array( '/baiduspider/i', 'Baiduspider', 'search', array( 'baidu.com', 'baidu.jp' ) ),
			array( '/yandex(bot|images|mobilebot|metrika)/i', 'YandexBot', 'search', array( 'yandex.ru', 'yandex.net', 'yandex.com' ) ),
			array( '/applebot/i', 'Applebot', 'search', array( 'applebot.apple.com' ) ),
			array( '/petalbot/i', 'PetalBot', 'search', array( 'petalsearch.com', 'aspiegel.com' ) ),
			array( '/seznambot/i', 'SeznamBot', 'search', array( 'seznam.cz' ) ),
			array( '/duckduckbot|duckassistbot/i', 'DuckDuckBot', 'search', array() ),
			array( '/qwantify|sogou|coccocbot|naverbot|yeti\//i', 'Search crawler', 'search', array() ),
			// SEO and marketing crawlers.
			array( '/ahrefsbot|ahrefssiteaudit/i', 'AhrefsBot', 'seo', array( 'ahrefs.com', 'ahrefs.net' ) ),
			array( '/semrushbot|siteauditbot/i', 'SemrushBot', 'seo', array( 'semrush.com' ) ),
			array( '/mj12bot/i', 'MJ12bot (Majestic)', 'seo', array() ),
			array( '/dotbot|rogerbot/i', 'DotBot (Moz)', 'seo', array() ),
			array( '/blexbot|dataforseobot|megaindex|serpstatbot|barkrowler|zoominfobot|seokicks|linkdexbot|screaming frog|sistrix|babbar|netpeakchecker|openlinkprofiler/i', 'SEO crawler', 'seo', array() ),
			// AI and data-collection crawlers.
			array( '/gptbot|chatgpt-user|oai-searchbot/i', 'OpenAI crawler', 'ai', array() ),
			array( '/claudebot|claude-web|claude-user|anthropic-ai/i', 'Anthropic crawler', 'ai', array() ),
			array( '/perplexitybot|perplexity-user/i', 'PerplexityBot', 'ai', array() ),
			array( '/bytespider|tiktokspider/i', 'Bytespider (ByteDance)', 'ai', array() ),
			array( '/amazonbot/i', 'Amazonbot', 'ai', array( 'crawl.amazonbot.amazon' ) ),
			array( '/ccbot|cohere-ai|diffbot|imagesiftbot|meta-externalagent|facebookbot|youbot|timpibot|omgili|google-extended/i', 'AI / data crawler', 'ai', array() ),
			// Link previews and social networks.
			array( '/facebookexternalhit|twitterbot|linkedinbot|slackbot|whatsapp|telegrambot|pinterest|discordbot|skypeuripreview|redditbot/i', 'Link preview bot', 'social', array() ),
			// Headless browsers (automation).
			array( '/headlesschrome|phantomjs|puppeteer|playwright|selenium|webdriver|chrome-lighthouse/i', 'Headless browser', 'headless', array() ),
			// WordPress itself (cron, loopback, link checkers and update checks run by plugins).
			array( '/^wordpress\/\d/i', 'WordPress request', 'tool', array() ),
			// Scripts, HTTP libraries and scanners.
			array( '/python-requests|python-urllib|python\/|aiohttp|httpx|scrapy|go-http-client|okhttp|java\/|apache-httpclient|node-fetch|axios|undici|guzzlehttp|libwww-perl|curl\/|wget\/|php\/|httrack|mechanize|postman|insomnia|restsharp|reqwest|ruby/i', 'Script / HTTP library', 'tool', array() ),
			array( '/sqlmap|nikto|masscan|zgrab|nmap|wpscan|acunetix|nessus|netsparker|dirbuster|gobuster|nuclei|openvas|burp/i', 'Security scanner', 'tool', array() ),
			// Archives and monitors.
			array( '/archive\.org_bot|ia_archiver|wayback/i', 'Internet Archive', 'bot', array() ),
			array( '/uptimerobot|pingdom|statuscake|site24x7|betteruptime|monitis|gtmetrix|pagespeed|jetmon/i', 'Uptime / speed monitor', 'bot', array() ),
		);
	}

	/**
	 * Classify a User-Agent string.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return array{label:string,kind:string,domains:array<int,string>,suspect:bool}
	 */
	public static function classify( string $user_agent ): array {
		$user_agent = trim( $user_agent );
		if ( '' === $user_agent ) {
			return array(
				'label'   => '',
				'kind'    => 'none',
				'domains' => array(),
				'suspect' => true,
			);
		}

		foreach ( self::signatures() as $sig ) {
			if ( 1 === preg_match( $sig[0], $user_agent ) ) {
				return array(
					'label'   => $sig[1],
					'kind'    => $sig[2],
					'domains' => $sig[3],
					'suspect' => false,
				);
			}
		}

		// Anything else that calls itself a bot, crawler or spider.
		if ( 1 === preg_match( '/(?:^|[^a-z])bot(?:[^a-z]|$)|crawl|spider|scrape|fetch|archiver|scanner|checker|monitor/i', $user_agent ) || 1 === preg_match( '/[a-z]Bot\b/', $user_agent ) ) {
			return array(
				'label'   => '',
				'kind'    => 'bot',
				'domains' => array(),
				'suspect' => false,
			);
		}

		// Browsers: the major version tells a current browser from a very old one, which is typical of a fake User-Agent.
		$browsers = array(
			'/Edg(?:e|A|iOS)?\/(\d+)/'         => 'Edge',
			'/OPR\/(\d+)/'                     => 'Opera',
			'/(?:Firefox|FxiOS)\/(\d+)/'       => 'Firefox',
			'/(?:Chrome|CriOS)\/(\d+)/'        => 'Chrome',
			'/Version\/(\d+).*Safari/'         => 'Safari',
			'/MSIE (\d+)|Trident\/.*rv:(\d+)/' => 'Internet Explorer',
		);
		foreach ( $browsers as $pattern => $name ) {
			if ( 1 === preg_match( $pattern, $user_agent, $m ) ) {
				$major = (int) ( $m[1] ?? 0 );
				if ( 0 === $major ) {
					$major = (int) ( $m[2] ?? 0 );
				}
				$minimum = array(
					'Edge'              => 100,
					'Opera'             => 85,
					'Firefox'           => 100,
					'Chrome'            => 100,
					'Safari'            => 14,
					'Internet Explorer' => 99999,
				);
				return array(
					'label'   => $major > 0 ? $name . ' ' . $major : $name,
					'kind'    => 'browser',
					'domains' => array(),
					'suspect' => $major < $minimum[ $name ],
				);
			}
		}

		// "Mozilla/5.0" only, or a short unknown string: not a real browser.
		return array(
			'label'   => '',
			'kind'    => 'unknown',
			'domains' => array(),
			'suspect' => true,
		);
	}

	/**
	 * Human label of a kind.
	 *
	 * @param string $kind Kind returned by classify().
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case 'search':
				return __( 'Search engine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'seo':
				return __( 'SEO crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'ai':
				return __( 'AI / data crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'social':
				return __( 'Link preview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'tool':
				return __( 'Script or scanner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'headless':
				return __( 'Headless browser', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'bot':
				return __( 'Bot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'browser':
				return __( 'Browser', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'none':
				return __( 'No User-Agent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			default:
				return __( 'Unrecognised agent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
	}

	/**
	 * Key under which a User-Agent is counted in a log row: its label, or "~kind" when it has none.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string
	 */
	public static function agent_key( string $user_agent ): string {
		$info = self::classify( $user_agent );
		return '' !== $info['label'] ? $info['label'] : '~' . $info['kind'];
	}

	/**
	 * Text shown for a key built by agent_key() (generic names are translated, product names are not).
	 *
	 * @param string $key Stored key.
	 * @return string
	 */
	public static function agent_text( string $key ): string {
		if ( 0 === strpos( $key, '~' ) ) {
			return self::kind_label( substr( $key, 1 ) );
		}
		switch ( $key ) {
			case 'Search crawler':
				return __( 'Search crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'SEO crawler':
				return __( 'SEO crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'OpenAI crawler':
				return __( 'OpenAI crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Anthropic crawler':
				return __( 'Anthropic crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'AI / data crawler':
				return __( 'AI / data crawler', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Link preview bot':
				return __( 'Link preview bot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Headless browser':
				return __( 'Headless browser', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Script / HTTP library':
				return __( 'Script / HTTP library', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Security scanner':
				return __( 'Security scanner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'WordPress request':
				return __( 'WordPress request', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'Uptime / speed monitor':
				return __( 'Uptime / speed monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			default:
				return $key;
		}
	}

	/**
	 * Whether an IP is this server itself (loopback or the address the web server answers on): a scheduled task,
	 * a link checker or a crawler running on the same host, or another site on the same shared server.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function is_own_server( string $ip ): bool {
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$packed = inet_pton( $ip );
		foreach ( self::own_server_ips() as $own ) {
			if ( inet_pton( $own ) === $packed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Addresses that identify this server: loopback, the address the web server answers on, the address of the
	 * server's own host name (hosts often answer on a different public address than SERVER_ADDR) and any address
	 * added with the tsosk_own_server_ips filter.
	 *
	 * @return array<int, string>
	 */
	private static function own_server_ips(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$ips = array( '127.0.0.1', '::1' );
		foreach ( array( 'SERVER_ADDR', 'LOCAL_ADDR' ) as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) ) {
				$ips[] = sanitize_text_field( wp_unslash( (string) $_SERVER[ $name ] ) );
			}
		}

		// Address of the server's host name (a local lookup, cached so the admin screen never waits on DNS twice).
		$resolved = get_transient( 'tsosk_own_host_ip' );
		if ( false === $resolved ) {
			$resolved = '';
			$name     = function_exists( 'gethostname' ) ? (string) gethostname() : '';
			if ( '' !== $name ) {
				$candidate = gethostbyname( $name );
				if ( $candidate !== $name && false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$resolved = $candidate;
				}
			}
			set_transient( 'tsosk_own_host_ip', $resolved, 12 * HOUR_IN_SECONDS );
		}
		if ( is_string( $resolved ) && '' !== $resolved ) {
			$ips[] = $resolved;
		}

		// Addresses learned from a "Look up host" that resolved to this server's own host name.
		$learned = get_option( 'tsosk_own_learned_ips', array() );
		if ( is_array( $learned ) ) {
			$ips = array_merge( $ips, array_map( 'strval', $learned ) );
		}

		/**
		 * Filters the IP addresses the 404 Monitor treats as "this server".
		 *
		 * @param string[] $ips Addresses.
		 */
		$ips = apply_filters( 'tsosk_own_server_ips', $ips );
		$ips = is_array( $ips ) ? $ips : array();

		$cache = array_values(
			array_filter(
				array_map( 'strval', $ips ),
				static function ( string $candidate ): bool {
					return false !== filter_var( $candidate, FILTER_VALIDATE_IP );
				}
			)
		);
		return $cache;
	}

	/**
	 * Remember an IP whose host name is this server's own, so every other row from that IP is recognised without a lookup.
	 *
	 * @param string $ip   Client IP.
	 * @param string $host Host name found for it.
	 */
	public static function learn_own_ip( string $ip, string $host ): void {
		if ( '' === $host || false === filter_var( $ip, FILTER_VALIDATE_IP ) || ! function_exists( 'gethostname' ) ) {
			return;
		}
		if ( strtolower( (string) gethostname() ) !== strtolower( $host ) ) {
			return;
		}
		$learned = get_option( 'tsosk_own_learned_ips', array() );
		$learned = is_array( $learned ) ? $learned : array();
		if ( in_array( $ip, $learned, true ) ) {
			return;
		}
		$learned[] = $ip;
		update_option( 'tsosk_own_learned_ips', array_slice( array_values( array_unique( $learned ) ), -10 ), false );
	}

	/**
	 * Whether a log row was requested by this server itself: by IP, or because the host name found with
	 * "Look up host" is the name of this very server.
	 *
	 * @param array<string, mixed> $row Log row.
	 * @return bool
	 */
	public static function row_is_own_server( array $row ): bool {
		if ( self::is_own_server( (string) ( $row['ip'] ?? '' ) ) ) {
			return true;
		}
		$host = strtolower( (string) ( $row['host'] ?? '' ) );
		if ( '' === $host || ! function_exists( 'gethostname' ) ) {
			return false;
		}
		return strtolower( (string) gethostname() ) === $host;
	}

	/**
	 * Minute of the hour (00-59) at which most hits of a row arrive, when that is regular enough to be a scheduled task.
	 *
	 * @param array<string, mixed> $row Log row.
	 * @return string Two-digit minute, or an empty string when there is no clear pattern.
	 */
	public static function schedule_minute( array $row ): string {
		$map = ( isset( $row['minutes'] ) && is_array( $row['minutes'] ) ) ? $row['minutes'] : array();
		if ( ! $map ) {
			return '';
		}
		$total = array_sum( array_map( 'absint', $map ) );
		arsort( $map );
		$top = absint( reset( $map ) );
		if ( $top < 4 || $total < 1 || $top / $total < 0.8 ) {
			return '';
		}
		return str_pad( substr( (string) key( $map ), 0, 2 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether a stored log row looks like automated traffic (known crawler or tool, spoofed, or behaviour flag).
	 *
	 * @param array<string, mixed> $row Log row.
	 * @return bool
	 */
	public static function row_is_bot( array $row ): bool {
		if ( ! empty( $row['bot_suspect'] ) || self::row_is_own_server( $row ) || '' !== self::schedule_minute( $row ) ) {
			return true;
		}
		$info = self::classify( (string) ( $row['user_agent'] ?? '' ) );
		return in_array( $info['kind'], self::BOT_KINDS, true ) || $info['suspect'];
	}

	/**
	 * Where a request came from, judged by its Referer and the requested path.
	 *
	 * @param string $referrer Referer header (may be empty).
	 * @param string $path     Requested path.
	 * @return string One of none|internal|search|hotlink|external.
	 */
	public static function origin( string $referrer, string $path ): string {
		if ( '' === $referrer ) {
			return 'none';
		}
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return 'none';
		}
		$strip = static function ( string $h ): string {
			return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		};
		$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $home && $strip( $host ) === $strip( $home ) ) {
			return 'internal';
		}
		if ( 1 === preg_match( '/(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia|qwant|brave|startpage)\./i', $host . '.' ) ) {
			return 'search';
		}
		if ( 1 === preg_match( '#/(?:wp-content|wp-includes)/|\.(?:jpe?g|png|gif|webp|svg|avif|css|js|woff2?|mp4|pdf)$#i', $path ) ) {
			return 'hotlink';
		}
		return 'external';
	}

	/**
	 * Label of an origin value.
	 *
	 * @param string $origin Value returned by origin().
	 * @return string
	 */
	public static function origin_label( string $origin ): string {
		switch ( $origin ) {
			case 'internal':
				return __( 'From this site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'search':
				return __( 'From a search engine', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'hotlink':
				return __( 'Loaded by another site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			case 'external':
				return __( 'From another site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			default:
				return '';
		}
	}

	/**
	 * Host name of a referrer, without "www.".
	 *
	 * @param string $url Referrer URL.
	 * @return string
	 */
	public static function host_of( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Reverse + forward DNS lookup of an IP. Runs only when an administrator asks for it.
	 *
	 * @param string $ip IP address.
	 * @return array{host:string,confirmed:bool}
	 */
	public static function lookup_host( string $ip ): array {
		$result = array(
			'host'      => '',
			'confirmed' => false,
		);
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $result;
		}
		$host = gethostbyaddr( $ip );
		if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
			return $result;
		}
		$host = strtolower( sanitize_text_field( $host ) );

		$addresses = array();
		if ( false !== strpos( $ip, ':' ) ) {
			if ( function_exists( 'dns_get_record' ) ) {
				$records = dns_get_record( $host, DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ipv6'] ) ) {
							$addresses[] = (string) $record['ipv6'];
						}
					}
				}
			}
		} else {
			$list = gethostbynamel( $host );
			if ( is_array( $list ) ) {
				$addresses = $list;
			}
		}

		$packed = inet_pton( $ip );
		foreach ( $addresses as $address ) {
			if ( false !== $packed && inet_pton( $address ) === $packed ) {
				$result['confirmed'] = true;
				break;
			}
		}
		$result['host'] = $host;
		return $result;
	}

	/**
	 * Judge a looked-up host against the crawler the User-Agent claims to be.
	 *
	 * @param string             $host      Host name from lookup_host().
	 * @param bool               $confirmed Whether forward DNS gave the same IP back.
	 * @param array<int, string> $domains   Genuine host domains for the claimed crawler.
	 * @return string verified|spoofed|unknown (unknown = nothing to compare against).
	 */
	public static function verdict( string $host, bool $confirmed, array $domains ): string {
		if ( empty( $domains ) ) {
			return 'unknown';
		}
		if ( '' === $host || ! $confirmed ) {
			return 'spoofed';
		}
		foreach ( $domains as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return 'verified';
			}
		}
		return 'spoofed';
	}
}
