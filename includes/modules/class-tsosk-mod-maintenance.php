<?php
/**
 * TSO Swiss Knife – Module: Maintenance Mode.
 *
 * Shows a custom maintenance page (HTTP 503) to all non-admin visitors,
 * switched on by hand or by a schedule.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintenance module.
 */
class TSOSK_Mod_Maintenance {

	/**
	 * Singleton instance.
	 *
	 * @var TSOSK_Mod_Maintenance|null
	 */
	private static $instance = null;

	const OPTION = 'tsosk_maintenance';

	/**
	 * Default colours of the visitor page.
	 *
	 * @var array<string,string>
	 */
	const DEFAULT_COLORS = array(
		'bg_color'     => '#1a1a2e',
		'title_color'  => '#ffffff',
		'text_color'   => '#aaaaaa',
		'accent_color' => '#f0c14b',
	);

	/**
	 * Get instance.
	 *
	 * @return self
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
		add_action( 'wp_ajax_tsosk_maintenance_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_tsosk_maintenance_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_maintenance_preview', array( $this, 'ajax_preview' ) );
	}

	/**
	 * Called early: applies maintenance mode for front-end requests.
	 */
	public function init(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] && '' === $settings['schedule_start'] ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'show_maintenance_page' ), 1 );
	}

	/**
	 * Whether maintenance mode applies right now, by hand or by schedule (used by the Site Status badge).
	 */
	public static function is_active_now(): bool {
		$module = self::get_instance();
		return $module->is_active( $module->get_settings() );
	}

	/**
	 * Whether a settings array would put the site in maintenance right now.
	 *
	 * @param array<string,mixed> $settings Maintenance settings.
	 */
	public static function settings_are_active( array $settings ): bool {
		$module = self::get_instance();
		return $module->is_active( wp_parse_args( $settings, $module->get_settings() ) );
	}

	/**
	 * Sanitize a maintenance settings array coming from a configuration snapshot import.
	 *
	 * @param array<string,mixed> $value Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_imported( array $value ): array {
		$module = self::get_instance();
		$text   = static function ( string $key ) use ( $value ): string {
			return sanitize_text_field( (string) ( $value[ $key ] ?? '' ) );
		};
		$area   = static function ( string $key ) use ( $value ): string {
			return sanitize_textarea_field( (string) ( $value[ $key ] ?? '' ) );
		};

		$out = array(
			'enabled'        => ! empty( $value['enabled'] ),
			'message'        => trim( $area( 'message' ) ),
			'page_title'     => $text( 'page_title' ),
			'subtitle'       => $text( 'subtitle' ),
			'contact'        => $module->sanitize_contact( $text( 'contact' ) ),
			'whitelist_ips'  => trim( $area( 'whitelist_ips' ) ),
			'logo_id'        => $module->sanitize_logo_id( absint( $value['logo_id'] ?? 0 ) ),
			'logo_width'     => $module->sanitize_logo_width( absint( $value['logo_width'] ?? 0 ) ),
			'return_at'      => $module->sanitize_local_datetime( $text( 'return_at' ) ),
			'show_countdown' => ! empty( $value['show_countdown'] ),
			'schedule_start' => $module->sanitize_local_datetime( $text( 'schedule_start' ) ),
			'schedule_end'   => $module->sanitize_local_datetime( $text( 'schedule_end' ) ),
			'custom_css'     => substr( trim( str_ireplace( '</style', '', $area( 'custom_css' ) ) ), 0, 5000 ),
		);
		foreach ( array_keys( self::DEFAULT_COLORS ) as $color_key ) {
			$out[ $color_key ] = (string) sanitize_hex_color( $text( $color_key ) );
		}
		return $out;
	}

	/**
	 * Show maintenance page to non-admins on the frontend.
	 */
	public function show_maintenance_page(): void {
		if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( ! $this->is_active( $settings ) ) {
			return;
		}

		// Check whitelisted IPs.
		$client_ip = $this->get_client_ip();
		if ( $client_ip && ! empty( $settings['whitelist_ips'] ) ) {
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Falsy fallback of a function result is intended.
			$whitelist = array_filter( array_map( 'trim', preg_split( '/\R/', (string) $settings['whitelist_ips'] ) ?: array() ) );
			if ( $this->ip_in_list( $client_ip, $whitelist ) ) {
				return;
			}
		}

		$this->render_maintenance_html( $settings, false );
	}

	/**
	 * Timestamp of a "Y-m-d\TH:i" value entered in the site time zone (0 = empty or invalid).
	 *
	 * @param string $local Local date-time string.
	 */
	private function to_timestamp( string $local ): int {
		if ( '' === $local ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $local, wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}
		return $date->getTimestamp();
	}

	/**
	 * Whether maintenance mode applies right now (switched on by hand, or inside the schedule window).
	 *
	 * @param array<string,mixed> $settings Saved settings.
	 */
	private function is_active( array $settings ): bool {
		$now   = time();
		$start = $this->to_timestamp( (string) $settings['schedule_start'] );
		$end   = $this->to_timestamp( (string) $settings['schedule_end'] );

		$manual    = ! empty( $settings['enabled'] ) && ( 0 === $end || $now < $end );
		$scheduled = $start > 0 && $now >= $start && ( 0 === $end || $now < $end );

		return $manual || $scheduled;
	}

	/**
	 * Seconds for the Retry-After header: until the announced return, else until the schedule ends, else one hour.
	 *
	 * @param array<string,mixed> $page Page settings.
	 */
	private function retry_after_seconds( array $page ): int {
		$now = time();
		foreach ( array( 'return_at', 'schedule_end' ) as $key ) {
			$ts = $this->to_timestamp( (string) ( $page[ $key ] ?? '' ) );
			if ( $ts > $now ) {
				return max( 60, min( $ts - $now, 30 * DAY_IN_SECONDS ) );
			}
		}
		return HOUR_IN_SECONDS;
	}

	/**
	 * CSS variables and the custom CSS of the visitor page.
	 *
	 * @param array<string,mixed> $page Page settings.
	 */
	private function build_inline_css( array $page ): string {
		$vars = array(
			'--tsosk-m-bg'     => (string) ( $page['bg_color'] ?? '' ),
			'--tsosk-m-title'  => (string) ( $page['title_color'] ?? '' ),
			'--tsosk-m-text'   => (string) ( $page['text_color'] ?? '' ),
			'--tsosk-m-accent' => (string) ( $page['accent_color'] ?? '' ),
		);
		$css  = '';
		foreach ( $vars as $name => $value ) {
			$value = sanitize_hex_color( $value );
			if ( $value ) {
				$css .= $name . ':' . $value . ';';
			}
		}
		$css = '' === $css ? '' : 'body.tsosk-maint{' . $css . '}';

		$width = absint( $page['logo_width'] ?? 0 );
		if ( $width > 0 ) {
			$css .= '.tsosk-maint-box{max-width:max(560px,min(' . $width . 'px,100%));}'
				. '.tsosk-maint-logo{max-width:min(' . $width . 'px,100%);max-height:none;}';
		}

		$custom = (string) ( $page['custom_css'] ?? '' );
		if ( '' !== trim( $custom ) ) {
			$css .= wp_strip_all_tags( $custom );
		}
		return $css;
	}

	/**
	 * Output the maintenance page HTML and exit.
	 *
	 * @param array<string,mixed> $page       Page settings (message, logo, colours, return date...).
	 * @param bool                $is_preview True when previewing without enabling maintenance.
	 */
	private function render_maintenance_html( array $page, bool $is_preview = false ): void {
		$message = (string) ( $page['message'] ?? '' );
		if ( '' === trim( $message ) ) {
			$message = $this->get_default_message();
		}

		$logo_width = absint( $page['logo_width'] ?? 0 );
		$logo_url   = $this->get_logo_url( (int) ( $page['logo_id'] ?? 0 ), $logo_width );
		$page_title = (string) ( $page['page_title'] ?? '' );
		$heading    = '' !== trim( $page_title ) ? $page_title : get_bloginfo( 'name' );
		$subtitle   = (string) ( $page['subtitle'] ?? '' );
		$contact    = (string) ( $page['contact'] ?? '' );
		$body_cls   = 'tsosk-maint' . ( $is_preview ? ' tsosk-maint-preview' : '' );

		$return_ts = $this->to_timestamp( (string) ( $page['return_at'] ?? '' ) );
		if ( $return_ts <= time() ) {
			$return_ts = 0;
		}
		$show_countdown = $return_ts > 0 && ! empty( $page['show_countdown'] );

		wp_register_style(
			'tsosk-maintenance',
			TSOSK_URL . 'assets/css/tsosk-maintenance.css',
			array(),
			TSOSK_VERSION
		);
		wp_enqueue_style( 'tsosk-maintenance' );
		$inline_css = $this->build_inline_css( $page );
		if ( '' !== $inline_css ) {
			wp_add_inline_style( 'tsosk-maintenance', $inline_css );
		}
		if ( $show_countdown ) {
			wp_register_script(
				'tsosk-maintenance',
				TSOSK_URL . 'assets/js/tsosk-maintenance.js',
				array(),
				TSOSK_VERSION,
				true
			);
			wp_enqueue_script( 'tsosk-maintenance' );
		}

		// status_header() replaces the "HTTP/1.1 200 OK" line WordPress already sent; http_response_code() does not.
		status_header( $is_preview ? 200 : 503 );
		header( 'Retry-After: ' . $this->retry_after_seconds( $page ) );
		header( 'Content-Type: text/html; charset=utf-8' );
		nocache_headers();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> – <?php esc_html_e( 'Maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></title>
		<?php wp_print_styles( 'tsosk-maintenance' ); ?>
</head>
<body class="<?php echo esc_attr( $body_cls ); ?>">
		<?php if ( $is_preview ) : ?>
<div class="tsosk-maint-preview-bar"><?php esc_html_e( 'Preview only — maintenance mode is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></div>
		<?php endif; ?>
<div class="tsosk-maint-box">
		<?php if ( $logo_url ) : ?>
	<img class="tsosk-maint-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
	<?php else : ?>
	<div class="tsosk-maint-icon" aria-hidden="true">🔧</div>
	<?php endif; ?>
	<h1><?php echo esc_html( $heading ); ?></h1>
		<?php if ( '' !== trim( $subtitle ) ) : ?>
	<p class="tsosk-maint-subtitle"><?php echo esc_html( $subtitle ); ?></p>
	<?php endif; ?>
	<p><?php echo wp_kses_post( nl2br( esc_html( $message ) ) ); ?></p>
		<?php if ( $return_ts > 0 ) : ?>
	<p class="tsosk-maint-return">
			<?php
			printf(
				/* translators: %s: date and time when the site is expected to be back */
				esc_html__( 'Expected back: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'<strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $return_ts ) ) . '</strong>'
			);
			?>
	</p>
			<?php if ( $show_countdown ) : ?>
	<div class="tsosk-maint-countdown" data-until="<?php echo esc_attr( (string) $return_ts ); ?>" data-now="<?php echo esc_attr( (string) time() ); ?>"
		data-d="<?php echo esc_attr_x( 'days', 'countdown unit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
		data-h="<?php echo esc_attr_x( 'hours', 'countdown unit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
		data-m="<?php echo esc_attr_x( 'min', 'countdown unit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
		data-s="<?php echo esc_attr_x( 'sec', 'countdown unit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"></div>
	<?php endif; ?>
	<?php endif; ?>
		<?php if ( '' !== $contact ) : ?>
	<p class="tsosk-maint-contact">
			<?php
			$contact_href = is_email( $contact ) ? 'mailto:' . $contact : $contact;
			printf(
				/* translators: %s: contact email address or web address */
				esc_html__( 'Contact: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'<a href="' . esc_url( $contact_href ) . '">' . esc_html( $contact ) . '</a>'
			);
			?>
	</p>
	<?php endif; ?>
</div>
		<?php
		if ( $show_countdown ) {
			wp_print_scripts( 'tsosk-maintenance' );
		}
		?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Default visitor message.
	 */
	private function get_default_message(): string {
		return __( 'We are performing scheduled maintenance. We will be back online shortly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Get settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_settings(): array {
		$defaults            = array(
			'enabled'        => false,
			'message'        => '',
			'page_title'     => '',
			'subtitle'       => '',
			'contact'        => '',
			'whitelist_ips'  => '',
			'logo_id'        => 0,
			'logo_width'     => 0,
			'bg_color'       => '',
			'title_color'    => '',
			'text_color'     => '',
			'accent_color'   => '',
			'return_at'      => '',
			'show_countdown' => false,
			'schedule_start' => '',
			'schedule_end'   => '',
			'custom_css'     => '',
		);
		$settings            = wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
		$settings['logo_id'] = absint( $settings['logo_id'] );
		// Older versions saved these two with stray whitespace around the text.
		$settings['message']       = trim( (string) $settings['message'] );
		$settings['whitelist_ips'] = trim( (string) $settings['whitelist_ips'] );

		return $settings;
	}

	/**
	 * Resolve a valid logo image URL from an attachment ID.
	 *
	 * @param int $logo_id Attachment post ID.
	 * @param int $width   Wanted display width in px (0 = default); wide logos use the full-size image.
	 */
	private function get_logo_url( int $logo_id, int $width = 0 ): string {
		if ( $logo_id <= 0 || ! wp_attachment_is_image( $logo_id ) ) {
			return '';
		}
		$url = $width > 1000 ? '' : wp_get_attachment_image_url( $logo_id, 'large' );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
		}
		return $url ? $url : '';
	}

	/**
	 * Sanitize logo attachment ID from request.
	 *
	 * @param int $logo_id Raw attachment ID.
	 */
	private function sanitize_logo_id( int $logo_id ): int {
		$logo_id = absint( $logo_id );
		if ( $logo_id <= 0 ) {
			return 0;
		}
		if ( ! wp_attachment_is_image( $logo_id ) ) {
			return 0;
		}
		return $logo_id;
	}

	/**
	 * Logo width in px: 0 (default size) or between 40 and 1200.
	 *
	 * @param int $width Raw width.
	 */
	private function sanitize_logo_width( int $width ): int {
		return $width <= 0 ? 0 : max( 40, min( 1200, $width ) );
	}

	/**
	 * A "Y-m-d\TH:i" date-time from a datetime-local field, or an empty string.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitize_local_datetime( string $value ): string {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * A contact email or an http(s) address, or an empty string.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitize_contact( string $value ): string {
		$value = trim( $value );
		if ( is_email( $value ) ) {
			return sanitize_email( $value );
		}
		if ( 1 === preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return '';
	}

	/**
	 * Read the visitor page fields from the AJAX request (save and preview), sanitized.
	 *
	 * @return array<string,mixed>
	 */
	private function read_page_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Both callers run check_ajax_referer() first.
		$text = static function ( string $key ): string {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$css     = isset( $_POST['custom_css'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_css'] ) ) : '';
		$logo_id = isset( $_POST['logo_id'] ) ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0;
		$width   = isset( $_POST['logo_width'] ) ? absint( wp_unslash( $_POST['logo_width'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$fields = array(
			'message'        => trim( $message ),
			'page_title'     => $text( 'page_title' ),
			'subtitle'       => $text( 'subtitle' ),
			'contact'        => $this->sanitize_contact( $text( 'contact' ) ),
			'logo_id'        => $this->sanitize_logo_id( $logo_id ),
			'logo_width'     => $this->sanitize_logo_width( $width ),
			'return_at'      => $this->sanitize_local_datetime( $text( 'return_at' ) ),
			'show_countdown' => '1' === $text( 'show_countdown' ),
			'schedule_start' => $this->sanitize_local_datetime( $text( 'schedule_start' ) ),
			'schedule_end'   => $this->sanitize_local_datetime( $text( 'schedule_end' ) ),
			'custom_css'     => substr( trim( str_ireplace( '</style', '', $css ) ), 0, 5000 ),
		);
		foreach ( array_keys( self::DEFAULT_COLORS ) as $color_key ) {
			$fields[ $color_key ] = (string) sanitize_hex_color( $text( $color_key ) );
		}
		return $fields;
	}

	/**
	 * Returns the visitor's IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return TSOSK_Support::get_client_ip();
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * AJAX: toggle.
	 */
	public function ajax_toggle(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}
		$settings            = $this->get_settings();
		$settings['enabled'] = ! $settings['enabled'];
		update_option( self::OPTION, $settings, true ); // Read on every request — must autoload.

		TSOSK_Activity_Log::log(
			'maintenance',
			$settings['enabled'] ? 'enable' : 'disable',
			$settings['enabled']
				? __( 'Maintenance mode enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: __( 'Maintenance mode disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		$active  = $this->is_active( $settings );
		$message = $settings['enabled']
			? __( 'Maintenance mode enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: __( 'Maintenance mode disabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		if ( $settings['enabled'] && ! $active ) {
			$message = __( 'Switched on, but the schedule end date has already passed, so maintenance mode is not active. Change or clear the end date.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		} elseif ( ! $settings['enabled'] && $active ) {
			$message = __( 'Switched off by hand, but the schedule keeps maintenance mode active until its end.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		wp_send_json_success(
			array(
				'enabled' => $settings['enabled'],
				'active'  => $active,
				'message' => $message,
			)
		);
	}

	/**
	 * AJAX: save.
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$previous                  = $this->get_settings();
		$settings                  = array_merge( $previous, $this->read_page_fields() );
		$settings['whitelist_ips'] = isset( $_POST['whitelist_ips'] )
			? sanitize_textarea_field( wp_unslash( $_POST['whitelist_ips'] ) ) : '';

		$start = $this->to_timestamp( (string) $settings['schedule_start'] );
		$end   = $this->to_timestamp( (string) $settings['schedule_end'] );
		if ( $end > 0 && $start > 0 && $end <= $start ) {
			wp_send_json_error( __( 'The schedule end must be after its start.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		// A manual switch that already passed its end date (the saved one or the new one) is finished: do not leave it armed, or clearing or moving the date would switch the site back into maintenance.
		$previous_end = $this->to_timestamp( (string) $previous['schedule_end'] );
		if ( ! empty( $settings['enabled'] ) && ( ( $end > 0 && time() >= $end ) || ( $previous_end > 0 && time() >= $previous_end ) ) ) {
			$settings['enabled'] = false;
		}

		update_option( self::OPTION, $settings, true ); // Read on every request — must autoload.

		TSOSK_Activity_Log::log(
			'maintenance',
			'save',
			__( 'Maintenance mode settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
		);

		wp_send_json_success( __( 'Settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: preview maintenance page in a new tab (does not enable maintenance).
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'tsosk_maintenance_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), '', array( 'response' => 403 ) );
		}

		$this->render_maintenance_html( $this->read_page_fields(), true );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce      = wp_create_nonce( 'tsosk_maintenance_nonce' );
		$settings   = $this->get_settings();
		$enabled    = (bool) $settings['enabled'];
		$active     = $this->is_active( $settings );
		$logo_id    = (int) $settings['logo_id'];
		$logo_url   = $this->get_logo_url( $logo_id );
		$logo_width = (int) $settings['logo_width'];
		$sched_from = (string) $settings['schedule_start'];
		$sched_to   = (string) $settings['schedule_end'];
		$date_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Put the site in maintenance mode. Logged-in administrators always see the site normally. All other visitors get a 503 page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Maintenance Mode Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div class="tsosk-maintenance-toggle" style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
				<span id="tsosk-maintenance-status" class="tsosk-badge <?php echo $active ? 'tsosk-badge-warn' : 'tsosk-badge-ok'; ?>">
					<?php echo $active ? esc_html__( '⚠ MAINTENANCE MODE ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
				<button class="button button-<?php echo $enabled ? 'primary' : 'secondary'; ?>" id="tsosk-maintenance-toggle"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php echo $enabled ? esc_html__( 'Disable Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Enable Maintenance Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-maintenance-toggle-msg"></span>
			</div>
			<?php if ( '' !== $sched_from ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: schedule start date and time, 2: schedule end date and time or "no end" */
					esc_html__( 'Schedule: on from %1$s until %2$s (site time).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<strong>' . esc_html( wp_date( $date_fmt, $this->to_timestamp( $sched_from ) ) ) . '</strong>',
					'<strong>' . ( '' !== $sched_to ? esc_html( wp_date( $date_fmt, $this->to_timestamp( $sched_to ) ) ) : esc_html__( 'no end', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ) . '</strong>'
				);
				if ( $active && ! $enabled ) {
					echo ' ' . esc_html__( 'It is active now because of the schedule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				}
				?>
			</p>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Maintenance Page Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>

			<div class="tsosk-field-row">
				<label><strong><?php esc_html_e( 'Logo (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<div class="tsosk-maint-logo-picker">
					<input type="hidden" id="tsosk-m-logo-id" value="<?php echo esc_attr( (string) $logo_id ); ?>">
					<div id="tsosk-m-logo-preview" class="tsosk-maint-logo-preview<?php echo $logo_url ? ' has-logo' : ''; ?>">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
						<?php endif; ?>
					</div>
					<p class="tsosk-maint-logo-actions">
						<button type="button" class="button" id="tsosk-m-logo-select">
							<?php esc_html_e( 'Select logo', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
						<button type="button" class="button" id="tsosk-m-logo-remove"
								<?php disabled( ! $logo_id ); ?>>
							<?php esc_html_e( 'Remove logo', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</button>
					</p>
				</div>
				<p class="description">
					<?php esc_html_e( 'Shown above the site name on the maintenance page. Leave empty to use the default wrench icon.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label for="tsosk-m-logo-width"><strong><?php esc_html_e( 'Logo width (px)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="number" id="tsosk-m-logo-width" data-m-field="logo_width" min="40" max="1200" step="10" style="width:110px;"
						value="<?php echo $logo_width > 0 ? esc_attr( (string) $logo_width ) : ''; ?>" placeholder="400">
				<p class="description">
					<?php esc_html_e( 'Maximum width of the logo on the maintenance page, between 40 and 1200. Leave empty for the default size (up to 400 px wide and 220 px tall). On small screens the logo always shrinks to fit.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Page heading', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-m-page-title" class="regular-text" style="width:100%;max-width:480px;"
						value="<?php echo esc_attr( $settings['page_title'] ?? '' ); ?>"
						placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<p class="description">
					<?php esc_html_e( 'Large title shown on the maintenance page (e.g. your brand name). Leave empty to use the site name.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label for="tsosk-m-subtitle"><strong><?php esc_html_e( 'Subtitle (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-m-subtitle" data-m-field="subtitle" class="regular-text" style="width:100%;max-width:480px;"
						value="<?php echo esc_attr( (string) $settings['subtitle'] ); ?>">
				<p class="description">
					<?php esc_html_e( 'A short line shown under the heading, for example “We are improving the site”.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Message to Visitors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<textarea name="tsosk_m_message" id="tsosk-m-message" rows="4" style="width:100%;"><?php echo esc_textarea( (string) $settings['message'] ); ?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: %s: default maintenance message */
						esc_html__( 'Leave empty for the default: “%s”', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( $this->get_default_message() )
					);
					?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label for="tsosk-m-contact"><strong><?php esc_html_e( 'Contact (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="text" id="tsosk-m-contact" data-m-field="contact" class="regular-text" style="width:100%;max-width:480px;"
						value="<?php echo esc_attr( (string) $settings['contact'] ); ?>" placeholder="info@example.com">
				<p class="description">
					<?php esc_html_e( 'An email address or a web address (https://…) shown at the bottom of the page as a link. Anything else is ignored.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>

			<div class="tsosk-field-row" style="margin-top:12px;">
				<label><strong><?php esc_html_e( 'Whitelisted IP Addresses (one per line)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<textarea name="tsosk_m_ips" id="tsosk-m-ips" rows="4" style="width:100%;font-family:monospace;"><?php echo esc_textarea( (string) $settings['whitelist_ips'] ); ?></textarea>
				<p class="description">
					<?php
					echo esc_html__( 'Your current IP:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
					echo ' <code>' . esc_html( $this->get_client_ip() ) . '</code>';
					?>
				</p>
				<p class="description"><?php esc_html_e( 'You can also use CIDR ranges, for example 203.0.113.0/24.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Appearance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-maint-colors">
				<?php
				$color_labels = array(
					'bg_color'     => __( 'Background', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'title_color'  => __( 'Heading', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'text_color'   => __( 'Text', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'accent_color' => __( 'Accent (countdown and links)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
				foreach ( $color_labels as $key => $label ) :
					$value = sanitize_hex_color( (string) $settings[ $key ] );
					$value = $value ? $value : self::DEFAULT_COLORS[ $key ];
					?>
				<label class="tsosk-maint-color">
					<input type="color" data-m-field="<?php echo esc_attr( $key ); ?>" data-default="<?php echo esc_attr( self::DEFAULT_COLORS[ $key ] ); ?>" value="<?php echo esc_attr( $value ); ?>">
					<span><?php echo esc_html( $label ); ?></span>
				</label>
				<?php endforeach; ?>
				<button type="button" class="button" id="tsosk-m-colors-reset"><?php esc_html_e( 'Restore default colours', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
			</div>

			<div class="tsosk-field-row" style="margin-top:14px;">
				<label for="tsosk-m-custom-css"><strong><?php esc_html_e( 'Additional CSS (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<textarea id="tsosk-m-custom-css" data-m-field="custom_css" rows="4" style="width:100%;font-family:monospace;" maxlength="5000"><?php echo esc_textarea( (string) $settings['custom_css'] ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Advanced: CSS applied only to the maintenance page, for example .tsosk-maint-box h1 { letter-spacing: 2px; }. HTML tags are removed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Return date shown to visitors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Optional. Tell visitors when you expect the site to be back. This date is only informative: it does not switch maintenance mode on or off.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description" style="margin-top:0;">
				<?php
				printf(
					/* translators: %s: site time zone, e.g. Europe/Madrid */
					esc_html__( 'All dates use the site time zone (%s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( wp_timezone_string() )
				);
				?>
			</p>

			<div class="tsosk-field-row">
				<label for="tsosk-m-return-at"><strong><?php esc_html_e( 'Return date and time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></label>
				<input type="datetime-local" id="tsosk-m-return-at" data-m-field="return_at" value="<?php echo esc_attr( (string) $settings['return_at'] ); ?>">
				<label class="tsosk-check-row" style="margin-top:8px;">
					<input type="checkbox" id="tsosk-m-show-countdown" data-m-field="show_countdown" value="1" <?php checked( ! empty( $settings['show_countdown'] ) ); ?>>
					<?php esc_html_e( 'Show a live countdown', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Visitors see “Expected back” with this date. Search engines are told to come back at that time (Retry-After header); without a date the header says one hour.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Automatic on/off schedule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Optional. Switch maintenance mode on and off by itself between two dates, without pressing the button above. Leave both empty to control it by hand only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<p class="description" style="margin-top:0;">
				<?php
				printf(
					/* translators: %s: site time zone, e.g. Europe/Madrid */
					esc_html__( 'All dates use the site time zone (%s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					esc_html( wp_timezone_string() )
				);
				?>
			</p>

			<div class="tsosk-field-row">
				<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
					<label><?php esc_html_e( 'Start', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						<input type="datetime-local" id="tsosk-m-sched-start" data-m-field="schedule_start" value="<?php echo esc_attr( $sched_from ); ?>">
					</label>
					<label><?php esc_html_e( 'End', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						<input type="datetime-local" id="tsosk-m-sched-end" data-m-field="schedule_end" value="<?php echo esc_attr( $sched_to ); ?>">
					</label>
					<button type="button" class="button" id="tsosk-m-sched-clear"><?php esc_html_e( 'Clear schedule', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				</div>
				<p class="description">
					<?php esc_html_e( 'Maintenance mode switches on at the start and off at the end by itself. If you only set an end date, it switches off at that time when you enabled it by hand.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>
		</div>

		<div class="tsosk-card">
			<button class="button button-primary" id="tsosk-maintenance-save"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<button type="button" class="button" id="tsosk-maintenance-preview"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Preview maintenance page', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Preview opens in a new tab using the settings above (even if you have not saved yet). Maintenance mode stays off.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<span class="tsosk-ajax-msg" id="tsosk-maintenance-save-msg"></span>
		</div>
		<?php
	}

	/**
	 * Whether an IP matches any entry of a list of exact IPs or CIDR ranges (IPv4 and IPv6).
	 *
	 * @param string   $ip      Visitor IP.
	 * @param string[] $entries Exact IPs or ranges such as 203.0.113.0/24.
	 * @return bool
	 */
	private function ip_in_list( string $ip, array $entries ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $packed ) {
			return in_array( $ip, $entries, true );
		}
		foreach ( $entries as $entry ) {
			$entry = (string) $entry;
			$bits  = null;
			if ( false !== strpos( $entry, '/' ) ) {
				list( $entry, $raw_bits ) = explode( '/', $entry, 2 );
				$bits                     = (int) $raw_bits;
			}
			$net = @inet_pton( trim( $entry ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$max   = strlen( $packed ) * 8;
			$bits  = null === $bits ? $max : max( 0, min( $max, $bits ) );
			$bytes = intdiv( $bits, 8 );
			if ( $bytes > 0 && substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( $rest > 0 ) {
				$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
				if ( ( ord( $packed[ $bytes ] ) & $mask ) !== ( ord( $net[ $bytes ] ) & $mask ) ) {
					continue;
				}
			}
			return true;
		}
		return false;
	}
}
