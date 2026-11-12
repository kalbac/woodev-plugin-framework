<?php
/**
 * The shape of a marketplace menu and what is derived from it.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Page;

require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/admin/class-page.php';
require_once dirname( __DIR__, 4 ) . '/woodev/marketplace/admin/class-menu.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Menu
 */
final class MenuTest extends TestCase {

	/**
	 * The six pages of B4, in order, as a plugin with settings declares them.
	 *
	 * @param string[] $slugs Slugs to include.
	 * @return Page[]
	 */
	private function pages( array $slugs = [ 'settings', 'products', 'orders', 'mappings', 'tools', 'diagnostics' ] ): array {
		$pages = [];

		foreach ( $slugs as $slug ) {
			$pages[] = Page::from_array(
				[
					'slug'   => $slug,
					'title'  => 'T ' . $slug,
					'kind'   => 'settings' === $slug ? 'settings' : 'records',
					'tables' => [ $slug ],
				]
			);
		}

		return $pages;
	}

	/**
	 * @param Page[]|null $pages Pages; the six by default.
	 * @return Menu
	 */
	private function menu( ?array $pages = null ): Menu {
		return new Menu(
			'ozon-plugin',
			[
				'id'       => 'ozon-seller',
				'title'    => 'OZON Seller',
				'icon'     => 'https://example.test/icon_square.svg',
				'position' => '55.63',
			],
			$pages ?? $this->pages()
		);
	}

	public function test_the_root_is_registered_without_a_parent_with_the_menus_icon_and_position(): void {
		$root = $this->menu()->build_root_args();

		$this->assertArrayNotHasKey( 'parent', $root, 'a root with a parent would be a submenu of another menu' );
		$this->assertSame( 'https://example.test/icon_square.svg', $root['icon'] );
		$this->assertSame( 55.63, $root['position'] );
		$this->assertSame( 'manage_woocommerce', $root['capability'] );
		$this->assertSame( 'OZON Seller', $root['title'] );
		$this->assertSame( 'OZON Seller', $root['page_title'], 'page_title is passed so the title is not copied into the tab title' );
	}

	public function test_the_root_is_a_container_no_page_shares(): void {
		$menu = $this->menu();

		$this->assertSame( '/ozon-seller', $menu->get_root_path() );
		$this->assertSame( '/ozon-seller', $menu->build_root_args()['path'] );

		foreach ( $menu->get_pages() as $page ) {
			$this->assertNotSame( $menu->get_root_path(), $menu->get_page_path( $page ), 'the root\'s slug is its own, so its copy in the submenu can be told apart' );
		}
	}

	public function test_the_root_opens_the_first_page_of_the_order_it_was_given(): void {
		$not_configured = $this->menu();
		$configured     = $this->menu( $this->pages( [ 'products', 'orders', 'mappings', 'tools', 'diagnostics', 'settings' ] ) );

		$this->assertSame( 'settings', $not_configured->get_landing_page()->get_slug() );
		$this->assertSame( 'products', $configured->get_landing_page()->get_slug() );
	}

	public function test_the_six_children_are_registered_in_the_declared_order_under_the_root(): void {
		$registrations = $this->menu()->build_registrations();

		$this->assertCount( 7, $registrations, 'the root and six children' );
		$this->assertSame(
			[ 'settings', 'products', 'orders', 'mappings', 'tools', 'diagnostics' ],
			array_map(
				static function ( array $args ): string {
					return substr( $args['path'], strlen( '/ozon-seller/' ) );
				},
				array_slice( $registrations, 1 )
			)
		);

		foreach ( array_slice( $registrations, 1 ) as $child ) {
			$this->assertSame( 'woodev-marketplace-ozon-seller', $child['parent'] );
		}

		$this->assertSame( 'woodev-marketplace-ozon-seller', $registrations[0]['id'] );
	}

	public function test_a_child_may_name_its_own_capability(): void {
		$pages = $this->pages( [ 'products' ] );
		$pages[] = Page::from_array( [ 'slug' => 'tools', 'title' => 'Tools', 'kind' => 'custom', 'view' => 'tools', 'capability' => 'manage_options' ] );

		$registrations = $this->menu( $pages )->build_registrations();

		$this->assertSame( 'manage_woocommerce', $registrations[1]['capability'] );
		$this->assertSame( 'manage_options', $registrations[2]['capability'] );
	}

	public function test_the_root_slug_and_dom_id_follow_woocommerces_page_formula(): void {
		$menu = $this->menu();

		$this->assertSame( 'wc-admin&path=/ozon-seller', $menu->get_root_slug() );
		$this->assertSame( 'toplevel_page_wc-admin-path--ozon-seller', $menu->get_root_dom_id() );
	}

	/**
	 * @return array<int,array<int,string>>
	 */
	private function entry( string $title, string $slug ): array {
		return [ $title, 'manage_woocommerce', $slug, $title ];
	}

	public function test_the_copy_of_the_root_is_removed_and_every_page_stays(): void {
		$root = 'wc-admin&path=/ozon-seller';

		// What WordPress builds when the first child is added: the copy of the root, then the children.
		$entries = [
			$this->entry( 'OZON Seller', $root ),
			$this->entry( 'Настройки', 'wc-admin&path=/ozon-seller/settings' ),
			$this->entry( 'Товары', 'wc-admin&path=/ozon-seller/products' ),
			$this->entry( 'Заказы', 'wc-admin&path=/ozon-seller/orders' ),
		];

		$cleaned = $this->menu()->remove_root_entry( $entries );

		$this->assertSame(
			[ 'wc-admin&path=/ozon-seller/settings', 'wc-admin&path=/ozon-seller/products', 'wc-admin&path=/ozon-seller/orders' ],
			array_column( $cleaned, 2 ),
			'the root\'s copy is gone; the root now links to the first real page'
		);
		$this->assertSame( [ 1, 2, 3 ], array_keys( $cleaned ), 'WooCommerce\'s unset() keeps the keys too' );

		foreach ( $cleaned as $entry ) {
			$this->assertCount( 4, $entry, 'no entry carries a hiding class or any extra field' );
		}
	}

	public function test_nothing_is_removed_when_there_is_no_copy_of_the_root(): void {
		$entries = [
			$this->entry( 'Товары', 'wc-admin&path=/ozon-seller/products' ),
			$this->entry( 'Заказы', 'wc-admin&path=/ozon-seller/orders' ),
		];

		$this->assertSame( $entries, $this->menu()->remove_root_entry( $entries ) );
	}

	public function test_another_menus_entries_are_never_removed(): void {
		$entries = [
			$this->entry( 'Other', 'wc-admin&path=/wildberries' ),
			$this->entry( 'Other', 'wc-admin&path=/wildberries/products' ),
		];

		$this->assertSame( $entries, $this->menu()->remove_root_entry( $entries ) );
	}

	public function test_the_css_is_only_the_icon_rule_scoped_to_this_roots_id(): void {
		$css = $this->menu()->build_menu_css();

		$this->assertSame( '#toplevel_page_wc-admin-path--ozon-seller .wp-menu-image img{width:18px;height:18px;opacity:.6}', $css );
		$this->assertStringNotContainsString( 'display:none', $css, 'no entry is hidden by a stylesheet' );
	}

	public function test_the_manifest_lists_what_the_user_may_open_with_finished_titles_and_breadcrumbs(): void {
		$manifest = $this->menu()->build_manifest(
			static function ( string $capability ): bool {
				return 'manage_woocommerce' === $capability;
			}
		);

		$this->assertSame( 'ozon-seller', $manifest['id'] );
		$this->assertSame( 'toplevel_page_wc-admin-path--ozon-seller', $manifest['domId'] );
		$this->assertCount( 6, $manifest['pages'] );

		$products = $manifest['pages'][1];
		$this->assertSame( 'products', $products['id'] );
		$this->assertSame( '/ozon-seller/products', $products['path'] );
		$this->assertSame( 'T products', $products['title'] );
		$this->assertSame( [ [ '/ozon-seller/settings', 'OZON Seller' ], 'T products' ], $products['breadcrumbs'], 'the first crumb is where the root leads: the first page' );
		$this->assertNull( $products['analytics'], 'the analytics slot is reserved: null, nothing computed' );
	}

	public function test_the_last_crumb_is_the_page_heading_when_it_differs_from_the_menu_entry(): void {
		$pages   = $this->pages( [ 'products' ] );
		$pages[] = Page::from_array( [ 'slug' => 'settings', 'title' => 'Настройки', 'heading' => 'Настройки OZON Seller', 'kind' => 'settings' ] );

		$manifest = $this->menu( $pages )->build_manifest(
			static function (): bool {
				return true;
			}
		);

		$settings = $manifest['pages'][1];
		$this->assertSame( 'Настройки', $settings['title'], 'the menu entry stays short' );
		$this->assertSame( 'Настройки OZON Seller', $settings['breadcrumbs'][1], 'the heading above the page is the long one' );
	}

	public function test_a_page_the_user_may_not_open_has_no_route(): void {
		$pages   = $this->pages( [ 'products' ] );
		$pages[] = Page::from_array( [ 'slug' => 'tools', 'title' => 'Tools', 'kind' => 'custom', 'view' => 'tools', 'capability' => 'manage_options' ] );

		$manifest = $this->menu( $pages )->build_manifest(
			static function ( string $capability ): bool {
				return 'manage_woocommerce' === $capability;
			}
		);

		$this->assertSame( [ 'products' ], array_column( $manifest['pages'], 'id' ) );
	}

	public function test_a_menu_the_user_may_open_nothing_of_has_no_manifest(): void {
		$this->assertNull(
			$this->menu()->build_manifest(
				static function (): bool {
					return false;
				}
			)
		);
	}

	public function test_a_v1_request_finds_the_page_that_replaced_it(): void {
		$pages   = $this->pages( [ 'products', 'orders' ] );
		$pages[0] = Page::from_array(
			[ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ], 'legacy_page' => [ 'ozon-seller', 'ozon-seller-products' ] ]
		);
		$pages[1] = Page::from_array(
			[ 'slug' => 'orders', 'title' => 'Заказы', 'kind' => 'records', 'tables' => [ 'orders' ], 'legacy_page' => 'ozon-seller-orders' ]
		);
		$menu     = $this->menu( $pages );

		$this->assertSame( 'products', $menu->find_page_for_legacy_request( [ 'page' => 'ozon-seller' ] )->get_slug() );
		$this->assertSame( 'products', $menu->find_page_for_legacy_request( [ 'page' => 'ozon-seller-products' ] )->get_slug() );
		$this->assertSame( 'orders', $menu->find_page_for_legacy_request( [ 'page' => 'ozon-seller-orders' ] )->get_slug() );
		$this->assertNull( $menu->find_page_for_legacy_request( [ 'page' => 'woodev-ozon-products' ] ), 'unreleased v2 slugs get no redirect' );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function provide_definitions_that_are_refused(): array {
		return [
			'id with a capital'  => [ [ 'id' => 'Ozon', 'title' => 'T' ] ],
			'no id'              => [ [ 'title' => 'T' ] ],
			'no title'           => [ [ 'id' => 'ozon' ] ],
			'position not a number' => [ [ 'id' => 'ozon', 'title' => 'T', 'position' => 'top' ] ],
		];
	}

	/**
	 * @dataProvider provide_definitions_that_are_refused
	 *
	 * @param array<string,mixed> $definition Menu definition.
	 */
	public function test_a_definition_that_cannot_form_a_menu_is_refused( array $definition ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Menu( 'p', $definition, $this->pages( [ 'products' ] ) );
	}

	public function test_a_menu_needs_pages_and_refuses_a_slug_twice(): void {
		try {
			new Menu( 'p', [ 'id' => 'ozon', 'title' => 'T' ], [] );
			$this->fail( 'a menu without pages was accepted' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'no pages', $exception->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );
		new Menu( 'p', [ 'id' => 'ozon', 'title' => 'T' ], array_merge( $this->pages( [ 'products' ] ), $this->pages( [ 'products' ] ) ) );
	}
}
