<?php
/**
 * The collector that wires every marketplace menu into wp-admin once.
 *
 * `wc_admin_register_page()` is never stubbed here: touching it once would leak its existence into every later test of the
 * process (`ShippingOrdersRegistryTest` documents the same trap). What is registered is asserted through
 * `Menu::build_registrations()` in MenuTest; here the collector's own decisions are.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Admin_Collector;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Test_Marketplace_Plugin.php';

/**
 * A collector that believes it is on a wc-admin screen.
 */
class Admin_Collector_On_Screen extends Admin_Collector {

	/** @var bool */
	public $on_screen = true;

	/** @return bool */
	protected function is_wc_admin_screen(): bool {
		return $this->on_screen;
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Admin_Collector
 */
final class AdminCollectorTest extends TestCase {

	/** @var Admin_Collector_On_Screen */
	private $collector;

	/** @var array<int,array{0:string,1:mixed}> Every `wp_enqueue_script()` call. */
	private $enqueued_scripts = [];

	/** @var string[] Handles `wp_script_is( …, 'enqueued' )` answers true for. */
	private $enqueued_handles = [];

	/** @var string[] Inline scripts added. */
	private $inline_scripts = [];

	protected function setUp(): void {
		parent::setUp();

		$this->collector        = new Admin_Collector_On_Screen();
		$this->enqueued_scripts = [];
		$this->enqueued_handles = [];
		$this->inline_scripts   = [];
		unset( $GLOBALS['submenu'] );
		unset( $_SERVER['REQUEST_URI'] );

		Functions\stubs( [ 'add_action', 'wp_json_encode' => 'json_encode' ] );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return dirname( $file ) . '/';
			}
		);
		Functions\when( 'plugins_url' )->alias(
			static function ( string $path ): string {
				return 'https://shop.test/wp-content/plugins/framework' . $path;
			}
		);
		Functions\when( 'wp_script_is' )->alias(
			function ( string $handle, string $list = 'enqueued' ): bool {
				return 'enqueued' === $list && in_array( $handle, $this->enqueued_handles, true );
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '', array $deps = [] ): void {
				$this->enqueued_scripts[] = [ $handle, $deps, $src ];
				$this->enqueued_handles[] = $handle;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $data ): bool {
				$this->inline_scripts[] = $data;

				return true;
			}
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['submenu'], $_SERVER['REQUEST_URI'] );

		parent::tearDown();
	}

	/**
	 * @param string $id          Plugin id (also the menu id).
	 * @param array  $page_slugs  Optional page slugs to declare; the default five otherwise.
	 */
	private function plugin( string $id, ?array $page_slugs = null ): Test_Marketplace_Plugin {
		$pages = null;

		if ( null !== $page_slugs ) {
			$pages = array_map(
				static function ( string $slug ) {
					return [ 'slug' => $slug, 'title' => 'T ' . $slug, 'kind' => 'records', 'tables' => [ $slug ] ];
				},
				$page_slugs
			);
		}

		return new Test_Marketplace_Plugin( $id, [ 'id' => $id, 'title' => strtoupper( $id ) ], $pages );
	}

	public function test_two_plugins_get_two_menus_and_one_bundle(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$this->collector->register_plugin( $this->plugin( 'wildberries' ) );

		$this->collector->enqueue_assets();
		$this->collector->enqueue_assets();

		$bundles = array_filter(
			$this->enqueued_scripts,
			static function ( array $call ): bool {
				return Admin_Collector::SCRIPT_HANDLE === $call[0];
			}
		);

		$this->assertCount( 1, $bundles, 'one bundle, however many marketplace plugins and however many calls' );
		$this->assertCount( 1, $this->inline_scripts );

		$manifest = json_decode( substr( $this->inline_scripts[0], strlen( 'window.woodevMarketplaceAdmin = ' ), -1 ), true );
		$this->assertSame( [ 'ozon-seller', 'wildberries' ], array_column( $manifest['menus'], 'id' ) );
		$this->assertContains( 'wc-admin-app', array_values( $bundles )[0][1], 'ordered after the wc-admin shell' );
	}

	public function test_nothing_is_enqueued_outside_wc_admin_or_without_a_menu(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$this->collector->on_screen = false;
		$this->collector->enqueue_assets();

		$this->assertSame( [], $this->enqueued_scripts );

		$empty = new Admin_Collector_On_Screen();
		$empty->enqueue_assets();

		$this->assertSame( [], $this->enqueued_scripts, 'no plugin, nothing to route' );
	}

	public function test_a_user_who_may_open_nothing_gets_no_menu_in_the_manifest(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( [ 'menus' => [] ], $this->collector->build_manifest() );

		$this->collector->enqueue_assets();
		$this->assertSame( [], $this->enqueued_scripts, 'no manifest entry: the bundle is not needed' );
	}

	public function test_a_menu_id_one_plugin_owns_is_not_taken_by_a_second(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$first  = $this->plugin( 'ozon-a', [ 'products' ] );
		$second = new Test_Marketplace_Plugin( 'ozon-b', [ 'id' => 'ozon-a', 'title' => 'CLASH' ], [ [ 'slug' => 'orders', 'title' => 'O', 'kind' => 'records', 'tables' => [ 'orders' ] ] ] );

		$this->collector->register_plugin( $first );
		$this->collector->register_plugin( $second );

		$menus = $this->collector->get_menus();

		$this->assertSame( [ 'ozon-a' ], array_keys( $menus ) );
		$this->assertSame( 'ozon-a', $menus['ozon-a']->get_plugin_id(), 'the first plugin keeps its menu' );
	}

	public function test_without_wc_admin_the_merchant_is_told_and_nothing_is_registered(): void {
		$this->assertFalse( function_exists( 'wc_admin_register_page' ), 'this test needs the function to be absent' );

		$handler = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$handler->shouldReceive( 'add_admin_notice' )->once()->with( Mockery::type( 'string' ), 'ozon-seller-marketplace-wc-admin-missing', Mockery::type( 'array' ) );

		$plugin = Mockery::mock( \Woodev\Framework\Marketplace\Marketplace_Plugin::class )->makePartial();
		$plugin->shouldReceive( 'get_id' )->andReturn( 'ozon-seller' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'OZON' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $handler );
		$plugin->shouldReceive( 'get_marketplace_admin_menu' )->andReturn( null );

		$this->collector->register_plugin( $plugin );
		$this->collector->register_menus();
	}

	public function test_the_copy_of_the_root_is_removed_from_the_global_submenu_and_no_entry_is_hidden(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$root = $this->collector->get_menus()['ozon-seller']->get_root_slug();

		$GLOBALS['submenu'] = [
			$root => [
				[ 'OZON-SELLER', 'manage_woocommerce', $root, 'OZON-SELLER' ],
				[ 'T', 'manage_woocommerce', 'wc-admin&path=/ozon-seller/products', 'T' ],
				[ 'Orders', 'manage_woocommerce', 'wc-admin&path=/ozon-seller/orders', 'Orders' ],
			],
			'woocommerce' => [ [ 'Home', 'manage_woocommerce', 'wc-admin', 'Home' ] ],
		];

		$this->collector->clean_up_submenus();

		$this->assertSame(
			[ 'wc-admin&path=/ozon-seller/products', 'wc-admin&path=/ozon-seller/orders' ],
			array_column( $GLOBALS['submenu'][ $root ], 2 ),
			'no copy of the root: the root links to the first page'
		);

		foreach ( $GLOBALS['submenu'][ $root ] as $entry ) {
			$this->assertCount( 4, $entry, 'no entry is marked with a class' );
		}

		$this->assertCount( 1, $GLOBALS['submenu']['woocommerce'], 'a menu that is not ours is left alone' );
	}

	public function test_each_root_loses_only_its_own_copy_when_two_plugins_share_the_submenu(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$this->collector->register_plugin( $this->plugin( 'wildberries' ) );
		$menus = $this->collector->get_menus();
		$ozon  = $menus['ozon-seller']->get_root_slug();
		$wb    = $menus['wildberries']->get_root_slug();

		$this->assertNotSame( $ozon, $wb );
		$this->assertNotSame( $menus['ozon-seller']->get_root_dom_id(), $menus['wildberries']->get_root_dom_id() );

		$GLOBALS['submenu'] = [
			$ozon => [ [ 'O', 'manage_woocommerce', $ozon, 'O' ], [ 'T', 'manage_woocommerce', 'wc-admin&path=/ozon-seller/products', 'T' ] ],
			$wb   => [ [ 'W', 'manage_woocommerce', $wb, 'W' ], [ 'T', 'manage_woocommerce', 'wc-admin&path=/wildberries/products', 'T' ] ],
		];

		$this->collector->clean_up_submenus();

		$this->assertSame( [ 'wc-admin&path=/ozon-seller/products' ], array_column( $GLOBALS['submenu'][ $ozon ], 2 ) );
		$this->assertSame( [ 'wc-admin&path=/wildberries/products' ], array_column( $GLOBALS['submenu'][ $wb ], 2 ) );
	}

	public function test_the_menu_styles_are_printed_for_every_root(): void {
		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$this->collector->register_plugin( $this->plugin( 'wildberries' ) );

		ob_start();
		$this->collector->print_menu_styles();
		$css = (string) ob_get_clean();

		$this->assertStringContainsString( '#toplevel_page_wc-admin-path--ozon-seller .wp-menu-image img', $css );
		$this->assertStringContainsString( '#toplevel_page_wc-admin-path--wildberries .wp-menu-image img', $css );
		$this->assertStringNotContainsString( 'display:none', $css );
	}

	/**
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public function provide_requests(): array {
		return [
			'v1 products page'              => [ '/wp-admin/admin.php?page=ozon-seller-products', 'path=%2Fozon-seller%2Fproducts' ],
			'v1 root'                       => [ '/wp-admin/admin.php?page=ozon-seller', 'path=%2Fozon-seller%2Fproducts' ],
			'v1 orders page with a pager'   => [ '/wp-admin/admin.php?page=ozon-seller-orders&paged=3', 'path=%2Fozon-seller%2Forders' ],
			'unreleased v2 slug'            => [ '/wp-admin/admin.php?page=woodev-ozon-products', null ],
			'the destination itself'        => [ '/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fproducts', null ],
			'an unrelated page naming us'   => [ '/wp-admin/admin.php?page=wc-status&tab=logs&log_file=ozon-seller-2026.log', null ],
			'no query at all'               => [ '/wp-admin/index.php', null ],
		];
	}

	/**
	 * @dataProvider provide_requests
	 */
	public function test_v1_urls_land_on_the_page_that_replaced_them( string $uri, ?string $expected_path ): void {
		Functions\stubs(
			[
				'wp_unslash'    => null,
				'wp_parse_url'  => 'parse_url',
				'wp_doing_ajax' => false,
				'admin_url'     => static function ( string $path = '' ): string {
					return 'https://shop.test/wp-admin/' . $path;
				},
				'add_query_arg' => static function ( array $args, string $url ): string {
					return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
				},
			]
		);

		$plugin = new Test_Marketplace_Plugin(
			'ozon',
			[ 'id' => 'ozon-seller', 'title' => 'OZON' ],
			[
				[ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ], 'legacy_page' => [ 'ozon-seller', 'ozon-seller-products' ] ],
				[ 'slug' => 'orders', 'title' => 'Заказы', 'kind' => 'records', 'tables' => [ 'orders' ], 'legacy_page' => 'ozon-seller-orders' ],
			]
		);
		$this->collector->register_plugin( $plugin );

		$_SERVER['REQUEST_URI'] = $uri;
		$target                 = $this->collector->resolve_legacy_redirect();

		if ( null === $expected_path ) {
			$this->assertNull( $target );

			return;
		}

		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=wc-admin&' . $expected_path, $target );
	}

	public function test_a_user_who_cannot_open_the_destination_is_not_redirected_to_it(): void {
		Functions\stubs( [ 'wp_unslash' => null, 'wp_parse_url' => 'parse_url', 'wp_doing_ajax' => false ] );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->collector->register_plugin(
			new Test_Marketplace_Plugin(
				'ozon',
				[ 'id' => 'ozon-seller', 'title' => 'OZON' ],
				[ [ 'slug' => 'products', 'title' => 'Товары', 'kind' => 'records', 'tables' => [ 'products' ], 'legacy_page' => 'ozon-seller' ] ]
			)
		);

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ozon-seller';

		$this->assertNull( $this->collector->resolve_legacy_redirect() );
	}

	public function test_an_ajax_request_is_never_redirected(): void {
		Functions\stubs( [ 'wp_unslash' => null, 'wp_parse_url' => 'parse_url', 'wp_doing_ajax' => true ] );

		$this->collector->register_plugin( $this->plugin( 'ozon-seller' ) );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?page=ozon-seller';

		$this->assertNull( $this->collector->resolve_legacy_redirect() );
	}
}
