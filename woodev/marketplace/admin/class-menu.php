<?php
/**
 * A marketplace plugin's wp-admin menu: one root and its pages.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Menu::class, false ) ) :

	/**
	 * The shape of one marketplace menu, and everything derived from it: the `wc_admin_register_page()` arguments, the page paths,
	 * the page manifest the React bundle reads, the icon rule and the v1 legacy URLs.
	 *
	 * It does not call WooCommerce; {@see Admin_Collector} does, with the arguments this class builds. That keeps every decision
	 * here testable without a WordPress (calling `wc_admin_register_page()` from a unit test would leak its existence into every
	 * later test of the run).
	 *
	 * @since 2.0.29
	 */
	final class Menu {

		/**
		 * Capability used when the menu names none.
		 */
		public const DEFAULT_CAPABILITY = 'manage_woocommerce';

		/**
		 * WooCommerce turns a registered path into the `page` value of the menu entry: `wc-admin&path=<path>`
		 * (`PageController::register_page()`). The root entry's slug, its DOM id and its submenu entries all derive from it.
		 */
		private const WC_ADMIN_PAGE_PREFIX = 'wc-admin&path=';

		/**
		 * @var string
		 */
		private $id;

		/**
		 * @var string
		 */
		private $title;

		/**
		 * @var string
		 */
		private $icon;

		/**
		 * @var float|int|null
		 */
		private $position;

		/**
		 * @var string
		 */
		private $capability;

		/**
		 * @var Page[] Pages in menu order, keyed by slug.
		 */
		private $pages = [];

		/**
		 * @var array<string,string> View handle => script handle of the plugin's own React views.
		 */
		private $views;

		/**
		 * @var string
		 */
		private $plugin_id;

		/**
		 * @param string               $plugin_id  Id of the plugin that owns the menu.
		 * @param array<string,mixed>  $definition Menu definition: `id`, `title`, `icon`, `position`, `capability`.
		 * @param Page[]               $pages      Pages in menu order.
		 * @param array<string,string> $views      Plugin view handle => script handle.
		 * @throws \InvalidArgumentException When the definition or the pages cannot form a menu.
		 */
		public function __construct( string $plugin_id, array $definition, array $pages, array $views = [] ) {
			$this->plugin_id = $plugin_id;
			$this->id        = isset( $definition['id'] ) ? (string) $definition['id'] : '';
			$this->title     = isset( $definition['title'] ) ? trim( (string) $definition['title'] ) : '';
			$this->icon      = isset( $definition['icon'] ) ? (string) $definition['icon'] : '';

			if ( 1 !== preg_match( Page::SLUG_PATTERN, $this->id ) ) {
				throw new \InvalidArgumentException( 'A marketplace menu id must be lower-case words joined by dashes.' );
			}

			if ( '' === $this->title ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace menu "%s" has no title.', $this->id ) );
			}

			if ( isset( $definition['position'] ) && ! is_numeric( $definition['position'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace menu "%s": the position must be a number.', $this->id ) );
			}

			$this->position   = isset( $definition['position'] ) ? $definition['position'] + 0 : null;
			$this->capability = isset( $definition['capability'] ) && '' !== (string) $definition['capability']
				? (string) $definition['capability']
				: self::DEFAULT_CAPABILITY;
			$this->views      = $views;

			foreach ( $pages as $page ) {
				if ( isset( $this->pages[ $page->get_slug() ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Marketplace menu "%s" declares the page "%s" twice.', $this->id, $page->get_slug() ) );
				}

				$this->pages[ $page->get_slug() ] = $page;
			}

			if ( [] === $this->pages ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace menu "%s" has no pages.', $this->id ) );
			}
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_plugin_id(): string {
			return $this->plugin_id;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_title(): string {
			return $this->title;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string The capability the menu's pages inherit.
		 */
		public function get_capability(): string {
			return $this->capability;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return Page[]
		 */
		public function get_pages(): array {
			return array_values( $this->pages );
		}

		/**
		 * @since 2.0.29
		 *
		 * @param string $slug Page slug.
		 * @return Page|null
		 */
		public function get_page( string $slug ): ?Page {
			return $this->pages[ $slug ] ?? null;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return array<string,string> Plugin view handle => script handle.
		 */
		public function get_views(): array {
			return $this->views;
		}

		/**
		 * The page the root opens: the menu's first page. WordPress links a top-level entry to the first entry of its submenu
		 * (`_wp_menu_output()`), so the order the plugin builds the menu in decides it: «Настройки» while the plugin is not
		 * configured, «Товары» once it is (`Marketplace_Plugin::build_menu()`).
		 *
		 * @since 2.0.29
		 *
		 * @return Page
		 */
		public function get_landing_page(): Page {
			return reset( $this->pages );
		}

		/**
		 * Router path of a page. PHP builds every path, so the manifest and the registration cannot disagree.
		 *
		 * @since 2.0.29
		 *
		 * @param Page $page A page of this menu.
		 * @return string e.g. `/ozon-seller/products`.
		 */
		public function get_page_path( Page $page ): string {
			return '/' . $this->id . '/' . $page->get_slug();
		}

		/**
		 * The root's router path: `/{menu id}`, a container of its own that no page shares — the way WooCommerce's `woocommerce`
		 * root is a container, not a page. WordPress copies the root into its submenu as the first entry; that copy is removed
		 * ({@see self::remove_root_entry()}), and the root then links to the first real page.
		 *
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_root_path(): string {
			return '/' . $this->id;
		}

		/**
		 * The `page` value WordPress files the root under (and the root's submenu entries use).
		 *
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_root_slug(): string {
			return self::WC_ADMIN_PAGE_PREFIX . $this->get_root_path();
		}

		/**
		 * Id of the root `<li>` in the admin menu. WordPress derives it from the hook name, replacing every character outside
		 * `[a-zA-Z0-9_:.]` with a dash (`_wp_menu_output()`); WooCommerce's `wpOpenMenu` and the icon rule both address it.
		 *
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_root_dom_id(): string {
			return (string) preg_replace( '|[^a-zA-Z0-9_:.]|', '-', 'toplevel_page_' . $this->get_root_slug() );
		}

		/**
		 * Arguments of the root's `wc_admin_register_page()` call: no `parent`, so WooCommerce registers a top-level menu entry
		 * (`PageController::register_page()` → `add_menu_page()`), with this menu's icon and position.
		 *
		 * `title` and `page_title` are both passed: WooCommerce copies `title` into the browser-tab title when `page_title` is empty.
		 *
		 * @since 2.0.29
		 *
		 * @return array<string,mixed>
		 */
		public function build_root_args(): array {
			$args = [
				'id'         => $this->get_root_id(),
				'title'      => $this->title,
				'page_title' => $this->title,
				'path'       => $this->get_root_path(),
				'capability' => $this->capability,
				'icon'       => $this->icon,
			];

			if ( null !== $this->position ) {
				$args['position'] = $this->position;
			}

			return $args;
		}

		/**
		 * Arguments of one child's `wc_admin_register_page()` call, `parent` = the root's registered id.
		 *
		 * @since 2.0.29
		 *
		 * @param Page $page A page of this menu.
		 * @return array<string,mixed>
		 */
		public function build_page_args( Page $page ): array {
			return [
				'id'         => $this->get_page_id( $page ),
				'title'      => $page->get_title(),
				'page_title' => $page->get_heading(),
				'parent'     => $this->get_root_id(),
				'path'       => $this->get_page_path( $page ),
				'capability' => $page->get_capability( $this->capability ),
			];
		}

		/**
		 * Every `wc_admin_register_page()` call of the menu, root first, children in menu order.
		 *
		 * @since 2.0.29
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function build_registrations(): array {
			$registrations = [ $this->build_root_args() ];

			foreach ( $this->pages as $page ) {
				$registrations[] = $this->build_page_args( $page );
			}

			return $registrations;
		}

		/**
		 * Removes the copy of the root that WordPress puts into its submenu.
		 *
		 * `add_submenu_page()` files the root as the first entry of its own submenu as soon as the first child is added. It has
		 * the root's slug, which no page shares ({@see self::get_root_path()}), so it is a second line carrying the menu's title.
		 * WooCommerce removes the same copy of its own root with `unset( $submenu['woocommerce'][0] )`
		 * (`WC_Admin_Menus::menu_order_count()`); this removes the entry by its slug instead of by position, so a menu where
		 * WordPress made no copy loses nothing. The root then links to the first entry that is left (`_wp_menu_output()`).
		 *
		 * @since 2.0.29
		 *
		 * @param array<int|string,array<int,string>> $entries The root's entries in `$submenu`.
		 * @return array<int|string,array<int,string>>
		 */
		public function remove_root_entry( array $entries ): array {
			$slug = $this->get_root_slug();

			foreach ( $entries as $key => $entry ) {
				if ( isset( $entry[2] ) && $slug === $entry[2] ) {
					unset( $entries[ $key ] );
				}
			}

			return $entries;
		}

		/**
		 * CSS for this root: the icon at the size WordPress gives its own.
		 *
		 * An image-URL icon is drawn at its natural size unless the rule is printed (v1 printed the same 18 px rule). It is
		 * scoped to this root's id, so another marketplace's icon is not touched.
		 *
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function build_menu_css(): string {
			$root = '#' . $this->get_root_dom_id();

			return $root . ' .wp-menu-image img{width:18px;height:18px;opacity:.6}';
		}

		/**
		 * The menu as the React bundle reads it, limited to what the user may open.
		 *
		 * The bundle adds one route per entry; a page the user may not open has no route. Titles and breadcrumbs are built here,
		 * so the bundle holds no copy (Rule 9).
		 *
		 * @since 2.0.29
		 *
		 * @param callable $can Receives a capability, returns whether the current user holds it.
		 * @return array<string,mixed>|null Null when the user may open no page of the menu.
		 */
		public function build_manifest( callable $can ): ?array {
			$entries = [];
			$landing = $this->get_page_path( $this->get_landing_page() );

			foreach ( $this->pages as $page ) {
				if ( ! $can( $page->get_capability( $this->capability ) ) ) {
					continue;
				}

				$entries[] = [
					'id'          => $page->get_slug(),
					'title'       => $page->get_title(),
					'path'        => $this->get_page_path( $page ),
					'kind'        => $page->get_kind(),
					'tables'      => $page->get_tables(),
					'view'        => $page->get_view(),
					'breadcrumbs' => [ [ $landing, $this->title ], $page->get_title() ],
					'analytics'   => null,
				];
			}

			if ( [] === $entries ) {
				return null;
			}

			return [
				'id'       => $this->id,
				'pluginId' => $this->plugin_id,
				'title'    => $this->title,
				'domId'    => $this->get_root_dom_id(),
				'pages'    => $entries,
			];
		}

		/**
		 * The page a v1 request is for, when any.
		 *
		 * @since 2.0.29
		 *
		 * @param array<string,string> $request_args The request's parsed query arguments.
		 * @return Page|null
		 */
		public function find_page_for_legacy_request( array $request_args ): ?Page {
			foreach ( $this->pages as $page ) {
				if ( $page->matches_legacy_request( $request_args ) ) {
					return $page;
				}
			}

			return null;
		}

		/**
		 * @return string WooCommerce id of the root page.
		 */
		private function get_root_id(): string {
			return 'woodev-marketplace-' . $this->id;
		}

		/**
		 * @param Page $page A page of this menu.
		 * @return string WooCommerce id of the child page.
		 */
		private function get_page_id( Page $page ): string {
			return $this->get_root_id() . '-' . $page->get_slug();
		}
	}

endif;
