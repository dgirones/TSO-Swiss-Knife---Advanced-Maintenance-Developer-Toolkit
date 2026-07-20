<?php
/**
 * TSO Swiss Knife – Support / donation helpers (shared TSO brand).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Support
 */
class TSOSK_Support {

	/**
	 * Blog / donations URL (TSO brand).
	 *
	 * @return string
	 */
	public static function get_donate_blog_url(): string {
		$default = 'https://www.tusoporteonline.es/blog';

		/**
		 * Filter the blog / donations URL for TSO Swiss Knife.
		 *
		 * @param string $url Donations page URL.
		 */
		return (string) apply_filters( 'tsosk_donate_blog_url', $default );
	}

	/**
	 * Donate link label for the Plugins screen row meta.
	 *
	 * @return string
	 */
	public static function get_donate_link_label(): string {
		return __( 'Donate', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Add a Donate link on the Plugins screen.
	 *
	 * @param string[] $links Plugin row meta links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function filter_plugin_row_meta( array $links, string $file ): array {
		if ( TSOSK_BASENAME !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::get_kofi_donate_url() ),
			esc_html( self::get_donate_link_label() )
		);

		return $links;
	}

	/**
	 * Ko-fi donation URL shown in the admin header and Plugins screen.
	 *
	 * @return string
	 */
	public static function get_kofi_donate_url(): string {
		$default = 'https://ko-fi.com/deadko_cat';

		/**
		 * Filter the Ko-fi donation URL for TSO Swiss Knife.
		 *
		 * @param string $url Donation page URL.
		 */
		return (string) apply_filters( 'tsosk_kofi_donate_url', $default );
	}

	/**
	 * Donate button label for the current plugin admin language.
	 *
	 * @return string
	 */
	public static function get_donate_label(): string {
		$lang = TSOSK_I18n::get_admin_language_key();

		if ( '' === $lang ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			if ( str_starts_with( $locale, 'ca' ) ) {
				$lang = 'ca';
			} elseif ( str_starts_with( $locale, 'es' ) ) {
				$lang = 'es';
			} else {
				$lang = 'en';
			}
		}

		if ( 'ca' === $lang ) {
			return '☕ Dona suport al plugin';
		}
		if ( 'es' === $lang ) {
			return '☕ Apoya este plugin';
		}

		return __( '☕ Support this plugin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}

	/**
	 * Echo the donate link markup for the admin header.
	 */
	public static function render_donate_button(): void {
		?>
		<a class="tsosk-donate-btn"
		   href="<?php echo esc_url( self::get_kofi_donate_url() ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( self::get_donate_label() ); ?>
		</a>
		<?php
	}

	/**
	 * Read a plugin CSS asset for standalone HTML pages (no wp_enqueue on exit).
	 *
	 * @param string $relative_path Path relative to TSOSK_PATH.
	 * @return string
	 */
	public static function read_asset_css( string $relative_path ): string {
		$path = TSOSK_PATH . ltrim( $relative_path, '/' );
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = file_get_contents( $path );
		return is_string( $css ) ? $css : '';
	}

	/**
	 * Read a POST field as text: unslash + UTF-8/null-byte cleanup.
	 *
	 * Callers must verify nonce/capability before using this helper.
	 * Keeps JSON / serialized shapes (unlike sanitize_text_field).
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	public static function get_post_scalar( string $key ): string {
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies nonce before use.
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Raw POST body cleaned with wp_check_invalid_utf8 + null-byte strip below (must not use sanitize_text_field: destroys JSON/serialized option values).
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) ) {
			if ( is_scalar( $raw ) || null === $raw ) {
				$raw = (string) $raw;
			} else {
				return '';
			}
		}

		$raw     = str_replace( "\0", '', $raw );
		$checked = wp_check_invalid_utf8( $raw, true );
		return is_string( $checked ) ? $checked : '';
	}

	/**
	 * Decode a JSON POST field into an array after UTF-8 cleanup.
	 *
	 * @param string $key POST key.
	 * @return array<mixed>
	 */
	public static function get_post_json_array( string $key ): array {
		return self::decode_json_array( self::get_post_scalar( $key ) );
	}

	/**
	 * Sanitize a scalar string meant for option/meta storage (keeps JSON/serialized shape).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_stored_scalar( $value ): string {
		if ( ! is_string( $value ) ) {
			if ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;
			} else {
				$value = '';
			}
		}

		$value = str_replace( "\0", '', $value );
		$checked = wp_check_invalid_utf8( $value, true );
		return is_string( $checked ) ? $checked : '';
	}

	/**
	 * Decode a JSON POST body into an array after UTF-8 cleanup.
	 *
	 * @param mixed $raw Unslashed JSON string.
	 * @return array<mixed>
	 */
	public static function decode_json_array( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$raw     = self::sanitize_stored_scalar( $raw );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Recursively sanitize a string/array structure with sanitize_text_field.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed
	 */
	public static function sanitize_text_deep( $value ) {
		if ( is_array( $value ) ) {
			return map_deep( $value, 'sanitize_text_field' );
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_scalar( $value ) || null === $value ) {
			return sanitize_text_field( (string) $value );
		}
		return '';
	}
}

add_filter( 'plugin_row_meta', array( 'TSOSK_Support', 'filter_plugin_row_meta' ), 10, 2 );
