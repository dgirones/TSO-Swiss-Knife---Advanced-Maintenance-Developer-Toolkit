<?php
/**
 * TSO Swiss Knife – Module: Staging Mode and outbound mail log.
 *
 * All features stay off until an administrator enables them. Mail intercept
 * uses pre_wp_mail (WordPress 5.7+) and never writes wp-config.php.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Staging
 */
class TSOSK_Mod_Staging {

	/** Settings option (autoloaded; read on front requests). */
	public const OPTION = 'tsosk_staging_settings';

	/** Max stored mail log rows. */
	private const MAIL_LOG_LIMIT = 100;

	/** Max characters stored from a message body. */
	private const BODY_EXCERPT = 200;

	/** @var TSOSK_Mod_Staging|null */
	private static $instance = null;

	/** @var bool */
	private static $did_init = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_staging_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_staging_clear_mail_log', array( $this, 'ajax_clear_mail_log' ) );
	}

	/**
	 * Runtime hooks (front and admin). All default off.
	 */
	public function init(): void {
		if ( self::$did_init ) {
			return;
		}
		self::$did_init = true;

		$settings = $this->get_settings();

		if ( ! empty( $settings['show_badge'] ) ) {
			add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_badge' ), 100 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_badge_assets' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_badge_assets' ) );
		}

		if ( ! empty( $settings['hide_from_search'] ) ) {
			// Do not filter pre_option_blog_public: Settings → Reading would show
			// "Discourage search engines" as checked and saving that screen would
			// persist blog_public = 0 after staging is turned off.
			add_filter( 'wp_robots', array( $this, 'filter_wp_robots_noindex' ) );
			add_filter( 'wp_headers', array( $this, 'filter_robots_header' ) );
			add_filter( 'wp_sitemaps_enabled', array( $this, 'filter_sitemaps_off' ) );
			add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );
		}

		if ( ! empty( $settings['block_mail'] ) || ! empty( $settings['log_mail'] ) ) {
			add_filter( 'pre_wp_mail', array( $this, 'filter_pre_wp_mail' ), 10, 2 );
		}
	}

	/**
	 * @param mixed $robots Robots directives from wp_robots (must stay untyped; other plugins may pass non-arrays).
	 * @return mixed
	 */
	public function filter_wp_robots_noindex( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		unset( $robots['index'] );
		$robots['noindex'] = true;
		return $robots;
	}

	/**
	 * @param mixed $enabled Whether sitemaps are enabled.
	 * @return bool
	 */
	public function filter_sitemaps_off( $enabled ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		unset( $enabled );
		return false;
	}

	/**
	 * @param mixed $output robots.txt body.
	 * @param mixed $public blog_public value (unused).
	 * @return mixed
	 */
	public function filter_robots_txt( $output, $public ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		unset( $public );
		if ( ! is_string( $output ) ) {
			return $output;
		}
		$marker = '# TSO Swiss Knife Staging Mode';
		if ( false !== strpos( $output, $marker ) ) {
			return $output;
		}
		return $output . "\n" . $marker . "\nUser-agent: *\nDisallow: /\n";
	}

	/**
	 * Merge noindex into X-Robots-Tag instead of replacing other plugins' values.
	 *
	 * @param mixed $headers Response headers (untyped: wp_headers is loosely typed).
	 * @return mixed
	 */
	public function filter_robots_header( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		$existing = '';
		foreach ( $headers as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'X-Robots-Tag' ) ) {
				$existing = is_string( $value ) ? $value : '';
				unset( $headers[ $name ] );
				break;
			}
		}
		if ( '' === $existing ) {
			$headers['X-Robots-Tag'] = 'noindex';
			return $headers;
		}
		if ( false === stripos( $existing, 'noindex' ) ) {
			$existing .= ', noindex';
		}
		$headers['X-Robots-Tag'] = $existing;
		return $headers;
	}

	/**
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts wp_mail arguments.
	 * @return mixed
	 */
	public function filter_pre_wp_mail( $pre, $atts ) {
		if ( null !== $pre ) {
			return $pre;
		}

		$settings = $this->get_settings();
		$block    = ! empty( $settings['block_mail'] );
		$log      = ! empty( $settings['log_mail'] ) || $block;

		if ( $log ) {
			$this->append_mail_log( is_array( $atts ) ? $atts : array(), $block );
		}

		if ( $block ) {
			// Pretend success so shops and forms do not retry forever.
			return true;
		}

		return $pre;
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function add_admin_bar_badge( $wp_admin_bar ): void {
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'tsosk-staging-badge',
				'title' => esc_html__( 'STAGING', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'href'  => admin_url( 'tools.php?page=tso-swiss-knife&tab=staging' ),
				'meta'  => array(
					'class' => 'tsosk-staging-ab-item',
					'title' => __( 'This copy is marked as a test site. Open Staging Mode settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			)
		);
	}

	/**
	 * Badge styles for the admin bar (front and admin).
	 */
	public function enqueue_badge_assets(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rel  = 'assets/css/tsosk-staging-bar.css';
		$path = TSOSK_PATH . $rel;
		$ver  = TSOSK_VERSION;
		if ( is_readable( $path ) ) {
			$ver .= '.' . (string) filemtime( $path );
		}

		wp_enqueue_style(
			'tsosk-staging-bar',
			TSOSK_URL . $rel,
			array(),
			$ver
		);
	}

	/**
	 * AJAX: save staging switches.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_staging_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$settings = self::sanitize_settings(
			array(
				'show_badge'       => '1' === TSOSK_Support::get_post_scalar( 'show_badge' ),
				'hide_from_search' => '1' === TSOSK_Support::get_post_scalar( 'hide_from_search' ),
				'block_mail'       => '1' === TSOSK_Support::get_post_scalar( 'block_mail' ),
				'log_mail'         => '1' === TSOSK_Support::get_post_scalar( 'log_mail' ),
			)
		);

		update_option( self::OPTION, $settings, true );
		TSOSK_Activity_Log::log( 'staging', 'save', __( 'Staging Mode settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: empty the mail log file.
	 */
	public function ajax_clear_mail_log(): void {
		check_ajax_referer( 'tsosk_staging_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		TSOSK_Config_Storage::delete_log_json( TSOSK_Config_Storage::MAIL_LOG_JSON );
		TSOSK_Activity_Log::log( 'staging', 'delete', __( 'Staging mail log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Mail log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * @param array<string, mixed> $raw Raw flags.
	 * @return array{show_badge:bool,hide_from_search:bool,block_mail:bool,log_mail:bool}
	 */
	public static function sanitize_settings( array $raw ): array {
		return array(
			'show_badge'       => ! empty( $raw['show_badge'] ),
			'hide_from_search' => ! empty( $raw['hide_from_search'] ),
			'block_mail'       => ! empty( $raw['block_mail'] ),
			'log_mail'         => ! empty( $raw['log_mail'] ),
		);
	}

	/**
	 * Truncate a UTF-8 string without splitting a multibyte character.
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Max characters.
	 */
	public static function utf8_excerpt( string $text, int $max ): string {
		if ( $max < 1 || '' === $text ) {
			return '';
		}
		if ( function_exists( 'wp_html_excerpt' ) ) {
			return wp_html_excerpt( $text, $max, '…' );
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
				return mb_substr( $text, 0, $max, 'UTF-8' ) . '…';
			}
			return $text;
		}
		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, $max ) . '…';
		}
		return $text;
	}

	/**
	 * Flatten wp_mail recipients to a short display string.
	 *
	 * @param mixed $to Recipients.
	 * @return string
	 */
	public static function normalize_recipients( $to ): string {
		$parts = array();
		if ( is_array( $to ) ) {
			foreach ( $to as $item ) {
				if ( is_string( $item ) || is_numeric( $item ) ) {
					$parts[] = (string) $item;
				}
			}
		} elseif ( is_string( $to ) || is_numeric( $to ) ) {
			$parts[] = (string) $to;
		}

		$clean = array();
		foreach ( $parts as $part ) {
			foreach ( array_map( 'trim', explode( ',', $part ) ) as $piece ) {
				if ( '' === $piece ) {
					continue;
				}
				$email = sanitize_email( $piece );
				$clean[] = is_email( $email ) ? $email : sanitize_text_field( $piece );
			}
		}

		$clean = array_values( array_unique( array_filter( $clean ) ) );
		return implode( ', ', array_slice( $clean, 0, 10 ) );
	}

	/**
	 * @param array<string, mixed> $atts    wp_mail atts.
	 * @param bool                 $blocked Whether sending was blocked.
	 * @return array{time:int,to:string,subject:string,excerpt:string,blocked:bool}
	 */
	public static function build_mail_entry( array $atts, bool $blocked ): array {
		$subject = isset( $atts['subject'] ) ? sanitize_text_field( (string) $atts['subject'] ) : '';
		$message = '';
		if ( isset( $atts['message'] ) && ( is_string( $atts['message'] ) || is_numeric( $atts['message'] ) ) ) {
			$message = (string) $atts['message'];
		}
		$excerpt = wp_strip_all_tags( $message );
		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );
		$excerpt = preg_replace( '/\s+/', ' ', $excerpt );
		$excerpt = is_string( $excerpt ) ? trim( $excerpt ) : '';
		$excerpt = self::utf8_excerpt( $excerpt, self::BODY_EXCERPT );

		return array(
			'time'     => time(),
			'to'       => self::normalize_recipients( $atts['to'] ?? '' ),
			'subject'  => $subject,
			'excerpt'  => sanitize_text_field( $excerpt ),
			'blocked'  => $blocked,
		);
	}

	/**
	 * @return array{show_badge:bool,hide_from_search:bool,block_mail:bool,log_mail:bool}
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize_settings( $stored );
	}

	/**
	 * Whether any staging switch is on (header badge).
	 */
	public static function is_any_feature_on(): bool {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		$settings = self::sanitize_settings( $stored );
		return in_array( true, $settings, true );
	}

	/**
	 * @return array<int, array{time:int,to:string,subject:string,excerpt:string,blocked:bool}>
	 */
	public function get_mail_log(): array {
		$data = TSOSK_Config_Storage::read_log_json( TSOSK_Config_Storage::MAIL_LOG_JSON );
		$raw  = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array();
		$out  = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'time'    => isset( $row['time'] ) ? absint( $row['time'] ) : 0,
				'to'      => isset( $row['to'] ) ? sanitize_text_field( (string) $row['to'] ) : '',
				'subject' => isset( $row['subject'] ) ? sanitize_text_field( (string) $row['subject'] ) : '',
				'excerpt' => isset( $row['excerpt'] ) ? sanitize_text_field( (string) $row['excerpt'] ) : '',
				'blocked' => ! empty( $row['blocked'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $atts    wp_mail atts.
	 * @param bool                 $blocked Blocked flag.
	 */
	private function append_mail_log( array $atts, bool $blocked ): void {
		$entry    = self::build_mail_entry( $atts, $blocked );
		$existing = $this->get_mail_log();
		array_unshift( $existing, $entry );
		$existing = array_slice( $existing, 0, self::MAIL_LOG_LIMIT );
		TSOSK_Config_Storage::write_log_json(
			TSOSK_Config_Storage::MAIL_LOG_JSON,
			array(
				'version' => 1,
				'entries' => $existing,
			)
		);
	}

	public function render(): void {
		$nonce    = wp_create_nonce( 'tsosk_staging_nonce' );
		$settings = $this->get_settings();
		$log      = $this->get_mail_log();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Use this on a test copy of the site so it is obvious it is not production, so search engines skip it, and so customer emails are not sent by mistake. Everything stays off until you tick a box.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-warn">
			<?php esc_html_e( 'Turn these options off before you copy this database back to the live site. They are saved in this WordPress install.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Test-site options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<label class="tsosk-heartbeat-option">
				<input type="checkbox" id="tsosk-staging-show-badge" value="1" <?php checked( $settings['show_badge'] ); ?>>
				<strong><?php esc_html_e( 'Show a red STAGING label in the admin bar', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<p class="description" style="margin:4px 0 0 24px;">
					<?php esc_html_e( 'Visible to administrators so nobody confuses this copy with the live site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</label>
			<label class="tsosk-heartbeat-option">
				<input type="checkbox" id="tsosk-staging-hide-search" value="1" <?php checked( $settings['hide_from_search'] ); ?>>
				<strong><?php esc_html_e( 'Ask search engines not to list this copy', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<p class="description" style="margin:4px 0 0 24px;">
					<?php esc_html_e( 'Adds noindex headers, pauses XML sitemaps, and disallows crawling in robots.txt while this option is on. It does not change Settings → Reading.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</label>
			<label class="tsosk-heartbeat-option">
				<input type="checkbox" id="tsosk-staging-block-mail" value="1" <?php checked( $settings['block_mail'] ); ?>>
				<strong><?php esc_html_e( 'Do not send real emails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<p class="description" style="margin:4px 0 0 24px;">
					<?php esc_html_e( 'WordPress will think the email was sent, but nothing leaves the server. A short copy is kept in the log below. Use this so a test shop does not email real customers.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</label>
			<label class="tsosk-heartbeat-option">
				<input type="checkbox" id="tsosk-staging-log-mail" value="1" <?php checked( $settings['log_mail'] ); ?>>
				<strong><?php esc_html_e( 'Keep a copy of emails even when they are sent', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				<p class="description" style="margin:4px 0 0 24px;">
					<?php esc_html_e( 'Useful on the live site when you need to see whether WordPress tried to send a message. Only administrators can read the log. Message bodies are shortened.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</label>
			<p style="margin-top:12px;">
				<button type="button" class="button button-primary" id="tsosk-staging-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-staging-msg"></span>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recent emails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Stored as JSON under the plugin uploads folder (not in the public media library listing). The last 100 messages are kept. Full HTML bodies are not stored.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No emails recorded yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat tsosk-table" id="tsosk-staging-mail-log">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'To', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Sent?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['time'] ? wp_date( 'Y-m-d H:i:s', $row['time'] ) : '—' ); ?></td>
								<td><code><?php echo esc_html( $row['to'] ); ?></code></td>
								<td>
									<?php echo esc_html( $row['subject'] ); ?>
									<?php if ( '' !== $row['excerpt'] ) : ?>
										<br><span class="description"><?php echo esc_html( $row['excerpt'] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $row['blocked'] ) : ?>
										<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Held here', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php else : ?>
										<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Allowed to send', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-staging-clear-log" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear mail log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
