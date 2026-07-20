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

	/** @var TSOSK_Mod_Content_Audit|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_ca_remove_shortcode', array( $this, 'ajax_remove_shortcode' ) );
	}

	/**
	 * AJAX: remove one or all broken shortcodes from a post.
	 */
	public function ajax_remove_shortcode(): void {
		check_ajax_referer( 'tsosk_ca_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$post_id   = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$shortcode = isset( $_POST['shortcode'] ) ? sanitize_key( wp_unslash( $_POST['shortcode'] ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Post not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$content = (string) $post->post_content;
		$removed = 0;

		if ( '' !== $shortcode ) {
			$quoted  = preg_quote( $shortcode, '/' );
			$pattern = '/\[' . $quoted . '(?:[^\]]*)\](?:.*?\[\/' . $quoted . '\])?/s';
			$new     = preg_replace( $pattern, '', $content, -1, $removed );
		} else {
			$broken = $this->get_post_broken_shortcodes( $post );
			$new    = $content;
			foreach ( $broken as $tag ) {
				$quoted  = preg_quote( $tag, '/' );
				$pattern = '/\[' . $quoted . '(?:[^\]]*)\](?:.*?\[\/' . $quoted . '\])?/s';
				$new     = preg_replace( $pattern, '', $new, -1, $count );
				$removed += (int) $count;
			}
		}

		if ( 0 === $removed || ! is_string( $new ) ) {
			wp_send_json_error( __( 'No shortcode removed. It may have already been deleted.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new,
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
				$shortcode ?: '*',
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
		$nonce = wp_create_nonce( 'tsosk_ca_nonce' );
		$empty_titles = $this->get_posts_by_issue( 'empty_titles' );
		$missing_featured = $this->get_posts_by_issue( 'missing_featured' );
		$old_pending = $this->get_posts_by_issue( 'old_pending' );
		$long_slugs = $this->get_posts_by_issue( 'long_slugs' );
		$broken_shortcodes = $this->get_broken_shortcodes();
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Find hidden content problems: empty titles, missing featured images, old pending/private content, long slugs and broken shortcodes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<?php $this->render_post_table( __( 'Posts Without Title', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $empty_titles ); ?>
		<?php $this->render_post_table( __( 'Published Posts Without Featured Image', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $missing_featured ); ?>
		<?php $this->render_post_table( __( 'Old Pending or Private Content', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $old_pending ); ?>
		<?php $this->render_post_table( __( 'Long Slugs', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), $long_slugs ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Broken Shortcodes', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?> (<?php echo esc_html( number_format_i18n( count( $broken_shortcodes ) ) ); ?>)</h3>
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
							<td><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $item['id'] ) ?: '#' . $item['id'] ); ?></a></td>
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
				<span class="tsosk-ajax-msg" id="tsosk-ca-shortcodes-msg"></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get posts for a content issue.
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_posts_by_issue( string $issue ): array {
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => 30,
			'no_found_rows'  => true,
			'post_status'    => 'publish',
		);

		if ( 'empty_titles' === $issue ) {
			$args['s'] = '';
		} elseif ( 'missing_featured' === $issue ) {
			$args['post_type'] = 'post';
			$args['posts_per_page'] = 100;
		} elseif ( 'old_pending' === $issue ) {
			$args['post_status'] = array( 'pending', 'private', 'draft' );
			$args['date_query'] = array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
					'column' => 'post_modified_gmt',
				),
			);
		}

		$posts = get_posts( $args );
		if ( 'empty_titles' === $issue ) {
			$posts = array_filter(
				$posts,
				static function ( WP_Post $post ): bool {
					return '' === trim( $post->post_title );
				}
			);
		}
		if ( 'missing_featured' === $issue ) {
			$posts = array_filter(
				$posts,
				static function ( WP_Post $post ): bool {
					return ! has_post_thumbnail( $post );
				}
			);
		}
		if ( 'long_slugs' === $issue ) {
			$posts = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
					'posts_per_page' => 100,
					'no_found_rows'  => true,
				)
			);
			$posts = array_filter(
				$posts,
				static function ( WP_Post $post ): bool {
					return strlen( $post->post_name ) > 70;
				}
			);
		}

		return array_slice( array_values( $posts ), 0, 30 );
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
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Posts whose content may contain shortcodes (search post_content, not WP search index).
	 *
	 * @return array<int, WP_Post>
	 */
	private function get_posts_with_bracket_content(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ('post','page')
			AND post_status IN ('publish','draft','private','pending')
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
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'post__in'       => array_map( 'absint', $ids ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'orderby'        => 'post__in',
			)
		);
	}

	/**
	 * @param WP_Post              $post       Post object.
	 * @param array<string, true>  $registered Registered shortcode map.
	 * @return string[]
	 */
	private function get_post_broken_shortcodes( WP_Post $post, array $registered = array() ): array {
		if ( empty( $registered ) ) {
			global $shortcode_tags;
			$registered = array_fill_keys( array_keys( (array) $shortcode_tags ), true );
		}
		if ( ! preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_-]*)/', $post->post_content, $matches ) ) {
			return array();
		}
		$unknown = array();
		foreach ( array_unique( $matches[1] ) as $shortcode ) {
			$shortcode = sanitize_key( (string) $shortcode );
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
	 * Render post table.
	 *
	 * @param string $title Title.
	 * @param array  $posts Posts.
	 */
	private function render_post_table( string $title, array $posts ): void {
		?>
		<div class="tsosk-card">
			<h3><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( count( $posts ) ) ); ?>)</h3>
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
							<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) ); ?></a></td>
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
