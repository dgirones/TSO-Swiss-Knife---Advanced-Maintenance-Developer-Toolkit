<?php
/**
 * TSO Swiss Knife – Module: Hooks Inspector.
 *
 * Safe paginated view of $wp_filter to avoid memory/timeout issues.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Hooks
 */
class TSOSK_Mod_Hooks {

	/** Maximum hooks to render per page to avoid fatal errors. */
	const PER_PAGE = 100;

	/** @var TSOSK_Mod_Hooks|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function render(): void {
		global $wp_filter;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search  = isset( $_GET['tsosk_hook_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_hook_search'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['tsosk_hooks_page'] ) ? max( 1, absint( wp_unslash( $_GET['tsosk_hooks_page'] ) ) ) : 1;

		// Collect & filter hook names only (no callback expansion yet).
		$all_tags = array_keys( (array) $wp_filter );
		if ( $search ) {
			$all_tags = array_values( array_filter( $all_tags, static function ( $tag ) use ( $search ) {
				return false !== stripos( $tag, $search );
			} ) );
		}
		sort( $all_tags );

		$total      = count( $all_tags );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;
		$page_tags   = array_slice( $all_tags, $offset, self::PER_PAGE );

		$base_url = add_query_arg( array( 'page' => 'tso-swiss-knife', 'tab' => 'hooks' ), admin_url( 'tools.php' ) );
		if ( $search ) {
			$base_url = add_query_arg( 'tsosk_hook_search', urlencode( $search ), $base_url );
		}
		?>
		<p class="tsosk-desc">
			<?php
			printf(
				/* translators: %d: number of registered hooks */
				esc_html__( '%d hooks are currently registered. This view is a snapshot at page load. Results are paginated to prevent server timeouts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				(int) $total
			);
			?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="tsosk-hooks-toolbar">
			<input type="hidden" name="page" value="tso-swiss-knife">
			<input type="hidden" name="tab"  value="hooks">
			<input type="text" name="tsosk_hook_search" value="<?php echo esc_attr( $search ); ?>"
			       placeholder="<?php esc_attr_e( 'Filter hook name…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
			       class="tsosk-hooks-search-input">
			<?php submit_button( __( 'Filter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'secondary', '', false ); ?>
			<?php if ( $search ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'tso-swiss-knife', 'tab' => 'hooks' ), admin_url( 'tools.php' ) ) ); ?>" class="button">
					<?php esc_html_e( 'Clear', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php if ( $total_pages > 1 ) : ?>
		<div class="tsosk-pagination tsosk-hooks-pagination">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'tsosk_hooks_page', $paged - 1, $base_url ) ); ?>">&#8592; <?php esc_html_e( 'Previous', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px;">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $paged,
					(int) $total_pages
				);
				?>
			</span>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'tsosk_hooks_page', $paged + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> &#8594;</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="tsosk-table-wrap tsosk-hooks-table-wrap">
			<table class="widefat tsosk-table tsosk-hooks-table" id="tsosk-hooks-table">
				<thead>
					<tr>
						<th class="tsosk-hooks-col-hook"><?php esc_html_e( 'Hook', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-hooks-col-priority"><?php esc_html_e( 'Priority', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-hooks-col-callback"><?php esc_html_e( 'Callback', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th class="tsosk-hooks-col-args"><?php esc_html_e( 'Args', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $page_tags as $tag ) : ?>
						<?php
						if ( ! isset( $wp_filter[ $tag ] ) ) {
							continue;
						}
						$callbacks = $this->get_callbacks( $wp_filter[ $tag ] );
						if ( empty( $callbacks ) ) :
							?>
						<tr class="tsosk-hooks-row tsosk-hooks-row-group-end">
							<td class="tsosk-code tsosk-hook-name"><?php echo esc_html( $tag ); ?></td>
							<td colspan="3" class="tsosk-hooks-empty">—</td>
						</tr>
							<?php
							continue;
						endif;
						foreach ( $callbacks as $index => $cb ) :
							$is_last_cb = ( $index === count( $callbacks ) - 1 );
							$row_class  = 'tsosk-hooks-row' . ( $is_last_cb ? ' tsosk-hooks-row-group-end' : '' );
							?>
						<tr class="<?php echo esc_attr( $row_class ); ?>">
							<?php if ( 0 === $index ) : ?>
							<td class="tsosk-code tsosk-hook-name" rowspan="<?php echo esc_attr( (string) count( $callbacks ) ); ?>"><?php echo esc_html( $tag ); ?></td>
							<?php endif; ?>
							<td class="tsosk-hooks-priority"><?php echo esc_html( (string) $cb['priority'] ); ?></td>
							<td class="tsosk-code tsosk-hooks-callback"><?php echo esc_html( $cb['function'] ); ?></td>
							<td class="tsosk-hooks-args"><?php echo esc_html( (string) $cb['accepted_args'] ); ?></td>
						</tr>
							<?php
						endforeach;
					endforeach;
					?>
				</tbody>
			</table>
		</div>
		<?php if ( $total_pages > 1 ) : ?>
		<div class="tsosk-pagination tsosk-hooks-pagination tsosk-hooks-pagination-bottom">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'tsosk_hooks_page', $paged - 1, $base_url ) ); ?>">&#8592; <?php esc_html_e( 'Previous', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<?php endif; ?>
			<span>
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					(int) $paged,
					(int) $total_pages
				);
				?>
			</span>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'tsosk_hooks_page', $paged + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> &#8594;</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Safely extract callback list from a WP_Hook object.
	 *
	 * @param \WP_Hook $wp_hook Hook object.
	 * @return array<int,array{priority:int,function:string,accepted_args:int}>
	 */
	private function get_callbacks( \WP_Hook $wp_hook ): array {
		$out = array();
		foreach ( $wp_hook->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$func = $cb['function'];
				if ( is_array( $func ) ) {
					$name = ( is_object( $func[0] ) ? get_class( $func[0] ) : (string) $func[0] ) . '::' . $func[1];
				} elseif ( $func instanceof Closure ) {
					$name = '{Closure}';
				} else {
					$name = (string) $func;
				}
				$out[] = array(
					'priority'      => (int) $priority,
					'function'      => $name,
					'accepted_args' => (int) $cb['accepted_args'],
				);
			}
		}
		return $out;
	}
}
