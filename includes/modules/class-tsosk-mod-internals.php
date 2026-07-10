<?php
/**
 * TSO Swiss Knife – Module: WordPress Internals.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Internals
 */
class TSOSK_Mod_Internals {

	/** Option key for disabled image size names. */
	private const OPTION_DISABLED_SIZES = 'tsosk_disabled_image_sizes';

	/** @var TSOSK_Mod_Internals|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return TSOSK_Mod_Internals
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_internals_image_sizes', array( $this, 'ajax_save_image_sizes' ) );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_disabled_image_sizes' ), 99 );
	}

	/**
	 * Prevent WordPress from generating disabled intermediate sizes.
	 *
	 * @param array<string,array> $sizes Registered sizes.
	 * @return array<string,array>
	 */
	public function filter_disabled_image_sizes( array $sizes ): array {
		$disabled = $this->get_disabled_sizes();
		foreach ( $disabled as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}

	/**
	 * @return string[]
	 */
	private function get_disabled_sizes(): array {
		$stored = get_option( self::OPTION_DISABLED_SIZES, array() );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'sanitize_key', $stored ) ) ) : array();
	}

	/**
	 * AJAX: save which image sizes are disabled.
	 */
	public function ajax_save_image_sizes(): void {
		check_ajax_referer( 'tsosk_internals_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		$disabled = array();
		if ( isset( $_POST['disabled'] ) && is_array( $_POST['disabled'] ) ) {
			foreach ( wp_unslash( $_POST['disabled'] ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$disabled[] = $name;
				}
			}
		}
		$disabled = array_values( array_unique( $disabled ) );
		update_option( self::OPTION_DISABLED_SIZES, $disabled, false );
		TSOSK_Activity_Log::log( 'internals', 'save', __( 'Image size settings saved.', 'tso-swiss-knife' ) );
		wp_send_json_success( __( 'Image size settings saved. New uploads will skip disabled sizes.', 'tso-swiss-knife' ) );
	}

	/**
	 * Render hidden WordPress internals.
	 */
	public function render(): void {
		global $wp, $wp_rewrite, $shortcode_tags;

		$nonce           = wp_create_nonce( 'tsosk_internals_nonce' );
		$disabled_sizes  = $this->get_disabled_sizes();
		$post_types  = get_post_types( array(), 'objects' );
		$post_status = get_post_stati( array(), 'objects' );
		$taxonomies  = get_taxonomies( array(), 'objects' );
		$image_sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		$roles       = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		$query_vars  = array_merge( (array) $wp->public_query_vars, (array) $wp->private_query_vars );
		$rewrite_tags = is_object( $wp_rewrite ) ? (array) $wp_rewrite->rewritecode : array();
		$shortcodes  = is_array( $shortcode_tags ) ? array_keys( $shortcode_tags ) : array();
		sort( $query_vars );
		sort( $rewrite_tags );
		sort( $shortcodes );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect hidden WordPress registries loaded in the current request: post types, statuses, taxonomies, roles, image sizes, query vars, rewrite tags and shortcodes.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-toolbar">
			<input type="text" id="tsosk-internals-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter internals...', 'tso-swiss-knife' ); ?>">
		</div>

		<?php $this->render_object_table( __( 'Registered Post Types', 'tso-swiss-knife' ), $post_types, array( 'name', 'label', 'public', 'show_ui', 'rewrite' ) ); ?>
		<?php $this->render_object_table( __( 'Registered Post Statuses', 'tso-swiss-knife' ), $post_status, array( 'name', 'label', 'public', 'internal', 'protected' ) ); ?>
		<?php $this->render_object_table( __( 'Registered Taxonomies', 'tso-swiss-knife' ), $taxonomies, array( 'name', 'label', 'public', 'show_ui', 'hierarchical' ) ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Registered Image Sizes', 'tso-swiss-knife' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Uncheck a size to stop WordPress from generating it on new uploads (saves disk space). Core sizes thumbnail, medium and large cannot be disabled here. Existing files are not deleted.', 'tso-swiss-knife' ); ?>
			</p>
			<div class="tsosk-notice tsosk-notice-warn" style="margin-bottom:12px;">
				<?php esc_html_e( 'Risks when disabling sizes: broken images in old content, missing srcset variants, layout shifts in themes/plugins that expect those sizes, and WooCommerce or page builders that rely on specific dimensions. Test on staging after changing sizes.', 'tso-swiss-knife' ); ?>
			</div>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead><tr>
					<th style="width:40px;"><?php esc_html_e( 'On', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Name', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Width', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Height', 'tso-swiss-knife' ); ?></th>
					<th><?php esc_html_e( 'Crop', 'tso-swiss-knife' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$core_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large' );
				foreach ( $image_sizes as $name => $size ) :
					$is_core    = in_array( $name, $core_sizes, true );
					$is_enabled = ! in_array( $name, $disabled_sizes, true );
				?>
					<tr class="tsosk-internals-row">
						<td>
							<input type="checkbox" class="tsosk-img-size-toggle"
							       data-name="<?php echo esc_attr( $name ); ?>"
							       <?php checked( $is_enabled ); ?>
							       <?php disabled( $is_core ); ?>>
						</td>
						<td><code><?php echo esc_html( $name ); ?></code><?php if ( $is_core ) : ?> <span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'core', 'tso-swiss-knife' ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( (string) ( $size['width'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $size['height'] ?? 0 ) ); ?></td>
						<td><?php echo ! empty( $size['crop'] ) ? esc_html__( 'Yes', 'tso-swiss-knife' ) : esc_html__( 'No', 'tso-swiss-knife' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="tsosk-internals-save-sizes"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save image size settings', 'tso-swiss-knife' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-internals-sizes-msg"></span>
			</p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Roles & Capabilities', 'tso-swiss-knife' ); ?></h3>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead><tr><th><?php esc_html_e( 'Role', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'Name', 'tso-swiss-knife' ); ?></th><th><?php esc_html_e( 'Capabilities', 'tso-swiss-knife' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $roles as $role_key => $role ) : ?>
					<tr class="tsosk-internals-row">
						<td><code><?php echo esc_html( $role_key ); ?></code></td>
						<td><?php echo esc_html( translate_user_role( $role['name'] ?? $role_key ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>

		<?php $this->render_list_card( __( 'Query Vars', 'tso-swiss-knife' ), $query_vars ); ?>
		<?php $this->render_list_card( __( 'Rewrite Tags', 'tso-swiss-knife' ), $rewrite_tags ); ?>
		<?php $this->render_list_card( __( 'Registered Shortcodes', 'tso-swiss-knife' ), $shortcodes ); ?>
		<?php
	}

	/**
	 * Render object registry table.
	 *
	 * @param string $title   Card title.
	 * @param array  $objects Objects keyed by name.
	 * @param array  $fields  Fields to show.
	 */
	private function render_object_table( string $title, array $objects, array $fields ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead>
					<tr>
						<?php foreach ( $fields as $field ) : ?>
							<th><?php echo esc_html( ucwords( str_replace( '_', ' ', $field ) ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $objects as $object ) : ?>
					<tr class="tsosk-internals-row">
						<?php foreach ( $fields as $field ) : ?>
							<td><?php echo esc_html( $this->format_value( $object->{$field} ?? '' ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a list card.
	 *
	 * @param string $title Card title.
	 * @param array  $items List values.
	 */
	private function render_list_card( string $title, array $items ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( count( $items ) ) ); ?>)</h3>
			<p class="tsosk-code tsosk-internals-row"><?php echo esc_html( implode( ', ', array_map( 'strval', $items ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Format mixed values safely.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'tso-swiss-knife' ) : __( 'No', 'tso-swiss-knife' );
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
