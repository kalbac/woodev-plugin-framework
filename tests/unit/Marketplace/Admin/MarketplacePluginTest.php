<?php
/**
 * The marketplace base plugin: which pages it declares and where they live.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Test_Marketplace_Plugin.php';

/**
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin
 */
final class MarketplacePluginTest extends TestCase {

	private const MENU = [
		'id'       => 'ozon-seller',
		'title'    => 'OZON Seller',
		'icon'     => 'https://example.test/icon.svg',
		'position' => '55.63',
	];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			}
		);
	}

	/**
	 * @param string[] $slugs The slugs of the menu's pages, in order.
	 * @return string[]
	 */
	private function slugs_of( Test_Marketplace_Plugin $plugin ): array {
		return array_map(
			static function ( Page $page ): string {
				return $page->get_slug();
			},
			$plugin->get_marketplace_admin_menu()->get_pages()
		);
	}

	public function test_a_plugin_with_settings_has_the_six_pages_in_the_order_the_operator_set(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		$this->assertSame(
			[ 'settings', 'products', 'orders', 'mappings', 'tools', 'diagnostics' ],
			$this->slugs_of( $plugin )
		);
	}

	public function test_a_plugin_without_settings_providers_has_no_settings_page(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertSame( [ 'products', 'orders', 'mappings', 'tools', 'diagnostics' ], $this->slugs_of( $plugin ) );
		$this->assertSame( '', $plugin->get_settings_url(), 'no settings page: no URL' );
	}

	public function test_the_menu_opens_products(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		$this->assertSame( '/ozon-seller/products', $plugin->get_marketplace_admin_menu()->get_root_path() );
	}

	public function test_a_plugin_may_drop_a_default_page(): void {
		$plugin = new Test_Marketplace_Plugin(
			'ozon',
			self::MENU,
			[ [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ] ] ]
		);

		$this->assertSame( [ 'products' ], $this->slugs_of( $plugin ) );
	}

	public function test_a_page_url_stays_inside_the_wc_admin_app(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		$this->assertSame(
			'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Forders&paged=2',
			$plugin->get_marketplace_page_url( 'orders', [ 'paged' => 2 ] )
		);
		$this->assertSame( '', $plugin->get_marketplace_page_url( 'no-such-page' ) );
	}

	public function test_the_settings_url_is_the_settings_page(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		$this->assertSame(
			'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fsettings',
			$plugin->get_settings_url()
		);
	}

	public function test_a_refused_definition_is_reported_once_and_leaves_the_plugin_without_a_menu(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$plugin = new Test_Marketplace_Plugin( 'ozon', [ 'id' => 'Ozon Seller', 'title' => 'x' ] );

		$this->assertNull( $plugin->get_marketplace_admin_menu() );
		$this->assertNull( $plugin->get_marketplace_admin_menu(), 'the refusal is remembered, not repeated' );
		$this->assertSame( '', $plugin->get_marketplace_page_url( 'products' ) );
	}

	public function test_a_refused_page_descriptor_is_reported_and_leaves_the_plugin_without_a_menu(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, [ [ 'slug' => 'products', 'title' => 'T', 'kind' => 'records' ] ] );

		$this->assertNull( $plugin->get_marketplace_admin_menu() );
	}

	public function test_a_plugin_brings_its_own_view_scripts_by_handle(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [], [ 'ozon-categories' => 'ozon-categories-view' ] );

		$this->assertSame( [ 'ozon-categories' => 'ozon-categories-view' ], $plugin->get_marketplace_admin_menu()->get_views() );
	}
}
