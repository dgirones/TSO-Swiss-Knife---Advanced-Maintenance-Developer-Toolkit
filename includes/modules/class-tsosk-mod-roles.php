<?php
/**
 * TSO Swiss Knife – Module: Roles & capabilities inspector.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Roles
 */
class TSOSK_Mod_Roles {

	/**
	 * Instance.
	 *
	 * @var TSOSK_Mod_Roles|null
	 */
	private static $instance = null;

	/**
	 * Capabilities that should not be granted via this UI.
	 *
	 * @return string[]
	 */
	public static function get_blocked_caps(): array {
		return array(
			'manage_options',
			'install_plugins',
			'activate_plugins',
			'delete_plugins',
			'edit_plugins',
			'update_plugins',
			'upload_plugins',
			'install_themes',
			'edit_themes',
			'delete_themes',
			'update_themes',
			'upload_themes',
			'update_core',
			'edit_files',
			'manage_network',
			'unfiltered_html',
			'edit_users',
			'delete_users',
			'create_users',
			'promote_users',
		);
	}

	/**
	 * High-risk capabilities (shown with warning badge).
	 *
	 * @return string[]
	 */
	public static function get_dangerous_caps(): array {
		return array_merge(
			self::get_blocked_caps(),
			array(
				'export',
				'import',
			)
		);
	}

	/**
	 * Built-in role templates (capability => true).
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function get_role_templates(): array {
		return array(
			'content_editor' => array(
				'read'                   => true,
				'edit_posts'             => true,
				'edit_pages'             => true,
				'edit_others_posts'      => true,
				'edit_others_pages'      => true,
				'edit_published_posts'   => true,
				'edit_published_pages'   => true,
				'edit_private_posts'     => true,
				'edit_private_pages'     => true,
				'read_private_posts'     => true,
				'read_private_pages'     => true,
				'publish_posts'          => true,
				'publish_pages'          => true,
				'delete_posts'           => true,
				'delete_pages'           => true,
				'delete_published_posts' => true,
				'delete_published_pages' => true,
				'delete_others_posts'    => true,
				'delete_others_pages'    => true,
				'upload_files'           => true,
				'moderate_comments'      => true,
				'manage_categories'      => true,
			),
			'media_manager'  => array(
				'read'         => true,
				'upload_files' => true,
				'edit_posts'   => true,
			),
			'read_only'      => array(
				'read' => true,
			),
		);
	}

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
		add_action( 'wp_ajax_tsosk_roles_caps', array( $this, 'ajax_caps' ) );
		add_action( 'wp_ajax_tsosk_roles_add_cap', array( $this, 'ajax_add_cap' ) );
		add_action( 'wp_ajax_tsosk_roles_remove_cap', array( $this, 'ajax_remove_cap' ) );
		add_action( 'wp_ajax_tsosk_roles_compare', array( $this, 'ajax_compare' ) );
		add_action( 'wp_ajax_tsosk_roles_clone', array( $this, 'ajax_clone' ) );
		add_action( 'wp_ajax_tsosk_roles_apply_template', array( $this, 'ajax_apply_template' ) );
	}

	/**
	 * Get roles.
	 *
	 * @return array<string, string> Role id => label.
	 */
	private function get_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$wp_roles = wp_roles();
		$out      = array();
		foreach ( $wp_roles->roles as $id => $role ) {
			$out[ $id ] = translate_user_role( $role['name'] );
		}
		return $out;
	}

	/**
	 * Get role caps.
	 *
	 * @param string $role_id Role slug.
	 * @return array<string, bool>|null
	 */
	private function get_role_caps( string $role_id ): ?array {
		$role = get_role( $role_id );
		if ( ! $role ) {
			return null;
		}
		$caps = $role->capabilities;
		ksort( $caps );
		return $caps;
	}

	/**
	 * Whether a role capability entry is granted (handles bool/int/string storage).
	 *
	 * @param mixed $granted Stored capability value.
	 * @return bool
	 */
	private function cap_is_granted( $granted ): bool {
		return true === $granted || 1 === $granted || '1' === $granted;
	}

	/**
	 * Read a role slug from POST (role_slug avoids WAF blocks on "administrator").
	 *
	 * Call only from AJAX handlers after check_ajax_referer().
	 *
	 * @param string $key POST key (default role_slug).
	 * @return string
	 */
	private function get_post_role_slug( string $key = 'role_slug' ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling AJAX handler.
		if ( ! isset( $_POST[ $key ] ) ) {
			if ( 'role_slug' === $key && isset( $_POST['role'] ) ) {
				return sanitize_key( wp_unslash( $_POST['role'] ) );
			}
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Role slug is sanitized via sanitize_key() below.
		$raw = wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes plugin-owned export data, not code.
		$decoded = base64_decode( $raw, true );
		if ( false !== $decoded && is_string( $decoded ) && preg_match( '/^[a-z0-9_-]+$/', $decoded ) ) {
			return sanitize_key( $decoded );
		}

		return sanitize_key( $raw );
	}

	/**
	 * AJAX: caps.
	 */
	public function ajax_caps(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role_id = $this->get_post_role_slug();
		$caps    = $this->get_role_caps( $role_id );
		if ( null === $caps ) {
			wp_send_json_error( __( 'Role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$list = array();
		foreach ( $caps as $cap => $granted ) {
			if ( ! $this->cap_is_granted( $granted ) ) {
				continue;
			}
			$cap = sanitize_key( (string) $cap );
			if ( '' === $cap ) {
				continue;
			}
			$list[] = array(
				'cap'         => $cap,
				'description' => $this->cap_description( $cap ),
				'dangerous'   => in_array( $cap, self::get_dangerous_caps(), true ),
			);
		}

		$core       = in_array( $role_id, array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ), true );
		$counts     = count_users();
		$user_total = (int) ( $counts['avail_roles'][ $role_id ] ?? 0 );
		$summary    = ( $core ? __( 'Built-in WordPress role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) : __( 'Custom role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) )
			. ' · '
			. sprintf(
				/* translators: %d: number of users with the role */
				_n( '%d user has this role', '%d users have this role', $user_total, 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$user_total
			);

		wp_send_json_success(
			array(
				'role'       => $role_id,
				'summary'    => $summary,
				'caps'       => $list,
				'count'      => count( $list ),
				'read_only'  => 'administrator' === $role_id,
				'admin_role' => 'administrator' === $role_id,
			)
		);
	}

	/**
	 * Human-readable hint for a capability slug.
	 *
	 * @param string $cap Capability name.
	 * @return string
	 */
	private function cap_description( string $cap ): string {
		$map = array(
			'level_0'                 => __( 'Legacy user level 0 (subscriber). Deprecated — ignore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'level_1'                 => __( 'Legacy user level 1 (contributor). Deprecated — ignore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'level_2'                 => __( 'Legacy user level 2 (author). Deprecated — ignore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'level_7'                 => __( 'Legacy user level 7 (editor). Deprecated — ignore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'level_10'                => __( 'Legacy user level 10 (administrator). Deprecated — ignore.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'manage_options'          => __( 'Access Settings and configure the site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_posts'              => __( 'Create and edit own posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'publish_posts'           => __( 'Publish posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_others_posts'       => __( 'Edit posts written by other users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_posts'            => __( 'Delete own posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_others_posts'     => __( 'Delete posts by other users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_pages'              => __( 'Edit pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'publish_pages'           => __( 'Publish pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'upload_files'            => __( 'Upload media to the library.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'moderate_comments'       => __( 'Moderate comments.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'manage_categories'       => __( 'Manage post categories.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'read'                    => __( 'Read the dashboard (logged-in access).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'install_plugins'         => __( 'Install plugins — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'activate_plugins'        => __( 'Activate/deactivate plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_plugins'            => __( 'Edit plugin files — very high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_themes'             => __( 'Edit theme files — very high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'unfiltered_html'         => __( 'Post HTML without filtering — XSS risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_users'              => __( 'Edit other user accounts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_published_posts'    => __( 'Edit own posts that are already published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_private_posts'      => __( 'Edit private posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'read_private_posts'      => __( 'See private posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_published_posts'  => __( 'Delete own posts that are already published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_private_posts'    => __( 'Delete private posts.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_others_pages'       => __( 'Edit pages written by other users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_published_pages'    => __( 'Edit pages that are already published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_private_pages'      => __( 'Edit private pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'read_private_pages'      => __( 'See private pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_pages'            => __( 'Delete own pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_others_pages'     => __( 'Delete pages written by other users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_published_pages'  => __( 'Delete pages that are already published.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_private_pages'    => __( 'Delete private pages.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'manage_links'            => __( 'Manage the links list.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'list_users'              => __( 'See the list of users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'create_users'            => __( 'Create new users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_users'            => __( 'Delete users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'promote_users'           => __( 'Change the role of other users.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'remove_users'            => __( 'Remove users from the site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'switch_themes'           => __( 'Switch the active theme.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_theme_options'      => __( 'Change menus, widgets and Customizer options.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'customize'               => __( 'Open the Customizer.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_dashboard'          => __( 'Customize the dashboard.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update_plugins'          => __( 'Update plugins.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_plugins'          => __( 'Delete plugins — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'upload_plugins'          => __( 'Upload plugin files — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'install_themes'          => __( 'Install themes — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update_themes'           => __( 'Update themes.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'delete_themes'           => __( 'Delete themes — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update_core'             => __( 'Update WordPress itself.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'edit_files'              => __( 'Edit plugin and theme files — very high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'import'                  => __( 'Import content into the site.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'export'                  => __( 'Export all site content — can expose private data.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'unfiltered_upload'       => __( 'Upload files of any type — high risk.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'view_site_health_checks' => __( 'See the Site Health screen.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'manage_privacy_options'  => __( 'Manage privacy settings and requests.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			'update_languages'        => __( 'Update translations.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
		);
		if ( isset( $map[ $cap ] ) ) {
			return $map[ $cap ];
		}
		if ( 0 === strpos( $cap, 'level_' ) ) {
			return __( 'Legacy user level (deprecated). WordPress no longer uses levels for permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
		}
		if ( preg_match( '/^(?:edit|delete|publish|read)_(?:others_|published_|private_)(.+)$/', $cap, $m ) ) {
			return sprintf(
				/* translators: %s: post type slug */
				__( 'Advanced permission for content of type: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$m[1]
			);
		}
		if ( preg_match( '/^edit_(.+)$/', $cap, $m ) ) {
			return sprintf(
				/* translators: %s: post type slug */
				__( 'Edit content of type: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$m[1]
			);
		}
		if ( preg_match( '/^publish_(.+)$/', $cap, $m ) ) {
			return sprintf(
				/* translators: %s: post type slug */
				__( 'Publish content of type: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$m[1]
			);
		}
		if ( preg_match( '/^delete_(.+)$/', $cap, $m ) ) {
			return sprintf(
				/* translators: %s: post type slug */
				__( 'Delete content of type: %s', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$m[1]
			);
		}
		return '';
	}

	/**
	 * AJAX: compare.
	 */
	public function ajax_compare(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role_a = $this->get_post_role_slug( 'role_a_slug' );
		if ( '' === $role_a ) {
			$role_a = isset( $_POST['role_a'] ) ? sanitize_key( wp_unslash( $_POST['role_a'] ) ) : '';
		}
		$role_b = $this->get_post_role_slug( 'role_b_slug' );
		if ( '' === $role_b ) {
			$role_b = isset( $_POST['role_b'] ) ? sanitize_key( wp_unslash( $_POST['role_b'] ) ) : '';
		}
		$caps_a = $this->get_role_caps( $role_a );
		$caps_b = $this->get_role_caps( $role_b );
		if ( null === $caps_a || null === $caps_b ) {
			wp_send_json_error( __( 'Role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( $role_a === $role_b ) {
			wp_send_json_error( __( 'Choose two different roles.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$only_a = array();
		$only_b = array();
		$both   = array();
		foreach ( $caps_a as $cap => $granted ) {
			if ( ! $this->cap_is_granted( $granted ) ) {
				continue;
			}
			if ( ! empty( $caps_b[ $cap ] ) && $this->cap_is_granted( $caps_b[ $cap ] ) ) {
				$both[] = $cap;
			} else {
				$only_a[] = $cap;
			}
		}
		foreach ( $caps_b as $cap => $granted ) {
			if ( $this->cap_is_granted( $granted ) && ( empty( $caps_a[ $cap ] ) || ! $this->cap_is_granted( $caps_a[ $cap ] ) ) ) {
				$only_b[] = $cap;
			}
		}
		sort( $only_a );
		sort( $only_b );
		sort( $both );

		wp_send_json_success(
			array(
				'only_a' => $only_a,
				'only_b' => $only_b,
				'both'   => $both,
			)
		);
	}

	/**
	 * AJAX: clone.
	 */
	public function ajax_clone(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$source = $this->get_post_role_slug( 'source_slug' );
		if ( '' === $source ) {
			$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		}
		$new_id = isset( $_POST['new_role'] ) ? sanitize_key( wp_unslash( $_POST['new_role'] ) ) : '';
		$label  = isset( $_POST['new_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_label'] ) ) : '';

		if ( ! $source || ! $new_id || ! $label ) {
			wp_send_json_error( __( 'Source role, new slug and label are required.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'administrator' === $source ) {
			wp_send_json_error( __( 'Cloning the administrator role is not allowed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,39}$/', $new_id ) ) {
			wp_send_json_error( __( 'Use lowercase letters, numbers and underscores only (for example moderate_comments).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( get_role( $new_id ) ) {
			wp_send_json_error( __( 'A role with that slug already exists.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$caps = $this->get_role_caps( $source );
		if ( null === $caps ) {
			wp_send_json_error( __( 'Source role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$blocked   = self::get_blocked_caps();
		$safe_caps = array();
		foreach ( $caps as $cap => $granted ) {
			if ( $this->cap_is_granted( $granted ) && ! in_array( $cap, $blocked, true ) ) {
				$safe_caps[ $cap ] = true;
			}
		}

		add_role( $new_id, $label, $safe_caps );
		TSOSK_Activity_Log::log(
			'roles',
			'clone',
			sprintf(
				/* translators: 1: source role, 2: new role */
				__( 'Role cloned: %1$s → %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$source,
				$new_id
			)
		);
		wp_send_json_success(
			array(
				'message' => __( 'Role cloned. It is now available in the lists above.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				'role'    => $new_id,
				'label'   => $label,
			)
		);
	}

	/**
	 * AJAX: apply template.
	 */
	public function ajax_apply_template(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role_id   = $this->get_post_role_slug();
		$template  = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
		$templates = self::get_role_templates();

		if ( 'administrator' === $role_id ) {
			wp_send_json_error( __( 'The administrator role cannot be modified from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! isset( $templates[ $template ] ) ) {
			wp_send_json_error( __( 'Unknown template.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		$role = get_role( $role_id );
		if ( ! $role ) {
			wp_send_json_error( __( 'Role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$blocked = self::get_blocked_caps();
		$desired = array();
		foreach ( $templates[ $template ] as $cap => $grant ) {
			if ( ! $grant || in_array( $cap, $blocked, true ) ) {
				continue;
			}
			$desired[ $cap ] = true;
		}

		foreach ( $role->capabilities as $cap => $granted ) {
			if ( ! $this->cap_is_granted( $granted ) ) {
				continue;
			}
			// Blocked capabilities are never granted, but removing them is always safe.
			if ( ! isset( $desired[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}

		foreach ( $desired as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			}
		}

		TSOSK_Activity_Log::log(
			'roles',
			'update',
			sprintf(
				/* translators: 1: role, 2: template id */
				__( 'Template %2$s applied to role %1$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$role_id,
				$template
			)
		);
		wp_send_json_success( __( 'Template applied.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: add cap.
	 */
	public function ajax_add_cap(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role_id = $this->get_post_role_slug();
		$cap     = isset( $_POST['cap'] ) ? sanitize_key( wp_unslash( $_POST['cap'] ) ) : '';

		if ( ! $role_id || ! $cap ) {
			wp_send_json_error( __( 'Role and capability are required.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'administrator' === $role_id ) {
			wp_send_json_error( __( 'The administrator role cannot be modified from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( in_array( $cap, self::get_blocked_caps(), true ) ) {
			wp_send_json_error( __( 'This capability is blocked for safety.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $cap ) ) {
			wp_send_json_error( __( 'Use lowercase letters, numbers and underscores only (for example moderate_comments).', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$role = get_role( $role_id );
		if ( ! $role ) {
			wp_send_json_error( __( 'Role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$role->add_cap( $cap );
		TSOSK_Activity_Log::log(
			'roles',
			'add',
			sprintf(
				/* translators: 1: role, 2: capability */
				__( 'Capability added to %1$s: %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$role_id,
				$cap
			),
			array(
				'role' => $role_id,
				'cap'  => $cap,
			)
		);
		wp_send_json_success( __( 'Capability added.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * AJAX: remove cap.
	 */
	public function ajax_remove_cap(): void {
		check_ajax_referer( 'tsosk_roles_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ), 403 );
		}

		$role_id = $this->get_post_role_slug();
		$cap     = isset( $_POST['cap'] ) ? sanitize_key( wp_unslash( $_POST['cap'] ) ) : '';

		if ( ! $role_id || ! $cap ) {
			wp_send_json_error( __( 'Role and capability are required.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}
		if ( 'administrator' === $role_id ) {
			wp_send_json_error( __( 'The administrator role cannot be modified from here.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$role = get_role( $role_id );
		if ( ! $role ) {
			wp_send_json_error( __( 'Role not found.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
		}

		$role->remove_cap( $cap );
		TSOSK_Activity_Log::log(
			'roles',
			'delete',
			sprintf(
				/* translators: 1: role, 2: capability */
				__( 'Capability removed from %1$s: %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
				$role_id,
				$cap
			),
			array(
				'role' => $role_id,
				'cap'  => $cap,
			)
		);
		wp_send_json_success( __( 'Capability removed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ) );
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$nonce        = wp_create_nonce( 'tsosk_roles_nonce' );
		$roles        = $this->get_roles();
		$default_role = 'editor';
		foreach ( array_keys( $roles ) as $role_id ) {
			if ( 'administrator' !== $role_id ) {
				$default_role = $role_id;
				break;
			}
		}
		if ( ! isset( $roles[ $default_role ] ) ) {
			$default_role = (string) array_key_first( $roles );
		}
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'A role is a group of permissions (capabilities) that WordPress gives to a type of user. Use this module to see exactly what each role can do, adjust custom roles, and create new ones. Work from top to bottom: choose a role, review its capabilities, then change, clone or compare it.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>
		</p>

		<div class="tsosk-card tsosk-roles-guide">
			<h3><?php esc_html_e( 'What can I do here?', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<ul class="tsosk-roles-guide-list">
				<li><strong><?php esc_html_e( 'Inspect', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'find out why a user cannot publish, upload files or see a menu by checking the role capabilities.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Add or remove a capability', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'for example, let the Author role moderate comments by adding moderate_comments.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Apply a template', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'reset a role to a ready-made set of permissions, for example a read-only role for clients.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Clone', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'copy the Editor role into a new "Shop manager" role, then adjust it without touching the original.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Compare', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'see what Author has that Contributor does not before deciding which role to give someone.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Safety: the administrator role is read-only here, and capabilities that could take over the site (install plugins, edit users, change settings…) can never be granted from this screen. Changes apply immediately to every user with that role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '1. Choose the role to work with', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Everything in steps 2 to 4 acts on the role selected here. Step 5 compares any two roles independently.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-toolbar">
				<label for="tsosk-roles-select" class="screen-reader-text"><?php esc_html_e( 'Role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<select id="tsosk-roles-select">
					<?php foreach ( $roles as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $default_role ); ?>>
						<?php
						$option_label = $label . ' (' . $id . ')';
						if ( 'administrator' === $id ) {
							$option_label .= ' — ' . __( 'read-only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' );
						}
						echo esc_html( $option_label );
						?>
					</option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="tsosk-roles-load"><?php esc_html_e( 'Reload capabilities', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-badge tsosk-badge-info" id="tsosk-roles-count"></span>
			</div>
			<p class="description tsosk-roles-info" id="tsosk-roles-info" aria-live="polite"></p>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '2. Capabilities of this role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Every permission the selected role has. Remove one to take that ability away from all users with this role; the "High risk" badge marks permissions that can damage the site or expose data.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-table-wrap">
				<table class="widefat tsosk-table tsosk-roles-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'What it allows', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody id="tsosk-roles-tbody">
						<tr><td colspan="3"><?php esc_html_e( 'Loading…', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></td></tr>
					</tbody>
				</table>
			</div>
			<div class="tsosk-roles-editonly tsosk-roles-add" id="tsosk-roles-add-box">
				<h4><?php esc_html_e( 'Add a capability', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Type the exact capability name (lowercase with underscores). Examples: moderate_comments lets the role approve comments, upload_files lets it use the media library, edit_theme_options lets it edit menus and widgets.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
				<div class="tsosk-toolbar">
					<input type="text" id="tsosk-roles-new-cap" class="regular-text" placeholder="moderate_comments" autocomplete="off">
					<button type="button" class="button button-primary" id="tsosk-roles-add-cap"><?php esc_html_e( 'Add capability', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
					<span class="tsosk-ajax-msg" id="tsosk-roles-msg"></span>
				</div>
			</div>
			<p class="description tsosk-roles-adminonly" id="tsosk-roles-admin-note" hidden><?php esc_html_e( 'The administrator role is view-only here: capabilities cannot be added, removed or replaced.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
		</div>

		<div class="tsosk-card tsosk-roles-editonly">
			<h3><?php esc_html_e( '3. Replace all capabilities with a template', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Resets the selected role to a ready-made set. Every capability that is not in the template is removed, so use it to quickly turn a role into something simpler. Example: apply "Read only" to a custom "Client" role.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<ul class="tsosk-roles-guide-list">
				<li><strong><?php esc_html_e( 'Content editor', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'create, edit, publish and delete posts and pages from anyone, upload media, moderate comments and manage categories.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Media manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'upload and organise files and draft own posts, nothing else.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
				<li><strong><?php esc_html_e( 'Read only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></strong> — <?php esc_html_e( 'can log in and view the dashboard, cannot create or change anything.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></li>
			</ul>
			<div class="tsosk-toolbar">
				<label for="tsosk-roles-template" class="screen-reader-text"><?php esc_html_e( 'Template', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></label>
				<select id="tsosk-roles-template">
					<option value="content_editor"><?php esc_html_e( 'Content editor (posts + pages + media)', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="media_manager"><?php esc_html_e( 'Media manager', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
					<option value="read_only"><?php esc_html_e( 'Read only', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></option>
				</select>
				<button type="button" class="button" id="tsosk-roles-apply-template"><?php esc_html_e( 'Apply to selected role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-roles-template-msg"></span>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '4. Clone the selected role into a new one', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Creates a new role with the same capabilities as the selected one (except the blocked ones). Example: clone Editor as "shop_manager" with the name "Shop manager", then edit its capabilities in step 2. The administrator role cannot be cloned.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-toolbar">
				<input type="text" id="tsosk-roles-clone-slug" class="regular-text" placeholder="<?php esc_attr_e( 'new_role_slug', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>" autocomplete="off">
				<input type="text" id="tsosk-roles-clone-label" class="regular-text" placeholder="<?php esc_attr_e( 'Display name', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>" autocomplete="off">
				<button type="button" class="button" id="tsosk-roles-clone"><?php esc_html_e( 'Clone selected role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-roles-clone-msg"></span>
			</div>
		</div>

		<div class="tsosk-card">
			<h3><?php esc_html_e( '5. Compare two roles', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Shows which capabilities each role has that the other lacks, and which they share. Independent of the role chosen in step 1; nothing is changed.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<div class="tsosk-toolbar">
				<select id="tsosk-roles-compare-a" aria-label="<?php esc_attr_e( 'First role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					<?php foreach ( $roles as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span aria-hidden="true">↔</span>
				<select id="tsosk-roles-compare-b" aria-label="<?php esc_attr_e( 'Second role', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?>">
					<?php foreach ( $roles as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="tsosk-roles-compare"><?php esc_html_e( 'Compare', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></button>
				<span class="tsosk-ajax-msg" id="tsosk-roles-compare-msg"></span>
			</div>
			<pre id="tsosk-roles-compare-out" class="tsosk-code tsosk-roles-compare-out"></pre>
		</div>

		<details class="tsosk-card tsosk-roles-blocked">
			<summary><?php esc_html_e( 'Capabilities that can never be granted here', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></summary>
			<p class="description"><?php esc_html_e( 'They allow taking control of the site (installing code, changing settings, managing users). Give them only by assigning the Administrator role to trusted people.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ); ?></p>
			<p><code><?php echo esc_html( implode( ', ', self::get_blocked_caps() ) ); ?></code></p>
		</details>

		<input type="hidden" id="tsosk-roles-nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php
	}
}
