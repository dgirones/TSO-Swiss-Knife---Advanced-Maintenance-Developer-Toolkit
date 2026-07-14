<?php
/**
 * TSO Swiss Knife – Site status badges and tab search index.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Site_Status
 */
class TSOSK_Site_Status {

	/**
	 * Extra search keywords per tab slug (English keys for matching).
	 *
	 * @return array<string, string>
	 */
	private static function get_tab_keyword_map(): array {
		return array(
			'hidden-profiles'  => 'emoji xmlrpc rss cron revisions autoload perfiles ocultos',
			'cron'             => 'wp-cron schedule events programador tareas',
			'debug'            => 'wp_debug log developer errors depuracion',
			'maintenance'      => 'offline coming soon mantenimiento',
			'sandbox'          => 'plugins isolate test aislar plugins',
			'rest-api'         => 'json api anonymous rest api',
			'security'         => 'security harden xmlrpc ssl review seguridad',
			'login-protect'    => 'login url brute force acceso login',
			'comment-antispam' => 'comment spam antispam honeypot cleantalk akismet comentarios spam',
			'transients'       => 'cache autoload cleanup transientes',
			'database'         => 'optimize repair mysql tables base datos limpieza',
			'options-editor'   => 'wp_options search edit editor opciones biblioteca',
			'option-library'   => 'plugin options library wp_options editor opciones',
			'meta-editor'      => 'post meta user custom fields metadatos',
			'redirects'        => '301 302 url redirect redirecciones',
			'custom-404'       => '404 page not found error pp_404 pagina error',
			'heartbeat'        => 'admin ajax pulse latido',
			'update-manager'   => 'updates automatic email core plugin theme actualizaciones',
			'action-scheduler' => 'woocommerce queue background jobs cola tareas',
			'slow-queries'     => 'sql performance savequeries consultas lentas',
			'health'           => 'report diagnostics alerts salud informe',
			'content-audit'    => 'broken shortcodes shortcode rotos roto empty title auditoria contenido imagen destacada',
			'slug-manager'     => 'slug permalink url rename slugs gestor slugs enlaces permanentes',
			'footprint'        => 'plugin footprint options huella plugins opciones',
			'history'          => 'activity history log historial actividad cambios registro',
		);
	}

	/**
	 * Active site-wide flags for the header status bar.
	 *
	 * @param string $base_url Plugin admin base URL.
	 * @return array<int, array{label: string, type: string, tab: string}>
	 */
	public static function get_active_badges( string $base_url ): array {
		$badges = array();
		$tab_url = static function ( string $tab ) use ( $base_url ): string {
			return add_query_arg( 'tab', $tab, $base_url );
		};

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$badges[] = array(
				'label' => __( 'Debug ON', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'warn',
				'tab'   => $tab_url( 'debug' ),
			);
		}
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$badges[] = array(
				'label' => __( 'SAVEQUERIES', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'info',
				'tab'   => $tab_url( 'slow-queries' ),
			);
		}

		$maintenance = get_option( 'tsosk_maintenance', array() );
		if ( is_array( $maintenance ) && ! empty( $maintenance['enabled'] ) ) {
			$badges[] = array(
				'label' => __( 'Maintenance', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'warn',
				'tab'   => $tab_url( 'maintenance' ),
			);
		}

		$uid = get_current_user_id();
		if ( $uid && get_user_meta( $uid, 'tsosk_sandbox_plugins', true ) ) {
			$badges[] = array(
				'label' => __( 'Sandbox', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'warn',
				'tab'   => $tab_url( 'sandbox' ),
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$badges[] = array(
				'label' => __( 'WP-Cron off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'info',
				'tab'   => $tab_url( 'hidden-profiles' ),
			);
		}

		$rest = get_option( 'tsosk_rest_settings', array() );
		if ( is_array( $rest ) && isset( $rest['mode'] ) && 'disabled' === $rest['mode'] ) {
			$badges[] = array(
				'label' => __( 'REST locked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'info',
				'tab'   => $tab_url( 'security' ),
			);
		}

		$profiles = get_option( 'tsosk_hidden_profiles', array() );
		if ( is_array( $profiles ) && ! empty( $profiles['disable_xmlrpc'] ) ) {
			$badges[] = array(
				'label' => __( 'XML-RPC off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'success',
				'tab'   => $tab_url( 'security' ),
			);
		}

		$lp = get_option( 'tsosk_login_protect', array() );
		if ( is_array( $lp ) && ! empty( $lp['custom_url'] ) && ! empty( $lp['login_slug'] ) ) {
			$badges[] = array(
				'label' => __( 'Hidden login', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'success',
				'tab'   => $tab_url( 'login-protect' ),
			);
		}

		if ( file_exists( trailingslashit( TSOSK_CONFIG_DIR ) . 'tsosk-security-flags.php' )
			|| file_exists( trailingslashit( TSOSK_CONFIG_DIR ) . 'tsosk-security-flags.json' ) ) {
			$badges[] = array(
				'label' => __( 'Hardened admin', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'success',
				'tab'   => $tab_url( 'security' ),
			);
		}

		if ( class_exists( 'TSOSK_Mod_Update_Manager' ) && TSOSK_Mod_Update_Manager::is_restricting_updates() ) {
			$um = TSOSK_Mod_Update_Manager::get_settings();
			$badges[] = array(
				'label' => 'disable_all' === ( $um['preset'] ?? '' )
					? __( 'Updates off', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Updates managed', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'warn',
				'tab'   => $tab_url( 'update-manager' ),
			);
		}

		if ( class_exists( 'TSOSK_Mod_Custom_404' ) && TSOSK_Mod_Custom_404::get_instance()->is_active() ) {
			$badges[] = array(
				'label' => __( 'Custom 404', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'info',
				'tab'   => $tab_url( 'custom-404' ),
			);
		}

		return $badges;
	}

	/**
	 * Whether developer preset is active (debug+log+queries, no display).
	 */
	public static function is_developer_mode_active(): bool {
		$path = trailingslashit( TSOSK_CONFIG_DIR ) . 'tsosk-debug-flags.php';
		if ( ! file_exists( $path ) ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG
				&& defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
				&& ( ! defined( 'WP_DEBUG_DISPLAY' ) || ! WP_DEBUG_DISPLAY )
				&& defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $path );
		return (bool) preg_match( "/define\(\s*'WP_DEBUG'\s*,\s*true\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'WP_DEBUG_LOG'\s*,\s*true\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'WP_DEBUG_DISPLAY'\s*,\s*false\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'SAVEQUERIES'\s*,\s*true\s*\)/i", $src );
	}

	/**
	 * Build tab search index for the global finder.
	 *
	 * @param array<string, array> $tabs         Ordered tabs from admin.
	 * @param array<string, string> $group_labels Group labels.
	 * @return array<int, array{slug: string, label: string, group: string, url: string, search: string}>
	 */
	public static function build_search_index( array $tabs, array $group_labels, string $base_url ): array {
		$keywords = self::get_tab_keyword_map();
		$index    = array();

		foreach ( $tabs as $slug => $tab ) {
			if ( ! empty( $tab['hidden'] ) ) {
				continue;
			}
			$group_id = $tab['group'] ?? 'site';
			$label    = $tab['label'] ?? $slug;
			$group    = $group_labels[ $group_id ] ?? $group_id;
			$extra    = $keywords[ $slug ] ?? '';
			$index[]  = array(
				'slug'   => $slug,
				'label'  => $label,
				'group'  => $group,
				'url'    => add_query_arg( 'tab', $slug, $base_url ),
				'search' => strtolower( $slug . ' ' . $label . ' ' . $group . ' ' . $extra ),
			);
		}

		return $index;
	}

	/**
	 * Normalize a user search query into lowercase tokens.
	 *
	 * @param string $query Raw search input.
	 * @return string[]
	 */
	public static function search_tokens( string $query ): array {
		$query = strtolower( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$tokens = preg_split( '/\s+/u', $query ) ?: array();
		return array_values( array_filter( array_map( 'trim', $tokens ) ) );
	}

	/**
	 * Whether every token appears in the haystack string.
	 *
	 * @param string   $haystack Lowercase search blob.
	 * @param string[] $tokens   Query tokens.
	 * @return bool
	 */
	public static function matches_search_tokens( string $haystack, array $tokens ): bool {
		if ( empty( $tokens ) ) {
			return true;
		}
		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( false === strpos( $haystack, $token ) ) {
				return false;
			}
		}
		return true;
	}
}
