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
		return __( 'Donate', 'tso-swiss-knife' );
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

		return __( '☕ Support this plugin', 'tso-swiss-knife' );
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
}

add_filter( 'plugin_row_meta', array( 'TSOSK_Support', 'filter_plugin_row_meta' ), 10, 2 );
