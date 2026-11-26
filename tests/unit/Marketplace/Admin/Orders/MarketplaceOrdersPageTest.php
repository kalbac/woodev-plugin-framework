<?php
/**
 * The shared «Заказы маркетплейсов» page: which plugin pages it takes over, its registration, its menu place and its manifest.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Marketplace_Orders_Page;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Controller;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Marketplace_Orders_Page
 */
final class MarketplaceOrdersPageTest extends TestCase {

	/**
	 * A menu with the given pages.
	 *
	 * @param string                         $plugin_id Plugin id; the menu id too.
	 * @param array<int,array<string,mixed>> $pages     Page descriptors.
	 * @param string                         $capability Menu capability.
	 * @return Menu
	 */
	private function menu( string $plugin_id, array $pages, string $capability = 'manage_woocommerce' ): Menu {
		return new Menu(
			$plugin_id,
			[
				'id'         => $plugin_id,
				'title'      => strtoupper( $plugin_id ),
				'capability' => $capability,
			],
			array_map( [ Page::class, 'from_array' ], $pages )
		);
	}

	/**
	 * @return array<string,mixed> The framework's default «Заказы» page.
	 */
	private function orders_page( array $extra = [] ): array {
		return array_merge( [ 'slug' => 'orders', 'title' => 'Заказы', 'kind' => 'records', 'tables' => [ 'orders' ] ], $extra );
	}

	/**
	 * @return array<string,mixed> A page the shared page does not take over.
	 */
	private function products_page(): array {
		return [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ] ];
	}

	public function test_only_the_default_orders_page_with_the_orders_table_is_taken_over(): void {
		$this->assertTrue( Marketplace_Orders_Page::serves( Page::from_array( $this->orders_page() ) ) );
		$this->assertFalse( Marketplace_Orders_Page::serves( Page::from_array( $this->products_page() ) ) );
		$this->assertFalse( Marketplace_Orders_Page::serves( Page::from_array( $this->orders_page( [ 'tables' => [ 'shipments' ] ] ) ) ), 'A plugin that put another table there keeps its page.' );
		$this->assertFalse( Marketplace_Orders_Page::serves( Page::from_array( [ 'slug' => 'orders', 'title' => 'Заказы', 'kind' => 'custom', 'view' => 'own' ] ) ), 'So does one with its own view.' );
	}

	public function test_the_page_is_registered_under_woocommerce_with_the_capability_of_the_first_orders_page_the_user_may_open(): void {
		$page = new Marketplace_Orders_Page(
			[
				$this->menu( 'ozon-seller', [ $this->orders_page( [ 'capability' => 'view_ozon_orders' ] ) ] ),
				$this->menu( 'wildberries', [ $this->products_page(), $this->orders_page() ], 'edit_shop_orders' ),
			]
		);

		$this->assertSame(
			[
				'id'         => 'woodev-marketplace-orders',
				'title'      => 'Заказы маркетплейсов',
				'page_title' => 'Заказы маркетплейсов',
				'parent'     => 'woocommerce',
				'path'       => '/woodev-marketplace-orders',
				'capability' => 'edit_shop_orders',
			],
			$page->build_registration(
				static function ( string $capability ): bool {
					return 'edit_shop_orders' === $capability;
				}
			)
		);
		$this->assertNull( $page->build_registration( '__return_false' ), 'No marketplace orders the user may open: no page.' );
		$this->assertNull( ( new Marketplace_Orders_Page( [ $this->menu( 'ozon-seller', [ $this->products_page() ] ) ] ) )->build_registration( '__return_true' ), 'No orders page: no page.' );
	}

	public function test_the_plugins_orders_entry_links_to_the_page_with_its_marketplace_and_nothing_else_changes(): void {
		$menu    = $this->menu( 'ozon-seller', [ $this->products_page(), $this->orders_page() ] );
		$entries = [
			[ 'Товары', 'manage_woocommerce', 'wc-admin&path=/ozon-seller/products', 'Товары' ],
			[ 'Заказы', 'manage_woocommerce', 'wc-admin&path=/ozon-seller/orders', 'Заказы' ],
		];

		$linked = ( new Marketplace_Orders_Page( [ $menu ] ) )->link_menu_entries( $menu, $entries );

		$this->assertSame( $entries[0], $linked[0] );
		$this->assertSame( [ 'Заказы', 'manage_woocommerce', 'admin.php?page=wc-admin&path=%2Fwoodev-marketplace-orders&marketplace=ozon-seller', 'Заказы' ], $linked[1], 'Same place and title, a new target.' );
	}

	/**
	 * @return array<string,array{0:array<int,string>,1:array<int,string>}>
	 */
	public function provide_menus(): array {
		$ours     = 'wc-admin&path=/woodev-marketplace-orders';
		$delivery = 'wc-admin&path=/woodev-shipping-orders';

		return [
			'after the delivery orders'                  => [ [ 'wc-admin', 'wc-orders', $delivery, 'wc-admin&path=/customers', $ours ], [ 'wc-admin', 'wc-orders', $delivery, $ours, 'wc-admin&path=/customers' ] ],
			'after the orders without a delivery page'   => [ [ 'wc-admin', 'wc-orders', 'wc-admin&path=/customers', $ours ], [ 'wc-admin', 'wc-orders', $ours, 'wc-admin&path=/customers' ] ],
			'after the orders of the post store'         => [ [ 'edit.php?post_type=shop_order', 'wc-settings', $ours ], [ 'edit.php?post_type=shop_order', $ours, 'wc-settings' ] ],
			'no neighbour: left alone'                   => [ [ 'wc-admin', 'wc-settings', $ours ], [ 'wc-admin', 'wc-settings', $ours ] ],
			'not registered: left alone'                 => [ [ 'wc-admin', 'wc-orders' ], [ 'wc-admin', 'wc-orders' ] ],
		];
	}

	/**
	 * @dataProvider provide_menus
	 *
	 * @param string[] $slugs    Submenu slugs before.
	 * @param string[] $expected Submenu slugs after.
	 */
	public function test_the_page_is_placed_after_its_neighbour_by_slug( array $slugs, array $expected ): void {
		$entries = array_map(
			static function ( string $slug ): array {
				return [ $slug, 'manage_woocommerce', $slug, $slug ];
			},
			$slugs
		);

		$this->assertSame( $expected, array_column( Marketplace_Orders_Page::place_in_menu( $entries ), 2 ) );
	}

	public function test_the_manifest_lists_the_marketplaces_whose_orders_page_the_user_may_open_with_their_help_links(): void {
		$help    = [ 'url' => 'https://woodev.ru/docs/ozon/orders', 'text' => 'Подробнее' ];
		$entries = [
			[
				'id'       => 'ozon-seller',
				'pluginId' => 'ozon-seller',
				'title'    => 'Ozon Seller',
				'pages'    => [
					[ 'id' => 'products', 'kind' => 'records', 'tables' => [ 'products' ] ],
					[ 'id' => 'orders', 'kind' => 'records', 'tables' => [ 'orders' ], 'help' => $help ],
				],
			],
			[
				'id'       => 'wb',
				'pluginId' => 'wildberries',
				'title'    => 'Wildberries',
				'pages'    => [ [ 'id' => 'orders', 'kind' => 'records', 'tables' => [ 'orders' ] ] ],
			],
		];

		$noun     = new Posting_Noun(
			[
				'one'        => 'сборочное задание',
				'of_one'     => 'сборочного задания',
				'to_one'     => 'сборочному заданию',
				'with_one'   => 'со сборочным заданием',
				'many'       => 'сборочные задания',
				'of_many'    => 'сборочных заданий',
				'about_many' => 'о сборочных заданиях',
			]
		);
		$manifest = Marketplace_Orders_Page::build_manifest( $entries, [ 'ozon-seller' => 'Ozon' ], [ 'wildberries' => $noun ] );

		$this->assertSame( '/woodev-marketplace-orders', $manifest['path'] );
		$this->assertSame( 'toplevel_page_woocommerce', $manifest['domId'], 'The WooCommerce menu stays open on the page.' );
		$this->assertSame( [ 'Заказы маркетплейсов' ], $manifest['breadcrumbs'] );
		$this->assertSame(
			[
				[ 'id' => 'ozon-seller', 'label' => 'Ozon', 'table' => 'orders', 'analytics' => Orders_Analytics_Controller::messages(), 'help' => $help ],
				[ 'id' => 'wildberries', 'label' => 'Wildberries', 'table' => 'orders', 'analytics' => Orders_Analytics_Controller::messages( $noun ) ],
			],
			$manifest['marketplaces'],
			'The marketplace name when the plugin gives one, the menu title otherwise; the «Аналитика» tab gets its words from PHP, in each marketplace\'s wording.'
		);
		$this->assertSame( 'Зависшие отправления', $manifest['marketplaces'][0]['analytics']['stuck'] );
		$this->assertSame( 'Зависшие сборочные задания', $manifest['marketplaces'][1]['analytics']['stuck'] );
		$this->assertSame( [ 'marketplace', 'tabs', 'orders', 'analytics' ], array_keys( $manifest['labels'] ) );
		$this->assertNull(
			Marketplace_Orders_Page::build_manifest( [ [ 'pluginId' => 'x', 'title' => 'X', 'pages' => [ $entries[0]['pages'][0] ] ] ], [] ),
			'No orders page the user may open: no page.'
		);
	}

	public function test_the_plugins_orders_page_says_where_it_moved(): void {
		$entry = Marketplace_Orders_Page::mark_moved(
			[
				'pluginId' => 'ozon-seller',
				'pages'    => [
					[ 'id' => 'products', 'kind' => 'records', 'tables' => [ 'products' ] ],
					[ 'id' => 'orders', 'kind' => 'records', 'tables' => [ 'orders' ] ],
				],
			]
		);

		$this->assertArrayNotHasKey( 'movedTo', $entry['pages'][0] );
		$this->assertSame( [ 'path' => '/woodev-marketplace-orders', 'query' => [ 'marketplace' => 'ozon-seller' ] ], $entry['pages'][1]['movedTo'] );
	}

	public function test_the_url_query_presets_the_marketplace_and_keeps_further_arguments(): void {
		$this->assertSame(
			[ 'page' => 'wc-admin', 'path' => '/woodev-marketplace-orders', 'marketplace' => 'ozon-seller', 'account' => '7' ],
			Marketplace_Orders_Page::url_query( 'ozon-seller', [ 'account' => '7' ] )
		);
	}
}
