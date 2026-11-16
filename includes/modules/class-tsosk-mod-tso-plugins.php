<?php
/**
 * TSO Swiss Knife – Module: More TSO Plugins.
 *
 * Presents the standalone TSO plugins (Link Inspector, Options & Tables Cleaner, Image Master, Login Organizer and Stack Inspector) from WordPress.org.
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
				),
			),
			array(
				'css'      => 'tsosk-promo-image-master',
				'icon'     => 'dashicons-images-alt2',
				'slug'     => 'tso-image-master',
				'file'     => 'tso-image-master/tso-image-master.php',
				'tool_url' => admin_url( 'admin.php?page=tso-image-master' ),
				'title'    => __( 'TSO Image Master', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'lead'     => __( 'Convert uploads to WebP or AVIF, compress PDFs and repair your media library. A dedicated plugin that also finds orphan images, leftover sizes and broken image URLs.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'open'     => __( 'Open TSO Image Master', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'tip'      => __( 'Go to Plugins → Add New, search for “TSO” and TSO Image Master appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'features' => array(
					__( 'Convert images to WebP, AVIF, JPG or PNG on upload or in bulk, with an undo backup.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Compress PDFs in the Media Library with GhostScript or Imagick; the file URL never changes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Find orphan images and image sizes left behind by old plugins or themes, and delete them with a restorable backup.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Fix missing alt text, SEO file names and broken image URLs after a format change.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Repair broken attachments and keep a history of every optimization.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			),
			array(
				'css'      => 'tsosk-promo-login-organizer',
				'icon'     => 'dashicons-admin-users',
				'slug'     => 'tso-login-organizer',
				'file'     => 'tso-login-organizer/tso-login-organizer.php',
				'tool_url' => admin_url( 'tools.php?page=tso-login-organizer' ),
				'title'    => __( 'TSO Login Organizer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'lead'     => __( 'Give your site its own login, registration and password-reset pages at addresses you choose, so visitors never see wp-login.php. Optional modules add redirects by role, moderation, restrictions and two-factor authentication.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'open'     => __( 'Open TSO Login Organizer', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'tip'      => __( 'Go to Plugins → Add New, search for “TSO” and TSO Login Organizer appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'features' => array(
					__( 'Front-end login, register, lost password and reset pages with custom slugs and the [tso-login-organizer] shortcode.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Login with username, email or either, email-only registration and AJAX forms.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Optional modules, all off by default: redirects by role, moderation, content restrictions, brute-force lockouts, reCAPTCHA and TOTP two-factor authentication.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Front-end profiles with avatar editing and custom HTML notifications.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Import the settings of Theme My Login and keep its shortcode working.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				),
			),
			array(
				'css'      => 'tsosk-promo-stack-inspector',
				'icon'     => 'dashicons-search',
				'slug'     => 'tso-stack-inspector',
				'file'     => 'tso-stack-inspector/tso-stack-inspector.php',
				'tool_url' => admin_url( 'tools.php?page=tso-stack-inspector' ),
				'title'    => __( 'TSO Stack Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'lead'     => __( 'Know where a plugin is still used before you remove it. A dedicated plugin that scans your content for the shortcodes, blocks and meta of any plugin and gives a safe, review or risk verdict.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'open'     => __( 'Open TSO Stack Inspector', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'tip'      => __( 'Go to Plugins → Add New, search for “TSO” and TSO Stack Inspector appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'features' => array(
					__( 'Scan posts, pages, templates, widgets, menus and options for the shortcodes, blocks and meta prefixes of a plugin.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Get a safe, review or risk verdict before you uninstall.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Find orphan shortcodes and blocks that are still in content but no longer registered.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Audit inactive plugins and themes that still leave traces, and compare plugins before a migration.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Replace shortcodes or blocks with a dry-run preview, confirmation and undo backups.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					__( 'Export scan results as CSV or a printable report.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
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
			<div class="<?php echo esc_attr( (string) $plugin['css'] ); ?>" id="<?php echo esc_attr( (string) $plugin['css'] ); ?>">
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
