<?php
/**
 * TSO Swiss Knife – Module: Admin Menu Editor (simple).
 *
 * Hide, rename, reorder, and relocate WordPress admin menu items.
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Mod_Admin_Menu
 */
class TSOSK_Mod_Admin_Menu {

	/** Option key. */
	public const OPTION = 'tsosk_admin_menu_settings';

	/** Cached menu rows for AJAX saves (admin-ajax.php does not build $menu). */
	public const MANIFEST_OPTION = 'tsosk_admin_menu_manifest';

	/** Our tools page — cannot be hidden. */
	private const PROTECTED_SLUG = 'tso-swiss-knife';

	/** WordPress core top-level menu slugs that must never be removed. */
	private const CORE_TOP_SLUGS = array(
		'index.php',
		'upload.php',
		'edit.php',
		'edit.php?post_type=page',
		'edit-comments.php',
		'themes.php',
		'plugins.php',
		'users.php',
		'tools.php',
		'options-general.php',
	);

	/** @var TSOSK_Mod_Admin_Menu|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_tsosk_am_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_tsosk_am_reset', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Apply saved menu rules on every admin screen.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'apply_customizations' ), 99999 );
		add_action( 'admin_menu', array( $this, 'enforce_submenu_layout' ), PHP_INT_MAX );
		add_action( 'admin_menu', array( $this, 'guard_protected_tools_submenu' ), PHP_INT_MAX );
		add_action( 'admin_init', array( $this, 'enforce_submenu_layout' ), PHP_INT_MAX );
		add_action( 'admin_init', array( $this, 'guard_protected_tools_submenu' ), PHP_INT_MAX );
		// After load-{$pagenow} (late submenu registration) and before the sidebar is rendered.
		add_action( 'in_admin_header', array( $this, 'enforce_submenu_layout' ), PHP_INT_MAX );
		add_action( 'in_admin_header', array( $this, 'guard_protected_tools_submenu' ), PHP_INT_MAX );
		add_action( 'admin_head', array( $this, 'enforce_submenu_layout' ), PHP_INT_MAX );
		add_action( 'admin_head', array( $this, 'guard_protected_tools_submenu' ), PHP_INT_MAX );
	}

	/**
	 * @return array{hidden:array<int,string>,labels:array<string,string>,top_order:array<int,string>,sub_order:array<string,array<int,string>>,relocations:array<string,string>}
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = self::get_instance()->normalize_stored_submenu_settings( $stored );
		return wp_parse_args(
			$stored,
			array(
				'hidden'         => array(),
				'labels'         => array(),
				'top_order'      => array(),
				'sub_order'          => array(),
				'sub_order_ids'      => array(),
				'sub_order_live'     => array(),
				'sub_order_resolved' => array(),
				'relocations'        => array(),
				'nested_tops'    => array(),
			)
		);
	}

	/**
	 * Build a stable item id.
	 *
	 * @param string $slug   Menu slug.
	 * @param string $parent Parent slug for submenus, empty for top level.
	 * @return string
	 */
	public static function item_id( string $slug, string $parent = '' ): string {
		if ( '' !== $parent ) {
			return 's:' . $parent . '|' . $slug;
		}
		return 't:' . $slug;
	}

	/**
	 * Parse item id into slug + parent.
	 *
	 * @param string $id Item id.
	 * @return array{parent:string,slug:string}|null
	 */
	public static function parse_item_id( string $id ): ?array {
		if ( 0 === strpos( $id, 't:sep:' ) ) {
			$position = (int) substr( $id, 6 );
			return array(
				'parent'       => '',
				'slug'         => 'sep:' . $position,
				'is_separator' => true,
				'position'     => $position,
			);
		}
		if ( 0 === strpos( $id, 't:' ) ) {
			$slug = substr( $id, 2 );
			return array(
				'parent'       => '',
				'slug'         => $slug,
				'is_separator' => '' === $slug || 0 === strpos( $slug, 'separator' ),
			);
		}
		if ( 0 === strpos( $id, 's:' ) ) {
			$rest = substr( $id, 2 );
			$pos  = strpos( $rest, '|' );
			if ( false === $pos ) {
				return null;
			}
			return array(
				'parent' => substr( $rest, 0, $pos ),
				'slug'   => substr( $rest, $pos + 1 ),
			);
		}
		return null;
	}

	/**
	 * Strip HTML, badges, and moderation/update counts from a menu title.
	 *
	 * @param string $title Menu title HTML.
	 * @param string $slug  Menu slug (optional, for fallbacks).
	 * @return string
	 */
	public static function plain_menu_title( string $title, string $slug = '' ): string {
		$clean = $title;

		// Remove WordPress admin notification bubbles (comments, plugins, updates…).
		$clean = preg_replace(
			'/<span[^>]*\b(?:awaiting-mod|pending-count|update-plugins|update-count|plugin-count|awaiting-count)[^>]*>.*?<\/span>\s*/is',
			'',
			$clean
		);
		$clean = preg_replace(
			'/<span[^>]*class="[^"]*\bcount-\d+[^"]*"[^>]*>.*?<\/span>\s*/is',
			'',
			$clean
		);

		$plain = wp_strip_all_tags( html_entity_decode( (string) $clean, ENT_QUOTES, 'UTF-8' ) );
		$plain = preg_replace( '/\s+/u', ' ', $plain );
		$plain = trim( $plain );

		$suffix_patterns = array(
			'/\s+\d+\s+comentarios?\s+en\s+moderaci[oó]n.*$/iu',
			'/\s+\d+\s+comentaris?\s+en\s+moderaci[oó].*$/iu',
			'/\s+\d+\s+comments?\s+in\s+moderation.*$/iu',
			'/\s+\d+\s+actualizaciones?\s+de\s+plugins?.*$/iu',
			'/\s+\d+\s+plugin\s+updates?.*$/iu',
			'/\s+\d+\s+actualitzacions?\s+de\s+plugins?.*$/iu',
			'/\s+\d+\s+updates?\s+available.*$/iu',
		);

		foreach ( $suffix_patterns as $pattern ) {
			$plain = preg_replace( $pattern, '', $plain );
		}

		// Glued counts: "Comments0" or "Comentarios 0".
		$plain = preg_replace( '/\s*\d+$/u', '', $plain );
		$plain = preg_replace( '/(\p{L})\d+$/u', '$1', $plain );

		$plain = trim( $plain );

		if ( '' === $plain && 'edit-comments.php' === $slug ) {
			return __( 'Comments', 'tso-swiss-knife' );
		}

		return $plain;
	}

	/**
	 * Whether a top-level menu entry is a visual separator.
	 *
	 * @param array<int, mixed> $entry Menu entry.
	 */
	private function is_menu_separator( array $entry ): bool {
		$classes = (string) ( $entry[4] ?? '' );
		if ( str_contains( $classes, 'wp-menu-separator' ) ) {
			return true;
		}
		$slug = (string) ( $entry[2] ?? '' );
		return '' === $slug || 0 === strpos( $slug, 'separator' );
	}

	/**
	 * Canonical slug used in settings for a top-level menu entry.
	 *
	 * @param array<int, mixed> $entry    Menu entry.
	 * @param int               $position Menu position key.
	 */
	private function menu_entry_slug( array $entry, int $position ): string {
		$slug = (string) ( $entry[2] ?? '' );
		if ( $this->is_menu_separator( $entry ) ) {
			if ( '' !== $slug ) {
				return $slug;
			}
			return 'sep:' . $position;
		}
		return $slug;
	}

	/**
	 * Stable id for a top-level menu entry (including separators).
	 *
	 * @param array<int, mixed> $entry    Menu entry.
	 * @param int               $position Menu position key.
	 */
	private function top_level_item_id( array $entry, int $position ): string {
		$slug = (string) ( $entry[2] ?? '' );
		if ( $this->is_menu_separator( $entry ) ) {
			if ( '' !== $slug ) {
				return self::item_id( $slug );
			}
			return 't:sep:' . $position;
		}
		if ( '' === $slug ) {
			return 't:sep:' . $position;
		}
		return self::item_id( $slug );
	}

	/**
	 * Human-readable label for a separator row.
	 *
	 * @param array<int, mixed> $entry    Menu entry.
	 * @param string            $slug     Canonical slug.
	 * @param int               $position Menu position key.
	 */
	private function separator_title( array $entry, string $slug, int $position ): string {
		$title = self::plain_menu_title( (string) ( $entry[0] ?? '' ), $slug );
		if ( '' !== $title ) {
			return $title;
		}
		if ( str_starts_with( $slug, 'sep:' ) ) {
			return sprintf(
				/* translators: %d: menu position number */
				__( 'Separator (position %d)', 'tso-swiss-knife' ),
				$position
			);
		}
		return sprintf(
			/* translators: %s: separator slug */
			__( 'Separator (%s)', 'tso-swiss-knife' ),
			$slug
		);
	}

	/**
	 * Rows for the hidden-items panel (by saved hidden ids).
	 *
	 * @param array<int, string>              $hidden_ids Hidden item ids.
	 * @param array<int, array<string,mixed>> $snapshot   Editor snapshot.
	 * @return array<int, array<string,mixed>>
	 */
	private function get_hidden_panel_rows( array $hidden_ids, array $snapshot ): array {
		$by_id = array();
		foreach ( $snapshot as $row ) {
			$by_id[ (string) $row['id'] ] = $row;
		}

		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( ! is_array( $manifest ) ) {
			$manifest = array();
		}

		$rows  = array();
		$added = array();
		foreach ( $hidden_ids as $hidden_id ) {
			$hidden_id = (string) $hidden_id;
			if ( isset( $added[ $hidden_id ] ) ) {
				continue;
			}

			if ( isset( $by_id[ $hidden_id ] ) ) {
				$rows[]              = $by_id[ $hidden_id ];
				$added[ $hidden_id ] = true;
				continue;
			}

			if ( isset( $manifest[ $hidden_id ] ) && is_array( $manifest[ $hidden_id ] ) ) {
				$rows[]              = $this->normalize_manifest_row( $manifest[ $hidden_id ] );
				$added[ $hidden_id ] = true;
				continue;
			}

			// Legacy id used when separators had an empty slug.
			if ( 't:' === $hidden_id ) {
				foreach ( $manifest as $manifest_row ) {
					if ( ! is_array( $manifest_row ) || empty( $manifest_row['is_separator'] ) ) {
						continue;
					}
					$legacy_id = (string) ( $manifest_row['id'] ?? '' );
					if ( '' === $legacy_id || isset( $added[ $legacy_id ] ) ) {
						continue;
					}
					$rows[]              = $this->normalize_manifest_row( $manifest_row );
					$added[ $legacy_id ] = true;
					break;
				}
			}
		}

		return $rows;
	}

	/**
	 * Stable submenu item id (original WordPress parent), even after relocation.
	 *
	 * @param string $sub_slug        Submenu slug.
	 * @param string $current_parent  Parent slug in the current $submenu tree.
	 */
	private function stable_submenu_item_id( string $sub_slug, string $current_parent ): string {
		$relocations = self::get_settings()['relocations'] ?? array();
		if ( ! is_array( $relocations ) ) {
			$relocations = array();
		}

		foreach ( $relocations as $item_id => $new_parent ) {
			$parsed = self::parse_item_id( (string) $item_id );
			if ( ! $parsed || '' === $parsed['parent'] ) {
				continue;
			}
			if ( $this->canonical_submenu_slug( (string) $parsed['slug'] ) === $this->canonical_submenu_slug( $sub_slug ) ) {
				return self::item_id( $this->canonical_submenu_slug( $sub_slug ), (string) $parsed['parent'] );
			}
		}

		return self::item_id( $this->canonical_submenu_slug( $sub_slug ), $current_parent );
	}

	/**
	 * Match a posted item id to the snapshot (handles relocated parent ids from the UI).
	 *
	 * @param string                              $posted_id  Item id from the browser.
	 * @param array<int, array<string, mixed>>    $known_rows Menu snapshot rows.
	 */
	private function resolve_posted_item_id( string $posted_id, array $known_rows ): string {
		$known_ids = array_column( $known_rows, 'id' );
		if ( in_array( $posted_id, $known_ids, true ) ) {
			return $posted_id;
		}

		$posted_parsed = self::parse_item_id( $posted_id );
		if ( ! $posted_parsed ) {
			return $posted_id;
		}

		foreach ( $known_rows as $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $posted_id ) {
				return $posted_id;
			}
			if ( (int) ( $row['level'] ?? 0 ) !== ( '' === $posted_parsed['parent'] ? 0 : 1 ) ) {
				continue;
			}
			if ( (string) ( $row['slug'] ?? '' ) !== $posted_parsed['slug'] ) {
				continue;
			}
			return (string) $row['id'];
		}

		return $posted_id;
	}

	/**
	 * Snapshot current admin menu for the settings UI.
	 *
	 * @return array<int, array{id:string,parent_id:string,slug:string,parent_slug:string,title:string,level:int,protected:bool}>
	 */
	public function get_menu_snapshot(): array {
		global $menu, $submenu;

		$items = array();
		if ( ! is_array( $menu ) ) {
			return $items;
		}

		foreach ( $menu as $position => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( $this->is_menu_separator( $entry ) ) {
				$slug  = $this->menu_entry_slug( $entry, (int) $position );
				$id    = $this->top_level_item_id( $entry, (int) $position );
				$items[] = array(
					'id'           => $id,
					'parent_id'    => '',
					'slug'         => $slug,
					'parent_slug'  => '',
					'title'        => $this->separator_title( $entry, $slug, (int) $position ),
					'level'        => 0,
					'position'     => (int) $position,
					'protected'    => false,
					'is_separator' => true,
				);
				continue;
			}

			if ( empty( $entry[2] ) ) {
				continue;
			}
			$slug = (string) $entry[2];
			$id   = self::item_id( $slug );
			$items[] = array(
				'id'          => $id,
				'parent_id'   => '',
				'slug'        => $slug,
				'parent_slug' => '',
				'title'       => self::plain_menu_title( (string) ( $entry[0] ?? '' ), $slug ),
				'level'       => 0,
				'position'    => (int) $position,
				'protected'   => $this->is_protected_slug( $slug ),
			);

			if ( ! is_array( $submenu ) || empty( $submenu[ $slug ] ) ) {
				continue;
			}

			$seen_sub_ids = array();
			$seen_slug    = array();
			foreach ( $submenu[ $slug ] as $sub_entry ) {
				if ( ! is_array( $sub_entry ) || empty( $sub_entry[2] ) ) {
					continue;
				}
				$sub_slug        = $this->canonical_submenu_slug( (string) $sub_entry[2] );
				$slug_dedupe     = $slug . '|' . $sub_slug;
				if ( isset( $seen_slug[ $slug_dedupe ] ) ) {
					continue;
				}
				$seen_slug[ $slug_dedupe ] = true;
				$stable_id       = $this->stable_submenu_item_id( $sub_slug, $slug );
				if ( isset( $seen_sub_ids[ $stable_id ] ) ) {
					continue;
				}
				$seen_sub_ids[ $stable_id ] = true;
				$stable_parsed   = self::parse_item_id( $stable_id );
				$original_parent = $stable_parsed ? $stable_parsed['parent'] : $slug;
				$items[]         = array(
					'id'          => $stable_id,
					'parent_id'   => self::item_id( $original_parent ),
					'slug'        => $sub_slug,
					'parent_slug' => $original_parent,
					'title'       => self::plain_menu_title( (string) ( $sub_entry[0] ?? '' ), $sub_slug ),
					'level'       => 1,
					'position'    => 0,
					'protected'   => $this->is_protected_slug( $sub_slug, $original_parent ),
				);
			}
		}

		return $items;
	}

	/**
	 * Persist the last known menu rows so AJAX save can validate posted items.
	 *
	 * @param array<int, array<string, mixed>> $snapshot Menu snapshot rows.
	 */
	private function persist_menu_manifest( array $snapshot ): void {
		if ( array() === $snapshot ) {
			return;
		}

		$manifest = array();
		foreach ( $snapshot as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}
			$id = (string) $row['id'];
			if ( 1 === (int) ( $row['level'] ?? 0 ) ) {
				$parsed = self::parse_item_id( $id );
				if ( $parsed && '' !== $parsed['parent'] ) {
					$id = $this->canonical_submenu_item_id( (string) $parsed['slug'], (string) $parsed['parent'] );
					$row['id']   = $id;
					$row['slug'] = $this->canonical_submenu_slug( (string) ( $row['slug'] ?? '' ) );
				}
			}
			$manifest[ $id ] = $this->normalize_manifest_row( $row );
		}

		$settings   = self::get_settings();
		$referenced = $this->get_settings_referenced_ids( $settings );
		$previous   = get_option( self::MANIFEST_OPTION, array() );
		if ( is_array( $previous ) ) {
			foreach ( $referenced as $item_id ) {
				if ( isset( $manifest[ $item_id ] ) || ! isset( $previous[ $item_id ] ) || ! is_array( $previous[ $item_id ] ) ) {
					continue;
				}
				$manifest[ $item_id ] = $this->normalize_manifest_row( $previous[ $item_id ] );
			}
		}

		$snapshot_ids = array_flip( array_map( 'strval', array_column( $snapshot, 'id' ) ) );
		foreach ( array_keys( $manifest ) as $item_id ) {
			$item_id = (string) $item_id;
			if ( isset( $snapshot_ids[ $item_id ] ) ) {
				continue;
			}
			if ( in_array( $item_id, $referenced, true ) ) {
				continue;
			}
			$parsed = self::parse_item_id( $item_id );
			if ( $parsed && '' !== $parsed['parent'] ) {
				unset( $manifest[ $item_id ] );
			}
		}

		update_option( self::MANIFEST_OPTION, $manifest, false );
	}

	/**
	 * Item ids still referenced in saved menu settings (keep in manifest when inactive).
	 *
	 * @param array<string, mixed> $settings Menu settings.
	 * @return array<int, string>
	 */
	private function get_settings_referenced_ids( array $settings ): array {
		$ids = array_merge(
			array_map( 'strval', is_array( $settings['hidden'] ?? null ) ? $settings['hidden'] : array() ),
			array_map( 'strval', array_keys( is_array( $settings['labels'] ?? null ) ? $settings['labels'] : array() ) ),
			array_map( 'strval', array_keys( is_array( $settings['relocations'] ?? null ) ? $settings['relocations'] : array() ) ),
			array_map( 'strval', array_keys( is_array( $settings['nested_tops'] ?? null ) ? $settings['nested_tops'] : array() ) )
		);

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Normalize a snapshot/manifest row to a consistent shape.
	 *
	 * @param array<string, mixed> $row Menu row.
	 * @return array<string, mixed>
	 */
	private function normalize_manifest_row( array $row ): array {
		return array(
			'id'           => (string) ( $row['id'] ?? '' ),
			'parent_id'    => (string) ( $row['parent_id'] ?? '' ),
			'slug'         => (string) ( $row['slug'] ?? '' ),
			'parent_slug'  => (string) ( $row['parent_slug'] ?? '' ),
			'title'        => (string) ( $row['title'] ?? '' ),
			'level'        => (int) ( $row['level'] ?? 0 ),
			'position'     => (int) ( $row['position'] ?? 0 ),
			'protected'    => ! empty( $row['protected'] ),
			'is_separator' => ! empty( $row['is_separator'] ),
		);
	}

	/**
	 * Full menu list for the editor (live menu + hidden items kept in manifest).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_editor_snapshot(): array {
		$live = $this->get_menu_snapshot();
		if ( array() !== $live ) {
			return $live;
		}

		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( ! is_array( $manifest ) || array() === $manifest ) {
			return array();
		}

		$rows = array();
		foreach ( $manifest as $stored ) {
			if ( is_array( $stored ) ) {
				$rows[] = $this->normalize_manifest_row( $stored );
			}
		}

		return $rows;
	}

	/**
	 * Sanitize a WordPress admin menu slug (may include .php, ?, =, &).
	 *
	 * @param string $slug Raw slug.
	 */
	private function sanitize_menu_slug( string $slug ): string {
		$slug = trim( wp_strip_all_tags( wp_unslash( $slug ) ) );
		if ( '' === $slug ) {
			return '';
		}

		// WordPress admin slugs may include query args with brackets (Customizer autofocus).
		$clean = preg_replace( '/[^\w.?=&:%#@|\/\[\]-]/', '', $slug );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Repair legacy Customizer slugs where square brackets were stripped on save.
	 *
	 * @param string $slug Menu slug.
	 */
	private function canonical_submenu_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}

		$repairs = array(
			'autofocussection='     => 'autofocus[section]=',
			'autofocuspanel='       => 'autofocus[panel]=',
			'autofocuscollection='  => 'autofocus[collection]=',
			'autofocuscontrol='     => 'autofocus[control]=',
		);

		foreach ( $repairs as $broken => $fixed ) {
			if ( str_contains( $slug, $broken ) && ! str_contains( $slug, 'autofocus[' ) ) {
				$slug = str_replace( $broken, $fixed, $slug );
			}
		}

		return $slug;
	}

	/**
	 * Canonical stable id for a submenu row (merges corrupted legacy ids).
	 *
	 * @param string $sub_slug       Submenu slug.
	 * @param string $original_parent Original WordPress parent slug.
	 */
	private function canonical_submenu_item_id( string $sub_slug, string $original_parent ): string {
		return self::item_id( $this->canonical_submenu_slug( $sub_slug ), $original_parent );
	}

	/**
	 * Remove duplicate submenu rows caused by legacy slug corruption.
	 *
	 * @param array<int, array<string, mixed>> $rows        Submenu rows.
	 * @param string                           $parent_slug Parent section slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function dedupe_submenu_display_rows( array $rows, string $parent_slug ): array {
		$deduped         = array();
		$seen_canonical  = array();
		$seen_titles     = array();

		foreach ( $rows as $row ) {
			$slug       = $this->canonical_submenu_slug( (string) ( $row['slug'] ?? '' ) );
			$row_parent = (string) ( $row['parent_slug'] ?? $parent_slug );
			$dedupe_key = $row_parent . '|' . $slug;

			if ( isset( $seen_canonical[ $dedupe_key ] ) ) {
				continue;
			}

			$title_key = $row_parent . '|' . strtolower( trim( (string) ( $row['title'] ?? '' ) ) );
			if ( '' !== trim( (string) ( $row['title'] ?? '' ) ) && isset( $seen_titles[ $title_key ] ) ) {
				continue;
			}

			$seen_canonical[ $dedupe_key ] = true;
			if ( '' !== trim( (string) ( $row['title'] ?? '' ) ) ) {
				$seen_titles[ $title_key ] = true;
			}

			$row['slug'] = $slug;
			$row['id']   = $this->canonical_submenu_item_id( $slug, $row_parent );
			$deduped[]   = $row;
		}

		return $deduped;
	}

	/**
	 * Repair saved submenu order maps (legacy corrupted Customizer slugs / duplicate ids).
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, mixed>
	 */
	private function normalize_stored_submenu_settings( array $settings ): array {
		$sub_order     = is_array( $settings['sub_order'] ?? null ) ? $settings['sub_order'] : array();
		$sub_order_ids = is_array( $settings['sub_order_ids'] ?? null ) ? $settings['sub_order_ids'] : array();

		foreach ( array_keys( $sub_order_ids ) as $parent ) {
			$parent     = (string) $parent;
			$ids        = is_array( $sub_order_ids[ $parent ] ?? null ) ? $sub_order_ids[ $parent ] : array();
			$clean_ids  = array();
			$clean_slug = array();
			$seen       = array();

			foreach ( $ids as $item_id ) {
				$item_id = (string) $item_id;
				$parsed  = self::parse_item_id( $item_id );
				if ( ! $parsed || '' === $parsed['parent'] ) {
					continue;
				}

				$canonical_slug = $this->canonical_submenu_slug( (string) $parsed['slug'] );
				$canonical_id   = self::item_id( $canonical_slug, (string) $parsed['parent'] );
				$dedupe_key     = (string) $parsed['parent'] . '|' . $canonical_slug;

				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$clean_ids[]         = $canonical_id;
				$clean_slug[]        = $canonical_slug;
			}

			if ( array() !== $clean_ids ) {
				$sub_order_ids[ $parent ] = $clean_ids;
				$sub_order[ $parent ]     = $clean_slug;
			} else {
				unset( $sub_order_ids[ $parent ] );
			}
		}

		foreach ( array_keys( $sub_order ) as $parent ) {
			$parent = (string) $parent;
			if ( ! is_array( $sub_order[ $parent ] ?? null ) ) {
				continue;
			}
			$clean_slug = array();
			$seen       = array();
			foreach ( $sub_order[ $parent ] as $slug ) {
				$slug           = $this->canonical_submenu_slug( (string) $slug );
				$dedupe_key     = $parent . '|' . $slug;
				if ( '' === $slug || isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$clean_slug[]        = $slug;
			}
			if ( array() !== $clean_slug ) {
				$sub_order[ $parent ] = $clean_slug;
			} else {
				unset( $sub_order[ $parent ] );
			}
		}

		$settings['sub_order']     = $sub_order;
		$settings['sub_order_ids'] = $sub_order_ids;

		return $settings;
	}

	/**
	 * Menu rows for save validation (live snapshot when available, manifest on AJAX).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_known_menu_rows(): array {
		global $menu;

		if ( is_array( $menu ) && array() !== $menu ) {
			$snapshot = $this->get_menu_snapshot();
			if ( array() !== $snapshot ) {
				return $this->augment_known_rows_with_nested_tops( $snapshot );
			}
		}

		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( is_array( $manifest ) && array() !== $manifest ) {
			$rows = array_values( $manifest );
			return $this->augment_known_rows_with_nested_tops( $rows );
		}

		return array();
	}

	/**
	 * Top-level menus for parent selectors.
	 *
	 * @return array<string, string> slug => title.
	 */
	public function get_top_level_choices(): array {
		$choices = array();
		foreach ( $this->get_editor_snapshot() as $row ) {
			if ( 0 !== (int) $row['level'] ) {
				continue;
			}
			if ( ! empty( $row['is_separator'] ) ) {
				continue;
			}
			$choices[ $row['slug'] ] = $row['title'];
		}
		return $choices;
	}

	/**
	 * Build a display row for a top-level menu nested under another section.
	 *
	 * @param string                              $item_id     Stable top-level item id (t:slug).
	 * @param string                              $parent_slug Target parent section slug.
	 * @param array<int, array<string, mixed>>    $snapshot    Live menu snapshot.
	 * @param array<string, array<string, mixed>> $tops        Top-level rows keyed by slug.
	 * @return array<string, mixed>|null
	 */
	private function resolve_nested_top_row( string $item_id, string $parent_slug, array $snapshot, array $tops ): ?array {
		$parsed = self::parse_item_id( $item_id );
		$parent_slug = $this->sanitize_menu_slug( $parent_slug );
		if ( ! $parsed || '' !== $parsed['parent'] || '' === $parent_slug ) {
			return null;
		}

		$slug = $parsed['slug'];
		if ( isset( $tops[ $slug ] ) ) {
			$row = $tops[ $slug ];
		} else {
			$row = null;
			foreach ( $snapshot as $snap_row ) {
				if ( (int) ( $snap_row['level'] ?? 0 ) !== 1 ) {
					continue;
				}
				if ( (string) ( $snap_row['slug'] ?? '' ) !== $slug ) {
					continue;
				}
				$row = array(
					'id'          => $item_id,
					'parent_id'   => '',
					'slug'        => $slug,
					'parent_slug' => '',
					'title'       => (string) ( $snap_row['title'] ?? $slug ),
					'level'       => 0,
					'position'    => 0,
					'protected'   => ! empty( $snap_row['protected'] ),
				);
				break;
			}

			if ( null === $row ) {
				$manifest = get_option( self::MANIFEST_OPTION, array() );
				if ( is_array( $manifest ) && isset( $manifest[ $item_id ] ) && is_array( $manifest[ $item_id ] ) ) {
					$row = $this->normalize_manifest_row( $manifest[ $item_id ] );
					$row['id']    = $item_id;
					$row['level'] = 0;
				}
			}

			if ( null === $row ) {
				return null;
			}
		}

		$row['effective_parent'] = $parent_slug;
		$row['nested_top']       = true;

		return $row;
	}

	/**
	 * Append virtual rows for nested top-level menus so AJAX save can validate them.
	 *
	 * @param array<int, array<string, mixed>> $rows Menu rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function augment_known_rows_with_nested_tops( array $rows ): array {
		$settings = self::get_settings();
		$nested   = is_array( $settings['nested_tops'] ?? null ) ? $settings['nested_tops'] : array();
		if ( array() === $nested ) {
			return $rows;
		}

		$known_ids = array_column( $rows, 'id' );
		$tops      = array();
		foreach ( $rows as $row ) {
			if ( 0 === (int) ( $row['level'] ?? 0 ) ) {
				$tops[ (string) $row['slug'] ] = $row;
			}
		}

		foreach ( $nested as $item_id => $parent_slug ) {
			$item_id = (string) $item_id;
			if ( in_array( $item_id, $known_ids, true ) ) {
				continue;
			}
			$resolved = $this->resolve_nested_top_row( $item_id, (string) $parent_slug, $rows, $tops );
			if ( null !== $resolved ) {
				$rows[]    = $resolved;
				$known_ids[] = $item_id;
			}
		}

		return $rows;
	}

	/**
	 * Rows ordered for the settings table (saved layout applied).
	 *
	 * @return array<int, array{id:string,parent_id:string,slug:string,parent_slug:string,effective_parent:string,title:string,level:int,protected:bool}>
	 */
	public function get_display_rows(): array {
		$snapshot = $this->get_menu_snapshot();
		if ( array() === $snapshot ) {
			$snapshot = $this->get_editor_snapshot();
		}

		$settings = self::get_settings();

		$tops       = array();
		$subs_by_id = array();
		foreach ( $snapshot as $row ) {
			if ( 0 === (int) $row['level'] ) {
				$tops[ $row['slug'] ] = $row;
			} else {
				$parsed = self::parse_item_id( (string) ( $row['id'] ?? '' ) );
				if ( $parsed && '' !== $parsed['parent'] ) {
					$row['slug'] = $this->canonical_submenu_slug( (string) ( $row['slug'] ?? '' ) );
					$row['id']   = $this->canonical_submenu_item_id( $row['slug'], (string) $parsed['parent'] );
				}
				if ( ! isset( $subs_by_id[ $row['id'] ] ) ) {
					$subs_by_id[ $row['id'] ] = $row;
				}
			}
		}

		$nested_tops  = is_array( $settings['nested_tops'] ?? null ) ? $settings['nested_tops'] : array();
		$nested_slugs = array();
		foreach ( $nested_tops as $item_id => $parent_slug ) {
			$parsed = self::parse_item_id( (string) $item_id );
			if ( ! $parsed || '' !== $parsed['parent'] ) {
				continue;
			}
			$nested_slugs[ $parsed['slug'] ] = true;
		}

		$top_slugs = $settings['top_order'];
		if ( empty( $top_slugs ) ) {
			$top_slugs = array_keys( $tops );
		} else {
			foreach ( array_keys( $tops ) as $slug ) {
				if ( ! in_array( $slug, $top_slugs, true ) ) {
					$top_slugs[] = $slug;
				}
			}
		}

		$top_slugs = array_values(
			array_filter(
				$top_slugs,
				static function ( $slug ) use ( $nested_slugs ) {
					return ! isset( $nested_slugs[ (string) $slug ] );
				}
			)
		);

		$relocations = is_array( $settings['relocations'] ) ? $settings['relocations'] : array();
		$sub_order   = is_array( $settings['sub_order'] ) ? $settings['sub_order'] : array();
		$sub_order_ids = is_array( $settings['sub_order_ids'] ?? null ) ? $settings['sub_order_ids'] : array();

		$subs_by_parent = array();
		foreach ( $subs_by_id as $id => $row ) {
			if ( isset( $nested_slugs[ (string) ( $row['slug'] ?? '' ) ] ) ) {
				continue;
			}
			$effective_parent = isset( $relocations[ $id ] ) ? (string) $relocations[ $id ] : $row['parent_slug'];
			if ( ! isset( $tops[ $effective_parent ] ) ) {
				$effective_parent = $row['parent_slug'];
			}
			$row['effective_parent'] = $effective_parent;
			$subs_by_parent[ $effective_parent ][] = $row;
		}

		foreach ( array_keys( $subs_by_parent ) as $parent_key ) {
			$subs_by_parent[ $parent_key ] = $this->dedupe_submenu_display_rows(
				$subs_by_parent[ $parent_key ],
				(string) $parent_key
			);
		}

		foreach ( $nested_tops as $item_id => $parent_slug ) {
			$row = $this->resolve_nested_top_row( (string) $item_id, (string) $parent_slug, $snapshot, $tops );
			if ( null === $row ) {
				continue;
			}
			$parent_slug = (string) $row['effective_parent'];
			$subs_by_parent[ $parent_slug ][] = $row;
		}

		$display = array();
		foreach ( $top_slugs as $top_slug ) {
			if ( ! isset( $tops[ $top_slug ] ) ) {
				continue;
			}
			$top_row = $tops[ $top_slug ];
			$display[] = $top_row;

			$subs = $subs_by_parent[ $top_slug ] ?? array();
			if ( ! empty( $sub_order_ids[ $top_slug ] ) ) {
				$subs = $this->sort_sub_rows_by_ids( $subs, $sub_order_ids[ $top_slug ] );
			} elseif ( ! empty( $sub_order[ $top_slug ] ) ) {
				$subs = $this->sort_sub_rows( $subs, $sub_order[ $top_slug ], (string) $top_slug );
			}
			foreach ( $subs as $sub_row ) {
				$display[] = $sub_row;
			}
		}

		return $display;
	}

	/**
	 * Sort submenu rows by saved stable item ids.
	 *
	 * @param array<int, array<string, mixed>> $rows     Submenu rows.
	 * @param array<int, string>               $id_order Ordered item ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_sub_rows_by_ids( array $rows, array $id_order ): array {
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (string) ( $row['id'] ?? '' ) ] = $row;
		}

		$sorted = array();
		$used   = array();
		foreach ( $id_order as $id ) {
			$id = (string) $id;
			if ( '' === $id || ! isset( $by_id[ $id ] ) || ! empty( $used[ $id ] ) ) {
				continue;
			}
			$sorted[]   = $by_id[ $id ];
			$used[ $id ] = true;
		}
		foreach ( $rows as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( ! empty( $used[ $id ] ) ) {
				continue;
			}
			$sorted[] = $row;
		}
		return $sorted;
	}

	/**
	 * Sort submenu rows by saved slug order.
	 *
	 * @param array<int, array<string, mixed>> $rows Submenu rows.
	 * @param array<int, string>               $slug_order Ordered slugs.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_sub_rows( array $rows, array $slug_order, string $parent_slug = '' ): array {
		$by_slug = array();
		foreach ( $rows as $row ) {
			$by_slug[ (string) $row['slug'] ] = $row;
		}

		$sorted = array();
		$used   = array();
		foreach ( $slug_order as $slug ) {
			$slug = (string) $slug;
			$key  = isset( $by_slug[ $slug ] ) ? $slug : $this->find_submenu_entry_key_for_slug( $by_slug, $slug, $parent_slug, true );
			if ( null === $key || ! empty( $used[ $key ] ) ) {
				continue;
			}
			$sorted[]     = $by_slug[ $key ];
			$used[ $key ] = true;
		}
		foreach ( $by_slug as $slug => $row ) {
			if ( ! empty( $used[ $slug ] ) ) {
				continue;
			}
			$sorted[] = $row;
		}
		return $sorted;
	}

	/**
	 * Whether a menu slug is protected from hiding.
	 *
	 * @param string $slug   Menu slug.
	 * @param string $parent Parent slug.
	 */
	private function is_protected_slug( string $slug, string $parent = '' ): bool {
		if ( self::PROTECTED_SLUG === $slug ) {
			return true;
		}
		if ( $this->normalize_menu_slug_for_compare( $slug ) === self::PROTECTED_SLUG ) {
			return true;
		}
		if ( 'tools.php' === $parent && str_contains( $slug, self::PROTECTED_SLUG ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Skip menu mutations on the plugin settings screen and during its AJAX save.
	 *
	 * Prevents a broken layout from locking the user out of this page (wp_die permissions).
	 */
	private function should_bypass_menu_modifications(): bool {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		return in_array( $action, array( 'tsosk_am_save', 'tsosk_am_reset' ), true );
	}

	/**
	 * Whether a slug belongs to a core WordPress admin top-level menu.
	 *
	 * @param string $slug Menu slug.
	 */
	private function is_core_top_menu_slug( string $slug ): bool {
		return in_array( $slug, self::CORE_TOP_SLUGS, true );
	}

	/**
	 * Apply hide, rename, reorder, and relocate rules.
	 */
	public function apply_customizations(): void {
		if ( $this->should_bypass_menu_modifications() ) {
			return;
		}

		global $menu, $submenu;

		$settings = self::get_settings();
		$hidden   = array_flip( array_map( 'strval', $settings['hidden'] ?? array() ) );
		$labels   = is_array( $settings['labels'] ?? null ) ? $settings['labels'] : array();
		$order    = is_array( $settings['top_order'] ?? null ) ? $settings['top_order'] : array();
		$nested   = is_array( $settings['nested_tops'] ?? null ) ? $settings['nested_tops'] : array();

		if ( is_array( $menu ) && ! empty( $order ) ) {
			$menu = $this->restore_unnested_top_menus( $menu, $submenu, $order, $nested );
			$GLOBALS['menu'] = $menu;
		}

		if ( is_array( $menu ) && ! empty( $nested ) ) {
			$menu = $this->apply_nested_top_menus( $menu, $submenu, $nested );
			$GLOBALS['menu'] = $menu;
		}

		if ( is_array( $menu ) && ! empty( $order ) ) {
			$menu = $this->reorder_top_level_menu( $menu, $order );
			$GLOBALS['menu'] = $menu;
		}

		if ( is_array( $submenu ) ) {
			$submenu = $this->apply_submenu_layout(
				$submenu,
				is_array( $settings['relocations'] ?? null ) ? $settings['relocations'] : array(),
				is_array( $settings['sub_order'] ?? null ) ? $settings['sub_order'] : array(),
				is_array( $settings['sub_order_ids'] ?? null ) ? $settings['sub_order_ids'] : array()
			);
			$GLOBALS['submenu'] = $submenu;
		}

		$this->ensure_protected_tools_submenu();

		if ( is_array( $menu ) ) {
			foreach ( $menu as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$is_separator = $this->is_menu_separator( $entry );
				$slug         = $this->menu_entry_slug( $entry, (int) $key );
				$id           = $this->top_level_item_id( $entry, (int) $key );

				if ( $is_separator ) {
					if ( isset( $hidden[ $id ] ) ) {
						unset( $menu[ $key ] );
					}
					continue;
				}

				if ( empty( $entry[2] ) ) {
					continue;
				}

				if ( isset( $hidden[ $id ] ) && ! $this->is_protected_slug( $slug ) && ! $this->is_core_top_menu_slug( $slug ) ) {
					remove_menu_page( $slug );
					continue;
				}

				if ( ! empty( $labels[ $id ] ) ) {
					$menu[ $key ][0] = esc_html( $labels[ $id ] );
				}
			}
		}

		if ( ! is_array( $submenu ) ) {
			return;
		}

		$submenu = $GLOBALS['submenu'] ?? $submenu;

		foreach ( $submenu as $parent_slug => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $sub_key => $sub_entry ) {
				if ( ! is_array( $sub_entry ) || empty( $sub_entry[2] ) ) {
					continue;
				}
				$sub_slug = (string) $sub_entry[2];
				$id       = $this->find_submenu_settings_id( $sub_slug, (string) $parent_slug );

				if ( isset( $hidden[ $id ] ) && ! $this->is_protected_slug( $sub_slug, (string) $parent_slug ) ) {
					remove_submenu_page( (string) $parent_slug, $sub_slug );
					continue;
				}

				if ( ! empty( $labels[ $id ] ) ) {
					$submenu[ $parent_slug ][ $sub_key ][0] = esc_html( $labels[ $id ] );
				}
			}
		}
	}

	/**
	 * Re-apply nested tops, relocations, and submenu order after other plugins register admin menus.
	 */
	public function enforce_submenu_layout(): void {
		if ( $this->should_bypass_menu_modifications() ) {
			return;
		}

		global $menu;

		$settings    = self::get_settings();
		$relocations = is_array( $settings['relocations'] ?? null ) ? $settings['relocations'] : array();
		$sub_order   = is_array( $settings['sub_order'] ?? null ) ? $settings['sub_order'] : array();
		$sub_order_ids = is_array( $settings['sub_order_ids'] ?? null ) ? $settings['sub_order_ids'] : array();
		$nested      = is_array( $settings['nested_tops'] ?? null ) ? $settings['nested_tops'] : array();

		if ( ! is_array( $GLOBALS['submenu'] ?? null ) ) {
			return;
		}

		if ( array() === $relocations && array() === $sub_order && array() === $sub_order_ids && array() === $nested ) {
			return;
		}

		if ( ! empty( $nested ) && is_array( $menu ) ) {
			$menu            = $this->apply_nested_top_menus( $menu, $GLOBALS['submenu'], $nested );
			$GLOBALS['menu'] = $menu;
		}

		$top_order = is_array( $settings['top_order'] ?? null ) ? $settings['top_order'] : array();
		if ( ! empty( $top_order ) && is_array( $menu ) ) {
			$menu            = $this->restore_unnested_top_menus( $menu, $GLOBALS['submenu'], $top_order, $nested );
			$GLOBALS['menu'] = $menu;
		}

		$GLOBALS['submenu'] = $this->apply_submenu_layout(
			$GLOBALS['submenu'],
			$relocations,
			$sub_order,
			$sub_order_ids
		);
		$this->ensure_protected_tools_submenu();
	}

	/**
	 * Resolve settings item id for a submenu entry (stable after relocation).
	 *
	 * @param string $slug         Submenu slug.
	 * @param string $parent_slug  Current parent slug in $submenu.
	 */
	private function find_submenu_settings_id( string $slug, string $parent_slug ): string {
		$settings = self::get_settings();

		foreach ( $settings['relocations'] ?? array() as $item_id => $new_parent ) {
			$parsed = self::parse_item_id( (string) $item_id );
			if ( $parsed && $parsed['slug'] === $slug ) {
				return (string) $item_id;
			}
		}

		$candidate_ids = array_merge(
			array_keys( is_array( $settings['labels'] ?? null ) ? $settings['labels'] : array() ),
			is_array( $settings['hidden'] ?? null ) ? $settings['hidden'] : array()
		);

		foreach ( $candidate_ids as $item_id ) {
			$parsed = self::parse_item_id( (string) $item_id );
			if ( $parsed && '' !== $parsed['parent'] && $parsed['slug'] === $slug ) {
				return (string) $item_id;
			}
		}

		return self::item_id( $slug, $parent_slug );
	}

	/**
	 * Always restore the TSO Swiss Knife Tools submenu entry and its registered hook.
	 *
	 * Runs even when other menu mutations are bypassed so a bad save cannot lock admins out.
	 */
	public function guard_protected_tools_submenu(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $_registered_pages;

		$hook = get_plugin_page_hookname( self::PROTECTED_SLUG, 'tools.php' );
		if ( '' !== $hook && empty( $_registered_pages[ $hook ] ) && class_exists( 'TSOSK_Admin' ) ) {
			$admin = TSOSK_Admin::get_instance();
			if ( method_exists( $admin, 'render_page' ) ) {
				add_submenu_page(
					'tools.php',
					__( 'TSO Swiss Knife', 'tso-swiss-knife' ),
					__( 'TSO Swiss Knife', 'tso-swiss-knife' ),
					'manage_options',
					self::PROTECTED_SLUG,
					array( $admin, 'render_page' )
				);
			}
		}

		$this->ensure_protected_tools_submenu();
	}

	/**
	 * Restore the TSO Swiss Knife Tools submenu if a previous save removed it by mistake.
	 */
	private function ensure_protected_tools_submenu(): void {
		global $submenu;

		if ( ! is_array( $submenu ) ) {
			return;
		}

		if ( ! is_array( $submenu['tools.php'] ?? null ) ) {
			$submenu['tools.php'] = array();
		}

		foreach ( $submenu['tools.php'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			if ( $this->is_protected_slug( (string) $entry[2], 'tools.php' ) ) {
				return;
			}
		}

		$submenu['tools.php'][] = array(
			__( 'TSO Swiss Knife', 'tso-swiss-knife' ),
			'manage_options',
			self::PROTECTED_SLUG,
			__( 'TSO Swiss Knife', 'tso-swiss-knife' ),
		);
	}

	/**
	 * Move and reorder submenu entries.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu     Global submenu.
	 * @param array<string, string>                        $relocations Item id => new parent slug.
	 * @param array<string, array<int, string>>            $sub_order      Parent => ordered slugs.
	 * @param array<string, array<int, string>>            $sub_order_ids  Parent => ordered item ids.
	 * @return array<string, array<int, array<int, mixed>>>
	 */
	private function apply_submenu_layout( array $submenu, array $relocations, array $sub_order, array $sub_order_ids = array() ): array {
		foreach ( $relocations as $item_id => $new_parent ) {
			$parsed = self::parse_item_id( (string) $item_id );
			$new_parent = (string) $new_parent;
			if ( ! $parsed || '' === $parsed['parent'] || '' === $new_parent ) {
				continue;
			}
			if ( $parsed['parent'] === $new_parent ) {
				continue;
			}
			if ( ! isset( $submenu[ $new_parent ] ) || ! is_array( $submenu[ $new_parent ] ) ) {
				$submenu[ $new_parent ] = array();
			}

			$already_there = false;
			foreach ( $submenu[ $new_parent ] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				if ( $this->submenu_entry_matches_slug( $entry, $parsed['slug'], $new_parent, true ) ) {
					$already_there = true;
					break;
				}
			}

			if ( $already_there ) {
				$this->extract_submenu_entry_by_match( $submenu, $parsed['parent'], $parsed['slug'] );
				continue;
			}

			$moved = $this->extract_submenu_entry_by_match( $submenu, $parsed['parent'], $parsed['slug'] );
			if ( null === $moved ) {
				$moved = $this->extract_submenu_entry_by_match_from_any_parent( $submenu, $parsed['slug'] );
			}
			if ( null === $moved ) {
				continue;
			}
			$submenu[ $new_parent ][] = $moved;
		}

		foreach ( $sub_order as $parent_slug => $slug_list ) {
			if ( ! is_array( $slug_list ) ) {
				continue;
			}
			$parent_key = $this->resolve_submenu_parent_key( $submenu, (string) $parent_slug );
			if ( ! isset( $submenu[ $parent_key ] ) || ! is_array( $submenu[ $parent_key ] ) ) {
				continue;
			}

			$item_ids = is_array( $sub_order_ids[ $parent_slug ] ?? null ) ? $sub_order_ids[ $parent_slug ] : array();
			if ( array() !== $item_ids ) {
				$hints = $this->build_entry_slug_hints(
					$item_ids,
					$submenu[ $parent_key ],
					$this->get_manifest_slug_map()
				);
				$submenu[ $parent_key ] = $this->reorder_submenu_entries_by_item_ids(
					$submenu[ $parent_key ],
					$item_ids,
					$hints,
					(string) $parent_key
				);
				continue;
			}

			$submenu[ $parent_key ] = $this->reorder_submenu_entries(
				$submenu[ $parent_key ],
				$slug_list,
				(string) $parent_key
			);
		}

		return $submenu;
	}

	/**
	 * Resolve the parent key used in the global $submenu array.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu     Global submenu.
	 * @param string                                       $parent_slug Parent slug.
	 */
	private function resolve_submenu_parent_key( array $submenu, string $parent_slug ): string {
		if ( isset( $submenu[ $parent_slug ] ) ) {
			return $parent_slug;
		}
		foreach ( array_keys( $submenu ) as $key ) {
			if ( (string) $key === $parent_slug ) {
				return (string) $key;
			}
		}
		return $parent_slug;
	}

	/**
	 * Move configured top-level menu pages under another section as submenu entries.
	 *
	 * @param array<int, mixed>                                $menu        Global menu.
	 * @param array<string, array<int, array<int, mixed>>>|null $submenu    Global submenu.
	 * @param array<string, string>                            $nested_tops Item id => parent slug.
	 * @return array<int, mixed>
	 */
	private function apply_nested_top_menus( array $menu, ?array &$submenu, array $nested_tops ): array {
		if ( ! is_array( $submenu ) ) {
			$submenu = array();
		}

		foreach ( $nested_tops as $item_id => $parent_slug ) {
			$parsed = self::parse_item_id( (string) $item_id );
			$parent_slug = $this->sanitize_menu_slug( (string) $parent_slug );
			if ( ! $parsed || '' !== $parsed['parent'] || '' === $parent_slug ) {
				continue;
			}

			$slug      = (string) $parsed['slug'];
			$entry     = null;
			$menu_key  = null;
			$parent_key = $this->resolve_submenu_parent_key( $submenu, $parent_slug );

			foreach ( $menu as $key => $menu_entry ) {
				if ( ! is_array( $menu_entry ) || empty( $menu_entry[2] ) ) {
					continue;
				}
				if ( ! $this->top_level_menu_slug_matches( (string) $menu_entry[2], $slug ) ) {
					continue;
				}
				$entry    = $menu_entry;
				$menu_key = $key;
				break;
			}

			if ( null !== $menu_key ) {
				unset( $menu[ $menu_key ] );
			}

			if ( null === $entry ) {
				$entry = $this->extract_submenu_entry_by_match_anywhere( $submenu, $slug, $parent_key );
			} else {
				$this->remove_nested_slug_from_submenus( $submenu, $slug, $parent_key );
			}

			if ( null === $entry ) {
				continue;
			}

			if ( ! isset( $submenu[ $parent_key ] ) || ! is_array( $submenu[ $parent_key ] ) ) {
				$submenu[ $parent_key ] = array();
			}

			$exists = false;
			foreach ( $submenu[ $parent_key ] as $sub_entry ) {
				if ( ! is_array( $sub_entry ) || empty( $sub_entry[2] ) ) {
					continue;
				}
				if ( $this->submenu_entry_matches_slug( $sub_entry, $slug, $parent_key, true ) ) {
					$exists = true;
					break;
				}
			}

			if ( ! $exists ) {
				$submenu[ $parent_key ][] = array(
					(string) ( $entry[0] ?? '' ),
					(string) ( $entry[1] ?? 'manage_options' ),
					(string) $entry[2],
					(string) ( $entry[3] ?? $entry[0] ?? '' ),
				);
			}
		}

		return $menu;
	}

	/**
	 * Move plugin menus back to the top-level sidebar when they are no longer nested.
	 *
	 * @param array<int, mixed>                                $menu        Global menu.
	 * @param array<string, array<int, array<int, mixed>>>|null $submenu     Global submenu.
	 * @param array<int, string>                               $top_order   Saved top-level slug order.
	 * @param array<string, string>                            $nested_tops Item id => parent slug.
	 * @return array<int, mixed>
	 */
	private function restore_unnested_top_menus( array $menu, ?array &$submenu, array $top_order, array $nested_tops ): array {
		if ( ! is_array( $submenu ) ) {
			$submenu = array();
		}

		$nested_slugs = array();
		foreach ( $nested_tops as $item_id => $parent_slug ) {
			unset( $parent_slug );
			$parsed = self::parse_item_id( (string) $item_id );
			if ( $parsed && '' === $parsed['parent'] && '' !== $parsed['slug'] ) {
				$nested_slugs[ (string) $parsed['slug'] ] = true;
			}
		}

		foreach ( $top_order as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || isset( $nested_slugs[ $slug ] ) ) {
				continue;
			}

			$in_menu = false;
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				if ( $this->top_level_menu_slug_matches( (string) $entry[2], $slug ) ) {
					$in_menu = true;
					break;
				}
			}

			if ( $in_menu ) {
				// Only remove copies under other sections — never the native parent submenu
				// (WordPress duplicates the parent slug as the first submenu item, e.g. edit.php).
				$this->remove_top_slug_from_foreign_submenus( $submenu, $slug );
				continue;
			}

			$entry = $this->extract_submenu_entry_by_match_from_any_parent( $submenu, $slug );
			if ( null === $entry ) {
				continue;
			}

			$menu[] = $entry;
		}

		return $menu;
	}

	/**
	 * Extract a submenu entry from whichever parent currently owns it.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu Global submenu.
	 * @param string                                       $slug    Target slug.
	 * @return array<int, mixed>|null
	 */
	private function extract_submenu_entry_by_match_from_any_parent( array &$submenu, string $slug ): ?array {
		foreach ( array_keys( $submenu ) as $parent ) {
			$found = $this->extract_submenu_entry_by_match( $submenu, (string) $parent, $slug );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Strict slug match for top-level $menu entries (avoid unsetting core menus by mistake).
	 *
	 * @param string $entry_slug Live top-level menu slug.
	 * @param string $saved_slug Saved nested-top slug.
	 */
	private function top_level_menu_slug_matches( string $entry_slug, string $saved_slug ): bool {
		if ( $entry_slug === $saved_slug ) {
			return true;
		}

		return $this->normalize_menu_slug_for_compare( $entry_slug ) === $this->normalize_menu_slug_for_compare( $saved_slug );
	}

	/**
	 * Remove a nested plugin slug from every submenu parent except the target section.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu       Global submenu.
	 * @param string                                       $slug          Nested plugin slug.
	 * @param string                                       $except_parent Parent slug to keep.
	 */
	private function remove_nested_slug_from_submenus( array &$submenu, string $slug, string $except_parent ): void {
		foreach ( array_keys( $submenu ) as $parent ) {
			$parent = (string) $parent;
			if ( $parent === $except_parent ) {
				continue;
			}
			$this->extract_submenu_entry_by_match( $submenu, $parent, $slug );
		}
	}

	/**
	 * Remove a top-level menu slug only from submenu sections other than its own parent.
	 *
	 * WordPress registers the parent file as the first submenu entry (e.g. index.php under
	 * Dashboard, edit.php under Posts). Stripping those breaks core submenus.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu Global submenu.
	 * @param string                                       $slug    Top-level menu slug.
	 */
	private function remove_top_slug_from_foreign_submenus( array &$submenu, string $slug ): void {
		foreach ( array_keys( $submenu ) as $parent ) {
			$parent = (string) $parent;
			if ( $this->top_level_menu_slug_matches( $parent, $slug ) ) {
				continue;
			}
			$this->extract_submenu_entry_by_match( $submenu, $parent, $slug );
		}
	}

	/**
	 * Extract a submenu entry using slug matching (supports admin.php?page= variants).
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu Global submenu.
	 * @param string                                       $parent  Parent slug.
	 * @param string                                       $slug    Target slug.
	 * @return array<int, mixed>|null
	 */
	private function extract_submenu_entry_by_match( array &$submenu, string $parent, string $slug ): ?array {
		if ( ! isset( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return null;
		}

		foreach ( $submenu[ $parent ] as $key => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$entry_slug = (string) $entry[2];
			if ( $this->is_protected_slug( $entry_slug, $parent ) ) {
				continue;
			}
			if ( ! $this->submenu_entry_matches_slug( $entry, $slug, $parent, true ) ) {
				continue;
			}
			$found = $entry;
			unset( $submenu[ $parent ][ $key ] );
			$submenu[ $parent ] = array_values( $submenu[ $parent ] );
			return $found;
		}

		return null;
	}

	/**
	 * Pull a nested-top entry out of any submenu parent (re-parenting).
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu       Global submenu.
	 * @param string                                       $slug          Nested plugin slug.
	 * @param string                                       $except_parent Target parent slug.
	 * @return array<int, mixed>|null
	 */
	private function extract_submenu_entry_by_match_anywhere( array &$submenu, string $slug, string $except_parent ): ?array {
		foreach ( array_keys( $submenu ) as $parent ) {
			$parent = (string) $parent;
			if ( $parent === $except_parent ) {
				continue;
			}
			$found = $this->extract_submenu_entry_by_match( $submenu, $parent, $slug );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Remove a submenu entry from its parent.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu Global submenu.
	 * @param string                                       $parent  Parent slug.
	 * @param string                                       $slug    Submenu slug.
	 * @return array<int, mixed>|null
	 */
	private function extract_submenu_entry( array &$submenu, string $parent, string $slug ): ?array {
		if ( ! isset( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return null;
		}
		foreach ( $submenu[ $parent ] as $key => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			if ( (string) $entry[2] !== $slug ) {
				continue;
			}
			$found = $entry;
			unset( $submenu[ $parent ][ $key ] );
			$submenu[ $parent ] = array_values( $submenu[ $parent ] );
			return $found;
		}
		return null;
	}

	/**
	 * Remove a submenu entry from its preferred parent, or from any parent as fallback.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $submenu           Global submenu.
	 * @param string                                       $slug              Submenu slug.
	 * @param string                                       $preferred_parent  Original parent slug.
	 * @return array<int, mixed>|null
	 */
	private function extract_submenu_entry_anywhere( array &$submenu, string $slug, string $preferred_parent = '' ): ?array {
		if ( '' !== $preferred_parent ) {
			$entry = $this->extract_submenu_entry( $submenu, $preferred_parent, $slug );
			if ( null !== $entry ) {
				return $entry;
			}
		}

		foreach ( array_keys( $submenu ) as $parent ) {
			$parent = (string) $parent;
			if ( $parent === $preferred_parent ) {
				continue;
			}
			$entry = $this->extract_submenu_entry( $submenu, $parent, $slug );
			if ( null !== $entry ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Assign WordPress-style numeric positions to an ordered submenu list.
	 *
	 * @param array<int, array<int, mixed>> $ordered_entries Entries in display order.
	 * @return array<int, array<int, mixed>>
	 */
	private function assign_submenu_positions( array $ordered_entries ): array {
		$position    = 0;
		$new_entries = array();

		foreach ( $ordered_entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$position               += 5;
			$new_entries[ $position ] = $entry;
		}

		ksort( $new_entries );
		return $new_entries;
	}

	/**
	 * Reorder submenu entries by slug list.
	 *
	 * @param array<int, array<int, mixed>> $entries  Submenu entries.
	 * @param array<int, string>            $slug_order Ordered slugs.
	 * @return array<int, array<int, mixed>>
	 */
	private function reorder_submenu_entries( array $entries, array $slug_order, string $parent_slug = '' ): array {
		$by_slug = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$by_slug[ (string) $entry[2] ] = $entry;
		}

		$ordered = array();
		$used    = array();

		foreach ( $slug_order as $slug ) {
			$slug = (string) $slug;
			$key  = isset( $by_slug[ $slug ] ) ? $slug : $this->find_submenu_entry_key_for_slug( $by_slug, $slug, $parent_slug, true );
			if ( null === $key || ! empty( $used[ $key ] ) ) {
				continue;
			}
			$ordered[]    = $by_slug[ $key ];
			$used[ $key ] = true;
		}
		foreach ( $by_slug as $slug => $entry ) {
			if ( ! empty( $used[ $slug ] ) ) {
				continue;
			}
			$ordered[] = $entry;
		}

		return $this->assign_submenu_positions( $ordered );
	}

	/**
	 * Reorder submenu entries using exact live entry slugs (entry[2]).
	 *
	 * @param array<int, array<int, mixed>> $entries           Submenu entries.
	 * @param array<int, string>            $exact_slug_order Live entry slugs in order.
	 * @return array<int, array<int, mixed>>
	 */
	private function reorder_submenu_entries_exact( array $entries, array $exact_slug_order ): array {
		$by_slug = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}
			$by_slug[ (string) $entry[2] ] = $entry;
		}

		$ordered_entries = array();
		$used            = array();

		foreach ( $exact_slug_order as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || ! isset( $by_slug[ $slug ] ) || ! empty( $used[ $slug ] ) ) {
				continue;
			}
			$ordered_entries[] = $by_slug[ $slug ];
			$used[ $slug ]     = true;
		}

		foreach ( $by_slug as $slug => $entry ) {
			if ( ! empty( $used[ $slug ] ) ) {
				continue;
			}
			$ordered_entries[] = $entry;
		}

		return $this->assign_submenu_positions( $ordered_entries );
	}

	/**
	 * Reorder submenu entries using stable item ids (matches nested tops and plugin submenus reliably).
	 *
	 * @param array<int, array<int, mixed>> $entries   Submenu entries.
	 * @param array<int, string>            $item_ids  Ordered item ids.
	 * @param array<string, string>         $slug_hints Item id => last known slug.
	 * @return array<int, array<int, mixed>>
	 */
	private function reorder_submenu_entries_by_item_ids( array $entries, array $item_ids, array $slug_hints, string $parent_slug = '' ): array {
		if ( array() === $item_ids ) {
			return $entries;
		}

		$ordered      = array();
		$used_indexes = array();

		foreach ( $item_ids as $item_id ) {
			$item_id    = (string) $item_id;
			$candidates = $this->get_item_id_slug_candidates( $item_id, $slug_hints );
			if ( array() === $candidates ) {
				continue;
			}

			foreach ( $entries as $index => $entry ) {
				if ( isset( $used_indexes[ $index ] ) || ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				foreach ( $candidates as $candidate ) {
					if ( ! $this->submenu_entry_matches_slug( $entry, $candidate, $parent_slug, true ) ) {
						continue;
					}
					$ordered[]              = $entry;
					$used_indexes[ $index ] = true;
					break 2;
				}
			}
		}

		foreach ( $entries as $index => $entry ) {
			if ( isset( $used_indexes[ $index ] ) ) {
				continue;
			}
			$ordered[] = $entry;
		}

		return $this->assign_submenu_positions( $ordered );
	}

	/**
	 * Map stable item ids to the last known menu slug (from manifest).
	 *
	 * @return array<string, string>
	 */
	private function get_manifest_slug_map(): array {
		$map      = array();
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( ! is_array( $manifest ) ) {
			return $map;
		}
		foreach ( $manifest as $item_id => $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}
			$map[ (string) $item_id ] = (string) $row['slug'];
		}
		return $map;
	}

	/**
	 * Map item ids to the live submenu slug currently registered in WordPress.
	 *
	 * @param array<int, string>              $item_ids       Ordered item ids.
	 * @param array<int, array<int, mixed>>   $entries        Live submenu entries.
	 * @param array<string, string>           $manifest_hints Item id => last known slug.
	 * @return array<string, string>
	 */
	private function build_entry_slug_hints( array $item_ids, array $entries, array $manifest_hints ): array {
		$hints      = $manifest_hints;
		$live_slugs = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry[2] ) ) {
				$live_slugs[] = (string) $entry[2];
			}
		}

		foreach ( $item_ids as $item_id ) {
			$item_id = (string) $item_id;
			if ( '' === $item_id ) {
				continue;
			}

			$candidates = array();
			if ( ! empty( $hints[ $item_id ] ) ) {
				$candidates[] = (string) $hints[ $item_id ];
			}
			$parsed = self::parse_item_id( $item_id );
			if ( $parsed && '' !== $parsed['slug'] ) {
				$candidates[] = (string) $parsed['slug'];
			}

			foreach ( $candidates as $candidate ) {
				foreach ( $live_slugs as $live_slug ) {
					if ( $this->submenu_entry_matches_slug( array( '', '', $live_slug ), $candidate ) ) {
						$hints[ $item_id ] = $live_slug;
						break 2;
					}
				}
			}
		}

		return $hints;
	}

	/**
	 * Build an ordered list of live submenu slugs (entry[2]) for reorder_submenu_entries().
	 *
	 * Tries stable item ids first (with slug hints), then falls back to saved slug order.
	 *
	 * @param array<int, array<int, mixed>> $entries        Live submenu entries.
	 * @param array<int, string>            $saved_slug_order Slugs saved with the settings (DOM/manifest).
	 * @param array<int, string>            $item_ids       Ordered stable item ids.
	 * @param array<string, string>         $slug_hints     Item id => slug hints.
	 * @return array<int, string>
	 */
	private function resolve_live_submenu_slug_order(
		array $entries,
		array $saved_slug_order,
		array $item_ids = array(),
		array $slug_hints = array(),
		string $parent_slug = ''
	): array {
		$live_order   = array();
		$used_indexes = array();

		foreach ( $item_ids as $item_id ) {
			$item_id    = (string) $item_id;
			$candidates = $this->get_item_id_slug_candidates( $item_id, $slug_hints );
			if ( array() === $candidates ) {
				continue;
			}

			foreach ( $entries as $index => $entry ) {
				if ( isset( $used_indexes[ $index ] ) || ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}
				foreach ( $candidates as $candidate ) {
					foreach ( $this->expand_slug_match_variants( $candidate ) as $variant ) {
						if ( ! $this->submenu_entry_matches_slug( $entry, $variant, $parent_slug ) ) {
							continue;
						}
						$live_order[]            = (string) $entry[2];
						$used_indexes[ $index ] = true;
						break 3;
					}
				}
			}
		}

		if ( array() !== $live_order ) {
			return $live_order;
		}

		foreach ( $saved_slug_order as $saved_slug ) {
			$saved_slug = (string) $saved_slug;
			if ( '' === $saved_slug ) {
				continue;
			}
			foreach ( $this->expand_slug_match_variants( $saved_slug ) as $variant ) {
				foreach ( $entries as $index => $entry ) {
					if ( isset( $used_indexes[ $index ] ) || ! is_array( $entry ) || empty( $entry[2] ) ) {
						continue;
					}
					if ( ! $this->submenu_entry_matches_slug( $entry, $variant, $parent_slug ) ) {
						continue;
					}
					$live_order[]            = (string) $entry[2];
					$used_indexes[ $index ] = true;
					break 2;
				}
			}
		}

		return $live_order;
	}

	/**
	 * Persist exact live entry slugs for each saved submenu order (used on apply).
	 */
	private function refresh_sub_order_resolved(): void {
		global $submenu;

		if ( ! is_array( $submenu ) ) {
			return;
		}

		$settings  = self::get_settings();
		$sub_order = is_array( $settings['sub_order'] ?? null ) ? $settings['sub_order'] : array();
		if ( array() === $sub_order ) {
			return;
		}

		$resolved = is_array( $settings['sub_order_resolved'] ?? null ) ? $settings['sub_order_resolved'] : array();
		$changed  = false;

		foreach ( $sub_order as $parent_slug => $slug_list ) {
			if ( ! is_array( $slug_list ) || array() === $slug_list ) {
				continue;
			}
			$parent_key = $this->resolve_submenu_parent_key( $submenu, (string) $parent_slug );
			if ( ! isset( $submenu[ $parent_key ] ) || ! is_array( $submenu[ $parent_key ] ) ) {
				continue;
			}
			$live_slugs = $this->resolve_live_submenu_slug_order(
				$submenu[ $parent_key ],
				$slug_list,
				$settings['sub_order_ids'][ $parent_slug ] ?? array(),
				$this->build_entry_slug_hints(
					$settings['sub_order_ids'][ $parent_slug ] ?? array(),
					$submenu[ $parent_key ],
					$this->get_manifest_slug_map()
				),
				(string) $parent_key
			);
			if ( array() === $live_slugs ) {
				continue;
			}
			if ( ( $resolved[ $parent_slug ] ?? array() ) !== $live_slugs ) {
				$resolved[ $parent_slug ] = $live_slugs;
				$changed                  = true;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored['sub_order_resolved'] = $resolved;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Build parent => ordered slugs map from saved item ids and known menu rows.
	 *
	 * @param array<string, array<int, string>> $sub_order_ids Parent => ordered item ids.
	 * @param array<int, array<string, mixed>>  $known         Known menu rows.
	 * @return array<string, array<int, string>>
	 */
	private function build_sub_order_live( array $sub_order_ids, array $known ): array {
		$known_by_id = array();
		foreach ( $known as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$known_by_id[ $id ] = (string) ( $row['slug'] ?? '' );
		}

		$live = array();
		foreach ( $sub_order_ids as $parent => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$parent = (string) $parent;
			foreach ( $ids as $item_id ) {
				$item_id = (string) $item_id;
				if ( '' === $item_id || empty( $known_by_id[ $item_id ] ) ) {
					continue;
				}
				if ( ! isset( $live[ $parent ] ) ) {
					$live[ $parent ] = array();
				}
				$live[ $parent ][] = $known_by_id[ $item_id ];
			}
		}

		return $live;
	}

	/**
	 * Best-effort resolved slug map at save time (refined on next admin page load).
	 *
	 * @param array<string, array<int, string>> $sub_order     Parent => ordered slugs.
	 * @param array<string, array<int, string>> $sub_order_ids Parent => ordered item ids.
	 * @param array<int, array<string, mixed>>  $known         Known menu rows.
	 * @return array<string, array<int, string>>
	 */
	private function build_sub_order_resolved_from_known( array $sub_order, array $sub_order_ids, array $known ): array {
		unset( $known );
		$resolved = array();
		foreach ( $sub_order as $parent => $slugs ) {
			if ( ! is_array( $slugs ) || array() === $slugs ) {
				continue;
			}
			$resolved[ (string) $parent ] = array_values( array_map( 'strval', $slugs ) );
		}
		return $resolved;
	}

	/**
	 * Append a submenu slug to the saved order list for a parent (no duplicates).
	 *
	 * @param array<string, array<int, string>> $sub_order   Parent => ordered slugs.
	 * @param string                            $parent_slug Parent menu slug.
	 * @param string                            $slug        Submenu slug.
	 */
	private function append_sub_order_slug( array &$sub_order, string $parent_slug, string $slug ): void {
		if ( '' === $parent_slug || '' === $slug ) {
			return;
		}
		if ( ! isset( $sub_order[ $parent_slug ] ) ) {
			$sub_order[ $parent_slug ] = array();
		}
		if ( in_array( $slug, $sub_order[ $parent_slug ], true ) ) {
			return;
		}
		$sub_order[ $parent_slug ][] = $slug;
	}

	/**
	 * Append a submenu item id to the saved order list for a parent (no duplicates).
	 *
	 * @param array<string, array<int, string>> $sub_order_ids Parent => ordered item ids.
	 * @param string                            $parent_slug   Parent menu slug.
	 * @param string                            $item_id       Stable item id.
	 */
	private function append_sub_order_id( array &$sub_order_ids, string $parent_slug, string $item_id ): void {
		if ( '' === $parent_slug || '' === $item_id ) {
			return;
		}
		if ( ! isset( $sub_order_ids[ $parent_slug ] ) ) {
			$sub_order_ids[ $parent_slug ] = array();
		}
		if ( in_array( $item_id, $sub_order_ids[ $parent_slug ], true ) ) {
			return;
		}
		$sub_order_ids[ $parent_slug ][] = $item_id;
	}

	/**
	 * Remove nested-top slugs from submenu order lists of sections they no longer belong to.
	 *
	 * @param array<string, array<int, string>> $sub_order   Parent => ordered slugs.
	 * @param array<string, string>             $nested_tops Item id => parent slug.
	 */
	private function prune_nested_slugs_from_sub_order( array &$sub_order, array $nested_tops ): void {
		foreach ( $nested_tops as $item_id => $parent_slug ) {
			$parsed = self::parse_item_id( (string) $item_id );
			$parent_slug = $this->sanitize_menu_slug( (string) $parent_slug );
			if ( ! $parsed || '' !== $parsed['parent'] || '' === $parent_slug ) {
				continue;
			}

			$slug = (string) $parsed['slug'];
			foreach ( array_keys( $sub_order ) as $section ) {
				$section = (string) $section;
				if ( $section === $parent_slug || ! is_array( $sub_order[ $section ] ?? null ) ) {
					continue;
				}

				$sub_order[ $section ] = array_values(
					array_filter(
						$sub_order[ $section ],
						function ( $saved_slug ) use ( $slug, $section ) {
							return ! $this->submenu_entry_matches_slug(
								array( '', '', (string) $saved_slug ),
								$slug,
								$section,
								true
							);
						}
					)
				);
			}
		}
	}

	/**
	 * Remove top-level plugin slugs from submenu order maps when they are no longer nested.
	 *
	 * @param array<string, array<int, string>> $sub_order   Parent => ordered slugs.
	 * @param array<int, string>                $top_order   Top-level slug order.
	 * @param array<string, string>             $nested_tops Item id => parent slug.
	 */
	private function prune_top_level_slugs_from_sub_order( array &$sub_order, array $top_order, array $nested_tops ): void {
		$nested_slugs = array();
		foreach ( $nested_tops as $item_id => $parent_slug ) {
			unset( $parent_slug );
			$parsed = self::parse_item_id( (string) $item_id );
			if ( $parsed && '' === $parsed['parent'] ) {
				$nested_slugs[ (string) $parsed['slug'] ] = true;
			}
		}

		foreach ( $top_order as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || isset( $nested_slugs[ $slug ] ) ) {
				continue;
			}

			foreach ( array_keys( $sub_order ) as $section ) {
				$section = (string) $section;
				if ( ! is_array( $sub_order[ $section ] ?? null ) ) {
					continue;
				}

				// Keep the native first submenu entry (parent slug duplicated under itself).
				if ( $this->top_level_menu_slug_matches( $section, $slug ) ) {
					continue;
				}

				$sub_order[ $section ] = array_values(
					array_filter(
						$sub_order[ $section ],
						function ( $saved_slug ) use ( $slug, $section ) {
							return ! $this->submenu_entry_matches_slug(
								array( '', '', (string) $saved_slug ),
								$slug,
								$section,
								true
							);
						}
					)
				);
			}
		}
	}

	/**
	 * Normalize a menu slug for loose comparisons (admin.php?page=foo → foo).
	 *
	 * @param string $slug Menu slug.
	 */
	private function normalize_menu_slug_for_compare( string $slug ): string {
		$slug = trim( $slug );
		if ( preg_match( '/[?&]page=([^&]+)/', $slug, $matches ) ) {
			return (string) $matches[1];
		}
		return $slug;
	}

	/**
	 * Whether a submenu entry matches a saved slug.
	 *
	 * @param array<int, mixed> $entry      Submenu entry.
	 * @param string            $order_slug Saved slug.
	 * @param string            $parent_slug Parent menu slug.
	 * @param bool              $strict      When true, only exact/normalized slug matches (safe for move/extract).
	 */
	private function submenu_entry_matches_slug( array $entry, string $order_slug, string $parent_slug = '', bool $strict = false ): bool {
		$entry_slug = '';
		if ( ! empty( $entry[2] ) ) {
			$entry_slug = (string) $entry[2];
		} elseif ( ! empty( $entry['slug'] ) ) {
			$entry_slug = (string) $entry['slug'];
		}
		if ( '' === $entry_slug ) {
			return false;
		}
		if ( $entry_slug === $order_slug ) {
			return true;
		}
		if ( $this->normalize_menu_slug_for_compare( $entry_slug ) === $this->normalize_menu_slug_for_compare( $order_slug ) ) {
			return true;
		}
		if ( $this->canonical_submenu_slug( $entry_slug ) === $this->canonical_submenu_slug( $order_slug ) ) {
			return true;
		}

		if ( $strict ) {
			return false;
		}

		// Only compare file basename when neither slug is an admin.php?page= plugin screen.
		if ( ! str_contains( $entry_slug, 'page=' ) && ! str_contains( $order_slug, 'page=' ) ) {
			$base_entry = basename( (string) strtok( $entry_slug, '?' ) );
			$base_order = basename( (string) strtok( $order_slug, '?' ) );
			if ( '' !== $base_entry && $base_entry === $base_order ) {
				return true;
			}
		}

		foreach ( $this->expand_slug_match_variants( $order_slug ) as $variant ) {
			if ( $entry_slug === $variant ) {
				return true;
			}
			if ( $this->normalize_menu_slug_for_compare( $entry_slug ) === $this->normalize_menu_slug_for_compare( $variant ) ) {
				return true;
			}
		}

		if ( '' !== $parent_slug && function_exists( 'get_plugin_page_hookname' ) ) {
			$entry_hook = get_plugin_page_hookname( $entry_slug, $parent_slug );
			$order_hook = get_plugin_page_hookname( $order_slug, $parent_slug );
			if ( '' !== $entry_hook && $entry_hook === $order_hook ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate slug variants commonly used by WordPress admin menus.
	 *
	 * @param string $slug Saved or live slug.
	 * @return array<int, string>
	 */
	private function expand_slug_match_variants( string $slug ): array {
		$slug      = trim( $slug );
		$variants  = array( $slug );
		$page_name = $this->normalize_menu_slug_for_compare( $slug );

		if ( '' !== $page_name && $page_name !== $slug ) {
			$variants[] = $page_name;
		}
		if ( '' !== $page_name && ! str_contains( $slug, 'admin.php' ) ) {
			$variants[] = 'admin.php?page=' . $page_name;
		}
		if ( str_contains( $slug, '.php' ) && ! str_contains( $slug, 'page=' ) ) {
			$variants[] = basename( $slug );
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * Candidate slugs used to match a saved item id against a live submenu entry.
	 *
	 * @param string              $item_id    Stable item id.
	 * @param array<string,string> $slug_hints Item id => slug map.
	 * @return array<int, string>
	 */
	private function get_item_id_slug_candidates( string $item_id, array $slug_hints ): array {
		$candidates = array();
		if ( ! empty( $slug_hints[ $item_id ] ) ) {
			$candidates[] = (string) $slug_hints[ $item_id ];
		}
		$parsed = self::parse_item_id( $item_id );
		if ( $parsed && '' !== $parsed['slug'] ) {
			$candidates[] = (string) $parsed['slug'];
		}
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Find a submenu entry slug key for a saved order slug.
	 *
	 * @param array<string, array<int, mixed>> $by_slug Submenu entries keyed by slug.
	 * @param string                           $order_slug Saved slug.
	 * @param string                           $parent_slug Parent menu slug.
	 * @param bool                             $strict      Use strict slug matching only.
	 */
	private function find_submenu_entry_key_for_slug( array $by_slug, string $order_slug, string $parent_slug = '', bool $strict = false ): ?string {
		if ( isset( $by_slug[ $order_slug ] ) ) {
			return $order_slug;
		}
		foreach ( $by_slug as $entry_slug => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( $this->submenu_entry_matches_slug( $entry, $order_slug, $parent_slug, $strict ) ) {
				return (string) $entry_slug;
			}
		}
		return null;
	}

	/**
	 * Sanitize posted submenu order from the browser (authoritative visual order).
	 *
	 * @param array<string, mixed>             $posted Posted sub_order map.
	 * @param array<int, array<string, mixed>> $known  Known menu rows.
	 * @return array<string, array<int, string>>
	 */
	private function sanitize_posted_sub_order( array $posted, array $known ): array {
		unset( $known );

		$clean = array();
		foreach ( $posted as $parent => $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}
			$parent = $this->sanitize_menu_slug( (string) $parent );
			if ( '' === $parent ) {
				continue;
			}
			$clean[ $parent ] = array();
			foreach ( $slugs as $slug ) {
				$slug = $this->sanitize_menu_slug( (string) $slug );
				if ( '' === $slug || in_array( $slug, $clean[ $parent ], true ) ) {
					continue;
				}
				$clean[ $parent ][] = $slug;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize posted submenu order maps keyed by stable item ids.
	 *
	 * @param array<string, mixed>             $posted Posted sub_order_ids map.
	 * @param array<int, array<string, mixed>> $known  Known menu rows.
	 * @return array{ids: array<string, array<int, string>>, slugs: array<string, array<int, string>>}
	 */
	private function sanitize_posted_sub_order_ids( array $posted, array $known ): array {
		$known_by_id = array();
		foreach ( $known as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$known_by_id[ $id ] = $row;
			}
		}

		$clean_ids   = array();
		$clean_slugs = array();
		foreach ( $posted as $parent => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$parent = $this->sanitize_menu_slug( (string) $parent );
			if ( '' === $parent ) {
				continue;
			}
			foreach ( $ids as $item_id ) {
				$item_id = sanitize_text_field( (string) $item_id );
				if ( '' === $item_id ) {
					continue;
				}

				$item_id = $this->resolve_posted_item_id( $item_id, $known );
				if ( ! isset( $known_by_id[ $item_id ] ) ) {
					continue;
				}

				$slug = (string) ( $known_by_id[ $item_id ]['slug'] ?? '' );
				if ( '' === $slug ) {
					$parsed = self::parse_item_id( $item_id );
					if ( $parsed ) {
						$slug = (string) $parsed['slug'];
					}
				}

				if ( '' === $slug ) {
					continue;
				}

				$this->append_sub_order_id( $clean_ids, $parent, $item_id );
				$this->append_sub_order_slug( $clean_slugs, $parent, $slug );
			}
		}

		return array(
			'ids'   => $clean_ids,
			'slugs' => $clean_slugs,
		);
	}

	/**
	 * Merge primary submenu id order with fallback ids (append missing only).
	 *
	 * @param array<string, array<int, string>> $primary  Preferred order.
	 * @param array<string, array<int, string>> $fallback Secondary order.
	 * @return array<string, array<int, string>>
	 */
	private function merge_sub_order_id_lists( array $primary, array $fallback ): array {
		$merged = $primary;
		foreach ( $fallback as $parent => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $item_id ) {
				$this->append_sub_order_id( $merged, (string) $parent, (string) $item_id );
			}
		}
		return $merged;
	}

	/**
	 * Merge a primary submenu order with fallback slugs (append missing only).
	 *
	 * @param array<string, array<int, string>> $primary  Preferred order.
	 * @param array<string, array<int, string>> $fallback Secondary order.
	 * @return array<string, array<int, string>>
	 */
	private function merge_sub_order_lists( array $primary, array $fallback ): array {
		$merged = $primary;
		foreach ( $fallback as $parent => $slugs ) {
			if ( ! is_array( $slugs ) ) {
				continue;
			}
			foreach ( $slugs as $slug ) {
				$this->append_sub_order_slug( $merged, (string) $parent, (string) $slug );
			}
		}
		return $merged;
	}

	/**
	 * Resolve the parent section used when saving submenu order from a posted row.
	 *
	 * @param array<string, mixed>     $row       Posted row.
	 * @param array{parent:string,slug:string} $parsed Parsed item id.
	 * @param array<string, bool>      $top_slugs Known top-level slugs.
	 */
	private function resolve_posted_order_parent( array $row, array $parsed, array $top_slugs ): string {
		if ( '' === $parsed['parent'] ) {
			$nest_under = isset( $row['nest_under'] ) ? $this->sanitize_menu_slug( (string) $row['nest_under'] ) : '';
			if ( '' !== $nest_under && isset( $top_slugs[ $nest_under ] ) && $nest_under !== $parsed['slug'] ) {
				return $nest_under;
			}
			return '';
		}

		$parent_slug = '';
		if ( ! empty( $row['effective_parent'] ) ) {
			$parent_slug = $this->sanitize_menu_slug( (string) $row['effective_parent'] );
		}
		if ( '' === $parent_slug && isset( $row['parent_slug'] ) ) {
			$parent_slug = $this->sanitize_menu_slug( (string) $row['parent_slug'] );
		}
		if ( '' === $parent_slug ) {
			$parent_slug = $this->sanitize_menu_slug( (string) $parsed['parent'] );
		}
		if ( '' === $parent_slug || ! isset( $top_slugs[ $parent_slug ] ) ) {
			return '';
		}

		return $parent_slug;
	}

	/**
	 * Rebuild top-level $menu order using saved slug list.
	 *
	 * @param array<int, mixed>  $menu        Global menu array.
	 * @param array<int, string> $order_slugs Ordered slugs.
	 * @return array<int, mixed>
	 */
	private function reorder_top_level_menu( array $menu, array $order_slugs ): array {
		$by_slug = array();
		foreach ( $menu as $position => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry[2] ) && ! $this->is_menu_separator( $entry ) ) {
				continue;
			}
			$by_slug[ $this->menu_entry_slug( $entry, (int) $position ) ] = $entry;
		}

		$new_menu = array();
		$used     = array();
		$position = 5;

		foreach ( $order_slugs as $slug ) {
			$slug = (string) $slug;
			if ( ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}
			$new_menu[ $position ] = $by_slug[ $slug ];
			$used[ $slug ]          = true;
			$position              += 5;
		}

		foreach ( $by_slug as $slug => $entry ) {
			if ( ! empty( $used[ $slug ] ) ) {
				continue;
			}
			$new_menu[ $position ] = $entry;
			$position             += 5;
		}

		return $new_menu;
	}

	/** AJAX: save menu settings. */
	public function ajax_save(): void {
		check_ajax_referer( 'tsosk_am_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['items'] ) ? json_decode( wp_unslash( (string) $_POST['items'] ), true ) : array();
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( __( 'Invalid menu data.', 'tso-swiss-knife' ) );
		}

		$known     = $this->get_known_menu_rows();
		$known_ids = array_column( $known, 'id' );
		if ( array() === $known_ids ) {
			wp_send_json_error( __( 'Could not read the admin menu. Reload this page and try again.', 'tso-swiss-knife' ) );
		}

		$top_slugs = array();
		foreach ( $known as $row ) {
			if ( 0 === (int) $row['level'] ) {
				$top_slugs[ (string) $row['slug'] ] = true;
			}
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['parent_slug'] ) ) {
				continue;
			}
			$posted_parent = $this->sanitize_menu_slug( (string) $row['parent_slug'] );
			if ( '' !== $posted_parent ) {
				$top_slugs[ $posted_parent ] = true;
			}
		}

		$protected = array();
		foreach ( $known as $row ) {
			if ( ! empty( $row['protected'] ) ) {
				$protected[ $row['id'] ] = true;
			}
		}

		$hidden       = array();
		$labels       = array();
		$top_order    = array();
		$sub_order    = array();
		$sub_order_ids = array();
		$relocations  = array();
		$nested_tops  = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
			$id = $this->resolve_posted_item_id( $id, $known );
			if ( ! in_array( $id, $known_ids, true ) ) {
				continue;
			}

			$visible = ! empty( $row['visible'] );
			$label   = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

			if ( ! $visible && empty( $protected[ $id ] ) ) {
				$hidden[] = $id;
			}
			if ( '' !== $label ) {
				$labels[ $id ] = $label;
			}
		}

		usort(
			$raw,
			static function ( $a, $b ) {
				return (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
			}
		);

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$id = $this->resolve_posted_item_id( sanitize_text_field( (string) $row['id'] ), $known );
			if ( ! in_array( $id, $known_ids, true ) ) {
				continue;
			}
			$parsed = self::parse_item_id( $id );
			if ( ! $parsed ) {
				continue;
			}

			$row_slug = isset( $row['slug'] ) ? $this->sanitize_menu_slug( (string) $row['slug'] ) : '';
			if ( '' === $row_slug ) {
				$row_slug = (string) $parsed['slug'];
			}

			if ( '' === $parsed['parent'] ) {
				$nest_under = isset( $row['nest_under'] ) ? $this->sanitize_menu_slug( (string) $row['nest_under'] ) : '';
				if ( '' !== $nest_under && isset( $top_slugs[ $nest_under ] ) && $nest_under !== $row_slug ) {
					$nested_tops[ $id ] = $nest_under;
					$this->append_sub_order_slug( $sub_order, $nest_under, $row_slug );
					$this->append_sub_order_id( $sub_order_ids, $nest_under, $id );
				} else {
					$top_order[] = $row_slug;
				}
				continue;
			}

			$orig_parent = isset( $row['orig_parent'] )
				? $this->sanitize_menu_slug( (string) $row['orig_parent'] )
				: (string) $parsed['parent'];
			$parent_slug = $this->resolve_posted_order_parent( $row, $parsed, $top_slugs );

			if ( '' === $parent_slug ) {
				continue;
			}

			if ( $parent_slug !== $orig_parent ) {
				$relocations[ $id ] = $parent_slug;
			}

			$this->append_sub_order_slug( $sub_order, $parent_slug, $row_slug );
			$this->append_sub_order_id( $sub_order_ids, $parent_slug, $id );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted_sub_order_ids = isset( $_POST['sub_order_ids'] ) ? json_decode( wp_unslash( (string) $_POST['sub_order_ids'] ), true ) : null;
		if ( is_array( $posted_sub_order_ids ) && array() !== $posted_sub_order_ids ) {
			$validated_ids = $this->sanitize_posted_sub_order_ids( $posted_sub_order_ids, $known );
			if ( array() !== $validated_ids['ids'] ) {
				$sub_order_ids = $validated_ids['ids'];
				foreach ( $validated_ids['slugs'] as $parent => $slugs ) {
					if ( is_array( $slugs ) && array() !== $slugs ) {
						$sub_order[ (string) $parent ] = $slugs;
					}
				}
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted_sub_order = isset( $_POST['sub_order'] ) ? json_decode( wp_unslash( (string) $_POST['sub_order'] ), true ) : null;
		if ( is_array( $posted_sub_order ) && array() !== $posted_sub_order ) {
			$validated = $this->sanitize_posted_sub_order( $posted_sub_order, $known );
			if ( array() !== $validated ) {
				foreach ( $validated as $parent => $slugs ) {
					if ( isset( $sub_order[ $parent ] ) && array() !== $sub_order[ $parent ] ) {
						continue;
					}
					$sub_order[ $parent ] = $slugs;
				}
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted_top_order = isset( $_POST['top_order'] ) ? json_decode( wp_unslash( (string) $_POST['top_order'] ), true ) : null;
		if ( is_array( $posted_top_order ) && array() !== $posted_top_order ) {
			$validated_top = array();
			foreach ( $posted_top_order as $slug ) {
				$slug = $this->sanitize_menu_slug( (string) $slug );
				if ( '' !== $slug && isset( $top_slugs[ $slug ] ) && ! in_array( $slug, $validated_top, true ) ) {
					$validated_top[] = $slug;
				}
			}
			if ( array() !== $validated_top ) {
				foreach ( $top_order as $slug ) {
					if ( ! in_array( $slug, $validated_top, true ) ) {
						$validated_top[] = $slug;
					}
				}
				$top_order = $validated_top;
			}
		}

		$this->prune_nested_slugs_from_sub_order( $sub_order, $nested_tops );
		$this->prune_top_level_slugs_from_sub_order( $sub_order, $top_order, $nested_tops );

		$clean_settings = $this->normalize_stored_submenu_settings(
			array(
				'sub_order'     => $sub_order,
				'sub_order_ids' => $sub_order_ids,
			)
		);
		$sub_order     = $clean_settings['sub_order'];
		$sub_order_ids = $clean_settings['sub_order_ids'];

		update_option(
			self::OPTION,
			array(
				'hidden'         => array_values( array_unique( $hidden ) ),
				'labels'         => $labels,
				'top_order'      => array_values( array_unique( $top_order ) ),
				'sub_order'      => $sub_order,
				'sub_order_ids'  => $sub_order_ids,
				'relocations'    => $relocations,
				'nested_tops'    => $nested_tops,
			),
			false
		);

		$this->persist_menu_manifest( $known );

		TSOSK_Activity_Log::log(
			'admin-menu',
			'save',
			sprintf(
				/* translators: 1: hidden items count, 2: renamed items count, 3: relocated items count */
				__( 'Sidebar menu saved: %1$d hidden, %2$d renamed, %3$d relocated.', 'tso-swiss-knife' ),
				count( $hidden ),
				count( $labels ),
				count( $relocations )
			)
		);

		wp_send_json_success(
			array(
				'message'         => __( 'Sidebar menu saved. Reloading…', 'tso-swiss-knife' ),
				'relocated'       => count( $relocations ),
				'hidden'          => count( $hidden ),
				'renamed'         => count( $labels ),
				'sub_order_count' => count( $sub_order, COUNT_RECURSIVE ) - count( $sub_order ),
				'tools_sub_count' => isset( $sub_order_ids['tools.php'] ) ? count( $sub_order_ids['tools.php'] ) : 0,
				'tools_sub_slugs' => isset( $sub_order['tools.php'] ) ? count( $sub_order['tools.php'] ) : 0,
				'tools_slug_first' => isset( $sub_order['tools.php'][0] ) ? (string) $sub_order['tools.php'][0] : '',
				'tools_slug_second' => isset( $sub_order['tools.php'][1] ) ? (string) $sub_order['tools.php'][1] : '',
				'tools_id_first'   => isset( $sub_order_ids['tools.php'][0] ) ? (string) $sub_order_ids['tools.php'][0] : '',
			)
		);
	}

	/** AJAX: reset menu settings. */
	public function ajax_reset(): void {
		check_ajax_referer( 'tsosk_am_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'tso-swiss-knife' ), 403 );
		}
		delete_option( self::OPTION );
		TSOSK_Activity_Log::log(
			'admin-menu',
			'reset',
			__( 'Sidebar menu reset to WordPress defaults.', 'tso-swiss-knife' )
		);
		wp_send_json_success( __( 'Sidebar menu reset to WordPress defaults.', 'tso-swiss-knife' ) );
	}

	/**
	 * Render a single admin-menu editor row.
	 *
	 * @param array<string, mixed>  $row              Row data.
	 * @param array<string, true>   $hidden           Hidden item ids.
	 * @param array<string, string> $labels           Custom labels.
	 * @param array<string, string> $top_choices      Parent section choices.
	 * @param array<string, int>    $sub_counts       Visible sub-item counts.
	 * @param bool                  $in_hidden_panel  Whether this row lives in the hidden panel.
	 * @param string                $nest_under       Parent slug when a top-level item is nested under another section.
	 */
	private function render_menu_row( array $row, array $hidden, array $labels, array $top_choices, array $sub_counts, bool $in_hidden_panel = false, string $nest_under = '' ): void {
		$id               = $row['id'];
		$is_hidden        = isset( $hidden[ $id ] );
		$label            = $labels[ $id ] ?? '';
		$is_nested_top    = ! empty( $row['nested_top'] );
		$is_top           = 0 === (int) $row['level'] && ! $is_nested_top;
		$is_separator     = ! empty( $row['is_separator'] );
		$effective_parent = $row['effective_parent'] ?? $row['parent_slug'];
		$sub_count        = $is_top ? (int) ( $sub_counts[ $row['slug'] ] ?? 0 ) : 0;
		$has_children     = $is_top && $sub_count > 0 && ! $in_hidden_panel;
		$row_class        = $is_top ? 'tsosk-am-row tsosk-am-row-top' : 'tsosk-am-row tsosk-am-row-sub';

		if ( $is_nested_top ) {
			$row_class .= ' tsosk-am-row-nested-top';
		}

		if ( $has_children ) {
			$row_class .= ' tsosk-am-has-children';
		}
		if ( ! $is_top && ! $in_hidden_panel ) {
			$row_class .= ' is-am-collapsed';
		}
		if ( $is_hidden || $in_hidden_panel ) {
			$row_class .= ' tsosk-am-row-is-hidden';
		}
		if ( $in_hidden_panel ) {
			$row_class .= ' tsosk-am-row-hidden-panel';
		}
		?>
		<tr class="<?php echo esc_attr( $row_class ); ?>"
		    data-id="<?php echo esc_attr( $id ); ?>"
		    data-slug="<?php echo esc_attr( $row['slug'] ); ?>"
		    data-level="<?php echo esc_attr( (string) $row['level'] ); ?>"
		    data-parent-id="<?php echo esc_attr( $row['parent_id'] ); ?>"
		    data-orig-parent="<?php echo esc_attr( $row['parent_slug'] ); ?>"
		    <?php if ( $is_top ) : ?>
		    data-am-section="<?php echo esc_attr( $row['slug'] ); ?>"
		    <?php else : ?>
		    data-am-parent-section="<?php echo esc_attr( (string) $effective_parent ); ?>"
		    <?php if ( ! $in_hidden_panel ) : ?>
		    hidden
		    <?php endif; ?>
		    <?php endif; ?>>
			<td class="tsosk-am-drag">
				<?php if ( ! $in_hidden_panel ) : ?>
				<span class="dashicons dashicons-menu tsosk-am-handle"
				      title="<?php esc_attr_e( 'Drag to reorder', 'tso-swiss-knife' ); ?>"></span>
				<?php endif; ?>
			</td>
			<td class="tsosk-am-title-cell">
				<?php if ( $is_top ) : ?>
					<?php if ( $has_children ) : ?>
					<button type="button"
					        class="tsosk-am-toggle"
					        aria-expanded="false"
					        aria-controls="tsosk-am-section-<?php echo esc_attr( $row['slug'] ); ?>"
					        title="<?php esc_attr_e( 'Show or hide sub-items', 'tso-swiss-knife' ); ?>">
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: main menu section title */
								esc_html__( 'Toggle sub-items for %s', 'tso-swiss-knife' ),
								esc_html( $row['title'] )
							);
							?>
						</span>
					</button>
					<?php else : ?>
					<span class="tsosk-am-toggle-spacer" aria-hidden="true"></span>
					<?php endif; ?>
					<?php if ( $is_separator ) : ?>
					<span class="tsosk-am-top-label tsosk-am-separator-label">
						<span class="dashicons dashicons-minus" aria-hidden="true"></span>
						<?php echo esc_html( $row['title'] ); ?>
					</span>
					<?php else : ?>
					<span class="tsosk-am-top-label"><?php echo esc_html( $row['title'] ); ?></span>
					<?php endif; ?>
					<?php if ( $has_children ) : ?>
					<span class="tsosk-am-sub-count">
						<?php
						printf(
							/* translators: %d: number of sub-items */
							esc_html( _n( '%d sub-item', '%d sub-items', $sub_count, 'tso-swiss-knife' ) ),
							(int) $sub_count
						);
						?>
					</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="tsosk-am-sub-label">
						<?php echo esc_html( $row['title'] ); ?>
						<?php if ( $is_nested_top ) : ?>
						<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Plugin menu', 'tso-swiss-knife' ); ?></span>
						<?php endif; ?>
					</span>
				<?php endif; ?>
				<?php if ( ! empty( $row['protected'] ) ) : ?>
					<span class="tsosk-badge tsosk-badge-info"><?php esc_html_e( 'Protected', 'tso-swiss-knife' ); ?></span>
				<?php endif; ?>
				<?php if ( $is_hidden || $in_hidden_panel ) : ?>
					<span class="tsosk-badge tsosk-badge-warn"><?php esc_html_e( 'Hidden', 'tso-swiss-knife' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="tsosk-am-parent-cell">
				<?php if ( $is_top && ! $is_separator ) : ?>
					<select class="tsosk-am-top-nest" aria-label="<?php esc_attr_e( 'Show as main section or under another menu', 'tso-swiss-knife' ); ?>">
						<option value=""><?php esc_html_e( 'Standalone (top level menu)', 'tso-swiss-knife' ); ?></option>
						<?php foreach ( $top_choices as $parent_slug => $parent_title ) : ?>
							<?php if ( $parent_slug === $row['slug'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<option value="<?php echo esc_attr( $parent_slug ); ?>" <?php selected( $nest_under, $parent_slug ); ?>>
								<?php echo esc_html( $parent_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( $is_nested_top ) : ?>
					<select class="tsosk-am-top-nest" aria-label="<?php esc_attr_e( 'Show as main section or under another menu', 'tso-swiss-knife' ); ?>">
						<option value=""><?php esc_html_e( 'Standalone (top level menu)', 'tso-swiss-knife' ); ?></option>
						<?php foreach ( $top_choices as $parent_slug => $parent_title ) : ?>
							<?php if ( $parent_slug === $row['slug'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<option value="<?php echo esc_attr( $parent_slug ); ?>" <?php selected( $effective_parent, $parent_slug ); ?>>
								<?php echo esc_html( $parent_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( $is_top ) : ?>
					<span class="tsosk-am-section-tag"><?php esc_html_e( 'Main section', 'tso-swiss-knife' ); ?></span>
				<?php else : ?>
					<select class="tsosk-am-parent" aria-label="<?php esc_attr_e( 'Move under section', 'tso-swiss-knife' ); ?>">
						<?php foreach ( $top_choices as $parent_slug => $parent_title ) : ?>
							<option value="<?php echo esc_attr( $parent_slug ); ?>" <?php selected( $effective_parent, $parent_slug ); ?>>
								<?php echo esc_html( $parent_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</td>
			<td class="tsosk-am-hide-cell">
				<input type="checkbox" class="tsosk-am-hide"
				       <?php checked( $is_hidden || $in_hidden_panel ); ?>
				       <?php disabled( ! empty( $row['protected'] ) ); ?>
				       aria-label="<?php esc_attr_e( 'Hide this menu item', 'tso-swiss-knife' ); ?>">
			</td>
			<td class="tsosk-am-label-cell">
				<?php if ( $is_separator ) : ?>
					<span class="tsosk-am-separator-note description"><?php esc_html_e( 'Separators cannot be renamed.', 'tso-swiss-knife' ); ?></span>
				<?php else : ?>
				<input type="text" class="tsosk-am-label"
				       value="<?php echo esc_attr( $label ); ?>"
				       placeholder="<?php echo esc_attr( $row['title'] ); ?>"
				       <?php disabled( ( $is_hidden || $in_hidden_panel ) && empty( $row['protected'] ) ); ?>>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Debug panel visible on this module page when ?tsosk_am_debug=1 is present.
	 *
	 * @param array<string, mixed> $settings Saved menu settings.
	 */
	private function maybe_render_debug_panel( array $settings ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['tsosk_am_debug'] ) ) {
			return;
		}

		$saved_tools = is_array( $settings['sub_order']['tools.php'] ?? null ) ? $settings['sub_order']['tools.php'] : array();
		$saved_ids   = is_array( $settings['sub_order_ids']['tools.php'] ?? null ) ? $settings['sub_order_ids']['tools.php'] : array();
		$live_tools  = array();
		if ( is_array( $GLOBALS['submenu']['tools.php'] ?? null ) ) {
			foreach ( $GLOBALS['submenu']['tools.php'] as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry[2] ) ) {
					$live_tools[] = (string) $entry[2];
				}
			}
		}

		$matched = 0;
		foreach ( $saved_tools as $slug ) {
			foreach ( $live_tools as $live_slug ) {
				if ( $this->submenu_entry_matches_slug( array( '', '', $live_slug ), (string) $slug, 'tools.php' ) ) {
					++$matched;
					break;
				}
			}
		}

		?>
		<div class="tsosk-notice tsosk-notice-warning" style="margin:12px 0;padding:12px;border-left:4px solid #dba617;">
			<p><strong><?php esc_html_e( 'TSO Admin Menu — debug mode', 'tso-swiss-knife' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: 1: matched slug count, 2: saved slug count, 3: live slug count */
					esc_html__( 'Tools submenu: %1$d of %2$d saved slugs match the live sidebar (%3$d items live).', 'tso-swiss-knife' ),
					(int) $matched,
					count( $saved_tools ),
					count( $live_tools )
				);
				?>
			</p>
			<p><strong><?php esc_html_e( 'Saved order (first 6):', 'tso-swiss-knife' ); ?></strong>
				<?php echo esc_html( implode( ' | ', array_slice( $saved_tools, 0, 6 ) ) ); ?></p>
			<p><strong><?php esc_html_e( 'Saved item ids (first 6):', 'tso-swiss-knife' ); ?></strong>
				<?php echo esc_html( implode( ' | ', array_slice( $saved_ids, 0, 6 ) ) ); ?></p>
			<p><strong><?php esc_html_e( 'Live sidebar (first 6):', 'tso-swiss-knife' ); ?></strong>
				<?php echo esc_html( implode( ' | ', array_slice( $live_tools, 0, 6 ) ) ); ?></p>
			<p class="description"><?php esc_html_e( 'Compare the sidebar on Dashboard or any admin page after saving. Reload this page with the same ?tsosk_am_debug=1 parameter to refresh these values.', 'tso-swiss-knife' ); ?></p>
		</div>
		<?php
	}

	public function render(): void {
		$nonce        = wp_create_nonce( 'tsosk_am_nonce' );
		$settings     = self::get_settings();
		$hidden       = array_flip( $settings['hidden'] );
		$labels       = $settings['labels'];
		$snapshot     = $this->get_menu_snapshot();
		$this->persist_menu_manifest( $snapshot );
		$display_rows = $this->get_display_rows();

		// Auto-repair legacy corrupted submenu slugs in the database once detected.
		$repaired   = $this->normalize_stored_submenu_settings( $settings );
		$needs_save = ( $repaired['sub_order'] ?? array() ) !== ( $settings['sub_order'] ?? array() )
			|| ( $repaired['sub_order_ids'] ?? array() ) !== ( $settings['sub_order_ids'] ?? array() );
		if ( $needs_save ) {
			update_option( self::OPTION, $repaired, false );
			$settings     = $repaired;
			$display_rows = $this->get_display_rows();
		}
		$top_choices      = $this->get_top_level_choices();
		$hidden_count     = count( $settings['hidden'] ?? array() );
		$hidden_panel_rows = $this->get_hidden_panel_rows( $settings['hidden'] ?? array(), $snapshot );
		?>
		<p class="tsosk-desc">
			<?php esc_html_e( 'Choose which items appear in the WordPress admin sidebar, change their order by dragging, rename labels, and move submenus under another section. Changes apply to all admin users.', 'tso-swiss-knife' ); ?>
		</p>

		<div class="tsosk-notice tsosk-notice-info">
			<?php esc_html_e( 'Main sections (Posts, Media, Plugins…) are collapsed by default. Click the arrow on a section to show its sub-items. Drag to reorder. Use “Under” to move a submenu, or choose a parent in “Main section (top level)” to place a plugin menu inside another section (e.g. a standalone plugin under Tools).', 'tso-swiss-knife' ); ?>
		</div>

		<?php $this->maybe_render_debug_panel( $settings ); ?>

		<div class="tsosk-card">
			<h3><?php esc_html_e( 'Sidebar menu items', 'tso-swiss-knife' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Main sections are collapsed by default — click the arrow to show sub-items. Drag the handle to reorder. Use “Hide” to remove items from the sidebar.', 'tso-swiss-knife' ); ?></p>

			<p class="tsosk-am-bulk-toggle" style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap;">
				<button type="button" class="button button-small" id="tsosk-am-expand-all">
					<?php esc_html_e( 'Expand all sections', 'tso-swiss-knife' ); ?>
				</button>
				<button type="button" class="button button-small" id="tsosk-am-collapse-all">
					<?php esc_html_e( 'Collapse all sections', 'tso-swiss-knife' ); ?>
				</button>
				<?php if ( $hidden_count > 0 ) : ?>
				<button type="button" class="button button-small" id="tsosk-am-show-hidden"
				        data-count="<?php echo esc_attr( (string) $hidden_count ); ?>">
					<?php
					printf(
						/* translators: %d: number of hidden menu items */
						esc_html( _n( 'Show %d hidden item', 'Show %d hidden items', $hidden_count, 'tso-swiss-knife' ) ),
						(int) $hidden_count
					);
					?>
				</button>
				<?php endif; ?>
			</p>

			<?php if ( $hidden_count > 0 ) : ?>
			<p class="description tsosk-am-hidden-hint">
				<?php esc_html_e( 'Hidden items are listed in the section below. Uncheck “Hide” and save to show them again in the sidebar.', 'tso-swiss-knife' ); ?>
			</p>
			<?php endif; ?>

			<div class="tsosk-table-wrap tsosk-am-wrap">
				<table class="tsosk-table tsosk-am-table" id="tsosk-am-table">
					<thead>
						<tr>
							<th class="tsosk-am-col-drag" aria-hidden="true"></th>
							<th class="tsosk-am-col-title"><?php esc_html_e( 'Menu item', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-am-col-parent"><?php esc_html_e( 'Under', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-am-col-hide"><?php esc_html_e( 'Hide', 'tso-swiss-knife' ); ?></th>
							<th class="tsosk-am-col-label"><?php esc_html_e( 'Custom label', 'tso-swiss-knife' ); ?></th>
						</tr>
					</thead>
					<tbody id="tsosk-am-tbody">
						<?php
						$sub_counts = array();
						foreach ( $display_rows as $count_row ) {
							if ( 1 !== (int) ( $count_row['level'] ?? 0 ) && empty( $count_row['nested_top'] ) ) {
								continue;
							}
							if ( isset( $hidden[ $count_row['id'] ] ) ) {
								continue;
							}
							$parent_key = (string) ( $count_row['effective_parent'] ?? $count_row['parent_slug'] ?? '' );
							if ( '' === $parent_key ) {
								continue;
							}
							$sub_counts[ $parent_key ] = ( $sub_counts[ $parent_key ] ?? 0 ) + 1;
						}
						foreach ( $display_rows as $row ) :
							$row_id = (string) $row['id'];
							if ( isset( $hidden[ $row_id ] ) ) {
								continue;
							}
							if ( ! empty( $row['is_separator'] ) && isset( $hidden['t:'] ) ) {
								continue;
							}
							$row_nest = (string) ( $settings['nested_tops'][ $row_id ] ?? '' );
							$this->render_menu_row( $row, $hidden, $labels, $top_choices, $sub_counts, false, $row_nest );
						endforeach;
						?>
					</tbody>
				</table>
			</div>

			<?php if ( $hidden_count > 0 ) : ?>
			<div class="tsosk-am-hidden-section" id="tsosk-am-hidden-section">
				<h4 class="tsosk-am-hidden-title">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<?php esc_html_e( 'Hidden items', 'tso-swiss-knife' ); ?>
				</h4>
				<div class="tsosk-table-wrap tsosk-am-wrap">
					<table class="tsosk-table tsosk-am-table tsosk-am-hidden-table">
						<thead>
							<tr>
								<th class="tsosk-am-col-drag" aria-hidden="true"></th>
								<th class="tsosk-am-col-title"><?php esc_html_e( 'Menu item', 'tso-swiss-knife' ); ?></th>
								<th class="tsosk-am-col-parent"><?php esc_html_e( 'Under', 'tso-swiss-knife' ); ?></th>
								<th class="tsosk-am-col-hide"><?php esc_html_e( 'Hide', 'tso-swiss-knife' ); ?></th>
								<th class="tsosk-am-col-label"><?php esc_html_e( 'Custom label', 'tso-swiss-knife' ); ?></th>
							</tr>
						</thead>
						<tbody id="tsosk-am-hidden-tbody">
							<?php if ( empty( $hidden_panel_rows ) ) : ?>
							<tr class="tsosk-am-hidden-empty">
								<td colspan="5">
									<?php esc_html_e( 'Hidden items could not be loaded. Reload this page, or reset the menu to defaults if the problem persists.', 'tso-swiss-knife' ); ?>
								</td>
							</tr>
							<?php else : ?>
							<?php
							foreach ( $hidden_panel_rows as $row ) :
								$this->render_menu_row( $row, $hidden, $labels, $top_choices, $sub_counts, true );
							endforeach;
							?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<p style="margin-top:16px;">
				<button type="button" class="button button-primary" id="tsosk-am-save"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Save sidebar menu', 'tso-swiss-knife' ); ?>
				</button>
				<button type="button" class="button" id="tsosk-am-reset"
				        data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Reset to defaults', 'tso-swiss-knife' ); ?>
				</button>
				<span class="tsosk-ajax-msg" id="tsosk-am-msg"></span>
			</p>
		</div>
		<?php
	}
}
