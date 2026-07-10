<?php
/**
 * TSO Swiss Knife – Module: TSO Link Inspector promo.
 *
 * Promotes the standalone TSO Link Inspector plugin on WordPress.org.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Options
 */
class TSOSK_Mod_Options {

	/** WordPress.org plugin slug. */
	private const LINK_INSPECTOR_SLUG = 'tso-link-inspector';

	/** @var TSOSK_Mod_Options|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Whether TSO Link Inspector is installed and active.
	 *
	 * @return bool
	 */
	private function is_link_inspector_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::LINK_INSPECTOR_SLUG . '/tso-link-inspector.php' );
	}

	/**
	 * Admin URL to open TSO Link Inspector if installed.
	 *
	 * @return string
	 */
	private function link_inspector_admin_url(): string {
		return admin_url( 'tools.php?page=tso-link-inspector' );
	}

	/**
	 * Admin URL to search/install from the plugin directory.
	 *
	 * @return string
	 */
	private function link_inspector_install_url(): string {
		return admin_url(
			'plugin-install.php?s=' . rawurlencode( 'TSO' ) . '&tab=search&type=term'
		);
	}

	public function render(): void {
		$org_url    = 'https://wordpress.org/plugins/' . self::LINK_INSPECTOR_SLUG . '/';
		$install    = $this->link_inspector_install_url();
		$is_active  = $this->is_link_inspector_active();
		$tool_url   = $this->link_inspector_admin_url();

		$features = array(
			__( 'Scans posts, pages, custom post types, menus, comments and optional ACF fields for links.', 'tso-swiss-knife' ),
			__( 'Checks each URL via HTTP: broken links, redirects, insecure HTTP and connection errors.', 'tso-swiss-knife' ),
			__( 'Fix links inline from the dashboard — edit URL, smart suggestion, unlink or mark as OK.', 'tso-swiss-knife' ),
			__( 'Bulk actions, CSV export, daily scan and hourly background checks via WP-Cron.', 'tso-swiss-knife' ),
			__( 'Email alerts for hard-broken links and optional nofollow on broken links for SEO.', 'tso-swiss-knife' ),
			__( 'Catalan and Spanish translations included.', 'tso-swiss-knife' ),
		);
		?>
		<div class="tsosk-promo-link-inspector">
			<div class="tsosk-promo-hero">
				<div class="tsosk-promo-hero-icon" aria-hidden="true">
					<span class="dashicons dashicons-admin-links"></span>
				</div>
				<div class="tsosk-promo-hero-body">
					<p class="tsosk-promo-kicker"><?php esc_html_e( 'From Tu Soporte Online', 'tso-swiss-knife' ); ?></p>
					<h2 class="tsosk-promo-title"><?php esc_html_e( 'TSO Link Inspector', 'tso-swiss-knife' ); ?></h2>
					<p class="tsosk-promo-lead">
						<?php esc_html_e( 'Find and fix broken links across your whole site without opening each post in the editor. A dedicated plugin for link maintenance, SEO hygiene and peace of mind.', 'tso-swiss-knife' ); ?>
					</p>
					<?php if ( $is_active ) : ?>
						<span class="tsosk-badge tsosk-badge-ok tsosk-promo-status">
							<?php esc_html_e( 'Installed & active', 'tso-swiss-knife' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="tsosk-card tsosk-promo-features-card">
				<h3><?php esc_html_e( 'What it does', 'tso-swiss-knife' ); ?></h3>
				<ul class="tsosk-promo-feature-list">
					<?php foreach ( $features as $feature ) : ?>
					<li>
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php echo esc_html( $feature ); ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="tsosk-promo-actions">
				<?php if ( $is_active ) : ?>
					<a class="button button-primary button-hero" href="<?php echo esc_url( $tool_url ); ?>">
						<?php esc_html_e( 'Open TSO Link Inspector', 'tso-swiss-knife' ); ?>
					</a>
				<?php else : ?>
					<a class="button button-primary button-hero" href="<?php echo esc_url( $install ); ?>">
						<?php esc_html_e( 'Install from WordPress.org', 'tso-swiss-knife' ); ?>
					</a>
				<?php endif; ?>
				<a class="button button-secondary button-hero" href="<?php echo esc_url( $org_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View on WordPress.org', 'tso-swiss-knife' ); ?>
				</a>
			</div>

			<div class="tsosk-notice tsosk-notice-info tsosk-promo-tip">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<p>
					<strong><?php esc_html_e( 'Quick tip:', 'tso-swiss-knife' ); ?></strong>
					<?php esc_html_e( 'Go to Plugins → Add New, search for “TSO” and TSO Link Inspector appears in the results — free on WordPress.org.', 'tso-swiss-knife' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
