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

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Hooks|null
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
	private function __construct() {
		add_action( 'admin_post_tsosk_hooks_export', array( $this, 'download_hooks' ) );
	}

	/**
	 * Neutralise spreadsheet formulas in a CSV cell.
	 *
	 * @param mixed $value Cell value.
	 */
	private function csv_safe_cell( $value ): string {
		$text = (string) $value;
		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $text;
		}
		return $text;
	}

	/**
	 * Hook names matching a search in the hook name or in any callback name.
	 *
	 * @param string $search Search text (empty = all hooks).
	 * @return string[]
	 */
	private function find_tags( string $search ): array {
		global $wp_filter;

		$all_tags = array_keys( (array) $wp_filter );
		if ( '' !== $search ) {
			$matched = array();
			foreach ( $all_tags as $tag ) {
				$tag = (string) $tag;
				if ( false !== stripos( $tag, $search ) ) {
					$matched[] = $tag;
					continue;
				}
				if ( ! ( $wp_filter[ $tag ] instanceof \WP_Hook ) ) {
					continue;
				}
				foreach ( $this->get_callbacks( $wp_filter[ $tag ], false ) as $cb ) {
					if ( false !== stripos( $cb['function'], $search ) ) {
						$matched[] = $tag;
						break;
					}
				}
			}
			$all_tags = $matched;
		}
		$all_tags = array_map( 'strval', $all_tags );
		sort( $all_tags );
		return $all_tags;
	}

	/**
	 * CSV download of the hooks registered in this request.
	 */
	public function download_hooks(): void {
		global $wp_filter;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		check_admin_referer( 'tsosk_hooks_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$search = isset( $_GET['tsosk_hook_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_hook_search'] ) ) : '';
		$tags   = $this->find_tags( $search );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tsosk-hooks.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV stream to the browser, not a plugin file write.
		if ( false === $out ) {
			exit;
		}
		echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel (a fixed literal, nothing to escape).
		fputcsv( $out, array( 'hook', 'priority', 'callback', 'accepted_args', 'source' ), ',', '"', '' );
		foreach ( $tags as $tag ) {
			if ( ! isset( $wp_filter[ $tag ] ) || ! ( $wp_filter[ $tag ] instanceof \WP_Hook ) ) {
				continue;
			}
			foreach ( $this->get_callbacks( $wp_filter[ $tag ], true ) as $cb ) {
				fputcsv(
					$out,
					array(
						$this->csv_safe_cell( $tag ),
						$cb['priority'],
						$this->csv_safe_cell( $cb['function'] ),
						$cb['accepted_args'],
						$this->csv_safe_cell( $cb['source'] ),
					),
					',',
					'"',
					''
				);
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the CSV stream opened above.
		exit;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		global $wp_filter;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$search = isset( $_GET['tsosk_hook_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsosk_hook_search'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['tsosk_hooks_page'] ) ? max( 1, absint( wp_unslash( $_GET['tsosk_hooks_page'] ) ) ) : 1;

		// Collect & filter hook names (a search also matches callback names).
		$all_tags = $this->find_tags( $search );

		$total       = count( $all_tags );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;
		$page_tags   = array_slice( $all_tags, $offset, self::PER_PAGE );

		$base_url = add_query_arg(
			array(
				'page' => 'tso-swiss-knife',
				'tab'  => 'hooks',
			),
			admin_url( 'tools.php' )
		);
		if ( $search ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Matches the encoding used by the target URL.
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
					placeholder="<?php esc_attr_e( 'Filter hook or callback name…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>"
					class="tsosk-hooks-search-input" id="tsosk-hooks-search">
			<?php submit_button( __( 'Filter', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 'secondary', '', false ); ?>
			<a class="button" href="
			<?php
			echo esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'            => 'tsosk_hooks_export',
							'tsosk_hook_search' => rawurlencode( $search ),
						),
						admin_url( 'admin-post.php' )
					),
					'tsosk_hooks_export'
				)
			);
			?>
									"><?php esc_html_e( 'Export CSV', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></a>
			<?php if ( $search ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => 'tso-swiss-knife',
							'tab'  => 'hooks',
						),
						admin_url( 'tools.php' )
					)
				);
				?>
							" class="button">
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
						<th class="tsosk-hooks-col-source"><?php esc_html_e( 'Source', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $page_tags as $tag ) : ?>
						<?php
						if ( ! isset( $wp_filter[ $tag ] ) ) {
							continue;
						}
						$callbacks = $this->get_callbacks( $wp_filter[ $tag ], true );
						if ( empty( $callbacks ) ) :
							?>
						<tr class="tsosk-hooks-row tsosk-hooks-row-group-end" data-hook="<?php echo esc_attr( strtolower( $tag ) ); ?>">
							<td class="tsosk-code tsosk-hook-name"><?php echo esc_html( $tag ); ?></td>
							<td colspan="4" class="tsosk-hooks-empty">—</td>
						</tr>
							<?php
							continue;
						endif;
						foreach ( $callbacks as $index => $cb ) :
							$is_last_cb = ( count( $callbacks ) - 1 === $index );
							$row_class  = 'tsosk-hooks-row' . ( $is_last_cb ? ' tsosk-hooks-row-group-end' : '' );
							?>
						<tr class="<?php echo esc_attr( $row_class ); ?>" data-hook="<?php echo esc_attr( strtolower( $tag ) ); ?>">
							<?php if ( 0 === $index ) : ?>
							<td class="tsosk-code tsosk-hook-name" rowspan="<?php echo esc_attr( (string) count( $callbacks ) ); ?>"><?php echo esc_html( $tag ); ?></td>
							<?php endif; ?>
							<td class="tsosk-hooks-priority"><?php echo esc_html( (string) $cb['priority'] ); ?></td>
							<td class="tsosk-code tsosk-hooks-callback"><?php echo esc_html( $cb['function'] ); ?></td>
							<td class="tsosk-hooks-args"><?php echo esc_html( (string) $cb['accepted_args'] ); ?></td>
							<td class="tsosk-hooks-source"><?php echo esc_html( $cb['source'] ); ?></td>
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
	 * @param \WP_Hook $wp_hook        Hook object.
	 * @param bool     $with_source   Resolve where each callback is defined (slower).
	 * @return array<int,array{priority:int,function:string,accepted_args:int,source:string}>
	 */
	private function get_callbacks( \WP_Hook $wp_hook, bool $with_source = false ): array {
		$out = array();
		foreach ( $wp_hook->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$func = $cb['function'];
				if ( is_array( $func ) ) {
					$name = ( is_object( $func[0] ) ? get_class( $func[0] ) : (string) $func[0] ) . '::' . $func[1];
				} elseif ( $func instanceof Closure ) {
					$name = '{Closure}';
				} elseif ( is_object( $func ) ) {
					$name = get_class( $func ) . '::__invoke';
				} else {
					$name = is_scalar( $func ) ? (string) $func : '';
				}
				$out[] = array(
					'priority'      => (int) $priority,
					'function'      => $name,
					'accepted_args' => (int) $cb['accepted_args'],
					'source'        => $with_source ? $this->callback_source( $func ) : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Work out whether a callback comes from core, a plugin, a theme or an MU plugin.
	 *
	 * @param mixed $func Callback as stored in WP_Hook.
	 */
	private function callback_source( $func ): string {
		try {
			if ( is_array( $func ) && isset( $func[0], $func[1] ) ) {
				$ref = new \ReflectionMethod( $func[0], (string) $func[1] );
			} elseif ( $func instanceof Closure ) {
				$ref = new \ReflectionFunction( $func );
			} elseif ( is_object( $func ) ) {
				$ref = new \ReflectionMethod( $func, '__invoke' );
			} elseif ( is_string( $func ) && false !== strpos( $func, '::' ) ) {
				$ref = new \ReflectionMethod( $func );
			} elseif ( is_string( $func ) || $func instanceof Closure ) {
				$ref = new \ReflectionFunction( $func );
			} else {
				return '';
			}
			$file = (string) $ref->getFileName();
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( '' === $file ) {
			return __( 'PHP / internal', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}

		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin' => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu'     => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'theme'  => wp_normalize_path( get_theme_root() ),
		);
		foreach ( $roots as $type => $root ) {
			$root = trailingslashit( $root );
			if ( 0 !== strpos( $file, $root ) ) {
				continue;
			}
			$parts = explode( '/', substr( $file, strlen( $root ) ) );
			$slug  = (string) $parts[0];
			if ( 'plugin' === $type ) {
				/* translators: %s: plugin folder or file name */
				return sprintf( __( 'Plugin: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $slug );
			}
			if ( 'mu' === $type ) {
				/* translators: %s: MU plugin file name */
				return sprintf( __( 'MU plugin: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $slug );
			}
			/* translators: %s: theme folder name */
			return sprintf( __( 'Theme: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $slug );
		}
		$abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $file, $abspath . 'wp-includes/' ) || 0 === strpos( $file, $abspath . 'wp-admin/' ) ) {
			return __( 'WordPress core', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		return __( 'Other', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
	}
}
