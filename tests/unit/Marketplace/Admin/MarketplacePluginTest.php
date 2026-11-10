<?php
/**
 * The marketplace base plugin: which pages it declares and where they live.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
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

	/**
	 * Saves an account into the double's store.
	 *
	 * @param Test_Marketplace_Plugin $plugin      The plugin.
	 * @param array<string,string>    $credentials Credentials by field name (the fake adapter declares `cabinet` and `token`).
	 * @param bool                    $enabled     The merchant's switch.
	 */
	private function add_account( Test_Marketplace_Plugin $plugin, array $credentials, bool $enabled = true ): void {
		$plugin->accounts()->save( new Account( 0, 'fake_wildberries', 'Cabinet', $enabled, $credentials ) );
	}

	public function test_a_plugin_that_is_not_configured_opens_on_settings_and_lists_it_first(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		$this->assertFalse( $plugin->is_configured() );
		$this->assertSame(
			[ 'settings', 'products', 'orders', 'mappings', 'tools', 'diagnostics' ],
			$this->slugs_of( $plugin )
		);
		$this->assertSame( 'settings', $plugin->get_marketplace_admin_menu()->get_landing_page()->get_slug(), 'the root leads to the first page' );
	}

	public function test_a_configured_plugin_opens_on_products_and_lists_settings_last(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$this->add_account( $plugin, [ 'cabinet' => '123', 'token' => 'secret' ] );

		$this->assertTrue( $plugin->is_configured() );
		$this->assertSame(
			[ 'products', 'orders', 'mappings', 'tools', 'diagnostics', 'settings' ],
			$this->slugs_of( $plugin )
		);
		$this->assertSame( 'products', $plugin->get_marketplace_admin_menu()->get_landing_page()->get_slug() );
	}

	public function test_the_registrations_follow_the_order_so_wordpress_files_the_children_the_same_way(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$paths  = static function ( Test_Marketplace_Plugin $plugin ): array {
			return array_column( array_slice( $plugin->get_marketplace_admin_menu()->build_registrations(), 1 ), 'path' );
		};

		$this->assertSame( '/ozon-seller/settings', $paths( $plugin )[0] );

		$configured = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$this->add_account( $configured, [ 'cabinet' => '123', 'token' => 'secret' ] );

		$this->assertSame( '/ozon-seller/products', $paths( $configured )[0] );
		$this->assertSame( '/ozon-seller/settings', $paths( $configured )[5] );
	}

	public function test_a_plugin_without_a_settings_page_keeps_one_order_and_does_not_read_the_accounts(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertSame( [ 'products', 'orders', 'mappings', 'tools', 'diagnostics' ], $this->slugs_of( $plugin ) );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:bool,2:bool}>
	 */
	public function provide_accounts(): array {
		return [
			'no account'                     => [ [], true, false ],
			'identifier and secret'          => [ [ 'cabinet' => '123', 'token' => 'secret' ], true, true ],
			'the secret is missing'          => [ [ 'cabinet' => '123', 'token' => '' ], true, false ],
			'the identifier is missing'      => [ [ 'cabinet' => '', 'token' => 'secret' ], true, false ],
			'blanks only'                    => [ [ 'cabinet' => ' ', 'token' => ' ' ], true, false ],
			'the merchant switched it off'   => [ [ 'cabinet' => '123', 'token' => 'secret' ], false, true ],
		];
	}

	/**
	 * @dataProvider provide_accounts
	 *
	 * @param array<string,string> $credentials Credentials of the only account; empty for none.
	 * @param bool                 $enabled     The merchant's switch.
	 * @param bool                 $expected    Whether the plugin is configured.
	 */
	public function test_configured_means_an_account_with_every_required_credential( array $credentials, bool $enabled, bool $expected ): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );

		if ( [] !== $credentials ) {
			$this->add_account( $plugin, $credentials, $enabled );
		}

		$this->assertSame( $expected, $plugin->is_configured() );
	}

	public function test_one_complete_account_is_enough_among_incomplete_ones(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$this->add_account( $plugin, [ 'cabinet' => '1', 'token' => '' ] );
		$this->add_account( $plugin, [ 'cabinet' => '2', 'token' => 'secret' ] );

		$this->assertTrue( $plugin->is_configured() );
	}

	public function test_an_account_of_another_adapter_does_not_configure_the_plugin(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$plugin->accounts()->save( new Account( 0, 'other_marketplace', 'Cabinet', true, [ 'cabinet' => '1', 'token' => 'secret' ] ) );

		$this->assertFalse( $plugin->is_configured() );
	}

	public function test_the_not_configured_notice_sends_the_merchant_to_the_settings_page(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$notice = $plugin->not_configured_notice();

		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'href="https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fsettings"', $notice['message'] );

		$this->add_account( $plugin, [ 'cabinet' => '123', 'token' => 'secret' ] );
		$this->assertNull( $plugin->not_configured_notice(), 'configured: no notice' );
	}

	public function test_a_plugin_without_settings_providers_has_no_settings_page(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertSame( [ 'products', 'orders', 'mappings', 'tools', 'diagnostics' ], $this->slugs_of( $plugin ) );
		$this->assertSame( '', $plugin->get_settings_url(), 'no settings page: no URL' );
	}

	public function test_the_root_is_a_container_with_its_own_slug_in_both_orders(): void {
		$not_configured = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$configured     = new Test_Marketplace_Plugin( 'ozon', self::MENU, null, [ new \stdClass() ] );
		$this->add_account( $configured, [ 'cabinet' => '123', 'token' => 'secret' ] );

		foreach ( [ $not_configured, $configured ] as $plugin ) {
			$menu = $plugin->get_marketplace_admin_menu();

			$this->assertSame( '/ozon-seller', $menu->get_root_path() );
			$this->assertSame( 'toplevel_page_wc-admin-path--ozon-seller', $menu->get_root_dom_id(), 'the highlight id does not depend on the order' );
		}
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
