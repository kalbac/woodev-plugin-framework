<?php
/**
 * The mappings routes are registered for a marketplace plugin.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Module;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
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

		$this->assertStringContainsString( 'Mappings_Module::register( $this );', implode( '', $lines ) );
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
}
