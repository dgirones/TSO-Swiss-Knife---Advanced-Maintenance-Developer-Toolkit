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

	/** Block log option key. */
	private const OPTION_LOG = 'tsosk_cas_log';

	/** Per-IP rate counter option key. */
	private const OPTION_RATE = 'tsosk_cas_rate';

	/** Recent form submission hashes for duplicate detection. */
	private const OPTION_FORM_DUP = 'tsosk_cas_form_dup';

	/** Silent form block flag (honeypot fake success). */
	private bool $form_silent_block = false;

	/** Unique honeypot field id suffix per rendered form. */
	private static int $trap_field_counter = 0;

	/** Honeypot field name (must not match real comment fields). */
	private const HONEYPOT_FIELD = 'tsosk_cas_author_url';

	/** Time token field name. */
	private const TIME_FIELD = 'tsosk_cas_time';

	/** Maximum log entries kept. */
	private const MAX_LOG = 150;

	/** Maximum distinct IPs stored for rate limiting. */
	private const MAX_RATE_IPS = 500;

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
	);

	/** @var TSOSK_Mod_Comment_Antispam|null */
	private static $instance = null;

	/** @var array<string, mixed> */
	private array $settings = array();

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_cas_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_cas_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	/**
	 * Register frontend hooks when enabled.
	 */
	public function init(): void {
		$this->settings = self::get_settings();
		if ( empty( $this->settings['enabled'] ) ) {
			return;
		}

		if ( empty( $this->settings['protect_comments'] ) && empty( $this->settings['protect_contact_forms'] ) ) {
			return;
		}

		if ( ! empty( $this->settings['protect_comments'] ) ) {
			add_filter( 'preprocess_comment', array( $this, 'filter_comment' ), 1 );
			add_action( 'comment_form_after_fields', array( $this, 'render_honeypot_fields' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'render_honeypot_fields' ) );
		}

		if ( ! empty( $this->settings['protect_contact_forms'] ) ) {
			$this->register_form_hooks();
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
			add_action( 'wpforms_frontend_output_container_before', array( $this, 'output_trap_fields' ), 10, 0 );
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
			'block_keywords'              => '',
			'block_urls'                  => '',
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
		);
	}

	/**
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

		if ( ! empty( $this->settings['protect_contact_forms'] ) && ! is_admin() ) {
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
		$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;
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
			$html .= '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Website', 'tso-swiss-knife' ) . '</label>';
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
		$html .= '<label for="' . esc_attr( self::HONEYPOT_FIELD ) . '">' . esc_html__( 'Website', 'tso-swiss-knife' ) . '</label>';
		$html .= '<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off" />';
		$html .= '</p>';
		return $html;
	}

	/**
	 * CF7 spam filter.
	 *
	 * @param bool               $spam       Whether CF7 already marked spam.
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
	public function cf7_feedback_response( array $response, string $status ): array {
		if ( $this->form_silent_block && 'spam' === $status ) {
			$this->form_silent_block = false;
			$response['status']  = 'mail_sent';
			$response['message'] = apply_filters(
				'wpcf7_mail_sent_ok',
				__( 'Thank you for your message. It has been sent.', 'contact-form-7' )
			);
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
	public function wpforms_process_before( array $fields, array $entry, array $form_data ): array {
		unset( $entry );
		$data = $this->extract_fields_from_array( $fields, 'wpforms' );
		if ( $this->should_skip_submission( $data ) ) {
			return $fields;
		}

		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return $fields;
		}

		$this->handle_form_block( $data, $check, 'wpforms', (int) ( $form_data['id'] ?? 0 ) );

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $data, $check );
		}

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
		if ( empty( $_POST['gform_submit'] ) ) {
			return $validation_result;
		}

		$data = $this->extract_gravity_fields();
		if ( $this->should_skip_submission( $data ) ) {
			return $validation_result;
		}

		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return $validation_result;
		}

		$validation_result['is_valid'] = false;
		$form                          = $validation_result['form'];
		foreach ( $form['fields'] as &$field ) {
			if ( in_array( $field->type, array( 'textarea', 'text', 'email' ), true ) ) {
				$field->failed_validation  = true;
				$field->validation_message = $check['silent']
					? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife' )
					: $check['message'];
				break;
			}
		}
		unset( $field );
		$validation_result['form'] = $form;

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $data, $check );
		}

		return $validation_result;
	}

	/**
	 * Elementor Pro forms validation.
	 *
	 * @param ElementorPro\Modules\Forms\Classes\Form_Record $record       Form record.
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
			$flat[ $key ] = $field['value'];
		}

		$data = $this->extract_fields_from_array( $flat, 'elementor' );
		if ( $this->should_skip_submission( $data ) ) {
			return;
		}

		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return;
		}

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $data, $check );
		}

		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message(
				$check['silent']
					? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife' )
					: $check['message']
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

		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return $errors;
		}

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $data, $check );
		}

		$errors['tsosk_cas'] = array(
			$check['silent']
				? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife' )
				: $check['message'],
		);

		return $errors;
	}

	/**
	 * Shared form block handler for CF7 (returns bool spam flag).
	 *
	 * @param array<string, mixed> $data Submission data.
	 */
	private function block_form_submission( array $data ): bool {
		if ( $this->should_skip_submission( $data ) ) {
			return false;
		}

		$check = $this->evaluate_submission( $data );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $data );
			return false;
		}

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $data, $check );
		}

		if ( ! empty( $check['silent'] ) ) {
			$this->form_silent_block = true;
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
			? __( 'There was a problem with your submission. Please try again.', 'tso-swiss-knife' )
			: $check['message'];

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
		$author  = '';
		$email   = '';
		$url     = '';
		$parts   = array();

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
	 * @param string $key Lowercase field key.
	 */
	private function is_email_field_key( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(email|correo|e-mail|mail)(?:$|[-_])/i', $key );
	}

	/**
	 * @param string $key Lowercase field key.
	 */
	private function is_name_field_key( string $key ): bool {
		return (bool) preg_match( '/(?:^|[-_])(name|nombre|author|fname|lname|first-name|last-name|your-name)(?:$|[-_])/i', $key );
	}

	/**
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

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) || empty( $field->id ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms validates submission.
			$raw_value = class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_field_value' )
				? GFFormsModel::get_field_value( $field, wp_unslash( $_POST ) )
				: null;

			if ( null === $raw_value ) {
				$input_key = 'input_' . (string) $field->id;
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( ! isset( $_POST[ $input_key ] ) ) {
					continue;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_value = wp_unslash( $_POST[ $input_key ] );
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
	 * Extract submission from raw POST (Gravity Forms).
	 *
	 * @param string $source Provider slug.
	 * @return array<string, mixed>
	 */
	private function extract_fields_from_post( string $source ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Provider validates nonce.
		$raw = array();
		foreach ( $_POST as $key => $value ) {
			$raw[ (string) $key ] = is_array( $value )
				? array_map( 'strval', wp_unslash( $value ) )
				: wp_unslash( $value );
		}
		return $this->extract_fields_from_array( $raw, $source );
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
		$check = $this->evaluate_submission( $commentdata );
		if ( $check['allow'] ) {
			$this->record_successful_submission( $commentdata );
			return $commentdata;
		}

		if ( ! empty( $this->settings['log_blocks'] ) ) {
			$this->log_block( $commentdata, $check );
		}

		if ( ! empty( $check['silent'] ) ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}

		if ( 'discard' === $this->settings['spam_action'] ) {
			wp_die(
				esc_html( $check['message'] ),
				esc_html__( 'Comment blocked', 'tso-swiss-knife' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$commentdata['comment_approved'] = ( 'trash' === $this->settings['spam_action'] ) ? 'trash' : 'spam';
		return $commentdata;
	}

	/**
	 * Whether this comment should bypass all checks.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function should_skip( array $commentdata ): bool {
		if ( ! empty( $commentdata['comment_type'] ) && 'comment' !== $commentdata['comment_type'] ) {
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

		if ( ! empty( $this->settings['honeypot'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public comment form; honeypot must be empty.
			$trap = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
			if ( '' !== $trap ) {
				return $fail( 'honeypot', __( 'Honeypot triggered.', 'tso-swiss-knife' ), true );
			}
		}

		if ( ! empty( $this->settings['time_trap'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public comment form.
			$token = isset( $_POST[ self::TIME_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TIME_FIELD ] ) ) : '';
			if ( '' !== $token && ! $this->verify_time_token( $token ) ) {
				return $fail(
					'time_trap',
					__( 'Submitted too quickly or the form expired. Please wait a few seconds and try again.', 'tso-swiss-knife' )
				);
			}
		}

		if ( ! empty( $this->settings['rate_limit'] ) && $this->is_rate_limited() ) {
			return $fail(
				'rate_limit',
				__( 'Too many submissions from your IP address. Please try again later.', 'tso-swiss-knife' )
			);
		}

		$reputation = $this->check_reputation_services( $data );
		if ( null !== $reputation ) {
			return $fail( $reputation['reason'], $reputation['message'] );
		}

		$cloud = $this->check_cloud( $data );
		if ( true === $cloud ) {
			return $fail( 'cloud', __( 'Submission flagged as spam by cloud filter.', 'tso-swiss-knife' ) );
		}

		$content  = (string) ( $data['comment_content'] ?? '' );
		$author   = (string) ( $data['comment_author'] ?? '' );
		$email    = (string) ( $data['comment_author_email'] ?? '' );
		$url      = (string) ( $data['comment_author_url'] ?? '' );
		$combined = strtolower( $content . ' ' . $author . ' ' . $email . ' ' . $url );

		if ( ! empty( $this->settings['max_links'] ) && $this->count_links( $content ) > (int) $this->settings['max_links'] ) {
			return $fail(
				'max_links',
				__( 'Submission contains too many links.', 'tso-swiss-knife' )
			);
		}

		if ( ! empty( $this->settings['block_disposable_email'] ) && $this->is_disposable_email( $email ) ) {
			return $fail(
				'disposable_email',
				__( 'Disposable email addresses are not allowed.', 'tso-swiss-knife' )
			);
		}

		if ( ! empty( $this->settings['block_cyrillic'] ) && preg_match( '/[\x{0400}-\x{04FF}]/u', $content . $author ) ) {
			return $fail(
				'cyrillic',
				__( 'Comment contains blocked characters.', 'tso-swiss-knife' )
			);
		}

		$keyword = $this->match_blocklist( $combined, (string) $this->settings['block_keywords'] );
		if ( null !== $keyword ) {
			return $fail(
				'keyword',
				/* translators: %s: matched keyword */
				sprintf( __( 'Submission blocked (keyword: %s).', 'tso-swiss-knife' ), $keyword )
			);
		}

		$url_match = $this->match_blocklist( $combined, (string) $this->settings['block_urls'] );
		if ( null !== $url_match ) {
			return $fail(
				'blocked_url',
				/* translators: %s: matched URL fragment */
				sprintf( __( 'Submission blocked (URL: %s).', 'tso-swiss-knife' ), $url_match )
			);
		}

		if ( ! empty( $this->settings['duplicate_check'] ) && $this->is_duplicate_submission( $data ) ) {
			return $fail(
				'duplicate',
				__( 'Duplicate submission detected.', 'tso-swiss-knife' )
			);
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
					'message' => __( 'Submission blocked by Stop Forum Spam reputation list.', 'tso-swiss-knife' ),
				);
			}
		}

		if ( ! empty( $this->settings['abuseipdb_enabled'] ) && '' !== trim( (string) $this->settings['abuseipdb_key'] ) && '' !== $ip ) {
			$abuse = $this->check_abuseipdb( $ip );
			if ( true === $abuse ) {
				return array(
					'reason'  => 'abuseipdb',
					'message' => __( 'Submission blocked: IP address has a poor abuse reputation score.', 'tso-swiss-knife' ),
				);
			}
		}

		if ( ! empty( $this->settings['honeypot_httpbl_enabled'] ) && '' !== trim( (string) $this->settings['honeypot_access_key'] ) && '' !== $ip ) {
			$hp = $this->check_honeypot_httpbl( $ip );
			if ( true === $hp ) {
				return array(
					'reason'  => 'honeypot_httpbl',
					'message' => __( 'Submission blocked by Project Honey Pot bot reputation list.', 'tso-swiss-knife' ),
				);
			}
		}

		return null;
	}

	/**
	 * StopForumSpam lookup (free API, no key required).
	 *
	 * @return bool|null True = spam, false = ham, null = unavailable.
	 */
	private function check_stopforumspam( string $ip, string $email, string $author ): ?bool {
		if ( '' === $ip && '' === $email && '' === $author ) {
			return null;
		}

		$cache_key = 'tsosk_cas_sfs_' . md5( $ip . '|' . $email . '|' . $author );
		$cached    = get_site_transient( $cache_key );
		if ( is_bool( $cached ) ) {
			return $cached;
		}

		$query = array(
			'f'          => 'json',
			'confidence' => 'true',
		);
		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$query['ip'] = $ip;
		}
		if ( is_email( $email ) ) {
			$query['email'] = $email;
		}
		if ( '' !== $author ) {
			$query['username'] = $author;
		}

		if ( count( $query ) <= 2 ) {
			return null;
		}

		$response = wp_remote_get(
			add_query_arg( $query, self::STOPFORUMSPAM_API ),
			array( 'timeout' => 8 )
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || 1 !== (int) ( $body['success'] ?? 0 ) ) {
			return null;
		}

		$min_conf = (float) $this->settings['sfs_min_confidence'];
		$is_spam  = false;

		foreach ( array( 'ip', 'email', 'username' ) as $field ) {
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

		set_site_transient( $cache_key, $is_spam, self::REPUTATION_CACHE_TTL );
		return $is_spam;
	}

	/**
	 * AbuseIPDB IP reputation check.
	 *
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
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return null;
		}

		$score   = isset( $body['data']['abuseConfidenceScore'] ) ? (int) $body['data']['abuseConfidenceScore'] : 0;
		$min     = max( 1, min( 100, (int) $this->settings['abuseipdb_min_score'] ) );
		$is_spam = $score >= $min;

		set_site_transient( $cache_key, $is_spam, self::REPUTATION_CACHE_TTL );
		return $is_spam;
	}

	/**
	 * Project Honey Pot HTTP:BL DNS lookup (IPv4 only).
	 *
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
				set_site_transient( $cache_key, false, self::REPUTATION_CACHE_TTL );
				return false;
			}
			$result = $records[0]['ip'];
		} else {
			$result = gethostbyname( $lookup );
			if ( $result === $lookup ) {
				set_site_transient( $cache_key, false, self::REPUTATION_CACHE_TTL );
				return false;
			}
		}

		$octets = explode( '.', $result );
		if ( 4 !== count( $octets ) || '127' !== $octets[0] ) {
			return null;
		}

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
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function check_akismet( array $commentdata ): ?bool {
		if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'check_comment' ) ) {
			return null;
		}

		$result = Akismet::check_comment( $commentdata, 'comment-check' );
		if ( true === $result || 'true' === $result ) {
			return true;
		}
		if ( false === $result || 'false' === $result ) {
			return false;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private function check_cleantalk( array $commentdata ): ?bool {
		$key = trim( (string) $this->settings['cleantalk_key'] );
		if ( '' === $key ) {
			return null;
		}

		$payload = array(
			'method_name'      => 'check_message',
			'auth_key'         => $key,
			'sender_email'     => (string) ( $commentdata['comment_author_email'] ?? '' ),
			'sender_ip'        => $this->get_client_ip(),
			'sender_nickname'  => (string) ( $commentdata['comment_author'] ?? '' ),
			'sender_url'       => (string) ( $commentdata['comment_author_url'] ?? '' ),
			'message'          => (string) ( $commentdata['comment_content'] ?? '' ),
			'js_on'            => 1,
			'post_info'        => array(
				'comment_type' => ( ! empty( $commentdata['source'] ) && 'comment' !== $commentdata['source'] ) ? 'contact_form' : 'comment',
				'post_url'     => get_permalink( (int) ( $commentdata['comment_post_ID'] ?? 0 ) ) ?: home_url( '/' ),
			),
		);

		$response = wp_remote_post(
			self::CLEANTALK_API,
			array(
				'timeout' => 15,
				'body'    => wp_json_encode( $payload ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

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
	 */
	private function count_links( string $text ): int {
		preg_match_all( '#https?://[^\s<>"\']+#i', $text, $matches );
		return isset( $matches[0] ) ? count( $matches[0] ) : 0;
	}

	/**
	 * @return string|null First matched blocklist entry.
	 */
	private function match_blocklist( string $haystack, string $list ): ?string {
		$list = trim( $list );
		if ( '' === $list ) {
			return null;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $list ) as $line ) {
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

		return in_array( $domain, $domains, true );
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

		$hash   = md5( $ip . '|' . $email . '|' . $content );
		$dupes  = get_option( self::OPTION_FORM_DUP, array() );
		if ( ! is_array( $dupes ) ) {
			$dupes = array();
		}

		$now    = time();
		$dupes  = array_filter(
			$dupes,
			static function ( $ts ) use ( $now, $window ) {
				return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
			}
		);

		return isset( $dupes[ $hash ] );
	}

	/**
	 * Whether IP exceeded rate limit.
	 */
	private function is_rate_limited(): bool {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return false;
		}

		$limit  = max( 1, (int) $this->settings['rate_limit_count'] );
		$window = max( 1, (int) $this->settings['rate_limit_window'] ) * MINUTE_IN_SECONDS;
		$now    = time();
		$rates  = get_option( self::OPTION_RATE, array() );
		if ( ! is_array( $rates ) ) {
			$rates = array();
		}

		$timestamps = isset( $rates[ $ip ] ) && is_array( $rates[ $ip ] ) ? $rates[ $ip ] : array();
		$timestamps = array_values(
			array_filter(
				$timestamps,
				static function ( $ts ) use ( $now, $window ) {
					return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
				}
			)
		);

		if ( count( $timestamps ) >= $limit ) {
			if ( count( $rates ) > 1 ) {
				$rates = $this->prune_rate_data( $rates, $window );
				update_option( self::OPTION_RATE, $rates, false );
			}
			return true;
		}

		if ( count( $rates ) > self::MAX_RATE_IPS ) {
			$rates = $this->prune_rate_data( $rates, $window );
			update_option( self::OPTION_RATE, $rates, false );
		}

		return false;
	}

	/**
	 * Record a successful submission for rate limiting and duplicate detection.
	 *
	 * @param array<string, mixed> $data Submission data.
	 */
	private function record_successful_submission( array $data ): void {
		if ( ! empty( $this->settings['rate_limit'] ) ) {
			$ip = $this->get_client_ip();
			if ( '' !== $ip ) {
				$window = max( 1, (int) $this->settings['rate_limit_window'] ) * MINUTE_IN_SECONDS;
				$now    = time();
				$rates  = get_option( self::OPTION_RATE, array() );
				if ( ! is_array( $rates ) ) {
					$rates = array();
				}

				$timestamps = isset( $rates[ $ip ] ) && is_array( $rates[ $ip ] ) ? $rates[ $ip ] : array();
				$timestamps[] = $now;
				$timestamps   = array_values(
					array_filter(
						$timestamps,
						static function ( $ts ) use ( $now, $window ) {
							return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
						}
					)
				);

				$rates[ $ip ] = $timestamps;
				$rates        = $this->prune_rate_data( $rates, $window );
				update_option( self::OPTION_RATE, $rates, false );
			}
		}

		$source = (string) ( $data['source'] ?? 'comment' );
		if ( 'comment' === $source || ! isset( $data['source'] ) ) {
			return;
		}

		if ( empty( $this->settings['duplicate_check'] ) ) {
			return;
		}

		$ip      = $this->get_client_ip();
		$content = (string) ( $data['comment_content'] ?? '' );
		$email   = (string) ( $data['comment_author_email'] ?? '' );
		if ( '' === $ip ) {
			return;
		}

		$hash  = md5( $ip . '|' . $email . '|' . $content );
		$dupes = get_option( self::OPTION_FORM_DUP, array() );
		if ( ! is_array( $dupes ) ) {
			$dupes = array();
		}
		$window = max( 1, (int) $this->settings['duplicate_window'] ) * MINUTE_IN_SECONDS;
		$now    = time();
		$dupes  = array_filter(
			$dupes,
			static function ( $ts ) use ( $now, $window ) {
				return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
			}
		);
		$dupes[ $hash ] = $now;
		update_option( self::OPTION_FORM_DUP, $dupes, false );
	}

	/**
	 * Append entry to block log.
	 *
	 * @param array<string, mixed> $data  Submission data.
	 * @param array<string, mixed> $check Check result.
	 */
	private function log_block( array $data, array $check ): void {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'ip'      => $this->get_client_ip(),
				'source'  => sanitize_key( (string) ( $data['source'] ?? 'comment' ) ),
				'author'  => sanitize_text_field( (string) ( $data['comment_author'] ?? '' ) ),
				'email'   => sanitize_email( (string) ( $data['comment_author_email'] ?? '' ) ),
				'reason'  => sanitize_key( (string) ( $check['reason'] ?? 'unknown' ) ),
				'excerpt' => $this->substr_safe( sanitize_text_field( (string) ( $data['comment_content'] ?? '' ) ), 120 ),
			)
		);

		if ( count( $log ) > self::MAX_LOG ) {
			$log = array_slice( $log, 0, self::MAX_LOG );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_log(): array {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
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

		$age     = time() - $ts;
		$min     = max( 1, (int) $this->settings['min_submit_seconds'] );
		$max     = max( $min + 1, (int) $this->settings['max_submit_seconds'] );

		return $age >= $min && $age <= $max;
	}

	/**
	 * Safe substring with mbstring fallback.
	 */
	private function substr_safe( string $text, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length );
		}

		return substr( $text, 0, $length );
	}

	/**
	 * Prune stale rate-limit entries and cap stored IP count.
	 *
	 * @param array<string, array<int, int>> $rates  Rate data keyed by IP.
	 * @param int                            $window Window in seconds.
	 * @return array<string, array<int, int>>
	 */
	private function prune_rate_data( array $rates, int $window ): array {
		$now = time();

		foreach ( $rates as $ip => $timestamps ) {
			if ( ! is_array( $timestamps ) ) {
				unset( $rates[ $ip ] );
				continue;
			}

			$timestamps = array_values(
				array_filter(
					$timestamps,
					static function ( $ts ) use ( $now, $window ) {
						return is_numeric( $ts ) && ( $now - (int) $ts ) <= $window;
					}
				)
			);

			if ( empty( $timestamps ) ) {
				unset( $rates[ $ip ] );
			} else {
				$rates[ $ip ] = $timestamps;
			}
		}

		if ( count( $rates ) <= self::MAX_RATE_IPS ) {
			return $rates;
		}

		uasort(
			$rates,
			static function ( $a, $b ) {
				$a_last = is_array( $a ) && ! empty( $a ) ? max( array_map( 'intval', $a ) ) : 0;
				$b_last = is_array( $b ) && ! empty( $b ) ? max( array_map( 'intval', $b ) ) : 0;
				return $a_last <=> $b_last;
			}
		);

		return array_slice( $rates, -self::MAX_RATE_IPS, null, true );
	}

	/**
	 * @return string
	 */
	private function get_client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * @param string $ip IP address.
	 */
	private function is_ip_whitelisted( string $ip ): bool {
		$list = trim( (string) $this->settings['whitelist_ips'] );
		if ( '' === $list || '' === $ip ) {
			return false;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $list ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && $line === $ip ) {
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
		$log     = $this->get_log();
		$cutoff  = time() - DAY_IN_SECONDS;
		$last24  = 0;
		$reasons = array();

		foreach ( $log as $entry ) {
			$reason = (string) ( $entry['reason'] ?? 'unknown' );
			$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
			if ( ! empty( $entry['time'] ) && (int) $entry['time'] >= $cutoff ) {
				++$last24;
			}
		}

		return array(
			'total'     => count( $log ),
			'last_24h'  => $last24,
			'by_reason' => $reasons,
		);
	}

	/** AJAX: save settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$cloud_mode = isset( $_POST['cloud_mode'] ) ? sanitize_key( wp_unslash( $_POST['cloud_mode'] ) ) : 'off';
		if ( ! in_array( $cloud_mode, array( 'off', 'akismet', 'cleantalk' ), true ) ) {
			$cloud_mode = 'off';
		}

		$spam_action = isset( $_POST['spam_action'] ) ? sanitize_key( wp_unslash( $_POST['spam_action'] ) ) : 'spam';
		if ( ! in_array( $spam_action, array( 'spam', 'trash', 'discard' ), true ) ) {
			$spam_action = 'spam';
		}

		$new = array(
			'enabled'                   => ! empty( $_POST['enabled'] ),
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
			'block_keywords'              => isset( $_POST['block_keywords'] )
				? sanitize_textarea_field( wp_unslash( $_POST['block_keywords'] ) )
				: '',
			'block_urls'                  => isset( $_POST['block_urls'] )
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
		);

		if ( $new['enabled'] && empty( $new['protect_comments'] ) && empty( $new['protect_contact_forms'] ) ) {
			wp_send_json_error(
				__( 'Enable at least one protection target: comments or contact forms.', 'tso-swiss-knife' ),
				400
			);
		}

		update_option( self::OPTION, $new, false );
		$this->settings = $new;

		TSOSK_Activity_Log::log( 'comment-antispam', 'save', __( 'Comment anti-spam settings saved.', 'tso-swiss-knife' ) );

		wp_send_json_success( __( 'Comment anti-spam settings saved.', 'tso-swiss-knife' ) );
	}

	/** AJAX: clear block log. */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'tsosk_cas_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		update_option( self::OPTION_LOG, array(), false );
		TSOSK_Activity_Log::log( 'comment-antispam', 'clear', __( 'Comment anti-spam log cleared.', 'tso-swiss-knife' ) );
		wp_send_json_success( __( 'Block log cleared.', 'tso-swiss-knife' ) );
	}

	/**
	 * Render admin tab.
	 */
	public function render(): void {
		$s       = self::get_settings();
		$stats   = $this->get_stats();
		$log     = $this->get_log();
		$nonce   = wp_create_nonce( 'tsosk_cas_nonce' );
		$plugins = $this->get_detected_form_plugins();
		$akismet_active = class_exists( 'Akismet' );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Multi-layer spam protection for WordPress comments and contact forms. Local rules catch most bots; optional Akismet or CleanTalk cloud checks add global reputation data when you need stronger filtering.', 'tso-swiss-knife' ); ?>
		</p>

		<form id="tsosk-cas-form" autocomplete="off">

		<div class="tsosk-card">
			<h3>
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<?php esc_html_e( 'Protection status', 'tso-swiss-knife' ); ?>
				<span class="tsosk-badge <?php echo ! empty( $s['enabled'] ) ? 'tsosk-badge-ok' : ''; ?>" style="margin-left:10px;font-size:12px;">
					<?php echo ! empty( $s['enabled'] ) ? esc_html__( 'Active', 'tso-swiss-knife' ) : esc_html__( 'Inactive', 'tso-swiss-knife' ); ?>
				</span>
			</h3>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-enabled" <?php checked( ! empty( $s['enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Enable anti-spam protection', 'tso-swiss-knife' ); ?></strong>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:24px;">
				<input type="checkbox" id="tsosk-cas-protect-comments" <?php checked( ! empty( $s['protect_comments'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'WordPress comments', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Applies all rules to the native comment form on posts and pages.', 'tso-swiss-knife' ); ?>
				</span>
			</label>

			<label class="tsosk-toggle-row" style="margin-left:24px;">
				<input type="checkbox" id="tsosk-cas-protect-forms" <?php checked( ! empty( $s['protect_contact_forms'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Contact forms', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Applies the same rules to Contact Form 7, WPForms, Gravity Forms, Elementor Pro and Fluent Forms when those plugins are active.', 'tso-swiss-knife' ); ?>
				</span>
			</label>

			<div class="tsosk-notice tsosk-notice-info" style="margin-top:12px;">
				<strong><?php esc_html_e( 'Supported form plugins', 'tso-swiss-knife' ); ?>:</strong>
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
					<span class="tsosk-kv-label"><?php esc_html_e( 'Blocked (24 h)', 'tso-swiss-knife' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $stats['last_24h'] ); ?></span>
				</div>
				<div class="tsosk-kv-item">
					<span class="tsosk-kv-label"><?php esc_html_e( 'Blocked (log)', 'tso-swiss-knife' ); ?></span>
					<span class="tsosk-kv-value"><?php echo esc_html( (string) $stats['total'] ); ?></span>
				</div>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Local protection layers', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'These run on your server without external API calls. Together they block most automated spam.', 'tso-swiss-knife' ); ?></p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-honeypot" <?php checked( ! empty( $s['honeypot'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Honeypot field', 'tso-swiss-knife' ); ?></strong> — <?php esc_html_e( 'Hidden field bots fill in; submission is silently rejected.', 'tso-swiss-knife' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-time-trap" <?php checked( ! empty( $s['time_trap'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Minimum submit time', 'tso-swiss-knife' ); ?></strong> — <?php esc_html_e( 'Rejects comments submitted faster than a human could type.', 'tso-swiss-knife' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-rate-limit" <?php checked( ! empty( $s['rate_limit'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Rate limit by IP', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Stops the same IP from sending too many comments or form submissions in a short period.', 'tso-swiss-knife' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-disposable" <?php checked( ! empty( $s['block_disposable_email'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Block disposable email domains', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Rejects temporary email services (e.g. Mailinator, Guerrilla Mail) often used by spammers.', 'tso-swiss-knife' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-duplicate" <?php checked( ! empty( $s['duplicate_check'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Block duplicate comments', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Rejects the same text from the same IP within the duplicate window below.', 'tso-swiss-knife' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-cyrillic" <?php checked( ! empty( $s['block_cyrillic'] ) ); ?>>
				<span><strong><?php esc_html_e( 'Block Cyrillic characters', 'tso-swiss-knife' ); ?></strong> — <?php esc_html_e( 'Useful for sites that only expect Latin-script comments.', 'tso-swiss-knife' ); ?></span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-skip-logged-in" <?php checked( ! empty( $s['skip_logged_in'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Skip checks for logged-in users', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Trusted visitors with an account bypass all anti-spam rules.', 'tso-swiss-knife' ); ?>
				</span>
			</label>
			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-log-blocks" <?php checked( ! empty( $s['log_blocks'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Log blocked attempts', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Keeps a recent list at the bottom of this page so you can review what was blocked and why.', 'tso-swiss-knife' ); ?>
				</span>
			</label>

			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin-top:14px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Min. seconds before submit', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-min-seconds" min="1" max="60" value="<?php echo esc_attr( (string) $s['min_submit_seconds'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'How long the visitor must wait after the form loads before submitting. Instant submissions are treated as bots. Typical value: 3–5 seconds.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max. form age (seconds)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-max-seconds" min="60" max="86400" value="<?php echo esc_attr( (string) $s['max_submit_seconds'] ); ?>" style="width:100px;">
						<p class="description"><?php esc_html_e( 'Maximum time a form stays valid after it was opened. Older tokens are rejected (e.g. a tab left open overnight). Default: 7200 (2 hours).', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max comments per IP', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-rate-count" min="1" max="50" value="<?php echo esc_attr( (string) $s['rate_limit_count'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Maximum allowed submissions from one IP address within the rate limit window. Example: 3 means the fourth attempt in that period is blocked.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Rate limit window (minutes)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-rate-window" min="1" max="1440" value="<?php echo esc_attr( (string) $s['rate_limit_window'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Time span used together with "Max comments per IP". Example: 3 submissions per 60 minutes.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max links in comment', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-max-links" min="0" max="20" value="<?php echo esc_attr( (string) $s['max_links'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Blocks submissions with more than this many http/https links in the text. Use 0 to disable. Spam often contains many URLs.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Duplicate window (minutes)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-dup-window" min="1" max="1440" value="<?php echo esc_attr( (string) $s['duplicate_window'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'How long to remember recent submissions when checking for duplicates from the same IP with identical content.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Action on spam', 'tso-swiss-knife' ); ?></th>
					<td>
						<select id="tsosk-cas-spam-action">
							<option value="spam" <?php selected( $s['spam_action'], 'spam' ); ?>><?php esc_html_e( 'Mark as spam', 'tso-swiss-knife' ); ?></option>
							<option value="trash" <?php selected( $s['spam_action'], 'trash' ); ?>><?php esc_html_e( 'Move to trash', 'tso-swiss-knife' ); ?></option>
							<option value="discard" <?php selected( $s['spam_action'], 'discard' ); ?>><?php esc_html_e( 'Reject with error (do not save)', 'tso-swiss-knife' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'What happens to blocked WordPress comments. Contact forms always show an error and never store the message.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Cloud filter (optional)', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'For effectiveness similar to dedicated anti-spam plugins, enable a cloud check. Local rules still run as a second layer.', 'tso-swiss-knife' ); ?></p>

			<table class="tsosk-kv-table" style="width:100%;max-width:640px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Cloud mode', 'tso-swiss-knife' ); ?></th>
					<td>
						<select id="tsosk-cas-cloud-mode">
							<option value="off" <?php selected( $s['cloud_mode'], 'off' ); ?>><?php esc_html_e( 'Off (local rules only)', 'tso-swiss-knife' ); ?></option>
							<option value="akismet" <?php selected( $s['cloud_mode'], 'akismet' ); ?>><?php esc_html_e( 'Akismet (requires Akismet plugin + API key)', 'tso-swiss-knife' ); ?></option>
							<option value="cleantalk" <?php selected( $s['cloud_mode'], 'cleantalk' ); ?>><?php esc_html_e( 'CleanTalk API (requires access key)', 'tso-swiss-knife' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Optional second opinion from a global spam database. Local rules always run first; the cloud check adds extra filtering when enabled.', 'tso-swiss-knife' ); ?></p>
						<?php if ( 'akismet' === $s['cloud_mode'] && ! $akismet_active ) : ?>
						<p class="description" style="color:#b32d2e;margin-top:6px;"><?php esc_html_e( 'Akismet plugin is not active. Install and configure Akismet or choose another mode.', 'tso-swiss-knife' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'CleanTalk access key', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-cleantalk-key" value="<?php echo esc_attr( (string) $s['cleantalk_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Only used when Cloud mode is CleanTalk. You can reuse your existing CleanTalk key without keeping their plugin active.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Reputation blocklists', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Query community spam databases before accepting a submission. Results are cached for 6 hours per IP/email to respect API limits.', 'tso-swiss-knife' ); ?>
			</p>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-sfs" <?php checked( ! empty( $s['stopforumspam_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Stop Forum Spam', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Free API, no key required. Checks IP, email and username.', 'tso-swiss-knife' ); ?>
					<a href="https://www.stopforumspam.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'stopforumspam.com', 'tso-swiss-knife' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 16px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Min. confidence (%)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-sfs-confidence" min="1" max="100" value="<?php echo esc_attr( (string) $s['sfs_min_confidence'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'When Stop Forum Spam finds a match, it returns how sure it is (0–100%). We only block if that value is equal to or above this threshold. Lower = stricter (more blocks, including uncertain matches). Higher = only block when the database is very confident. Recommended: 50.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-abuseipdb" <?php checked( ! empty( $s['abuseipdb_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'AbuseIPDB', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'Checks IP abuse score. Requires a free API key.', 'tso-swiss-knife' ); ?>
					<a href="https://www.abuseipdb.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'abuseipdb.com', 'tso-swiss-knife' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 16px 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'API key', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-abuseipdb-key" value="<?php echo esc_attr( (string) $s['abuseipdb_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Create a free key at abuseipdb.com. The plugin sends the visitor IP to check whether other sites have reported it for abuse.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Min. abuse score (0–100)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-abuseipdb-score" min="1" max="100" value="<?php echo esc_attr( (string) $s['abuseipdb_min_score'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'AbuseIPDB assigns each IP a score from 0 (clean) to 100 (widely reported). We block when the score is equal to or above this value. Lower = block sooner; higher = only block IPs with many abuse reports. Recommended: 75.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Max. report age (days)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-abuseipdb-age" min="1" max="365" value="<?php echo esc_attr( (string) $s['abuseipdb_max_age_days'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Ignore abuse reports older than this many days. Prevents blocking an IP because of a problem that happened long ago.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>

			<label class="tsosk-toggle-row">
				<input type="checkbox" id="tsosk-cas-httpbl" <?php checked( ! empty( $s['honeypot_httpbl_enabled'] ) ); ?>>
				<span>
					<strong><?php esc_html_e( 'Project Honey Pot (HTTP:BL)', 'tso-swiss-knife' ); ?></strong>
					— <?php esc_html_e( 'DNS bot reputation list. Requires a free access key. IPv4 only.', 'tso-swiss-knife' ); ?>
					<a href="https://www.projecthoneypot.org/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'projecthoneypot.org', 'tso-swiss-knife' ); ?></a>
				</span>
			</label>
			<table class="tsosk-kv-table" style="width:100%;max-width:640px;margin:8px 0 0 24px;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Access key', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="text" id="tsosk-cas-httpbl-key" value="<?php echo esc_attr( (string) $s['honeypot_access_key'] ); ?>" style="width:100%;max-width:360px;" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your personal key from projecthoneypot.org. Used to query their DNS blocklist for known bots and harvesters.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Min. threat score (suspicious IPs)', 'tso-swiss-knife' ); ?></th>
					<td>
						<input type="number" id="tsosk-cas-httpbl-threat" min="0" max="255" value="<?php echo esc_attr( (string) $s['honeypot_min_threat'] ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'For IPs marked only as "suspicious" (not confirmed spammers), block when their threat score is at or above this value (0-255). Confirmed comment spammers and email harvesters are always blocked regardless of this setting.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Blocklists & bypass', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Your own rules on top of automatic checks: block specific words or URLs, extend the disposable-email list, or whitelist trusted IPs that should never be filtered.', 'tso-swiss-knife' ); ?></p>
			<table class="tsosk-kv-table" style="width:100%;">
				<tr>
					<th style="width:220px;vertical-align:top;"><?php esc_html_e( 'Blocked keywords', 'tso-swiss-knife' ); ?></th>
					<td>
						<textarea id="tsosk-cas-keywords" rows="4" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['block_keywords'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One word or phrase per line. If any line appears in the comment text, author name, email or URL, the submission is blocked. Lines starting with # are ignored. Not case-sensitive.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Blocked URL fragments', 'tso-swiss-knife' ); ?></th>
					<td>
						<textarea id="tsosk-cas-urls" rows="4" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['block_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One domain or URL fragment per line (e.g. casino.xyz or bit.ly/spam). Matched anywhere in the submission content. Useful to stop recurring spam links.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Extra disposable domains', 'tso-swiss-knife' ); ?></th>
					<td>
						<textarea id="tsosk-cas-domains" rows="3" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['custom_disposable_domains'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Add email domains to block in addition to the built-in list. One domain per line, with or without @ (e.g. tempmail.example). Only applies when "Block disposable email domains" is enabled above.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Whitelist IPs', 'tso-swiss-knife' ); ?></th>
					<td>
						<textarea id="tsosk-cas-whitelist" rows="3" style="width:100%;max-width:520px;"><?php echo esc_textarea( (string) $s['whitelist_ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One IP address per line. These addresses skip every anti-spam rule (local, cloud and reputation). Use for your office, home or test machines — not for public visitors.', 'tso-swiss-knife' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( ! empty( $log ) ) : ?>
		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Recent blocks', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-table-wrap">
				<table class="widefat striped tsosk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Source', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'IP', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Author', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'tso-swiss-knife' ); ?></th>
							<th><?php esc_html_e( 'Excerpt', 'tso-swiss-knife' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 25 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $entry['source'] ?? 'comment' ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $entry['ip'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['author'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $entry['excerpt'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p style="margin-top:12px;">
				<button type="button" class="button" id="tsosk-cas-clear-log" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Clear log', 'tso-swiss-knife' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-primary" id="tsosk-cas-save" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Save settings', 'tso-swiss-knife' ); ?>
			</button>
			<span id="tsosk-cas-msg" class="tsosk-msg" aria-live="polite"></span>
		</p>

		</form>
		<?php
	}
}
