<?php
/**
 * Unit tests for the wiring of the embedded classic screens: a screen exists only for an adapter that offers its capability.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Product\Embedded_Screens;
use Woodev\Framework\Marketplace\Admin\Product\Product_Tab_Facts;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Export_Facts;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class EmbeddedScreensTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		Embedded_Screens::forget();
		Functions\when( 'is_admin' )->justReturn( true );
	}

	public function test_an_adapter_without_either_capability_gets_no_screen(): void {
		$adapter = Mockery::mock( Marketplace_Adapter::class );

		$this->assertSame(
			[
				'product_tab'  => false,
				'order_cancel' => false,
			],
			Embedded_Screens::capabilities_of( $adapter )
		);

		Actions\expectAdded( 'woocommerce_product_data_tabs' )->never();
		Actions\expectAdded( 'add_meta_boxes' )->never();

		Embedded_Screens::register( $this->plugin( $adapter ), $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_each_capability_is_detected_on_its_own(): void {
		$tab    = Mockery::mock( Marketplace_Adapter::class, Product_Tab_Facts::class );
		$cancel = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );

		$this->assertSame(
			[
				'product_tab'  => true,
				'order_cancel' => false,
			],
			Embedded_Screens::capabilities_of( $tab )
		);
		$this->assertSame(
			[
				'product_tab'  => false,
				'order_cancel' => true,
			],
			Embedded_Screens::capabilities_of( $cancel )
		);
	}

	public function test_an_adapter_that_can_cancel_gets_the_cancel_screens_and_not_the_tab(): void {
		$adapter = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );
		$adapter->shouldReceive( 'id' )->andReturn( 'fake' );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( new Fake_Settings_Descriptor( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) ) );
		$adapter->shouldReceive( 'order_meta' )->andReturn( new Fake_Order_Meta() );

		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Actions\expectAdded( 'wp_ajax_woodev_fake_cancel_reasons' )->once();
		Actions\expectAdded( 'wp_ajax_woodev_fake_cancel_posting' )->once();
		Actions\expectAdded( 'add_meta_boxes' )->once();
		Actions\expectAdded( 'wp_ajax_woodev_fake_product' )->never();
		Filters\expectAdded( 'woocommerce_product_data_tabs' )->never();

		Embedded_Screens::register( $this->plugin( $adapter ), $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_an_adapter_with_product_facts_gets_the_tab_and_not_the_cancel_screens(): void {
		$adapter = Mockery::mock( Marketplace_Adapter::class, Product_Tab_Facts::class );
		$adapter->shouldReceive( 'id' )->andReturn( 'fake' );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( new Fake_Settings_Descriptor( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) ) );
		$adapter->shouldReceive( 'stock_cadence' )->andReturn( new Sync_Cadence( 0, 5 ) );
		$adapter->shouldReceive( 'article_policy' )->andReturn( new Fake_Article_Policy() );
		$adapter->shouldReceive( 'export_facts' )->andReturn( new Fake_Export_Facts() );
		$adapter->shouldReceive( 'price_source' )->andReturn( null );
		$adapter->shouldReceive( 'export_rules' )->andReturn( [] );
		$adapter->shouldReceive( 'stock_policy' )->andReturn( new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY ) );
		$adapter->shouldReceive( 'supports_oversize' )->andReturn( false );

		Filters\expectAdded( 'woocommerce_product_data_tabs' )->once();
		Actions\expectAdded( 'wp_ajax_woodev_fake_product' )->once();
		Actions\expectAdded( 'wp_ajax_woodev_fake_cancel_posting' )->never();
		Actions\expectAdded( 'add_meta_boxes' )->never();

		Embedded_Screens::register( $this->plugin( $adapter ), $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_outside_the_admin_nothing_is_hooked(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$adapter = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );

		Actions\expectAdded( 'add_meta_boxes' )->never();

		Embedded_Screens::register( $this->plugin( $adapter ), $this->accounts(), new Sqlite_Wpdb() );
	}

	public function test_a_second_call_for_the_same_plugin_hooks_nothing_twice(): void {
		$adapter = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );
		$adapter->shouldReceive( 'id' )->andReturn( 'fake' );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( new Fake_Settings_Descriptor( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) ) );
		$adapter->shouldReceive( 'order_meta' )->andReturn( new Fake_Order_Meta() );

		Actions\expectAdded( 'add_meta_boxes' )->once();

		$plugin = $this->plugin( $adapter );

		Embedded_Screens::register( $plugin, $this->accounts(), new Sqlite_Wpdb() );
		Embedded_Screens::register( $plugin, $this->accounts(), new Sqlite_Wpdb() );
	}

	/**
	 * Builds a plugin double that hands out an adapter.
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return Marketplace_Plugin
	 */
	private function plugin( Marketplace_Adapter $adapter ): Marketplace_Plugin {
		$plugin = Mockery::mock( Marketplace_Plugin::class );

		$plugin->shouldReceive( 'get_marketplace_adapter' )->andReturn( $adapter );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'fake-seller' );
		$plugin->shouldReceive( 'get_version' )->andReturn( '1.0' );
		$plugin->shouldReceive( 'get_framework_assets_url' )->andReturn( 'https://example.test/woodev/assets' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( 'https://example.test/settings' );
		$plugin->shouldReceive( 'get_marketplace_admin_menu' )->andReturn( null );

		return $plugin;
	}

	/**
	 * Builds an account store the wiring only holds on to.
	 *
	 * @return Wpdb_Account_Store
	 */
	private function accounts(): Wpdb_Account_Store {
		return ( new \ReflectionClass( Wpdb_Account_Store::class ) )->newInstanceWithoutConstructor();
	}
}
