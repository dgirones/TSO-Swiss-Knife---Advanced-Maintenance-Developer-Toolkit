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

	private function __construct() {}

	/**
	 * Render hidden WordPress internals.
	 */
	public function render(): void {
		global $wp, $wp_rewrite, $shortcode_tags;

		$post_types   = get_post_types( array(), 'objects' );
		$post_status  = get_post_stati( array(), 'objects' );
		$taxonomies   = get_taxonomies( array(), 'objects' );
		$roles        = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		$query_vars   = array_merge( (array) $wp->public_query_vars, (array) $wp->private_query_vars );
		$rewrite_tags = is_object( $wp_rewrite ) ? (array) $wp_rewrite->rewritecode : array();
		$shortcodes   = is_array( $shortcode_tags ) ? array_keys( $shortcode_tags ) : array();
		sort( $query_vars );
		sort( $rewrite_tags );
		sort( $shortcodes );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Inspect hidden WordPress registries loaded in the current request: post types, statuses, taxonomies, roles, query vars, rewrite tags and shortcodes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-toolbar">
			<input type="text" id="tsosk-internals-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter internals...', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
		</div>

		<?php $this->render_object_table( __( 'Registered Post Types', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $post_types, array( 'name', 'label', 'public', 'show_ui', 'rewrite' ) ); ?>
		<?php $this->render_object_table( __( 'Registered Post Statuses', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $post_status, array( 'name', 'label', 'public', 'internal', 'protected' ) ); ?>
		<?php $this->render_object_table( __( 'Registered Taxonomies', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $taxonomies, array( 'name', 'label', 'public', 'show_ui', 'hierarchical' ) ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Roles & Capabilities', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<div class="tsosk-table-wrap">
			<table class="widefat tsosk-table">
				<thead><tr><th><?php esc_html_e( 'Role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Capabilities', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th></tr></thead>
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

		<?php $this->render_list_card( __( 'Query Vars', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $query_vars ); ?>
		<?php $this->render_list_card( __( 'Rewrite Tags', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $rewrite_tags ); ?>
		<?php $this->render_list_card( __( 'Registered Shortcodes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $shortcodes ); ?>
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
			return $value ? __( 'Yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'No', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
