<?php
/**
 * TSO Swiss Knife – Module: More TSO Plugins.
 *
 * Presents the standalone TSO Link Inspector and TSO Options & Tables Cleaner plugins from WordPress.org.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Tso_Plugins
 */
class TSOSK_Mod_Tso_Plugins {

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Tso_Plugins|null
	 */
	private static $instance = null;

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
	private function __construct() {}

	/**
	 * Whether a plugin is installed and active.
	 *
	 * @param string $file Plugin main file relative to the plugins folder.
	 * @return bool
	 */
	private function is_active( string $file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			tsosk_require_wp_admin( 'includes/plugin.php' );
		}
		return is_plugin_active( $file );
	}

	/**
	 * Admin URL to search/install from the plugin directory.
	 *
	 * @return string
	 */
	private function install_url(): string {
		return admin_url( 'plugin-install.php?s=' . rawurlencode( 'TSO' ) . '&tab=search&type=term' );
	}

	/**
	 * Plugins presented on this tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_plugins_info(): array {
		return array(
			array(
				'css'      => 'tsosk-promo-link-inspector',
				'icon'     => 'dashicons-admin-links',
				'slug'     => 'tso-link-inspector',
				'file'     => 'tso-link-inspector/tso-link-inspector.php',
				'tool_url' => admin_url( 'tools.php?page=tso-link-inspector' ),
				'title'    => __( 'TSO Link Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'lead'     => __( 'Find and fix broken links across your whole site without opening each post in the editor. A dedicated plugin for link maintenance, SEO hygiene and peace of mind.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'open'     => __( 'Open TSO Link Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'tip'      => __( 'Go to Plugins → Add New, search for “TSO” and TSO Link Inspector appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'features' => array(
					__( 'Scans posts, pages, custom post types, menus, comments and optional ACF fields for links.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Checks each URL via HTTP: broken links, redirects, insecure HTTP and connection errors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Fix links inline from the dashboard — edit URL, smart suggestion, unlink or mark as OK.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Bulk actions, CSV export, daily scan and hourly background checks via WP-Cron.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Email alerts for hard-broken links and optional nofollow on broken links for SEO.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Catalan and Spanish translations included.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			),
			array(
				'css'      => 'tsosk-promo-options-cleaner',
				'icon'     => 'dashicons-database-view',
				'slug'     => 'tso-options-tables-cleaner',
				'file'     => 'tso-options-tables-cleaner/tso-options-tables-cleaner.php',
				'tool_url' => admin_url( 'tools.php?page=tso-options-tables-cleaner' ),
				'title'    => __( 'TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'lead'     => __( 'Keep your WordPress database lean and fast. A dedicated plugin to clean wp_options, orphan metadata, revisions, and leftover tables — with SQL backups before you delete anything.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'open'     => __( 'Open TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'tip'      => __( 'Go to Plugins → Add New, search for “TSO” and TSO Options & Tables Cleaner appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'features' => array(
					__( 'Clean expired transients, revisions, auto-drafts, and trashed posts or comments safely.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Browse and delete orphan wp_options grouped by plugin, with autoload management.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Detect and remove tables left behind by uninstalled plugins — with review-only mode when confidence is low.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Full database SQL backup: export, download, restore, or delete backups from uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'OPTIMIZE fragmented tables to reclaim space and improve query performance.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Catalan, Spanish, and English interface included.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			),
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$install = $this->install_url();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Free plugins from Tu Soporte Online that complement this toolkit.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<div class="tsosk-promo-more">
			<?php
			foreach ( $this->get_plugins_info() as $plugin ) :
				$org_url   = 'https://wordpress.org/plugins/' . $plugin['slug'] . '/';
				$is_active = $this->is_active( (string) $plugin['file'] );
				?>
			<div class="<?php echo esc_attr( (string) $plugin['css'] ); ?>">
				<div class="tsosk-promo-hero">
					<div class="tsosk-promo-hero-icon" aria-hidden="true">
						<span class="dashicons <?php echo esc_attr( (string) $plugin['icon'] ); ?>"></span>
					</div>
					<div class="tsosk-promo-hero-body">
						<p class="tsosk-promo-kicker"><?php esc_html_e( 'From Tu Soporte Online', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
						<h2 class="tsosk-promo-title"><?php echo esc_html( (string) $plugin['title'] ); ?></h2>
						<p class="tsosk-promo-lead"><?php echo esc_html( (string) $plugin['lead'] ); ?></p>
						<?php if ( $is_active ) : ?>
							<span class="tsosk-badge tsosk-badge-ok tsosk-promo-status">
								<?php esc_html_e( 'Installed & active', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="tsosk-card tsosk-promo-features-card">
					<h3><?php esc_html_e( 'What it does', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
					<ul class="tsosk-promo-feature-list">
						<?php foreach ( (array) $plugin['features'] as $feature ) : ?>
						<li>
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php echo esc_html( (string) $feature ); ?>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="tsosk-promo-actions">
					<?php if ( $is_active ) : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( (string) $plugin['tool_url'] ); ?>">
							<?php echo esc_html( (string) $plugin['open'] ); ?>
						</a>
					<?php else : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( $install ); ?>">
							<?php esc_html_e( 'Install from WordPress.org', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
						</a>
					<?php endif; ?>
					<a class="button button-secondary button-hero" href="<?php echo esc_url( $org_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View on WordPress.org', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</a>
				</div>

				<div class="tsosk-notice tsosk-notice-info tsosk-promo-tip">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<p>
						<strong><?php esc_html_e( 'Quick tip:', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong>
						<?php echo esc_html( (string) $plugin['tip'] ); ?>
					</p>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
