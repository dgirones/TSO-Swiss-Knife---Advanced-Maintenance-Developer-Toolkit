<?php
/**
 * TSO Swiss Knife – Module: TSO Options & Tables Cleaner promo.
 *
 * Promotes the standalone TSO Options & Tables Cleaner plugin on WordPress.org.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Database
 */
class TSOSK_Mod_Database {

	/** WordPress.org plugin slug. */
	private const CLEANER_SLUG = 'tso-options-tables-cleaner';

	/** @var TSOSK_Mod_Database|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Whether TSO Options & Tables Cleaner is installed and active.
	 *
	 * @return bool
	 */
	private function is_cleaner_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::CLEANER_SLUG . '/tso-options-tables-cleaner.php' );
	}

	/**
	 * Admin URL to open TSO Options & Tables Cleaner if installed.
	 *
	 * @return string
	 */
	private function cleaner_admin_url(): string {
		return admin_url( 'tools.php?page=tso-options-tables-cleaner' );
	}

	/**
	 * Admin URL to search/install from the plugin directory.
	 *
	 * @return string
	 */
	private function cleaner_install_url(): string {
		return admin_url(
			'plugin-install.php?s=' . rawurlencode( 'TSO' ) . '&tab=search&type=term'
		);
	}

	public function render(): void {
		$org_url   = 'https://wordpress.org/plugins/' . self::CLEANER_SLUG . '/';
		$install   = $this->cleaner_install_url();
		$is_active = $this->is_cleaner_active();
		$tool_url  = $this->cleaner_admin_url();

		$features = array(
			__( 'Clean expired transients, revisions, auto-drafts, and trashed posts or comments safely.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'Browse and delete orphan wp_options grouped by plugin, with autoload management.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'Detect and remove tables left behind by uninstalled plugins — with review-only mode when confidence is low.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'Full database SQL backup: export, download, restore, or delete backups from uploads.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'OPTIMIZE fragmented tables to reclaim space and improve query performance.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			__( 'Catalan, Spanish, and English interface included.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		?>
		<div class="tsosk-promo-options-cleaner">
			<div class="tsosk-promo-hero">
				<div class="tsosk-promo-hero-icon" aria-hidden="true">
					<span class="dashicons dashicons-database-view"></span>
				</div>
				<div class="tsosk-promo-hero-body">
					<p class="tsosk-promo-kicker"><?php esc_html_e( 'From Tu Soporte Online', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
					<h2 class="tsosk-promo-title"><?php esc_html_e( 'TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h2>
					<p class="tsosk-promo-lead">
						<?php esc_html_e( 'Keep your WordPress database lean and fast. A dedicated plugin to clean wp_options, orphan metadata, revisions, and leftover tables — with SQL backups before you delete anything.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
					</p>
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
						<?php esc_html_e( 'Open TSO Options & Tables Cleaner', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
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
					<?php esc_html_e( 'Go to Plugins → Add New, search for “TSO” and TSO Options & Tables Cleaner appears in the results — free on WordPress.org.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
