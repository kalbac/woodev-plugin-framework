<?php
/**
 * Settings placement (framework F3, #1681): a marketplace plugin's settings providers are listed on that plugin's own
 * «Настройки» page and never on Woodev → Настройки; everything else about the settings page stays as it was.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\{Settings_Page_Registry, Settings_Provider};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Test_Marketplace_Plugin.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/controllers/class-rest-api-settings-page.php';

/**
 * A delivery-style plugin: not a marketplace plugin, so its providers stay on Woodev → Настройки.
 */
final class Placement_Delivery_Plugin_Fixture extends \Woodev\Framework\Woocommerce_Plugin {

	/** @var array<int,object> */
	public $providers = [];

	/** @return string */
	public function get_id() {
		return 'cdek';
	}

	/** @return array<int,object> */
	public function get_settings_providers(): array {
		return $this->providers;
	}

	/** @return string */
	protected function get_file() {
		return 'cdek/cdek.php';
	}

	/** @return string */
	public function get_plugin_name() {
		return 'CDEK';
	}

	/** @return int */
	public function get_download_id() {
		return 0;
	}
}

/**
 * @covers \Woodev\Framework\Settings\Settings_Page_Registry
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin::get_settings_url
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin::get_settings_tab_url
 * @covers \Woodev_REST_API_Settings_Page
 */
final class SettingsPlacementTest extends TestCase {

	private const MENU = [
		'id'    => 'ozon-seller',
		'title' => 'OZON Seller',
		'icon'  => 'https://example.test/icon.svg',
	];

	private const OZON_SETTINGS_URL = 'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fsettings';

	/** @var Settings_Page_Registry */
	private $registry;

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
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$this->registry = Settings_Page_Registry::instance();
		$this->registry->reset_for_tests();
	}

	protected function tearDown(): void {
		$this->registry->reset_for_tests();
		unset( $_SERVER['REQUEST_URI'] );

		parent::tearDown();
	}

	/**
	 * @param string      $id         Provider (tab) id.
	 * @param string|null $capability Declared capability.
	 * @param string|null $legacy     Legacy admin URL.
	 * @return Settings_Provider
	 */
	private function provider( string $id, ?string $capability = null, ?string $legacy = null ): Settings_Provider {
		return Settings_Provider::create(
			$id,
			'Tab ' . $id,
			Mockery::mock( \Woodev_Abstract_Settings::class ),
			[],
			array_filter(
				[
					'capability'  => $capability,
					'legacy_page' => $legacy,
				]
			)
		);
	}

	/**
	 * @param Settings_Provider ...$providers The plugin's providers.
	 * @return Test_Marketplace_Plugin
	 */
	private function marketplace_plugin( Settings_Provider ...$providers ): Test_Marketplace_Plugin {
		return new Test_Marketplace_Plugin( 'woocommerce-ozon-seller', self::MENU, null, $providers );
	}

	/**
	 * @param Settings_Provider ...$providers The plugin's providers.
	 * @return Placement_Delivery_Plugin_Fixture
	 */
	private function delivery_plugin( Settings_Provider ...$providers ): Placement_Delivery_Plugin_Fixture {
		$plugin            = ( new \ReflectionClass( Placement_Delivery_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();
		$plugin->providers = $providers;

		return $plugin;
	}

	/**
	 * A redirect in the real code is followed by `exit`, which would end the test run; throw instead so a stray one fails the test.
	 *
	 * @return void
	 */
	private function fail_on_redirect(): void {
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new \LogicException( 'Unexpected redirect to ' . $url );
			}
		);
	}

	/**
	 * @param array<string,mixed> $params Request parameters.
	 * @return \WP_REST_Request
	 */
	private function request( array $params ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);

		return $request;
	}

	/**
	 * @param array<int,array<string,mixed>> $tabs Tabs as the schema lists them.
	 * @return string[]
	 */
	private function ids( array $tabs ): array {
		return array_column( $tabs, 'id' );
	}

	// ----- the entries -----

	public function test_a_marketplace_provider_is_placed_on_its_plugin_and_a_delivery_provider_is_not(): void {
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$placements = [];
		foreach ( $this->registry->collect_entries() as $entry ) {
			$placements[ $entry['provider']->get_id() ] = $entry['placement'];
		}

		$this->assertSame(
			[
				'ozon' => 'woocommerce-ozon-seller',
				'cdek' => null,
			],
			$placements
		);
	}

	// ----- the Woodev page counts default-placement entries only -----

	public function test_the_woodev_page_lists_delivery_tabs_and_never_a_marketplace_tab(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$this->assertSame( [ 'cdek' ], $this->ids( $this->registry->get_tabs() ) );
		$this->assertSame( [ 'cdek' ], $this->ids( $this->registry->get_tabs( null ) ) );
		$this->assertSame( [ 'cdek' ], $this->ids( $this->registry->get_tabs( '' ) ) );
	}

	public function test_a_site_with_only_marketplace_plugins_gets_no_woodev_settings_page(): void {
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );

		$this->assertFalse( $this->registry->has_providers(), 'register_page() adds nothing without a default-placement provider' );
	}

	public function test_a_delivery_plugin_still_makes_the_woodev_page(): void {
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$this->assertTrue( $this->registry->has_providers() );
	}

	public function test_the_woodev_page_capability_ignores_a_marketplace_provider(): void {
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon', 'manage_woocommerce' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'neutral', 'manage_options' ) ) );

		$this->assertSame( 'manage_options', $this->registry->get_page_capability(), 'the broadest-reach rule sees the Woodev page tabs only' );
		$this->assertSame( 'manage_woocommerce', $this->registry->get_page_capability( 'woocommerce-ozon-seller' ) );
	}

	// ----- placement scoping of the list -----

	public function test_a_placement_lists_that_plugins_tabs_only(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ), $this->provider( 'ozon-notifications' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$this->assertSame( [ 'ozon', 'ozon-notifications' ], $this->ids( $this->registry->get_tabs( 'woocommerce-ozon-seller' ) ) );
	}

	public function test_an_unknown_placement_lists_nothing_and_never_falls_back_to_the_woodev_page(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$this->assertSame( [], $this->registry->get_tabs( 'no-such-plugin' ) );
	}

	public function test_a_placement_does_not_grant_access_to_a_tab_the_user_may_not_open(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'manage_woocommerce' === $capability;
			}
		);
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ), $this->provider( 'admin-only', 'manage_options' ) ) );

		$this->assertSame( [ 'ozon' ], $this->ids( $this->registry->get_tabs( 'woocommerce-ozon-seller' ) ) );
	}

	public function test_the_tab_cache_keeps_each_placement_apart(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		$this->assertSame( [ 'ozon' ], $this->ids( $this->registry->get_tabs( 'woocommerce-ozon-seller' ) ) );
		$this->assertSame( [ 'cdek' ], $this->ids( $this->registry->get_tabs() ), 'a cached marketplace list is not served as the default one' );
	}

	// ----- the REST controller -----

	public function test_the_schema_route_scopes_the_list_by_placement(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );
		$controller = new \Woodev_REST_API_Settings_Page( $this->registry );

		$this->assertSame( [ 'cdek' ], $this->ids( $controller->get_schema( $this->request( [] ) )['tabs'] ), 'omitted: the default set' );
		$this->assertSame( [ 'cdek' ], $this->ids( $controller->get_schema( $this->request( [ 'placement' => '' ] ) )['tabs'] ) );
		$this->assertSame(
			[ 'ozon' ],
			$this->ids( $controller->get_schema( $this->request( [ 'placement' => 'woocommerce-ozon-seller' ] ) )['tabs'] )
		);
	}

	public function test_the_read_gate_is_the_capability_of_the_requested_page(): void {
		$asked = [];
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$asked ): bool {
				$asked[] = $capability;

				return true;
			}
		);
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon', 'manage_woocommerce' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'neutral', 'manage_options' ) ) );
		$controller = new \Woodev_REST_API_Settings_Page( $this->registry );

		$controller->read_permissions_check( $this->request( [] ) );
		$controller->read_permissions_check();
		$controller->read_permissions_check( $this->request( [ 'placement' => 'woocommerce-ozon-seller' ] ) );

		$this->assertSame( [ 'manage_options', 'manage_options', 'manage_woocommerce' ], $asked );
	}

	public function test_saving_a_marketplace_provider_is_gated_by_its_own_capability_whatever_the_placement(): void {
		$asked = [];
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$asked ): bool {
				$asked[] = $capability;

				return 'manage_options' === $capability;
			}
		);
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon', 'manage_woocommerce' ) ) );
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'neutral', 'manage_options' ) ) );
		$controller = new \Woodev_REST_API_Settings_Page( $this->registry );

		$this->assertFalse( $controller->save_permissions_check( $this->request( [ 'provider_id' => 'ozon', 'placement' => 'woocommerce-ozon-seller' ] ) ) );
		$this->assertTrue( $controller->save_permissions_check( $this->request( [ 'provider_id' => 'neutral', 'placement' => 'woocommerce-ozon-seller' ] ) ), 'a placement names no provider and grants nothing' );
		$this->assertFalse( $controller->save_permissions_check( $this->request( [ 'provider_id' => 'no-such-tab' ] ) ), 'an unknown provider is refused' );
		$this->assertSame( [ 'manage_woocommerce', 'manage_options' ], $asked );
	}

	public function test_a_tool_run_is_gated_by_the_providers_own_capability(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'manage_options' === $capability;
			}
		);
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon', 'manage_woocommerce' ) ) );
		$controller = new \Woodev_REST_API_Settings_Page( $this->registry );

		// run_tool() and test_connection() share save_permissions_check() as their gate.
		$this->assertFalse( $controller->save_permissions_check( $this->request( [ 'provider_id' => 'ozon', 'tool_id' => 'reset' ] ) ) );
	}

	// ----- URLs -----

	public function test_a_default_placement_tab_url_is_unchanged_byte_for_byte(): void {
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ) ) );

		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=cdek', Settings_Page_Registry::get_tab_url( 'cdek' ) );
		$this->assertSame( 'https://shop.test/wp-admin/admin.php?page=woodev-settings&tab=a%20b', Settings_Page_Registry::get_tab_url( 'a b' ) );
	}

	public function test_a_marketplace_tab_url_is_its_plugins_settings_page_with_the_tab(): void {
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon' ), $this->provider( 'ozon-notifications' ) ) );

		$this->assertSame( self::OZON_SETTINGS_URL . '&tab=ozon', Settings_Page_Registry::get_tab_url( 'ozon' ) );
		$this->assertSame( self::OZON_SETTINGS_URL . '&tab=ozon-notifications', Settings_Page_Registry::get_tab_url( 'ozon-notifications' ) );
	}

	public function test_the_plugins_own_settings_url_is_the_url_the_one_builder_gives_its_first_tab(): void {
		$plugin = $this->marketplace_plugin( $this->provider( 'ozon' ), $this->provider( 'ozon-notifications' ) );
		$this->registry->register_plugin( $plugin );

		$this->assertSame( Settings_Page_Registry::get_tab_url( 'ozon' ), $plugin->get_settings_url() );
		$this->assertSame( self::OZON_SETTINGS_URL . '&tab=ozon', $plugin->get_settings_url() );
	}

	public function test_a_marketplace_plugin_without_a_menu_has_no_tab_url_rather_than_a_woodev_one(): void {
		Functions\when( '_doing_it_wrong' )->justReturn( null );
		$plugin = new Test_Marketplace_Plugin( 'woocommerce-ozon-seller', [ 'id' => 'Not A Slug' ], null, [ $this->provider( 'ozon' ) ] );
		$this->registry->register_plugin( $plugin );

		$this->assertSame( '', Settings_Page_Registry::get_tab_url( 'ozon' ) );
	}

	// ----- the legacy redirect -----

	public function test_the_v1_settings_link_redirects_to_the_placement_aware_url(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_unslash' )->returnArg();
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon-seller', null, 'wc-settings&tab=ozon-seller' ) ) );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wc-settings&tab=ozon-seller';

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( self::OZON_SETTINGS_URL . '&tab=ozon-seller' )
			->andThrow( new \RuntimeException( 'redirected' ) ); // the real call is followed by exit.

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );

		$this->registry->maybe_redirect_legacy();
	}

	public function test_the_marketplace_settings_route_is_a_destination_and_is_never_redirected_away_from(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_unslash' )->returnArg();
		// A declaration that happens to share the route's arguments must not loop.
		$this->registry->register_plugin( $this->marketplace_plugin( $this->provider( 'ozon-seller', null, 'wc-admin&tab=ozon-seller' ) ) );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wc-admin&path=%2Fozon-seller%2Fsettings&tab=ozon-seller';

		$this->fail_on_redirect();

		$this->registry->maybe_redirect_legacy();
		$this->assertTrue( true, 'no redirect was issued' );
	}

	public function test_a_plugin_without_a_page_to_land_on_is_left_alone_by_the_redirect(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '_doing_it_wrong' )->justReturn( null );
		$plugin = new Test_Marketplace_Plugin( 'woocommerce-ozon-seller', [ 'id' => 'Not A Slug' ], null, [ $this->provider( 'ozon-seller', null, 'wc-settings&tab=ozon-seller' ) ] );
		$this->registry->register_plugin( $plugin );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wc-settings&tab=ozon-seller';

		$this->fail_on_redirect();

		$this->registry->maybe_redirect_legacy();
		$this->assertTrue( true, 'no redirect was issued' );
	}

	// ----- the embedded bundle -----

	public function test_the_bundle_is_not_enqueued_on_wc_admin_screens_when_no_marketplace_plugin_places_a_provider(): void {
		$this->registry->register_plugin( $this->delivery_plugin( $this->provider( 'cdek' ) ) );

		Functions\expect( 'wp_enqueue_script' )->never();

		$this->registry->enqueue_embedded_assets();
		$this->assertTrue( true );
	}
}
