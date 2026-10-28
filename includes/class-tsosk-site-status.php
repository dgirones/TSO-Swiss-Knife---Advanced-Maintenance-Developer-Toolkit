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
	 * Extra search keywords per tab slug.
	 *
	 * Mixed English / Spanish / Catalan on purpose, so the finder works whatever the plugin
	 * language is. Accents are stripped when the index is built, so they are optional here.
	 * Add concrete file names, constants and plain-language terms people actually type.
	 *
	 * @return array<string, string>
	 */
	private static function get_tab_keyword_map(): array {
		return array(
			'dashboard'         => 'overview home dashboard score summary panel vitals status resumen resum puntuacion puntuacio inicio inici estado estat salud recomendaciones recomanacions atencion most viewed shortcuts atajos dreceres actividad reciente',
			'history'           => 'activity history log audit trail changes historial actividad cambios canvis registro registre auditoria',
			'hidden-profiles'   => 'hidden profiles presets performance rendimiento rendiment emoji emojis xmlrpc xml-rpc rss feed feeds oembed embeds revisions revisiones autosave autoguardado trash papelera paperera comments comentarios comentaris pings pingbacks trackbacks generator wp_head head cleanup limpieza neteja wp-config constants perfiles ocultos perfils ocults',
			'cron'              => 'cron wp-cron wp_cron schedule scheduled events cron jobs programador tareas tasques programades eventos esdeveniments missed schedule publicacion programada disable_wp_cron reschedule reprogramar',
			'action-scheduler'  => 'action scheduler woocommerce queue background jobs cola tareas segundo plano cua tasques pending failed pendientes fallidas',
			'debug'             => 'debug wp_debug debug.log log logs errors error errores depuracion depuracio developer desarrollador desenvolupador savequeries script_debug display php notices warnings deprecated fatal modo desarrollador',
			'options-editor'    => 'options editor wp_options option opciones opcions autoload autoloaded autocarga database edit editar siteurl home blogname serialized json add delete eliminar',
			'leftovers'         => 'leftovers plugin theme residues orphan orphaned uninstalled removed deleted old restos residuos huerfanos restes residus orfes plugins temas temes wp-content database base datos dades clean limpiar netejar',
			'meta-editor'       => 'meta postmeta post_meta usermeta user_meta custom fields campos personalizados metadatos metadades camps',
			'option-library'    => 'option library biblioteca opciones plugin options documented documentadas catalog catalogo cataleg opcions plugins wp_options reference referencia',
			'site-snapshot'     => 'export import snapshot configuration config settings backup copia seguridad exportar importar configuracion configuracio migrar migrate json restore restaurar undo deshacer environment entorno',
			'tso-plugins'       => 'link inspector links enlaces rotos broken links broken 404 enllacos trencats checker revisar enlaces database db mysql tables tablas taules options opciones orphan huerfanas orfes cleaner limpiador neteja optimize optimizar optimitzar repair reparar overhead size tamano mida bloat wp_options autoload cleanup leftover residual base datos limpieza more tso plugins mas plugins tso mes plugins tso options tables cleaner',
			'transients'        => 'transient transients transientes cache cached expired caducados purge purgar autoload cleanup limpieza neteja delete eliminar',
			'view-counter'      => 'view counter visits visitas visites pageviews statistics estadisticas estadistiques stats analytics downloads descargas descarregues most viewed mas vistos outbound clicks enlaces salientes tracking contador de visitas',
			'constants'         => 'constants constantes wp-config wp-config.php define defines memory limit wp_memory_limit paths urls database security autosave revisions multisite php version upload max filesize max execution time system information environment overrides sistema informacion',
			'internals'         => 'internals post types taxonomies taxonomias shortcodes roles capabilities capacidades query vars rewrite tags post statuses cpt custom post types registered registrados',
			'rest-api'          => 'rest api rest-api json wp-json endpoint endpoints namespace namespaces user enumeration enumeracion usuarios authors autores block bloquear disable deshabilitar anonymous anonimo',
			'heartbeat'         => 'heartbeat latido pulse admin-ajax ajax cpu load carga autosave interval intervalo',
			'update-manager'    => 'updates update actualizaciones actualitzacions automatic automaticas automatiques auto-update core plugin theme tema themes plugins email notifications notificaciones notificacions disable updates bloquear pause version versions',
			'slow-queries'      => 'slow queries slow query consultas lentas consultes lentes sql mysql savequeries performance rendimiento rendiment duplicate queries database profiling query monitor',
			'search-replace'    => 'search replace buscar reemplazar substituir canviar domain dominio domini url migration migracion migracio migrate http https regex serialized backup preview vista previa wp_posts find',
			'hooks'             => 'hooks hook actions action filters filter callbacks priority prioridad prioritat add_action add_filter inspector acciones filtros',
			'rewrite'           => 'rewrite rules reglas reescritura regles permalinks enlaces permanentes enllacos permanents flush htaccess .htaccess routing router routes rutas simulator simulador 404 apache nginx web.config',
			'server-files'      => 'server files robots robots.txt htaccess .htaccess sitemap sitemaps sitemap.xml xml seo google indexing indexacion indexacio crawler crawlers bots spider noindex disallow allow wp-config ads.txt security.txt llms.txt apache servidor archivos fitxers file editor',
			'redirects'         => 'redirects redirect redirections redireccion redireccions redirecciones 301 302 307 410 404 monitor 404s not found broken urls urls rotas trencades regex wildcard csv import export seo moved gone',
			'custom-404'        => 'custom 404 page not found pagina no encontrada pagina no trobada error page template plantilla pp_404 landing',
			'slug-manager'      => 'slug slugs permalink permalinks url urls rename renombrar reanomenar long slugs largos llargs duplicates duplicados duplicats accents tildes acentos characters caracteres seo gestor slugs enlaces permanentes',
			'health'            => 'health site health report salud informe diagnostics diagnostico diagnostic alerts alertas alertes email score autoload site health notices avisos autoloaded options performance',
			'admin-menu'        => 'admin menu sidebar menu lateral barra lateral reorder reordenar hide ocultar amagar hidden items elementos wp-admin',
			'users'             => 'users usuarios usuaris sessions sesiones inactive inactivos inactius login history historial accesos accessos logout cerrar sesion force password change contrasena password admin administrators administradores',
			'roles'             => 'roles capabilities capacidades permisos permissions capacitats clone clonar compare comparar templates plantillas editor author contributor subscriber',
			'media-cleaner'     => 'media cleaner media library biblioteca medios mediateca orphan huerfanos orfes unused sin usar attachments adjuntos missing files archivos faltantes broken duplicates duplicados images imagenes unattached clean limpiar neteja',
			'media-footprint'   => 'uploads footprint huella disk space espacio disco espai disc size tamano mida folder carpeta quarantine cuarentena quarentena largest files archivos grandes file types tipos formats scan escaneo month mes storage almacenamiento hosting quota',
			'image-sizes-audit' => 'image sizes tamanos imagen mides imatge thumbnails miniaturas miniatures regenerate regenerar crop recorte disabled sizes unused free up space liberar espacio quarantine webp avif intermediate',
			'security'          => 'security seguridad seguretat harden hardening bastionar xmlrpc xml-rpc rss feeds application passwords contrasenas de aplicacion php execution uploads .htaccess htaccess wp-config file edit disallow_file_edit ssl force_ssl_admin admin alerts new administrators user enumeration review checks',
			'file-integrity'    => 'file integrity core files checksums md5 modified files archivos modificados fitxers modificats malware virus hack hacked infected suspicious sospechosos sospitosos missing uploads php verify wordpress.org integridad',
			'login-protect'     => 'login protection brute force fuerza bruta forca bruta lockout bloqueo bloqueig custom login url wp-login wp-login.php wp-admin hide login ocultar acceso whitelist ip lista blanca captcha attempts intentos intents forbidden usernames admin',
			'comment-antispam'  => 'comment spam antispam anti-spam comentarios comentaris honeypot akismet cleantalk blocklist stopforumspam dnsbl bots blocked learning cloud filter links',
			'email'             => 'email mail wp_mail smtp test email prueba correo correu enviament deliverability entregabilidad phpmailer from name from email plugin smtp spf dkim notifications',
			'staging'           => 'staging mode test copy copia pruebas proves noindex mail log correos bloquear emails discourage search engines entorno pruebas desarrollo development robots prova correus',
			'url-doctor'        => 'url doctor https http ssl siteurl home mixed content contenido mixto www redirect loop bucle certificado certificat mover migration migracion domain dominio protocol protocolo cadenat',
			'runtime-stack'     => 'server runtime php version memory limit memoria max execution time upload opcache object cache redis memcached drop-in dropins mu-plugins must-use limits servidor hosting ini extensions phpinfo apache nginx litespeed sapi',
			'content-audit'     => 'content audit auditoria contenido shortcode shortcodes broken rotos duplicate titles titulos duplicados empty vacio featured image imagen destacada thin content seo orphan posts',
			'maintenance'       => 'maintenance mode maintenance modo mantenimiento manteniment coming soon proximamente 503 offline construction under construction whitelist ip logo page message',
			'sandbox'           => 'plugin sandbox sandbox bisection biseccion conflict conflicto conflicte isolate aislar aillar test plugins deactivate desactivar troubleshoot troubleshooting white screen pantalla blanca error fatal',
		);
	}

	/**
	 * Sections inside tabs that the finder can jump to: heading text on the destination screen plus extra keywords.
	 * Needles are the exact heading strings (translated), so they match the screen in every language.
	 *
	 * @return array<string, array<int, array{needle: string, kw: string}>>
	 */
	private static function get_section_map(): array {
		return array(
			'server-files'      => array(
				array(
					'needle' => 'robots.txt',
					'kw'     => 'robots robots.txt disallow allow crawler crawlers bots spider noindex google indexing indexacion indexacio',
				),
				array(
					'needle' => '.htaccess',
					'kw'     => 'htaccess .htaccess apache servidor server rewrite redirects',
				),
				array(
					'needle' => __( 'Sitemaps', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'sitemap sitemaps sitemap.xml xml seo google mapa del sitio',
				),
			),
			'security'          => array(
				array(
					'needle' => __( 'Remote access', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'xmlrpc xml-rpc rss feeds remote acceso remoto',
				),
				array(
					'needle' => __( 'User & Admin Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'alerts alertas alertes admin usuarios',
				),
				array(
					'needle' => __( 'Application Passwords', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'app passwords application contrasenas aplicacion contrasenyes',
				),
				array(
					'needle' => __( 'PHP Execution in Uploads', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'php execution uploads ejecucion subidas .htaccess',
				),
				array(
					'needle' => __( 'Toggleable Security Constants', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'disallow_file_edit file edit force_ssl_admin ssl constants constantes editor de archivos',
				),
				array(
					'needle' => __( 'Security Checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'checks comprobaciones revisio revision',
				),
			),
			'hidden-profiles'   => array(
				array(
					'needle' => __( 'Quick presets', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'presets emoji emojis xmlrpc rss feed feeds oembed embeds revisions revisiones autosave autoguardado trash papelera comments comentarios pings pingbacks heartbeat',
				),
				array(
					'needle' => __( 'Constants (uploads config)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'constants constantes wp_post_revisions autosave_interval empty_trash_days disable_wp_cron',
				),
				array(
					'needle' => __( 'Runtime filters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'filters filtros filtres runtime',
				),
			),
			'debug'             => array(
				array(
					'needle' => __( 'Debug Constants', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'wp_debug savequeries script_debug display constants constantes',
				),
				array(
					'needle' => __( 'Available Error Logs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'debug.log logs errors errores log registro php',
				),
			),
			'update-manager'    => array(
				array(
					'needle' => __( 'Update checks', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'core plugin theme automatic auto-update automaticas automatiques',
				),
				array(
					'needle' => __( 'Individual plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'plugins individual pause pausar bloquear',
				),
				array(
					'needle' => __( 'Update email notifications', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'email notifications notificaciones notificacions correo',
				),
			),
			'redirects'         => array(
				array(
					'needle' => __( 'Add or Edit Redirect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => '301 302 307 410 regex wildcard crear nueva redireccion',
				),
				array(
					'needle' => __( 'Redirect Review', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'review revisar revisio',
				),
				array(
					'needle' => __( '404 Monitor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => '404 not found monitor urls rotas trencades',
				),
				array(
					'needle' => __( 'Suggested patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'patterns patrones suggerides',
				),
			),
			'rest-api'          => array(
				array(
					'needle' => __( 'Block User Enumeration', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'user enumeration enumeracion usuarios authors autores',
				),
				array(
					'needle' => __( 'Disable Specific Namespaces', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'namespace namespaces endpoint endpoints',
				),
				array(
					'needle' => __( 'Recently blocked requests', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'blocked bloqueadas bloquejades log',
				),
			),
			'health'            => array(
				array(
					'needle' => __( 'Top autoloaded options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'autoload autoloaded wp_options autocarga',
				),
				array(
					'needle' => __( 'Email Alerts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'alerts alertas alertes email correo',
				),
			),
			'email'             => array(
				array(
					'needle' => __( 'Send Test Email', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'test prueba proves correo correu smtp',
				),
				array(
					'needle' => __( 'Detected SMTP / mail plugins', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'smtp plugins mail detected detectados',
				),
			),
			'users'             => array(
				array(
					'needle' => __( 'Administrator Sessions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'sessions sesiones logout cerrar sesion administrators',
				),
				array(
					'needle' => __( 'Inactive Users (90+ days)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'inactive inactivos inactius',
				),
				array(
					'needle' => __( 'Login History', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'login history historial accesos accessos',
				),
			),
			'view-counter'      => array(
				array(
					'needle' => __( 'Most downloaded files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'downloads descargas descarregues archivos',
				),
				array(
					'needle' => __( 'Most clicked outbound links', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'outbound clicks enlaces salientes',
				),
				array(
					'needle' => __( 'Import previous counters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'import importar contadores',
				),
			),
			'site-snapshot'     => array(
				array(
					'needle' => __( 'Export snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'export exportar copia backup',
				),
				array(
					'needle' => __( 'Import snapshot', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'import importar restore restaurar',
				),
				array(
					'needle' => __( 'Undo last import', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'undo deshacer desfer restore',
				),
			),
			'slow-queries'      => array(
				array(
					'needle' => __( 'Duplicate queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'duplicate duplicadas duplicades',
				),
				array(
					'needle' => __( 'Slowest queries', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'slowest lentas lentes',
				),
				array(
					'needle' => __( 'Top Slow Query Patterns', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'patterns patrones',
				),
			),
			'media-footprint'   => array(
				array(
					'needle' => __( 'Quarantine (recoverable trash)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'quarantine cuarentena quarentena trash papelera',
				),
				array(
					'needle' => __( 'Largest files (top 20)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'largest biggest grandes arxius files',
				),
				array(
					'needle' => __( 'Usage by month', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'month mes monthly',
				),
				array(
					'needle' => __( 'Space by file type', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'types tipos formats',
				),
			),
			'image-sizes-audit' => array(
				array(
					'needle' => __( 'Registered image sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'registered sizes tamanos mides thumbnails',
				),
				array(
					'needle' => __( 'Free up space from disabled sizes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'free space liberar espacio disabled',
				),
			),
			'constants'         => array(
				array(
					'needle' => __( 'Useful System Information', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'php version memory limit system information',
				),
				array(
					'needle' => __( 'Environment Overrides', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'environment overrides entorno',
				),
			),
			'runtime-stack'     => array(
				array(
					'needle' => __( 'PHP and hosting limits', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'php version memory limit upload max execution time limites',
				),
				array(
					'needle' => __( 'Object cache (fast database memory)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'object cache redis memcached cache de objetos',
				),
				array(
					'needle' => __( 'Drop-in files in wp-content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'drop-in dropins',
				),
				array(
					'needle' => __( 'Must-use plugins (always on)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'mu-plugins must-use',
				),
			),
			'maintenance'       => array(
				array(
					'needle' => __( 'Maintenance Page Settings', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'whitelist ip lista blanca logo message mensaje page pagina cidr',
				),
			),
			'comment-antispam'  => array(
				array(
					'needle' => __( 'Local protection layers', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'honeypot local layers capas',
				),
				array(
					'needle' => __( 'Reputation blocklists', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'blocklist stopforumspam dnsbl reputation',
				),
				array(
					'needle' => __( 'Cloud filter (optional)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'akismet cleantalk cloud nube',
				),
			),
			'login-protect'     => array(
				array(
					'needle' => __( 'Live Attempt Counters', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'attempts intentos intents counters',
				),
				array(
					'needle' => __( 'Login Maintenance & Advanced', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'custom login url wp-login whitelist ip lockout advanced avanzado',
				),
			),
			'rewrite'           => array(
				array(
					'needle' => __( 'Flush Rewrite Rules', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'flush permalinks refrescar regenerar',
				),
				array(
					'needle' => __( 'Permalink & Routing Simulator', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'simulator simulador router routes',
				),
			),
			'cron'              => array(
				array(
					'needle' => __( 'WP-Cron Health', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'health salud salut disable_wp_cron',
				),
				array(
					'needle' => __( 'Missed scheduled posts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'missed perdidas perdudes publicacion programada',
				),
				array(
					'needle' => __( 'Reschedule cron event', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'reschedule reprogramar',
				),
			),
			'staging'           => array(
				array(
					'needle' => __( 'Test-site options', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'noindex discourage search engines mail log pruebas proves',
				),
				array(
					'needle' => __( 'Recent emails', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'emails correos correus',
				),
			),
			'url-doctor'        => array(
				array(
					'needle' => __( 'Addresses WordPress has saved', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'siteurl home addresses direcciones adreces',
				),
				array(
					'needle' => __( 'Leftover http addresses in the database', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'mixed content http https contenido mixto',
				),
			),
			'heartbeat'         => array(
				array(
					'needle' => __( 'Heartbeat Mode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'mode modo',
				),
				array(
					'needle' => __( 'Custom Interval (seconds)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'interval intervalo segundos',
				),
			),
			'content-audit'     => array(
				array(
					'needle' => __( 'Duplicate titles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'duplicate titles titulos duplicados',
				),
				array(
					'needle' => __( 'Shortcode inventory', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'shortcodes inventory inventario',
				),
				array(
					'needle' => __( 'Broken Shortcodes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'broken rotos trencats shortcodes',
				),
			),
			'file-integrity'    => array(
				array(
					'needle' => __( 'Scan Summary', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'scan summary resumen resum',
				),
				array(
					'needle' => __( 'Ignored Files', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'ignored ignorados ignorats',
				),
			),
			'options-editor'    => array(
				array(
					'needle' => __( 'Add New Option', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'add new nueva nova option opcion crear',
				),
			),
			'meta-editor'       => array(
				array(
					'needle' => __( 'Search meta rows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'kw'     => 'search buscar filas rows',
				),
			),
		);
	}

	/**
	 * Lowercase a string and strip accents so "configuración" matches "configuracion".
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize_search_text( string $text ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		return strtolower( $text );
	}

	/**
	 * Active site-wide flags for the header status bar.
	 *
	 * @param string $base_url Plugin admin base URL.
	 * @return array<int, array{label: string, type: string, tab: string}>
	 */
	public static function get_active_badges( string $base_url ): array {
		$badges  = array();
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

		if ( class_exists( 'TSOSK_Mod_Maintenance' ) && TSOSK_Mod_Maintenance::is_active_now() ) {
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
			$um       = TSOSK_Mod_Update_Manager::get_settings();
			$badges[] = array(
				'label' => 'disable_all' === ( $um['preset'] ?? '' )
					? __( 'Updates blocked', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' )
					: __( 'Update checks limited', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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

		if ( class_exists( 'TSOSK_Mod_Staging' ) && TSOSK_Mod_Staging::is_any_feature_on() ) {
			$badges[] = array(
				'label' => __( 'Staging', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'type'  => 'warn',
				'tab'   => $tab_url( 'staging' ),
			);
		}

		return $badges;
	}

	/**
	 * Whether the plugin's developer preset JSON (or legacy PHP) is saved and matches the preset.
	 */
	public static function is_developer_mode_active(): bool {
		if ( class_exists( 'TSOSK_Config_Storage' ) ) {
			$data = TSOSK_Config_Storage::read_json( TSOSK_Config_Storage::DEBUG_JSON );
			if ( ! empty( $data['flags'] ) && is_array( $data['flags'] ) ) {
				return self::flags_match_developer_preset( $data['flags'] );
			}
		}

		$path = trailingslashit( TSOSK_CONFIG_DIR ) . 'tsosk-debug-flags.php';
		if ( ! file_exists( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$src = (string) file_get_contents( $path );
		return (bool) preg_match( "/define\(\s*'WP_DEBUG'\s*,\s*true\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'WP_DEBUG_LOG'\s*,\s*true\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'WP_DEBUG_DISPLAY'\s*,\s*false\s*\)/i", $src )
			&& (bool) preg_match( "/define\(\s*'SAVEQUERIES'\s*,\s*true\s*\)/i", $src );
	}

	/**
	 * Flags match developer preset.
	 *
	 * @param array<string, mixed> $flags Saved debug flag map.
	 */
	private static function flags_match_developer_preset( array $flags ): bool {
		return ! empty( $flags['WP_DEBUG'] )
			&& ! empty( $flags['WP_DEBUG_LOG'] )
			&& empty( $flags['WP_DEBUG_DISPLAY'] )
			&& empty( $flags['SCRIPT_DEBUG'] )
			&& ! empty( $flags['SAVEQUERIES'] );
	}

	/**
	 * Build tab search index for the global finder.
	 *
	 * @param array<string, array>  $tabs         Ordered tabs from admin.
	 * @param array<string, string> $group_labels Group labels.
	 * @param string                $base_url Base url.
	 * @return array<int, array{slug: string, label: string, group: string, url: string, search: string, label_n: string, group_n: string, kw: string}>
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
			$extra    = self::normalize_search_text( $keywords[ $slug ] ?? '' );
			$label_n  = self::normalize_search_text( (string) $label );
			$group_n  = self::normalize_search_text( (string) $group );
			$index[]  = array(
				'slug'    => $slug,
				'label'   => $label,
				'group'   => $group,
				'url'     => add_query_arg( 'tab', $slug, $base_url ),
				'label_n' => $label_n,
				'group_n' => $group_n,
				'kw'      => $extra,
				'search'  => trim( $slug . ' ' . $label_n . ' ' . $group_n . ' ' . $extra ),
			);
		}

		// Sections inside tabs: jump straight to the heading, not to the top of the screen.
		$sections = self::get_section_map();
		foreach ( $sections as $slug => $items ) {
			if ( ! isset( $tabs[ $slug ] ) || ! empty( $tabs[ $slug ]['hidden'] ) ) {
				continue;
			}
			$tab_label = (string) ( $tabs[ $slug ]['label'] ?? $slug );
			$group     = $tab_label;
			foreach ( $items as $item ) {
				$needle  = (string) $item['needle'];
				$label_n = self::normalize_search_text( $needle );
				$group_n = self::normalize_search_text( $group );
				$extra   = self::normalize_search_text( (string) $item['kw'] );
				$index[] = array(
					'slug'    => $slug . '#' . sanitize_title( $needle ),
					'label'   => $needle,
					'group'   => $group,
					'url'     => add_query_arg( 'tab', $slug, $base_url ) . '#tsosk-go=' . rawurlencode( $needle ),
					'label_n' => $label_n,
					'group_n' => $group_n,
					'kw'      => $extra,
					'search'  => trim( $label_n . ' ' . $group_n . ' ' . $extra ),
					'section' => true,
				);
			}
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
		$query = self::normalize_search_text( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$tokens = preg_split( '/\s+/u', $query );
		if ( false === $tokens ) {
			$tokens = array();
		}
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
