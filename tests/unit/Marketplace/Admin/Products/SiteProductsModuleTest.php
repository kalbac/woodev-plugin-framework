<?php
/**
 * Wiring of the «Товары сайта» table for a marketplace plugin.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Provider;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Test_Marketplace_Plugin.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Module
 */
final class SiteProductsModuleTest extends TestCase {
	private const MENU = [
		'id'       => 'ozon-seller',
		'title'    => 'OZON Seller',
		'icon'     => 'https://example.test/icon.svg',
		'position' => '55.63',
	];

	protected function setUp(): void {
		parent::setUp();

		Records_Registry::reset_for_tests();
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

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
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

		Site_Products_Module::register( $plugin, $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_the_table_is_registered_under_the_plugin_with_the_pages_capability(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertTrue( Site_Products_Module::register_provider( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );

		$registration = Records_Registry::instance()->get( 'ozon', 'products' );

		$this->assertNotNull( $registration );
		$this->assertInstanceOf( Site_Products_Provider::class, $registration->get_provider() );
		$this->assertSame( 'manage_woocommerce', $registration->get_capability() );
	}

	/**
	 * The `products` page as a plugin declares it when its adapter can list the marketplace's cards.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pages_with_cards(): array {
		return [
			[
				'slug'   => 'products',
				'title'  => 'Товары',
				'kind'   => 'records',
				'tables' => [
					'products'     => 'Товары сайта',
					'remote_cards' => 'Карточки на маркетплейсе',
				],
			],
		];
	}

	public function test_the_cards_table_is_registered_next_to_the_products_table_with_the_pages_capability(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $this->pages_with_cards() );

		$this->assertTrue( Site_Products_Module::register_remote_cards( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );

		$registration = Records_Registry::instance()->get( 'ozon', 'remote_cards' );

		$this->assertNotNull( $registration );
		$this->assertInstanceOf( Remote_Cards_Provider::class, $registration->get_provider() );
		$this->assertSame( 'manage_woocommerce', $registration->get_capability() );
	}

	public function test_a_page_that_does_not_declare_the_cards_table_gets_no_such_table(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertTrue( Site_Products_Module::register_provider( $plugin, $this->accounts(), new Sqlite_Wpdb() ), 'The products table is there.' );
		$this->assertFalse( Site_Products_Module::register_remote_cards( $plugin, $this->accounts(), new Sqlite_Wpdb() ), 'The default page of an adapter that cannot import lists no cards.' );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'remote_cards' ) );
	}

	public function test_a_plugin_that_dropped_the_products_page_gets_neither_table(): void {
		$pages  = [ [ 'slug' => 'tools', 'title' => 'Инструменты', 'kind' => 'custom', 'view' => 'tools' ] ];
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $pages );

		$this->assertFalse( Site_Products_Module::register_remote_cards( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'remote_cards' ) );
	}

	public function test_starting_the_rest_api_registers_both_tables_in_one_hook(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $this->pages_with_cards() );
		$hook   = null;

		Actions\expectAdded( 'rest_api_init' )->once()->with(
			Mockery::on(
				static function ( $callback ) use ( &$hook ): bool {
					$hook = $callback;

					return true;
				}
			),
			5
		);

		Site_Products_Module::register( $plugin, $this->accounts(), new Sqlite_Wpdb() );
		$hook();

		$this->assertNotNull( Records_Registry::instance()->get( 'ozon', 'products' ) );
		$this->assertNotNull( Records_Registry::instance()->get( 'ozon', 'remote_cards' ) );
	}

	public function test_a_plugin_that_dropped_the_products_page_gets_no_table(): void {
		$pages  = [
			[
				'slug'  => 'tools',
				'title' => 'Инструменты',
				'kind'  => 'custom',
				'view'  => 'tools',
			],
		];
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $pages );

		$this->assertFalse( Site_Products_Module::register_provider( $plugin, $this->accounts(), new Sqlite_Wpdb() ) );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'products' ) );
	}

	public function test_linking_by_article_counts_the_products_with_an_article_that_have_no_card_identity(): void {
		$products = [
			[ 'id' => 1, 'parent_id' => 0, 'sku' => 'A' ],
			[ 'id' => 2, 'parent_id' => 0, 'sku' => 'B' ],
			[ 'id' => 3, 'parent_id' => 0, 'sku' => 'C' ],
		];
		$identity = [
			1 => [ 'offer_id' => 'A', 'has_remote_ids' => true, 'card_state' => 'created' ],
			2 => [ 'offer_id' => 'B', 'has_remote_ids' => false, 'card_state' => 'queued' ],
		];

		$this->assertSame( 2, Site_Products_Module::count_candidates( $products, $identity ) );
		$this->assertSame( 3, Site_Products_Module::count_candidates( $products, [] ) );
	}
}
