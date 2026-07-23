<?php
/**
 * TSO Swiss Knife – Documented wp_options presets for popular plugins.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Option_Library
 */
class TSOSK_Option_Library {

	/**
	 * Preset libraries keyed by internal id.
	 *
	 * @return array<string, array{
	 *   label: string,
	 *   icon: string,
	 *   detect: callable,
	 *   prefix: string,
	 *   options: array<int, array{name: string, desc: string, caution?: bool}>
	 * }>
	 */
	public static function get_registry(): array {
		return array(
			'wordpress' => array(
				'label'  => __( 'WordPress core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-wordpress',
				'detect' => static function (): bool {
					return true;
				},
				'prefix' => '',
				'options' => array(
					array(
						'name' => 'uploads_use_yearmonth_folders',
						'desc' => __( 'Organise uploads into year/month folders.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'posts_per_page',
						'desc' => __( 'Number of posts shown on blog pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'posts_per_rss',
						'desc' => __( 'Number of posts in RSS feeds.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'rss_use_excerpt',
						'desc' => __( 'Use excerpts instead of full posts in feeds.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'blog_charset',
						'desc' => __( 'Site character encoding (usually UTF-8).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'timezone_string',
						'desc' => __( 'Site timezone identifier.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'default_role',
						'desc' => __( 'Default role assigned to new users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'users_can_register',
						'desc' => __( 'Whether anyone can register (0 or 1).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'blog_public',
						'desc' => __( 'Discourage search engines (0 = discourage, 1 = allow indexing).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'permalink_structure',
						'desc' => __( 'Pretty permalink pattern (empty = plain ?p= links).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'category_base',
						'desc' => __( 'Custom base slug for category URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'tag_base',
						'desc' => __( 'Custom base slug for tag URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'show_on_front',
						'desc' => __( 'Front page displays: posts or a static page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'page_on_front',
						'desc' => __( 'Page ID used as the static front page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'page_for_posts',
						'desc' => __( 'Page ID used as the posts page.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'default_comment_status',
						'desc' => __( 'Allow comments on new posts (open or closed).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'comment_moderation',
						'desc' => __( 'Hold comments for manual approval before they appear.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'thumbnail_size_w',
						'desc' => __( 'Thumbnail width in pixels.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'thumbnail_size_h',
						'desc' => __( 'Thumbnail height in pixels.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'medium_size_w',
						'desc' => __( 'Medium image width in pixels.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'large_size_w',
						'desc' => __( 'Large image width in pixels.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'image_default_size',
						'desc' => __( 'Default size inserted into posts (thumbnail, medium, large, full).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'WPLANG',
						'desc' => __( 'WordPress locale code (empty = English US).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'cron',
						'desc' => __( 'Scheduled cron events (serialized array).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
						'readonly' => true,
					),
				),
			),
			'woocommerce' => array(
				'label'  => __( 'WooCommerce', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-cart',
				'detect' => static function (): bool {
					return class_exists( 'WooCommerce' );
				},
				'prefix' => 'woocommerce_',
				'options' => array(
					array(
						'name' => 'woocommerce_store_address',
						'desc' => __( 'Store street address.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'woocommerce_currency',
						'desc' => __( 'Store currency code (e.g. EUR, USD).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'woocommerce_enable_guest_checkout',
						'desc' => __( 'Allow checkout without an account.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'woocommerce_manage_stock',
						'desc' => __( 'Enable stock management globally.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
				),
			),
			'yoast' => array(
				'label'  => __( 'Yoast SEO', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-chart-line',
				'detect' => static function (): bool {
					return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
				},
				'prefix' => 'wpseo',
				'options' => array(
					array(
						'name' => 'wpseo',
						'desc' => __( 'Main Yoast SEO settings (serialized array).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'wpseo_titles',
						'desc' => __( 'Title and meta description templates.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'wpseo_social',
						'desc' => __( 'Open Graph and social profiles.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
				),
			),
			'rankmath' => array(
				'label'  => __( 'Rank Math', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-chart-area',
				'detect' => static function (): bool {
					return defined( 'RANK_MATH_VERSION' );
				},
				'prefix' => 'rank_math',
				'options' => array(
					array(
						'name' => 'rank_math_options',
						'desc' => __( 'Main Rank Math settings (serialized).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'rank_math_modules',
						'desc' => __( 'Enabled Rank Math modules.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
				),
			),
			'acf' => array(
				'label'  => __( 'Advanced Custom Fields', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-forms',
				'detect' => static function (): bool {
					return class_exists( 'ACF' ) || function_exists( 'acf_get_setting' );
				},
				'prefix' => 'acf_',
				'options' => array(
					array(
						'name' => 'acf_version',
						'desc' => __( 'Installed ACF version string.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'acf_pro_license',
						'desc' => __( 'ACF Pro license data (read-only here — do not share exports).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
						'readonly' => true,
					),
				),
			),
			'elementor' => array(
				'label'  => __( 'Elementor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-layout',
				'detect' => static function (): bool {
					return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
				},
				'prefix' => 'elementor_',
				'options' => array(
					array(
						'name' => 'elementor_version',
						'desc' => __( 'Installed Elementor version.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'elementor_cpt_support',
						'desc' => __( 'Post types that use Elementor.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'elementor_disable_color_schemes',
						'desc' => __( 'Disable default Elementor color schemes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
				),
			),
			'wpforms' => array(
				'label'  => __( 'WPForms', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-email',
				'detect' => static function (): bool {
					return function_exists( 'wpforms' ) || defined( 'WPFORMS_VERSION' );
				},
				'prefix' => 'wpforms_',
				'options' => array(
					array(
						'name' => 'wpforms_version',
						'desc' => __( 'WPForms version.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					),
					array(
						'name' => 'wpforms_settings',
						'desc' => __( 'Global WPForms settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
				),
			),
			'litespeed' => array(
				'label'  => __( 'LiteSpeed Cache', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'icon'   => 'dashicons-performance',
				'detect' => static function (): bool {
					return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' );
				},
				'prefix' => 'litespeed.',
				'options' => array(
					array(
						'name' => 'litespeed.conf.cache',
						'desc' => __( 'LiteSpeed cache enabled flag.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
					array(
						'name' => 'litespeed.conf.object',
						'desc' => __( 'Object cache settings.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
						'caution' => true,
					),
				),
			),
		);
	}

	/**
	 * Libraries for currently active plugins / core.
	 *
	 * @return array<string, array>
	 */
	public static function get_active_libraries(): array {
		$active = array();
		foreach ( self::get_registry() as $id => $library ) {
			$detect = $library['detect'];
			if ( is_callable( $detect ) && $detect() ) {
				$active[ $id ] = $library;
			}
		}
		return $active;
	}

	/**
	 * Whether an option exists in the database.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_exists( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);
	}
}
