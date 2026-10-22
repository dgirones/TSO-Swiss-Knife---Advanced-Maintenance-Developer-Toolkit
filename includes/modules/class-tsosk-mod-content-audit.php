<?php
/**
 * TSO Swiss Knife – Module: Content Audit.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Content_Audit
 */
class TSOSK_Mod_Content_Audit {

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Content_Audit|null
	 */
	private static $instance = null;

	/**
	 * Rows shown per card.
	 */
	private const LIST_LIMIT = 30;

	/**
	 * Duplicate-title rows list at most this many post IDs each.
	 */
	private const IDS_PER_TITLE = 20;

	/**
	 * Name part of a shortcode tag.
	 */
	private const SHORTCODE_NAME = '[a-zA-Z][a-zA-Z0-9_-]*';

	/**
	 * Real totals for cards whose list is capped (so "30" is never shown as if it were everything).
	 *
	 * @var array<string,int>
	 */
	private $issue_totals = array();

	/**
	 * Per-request cache of the posts that contain square brackets (shared by the inventory and the broken-shortcode scan).
	 *
	 * @var array<int, WP_Post>|null
	 */
	private $bracket_posts_cache = null;

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
		add_action( 'wp_ajax_tsosk_ca_remove_shortcode', array( $this, 'ajax_remove_shortcode' ) );
	}

	/**
	 * AJAX: remove one or all broken shortcodes from a post.
	 */
	public function ajax_remove_shortcode(): void {
		check_ajax_referer( 'tsosk_ca_nonce', 'nonce' );
		// Same rule as the rest of the toolkit (administrators); the per-post check below stays as a second guard.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		// Shortcode names are case-sensitive in WordPress: keep the case (sanitize_key would lowercase [MyCode]).
		$shortcode = isset( $_POST['shortcode'] ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['shortcode'] ) ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Post not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$content = (string) $post->post_content;
		$tags    = '' !== $shortcode ? array( $shortcode ) : $this->get_post_broken_shortcodes( $post );
		$new     = $content;
		$removed = 0;

		foreach ( $tags as $tag ) {
			$count = 0;
			// Same shape as the detection (never Markdown links or escaped [[tag]]). Only the tags go; the text between them stays.
			$new = preg_replace_callback(
				$this->build_removal_pattern( (string) $tag ),
				static function ( array $match ) use ( &$count ): string {
					// Count opening tags only: [tag]…[/tag] is one shortcode, not two.
					if ( '[/' !== substr( $match[0], 0, 2 ) ) {
						++$count;
					}
					return '';
				},
				$new
			);
			if ( ! is_string( $new ) ) {
				break; // PCRE failure (backtracking limit): never save a half-processed post.
			}
			$removed += $count;
		}

		if ( ! is_string( $new ) || $new === $content ) {
			wp_send_json_error( __( 'No shortcode removed. It may have already been deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 0 === $removed ) {
			$removed = 1; // Only orphan closing tags were removed.
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $new ), // wp_update_post() expects slashed data; unslashed content strips backslashes from block JSON attributes.
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TSOSK_Activity_Log::log(
			'content-audit',
			'delete',
			sprintf(
				/* translators: 1: shortcode tag, 2: post ID */
				__( 'Removed broken shortcode [%1$s] from post #%2$d.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$shortcode ? $shortcode : '*',
				$post_id
			)
		);

		wp_send_json_success(
			array(
				'removed' => $removed,
				'message' => sprintf(
					/* translators: %d: number of shortcodes removed */
					_n( '%d shortcode removed.', '%d shortcodes removed.', $removed, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					$removed
				),
			)
		);
	}

	/**
	 * Render content audit.
	 */
	public function render(): void {
		$nonce               = wp_create_nonce( 'tsosk_ca_nonce' );
		$empty_titles        = $this->get_posts_by_issue( 'empty_titles' );
		$missing_featured    = $this->get_posts_by_issue( 'missing_featured' );
		$old_pending         = $this->get_posts_by_issue( 'old_pending' );
		$broken_shortcodes   = $this->get_broken_shortcodes();
		$duplicate_titles    = $this->get_duplicate_titles();
		$shortcode_inventory = $this->get_shortcode_inventory();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Find hidden content problems: empty titles, missing featured images, old pending/private/draft content, duplicate titles and broken shortcodes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>
		<p class="description">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: link to the Slug Manager tab */
					__( 'Long slugs are reviewed — and can be fixed — in the %s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
					'<a href="' . esc_url( admin_url( 'tools.php?page=tso-swiss-knife&tab=slug-manager' ) ) . '">' . esc_html__( 'Slug Manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
		</p>

		<?php $this->render_post_table( __( 'Published Posts and Pages Without Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $empty_titles, $this->issue_totals['empty_titles'] ?? null ); ?>
		<?php $this->render_post_table( __( 'Published Posts Without Featured Image', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $missing_featured, $this->issue_totals['missing_featured'] ?? null ); ?>
		<?php $this->render_post_table( __( 'Old Pending, Private or Draft Content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $old_pending, $this->issue_totals['old_pending'] ?? null ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Duplicate titles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> (<?php echo esc_html( $this->format_count_label( count( $duplicate_titles ), $this->issue_totals['duplicate_titles'] ?? null ) ); ?>)</h3>
			<p class="description"><?php esc_html_e( 'Posts or pages that share the exact same title. Duplicate titles can confuse editors and SEO tools.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( empty( $duplicate_titles ) ) : ?>
				<p><?php esc_html_e( 'No duplicate titles found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Count', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Post IDs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $duplicate_titles as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['title'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
									<td class="tsosk-code">
										<?php
										$links = array();
										foreach ( $row['ids'] as $post_id ) {
											$edit    = get_edit_post_link( $post_id );
											$links[] = $edit
												? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $post_id ) . '</a>'
												: esc_html( (string) $post_id );
										}
										echo wp_kses_post( implode( ', ', $links ) );
										if ( ! empty( $row['more'] ) ) {
											echo ' ' . esc_html( sprintf( /* translators: %d: number of post IDs not listed */ __( '+%d more', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), (int) $row['more'] ) );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Shortcode inventory', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Shortcodes found in the latest 100 posts/pages whose content contains brackets. Count = number of posts using each tag.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php if ( empty( $shortcode_inventory ) ) : ?>
				<p><?php esc_html_e( 'No shortcodes detected in the sampled content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
					<table class="widefat tsosk-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shortcode', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Posts', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Registered', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $shortcode_inventory as $tag => $count ) : ?>
								<tr>
									<td class="tsosk-code">[<?php echo esc_html( $tag ); ?>]</td>
									<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
									<td>
										<?php if ( shortcode_exists( $tag ) ) : ?>
											<span class="tsosk-badge tsosk-badge-ok"><?php esc_html_e( 'Yes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php else : ?>
											<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'No', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Broken Shortcodes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> (<?php echo esc_html( $this->format_count_label( count( $broken_shortcodes ), $this->issue_totals['broken_shortcodes'] ?? null ) ); ?>)</h3>
			<div class="tsosk-notice tsosk-notice-info">
				<strong><?php esc_html_e( 'What are broken shortcodes?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong><br>
				<?php esc_html_e( 'A broken shortcode is a [tag] found in post or page content whose plugin or theme is no longer active or installed. WordPress will not convert it into HTML — instead, the raw [shortcode] text appears on your page, which looks unprofessional and may confuse visitors.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<br><br>
				<?php esc_html_e( 'Common causes: a plugin was deactivated or uninstalled without cleaning up its shortcodes from content. To fix: re-install the plugin, replace the shortcode with equivalent HTML, or remove it entirely from the post.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
				<br><br>
				<em><?php esc_html_e( 'Note: scans up to 100 recent posts/pages whose content contains a [ character. Numeric-only tags like [5196] are ignored. Shortcodes from MU-plugins or late-registered plugins may still appear as false positives.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></em>
			</div>
			<p class="description">
				<?php esc_html_e( 'Each row is one post or page. If it contains several broken shortcodes, you can remove one tag at a time or all broken tags in that post.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
			</p>
			<?php if ( empty( $broken_shortcodes ) ) : ?>
				<p><?php esc_html_e( 'No broken shortcodes detected in the sampled content.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table" id="tsosk-ca-shortcodes-table">
					<thead><tr>
						<th><?php esc_html_e( 'Post', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Shortcodes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $broken_shortcodes as $item ) : ?>
						<?php $sc_count = count( $item['shortcodes'] ); ?>
						<tr id="tsosk-ca-row-<?php echo esc_attr( (string) $item['id'] ); ?>">
							<td><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== get_the_title( $item['id'] ) ? get_the_title( $item['id'] ) : '#' . $item['id'] ); ?></a></td>
							<td class="tsosk-code"><?php echo esc_html( implode( ', ', $item['shortcodes'] ) ); ?></td>
							<td class="tsosk-actions">
								<?php foreach ( $item['shortcodes'] as $sc ) : ?>
								<button type="button" class="button button-small tsosk-ca-remove-sc"
										data-post-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
										data-shortcode="<?php echo esc_attr( $sc ); ?>"
										data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php
									printf(
										/* translators: %s: shortcode tag */
										esc_html__( 'Remove [%s]', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
										esc_html( $sc )
									);
									?>
								</button>
								<?php endforeach; ?>
								<?php if ( $sc_count > 1 ) : ?>
								<button type="button" class="button button-small tsosk-ca-remove-all-sc"
										data-post-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
										data-nonce="<?php echo esc_attr( $nonce ); ?>">
									<?php esc_html_e( 'Remove all in this post', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
								</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<span class="tsosk-ajax-msg" id="tsosk-ca-shortcodes-msg"></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get posts for a content issue (at most LIST_LIMIT rows; the real total goes to $issue_totals).
	 *
	 * @param string $issue Issue.
	 * @return array<int,WP_Post>
	 */
	private function get_posts_by_issue( string $issue ): array {
		if ( 'empty_titles' === $issue ) {
			return $this->get_empty_title_posts();
		}

		$args = array(
			'post_type'           => array( 'post', 'page' ),
			'posts_per_page'      => self::LIST_LIMIT,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
		);

		if ( 'missing_featured' === $issue ) {
			$args['post_type'] = 'post';
			// Ask the database for posts with no thumbnail instead of filtering only the newest ones.
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Targeted audit query, small result.
				'relation' => 'OR',
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_thumbnail_id',
					'value'   => '0',
					'compare' => '=',
				),
			);
		} elseif ( 'old_pending' === $issue ) {
			$args['post_status'] = array( 'pending', 'private', 'draft' );
			$args['date_query']  = array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
					'column' => 'post_modified_gmt',
				),
			);
			// Most neglected first.
			$args['orderby'] = 'modified';
			$args['order']   = 'ASC';
		}

		$query                        = new WP_Query( $args );
		$this->issue_totals[ $issue ] = (int) $query->found_posts;

		return array_values( $query->posts );
	}

	/**
	 * Published posts/pages with an empty title (targeted query, not a recent sample).
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_empty_title_posts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ('post','page')
				AND post_status = 'publish'
				AND TRIM(post_title) = ''
				ORDER BY post_modified_gmt DESC
				LIMIT %d",
				self::LIST_LIMIT
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->issue_totals['empty_titles'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type IN ('post','page')
			AND post_status = 'publish'
			AND TRIM(post_title) = ''"
		);

		if ( ! is_array( $ids ) || array() === $ids ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'post__in'       => array_map( 'absint', $ids ),
				'posts_per_page' => count( $ids ),
				'orderby'        => 'post__in',
				'no_found_rows'  => true,
			)
		);
		return is_array( $posts ) ? array_values( $posts ) : array();
	}

	/**
	 * "30" or "30 of 412": a capped list must not look like the full count.
	 *
	 * @param int      $shown Rows listed.
	 * @param int|null $total Real total when known.
	 */
	private function format_count_label( int $shown, ?int $total ): string {
		if ( null !== $total && $total > $shown ) {
			return sprintf(
				/* translators: 1: rows shown, 2: real total */
				__( '%1$s of %2$s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				number_format_i18n( $shown ),
				number_format_i18n( $total )
			);
		}
		return number_format_i18n( $shown );
	}

	/**
	 * Find sampled posts containing shortcodes that are not registered.
	 *
	 * @return array<int,array{id:int,shortcodes:array<int,string>}>
	 */
	private function get_broken_shortcodes(): array {
		global $shortcode_tags;
		$registered = array_fill_keys( array_keys( (array) $shortcode_tags ), true );
		$posts      = $this->get_posts_with_bracket_content();
		$out        = array();
		foreach ( $posts as $post ) {
			$unknown = $this->get_post_broken_shortcodes( $post, $registered );
			if ( $unknown ) {
				$out[] = array(
					'id'         => $post->ID,
					'shortcodes' => $unknown,
				);
			}
		}
		$this->issue_totals['broken_shortcodes'] = count( $out );
		return array_slice( $out, 0, self::LIST_LIMIT );
	}

	/**
	 * Titles used by more than one post or page.
	 *
	 * The match is exact (BINARY): "Café" and "Cafe" are different titles, as the screen says. The ID list is
	 * cut with SUBSTRING_INDEX so it never depends on group_concat_max_len (1024 by default on MySQL), which
	 * used to truncate it mid-number on titles with many duplicates.
	 *
	 * @return array<int, array{title:string,count:int,ids:int[],more:int}>
	 */
	private function get_duplicate_titles(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT MIN(post_title) AS post_title, COUNT(*) AS cnt,
					SUBSTRING_INDEX(GROUP_CONCAT(ID ORDER BY ID SEPARATOR ','), ',', %d) AS ids
				FROM {$wpdb->posts}
				WHERE post_type IN ('post','page')
				AND post_status IN ('publish','future','draft','private','pending')
				AND TRIM(post_title) != ''
				GROUP BY BINARY post_title
				HAVING cnt > 1
				ORDER BY cnt DESC, post_title ASC
				LIMIT %d",
				self::IDS_PER_TITLE,
				self::LIST_LIMIT + 1
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		if ( count( $rows ) > self::LIST_LIMIT ) {
			// More groups than we list: count them for the "30 of N" heading.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->issue_totals['duplicate_titles'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT 1 FROM {$wpdb->posts}
					WHERE post_type IN ('post','page')
					AND post_status IN ('publish','future','draft','private','pending')
					AND TRIM(post_title) != ''
					GROUP BY BINARY post_title
					HAVING COUNT(*) > 1
				) AS duplicate_groups"
			);
			$rows = array_slice( $rows, 0, self::LIST_LIMIT );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$ids_raw = isset( $row['ids'] ) ? explode( ',', (string) $row['ids'] ) : array();
			$ids     = array_values( array_filter( array_map( 'absint', $ids_raw ) ) );
			if ( empty( $ids ) ) {
				continue;
			}
			$count = absint( $row['cnt'] ?? count( $ids ) );
			$out[] = array(
				'title' => (string) ( $row['post_title'] ?? '' ),
				'count' => $count,
				'ids'   => $ids,
				'more'  => max( 0, $count - count( $ids ) ),
			);
		}

		return $out;
	}

	/**
	 * Shortcode usage counts in sampled content.
	 *
	 * @return array<string, int>
	 */
	private function get_shortcode_inventory(): array {
		$posts  = $this->get_posts_with_bracket_content();
		$counts = array();

		foreach ( $posts as $post ) {
			// Same shape as the broken-shortcode scan, so Markdown links, plain "[text]" and escaped [[tag]] are not listed as shortcodes.
			if ( ! preg_match_all( $this->shortcode_detect_pattern(), $post->post_content, $matches ) ) {
				continue;
			}
			// Case-sensitive, as in WordPress: sanitize_key() would lowercase [MyCode] and report it as not registered.
			foreach ( array_unique( $matches[1] ) as $shortcode ) {
				$shortcode = (string) $shortcode;
				if ( '' === $shortcode ) {
					continue;
				}
				if ( ! isset( $counts[ $shortcode ] ) ) {
					$counts[ $shortcode ] = 0;
				}
				++$counts[ $shortcode ];
			}
		}

		arsort( $counts, SORT_NUMERIC );

		return array_slice( $counts, 0, 40, true );
	}

	/**
	 * Posts whose content may contain shortcodes (search post_content, not WP search index).
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_posts_with_bracket_content(): array {
		if ( null === $this->bracket_posts_cache ) {
			$this->bracket_posts_cache = $this->query_posts_with_bracket_content();
		}
		return $this->bracket_posts_cache;
	}

	/**
	 * Queries the posts whose content contains square brackets (uncached).
	 *
	 * @return array<int, WP_Post>
	 */
	private function query_posts_with_bracket_content(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ('post','page')
			AND post_status IN ('publish','future','draft','private','pending')
			AND post_content LIKE '%[%'
			ORDER BY post_modified_gmt DESC
			LIMIT 100"
		);

		if ( empty( $ids ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'future', 'draft', 'private', 'pending' ),
				'post__in'       => array_map( 'absint', $ids ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'orderby'        => 'post__in',
			)
		);
	}

	/**
	 * Get post broken shortcodes.
	 *
	 * @param WP_Post             $post       Post object.
	 * @param array<string, true> $registered Registered shortcode map.
	 * @return string[]
	 */
	private function get_post_broken_shortcodes( WP_Post $post, array $registered = array() ): array {
		if ( empty( $registered ) ) {
			global $shortcode_tags;
			$registered = array_fill_keys( array_keys( (array) $shortcode_tags ), true );
		}
		if ( ! preg_match_all( $this->shortcode_detect_pattern(), $post->post_content, $matches ) ) {
			return array();
		}
		$unknown = array();
		foreach ( array_unique( $matches[1] ) as $shortcode ) {
			$shortcode = (string) $shortcode; // Case-sensitive, as in WordPress.
			if ( '' === $shortcode || ctype_digit( $shortcode ) ) {
				continue;
			}
			if ( ! isset( $registered[ $shortcode ] ) ) {
				$unknown[] = $shortcode;
			}
		}
		return $unknown;
	}

	/**
	 * Regex that finds the opening/self-closing tag of any shortcode (name in group 1).
	 */
	private function shortcode_detect_pattern(): string {
		return '/' . $this->shortcode_open_pattern( self::SHORTCODE_NAME ) . '/';
	}

	/**
	 * Regex that removes one shortcode's opening/self-closing and closing tags (never its content).
	 *
	 * @param string $tag Shortcode name (case-sensitive).
	 */
	private function build_removal_pattern( string $tag ): string {
		$name = preg_quote( $tag, '/' );
		return '/' . $this->shortcode_open_pattern( $name, false ) . '|(?<!\[)\[\/' . $name . '\s*\](?!\])/';
	}

	/**
	 * Shape of a real shortcode tag: [name], [name attr="x"] or [name /].
	 *
	 * Not matched, on purpose: "[text](link)" (Markdown), a bracket with no closing "]", and the escaped
	 * form [[tag]] (WordPress prints it literally, it is not a shortcode).
	 *
	 * @param string $name    Name pattern (already a valid regex fragment).
	 * @param bool   $capture Whether the name is a capture group.
	 */
	private function shortcode_open_pattern( string $name, bool $capture = true ): string {
		$group = $capture ? '(' . $name . ')' : '(?:' . $name . ')';
		return '(?<!\[)\[' . $group . '(?=[\s\/\]])[^\[\]]*\](?![\(\]])';
	}

	/**
	 * Render post table.
	 *
	 * @param string   $title Title.
	 * @param array    $posts Posts.
	 * @param int|null $total Real total when the list is capped.
	 */
	private function render_post_table( string $title, array $posts, ?int $total = null ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?> (<?php echo esc_html( $this->format_count_label( count( $posts ), $total ) ); ?>)</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p><?php esc_html_e( 'No items found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<?php else : ?>
				<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table">
					<thead><tr><th><?php esc_html_e( 'ID', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Status', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th><th><?php esc_html_e( 'Modified', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $posts as $post ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $post->ID ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></a></td>
							<td><?php echo esc_html( $post->post_status ); ?></td>
							<td class="tsosk-code"><?php echo esc_html( $post->post_name ); ?></td>
							<td><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
