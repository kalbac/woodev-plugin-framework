<?php
/**
 * Wiring of the «Заказы» table for a marketplace plugin.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Test_Marketplace_Plugin.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module
 */
final class SiteOrdersModuleTest extends TestCase {
	private const MENU = [
		'id'       => 'ozon-seller',
		'title'    => 'OZON Seller',
		'icon'     => 'https://example.test/icon.svg',
		'position' => '55.63',
	];

	/**
	 * @var mixed
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		// Without WooCommerce's HPOS classes the storage is the posts table, whose name is read from the global database.
		$this->wpdb            = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']       = new Sqlite_Wpdb();
		$GLOBALS['wpdb']->posts = 'wp_posts';

		Records_Registry::reset_for_tests();
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		$GLOBALS['wpdb'] = $this->wpdb;
		parent::tearDown();
	}

	/**
	 * An account store the wiring only holds on to.
	 *
	 * @return Wpdb_Account_Store
	 */
	private function accounts(): Wpdb_Account_Store {
		return ( new \ReflectionClass( Wpdb_Account_Store::class ) )->newInstanceWithoutConstructor();
	}

	public function test_the_provider_is_registered_when_the_rest_api_starts_and_before_the_registrar_walks_its_controllers(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		Actions\expectAdded( 'rest_api_init' )->with( Mockery::type( 'callable' ), 5 )->once();

		Site_Orders_Module::register( $plugin, $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_the_table_is_registered_under_the_plugin_with_the_pages_capability(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertTrue( Site_Orders_Module::register_provider( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );

		$registration = Records_Registry::instance()->get( 'ozon', 'orders' );

		$this->assertNotNull( $registration );
		$this->assertInstanceOf( Site_Orders_Provider::class, $registration->get_provider() );
		$this->assertSame( 'manage_woocommerce', $registration->get_capability() );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'products' ), 'Only its own table.' );
	}

	public function test_a_plugin_that_dropped_the_orders_page_gets_no_table(): void {
		$pages  = [
			[
				'slug'  => 'tools',
				'title' => 'Инструменты',
				'kind'  => 'custom',
				'view'  => 'tools',
			],
		];
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $pages );

		$this->assertFalse( Site_Orders_Module::register_provider( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'orders' ) );
	}
}
