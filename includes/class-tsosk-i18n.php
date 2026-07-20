<?php
/**
 * TSO Swiss Knife – Internationalization bootstrap.
 *
 * Loads bundled translations from languages/ and applies the admin
 * language preference only on this plugin's settings screen (WordPress.org
 * compatible gettext flow; no custom gettext filter).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_I18n
 */
class TSOSK_I18n {

	/** User meta key for the plugin admin language switcher. */
	public const LANGUAGE_META_KEY = 'tsosk_admin_language';

	/** @var TSOSK_I18n|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_I18n
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @codeCoverageIgnore */
	private function __construct() {}

	/**
	 * Register locale filters and load bundled translations on the plugin admin screen.
	 */
	public function init(): void {
		add_filter( 'locale', array( $this, 'filter_locale' ), 10, 1 );
		add_filter( 'determine_locale', array( $this, 'filter_locale' ), 10, 1 );
		add_action( 'init', array( $this, 'load_bundled_translations' ), 20 );
		add_action( 'admin_init', array( $this, 'load_bundled_translations' ), 1 );
	}

	/**
	 * Supported plugin admin languages (UI switcher only).
	 *
	 * @return array<string, array{label:string,locale:string}>
	 */
	public static function get_languages(): array {
		return array(
			'ca' => array(
				'label'  => 'CAT',
				'locale' => 'ca',
			),
			'es' => array(
				'label'  => 'ES',
				'locale' => 'es_ES',
			),
			'en' => array(
				'label'  => 'ENG',
				'locale' => 'en_US',
			),
		);
	}

	/**
	 * Current user's plugin admin language key, or empty for site default.
	 *
	 * @return string One of ca, es, en, or ''.
	 */
	public static function get_admin_language_key(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$lang      = get_user_meta( get_current_user_id(), self::LANGUAGE_META_KEY, true );
		$languages = self::get_languages();

		return isset( $languages[ $lang ] ) ? (string) $lang : '';
	}

	/**
	 * Apply the selected language on this plugin admin page only.
	 *
	 * @param string $locale Current locale.
	 * @return string
	 */
	public function filter_locale( string $locale ): string {
		if ( ! $this->is_plugin_admin_screen() ) {
			return $locale;
		}

		$lang      = self::get_admin_language_key();
		$languages = self::get_languages();

		return isset( $languages[ $lang ] ) ? $languages[ $lang ]['locale'] : $locale;
	}

	/**
	 * Whether the current request is the plugin settings screen.
	 *
	 * @return bool
	 */
	private function is_plugin_admin_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page slug check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'tso-swiss-knife' === $page;
	}

	/**
	 * Load the bundled .mo catalog for the active plugin admin locale.
	 *
	 * WordPress JIT may load an outdated language pack; our bundled .mo is the source
	 * of truth for new strings on this screen.
	 */
	public function load_bundled_translations(): void {
		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}

		$locale = determine_locale();
		$suffix = self::locale_file_suffix( $locale );
		if ( '' === $suffix ) {
			return;
		}

		$mofile = $this->resolve_mo_file( $suffix );
		if ( '' === $mofile ) {
			return;
		}

		$domain = TSOSK_TEXT_DOMAIN;
		unload_textdomain( $domain );
		load_textdomain( $domain, $mofile, $locale );
	}

	/**
	 * Map a WordPress locale to the bundled translation file suffix.
	 *
	 * @param string $locale Active locale.
	 * @return string File suffix (ca, es_ES) or empty for English/default.
	 */
	public static function locale_file_suffix( string $locale ): string {
		$map = array(
			'ca'    => 'ca',
			'es_ES' => 'es_ES',
		);

		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		if ( str_starts_with( $locale, 'ca' ) ) {
			return 'ca';
		}

		if ( str_starts_with( $locale, 'es' ) ) {
			return 'es_ES';
		}

		return '';
	}

	/**
	 * Resolve the bundled MO file for a locale suffix.
	 *
	 * @param string $suffix Locale suffix (ca, es_ES).
	 * @return string Readable .mo path or empty string.
	 */
	private function resolve_mo_file( string $suffix ): string {
		$bundled = TSOSK_PATH . 'languages/' . TSOSK_TEXT_DOMAIN . '-' . $suffix . '.mo';
		return is_readable( $bundled ) ? $bundled : '';
	}
}
