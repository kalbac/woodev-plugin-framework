<?php
/**
 * The mappings routes are registered for a marketplace plugin.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Module;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Article_Preview;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Test_Marketplace_Plugin.php';
require_once dirname( __DIR__, 5 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Module
 */
final class MappingsWiringTest extends TestCase {
	private const MENU = [
		'id'       => 'ozon-seller',
		'title'    => 'OZON Seller',
		'icon'     => 'https://example.test/icon.svg',
		'position' => '55.63',
	];

	/**
	 * Previous global database.
	 *
	 * @var mixed
	 */
	private $previous_db;

	/**
	 * Gives the module a database and the URL helpers the menu needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->previous_db = $GLOBALS['wpdb'] ?? null;
		$db                = new Sqlite_Wpdb();
		$db->prefix        = Sqlite_Tables::PREFIX;
		$GLOBALS['wpdb']   = $db;

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
	 * Restores the global database.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_db;
		parent::tearDown();
	}

	/**
	 * The base class constructor registers the module (the double bypasses the constructor, so its source is read).
	 *
	 * @return void
	 */
	public function test_the_base_class_constructor_registers_the_module(): void {
		$constructor = new \ReflectionMethod( Marketplace_Plugin::class, '__construct' );
		$source      = (array) file( (string) $constructor->getFileName() );
		$lines       = array_slice( $source, $constructor->getStartLine() - 1, $constructor->getEndLine() - $constructor->getStartLine() + 1 );

		$this->assertStringContainsString( 'Mappings_Module::register(', implode( '', $lines ) );
		$this->assertStringContainsString( 'return $this->get_account_store();', implode( '', $lines ) );
	}

	/**
	 * Firing `rest_api_init` registers every route under the plugin's own id, with the permission gate on each.
	 *
	 * @return void
	 */
	public function test_rest_init_registers_the_routes_of_the_plugin(): void {
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );
		$routes = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $pattern, array $endpoints ) use ( &$routes ): void {
				$routes[ $namespace . $pattern ] = $endpoints;
			}
		);

		Mappings_Module::register( $plugin );
		$this->assertTrue( has_action( 'rest_api_init' ) !== false, 'the module hooks rest_api_init' );
		Mappings_Module::controller( $plugin )->register_routes();

		$base = 'woodev/v1/admin/marketplace/ozon/mappings';
		$this->assertCount( 10, $routes, 'ten patterns carry the handlers' );
		$this->assertArrayHasKey( $base, $routes );
		$this->assertArrayHasKey( $base . '/categories', $routes );
		$this->assertArrayHasKey( $base . '/mappings/(?P<id>[1-9][0-9]{0,18})/check', $routes );
		$this->assertArrayHasKey( $base . '/profiles', $routes );

		$handlers = 0;
		foreach ( $routes as $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				++$handlers;
				$this->assertIsCallable( $endpoint['permission_callback'] );
			}
		}
		$this->assertSame( 14, $handlers );
	}

	/**
	 * An adapter with an article preview gets the route of the articles; the one above, without it, does not.
	 *
	 * @return void
	 */
	public function test_an_adapter_with_an_article_preview_gets_the_articles_route(): void {
		$plugin = new class( 'ozon', self::MENU ) extends Test_Marketplace_Plugin {
			/** @var Marketplace_Adapter */
			public $adapter;

			/** @return Marketplace_Adapter */
			public function get_marketplace_adapter(): Marketplace_Adapter {
				return $this->adapter;
			}
		};

		$plugin->adapter = $this->adapter_with_a_preview();
		$routes          = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $pattern ) use ( &$routes ): void {
				$routes[] = $pattern;
			}
		);

		Mappings_Module::controller( $plugin, array( $plugin, 'accounts' ) )->register_routes();

		$this->assertContains( '/admin/marketplace/ozon/mappings/mappings/(?P<id>[1-9][0-9]{0,18})/articles', $routes );
		$this->assertCount( 11, $routes );

		$routes = [];
		Mappings_Module::controller( $plugin )->register_routes();

		$this->assertCount( 10, $routes, 'without the accounts there is no preview: it would not know the products own settings' );
	}

	/**
	 * The fake adapter, with an article preview added: the fake is final, so every call is passed on to it.
	 *
	 * @return Marketplace_Adapter
	 */
	private function adapter_with_a_preview(): Marketplace_Adapter {
		$fake    = new Fake_Marketplace_Adapter();
		$adapter = Mockery::mock( Marketplace_Adapter::class, Article_Preview::class );

		foreach ( get_class_methods( Marketplace_Adapter::class ) as $method ) {
			$adapter->shouldReceive( $method )->andReturnUsing(
				static function ( ...$arguments ) use ( $fake, $method ) {
					return $fake->$method( ...$arguments );
				}
			);
		}

		return $adapter;
	}

	/**
	 * A plugin that dropped the page gets no routes.
	 *
	 * @return void
	 */
	public function test_a_plugin_without_the_mappings_page_gets_no_routes(): void {
		$pages  = [
			[
				'slug'   => 'products',
				'title'  => 'Товары',
				'kind'   => 'records',
				'tables' => [ 'products' ],
			],
		];
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $pages );

		$this->assertNull( Mappings_Module::controller( $plugin ) );
	}

	/**
	 * The two lists are registered with the records engine under the plugin's own id, for the capability of the page.
	 *
	 * @return void
	 */
	public function test_the_two_lists_are_registered_with_the_records_engine(): void {
		Records_Registry::reset_for_tests();
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU );

		$this->assertTrue( Mappings_Module::register_lists( $plugin ) );

		$registry = Records_Registry::instance();
		$this->assertSame( 'mappings', $registry->get( 'ozon', 'mappings' )->get_provider()->get_id() );
		$this->assertSame( 'profiles', $registry->get( 'ozon', 'profiles' )->get_provider()->get_id() );
		$this->assertNull( $registry->get( 'ozon', 'products' ) );

		Records_Registry::reset_for_tests();
	}

	/**
	 * A plugin that dropped the page gets no lists.
	 *
	 * @return void
	 */
	public function test_a_plugin_without_the_mappings_page_gets_no_lists(): void {
		Records_Registry::reset_for_tests();
		$pages  = [
			[
				'slug'   => 'products',
				'title'  => 'Товары',
				'kind'   => 'records',
				'tables' => [ 'products' ],
			],
		];
		$plugin = new Test_Marketplace_Plugin( 'ozon', self::MENU, $pages );

		$this->assertFalse( Mappings_Module::register_lists( $plugin ) );
		$this->assertNull( Records_Registry::instance()->get( 'ozon', 'mappings' ) );

		Records_Registry::reset_for_tests();
	}
}
