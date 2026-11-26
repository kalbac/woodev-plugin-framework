<?php
/**
 * The shared «Заказы маркетплейсов» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * One page under the WooCommerce menu, next to «Заказы доставки», for the orders of every active marketplace plugin. It hosts the
 * `orders` table each plugin already registers with the records engine ({@see Site_Orders_Module}), one marketplace at a time, chosen
 * by the `marketplace` URL argument; the table, its filters, actions and REST routes stay the plugin's own.
 *
 * Each plugin keeps its «Заказы» page so that nothing a plugin declares changes: its menu entry becomes a link to this page with the
 * plugin's marketplace preset, its URL ({@see self::url_query()}, used by `get_marketplace_page_url()` and the v1 redirects) leads
 * here, and its old route forwards here in the browser (`movedTo` in the manifest).
 *
 * Like {@see Menu}, it builds arguments and never calls WooCommerce itself; {@see \Woodev\Framework\Marketplace\Admin\Admin_Collector}
 * does.
 *
 * @since 2.1.13
 */
final class Marketplace_Orders_Page {
	/**
	 * WooCommerce id of the page, and its path without the slash.
	 */
	public const SLUG = 'woodev-marketplace-orders';

	/**
	 * The page's router path.
	 */
	public const PATH = '/' . self::SLUG;

	/**
	 * The URL argument that names the marketplace plugin whose orders are shown.
	 */
	public const MARKETPLACE_ARG = 'marketplace';

	/**
	 * DOM id of the WooCommerce menu root, which the page keeps open while it is on screen.
	 */
	public const PARENT_DOM_ID = 'toplevel_page_woocommerce';

	/**
	 * The menu the page is filed under.
	 */
	private const PARENT = 'woocommerce';

	/**
	 * Path of «Заказы доставки»: the page is placed right after it when it is there.
	 */
	private const DELIVERY_PATH = '/woodev-shipping-orders';

	/**
	 * @var Menu[]
	 */
	private $menus;

	/**
	 * Creates the page over the menus of the request.
	 *
	 * @since 2.1.13
	 *
	 * @param Menu[] $menus Marketplace menus, in registration order.
	 */
	public function __construct( array $menus ) {
		$this->menus = array_values( $menus );
	}

	/**
	 * Whether a plugin's page is the one this page took over: the framework's default «Заказы» page with the orders table.
	 *
	 * @since 2.1.13
	 *
	 * @param Page $page A page of a marketplace menu.
	 * @return bool
	 */
	public static function serves( Page $page ): bool {
		return self::is_orders_page( $page->get_slug(), $page->get_kind(), $page->get_tables() );
	}

	/**
	 * Query arguments of the page with a marketplace preset.
	 *
	 * @since 2.1.13
	 *
	 * @param string              $plugin_id The marketplace plugin.
	 * @param array<string,mixed> $query     Further arguments, e.g. an account.
	 * @return array<string,mixed>
	 */
	public static function url_query( string $plugin_id, array $query = [] ): array {
		return array_merge(
			[
				'page'                => 'wc-admin',
				'path'                => self::PATH,
				self::MARKETPLACE_ARG => $plugin_id,
			],
			$query
		);
	}

	/**
	 * The `wc_admin_register_page()` arguments, or null when the user may open the orders of no marketplace. The capability is that
	 * of the first marketplace orders page the user may open, so a user who may open one may open the page.
	 *
	 * @since 2.1.13
	 *
	 * @param callable $can Receives a capability, returns whether the current user holds it.
	 * @return array<string,mixed>|null
	 */
	public function build_registration( callable $can ): ?array {
		foreach ( $this->served_pages() as [ $menu, $page ] ) {
			$capability = $page->get_capability( $menu->get_capability() );

			if ( $can( $capability ) ) {
				return [
					'id'         => self::SLUG,
					'title'      => self::title(),
					'page_title' => self::title(),
					'parent'     => self::PARENT,
					'path'       => self::PATH,
					'capability' => $capability,
				];
			}
		}

		return null;
	}

	/**
	 * Turns a plugin's «Заказы» entry of its own submenu into a link to this page with the plugin's marketplace preset.
	 *
	 * WordPress prints an entry whose slug is not a registered page as a plain link (`_wp_menu_output()`), so the entry keeps its
	 * place and title and only its target changes.
	 *
	 * @since 2.1.13
	 *
	 * @param Menu                                $menu    A marketplace menu.
	 * @param array<int|string,array<int,string>> $entries The menu root's entries in `$submenu`.
	 * @return array<int|string,array<int,string>>
	 */
	public function link_menu_entries( Menu $menu, array $entries ): array {
		foreach ( $menu->get_pages() as $page ) {
			if ( ! self::serves( $page ) ) {
				continue;
			}

			$slug = 'wc-admin&path=' . $menu->get_page_path( $page );

			foreach ( $entries as $key => $entry ) {
				if ( isset( $entry[2] ) && $slug === $entry[2] ) {
					$entries[ $key ][2] = self::menu_link( $menu->get_plugin_id() );
				}
			}
		}

		return $entries;
	}

	/**
	 * Places the page in the WooCommerce submenu right after «Заказы доставки», or after WooCommerce's own «Заказы» when there is no
	 * delivery page — found by the neighbour's slug, never by position, for the reason `Orders_Registry::move_menu_item_after_orders()`
	 * gives. Fail-soft: without the page or a neighbour the submenu is returned as it was.
	 *
	 * @since 2.1.13
	 *
	 * @param array<int|string,mixed> $entries The WooCommerce entries in `$submenu`.
	 * @return array<int|string,mixed>
	 */
	public static function place_in_menu( array $entries ): array {
		$ours   = null;
		$others = [];

		foreach ( $entries as $entry ) {
			if ( null === $ours && self::slug_has_path( $entry, self::PATH ) ) {
				$ours = $entry;
				continue;
			}

			$others[] = $entry;
		}

		if ( null === $ours ) {
			return $entries;
		}

		$after = self::neighbour_index( $others );

		if ( null === $after ) {
			return $entries;
		}

		array_splice( $others, $after + 1, 0, [ $ours ] );

		return $others;
	}

	/**
	 * Adds `movedTo` — where the browser goes instead — to the plugin's «Заказы» page in its manifest entry.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $menu_entry A menu as `Menu::build_manifest()` gives it.
	 * @return array<string,mixed>
	 */
	public static function mark_moved( array $menu_entry ): array {
		foreach ( $menu_entry['pages'] as $index => $page ) {
			if ( self::is_orders_entry( $page ) ) {
				$menu_entry['pages'][ $index ]['movedTo'] = [
					'path'  => self::PATH,
					'query' => [ self::MARKETPLACE_ARG => (string) $menu_entry['pluginId'] ],
				];
			}
		}

		return $menu_entry;
	}

	/**
	 * The page as the React bundle reads it, or null when the user may open the orders of no marketplace.
	 *
	 * Built from the menus' own manifest entries, so the marketplaces are already limited to the pages the user may open and each
	 * carries the help links its plugin resolved for its «Заказы» page, and the words of its «Аналитика» tab.
	 *
	 * @since 2.1.13
	 * @since 2.1.23 Takes the posting nouns; the words of the «Аналитика» tab moved into each marketplace's entry.
	 *
	 * @param array<int,array<string,mixed>> $menu_entries Menus as `Menu::build_manifest()` gives them.
	 * @param array<string,string>           $names        Marketplace name by plugin id; the menu title when absent.
	 * @param array<string,Posting_Noun>     $nouns        Marketplace's word for a posting by plugin id; «отправление» when absent.
	 * @return array<string,mixed>|null
	 */
	public static function build_manifest( array $menu_entries, array $names, array $nouns = [] ): ?array {
		$marketplaces = [];

		foreach ( $menu_entries as $menu_entry ) {
			foreach ( $menu_entry['pages'] as $page ) {
				if ( self::is_orders_entry( $page ) ) {
					$marketplaces[] = self::marketplace_entry( $menu_entry, $page, $names, $nouns );
				}
			}
		}

		if ( [] === $marketplaces ) {
			return null;
		}

		return [
			'path'         => self::PATH,
			'title'        => self::title(),
			'breadcrumbs'  => [ self::title() ],
			'domId'        => self::PARENT_DOM_ID,
			'marketplaces' => $marketplaces,
			'labels'       => [
				'marketplace' => __( 'Маркетплейс', 'woodev-plugin-framework' ),
				'tabs'        => __( 'Разделы страницы', 'woodev-plugin-framework' ),
				'orders'      => __( 'Заказы', 'woodev-plugin-framework' ),
				'analytics'   => __( 'Аналитика', 'woodev-plugin-framework' ),
			],
		];
	}

	/**
	 * @return string The page's title.
	 */
	private static function title(): string {
		return __( 'Заказы маркетплейсов', 'woodev-plugin-framework' );
	}

	/**
	 * The relative link a submenu entry carries; WordPress resolves it against `wp-admin/`.
	 *
	 * @param string $plugin_id The marketplace plugin.
	 * @return string
	 */
	private static function menu_link( string $plugin_id ): string {
		return 'admin.php?' . http_build_query( self::url_query( $plugin_id ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @return array<int,array{0:Menu,1:Page}> Every page this page took over, with its menu.
	 */
	private function served_pages(): array {
		$served = [];

		foreach ( $this->menus as $menu ) {
			foreach ( $menu->get_pages() as $page ) {
				if ( self::serves( $page ) ) {
					$served[] = [ $menu, $page ];
				}
			}
		}

		return $served;
	}

	/**
	 * @param array<string,mixed>        $menu_entry A menu manifest entry.
	 * @param array<string,mixed>        $page       Its «Заказы» page entry.
	 * @param array<string,string>       $names Marketplace name by plugin id.
	 * @param array<string,Posting_Noun> $nouns Posting noun by plugin id.
	 * @return array<string,mixed>
	 */
	private static function marketplace_entry( array $menu_entry, array $page, array $names, array $nouns ): array {
		$plugin_id = (string) $menu_entry['pluginId'];
		$entry     = [
			'id'        => $plugin_id,
			'label'     => $names[ $plugin_id ] ?? (string) $menu_entry['title'],
			'table'     => Site_Orders_Provider::TABLE,
			'analytics' => Orders_Analytics_Controller::messages( $nouns[ $plugin_id ] ?? null ),
		];

		foreach ( [ 'help', 'problems' ] as $link ) {
			if ( isset( $page[ $link ] ) ) {
				$entry[ $link ] = $page[ $link ];
			}
		}

		return $entry;
	}

	/**
	 * @param array<string,mixed> $page A page manifest entry.
	 * @return bool
	 */
	private static function is_orders_entry( array $page ): bool {
		return self::is_orders_page( (string) ( $page['id'] ?? '' ), (string) ( $page['kind'] ?? '' ), (array) ( $page['tables'] ?? [] ) );
	}

	/**
	 * The one rule both shapes of a page answer to.
	 *
	 * @param string   $slug   Page slug.
	 * @param string   $kind   Page kind.
	 * @param string[] $tables Page tables.
	 * @return bool
	 */
	private static function is_orders_page( string $slug, string $kind, array $tables ): bool {
		return Site_Orders_Module::PAGE === $slug && Page::KIND_RECORDS === $kind && in_array( Site_Orders_Provider::TABLE, $tables, true );
	}

	/**
	 * Index of the entry the page goes after: «Заказы доставки», else WooCommerce's «Заказы» (HPOS or the post store).
	 *
	 * @param array<int,mixed> $entries WooCommerce entries without the page.
	 * @return int|null
	 */
	private static function neighbour_index( array $entries ): ?int {
		$orders = null;

		foreach ( $entries as $index => $entry ) {
			if ( self::slug_has_path( $entry, self::DELIVERY_PATH ) ) {
				return $index;
			}

			if ( null === $orders && is_array( $entry ) && isset( $entry[2] ) && self::is_wc_orders_slug( (string) $entry[2] ) ) {
				$orders = $index;
			}
		}

		return $orders;
	}

	/**
	 * @param mixed  $entry A submenu entry.
	 * @param string $path  A wc-admin path.
	 * @return bool Whether the entry is the wc-admin page of that path.
	 */
	private static function slug_has_path( $entry, string $path ): bool {
		return is_array( $entry ) && isset( $entry[2] ) && 'wc-admin&path=' . $path === (string) $entry[2];
	}

	/**
	 * @param string $slug A submenu slug.
	 * @return bool Whether it is WooCommerce's own orders screen, in either store.
	 */
	private static function is_wc_orders_slug( string $slug ): bool {
		return 'wc-orders' === $slug || 'admin.php?page=wc-orders' === $slug || false !== strpos( $slug, 'post_type=shop_order' );
	}
}
