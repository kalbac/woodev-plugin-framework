<?php
/**
 * Unit tests for the wiring of the read-only order screen.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Admin\Order\Order_Screen_Module;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Actions;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * Every marketplace plugin gets the read-only order screen, whatever else its adapter offers, and only in the admin, once.
 */
final class OrderScreenModuleTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		Order_Screen_Module::forget();
		Functions\when( 'is_admin' )->justReturn( true );
	}

	public function test_an_adapter_with_no_optional_capability_still_gets_the_screen(): void {
		Actions\expectAdded( 'add_meta_boxes' )->once();
		Actions\expectAdded( 'woocommerce_process_shop_order_meta' )->once();
		Filters\expectAdded( 'wc_order_is_editable' )->once();

		Order_Screen_Module::register( $this->plugin(), new Sqlite_Wpdb() );
	}

	public function test_outside_the_admin_nothing_is_hooked(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'add_meta_boxes' )->never();
		Filters\expectAdded( 'wc_order_is_editable' )->never();

		Order_Screen_Module::register( $this->plugin(), new Sqlite_Wpdb() );
	}

	public function test_a_second_call_for_the_same_plugin_hooks_nothing_twice(): void {
		Actions\expectAdded( 'add_meta_boxes' )->once();

		$plugin = $this->plugin();

		Order_Screen_Module::register( $plugin, new Sqlite_Wpdb() );
		Order_Screen_Module::register( $plugin, new Sqlite_Wpdb() );
	}

	public function test_an_adapter_with_seller_actions_gets_their_ajax(): void {
		Actions\expectAdded( 'wp_ajax_woodev_fake_seller_action' )->once();
		Actions\expectAdded( 'wp_ajax_woodev_fake_seller_options' )->once();

		$db       = new Sqlite_Wpdb();
		$accounts = new Wpdb_Account_Store( $db, 'wp_woodev_fake_accounts', new Secret_Cipher( 'test-salt' ), 'fake', new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) );

		Order_Screen_Module::register( $this->plugin( Seller_Actions::class ), $db, $accounts );
	}

	/**
	 * Builds a plugin over an adapter that offers nothing optional, or one more contract.
	 *
	 * @param string ...$contracts Further contracts the adapter implements.
	 * @return Marketplace_Plugin
	 */
	private function plugin( string ...$contracts ): Marketplace_Plugin {
		$adapter = Mockery::mock( Marketplace_Adapter::class, ...$contracts );
		$adapter->shouldReceive( 'id' )->andReturn( 'fake' );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( new Fake_Settings_Descriptor( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) ) );
		$adapter->shouldReceive( 'order_meta' )->andReturn( new Fake_Order_Meta() );
		$adapter->shouldReceive( 'label_provider' )->andReturn( null );

		$plugin = Mockery::mock( Marketplace_Plugin::class );
		$plugin->shouldReceive( 'get_marketplace_adapter' )->andReturn( $adapter );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'fake-seller' );
		$plugin->shouldReceive( 'get_version' )->andReturn( '1.0' );
		$plugin->shouldReceive( 'get_framework_assets_url' )->andReturn( 'https://example.test/woodev/assets' );

		return $plugin;
	}
}
