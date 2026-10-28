<?php
/**
 * TSO Swiss Knife – Module: Comment Anti-Spam.
 *
 * Layered spam protection for WordPress comments and contact forms:
 * honeypot, timing checks, rate limits, heuristics, and optional cloud checks.
 *
 * @package TSO_Swiss_Knife
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Comment_Antispam
 */
class TSOSK_Mod_Comment_Antispam {

	/** Settings option key. */
	public const OPTION = 'tsosk_comment_antispam';

	/** Remote provider health/usage option key. */
	private const OPTION_PROVIDERS = 'tsosk_cas_providers';

	/** Legacy option-based logs (moved to the log table once, then deleted). */
	private const LEGACY_LOGS = array(
		'tsosk_cas_log'          => 'block',
		'tsosk_cas_learning_log' => 'learning',
	);

	/** Rules that can be put in observe-only mode (reason keys). */
	private const OBSERVABLE_RULES = array( 'honeypot', 'time_trap', 'rate_limit', 'max_links', 'disposable_email', 'cyrillic', 'keyword', 'blocked_url', 'duplicate', 'stopforumspam', 'abuseipdb', 'honeypot_httpbl', 'cloud' );

	/** Log table schema version. */
	private const DB_VERSION = '1';

	/** Log table schema version option. */
	private const OPTION_DB_VERSION = 'tsosk_cas_db_version';

	/**
	 * Silent form block flag (honeypot fake success).
	 *
	 * @var bool
	 */
	private bool $form_silent_block = false;

	/**
	 * Visitor-facing message of the last blocked Contact Form 7 submission.
	 *
	 * @var string
	 */
	private string $form_block_message = '';

	/**
	 * Unique honeypot field id suffix per rendered form.
	 *
	 * @var int
	 */
	private static int $trap_field_counter = 0;

	/** Honeypot field name (must not match real comment fields). */
	private const HONEYPOT_FIELD = 'tsosk_cas_author_url';

	/** Time token field name. */
	private const TIME_FIELD = 'tsosk_cas_time';

	/** CleanTalk moderation API endpoint. */
	private const CLEANTALK_API = 'https://moderate.cleantalk.org/api2.0';

	/** StopForumSpam API endpoint. */
	private const STOPFORUMSPAM_API = 'https://api.stopforumspam.org/api';

	/** AbuseIPDB check endpoint. */
	private const ABUSEIPDB_API = 'https://api.abuseipdb.com/api/v2/check';

	/** Reputation lookup cache TTL (seconds). */
	private const REPUTATION_CACHE_TTL = 21600;

	/** Common disposable email domains (subset). */
	private const DISPOSABLE_DOMAINS = array(
		'mailinator.com',
		'guerrillamail.com',
		'guerrillamail.net',
		'tempmail.com',
		'temp-mail.org',
		'10minutemail.com',
		'throwaway.email',
		'yopmail.com',
		'trashmail.com',
		'getnada.com',
		'maildrop.cc',
		'sharklasers.com',
		'grr.la',
		'dispostable.com',
		'mailnesia.com',
		'fakeinbox.com',
		'guerrillamail.org',
		'guerrillamail.biz',
		'guerrillamail.de',
		'guerrillamail.info',
		'guerrillamailblock.com',
		'spam4.me',
		'temp-mail.io',
		'tempmail.net',
		'tempmailo.com',
		'10minutemail.net',
		'20minutemail.com',
		'throwawaymail.com',
		'yopmail.fr',
		'yopmail.net',
		'trashmail.net',
		'trashmail.de',
		'nada.email',
		'mintemail.com',
		'mytemp.email',
		'mohmal.com',
		'emailondeck.com',
		'mailcatch.com',
		'mailnull.com',
		'spambox.us',
		'spamgourmet.com',
		'tempinbox.com',
		'tempr.email',
		'dropmail.me',
		'discard.email',
		'burnermail.io',
		'moakt.com',
		'tmail.ws',
		'tmpmail.org',
		'tmpmail.net',
		'fakemail.net',
		'fakemailgenerator.com',
		'harakirimail.com',
		'incognitomail.com',
		'jetable.org',
		'mailforspam.com',
		'mailmetrash.com',
		'mailtemp.info',
		'nowmymail.com',
		'owlymail.com',
		'pokemail.net',
		'sogetthis.com',
		'spamex.com',
		'suremail.info',
		'tempail.com',
		'tempemail.net',
		'tempmailaddress.com',
		'tempmailer.com',
		'throwam.com',
		'trash-mail.com',
		'wegwerfmail.de',
		'wegwerfmail.net',
		'wegwerfmail.org',
		'zetmail.com',
		'emailfake.com',
		'email-fake.com',
		'crazymailing.com',
		'inboxbear.com',
		'inboxkitten.com',
		'luxusmail.org',
		'mailpoof.com',
		'mailsac.com',
		'minuteinbox.com',
	);

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Comment_Antispam|null
	 */
	private static $instance = null;

	/**
	 * Module settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

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
		add_action( 'wp_ajax_tsosk_cas_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_cas_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_tsosk_cas_clear_learning_log', array( $this, 'ajax_clear_learning_log' ) );
		add_action( 'wp_ajax_tsosk_cas_test_provider', array( $this, 'ajax_test_provider' ) );
		add_action( 'wp_ajax_tsosk_cas_allow_ip', array( $this, 'ajax_allow_ip' ) );
		add_action( 'wp_ajax_tsosk_cas_test_alert', array( $this, 'ajax_test_alert' ) );
		add_action( 'admin_post_tsosk_cas_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Register frontend hooks when enabled.
	 */
	public function init(): void {
		$this->settings = self::get_settings();
		$this->maybe_upgrade_storage();
		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( empty( $this->settings['protect_comments'] ) && empty( $this->settings['protect_contact_forms'] ) && empty( $this->settings['protect_registration'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['protect_comments'] ) ) {
			add_filter( 'preprocess_comment', array( $this, 'filter_comment' ), 1 );
			add_filter( 'pre_comment_approved', array( $this, 'force_comment_status' ), 99, 2 );
			add_action( 'comment_form_after_fields', array( $this, 'render_honeypot_fields' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'render_honeypot_fields' ) );
		}

		if ( ! empty( $this->settings['protect_contact_forms'] ) ) {
			$this->register_form_hooks();
		}

		if ( ! empty( $this->settings['protect_registration'] ) ) {
			add_action( 'register_form', array( $this, 'render_honeypot_fields' ) );
			add_filter( 'registration_errors', array( $this, 'registration_errors' ), 20, 3 );
			add_action( 'woocommerce_register_form', array( $this, 'render_honeypot_fields' ) );
			add_filter( 'woocommerce_process_registration_errors', array( $this, 'woo_registration_errors' ), 20, 4 );
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register integration hooks for popular form plugins.
	 */
	private function register_form_hooks(): void {
		// Contact Form 7.
		if ( class_exists( 'WPCF7' ) ) {
			add_filter( 'wpcf7_form_hidden_fields', array( $this, 'cf7_hidden_fields' ) );
			add_filter( 'wpcf7_form_elements', array( $this, 'cf7_form_elements' ) );
			add_filter( 'wpcf7_spam', array( $this, 'cf7_spam_check' ), 20, 2 );
			add_filter( 'wpcf7_feedback_response', array( $this, 'cf7_feedback_response' ), 20, 2 );
		}

		// WPForms.
		if ( function_exists( 'wpforms' ) ) {
			// "wpforms_frontend_output" fires inside the <form> element (WPForms' own honeypot uses it); the
			// "container_before" hook fires outside it, so the fields would never be submitted.
			add_action( 'wpforms_frontend_output', array( $this, 'output_trap_fields' ), 10, 0 );
			add_filter( 'wpforms_process_before_filter', array( $this, 'wpforms_process_before' ), 5, 3 );
		}

		// Gravity Forms.
		if ( class_exists( 'GFCommon' ) ) {
			add_filter( 'gform_get_form_filter', array( $this, 'gravity_form_markup' ), 20, 2 );
			add_filter( 'gform_validation', array( $this, 'gravity_validate' ) );
		}

		// Elementor Pro forms.
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			add_action( 'elementor_pro/forms/render/form', array( $this, 'elementor_render_traps' ), 5, 2 );
			add_action( 'elementor_pro/forms/validation', array( $this, 'elementor_validate' ), 10, 2 );
		}

		// Fluent Forms.
		if ( defined( 'FLUENTFORM_VERSION' ) ) {
			add_action( 'fluentform/before_form_render', array( $this, 'fluentform_render_traps' ), 10, 1 );
			add_filter( 'fluentform/validation_errors', array( $this, 'fluentform_validate_errors' ), 10, 4 );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'enabled'                   => false,
			'learning_mode'             => false,
			'protect_comments'          => true,
			'protect_contact_forms'     => true,
			'honeypot'                  => true,
			'time_trap'                 => true,
			'min_submit_seconds'        => 3,
			'max_submit_seconds'        => 7200,
			'rate_limit'                => true,
			'rate_limit_count'          => 3,
			'rate_limit_window'         => 60,
			'max_links'                 => 2,
			'block_keywords'            => '',
			'block_urls'                => '',
			'block_disposable_email'    => true,
			'custom_disposable_domains' => '',
			'duplicate_check'           => true,
			'duplicate_window'          => 60,
			'block_cyrillic'            => false,
			'cloud_mode'                => 'off',
			'cleantalk_key'             => '',
			'stopforumspam_enabled'     => false,
			'sfs_min_confidence'        => 50,
			'abuseipdb_enabled'         => false,
			'abuseipdb_key'             => '',
			'abuseipdb_min_score'       => 75,
			'abuseipdb_max_age_days'    => 30,
			'honeypot_httpbl_enabled'   => false,
			'honeypot_access_key'       => '',
			'honeypot_min_threat'       => 25,
			'skip_logged_in'            => true,
			'whitelist_ips'             => '',
			'spam_action'               => 'spam',
			'log_blocks'                => true,
			'log_max'                   => 1000,
			'protect_reviews'           => true,
			'protect_registration'      => false,
			'ban_enabled'               => false,
			'ban_threshold'             => 5,
			'ban_hours'                 => 24,
			'trust_returning'           => true,
			'block_message'             => '',
			'observe_rules'             => array(),
			'alert_enabled'             => false,
			'alert_email'               => '',
			'alert_threshold'           => 20,
			'alert_provider_errors'     => true,
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Whether protection is active.
	 */
	public function is_active(): bool {
		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Enqueue minimal CSS to hide honeypot fields.
	 */
	public function enqueue_assets(): void {
		$needs_assets = false;

		if ( ! empty( $this->settings['protect_comments'] ) && is_singular() && comments_open() ) {
			$needs_assets = true;
		}

		if ( ( ! empty( $this->settings['protect_contact_forms'] ) || ! empty( $this->settings['protect_registration'] ) ) && ! is_admin() ) {
			$needs_assets = true;
		}

		if ( ! $needs_assets ) {
			return;
		}

		wp_register_style( 'tsosk-cas', false, array(), TSOSK_VERSION );
		wp_enqueue_style( 'tsosk-cas' );
		wp_add_inline_style(
			'tsosk-cas',
			'.tsosk-cas-trap{position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(1px,1px,1px,1px)!important;white-space:nowrap!important;}'
		);
	}

	/**
	 * Output honeypot and signed time token on comment forms.
	 */
	public function render_honeypot_fields(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_trap_fields_html().
		echo $this->get_trap_fields_html();
	}

	/**
	 * Output honeypot and time token markup for contact forms.
	 */
	public function output_trap_fields(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_trap_fields_html().
		echo $this->get_trap_fields_html();
	}

	/**
	 * Fluent Forms: output traps once per form.
	 *
	 * @param object $form Form object.
	 */
	public function fluentform_render_traps( $form ): void {
		static $rendered = array();
		$form_id         = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;
		if ( $form_id > 0 && isset( $rendered[ $form_id ] ) ) {
			return;
		}
		if ( $form_id > 0 ) {
			$rendered[ $form_id ] = true;
		}
		$this->output_trap_fields();
	}

	/**
	 * Elementor Pro: output traps once per form widget instance.
	 *
	 * @param mixed $instance Form instance.
	 * @param mixed $widget   Form widget.
	 */
	public function elementor_render_traps( $instance = null, $widget = null ): void {
		unset( $instance );
		static $rendered = array();

		$widget_id = 0;
		if ( is_object( $widget ) && method_exists( $widget, 'get_id' ) ) {
			$widget_id = (int) $widget->get_id();
		}

		$key = $widget_id > 0 ? 'w' . $widget_id : 'default';
		if ( isset( $rendered[ $key ] ) ) {
			return;
		}
		$rendered[ $key ] = true;
		$this->output_trap_fields();
	}

	/**
	 * Honeypot + time token HTML shared by comments and forms.
	 */
	private function get_trap_fields_html(): string {
		if ( empty( $this->settings['honeypot'] ) && empty( $this->settings['time_trap'] ) ) {
			return '';
		}

		++self::$trap_field_counter;
		$field_id = self::HONEYPOT_FIELD . '-' . self::$trap_field_counter;

		$html = '';
		if ( ! empty( $this->settings['honeypot'] ) ) {
			$html .= '<p class="tsosk-cas-trap" aria-hidden="true">';
			$html .= '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Website', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</label>';
			$html .= '<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" id="' . esc_attr( $field_id ) . '" value="" tabindex="-1" autocomplete="off" />';
			$html .= '</p>';
		}
		if ( ! empty( $this->settings['time_trap'] ) ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::TIME_FIELD ) . '" value="' . esc_attr( $this->create_time_token() ) . '" />';
		}
		return $html;
	}

	/**
	 * CF7: inject time token as hidden field.
	 *
	 * @param array<string, string> $fields Hidden fields.
	 * @return array<string, string>
	 */
	public function cf7_hidden_fields( array $fields ): array {
		if ( ! empty( $this->settings['time_trap'] ) ) {
			$fields[ self::TIME_FIELD ] = $this->create_time_token();
		}
		return $fields;
	}

	/**
	 * CF7: append honeypot markup.
	 *
	 * @param string $form Form HTML.
	 */
	public function cf7_form_elements( string $form ): string {
		if ( empty( $this->settings['honeypot'] ) ) {
			return $form;
		}
		return $form . $this->get_honeypot_html_only();
	}

	/**
	 * Honeypot only (CF7 time field uses hidden_fields).
	 */
	private function get_honeypot_html_only(): string {
		if ( empty( $this->settings['honeypot'] ) ) {
			return '';
		}
		$html  = '<p class="tsosk-cas-trap" aria-hidden="true">';
		$html .= '<label for="' . esc_attr( self::HONEYPOT_FIELD ) . '">' . esc_html__( 'Website', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</label>';
		$html .= '<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off" />';
		$html .= '</p>';
		return $html;
	}

	/**
	 * CF7 spam filter.
	 *
	 * @param bool                  $spam       Whether CF7 already marked spam.
	 * @param WPCF7_Submission|null $submission Submission instance.
	 */
	public function cf7_spam_check( bool $spam, $submission ): bool {
		if ( $spam || ! $submission || ! is_a( $submission, 'WPCF7_Submission' ) ) {
			return $spam;
		}

		$data = $this->extract_fields_from_array( (array) $submission->get_posted_data(), 'cf7' );
		return $this->block_form_submission( $data );
	}

	/**
	 * CF7: fake success response after silent honeypot block.
	 *
	 * @param array<string, mixed> $response Response data.
	 * @param string               $status   Result status.
	 * @return array<string, mixed>
	 */
	public function cf7_feedback_response( $response, $result = '' ) {
		if ( ! is_array( $response ) ) {
			return $response;
		}
		// Contact Form 7 passes the submission result as an array ('status' => ...); older code paths pass the status itself.
		$status = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : (string) $result;
		if ( '' === $status ) {
			$status = (string) ( $response['status'] ?? '' );
		}
		if ( 'spam' !== $status ) {
			return $response;
		}

		if ( $this->form_silent_block ) {
			$this->form_silent_block = false;
			$response['status']      = 'mail_sent';
			$response['message']     = apply_filters(
				'tsosk_cf7_silent_block_success_message',
				__( 'Thank you for your message. It has been sent.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		} elseif ( '' !== $this->form_block_message ) {
			// Show the reason (or the custom "message for blocked visitors") instead of CF7's generic spam text.
			$response['message'] = $this->form_block_message;
		}
		return $response;
	}

	/**
	 * WPForms: validate before processing.
	 *
	 * @param array<string, mixed> $fields    Sanitized fields.
	 * @param array<string, mixed> $entry     Raw entry.
	 * @param array<string, mixed> $form_data Form configuration.
	 * @return array<string, mixed>
	 */
	public function wpforms_process_before( $fields, $entry = array(), $form_data = null ) {
		// WPForms passes ($entry, $form_data); accept the three-argument form too so a missing argument can never be fatal.
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		if ( null === $form_data ) {
			$form_data = is_array( $entry ) ? $entry : array();
		}
		$form_data = is_array( $form_data ) ? $form_data : array();

		// The raw entry keys its values by numeric field id: name them after the field definition so the
		// email, name and message are recognised.
		$values = isset( $fields['fields'] ) && is_array( $fields['fields'] ) ? $fields['fields'] : $fields;
		$flat   = array();
		foreach ( $values as $field_id => $value ) {
			if ( ! is_scalar( $value ) && ! is_array( $value ) ) {
				continue;
			}
			$def   = isset( $form_data['fields'][ $field_id ] ) && is_array( $form_data['fields'][ $field_id ] ) ? $form_data['fields'][ $field_id ] : array();
			$type  = strtolower( (string) ( $def['type'] ?? '' ) );
			$label = strtolower( (string) ( $def['label'] ?? '' ) );
			if ( 'email' === $type ) {
				$key = 'email';
			} elseif ( 'name' === $type ) {
				$key = 'name';
			} elseif ( '' !== $label ) {
				$key = $label;
			} else {
				$key = (string) $field_id;
			}
			if ( isset( $flat[ $key ] ) ) {
				$key .= '-' . $field_id;
			}
			$flat[ $key ] = $value;
		}

		$data = $this->extract_fields_from_array( $flat, 'wpforms' );
		if ( $this->should_skip_submission( $data ) ) {
			return $fields;
		}

		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return $fields;
		}

		$form_id = (int) ( $form_data['id'] ?? ( $fields['id'] ?? 0 ) );
		$this->handle_form_block( $data, $check, 'wpforms', $form_id );

		return $fields;
	}

	/**
	 * Gravity Forms: append trap fields to form markup.
	 *
	 * @param string              $form_string Form HTML.
	 * @param array<string,mixed> $form        Form config.
	 */
	public function gravity_form_markup( string $form_string, array $form ): string {
		unset( $form );
		$trap = $this->get_trap_fields_html();
		if ( '' === $trap ) {
			return $form_string;
		}
		return str_replace( '</form>', $trap . '</form>', $form_string );
	}

	/**
	 * Gravity Forms validation.
	 *
	 * @param array<string, mixed> $validation_result Validation state.
	 * @return array<string, mixed>
	 */
	public function gravity_validate( array $validation_result ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated by Gravity Forms.
		$form_id = isset( $_POST['gform_submit'] ) ? absint( wp_unslash( $_POST['gform_submit'] ) ) : 0;
		if ( $form_id <= 0 ) {
			return $validation_result;
		}

		$data = $this->extract_gravity_fields();
		if ( $this->should_skip_submission( $data ) ) {
			return $validation_result;
		}

		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return $validation_result;
		}

		$validation_result['is_valid'] = false;
		$form                          = $validation_result['form'];
		foreach ( $form['fields'] as &$field ) {
			if ( in_array( $field->type, array( 'textarea', 'text', 'email' ), true ) ) {
				$field->failed_validation  = true;
				$field->validation_message = $check['silent']
					? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: $this->public_message( $check );
				break;
			}
		}
		unset( $field );
		$validation_result['form'] = $form;

		return $validation_result;
	}

	/**
	 * Elementor Pro forms validation.
	 *
	 * @param ElementorPro\Modules\Forms\Classes\Form_Record  $record       Form record.
	 * @param ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Handler.
	 */
	public function elementor_validate( $record, $ajax_handler ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$fields = $record->get( 'fields' );
		if ( ! is_array( $fields ) ) {
			return;
		}

		$flat = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! array_key_exists( 'value', $field ) ) {
				continue;
			}
			$key = '';
			if ( ! empty( $field['type'] ) ) {
				$key = (string) $field['type'];
			}
			if ( ! empty( $field['title'] ) ) {
				$key = (string) $field['title'];
			}
			if ( '' === $key && isset( $field['id'] ) ) {
				$key = (string) $field['id'];
			}
			if ( '' === $key ) {
				continue;
			}
			// Two fields of the same type without a title would overwrite each other and lose content.
			if ( isset( $flat[ $key ] ) ) {
				$key .= '-' . ( isset( $field['id'] ) ? (string) $field['id'] : (string) count( $flat ) );
			}
			$flat[ $key ] = $field['value'];
		}

		$data = $this->extract_fields_from_array( $flat, 'elementor' );
		if ( $this->should_skip_submission( $data ) ) {
			return;
		}

		// Same path as the other integrations, so blocks are logged and Learning mode / observed rules are honoured.
		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return;
		}

		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message(
				$check['silent']
					? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: $this->public_message( $check )
			);
		}
	}

	/**
	 * Fluent Forms validation.
	 *
	 * @param array<string, mixed> $errors    Validation errors.
	 * @param array<string, mixed> $form_data Submitted data.
	 * @param object               $form      Form object.
	 * @param array<string, mixed> $fields    Form fields.
	 * @return array<string, mixed>
	 */
	public function fluentform_validate_errors( array $errors, array $form_data, $form, array $fields ): array {
		unset( $form, $fields );
		$data = $this->extract_fields_from_array( $form_data, 'fluentform' );
		if ( $this->should_skip_submission( $data ) ) {
			return $errors;
		}

		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return $errors;
		}

		$errors['tsosk_cas'] = array(
			$check['silent']
				? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: $this->public_message( $check ),
		);

		return $errors;
	}

	/**
	 * Run the checks for a form submission.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return bool True when the submission must be blocked.
	 */
	private function block_form_submission( array $data ): bool {
		if ( $this->should_skip_submission( $data ) ) {
			return false;
		}

		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return false;
		}

		if ( ! empty( $check['silent'] ) ) {
			$this->form_silent_block = true;
		} else {
			$this->form_block_message = $this->public_message( $check );
		}

		return true;
	}

	/**
	 * Handle blocked WPForms submission.
	 *
	 * @param array<string, mixed> $data     Submission data.
	 * @param array<string, mixed> $check    Check result.
	 * @param string               $provider Provider slug.
	 * @param int                  $form_id  Form ID.
	 */
	private function handle_form_block( array $data, array $check, string $provider, int $form_id = 0 ): void {
		unset( $data );
		$message = $check['silent']
			? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			: $this->public_message( $check );

		if ( 'wpforms' === $provider && function_exists( 'wpforms' ) && $form_id > 0 ) {
			if ( ! isset( wpforms()->process->errors[ $form_id ] ) || ! is_array( wpforms()->process->errors[ $form_id ] ) ) {
				wpforms()->process->errors[ $form_id ] = array();
			}
			wpforms()->process->errors[ $form_id ]['header'] = $message;
		}
	}

	/**
	 * Extract normalized submission from flat field array.
	 *
	 * @param array<string, mixed> $fields Field values.
	 * @param string               $source Provider slug.
	 * @return array<string, mixed>
	 */
	private function extract_fields_from_array( array $fields, string $source ): array {
		$author = '';
		$email  = '';
		$url    = '';
		$parts  = array();

		foreach ( $fields as $key => $value ) {
			if ( in_array( (string) $key, array( self::HONEYPOT_FIELD, self::TIME_FIELD ), true ) ) {
				continue;
			}

			$key_lower = strtolower( (string) $key );
			if ( is_array( $value ) ) {
				if ( isset( $value['type'] ) && in_array( (string) $value['type'], array( 'honeypot', 'hidden' ), true ) ) {
					continue;
				}
				if ( ! empty( $value['name'] ) ) {
					$key_lower = strtolower( (string) $value['name'] );
				} elseif ( ! empty( $value['label'] ) ) {
					$key_lower = strtolower( (string) $value['label'] );
				}
			}

			$value = $this->normalize_field_value( $value );

			if ( '' === $value ) {
				continue;
			}

			if ( '' === $email && $this->is_email_field_key( $key_lower ) && is_email( $value ) ) {
				$email = sanitize_email( $value );
				continue;
			}

			if ( '' === $author && $this->is_name_field_key( $key_lower ) ) {
				$author = sanitize_text_field( $value );
				continue;
			}

			if ( '' === $url && $this->is_url_field_key( $key_lower ) ) {
				$url = esc_url_raw( $value );
				continue;
			}

			$parts[] = sanitize_text_field( (string) $key ) . ': ' . sanitize_text_field( $value );
		}

		return array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => $url,
			'comment_content'      => implode( "\n", $parts ),
			'comment_post_ID'      => get_queried_object_id(),
			'source'               => $source,
		);
	}

	/**
	 * Normalize plugin field values (WPForms/Gravity nested arrays).
	 *
	 * @param mixed $value Raw field value.
	 */
	private function normalize_field_value( $value ): string {
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'value', $value ) ) {
				return $this->normalize_field_value( $value['value'] );
			}
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Is email field key.
	 *
	 * @param string $key Lowercase field key.
	 */
	private function is_email_field_key( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(email|correo|e-mail|mail)(?:$|[-_])/i', $key );
	}

	/**
	 * Is name field key.
	 *
	 * @param string $key Lowercase field key.
	 */
	private function is_name_field_key( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(name|nombre|author|fname|lname|first-name|last-name|your-name)(?:$|[-_])/i', $key );
	}

	/**
	 * Is url field key.
	 *
	 * @param string $key Lowercase field key.
	 */
	private function is_url_field_key( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(url|website|web|site)(?:$|[-_])/i', $key );
	}

	/**
	 * Extract Gravity Forms submission using field metadata when available.
	 *
	 * @return array<string, mixed>
	 */
	private function extract_gravity_fields(): array {
		if ( ! function_exists( 'rgpost' ) ) {
			return $this->extract_fields_from_post( 'gravity' );
		}

		$form_id = absint( rgpost( 'gform_submit' ) );
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return $this->extract_fields_from_post( 'gravity' );
		}

		$form = GFAPI::get_form( $form_id );
		if ( is_wp_error( $form ) || empty( $form['fields'] ) ) {
			return $this->extract_fields_from_post( 'gravity' );
		}

		$author = '';
		$email  = '';
		$url    = '';
		$parts  = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Gravity Forms validates submission.
		$post_data = $this->sanitize_provider_post( wp_unslash( $_POST ) );
		if ( ! is_array( $post_data ) ) {
			$post_data = array();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) || empty( $field->id ) ) {
				continue;
			}

			$raw_value = class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_field_value' )
				? GFFormsModel::get_field_value( $field, $post_data )
				: null;

			$input_key = 'input_' . (string) $field->id;
			if ( null === $raw_value ) {
				if ( ! isset( $post_data[ $input_key ] ) ) {
					continue;
				}
				$raw_value = $post_data[ $input_key ];
			}

			$value = $this->normalize_field_value( $raw_value );
			if ( '' === $value ) {
				continue;
			}

			$label = strtolower( (string) ( $field->label ?? $input_key ) );
			$type  = strtolower( (string) ( $field->type ?? '' ) );

			if ( '' === $email && ( 'email' === $type || $this->is_email_field_key( $label ) ) && is_email( $value ) ) {
				$email = sanitize_email( $value );
				continue;
			}

			if ( '' === $author && ( in_array( $type, array( 'name', 'text' ), true ) && $this->is_name_field_key( $label ) ) ) {
				$author = sanitize_text_field( $value );
				continue;
			}

			if ( '' === $url && ( 'website' === $type || $this->is_url_field_key( $label ) ) ) {
				$url = esc_url_raw( $value );
				continue;
			}

			$parts[] = sanitize_text_field( (string) ( $field->label ?? $input_key ) ) . ': ' . sanitize_text_field( $value );
		}

		return array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => $url,
			'comment_content'      => implode( "\n", $parts ),
			'comment_post_ID'      => get_queried_object_id(),
			'source'               => 'gravity',
		);
	}

	/**
	 * Recursively sanitize POST data from third-party form providers.
	 *
	 * @param mixed $post Raw POST array or value.
	 * @return array<string, mixed>|string
	 */
	private function sanitize_provider_post( $post ) {
		if ( ! is_array( $post ) ) {
			return sanitize_textarea_field( (string) $post );
		}

		$sanitized = array();
		foreach ( $post as $key => $value ) {
			$key = $this->sanitize_provider_post_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$sanitized[ $key ] = is_array( $value )
				? $this->sanitize_provider_post( $value )
				: sanitize_textarea_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a third-party form POST key without stripping Gravity Forms dots/brackets.
	 *
	 * @param string $key Raw POST key.
	 * @return string
	 */
	private function sanitize_provider_post_key( string $key ): string {
		$key = preg_replace( '/[^a-zA-Z0-9_\-\[\]\.]/', '', $key );
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}
		if ( strlen( $key ) > 191 ) {
			$key = substr( $key, 0, 191 );
		}
		return $key;
	}

	/**
	 * Extract submission from raw POST (Gravity Forms).
	 *
	 * @param string $source Provider slug.
	 * @return array<string, mixed>
	 */
	private function extract_fields_from_post( string $source ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Provider validates nonce.
		$post = wp_unslash( $_POST );
		if ( ! is_array( $post ) ) {
			$post = array();
		}
		$raw = $this->sanitize_provider_post( $post );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $this->extract_fields_from_array( is_array( $raw ) ? $raw : array(), $source );
	}

	/**
	 * Detect installed form plugins for admin UI.
	 *
	 * @return array<string, bool>
	 */
	public function get_detected_form_plugins(): array {
		return array(
			'cf7'        => class_exists( 'WPCF7' ),
			'wpforms'    => function_exists( 'wpforms' ),
			'gravity'    => class_exists( 'GFCommon' ),
			'elementor'  => defined( 'ELEMENTOR_PRO_VERSION' ),
			'fluentform' => defined( 'FLUENTFORM_VERSION' ),
		);
	}

	/**
	 * Main comment filter.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return array<string, mixed>
	 */
	public function filter_comment( array $commentdata ): array {
		if ( $this->should_skip( $commentdata ) ) {
			return $commentdata;
		}

		$commentdata['source'] = 'comment';
		$check                 = $this->evaluate_submission( $commentdata );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $commentdata );
			return $commentdata;
		}

		if ( $this->is_observed( $check ) ) {
			$this->log_learning_block( $commentdata, $check );
			$this->record_successful_submission( $commentdata );
			return $commentdata;
		}

		$this->log_block( $commentdata, $check );

		if ( ! empty( $check['silent'] ) ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}

		if ( 'discard' === $this->settings['spam_action'] ) {
			wp_die(
				esc_html( $this->public_message( $check ) ),
				esc_html__( 'Comment blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		// WordPress recomputes comment_approved after this filter (wp_allow_comment), so the verdict travels in a
		// private key and is applied in force_comment_status() on pre_comment_approved.
		$commentdata['tsosk_cas_status'] = ( 'trash' === $this->settings['spam_action'] ) ? 'trash' : 'spam';
		return $commentdata;
	}

	/**
	 * Apply the spam/trash verdict set by filter_comment() (pre_comment_approved runs after preprocess_comment).
	 *
	 * @param mixed $approved    Approval status computed by WordPress.
	 * @param mixed $commentdata Comment data, including our private verdict key.
	 * @return mixed
	 */
	public function force_comment_status( $approved, $commentdata = array() ) {
		if ( is_array( $commentdata ) && isset( $commentdata['tsosk_cas_status'] ) && in_array( $commentdata['tsosk_cas_status'], array( 'spam', 'trash' ), true ) ) {
			return $commentdata['tsosk_cas_status'];
		}
		return $approved;
	}

	/**
	 * Whether this comment should bypass all checks.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function should_skip( array $commentdata ): bool {
		$type = (string) ( $commentdata['comment_type'] ?? '' );
		if ( '' !== $type && 'comment' !== $type && ! ( 'review' === $type && ! empty( $this->settings['protect_reviews'] ) ) ) {
			return true;
		}
		return $this->should_skip_submission( $commentdata );
	}

	/**
	 * Whether submission should bypass checks (comments or forms).
	 *
	 * @param array<string, mixed> $data Submission data.
	 */
	private function should_skip_submission( array $data ): bool {
		unset( $data );
		if ( ! empty( $this->settings['skip_logged_in'] ) && is_user_logged_in() ) {
			return true;
		}

		$ip = $this->get_client_ip();
		return '' !== $ip && $this->is_ip_whitelisted( $ip );
	}

	/**
	 * Run all enabled checks on a comment or form submission.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return array{allow:bool,silent:bool,reason:string,message:string}
	 */
	private function evaluate_submission( array $data ): array {
		$fail = static function ( string $reason, string $message, bool $silent = false ): array {
			return array(
				'allow'   => false,
				'silent'  => $silent,
				'reason'  => $reason,
				'message' => $message,
			);
		};

		if ( ! empty( $this->settings['ban_enabled'] ) && $this->is_ip_banned() ) {
			return $fail( 'banned', __( 'Too many blocked attempts from your IP address. Please try again later.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		// Jetpack Comments renders the form inside an iframe, so our hidden trap fields can never be present.
		$via_jetpack = $this->is_jetpack_comment_request();

		if ( ! empty( $this->settings['honeypot'] ) && ! $via_jetpack ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public comment form; honeypot must be empty.
			$trap = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
			if ( '' !== $trap ) {
				return $fail( 'honeypot', __( 'Honeypot triggered.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), true );
			}
		}

		if ( ! empty( $this->settings['time_trap'] ) && ! $via_jetpack ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public comment form.
			$token = isset( $_POST[ self::TIME_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TIME_FIELD ] ) ) : '';
			if ( '' === $token || ! $this->verify_time_token( $token ) ) {
				return $fail(
					'time_trap',
					__( 'Submitted too quickly or the form expired. Please wait a few seconds and try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				);
			}
		}

		if ( ! empty( $this->settings['rate_limit'] ) && $this->is_rate_limited() ) {
			return $fail(
				'rate_limit',
				__( 'Too many submissions from your IP address. Please try again later.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		$content  = (string) ( $data['comment_content'] ?? '' );
		$author   = (string) ( $data['comment_author'] ?? '' );
		$email    = (string) ( $data['comment_author_email'] ?? '' );
		$url      = (string) ( $data['comment_author_url'] ?? '' );
		$combined = strtolower( $content . ' ' . $author . ' ' . $email . ' ' . $url );

		if ( ! empty( $this->settings['max_links'] ) && $this->count_links( $content ) > (int) $this->settings['max_links'] ) {
			return $fail(
				'max_links',
				__( 'Submission contains too many links.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		if ( ! empty( $this->settings['block_disposable_email'] ) && $this->is_disposable_email( $email ) ) {
			return $fail(
				'disposable_email',
				__( 'Disposable email addresses are not allowed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		if ( ! empty( $this->settings['block_cyrillic'] ) && preg_match( '/[\x{0400}-\x{04FF}]/u', $content . $author ) ) {
			return $fail(
				'cyrillic',
				__( 'Comment contains blocked characters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		$keyword = $this->match_blocklist( $combined, (string) $this->settings['block_keywords'] );
		if ( null !== $keyword ) {
			return $fail(
				'keyword',
				/* translators: %s: matched keyword */
				sprintf( __( 'Submission blocked (keyword: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $keyword )
			);
		}

		$url_match = $this->match_blocklist( $combined, (string) $this->settings['block_urls'] );
		if ( null !== $url_match ) {
			return $fail(
				'blocked_url',
				/* translators: %s: matched URL fragment */
				sprintf( __( 'Submission blocked (URL: %s).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $url_match )
			);
		}

		if ( ! empty( $this->settings['duplicate_check'] ) && 'registration' !== ( $data['source'] ?? '' ) && $this->is_duplicate_submission( $data ) ) {
			return $fail(
				'duplicate',
				__( 'Duplicate submission detected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
			);
		}

		// Remote lookups run last: cheap local rules reject obvious spam first, so no visitor data is sent to third parties for it.
		$trusted = ! empty( $this->settings['trust_returning'] ) && 'registration' !== ( $data['source'] ?? '' ) && $this->is_returning_commenter( $email );
		if ( ! $trusted ) {
			$reputation = $this->check_reputation_services( $data );
			if ( null !== $reputation ) {
				return $fail( $reputation['reason'], $reputation['message'] );
			}

			$cloud = $this->check_cloud( $data );
			if ( true === $cloud ) {
				return $fail( 'cloud', __( 'Submission flagged as spam by cloud filter.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
			}
		}

		return array(
			'allow'   => true,
			'silent'  => false,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Check optional reputation blocklists (StopForumSpam, AbuseIPDB, Project Honey Pot).
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return array{reason:string,message:string}|null Block reason when spam, null when allowed.
	 */
	private function check_reputation_services( array $data ): ?array {
		$ip     = $this->get_client_ip();
		$email  = (string) ( $data['comment_author_email'] ?? '' );
		$author = (string) ( $data['comment_author'] ?? '' );

		if ( ! empty( $this->settings['stopforumspam_enabled'] ) ) {
			$sfs = $this->check_stopforumspam( $ip, $email, $author );
			if ( true === $sfs ) {
				return array(
					'reason'  => 'stopforumspam',
					'message' => __( 'Submission blocked by Stop Forum Spam reputation list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
			}
		}

		if ( ! empty( $this->settings['abuseipdb_enabled'] ) && '' !== trim( (string) $this->settings['abuseipdb_key'] ) && '' !== $ip ) {
			$abuse = $this->check_abuseipdb( $ip );
			if ( true === $abuse ) {
				return array(
					'reason'  => 'abuseipdb',
					'message' => __( 'Submission blocked: IP address has a poor abuse reputation score.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
			}
		}

		if ( ! empty( $this->settings['honeypot_httpbl_enabled'] ) && '' !== trim( (string) $this->settings['honeypot_access_key'] ) && '' !== $ip ) {
			$hp = $this->check_honeypot_httpbl( $ip );
			if ( true === $hp ) {
				return array(
					'reason'  => 'honeypot_httpbl',
					'message' => __( 'Submission blocked by Project Honey Pot bot reputation list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				);
			}
		}

		return null;
	}

	/**
	 * StopForumSpam lookup (read-only query; no account or API key needed).
	 *
	 * The 2026 restriction on API signups only affects API keys used to SUBMIT reports; lookups stay anonymous.
	 * The email is sent as an MD5 hash and the username is not sent (names such as "John" give false positives).
	 * Failures (timeout, HTTP error, rate limit) pause lookups for a few minutes so comments are never delayed.
	 *
	 * @param string $ip Ip.
	 * @param string $email Email.
	 * @param string $author Author (unused, kept for signature compatibility).
	 * @return bool|null True = spam, false = ham, null = unavailable.
	 */
	private function check_stopforumspam( string $ip, string $email, string $author ): ?bool {
		unset( $author );

		if ( get_site_transient( 'tsosk_cas_sfs_down' ) ) {
			return null;
		}

		$query = array(
			'f'          => 'json',
			'confidence' => 'true',
		);
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$query['ip'] = $ip;
		}
		if ( is_email( $email ) ) {
			$query['emailhash'] = md5( strtolower( trim( $email ) ) );
		}

		if ( count( $query ) <= 2 ) {
			return null;
		}

		$cache_key = 'tsosk_cas_sfs_' . md5( ( $query['ip'] ?? '' ) . '|' . ( $query['emailhash'] ?? '' ) );
		$cached    = get_site_transient( $cache_key );
		if ( is_bool( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			add_query_arg( $query, self::STOPFORUMSPAM_API ),
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->record_provider( 'stopforumspam', 'error', is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
			set_site_transient( 'tsosk_cas_sfs_down', 1, 10 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || 1 !== (int) ( $body['success'] ?? 0 ) ) {
			// Rate limit, banned IP or a changed API: back off instead of retrying on every comment.
			$this->record_provider( 'stopforumspam', 'error', is_array( $body ) && ! empty( $body['error'] ) ? (string) ( is_array( $body['error'] ) ? wp_json_encode( $body['error'] ) : $body['error'] ) : 'Invalid response' );
			set_site_transient( 'tsosk_cas_sfs_down', 1, 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$min_conf = (float) $this->settings['sfs_min_confidence'];
		$is_spam  = false;

		foreach ( array( 'ip', 'emailhash', 'email' ) as $field ) {
			if ( empty( $body[ $field ] ) || ! is_array( $body[ $field ] ) ) {
				continue;
			}
			if ( ! (int) ( $body[ $field ]['appears'] ?? 0 ) ) {
				continue;
			}
			$confidence = isset( $body[ $field ]['confidence'] ) ? (float) $body[ $field ]['confidence'] : 100.0;
			if ( $confidence >= $min_conf ) {
				$is_spam = true;
				break;
			}
		}

		$this->record_provider( 'stopforumspam', 'ok' );
		set_site_transient( $cache_key, $is_spam, self::REPUTATION_CACHE_TTL );
		return $is_spam;
	}

	/**
	 * AbuseIPDB IP reputation check.
	 *
	 * @param string $ip Ip.
	 * @return bool|null True = spam, false = ham, null = unavailable.
	 */
	private function check_abuseipdb( string $ip ): ?bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$key = trim( (string) $this->settings['abuseipdb_key'] );
		if ( '' === $key ) {
			return null;
		}

		$cache_key = 'tsosk_cas_aipdb_' . md5( $ip );
		$cached    = get_site_transient( $cache_key );
		if ( is_bool( $cached ) ) {
			return $cached;
		}

		$max_age = max( 1, min( 365, (int) $this->settings['abuseipdb_max_age_days'] ) );
		$url     = add_query_arg(
			array(
				'ipAddress'    => $ip,
				'maxAgeInDays' => $max_age,
			),
			self::ABUSEIPDB_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Key'    => $key,
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->record_provider( 'abuseipdb', 'error', $response->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->record_provider( 'abuseipdb', 'error', 'HTTP ' . $code );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return null;
		}

		$this->record_provider( 'abuseipdb', 'ok' );
		$score   = isset( $body['data']['abuseConfidenceScore'] ) ? (int) $body['data']['abuseConfidenceScore'] : 0;
		$min     = max( 1, min( 100, (int) $this->settings['abuseipdb_min_score'] ) );
		$is_spam = $score >= $min;

		set_site_transient( $cache_key, $is_spam, self::REPUTATION_CACHE_TTL );
		return $is_spam;
	}

	/**
	 * Project Honey Pot HTTP:BL DNS lookup (IPv4 only).
	 *
	 * @param string $ip Ip.
	 * @return bool|null True = spam, false = ham, null = unavailable.
	 */
	private function check_honeypot_httpbl( string $ip ): ?bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return null;
		}

		$access_key = trim( (string) $this->settings['honeypot_access_key'] );
		if ( '' === $access_key ) {
			return null;
		}

		$cache_key = 'tsosk_cas_httpbl_' . md5( $ip );
		$cached    = get_site_transient( $cache_key );
		if ( is_bool( $cached ) ) {
			return $cached;
		}

		$reversed = implode( '.', array_reverse( explode( '.', $ip ) ) );
		$lookup   = sanitize_text_field( $access_key ) . '.' . $reversed . '.dnsbl.httpbl.org';

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $lookup, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $records ) || empty( $records[0]['ip'] ) ) {
				$this->record_provider( 'honeypot_httpbl', 'ok' );
				set_site_transient( $cache_key, false, self::REPUTATION_CACHE_TTL );
				return false;
			}
			$result = $records[0]['ip'];
		} else {
			$result = gethostbyname( $lookup );
			if ( $result === $lookup ) {
				$this->record_provider( 'honeypot_httpbl', 'ok' );
				set_site_transient( $cache_key, false, self::REPUTATION_CACHE_TTL );
				return false;
			}
		}

		$octets = explode( '.', $result );
		if ( 4 !== count( $octets ) || '127' !== $octets[0] ) {
			return null;
		}

		$this->record_provider( 'honeypot_httpbl', 'ok' );
		$threat = (int) $octets[2];
		$type   = (int) $octets[3];
		$min    = max( 0, min( 255, (int) $this->settings['honeypot_min_threat'] ) );

		// Type 0 = search engine — never block.
		if ( 0 === $type ) {
			$is_spam = false;
		} elseif ( $type & 4 || $type & 2 ) {
			// Comment spammer or harvester.
			$is_spam = true;
		} elseif ( ( $type & 1 ) && $threat >= $min ) {
			// Suspicious with sufficient threat score.
			$is_spam = true;
		} else {
			$is_spam = false;
		}

		set_site_transient( $cache_key, $is_spam, self::REPUTATION_CACHE_TTL );
		return $is_spam;
	}

	/**
	 * Optional cloud check: Akismet or CleanTalk.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return bool|null True = spam, false = ham, null = skipped/unavailable.
	 */
	private function check_cloud( array $commentdata ): ?bool {
		$mode = (string) $this->settings['cloud_mode'];

		if ( 'akismet' === $mode ) {
			return $this->check_akismet( $commentdata );
		}

		if ( 'cleantalk' === $mode && '' !== trim( (string) $this->settings['cleantalk_key'] ) ) {
			return $this->check_cleantalk( $commentdata );
		}

		return null;
	}

	/**
	 * Check akismet.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function check_akismet( array $commentdata ): ?bool {
		if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'http_post' ) || ! method_exists( 'Akismet', 'get_api_key' ) ) {
			return null;
		}
		if ( '' === (string) Akismet::get_api_key() ) {
			return null;
		}

		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		$payload = array(
			'blog'                 => home_url( '/' ),
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
			'user_ip'              => $this->get_client_ip(),
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'permalink'            => $post_id > 0 ? (string) get_permalink( $post_id ) : '',
			'comment_type'         => ( ! empty( $commentdata['source'] ) && 'comment' !== $commentdata['source'] ) ? 'contact-form' : 'comment',
			'comment_author'       => (string) ( $commentdata['comment_author'] ?? '' ),
			'comment_author_email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
			'comment_author_url'   => (string) ( $commentdata['comment_author_url'] ?? '' ),
			'comment_content'      => (string) ( $commentdata['comment_content'] ?? '' ),
		);

		// Akismet has no public "check this data" method: its own HTTP client is used with the comment-check endpoint.
		$response = Akismet::http_post( http_build_query( $payload ), 'comment-check' );
		if ( ! is_array( $response ) || ! isset( $response[1] ) ) {
			$this->record_provider( 'akismet', 'error', 'No response' );
			return null;
		}

		$verdict = trim( (string) $response[1] );
		$this->record_provider( 'akismet', in_array( $verdict, array( 'true', 'false' ), true ) ? 'ok' : 'error', in_array( $verdict, array( 'true', 'false' ), true ) ? '' : 'Unexpected response' );
		if ( 'true' === trim( (string) $response[1] ) ) {
			return true;
		}
		if ( 'false' === trim( (string) $response[1] ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Check cleantalk.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function check_cleantalk( array $commentdata ): ?bool {
		$key = trim( (string) $this->settings['cleantalk_key'] );
		if ( '' === $key ) {
			return null;
		}

		$payload = array(
			'method_name'     => 'check_message',
			'auth_key'        => $key,
			'sender_email'    => (string) ( $commentdata['comment_author_email'] ?? '' ),
			'sender_ip'       => $this->get_client_ip(),
			'sender_nickname' => (string) ( $commentdata['comment_author'] ?? '' ),
			'sender_url'      => (string) ( $commentdata['comment_author_url'] ?? '' ),
			'message'         => (string) ( $commentdata['comment_content'] ?? '' ),
			'js_on'           => 1,
			'post_info'       => array(
				'comment_type' => ( ! empty( $commentdata['source'] ) && 'comment' !== $commentdata['source'] ) ? 'contact_form' : 'comment',
				// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Falsy fallback of a function result is intended.
				'post_url'     => get_permalink( (int) ( $commentdata['comment_post_ID'] ?? 0 ) ) ?: home_url( '/' ),
			),
		);

		$response = wp_remote_post(
			self::CLEANTALK_API,
			array(
				'timeout' => 8,
				'body'    => wp_json_encode( $payload ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->record_provider( 'cleantalk', 'error', is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['allow'] ) ) {
			$this->record_provider( 'cleantalk', 'error', is_array( $body ) && ! empty( $body['comment'] ) ? sanitize_text_field( (string) $body['comment'] ) : 'Invalid response' );
			return null;
		}

		$this->record_provider( 'cleantalk', 'ok' );
		if ( isset( $body['allow'] ) && 0 === (int) $body['allow'] ) {
			return true;
		}
		if ( isset( $body['allow'] ) && 1 === (int) $body['allow'] ) {
			return false;
		}

		return null;
	}

	/**
	 * Count http/https links in text.
	 *
	 * @param string $text Text.
	 */
	private function count_links( string $text ): int {
		preg_match_all( '#https?://[^\s<>"\']+#i', $text, $matches );
		return isset( $matches[0] ) ? count( $matches[0] ) : 0;
	}

	/**
	 * Match blocklist.
	 *
	 * @param string $haystack Haystack.
	 * @param string $blocklist List.
	 * @return string|null First matched blocklist entry.
	 */
	private function match_blocklist( string $haystack, string $blocklist ): ?string {
		$blocklist = trim( $blocklist );
		if ( '' === $blocklist ) {
			return null;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $blocklist ) as $line ) {
			$line = trim( strtolower( $line ) );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( str_contains( $haystack, $line ) ) {
				return $line;
			}
		}

		return null;
	}

	/**
	 * Whether email domain is disposable.
	 *
	 * @param string $email Email.
	 */
	private function is_disposable_email( string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$domain = substr( strrchr( $email, '@' ), 1 );
		if ( '' === $domain ) {
			return false;
		}

		$domains = self::DISPOSABLE_DOMAINS;
		$custom  = trim( (string) $this->settings['custom_disposable_domains'] );
		if ( '' !== $custom ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $custom ) as $line ) {
				$line = strtolower( trim( $line ) );
				if ( '' !== $line && ! str_starts_with( $line, '#' ) ) {
					$domains[] = ltrim( $line, '@' );
				}
			}
		}

		foreach ( $domains as $bad ) {
			if ( $domain === $bad || str_ends_with( $domain, '.' . $bad ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Duplicate submission from same IP within window.
	 *
	 * @param array<string, mixed> $data Submission data.
	 */
	private function is_duplicate_submission( array $data ): bool {
		$ip      = $this->get_client_ip();
		$content = (string) ( $data['comment_content'] ?? '' );
		$email   = (string) ( $data['comment_author_email'] ?? '' );
		$source  = (string) ( $data['source'] ?? 'comment' );
		$post_id = (int) ( $data['comment_post_ID'] ?? 0 );
		$window  = max( 1, (int) $this->settings['duplicate_window'] ) * MINUTE_IN_SECONDS;

		if ( '' === $ip || ( '' === $content && '' === $email ) ) {
			return false;
		}

		if ( 'comment' === $source || ! isset( $data['source'] ) ) {
			global $wpdb;
			if ( $post_id <= 0 || '' === $content ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments}
					WHERE comment_post_ID = %d
					AND comment_author_IP = %s
					AND comment_content = %s
					AND comment_date_gmt >= %s
					LIMIT 1",
					$post_id,
					$ip,
					$content,
					gmdate( 'Y-m-d H:i:s', time() - $window )
				)
			);

			return ! empty( $found );
		}

		return false !== get_transient( 'tsosk_cas_dup_' . md5( $ip . '|' . $email . '|' . $content ) );
	}

	/**
	 * Whether IP exceeded rate limit (per-IP transient, expires with the window).
	 */
	private function is_rate_limited(): bool {
		$ip = $this->get_client_ip();
		if ( $this->is_shared_ip( $ip ) ) {
			return false;
		}
		$ip = $this->ip_bucket( $ip );

		$limit  = max( 1, (int) $this->settings['rate_limit_count'] );
		$window = max( 1, (int) $this->settings['rate_limit_window'] ) * MINUTE_IN_SECONDS;
		$now    = time();
		$stamps = get_transient( 'tsosk_cas_rl_' . md5( $ip ) );
		if ( ! is_array( $stamps ) ) {
			return false;
		}

		$recent = array_filter(
			$stamps,
			static function ( $ts ) use ( $now, $window ) {
				return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
			}
		);

		return count( $recent ) >= $limit;
	}

	/**
	 * Record a successful submission for rate limiting and duplicate detection.
	 *
	 * @param array<string, mixed> $data Submission data.
	 */
	private function record_successful_submission( array $data ): void {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return;
		}

		if ( ! empty( $this->settings['rate_limit'] ) && ! $this->is_shared_ip( $ip ) ) {
			$window = max( 1, (int) $this->settings['rate_limit_window'] ) * MINUTE_IN_SECONDS;
			$now    = time();
			$key    = 'tsosk_cas_rl_' . md5( $this->ip_bucket( $ip ) );
			$stamps = get_transient( $key );
			$stamps = is_array( $stamps ) ? $stamps : array();

			$stamps[] = $now;
			$stamps   = array_values(
				array_filter(
					$stamps,
					static function ( $ts ) use ( $now, $window ) {
						return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
					}
				)
			);
			set_transient( $key, $stamps, $window );
		}

		$source = (string) ( $data['source'] ?? 'comment' );
		if ( 'comment' === $source || ! isset( $data['source'] ) || empty( $this->settings['duplicate_check'] ) ) {
			return;
		}

		$hash   = md5( $ip . '|' . (string) ( $data['comment_author_email'] ?? '' ) . '|' . (string) ( $data['comment_content'] ?? '' ) );
		$window = max( 1, (int) $this->settings['duplicate_window'] ) * MINUTE_IN_SECONDS;
		set_transient( 'tsosk_cas_dup_' . $hash, 1, $window );
	}

	/**
	 * Append entry to block log and feed the spike alert.
	 *
	 * @param array<string, mixed> $data  Submission data.
	 * @param array<string, mixed> $check Check result.
	 */
	private function log_block( array $data, array $check ): void {
		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->insert_log( 'block', $data, $check );
		}
		$this->note_block_for_alert();
		$this->note_strike( $check );
	}

	/**
	 * Record a submission that would have been blocked, while Learning mode lets it through.
	 *
	 * @param array<string, mixed> $data  Submission data.
	 * @param array<string, mixed> $check Check result.
	 */
	private function log_learning_block( array $data, array $check ): void {
		$this->insert_log( 'learning', $data, $check );
	}

	/**
	 * Create signed timestamp token for time trap.
	 */
	private function create_time_token(): string {
		$ts  = time();
		$sig = substr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) ), 0, 16 );
		return $ts . '.' . $sig;
	}

	/**
	 * Verify signed time token.
	 *
	 * @param string $token Token.
	 */
	private function verify_time_token( string $token ): bool {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$ts = absint( $parts[0] );
		if ( $ts <= 0 ) {
			return false;
		}

		$expected = substr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) ), 0, 16 );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return false;
		}

		$age = time() - $ts;
		$min = max( 1, (int) $this->settings['min_submit_seconds'] );
		$max = max( $min + 1, (int) $this->settings['max_submit_seconds'] );

		return $age >= $min && $age <= $max;
	}

	/**
	 * Safe substring with mbstring fallback.
	 *
	 * @param string $text Text.
	 * @param int    $length Length.
	 */
	private function substr_safe( string $text, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length );
		}

		return substr( $text, 0, $length );
	}

	/**
	 * Get client ip.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return TSOSK_Support::get_client_ip();
	}

	/**
	 * Counting key for an address: IPv4 stays as is, IPv6 collapses to its /64 network, so one IPv6
	 * connection cannot dodge rate limits and bans by rotating addresses inside its own range.
	 *
	 * @param string $ip Client IP.
	 */
	private function ip_bucket( string $ip ): string {
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		if ( 0 === strncmp( $packed, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		$network = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
		return false !== $network ? $network : $ip;
	}

	/**
	 * Whether this comment was posted through Jetpack Comments (the iframe form).
	 *
	 * Jetpack verifies the signed request (sig + token_key) on pre_comment_on_post and stops the request when it is
	 * not valid, so by the time our filter runs these fields can be trusted. The hidden trap fields do not exist
	 * inside the iframe: without this exception every Jetpack comment would fail the time trap.
	 */
	private function is_jetpack_comment_request(): bool {
		if ( ! class_exists( 'Jetpack_Comments' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Signature is verified by Jetpack before this runs.
		return ! empty( $_POST['sig'] ) && ! empty( $_POST['token_key'] ) && ! empty( $_POST['hc_post_as'] );
	}

	/**
	 * Whether the detected IP is a private or reserved address (typically a reverse proxy or CDN node shared by every visitor).
	 * Banning or rate limiting such an address would lock out the whole site, so those protections skip it.
	 *
	 * @param string $ip IP address.
	 */
	private function is_shared_ip( string $ip ): bool {
		return '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether the request carries a forwarded-IP header while no trusted proxy header is configured
	 * (the detected IP is then probably the proxy's, not the visitor's).
	 */
	private function proxy_header_unconfigured(): bool {
		if ( defined( 'TSOSK_TRUSTED_PROXY_HEADER' ) && '' !== (string) TSOSK_TRUSTED_PROXY_HEADER ) {
			return false;
		}
		if ( '' !== (string) apply_filters( 'tsosk_trusted_proxy_header', '' ) ) {
			return false;
		}
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an IP is in the whitelist (exact addresses or CIDR ranges).
	 *
	 * @param string $ip IP address.
	 */
	private function is_ip_whitelisted( string $ip ): bool {
		$list = trim( (string) $this->settings['whitelist_ips'] );
		if ( '' === $list || '' === $ip ) {
			return false;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $list ) as $line ) {
			if ( $this->ip_matches_rule( $ip, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Block stats for admin UI.
	 *
	 * @return array{total:int,last_24h:int,by_reason:array<string,int>}
	 */
	public function get_stats(): array {
		return $this->compute_stats( 'block' );
	}

	/**
	 * Stats for submissions that Learning mode would have blocked.
	 *
	 * @return array{total:int,last_24h:int,by_reason:array<string,int>}
	 */
	public function get_learning_stats(): array {
		return $this->compute_stats( 'learning' );
	}

	/**
	 * Remember the outcome of a real remote call (rolling 24 h counters).
	 *
	 * @param string $id    Provider id.
	 * @param string $state 'ok' or 'error'.
	 * @param string $note  Short error text.
	 */
	private function record_provider( string $id, string $state, string $note = '' ): void {
		$data = get_option( self::OPTION_PROVIDERS, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$now = time();
		$row = isset( $data[ $id ] ) && is_array( $data[ $id ] ) ? $data[ $id ] : array();
		if ( empty( $row['since'] ) || ( $now - (int) $row['since'] ) > DAY_IN_SECONDS ) {
			$row['since']  = $now;
			$row['calls']  = 0;
			$row['errors'] = 0;
		}

		$row['calls']  = (int) ( $row['calls'] ?? 0 ) + 1;
		$row['errors'] = (int) ( $row['errors'] ?? 0 ) + ( 'ok' === $state ? 0 : 1 );
		$row['last']   = $now;
		$row['state']  = 'ok' === $state ? 'ok' : 'error';
		$row['note']   = $this->substr_safe( sanitize_text_field( $note ), 160 );

		$data[ $id ] = $row;
		update_option( self::OPTION_PROVIDERS, $data, false );

		$cfg = empty( $this->settings ) ? self::get_settings() : $this->settings;
		if ( 'ok' !== $state && ! empty( $cfg['alert_enabled'] ) && ! empty( $cfg['alert_provider_errors'] ) && (int) $row['errors'] >= 5 && ! get_transient( 'tsosk_cas_palert_' . $id ) ) {
			set_transient( 'tsosk_cas_palert_' . $id, 1, DAY_IN_SECONDS );
			$this->settings = $cfg;
			$labels         = $this->get_provider_rows( $cfg );
			$this->send_alert_email(
				'provider',
				array(
					'label'  => isset( $labels[ $id ]['label'] ) ? (string) $labels[ $id ]['label'] : $id,
					'errors' => (int) $row['errors'],
					'note'   => (string) $row['note'],
				)
			);
		}
	}

	/**
	 * Provider rows for the status table.
	 *
	 * @param array<string, mixed> $s Settings.
	 * @return array<string, array<string, mixed>>
	 */
	private function get_provider_rows( array $s ): array {
		$data = get_option( self::OPTION_PROVIDERS, array() );
		$data = is_array( $data ) ? $data : array();

		$off     = __( 'Off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$missing = __( 'Missing key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$active  = __( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		$cloud   = (string) $s['cloud_mode'];

		$rows = array(
			'stopforumspam'   => array(
				'label'  => 'Stop Forum Spam',
				'config' => ! empty( $s['stopforumspam_enabled'] ) ? $active : $off,
			),
			'abuseipdb'       => array(
				'label'  => 'AbuseIPDB',
				'config' => empty( $s['abuseipdb_enabled'] ) ? $off : ( '' === trim( (string) $s['abuseipdb_key'] ) ? $missing : $active ),
			),
			'honeypot_httpbl' => array(
				'label'  => 'Project Honey Pot',
				'config' => empty( $s['honeypot_httpbl_enabled'] ) ? $off : ( '' === trim( (string) $s['honeypot_access_key'] ) ? $missing : $active ),
			),
			'akismet'         => array(
				'label'  => 'Akismet',
				'config' => 'akismet' !== $cloud ? $off : ( class_exists( 'Akismet' ) ? $active : __( 'Plugin not active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ),
			),
			'cleantalk'       => array(
				'label'  => 'CleanTalk',
				'config' => 'cleantalk' !== $cloud ? $off : ( '' === trim( (string) $s['cleantalk_key'] ) ? $missing : $active ),
			),
		);

		foreach ( array_keys( $rows ) as $id ) {
			$rows[ $id ]['row'] = isset( $data[ $id ] ) && is_array( $data[ $id ] ) ? $data[ $id ] : array();
		}

		return $rows;
	}

	/**
	 * Render the "Provider status" card.
	 *
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $nonce Nonce.
	 */
	private function render_provider_status( array $s, string $nonce ): void {
		$now = time();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Provider status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Last real response from each external service and its usage in the last 24 hours. The test button uses the saved settings, so save first after changing a key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-table-wrap">
				<table class="widefat striped tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Provider', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Configuration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Last result', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Calls (24 h)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Errors (24 h)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $this->get_provider_rows( $s ) as $id => $p ) : ?>
						<?php
						$row   = $p['row'];
						$fresh = ! empty( $row['since'] ) && ( $now - (int) $row['since'] ) <= DAY_IN_SECONDS;
						if ( empty( $row['last'] ) ) {
							$last = __( 'No calls yet', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						} else {
							$last = ( 'ok' === ( $row['state'] ?? '' ) ? __( 'OK', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Error', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) )
								/* translators: %s: human readable time difference, e.g. "5 minutes" */
								. ' · ' . sprintf( __( '%s ago', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), human_time_diff( (int) $row['last'], $now ) )
								. ( ! empty( $row['note'] ) && 'ok' !== ( $row['state'] ?? '' ) ? ' · ' . $row['note'] : '' );
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( (string) $p['label'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $p['config'] ); ?></td>
							<td><?php echo esc_html( $last ); ?></td>
							<td><?php echo esc_html( (string) ( $fresh ? (int) ( $row['calls'] ?? 0 ) : 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $fresh ? (int) ( $row['errors'] ?? 0 ) : 0 ) ); ?></td>
							<td>
								<button type="button" class="button button-small tsosk-cas-test-provider" data-provider="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p id="tsosk-cas-test-msg" class="tsosk-msg" aria-live="polite" style="display:none;margin-top:10px;"></p>
		</div>
		<?php
	}

	/**
	 * "Allow this IP" button cell for a log row.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 * @param string               $log   'block' or 'learning'.
	 * @param string               $nonce Nonce.
	 */
	private function render_allow_button( array $entry, string $log, string $nonce ): void {
		$ip = (string) ( $entry['ip'] ?? '' );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			echo '—';
			return;
		}
		if ( $this->is_ip_whitelisted( $ip ) ) {
			esc_html_e( 'Whitelisted', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			return;
		}
		?>
		<button type="button" class="button button-small tsosk-cas-allow-ip"
			data-ip="<?php echo esc_attr( $ip ); ?>"
			data-log="<?php echo esc_attr( $log ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-confirm="<?php echo esc_attr__( 'Add this IP to the whitelist? It will skip every anti-spam rule from now on.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"><?php esc_html_e( 'Allow IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
		<?php
	}

	/** AJAX: test one provider with a real request (saved settings). */
	public function ajax_test_provider(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$s  = self::get_settings();

		$this->settings = $s;
		$result         = array(
			'ok'      => false,
			'message' => __( 'Unknown provider.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'skipped' => true,
		);

		switch ( $id ) {
			case 'stopforumspam':
				$result = $this->test_stopforumspam();
				break;
			case 'abuseipdb':
				$result = $this->test_abuseipdb( trim( (string) $s['abuseipdb_key'] ) );
				break;
			case 'honeypot_httpbl':
				$result = $this->test_httpbl( trim( (string) $s['honeypot_access_key'] ) );
				break;
			case 'akismet':
				$result = $this->test_akismet();
				break;
			case 'cleantalk':
				$result = $this->test_cleantalk( trim( (string) $s['cleantalk_key'] ) );
				break;
		}

		if ( empty( $result['skipped'] ) ) {
			$this->record_provider( $id, $result['ok'] ? 'ok' : 'error', $result['ok'] ? '' : $result['message'] );
		}

		if ( $result['ok'] ) {
			wp_send_json_success( $result['message'] );
		}
		wp_send_json_error( $result['message'] );
	}

	/**
	 * Test Stop Forum Spam.
	 *
	 * @return array<string, mixed>
	 */
	private function test_stopforumspam(): array {
		$url      = add_query_arg(
			array(
				'f'         => 'json',
				'ip'        => '8.8.8.8',
				'emailhash' => md5( 'test@example.com' ),
			),
			self::STOPFORUMSPAM_API
		);
		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || 1 !== (int) ( $body['success'] ?? 0 ) ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'Unexpected response (HTTP %d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $code ),
			);
		}
		delete_site_transient( 'tsosk_cas_sfs_down' );
		return array(
			'ok'      => true,
			'message' => __( 'Stop Forum Spam responded correctly.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Test AbuseIPDB.
	 *
	 * @param string $key API key.
	 * @return array<string, mixed>
	 */
	private function test_abuseipdb( string $key ): array {
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No API key saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'skipped' => true,
			);
		}
		$response = wp_remote_get(
			add_query_arg(
				array(
					'ipAddress'    => '8.8.8.8',
					'maxAgeInDays' => 30,
				),
				self::ABUSEIPDB_API
			),
			array(
				'timeout' => 8,
				'headers' => array(
					'Key'    => $key,
					'Accept' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'AbuseIPDB rejected the API key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		if ( 429 === $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'AbuseIPDB daily limit reached.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'Unexpected response (HTTP %d).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $code ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'AbuseIPDB accepted the API key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Test Project Honey Pot HTTP:BL.
	 *
	 * @param string $key Access key.
	 * @return array<string, mixed>
	 */
	private function test_httpbl( string $key ): array {
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No access key saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'skipped' => true,
			);
		}
		$lookup = sanitize_text_field( $key ) . '.8.8.8.8.dnsbl.httpbl.org';
		$ip     = '';
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $lookup, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ip      = is_array( $records ) && ! empty( $records[0]['ip'] ) ? (string) $records[0]['ip'] : '';
		} else {
			$host = gethostbyname( $lookup );
			$ip   = $host !== $lookup ? $host : '';
		}
		if ( '127.255.255.255' === $ip ) {
			return array(
				'ok'      => false,
				'message' => __( 'Project Honey Pot reported an invalid access key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'DNS lookup completed without errors (a clean IP returns no record).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Test Akismet.
	 *
	 * @return array<string, mixed>
	 */
	private function test_akismet(): array {
		if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'get_api_key' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The Akismet plugin is not active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'skipped' => true,
			);
		}
		$key = (string) Akismet::get_api_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'Akismet has no API key configured.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'skipped' => true,
			);
		}
		$status = method_exists( 'Akismet', 'verify_key' ) ? Akismet::verify_key( $key ) : 'failed';
		if ( 'valid' === $status ) {
			return array(
				'ok'      => true,
				'message' => __( 'Akismet API key is valid.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		if ( 'invalid' === $status ) {
			return array(
				'ok'      => false,
				'message' => __( 'Akismet reports the API key as invalid.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		return array(
			'ok'      => false,
			'message' => __( 'Could not reach Akismet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/**
	 * Test CleanTalk.
	 *
	 * @param string $key Access key.
	 * @return array<string, mixed>
	 */
	private function test_cleantalk( string $key ): array {
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No access key saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'skipped' => true,
			);
		}
		$response = wp_remote_post(
			self::CLEANTALK_API,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'method_name'     => 'check_message',
						'auth_key'        => $key,
						'sender_email'    => 'test@example.com',
						'sender_ip'       => '8.8.8.8',
						'sender_nickname' => 'Test',
						'message'         => 'Connection test from the site administrator.',
						'js_on'           => 1,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['allow'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'CleanTalk accepted the access key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			);
		}
		return array(
			'ok'      => false,
			'message' => is_array( $body ) && ! empty( $body['comment'] ) ? sanitize_text_field( (string) $body['comment'] ) : __( 'Invalid response.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
	}

	/** AJAX: add an IP from a log row to the whitelist. */
	public function ajax_allow_ip(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$ip  = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$log = isset( $_POST['log'] ) && 'learning' === sanitize_key( wp_unslash( $_POST['log'] ) ) ? 'learning' : 'block';
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( __( 'Invalid IP address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 400 );
		}

		$settings       = self::get_settings();
		$this->settings = $settings;
		if ( ! $this->is_ip_whitelisted( $ip ) ) {
			$list                      = trim( (string) $settings['whitelist_ips'] );
			$settings['whitelist_ips'] = ( '' === $list ? '' : $list . "\n" ) . $ip;
			update_option( self::OPTION, $settings, true );
			$this->settings = $settings;
		}

		$this->delete_log_rows( $log, $ip );

		/* translators: %s: IP address */
		TSOSK_Activity_Log::log( 'comment-antispam', 'allow-ip', sprintf( __( 'IP %s added to the comment anti-spam whitelist.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $ip ) );
		/* translators: %s: IP address */
		wp_send_json_success( sprintf( __( 'IP %s added to the whitelist.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $ip ) );
	}

	/**
	 * Run every enabled check once and log/record the outcome.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return array<string, mixed>|null Check result when the submission must be blocked, null when it may pass
	 *                                   (allowed, or only an observed rule / Learning mode matched).
	 */
	private function gate_submission( array $data ): ?array {
		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return null;
		}

		if ( $this->is_observed( $check ) ) {
			$this->log_learning_block( $data, $check );
			$this->record_successful_submission( $data );
			return null;
		}

		$this->log_block( $data, $check );
		return $check;
	}

	/**
	 * Whether a failed check must only be logged (Learning mode, or this single rule is being observed).
	 *
	 * @param array<string, mixed> $check Check result.
	 */
	private function is_observed( array $check ): bool {
		if ( ! empty( $this->settings['learning_mode'] ) ) {
			return true;
		}
		$observed = isset( $this->settings['observe_rules'] ) && is_array( $this->settings['observe_rules'] ) ? $this->settings['observe_rules'] : array();
		return in_array( (string) ( $check['reason'] ?? '' ), $observed, true );
	}

	/**
	 * Message shown to the visitor (custom message when configured).
	 *
	 * @param array<string, mixed> $check Check result.
	 */
	private function public_message( array $check ): string {
		$custom = isset( $this->settings['block_message'] ) ? trim( (string) $this->settings['block_message'] ) : '';
		return '' !== $custom ? $custom : (string) ( $check['message'] ?? '' );
	}

	/**
	 * WordPress registration form check.
	 *
	 * @param mixed  $errors WP_Error.
	 * @param string $login  Username.
	 * @param string $email  Email.
	 * @return mixed
	 */
	public function registration_errors( $errors, $login = '', $email = '' ) {
		return $this->check_registration( $errors, (string) $login, (string) $email );
	}

	/**
	 * WooCommerce registration form check.
	 *
	 * @param mixed  $errors   WP_Error.
	 * @param string $username Username.
	 * @param string $password Password (never used).
	 * @param string $email    Email.
	 * @return mixed
	 */
	public function woo_registration_errors( $errors, $username = '', $password = '', $email = '' ) {
		unset( $password );
		return $this->check_registration( $errors, (string) $username, (string) $email );
	}

	/**
	 * Shared registration check.
	 *
	 * @param mixed  $errors WP_Error.
	 * @param string $login  Username.
	 * @param string $email  Email.
	 * @return mixed
	 */
	private function check_registration( $errors, string $login, string $email ) {
		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}

		$data = array(
			'source'               => 'registration',
			'comment_author'       => sanitize_user( $login ),
			'comment_author_email' => sanitize_email( $email ),
			'comment_content'      => '',
		);
		if ( $this->should_skip_submission( $data ) ) {
			return $errors;
		}

		$check = $this->gate_submission( $data );
		if ( null === $check ) {
			return $errors;
		}

		$errors->add(
			'tsosk_cas_blocked',
			! empty( $check['silent'] )
				? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
				: $this->public_message( $check )
		);
		return $errors;
	}

	/**
	 * Whether the email already has an approved comment (returning visitor).
	 *
	 * @param string $email Email.
	 */
	private function is_returning_commenter( string $email ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}

		$key    = 'tsosk_cas_ret_' . md5( strtolower( $email ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 1 === (int) $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_approved = '1'", $email ) );
		set_transient( $key, $count > 0 ? 1 : 0, HOUR_IN_SECONDS );
		return $count > 0;
	}

	/**
	 * Whether the current IP is temporarily banned.
	 */
	private function is_ip_banned(): bool {
		$ip = $this->get_client_ip();
		return ! $this->is_shared_ip( $ip ) && false !== get_transient( 'tsosk_cas_ban_' . md5( $this->ip_bucket( $ip ) ) );
	}

	/**
	 * Count a real block against the IP and ban it when it reaches the threshold.
	 *
	 * @param array<string, mixed> $check Check result.
	 */
	private function note_strike( array $check ): void {
		if ( empty( $this->settings['ban_enabled'] ) || 'banned' === ( $check['reason'] ?? '' ) ) {
			return;
		}

		$ip = $this->get_client_ip();
		if ( $this->is_shared_ip( $ip ) ) {
			return;
		}

		$ip      = $this->ip_bucket( $ip );
		$key     = 'tsosk_cas_strikes_' . md5( $ip );
		$strikes = (int) get_transient( $key ) + 1;
		set_transient( $key, $strikes, DAY_IN_SECONDS );

		if ( $strikes >= max( 1, (int) ( $this->settings['ban_threshold'] ?? 5 ) ) ) {
			set_transient( 'tsosk_cas_ban_' . md5( $ip ), 1, max( 1, (int) ( $this->settings['ban_hours'] ?? 24 ) ) * HOUR_IN_SECONDS );
			delete_transient( $key );
		}
	}

	/**
	 * Whether an IP matches a rule: exact address, or a CIDR range (IPv4 or IPv6).
	 *
	 * @param string $ip   Visitor IP.
	 * @param string $rule Address or range such as 203.0.113.0/24.
	 */
	private function ip_matches_rule( string $ip, string $rule ): bool {
		$rule = trim( $rule );
		if ( '' === $rule || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$bits = null;
		if ( false !== strpos( $rule, '/' ) ) {
			list( $rule, $bits_raw ) = explode( '/', $rule, 2 );
			if ( ! ctype_digit( $bits_raw ) ) {
				return false;
			}
			$bits = (int) $bits_raw;
		}

		if ( false === filter_var( $rule, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ip_bin   = inet_pton( $ip );
		$rule_bin = inet_pton( $rule );
		if ( false === $ip_bin || false === $rule_bin || strlen( $ip_bin ) !== strlen( $rule_bin ) ) {
			return false;
		}

		if ( null === $bits ) {
			return $ip_bin === $rule_bin;
		}

		$max = strlen( $ip_bin ) * 8;
		if ( $bits > $max ) {
			return false;
		}

		$full = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $full ) !== substr( $rule_bin, 0, $full ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $ip_bin[ $full ] ) & $mask ) === ( ord( $rule_bin[ $full ] ) & $mask );
	}

	/**
	 * Blocks per day (UTC) for the last days, as a small bar chart.
	 *
	 * @param int $days Number of days.
	 */
	private function render_daily_chart( int $days = 14 ): void {
		global $wpdb;

		$table = esc_sql( self::log_table() );
		$first = (int) floor( time() / DAY_IN_SECONDS ) - ( $days - 1 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR(logged_at / 86400) AS d, COUNT(*) AS c FROM {$table} WHERE log_type = 'block' AND logged_at >= %d GROUP BY d", $first * DAY_IN_SECONDS ), ARRAY_A );

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$counts[ (int) $r['d'] ] = (int) $r['c'];
		}

		$max = max( 1, $counts ? max( $counts ) : 1 );
		?>
		<p style="margin:14px 0 4px;"><strong><?php esc_html_e( 'Blocks per day (last 14 days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></p>
		<div class="tsosk-cas-chart" role="img" aria-label="<?php esc_attr_e( 'Blocks per day (last 14 days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
			<?php
			for ( $d = $first; $d < $first + $days; $d++ ) {
				$c   = $counts[ $d ] ?? 0;
				$pct = $c > 0 ? max( 4, (int) round( $c / $max * 100 ) ) : 1;
				$day = wp_date( 'M j', $d * DAY_IN_SECONDS + 43200, new DateTimeZone( 'UTC' ) );
				?>
				<span class="tsosk-cas-bar<?php echo $c > 0 ? '' : ' tsosk-cas-bar-empty'; ?>" style="<?php echo esc_attr( 'height:' . $pct . '%' ); ?>" title="<?php echo esc_attr( $day . ': ' . $c ); ?>"></span>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * Render the "Repeat offenders", "Exceptions and messages" and "Test individual rules" cards.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_advanced_rules_cards( array $s ): void {
		$observe = isset( $s['observe_rules'] ) && is_array( $s['observe_rules'] ) ? $s['observe_rules'] : array();
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Repeat offenders', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<?php if ( $this->proxy_header_unconfigured() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: 1: detected IP address, 2: PHP constant name */
						esc_html__( 'Your site seems to be behind a proxy or CDN, but no trusted proxy header is configured. The detected IP (%1$s) may be the proxy\'s, not the visitor\'s, which would make bans and rate limits affect every visitor. Define %2$s in wp-config.php (for example HTTP_CF_CONNECTING_IP) before enabling bans.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						esc_html( $this->get_client_ip() ),
						'TSOSK_TRUSTED_PROXY_HEADER'
					);
					?>
				</p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Ban an IP address temporarily after several blocked submissions. Banned visitors are rejected immediately, without querying any external service.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-ban-enabled" <?php checked( ! empty( $s['ban_enabled'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Ban repeat offenders', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 8px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Blocks before banning', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-ban-threshold" min="1" max="100" value="<?php echo esc_attr( (string) $s['ban_threshold'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Blocked submissions from the same IP within 24 hours. Whitelisted IPs are never banned.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Ban duration (hours)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td><input type="number" id="tsosk-cas-ban-hours" min="1" max="720" value="<?php echo esc_attr( (string) $s['ban_hours'] ); ?>" style="width:80px;"></td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Exceptions and messages', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-trust-returning" <?php checked( ! empty( $s['trust_returning'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Trust returning commenters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Skip the external reputation and cloud checks when the email already has an approved comment. Local rules still apply.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 8px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Message for blocked visitors', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-block-message" value="<?php echo esc_attr( (string) $s['block_message'] ); ?>" maxlength="200" style="width:100%;max-width:480px;">
						<p class="description"><?php esc_html_e( 'Shown instead of the technical reason when a comment or form is rejected. Leave empty to show the default message for each rule.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Test individual rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Rules ticked here do not block: when they match, the submission is allowed and written to the learning log, while every other rule keeps working normally. Useful to try a new rule safely. A submission that matches an observed rule is not checked against the rules that come after it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p>
				<?php foreach ( self::OBSERVABLE_RULES as $rule ) : ?>
					<label style="display:inline-block;margin:0 18px 6px 0;">
						<input type="checkbox" class="tsosk-cas-observe" value="<?php echo esc_attr( $rule ); ?>" <?php checked( in_array( $rule, $observe, true ) ); ?>>
						<?php echo esc_html( $this->get_reason_label( $rule ) ); ?>
					</label>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Log table name.
	 */
	private static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tsosk_cas_log';
	}

	/**
	 * Create/upgrade the log table via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			tsosk_require_wp_admin( 'includes/upgrade.php' );
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$wpdb->prefix}tsosk_cas_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			log_type VARCHAR(10) NOT NULL DEFAULT 'block',
			logged_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			source VARCHAR(30) NOT NULL DEFAULT '',
			author VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(255) NOT NULL DEFAULT '',
			reason VARCHAR(40) NOT NULL DEFAULT '',
			excerpt VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY tsosk_cas_type_id (log_type, id),
			KEY tsosk_cas_type_ip (log_type, ip)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create the table once, move the old option-based logs into it and drop the option-based counters.
	 */
	private function maybe_upgrade_storage(): void {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		self::create_table();

		$table = esc_sql( self::log_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return;
		}

		foreach ( self::LEGACY_LOGS as $option => $type ) {
			$entries = get_option( $option, array() );
			if ( is_array( $entries ) ) {
				// Legacy entries were stored newest first: insert oldest first to keep the order.
				foreach ( array_reverse( $entries ) as $e ) {
					if ( ! is_array( $e ) ) {
						continue;
					}
					$this->insert_log_row( $type, (int) ( $e['time'] ?? time() ), $e );
				}
			}
			delete_option( $option );
		}
		delete_option( 'tsosk_cas_rate' );
		delete_option( 'tsosk_cas_form_dup' );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Insert one row into the log table.
	 *
	 * @param string               $type Log type: block or learning.
	 * @param int                  $time Unix time.
	 * @param array<string, mixed> $row  ip, source, author, email, reason, excerpt.
	 */
	private function insert_log_row( string $type, int $time, array $row ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::log_table(),
			array(
				'log_type'  => 'learning' === $type ? 'learning' : 'block',
				'logged_at' => max( 0, $time ),
				'ip'        => substr( (string) ( $row['ip'] ?? '' ), 0, 45 ),
				'source'    => substr( sanitize_key( (string) ( $row['source'] ?? 'comment' ) ), 0, 30 ),
				'author'    => $this->substr_safe( sanitize_text_field( (string) ( $row['author'] ?? '' ) ), 200 ),
				'email'     => $this->substr_safe( sanitize_email( (string) ( $row['email'] ?? '' ) ), 200 ),
				'reason'    => substr( sanitize_key( (string) ( $row['reason'] ?? 'unknown' ) ), 0, 40 ),
				'excerpt'   => $this->substr_safe( sanitize_text_field( (string) ( $row['excerpt'] ?? '' ) ), 200 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record a blocked (or would-be blocked) submission.
	 *
	 * @param string               $type  Log type: block or learning.
	 * @param array<string, mixed> $data  Submission data.
	 * @param array<string, mixed> $check Check result.
	 */
	private function insert_log( string $type, array $data, array $check ): void {
		$this->insert_log_row(
			$type,
			time(),
			array(
				'ip'      => $this->get_client_ip(),
				'source'  => (string) ( $data['source'] ?? 'comment' ),
				'author'  => (string) ( $data['comment_author'] ?? '' ),
				'email'   => (string) ( $data['comment_author_email'] ?? '' ),
				'reason'  => (string) ( $check['reason'] ?? 'unknown' ),
				'excerpt' => (string) ( $data['comment_content'] ?? '' ),
			)
		);

		// Trim the log now and then (not on every insert) to the configured size.
		if ( 0 === wp_rand( 0, 9 ) ) {
			$this->prune_log( 'learning' === $type ? 'learning' : 'block' );
		}
	}

	/**
	 * Delete the oldest rows beyond the configured maximum.
	 *
	 * @param string $type Log type.
	 */
	private function prune_log( string $type ): void {
		global $wpdb;

		$max   = max( 100, min( 10000, (int) ( $this->settings['log_max'] ?? 1000 ) ) );
		$table = esc_sql( self::log_table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cut = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE log_type = %s ORDER BY id DESC LIMIT 1 OFFSET %d", $type, $max ) );
		if ( $cut ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE log_type = %s AND id <= %d", $type, (int) $cut ) );
		}
	}

	/**
	 * Newest log rows.
	 *
	 * @param string $type  Log type: block or learning.
	 * @param int    $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_log( string $type = 'block', int $limit = 50 ): array {
		global $wpdb;

		$table = esc_sql( self::log_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at AS time, ip, source, author, email, reason, excerpt FROM {$table} WHERE log_type = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Totals for one log.
	 *
	 * @param string $type Log type.
	 * @return array{total:int,last_24h:int,by_reason:array<string,int>}
	 */
	private function compute_stats( string $type ): array {
		global $wpdb;

		$table = esc_sql( self::log_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE log_type = %s", $type ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last24 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE log_type = %s AND logged_at >= %d", $type, time() - DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) AS c FROM {$table} WHERE log_type = %s GROUP BY reason", $type ), ARRAY_A );

		$reasons = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$reasons[ (string) $r['reason'] ] = (int) $r['c'];
		}

		return array(
			'total'     => $total,
			'last_24h'  => $last24,
			'by_reason' => $reasons,
		);
	}

	/**
	 * Count a block towards the hourly spike alert and send the email when the threshold is reached.
	 */
	private function note_block_for_alert(): void {
		if ( empty( $this->settings['alert_enabled'] ) ) {
			return;
		}

		$key       = 'tsosk_cas_hour_' . gmdate( 'YmdH' );
		$hits      = (int) get_transient( $key ) + 1;
		$threshold = max( 1, (int) ( $this->settings['alert_threshold'] ?? 20 ) );
		set_transient( $key, $hits, 2 * HOUR_IN_SECONDS );

		if ( $hits >= $threshold && ! get_transient( 'tsosk_cas_alert_sent' ) ) {
			set_transient( 'tsosk_cas_alert_sent', 1, 3 * HOUR_IN_SECONDS );
			$this->send_alert_email(
				'spike',
				array(
					'hits'      => $hits,
					'threshold' => $threshold,
				)
			);
		}
	}

	/**
	 * Alert recipient.
	 */
	private function get_alert_recipient(): string {
		$to = isset( $this->settings['alert_email'] ) ? sanitize_email( (string) $this->settings['alert_email'] ) : '';
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/**
	 * Send an alert email (spike, provider failure or test) with the TSO template.
	 *
	 * @param string               $kind spike|provider|test.
	 * @param array<string, mixed> $ctx  Context values.
	 */
	private function send_alert_email( string $kind, array $ctx ): bool {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$rows = array(
			array(
				'label' => __( 'Site', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => home_url( '/' ),
			),
		);

		if ( 'provider' === $kind ) {
			$rows[] = array(
				'label' => __( 'Provider', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'value' => (string) ( $ctx['label'] ?? '' ),
			);
			if ( ! empty( $ctx['note'] ) ) {
				$rows[] = array(
					'label' => __( 'Last error', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'value' => (string) $ctx['note'],
				);
			}
			$heading = __( 'Comment anti-spam: service failing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$intro   = sprintf(
				/* translators: 1: provider name, 2: number of errors in the last 24 hours */
				__( '%1$s returned %2$d errors in the last 24 hours. The service may be down or its key may be invalid; submissions are being allowed through that check.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(string) ( $ctx['label'] ?? '' ),
				(int) ( $ctx['errors'] ?? 0 )
			);
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Comment anti-spam: a service is failing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $site );
		} elseif ( 'spike' === $kind ) {
			foreach ( $this->get_recent_reasons() as $reason => $count ) {
				$rows[] = array(
					'label' => $this->get_reason_label( (string) $reason ),
					'value' => (string) $count,
				);
			}
				$heading = __( 'Comment anti-spam alert', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
				$intro   = sprintf(
					/* translators: 1: blocked submissions this hour, 2: configured threshold */
					__( '%1$d submissions were blocked in the last hour (threshold: %2$d). Review the logs to check there are no false positives.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) ( $ctx['hits'] ?? 0 ),
					(int) ( $ctx['threshold'] ?? 0 )
				);
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Comment anti-spam alert', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $site );
		} else {
			$heading = __( 'Comment anti-spam: test email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			$intro   = __( 'This is a test email. If you can read it, alerts will reach this address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Comment anti-spam test', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $site );
		}

		return TSOSK_Email_Template::send(
			$this->get_alert_recipient(),
			$subject,
			array(
				'heading'      => $heading,
				'intro'        => $intro,
				'rows'         => $rows,
				'button_label' => __( 'Open comment anti-spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'button_url'   => admin_url( 'tools.php?page=tso-swiss-knife&tab=comment-antispam' ),
			)
		);
	}

	/**
	 * Block reasons of the last hour (for the alert email).
	 *
	 * @return array<string, int>
	 */
	private function get_recent_reasons(): array {
		global $wpdb;

		$table = esc_sql( self::log_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) AS c FROM {$table} WHERE log_type = 'block' AND logged_at >= %d GROUP BY reason ORDER BY c DESC LIMIT 6", time() - HOUR_IN_SECONDS ), ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (string) $r['reason'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** AJAX: send a test alert email. */
	public function ajax_test_alert(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$this->settings = self::get_settings();
		if ( $this->send_alert_email( 'test', array() ) ) {
			/* translators: %s: email address */
			wp_send_json_success( sprintf( __( 'Test email sent to %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $this->get_alert_recipient() ) );
		}
		wp_send_json_error( __( 'The email could not be sent. Check your site mail configuration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** Admin-post: download a log as CSV. */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'tsosk_cas_export' );

		$type = isset( $_GET['type'] ) && 'learning' === sanitize_key( wp_unslash( $_GET['type'] ) ) ? 'learning' : 'block';
		$rows = $this->get_log( $type, 10000 );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-comment-antispam-' . $type . '-' . gmdate( 'Ymd' ) . '.csv"' );

		$out = "\xEF\xBB\xBF" . implode( ',', array( 'time_utc', 'source', 'ip', 'author', 'email', 'reason', 'excerpt' ) ) . "\r\n";
		foreach ( $rows as $r ) {
			$cells = array(
				gmdate( 'Y-m-d H:i:s', (int) $r['time'] ),
				(string) $r['source'],
				(string) $r['ip'],
				(string) $r['author'],
				(string) $r['email'],
				(string) $r['reason'],
				(string) $r['excerpt'],
			);
			$out  .= implode( ',', array_map( array( $this, 'csv_cell' ), $cells ) ) . "\r\n";
		}

		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells escaped by csv_cell().
		exit;
	}

	/**
	 * Escape one CSV cell (quotes, and spreadsheet formula injection).
	 *
	 * @param string $value Cell value.
	 */
	private function csv_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * Render the "Email alerts" card.
	 *
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $nonce Nonce.
	 */
	private function render_alerts_card( array $s, string $nonce ): void {
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Email alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Get an email when the site is receiving a burst of spam, or when an external service keeps failing.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-alert-enabled" <?php checked( ! empty( $s['alert_enabled'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Enable email alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong></span>
			</label>

			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 8px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Send alerts to', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="email" id="tsosk-cas-alert-email" value="<?php echo esc_attr( (string) $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" style="width:100%;max-width:360px;">
						<p class="description"><?php esc_html_e( 'Leave empty to use the site administrator email.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Blocks per hour', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-alert-threshold" min="1" max="1000" value="<?php echo esc_attr( (string) $s['alert_threshold'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'An alert is sent when the blocked submissions in one hour reach this number (at most one email every 3 hours).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-alert-providers" <?php checked( ! empty( $s['alert_provider_errors'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Also alert when a service keeps failing', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( '5 or more errors in 24 hours (at most one email per service per day).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-cas-test-alert" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Send test email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span id="tsosk-cas-alert-msg" class="tsosk-msg" aria-live="polite" style="display:none;"></span>
			</p>
			<p class="description"><?php esc_html_e( 'The test email uses the saved settings: save first if you changed the address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the "Log storage" card (retention + CSV export).
	 *
	 * @param array<string, mixed> $s       Settings.
	 * @param array<string, mixed> $stats   Block stats.
	 * @param array<string, mixed> $lstats  Learning stats.
	 */
	private function render_log_storage_card( array $s, array $stats, array $lstats ): void {
		$export = static function ( string $type ): string {
			return wp_nonce_url( admin_url( 'admin-post.php?action=tsosk_cas_export&type=' . $type ), 'tsosk_cas_export' );
		};
		?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Log storage', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Entries are stored in a dedicated database table (not in the options table), so long logs do not slow the site down. The lists below show the latest 50 entries; export the CSV to get the full log.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Max. entries per log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-log-max" min="100" max="10000" value="<?php echo esc_attr( (string) $s['log_max'] ); ?>" style="width:100px;">
						<p class="description"><?php esc_html_e( 'The oldest entries are deleted automatically beyond this number (100–10000).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( $export( 'block' ) ); ?>"><?php esc_html_e( 'Export blocks (CSV)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export( 'learning' ) ); ?>"><?php esc_html_e( 'Export learning log (CSV)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			</p>
			<?php if ( 0 === (int) $stats['total'] && 0 === (int) $lstats['total'] ) : ?>
			<p class="description"><?php esc_html_e( 'No log entries yet.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Human-readable label for a block reason key.
	 *
	 * @param string $reason Reason key stored in the log.
	 */
	private function get_reason_label( string $reason ): string {
		$labels = array(
			'honeypot'         => __( 'Honeypot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'time_trap'        => __( 'Time trap', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'rate_limit'       => __( 'Rate limit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'stopforumspam'    => __( 'Stop Forum Spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'abuseipdb'        => __( 'AbuseIPDB', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'honeypot_httpbl'  => __( 'Project Honey Pot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'cloud'            => __( 'Cloud filter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'max_links'        => __( 'Too many links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'disposable_email' => __( 'Disposable email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'cyrillic'         => __( 'Blocked characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'keyword'          => __( 'Blocked keyword', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'blocked_url'      => __( 'Blocked URL', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'duplicate'        => __( 'Duplicate', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'banned'           => __( 'Temporarily banned IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		return $labels[ $reason ] ?? $reason;
	}

	/**
	 * Delete log rows of a type, optionally only for one IP.
	 *
	 * @param string $type Log type.
	 * @param string $ip   Optional IP.
	 */
	private function delete_log_rows( string $type, string $ip = '' ): void {
		global $wpdb;

		$table = esc_sql( self::log_table() );
		if ( '' === $ip ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE log_type = %s", $type ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE log_type = %s AND ip = %s", $type, $ip ) );
	}

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$cloud_mode = isset( $_POST['cloud_mode'] ) ? sanitize_key( wp_unslash( $_POST['cloud_mode'] ) ) : 'off';
		if ( ! in_array( $cloud_mode, array( 'off', 'akismet', 'cleantalk' ), true ) ) {
			$cloud_mode = 'off';
		}

		$spam_action = isset( $_POST['spam_action'] ) ? sanitize_key( wp_unslash( $_POST['spam_action'] ) ) : 'spam';
		if ( ! in_array( $spam_action, array( 'spam', 'trash', 'discard' ), true ) ) {
			$spam_action = 'spam';
		}

		$observe_rules = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is passed through sanitize_key() and checked against a fixed list below.
		$observe_raw = isset( $_POST['observe_rules'] ) && is_array( $_POST['observe_rules'] ) ? wp_unslash( $_POST['observe_rules'] ) : array();
		foreach ( $observe_raw as $rule ) {
			$rule = sanitize_key( (string) $rule );
			if ( in_array( $rule, self::OBSERVABLE_RULES, true ) ) {
				$observe_rules[] = $rule;
			}
		}

		$new = array(
			'enabled'                   => ! empty( $_POST['enabled'] ),
			'learning_mode'             => ! empty( $_POST['learning_mode'] ),
			'protect_comments'          => ! empty( $_POST['protect_comments'] ),
			'protect_contact_forms'     => ! empty( $_POST['protect_contact_forms'] ),
			'honeypot'                  => ! empty( $_POST['honeypot'] ),
			'time_trap'                 => ! empty( $_POST['time_trap'] ),
			'min_submit_seconds'        => isset( $_POST['min_submit_seconds'] )
				? max( 1, min( 60, absint( wp_unslash( $_POST['min_submit_seconds'] ) ) ) )
				: 3,
			'max_submit_seconds'        => isset( $_POST['max_submit_seconds'] )
				? max( 60, min( 86400, absint( wp_unslash( $_POST['max_submit_seconds'] ) ) ) )
				: 7200,
			'rate_limit'                => ! empty( $_POST['rate_limit'] ),
			'rate_limit_count'          => isset( $_POST['rate_limit_count'] )
				? max( 1, min( 50, absint( wp_unslash( $_POST['rate_limit_count'] ) ) ) )
				: 3,
			'rate_limit_window'         => isset( $_POST['rate_limit_window'] )
				? max( 1, min( 1440, absint( wp_unslash( $_POST['rate_limit_window'] ) ) ) )
				: 60,
			'max_links'                 => isset( $_POST['max_links'] )
				? max( 0, min( 20, absint( wp_unslash( $_POST['max_links'] ) ) ) )
				: 2,
			'block_keywords'            => isset( $_POST['block_keywords'] )
				? sanitize_textarea_field( wp_unslash( $_POST['block_keywords'] ) )
				: '',
			'block_urls'                => isset( $_POST['block_urls'] )
				? sanitize_textarea_field( wp_unslash( $_POST['block_urls'] ) )
				: '',
			'block_disposable_email'    => ! empty( $_POST['block_disposable_email'] ),
			'custom_disposable_domains' => isset( $_POST['custom_disposable_domains'] )
				? sanitize_textarea_field( wp_unslash( $_POST['custom_disposable_domains'] ) )
				: '',
			'duplicate_check'           => ! empty( $_POST['duplicate_check'] ),
			'duplicate_window'          => isset( $_POST['duplicate_window'] )
				? max( 1, min( 1440, absint( wp_unslash( $_POST['duplicate_window'] ) ) ) )
				: 60,
			'block_cyrillic'            => ! empty( $_POST['block_cyrillic'] ),
			'cloud_mode'                => $cloud_mode,
			'cleantalk_key'             => isset( $_POST['cleantalk_key'] )
				? sanitize_text_field( wp_unslash( $_POST['cleantalk_key'] ) )
				: '',
			'stopforumspam_enabled'     => ! empty( $_POST['stopforumspam_enabled'] ),
			'sfs_min_confidence'        => isset( $_POST['sfs_min_confidence'] )
				? max( 1, min( 100, absint( wp_unslash( $_POST['sfs_min_confidence'] ) ) ) )
				: 50,
			'abuseipdb_enabled'         => ! empty( $_POST['abuseipdb_enabled'] ),
			'abuseipdb_key'             => isset( $_POST['abuseipdb_key'] )
				? sanitize_text_field( wp_unslash( $_POST['abuseipdb_key'] ) )
				: '',
			'abuseipdb_min_score'       => isset( $_POST['abuseipdb_min_score'] )
				? max( 1, min( 100, absint( wp_unslash( $_POST['abuseipdb_min_score'] ) ) ) )
				: 75,
			'abuseipdb_max_age_days'    => isset( $_POST['abuseipdb_max_age_days'] )
				? max( 1, min( 365, absint( wp_unslash( $_POST['abuseipdb_max_age_days'] ) ) ) )
				: 30,
			'honeypot_httpbl_enabled'   => ! empty( $_POST['honeypot_httpbl_enabled'] ),
			'honeypot_access_key'       => isset( $_POST['honeypot_access_key'] )
				? sanitize_text_field( wp_unslash( $_POST['honeypot_access_key'] ) )
				: '',
			'honeypot_min_threat'       => isset( $_POST['honeypot_min_threat'] )
				? max( 0, min( 255, absint( wp_unslash( $_POST['honeypot_min_threat'] ) ) ) )
				: 25,
			'skip_logged_in'            => ! empty( $_POST['skip_logged_in'] ),
			'whitelist_ips'             => isset( $_POST['whitelist_ips'] )
				? sanitize_textarea_field( wp_unslash( $_POST['whitelist_ips'] ) )
				: '',
			'spam_action'               => $spam_action,
			'log_blocks'                => ! empty( $_POST['log_blocks'] ),
			'log_max'                   => isset( $_POST['log_max'] )
				? max( 100, min( 10000, absint( wp_unslash( $_POST['log_max'] ) ) ) )
				: 1000,
			'alert_enabled'             => ! empty( $_POST['alert_enabled'] ),
			'alert_email'               => isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '',
			'alert_threshold'           => isset( $_POST['alert_threshold'] )
				? max( 1, min( 1000, absint( wp_unslash( $_POST['alert_threshold'] ) ) ) )
				: 20,
			'alert_provider_errors'     => ! empty( $_POST['alert_provider_errors'] ),
			'protect_reviews'           => ! empty( $_POST['protect_reviews'] ),
			'protect_registration'      => ! empty( $_POST['protect_registration'] ),
			'ban_enabled'               => ! empty( $_POST['ban_enabled'] ),
			'ban_threshold'             => isset( $_POST['ban_threshold'] )
				? max( 1, min( 100, absint( wp_unslash( $_POST['ban_threshold'] ) ) ) )
				: 5,
			'ban_hours'                 => isset( $_POST['ban_hours'] )
				? max( 1, min( 720, absint( wp_unslash( $_POST['ban_hours'] ) ) ) )
				: 24,
			'trust_returning'           => ! empty( $_POST['trust_returning'] ),
			'block_message'             => isset( $_POST['block_message'] ) ? $this->substr_safe( sanitize_text_field( wp_unslash( $_POST['block_message'] ) ), 200 ) : '',
			'observe_rules'             => $observe_rules,
		);

		// A whitelist line that is not an IP or a CIDR range would be ignored silently, leaving that visitor unprotected from the rules.
		$bad_lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $new['whitelist_ips'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$addr = $line;
			$bits = null;
			if ( false !== strpos( $line, '/' ) ) {
				list( $addr, $bits_raw ) = explode( '/', $line, 2 );
				$bits                    = ctype_digit( $bits_raw ) ? (int) $bits_raw : -1;
			}
			$is_v6 = false !== strpos( $addr, ':' );
			if ( false === filter_var( $addr, FILTER_VALIDATE_IP ) || ( null !== $bits && ( $bits < 0 || $bits > ( $is_v6 ? 128 : 32 ) ) ) ) {
				$bad_lines[] = $line;
			}
		}
		if ( ! empty( $bad_lines ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: comma-separated invalid whitelist lines */
					__( 'Settings were not saved. These whitelist lines are not a valid IP address or range (such as 203.0.113.0/24): %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					implode( ', ', array_slice( $bad_lines, 0, 5 ) )
				),
				400
			);
		}

		if ( $new['enabled'] && empty( $new['protect_comments'] ) && empty( $new['protect_contact_forms'] ) && empty( $new['protect_registration'] ) ) {
			wp_send_json_error(
				__( 'Enable at least one protection target: comments, contact forms or registration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				400
			);
		}

		update_option( self::OPTION, $new, true ); // Settings are read on every request — must autoload.
		$this->settings = $new;

		TSOSK_Activity_Log::log( 'comment-antispam', 'save', __( 'Comment anti-spam settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );

		wp_send_json_success( __( 'Comment anti-spam settings saved.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: clear block log. */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$this->delete_log_rows( 'block' );
		TSOSK_Activity_Log::log( 'comment-antispam', 'clear', __( 'Comment anti-spam log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Block log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/** AJAX: clear learning-mode log. */
	public function ajax_clear_learning_log(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$this->delete_log_rows( 'learning' );
		TSOSK_Activity_Log::log( 'comment-antispam', 'clear-learning', __( 'Comment anti-spam learning log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		wp_send_json_success( __( 'Learning log cleared.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Render admin tab.
	 */
	public function render(): void {
		$s              = self::get_settings();
		$stats          = $this->get_stats();
		$log            = $this->get_log( 'block', 50 );
		$learning_stats = $this->get_learning_stats();
		$learning_log   = $this->get_log( 'learning', 50 );
		$nonce          = wp_create_nonce( 'tsosk_cas_nonce' );
		$plugins        = $this->get_detected_form_plugins();
		$akismet_active = class_exists( 'Akismet' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Multi-layer spam protection for WordPress comments and contact forms. Local rules catch most bots; optional Akismet or CleanTalk cloud checks add global reputation data when you need stronger filtering.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<form id="tsosk-cas-form" autocomplete="off">

		<?php if ( empty( $s['enabled'] ) ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Protection is disabled: none of the options on these tabs has any effect until you enable it in Overview.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p></div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper tsosk-cas-tabs" role="tablist">
			<a href="#tsosk-cas-panel-overview" class="nav-tab tsosk-cas-tab nav-tab-active" data-tab="overview" role="tab" aria-selected="true"><?php esc_html_e( 'Overview', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-cas-panel-rules" class="nav-tab tsosk-cas-tab" data-tab="rules" role="tab" aria-selected="false"><?php esc_html_e( 'Local rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-cas-panel-services" class="nav-tab tsosk-cas-tab" data-tab="services" role="tab" aria-selected="false"><?php esc_html_e( 'External services', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<a href="#tsosk-cas-panel-logs" class="nav-tab tsosk-cas-tab" data-tab="logs" role="tab" aria-selected="false"><?php esc_html_e( 'Logs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
		</h2>

		<div class="tsosk-cas-panel" id="tsosk-cas-panel-overview" role="tabpanel">
		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<?php esc_html_e( 'Protection status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<span class="tsosk-badge <?php echo ! empty( $s['enabled'] ) ? 'tsosk-badge-ok' : ''; ?>" style="margin-left:10px;font-size:12px;">
					<?php echo ! empty( $s['enabled'] ) ? esc_html__( 'Active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : esc_html__( 'Inactive', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</h3>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-enabled" <?php checked( ! empty( $s['enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Enable anti-spam protection', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:24px;">
				<input type="checkbox" id="tsosk-cas-protect-comments" <?php checked( ! empty( $s['protect_comments'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'WordPress comments', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Applies all rules to the native comment form on posts and pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:48px;">
				<input type="checkbox" id="tsosk-cas-protect-reviews" <?php checked( ! empty( $s['protect_reviews'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Product reviews', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Also protects WooCommerce product reviews (they are comments of type "review"). Needs WordPress comments protection enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:24px;">
				<input type="checkbox" id="tsosk-cas-protect-forms" <?php checked( ! empty( $s['protect_contact_forms'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Contact forms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Applies the same rules to Contact Form 7, WPForms, Gravity Forms, Elementor Pro and Fluent Forms when those plugins are active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:24px;">
				<input type="checkbox" id="tsosk-cas-protect-registration" <?php checked( ! empty( $s['protect_registration'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'User registration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Applies the rules to the WordPress and WooCommerce registration forms (honeypot, time trap, disposable emails, reputation lists).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-learning-mode" <?php checked( ! empty( $s['learning_mode'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Learning mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Nothing is actually blocked. Every submission that would have been rejected is logged instead, so you can check for false positives before turning on real enforcement.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<div class="tsosk-notice tsosk-notice-info" style="margin-top:12px;">
				<strong><?php esc_html_e( 'Supported form plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php
				$labels = array(
					'cf7'        => 'Contact Form 7',
					'wpforms'    => 'WPForms',
					'gravity'    => 'Gravity Forms',
					'elementor'  => 'Elementor Pro',
					'fluentform' => 'Fluent Forms',
				);
				$parts  = array();
				foreach ( $labels as $key => $label ) {
					$parts[] = $label . ( ! empty( $plugins[ $key ] ) ? ' ✓' : '' );
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
			</div>

			<div class="tsosk-kv-grid" style="margin-top:16px;">
				<div class="tsosk-kv-item">
					<span class="tsosk-kv-label"><?php esc_html_e( 'Blocked (24 h)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $stats['last_24h'] ); ?></span>
				</div>
				<div class="tsosk-kv-item">
					<span class="tsosk-kv-label"><?php esc_html_e( 'Blocked (total)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $stats['total'] ); ?></span>
				</div>
				<?php if ( ! empty( $s['learning_mode'] ) || $learning_stats['total'] > 0 ) : ?>
				<div class="tsosk-kv-item">
					<span class="tsosk-kv-label"><?php esc_html_e( 'Would block (24 h)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $learning_stats['last_24h'] ); ?></span>
				</div>
				<div class="tsosk-kv-item">
					<span class="tsosk-kv-label"><?php esc_html_e( 'Would block (total)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $learning_stats['total'] ); ?></span>
				</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $stats['by_reason'] ) ) : ?>
			<p style="margin-top:12px;">
				<strong><?php esc_html_e( 'Blocks by reason (log)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>:</strong>
				<?php
				arsort( $stats['by_reason'] );
				foreach ( $stats['by_reason'] as $reason_key => $reason_count ) {
					echo '<span class="tsosk-badge" style="margin:0 6px 4px 0;">' . esc_html( $this->get_reason_label( (string) $reason_key ) . ' × ' . (int) $reason_count ) . '</span>';
				}
				?>
			</p>
			<?php endif; ?>
			<?php $this->render_daily_chart(); ?>
		</div>

		<?php $this->render_alerts_card( $s, $nonce ); ?>

		</div>

		<div class="tsosk-cas-panel" id="tsosk-cas-panel-rules" role="tabpanel" hidden>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Local protection layers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'These run on your server without external API calls. Together they block most automated spam.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-honeypot" <?php checked( ! empty( $s['honeypot'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Honeypot field', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'Hidden field bots fill in; submission is silently rejected.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-time-trap" <?php checked( ! empty( $s['time_trap'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Minimum submit time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'Rejects comments submitted faster than a human could type.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-rate-limit" <?php checked( ! empty( $s['rate_limit'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Rate limit by IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Stops the same IP from sending too many comments or form submissions in a short period.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-disposable" <?php checked( ! empty( $s['block_disposable_email'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Block disposable email domains', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Rejects temporary email services (e.g. Mailinator, Guerrilla Mail) often used by spammers.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-duplicate" <?php checked( ! empty( $s['duplicate_check'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Block duplicate comments', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Rejects the same text from the same IP within the duplicate window below.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-cyrillic" <?php checked( ! empty( $s['block_cyrillic'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Block Cyrillic characters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'Useful for sites that only expect Latin-script comments.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-skip-logged-in" <?php checked( ! empty( $s['skip_logged_in'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Skip checks for logged-in users', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Trusted visitors with an account bypass all anti-spam rules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-log-blocks" <?php checked( ! empty( $s['log_blocks'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Log blocked attempts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Keeps a recent list at the bottom of this page so you can review what was blocked and why.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</span>
			</label>

			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin-top:14px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Min. seconds before submit', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-min-seconds" min="1" max="60" value="<?php echo esc_attr( (string) $s['min_submit_seconds'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'How long the visitor must wait after the form loads before submitting. Instant submissions are treated as bots. Typical value: 3–5 seconds.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max. form age (seconds)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-max-seconds" min="60" max="86400" value="<?php echo esc_attr( (string) $s['max_submit_seconds'] ); ?>" style="width:100px;">
						<p class="description"><?php esc_html_e( 'Maximum time a form stays valid after it was opened. Older tokens are rejected (e.g. a tab left open overnight). If a page cache serves your pages, keep this higher than the cache lifetime, or visitors on cached pages will be rejected. Default: 7200 (2 hours).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max comments per IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-rate-count" min="1" max="50" value="<?php echo esc_attr( (string) $s['rate_limit_count'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Maximum allowed submissions from one IP address within the rate limit window. Example: 3 means the fourth attempt in that period is blocked.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Rate limit window (minutes)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-rate-window" min="1" max="1440" value="<?php echo esc_attr( (string) $s['rate_limit_window'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Time span used together with "Max comments per IP". Example: 3 submissions per 60 minutes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max links in comment', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-max-links" min="0" max="20" value="<?php echo esc_attr( (string) $s['max_links'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Blocks submissions with more than this many http/https links in the text. Use 0 to disable. Spam often contains many URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Duplicate window (minutes)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-dup-window" min="1" max="1440" value="<?php echo esc_attr( (string) $s['duplicate_window'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'How long to remember recent submissions when checking for duplicates from the same IP with identical content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Action on spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<select id="tsosk-cas-spam-action">
							<option value="spam" <?php selected( $s['spam_action'], 'spam' ); ?>><?php esc_html_e( 'Mark as spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="trash" <?php selected( $s['spam_action'], 'trash' ); ?>><?php esc_html_e( 'Move to trash', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="discard" <?php selected( $s['spam_action'], 'discard' ); ?>><?php esc_html_e( 'Reject with error (do not save)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'What happens to blocked WordPress comments. Contact forms always show an error and never store the message.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Blocklists & bypass', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Your own rules on top of automatic checks: block specific words or URLs, extend the disposable-email list, or whitelist trusted IPs that should never be filtered.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<table class="tsosk-kv-table" style="width:100%;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Blocked keywords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-cas-keywords" rows="4" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['block_keywords'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One word or phrase per line. If any line appears in the comment text, author name, email or URL, the submission is blocked. Lines starting with # are ignored. Not case-sensitive.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Blocked URL fragments', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-cas-urls" rows="4" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['block_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One domain or URL fragment per line (e.g. casino.xyz or bit.ly/spam). Matched anywhere in the submission content. Useful to stop recurring spam links.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Extra disposable domains', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-cas-domains" rows="3" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['custom_disposable_domains'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Add email domains to block in addition to the built-in list. One domain per line, with or without @ (e.g. tempmail.example). Only applies when "Block disposable email domains" is enabled above.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Whitelist IPs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<textarea id="tsosk-cas-whitelist" rows="3" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['whitelist_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One IP address per line. These addresses skip every anti-spam rule (local, cloud and reputation). Use for your office, home or test machines — not for public visitors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<p class="description"><?php esc_html_e( 'You can also use CIDR ranges, for example 203.0.113.0/24 or 2001:db8::/32.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php $this->render_advanced_rules_cards( $s ); ?>
		</div>

		<div class="tsosk-cas-panel" id="tsosk-cas-panel-services" role="tabpanel" hidden>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Cloud filter (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'For effectiveness similar to dedicated anti-spam plugins, enable a cloud check. Local rules still run as a second layer.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>

			<table class="tsosk-kv-table" style="width:100%;max-width:640px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Cloud mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<select id="tsosk-cas-cloud-mode">
							<option value="off" <?php selected( $s['cloud_mode'], 'off' ); ?>><?php esc_html_e( 'Off (local rules only)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="akismet" <?php selected( $s['cloud_mode'], 'akismet' ); ?>><?php esc_html_e( 'Akismet (requires Akismet plugin + API key)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
							<option value="cleantalk" <?php selected( $s['cloud_mode'], 'cleantalk' ); ?>><?php esc_html_e( 'CleanTalk API (requires access key)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Optional second opinion from a global spam database. Local rules always run first; the cloud check adds extra filtering when enabled.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php if ( 'akismet' === $s['cloud_mode'] && ! $akismet_active ) : ?>
						<p class="description" style="color:#b32d2e;margin-top:6px;"><?php esc_html_e( 'Akismet plugin is not active. Install and configure Akismet or choose another mode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'CleanTalk access key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-cleantalk-key" value="<?php echo esc_attr( (string) $s['cleantalk_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Only used when Cloud mode is CleanTalk. You can reuse your existing CleanTalk key without keeping their plugin active.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Reputation blocklists', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Query community spam databases before accepting a submission. Results are cached for 6 hours per IP/email to respect API limits.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-sfs" <?php checked( ! empty( $s['stopforumspam_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Stop Forum Spam', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Free lookups, no account or key required. Checks the IP and a hash of the email.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<a href="https://www.stopforumspam.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'stopforumspam.com', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 16px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Min. confidence (%)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-sfs-confidence" min="1" max="100" value="<?php echo esc_attr( (string) $s['sfs_min_confidence'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'When Stop Forum Spam finds a match, it returns how sure it is (0–100%). We only block if that value is equal to or above this threshold. Lower = stricter (more blocks, including uncertain matches). Higher = only block when the database is very confident. Recommended: 50.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-abuseipdb" <?php checked( ! empty( $s['abuseipdb_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'AbuseIPDB', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'Checks IP abuse score. Requires a free API key.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<a href="https://www.abuseipdb.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'abuseipdb.com', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 16px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'API key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-abuseipdb-key" value="<?php echo esc_attr( (string) $s['abuseipdb_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Create a free key at abuseipdb.com. The plugin sends the visitor IP to check whether other sites have reported it for abuse.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Min. abuse score (0–100)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-abuseipdb-score" min="1" max="100" value="<?php echo esc_attr( (string) $s['abuseipdb_min_score'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'AbuseIPDB assigns each IP a score from 0 (clean) to 100 (widely reported). We block when the score is equal to or above this value. Lower = block sooner; higher = only block IPs with many abuse reports. Recommended: 75.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max. report age (days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-abuseipdb-age" min="1" max="365" value="<?php echo esc_attr( (string) $s['abuseipdb_max_age_days'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Ignore abuse reports older than this many days. Prevents blocking an IP because of a problem that happened long ago.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-httpbl" <?php checked( ! empty( $s['honeypot_httpbl_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Project Honey Pot (HTTP:BL)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
					— <?php esc_html_e( 'DNS bot reputation list. Requires a free access key. IPv4 only.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					<a href="https://www.projecthoneypot.org/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'projecthoneypot.org', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 0 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Access key', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-httpbl-key" value="<?php echo esc_attr( (string) $s['honeypot_access_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your personal key from projecthoneypot.org. Used to query their DNS blocklist for known bots and harvesters.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Min. threat score (suspicious IPs)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-httpbl-threat" min="0" max="255" value="<?php echo esc_attr( (string) $s['honeypot_min_threat'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'For IPs marked only as "suspicious" (not confirmed spammers), block when their threat score is at or above this value (0-255). Confirmed comment spammers and email harvesters are always blocked regardless of this setting.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php $this->render_provider_status( $s, $nonce ); ?>

		</div>

		<div class="tsosk-cas-panel" id="tsosk-cas-panel-logs" role="tabpanel" hidden>
		<?php $this->render_log_storage_card( $s, $stats, $learning_stats ); ?>

		<?php if ( ! empty( $log ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recent blocks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat striped tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Author', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Excerpt', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $entry['source'] ?? 'comment' ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $entry['ip'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['author'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( $this->get_reason_label( (string) ( $entry['reason'] ?? '' ) ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['excerpt'] ?? '' ) ); ?></td>
							<td><?php $this->render_allow_button( $entry, 'block', $nonce ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-cas-clear-log" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $learning_log ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Learning log (would-be blocks)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Submissions that were let through while Learning mode is on, but that the current rules would otherwise have blocked. Review this list before disabling Learning mode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-table-wrap">
				<table class="widefat striped tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'IP', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Author', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Excerpt', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Action', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $learning_log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $entry['source'] ?? 'comment' ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $entry['ip'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['author'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( $this->get_reason_label( (string) ( $entry['reason'] ?? '' ) ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['excerpt'] ?? '' ) ); ?></td>
							<td><?php $this->render_allow_button( $entry, 'learning', $nonce ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-cas-clear-learning-log" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear log', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

		</div>

		<p>
			<button type="button" class="button button-primary" id="tsosk-cas-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</button>
			<span id="tsosk-cas-msg" class="tsosk-msg" aria-live="polite"></span>
		</p>

		</form>
		<?php
	}
}
